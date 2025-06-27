<?php
// FILEX: hotel_booking/pages/ajax_get_booking_group_summary.php
// VERSION: 2.1 - Patched by System Auditor
// FIX: Reworked image export to produce consistent high-resolution output.

require_once __DIR__ . '/../bootstrap.php';

// --- REVISED PARAMETER HANDLING ---
$bookingGroupId = filter_input(INPUT_GET, 'booking_group_id', FILTER_VALIDATE_INT);
if (empty($bookingGroupId)) {
    echo '<p class="text-danger" style="padding:15px;">ไม่พบรหัสกลุ่มการจอง (Booking Group ID)</p>';
    exit;
}

// --- DATA QUERIES USING booking_group_id (Unchanged) ---
// 1. ดึงข้อมูลหลักของกลุ่มการจอง
$stmt_group_main = $pdo->prepare("
    SELECT bg.customer_name, bg.customer_phone, bg.notes, bg.created_at
    FROM booking_groups bg WHERE bg.id = :group_id
");
$stmt_group_main->execute([':group_id' => $bookingGroupId]);
$groupMainInfo = $stmt_group_main->fetch(PDO::FETCH_ASSOC);

if (!$groupMainInfo) {
    echo '<p class="text-danger" style="padding:15px;">ไม่พบข้อมูลหลักสำหรับกลุ่มการจองนี้</p>';
    exit;
}

// 2. ดึงข้อมูลห้องพักทั้งหมดในกลุ่ม
$stmt_group_rooms = $pdo->prepare("
    SELECT
        b.id as booking_id, r.zone, r.room_number, b.price_per_night, b.nights,
        b.booking_type, b.total_price AS booking_specific_total_price,
        COALESCE(r.short_stay_duration_hours, ".DEFAULT_SHORT_STAY_DURATION_HOURS.") as effective_short_stay_duration
    FROM bookings b JOIN rooms r ON b.room_id = r.id
    WHERE b.booking_group_id = :group_id
    ORDER BY r.zone ASC, CAST(r.room_number AS UNSIGNED) ASC
");
$stmt_group_rooms->execute([':group_id' => $bookingGroupId]);
$groupRooms = $stmt_group_rooms->fetchAll(PDO::FETCH_ASSOC);

// 3. ดึงข้อมูลบริการเสริมทั้งหมด
$stmt_group_addons = $pdo->prepare("
    SELECT aserv.name AS addon_name, ba.quantity, ba.price_at_booking
    FROM booking_addons ba
    JOIN addon_services aserv ON ba.addon_service_id = aserv.id
    JOIN bookings b ON ba.booking_id = b.id
    WHERE b.booking_group_id = :group_id ORDER BY aserv.name ASC
");
$stmt_group_addons->execute([':group_id' => $bookingGroupId]);
$groupAddons = $stmt_group_addons->fetchAll(PDO::FETCH_ASSOC);

// 4. ดึงข้อมูลสลิปทั้งหมด
$stmt_group_receipts = $pdo->prepare("
    SELECT receipt_path, description, amount, payment_method, uploaded_at
    FROM booking_group_receipts WHERE booking_group_id = :group_id
    ORDER BY uploaded_at ASC
");
$stmt_group_receipts->execute([':group_id' => $bookingGroupId]);
$groupReceipts = $stmt_group_receipts->fetchAll(PDO::FETCH_ASSOC);


// --- CALCULATIONS (Revised for new display structure) ---
$totalRoomCost = 0;
$totalAddonCost = 0;
$totalAmountPaid = 0;
$earliestCheckin = null;
$latestCheckout = null;

// ดึง checkin/checkout ที่เก่าสุดและใหม่สุดจากทุก booking ในกลุ่ม
$stmt_dates = $pdo->prepare("
    SELECT MIN(checkin_datetime) as earliest_checkin, MAX(checkout_datetime_calculated) as latest_checkout
    FROM bookings WHERE booking_group_id = :group_id
");
$stmt_dates->execute([':group_id' => $bookingGroupId]);
$dates = $stmt_dates->fetch(PDO::FETCH_ASSOC);
if ($dates) {
    $earliestCheckin = $dates['earliest_checkin'] ? new DateTime($dates['earliest_checkin']) : null;
    $latestCheckout = $dates['latest_checkout'] ? new DateTime($dates['latest_checkout']) : null;
}

// ***** START: โค้ดที่แก้ไข *****
// ดึงข้อมูลบริการเสริมทั้งหมดในกลุ่ม และจัดกลุ่มตาม booking_id
$stmt_all_addons_in_group = $pdo->prepare("
    SELECT b.id as booking_id, aserv.name AS addon_name, ba.quantity, ba.price_at_booking
    FROM booking_addons ba
    JOIN addon_services aserv ON ba.addon_service_id = aserv.id
    JOIN bookings b ON ba.booking_id = b.id
    WHERE b.booking_group_id = :group_id ORDER BY aserv.name ASC
");
$stmt_all_addons_in_group->execute([':group_id' => $bookingGroupId]);
$allAddonsInGroup = $stmt_all_addons_in_group->fetchAll(PDO::FETCH_ASSOC);

// สร้าง Map ของ Addon cost สำหรับแต่ละ booking_id
$addonCostsPerBooking = [];
foreach ($allAddonsInGroup as $addon) {
    $bookingIdKey = $addon['booking_id'];
    if (!isset($addonCostsPerBooking[$bookingIdKey])) {
        $addonCostsPerBooking[$bookingIdKey] = 0;
    }
    $addonCostsPerBooking[$bookingIdKey] += (float)$addon['price_at_booking'] * (int)$addon['quantity'];
}


// คำนวณยอดรวมค่าห้องพัก (แก้ไขส่วน short_stay)
foreach ($groupRooms as $room) {
    if ($room['booking_type'] === 'overnight') {
        $totalRoomCost += (float)$room['price_per_night'] * (int)$room['nights'];
    } else { // short_stay
        // สำหรับ short_stay, booking_specific_total_price คือราคารวมทุกอย่างของ booking นั้น
        // เราต้องหักค่า Addon ของ booking นั้นๆ ออก เพื่อให้ได้ค่าห้องที่แท้จริง
        $bookingIdForLookup = $room['booking_id'] ?? null; 
        $addonCostForThisBooking = isset($addonCostsPerBooking[$bookingIdForLookup]) ? $addonCostsPerBooking[$bookingIdForLookup] : 0;
        
        // ยอดค่าห้องที่แท้จริง = ยอดรวมของการจอง - ยอด addon ของการจองนั้น
        $actualRoomCostForShortStay = (float)$room['booking_specific_total_price'] - $addonCostForThisBooking;
        $totalRoomCost += $actualRoomCostForShortStay;
    }
}
// คำนวณยอดรวมบริการเสริม (ใช้ผลรวมที่คำนวณไว้แล้ว)
$totalAddonCost = array_sum($addonCostsPerBooking);
// ***** END: โค้ดที่แก้ไข *****


// คำนวณยอดชำระแล้ว
foreach ($groupReceipts as $receipt) {
    if (isset($receipt['amount']) && is_numeric($receipt['amount'])) {
        $totalAmountPaid += (float)$receipt['amount'];
    }
}

// ** สำคัญ: totalOverallPrice คือยอดรวมทั้งหมดที่ระบบคำนวณจากการจอง ไม่ใช่ผลรวมจากรายการที่แสดง **
// เราจะใช้ผลรวมจากรายการย่อยที่เราจะแสดงเพื่อความโปร่งใส
$grandTotal = $totalRoomCost + $totalAddonCost;
$outstandingBalance = $grandTotal - $totalAmountPaid;

// ฟังก์ชันสำหรับแสดงวันที่ภาษาไทย
function toThaiDate($date) {
    if (!$date) return 'N/A';
    $thaiMonths = ['ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
    return $date->format('j') . ' ' . $thaiMonths[$date->format('n') - 1] . ' ' . ($date->format('Y') + 543);
}

// โลโก้โรงแรม
$logo_path = '/hotel_booking/assets/image/logo_bill.png';
$logo_base64 = '';
if (file_exists(__DIR__ . '/..' . $logo_path)) {
    $logo_data = file_get_contents(__DIR__ . '/..' . $logo_path);
    $logo_base64 = 'data:image/png;base64,' . base64_encode($logo_data);
}

?>

<div id="booking-group-summary-content" style="font-family: 'Sarabun', sans-serif; padding: 25px; color: #333; background-color: #f4f7f6; border-radius: 8px; border: 1px solid #ddd; width: 100%; max-width: 800px; margin: auto;">

    <table style="width: 100%; border-bottom: 2px solid #0056b3; padding-bottom: 15px; margin-bottom: 20px;">
        <tr>
            <td style="width: 65%;">
                <?php if ($logo_base64): ?>
                    <img src="<?= $logo_base64 ?>" alt="Logo" style="max-width: 180px; max-height: 60px; object-fit: contain;">
                <?php endif; ?>
            </td>
            <td style="width: 35%; text-align: right; vertical-align: bottom;">
                <h2 style="margin: 0; color: #0056b3; font-size: 1.8rem;">สรุปการจอง</h2>
                <p style="margin: 0; font-size: 0.9rem;">Group ID: <?= h($bookingGroupId) ?></p>
            </td>
        </tr>
    </table>

    <table style="width: 100%; margin-bottom: 20px;">
        <tr>
            <td style="width: 60%; vertical-align: top;">
                <h4 style="margin: 0 0 5px 0; font-size: 1.1rem; color: #004080;">ข้อมูลผู้จอง:</h4>
                <p style="margin: 0 0 3px 0;"><strong>ชื่อ:</strong> <?= h($groupMainInfo['customer_name']) ?></p>
                <?php if (!empty($groupMainInfo['customer_phone'])): ?>
                    <p style="margin: 0;"><strong>โทร:</strong> <?= h($groupMainInfo['customer_phone']) ?></p>
                <?php endif; ?>
            </td>
            <td style="width: 40%; text-align: right; vertical-align: top;">
                <p style="margin: 0 0 3px 0;"><strong>วันที่จอง:</strong> <?= toThaiDate(new DateTime($groupMainInfo['created_at'])) ?></p>
                <p style="margin: 0 0 3px 0;"><strong>เช็คอิน:</strong> <?= $earliestCheckin ? toThaiDate($earliestCheckin) : 'N/A' ?></p>
                <p style="margin: 0;"><strong>เช็คเอาท์:</strong> <?= $latestCheckout ? toThaiDate($latestCheckout) : 'N/A' ?></p>
            </td>
        </tr>
    </table>
    
    <?php if (!empty(trim($groupMainInfo['notes']))): ?>
    <div style="margin-bottom: 20px;">
        <h4 style="margin: 0 0 5px 0; font-size: 1.1rem; color: #004080;">หมายเหตุ:</h4>
        <div style="white-space: pre-wrap; background-color: #fff; padding: 10px; border-radius: 5px; border: 1px solid #e0e0e0; font-size: 0.9rem;"><?= h(trim($groupMainInfo['notes'])) ?></div>
    </div>
    <?php endif; ?>

    <div>
        <h4 style="margin: 0 0 10px 0; font-size: 1.1rem; color: #004080; border-bottom: 1px solid #ccc; padding-bottom: 5px;">
            <i class="fas fa-bed" style="margin-right: 8px;"></i>รายละเอียดห้องพัก (<?= count($groupRooms) ?> ห้อง)
        </h4>
        <table style="width: 100%; border-collapse: collapse; font-size: 0.95rem;">
            <thead>
                <tr style="background-color: #e9ecef; color: #333;">
                    <th style="padding: 8px; text-align: left; border-bottom: 1px solid #ccc;">ห้อง</th>
                    <th style="padding: 8px; text-align: center; border-bottom: 1px solid #ccc;">ระยะเวลา</th>
                    <th style="padding: 8px; text-align: right; border-bottom: 1px solid #ccc;">ราคา (บาท)</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($groupRooms as $room): 
                    $roomBasePrice = ($room['booking_type'] === 'overnight') ? (float)$room['price_per_night'] * (int)$room['nights'] : ((float)$room['booking_specific_total_price'] - (isset($addonCostsPerBooking[$room['booking_id']]) ? $addonCostsPerBooking[$room['booking_id']] : 0));
                ?>
                <tr style="border-bottom: 1px solid #eef2f5;">
                    <td style="padding: 8px;"><?= h($room['zone'] . $room['room_number']) ?></td>
                    <td style="padding: 8px; text-align: center;"><?= h($room['booking_type'] === 'overnight' ? $room['nights'] . ' คืน' : $room['effective_short_stay_duration'] . ' ชม.') ?></td>
                    <td style="padding: 8px; text-align: right;"><?= h(number_format($roomBasePrice, 2)) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if (!empty($groupAddons)): ?>
    <div style="margin-top: 20px;">
        <h4 style="margin: 0 0 10px 0; font-size: 1.1rem; color: #004080; border-bottom: 1px solid #ccc; padding-bottom: 5px;">
            <i class="fas fa-concierge-bell" style="margin-right: 8px;"></i>บริการเสริม
        </h4>
        <table style="width: 100%; border-collapse: collapse; font-size: 0.95rem;">
            <tbody>
                <?php foreach ($groupAddons as $addon): ?>
                <tr style="border-bottom: 1px solid #eef2f5;">
                    <td style="padding: 8px;"><?= h($addon['addon_name']) ?> (x<?= h($addon['quantity']) ?>)</td>
                    <td style="padding: 8px; text-align: right;"><?= h(number_format((float)$addon['price_at_booking'] * (int)$addon['quantity'], 2)) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <?php if (!empty($groupReceipts)): ?>
    <div style="margin-top: 20px;">
        <h4 style="margin: 0 0 10px 0; font-size: 1.1rem; color: #004080; border-bottom: 1px solid #ccc; padding-bottom: 5px;">
            <i class="fas fa-receipt" style="margin-right: 8px;"></i>ประวัติการชำระเงิน
        </h4>
        <?php foreach ($groupReceipts as $receipt): ?>
            <div style="display: flex; align-items: center; padding: 8px; background-color: #fff; border-radius: 5px; margin-bottom: 8px; border: 1px solid #e0e0e0;">
                <img src="/hotel_booking/uploads/receipts/<?= h($receipt['receipt_path']) ?>" alt="สลิป" style="width: 60px; height: 60px; object-fit: cover; border-radius: 4px; margin-right: 15px;">
                <div style="flex-grow: 1; font-size: 0.9rem;">
                    <?php if (!empty($receipt['description'])): ?>
                        <strong><?= h($receipt['description']) ?></strong><br>
                    <?php endif; ?>
                    <small>วิธีชำระ: <?= h($receipt['payment_method'] ?? '-') ?> | อัปโหลด: <?= h(date('d/m/Y H:i', strtotime($receipt['uploaded_at']))) ?></small>
                </div>
                <div style="font-weight: bold; font-size: 1rem; color: #218838; text-align: right;">
                    <?= h(number_format((float)$receipt['amount'], 2)) ?> บาท
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
    
    <div style="margin-top: 25px; padding: 20px; background-color: #e6f7ff; border-radius: 8px; border: 1px solid #b3e0ff;">
        <table style="width: 100%; font-size: 1.1rem; line-height: 1.8;">
            <tr>
                <td>ยอดรวมค่าห้องพัก:</td>
                <td style="text-align: right;"><?= h(number_format($totalRoomCost, 2)) ?> บาท</td>
            </tr>
            <tr>
                <td>ยอดรวมค่าบริการเสริม:</td>
                <td style="text-align: right;"><?= h(number_format($totalAddonCost, 2)) ?> บาท</td>
            </tr>
            <tr style="font-weight: bold; border-top: 1px solid #99ccec; border-bottom: 1px solid #99ccec;">
                <td style="padding: 8px 0;">ยอดรวมสุทธิ:</td>
                <td style="padding: 8px 0; text-align: right;"><?= h(number_format($grandTotal, 2)) ?> บาท</td>
            </tr>
            <tr>
                <td style="color: #1e7e34;">ยอดชำระแล้ว:</td>
                <td style="text-align: right; color: #1e7e34;"><?= h(number_format($totalAmountPaid, 2)) ?> บาท</td>
            </tr>
            <tr style="font-weight: bold; font-size: 1.4rem; color: <?= $outstandingBalance > 0 ? '#c82333' : '#218838' ?>;">
                <td style="padding-top: 10px;">ยอดคงเหลือ:</td>
                <td style="padding-top: 10px; text-align: right;">
                    <?= h(number_format($outstandingBalance, 2)) ?> บาท
                </td>
            </tr>
        </table>
    </div>

</div>

<div class="button-group" style="margin-top: 25px; padding-top:20px; border-top:1px solid var(--color-border); text-align:right; display: flex; justify-content: flex-end; align-items: center; flex-wrap: wrap; gap: 0.5rem;">
    <a href="edit_booking_group.php?booking_group_id=<?= h($bookingGroupId) ?>" class="button primary">แก้ไขข้อมูลกลุ่มนี้</a>
    <a href="cash_bill.php?booking_group_id=<?= h($bookingGroupId) ?>" class="button secondary" target="_blank">
        <img src="/hotel_booking/assets/image/printer.png" alt="Bill" style="width:16px; height:16px; margin-right:5px; vertical-align:middle;">
        ออกบิลเงินสด
    </a>
    <button id="export-booking-summary-btn" class="button outline-secondary"><img src="/hotel_booking/assets/image/picture.png" alt="Export" style="width:16px; height:16px; margin-right:5px; vertical-align:middle;">Export เป็นรูปภาพ</button>
    <button id="share-booking-summary-btn" class="button info" style="display:none;"><img src="/hotel_booking/assets/image/share.png" alt="Share" style="width:16px; height:16px; margin-right:5px; vertical-align:middle;">แชร์</button>
</div>

<script>
if (typeof html2canvas !== 'function') {
    console.warn('[GroupSummaryModal] html2canvas library is not loaded. Image export will not work.');
    const exportBtnOnLoad = document.getElementById('export-booking-summary-btn');
    if(exportBtnOnLoad) exportBtnOnLoad.disabled = true;
}

const exportButton = document.getElementById('export-booking-summary-btn');
if (exportButton) {
    exportButton.addEventListener('click', async function() {
        const sourceElement = document.getElementById('booking-group-summary-content');
        if (!sourceElement || typeof html2canvas !== 'function') {
            // Replaced alert with console.error
            console.error('ไม่พบส่วนประกอบที่จำเป็นสำหรับ Export รูปภาพ');
            return;
        }

        const buttonId = this.id || 'export-booking-summary-btn';
        if (typeof setButtonLoading === 'function') setButtonLoading(this, true, buttonId);

        // 1. Create a clone for off-screen rendering
        const clone = sourceElement.cloneNode(true);
        
        // 2. Style the clone for fixed, high-quality rendering
        clone.style.position = 'absolute';
        clone.style.top = '-9999px';
        clone.style.left = '0px';
        clone.style.width = '800px'; // A4-like width
        clone.style.maxWidth = '800px';
        clone.style.height = 'auto';
        clone.style.margin = '0';
        clone.style.padding = '25px'; // Ensure padding is consistent

        document.body.appendChild(clone);

        try {
            // 3. Render the CLONE using html2canvas
            const canvas = await html2canvas(clone, {
                scale: 2, // Increase scale for better resolution
                useCORS: true,
                logging: true,
                width: clone.offsetWidth,
                height: clone.offsetHeight
            });

            const image = canvas.toDataURL('image/png', 1.0);
            
            // 4. Trigger download
            const link = document.createElement('a');
            const customerNameForFile = "<?= h(addslashes($groupMainInfo['customer_name'] ?? 'booking')) ?>";
            const safeCustomerName = customerNameForFile.replace(/[^a-z0-9]/gi, '_').toLowerCase() || 'booking_summary';
            link.download = `${safeCustomerName}_summary_${new Date().toISOString().slice(0,10)}.png`;
            link.href = image;
            link.click();

            // Enable share button
            const shareBtn = document.getElementById('share-booking-summary-btn');
            if(shareBtn) {
                shareBtn.style.display = 'inline-block';
                shareBtn.dataset.imageDataUrl = image;
                shareBtn.dataset.customerName = customerNameForFile;
            }

        } catch (err) {
            console.error('[Export Image] Error generating image with html2canvas:', err);
            // Replaced alert with console.error
            console.error('เกิดข้อผิดพลาดในการสร้างรูปภาพสรุป: ' + err.message);
        } finally {
            // 5. Clean up: remove the clone and restore button state
            document.body.removeChild(clone);
            if (typeof setButtonLoading === 'function') setButtonLoading(exportButton, false, buttonId);
        }
    });
}


const shareButton = document.getElementById('share-booking-summary-btn');
if (shareButton) {
    shareButton.addEventListener('click', async function() {
        const buttonId = this.id || 'share-booking-summary-btn';
        if (typeof setButtonLoading === 'function') {
            setButtonLoading(this, true, buttonId);
        } else {
            this.disabled = true;
            this.textContent = 'กำลังแชร์...';
        }

        const imageDataUrl = this.dataset.imageDataUrl;
        const customerName = this.dataset.customerName || 'ลูกค้า';
        const safeCustomerName = customerName.replace(/[^a-z0-9]/gi, '_').toLowerCase() || 'booking_summary';
        const fileName = `${safeCustomerName}_summary_${new Date().toISOString().slice(0,10)}.png`;

        if (!imageDataUrl) {
            // Replaced alert with console.warn
            console.warn('กรุณา Export รูปภาพออกมาก่อนทำการแชร์');
        } else if (navigator.share && typeof File === 'function') {
            try {
                const response = await fetch(imageDataUrl);
                const blob = await response.blob();
                const file = new File([blob], fileName, { type: 'image/png' });
                await navigator.share({
                    title: 'สรุปการจอง: ' + customerName,
                    text: 'ข้อมูลสรุปการจองสำหรับ ' + customerName,
                    files: [file]
                });
            } catch (error) {
                console.error('[Share Image] Share failed:', error);
                // Replaced alert with console.error
                console.error('การแชร์ไม่สำเร็จ: ' + error.message);
            }
        } else {
            // Replaced alert with console.warn
            console.warn('เบราว์เซอร์นี้ไม่รองรับการแชร์ไฟล์โดยตรง\nคุณสามารถดาวน์โหลดรูปภาพแล้วแชร์ด้วยตนเองได้ค่ะ');
        }
        
        if (typeof setButtonLoading === 'function') {
            setButtonLoading(shareButton, false, buttonId);
        } else {
            shareButton.disabled = false;
            shareButton.innerHTML = '<img src="/hotel_booking/assets/image/share.png" alt="Share" style="width:16px; height:16px; margin-right:5px; vertical-align:middle;">แชร์';
        }
    });
}
</script>
