<?php
// FILEX: hotel_booking/pages/edit_booking_group.php
require_once __DIR__ . '/../bootstrap.php';
require_login();

$bookingGroupId = isset($_GET['booking_group_id']) ? (int)$_GET['booking_group_id'] : 0;
if (!$bookingGroupId) {
    set_error_message('ไม่พบรหัสกลุ่มการจอง');
    header('Location: ' . DASHBOARD_PAGE);
    exit;
}

// 1. Fetch Group Details
$stmtGroup = $pdo->prepare("SELECT * FROM booking_groups WHERE id = ?");
$stmtGroup->execute([$bookingGroupId]);
$groupData = $stmtGroup->fetch(PDO::FETCH_ASSOC);

if (!$groupData) {
    set_error_message('ไม่พบข้อมูลกลุ่มการจอง ID: ' . h($bookingGroupId));
    header('Location: ' . DASHBOARD_PAGE);
    exit;
}

// 2. Fetch Associated Bookings in this Group (Modified to include amount_paid)
$stmtBookings = $pdo->prepare("
    SELECT b.id, b.checkin_datetime, b.checkout_datetime_calculated, b.total_price, b.amount_paid, r.zone, r.room_number
    FROM bookings b
    JOIN rooms r ON b.room_id = r.id
    WHERE b.booking_group_id = ?
    ORDER BY r.zone, CAST(r.room_number AS UNSIGNED)
");
$stmtBookings->execute([$bookingGroupId]);
$associatedBookings = $stmtBookings->fetchAll(PDO::FETCH_ASSOC);

// 3. Fetch Receipts for this Group
$stmtReceipts = $pdo->prepare("SELECT * FROM booking_group_receipts WHERE booking_group_id = ? ORDER BY uploaded_at DESC");
$stmtReceipts->execute([$bookingGroupId]);
$groupReceipts = $stmtReceipts->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'แก้ไขการจองกลุ่ม: ' . h($groupData['customer_name']);

ob_start();
?>

<div class="booking-header">
    <h2><?= h($pageTitle) ?> (Group ID: <?= h($groupData['id']) ?>)</h2>
    <p class="text-muted">หน้านี้สำหรับการแก้ไขข้อมูลโดยรวมของกลุ่ม เช่น ชื่อผู้จอง, เบอร์โทร, หมายเหตุ และจัดการสลิปทั้งหมดของกลุ่ม</p>
</div>

<form id="edit-group-form" enctype="multipart/form-data">
    <input type="hidden" name="action" value="update_booking_group">
    <input type="hidden" name="booking_group_id" value="<?= h($groupData['id']) ?>">

    <section class="settings-section">
        <h3><i class="fas fa-users"></i> ข้อมูลหลักของกลุ่ม</h3>
        <div class="form-grid" style="grid-template-columns: 1fr 1fr;">
            <div class="form-group">
                <label for="group_customer_name">ชื่อผู้จองหลัก:</label>
                <input type="text" id="group_customer_name" name="customer_name" value="<?= h($groupData['customer_name']) ?>" class="form-control" required>
            </div>
            <div class="form-group">
                <label for="group_customer_phone">เบอร์โทรศัพท์ติดต่อ:</label>
                <input type="tel" id="group_customer_phone" name="customer_phone" value="<?= h($groupData['customer_phone']) ?>" class="form-control">
            </div>
        </div>
        <div class="form-group">
            <label for="group_notes">หมายเหตุของกลุ่ม:</label>
            <textarea id="group_notes" name="notes" rows="3" class="form-control"><?= h($groupData['notes']) ?></textarea>
        </div>
    </section>

    <section class="settings-section">
        <h3><i class="fas fa-receipt"></i> จัดการสลิปของกลุ่ม</h3>
        <div class="form-group">
            <label for="new_receipt_files" class="stylish-upload-label">
                <div class="upload-icon-area">
                    <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="currentColor" class="upload-icon"><path d="M19.35 10.04C18.67 6.59 15.64 4 12 4 9.11 4 6.6 5.64 5.35 8.04 2.34 8.36 0 10.91 0 14c0 3.31 2.69 6 6 6h13c2.76 0 5-2.24 5-5 0-2.64-2.05-4.78-4.65-4.96zM14 13v4h-4v-4H7l5-5 5 5h-3z"/></svg>
                </div>
                <span class="upload-main-text">เพิ่มไฟล์สลิปใหม่ให้กลุ่มนี้</span>
                <span class="upload-sub-text">(เลือกได้หลายไฟล์, รองรับรูปภาพและ PDF)</span>
                <input type="file" name="new_receipt_files[]" id="new_receipt_files" accept="image/*,application/pdf" multiple style="display:none;">
            </label>
            <div id="new-filenames-display" class="filenames-display-area"></div>
        </div>

        <h4>สลิปที่มีอยู่แล้วในกลุ่มนี้:</h4>
        <div id="existing-receipts-list" class="table-responsive">
            <table class="report-table">
                <thead><tr><th>ไฟล์</th><th>คำอธิบาย</th><th>การดำเนินการ</th></tr></thead>
                <tbody>
                <?php if (empty($groupReceipts)): ?>
                    <tr><td colspan="3" class="text-center text-muted"><em>ไม่มีสลิปในกลุ่มนี้</em></td></tr>
                <?php else: ?>
                    <?php foreach ($groupReceipts as $receipt): ?>
                        <tr id="receipt-row-<?= h($receipt['id']) ?>">
                            <td><a href="/hotel_booking/uploads/receipts/<?= h($receipt['receipt_path']) ?>" target="_blank" class="link-like"><?= h($receipt['receipt_path']) ?></a></td>
                            <td><?= h($receipt['description'] ?: '-') ?></td>
                            <td class="text-center">
                                <button type="button" class="button-small alert delete-receipt-btn" data-receipt-id="<?= h($receipt['id']) ?>">ลบ</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <div class="button-group" style="justify-content: center; padding-top: 1rem; margin-top: 1rem; border-top: 2px solid var(--color-primary);">
        <button type="submit" id="save-group-changes-btn" class="button primary" style="font-size: 1.1rem; padding: 0.8rem 2rem;">
            <i class="fas fa-save" style="margin-right: 8px;"></i>บันทึกการเปลี่ยนแปลงข้อมูลกลุ่ม
        </button>
        <a href="/hotel_booking/pages/index.php" class="button outline-secondary">กลับหน้าหลัก</a>
    </div>
</form>

<!-- START: Modified Section for Feature #1 -->
<section class="settings-section" style="margin-top: 2rem;">
    <h3><i class="fas fa-bed"></i> การจองและยอดชำระรายห้องในกลุ่มนี้</h3>
    <div class="table-responsive">
        <table class="report-table" id="room-payment-table">
            <thead>
                <tr>
                    <th>ห้อง</th>
                    <th>เช็คอิน</th>
                    <th>เช็คเอาท์</th>
                    <th class="right-aligned">ราคาเฉพาะห้องนี้ (บาท)</th>
                    <th class="right-aligned">ยอดชำระของห้องนี้ (บาท)</th>
                    <th class="text-center">ดำเนินการ</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($associatedBookings as $booking): ?>
                    <tr>
                        <td><strong><?= h($booking['zone'] . $booking['room_number']) ?></strong></td>
                        <td><?= h(date('d/m/Y H:i', strtotime($booking['checkin_datetime']))) ?></td>
                        <td><?= h(date('d/m/Y H:i', strtotime($booking['checkout_datetime_calculated']))) ?></td>
                        <td class="right-aligned"><?= h(number_format((float)$booking['total_price'], 0)) ?></td>
                        <td class="right-aligned">
                            <input type="number" class="form-control form-control-sm amount-paid-input"
                                   value="<?= h(round((float)$booking['amount_paid'])) ?>"
                                   data-booking-id="<?= h($booking['id']) ?>"
                                   style="max-width: 120px; text-align: right; display: inline-block;">
                        </td>
                        <td class="text-center actions-cell">
                             <button type="button" class="button-small success save-payment-btn" data-booking-id="<?= h($booking['id']) ?>">บันทึกยอด</button>
                             <a href="booking.php?edit_booking_id=<?= h($booking['id']) ?>" class="button-small info">แก้ไขหลัก</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<!-- END: Modified Section for Feature #1 -->

<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('edit-group-form');
    const saveBtn = document.getElementById('save-group-changes-btn');
    const roomPaymentTable = document.getElementById('room-payment-table');


    // Helper function for loading state on buttons
    const originalButtonContents = {};
    function setButtonLoading(buttonElement, isLoading, buttonIdForTextStore) {
        if (!buttonElement) return;
        const key = buttonIdForTextStore || buttonElement.id || `btn-${Date.now()}-${Math.random()}`;
        if (isLoading) {
            if (!buttonElement.classList.contains('loading')) {
                if (originalButtonContents[key] === undefined) {
                    originalButtonContents[key] = buttonElement.innerHTML;
                }
                buttonElement.innerHTML = '<span class="spinner-sm"></span>';
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


    form.addEventListener('submit', async function(e) {
        e.preventDefault();
        setButtonLoading(saveBtn, true, 'save-group-changes-btn');

        const formData = new FormData(form);

        try {
            const response = await fetch('/hotel_booking/pages/api.php', {
                method: 'POST',
                body: formData
            });
            const result = await response.json();
            alert(result.message || 'เกิดข้อผิดพลาด');
            if (result.success) {
                location.reload();
            }
        } catch (error) {
            console.error('Error updating group:', error);
            alert('เกิดข้อผิดพลาดในการเชื่อมต่อ');
        } finally {
            setButtonLoading(saveBtn, false, 'save-group-changes-btn');
        }
    });

    document.getElementById('existing-receipts-list').addEventListener('click', async function(e) {
        if (e.target.classList.contains('delete-receipt-btn')) {
            const button = e.target;
            const receiptId = button.dataset.receiptId;
            
            if (!receiptId || !confirm('คุณแน่ใจหรือไม่ว่าต้องการลบสลิปนี้?')) return;

            setButtonLoading(button, true, `delete-receipt-${receiptId}`);

            try {
                const formData = new FormData();
                formData.append('action', 'update_booking_group');
                formData.append('sub_action', 'delete_receipt');
                formData.append('receipt_id', receiptId);

                const response = await fetch('/hotel_booking/pages/api.php', {
                    method: 'POST',
                    body: formData
                });
                const result = await response.json();
                alert(result.message || 'เกิดข้อผิดพลาด');
                if (result.success) {
                    const row = document.getElementById(`receipt-row-${receiptId}`);
                    if(row) row.remove();
                }
            } catch (error) {
                 console.error('Error deleting receipt:', error);
                 alert('เกิดข้อผิดพลาดในการเชื่อมต่อ');
            } finally {
                 setButtonLoading(button, false, `delete-receipt-${receiptId}`);
            }
        }
    });

    document.getElementById('new_receipt_files').addEventListener('change', function(e){
        const displayArea = document.getElementById('new-filenames-display');
        displayArea.innerHTML = '';
        if(e.target.files.length > 0) {
            let fileNames = Array.from(e.target.files).map(f => f.name).join(', ');
            displayArea.textContent = 'ไฟล์ที่เลือก: ' + fileNames;
        }
    });
    
    // START: JavaScript for Feature #1
    if (roomPaymentTable) {
        roomPaymentTable.addEventListener('click', async function(e) {
            if (e.target.classList.contains('save-payment-btn')) {
                const button = e.target;
                const bookingId = button.dataset.bookingId;
                const input = document.querySelector(`.amount-paid-input[data-booking-id="${bookingId}"]`);
                const newAmountPaid = input.value;

                if (newAmountPaid === null || newAmountPaid.trim() === '' || !/^-?\d+(\.\d+)?$/.test(newAmountPaid)) {
                    alert('กรุณากรอกยอดชำระเป็นตัวเลขที่ถูกต้อง');
                    input.focus();
                    return;
                }

                setButtonLoading(button, true, `save-payment-${bookingId}`);
                
                try {
                    const formData = new FormData();
                    formData.append('action', 'update_booking_payment');
                    formData.append('booking_id', bookingId);
                    formData.append('new_amount_paid', newAmountPaid);

                    const response = await fetch('/hotel_booking/pages/api.php', {
                        method: 'POST',
                        body: formData
                    });
                    
                    const resultText = await response.text();
                    try {
                        const result = JSON.parse(resultText);
                        if (result.success) {
                            // Visual feedback for success
                            const originalText = "บันทึกยอด";
                            button.innerHTML = 'บันทึกแล้ว!';
                            button.classList.remove('success');
                            button.classList.add('info');
                            setTimeout(() => {
                                button.innerHTML = originalText;
                                button.classList.remove('info');
                                button.classList.add('success');
                            }, 2500);
                        } else {
                             alert(result.message || 'เกิดข้อผิดพลาดในการบันทึก');
                        }
                    } catch(jsonError) {
                         console.error('JSON Parse Error:', jsonError);
                         console.error('Response Text:', resultText);
                         alert('เกิดข้อผิดพลาดในการประมวลผลการตอบกลับจากเซิร์ฟเวอร์');
                    }
                } catch (error) {
                    console.error('Error updating payment:', error);
                    alert('เกิดข้อผิดพลาดในการเชื่อมต่อ');
                } finally {
                    setButtonLoading(button, false, `save-payment-${bookingId}`);
                }
            }
        });
    }
    // END: JavaScript for Feature #1

});
</script>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../templates/layout.php';
?>
