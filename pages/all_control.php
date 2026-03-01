<?php
// --- START: CONFIGURATION & SETUP ---
session_start();
$script_start_time = microtime(true); // Start timing page load

require_once __DIR__ . '/../bootstrap.php'; // For DB connection

// --- CONFIGURATION ---
const DEV_PASSWORD = 'kaokam9119@kao'; // Please consider a stronger password or environment variables
const SESSION_KEY = 'all_control_logged_in';
const ROWS_PER_PAGE = 50;
const IMAGE_VIEWER_PATH = __DIR__ . '/../uploads/receipts'; // *** NEW: Path to scan for images
const IMAGE_VIEWER_URL = '../uploads/receipts';           // *** NEW: Public URL for images

// --- END: CONFIGURATION & SETUP ---


// --- START: AJAX REQUEST HANDLING ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    if (!isset($_SESSION[SESSION_KEY]) || $_SESSION[SESSION_KEY] !== true) {
        echo json_encode(['success' => false, 'message' => 'Session expired. Please log in again.']);
        exit;
    }

    $action = $_POST['action'];
    $allowed_tables = ['rooms', 'bookings', 'booking_groups', 'users', 'addon_services', 'booking_group_receipts', 'archives', 'archive_addons', 'system_settings'];

    try {
        if ($action === 'update') {
            $table = $_POST['table'] ?? '';
            $id = $_POST['id'] ?? 0;
            if (!in_array($table, $allowed_tables)) {
                echo json_encode(['success' => false, 'message' => 'Table not allowed.']);
                exit;
            }
            $column = $_POST['column'] ?? '';
            $value = $_POST['value'] ?? '';

            $stmtCols = $pdo->query("DESCRIBE `$table`");
            $allowed_columns = array_column($stmtCols->fetchAll(PDO::FETCH_ASSOC), 'Field');
            if (!in_array($column, $allowed_columns)) {
                echo json_encode(['success' => false, 'message' => 'Column not allowed.']);
                exit;
            }

            $pk_column = ($table === 'system_settings') ? 'setting_key' : 'id';
            $sql = "UPDATE `" . $table . "` SET `" . $column . "` = ? WHERE `" . $pk_column . "` = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$value, $id]);
            echo json_encode(['success' => true, 'message' => "Table '$table' updated successfully."]);

        } elseif ($action === 'delete') {
            $table = $_POST['table'] ?? '';
            $id = $_POST['id'] ?? 0;
            if (!in_array($table, $allowed_tables)) {
                echo json_encode(['success' => false, 'message' => 'Table not allowed.']);
                exit;
            }
            $pk_column = ($table === 'system_settings') ? 'setting_key' : 'id';
            $sql = "DELETE FROM `" . $table . "` WHERE `" . $pk_column . "` = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$id]);
            if ($stmt->rowCount() > 0) {
                 echo json_encode(['success' => true, 'message' => "Row from '$table' with ID $id deleted."]);
            } else {
                 echo json_encode(['success' => false, 'message' => "Row with ID $id not found in table '$table'. No rows deleted."]);
            }
        } elseif ($action === 'delete_image') {
            $filename = $_POST['filename'] ?? '';

            // Security: Prevent directory traversal. Only allow a filename without slashes.
            if (empty($filename) || basename($filename) !== $filename) {
                echo json_encode(['success' => false, 'message' => 'Invalid filename.']);
                exit;
            }
            $filepath = IMAGE_VIEWER_PATH . '/' . $filename;

            if (file_exists($filepath)) {
                if (@unlink($filepath)) {
                    echo json_encode(['success' => true, 'message' => 'Image deleted successfully.']);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Could not delete the image. Please check file permissions.']);
                }
            } else {
                echo json_encode(['success' => false, 'message' => 'Image not found on the server.']);
            }
        } elseif ($action === 'get_server_stats') {
            // Function to recursively get the size of a directory.
            function getDirectorySize($path) {
                if (!is_dir($path)) return 0;
                $bytes = 0;
                try {
                    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
                    foreach ($iterator as $file) {
                        if ($file->isReadable()) {
                             $bytes += $file->getSize();
                        }
                    }
                } catch(Exception $e) {
                    // Could fail due to open_basedir restrictions
                    return 0;
                }
                return $bytes;
            }

            // Calculate the size of the entire project directory.
            $project_path = dirname(__DIR__);
            $used_space_bytes = getDirectorySize($project_path);

            $total_space_gb = 20; // As specified by user
            $total_space_bytes = $total_space_gb * 1024 * 1024 * 1024;

            $percentage_used = ($total_space_bytes > 0) ? ($used_space_bytes / $total_space_bytes) * 100 : 0;

            echo json_encode([
                'success' => true,
                'total_space_gb' => $total_space_gb,
                'used_space_gb' => round($used_space_bytes / (1024*1024*1024), 2),
                'percentage_used' => round($percentage_used, 2)
            ]);
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Server Error: ' . $e->getMessage()]);
    }
    exit;
}
// --- END: AJAX REQUEST HANDLING ---


// --- START: AUTHENTICATION & LOGIN PAGE ---
if (isset($_POST['password'])) {
    if ($_POST['password'] === DEV_PASSWORD) {
        $_SESSION[SESSION_KEY] = true;
        header('Location: all_control.php');
        exit;
    } else {
        $error = 'รหัสผ่านไม่ถูกต้อง!';
    }
}

if (isset($_GET['logout'])) {
    unset($_SESSION[SESSION_KEY]);
    header('Location: all_control.php');
    exit;
}

if (!isset($_SESSION[SESSION_KEY]) || $_SESSION[SESSION_KEY] !== true) {
    // Render Login Form if not logged in
    ?>
    <!DOCTYPE html>
    <html lang="th">
    <head>
        <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Developer Access</title>
        <style>
            body { font-family: sans-serif; background: #2c3e50; color: #ecf0f1; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; }
            .login-box { background: #34495e; padding: 40px; border-radius: 8px; box-shadow: 0 10px 25px rgba(0,0,0,0.3); text-align: center; }
            input[type="password"] { padding: 10px; border: 2px solid #7f8c8d; background: #2c3e50; color: white; border-radius: 4px; margin: 10px 0; width: 200px; }
            button { padding: 10px 20px; border: none; background: #2980b9; color: white; border-radius: 4px; cursor: pointer; }
            .error { color: #e74c3c; }
        </style>
    </head>
    <body>
        <div class="login-box">
            <h2>Developer Control Panel</h2><p>กรุณาใส่รหัสผ่านเพื่อเข้าถึง</p>
            <?php if (isset($error)) echo "<p class='error'>$error</p>"; ?>
            <form method="post">
                <input type="password" name="password" required autofocus><br>
                <button type="submit">เข้าสู่ระบบ</button>
            </form>
        </div>
    </body>
    </html>
    <?php
    exit;
}
// --- END: AUTHENTICATION & LOGIN PAGE ---


// --- START: MAIN PAGE SETUP (LOGGED IN) ---
$tables_to_show = ['rooms', 'bookings', 'booking_groups', 'users', 'addon_services', 'booking_group_receipts', 'archives', 'archive_addons', 'system_settings'];
$enum_columns = [
    'rooms' => ['status' => ['free', 'booked', 'occupied'], 'zone' => ['A', 'B', 'C', 'F']],
    'bookings' => ['booking_type' => ['overnight', 'short_stay'], 'payment_method' => ['เงินสด', 'เงินโอน', 'บัตรเครดิต', 'อื่นๆ']],
    'users' => ['role' => ['admin', 'staff'], 'is_active' => [0, 1]],
    'addon_services' => ['is_active' => [0, 1]]
];

function format_bytes($bytes) {
    if ($bytes >= 1048576) return number_format($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024) return number_format($bytes / 1024, 2) . ' KB';
    return $bytes . ' bytes';
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>All Control Panel</title>
    <style>
        :root { --main-bg: #1e1e1e; --text-color: #d4d4d4; --primary-color: #58a6ff; --border-color: #444; --header-bg: #2a2a2a; --row-alt-bg: #2c2c2c; --hover-bg: #3a3d41; --danger-color: #f44747; --success-color: #4CAF50; --warning-color: #ffca58; }
        html { scroll-behavior: smooth; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; background-color: var(--main-bg); color: var(--text-color); margin: 0; padding: 20px; padding-bottom: 220px; /* Space for fixed panels */ }
        .header { display: flex; justify-content: space-between; align-items: center; padding-bottom: 10px; border-bottom: 2px solid var(--border-color); margin-bottom: 20px; flex-wrap: wrap; gap: 10px; }
        h1 { color: var(--primary-color); margin: 0; }
        .header-meta { display: flex; gap: 20px; align-items: center; }
        .load-time { font-size: 0.9em; color: #888; }
        .logout-btn { background-color: var(--danger-color); color: white; padding: 8px 15px; text-decoration: none; border-radius: 5px; }
        .quick-nav { background: var(--header-bg); padding: 10px; border-radius: 5px; margin-bottom: 25px; }
        .quick-nav a { color: var(--primary-color); text-decoration: none; margin-right: 15px; }
        .quick-nav a:hover { text-decoration: underline; }
        .warning { background-color: #49310a; border: 1px solid #c2810a; color: var(--warning-color); padding: 15px; border-radius: 5px; margin-bottom: 20px; }
        section { scroll-margin-top: 20px; }
        h2 { color: var(--primary-color); margin-top: 40px; border-bottom: 1px solid var(--border-color); padding-bottom: 8px; }
        .table-controls { display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; }
        .filter-box input { background: #333; border: 1px solid #555; color: white; padding: 8px; border-radius: 4px; }
        .filter-box button { background: #555; border: none; color: white; padding: 8px 12px; border-radius: 4px; cursor: pointer; margin-left: 5px;}
        .table-container { max-height: 60vh; overflow: auto; border: 1px solid var(--border-color); border-radius: 5px;}
        table { border-collapse: collapse; width: 100%; font-size: 13px; }
        thead th { position: sticky; top: 0; background-color: var(--header-bg); z-index: 10; }
        th, td { border: 1px solid var(--border-color); padding: 8px 12px; text-align: left; vertical-align: top; white-space: nowrap; }
        tr:nth-child(even) { background-color: var(--row-alt-bg); }
        .editable:hover { background-color: var(--hover-bg); cursor: pointer; }
        .editable-cell input, .editable-cell select { width: 95%; background: #111; color: #fff; border: 1px solid var(--primary-color); padding: 4px; }
        input[type="datetime-local"] { color-scheme: dark; }
        .action-buttons button { margin: 2px; padding: 2px 5px; font-size: 11px; cursor: pointer; border-radius: 3px; border: none; }
        .btn-delete { background-color: var(--danger-color); color: white; }
        .pagination { padding: 15px 0; }
        .pagination a, .pagination strong { display: inline-block; padding: 5px 10px; background: var(--header-bg); border: 1px solid var(--border-color); color: var(--primary-color); margin: 0 2px; text-decoration: none; border-radius: 4px; }
        .pagination strong { background: var(--primary-color); color: var(--main-bg); border-color: var(--primary-color); }
        
        /* Image Viewer Styles */
        .bulk-actions { background-color: var(--header-bg); padding: 15px; border-radius: 5px; margin-bottom: 20px; border: 1px solid var(--border-color); }
        .bulk-actions h4 { margin-top: 0; margin-bottom: 10px; }
        .bulk-actions .action-group { display: flex; align-items: center; gap: 10px; }
        .bulk-actions .action-group:not(:last-child) { margin-bottom: 10px; }
        .bulk-actions input[type="number"] { width: 60px; background: #333; border: 1px solid #555; color: white; padding: 8px; border-radius: 4px; }
        .bulk-actions button { background-color: var(--primary-color); color: var(--main-bg); border: none; padding: 8px 12px; border-radius: 4px; cursor: pointer; }
        .bulk-actions button.btn-danger { background-color: var(--danger-color); color: white; }
        .image-viewer-controls { margin-bottom: 15px; display: flex; gap: 10px; flex-wrap: wrap; }
        .image-viewer-controls input, .image-viewer-controls select { background: #333; border: 1px solid #555; color: white; padding: 8px; border-radius: 4px; }
        .image-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 15px; }
        .image-card { position: relative; background: var(--row-alt-bg); border: 1px solid var(--border-color); border-radius: 5px; text-align: center; padding: 10px; font-size: 12px; transition: box-shadow 0.2s, border-color 0.2s; }
        .image-card.selected { box-shadow: 0 0 0 3px var(--primary-color); border-color: var(--primary-color); }
        .image-card .selection-checkbox { position: absolute; top: 5px; left: 5px; width: 18px; height: 18px; cursor: pointer; z-index: 5; }
        .image-card img { max-width: 100%; height: 100px; object-fit: cover; border-radius: 4px; margin-bottom: 8px; }
        .image-card .filename { word-wrap: break-word; color: var(--primary-color); }
        .image-card .filemeta { color: #999; margin-top: 5px; }
        .image-card .delete-img-btn { position: absolute; top: 5px; right: 5px; background-color: var(--danger-color); color: white; border: none; border-radius: 50%; width: 20px; height: 20px; font-size: 12px; line-height: 20px; cursor: pointer; opacity: 0; transition: opacity 0.2s; padding: 0; z-index: 5; }
        .image-card:hover .delete-img-btn { opacity: 1; }

        /* Floating Panels */
        #log-panel, #resource-panel, #server-resource-panel { position: fixed; width: 350px; background-color: rgba(20, 20, 20, 0.9); border: 1px solid var(--border-color); border-radius: 5px; z-index: 1000; font-size: 12px; backdrop-filter: blur(5px); }
        #log-panel { right: 10px; bottom: 10px; max-height: 250px; display: flex; flex-direction: column; }
        #resource-panel { left: 10px; bottom: 10px; }
        #server-resource-panel { left: 10px; bottom: 115px; }
        #log-panel h5, #resource-panel h5, #server-resource-panel h5 { margin: 0; padding: 8px 10px; background-color: var(--header-bg); border-bottom: 1px solid var(--border-color); }
        #log-entries { padding: 10px; overflow-y: auto; flex-grow: 1; }
        #resource-panel-content, #server-resource-content { padding: 10px; }
        .log-entry { margin-bottom: 5px; padding-bottom: 5px; border-bottom: 1px solid #333; }
        .log-entry:last-child { border-bottom: none; }
        .log-entry .timestamp { color: #888; margin-right: 5px; }
        .log-entry.log-success { color: var(--success-color); }
        .log-entry.log-error { color: var(--danger-color); }
        .log-entry.log-info { color: var(--primary-color); }
        #resource-panel-content div, #server-resource-content div { display: flex; justify-content: space-between; padding: 4px 0; }
        .progress-bar-container { background-color: #333; border-radius: 5px; height: 10px; margin-top: 5px; overflow: hidden; padding: 0 !important; }
        .progress-bar { background-color: var(--primary-color); height: 100%; width: 0%; transition: width 0.5s ease-in-out, background-color 0.5s; }
    </style>
</head>
<body>

    <header class="header">
        <h1>Developer Control Panel</h1>
        <div class="header-meta">
            <span class="load-time">Page loaded in: <?php echo round((microtime(true) - $script_start_time) * 1000, 2); ?> ms</span>
            <a href="?logout=1" class="logout-btn">ออกจากระบบ</a>
        </div>
    </header>

    <nav class="quick-nav">
        <strong>Jump to:</strong>
        <?php foreach ($tables_to_show as $table): ?>
            <a href="#table-<?php echo h($table); ?>"><?php echo h($table); ?></a>
        <?php endforeach; ?>
        <a href="#image-viewer">Image Viewer</a>
    </nav>

    <div class="warning">
        <strong>คำเตือน:</strong> การแก้ไขข้อมูลในหน้านี้มีผลต่อฐานข้อมูลโดยตรง โปรดใช้ความระมัดระวังสูงสุด
    </div>

    <?php
    // --- START: DATABASE TABLE DISPLAY ---
    foreach ($tables_to_show as $table) {
        echo "<section id='table-" . h($table) . "'>";
        $pk_column = ($table === 'system_settings') ? 'setting_key' : 'id';
        $page = isset($_GET[$table.'_page']) ? (int)$_GET[$table.'_page'] : 1;
        $offset = ($page - 1) * ROWS_PER_PAGE;

        $total_rows_stmt = $pdo->query("SELECT COUNT(*) FROM `$table`");
        $total_rows = $total_rows_stmt->fetchColumn();
        $total_pages = ceil($total_rows / ROWS_PER_PAGE);

        $stmt = $pdo->query("SELECT * FROM `$table` ORDER BY `$pk_column` DESC LIMIT " . ROWS_PER_PAGE . " OFFSET " . $offset);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo "<h2>Table: `" . h($table) . "` <small>(<span class='total-rows' data-table='".h($table)."'>$total_rows</span> rows)</small></h2>";

        echo "<div class='table-controls'><div class='filter-box'>";
        echo "<input type='text' class='table-filter' data-target-table='".h($table)."' placeholder='Filter Data...'>";
        echo "<button class='clear-filter' data-target-input='".h($table)."'>Clear</button>";
        echo "</div></div>";

        if (empty($rows)) {
            echo "<p>No data found in this table.</p>";
        } else {
            echo '<div class="table-container">';
            echo "<table data-table='".h($table)."'><thead><tr>";
            foreach (array_keys($rows[0]) as $column) {
                echo "<th>" . h($column) . "</th>";
            }
            echo "<th>Actions</th></tr></thead><tbody>";
            foreach ($rows as $row) {
                $pk_value = $row[$pk_column];
                echo "<tr data-row-id='" . h($pk_value) . "'>";
                foreach ($row as $col_name => $col_val) {
                    $is_editable = ($col_name !== $pk_column);
                    echo "<td " . ($is_editable ? "class='editable' " : "") . "data-table='".h($table)."' data-column='".h($col_name)."' data-pk='".h($pk_value)."' data-pk-name='".h($pk_column)."'>" . h($col_val) . "</td>";
                }
                echo "<td class='actions-cell'><button class='btn-delete' data-table='".h($table)."' data-pk='".h($pk_value)."' data-pk-name='".h($pk_column)."'>Delete</button></td>";
                echo "</tr>";
            }
            echo "</tbody></table></div>";
        }

        if ($total_pages > 1) {
            echo "<div class='pagination'>";
            for ($i = 1; $i <= $total_pages; $i++) {
                $activeClass = ($i == $page) ? 'strong' : 'a';
                echo "<{$activeClass} href='?{$table}_page=$i#table-".h($table)."'>$i</{$activeClass}>";
            }
            echo "</div>";
        }
        echo "</section>";
    }
    // --- END: DATABASE TABLE DISPLAY ---
    ?>

    <!-- --- START: IMAGE FILE VIEWER --- -->
    <section id="image-viewer">
        <h2>Image File Viewer</h2>
        <p>Scanning directory: <code><?php echo h(IMAGE_VIEWER_PATH); ?></code></p>
        
        <div class="bulk-actions">
            <h4>Bulk Actions</h4>
            <div class="action-group">
                <label for="days-old">Select images older than:</label>
                <input type="number" id="days-old" value="30" min="1">
                <span>days</span>
                <button id="select-old-btn">Select</button>
            </div>
            <div class="action-group" id="selection-controls" style="display: none;">
                 <button id="delete-selected-btn" class="btn-danger">Delete Selected</button>
                 <button id="clear-selection-btn">Clear Selection</button>
                 <span id="selection-count" style="margin-left: 10px; font-weight: bold;"></span>
            </div>
        </div>

        <div class="image-viewer-controls">
            <input type="text" id="image-filter" placeholder="Filter by filename...">
            <select id="image-sort">
                <option value="name_asc">Sort by Name (A-Z)</option>
                <option value="name_desc">Sort by Name (Z-A)</option>
                <option value="date_desc" selected>Sort by Date (Newest)</option>
                <option value="date_asc">Sort by Date (Oldest)</option>
            </select>
        </div>

        <div class="image-grid" id="image-grid-container">
            <?php
            $images = [];
            if (is_dir(IMAGE_VIEWER_PATH)) {
                $files = glob(IMAGE_VIEWER_PATH . '/*.{jpg,jpeg,png,gif,webp}', GLOB_BRACE);
                if ($files) {
                    foreach ($files as $file) {
                        $images[] = [
                            'path' => $file,
                            'url' => IMAGE_VIEWER_URL . '/' . basename($file),
                            'name' => basename($file),
                            'size' => filesize($file),
                            'modified' => filemtime($file)
                        ];
                    }
                }
            }
            
            if (empty($images)) {
                echo "<p>No images found in the specified directory.</p>";
            } else {
                usort($images, fn($a, $b) => $b['modified'] <=> $a['modified']);
                foreach ($images as $image) {
                ?>
                <div class="image-card" data-name="<?php echo h(strtolower($image['name'])); ?>" data-date="<?php echo $image['modified']; ?>">
                    <input type="checkbox" class="selection-checkbox" data-filename="<?php echo h($image['name']); ?>" title="Select this image">
                    <button class="delete-img-btn" data-filename="<?php echo h($image['name']); ?>" title="Delete Image">&times;</button>
                    <a href="<?php echo h($image['url']); ?>" target="_blank">
                        <img src="<?php echo h($image['url']); ?>" alt="<?php echo h($image['name']); ?>" loading="lazy">
                    </a>
                    <div class="filename"><?php echo h($image['name']); ?></div>
                    <div class="filemeta">
                        <?php echo format_bytes($image['size']); ?><br>
                        <?php echo date('Y-m-d H:i:s', $image['modified']); ?>
                    </div>
                </div>
                <?php
                }
            }
            ?>
        </div>
    </section>
    <!-- --- END: IMAGE FILE VIEWER --- -->

    <!-- FLOATING PANELS -->
    <div id="server-resource-panel">
        <h5>Server Resources</h5>
        <div id="server-resource-content">
             <div>
                <span>Disk Usage:</span>
                <span id="res-disk-usage">Loading...</span>
            </div>
            <div class="progress-bar-container">
                <div id="res-disk-progress" class="progress-bar"></div>
            </div>
        </div>
    </div>
    <div id="resource-panel">
        <h5>Browser Resources</h5>
        <div id="resource-panel-content">
            <div><span>Current Time:</span> <span id="res-time">--:--:--</span></div>
            <div><span>Memory Usage:</span> <span id="res-memory">N/A</span></div>
            <div><span>Visible Rows:</span> <span id="res-rows">0</span></div>
        </div>
    </div>
    <div id="log-panel">
        <h5>Live Activity Log (Session only)</h5>
        <div id="log-entries"></div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const mainContent = document.body;
        const logEntriesContainer = document.getElementById('log-entries');
        
        function logActivity(message, type = 'info') {
            const entry = document.createElement('div');
            entry.className = `log-entry log-${type}`;
            const timestamp = new Date().toLocaleTimeString();
            entry.innerHTML = `<span class="timestamp">${timestamp}</span> ${message}`;
            logEntriesContainer.prepend(entry);
        }

        document.querySelectorAll('.table-filter').forEach(input => {
            input.addEventListener('input', function() {
                const searchTerm = this.value.toLowerCase();
                const targetTable = this.dataset.targetTable;
                const tableBody = document.querySelector(`table[data-table="${targetTable}"] tbody`);
                if (!tableBody) return;
                tableBody.querySelectorAll('tr').forEach(row => {
                    row.style.display = row.textContent.toLowerCase().includes(searchTerm) ? '' : 'none';
                });
                updateVisibleRowCount();
            });
        });
        document.querySelectorAll('.clear-filter').forEach(button => {
            button.addEventListener('click', function() {
                const targetInputName = this.dataset.targetInput;
                const input = document.querySelector(`.table-filter[data-target-table="${targetInputName}"]`);
                if (input) {
                    input.value = '';
                    input.dispatchEvent(new Event('input'));
                }
            });
        });

        mainContent.addEventListener('click', function(e) {
            if (e.target && e.target.classList.contains('editable')) {
                const cell = e.target;
                if (cell.querySelector('input, select')) return;
                const originalValue = cell.textContent.trim();
                const table = cell.dataset.table;
                const column = cell.dataset.column;
                const pk = cell.dataset.pk;
                cell.innerHTML = '';
                const enumOptions = <?php echo json_encode($enum_columns); ?>;
                let editor;

                if (enumOptions[table] && enumOptions[table][column]) {
                    editor = document.createElement('select');
                    enumOptions[table][column].forEach(opt => {
                        const optionEl = document.createElement('option');
                        optionEl.value = opt; optionEl.textContent = opt;
                        if (String(opt) === originalValue) optionEl.selected = true;
                        editor.appendChild(optionEl);
                    });
                } else if (column.includes('date') || column.includes('_at') || column.includes('time')) {
                    editor = document.createElement('input');
                    editor.type = 'datetime-local';
                    editor.value = originalValue.replace(' ', 'T').substring(0, 16);
                } else {
                    editor = document.createElement('input');
                    editor.type = 'text'; editor.value = originalValue;
                }
                cell.appendChild(editor); editor.focus();

                const saveBtn = document.createElement('button'); saveBtn.textContent = 'Save';
                const cancelBtn = document.createElement('button'); cancelBtn.textContent = 'Cancel';
                const actionsDiv = document.createElement('div');
                actionsDiv.className = 'action-buttons';
                actionsDiv.appendChild(saveBtn); actionsDiv.appendChild(cancelBtn);
                cell.appendChild(actionsDiv);

                const restoreCell = () => { cell.innerHTML = originalValue; };
                cancelBtn.onclick = restoreCell;
                
                saveBtn.onclick = () => {
                    let newValue = editor.value;
                    if (editor.type === 'datetime-local' && newValue) {
                        newValue = newValue.replace('T', ' ') + ':00';
                    }
                    const formData = new FormData();
                    formData.append('action', 'update'); formData.append('table', table);
                    formData.append('column', column); formData.append('id', pk);
                    formData.append('value', newValue);

                    fetch('all_control.php', { method: 'POST', body: formData })
                        .then(res => res.json())
                        .then(data => {
                            if (data.success) { 
                                cell.innerHTML = newValue; 
                                logActivity(`Updated [${table}.${column}] for ID ${pk}.`, 'success');
                            } else { 
                                logActivity(`Update failed: ${data.message}`, 'error');
                                restoreCell(); 
                            }
                        })
                        .catch(err => { 
                            logActivity(`AJAX Error on update: ${err}`, 'error');
                            restoreCell(); 
                        });
                };
            }

            if (e.target && e.target.classList.contains('btn-delete')) {
                const btn = e.target;
                const table = btn.dataset.table;
                const pk = btn.dataset.pk;
                if (confirm(`Are you sure you want to delete row with ID/Key '${pk}' from table '${table}'?`)) {
                    const formData = new FormData();
                    formData.append('action', 'delete'); formData.append('table', table);
                    formData.append('id', pk);
                    fetch('all_control.php', { method: 'POST', body: formData })
                        .then(res => res.json())
                        .then(data => {
                            if (data.success) {
                                logActivity(`Deleted row ID ${pk} from [${table}].`, 'success');
                                const row = btn.closest('tr');
                                row.style.transition = 'opacity 0.5s';
                                row.style.opacity = '0';
                                setTimeout(() => {
                                    row.remove();
                                    const countSpan = document.querySelector(`.total-rows[data-table="${table}"]`);
                                    if(countSpan) countSpan.textContent = parseInt(countSpan.textContent) - 1;
                                    updateVisibleRowCount();
                                }, 500);
                            } else {
                                logActivity(`Delete failed: ${data.message}`, 'error');
                            }
                        })
                        .catch(err => logActivity(`AJAX Error on delete: ${err}`, 'error'));
                }
            }

            if (e.target && e.target.classList.contains('delete-img-btn')) {
                const btn = e.target;
                const filename = btn.dataset.filename;
                const card = btn.closest('.image-card');
                if (confirm(`Are you sure you want to permanently delete '${filename}'?`)) {
                    const formData = new FormData();
                    formData.append('action', 'delete_image');
                    formData.append('filename', filename);
                    fetch('all_control.php', { method: 'POST', body: formData })
                        .then(res => res.json())
                        .then(data => {
                            if (data.success) {
                                logActivity(`Deleted image [${filename}].`, 'success');
                                card.style.transition = 'opacity 0.5s, transform 0.5s';
                                card.style.opacity = '0';
                                card.style.transform = 'scale(0.8)';
                                setTimeout(() => card.remove(), 500);
                            } else { logActivity(`Image delete failed: ${data.message}`, 'error'); }
                        })
                        .catch(err => logActivity(`AJAX Error on image delete: ${err}`, 'error'));
                }
            }
        });

        // --- Image Viewer Specific Logic ---
        const imageFilterInput = document.getElementById('image-filter');
        const imageSortSelect = document.getElementById('image-sort');
        const imageGridContainer = document.getElementById('image-grid-container');
        const selectOldBtn = document.getElementById('select-old-btn');
        const deleteSelectedBtn = document.getElementById('delete-selected-btn');
        const clearSelectionBtn = document.getElementById('clear-selection-btn');
        const daysOldInput = document.getElementById('days-old');
        const selectionControls = document.getElementById('selection-controls');
        const selectionCountSpan = document.getElementById('selection-count');
        
        function updateImageView() {
            // Get all cards from the container into a JS array to manipulate
            const imageCards = Array.from(document.querySelectorAll('#image-grid-container .image-card'));
            if (imageCards.length === 0) return;

            // 1. Sort the array of card elements in memory
            const sortValue = imageSortSelect.value;
            imageCards.sort((a, b) => {
                const nameA = a.dataset.name, nameB = b.dataset.name;
                const dateA = parseInt(a.dataset.date), dateB = parseInt(b.dataset.date);
                switch (sortValue) {
                    case 'name_asc': return nameA.localeCompare(nameB);
                    case 'name_desc': return nameB.localeCompare(nameA);
                    case 'date_asc': return dateA - dateB;
                    case 'date_desc': default: return dateB - dateA;
                }
            });

            // 2. Get the current filter text
            const filterText = imageFilterInput.value.toLowerCase();

            // 3. Clear the actual grid container in the DOM
            imageGridContainer.innerHTML = '';

            // 4. Loop through the sorted array and append only the cards that match the filter
            imageCards.forEach(card => {
                if (card.dataset.name.includes(filterText)) {
                    imageGridContainer.appendChild(card);
                }
            });
        }

        function updateSelectionControls() {
            const selectedCheckboxes = imageGridContainer.querySelectorAll('.selection-checkbox:checked');
            const count = selectedCheckboxes.length;
            if (count > 0) {
                selectionControls.style.display = 'flex';
                selectionCountSpan.textContent = `${count} image(s) selected.`;
            } else {
                selectionControls.style.display = 'none';
                selectionCountSpan.textContent = '';
            }
        }

        imageGridContainer.addEventListener('change', e => {
            if (e.target.classList.contains('selection-checkbox')) {
                e.target.closest('.image-card').classList.toggle('selected', e.target.checked);
                updateSelectionControls();
            }
        });

        selectOldBtn.addEventListener('click', () => {
            const days = parseInt(daysOldInput.value);
            if (isNaN(days) || days <= 0) {
                alert('Please enter a valid number of days.');
                return;
            }
            const cutoffTimestamp = Math.floor(Date.now() / 1000) - (days * 86400);
            
            // Note: We query all cards from the document, not just the visible ones
            document.querySelectorAll('#image-grid-container .image-card').forEach(card => {
                const imageTimestamp = parseInt(card.dataset.date);
                const checkbox = card.querySelector('.selection-checkbox');
                const shouldBeSelected = imageTimestamp < cutoffTimestamp;
                
                checkbox.checked = shouldBeSelected;
                card.classList.toggle('selected', shouldBeSelected);
            });
            updateSelectionControls();
        });

        clearSelectionBtn.addEventListener('click', () => {
            imageGridContainer.querySelectorAll('.selection-checkbox:checked').forEach(checkbox => {
                checkbox.checked = false;
                checkbox.closest('.image-card').classList.remove('selected');
            });
            updateSelectionControls();
        });

        deleteSelectedBtn.addEventListener('click', () => {
            const selected = imageGridContainer.querySelectorAll('.selection-checkbox:checked');
            if (selected.length === 0) {
                alert('No images selected.');
                return;
            }
            if (!confirm(`Are you sure you want to permanently delete ${selected.length} image(s)?`)) return;

            const promises = Array.from(selected).map(checkbox => {
                const card = checkbox.closest('.image-card');
                const formData = new FormData();
                formData.append('action', 'delete_image');
                formData.append('filename', checkbox.dataset.filename);
                return fetch('all_control.php', { method: 'POST', body: formData })
                    .then(res => res.json())
                    .then(data => {
                        if (data.success) {
                            card.remove();
                        } else {
                            logActivity(`Failed to delete ${checkbox.dataset.filename}: ${data.message}`, 'error');
                        }
                    });
            });

            Promise.all(promises).then(() => {
                logActivity(`Deletion process finished for ${selected.length} images.`, 'success');
                updateSelectionControls();
            });
        });
        
        imageFilterInput.addEventListener('input', updateImageView);
        imageSortSelect.addEventListener('change', updateImageView);

        // --- Resource Panels Logic ---
        const resTime = document.getElementById('res-time');
        const resMemory = document.getElementById('res-memory');
        const resRows = document.getElementById('res-rows');
        const resDiskUsage = document.getElementById('res-disk-usage');
        const resDiskProgress = document.getElementById('res-disk-progress');
        
        function fetchServerStats() {
            const formData = new FormData();
            formData.append('action', 'get_server_stats');
            fetch('all_control.php', { method: 'POST', body: formData })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        resDiskUsage.textContent = `${data.used_space_gb} GB / ${data.total_space_gb} GB`;
                        resDiskProgress.style.width = `${data.percentage_used}%`;
                        if (data.percentage_used > 85) {
                            resDiskProgress.style.backgroundColor = 'var(--danger-color)';
                        } else if (data.percentage_used > 60) {
                            resDiskProgress.style.backgroundColor = 'var(--warning-color)';
                        } else {
                             resDiskProgress.style.backgroundColor = 'var(--primary-color)';
                        }
                    } else {
                        resDiskUsage.textContent = 'Error';
                        logActivity(`Server Stats Error: ${data.message}`, 'error');
                    }
                })
                .catch(err => {
                    resDiskUsage.textContent = 'AJAX Error';
                    logActivity(`Failed to fetch server stats: ${err}`, 'error');
                });
        }
        
        function updateVisibleRowCount() {
            let count = 0;
            document.querySelectorAll('.table-container tbody tr').forEach(row => {
                if(row.style.display !== 'none') count++;
            });
            resRows.textContent = count.toLocaleString();
        }

        setInterval(() => {
            resTime.textContent = new Date().toLocaleTimeString();
            if (performance.memory) {
                const used = (performance.memory.usedJSHeapSize / 1048576).toFixed(2);
                resMemory.textContent = `${used} MB`;
            }
        }, 1000);
        
        // Initial calls
        updateImageView();
        fetchServerStats();
        updateVisibleRowCount();
        logActivity('Control panel initialized.');
    });
    </script>
</body>
</html>