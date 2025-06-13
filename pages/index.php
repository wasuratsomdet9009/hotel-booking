<?php
// FILEX: hotel_booking/pages/index.php
require_once __DIR__ . '/../bootstrap.php'; // Defines CHECKOUT_TIME_STR, FIXED_DEPOSIT_AMOUNT etc.
require_login(); // ตรวจสอบว่าล็อกอินหรือยัง

$pageTitle = 'Dashboard โรงแรม';

// --- START: New Automatic Archiving for OVERDUE ZONE F Bookings (WITH DEPOSIT CHECK) ---
// ส่วนนี้ไม่มีการเปลี่ยนแปลงจากโค้ดเดิมของคุณ
try {
    $pdo->beginTransaction();

    // 1. Identify overdue Zone F bookings that have NO DEPOSIT and haven't been superseded by a new active booking
    $stmtOverdueZoneF = $pdo->prepare("
        SELECT b.*, r.zone 
        FROM bookings b
        JOIN rooms r ON b.room_id = r.id
        WHERE r.zone = 'F'
          AND NOW() >= b.checkout_datetime_calculated
          AND (b.deposit_amount IS NULL OR b.deposit_amount = 0) 
          AND b.id = ( -- Process only the latest relevant booking for the room that is overdue
                SELECT b_latest_check.id
                FROM bookings b_latest_check
                WHERE b_latest_check.room_id = r.id
                ORDER BY 
                    (CASE 
                        WHEN b_latest_check.checkin_datetime <= NOW() AND NOW() < b_latest_check.checkout_datetime_calculated THEN 1 /* Active */
                        WHEN DATE(b_latest_check.checkin_datetime) = CURDATE() AND b_latest_check.checkin_datetime > NOW() THEN 2  /* Pending Today */
                        WHEN NOW() >= b_latest_check.checkout_datetime_calculated THEN 3 /* Potentially Overdue */
                        ELSE 4 
                    END), 
                    b_latest_check.checkout_datetime_calculated DESC, 
                    b_latest_check.id DESC
                LIMIT 1
            )
    ");
    $stmtOverdueZoneF->execute();
    $overdueZoneFBookings = $stmtOverdueZoneF->fetchAll(PDO::FETCH_ASSOC);

    $archivedCountZoneF = 0;
    foreach ($overdueZoneFBookings as $ozfBooking) {
        error_log("[AutoArchive ZoneF NO DEPOSIT] Processing overdue booking ID: {$ozfBooking['id']} for room ID: {$ozfBooking['room_id']}. Checkout was: {$ozfBooking['checkout_datetime_calculated']}");

        $is_temporary_archive_flag_ozf = ($ozfBooking['booking_type'] === 'short_stay') ? 1 : 0;

        $archiveSqlOZF = "INSERT INTO archives (
                                 room_id, customer_name, customer_phone, booking_type,
                                 checkin_datetime, checkout_datetime_calculated, checkout_datetime, 
                                 nights, extended_hours, price_per_night, total_price, amount_paid,
                                 additional_paid_amount, deposit_amount, payment_method, extended_payment_method,
                                 receipt_path, extended_receipt_path,
                                 deposit_returned, deposit_path, notes,
                                 created_at, last_extended_at, archived_at, is_temporary_archive,
                                 created_by_user_id, last_modified_by_user_id
                             ) VALUES (
                                 :room_id, :customer_name, :customer_phone, :booking_type,
                                 :checkin_datetime, :checkout_datetime_calculated, :checkout_datetime_legacy,
                                 :nights, :extended_hours, :price_per_night, :total_price, :amount_paid,
                                 :additional_paid_amount, :deposit_amount, :payment_method, :extended_payment_method,
                                 :receipt_path, :extended_receipt_path,
                                 0, NULL, :notes, 
                                 :created_at_orig_booking, :last_extended_at_orig_booking, NOW(), :is_temporary_archive,
                                 :created_by_user_id, :last_modified_by_user_id
                             )";
        $stmtArchiveOZF = $pdo->prepare($archiveSqlOZF);
        $stmtArchiveOZF->execute([
            ':room_id' => $ozfBooking['room_id'],
            ':customer_name' => $ozfBooking['customer_name'],
            ':customer_phone' => $ozfBooking['customer_phone'],
            ':booking_type' => $ozfBooking['booking_type'],
            ':checkin_datetime' => $ozfBooking['checkin_datetime'],
            ':checkout_datetime_calculated' => $ozfBooking['checkout_datetime_calculated'],
            ':checkout_datetime_legacy' => $ozfBooking['checkout_datetime_calculated'],
            ':nights' => $ozfBooking['nights'],
            ':extended_hours' => $ozfBooking['extended_hours'] ?? 0,
            ':price_per_night' => $ozfBooking['price_per_night'],
            ':total_price' => $ozfBooking['total_price'],
            ':amount_paid' => $ozfBooking['amount_paid'],
            ':additional_paid_amount' => $ozfBooking['additional_paid_amount'] ?? 0.00,
            ':deposit_amount' => $ozfBooking['deposit_amount'] ?? 0.00, 
            ':payment_method' => $ozfBooking['payment_method'],
            ':extended_payment_method' => $ozfBooking['extended_payment_method'],
            ':receipt_path' => $ozfBooking['receipt_path'],
            ':extended_receipt_path' => $ozfBooking['extended_receipt_path'],
            ':notes' => $ozfBooking['notes'],
            ':created_at_orig_booking' => $ozfBooking['created_at'],
            ':last_extended_at_orig_booking' => $ozfBooking['last_extended_at'],
            ':is_temporary_archive' => $is_temporary_archive_flag_ozf,
            ':created_by_user_id' => $ozfBooking['created_by_user_id'],
            ':last_modified_by_user_id' => $ozfBooking['last_modified_by_user_id']
        ]);
        $archivedIdOZF = $pdo->lastInsertId();

        $stmtBookingAddonsOZF = $pdo->prepare("SELECT * FROM booking_addons WHERE booking_id = ?");
        $stmtBookingAddonsOZF->execute([$ozfBooking['id']]);
        $addonsToArchiveOZF = $stmtBookingAddonsOZF->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($addonsToArchiveOZF)) {
            $stmtArchiveAddonOZF = $pdo->prepare("INSERT INTO archive_addons (archive_id, addon_service_id, quantity, price_at_booking) VALUES (?, ?, ?, ?)");
            foreach ($addonsToArchiveOZF as $addonOZF) {
                $stmtArchiveAddonOZF->execute([$archivedIdOZF, $addonOZF['addon_service_id'], $addonOZF['quantity'], $addonOZF['price_at_booking']]);
            }
        }

        $stmtDeleteAddonsOZF = $pdo->prepare("DELETE FROM booking_addons WHERE booking_id = ?");
        $stmtDeleteAddonsOZF->execute([$ozfBooking['id']]);
        $stmtDeleteBookingOZF = $pdo->prepare("DELETE FROM bookings WHERE id = ?");
        $stmtDeleteBookingOZF->execute([$ozfBooking['id']]);

        $stmtCheckNewerRelevantBooking = $pdo->prepare("
            SELECT COUNT(*) FROM bookings
            WHERE room_id = ?
              AND ( 
                  (checkin_datetime <= NOW() AND NOW() < checkout_datetime_calculated) 
                  OR 
                  (DATE(checkin_datetime) = CURDATE() AND checkin_datetime > NOW()) 
              )
        ");
        $stmtCheckNewerRelevantBooking->execute([$ozfBooking['room_id']]);
        if ($stmtCheckNewerRelevantBooking->fetchColumn() == 0) {
            $stmtRoomUpdateOZF = $pdo->prepare("UPDATE rooms SET status = 'free' WHERE id = ?");
            $stmtRoomUpdateOZF->execute([$ozfBooking['room_id']]);
            error_log("[AutoArchive ZoneF NO DEPOSIT] Room ID: {$ozfBooking['room_id']} status set to 'free' after archiving booking ID: {$ozfBooking['id']}.");
        } else {
            error_log("[AutoArchive ZoneF NO DEPOSIT] Room ID: {$ozfBooking['room_id']} has another relevant booking. Status not changed to 'free' after archiving booking ID: {$ozfBooking['id']}.");
        }
        $archivedCountZoneF++;
    }

    if ($archivedCountZoneF > 0) {
        error_log("[AutoArchive ZoneF NO DEPOSIT] Successfully auto-archived {$archivedCountZoneF} overdue Zone F bookings (NO DEPOSIT).");
    }
    $pdo->commit();

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log("[AutoArchive ZoneF NO DEPOSIT] Error during auto-archiving: " . $e->getMessage() . "\nTrace: " . $e->getTraceAsString());
}
// --- END: New Automatic Archiving for OVERDUE ZONE F Bookings ---


// --- START: Adjusted Status Update Logic (Core Changes Here) ---
try {
    $pdo->beginTransaction();

    // ** 1. Correct Room Status if no active/pending/future/overdue booking exists **
    // ***** START: โค้ดที่แก้ไข *****
    $stmtCorrectRoomStatus = $pdo->prepare("
        UPDATE rooms r
        SET r.status = 'free'
        WHERE r.status != 'free' -- พิจารณาทุกสถานะที่ไม่ใช่ free
          AND NOT EXISTS (
              SELECT 1 FROM bookings b
              WHERE b.room_id = r.id
                AND (
                    -- Booking ที่กำลัง active อยู่ (ได้เช็คอินแล้ว และยังไม่ถึงเวลา checkout)
                    (b.checkin_datetime <= NOW() AND NOW() < b.checkout_datetime_calculated)
                    OR
                    -- Booking ที่รอเช็คอินสำหรับวันนี้ (ไม่ว่าจะถึงเวลาเช็คอินแล้วหรือยัง แต่ยังไม่ถึง checkout)
                    (DATE(b.checkin_datetime) = CURDATE() AND NOW() < b.checkout_datetime_calculated)
                    OR
                    -- Booking ที่เป็น Advance Booking สำหรับวันในอนาคต
                    (DATE(b.checkin_datetime) > CURDATE())
                    OR
                    -- *** START: ส่วนที่เพิ่มเข้ามา ***
                    -- Booking ที่เลยเวลาเช็คเอาท์ไปแล้ว (Overdue) และยังไม่ถูกย้ายไปประวัติ
                    (NOW() >= b.checkout_datetime_calculated)
                    -- *** END: ส่วนที่เพิ่มเข้ามา ***
                )
          );
    ");
    $stmtCorrectRoomStatus->execute();
    // ***** END: โค้ดที่แก้ไข *****
    if (function_exists('error_log')) {
        error_log("[IndexStatusUpdate] Corrected room statuses to 'free' if no justifying active/pending/future booking found: " . $stmtCorrectRoomStatus->rowCount());
    }

    // ** 2. Mark rooms in DB as 'booked' ONLY if they have a booking FOR TODAY and are currently 'free' **
    $stmtSetBooked = $pdo->prepare("
        UPDATE rooms r SET status = 'booked'
        WHERE r.status = 'free' -- พิจารณาเฉพาะห้องที่ควรจะเป็น free
          AND EXISTS (
            SELECT 1 FROM bookings b
            WHERE b.room_id = r.id
              AND DATE(b.checkin_datetime) = CURDATE() -- การจองสำหรับวันนี้เท่านั้น
              AND NOW() < b.checkout_datetime_calculated -- และยังไม่ถึงเวลาเช็คเอาท์ของการจองนั้น
          )
          AND NOT EXISTS ( -- เพิ่มเงื่อนไข: ต้องไม่มี booking ที่ active อยู่แล้วสำหรับห้องนี้ (กรณีข้อมูลซ้ำซ้อน)
              SELECT 1 FROM bookings b_active_check
              WHERE b_active_check.room_id = r.id
                AND (b_active_check.checkin_datetime <= NOW() AND NOW() < b_active_check.checkout_datetime_calculated)
          );
    ");
    $stmtSetBooked->execute();
    if (function_exists('error_log')) {
        error_log("[IndexStatusUpdate] 'Free' rooms set to DB status 'booked' if pending check-in for TODAY: " . $stmtSetBooked->rowCount());
    }


    // ** 3. Free up rooms for PAST 'booked' (No-Show) that were NOT Zone F. **
    $stmtFreeNoShowNonZoneF = $pdo->prepare("
        UPDATE rooms r
        SET r.status = 'free'
        WHERE r.status = 'booked'
          AND r.zone != 'F' 
          AND EXISTS ( 
              SELECT 1 FROM bookings b
              WHERE b.room_id = r.id
                AND DATE(b.checkin_datetime) < CURDATE() -- การจองของเมื่อวานหรือก่อนหน้า
                AND b.id = ( 
                    SELECT b_past.id FROM bookings b_past 
                    WHERE b_past.room_id = r.id 
                    ORDER BY b_past.checkin_datetime DESC LIMIT 1
                )
          )
          AND NOT EXISTS ( 
              SELECT 1 FROM bookings b_today_active_or_pending
              WHERE b_today_active_or_pending.room_id = r.id
                AND (
                    (DATE(b_today_active_or_pending.checkin_datetime) = CURDATE() AND NOW() < b_today_active_or_pending.checkout_datetime_calculated) -- Pending or Active today
                    OR
                    (DATE(b_today_active_or_pending.checkin_datetime) > CURDATE()) -- Future booking
                )
          );
    ");
    $stmtFreeNoShowNonZoneF->execute();
    if (function_exists('error_log')) {
        error_log("[IndexStatusUpdate] Non-Zone F Past 'booked' (no-show) rooms processed to 'free': " . $stmtFreeNoShowNonZoneF->rowCount());
    }
    
    $pdo->commit();
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if (function_exists('error_log')) {
        error_log("Error in daily status update (index.php): " . $e->getMessage());
    }
}
// --- END: Adjusted Status Update Logic ---


// Fetch statistics (Counts from the 'rooms' table status after ALL updates)
$bookedCount = $pdo->query("SELECT COUNT(*) FROM rooms WHERE status='booked'")->fetchColumn();
$occupiedCount = $pdo->query("SELECT COUNT(*) FROM rooms WHERE status='occupied'")->fetchColumn();
$freeCount = $pdo->query("SELECT COUNT(*) FROM rooms WHERE status='free'")->fetchColumn();

$stmtTodayOccupancy = $pdo->query(
    "SELECT COUNT(DISTINCT b.room_id) FROM bookings b
     WHERE b.checkin_datetime <= NOW()
       AND NOW() < b.checkout_datetime_calculated"
);
$todayOccupancyCount = $stmtTodayOccupancy->fetchColumn();

// --- START: View Mode Logic ---
$viewMode = trim($_GET['view'] ?? 'grid'); // 'grid' or 'table'
if (!in_array($viewMode, ['grid', 'table'])) {
    $viewMode = 'grid'; // Default to grid if invalid value
}
// --- END: View Mode Logic ---

// --- START: การปรับปรุง SQL Query หลัก (`$roomsDataQuery`) เพื่อให้ `display_status` สอดคล้อง ---
// เป้าหมาย: ให้ display_status สะท้อนสถานะที่รอการ manual check-in และการจองของวันพรุ่งนี้
$roomsDataQuery = $pdo->prepare("
    SELECT
        r.id,
        r.zone,
        r.room_number,
        r.status AS db_actual_status,
        r.price_per_day,
        r.price_short_stay,
        r.allow_short_stay,
        r.short_stay_duration_hours,
        
        current_booking.id AS current_booking_id,
        current_booking.customer_name AS current_customer_name,
        current_booking.customer_phone AS current_customer_phone,
        DATE_FORMAT(current_booking.checkin_datetime, '%e %b %Y, %H:%i น.') AS current_formatted_checkin,
        DATE_FORMAT(current_booking.checkout_datetime_calculated, '%e %b %Y, %H:%i น.') AS current_formatted_checkout,
        current_booking.checkout_datetime_calculated AS current_raw_checkout_datetime,
        current_booking.booking_type AS current_booking_type,
        current_booking.receipt_path AS current_receipt_path,
        current_booking.nights AS current_nights,
        COALESCE(r.short_stay_duration_hours, " . (defined('DEFAULT_SHORT_STAY_DURATION_HOURS') ? DEFAULT_SHORT_STAY_DURATION_HOURS : 3) . ") AS current_short_stay_duration,
        current_booking.total_price AS current_total_price,
        current_booking.amount_paid AS current_amount_paid,
        current_booking.booking_group_id AS current_booking_group_id,

        (CASE
            WHEN current_booking.id IS NOT NULL AND NOW() >= current_booking.checkout_datetime_calculated THEN 1
            ELSE 0
        END) AS is_overdue,
        
        -- Flag สำหรับการแจ้งเตือน (ไม่มีการเปลี่ยนแปลง)
        (CASE 
            WHEN current_booking.id IS NOT NULL 
                 AND current_booking.checkin_datetime <= NOW() 
                 AND NOW() < current_booking.checkout_datetime_calculated 
                 AND TIMESTAMPDIFF(MINUTE, NOW(), current_booking.checkout_datetime_calculated) <= 60
                 AND TIMESTAMPDIFF(MINUTE, NOW(), current_booking.checkout_datetime_calculated) > 0 
            THEN 1 ELSE 0 
        END) AS is_nearing_checkout_dashboard,

        (CASE 
            WHEN current_booking.id IS NOT NULL AND current_booking.total_price > current_booking.amount_paid 
            THEN 1 ELSE 0 
        END) AS has_pending_payment_dashboard,

        -- ** START: MODIFICATION - ปรับปรุง CASE สำหรับ display_status ตามโจทย์ **
        CASE
            -- Priority 1: Overdue Occupied (เกินกำหนด)
            WHEN current_booking.id IS NOT NULL AND NOW() >= current_booking.checkout_datetime_calculated THEN 'overdue_occupied'

            -- Priority 2: Occupied (กำลังเข้าพัก, สถานะใน DB เป็น occupied)
            WHEN current_booking.id IS NOT NULL
                 AND current_booking.checkin_datetime <= NOW()
                 AND NOW() < current_booking.checkout_datetime_calculated
                 AND r.status = 'occupied'
            THEN 'occupied'

            -- Priority 3: F Short Occupied (โซน F ชั่วคราว กำลังเข้าพัก)
            WHEN r.zone = 'F' AND current_booking.id IS NOT NULL AND current_booking.booking_type = 'short_stay'
                 AND current_booking.checkin_datetime <= NOW() AND NOW() < current_booking.checkout_datetime_calculated
            THEN 'f_short_occupied'

            -- Priority 4: 'booked' (รอเช็คอินสำหรับ 'วันนี้' เท่านั้น)
            WHEN current_booking.id IS NOT NULL
                 AND DATE(current_booking.checkin_datetime) = CURDATE()
                 AND NOW() < current_booking.checkout_datetime_calculated
            THEN 'booked'

            -- Priority 5: 'advance_booking' (มีการจองสำหรับ 'วันพรุ่งนี้' เท่านั้น)
            WHEN current_booking.id IS NOT NULL
                 AND DATE(current_booking.checkin_datetime) = DATE_ADD(CURDATE(), INTERVAL 1 DAY)
                 AND NOT EXISTS ( -- ตรวจสอบให้แน่ใจว่าไม่มีใครพักห้องนี้อยู่
                       SELECT 1 FROM bookings b_active
                       WHERE b_active.room_id = r.id
                       AND b_active.checkin_datetime <= NOW() AND NOW() < b_active.checkout_datetime_calculated
                 )
            THEN 'advance_booking'

            -- Priority 6: 'free' (ห้องว่างสำหรับวันนี้)
            -- หากไม่เข้าเงื่อนไขข้างบนทั้งหมด ให้ถือว่าห้อง 'ว่าง'
            -- ซึ่งจะรวมถึงห้องที่ว่างจริงๆ และห้องที่มีการจองล่วงหน้าไกลกว่าวันพรุ่งนี้
            ELSE 'free'
        END AS display_status,
        -- ** END: MODIFICATION **

        (SELECT b.id FROM bookings b
         WHERE b.room_id = r.id AND b.checkout_datetime_calculated > NOW() -- Check any future or ongoing booking
         ORDER BY b.checkin_datetime ASC
         LIMIT 1) as relevant_booking_id

FROM rooms r
LEFT JOIN ( 
    -- Subquery นี้จะหา Booking ที่เกี่ยวข้องที่สุดกับสถานะปัจจุบันของห้อง (ไม่มีการเปลี่ยนแปลง)
    SELECT
        b_inner.room_id, 
        b_inner.id,
        b_inner.customer_name,
        b_inner.customer_phone,
        b_inner.checkin_datetime,
        b_inner.checkout_datetime_calculated,
        b_inner.booking_type,
        b_inner.receipt_path,
        b_inner.nights,
        b_inner.total_price, 
        b_inner.amount_paid,
        b_inner.booking_group_id
    FROM bookings b_inner
    WHERE
        b_inner.id = (
            SELECT b_latest.id
            FROM bookings b_latest
            WHERE b_latest.room_id = b_inner.room_id
            ORDER BY 
                (CASE 
                    -- 1. Active Now
                    WHEN b_latest.checkin_datetime <= NOW() AND NOW() < b_latest.checkout_datetime_calculated THEN 1 
                    -- 2. Pending Today
                    WHEN DATE(b_latest.checkin_datetime) = CURDATE() THEN 2  
                    -- 3. Booked for Tomorrow
                    WHEN DATE(b_latest.checkin_datetime) = DATE_ADD(CURDATE(), INTERVAL 1 DAY) THEN 3
                    -- 4. Overdue
                    WHEN NOW() >= b_latest.checkout_datetime_calculated THEN 4 
                    -- 5. Others (Future bookings > tomorrow)
                    ELSE 5 
                END) ASC, 
                b_latest.checkin_datetime ASC, -- ถ้า Priority เท่ากัน เอาอันที่เช็คอินก่อน
                b_latest.id DESC 
            LIMIT 1
        )
) AS current_booking ON current_booking.room_id = r.id
GROUP BY r.id 
ORDER BY r.zone ASC, CAST(r.room_number AS UNSIGNED) ASC
");
$roomsDataQuery->execute();
$roomsData = $roomsDataQuery->fetchAll(PDO::FETCH_ASSOC);
// --- END: การปรับปรุง SQL Query หลัก ---

// Group rooms by custom titles
$groupedRooms = [
    'นั้งกินนอนฟิน' => [], // For Zones A, B, C
    'ภัทรรีสอร์ท' => []    // For Zone F
];
foreach ($roomsData as $room) {
    if (in_array($room['zone'], ['A', 'B', 'C'])) {
        $groupedRooms['นั้งกินนอนฟิน'][] = $room;
    } elseif ($room['zone'] === 'F') {
        $groupedRooms['ภัทรรีสอร์ท'][] = $room;
    } else {
        // Create a new group if it doesn't exist for other zones
        if (!isset($groupedRooms['โซน ' . $room['zone']])) {
            $groupedRooms['โซน ' . $room['zone']] = [];
        }
        $groupedRooms['โซน ' . $room['zone']][] = $room;
    }
}

// Fetch all ADVANCE bookings (checkin_datetime > NOW()) for the table at the bottom
// ----- START: โค้ดที่แก้ไข -----
$advBookingsQuery = $pdo->prepare(
    "SELECT b.id, r.zone, r.room_number, r.id as room_id_for_link, b.customer_name, b.receipt_path,
            DATE_FORMAT(b.checkin_datetime, '%e %b %Y, %H:%i น.') AS checkin_datetime_formatted,
            DATE_FORMAT(b.checkout_datetime_calculated, '%e %b %Y, %H:%i น.') AS checkout_datetime_formatted,
            b.nights, b.booking_type, r.short_stay_duration_hours, b.booking_group_id
     FROM bookings b
     JOIN rooms r ON b.room_id = r.id
     WHERE b.checkin_datetime > NOW() -- Only future bookings
     ORDER BY b.checkin_datetime ASC, r.zone ASC, CAST(r.room_number AS UNSIGNED) ASC"
);
// ----- END: โค้ดที่แก้ไข -----
$advBookingsQuery->execute();
$advBookings = $advBookingsQuery->fetchAll(PDO::FETCH_ASSOC);

// Handle Customer Search
$customerSearchTerm = trim($_GET['customer_search'] ?? '');
$searchedBookings = [];
if (!empty($customerSearchTerm)) {
    $searchStmt = $pdo->prepare("
        SELECT
            b.id AS booking_id, r.id as room_id, r.zone, r.room_number, b.customer_name, b.customer_phone,
            DATE_FORMAT(b.checkin_datetime, '%e %b %Y, %H:%i น.') AS checkin_datetime_formatted,
            DATE_FORMAT(b.checkout_datetime_calculated, '%e %b %Y, %H:%i น.') AS checkout_datetime_formatted,
            b.booking_type, r.short_stay_duration_hours, b.nights,
            CASE
                WHEN b.checkin_datetime <= NOW() AND NOW() < b.checkout_datetime_calculated THEN 'กำลังเข้าพัก'
                WHEN DATE(b.checkin_datetime) = CURDATE() AND NOW() < b.checkin_datetime THEN 'รอเช็คอินวันนี้'
                WHEN DATE(b.checkin_datetime) > CURDATE() THEN 'จองล่วงหน้า'
                WHEN b.checkout_datetime_calculated <= NOW() THEN 'เช็คเอาท์แล้ว (รอเก็บเข้าประวัติ)' 
                ELSE 'อื่นๆ'
            END AS booking_status_display
        FROM bookings b
        JOIN rooms r ON b.room_id = r.id
        WHERE b.customer_name LIKE :searchTerm
        ORDER BY b.checkin_datetime DESC
        LIMIT 50
    ");
    $searchStmt->execute([':searchTerm' => '%' . $customerSearchTerm . '%']);
    $searchedBookings = $searchStmt->fetchAll(PDO::FETCH_ASSOC);
}

ob_start();
?>

<div class="dashboard-stats">
  <div class="stat-box">
    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" style="margin-bottom: 0.5rem; color: var(--color-warning-dark);"><path d="M7 4a2 2 0 012-2h6a2 2 0 012 2v2h2a1 1 0 011 1v11a1 1 0 01-1 1H6a1 1 0 01-1-1V7a1 1 0 011-1h2V4zm10 4H7v9h10V8zM9 6V4h6v2H9z" fill="currentColor"/></svg>
    <h3>รอเช็กอิน (วันนี้)</h3>
    <p><?= h($bookedCount) ?></p>
  </div>
  <div class="stat-box">
    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" style="margin-bottom: 0.5rem; color: var(--color-alert-dark);"><path d="M17.663 2.307C17.29 2.115 16.863 2 16.403 2c-1.717 0-3.314.958-4.403 2.438C10.914 2.958 9.317 2 7.597 2c-.46 0-.887.115-1.26.307A4.002 4.002 0 003 6.166V18c0 1.104.896 2 2 2h14c1.104 0 2-.896 2-2V6.166a4.002 4.002 0 00-3.337-3.859zM5 18V6.166a2.001 2.001 0 011.663-1.973c.393-.2.854-.315 1.337-.315.968 0 1.896.532 2.597 1.403V18H5zm14 0h-5.597V5.281c.701-.871 1.629-1.403 2.597-1.403.483 0 .944.115 1.337.315A2.001 2.001 0 0119 6.166V18z" fill="currentColor"/></svg>
    <h3>เช็กอินแล้ว (Occupied)</h3>
    <p><?= h($occupiedCount) ?></p>
  </div>
  <div class="stat-box">
     <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" style="margin-bottom: 0.5rem; color: var(--color-secondary-dark);"><path d="M17.663 2.307C17.29 2.115 16.863 2 16.403 2c-1.717 0-3.314.958-4.403 2.438C10.914 2.958 9.317 2 7.597 2c-.46 0-.887.115-1.26.307A4.002 4.002 0 003 6.166V18c0 1.104.896 2 2 2h14c1.104 0 2-.896 2-2V6.166a4.002 4.002 0 00-3.337-3.859zM5 18V6.166a2.001 2.001 0 011.663-1.973c.393-.2.854-.315 1.337-.315.968 0 1.896.532 2.597 1.403V18H5zm14 0h-5.597V5.281c.701-.871 1.629-1.403 2.597-1.403.483 0 .944.115 1.337.315A2.001 2.001 0 0119 6.166V18zM9.5 11a.5.5 0 000 1h5a.5.5 0 000-1h-5z" fill="currentColor"/></svg>
    <h3>ห้องว่าง (Free Now)</h3>
    <p><?= h($freeCount) ?></p>
  </div>
  <div class="stat-box">
     <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" style="margin-bottom: 0.5rem; color: var(--color-primary-dark);"><path clip-rule="evenodd" d="M10 1a1 1 0 011 1v2.065A8.001 8.001 0 0119.935 11H22a1 1 0 110 2h-2.065a8.001 8.001 0 01-15.87 0H2a1 1 0 110-2h2.065A8.001 8.001 0 0110 4.065V2a1 1 0 011-1zM7.5 12a4.5 4.5 0 109 0 4.5 4.5 0 00-9 0zm4.5-2.5a1 1 0 00-1 1v1h-1a1 1 0 100 2h1v1a1 1 0 102 0v-1h1a1 1 0 100-2h-1v-1a1 1 0 00-1-1z" fill-rule="evenodd" fill="currentColor"/></svg>
    <h3>กำลังเข้าพัก (Active Stays)</h3>
    <p><?= h($todayOccupancyCount) ?></p>
  </div>
</div>

<?php // --- START: Toolbar for Share Dashboard & View Toggle Switch --- ?>
<div class="dashboard-toolbar" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 10px;">
    <div>
        <button id="share-dashboard-btn" class="button info px-4 py-2 rounded-md shadow-sm hover:shadow-md transition-shadow duration-200 flex items-center">
            <img src="/hotel_booking/assets/image/share.png" alt="Share" style="width:16px; height:16px; margin-right:8px; vertical-align:middle;">
            แชร์ภาพรวม Dashboard
        </button>
    </div>
    <div>
        <?php $isTableView = ($viewMode === 'table'); ?>
        <label for="view-mode-toggle-checkbox" class="switch" aria-label="สลับมุมมอง Grid และ ตาราง">
          <input type="checkbox" id="view-mode-toggle-checkbox" <?= $isTableView ? 'checked' : '' ?> />
          <span>Grid</span><span>ตาราง</span>
        </label>
    </div>
</div>
<?php // --- END: Toolbar for Share Dashboard & View Toggle Switch --- ?>

<form method="GET" action="index.php" class="report-filter mb-8 bg-white p-4 rounded-lg shadow">
    <div class="filter-group flex flex-wrap gap-4 items-end">
        <div class="flex-grow">
            <label for="customer_search" class="block text-sm font-medium text-gray-700 mb-1">ค้นหาชื่อผู้จอง (การจองปัจจุบันและอนาคต):</label>
            <input type="text" name="customer_search" id="customer_search" value="<?= h($customerSearchTerm) ?>" placeholder="พิมพ์ชื่อลูกค้า..." class="p-2 border border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500 w-full shadow-sm">
        </div>
        <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white font-semibold py-2 px-4 rounded-md shadow-sm hover:shadow-md transition-shadow duration-200">ค้นหา</button>
        <?php if (!empty($customerSearchTerm)): ?>
            <?php $clearSearchParams = ['view' => $viewMode]; ?>
            <a href="index.php?<?= http_build_query($clearSearchParams) ?>" class="border border-gray-300 hover:bg-gray-100 text-gray-700 font-semibold py-2 px-4 rounded-md shadow-sm hover:shadow-md transition-shadow duration-200">ล้างการค้นหา</a>
        <?php endif; ?>
    </div>
    <input type="hidden" name="view" id="search_view_mode_input" value="<?= h($viewMode) ?>">
</form>

<?php if (!empty($customerSearchTerm)): ?>
    <section class="search-results-section report-section mb-8">
        <h3 class="text-xl font-semibold mb-3">ผลการค้นหาสำหรับ "<?= h($customerSearchTerm) ?>"</h3>
        <?php if (!empty($searchedBookings)): ?>
            <div class="table-responsive shadow border-b border-gray-200 sm:rounded-lg">
                <table class="report-table modern-table min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">ID จอง</th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">ห้อง</th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">ชื่อลูกค้า</th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">เบอร์โทร</th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">เช็คอิน</th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">เช็คเอาท์</th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">ประเภท</th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">ระยะเวลา</th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">สถานะ</th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">ดำเนินการ</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php foreach ($searchedBookings as $sBooking): ?>
                        <tr>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?= h($sBooking['booking_id']) ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?= h($sBooking['zone'] . $sBooking['room_number']) ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?= h($sBooking['customer_name']) ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?= h($sBooking['customer_phone'] ?? '-') ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?= h($sBooking['checkin_datetime_formatted']) ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?= h($sBooking['checkout_datetime_formatted']) ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?= h($sBooking['booking_type'] === 'short_stay' ? 'ชั่วคราว' : 'ค้างคืน') ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?= h($sBooking['booking_type'] === 'short_stay' ? ($sBooking['short_stay_duration_hours'] . ' ชม.') : ($sBooking['nights'] . ' คืน')) ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?= h($sBooking['booking_status_display']) ?></td>
                            <td class="actions-cell px-6 py-4 whitespace-nowrap text-sm font-medium space-x-2">
                                <button class="button-small room px-3 py-1 text-xs font-medium rounded-md text-gray-700 bg-gray-200 hover:bg-gray-300 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-gray-500" data-id="<?=h($sBooking['room_id'])?>" data-booking-id="<?=h($sBooking['booking_id'])?>">ดูห้อง</button>
                                <a href="booking.php?edit_booking_id=<?= h($sBooking['booking_id']) ?>" class="button-small edit-booking-btn info px-3 py-1 text-xs font-medium rounded-md text-white bg-blue-500 hover:bg-blue-600 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">แก้ไข</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <p class="text-gray-600">ไม่พบการจองตามชื่อที่ค้นหา</p>
        <?php endif; ?>
    </section>
<?php endif; ?>

<div class="status-container">
  <div class="status-box"><div class="color-box status-overdue_occupied" style="background-color: var(--color-alert-dark, #a71d2a);"></div><span>เกินกำหนด (Overdue)</span></div>
  <div class="status-box"><div class="color-box occupied"></div><span>ห้องไม่ว่าง (Occupied)</span></div>
  <div class="status-box"><div class="color-box booked"></div><span>รอเช็กอิน (Booked for Today)</span></div>
  <div class="status-box"><div class="color-box status-f_short_occupied" style="background-color: var(--color-purple, #6f42c1);"></div><span>โซน F (ชั่วคราว, ไม่ว่าง)</span></div>
  <div class="status-box"><div class="color-box free"></div><span>ห้องว่าง (Free)</span></div>
  <div class="status-box"><div class="color-box advance_booking"></div><span>มีจองล่วงหน้า (Free Today)</span></div>
</div>

<?php // --- START: View Switch (Grid or Table) --- ?>
<?php if ($viewMode === 'grid'): ?>
    <?php foreach ($groupedRooms as $groupName => $roomsInGroup): ?>
        <?php if (!empty($roomsInGroup)): ?>
            <h3 class="text-xl font-semibold mt-8 mb-4 pb-2 border-b border-gray-300"><?= h($groupName) ?></h3>
            <div class="rooms-grid">
              <?php foreach ($roomsInGroup as $r): ?>
                <div class="room-container" title="ห้อง <?= h($r['zone'] . $r['room_number']) ?> - สถานะ: <?= h(ucfirst(str_replace('_', ' ', $r['display_status']))) ?> <?= ($r['is_overdue'] ?? 0) ? '(เกินกำหนด!)' : '' ?>">
                    <svg
                        class="room room-svg-house <?= h($r['display_status']) ?> <?= ($r['is_overdue'] ?? 0) ? 'has-overdue-indicator' : '' ?>" 
                        viewBox="0 0 100 95"
                        data-id="<?= h($r['id']) ?>"
                        data-status="<?= h($r['display_status']) ?>"
                        data-is-overdue="<?= ($r['is_overdue'] ?? 0) ? 'true' : 'false' ?>"
                        <?php if (!empty($r['relevant_booking_id']) && !in_array($r['display_status'], ['occupied', 'booked', 'overdue_occupied', 'f_short_occupied'] ) ): ?>
                            data-booking-id="<?= h($r['relevant_booking_id']) ?>"
                        <?php elseif (!empty($r['current_booking_id'])): ?>
                            data-booking-id="<?= h($r['current_booking_id']) ?>"
                        <?php endif; ?>
                    >
                        <path class="house-shape" d="M50 0 L0 35 L0 95 L100 95 L100 35 Z" />
                        <text class="room-text" x="50" y="67" text-anchor="middle" dominant-baseline="middle">
                            <?= h($r['zone'] . $r['room_number']) ?>
                        </text>
                        <?php if ($r['is_overdue'] ?? 0): ?>
                            <text class="overdue-indicator-svg" x="85" y="25" font-size="24" fill="red" dominant-baseline="middle" text-anchor="middle">⚠️</text>
                        <?php endif; ?>
                    </svg>
                </div>
              <?php endforeach; ?>
            </div>
        <?php endif; ?>
    <?php endforeach; ?>
<?php elseif ($viewMode === 'table'): ?>
    <section class="report-section mt-8">
        <h3 class="text-xl font-semibold mb-4">ภาพรวมห้องพักวันนี้ (มุมมองตาราง)</h3>

        <?php // ***** START: โค้ดที่เพิ่มเข้ามา ***** ?>
        <div id="group-action-toolbar" style="padding: 0.5rem 0; text-align: right; display: none;">
            <button id="group-selected-bookings-btn" class="button secondary">
                <i class="fas fa-object-group"></i> จัดกลุ่มที่เลือก (<span id="selected-booking-count">0</span>)
            </button>
        </div>
        <?php // ***** END: โค้ดที่เพิ่มเข้ามา ***** ?>

        <div class="table-responsive shadow border-b border-gray-200 sm:rounded-lg">
            <table class="report-table modern-table min-w-full divide-y divide-gray-200" id="room-status-table-view">
                <thead class="bg-gray-50">
                    <tr>
                        <?php // ***** START: โค้ดที่เพิ่มเข้ามา ***** ?>
                        <th scope="col" class="px-3 py-3 text-center">
                            <input type="checkbox" id="select-all-bookings-checkbox" title="เลือกทั้งหมด">
                        </th>
                        <?php // ***** END: โค้ดที่เพิ่มเข้ามา ***** ?>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">ห้อง</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">สถานะ</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">ผู้เข้าพัก/รอเช็คอิน</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">เบอร์โทร</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">เช็คอิน</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">เช็คเอาท์</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">ประเภท</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">ระยะเวลา</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">สลิป</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider" style="min-width: 200px;">ดำเนินการ</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php if (empty($roomsData)): ?>
                        <tr><td colspan="11" class="px-6 py-4 text-center text-sm text-gray-500"><em>ไม่พบข้อมูลห้องพัก</em></td></tr>
                    <?php else: ?>
                        <?php foreach ($roomsData as $room): ?>
                            <tr class="room-row-status-<?= h($room['display_status']) ?> <?= ($room['is_overdue'] ?? 0) ? 'has-overdue-indicator-row' : '' ?>">
                                <?php // ***** START: โค้ดที่เพิ่มเข้ามา ***** ?>
                                <td class="px-3 py-4 whitespace-nowrap text-center">
                                    <?php if (!empty($room['current_booking_id'])): ?>
                                        <input type="checkbox" class="booking-group-checkbox" data-booking-id="<?= h($room['current_booking_id']) ?>">
                                    <?php endif; ?>
                                </td>
                                <?php // ***** END: โค้ดที่เพิ่มเข้ามา ***** ?>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900 room-name-cell" data-room-id-cell="<?= h($room['id']) ?>">
                                    <strong><?= h($room['zone'] . $room['room_number']) ?></strong>
                                    <?php if ($room['is_overdue'] ?? 0): ?>
                                        <span class="overdue-indicator-table" title="การจองนี้เกินกำหนดเวลาเช็คเอาท์แล้ว">⚠️</span>
                                    <?php endif; ?>

                                    <span class="nearing-checkout-indicator-table" style="display: none; color: orange; margin-left: 4px;" title="ใกล้หมดเวลาเช็คเอาท์!">
                                        <img src="/hotel_booking/assets/image/clock_alert.png" alt="Clock Alert" style="width:16px; height:16px; vertical-align:middle;">
                                    </span>
                                    <span class="pending-payment-indicator-table" style="display: none; color: green; margin-left: 4px;" title="มียอดค้างชำระ!">
                                        <img src="/hotel_booking/assets/image/money_alert.png" alt="Money Alert" style="width:16px; height:16px; vertical-align:middle;">
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span class="status-indicator status-<?= h($room['display_status']) ?> px-2 inline-flex text-xs leading-5 font-semibold rounded-full" style="color: white;
                                        background-color: <?=
                                            match($room['display_status']) {
                                                'overdue_occupied' => 'var(--color-alert-dark, #a71d2a)',
                                                'occupied' => 'var(--color-danger, #DC2626)',
                                                'booked' => 'var(--color-warning, #F59E0B)',
                                                'free' => 'var(--color-success, #10B981)',
                                                'advance_booking' => 'var(--color-info, #3B82F6)',
                                                'f_short_occupied' => 'var(--color-purple, #6f42c1)',
                                                default => 'var(--color-secondary-text, #6B7280)'
                                            }
                                        ?> ;">
                                        <?= h(ucfirst(str_replace(['_', 'f short '], [' ', 'F ชั่วคราว '], $room['display_status']))) ?>
                                    </span>
                                </td>
                                <?php if (!empty($room['current_customer_name']) && in_array($room['display_status'], ['occupied', 'booked', 'f_short_occupied', 'overdue_occupied'])): ?>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?= h($room['current_customer_name']) ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        <?php if (!empty($room['current_customer_phone'])): ?>
                                            <a href="tel:<?= h(preg_replace('/[^0-9+]/', '', $room['current_customer_phone'])) ?>" class="text-indigo-600 hover:text-indigo-900"><?= h($room['current_customer_phone']) ?></a>
                                        <?php else: echo '-'; endif; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?= h($room['current_formatted_checkin']) ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?= h($room['current_formatted_checkout']) ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?= h($room['current_booking_type'] === 'short_stay' ? 'ชั่วคราว' : 'ค้างคืน') ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        <?= h($room['current_booking_type'] === 'short_stay' ? ($room['current_short_stay_duration'] . ' ชม.') : ($room['current_nights'] . ' คืน')) ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        <?php if (!empty($room['current_receipt_path'])): ?>
                                            <img src="/hotel_booking/uploads/receipts/<?= h($room['current_receipt_path']) ?>"
                                                 alt="สลิป" class="receipt-thumbnail-table receipt-btn-global w-10 h-10 object-cover rounded-md cursor-pointer shadow-sm hover:shadow-md"
                                                 data-src="/hotel_booking/uploads/receipts/<?= h($room['current_receipt_path']) ?>">
                                        <?php else: echo '-'; endif; ?>
                                    </td>
                                <?php else: ?>
                                    <td colspan="7" class="px-6 py-4 text-center text-sm text-gray-500 italic">
                                        <?=
                                            match($room['display_status']) {
                                                'free' => 'ห้องว่าง',
                                                'advance_booking' => 'มีจองล่วงหน้า (สำหรับวันพรุ่งนี้)',
                                                default => 'รอข้อมูล / ยังไม่มีการจองสำหรับวันนี้'
                                            };
                                        ?>
                                    </td>
                                <?php endif; ?>
                                <td class="actions-cell px-6 py-4 whitespace-nowrap text-sm font-medium space-x-2">
                                    <button class="button-small room px-3 py-1 text-xs font-medium rounded-md text-gray-700 bg-gray-200 hover:bg-gray-300 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-gray-500" data-id="<?=h($room['id'])?>"
                                        <?php if (!empty($room['current_booking_id'])): ?>
                                            data-booking-id="<?=h($room['current_booking_id'])?>"
                                        <?php elseif(!empty($room['relevant_booking_id'])): ?>
                                            data-booking-id="<?=h($room['relevant_booking_id'])?>"
                                        <?php endif; ?>>ดูห้อง</button>

                                    <?php if ($room['display_status'] === 'booked' && !empty($room['current_booking_id'])): ?>
                                        <button class="button-small occupy-btn-table success px-3 py-1 text-xs font-medium rounded-md text-white bg-green-500 hover:bg-green-600 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500" data-booking-id="<?=h($room['current_booking_id'])?>" id="occupy-tbl-<?=h($room['current_booking_id'])?>">เช็คอิน</button>
                                    <?php endif; ?>

                                    <?php // -- START: ส่วนที่แก้ไขและเพิ่มเติม -- ?>
                                    <?php if (!empty($room['current_booking_id']) && in_array($room['display_status'], ['occupied', 'booked', 'f_short_occupied', 'overdue_occupied'])): ?>
                                        
                                        <?php // ถ้ามี current_booking_group_id ให้แสดงปุ่มแก้ไขกลุ่ม ?>
                                        <?php if (!empty($room['current_booking_group_id'])): ?>
                                            <a href="edit_booking_group.php?booking_group_id=<?= h($room['current_booking_group_id']) ?>" class="button-small warning px-3 py-1 text-xs font-medium rounded-md text-white bg-yellow-500 hover:bg-yellow-600">แก้ไขกลุ่ม</a>
                                        <?php endif; ?>

                                        <a href="booking.php?edit_booking_id=<?= h($room['current_booking_id']) ?>" class="button-small edit-booking-btn info px-3 py-1 text-xs font-medium rounded-md text-white bg-blue-500 hover:bg-blue-600">แก้ไขห้องนี้</a>
                                    
                                    <?php elseif ($room['display_status'] === 'free' || $room['display_status'] === 'advance_booking'): ?>
                                        <a href="booking.php?room_id=<?= h($room['id']) ?>" class="button-small success px-3 py-1 text-xs font-medium rounded-md text-white bg-green-500 hover:bg-green-600 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500">จองห้องนี้</a>
                                    <?php endif; ?>
                                    <?php // -- END: ส่วนที่แก้ไขและเพิ่มเติม -- ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endif; ?>
<?php // --- END: View Switch (Grid or Table) --- ?>


<?php if (!empty($advBookings)): ?>
<h3 class="text-xl font-semibold mt-10 mb-4 pb-2 border-b border-gray-300">การจองล่วงหน้าทั้งหมด (All Future Bookings)</h3>
<div class="table-responsive shadow border-b border-gray-200 sm:rounded-lg">
    <table class="report-table modern-table advance-table min-w-full divide-y divide-gray-200">
      <thead class="bg-gray-50">
        <tr>
          <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">ห้อง</th>
          <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">ผู้จอง</th>
          <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">กลุ่ม</th>
          <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">วันเวลาเช็กอิน</th>
          <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">วันเวลาเช็กเอาต์</th>
          <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">ประเภท</th>
          <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">ระยะเวลา</th>
          <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider" style="min-width: 260px;">การดำเนินการ</th>
        </tr>
      </thead>
      <tbody class="bg-white divide-y divide-gray-200">
        <?php foreach($advBookings as $a): ?>
        <tr>
          <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?= h($a['zone'].$a['room_number']) ?></td>
          <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?= h($a['customer_name']) ?></td>

          <?php // --- START: ส่วนที่เพิ่มเข้ามา --- ?>
          <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 text-center">
              <?php if (!empty($a['booking_group_id'])): ?>
                  <a href="edit_booking_group.php?booking_group_id=<?= h($a['booking_group_id']) ?>" 
                     class="link-like" 
                     title="ไปที่หน้าแก้ไขกลุ่ม ID: <?= h($a['booking_group_id']) ?>">
                      กลุ่ม #<?= h($a['booking_group_id']) ?>
                  </a>
              <?php else: ?>
                  <span class="text-muted">-</span>
              <?php endif; ?>
          </td>
          <?php // --- END: ส่วนที่เพิ่มเข้ามา --- ?>
          
          <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?= h($a['checkin_datetime_formatted']) ?></td>
          <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?= h($a['checkout_datetime_formatted']) ?></td>
          <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?= h($a['booking_type'] === 'short_stay' ? 'ชั่วคราว' : 'ค้างคืน') ?></td>
          <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?= h($a['booking_type'] === 'short_stay' ? ($a['short_stay_duration_hours'] . ' ชม.') : ($a['nights'] . ' คืน')) ?></td>
          <td class="actions-cell px-6 py-4 whitespace-nowrap text-sm font-medium space-x-2">
            <?php if (!empty($a['receipt_path'])): ?>
              <button class="button-small receipt-btn-global px-3 py-1 text-xs font-medium rounded-md text-white bg-teal-500 hover:bg-teal-600 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-teal-500" data-src="/hotel_booking/uploads/receipts/<?= h($a['receipt_path']) ?>">ดูสลิป</button>
            <?php endif; ?>
            <button class="button-small room px-3 py-1 text-xs font-medium rounded-md text-gray-700 bg-gray-200 hover:bg-gray-300 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-gray-500" data-id="<?=h($a['room_id_for_link'])?>" data-booking-id="<?=h($a['id'])?>">ดูห้อง</button>
            <a href="/hotel_booking/pages/booking.php?edit_booking_id=<?= h($a['id']) ?>" class="button-small edit-booking-btn info px-3 py-1 text-xs font-medium rounded-md text-white bg-blue-500 hover:bg-blue-600 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">แก้ไข</a>
            <button class="delete-booking-btn flex inline-flex items-center gap-x-1 h-7 px-3 cursor-pointer rounded-md shadow text-white text-xs font-semibold bg-gradient-to-r from-[#fb7185] via-[#e11d48] to-[#be123c] hover:shadow-xl hover:shadow-red-500/50 hover:scale-105 duration-300 hover:from-[#be123c] hover:to-[#fb7185]" data-booking-id="<?= h($a['id']) ?>" id="delete-adv-booking-idx-<?=h($a['id'])?>">
              <svg class="w-4 h-4" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" stroke-linejoin="round" stroke-linecap="round"></path>
              </svg>
              <span>ลบ</span>
            </button>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
      </table>
</div>
<?php else: ?>
<p class="mt-6 text-gray-600">ไม่มีการจองล่วงหน้าในขณะนี้</p>
<?php endif; ?>

<div id="modal" class="modal-overlay">
  <div class="modal-content" data-aos="fade-down" data-aos-duration="300">
    <button class="modal-close" aria-label="Close">×</button>
    <div id="modal-body">
        </div>
  </div>
</div>

<div id="image-modal" class="modal-overlay">
  <div class="modal-content" data-aos="zoom-in" data-aos-duration="300" style="max-width:700px; width:90%;">
    <button class="modal-close" aria-label="Close">×</button>
    <img id="modal-image" src="" alt="หลักฐาน" style="max-width:100%; height:auto; border-radius:var(--border-radius-md); display:block;" />
  </div>
</div>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../templates/layout.php';
?>