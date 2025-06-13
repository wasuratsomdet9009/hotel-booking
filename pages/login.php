<?php
// FILEX: hotel_booking/pages/login.php
require_once __DIR__ . '/../bootstrap.php'; // For DB connection and session start

$pageTitle = 'ลงชื่อเข้าใช้ระบบ';
$login_error = '';

// ดึงรายชื่อผู้ใช้ทั้งหมดสำหรับ Dropdown (เฉพาะที่ active)
$users_stmt = $pdo->query("SELECT id, username, role FROM users WHERE is_active = 1 ORDER BY username ASC");
$available_users = $users_stmt->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $selected_user_id = $_POST['user_id'] ?? null;
    $password = $_POST['password'] ?? null;

    if ($selected_user_id) {
        $stmt = $pdo->prepare("SELECT id, username, password_hash, role FROM users WHERE id = ? AND is_active = 1");
        $stmt->execute([$selected_user_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            if ($user['role'] === 'admin') {
                if (empty($password)) {
                    $login_error = 'กรุณากรอกรหัสผ่านสำหรับผู้ดูแล';
                } elseif (password_verify($password, $user['password_hash'])) {
                    // Admin login success
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['user_role'] = $user['role'];
                    session_regenerate_id(true); // ป้องกัน session fixation
                    header('Location: ' . DASHBOARD_PAGE);
                    exit;
                } else {
                    $login_error = 'รหัสผ่านผู้ดูแลไม่ถูกต้อง';
                }
            } elseif ($user['role'] === 'staff') {
                // Staff login success (no password check per requirement)
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['user_role'] = $user['role'];
                session_regenerate_id(true);
                header('Location: ' . DASHBOARD_PAGE);
                exit;
            }
        } else {
            $login_error = 'ไม่พบผู้ใช้งานที่เลือก หรือผู้ใช้ถูกปิดการใช้งาน';
        }
    } else {
        $login_error = 'กรุณาเลือกชื่อผู้ใช้';
    }
}

ob_start();
?>
<style>
    body { display: flex; justify-content: center; align-items: center; min-height: 100vh; background-color: var(--color-bg); }
    .login-container { background-color: var(--color-surface); padding: 2rem; border-radius: var(--border-radius-lg); box-shadow: var(--shadow-lg); width: 100%; max-width: 400px; }
    .login-container h2 { text-align: center; color: var(--color-primary-dark); margin-bottom: 1.5rem; }
    .form-group { margin-bottom: 1.25rem; }
    .form-group label { display: block; margin-bottom: 0.4rem; font-weight: 500; }
    .form-group select, .form-group input[type="password"] { width: 100%; padding: 0.7rem; border: 1px solid var(--color-border); border-radius: var(--border-radius-md); }
    .error-message { color: var(--color-error-text); background-color: var(--color-error-bg); border: 1px solid var(--color-error-border); padding: 0.75rem; border-radius: var(--border-radius-md); margin-bottom: 1rem; text-align: center;}
</style>

<div class="login-container">
    <h2>เข้าสู่ระบบจัดการโรงแรม</h2>
    <?php if ($login_error): ?>
        <p class="error-message"><?= h($login_error) ?></p>
    <?php endif; ?>
    <form method="POST" action="login.php">
        <div class="form-group">
            <label for="user_id">เลือกชื่อผู้ใช้:</label>
            <select name="user_id" id="user_id" required onchange="togglePasswordField(this)">
                <option value="">-- กรุณาเลือก --</option>
                <?php foreach ($available_users as $u): ?>
                    <option value="<?= h($u['id']) ?>" data-role="<?= h($u['role']) ?>"><?= h($u['username']) ?> (<?= h(ucfirst($u['role'])) ?>)</option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group" id="password-group" style="display: none;">
            <label for="password">รหัสผ่าน (สำหรับผู้ดูแล):</label>
            <input type="password" name="password" id="password">
        </div>
        <button type="submit" class="button primary" style="width:100%;">เข้าสู่ระบบ</button>
    </form>
</div>

<script>
    function togglePasswordField(selectElement) {
        const selectedOption = selectElement.options[selectElement.selectedIndex];
        const role = selectedOption ? selectedOption.dataset.role : null;
        const passwordGroup = document.getElementById('password-group');
        const passwordInput = document.getElementById('password');

        if (role === 'admin') {
            passwordGroup.style.display = 'block';
            passwordInput.required = true;
        } else {
            passwordGroup.style.display = 'none';
            passwordInput.required = false;
            passwordInput.value = ''; // Clear password if not admin
        }
    }
    // Initialize on page load in case a user is pre-selected by browser
    const initialUserSelect = document.getElementById('user_id');
    if (initialUserSelect) {
        togglePasswordField(initialUserSelect);
    }
</script>
<?php
$content = ob_get_clean();
// For login page, we don't use the main layout.php, it's standalone.
// But it needs basic HTML structure.
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($pageTitle) ?></title>
    <link rel="stylesheet" href="/hotel_booking/assets/css/main.css">
    <link rel="icon" type="image/x-icon" href="/hotel_booking/assets/image/logo.ico">
</head>
<body>
    <?= $content ?>
</body>
</html>