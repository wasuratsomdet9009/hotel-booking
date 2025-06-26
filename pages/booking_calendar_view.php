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


// Step 2: Organize bookings by date and group using the new logic
$bookingsByDateAndGroup = [];
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
                        'custom_color' => null
                    ];
                }
                // Add rooms that are active on $dateKeyIter
                foreach($groupDetails['bookings'] as $bkg){
                    $bkg_checkin = new DateTime($bkg['checkin_datetime']);
                    $bkg_checkout = new DateTime($bkg['checkout_datetime_calculated']);
                    if($currentIterDate >= $bkg_checkin && $currentIterDate < $bkg_checkout){
                        $room_exists = false;
                        foreach($bookingsByDateAndGroup[$finalGroupKeyForDisplay]['rooms'] as $existing_room){
                            if($existing_room['id'] == $bkg['room_id']){
                                $room_exists = true;
                                break;
                            }
                        }
                        if(!$room_exists){
                             $bookingsByDateAndGroup[$finalGroupKeyForDisplay]['rooms'][] = [
                                'display' => h($bkg['zone'] . $bkg['room_number']),
                                'id' => $bkg['room_id']
                            ];
                        }
                    }
                }
                // If after checking all bookings in the group, no room is active for this $dateKeyIter, remove the entry
                if(empty($bookingsByDateAndGroup[$finalGroupKeyForDisplay]['rooms'])){
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

$prevMonth = $currentMonth - 1; $prevYear = $currentYear;
if ($prevMonth == 0) { $prevMonth = 12; $prevYear--; }
$nextMonth = $currentMonth + 1; $nextYear = $currentYear;
if ($nextMonth == 13) { $nextMonth = 1; $nextYear++; }
$bidsQueryParam = !empty($highlightBookingIds) ? "&bids=" . implode(',', $highlightBookingIds) : "";

ob_start();
?>

<div class="container">
    <h2 style="margin-bottom: 1rem; padding-bottom: 0.5rem; border-bottom: 1px solid var(--color-border);">
        <?= h($pageTitle) ?>
    </h2>

    <section class="report-section calendar-summary-section" style="margin-bottom: 1.5rem; padding: 1.25rem; background-color: var(--color-surface-alt); border-radius: var(--border-radius-lg); box-shadow: var(--shadow-sm);">
        <h3 style="font-size: 1.2rem; margin-top:0; margin-bottom: 1rem; color: var(--color-primary-dark); padding-bottom: 0.5rem; border-bottom: 1px solid var(--color-border);">
            <i class="fas fa-calendar-check" style="margin-right: 0.5em;"></i>สรุปการจองสำหรับเดือน <?= h($firstDayOfMonth->format('F Y')) ?> (<?= thaimonthfull($firstDayOfMonth->format('F')) . ' ' . ($currentYear + 543) ?>)
        </h3>
        <div class="kpi-summary-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 1rem;">
            <div class="kpi-box" style="padding: 0.8rem; background-color:var(--color-surface); border-radius: var(--border-radius-md);">
                <h4 style="font-size:0.85rem; margin-top:0; margin-bottom: 0.25rem; color: var(--color-text-muted);">การจองทั้งหมด</h4>
                <p style="font-size: 1.6rem; color: var(--color-primary); margin-bottom:0; font-weight: bold;"><?= $summaryTotalBookings ?></p>
            </div>
            <div class="kpi-box" style="padding: 0.8rem; background-color:var(--color-surface); border-radius: var(--border-radius-md);">
                <h4 style="font-size:0.85rem; margin-top:0; margin-bottom: 0.25rem; color: var(--color-text-muted);">การพักค้างคืน (ครั้ง)</h4>
                <p style="font-size: 1.6rem; color: var(--color-info); margin-bottom:0; font-weight: bold;"><?= $summaryTotalOvernightStays ?></p>
            </div>
            <div class="kpi-box" style="padding: 0.8rem; background-color:var(--color-surface); border-radius: var(--border-radius-md);">
                <h4 style="font-size:0.85rem; margin-top:0; margin-bottom: 0.25rem; color: var(--color-text-muted);">Room-Nights (ค้างคืน)</h4>
                <p style="font-size: 1.6rem; color: var(--color-secondary); margin-bottom:0; font-weight: bold;"><?= $summaryTotalRoomNights ?></p>
            </div>
            <div class="kpi-box" style="padding: 0.8rem; background-color:var(--color-surface); border-radius: var(--border-radius-md);">
                <h4 style="font-size:0.85rem; margin-top:0; margin-bottom: 0.25rem; color: var(--color-text-muted);">การพักชั่วคราว (ครั้ง)</h4>
                <p style="font-size: 1.6rem; color: var(--color-warning-dark); margin-bottom:0; font-weight: bold;"><?= $summaryTotalShortStays ?></p>
            </div>
        </div>
    </section>
    <?php if ($transactionCustomerName && $transactionCheckinDate && $transactionCheckoutDate && !empty($highlightedBookingsDataForSummary)): ?>
    <div class="report-section" style="background-color: #e6f7ff; border-left: 5px solid var(--color-info); margin-bottom: 2rem; padding: 1rem;">
        <h3 style="color: var(--color-primary-dark); margin-top:0;">สรุปการจอง (สำหรับรายการที่ส่งมา): <?= h($transactionCustomerName) ?></h3>
        <p><strong>ช่วงวันที่จอง (สำหรับรายการที่ส่งมา):</strong> <?= h($transactionCheckinDate->format('d M Y')) ?> - <?= h($transactionCheckoutDate->format('d M Y H:i น.')) ?></p>
        <p><strong>จำนวนห้องที่เกี่ยวข้องกับรายการที่ส่งมา:</strong> <?= count($highlightedBookingsDataForSummary) ?></p>
    </div>
    <?php endif; ?>

    <div class="calendar-navigation" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
        <a href="?month=<?= $prevMonth ?>&year=<?= $prevYear ?><?= $bidsQueryParam ?>" class="button outline-secondary">&laquo; เดือนก่อนหน้า</a>
        <h3 style="margin:0; color: var(--color-primary-dark);"><?= $firstDayOfMonth->format('F Y') ?> (<?= thaimonthfull($firstDayOfMonth->format('F')) . ' ' . ($currentYear + 543) ?>)</h3>
        <a href="?month=<?= $nextMonth ?>&year=<?= $nextYear ?><?= $bidsQueryParam ?>" class="button outline-secondary">เดือนถัดไป &raquo;</a>
    </div>

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

                        if ($isToday) {
                            $cellClass .= ' today';
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
                                    if ($groupData_php['is_highlighted_group']) { $entryClassDesktop .= " highlighted-transaction"; }
                                    else { $entryClassDesktop .= " regular-booking-entry"; }
                                    
                                    $roomNamesDesktop = array_map(function($room) { return h($room['display']); }, $groupData_php['rooms']);
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
     <div style="margin-top: 2.5rem; padding-top:1.5rem; border-top: 1px dashed var(--color-border); text-align: center;" class="button-group">
            <a href="/hotel_booking/pages/index.php" class="button primary" style="padding: 0.8rem 1.5rem;">กลับไปหน้าหลัก Dashboard</a>
            <a href="/hotel_booking/pages/booking.php?mode=multi" class="button outline-secondary" style="margin-left: 10px; padding: 0.8rem 1.5rem;">ทำการจองหลายห้องเพิ่ม</a>
    </div>
</div>
<style>
    /* === Calendar View Enhancements === */

    /* Calendar Table & Cells */
    .calendar-table {
        border-collapse: collapse; /* ลดปัญหาเส้นขอบซ้อนกัน */
        border-spacing: 0;
        box-shadow: var(--shadow-md); /* เพิ่มเงาให้ตาราง */
        border-radius: var(--border-radius-lg); /* ทำให้มุมโค้งมน */
        overflow: hidden; /* สำหรับ border-radius */
    }

    .calendar-table th {
        background-color: var(--color-primary-dark); /* ใช้สีเข้มสำหรับ Header */
        color: var(--color-header-text);
        padding: 0.75rem 0.5rem; /* ปรับ Padding */
        font-weight: 600; /* เพิ่มความหนาตัวอักษร */
        font-size: 0.9rem;
    }

    .calendar-day {
        border: 1px solid var(--color-border);
        vertical-align: top;
        height: 130px; /* ปรับความสูงตามความเหมาะสม */
        min-width: 100px; /* กำหนดความกว้างขั้นต่ำ */
        padding: 0.5rem; /* ปรับ Padding */
        position: relative; /* สำหรับองค์ประกอบภายในที่ต้องการ absolute positioning */
        transition: background-color 0.2s ease-in-out;
    }

    .calendar-day.empty {
        background-color: var(--color-bg); /* สีพื้นหลังสำหรับวันที่ว่าง */
        opacity: 0.7;
    }
    body.dark-theme .calendar-day.empty {
        background-color: var(--dt-color-surface-alt); /* สีสำหรับ Dark Theme */
        opacity: 0.5;
    }

    .calendar-day.today {
        background-color: var(--color-calendar-today-bg, #fffadc); /* ใช้ตัวแปรจาก :root */
        border: 2px solid var(--color-calendar-today-border, var(--color-warning-dark)); /* เส้นขอบเด่นขึ้น */
    }
    body.dark-theme .calendar-day.today {
        background-color: var(--dt-color-calendar-today-bg, #423c01);
        border-color: var(--dt-color-calendar-today-border, var(--dt-color-warning-dark));
    }

    .date-number {
        font-size: 0.9em;
        font-weight: 600;
        color: var(--color-text);
        text-align: right;
        padding: 3px 5px;
        margin-bottom: 4px; /* เพิ่มระยะห่างจากรายการจอง */
        z-index: 2;
        position: sticky; /* หรือ relative ถ้าไม่ต้องการให้ติดขอบ ao */
        top: 5px;
        right: 5px;
    }

    .calendar-day.today .date-number {
        color: var(--color-black); /* หรือ var(--dt-color-text) ถ้า today bg เข้ม */
        background-color: var(--color-warning); /* ปรับสีให้เด่น */
        border-radius: 50%;
        width: 1.8em;
        height: 1.8em;
        line-height: 1.8em;
        text-align: center;
        padding: 0;
        font-weight: 700;
    }
    body.dark-theme .calendar-day.today .date-number {
        color: var(--color-black); /* หรือ var(--dt-color-text) */
        background-color: var(--dt-color-warning);
    }

    /* Booking Entries Styling */
    .booking-entries-area { /* This is for DESKTOP only now */
        margin-top: 2px; 
        max-height: calc(130px - 2.5em - 25px - 38px); /* cell_height - date_number_approx_height - cell_padding_approx - fab_area_height */
        overflow-y: auto;
        padding-right: 4px; 
    }
    .booking-summary-mobile { /* For MOBILE only */
        padding-top: 8px; /* Add some space from FAB area */
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
    .mobile-only { display: none; } /* Hide on desktop by default */
    .desktop-only { display: block; } /* Show on desktop by default */

    @media (max-width: 768px) { /* Example breakpoint for mobile */
        .mobile-only { display: block; }
        .desktop-only { display: none; }
        .booking-entries-area { /* Reset max-height if it's hidden on mobile, or adjust as needed */
            max-height: none;
        }
    }


    .booking-group {
        border-radius: var(--border-radius-sm);
        padding: 5px 8px;
        margin-bottom: 5px;
        font-size: 0.78rem;
        line-height: 1.35;
        overflow: hidden;
        text-overflow: ellipsis;
        display: -webkit-box;
        -webkit-line-clamp: 2; 
        -webkit-box-orient: vertical;
        border: 1px solid transparent;
        transition: transform 0.15s ease-out, box-shadow 0.15s ease-out;
        cursor: pointer;
        position: relative;
    }
    .booking-group:hover {
        transform: translateY(-1px);
        box-shadow: var(--shadow-md);
        z-index: 5; 
        position: relative; 
        -webkit-line-clamp: unset;
        overflow: visible; /* <<< MODIFIED: Ensure the alert is visible on hover if group expands */
    }

    .regular-booking-entry {
        background-color: var(--color-surface); 
        border-left: 4px solid var(--color-secondary); 
        color: var(--color-text);
    }
    body.dark-theme .regular-booking-entry {
        background-color: var(--dt-color-surface);
        border-left-color: var(--dt-color-secondary);
        color: var(--dt-color-text);
    }
    .regular-booking-entry:hover {
        border-left-color: var(--color-secondary-dark);
    }

    .highlighted-transaction {
        background-color: var(--color-info-bg-light);
        border-left: 4px solid var(--color-info); 
        color: var(--color-info-dark);
        font-weight: 500;
    }
    body.dark-theme .highlighted-transaction {
        background-color: var(--dt-color-info-bg-light);
        border-left-color: var(--dt-color-info);
        color: var(--dt-color-text);
    }
    .highlighted-transaction:hover {
        background-color: var(--color-info);
        color: var(--color-white);
        border-left-color: var(--color-info-dark);
    }
    body.dark-theme .highlighted-transaction:hover {
        background-color: var(--dt-color-info-dark);
        color: var(--dt-color-text); 
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
        position: relative; /* Needed for absolute positioning of the alert icon */
        display: inline-block; /* Ensures proper positioning context for the child */
    }
    body.dark-theme .booking-customer-name-highlight {
        color: var(--dt-link-color);
    }
    
    /* --- NEW/UPDATED CSS --- */
    .calendar-pending-payment-alert {
        position: absolute;
        top: -5px;      /* ปรับตำแหน่งตามความเหมาะสม */
        right: -12px;   /* ปรับตำแหน่งตามความเหมาะสม, ให้เยื้องออกไปทางขวาเล็กน้อย */
        font-size: 0.7em; /* ขนาดไอคอน/ตัวอักษร */
        background-color: var(--color-alert, #dc3545); /* สีพื้นหลัง */
        color: var(--color-white, white);          /* สีตัวอักษร */
        border-radius: 50%;   /* ทำให้เป็นวงกลม */
        padding: 1px 4px;   /* ระยะห่างภายใน */
        line-height: 1;
        z-index: 5;           /* ให้อยู่เหนือ customer name */
        box-shadow: 0 0 4px rgba(0,0,0,0.4); /* เพิ่มเงาให้ดูเด่นขึ้น */
        animation: pulse-warning-text 1.5s infinite ease-in-out; /* Add pulse animation */
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
        margin-top:0; 
    }
    .calendar-summary-section .kpi-box p {
        font-size: 1.4rem; 
        font-weight: 600;
        margin-bottom:0; 
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
    .modal-booking-entry.regular { border-left-color: var(--color-secondary); }
    .modal-booking-entry.highlighted { border-left-color: var(--color-info); background-color: var(--color-info-bg-light); }
    .modal-booking-entry p { margin: 0 0 0.4rem 0; }
    .modal-booking-entry p:last-child { margin-bottom: 0; }
    .modal-customer-name { font-weight: 600; font-size: 1.05rem; color: var(--color-primary-dark); }
    .modal-room-names { font-size: 0.9rem; color: var(--color-text); }


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
function thaimonthfull($montheng) {
    $thaimonths = [
        'January' => 'มกราคม', 'February' => 'กุมภาพันธ์', 'March' => 'มีนาคม',
        'April' => 'เมษายน', 'May' => 'พฤษภาคม', 'June' => 'มิถุนายน',
        'July' => 'กรกฎาคม', 'August' => 'สิงหาคม', 'September' => 'กันยายน',
        'October' => 'ตุลาคม', 'November' => 'พฤศจิกายน', 'December' => 'ธันวาคม'
    ];
    return $thaimonths[$montheng] ?? $montheng;
}

$content = ob_get_clean();
require_once __DIR__ . '/../templates/layout.php';
?>
