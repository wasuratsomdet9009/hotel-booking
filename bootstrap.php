<?php
// FILEX: hotel_booking/bootstrap.php

// --- START: Session and Authentication ---
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
date_default_timezone_set('Asia/Bangkok');
// --- การตั้งค่า URL และ Path หลักของเว็บไซต์ (บังคับ HTTPS) ---
define('BASE_URL', 'https://resort-booking.online');
define('BASE_PATH', '/');

if (BASE_PATH === '/') {
    define('FULL_BASE_URL', rtrim(BASE_URL, '/'));
} else {
    define('FULL_BASE_URL', rtrim(BASE_URL, '/') . '/' . trim(BASE_PATH, '/'));
}


define('LOGIN_PAGE', FULL_BASE_URL . '/hotel_booking/pages/login.php');
define('DASHBOARD_PAGE', FULL_BASE_URL . '/hotel_booking/pages/index.php');


function is_logged_in()
{
    return isset($_SESSION['user_id']);
}

function get_current_user_id()
{
    return $_SESSION['user_id'] ?? null;
}

function get_current_username()
{
    return $_SESSION['username'] ?? null;
}

function get_current_user_role()
{
    return $_SESSION['user_role'] ?? null;
}

function require_login()
{
    if (!is_logged_in()) {
        if (parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH) !== parse_url(LOGIN_PAGE, PHP_URL_PATH)) {
            header('Location: ' . LOGIN_PAGE);
            exit;
        }
    }
}

function require_role($roleNeeded)
{
    require_login();
    $currentRole = get_current_user_role();
    if (is_array($roleNeeded)) {
        if (!in_array($currentRole, $roleNeeded)) {
            $_SESSION['error_message'] = 'คุณไม่มีสิทธิ์เข้าถึงหน้านี้';
            header('Location: ' . DASHBOARD_PAGE);
            exit;
        }
    } elseif ($currentRole !== $roleNeeded) {
        $_SESSION['error_message'] = 'คุณไม่มีสิทธิ์เข้าถึงหน้านี้';
        header('Location: ' . DASHBOARD_PAGE);
        exit;
    }
}

function require_admin()
{
    require_role('admin');
}
// --- END: Session and Authentication ---

define('DB_HOST', 'localhost');
define('DB_NAME', 'resortbn_booking');
define('DB_USER', 'resortbn_root');
define('DB_PASS', 'Kaokam9119@kao');

define('CHECKOUT_TIME_STR', '12:00:00');
define('CHECKOUT_TIME_SQL_INTERVAL', 'INTERVAL 12 HOUR');
define('DEFAULT_SHORT_STAY_DURATION_HOURS', 3);

// --- START: Telegram Notification Configuration ---
define('TELEGRAM_BOT_TOKEN', '7207889837:AAFnxRBIiAqZUdJDU0Fc9FI0pcV5iIW1_mI');
define('TELEGRAM_CHAT_ID', '-4879004248');
// --- END: Telegram Notification Configuration ---

$default_fixed_deposit_val = 100;
$default_hourly_rate_val = 100;

try {
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
} catch (PDOException $e) {
    error_log('Database connection failed: ' . $e->getMessage());
    exit('ระบบขัดข้อง: ไม่สามารถเชื่อมต่อฐานข้อมูลได้ในขณะนี้...');
}

function get_system_setting_value($pdoConn, $key, $default = null)
{
    try {
        $stmt = $pdoConn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();
        return $value !== false ? $value : $default;
    } catch (PDOException $e) {
        error_log("Error fetching system setting '{$key}': " . $e->getMessage());
        return $default;
    }
}

try {
    $_db_fixed_deposit_str = get_system_setting_value($pdo, 'default_fixed_deposit', (string)$default_fixed_deposit_val);
    if (is_numeric($_db_fixed_deposit_str)) {
        define('FIXED_DEPOSIT_AMOUNT', (int)round((float)$_db_fixed_deposit_str));
    } else {
        define('FIXED_DEPOSIT_AMOUNT', $default_fixed_deposit_val);
    }
    $_db_hourly_rate_str = get_system_setting_value($pdo, 'hourly_extension_rate', (string)$default_hourly_rate_val);
    if (is_numeric($_db_hourly_rate_str)) {
        define('HOURLY_RATE', (int)round((float)$_db_hourly_rate_str));
    } else {
        define('HOURLY_RATE', $default_hourly_rate_val);
    }
} catch (PDOException $e) {
    if (!defined('FIXED_DEPOSIT_AMOUNT')) define('FIXED_DEPOSIT_AMOUNT', $default_fixed_deposit_val);
    if (!defined('HOURLY_RATE')) define('HOURLY_RATE', $default_hourly_rate_val);
}

if (!defined('API_BASE_URL_PHP')) {
    if (!defined('FULL_BASE_URL')) {
        $_temp_base_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://" . $_SERVER['HTTP_HOST'];
        $_temp_base_path = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
        define('FULL_BASE_URL', $_temp_base_url . $_temp_base_path);
    }
    define('API_BASE_URL_PHP', FULL_BASE_URL . '/hotel_booking/pages/api.php');
}

function h($str)
{
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}

define('MAX_FILE_SIZE_BEFORE_COMPRESSION', 1024 * 1024);
define('IMAGE_COMPRESSION_QUALITY_JPEG', 75);
define('IMAGE_COMPRESSION_LEVEL_PNG', 6);
define('WATERMARK_PATH', dirname(__DIR__) . '/assets/image/watermark.png');

/**
 * Sends a room status update to Telegram.
 */
function sendTelegramRoomStatusUpdate(PDO $pdo)
{
    if (!defined('TELEGRAM_BOT_TOKEN') || !defined('TELEGRAM_CHAT_ID') || empty(TELEGRAM_BOT_TOKEN)) return;

    try {
        $sql = "
            SELECT
                r.id, r.zone, r.room_number,
                current_booking.customer_name,
                CASE
                    WHEN current_booking.id IS NOT NULL AND NOW() >= current_booking.checkout_datetime_calculated THEN 'overdue_occupied'
                    WHEN current_booking.id IS NOT NULL AND current_booking.checkin_datetime <= NOW() AND NOW() < current_booking.checkout_datetime_calculated AND r.zone = 'F' AND current_booking.booking_type = 'short_stay' THEN 'f_short_occupied'
                    WHEN current_booking.id IS NOT NULL AND current_booking.checkin_datetime <= NOW() AND NOW() < current_booking.checkout_datetime_calculated THEN 'occupied'
                    WHEN current_booking.id IS NOT NULL AND DATE(current_booking.checkin_datetime) = CURDATE() THEN 'booked'
                    ELSE 'free'
                END AS display_status
            FROM rooms r
            LEFT JOIN (
                SELECT b_inner.room_id, b_inner.id, b_inner.customer_name, b_inner.checkin_datetime, b_inner.checkout_datetime_calculated, b_inner.booking_type
                FROM bookings b_inner
                WHERE b_inner.id = (
                    SELECT b_latest.id FROM bookings b_latest WHERE b_latest.room_id = b_inner.room_id
                    ORDER BY 
                        (CASE 
                            WHEN NOW() >= b_latest.checkout_datetime_calculated THEN 1
                            WHEN b_latest.checkin_datetime <= NOW() AND NOW() < b_latest.checkout_datetime_calculated THEN 2
                            WHEN DATE(b_latest.checkin_datetime) = CURDATE() THEN 3
                            ELSE 4
                        END), 
                        b_latest.checkin_datetime ASC,
                        b_latest.id DESC
                    LIMIT 1
                )
            ) AS current_booking ON current_booking.room_id = r.id
            ORDER BY r.zone ASC, CAST(r.room_number AS UNSIGNED) ASC
        ";
        $stmt = $pdo->query($sql);
        $rooms = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $groupedRooms = [];
        foreach ($rooms as $room) {
            $groupedRooms[$room['zone']][] = $room;
        }

        $dateNow = new DateTime("now", new DateTimeZone("Asia/Bangkok"));
        $message = "อัพเดท " . $dateNow->format('d/m/') . ($dateNow->format('Y') + 543) . " เวลา " . $dateNow->format('H.i') . " น.\n";
        $message .= "❌ ไม่ว่าง/จอง ✅ ว่าง ⭕️ ชั่วคราว\n\n";

        foreach ($groupedRooms as $zone => $roomsInZone) {
            $message .= "<b>" . strtoupper($zone) . "</b>\n";
            foreach ($roomsInZone as $room) {
                $customer_info = '';
                switch ($room['display_status']) {
                    case 'free':
                        $status_icon = '✅';
                        break;
                    case 'f_short_occupied':
                        $status_icon = '⭕️';
                        break;
                    default:
                        $status_icon = '❌';
                        if (!empty($room['customer_name'])) {
                            $name_parts = explode(' ', $room['customer_name']);
                            $customer_info = ' ' . htmlspecialchars($name_parts[0]);
                        }
                        break;
                }
                $message .= htmlspecialchars($room['zone'] . $room['room_number']) . $status_icon . $customer_info . "\n";
            }
            $message .= "\n";
        }

        $telegramApiUrl = "https://api.telegram.org/bot" . TELEGRAM_BOT_TOKEN . "/sendMessage";
        $post_fields = ['chat_id' => TELEGRAM_CHAT_ID, 'text' => $message, 'parse_mode' => 'HTML'];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $telegramApiUrl);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post_fields));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_exec($ch);
        curl_close($ch);
    } catch (Exception $e) {
        error_log("Failed to send Telegram notification: " . $e->getMessage());
    }
}

/**
 * [HELPER] Sends a photo to Telegram with a caption. Falls back to sendMessage if the file is missing or a PDF.
 */
function _tg_sendPhotoOrMessage(string $caption, ?string $filePath = null): void
{
    if (!defined('TELEGRAM_BOT_TOKEN') || !defined('TELEGRAM_CHAT_ID') || empty(TELEGRAM_BOT_TOKEN)) return;

    $apiBase = "https://api.telegram.org/bot" . TELEGRAM_BOT_TOKEN;

    // Send as photo if file exists and is an image
    if ($filePath && file_exists($filePath)) {
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif'])) {
            $ch = curl_init();
            $post_fields = [
                'chat_id'    => TELEGRAM_CHAT_ID,
                'photo'      => new CURLFile($filePath),
                'caption'    => mb_substr($caption, 0, 1024), // Telegram caption limit
                'parse_mode' => 'HTML',
            ];
            curl_setopt($ch, CURLOPT_URL, $apiBase . "/sendPhoto");
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $post_fields);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            $response = curl_exec($ch);
            curl_close($ch);
            // If Telegram reports ok, we're done
            $result = json_decode($response, true);
            if (!empty($result['ok'])) return;
            error_log("[Telegram sendPhoto] Failed, falling back to sendMessage. Response: " . $response);
        }
        // PDF or failed photo: append note to message
        if (in_array($ext, ['pdf', 'pdf'])) {
            $caption .= "\n\n📎 (มีหลักฐานเป็น PDF แนบในระบบ)";
        }
    }

    // Fallback: send as text message
    $ch = curl_init();
    $post_fields = ['chat_id' => TELEGRAM_CHAT_ID, 'text' => $caption, 'parse_mode' => 'HTML'];
    curl_setopt($ch, CURLOPT_URL, $apiBase . "/sendMessage");
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post_fields));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_exec($ch);
    curl_close($ch);
}

/**
 * Sends a grouped booking notification to Telegram when a new booking is created.
 * Handles both single and multi-room bookings as one grouped message.
 *
 * @param PDO    $pdo            Active DB connection
 * @param array  $bookingIds     Array of newly created booking IDs
 * @param string|null $receiptImagePath Full filesystem path to the first receipt image (if any)
 */
function sendTelegramNewBookingNotification(PDO $pdo, array $bookingIds, ?string $receiptImagePath = null): void
{
    if (!defined('TELEGRAM_BOT_TOKEN') || empty(TELEGRAM_BOT_TOKEN) || empty($bookingIds)) return;

    try {
        $placeholders = implode(',', array_fill(0, count($bookingIds), '?'));
        $stmt = $pdo->prepare("
            SELECT
                b.id, b.customer_name, b.customer_phone, b.booking_type,
                b.checkin_datetime, b.checkout_datetime_calculated,
                b.nights, b.total_price, b.amount_paid, b.payment_method,
                b.deposit_amount, b.booking_group_id,
                r.zone, r.room_number
            FROM bookings b
            JOIN rooms r ON b.room_id = r.id
            WHERE b.id IN ({$placeholders})
            ORDER BY r.zone, CAST(r.room_number AS UNSIGNED)
        ");
        $stmt->execute($bookingIds);
        $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($bookings)) return;

        $first       = $bookings[0];
        $customerName = htmlspecialchars($first['customer_name']);
        $phone       = !empty($first['customer_phone']) ? htmlspecialchars($first['customer_phone']) : '-';
        $isMulti     = count($bookings) > 1;
        $groupId     = $first['booking_group_id'];

        // Build room list
        $roomList = [];
        $totalPaid = 0;
        foreach ($bookings as $b) {
            $roomList[] = htmlspecialchars($b['zone'] . $b['room_number']);
            $totalPaid += (int)$b['amount_paid'];
        }
        $roomsStr = implode(', ', $roomList);

        // Thai date/time formatting
        $thaiMonths = ['', 'ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
        $tz = new DateTimeZone('Asia/Bangkok');
        $dtCheckin  = new DateTime($first['checkin_datetime'], $tz);
        $dtCheckout = new DateTime($first['checkout_datetime_calculated'], $tz);
        $checkinStr  = $dtCheckin->format('j') . ' ' . $thaiMonths[(int)$dtCheckin->format('n')] . ' ' . ($dtCheckin->format('Y') + 543) . ' ' . $dtCheckin->format('H:i') . ' น.';
        $checkoutStr = $dtCheckout->format('j') . ' ' . $thaiMonths[(int)$dtCheckout->format('n')] . ' ' . ($dtCheckout->format('Y') + 543) . ' ' . $dtCheckout->format('H:i') . ' น.';
        $nowThai = (new DateTime('now', $tz))->format('j') . ' ' . $thaiMonths[(int)(new DateTime('now', $tz))->format('n')] . ' ' . ((new DateTime('now', $tz))->format('Y') + 543) . ' ' . (new DateTime('now', $tz))->format('H:i') . ' น.';

        $bookingTypeLabel = $first['booking_type'] === 'short_stay' ? '🕐 ชั่วคราว' : '🌙 ค้างคืน';
        $nights = (int)($first['nights'] ?? 1);
        $payMethod = htmlspecialchars($first['payment_method'] ?? '-');

        // Compose message
        $icon = $isMulti ? '👥' : '🏨';
        $msg  = "{$icon} <b>การจองใหม่!</b>\n";
        $msg .= "━━━━━━━━━━━━━━━━━━━\n";
        if ($groupId) $msg .= "📋 กลุ่มการจอง: <b>#{$groupId}</b>\n";
        $msg .= "👤 ลูกค้า: <b>{$customerName}</b>\n";
        $msg .= "📞 เบอร์โทร: {$phone}\n";
        $msg .= "🛏️ ห้อง: <b>{$roomsStr}</b>\n";
        $msg .= "🗓️ เช็คอิน: {$checkinStr}\n";
        $msg .= "🗓️ เช็คเอาท์: {$checkoutStr}\n";
        if ($first['booking_type'] === 'overnight') {
            $msg .= "🌙 จำนวน: {$nights} คืน\n";
        }
        $msg .= "🏷️ ประเภท: {$bookingTypeLabel}\n";
        $msg .= "💰 ยอดชำระ: <b>" . number_format($totalPaid) . " บาท</b>\n";
        $msg .= "💳 วิธีชำระ: {$payMethod}\n";
        $msg .= "━━━━━━━━━━━━━━━━━━━\n";
        $msg .= "⏰ บันทึกเมื่อ: {$nowThai}";

        _tg_sendPhotoOrMessage($msg, $receiptImagePath);

    } catch (Exception $e) {
        error_log("[TG NewBooking] Failed: " . $e->getMessage());
    }
}

/**
 * Sends a deposit return notification to Telegram when a deposit is returned.
 *
 * @param PDO         $pdo             Active DB connection
 * @param string      $roomName        Room identifier (e.g. "A1")
 * @param string      $customerName    Customer name
 * @param int         $depositAmount   Amount of deposit returned (THB)
 * @param string|null $proofImagePath  Full filesystem path to the proof image (if any)
 */
function sendTelegramDepositReturnNotification(PDO $pdo, string $roomName, string $customerName, int $depositAmount, ?string $proofImagePath = null): void
{
    if (!defined('TELEGRAM_BOT_TOKEN') || empty(TELEGRAM_BOT_TOKEN)) return;

    try {
        $tz = new DateTimeZone('Asia/Bangkok');
        $now = new DateTime('now', $tz);
        $thaiMonths = ['', 'ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
        $nowThai = $now->format('j') . ' ' . $thaiMonths[(int)$now->format('n')] . ' ' . ($now->format('Y') + 543) . ' ' . $now->format('H:i') . ' น.';

        $customerHtml = htmlspecialchars($customerName);
        $roomHtml     = htmlspecialchars($roomName);

        $msg  = "✅ <b>คืนมัดจำแล้ว!</b>\n";
        $msg .= "━━━━━━━━━━━━━━━━━━━\n";
        $msg .= "🛏️ ห้อง: <b>{$roomHtml}</b>\n";
        $msg .= "👤 ลูกค้า: <b>{$customerHtml}</b>\n";
        $msg .= "💵 มัดจำที่คืน: <b>" . number_format($depositAmount) . " บาท</b>\n";
        $msg .= "━━━━━━━━━━━━━━━━━━━\n";
        $msg .= "⏰ บันทึกเมื่อ: {$nowThai}";

        _tg_sendPhotoOrMessage($msg, $proofImagePath);

    } catch (Exception $e) {
        error_log("[TG DepositReturn] Failed: " . $e->getMessage());
    }
}

function process_uploaded_image_with_compression($tmpFilePath, $destinationPath, $originalFilename)
{
    if (!file_exists($tmpFilePath) || !is_uploaded_file($tmpFilePath)) return false;
    $fileSize = @filesize($tmpFilePath);
    if ($fileSize === false) return move_uploaded_file($tmpFilePath, $destinationPath);
    $fileExtension = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION));
    $supportedImageTypes = ['jpg', 'jpeg', 'png'];

    if ($fileSize > MAX_FILE_SIZE_BEFORE_COMPRESSION && in_array($fileExtension, $supportedImageTypes) && extension_loaded('gd')) {
        $image = null;
        $compressionSuccess = false;
        try {
            if ($fileExtension === 'jpg' || $fileExtension === 'jpeg') {
                $image = @imagecreatefromjpeg($tmpFilePath);
                if ($image) $compressionSuccess = @imagejpeg($image, $destinationPath, IMAGE_COMPRESSION_QUALITY_JPEG);
            } elseif ($fileExtension === 'png') {
                $image = @imagecreatefrompng($tmpFilePath);
                if ($image) {
                    imagesavealpha($image, true);
                    $compressionSuccess = @imagepng($image, $destinationPath, IMAGE_COMPRESSION_LEVEL_PNG);
                }
            }
            if ($image) imagedestroy($image);
            if ($compressionSuccess) return true;
        } catch (Exception $e) {
        }
    }
    return move_uploaded_file($tmpFilePath, $destinationPath);
}

function getNextReceiptNumber(PDO $pdo)
{
    try {
        $date = new DateTime("now", new DateTimeZone("Asia/Bangkok"));
        $thaiYearShort = (int)$date->format('Y') + 543 - 2500;
        $prefix = 'RE' . $thaiYearShort . '-';
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("SELECT MAX(CAST(SUBSTRING(receipt_number, 6) AS UNSIGNED)) FROM generated_receipts WHERE receipt_number LIKE ? FOR UPDATE");
        $stmt->execute([$prefix . '%']);
        $maxNum = (int)$stmt->fetchColumn();
        $nextNum = $maxNum + 1;
        $newReceiptNumber = $prefix . str_pad((string)$nextNum, 5, '0', STR_PAD_LEFT);
        $pdo->commit();
        return $newReceiptNumber;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return 'ERR-' . time() . rand(100, 999);
    }
}

/**
 * [REFACTORED V3 - OVERDUE PRIORITY FIX]
 * ดึงข้อมูลสถานะห้องพักโดยให้ความสำคัญกับ Overdue (เกินกำหนดคืนห้อง) สูงสุด
 */
function fetchRoomStatuses(PDO $pdo)
{
    $sql = "
        WITH RankedBookings AS (
            SELECT
                b.*,
                -- ปรับลำดับความสำคัญใหม่ตามคำขอ: 1=Overdue, 2=Active, 3=BookedToday, 4=Future
                ROW_NUMBER() OVER(
                    PARTITION BY b.room_id
                    ORDER BY
                        (CASE
                            -- 1. Overdue (ต้องจัดการด่วนที่สุด)
                            WHEN NOW() >= b.checkout_datetime_calculated THEN 1 
                            -- 2. Active (กำลังพักอยู่และยังไม่ถึงเวลาออก)
                            WHEN b.checkin_datetime <= NOW() AND NOW() < b.checkout_datetime_calculated THEN 2
                            -- 3. รอเช็คอินวันนี้
                            WHEN DATE(b.checkin_datetime) = CURDATE() THEN 3
                            -- 4. จองล่วงหน้าอื่นๆ
                            ELSE 4
                        END) ASC,
                        b.checkin_datetime ASC,
                        b.id DESC
                ) as rn
            FROM bookings b
            -- กรองดูย้อนหลังนานขึ้น (30 วัน) เพื่อกันพลาดกรณีเคสตกค้าง
            WHERE b.checkout_datetime_calculated > DATE_SUB(NOW(), INTERVAL 30 DAY)
        )
        SELECT
            r.id, r.zone, r.room_number, r.status AS db_actual_status,
            r.price_per_day, r.price_short_stay, r.allow_short_stay,
            r.short_stay_duration_hours, r.ask_deposit_on_overnight, r.price_per_hour_extension,

            rb.id AS current_booking_id,
            rb.customer_name AS current_customer_name,
            rb.customer_phone AS current_customer_phone,
            rb.checkin_datetime AS current_booking_checkin_datetime,
            rb.checkout_datetime_calculated AS current_booking_checkout_datetime_calculated,
            rb.booking_type AS current_booking_type,
            rb.receipt_path AS current_receipt_path,
            rb.nights AS current_booking_nights,
            rb.price_per_night AS current_booking_price_per_night,
            rb.total_price AS current_booking_total_price,
            rb.amount_paid AS current_booking_amount_paid,
            rb.deposit_amount AS current_booking_deposit_amount,
            rb.extended_hours AS current_booking_extended_hours,
            rb.payment_method AS current_booking_payment_method,
            rb.extended_payment_method AS current_booking_extended_payment_method,
            rb.notes AS current_booking_notes,
            rb.booking_group_id AS current_booking_group_id,

            (CASE WHEN rb.id IS NOT NULL AND NOW() >= rb.checkout_datetime_calculated THEN 1 ELSE 0 END) AS is_overdue,
            
            (CASE WHEN rb.id IS NOT NULL AND rb.checkin_datetime <= NOW() AND NOW() < rb.checkout_datetime_calculated
                  AND TIMESTAMPDIFF(MINUTE, NOW(), rb.checkout_datetime_calculated) <= 60
                  AND TIMESTAMPDIFF(MINUTE, NOW(), rb.checkout_datetime_calculated) > 0
             THEN 1 ELSE 0 END) AS is_nearing_checkout,
             
            (CASE WHEN rb.id IS NOT NULL AND rb.total_price > rb.amount_paid THEN 1 ELSE 0 END) AS has_pending_payment,

            -- Logic การแสดงผลสถานะ (Display Status) ปรับให้สอดคล้องกัน
            CASE
                -- 1. Overdue ต้องแดงเข้มก่อนเสมอ (Priority สูงสุด)
                WHEN rb.id IS NOT NULL AND NOW() >= rb.checkout_datetime_calculated THEN 'overdue_occupied'
                
                -- 2. Occupied (กำลังพัก)
                WHEN rb.id IS NOT NULL AND rb.checkin_datetime <= NOW() THEN 
                    CASE WHEN r.zone = 'F' AND rb.booking_type = 'short_stay' THEN 'f_short_occupied' ELSE 'occupied' END

                -- 4. Advance Booking (จองล่วงหน้า)
                -- สีฟ้า (advance_booking) เฉพาะการจองล่วงหน้าสำหรับพรุ่งนี้เท่านั้น
                -- หากการจองล่วงหน้ามากกว่า 2 วัน (คือมะรืนนี้เป็นต้นไป) ให้เป็นสีเขียว (free) ก่อน
                WHEN rb.id IS NOT NULL AND rb.checkin_datetime > NOW() THEN 
                     CASE 
                         WHEN r.status = 'free' AND DATE(rb.checkin_datetime) = DATE_ADD(CURDATE(), INTERVAL 1 DAY) THEN 'advance_booking' 
                         WHEN r.status = 'free' THEN 'free'
                         ELSE r.status 
                     END

                ELSE 'free'
            END AS display_status,

            (SELECT b_rel.id FROM bookings b_rel
             WHERE b_rel.room_id = r.id AND b_rel.checkout_datetime_calculated > NOW()
             ORDER BY b_rel.checkin_datetime ASC LIMIT 1) as relevant_booking_id

        FROM rooms r
        LEFT JOIN RankedBookings rb ON r.id = rb.room_id AND rb.rn = 1
        ORDER BY r.zone ASC, CAST(r.room_number AS UNSIGNED) ASC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// +++ Centralized Helper Functions +++
if (!function_exists('set_success_message')) {
    function set_success_message($message)
    {
        $_SESSION['success_message'] = $message;
    }
}
if (!function_exists('set_error_message')) {
    function set_error_message($message)
    {
        $_SESSION['error_message'] = $message;
    }
}
if (!function_exists('toThaiDateString')) {
    function toThaiDateString($dateInput)
    {
        if (empty($dateInput)) return 'N/A';
        try {
            $date = $dateInput instanceof DateTime ? $dateInput : new DateTime($dateInput);
            $thaiMonths = [1 => 'มกราคม', 2 => 'กุมภาพันธ์', 3 => 'มีนาคม', 4 => 'เมษายน', 5 => 'พฤษภาคม', 6 => 'มิถุนายน', 7 => 'กรกฎาคม', 8 => 'สิงหาคม', 9 => 'กันยายน', 10 => 'ตุลาคม', 11 => 'พฤศจิกายน', 12 => 'ธันวาคม'];
            return $date->format('j') . ' ' . $thaiMonths[(int)$date->format('n')] . ' ' . ($date->format('Y') + 543);
        } catch (Exception $e) {
            return 'รูปแบบวันที่ผิดพลาด';
        }
    }
}
if (!function_exists('thaimonthfull')) {
    function thaimonthfull($montheng)
    {
        $thaimonths = ['January' => 'มกราคม', 'February' => 'กุมภาพันธ์', 'March' => 'มีนาคม', 'April' => 'เมษายน', 'May' => 'พฤษภาคม', 'June' => 'มิถุนายน', 'July' => 'กรกฎาคม', 'August' => 'สิงหาคม', 'September' => 'กันยายน', 'October' => 'ตุลาคม', 'November' => 'พฤศจิกายน', 'December' => 'ธันวาคม'];
        return $thaimonths[$montheng] ?? $montheng;
    }
}
