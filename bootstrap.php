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


function is_logged_in() {
    return isset($_SESSION['user_id']);
}

function get_current_user_id() {
    return $_SESSION['user_id'] ?? null;
}

function get_current_username() {
    return $_SESSION['username'] ?? null;
}

function get_current_user_role() {
    return $_SESSION['user_role'] ?? null;
}

function require_login() {
    if (!is_logged_in()) {
        if (parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH) !== parse_url(LOGIN_PAGE, PHP_URL_PATH)) {
            header('Location: ' . LOGIN_PAGE);
            exit;
        }
    }
}

function require_role($roleNeeded) {
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

function require_admin() {
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
// **สำคัญ:** กรุณาใส่ Chat ID ที่ถูกต้องที่นี่
// วิธีหา Chat ID:
// 1. เพิ่มบอท @RawDataBot เข้าไปในกลุ่ม Telegram ของคุณ
// 2. คุณจะเห็นข้อความ JSON, มองหา `chat` -> `id` ซึ่งจะเป็นตัวเลขติดลบ (เช่น -1001234567890)
// 3. นำตัวเลขนั้นมาใส่ที่นี่
define('TELEGRAM_CHAT_ID', '-4879004248'); // <-- ใส่ Chat ID ของคุณที่นี่
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
    exit('ระบบขัดข้อง: ไม่สามารถเชื่อมต่อฐานข้อมูลได้ในขณะนี้ (DB_CONN_FAIL). กรุณาตรวจสอบการตั้งค่า DB_HOST, DB_NAME, DB_USER, DB_PASS ใน bootstrap.php ว่าถูกต้องสำหรับสภาพแวดล้อมของ ReadyIDC และชื่อฐานข้อมูล `resortbn_booking` หรือติดต่อผู้ดูแลระบบ');
}

function get_system_setting_value($pdoConn, $key, $default = null) {
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
    error_log("Critical: Could not load system settings from database. Using default values. Error: " . $e->getMessage());
}

if (!defined('API_BASE_URL_PHP')) {
    if (!defined('FULL_BASE_URL')) {
        $_temp_base_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://" . $_SERVER['HTTP_HOST'];
        $_temp_base_path = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
        define('FULL_BASE_URL', $_temp_base_url . $_temp_base_path);
    }
    define('API_BASE_URL_PHP', FULL_BASE_URL . '/hotel_booking/pages/api.php');
}

function h($str) {
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}

define('MAX_FILE_SIZE_BEFORE_COMPRESSION', 1024 * 1024);
define('IMAGE_COMPRESSION_QUALITY_JPEG', 75);
define('IMAGE_COMPRESSION_LEVEL_PNG', 6);
define('WATERMARK_PATH', dirname(__DIR__) . '/assets/image/watermark.png');

/**
 * Sends a room status update to Telegram.
 * This function queries the current state of all rooms and sends a formatted message.
 *
 * @param PDO $pdo The PDO database connection object.
 * @return void
 */
function sendTelegramRoomStatusUpdate(PDO $pdo) {
    if (!defined('TELEGRAM_BOT_TOKEN') || !defined('TELEGRAM_CHAT_ID') || TELEGRAM_CHAT_ID === '-100XXXXXXXXXX' || empty(TELEGRAM_BOT_TOKEN)) {
        error_log("Telegram notifications are not configured (TOKEN or CHAT_ID is missing/default).");
        return;
    }

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
                            WHEN b_latest.checkin_datetime <= NOW() AND NOW() < b_latest.checkout_datetime_calculated THEN 1 
                            WHEN DATE(b_latest.checkin_datetime) = CURDATE() THEN 2
                            ELSE 3
                        END), 
                        b_latest.checkin_datetime DESC,
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
                    default: // 'occupied', 'booked', 'overdue_occupied'
                        $status_icon = '❌';
                        if (!empty($room['customer_name'])) {
                            $name_parts = explode(' ', $room['customer_name']);
                            $customer_info = ' คุณ' . htmlspecialchars($name_parts[0]);
                        }
                        break;
                }
                $message .= htmlspecialchars($room['zone'] . $room['room_number']) . $status_icon . $customer_info . "\n";
            }
            $message .= "\n";
        }
        
        $telegramApiUrl = "https://api.telegram.org/bot" . TELEGRAM_BOT_TOKEN . "/sendMessage";
        $post_fields = [
            'chat_id' => TELEGRAM_CHAT_ID,
            'text' => $message,
            'parse_mode' => 'HTML'
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $telegramApiUrl);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post_fields));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $server_output = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_code !== 200) {
            error_log("Telegram API error. HTTP Code: {$http_code}. Response: {$server_output}");
        } else {
            error_log("Successfully sent Telegram status update.");
        }
    } catch (Exception $e) {
        error_log("Failed to send Telegram notification: " . $e->getMessage());
    }
}


function process_uploaded_image_with_compression($tmpFilePath, $destinationPath, $originalFilename) {
    if (!file_exists($tmpFilePath) || !is_uploaded_file($tmpFilePath)) {
        return false;
    }
    $fileSize = @filesize($tmpFilePath);
    if ($fileSize === false) {
        return move_uploaded_file($tmpFilePath, $destinationPath);
    }
    $fileExtension = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION));
    $supportedImageTypes = ['jpg', 'jpeg', 'png'];

    if ($fileSize > MAX_FILE_SIZE_BEFORE_COMPRESSION && in_array($fileExtension, $supportedImageTypes) && extension_loaded('gd')) {
        $image = null; $compressionSuccess = false;
        try {
            if ($fileExtension === 'jpg' || $fileExtension === 'jpeg') { $image = @imagecreatefromjpeg($tmpFilePath); if ($image) $compressionSuccess = @imagejpeg($image, $destinationPath, IMAGE_COMPRESSION_QUALITY_JPEG); }
            elseif ($fileExtension === 'png') { $image = @imagecreatefrompng($tmpFilePath); if ($image) { imagesavealpha($image, true); $compressionSuccess = @imagepng($image, $destinationPath, IMAGE_COMPRESSION_LEVEL_PNG); } }
            if ($image) imagedestroy($image);
            if ($compressionSuccess) return true;
        } catch (Exception $e) { /* Fall through to move original */ }
    }
    return move_uploaded_file($tmpFilePath, $destinationPath);
}

if (!function_exists('set_success_message')) {
    function set_success_message($message) { $_SESSION['success_message'] = $message; }
}
if (!function_exists('set_error_message')) {
    function set_error_message($message) { $_SESSION['error_message'] = $message; }
}
?>
