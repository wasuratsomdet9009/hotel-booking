<?php
// FILEX: hotel_booking/pages/manager_dashboard.php

require_once __DIR__ . '/../bootstrap.php';
require_admin(); // Manager Dashboard is for admins only

$pageTitle = 'Manager Dashboard (Analytics & Operations)';

// --- 1. OPERATIONS DATA ---
// Calculate Occupancy Rate, Total Booked, Total Free, Active Stays today.
$bookedCount = $pdo->query("SELECT COUNT(*) FROM rooms WHERE status='booked'")->fetchColumn();
$occupiedCount = $pdo->query("SELECT COUNT(*) FROM rooms WHERE status='occupied'")->fetchColumn();
$freeCount = $pdo->query("SELECT COUNT(*) FROM rooms WHERE status='free'")->fetchColumn();

// Total rooms for occupancy calculation
$totalRoomsQuery = $pdo->query("SELECT COUNT(*) FROM rooms");
$totalRooms = ($totalRoomsQuery) ? (int)$totalRoomsQuery->fetchColumn() : 0;

$stmtTodayOccupancy = $pdo->query(
    "SELECT COUNT(DISTINCT b.room_id) FROM bookings b
     WHERE b.checkin_datetime <= NOW()
       AND NOW() < b.checkout_datetime_calculated"
);
$todayOccupancyCount = $stmtTodayOccupancy->fetchColumn();

// Calculate Occupancy Rate percentage
$occupancyRate = ($totalRooms > 0) ? (($occupiedCount + $bookedCount) / $totalRooms) * 100 : 0;

// Get Room Statuses for the mini-grid
if (!function_exists('fetchRoomStatuses')) {
    die("Critical error: fetchRoomStatuses() function not found. Please check bootstrap.php.");
}
try {
    $roomsData = fetchRoomStatuses($pdo);
} catch (PDOException $e) {
    error_log("Failed to fetch room statuses on manager_dashboard.php: " . $e->getMessage());
    $roomsData = [];
}

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


// --- 2. ANALYTICS DATA (Simplified for Current Month) ---
$startDateObj = new DateTime(date('Y-m-01'));
$endDateObj = new DateTime(date('Y-m-t'));
$sqlStartDate = $startDateObj->format('Y-m-d 00:00:00');
$sqlEndDate = $endDateObj->format('Y-m-d 23:59:59');

$kpiSql = "SELECT
                SUM(
                    CASE
                        WHEN a.booking_type = 'overnight' THEN (a.amount_paid - IF(a.deposit_returned = 1, a.deposit_amount, 0))
                        ELSE a.amount_paid
                    END
                ) AS total_revenue,
                COUNT(DISTINCT a.id) AS total_stays,
                SUM(CASE WHEN a.booking_type = 'overnight' THEN a.nights ELSE 0 END) AS total_nights
            FROM archives a
            WHERE a.checkin_datetime BETWEEN :start_datetime AND :end_datetime";

$stmtKpi = $pdo->prepare($kpiSql);
$stmtKpi->execute([':start_datetime' => $sqlStartDate, ':end_datetime' => $sqlEndDate]);
$kpiData = $stmtKpi->fetch(PDO::FETCH_ASSOC);

$totalRevenueThisMonth = round((float)($kpiData['total_revenue'] ?? 0));
$totalStaysThisMonth = (int)($kpiData['total_stays'] ?? 0);
$totalNightsThisMonth = (int)($kpiData['total_nights'] ?? 0);


// --- 3. USER MANAGEMENT DATA ---
$users_list_stmt = $pdo->query("SELECT id, username, role, is_active, created_at FROM users ORDER BY username ASC");
$system_users = $users_list_stmt->fetchAll(PDO::FETCH_ASSOC);

ob_start();
?>
<style>
    /* Dashboard Base Layout */
    .manager-dashboard {
        display: flex;
        flex-direction: column;
        gap: 2rem;
        padding-bottom: 3rem;
    }

    /* Premium Header Section */
    .dashboard-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-end;
        margin-bottom: 1rem;
        padding-bottom: 1.5rem;
        border-bottom: 2px solid var(--color-border);
    }

    .dashboard-header .title-area h2 {
        font-size: 2.5rem;
        font-weight: 800;
        margin: 0;
        background: linear-gradient(135deg, var(--color-primary-dark), #4f46e5);
        -webkit-background-clip: text;
        background-clip: text;
        -webkit-text-fill-color: transparent;
        letter-spacing: -0.5px;
    }

    .dashboard-header .title-area p {
        color: var(--color-text-muted);
        margin: 0.5rem 0 0 0;
        font-size: 1.1rem;
        font-weight: 500;
    }

    /* Tab Navigation System */
    .dashboard-tabs {
        display: flex;
        gap: 1rem;
        background: var(--color-surface-alt);
        padding: 0.5rem;
        border-radius: 12px;
        box-shadow: inset 0 2px 4px rgba(0, 0, 0, 0.05);
        margin-bottom: 2rem;
        overflow-x: auto;
        /* For mobile */
        -webkit-overflow-scrolling: touch;
    }

    .tab-btn {
        flex: 1;
        text-align: center;
        padding: 1rem 1.5rem;
        border: none;
        background: transparent;
        border-radius: 8px;
        font-size: 1.1rem;
        font-weight: 600;
        color: var(--color-text-muted);
        cursor: pointer;
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        white-space: nowrap;
        position: relative;
        overflow: hidden;
        z-index: 1;
    }

    .tab-btn::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: var(--color-surface);
        border-radius: 8px;
        z-index: -1;
        opacity: 0;
        transform: scale(0.95);
        transition: all 0.3s ease;
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
    }

    .tab-btn:hover {
        color: var(--color-primary);
    }

    .tab-btn.active {
        color: var(--color-primary-dark);
    }

    .tab-btn.active::before {
        opacity: 1;
        transform: scale(1);
    }

    body.dark-theme .dashboard-tabs {
        background: var(--dt-color-surface-alt);
        box-shadow: inset 0 2px 4px rgba(0, 0, 0, 0.5);
    }

    body.dark-theme .tab-btn.active::before {
        background: var(--dt-color-surface);
    }

    /* Tab Content Areas */
    .tab-content {
        display: none;
        animation: fadeIn 0.4s ease forwards;
    }

    .tab-content.active {
        display: block;
    }

    @keyframes fadeIn {
        from {
            opacity: 0;
            transform: translateY(10px);
        }

        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    /* Glassmorphism Premium Cards */
    .premium-card {
        background: rgba(255, 255, 255, 0.85);
        /* Slightly transparent white for light mode */
        backdrop-filter: blur(16px);
        -webkit-backdrop-filter: blur(16px);
        border: 1px solid rgba(255, 255, 255, 0.5);
        border-radius: 20px;
        padding: 2rem;
        box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.05), 0 8px 10px -6px rgba(0, 0, 0, 0.01);
        transition: transform 0.3s ease, box-shadow 0.3s ease;
        margin-bottom: 2rem;
        position: relative;
        overflow: hidden;
    }

    body.dark-theme .premium-card {
        background: rgba(30, 41, 59, 0.7);
        /* Slate 800 with opacity */
        border: 1px solid rgba(255, 255, 255, 0.05);
        box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.3);
    }

    .premium-card::before {
        /* Subtle accent gradient at the top edge */
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 4px;
        background: linear-gradient(90deg, var(--color-primary), var(--color-info), var(--color-purple));
        opacity: 0.7;
    }

    .premium-card.card-hover:hover {
        transform: translateY(-5px);
        box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
    }

    body.dark-theme .premium-card.card-hover:hover {
        box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.5);
    }

    .card-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 1.5rem;
    }

    .card-title {
        font-size: 1.4rem;
        font-weight: 700;
        color: var(--color-text);
        margin: 0;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .card-title i {
        color: var(--color-primary);
    }

    /* Grid Layouts for Metrics */
    .metrics-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 1.5rem;
    }

    .metric-item {
        display: flex;
        flex-direction: column;
        padding: 1.25rem;
        background: var(--color-surface);
        /* Solid interior to contrast glass edge */
        border-radius: 12px;
        border: 1px solid var(--color-border);
        transition: all 0.2s ease;
    }

    body.dark-theme .metric-item {
        background: var(--dt-color-surface-alt);
    }

    .metric-item:hover {
        border-color: var(--color-primary);
        box-shadow: 0 0 0 1px var(--color-primary);
    }

    .metric-label {
        font-size: 0.95rem;
        font-weight: 600;
        color: var(--color-text-muted);
        margin-bottom: 0.5rem;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .metric-value {
        font-size: 2.2rem;
        font-weight: 800;
        color: var(--color-text);
        line-height: 1.1;
    }

    .metric-value.highlight {
        color: var(--color-primary-dark);
    }

    .metric-value.success {
        color: var(--color-secondary-dark);
    }

    .metric-value.warning {
        color: var(--color-warning-dark);
    }

    /* Occupancy Circular Progress */
    .occupancy-circle-wrapper {
        display: flex;
        justify-content: center;
        align-items: center;
        position: relative;
        width: 200px;
        height: 200px;
        margin: 0 auto;
    }

    .occupancy-svg {
        transform: rotate(-90deg);
        width: 100%;
        height: 100%;
    }

    .circle-bg {
        fill: none;
        stroke: var(--color-border);
        stroke-width: 12;
    }

    body.dark-theme .circle-bg {
        stroke: #334155;
    }

    .circle-progress {
        fill: none;
        stroke: url(#gradientPrimary);
        stroke-width: 12;
        stroke-linecap: round;
        transition: stroke-dasharray 1.5s ease-out;
    }

    .occupancy-text-center {
        position: absolute;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        text-align: center;
    }

    .occupancy-text-center .percentage {
        display: block;
        font-size: 2.8rem;
        font-weight: 800;
        color: var(--color-text);
        line-height: 1;
    }

    .occupancy-text-center .label {
        display: block;
        font-size: 0.9rem;
        font-weight: 600;
        color: var(--color-text-muted);
        margin-top: 0.2rem;
        text-transform: uppercase;
    }

    /* Mini Room Grid */
    .mini-rooms-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(60px, 1fr));
        gap: 0.75rem;
    }

    .mini-room-item {
        aspect-ratio: 1;
        border-radius: 8px;
        display: flex;
        justify-content: center;
        align-items: center;
        font-weight: 700;
        font-size: 0.9rem;
        color: #fff;
        cursor: default;
        box-shadow: var(--shadow-sm);
        transition: transform 0.2s;
    }

    .mini-room-item:hover {
        transform: scale(1.1);
    }

    .mini-room-item.status-free {
        background-color: var(--status-free-bg);
    }

    .mini-room-item.status-occupied {
        background-color: var(--status-occupied-bg);
    }

    .mini-room-item.status-booked {
        background-color: var(--status-booked-bg);
        color: #000;
    }

    .mini-room-item.status-advance_booking {
        background-color: var(--status-advance_booking-bg);
    }

    .mini-room-item.status-f_short_occupied {
        background-color: var(--status-zone_f-bg);
    }

    .mini-room-item.status-overdue_occupied {
        background-color: var(--status-overdue-bg);
        border: 2px solid white;
    }

    body.dark-theme .mini-room-item.status-free {
        background-color: var(--status-free-bg);
    }

    body.dark-theme .mini-room-item.status-occupied {
        background-color: var(--status-occupied-bg);
    }

    body.dark-theme .mini-room-item.status-booked {
        background-color: var(--status-booked-bg);
        color: #000;
    }

    body.dark-theme .mini-room-item.status-advance_booking {
        background-color: var(--status-advance_booking-bg);
    }

    body.dark-theme .mini-room-item.status-f_short_occupied {
        background-color: var(--status-zone_f-bg);
    }

    body.dark-theme .mini-room-item.status-overdue_occupied {
        background-color: #7f1d1d;
        border: 2px solid var(--status-overdue-bg);
    }


    /* User Management Table */
    .data-table-container {
        overflow-x: auto;
        border-radius: 12px;
        border: 1px solid var(--color-border);
    }

    .data-table {
        width: 100%;
        border-collapse: collapse;
        background: var(--color-surface);
    }

    .data-table th,
    .data-table td {
        padding: 1rem 1.5rem;
        text-align: left;
        border-bottom: 1px solid var(--color-border);
    }

    .data-table th {
        background: var(--color-table-head-bg);
        font-weight: 600;
        text-transform: uppercase;
        font-size: 0.85rem;
        letter-spacing: 0.5px;
        color: var(--color-text-muted);
    }

    .data-table tr:last-child td {
        border-bottom: none;
    }

    .data-table tbody tr:hover {
        background-color: var(--color-table-row-hover-bg);
    }

    .role-badge {
        padding: 0.3rem 0.8rem;
        border-radius: 20px;
        font-size: 0.8rem;
        font-weight: 600;
        text-transform: uppercase;
    }

    .role-badge.admin {
        background: rgba(99, 102, 241, 0.1);
        color: #4f46e5;
    }

    .role-badge.staff {
        background: rgba(16, 185, 129, 0.1);
        color: #059669;
    }

    body.dark-theme .role-badge.admin {
        background: rgba(99, 102, 241, 0.2);
        color: #818cf8;
    }

    body.dark-theme .role-badge.staff {
        background: rgba(16, 185, 129, 0.2);
        color: #34d399;
    }
</style>

<div class="manager-dashboard">
    <div class="dashboard-header">
        <div class="title-area">
            <h2>Manager Dashboard</h2>
            <p>ศูนย์ควบคุมและวิเคราะห์ข้อมูลสำหรับผู้บริหาร</p>
        </div>
        <div class="actions-area">
            <span class="report-badge" style="display:inline-block; padding: 0.5rem 1rem; background: var(--color-info-bg-light); color: var(--color-info-dark); border-radius: 20px; font-weight: 600; font-size: 0.9rem;">
                <i class="fa-solid fa-clock"></i> ข้อมูล ณ เวลา <?= date('H:i') ?>
            </span>
        </div>
    </div>

    <!-- Tab Navigation -->
    <div class="dashboard-tabs">
        <button class="tab-btn active" data-target="tab-operations"><i class="fa-solid fa-hotel"></i> Operations (ปฏิบัติการ)</button>
        <button class="tab-btn" data-target="tab-analytics"><i class="fa-solid fa-chart-pie"></i> Analytics (วิเคราะห์ข้อมูล)</button>
        <button class="tab-btn" data-target="tab-users"><i class="fa-solid fa-users-gear"></i> User Management (จัดการพนักงาน)</button>
    </div>

    <!-- TAB: OPERATIONS -->
    <div id="tab-operations" class="tab-content active">

        <div style="display: grid; grid-template-columns: 1fr 2fr; gap: 2rem; margin-bottom: 2rem;">
            <!-- Occupancy Widget -->
            <div class="premium-card">
                <div class="card-header">
                    <h3 class="card-title"><i class="fa-solid fa-percent"></i> อัตราการเข้าพักวันนี้</h3>
                </div>
                <div class="occupancy-circle-wrapper">
                    <svg class="occupancy-svg" viewBox="0 0 120 120">
                        <defs>
                            <linearGradient id="gradientPrimary" x1="0%" y1="0%" x2="100%" y2="100%">
                                <stop offset="0%" stop-color="#4f46e5" />
                                <stop offset="100%" stop-color="#3b82f6" />
                            </linearGradient>
                        </defs>
                        <circle class="circle-bg" cx="60" cy="60" r="50"></circle>
                        <circle class="circle-progress" cx="60" cy="60" r="50" style="stroke-dasharray: <?= ($occupancyRate / 100) * (2 * pi() * 50) ?>, 999;"></circle>
                    </svg>
                    <div class="occupancy-text-center">
                        <span class="percentage"><?= number_format($occupancyRate, 0) ?>%</span>
                        <span class="label">Occupied</span>
                    </div>
                </div>
                <div style="text-align: center; margin-top: 1.5rem;">
                    <p style="color: var(--color-text-muted); font-size: 0.95rem;">ใช้งาน/จองแล้ว: <strong><?= ($occupiedCount + $bookedCount) ?></strong> จาก <strong><?= $totalRooms ?></strong> ห้อง</p>
                </div>
            </div>

            <!-- Todays Metrics -->
            <div class="premium-card">
                <div class="card-header">
                    <h3 class="card-title"><i class="fa-solid fa-bolt"></i> สถานะห้องพักโดยรวม</h3>
                </div>
                <div class="metrics-grid" style="grid-template-columns: repeat(2, 1fr);">
                    <div class="metric-item">
                        <span class="metric-label">ห้องว่างพร้อมขาย</span>
                        <span class="metric-value success"><?= $freeCount ?> <span style="font-size: 1rem; color: var(--color-text-muted); font-weight: normal;">ห้อง</span></span>
                    </div>
                    <div class="metric-item">
                        <span class="metric-label">รอเช็คอินวันนี้</span>
                        <span class="metric-value warning"><?= $bookedCount ?> <span style="font-size: 1rem; color: var(--color-text-muted); font-weight: normal;">รายการ</span></span>
                    </div>
                    <div class="metric-item">
                        <span class="metric-label">มีผู้เข้าพักอยู่ (Occupied)</span>
                        <span class="metric-value highlight"><?= $occupiedCount ?> <span style="font-size: 1rem; color: var(--color-text-muted); font-weight: normal;">ห้อง</span></span>
                    </div>
                    <div class="metric-item">
                        <span class="metric-label">กำลังเข้าพักทั้งหมดวันนี้</span>
                        <span class="metric-value"><?= $todayOccupancyCount ?> <span style="font-size: 1rem; color: var(--color-text-muted); font-weight: normal;">การจอง</span></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Mini Room Status Grid -->
        <div class="premium-card">
            <div class="card-header">
                <h3 class="card-title"><i class="fa-solid fa-shapes"></i> ผังสถานะห้องพักย่อ (Live)</h3>
                <a href="/hotel_booking/pages/index.php" class="button outline-primary button-small">ไปที่ผังห้องเต็ม</a>
            </div>

            <?php foreach ($groupedRooms as $groupName => $roomsInGroup): ?>
                <?php if (!empty($roomsInGroup)): ?>
                    <h4 style="margin-top: 1.5rem; margin-bottom: 0.5rem; font-size: 1rem; color: var(--color-text-muted); border-bottom: 1px solid var(--color-border); padding-bottom: 0.3rem;"><?= h($groupName) ?></h4>
                    <div class="mini-rooms-grid">
                        <?php foreach ($roomsInGroup as $r): ?>
                            <div class="mini-room-item status-<?= h($r['display_status']) ?>" title="ห้อง <?= h($r['zone'] . $r['room_number']) ?> - สถานะ: <?= h(ucfirst(str_replace('_', ' ', $r['display_status']))) ?>">
                                <?= h($r['zone'] . $r['room_number']) ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>

            <div class="status-legend" style="margin-top: 2rem; display: flex; gap: 1rem; flex-wrap: wrap; justify-content: center; font-size: 0.85rem;">
                <span style="display:flex; align-items:center; gap:0.3rem;"><span style="width:12px; height:12px; background:var(--status-free-bg); border-radius:3px;"></span> ว่าง</span>
                <span style="display:flex; align-items:center; gap:0.3rem;"><span style="width:12px; height:12px; background:var(--status-occupied-bg); border-radius:3px;"></span> ไม่ว่าง</span>
                <span style="display:flex; align-items:center; gap:0.3rem;"><span style="width:12px; height:12px; background:var(--status-booked-bg); border-radius:3px;"></span> รอเช็คอิน</span>
                <span style="display:flex; align-items:center; gap:0.3rem;"><span style="width:12px; height:12px; background:var(--status-advance_booking-bg); border-radius:3px;"></span> จองล่วงหน้า</span>
                <span style="display:flex; align-items:center; gap:0.3rem;"><span style="width:12px; height:12px; background:var(--status-zone_f-bg); border-radius:3px;"></span> โซน F ชั่วคราว</span>
                <span style="display:flex; align-items:center; gap:0.3rem;"><span style="width:12px; height:12px; background:var(--status-overdue-bg); border-radius:3px;"></span> เกินกำหนด</span>
            </div>
        </div>

    </div>

    <!-- TAB: ANALYTICS -->
    <div id="tab-analytics" class="tab-content">
        <div class="premium-card">
            <div class="card-header">
                <h3 class="card-title"><i class="fa-solid fa-chart-line"></i> สรุปรายได้เดือนนี้ (<?= date('M Y') ?>)</h3>
                <a href="/hotel_booking/pages/report.php" class="button outline-primary button-small">ดูรายงานฉบับเต็ม</a>
            </div>
            <div class="metrics-grid">
                <div class="metric-item">
                    <span class="metric-label">รายได้รวมบริการสุทธิ</span>
                    <span class="metric-value success">฿<?= number_format($totalRevenueThisMonth, 0) ?></span>
                </div>
                <div class="metric-item">
                    <span class="metric-label">การเข้าพักทั้งหมด</span>
                    <span class="metric-value highlight"><?= number_format($totalStaysThisMonth, 0) ?> <span style="font-size: 1rem; font-weight:normal; color: var(--color-text-muted);">ครั้ง</span></span>
                </div>
                <div class="metric-item">
                    <span class="metric-label">คืนที่ขายได้ (Overnight)</span>
                    <span class="metric-value"><?= number_format($totalNightsThisMonth, 0) ?> <span style="font-size: 1rem; font-weight:normal; color: var(--color-text-muted);">คืน</span></span>
                </div>
                <div class="metric-item">
                    <span class="metric-label">ADR โดยประมาณ</span>
                    <span class="metric-value">฿<?= ($totalStaysThisMonth > 0) ? number_format($totalRevenueThisMonth / $totalStaysThisMonth, 0) : 0 ?></span>
                </div>
            </div>
            <div style="margin-top: 2rem; padding: 1rem; background: var(--color-info-bg-light); border: 1px solid var(--color-info-border-light); border-radius: 8px; color: var(--color-info-dark);">
                <i class="fa-solid fa-lightbulb"></i> <strong>Tip:</strong> แท็บนี้แสดงข้อมูลเบื้องต้นของเดือนปัจจุบัน หากต้องการดูวิเคราะห์เชิงลึก ข้ามเดือน และกราฟเส้นแนวโน้ม กรุณากดปุ่ม <strong>"ดูรายงานฉบับเต็ม"</strong>
            </div>
        </div>
    </div>

    <!-- TAB: USER MANAGEMENT -->
    <div id="tab-users" class="tab-content">
        <div class="premium-card">
            <div class="card-header">
                <h3 class="card-title"><i class="fa-solid fa-users"></i> พนักงานในระบบ</h3>
                <button id="open-add-user-modal-mgr" class="button primary"><i class="fa-solid fa-plus"></i> เพิ่มพนักงานใหม่</button>
            </div>

            <div class="data-table-container">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>ชื่อผู้ใช้ (Username)</th>
                            <th>บทบาท (Role)</th>
                            <th>วันที่สร้าง</th>
                            <th>สถานะ</th>
                            <th>จัดการ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($system_users)): ?>
                            <tr>
                                <td colspan="5" style="text-align: center;">ไม่มีข้อมูลพนักงาน</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($system_users as $u): ?>
                                <tr>
                                    <td style="font-weight: 600;"><?= h($u['username']) ?></td>
                                    <td>
                                        <span class="role-badge <?= h($u['role']) ?>"><?= h($u['role']) ?></span>
                                    </td>
                                    <td><?= date('d M Y, H:i', strtotime($u['created_at'])) ?></td>
                                    <td>
                                        <?php if ($u['is_active']): ?>
                                            <span style="color: var(--color-success);"><i class="fa-solid fa-circle-check"></i> ใช้งานปกติ</span>
                                        <?php else: ?>
                                            <span style="color: var(--color-alert);"><i class="fa-solid fa-circle-xmark"></i> ถูกระงับ</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($u['id'] != get_current_user_id()): // Prevent changing own status easily here 
                                        ?>
                                            <button class="button-small mgr-toggle-user-btn <?= $u['is_active'] ? 'outline-secondary' : 'primary' ?>" data-id="<?= h($u['id']) ?>">
                                                <?= $u['is_active'] ? 'ระงับสิทธิ์' : 'เปิดใช้งาน' ?>
                                            </button>
                                        <?php else: ?>
                                            <span style="color: var(--color-text-muted); font-size: 0.85rem;">(คุณใช้งานอยู่)</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Add User Modal for Manager Dashboard -->
        <div id="mgr-add-user-modal" class="modal-overlay" style="display: none;">
            <div class="modal-content" style="max-width: 450px;">
                <button class="modal-close" aria-label="Close" onclick="document.getElementById('mgr-add-user-modal').style.display='none';">×</button>
                <h3>เพิ่มผู้ใช้งานใหม่</h3>
                <form id="mgr-add-user-form" style="display: flex; flex-direction: column; gap: 1rem; margin-top: 1.5rem;">
                    <div class="form-group">
                        <label>ชื่อผู้ใช้ (Username):</label>
                        <input type="text" name="username" required class="form-control" placeholder="เช่น somchai">
                    </div>
                    <div class="form-group">
                        <label>บทบาท:</label>
                        <select name="role" id="mgr_new_user_role" required class="form-control">
                            <option value="staff">พนักงาน (Staff)</option>
                            <option value="admin">ผู้ดูแล (Admin - ต้องตั้งรหัสผ่าน)</option>
                        </select>
                    </div>
                    <div class="form-group" id="mgr_new_user_password_group" style="display:none;">
                        <label>รหัสผ่าน (สำหรับ Admin):</label>
                        <input type="password" id="mgr_new_user_password" name="password" class="form-control" placeholder="อย่างน้อย 6 ตัวอักษร">
                    </div>
                    <div style="margin-top: 1rem; text-align: right;">
                        <button type="button" class="button outline-secondary" onclick="document.getElementById('mgr-add-user-modal').style.display='none';">ยกเลิก</button>
                        <button type="submit" class="button primary" id="mgrSubmitAddUserBtn">บันทึกข้อมูล</button>
                    </div>
                </form>
            </div>
        </div>

    </div>

</div>

<script>
    document.addEventListener('DOMContentLoaded', () => {
        // Tab Switching Logic
        const tabBtns = document.querySelectorAll('.tab-btn');
        const tabContents = document.querySelectorAll('.tab-content');

        tabBtns.forEach(btn => {
            btn.addEventListener('click', () => {
                // Remove active from all
                tabBtns.forEach(b => b.classList.remove('active'));
                tabContents.forEach(c => c.classList.remove('active'));

                // Add active to clicked
                btn.classList.add('active');
                const targetId = btn.getAttribute('data-target');
                document.getElementById(targetId).classList.add('active');
            });
        });

        // --- User Management API Logic ---
        const API_URL = '<?= defined("API_BASE_URL_PHP") ? API_BASE_URL_PHP : "/hotel_booking/pages/api.php" ?>';

        // 1. Toggle Status
        document.querySelectorAll('.mgr-toggle-user-btn').forEach(btn => {
            btn.addEventListener('click', async function() {
                if (!confirm('ยืนยันการเปลี่ยนสถานะพนักงาน?')) return;
                const userId = this.dataset.id;
                const originalText = this.innerHTML;

                if (typeof setButtonLoading === 'function') {
                    setButtonLoading(this, true, 'mgr-btn-' + userId);
                } else {
                    this.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> รอสักครู่...';
                    this.disabled = true;
                }

                try {
                    const response = await fetch(`${API_URL}?action=toggle_user_status`, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded'
                        },
                        body: new URLSearchParams({
                            id: userId
                        })
                    });
                    const data = await response.json();
                    if (data.success) {
                        location.reload();
                    } else {
                        alert(data.message || 'เกิดข้อผิดพลาด');
                    }
                } catch (err) {
                    console.error(err);
                    alert('เกิดข้อผิดพลาดในการเชื่อมต่อ');
                } finally {
                    if (typeof setButtonLoading === 'function') {
                        setButtonLoading(this, false, 'mgr-btn-' + userId);
                    } else {
                        this.innerHTML = originalText;
                        this.disabled = false;
                    }
                }
            });
        });

        // 2. Add User Modal Logic
        const addUserModal = document.getElementById('mgr-add-user-modal');
        const openAddUserBtn = document.getElementById('open-add-user-modal-mgr');

        if (openAddUserBtn && addUserModal) {
            openAddUserBtn.addEventListener('click', () => {
                addUserModal.style.display = 'flex';
            });

            // Hide string password input if staff
            const roleSelect = document.getElementById('mgr_new_user_role');
            const passGroup = document.getElementById('mgr_new_user_password_group');
            const passInput = document.getElementById('mgr_new_user_password');

            roleSelect.addEventListener('change', function() {
                if (this.value === 'admin') {
                    passGroup.style.display = 'block';
                    passInput.required = true;
                } else {
                    passGroup.style.display = 'none';
                    passInput.required = false;
                    passInput.value = '';
                }
            });

            // Form Submit
            const addForm = document.getElementById('mgr-add-user-form');
            addForm.addEventListener('submit', async function(e) {
                e.preventDefault();
                const formData = new FormData(addForm);

                if (formData.get('role') === 'admin' && formData.get('password').length < 6) {
                    alert('รหัสผ่าน Admin ต้องมีความยาวอย่างน้อย 6 ตัวอักษร');
                    return;
                }

                const submitBtn = document.getElementById('mgrSubmitAddUserBtn');
                const originalText = submitBtn.innerHTML;

                if (typeof setButtonLoading === 'function') {
                    setButtonLoading(submitBtn, true, 'mgrSubmitAddUserBtn');
                } else {
                    submitBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> บันทึกข้อมูล...';
                    submitBtn.disabled = true;
                }

                try {
                    const response = await fetch(`${API_URL}?action=add_user`, {
                        method: 'POST',
                        body: formData
                    });
                    const data = await response.json();
                    if (data.success) {
                        location.reload();
                    } else {
                        alert(data.message || 'เกิดข้อผิดพลาด');
                    }
                } catch (err) {
                    console.error(err);
                    alert('เกิดข้อผิดพลาดในการเชื่อมต่อ');
                } finally {
                    if (typeof setButtonLoading === 'function') {
                        setButtonLoading(submitBtn, false, 'mgrSubmitAddUserBtn');
                    } else {
                        submitBtn.innerHTML = originalText;
                        submitBtn.disabled = false;
                    }
                }
            });
        }

    });
</script>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../templates/layout.php';
?>