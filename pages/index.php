<?php
// FILEX: hotel_booking/pages/index.php
require_once __DIR__ . '/../bootstrap.php';
require_login();

$pageTitle = 'Dashboard โรงแรม';

// --- START: New Automatic Archiving for OVERDUE ZONE F Bookings (WITH DEPOSIT CHECK) ---
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
          AND b.id = ( 
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

// --- START: Adjusted Status Update Logic ---
try {
    $pdo->beginTransaction();

    $stmtCorrectRoomStatus = $pdo->prepare("
        UPDATE rooms r
        SET r.status = 'free'
        WHERE r.status != 'free' 
          AND NOT EXISTS (
              SELECT 1 FROM bookings b
              WHERE b.room_id = r.id
                AND (
                    (b.checkin_datetime <= NOW() AND NOW() < b.checkout_datetime_calculated)
                    OR
                    (DATE(b.checkin_datetime) = CURDATE() AND NOW() < b.checkout_datetime_calculated)
                    OR
                    (DATE(b.checkin_datetime) > CURDATE())
                    OR
                    (NOW() >= b.checkout_datetime_calculated)
                )
          );
    ");
    $stmtCorrectRoomStatus->execute();

    $stmtSetBooked = $pdo->prepare("
        UPDATE rooms r SET status = 'booked'
        WHERE r.status = 'free' 
          AND EXISTS (
            SELECT 1 FROM bookings b
            WHERE b.room_id = r.id
              AND DATE(b.checkin_datetime) = CURDATE() 
              AND NOW() < b.checkout_datetime_calculated 
          )
          AND NOT EXISTS ( 
              SELECT 1 FROM bookings b_active_check
              WHERE b_active_check.room_id = r.id
                AND (b_active_check.checkin_datetime <= NOW() AND NOW() < b_active_check.checkout_datetime_calculated)
          );
    ");
    $stmtSetBooked->execute();

    $stmtFreeNoShowNonZoneF = $pdo->prepare("
        UPDATE rooms r
        SET r.status = 'free'
        WHERE r.status = 'booked'
          AND r.zone != 'F' 
          AND EXISTS ( 
              SELECT 1 FROM bookings b
              WHERE b.room_id = r.id
                AND DATE(b.checkin_datetime) < CURDATE() 
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
                    (DATE(b_today_active_or_pending.checkin_datetime) = CURDATE() AND NOW() < b_today_active_or_pending.checkout_datetime_calculated)
                    OR
                    (DATE(b_today_active_or_pending.checkin_datetime) > CURDATE())
                )
          );
    ");
    $stmtFreeNoShowNonZoneF->execute();

    $pdo->commit();
} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
}
// --- END: Adjusted Status Update Logic ---

$bookedCount = $pdo->query("SELECT COUNT(*) FROM rooms WHERE status='booked'")->fetchColumn();
$occupiedCount = $pdo->query("SELECT COUNT(*) FROM rooms WHERE status='occupied'")->fetchColumn();
$freeCount = $pdo->query("SELECT COUNT(*) FROM rooms WHERE status='free'")->fetchColumn();

$stmtTodayOccupancy = $pdo->query(
    "SELECT COUNT(DISTINCT b.room_id) FROM bookings b
     WHERE b.checkin_datetime <= NOW()
       AND NOW() < b.checkout_datetime_calculated"
);
$todayOccupancyCount = $stmtTodayOccupancy->fetchColumn();

$viewMode = trim($_GET['view'] ?? 'grid');
if (!in_array($viewMode, ['grid', 'table'])) {
    $viewMode = 'grid';
}

// +++ START: REFACTORED V2 - PERFORMANCE FIX 1.1 +++
if (!function_exists('fetchRoomStatuses')) {
    die("Critical error: fetchRoomStatuses() function not found. Please check bootstrap.php.");
}
try {
    $roomsData = fetchRoomStatuses($pdo);
} catch (PDOException $e) {
    error_log("Failed to fetch room statuses on index.php: " . $e->getMessage());
    $roomsData = [];
}
// +++ END: REFACTORED V2 +++

$groupedRooms = ['นั้งกินนอนฟิน' => [], 'ภัทรรีสอร์ท' => []];
foreach ($roomsData as $room) {
    if (in_array($room['zone'], ['A', 'B', 'C'])) {
        $groupedRooms['นั้งกินนอนฟิน'][] = $room;
    } elseif ($room['zone'] === 'F') {
        $groupedRooms['ภัทรรีสอร์ท'][] = $room;
    } else {
        if (!isset($groupedRooms['โซน ' . $room['zone']])) {
            $groupedRooms['โซน ' . $room['zone']] = [];
        }
        $groupedRooms['โซน ' . $room['zone']][] = $room;
    }
}

$advBookingsQuery = $pdo->prepare("SELECT b.id, r.zone, r.room_number, r.id as room_id_for_link, b.customer_name, b.receipt_path, DATE_FORMAT(b.checkin_datetime, '%e %b %Y, %H:%i น.') AS checkin_datetime_formatted, DATE_FORMAT(b.checkout_datetime_calculated, '%e %b %Y, %H:%i น.') AS checkout_datetime_formatted, b.nights, b.booking_type, r.short_stay_duration_hours, b.booking_group_id FROM bookings b JOIN rooms r ON b.room_id = r.id WHERE b.checkin_datetime > NOW() ORDER BY b.checkin_datetime ASC, r.zone ASC, CAST(r.room_number AS UNSIGNED) ASC");
$advBookingsQuery->execute();
$advBookings = $advBookingsQuery->fetchAll(PDO::FETCH_ASSOC);

$customerSearchTerm = trim($_GET['customer_search'] ?? '');
$searchedBookings = [];
if (!empty($customerSearchTerm)) {
    $searchStmt = $pdo->prepare("SELECT b.id AS booking_id, r.id as room_id, r.zone, r.room_number, b.customer_name, b.customer_phone, DATE_FORMAT(b.checkin_datetime, '%e %b %Y, %H:%i น.') AS checkin_datetime_formatted, DATE_FORMAT(b.checkout_datetime_calculated, '%e %b %Y, %H:%i น.') AS checkout_datetime_formatted, b.booking_type, r.short_stay_duration_hours, b.nights, CASE WHEN b.checkin_datetime <= NOW() AND NOW() < b.checkout_datetime_calculated THEN 'กำลังเข้าพัก' WHEN DATE(b.checkin_datetime) = CURDATE() AND NOW() < b.checkin_datetime THEN 'รอเช็คอินวันนี้' WHEN DATE(b.checkin_datetime) > CURDATE() THEN 'จองล่วงหน้า' WHEN b.checkout_datetime_calculated <= NOW() THEN 'เช็คเอาท์แล้ว (รอเก็บเข้าประวัติ)' ELSE 'อื่นๆ' END AS booking_status_display FROM bookings b JOIN rooms r ON b.room_id = r.id WHERE b.customer_name LIKE :searchTerm ORDER BY b.checkin_datetime DESC LIMIT 50");
    $searchStmt->execute([':searchTerm' => '%' . $customerSearchTerm . '%']);
    $searchedBookings = $searchStmt->fetchAll(PDO::FETCH_ASSOC);
}

ob_start();
?>

<div class="dashboard-stats" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1.5rem; margin-bottom: 2.5rem;">
    <!-- Wait for Checkin -->
    <div class="stat-box glass-card" style="display: flex; flex-direction: column; justify-content: space-between; position: relative; overflow: hidden; padding: 1.5rem; background: linear-gradient(135deg, rgba(245, 158, 11, 0.1), rgba(245, 158, 11, 0.05)); border-color: rgba(245, 158, 11, 0.2);">
        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 1rem;">
            <h3 style="margin: 0; font-size: 0.95rem; font-weight: 600; color: var(--color-warning-dark);">รอเช็กอิน (วันนี้)</h3>
            <div style="background: rgba(245, 158, 11, 0.2); padding: 0.5rem; border-radius: 0.5rem; color: var(--color-warning-dark);">
                <i class="fa-solid fa-clock"></i>
            </div>
        </div>
        <p class="number-font" style="font-size: 2.5rem; font-weight: 700; color: var(--color-text); margin: 0; line-height: 1;"><?= h($bookedCount) ?></p>
        <div style="position: absolute; right: -15px; bottom: -15px; opacity: 0.1; font-size: 5rem; color: var(--color-warning-dark);"><i class="fa-solid fa-clock"></i></div>
    </div>

    <!-- Occupied -->
    <div class="stat-box glass-card" style="display: flex; flex-direction: column; justify-content: space-between; position: relative; overflow: hidden; padding: 1.5rem; background: linear-gradient(135deg, rgba(239, 68, 68, 0.1), rgba(239, 68, 68, 0.05)); border-color: rgba(239, 68, 68, 0.2);">
        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 1rem;">
            <h3 style="margin: 0; font-size: 0.95rem; font-weight: 600; color: var(--color-alert-dark);">ห้องไม่ว่าง (Occupied)</h3>
            <div style="background: rgba(239, 68, 68, 0.2); padding: 0.5rem; border-radius: 0.5rem; color: var(--color-alert-dark);">
                <i class="fa-solid fa-bed"></i>
            </div>
        </div>
        <p class="number-font" style="font-size: 2.5rem; font-weight: 700; color: var(--color-text); margin: 0; line-height: 1;"><?= h($occupiedCount) ?></p>
        <div style="position: absolute; right: -15px; bottom: -15px; opacity: 0.1; font-size: 5rem; color: var(--color-alert-dark);"><i class="fa-solid fa-bed"></i></div>
    </div>

    <!-- Free -->
    <div class="stat-box glass-card" style="display: flex; flex-direction: column; justify-content: space-between; position: relative; overflow: hidden; padding: 1.5rem; background: linear-gradient(135deg, rgba(16, 185, 129, 0.1), rgba(16, 185, 129, 0.05)); border-color: rgba(16, 185, 129, 0.2);">
        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 1rem;">
            <h3 style="margin: 0; font-size: 0.95rem; font-weight: 600; color: var(--color-success);">ห้องว่าง (Free)</h3>
            <div style="background: rgba(16, 185, 129, 0.2); padding: 0.5rem; border-radius: 0.5rem; color: var(--color-success);">
                <i class="fa-solid fa-door-open"></i>
            </div>
        </div>
        <p class="number-font" style="font-size: 2.5rem; font-weight: 700; color: var(--color-text); margin: 0; line-height: 1;"><?= h($freeCount) ?></p>
        <div style="position: absolute; right: -15px; bottom: -15px; opacity: 0.1; font-size: 5rem; color: var(--color-success);"><i class="fa-solid fa-door-open"></i></div>
    </div>

    <!-- Active Stays (Unique Bookings) -->
    <div class="stat-box glass-card" style="display: flex; flex-direction: column; justify-content: space-between; position: relative; overflow: hidden; padding: 1.5rem; background: linear-gradient(135deg, rgba(79, 70, 229, 0.1), rgba(79, 70, 229, 0.05)); border-color: rgba(79, 70, 229, 0.2);">
        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 1rem;">
            <h3 style="margin: 0; font-size: 0.95rem; font-weight: 600; color: var(--color-primary-dark);">กำลังเข้าพัก (Active Stays)</h3>
            <div style="background: rgba(79, 70, 229, 0.2); padding: 0.5rem; border-radius: 0.5rem; color: var(--color-primary-dark);">
                <i class="fa-solid fa-users"></i>
            </div>
        </div>
        <p class="number-font" style="font-size: 2.5rem; font-weight: 700; color: var(--color-text); margin: 0; line-height: 1;"><?= h($todayOccupancyCount) ?></p>
        <div style="position: absolute; right: -15px; bottom: -15px; opacity: 0.1; font-size: 5rem; color: var(--color-primary-dark);"><i class="fa-solid fa-users"></i></div>
    </div>
</div>

<div class="dashboard-toolbar" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem; flex-wrap: wrap; gap: 15px; background: var(--color-surface); padding: 1rem 1.5rem; border-radius: 1rem; border: 1px solid var(--color-border); box-shadow: var(--shadow-sm);">
    <div style="display: flex; gap: 10px; flex-wrap: wrap; align-items: center;">
        <a href="booking.php?mode=multi" class="button" style="background: linear-gradient(135deg, var(--color-primary), var(--color-purple)); color: white; border: none; padding: 0.6rem 1.2rem; border-radius: 0.5rem; font-weight: 600; display: inline-flex; align-items: center; gap: 8px; text-decoration: none; box-shadow: 0 4px 10px rgba(79, 70, 229, 0.3);">
            <i class="fa-solid fa-layer-group"></i> จองหลายห้อง/จองกลุ่ม
        </a>
        <button id="share-dashboard-btn" class="button outline-secondary" style="display: inline-flex; align-items: center; gap: 8px; padding: 0.6rem 1.2rem; border-radius: 0.5rem;">
            <i class="fa-solid fa-share-nodes"></i> แชร์ภาพรวม
        </button>
        <a href="/hotel_booking/pages/cash_bill.php" class="button outline-secondary" style="display: inline-flex; align-items: center; gap: 8px; padding: 0.6rem 1.2rem; border-radius: 0.5rem;">
            <i class="fa-solid fa-receipt"></i> ระบบบิลเงินสด
        </a>
        <!-- START: Multi-Select Mode Toggle & Bulk Action Buttons -->
        <button id="toggle-select-mode-btn" class="button outline-secondary" style="display: inline-flex; align-items: center; gap: 8px; padding: 0.6rem 1.2rem; border-radius: 0.5rem;">
            <i class="fa-solid fa-check-square"></i> เลือกหลายห้อง
        </button>
        <button id="bulk-cancel-btn" class="button alert" style="display: none; align-items: center; gap: 8px; padding: 0.6rem 1.2rem; border-radius: 0.5rem; background: var(--color-error);">
            <i class="fa-solid fa-ban"></i> ยกเลิก/ลบ (<span id="bulk-cancel-count">0</span>)
        </button>
        <button id="bulk-checkout-btn" class="button success" style="display: none; align-items: center; gap: 8px; padding: 0.6rem 1.2rem; border-radius: 0.5rem;">
            <i class="fa-solid fa-money-bill-wave"></i> คืนมัดจำ/เช็คเอาท์ (<span id="bulk-selected-count">0</span>)
        </button>
        <!-- END: Multi-Select Mode Toggle & Bulk Action Buttons -->
    </div>
    <div style="display: flex; align-items: center; gap: 10px; background: var(--color-bg); padding: 0.3rem; border-radius: 0.5rem; border: 1px solid var(--color-border);">
        <?php $isTableView = ($viewMode === 'table'); ?>
        <a href="?view=grid" style="padding: 0.4rem 1rem; border-radius: 0.3rem; text-decoration: none; font-size: 0.9rem; font-weight: 500; color: <?= !$isTableView ? 'var(--color-primary)' : 'var(--color-text-muted)' ?>; background: <?= !$isTableView ? 'var(--color-surface)' : 'transparent' ?>; box-shadow: <?= !$isTableView ? 'var(--shadow-sm)' : 'none' ?>; transition: all 0.2s;">
            <i class="fa-solid fa-grip" style="margin-right: 5px;"></i> Grid
        </a>
        <a href="?view=table" style="padding: 0.4rem 1rem; border-radius: 0.3rem; text-decoration: none; font-size: 0.9rem; font-weight: 500; color: <?= $isTableView ? 'var(--color-primary)' : 'var(--color-text-muted)' ?>; background: <?= $isTableView ? 'var(--color-surface)' : 'transparent' ?>; box-shadow: <?= $isTableView ? 'var(--shadow-sm)' : 'none' ?>; transition: all 0.2s;">
            <i class="fa-solid fa-list" style="margin-right: 5px;"></i> List
        </a>
    </div>
</div>

<form method="GET" action="index.php" class="report-filter" style="background: var(--color-surface); padding: 1.5rem; border-radius: 1rem; border: 1px solid var(--color-border); box-shadow: var(--shadow-sm); margin-bottom: 2rem;">
    <div class="filter-group" style="display: flex; gap: 1rem; flex-wrap: wrap; align-items: flex-end;">
        <div style="flex-grow: 1; min-width: 250px;">
            <label for="customer_search" style="display: block; margin-bottom: 0.5rem; font-weight: 500; color: var(--color-text-muted);"><i class="fa-solid fa-magnifying-glass"></i> ค้นหาชื่อผู้จอง (ปัจจุบันและอนาคต):</label>
            <input type="text" name="customer_search" id="customer_search" value="<?= h($customerSearchTerm) ?>" placeholder="พิมพ์ชื่อลูกค้า..." style="width: 100%; padding: 0.75rem 1rem; border: 1px solid var(--color-border); border-radius: 0.5rem; background: var(--color-bg); font-family: 'Prompt', sans-serif;">
        </div>
        <button type="submit" class="button primary" style="padding: 0.75rem 1.5rem; border-radius: 0.5rem;">ค้นหา</button>
        <?php if (!empty($customerSearchTerm)): ?>
            <?php $clearSearchParams = ['view' => $viewMode]; ?>
            <a href="index.php?<?= http_build_query($clearSearchParams) ?>" class="button secondary" style="padding: 0.75rem 1.5rem; border-radius: 0.5rem;">ล้าง</a>
        <?php endif; ?>
    </div>
    <input type="hidden" name="view" id="search_view_mode_input" value="<?= h($viewMode) ?>">
</form>

<section class="search-results-section report-section">
    <?php if (!empty($customerSearchTerm)): ?>
        <h3 style="font-size: 1.2rem; margin-bottom: 1rem; color: var(--color-text);">ผลการค้นหาสำหรับ "<?= h($customerSearchTerm) ?>"</h3>
    <?php endif; ?>
    <?php if (!empty($searchedBookings)): ?>
        <div class="table-responsive glass-card" style="padding: 0; margin-bottom: 2rem;">
            <table class="report-table modern-table" style="margin: 0;">
                <thead style="background: var(--color-table-head-bg);">
                    <tr>
                        <th style="padding: 1rem; border-bottom: 1px solid var(--color-border); font-weight: 600; color: var(--color-text-muted);">ID จอง</th>
                        <th style="padding: 1rem; border-bottom: 1px solid var(--color-border); font-weight: 600; color: var(--color-text-muted);">ห้อง</th>
                        <th style="padding: 1rem; border-bottom: 1px solid var(--color-border); font-weight: 600; color: var(--color-text-muted);">ชื่อลูกค้า</th>
                        <th style="padding: 1rem; border-bottom: 1px solid var(--color-border); font-weight: 600; color: var(--color-text-muted);">เบอร์โทร</th>
                        <th style="padding: 1rem; border-bottom: 1px solid var(--color-border); font-weight: 600; color: var(--color-text-muted);">เช็คอิน</th>
                        <th style="padding: 1rem; border-bottom: 1px solid var(--color-border); font-weight: 600; color: var(--color-text-muted);">เช็คเอาท์</th>
                        <th style="padding: 1rem; border-bottom: 1px solid var(--color-border); font-weight: 600; color: var(--color-text-muted);">ประเภท</th>
                        <th style="padding: 1rem; border-bottom: 1px solid var(--color-border); font-weight: 600; color: var(--color-text-muted);">ระยะเวลา</th>
                        <th style="padding: 1rem; border-bottom: 1px solid var(--color-border); font-weight: 600; color: var(--color-text-muted);">สถานะ</th>
                        <th style="padding: 1rem; border-bottom: 1px solid var(--color-border); font-weight: 600; color: var(--color-text-muted);">ดำเนินการ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($searchedBookings as $sBooking): ?>
                        <tr style="border-bottom: 1px solid var(--color-border); transition: background 0.2s;">
                            <td class="px-6 py-4 whitespace-nowrap text-sm number-font" style="color: var(--color-text-muted);"><?= h($sBooking['booking_id']) ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-semibold" style="color: var(--color-text);"><?= h($sBooking['zone'] . $sBooking['room_number']) ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm" style="color: var(--color-text);"><?= h($sBooking['customer_name']) ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm number-font" style="color: var(--color-text-muted);"><?= h($sBooking['customer_phone'] ?? '-') ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm number-font" style="color: var(--color-text-muted);"><?= h($sBooking['checkin_datetime_formatted']) ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm number-font" style="color: var(--color-text-muted);"><?= h($sBooking['checkout_datetime_formatted']) ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm" style="color: var(--color-text-muted);"><?= h($sBooking['booking_type'] === 'short_stay' ? 'ชั่วคราว' : 'ค้างคืน') ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm" style="color: var(--color-text-muted);"><?= h($sBooking['booking_type'] === 'short_stay' ? ($sBooking['short_stay_duration_hours'] . ' ชม.') : ($sBooking['nights'] . ' คืน')) ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm">
                                <span style="background: var(--color-surface-alt); padding: 0.3rem 0.6rem; border-radius: 1rem; font-size: 0.8rem; font-weight: 500;"><?= h($sBooking['booking_status_display']) ?></span>
                            </td>
                            <td class="actions-cell px-6 py-4" style="display: flex; gap: 8px;">
                                <button class="button outline-secondary button-small" style="padding: 0.4rem 0.8rem; border-radius: 0.4rem; font-size: 0.85rem;" data-id="<?= h($sBooking['room_id']) ?>" data-booking-id="<?= h($sBooking['booking_id']) ?>">ดูห้อง</button>
                                <a href="booking.php?edit_booking_id=<?= h($sBooking['booking_id']) ?>" class="button outline-primary button-small" style="padding: 0.4rem 0.8rem; border-radius: 0.4rem; font-size: 0.85rem;">แก้ไข</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php elseif (!empty($customerSearchTerm)): ?>
        <p style="color: var(--color-text-muted); padding: 1rem; background: var(--color-surface); border-radius: 0.5rem; text-align: center; border: 1px dashed var(--color-border);"><i class="fa-solid fa-inbox" style="display: block; font-size: 2rem; margin-bottom: 0.5rem; opacity: 0.5;"></i>ไม่พบข้อมูลการจองสำหรับชื่อนี้</p>
    <?php endif; ?>
</section>

<!-- Legend (Simplified and Clean) -->
<div class="status-container" style="display: flex; flex-wrap: wrap; gap: 1rem; margin-bottom: 1.5rem; background: var(--color-surface); padding: 1rem 1.5rem; border-radius: 1rem; border: 1px solid var(--color-border);">
    <div style="display: flex; align-items: center; gap: 0.5rem; font-size: 0.9rem; font-weight: 500;">
        <div style="width: 12px; height: 12px; border-radius: 50%; background-color: var(--status-overdue-bg);"></div> เกินกำหนด (Overdue)
    </div>
    <div style="display: flex; align-items: center; gap: 0.5rem; font-size: 0.9rem; font-weight: 500;">
        <div style="width: 12px; height: 12px; border-radius: 50%; background-color: var(--status-occupied-bg);"></div> ไม่ว่าง (Occupied)
    </div>
    <div style="display: flex; align-items: center; gap: 0.5rem; font-size: 0.9rem; font-weight: 500;">
        <div style="width: 12px; height: 12px; border-radius: 50%; background-color: var(--status-booked-bg);"></div> รอเข้าพัก (Booked)
    </div>
    <div style="display: flex; align-items: center; gap: 0.5rem; font-size: 0.9rem; font-weight: 500;">
        <div style="width: 12px; height: 12px; border-radius: 50%; background-color: var(--status-free-bg);"></div> ว่าง (Free)
    </div>
    <div style="display: flex; align-items: center; gap: 0.5rem; font-size: 0.9rem; font-weight: 500;">
        <div style="width: 12px; height: 12px; border-radius: 50%; background-color: var(--status-advance_booking-bg);"></div> จองล่วงหน้าพรุ่งนี้ (Advance)
    </div>
    <div style="display: flex; align-items: center; gap: 0.5rem; font-size: 0.9rem; font-weight: 500;">
        <div style="width: 12px; height: 12px; border-radius: 50%; background-color: var(--status-zone_f-bg);"></div> โซน F
    </div>
</div>

<?php if ($viewMode === 'grid'): ?>
    <?php foreach ($groupedRooms as $groupName => $roomsInGroup): ?>
        <?php if (!empty($roomsInGroup)): ?>
            <h3 class="group-header" style="font-size: 1.5rem; font-weight: 700; color: var(--color-text); margin-top: 2rem; margin-bottom: 1rem; display: flex; align-items: center; gap: 10px;">
                <i class="fa-solid fa-layer-group" style="color: var(--color-primary); font-size: 1.2rem;"></i> <?= h($groupName) ?>
            </h3>
            <!-- Modern CSS Grid for Rooms -->
            <div class="rooms-grid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(130px, 1fr)); gap: 1.2rem; margin-bottom: 2rem;">
                <?php foreach ($roomsInGroup as $r): ?>
                    <?php
                    // Determine background color based on status
                    $bgColor = 'var(--status-default-bg)';
                    $textColor = '#fff';
                    switch ($r['display_status']) {
                        case 'free':
                            $bgColor = 'var(--status-free-bg)';
                            break;
                        case 'booked':
                            $bgColor = 'var(--status-booked-bg)';
                            $textColor = '#1e293b';
                            break;
                        case 'occupied':
                            $bgColor = 'var(--status-occupied-bg)';
                            break;
                        case 'overdue_occupied':
                            $bgColor = 'var(--status-overdue-bg)';
                            break;
                        case 'advance_booking':
                            $bgColor = 'var(--status-advance_booking-bg)';
                            break;
                        case 'f_short_occupied':
                            $bgColor = 'var(--status-zone_f-bg)';
                            break;
                    }
                    ?>
                    <div class="room-card room <?= h($r['display_status']) ?> <?= ($r['is_overdue'] ?? 0) ? 'has-overdue' : '' ?>"
                        data-id="<?= h($r['id']) ?>"
                        data-status="<?= h($r['display_status']) ?>"
                        <?php if (!empty($r['relevant_booking_id']) && !in_array($r['display_status'], ['occupied', 'booked', 'overdue_occupied', 'f_short_occupied'])): ?>
                        data-booking-id="<?= h($r['relevant_booking_id']) ?>"
                        <?php elseif (!empty($r['current_booking_id'])): ?>
                        data-booking-id="<?= h($r['current_booking_id']) ?>"
                        <?php endif; ?>
                        style="background-color: <?= $bgColor ?>; color: <?= $textColor ?>; border-radius: 1rem; padding: 1.2rem; position: relative; cursor: pointer; transition: transform 0.2s, box-shadow 0.2s; box-shadow: var(--shadow-sm); display: flex; flex-direction: column; align-items: center; justify-content: center; aspect-ratio: 1; border: 2px solid transparent; overflow: hidden;">

                        <!-- Optional Checkbox for Bulk Actions -->
                        <?php if (!empty($r['current_booking_id'])): ?>
                            <input type="checkbox" class="room-select-checkbox" data-booking-id="<?= h($r['current_booking_id']) ?>" data-room-name="<?= h($r['zone'] . $r['room_number']) ?>" style="position: absolute; top: 10px; left: 10px; z-index: 10; cursor: pointer; transform: scale(1.2);">
                        <?php endif; ?>

                        <!-- Room Number -->
                        <div class="number-font" style="font-size: 1.8rem; font-weight: 800; z-index: 2; text-shadow: 0px 2px 4px rgba(0,0,0,0.1); margin-bottom: 0.2rem; pointer-events: none;">
                            <?= h($r['zone'] . $r['room_number']) ?>
                        </div>

                        <!-- Status Badge (Optional small badge) -->
                        <!-- <span style="font-size: 0.70rem; font-weight: 600; padding: 0.1rem 0.5rem; background: rgba(255,255,255,0.2); border-radius: 1rem; backdrop-filter: blur(4px); z-index: 2; pointer-events: none;"> -->
                        <!-- <?= h(strtoupper(str_replace('_', ' ', $r['display_status']))) ?> -->
                        <!-- </span> -->

                        <!-- Indicators -->
                        <div style="position: absolute; bottom: 10px; display: flex; gap: 5px; z-index: 2;">
                            <?php if ($r['is_overdue'] ?? 0): ?>
                                <span title="เกินกำหนด" style="background: white; border-radius: 50%; width: 20px; height: 20px; display: flex; align-items: center; justify-content: center; font-size: 0.8rem; box-shadow: var(--shadow-sm);">⚠️</span>
                            <?php endif; ?>
                            <?php if (($r['has_pending_payment_dashboard'] ?? 0) && in_array($r['display_status'], ['occupied', 'booked', 'f_short_occupied', 'overdue_occupied'])): ?>
                                <span title="ค้างชำระ" style="background: white; border-radius: 50%; width: 20px; height: 20px; display: flex; align-items: center; justify-content: center; font-size: 0.8rem; box-shadow: var(--shadow-sm);">💰</span>
                            <?php endif; ?>
                        </div>

                        <!-- Hover Overlay (Added via JS class or CSS below) -->
                    </div>
                <?php endforeach; ?>
            </div>
            <!-- Inline styles for room-card hover effects -->
            <style>
                .room-card:hover {
                    transform: translateY(-4px);
                    box-shadow: var(--shadow-lg);
                }

                .room-card::after {
                    content: '';
                    position: absolute;
                    inset: 0;
                    background: linear-gradient(180deg, rgba(255, 255, 255, 0.2) 0%, rgba(0, 0, 0, 0) 100%);
                    pointer-events: none;
                }

                .room-card.has-overdue {
                    border-color: #ffffff;
                    animation: pulse-border 2s infinite;
                }

                .room-select-checkbox {
                    display: none;
                    /* Hidden by default, shown in select-mode */
                }

                body.select-mode .room-select-checkbox {
                    display: block;
                }

                @keyframes pulse-border {
                    0% {
                        box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.4);
                    }

                    70% {
                        box-shadow: 0 0 0 10px rgba(239, 68, 68, 0);
                    }

                    100% {
                        box-shadow: 0 0 0 0 rgba(239, 68, 68, 0);
                    }
                }
            </style>
        <?php endif; ?>
    <?php endforeach; ?>
<?php elseif ($viewMode === 'table'): ?>
    <section class="report-section mt-8">
        <h3 style="font-size: 1.5rem; font-weight: 700; color: var(--color-text); margin-top: 2rem; margin-bottom: 1rem; display: flex; align-items: center; gap: 10px;">
            <i class="fa-solid fa-list" style="color: var(--color-primary); font-size: 1.2rem;"></i> ภาพรวมห้องพักวันนี้ (มุมมองตาราง)
        </h3>
        <div id="group-action-toolbar" style="padding: 0.5rem 0; text-align: right; display: none; margin-bottom: 1rem;">
            <button id="group-selected-bookings-btn" class="button secondary" style="border-radius: 0.5rem; padding: 0.5rem 1rem;">
                <i class="fa-solid fa-object-group"></i> จัดกลุ่มที่เลือก (<span id="selected-booking-count">0</span>)
            </button>
        </div>
        <div class="table-responsive glass-card" style="padding: 0; margin-bottom: 2rem;">
            <table class="report-table modern-table" id="room-status-table-view" style="margin: 0; width: 100%;">
                <thead style="background: var(--color-table-head-bg);">
                    <tr>
                        <th style="padding: 1rem; border-bottom: 1px solid var(--color-border); text-align: center;">
                            <input type="checkbox" id="select-all-bookings-checkbox" title="เลือกทั้งหมด" style="cursor: pointer; transform: scale(1.2);">
                        </th>
                        <th style="padding: 1rem; border-bottom: 1px solid var(--color-border); font-weight: 600; color: var(--color-text-muted);">ห้อง</th>
                        <th style="padding: 1rem; border-bottom: 1px solid var(--color-border); font-weight: 600; color: var(--color-text-muted);">สถานะ</th>
                        <th style="padding: 1rem; border-bottom: 1px solid var(--color-border); font-weight: 600; color: var(--color-text-muted);">ผู้เข้าพัก/รอเช็คอิน</th>
                        <th style="padding: 1rem; border-bottom: 1px solid var(--color-border); font-weight: 600; color: var(--color-text-muted);">เบอร์โทร</th>
                        <th style="padding: 1rem; border-bottom: 1px solid var(--color-border); font-weight: 600; color: var(--color-text-muted);">เช็คอิน</th>
                        <th style="padding: 1rem; border-bottom: 1px solid var(--color-border); font-weight: 600; color: var(--color-text-muted);">เช็คเอาท์</th>
                        <th style="padding: 1rem; border-bottom: 1px solid var(--color-border); font-weight: 600; color: var(--color-text-muted);">ประเภท</th>
                        <th style="padding: 1rem; border-bottom: 1px solid var(--color-border); font-weight: 600; color: var(--color-text-muted);">ระยะเวลา</th>
                        <th style="padding: 1rem; border-bottom: 1px solid var(--color-border); font-weight: 600; color: var(--color-text-muted);">สลิป</th>
                        <th style="padding: 1rem; border-bottom: 1px solid var(--color-border); font-weight: 600; color: var(--color-text-muted); min-width: 200px;">ดำเนินการ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($roomsData)): ?>
                        <tr>
                            <td colspan="11" style="padding: 2rem; text-align: center; color: var(--color-text-muted); font-style: italic;">ไม่พบข้อมูลห้องพัก</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($roomsData as $room): ?>
                            <tr class="room-row-status-<?= h($room['display_status']) ?> <?= ($room['is_overdue'] ?? 0) ? 'has-overdue-indicator' : '' ?>" style="border-bottom: 1px solid var(--color-border); transition: background 0.2s;">
                                <td style="padding: 1rem; text-align: center;">
                                    <?php if (!empty($room['current_booking_id'])): ?>
                                        <input type="checkbox" class="room-select-checkbox booking-group-checkbox"
                                            data-booking-id="<?= h($room['current_booking_id']) ?>"
                                            data-room-name="<?= h($room['zone'] . $room['room_number']) ?>" style="cursor: pointer; transform: scale(1.2);">
                                    <?php endif; ?>
                                </td>
                                <td class="room-name-cell number-font" data-room-id-cell="<?= h($room['id']) ?>" style="padding: 1rem; font-weight: 700; color: var(--color-text); font-size: 1.1rem;">
                                    <?= h($room['zone'] . $room['room_number']) ?>
                                    <?php if ($room['is_overdue'] ?? 0): ?>
                                        <span class="overdue-indicator-table" title="การจองนี้เกินกำหนดเวลาเช็คเอาท์แล้ว" style="margin-left: 4px;">⚠️</span>
                                    <?php endif; ?>
                                    <?php if ($room['is_nearing_checkout_dashboard'] ?? 0): ?>
                                        <span class="nearing-checkout-indicator-table" style="color: var(--color-warning); margin-left: 4px;" title="ใกล้หมดเวลาเช็คเอาท์!"><i class="fa-solid fa-clock-rotate-left"></i></span>
                                    <?php endif; ?>
                                    <?php if ($room['has_pending_payment_dashboard'] ?? 0): ?>
                                        <span class="pending-payment-indicator-table" style="color: var(--color-success); margin-left: 4px;" title="มียอดค้างชำระ!">💰</span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 1rem;">
                                    <?php
                                    $statusBg = 'var(--color-surface-alt)';
                                    $statusText = 'var(--color-text)';
                                    switch ($room['display_status']) {
                                        case 'overdue_occupied':
                                            $statusBg = 'var(--status-overdue-bg)';
                                            $statusText = 'white';
                                            break;
                                        case 'occupied':
                                            $statusBg = 'var(--status-occupied-bg)';
                                            $statusText = 'white';
                                            break;
                                        case 'booked':
                                            $statusBg = 'var(--status-booked-bg)';
                                            $statusText = '#1e293b';
                                            break;
                                        case 'free':
                                            $statusBg = 'var(--status-free-bg)';
                                            $statusText = 'white';
                                            break;
                                        case 'advance_booking':
                                            $statusBg = 'var(--status-advance_booking-bg)';
                                            $statusText = 'white';
                                            break;
                                        case 'f_short_occupied':
                                            $statusBg = 'var(--status-zone_f-bg)';
                                            $statusText = 'white';
                                            break;
                                    }
                                    ?>
                                    <span class="status-indicator status-<?= h($room['display_status']) ?>" style="background-color: <?= $statusBg ?>; color: <?= $statusText ?>; padding: 0.3rem 0.8rem; border-radius: 1rem; font-size: 0.8rem; font-weight: 600; display: inline-block;">
                                        <?= h(ucfirst(str_replace(['_', 'f short '], [' ', 'F ชั่วคราว '], $room['display_status']))) ?>
                                    </span>
                                </td>
                                <?php if (!empty($room['current_customer_name']) && in_array($room['display_status'], ['occupied', 'booked', 'f_short_occupied', 'overdue_occupied'])): ?>
                                    <td style="padding: 1rem; color: var(--color-text); font-weight: 500;"><?= h($room['current_customer_name']) ?></td>
                                    <td class="number-font" style="padding: 1rem; color: var(--color-text-muted);">
                                        <?php if (!empty($room['current_customer_phone'])): ?>
                                            <a href="tel:<?= h(preg_replace('/[^0-9+]/', '', $room['current_customer_phone'])) ?>" style="color: var(--color-primary); text-decoration: none;"><?= h($room['current_customer_phone']) ?></a>
                                        <?php else: echo '-';
                                        endif; ?>
                                    </td>
                                    <td class="number-font" style="padding: 1rem; color: var(--color-text-muted); font-size: 0.9rem;"><?= h($room['current_formatted_checkin']) ?></td>
                                    <td class="number-font" style="padding: 1rem; color: var(--color-text-muted); font-size: 0.9rem;"><?= h($room['current_formatted_checkout']) ?></td>
                                    <td style="padding: 1rem; color: var(--color-text-muted);"><?= h($room['current_booking_type'] === 'short_stay' ? 'ชั่วคราว' : 'ค้างคืน') ?></td>
                                    <td style="padding: 1rem; color: var(--color-text-muted); font-weight: 500;"><?= h($room['current_booking_type'] === 'short_stay' ? ($room['current_short_stay_duration'] . ' ชม.') : ($room['current_nights'] . ' คืน')) ?></td>
                                    <td style="padding: 1rem;">
                                        <?php if (!empty($room['current_receipt_path'])): ?>
                                            <img src="/hotel_booking/uploads/receipts/<?= h($room['current_receipt_path']) ?>" alt="สลิป" class="receipt-thumbnail-table receipt-btn-global" style="width: 40px; height: 40px; object-fit: cover; border-radius: 0.4rem; cursor: pointer; box-shadow: var(--shadow-sm); transition: transform 0.2s;" data-src="/hotel_booking/uploads/receipts/<?= h($room['current_receipt_path']) ?>" onmouseover="this.style.transform='scale(1.1)'" onmouseout="this.style.transform='scale(1)'">
                                        <?php else: echo '-';
                                        endif; ?>
                                    </td>
                                <?php else: ?>
                                    <td colspan="7" style="padding: 1rem; text-align: center; color: var(--color-text-muted); font-style: italic;">
                                        <?= match ($room['display_status']) {
                                            'free' => 'ห้องว่าง',
                                            'advance_booking' => 'มีจองล่วงหน้า (สำหรับวันพรุ่งนี้)',
                                            default => 'รอข้อมูล / ยังไม่มีการจองสำหรับวันนี้'
                                        }; ?>
                                    </td>
                                <?php endif; ?>
                                <td class="actions-cell" style="padding: 1rem; display: flex; gap: 8px; flex-wrap: wrap;">
                                    <button class="button outline-secondary button-small room" style="padding: 0.4rem 0.8rem; border-radius: 0.4rem; font-size: 0.8rem;" data-id="<?= h($room['id']) ?>" <?php if (!empty($room['current_booking_id'])): ?>data-booking-id="<?= h($room['current_booking_id']) ?>" <?php elseif (!empty($room['relevant_booking_id'])): ?>data-booking-id="<?= h($room['relevant_booking_id']) ?>" <?php endif; ?>>ดูห้อง</button>
                                    <?php if ($room['display_status'] === 'booked' && !empty($room['current_booking_id'])): ?>
                                        <button class="button success button-small occupy-btn-table" style="padding: 0.4rem 0.8rem; border-radius: 0.4rem; font-size: 0.8rem;" data-booking-id="<?= h($room['current_booking_id']) ?>" id="occupy-tbl-<?= h($room['current_booking_id']) ?>">เช็คอิน</button>
                                    <?php endif; ?>
                                    <?php if (!empty($room['current_booking_id']) && in_array($room['display_status'], ['occupied', 'booked', 'f_short_occupied', 'overdue_occupied'])): ?>
                                        <?php if (!empty($room['current_booking_group_id'])): ?>
                                            <a href="edit_booking_group.php?booking_group_id=<?= h($room['current_booking_group_id']) ?>" class="button warning button-small" style="padding: 0.4rem 0.8rem; border-radius: 0.4rem; font-size: 0.8rem;">แก้ไขกลุ่ม</a>
                                        <?php endif; ?>
                                        <a href="booking.php?edit_booking_id=<?= h($room['current_booking_id']) ?>" class="button primary button-small edit-booking-btn" style="padding: 0.4rem 0.8rem; border-radius: 0.4rem; font-size: 0.8rem;">แก้ไข</a>
                                    <?php elseif ($room['display_status'] === 'free' || $room['display_status'] === 'advance_booking'): ?>
                                        <a href="booking.php?room_id=<?= h($room['id']) ?>" class="button success button-small" style="padding: 0.4rem 0.8rem; border-radius: 0.4rem; font-size: 0.8rem;">จองห้องนี้</a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <style>
            tr.has-overdue-indicator {
                background-color: rgba(239, 68, 68, 0.05);
                /* very light red */
            }
        </style>
    </section>
<?php endif; ?>

<?php if (!empty($advBookings)): ?>
    <h3 style="font-size: 1.5rem; font-weight: 700; color: var(--color-text); margin-top: 3rem; margin-bottom: 1rem; display: flex; align-items: center; gap: 10px;">
        <i class="fa-solid fa-calendar-days" style="color: var(--color-primary); font-size: 1.2rem;"></i> การจองล่วงหน้าทั้งหมด (All Future Bookings)
    </h3>
    <div class="table-responsive glass-card" style="padding: 0; margin-bottom: 2rem;">
        <table class="report-table modern-table advance-table" style="margin: 0; width: 100%;">
            <thead style="background: var(--color-table-head-bg);">
                <tr>
                    <th style="padding: 1rem; border-bottom: 1px solid var(--color-border); font-weight: 600; color: var(--color-text-muted);">ห้อง</th>
                    <th style="padding: 1rem; border-bottom: 1px solid var(--color-border); font-weight: 600; color: var(--color-text-muted);">ผู้จอง</th>
                    <th style="padding: 1rem; border-bottom: 1px solid var(--color-border); font-weight: 600; color: var(--color-text-muted); text-align: center;">กลุ่ม</th>
                    <th style="padding: 1rem; border-bottom: 1px solid var(--color-border); font-weight: 600; color: var(--color-text-muted);">วันเวลาเช็กอิน</th>
                    <th style="padding: 1rem; border-bottom: 1px solid var(--color-border); font-weight: 600; color: var(--color-text-muted);">วันเวลาเช็กเอาต์</th>
                    <th style="padding: 1rem; border-bottom: 1px solid var(--color-border); font-weight: 600; color: var(--color-text-muted);">ประเภท</th>
                    <th style="padding: 1rem; border-bottom: 1px solid var(--color-border); font-weight: 600; color: var(--color-text-muted);">ระยะเวลา</th>
                    <th style="padding: 1rem; border-bottom: 1px solid var(--color-border); font-weight: 600; color: var(--color-text-muted); min-width: 200px;">การดำเนินการ</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($advBookings as $a): ?>
                    <tr style="border-bottom: 1px solid var(--color-border); transition: background 0.2s;">
                        <td class="number-font" style="padding: 1rem; font-weight: 700; color: var(--color-text);"><?= h($a['zone'] . $a['room_number']) ?></td>
                        <td style="padding: 1rem; font-weight: 500; color: var(--color-text);"><?= h($a['customer_name']) ?></td>
                        <td style="padding: 1rem; text-align: center;">
                            <?php if (!empty($a['booking_group_id'])): ?>
                                <a href="edit_booking_group.php?booking_group_id=<?= h($a['booking_group_id']) ?>" style="background: var(--color-surface-alt); padding: 0.3rem 0.8rem; border-radius: 1rem; font-size: 0.8rem; font-weight: 600; color: var(--color-primary); text-decoration: none;" title="ไปที่หน้าแก้ไขกลุ่ม ID: <?= h($a['booking_group_id']) ?>">กลุ่ม #<?= h($a['booking_group_id']) ?></a>
                            <?php else: ?><span style="color: var(--color-text-muted);">-</span><?php endif; ?>
                        </td>
                        <td class="number-font" style="padding: 1rem; color: var(--color-text-muted); font-size: 0.9rem;"><?= h($a['checkin_datetime_formatted']) ?></td>
                        <td class="number-font" style="padding: 1rem; color: var(--color-text-muted); font-size: 0.9rem;"><?= h($a['checkout_datetime_formatted']) ?></td>
                        <td style="padding: 1rem; color: var(--color-text-muted);"><?= h($a['booking_type'] === 'short_stay' ? 'ชั่วคราว' : 'ค้างคืน') ?></td>
                        <td style="padding: 1rem; color: var(--color-text-muted); font-weight: 500;"><?= h($a['booking_type'] === 'short_stay' ? ($a['short_stay_duration_hours'] . ' ชม.') : ($a['nights'] . ' คืน')) ?></td>
                        <td class="actions-cell" style="padding: 1rem; display: flex; gap: 8px; flex-wrap: wrap;">
                            <?php if (!empty($a['receipt_path'])): ?>
                                <button class="button primary button-small receipt-btn-global" style="padding: 0.4rem 0.8rem; border-radius: 0.4rem; font-size: 0.8rem;" data-src="/hotel_booking/uploads/receipts/<?= h($a['receipt_path']) ?>">ดูสลิป</button>
                            <?php endif; ?>
                            <button class="button outline-secondary button-small room" style="padding: 0.4rem 0.8rem; border-radius: 0.4rem; font-size: 0.8rem;" data-id="<?= h($a['room_id_for_link']) ?>" data-booking-id="<?= h($a['id']) ?>">ดูห้อง</button>
                            <a href="/hotel_booking/pages/booking.php?edit_booking_id=<?= h($a['id']) ?>" class="button outline-primary button-small edit-booking-btn" style="padding: 0.4rem 0.8rem; border-radius: 0.4rem; font-size: 0.8rem;">แก้ไข</a>
                            <button class="button alert button-small delete-booking-btn" style="padding: 0.4rem 0.8rem; border-radius: 0.4rem; font-size: 0.8rem;" data-booking-id="<?= h($a['id']) ?>" id="delete-adv-booking-idx-<?= h($a['id']) ?>"><i class="fa-solid fa-trash-can"></i> ลบ</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php else: ?>
    <div class="glass-card" style="padding: 2.5rem; text-align: center; margin-top: 2rem;">
        <div style="font-size: 2.5rem; color: var(--color-text-muted); opacity: 0.5; margin-bottom: 1rem;"><i class="fa-solid fa-calendar-xmark"></i></div>
        <p style="color: var(--color-text-muted); font-size: 1.1rem; font-weight: 500; margin: 0;">ไม่มีการจองล่วงหน้าในขณะนี้</p>
    </div>
<?php endif; ?>

<div id="modal" class="modal-overlay">
    <div class="modal-content" data-aos="fade-down" data-aos-duration="300">
        <button class="modal-close" aria-label="Close">×</button>
        <div id="modal-body"></div>
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