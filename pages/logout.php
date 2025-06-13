<?php
// FILEX: hotel_booking/pages/logout.php
require_once __DIR__ . '/../bootstrap.php'; // To ensure session_start() is called

$_SESSION = array(); // Clear all session variables

if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

session_destroy();

header('Location: ' . LOGIN_PAGE);
exit;
?>