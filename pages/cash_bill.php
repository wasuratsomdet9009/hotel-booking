<?php
// FILEX: hotel_booking/pages/cash_bill.php
// VERSION: 4.2.1 - Updated Logo Path to image/logo.ico
// DESC: Added customer search (autocomplete) from new `customer_directory` table.
//       Updated `save_receipt` action to auto-save/update customer info
//       to the directory. Updated logo path.

require_once __DIR__ . '/../bootstrap.php';

// ***** START: API HANDLER BLOCK *****

// --- API Action: Search Customers ---
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'search_customers') {
    header('Content-Type: application/json');
    $term = $_GET['term'] ?? '';

    if (mb_strlen($term) < 2) {
        echo json_encode([]);
        exit;
    }

    try {
        $stmt = $pdo->prepare("
            SELECT customer_name, customer_address, customer_tax_id 
            FROM customer_directory 
            WHERE customer_name LIKE ? 
            LIMIT 10
        ");
        $stmt->execute(['%' . $term . '%']);
        $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($customers);
    } catch (Exception $e) {
        error_log("Customer search failed: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database error during search.']);
    }
    exit;
}

// --- API Action: Save Receipt ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'save_receipt') {
    header('Content-Type: application/json');

    if (session_status() == PHP_SESSION_NONE) {
        session_start();
    }

    $data = json_decode(file_get_contents('php://input'), true);

    try {
        // 1. Get current user ID
        $userId = get_current_user_id(); // From bootstrap.php
        if (!$userId) {
            throw new Exception('User not authenticated. Please log in again.');
        }

        // 2. Get new receipt number (function from bootstrap.php)
        $receiptNumber = getNextReceiptNumber($pdo);

        // 3. Prepare data for insertion
        $bookingGroupId = !empty($data['booking_group_id']) ? (int)$data['booking_group_id'] : null;
        $totalAmount = !empty($data['grand_total']) ? (float)$data['grand_total'] : 0.0;
        $paymentMethod = !empty($data['payment_method']) ? $data['payment_method'] : 'Cash';
        $customerName = $data['customer_name'] ?? '';
        $customerAddress = $data['customer_address'] ?? '';
        $customerTaxId = $data['customer_tax_id'] ?? '';

        // 4. Create the full JSON snapshot for auditing
        $receiptDataJson = json_encode($data);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Failed to encode receipt data: ' . json_last_error_msg());
        }

        // Start transaction for multiple inserts
        $pdo->beginTransaction();

        // 5. Insert into new table
        $stmt = $pdo->prepare("
            INSERT INTO generated_receipts 
            (booking_group_id, receipt_number, receipt_date, customer_name, customer_address, customer_tax_id, total_amount, payment_method, issued_by_user_id, receipt_data_json, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");

        $stmt->execute([
            $bookingGroupId,
            $receiptNumber,
            $data['bill_date'], // From client
            $customerName,
            $customerAddress,
            $customerTaxId,
            $totalAmount,
            $paymentMethod,
            $userId,
            $receiptDataJson
        ]);

        $receiptId = $pdo->lastInsertId();

        // 6. ***** NEW: Save/Update Customer Directory *****
        // If a customer name was provided, save it to the directory
        if (!empty($customerName)) {
            $stmt_cust = $pdo->prepare("
                INSERT INTO customer_directory (customer_name, customer_address, customer_tax_id, created_at, updated_at)
                VALUES (?, ?, ?, NOW(), NOW())
                ON DUPLICATE KEY UPDATE
                    customer_address = VALUES(customer_address),
                    customer_tax_id = VALUES(customer_tax_id),
                    updated_at = NOW()
            ");
            $stmt_cust->execute([$customerName, $customerAddress, $customerTaxId]);
        }

        // Commit transaction
        $pdo->commit();

        echo json_encode(['success' => true, 'receipt_id' => $receiptId, 'receipt_number' => $receiptNumber]);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Failed to save receipt: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit; // Stop script execution
}
// ***** END: API HANDLER BLOCK *****


require_login();

$pageTitle = 'ออกใบเสร็จรับเงิน / บิลเงินสด';

// --- Data Fetching for UI Controls ---
$rooms_stmt = $pdo->query("SELECT id, zone, room_number, price_per_day FROM rooms ORDER BY zone ASC, CAST(room_number AS UNSIGNED) ASC");
$all_rooms_for_bill = $rooms_stmt->fetchAll(PDO::FETCH_ASSOC);

$active_groups_stmt = $pdo->query("
    SELECT DISTINCT bg.id, bg.customer_name 
    FROM booking_groups bg
    JOIN bookings b ON bg.id = b.booking_group_id
    ORDER BY bg.created_at DESC
");
$active_booking_groups = $active_groups_stmt->fetchAll(PDO::FETCH_ASSOC);

$addons_stmt = $pdo->query("SELECT id, name, price FROM addon_services WHERE is_active = 1 ORDER BY name ASC");
$active_addons_for_bill = $addons_stmt->fetchAll(PDO::FETCH_ASSOC);


// --- Utility Functions & Defaults ---
$current_thai_year = date('Y') + 543;
$default_bill_number_prefix = "01";

ob_start();
?>

<style>
    /* ============================
       Cash Bill - Modern Invoice Design
       ============================  */
    @import url('https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700;800&display=swap');

    .cash-bill-container {
        max-width: 1000px;
        margin: 0 auto;
    }

    /* --- Form Section --- */
    .bill-form-section,
    .bill-preview-section {
        background-color: var(--color-surface);
        padding: 1.5rem;
        border-radius: var(--border-radius-lg);
        box-shadow: var(--shadow-md);
        margin-bottom: 2rem;
        border: 1px solid var(--color-border);
    }

    .bill-form-section h3,
    .bill-preview-section h3 {
        margin-top: 0;
        color: var(--color-primary-dark);
        padding-bottom: 0.75rem;
        border-bottom: 1px solid var(--color-border);
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .form-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 1rem;
    }

    .item-entry-form {
        border: 1px dashed var(--color-border);
        padding: 1rem;
        margin-bottom: 1rem;
        border-radius: var(--border-radius-md);
    }

    #added-items-table {
        width: 100%;
        margin-top: 1rem;
        border-collapse: collapse;
        table-layout: fixed;
    }

    #added-items-table th,
    #added-items-table td {
        border: 1px solid var(--color-border);
        padding: 0.5rem;
        text-align: left;
        font-size: 0.9em;
        word-break: break-word;
    }

    #added-items-table th {
        background-color: var(--color-table-head-bg);
    }

    #added-items-table .action-cell {
        width: 80px;
        text-align: center;
    }

    #added-items-table .number-cell {
        text-align: right;
        width: 120px;
    }

    .editable-cell {
        cursor: pointer;
    }

    .editable-cell:hover {
        background-color: var(--color-primary-light);
    }

    .editable-cell input {
        width: 100%;
        box-sizing: border-box;
        text-align: right;
        padding: 2px 4px;
    }

    /* --- Bill Preview Wrapper --- */
    #bill-content-wrapper {
        padding: 20px;
        background-color: #e8edf2;
        display: block;
        /* เปลี่ยนจาก flex เป็น block เพื่อแก้ปัญหาเบียดและคลิปหน้าจอในมือถือ */
        overflow-x: auto;
        /* เพิ่ม scroll แนวนอน */
        overflow-y: auto;
        max-height: 90vh;
        border-radius: var(--border-radius-md);
        -webkit-overflow-scrolling: touch;
        text-align: center;
        /* จัดให้บิลอยู่ตรงกลางหน้าจอ */
    }

    /* ============================
       INVOICE DOCUMENT STYLES (CLASSIC THAI STYLE)
       ============================ */
    #bill-content {
        font-family: 'Sarabun', sans-serif;
        background-color: #fff;
        color: #000;
        width: 210mm;
        min-width: 210mm;
        min-height: 297mm;
        margin: 0 auto;
        padding: 10mm;
        /* ระยะห่างขอบกระดาษ */
        box-sizing: border-box;
        text-align: left;
        font-size: 11pt;
    }

    .classic-border-wrap {
        border: 1px solid #000;
        padding: 10mm 12mm;
        height: 100%;
        min-height: 277mm;
        /* 297 - 20 */
        display: flex;
        flex-direction: column;
        box-sizing: border-box;
    }

    .c-header {
        text-align: center;
        margin-bottom: 5mm;
    }

    .c-header h2 {
        font-size: 1.4em;
        font-weight: bold;
        margin: 0 0 2mm 0;
        color: #000;
        border: none;
        padding: 0;
        display: block;
        justify-content: center;
    }

    .c-header h3 {
        font-size: 1.4em;
        font-weight: bold;
        margin: 0 0 3mm 0;
        color: #000;
        border: none;
        padding: 0;
        display: block;
        justify-content: center;
    }

    .c-header p {
        margin: 1.5mm 0;
        font-size: 1.05em;
    }

    .c-dotted {
        text-decoration: underline;
        text-decoration-style: dotted;
        text-underline-offset: 4px;
        text-decoration-thickness: 1.5px;
    }

    .c-meta {
        display: flex;
        justify-content: space-between;
        margin-top: 4mm;
        margin-bottom: 2mm;
        font-size: 1em;
    }

    .c-line {
        border-top: 1px solid #000;
        margin-bottom: 4mm;
    }

    .c-customer {
        border: 1px solid #000;
        padding: 4mm 5mm;
        margin-bottom: 6mm;
        font-size: 1.05em;
    }

    .c-customer p {
        margin: 2.5mm 0;
        line-height: 1.6;
    }

    .c-dates {
        font-size: 1.1em;
        margin-bottom: 6mm;
        font-weight: bold;
    }

    .c-dates p {
        margin: 3mm 0;
    }

    .c-table {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 2mm;
        font-size: 1em;
    }

    .c-table th {
        border-top: 1px solid #000;
        border-bottom: 1px solid #000;
        padding: 3mm 1mm;
        font-weight: bold;
    }

    .c-table td {
        padding: 3mm 1mm;
        vertical-align: top;
    }

    .c-table-bottom-line {
        border-top: 1px solid #000;
        margin-bottom: 5mm;
    }

    .c-total-wrap {
        text-align: right;
        font-weight: bold;
        font-size: 1.2em;
        padding-top: 2mm;
        margin-bottom: 15mm;
    }

    .c-total-inner {
        display: inline-block;
        border-bottom: 3px double #000;
        padding-bottom: 2mm;
        padding-right: 2mm;
    }

    .c-total-inner span:first-child {
        display: inline-block;
        width: 40mm;
        text-align: right;
        margin-right: 6mm;
    }

    .c-total-inner span:last-child {
        display: inline-block;
        width: 25mm;
        text-align: right;
    }

    .c-footer {
        text-align: center;
        margin-top: auto;
        padding-top: 10mm;
    }

    .c-footer .thank-you {
        font-weight: bold;
        font-size: 1.2em;
        margin-bottom: 15mm;
        letter-spacing: 1px;
    }

    .c-footer .sig {
        margin-bottom: 8mm;
        font-size: 1em;
    }

    .c-footer .sig-line {
        margin-top: 8mm;
        color: #333;
    }

    .c-footer .auto-gen {
        font-size: 0.9em;
        color: #555;
        font-weight: bold;
    }

    /* Action Buttons */
    .bill-actions {
        margin-top: 1rem;
        display: flex;
        gap: 0.75rem;
        justify-content: center;
        flex-wrap: wrap;
    }

    .bill-actions button {
        padding: 0.65rem 1.4rem;
        border-radius: 0.5rem;
        font-size: 0.95rem;
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }

    .bill-actions button:disabled {
        opacity: 0.5;
        cursor: not-allowed;
    }

    .form-group small.input-hint {
        font-size: 0.8em;
        color: var(--color-text-muted);
    }

    /* Custom Modal */
    #custom-modal {
        display: none;
        position: fixed;
        z-index: 1000;
        left: 0;
        top: 0;
        width: 100%;
        height: 100%;
        overflow: auto;
        background-color: rgba(0, 0, 0, 0.5);
        justify-content: center;
        align-items: center;
    }

    #custom-modal-content {
        background-color: var(--color-surface);
        padding: 25px;
        border-radius: var(--border-radius-lg);
        box-shadow: var(--shadow-lg);
        max-width: 450px;
        width: 90%;
        text-align: center;
    }

    #custom-modal-message {
        font-size: 1.1em;
        margin-top: 0;
        margin-bottom: 1.5rem;
        line-height: 1.6;
    }

    #custom-modal-buttons {
        display: flex;
        justify-content: center;
        gap: 1rem;
    }

    /* Mobile Responsive (ลบการแก้ขนาดภายในบิลออก เพื่อคงสัดส่วน A4 เสมอ) */
    @media (max-width: 768px) {
        #bill-content-wrapper {
            padding: 8px;
            max-height: 75vh;
        }

        .bill-actions {
            flex-direction: column;
        }

        .bill-actions button {
            width: 100%;
            justify-content: center;
        }
    }
</style>

<div class="cash-bill-container">
    <h2><?= h($pageTitle) ?></h2>
    <div class="bill-form-section" id="cash-bill-input-form">
        <h3><i class="fas fa-edit"></i> กรอกข้อมูลสำหรับออกบิล</h3>

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
                <!-- ***** START: MODIFIED CUSTOMER NAME INPUT ***** -->
                <label for="bill_customer_company_name">ในนามบริษัท/ลูกค้า (พิมพ์เพื่อค้นหา):</label>
                <input type="text" id="bill_customer_company_name" class="form-control" list="customer-list" autocomplete="off">
                <datalist id="customer-list"></datalist>
                <!-- ***** END: MODIFIED CUSTOMER NAME INPUT ***** -->
            </div>
            <div class="form-group">
                <label for="bill_number_input">เลขที่เอกสาร (สำหรับบิลชั่วคราว):</label>
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
            <!-- ***** START: NEW BILL DATE FIELD ***** -->
            <div class="form-group">
                <label for="bill_date_input">วันที่ออกเอกสาร:</label>
                <input type="date" id="bill_date_input" value="<?= date('Y-m-d') ?>" class="form-control">
            </div>
            <!-- ***** END: NEW BILL DATE FIELD ***** -->
        </div>

        <div class="form-group" style="grid-column: 1 / -1; background-color: #fffbe6; padding: 0.75rem; border-radius: 5px; border: 1px solid #ffe58f; margin-top: 1rem;">
            <label class="checkbox-btn" for="holiday_surcharge_checkbox" style="display: flex; align-items: center; cursor: pointer;">
                <input type="checkbox" id="holiday_surcharge_checkbox" style="margin-right: 10px;">
                <span style="font-weight: bold;">คิดค่าบริการเพิ่มสำหรับวันหยุดนักขัตฤกษ์ (+100 บาท ต่อห้อง/คืน)</span>
            </label>
        </div>

        <hr style="margin: 1.5rem 0;">

        <div class="item-entry-form">
            <!-- ... existing item entry form ... -->
            <h4><i class="fas fa-plus-circle"></i> เพิ่มรายการในบิล</h4>
            <div id="item-type-selector">
                <button type="button" class="button outline-secondary" data-type="room">เพิ่มรายการห้องพัก</button>
                <button type="button" class="button outline-secondary" data-type="service">เพิ่มรายการบริการ/อื่นๆ</button>
            </div>

            <div id="room-fields" style="display:none; margin-top:1rem; border-top:1px dashed #ccc; padding-top:1rem;">
                <div class="form-grid" style="grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));">
                    <div class="form-group" style="grid-column: 1 / -1;"><label for="bill_room_select">เลือกห้องพัก:</label><select id="bill_room_select" class="form-control">
                            <option value="">-- เลือกห้อง --</option><?php foreach ($all_rooms_for_bill as $room): ?><option value="<?= h($room['id']) ?>" data-price="<?= h($room['price_per_day']) ?>" data-zone="<?= h($room['zone']) ?>"><?= h($room['zone'] . $room['room_number']) ?> (<?= h(number_format((float)$room['price_per_day'], 0)) ?> บ./คืน)</option><?php endforeach; ?>
                        </select></div>
                    <div class="form-group"><label for="bill_room_nights">จำนวนคืน:</label><input type="number" id="bill_room_nights" value="1" min="1" class="form-control"></div>

                    <div class="form-group"><label for="bill_room_price">ราคา/คืน (แก้ไขได้):</label><input type="number" id="bill_room_price" step="any" min="0" class="form-control"></div>

                    <div class="form-group"><label for="bill_checkin_date">วันที่เช็คอิน:</label><input type="date" id="bill_checkin_date" value="<?= date('Y-m-d') ?>" class="form-control"></div>
                    <div class="form-group"><label for="bill_checkout_date">วันที่เช็คเอาท์:</label><input type="date" id="bill_checkout_date" value="<?= date('Y-m-d', strtotime('+1 day')) ?>" class="form-control"></div>
                </div>
                <button type="button" id="add-room-to-bill-btn" class="button primary" style="margin-top: 0.5rem;">เพิ่มห้องนี้</button>
            </div>

            <div id="service-fields" style="display:none; margin-top:1rem; border-top:1px dashed #ccc; padding-top:1rem;">
                <div class="form-grid">
                    <div class="form-group"><label for="bill_service_select">เลือกบริการ (ถ้ามี):</label><select id="bill_service_select" class="form-control">
                            <option value="">-- หรือพิมพ์รายการเอง --</option><?php foreach ($active_addons_for_bill as $addon): ?><option value="<?= h($addon['id']) ?>" data-price="<?= h($addon['price']) ?>" data-name="<?= h($addon['name']) ?>"><?= h($addon['name']) ?></option><?php endforeach; ?>
                        </select></div>
                    <div class="form-group"><label for="bill_service_name">ชื่อรายการ:</label><input type="text" id="bill_service_name" class="form-control"></div>
                    <div class="form-group"><label for="bill_service_qty">จำนวน:</label><input type="number" id="bill_service_qty" value="1" min="1" class="form-control"></div>
                    <div class="form-group"><label for="bill_service_price">ราคา/หน่วย:</label><input type="number" id="bill_service_price" step="any" min="0" class="form-control"></div>
                </div>
                <button type="button" id="add-service-to-bill-btn" class="button primary" style="margin-top: 0.5rem;">เพิ่มรายการนี้</button>
            </div>
        </div>

        <h4><i class="fas fa-list-ul"></i> รายการทั้งหมดในบิล</h4>
        <div class="table-responsive">
            <!-- ... existing items table ... -->
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

        <!-- ... existing save receipt button section ... -->
        <div class="form-group" style="margin-top: 1.5rem; padding-top: 1.5rem; border-top: 1px solid var(--color-border); text-align: center;">
            <label for="payment_method_select">วิธีชำระเงิน:</label>
            <select id="payment_method_select" class="form-control" style="max-width: 250px; display: inline-block; margin-right: 1rem; vertical-align: middle;">
                <option value="Cash">เงินสด</option>
                <option value="Transfer">โอนชำระ</option>
                <option value="Credit Card">บัตรเครดิต</option>
            </select>
            <button type="button" id="confirm-save-receipt-btn" class="button primary" style="min-width: 200px; font-size: 1.1em; vertical-align: middle;">
                <i class="fas fa-save" style="margin-right: 8px;"></i> ยืนยันและบันทึกใบเสร็จ
            </button>
            <small class="input-hint" style="display: block; margin-top: 0.75rem;">การดำเนินการนี้จะบันทึกใบเสร็จลงในระบบอย่างถาวร (ไม่สามารถแก้ไขได้)</small>
        </div>
        <!-- ... -->
    </div>

    <div class="bill-preview-section">
        <h3><i class="fas fa-eye"></i> ตัวอย่างบิล / ใบแจ้งหนี้</h3>
        <div id="bill-content-wrapper">
            <div id="bill-content">
                <div class="classic-border-wrap">

                    <!-- ===== HEADER ===== -->
                    <div class="c-header">
                        <h2>ใบเสร็จรับเงิน / บิลเงินสด</h2>
                        <h3>โรงแรมภัทรรีสอร์ท</h3>
                        <p>ที่อยู่: <span class="c-dotted">119 / 2 ม.13 ต.โคกแย้ อ.หนองแค จ.สระบุรี 18230</span></p>
                        <p>โทร: <span class="c-dotted">089 -889 -5019 / 083 -879 -4469 / 064 -879 -4469</span></p>
                        <p>เลขประจำตัวผู้เสียภาษี: <span class="c-dotted">3260300408491</span></p>
                    </div>

                    <!-- ===== META ===== -->
                    <div class="c-meta">
                        <div>เลขที่: <span id="preview_bill_number"></span></div>
                        <div>วันที่: <span id="preview_bill_date"></span></div>
                    </div>
                    <div class="c-line"></div>

                    <!-- ===== CUSTOMER ===== -->
                    <div class="c-customer">
                        <p>นามลูกค้า/ บริษัท: <span id="preview_customer_name" class="c-dotted"></span></p>
                        <p>ที่อยู่: <span id="preview_customer_address" class="c-dotted"></span></p>
                        <p>เลขประจำตัวผู้เสียภาษี: <span id="preview_customer_tax_id" class="c-dotted"></span></p>
                    </div>

                    <!-- ===== DATES ===== -->
                    <div class="c-dates" id="preview_dates_section">
                        <p>วันที่เข้าพัก: <span id="preview_checkin_date"></span></p>
                        <p>วันที่ออก: <span id="preview_checkout_date"></span></p>
                    </div>

                    <!-- ===== ITEMS TABLE ===== -->
                    <table class="c-table">
                        <thead>
                            <tr>
                                <th style="text-align:left; width: 45%;">รายการ</th>
                                <th style="text-align:center; width: 15%;">จำนวน</th>
                                <th style="text-align:center; width: 20%;">ราคา/ หน่วย</th>
                                <th style="text-align:right; width: 20%;">จำนวนเงิน</th>
                            </tr>
                        </thead>
                        <tbody id="preview_line_items">
                            <tr>
                                <td colspan="4" style="text-align:center; color:#888; padding: 6mm;"><i>- ยังไม่มีรายการ -</i></td>
                            </tr>
                        </tbody>
                    </table>
                    <div class="c-table-bottom-line"></div>

                    <!-- ===== TOTAL ===== -->
                    <div class="c-total-wrap">
                        <div class="c-total-inner">
                            <span>ยอดรวมทั้งสิ้น:</span>
                            <span id="preview_grand_total">0.00</span>
                        </div>
                    </div>

                    <!-- ===== FOOTER ===== -->
                    <div class="c-footer">
                        <p class="thank-you">*** ขอขอบคุณที่ไว้วางใจใช้บริการ ***</p>
                        <div class="sig">
                            <p>ผู้รับเงิน</p>
                            <p class="sig-line">(....................................................................)</p>
                        </div>
                        <p class="auto-gen">เอกสารนี้ออกโดยระบบอัตโนมัติ - โรงแรมภัทรรีสอร์ท</p>
                    </div>

                </div>
            </div>
        </div>
            <button type="button" id="save-bill-as-image-btn" class="button secondary" disabled>
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <rect width="18" height="18" x="3" y="3" rx="2" ry="2" />
                    <circle cx="9" cy="9" r="2" />
                    <path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21" />
                </svg>
                บันทึกเป็นรูปภาพ
            </button>
            <button type="button" id="share-bill-btn" class="button info" disabled>
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="18" cy="5" r="3" />
                    <circle cx="6" cy="12" r="3" />
                    <circle cx="18" cy="19" r="3" />
                    <path d="m8.59 13.51 6.83 4.98" />
                    <path d="m15.41 6.49-6.83 4.98" />
                </svg>
                แชร์บิล
            </button>
            <button type="button" id="print-bill-btn" class="button alert" disabled>
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M6 18H4a2 2 0 0 1-2-2V9a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v7a2 2 0 0 1-2 2h-2" />
                    <path d="M18 14H6" />
                    <path d="M9 18V7h6v11" />
                </svg>
                สั่งพิมพ์
            </button>
        </div>
    </div>
</div>

        <div class="export-settings-container" style="margin-top: 1.5rem; margin-bottom: 1rem; padding: 0 1rem;">
            <details style="background: var(--color-surface-alt); border: 1px solid var(--color-border); border-radius: var(--border-radius-md); padding: 0.5rem 1rem;">
                <summary style="cursor: pointer; font-weight: bold; color: var(--color-primary-dark); padding: 0.5rem 0; list-style: none; display: flex; align-items: center; justify-content: space-between;">
                    <span><i class="fas fa-cog"></i> ตั้งค่าหน้ากระดาษก่อนพิมพ์/แชร์</span>
                    <i class="fas fa-chevron-down" style="font-size: 0.8em; transition: transform 0.3s;"></i>
                </summary>
                
                <div class="settings-content" style="padding-top: 1rem; border-top: 1px solid var(--color-border); margin-top: 0.5rem;">
                    <!-- Basic Settings -->
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-bottom: 1rem;">
                        <div class="form-group" style="margin: 0;">
                            <label for="export_paper_size" style="font-size: 0.9em;">ขนาดกระดาษ:</label>
                            <select id="export_paper_size" class="form-control form-control-sm" onchange="updateBillPreview()">
                                <option value="a4">A4 (210 x 297 mm)</option>
                                <option value="a3">A3 (297 x 420 mm)</option>
                            </select>
                        </div>
                        <div class="form-group" style="margin: 0;">
                            <label for="export_template_select" style="font-size: 0.9em;">รูปแบบเอกสาร:</label>
                            <select id="export_template_select" class="form-control form-control-sm" onchange="updateBillPreview()">
                                <option value="classic">แบบคลาสสิก (ขาวดำ มีกรอบ)</option>
                                <option value="detailed">แบบละเอียด (ตารางมีเส้นกั้นทุกช่อง)</option>
                            </select>
                        </div>
                    </div>

                    <!-- Advanced Settings inside another details tag -->
                    <details style="margin-top: 0.5rem;">
                        <summary style="cursor: pointer; font-size: 0.9em; color: var(--color-text-muted); opacity: 0.8;">
                            <i class="fas fa-sliders-h"></i> ตั้งค่าขั้นสูง
                        </summary>
                        <div style="padding: 0.75rem; background: rgba(0,0,0,0.02); border-radius: var(--border-radius-sm); margin-top: 0.5rem;">
                            <div class="form-group" style="margin: 0; max-width: 300px;">
                                <label for="export_font_size" style="font-size: 0.85em;">ขนาดตัวอักษรตั้งต้น:</label>
                                <select id="export_font_size" class="form-control form-control-sm" onchange="updateBillPreview()">
                                    <option value="small">เล็ก (10pt)</option>
                                    <option value="normal" selected>ปกติ (12pt)</option>
                                    <option value="large">ใหญ่ (14pt)</option>
                                </select>
                            </div>
                        </div>
                    </details>
                </div>
            </details>
        </div>
        
        <script>
            // Add slight animation to the chevron icon inside the details summary
            document.querySelector('.export-settings-container details summary').addEventListener('click', function() {
                const icon = this.querySelector('.fa-chevron-down');
                if (this.parentNode.open) {
                    icon.style.transform = 'rotate(0deg)';
                } else {
                    icon.style.transform = 'rotate(180deg)';
                }
            });
        </script>

<!-- ... existing custom modal ... -->
<div id="custom-modal">
    <div id="custom-modal-content">
        <p id="custom-modal-message"></p>
        <div id="custom-modal-buttons">
            <button id="custom-modal-btn-confirm" class="button primary">ยืนยัน</button>
            <button id="custom-modal-btn-cancel" class="button secondary">ยกเลิก</button>
        </div>
    </div>
</div>
<!-- ... -->


<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
<script>
    window.updateBillPreview = function() {
        const paperSize = document.getElementById('export_paper_size') ? document.getElementById('export_paper_size').value : 'a4';
        const fontSize = document.getElementById('export_font_size') ? document.getElementById('export_font_size').value : 'normal';
        const template = document.getElementById('export_template_select') ? document.getElementById('export_template_select').value : 'classic';
        const billContent = document.getElementById('bill-content');
        if (!billContent) return;

        // Apply Paper Size
        if (paperSize === 'a3') {
            billContent.style.width = '297mm';
            billContent.style.minWidth = '297mm';
            billContent.style.minHeight = '420mm';
        } else {
            billContent.style.width = '210mm';
            billContent.style.minWidth = '210mm';
            billContent.style.minHeight = '297mm';
        }

        // Apply Font Size
        let fontCssSize = '11pt'; // default
        if (fontSize === 'small') fontCssSize = '10pt';
        else if (fontSize === 'large') fontCssSize = '14pt';
        else fontCssSize = '12pt';
        billContent.style.fontSize = fontCssSize;

        // Apply Template Styles (WYSIWYG)
        const header = billContent.querySelector('.c-header');
        const customer = billContent.querySelector('.c-customer');
        const dates = billContent.querySelector('.c-dates');
        const _table = billContent.querySelector('.c-table');
        const totals = billContent.querySelector('.c-total-inner');
        const line = billContent.querySelector('.c-line');
        const tableBottomLine = billContent.querySelector('.c-table-bottom-line');
        const wrap = billContent.querySelector('.classic-border-wrap');

        if (template === 'detailed') {
            if (wrap) { wrap.style.border = 'none'; wrap.style.padding = '0'; wrap.style.minHeight = (paperSize === 'a3' ? '410mm' : '287mm'); }
            if (header) { header.style.borderBottom = '2px solid #2c3e50'; header.style.paddingBottom = '5mm'; }
            if (customer) { customer.style.background = '#fff'; customer.style.borderRadius = '4px'; customer.style.border = '1px solid #dee2e6'; }
            if (dates) { dates.style.background = '#e9ecef'; dates.style.borderRadius = '4px'; dates.style.border = '1px solid #ced4da'; dates.style.display = 'flex'; dates.style.gap = '20px'; }
            if (_table) { 
                _table.style.border = '1px solid #adb5bd'; 
                _table.querySelectorAll('th').forEach(th => { th.style.background = '#e9ecef'; th.style.border = '1px solid #adb5bd'; th.style.color = '#000'; });
                _table.querySelectorAll('td').forEach(td => { td.style.border = '1px solid #adb5bd'; th.style.color = '#000'; });
            }
            if (totals) { totals.style.background = '#e9ecef'; totals.style.borderRadius = '4px'; totals.style.border = '1px solid #adb5bd'; totals.style.borderBottom = '1px solid #adb5bd'; totals.style.padding = '10px 15px'; }
            if (line) { line.style.display = 'none'; }
            if (tableBottomLine) { tableBottomLine.style.display = 'none'; }
        } else {
            // Classic
            if (wrap) { wrap.style.border = '1px solid #000'; wrap.style.padding = '10mm 12mm'; wrap.style.minHeight = (paperSize === 'a3' ? '390mm' : '267mm'); }
            if (header) { header.style.borderBottom = 'none'; header.style.paddingBottom = '0'; }
            if (customer) { customer.style.background = 'transparent'; customer.style.borderRadius = '0'; customer.style.border = '1px solid #000'; }
            if (dates) { dates.style.background = 'transparent'; dates.style.borderRadius = '0'; dates.style.border = 'none'; dates.style.display = 'block'; dates.style.gap = '0'; }
            if (_table) { 
                _table.style.border = 'none'; 
                _table.querySelectorAll('th').forEach(th => { th.style.background = 'transparent'; th.style.borderTop = '1px solid #000'; th.style.borderBottom = '1px solid #000'; th.style.borderLeft = 'none'; th.style.borderRight = 'none'; });
                _table.querySelectorAll('td').forEach(td => { td.style.border = 'none'; });
            }
            if (totals) { totals.style.background = 'transparent'; totals.style.borderRadius = '0'; totals.style.border = 'none'; totals.style.borderBottom = '3px double #000'; totals.style.padding = '0 2mm 2mm 0'; }
            if (line) { line.style.display = 'block'; }
            if (tableBottomLine) { tableBottomLine.style.display = 'block'; }
        }
    };

    document.addEventListener('DOMContentLoaded', function() {
        // --- Element Selectors ---
        // ... existing selectors ...
        const groupSelect = document.getElementById('select_booking_group');
        const itemTypeSelector = document.getElementById('item-type-selector');
        const roomFields = document.getElementById('room-fields');
        const serviceFields = document.getElementById('service-fields');
        const roomSelect = document.getElementById('bill_room_select');
        const nightsInput = document.getElementById('bill_room_nights');
        const checkinDateInput = document.getElementById('bill_checkin_date');
        const checkoutDateInput = document.getElementById('bill_checkout_date');
        const roomPriceInput = document.getElementById('bill_room_price');
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
        const billDateInput = document.getElementById('bill_date_input');
        const saveAsImageBtn = document.getElementById('save-bill-as-image-btn');
        const shareBillBtn = document.getElementById('share-bill-btn');
        const printBillBtn = document.getElementById('print-bill-btn');
        const holidaySurchargeCheckbox = document.getElementById('holiday_surcharge_checkbox');

        // ***** START: NEW RECEIPT ELEMENTS *****
        // ... existing selectors ...
        const saveReceiptBtn = document.getElementById('confirm-save-receipt-btn');
        const paymentMethodSelect = document.getElementById('payment_method_select');

        // ***** START: NEW CUSTOMER SEARCH ELEMENTS *****
        const customerDatalist = document.getElementById('customer-list');
        let customerCache = []; // To store search results
        let searchTimeout;
        // ***** END: NEW CUSTOMER SEARCH ELEMENTS *****


        let billItems = [];

        // --- Custom Modal Logic (Replaces alert/confirm) ---
        // ... existing modal logic ...
        const modal = document.getElementById('custom-modal');
        const modalMsg = document.getElementById('custom-modal-message');
        const modalConfirmBtn = document.getElementById('custom-modal-btn-confirm');
        const modalCancelBtn = document.getElementById('custom-modal-btn-cancel');

        let modalConfirmCallback = null;
        let modalCancelCallback = null;

        function showCustomConfirm(message, onConfirm, onCancel = null) {
            modalMsg.innerHTML = message; // Use innerHTML to allow line breaks
            modalConfirmBtn.textContent = 'ยืนยัน';
            modalConfirmBtn.style.display = 'inline-block';
            modalCancelBtn.style.display = 'inline-block';

            modalConfirmCallback = onConfirm;
            modalCancelCallback = onCancel;

            modal.style.display = 'flex';
        }

        function showCustomAlert(message, onOk = null) {
            modalMsg.innerHTML = message.replace(/\n/g, '<br>'); // Replace newlines with <br>
            modalConfirmBtn.style.display = 'inline-block';
            modalConfirmBtn.textContent = 'ตกลง';
            modalCancelBtn.style.display = 'none';

            modalConfirmCallback = () => {
                if (onOk) onOk();
                hideModal(); // Default action is just to close
            };
            modalCancelCallback = null; // No cancel action

            modal.style.display = 'flex';
        }

        function hideModal() {
            modal.style.display = 'none';
            modalConfirmBtn.textContent = 'ยืนยัน'; // Reset button text
            modalConfirmCallback = null;
            modalCancelCallback = null;
        }

        modalConfirmBtn.addEventListener('click', () => {
            if (modalConfirmCallback) {
                modalConfirmCallback();
            }
            // If it's an alert (cancel hidden), close modal on confirm
            if (modalCancelBtn.style.display === 'none') {
                hideModal();
            }
        });

        modalCancelBtn.addEventListener('click', () => {
            if (modalCancelCallback) {
                modalCancelCallback();
            }
            hideModal();
        });
        // --- End Custom Modal Logic ---


        // --- Event Listeners ---
        // ... existing listeners ...
        itemTypeSelector.addEventListener('click', function(e) {
            if (e.target.tagName === 'BUTTON') {
                const type = e.target.dataset.type;
                roomFields.style.display = type === 'room' ? 'block' : 'none';
                serviceFields.style.display = type === 'service' ? 'block' : 'none';
            }
        });

        roomSelect.addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            if (selectedOption.value && roomPriceInput) {
                roomPriceInput.value = parseFloat(selectedOption.dataset.price || 0).toFixed(2);
            } else if (roomPriceInput) {
                roomPriceInput.value = '';
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
                showCustomAlert('กรุณากรอกข้อมูลรายการบริการให้ครบถ้วนและถูกต้อง (ราคาต้องเป็นตัวเลข)');
                return;
            }
            billItems.push({
                id: `service-${Date.now()}`,
                type: 'service',
                description: name,
                quantity: qty,
                unitPrice: price
            });
            renderAllItems();
            serviceNameInput.value = '';
            serviceQtyInput.value = '1';
            servicePriceInput.value = '';
            serviceSelect.value = '';
        });

        addRoomBtn.addEventListener('click', function() {
            const roomId = roomSelect.value;
            const nights = parseInt(nightsInput.value);
            const checkin = checkinDateInput.value;
            const checkout = checkoutDateInput.value;
            const pricePerNight = parseFloat(roomPriceInput.value);

            if (!roomId || isNaN(nights) || nights < 1 || !checkin || !checkout || isNaN(pricePerNight) || pricePerNight < 0) {
                showCustomAlert('กรุณาเลือกห้องพัก, จำนวนคืน, วันที่, และราคาต่อคืนให้ถูกต้อง');
                return;
            }
            const selectedRoomOption = roomSelect.options[roomSelect.selectedIndex];
            const roomName = selectedRoomOption.text.split(' (')[0];

            billItems.push({
                id: `room-${Date.now()}`,
                type: 'room',
                description: `ค่าห้องพัก ${roomName}`,
                quantity: nights,
                unitPrice: pricePerNight,
                checkin: checkin,
                checkout: checkout
            });
            renderAllItems();
        });

        addedItemsTableBody.addEventListener('click', function(e) {
            // ... existing editable cell logic ...
            const cell = e.target.closest('.editable-cell');
            if (!cell || cell.querySelector('input')) return;

            const row = cell.closest('tr');
            const itemId = row.querySelector('.remove-item-btn').dataset.itemId;
            const fieldToEdit = cell.dataset.field;
            const itemIndex = billItems.findIndex(item => item.id === itemId);
            if (itemIndex === -1) return;

            const originalValue = billItems[itemIndex][fieldToEdit];
            cell.innerHTML = '';
            const input = document.createElement('input');
            input.type = 'number';
            input.value = originalValue;
            input.className = 'form-control';
            input.style.width = '100%';
            input.style.textAlign = 'right';
            cell.appendChild(input);
            input.focus();
            input.select();

            const saveChange = () => {
                const newValue = parseFloat(input.value);
                if (!isNaN(newValue) && newValue >= 0) {
                    billItems[itemIndex][fieldToEdit] = newValue;
                }
                renderAllItems();
            };

            input.addEventListener('blur', saveChange);
            input.addEventListener('keydown', (e) => {
                if (e.key === 'Enter') saveChange();
                else if (e.key === 'Escape') renderAllItems();
            });
        });

        holidaySurchargeCheckbox.addEventListener('change', renderAllItems);

        [customerAddressInput, customerTaxIdInput, billNumberInputEl, billDateInput].forEach(input => input.addEventListener('input', updatePreview));
        // Remove customerNameInput from the simple updatePreview listener
        customerNameInput.addEventListener('input', updatePreview); // Keep this for live preview

        checkinDateInput.addEventListener('change', calculateCheckoutDate);
        nightsInput.addEventListener('input', calculateCheckoutDate);

        groupSelect.addEventListener('change', async function() {
            // ... existing group select logic ...
            const groupId = this.value;
            if (!groupId) {
                billItems = [];
                customerNameInput.value = '';
                customerAddressInput.value = '';
                customerTaxIdInput.value = '';
                renderAllItems();
                return;
            }
            try {
                const response = await fetch(`/hotel_booking/pages/api.php?action=get_group_details_for_bill&booking_group_id=${groupId}`);
                const data = await response.json();
                if (data.success) {
                    customerNameInput.value = data.group_info.customer_name || '';
                    billItems = data.bookings.map(booking => ({
                        id: `room-${booking.id}`,
                        type: 'room',
                        description: `ค่าห้องพัก ${booking.zone}${booking.room_number}`,
                        quantity: parseInt(booking.nights, 10),
                        unitPrice: parseFloat(booking.price_per_night),
                        checkin: booking.checkin_datetime.split(' ')[0],
                        checkout: booking.checkout_datetime_calculated.split(' ')[0]
                    }));
                    renderAllItems();
                } else {
                    showCustomAlert('Error: ' + data.message);
                }
            } catch (error) {
                console.error('Error:', error);
                showCustomAlert('เกิดข้อผิดพลาดในการเชื่อมต่อ');
            }
        });

        // --- Core Functions ---
        function renderAllItems() {
            // ... existing renderAllItems logic ...
            addedItemsTableBody.innerHTML = '';
            let grandTotal = 0;
            const isHoliday = holidaySurchargeCheckbox.checked;
            const holidaySurchargeAmount = 100;

            if (billItems.length === 0) {
                addedItemsTableBody.innerHTML = '<tr><td colspan="5" class="text-center text-muted"><i>- ยังไม่มีรายการ -</i></td></tr>';
            } else {
                billItems.forEach(item => {
                    let currentItemTotal = item.quantity * item.unitPrice;
                    let displayDescription = item.description;

                    if (item.type === 'room' && isHoliday) {
                        currentItemTotal += item.quantity * holidaySurchargeAmount;
                        displayDescription += '';
                    }

                    const row = addedItemsTableBody.insertRow();
                    row.insertCell().textContent = displayDescription;

                    const qtyCell = row.insertCell();
                    qtyCell.textContent = item.quantity;
                    qtyCell.className = 'number-cell editable-cell';
                    qtyCell.dataset.field = 'quantity';

                    const unitPriceCell = row.insertCell();
                    unitPriceCell.textContent = item.unitPrice.toLocaleString('th-TH', {
                        minimumFractionDigits: 2,
                        maximumFractionDigits: 2
                    });
                    unitPriceCell.className = 'number-cell editable-cell';
                    unitPriceCell.dataset.field = 'unitPrice';

                    const totalCell = row.insertCell();
                    totalCell.textContent = currentItemTotal.toLocaleString('th-TH', {
                        minimumFractionDigits: 2,
                        maximumFractionDigits: 2
                    });
                    totalCell.className = 'number-cell';

                    const actionCell = row.insertCell();
                    actionCell.className = 'action-cell';
                    const removeBtn = document.createElement('button');
                    removeBtn.textContent = 'ลบ';
                    removeBtn.className = 'button-small alert remove-item-btn';
                    removeBtn.dataset.itemId = item.id;
                    removeBtn.onclick = function() {
                        billItems = billItems.filter(bItem => bItem.id !== this.dataset.itemId);
                        renderAllItems();
                    };
                    actionCell.appendChild(removeBtn);
                    grandTotal += currentItemTotal;
                });
            }
            billGrandTotalSpan.textContent = grandTotal.toLocaleString('th-TH', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
            updatePreview();
            updateActionButtonsState();
        }

        function updatePreview() {
            // --- Basic fields ---
            previewBillNumber.textContent = billNumberInputEl.value || 'N/A';
            const thaiDateStr = toThaiDateForJS(billDateInput.value || new Date());
            previewBillDate.textContent = thaiDateStr;

            previewCustomerName.textContent = customerNameInput.value.trim() || '-';
            previewCustomerAddress.textContent = customerAddressInput.value.trim() || '-';
            previewCustomerTaxId.textContent = customerTaxIdInput.value.trim() || '-';

            // --- Line Items ---
            previewLineItemsBody.innerHTML = '';
            let currentPreviewGrandTotal = 0;
            let overallMinCheckin = null;
            let overallMaxCheckout = null;
            const isHoliday = holidaySurchargeCheckbox.checked;
            const holidaySurchargeAmount = 100;

            if (billItems.length === 0) {
                previewLineItemsBody.innerHTML = '<tr><td colspan="4" style="text-align:center; color:#888; padding: 6mm;"><i>- ยังไม่มีรายการ -</i></td></tr>';
            } else {
                billItems.forEach((item) => {
                    let itemTotalForPreview = item.quantity * item.unitPrice;
                    let descriptionForPreview = item.description;
                    const unitLabel = item.type === 'room' ? 'คืน' : 'หน่วย';

                    if (item.type === 'room') {
                        try {
                            const checkin = new Date(item.checkin + 'T00:00:00');
                            const checkout = new Date(item.checkout + 'T00:00:00');
                            if (!isNaN(checkin.getTime())) {
                                if (!overallMinCheckin || checkin < overallMinCheckin) overallMinCheckin = checkin;
                            }
                            if (!isNaN(checkout.getTime())) {
                                if (!overallMaxCheckout || checkout > overallMaxCheckout) overallMaxCheckout = checkout;
                            }
                        } catch (e) {
                            console.error("Invalid date in bill item: ", item);
                        }

                        if (isHoliday) {
                            itemTotalForPreview += item.quantity * holidaySurchargeAmount;
                        }
                    }

                    const row = previewLineItemsBody.insertRow();

                    // 1. รายการ (Left)
                    const descCell = row.insertCell(0);
                    descCell.textContent = descriptionForPreview;
                    descCell.style.textAlign = 'left';

                    // 2. จำนวน (Center, รวมหน่วย)
                    const qtyCell = row.insertCell(1);
                    qtyCell.textContent = `${item.quantity} ${unitLabel}`;
                    qtyCell.style.textAlign = 'center';

                    // 3. ราคา/หน่วย (Center)
                    const upCell = row.insertCell(2);
                    upCell.textContent = item.unitPrice.toLocaleString('th-TH', {
                        minimumFractionDigits: 2,
                        maximumFractionDigits: 2
                    });
                    upCell.style.textAlign = 'center';

                    // 4. จำนวนเงิน (Right)
                    const totCell = row.insertCell(3);
                    totCell.textContent = itemTotalForPreview.toLocaleString('th-TH', {
                        minimumFractionDigits: 2,
                        maximumFractionDigits: 2
                    });
                    totCell.style.textAlign = 'right';

                    currentPreviewGrandTotal += itemTotalForPreview;
                });
            }

            // Checkin/checkout display
            const datesSection = document.getElementById('preview_dates_section');
            if (overallMinCheckin && overallMaxCheckout) {
                previewCheckinDate.textContent = toThaiDateForJS(overallMinCheckin);
                previewCheckoutDate.textContent = toThaiDateForJS(overallMaxCheckout);
                if (datesSection) datesSection.style.display = 'block';
            } else {
                if (datesSection) datesSection.style.display = 'none';
            }

            // Totals
            previewGrandTotal.textContent = currentPreviewGrandTotal.toLocaleString('th-TH', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
        }

        // --- Convert amount to Thai words ---
        function amountToThaiWords(amount) {
            const ones = ['', 'หนึ่ง', 'สอง', 'สาม', 'สี่', 'ห้า', 'หก', 'เจ็ด', 'แปด', 'เก้า'];
            const positions = ['', 'สิบ', 'ร้อย', 'พัน', 'หมื่น', 'แสน', 'ล้าน'];
            const int = Math.round(amount);
            if (int === 0) return 'ศูนย์';
            const s = String(int);
            let words = '';
            const len = s.length;
            for (let i = 0; i < len; i++) {
                const d = parseInt(s[i]);
                const pos = len - 1 - i;
                if (d === 0) continue;
                if (d === 1 && pos % 6 === 1) words += 'สิบ'; // สิบ not หนึ่งสิบ
                else if (d === 2 && pos % 6 === 1) words += 'ยี่สิบ';
                else words += ones[d] + positions[pos % 6];
                if (pos === 6) words += 'ล้าน';
            }
            return words || 'ศูนย์';
        }

        function calculateCheckoutDate() {
            // ... existing calculateCheckoutDate logic ...
            if (!checkinDateInput.value || !nightsInput.value) {
                checkoutDateInput.value = '';
                return;
            }
            try {
                const checkin = new Date(checkinDateInput.value);
                const nights = parseInt(nightsInput.value);
                if (isNaN(checkin.getTime()) || isNaN(nights) || nights < 1) {
                    checkoutDateInput.value = '';
                    return;
                }
                const checkout = new Date(checkin);
                checkout.setDate(checkin.getDate() + nights);
                checkoutDateInput.value = checkout.toISOString().split('T')[0];
            } catch (e) {
                checkoutDateInput.value = '';
            }
        }

        function toThaiDateForJS(dateInput) {
            // ... existing toThaiDateForJS logic ...
            if (!dateInput) return 'N/A';
            const date = new Date(dateInput);
            if (isNaN(date.getTime())) {
                // Try to parse YYYY-MM-DD manually
                const parts = String(dateInput).split('-');
                if (parts.length === 3) {
                    date = new Date(parseInt(parts[0]), parseInt(parts[1]) - 1, parseInt(parts[2]));
                } else {
                    return 'N/A';
                }
            }
            if (isNaN(date.getTime())) return 'N/A';

            const thaiMonths = ["มกราคม", "กุมภาพันธ์", "มีนาคม", "เมษายน", "พฤษภาคม", "มิถุนายน", "กรกฎาคม", "สิงหาคม", "กันยายน", "ตุลาคม", "พฤศจิกายน", "ธันวาคม"];
            return `${date.getDate()} ${thaiMonths[date.getMonth()]} ${date.getFullYear() + 543}`;
        }

        function updateActionButtonsState() {
            // ... existing updateActionButtonsState logic ...
            const hasItems = billItems.length > 0;
            saveAsImageBtn.disabled = !hasItems;
            shareBillBtn.disabled = !hasItems;
            printBillBtn.disabled = !hasItems;
            // Also enable/disable the new save button
            saveReceiptBtn.disabled = !hasItems;
        }

        // ***** START: NEW CUSTOMER SEARCH FUNCTIONS *****
        async function searchCustomers() {
            const searchTerm = customerNameInput.value.trim();

            if (searchTerm.length < 2) {
                customerDatalist.innerHTML = '';
                customerCache = [];
                return;
            }

            try {
                const response = await fetch(`/hotel_booking/pages/cash_bill.php?action=search_customers&term=${encodeURIComponent(searchTerm)}`);
                if (!response.ok) {
                    throw new Error('Search request failed');
                }
                const customers = await response.json();
                customerCache = customers; // Store results

                customerDatalist.innerHTML = ''; // Clear old options
                customers.forEach(cust => {
                    const option = document.createElement('option');
                    option.value = cust.customer_name;
                    // Add extra info to display, though datalist support varies
                    option.textContent = cust.customer_tax_id ? `${cust.customer_name} (${cust.customer_tax_id})` : cust.customer_name;
                    customerDatalist.appendChild(option);
                });
            } catch (error) {
                console.error('Error searching customers:', error);
                customerCache = [];
            }
        }

        function populateCustomerDetails() {
            const selectedName = customerNameInput.value;
            const selectedCustomer = customerCache.find(cust => cust.customer_name === selectedName);

            if (selectedCustomer) {
                customerAddressInput.value = selectedCustomer.customer_address || '';
                customerTaxIdInput.value = selectedCustomer.customer_tax_id || '';

                // Trigger update for preview
                updatePreview();
            }
        }

        // Add listeners for customer search
        customerNameInput.addEventListener('input', () => {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(searchCustomers, 300); // Debounce search
        });
        customerNameInput.addEventListener('change', populateCustomerDetails); // Use 'change' for when user selects from datalist
        // ***** END: NEW CUSTOMER SEARCH FUNCTIONS *****


        // ***** START: NEW SAVE RECEIPT LISTENER *****
        saveReceiptBtn.addEventListener('click', async function() {
            // ... existing saveReceiptBtn logic ...
            if (billItems.length === 0) {
                showCustomAlert('ไม่สามารถบันทึกได้: ยังไม่มีรายการในใบเสร็จ');
                return;
            }

            // 1. Gather all data
            const grandTotal = parseFloat(billGrandTotalSpan.textContent.replace(/[^0-9.-]+/g, ""));
            const dataToSave = {
                bill_number: billNumberInputEl.value,
                bill_date: billDateInput.value,
                customer_name: customerNameInput.value,
                customer_address: customerAddressInput.value,
                customer_tax_id: customerTaxIdInput.value,
                payment_method: paymentMethodSelect.value,
                booking_group_id: groupSelect.value || null,
                line_items: billItems, // The full billItems array
                grand_total: grandTotal,
                is_holiday_surcharge: holidaySurchargeCheckbox.checked
                // The server will serialize this whole object into `receipt_data_json`
            };

            // 2. Confirm with the user
            showCustomConfirm(
                `ยืนยันการบันทึกใบเสร็จ?<br><br><strong>วันที่:</strong> ${toThaiDateForJS(dataToSave.bill_date)}<br><strong>ลูกค้า:</strong> ${dataToSave.customer_name || '-'}<br><strong>ยอดรวม:</strong> ${grandTotal.toLocaleString('th-TH')} บาท<br><br>การดำเนินการนี้จะสร้างใบเสร็จถาวรในระบบ และไม่สามารถแก้ไขได้<br><br><strong style="color: var(--color-primary);">ข้อมูลลูกค้า/บริษัทนี้ จะถูกบันทึกไว้ใช้ในอนาคต</strong>`,
                async () => { // onConfirm callback
                        hideModal(); // Hide confirmation modal first

                        const buttonId = 'confirm-save-receipt-btn';
                        if (typeof setButtonLoading === 'function') setButtonLoading(saveReceiptBtn, true, buttonId);

                        try {
                            // 3. Send to API (the top of this same file)
                            const response = await fetch('/hotel_booking/pages/cash_bill.php?action=save_receipt', {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json',
                                    'Accept': 'application/json'
                                },
                                body: JSON.stringify(dataToSave)
                            });

                            const result = await response.json();

                            if (response.ok && result.success) {
                                showCustomAlert(`บันทึกใบเสร็จสำเร็จ!\nเลขที่ใบเสร็จใหม่ของระบบ: ${result.receipt_number}`);
                                // Optionally, disable the button to prevent double-save
                                saveReceiptBtn.disabled = true;
                                saveReceiptBtn.innerHTML = `<i class="fas fa-check-circle"></i> บันทึกใบเสร็จแล้ว (${result.receipt_number})`;
                                // Update preview with the new permanent number
                                previewBillNumber.textContent = result.receipt_number;
                                billNumberInputEl.value = result.receipt_number;
                            } else {
                                throw new Error(result.message || 'ไม่สามารถบันทึกข้อมูลได้');
                            }

                        } catch (error) {
                            console.error('Error saving receipt:', error);
                            showCustomAlert('เกิดข้อผิดพลาดในการบันทึกใบเสร็จ: ' + error.message);
                        } finally {
                            // 4. Hide loading state
                            if (typeof setButtonLoading === 'function') setButtonLoading(saveReceiptBtn, false, buttonId);
                        }
                    },
                    () => {
                        // onCancel callback
                        hideModal();
                    }
            ); // end showCustomConfirm
        });
        // ***** END: NEW SAVE RECEIPT LISTENER *****

        // --- Action Button Listeners (Print, Save, Share) ---

        const exportPaperSizeSelect = document.getElementById('export_paper_size');
        const exportTemplateSelect = document.getElementById('export_template_select');
        const exportFontSizeSelect = document.getElementById('export_font_size');

        printBillBtn.addEventListener('click', function() {
            if (billItems.length === 0) {
                showCustomAlert('ไม่สามารถสั่งพิมพ์ได้: ยังไม่มีรายการในบิล');
                return;
            }
            const selectedTemplate = exportTemplateSelect.value;
            const selectedPaperSize = exportPaperSizeSelect.value;
            const selectedFontSize = exportFontSizeSelect.value;
            executePrint(selectedTemplate, selectedPaperSize, selectedFontSize);
        });

        // ฟังก์ชันสร้างหน้าจอพิมพ์
        function executePrint(templateType, paperSize, fontSize) {
            const billContentNode = document.getElementById('bill-content');
            if (!billContentNode) {
                showCustomAlert('ผิดพลาด: ไม่พบเนื้อหาสำหรับพิมพ์');
                return;
            }

            // Paper Size Variables
            let widthStr = '210mm';
            let minHeightStr = '297mm';
            let paperCssSize = 'A4 portrait';
            
            if (paperSize === 'a3') {
                widthStr = '297mm';
                minHeightStr = '420mm';
                paperCssSize = 'A3 portrait';
            }

            // Font Size Variables
            let fontCssSize = '12pt'; // normal
            if (fontSize === 'small') fontCssSize = '10pt';
            else if (fontSize === 'large') fontCssSize = '14pt';

            // CSS พื้นฐาน
            const baseCSS = `
                body { font-family: 'Sarabun', sans-serif; margin: 0; padding: 0; background: #fff; color: #000; -webkit-print-color-adjust: exact; print-color-adjust: exact; font-size: ${fontCssSize}; box-sizing: border-box; }
                @page { size: ${paperCssSize}; margin: 0; }
                * { box-sizing: border-box; }
            `;

            let specificCSS = '';

            if (templateType === 'classic') {
                // รูปแบบคลาสสิก (เหมือนหน้าจอเป๊ะ)
                specificCSS = `
                    .classic-border-wrap { border: 1px solid #000; padding: 10mm 12mm; height: 100%; min-height: 267mm; display: flex; flex-direction: column; box-sizing: border-box; }
                    .c-header { text-align: center; margin-bottom: 5mm; }
                    .c-header h2 { font-size: 1.5em; font-weight: bold; margin: 0 0 2mm 0; }
                    .c-header h3 { font-size: 1.4em; font-weight: bold; margin: 0 0 3mm 0; }
                    .c-header p { margin: 1.5mm 0; font-size: 1.05em; }
                    .c-dotted { text-decoration: underline; text-decoration-style: dotted; text-underline-offset: 4px; text-decoration-thickness: 1.5px; }
                    .c-meta { display: flex; justify-content: space-between; margin-top: 4mm; margin-bottom: 2mm; font-size: 1em; }
                    .c-line { border-top: 1px solid #000; margin-bottom: 4mm; }
                    .c-customer { border: 1px solid #000; padding: 4mm 5mm; margin-bottom: 6mm; font-size: 1.05em; }
                    .c-customer p { margin: 2.5mm 0; line-height: 1.6; }
                    .c-dates { font-size: 1.1em; margin-bottom: 6mm; font-weight: bold; }
                    .c-dates p { margin: 3mm 0; }
                    .c-table { width: 100%; border-collapse: collapse; margin-bottom: 2mm; font-size: 1em; }
                    .c-table th { border-top: 1px solid #000; border-bottom: 1px solid #000; padding: 3mm 1mm; font-weight: bold; }
                    .c-table td { padding: 3mm 1mm; vertical-align: top; }
                    .c-table-bottom-line { border-top: 1px solid #000; margin-bottom: 5mm; }
                    .c-total-wrap { text-align: right; font-weight: bold; font-size: 1.2em; padding-top: 2mm; margin-bottom: 15mm;}
                    .c-total-inner { display: inline-block; border-bottom: 3px double #000; padding-bottom: 2mm; padding-right: 2mm;}
                    .c-total-inner span:first-child { display: inline-block; width: 40mm; text-align: right; margin-right: 6mm;}
                    .c-total-inner span:last-child { display: inline-block; width: 25mm; text-align: right;}
                    .c-footer { text-align: center; margin-top: auto; padding-top: 10mm;}
                    .c-footer .thank-you { font-weight: bold; font-size: 1.2em; margin-bottom: 15mm; letter-spacing: 1px;}
                    .c-footer .sig { margin-bottom: 8mm; font-size: 1em;}
                    .c-footer .sig-line { margin-top: 8mm; color: #333;}
                    .c-footer .auto-gen { font-size: 0.9em; color: #555; font-weight: bold;}
                `;
            } else if (templateType === 'detailed') {
                // รูปแบบละเอียด (ตารางเต็มรูปแบบ)
                specificCSS = `
                    .classic-border-wrap { border: none; padding: 0; height: 100%; min-height: 267mm; display: flex; flex-direction: column; box-sizing: border-box; }
                    .c-header { text-align: center; margin-bottom: 8mm; border-bottom: 2px solid #2c3e50; padding-bottom: 5mm;}
                    .c-header h2 { font-size: 1.8em; font-weight: bold; margin: 0 0 2mm 0; color: #2c3e50; }
                    .c-header h3 { font-size: 1.4em; font-weight: bold; margin: 0 0 3mm 0; color: #34495e;}
                    .c-header p { margin: 1mm 0; font-size: 1em; color: #555;}
                    .c-dotted { font-weight: bold; color: #000; }
                    .c-meta { display: flex; justify-content: space-between; margin-bottom: 5mm; font-size: 1em; background: #f8f9fa; padding: 8px; border: 1px solid #dee2e6;}
                    .c-line { display: none; }
                    .c-customer { border: 1px solid #dee2e6; padding: 10px; margin-bottom: 6mm; font-size: 1.05em; background: #fff; border-radius: 4px;}
                    .c-customer p { margin: 2mm 0; }
                    .c-dates { display: flex; gap: 20px; font-size: 1em; margin-bottom: 6mm; background: #e9ecef; padding: 8px; border-radius: 4px; border: 1px solid #ced4da;}
                    .c-dates p { margin: 0; }
                    .c-table { width: 100%; border-collapse: collapse; margin-bottom: 5mm; font-size: 1em; border: 1px solid #adb5bd;}
                    .c-table th { background-color: #e9ecef; border: 1px solid #adb5bd; padding: 8px 5px; font-weight: bold; text-align: center !important; color: #000;}
                    .c-table td { border: 1px solid #adb5bd; padding: 8px 5px; vertical-align: top; color: #000;}
                    .c-table-bottom-line { display: none; }
                    .c-total-wrap { text-align: right; font-weight: bold; font-size: 1.2em; margin-bottom: 15mm;}
                    .c-total-inner { display: inline-block; background-color: #e9ecef; border: 1px solid #adb5bd; padding: 10px 15px; border-radius: 4px;}
                    .c-total-inner span:first-child { margin-right: 15px; }
                    .c-footer { display: flex; justify-content: space-between; align-items: flex-end; margin-top: auto; border-top: 1px solid #dee2e6; padding-top: 10mm;}
                    .c-footer .thank-you { font-weight: bold; font-size: 1.1em; color: #2c3e50;}
                    .c-footer .sig { text-align: center; }
                    .c-footer .sig-line { margin-top: 10mm; color: #000; border-top: 1px dotted #000; padding-top: 2mm; width: 50mm;}
                    .c-footer .auto-gen { position: absolute; bottom: 5mm; right: 15mm; font-size: 0.8em; color: #adb5bd; }
                `;
            }

            const printWindow = window.open('', '_blank', 'width=880,height=900,scrollbars=yes,resizable=yes');
            printWindow.document.write(`
                <html>
                <head>
                    <title>พิมพ์บิลเงินสด</title>
                    <link rel="preconnect" href="https://fonts.googleapis.com">
                    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
                    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;700;800&display=swap" rel="stylesheet">
                    <style>
                        ${baseCSS}
                        ${specificCSS}
                    </style>
                </head>
                <body>
                    <div id="bill-content">${billContentNode.innerHTML}</div>
                </body>
                </html>
            `);
            printWindow.document.close();

            // รอโหลด Font และ CSS เล็กน้อยก่อนเรียกคำสั่งพิมพ์
            setTimeout(() => {
                try {
                    printWindow.focus();
                    printWindow.print();
                    // printWindow.close(); // เปิดไว้ให้ผู้ใช้ตรวจสอบ หรือปิดเองได้
                } catch (e) {
                    console.error("Print failed:", e);
                    showCustomAlert("ไม่สามารถสั่งพิมพ์ได้ อาจถูกบล็อกการ Popup โดยเบราว์เซอร์");
                }
            }, 1000);
        }

        async function generateBillCanvas() {
            // ... existing generateBillCanvas logic ...
            const sourceElement = document.getElementById('bill-content');
            if (!sourceElement || typeof html2canvas !== 'function') {
                showCustomAlert('ไม่สามารถสร้างรูปภาพได้: ไม่พบส่วนประกอบที่จำเป็น');
                return null;
            }
            
            // We apply scaling to HTML2Canvas to get high quality, but we don't need to force CSS here
            // since updateBillPreview() already visually applied it to #bill-content.
            const clone = sourceElement.cloneNode(true);
            clone.style.position = 'absolute';
            clone.style.top = '-9999px';
            clone.style.left = '0px';
            clone.style.margin = '0';
            clone.style.backgroundColor = '#fff';
            
            document.body.appendChild(clone);
            try {
                const canvas = await html2canvas(clone, {
                    scale: 3,
                    useCORS: true,
                    logging: false,
                    width: clone.offsetWidth,
                    height: clone.offsetHeight,
                    backgroundColor: '#ffffff'
                });
                return canvas;
            } catch (error) {
                console.error('Error generating canvas:', error);
                showCustomAlert('เกิดข้อผิดพลาดในการสร้างรูปภาพ: ' + error.message);
                return null;
            } finally {
                if (document.body.contains(clone)) {
                    document.body.removeChild(clone);
                }
            }
        }

        saveAsImageBtn.addEventListener('click', async function() {
            // ... existing saveAsImageBtn logic ...
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
                // ... existing shareBillBtn logic (with Apple fix) ...
                const buttonId = 'share-bill-btn';
                if (typeof setButtonLoading === 'function') setButtonLoading(this, true, buttonId);
                const canvas = await generateBillCanvas();
                if (canvas) {
                    const fileName = `receipt_${(document.getElementById('bill_number_input').value || 'receipt').replace(/[\/\\]/g, '-')}.png`;
                    canvas.toBlob(async function(blob) {
                        if (!blob) {
                            showCustomAlert('ไม่สามารถสร้างไฟล์รูปภาพสำหรับแชร์ได้');
                            if (typeof setButtonLoading === 'function') setButtonLoading(shareBillBtn, false, buttonId);
                            return;
                        }

                        // ***** START: MODIFICATION FOR APPLE/SHARE ISSUE *****
                        const explicitMimeType = 'image/png';

                        if (navigator.share && typeof File !== 'undefined' && navigator.canShare({
                                files: [new File([blob], fileName, {
                                    type: explicitMimeType
                                })]
                            })) {
                            const shareFile = new File([blob], fileName, {
                                type: explicitMimeType
                            });
                            // ***** END: MODIFICATION FOR APPLE/SHARE ISSUE *****

                            try {
                                await navigator.share({
                                    title: `ใบเสร็จรับเงิน เลขที่ ${document.getElementById('bill_number_input').value || ''}`,
                                    text: `ใบเสร็จรับเงินสำหรับ ${document.getElementById('bill_customer_company_name').value || 'ลูกค้า'}`,
                                    files: [shareFile]
                                });
                            } catch (error) {
                                if (error.name !== 'AbortError') {
                                    console.error('[Share Bill] Share failed:', error);
                                    showCustomAlert('การแชร์ไม่สำเร็จ: ' + error.message);
                                }
                            }
                        } else {
                            showCustomAlert('เบราว์เซอร์นี้ไม่รองรับการแชร์ไฟล์โดยตรง กรุณาใช้ปุ่ม "บันทึกเป็นรูปภาพ" แล้วแชร์ด้วยตนเอง');
                        }
                        if (typeof setButtonLoading === 'function') setButtonLoading(shareBillBtn, false, buttonId);
                    }, 'image/png'); // Ensure blob is created as PNG
                } else {
                    if (typeof setButtonLoading === 'function') setButtonLoading(shareBillBtn, false, buttonId);
                }
            });
        }

        // --- Utility Functions (Loading Spinners) ---
        const originalButtonContents_cashbill = {};

        function setButtonLoading(buttonElement, isLoading, buttonIdForTextStore) {
            // ... existing setButtonLoading logic ...
            if (!buttonElement) return;
            const key = buttonIdForTextStore || buttonElement.id || `btn-cashbill-${Date.now()}`;
            if (isLoading) {
                if (!buttonElement.classList.contains('loading')) {
                    if (originalButtonContents_cashbill[key] === undefined) {
                        originalButtonContents_cashbill[key] = buttonElement.innerHTML;
                    }
                    const spinnerSpan = '<span class="button-spinner-css" style="width:1em; height:1em; border:2px solid rgba(255,255,255,0.3); border-top-color:white; border-radius:50%; display:inline-block; animation: spin 0.6s linear infinite; margin-right: 5px; vertical-align: middle;"></span>';
                    buttonElement.innerHTML = spinnerSpan + ' กำลังดำเนินการ...';
                    buttonElement.classList.add('loading');
                    buttonElement.disabled = true;
                }
            } else {
                if (buttonElement.classList.contains('loading')) {
                    if (originalButtonContents_cashbill[key] !== undefined) {
                        buttonElement.innerHTML = originalButtonContents_cashbill[key];
                    }
                    buttonElement.classList.remove('loading');
                    // Re-enable based on items, not just unconditionally
                    // Exception: don't re-enable save receipt button if it was successful
                    if (buttonElement.id !== 'confirm-save-receipt-btn' || !buttonElement.innerHTML.includes('บันทึกใบเสร็จแล้ว')) {
                        updateActionButtonsState();
                    }
                }
            }
        }
        if (!document.getElementById('button-spinner-style-cashbill')) {
            const style = document.createElement('style');
            style.id = 'button-spinner-style-cashbill';
            style.innerHTML = `@keyframes spin { to { transform: rotate(360deg); } }`;
            document.head.appendChild(style);
        }

        // --- Initial Load ---
        renderAllItems();
        if (typeof window.updateBillPreview === 'function') {
            window.updateBillPreview();
        }

    });
</script>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../templates/layout.php';
?>