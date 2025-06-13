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
    function getHotelDefaultCheckinTime(bool $fromCalendar = false, ?string $selectedDateStr = null): string {
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
}
elseif (!$editBookingId && !$isMultiRoomMode) {
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

        if (isset($_GET['calendar_checkin_date']) && isset($_GET['edit_booking_id']) && $_GET['edit_booking_id'] == $currentEditBookingIdForFallback && !$isMultiRoomMode ) {
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
<div style="margin-bottom: 1.5rem; padding-bottom: 1rem; border-bottom: 1px solid var(--color-border);">
    <h2><?= h($pageTitle) ?></h2>
    <?php if (!$editBookingId && !$isMultiRoomMode): ?>
        <div class="button-group" style="margin-top: 0.5rem;">
            <a href="booking.php?mode=multi" class="button outline-secondary">สลับไปโหมดจองหลายห้อง</a>
        </div>
    <?php elseif ($isMultiRoomMode && !$editBookingId): ?>
         <div class="button-group" style="margin-top: 0.5rem;">
            <a href="booking.php" class="button outline-secondary">สลับไปโหมดจองห้องเดียว</a>
        </div>
    <?php endif; ?>
</div>

<?php // **** START: PROGRESS BAR **** ?>
<div id="booking-progress-bar-container" style="margin-bottom: 1.5rem; <?= ($editBookingId && $bookingData) ? 'display:none;' : '' ?>">
    <div class="progress-bar-steps">
        <div class="progress-step active" data-step="1">ห้องพัก</div>
        <div class="progress-step" data-step="2">ผู้จอง</div>
        <div class="progress-step" data-step="3">วันเวลา</div>
        <div class="progress-step" data-step="4">บริการเสริม</div>
        <div class="progress-step" data-step="5">ชำระเงิน</div>
        <div class="progress-step" data-step="6">ยืนยัน</div>
    </div>
</div>
<?php // **** END: PROGRESS BAR **** ?>


<form id="booking-form" action="<?= h($form_action_url) ?>" method="post" enctype="multipart/form-data">
    <input type="hidden" name="action" value="<?= ($editBookingId && $bookingData) ? 'update_booking_with_addons' : 'create' ?>">
    <?php if ($editBookingId && $bookingData): ?>
        <input type="hidden" name="booking_id" value="<?= h($bookingData['id']) ?>">
    <?php endif; ?>

    <?php // **** START: SECTION WRAPPERS **** ?>
    <div id="booking-section-1" class="booking-section active-section">
        <h4 class="section-title">ขั้นตอนที่ 1: เลือกห้องพักและประเภทการจอง</h4>
        
        <?php if ($isMultiRoomMode): ?>
            <input type="hidden" name="booking_mode" value="multi">
            <div class="form-group">
                <label for="room_ids">เลือกห้องพัก (เลือกได้หลายห้อง - สำหรับค้างคืนเท่านั้นในโหมดนี้):</label>
                <select name="room_ids[]" id="room_ids" multiple required size="10" class="form-control">
                    <?php
                    foreach ($all_rooms_for_multi as $room_item_multi) { 
                        $price_display = h(number_format((float)$room_item_multi['price_per_day'],2));
                        echo '<option value="'.h($room_item_multi['id']).'" data-price="'.h($room_item_multi['price_per_day']).'" data-zone="'.h($room_item_multi['zone']).'" data-allow-short-stay="0" data-ask-deposit-f="'.h($room_item_multi['ask_deposit_on_overnight']).'">'.h($room_item_multi['zone'] . $room_item_multi['room_number']).' (ราคาปกติ: '.$price_display.' บาท)</option>';
                    }
                    ?>
                </select>
                <small>กด Ctrl (หรือ Cmd บน Mac) ค้างไว้เพื่อเลือกหลายห้อง สถานะห้องปัจจุบันจะไม่มีผลกับการจองล่วงหน้า</small>
                <small class="text-danger"><br>หมายเหตุ: การจองหลายห้องในขณะนี้จะใช้ราคา "ค้างคืน" และคิดค่ามัดจำมาตรฐานสำหรับทุกห้อง (โซน F จะถูกจัดการตามมาตรฐานในโหมดนี้)</small>
            </div>
        <?php else: ?>
            <div class="form-group">
                <label for="room_id">ห้องพัก</label>
                <select name="room_id" id="room_id" required <?= ($editBookingId && $bookingData) ? 'disabled' : '' ?> class="form-control">
                    <option value="">-- เลือกห้องพัก --</option>
                    <?php
                    if ($editBookingId && $bookingData && isset($room_details_for_js[$bookingData['room_id']])) {
                        $r_edit = $room_details_for_js[$bookingData['room_id']]; 
                        $price_text = 'ราคาปกติ: ' . h(number_format((float)$r_edit['price_per_day'],2)) . ' บ.';
                        if ($r_edit['allow_short_stay']) {
                            $price_text .= ' / ชั่วคราว: ' . h(number_format((float)$r_edit['price_short_stay'],2)) . ' บ. (' . h($r_edit['short_stay_duration_hours']) . ' ชม.)';
                        }
                        echo '<option value="'.h($r_edit['id']).'" selected data-price="'.h($r_edit['price_per_day']).'" data-price-short="'.h($r_edit['price_short_stay']).'" data-allow-short-stay="'.h($r_edit['allow_short_stay']).'" data-duration-short="'.h($r_edit['short_stay_duration_hours']).'" data-zone="'.h($r_edit['zone']).'" data-ask-deposit-f="'.h($r_edit['ask_deposit_on_overnight']).'">'.h($r_edit['zone'] . $r_edit['room_number']).' (ห้องปัจจุบัน - ' . $price_text . ')</option>';
                    } elseif (!$editBookingId && !$isMultiRoomMode) {
                        if (empty($available_rooms_for_single)) {
                            echo '<option value="" disabled>ไม่มีห้องพักที่สามารถจองได้ในขณะนี้</option>';
                        } else {
                            foreach ($available_rooms_for_single as $room_item_single) {
                                $selected = ($prefillRoomId === (int)$room_item_single['id']) ? 'selected' : '';
                                $price_text = 'ราคาปกติ: ' . h(number_format((float)$room_item_single['price_per_day'],2)) . ' บ.';
                                if ($room_item_single['allow_short_stay']) {
                                     $price_text .= ' / ชั่วคราว: ' . h(number_format((float)$room_item_single['price_short_stay'],2)) . ' บ. (' . h($room_item_single['short_stay_duration_hours']) . ' ชม.)';
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
                                echo '<option value="'.h($room_item_single['id']).'" data-price="'.h($room_item_single['price_per_day']).'" data-price-short="'.h($room_item_single['price_short_stay']).'" data-allow-short-stay="'.h($room_item_single['allow_short_stay']).'" data-duration-short="'.h($room_item_single['short_stay_duration_hours']).'" '.$selected.' data-zone="'.h($room_item_single['zone']).'" data-ask-deposit-f="'.h($room_item_single['ask_deposit_on_overnight']).'" data-current-status="'.h($current_room_display_status).'">'.h($room_item_single['zone'] . $room_item_single['room_number']).' - ' . $price_text . h($status_display_text) . '</option>';
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
        
        <div class="form-group" id="booking-type-group" style="<?= ($isMultiRoomMode || ($editBookingId && $bookingData && isset($bookingData['current_allow_short_stay']) && $bookingData['current_allow_short_stay'] == '0' && $current_booking_type_for_edit !== 'short_stay')) ? 'display:none;' : 'display:block;' ; ?>">
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
            <button type="button" class="button outline-secondary prev-step-btn">&laquo; ย้อนกลับ</button>
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
                       <?= ($editBookingId && $current_booking_type_for_edit === 'overnight' && !empty($bookingData['checkout_datetime_calculated']) && isset($_POST['checkout_datetime_edit']) ) ? 'readonly' : '' ?>>
                <button type="button" class="quantity-btn quantity-plus" aria-label="Increase nights" data-field="nights" <?= ($editBookingId && $current_booking_type_for_edit === 'short_stay') ? 'disabled' : '' ?>>+</button>
            </div>
            <small id="nights-readonly-note" style="display:none; color: var(--color-text-muted); margin-top: 0.25rem;">
                <em>จำนวนคืนจะถูกคำนวณอัตโนมัติเมื่อแก้ไขวันเช็คเอาท์</em>
            </small>
        </div>
        <input type="hidden" name="short_stay_duration_hours" id="short_stay_duration_hours" value="<?= h($editBookingId && $bookingData && $bookingData['booking_type']==='short_stay' && isset($room_details_for_js[$bookingData['room_id']]) ? ($room_details_for_js[$bookingData['room_id']]['short_stay_duration_hours'] ?? DEFAULT_SHORT_STAY_DURATION_HOURS) : DEFAULT_SHORT_STAY_DURATION_HOURS) ?>">
        
        <div class="form-group" id="flexible-overnight-group" >
            <label class="checkbox-btn" for="flexible_overnight_mode">
                <strong>โหมดค้างคืนแบบยืดหยุ่น:</strong> เช็คอินดึก (เช่น ตี 1 - 11 โมงเช้า) จะเช็คเอาท์เที่ยงวันเดียวกัน (ค่าบริการเท่าเดิม)
                <input id="flexible_overnight_mode" name="flexible_overnight_mode" type="checkbox" value="1" <?= ($editBookingId && $bookingData) ? 'disabled' : '' ?>>
             <span class="checkmark"></span>
            </label>
            <?php if ($editBookingId && $bookingData): ?> <small><em>โหมดนี้ใช้ได้เฉพาะการสร้างการจองใหม่</em></small> <?php endif; ?>
        </div>
        <div class="navigation-buttons">
            <button type="button" class="button outline-secondary prev-step-btn">&laquo; ย้อนกลับ</button>
            <button type="button" class="button next-step-btn">ถัดไป &raquo;</button>
        </div>
    </div>

    <div id="booking-section-4" class="booking-section">
        <h4 class="section-title">ขั้นตอนที่ 4: บริการเสริม (Add-ons)</h4>

        <?php // ***** START: โค้ดที่แก้ไขและเพิ่มเติม ***** ?>

        <?php if ($isMultiRoomMode): ?>
            <div id="multi-room-addon-manager">
                <p class="text-muted">กรุณาเลือกห้องพักในขั้นตอนที่ 1 ก่อน เพื่อจัดการบริการเสริม</p>
                
                <?php // ส่วนนี้จะถูกสร้างโดย JavaScript เมื่อมีการเลือกห้อง ?>
            </div>
        <?php else: ?>
            <?php // โค้ดสำหรับโหมดห้องเดียว (Single Room Mode) ยังคงเดิม ?>
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
                        <div class="addon-chip-wrapper <?= $isChecked ? 'selected' : '' ?>">
                            <input type="checkbox"
                                   name="selected_addons[<?= h($addonId) ?>][id]"
                                   value="<?= h($addonId) ?>"
                                   id="addon_<?= h($addonId) ?>"
                                   data-price="<?= h($addon['price']) ?>"
                                   class="addon-checkbox"
                                   <?= $isChecked ? 'checked' : '' ?>>
                            <label for="addon_<?= h($addonId) ?>" class="addon-chip-label">
                                <?= h($addon['name']) ?> (<?= h(number_format((float)$addon['price'], 2)) ?> บ.) </label>
                            <input type="number"
                                   name="selected_addons[<?= h($addonId) ?>][quantity]"
                                   value="<?= h($quantity) ?>"
                                   min="1"
                                   class="addon-quantity"
                                   data-addon-id="<?= h($addonId) ?>"
                                   style="width: 60px; margin-left: 5px; <?= !$isChecked ? 'display:none;' : 'display:inline-block;' ?>"
                                   <?= !$isChecked ? 'disabled' : '' ?>>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php else: ?>
                <p><em>ไม่มีบริการเสริมให้เลือกในขณะนี้</em></p>
            <?php endif; ?>
        <?php endif; ?>

        <?php // ***** END: โค้ดที่แก้ไขและเพิ่มเติม ***** ?>

        <div class="navigation-buttons">
            <button type="button" class="button outline-secondary prev-step-btn">&laquo; ย้อนกลับ</button>
            <button type="button" class="button next-step-btn">ถัดไป &raquo;</button>
        </div>
    </div>

    <div id="booking-section-5" class="booking-section">
        <h4 class="section-title">ขั้นตอนที่ 5: การชำระเงินและหลักฐาน</h4>
        
        <div class="form-group" id="zone-f-deposit-group" style="display:none; background-color: #fffbe6; padding: 10px; border-radius: var(--border-radius-sm); border: 1px solid #ffe58f;">
            <label class="checkbox-btn" for="collect_deposit_zone_f">
                <strong>สำหรับห้องโซน F (ค้างคืน): ต้องการเก็บค่ามัดจำ <?= h(number_format(FIXED_DEPOSIT_AMOUNT,0)) ?> บาท หรือไม่?</strong> (หากไม่เลือก จะไม่เก็บค่ามัดจำ) <input id="collect_deposit_zone_f" name="collect_deposit_zone_f" type="checkbox" value="1" <?= ($editBookingId && $bookingData && (float)($bookingData['deposit_amount'] ?? 0) > 0 && isset($bookingData['zone']) && $bookingData['zone'] === 'F') ? 'checked' : '' ?> <?= ($editBookingId && $bookingData) ? 'disabled' : '' ?>>
                <span class="checkmark"></span>
            </label>
            <?php if ($editBookingId && $bookingData): ?> <small><em>การตัดสินใจเก็บมัดจำโซน F ไม่สามารถเปลี่ยนได้ในหน้านี้</em></small> <?php endif; ?>
        </div>

        <div class="form-group">
            <label for="base_amount_paid_display">ยอดค่าห้องพัก (บาท):</label>
            <input type="number" name="base_amount_paid_display" id="base_amount_paid_display" value="<?= ($editBookingId && $bookingData) ? $base_amount_for_edit_js : '0' ?>" readonly style="background-color: #e9ecef; border: 1px solid #ced4da;" class="form-control">
            <small id="base_amount_note">ยอดนี้สำหรับค่าห้องพัก (ตามประเภทและจำนวนคืน/ชั่วโมง) ยังไม่รวมบริการเสริม</small>
        </div>

        <div class="form-group">
            <p><strong>ยอดบริการเสริม:</strong> <span id="total-addon-price-display"><?= ($editBookingId && $bookingData) ? $total_addon_price_for_edit_js : '0' ?></span> บาท</p>
            <p><strong>ค่ามัดจำ:</strong> <span id="deposit-amount-display"><?= ($editBookingId && $bookingData) ? $deposit_amount_for_edit_js : '0' ?></span> บาท <span id="deposit_note_text" class="text-muted">(มาตรฐาน <?= h(number_format(FIXED_DEPOSIT_AMOUNT,0)) ?> บาท สำหรับการจองค้างคืน นอกโซน F)</span></p> <hr>
            <p style="font-size: 1.1rem;"><strong>ยอดรวมที่ต้องชำระ/มูลค่าการจอง:</strong> <strong id="grand-total-price-display" style="color: var(--color-primary-dark);"><?= ($editBookingId && $bookingData) ? $grand_total_amount_for_edit_js : '0' ?></strong> บาท</p>
        </div>

        <div class="form-group" style="margin-top: 1rem;">
            <label for="final_amount_paid">ยอดชำระแล้วทั้งหมด (บาท):</label>
            <input type="number" name="amount_paid" id="final_amount_paid" step="1" min="0" value="<?= ($editBookingId && $bookingData) ? h($final_amount_paid_for_edit_js) : '0' ?>" class="form-control" data-amount-paid-manually-set="false">
             <?php if($editBookingId && $bookingData): ?>
                <small class="text-info" style="display:block; margin-top:0.25rem;"><em>คุณสามารถแก้ไขยอดชำระแล้วทั้งหมดได้ที่นี่ หากมีการรับเงินเพิ่มหรือคืนเงินภายหลังการแก้ไขการจองหลัก</em></small>
            <?php else: ?>
                <small class="text-muted" style="display:block; margin-top:0.25rem;"><em>สำหรับสร้างใหม่: กรอกยอดที่ลูกค้าชำระจริง หากไม่กรอก ระบบจะใช้ยอดรวมที่คำนวณได้</em></small>
            <?php endif; ?>
        </div>

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
                    <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="currentColor" class="upload-icon"><path d="M19.35 10.04C18.67 6.59 15.64 4 12 4 9.11 4 6.6 5.64 5.35 8.04 2.34 8.36 0 10.91 0 14c0 3.31 2.69 6 6 6h13c2.76 0 5-2.24 5-5 0-2.64-2.05-4.78-4.65-4.96zM14 13v4h-4v-4H7l5-5 5 5h-3z"/></svg>
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
            <button type="button" class="button outline-secondary prev-step-btn">&laquo; ย้อนกลับ</button>
            <button type="submit" id="submit-booking-form-btn" class="button primary"><?= ($editBookingId && $bookingData) ? 'บันทึกการแก้ไข' : ($isMultiRoomMode ? 'ยืนยันการจองหลายห้อง' : 'ยืนยันการจอง') ?></button>
        </div>
    </div>
    <?php // **** END: SECTION WRAPPERS **** ?>

    <?php if ($editBookingId && $bookingData): ?>
        <a href="/hotel_booking/pages/index.php" class="button outline-primary" style="margin-left: 10px; margin-top:1rem;">ยกเลิกการแก้ไข</a>
    <?php else: ?>
        <a href="/hotel_booking/pages/index.php" class="button outline-secondary" style="margin-top:1rem; display:none;">ยกเลิก</a>
    <?php endif; ?>
</form>

<style>
/* CSS สำหรับ Progress Bar และ Sections */
.progress-bar-steps {
    display: flex;
    justify-content: space-between;
    margin-bottom: 20px;
    padding: 0;
    list-style: none;
}
.progress-step {
    flex: 1;
    text-align: center;
    padding: 10px;
    border-bottom: 3px solid var(--color-border);
    color: var(--color-text-muted);
    font-weight: 500;
    transition: border-color 0.3s, color 0.3s;
    font-size: 0.9em;
}
.progress-step.active {
    border-bottom-color: var(--color-primary);
    color: var(--color-primary-dark);
    font-weight: 700;
}
.progress-step.completed { /* เพิ่มสถานะ completed */
    border-bottom-color: var(--color-secondary);
    color: var(--color-secondary-dark);
}

.booking-section {
    display: none; /* ซ่อนทุก section โดย default */
    padding: 1.5rem;
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius-md);
    margin-bottom: 1.5rem;
    background-color: var(--color-surface);
}
.booking-section.active-section {
    display: block; /* แสดงเฉพาะ section ที่ active */
}
.section-title {
    font-size: 1.3rem;
    color: var(--color-primary-dark);
    margin-top: 0;
    margin-bottom: 1.5rem;
    padding-bottom: 0.5rem;
    border-bottom: 1px solid var(--color-border);
}
.navigation-buttons {
    margin-top: 2rem;
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding-top: 1rem;
    border-top: 1px dashed var(--color-border);
}
.navigation-buttons .next-step-btn,
.navigation-buttons .prev-step-btn {
    min-width: 120px;
}

/* Styles for File Upload Preview */
.previews-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
    gap: 1rem;
    margin-top: 1rem;
    padding: 0.5rem;
    background-color: var(--color-bg);
    border-radius: var(--border-radius-md);
    border: 1px solid var(--color-border);
}
.file-preview-item {
    border: 1px solid var(--color-border);
    border-radius: var(--border-radius-sm);
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
    height: 80px; /* Smaller preview height */
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 0.5rem;
    background-color: var(--color-surface-alt);
    border-radius: var(--border-radius-sm);
    overflow: hidden;
}
.receipt-preview-thumb { /* Reusing existing class for consistency */
    max-width: 100%;
    max-height: 100%;
    object-fit: contain;
}
.pdf-icon-preview, .other-file-preview {
    font-size: 2rem;
    color: var(--color-text-muted);
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    width: 100%;
    height: 100%;
}
.pdf-filename-preview, .other-filename-preview {
    font-size: 0.65rem; /* Smaller font for filename */
    color: var(--color-text-muted);
    word-break: break-all;
    line-height: 1.2;
    text-align: center;
    margin-top: 0.2rem;
}
.receipt-description-group { margin-top: 0.5rem; width: 100%; }
.receipt-description-group label { font-size: 0.75rem !important; margin-bottom: 0.1rem !important; }
.form-control-sm { font-size: 0.8rem !important; padding: 0.3rem 0.5rem !important; }

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
.remove-file-btn:hover { background-color: var(--color-alert-dark); }
.filenames-display-area p { margin: 0.2rem 0; }
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
        const multiRoomSelect_BookingForm = document.getElementById('room_ids');
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

        // ***** START: โค้ดส่วนใหม่สำหรับ Multi-Room Add-ons *****
        const multiRoomAddonManager = document.getElementById('multi-room-addon-manager');
        const activeAddonsData = <?php echo json_encode($active_addons ?? []); ?>;

        function createAddonSelector(roomId = null) {
            const select = document.createElement('select');
            select.className = 'form-control';
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
            confirmBtn.style.marginLeft = '10px';

            confirmBtn.onclick = function() {
                const selectedOption = select.options[select.selectedIndex];
                if (!selectedOption.value) return;

                const addonId = selectedOption.value;
                const addonName = selectedOption.dataset.name;
                const addonPrice = selectedOption.dataset.price;
                
                if (roomId) { // Add to specific room
                    addAddonToRoomDOM(roomId, addonId, addonName, addonPrice);
                } else { // Add to all selected rooms
                    const selectedRoomIds = Array.from(multiRoomSelect_BookingForm.selectedOptions).map(opt => opt.value);
                    selectedRoomIds.forEach(id => {
                        addAddonToRoomDOM(id, addonId, addonName, addonPrice);
                    });
                }
                select.parentElement.remove(); // Remove selector after adding
                calculateAndUpdateBookingFormTotals();
            };

            const container = document.createElement('div');
            container.style.display = 'flex';
            container.style.marginTop = '10px';
            container.appendChild(select);
            container.appendChild(confirmBtn);
            return container;
        }

        function addAddonToRoomDOM(roomId, addonId, addonName, addonPrice) {
            const roomContainer = multiRoomAddonManager.querySelector(`.addon-room-container[data-room-id="${roomId}"]`);
            const itemsList = roomContainer.querySelector('.addon-items-list');
            
            // Prevent adding the same addon twice to the same room
            if (roomContainer.querySelector(`[data-addon-id="${addonId}"]`)) {
                return;
            }

            const addonItemDiv = document.createElement('div');
            addonItemDiv.className = 'addon-item-entry';
            addonItemDiv.dataset.addonId = addonId;

            addonItemDiv.innerHTML = `
                <span>${addonName}</span>
                <input type="number" name="room_addons[${roomId}][${addonId}]" value="1" min="1" class="addon-quantity-multi form-control-sm" style="width: 60px; text-align: center;">
                <button type="button" class="button-small alert remove-addon-btn">&times;</button>
            `;

            addonItemDiv.querySelector('.remove-addon-btn').onclick = function() {
                this.parentElement.remove();
                calculateAndUpdateBookingFormTotals();
            };
            
            addonItemDiv.querySelector('.addon-quantity-multi').oninput = calculateAndUpdateBookingFormTotals;

            itemsList.appendChild(addonItemDiv);
        }

        function updateMultiRoomAddonUI() {
            if (!IS_MULTI_ROOM_MODE_JS || !multiRoomAddonManager) return;
            
            multiRoomAddonManager.innerHTML = ''; // Clear previous state
            const selectedOptions = Array.from(multiRoomSelect_BookingForm.selectedOptions);

            if (selectedOptions.length === 0) {
                multiRoomAddonManager.innerHTML = '<p class="text-muted">กรุณาเลือกห้องพักในขั้นตอนที่ 1 ก่อน เพื่อจัดการบริการเสริม</p>';
                return;
            }
            
            const toolbar = document.createElement('div');
            toolbar.className = 'multi-addon-toolbar';
            const addAllBtn = document.createElement('button');
            addAllBtn.type = 'button';
            addAllBtn.className = 'button primary';
            addAllBtn.innerHTML = '<i class="fas fa-plus"></i> เพิ่มบริการเสริมให้ทุกห้องที่เลือก';
            addAllBtn.onclick = function() {
                // Remove existing selector if any
                const existingSelector = toolbar.querySelector('.addon-selector-container');
                if(existingSelector) existingSelector.remove();
                
                const selectorContainer = document.createElement('div');
                selectorContainer.className = 'addon-selector-container';
                selectorContainer.appendChild(createAddonSelector());
                toolbar.appendChild(selectorContainer);
            };
            toolbar.appendChild(addAllBtn);
            multiRoomAddonManager.appendChild(toolbar);

            const roomsContainer = document.createElement('div');
            roomsContainer.className = 'addon-rooms-grid';
            selectedOptions.forEach(option => {
                const roomId = option.value;
                const roomName = option.text.split(' (')[0];

                const roomDiv = document.createElement('div');
                roomDiv.className = 'addon-room-container';
                roomDiv.dataset.roomId = roomId;
                roomDiv.innerHTML = `
                    <h5>${roomName}</h5>
                    <div class="addon-items-list"></div>
                    <button type="button" class="button-small outline-secondary add-addon-per-room-btn">+ เพิ่มให้ห้องนี้</button>
                `;
                roomDiv.querySelector('.add-addon-per-room-btn').onclick = function() {
                    this.parentElement.appendChild(createAddonSelector(roomId));
                    this.style.display = 'none'; // Hide button after click
                };
                roomsContainer.appendChild(roomDiv);
            });
            multiRoomAddonManager.appendChild(roomsContainer);
        }
        
        // Add some CSS for the new UI
        const multiAddonStyle = document.createElement('style');
        multiAddonStyle.innerHTML = `
            .multi-addon-toolbar { margin-bottom: 1.5rem; }
            .addon-rooms-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1rem; }
            .addon-room-container { border: 1px solid var(--color-border); padding: 1rem; border-radius: var(--border-radius-md); }
            .addon-room-container h5 { margin-top: 0; }
            .addon-items-list { min-height: 40px; margin-bottom: 1rem; }
            .addon-item-entry { display: flex; justify-content: space-between; align-items: center; padding: 0.5rem; background-color: var(--color-surface-alt); margin-bottom: 0.5rem; border-radius: var(--border-radius-sm); }
        `;
        document.head.appendChild(multiAddonStyle);

        // ***** END: โค้ดส่วนใหม่สำหรับ Multi-Room Add-ons *****

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
            }
        }

        function validateCurrentSection() {
            const currentSection = sections[currentSectionIndex];
            if (!currentSection) return true;
            let isValid = true;
            let firstInvalidElement = null;

            const inputs = currentSection.querySelectorAll('input[required], select[required]');
            for(const input of inputs) {
                if(input.offsetParent !== null && !input.disabled) {
                    if ((input.type === 'checkbox' && !input.checked) || (input.type !== 'checkbox' && !input.value.trim())) {
                         isValid = false;
                         firstInvalidElement = input;
                         alert(`กรุณากรอกข้อมูล: ${input.labels[0]?.textContent || input.name}`);
                         break;
                    }
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
            
            document.getElementById('summary_room').textContent = IS_MULTI_ROOM_MODE_JS ? 
                Array.from(multiRoomSelect_BookingForm.selectedOptions).map(opt => opt.text.split(' (')[0]).join(', ') : 
                (roomSelect_BookingForm.options[roomSelect_BookingForm.selectedIndex]?.text.split(' - ')[0] || 'N/A');
            
            document.getElementById('summary_customer').textContent = customerNameInput.value.trim() || 'ไม่ระบุ';
            
            document.getElementById('summary_checkin').textContent = checkinDatetimeInput.value ? 
                new Date(checkinDatetimeInput.value.replace('T', ' ')).toLocaleString('th-TH', { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' }) + ' น.' : 
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
            document.getElementById('summary_amount_paid').textContent = finalAmountPaidInput_BookingForm_Local.value;
            document.getElementById('summary_payment_method').textContent = paymentMethodSelect_BookingForm.value || 'N/A';
        }
        
        function calculateAndUpdateBookingFormTotals() {
            // [Your full calculation logic should be here]
            
            if (IS_MULTI_ROOM_MODE_JS) {
                let multiAddonTotal = 0;
                const addonInputs = document.querySelectorAll('.addon-quantity-multi');
                addonInputs.forEach(input => {
                    const addonIdMatch = input.name.match(/\[(\d+)\]$/);
                    if (addonIdMatch) {
                        const addonId = addonIdMatch[1];
                        const quantity = parseInt(input.value, 10) || 0;
                        const addonData = activeAddonsData.find(a => a.id == addonId);
                        if (addonData && quantity > 0) {
                            multiAddonTotal += (parseFloat(addonData.price) * quantity);
                        }
                    }
                });

                const totalAddonPriceDisplay = document.getElementById('total-addon-price-display');
                const grandTotalPriceDisplay = document.getElementById('grand-total-price-display');
                const finalAmountPaidInput = document.getElementById('final_amount_paid');
                const baseAmount = parseFloat(document.getElementById('base_amount_paid_display').value) || 0;
                const depositAmount = parseFloat(document.getElementById('deposit-amount-display').textContent) || 0;

                if (totalAddonPriceDisplay) totalAddonPriceDisplay.textContent = Math.round(multiAddonTotal);
                const grandTotal = baseAmount + multiAddonTotal + depositAmount;
                if (grandTotalPriceDisplay) grandTotalPriceDisplay.textContent = Math.round(grandTotal);
                if (finalAmountPaidInput && finalAmountPaidInput.dataset.amountPaidManuallySet !== 'true') {
                    finalAmountPaidInput.value = Math.round(grandTotal);
                }
            }

            // At the end, update the summary if it's visible.
            updateSummaryReview();
        }

        // --- START: *** ส่วนที่แก้ไข proceedWithActualSubmission และการเรียกใช้ *** ---
        async function proceedWithActualSubmission(formData, formActionUrlParam, submitButton, isMultiMode, actionType) {
            console.log('[ProceedSubmit booking.php] Starting actual submission.');
            console.log('[ProceedSubmit booking.php] formActionUrlParam received:', formActionUrlParam);

            if (typeof formActionUrlParam !== 'string' || !formActionUrlParam || !formActionUrlParam.includes('api.php')) {
                console.error('[ProceedSubmit booking.php] Invalid formActionUrlParam:', formActionUrlParam);
                alert('เกิดข้อผิดพลาดของหน้าเว็บ: ไม่สามารถหา URL สำหรับส่งข้อมูลได้ (Invalid API URL)');
                if(submitButton) setButtonLoading(submitButton, false, submitButton.id || 'submitBookingFormBtnError');
                return;
            }

            if(submitButton) setButtonLoading(submitButton, true, submitButton.id || 'submitBookingFormBtn');
            
            if (uploadedFileObjects && uploadedFileObjects.length > 0) {
                formData.delete('receipt_files[]');
                uploadedFileObjects.forEach(file => {
                    formData.append('receipt_files[]', file, file.name);
                });
            } else if (formData.has('receipt_files[]')) {
                formData.delete('receipt_files[]');
            }

            try {
                const resp = await fetch(formActionUrlParam, { method: 'POST', body: formData });
                const responseText = await resp.text();
                console.log('[ProceedSubmit booking.php] API Raw Response:', responseText);
                try {
                    const data = JSON.parse(responseText);
                    if (data.success) {
                        alert(data.message || 'การดำเนินการสำเร็จ!');
                        window.location.href = data.redirect_url || '/hotel_booking/pages/index.php';
                    } else {
                        alert(data.message || 'เกิดข้อผิดพลาด: ' + (data.detail || 'An error occurred'));
                        if(submitButton) setButtonLoading(submitButton, false, submitButton.id || 'submitBookingFormBtnSuccessFalse');
                    }
                } catch (parseError) {
                    console.error('[ProceedSubmit booking.php] JSON Parse error:', parseError, "\nResponse was:", responseText);
                    alert('เกิดข้อผิดพลาดในการประมวลผลการตอบกลับจากเซิร์ฟเวอร์');
                    if(submitButton) setButtonLoading(submitButton, false, submitButton.id || 'submitBookingFormBtnParseError');
                }
            } catch (err) {
                console.error('[ProceedSubmit booking.php] Submission fetch error:', err);
                alert('ไม่สามารถเชื่อมต่อเซิร์ฟเวอร์ได้');
                if(submitButton) setButtonLoading(submitButton, false, submitButton.id || 'submitBookingFormBtnFetchError');
            }
        }

        bookingForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            console.log('[BookingFormSubmit booking.php] Form submission initiated.');

            calculateAndUpdateBookingFormTotals();
            
            if (!IS_EDIT_MODE_JS && !validateCurrentSection()) {
                if (mainSubmitButton) setButtonLoading(mainSubmitButton, false, mainSubmitButton.id || 'submitBookingFormBtnValidationFailed');
                return;
            }

            const formData = new FormData(bookingForm);
            const currentActionForSubmit = formData.get('action');
            const isMultiModeForSubmit = IS_MULTI_ROOM_MODE_JS;
            
            const formActionUrlFromPHP = <?php echo json_encode($form_action_url); ?>;
            console.log('[BookingFormSubmit booking.php] Intended API URL from PHP:', formActionUrlFromPHP);
            
            const resolvedFormActionUrl = formActionUrlFromPHP;

            if (isMultiModeForSubmit && currentActionForSubmit === 'create') {
                if (confirm('คุณกำลังจะจองหลายห้องพัก ยืนยันหรือไม่?')) {
                    await proceedWithActualSubmission(formData, resolvedFormActionUrl, mainSubmitButton, true, 'create');
                } else {
                    if (mainSubmitButton) setButtonLoading(mainSubmitButton, false, mainSubmitButton.id || 'submitBookingFormBtnMultiCancel');
                }
            } else {
                await proceedWithActualSubmission(formData, resolvedFormActionUrl, mainSubmitButton, isMultiModeForSubmit, currentActionForSubmit);
            }
        });
        // --- END: *** ส่วนที่แก้ไข proceedWithActualSubmission และการเรียกใช้ *** ---

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
                        receiptFilesInput.dispatchEvent(new Event('change', { bubbles: true }));
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
        if (multiRoomSelect_BookingForm) {
            multiRoomSelect_BookingForm.addEventListener('change', () => {
                 calculateAndUpdateBookingFormTotals();
                 updateMultiRoomAddonUI();
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