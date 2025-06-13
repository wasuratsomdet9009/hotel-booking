<?php
session_start();
require_once __DIR__ . '/../bootstrap.php'; // For DB connection

// --- CONFIGURATION ---
const DEV_PASSWORD = 'kaokam9119@kao';
const SESSION_KEY = 'all_control_logged_in';
const ROWS_PER_PAGE = 50; // จำนวนแถวที่แสดงต่อหน้า

// --- SECURITY & AJAX HANDLING ---
// Handle AJAX requests for updating and deleting data
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    if (!isset($_SESSION[SESSION_KEY]) || $_SESSION[SESSION_KEY] !== true) {
        echo json_encode(['success' => false, 'message' => 'Session expired. Please log in again.']);
        exit;
    }

    $action = $_POST['action'];
    $table = $_POST['table'] ?? '';
    $id = $_POST['id'] ?? 0;
    
    // Whitelist of tables to prevent arbitrary table manipulation
    $allowed_tables = ['rooms', 'bookings', 'booking_groups', 'users', 'addon_services', 'booking_group_receipts', 'archives', 'archive_addons', 'system_settings'];
    if (!in_array($table, $allowed_tables)) {
        echo json_encode(['success' => false, 'message' => 'Table not allowed.']);
        exit;
    }

    try {
        if ($action === 'update') {
            $column = $_POST['column'] ?? '';
            $value = $_POST['value'] ?? '';
            
            // Whitelist columns as well for extra security
            $stmtCols = $pdo->query("DESCRIBE `$table`");
            $allowed_columns = array_column($stmtCols->fetchAll(PDO::FETCH_ASSOC), 'Field');
            if (!in_array($column, $allowed_columns)) {
                echo json_encode(['success' => false, 'message' => 'Column not allowed.']);
                exit;
            }

            // The primary key column might not always be 'id'
            $pk_column = ($table === 'system_settings') ? 'setting_key' : 'id';

            $sql = "UPDATE `" . $table . "` SET `" . $column . "` = ? WHERE `".$pk_column."` = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$value, $id]);
            echo json_encode(['success' => true, 'message' => "Table '$table' updated successfully."]);

        } elseif ($action === 'delete') {
            $pk_column = ($table === 'system_settings') ? 'setting_key' : 'id';
            $sql = "DELETE FROM `" . $table . "` WHERE `".$pk_column."` = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$id]);
            if ($stmt->rowCount() > 0) {
                 echo json_encode(['success' => true, 'message' => "Row from '$table' with ID $id deleted."]);
            } else {
                 echo json_encode(['success' => false, 'message' => "Row with ID $id not found in table '$table'. No rows deleted."]);
            }
        }
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database Error: ' . $e->getMessage()]);
    }
    exit;
}

// --- PASSWORD PROTECTION ---
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
    // --- LOGIN FORM ---
    ?>
    <!DOCTYPE html>
    <html lang="th">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
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
            <h2>Developer Control Panel</h2>
            <p>กรุณาใส่รหัสผ่านเพื่อเข้าถึง</p>
            <?php if (isset($error)) echo "<p class='error'>$error</p>"; ?>
            <form method="post">
                <input type="password" name="password" required autofocus>
                <br>
                <button type="submit">เข้าสู่ระบบ</button>
            </form>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// --- LOGGED IN - MAIN PAGE ---
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>All Control Panel</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; background-color: #1e1e1e; color: #d4d4d4; margin: 0; padding: 20px; }
        h1, h2 { color: #58a6ff; border-bottom: 2px solid #333; padding-bottom: 10px; }
        h2 { margin-top: 40px; }
        .table-container { overflow-x: auto; }
        table { border-collapse: collapse; width: 100%; margin-bottom: 20px; font-size: 13px; }
        th, td { border: 1px solid #444; padding: 8px 12px; text-align: left; vertical-align: top; }
        th { background-color: #2a2a2a; }
        tr:nth-child(even) { background-color: #2c2c2c; }
        td:hover { background-color: #333; cursor: pointer; }
        .editable-cell input, .editable-cell select { width: 95%; background: #111; color: #fff; border: 1px solid #58a6ff; padding: 4px; }
        .action-buttons button { margin: 0 2px; padding: 2px 5px; font-size: 11px; cursor: pointer; }
        .btn-delete { background-color: #e74c3c; color: white; border: none; border-radius: 3px; }
        .pagination { padding: 10px 0; }
        .pagination a { color: #58a6ff; margin: 0 5px; text-decoration: none; }
        .pagination strong { margin: 0 5px; }
        .logout-btn { position: fixed; top: 15px; right: 20px; background-color: #e74c3c; color: white; padding: 8px 15px; text-decoration: none; border-radius: 5px; }
    </style>
</head>
<body>
    <a href="?logout=1" class="logout-btn">ออกจากระบบ</a>
    <h1>Developer Control Panel</h1>
    <p>คลิกที่ Cell เพื่อแก้ไขข้อมูล | **คำเตือน:** การแก้ไขข้อมูลในหน้านี้มีผลต่อฐานข้อมูลโดยตรง โปรดใช้ความระมัดระวังสูงสุด</p>

    <?php
    $tables_to_show = ['rooms', 'bookings', 'booking_groups', 'users', 'addon_services', 'booking_group_receipts', 'archives', 'archive_addons', 'system_settings'];
    $enum_columns = [
        'rooms' => ['status' => ['free', 'booked', 'occupied'], 'zone' => ['A', 'B', 'C', 'F']],
        'bookings' => ['booking_type' => ['overnight', 'short_stay'], 'payment_method' => ['เงินสด', 'เงินโอน', 'บัตรเครดิต', 'อื่นๆ']],
        'users' => ['role' => ['admin', 'staff'], 'is_active' => [0, 1]],
        'addon_services' => ['is_active' => [0, 1]]
    ];

    foreach ($tables_to_show as $table) {
        $pk_column = ($table === 'system_settings') ? 'setting_key' : 'id';
        $page = isset($_GET[$table.'_page']) ? (int)$_GET[$table.'_page'] : 1;
        $offset = ($page - 1) * ROWS_PER_PAGE;

        // Get total rows for pagination
        $total_rows_stmt = $pdo->query("SELECT COUNT(*) FROM `$table`");
        $total_rows = $total_rows_stmt->fetchColumn();
        $total_pages = ceil($total_rows / ROWS_PER_PAGE);

        $stmt = $pdo->query("SELECT * FROM `$table` ORDER BY `$pk_column` DESC LIMIT " . ROWS_PER_PAGE . " OFFSET " . $offset);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo "<h2>Table: `$table` <small>($total_rows rows)</small></h2>";

        if (empty($rows)) {
            echo "<p>No data found in this table.</p>";
            continue;
        }

        echo '<div class="table-container">';
        echo "<table>";
        // Header
        echo "<thead><tr>";
        foreach (array_keys($rows[0]) as $column) {
            echo "<th>" . h($column) . "</th>";
        }
        echo "<th>Actions</th>";
        echo "</tr></thead>";
        
        // Body
        echo "<tbody>";
        foreach ($rows as $row) {
            $pk_value = $row[$pk_column];
            echo "<tr data-row-id='$pk_value'>";
            foreach ($row as $col_name => $col_val) {
                $is_editable = ($col_name !== $pk_column);
                echo "<td " . ($is_editable ? "class='editable' " : "") . "data-table='$table' data-column='$col_name' data-pk='$pk_value' data-pk-name='$pk_column'>" . h($col_val) . "</td>";
            }
            echo "<td class='actions-cell'><button class='btn-delete' data-table='$table' data-pk='$pk_value' data-pk-name='$pk_column'>Delete</button></td>";
            echo "</tr>";
        }
        echo "</tbody></table></div>";

        // Pagination
        if ($total_pages > 1) {
            echo "<div class='pagination'>";
            for ($i = 1; $i <= $total_pages; $i++) {
                if ($i == $page) {
                    echo "<strong>$i</strong>";
                } else {
                    echo "<a href='?{$table}_page=$i'>$i</a>";
                }
            }
            echo "</div>";
        }
    }
    ?>

    <script>
    document.addEventListener('click', function(e) {
        // --- Edit Cell Logic ---
        if (e.target && e.target.classList.contains('editable')) {
            const cell = e.target;
            // Prevent re-clicking if already in edit mode
            if (cell.querySelector('input, select')) {
                return;
            }

            const originalValue = cell.textContent.trim();
            const table = cell.dataset.table;
            const column = cell.dataset.column;
            const pk = cell.dataset.pk;
            const pkName = cell.dataset.pkName;

            cell.innerHTML = ''; // Clear cell

            // Check if column is an enum type
            const enumOptions = <?php echo json_encode($enum_columns); ?>;
            let editor;
            if (enumOptions[table] && enumOptions[table][column]) {
                editor = document.createElement('select');
                enumOptions[table][column].forEach(opt => {
                    const optionEl = document.createElement('option');
                    optionEl.value = opt;
                    optionEl.textContent = opt;
                    if (opt == originalValue) {
                        optionEl.selected = true;
                    }
                    editor.appendChild(optionEl);
                });
            } else {
                editor = document.createElement('input');
                editor.type = 'text';
                editor.value = originalValue;
            }
            
            cell.appendChild(editor);
            editor.focus();

            // Save or cancel
            const saveBtn = document.createElement('button');
            saveBtn.textContent = 'Save';
            const cancelBtn = document.createElement('button');
            cancelBtn.textContent = 'Cancel';
            
            const actionsDiv = document.createElement('div');
            actionsDiv.className = 'action-buttons';
            actionsDiv.appendChild(saveBtn);
            actionsDiv.appendChild(cancelBtn);
            cell.appendChild(actionsDiv);

            cancelBtn.onclick = () => {
                cell.innerHTML = originalValue;
            };

            saveBtn.onclick = () => {
                const newValue = editor.value;
                const formData = new FormData();
                formData.append('action', 'update');
                formData.append('table', table);
                formData.append('column', column);
                formData.append('id', pk);
                formData.append('value', newValue);

                fetch('all_control.php', { method: 'POST', body: formData })
                    .then(res => res.json())
                    .then(data => {
                        if (data.success) {
                            cell.innerHTML = newValue;
                        } else {
                            alert('Error: ' + data.message);
                            cell.innerHTML = originalValue;
                        }
                    })
                    .catch(err => {
                        alert('AJAX Error: ' + err);
                        cell.innerHTML = originalValue;
                    });
            };
        }

        // --- Delete Row Logic ---
        if (e.target && e.target.classList.contains('btn-delete')) {
            const btn = e.target;
            const table = btn.dataset.table;
            const pk = btn.dataset.pk;

            if (confirm(`Are you sure you want to delete row with ID/Key '${pk}' from table '${table}'? This cannot be undone.`)) {
                const formData = new FormData();
                formData.append('action', 'delete');
                formData.append('table', table);
                formData.append('id', pk);
                
                fetch('all_control.php', { method: 'POST', body: formData })
                    .then(res => res.json())
                    .then(data => {
                        if (data.success) {
                            const row = btn.closest('tr');
                            row.style.transition = 'opacity 0.5s';
                            row.style.opacity = '0';
                            setTimeout(() => row.remove(), 500);
                        } else {
                            alert('Error: ' + data.message);
                        }
                    })
                    .catch(err => alert('AJAX Error: ' + err));
            }
        }
    });
    </script>
</body>
</html>