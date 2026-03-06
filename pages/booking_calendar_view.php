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

// --- Fetch PAST bookings from archives table for the current month ---
$stmt_archives = $pdo->prepare("
    SELECT
        a.id, a.room_id, a.customer_name, a.customer_phone,
        a.checkin_datetime, a.checkout_datetime_calculated,
        a.booking_type,
        a.booking_group_id,
        a.total_price,
        a.amount_paid,
        r.zone, r.room_number
    FROM archives a
    JOIN rooms r ON a.room_id = r.id
    WHERE (a.checkin_datetime <= :end_date AND a.checkout_datetime_calculated >= :start_date)
      AND a.customer_name IS NOT NULL
      AND a.customer_name != ''
      AND a.customer_name NOT LIKE 'ผู้เข้าพัก (ไม่ระบุชื่อ)%'
      AND a.customer_name NOT LIKE 'ผู้เข้าพักโซน F (ไม่ระบุชื่อ)%'
      AND a.customer_name NOT LIKE 'กลุ่มผู้เข้าพัก (ไม่ระบุชื่อ)%'
    ORDER BY a.booking_group_id ASC, a.checkin_datetime ASC
");
$stmt_archives->execute([
    ':start_date' => $startDateOfMonthStr,
    ':end_date'   => $endDateOfMonthStr
]);
$allArchivesInView = $stmt_archives->fetchAll(PDO::FETCH_ASSOC);

// Step 2: Organize bookings by date and group using the new logic
$bookingsByDateAndGroup = [];
$pastBookingsForTimeline = []; // Timeline Data
// Temporary array to collect all bookings within a group to check for pending payments accurately
$rawGroupData = [];

// --- Merge archives as past-booking entries into rawGroupData first ---
foreach ($allArchivesInView as $archive) {
    if (empty(trim($archive['customer_name']))) continue;
    $archiveKey = 'ARC_' . $archive['id'];
    $groupKey  = !empty($archive['booking_group_id']) ? 'ARCGROUP_' . $archive['booking_group_id'] : $archiveKey;
    if (!isset($rawGroupData[$groupKey])) {
        $rawGroupData[$groupKey] = [
            'customer_name' => $archive['customer_name'],
            'customer_phone' => $archive['customer_phone'],
            'bookings' => [],
            'booking_group_id' => $archive['booking_group_id'],
            'is_highlighted_group_raw' => false,
            'is_archive' => true, // Mark as archive = always past
        ];
    }
    // Reuse the same booking structure so the existing loop handles it
    $rawGroupData[$groupKey]['bookings'][] = [
        'id'                         => 'arc_' . $archive['id'],
        'room_id'                    => $archive['room_id'],
        'customer_name'              => $archive['customer_name'],
        'customer_phone'             => $archive['customer_phone'],
        'checkin_datetime'           => $archive['checkin_datetime'],
        'checkout_datetime_calculated' => $archive['checkout_datetime_calculated'],
        'booking_type'               => $archive['booking_type'],
        'booking_group_id'           => $archive['booking_group_id'],
        'total_price'                => $archive['total_price'],
        'amount_paid'                => $archive['amount_paid'],
        'zone'                       => $archive['zone'],
        'room_number'                => $archive['room_number'],
    ];
}

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
            // Archives are always past bookings, regardless of stored datetimes
            $isPastBookingGroup = ($overallGroupCheckout < $now) || !empty($groupDetails['is_archive']);

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
                            <?php for ($d = 1; $d <= $daysInMonth; $d++): ?>
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
                                <?php for ($d = 1; $d <= $daysInMonth; $d++): ?>
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
    /* ================================================================ */
    /* === CALENDAR PAGE FULL UI OVERHAUL — Blue Premium Theme ========= */
    /* ================================================================ */

    /* Page-level background gradient */
    .container>h2 {
        display: none;
    }

    /* hide if using custom header */

    /* ===== Animations ===== */
    @keyframes calendarSlideFade {
        0% {
            opacity: 0;
            transform: translateY(18px);
        }

        100% {
            opacity: 1;
            transform: translateY(0);
        }
    }

    @keyframes pulse-dot {

        0%,
        100% {
            box-shadow: 0 0 0 0 rgba(37, 99, 235, 0.35);
        }

        50% {
            box-shadow: 0 0 0 6px rgba(37, 99, 235, 0);
        }
    }

    @keyframes shimmer {
        0% {
            background-position: -200% 0;
        }

        100% {
            background-position: 200% 0;
        }
    }

    .calendar-fade-in {
        animation: calendarSlideFade 0.45s cubic-bezier(0.16, 1, 0.3, 1) forwards;
    }

    /* ===== Month Selector ===== */
    .month-selector-dropdown:focus {
        background-color: rgba(37, 99, 235, 0.06) !important;
        border-radius: 8px;
    }

    /* ===== Calendar Table ===== */
    .calendar-table {
        border-collapse: separate;
        border-spacing: 3px;
        border-radius: 16px;
        overflow: visible;
        background: transparent;
        width: 100%;
        table-layout: fixed;
    }

    .calendar-table thead {
        position: sticky;
        top: 0;
        z-index: 10;
    }

    .calendar-table th {
        background: linear-gradient(135deg, #1d4ed8, #2563eb);
        color: rgba(255, 255, 255, 0.9);
        padding: 0.75rem 0.4rem;
        font-weight: 600;
        font-size: 0.82rem;
        text-align: center;
        border-radius: 8px;
        letter-spacing: 0.03em;
        text-transform: uppercase;
    }

    body.dark-theme .calendar-table th {
        background: linear-gradient(135deg, #1e3a8a, #1d4ed8);
    }

    /* ===== Calendar Cells ===== */
    .calendar-day {
        border: none;
        border-radius: 12px;
        background: var(--color-surface, #fff);
        border: 1px solid rgba(219, 229, 255, 0.8);
        vertical-align: top;
        height: 130px;
        min-width: 80px;
        padding: 0.4rem;
        position: relative;
        transition: background-color 0.2s, border-color 0.2s, box-shadow 0.2s;
        box-shadow: 0 1px 3px rgba(37, 99, 235, 0.05);
    }

    .calendar-day:hover {
        border-color: rgba(37, 99, 235, 0.3);
        box-shadow: 0 4px 12px rgba(37, 99, 235, 0.1);
        background: rgba(239, 246, 255, 0.8);
        z-index: 2;
    }

    body.dark-theme .calendar-day:hover {
        background: rgba(30, 41, 80, 0.7);
        border-color: rgba(96, 165, 250, 0.3);
    }

    .calendar-day.empty {
        background: transparent;
        border: none;
        box-shadow: none;
        opacity: 0;
        pointer-events: none;
    }

    /* Past days — subtle wash */
    .calendar-day.past-day {
        background: rgba(248, 250, 252, 0.9);
        border-color: rgba(226, 232, 240, 0.7);
        opacity: 1;
    }

    body.dark-theme .calendar-day.past-day {
        background: rgba(15, 23, 42, 0.6);
        border-color: rgba(51, 65, 85, 0.5);
    }

    .calendar-day.past-day .date-number {
        color: #94a3b8;
    }

    /* Today highlight */
    .calendar-day.today {
        background: linear-gradient(145deg, #eff6ff, #dbeafe);
        border-color: #93c5fd;
        border-width: 2px;
        box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.12), inset 0 1px 0 rgba(255, 255, 255, 0.8);
    }

    body.dark-theme .calendar-day.today {
        background: linear-gradient(145deg, #1e3a8a22, #1d4ed822);
        border-color: #3b82f6;
    }

    /* ===== Date Number ===== */
    .date-number {
        font-size: 0.88em;
        font-weight: 500;
        color: var(--color-text);
        text-align: center;
        display: flex;
        align-items: center;
        justify-content: center;
        width: 26px;
        height: 26px;
        border-radius: 50%;
        margin: 0 auto 4px auto;
        line-height: 1;
        transition: all 0.2s;
    }

    .calendar-day.today .date-number {
        background: linear-gradient(135deg, #2563eb, #1d4ed8);
        color: #fff;
        font-weight: 700;
        box-shadow: 0 3px 8px rgba(37, 99, 235, 0.4);
        animation: pulse-dot 2.5s infinite;
    }

    body.dark-theme .calendar-day.today .date-number {
        background: linear-gradient(135deg, #3b82f6, #2563eb);
    }

    /* ===== Booking Entry Pills ===== */
    .booking-entries-area {
        margin-top: 2px;
        max-height: calc(130px - 2.2em - 15px - 38px);
        overflow-y: auto;
        padding-right: 2px;
        scrollbar-width: thin;
        scrollbar-color: rgba(147, 197, 253, 0.5) transparent;
    }

    .booking-entries-area::-webkit-scrollbar {
        width: 3px;
    }

    .booking-entries-area::-webkit-scrollbar-thumb {
        background: rgba(147, 197, 253, 0.6);
        border-radius: 2px;
    }

    .booking-group {
        position: relative;
        margin-bottom: 4px;
        padding: 4px 7px;
        font-size: 0.75rem;
        cursor: pointer;
        border-radius: 7px;
        transition: all 0.22s cubic-bezier(0.4, 0, 0.2, 1);
        display: flex;
        flex-direction: column;
        gap: 1px;
        overflow: hidden;
        border: none;
        color: #ffffff;
        box-shadow: 0 2px 5px rgba(0, 0, 0, 0.12);
    }

    .booking-group:hover {
        transform: translateY(-2px) scale(1.015);
        box-shadow: 0 5px 14px rgba(0, 0, 0, 0.18);
        z-index: 10;
    }

    /* Active / future bookings — Blue gradient */
    .regular-booking-entry {
        background: linear-gradient(135deg, #3b82f6, #60a5fa);
        border: none;
        color: #fff;
        box-shadow: 0 4px 12px rgba(59, 130, 246, 0.25);
    }

    .regular-booking-entry:hover {
        background: linear-gradient(135deg, #2563eb, #3b82f6);
        transform: translateY(-1.5px) scale(1.02);
        box-shadow: 0 6px 16px rgba(59, 130, 246, 0.35);
    }

    body.dark-theme .regular-booking-entry {
        background: linear-gradient(135deg, #1d4ed8, #0284c7);
        color: #f0f9ff;
    }

    /* Highlighted (search result) — Amber */
    .highlighted-transaction {
        background: linear-gradient(135deg, #f59e0b, #ef4444);
        color: #fff;
    }

    body.dark-theme .highlighted-transaction {
        background: linear-gradient(135deg, #d97706, #dc2626);
    }

    /* Past booking pill — Muted gray */
    .past-booking-entry {
        background: linear-gradient(135deg, #94a3b8, #64748b) !important;
        color: #e2e8f0 !important;
        opacity: 0.7;
        filter: grayscale(40%);
    }

    .past-booking-entry:hover {
        opacity: 0.9;
        filter: grayscale(10%);
    }

    body.dark-theme .past-booking-entry {
        background: linear-gradient(135deg, #334155, #475569) !important;
        opacity: 0.6;
    }

    .booking-customer-name-highlight {
        font-weight: 600;
        font-size: 0.78rem;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        color: #fff;
        opacity: 0.95;
    }

    .booking-room-names {
        font-size: 0.68rem;
        opacity: 0.82;
        display: block;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        color: rgba(255, 255, 255, 0.9);
    }

    /* Pending payment badge on pill */
    .calendar-pending-payment-alert {
        position: absolute;
        top: 2px;
        right: 3px;
        font-size: 0.6em;
        background: #ef4444;
        color: white;
        border-radius: 50%;
        width: 14px;
        height: 14px;
        display: flex;
        align-items: center;
        justify-content: center;
        line-height: 1;
        z-index: 5;
        box-shadow: 0 0 4px rgba(239, 68, 68, 0.5);
    }

    .booking-more-indicator {
        font-size: 0.72rem;
        color: #2563eb;
        text-align: center;
        padding: 2px;
        margin-top: 2px;
        cursor: pointer;
        border-radius: 5px;
        font-weight: 600;
        background: rgba(37, 99, 235, 0.08);
    }

    .booking-more-indicator:hover {
        background: rgba(37, 99, 235, 0.15);
        text-decoration: underline;
    }

    /* ===== Booking Summary (Mobile) ===== */
    .booking-summary-mobile {
        padding-top: 6px;
        text-align: center;
        font-size: 0.82rem;
        cursor: pointer;
    }

    .booking-summary-mobile .booking-count {
        display: block;
        font-weight: 600;
        color: #2563eb;
    }

    .booking-summary-mobile .no-bookings-mobile {
        color: var(--color-text-muted);
        font-size: 0.75rem;
    }

    /* ===== Responsive ===== */
    .mobile-only {
        display: none;
    }

    .desktop-only {
        display: block;
    }

    @media (max-width: 768px) {
        .mobile-only {
            display: block;
        }

        .desktop-only {
            display: none;
        }

        .booking-entries-area {
            max-height: none;
        }

        .calendar-day {
            height: 100px !important;
        }
    }

    /* ===== FAB Add Booking ===== */
    .calendar-add-booking-area {
        display: flex;
        justify-content: flex-end;
        padding-top: 4px;
        height: 36px;
        z-index: 3;
    }

    .calendar-fab-container .fab-main-btn {
        background: linear-gradient(135deg, #16a34a, #22c55e);
        color: #fff;
        width: 28px;
        height: 28px;
        border-radius: 50%;
        box-shadow: 0 3px 8px rgba(22, 163, 74, 0.4);
        display: flex;
        align-items: center;
        justify-content: center;
        transition: all 0.2s ease;
        border: none;
        cursor: pointer;
    }

    .calendar-fab-container .fab-main-btn:hover {
        transform: scale(1.1) rotate(90deg);
        box-shadow: 0 5px 12px rgba(22, 163, 74, 0.5);
    }

    .calendar-fab-container .fab-main-btn svg {
        width: 15px;
        height: 15px;
    }

    .fab-options {
        background: rgba(255, 255, 255, 0.96);
        backdrop-filter: blur(12px);
        -webkit-backdrop-filter: blur(12px);
        border: 1px solid rgba(226, 232, 240, 0.9);
        box-shadow: 0 12px 28px rgba(0, 0, 0, 0.12);
        border-radius: 12px;
        display: none;
        position: absolute;
        bottom: 100%;
        right: 0;
        margin-bottom: 8px;
        flex-direction: column;
        align-items: stretch;
        padding: 6px;
        z-index: 20;
        min-width: 120px;
    }

    body.dark-theme .fab-options {
        background: rgba(30, 41, 59, 0.95);
        border-color: rgba(51, 65, 85, 0.8);
    }

    .calendar-fab-container:hover .fab-options,
    .calendar-fab-container:focus-within .fab-options,
    .calendar-fab-container.active .fab-options {
        display: flex;
    }

    .fab-option-btn {
        color: #1e40af;
        background: rgba(239, 246, 255, 0.8);
        border: 1px solid rgba(147, 197, 253, 0.5);
        text-decoration: none;
        padding: 6px 10px;
        font-size: 0.8em;
        border-radius: 8px;
        margin: 2px 0;
        text-align: center;
        display: block;
        transition: all 0.2s;
        font-weight: 500;
    }

    .fab-option-btn:hover {
        background: #2563eb;
        color: #fff;
        border-color: #2563eb;
    }

    body.dark-theme .fab-option-btn {
        color: #93c5fd;
        background: rgba(30, 58, 138, 0.4);
        border-color: rgba(59, 130, 246, 0.3);
    }

    body.dark-theme .fab-option-btn:hover {
        background: #2563eb;
        color: #fff;
    }

    /* Today add btn */
    .button-small.calendar-add-btn.old-style-today-btn {
        background: linear-gradient(135deg, #2563eb, #0ea5e9);
        color: #fff;
        padding: 0.2rem 0.5rem;
        font-size: 0.72rem;
        border: none;
        border-radius: 5px;
        text-decoration: none;
        display: inline-block;
        width: calc(100% - 8px);
        margin: 0 4px;
        box-sizing: border-box;
        text-align: center;
    }

    .button-small.calendar-add-btn.old-style-today-btn:hover {
        filter: brightness(1.1);
    }

    /* ===== Upcoming Plan Cards ===== */
    .upcoming-card {
        transition: transform 0.22s ease, box-shadow 0.22s ease;
    }

    .upcoming-card:hover {
        transform: translateY(-3px);
        box-shadow: 0 12px 30px rgba(0, 0, 0, 0.15) !important;
    }

    /* ===== Booking Summary / Detail Popup Modal ===== */
    #booking-detail-popup {
        position: fixed;
        inset: 0;
        background: rgba(0, 0, 0, 0.55);
        backdrop-filter: blur(4px);
        -webkit-backdrop-filter: blur(4px);
        display: flex;
        align-items: center;
        justify-content: center;
        z-index: 1000;
        opacity: 0;
        pointer-events: none;
        transition: opacity 0.25s ease;
    }

    #booking-detail-popup.active {
        opacity: 1;
        pointer-events: all;
    }

    #booking-detail-popup-inner {
        background: #fff;
        border-radius: 20px;
        width: 96%;
        max-width: 480px;
        max-height: 90vh;
        overflow-y: auto;
        box-shadow: 0 25px 60px rgba(0, 0, 0, 0.2);
        transform: scale(0.94) translateY(10px);
        transition: transform 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
        position: relative;
    }

    #booking-detail-popup.active #booking-detail-popup-inner {
        transform: scale(1) translateY(0);
    }

    body.dark-theme #booking-detail-popup-inner {
        background: #0f172a;
        border: 1px solid rgba(51, 65, 85, 0.7);
    }

    #booking-detail-popup-header {
        background: linear-gradient(135deg, #1d4ed8, #0ea5e9);
        color: #fff;
        border-radius: 20px 20px 0 0;
        padding: 1.25rem 1.5rem 1rem;
        position: relative;
    }

    #booking-detail-popup-header h3 {
        margin: 0 0 0.15rem;
        font-size: 1.2rem;
        font-weight: 700;
    }

    #booking-detail-popup-header .popup-date {
        font-size: 0.85rem;
        opacity: 0.85;
    }

    #booking-detail-popup-close {
        position: absolute;
        top: 1rem;
        right: 1rem;
        background: rgba(255, 255, 255, 0.2);
        border: none;
        color: #fff;
        font-size: 1.2rem;
        width: 32px;
        height: 32px;
        border-radius: 50%;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        line-height: 1;
        transition: background 0.2s;
    }

    #booking-detail-popup-close:hover {
        background: rgba(255, 255, 255, 0.35);
    }

    #booking-detail-popup-body {
        padding: 1.25rem 1.5rem;
    }

    .popup-booking-card {
        background: #f8fafc;
        border-radius: 14px;
        border: 1px solid #e2e8f0;
        padding: 1rem;
        margin-bottom: 0.85rem;
        transition: box-shadow 0.2s;
    }

    .popup-booking-card:hover {
        box-shadow: 0 4px 14px rgba(37, 99, 235, 0.1);
        border-color: rgba(147, 197, 253, 0.7);
    }

    body.dark-theme .popup-booking-card {
        background: rgba(30, 41, 59, 0.8);
        border-color: rgba(51, 65, 85, 0.7);
    }

    .popup-booking-card .card-customer {
        font-size: 1.05rem;
        font-weight: 700;
        color: #1e40af;
        margin-bottom: 0.15rem;
    }

    body.dark-theme .popup-booking-card .card-customer {
        color: #93c5fd;
    }

    .popup-booking-card .card-row {
        display: flex;
        align-items: center;
        gap: 0.4rem;
        font-size: 0.82rem;
        color: #475569;
        margin-top: 0.3rem;
        flex-wrap: wrap;
    }

    body.dark-theme .popup-booking-card .card-row {
        color: #94a3b8;
    }

    .popup-booking-card .card-row i {
        color: #2563eb;
        width: 14px;
        flex-shrink: 0;
    }

    body.dark-theme .popup-booking-card .card-row i {
        color: #60a5fa;
    }

    .popup-booking-card .card-row .badge {
        padding: 0.15rem 0.5rem;
        border-radius: 6px;
        font-size: 0.75rem;
        font-weight: 600;
    }

    .popup-booking-card .card-row .badge-pending {
        background: #fef2f2;
        color: #dc2626;
        border: 1px solid #fca5a5;
    }

    .popup-booking-card .card-row .badge-paid {
        background: #f0fdf4;
        color: #16a34a;
        border: 1px solid #86efac;
    }

    .popup-booking-card .card-row .badge-past {
        background: #f8fafc;
        color: #64748b;
        border: 1px solid #cbd5e1;
    }

    .popup-booking-card .view-details-link {
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        margin-top: 0.6rem;
        background: #eff6ff;
        color: #1d4ed8;
        border: 1px solid #bfdbfe;
        border-radius: 8px;
        padding: 0.35rem 0.8rem;
        font-size: 0.8rem;
        font-weight: 600;
        text-decoration: none;
        transition: all 0.2s;
    }

    .popup-booking-card .view-details-link:hover {
        background: #2563eb;
        color: #fff;
        border-color: #2563eb;
    }

    body.dark-theme .popup-booking-card .view-details-link {
        background: rgba(30, 58, 138, 0.4);
        color: #93c5fd;
        border-color: rgba(59, 130, 246, 0.3);
    }

    body.dark-theme .popup-booking-card .view-details-link:hover {
        background: #2563eb;
        color: #fff;
    }

    /* ===== Modal (Calendar Day) ===== */
    #calendar-day-bookings-modal .modal-content {
        border-radius: 20px;
        overflow: hidden;
        border: none;
        box-shadow: 0 20px 50px rgba(0, 0, 0, 0.2);
    }

    #calendar-day-bookings-modal h3 {
        background: linear-gradient(135deg, #1d4ed8, #0ea5e9);
        color: #fff !important;
        border-radius: 16px 16px 0 0;
        padding: 1.1rem 1.5rem;
        margin: 0 0 1rem !important;
        font-size: 1.1rem !important;
        border-bottom: none !important;
    }

    .modal-booking-entry {
        border: 1px solid #e2e8f0;
        border-left: 4px solid #2563eb;
        padding: 0.8rem 1rem;
        margin-bottom: 0.75rem;
        border-radius: 10px;
        background: #f8fafc;
        transition: box-shadow 0.2s;
    }

    .modal-booking-entry:hover {
        box-shadow: 0 4px 12px rgba(37, 99, 235, 0.12);
    }

    body.dark-theme .modal-booking-entry {
        background: rgba(30, 41, 59, 0.7);
        border-color: rgba(51, 65, 85, 0.6);
        border-left-color: #3b82f6;
    }

    .modal-booking-entry.regular {
        border-left-color: #2563eb;
    }

    .modal-booking-entry.highlighted {
        border-left-color: #f59e0b;
        background: #fffbeb;
    }

    body.dark-theme .modal-booking-entry.highlighted {
        background: rgba(120, 80, 0, 0.15);
    }

    .modal-booking-entry p {
        margin: 0 0 0.35rem;
        font-size: 0.9rem;
        color: var(--color-text);
    }

    .modal-customer-name {
        font-weight: 700;
        font-size: 1rem !important;
        color: #1d4ed8 !important;
    }

    body.dark-theme .modal-customer-name {
        color: #60a5fa !important;
    }

    .modal-room-names {
        font-size: 0.82rem;
        color: #475569;
    }

    /* ===== Calendar Summary KPI ===== */
    .calendar-summary-section .kpi-box {
        border-radius: 12px;
        border: 1px solid #dbeafe;
        background: linear-gradient(145deg, #eff6ff, #fff);
        transition: transform 0.2s, box-shadow 0.2s;
    }

    .calendar-summary-section .kpi-box:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 16px rgba(37, 99, 235, 0.1);
    }

    .calendar-summary-section .kpi-box h4 {
        font-size: 0.78rem;
        font-weight: 600;
        margin-bottom: 0.3rem;
        color: #64748b;
        margin-top: 0;
        text-transform: uppercase;
        letter-spacing: 0.04em;
    }

    .calendar-summary-section .kpi-box p {
        font-size: 1.5rem;
        font-weight: 700;
        color: #1d4ed8;
        margin-bottom: 0;
    }

    /* ===== Timeline Gantt Chart ===== */
    .timeline-container {
        scrollbar-width: thin;
        scrollbar-color: #93c5fd transparent;
    }

    .timeline-grid {
        display: flex;
        flex-direction: column;
        min-width: 800px;
    }

    .timeline-header-row {
        display: flex;
        background: linear-gradient(135deg, #1e40af, #1d4ed8);
        border-bottom: 2px solid #1d4ed8;
        position: sticky;
        top: 0;
        z-index: 10;
        border-radius: 10px 10px 0 0;
        overflow: hidden;
    }

    .timeline-room-row {
        display: flex;
        border-bottom: 1px dashed rgba(147, 197, 253, 0.3);
    }

    .timeline-cell {
        padding: 0.4rem;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 0.78rem;
    }

    .room-header,
    .room-name {
        width: 80px;
        min-width: 80px;
        position: sticky;
        left: 0;
        background: var(--color-surface);
        border-right: 2px solid rgba(147, 197, 253, 0.3);
        font-weight: 600;
        z-index: 5;
        color: #1d4ed8;
    }

    .room-header {
        background: rgba(255, 255, 255, 0.1);
        color: rgba(255, 255, 255, 0.9);
        z-index: 11;
        border-right-color: rgba(255, 255, 255, 0.15);
    }

    .day-header {
        font-weight: 600;
        color: rgba(255, 255, 255, 0.85);
        font-size: 0.75rem;
        border-right: 1px dashed rgba(255, 255, 255, 0.12);
    }

    .day-header:last-child {
        border-right: none;
    }

    .grid-cell {
        border-right: 1px dashed rgba(147, 197, 253, 0.2);
        min-height: 40px;
    }

    .grid-cell:last-child {
        border-right: none;
    }

    .timeline-bar {
        position: absolute;
        top: 50%;
        transform: translateY(-50%);
        height: 26px;
        border-radius: 13px;
        display: flex;
        align-items: center;
        padding: 0 0.55rem;
        font-size: 0.72rem;
        color: #fff;
        cursor: pointer;
        box-shadow: 0 2px 6px rgba(0, 0, 0, 0.15);
        z-index: 2;
        transition: all 0.2s ease;
        margin-left: 2px;
        box-sizing: border-box;
        width: calc(100% - 4px) !important;
    }

    .timeline-bar:hover {
        transform: translateY(-50%) scale(1.03);
        box-shadow: 0 5px 14px rgba(0, 0, 0, 0.2);
        z-index: 3;
    }

    .timeline-bar.normal {
        background: linear-gradient(135deg, #2563eb, #0ea5e9);
    }

    .timeline-bar.highlighted {
        background: linear-gradient(135deg, #f59e0b, #ef4444);
    }

    body.dark-theme .timeline-bar.normal {
        background: linear-gradient(135deg, #1d4ed8, #0284c7);
    }

    body.dark-theme .timeline-bar.highlighted {
        background: linear-gradient(135deg, #d97706, #dc2626);
    }

    .tbkg-name {
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        font-weight: 500;
        font-size: 0.73rem;
    }
</style>

<div id="calendar-day-bookings-modal" class="modal-overlay">
    <div class="modal-content" style="max-width: 90%; width:500px; border-radius: 20px; overflow: hidden; padding: 0;">
        <button class="modal-close" aria-label="Close" style="position:absolute;top:0.85rem;right:1rem;z-index:10;background:rgba(255,255,255,0.2);border:none;color:#fff;font-size:1.3rem;width:34px;height:34px;border-radius:50%;cursor:pointer;display:flex;align-items:center;justify-content:center;">&#x2715;</button>
        <h3 id="calendar-day-modal-title" style="background:linear-gradient(135deg,#1d4ed8,#0ea5e9);color:#fff;margin:0;padding:1.1rem 1.5rem;font-size:1.1rem;font-weight:700;">
            &#128197; รายการจองสำหรับวันที่ <span id="modal-selected-date-display"></span>
        </h3>
        <div id="calendar-day-modal-body" style="max-height: 65vh; overflow-y: auto; padding: 1rem 1.25rem;">
        </div>
        <div class="button-group" style="padding: 0.75rem 1.25rem; border-top: 1px solid #e2e8f0; justify-content: flex-end; background: #f8fafc;">
            <button type="button" class="button outline-secondary modal-close" style="border-radius: 10px;">ปิด</button>
        </div>
    </div>
</div>

<!-- Booking Detail Popup -->
<div id="booking-detail-popup">
    <div id="booking-detail-popup-inner">
        <div id="booking-detail-popup-header">
            <button id="booking-detail-popup-close">&#x2715;</button>
            <h3 id="popup-customer-name">ข้อมูลการจอง</h3>
            <div class="popup-date" id="popup-date-range"></div>
        </div>
        <div id="booking-detail-popup-body">
            <p style="text-align:center; color:#94a3b8; padding: 2rem 0;">กำลังโหลดข้อมูล...</p>
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
    // NOTE: This script block runs AFTER the layout, so DOM is already ready.
    (function() {
        // ===== Swipe Navigation =====
        const calendarTable = document.querySelector('.table-responsive');
        if (calendarTable) {
            let touchstartX = 0;
            let touchendX = 0;
            const minSwipeDistance = 50;
            calendarTable.addEventListener('touchstart', e => {
                touchstartX = e.changedTouches[0].screenX;
            }, {
                passive: true
            });
            calendarTable.addEventListener('touchend', e => {
                touchendX = e.changedTouches[0].screenX;
                const diff = touchstartX - touchendX;
                if (Math.abs(diff) > minSwipeDistance) {
                    const navLinks = document.querySelectorAll('.calendar-navigation a.button');
                    if (navLinks.length >= 2) {
                        window.location.href = diff > 0 ? navLinks[1].href : navLinks[0].href;
                    }
                }
            }, {
                passive: true
            });
        }

        // ===== Booking Detail Popup =====
        const popup = document.getElementById('booking-detail-popup');
        const popupBody = document.getElementById('booking-detail-popup-body');
        const popupCustomerName = document.getElementById('popup-customer-name');
        const popupDateRange = document.getElementById('popup-date-range');
        const popupClose = document.getElementById('booking-detail-popup-close');

        function thaiNum(n) {
            return n;
        }

        function openBookingPopup(bookingIds, groupId, cardEl) {
            if (!popup || !bookingIds) return;
            popup.classList.add('active');
            document.body.style.overflow = 'hidden';

            // Try to build popup content from JS data
            const allBookings = (typeof bookingsByDateAndGroupJS !== 'undefined') ? bookingsByDateAndGroupJS : {};

            // Find matching groups
            let found = null;
            for (const key in allBookings) {
                const g = allBookings[key];
                const gIds = (g.booking_ids || []).map(String);
                const targetIds = bookingIds.split(',').map(s => s.trim());
                if (targetIds.some(id => gIds.includes(id))) {
                    found = g;
                    break;
                }
            }

            if (found) {
                popupCustomerName.textContent = found.customer_name || 'การจอง';
                const rooms = (found.rooms || []).map(r => r.display).join(', ');
                const nights = 0; // Will be computed from the card data attribute if available
                const nightsData = cardEl ? cardEl.dataset.nights : '';
                const checkinData = cardEl ? cardEl.dataset.checkin : '';
                const checkoutData = cardEl ? cardEl.dataset.checkout : '';

                popupDateRange.textContent = checkinData ? `In: ${checkinData}  →  Out: ${checkoutData}` : rooms;

                const paymentBadge = found.has_pending_payment_group ?
                    '<span class="badge badge-pending">&#128205; ค้างชำระ</span>' :
                    '<span class="badge badge-paid">&#9989; ชำระแล้ว</span>';

                const nightsHtml = nightsData ? `<div class="card-row"><i class="fa-solid fa-moon"></i> <span>${nightsData} คืน</span></div>` : '';
                const checkinHtml = checkinData ? `<div class="card-row"><i class="fa-solid fa-arrow-right-to-bracket"></i> เข้า: ${checkinData}</div>` : '';
                const checkoutHtml = checkoutData ? `<div class="card-row"><i class="fa-solid fa-arrow-right-from-bracket"></i> ออก: ${checkoutData}</div>` : '';

                const targetBookingId = (found.booking_ids || [])[0];
                const detailLink = targetBookingId ?
                    `<a href="/hotel_booking/pages/edit_booking_group.php?booking_id=${targetBookingId}" class="view-details-link"><i class="fa-solid fa-file-lines"></i> ดูรายละเอียดเต็ม</a>` :
                    '';

                popupBody.innerHTML = `
                    <div class="popup-booking-card">
                        <div class="card-customer">${found.customer_name || ''}</div>
                        <div class="card-row"><i class="fa-solid fa-door-open"></i> ห้อง: <strong>${rooms}</strong></div>
                        ${checkinHtml}
                        ${checkoutHtml}
                        ${nightsHtml}
                        <div class="card-row">${paymentBadge}</div>
                        ${found.customer_phone ? `<div class="card-row"><i class="fa-solid fa-phone"></i> ${found.customer_phone}</div>` : ''}
                        ${detailLink}
                    </div>`;
            } else {
                popupCustomerName.textContent = 'ข้อมูลการจอง';
                popupDateRange.textContent = '';
                const firstId = (bookingIds || '').split(',')[0].trim();
                const editUrl = firstId ? `/hotel_booking/pages/edit_booking_group.php?booking_id=${firstId}` : '#';
                popupBody.innerHTML = `<div class="popup-booking-card">
                    <div class="card-row" style="justify-content:center;"><i class="fa-solid fa-circle-info"></i>&nbsp;กดดูรายละเอียดเต็มได้เลยครับ</div>
                    <div style="text-align:center;margin-top:0.75rem;"><a href="${editUrl}" class="view-details-link"><i class="fa-solid fa-file-lines"></i> ดูรายละเอียด</a></div>
                </div>`;
            }
        }

        function closePopup() {
            if (popup) {
                popup.classList.remove('active');
                document.body.style.overflow = '';
            }
        }

        if (popupClose) {
            popupClose.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                closePopup();
            });
        }

        if (popup) {
            popup.addEventListener('click', function(e) {
                // Close only when clicking the dark backdrop (not the inner card)
                if (e.target === popup) closePopup();
            });
        }

        document.addEventListener('keydown', e => {
            if (e.key === 'Escape') closePopup();
        });

        // Attach popup to upcoming-card clicks
        document.querySelectorAll('.upcoming-card').forEach(card => {
            card.addEventListener('click', function(e) {
                e.stopPropagation();
                const ids = this.dataset.bookingIds || '';
                const groupId = this.dataset.bookingGroupId || '';
                openBookingPopup(ids, groupId, this);
            });
        });
    })();
</script>