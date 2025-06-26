<?php
// FILEX: hotel_booking/pages/report.php
// VERSION: 2.2 - Patched by System Auditor
// FIX-1: Modified history query to LEFT JOIN and GROUP_CONCAT group receipts.
// FIX-2: Reworked cash out report query for correctness and to fix SQLSTATE[HY093].

require_once __DIR__ . '/../bootstrap.php';
require_admin();

$pageTitle = 'รายงานสรุปสำหรับผู้บริหาร';

if (!defined('CHECKOUT_TIME_STR')) {
    define('CHECKOUT_TIME_STR', '12:00:00');
}

// --- Default values for existing report filters ---
$currentYear = date('Y');
$currentMonth = date('m');
$defaultStartDate = $_GET['start_date'] ?? date('Y-m-01');
$defaultEndDate = $_GET['end_date'] ?? date('Y-m-t');

$startDate = $_GET['start_date'] ?? $defaultStartDate;
$endDate   = $_GET['end_date']   ?? $defaultEndDate;
$filterZone = trim($_GET['filter_zone'] ?? '');
$filterBookingType = trim($_GET['filter_booking_type'] ?? '');
$groupBy = trim($_GET['group_by'] ?? 'day');

try {
    $startDateObj = new DateTime($startDate);
    $endDateObj = new DateTime($endDate);
    $startDate = $startDateObj->format('Y-m-d');
    $endDate = $endDateObj->format('Y-m-d');
    if ($endDateObj < $startDateObj) {
        list($startDate, $endDate) = [$endDate, $startDate];
        list($startDateObj, $endDateObj) = [$endDateObj, $startDateObj];
    }
} catch (Exception $e) {
    $startDate = date('Y-m-01');
    $endDate = date('Y-m-t');
    $startDateObj = new DateTime($startDate);
    $endDateObj = new DateTime($endDate);
}

$zones = $pdo->query("SELECT DISTINCT zone FROM rooms ORDER BY zone")->fetchAll(PDO::FETCH_COLUMN);
$totalHotelRoomsQuery = $pdo->query("SELECT COUNT(*) FROM rooms");
$totalHotelRooms = ($totalHotelRoomsQuery) ? (int)$totalHotelRoomsQuery->fetchColumn() : 0;

$interval = $startDateObj->diff($endDateObj);
$daysInPeriod = $interval->days + 1;

$baseWhereClauses = ["DATE(a.checkin_datetime) BETWEEN :start_date AND :end_date"];
$bindings = [':start_date' => $startDate, ':end_date' => $endDate];

$roomsForKpiCalculation = $totalHotelRooms;

if (!empty($filterZone)) {
    if ($filterZone === 'ABC') {
        $baseWhereClauses[] = "r.zone IN ('A', 'B', 'C')";
        $roomsInZoneQuery = $pdo->query("SELECT COUNT(*) FROM rooms WHERE zone IN ('A', 'B', 'C')");
        $roomsForKpiCalculation = (int)$roomsInZoneQuery->fetchColumn();
    } else if (in_array($filterZone, ['A', 'B', 'C', 'F'])) {
        $baseWhereClauses[] = "r.zone = :filter_zone";
        $bindings[':filter_zone'] = $filterZone;
        $roomsInZoneQuery = $pdo->prepare("SELECT COUNT(*) FROM rooms WHERE zone = :filter_zone_kpi");
        $roomsInZoneQuery->execute([':filter_zone_kpi' => $filterZone]);
        $roomsForKpiCalculation = (int)$roomsInZoneQuery->fetchColumn();
    }
}

if (!empty($filterBookingType)) {
    $baseWhereClauses[] = "a.booking_type = :filter_booking_type";
    $bindings[':filter_booking_type'] = $filterBookingType;
}
$whereClauseForArchive = "WHERE " . implode(" AND ", $baseWhereClauses);


$kpiSql = "SELECT
                SUM(
                    CASE
                        WHEN a.booking_type = 'overnight' THEN (a.amount_paid - IF(a.deposit_returned = 1, a.deposit_amount, 0))
                        ELSE a.amount_paid
                    END
                ) AS total_service_revenue_calculated,
                SUM(a.amount_paid) AS total_cash_collected_from_archived,
                SUM(CASE WHEN a.booking_type = 'overnight' THEN a.deposit_amount ELSE 0 END) AS total_deposits_involved_actual,
                SUM(CASE WHEN a.booking_type = 'overnight' AND a.deposit_returned = 1 THEN a.deposit_amount ELSE 0 END) AS total_deposits_returned_actual,
                SUM(CASE WHEN a.booking_type = 'overnight' AND a.deposit_returned = 0 THEN a.deposit_amount ELSE 0 END) AS total_deposits_kept_as_income_actual,
                COUNT(DISTINCT a.id) AS total_completed_stays,
                SUM(CASE WHEN a.booking_type = 'overnight' THEN a.nights ELSE 0 END) AS total_overnight_nights_sold,
                COUNT(CASE WHEN a.booking_type = 'short_stay' THEN 1 ELSE NULL END) AS total_short_stays_sold
            FROM archives a";
if (!empty($filterZone) || strpos($whereClauseForArchive, "r.zone") !== false ) {
    $kpiSql .= " JOIN rooms r ON a.room_id = r.id ";
}
$kpiSql .= " $whereClauseForArchive";

$stmtKpi = $pdo->prepare($kpiSql);
$stmtKpi->execute($bindings);
$kpiData = $stmtKpi->fetch(PDO::FETCH_ASSOC);

$totalServiceRevenue = round((float)($kpiData['total_service_revenue_calculated'] ?? 0));
$totalCashCollected = round((float)($kpiData['total_cash_collected_from_archived'] ?? 0));
$totalDepositsInvolved = round((float)($kpiData['total_deposits_involved_actual'] ?? 0));
$totalDepositsReturned = round((float)($kpiData['total_deposits_returned_actual'] ?? 0));
$totalDepositsKept = round((float)($kpiData['total_deposits_kept_as_income_actual'] ?? 0));
$totalCompletedStays = (int)($kpiData['total_completed_stays'] ?? 0);
$totalOvernightNightsSold = (int)($kpiData['total_overnight_nights_sold'] ?? 0);
$totalShortStaysSold = (int)($kpiData['total_short_stays_sold'] ?? 0);

$totalAvailableRoomNights = $roomsForKpiCalculation * $daysInPeriod;
$occupancyRate = ($totalAvailableRoomNights > 0 && $totalOvernightNightsSold > 0) ? ($totalOvernightNightsSold / $totalAvailableRoomNights) * 100 : 0;

$adr_denominator_count = 0;
if ($filterBookingType === 'overnight') {
    $adr_denominator_count = $totalOvernightNightsSold;
} elseif ($filterBookingType === 'short_stay') {
    $adr_denominator_count = $totalShortStaysSold;
} else {
    $adr_denominator_count = $totalCompletedStays;
}
$adr = ($adr_denominator_count > 0) ? ($totalServiceRevenue / $adr_denominator_count) : 0;
$revPar = ($totalAvailableRoomNights > 0) ? ($totalServiceRevenue / $totalAvailableRoomNights) : 0;

$alos_bindings = $bindings;
$alosBaseWhereClauses = $baseWhereClauses;
$alosBaseWhereClauses[] = "a.booking_type = 'overnight'";
$alosWhereClause = "WHERE " . implode(" AND ", $alosBaseWhereClauses);

$stmtOvernightStaysCountSql = "SELECT COUNT(DISTINCT a.id) FROM archives a ";
if (!empty($filterZone) || (strpos(implode(" ", $alosBaseWhereClauses), "r.zone") !== false) ) {
    $stmtOvernightStaysCountSql .= "JOIN rooms r ON a.room_id = r.id ";
}
$stmtOvernightStaysCountSql .= $alosWhereClause;
$stmtOvernightStaysCount = $pdo->prepare($stmtOvernightStaysCountSql);
$stmtOvernightStaysCount->execute($alos_bindings);
$overnightStaysCountForAlos = (int)$stmtOvernightStaysCount->fetchColumn();
$alos = ($overnightStaysCountForAlos > 0) ? ($totalOvernightNightsSold / $overnightStaysCountForAlos) : 0;


$dateFormatSql = "DATE(a.checkin_datetime)";
$groupBySql = "DATE(a.checkin_datetime)";
$xLabel = 'วันที่';
if ($groupBy === 'week') {
    $dateFormatSql = "CONCAT(YEAR(a.checkin_datetime), '-W', LPAD(WEEK(a.checkin_datetime, 1), 2, '0'))";
    $groupBySql = "YEAR(a.checkin_datetime), WEEK(a.checkin_datetime, 1)";
    $xLabel = 'สัปดาห์';
} elseif ($groupBy === 'month') {
    $dateFormatSql = "DATE_FORMAT(a.checkin_datetime, '%Y-%m')";
    $groupBySql = "DATE_FORMAT(a.checkin_datetime, '%Y-%m')";
    $xLabel = 'เดือน';
}

$revenueTrendSql = "SELECT
                        $dateFormatSql AS period,
                        SUM(
                            CASE
                                WHEN a.booking_type = 'overnight' THEN (a.amount_paid - IF(a.deposit_returned = 1, a.deposit_amount, 0))
                                ELSE a.amount_paid
                            END
                        ) AS service_revenue_trend
                    FROM archives a";
if (!empty($filterZone) || (strpos($whereClauseForArchive, "r.zone") !== false) ) {
    $revenueTrendSql .= " JOIN rooms r ON a.room_id = r.id ";
}
$revenueTrendSql .= " $whereClauseForArchive GROUP BY $groupBySql ORDER BY period ASC";
$stmtRevenueTrend = $pdo->prepare($revenueTrendSql);
$stmtRevenueTrend->execute($bindings);
$revenueTrendData = $stmtRevenueTrend->fetchAll(PDO::FETCH_ASSOC);
$revenueTrendLabels_json = json_encode(array_column($revenueTrendData, 'period'));
$revenueTrendValues_json = json_encode(array_map(function($val) { return (int)round($val); }, array_column($revenueTrendData, 'service_revenue_trend')));
$xLabel_json = json_encode((string)$xLabel);

$revenueByZoneSql = "SELECT
                        r.zone,
                        SUM(
                            CASE
                                WHEN a.booking_type = 'overnight' THEN (a.amount_paid - IF(a.deposit_returned = 1, a.deposit_amount, 0))
                                ELSE a.amount_paid
                            END
                        ) AS service_revenue_zone
                    FROM archives a
                    JOIN rooms r ON a.room_id = r.id ";
$revenueByZoneSql .= " $whereClauseForArchive ";
$revenueByZoneSql .= " GROUP BY r.zone ORDER BY r.zone ASC";

$stmtRevenueByZone = $pdo->prepare($revenueByZoneSql);
$stmtRevenueByZone->execute($bindings);
$revenueByZoneData = $stmtRevenueByZone->fetchAll(PDO::FETCH_ASSOC);
$revenueByZoneLabels_json = json_encode(array_column($revenueByZoneData, 'zone'));
$revenueByZoneValues_json = json_encode(array_map(function($val) { return (int)round($val); }, array_column($revenueByZoneData, 'service_revenue_zone')));

$customerNameFilter = trim($_GET['customer_name'] ?? '');
$customerPhoneFilter = trim($_GET['customer_phone'] ?? '');
$historyBindings_detail = $bindings;
$historyWhereClauses_detail = $baseWhereClauses;

if (!empty($customerNameFilter)) {
    $historyWhereClauses_detail[] = "a.customer_name LIKE :customer_name_hist";
    $historyBindings_detail[':customer_name_hist'] = '%' . $customerNameFilter . '%';
}
if (!empty($customerPhoneFilter)) {
    $historyWhereClauses_detail[] = "a.customer_phone LIKE :customer_phone_hist";
    $historyBindings_detail[':customer_phone_hist'] = '%' . $customerPhoneFilter . '%';
}
$historyWhereClause_detail_final = "";
if (!empty($historyWhereClauses_detail)) {
    $historyWhereClause_detail_final = "WHERE " . implode(" AND ", $historyWhereClauses_detail);
}

// Pagination for History Table
$items_per_page_history = 10;
$page_history = isset($_GET['p_hist']) ? max(1, (int)$_GET['p_hist']) : 1;
$offset_history = ($page_history - 1) * $items_per_page_history;

$countSqlHistory = "SELECT COUNT(DISTINCT a.id) FROM archives a ";
$joinRoomsForCount = false;
if (strpos($historyWhereClause_detail_final, "r.") !== false) {
    $joinRoomsForCount = true;
}
if ($joinRoomsForCount) {
    $countSqlHistory .= "JOIN rooms r ON a.room_id = r.id ";
}
$countSqlHistory .= $historyWhereClause_detail_final;

$stmtCountHist = $pdo->prepare($countSqlHistory);
$stmtCountHist->execute($historyBindings_detail);
$total_history_items = (int)$stmtCountHist->fetchColumn();
$total_history_pages = ceil($total_history_items / $items_per_page_history);


// ***** START: FIX-1 (History Query) *****
// แก้ไข SQL Query เพื่อ JOIN ตาราง booking_group_receipts และดึงข้อมูลสลิปทั้งหมด
$sqlHistory = "SELECT
                a.id, r.zone, r.room_number, a.booking_type, a.is_temporary_archive,
                a.customer_name, a.customer_phone,
                DATE_FORMAT(a.checkin_datetime, '%y-%m-%d %H:%i') AS checkin,
                DATE_FORMAT(a.checkout_datetime_calculated, '%y-%m-%d %H:%i') AS checkout_calc,
                a.nights, r.short_stay_duration_hours,
                a.amount_paid, a.price_per_night, a.total_price, a.deposit_amount,
                a.receipt_path, a.extended_receipt_path, a.deposit_path, a.deposit_returned,
                (CASE
                    WHEN a.booking_type = 'overnight' THEN (a.amount_paid - IF(a.deposit_returned = 1, a.deposit_amount, 0) )
                    ELSE a.amount_paid
                END) as net_hotel_gain_calculated,
                DATE_FORMAT(a.archived_at, '%y-%m-%d %H:%i') AS archived_at_formatted,
                GROUP_CONCAT(bgr.receipt_path SEPARATOR ',,,') as group_receipt_paths,
                GROUP_CONCAT(bgr.description SEPARATOR '|||') as group_receipt_descriptions
            FROM archives a
            JOIN rooms r ON a.room_id = r.id
            LEFT JOIN booking_group_receipts bgr ON a.booking_group_id = bgr.booking_group_id ";
$sqlHistory .= " $historyWhereClause_detail_final
            GROUP BY a.id
            ORDER BY a.archived_at DESC
            LIMIT {$items_per_page_history} OFFSET {$offset_history}";
// ***** END: FIX-1 (History Query) *****

$stmtHist = $pdo->prepare($sqlHistory);
$stmtHist->execute($historyBindings_detail);
$history = $stmtHist->fetchAll(PDO::FETCH_ASSOC);


// --- START: Cash Out Report Logic (Integrated and Enhanced) ---
$cash_out_report_data_display = null;
$cash_out_summary_start_time_display = null;
$cash_out_summary_end_time_display = null;
$cash_out_detailed_transactions = [];
$paginated_cash_out_details = [];
$total_co_pages = 0;
$page_co = 1;

if (!function_exists('set_success_message')) {
    function set_success_message($message) {
        $_SESSION['success_message'] = $message;
    }
}
if (!function_exists('set_error_message')) {
    function set_error_message($message) {
        $_SESSION['error_message'] = $message;
    }
}

$cash_out_last_timestamp_str = get_system_setting_value($pdo, 'last_cash_out_timestamp', null);
$cash_out_default_start_datetime_val = '';

if ($cash_out_last_timestamp_str) {
    try {
        $cash_out_last_dt = new DateTime($cash_out_last_timestamp_str, new DateTimeZone('Asia/Bangkok'));
        $cash_out_last_dt->modify('+1 second');
        $cash_out_default_start_datetime_val = $cash_out_last_dt->format('Y-m-d\TH:i:s');
    } catch (Exception $e) {
        error_log("[CashOutReport Logic in report.php] Error parsing last_cash_out_timestamp: " . $e->getMessage());
        $cash_out_default_start_datetime_val = date('Y-m-d\T00:00:00');
    }
} else {
    $cash_out_default_start_datetime_val = date('Y-m-d\T00:00:00');
}
$cash_out_current_end_datetime_val = date('Y-m-d\TH:i:s');

$should_generate_cash_out_report = false;
$cash_out_start_datetime_for_report = null;
$cash_out_end_datetime_for_report = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cash_out_action'])) {
    $should_generate_cash_out_report = true;
    $cash_out_start_datetime_for_report = $_POST['cash_out_start_datetime'] ?? $cash_out_default_start_datetime_val;
    $cash_out_end_datetime_for_report = $_POST['cash_out_end_datetime'] ?? $cash_out_current_end_datetime_val;
    $_SESSION['cash_out_last_start_dt_val'] = $cash_out_start_datetime_for_report;
    $_SESSION['cash_out_last_end_dt_val'] = $cash_out_end_datetime_for_report;

} elseif (isset($_GET['trigger_co_report']) && $_GET['trigger_co_report'] === 'true' &&
          (isset($_GET['cash_out_start_datetime_display']) && isset($_GET['cash_out_end_datetime_display'])) ) {
    $should_generate_cash_out_report = true;
    $cash_out_start_datetime_for_report = $_GET['cash_out_start_datetime_display'];
    $cash_out_end_datetime_for_report = $_GET['cash_out_end_datetime_display'];
} elseif (isset($_GET['p_co']) && isset($_SESSION['cash_out_last_start_dt_val']) && isset($_SESSION['cash_out_last_end_dt_val'])) {
    $should_generate_cash_out_report = true;
    $cash_out_start_datetime_for_report = $_SESSION['cash_out_last_start_dt_val'];
    $cash_out_end_datetime_for_report = $_SESSION['cash_out_last_end_dt_val'];
}


if ($should_generate_cash_out_report) {
    try {
        $cash_out_start_dt_obj = new DateTime($cash_out_start_datetime_for_report);
        $cash_out_end_dt_obj = new DateTime($cash_out_end_datetime_for_report);

        $cash_out_summary_start_time_display = $cash_out_start_dt_obj->format('Y-m-d H:i:s');
        $cash_out_summary_end_time_display = $cash_out_end_dt_obj->format('Y-m-d H:i:s');

        if ($cash_out_end_dt_obj <= $cash_out_start_dt_obj) {
            throw new Exception("เวลาสิ้นสุดของรอบตัดยอดต้องอยู่หลังเวลาเริ่มต้น");
        }

        // ***** START: FIX-2 (Cash Out Query) *****
        // แก้ไข SQL Query ให้ใช้ Placeholder ซ้ำกันได้ และปรับปรุงตรรกะการดึงข้อมูลให้แม่นยำขึ้น
        $sql_cash_out_transactions = "
            (SELECT
                bgr.id AS reference_id,
                'receipt' AS source_table,
                r.zone AS room_zone,
                r.room_number,
                bg.customer_name,
                b.checkin_datetime,
                bgr.uploaded_at AS transaction_time,
                bgr.amount AS paid_amount,
                bgr.payment_method,
                bgr.receipt_path,
                COALESCE(bgr.description, 'ชำระเงิน') AS payment_type_description,
                b.booking_type
            FROM booking_group_receipts bgr
            JOIN booking_groups bg ON bgr.booking_group_id = bg.id
            LEFT JOIN (
                SELECT booking_group_id, MIN(id) as first_booking_id
                FROM bookings
                WHERE booking_group_id IS NOT NULL
                GROUP BY booking_group_id
            ) as first_b ON bg.id = first_b.booking_group_id
            LEFT JOIN bookings b ON first_b.first_booking_id = b.id
            LEFT JOIN rooms r ON b.room_id = r.id
            WHERE bgr.uploaded_at BETWEEN :start_dt AND :end_dt
              AND bgr.amount IS NOT NULL AND bgr.amount > 0)

            UNION ALL

            (SELECT
                a.id AS reference_id,
                'archive_legacy' AS source_table,
                r.zone AS room_zone,
                r.room_number,
                a.customer_name,
                a.checkin_datetime,
                a.created_at AS transaction_time,
                (a.amount_paid - COALESCE(a.additional_paid_amount, 0)) AS paid_amount,
                a.payment_method,
                a.receipt_path,
                'ชำระครั้งแรก (ประวัติเก่า)' AS payment_type_description,
                a.booking_type
            FROM archives a
            JOIN rooms r ON a.room_id = r.id
            WHERE a.created_at BETWEEN :start_dt AND :end_dt
              AND (a.amount_paid - COALESCE(a.additional_paid_amount, 0)) > 0
              AND a.booking_group_id IS NULL)

            UNION ALL

            (SELECT
                a.id AS reference_id,
                'archive_legacy_ext' AS source_table,
                r.zone AS room_zone,
                r.room_number,
                a.customer_name,
                a.checkin_datetime,
                a.last_extended_at AS transaction_time,
                a.additional_paid_amount AS paid_amount,
                a.extended_payment_method as payment_method,
                a.extended_receipt_path as receipt_path,
                'ชำระเพิ่ม (ประวัติเก่า)' AS payment_type_description,
                a.booking_type
            FROM archives a
            JOIN rooms r ON a.room_id = r.id
            WHERE a.last_extended_at BETWEEN :start_dt AND :end_dt
              AND a.additional_paid_amount IS NOT NULL AND a.additional_paid_amount > 0
              AND a.booking_group_id IS NULL)

            ORDER BY transaction_time ASC;
        ";
        $stmt_cash_out_tx = $pdo->prepare($sql_cash_out_transactions);
        $bindings_co = [
            ':start_dt' => $cash_out_summary_start_time_display,
            ':end_dt' => $cash_out_summary_end_time_display,
        ];
        $stmt_cash_out_tx->execute($bindings_co);
        // ***** END: FIX-2 (Cash Out Query) *****

        $all_cash_out_transactions_details = $stmt_cash_out_tx->fetchAll(PDO::FETCH_ASSOC);

        $cash_out_report_data_display = ['เงินสด' => 0, 'เงินโอน' => 0, 'บัตรเครดิต' => 0, 'อื่นๆ' => 0, 'total' => 0];
        $cash_out_detailed_transactions = [];

        foreach ($all_cash_out_transactions_details as $tx) {
            $method_key = $tx['payment_method'] ?? 'อื่นๆ';
            $amount_val = (float)$tx['paid_amount'];

            if ($amount_val != 0) {
                $rounded_amount_val = round($amount_val);
                if (isset($cash_out_report_data_display[$method_key])) {
                    $cash_out_report_data_display[$method_key] += $rounded_amount_val;
                } else {
                    $cash_out_report_data_display['อื่นๆ'] += $rounded_amount_val;
                }
                $cash_out_report_data_display['total'] += $rounded_amount_val;

                $cash_out_detailed_transactions[] = [
                    'reference_id' => $tx['reference_id'] . ' (' . str_replace('_', ' ', ucfirst($tx['source_table'])) . ')',
                    'room_zone' => $tx['room_zone'],
                    'room_number' => $tx['room_number'],
                    'customer_name' => $tx['customer_name'],
                    'transaction_time' => $tx['transaction_time'] ?? $tx['checkin_datetime'],
                    'payment_type_description' => $tx['payment_type_description'],
                    'payment_method' => $method_key,
                    'paid_amount' => $rounded_amount_val,
                    'receipt_path' => $tx['receipt_path'],
                    'booking_type' => $tx['booking_type']
                ];
            }
        }

        $items_per_page_co = 10;
        $page_co = isset($_GET['p_co']) ? max(1, (int)$_GET['p_co']) : 1;
        $total_co_items = count($cash_out_detailed_transactions);
        $total_co_pages = ceil($total_co_items / $items_per_page_co);
        $offset_co = ($page_co - 1) * $items_per_page_co;
        $paginated_cash_out_details = array_slice($cash_out_detailed_transactions, $offset_co, $items_per_page_co);

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cash_out_action']) && $_POST['cash_out_action'] === 'close_period_co') {
            $stmt_update_setting = $pdo->prepare("
                INSERT INTO system_settings (setting_key, setting_value, description, updated_at)
                VALUES ('last_cash_out_timestamp', :new_timestamp_param, 'Timestamp of the last cash-out period closure', NOW())
                ON DUPLICATE KEY UPDATE setting_value = :new_timestamp_param, updated_at = NOW()");
            $stmt_update_setting->execute([':new_timestamp_param' => $cash_out_summary_end_time_display]);

            $new_start_dt_obj_co = new DateTime($cash_out_summary_end_time_display, new DateTimeZone('Asia/Bangkok'));
            $new_start_dt_obj_co->modify('+1 second');
            $cash_out_default_start_datetime_val = $new_start_dt_obj_co->format('Y-m-d\TH:i:s');
            $cash_out_current_end_datetime_val = date('Y-m-d\TH:i:s');

            unset($_SESSION['cash_out_last_start_dt_val']);
            unset($_SESSION['cash_out_last_end_dt_val']);

            set_success_message("ปิดยอดสำหรับช่วงเวลา " . htmlspecialchars(date('d/m/Y H:i', strtotime($cash_out_summary_start_time_display))) . " ถึง " . htmlspecialchars(date('d/m/Y H:i', strtotime($cash_out_summary_end_time_display))) . " เรียบร้อยแล้ว เริ่มช่วงเวลาใหม่");

            $current_query_string_params = $_GET;
            unset($current_query_string_params['p_co']);
            unset($current_query_string_params['trigger_co_report']);
            unset($current_query_string_params['cash_out_start_datetime_display']);
            unset($current_query_string_params['cash_out_end_datetime_display']);

            $current_query_string = http_build_query($current_query_string_params);

            if (ob_get_level() > 0) {
                 ob_end_clean();
            }
            header("Location: report.php" . ($current_query_string ? "?".$current_query_string : "") . "#cash-out-section");
            exit;
        }

    } catch (PDOException $e) {
        error_log("[CashOutReport Logic in report.php] PDOException: " . $e->getMessage());
        set_error_message("เกิดข้อผิดพลาดในการดึงข้อมูลจากฐานข้อมูล (Cash Out): " . $e->getMessage());
        $cash_out_report_data_display = null; $cash_out_detailed_transactions = []; $paginated_cash_out_details = [];
    } catch (Exception $e) {
        error_log("[CashOutReport Logic in report.php] Exception: " . $e->getMessage());
        set_error_message("เกิดข้อผิดพลาด (Cash Out): " . $e->getMessage());
        $cash_out_report_data_display = null; $cash_out_detailed_transactions = []; $paginated_cash_out_details = [];
    }
}
ob_start();
?>
<style>
    /* Styles are assumed to be correct from the provided file */
    .pagination-nav { margin-top: 1.5rem; display: flex; justify-content: center; }
    .pagination { display: inline-flex; list-style: none; padding-left: 0; border-radius: var(--border-radius-md); overflow: hidden; box-shadow: var(--shadow-sm); }
    .page-item { margin: 0; }
    .page-link { padding: 0.6rem 0.9rem; display: block; color: var(--color-primary); background-color: var(--color-surface); border: 1px solid var(--color-border); text-decoration: none; transition: background-color 0.2s, color 0.2s; font-size: 0.9rem; }
    .page-item:not(:first-child) .page-link { border-left: none; }
    .page-item.active .page-link { z-index: 1; color: var(--color-surface); background-color: var(--color-primary); border-color: var(--color-primary); }
    .page-item.disabled .page-link { color: var(--color-text-muted); pointer-events: none; background-color: var(--color-surface-alt); }
    .page-link:hover { background-color: var(--color-surface-alt-hover); color: var(--color-primary-dark); }
    .page-item.active .page-link:hover { background-color: var(--color-primary-dark); }
    .report-header { margin-bottom: 2rem; padding-bottom: 1rem; border-bottom: 2px solid var(--color-primary); }
    .report-header h2 { color: var(--color-primary-dark); font-size: 1.8rem; }
    .report-section { background-color: var(--color-surface); padding: 1.5rem; border-radius: var(--border-radius-lg); box-shadow: var(--shadow-md); margin-bottom: 2rem; border: 1px solid var(--color-border); }
    .report-section h3 { color: var(--color-primary-dark); margin-top: 0; margin-bottom: 1.5rem; padding-bottom: 0.75rem; border-bottom: 1px solid var(--color-border); font-size: 1.4rem; }
    .report-section h3 > i.fas { margin-right: 0.5em; }
    .kpi-summary-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1.5rem; }
    .kpi-box { background-color: var(--color-surface); border-radius: var(--border-radius-md); padding: 1.25rem; box-shadow: var(--shadow-sm); text-align: center; border: 1px solid var(--color-border); transition: transform 0.2s ease-in-out, box-shadow 0.2s ease-in-out, background-color var(--transition-speed) var(--transition-func); }
    .kpi-box:hover { transform: translateY(-3px); box-shadow: var(--shadow-md); }
    .kpi-box h4 { margin-top: 0; margin-bottom: 0.5rem; font-size: 0.9rem; font-weight: 600; color: var(--color-text-muted); text-transform: uppercase; letter-spacing: 0.5px; }
    .kpi-box p { font-size: 1.7rem; font-weight: 700; margin: 0; color: var(--color-primary); }
    .kpi-box p.small { font-size: 1.2rem; font-weight: 600; }
    .kpi-box .sub-text { font-size: 0.8rem; color: var(--color-text-muted); margin-top: 0.25rem; }
    .charts-section { display: grid; grid-template-columns: 1fr; gap: 2rem; }
    @media (min-width: 992px) { .charts-section { grid-template-columns: 1fr 1fr; } }
    .chart-container { background-color: var(--color-surface); padding: 1.5rem; border-radius: var(--border-radius-lg); box-shadow: var(--shadow-md); border: 1px solid var(--color-border); min-height: 400px; display: flex; flex-direction: column; transition: background-color var(--transition-speed) var(--transition-func); }
    .chart-canvas-container { flex-grow: 1; position: relative; }
    .chart-canvas-container canvas { position: absolute; top: 0; left: 0; width: 100%; height: 100%; }
    .report-filter-form { background-color: var(--color-surface-alt); padding: 1.5rem; border-radius: var(--border-radius-md); margin-bottom: 2rem; border: 1px solid var(--color-border); transition: background-color var(--transition-speed) var(--transition-func); }
    .report-filter-form .filter-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; align-items: flex-end; }
    .report-filter-form .filter-group label { display: block; margin-bottom: 0.4rem; font-weight: 500; font-size: 0.9rem; }
    .report-filter-form .filter-group input, .report-filter-form .filter-group select { width: 100%; }
    .report-filter-form .filter-button-group { grid-column: 1 / -1; text-align: right; margin-top: 1rem; }
    .report-filter-form button[type="submit"]{ padding: 0.7rem 1.5rem; }
    .report-table th, .room-performance-table th { background-color: var(--color-table-head-bg); color: var(--color-text); font-weight: 600; padding: 0.8rem 1rem; text-align: left; }
    .report-table td, .room-performance-table td { padding: 0.75rem 1rem; vertical-align: middle; }
    .report-table tbody tr:nth-child(even), .room-performance-table tbody tr:nth-child(even) { background-color: var(--color-table-row-even-bg); }
    .proof-item { display: flex; align-items: center; margin-bottom: 5px; }
    .proof-item:last-child { margin-bottom: 0; }
    .proof-label { font-weight: 500; margin-right: 8px; min-width: 120px; font-size: 0.85rem; }
    .proof-thumb { width: 40px; height: auto; border-radius: var(--border-radius-sm); cursor: pointer; border: 1px solid var(--color-border); vertical-align: middle; margin-right: 8px; }
    .text-success { color: var(--color-success); }
    .text-danger { color: var(--color-error-text); }
    .text-muted { color: var(--color-text-muted); }
    .highlight-value { font-weight: bold; color: var(--color-primary-dark); }
    th.centered, td.centered { text-align: center; }
    th.right-aligned, td.right-aligned { text-align: right; }
    .temporary-archive-row td { background-color: var(--color-info-bg-light) !important; font-style: italic; }
    #cash-out-section .stat-box h4 { font-size: 0.9rem; }
    #cash-out-section .stat-box p { font-size: 1.8rem; }
    #cash-out-section .filter-grid label { font-weight: normal; font-size: 0.9em; }
    .cash-out-details-table th, .cash-out-details-table td { font-size: 0.85rem; padding: 0.5rem 0.75rem; }
    .cash-out-details-table .button-small.info { background-color: var(--color-info); color: var(--color-info-text); border: 1px solid var(--color-info-border); }
    .cash-out-details-table .button-small.info:hover { background-color: var(--color-info-dark); }
</style>

<div class="report-header">
    <h2><?= h($pageTitle) ?></h2>
    <p class="text-muted">แสดงข้อมูลสรุปการจองที่เก็บเข้าประวัติตั้งแต่วันที่ <?= h(date('d/m/Y', strtotime($startDate))) ?> ถึง <?= h(date('d/m/Y', strtotime($endDate))) ?>
        <?php
            if (!empty($filterZone)) {
                if ($filterZone === 'ABC') {
                    echo " | โซน: A, B, C (รวม)";
                } else {
                    echo " | โซน: " . h($filterZone);
                }
            }
        ?>
        <?= !empty($filterBookingType) ? " | ประเภท: " . h($filterBookingType === 'overnight' ? 'ค้างคืน' : ($filterBookingType === 'short_stay' ? 'ชั่วคราว' : '')) : "" ?>
    </p>
</div>

<form method="get" class="report-filter-form" id="main-filter-form">
    <div class="filter-grid">
        <div class="filter-group">
            <label for="start_date">ตั้งแต่วันที่ (เช็คอิน):</label>
            <input type="date" id="start_date" name="start_date" value="<?= h($startDate) ?>" class="form-control">
        </div>
        <div class="filter-group">
            <label for="end_date">ถึงวันที่ (เช็คอิน):</label>
            <input type="date" id="end_date" name="end_date" value="<?= h($endDate) ?>" class="form-control">
        </div>
        <div class="filter-group">
            <label for="filter_zone">โซนห้องพัก:</label>
            <select name="filter_zone" id="filter_zone" class="form-control">
                <option value="">ทุกโซน</option>
                <option value="ABC" <?= ($filterZone == 'ABC') ? 'selected' : '' ?>>โซน A, B, C (รวม)</option>
                <?php
                $specific_zones_to_list = ['A', 'B', 'C', 'F'];
                foreach ($zones as $zone_item):
                    if (in_array($zone_item, $specific_zones_to_list)):
                ?>
                    <option value="<?= h($zone_item) ?>" <?= ($filterZone == $zone_item) ? 'selected' : '' ?>>โซน <?= h($zone_item) ?></option>
                <?php
                    endif;
                endforeach;
                ?>
            </select>
        </div>
        <div class="filter-group">
            <label for="filter_booking_type">ประเภทการจอง:</label>
            <select name="filter_booking_type" id="filter_booking_type" class="form-control">
                <option value="">ทุกประเภท</option>
                <option value="overnight" <?= ($filterBookingType == 'overnight') ? 'selected' : '' ?>>ค้างคืน</option>
                <option value="short_stay" <?= ($filterBookingType == 'short_stay') ? 'selected' : '' ?>>ชั่วคราว</option>
            </select>
        </div>
        <div class="filter-group">
            <label for="group_by">จัดกลุ่มข้อมูลแนวโน้มตาม:</label>
            <select name="group_by" id="group_by" class="form-control">
                <option value="day" <?= ($groupBy == 'day') ? 'selected' : '' ?>>รายวัน</option>
                <option value="week" <?= ($groupBy == 'week') ? 'selected' : '' ?>>รายสัปดาห์</option>
                <option value="month" <?= ($groupBy == 'month') ? 'selected' : '' ?>>รายเดือน</option>
            </select>
        </div>
         <div class="filter-button-group" style="grid-column: 1 / -1; align-self:end;">
             <button type="submit" class="button primary">กรองข้อมูล (รายงานหลัก)</button>
        </div>
    </div>
</form>

<section class="report-section kpi-section">
    <h3><i class="fas fa-chart-line"></i> ภาพรวมประสิทธิภาพ (KPIs) - จากรายการที่เก็บเข้าประวัติแล้ว</h3>
    <div class="kpi-summary-grid">
        <div class="kpi-box">
            <h4>ยอดเงินสดที่รับ (Archived)</h4>
            <p><?= h(number_format($totalCashCollected, 0)) ?> บ.</p>
        </div>
        <div class="kpi-box">
            <h4>รายได้บริการสุทธิ</h4>
            <p style="color: green;"><?= h(number_format($totalServiceRevenue, 0)) ?> บ.</p>
            <p class="sub-text">(หลังจัดการมัดจำ)</p>
        </div>
        <div class="kpi-box"><h4>ยอดมัดจำเกี่ยวข้อง (ค้างคืน)</h4><p><?= h(number_format($totalDepositsInvolved, 0)) ?> บ.</p></div>
        <div class="kpi-box"><h4>ยอดมัดจำที่คืนแล้ว</h4><p class="text-danger"><?= h(number_format($totalDepositsReturned, 0)) ?> บ.</p></div>
        <div class="kpi-box"><h4>ยอดมัดจำที่โรงแรมเก็บไว้</h4><p class="text-success"><?= h(number_format($totalDepositsKept, 0)) ?> บ.</p><p class="sub-text">(รายได้อื่นๆ)</p></div>
        <div class="kpi-box"><h4>อัตราการเข้าพัก (ค้างคืน)</h4><p><?= h(number_format($occupancyRate, 1)) ?>%</p><p class="sub-text">เทียบกับ <?=$roomsForKpiCalculation?> ห้อง <?= ($filterZone === 'ABC' ? "(โซน A,B,C)" : (!empty($filterZone) && in_array($filterZone, ['A','B','C','F']) ? "(โซน ".h($filterZone).")" : "(ทุกโซน)")) ?></p></div>
        <div class="kpi-box"><h4>ADR (เฉลี่ยต่อการขาย)</h4><p><?= h(number_format($adr, 0)) ?> บ.</p><p class="sub-text">จาก <?= $filterBookingType ? h(ucfirst($filterBookingType === 'overnight' ? 'ค้างคืน' : 'ชั่วคราว')) : 'ทั้งหมด' ?></p></div>
        <div class="kpi-box"><h4>RevPAR (จากรายได้บริการ)</h4><p><?= h(number_format($revPar, 0)) ?> บ.</p></div>
        <div class="kpi-box"><h4>จำนวนคืนที่ขายได้ (ค้างคืน)</h4><p class="small"><?= h(number_format($totalOvernightNightsSold, 0)) ?></p></div>
        <div class="kpi-box"><h4>จำนวนการพักชั่วคราว</h4><p class="small"><?= h(number_format($totalShortStaysSold, 0)) ?></p></div>
        <div class="kpi-box"><h4>การเข้าพักทั้งหมด (ที่เสร็จสิ้น)</h4><p class="small"><?= h(number_format($totalCompletedStays, 0)) ?></p></div>
        <div class="kpi-box"><h4>ALOS (ค้างคืน)</h4><p class="small"><?= h(number_format($alos, 1)) ?> คืน</p></div>
    </div>
</section>

<section class="report-section charts-section-container">
    <h3><i class="fas fa-chart-pie"></i> การแสดงผลข้อมูลกราฟ (จากรายการที่เก็บเข้าประวัติ)</h3>
    <div class="charts-section">
        <div class="chart-container">
            <h3>แนวโน้มรายได้บริการสุทธิ (<?= h($xLabel) ?>)</h3>
            <div class="chart-canvas-container"><canvas id="revenueTrendChart"></canvas></div>
        </div>
        <div class="chart-container">
            <h3>สัดส่วนรายได้บริการสุทธิตามโซน</h3>
            <div class="chart-canvas-container"><canvas id="revenueByZoneChart"></canvas></div>
        </div>
    </div>
</section>

<section class="report-section room-performance-section">
    <h3><i class="fas fa-door-open"></i> รายงานประสิทธิภาพห้องพัก (จากรายการที่เก็บเข้าประวัติ)</h3>
    <div class="table-responsive">
        <table class="report-table modern-table room-performance-table">
          <thead>
            <tr>
              <th>โซน</th><th>ห้อง</th><th class="centered">เข้าพักทั้งหมด</th><th class="centered">คืนที่ขาย (ค้างคืน)</th>
              <th class="centered">ครั้ง (ชั่วคราว)</th><th class="right-aligned">รายได้บริการสุทธิ</th><th class="right-aligned">ARR (จากรายได้บริการ)</th>
            </tr>
          </thead>
          <tbody>
            <?php
            $roomPerformanceSql = "SELECT
                                    r.zone, r.room_number, COUNT(DISTINCT a.id) AS stays_count,
                                    SUM(CASE WHEN a.booking_type = 'overnight' THEN a.nights ELSE 0 END) AS nights_sold_count,
                                    COUNT(CASE WHEN a.booking_type = 'short_stay' THEN 1 ELSE NULL END) AS short_stays_count,
                                    SUM(CASE WHEN a.booking_type = 'overnight' THEN (a.amount_paid - IF(a.deposit_returned = 1, a.deposit_amount, 0)) ELSE a.amount_paid END
                                    ) AS room_service_revenue_calculated
                                FROM archives a JOIN rooms r ON a.room_id = r.id ";
            $roomPerformanceSql .= " $whereClauseForArchive GROUP BY r.id, r.zone, r.room_number ORDER BY r.zone, r.room_number";
            $stmtRoomPerf = $pdo->prepare($roomPerformanceSql);
            $stmtRoomPerf->execute($bindings);
            $roomPerformanceData = $stmtRoomPerf->fetchAll(PDO::FETCH_ASSOC);

            if ($roomPerformanceData):
                foreach ($roomPerformanceData as $room_perf):
                    $room_revenue = round((float)$room_perf['room_service_revenue_calculated']);
                    $total_sales_for_arr = (int)$room_perf['stays_count'];
                    $arr = ($total_sales_for_arr > 0) ? ($room_revenue / $total_sales_for_arr) : 0;
            ?>
                <tr>
                  <td><?= h($room_perf['zone']) ?></td><td><?= h($room_perf['room_number']) ?></td>
                  <td class="centered"><?= h($room_perf['stays_count']) ?></td><td class="centered"><?= h((int)$room_perf['nights_sold_count']) ?></td>
                  <td class="centered"><?= h((int)$room_perf['short_stays_count']) ?></td><td class="right-aligned"><?= h(number_format($room_revenue, 0)) ?></td>
                  <td class="right-aligned"><?= h(number_format($arr, 0)) ?></td>
                </tr>
              <?php endforeach; ?>
            <?php else: ?>
              <tr><td colspan="7" style="text-align:center;" class="text-muted"><em>ไม่มีข้อมูลประสิทธิภาพห้องพักตามเงื่อนไขที่เลือก</em></td></tr>
            <?php endif; ?>
          </tbody>
        </table>
    </div>
</section>

<section class="report-section detailed-history-section" id="detailed-history-section">
    <h3><i class="fas fa-history"></i> รายละเอียดการจองที่เก็บในประวัติ</h3>
    <form method="get" class="report-filter-form" id="detail-filter-form" action="report.php#detailed-history-section">
        <input type="hidden" name="start_date" value="<?= h($startDate) ?>">
        <input type="hidden" name="end_date" value="<?= h($endDate) ?>">
        <input type="hidden" name="filter_zone" value="<?= h($filterZone) ?>">
        <input type="hidden" name="filter_booking_type" value="<?= h($filterBookingType) ?>">
        <input type="hidden" name="group_by" value="<?= h($groupBy) ?>">
        <div class="filter-grid">
            <div class="filter-group">
                <label for="customer_name_detail">ชื่อลูกค้า:</label>
                <input type="text" id="customer_name_detail" name="customer_name" value="<?= h($customerNameFilter) ?>" placeholder="ค้นหาชื่อลูกค้า" class="form-control">
            </div>
            <div class="filter-group">
                <label for="customer_phone_detail">เบอร์โทรศัพท์:</label>
                <input type="text" id="customer_phone_detail" name="customer_phone" value="<?= h($customerPhoneFilter) ?>" placeholder="ค้นหาเบอร์โทร" class="form-control">
            </div>
            <div class="filter-button-group" style="grid-column: span 2 / auto; align-self:end;">
                <button type="submit" class="button secondary">ค้นหารายละเอียด</button>
                 <?php if (!empty($customerNameFilter) || !empty($customerPhoneFilter)):
                        $clearDetailFilterParams = $_GET;
                        unset($clearDetailFilterParams['customer_name']); unset($clearDetailFilterParams['customer_phone']); unset($clearDetailFilterParams['p_hist']);
                        $clearDetailFilterQueryString = http_build_query($clearDetailFilterParams);
                 ?>
                    <a href="report.php?<?= $clearDetailFilterQueryString ?>#detailed-history-section" class="button outline-secondary" style="margin-left: 0.5rem;">ล้างการค้นหารายละเอียด</a>
                <?php endif; ?>
            </div>
        </div>
    </form>

    <div class="table-responsive" style="margin-top: 1.5rem;">
        <table class="report-table modern-table">
          <thead>
            <tr>
              <th>ID เก็บ</th><th>ห้อง</th><th>ลูกค้า</th><th>เช็กอิน</th><th>เช็กเอาท์</th><th>ประเภท</th>
              <th class="centered">ระยะเวลา</th><th class="right-aligned">ยอดชำระ</th><th class="right-aligned highlight-value">ค่าบริการที่ได้รับจริง</th>
              <th class="centered">สถานะมัดจำ</th><th>หลักฐาน</th><th>วันที่เก็บ</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($history): ?>
              <?php foreach ($history as $h_item):
                    $deposit_text = '-'; $deposit_class = '';
                    $row_class = $h_item['is_temporary_archive'] ? 'temporary-archive-row' : '';
                    if ($h_item['booking_type'] === 'overnight') {
                        if (round((float)$h_item['deposit_amount']) > 0) {
                            if ($h_item['deposit_returned']) {
                                $deposit_text = 'คืนแล้ว (' . number_format(round((float)$h_item['deposit_amount']),0) . ')'; $deposit_class = 'text-danger';
                            } else {
                                $deposit_text = 'เก็บไว้ (' . number_format(round((float)$h_item['deposit_amount']),0) . ')'; $deposit_class = 'text-success';
                            }
                        } else { $deposit_text = 'ไม่เก็บมัดจำ'; $deposit_class = 'text-muted'; }
                    } elseif ($h_item['booking_type'] === 'short_stay' && $h_item['is_temporary_archive']) {
                        $deposit_text = 'โซน F ชั่วคราว'; $deposit_class = 'text-info';
                    }
              ?>
                <tr class="<?= $row_class ?>">
                  <td><?= h($h_item['id']) ?></td><td><?= h($h_item['zone'] . $h_item['room_number']) ?></td>
                  <td><?= h($h_item['customer_name']) ?><br><small class="text-muted"><?= h($h_item['customer_phone']) ?></small></td>
                  <td><?= h($h_item['checkin']) ?></td><td><?= h($h_item['checkout_calc']) ?></td>
                  <td><?= h($h_item['booking_type'] === 'short_stay' ? 'ชั่วคราว' : 'ค้างคืน') . ($h_item['is_temporary_archive'] ? ' (F)' : '')?></td>
                  <td class="centered"><?= h($h_item['booking_type'] === 'short_stay' ? (($h_item['short_stay_duration_hours'] ?? 'N/A').' ชม.') : (($h_item['nights'] ?? 'N/A').' คืน')) ?></td>
                  <td class="right-aligned"><?= h(number_format(round((float)$h_item['amount_paid']),0)) ?></td>
                  <td class="right-aligned highlight-value"><?= h(number_format(round((float)$h_item['net_hotel_gain_calculated']),0)) ?></td>
                  <td class="centered <?= $deposit_class ?>"><?= $deposit_text ?></td>
                  <td>
                    <?php
                    // ***** START: FIX-1 (Display Logic) *****
                    // รวมการแสดงผลสลิปจาก group_receipts และสลิปเก่า
                    $all_receipts = [];

                    // 1. เพิ่มสลิปจาก group_receipts (ระบบใหม่)
                    if (!empty($h_item['group_receipt_paths'])) {
                        $paths = explode(',,,', $h_item['group_receipt_paths']);
                        $descs = explode('|||', $h_item['group_receipt_descriptions']);
                        foreach ($paths as $index => $path) {
                             if(empty(trim($path))) continue;
                            $all_receipts[h($path)] = [ // Use path as key to prevent duplicates
                                'path' => h($path),
                                'label' => isset($descs[$index]) && !empty(trim($descs[$index])) ? h($descs[$index]) : 'สลิปกลุ่ม',
                                'dir' => 'receipts'
                            ];
                        }
                    }

                    // 2. เพิ่มสลิปเก่า (Fallback)
                    if (!empty($h_item['receipt_path']) && !isset($all_receipts[h($h_item['receipt_path'])])) {
                        $all_receipts[h($h_item['receipt_path'])] = ['path' => h($h_item['receipt_path']), 'label' => 'สลิปหลัก (เก่า)', 'dir' => 'receipts'];
                    }
                    if (!empty($h_item['extended_receipt_path']) && !isset($all_receipts[h($h_item['extended_receipt_path'])])) {
                        $all_receipts[h($h_item['extended_receipt_path'])] = ['path' => h($h_item['extended_receipt_path']), 'label' => 'สลิปเพิ่ม/ปรับ (เก่า)', 'dir' => 'receipts'];
                    }
                    if (!empty($h_item['deposit_path'])) {
                        $all_receipts[h($h_item['deposit_path'])] = ['path' => h($h_item['deposit_path']), 'label' => 'สลิปคืนมัดจำ', 'dir' => 'deposit'];
                    }

                    if (!empty($all_receipts)) {
                        foreach ($all_receipts as $receipt) {
                            echo '<div class="proof-item">';
                            echo '<span class="proof-label">' . $receipt['label'] . ':</span>';
                            echo '<img class="proof-thumb" src="/hotel_booking/uploads/' . $receipt['dir'] . '/' . $receipt['path'] . '" data-src="/hotel_booking/uploads/' . $receipt['dir'] . '/' . $receipt['path'] . '" alt="' . $receipt['label'] . '">';
                            echo '</div>';
                        }
                    } elseif (round((float)($h_item['amount_paid'] ?? 0)) > 0) {
                        echo '<span class="text-muted"><em>ไม่มีหลักฐาน</em></span>';
                    } else {
                        echo '<span class="text-muted"><em>-</em></span>';
                    }
                    // ***** END: FIX-1 (Display Logic) *****
                    ?>
                  </td><td><?= h($h_item['archived_at_formatted']) ?></td>
                </tr>
              <?php endforeach; ?>
            <?php else: ?>
              <tr><td colspan="12" style="text-align:center;" class="text-muted"><em>ไม่มีประวัติการจองตามเงื่อนไขที่เลือก</em></td></tr>
            <?php endif; ?>
          </tbody>
        </table>
    </div>
    <?php if ($total_history_pages > 1): ?>
    <nav class="pagination-nav" aria-label="History Pagination">
        <ul class="pagination">
            <?php if ($page_history > 1): ?>
                <li class="page-item"><a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['p_hist' => $page_history - 1])) ?>#detailed-history-section">&laquo; ก่อนหน้า</a></li>
            <?php else: ?> <li class="page-item disabled"><span class="page-link">&laquo; ก่อนหน้า</span></li> <?php endif; ?>
            <?php
            $num_adjacents = 2; $start_page = max(1, $page_history - $num_adjacents); $end_page = min($total_history_pages, $page_history + $num_adjacents);
            if ($start_page > 1) {
                echo '<li class="page-item"><a class="page-link" href="?' . http_build_query(array_merge($_GET, ['p_hist' => 1])) . '#detailed-history-section">1</a></li>';
                if ($start_page > 2) { echo '<li class="page-item disabled"><span class="page-link">...</span></li>'; }
            }
            for ($i = $start_page; $i <= $end_page; $i++):
                if ($i == $page_history): ?> <li class="page-item active"><span class="page-link"><?= $i ?></span></li>
                <?php else: ?> <li class="page-item"><a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['p_hist' => $i])) ?>#detailed-history-section"><?= $i ?></a></li> <?php endif;
            endfor;
            if ($end_page < $total_history_pages) {
                if ($end_page < $total_history_pages - 1) { echo '<li class="page-item disabled"><span class="page-link">...</span></li>'; }
                echo '<li class="page-item"><a class="page-link" href="?' . http_build_query(array_merge($_GET, ['p_hist' => $total_history_pages])) . '#detailed-history-section">' . $total_history_pages . '</a></li>';
            } ?>
            <?php if ($page_history < $total_history_pages): ?>
                <li class="page-item"><a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['p_hist' => $page_history + 1])) ?>#detailed-history-section">ถัดไป &raquo;</a></li>
            <?php else: ?> <li class="page-item disabled"><span class="page-link">ถัดไป &raquo;</span></li> <?php endif; ?>
        </ul>
    </nav>
    <?php endif; ?>
</section>

<?php // --- START: Integrated Cash Out Report Section --- ?>
<section class="report-section" id="cash-out-section" style="margin-top: 3rem; border-top: 3px solid var(--color-primary);">
    <h3 style="font-size: 1.6rem; color: var(--color-primary-dark);"><i class="fas fa-cash-register"></i> รายงานการรับเงิน (สรุปยอดรายวัน/ตามช่วงเวลา)</h3>
    <?php
    if (isset($_SESSION['success_message']) && !empty($_SESSION['success_message'])) {
        echo '<div class="message success">' . htmlspecialchars($_SESSION['success_message']) . '</div>'; unset($_SESSION['success_message']);
    }
    if (isset($_SESSION['error_message']) && !empty($_SESSION['error_message'])) {
        echo '<div class="message error">' . htmlspecialchars($_SESSION['error_message']) . '</div>'; unset($_SESSION['error_message']);
    } ?>
    <form method="POST" action="report.php#cash-out-section" class="report-filter-form" style="background-color: var(--color-surface);">
        <?php
            $mainReportParamsQuery = $_GET;
            unset($mainReportParamsQuery['p_hist']);
            unset($mainReportParamsQuery['p_co']);
            unset($mainReportParamsQuery['trigger_co_report']);
            unset($mainReportParamsQuery['cash_out_start_datetime_display']);
            unset($mainReportParamsQuery['cash_out_end_datetime_display']);

             foreach ($mainReportParamsQuery as $key => $value) {
                if (is_array($value)) {
                    foreach ($value as $subValue) { echo '<input type="hidden" name="' . htmlspecialchars($key) . '[]" value="' . htmlspecialchars($subValue) . '">'; }
                } else { echo '<input type="hidden" name="' . htmlspecialchars($key) . '" value="' . htmlspecialchars($value) . '">'; }
            } ?>
        <div class="filter-grid" style="grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 1.5rem;">
            <div class="form-group">
                <label for="cash_out_start_datetime">เริ่มช่วงเวลาตัดยอด:</label>
                <input type="datetime-local" id="cash_out_start_datetime" name="cash_out_start_datetime" value="<?= htmlspecialchars($cash_out_default_start_datetime_val) ?>" required class="form-control" style="padding: 0.6rem;">
            </div>
            <div class="form-group">
                <label for="cash_out_end_datetime">สิ้นสุดช่วงเวลาตัดยอด:</label>
                <input type="datetime-local" id="cash_out_end_datetime" name="cash_out_end_datetime" value="<?= htmlspecialchars($cash_out_current_end_datetime_val) ?>" required class="form-control" style="padding: 0.6rem;">
            </div>
            <div class="filter-button-group" style="grid-column: 1 / -1; display:flex; gap:1rem; justify-content: flex-start; margin-top: 1.5rem; padding-top: 1rem; border-top: 1px solid var(--color-border);">
                 <button type="submit" name="cash_out_action" value="generate_report_co" class="button primary" style="padding: 0.7rem 1.5rem;">
                    <img src="/hotel_booking/assets/image/report.png" alt="" style="width:16px; height:16px; margin-right:8px; vertical-align:middle;">ดูรายงานตัดยอด
                 </button>
                 <button type="submit" name="cash_out_action" value="close_period_co" class="button alert" style="padding: 0.7rem 1.5rem;"
                         onclick="return confirm('คุณแน่ใจหรือไม่ว่าต้องการปิดยอดสำหรับช่วงเวลานี้ และเริ่มนับรอบใหม่? การดำเนินการนี้จะอัปเดตเวลาเริ่มต้นของรอบตัดยอดถัดไป');">
                    <img src="/hotel_booking/assets/image/new_day.png" alt="" style="width:16px; height:16px; margin-right:8px; vertical-align:middle;">ปิดยอดและเริ่มรอบใหม่
                 </button>
            </div>
        </div>
    </form>

    <?php if ($cash_out_report_data_display && $cash_out_summary_start_time_display && $cash_out_summary_end_time_display): ?>
    <section class="report-section kpi-section" id="cash-out-summary-display-section" style="margin-top: 2.5rem; padding: 2rem; border-radius: var(--border-radius-lg); box-shadow: var(--shadow-inner, inset 0 2px 4px rgba(0,0,0,0.06)); background-color:var(--color-bg);">
        <h4 style="font-size: 1.4rem; color: var(--color-primary-dark); border-bottom: 1px solid var(--color-border); padding-bottom: 0.7rem; margin-bottom: 1.5rem;">
            สรุปการรับเงิน (ทำรายการช่วง): <?= htmlspecialchars(date('d/m/Y H:i', strtotime($cash_out_summary_start_time_display))) ?> ถึง <?= htmlspecialchars(date('d/m/Y H:i', strtotime($cash_out_summary_end_time_display))) ?>
        </h4>
        <div class="dashboard-stats" style="gap: 1.2rem;">
            <div class="stat-box"><h3 style="font-size:1rem;">เงินสด</h3><p style="font-size:1.8rem;"><?= htmlspecialchars(number_format($cash_out_report_data_display['เงินสด'], 0)) ?> บ.</p></div>
            <div class="stat-box"><h3 style="font-size:1rem;">เงินโอน</h3><p style="font-size:1.8rem;"><?= htmlspecialchars(number_format($cash_out_report_data_display['เงินโอน'], 0)) ?> บ.</p></div>
            <div class="stat-box"><h3 style="font-size:1rem;">บัตรเครดิต</h3><p style="font-size:1.8rem;"><?= htmlspecialchars(number_format($cash_out_report_data_display['บัตรเครดิต'], 0)) ?> บ.</p></div>
            <div class="stat-box"><h3 style="font-size:1rem;">อื่นๆ</h3><p style="font-size:1.8rem;"><?= htmlspecialchars(number_format($cash_out_report_data_display['อื่นๆ'], 0)) ?> บ.</p></div>
        </div>
        <div class="stat-box" style="background-color: var(--color-primary-light, #e0efff); border: 2px solid var(--color-primary-dark); margin-top: 2rem; padding: 1.2rem;">
            <h3 style="color: var(--color-primary-dark); font-size:1.1rem;">ยอดรวมทั้งสิ้น (ตัดยอด)</h3>
            <p style="color: var(--color-primary-dark); font-size: 2.2rem; font-weight: 700;"><?= htmlspecialchars(number_format($cash_out_report_data_display['total'], 0)) ?> บ.</p>
        </div>
        <p style="text-align: right; margin-top: 1.2rem; font-size: 0.85em; color: var(--color-text-muted);">สร้างรายงานตัดยอดเมื่อ: <?= htmlspecialchars(date('d M Y, H:i:s')) ?></p>

        <?php if (!empty($paginated_cash_out_details)): ?>
            <h4 style="font-size: 1.3rem; color: var(--color-primary-dark); margin-top: 2.5rem; margin-bottom: 1rem; padding-bottom: 0.5rem; border-bottom: 1px solid var(--color-border);">
                รายการรับเงินโดยละเอียด (อิงตามเวลาที่ทำรายการ)
            </h4>
            <div class="table-responsive">
                <table class="report-table modern-table cash-out-details-table">
                    <thead>
                        <tr>
                            <th>ID อ้างอิง</th><th>ห้อง</th><th>ลูกค้า</th>
                            <th>เวลาทำรายการ</th><th>คำอธิบาย</th><th>ช่องทางชำระ</th>
                            <th class="right-aligned">ยอดเงิน (บ.)</th><th class="centered">หลักฐาน</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($paginated_cash_out_details as $tx_detail): ?>
                            <tr>
                                <td><?= h($tx_detail['reference_id']) ?></td><td><?= h($tx_detail['room_zone'] . $tx_detail['room_number']) ?></td>
                                <td><?= h($tx_detail['customer_name']) ?></td>
                                <td><?= h($tx_detail['transaction_time'] ? date('d/m/y H:i', strtotime($tx_detail['transaction_time'])) : 'N/A') ?></td>
                                <td><?= h($tx_detail['payment_type_description']) ?></td>
                                <td><?= h($tx_detail['payment_method']) ?></td><td class="right-aligned"><?= h(number_format((float)$tx_detail['paid_amount'], 0)) ?></td>
                                <td class="centered">
                                    <?php if (!empty($tx_detail['receipt_path'])): ?>
                                        <a href="/hotel_booking/uploads/receipts/<?= h($tx_detail['receipt_path']) ?>" target="_blank" class="button-small info receipt-link-co">ดูสลิป</a>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($total_co_pages > 1): ?>
            <nav class="pagination-nav" aria-label="Cash Out Details Pagination">
                <ul class="pagination">
                    <?php
                    $current_co_params = $_GET;
                    if (isset($cash_out_summary_start_time_display) && isset($cash_out_summary_end_time_display)) {
                        $current_co_params['cash_out_start_datetime_display'] = $cash_out_summary_start_time_display;
                        $current_co_params['cash_out_end_datetime_display'] = $cash_out_summary_end_time_display;
                        $current_co_params['trigger_co_report'] = 'true';
                    }
                    unset($current_co_params['p_co']);
                    ?>
                    <?php if ($page_co > 1): ?>
                        <li class="page-item"><a class="page-link" href="?<?= http_build_query(array_merge($current_co_params, ['p_co' => $page_co - 1])) ?>#cash-out-summary-display-section">&laquo; ก่อนหน้า</a></li>
                    <?php else: ?><li class="page-item disabled"><span class="page-link">&laquo; ก่อนหน้า</span></li><?php endif; ?>

                    <?php
                    $num_adjacents_co = 2; $start_page_co = max(1, $page_co - $num_adjacents_co); $end_page_co = min($total_co_pages, $page_co + $num_adjacents_co);
                    if ($start_page_co > 1) {
                        echo '<li class="page-item"><a class="page-link" href="?' . http_build_query(array_merge($current_co_params, ['p_co' => 1])) . '#cash-out-summary-display-section">1</a></li>';
                        if ($start_page_co > 2) { echo '<li class="page-item disabled"><span class="page-link">...</span></li>'; }
                    }
                    for ($i = $start_page_co; $i <= $end_page_co; $i++): ?>
                        <?php if ($i == $page_co): ?>
                            <li class="page-item active"><span class="page-link"><?= $i ?></span></li>
                        <?php else: ?>
                            <li class="page-item"><a class="page-link" href="?<?= http_build_query(array_merge($current_co_params, ['p_co' => $i])) ?>#cash-out-summary-display-section"><?= $i ?></a></li>
                        <?php endif; ?>
                    <?php endfor;
                    if ($end_page_co < $total_co_pages) {
                        if ($end_page_co < $total_co_pages - 1) { echo '<li class="page-item disabled"><span class="page-link">...</span></li>'; }
                        echo '<li class="page-item"><a class="page-link" href="?' . http_build_query(array_merge($current_co_params, ['p_co' => $total_co_pages])) . '#cash-out-summary-display-section">' . $total_co_pages . '</a></li>';
                    }?>
                    <?php if ($page_co < $total_co_pages): ?>
                        <li class="page-item"><a class="page-link" href="?<?= http_build_query(array_merge($current_co_params, ['p_co' => $page_co + 1])) ?>#cash-out-summary-display-section">ถัดไป &raquo;</a></li>
                    <?php else: ?><li class="page-item disabled"><span class="page-link">ถัดไป &raquo;</span></li><?php endif; ?>
                </ul>
            </nav>
            <?php endif; ?>
        <?php elseif ($should_generate_cash_out_report && empty($paginated_cash_out_details)): ?>
             <p class="text-muted" style="margin-top:1.5rem; text-align:center;"><em>ไม่มีรายการโดยละเอียดในช่วงเวลาตัดยอดนี้</em></p>
        <?php endif; ?>
    </section>
    <?php elseif ($should_generate_cash_out_report && empty($_SESSION['success_message']) && empty($_SESSION['error_message'])):
        ?>
        <div class="message info" style="margin-top:1.5rem; padding:1.5rem; background-color: var(--color-info-bg-light); border:1px solid var(--color-info-border-light); border-radius:var(--border-radius-md); text-align:center;">
            <p style="font-size:1.1rem; margin:0;">ไม่มีข้อมูลการรับเงินสำหรับช่วงเวลาตัดยอดที่เลือก หรือยอดรวมเป็นศูนย์</p>
        </div>
    <?php endif; ?>
</section>
<?php // --- END: Integrated Cash Out Report Section --- ?>


<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    Chart.defaults.font.family = "'Segoe UI', Tahoma, Geneva, Verdana, sans-serif";
    Chart.defaults.borderColor = 'rgba(0, 0, 0, 0.1)'; Chart.defaults.color = '#555';
    const isDarkTheme = document.body.classList.contains('dark-theme');
    if (isDarkTheme) { Chart.defaults.borderColor = 'rgba(255, 255, 255, 0.2)'; Chart.defaults.color = '#ccc'; }

    const revenueTrendCtx = document.getElementById('revenueTrendChart');
    if (revenueTrendCtx && typeof Chart !== 'undefined' && <?= !empty($revenueTrendLabels_json) && $revenueTrendLabels_json !== '[]' ? 'true' : 'false' ?>) {
        new Chart(revenueTrendCtx, { type: 'line', data: { labels: <?= $revenueTrendLabels_json ?>, datasets: [{ label: 'รายได้บริการสุทธิ', data: <?= $revenueTrendValues_json ?>, borderColor: isDarkTheme ? 'rgba(75, 192, 192, 1)' : 'rgba(33, 136, 56, 1)', backgroundColor: isDarkTheme ? 'rgba(75, 192, 192, 0.2)' : 'rgba(33, 136, 56, 0.1)', tension: 0.2, fill: true, pointBackgroundColor: isDarkTheme ? 'rgba(75, 192, 192, 1)' : 'rgba(33, 136, 56, 1)', pointBorderColor: isDarkTheme ? '#2b2b2b' : '#fff', pointHoverRadius: 6, pointRadius: 4, }] }, options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true, ticks: { callback: function(value) { return Number.isInteger(value) ? value.toLocaleString('th-TH') + ' บ.' : value; }, color: Chart.defaults.color }, grid: { color: Chart.defaults.borderColor } }, x: { title: { display: true, text: <?= $xLabel_json ?>, color: Chart.defaults.color }, ticks: { color: Chart.defaults.color }, grid: { color: Chart.defaults.borderColor } } }, plugins: { legend: { display: true, position: 'top', labels: { color: Chart.defaults.color } }, tooltip: { callbacks: { label: function(context) { let label = context.dataset.label || ''; if (label) { label += ': '; } if (context.parsed.y !== null) { label += context.parsed.y.toLocaleString('th-TH', { minimumFractionDigits: 0, maximumFractionDigits: 0 }) + ' บ.'; } return label; } }, bodyColor: isDarkTheme ? '#e0e0e0' : '#333', titleColor: isDarkTheme ? '#e0e0e0' : '#333', backgroundColor: isDarkTheme ? 'rgba(40,40,40,0.9)' : 'rgba(255,255,255,0.9)', borderColor: isDarkTheme ? 'rgba(100,100,100,0.9)' : 'rgba(0,0,0,0.1)' } } } });
    } else if (revenueTrendCtx) { const ctx2d = revenueTrendCtx.getContext('2d'); ctx2d.fillStyle = Chart.defaults.color; ctx2d.font = "1rem 'Segoe UI'"; ctx2d.textAlign = "center"; ctx2d.fillText("ไม่มีข้อมูลเพียงพอสำหรับแสดงกราฟแนวโน้ม", revenueTrendCtx.width / 2, revenueTrendCtx.height / 2); }

    const revenueByZoneCtx = document.getElementById('revenueByZoneChart');
    if (revenueByZoneCtx && typeof Chart !== 'undefined' && <?= !empty($revenueByZoneLabels_json) && $revenueByZoneLabels_json !== '[]' ? 'true' : 'false' ?>) {
        new Chart(revenueByZoneCtx, { type: 'doughnut', data: { labels: <?= $revenueByZoneLabels_json ?>, datasets: [{ label: 'สัดส่วนรายได้บริการสุทธิตามโซน', data: <?= $revenueByZoneValues_json ?>, backgroundColor: [ 'rgba(0, 86, 179, 0.8)', 'rgba(33, 136, 56, 0.8)', 'rgba(224, 168, 0, 0.8)', 'rgba(23, 162, 184, 0.8)', 'rgba(108, 117, 125, 0.8)','rgba(200, 35, 51, 0.8)', 'rgba(102, 16, 242, 0.8)', 'rgba(253, 126, 20, 0.8)' ], borderColor: isDarkTheme ? '#2b2b2b' : '#fff', borderWidth: 2, hoverOffset: 8 }] }, options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'right', labels: { padding: 15, color: Chart.defaults.color } }, tooltip: { callbacks: { label: function(context) { let label = context.label || ''; if (label) { label += ': '; } label += context.parsed.toLocaleString('th-TH', { minimumFractionDigits: 0, maximumFractionDigits: 0 }) + ' บ.'; return label; } }, bodyColor: isDarkTheme ? '#e0e0e0' : '#333', titleColor: isDarkTheme ? '#e0e0e0' : '#333', backgroundColor: isDarkTheme ? 'rgba(40,40,40,0.9)' : 'rgba(255,255,255,0.9)', borderColor: isDarkTheme ? 'rgba(100,100,100,0.9)' : 'rgba(0,0,0,0.1)' } } } });
    } else if (revenueByZoneCtx) { const ctx2d = revenueByZoneCtx.getContext('2d'); ctx2d.fillStyle = Chart.defaults.color; ctx2d.font = "1rem 'Segoe UI'"; ctx2d.textAlign = "center"; ctx2d.fillText("ไม่มีข้อมูลเพียงพอสำหรับแสดงกราฟสัดส่วนรายได้", revenueByZoneCtx.width / 2, revenueByZoneCtx.height / 2); }

    document.querySelectorAll('.proof-thumb, .receipt-btn-global, .receipt-link-co').forEach(element => {
        element.addEventListener('click', function(e) {
            e.preventDefault();
            const imageModal = document.getElementById('image-modal');
            const modalImage = document.getElementById('modal-image');
            const imageSrc = this.dataset.src || this.href;
            if (imageModal && modalImage && imageSrc) {
                modalImage.src = imageSrc;
                if (typeof showModal === 'function') {
                    showModal(imageModal);
                } else {
                    imageModal.style.display = 'block';
                }
            } else if (imageSrc) {
                window.open(imageSrc, '_blank');
            }
        });
    });
});
</script>

<?php
$content_main_report = ob_get_clean();
ob_start();
echo $content_main_report;
$content = ob_get_clean();
require_once __DIR__ . '/../templates/layout.php';
?>