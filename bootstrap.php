<?php
// FILEX: hotel_booking/bootstrap.php

// --- START: Session and Authentication ---
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
date_default_timezone_set('Asia/Bangkok');
// --- การตั้งค่า URL และ Path หลักของเว็บไซต์ (บังคับ HTTPS) ---
// แก้ไข: บังคับใช้ HTTPS และตั้งค่าโดเมนใหม่โดยตรง
define('BASE_URL', 'https://resort-booking.online');

// แก้ไข: กำหนด BASE_PATH ตามโครงสร้างใหม่ (ถ้าโปรเจกต์อยู่ที่ root ของโดเมน)
// หาก resort-booking.online ชี้ไปที่โฟลเดอร์ที่เคยเป็น hotel_booking/ โดยตรง
// BASE_PATH จะเป็น '/'
// หากคุณยังคงมี hotel_booking/ เป็น subdirectory ภายใต้ resort-booking.online (เช่น resort-booking.online/hotel_booking/)
// ให้ใช้ define('BASE_PATH', '/hotel_booking/');
define('BASE_PATH', '/'); // <--- **ตรวจสอบและปรับแก้ตามโครงสร้างจริงบนโฮสต์ของคุณ**

// สร้าง FULL_BASE_URL จาก BASE_URL และ BASE_PATH
// rtrim เพื่อเอา / ปิดท้ายออกถ้ามี, ltrim เพื่อเอา / นำหน้าออกถ้ามี (สำหรับ BASE_PATH ที่ไม่ใช่ /)
// และต่อด้วย / คั่นกลางถ้า BASE_PATH ไม่ใช่แค่ /
if (BASE_PATH === '/') {
    define('FULL_BASE_URL', rtrim(BASE_URL, '/'));
} else {
    define('FULL_BASE_URL', rtrim(BASE_URL, '/') . '/' . trim(BASE_PATH, '/'));
}


// แก้ไข: ปรับ Path ของหน้าต่างๆ ให้สอดคล้องกับ FULL_BASE_URL และ BASE_PATH ใหม่
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

// Default values for settings, used as fallbacks
$default_fixed_deposit_val = 100; // Default as integer
$default_hourly_rate_val = 100;   // Default as integer

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

// Load system settings and define constants
try {
    // FIXED_DEPOSIT_AMOUNT
    $_db_fixed_deposit_str = get_system_setting_value($pdo, 'default_fixed_deposit', (string)$default_fixed_deposit_val);
    if (is_numeric($_db_fixed_deposit_str)) {
        define('FIXED_DEPOSIT_AMOUNT', (int)round((float)$_db_fixed_deposit_str));
    } else {
        define('FIXED_DEPOSIT_AMOUNT', $default_fixed_deposit_val);
        error_log("Warning: 'default_fixed_deposit' not found or invalid in system_settings. Using default: " . FIXED_DEPOSIT_AMOUNT);
    }

    // HOURLY_RATE (previously HOURLY_EXTENSION_RATE)
    $_db_hourly_rate_str = get_system_setting_value($pdo, 'hourly_extension_rate', (string)$default_hourly_rate_val);
    if (is_numeric($_db_hourly_rate_str)) {
        define('HOURLY_RATE', (int)round((float)$_db_hourly_rate_str));
    } else {
        define('HOURLY_RATE', $default_hourly_rate_val);
        error_log("Warning: 'hourly_extension_rate' not found or invalid in system_settings. Using default HOURLY_RATE: " . HOURLY_RATE);
    }

} catch (PDOException $e) {
    // Fallback if DB error during settings fetch
    if (!defined('FIXED_DEPOSIT_AMOUNT')) {
        define('FIXED_DEPOSIT_AMOUNT', $default_fixed_deposit_val);
    }
    if (!defined('HOURLY_RATE')) {
        define('HOURLY_RATE', $default_hourly_rate_val);
    }
    error_log("Critical: Could not load system settings from database. Using default values. Error: " . $e->getMessage());
}


if (!defined('API_BASE_URL_PHP')) {
    // FULL_BASE_URL should be defined earlier based on BASE_URL and BASE_PATH
    if (!defined('FULL_BASE_URL')) {
        // Attempt to reconstruct FULL_BASE_URL if not defined yet (should be defined before this)
        // This is a fallback, ideally FULL_BASE_URL is always set up top.
        $_temp_base_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://" . $_SERVER['HTTP_HOST'];
        $_temp_base_path = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\'); // Basic guess for path
        define('FULL_BASE_URL', $_temp_base_url . $_temp_base_path);
        error_log("Warning: FULL_BASE_URL was not defined. Guessed as: " . FULL_BASE_URL);
    }
    define('API_BASE_URL_PHP', FULL_BASE_URL . '/hotel_booking/pages/api.php');
}


function h($str) {
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}

// --- START: Image Processing Configuration ---
if (!defined('MAX_FILE_SIZE_BEFORE_COMPRESSION')) {
    define('MAX_FILE_SIZE_BEFORE_COMPRESSION', 1024 * 1024); // 1 MB (1 * 1024 KB * 1024 Bytes)
}
if (!defined('IMAGE_COMPRESSION_QUALITY_JPEG')) {
    define('IMAGE_COMPRESSION_QUALITY_JPEG', 75); // คุณภาพสำหรับ JPEG (0-100)
}
if (!defined('IMAGE_COMPRESSION_LEVEL_PNG')) {
    define('IMAGE_COMPRESSION_LEVEL_PNG', 6); // ระดับการบีบอัดสำหรับ PNG (0-9, 0 คือไม่บีบอัด, 9 คือบีบอัดสูงสุด)
}

// Assumes bootstrap.php is in a subdirectory like 'hotel_booking' and 'assets' is in the parent directory.
// e.g. project_root/assets/image/watermark.png
// e.g. project_root/hotel_booking/bootstrap.php
define('WATERMARK_PATH', dirname(__DIR__) . '/assets/image/watermark.png'); 
// --- END: Image Processing Configuration ---


/**
 * ประมวลผลไฟล์รูปภาพที่อัปโหลด
 * หากรูปภาพมีขนาดใหญ่กว่า MAX_FILE_SIZE_BEFORE_COMPRESSION จะพยายามบีบอัด
 * หากการบีบอัดล้มเหลวหรือไม่สามารถทำได้ จะย้ายไฟล์ต้นฉบับไปแทน
 *
 * @param string $tmpFilePath The temporary path of the uploaded file (เช่น $_FILES['receipt']['tmp_name'])
 * @param string $destinationPath The full path to save the final image.
 * @param string $originalFilename The original filename (เช่น $_FILES['receipt']['name']) เพื่อใช้ตรวจสอบนามสกุลไฟล์
 * @return bool True on success, false on failure.
 */
function process_uploaded_image_with_compression($tmpFilePath, $destinationPath, $originalFilename) {
    if (!file_exists($tmpFilePath) || !is_uploaded_file($tmpFilePath)) {
        error_log("[ProcessImageCompress] ไฟล์ชั่วคราวไม่พบหรือไม่ได้อัปโหลดอย่างถูกต้อง: " . $tmpFilePath);
        return false;
    }

    $fileSize = @filesize($tmpFilePath);
    if ($fileSize === false) {
        error_log("[ProcessImageCompress] ไม่สามารถตรวจสอบขนาดไฟล์ได้: " . $tmpFilePath);
        return move_uploaded_file($tmpFilePath, $destinationPath); // ลองย้ายไฟล์เดิมไปเลย
    }

    $fileExtension = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION));
    $supportedImageTypesForCompression = ['jpg', 'jpeg', 'png'];

    // ตรวจสอบว่า GD library พร้อมใช้งานหรือไม่
    if (!extension_loaded('gd') || !function_exists('gd_info')) {
        error_log("[ProcessImageCompress] GD library ไม่พร้อมใช้งาน จะทำการย้ายไฟล์ต้นฉบับโดยไม่บีบอัดสำหรับ: " . $originalFilename);
        return move_uploaded_file($tmpFilePath, $destinationPath);
    }

    if ($fileSize > MAX_FILE_SIZE_BEFORE_COMPRESSION && in_array($fileExtension, $supportedImageTypesForCompression)) {
        error_log("[ProcessImageCompress] ไฟล์ '{$originalFilename}' (ขนาด: {$fileSize} bytes) ใหญ่กว่า 1MB. กำลังพยายามบีบอัด...");
        $image = null;
        $compressionSuccess = false;

        try {
            switch ($fileExtension) {
                case 'jpg':
                case 'jpeg':
                    $image = @imagecreatefromjpeg($tmpFilePath);
                    if ($image) {
                        $compressionSuccess = @imagejpeg($image, $destinationPath, IMAGE_COMPRESSION_QUALITY_JPEG);
                    } else {
                        error_log("[ProcessImageCompress] ไม่สามารถสร้าง image resource จาก JPEG: {$tmpFilePath}");
                    }
                    break;
                case 'png':
                    $image = @imagecreatefrompng($tmpFilePath);
                    if ($image) {
                        imagesavealpha($image, true); // รักษาส่วนโปร่งใสของ PNG
                        $compressionSuccess = @imagepng($image, $destinationPath, IMAGE_COMPRESSION_LEVEL_PNG);
                    } else {
                        error_log("[ProcessImageCompress] ไม่สามารถสร้าง image resource จาก PNG: {$tmpFilePath}");
                    }
                    break;
                // อาจจะเพิ่ม case 'gif' ถ้าต้องการ แต่การบีบอัด GIF ด้วย GD อาจทำให้ Animation หาย
            }

            if ($image && (is_resource($image) || (function_exists('is_gd_image') && is_gd_image($image)))) {
                imagedestroy($image);
            }

            if ($compressionSuccess) {
                $newSize = file_exists($destinationPath) ? filesize($destinationPath) : 'N/A';
                error_log("[ProcessImageCompress] บีบอัดและบันทึกไฟล์ '{$originalFilename}' ไปที่ '{$destinationPath}' สำเร็จ ขนาดใหม่: {$newSize} bytes.");
                return true;
            } else {
                error_log("[ProcessImageCompress] การบีบอัดไฟล์ '{$originalFilename}' ล้มเหลว (อาจเกิดจาก imagecreatefrom... หรือ imagejpeg/png ล้มเหลว) จะทำการย้ายไฟล์ต้นฉบับแทน");
                // หากการบีบอัดล้มเหลว ให้ย้ายไฟล์ต้นฉบับไปแทน
                if (file_exists($destinationPath)) { // ลบไฟล์ปลายทางที่อาจสร้างขึ้นแต่ไม่สมบูรณ์จากการพยายามบีบอัด
                    @unlink($destinationPath);
                }
                return move_uploaded_file($tmpFilePath, $destinationPath);
            }
        } catch (Exception $e) {
            error_log("[ProcessImageCompress] เกิด Exception ระหว่างการบีบอัดไฟล์ '{$originalFilename}': " . $e->getMessage() . ". จะทำการย้ายไฟล์ต้นฉบับแทน");
            // หากเกิด Exception ให้ย้ายไฟล์ต้นฉบับไปแทน
            if (file_exists($destinationPath)) {
                 @unlink($destinationPath);
            }
            return move_uploaded_file($tmpFilePath, $destinationPath);
        }
    } else {
        // ไฟล์มีขนาดเล็กพอ, ไม่ใช่ประเภทไฟล์รูปภาพที่รองรับการบีบอัด, หรือเป็น PDF
        if ($fileSize <= MAX_FILE_SIZE_BEFORE_COMPRESSION) {
            error_log("[ProcessImageCompress] ไฟล์ '{$originalFilename}' (ขนาด: {$fileSize} bytes) อยู่ในเกณฑ์ขนาดที่กำหนด จะย้ายไฟล์ต้นฉบับ");
        } else {
            error_log("[ProcessImageCompress] ไฟล์ '{$originalFilename}' ไม่ใช่ประเภทรูปภาพที่รองรับการบีบอัด (เช่น PDF) หรือมีขนาดใหญ่แต่ไม่ถูกบีบอัด จะย้ายไฟล์ต้นฉบับ");
        }
        return move_uploaded_file($tmpFilePath, $destinationPath);
    }
}


function process_uploaded_image(string $temp_path, string $destination_path, ?string $watermark_image_path = null): bool {
    // Use WATERMARK_PATH constant if $watermark_image_path is not explicitly provided or is null
    $effective_watermark_path = $watermark_image_path ?? (defined('WATERMARK_PATH') ? WATERMARK_PATH : null);

    $file_extension = strtolower(pathinfo($destination_path, PATHINFO_EXTENSION));
    $source_image = null;

    if ($file_extension === 'jpg' || $file_extension === 'jpeg') {
        $source_image = @imagecreatefromjpeg($temp_path);
    } elseif ($file_extension === 'png') {
        $source_image = @imagecreatefrompng($temp_path);
    } elseif ($file_extension === 'gif') {
        $source_image = @imagecreatefromgif($temp_path);
    } else {
        error_log("[ImageProcess] Function called with an unsupported extension for GD processing: {$file_extension}. Moving original file.");
        return move_uploaded_file($temp_path, $destination_path);
    }

    if (!$source_image) {
        error_log("[ImageProcess] GD: Failed to create image resource from temp path: {$temp_path}. Attempting direct move.");
        if (is_uploaded_file($temp_path)) {
             return move_uploaded_file($temp_path, $destination_path);
        } else {
             error_log("[ImageProcess] Original temp file {$temp_path} is no longer a valid uploaded file or does not exist. Cannot move.");
             return false;
        }
    }

    try {
        $orig_width = imagesx($source_image);
        $orig_height = imagesy($source_image);

        if ($effective_watermark_path && file_exists($effective_watermark_path)) {
            $original_watermark_img = @imagecreatefrompng($effective_watermark_path); // Assuming watermark is PNG

            if ($original_watermark_img) {
                imagesavealpha($original_watermark_img, true);
                imagealphablending($original_watermark_img, true);
                $original_watermark_width = imagesx($original_watermark_img);
                $original_watermark_height = imagesy($original_watermark_img);

                $target_watermark_width_ratio = 0.60;
                $new_watermark_width = (int)($orig_width * $target_watermark_width_ratio);
                $new_watermark_height = ($original_watermark_width > 0) ? (int)($new_watermark_width * ($original_watermark_height / $original_watermark_width)) : 0;
                
                if ($new_watermark_width > 0 && $new_watermark_height > 0) {
                    $resized_watermark_img = imagecreatetruecolor($new_watermark_width, $new_watermark_height);
                    imagealphablending($resized_watermark_img, false);
                    imagesavealpha($resized_watermark_img, true);
                    $transparent_background = imagecolorallocatealpha($resized_watermark_img, 0, 0, 0, 127);
                    imagefill($resized_watermark_img, 0, 0, $transparent_background);
                    imagealphablending($resized_watermark_img, true);
                    
                    imagecopyresampled($resized_watermark_img, $original_watermark_img, 0, 0, 0, 0, $new_watermark_width, $new_watermark_height, $original_watermark_width, $original_watermark_height);
                    imagedestroy($original_watermark_img);

                    $dest_x = ($orig_width - $new_watermark_width) / 2;
                    $dest_y = ($orig_height - $new_watermark_height) / 2;
                    imagecopy($source_image, $resized_watermark_img, (int)$dest_x, (int)$dest_y, 0, 0, $new_watermark_width, $new_watermark_height);
                    imagedestroy($resized_watermark_img);
                } else {
                     error_log("[ImageProcess] Watermark new dimensions are invalid. Skipping watermark resize.");
                }
            } else {
                error_log("[ImageProcess] Watermark image not found or failed to load: {$effective_watermark_path}. Skipping watermarking.");
            }
        }

        $save_success = false;
        // Use constants defined for compression quality/level
        $jpeg_quality = defined('IMAGE_COMPRESSION_QUALITY_JPEG') ? IMAGE_COMPRESSION_QUALITY_JPEG : 75;
        $png_compression = defined('IMAGE_COMPRESSION_LEVEL_PNG') ? IMAGE_COMPRESSION_LEVEL_PNG : 6;

        if ($file_extension === 'jpg' || $file_extension === 'jpeg') {
            $save_success = @imagejpeg($source_image, $destination_path, $jpeg_quality);
        } elseif ($file_extension === 'png') {
            @imagesavealpha($source_image, true);
            $save_success = @imagepng($source_image, $destination_path, $png_compression);
        } elseif ($file_extension === 'gif') {
            $save_success = @imagegif($source_image, $destination_path);
        }

        if (is_resource($source_image) || (function_exists('is_gd_image') && is_gd_image($source_image))) {
            @imagedestroy($source_image);
        }
        
        if (!$save_success) {
            error_log("[ImageProcess] GD: Failed to save processed image to {$destination_path} using GD.");
        }
        return $save_success;

    } catch (Throwable $e) {
        error_log("[ImageProcess] Exception during image processing for {$temp_path}: " . $e->getMessage());
        if (isset($source_image) && (is_resource($source_image) || (function_exists('is_gd_image') && is_gd_image($source_image)))) {
             @imagedestroy($source_image);
        }
        // Fallback to move the original file
         if (is_uploaded_file($temp_path)) {
            error_log("[ImageProcessFallback] Attempting to move original file due to exception: {$temp_path} to {$destination_path}");
            return move_uploaded_file($temp_path, $destination_path);
        } else {
            error_log("[ImageProcessFallback] Original temp file {$temp_path} is no longer valid or does not exist after exception. Cannot move.");
            return false;
        }
    }
}

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

?>