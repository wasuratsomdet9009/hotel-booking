<?php
// FILEX: hotel_booking/pages/api.php
// pages/api.php
require_once __DIR__ . '/../bootstrap.php'; // Defines CHECKOUT_TIME_STR, FIXED_DEPOSIT_AMOUNT, DEFAULT_SHORT_STAY_DURATION_HOURS, HOURLY_EXTENSION_RATE, and hopefully get_current_user_id() and process_uploaded_image_with_compression()
header('Content-Type: application/json; charset=utf-8');

// Ensure constants are defined (these might also be in bootstrap.php)
if (!defined('CHECKOUT_TIME_STR')) {
    define('CHECKOUT_TIME_STR', '12:00:00'); // Default if not in bootstrap
}
if (!defined('CHECKOUT_TIME_SQL_INTERVAL')) {
    define('CHECKOUT_TIME_SQL_INTERVAL', 'INTERVAL 12 HOUR');  // Default if not in bootstrap
}
if (!defined('DEFAULT_SHORT_STAY_DURATION_HOURS')) {
    define('DEFAULT_SHORT_STAY_DURATION_HOURS', 3); // Default if not in bootstrap
}
if (!defined('HOURLY_EXTENSION_RATE')) {
    define('HOURLY_EXTENSION_RATE', 100); // Default hourly extension rate if not defined
}


// ** MODIFICATION START **
// Ensure $action is trimmed and robustly fetched
$action_get = isset($_GET['action']) ? trim((string)$_GET['action']) : '';
$action_post = isset($_POST['action']) ? trim((string)$_POST['action']) : '';
$action = !empty($action_get) ? $action_get : $action_post;
// ** MODIFICATION END **

error_log("[API] Action: '{$action}'. Request Method: {$_SERVER['REQUEST_METHOD']}. GET: " . print_r($_GET, true) . " POST: " . print_r($_POST, true) . " FILES: " . print_r($_FILES, true));


$logTimestampField = "last_modified_at";
try {
    $checkColumnStmt = $pdo->query("SHOW COLUMNS FROM bookings LIKE 'last_modified_at'");
    if(!$checkColumnStmt->fetch()){
        $logTimestampField = "last_extended_at"; // Fallback
    }
} catch (PDOException $e) {
    error_log("[API] DB Error checking columns: " . $e->getMessage());
    $logTimestampField = "last_extended_at"; // Safer fallback
}


switch ($action) {
    case 'create':
        $booking_mode = $_POST['booking_mode'] ?? 'single';
        $pdo->beginTransaction();
        try {
            $current_user_id = get_current_user_id();
            if ($current_user_id === null) {
                error_log("[API Create] Warning: current_user_id is null. Ensure get_current_user_id() is working correctly and user is logged in.");
            }

            $customer_name_raw = trim($_POST['customer_name'] ?? '');
            $customer_phone = trim($_POST['customer_phone'] ?? '');
            $payment_method = trim($_POST['payment_method'] ?? '');
            // ** MODIFICATION START: Clarify checkin_now logic source **
            $is_checkin_now_from_form_input = !empty($_POST['checkin_now']); // This comes directly from the form's checkbox state
            // ** MODIFICATION END **
            $checkin_datetime_str = $_POST['checkin_datetime'] ?? '';
            $notes = isset($_POST['notes']) ? trim($_POST['notes']) : null;
            // Original $selected_addons_raw for group-level addons (kept for compatibility if needed)
            $selected_addons_raw = $_POST['selected_addons'] ?? []; 
            // NEW: Room-specific addons
            $posted_room_addons = $_POST['room_addons'] ?? [];

            $booking_type = $_POST['booking_type'] ?? 'overnight';
            $nights = ($booking_type === 'overnight') ? max(1, (int)($_POST['nights'] ?? 1)) : 0;
            $is_flexible_overnight_mode_active = isset($_POST['flexible_overnight_mode']) && $_POST['flexible_overnight_mode'] === '1';
            
            $short_stay_duration_hours = ($booking_type === 'short_stay') ? (int)($_POST['short_stay_duration_hours'] ?? DEFAULT_SHORT_STAY_DURATION_HOURS) : 0;
            
            $collect_deposit_zone_f = isset($_POST['collect_deposit_zone_f']) && $_POST['collect_deposit_zone_f'] === '1';
            $amount_paid_by_customer_for_group = isset($_POST['amount_paid']) ? (int)round((float)$_POST['amount_paid']) : 0;

            // ** MODIFICATION START: Use $is_checkin_now_from_form_input for internal API logic is now more constrained **
            // This flag is used to determine if checkin_datetime should be set to NOW().
            // The actual 'occupy' status change is deferred.
            $set_checkin_to_now_for_api_logic = false;
            if ($booking_mode === 'single' && $booking_type === 'overnight' && $is_checkin_now_from_form_input) {
                $set_checkin_to_now_for_api_logic = true;
            }
            // For short_stay, if checkin_now is flagged, we'll use current time for checkin_datetime,
            // but it won't trigger an 'occupy' status here. The status will be 'booked'.
            if ($booking_mode === 'single' && $booking_type === 'short_stay' && $is_checkin_now_from_form_input) {
                $set_checkin_to_now_for_api_logic = true; // Yes, set checkin_datetime to now.
            }
            // Multi-room mode never uses 'checkin_now' from form for this purpose.
            // ** MODIFICATION END **


            $customer_name = $customer_name_raw; 
            if (empty($customer_name_raw)) {
                // Logic for setting default customer name (kept as original)
                 if ($booking_mode === 'single') {
                    $roomId_single_for_name_check = (int) ($_POST['room_id'] ?? 0);
                    if ($roomId_single_for_name_check > 0) {
                        $stmt_room_zone_for_name = $pdo->prepare("SELECT zone FROM rooms WHERE id = ?");
                        $stmt_room_zone_for_name->execute([$roomId_single_for_name_check]);
                        $room_zone_for_name_check = $stmt_room_zone_for_name->fetchColumn();
                        if ($room_zone_for_name_check === 'F') {
                            $customer_name = "ผู้เข้าพักโซน F (ไม่ระบุชื่อ)";
                        } else { 
                            $customer_name = "ผู้เข้าพัก (ไม่ระบุชื่อ)";
                        }
                    } else { 
                         $customer_name = "ผู้เข้าพัก (ไม่ระบุชื่อ)";
                    }
                } else { 
                    $customer_name = "กลุ่มผู้เข้าพัก (ไม่ระบุชื่อ)";
                }
            }

            if (empty($checkin_datetime_str) || empty($payment_method) ||
                ($booking_type === 'overnight' && $nights < 1) ||
                ($booking_type === 'short_stay' && $short_stay_duration_hours <= 0)) {
                throw new Exception('ข้อมูลการจองหลักไม่ครบถ้วน (เช็คอิน, วิธีชำระเงิน, ระยะเวลา)', 400);
            }
            
            $room_zone_for_logic = null; 
            $room_details_for_validation = null; 

            if ($booking_mode === 'single') {
                // Logic for fetching room details for single booking (kept as original)
                $roomId_single = (int) ($_POST['room_id'] ?? 0); 
                if (!$roomId_single) throw new Exception('ไม่ได้เลือกห้องพักสำหรับโหมดห้องเดียว', 400);
                $stmt_room_zone = $pdo->prepare("SELECT zone, allow_short_stay, short_stay_duration_hours, price_per_day, price_short_stay, ask_deposit_on_overnight FROM rooms WHERE id = ?");
                $stmt_room_zone->execute([$roomId_single]);
                $room_details_for_validation = $stmt_room_zone->fetch(PDO::FETCH_ASSOC);
                if (!$room_details_for_validation) throw new Exception("ไม่พบห้อง ID: {$roomId_single}", 404);
                $room_zone_for_logic = $room_details_for_validation['zone'];
            }

            $checkin_datetime_obj = null;
            $now_dt_api = new \DateTime('now', new \DateTimeZone('Asia/Bangkok')); 

            // ** MODIFICATION START: Use $set_checkin_to_now_for_api_logic **
            if ($set_checkin_to_now_for_api_logic) { 
                $checkin_datetime_obj = clone $now_dt_api; 
                error_log("[API Create] 'Check-in Now' logic triggered. Check-in time set to current: " . $checkin_datetime_obj->format('Y-m-d H:i:s') . " for booking type: " . $booking_type);
            } else {
            // ** MODIFICATION END **
                $d = \DateTime::createFromFormat('Y-m-d\TH:i', $checkin_datetime_str); 
                if (!$d || $d->format('Y-m-d\TH:i') !== $checkin_datetime_str) { 
                    $d_alt = \DateTime::createFromFormat('Y-m-d H:i:s', $checkin_datetime_str); 
                    if (!$d_alt || $d_alt->format('Y-m-d H:i:s') !== $checkin_datetime_str) { 
                        throw new Exception('รูปแบบวันเวลาเช็คอินไม่ถูกต้อง: ' . htmlspecialchars($checkin_datetime_str), 400); 
                    }
                    $checkin_datetime_obj = $d_alt; 
                } else {
                    $checkin_datetime_obj = $d; 
                }
                error_log("[API Create] Parsed Form Check-in time '{$checkin_datetime_str}' to DateTime: " . $checkin_datetime_obj->format('Y-m-d H:i:s')); 
            }
            $checkin_sql_format = $checkin_datetime_obj->format('Y-m-d H:i:s'); 

            // **** START: DELETED VALIDATION FOR PAST CHECK-IN ON NEW BOOKINGS ****
            // The block that previously prevented booking in the past has been removed.
            // **** END: DELETED VALIDATION FOR PAST CHECK-IN ON NEW BOOKINGS ****

            $checkout_datetime_calculated_obj = clone $checkin_datetime_obj; 
            // Logic for calculating checkout_datetime_calculated (kept as original)
            if ($booking_type === 'overnight') {
                list($h, $m, $s) = explode(':', CHECKOUT_TIME_STR); 
                if ($is_flexible_overnight_mode_active) {
                    // Flexible overnight logic (kept as original)
                    $checkin_hour = (int)$checkin_datetime_obj->format('H');
                    $checkin_date_Y_m_d = $checkin_datetime_obj->format('Y-m-d');
                    $noon_on_checkin_day_obj = new \DateTime($checkin_date_Y_m_d . ' ' . CHECKOUT_TIME_STR, new \DateTimeZone('Asia/Bangkok'));
                    if ($checkin_hour >= 1 && $checkin_hour < 11) { 
                        $checkout_datetime_calculated_obj = clone $noon_on_checkin_day_obj;
                        error_log("[API Create Flexible Overnight EARLY CHECK-IN] Check-in at {$checkin_hour}:00. Checkout forced to: " . $checkout_datetime_calculated_obj->format('Y-m-d H:i:s'));
                    } elseif ($checkin_datetime_obj < $noon_on_checkin_day_obj) { 
                        $checkout_datetime_calculated_obj = $noon_on_checkin_day_obj;
                        error_log("[API Create Flexible Overnight REGULAR BEFORE NOON] Check-in before noon. Checkout set to: " . $checkout_datetime_calculated_obj->format('Y-m-d H:i:s'));
                    } else {
                        $checkout_datetime_calculated_obj->add(new DateInterval("P{$nights}D"));
                        $checkout_datetime_calculated_obj->setTime((int)$h, (int)$m, (int)$s);
                        error_log("[API Create Flexible Overnight STANDARD] Check-in at or after noon. Checkout based on nights: " . $checkout_datetime_calculated_obj->format('Y-m-d H:i:s'));
                    }
                } else { 
                    $checkout_datetime_calculated_obj->add(new DateInterval("P{$nights}D"));
                    $checkout_datetime_calculated_obj->setTime((int)$h, (int)$m, (int)$s);
                    error_log("[API Create Standard Overnight] Checkout set to: " . $checkout_datetime_calculated_obj->format('Y-m-d H:i:s'));
                }
            } elseif ($booking_type === 'short_stay') { 
                // Short stay checkout logic (kept as original)
                $effective_short_stay_hours = $short_stay_duration_hours; 
                if ($booking_mode === 'single' && $room_details_for_validation && isset($room_details_for_validation['short_stay_duration_hours']) && (int)$room_details_for_validation['short_stay_duration_hours'] > 0) {
                    $effective_short_stay_hours = (int)$room_details_for_validation['short_stay_duration_hours'];
                } elseif ($booking_mode === 'single' && $room_details_for_validation && $room_details_for_validation['allow_short_stay'] == '1' && (!isset($room_details_for_validation['short_stay_duration_hours']) || (int)$room_details_for_validation['short_stay_duration_hours'] <= 0) ){
                }
                $checkout_datetime_calculated_obj->add(new DateInterval("PT{$effective_short_stay_hours}H")); 
                error_log("[API Create Short Stay] Checkout set to: " . $checkout_datetime_calculated_obj->format('Y-m-d H:i:s'));
            }
            $checkout_datetime_calculated_sql_format = $checkout_datetime_calculated_obj->format('Y-m-d H:i:s'); 
            $nights_for_db = $nights; 

            // --- START: Create Booking Group for ALL booking modes ---
            $stmtCreateGroup = $pdo->prepare(
                "INSERT INTO booking_groups (customer_name, customer_phone, main_checkin_datetime, created_by_user_id, notes)
                 VALUES (:customer_name, :customer_phone, :checkin_datetime, :user_id, :notes)"
            );
            $stmtCreateGroup->execute([
                ':customer_name' => $customer_name,
                ':customer_phone' => empty($customer_phone) ? null : $customer_phone,
                ':checkin_datetime' => $checkin_sql_format,
                ':user_id' => $current_user_id,
                ':notes' => $notes
            ]);
            $bookingGroupId = $pdo->lastInsertId();
            if (!$bookingGroupId) {
                throw new Exception('ไม่สามารถสร้างกลุ่มการจองได้ (Failed to create booking group)', 500);
            }
            error_log("[API Create] Created booking_group ID: {$bookingGroupId} for booking_mode: {$booking_mode}");
            // --- END: Create Booking Group ---
            
            $receiptDir = __DIR__ . '/../uploads/receipts/';
            if (!is_dir($receiptDir)) @mkdir($receiptDir, 0777, true);
            if (!is_writable($receiptDir)) throw new Exception('โฟลเดอร์หลักฐานไม่มีสิทธิ์เขียน', 500);

            // --- START: MODIFIED RECEIPT HANDLING for Multi-File Upload ---
            // Assumes HTML input is <input type="file" name="receipt_files[]" multiple>
            // And optional descriptions: <input type="text" name="receipt_descriptions[]">
            $is_receipt_uploaded = false;
            if (isset($_FILES['receipt_files']) && is_array($_FILES['receipt_files']['name'])) {
                $uploadedReceipts = [];
                foreach ($_FILES['receipt_files']['name'] as $key => $filename) {
                    if ($_FILES['receipt_files']['error'][$key] === UPLOAD_ERR_OK) {
                        $is_receipt_uploaded = true; // Mark that at least one file was uploaded
                        $temp_file_path_multi = $_FILES['receipt_files']['tmp_name'][$key];
                        $original_filename_multi = $_FILES['receipt_files']['name'][$key];
                        $ext_multi = strtolower(pathinfo($original_filename_multi, PATHINFO_EXTENSION));
                        $allowed_exts_multi = ['jpg', 'jpeg', 'png', 'gif', 'pdf']; // Allow images and PDF

                        if (in_array($ext_multi, $allowed_exts_multi)) {
                            $unique_rcpt_filename = 'grp_rcpt_' . $bookingGroupId . '_' . uniqid() . '.' . $ext_multi;
                            $destination_path_multi = $receiptDir . $unique_rcpt_filename;

                            $moved_successfully = false;
                            if (in_array($ext_multi, ['jpg', 'jpeg', 'png', 'gif'])) {
                                if (function_exists('process_uploaded_image_with_compression') && process_uploaded_image_with_compression($temp_file_path_multi, $destination_path_multi, $original_filename_multi)) {
                                    $moved_successfully = true;
                                } else {
                                    error_log("[API Create MultiReceipt] process_uploaded_image_with_compression failed for {$original_filename_multi}, falling back to move_uploaded_file.");
                                    if (move_uploaded_file($temp_file_path_multi, $destination_path_multi)) {
                                        $moved_successfully = true;
                                    }
                                }
                            } elseif ($ext_multi === 'pdf') {
                                if (move_uploaded_file($temp_file_path_multi, $destination_path_multi)) {
                                    $moved_successfully = true;
                                }
                            }

                            if($moved_successfully) {
                                $receipt_description = isset($_POST['receipt_descriptions'][$key]) ? trim($_POST['receipt_descriptions'][$key]) : null;
                                $uploadedReceipts[] = [
                                    'path' => $unique_rcpt_filename,
                                    'description' => $receipt_description
                                ];
                            } else {
                                error_log("[API Create MultiReceipt] Could not move/process uploaded file: {$original_filename_multi}");
                                continue; // Skip this file
                            }
                        } else {
                             error_log("[API Create MultiReceipt] Skipped unsupported file type: {$ext_multi} for file {$original_filename_multi}");
                        }
                    }
                }

                if (!empty($uploadedReceipts)) {
                    $stmtInsertGroupReceipt = $pdo->prepare(
                        "INSERT INTO booking_group_receipts (booking_group_id, receipt_path, description, uploaded_by_user_id, amount, payment_method)
                         VALUES (:booking_group_id, :receipt_path, :description, :user_id, :amount, :payment_method)"
                    );
                    // For now, let's assume the first receipt corresponds to the main payment.
                    // A more complex form could allow amounts per receipt.
                    foreach ($uploadedReceipts as $index => $receiptData) {
                        $stmtInsertGroupReceipt->execute([
                            ':booking_group_id' => $bookingGroupId,
                            ':receipt_path' => $receiptData['path'],
                            ':description' => $receiptData['description'],
                            ':user_id' => $current_user_id,
                            ':amount' => ($index === 0) ? $amount_paid_by_customer_for_group : null,
                            ':payment_method' => ($index === 0) ? $payment_method : null
                        ]);
                    }
                    error_log("[API Create] Inserted " . count($uploadedReceipts) . " receipts for booking group ID: {$bookingGroupId}");
                }
            }
            // --- END: MODIFIED RECEIPT HANDLING ---
            
            // Check if receipt is required but not uploaded
            if ($amount_paid_by_customer_for_group > 0 && !$is_receipt_uploaded) {
                if ($booking_mode === 'single') {
                    if ($room_zone_for_logic !== 'F' || ($room_zone_for_logic === 'F' && $booking_type === 'overnight' && $collect_deposit_zone_f)) {
                         throw new Exception('กรุณาแนบหลักฐานการชำระเงิน (ยกเว้นโซน F หรือกรณีไม่เก็บมัดจำโซน F)', 400);
                    }
                } else { // multi-mode always requires receipt if payment is made
                     throw new Exception('กรุณาแนบหลักฐานการชำระเงินสำหรับโหมดหลายห้อง', 400);
                }
            }


            // Logic for calculating Addons at group level (kept as original for now, but will be ignored for room-specific addons)
            $total_addon_cost_for_group_calculated = 0; 
            $valid_addons_for_booking_group = []; // These are for the *group*, not room-specific.
            if (!empty($selected_addons_raw)) {
                $addon_ids = array_keys($selected_addons_raw);
                if (!empty($addon_ids)) {
                    $placeholders_addons = implode(',', array_fill(0, count($addon_ids), '?'));
                    $stmt_addon_prices = $pdo->prepare("SELECT id, price FROM addon_services WHERE id IN ($placeholders_addons) AND is_active = 1");
                    $stmt_addon_prices->execute($addon_ids);
                    $db_addons = $stmt_addon_prices->fetchAll(PDO::FETCH_KEY_PAIR);
                    foreach ($selected_addons_raw as $addon_id_str => $addon_data) {
                        $addon_id = (int)$addon_id_str;
                        if (isset($db_addons[$addon_id]) && isset($addon_data['id']) && (int)$addon_data['id'] === $addon_id) {
                            $quantity = isset($addon_data['quantity']) ? max(1, (int)$addon_data['quantity']) : 1;
                            $price_at_booking_val = (int)round((float)$db_addons[$addon_id]); 
                            $total_addon_cost_for_group_calculated += $price_at_booking_val * $quantity;
                            $valid_addons_for_booking_group[] = ['addon_service_id' => $addon_id, 'quantity' => $quantity, 'price_at_booking' => $price_at_booking_val];
                        }
                    }
                }
            }

            $createdBookingIds = [];

            if ($booking_mode === 'multi') {
                // Logic for multi-room booking creation (kept as original for main flow)
                $roomIds = $_POST['room_ids'] ?? [];
                if (empty($roomIds) || !is_array($roomIds)) {
                    throw new Exception('กรุณาเลือกอย่างน้อยหนึ่งห้องสำหรับโหมดจองหลายห้อง', 400);
                }
                $num_rooms_in_group = count($roomIds);

                if ($booking_type !== 'overnight') {
                    error_log("[API Create Multi] Forcing booking_type to 'overnight' for multi-room mode implicitly. Original type was {$booking_type}");
                    if ($nights_for_db == 0) throw new Exception('Multi-room bookings must be overnight and have at least 1 night.', 400);
                     $booking_type = 'overnight'; // Ensure booking type is overnight for multi-room logic
                }
                
                $total_base_room_cost_group = 0;
                $total_deposit_group_calculated_for_expected_value = 0;
                $room_details_map_multi = [];

                foreach ($roomIds as $r_id_str) {
                    $r_id = (int)$r_id_str;
                    $stmt_r_details = $pdo->prepare("SELECT price_per_day, zone, room_number, ask_deposit_on_overnight FROM rooms WHERE id = ?");
                    $stmt_r_details->execute([$r_id]);
                    $r_detail = $stmt_r_details->fetch(PDO::FETCH_ASSOC);
                    if (!$r_detail) throw new Exception("ไม่พบห้อง ID: {$r_id}", 404);

                    $room_price_per_day_this_room = (int)round((float)$r_detail['price_per_day']);
                    $room_details_map_multi[$r_id] = [
                        'price_per_day' => $room_price_per_day_this_room,
                        'zone' => $r_detail['zone'],
                        'room_number' => $r_detail['room_number'],
                        'ask_deposit_on_overnight' => $r_detail['ask_deposit_on_overnight']
                    ];
                    $total_base_room_cost_group += $room_price_per_day_this_room * $nights_for_db;

                    if ($booking_type === 'overnight') {
                        if ($r_detail['zone'] === 'F' && $r_detail['ask_deposit_on_overnight'] == '1') {
                            if ($collect_deposit_zone_f) {
                                $total_deposit_group_calculated_for_expected_value += FIXED_DEPOSIT_AMOUNT;
                            }
                        } else {
                            $total_deposit_group_calculated_for_expected_value += FIXED_DEPOSIT_AMOUNT;
                        }
                    }
                }
                
                // Calculate expected total value for the group (including group-level addons)
                // This value is no longer directly used for total_price_this_room_for_db, but remains for original amount_paid distribution logic.
                $expected_total_value_group = $total_base_room_cost_group + $total_addon_cost_for_group_calculated + $total_deposit_group_calculated_for_expected_value;

                foreach ($roomIds as $roomId_multi_str) {
                    $current_processing_room_id = (int)$roomId_multi_str;
                    $current_room_details_multi = $room_details_map_multi[$current_processing_room_id];
                    $current_room_price_per_night = $current_room_details_multi['price_per_day'];

                    // Check for overlap (kept as original)
                    $stmtCheckOverlap = $pdo->prepare("
                        SELECT COUNT(*) FROM bookings b
                        WHERE b.room_id = :room_id
                        AND b.checkout_datetime_calculated > :new_checkin 
                        AND b.checkin_datetime < :new_checkout
                    ");
                    $stmtCheckOverlap->execute([
                        ':room_id' => $current_processing_room_id,
                        ':new_checkin' => $checkin_sql_format, 
                        ':new_checkout' => $checkout_datetime_calculated_sql_format 
                    ]);
                    if ($stmtCheckOverlap->fetchColumn() > 0) {
                        $room_display_name_multi = $current_room_details_multi['zone'] . $current_room_details_multi['room_number'];
                        throw new Exception("ห้องพัก ".htmlspecialchars($room_display_name_multi)." ไม่ว่างในช่วงเวลาที่คุณเลือก ({$checkin_sql_format} - {$checkout_datetime_calculated_sql_format}) กรุณาเลือกห้องหรือช่วงเวลาอื่น", 409); 
                    }

                    $base_cost_this_room = $current_room_price_per_night * $nights_for_db; 

                    // ***** START: โค้ดที่ต้องแก้ไขและเพิ่มเติม - คำนวณค่า Addons ของห้องนี้โดยเฉพาะ *****
                    // ส่วนนี้จะคำนวณค่าบริการเสริมจาก $posted_room_addons ที่ส่งมาจากฟอร์มสำหรับห้องนี้โดยเฉพาะ
                    $addon_cost_for_this_specific_room = 0;
                    if (isset($posted_room_addons[$current_processing_room_id]) && is_array($posted_room_addons[$current_processing_room_id])) {
                        // ดึงราคาล่าสุดของ addon จาก DB เพื่อความปลอดภัย
                        $addon_ids_for_this_room = array_keys($posted_room_addons[$current_processing_room_id]);
                        if (!empty($addon_ids_for_this_room)) {
                            $placeholders = implode(',', array_fill(0, count($addon_ids_for_this_room), '?'));
                            $stmt_addon_prices_for_room_calc = $pdo->prepare("SELECT id, price FROM addon_services WHERE id IN (" . $placeholders . ") AND is_active = 1");
                            $stmt_addon_prices_for_room_calc->execute($addon_ids_for_this_room);
                            $db_addon_prices_this_room = $stmt_addon_prices_for_room_calc->fetchAll(PDO::FETCH_KEY_PAIR);

                            foreach ($posted_room_addons[$current_processing_room_id] as $addon_id => $quantity) {
                                if (isset($db_addon_prices_this_room[$addon_id])) {
                                    $price_each = (int)round((float)$db_addon_prices_this_room[$addon_id]);
                                    $quantity = (int)$quantity;
                                    $addon_cost_for_this_specific_room += ($price_each * $quantity);
                                }
                            }
                        }
                    }
                    
                    // ลบโค้ดเก่าที่คำนวณ addon_cost_this_room_record จากการหารเฉลี่ย
                    // $addon_cost_this_room_record = ($num_rooms_in_group > 0) ? (int)round($total_addon_cost_for_group_calculated / $num_rooms_in_group) : 0; 
                    
                    // ***** END: โค้ดที่ต้องแก้ไขและเพิ่มเติม *****
                    
                    $deposit_this_room = 0;
                    if ($booking_type === 'overnight') {
                        if ($current_room_details_multi['zone'] === 'F' && $current_room_details_multi['ask_deposit_on_overnight'] == '1') {
                            if ($collect_deposit_zone_f) {
                                $deposit_this_room = FIXED_DEPOSIT_AMOUNT;
                            }
                        } else {
                            $deposit_this_room = FIXED_DEPOSIT_AMOUNT;
                        }
                    }
                    // ***** START: โค้ดที่ต้องแก้ไข - อัปเดตการคำนวณ total_price ให้รวมค่า addon ของห้องนี้ *****
                    // ใช้ $addon_cost_for_this_specific_room ที่คำนวณใหม่
                    $total_price_this_room_for_db = $base_cost_this_room + $addon_cost_for_this_specific_room + $deposit_this_room;
                    // ***** END: โค้ดที่ต้องแก้ไข *****

                    $amount_paid_for_this_room_record = 0;
                    // Original logic for distributing amount_paid_by_customer_for_group remains.
                    // This assumes amount_paid_by_customer_for_group still represents the total payment for the entire group,
                    // which might now be less accurate if room-specific addons significantly change individual room totals.
                    // A more robust solution might require the client to send amount_paid_per_room if payment is per room.
                    // For now, adhering to the request to only fix total_price calculation and leave amount_paid distribution.
                    if ($expected_total_value_group > 0) {
                        $amount_paid_for_this_room_record = (int)round($amount_paid_by_customer_for_group * ($total_price_this_room_for_db / $expected_total_value_group));
                    } else if ($num_rooms_in_group > 0 && $amount_paid_by_customer_for_group > 0) {
                        $amount_paid_for_this_room_record = (int)round($amount_paid_by_customer_for_group / $num_rooms_in_group);
                    }
                    
                    // --- START: MODIFIED SQL INSERT for booking_group_id ---
                    $sql = "INSERT INTO bookings
                                (room_id, customer_name, customer_phone, booking_type, checkin_datetime, checkout_datetime_calculated,
                                 nights, price_per_night, total_price, amount_paid, payment_method, notes,
                                 additional_paid_amount, extended_hours, {$logTimestampField}, deposit_amount,
                                 created_by_user_id, last_modified_by_user_id, booking_group_id)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0, NOW(), ?, ?, ?, ?)";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([
                        $current_processing_room_id, $customer_name, empty($customer_phone) ? null : $customer_phone,
                        'overnight', 
                        $checkin_sql_format, $checkout_datetime_calculated_sql_format, 
                        $nights_for_db, 
                        $current_room_price_per_night, 
                        $total_price_this_room_for_db,
                        $amount_paid_for_this_room_record,
                        $payment_method, $notes,
                        $deposit_this_room,
                        $current_user_id, 
                        $current_user_id,
                        $bookingGroupId // Added booking_group_id
                    ]);
                    // --- END: MODIFIED SQL INSERT ---
                    $bookingId = $pdo->lastInsertId();
                    $createdBookingIds[] = $bookingId;

                    // This block is for group-level addons. For room-specific, see below.
                    // This will now be ignored if room-specific addons are present for this room.
                    // If you want to keep group-level addons *in addition* to room-specific ones,
                    // you'd need to adjust the $addon_cost_for_this_specific_room calculation
                    // to include this total as well. For now, we assume room-specific addons
                    // override or are the primary way to specify addons for a room.
                    if (!empty($valid_addons_for_booking_group)) {
                        $stmt_insert_addon = $pdo->prepare(
                            "INSERT INTO booking_addons (booking_id, addon_service_id, quantity, price_at_booking) VALUES (?, ?, ?, ?)"
                        );
                        foreach ($valid_addons_for_booking_group as $addon_to_save) { 
                            // Only insert if this specific room doesn't have its own addon of this type,
                            // or if group-level and room-level addons are meant to stack.
                            // For this update, we are prioritizing room-specific addons.
                            // If this room has room-specific addons for a given addon_service_id,
                            // the group-level addon for that service_id will NOT be applied to this room.
                            // This decision is based on common UI/UX for specific room configurations.
                            if (!isset($posted_room_addons[$current_processing_room_id][$addon_to_save['addon_service_id']])) {
                                $stmt_insert_addon->execute([$bookingId, $addon_to_save['addon_service_id'], $addon_to_save['quantity'], $addon_to_save['price_at_booking']]);
                            }
                        }
                    }
                    
                    // ***** START: โค้ดที่แก้ไขและเพิ่มเติม - บันทึก Addons เฉพาะของห้องนี้ *****
                    if (isset($posted_room_addons[$current_processing_room_id]) && is_array($posted_room_addons[$current_processing_room_id])) {
                        error_log("[API Create Multi] Processing room-specific addons for Room ID: {$current_processing_room_id}, Booking ID: {$bookingId}");
                        
                        $stmt_addon_prices_for_room = $pdo->prepare("SELECT id, price FROM addon_services WHERE id = ? AND is_active = 1");
                        $stmt_insert_addon_room_specific = $pdo->prepare(
                            "INSERT INTO booking_addons (booking_id, addon_service_id, quantity, price_at_booking) VALUES (?, ?, ?, ?)"
                        );

                        foreach ($posted_room_addons[$current_processing_room_id] as $addon_id => $quantity) {
                            $addon_id = (int)$addon_id;
                            $quantity = (int)$quantity;
                            
                            if ($addon_id > 0 && $quantity > 0) {
                                // ดึงราคาล่าสุดจาก DB เพื่อความปลอดภัย
                                $stmt_addon_prices_for_room->execute([$addon_id]);
                                $addon_db_data = $stmt_addon_prices_for_room->fetch(PDO::FETCH_ASSOC);

                                if ($addon_db_data) {
                                    $price_at_booking = (int)round((float)$addon_db_data['price']);
                                    $stmt_insert_addon_room_specific->execute([$bookingId, $addon_id, $quantity, $price_at_booking]);
                                    error_log("[API Create Multi] Inserted room-specific addon: Booking ID {$bookingId}, Addon ID {$addon_id}, Quantity {$quantity}, Price {$price_at_booking}");
                                } else {
                                    error_log("[API Create Multi] Addon ID {$addon_id} not found or inactive, skipping for Booking ID {$bookingId}.");
                                }
                            }
                        }
                    }
                    // ***** END: โค้ดที่แก้ไขและเพิ่มเติม *****

                    $stmt_get_current_room_status_multi = $pdo->prepare("SELECT status FROM rooms WHERE id = ?");
                    $stmt_get_current_room_status_multi->execute([$current_processing_room_id]);
                    $db_current_room_status_multi = $stmt_get_current_room_status_multi->fetchColumn();
                    
                    // <<<< START: MODIFICATION FOR AUTO CHECK-IN AND STATUS LOGIC (MULTI ROOM) >>>>
                    $current_new_room_status_for_multi = $db_current_room_status_multi; // Default to current DB status

                    // $now_dt_api and $checkin_datetime_obj are defined earlier in the 'create' action
                    // Multi-room bookings are forced to 'overnight' type earlier in the script.

                    if ($checkin_datetime_obj <= $now_dt_api) { // Check-in time is past or current
                        // For multi-room, assuming overlap checks prior to this loop are sufficient for safety.
                        // If more granular check per room is needed, it can be added similar to single room.
                        $current_new_room_status_for_multi = 'occupied';
                        error_log("[API Create Multi] AUTO CHECK-IN: Check-in time ({$checkin_sql_format}) is past/current. Room ID: {$current_processing_room_id}, Booking ID: {$bookingId}. Setting status to 'occupied'.");
                    } else { // Check-in time is in the future (Multi-room is always 'overnight')
                        $today_date_obj_api_multi = (clone $now_dt_api)->setTime(0,0,0);
                        $checkin_date_part_obj_api_multi = (clone $checkin_datetime_obj)->setTime(0,0,0);

                        if ($checkin_date_part_obj_api_multi == $today_date_obj_api_multi) { // Booking for later today
                            $current_new_room_status_for_multi = 'booked';
                            error_log("[API Create Multi] Multi-booking FOR TODAY (Overnight), check-in time ({$checkin_sql_format}) is IN THE FUTURE. Room ID: {$current_processing_room_id}. Setting status to 'booked'.");
                        } else { // Booking for a future date (more than 1 day ahead)
                            if ($db_current_room_status_multi === 'free') {
                                $current_new_room_status_for_multi = 'free'; // Or 'advance_booking' if preferred for UI
                            }
                            // If room is already 'advance_booking' or 'maintenance', its $db_current_room_status_multi will be preserved.
                            error_log("[API Create Multi] Multi-booking FOR FUTURE date (Overnight) ({$checkin_sql_format}). Room ID: {$current_processing_room_id}. Current DB status '{$db_current_room_status_multi}', Proposed new status: '{$current_new_room_status_for_multi}'.");
                        }
                    }
                    // The existing SQL execution for updating room status (around lines 289-299) will use this $current_new_room_status_for_multi.
                    // if ($current_new_room_status_for_multi !== $db_current_room_status_multi) { ... }
                    // <<<< END: MODIFICATION FOR AUTO CHECK-IN AND STATUS LOGIC (MULTI ROOM) >>>>

                    if ($current_new_room_status_for_multi !== $db_current_room_status_multi) {
                        $updateRoomStmt = $pdo->prepare("UPDATE rooms SET status = :new_status WHERE id = :room_id");
                        $updateRoomStmt->execute([':new_status' => $current_new_room_status_for_multi, ':room_id' => $current_processing_room_id]);
                        error_log("[API Create Multi] Room ID: {$current_processing_room_id} status CHANGED from '{$db_current_room_status_multi}' to '{$current_new_room_status_for_multi}'.");
                    } else {
                        error_log("[API Create Multi] Room ID: {$current_processing_room_id} status REMAINS '{$db_current_room_status_multi}'. No status change by this booking operation.");
                    }
                }

            } else { // Single booking mode
                $roomId = (int) ($_POST['room_id'] ?? 0); 
                if (!$roomId && isset($roomId_single)) $roomId = $roomId_single; 

                // Check for overlap (kept as original)
                $stmtCheckOverlap = $pdo->prepare("
                    SELECT COUNT(*) FROM bookings b
                    WHERE b.room_id = :room_id
                    AND b.checkout_datetime_calculated > :new_checkin 
                    AND b.checkin_datetime < :new_checkout
                ");
                $stmtCheckOverlap->execute([
                    ':room_id' => $roomId,
                    ':new_checkin' => $checkin_sql_format,
                    ':new_checkout' => $checkout_datetime_calculated_sql_format
                ]);
                if ($stmtCheckOverlap->fetchColumn() > 0) {
                    $room_display_name_stmt_single = $pdo->prepare("SELECT CONCAT(zone, room_number) FROM rooms WHERE id = ?");
                    $room_display_name_stmt_single->execute([$roomId]);
                    $room_display_name_single = $room_display_name_stmt_single->fetchColumn() ?: "ID {$roomId}";
                    throw new Exception("ห้องพัก ".htmlspecialchars($room_display_name_single)." ไม่ว่างในช่วงเวลาที่คุณเลือก ({$checkin_sql_format} - {$checkout_datetime_calculated_sql_format}) กรุณาเลือกห้องหรือช่วงเวลาอื่น", 409); 
                }


                $base_room_cost_single = 0; 
                $price_per_night_db_single = null; 
                $deposit_amount_single = 0; 

                if ($booking_type === 'overnight') {
                    if (!$room_details_for_validation) throw new Exception("ไม่พบรายละเอียดห้องพักสำหรับคำนวณราคา (Overnight)", 500);
                    $price_per_night_db_single = (int)round((float)$room_details_for_validation['price_per_day']); 
                    $base_room_cost_single = $price_per_night_db_single * $nights_for_db; 
                    
                    if ($room_zone_for_logic === 'F') { 
                        if ($room_details_for_validation['ask_deposit_on_overnight'] == '1' && $collect_deposit_zone_f) {
                            $deposit_amount_single = FIXED_DEPOSIT_AMOUNT; 
                        } else {
                            $deposit_amount_single = 0;
                        }
                    } else { 
                        $deposit_amount_single = FIXED_DEPOSIT_AMOUNT; 
                    }
                } elseif ($booking_type === 'short_stay') {
                    if (!$room_details_for_validation) throw new Exception("ไม่พบรายละเอียดห้องพักสำหรับคำนวณราคา (Short Stay)", 500);
                    if (!$room_details_for_validation['allow_short_stay']) throw new Exception("ห้องนี้ ID:{$roomId} ไม่รองรับการจองแบบชั่วคราว", 400);
                    $base_room_cost_single = (int)round((float)$room_details_for_validation['price_short_stay']); 
                    $deposit_amount_single = 0; 
                }
                $total_price_for_db_single = $base_room_cost_single + $total_addon_cost_for_group_calculated + $deposit_amount_single; 
                
                if ($amount_paid_by_customer_for_group != $total_price_for_db_single) { 
                     error_log("[API Create Single] Amount paid by customer ({$amount_paid_by_customer_for_group}) differs from server calculated total price ({$total_price_for_db_single}). Using customer provided amount for 'amount_paid' field.");
                }

                // --- START: MODIFIED SQL INSERT for booking_group_id ---
                $sql = "INSERT INTO bookings
                            (room_id, customer_name, customer_phone, booking_type, checkin_datetime, checkout_datetime_calculated,
                             nights, price_per_night, total_price, amount_paid, payment_method, notes,
                             additional_paid_amount, extended_hours, {$logTimestampField}, deposit_amount,
                             created_by_user_id, last_modified_by_user_id, booking_group_id)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0, NOW(), ?, ?, ?, ?)";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    $roomId, $customer_name, empty($customer_phone) ? null : $customer_phone, $booking_type,
                    $checkin_sql_format, $checkout_datetime_calculated_sql_format, 
                    $nights_for_db, 
                    $price_per_night_db_single, 
                    $total_price_for_db_single, 
                    $amount_paid_by_customer_for_group, 
                    $payment_method, empty($notes) ? null : $notes,
                    $deposit_amount_single, 
                    $current_user_id, 
                    $current_user_id,
                    $bookingGroupId // Added booking_group_id
                ]);
                // --- END: MODIFIED SQL INSERT ---
                $bookingId = $pdo->lastInsertId();
                $createdBookingIds[] = $bookingId;

                // For single booking, process *group-level* addons (original logic).
                // If you want room-specific addons for single booking, this logic would need to be moved here.
                if (!empty($valid_addons_for_booking_group)) {
                    $stmt_insert_addon = $pdo->prepare(
                        "INSERT INTO booking_addons (booking_id, addon_service_id, quantity, price_at_booking) VALUES (?, ?, ?, ?)"
                    );
                    foreach ($valid_addons_for_booking_group as $addon_to_save) { 
                        $stmt_insert_addon->execute([$bookingId, $addon_to_save['addon_service_id'], $addon_to_save['quantity'], $addon_to_save['price_at_booking']]);
                    }
                }
                // Single booking does not currently support `posted_room_addons` in the same way as multi-room,
                // as its `room_id` is singular. If room-specific addons are needed for single booking,
                // the $posted_room_addons structure would need to be adapted or a separate input used.
                // For now, it only processes $valid_addons_for_booking_group (group-level addons applied to this single booking).


                // <<<< START: MODIFICATION FOR AUTO CHECK-IN AND STATUS LOGIC (SINGLE ROOM) >>>>
                $stmt_get_current_status = $pdo->prepare("SELECT status FROM rooms WHERE id = ?");
                $stmt_get_current_status->execute([$roomId]);
                $currentRoomDBStatus = $stmt_get_current_status->fetchColumn();
                $newRoomStatus_single = $currentRoomDBStatus; // Default to current status

                // $now_dt_api and $checkin_datetime_obj are defined earlier in the 'create' action
                // $booking_type is also defined earlier

                if ($checkin_datetime_obj <= $now_dt_api) { // Check-in time is past or current
                    // Check for active overlap again, to be absolutely sure before forcing 'occupied'
                    $stmtCheckActiveOverlapForce = $pdo->prepare("
                        SELECT COUNT(*) FROM bookings b_overlap
                        WHERE b_overlap.room_id = :room_id
                          AND b_overlap.id != :new_booking_id /* Exclude the booking just created */
                          AND b_overlap.checkin_datetime <= NOW() /* Another booking is currently active */
                          AND NOW() < b_overlap.checkout_datetime_calculated
                    ");
                    $stmtCheckActiveOverlapForce->execute([':room_id' => $roomId, ':new_booking_id' => $bookingId]);

                    if ($stmtCheckActiveOverlapForce->fetchColumn() == 0) {
                        $newRoomStatus_single = 'occupied';
                        error_log("[API Create Single] AUTO CHECK-IN: Check-in time ({$checkin_sql_format}) is past/current. Room ID: {$roomId}, Booking ID: {$bookingId}. Type: {$booking_type}. Setting status to 'occupied'.");
                    } else {
                        // This case should be rare if initial overlap checks are thorough
                        $newRoomStatus_single = 'booked'; // Fallback if unexpected overlap
                        error_log("[API Create Single] AUTO CHECK-IN BLOCKED: Check-in time ({$checkin_sql_format}) is past/current, but another booking is currently active. Room ID: {$roomId}, Booking ID: {$bookingId}. Setting to 'booked'.");
                    }
                } else { // Check-in time is in the future
                    $today_date_obj_api = (clone $now_dt_api)->setTime(0, 0, 0);
                    $checkin_date_part_obj_api = (clone $checkin_datetime_obj)->setTime(0, 0, 0);

                    if ($booking_type === 'short_stay') {
                        // Future short_stay bookings are marked as 'booked'.
                        // Their transition to 'occupied' when their time comes will be handled by status update scripts or cron.
                        $newRoomStatus_single = 'booked';
                        error_log("[API Create Single] Future Short-Stay. Room ID: {$roomId}, Booking ID: {$bookingId}. Check-in: {$checkin_sql_format}. Setting status to 'booked'.");
                    } elseif ($checkin_date_part_obj_api == $today_date_obj_api) { // Overnight booking for later today
                        $newRoomStatus_single = 'booked';
                        error_log("[API Create Single] Booking for later today (Overnight). Room ID: {$roomId}, Booking ID: {$bookingId}. Check-in: {$checkin_sql_format}. Setting status to 'booked'.");
                    } else { // Overnight booking for a future date (more than 1 day ahead)
                        if ($currentRoomDBStatus === 'free') {
                            // If the room is 'free', it means no other advance bookings are making it 'advance_booking' yet from other mechanisms.
                            // We can set it to 'free' (implying it's free now but has this future booking)
                            // or 'advance_booking' if your UI specifically uses that for a room that is free AND has a future booking.
                            // For consistency with index.php logic, if it has a future booking, it might be 'advance_booking'.
                            // However, setting to 'free' is also acceptable as the display_status query will handle it.
                            // Let's stick to 'free' if it's free, and dashboard logic will show 'advance_booking'.
                            $newRoomStatus_single = 'free';
                        }
                        // If room is already 'advance_booking' or 'maintenance', its $currentRoomDBStatus will be preserved.
                        error_log("[API Create Single] Advance Booking (Overnight) for future date. Room ID: {$roomId}, Booking ID: {$bookingId}. Check-in: {$checkin_sql_format}. DB status '{$currentRoomDBStatus}', New status proposal: '{$newRoomStatus_single}'.");
                    }
                }

                // The existing SQL execution for updating room status (around lines 392-408) will use this $newRoomStatus_single.
                // error_log("[API Create Single] Final newRoomStatus_single for Room ID {$roomId}: {$newRoomStatus_single}. Based on Processed Check-in time: {$checkin_sql_format}");
                // if ($newRoomStatus_single !== null && ($newRoomStatus_single !== $currentRoomDBStatus)) { ... }
                // <<<< END: MODIFICATION FOR AUTO CHECK-IN AND STATUS LOGIC (SINGLE ROOM) >>>>
                
                error_log("[API Create Single] Final newRoomStatus_single for Room ID {$roomId}: {$newRoomStatus_single}. Based on Processed Check-in time: {$checkin_sql_format}");

                if ($newRoomStatus_single !== null && ($newRoomStatus_single !== $currentRoomDBStatus)) {
                    $updateQuerySql = "UPDATE rooms SET status = :new_status WHERE id = :room_id";
                    $updateRoomStmt = $pdo->prepare($updateQuerySql);
                    $updateRoomStmt->execute([':new_status' => $newRoomStatus_single, ':room_id' => $roomId]);

                    if ($updateRoomStmt->rowCount() > 0) {
                        error_log("[API Create Single] Room ID: {$roomId}. Status successfully CHANGED from '{$currentRoomDBStatus}' to '{$newRoomStatus_single}'.");
                    } else {
                        // ... (log เดิมกรณี update ไม่สำเร็จ) ...
                        $finalCheckStatusStmt = $pdo->prepare("SELECT status FROM rooms WHERE id = ?");
                        $finalCheckStatusStmt->execute([$roomId]);
                        $actualFinalStatus = $finalCheckStatusStmt->fetchColumn();
                        if ($actualFinalStatus === $newRoomStatus_single) {
                            error_log("[API Create Single] Room ID: {$roomId}. Status was already '{$newRoomStatus_single}'. No change needed.");
                        } else {
                            error_log("[API Create Single] Room ID: {$roomId}. Failed to set status from '{$currentRoomDBStatus}' to '{$newRoomStatus_single}'. RowCount: 0. Actual status after attempt: '{$actualFinalStatus}'.");
                        }
                    }
                } else {
                     error_log("[API Create Single] Room ID: {$roomId}. No status change needed for this booking operation (new status '{$newRoomStatus_single}', current DB status '{$currentRoomDBStatus}').");
                }
            }

            $pdo->commit();
            $successMessage = $booking_mode === 'multi' ? 'จองหลายห้องพักเรียบร้อย! (' . count($createdBookingIds) . ' ห้อง)' : 'จองห้องพักเรียบร้อย!';
            // ** MODIFICATION START: ส่ง booking_ids กลับไปเสมอ เพื่อให้ client ใช้ได้ **
            echo json_encode(['success' => true, 'message' => $successMessage, 'booking_ids' => $createdBookingIds, 'booking_group_id' => $bookingGroupId, 'redirect_url' => '/hotel_booking/pages/index.php']);
            // ** MODIFICATION END **
            exit;

        } catch (PDOException $e) {
            // ... (catch PDOException เหมือนเดิม) ...
            if ($pdo->inTransaction()) $pdo->rollBack();
            http_response_code(500);
            error_log("[API Create] PDO Error: " . $e->getMessage() . " SQLSTATE: " . $e->getCode() . " Trace: " . $e->getTraceAsString());
            echo json_encode(['success' => false, 'message' => 'เกิดข้อผิดพลาดในการประมวลผลข้อมูลกับฐานข้อมูล', 'detail' => $e->getMessage()]);
            exit;
        } catch (Exception $e) {
            // ... (catch Exception เหมือนเดิม) ...
            if ($pdo->inTransaction()) $pdo->rollBack();
            $errorCode = ($e->getCode() >= 400 && $e->getCode() < 600) ? $e->getCode() : 500; 
            http_response_code($errorCode);
            error_log("[API Create] App Error: " . $e->getMessage() . " Trace: " . $e->getTraceAsString());
            echo json_encode(['success' => false, 'message' => $e->getMessage(), 'detail' => $e->getMessage()]); 
            exit;
        }

        case 'update_booking_group':
        try {
            $pdo->beginTransaction();
            $current_user_id = get_current_user_id();
            if ($current_user_id === null) {
                error_log("[API UpdateBookingGroup] Warning: current_user_id is null.");
            }

            $booking_group_id = (int)($_POST['booking_group_id'] ?? 0);
            $sub_action = $_POST['sub_action'] ?? 'update_main';

            if (!$booking_group_id) {
                throw new Exception("ไม่พบ ID ของกลุ่มการจอง", 400);
            }

            if ($sub_action === 'delete_receipt') {
                $receipt_id = (int)($_POST['receipt_id'] ?? 0);
                if (!$receipt_id) {
                    throw new Exception("ไม่พบ ID ของสลิปที่จะลบ", 400);
                }

                // Find filename before deleting DB record
                $stmtFile = $pdo->prepare("SELECT receipt_path FROM booking_group_receipts WHERE id = ? AND booking_group_id = ?");
                $stmtFile->execute([$receipt_id, $booking_group_id]);
                $filename = $stmtFile->fetchColumn();

                // Delete DB record
                $stmtDelete = $pdo->prepare("DELETE FROM booking_group_receipts WHERE id = ?");
                $stmtDelete->execute([$receipt_id]);

                if ($stmtDelete->rowCount() > 0 && $filename) {
                    // Delete physical file
                    $filepath = __DIR__ . '/../uploads/receipts/' . $filename;
                    if (file_exists($filepath)) {
                        @unlink($filepath);
                    }
                    $pdo->commit();
                    echo json_encode(['success' => true, 'message' => 'ลบสลิปเรียบร้อยแล้ว']);
                } else {
                    throw new Exception("ไม่สามารถลบสลิปได้ หรือไม่พบสลิปดังกล่าว");
                }
                exit;
            }

            // Default action: Update main group info
            $customer_name = trim($_POST['customer_name'] ?? '');
            $customer_phone = trim($_POST['customer_phone'] ?? '');
            $notes = trim($_POST['notes'] ?? '');

            if (empty($customer_name)) {
                throw new Exception("ชื่อผู้จองหลักต้องไม่เป็นค่าว่าง", 400);
            }
            
            $stmtUpdate = $pdo->prepare("UPDATE booking_groups SET customer_name = ?, customer_phone = ?, notes = ? WHERE id = ?");
            $stmtUpdate->execute([$customer_name, $customer_phone, $notes, $booking_group_id]);

            // Handle new file uploads
            if (isset($_FILES['new_receipt_files']) && is_array($_FILES['new_receipt_files']['name'])) {
                $receiptDir = __DIR__ . '/../uploads/receipts/';
                foreach ($_FILES['new_receipt_files']['name'] as $key => $filename) {
                    if ($_FILES['new_receipt_files']['error'][$key] === UPLOAD_ERR_OK) {
                        $tmp_path = $_FILES['new_receipt_files']['tmp_name'][$key];
                        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                        $unique_filename = 'grp_rcpt_' . $booking_group_id . '_' . uniqid('edit_') . '.' . $ext;
                        $destination = $receiptDir . $unique_filename;

                        if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif'])) {
                            if (function_exists('process_uploaded_image_with_compression') && process_uploaded_image_with_compression($tmp_path, $destination, $filename)) {
                                $moved_successfully = true;
                            } else {
                                // Fallback to move_uploaded_file if compression fails
                                if (move_uploaded_file($tmp_path, $destination)) {
                                    $moved_successfully = true;
                                }
                            }
                        } elseif ($ext === 'pdf') {
                            if (move_uploaded_file($tmp_path, $destination)) {
                                $moved_successfully = true;
                            }
                        }

                        if($moved_successfully) {
                            $stmtAddReceipt = $pdo->prepare("INSERT INTO booking_group_receipts (booking_group_id, receipt_path, uploaded_by_user_id) VALUES (?, ?, ?)");
                            $stmtAddReceipt->execute([$booking_group_id, $unique_filename, $current_user_id]);
                        } else {
                             error_log("[API UpdateGroup] Failed to move uploaded receipt: {$filename}");
                        }
                    }
                }
            }
            
            $pdo->commit();
            echo json_encode(['success' => true, 'message' => 'บันทึกข้อมูลกลุ่มเรียบร้อยแล้ว']);

        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            http_response_code(400);
            error_log("[API update_booking_group] Error: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;

        case 'get_group_details_for_bill':
        try {
            $groupId = (int)($_GET['booking_group_id'] ?? 0);
            if (!$groupId) {
                throw new Exception('ไม่พบรหัสกลุ่มการจอง', 400);
            }

            $stmtGroup = $pdo->prepare("SELECT customer_name FROM booking_groups WHERE id = ?");
            $stmtGroup->execute([$groupId]);
            $groupInfo = $stmtGroup->fetch(PDO::FETCH_ASSOC);

            if (!$groupInfo) {
                throw new Exception('ไม่พบข้อมูลกลุ่ม', 404);
            }

            $stmtBookings = $pdo->prepare("
                SELECT b.id, b.room_id, r.zone, r.room_number, b.nights, b.price_per_night, b.checkin_datetime, b.checkout_datetime_calculated
                FROM bookings b
                JOIN rooms r ON b.room_id = r.id
                WHERE b.booking_group_id = ?
            ");
            $stmtBookings->execute([$groupId]);
            $bookings = $stmtBookings->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode(['success' => true, 'group_info' => $groupInfo, 'bookings' => $bookings]);

        } catch (Exception $e) {
            http_response_code($e->getCode() >= 400 ? $e->getCode() : 500);
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;

    case 'update_booking_with_addons': 
        $pdo->beginTransaction();
        try {
            $current_user_id = get_current_user_id();
            if ($current_user_id === null) {
                error_log("[API UpdateBookingAddons] Warning: current_user_id is null.");
            }

            $bookingId = (int)($_POST['booking_id'] ?? 0);
            if (!$bookingId) throw new Exception('ไม่พบรหัสการจองสำหรับการแก้ไข', 400);

            // --- START: Fetch booking_group_id ---
            $stmtGetGroup = $pdo->prepare("SELECT booking_group_id FROM bookings WHERE id = ?");
            $stmtGetGroup->execute([$bookingId]);
            $currentBookingGroupId = $stmtGetGroup->fetchColumn();
            if (!$currentBookingGroupId) {
                error_log("[API UpdateBookingAddons] Warning: Booking ID {$bookingId} does not have a booking_group_id. Receipt handling might fail.");
                // Depending on policy, you might want to create a group here or throw an error.
                // For now, we'll let it proceed but log the warning.
            }
            // --- END: Fetch booking_group_id ---

            $stmtOld = $pdo->prepare("
                SELECT b.*,
                       r.room_number as room_number,
                       r.zone as room_current_zone,
                       r.price_per_day as room_daily_price,
                       r.price_short_stay as room_short_price,
                       r.allow_short_stay as room_allows_short,
                       r.ask_deposit_on_overnight as room_ask_deposit_f,
                       COALESCE(r.short_stay_duration_hours, ".DEFAULT_SHORT_STAY_DURATION_HOURS.") as room_default_short_duration
                FROM bookings b
                JOIN rooms r ON b.room_id = r.id
                WHERE b.id = ?
            ");
            $stmtOld->execute([$bookingId]);
            $oldBookingData = $stmtOld->fetch(PDO::FETCH_ASSOC);
            if (!$oldBookingData) throw new Exception("ไม่พบข้อมูลการจอง ID: {$bookingId} สำหรับแก้ไข", 404);

            $stmtOldAddonsSum = $pdo->prepare("SELECT SUM(quantity * price_at_booking) FROM booking_addons WHERE booking_id = ?");
            $stmtOldAddonsSum->execute([$bookingId]);
            $original_total_addon_cost_from_db_for_calc = (int)round((float)$stmtOldAddonsSum->fetchColumn()); 

            $customer_name_raw = trim($_POST['customer_name'] ?? $oldBookingData['customer_name']);
            $current_room_zone = $oldBookingData['room_current_zone'];
            $customer_name = $customer_name_raw;
            if (array_key_exists('customer_name', $_POST) && empty(trim($_POST['customer_name']))) {
                $customer_name = "ผู้เข้าพัก (แก้ไข ไม่ระบุชื่อ)";
                if ($current_room_zone === 'F') {
                    $customer_name = "ผู้เข้าพักโซน F (แก้ไข ไม่ระบุชื่อ)";
                }
            } else if (!array_key_exists('customer_name', $_POST) && empty($oldBookingData['customer_name'])) {
                 if (empty($customer_name_raw)) { 
                    $customer_name = "ผู้เข้าพัก (แก้ไข ไม่ระบุชื่อ)";
                    if ($current_room_zone === 'F') {
                        $customer_name = "ผู้เข้าพักโซน F (แก้ไข ไม่ระบุชื่อ)";
                    }
                }
            }

            $customer_phone = trim($_POST['customer_phone'] ?? $oldBookingData['customer_phone']);
            $payment_method = trim($_POST['payment_method'] ?? $oldBookingData['payment_method']);
            $notes = isset($_POST['notes']) ? trim($_POST['notes']) : $oldBookingData['notes'];
            $selected_addons_raw = $_POST['selected_addons'] ?? [];

            $new_amount_paid_from_form = isset($_POST['amount_paid']) ? (int)round((float)$_POST['amount_paid']) : (int)round((float)$oldBookingData['amount_paid']); 
            $new_checkout_datetime_from_form_str = isset($_POST['checkout_datetime_edit']) ? trim($_POST['checkout_datetime_edit']) : null; 
            $new_nights_from_form = isset($_POST['nights']) ? (int)$_POST['nights'] : (int)$oldBookingData['nights']; 

            $fieldsToUpdate = [];
            $bindings = [];
            $dataChanged = false; 

            $fieldsToUpdate['customer_name'] = $customer_name;
            $fieldsToUpdate['customer_phone'] = empty($customer_phone) ? null : $customer_phone;
            $fieldsToUpdate['payment_method'] = $payment_method;
            $fieldsToUpdate['notes'] = empty($notes) ? null : $notes;
            
            $old_booking_amount_paid_int = (int)round((float)$oldBookingData['amount_paid']);
            if ($new_amount_paid_from_form != $old_booking_amount_paid_int) { 
                $fieldsToUpdate['amount_paid'] = $new_amount_paid_from_form; 
                $additional_increment = $new_amount_paid_from_form - $old_booking_amount_paid_int; 
                
                $current_additional_paid = (int)round((float)($oldBookingData['additional_paid_amount'] ?? 0)); 
                $fieldsToUpdate['additional_paid_amount'] = $current_additional_paid + $additional_increment; 
                
                if ($fieldsToUpdate['additional_paid_amount'] < 0) {
                     error_log("[API UpdateBooking] Warning: additional_paid_amount would become negative. Clamping to 0. Booking ID: {$bookingId}");
                    $fieldsToUpdate['additional_paid_amount'] = 0; 
                }
                $dataChanged = true;
            } else {
                $fieldsToUpdate['amount_paid'] = $old_booking_amount_paid_int; 
            }


            $db_nights_to_update = (int)$oldBookingData['nights'];
            $new_checkout_datetime_calculated_sql = $oldBookingData['checkout_datetime_calculated'];
            $price_per_night_for_calc = (int)round((float)($oldBookingData['price_per_night'] ?? $oldBookingData['room_daily_price'] ?? 0)); 


            if ($oldBookingData['booking_type'] === 'overnight') {
                $checkout_changed_by_datetime = false;
                if ($new_checkout_datetime_from_form_str !== null) {
                    $new_checkout_dt_obj = DateTime::createFromFormat('Y-m-d\TH:i', $new_checkout_datetime_from_form_str);
                    if ($new_checkout_dt_obj) { 
                        $formatted_new_checkout_from_form = $new_checkout_dt_obj->format('Y-m-d H:i:s');
                        if ($formatted_new_checkout_from_form !== $oldBookingData['checkout_datetime_calculated']) {
                            $new_checkout_datetime_calculated_sql = $formatted_new_checkout_from_form;
                            $fieldsToUpdate['checkout_datetime_calculated'] = $new_checkout_datetime_calculated_sql;
                            $dataChanged = true;
                            $checkout_changed_by_datetime = true;

                            $checkin_dt_obj = new DateTime($oldBookingData['checkin_datetime']);
                            
                            $checkout_date_part = $new_checkout_dt_obj->format('Y-m-d');
                            list($h_co, $m_co, $s_co) = explode(':', CHECKOUT_TIME_STR); 
                            $standardized_checkout_for_nights_calc = (new DateTime($checkout_date_part))->setTime((int)$h_co, (int)$m_co, (int)$s_co);
                            
                            $interval = $checkin_dt_obj->diff($standardized_checkout_for_nights_calc);
                            $calculated_nights = (int)$interval->days;
                            
                            $db_nights_to_update = max(1, $calculated_nights); 
                            if ($new_checkout_dt_obj <= $checkin_dt_obj) { 
                                $db_nights_to_update = 1;
                                $correctedCheckout = clone $checkin_dt_obj;
                                $correctedCheckout->add(new DateInterval("P1D"));
                                $correctedCheckout->setTime((int)$h_co, (int)$m_co, (int)$s_co);
                                $fieldsToUpdate['checkout_datetime_calculated'] = $correctedCheckout->format('Y-m-d H:i:s');
                                error_log("[API UpdateBookingAddons] Corrected checkout for Booking {$bookingId} due to invalid edit. New checkout: {$fieldsToUpdate['checkout_datetime_calculated']}");
                            }
                            
                            $fieldsToUpdate['nights'] = $db_nights_to_update;
                            error_log("[API UpdateBookingAddons] Checkout datetime edited for Booking {$bookingId} to {$new_checkout_datetime_calculated_sql}. New nights: {$db_nights_to_update}");
                        }
                    } else {
                         error_log("[API UpdateBookingAddons] Invalid checkout_datetime_edit format: {$new_checkout_datetime_from_form_str} for Booking ID {$bookingId}");
                    }
                }
                
                if (!$checkout_changed_by_datetime && $new_nights_from_form !== (int)$oldBookingData['nights']) {
                    if ($new_nights_from_form < 1) throw new Exception("จำนวนคืนต้องอย่างน้อย 1 คืน", 400);
                    $db_nights_to_update = $new_nights_from_form;
                    $fieldsToUpdate['nights'] = $db_nights_to_update;
                    $dataChanged = true;

                    $checkin_dt_obj = new DateTime($oldBookingData['checkin_datetime']);
                    $new_checkout_dt_obj_from_nights = clone $checkin_dt_obj;
                    $new_checkout_dt_obj_from_nights->add(new DateInterval("P{$db_nights_to_update}D"));
                    list($h_co, $m_co, $s_co) = explode(':', CHECKOUT_TIME_STR);
                    $new_checkout_dt_obj_from_nights->setTime((int)$h_co, (int)$m_co, (int)$s_co);
                    $new_checkout_datetime_calculated_sql = $new_checkout_dt_obj_from_nights->format('Y-m-d H:i:s');
                    $fieldsToUpdate['checkout_datetime_calculated'] = $new_checkout_datetime_calculated_sql;
                    error_log("[API UpdateBookingAddons] Nights input changed for booking {$bookingId} to {$db_nights_to_update}. New checkout: {$new_checkout_datetime_calculated_sql}");
                }
                
                if (isset($fieldsToUpdate['checkout_datetime_calculated']) && $new_checkout_datetime_calculated_sql > $oldBookingData['checkout_datetime_calculated']) {
                    $stmtCheckOverlap = $pdo->prepare("
                        SELECT COUNT(*) FROM bookings b
                        WHERE b.room_id = :room_id
                        AND b.id != :current_booking_id 
                        AND b.checkout_datetime_calculated > :new_checkin_boundary 
                        AND b.checkin_datetime < :new_checkout_boundary 
                    ");
                    $stmtCheckOverlap->execute([
                        ':room_id' => $oldBookingData['room_id'],
                        ':current_booking_id' => $bookingId,
                        ':new_checkin_boundary' => $oldBookingData['checkin_datetime'], 
                        ':new_checkout_boundary' => $new_checkout_datetime_calculated_sql 
                    ]);
                    if ($stmtCheckOverlap->fetchColumn() > 0) {
                        $room_display_info = htmlspecialchars($oldBookingData['room_current_zone'] . ($oldBookingData['room_number'] ?? $oldBookingData['room_id']));
                        throw new Exception("ห้องพัก ".$room_display_info." ไม่ว่างสำหรับช่วงเวลาที่แก้ไข กรุณาตรวจสอบปฏิทิน", 409);
                    }
                }
            }

            $total_addon_cost_new = 0; 
            $valid_addons_for_update = [];
            $addons_structure_changed = false; 

            if (isset($_POST['selected_addons'])) {
                $current_db_addons_map = [];
                $stmt_current_db_addons = $pdo->prepare("SELECT addon_service_id, quantity FROM booking_addons WHERE booking_id = ?");
                $stmt_current_db_addons->execute([$bookingId]);
                foreach($stmt_current_db_addons->fetchAll(PDO::FETCH_ASSOC) as $db_addon){
                    $current_db_addons_map[(int)$db_addon['addon_service_id']] = (int)$db_addon['quantity'];
                }

                $form_addons_map = [];
                 if (!empty($selected_addons_raw)) {
                    $addon_ids = array_keys($selected_addons_raw);
                    if(!empty($addon_ids)){
                        $placeholders = implode(',', array_fill(0, count($addon_ids), '?'));
                        $stmt_addon_prices = $pdo->prepare("SELECT id, price FROM addon_services WHERE id IN ($placeholders) AND is_active = 1");
                        $stmt_addon_prices->execute($addon_ids);
                        $db_addons_info = $stmt_addon_prices->fetchAll(PDO::FETCH_KEY_PAIR);

                        foreach ($selected_addons_raw as $addon_id_str => $addon_data) {
                            $addon_id = (int)$addon_id_str;
                            if (isset($db_addons_info[$addon_id]) && isset($addon_data['id']) && (int)$addon_data['id'] === $addon_id) {
                                $quantity = isset($addon_data['quantity']) ? max(1, (int)$addon_data['quantity']) : 1;
                                $price_at_booking_val = (int)round((float)$db_addons_info[$addon_id]); 
                                $total_addon_cost_new += $price_at_booking_val * $quantity; 
                                $valid_addons_for_update[] = ['addon_service_id' => $addon_id, 'quantity' => $quantity, 'price_at_booking' => $price_at_booking_val];
                                // Use this for comparison against DB, ensures integer keys
                                $form_addons_map[$addon_id] = $quantity; 
                            }
                        }
                    }
                }

                // Check if addon structure has changed (quantity or existence)
                if (count($current_db_addons_map) !== count($form_addons_map)) {
                    $addons_structure_changed = true;
                } else {
                    foreach($form_addons_map as $addon_id => $quantity) {
                        if (!isset($current_db_addons_map[$addon_id]) || $current_db_addons_map[$addon_id] !== $quantity) {
                            $addons_structure_changed = true;
                            break;
                        }
                    }
                }
                
                if ($addons_structure_changed) {
                    $dataChanged = true; 
                    $deleteOldAddons = $pdo->prepare("DELETE FROM booking_addons WHERE booking_id = ?");
                    $deleteOldAddons->execute([$bookingId]);

                    if (!empty($valid_addons_for_update)) {
                        $stmtInsertAddon = $pdo->prepare(
                            "INSERT INTO booking_addons (booking_id, addon_service_id, quantity, price_at_booking) VALUES (?, ?, ?, ?)"
                        );
                        foreach ($valid_addons_for_update as $addon_to_save) { 
                            $stmtInsertAddon->execute([
                                $bookingId, $addon_to_save['addon_service_id'],
                                $addon_to_save['quantity'], $addon_to_save['price_at_booking']
                            ]);
                        }
                    }
                    error_log("[API UpdateBookingAddons] Addons updated for booking {$bookingId}. New total addon cost: {$total_addon_cost_new}");
                } else {
                    $total_addon_cost_new = $original_total_addon_cost_from_db_for_calc; 
                     error_log("[API UpdateBookingAddons] Addons structure unchanged for booking {$bookingId}. Using original addon cost: {$total_addon_cost_new}");
                }
            } else {
                $total_addon_cost_new = $original_total_addon_cost_from_db_for_calc; 
                error_log("[API UpdateBookingAddons] 'selected_addons' not in POST for booking {$bookingId}. Using original addon cost: {$total_addon_cost_new}");
            }

            $new_total_price_for_db; 
            $old_booking_deposit_amount_int = (int)round((float)$oldBookingData['deposit_amount']); 
            if ($oldBookingData['booking_type'] === 'overnight') {
                $new_total_price_for_db = ($db_nights_to_update * $price_per_night_for_calc) + $total_addon_cost_new + $old_booking_deposit_amount_int; 
            } else { 
                $original_room_cost_component = (int)round((float)$oldBookingData['total_price']) - $original_total_addon_cost_from_db_for_calc - $old_booking_deposit_amount_int; 
                $new_total_price_for_db = $original_room_cost_component + $total_addon_cost_new + $old_booking_deposit_amount_int; 
            }
            
            $old_booking_total_price_int = (int)round((float)$oldBookingData['total_price']);
            if ($new_total_price_for_db != $old_booking_total_price_int) { 
                $fieldsToUpdate['total_price'] = $new_total_price_for_db; 
                $dataChanged = true;
            } else {
                 $fieldsToUpdate['total_price'] = $old_booking_total_price_int; 
            }

            // --- START: MODIFIED RECEIPT HANDLING ---
            $is_new_receipt_uploaded = isset($_FILES['receipt']) && $_FILES['receipt']['error'] === UPLOAD_ERR_OK;
            if ($is_new_receipt_uploaded) {
                if (!$currentBookingGroupId) {
                    throw new Exception('ไม่สามารถอัปโหลดสลิปได้เนื่องจากไม่พบกลุ่มการจอง', 500);
                }
                $receiptDir = __DIR__ . '/../uploads/receipts/';
                if (!is_dir($receiptDir)) @mkdir($receiptDir, 0777, true);
                if (!is_writable($receiptDir)) throw new Exception('โฟลเดอร์หลักฐาน (แก้ไข) ไม่มีสิทธิ์เขียน', 500);

                $temp_file_path = $_FILES['receipt']['tmp_name'];
                $original_filename = $_FILES['receipt']['name'];
                $ext = strtolower(pathinfo($original_filename, PATHINFO_EXTENSION));
                $allowed_exts = ['jpg', 'jpeg', 'png', 'gif', 'pdf'];

                if (!in_array($ext, $allowed_exts)) {
                    throw new Exception('ไฟล์หลักฐานใหม่ต้องเป็นรูปภาพ (JPG, JPEG, PNG, GIF) หรือ PDF', 400);
                }
                $new_rcpt_filename_only = 'grp_rcpt_' . $currentBookingGroupId . '_' . uniqid('edit_') . '.' . $ext;
                $new_rcpt_destination_path = $receiptDir . $new_rcpt_filename_only;

                $moved_successfully = false;
                if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif'])) {
                    if (function_exists('process_uploaded_image_with_compression') && process_uploaded_image_with_compression($temp_file_path, $new_rcpt_destination_path, $original_filename)) {
                        $moved_successfully = true;
                    }
                } elseif ($ext === 'pdf') {
                    if (move_uploaded_file($temp_file_path, $new_rcpt_destination_path)) {
                        $moved_successfully = true;
                    }
                }

                if(!$moved_successfully){
                    throw new Exception('การบันทึกหรือประมวลผลไฟล์หลักฐานใหม่ล้มเหลว', 500);
                }
                
                // Insert new receipt into the group receipts table
                $stmtInsertGroupReceipt = $pdo->prepare(
                    "INSERT INTO booking_group_receipts (booking_group_id, receipt_path, description, uploaded_by_user_id)
                     VALUES (:booking_group_id, :receipt_path, :description, :user_id)"
                );
                $stmtInsertGroupReceipt->execute([
                    ':booking_group_id' => $currentBookingGroupId,
                    ':receipt_path' => $new_rcpt_filename_only,
                    ':description' => 'สลิปที่อัปเดต', // Or get from a new form field
                    ':user_id' => $current_user_id
                ]);
                error_log("[API UpdateBookingAddons] New receipt {$new_rcpt_filename_only} added to booking group {$currentBookingGroupId}.");
                $dataChanged = true;
            }
            // The `receipt_path` field in the `bookings` table is no longer updated here.
            // --- END: MODIFIED RECEIPT HANDLING ---
            
            if ($fieldsToUpdate['customer_name'] !== $oldBookingData['customer_name'] ||
                ($fieldsToUpdate['customer_phone'] ?? null) !== ($oldBookingData['customer_phone'] ?? null) || 
                $fieldsToUpdate['payment_method'] !== $oldBookingData['payment_method'] ||
                ($fieldsToUpdate['notes'] ?? null) !== ($oldBookingData['notes'] ?? null) ) { 
                $dataChanged = true;
            }
            
            if (!$dataChanged && !$addons_structure_changed) { 
                $pdo->rollBack();
                echo json_encode(['success' => true, 'message' => 'ไม่มีข้อมูลที่ต้องอัปเดต', 'booking_id' => $bookingId]);
                exit;
            }

            $fieldsToUpdate[$logTimestampField] = 'NOW()'; 
            $fieldsToUpdate['last_modified_by_user_id'] = $current_user_id;


            $sqlSetParts = [];
            foreach ($fieldsToUpdate as $field => $value) {
                // Do not update receipt_path in bookings table anymore
                if ($field === 'receipt_path') continue;

                if ($field === $logTimestampField && $value === 'NOW()') { 
                    $sqlSetParts[] = "{$field} = NOW()";
                } else {
                    $sqlSetParts[] = "{$field} = :{$field}";
                    $bindings[":{$field}"] = $value;
                }
            }
            
            $updateBookingSql = "UPDATE bookings SET " . implode(", ", $sqlSetParts) . " WHERE id = :booking_id_main";
            $bindings[':booking_id_main'] = $bookingId;

            error_log("[API UpdateBookingAddons] Final Update SQL: " . $updateBookingSql);
            error_log("[API UpdateBookingAddons] Final Bindings: " . print_r($bindings, true));

            $stmtUpdateBooking = $pdo->prepare($updateBookingSql);
            $stmtUpdateBooking->execute($bindings);

            $pdo->commit();
            echo json_encode(['success' => true, 'message' => 'แก้ไขการจองเรียบร้อยแล้ว', 'booking_id' => $bookingId, 'redirect_url' => '/hotel_booking/pages/index.php']);
            exit;

        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            http_response_code(500);
            error_log("[API UpdateBookingAddons] PDO Error: " . $e->getMessage() . " Trace: " . $e->getTraceAsString());
            echo json_encode(['success' => false, 'message' => 'เกิดข้อผิดพลาดฐานข้อมูล (PDO) ขณะแก้ไขการจอง: ' . $e->getMessage(), 'detail' => $e->getMessage()]);
            exit;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $errorCode = ($e->getCode() >= 400 && $e->getCode() < 600) ? $e->getCode() : 500;
            http_response_code($errorCode);
            error_log("[API UpdateBookingAddons] App Error: " . $e->getMessage() . " Trace: " . $e->getTraceAsString());
            echo json_encode(['success' => false, 'message' => 'เกิดข้อผิดพลาดขณะแก้ไขการจอง: ' . $e->getMessage(), 'detail' => $e->getMessage()]);
            exit;
        }
       
    case 'update': 
        error_log("[API Update] Starting 'update' action. POST data: " . print_r($_POST, true));
        try {
            $pdo->beginTransaction();

            $bookingId = (int)($_POST['booking_id'] ?? 0);
            $updateAction = isset($_POST['update_action']) ? trim((string)$_POST['update_action']) : ''; 

            if (!$bookingId || empty($updateAction)) {
                error_log("[API Update] Missing bookingId or updateAction. BookingID: {$bookingId}, UpdateAction: {$updateAction}");
                throw new Exception('ข้อมูลไม่ครบถ้วนสำหรับการอัปเดต (ID หรือ update_action)', 400);
            }
            
            $stmtBooking = $pdo->prepare("
                SELECT b.*, r.id as room_actual_id, r.zone as room_zone, 
                       COALESCE(r.short_stay_duration_hours, ".DEFAULT_SHORT_STAY_DURATION_HOURS.") as room_short_stay_duration_config
                FROM bookings b 
                JOIN rooms r ON b.room_id = r.id 
                WHERE b.id = ?
            ");
            $stmtBooking->execute([$bookingId]);
            $booking = $stmtBooking->fetch(PDO::FETCH_ASSOC);

            if (!$booking) {
                throw new Exception("ไม่พบข้อมูลการจอง ID: {$bookingId}", 404);
            }
            $roomId = $booking['room_actual_id']; 
            $current_user_id = get_current_user_id(); 

            if ($updateAction === 'occupy') {
                if ($current_user_id === null) {
                    error_log("[API Update Occupy] Warning: current_user_id is null.");
                }

                $nowDateTime = new DateTime('now', new DateTimeZone('Asia/Bangkok'));
                $originalCheckinTime = new DateTime($booking['checkin_datetime'], new DateTimeZone('Asia/Bangkok'));
                
                $updateBookingFields = [];
                $updateBookingBindings = [];
                $actualCheckinTimeForCalc = $originalCheckinTime; 

                if ($nowDateTime < $originalCheckinTime && $nowDateTime->format('Y-m-d') === $originalCheckinTime->format('Y-m-d')) {
                    error_log("[API Update Occupy] Early check-in detected for Booking ID: {$bookingId}. Original: {$originalCheckinTime->format('Y-m-d H:i:s')}, Now: {$nowDateTime->format('Y-m-d H:i:s')}");
                    $actualCheckinTimeSql = $nowDateTime->format('Y-m-d H:i:s');
                    $updateBookingFields[] = "checkin_datetime = :new_checkin_datetime";
                    $updateBookingBindings[':new_checkin_datetime'] = $actualCheckinTimeSql;
                    $actualCheckinTimeForCalc = $nowDateTime; 

                    if ($booking['booking_type'] === 'short_stay') {
                        $durationHours = (int)($booking['room_short_stay_duration_config'] ?? DEFAULT_SHORT_STAY_DURATION_HOURS);
                        if ($durationHours <= 0) { 
                            $durationHours = DEFAULT_SHORT_STAY_DURATION_HOURS;
                        }
                        $newCheckoutDateTime = clone $actualCheckinTimeForCalc; 
                        $newCheckoutDateTime->add(new DateInterval("PT{$durationHours}H"));
                        
                        $updateBookingFields[] = "checkout_datetime_calculated = :new_checkout_datetime_calculated";
                        $updateBookingBindings[':new_checkout_datetime_calculated'] = $newCheckoutDateTime->format('Y-m-d H:i:s');
                        error_log("[API Update Occupy] Short stay Booking ID: {$bookingId}. New checkout calculated: {$newCheckoutDateTime->format('Y-m-d H:i:s')}");
                    }
                }
                
                $updateBookingFields[] = "{$logTimestampField} = NOW()";
                $updateBookingFields[] = "last_modified_by_user_id = :current_user_id_booking";
                $updateBookingBindings[':current_user_id_booking'] = $current_user_id;
                $updateBookingBindings[':booking_id_occupy'] = $bookingId;

                if (!empty($updateBookingFields)) {
                    $updateBookingSql = "UPDATE bookings SET " . implode(", ", $updateBookingFields) . " WHERE id = :booking_id_occupy";
                    $stmtUpdateBooking = $pdo->prepare($updateBookingSql);
                    $stmtUpdateBooking->execute($updateBookingBindings);
                    if ($stmtUpdateBooking->rowCount() > 0) {
                        error_log("[API Update Occupy] Booking ID: {$bookingId} times updated for occupy (Early check-in or timestamp).");
                    } else {
                         error_log("[API Update Occupy] Booking ID: {$bookingId} times update query executed but no rows affected (could be same timestamps or issue).");
                    }
                }

                $stmtRoomStatus = $pdo->prepare("UPDATE rooms SET status = 'occupied' WHERE id = ? AND (status = 'booked' OR status='free')");
                $stmtRoomStatus->execute([$roomId]);

                if ($stmtRoomStatus->rowCount() > 0) {
                    $pdo->commit();
                    echo json_encode(['success' => true, 'message' => 'เช็คอินห้องพักเรียบร้อย!']);
                } else {
                    $currentRoomStatusStmt = $pdo->prepare("SELECT status FROM rooms WHERE id = ?");
                    $currentRoomStatusStmt->execute([$roomId]);
                    $currentStatus = $currentRoomStatusStmt->fetchColumn();
                    if ($currentStatus === 'occupied') {
                        $pdo->commit(); 
                        echo json_encode(['success' => true, 'message' => 'ห้องพักนี้ได้เช็คอินไปแล้วก่อนหน้า']);
                    } else {
                        $pdo->rollBack(); 
                        throw new Exception('ไม่สามารถเช็คอินห้องได้ อาจเนื่องจากสถานะห้องไม่ถูกต้อง (ปัจจุบันคือ: '.htmlspecialchars($currentStatus).') หรือห้องไม่ตรงกัน', 409);
                    }
                }
                exit;

            } elseif ($updateAction === 'return_and_complete') {
                // --- Start of user-requested modifications (enhanced error handling) ---
                if (empty($booking['room_id']) || empty($booking['room_zone'])) { 
                    throw new Exception('ข้อมูลการจองไม่สมบูรณ์ ไม่สามารถดำเนินการต่อได้ (ขาด room_id หรือ room_zone)', 500); 
                } 

                $depositProofFile = null;
                $original_deposit_amount_for_this_booking = (int)round((float)($booking['deposit_amount'] ?? 0)); 
                $bookingCompletionType = $_POST['booking_completion_type'] ?? 'overnight_with_deposit_return'; 
                $proofNeeded = false; 
                $actuallyReturnDepositFlag = 0; 

                if ($bookingCompletionType === 'overnight_with_deposit_return') {
                    if ($booking['booking_type'] === 'overnight' && $original_deposit_amount_for_this_booking > 0) {
                        $proofNeeded = true; 
                        $actuallyReturnDepositFlag = 1; 
                    }
                } elseif ($bookingCompletionType === 'overnight_no_deposit_return') {
                    $proofNeeded = false;
                    $actuallyReturnDepositFlag = 0;
                } elseif ($bookingCompletionType === 'no_deposit_return_needed' || $bookingCompletionType === 'short_stay_complete') {
                    $proofNeeded = false;
                    $actuallyReturnDepositFlag = 0;
                }

                if ($proofNeeded) { 
                    if (isset($_FILES['deposit_proof']) && $_FILES['deposit_proof']['error'] === UPLOAD_ERR_OK) {
                        $depositDir = __DIR__ . '/../uploads/deposit/';
                        if (!is_dir($depositDir)) @mkdir($depositDir, 0777, true);
                        if (!is_writable($depositDir)) throw new Exception('โฟลเดอร์หลักฐานคืนมัดจำไม่มีสิทธิ์เขียน', 500);

                        $temp_file_path = $_FILES['deposit_proof']['tmp_name'];
                        $original_filename = $_FILES['deposit_proof']['name'];
                        $ext = strtolower(pathinfo($original_filename, PATHINFO_EXTENSION));
                        $allowed_image_exts = ['jpg', 'jpeg', 'png', 'gif'];
                        $allowed_pdf_exts = ['pdf'];

                        if (!in_array($ext, $allowed_image_exts) && !in_array($ext, $allowed_pdf_exts)) {
                             throw new Exception('ไฟล์หลักฐานคืนมัดจำต้องเป็นรูปภาพหรือ PDF', 400);
                        }

                        $depositProofFile = 'deposit_' . uniqid() . '.' . $ext;
                        $destination_path = $depositDir . $depositProofFile;
                        
                        $moved_successfully = false;
                        if (in_array($ext, $allowed_image_exts)) {
                           if (function_exists('process_uploaded_image_with_compression') && process_uploaded_image_with_compression($temp_file_path, $destination_path, $original_filename)) {
                                $moved_successfully = true;
                           }
                        } elseif (in_array($ext, $allowed_pdf_exts)) {
                            if (move_uploaded_file($temp_file_path, $destination_path)) {
                                $moved_successfully = true;
                            }
                        }

                        if($moved_successfully){
                             error_log("[API ReturnComplete] ไฟล์หลักฐานคืนมัดจำถูกประมวลผลและบันทึกแล้ว: {$depositProofFile}");
                        } else {
                            error_log("[API ReturnComplete] ไม่สามารถประมวลผลและบันทึกไฟล์หลักฐานคืนมัดจำ: {$original_filename}");
                            throw new Exception('การประมวลผลและบันทึกไฟล์หลักฐานคืนมัดจำล้มเหลว', 500);
                        }

                    } else {
                        throw new Exception('ต้องอัปโหลดหลักฐานการคืนมัดจำสำหรับห้องที่คืนมัดจำ', 400);
                    }
                }
                
                $is_temporary_archive_flag = false;
                if ($booking['room_zone'] === 'F' && $booking['booking_type'] === 'short_stay') { 
                    $is_temporary_archive_flag = true; 
                }

                $actualCheckoutDatetimeForArchive = $booking['checkout_datetime_calculated'];
                $archivedAtTimestamp = date('Y-m-d H:i:s');
                $current_user_id_for_archive = $current_user_id; // User performing the action

                // 1. Insert into archives
                // Note: The receipt_path and extended_receipt_path are now legacy.
                // New system should look up receipts via booking_group_id -> booking_group_receipts.
                // We keep them in archives for historical data from before the system change.
                $archiveSql = "INSERT INTO archives (
                                     room_id, customer_name, customer_phone, booking_type,
                                     checkin_datetime, checkout_datetime_calculated, checkout_datetime,
                                     nights, extended_hours, price_per_night, total_price, amount_paid,
                                     additional_paid_amount, deposit_amount, payment_method, extended_payment_method,
                                     receipt_path, extended_receipt_path,
                                     deposit_returned, deposit_path, notes,
                                     created_at, last_extended_at, archived_at, is_temporary_archive,
                                     created_by_user_id, last_modified_by_user_id, booking_group_id
                                 ) VALUES (
                                     :room_id, :customer_name, :customer_phone, :booking_type,
                                     :checkin_datetime, :checkout_datetime_calculated, :checkout_datetime_legacy,
                                     :nights, :extended_hours, :price_per_night, :total_price, :amount_paid,
                                     :additional_paid_amount, :deposit_amount, :payment_method, :extended_payment_method,
                                     :receipt_path, :extended_receipt_path,
                                     :deposit_returned, :deposit_path, :notes, 
                                     :created_at_orig_booking, :last_extended_at_orig_booking, :archived_at, :is_temporary_archive,
                                     :created_by_user_id, :last_modified_by_user_id, :booking_group_id
                                 )";
                $stmtArchive = $pdo->prepare($archiveSql);
                $executeParamsArchive = [
                    ':room_id' => $booking['room_id'],
                    ':customer_name' => $booking['customer_name'],
                    ':customer_phone' => $booking['customer_phone'],
                    ':booking_type' => $booking['booking_type'] ?? 'overnight',
                    ':checkin_datetime' => $booking['checkin_datetime'],
                    ':checkout_datetime_calculated' => $actualCheckoutDatetimeForArchive,
                    ':checkout_datetime_legacy' => $actualCheckoutDatetimeForArchive, 
                    ':nights' => $booking['nights'],
                    ':extended_hours' => $booking['extended_hours'] ?? 0,
                    ':price_per_night' => (int)round((float)($booking['price_per_night'] ?? 0)),
                    ':total_price' => (int)round((float)$booking['total_price']),
                    ':amount_paid' => (int)round((float)$booking['amount_paid']),
                    ':additional_paid_amount' => (int)round((float)($booking['additional_paid_amount'] ?? 0.00)),
                    ':deposit_amount' => $original_deposit_amount_for_this_booking,
                    ':payment_method' => $booking['payment_method'],
                    ':extended_payment_method' => $booking['extended_payment_method'],
                    ':receipt_path' => $booking['receipt_path'], // Legacy
                    ':extended_receipt_path' => $booking['extended_receipt_path'], // Legacy
                    ':deposit_returned' => $actuallyReturnDepositFlag,
                    ':deposit_path' => $depositProofFile,
                    ':notes' => $booking['notes'],
                    ':created_at_orig_booking' => $booking['created_at'],
                    ':last_extended_at_orig_booking' => $booking['last_extended_at'], // Ensure this field exists in bookings or use $logTimestampField data
                    ':archived_at' => $archivedAtTimestamp,
                    ':is_temporary_archive' => $is_temporary_archive_flag ? 1 : 0,
                    ':created_by_user_id' => $booking['created_by_user_id'],
                    ':last_modified_by_user_id' => $current_user_id_for_archive,
                    ':booking_group_id' => $booking['booking_group_id'] ?? null // Carry over the group ID
                ];
                
                $archivedBookingId = null;
                try {
                    if (!$stmtArchive->execute($executeParamsArchive)) {
                        $pdo->rollBack();
                        error_log("[API ReturnComplete] Failed to execute insert into archives for Booking ID: {$bookingId}. Params: " . print_r($executeParamsArchive, true));
                        throw new Exception('เกิดข้อผิดพลาดในการย้ายข้อมูลไปประวัติ (archives insert execution failed)', 500);
                    }
                    $archivedBookingId = $pdo->lastInsertId();
                    if (!$archivedBookingId) {
                        $pdo->rollBack();
                        error_log("[API ReturnComplete] Failed to get lastInsertId for archives. Booking ID: {$bookingId}. Params: " . print_r($executeParamsArchive, true));
                        throw new Exception('เกิดข้อผิดพลาดในการย้ายข้อมูลไปประวัติ (archives insert ID error)', 500);
                    }
                    error_log("[API ReturnComplete] Booking ID: {$bookingId} inserted into archives. New Archive ID: {$archivedBookingId}");
                } catch (PDOException $e) {
                    $pdo->rollBack();
                    error_log("[API ReturnComplete] PDOException during insert into archives for Booking ID: {$bookingId}. Error: " . $e->getMessage() . " SQL: " . $archiveSql . " Params: " . print_r($executeParamsArchive, true));
                    throw new Exception('เกิดข้อผิดพลาด PDO ในการย้ายข้อมูลไปประวัติ: ' . $e->getMessage(), 500);
                }

                // 2. Move booking_addons to archive_addons
                $stmtBookingAddons = $pdo->prepare("SELECT * FROM booking_addons WHERE booking_id = ?");
                $stmtBookingAddons->execute([$bookingId]); // Assuming this will succeed if bookingId is valid
                $addonsToArchive = $stmtBookingAddons->fetchAll(PDO::FETCH_ASSOC);

                if (!empty($addonsToArchive)) {
                    $stmtArchiveAddon = $pdo->prepare("INSERT INTO archive_addons (archive_id, addon_service_id, quantity, price_at_booking) VALUES (?, ?, ?, ?)");
                    foreach ($addonsToArchive as $addon) {
                        try {
                            if (!$stmtArchiveAddon->execute([$archivedBookingId, $addon['addon_service_id'], $addon['quantity'], (int)$addon['price_at_booking']])) {
                                $pdo->rollBack();
                                error_log("[API ReturnComplete] Failed to execute insert into archive_addons for Booking ID: {$bookingId}, Addon ID: {$addon['addon_service_id']}. Archive ID: {$archivedBookingId}");
                                throw new Exception('เกิดข้อผิดพลาดในการย้ายข้อมูลส่วนเสริม (archive_addons insert execution failed)', 500);
                            }
                        } catch (PDOException $e) {
                            $pdo->rollBack();
                            error_log("[API ReturnComplete] PDOException during insert into archive_addons for Booking ID: {$bookingId}, Addon ID: {$addon['addon_service_id']}. Archive ID: {$archivedBookingId}. Error: " . $e->getMessage());
                            throw new Exception('เกิดข้อผิดพลาด PDO ในการย้ายข้อมูลส่วนเสริมไปประวัติ: ' . $e->getMessage(), 500);
                        }
                    }
                    error_log("[API ReturnComplete] Addons for Booking ID: {$bookingId} moved to archive_addons for Archive ID: {$archivedBookingId}");
                }

                // Delete from booking_addons
                $stmtDeleteAddons = $pdo->prepare("DELETE FROM booking_addons WHERE booking_id = ?");
                try {
                    if (!$stmtDeleteAddons->execute([$bookingId])) {
                         $pdo->rollBack(); // If execute fails, it's a definite error.
                         error_log("[API ReturnComplete] Failed to execute delete from booking_addons for Booking ID: {$bookingId}.");
                         throw new Exception('เกิดข้อผิดพลาดในการลบข้อมูลส่วนเสริมเดิม (booking_addons delete execution failed)', 500);
                    }
                    // Log if addons were expected but not deleted, or successful deletion count.
                    if ($stmtDeleteAddons->rowCount() === 0 && !empty($addonsToArchive)) {
                        error_log("[API ReturnComplete] No rows deleted from booking_addons for Booking ID: {$bookingId}, but addons were archived and expected to be present for deletion.");
                    } elseif ($stmtDeleteAddons->rowCount() > 0) {
                         error_log("[API ReturnComplete] Addons deleted from booking_addons for Booking ID: {$bookingId}. Count: " . $stmtDeleteAddons->rowCount());
                    } else {
                         error_log("[API ReturnComplete] No addons to delete or no rows affected in booking_addons for Booking ID: {$bookingId}.");
                    }
                } catch (PDOException $e) {
                    $pdo->rollBack();
                    error_log("[API ReturnComplete] PDOException during delete from booking_addons for Booking ID: {$bookingId}. Error: " . $e->getMessage());
                    throw new Exception('เกิดข้อผิดพลาด PDO ในการลบข้อมูลส่วนเสริมเดิม: ' . $e->getMessage(), 500);
                }

                // 3. Delete from bookings
                $stmtDelete = $pdo->prepare("DELETE FROM bookings WHERE id = ?");
                try {
                    if (!$stmtDelete->execute([$bookingId])) {
                         $pdo->rollBack();
                         error_log("[API ReturnComplete] Failed to execute delete from bookings for Booking ID: {$bookingId}.");
                         throw new Exception('เกิดข้อผิดพลาดในการลบการจองเดิม (booking delete execution failed)', 500);
                    }
                    if ($stmtDelete->rowCount() === 0) {
                        // This booking was fetched successfully earlier, so it should exist unless deleted by a concurrent process.
                        // Log this as a serious anomaly. Depending on strictness, could rollback.
                        error_log("[API ReturnComplete] CRITICAL: No rows deleted from bookings for Booking ID: {$bookingId}. Booking should have existed. Investigate potential race condition or data inconsistency.");
                        // For now, following prompt's idea of not always rolling back here if archive succeeded.
                        // However, if the booking MUST be deleted for consistency, this should be a rollback condition:
                        // $pdo->rollBack();
                        // throw new Exception('ไม่พบการจองที่ต้องการลบหลังจากย้ายข้อมูล (อาจเป็นปัญหา race condition)', 500);
                    } else {
                        error_log("[API ReturnComplete] Booking ID: {$bookingId} deleted from bookings table.");
                    }
                } catch (PDOException $e) {
                    $pdo->rollBack();
                    error_log("[API ReturnComplete] PDOException during delete from bookings for Booking ID: {$bookingId}. Error: " . $e->getMessage());
                    throw new Exception('เกิดข้อผิดพลาด PDO ในการลบการจองเดิม: ' . $e->getMessage(), 500);
                }

                // 4. Update room status to 'free'
                $stmtRoomUpdate = $pdo->prepare("UPDATE rooms SET status = 'free' WHERE id = ?");
                try {
                    if (!$stmtRoomUpdate->execute([$roomId])) {
                        // Log the failure, but the main transaction for archiving might still be committed.
                        error_log("[API ReturnComplete] Failed to execute update room status for Room ID: {$roomId}. Transaction for booking move will still attempt to commit.");
                    } elseif ($stmtRoomUpdate->rowCount() === 0) {
                        error_log("[API ReturnComplete] Room ID: {$roomId} status was not updated to 'free' (rowCount is 0). Current status might already be 'free', room ID mismatch, or room was not in a state to be freed. Check room's current status.");
                    } else {
                        error_log("[API ReturnComplete] Room ID: {$roomId} status set to 'free'.");
                    }
                } catch (PDOException $e) {
                    error_log("[API ReturnComplete] PDOException during update room status for Room ID: {$roomId}. Error: " . $e->getMessage() . ". Transaction for booking move will still attempt to commit.");
                }
                // --- End of user-requested modifications ---

                $pdo->commit(); 
                // Use the simplified success message as requested
                echo json_encode(['success' => true, 'message' => 'ดำเนินการเช็คเอาท์และย้ายข้อมูลไปประวัติเรียบร้อยแล้ว', 'archived_id' => $archivedBookingId]);
                exit;

            } elseif ($updateAction === 'delete') {
                // ... (delete logic as previously, ensuring $current_user_id is used for logs if any)
                $stmtFilePaths = $pdo->prepare("SELECT receipt_path, extended_receipt_path FROM bookings WHERE id = ?");
                $stmtFilePaths->execute([$bookingId]);
                $pathsToDelete = $stmtFilePaths->fetch(PDO::FETCH_ASSOC);

                $stmtDeleteAddons = $pdo->prepare("DELETE FROM booking_addons WHERE booking_id = ?");
                $stmtDeleteAddons->execute([$bookingId]);

                $stmt = $pdo->prepare("DELETE FROM bookings WHERE id = ?");
                $stmt->execute([$bookingId]);

                if ($stmt->rowCount() > 0) {
                    $receiptDir = __DIR__ . '/../uploads/receipts/';
                    if ($pathsToDelete) {
                        if (!empty($pathsToDelete['receipt_path'])) {
                            $filePath = $receiptDir . $pathsToDelete['receipt_path'];
                            if (file_exists($filePath)) {
                                if (!@unlink($filePath)) error_log("[API DeleteBooking] Failed to delete receipt: {$filePath}");
                                else error_log("[API DeleteBooking] Deleted receipt: {$filePath}");
                            }
                        }
                        if (!empty($pathsToDelete['extended_receipt_path'])) {
                            $filePath = $receiptDir . $pathsToDelete['extended_receipt_path'];
                            if (file_exists($filePath)) {
                                if (!@unlink($filePath)) error_log("[API DeleteBooking] Failed to delete extended receipt: {$filePath}");
                                else error_log("[API DeleteBooking] Deleted extended receipt: {$filePath}");
                            }
                        }
                    }

                    $activeOrFutureBookingsStmt = $pdo->prepare("
                        SELECT b.id, b.checkin_datetime, b.checkout_datetime_calculated
                        FROM bookings b
                        WHERE b.room_id = :room_id
                          AND b.checkout_datetime_calculated > NOW()
                        ORDER BY b.checkin_datetime ASC
                        LIMIT 1
                    ");
                    $activeOrFutureBookingsStmt->execute([':room_id' => $roomId]);
                    $nextRelevantBooking = $activeOrFutureBookingsStmt->fetch(PDO::FETCH_ASSOC);

                    $newRoomStatus = 'free'; 

                    if ($nextRelevantBooking) {
                        $nextCheckinDateTime = new DateTime($nextRelevantBooking['checkin_datetime'], new DateTimeZone('Asia/Bangkok'));
                        $nowDateTime = new DateTime('now', new DateTimeZone('Asia/Bangkoku'));

                        if ($nextCheckinDateTime <= $nowDateTime) { 
                            $newRoomStatus = 'occupied'; 
                            error_log("[API Delete] Room {$roomId} next relevant booking (ID: ".$nextRelevantBooking['id'].") is currently active or check-in time passed. Setting status to 'occupied'.");
                        }
                        elseif ($nextCheckinDateTime->format('Y-m-d') === $nowDateTime->format('Y-m-d')) { 
                             $newRoomStatus = 'booked';
                             error_log("[API Delete] Room {$roomId} next relevant booking (ID: ".$nextRelevantBooking['id'].") is for later today. Setting status to 'booked'.");
                        }
                        else {
                             error_log("[API Delete] Room {$roomId} next relevant booking (ID: ".$nextRelevantBooking['id'].") is in the future. Setting status to 'free'.");
                        }
                    } else {
                         error_log("[API Delete] Room {$roomId} has no other active or future bookings. Setting status to 'free'.");
                    }

                    $pdo->prepare("UPDATE rooms SET status = ? WHERE id = ?")->execute([$newRoomStatus, $roomId]);

                    $pdo->commit();
                    echo json_encode(['success' => true, 'message' => 'ลบการจองเรียบร้อยแล้ว']);
                } else {
                    $pdo->rollBack();
                    throw new Exception('ไม่สามารถลบการจองได้ หรือการจองไม่มีอยู่', 404);
                }
                exit;
            } else {
                $pdo->rollBack();
                error_log("[API Update] Invalid update_action: {$updateAction}");
                throw new Exception('การดำเนินการอัปเดตไม่ถูกต้อง (invalid update_action)', 400);
            }
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            http_response_code(500);
            error_log("[API Update] PDO Error: " . $e->getMessage() . " Trace: " . $e->getTraceAsString());
            echo json_encode(['success' => false, 'message' => 'เกิดข้อผิดพลาดฐานข้อมูล (PDO) ขณะอัปเดต: ' . $e->getMessage()]);
            exit;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $errorCode = ($e->getCode() >= 400 && $e->getCode() < 600) ? $e->getCode() : 500;
            http_response_code($errorCode);
            error_log("[API Update] General Error: " . $e->getMessage() . " Trace: " . $e->getTraceAsString());
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            exit;
        }
    
    // ***** START: โค้ดที่เพิ่มเข้ามา (NEW ACTION: group_bookings) *****
    case 'group_bookings':
        $pdo->beginTransaction();
        try {
            $bookingIds = $_POST['booking_ids'] ?? [];
            if (count($bookingIds) < 2) {
                throw new Exception("ต้องเลือกอย่างน้อย 2 การจองเพื่อจัดกลุ่ม", 400);
            }

            // ตรวจสอบว่า booking ที่เลือกมายังไม่มี group หรืออยู่ใน group เดียวกัน
            $stmtCheckGroups = $pdo->prepare("SELECT DISTINCT booking_group_id FROM bookings WHERE id IN (" . implode(',', array_fill(0, count($bookingIds), '?')) . ")");
            $stmtCheckGroups->execute($bookingIds);
            $existingGroupIds = $stmtCheckGroups->fetchAll(PDO::FETCH_COLUMN);
            
            $nonNullGroupIds = array_filter($existingGroupIds, function($id) { return $id !== null; });

            if (count($nonNullGroupIds) > 1) {
                throw new Exception("ไม่สามารถรวมกลุ่มได้ เนื่องจากการจองที่เลือกอยู่คนละกลุ่มกันอยู่แล้ว กรุณาตรวจสอบ", 409);
            }

            $firstBookingId = $bookingIds[0];
            $stmtFirstBooking = $pdo->prepare("SELECT customer_name, customer_phone, checkin_datetime FROM bookings WHERE id = ?");
            $stmtFirstBooking->execute([$firstBookingId]);
            $firstBookingData = $stmtFirstBooking->fetch(PDO::FETCH_ASSOC);

            // สร้างกลุ่มใหม่
            $stmtCreateGroup = $pdo->prepare(
                "INSERT INTO booking_groups (customer_name, customer_phone, main_checkin_datetime, created_by_user_id)
                 VALUES (:customer_name, :customer_phone, :checkin_datetime, :user_id)"
            );
            $stmtCreateGroup->execute([
                ':customer_name' => $firstBookingData['customer_name'],
                ':customer_phone' => $firstBookingData['customer_phone'],
                ':checkin_datetime' => $firstBookingData['checkin_datetime'],
                ':user_id' => get_current_user_id()
            ]);
            $newBookingGroupId = $pdo->lastInsertId();

            // อัปเดต booking ทั้งหมดให้ใช้ group_id ใหม่
            $stmtUpdateBookings = $pdo->prepare("UPDATE bookings SET booking_group_id = ? WHERE id IN (" . implode(',', array_fill(0, count($bookingIds), '?')) . ")");
            $updateParams = array_merge([$newBookingGroupId], $bookingIds);
            $stmtUpdateBookings->execute($updateParams);
            
            $pdo->commit();
            echo json_encode(['success' => true, 'message' => 'จัดกลุ่มการจอง ' . count($bookingIds) . ' รายการเรียบร้อยแล้ว', 'new_group_id' => $newBookingGroupId]);

        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            http_response_code($e->getCode() >= 400 ? $e->getCode() : 500);
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    // ***** END: โค้ดที่เพิ่มเข้ามา *****
        
    case 'extend_stay':
        error_log("[API ExtendStay] Starting 'extend_stay' action. POST data: " . print_r($_POST, true) . " FILES: " . print_r($_FILES, true));
        try {
            $pdo->beginTransaction();
            $current_user_id = get_current_user_id();
            if ($current_user_id === null) {
                error_log("[API ExtendStay] Warning: current_user_id is null.");
            }

            $bookingIdExtend = (int)($_POST['booking_id_extend'] ?? 0);
            if (!$bookingIdExtend) throw new Exception('ข้อมูลการขยาย/อัปเกรดไม่ครบถ้วน (ID การจอง)', 400);
            
            // --- START: Fetch booking_group_id ---
            $stmtGetGroup = $pdo->prepare("SELECT booking_group_id FROM bookings WHERE id = ?");
            $stmtGetGroup->execute([$bookingIdExtend]);
            $currentBookingGroupId = $stmtGetGroup->fetchColumn();
            if (!$currentBookingGroupId) {
                error_log("[API ExtendStay] Warning: Booking ID {$bookingIdExtend} does not have a booking_group_id. Receipt handling might fail.");
            }
            // --- END: Fetch booking_group_id ---

            $extendType = $_POST['extend_type'] ?? 'hours'; 
            $extendHours = ($extendType === 'hours') ? (int)($_POST['extend_hours'] ?? 0) : 0;
            $extendNights = ($extendType === 'nights') ? (int)($_POST['extend_nights'] ?? 0) : 0;
            $extendPaymentMethod = trim($_POST['extend_payment_method'] ?? '');
            $paymentForThisExtension = isset($_POST['payment_for_extension']) ? (int)round((float)$_POST['payment_for_extension']) : 0; 

            if (empty($extendPaymentMethod) && $paymentForThisExtension > 0) {
                 throw new Exception('ข้อมูลการขยาย/อัปเกรดไม่ครบถ้วน (วิธีชำระเงิน)', 400);
            }
            if ($extendType === 'hours' && $extendHours <= 0) {
                 throw new Exception('จำนวนชั่วโมงที่เพิ่มต้องมากกว่า 0', 400);
            }
            if ($extendType === 'nights' && $extendNights <= 0) { 
                 throw new Exception('จำนวนคืนที่เพิ่มต้องมากกว่า 0', 400);
            }
            if ($paymentForThisExtension < 0) { 
                 throw new Exception('ยอดชำระสำหรับการขยายเวลาต้องไม่ติดลบ', 400);
            }

            $stmtBooking = $pdo->prepare("
                SELECT b.*, r.zone as room_current_zone, r.room_number as room_number_for_log, r.price_per_day as room_price_per_day,
                       r.price_short_stay as room_price_short_stay,
                       r.allow_short_stay as room_allow_short_stay,
                       r.ask_deposit_on_overnight as room_ask_deposit_f,
                       r.price_per_hour_extension, 
                       COALESCE(r.short_stay_duration_hours, ".DEFAULT_SHORT_STAY_DURATION_HOURS.") as room_base_short_stay_duration
                FROM bookings b
                JOIN rooms r ON b.room_id = r.id
                WHERE b.id = ?
            ");
            $stmtBooking->execute([$bookingIdExtend]);
            $booking = $stmtBooking->fetch(PDO::FETCH_ASSOC);

            if (!$booking) {
                throw new Exception("ไม่พบข้อมูลการจอง ID: {$bookingIdExtend} สำหรับดำเนินการ", 404);
            }

            // ***** START: โค้ดที่แก้ไข (ปรับปรุงการคำนวณราคาและตรรกะ) *****
            $calculatedExtensionCost = 0; 
            $newExtendedHoursTotal = (int)($booking['extended_hours'] ?? 0); 
            $newNightsTotal = (int)($booking['nights'] ?? 0); 
            $newPricePerNight = (int)round((float)($booking['price_per_night'] ?? 0));
            $newBookingType = $booking['booking_type'];
            $newDepositAmount = (int)round((float)($booking['deposit_amount'] ?? 0));
            $new_total_price_for_booking_record = (float)($booking['total_price'] ?? 0); // << เริ่มต้นด้วยราคาเดิม

            $currentCheckoutCalc = new DateTime($booking['checkout_datetime_calculated']);
            $newCheckoutDatetimeCalculatedSql = $booking['checkout_datetime_calculated'];

            if ($extendType === 'hours') {
                $_room_specific_rate_val = isset($booking['price_per_hour_extension']) ? (int)round((float)$booking['price_per_hour_extension']) : null;
                $hourlyRateForThisRoomExtension = $_room_specific_rate_val ?? HOURLY_EXTENSION_RATE; 
                if ($hourlyRateForThisRoomExtension <=0) $hourlyRateForThisRoomExtension = HOURLY_EXTENSION_RATE; 

                $calculatedExtensionCost = $extendHours * $hourlyRateForThisRoomExtension; 
                
                $newExtendedHoursTotal += $extendHours;
                $currentCheckoutCalc->add(new DateInterval("PT{$extendHours}H"));
                $newCheckoutDatetimeCalculatedSql = $currentCheckoutCalc->format('Y-m-d H:i:s');
                
                // << เพิ่มค่าใช้จ่ายเข้าไปใน total_price เดิม >>
                $new_total_price_for_booking_record += $calculatedExtensionCost;

            } elseif ($extendType === 'nights') {
                 if ($booking['booking_type'] === 'short_stay') {
                     throw new Exception('ไม่สามารถขยายเป็น "คืน" สำหรับการจองแบบชั่วคราวได้ กรุณาใช้ "เปลี่ยนเป็นค้างคืน" หรือขยายเป็น "ชั่วโมง"', 400);
                 }
                $pricePerNightForExtension = (int)round((float)($booking['price_per_night'] ?? $booking['room_price_per_day'])); 
                if ($pricePerNightForExtension <=0) {
                    throw new Exception("ไม่สามารถคำนวณค่าขยายเวลาได้: ราคาต่อคืนของห้องไม่ถูกต้อง", 500);
                }
                $calculatedExtensionCost = $extendNights * $pricePerNightForExtension; 
                
                // << เพิ่มค่าใช้จ่ายเข้าไปใน total_price เดิม >>
                $new_total_price_for_booking_record += $calculatedExtensionCost;
                
                $newNightsTotal += $extendNights; 

                list($h, $m, $s) = explode(':', CHECKOUT_TIME_STR);
                $currentCheckoutCalc->add(new DateInterval("P{$extendNights}D"));
                $currentCheckoutCalc->setTime((int)$h, (int)$m, (int)$s);
                $newCheckoutDatetimeCalculatedSql = $currentCheckoutCalc->format('Y-m-d H:i:s');

            } elseif ($extendType === 'upgrade_to_overnight') {
                if ($booking['booking_type'] !== 'short_stay' || $booking['room_current_zone'] !== 'F') {
                    throw new Exception('การอัปเกรดเป็นค้างคืนใช้ได้เฉพาะการจองชั่วคราวในโซน F เท่านั้น', 400);
                }
                
                $stmtExistingAddons = $pdo->prepare("SELECT SUM(price_at_booking * quantity) FROM booking_addons WHERE booking_id = ?");
                $stmtExistingAddons->execute([$bookingIdExtend]);
                $currentTotalAddonCostForBooking = (int)round((float)$stmtExistingAddons->fetchColumn());

                $newBookingType = 'overnight';
                $newNightsTotal = 1;
                $newExtendedHoursTotal = 0;

                $upgradeTime = new DateTime('now', new DateTimeZone('Asia/Bangkok'));
                $newCheckoutDateTimeObj = clone $upgradeTime;
                $newCheckoutDateTimeObj->modify('+1 day');
                list($h_co, $m_co, $s_co) = explode(':', CHECKOUT_TIME_STR);
                $newCheckoutDateTimeObj->setTime((int)$h_co, (int)$m_co, (int)$s_co);
                $newCheckoutDatetimeCalculatedSql = $newCheckoutDateTimeObj->format('Y-m-d H:i:s');
                
                $original_short_stay_total_price = (int)round((float)$booking['total_price']); 
                $original_short_stay_deposit = (int)round((float)$booking['deposit_amount']); 
                $original_short_stay_room_component_cost = $original_short_stay_total_price - $currentTotalAddonCostForBooking - $original_short_stay_deposit; 

                $target_overnight_room_price = (int)round((float)($booking['room_price_per_day'] ?? 600)); 
                $newPricePerNight = $target_overnight_room_price;

                $additional_payment_for_room_upgrade = max(0, $target_overnight_room_price - $original_short_stay_room_component_cost); 
                
                if ($booking['room_ask_deposit_f'] == '1' || $booking['room_current_zone'] !== 'F') { 
                     $newDepositAmount = FIXED_DEPOSIT_AMOUNT; 
                } else {
                     $newDepositAmount = 0; 
                }
                $deposit_increase = max(0, $newDepositAmount - $original_short_stay_deposit);
                $calculatedExtensionCost = $additional_payment_for_room_upgrade + $deposit_increase;
                
                // << คำนวณ total_price ใหม่ทั้งหมดสำหรับการอัปเกรด >>
                $new_total_price_for_booking_record = $target_overnight_room_price + $currentTotalAddonCostForBooking + $newDepositAmount;
            }

            if ($calculatedExtensionCost != $paymentForThisExtension && $paymentForThisExtension >= 0) { 
                 error_log("[API ExtendStay] Mismatch: Calculated cost ({$calculatedExtensionCost}) vs. payment made ({$paymentForThisExtension}) for Booking ID {$bookingIdExtend}. Using payment made for financial records.");
            }
            // ***** END: โค้ดที่แก้ไข *****

            // ***** START: โค้ดที่แก้ไข (ปรับปรุง Overlap Check) *****
            // การตรวจสอบ Overlap จะต้องไม่พิจารณาการจองอื่นที่อยู่ใน "กลุ่มเดียวกัน" กับการจองนี้
            if ($newCheckoutDatetimeCalculatedSql > $booking['checkout_datetime_calculated']) {
                $stmtCheckOverlap = $pdo->prepare("
                    SELECT COUNT(*) FROM bookings
                    WHERE room_id = :room_id
                    AND id != :current_booking_id
                    AND (booking_group_id IS NULL OR booking_group_id != :current_booking_group_id) -- << เพิ่มเงื่อนไขนี้
                    AND checkout_datetime_calculated > :current_checkout_original
                    AND checkin_datetime < :new_proposed_checkout
                ");
                $stmtCheckOverlap->execute([
                    ':room_id' => $booking['room_id'],
                    ':current_booking_id' => $bookingIdExtend,
                    ':current_booking_group_id' => $currentBookingGroupId, // << ส่ง group_id ปัจจุบัน
                    ':current_checkout_original' => $booking['checkout_datetime_calculated'],
                    ':new_proposed_checkout' => $newCheckoutDatetimeCalculatedSql
                ]);
                if ($stmtCheckOverlap->fetchColumn() > 0) {
                    throw new Exception("ห้องพัก ".htmlspecialchars($booking['room_current_zone'] . $booking['room_number_for_log'])." ไม่ว่างสำหรับช่วงเวลาที่ขยาย/อัปเกรดเพิ่ม (มีการจองอื่นนอกกลุ่มนี้ขวางอยู่)", 409);
                }
            }
            // ***** END: โค้ดที่แก้ไข *****
            
            // --- START: MODIFIED RECEIPT HANDLING ---
            $is_new_extend_receipt_uploaded = isset($_FILES['extend_receipt']) && $_FILES['extend_receipt']['error'] === UPLOAD_ERR_OK;
            if ($is_new_extend_receipt_uploaded) {
                if (!$currentBookingGroupId) {
                    throw new Exception('ไม่สามารถอัปโหลดสลิปได้เนื่องจากไม่พบกลุ่มการจอง', 500);
                }
                $receiptDir = __DIR__ . '/../uploads/receipts/';
                if (!is_dir($receiptDir)) @mkdir($receiptDir, 0777, true);
                if (!is_writable($receiptDir)) throw new Exception('โฟลเดอร์หลักฐาน (ส่วนขยาย/อัปเกรด) ไม่มีสิทธิ์เขียน', 500);

                $temp_file_path = $_FILES['extend_receipt']['tmp_name'];
                $original_filename = $_FILES['extend_receipt']['name'];
                $ext = strtolower(pathinfo($original_filename, PATHINFO_EXTENSION));
                $allowed_exts = ['jpg', 'jpeg', 'png', 'gif', 'pdf'];

                if (!in_array($ext, $allowed_exts)) {
                    throw new Exception('ไฟล์หลักฐาน (ส่วนขยาย/อัปเกรด) ต้องเป็นรูปภาพหรือ PDF', 400);
                }
                $new_extend_receipt_filename_only = 'grp_rcpt_' . $currentBookingGroupId . '_' . uniqid('ext_') . '.' . $ext;
                $new_extend_receipt_destination_path = $receiptDir . $new_extend_receipt_filename_only;
                
                $moved_successfully = false;
                if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif'])) {
                    if (function_exists('process_uploaded_image_with_compression') && process_uploaded_image_with_compression($temp_file_path, $new_extend_receipt_destination_path, $original_filename)) { 
                        $moved_successfully = true;
                    }
                } elseif ($ext === 'pdf') {
                    if (move_uploaded_file($temp_file_path, $new_extend_receipt_destination_path)) { 
                        $moved_successfully = true;
                    }
                }

                if(!$moved_successfully){
                    throw new Exception('การประมวลผลและบันทึกรูปภาพหลักฐาน (ส่วนขยาย/อัปเกรด) ล้มเหลว', 500);
                }
                
                // Insert into group receipts table
                $stmtInsertGroupReceipt = $pdo->prepare(
                    "INSERT INTO booking_group_receipts (booking_group_id, receipt_path, description, uploaded_by_user_id, amount, payment_method)
                     VALUES (:booking_group_id, :receipt_path, :description, :user_id, :amount, :payment_method)"
                );
                $stmtInsertGroupReceipt->execute([
                    ':booking_group_id' => $currentBookingGroupId,
                    ':receipt_path' => $new_extend_receipt_filename_only,
                    ':description' => 'สลิปขยายเวลา/อัปเกรด',
                    ':user_id' => $current_user_id,
                    ':amount' => $paymentForThisExtension,
                    ':payment_method' => $extendPaymentMethod
                ]);
                error_log("[API ExtendStay] New receipt {$new_extend_receipt_filename_only} added to booking group {$currentBookingGroupId}.");
            } elseif ($paymentForThisExtension > 0 && !$is_new_extend_receipt_uploaded && $extendPaymentMethod !== 'เงินสด') {
                throw new Exception('กรุณาแนบหลักฐานการชำระเงินสำหรับการขยาย/อัปเกรดนี้ (ยกเว้นกรณีชำระด้วยเงินสด)', 400);
            }
            // --- END: MODIFIED RECEIPT HANDLING ---

            // `extended_receipt_path` is removed from this update query
            $updateSql = "UPDATE bookings
                          SET booking_type = :new_booking_type,
                              nights = :new_nights,
                              price_per_night = :new_price_per_night, 
                              extended_hours = :new_extended_hours,
                              checkout_datetime_calculated = :new_checkout_datetime,
                              total_price = :new_total_price, 
                              amount_paid = amount_paid + :payment_for_extension, 
                              additional_paid_amount = COALESCE(additional_paid_amount, 0) + :payment_for_extension_additional, 
                              deposit_amount = :new_deposit_amount, 
                              extended_payment_method = :extend_payment_method,
                              last_extended_at = NOW(),
                              {$logTimestampField} = NOW(),
                              last_modified_by_user_id = :user_id
                          WHERE id = :booking_id";
            $stmtUpdate = $pdo->prepare($updateSql);
            $stmtUpdate->execute([
                ':new_booking_type' => $newBookingType,
                ':new_nights' => $newNightsTotal,
                ':new_price_per_night' => ($newBookingType === 'overnight' ? $newPricePerNight : (int)round((float)$booking['price_per_night'])),
                ':new_extended_hours' => $newExtendedHoursTotal,
                ':new_checkout_datetime' => $newCheckoutDatetimeCalculatedSql,
                ':new_total_price' => $new_total_price_for_booking_record,
                ':payment_for_extension' => $paymentForThisExtension, 
                ':payment_for_extension_additional' => $paymentForThisExtension, 
                ':new_deposit_amount' => $newDepositAmount,
                ':extend_payment_method' => $extendPaymentMethod,
                ':user_id' => $current_user_id,
                ':booking_id' => $bookingIdExtend
            ]);

            $pdo->commit();
            $successMessage = 'ดำเนินการเรียบร้อยแล้ว';
            if ($extendType === 'upgrade_to_overnight') {
                $successMessage = 'อัปเกรดเป็นค้างคืนเรียบร้อย (ยอดรวมค่าห้อง '.$new_total_price_for_booking_record.' บ. รวมมัดจำ)'; 
            } elseif ($extendType === 'hours') {
                $successMessage = 'ขยายเวลาการเข้าพัก (ชั่วโมง) เรียบร้อยแล้ว';
            } elseif ($extendType === 'nights') {
                $successMessage = 'ขยายเวลาการเข้าพัก (คืน) เรียบร้อยแล้ว';
            }
            echo json_encode(['success' => true, 'message' => $successMessage]);
            exit;

        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            http_response_code(500);
            error_log("[API ExtendStay] PDO Error: " . $e->getMessage() . " Trace: " . $e->getTraceAsString());
            echo json_encode(['success' => false, 'message' => 'เกิดข้อผิดพลาดฐานข้อมูล (PDO) ขณะดำเนินการ: ' . $e->getMessage()]);
            exit;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $errorCode = ($e->getCode() >= 400 && $e->getCode() < 600) ? $e->getCode() : 500;
            http_response_code($errorCode);
            error_log("[API ExtendStay] General Error: " . $e->getMessage() . " Trace: " . $e->getTraceAsString());
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            exit;
        }
        
    case 'edit_booking_details': 
        error_log("[API EditDetails] Starting. POST: " . print_r($_POST, true) . " FILES: " . print_r($_FILES, true));
        try {
            $pdo->beginTransaction();
            $current_user_id = get_current_user_id();
            if ($current_user_id === null) {
                error_log("[API EditDetails] Warning: current_user_id is null.");
            }

            $bookingIdToEdit = (int)($_POST['booking_id_edit_details'] ?? 0);
            if (!$bookingIdToEdit) throw new Exception('ไม่พบรหัสการจอง', 400);

            // --- START: Fetch booking_group_id ---
            $stmtGetGroup = $pdo->prepare("SELECT booking_group_id FROM bookings WHERE id = ?");
            $stmtGetGroup->execute([$bookingIdToEdit]);
            $currentBookingGroupId = $stmtGetGroup->fetchColumn();
            if (!$currentBookingGroupId) {
                error_log("[API EditDetails] Warning: Booking ID {$bookingIdToEdit} does not have a booking_group_id. Receipt handling might fail.");
            }
            // --- END: Fetch booking_group_id ---

            $newNotes = isset($_POST['edit_notes']) ? trim($_POST['edit_notes']) : null;
            $selected_addons_modal_raw = $_POST['selected_addons_modal'] ?? [];

            $adjustmentType = $_POST['adjustment_type'] ?? 'none';
            $adjustmentAmount = isset($_POST['adjustment_amount']) ? (int)round((float)$_POST['adjustment_amount']) : 0; 
            $adjustmentPaymentMethod = ($adjustmentType !== 'none' && $adjustmentAmount != 0) ? trim($_POST['adjustment_payment_method'] ?? '') : null;
            
            if ($adjustmentType !== 'none' && $adjustmentAmount > 0 && empty($adjustmentPaymentMethod)) { 
                 throw new Exception('ข้อมูลการปรับยอดไม่ครบถ้วน: กรุณาระบุวิธีการชำระ/คืนเงิน', 400);
            }
             if ($adjustmentType !== 'none' && $adjustmentAmount < 0) { 
                throw new Exception('จำนวนเงินที่ปรับต้องไม่ติดลบ (ใช้ประเภทการปรับยอดเพื่อระบุเพิ่ม/ลด)', 400);
            }

            $stmtFetch = $pdo->prepare("SELECT b.*, r.zone as room_zone, r.price_per_day as room_daily_price, r.price_short_stay as room_short_price, r.ask_deposit_on_overnight as room_ask_deposit_f FROM bookings b JOIN rooms r ON b.room_id = r.id WHERE b.id = ?");
            $stmtFetch->execute([$bookingIdToEdit]);
            $bookingDetails = $stmtFetch->fetch(PDO::FETCH_ASSOC);
            if (!$bookingDetails) {
                throw new Exception('ไม่พบข้อมูลการจอง (ID: ' . htmlspecialchars($bookingIdToEdit) . ')', 404);
            }

            $stmtOldAddons = $pdo->prepare("SELECT addon_service_id, quantity, price_at_booking FROM booking_addons WHERE booking_id = ?");
            $stmtOldAddons->execute([$bookingIdToEdit]);
            $oldAddonsFromDb = $stmtOldAddons->fetchAll(PDO::FETCH_ASSOC);
            $oldAddonsMap = [];
            $original_total_addon_cost_from_db = 0; 
            foreach($oldAddonsFromDb as $oa){
                $current_addon_price = (int)round((float)$oa['price_at_booking']); 
                $oldAddonsMap[$oa['addon_service_id']] = ['quantity' => (int)$oa['quantity'], 'price_at_booking' => $current_addon_price];
                $original_total_addon_cost_from_db += $current_addon_price * (int)$oa['quantity'];
            }

            $newly_selected_addons_for_db_map = [];
            $new_total_addon_cost_from_modal_calculated = 0; 
            $addons_structure_changed = false;

            if (isset($_POST['selected_addons_modal'])) { 
                if (!empty($selected_addons_modal_raw)) {
                    $addon_ids_from_modal = array_keys($selected_addons_modal_raw);
                    if (!empty($addon_ids_from_modal)) {
                        $placeholders = implode(',', array_fill(0, count($addon_ids_from_modal), '?'));
                        $stmt_addon_prices = $pdo->prepare("SELECT id, price FROM addon_services WHERE id IN ($placeholders) AND is_active = 1");
                        $stmt_addon_prices->execute($addon_ids_from_modal);
                        $db_active_addons_info = $stmt_addon_prices->fetchAll(PDO::FETCH_KEY_PAIR);

                        foreach ($selected_addons_modal_raw as $addon_id_str => $addon_data_from_modal) {
                            $addon_id = (int)$addon_id_str;
                            if (isset($db_active_addons_info[$addon_id]) && isset($addon_data_from_modal['id']) && (int)$addon_data_from_modal['id'] === $addon_id) {
                                $quantity = isset($addon_data_from_modal['quantity']) ? max(1, (int)$addon_data_from_modal['quantity']) : 1;
                                $current_price_for_this_addon = (int)round((float)$db_active_addons_info[$addon_id]); 
                                $new_total_addon_cost_from_modal_calculated += $current_price_for_this_addon * $quantity;
                                $newly_selected_addons_for_db_map[$addon_id] = ['quantity' => $quantity, 'price_at_booking' => $current_price_for_this_addon];
                            }
                        }
                    }
                }
                // Check for changes in addon structure
                if (count($oldAddonsMap) !== count($newly_selected_addons_for_db_map)) {
                    $addons_structure_changed = true;
                } else {
                    foreach ($newly_selected_addons_for_db_map as $new_addon_id => $new_addon_details) {
                        if (!isset($oldAddonsMap[$new_addon_id]) ||
                            (int)$oldAddonsMap[$new_addon_id]['quantity'] !== (int)$new_addon_details['quantity'] ||
                            (int)$oldAddonsMap[$new_addon_id]['price_at_booking'] != (int)$new_addon_details['price_at_booking'] ) { 
                            $addons_structure_changed = true;
                            break;
                        }
                    }
                    if (!$addons_structure_changed) { 
                        foreach ($oldAddonsMap as $old_addon_id => $old_addon_details) {
                            if (!isset($newly_selected_addons_for_db_map[$old_addon_id])) {
                                $addons_structure_changed = true;
                                break;
                            }
                        }
                    }
                }
            } else { 
                // If selected_addons_modal is not in POST, assume no change and retain existing addons
                $addons_structure_changed = false;
                $newly_selected_addons_for_db_map = $oldAddonsMap; 
                $new_total_addon_cost_from_modal_calculated = $original_total_addon_cost_from_db; 
            }
            
            $fieldsToUpdate = [];
            $bindings = [];    
            $dataChangedOverall = false;

            if ($addons_structure_changed) {
                error_log("[API EditDetails] Addons structure changed for booking ID: {$bookingIdToEdit}");
                $dataChangedOverall = true;

                $deleteOldAddonsStmt = $pdo->prepare("DELETE FROM booking_addons WHERE booking_id = ?");
                $deleteOldAddonsStmt->execute([$bookingIdToEdit]);

                if (!empty($newly_selected_addons_for_db_map)) {
                    $stmtInsertAddon = $pdo->prepare(
                        "INSERT INTO booking_addons (booking_id, addon_service_id, quantity, price_at_booking) VALUES (?, ?, ?, ?)"
                    );
                    foreach ($newly_selected_addons_for_db_map as $addon_id => $details) { 
                        $stmtInsertAddon->execute([$bookingIdToEdit, $addon_id, $details['quantity'], $details['price_at_booking']]);
                    }
                }
            }

            $room_cost_component; 
            $booking_deposit_amount_int = (int)round((float)($bookingDetails['deposit_amount'] ?? 0)); 
            if ($bookingDetails['booking_type'] === 'overnight') {
                $booking_price_per_night_int = (int)round((float)($bookingDetails['price_per_night'] ?? 0)); 
                $room_cost_component = (int)($bookingDetails['nights'] ?? 0) * $booking_price_per_night_int;
            } else { 
                $booking_total_price_int = (int)round((float)$bookingDetails['total_price']); 
                $room_cost_component = $booking_total_price_int - $original_total_addon_cost_from_db - $booking_deposit_amount_int;
            }

            $new_calculated_total_price_of_booking = $room_cost_component + $new_total_addon_cost_from_modal_calculated + $booking_deposit_amount_int; 

            $current_booking_total_price_int = (int)round((float)$bookingDetails['total_price']);
            if ($current_booking_total_price_int != $new_calculated_total_price_of_booking ) { 
                $fieldsToUpdate[] = "total_price = :new_total_booking_price_val";
                $bindings[':new_total_booking_price_val'] = $new_calculated_total_price_of_booking; 
                $dataChangedOverall = true;
            }

            if ($adjustmentType !== 'none' && $adjustmentAmount >= 0) { 
                $dataChangedOverall = true;
                if ($adjustmentAmount > 0) { 
                    if ($adjustmentType === 'add') {
                        $fieldsToUpdate[] = "amount_paid = amount_paid + :adjustment_amount_val";
                        $fieldsToUpdate[] = "additional_paid_amount = COALESCE(additional_paid_amount, 0) + :adjustment_amount_val_additional"; 
                    } elseif ($adjustmentType === 'reduce') {
                        $current_total_actually_paid_by_customer = (int)round((float)($bookingDetails['amount_paid'] ?? 0)); 
                        if ($adjustmentAmount > $current_total_actually_paid_by_customer) {
                            throw new Exception('จำนวนเงินที่คืน (' . $adjustmentAmount . ') มากกว่ายอดที่ลูกค้าชำระแล้วทั้งหมด (' . $current_total_actually_paid_by_customer . ')', 400);
                        }
                        $fieldsToUpdate[] = "amount_paid = amount_paid - :adjustment_amount_val";
                        $fieldsToUpdate[] = "additional_paid_amount = GREATEST(0, COALESCE(additional_paid_amount, 0) - :adjustment_amount_val_additional)"; 
                    }
                    $bindings[':adjustment_amount_val'] = $adjustmentAmount; 
                    $bindings[':adjustment_amount_val_additional'] = $adjustmentAmount; 
                }

                if (!empty($adjustmentPaymentMethod)) {
                    $fieldsToUpdate[] = "extended_payment_method = :adj_payment_method";
                    $bindings[':adj_payment_method'] = $adjustmentPaymentMethod;
                }

                // --- START: MODIFIED RECEIPT HANDLING ---
                if (isset($_FILES['adjustment_receipt']) && $_FILES['adjustment_receipt']['error'] === UPLOAD_ERR_OK) {
                    if (!$currentBookingGroupId) {
                        throw new Exception('ไม่สามารถอัปโหลดสลิปได้เนื่องจากไม่พบกลุ่มการจอง', 500);
                    }
                    $receiptDir = __DIR__ . '/../uploads/receipts/';
                    if (!is_dir($receiptDir)) { @mkdir($receiptDir, 0777, true); }
                    if (!is_writable($receiptDir)) { throw new Exception('โฟลเดอร์หลักฐานไม่มีสิทธิ์ในการเขียน', 500); }

                    $temp_file_path = $_FILES['adjustment_receipt']['tmp_name'];
                    $original_filename = $_FILES['adjustment_receipt']['name'];
                    $ext = strtolower(pathinfo($original_filename, PATHINFO_EXTENSION));
                    $allowed_exts = ['jpg', 'jpeg', 'png', 'gif', 'pdf'];

                    if (!in_array($ext, $allowed_exts)) {
                        throw new Exception('ไฟล์หลักฐาน (ปรับยอด) ต้องเป็นรูปภาพหรือ PDF', 400);
                    }

                    $newAdjustmentReceiptFileName = 'grp_rcpt_' . $currentBookingGroupId . '_' . uniqid('adj_') . '.' . $ext;
                    $receiptDest = $receiptDir . $newAdjustmentReceiptFileName;
                    
                    $moved_successfully = false;
                    if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif'])) {
                        if (function_exists('process_uploaded_image_with_compression') && process_uploaded_image_with_compression($temp_file_path, $receiptDest, $original_filename)) {
                            $moved_successfully = true;
                        }
                    } elseif ($ext === 'pdf') {
                        if (move_uploaded_file($temp_file_path, $receiptDest)) { 
                            $moved_successfully = true;
                        }
                    }

                    if(!$moved_successfully){
                        throw new Exception('การประมวลผลและบันทึกรูปภาพหลักฐาน (ปรับยอด) ล้มเหลว', 500);
                    }
                    
                    // Insert into group receipts table instead of updating bookings table
                    $stmtInsertGroupReceipt = $pdo->prepare(
                        "INSERT INTO booking_group_receipts (booking_group_id, receipt_path, description, uploaded_by_user_id, amount, payment_method)
                         VALUES (:booking_group_id, :receipt_path, :description, :user_id, :amount, :payment_method)"
                    );
                    $stmtInsertGroupReceipt->execute([
                        ':booking_group_id' => $currentBookingGroupId,
                        ':receipt_path' => $newAdjustmentReceiptFileName,
                        ':description' => 'สลิปปรับยอด',
                        ':user_id' => $current_user_id,
                        ':amount' => ($adjustmentType === 'add' ? $adjustmentAmount : -$adjustmentAmount),
                        ':payment_method' => $adjustmentPaymentMethod
                    ]);
                    error_log("[API EditDetails] Adjustment receipt {$newAdjustmentReceiptFileName} added to booking group {$currentBookingGroupId}.");
                }
                // --- END: MODIFIED RECEIPT HANDLING ---
            }

            $finalNotesToStore = ($newNotes === null || $newNotes === '') ? null : $newNotes;
            if ($finalNotesToStore !== ($bookingDetails['notes'] ?? null)) {
                $fieldsToUpdate[] = "notes = :notes_val";
                $bindings[':notes_val'] = $finalNotesToStore;
                $dataChangedOverall = true;
            }


            if ($dataChangedOverall && !empty($fieldsToUpdate)) {
                $fieldsToUpdate[] = "{$logTimestampField} = NOW()";
                $fieldsToUpdate[] = "last_modified_by_user_id = :last_modified_by_user_id_val";
                $bindings[':last_modified_by_user_id_val'] = $current_user_id;

                $updateSqlParts = implode(", ", $fieldsToUpdate);
                $updateSql = "UPDATE bookings SET {$updateSqlParts} WHERE id = :booking_id_main_edit_val";
                $bindings[':booking_id_main_edit_val'] = $bookingIdToEdit;

                error_log("[API EditDetails - No Nights Edit] Update SQL: " . $updateSql);
                error_log("[API EditDetails - No Nights Edit] Bindings: " . print_r($bindings, true));

                $stmtUpdate = $pdo->prepare($updateSql);
                $stmtUpdate->execute($bindings);

                $pdo->commit();
                echo json_encode(['success' => true, 'message' => 'แก้ไขรายละเอียดการจองเรียบร้อยแล้ว']);
            } elseif ($addons_structure_changed) { 
                // If only addons changed, just update timestamps
                $updateTimestampsSql = "UPDATE bookings SET {$logTimestampField} = NOW(), last_modified_by_user_id = :user_id WHERE id = :booking_id";
                $stmtTsUpdate = $pdo->prepare($updateTimestampsSql);
                $stmtTsUpdate->execute([':user_id' => $current_user_id, ':booking_id' => $bookingIdToEdit]);

                $pdo->commit();
                echo json_encode(['success' => true, 'message' => 'แก้ไขรายการเสริมเรียบร้อยแล้ว']);
            } else {
                $pdo->rollBack();
                echo json_encode(['success' => true, 'message' => 'ไม่มีข้อมูลที่ต้องอัปเดต']);
            }
            exit;

        } catch (PDOException $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
                http_response_code(500);
                error_log("[API EditDetails] PDO Error: " . $e->getMessage() . " | SQL: " . ($updateSql ?? "N/A"));
                echo json_encode(['success' => false, 'message' => 'เกิดข้อผิดพลาดฐานข้อมูล: ' . $e->getMessage()]);
                exit;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
                $errorCode = ($e->getCode() >= 400 && $e->getCode() < 600) ? $e->getCode() : 500;
                http_response_code($errorCode);
                error_log("[API EditDetails] General Error: " . $e->getMessage());
                echo json_encode(['success' => false, 'message' => $e->getMessage()]);
                exit;
        }
    
    case 'update_room_price':
        try {
            $pdo->beginTransaction();
            $roomIdForPriceUpdate = (int)($_POST['room_id_price_update'] ?? 0);
            $newPricePerDay = isset($_POST['new_price_per_day']) && $_POST['new_price_per_day'] !== '' ? (int)round(filter_var($_POST['new_price_per_day'], FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION)) : null;
            $newPriceShortStay = isset($_POST['new_price_short_stay']) && $_POST['new_price_short_stay'] !== '' ? (int)round(filter_var($_POST['new_price_short_stay'], FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION)) : null;
            $newPricePerHourExtension = isset($_POST['new_price_per_hour_extension']) && $_POST['new_price_per_hour_extension'] !== '' ? (int)round(filter_var($_POST['new_price_per_hour_extension'], FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION)) : null;


            if (!$roomIdForPriceUpdate) {
                throw new Exception('ไม่พบรหัสห้องสำหรับอัปเดตราคา', 400);
            }
            if ($newPricePerDay !== null && $newPricePerDay < 0) { 
                throw new Exception('ราคาต่อวันต้องไม่ติดลบ', 400);
            }
            if ($newPriceShortStay !== null && $newPriceShortStay < 0) { 
                throw new Exception('ราคาพักชั่วคราวต้องไม่ติดลบ', 400);
            }
            if ($newPricePerHourExtension !== null && $newPricePerHourExtension < 0) { 
                throw new Exception('ราคาเพิ่มต่อชั่วโมงต้องไม่ติดลบ', 400);
            }
            
            $stmtFetchRoom = $pdo->prepare("SELECT price_per_day, price_short_stay, price_per_hour_extension FROM rooms WHERE id = ?");
            $stmtFetchRoom->execute([$roomIdForPriceUpdate]);
            $currentRoomPrices = $stmtFetchRoom->fetch(PDO::FETCH_ASSOC);

            if (!$currentRoomPrices) {
                 throw new Exception('ไม่พบห้อง ID: '.htmlspecialchars($roomIdForPriceUpdate).' สำหรับอัปเดตราคา', 404);
            }

            $updateFieldsPrice = []; 
            $updateBindingsPrice = []; 
            $priceChanged = false;    

            $currentPricePerDayInt = isset($currentRoomPrices['price_per_day']) ? (int)round((float)$currentRoomPrices['price_per_day']) : 0;
            $currentPriceShortStayInt = isset($currentRoomPrices['price_short_stay']) ? (int)round((float)$currentRoomPrices['price_short_stay']) : 0;
            $currentPricePerHourExtensionInt = isset($currentRoomPrices['price_per_hour_extension']) ? (int)round((float)$currentRoomPrices['price_per_hour_extension']) : 0;


            if ($newPricePerDay !== null && $currentPricePerDayInt != $newPricePerDay) { 
                $updateFieldsPrice[] = "price_per_day = :price_day";
                $updateBindingsPrice[':price_day'] = $newPricePerDay; 
                $priceChanged = true;
            }
            if ($newPriceShortStay !== null && $currentPriceShortStayInt != $newPriceShortStay) { 
                $updateFieldsPrice[] = "price_short_stay = :price_short";
                $updateBindingsPrice[':price_short'] = $newPriceShortStay; 
                $priceChanged = true;
            }
            if ($newPricePerHourExtension !== null && $currentPricePerHourExtensionInt != $newPricePerHourExtension) { 
                $updateFieldsPrice[] = "price_per_hour_extension = :price_hour_ext";
                $updateBindingsPrice[':price_hour_ext'] = $newPricePerHourExtension; 
                $priceChanged = true;
            }

            if ($priceChanged && !empty($updateFieldsPrice)) {
                $updateBindingsPrice[':room_id_price'] = $roomIdForPriceUpdate; 
                $sqlPriceUpdate = "UPDATE rooms SET " . implode(", ", $updateFieldsPrice) . " WHERE id = :room_id_price";
                $stmtPriceUpdate = $pdo->prepare($sqlPriceUpdate);
                $stmtPriceUpdate->execute($updateBindingsPrice); 
                $pdo->commit(); 
                echo json_encode(['success' => true, 'message' => 'อัปเดตราคาห้องพัก ID: ' . htmlspecialchars($roomIdForPriceUpdate) . ' เรียบร้อยแล้ว']);
            } else {
                $pdo->rollBack(); 
                echo json_encode(['success' => true, 'message' => 'ราคาห้องพัก ID: ' . htmlspecialchars($roomIdForPriceUpdate) . ' ไม่มีการเปลี่ยนแปลง']);
            }
            exit;

        } catch (Exception $e) { 
            if ($pdo->inTransaction()) $pdo->rollBack(); 
            $errorCode = ($e->getCode() >= 400 && $e->getCode() < 600) ? $e->getCode() : 500; 
            http_response_code($errorCode);
            error_log("[API UpdateRoomPrice] Error: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            exit;
        }
        
    case 'get_system_setting': 
        try {
            $key = $_GET['setting_key'] ?? '';
            if (empty($key)) {
                throw new Exception('ไม่ได้ระบุ setting_key', 400);
            }
            $value = get_system_setting_value($pdo, $key, null); 

            if (($key === 'hourly_extension_rate' || $key === 'default_fixed_deposit') && $value !== null) {
                $value = (int)round((float)$value);
            }

            if ($value === null && $key !== 'hourly_extension_rate' && $key !== 'default_fixed_deposit') { 
                $stmtCheckKey = $pdo->prepare("SELECT COUNT(*) FROM system_settings WHERE setting_key = ?");
                $stmtCheckKey->execute([$key]);
                if ($stmtCheckKey->fetchColumn() == 0) {
                     echo json_encode(['success' => false, 'setting_key' => $key, 'value' => null, 'message' => 'ไม่พบการตั้งค่านี้ในระบบ: ' . htmlspecialchars($key)]);
                } else {
                     echo json_encode(['success' => true, 'setting_key' => $key, 'value' => $value, 'message' => 'การตั้งค่านี้มีค่าเป็น null']);
                }

            } else {
                echo json_encode(['success' => true, 'setting_key' => $key, 'value' => $value]);
            }
            exit;
        } catch (Exception $e) {
            $errorCode = ($e->getCode() >= 400 && $e->getCode() < 600) ? $e->getCode() : 500;
            http_response_code($errorCode);
            error_log("[API GetSetting] Error: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            exit;
        }
        
    case 'update_system_setting':
        try {
            $pdo->beginTransaction();
            $key = $_POST['setting_key'] ?? '';
            $value_raw = $_POST['setting_value'] ?? ''; 

            if (empty($key)) {
                throw new Exception('ไม่ได้ระบุ setting_key', 400);
            }
            
            $value_to_store = $value_raw; 

            if ($key === 'hourly_extension_rate' || $key === 'default_fixed_deposit') {
                if ($value_raw === '' || $value_raw === null) { 
                    $value_to_store = null; 
                } elseif (!is_numeric($value_raw) || (float)$value_raw < 0) { 
                    throw new Exception('ค่าที่ตั้งต้องเป็นตัวเลขและไม่ติดลบสำหรับ: ' . htmlspecialchars($key), 400);
                } else {
                    $value_to_store = (int)round((float)$value_raw); 
                }
            }
            
            $stmt = $pdo->prepare("UPDATE system_settings SET setting_value = ?, updated_at = NOW() WHERE setting_key = ?");
            $stmt->execute([$value_to_store, $key]);

            if ($stmt->rowCount() > 0) {
                $pdo->commit();
                echo json_encode(['success' => true, 'message' => 'อัปเดตการตั้งค่า "' . htmlspecialchars($key) . '" เรียบร้อยแล้ว']);
            } else {
                $checkStmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
                $checkStmt->execute([$key]);
                $existingValueResult = $checkStmt->fetch(PDO::FETCH_ASSOC);

                if ($existingValueResult !== false) { 
                    $existingValue = $existingValueResult['setting_value'];
                    $noChange = false;
                    if ($key === 'hourly_extension_rate' || $key === 'default_fixed_deposit') {
                        $existingValueInt = ($existingValue === null) ? null : (int)round((float)$existingValue);
                        if ($value_to_store === null && $existingValueInt === null) $noChange = true;
                        elseif ($value_to_store !== null && $existingValueInt !== null && $existingValueInt == $value_to_store) $noChange = true; 
                    } else {
                         if ($existingValue == $value_to_store) $noChange = true; 
                    }

                    if ($noChange) {
                         $pdo->commit(); 
                         echo json_encode(['success' => true, 'message' => 'ค่าสำหรับ "' . htmlspecialchars($key) . '" เหมือนเดิม ไม่มีการเปลี่ยนแปลง']);
                    } else {
                         $pdo->rollBack(); 
                         throw new Exception('อัปเดต "' . htmlspecialchars($key) . '" ล้มเหลวโดยไม่ทราบสาเหตุ ทั้งที่ค่าต่างกัน');
                    }
                } else { 
                    $pdo->rollBack();
                    throw new Exception('ไม่พบการตั้งค่าที่ต้องการอัปเดต: ' . htmlspecialchars($key), 404);
                }
            }
            exit;
        } catch (Exception $e) { 
            if ($pdo->inTransaction()) $pdo->rollBack();
            $errorCode = ($e->getCode() >= 400 && $e->getCode() < 600) ? $e->getCode() : 500;
            http_response_code($errorCode);
            error_log("[API UpdateSetting] Error: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            exit;
        }        
    case 'get_addon_services':
        try {
            $only_active = isset($_GET['active_only']) && $_GET['active_only'] == 'true';
            $sql = "SELECT id, name, price, is_active, created_at, updated_at FROM addon_services";
            if ($only_active) {
                $sql .= " WHERE is_active = 1";
            }
            $sql .= " ORDER BY name ASC";
            $stmt = $pdo->query($sql);
            $addons_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $addons = array_map(function($addon) {
                $addon['price'] = (int)round((float)$addon['price']); 
                return $addon;
            }, $addons_raw);
            echo json_encode(['success' => true, 'addons' => $addons]);
            exit;
        } catch (Exception $e) { 
            http_response_code(500);
            error_log("[API GetAddons] Error: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'เกิดข้อผิดพลาดในการดึงข้อมูลบริการเสริม: ' . $e->getMessage()]);
            exit;
        }
        
    case 'add_addon_service':
        try {
            $pdo->beginTransaction();
            $name = trim($_POST['name'] ?? '');
            $price_str = $_POST['price'] ?? '0'; 
            
            if (empty($name)) {
                throw new Exception('ชื่อบริการเสริมต้องไม่เป็นค่าว่าง', 400);
            }
            if (!is_numeric($price_str) || (float)$price_str < 0) { 
                 throw new Exception('ราคาบริการเสริมต้องเป็นตัวเลขและไม่ติดลบ', 400);
            }
            $price = (int)round((float)$price_str); 

            $stmt = $pdo->prepare("INSERT INTO addon_services (name, price, is_active, created_at, updated_at) VALUES (?, ?, 1, NOW(), NOW())");
            $stmt->execute([$name, $price]); 
            $newAddonId = $pdo->lastInsertId();
            $pdo->commit();
            echo json_encode(['success' => true, 'message' => 'เพิ่มบริการเสริม "'.htmlspecialchars($name).'" เรียบร้อยแล้ว', 'new_addon_id' => $newAddonId, 'name' => $name, 'price' => $price, 'is_active' => 1]);
            exit;
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($e->getCode() == 23000) { 
                http_response_code(409); 
                echo json_encode(['success' => false, 'message' => 'ชื่อบริการเสริมนี้มีอยู่แล้ว: "' . htmlspecialchars($name) . '"']);
            } else {
                http_response_code(500);
                error_log("[API AddAddon] PDO Error: " . $e->getMessage());
                echo json_encode(['success' => false, 'message' => 'เกิดข้อผิดพลาดฐานข้อมูลขณะเพิ่มบริการเสริม: ' . $e->getMessage()]);
            }
            exit;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $errorCode = ($e->getCode() >= 400 && $e->getCode() < 600) ? $e->getCode() : 500;
            http_response_code($errorCode);
            error_log("[API AddAddon] Error: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            exit;
        }
        
    case 'update_addon_service':
        try {
            $pdo->beginTransaction();
            $id = (int)($_POST['id'] ?? $_POST['edit_addon_id'] ?? 0);
            $name = trim($_POST['name'] ?? $_POST['edit_addon_name_modal'] ?? '');
            $price_str = $_POST['price'] ?? $_POST['edit_addon_price_modal'] ?? null; 

            if (!$id) {
                throw new Exception('ไม่พบรหัสบริการเสริมสำหรับการแก้ไข', 400);
            }
            if (empty($name)) { 
                 throw new Exception('ชื่อบริการเสริมต้องไม่เป็นค่าว่าง', 400);
            }
            if ($price_str === null) { 
                 throw new Exception('ราคาบริการเสริมต้องไม่เป็นค่าว่าง', 400);
            }
            if (!is_numeric($price_str) || (float)$price_str < 0) { 
                throw new Exception('ราคาบริการเสริมต้องเป็นตัวเลขและไม่ติดลบ', 400);
            }
            $price = (int)round((float)$price_str); 

            $stmt = $pdo->prepare("UPDATE addon_services SET name = ?, price = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$name, $price, $id]); 

            if ($stmt->rowCount() > 0) {
                $pdo->commit();
                echo json_encode(['success' => true, 'message' => 'แก้ไขบริการเสริม ID: '.htmlspecialchars($id).' เรียบร้อยแล้ว']);
            } else {
                $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM addon_services WHERE id = ? AND name = ? AND price = ?");
                $checkStmt->execute([$id, $name, $price]); 
                if ($checkStmt->fetchColumn() > 0) { 
                    $pdo->commit(); 
                    echo json_encode(['success' => true, 'message' => 'ข้อมูลบริการเสริม ID: '.htmlspecialchars($id).' เหมือนเดิม ไม่มีการเปลี่ยนแปลง']);
                } else { 
                    $checkExistsStmt = $pdo->prepare("SELECT COUNT(*) FROM addon_services WHERE id = ?");
                    $checkExistsStmt->execute([$id]);
                    if($checkExistsStmt->fetchColumn() == 0) {
                        $pdo->rollBack();
                        throw new Exception('ไม่พบรายการบริการเสริม ID: '.htmlspecialchars($id).' ที่ต้องการแก้ไข', 404);
                    }
                    $pdo->rollBack();
                    throw new Exception('แก้ไขบริการเสริม ID: '.htmlspecialchars($id).' ล้มเหลว หรือข้อมูลเหมือนเดิม (แต่ rowCount = 0)', 500);
                }
            }
            exit;
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($e->getCode() == 23000) { 
                http_response_code(409); 
                echo json_encode(['success' => false, 'message' => 'ชื่อบริการเสริม "' . htmlspecialchars($name) . '" นี้มีอยู่แล้ว (สำหรับรายการอื่น)']);
            } else {
                http_response_code(500);
                error_log("[API UpdateAddon] PDO Error: " . $e->getMessage());
                echo json_encode(['success' => false, 'message' => 'เกิดข้อผิดพลาดฐานข้อมูลขณะแก้ไขบริการเสริม: ' . $e->getMessage()]);
            }
            exit;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $errorCode = ($e->getCode() >= 400 && $e->getCode() < 600) ? $e->getCode() : 500;
            http_response_code($errorCode);
            error_log("[API UpdateAddon] Error: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            exit;
        }
        
    case 'add_user': 
        try {
            $pdo->beginTransaction();
            $username = trim($_POST['username'] ?? '');
            $role = $_POST['role'] ?? 'staff'; 
            $password_raw = $_POST['password'] ?? ''; 

            if (empty($username) || !in_array($role, ['admin', 'staff'])) {
                throw new Exception('ข้อมูลผู้ใช้ไม่ถูกต้อง (ชื่อผู้ใช้, บทบาท)', 400);
            }
            if ($role === 'admin' && empty($password_raw)) {
                throw new Exception('กรุณากำหนดรหัสผ่านสำหรับผู้ดูแล', 400);
            }

            $password_hash = null;
            if (!empty($password_raw)) { 
                $password_hash = password_hash($password_raw, PASSWORD_DEFAULT);
            } else if ($role === 'admin') { 
                 throw new Exception('รหัสผ่านจำเป็นสำหรับผู้ดูแลระบบ', 400); 
            }

            $stmt = $pdo->prepare("INSERT INTO users (username, password_hash, role) VALUES (?, ?, ?)");
            $stmt->execute([$username, $password_hash, $role]);
            $newUserId = $pdo->lastInsertId();
            $pdo->commit();
            echo json_encode(['success' => true, 'message' => 'เพิ่มผู้ใช้ "'.htmlspecialchars($username).'" เรียบร้อยแล้ว', 'user_id' => $newUserId]);
            exit;
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($e->getCode() == 23000) { 
                http_response_code(409); 
                echo json_encode(['success' => false, 'message' => 'ชื่อผู้ใช้นี้มีอยู่แล้ว: "' . htmlspecialchars($username) . '"']); 
            } else {
                http_response_code(500); error_log("[API AddUser] PDO Error: " . $e->getMessage());
                echo json_encode(['success' => false, 'message' => 'เกิดข้อผิดพลาดฐานข้อมูล: ' . $e->getMessage()]);
            }
            exit;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $errorCode = $e->getCode() ?: 500; 
            if ($errorCode < 400 || $errorCode > 599) $errorCode = 500; 
            http_response_code($errorCode);
            error_log("[API AddUser] App Error: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            exit;
        }

    case 'toggle_user_status': 
        try {
            $pdo->beginTransaction();
            $userId = (int)($_POST['id'] ?? 0);
            if (!$userId) throw new Exception('ไม่พบรหัสผู้ใช้', 400);
            
            $requesting_user_id = get_current_user_id(); 
            if ($requesting_user_id !== null && $userId == $requesting_user_id) {
                 throw new Exception('ไม่สามารถเปลี่ยนสถานะผู้ใช้ของตัวเองได้', 403); 
            }

            $stmt_current = $pdo->prepare("SELECT is_active FROM users WHERE id = ?");
            $stmt_current->execute([$userId]);
            $current_status_val = $stmt_current->fetchColumn();

            if ($current_status_val === false) throw new Exception('ไม่พบผู้ใช้ ID: '.htmlspecialchars($userId), 404);
            
            $new_status = ((int)$current_status_val === 1) ? 0 : 1;

            $stmt = $pdo->prepare("UPDATE users SET is_active = ? WHERE id = ?");
            $stmt->execute([$new_status, $userId]);
            $pdo->commit();
            $status_text = $new_status === 1 ? "เปิดใช้งาน" : "ปิดใช้งาน";
            echo json_encode(['success' => true, 'message' => 'เปลี่ยนสถานะผู้ใช้เป็น "'.$status_text.'" เรียบร้อยแล้ว', 'new_status' => $new_status]);
            exit;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $errorCode = $e->getCode() ?: 500;
            if ($errorCode < 400 || $errorCode > 599) $errorCode = 500;
            http_response_code($errorCode);
            error_log("[API ToggleUserStatus] Error: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            exit;
        }

    case 'reset_admin_password': 
        try {
            $pdo->beginTransaction();
            $userIdToReset = (int)($_POST['user_id'] ?? 0);
            $newPasswordRaw = trim($_POST['new_password'] ?? '');

            if (!$userIdToReset || empty($newPasswordRaw)) {
                throw new Exception('ข้อมูลไม่ครบถ้วนสำหรับการตั้งรหัสผ่านใหม่', 400);
            }

            $stmtUser = $pdo->prepare("SELECT role FROM users WHERE id = ?");
            $stmtUser->execute([$userIdToReset]);
            $userRole = $stmtUser->fetchColumn();

            if ($userRole === false) { 
                throw new Exception('ไม่พบผู้ใช้ ID: '.htmlspecialchars($userIdToReset).' สำหรับการตั้งรหัสผ่านใหม่', 404);
            }
            if ($userRole !== 'admin') {
                throw new Exception('สามารถตั้งรหัสผ่านใหม่ให้เฉพาะผู้ดูแลเท่านั้น', 403); 
            }

            $newPasswordHash = password_hash($newPasswordRaw, PASSWORD_DEFAULT);
            $stmtUpdatePass = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ? AND role = 'admin'");
            $stmtUpdatePass->execute([$newPasswordHash, $userIdToReset]);

            if ($stmtUpdatePass->rowCount() > 0) {
                $pdo->commit();
                echo json_encode(['success' => true, 'message' => 'ตั้งรหัสผ่านใหม่สำหรับผู้ใช้ ID: '.htmlspecialchars($userIdToReset).' เรียบร้อยแล้ว']);
            } else {
                 $pdo->rollBack();
                throw new Exception('ไม่สามารถตั้งรหัสผ่านใหม่ได้ อาจมีข้อผิดพลาดกับข้อมูลผู้ใช้ หรือผู้ใช้ไม่ใช่ Admin', 500);
            }
            exit;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $errorCode = $e->getCode() ?: 500;
            if ($errorCode < 400 || $errorCode > 599) $errorCode = 500;
            http_response_code($errorCode);
            error_log("[API ResetAdminPass] Error: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            exit;
        }

    case 'toggle_addon_service_status': 
        try {
            $pdo->beginTransaction();
            $id = (int)($_POST['id'] ?? 0);
            if (!$id) {
                throw new Exception('ไม่พบรหัสบริการเสริม', 400);
            }

            $stmt_current = $pdo->prepare("SELECT is_active FROM addon_services WHERE id = ?");
            $stmt_current->execute([$id]);
            $current_status_val = $stmt_current->fetchColumn();

            if ($current_status_val === false) { 
                throw new Exception('ไม่พบรายการบริการเสริม ID: '.htmlspecialchars($id), 404);
            }
            $new_status = ((int)$current_status_val === 1) ? 0 : 1;

            $stmt = $pdo->prepare("UPDATE addon_services SET is_active = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$new_status, $id]);
            $pdo->commit();
            $status_text = $new_status === 1 ? "เปิดใช้งาน" : "ปิดใช้งาน";
            echo json_encode(['success' => true, 'message' => 'เปลี่ยนสถานะบริการเสริมเป็น "'.$status_text.'" เรียบร้อยแล้ว', 'new_status' => $new_status]);
            exit;
        } catch (Exception $e) { 
            if ($pdo->inTransaction()) $pdo->rollBack();
            $errorCode = ($e->getCode() >= 400 && $e->getCode() < 600) ? $e->getCode() : 500;
            http_response_code($errorCode);
            error_log("[API ToggleAddon] Error: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            exit;
        }
    case 'get_room_statuses':
        try {
            // --- START OF MODIFIED SQL QUERY ---
            $roomsDataQueryApi = $pdo->prepare("
                SELECT
                    r.id,
                    r.status AS db_actual_status,
                    current_booking.id AS current_booking_id,
                    current_booking.total_price AS current_total_price,
                    current_booking.amount_paid AS current_amount_paid,
                    current_booking.checkin_datetime AS current_checkin_datetime_raw,
                    current_booking.checkout_datetime_calculated AS current_checkout_datetime_raw,
                    current_booking.booking_type AS current_booking_type_raw,
                    r.zone AS current_room_zone_raw,

                    (CASE /* is_overdue */
                        WHEN current_booking.id IS NOT NULL AND NOW() >= current_booking.checkout_datetime_calculated THEN 1
                        ELSE 0
                    END) AS is_overdue,
                    
                    (CASE /* is_nearing_checkout */
                        WHEN current_booking.id IS NOT NULL
                            AND current_booking.checkin_datetime <= NOW()
                            AND NOW() < current_booking.checkout_datetime_calculated
                            AND TIMESTAMPDIFF(MINUTE, NOW(), current_booking.checkout_datetime_calculated) <= 60 
                            AND TIMESTAMPDIFF(MINUTE, NOW(), current_booking.checkout_datetime_calculated) > 0
                        THEN 1
                        ELSE 0
                    END) AS is_nearing_checkout,

                    (CASE /* has_pending_payment */
                        WHEN current_booking.id IS NOT NULL
                            AND current_booking.total_price > current_booking.amount_paid
                        THEN 1
                        ELSE 0
                    END) AS has_pending_payment,

                    -- ** START: REVISED display_status LOGIC **
                    CASE
                        -- Priority 1: Overdue Occupied
                        WHEN current_booking.id IS NOT NULL AND NOW() >= current_booking.checkout_datetime_calculated THEN 'overdue_occupied'

                        -- Priority 2: Occupied (Normal or F Short Occupied)
                        WHEN current_booking.id IS NOT NULL
                             AND current_booking.checkin_datetime <= NOW()
                             AND NOW() < current_booking.checkout_datetime_calculated
                        THEN 
                            CASE
                                WHEN r.zone = 'F' AND current_booking.booking_type = 'short_stay' THEN 'f_short_occupied'
                                WHEN r.status = 'occupied' THEN 'occupied' -- Check DB status for normal occupied
                                ELSE r.status -- Should ideally be 'occupied'
                            END

                        -- Priority 3: Booked (Pending Check-in Today - สีเหลือง)
                        WHEN current_booking.id IS NOT NULL
                             AND DATE(current_booking.checkin_datetime) = CURDATE()
                             -- AND (r.status = 'booked' OR (r.status = 'free' AND current_booking.checkin_datetime > NOW()))
                        THEN 'booked'

                        -- Priority 4: Advance Booking (Arriving Tomorrow - สีฟ้า)
                        WHEN current_booking.id IS NOT NULL
                             AND DATE(current_booking.checkin_datetime) = DATE_ADD(CURDATE(), INTERVAL 1 DAY)
                             AND NOT EXISTS ( -- Ensure room is not already occupied by someone else today
                                 SELECT 1 FROM bookings b_active_today
                                 WHERE b_active_today.room_id = r.id
                                 AND b_active_today.checkin_datetime <= NOW() AND NOW() < b_active_today.checkout_datetime_calculated
                             )
                        THEN 'advance_booking'

                        -- Priority 5: Free (No bookings today/tomorrow, or bookings are >1 day away - สีเขียว)
                        WHEN (r.status = 'free' OR r.status = 'advance_booking') -- Base DB status
                             AND (
                                    current_booking.id IS NULL OR -- No relevant booking for today/tomorrow
                                    DATE(current_booking.checkin_datetime) > DATE_ADD(CURDATE(), INTERVAL 1 DAY) -- Most relevant booking is > tomorrow
                                 )
                             AND NOT EXISTS ( -- Double check no other bookings for today or tomorrow
                                 SELECT 1 FROM bookings b_interfere
                                 WHERE b_interfere.room_id = r.id
                                 AND DATE(b_interfere.checkin_datetime) <= DATE_ADD(CURDATE(), INTERVAL 1 DAY)
                                 AND b_interfere.checkout_datetime_calculated > NOW()
                             )
                        THEN 'free'
                        
                        -- Fallback: if r.status is 'advance_booking' and current_booking is > tomorrow, show as 'free'
                        WHEN r.status = 'advance_booking' AND current_booking.id IS NOT NULL AND DATE(current_booking.checkin_datetime) > DATE_ADD(CURDATE(), INTERVAL 1 DAY)
                        THEN 'free'

                        ELSE r.status -- Fallback to actual DB status (e.g., maintenance, dirty)
                    END AS display_status,
                    -- ** END: REVISED display_status LOGIC **
                    (SELECT b_rel.id FROM bookings b_rel
                        WHERE b_rel.room_id = r.id AND b_rel.checkout_datetime_calculated > NOW()
                        ORDER BY b_rel.checkin_datetime ASC
                        LIMIT 1) as relevant_booking_id
                FROM rooms r
                LEFT JOIN ( 
                    SELECT
                        b_inner.room_id,
                        b_inner.id,
                        b_inner.checkin_datetime,
                        b_inner.checkout_datetime_calculated,
                        b_inner.booking_type,
                        b_inner.total_price, 
                        b_inner.amount_paid
                    FROM bookings b_inner
                    WHERE
                        b_inner.id = (
                            SELECT b_latest.id
                            FROM bookings b_latest
                            WHERE b_latest.room_id = b_inner.room_id
                            ORDER BY
                                (CASE
                                    WHEN b_latest.checkin_datetime <= NOW() AND NOW() < b_latest.checkout_datetime_calculated THEN 1 -- Active (highest prio)
                                    WHEN DATE(b_latest.checkin_datetime) = CURDATE() THEN 2 -- Today
                                    WHEN DATE(b_latest.checkin_datetime) = DATE_ADD(CURDATE(), INTERVAL 1 DAY) THEN 3 -- Tomorrow
                                    WHEN NOW() >= b_latest.checkout_datetime_calculated THEN 4 -- Overdue (after active/today/tomorrow)
                                    ELSE 5 -- Future (beyond tomorrow)
                                END) ASC,
                                b_latest.checkin_datetime ASC, -- For same priority, earlier check-in wins
                                b_latest.id DESC
                            LIMIT 1
                        )
                ) AS current_booking ON current_booking.room_id = r.id
                GROUP BY r.id
                ORDER BY r.id ASC
            ");
            // --- END OF MODIFIED SQL QUERY ---
            $roomsDataQueryApi->execute();
            $roomsStatuses = $roomsDataQueryApi->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'rooms' => $roomsStatuses]);
            exit;
        } catch (PDOException $e) {
            http_response_code(500);
            error_log("[API GetRoomStatuses] PDO Error: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Error fetching room statuses.']);
        }
        break; 

    // ***** START: โค้ดที่เพิ่มเข้ามา (NEW ACTIONS) *****
    case 'get_available_rooms_for_move':
        try {
            $booking_id = (int)($_GET['booking_id'] ?? 0);
            if (!$booking_id) throw new Exception("ไม่พบรหัสการจอง", 400);

            // 1. ดึงข้อมูล check-in/out ของ booking ที่จะย้าย
            $stmt = $pdo->prepare("SELECT checkin_datetime, checkout_datetime_calculated, room_id FROM bookings WHERE id = ?");
            $stmt->execute([$booking_id]);
            $booking_times = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$booking_times) throw new Exception("ไม่พบข้อมูลการจอง ID: {$booking_id}", 404);

            // 2. ค้นหาห้องทั้งหมดที่ "ว่าง" ในช่วงเวลาของการจองนั้นๆ
            $stmt_avail = $pdo->prepare("
                SELECT r.id, r.zone, r.room_number, r.price_per_day, r.price_short_stay
                FROM rooms r
                WHERE r.id != :current_room_id 
                  AND NOT EXISTS (
                      SELECT 1 FROM bookings b
                      WHERE b.room_id = r.id
                        AND b.checkout_datetime_calculated > :checkin_time
                        AND b.checkin_datetime < :checkout_time
                  )
                ORDER BY r.zone, CAST(r.room_number AS UNSIGNED)
            ");
            $stmt_avail->execute([
                ':current_room_id' => $booking_times['room_id'],
                ':checkin_time' => $booking_times['checkin_datetime'],
                ':checkout_time' => $booking_times['checkout_datetime_calculated']
            ]);
            $available_rooms = $stmt_avail->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['success' => true, 'rooms' => $available_rooms]);

        } catch (Exception $e) {
            http_response_code($e->getCode() >= 400 ? $e->getCode() : 500);
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;

    case 'move_booking':
        $pdo->beginTransaction();
        try {
            $booking_id = (int)($_POST['booking_id_to_move'] ?? 0);
            $new_room_id = (int)($_POST['new_room_id'] ?? 0);

            if (!$booking_id || !$new_room_id) throw new Exception("ข้อมูลไม่ครบถ้วนสำหรับการย้ายห้อง", 400);

            // 1. ดึงข้อมูล booking เดิม
            $stmt = $pdo->prepare("SELECT * FROM bookings WHERE id = ?");
            $stmt->execute([$booking_id]);
            $booking = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$booking) throw new Exception("ไม่พบข้อมูลการจองเดิม ID: {$booking_id}", 404);

            $old_room_id = $booking['room_id'];
            if ($old_room_id == $new_room_id) throw new Exception("ไม่สามารถย้ายไปยังห้องเดิมได้", 400);

            // 2. ตรวจสอบการจองซ้อนในห้องใหม่ (Double-check for safety)
            $stmt_overlap = $pdo->prepare("
                SELECT COUNT(*) FROM bookings
                WHERE room_id = :new_room_id
                  AND checkout_datetime_calculated > :checkin_time
                  AND checkin_datetime < :checkout_time
            ");
            $stmt_overlap->execute([
                ':new_room_id' => $new_room_id,
                ':checkin_time' => $booking['checkin_datetime'],
                ':checkout_time' => $booking['checkout_datetime_calculated']
            ]);
            if ($stmt_overlap->fetchColumn() > 0) {
                throw new Exception("ห้องพักปลายทางไม่ว่างในช่วงเวลาที่ต้องการย้าย (อาจมีการจองเข้ามาพอดี)", 409);
            }

            // 3. อัปเดต room_id ในการจอง
            $stmt_update = $pdo->prepare("UPDATE bookings SET room_id = :new_room_id, {$logTimestampField} = NOW(), last_modified_by_user_id = :user_id WHERE id = :booking_id");
            $stmt_update->execute([
                ':new_room_id' => $new_room_id,
                ':user_id' => get_current_user_id(),
                ':booking_id' => $booking_id
            ]);

            // 4. อัปเดตสถานะห้องเก่า -> ทำให้ว่าง ถ้าไม่มีการจองอื่นรออยู่
            $stmt_check_old_room = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE room_id = ? AND checkout_datetime_calculated > NOW()");
            $stmt_check_old_room->execute([$old_room_id]);
            if ($stmt_check_old_room->fetchColumn() == 0) {
                $pdo->prepare("UPDATE rooms SET status = 'free' WHERE id = ?")->execute([$old_room_id]);
            }

            // 5. อัปเดตสถานะห้องใหม่ -> ทำให้ไม่ว่าง (occupied หรือ booked)
            $new_status = (new DateTime($booking['checkin_datetime']) <= new DateTime()) ? 'occupied' : 'booked';
            $pdo->prepare("UPDATE rooms SET status = ? WHERE id = ?")->execute([$new_status, $new_room_id]);

            $pdo->commit();
            echo json_encode(['success' => true, 'message' => 'ย้ายห้องพักเรียบร้อยแล้ว']);

        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            http_response_code($e->getCode() >= 400 ? $e->getCode() : 500);
            error_log("[API MoveBooking] Error: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'เกิดข้อผิดพลาด: ' . $e->getMessage()]);
        }
        exit;
    // ***** END: โค้ดที่เพิ่มเข้ามา *****

    default:
        http_response_code(400);
        error_log("[API] Default case triggered. Action: '{$action}'. This means the switch did not match any case.");
        echo json_encode(['success' => false, 'message' => 'ไม่มี action ที่ระบุหรือ action ไม่ถูกต้อง (No action specified or action is invalid: ' . htmlspecialchars($action) . ')', 'detail' => 'Invalid action specified in main switch.']);
        exit; 
}
?>
