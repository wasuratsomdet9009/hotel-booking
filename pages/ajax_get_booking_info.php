<?php
// FILEX: hotel_booking/pages/ajax_get_booking_info.php
require_once __DIR__ . '/../bootstrap.php'; // Defines CHECKOUT_TIME_STR etc.

$bookingId = isset($_GET['booking_id']) ? (int)$_GET['booking_id'] : 0;

if (!$bookingId) {
    echo '<p class="text-danger">ไม่พบรหัสการจอง</p>';
    exit;
}

// Fetch booking details along with room details for duration/type
$stmt = $pdo->prepare("
    SELECT
        b.id AS booking_id,
        b.customer_name,
        b.customer_phone,
        b.booking_type,
        b.checkin_datetime, /* Raw for formatting */
        DATE_FORMAT(b.checkin_datetime, '%e %b %Y, %H:%i น.') AS formatted_checkin,
        b.checkout_datetime_calculated, /* Use calculated checkout */
        DATE_FORMAT(b.checkout_datetime_calculated, '%e %b %Y, %H:%i น.') AS formatted_checkout_calc,
        b.nights,
        r.short_stay_duration_hours, /* Get from room table */
        b.amount_paid,
        b.payment_method,
        b.receipt_path,
        r.zone,
        r.room_number
    FROM bookings b
    JOIN rooms r ON b.room_id = r.id
    WHERE b.id = ?
");
$stmt->execute([$bookingId]);
$booking = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$booking) {
    echo '<p class="text-danger">ไม่พบข้อมูลการจองสำหรับ ID: ' . h($bookingId) . '</p>';
    exit;
}
?>
<div class="booking-confirmation-details">
    <h4>ยืนยันการเช็คอินสำหรับห้อง <?= h($booking['zone'] . $booking['room_number']) ?></h4>
    <p><strong>รหัสการจอง:</strong> <?= h($booking['booking_id']) ?></p>
    <p><strong>ชื่อผู้จอง:</strong> <?= h($booking['customer_name']) ?></p>
    <?php if (!empty($booking['customer_phone'])): ?>
        <p><strong>เบอร์โทรศัพท์:</strong> <a href="tel:<?= h(preg_replace('/[^0-9+]/', '', $booking['customer_phone'])) ?>" class="link-like"><?= h($booking['customer_phone']) ?></a></p>
    <?php endif; ?>
    <p><strong>ประเภทการจอง:</strong> <?= h($booking['booking_type'] === 'short_stay' ? 'ชั่วคราว' : 'ค้างคืน') ?></p>
    <p><strong>กำหนดเช็กอิน:</strong> <?= h($booking['formatted_checkin']) ?></p>
    <?php if ($booking['booking_type'] === 'overnight'): ?>
        <p><strong>จำนวนคืน:</strong> <?= h($booking['nights']) ?></p>
    <?php else: ?>
        <p><strong>ระยะเวลา:</strong> <?= h($booking['short_stay_duration_hours']) ?> ชั่วโมง</p>
    <?php endif; ?>
    <p><strong>กำหนดเช็กเอาต์:</strong> <?= h($booking['formatted_checkout_calc']) ?></p>
    <p><strong>ยอดชำระ (รวมมัดจำ ถ้ามี):</strong> <?= h(number_format((float)($booking['amount_paid'] ?? 0), 2)) ?> บาท</p>
    <p><strong>วิธีการชำระเงิน:</strong> <?= h($booking['payment_method']) ?></p>

    <?php if (!empty($booking['receipt_path'])): ?>
        <div class="receipt-preview-inline" style="margin-top:10px;">
            <strong>หลักฐานการชำระ:</strong><br>
            <img src="/hotel_booking/uploads/receipts/<?= h($booking['receipt_path']) ?>"
                 alt="หลักฐานการชำระ" style="max-width: 200px; max-height: 150px; border-radius: 4px; margin-top:5px; cursor:pointer;"
                 onclick="viewReceiptImage('/hotel_booking/uploads/receipts/<?= h($booking['receipt_path']) ?>')">
        </div>
    <?php else: ?>
        <p><em>ไม่มีหลักฐานการชำระเงินแนบมา</em></p>
    <?php endif; ?>
</div>

<script>
// This script is part of the AJAX response.
// viewReceiptImage should be globally available from main.js or layout.php
if (typeof viewReceiptImage !== 'function' && document.getElementById('image-modal')) { // Check if modal exists too
    window.viewReceiptImage = function(src) { // Define locally if not global, ensure it matches global def
        const imageModalGlobal = document.getElementById('image-modal');
        const modalImageGlobal = document.getElementById('modal-image');
        if (imageModalGlobal && modalImageGlobal) {
            modalImageGlobal.src = src;
            // Assuming showModal is globally available from main.js
            if(typeof showModal === 'function') showModal(imageModalGlobal);
            else imageModalGlobal.classList.add('show');
        } else {
            window.open(src, '_blank');
        }
    }
}
</script>