<?php
// templates/layout.php
// แม่แบบโครงสร้าง HTML สำหรับทุกหน้า
// แต่ละ page จะกำหนดตัวแปร $pageTitle และ $content
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= isset($pageTitle) ? h($pageTitle) : 'Hotel Admin' ?></title>
  <link rel="stylesheet" href="/hotel_booking/assets/css/main.css">
  <link rel="icon" type="image/x-icon" href="/hotel_booking/assets/image/logo.ico">
  <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
  <script src="/hotel_booking/assets/js/main.js" defer></script>
  <style>
    /* Basic Dark Theme */
    body.dark-theme {
      background-color: #121212;
      color: #e0e0e0;
    }
    body.dark-theme .site-header {
      background-color: #1e1e1e;
      border-bottom-color: #333;
    }
    body.dark-theme .site-header h1 a,
    body.dark-theme .site-nav ul li a,
    body.dark-theme .site-nav ul li span {
      color: #e0e0e0;
    }
    body.dark-theme .site-footer {
      background-color: #1e1e1e;
      border-top-color: #333;
      color: #aaa;
    }
    body.dark-theme .container {
        /* Adjust container styles if needed */
    }
    body.dark-theme .button.primary {
        background-color: #0056b3; /* Darker primary */
        border-color: #0056b3;
        color: #fff;
    }
    body.dark-theme .button.outline-primary {
        color: #66bfff; /* Lighter blue for dark theme */
        border-color: #66bfff;
    }
    body.dark-theme .button.alert {
        background-color: #c82333; /* Darker alert */
        border-color: #c82333;
    }
    body.dark-theme .message.error {
        background-color: #52181c;
        color: #f8d7da;
        border-color: #721c24;
    }
    body.dark-theme .message.success {
        background-color: #153e20;
        color: #d4edda;
        border-color: #155724;
    }
    body.dark-theme .modal-content {
        background-color: #2b2b2b;
        color: #e0e0e0;
        border-color: #444;
    }
    body.dark-theme #confirmBookingModalHeader,
    body.dark-theme .button-group[style*="border-top"] {
        border-color: #444 !important; /* important to override inline style if necessary */
    }
    body.dark-theme #confirmBookingModalHeader h4 {
        color: #66bfff; /* Lighter blue for dark theme */
    }
     body.dark-theme input[type="text"],
     body.dark-theme input[type="number"],
     body.dark-theme input[type="email"],
     body.dark-theme input[type="password"],
     body.dark-theme textarea,
     body.dark-theme select {
        background-color: #333;
        color: #e0e0e0;
        border-color: #555;
     }
     body.dark-theme input[type="text"]:focus,
     body.dark-theme input[type="number"]:focus,
     body.dark-theme input[type="email"]:focus,
     body.dark-theme input[type="password"]:focus,
     body.dark-theme textarea:focus,
     body.dark-theme select:focus {
        border-color: #66bfff;
     }
     body.dark-theme label {
        color: #ccc;
     }


    /* Theme Toggle Switch Styles */
    .theme-toggle-switch {
      position: relative;
      display: inline-block;
      width: 50px; /* Reduced width */
      height: 24px; /* Reduced height */
      margin-left: 15px; /* Space from other nav items */
      vertical-align: middle;
    }
    .theme-toggle-switch input {
      opacity: 0;
      width: 0;
      height: 0;
    }
    .theme-toggle-slider {
      position: absolute;
      cursor: pointer;
      top: 0;
      left: 0;
      right: 0;
      bottom: 0;
      background-color: #ccc;
      transition: .4s;
      border-radius: 24px; /* Adjusted for new height */
    }
    .theme-toggle-slider:before {
      position: absolute;
      content: "";
      height: 18px; /* Reduced size */
      width: 18px;  /* Reduced size */
      left: 3px;   /* Adjusted position */
      bottom: 3px;  /* Adjusted position */
      background-color: white;
      transition: .4s;
      border-radius: 50%;
    }
    input:checked + .theme-toggle-slider {
      background-color: #2196F3; /* Blue when active */
    }
    body.dark-theme input:checked + .theme-toggle-slider {
      background-color: #66bfff; /* Lighter blue for dark theme */
    }
    input:focus + .theme-toggle-slider {
      box-shadow: 0 0 1px #2196F3;
    }
    input:checked + .theme-toggle-slider:before {
      transform: translateX(26px); /* Adjusted for new width */
    }
  </style>
</head>
<body>
  <header class="site-header">
    <div class="container" style="display: flex; justify-content: space-between; align-items: center;">
      <h1 class="logo" style="margin:0;"><a href="<?= defined('DASHBOARD_PAGE') ? DASHBOARD_PAGE : '/hotel_booking/pages/index.php' ?>" style="color:white; text-decoration:none;">Hotel Booking ระบบจอง</a></h1>
      <nav class="site-nav">
        <ul>
          <?php if (function_exists('is_logged_in') && is_logged_in()): ?>
            <li><a href="/hotel_booking/pages/index.php">หน้าหลัก</a></li>
            <li><a href="/hotel_booking/pages/booking.php">การจอง</a></li>
            <li><a href="/hotel_booking/pages/booking_calendar_view.php">ปฏิทินการจอง</a></li>
            <?php if (function_exists('get_current_user_role') && get_current_user_role() === 'admin'): ?>
              <li><a href="/hotel_booking/pages/report.php">รายงานวิเคราะห์</a></li>
              <li><a href="/hotel_booking/pages/settings_management.php">จัดการตั้งค่า</a></li>
              <li>
                <label class="theme-toggle-switch" title="Toggle Dark Mode">
                  <input type="checkbox" id="themeToggleButton">
                  <span class="theme-toggle-slider"></span>
                </label>
              </li>
            <?php endif; ?>
            <li>
                <span style="color: #fff; margin-right: 10px;">
                    ผู้ใช้: <?= function_exists('get_current_username') ? h(get_current_username()) : '' ?> (<?= function_exists('get_current_user_role') ? h(ucfirst(get_current_user_role())) : '' ?>)
                </span>
                <a href="/hotel_booking/pages/logout.php" class="button-small alert" style="padding: 0.3rem 0.6rem; vertical-align: middle;">ออกจากระบบ</a>
            </li>
          <?php else: ?>
             <?php // If not logged in, you might want to show a login link, or nothing, depending on your app's flow. ?>
          <?php endif; ?>
        </ul>
      </nav>
    </div>
  </header>

  <main class="site-content container">
    <?php
        if (isset($_SESSION['error_message'])) {
            echo '<p class="message error" style="background-color: #f8d7da; color: #721c24; padding: 10px; border: 1px solid #f5c6cb; border-radius: 4px; margin-bottom:1rem;">' . (function_exists('h') ? h($_SESSION['error_message']) : htmlspecialchars($_SESSION['error_message'])) . '</p>';
            unset($_SESSION['error_message']);
        }
        if (isset($_SESSION['success_message'])) {
             echo '<p class="message success" style="background-color: #d4edda; color: #155724; padding: 10px; border: 1px solid #c3e6cb; border-radius: 4px; margin-bottom:1rem;">' . (function_exists('h') ? h($_SESSION['success_message']) : htmlspecialchars($_SESSION['success_message'])) . '</p>';
            unset($_SESSION['success_message']);
        }
        if (isset($content)) {
            echo $content;
        }
    ?>
  </main>

  <div id="modal" class="modal-overlay">
    <div class="modal-content" data-aos="fade-down" data-aos-duration="300" data-aos-once="false">
      <button class="modal-close" aria-label="Close">×</button>
      <div id="modal-body"></div>
    </div>
  </div>
  <div id="image-modal" class="modal-overlay">
    <div class="modal-content" data-aos="fade-down" data-aos-duration="300" data-aos-once="false">
      <button class="modal-close">×</button>
      <img id="modal-image" src="" alt="หลักฐานการชำระ" style="max-width:100%;border-radius:4px;" />
    </div>
  </div>
  <div id="deposit-modal" class="modal-overlay">
    <div class="modal-content" data-aos="fade-down" data-aos-duration="300" data-aos-once="false">
      <button class="modal-close">×</button>
      <img id="deposit-modal-image" src="" alt="หลักฐานคืนมัดจำ" style="max-width:100%;border-radius:4px;" />
    </div>
  </div>
  <div id="edit-addon-modal" class="modal-overlay">
    <div class="modal-content" data-aos="fade-down" data-aos-duration="300" data-aos-once="false">
      <button class="modal-close" aria-label="Close">×</button>
      <h4>แก้ไขบริการเสริม</h4>
      <form id="edit-addon-modal-form">
          <input type="hidden" id="edit_addon_id" name="id">
          <div class="form-group">
              <label for="edit_addon_name_modal">ชื่อบริการ:</label>
              <input type="text" id="edit_addon_name_modal" name="name" required>
          </div>
          <div class="form-group">
              <label for="edit_addon_price_modal">ราคา (บาท):</label>
              <input type="number" id="edit_addon_price_modal" name="price" step="0.01" required>
          </div>
          <div class="button-group">
              <button type="submit" class="button primary">บันทึกการแก้ไข</button>
              <button type="button" class="modal-close button outline-primary">ยกเลิก</button>
          </div>
      </form>
    </div>
  </div>
  <div id="confirmBookingModal" class="modal-overlay">
    <div class="modal-content" data-aos="fade-down" data-aos-duration="300" data-aos-once="false" style="max-width: 550px;">
      <div id="confirmBookingModalHeader" style="padding-bottom: 1rem; border-bottom: 1px solid var(--color-border); margin-bottom: 1rem;">
          <h4 style="margin:0; color: var(--color-primary-dark); font-size:1.3rem;">ยืนยันข้อมูลการจอง</h4>
      </div>
      <div id="confirmBookingModalBody" style="padding-bottom: 1.5rem; line-height:1.6;"></div>
      <div class="button-group" style="justify-content: flex-end; border-top: 1px solid var(--color-border); padding-top: 1rem;">
          <button type="button" id="confirmBookingModalCancelBtn" class="button outline-secondary modal-close">ยกเลิก</button>
          <button type="button" id="confirmBookingModalActionBtn" class="button primary" style="margin-left: 0.5rem;">ยืนยันการจอง</button>
      </div>
    </div>
  </div>

  <?php // ***** START: โค้ดที่เพิ่มเข้ามา ***** ?>
  <div id="move-room-modal" class="modal-overlay">
    <div class="modal-content" data-aos="fade-down" data-aos-duration="300">
      <button class="modal-close" aria-label="Close">×</button>
      <div id="move-room-modal-header">
          <h4 style="margin-top:0; color: var(--color-primary-dark);">ย้ายการจองไปยังห้องอื่น</h4>
      </div>
      <div id="move-room-modal-body">
          <p id="move-room-info-text">กำลังโหลดข้อมูล...</p>
          <div class="form-group">
              <label for="select-new-room">เลือกห้องใหม่ที่ต้องการย้ายไป:</label>
              <select id="select-new-room" class="form-control" required>
                  <option value="">-- กรุณารอสักครู่ --</option>
              </select>
          </div>
      </div>
      <div class="button-group" style="margin-top: 1.5rem; padding-top: 1rem; border-top: 1px solid var(--color-border); justify-content: flex-end;">
          <button type="button" class="button outline-secondary modal-close">ยกเลิก</button>
          <button type="button" id="confirm-move-room-btn" class="button primary">ยืนยันการย้ายห้อง</button>
      </div>
    </div>
  </div>
  <?php // ***** END: โค้ดที่เพิ่มเข้ามา ***** ?>

  <footer class="site-footer">
    <div class="container">
      <p>&copy; <?= date('Y') ?> Hotel Booking System. All rights reserved.</p>
    </div>
  </footer>
  <script>
    // Initialize AOS
    document.addEventListener('DOMContentLoaded', function() {
        AOS.init({
            once: false // Set to true if you want animations to play only once
        });

        // Theme Management
        const themeToggleButton = document.getElementById('themeToggleButton');
        // We need to know if the current user is an admin.
        // This PHP block will set a JavaScript variable.
        const isAdmin = <?= (function_exists('get_current_user_role') && get_current_user_role() === 'admin') ? 'true' : 'false' ?>;
        const THEME_PREFERENCE_KEY = 'adminThemePreference';

        function applyTheme(theme) {
            if (theme === 'dark') {
                document.body.classList.add('dark-theme');
                if (themeToggleButton) themeToggleButton.checked = true;
            } else {
                document.body.classList.remove('dark-theme');
                if (themeToggleButton) themeToggleButton.checked = false;
            }
        }

        function getAdminPreference() {
            return localStorage.getItem(THEME_PREFERENCE_KEY);
        }

        function setAdminPreference(theme) {
            localStorage.setItem(THEME_PREFERENCE_KEY, theme);
        }

        function initializeTheme() {
            let preferredTheme = null;

            if (isAdmin) {
                preferredTheme = getAdminPreference();
            }

            if (preferredTheme) {
                applyTheme(preferredTheme);
            } else {
                // Automatic theme based on time
                const currentHour = new Date().getHours();
                // Dark theme from 7 PM (19) to 3:59 AM (3)
                if (currentHour >= 19 || currentHour < 4) {
                    applyTheme('dark');
                } else {
                    applyTheme('light');
                }
            }
        }

        if (themeToggleButton) {
            themeToggleButton.addEventListener('change', function() {
                if (isAdmin) {
                    const newTheme = this.checked ? 'dark' : 'light';
                    applyTheme(newTheme);
                    setAdminPreference(newTheme);
                } else {
                    // If not admin, the toggle shouldn't ideally be visible,
                    // but if it is, prevent non-admins from setting a persistent preference.
                    // The theme will just toggle visually for their session but not save.
                     applyTheme(this.checked ? 'dark' : 'light');
                }
            });
        }

        initializeTheme();

        // Optional: Auto-update theme based on time if no admin preference is set
        // This interval will run every minute.
        // Clear this interval if an admin sets a preference to avoid override.
        let themeCheckInterval = null;
        if (!isAdmin || (isAdmin && !getAdminPreference())) {
            themeCheckInterval = setInterval(() => {
                // Only run if admin hasn't set a preference
                if (isAdmin && getAdminPreference()) {
                    clearInterval(themeCheckInterval);
                    return;
                }
                 const currentHour = new Date().getHours();
                if (currentHour >= 19 || currentHour < 4) {
                    applyTheme('dark');
                } else {
                    applyTheme('light');
                }
            }, 60000); // 60000ms = 1 minute
        }
         // Ensure the interval is cleared if an admin logs in and sets a preference later
        if (themeToggleButton && isAdmin) {
            themeToggleButton.addEventListener('change', function() {
                 if (isAdmin && getAdminPreference() && themeCheckInterval) {
                    clearInterval(themeCheckInterval);
                }
            });
        }

    });
  </script>
</body>
</html>