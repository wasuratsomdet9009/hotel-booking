<?php
// FILEX: hotel_booking/pages/booking_calendar_view.php
require_once __DIR__ . '/../bootstrap.php';
require_login(); // ตรวจสอบว่าล็อกอินหรือยัง
date_default_timezone_set('Asia/Bangkok');

$pageTitle = 'ปฏิทินการจองห้องพัก (มุมมองแบบกลุ่ม)';

// --- Get booking IDs from URL ---
$bookingIdsStr = $_GET['bids'] ?? '';
$highlightBookingIds = [];
if (!empty($bookingIdsStr)) {
    $tempIds = explode(',', $bookingIdsStr);
    foreach ($tempIds as $id) {
        if (filter_var(trim($id), FILTER_VALIDATE_INT)) {
            $highlightBookingIds[] = (int)trim($id);
        }
    }
}

// --- Determine Month and Year to Display ---
$currentMonth = isset($_GET['month']) ? (int)$_GET['month'] : date('n');
$currentYear = isset($_GET['year']) ? (int)$_GET['year'] : date('Y');

// If BIDs are provided, set the calendar to the month/year of the first booking's check-in date
$transactionCustomerName = null;
$transactionCheckinDate = null;
$transactionCheckoutDate = null;

if (!empty($highlightBookingIds)) {
    $stmt_first_booking_date = $pdo->prepare("SELECT checkin_datetime, checkout_datetime_calculated, customer_name FROM bookings WHERE id = ? LIMIT 1");
    $stmt_first_booking_date->execute([$highlightBookingIds[0]]);
    $first_booking_info = $stmt_first_booking_date->fetch();
    if ($first_booking_info) {
        $checkinDateObj = new DateTime($first_booking_info['checkin_datetime']);
        if (!isset($_GET['month']) && !isset($_GET['year'])) {
            $currentMonth = (int)$checkinDateObj->format('n');
            $currentYear = (int)$checkinDateObj->format('Y');
        }
        $transactionCustomerName = $first_booking_info['customer_name'];
        $transactionCheckinDate = $checkinDateObj;
        $transactionCheckoutDate = new DateTime($first_booking_info['checkout_datetime_calculated']);
    }
}

// --- Fetch Bookings for the Current Month ---
$startDateOfMonth = new DateTime("$currentYear-$currentMonth-01");
$endDateOfMonth = new DateTime("$currentYear-$currentMonth-" . $startDateOfMonth->format('t'));
$startDateOfMonthStr = $startDateOfMonth->format('Y-m-d 00:00:00');
$endDateOfMonthStr = $endDateOfMonth->format('Y-m-d 23:59:59');

// Step 1: Update SQL query to fetch booking_group_id, total_price, amount_paid
$stmt_month_bookings = $pdo->prepare("
    SELECT
        b.id, b.room_id, b.customer_name, b.customer_phone,
        b.checkin_datetime, b.checkout_datetime_calculated,
        b.receipt_path,
        b.booking_type,
        b.booking_group_id,
        b.total_price,      -- <<< MODIFIED: Added total_price
        b.amount_paid,      -- <<< MODIFIED: Added amount_paid
        r.zone, r.room_number
    FROM bookings b
    JOIN rooms r ON b.room_id = r.id
    WHERE (b.checkin_datetime <= :end_date_of_month AND b.checkout_datetime_calculated >= :start_date_of_month)
      AND b.customer_name IS NOT NULL
      AND b.customer_name != ''
      AND b.customer_name NOT LIKE 'ผู้เข้าพัก (ไม่ระบุชื่อ)%'
      AND b.customer_name NOT LIKE 'ผู้เข้าพักโซน F (ไม่ระบุชื่อ)%'
      AND b.customer_name NOT LIKE 'กลุ่มผู้เข้าพัก (ไม่ระบุชื่อ)%'
    ORDER BY b.booking_group_id ASC, b.checkin_datetime ASC -- <<< MODIFIED: Simplified sorting for PHP grouping
");
$stmt_month_bookings->execute([
    ':start_date_of_month' => $startDateOfMonthStr,
    ':end_date_of_month' => $endDateOfMonthStr
]);
$allBookingsInView = $stmt_month_bookings->fetchAll(PDO::FETCH_ASSOC);

// Fetch data specifically for the summary of highlighted bookings
$highlightedBookingsDataForSummary = [];
if (!empty($highlightBookingIds)) {
    $placeholders = implode(',', array_fill(0, count($highlightBookingIds), '?'));
    $stmt_highlighted_summary = $pdo->prepare("
        SELECT b.id, b.room_id, b.customer_name, b.checkin_datetime, b.checkout_datetime_calculated, r.zone, r.room_number
        FROM bookings b
        JOIN rooms r ON b.room_id = r.id
        WHERE b.id IN ($placeholders)
        ORDER BY b.checkin_datetime ASC, r.zone ASC, CAST(r.room_number AS UNSIGNED) ASC
    ");
    $stmt_highlighted_summary->execute($highlightBookingIds);
    $highlightedBookingsDataForSummary = $stmt_highlighted_summary->fetchAll();

    if ((!$transactionCustomerName || !$transactionCheckinDate) && !empty($highlightedBookingsDataForSummary)) {
        $transactionCustomerName = $highlightedBookingsDataForSummary[0]['customer_name'];
        $transactionCheckinDate = new DateTime($highlightedBookingsDataForSummary[0]['checkin_datetime']);
        $latestCheckoutForSummary = $transactionCheckinDate;
        foreach ($highlightedBookingsDataForSummary as $hBooking) {
            $currentCheckoutSummary = new DateTime($hBooking['checkout_datetime_calculated']);
            if ($currentCheckoutSummary > $latestCheckoutForSummary) {
                $latestCheckoutForSummary = $currentCheckoutSummary;
            }
        }
        $transactionCheckoutDate = $latestCheckoutForSummary;
    }
}

// --- START: PHP สำหรับคำนวณข้อมูลสรุปรายเดือน ---
$summaryTotalBookings = 0;
$summaryTotalOvernightStays = 0;
$summaryTotalShortStays = 0;
$summaryTotalRoomNights = 0;
$uniqueBookingIdsForSummary = [];

foreach ($allBookingsInView as $bookingSummaryItem) {
    if (!in_array($bookingSummaryItem['id'], $uniqueBookingIdsForSummary)) {
        $uniqueBookingIdsForSummary[] = $bookingSummaryItem['id'];
        $summaryTotalBookings++;

        if (isset($bookingSummaryItem['booking_type']) && $bookingSummaryItem['booking_type'] === 'overnight') {
            $summaryTotalOvernightStays++;
            $bookingStartDt = new DateTime($bookingSummaryItem['checkin_datetime']);
            $bookingEndDt = new DateTime($bookingSummaryItem['checkout_datetime_calculated']);
            $monthViewStartDt = clone $startDateOfMonth;
            $monthViewEndDt = clone $endDateOfMonth;
            $effectiveStartDateForRoomNights = ($bookingStartDt > $monthViewStartDt) ? clone $bookingStartDt : clone $monthViewStartDt;
            $effectiveEndDateForRoomNights = ($bookingEndDt < $monthViewEndDt) ? clone $bookingEndDt : clone $monthViewEndDt;
            if ($effectiveStartDateForRoomNights < $effectiveEndDateForRoomNights) {
                $tempEffectiveStart = new DateTime($effectiveStartDateForRoomNights->format('Y-m-d'));
                $tempEffectiveEnd = new DateTime($effectiveEndDateForRoomNights->format('Y-m-d'));
                $dateInterval = $tempEffectiveStart->diff($tempEffectiveEnd);
                $summaryTotalRoomNights += (int)$dateInterval->days;
            }
        } elseif (isset($bookingSummaryItem['booking_type']) && $bookingSummaryItem['booking_type'] === 'short_stay') {
            $checkinShortStayDt = new DateTime($bookingSummaryItem['checkin_datetime']);
            if ($checkinShortStayDt->format('Y-m') === $startDateOfMonth->format('Y-m')) {
                $summaryTotalShortStays++;
            }
        }
    }
}
// --- END: PHP สำหรับคำนวณข้อมูลสรุปรายเดือน ---



// Fetch all rooms for Timeline/Gantt chart
$stmt_rooms = $pdo->query("SELECT id, zone, room_number FROM rooms ORDER BY zone ASC, CAST(room_number AS UNSIGNED) ASC");
$allRoomsList = $stmt_rooms->fetchAll(PDO::FETCH_ASSOC);

// Step 2: Organize bookings by date and group using the new logic
$bookingsByDateAndGroup = [];
$pastBookingsForTimeline = []; // Timeline Data
// Temporary array to collect all bookings within a group to check for pending payments accurately
$rawGroupData = [];

foreach ($allBookingsInView as $booking) {
    if (empty(trim($booking['customer_name']))) {
        continue;
    }
    // Store raw booking data keyed by group_id (or booking_id if no group)
    $groupingKeyForRaw = !empty($booking['booking_group_id']) ? 'GROUPID_' . $booking['booking_group_id'] : 'SINGLE_' . $booking['id'];
    if (!isset($rawGroupData[$groupingKeyForRaw])) {
        $rawGroupData[$groupingKeyForRaw] = [
            'customer_name' => $booking['customer_name'], // Use the first customer name encountered for the group
            'customer_phone' => $booking['customer_phone'],
            'bookings' => [],
            'booking_group_id' => $booking['booking_group_id'],
            'is_highlighted_group_raw' => false, // Initialize
        ];
    }
    $rawGroupData[$groupingKeyForRaw]['bookings'][] = $booking;
    if (in_array($booking['id'], $highlightBookingIds)) {
        $rawGroupData[$groupingKeyForRaw]['is_highlighted_group_raw'] = true;
    }
}

// Now process the grouped raw data to create $bookingsByDateAndGroup
foreach ($rawGroupData as $groupKeyRaw => $groupDetails) {
    $groupHasPendingPayment = false;
    $firstCheckin = null;
    $bookingIdsInGroup = [];

    foreach ($groupDetails['bookings'] as $bookingInGroup) {
        $bookingIdsInGroup[] = $bookingInGroup['id'];
        if ((float)($bookingInGroup['total_price'] ?? 0) > (float)($bookingInGroup['amount_paid'] ?? 0)) {
            $groupHasPendingPayment = true;
        }
        $currentCheckin = new DateTime($bookingInGroup['checkin_datetime']);
        if ($firstCheckin === null || $currentCheckin < $firstCheckin) {
            $firstCheckin = $currentCheckin;
        }
    }

    // Using the first check-in of the group to determine its display start for iteration
    if ($firstCheckin) {
        // Find the overall checkout for the group for date iteration
        $overallGroupCheckout = null;
        foreach ($groupDetails['bookings'] as $bookingInGroup) {
            $currentCheckout = new DateTime($bookingInGroup['checkout_datetime_calculated']);
            if ($overallGroupCheckout === null || $currentCheckout > $overallGroupCheckout) {
                $overallGroupCheckout = $currentCheckout;
            }
        }

        if ($overallGroupCheckout) {
            $now = new DateTime('now', new DateTimeZone('Asia/Bangkok'));
            $isPastBookingGroup = ($overallGroupCheckout < $now);

            // Collect past bookings for timeline, but NO LONGER hide them from main calendar
            if ($isPastBookingGroup) {
                foreach ($groupDetails['bookings'] as $bkg) {
                    $roomId = $bkg['room_id'];
                    if (!isset($pastBookingsForTimeline[$roomId])) {
                        $pastBookingsForTimeline[$roomId] = [];
                    }
                    $pastBookingsForTimeline[$roomId][] = [
                        'id' => $bkg['id'],
                        'booking_group_id' => $bkg['booking_group_id'],
                        'customer_name' => h($bkg['customer_name']),
                        'checkin' => $bkg['checkin_datetime'],
                        'checkout' => $bkg['checkout_datetime_calculated'],
                        'amount_paid' => $bkg['amount_paid'] ?? 0,
                        'total_price' => $bkg['total_price'] ?? 0,
                        'is_highlighted' => $groupDetails['is_highlighted_group_raw']
                    ];
                }
            }

            $currentIterDate = clone $firstCheckin;
            while ($currentIterDate < $overallGroupCheckout) {
                $dateKeyIter = $currentIterDate->format('Y-m-d');
                $finalGroupKeyForDisplay = $dateKeyIter . '_' . $groupKeyRaw; // Make it unique per day

                if (!isset($bookingsByDateAndGroup[$finalGroupKeyForDisplay])) {
                    $bookingsByDateAndGroup[$finalGroupKeyForDisplay] = [
                        'date' => $dateKeyIter,
                        'customer_name' => h($groupDetails['customer_name']),
                        'customer_phone' => h($groupDetails['customer_phone'] ?? ''),
                        'rooms' => [], // Rooms for this specific day will be populated if booking spans it
                        'booking_ids' => $bookingIdsInGroup, // All booking IDs belonging to this logical group
                        'booking_group_id' => $groupDetails['booking_group_id'] ?? null,
                        'is_highlighted_group' => $groupDetails['is_highlighted_group_raw'],
                        'has_pending_payment_group' => $groupHasPendingPayment, // Consolidated pending payment status
                        'is_past_booking' => $isPastBookingGroup,
                        'custom_color' => null
                    ];
                }
                // Add rooms that are active on $dateKeyIter
                foreach ($groupDetails['bookings'] as $bkg) {
                    $bkg_checkin = new DateTime($bkg['checkin_datetime']);
                    $bkg_checkout = new DateTime($bkg['checkout_datetime_calculated']);
                    if ($currentIterDate >= $bkg_checkin && $currentIterDate < $bkg_checkout) {
                        $room_exists = false;
                        foreach ($bookingsByDateAndGroup[$finalGroupKeyForDisplay]['rooms'] as $existing_room) {
                            if ($existing_room['id'] == $bkg['room_id']) {
                                $room_exists = true;
                                break;
                            }
                        }
                        if (!$room_exists) {
                            $bookingsByDateAndGroup[$finalGroupKeyForDisplay]['rooms'][] = [
                                'display' => h($bkg['zone'] . $bkg['room_number']),
                                'id' => $bkg['room_id']
                            ];
                        }
                    }
                }
                // If after checking all bookings in the group, no room is active for this $dateKeyIter, remove the entry
                if (empty($bookingsByDateAndGroup[$finalGroupKeyForDisplay]['rooms'])) {
                    unset($bookingsByDateAndGroup[$finalGroupKeyForDisplay]);
                }

                $currentIterDate->modify('+1 day');
            }
        }
    }
}


// --- Calendar Generation ---
$daysOfWeek = ['อา', 'จ', 'อ', 'พ', 'พฤ', 'ศ', 'ส'];
$firstDayOfMonth = new DateTime("$currentYear-$currentMonth-01");
$daysInMonth = (int)$firstDayOfMonth->format('t');
$dayOfWeekNumeric = (int)$firstDayOfMonth->format('w');

$prevMonth = $currentMonth - 1;
$prevYear = $currentYear;
if ($prevMonth == 0) {
    $prevMonth = 12;
    $prevYear--;
}
$nextMonth = $currentMonth + 1;
$nextYear = $currentYear;
if ($nextMonth == 13) {
    $nextMonth = 1;
    $nextYear++;
}
$bidsQueryParam = !empty($highlightBookingIds) ? "&bids=" . implode(',', $highlightBookingIds) : "";

ob_start();
?>

<div class="container">
    <div class="calendar-header-minimal" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem; flex-wrap: wrap; gap: 1rem; padding-bottom: 1rem; border-bottom: 1px solid var(--color-border);">
        <h2 style="margin: 0; font-size: 1.8rem; font-weight: 700; color: var(--color-text);">
            <?= h($pageTitle) ?>
        </h2>

        <div class="calendar-navigation" style="display: flex; gap: 0.5rem; align-items: center; background: var(--color-surface); padding: 0.25rem; border-radius: var(--border-radius-lg); box-shadow: var(--shadow-sm); border: 1px solid var(--color-border); width: 100%; max-width: 400px; margin: 0 auto;">
            <a href="?month=<?= $prevMonth ?>&year=<?= $prevYear ?><?= $bidsQueryParam ?>" class="button outline-secondary" style="border:none; border-radius:var(--border-radius-md); padding: 0.6rem 1rem;"><i class="fas fa-chevron-left"></i></a>

            <div style="position: relative; flex: 1; display: flex; justify-content: center;">
                <select class="month-selector-dropdown" onchange="window.location.href='?month=' + this.value.split('-')[1] + '&year=' + this.value.split('-')[0] + '<?= $bidsQueryParam ?>'" style="appearance: none; -webkit-appearance: none; background: transparent; border: none; font-weight: 700; font-size: 1.15rem; color: var(--color-primary-dark); padding: 0.5rem 2rem 0.5rem 0.5rem; cursor: pointer; outline: none; text-align: center; font-family: inherit; width: 100%;">
                    <?php
                    $startDateOption = new DateTime("$currentYear-$currentMonth-01");
                    $startDateOption->modify('-6 months');
                    for ($i = 0; $i <= 18; $i++) {
                        $mItem = $startDateOption->format('m');
                        $yItem = $startDateOption->format('Y');
                        $valItem = $yItem . '-' . $mItem;
                        $labelItem = thaimonthfull($startDateOption->format('F')) . ' ' . ($yItem + 543);
                        $selectedItem = ($mItem == $currentMonth && $yItem == $currentYear) ? 'selected' : '';
                        echo "<option value=\"$valItem\" $selectedItem>$labelItem</option>";
                        $startDateOption->modify('+1 month');
                    }
                    ?>
                </select>
                <i class="fas fa-chevron-down" style="position: absolute; right: 10px; top: 50%; transform: translateY(-50%); pointer-events: none; color: var(--color-text-muted);"></i>
            </div>

            <a href="?month=<?= $nextMonth ?>&year=<?= $nextYear ?><?= $bidsQueryParam ?>" class="button outline-secondary" style="border:none; border-radius:var(--border-radius-md); padding: 0.6rem 1rem;"><i class="fas fa-chevron-right"></i></a>
        </div>
    </div>

    <?php if ($transactionCustomerName && $transactionCheckinDate && $transactionCheckoutDate && !empty($highlightedBookingsDataForSummary)): ?>
        <div class="report-section" style="background-color: #e6f7ff; border-left: 5px solid var(--color-info); margin-bottom: 2rem; padding: 1rem;">
            <h3 style="color: var(--color-primary-dark); margin-top:0;">สรุปการจอง (สำหรับรายการค้นหา): <?= h($transactionCustomerName) ?></h3>
            <p><strong>ช่วงวันที่จอง:</strong> <?= h($transactionCheckinDate->format('d M Y')) ?> - <?= h($transactionCheckoutDate->format('d M Y H:i น.')) ?></p>
            <p><strong>จำนวนห้องที่เกี่ยวข้อง:</strong> <?= count($highlightedBookingsDataForSummary) ?></p>
        </div>
    <?php endif; ?>

    <div class="calendar-fade-in">
        <div class="table-responsive">
            <table class="calendar-table">
                <thead>
                    <tr>
                        <?php foreach ($daysOfWeek as $day): ?>
                            <th><?= $day ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <?php
                        for ($i = 0; $i < $dayOfWeekNumeric; $i++) {
                            echo '<td class="calendar-day empty"></td>';
                        }

                        $currentDay = 1;
                        $todayDateObj = new DateTime('today');

                        while ($currentDay <= $daysInMonth) {
                            if ($dayOfWeekNumeric == 7) {
                                echo '</tr><tr>';
                                $dayOfWeekNumeric = 0;
                            }

                            $cellDateStr = $currentYear . '-' . str_pad($currentMonth, 2, '0', STR_PAD_LEFT) . '-' . str_pad($currentDay, 2, '0', STR_PAD_LEFT);
                            $cellDateObj = new DateTime($cellDateStr);
                            $cellClass = 'calendar-day';
                            $isToday = ($todayDateObj->format('Y-m-d') == $cellDateStr);
                            $isPastDay = ($cellDateObj < $todayDateObj);

                            if ($isToday) {
                                $cellClass .= ' today';
                            } elseif ($isPastDay) {
                                $cellClass .= ' past-day';
                            }

                            $bookingsForThisDay = [];
                            foreach ($bookingsByDateAndGroup as $groupKey_php => $groupData_php) {
                                if ($groupData_php['date'] === $cellDateStr) {
                                    $bookingsForThisDay[] = $groupData_php;
                                }
                            }
                            $numBookingGroupsThisDay = count($bookingsForThisDay);

                            echo '<td class="' . $cellClass . '" data-date="' . h($cellDateStr) . '" data-booking-count="' . h($numBookingGroupsThisDay) . '">';
                            echo '  <div class="date-number">' . $currentDay . '</div>';
                            echo '  <div class="calendar-add-booking-area">';
                            if ($isToday) {
                                echo '<a href="/hotel_booking/pages/index.php"' .
                                    ' class="button-small calendar-add-btn old-style-today-btn"' .
                                    ' title="การจองสำหรับวันนี้ กรุณาทำผ่านหน้าหลัก Dashboard"' .
                                    ' onclick="event.preventDefault(); window.location.href=this.href;">+ จอง (หน้าหลัก)</a>'; // Removed alert
                            } elseif ($cellDateObj > $todayDateObj) {
                                echo '<div class="calendar-fab-container">';
                                echo '  <button type="button" class="fab-main-btn" title="เพิ่มการจอง">';
                                echo '    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-plus-lg" viewBox="0 0 16 16"><path fill-rule="evenodd" d="M8 2a.5.5 0 0 1 .5.5v5h5a.5.5 0 0 1 0 1h-5v5a.5.5 0 0 1-1 0v-5h-5a.5.5 0 0 1 0-1h5v-5A.5.5 0 0 1 8 2Z"/></svg>';
                                echo '  </button>';
                                echo '  <div class="fab-options">';
                                echo '    <a href="/hotel_booking/pages/booking.php?mode=single&calendar_checkin_date=' . h($cellDateStr) . '" class="button-small fab-option-btn fab-option-single" title="จองห้องเดียวสำหรับ ' . h(date('d/m/Y', strtotime($cellDateStr))) . ' เวลา 14:00 น.">ห้องเดียว</a>';
                                echo '    <a href="/hotel_booking/pages/booking.php?mode=multi&calendar_checkin_date=' . h($cellDateStr) . '" class="button-small fab-option-btn fab-option-multi" title="จองหลายห้องสำหรับ ' . h(date('d/m/Y', strtotime($cellDateStr))) . ' เวลา 14:00 น.">หลายห้อง</a>';
                                echo '  </div>';
                                echo '</div>';
                            }
                            echo '  </div>';

                            echo '  <div class="booking-entries-area desktop-only">';
                            if ($numBookingGroupsThisDay > 0) {
                                $entriesShownDesktop = 0;
                                $maxEntriesDesktop = 2;

                                foreach ($bookingsForThisDay as $groupData_php) {
                                    if ($entriesShownDesktop < $maxEntriesDesktop) {
                                        $entryClassDesktop = "booking-group";
                                        if ($groupData_php['is_highlighted_group']) {
                                            $entryClassDesktop .= " highlighted-transaction";
                                        } else {
                                            $entryClassDesktop .= " regular-booking-entry";
                                        }

                                        if (isset($groupData_php['is_past_booking']) && $groupData_php['is_past_booking']) {
                                            $entryClassDesktop .= " past-booking-entry";
                                        }

                                        $roomNamesDesktop = array_map(function ($room) {
                                            return h($room['display']);
                                        }, $groupData_php['rooms']);
                                        sort($roomNamesDesktop);
                                        $roomNamesStrDesktop = implode(', ', $roomNamesDesktop);
                                        $firstRoomIdDesktop = (!empty($groupData_php['rooms']) && isset($groupData_php['rooms'][0]['id'])) ? h($groupData_php['rooms'][0]['id']) : '';

                                        $customerDisplayName = h($groupData_php['customer_name']);
                                        $roomCount = count($groupData_php['rooms']);
                                        if ($roomCount > 1 && strpos($customerDisplayName, '(' . $roomCount . ' ห้อง)') === false) {
                                            $customerDisplayName .= ' (' . $roomCount . ' ห้อง)';
                                        }
                                        $titleHoverDesktop = "ลูกค้า: " . h($groupData_php['customer_name']) . "\nห้อง: " . $roomNamesStrDesktop;

                                        $customerDisplayHtml = '<span class="booking-customer-name-highlight">' . $customerDisplayName;
                                        if (isset($groupData_php['has_pending_payment_group']) && $groupData_php['has_pending_payment_group']) {
                                            $customerDisplayHtml .= '<span class="calendar-pending-payment-alert" title="มียอดค้างชำระ">💰</span>';
                                        }
                                        $customerDisplayHtml .= '</span>';

                                        $dataAttributes = 'data-booking-ids="' . h(implode(',', $groupData_php['booking_ids'])) . '" ';
                                        if (!empty($groupData_php['booking_group_id'])) {
                                            $dataAttributes .= 'data-booking-group-id="' . h($groupData_php['booking_group_id']) . '" ';
                                        }
                                        $dataAttributes .= 'title="' . $titleHoverDesktop . '" ';
                                        $dataAttributes .= ($firstRoomIdDesktop ? 'data-first-room-id="' . $firstRoomIdDesktop . '"' : '');

                                        echo '<div class="' . $entryClassDesktop . ' calendar-customer-name-action" ' . $dataAttributes . '>';
                                        echo '<span class="booking-room-names">' . $roomNamesStrDesktop . '</span> ';
                                        echo $customerDisplayHtml;
                                        echo '</div>';
                                        $entriesShownDesktop++;
                                    } else {
                                        break;
                                    }
                                }
                                if ($numBookingGroupsThisDay > $maxEntriesDesktop) {
                                    echo '<div class="booking-more-indicator calendar-day-action-trigger" data-date="' . h($cellDateStr) . '">+' . ($numBookingGroupsThisDay - $maxEntriesDesktop) . ' เพิ่มเติม</div>';
                                }
                            }
                            echo '  </div>';

                            echo '  <div class="booking-summary-mobile mobile-only calendar-day-action-trigger" data-date="' . h($cellDateStr) . '">';
                            if ($numBookingGroupsThisDay > 0) {
                                echo '    <span class="booking-count">' . h($numBookingGroupsThisDay) . ' รายการจอง</span>';
                            } else {
                                echo '    <span class="no-bookings-mobile"><em>ไม่มีการจอง</em></span>';
                            }
                            echo '  </div>';

                            echo '</td>';

                            $currentDay++;
                            $dayOfWeekNumeric++;
                        }

                        if ($dayOfWeekNumeric != 7) {
                            for ($i = $dayOfWeekNumeric; $i < 7; $i++) {
                                echo '<td class="calendar-day empty"></td>';
                            }
                        }
                        ?>
                    </tr>
                </tbody>
            </table>
        </div>

    </div> <!-- End calendar-fade-in -->

    <?php if (!empty($pastBookingsForTimeline)): ?>
        <div class="past-bookings-timeline-section" style="margin-top: 2.5rem; padding: 0 0.5rem;">
            <h3 style="font-size: 1.2rem; font-weight: 700; color: var(--color-text); margin-bottom: 1rem; display: flex; align-items: center; gap: 0.5rem;">
                ประวัติการจองย้อนหลัง (Timeline)
            </h3>
            <div class="timeline-container" style="overflow-x: auto; background: var(--color-surface); border-radius: 12px; border: 1px solid var(--color-border); box-shadow: var(--shadow-sm);">
                <div class="timeline-grid">
                    <!-- Top Header Row -->
                    <div class="timeline-header-row">
                        <div class="timeline-cell room-header">ห้องพัก</div>
                        <div class="timeline-days-header-wrapper" style="display: flex; flex: 1;">
                            <?php for ($d = 1; $d <= $daysInMonth; $d()): ?>
                                <div class="timeline-cell day-header" style="flex: 1;"><?= $d ?></div>
                            <?php endfor; ?>
                        </div>
                    </div>

                    <!-- Room Rows -->
                    <?php foreach ($allRoomsList as $room):
                        $rId = $room['id'];
                        // Only show rooms that have at least one past booking to keep it clean, OR show all? Let's show only rooms with history for this month.
                        if (empty($pastBookingsForTimeline[$rId])) continue;
                    ?>
                        <div class="timeline-room-row">
                            <div class="timeline-cell room-name">
                                <?= h($room['zone'] . $room['room_number']) ?>
                            </div>

                            <!-- Days Grid -->
                            <div class="timeline-days-grid" style="position: relative; flex: 1; display: grid; grid-template-columns: repeat(<?= $daysInMonth ?>, 1fr);">
                                <?php for ($d = 1; $d <= $daysInMonth; $d()): ?>
                                    <div class="timeline-cell grid-cell"></div>
                                <?php endfor; ?>

                                <!-- Booking Bars -->
                                <?php
                                foreach ($pastBookingsForTimeline[$rId] as $tbkg):
                                    $cin = new DateTime($tbkg['checkin']);
                                    $cout = new DateTime($tbkg['checkout']);

                                    $mStart = clone $startDateOfMonth;
                                    $mEnd = clone $endDateOfMonth;

                                    $actualStart = max($cin, $mStart);
                                    $actualEnd = min($cout, $mEnd);

                                    $startDay = (int)$actualStart->format('j');
                                    $endDay = (int)$actualEnd->format('j');

                                    $span = $endDay - $startDay;
                                    if ($span < 1) $span = 1; // Minimum 1 col

                                    $leftPercent = (($startDay - 1) / $daysInMonth) * 100;
                                    $widthPercent = ($span / $daysInMonth) * 100;

                                    $barClass = $tbkg['is_highlighted'] ? 'timeline-bar highlighted' : 'timeline-bar normal';
                                ?>
                                    <div class="<?= $barClass ?> calendar-customer-name-action" style="left: <?= $leftPercent ?>%; width: <?= $widthPercent ?>%;" data-booking-ids="<?= h($tbkg['id']) ?>" data-booking-group-id="<?= h($tbkg['booking_group_id']) ?>">
                                        <span class="tbkg-name"><?= h($tbkg['customer_name']) ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?php
    // --- Determine Upcoming Bookings ---
    $upcomingBookingsList = [];
    $todayDateString = $todayDateObj->format('Y-m-d');
    $addedGroupForUpcoming = [];

    foreach ($bookingsByDateAndGroup as $groupData) {
        if ($groupData['date'] >= $todayDateString) {
            $groupKey = $groupData['booking_group_id'] ?? implode(',', $groupData['booking_ids']);
            if (!isset($addedGroupForUpcoming[$groupKey])) {
                $upcomingBookingsList[] = $groupData;
                $addedGroupForUpcoming[$groupKey] = true;
            }
        }
    }
    usort($upcomingBookingsList, function ($a, $b) {
        return strcmp($a['date'], $b['date']);
    });
    // Limit to next 5 upcoming
    $upcomingBookingsList = array_slice($upcomingBookingsList, 0, 5);
    ?>

    <div class="upcoming-plan-section" style="margin-top: 2.5rem; padding: 0 0.5rem;">
        <h3 style="font-size: 1.2rem; font-weight: 700; color: var(--color-text); margin-bottom: 1.5rem; display: flex; align-items: center; gap: 0.5rem;">
            Upcoming plan <span style="background: var(--color-primary-light); color: var(--color-primary-dark); padding: 0.2rem 0.6rem; border-radius: 12px; font-size: 0.8rem; font-weight: 600;"><?= count($upcomingBookingsList) ?></span>
        </h3>

        <?php if (empty($upcomingBookingsList)): ?>
            <p style="color: var(--color-text-muted); text-align: center; padding: 2rem 0; background: var(--color-surface); border-radius: 12px;">ไม่มีรายการจองที่กำลังจะมาถึง</p>
        <?php else: ?>
            <div class="agenda-timeline" style="position: relative; padding-left: 2rem; display: flex; flex-direction: column; gap: 1.5rem;">
                <!-- Vertical dashed line for timeline -->
                <div style="position: absolute; left: 0.45rem; top: 0.5rem; bottom: 0; width: 2px; background: repeating-linear-gradient(to bottom, var(--color-border) 0, var(--color-border) 6px, transparent 6px, transparent 12px);"></div>

                <?php foreach ($upcomingBookingsList as $upcoming):
                    $roomNames = array_map(function ($room) {
                        return h($room['display']);
                    }, $upcoming['rooms']);
                    $roomNamesStr = implode(', ', $roomNames);

                    $cardGradient = 'linear-gradient(135deg, #a78bfa, #8b5cf6)'; // Purple mockup style
                    $iconBg = 'rgba(255,255,255,0.2)';
                    $dotColor = '#8b5cf6';
                    if ($upcoming['is_highlighted_group']) {
                        $cardGradient = 'linear-gradient(135deg, #fca5a5, #f87171)'; // Rose/Red mockup style
                        $iconBg = 'rgba(0,0,0,0.1)';
                        $dotColor = '#f87171';
                    }

                    $ts = strtotime($upcoming['date']);
                    $agendaDateStr = date('j', $ts) . ' ' . thaimonthfull(date('F', $ts)) . ' ' . (date('Y', $ts) + 543);

                    // Detail extraction for agenda cards
                    $agendaCheckin = null;
                    $agendaCheckout = null;
                    foreach ($allBookingsInView as $b) {
                        if (in_array($b['id'], $upcoming['booking_ids'])) {
                            $agendaCheckin = new DateTime($b['checkin_datetime']);
                            $agendaCheckout = new DateTime($b['checkout_datetime_calculated']);
                            break; // Just grab from the first associated booking since they are grouped
                        }
                    }
                    $checkinStr = $agendaCheckin ? $agendaCheckin->format('d/m/Y H:i') : '';
                    $checkoutStr = $agendaCheckout ? $agendaCheckout->format('d/m/Y H:i') : '';
                    $nights = 0;
                    if ($agendaCheckin && $agendaCheckout) {
                        $nights = $agendaCheckin->diff($agendaCheckout)->days;
                    }
                ?>
                    <div class="agenda-item" style="position: relative;">
                        <!-- Timeline Dot -->
                        <div style="position: absolute; left: -2.3rem; top: 1.8rem; width: 14px; height: 14px; border-radius: 50%; background: <?= $dotColor ?>; border: 3px solid var(--color-surface); box-shadow: 0 0 0 1px var(--color-border-light); z-index: 2;"></div>

                        <!-- Date Header above card -->
                        <div style="font-weight: 600; color: var(--color-text-muted); font-size: 0.9rem; margin-bottom: 0.4rem; padding-left: 0.5rem;">
                            <?= $agendaDateStr ?>
                        </div>

                        <!-- Event Card -->
                        <div class="upcoming-card calendar-customer-name-action" style="background: <?= $cardGradient ?>; color: #fff; padding: 1.25rem; border-radius: 12px; display: flex; align-items: flex-start; gap: 1rem; box-shadow: 0 4px 10px rgba(0,0,0,0.08); cursor: pointer; transition: transform 0.2s;" data-booking-ids="<?= h(implode(',', $upcoming['booking_ids'])) ?>" data-booking-group-id="<?= h($upcoming['booking_group_id'] ?? '') ?>">
                            <div style="width: 44px; height: 44px; min-width: 44px; border-radius: 10px; background: <?= $iconBg ?>; display: flex; align-items: center; justify-content: center; font-size: 1.3rem;">
                                <i class="fas fa-<?= $upcoming['is_highlighted_group'] ? 'id-card' : 'suitcase-rolling' ?>"></i>
                            </div>
                            <div style="flex: 1;">
                                <div style="display: flex; justify-content: space-between; align-items: flex-start;">
                                    <h4 style="margin: 0; font-size: 1.15rem; font-weight: 700; text-shadow: 0 1px 2px rgba(0,0,0,0.1); display: flex; align-items: center; gap: 0.5rem;">
                                        <?= h($upcoming['customer_name']) ?>
                                        <?php if ($upcoming['has_pending_payment_group']): ?>
                                            <span style="background: #ef4444; color: white; font-size: 0.7rem; padding: 0.15rem 0.4rem; border-radius: 4px; box-shadow: 0 1px 2px rgba(0,0,0,0.2);"><i class="fa-solid fa-coins"></i> ค้างชำระ</span>
                                        <?php endif; ?>
                                    </h4>
                                    <div style="background: rgba(255,255,255,0.2); padding: 0.2rem 0.6rem; border-radius: 8px; font-weight: 600; font-size: 0.85rem; backdrop-filter: blur(4px);">
                                        <i class="fa-solid fa-moon"></i> <?= $nights ?> คืน
                                    </div>
                                </div>
                                <div style="font-size: 0.95rem; opacity: 0.95; margin-top: 0.5rem; display: flex; align-items: center; gap: 0.4rem;">
                                    <i class="fa-solid fa-bed"></i> ห้อง: <strong><?= $roomNamesStr ?></strong>
                                </div>
                                <div style="font-size: 0.85rem; color: rgba(255,255,255,0.85); margin-top: 0.5rem; display: flex; gap: 1rem; flex-wrap: wrap;">
                                    <span><i class="fa-solid fa-arrow-right-to-bracket"></i> In: <?= $checkinStr ?></span>
                                    <span><i class="fa-solid fa-arrow-right-from-bracket"></i> Out: <?= $checkoutStr ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <div style="margin-top: 2.5rem; padding-top:1.5rem; border-top: 1px dashed var(--color-border); text-align: center;" class="button-group">
        <a href="/hotel_booking/pages/index.php" class="button primary" style="padding: 0.8rem 1.5rem;">กลับไปหน้าหลัก Dashboard</a>
        <a href="/hotel_booking/pages/booking.php?mode=multi" class="button outline-secondary" style="margin-left: 10px; padding: 0.8rem 1.5rem;">ทำการจองหลายห้องเพิ่ม</a>
    </div>
</div>
<style>
    /* === Calendar View Enhancements === */
    @keyframes calendarSlideFade {
        0% {
            opacity: 0;
            transform: translateY(15px);
        }

        100% {
            opacity: 1;
            transform: translateY(0);
        }
    }

    .calendar-fade-in {
        animation: calendarSlideFade 0.4s cubic-bezier(0.16, 1, 0.3, 1) forwards;
    }

    /* Month Dropdown Styling */
    .month-selector-dropdown:focus {
        background-color: var(--color-surface-alt) !important;
        border-radius: 8px;
    }

    /* Calendar Table & Cells */
    .calendar-table {
        border-collapse: collapse;
        border-spacing: 0;
        border-radius: var(--border-radius-lg);
        overflow: hidden;
        background: var(--color-surface);
        width: 100%;
    }

    .calendar-table th {
        background-color: transparent;
        color: var(--color-text-muted);
        padding: 1rem 0.5rem;
        font-weight: 600;
        font-size: 0.85rem;
        text-align: center;
        border-bottom: 1px solid var(--color-border-light);
    }

    .calendar-day {
        border: none;
        border-bottom: 1px solid var(--color-border-light);
        vertical-align: top;
        height: 120px;
        min-width: 80px;
        padding: 0.5rem;
        position: relative;
        transition: background-color 0.2s ease-in-out;
    }

    .calendar-day.empty {
        background-color: transparent;
        opacity: 0;
    }

    body.dark-theme .calendar-day.empty {
        background-color: transparent;
        opacity: 0;
    }

    /* Faded Past Days */
    .calendar-day.past-day {
        opacity: 0.4;
    }

    body.dark-theme .calendar-day.past-day {
        opacity: 0.3;
    }

    .calendar-day.today {
        background-color: transparent;
        border: none;
        border-bottom: 1px solid var(--color-border-light);
    }

    body.dark-theme .calendar-day.today {
        background-color: transparent;
        border: none;
        border-bottom: 1px solid var(--color-border-light);
    }

    .date-number {
        font-size: 0.95em;
        font-weight: 500;
        color: var(--color-text);
        text-align: center;
        padding: 5px;
        margin: 0 auto 8px auto;
        display: flex;
        align-items: center;
        justify-content: center;
        width: 30px;
        height: 30px;
        border-radius: 50%;
    }

    .calendar-day.today .date-number {
        color: #ffffff;
        background-color: var(--color-primary);
        font-weight: 700;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
    }

    body.dark-theme .calendar-day.today .date-number {
        color: #ffffff;
        background-color: #3b82f6;
    }

    /* Booking Entries Styling */
    .booking-entries-area {
        /* This is for DESKTOP only now */
        margin-top: 2px;
        max-height: calc(130px - 2.5em - 25px - 38px);
        /* cell_height - date_number_approx_height - cell_padding_approx - fab_area_height */
        overflow-y: auto;
        padding-right: 4px;
    }

    .booking-summary-mobile {
        /* For MOBILE only */
        padding-top: 8px;
        /* Add some space from FAB area */
        text-align: center;
        font-size: 0.85rem;
        cursor: pointer;
    }

    .booking-summary-mobile .booking-count {
        display: block;
        font-weight: 500;
        color: var(--color-primary);
    }

    .booking-summary-mobile .no-bookings-mobile {
        color: var(--color-text-muted);
    }

    /* CSS to hide/show based on screen size (Example using media queries) */
    .mobile-only {
        display: none;
    }

    /* Hide on desktop by default */
    .desktop-only {
        display: block;
    }

    /* Show on desktop by default */

    @media (max-width: 768px) {

        /* Example breakpoint for mobile */
        .mobile-only {
            display: block;
        }

        .desktop-only {
            display: none;
        }

        .booking-entries-area {
            /* Reset max-height if it's hidden on mobile, or adjust as needed */
            max-height: none;
        }
    }


    .booking-group {
        position: relative;
        margin-bottom: 6px;
        padding: 5px 8px;
        font-size: 0.8rem;
        cursor: pointer;
        border-radius: 8px;
        transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
        display: flex;
        flex-direction: column;
        gap: 2px;
        overflow: hidden;
        border: none;
        color: #ffffff;
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
    }

    .booking-group:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 12px -2px rgba(0, 0, 0, 0.15);
        z-index: 10;
        filter: brightness(1.05);
    }

    .regular-booking-entry {
        background: linear-gradient(135deg, var(--color-primary), var(--color-info));
    }

    body.dark-theme .regular-booking-entry {
        background: linear-gradient(135deg, #4f46e5, #3b82f6);
        /* Richer gradient for dark mode */
        color: #f8fafc;
    }

    .highlighted-transaction {
        background: linear-gradient(135deg, var(--color-warning), #fa9021);
        color: #333333;
    }

    body.dark-theme .highlighted-transaction {
        background: linear-gradient(135deg, #f59e0b, #d97706);
        color: #111827;
    }

    .booking-customer-name-highlight {
        font-weight: 600;
        font-size: 0.8rem;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .booking-room-names {
        font-size: 0.72rem;
        opacity: 0.9;
        display: inline-block;
        padding-bottom: 1px;
    }



    .booking-more-indicator {
        font-size: 0.8rem;
        color: var(--color-link);
        text-align: center;
        padding: 3px;
        margin-top: 2px;
        cursor: pointer;
        border-radius: var(--border-radius-sm);
    }

    .booking-more-indicator:hover {
        background-color: var(--color-surface-hover);
        text-decoration: underline;
    }


    .booking-room-names {
        display: block;
        font-size: 0.88em;
        color: var(--color-text-muted);
        margin-bottom: 1px;
        font-weight: 400;
    }

    /* --- MODIFIED CSS --- */
    .booking-customer-name-highlight {
        font-weight: 600;
        color: var(--color-primary-dark);
        position: relative;
        /* Needed for absolute positioning of the alert icon */
        display: inline-block;
        /* Ensures proper positioning context for the child */
    }

    body.dark-theme .booking-customer-name-highlight {
        color: var(--dt-link-color);
    }

    /* --- NEW/UPDATED CSS --- */
    .calendar-pending-payment-alert {
        position: absolute;
        top: -5px;
        /* ปรับตำแหน่งตามความเหมาะสม */
        right: -12px;
        /* ปรับตำแหน่งตามความเหมาะสม, ให้เยื้องออกไปทางขวาเล็กน้อย */
        font-size: 0.7em;
        /* ขนาดไอคอน/ตัวอักษร */
        background-color: var(--color-alert, #dc3545);
        /* สีพื้นหลัง */
        color: var(--color-white, white);
        /* สีตัวอักษร */
        border-radius: 50%;
        /* ทำให้เป็นวงกลม */
        padding: 1px 4px;
        /* ระยะห่างภายใน */
        line-height: 1;
        z-index: 5;
        /* ให้อยู่เหนือ customer name */
        box-shadow: 0 0 4px rgba(0, 0, 0, 0.4);
        /* เพิ่มเงาให้ดูเด่นขึ้น */
        animation: pulse-warning-text 1.5s infinite ease-in-out;
        /* Add pulse animation */
    }

    /* FAB Button Styling */
    .calendar-add-booking-area {
        display: flex;
        justify-content: flex-end;
        padding-top: 5px;
        height: 38px;
        z-index: 3;
    }

    .calendar-fab-container .fab-main-btn {
        background-color: var(--color-secondary);
        color: var(--color-white);
        width: 32px;
        height: 32px;
        border-radius: 50%;
        box-shadow: var(--shadow-md);
        display: flex;
        align-items: center;
        justify-content: center;
        transition: background-color var(--transition-speed) var(--transition-func), transform 0.2s ease;
    }

    .calendar-fab-container .fab-main-btn:hover {
        background-color: var(--color-secondary-dark);
        transform: scale(1.08);
    }

    .calendar-fab-container .fab-main-btn svg {
        width: 16px;
        height: 16px;
    }

    .fab-options {
        background-color: var(--color-calendar-fab-options-bg);
        border: 1px solid var(--color-border);
        box-shadow: var(--shadow-lg);
        border-radius: var(--border-radius-md);
        display: none;
        position: absolute;
        bottom: 100%;
        left: 50%;
        transform: translateX(-50%);
        margin-bottom: 10px;
        flex-direction: column;
        align-items: center;
        padding: 5px;
        z-index: 10;
    }

    /* --- MODIFIED: Show options on container:hover, container:focus-within, or container.active --- */
    .calendar-fab-container:hover .fab-options,
    .calendar-fab-container:focus-within .fab-options,
    .calendar-fab-container.active .fab-options {
        display: flex;
    }


    .fab-option-btn {
        color: var(--color-calendar-fab-option-btn-text);
        background-color: var(--color-calendar-fab-option-btn-bg);
        border: 1px solid var(--color-calendar-fab-option-btn-border);
        text-decoration: none;
        padding: 6px 10px;
        font-size: 0.8em;
        border-radius: 4px;
        margin: 3px 0;
        width: 100px;
        text-align: center;
        display: block;
        transition: background-color 0.2s;
    }

    .fab-option-btn:hover {
        background-color: var(--color-primary-light);
        color: var(--color-primary-dark);
        border-color: var(--color-primary);
    }

    .button-small.calendar-add-btn.old-style-today-btn {
        background-color: var(--color-primary);
        color: var(--color-white);
        padding: 0.2rem 0.5rem;
        font-size: 0.8rem;
        border: none;
        border-radius: 4px;
        text-decoration: none;
        display: inline-block;
        width: calc(100% - 10px);
        margin-left: 5px;
        margin-right: 5px;
        box-sizing: border-box;
    }

    .button-small.calendar-add-btn.old-style-today-btn:hover {
        background-color: var(--color-primary-dark);
        opacity: 0.9;
    }

    .calendar-summary-section .kpi-box h4 {
        font-size: 0.8rem;
        font-weight: 500;
        margin-bottom: 0.3rem;
        color: var(--color-text-muted, #6c757d);
        margin-top: 0;
    }

    .calendar-summary-section .kpi-box p {
        font-size: 1.4rem;
        font-weight: 600;
        margin-bottom: 0;
    }

    .calendar-summary-section .kpi-box {
        border-radius: var(--border-radius-md, 4px);
    }

    /* Style for modal */
    .modal-booking-entry {
        border: 1px solid var(--color-border);
        border-left-width: 5px;
        padding: 0.8rem 1rem;
        margin-bottom: 0.75rem;
        border-radius: var(--border-radius-md);
        background-color: var(--color-surface);
    }

    .modal-booking-entry.regular {
        border-left-color: var(--color-secondary);
    }

    .modal-booking-entry.highlighted {
        border-left-color: var(--color-info);
        background-color: var(--color-info-bg-light);
    }

    .modal-booking-entry p {
        margin: 0 0 0.4rem 0;
    }

    .modal-booking-entry p:last-child {
        margin-bottom: 0;
    }

    .modal-customer-name {
        font-weight: 600;
        font-size: 1.05rem;
        color: var(--color-primary-dark);
    }

    .modal-room-names {
        font-size: 0.9rem;
        color: var(--color-text);
    }

    /* === Timeline Gantt Chart CSS === */
    .timeline-container {
        scrollbar-width: thin;
    }

    .timeline-grid {
        display: flex;
        flex-direction: column;
        min-width: 800px;
        /* Force scroll on small screens */
    }

    .timeline-header-row {
        display: flex;
        background: var(--color-surface-alt);
        border-bottom: 2px solid var(--color-border);
        position: sticky;
        top: 0;
        z-index: 10;
    }

    .timeline-room-row {
        display: flex;
        border-bottom: 1px dashed var(--color-border-light);
    }

    .timeline-cell {
        padding: 0.4rem;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 0.8rem;
    }

    .room-header,
    .room-name {
        width: 80px;
        min-width: 80px;
        position: sticky;
        left: 0;
        background: var(--color-surface);
        border-right: 2px solid var(--color-border);
        font-weight: 600;
        z-index: 5;
    }

    .room-header {
        background: var(--color-surface-alt);
        z-index: 11;
    }

    .day-header {
        font-weight: 600;
        color: var(--color-text-muted);
        border-right: 1px dashed var(--color-border-light);
    }

    .day-header:last-child {
        border-right: none;
    }

    .grid-cell {
        border-right: 1px dashed var(--color-border-light);
        min-height: 44px;
    }

    .grid-cell:last-child {
        border-right: none;
    }

    .timeline-bar {
        position: absolute;
        top: 50%;
        transform: translateY(-50%);
        height: 28px;
        border-radius: 14px;
        display: flex;
        align-items: center;
        padding: 0 0.6rem;
        font-size: 0.75rem;
        color: #fff;
        cursor: pointer;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        z-index: 2;
        transition: transform 0.2s, filter 0.2s;
        margin-left: 2px;
        box-sizing: border-box;
        width: calc(100% - 4px) !important;
    }

    .timeline-bar:hover {
        transform: translateY(-50%) scale(1.02);
        filter: brightness(1.1);
        z-index: 3;
    }

    .timeline-bar.normal {
        background: linear-gradient(135deg, var(--color-primary), var(--color-info));
    }

    .timeline-bar.highlighted {
        background: linear-gradient(135deg, var(--color-warning), #fa9021);
    }

    body.dark-theme .timeline-bar.normal {
        background: linear-gradient(135deg, #4f46e5, #3b82f6);
    }

    body.dark-theme .timeline-bar.highlighted {
        background: linear-gradient(135deg, #f59e0b, #d97706);
    }

    .tbkg-name {
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        font-weight: 500;
    }
</style>

<div id="calendar-day-bookings-modal" class="modal-overlay">
    <div class="modal-content" style="max-width: 90%; width:480px;"> <button class="modal-close" aria-label="Close">×</button>
        <h3 id="calendar-day-modal-title" style="margin-top:0; color: var(--color-primary-dark); border-bottom: 1px solid var(--color-border); padding-bottom: 0.75rem; margin-bottom: 1rem; font-size:1.25rem;">
            รายการจองสำหรับวันที่ <span id="modal-selected-date-display"></span>
        </h3>
        <div id="calendar-day-modal-body" style="max-height: 65vh; overflow-y: auto; padding-right:10px;">
        </div>
        <div class="button-group" style="margin-top: 1.5rem; padding-top: 1rem; border-top: 1px solid var(--color-border); justify-content: flex-end;">
            <button type="button" class="button outline-secondary modal-close">ปิด</button>
        </div>
    </div>
</div>
<?php
// ***** START: FIX 2.0 - เหลือไว้แค่ส่วนที่จำเป็น *****
// กำหนดค่าตัวแปร JavaScript เพื่อให้ main.js นำไปใช้ต่อ
echo "<script>const bookingsByDateAndGroupJS = " . json_encode($bookingsByDateAndGroup) . ";</script>";
// ***** END: FIX 2.0 *****
?>

<?php
function thaimonthfull($montheng)
{
    $thaimonths = [
        'January' => 'มกราคม',
        'February' => 'กุมภาพันธ์',
        'March' => 'มีนาคม',
        'April' => 'เมษายน',
        'May' => 'พฤษภาคม',
        'June' => 'มิถุนายน',
        'July' => 'กรกฎาคม',
        'August' => 'สิงหาคม',
        'September' => 'กันยายน',
        'October' => 'ตุลาคม',
        'November' => 'พฤศจิกายน',
        'December' => 'ธันวาคม'
    ];
    return $thaimonths[$montheng] ?? $montheng;
}

$content = ob_get_clean();
require_once __DIR__ . '/../templates/layout.php';
?>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const calendarTable = document.querySelector('.table-responsive');
        if (!calendarTable) return;

        let touchstartX = 0;
        let touchendX = 0;
        const minSwipeDistance = 50; // Minimum distance to trigger swipe

        calendarTable.addEventListener('touchstart', function(event) {
            touchstartX = event.changedTouches[0].screenX;
        }, {
            passive: true
        });

        calendarTable.addEventListener('touchend', function(event) {
            touchendX = event.changedTouches[0].screenX;
            handleSwipe();
        }, {
            passive: true
        });

        function handleSwipe() {
            const diff = touchstartX - touchendX;
            if (Math.abs(diff) > minSwipeDistance) {
                // Find the navigation links
                const navLinks = document.querySelectorAll('.calendar-navigation a.button');
                if (navLinks.length >= 2) {
                    const prevLink = navLinks[0].getAttribute('href');
                    const nextLink = navLinks[1].getAttribute('href');

                    if (diff > 0) {
                        // Swiped left (next month)
                        window.location.href = nextLink;
                    } else {
                        // Swiped right (prev month)
                        window.location.href = prevLink;
                    }
                }
            }
        }
    });
</script>