<?php
// FILEX: hotel_booking/pages/cash_bill.php
// VERSION: 3.1 - Printing System Revamp by Senior System Auditor
// DESC: A completely redesigned cash bill generation system focusing on efficiency,
//       print/image export quality, and a flexible item-based workflow.
//       Uses the "Clone & Render" technique for perfect image exports on all devices.
//       MOD: Replaced window.print() with a new, robust print-window generation method.

require_once __DIR__ . '/../bootstrap.php';
require_login(); 

$pageTitle = 'ออกใบเสร็จรับเงิน/ต้นฉบับ';

// --- Data Fetching for UI Controls ---

// Fetch all rooms for manual item selection
$rooms_stmt = $pdo->query("SELECT id, zone, room_number, price_per_day FROM rooms ORDER BY zone ASC, CAST(room_number AS UNSIGNED) ASC");
$all_rooms_for_bill = $rooms_stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch active booking groups to pre-fill bill data
$active_groups_stmt = $pdo->query("
    SELECT DISTINCT bg.id, bg.customer_name 
    FROM booking_groups bg
    JOIN bookings b ON bg.id = b.booking_group_id
    ORDER BY bg.created_at DESC
");
$active_booking_groups = $active_groups_stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch active addon services for manual item selection
$addons_stmt = $pdo->query("SELECT id, name, price FROM addon_services WHERE is_active = 1 ORDER BY name ASC");
$active_addons_for_bill = $addons_stmt->fetchAll(PDO::FETCH_ASSOC);


// --- Utility Functions & Defaults ---
$current_thai_year = date('Y') + 543;
$default_bill_number_prefix = "01"; 

if (!function_exists('toThaiDateString')) {
    function toThaiDateString($dateInput) {
        if (empty($dateInput)) return 'N/A';
        try {
            $date = $dateInput instanceof DateTime ? $dateInput : new DateTime($dateInput);
            $thaiMonths = [
                1 => 'มกราคม', 2 => 'กุมภาพันธ์', 3 => 'มีนาคม', 4 => 'เมษายน',
                5 => 'พฤษภาคม', 6 => 'มิถุนายน', 7 => 'กรกฎาคม', 8 => 'สิงหาคม',
                9 => 'กันยายน', 10 => 'ตุลาคม', 11 => 'พฤศจิกายน', 12 => 'ธันวาคม'
            ];
            return $date->format('j') . ' ' . $thaiMonths[(int)$date->format('n')] . ' ' . ($date->format('Y') + 543);
        } catch (Exception $e) {
            return 'รูปแบบวันที่ผิดพลาด';
        }
    }
}

$logo_path = '/hotel_booking/assets/image/logo_bill.png'; 
if (!file_exists(__DIR__ . '/..' . $logo_path)) {
    $logo_path = ''; 
    error_log("Cash Bill Logo not found at: " . __DIR__ . '/..' . $logo_path);
}

ob_start();
?>

<style>
    /* General Styles */
    .cash-bill-container { max-width: 900px; margin: 0 auto; }
    .bill-form-section, .bill-preview-section {
        background-color: var(--color-surface); padding: 1.5rem;
        border-radius: var(--border-radius-lg); box-shadow: var(--shadow-md);
        margin-bottom: 2rem; border: 1px solid var(--color-border);
    }
    .bill-form-section h3, .bill-preview-section h3 {
        margin-top: 0; color: var(--color-primary-dark);
        padding-bottom: 0.75rem; border-bottom: 1px solid var(--color-border);
    }
    .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; }
    .item-entry-form { border: 1px dashed var(--color-border); padding: 1rem; margin-bottom: 1rem; border-radius: var(--border-radius-md); }
    #added-items-table { width: 100%; margin-top: 1rem; border-collapse: collapse; table-layout: fixed; }
    #added-items-table th, #added-items-table td { 
        border: 1px solid var(--color-border); padding: 0.5rem; 
        text-align: left; font-size:0.9em; word-break: break-word;
    }
    #added-items-table th { background-color: var(--color-table-head-bg); }
    #added-items-table .action-cell { width: 80px; text-align: center; }
    #added-items-table .number-cell { text-align: right; width: 120px; }

    /* Bill Preview Styles */
    #bill-content-wrapper {
        padding: 20px; background-color: #525659;
        display: flex; justify-content: center; align-items: flex-start; 
        overflow-y: auto; max-height: 80vh; border-radius: var(--border-radius-md);
    }
    #bill-content {
        font-family: 'Sarabun', sans-serif; background-color: #fff; color: #000;
        width: 210mm;
        height: 297mm;
        font-size: 12pt;
        overflow: hidden; 
        padding: 10mm; 
        box-shadow: 0 5px 15px rgba(0,0,0,0.25);
        box-sizing: border-box; 
        display: flex; flex-direction: column; 
        position: relative;
    }
    
    .bill-body {
        border: 1.5px solid #333; padding: 8mm; width: 100%; height: 100%;
        display: flex; flex-direction: column; position: relative; z-index: 2;
    }
    
    #bill-content::after {
        content: 'ต้นฉบับ'; position: absolute; top: 50%; left: 50%;
        transform: translate(-50%, -50%) rotate(-45deg);
        font-size: 150pt; font-weight: 800; color: rgba(0, 0, 0, 0.04);
        z-index: 1; pointer-events: none;
    }

    #bill-content header { text-align: center; margin-bottom: 5mm; flex-shrink: 0;}
    #bill-content .logo-container img { max-width: 150px; max-height: 50px; object-fit: contain; }
    #bill-content h1 { font-size: 1.5em; margin: 0 0 1mm 0; color: #000; } 
    #bill-content h2 { font-size: 1.2em; margin: 1mm 0; color: #000; } 
    #bill-content .address-phone p { margin: 0.5mm 0; font-size: 0.9em; line-height: 1.4; } 
    #bill-content .bill-meta { display: flex; justify-content: space-between; margin-bottom: 5mm; font-size: 0.9em; flex-shrink: 0;}
    #bill-content .customer-info { border: 1px solid #ccc; padding: 2mm 3mm; margin-bottom: 5mm; font-size: 0.9em; flex-shrink: 0;}
    #bill-content .customer-info p { margin: 1mm 0; } 
    hr.section-divider { border: 0; border-top: 1px solid #000; margin: 4mm 0; }
    
    #bill-content .line-items { flex-grow: 1; margin-bottom: 5mm; border-top: 1px solid #000; border-bottom: 1px solid #000; padding: 1mm 0;}
    #bill-content .line-items-table { width: 100%; border-collapse: collapse; font-size: 0.9em; } 
    #bill-content .line-items-table th, #bill-content .line-items-table td { 
        border: none; padding: 1.5mm 1mm; text-align: left; vertical-align: top;
    }
    #bill-content .line-items-table th { font-weight: bold; border-bottom: 1px solid #999;}
    #bill-content .line-items-table td { border-bottom: 1px dotted #ccc; }
    #bill-content .line-items-table tr:last-child td { border-bottom: none; }
    
    #bill-content .col-desc { width: 55%; }
    #bill-content .col-qty { width: 15%; text-align: center; }
    #bill-content .col-unit-price { width: 15%; text-align: right; }
    #bill-content .col-amount { width: 15%; text-align: right; }

    #bill-content .checkin-checkout-info { font-size: 0.9em; margin-bottom: 5mm; flex-shrink: 0; } 
    #bill-content .totals { text-align: right; margin-top: auto; padding-top: 3mm; font-size: 1em; flex-shrink: 0;} 
    #bill-content .totals table { width: 50%; margin-left: auto; border-collapse: collapse; } 
    #bill-content .totals td { padding: 1.5mm; } 
    #bill-content .totals .grand-total td { font-weight: bold; font-size: 1.2em; border-top: 1px solid #000; border-bottom: 3px double #000;}
    #bill-content .signatures { display: flex; justify-content: center; margin-top: 15mm; font-size: 0.9em; flex-shrink: 0;} 
    #bill-content .signature-box { text-align: center; width: 50%; } 
    #bill-content .signature-line { border-bottom: 1px dotted #000; height: 12mm; margin: 2mm 0 1mm 0; } 
    #bill-content .signature-box p { margin: 0; line-height: 1.4; }
    #bill-content .note-footer {text-align: center; font-size: 0.7em; margin-top: 5mm; color: #555; flex-shrink: 0;}
    #bill-content .thank-you-note {text-align: center; font-weight: bold; font-size: 1em; margin-top: 8mm; flex-shrink: 0;}

    .bill-actions { margin-top: 1rem; display: flex; gap: 1rem; justify-content: center; flex-wrap: wrap;}
    .bill-actions button img, .bill-actions button svg { width: 16px; height: 16px; margin-right: 8px; vertical-align: middle; }
    .bill-actions button:disabled { opacity: 0.6; cursor: not-allowed; }
    
    .form-group small.input-hint {font-size: 0.8em; color: var(--color-text-muted);}

</style>

<div class="cash-bill-container">
    <h2><?= h($pageTitle) ?></h2>
    <div class="bill-form-section" id="cash-bill-input-form">
        <h3><i class="fas fa-edit"></i> กรอกข้อมูลสำหรับออกใบเสร็จ</h3>

        <div class="form-group" style="background-color: var(--color-surface-alt); padding: 1rem; border-radius: var(--border-radius-md);">
            <label for="select_booking_group"><strong>(ทางเลือก) ดึงข้อมูลจากการจองกลุ่ม:</strong></label>
            <select id="select_booking_group" class="form-control">
                <option value="">-- เลือกกลุ่มการจองเพื่อดึงข้อมูล --</option>
                <?php foreach ($active_booking_groups as $group): ?>
                    <option value="<?= h($group['id']) ?>">
                        Group ID: <?= h($group['id']) ?> - <?= h($group['customer_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <small class="input-hint">เมื่อเลือกแล้ว ข้อมูลลูกค้าและรายการห้องพักจะถูกกรอกอัตโนมัติ (รายการบริการเสริมต้องเพิ่มด้วยตนเอง)</small>
        </div>
        <hr>

        <div class="form-grid">
            <div class="form-group">
                <label for="bill_customer_company_name">ในนามบริษัท/ลูกค้า:</label>
                <input type="text" id="bill_customer_company_name" class="form-control">
            </div>
            <div class="form-group">
                <label for="bill_number_input">เลขที่เอกสาร:</label>
                <input type="text" id="bill_number_input" value="<?= h($default_bill_number_prefix . '/' . $current_thai_year) ?>" class="form-control">
            </div>
        </div>
        <div class="form-group">
            <label for="bill_customer_address">ที่อยู่บริษัท/ลูกค้า:</label>
            <textarea id="bill_customer_address" rows="2" class="form-control"></textarea>
        </div>
        <div class="form-grid">
            <div class="form-group">
                <label for="bill_customer_tax_id">เลขประจำตัวผู้เสียภาษี:</label>
                <input type="text" id="bill_customer_tax_id" class="form-control">
            </div>
        </div>
        
        <hr style="margin: 1.5rem 0;">
        
        <div class="item-entry-form">
            <h4><i class="fas fa-plus-circle"></i> เพิ่มรายการในใบเสร็จ</h4>
            <div id="item-type-selector">
                <button type="button" class="button outline-secondary" data-type="room">เพิ่มรายการห้องพัก</button>
                <button type="button" class="button outline-secondary" data-type="service">เพิ่มรายการบริการ/อื่นๆ</button>
            </div>
            
            <div id="room-fields" style="display:none; margin-top:1rem; border-top:1px dashed #ccc; padding-top:1rem;">
                <div class="form-grid">
                    <div class="form-group"><label for="bill_room_select">เลือกห้องพัก:</label><select id="bill_room_select" class="form-control"><option value="">-- เลือกห้อง --</option><?php foreach ($all_rooms_for_bill as $room): ?><option value="<?= h($room['id']) ?>" data-price="<?= h($room['price_per_day']) ?>" data-zone="<?= h($room['zone']) ?>"><?= h($room['zone'] . $room['room_number']) ?> (<?= h(number_format((float)$room['price_per_day'], 0)) ?> บ./คืน)</option><?php endforeach; ?></select></div>
                    <div class="form-group"><label for="bill_room_nights">จำนวนคืน:</label><input type="number" id="bill_room_nights" value="1" min="1" class="form-control"></div>
                    <div class="form-group"><label for="bill_checkin_date">วันที่เช็คอิน:</label><input type="date" id="bill_checkin_date" value="<?= date('Y-m-d') ?>" class="form-control"></div>
                    <div class="form-group"><label for="bill_checkout_date">วันที่เช็คเอาท์:</label><input type="date" id="bill_checkout_date" value="<?= date('Y-m-d', strtotime('+1 day')) ?>" class="form-control"></div>
                </div>
                <button type="button" id="add-room-to-bill-btn" class="button primary" style="margin-top: 0.5rem;">เพิ่มห้องนี้</button>
            </div>

            <div id="service-fields" style="display:none; margin-top:1rem; border-top:1px dashed #ccc; padding-top:1rem;">
                <div class="form-grid">
                    <div class="form-group"><label for="bill_service_select">เลือกบริการ (ถ้ามี):</label><select id="bill_service_select" class="form-control"><option value="">-- หรือพิมพ์รายการเอง --</option><?php foreach ($active_addons_for_bill as $addon): ?><option value="<?= h($addon['id']) ?>" data-price="<?= h($addon['price']) ?>" data-name="<?= h($addon['name']) ?>"><?= h($addon['name']) ?></option><?php endforeach; ?></select></div>
                    <div class="form-group"><label for="bill_service_name">ชื่อรายการ:</label><input type="text" id="bill_service_name" class="form-control"></div>
                    <div class="form-group"><label for="bill_service_qty">จำนวน:</label><input type="number" id="bill_service_qty" value="1" min="1" class="form-control"></div>
                    <div class="form-group"><label for="bill_service_price">ราคา/หน่วย:</label><input type="number" id="bill_service_price" step="any" min="0" class="form-control"></div>
                </div>
                <button type="button" id="add-service-to-bill-btn" class="button primary" style="margin-top: 0.5rem;">เพิ่มรายการนี้</button>
            </div>
        </div>

        <h4><i class="fas fa-list-ul"></i> รายการทั้งหมดในใบเสร็จ</h4>
        <div class="table-responsive">
            <table id="added-items-table">
                <thead>
                    <tr>
                        <th>รายการ</th>
                        <th class="number-cell">จำนวน</th>
                        <th class="number-cell">ราคา/หน่วย (บาท)</th>
                        <th class="number-cell">รวม (บาท)</th>
                        <th class="action-cell">การดำเนินการ</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
        <div style="text-align: right; font-size: 1.2em; margin-top: 1rem; font-weight:bold;">
            ยอดรวมที่ต้องชำระ: <span id="bill_grand_total" style="color: var(--color-primary-dark);">0.00</span> บาท
        </div>
    </div>

    <div class="bill-preview-section">
        <h3><i class="fas fa-eye"></i> ตัวอย่างใบเสร็จรับเงิน</h3>
        <div id="bill-content-wrapper">
            <div id="bill-content">
                <div class="bill-body">
                    <header>
                        <?php if ($logo_path): ?>
                        <div class="logo-container">
                            <img src="<?= h($logo_path) ?>" alt="Hotel Logo">
                        </div>
                        <?php endif; ?>
                        <h1>ใบเสร็จรับเงิน / ต้นฉบับ</h1>
                        <h2> โรงแรมภัทรรีสอร์ท</h2>
                        <div class="address-phone">
                            <p>ที่อยู่: 119/2 ม.13 ต.โคกแย้ อ.หนองแค จ.สระบุรี 18230</p>
                            <p>โทร: 089-889-5019 / 083-879-4469 / 064-879-4469</p>
                        </div>
                    </header>
                    <hr class="section-divider">
                    <section class="bill-meta">
                        <div>เลขที่: <span id="preview_bill_number"><?= h($default_bill_number_prefix . '/' . $current_thai_year) ?></span></div>
                        <div>วันที่: <span id="preview_bill_date"><?= toThaiDateString(date('Y-m-d')) ?></span></div>
                    </section>
                    <section class="customer-info">
                        <p><strong>นามลูกค้า/บริษัท:</strong> <span id="preview_customer_name"></span></p>
                        <p><strong>ที่อยู่:</strong> <span id="preview_customer_address"></span></p>
                        <p><strong>เลขประจำตัวผู้เสียภาษี:</strong> <span id="preview_customer_tax_id"></span></p>
                    </section>
                     <section class="checkin-checkout-info">
                        <p><strong>วันที่เข้าพัก:</strong> <span id="preview_checkin_date"></span></p>
                        <p><strong>วันที่ออก:</strong> <span id="preview_checkout_date"></span></p>
                    </section>
                    <section class="line-items">
                        <table class="line-items-table">
                            <thead>
                                <tr>
                                    <th class="col-desc">รายการ</th>
                                    <th class="col-qty">จำนวน</th>
                                    <th class="col-unit-price">ราคา/หน่วย</th>
                                    <th class="col-amount">จำนวนเงิน</th>
                                </tr>
                            </thead>
                            <tbody id="preview_line_items">
                                <tr><td colspan="4" style="text-align:center; color:#888; padding: 5mm;"><i>- ยังไม่มีรายการ -</i></td></tr>
                            </tbody>
                        </table>
                    </section>
                    <section class="totals">
                        <table>
                            <tr class="grand-total">
                                <td>ยอดรวมทั้งสิ้น:</td>
                                <td class="amount"><span id="preview_grand_total">0.00</span></td>
                            </tr>
                        </table>
                    </section>
                    <div class="thank-you-note">
                        <p>*** ขอขอบคุณที่ไว้วางใจใช้บริการ ***</p>
                    </div>
                     <section class="signatures">
                        <div class="signature-box">
                            <p>ผู้รับเงิน</p>
                            <div class="signature-line"></div>
                            <p>(............................................)</p>
                        </div>
                    </section>
                     <p class="note-footer">
                        เอกสารนี้ออกโดยระบบอัตโนมัติ - โรงแรมภัทรรีสอร์ท
                    </p>
                </div>
            </div>
        </div>
        <div class="bill-actions">
            <button type="button" id="save-bill-as-image-btn" class="button secondary" disabled>
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-image"><rect width="18" height="18" x="3" y="3" rx="2" ry="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21"/></svg>
                บันทึกเป็นรูปภาพ
            </button>
            <button type="button" id="share-bill-btn" class="button info" disabled>
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-share-2"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="m8.59 13.51 6.83 4.98"/><path d="m15.41 6.49-6.83 4.98"/></svg>
                แชร์บิล
            </button>
            <button type="button" id="print-bill-btn" class="button alert" disabled>
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-printer"><path d="M6 18H4a2 2 0 0 1-2-2V9a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v7a2 2 0 0 1-2 2h-2"/><path d="M18 14H6"/><path d="M9 18V7h6v11"/></svg>
                สั่งพิมพ์
            </button>
        </div>
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const groupSelect = document.getElementById('select_booking_group');
    const itemTypeSelector = document.getElementById('item-type-selector');
    const roomFields = document.getElementById('room-fields');
    const serviceFields = document.getElementById('service-fields');
    const roomSelect = document.getElementById('bill_room_select');
    const nightsInput = document.getElementById('bill_room_nights');
    const checkinDateInput = document.getElementById('bill_checkin_date');
    const checkoutDateInput = document.getElementById('bill_checkout_date');
    const addRoomBtn = document.getElementById('add-room-to-bill-btn');
    const serviceSelect = document.getElementById('bill_service_select');
    const serviceNameInput = document.getElementById('bill_service_name');
    const serviceQtyInput = document.getElementById('bill_service_qty');
    const servicePriceInput = document.getElementById('bill_service_price');
    const addServiceBtn = document.getElementById('add-service-to-bill-btn');
    const addedItemsTableBody = document.querySelector('#added-items-table tbody');
    const billGrandTotalSpan = document.getElementById('bill_grand_total');
    const previewBillNumber = document.getElementById('preview_bill_number');
    const previewBillDate = document.getElementById('preview_bill_date');
    const previewCustomerName = document.getElementById('preview_customer_name');
    const previewCustomerAddress = document.getElementById('preview_customer_address');
    const previewCustomerTaxId = document.getElementById('preview_customer_tax_id');
    const previewLineItemsBody = document.getElementById('preview_line_items');
    const previewGrandTotal = document.getElementById('preview_grand_total');
    const previewCheckinDate = document.getElementById('preview_checkin_date');
    const previewCheckoutDate = document.getElementById('preview_checkout_date');
    const customerNameInput = document.getElementById('bill_customer_company_name');
    const customerAddressInput = document.getElementById('bill_customer_address');
    const customerTaxIdInput = document.getElementById('bill_customer_tax_id');
    const billNumberInputEl = document.getElementById('bill_number_input');
    const saveAsImageBtn = document.getElementById('save-bill-as-image-btn');
    const shareBillBtn = document.getElementById('share-bill-btn');
    const printBillBtn = document.getElementById('print-bill-btn');

    let billItems = [];

    itemTypeSelector.addEventListener('click', function(e) {
        if (e.target.tagName === 'BUTTON') {
            const type = e.target.dataset.type;
            roomFields.style.display = type === 'room' ? 'block' : 'none';
            serviceFields.style.display = type === 'service' ? 'block' : 'none';
        }
    });

    serviceSelect.addEventListener('change', function() {
        const selected = this.options[this.selectedIndex];
        if (selected.value) {
            serviceNameInput.value = selected.dataset.name || '';
            servicePriceInput.value = parseFloat(selected.dataset.price).toFixed(2) || '';
        }
    });

    addServiceBtn.addEventListener('click', function() {
        const name = serviceNameInput.value.trim();
        const qty = parseInt(serviceQtyInput.value, 10);
        const price = parseFloat(servicePriceInput.value);
        if (!name || isNaN(qty) || qty < 1 || isNaN(price)) {
            alert('กรุณากรอกข้อมูลรายการบริการให้ครบถ้วนและถูกต้อง (ราคาต้องเป็นตัวเลข)'); return;
        }
        billItems.push({ id: `service-${Date.now()}`, type: 'service', description: name, quantity: qty, unitPrice: price, itemTotal: qty * price });
        renderAllItems();
        serviceNameInput.value = ''; serviceQtyInput.value = '1'; servicePriceInput.value = ''; serviceSelect.value = '';
    });

    addRoomBtn.addEventListener('click', function() {
        const roomId = roomSelect.value;
        const nights = parseInt(nightsInput.value);
        const checkin = checkinDateInput.value;
        const checkout = checkoutDateInput.value;
        if (!roomId || isNaN(nights) || nights < 1 || !checkin || !checkout) {
            alert('กรุณาเลือกห้องพัก, จำนวนคืน, และวันที่ให้ถูกต้อง'); return;
        }
        const selectedRoomOption = roomSelect.options[roomSelect.selectedIndex];
        const roomName = selectedRoomOption.text.split(' (')[0];
        const pricePerNight = parseFloat(selectedRoomOption.dataset.price);
        billItems.push({ id: `room-${Date.now()}`, type: 'room', description: `ค่าห้องพัก ${roomName}`, quantity: nights, unitPrice: pricePerNight, itemTotal: pricePerNight * nights, checkin: checkin, checkout: checkout });
        renderAllItems();
    });

    [customerNameInput, customerAddressInput, customerTaxIdInput, billNumberInputEl].forEach(input => input.addEventListener('input', updatePreview));
    checkinDateInput.addEventListener('change', calculateCheckoutDate);
    nightsInput.addEventListener('input', calculateCheckoutDate);

    groupSelect.addEventListener('change', async function() {
        const groupId = this.value;
        if (!groupId) {
            billItems = []; customerNameInput.value = ''; customerAddressInput.value = ''; customerTaxIdInput.value = '';
            renderAllItems(); return;
        }
        try {
            const response = await fetch(`/hotel_booking/pages/api.php?action=get_group_details_for_bill&booking_group_id=${groupId}`);
            const data = await response.json();
            if (data.success) {
                customerNameInput.value = data.group_info.customer_name || '';
                billItems = data.bookings.map(booking => ({ id: `room-${booking.id}`, type: 'room', description: `ค่าห้องพัก ${booking.zone}${booking.room_number}`, quantity: parseInt(booking.nights, 10), unitPrice: parseFloat(booking.price_per_night), itemTotal: parseFloat(booking.price_per_night) * parseInt(booking.nights, 10), checkin: booking.checkin_datetime.split(' ')[0], checkout: booking.checkout_datetime_calculated.split(' ')[0] }));
                renderAllItems();
            } else { alert('Error: ' + data.message); }
        } catch (error) { console.error('Error:', error); alert('เกิดข้อผิดพลาดในการเชื่อมต่อ'); }
    });
    
    function renderAllItems() {
        addedItemsTableBody.innerHTML = '';
        let grandTotal = 0;
        if (billItems.length === 0) {
            addedItemsTableBody.innerHTML = '<tr><td colspan="5" class="text-center text-muted"><i>- ยังไม่มีรายการ -</i></td></tr>';
        } else {
            billItems.forEach(item => {
                const row = addedItemsTableBody.insertRow();
                row.insertCell().textContent = item.description;
                const qtyCell = row.insertCell();
                qtyCell.textContent = item.quantity;
                qtyCell.className = 'number-cell';
                const unitPriceCell = row.insertCell();
                unitPriceCell.textContent = item.unitPrice.toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                unitPriceCell.className = 'number-cell';
                const totalCell = row.insertCell();
                totalCell.textContent = item.itemTotal.toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                totalCell.className = 'number-cell';
                const actionCell = row.insertCell();
                actionCell.className = 'action-cell';
                const removeBtn = document.createElement('button');
                removeBtn.textContent = 'ลบ';
                removeBtn.className = 'button-small alert';
                removeBtn.dataset.itemId = item.id;
                removeBtn.onclick = function() {
                    billItems = billItems.filter(bItem => bItem.id !== this.dataset.itemId);
                    renderAllItems();
                };
                actionCell.appendChild(removeBtn);
                grandTotal += item.itemTotal;
            });
        }
        billGrandTotalSpan.textContent = grandTotal.toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        updatePreview();
        updateActionButtonsState();
    }

    function updatePreview() {
        previewBillNumber.textContent = billNumberInputEl.value || 'N/A';
        previewBillDate.textContent = toThaiDateForJS(new Date());
        previewCustomerName.textContent = customerNameInput.value.trim() || '-';
        previewCustomerAddress.textContent = customerAddressInput.value.trim() || '-';
        previewCustomerTaxId.textContent = customerTaxIdInput.value.trim() || '-';
        
        previewLineItemsBody.innerHTML = '';
        let currentPreviewGrandTotal = 0;
        let overallMinCheckin = null;
        let overallMaxCheckout = null;

        if (billItems.length === 0) {
            previewLineItemsBody.innerHTML = '<tr><td colspan="4" style="text-align:center; color:#888; padding: 5mm;"><i>- ยังไม่มีรายการ -</i></td></tr>';
        } else {
            const groupedRoomItems = {};
            const serviceItems = billItems.filter(item => item.type === 'service');
            
            billItems.filter(item => item.type === 'room').forEach(item => {
                const groupKey = `${item.unitPrice}_${item.quantity}`; 
                if (!groupedRoomItems[groupKey]) {
                    groupedRoomItems[groupKey] = { roomNames: [], quantity: item.quantity, unitPrice: item.unitPrice, itemTotalSum: 0 };
                }
                const roomNumberOnly = item.description.replace('ค่าห้องพัก ', '');
                groupedRoomItems[groupKey].roomNames.push(roomNumberOnly);
                groupedRoomItems[groupKey].itemTotalSum += item.itemTotal;
                const checkin = new Date(item.checkin);
                const checkout = new Date(item.checkout);
                if (!overallMinCheckin || checkin < overallMinCheckin) overallMinCheckin = checkin;
                if (!overallMaxCheckout || checkout > overallMaxCheckout) overallMaxCheckout = checkout;
            });

            Object.values(groupedRoomItems).forEach(group => {
                const row = previewLineItemsBody.insertRow();
                row.insertCell(0).textContent = `ค่าห้องพัก ${group.roomNames.join(', ')}`;
                row.cells[0].className = 'col-desc';
                row.insertCell(1).textContent = `${group.quantity} คืน`;
                row.cells[1].className = 'col-qty';
                row.insertCell(2).textContent = group.unitPrice.toLocaleString('th-TH', { minimumFractionDigits: 2 });
                row.cells[2].className = 'col-unit-price';
                row.insertCell(3).textContent = group.itemTotalSum.toLocaleString('th-TH', { minimumFractionDigits: 2 });
                row.cells[3].className = 'col-amount';
                currentPreviewGrandTotal += group.itemTotalSum;
            });
            
            serviceItems.forEach(item => {
                const row = previewLineItemsBody.insertRow();
                row.insertCell(0).textContent = item.description;
                row.cells[0].className = 'col-desc';
                row.insertCell(1).textContent = item.quantity;
                row.cells[1].className = 'col-qty';
                row.insertCell(2).textContent = item.unitPrice.toLocaleString('th-TH', { minimumFractionDigits: 2 });
                row.cells[2].className = 'col-unit-price';
                row.insertCell(3).textContent = item.itemTotal.toLocaleString('th-TH', { minimumFractionDigits: 2 });
                row.cells[3].className = 'col-amount';
                currentPreviewGrandTotal += item.itemTotal;
            });
        }
        
        previewCheckinDate.textContent = overallMinCheckin ? toThaiDateForJS(overallMinCheckin) : '-';
        previewCheckoutDate.textContent = overallMaxCheckout ? toThaiDateForJS(overallMaxCheckout) : '-';
        previewGrandTotal.textContent = currentPreviewGrandTotal.toLocaleString('th-TH', { minimumFractionDigits: 2 });
    }

    function calculateCheckoutDate() {
        if (!checkinDateInput.value || !nightsInput.value) { checkoutDateInput.value = ''; return; }
        try {
            const checkin = new Date(checkinDateInput.value);
            const nights = parseInt(nightsInput.value);
            if (isNaN(checkin.getTime()) || isNaN(nights) || nights < 1) { checkoutDateInput.value = ''; return; }
            const checkout = new Date(checkin);
            checkout.setDate(checkin.getDate() + nights);
            checkoutDateInput.value = checkout.toISOString().split('T')[0];
        } catch (e) { checkoutDateInput.value = ''; }
    }

    function toThaiDateForJS(dateInput) {
        if (!dateInput) return 'N/A';
        const date = new Date(dateInput);
        if (isNaN(date.getTime())) return 'N/A';
        const thaiMonths = ["มกราคม", "กุมภาพันธ์", "มีนาคม", "เมษายน", "พฤษภาคม", "มิถุนายน", "กรกฎาคม", "สิงหาคม", "กันยายน", "ตุลาคม", "พฤศจิกายน", "ธันวาคม"];
        return `${date.getDate()} ${thaiMonths[date.getMonth()]} ${date.getFullYear() + 543}`;
    }

    function updateActionButtonsState() {
        const hasItems = billItems.length > 0;
        saveAsImageBtn.disabled = !hasItems; 
        shareBillBtn.disabled = !hasItems; 
        printBillBtn.disabled = !hasItems;
    }

    // --- START: New Printing Logic ---
    printBillBtn.addEventListener('click', function() {
        const billContentNode = document.getElementById('bill-content');
        if (!billContentNode) {
            alert('ผิดพลาด: ไม่พบเนื้อหาสำหรับพิมพ์');
            return;
        }

        const printWindow = window.open('', '_blank', 'width=880,height=900,scrollbars=yes,resizable=yes');
        
        printWindow.document.write(`
            <html>
                <head>
                    <title>พิมพ์ใบเสร็จรับเงิน</title>
                    <link rel="preconnect" href="https://fonts.googleapis.com">
                    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
                    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;700;800&display=swap" rel="stylesheet">
                    <style>
                        body { font-family: 'Sarabun', sans-serif; margin: 0; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
                        @page { size: A4; margin: 0; }
                        #bill-content {
                            width: 210mm; height: 297mm; padding: 10mm; box-sizing: border-box; display: flex; flex-direction: column; position: relative;
                            background-color: #fff; color: #000; font-size: 12pt;
                        }
                        .bill-body { border: 1.5px solid #333; padding: 8mm; width: 100%; height: 100%; display: flex; flex-direction: column; position: relative; z-index: 2; box-sizing: border-box; }
                        #bill-content::after { content: 'ต้นฉบับ'; position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%) rotate(-45deg); font-size: 150pt; font-weight: 800; color: rgba(0, 0, 0, 0.04); z-index: 1; pointer-events: none; }
                        header { text-align: center; margin-bottom: 5mm; flex-shrink: 0;}
                        .logo-container img { max-width: 150px; max-height: 50px; object-fit: contain; }
                        h1 { font-size: 1.5em; margin: 0 0 1mm 0; color: #000; } 
                        h2 { font-size: 1.2em; margin: 1mm 0; color: #000; } 
                        .address-phone p { margin: 0.5mm 0; font-size: 0.9em; line-height: 1.4; } 
                        .bill-meta { display: flex; justify-content: space-between; margin-bottom: 5mm; font-size: 0.9em; flex-shrink: 0;}
                        .customer-info { border: 1px solid #ccc; padding: 2mm 3mm; margin-bottom: 5mm; font-size: 0.9em; flex-shrink: 0;}
                        .customer-info p { margin: 1mm 0; } 
                        hr.section-divider { border: 0; border-top: 1px solid #000; margin: 4mm 0; }
                        .line-items { flex-grow: 1; margin-bottom: 5mm; border-top: 1px solid #000; border-bottom: 1px solid #000; padding: 1mm 0;}
                        .line-items-table { width: 100%; border-collapse: collapse; font-size: 0.9em; } 
                        .line-items-table th, .line-items-table td { border: none; padding: 1.5mm 1mm; text-align: left; vertical-align: top; }
                        .line-items-table th { font-weight: bold; border-bottom: 1px solid #999;}
                        .line-items-table td { border-bottom: 1px dotted #ccc; }
                        .line-items-table tr:last-child td { border-bottom: none; }
                        .col-desc { width: 55%; } .col-qty { width: 15%; text-align: center; } .col-unit-price { width: 15%; text-align: right; } .col-amount { width: 15%; text-align: right; }
                        .checkin-checkout-info { font-size: 0.9em; margin-bottom: 5mm; flex-shrink: 0; } 
                        .totals { text-align: right; margin-top: auto; padding-top: 3mm; font-size: 1em; flex-shrink: 0;} 
                        .totals table { width: 50%; margin-left: auto; border-collapse: collapse; } 
                        .totals td { padding: 1.5mm; } 
                        .totals .grand-total td { font-weight: bold; font-size: 1.2em; border-top: 1px solid #000; border-bottom: 3px double #000;}
                        .signatures { display: flex; justify-content: center; margin-top: 15mm; font-size: 0.9em; flex-shrink: 0;} 
                        .signature-box { text-align: center; width: 50%; } 
                        .signature-line { border-bottom: 1px dotted #000; height: 12mm; margin: 2mm 0 1mm 0; } 
                        .signature-box p { margin: 0; line-height: 1.4; }
                        .note-footer {text-align: center; font-size: 0.7em; margin-top: 5mm; color: #555; flex-shrink: 0;}
                        .thank-you-note {text-align: center; font-weight: bold; font-size: 1em; margin-top: 8mm; flex-shrink: 0;}
                    </style>
                </head>
                <body>
                    <div id="bill-content">
                        ${billContentNode.innerHTML}
                    </div>
                </body>
            </html>
        `);

        printWindow.document.close();
        setTimeout(() => {
            try {
                printWindow.focus();
                printWindow.print();
                printWindow.close();
            } catch (e) {
                console.error("Print failed:", e);
                alert("ไม่สามารถสั่งพิมพ์ได้ อาจถูกบล็อกโดยเบราว์เซอร์");
            }
        }, 1000); // 1 second delay
    });
    // --- END: New Printing Logic ---

    async function generateBillCanvas() {
        const sourceElement = document.getElementById('bill-content');
        if (!sourceElement || typeof html2canvas !== 'function') {
            alert('ไม่สามารถสร้างรูปภาพได้: ไม่พบส่วนประกอบที่จำเป็น');
            return null;
        }
        const clone = sourceElement.cloneNode(true);
        clone.style.position = 'absolute';
        clone.style.top = '-9999px';
        clone.style.left = '0px';
        clone.style.width = '210mm';
        clone.style.height = '297mm';
        clone.style.margin = '0';
        clone.style.fontSize = '12pt';
        clone.style.fontFamily = "'Sarabun', sans-serif";
        clone.style.color = '#000';
        document.body.appendChild(clone);
        try {
            const canvas = await html2canvas(clone, { scale: 3, useCORS: true, logging: false, width: clone.offsetWidth, height: clone.offsetHeight, backgroundColor: '#ffffff' });
            return canvas;
        } catch (error) {
            console.error('Error generating canvas:', error);
            alert('เกิดข้อผิดพลาดในการสร้างรูปภาพ: ' + error.message);
            return null;
        } finally {
            if (document.body.contains(clone)) { document.body.removeChild(clone); }
        }
    }
    
    saveAsImageBtn.addEventListener('click', async function() {
        const buttonId = 'save-bill-as-image-btn';
        if (typeof setButtonLoading === 'function') setButtonLoading(this, true, buttonId);
        const canvas = await generateBillCanvas();
        if (canvas) {
            const image = canvas.toDataURL('image/png', 1.0);
            const link = document.createElement('a');
            const fileName = `bill_${(document.getElementById('bill_number_input').value || 'receipt').replace(/[\/\\]/g, '-')}.png`;
            link.download = fileName;
            link.href = image;
            link.click();
        }
        if (typeof setButtonLoading === 'function') setButtonLoading(this, false, buttonId);
    });

    if (shareBillBtn) {
        shareBillBtn.addEventListener('click', async function() {
            const buttonId = 'share-bill-btn';
            if (typeof setButtonLoading === 'function') setButtonLoading(this, true, buttonId);
            const canvas = await generateBillCanvas();
            if (canvas) {
                const fileName = `receipt_${(document.getElementById('bill_number_input').value || 'receipt').replace(/[\/\\]/g, '-')}.png`;
                canvas.toBlob(async function(blob) {
                    if (!blob) {
                         alert('ไม่สามารถสร้างไฟล์รูปภาพสำหรับแชร์ได้');
                         if (typeof setButtonLoading === 'function') setButtonLoading(shareBillBtn, false, buttonId);
                         return;
                    }
                    if (navigator.share && typeof File !== 'undefined' && navigator.canShare({ files: [new File([blob], fileName, {type: blob.type})] })) {
                        const shareFile = new File([blob], fileName, { type: blob.type });
                        try {
                            await navigator.share({
                                title: `ใบเสร็จรับเงิน เลขที่ ${document.getElementById('bill_number_input').value || ''}`,
                                text: `ใบเสร็จรับเงินสำหรับ ${document.getElementById('bill_customer_company_name').value || 'ลูกค้า'}`,
                                files: [shareFile]
                            });
                        } catch (error) {
                            if (error.name !== 'AbortError') {
                                console.error('[Share Bill] Share failed:', error);
                                alert('การแชร์ไม่สำเร็จ: ' + error.message);
                            }
                        }
                    } else {
                        alert('เบราว์เซอร์นี้ไม่รองรับการแชร์ไฟล์โดยตรง กรุณาใช้ปุ่ม "บันทึกเป็นรูปภาพ" แล้วแชร์ด้วยตนเอง');
                    }
                    if (typeof setButtonLoading === 'function') setButtonLoading(shareBillBtn, false, buttonId);
                }, 'image/png');
            } else {
                if (typeof setButtonLoading === 'function') setButtonLoading(shareBillBtn, false, buttonId);
            }
        });
    }

    const originalButtonContents_cashbill = {}; 
    function setButtonLoading(buttonElement, isLoading, buttonIdForTextStore) {
        if (!buttonElement) return;
        const key = buttonIdForTextStore || buttonElement.id || `btn-cashbill-${Date.now()}`;
        if (isLoading) {
            if (!buttonElement.classList.contains('loading')) {
                if (originalButtonContents_cashbill[key] === undefined) { originalButtonContents_cashbill[key] = buttonElement.innerHTML; }
                const spinnerSpan = '<span class="button-spinner-css" style="width:1em; height:1em; border:2px solid rgba(255,255,255,0.3); border-top-color:white; border-radius:50%; display:inline-block; animation: spin 0.6s linear infinite; margin-right: 5px;"></span>';
                buttonElement.innerHTML = spinnerSpan + ' กำลังดำเนินการ...';
                buttonElement.classList.add('loading');
                buttonElement.disabled = true;
            }
        } else {
            if (buttonElement.classList.contains('loading')) {
                if (originalButtonContents_cashbill[key] !== undefined) { buttonElement.innerHTML = originalButtonContents_cashbill[key]; }
                buttonElement.classList.remove('loading');
                buttonElement.disabled = false;
            }
        }
    }
    if (!document.getElementById('button-spinner-style-cashbill')) {
        const style = document.createElement('style');
        style.id = 'button-spinner-style-cashbill';
        style.innerHTML = `@keyframes spin { to { transform: rotate(360deg); } }`;
        document.head.appendChild(style);
    }
    
    renderAllItems();

});
</script>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../templates/layout.php';
?>
