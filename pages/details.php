<?php
// FILEX: hotel_booking/pages/details.php
require_once __DIR__ . '/../bootstrap.php';

$roomId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$roomId) {
    echo '<p class="text-danger" style="padding:20px;">ไม่พบรหัสห้องพัก</p>';
    exit;
}

// Ensure constants are defined (they should be in bootstrap.php)
if (!defined('DEFAULT_SHORT_STAY_DURATION_HOURS')) {
    define('DEFAULT_SHORT_STAY_DURATION_HOURS', 3);
}
if (!defined('CHECKOUT_TIME_STR')) {
    define('CHECKOUT_TIME_STR', '12:00:00');
}

$stmtRoom = $pdo->prepare("SELECT id, zone, room_number, status, price_per_day, price_short_stay, allow_short_stay, short_stay_duration_hours, ask_deposit_on_overnight, price_per_hour_extension FROM rooms WHERE id = ?");
$stmtRoom->execute([$roomId]);
$room = $stmtRoom->fetch(PDO::FETCH_ASSOC);
$hourly_rate_from_system_settings_details = HOURLY_RATE;

if (!$room) {
    echo '<p class="text-danger" style="padding:20px;">ไม่พบข้อมูลห้องพัก</p>';
    exit;
}

// --- START: ปรับปรุงการ Query และการตั้งค่า Flag ---
$stmtRelevantBooking = $pdo->prepare("
    SELECT
        b.*,
        r.zone as room_current_zone,
        r.room_number as room_number_for_log,
        COALESCE(r.short_stay_duration_hours, ".DEFAULT_SHORT_STAY_DURATION_HOURS.") as room_short_stay_duration, /* MODIFIED */
        r.price_per_hour_extension,
        DATE_FORMAT(b.checkin_datetime, '%e %b %Y, %H:%i น.') AS formatted_checkin,
        DATE_FORMAT(b.checkout_datetime_calculated, '%e %b %Y, %H:%i น.') AS current_checkout_display_with_ext,
        DATE_FORMAT(b.checkout_datetime_calculated, '%Y-%m-%d %H:%i:%s') AS php_calculated_actual_checkout_datetime_str_for_js,
        u_creator.username AS creator_username,
        u_modifier.username AS last_modifier_username
    FROM bookings b
    JOIN rooms r ON b.room_id = r.id
    LEFT JOIN users u_creator ON b.created_by_user_id = u_creator.id
    LEFT JOIN users u_modifier ON b.last_modified_by_user_id = u_modifier.id
    WHERE b.room_id = :room_id
      AND b.id = ( /* Logic การเลือก booking ที่เกี่ยวข้องที่สุด */
          SELECT b_latest.id
          FROM bookings b_latest
          WHERE b_latest.room_id = :room_id_inner
          ORDER BY
              (CASE
                  WHEN b_latest.checkin_datetime <= NOW() AND NOW() < b_latest.checkout_datetime_calculated THEN 1 /* Active */
                  WHEN DATE(b_latest.checkin_datetime) = CURDATE() AND b_latest.checkin_datetime > NOW() THEN 2  /* Pending Today */
                  WHEN NOW() >= b_latest.checkout_datetime_calculated THEN 3 /* Potentially Overdue */
                  ELSE 4 /* Future or other states */
              END) ASC,
              CASE
                  WHEN (CASE
                          WHEN b_latest.checkin_datetime <= NOW() AND NOW() < b_latest.checkout_datetime_calculated THEN 1
                          WHEN DATE(b_latest.checkin_datetime) = CURDATE() AND b_latest.checkin_datetime > NOW() THEN 2
                          WHEN NOW() >= b_latest.checkout_datetime_calculated THEN 3
                          ELSE 4
                      END) = 3 THEN b_latest.checkout_datetime_calculated
                  ELSE b_latest.checkin_datetime
              END DESC,
              b_latest.id DESC
          LIMIT 1
      )
");
$stmtRelevantBooking->execute([':room_id' => $roomId, ':room_id_inner' => $roomId]);
$booking_to_display = $stmtRelevantBooking->fetch(PDO::FETCH_ASSOC);

$activeBooking = null;
$isEffectivelyOverdue = false;
$isPendingToday = false;
$isAdvanceBookingPrimaryDisplay = false;

if ($booking_to_display) {
    $checkin_dt = new DateTime($booking_to_display['checkin_datetime'], new DateTimeZone('Asia/Bangkok'));
    $checkout_calc_dt = new DateTime($booking_to_display['checkout_datetime_calculated'], new DateTimeZone('Asia/Bangkok'));
    $now_dt = new DateTime('now', new DateTimeZone('Asia/Bangkok'));

    if ($checkin_dt <= $now_dt && $now_dt < $checkout_calc_dt) {
        $activeBooking = $booking_to_display;
    } elseif ($now_dt >= $checkout_calc_dt) {
        $isEffectivelyOverdue = true;
    } elseif ($checkin_dt > $now_dt) {
        if ($checkin_dt->format('Y-m-d') === $now_dt->format('Y-m-d')) {
            $isPendingToday = true;
        } else {
            $isAdvanceBookingPrimaryDisplay = true;
        }
    }
}

$show_occupy_button_for_booking_id = null;
$can_early_checkin_after_14 = false;
if ($booking_to_display && $room['status'] === 'booked' && !$isEffectivelyOverdue && !$isAdvanceBookingPrimaryDisplay) {
    if ($activeBooking || $isPendingToday) {
        $show_occupy_button_for_booking_id = $booking_to_display['id'];
        if ($isPendingToday) {
            $checkinDateTimeObjModalButton = new DateTime($booking_to_display['checkin_datetime'], new DateTimeZone('Asia/Bangkok'));
            $nowDateTimeObjModalButton = new DateTime('now', new DateTimeZone('Asia/Bangkok'));
            if ($nowDateTimeObjModalButton < $checkinDateTimeObjModalButton) {
                $can_early_checkin_after_14 = true;
            }
        }
    }
}
// --- END: ปรับปรุงการ Query และการตั้งค่า Flag ---

// ***** START: เพิ่มการดึงข้อมูล Group Receipts *****
$all_group_receipts_for_display = [];
if ($booking_to_display && !empty($booking_to_display['booking_group_id'])) {
    $stmtGroupReceiptsDetails = $pdo->prepare("SELECT receipt_path, description FROM booking_group_receipts WHERE booking_group_id = ? ORDER BY uploaded_at ASC");
    $stmtGroupReceiptsDetails->execute([$booking_to_display['booking_group_id']]);
    $all_group_receipts_for_display = $stmtGroupReceiptsDetails->fetchAll(PDO::FETCH_ASSOC);
}
// ***** END: เพิ่มการดึงข้อมูล Group Receipts *****

$current_booking_addons = [];
$calculated_total_addon_cost_for_display = 0;
if ($booking_to_display) {
    $stmtBookingAddons = $pdo->prepare("
        SELECT ba.addon_service_id, ba.quantity, ba.price_at_booking, aserv.name AS addon_name
        FROM booking_addons ba
        JOIN addon_services aserv ON ba.addon_service_id = aserv.id
        WHERE ba.booking_id = ?
        ORDER BY aserv.name ASC
    ");
    $stmtBookingAddons->execute([$booking_to_display['id']]);
    $current_booking_addons = $stmtBookingAddons->fetchAll(PDO::FETCH_ASSOC);
    foreach ($current_booking_addons as $addon) {
        $calculated_total_addon_cost_for_display += (float)$addon['price_at_booking'] * (int)$addon['quantity'];
    }
}

$sqlAdvanceBookings = "
    SELECT
        b.id as booking_id, b.customer_name, b.customer_phone, b.booking_type,
        DATE_FORMAT(b.checkin_datetime, '%e %b %Y, %H:%i น.') AS formatted_checkin,
        DATE_FORMAT(b.checkout_datetime_calculated, '%e %b %Y, %H:%i น.') AS formatted_checkout,
        b.nights,
        r.short_stay_duration_hours, /* This should be from rooms table for consistency if not overridden in booking */
        b.amount_paid,
        b.receipt_path,
        u_creator.username AS creator_username,
        u_modifier.username AS last_modifier_username
    FROM bookings b
    JOIN rooms r ON b.room_id = r.id
    LEFT JOIN users u_creator ON b.created_by_user_id = u_creator.id
    LEFT JOIN users u_modifier ON b.last_modified_by_user_id = u_modifier.id
    WHERE b.room_id = :room_id_adv
      AND b.checkin_datetime > NOW() ";
// If the primary booking being displayed *is* an advance booking, exclude it from this "other advance bookings" list.
if ($booking_to_display && $isAdvanceBookingPrimaryDisplay) {
     $sqlAdvanceBookings .= " AND b.id != " . (int)$booking_to_display['id'] . " ";
}
$sqlAdvanceBookings .= "ORDER BY b.checkin_datetime ASC";
$stmtAdvanceBookings = $pdo->prepare($sqlAdvanceBookings);
$stmtAdvanceBookings->execute([':room_id_adv' => $roomId]);
$advanceBookings = $stmtAdvanceBookings->fetchAll(PDO::FETCH_ASSOC);


$all_active_addons_for_modal_edit = [];
if ($booking_to_display &&
    ( $activeBooking || $isEffectivelyOverdue || $isAdvanceBookingPrimaryDisplay || $isPendingToday )
   ) {
    $stmt_all_active_addons = $pdo->query("SELECT id, name, price FROM addon_services WHERE is_active = 1 ORDER BY name ASC");
    $all_active_addons_for_modal_edit = $stmt_all_active_addons->fetchAll(PDO::FETCH_ASSOC);
}

if (!function_exists('h')) {
    function h($string) {
        return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8');
    }
}
?>
<div class="details-container">
  <h3>ห้อง <?= h($room['zone'] . $room['room_number']) ?> (สถานะ: <span class="room-status-<?=h($room['status'])?>"><?= h(ucfirst($room['status'])) ?></span>)</h3>
  <p>ราคาปกติ: <?= h(number_format((float)($room['price_per_day'] ?? 0),0)) ?> บาท
    <?php if(isset($room['allow_short_stay']) && $room['allow_short_stay']): ?>
        / ชั่วคราว: <?= h(number_format((float)($room['price_short_stay'] ?? 0),0)) ?> บาท (<?= h($room['short_stay_duration_hours'] ?? DEFAULT_SHORT_STAY_DURATION_HOURS) ?> ชม.)
    <?php endif; ?>
  </p>
  <hr>

  <?php // ***** START: MODIFIED CONDITION FOR DISPLAYING PRIMARY BOOKING DETAILS ***** ?>
  <?php if ($booking_to_display && !$isAdvanceBookingPrimaryDisplay): ?>
    <section class="current-booking-details">
      <h4>
          <?php
            $bookingHeaderTitle = 'ข้อมูลการจอง'; // Default
            $bookingStatusIndicatorText = '';

            if ($isEffectivelyOverdue) {
                $bookingHeaderTitle = 'ข้อมูลการจองที่เกินกำหนด ';
                $bookingStatusIndicatorText = '<span style="color: var(--color-alert-dark); font-weight: bold;">(อยู่เกินกำหนดเวลาเช็คเอาท์)</span>';
            } elseif ($activeBooking) {
                $bookingHeaderTitle = 'ข้อมูลการจองปัจจุบัน ';
            } elseif ($isPendingToday) {
                $bookingHeaderTitle = 'ข้อมูลการจอง (รอเช็คอินวันนี้) ';
            }
            // Note: $isAdvanceBookingPrimaryDisplay case is handled by the new elseif block below
            echo $bookingHeaderTitle . $bookingStatusIndicatorText;
          ?>
      </h4>
      <?php if ($isEffectivelyOverdue): ?>
          <p style="color: var(--color-alert-dark); font-weight: bold; background-color: var(--color-error-bg); padding: 8px; border-radius: var(--border-radius-sm); border: 1px solid var(--color-error-border);">
              <img src="/hotel_booking/assets/image/warning.png" alt="Warning" style="width:16px; height:16px; margin-right:5px; vertical-align:middle;">
              <strong>แจ้งเตือน:</strong> การจองนี้อยู่เกินกำหนดเวลาเช็คเอาท์แล้ว! กรุณาติดต่อลูกค้าเพื่อดำเนินการต่อ หรือขยายเวลาการเข้าพัก
          </p>
      <?php endif; ?>
      
      <p><strong>ID การจอง:</strong> <?= h($booking_to_display['id']) ?></p>
      <?php $bookingType = $booking_to_display['booking_type'] ?? 'overnight'; ?>
      <p><strong>ชื่อผู้จอง/ลูกค้า:</strong> <?= h($booking_to_display['customer_name']) ?></p>
      <?php if (!empty($booking_to_display['customer_phone'])): ?>
        <p><strong>เบอร์โทรศัพท์:</strong> <a href="tel:<?= h(preg_replace('/[^0-9+]/', '', $booking_to_display['customer_phone'])) ?>" class="link-like"><?= h($booking_to_display['customer_phone']) ?></a></p>
      <?php endif; ?>
      <p><strong>เช็กอิน:</strong> <?= h($booking_to_display['formatted_checkin']) ?></p>
      <p><strong>เช็กเอาต์ (รวมส่วนขยาย):</strong> <span id="current-checkout-datetime-display"><?= h($booking_to_display['current_checkout_display_with_ext'] ?? 'N/A') ?></span></p>

      <?php
        // --- START: ปรับปรุงการแสดงผล Nights และ Extended Hours ---
        if ($bookingType === 'overnight') {
            echo "<p><strong>ประเภทการจอง:</strong> <span style=\"font-weight:bold; color: var(--color-primary);\">ค้างคืน</span></p>";
            echo "<p><strong>จำนวนคืนหลัก:</strong> <span id='current-nights-display'>" . h($booking_to_display['nights']) . "</span> คืน</p>";
            if (isset($booking_to_display['extended_hours']) && (int)$booking_to_display['extended_hours'] > 0) {
                echo "<p><strong>ชั่วโมงที่ขยายเพิ่ม:</strong> <span id='current-extended-hours-display'>" . h((int)$booking_to_display['extended_hours']) . "</span> ชั่วโมง</p>";
            }
            echo "<p><strong>ราคาต่อคืน (ห้องพัก):</strong> " . h(isset($booking_to_display['price_per_night']) ? number_format((float)$booking_to_display['price_per_night'], 0) : '0') . " บาท</p>";
        } else { // short_stay
            echo "<p><strong>ประเภทการจอง:</strong> <span style=\"font-weight:bold; color: var(--color-primary);\">ชั่วคราว (" . h($booking_to_display['room_short_stay_duration'] ?? DEFAULT_SHORT_STAY_DURATION_HOURS) . " ชม.)</span></p>";
            // สำหรับ short_stay ถ้ามีการขยายเป็นชั่วโมง จะแสดงรวมใน checkout_datetime_calculated อยู่แล้ว
            // ถ้าต้องการแสดง "ชั่วโมงที่ขยายเพิ่ม" แยกสำหรับ short_stay ด้วย ก็สามารถเพิ่มเงื่อนไขคล้ายด้านบนได้
            if (isset($booking_to_display['extended_hours']) && (int)$booking_to_display['extended_hours'] > 0) {
                 echo "<p><strong>ชั่วโมงที่ขยายเพิ่ม:</strong> <span id='current-extended-hours-display'>" . h((int)$booking_to_display['extended_hours']) . "</span> ชั่วโมง</p>";
            }
            echo "<p><strong>ราคาห้องพัก (ชั่วคราว):</strong> " . h(number_format((float)($room['price_short_stay'] ?? 0), 0)) . " บาท</p>";
        }
        // --- END: ปรับปรุงการแสดงผล Nights และ Extended Hours ---
      ?>
      
      <?php if (isset($booking_to_display['notes']) && !empty(trim($booking_to_display['notes']))): ?>
        <p><strong>หมายเหตุ:</strong> <span id="current-notes-display" class="notes-display-box"><?= nl2br(h($booking_to_display['notes'])) ?></span></p>
      <?php else: ?>
        <p><strong>หมายเหตุ:</strong> <span id="current-notes-display"><em>ไม่มีหมายเหตุ</em></span></p>
      <?php endif; ?>

      <?php if (!empty($current_booking_addons)): ?>
        <div class="booking-addons-summary">
          <h4>บริการเสริมที่เลือก:</h4>
          <ul class="booking-addons-list">
            <?php foreach ($current_booking_addons as $addon):
                $addon_total_price = (float)$addon['price_at_booking'] * (int)$addon['quantity'];
            ?>
              <li class="addon-item">
                <span class="addon-name"><?= h($addon['addon_name']) ?></span>
                <span class="addon-quantity">x <?= h($addon['quantity']) ?></span>
                <span class="addon-price-each">(<?= h(number_format((float)$addon['price_at_booking'], 0)) ?> บ./หน่วย)</span>
                <span class="addon-price-total"><?= h(number_format($addon_total_price, 0)) ?> บ.</span>
              </li>
            <?php endforeach; ?>
          </ul>
          <p><strong>ยอดรวมค่าบริการเสริม:</strong> <?= h(number_format($calculated_total_addon_cost_for_display, 0)) ?> บาท</p>
        </div>
      <?php else: ?>
        <div class="booking-addons-summary">
             <p><em>ไม่มีบริการเสริมที่เลือก</em></p>
        </div>
      <?php endif; ?>
      <hr style="margin: 1rem 0;">

      <?php
        $displayServiceValue = (float)($booking_to_display['total_price'] ?? 0);
        $displayActualDepositCollected = (float)($booking_to_display['deposit_amount'] ?? 0);
        $displayTotalPaid = (float)($booking_to_display['amount_paid'] ?? 0);
      ?>

      <p><strong>มูลค่าบริการรวม (ห้องพัก + บริการเสริม + ส่วนขยาย):</strong> <span id="displayed-total-service-price"><?= h(number_format(($displayServiceValue - $displayActualDepositCollected), 0)) ?></span> บาท</p>
      <?php if ($displayActualDepositCollected > 0): ?>
        <p><strong>ค่ามัดจำที่เก็บไว้:</strong> <?= h(number_format($displayActualDepositCollected, 0)) ?> บาท</p>
      <?php elseif ($bookingType === 'overnight' && ($booking_to_display['room_current_zone'] ?? $room['zone']) !== 'F'):
        // Check if room itself is configured to ask for deposit (relevant for non-Zone F overnight where deposit is standard but might be missing)
        $roomRequiresDeposit = isset($room['ask_deposit_on_overnight']) && $room['ask_deposit_on_overnight'] == 1;
        if ($roomRequiresDeposit || ($booking_to_display['room_current_zone'] ?? $room['zone']) !== 'F') { // Default to expecting deposit for Non-F overnight
            echo '<p><strong>ค่ามัดจำที่เก็บไว้:</strong> <span class="text-danger">0 บาท (ควรมีค่ามัดจำ)</span></p>';
        }
      ?>
      <?php elseif ($bookingType === 'overnight' && ($booking_to_display['room_current_zone'] ?? $room['zone']) === 'F' && $displayActualDepositCollected == 0): ?>
         <?php if (isset($room['ask_deposit_on_overnight']) && $room['ask_deposit_on_overnight'] == 1): ?>
            <p><strong>ค่ามัดจำที่เก็บไว้:</strong> <span class="text-danger">0 บาท (โซน F - แต่ตั้งค่าให้เก็บมัดจำ)</span></p>
         <?php else: ?>
            <p><strong>ค่ามัดจำที่เก็บไว้:</strong> <span class="text-muted">0 บาท (โซน F - ไม่ได้เลือกเก็บมัดจำ)</span></p>
         <?php endif; ?>
      <?php endif; ?>

      <p><strong>ยอดเรียกเก็บลูกค้ารวม:</strong> <span class="highlight-value"><?= h(number_format($displayServiceValue, 0)) ?></span> บาท</p>
      <p><strong>ยอดชำระแล้วทั้งหมด:</strong> <span id="current-amount-paid-display" class="highlight-value"><?= h(number_format($displayTotalPaid, 0)) ?></span> บาท</p>

      <?php // ***** START: แก้ไขการแสดงผลสลิป ***** ?>
      <?php if (!empty($all_group_receipts_for_display)): ?>
        <h4 style="margin-top: 15px; margin-bottom: 5px;">หลักฐานการชำระเงินของกลุ่ม:</h4>
        <?php foreach ($all_group_receipts_for_display as $grcpt): ?>
            <div class="receipt-actions" style="margin-top: 5px; margin-bottom:5px;">
              <button class="button-small receipt-btn" data-src="/hotel_booking/uploads/receipts/<?= h($grcpt['receipt_path']) ?>">
                <?= (empty(trim($grcpt['description'])) ? 'ดูสลิป' : h($grcpt['description'])) ?>
              </button>
            </div>
        <?php endforeach; ?>
      <?php elseif (!empty($booking_to_display['receipt_path']) || !empty($booking_to_display['extended_receipt_path'])): // Fallback สำหรับข้อมูลเก่า ?>
        <?php if (!empty($booking_to_display['receipt_path'])): ?>
            <div class="receipt-actions" style="margin-top: 10px; margin-bottom:5px;">
              <button class="button-small receipt-btn" data-src="/hotel_booking/uploads/receipts/<?= h($booking_to_display['receipt_path']) ?>">สลิปหลัก</button>
            </div>
        <?php endif; ?>
        <?php if (!empty($booking_to_display['extended_receipt_path'])): ?>
            <div class="receipt-actions" style="margin-top: 5px; margin-bottom:15px;">
              <button class="button-small receipt-btn" data-src="/hotel_booking/uploads/receipts/<?= h($booking_to_display['extended_receipt_path']) ?>">สลิปส่วนขยาย/ปรับยอด</button>
            </div>
        <?php endif; ?>
      <?php endif; ?>
      <?php // ***** END: แก้ไขการแสดงผลสลิป ***** ?>

      <p><strong>สร้างโดย:</strong> <?= h($booking_to_display['creator_username'] ?? 'N/A') ?></p>
      <?php if (isset($booking_to_display['last_modifier_username']) && $booking_to_display['last_modifier_username'] !== $booking_to_display['creator_username']): ?>
          <p><strong>แก้ไขล่าสุดโดย:</strong> <?= h($booking_to_display['last_modifier_username']) ?></p>
      <?php endif; ?>

      <div class="button-group stack-on-mobile" style="margin-top: 15px;">
          <?php
            if ($show_occupy_button_for_booking_id):
                $button_text = $can_early_checkin_after_14 ? 'เช็คอิน (ก่อนเวลาที่กำหนด)' : 'ดำเนินการเช็คอิน';
            ?>
              <button id="occupy-btn-in-modal-<?= h($show_occupy_button_for_booking_id) ?>" class="button occupy-btn alert" data-action="occupy" data-booking-id="<?= h($show_occupy_button_for_booking_id) ?>"><?= h($button_text) ?></button>
            <?php endif; ?>

            <?php
            // --- START: Logic for Main and Modify Action Buttons ---
            $canShowCheckoutAndExtendActions = false;
            $canShowEditMainBookingAction = false;
            $canShowCancelBookingAction = false;

            if ($booking_to_display) { // This block is now inside `if ($booking_to_display && !$isAdvanceBookingPrimaryDisplay)`
                $bookingIdForActions = $booking_to_display['id'];

                if ($isEffectivelyOverdue || $activeBooking) {
                    $canShowCheckoutAndExtendActions = true;
                    $canShowEditMainBookingAction = true;
                    $canShowCancelBookingAction = true;
                } elseif ($isPendingToday) {
                    $canShowCheckoutAndExtendActions = false;
                    $canShowEditMainBookingAction = true;
                    $canShowCancelBookingAction = true;
                }
                // $isAdvanceBookingPrimaryDisplay case is handled by the outer condition, so it's false here.
            }


            if ($canShowCheckoutAndExtendActions && isset($bookingIdForActions)):
                $actualDepositCollectedForActions = (float)($booking_to_display['deposit_amount'] ?? 0);
                $showDepositProofForm = true;
                $roomZoneForButtonLogic = $booking_to_display['room_current_zone'] ?? $room['zone'];
                $bookingTypeForButtonLogic = $booking_to_display['booking_type'] ?? 'overnight';

                $completeButtonText = "คืนมัดจำ & ดําเนินการเช็คเอาท์";
                if ($actualDepositCollectedForActions == 0) { $completeButtonText = "ดำเนินการเช็คเอาท์ (ไม่มีมัดจำ)"; $showDepositProofForm = false; }
                elseif ($roomZoneForButtonLogic === 'F' && $bookingTypeForButtonLogic === 'short_stay') { $completeButtonText = "ดำเนินการเช็คเอาท์ (โซน F ชั่วคราว)"; $showDepositProofForm = false; }
                elseif ($bookingTypeForButtonLogic === 'short_stay' && $actualDepositCollectedForActions == 0) { $completeButtonText = "ดำเนินการเช็คเอาท์ (ชั่วคราว)"; $showDepositProofForm = false; }
            ?>
              <button id="return-deposit-btn" class="button secondary"><?= h($completeButtonText) ?></button>
              <button id="show-extend-stay-form-btn" class="button info" data-booking-id="<?= h($bookingIdForActions) ?>">เพิ่มชั่วโมง/คืน</button>
              <button id="show-edit-booking-details-btn" class="button warning" data-booking-id="<?= h($bookingIdForActions) ?>">แก้ไขหมายเหตุ/ปรับยอด</button>
              
              <?php // ***** START: โค้ดที่เพิ่มเข้ามา ***** ?>
              <button type="button" class="button outline-secondary show-move-room-modal-btn"
                      data-booking-id="<?= h($bookingIdForActions) ?>"
                      data-current-room-id="<?= h($room['id']) ?>"
                      data-customer-name="<?= h($booking_to_display['customer_name']) ?>">
                  <img src="/hotel_booking/assets/image/move_room.png" alt="Move" style="width:16px; height:16px; margin-right:5px; vertical-align:middle;">
                  ย้ายห้อง
              </button>
              <?php // ***** END: โค้ดที่เพิ่มเข้ามา ***** ?>
              
            <?php endif; ?>

            <?php if ($canShowEditMainBookingAction && isset($bookingIdForActions)): ?>
                <a href="/hotel_booking/pages/booking.php?edit_booking_id=<?= h($bookingIdForActions) ?>" class="button primary" title="แก้ไขข้อมูลการจองหลัก เช่น ลูกค้า, จำนวนคืน, บริการเสริม">แก้ไขการจองหลัก</a>
            <?php endif; ?>

            <?php if ($canShowCancelBookingAction && isset($bookingIdForActions)): ?>
                <button class="button-small delete-booking-btn alert" data-booking-id="<?= h($bookingIdForActions) ?>" id="delete-current-booking-dtl-<?=h($bookingIdForActions)?>">ยกเลิกการจอง</button>
            <?php endif; ?>

            <?php if ($canShowCheckoutAndExtendActions && isset($bookingIdForActions)): ?>
              <div id="return-deposit-form" style="display:none; margin-top:10px; padding:10px; border:1px solid var(--color-border); border-radius:var(--border-radius-md); background-color: #f8f9fa; width:100%;">
                <?php
                    if ($showDepositProofForm && $actualDepositCollectedForActions > 0):
                ?>
                    <h4>กรอกข้อมูลเพื่อดำเนินการคืนมัดจำ</h4>
                    <label for="deposit-proof">หลักฐานการคืนมัดจำ (สำหรับยอดมัดจำ <?=h(number_format($actualDepositCollectedForActions,0))?> บาท):</label>
                    <input type="file" id="deposit-proof" name="deposit_proof" accept="image/*,application/pdf" required style="margin-bottom:10px; width:100%;" />
                    <button id="submit-deposit" class="button alert complete-booking-btn" data-booking-id="<?= h($bookingIdForActions) ?>" data-booking-type="overnight_with_deposit_return">อัปโหลดและยืนยันการย้าย</button>
                    <hr style="margin: 1.5rem 0;">
                    <p style="text-align:center; margin-bottom:0.5rem;">หรือ</p>
                     <button id="complete-no-refund-action-btn" class="button warning"
                             style="width:100%;"
                             data-booking-id="<?= h($bookingIdForActions) ?>"
                             title="ดำเนินการเช็คเอาท์และย้ายไปประวัติ โดยไม่ทำการคืนเงินมัดจำ (เช่น กรณีลูกค้าผิดเงื่อนไข)">
                         ดำเนินการต่อ (ไม่คืนมัดจำ <?=h(number_format($actualDepositCollectedForActions,0))?> บาท)
                     </button>
                <?php else:
                    $noDepositReturnText = 'ยืนยันการดำเนินการเช็คเอาท์';
                    if (($booking_to_display['room_current_zone'] ?? $room['zone']) === 'F' && ($booking_to_display['booking_type'] ?? 'overnight') === 'short_stay') {
                         $noDepositReturnText = 'ยืนยันการย้ายการจองชั่วคราว (โซน F) และดำเนินการเช็คเอาท์';
                    } elseif (($booking_to_display['booking_type'] ?? 'overnight') === 'short_stay') {
                        $noDepositReturnText = 'ยืนยันการย้ายการจองชั่วคราว และดำเนินการเช็คเอาท์';
                    } elseif ($actualDepositCollectedForActions == 0) {
                        $noDepositReturnText = 'ยืนยันการย้ายการจอง (ไม่มีมัดจำ) และดำเนินการเช็คเอาท์';
                    }
                ?>
                     <p><?= $noDepositReturnText ?></p>
                     <button id="submit-deposit" class="button alert complete-booking-btn" data-booking-id="<?= h($bookingIdForActions) ?>" data-booking-type="no_deposit_return_needed"><?= (($booking_to_display['room_current_zone'] ?? $room['zone']) === 'F' && ($booking_to_display['booking_type'] ?? 'overnight') === 'short_stay' ? 'ยืนยัน (โซน F ชั่วคราว)' : 'ยืนยันการย้าย') ?></button>
                <?php endif; ?>
              </div>
            <?php endif; ?>
      </div> <?php // End button-group ?>

    <?php
    $bookingForExtendAndEditForms = $booking_to_display;
    if ($canShowCheckoutAndExtendActions && $bookingForExtendAndEditForms ):
    ?>
      <div id="extend-stay-form-container" style="display:none; margin-top:20px; padding:15px; border:1px solid var(--color-info); border-radius:var(--border-radius-md); background-color: #f0f9ff;">
        <?php /* Extend stay form content as before, it's shown based on $canShowCheckoutAndExtendActions */ ?>
        <h4>ขยายเวลาการเข้าพัก / เปลี่ยนเป็นค้างคืน</h4>
        <?php
            $hourly_rate_from_system_settings_details = (defined('HOURLY_RATE_DB') ? (float)HOURLY_RATE_DB : 100);
            // HOURLY_RATE constant should be used here if it's the effective one, or HOURLY_RATE_DB.
            // Assuming HOURLY_RATE is defined somewhere or get_system_setting_value is preferred.
            // For safety, let's use the function as it's more robust if bootstrap.php changes definitions.
            $_rate_val_temp = get_system_setting_value($pdo, 'hourly_extension_rate', 100);
            $hourly_rate_from_system_settings_details = is_numeric($_rate_val_temp) && (float)$_rate_val_temp > 0 ? (float)$_rate_val_temp : 100;


            $room_specific_hourly_rate_details = null;
            if (isset($bookingForExtendAndEditForms['price_per_hour_extension']) && $bookingForExtendAndEditForms['price_per_hour_extension'] !== null && (float)$bookingForExtendAndEditForms['price_per_hour_extension'] > 0) {
                $room_specific_hourly_rate_details = (float)$bookingForExtendAndEditForms['price_per_hour_extension'];
            } elseif (isset($room['price_per_hour_extension']) && $room['price_per_hour_extension'] !== null && (float)$room['price_per_hour_extension'] > 0) {
                $room_specific_hourly_rate_details = (float)$room['price_per_hour_extension'];
            }
            $room_price_per_hour_for_js_details = ($room_specific_hourly_rate_details !== null && $room_specific_hourly_rate_details > 0) ? $room_specific_hourly_rate_details : $hourly_rate_from_system_settings_details;

            $initial_short_stay_room_cost_for_js_details = 0;
            if ($bookingForExtendAndEditForms && $bookingForExtendAndEditForms['booking_type'] === 'short_stay') {
                $initial_short_stay_room_cost_for_js_details = (float)($bookingForExtendAndEditForms['total_price'] ?? 0) - $calculated_total_addon_cost_for_display - (float)($bookingForExtendAndEditForms['deposit_amount'] ?? 0);
            }
            $pricePerNightForExtendDetails = (int)round((float)($bookingForExtendAndEditForms['price_per_night'] ?? ($room['price_per_day'] ?? 0)));
        ?>
        <form id="extend-stay-form"
              data-current-room-zone="<?= h($bookingForExtendAndEditForms['room_current_zone'] ?? $room['zone']) ?>"
              data-room-hourly-rate="<?= h((int)round($room_price_per_hour_for_js_details)) ?>"
              data-room-overnight-price="<?= h((int)round((float)($room['price_per_day'] ?? 0))) ?>"
              data-room-ask-deposit-f="<?= h($room['ask_deposit_on_overnight'] ?? 0) ?>"
              data-initial-short-stay-room-cost="<?= h((int)round($initial_short_stay_room_cost_for_js_details)) ?>">
            <input type="hidden" name="booking_id_extend" value="<?= h($bookingForExtendAndEditForms['id']) ?>">
            <input type="hidden" id="js-current-total-price" value="<?= h((int)round((float)($bookingForExtendAndEditForms['total_price'] ?? 0))) ?>">
            <input type="hidden" id="js-current-price-per-night" value="<?= h($pricePerNightForExtendDetails) ?>">
            <input type="hidden" id="js-current-checkout-datetime-obj" value="<?= h($bookingForExtendAndEditForms['php_calculated_actual_checkout_datetime_str_for_js'] ?? '') ?>">
            <input type="hidden" id="js-current-booking-type" value="<?= h($bookingForExtendAndEditForms['booking_type']) ?>">
            <input type="hidden" id="js-standard-checkout-time-str" value="<?= h(CHECKOUT_TIME_STR) ?>">
            <input type="hidden" id="js-fixed-deposit-amount" value="<?= h((int)FIXED_DEPOSIT_AMOUNT) ?>">
            <?php
                if (isset($bookingForExtendAndEditForms['checkin_datetime'])) echo '<input type="hidden" id="js-current-checkin" value="' . h($bookingForExtendAndEditForms['checkin_datetime']) . '">';
                if (isset($bookingForExtendAndEditForms['booking_type']) && $bookingForExtendAndEditForms['booking_type'] == 'overnight' && isset($bookingForExtendAndEditForms['nights'])) echo '<input type="hidden" id="js-current-nights" value="' . h($bookingForExtendAndEditForms['nights']) . '">';
                if (isset($bookingForExtendAndEditForms['extended_hours'])) echo '<input type="hidden" id="js-current-extended-hours" value="' . h($bookingForExtendAndEditForms['extended_hours'] ?? 0) . '">';
                if (isset($bookingForExtendAndEditForms['booking_type']) && $bookingForExtendAndEditForms['booking_type'] === 'short_stay') {
                    echo '<input type="hidden" id="js-current-short-stay-duration-hours" value="' . h($bookingForExtendAndEditForms['room_short_stay_duration'] ?? DEFAULT_SHORT_STAY_DURATION_HOURS) . '">';
                }
            ?>
            <div class="form-group">
                <label for="extend_type">ประเภทการดำเนินการ:</label>
                <select name="extend_type" id="extend_type" class="form-control">
                    <option value="hours">เพิ่มชั่วโมง (ราคา: <span id="hourly_rate_display_extend_val"><?=h((int)round($room_price_per_hour_for_js_details))?></span> บ./ชม.)</option>
                    <?php if (isset($bookingForExtendAndEditForms['booking_type']) && $bookingForExtendAndEditForms['booking_type'] === 'overnight'): ?>
                    <option value="nights">เพิ่มคืน (ราคาต่อคืน: <span id="price_per_night_display_extend_val"><?= h($pricePerNightForExtendDetails) ?></span> บ.)</option>
                    <?php endif; ?>
                    <?php if ($bookingForExtendAndEditForms && ($bookingForExtendAndEditForms['booking_type'] ?? '') === 'short_stay' && ($bookingForExtendAndEditForms['room_current_zone'] ?? $room['zone']) === 'F'): ?>
                    <option value="upgrade_to_overnight">เปลี่ยนเป็นค้างคืน (โซน F ยอดรวมค่าห้อง <?=h((int)round((float)($room['price_per_day'] ?? 0)))?> บ.)</option>
                    <?php endif; ?>
                </select>
            </div>
            <div class="form-group" id="extend_hours_group">
                <label for="extend_hours">จำนวนชั่วโมงที่เพิ่ม:</label>
                <input type="number" name="extend_hours" id="extend_hours" min="1" value="1" class="form-control">
            </div>
            <div class="form-group" id="extend_nights_group" style="<?= (isset($bookingForExtendAndEditForms['booking_type']) && $bookingForExtendAndEditForms['booking_type'] !== 'overnight') ? 'display:none;' : '' ?>">
                <label for="extend_nights">จำนวนคืนที่เพิ่ม:</label>
                <input type="number" name="extend_nights" id="extend_nights" min="1" value="1" class="form-control">
            </div>
            <?php // ***** START: MODIFIED PAYMENT DETAILS SECTION ***** ?>
            <div class="form-group">
                <p><strong>ค่าบริการส่วนเพิ่ม/ส่วนต่างที่ต้องชำระ (สำหรับรายการขยายนี้):</strong> <span id="additional_cost_display">0</span> บาท</p>
                <p><strong>ระยะเวลาที่จะขยายเพิ่ม:</strong> <span id="extension_duration_details_display" style="font-weight:bold; color: var(--color-info-dark);">-</span></p>
                <p><strong>เช็คเอาต์ใหม่โดยประมาณ:</strong> <span id="new_checkout_time_display" style="font-weight:bold; color:var(--color-primary-dark);"><?= h($bookingForExtendAndEditForms['current_checkout_display_with_ext'] ?? 'N/A') ?></span></p>
                <hr style="margin: 0.5rem 0;">
                <p><strong>มูลค่าการจองใหม่ทั้งหมด (หลังการขยาย):</strong> <span id="new_total_amount_display"><?= h((int)round((float)($bookingForExtendAndEditForms['total_price'] ?? 0))) ?></span> บาท</p>
                <p style="margin-top: 0.5rem;"><strong>ยอดชำระแล้วทั้งหมด (เดิม):</strong> <span id="current_paid_for_extend_display" style="color: var(--color-text-muted);"><?= h(number_format((float)($bookingForExtendAndEditForms['amount_paid'] ?? 0), 0)) ?></span> บาท</p>
                <p style="font-size: 1.1em;"><strong>ยอดที่ต้องเรียกเก็บจากลูกค้า (สำหรับการดำเนินการนี้):</strong> <strong id="payment_due_for_extension_display" style="color: var(--color-success-text); background-color: var(--color-success-bg); padding: 3px 6px; border-radius: var(--border-radius-sm);">0</strong> บาท</p>
            </div>
            <?php // ***** END: MODIFIED PAYMENT DETAILS SECTION ***** ?>
            <div class="form-group">
                <label for="extend_payment_method">วิธีการชำระเงิน (สำหรับส่วนเพิ่ม/ส่วนต่าง):</label>
                <select name="extend_payment_method" id="extend_payment_method" class="form-control" required>
                    <option value="เงินสด">เงินสด</option>
                    <option value="เงินโอน">เงินโอน</option>
                    <option value="บัตรเครดิต">บัตรเครดิต</option>
                    <option value="อื่นๆ">อื่นๆ</option>
                </select>
            </div>
             <div class="form-group">
                <label for="extend_receipt">หลักฐานการชำระ (ส่วนที่เพิ่ม ถ้ามี):</label>
                <input type="file" name="extend_receipt" id="extend_receipt" accept="image/*,application/pdf">
            </div>
            <div class="button-group stack-on-mobile">
                <button type="button" id="submit-extend-stay-btn" class="button primary">ยืนยันการดำเนินการ</button>
                <button type="button" id="cancel-extend-stay-btn" class="button outline-primary close-modal-btn">ยกเลิก</button>
            </div>
        </form>
      </div>

      <div id="edit-booking-details-form-container" style="display:none; margin-top:20px; padding:15px; border:1px solid var(--color-warning); border-radius:var(--border-radius-md); background-color: #fffbeb;">
        <?php /* Edit booking details form content as before */ ?>
         <h4>แก้ไขหมายเหตุ และ/หรือ ปรับยอดชำระ</h4>
        <?php
            $editFormServiceValueDetails = (int)round((float)($bookingForExtendAndEditForms['total_price'] ?? 0));
            $editFormRoomCostDetails = 0;
            if (isset($bookingForExtendAndEditForms['booking_type']) && $bookingForExtendAndEditForms['booking_type'] === 'short_stay') {
                // For short stay, original room cost is total - addons - deposit
                $original_deposit_edit = (float)($bookingForExtendAndEditForms['deposit_amount'] ?? 0);
                $editFormRoomCostDetails = (float)($bookingForExtendAndEditForms['total_price'] ?? 0) - $calculated_total_addon_cost_for_display - $original_deposit_edit;
            } else { // overnight
                $editFormRoomCostDetails = (float)($bookingForExtendAndEditForms['price_per_night'] ?? 0) * (int)($bookingForExtendAndEditForms['nights'] ?? 1);
            }
            $editFormTotalPaidDetails = (int)round((float)($bookingForExtendAndEditForms['amount_paid'] ?? 0));
            $editFormActualDepositDetails = (int)round((float)($bookingForExtendAndEditForms['deposit_amount'] ?? 0));
        ?>
        <form id="edit-booking-details-form">
            <input type="hidden" name="booking_id_edit_details" value="<?= h($bookingForExtendAndEditForms['id']) ?>">
            <input type="hidden" id="js-edit-initial-service-total-price" value="<?= h($editFormServiceValueDetails) ?>">
            <input type="hidden" id="js-edit-initial-room-cost" value="<?= h((int)round($editFormRoomCostDetails)) ?>">
            <input type="hidden" id="js-edit-initial-total-paid" value="<?= h($editFormTotalPaidDetails) ?>">
            <input type="hidden" id="js-edit-initial-booking-type" value="<?= h($bookingForExtendAndEditForms['booking_type']) ?>">
            <input type="hidden" id="js-edit-initial-deposit-amount" value="<?= h($editFormActualDepositDetails) ?>">
            <div class="form-group">
                <label for="edit_notes">หมายเหตุ:</label>
                <textarea name="edit_notes" id="edit_notes" rows="3" class="form-control" data-initial-value="<?= h($bookingForExtendAndEditForms['notes'] ?? '') ?>"><?= h($bookingForExtendAndEditForms['notes'] ?? '') ?></textarea>
            </div>
            <?php if (!empty($all_active_addons_for_modal_edit)): ?>
            <div class="form-group">
                <label>บริการเสริม (Add-ons):</label>
                <div id="edit-addon-chips-container-modal" class="addon-chips-flex-container" style="background-color: white; padding:10px; border-radius:var(--border-radius-sm);">
                    <?php foreach ($all_active_addons_for_modal_edit as $modal_addon):
                            $modal_addon_id_details = (int)$modal_addon['id'];
                            $current_quantity_for_this_addon_details = 1;
                            $is_checked_in_modal_details = false;
                            if(isset($current_booking_addons) && is_array($current_booking_addons)){
                                foreach($current_booking_addons as $cba_item){
                                    if(isset($cba_item['addon_service_id']) && (int)$cba_item['addon_service_id'] == $modal_addon_id_details){
                                        $is_checked_in_modal_details = true;
                                        $current_quantity_for_this_addon_details = (int)$cba_item['quantity'];
                                        break;
                                    }
                                }
                            }
                        ?>
                        <div class="addon-chip-wrapper <?= $is_checked_in_modal_details ? 'selected' : '' ?>">
                            <input type="checkbox"
                                   name="selected_addons_modal[<?= h($modal_addon_id_details) ?>][id]"
                                   value="<?= h($modal_addon_id_details) ?>"
                                   id="modal_addon_<?= h($modal_addon_id_details) ?>"
                                   data-price="<?= h((int)round((float)$modal_addon['price'])) ?>"
                                   class="addon-checkbox-modal"
                                   <?= $is_checked_in_modal_details ? 'checked' : '' ?>
                                   data-initial-checked="<?= $is_checked_in_modal_details ? 'true' : 'false' ?>"
                                   data-initial-quantity="<?= h($current_quantity_for_this_addon_details) ?>">
                            <label for="modal_addon_<?= h($modal_addon_id_details) ?>" class="addon-chip-label">
                                <?= h($modal_addon['name']) ?> (<?= h((int)round((float)$modal_addon['price'])) ?> บ.)
                            </label>
                            <input type="number"
                                   name="selected_addons_modal[<?= h($modal_addon_id_details) ?>][quantity]"
                                   value="<?= h($current_quantity_for_this_addon_details) ?>"
                                   min="1" step="1"
                                   class="addon-quantity-modal"
                                   data-addon-id="<?= h($modal_addon_id_details) ?>"
                                   style="width: 60px; margin-left: 5px; <?= !$is_checked_in_modal_details ? 'display:none;' : 'display:inline-block;' ?>"
                                   <?= !$is_checked_in_modal_details ? 'disabled' : '' ?>>
                        </div>
                    <?php endforeach; ?>
                </div>
                <p style="margin-top:5px;"><strong>ยอดบริการเสริม (ใหม่):</strong> <span id="modal-total-addon-price-display">0</span> บาท</p>
            </div>
            <?php endif; ?>
            <div class="form-group">
                <label for="adjustment_type">ประเภทการปรับยอด (การชำระเงิน/คืนเงิน เพิ่มเติม):</label>
                <select name="adjustment_type" id="adjustment_type" class="form-control">
                    <option value="none" selected>ไม่ปรับยอดชำระ</option>
                    <option value="add">ลูกค้าชำระเพิ่ม</option>
                    <option value="reduce">คืนเงินให้ลูกค้า</option>
                </select>
            </div>
            <div class="form-group" id="adjustment_amount_group" style="display:none;">
                <label for="adjustment_amount">จำนวนเงินที่ชำระเพิ่ม/คืนเงิน (บาท):</label>
                <input type="number" name="adjustment_amount" id="adjustment_amount" step="1" min="0" value="0" class="form-control">
            </div>
            <div class="form-group" id="adjustment_payment_method_group" style="display:none;">
                <label for="adjustment_payment_method">วิธีการชำระ/คืนเงิน:</label>
                <select name="adjustment_payment_method" id="adjustment_payment_method" class="form-control">
                    <option value="เงินสด">เงินสด</option>
                    <option value="เงินโอน">เงินโอน</option>
                    <option value="บัตรเครดิต">บัตรเครดิต (คืนยอด)</option>
                    <option value="อื่นๆ">อื่นๆ</option>
                </select>
            </div>
            <div class="form-group" id="adjustment_receipt_group" style="display:none;">
                <label for="adjustment_receipt">หลักฐานการชำระ/คืนเงิน (ถ้ามี):</label>
                <input type="file" name="adjustment_receipt" id="adjustment_receipt" accept="image/*,application/pdf">
            </div>
            <hr style="margin: 1rem 0;">
            <p><strong>ยอดที่ลูกค้าชำระแล้วทั้งหมด (เดิม):</strong> <span id="current_paid_for_edit_display"><?= h($editFormTotalPaidDetails) ?></span> บาท</p>
            <p><strong>มูลค่าบริการใหม่ทั้งหมด (ห้องพัก + บริการเสริมใหม่ <?= $editFormActualDepositDetails > 0 ? '+ มัดจำ '.h($editFormActualDepositDetails).' บ.' : ((isset($bookingForExtendAndEditForms['booking_type']) && $bookingForExtendAndEditForms['booking_type'] === 'overnight' && ($bookingForExtendAndEditForms['room_current_zone'] ?? $room['zone']) !== 'F' && (!isset($room['ask_deposit_on_overnight']) || $room['ask_deposit_on_overnight']==1)) ? '+ มัดจำที่ควรมี' : '') ?>):</strong> <span id="new_total_price_after_adjustment_display"><?= h($editFormServiceValueDetails) ?></span> บาท</p>
            <p><strong>ยอดที่ต้องดำเนินการสุทธิ:</strong> <span id="net_change_amount_display" style="font-weight:bold;">0</span></p>
            <div class="button-group stack-on-mobile">
                <button type="button" id="submit-edit-booking-details-btn" class="button primary">บันทึกการแก้ไข</button>
                <button type="button" id="cancel-edit-booking-details-btn" class="button outline-primary close-modal-btn">ยกเลิก</button>
            </div>
        </form>
      </div>
    <?php endif; ?>
    </section>
  <?php // ***** START: NEW ELSEIF BLOCK for $isAdvanceBookingPrimaryDisplay ***** ?>
  <?php elseif ($booking_to_display && $isAdvanceBookingPrimaryDisplay): ?>
    <section class="current-booking-details">
        <h4>สถานะห้องปัจจุบัน</h4>
        <p>ห้อง <?= h($room['zone'] . $room['room_number']) ?> นี้ <strong style="color: var(--color-secondary-dark);">ว่างอยู่ในปัจจุบัน</strong></p>
        <?php
            // We already have $booking_to_display which is the earliest future booking.
            // The $advanceBookings list contains OTHER future bookings for this room.
            $nextUpcomingBookingToDisplay = $booking_to_display; // This is the one that triggered $isAdvanceBookingPrimaryDisplay

            echo "<p>จะมีการเช็คอินครั้งถัดไปโดยคุณ <strong>" . h($nextUpcomingBookingToDisplay['customer_name']) . "</strong>";
            echo " ในวันที่ " . h($nextUpcomingBookingToDisplay['formatted_checkin']) . "</p>";
        ?>
        <p>รายละเอียดการจองล่วงหน้าทั้งหมดสำหรับห้องนี้ (รวมถึงรายการนี้) แสดงอยู่ด้านล่าง</p>
         <div class="button-group stack-on-mobile" style="margin-top: 15px;">
             <a href="/hotel_booking/pages/booking.php?edit_booking_id=<?= h($nextUpcomingBookingToDisplay['id']) ?>" class="button primary" title="แก้ไขข้อมูลการจองล่วงหน้านี้">แก้ไขการจองนี้</a>
             <button class="button-small delete-booking-btn alert" data-booking-id="<?= h($nextUpcomingBookingToDisplay['id']) ?>" id="delete-adv-booking-dtl-primary-<?=h($nextUpcomingBookingToDisplay['id'])?>">ยกเลิกการจองนี้</button>
        </div>
    </section>
  <?php // ***** END: NEW ELSEIF BLOCK ***** ?>
  <?php else: ?>
    <p style="padding:10px 0;">ห้องนี้ <strong style="color: var(--color-secondary-dark);">ว่าง</strong> และยังไม่มีการจองที่เกี่ยวข้องในปัจจุบัน, รอการเช็คอินสำหรับวันนี้, หรือข้อมูลการจองที่เกินกำหนด</p>
  <?php endif; ?>
  <?php // ***** END: MODIFIED CONDITION ***** ?>


  <?php
  // --- START: ตรรกะใหม่สำหรับแสดงปุ่ม "สร้างการจองใหม่" ---
  $showCreateBookingButton_details = false;
  if (isset($room['status']) && $room['status'] === 'free') {
    // ห้องต้องมีสถานะเป็น 'free' ในตาราง rooms
    // และต้องไม่มีการเข้าพักปัจจุบัน หรือรอเช็คอินวันนี้
    if (!$activeBooking && !$isPendingToday) {
        $showCreateBookingButton_details = true;
    }
  }

  if ($showCreateBookingButton_details): ?>
    <div class="button-group" style="margin-top: 15px; border-top: 1px dashed var(--color-border); padding-top: 15px;">
        <button class="button primary create-booking-btn" data-room-id="<?= h($room['id']) ?>">สร้างการจองใหม่สำหรับห้องนี้ (สำหรับช่วงเวลาที่ยังว่าง)</button>
    </div>
  <?php endif; ?>
  <?php // --- END: ตรรกะใหม่ --- ?>

  <?php if (!empty($advanceBookings)): ?>
    <section class="advance-booking-details" style="margin-top: 20px; padding-top: 15px; border-top: 1px solid #eee;">
        <h4>การจองล่วงหน้าอื่นๆ สำหรับห้องนี้</h4>
        <div class="table-responsive">
            <table class="report-table advance-table-popup">
                <thead>
                    <tr>
                        <th>ผู้จอง</th>
                        <th>เบอร์โทร</th>
                        <th>เช็กอิน</th>
                        <th>เช็กเอาต์</th>
                        <th>ประเภท</th>
                        <th>ระยะเวลา</th>
                        <th>ยอดชำระ</th>
                        <th>หลักฐาน</th>
                        <th>ผู้สร้าง</th>
                        <th>ผู้แก้ไขล่าสุด</th>
                        <th>ดำเนินการ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($advanceBookings as $advBooking): ?>
                        <?php $advBookingType = $advBooking['booking_type'] ?? 'overnight'; ?>
                        <tr>
                            <td><?= h($advBooking['customer_name']) ?></td>
                            <td>
                                <?php if (!empty($advBooking['customer_phone'])): ?>
                                    <a href="tel:<?= h(preg_replace('/[^0-9+]/', '', $advBooking['customer_phone'])) ?>" class="link-like"><?= h($advBooking['customer_phone']) ?></a>
                                <?php else: echo '-'; endif; ?>
                            </td>
                            <td><?= h($advBooking['formatted_checkin']) ?></td>
                            <td><?= h($advBooking['formatted_checkout']) ?></td>
                            <td><?= h($advBookingType === 'short_stay' ? 'ชั่วคราว' : 'ค้างคืน') ?></td>
                            <td style="text-align:center;"><?= h($advBookingType === 'short_stay' ? (($advBooking['short_stay_duration_hours'] ?? DEFAULT_SHORT_STAY_DURATION_HOURS) . ' ชม.') : (($advBooking['nights'] ?? 'N/A') . ' คืน')) ?></td>
                            <td style="text-align:right;"><?= h(number_format((float)($advBooking['amount_paid'] ?? 0), 0)) ?></td>
                            <td>
                                <?php if (!empty($advBooking['receipt_path'])): ?>
                                  <button class="button-small receipt-btn" data-src="/hotel_booking/uploads/receipts/<?= h($advBooking['receipt_path']) ?>">ดูสลิป</button>
                                <?php else: ?>
                                  <span class="text-muted"><em>ไม่มี</em></span>
                                <?php endif; ?>
                            </td>
                            <td><?= h($advBooking['creator_username'] ?? 'N/A') ?></td>
                            <td>
                                <?php if (isset($advBooking['last_modifier_username']) && $advBooking['last_modifier_username'] !== $advBooking['creator_username']): ?>
                                    <?= h($advBooking['last_modifier_username']) ?>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                            <td class="actions-cell">
                                <a href="/hotel_booking/pages/booking.php?edit_booking_id=<?= h($advBooking['booking_id']) ?>" class="button-small edit-booking-btn info">แก้ไข</a>
                                <button class="button-small delete-booking-btn alert" data-booking-id="<?= h($advBooking['booking_id']) ?>" id="delete-adv-booking-dtl-<?=h($advBooking['booking_id'])?>">ลบ</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
  <?php // ***** START: MODIFIED CONDITION FOR "NO ADVANCE BOOKINGS" MESSAGE ***** ?>
  <?php elseif (!($booking_to_display && $isAdvanceBookingPrimaryDisplay) && !$activeBooking && !$isEffectivelyOverdue && !$isPendingToday ): ?>
    <p style="margin-top:10px;"><em>ไม่มีการจองล่วงหน้าสำหรับห้องนี้</em></p>
  <?php endif; ?>
  <?php // ***** END: MODIFIED CONDITION ***** ?>
</div>
<style>
    .room-status-free { color: var(--color-secondary-dark); font-weight: bold; }
    .room-status-booked { color: var(--color-warning-dark); font-weight: bold; }
    .room-status-occupied { color: var(--color-alert-dark); font-weight: bold; }
    .room-status-advance_booking { color: var(--color-info-dark); font-weight: bold; }
    .room-status-overdue_occupied { color: var(--color-danger, #dc3545); font-weight: bold; }

    .notes-display-box {
        white-space: pre-wrap;
        background-color: var(--color-muted-bg);
        padding: 8px 12px;
        border-radius: var(--border-radius-sm);
        display: block;
        border: 1px solid var(--color-border);
        max-height: 150px;
        overflow-y: auto;
    }
    .highlight-value { font-weight: bold; color: var(--color-primary-dark); }
    .link-like {
        color: var(--link-color, var(--color-primary));
        text-decoration: underline;
    }
    .addon-chips-flex-container {
        background-color: var(--color-surface);
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
    }
    .addon-chip-wrapper.selected {
        background-color: var(--color-primary-light, #e0efff);
        border-color: var(--color-primary, #007bff);
    }
    .addon-chip-label {
        cursor: pointer;
        margin-left: 4px;
        user-select: none;
    }
    .addon-checkbox-modal, .addon-quantity-modal {
        margin-right: 5px;
    }
</style>