<?php
// FILEX: hotel_booking/pages/booking.php
require_once __DIR__ . '/../bootstrap.php';
require_login(); // ตรวจสอบว่าล็อกอินหรือยัง

$editBookingId = isset($_GET['edit_booking_id']) ? (int)$_GET['edit_booking_id'] : null;
$isMultiRoomMode = !$editBookingId && isset($_GET['mode']) && $_GET['mode'] === 'multi';

// --- START: Modified section for initialCheckinDatetimeValue ---
$initialCheckinDatetimeValue = '';
$isCalendarPrefill = false;
$disableCheckinNow = false;
$isCheckinTimeReadOnly = false; // Initial default state

// Helper function for the new default check-in time logic
if (!function_exists('getHotelDefaultCheckinTime')) {
    function getHotelDefaultCheckinTime(bool $fromCalendar = false, ?string $selectedDateStr = null): string
    {
        $tz = new \DateTimeZone('Asia/Bangkok');

        if ($fromCalendar && $selectedDateStr) {
            // การจองจากปฏิทิน กำหนดเวลาเป็น 14:00 น. ของวันที่เลือก
            $selectedDateObj = new \DateTime($selectedDateStr, $tz);
            return $selectedDateObj->format('Y-m-d') . 'T14:00';
        }

        // การจองทั่วไป (ไม่ใช่จากปฏิทิน) จะใช้เวลาปัจจุบัน
        $now = new \DateTime('now', $tz);
        return $now->format('Y-m-d\TH:i');
    }
}

// Determine $initialCheckinDatetimeValue and $isCheckinTimeReadOnly
if ($editBookingId) {
    // In edit mode, check-in time is generally fixed to the existing booking time and read-only.
    $disableCheckinNow = true;
    $isCheckinTimeReadOnly = true; // Will be set to true for edit mode
    // $initialCheckinDatetimeValue is set after $bookingData fetch, later in the script
} elseif (isset($_GET['calendar_checkin_date'])) { // Check for calendar_checkin_date first, regardless of mode
    $calendarDateStr = $_GET['calendar_checkin_date'];
    if (preg_match("/^[0-9]{4}-(0[1-9]|1[0-2])-(0[1-9]|[1-2][0-9]|3[0-1])$/", $calendarDateStr)) {
        $initialCheckinDatetimeValue = getHotelDefaultCheckinTime(true, $calendarDateStr); // fromCalendar = true
        $isCalendarPrefill = true; //
        $disableCheckinNow = true; // Disable "Check-in Now" if prefilled from calendar
        $isCheckinTimeReadOnly = true; // Make check-in time readonly if prefilled from calendar
    } else {
        error_log("Invalid calendar_checkin_date format received: " . $_GET['calendar_checkin_date']);
        // Fallback to general default for new booking if calendar date is invalid
        $initialCheckinDatetimeValue = getHotelDefaultCheckinTime(false); //
    }
} else {
    // This case is for new bookings (single or multi) NOT from calendar
    $initialCheckinDatetimeValue = getHotelDefaultCheckinTime(false); // General default (current time)
}
// --- END: Modified section for initialCheckinDatetimeValue ---


// Page Title Logic
if ($isMultiRoomMode) {
    $pageTitle = 'จองหลายห้องพัก (ชื่อเดียว-สลิปเดียว)';
} elseif ($editBookingId) {
    $pageTitle = 'แก้ไขการจอง';
} else {
    $pageTitle = 'ฟอร์มจองห้องพัก';
}


// Fetch rooms data
$available_rooms_for_single = [];
$all_rooms_for_multi = [];
$room_details_for_js = [];

if ($isMultiRoomMode) {
    $all_rooms_stmt = $pdo->query("SELECT id, zone, room_number, price_per_day, price_short_stay, allow_short_stay, short_stay_duration_hours, ask_deposit_on_overnight FROM rooms ORDER BY zone ASC, CAST(room_number AS UNSIGNED) ASC");
    $all_rooms_for_multi = $all_rooms_stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($all_rooms_for_multi as $r_multi) {
        $room_details_for_js[$r_multi['id']] = $r_multi;
    }
} elseif (!$editBookingId && !$isMultiRoomMode) {
    $all_active_rooms_stmt = $pdo->prepare("
        SELECT
            r.id, r.zone, r.room_number, r.status AS db_actual_status,
            r.price_per_day, r.price_short_stay, r.allow_short_stay,
            r.short_stay_duration_hours, r.ask_deposit_on_overnight,
            CASE
                WHEN cb.id IS NOT NULL AND NOW() >= cb.checkout_datetime_calculated THEN 'overdue_occupied'
                WHEN r.zone = 'F' AND cb.id IS NOT NULL AND cb.booking_type = 'short_stay' AND cb.checkin_datetime <= NOW() AND NOW() < cb.checkout_datetime_calculated THEN 'f_short_occupied'
                WHEN cb.id IS NOT NULL AND cb.checkin_datetime <= NOW() AND NOW() < cb.checkout_datetime_calculated THEN 'occupied'
                WHEN cb.id IS NOT NULL AND DATE(cb.checkin_datetime) = CURDATE() AND cb.checkin_datetime > NOW() THEN 'booked'
                WHEN cb.id IS NOT NULL AND DATE(cb.checkin_datetime) > CURDATE() AND r.status = 'free' THEN 'advance_booking'
                WHEN r.status = 'free' AND cb.id IS NULL AND NOT EXISTS (SELECT 1 FROM bookings b_adv_check WHERE b_adv_check.room_id = r.id AND DATE(b_adv_check.checkin_datetime) > CURDATE()) THEN 'free'
                WHEN r.status = 'free' AND cb.id IS NULL AND EXISTS (SELECT 1 FROM bookings b_adv_check2 WHERE b_adv_check2.room_id = r.id AND DATE(b_adv_check2.checkin_datetime) > CURDATE()) THEN 'advance_booking'
                ELSE r.status
            END AS calculated_display_status
        FROM rooms r
        LEFT JOIN (
            SELECT b_inner.*
            FROM bookings b_inner
            WHERE b_inner.id = (
                SELECT b_latest.id FROM bookings b_latest
                WHERE b_latest.room_id = b_inner.room_id
                ORDER BY
                    (CASE
                        WHEN b_latest.checkin_datetime <= NOW() AND NOW() < b_latest.checkout_datetime_calculated THEN 1
                        WHEN DATE(b_latest.checkin_datetime) = CURDATE() AND b_latest.checkin_datetime > NOW() THEN 2
                        WHEN NOW() >= b_latest.checkout_datetime_calculated THEN 3
                        ELSE 4
                    END) ASC,
                    CASE
                        WHEN (CASE WHEN b_latest.checkin_datetime <= NOW() AND NOW() < b_latest.checkout_datetime_calculated THEN 1 WHEN DATE(b_latest.checkin_datetime) = CURDATE() AND b_latest.checkin_datetime > NOW() THEN 2 WHEN NOW() >= b_latest.checkout_datetime_calculated THEN 3 ELSE 4 END) = 3 THEN b_latest.checkout_datetime_calculated
                        ELSE b_latest.checkin_datetime
                    END DESC,
                    b_latest.id DESC
                LIMIT 1
            )
        ) AS cb ON cb.room_id = r.id
        ORDER BY r.zone ASC, CAST(r.room_number AS UNSIGNED) ASC
    ");
    $all_active_rooms_stmt->execute();
    $available_rooms_for_single = $all_active_rooms_stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($available_rooms_for_single as $r_single) {
        $room_details_for_js[$r_single['id']] = $r_single;
    }
}


$active_addons_stmt = $pdo->query("SELECT id, name, price FROM addon_services WHERE is_active = 1 ORDER BY name ASC");
$active_addons = $active_addons_stmt->fetchAll(PDO::FETCH_ASSOC);

$prefillRoomId = isset($_GET['room_id']) && !$editBookingId && !$isMultiRoomMode ? (int) $_GET['room_id'] : '';

$bookingData = null;
$selected_booking_addons = [];
$base_amount_for_edit_js = '0';
$total_addon_price_for_edit_js = '0';
$deposit_amount_for_edit_js = '0';
$final_amount_paid_for_edit_js = '0';
$grand_total_amount_for_edit_js = '0';
$current_booking_type_for_edit = 'overnight';


if ($editBookingId) {
    $stmt = $pdo->prepare("
        SELECT b.*, r.zone, r.room_number as current_room_number, r.price_per_day as current_price_per_day,
               r.price_short_stay as current_price_short_stay, r.allow_short_stay as current_allow_short_stay,
               r.short_stay_duration_hours as current_short_stay_duration, r.ask_deposit_on_overnight as current_ask_deposit_f
        FROM bookings b
        JOIN rooms r ON b.room_id = r.id
        WHERE b.id = ?
    ");
    $stmt->execute([$editBookingId]);
    $bookingData = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$bookingData) {
        echo "<p class=\"text-danger\" style=\"padding:1rem;\">Error: Booking with ID " . h($editBookingId) . " not found.</p>";
        $currentEditBookingIdForFallback = $editBookingId;
        $editBookingId = null;
        $pageTitle = 'ฟอร์มจองห้องพัก';

        $disableCheckinNow = false;
        $isCheckinTimeReadOnly = false;
        $isCalendarPrefill = false;
        $current_booking_type_for_edit = 'overnight';

        if (isset($_GET['calendar_checkin_date']) && isset($_GET['edit_booking_id']) && $_GET['edit_booking_id'] == $currentEditBookingIdForFallback && !$isMultiRoomMode) {
            $calendarDateStr = $_GET['calendar_checkin_date'];
            if (preg_match("/^[0-9]{4}-(0[1-9]|1[0-2])-(0[1-9]|[1-2][0-9]|3[0-1])$/", $calendarDateStr)) {
                $initialCheckinDatetimeValue = getHotelDefaultCheckinTime(true, $calendarDateStr);
                $isCalendarPrefill = true;
                $disableCheckinNow = true;
                $isCheckinTimeReadOnly = true;
            } else {
                error_log("Invalid calendar_checkin_date format on edit fallback: " . $_GET['calendar_checkin_date']);
                $initialCheckinDatetimeValue = getHotelDefaultCheckinTime(false);
            }
        } else {
            $initialCheckinDatetimeValue = getHotelDefaultCheckinTime(false);
        }
    } else {
        $current_booking_type_for_edit = $bookingData['booking_type'] ?? 'overnight';
        $room_details_for_js[$bookingData['room_id']] = [
            'id' => $bookingData['room_id'],
            'zone' => $bookingData['zone'],
            'room_number' => $bookingData['current_room_number'],
            'price_per_day' => $bookingData['current_price_per_day'],
            'price_short_stay' => $bookingData['current_price_short_stay'],
            'allow_short_stay' => $bookingData['current_allow_short_stay'],
            'short_stay_duration_hours' => $bookingData['current_short_stay_duration'],
            'ask_deposit_on_overnight' => $bookingData['current_ask_deposit_f']
        ];

        if (isset($bookingData['checkin_datetime'])) {
            try {
                $dt = new \DateTime($bookingData['checkin_datetime']);
                $initialCheckinDatetimeValue = $dt->format('Y-m-d\TH:i');
            } catch (Exception $e) {
                error_log("Error formatting checkin_datetime from bookingData: " . $e->getMessage());
                $initialCheckinDatetimeValue = getHotelDefaultCheckinTime(false);
            }
        }

        $stmt_booking_addons = $pdo->prepare("
            SELECT ba.addon_service_id, ba.quantity, ba.price_at_booking
            FROM booking_addons ba
            WHERE ba.booking_id = ?
        ");
        $stmt_booking_addons->execute([$bookingData['id']]);
        $current_booking_addons_raw = $stmt_booking_addons->fetchAll(PDO::FETCH_ASSOC);

        $current_total_addon_cost = 0;
        foreach ($current_booking_addons_raw as $ba) {
            $selected_booking_addons[$ba['addon_service_id']] = $ba['quantity'];
            $current_total_addon_cost += (float)$ba['price_at_booking'] * (int)$ba['quantity'];
        }
        $total_addon_price_for_edit_js = (string)round($current_total_addon_cost);
        $current_base_room_cost = 0;
        if ($current_booking_type_for_edit === 'short_stay') {
            $current_base_room_cost = (float)($bookingData['total_price'] ?? 0) - (float)($bookingData['deposit_amount'] ?? 0) - $current_total_addon_cost;
        } else {
            $current_base_room_cost = (float)($bookingData['price_per_night'] ?? 0) * (int)($bookingData['nights'] ?? 1);
        }
        $base_amount_for_edit_js = (string)round($current_base_room_cost);
        $deposit_amount_for_edit_js = (string)round((float)($bookingData['deposit_amount'] ?? 0));
        $final_amount_paid_for_edit_js = (string)round((float)($bookingData['amount_paid'] ?? 0));

        $val1_grand = (int)($base_amount_for_edit_js ?? '0');
        $val2_grand = (int)($total_addon_price_for_edit_js ?? '0');
        $val3_grand = (int)($deposit_amount_for_edit_js ?? '0');
        $grand_total_amount_for_edit_js = (string)($val1_grand + $val2_grand + $val3_grand);
    }
}


$form_action_url = '/hotel_booking/pages/api.php';

ob_start();
?>
<ul class="progress-bar-steps">
    <li class="progress-step active" data-step="1"><span>1</span>ห้องพัก</li>
    <li class="progress-step" data-step="2"><span>2</span>ผู้จอง</li>
    <li class="progress-step" data-step="3"><span>3</span>วัน-เวลา</li>
    <li class="progress-step" data-step="4"><span>4</span>บริการ</li>
    <li class="progress-step" data-step="5"><span>5</span>ชำระเงิน</li>
    <li class="progress-step" data-step="6"><span>6</span>ยืนยัน</li>
</ul>

<form id="booking-form" action="<?= h($form_action_url) ?>" method="POST" enctype="multipart/form-data">
    <input type="hidden" name="action" value="<?= ($editBookingId && $bookingData) ? 'update_booking_with_addons' : 'create' ?>">
    <?php if ($editBookingId && $bookingData): ?>
        <input type="hidden" name="booking_id" value="<?= h($bookingData['id']) ?>">
    <?php endif; ?>
    <?php if ($isMultiRoomMode): ?>
        <input type="hidden" name="booking_mode" value="multi">
    <?php endif; ?>

    <div id="booking-section-1" class="booking-section active-section">
        <?php if (!$editBookingId): ?>
            <div class="booking-mode-toggle-container mb-6">
                <div class="mode-toggle-group">
                    <a href="booking.php" class="mode-toggle-btn <?= !$isMultiRoomMode ? 'active' : '' ?>">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z" />
                            <polyline points="9 22 9 12 15 12 15 22" />
                        </svg>
                        ห้องเดียว
                    </a>
                    <a href="booking.php?mode=multi" class="mode-toggle-btn <?= $isMultiRoomMode ? 'active' : '' ?>">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2" />
                            <circle cx="9" cy="7" r="4" />
                            <path d="M23 21v-2a4 4 0 00-3-3.87" />
                            <path d="M16 3.13a4 4 0 010 7.75" />
                        </svg>
                        จองหลายห้อง
                    </a>
                </div>
            </div>
        <?php endif; ?>

        <h4 class="section-title">ขั้นตอนที่ 1: เลือกห้องพัก</h4>

        <?php if ($isMultiRoomMode): ?>
            <div class="form-group">
                <label>เลือกรายการห้องพัก:</label>
                <div class="rooms-selection-grid">
                    <?php if (empty($all_rooms_for_multi)): ?>
                        <div class="no-rooms-alert">ไม่มีห้องพักว่างในขณะนี้</div>
                    <?php else: ?>
                        <?php foreach ($all_rooms_for_multi as $r_multi): ?>
                            <div class="room-checkbox-card-wrapper">
                                <input type="checkbox" name="room_ids[]" value="<?= h($r_multi['id']) ?>" id="room_<?= h($r_multi['id']) ?>" class="multi-room-checkbox hidden-checkbox" data-room-id="<?= h($r_multi['id']) ?>" data-price="<?= h($r_multi['price_per_day']) ?>" data-zone="<?= h($r_multi['zone']) ?>">
                                <label class="room-checkbox-card" for="room_<?= h($r_multi['id']) ?>">
                                    <div class="room-card-status-badge <?= h($r_multi['calculated_display_status']) ?>">
                                        <?= h(ucfirst(str_replace('_', ' ', $r_multi['calculated_display_status']))) ?>
                                    </div>
                                    <div class="room-card-icon">
                                        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z" />
                                            <polyline points="9 22 9 12 15 12 15 22" />
                                        </svg>
                                    </div>
                                    <div class="room-card-content">
                                        <span class="room-name">ห้อง <?= h($r_multi['zone'] . $r_multi['room_number']) ?></span>
                                        <span class="room-price"><?= h(number_format((float)$r_multi['price_per_day'], 0)) ?>.- / คืน</span>
                                    </div>
                                    <div class="room-card-selection-indicator">
                                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                                            <polyline points="20 6 9 17 4 12" />
                                        </svg>
                                    </div>
                                </label>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <small>สถานะห้องปัจจุบันจะไม่มีผลกับการจองล่วงหน้า</small>
                <small class="text-danger"><br>หมายเหตุ: การจองหลายห้องจะใช้ราคา "ค้างคืน" และคิดค่ามัดจำมาตรฐานสำหรับทุกห้อง</small>
            </div>
        <?php else: ?>
            <div class="form-group">
                <label for="room_id">ห้องพัก</label>
                <select name="room_id" id="room_id" required <?= ($editBookingId && $bookingData) ? 'disabled' : '' ?> class="form-control">
                    <option value="">-- เลือกห้องพัก --</option>
                    <?php
                    if ($editBookingId && $bookingData && isset($room_details_for_js[$bookingData['room_id']])) {
                        $r_edit = $room_details_for_js[$bookingData['room_id']];
                        $price_text = 'ราคาปกติ: ' . h(number_format((float)$r_edit['price_per_day'], 2)) . ' บ.';
                        if ($r_edit['allow_short_stay']) {
                            $price_text .= ' / ชั่วคราว: ' . h(number_format((float)$r_edit['price_short_stay'], 2)) . ' บ. (' . h($r_edit['short_stay_duration_hours']) . ' ชม.)';
                        }
                        echo '<option value="' . h($r_edit['id']) . '" selected data-price="' . h($r_edit['price_per_day']) . '" data-price-short="' . h($r_edit['price_short_stay']) . '" data-allow-short-stay="' . h($r_edit['allow_short_stay']) . '" data-duration-short="' . h($r_edit['short_stay_duration_hours']) . '" data-zone="' . h($r_edit['zone']) . '" data-ask-deposit-f="' . h($r_edit['ask_deposit_on_overnight']) . '">' . h($r_edit['zone'] . $r_edit['room_number']) . ' (ห้องปัจจุบัน - ' . $price_text . ')</option>';
                    } else {
                        if (empty($available_rooms_for_single)) {
                            echo '<option value="" disabled>ไม่มีห้องพักที่สามารถจองได้ในขณะนี้</option>';
                        } else {
                            foreach ($available_rooms_for_single as $room_item_single) {
                                $selected = ($prefillRoomId === (int)$room_item_single['id']) ? 'selected' : '';
                                $price_text = 'ราคาปกติ: ' . h(number_format((float)$room_item_single['price_per_day'], 2)) . ' บ.';
                                if ($room_item_single['allow_short_stay']) {
                                    $price_text .= ' / ชั่วคราว: ' . h(number_format((float)$room_item_single['price_short_stay'], 2)) . ' บ. (' . h($room_item_single['short_stay_duration_hours']) . ' ชม.)';
                                }
                                $status_display_text = '';
                                $current_room_display_status = $room_item_single['calculated_display_status'] ?? $room_item_single['db_actual_status'];
                                if ($current_room_display_status === 'advance_booking') {
                                    $status_display_text = ' (มีจองล่วงหน้า)';
                                } elseif ($current_room_display_status === 'booked') {
                                    $status_display_text = ' (รอเช็คอินวันนี้)';
                                } elseif ($current_room_display_status === 'occupied' || $current_room_display_status === 'f_short_occupied' || $current_room_display_status === 'overdue_occupied') {
                                    $status_display_text = ' (ไม่ว่าง)';
                                } else {
                                    $status_display_text = ' (ว่าง)';
                                }
                                echo '<option value="' . h($room_item_single['id']) . '" data-price="' . h($room_item_single['price_per_day']) . '" data-price-short="' . h($room_item_single['price_short_stay']) . '" data-allow-short-stay="' . h($room_item_single['allow_short_stay']) . '" data-duration-short="' . h($room_item_single['short_stay_duration_hours']) . '" ' . $selected . ' data-zone="' . h($room_item_single['zone']) . '" data-ask-deposit-f="' . h($room_item_single['ask_deposit_on_overnight']) . '" data-current-status="' . h($current_room_display_status) . '">' . h($room_item_single['zone'] . $room_item_single['room_number']) . ' - ' . $price_text . h($status_display_text) . '</option>';
                            }
                        }
                    }
                    ?>
                </select>
                <?php if ($editBookingId && $bookingData): ?>
                    <small><em>ไม่สามารถเปลี่ยนห้องได้ในโหมดแก้ไข หากต้องการเปลี่ยนห้อง กรุณายกเลิกแล้วสร้างการจองใหม่</em></small>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="form-group" id="booking-type-group" style="<?= ($isMultiRoomMode || ($editBookingId && $bookingData && isset($bookingData['current_allow_short_stay']) && $bookingData['current_allow_short_stay'] == '0' && $current_booking_type_for_edit !== 'short_stay')) ? 'display:none;' : 'display:block;'; ?>">
            <label for="booking_type">ประเภทการจอง:</label>
            <select name="booking_type" id="booking_type" class="form-control" <?= ($isMultiRoomMode || ($editBookingId && $bookingData)) ? 'disabled' : '' ?>>
                <option value="overnight" <?= (($editBookingId && $current_booking_type_for_edit === 'overnight') || !$editBookingId) ? 'selected' : '' ?>>ค้างคืน (เช็คเอาท์ 12:00 น.)</option>
                <option value="short_stay" <?= ($editBookingId && $current_booking_type_for_edit === 'short_stay') ? 'selected' : '' ?>>ชั่วคราว (<span id="short_stay_duration_display"><?= h($editBookingId && $bookingData && isset($room_details_for_js[$bookingData['room_id']]) ? ($room_details_for_js[$bookingData['room_id']]['short_stay_duration_hours'] ?? DEFAULT_SHORT_STAY_DURATION_HOURS) : DEFAULT_SHORT_STAY_DURATION_HOURS) ?></span> ชม.)</option>
            </select>
            <?php if ($editBookingId && $bookingData): ?>
                <small><em>การเปลี่ยนประเภทการจอง (เช่น จากค้างคืนเป็นชั่วคราว) ยังไม่รองรับในหน้านี้ หากต้องการเปลี่ยน กรุณายกเลิกแล้วสร้างใหม่</em></small>
            <?php endif; ?>
        </div>
        <div class="navigation-buttons">
            <button type="button" class="button prev-step-btn" style="display:none;">&laquo; ย้อนกลับ</button>
            <button type="button" class="button next-step-btn">ถัดไป &raquo;</button>
        </div>
    </div>

    <div id="booking-section-2" class="booking-section">
        <h4 class="section-title">ขั้นตอนที่ 2: ข้อมูลผู้จอง</h4>
        <div class="form-group">
            <label for="customer_name">ชื่อผู้จอง (ถ้ามี)</label>
            <input type="text" name="customer_name" id="customer_name" value="<?= h($bookingData['customer_name'] ?? '') ?>" class="form-control">
        </div>
        <div class="form-group">
            <label for="customer_phone">เบอร์โทรศัพท์ (ถ้ามี)</label>
            <input type="tel" name="customer_phone" id="customer_phone" class="input-phone" value="<?= h($bookingData['customer_phone'] ?? '') ?>" placeholder="เช่น 08XXXXXXX" class="form-control">
        </div>
        <div class="navigation-buttons">
            <button type="button" class="button secondary prev-step-btn">&laquo; ย้อนกลับ</button>
            <button type="button" class="button next-step-btn">ถัดไป &raquo;</button>
        </div>
    </div>

    <div id="booking-section-3" class="booking-section">
        <h4 class="section-title">ขั้นตอนที่ 3: วันที่และเวลา</h4>
        <div class="form-group" id="normal-checkin">
            <label for="checkin_datetime">วันที่–เวลาเช็กอิน</label>
            <input type="datetime-local" name="checkin_datetime" id="checkin_datetime" value="<?= h($initialCheckinDatetimeValue) ?>" required <?= ($isCheckinTimeReadOnly || ($editBookingId && $bookingData)) ? 'readonly' : '' ?> class="form-control">
            <br>
            <?php if (!$editBookingId && !$isMultiRoomMode && !$disableCheckinNow): ?>
                <label class="checkbox-btn" for="checkin_now" style="margin-top:0.5rem;">
                    เช็กอินทันที
                    <input id="checkin_now" name="checkin_now" type="checkbox" value="1">
                    <span class="checkmark"></span>
                </label>
            <?php endif; ?>
            <?php if (($editBookingId && $bookingData) || ($isCalendarPrefill && $isCheckinTimeReadOnly)): ?>
                <small style="display:block; margin-top:0.5rem;"><em><?php echo ($editBookingId && $bookingData) ? 'ไม่สามารถเปลี่ยนวันเช็กอินได้ในโหมดแก้ไข' : 'เวลาเช็กอินถูกกำหนดจากปฏิทิน และไม่สามารถแก้ไขได้'; ?></em></small>
            <?php endif; ?>
        </div>

        <?php if ($editBookingId && $bookingData && $current_booking_type_for_edit === 'overnight'): ?>
            <div class="form-group" id="checkout_datetime_edit_group">
                <label for="checkout_datetime_edit">แก้ไข วันที่–เวลาเช็กเอาต์ (สำหรับค้างคืน):</label>
                <input type="datetime-local" name="checkout_datetime_edit" id="checkout_datetime_edit"
                    value="<?= h(isset($bookingData['checkout_datetime_calculated']) && $bookingData['checkout_datetime_calculated'] ? (new \DateTime($bookingData['checkout_datetime_calculated']))->format('Y-m-d\TH:i') : '') ?>"
                    class="form-control">
                <small>การแก้ไขเวลาเช็คเอาท์ อาจมีผลต่อการคำนวณจำนวนคืนและยอดรวม (ระบบจะคำนวณใหม่เมื่อมีการเปลี่ยนแปลง)</small>
            </div>
        <?php endif; ?>

        <div class="form-group" id="nights-group" style="<?= ($current_booking_type_for_edit === 'short_stay') ? 'display:none;' : 'display:block;' ?>">
            <label for="nights">จำนวนคืน</label>
            <div class="input-group-quantity">
                <button type="button" class="quantity-btn quantity-minus" aria-label="Decrease nights" data-field="nights" <?= ($editBookingId && $current_booking_type_for_edit === 'short_stay') ? 'disabled' : '' ?>>-</button>
                <input type="number" name="nights" id="nights" min="1" value="<?= h($bookingData['nights'] ?? 1) ?>"
                    class="quantity-input"
                    <?= ($current_booking_type_for_edit === 'overnight' || $isMultiRoomMode) ? 'required' : '' ?>
                    <?= ($editBookingId && $current_booking_type_for_edit === 'overnight' && !empty($bookingData['checkout_datetime_calculated']) && isset($_POST['checkout_datetime_edit'])) ? 'readonly' : '' ?>>
                <button type="button" class="quantity-btn quantity-plus" aria-label="Increase nights" data-field="nights" <?= ($editBookingId && $current_booking_type_for_edit === 'short_stay') ? 'disabled' : '' ?>>+</button>
            </div>
            <small id="nights-readonly-note" style="display:none; color: var(--color-text-muted); margin-top: 0.25rem;">
                <em>จำนวนคืนจะถูกคำนวณอัตโนมัติเมื่อแก้ไขวันเช็คเอาท์</em>
            </small>
        </div>
        <input type="hidden" name="short_stay_duration_hours" id="short_stay_duration_hours" value="<?= h($editBookingId && $bookingData && $bookingData['booking_type'] === 'short_stay' && isset($room_details_for_js[$bookingData['room_id']]) ? ($room_details_for_js[$bookingData['room_id']]['short_stay_duration_hours'] ?? DEFAULT_SHORT_STAY_DURATION_HOURS) : DEFAULT_SHORT_STAY_DURATION_HOURS) ?>">

        <div class="form-group" id="flexible-overnight-group">
            <label class="checkbox-btn" for="flexible_overnight_mode">
                <strong>โหมดค้างคืนแบบยืดหยุ่น:</strong> เช็คอินดึก (เช่น ตี 1 - 11 โมงเช้า) จะเช็คเอาท์เที่ยงวันเดียวกัน (ค่าบริการเท่าเดิม)
                <input id="flexible_overnight_mode" name="flexible_overnight_mode" type="checkbox" value="1" <?= ($editBookingId && $bookingData) ? 'disabled' : '' ?>>
                <span class="checkmark"></span>
            </label>
            <?php if ($editBookingId && $bookingData): ?> <small><em>โหมดนี้ใช้ได้เฉพาะการสร้างการจองใหม่</em></small> <?php endif; ?>
        </div>
        <div class="navigation-buttons">
            <button type="button" class="button secondary prev-step-btn">&laquo; ย้อนกลับ</button>
            <button type="button" class="button next-step-btn">ถัดไป &raquo;</button>
        </div>
    </div>

    <div id="booking-section-4" class="booking-section">
        <h4 class="section-title">ขั้นตอนที่ 4: บริการเสริม (Add-ons)</h4>

        <?php if ($isMultiRoomMode): ?>
            <div id="multi-room-addon-manager">
                <p>กรุณาเลือกห้องพักในขั้นตอนที่ 1 ก่อน เพื่อจัดการบริการเสริม</p>
            </div>
        <?php else: ?>
            <?php if (!empty($active_addons)): ?>
                <div class="form-group">
                    <label>บริการเสริม (Add-ons):</label>
                    <div id="addon-chips-container" class="addon-chips-flex-container">
                        <?php foreach ($active_addons as $addon): ?>
                            <?php
                            $addonId = (int)$addon['id'];
                            $isChecked = ($editBookingId && $bookingData) && isset($selected_booking_addons[$addonId]);
                            $quantity = $isChecked ? (int)$selected_booking_addons[$addonId] : 1;
                            ?>
                            <div class="addon-chip-wrapper glass-card <?= $isChecked ? 'selected' : '' ?>" style="display: flex; flex-direction: column; align-items: center; justify-content: space-between; padding: 1rem; width: 140px; text-align: center; cursor: pointer; border: 2px solid transparent; transition: all 0.2s;" onclick="toggleAddonCard(<?= $addonId ?>)">
                                <input type="checkbox"
                                    name="selected_addons[<?= h($addonId) ?>][id]"
                                    value="<?= h($addonId) ?>"
                                    id="addon_<?= h($addonId) ?>"
                                    data-price="<?= h($addon['price']) ?>"
                                    class="addon-checkbox"
                                    style="display: none;"
                                    <?= $isChecked ? 'checked' : '' ?>>

                                <div style="font-size: 2rem; color: var(--color-primary); margin-bottom: 0.5rem;"><i class="fa-solid fa-bell-concierge"></i></div>
                                <label for="addon_<?= h($addonId) ?>" class="addon-chip-label" style="font-weight: 600; cursor: pointer; margin-bottom: 0.25rem;">
                                    <?= h($addon['name']) ?>
                                </label>
                                <div style="font-size: 0.85rem; color: var(--color-text-muted); margin-bottom: 0.75rem;">+<?= h(number_format((float)$addon['price'], 0)) ?>.-</div>

                                <div class="addon-quantity-controls" id="qty_controls_<?= h($addonId) ?>" style="<?= !$isChecked ? 'opacity: 0; pointer-events: none;' : 'opacity: 1; pointer-events: auto;' ?> transition: opacity 0.2s;">
                                    <div class="input-group-quantity" style="transform: scale(0.85); transform-origin: center;">
                                        <button type="button" class="quantity-btn quantity-minus addon-qty-btn" onclick="event.stopPropagation(); updateAddonQty(<?= h($addonId) ?>, -1)">-</button>
                                        <input type="number"
                                            name="selected_addons[<?= h($addonId) ?>][quantity]"
                                            id="addon_qty_<?= h($addonId) ?>"
                                            value="<?= h($quantity) ?>"
                                            min="1"
                                            class="quantity-input addon-quantity"
                                            data-addon-id="<?= h($addonId) ?>"
                                            onclick="event.stopPropagation();"
                                            onchange="updateAddonChange(<?= h($addonId) ?>)"
                                            <?= !$isChecked ? 'disabled' : '' ?>>
                                        <button type="button" class="quantity-btn quantity-plus addon-qty-btn" onclick="event.stopPropagation(); updateAddonQty(<?= h($addonId) ?>, 1)">+</button>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>

                        <script>
                            function toggleAddonCard(addonId) {
                                const checkbox = document.getElementById('addon_' + addonId);
                                const wrapper = checkbox.closest('.addon-chip-wrapper');
                                const qtyControls = document.getElementById('qty_controls_' + addonId);
                                const qtyInput = document.getElementById('addon_qty_' + addonId);

                                checkbox.checked = !checkbox.checked;

                                if (checkbox.checked) {
                                    wrapper.classList.add('selected');
                                    wrapper.style.borderColor = 'var(--color-primary)';
                                    qtyControls.style.opacity = '1';
                                    qtyControls.style.pointerEvents = 'auto';
                                    qtyInput.disabled = false;
                                } else {
                                    wrapper.classList.remove('selected');
                                    wrapper.style.borderColor = 'transparent';
                                    qtyControls.style.opacity = '0';
                                    qtyControls.style.pointerEvents = 'none';
                                    qtyInput.disabled = true;
                                    qtyInput.value = 1;
                                }

                                // Trigger recalculation if the app uses global change events
                                if (typeof calculateTotal === 'function') {
                                    calculateTotal();
                                }
                            }

                            function updateAddonQty(addonId, delta) {
                                const input = document.getElementById('addon_qty_' + addonId);
                                let val = parseInt(input.value) || 1;
                                val += delta;
                                if (val < 1) val = 1;
                                input.value = val;
                                updateAddonChange(addonId);
                            }

                            function updateAddonChange(addonId) {
                                if (typeof calculateTotal === 'function') {
                                    calculateTotal();
                                }
                            }
                        </script>
                    </div>
                </div>
            <?php else: ?>
                <p><em>ไม่มีบริการเสริมให้เลือกในขณะนี้</em></p>
            <?php endif; ?>
        <?php endif; ?>

        <div class="navigation-buttons">
            <button type="button" class="button outline-secondary prev-step-btn">&laquo; ย้อนกลับ</button>
            <button type="button" class="button next-step-btn">ถัดไป &raquo;</button>
        </div>
    </div>

    <div id="booking-section-5" class="booking-section">
        <h4 class="section-title">ขั้นตอนที่ 5: การชำระเงินและหลักฐาน</h4>

        <div class="form-group info-box" id="zone-f-deposit-group" style="display:none;">
            <label class="checkbox-btn" for="collect_deposit_zone_f">
                <strong>สำหรับห้องโซน F (ค้างคืน): เก็บค่ามัดจำ <?= h(number_format(FIXED_DEPOSIT_AMOUNT, 0)) ?> บาท หรือไม่?</strong>
                <input id="collect_deposit_zone_f" name="collect_deposit_zone_f" type="checkbox" value="1" <?= ($editBookingId && $bookingData && (float)($bookingData['deposit_amount'] ?? 0) > 0 && isset($bookingData['zone']) && $bookingData['zone'] === 'F') ? 'checked' : '' ?> <?= ($editBookingId && $bookingData) ? 'disabled' : '' ?>>
                <span class="checkmark"></span>
            </label>
            <?php if ($editBookingId && $bookingData): ?> <small><em>การตัดสินใจเก็บมัดจำโซน F ไม่สามารถเปลี่ยนได้</em></small> <?php endif; ?>
        </div>

        <div class="form-group">
            <label for="base_amount_paid_display">ยอดค่าห้องพัก (บาท):</label>
            <input type="number" name="base_amount_paid_display" id="base_amount_paid_display" value="<?= ($editBookingId && $bookingData) ? $base_amount_for_edit_js : '0' ?>" readonly style="background-color: #e9ecef; border: 1px solid #ced4da;" class="form-control">
            <small id="base_amount_note">ยอดนี้สำหรับค่าห้องพัก (ตามประเภทและจำนวนคืน/ชั่วโมง) ยังไม่รวมบริการเสริม</small>
        </div>

        <div class="form-group">
            <p><strong>ยอดบริการเสริม:</strong> <span id="total-addon-price-display"><?= ($editBookingId && $bookingData) ? $total_addon_price_for_edit_js : '0' ?></span> บาท</p>
            <p><strong>ค่ามัดจำ:</strong> <span id="deposit-amount-display"><?= ($editBookingId && $bookingData) ? $deposit_amount_for_edit_js : '0' ?></span> บาท <span id="deposit_note_text" class="text-muted">(มาตรฐาน <?= h(number_format(FIXED_DEPOSIT_AMOUNT, 0)) ?> บาท สำหรับการจองค้างคืน นอกโซน F)</span></p>
            <hr>
            <p style="font-size: 1.1rem;"><strong>ยอดรวมที่ต้องชำระ/มูลค่าการจอง:</strong> <strong id="grand-total-price-display" style="color: var(--color-primary-dark);"><?= ($editBookingId && $bookingData) ? $grand_total_amount_for_edit_js : '0' ?></strong> บาท</p>
        </div>

        <!-- START: Modified Section for Feature #2 -->
        <div class="form-group">
            <label for="final_amount_paid">ยอดชำระแล้วทั้งหมด (บาท):</label>
            <input type="number" name="amount_paid" id="final_amount_paid" step="1" min="0" value="<?= ($editBookingId && $bookingData) ? h($final_amount_paid_for_edit_js) : '0' ?>" class="form-control" data-amount-paid-manually-set="false">
            <?php if ($editBookingId && $bookingData): ?>
                <small class="text-info">คุณสามารถแก้ไขยอดชำระแล้วทั้งหมดได้ที่นี่ หากมีการรับเงินเพิ่มหรือคืนเงิน</small>
            <?php else: ?>
                <small class="text-muted">สำหรับสร้างใหม่: กรอกยอดที่ลูกค้าชำระจริง หากไม่กรอก ระบบจะใช้ยอดรวมที่คำนวณได้</small>
            <?php endif; ?>
        </div>

        <?php if (!$editBookingId): // Show only on new bookings 
        ?>
            <div class="form-group">
                <label class="checkbox-btn" for="pay_later_checkbox">
                    ยังไม่ชำระเงิน (จ่ายทีหลัง)
                    <input id="pay_later_checkbox" name="pay_later" type="checkbox" value="1">
                    <span class="checkmark"></span>
                </label>
            </div>
        <?php endif; ?>
        <!-- END: Modified Section for Feature #2 -->

        <div class="form-group">
            <label for="payment_method">วิธีการชำระเงิน</label>
            <select name="payment_method" id="payment_method" required class="form-control">
                <option value="" disabled <?= !($bookingData && isset($bookingData['payment_method'])) ? 'selected' : '' ?>>-- เลือกวิธีการชำระเงิน --</option>
                <option value="เงินสด" <?= (($bookingData['payment_method'] ?? '') == 'เงินสด') ? 'selected' : '' ?>>เงินสด</option>
                <option value="เงินโอน" <?= (($bookingData['payment_method'] ?? '') == 'เงินโอน') ? 'selected' : '' ?>>เงินโอน</option>
                <option value="บัตรเครดิต" <?= (($bookingData['payment_method'] ?? '') == 'บัตรเครดิต') ? 'selected' : '' ?>>บัตรเครดิต</option>
                <option value="อื่นๆ" <?= (($bookingData['payment_method'] ?? '') == 'อื่นๆ') ? 'selected' : '' ?>>อื่นๆ</option>
            </select>
        </div>

        <div class="form-group">
            <label for="receipt_files" class="file-upload-label stylish-upload-label">
                <div class="upload-icon-area">
                    <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="currentColor" class="upload-icon">
                        <path d="M19.35 10.04C18.67 6.59 15.64 4 12 4 9.11 4 6.6 5.64 5.35 8.04 2.34 8.36 0 10.91 0 14c0 3.31 2.69 6 6 6h13c2.76 0 5-2.24 5-5 0-2.64-2.05-4.78-4.65-4.96zM14 13v4h-4v-4H7l5-5 5 5h-3z" />
                    </svg>
                </div>
                <span class="upload-main-text">เลือกไฟล์สลิป</span>
                <span class="upload-sub-text">(เลือกได้หลายไฟล์, รองรับรูปภาพและ PDF)</span>
                <input type="file" name="receipt_files[]" id="receipt_files" accept="image/*,application/pdf" multiple style="display:none;">
            </label>
            <div id="file-upload-filenames-display" class="filenames-display-area"></div>
            <div id="receipt-previews-container" class="previews-grid"></div>
            <?php if ($editBookingId && $bookingData && !empty($bookingData['receipt_path'])): ?>
                <div style="margin-top: 1.5rem; padding-top: 1rem; border-top: 1px solid var(--color-border); text-align:center;">
                    <p style="margin-bottom: 0.25rem;"><strong>ไฟล์ที่มีอยู่แล้ว:</strong></p>
                    <p><a href="/hotel_booking/uploads/receipts/<?= h($bookingData['receipt_path']) ?>" target="_blank" class="link-like"><?= h($bookingData['receipt_path']) ?></a></p>
                    <small><em>การเลือกไฟล์ใหม่จะเป็นการแนบไฟล์เพิ่มเติม (ไฟล์เดิมจะยังคงอยู่)</em></small>
                </div>
            <?php endif; ?>
            <small id="receipt_required_note" style="display: block; text-align: center; margin-top: 0.5rem;">หากยอดชำระมากกว่า 0 กรุณาแนบหลักฐาน (จำเป็นสำหรับสร้างการจองใหม่)</small>
        </div>
        <div class="navigation-buttons">
            <button type="button" class="button outline-secondary prev-step-btn">&laquo; ย้อนกลับ</button>
            <button type="button" class="button next-step-btn">ถัดไป &raquo;</button>
        </div>
    </div>

    <div id="booking-section-6" class="booking-section">
        <h4 class="section-title">ขั้นตอนที่ 6: หมายเหตุและยืนยันการจอง</h4>
        <div class="form-group">
            <label for="notes">หมายเหตุ (ถ้ามี)</label>
            <textarea name="notes" id="notes" rows="3" class="form-control"><?= h($bookingData['notes'] ?? '') ?></textarea>
        </div>
        <div id="summary-review" style="margin-bottom: 1.5rem; padding:1rem; background-color: var(--color-surface-alt); border-radius:var(--border-radius-md);">
            <h5><i class="fas fa-clipboard-check" style="margin-right: 8px;"></i> ตรวจสอบข้อมูลสรุป:</h5>
            <p><strong>ห้องพัก:</strong> <span id="summary_room"></span></p>
            <p><strong>ผู้จอง:</strong> <span id="summary_customer"></span></p>
            <p><strong>เช็คอิน:</strong> <span id="summary_checkin"></span></p>
            <p><strong>ระยะเวลา:</strong> <span id="summary_duration"></span></p>
            <p><strong>ประเภท:</strong> <span id="summary_type"></span></p>
            <p><strong>ยอดรวม:</strong> <span id="summary_grand_total"></span> บาท</p>
            <p><strong>ยอดชำระแล้ว:</strong> <span id="summary_amount_paid"></span> บาท</p>
            <p><strong>วิธีชำระ:</strong> <span id="summary_payment_method"></span></p>
        </div>
        <div class="navigation-buttons">
            <button type="button" class="button secondary prev-step-btn">&laquo; ย้อนกลับ</button>
            <button type="button" id="submit-booking-form-btn" class="button primary"><?= ($editBookingId && $bookingData) ? 'บันทึกการแก้ไข' : ($isMultiRoomMode ? 'ยืนยันการจองหลายห้อง' : 'ยืนยันการจอง') ?></button>
        </div>
    </div>

    <?php if ($editBookingId && $bookingData): ?>
        <a href="/hotel_booking/pages/index.php" class="button secondary" style="margin-top:1rem; display: inline-block;">ยกเลิกการแก้ไข</a>
    <?php else: ?>
        <a href="/hotel_booking/pages/index.php" class="button secondary" style="margin-top:1rem; display:none;">ยกเลิก</a>
    <?php endif; ?>
</form>

<style>
    /* Progress Bar (Flat UI) */
    .progress-bar-steps {
        display: flex;
        justify-content: space-between;
        margin: 2rem 0;
        padding: 0;
        list-style: none;
        position: relative;
        background: var(--color-surface);
        border: 2px solid var(--color-border);
        border-radius: 0.5rem;
        overflow: hidden;
    }

    .progress-step {
        flex: 1;
        text-align: center;
        padding: 0.75rem 0.5rem;
        font-size: 0.85rem;
        color: var(--color-text-muted);
        font-weight: 500;
        position: relative;
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 0.25rem;
        border-right: 1px solid var(--color-border);
    }

    .progress-step:last-child {
        border-right: none;
    }

    .progress-step span {
        display: flex;
        align-items: center;
        justify-content: center;
        width: 24px;
        height: 24px;
        background: var(--color-bg);
        border: 2px solid var(--color-border);
        border-radius: 50%;
        font-size: 0.75rem;
        font-weight: 700;
    }

    .progress-step.active {
        background: var(--color-primary-light);
        color: var(--color-primary-dark);
    }

    .progress-step.active span {
        background: var(--color-primary);
        color: var(--color-white);
        border-color: var(--color-primary-dark);
    }

    .progress-step.completed {
        color: var(--color-secondary-dark);
    }

    .progress-step.completed span {
        background: var(--color-secondary);
        color: var(--color-white);
        border-color: var(--color-secondary-dark);
    }

    .booking-section {
        display: none;
        animation: fadeIn 0.3s ease;
        padding: 1.5rem;
        border: 2px solid var(--color-border);
        border-radius: var(--border-radius-md);
        background: var(--color-surface);
    }

    .booking-section.active-section {
        display: block;
    }

    .section-title {
        font-size: 1.25rem;
        color: var(--color-primary-dark);
        margin-bottom: 1.5rem;
        padding-bottom: 0.75rem;
        border-bottom: 2px solid var(--color-border);
    }

    .navigation-buttons {
        display: flex;
        justify-content: space-between;
        margin-top: 2rem;
        padding-top: 1.5rem;
        border-top: 1px solid var(--color-border);
    }

    /* Multi-room Selection Grid */
    .rooms-selection-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(100px, 1fr));
        gap: 0.75rem;
        margin-top: 1rem;
        margin-bottom: 1rem;
    }

    .room-checkbox-card {
        position: relative;
        cursor: pointer;
    }

    .room-checkbox-card input {
        position: absolute;
        opacity: 0;
    }

    .room-card-content {
        display: flex;
        flex-direction: column;
        align-items: center;
        padding: 1rem;
        background: var(--color-surface);
        border: 2px solid transparent;
        border-radius: 0.75rem;
        box-shadow: var(--shadow-sm);
        transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        position: relative;
        overflow: hidden;
    }

    .room-card-content::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        border-radius: 0.75rem;
        border: 1px solid var(--color-border);
        pointer-events: none;
        transition: opacity 0.2s;
    }

    .room-checkbox-card:hover .room-card-content {
        transform: translateY(-2px);
        box-shadow: var(--shadow-md);
    }

    .room-checkbox-card:hover .room-card-content::before {
        border-color: var(--color-primary);
        opacity: 0.5;
    }

    .room-checkbox-card input:checked+.room-card-content {
        background: linear-gradient(135deg, var(--color-primary), var(--color-primary-dark));
        color: var(--color-white);
        box-shadow: 0 4px 12px rgba(79, 70, 229, 0.3);
        transform: translateY(-2px);
    }

    .room-checkbox-card input:checked+.room-card-content::before {
        opacity: 0;
    }

    .room-checkbox-card input:checked+.room-card-content .room-price {
        color: rgba(255, 255, 255, 0.9);
    }

    .room-name {
        font-size: 1.15rem;
        font-weight: 700;
        margin-top: 0.5rem;
        letter-spacing: -0.02em;
    }

    .room-price {
        font-size: 0.85rem;
        color: var(--color-text-muted);
        font-weight: 500;
        margin-top: 0.25rem;
    }

    @keyframes fadeIn {
        from {
            opacity: 0;
            transform: translateY(10px);
        }

        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    .file-preview-item {
        border: 1px solid var(--color-border);
        border-radius: var(--border-radius-sm);
        background-color: var(--color-surface);
        padding: 0.5rem;
        position: relative;
    }

    padding: 0.5rem;
    background-color: var(--color-surface);
    box-shadow: var(--shadow-sm);
    display: flex;
    flex-direction: column;
    align-items: center;
    position: relative;
    }

    .preview-element-container {
        width: 100%;
        height: 80px;
        /* Smaller preview height */
        display: flex;
        align-items: center;
        justify-content: center;
        margin-bottom: 0.5rem;
        background-color: var(--color-surface-alt);
        border-radius: var(--border-radius-sm);
        overflow: hidden;
    }

    .receipt-preview-thumb {
        /* Reusing existing class for consistency */
        max-width: 100%;
        max-height: 100%;
        object-fit: contain;
    }

    .pdf-icon-preview,
    .other-file-preview {
        font-size: 2rem;
        color: var(--color-text-muted);
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        width: 100%;
        height: 100%;
    }

    .pdf-filename-preview,
    .other-filename-preview {
        font-size: 0.65rem;
        /* Smaller font for filename */
        color: var(--color-text-muted);
        word-break: break-all;
        line-height: 1.2;
        text-align: center;
        margin-top: 0.2rem;
    }

    .receipt-description-group {
        margin-top: 0.5rem;
        width: 100%;
    }

    .receipt-description-group label {
        font-size: 0.75rem !important;
        margin-bottom: 0.1rem !important;
    }

    .form-control-sm {
        font-size: 0.8rem !important;
        padding: 0.3rem 0.5rem !important;
    }

    .remove-file-btn {
        position: absolute;
        top: 2px;
        right: 2px;
        background-color: var(--color-alert);
        color: white;
        border: none;
        border-radius: 50%;
        width: 20px;
        height: 20px;
        font-size: 12px;
        line-height: 18px;
        text-align: center;
        cursor: pointer;
        padding: 0;
        font-weight: bold;
    }

    .remove-file-btn:hover {
        background-color: var(--color-alert-dark);
    }

    .filenames-display-area p {
        margin: 0.2rem 0;
    }

    .input-error-highlight {
        border-color: var(--color-alert-dark) !important;
        box-shadow: 0 0 0 2px rgba(217, 28, 28, 0.2);
    }
</style>


<script>
    const ROOM_DETAILS_JS = <?php echo json_encode($room_details_for_js); ?>;
    const IS_EDIT_MODE_JS = <?php echo json_encode(($editBookingId && $bookingData) ? true : false); ?>;
    const CURRENT_BOOKING_TYPE_EDIT_JS = <?php echo json_encode($current_booking_type_for_edit); ?>;
    const FIXED_DEPOSIT_AMOUNT_GLOBAL_JS = <?php echo json_encode((int)FIXED_DEPOSIT_AMOUNT); ?>;
    const DEFAULT_SHORT_STAY_HOURS_GLOBAL_JS = <?php echo json_encode((int)DEFAULT_SHORT_STAY_DURATION_HOURS); ?>;

    const ORIGINAL_CHECKIN_DATETIME_EDIT_JS = <?php echo json_encode(($editBookingId && $bookingData) ? $bookingData['checkin_datetime'] : null); ?>;
    const ORIGINAL_PRICE_PER_NIGHT_EDIT_JS = <?php echo json_encode(($editBookingId && $bookingData) ? ($bookingData['price_per_night'] ?? ($bookingData['current_price_per_day'] ?? 0)) : 0); ?>;

    const PHP_INITIAL_CHECKIN_DATETIME_BOOKING_PAGE = <?php echo json_encode($initialCheckinDatetimeValue); ?>;
    const IS_CALENDAR_PREFILL_BOOKING_PAGE = <?php echo json_encode($isCalendarPrefill); ?>;
    const IS_CHECKIN_TIME_READONLY_BOOKING_PAGE = <?php echo json_encode($isCheckinTimeReadOnly); ?>;
    const IS_DISABLE_CHECKIN_NOW_BOOKING_PAGE = <?php echo json_encode($disableCheckinNow); ?>;
    const IS_MULTI_ROOM_MODE_JS = <?php echo json_encode($isMultiRoomMode); ?>;


    document.addEventListener('DOMContentLoaded', function() {
        const bookingForm = document.getElementById('booking-form');
        if (!bookingForm) return;

        // --- Element Declarations ---
        const roomSelect_BookingForm = document.getElementById('room_id');
        const multiRoomCheckboxes_BookingForm = document.querySelectorAll('.multi-room-checkbox');
        const bookingTypeSelect_BookingForm = document.getElementById('booking_type');
        const finalAmountPaidInput_BookingForm_Local = document.getElementById('final_amount_paid');
        const grandTotalPriceDisplay_BookingForm = document.getElementById('grand-total-price-display');
        const nightsInput_BookingForm = document.getElementById('nights');
        const checkinDatetimeInput = document.getElementById('checkin_datetime');
        const mainSubmitButton = document.getElementById('submit-booking-form-btn');
        const receiptFilesInput = document.getElementById('receipt_files');

        // --- Stepper Logic Elements ---
        const sections = Array.from(document.querySelectorAll('.booking-section'));
        const progressSteps = Array.from(document.querySelectorAll('.progress-step'));
        let currentSectionIndex = 0;

        // --- File Upload Elements ---
        const filenamesDisplay = document.getElementById('file-upload-filenames-display');
        const previewsContainer = document.getElementById('receipt-previews-container');
        let uploadedFileObjects = [];

        // START: JavaScript for Feature #2
        const payLaterCheckbox = document.getElementById('pay_later_checkbox');
        if (payLaterCheckbox) {
            payLaterCheckbox.addEventListener('change', function() {
                const finalAmountPaidInput = document.getElementById('final_amount_paid');
                const grandTotalPriceDisplay = document.getElementById('grand-total-price-display');

                if (this.checked) {
                    finalAmountPaidInput.value = '0';
                    finalAmountPaidInput.readOnly = true;
                    // When pay later is checked, it should not be considered a manual entry
                    finalAmountPaidInput.dataset.amountPaidManuallySet = 'false';
                } else {
                    finalAmountPaidInput.readOnly = false;
                    // When unchecked, set amount paid back to the calculated grand total
                    finalAmountPaidInput.value = grandTotalPriceDisplay.textContent;
                }
                // Manually trigger summary update
                updateSummaryReview();
            });
        }
        // END: JavaScript for Feature #2

        const multiRoomAddonManager = document.getElementById('multi-room-addon-manager');
        const activeAddonsData = <?php echo json_encode($active_addons ?? []); ?>;

        function createAddonSelector(roomId = null) {
            const container = document.createElement('div');
            container.className = 'addon-selection-dropdown-container';

            const select = document.createElement('select');
            select.className = 'form-control form-control-sm';
            select.innerHTML = '<option value="">-- เลือกบริการเสริม --</option>';
            activeAddonsData.forEach(addon => {
                const option = document.createElement('option');
                option.value = addon.id;
                option.textContent = `${addon.name} (${parseInt(addon.price, 10)} บ.)`;
                option.dataset.price = addon.price;
                option.dataset.name = addon.name;
                select.appendChild(option);
            });

            const confirmBtn = document.createElement('button');
            confirmBtn.type = 'button';
            confirmBtn.textContent = 'เพิ่ม';
            confirmBtn.className = 'button-small primary';

            confirmBtn.onclick = function() {
                const selectedOption = select.options[select.selectedIndex];
                if (!selectedOption.value) return;

                const addonId = selectedOption.value;
                const addonName = selectedOption.dataset.name;
                const addonPrice = selectedOption.dataset.price;

                if (roomId) {
                    addAddonToRoomDOM(roomId, addonId, addonName, addonPrice);
                } else {
                    const selectedRoomIds = Array.from(document.querySelectorAll('.multi-room-checkbox:checked')).map(cb => cb.value);
                    selectedRoomIds.forEach(id => {
                        addAddonToRoomDOM(id, addonId, addonName, addonPrice);
                    });
                }
                select.value = ''; // Reset select
                calculateAndUpdateBookingFormTotals();
            };

            container.appendChild(select);
            container.appendChild(confirmBtn);
            return container;
        }

        function addAddonToRoomDOM(roomId, addonId, addonName, addonPrice) {
            const roomContainer = multiRoomAddonManager.querySelector(`.addon-room-container[data-room-id="${roomId}"]`);
            if (!roomContainer) return;
            const itemsList = roomContainer.querySelector('.addon-items-list');

            let existingEntry = roomContainer.querySelector(`[data-addon-id="${addonId}"]`);
            if (existingEntry) {
                const qtyInput = existingEntry.querySelector('.addon-quantity-multi');
                qtyInput.value = parseInt(qtyInput.value) + 1;
                calculateAndUpdateBookingFormTotals();
                return;
            }

            const addonItemDiv = document.createElement('div');
            addonItemDiv.className = 'addon-item-entry';
            addonItemDiv.dataset.addonId = addonId;

            addonItemDiv.innerHTML = `
                <div class="addon-info">
                    <span class="addon-name-label">${addonName}</span>
                    <span class="addon-price-sub">${parseInt(addonPrice)} บ.</span>
                </div>
                <div class="addon-controls">
                    <button type="button" class="qty-btn minus">-</button>
                    <input type="number" name="room_addons[${roomId}][${addonId}]" value="1" min="1" class="addon-quantity-multi" readonly>
                    <button type="button" class="qty-btn plus">+</button>
                    <button type="button" class="remove-addon-btn">&times;</button>
                </div>
            `;

            const qtyInput = addonItemDiv.querySelector('.addon-quantity-multi');
            addonItemDiv.querySelector('.qty-btn.minus').onclick = () => {
                if (parseInt(qtyInput.value) > 1) {
                    qtyInput.value = parseInt(qtyInput.value) - 1;
                    calculateAndUpdateBookingFormTotals();
                }
            };
            addonItemDiv.querySelector('.qty-btn.plus').onclick = () => {
                qtyInput.value = parseInt(qtyInput.value) + 1;
                calculateAndUpdateBookingFormTotals();
            };
            addonItemDiv.querySelector('.remove-addon-btn').onclick = () => {
                addonItemDiv.remove();
                calculateAndUpdateBookingFormTotals();
            };

            itemsList.appendChild(addonItemDiv);
        }

        function updateMultiRoomAddonUI() {
            if (!IS_MULTI_ROOM_MODE_JS || !multiRoomAddonManager) return;

            multiRoomAddonManager.innerHTML = '';
            const selectedCheckboxes = Array.from(document.querySelectorAll('.multi-room-checkbox:checked'));

            if (selectedCheckboxes.length === 0) {
                multiRoomAddonManager.innerHTML = '<div class="alert info">กรุณาเลือกห้องพักในขั้นตอนที่ 1 ก่อน เพื่อจัดการบริการเสริม</div>';
                return;
            }

            // --- 1. Global Addon Section (Apply to All) ---
            const globalSection = document.createElement('div');
            globalSection.className = 'global-addon-section';
            globalSection.innerHTML = `
                <div class="global-addon-header">
                    <h6><i class="fas fa-layer-group"></i> เพิ่มบริการเสริมแบบด่วน (สำหรับทุกห้องที่เลือก)</h6>
                    <p class="text-muted small">บริการที่เลือกที่นี่จะถูกเพิ่มให้ทุกห้องพัก 1 รายการ</p>
                </div>
            `;
            const quickSelectGrid = document.createElement('div');
            quickSelectGrid.className = 'quick-select-addon-grid';
            activeAddonsData.forEach(addon => {
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'addon-quick-btn';
                btn.innerHTML = `<span>${addon.name}</span> <small>${parseInt(addon.price)}.-</small>`;
                btn.onclick = () => {
                    const roomIds = selectedCheckboxes.map(cb => cb.value);
                    roomIds.forEach(id => addAddonToRoomDOM(id, addon.id, addon.name, addon.price));
                    calculateAndUpdateBookingFormTotals();
                };
                quickSelectGrid.appendChild(btn);
            });
            globalSection.appendChild(quickSelectGrid);
            multiRoomAddonManager.appendChild(globalSection);

            // --- 2. Room-Specific Addon Section ---
            const roomsSectionHeader = document.createElement('div');
            roomsSectionHeader.className = 'rooms-addon-header';
            roomsSectionHeader.innerHTML = '<h6><i class="fas fa-bed"></i> จัดการรายห้องพัก</h6>';
            multiRoomAddonManager.appendChild(roomsSectionHeader);

            const roomsContainer = document.createElement('div');
            roomsContainer.className = 'addon-rooms-grid';
            selectedCheckboxes.forEach(checkbox => {
                const roomId = checkbox.value;
                const roomCard = checkbox.closest('.room-checkbox-card');
                const roomName = roomCard ? roomCard.querySelector('.room-name').textContent : 'Room ' + roomId;

                const roomDiv = document.createElement('div');
                roomDiv.className = 'addon-room-container';
                roomDiv.dataset.roomId = roomId;
                roomDiv.innerHTML = `
                    <div class="room-addon-header">
                        <strong>${roomName}</strong>
                    </div>
                    <div class="addon-items-list"></div>
                    <div class="room-addon-footer">
                        <!-- Selector is always visible now -->
                    </div>
                `;
                roomDiv.querySelector('.room-addon-footer').appendChild(createAddonSelector(roomId));
                roomsContainer.appendChild(roomDiv);
            });
            multiRoomAddonManager.appendChild(roomsContainer);
        }

        // Add some CSS for the new UI
        const multiAddonStyle = document.createElement('style');
        multiAddonStyle.innerHTML = `
            .global-addon-section { background: var(--color-surface-alt); padding: 1rem; border-radius: var(--border-radius-md); margin-bottom: 1.5rem; border: 1px solid var(--color-border); }
            .global-addon-header h6 { margin: 0 0 0.5rem 0; color: var(--color-primary-dark); font-size: 1rem; }
            .quick-select-addon-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 0.5rem; margin-top: 0.75rem; }
            .addon-quick-btn { background: var(--color-surface); border: 2px solid var(--color-border); border-radius: 0.5rem; padding: 0.5rem; cursor: pointer; display: flex; flex-direction: column; align-items: center; transition: all 0.2s; }
            .addon-quick-btn:hover { border-color: var(--color-primary); background: var(--color-primary-light); }
            .addon-quick-btn span { font-weight: 600; font-size: 0.9rem; }
            .addon-quick-btn small { color: var(--color-text-muted); }

            .rooms-addon-header h6 { margin-bottom: 1rem; color: var(--color-primary-dark); font-size: 1rem; }
            .addon-rooms-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 1rem; }
            .addon-room-container { background: var(--color-surface); border: 2px solid var(--color-border); border-radius: var(--border-radius-md); padding: 1rem; display: flex; flex-direction: column; }
            .room-addon-header { border-bottom: 1px solid var(--color-border); padding-bottom: 0.5rem; margin-bottom: 0.75rem; }
            .addon-items-list { flex-grow: 1; min-height: 20px; }
            
            .addon-item-entry { display: flex; justify-content: space-between; align-items: center; padding: 0.4rem 0.6rem; background: var(--color-surface-alt); border-radius: 0.4rem; margin-bottom: 0.5rem; }
            .addon-info { display: flex; flex-direction: column; }
            .addon-name-label { font-size: 0.85rem; font-weight: 600; }
            .addon-price-sub { font-size: 0.75rem; color: var(--color-text-muted); }
            
            .addon-controls { display: flex; align-items: center; gap: 4px; }
            .addon-controls input { width: 35px; text-align: center; border: 1px solid var(--color-border); border-radius: 4px; font-weight: 600; }
            .qty-btn { width: 22px; height: 22px; border-radius: 50%; border: 1px solid var(--color-border); background: var(--color-surface); cursor: pointer; display: flex; align-items: center; justify-content: center; font-weight: bold; }
            .qty-btn:hover { background: var(--color-primary-light); border-color: var(--color-primary); }
            .remove-addon-btn { background: none; border: none; color: var(--color-alert); cursor: pointer; font-size: 1.2rem; line-height: 1; padding: 0 4px; }
            
            .addon-selection-dropdown-container { margin-top: 0.75rem; display: flex; gap: 5px; }
            .addon-selection-dropdown-container select { flex-grow: 1; }
        `;
        document.head.appendChild(multiAddonStyle);


        // --- Helper and Logic Functions ---
        const originalButtonContents = {};

        function setButtonLoading(buttonElement, isLoading, buttonIdForTextStore) {
            if (!buttonElement) return;
            const key = buttonIdForTextStore || buttonElement.id || buttonElement.dataset.loadingKey || `btn-${Date.now()}-${Math.random()}`;
            if (isLoading) {
                if (!buttonElement.classList.contains('loading')) {
                    if (originalButtonContents[key] === undefined) {
                        originalButtonContents[key] = buttonElement.innerHTML;
                    }
                    buttonElement.innerHTML = '<span class="spinner-sm"></span> กำลังประมวลผล...';
                    buttonElement.classList.add('loading');
                    buttonElement.disabled = true;
                }
            } else {
                if (buttonElement.classList.contains('loading')) {
                    if (originalButtonContents[key] !== undefined) {
                        buttonElement.innerHTML = originalButtonContents[key];
                    }
                    buttonElement.classList.remove('loading');
                    buttonElement.disabled = false;
                }
            }
        }

        function updateProgressBar() {
            progressSteps.forEach((step, index) => {
                step.classList.remove('active', 'completed');
                if (index < currentSectionIndex) {
                    step.classList.add('completed');
                } else if (index === currentSectionIndex) {
                    step.classList.add('active');
                }
            });
        }

        function showSection(index) {
            sections.forEach((section, i) => {
                section.classList.toggle('active-section', i === index);
            });
            currentSectionIndex = index;
            updateProgressBar();

            if (!IS_EDIT_MODE_JS) {
                if (mainSubmitButton) {
                    mainSubmitButton.style.display = (index === sections.length - 1) ? 'inline-block' : 'none';
                }
                sections.forEach((sec, secIdx) => {
                    const nextBtn = sec.querySelector('.next-step-btn');
                    const prevBtn = sec.querySelector('.prev-step-btn');
                    if (nextBtn) nextBtn.style.display = (secIdx === sections.length - 1) ? 'none' : 'inline-block';
                    if (prevBtn) prevBtn.style.display = (secIdx === 0) ? 'none' : 'inline-block';
                });

                // PROACTIVE: Update totals and summary when navigating
                calculateAndUpdateBookingFormTotals();
                if (index === sections.length - 1) {
                    updateSummaryReview();
                }
            }
        }

        function validateCurrentSection() {
            const currentSection = sections[currentSectionIndex];
            if (!currentSection) return true;
            let isValid = true;
            let firstInvalidElement = null;

            const inputs = currentSection.querySelectorAll('input[required], select[required]');
            for (const input of inputs) {
                if (input.offsetParent !== null && !input.disabled) {
                    if ((input.type === 'checkbox' && !input.checked) || (input.type !== 'checkbox' && !input.value.trim())) {
                        isValid = false;
                        firstInvalidElement = input;
                        alert(`กรุณากรอกข้อมูล: ${input.labels[0]?.textContent || input.name}`);
                        break;
                    }
                }
            }

            // Custom validation for Multi-room selection in Step 1
            if (isValid && IS_MULTI_ROOM_MODE_JS && currentSectionIndex === 0) {
                const checked = document.querySelectorAll('.multi-room-checkbox:checked').length;
                if (checked === 0) {
                    isValid = false;
                    alert('กรุณาเลือกอย่างน้อย 1 ห้องพัก');
                }
            }

            if (!isValid && firstInvalidElement) {
                firstInvalidElement.focus();
                firstInvalidElement.classList.add('input-error-highlight');
                setTimeout(() => {
                    firstInvalidElement.classList.remove('input-error-highlight');
                }, 2500);
            }
            return isValid;
        }

        function updateSummaryReview() {
            // Check if on the last section
            if (currentSectionIndex !== sections.length - 1) return;

            const customerNameInput = document.getElementById('customer_name');
            const shortStayDurationInput_BookingForm = document.getElementById('short_stay_duration_hours');
            const paymentMethodSelect_BookingForm = document.getElementById('payment_method');
            const finalAmountPaidInput = document.getElementById('final_amount_paid');

            let roomSummaryText = 'N/A';
            if (IS_MULTI_ROOM_MODE_JS) {
                const selectedCbs = Array.from(document.querySelectorAll('.multi-room-checkbox:checked'));
                roomSummaryText = selectedCbs.length > 0 ?
                    selectedCbs.map(cb => {
                        const card = cb.closest('.room-checkbox-card');
                        return card ? card.querySelector('.room-name').textContent : 'Room ' + cb.value;
                    }).join(', ') : 'ไม่ได้เลือกห้อง';
            } else if (roomSelect_BookingForm) {
                roomSummaryText = (roomSelect_BookingForm.options[roomSelect_BookingForm.selectedIndex]?.text.split(' - ')[0] || 'N/A');
            }
            document.getElementById('summary_room').textContent = roomSummaryText;

            document.getElementById('summary_customer').textContent = customerNameInput.value.trim() || 'ไม่ระบุ';

            document.getElementById('summary_checkin').textContent = checkinDatetimeInput.value ?
                new Date(checkinDatetimeInput.value.replace('T', ' ')).toLocaleString('th-TH', {
                    year: 'numeric',
                    month: 'short',
                    day: 'numeric',
                    hour: '2-digit',
                    minute: '2-digit'
                }) + ' น.' :
                'N/A';

            let durationText = 'N/A';
            const currentBookingType = bookingTypeSelect_BookingForm.value;
            if (currentBookingType === 'overnight') {
                durationText = `${nightsInput_BookingForm.value || '1'} คืน`;
            } else if (currentBookingType === 'short_stay') {
                const durationVal = shortStayDurationInput_BookingForm ? shortStayDurationInput_BookingForm.value : DEFAULT_SHORT_STAY_HOURS_GLOBAL_JS;
                durationText = `${durationVal} ชั่วโมง`;
            }
            document.getElementById('summary_duration').textContent = durationText;

            document.getElementById('summary_type').textContent = bookingTypeSelect_BookingForm.options[bookingTypeSelect_BookingForm.selectedIndex]?.text.split(' (')[0] || 'N/A';
            document.getElementById('summary_grand_total').textContent = grandTotalPriceDisplay_BookingForm.textContent;
            document.getElementById('summary_amount_paid').textContent = finalAmountPaidInput.value;
            document.getElementById('summary_payment_method').textContent = paymentMethodSelect_BookingForm.value || 'N/A';
        }

        function calculateAndUpdateBookingFormTotals() {
            let baseRoomTotal = 0;
            let depositTotal = 0;
            let addonTotal = 0;

            const bookingType = bookingTypeSelect_BookingForm.value;
            const nights = parseInt(nightsInput_BookingForm.value, 10) || 1;

            if (IS_MULTI_ROOM_MODE_JS) {
                const selectedCheckboxes = Array.from(document.querySelectorAll('.multi-room-checkbox:checked'));
                selectedCheckboxes.forEach(cb => {
                    const pricePerDay = parseFloat(cb.dataset.price) || 0;
                    const askDeposit = cb.dataset.askDepositF === '1';
                    const zone = cb.dataset.zone;

                    baseRoomTotal += pricePerDay * nights;

                    if (askDeposit) {
                        if (zone !== 'F' || (zone === 'F' && document.getElementById('collect_deposit_zone_f').checked)) {
                            depositTotal += FIXED_DEPOSIT_AMOUNT_GLOBAL_JS;
                        }
                    }
                });

                // Calculate multi-room addons
                const addonInputs = document.querySelectorAll('.addon-quantity-multi');
                addonInputs.forEach(input => {
                    const quantity = parseInt(input.value, 10) || 0;
                    // Extract ID from name="room_addons[ROOM_ID][ADDON_ID]"
                    const nameAttr = input.name;
                    const matches = nameAttr.match(/\[(\d+)\]$/);
                    if (matches) {
                        const addonId = matches[1];
                        const addonData = activeAddonsData.find(a => a.id == addonId);
                        if (addonData && quantity > 0) {
                            addonTotal += (parseFloat(addonData.price) * quantity);
                        }
                    }
                });
            } else {
                const selectedOpt = roomSelect_BookingForm.options[roomSelect_BookingForm.selectedIndex];
                if (selectedOpt && selectedOpt.value) {
                    const pricePerDay = parseFloat(selectedOpt.dataset.price) || 0;
                    const priceShort = parseFloat(selectedOpt.dataset.priceShort) || 0;
                    const askDeposit = selectedOpt.dataset.askDepositF === '1';
                    const zone = selectedOpt.dataset.zone;

                    if (bookingType === 'short_stay') {
                        baseRoomTotal = priceShort;
                    } else {
                        baseRoomTotal = pricePerDay * nights;
                        if (askDeposit) {
                            if (zone !== 'F' || (zone === 'F' && document.getElementById('collect_deposit_zone_f').checked)) {
                                depositTotal = FIXED_DEPOSIT_AMOUNT_GLOBAL_JS;
                            }
                        }
                    }
                }

                // Calculate single-room addons
                document.querySelectorAll('.addon-chip-wrapper.selected .addon-quantity').forEach(input => {
                    const quantity = parseInt(input.value, 10) || 1;
                    const addonId = input.dataset.addonId;
                    const addonData = activeAddonsData.find(a => a.id == addonId);
                    if (addonData) {
                        addonTotal += parseFloat(addonData.price) * quantity;
                    }
                });
            }

            const baseAmountPaidDisplay = document.getElementById('base_amount_paid_display');
            const totalAddonPriceDisplay = document.getElementById('total-addon-price-display');
            const depositAmountDisplay = document.getElementById('deposit-amount-display');
            const finalAmountPaidInput = document.getElementById('final_amount_paid');

            if (baseAmountPaidDisplay) baseAmountPaidDisplay.value = Math.round(baseRoomTotal);
            if (totalAddonPriceDisplay) totalAddonPriceDisplay.textContent = Math.round(addonTotal);
            if (depositAmountDisplay) depositAmountDisplay.textContent = Math.round(depositTotal);

            const grandTotal = baseRoomTotal + addonTotal + depositTotal;
            if (grandTotalPriceDisplay_BookingForm) grandTotalPriceDisplay_BookingForm.textContent = Math.round(grandTotal);

            if (finalAmountPaidInput && finalAmountPaidInput.dataset.amountPaidManuallySet !== 'true' && (!payLaterCheckbox || !payLaterCheckbox.checked)) {
                // For single-room, default to full payment (Grand Total)
                // For multi-room, we usually keep it 0 or standard deposit, but user said "pay full if not edited" for single room
                if (!IS_MULTI_ROOM_MODE_JS) {
                    finalAmountPaidInput.value = Math.round(grandTotal);
                } else {
                    // For multi-room, default might be different, but let's stick to grand total if not set manually
                    finalAmountPaidInput.value = Math.round(grandTotal);
                }
            }

            updateSummaryReview();
        }

        async function proceedWithActualSubmission(formData, formActionUrlParam, submitButton, isMultiMode, actionType) {
            console.log('[ProceedSubmit booking.php] Starting actual submission.');
            console.log('[ProceedSubmit booking.php] formActionUrlParam received:', formActionUrlParam);

            if (typeof formActionUrlParam !== 'string' || !formActionUrlParam || !formActionUrlParam.includes('api.php')) {
                console.error('[ProceedSubmit booking.php] Invalid formActionUrlParam:', formActionUrlParam);
                alert('เกิดข้อผิดพลาดของหน้าเว็บ: ไม่สามารถหา URL สำหรับส่งข้อมูลได้ (Invalid API URL)');
                if (submitButton) setButtonLoading(submitButton, false, submitButton.id || 'submitBookingFormBtnError');
                return;
            }

            if (submitButton) setButtonLoading(submitButton, true, submitButton.id || 'submitBookingFormBtn');

            if (uploadedFileObjects && uploadedFileObjects.length > 0) {
                formData.delete('receipt_files[]');
                uploadedFileObjects.forEach(file => {
                    formData.append('receipt_files[]', file, file.name);
                });
            } else if (formData.has('receipt_files[]')) {
                formData.delete('receipt_files[]');
            }
            try {
                const resp = await fetch(formActionUrlParam, {
                    method: 'POST',
                    body: formData
                });
                const responseText = await resp.text();
                console.log('[ProceedSubmit booking.php] API Raw Response:', responseText);
                try {
                    const data = JSON.parse(responseText);
                    if (data.success) {
                        alert(data.message || 'การดำเนินการสำเร็จ!');
                        window.location.href = data.redirect_url || '/hotel_booking/pages/index.php';
                    } else {
                        alert(data.message || 'เกิดข้อผิดพลาด: ' + (data.detail || 'An error occurred'));
                        if (submitButton) setButtonLoading(submitButton, false, submitButton.id || 'submitBookingFormBtnSuccessFalse');
                    }
                } catch (parseError) {
                    console.error('[ProceedSubmit booking.php] JSON Parse error:', parseError, "\nResponse was:", responseText);
                    alert('เกิดข้อผิดพลาดในการประมวลผลการตอบกลับจากเซิร์ฟเวอร์');
                    if (submitButton) setButtonLoading(submitButton, false, submitButton.id || 'submitBookingFormBtnParseError');
                }
            } catch (err) {
                console.error('[ProceedSubmit booking.php] Submission fetch error:', err);
                alert('ไม่สามารถเชื่อมต่อเซิร์ฟเวอร์ได้');
                if (submitButton) setButtonLoading(submitButton, false, submitButton.id || 'submitBookingFormBtnFetchError');
            }
        }

        // Replacement for form submit with explicit button click for better reliability
        if (mainSubmitButton) {
            mainSubmitButton.addEventListener('click', async function(e) {
                console.log('[SubmitClick booking.php] Submit button clicked.');

                // Final calculation refresh
                calculateAndUpdateBookingFormTotals();

                // Validation across all sections before submitting
                let overallValid = true;
                const originalIndex = currentSectionIndex;

                // CRITICAL: We need to temporarily "show" sections to validate them if they use offsetParent checks
                for (let i = 0; i < sections.length; i++) {
                    const sec = sections[i];
                    const wasActive = sec.classList.contains('active-section');

                    // Temporarily force active for validation
                    sec.classList.add('active-section');
                    currentSectionIndex = i;

                    if (!validateCurrentSection()) {
                        overallValid = false;
                        showSection(i); // Stay on the invalid section
                        break;
                    }

                    if (!wasActive) sec.classList.remove('active-section');
                }

                if (!overallValid) {
                    setButtonLoading(mainSubmitButton, false, 'submitBookingFormBtnValidationFailed');
                    return;
                }

                // If everything is valid, proceed
                showSection(originalIndex); // Restore UI safely

                const formData = new FormData(bookingForm);
                const currentActionForSubmit = formData.get('action');
                const isMultiModeForSubmit = IS_MULTI_ROOM_MODE_JS;

                const formActionUrlFromPHP = <?php echo json_encode($form_action_url); ?>;
                const resolvedFormActionUrl = formActionUrlFromPHP;

                if (isMultiModeForSubmit && currentActionForSubmit === 'create') {
                    if (confirm('คุณกำลังจะจองหลายห้องพัก ยืนยันหรือไม่?')) {
                        await proceedWithActualSubmission(formData, resolvedFormActionUrl, mainSubmitButton, true, 'create');
                    } else {
                        setButtonLoading(mainSubmitButton, false, 'submitBookingFormBtnMultiCancel');
                    }
                } else {
                    await proceedWithActualSubmission(formData, resolvedFormActionUrl, mainSubmitButton, isMultiModeForSubmit, currentActionForSubmit);
                }
            });
        }

        // Prevent default submit if somehow triggered
        bookingForm.addEventListener('submit', (e) => {
            e.preventDefault();
            console.log('[BookingFormSubmit booking.php] Default submit prevented.');
        });

        // --- Event Listeners Initialization ---
        document.querySelectorAll('.next-step-btn').forEach(button => {
            button.addEventListener('click', () => {
                if (validateCurrentSection()) {
                    if (currentSectionIndex < sections.length - 1) {
                        showSection(currentSectionIndex + 1);
                        calculateAndUpdateBookingFormTotals();
                    }
                }
            });
        });

        document.querySelectorAll('.prev-step-btn').forEach(button => {
            button.addEventListener('click', () => {
                if (currentSectionIndex > 0) {
                    showSection(currentSectionIndex - 1);
                }
            });
        });

        if (receiptFilesInput) {
            receiptFilesInput.addEventListener('change', function(e) {
                previewsContainer.innerHTML = '';
                filenamesDisplay.innerHTML = '';
                uploadedFileObjects = Array.from(e.target.files);

                if (uploadedFileObjects.length === 0) return;

                const summaryP = document.createElement('p');
                summaryP.textContent = `ไฟล์ที่เลือก (${uploadedFileObjects.length} ไฟล์):`;
                filenamesDisplay.appendChild(summaryP);

                uploadedFileObjects.forEach((file, index) => {
                    const fileWrapper = document.createElement('div');
                    fileWrapper.className = 'file-preview-item';
                    fileWrapper.dataset.fileIndex = index;

                    const previewElementContainer = document.createElement('div');
                    previewElementContainer.className = 'preview-element-container';

                    if (file.type.startsWith('image/')) {
                        const reader = new FileReader();
                        reader.onload = (event) => {
                            const img = document.createElement('img');
                            img.src = event.target.result;
                            img.alt = `ตัวอย่าง ${file.name}`;
                            img.className = 'receipt-preview-thumb';
                            previewElementContainer.appendChild(img);
                        }
                        reader.readAsDataURL(file);
                    } else if (file.type === 'application/pdf') {
                        previewElementContainer.innerHTML = `<div class="pdf-icon-preview">📄<span class="pdf-filename-preview">${file.name}</span></div>`;
                    } else {
                        previewElementContainer.innerHTML = `<div class="other-file-preview">❔<span class="other-filename-preview">${file.name}</span></div>`;
                    }
                    fileWrapper.appendChild(previewElementContainer);

                    const removeBtn = document.createElement('button');
                    removeBtn.type = 'button';
                    removeBtn.className = 'remove-file-btn';
                    removeBtn.innerHTML = '&times;';
                    removeBtn.title = `ลบไฟล์ ${file.name}`;
                    removeBtn.addEventListener('click', function() {
                        const fileIdxToRemove = parseInt(this.closest('.file-preview-item').dataset.fileIndex);
                        uploadedFileObjects = uploadedFileObjects.filter((_, i) => i !== fileIdxToRemove);
                        const dataTransfer = new DataTransfer();
                        uploadedFileObjects.forEach(f => dataTransfer.items.add(f));
                        receiptFilesInput.files = dataTransfer.files;
                        receiptFilesInput.dispatchEvent(new Event('change', {
                            bubbles: true
                        }));
                    });
                    fileWrapper.appendChild(removeBtn);
                    previewsContainer.appendChild(fileWrapper);
                });
            });
        }

        bookingForm.querySelectorAll('input, select, textarea').forEach(input => {
            input.addEventListener('change', calculateAndUpdateBookingFormTotals);
            if (input.type === 'number' || input.type === 'datetime-local' || input.tagName.toLowerCase() === 'textarea') {
                input.addEventListener('input', calculateAndUpdateBookingFormTotals);
            }
        });

        // Hook into existing listeners for multi-room
        if (IS_MULTI_ROOM_MODE_JS) {
            document.querySelectorAll('.multi-room-checkbox').forEach(cb => {
                cb.addEventListener('change', () => {
                    calculateAndUpdateBookingFormTotals();
                    updateMultiRoomAddonUI();
                });
            });
            // Initial call if rooms are pre-selected
            updateMultiRoomAddonUI();
        }

        // --- Page Initialization ---
        if (IS_EDIT_MODE_JS) {
            // ทำให้ทุก section แสดงผลในโหมดแก้ไขเหมือนเดิม
            sections.forEach(section => section.classList.add('active-section'));

            // ซ่อน Progress bar
            const progressBarContainer = document.getElementById('booking-progress-bar-container');
            if (progressBarContainer) progressBarContainer.style.display = 'none';

            // ซ่อนเฉพาะปุ่ม "ย้อนกลับ" และ "ถัดไป" เท่านั้น
            document.querySelectorAll('.next-step-btn, .prev-step-btn').forEach(btn => {
                btn.style.display = 'none';
            });

            // ตรวจสอบให้แน่ใจว่าปุ่ม "บันทึกการแก้ไข" และคอนเทนเนอร์ของมันแสดงผลอยู่
            if (mainSubmitButton) {
                mainSubmitButton.style.display = 'inline-block';
                const finalNavContainer = mainSubmitButton.closest('.navigation-buttons');
                if (finalNavContainer) {
                    finalNavContainer.style.display = 'flex';
                    finalNavContainer.style.justifyContent = 'flex-end'; // จัดปุ่มไปทางขวา
                }
            }
        } else {
            showSection(0);
        }

        calculateAndUpdateBookingFormTotals();

    }); // End of DOMContentLoaded
</script>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../templates/layout.php';
?>