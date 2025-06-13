<?php
// pages/settings_management.php
require_once __DIR__ . '/../bootstrap.php';
require_admin(); // ตรวจสอบว่าเป็นผู้ดูแลหรือไม่

$pageTitle = 'จัดการการตั้งค่าระบบและราคาห้องพัก';

// ดึงข้อมูล services เสริมทั้งหมด
$stmt_addons = $pdo->query("SELECT * FROM addon_services ORDER BY name ASC");
$addon_services = $stmt_addons->fetchAll();

// ดึงข้อมูลราคาต่อชั่วโมงปัจจุบัน
$current_hourly_rate = get_system_setting_value($pdo, 'hourly_extension_rate', 100);

// ดึงข้อมูลห้องพักทั้งหมด จัดกลุ่มตามโซน
$stmt_all_rooms = $pdo->query("SELECT id, zone, room_number, price_per_day, price_short_stay, allow_short_stay, short_stay_duration_hours, price_per_hour_extension FROM rooms ORDER BY zone ASC, CAST(room_number AS UNSIGNED) ASC");
$all_rooms_data = $stmt_all_rooms->fetchAll(PDO::FETCH_ASSOC);
$rooms_by_zone = [];
foreach ($all_rooms_data as $room_item) {
    $rooms_by_zone[$room_item['zone']][] = $room_item;
}

// ดึงข้อมูลผู้ใช้ทั้งหมด
$users_list_stmt = $pdo->query("SELECT id, username, role, is_active, created_at FROM users ORDER BY username ASC");
$system_users = $users_list_stmt->fetchAll(PDO::FETCH_ASSOC);

ob_start();
?>

<h2><?= h($pageTitle) ?></h2>

<section id="manage-addons" class="settings-section">
    <h3>จัดการบริการเสริม (Chip Components)</h3>
    <form id="add-addon-form" class="form-inline-group">
        <h4>เพิ่มบริการเสริมใหม่</h4>
        <div class="form-group">
            <label for="new_addon_name">ชื่อบริการ:</label>
            <input type="text" id="new_addon_name" name="name" required class="form-control">
        </div>
        <div class="form-group">
            <label for="new_addon_price">ราคา (บาท):</label>
            <input type="number" id="new_addon_price" name="price" step="0.01" min="-10000" required class="form-control">
        </div>
        <button type="submit" class="button primary" id="submitAddAddonBtn">เพิ่มบริการเสริม</button>
    </form>

    <h4>รายการบริการเสริมที่มีอยู่</h4>
    <div class="table-responsive">
        <table id="addon-services-table" class="report-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>ชื่อบริการ</th>
                    <th>ราคา (บาท)</th>
                    <th>สถานะ</th>
                    <th>การดำเนินการ</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($addon_services)): ?>
                    <tr><td colspan="5" class="text-center">ยังไม่มีบริการเสริม</td></tr>
                <?php else: ?>
                    <?php foreach ($addon_services as $addon): ?>
                        <tr data-addon-id="<?= h($addon['id']) ?>">
                            <td><?= h($addon['id']) ?></td>
                            <td class="addon-name"><?= h($addon['name']) ?></td>
                            <td class="addon-price"><?= h(number_format((float)$addon['price'], 2)) ?></td>
                            <td class="addon-status"><?= $addon['is_active'] ? '<span class="text-success">ใช้งาน</span>' : '<span class="text-danger">ไม่ใช้งาน</span>' ?></td>
                            <td class="actions-cell">
                                <button class="button-small edit-addon-btn" data-id="<?=h($addon['id'])?>" data-name="<?=h($addon['name'])?>" data-price="<?=h((float)$addon['price'])?>">แก้ไข</button>
                                <button class="button-small toggle-addon-status-btn <?= $addon['is_active'] ? 'alert' : 'secondary' ?>" data-id="<?=h($addon['id'])?>">
                                    <?= $addon['is_active'] ? 'ปิดใช้งาน' : 'เปิดใช้งาน' ?>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<section id="manage-system-settings" class="settings-section" style="margin-top: 2rem;">
    <h3>จัดการการตั้งค่าระบบ</h3>
    <form id="update-hourly-rate-form" class="form-inline-group">
        <p>ราคาต่อชั่วโมง (ค่าเริ่มต้น): <strong id="current_hourly_extension_rate_display"><?= h(number_format((float)$current_hourly_rate, 2)) ?></strong> บาท/ชม.</p>
        <div class="form-group">
            <label for="hourly_rate_value">ราคาต่อชั่วโมงใหม่ (บาท):</label>
            <input type="number" id="hourly_rate_value" name="setting_value" value="<?= h((float)$current_hourly_rate) ?>" step="0.01" min="0" required class="form-control">
            <input type="hidden" name="setting_key" value="hourly_extension_rate">
        </div>
        <button type="submit" class="button primary" id="submitUpdateHourlyRateBtn">อัปเดตราคาต่อชั่วโมง</button>
    </form>
</section>

<section id="manage-room-prices" class="settings-section" style="margin-top: 2rem;">
    <h3>จัดการราคาห้องพัก (รายห้อง)</h3>
    <?php if (empty($rooms_by_zone)): ?>
        <p>ไม่พบข้อมูลห้องพัก</p>
    <?php else: ?>
        <?php foreach ($rooms_by_zone as $zone_key => $rooms_in_this_zone): ?>
            <h4 style="margin-top:1.5rem; padding-top:1rem; border-top:1px dashed #ccc;">โซน: <?= h($zone_key) ?></h4>
            <div class="table-responsive">
                <table class="report-table room-price-table">
                    <thead>
                        <tr>
                            <th>ห้อง</th>
                            <th>ราคาค้างคืน (บาท)</th>
                            <th>ราคาชั่วคราว (บาท)</th>
                            <th>ราคาเพิ่ม/ชม. (บาท)</th>
                            <th>ดำเนินการ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rooms_in_this_zone as $room_detail): ?>
                        <tr data-room-id="<?=h($room_detail['id'])?>">
                            <td><?= h($room_detail['zone'] . $room_detail['room_number']) ?></td>
                            <td><input type="number" class="form-control room-price-input" name="price_per_day_<?=h($room_detail['id'])?>" value="<?= h(number_format((float)($room_detail['price_per_day'] ?? 0), 2, '.', '')) ?>" step="0.01" min="0"></td>
                            <td><input type="number" class="form-control room-price-input" name="price_short_stay_<?=h($room_detail['id'])?>" value="<?= h(number_format((float)($room_detail['price_short_stay'] ?? 0), 2, '.', '')) ?>" step="0.01" min="0" <?= !$room_detail['allow_short_stay'] ? 'disabled' : '' ?>></td>
                            <td><input type="number" class="form-control room-price-input" name="price_per_hour_extension_<?=h($room_detail['id'])?>" value="<?= h(number_format((float)($room_detail['price_per_hour_extension'] ?? ($current_hourly_rate ?? 80)), 2, '.', '')) ?>" step="0.01" min="0"></td>
                            <td><button class="button-small save-room-price-btn" data-room-id="<?=h($room_detail['id'])?>">บันทึก</button></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</section>

<section id="manage-users" class="settings-section" style="margin-top: 2rem;">
    <h3>จัดการผู้ใช้งานระบบ</h3>
    <form id="add-user-form" class="form-inline-group" style="margin-bottom:1.5rem;">
        <h4>เพิ่มผู้ใช้ใหม่</h4>
        <div class="form-group">
            <label for="new_username">ชื่อผู้ใช้:</label>
            <input type="text" id="new_username" name="username" required class="form-control">
        </div>
        <div class="form-group">
            <label for="new_user_role">บทบาท:</label>
            <select name="role" id="new_user_role" required class="form-control">
                <option value="staff">พนักงาน (Staff)</option>
                <option value="admin">ผู้ดูแล (Admin)</option>
            </select>
        </div>
        <div class="form-group" id="new_user_password_group" style="display:none;">
            <label for="new_user_password">รหัสผ่าน (สำหรับ Admin):</label>
            <input type="password" id="new_user_password" name="password" class="form-control">
        </div>
        <button type="submit" class="button primary" id="submitAddUserBtn">เพิ่มผู้ใช้</button>
    </form>

    <h4>รายชื่อผู้ใช้งานในระบบ</h4>
    <div class="table-responsive">
        <table id="users-table" class="report-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>ชื่อผู้ใช้</th>
                    <th>บทบาท</th>
                    <th>สถานะ</th>
                    <th>สร้างเมื่อ</th>
                    <th>การดำเนินการ</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($system_users)): ?>
                    <tr><td colspan="6" class="text-center">ยังไม่มีผู้ใช้งานในระบบ</td></tr>
                <?php else: ?>
                    <?php foreach ($system_users as $sys_user): ?>
                        <tr data-user-id="<?= h($sys_user['id']) ?>">
                            <td><?= h($sys_user['id']) ?></td>
                            <td><?= h($sys_user['username']) ?></td>
                            <td><?= h(ucfirst($sys_user['role'])) ?></td>
                            <td><?= $sys_user['is_active'] ? '<span class="text-success">ใช้งาน</span>' : '<span class="text-danger">ปิดใช้งาน</span>' ?></td>
                            <td><?= h(date('d/m/Y H:i', strtotime($sys_user['created_at']))) ?></td>
                            <td class="actions-cell">
                                <?php if (function_exists('get_current_user_id') && $sys_user['id'] != get_current_user_id()): ?>
                                <button class="button-small toggle-user-status-btn <?= $sys_user['is_active'] ? 'alert' : 'secondary' ?>" data-id="<?=h($sys_user['id'])?>">
                                    <?= $sys_user['is_active'] ? 'ปิดใช้งาน' : 'เปิดใช้งาน' ?>
                                </button>
                                <?php endif; ?>
                                <?php if ($sys_user['role'] === 'admin'): ?>
                                    <button class="button-small reset-admin-password-btn warning" data-id="<?=h($sys_user['id'])?>" data-username="<?=h($sys_user['username'])?>">ตั้งรหัสผ่านใหม่</button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<?php // Modal for editing addons is now loaded from layout.php, so it's removed from here to fix duplicate IDs. ?>

<style>
.settings-section {
    background-color: var(--color-surface);
    padding: 1.5rem;
    border-radius: var(--border-radius-lg);
    box-shadow: var(--shadow-sm);
    border: 1px solid var(--color-border);
    margin-bottom: 2rem;
}
.settings-section h3 {
    margin-top: 0;
    margin-bottom: 1rem;
    border-bottom: 1px solid var(--color-border);
    padding-bottom: 0.5rem;
}
.settings-section h4 {
    margin-top: 1.5rem;
    margin-bottom: 0.75rem;
}
.form-inline-group {
    display: flex;
    flex-wrap: wrap;
    gap: 1rem;
    align-items: flex-end;
    margin-bottom: 1rem;
}
.form-inline-group .form-group {
    flex-grow: 1;
    margin-bottom: 0;
}
.form-inline-group button[type="submit"] {
    flex-shrink: 0;
}
.room-price-table input[type="number"] {
    max-width: 120px;
    padding: 0.4rem 0.6rem;
    font-size: 0.9rem;
}
.room-price-table td {
    vertical-align: middle;
}
.room-price-table td input[type="number"] {
    margin-bottom: 0;
}
.text-success { color: var(--color-success-text); }
.text-danger { color: var(--color-error-text); }
</style>

<script>
// This script assumes main.js is loaded, which contains setButtonLoading
document.addEventListener('DOMContentLoaded', function() {
    // *** START: FIX for API URL ***
    // Use the PHP constant defined in bootstrap.php
    const API_URL = '<?= defined("API_BASE_URL_PHP") ? API_BASE_URL_PHP : "/hotel_booking/pages/api.php" ?>';
    // *** END: FIX for API URL ***

    const newUserRoleSelect = document.getElementById('new_user_role');
    const newUserPasswordGroup = document.getElementById('new_user_password_group');
    const newUserPasswordInput = document.getElementById('new_user_password');

    if (newUserRoleSelect) {
        newUserRoleSelect.addEventListener('change', function() {
            if (this.value === 'admin') {
                newUserPasswordGroup.style.display = 'block';
                newUserPasswordInput.required = true;
            } else {
                newUserPasswordGroup.style.display = 'none';
                newUserPasswordInput.required = false;
                newUserPasswordInput.value = '';
            }
        });
        newUserRoleSelect.dispatchEvent(new Event('change'));
    }

    const addUserForm = document.getElementById('add-user-form');
    if (addUserForm) {
        const submitBtn = addUserForm.querySelector('#submitAddUserBtn');
        addUserForm.addEventListener('submit', async function(e) {
            e.preventDefault();
            const formData = new FormData(addUserForm);
            if (formData.get('role') === 'admin' && !formData.get('password')) {
                alert('กรุณากำหนดรหัสผ่านสำหรับผู้ดูแลใหม่'); return;
            }
            if (formData.get('username').trim() === '') {
                alert('ชื่อผู้ใช้ต้องไม่เป็นค่าว่าง'); return;
            }

            if (submitBtn && typeof setButtonLoading === 'function') {
                setButtonLoading(submitBtn, true, 'submitAddUserBtn');
            }
            try {
                // *** FIX: Use the correct API_URL constant ***
                const response = await fetch(`${API_URL}?action=add_user`, { method: 'POST', body: formData });
                const data = await response.json();
                alert(data.message || (data.success ? 'เพิ่มผู้ใช้สำเร็จ' : 'เกิดข้อผิดพลาด'));
                if (data.success) {
                    location.reload();
                }
            } catch (err) {
                console.error('Add user error:', err);
                alert('เกิดข้อผิดพลาดในการเชื่อมต่อ: ' + err.message);
            } finally {
                if (submitBtn && typeof setButtonLoading === 'function') {
                    setButtonLoading(submitBtn, false, 'submitAddUserBtn');
                }
            }
        });
    }

    const usersTable = document.getElementById('users-table');
    if (usersTable) {
        usersTable.addEventListener('click', async function(e){
            const targetButton = e.target.closest('button');
            if (!targetButton) return;
            const userId = targetButton.dataset.id;
            if (!userId) return;
            const buttonId = targetButton.id || `user-action-${userId}`;

            if (targetButton.classList.contains('toggle-user-status-btn')) {
                if (!confirm('คุณต้องการเปลี่ยนสถานะผู้ใช้งานนี้ใช่หรือไม่?')) return;
                if (typeof setButtonLoading === 'function') setButtonLoading(targetButton, true, buttonId);
                try {
                     // *** FIX: Use the correct API_URL constant ***
                    const response = await fetch(`${API_URL}?action=toggle_user_status`, {
                        method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                        body: new URLSearchParams({ id: userId })
                    });
                    const data = await response.json();
                    alert(data.message || (data.success ? 'เปลี่ยนสถานะสำเร็จ' : 'เกิดข้อผิดพลาด'));
                    if (data.success) location.reload();
                } catch (err) { console.error('Toggle user status error:', err); alert('เกิดข้อผิดพลาดในการเชื่อมต่อ: ' + err.message);
                } finally { if (typeof setButtonLoading === 'function') setButtonLoading(targetButton, false, buttonId); }

            } else if (targetButton.classList.contains('reset-admin-password-btn')) {
                const username = targetButton.dataset.username;
                const newPassword = prompt(`กรุณาใส่รหัสผ่านใหม่สำหรับผู้ดูแล: ${username}`);
                if (newPassword === null || newPassword.trim() === '') return;
                if (newPassword.length < 6) { alert('รหัสผ่านต้องมีความยาวอย่างน้อย 6 ตัวอักษร'); return; }

                if (typeof setButtonLoading === 'function') setButtonLoading(targetButton, true, buttonId);
                try {
                     // *** FIX: Use the correct API_URL constant ***
                    const response = await fetch(`${API_URL}?action=reset_admin_password`, {
                        method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                        body: new URLSearchParams({ user_id: userId, new_password: newPassword })
                    });
                    const data = await response.json();
                    alert(data.message || (data.success ? 'ตั้งรหัสผ่านใหม่สำเร็จ' : 'เกิดข้อผิดพลาด'));
                } catch (err) { console.error('Reset admin password error:', err); alert('เกิดข้อผิดพลาดในการเชื่อมต่อ: ' + err.message);
                } finally { if (typeof setButtonLoading === 'function') setButtonLoading(targetButton, false, buttonId); }
            }
        });
    }

    // This section is now almost identical to main.js, which is fine, but shows opportunity for code reuse in the future.
    // For now, we fix it here to ensure this page works independently.
    document.querySelectorAll('.save-room-price-btn').forEach(button => {
        button.addEventListener('click', async function() {
            const roomId = this.dataset.roomId;
            const row = this.closest('tr');
            const pricePerDayInput = row.querySelector(`input[name="price_per_day_${roomId}"]`);
            const priceShortStayInput = row.querySelector(`input[name="price_short_stay_${roomId}"]`);
            const pricePerHourExtensionInput = row.querySelector(`input[name="price_per_hour_extension_${roomId}"]`);
            const buttonId = `save-room-price-btn-${roomId}`;
            if (typeof setButtonLoading === 'function') setButtonLoading(this, true, buttonId);
            
            try {
                const formData = new FormData();
                formData.append('action', 'update_room_price');
                formData.append('room_id_price_update', roomId);
                formData.append('new_price_per_day', parseFloat(pricePerDayInput.value));
                if (!priceShortStayInput.disabled) {
                    formData.append('new_price_short_stay', parseFloat(priceShortStayInput.value));
                }
                formData.append('new_price_per_hour_extension', parseFloat(pricePerHourExtensionInput.value));

                 // *** FIX: Use the correct API_URL constant ***
                const response = await fetch(API_URL, { method: 'POST', body: formData });
                const result = await response.json();
                alert(result.message || (result.success ? 'บันทึกราคาสำเร็จ' : 'เกิดข้อผิดพลาด'));
                if(result.success) {
                   // Optionally update UI to show it's saved without reload
                }
            } catch (error) {
                console.error('Error updating room price:', error);
                alert('เกิดข้อผิดพลาดในการเชื่อมต่อ');
            } finally {
                if (typeof setButtonLoading === 'function') setButtonLoading(this, false, buttonId);
            }
        });
    });

    const addAddonForm = document.getElementById('add-addon-form');
    if (addAddonForm) {
        const submitBtn = addAddonForm.querySelector('#submitAddAddonBtn');
        addAddonForm.addEventListener('submit', async function(e) {
            e.preventDefault();
            const formData = new FormData(addAddonForm);
            if (typeof setButtonLoading === 'function') setButtonLoading(submitBtn, true, 'submitAddAddonBtn');
            try {
                // *** FIX: Use the correct API_URL constant ***
                const response = await fetch(`${API_URL}?action=add_addon_service`, { method: 'POST', body: formData });
                const data = await response.json();
                alert(data.message || 'Complete');
                if (data.success) location.reload();
            } catch (err) {
                console.error('Add addon error:', err);
                alert('เกิดข้อผิดพลาดในการเชื่อมต่อ');
            } finally {
                if (typeof setButtonLoading === 'function') setButtonLoading(submitBtn, false, 'submitAddAddonBtn');
            }
        });
    }

    const updateHourlyRateForm = document.getElementById('update-hourly-rate-form');
    if (updateHourlyRateForm) {
        const submitBtn = updateHourlyRateForm.querySelector('#submitUpdateHourlyRateBtn');
        updateHourlyRateForm.addEventListener('submit', async function(e) {
            e.preventDefault();
            const formData = new FormData(updateHourlyRateForm);
            if (typeof setButtonLoading === 'function') setButtonLoading(submitBtn, true, 'submitUpdateHourlyRateBtn');
            try {
                 // *** FIX: Use the correct API_URL constant ***
                const response = await fetch(`${API_URL}?action=update_system_setting`, { method: 'POST', body: formData });
                const data = await response.json();
                alert(data.message || 'Complete');
                if (data.success) location.reload();
            } catch (err) {
                console.error('Update hourly rate error:', err);
                alert('เกิดข้อผิดพลาดในการเชื่อมต่อ');
            } finally {
                if (typeof setButtonLoading === 'function') setButtonLoading(submitBtn, false, 'submitUpdateHourlyRateBtn');
            }
        });
    }
    
    // The edit addon modal logic is now handled by main.js, using the modal from layout.php
    // This removes the need for duplicate JS here.
});
</script>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../templates/layout.php';
?>