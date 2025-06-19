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
  
  <!-- CSS หลัก (ไฟล์เดิม) -->
  <link rel="stylesheet" href="/hotel_booking/assets/css/main.css">
  
  <!-- Favicon -->
  <link rel="icon" type="image/x-icon" href="/hotel_booking/assets/image/logo.ico">

  <!-- Font Awesome 6 for Icons (แก้ไข Attribute ที่ผิด) -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" xintegrity="sha512-DTOQO9RWCH3ppGqcWaEA1BIZOC6xxalwEsw9c2QQeAIftl+Vegovlnee1c9QX4TctnWMn13TZye+giMm8e2LwA==" crossorigin="anonymous" referrerpolicy="no-referrer" />

  <!-- AOS Animation Library -->
  <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">
  
  <!-- Scripts -->
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
  <script src="/hotel_booking/assets/js/main.js" defer></script>
  
  <!-- Styles (รวมโค้ด CSS สำหรับ Navbar ใหม่เข้ามาแล้ว) -->
  <style>
    /* --- Dark Theme เดิม --- */
    body.dark-theme { background-color: #121212; color: #e0e0e0; }
    body.dark-theme .site-header { background-color: #1e1e1e; border-bottom-color: #333; }
    body.dark-theme .site-header h1 a, 
    body.dark-theme .site-nav a, 
    body.dark-theme .user-info-text { color: #e0e0e0; }
    body.dark-theme .nav-toggle .hamburger { background-color: #e0e0e0; }
    body.dark-theme .site-nav { background-color: #1e1e1e; }
    body.dark-theme .site-footer { background-color: #1e1e1e; border-top-color: #333; color: #aaa; }
    body.dark-theme input[type="text"], body.dark-theme input[type="number"], body.dark-theme input[type="email"], body.dark-theme input[type="password"], body.dark-theme textarea, body.dark-theme select { background-color: #333; color: #e0e0e0; border-color: #555; }
    body.dark-theme input:focus, body.dark-theme textarea:focus, body.dark-theme select:focus { border-color: #66bfff; }
    body.dark-theme label { color: #ccc; }
    body.dark-theme .modal-content { background-color: #2b2b2b; color: #e0e0e0; border-color: #444; }
    
    /* Theme Toggle Switch */
    .theme-toggle-switch { position: relative; display: inline-block; width: 50px; height: 24px; margin-left: 15px; vertical-align: middle; }
    .theme-toggle-switch input { opacity: 0; width: 0; height: 0; }
    .theme-toggle-slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: #ccc; transition: .4s; border-radius: 24px; }
    .theme-toggle-slider:before { position: absolute; content: ""; height: 18px; width: 18px; left: 3px; bottom: 3px; background-color: white; transition: .4s; border-radius: 50%; }
    input:checked + .theme-toggle-slider { background-color: #2196F3; }
    body.dark-theme input:checked + .theme-toggle-slider { background-color: #66bfff; }
    input:checked + .theme-toggle-slider:before { transform: translateX(26px); }
  
    /* ============================================= */
    /* === START: NEW NAVBAR & RESPONSIVE STYLES === */
    /* ============================================= */
    
    /* --- Base Header Layout --- */
    .site-header {
      /*ใช้สีจากไฟล์ main.css ถ้ามี*/
      background-color: var(--color-header-bg, #004080); 
      color: var(--color-header-text, #ffffff);
      position: sticky;
      top: 0;
      z-index: 999;
      box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
    }

    .site-header .header-container {
      display: flex;
      justify-content: space-between;
      align-items: center;
      position: relative;
      min-height: 70px; /* ให้ Header มีความสูงคงที่ */
    }

    .site-header .logo a {
      font-weight: 700;
      font-size: 1.5rem;
      text-decoration: none;
      color: var(--color-header-text, #ffffff);
      padding: 0.5rem 0;
    }

    /* --- Desktop Navigation --- */
    .site-nav .nav-links {
      margin: 0;
      padding: 0;
      list-style: none;
      display: flex;
      align-items: center;
      gap: 0.5rem;
    }

    .site-nav a {
      display: flex; /* ใช้ flex เพื่อจัดไอคอนกับข้อความ */
      align-items: center;
      gap: 0.6rem; /* ระยะห่างระหว่างไอคอนกับข้อความ */
      color: var(--color-header-text, #ffffff);
      text-decoration: none;
      padding: 0.8rem 1rem;
      border-radius: 8px; /*var(--border-radius-md)*/
      position: relative;
      overflow: hidden;
      transition: color 0.3s ease, background-color 0.3s ease;
    }

    .site-nav a::after {
      content: '';
      position: absolute;
      bottom: 0;
      left: 50%;
      transform: translateX(-50%);
      width: 0;
      height: 3px;
      background-color: var(--color-warning, #e0a800);
      transition: width 0.3s ease;
    }

    .site-nav a:hover,
    .site-nav a:focus,
    .site-nav li.active a { /* เพิ่ม .active สำหรับหน้าปัจจุบัน */
      color: #fff;
      background-color: rgba(255, 255, 255, 0.1);
    }

    .site-nav a:hover::after,
    .site-nav li.active a::after {
      width: 70%;
    }

    .site-nav .icon {
      font-size: 1.1rem;
      width: 20px; /* กำหนดความกว้างให้ไอคอน */
      text-align: center;
    }

    /* --- User Section --- */
    .nav-user-section {
      display: flex;
      align-items: center;
      gap: 1rem;
      padding-left: 1.5rem;
      margin-left: 1rem;
      border-left: 1px solid rgba(255, 255, 255, 0.2);
    }

    .user-info {
      display: flex;
      align-items: center;
      gap: 0.5rem;
      color: #f0f0f0;
    }
    .user-icon { font-size: 1.5rem; color: #fff; }
    .user-info-text { font-size: 0.9rem; white-space: nowrap; }

    .logout-button {
      display: flex;
      align-items: center;
      gap: 0.5rem;
      padding: 0.5rem 1rem !important; /* Override button style */
    }
    body.dark-theme .nav-user-section {
      border-left-color: rgba(255, 255, 255, 0.1);
    }

    /* --- Hamburger Menu (Mobile) --- */
    .nav-toggle {
      display: none; /* ซ่อนบน Desktop */
      position: absolute; /* จัดตำแหน่งเทียบกับ header-container */
      top: 50%;
      right: 1rem;
      transform: translateY(-50%);
      z-index: 1001; /* ให้อยู่เหนือทุกอย่างใน header */
      width: 40px;
      height: 40px;
      background: transparent;
      border: none;
      cursor: pointer;
      padding: 0;
    }

    .hamburger {
      display: block;
      position: relative;
      width: 25px;
      height: 3px;
      background: var(--color-header-text, #ffffff);
      border-radius: 3px;
      transition: transform 0.3s ease;
    }

    .hamburger::before,
    .hamburger::after {
      content: '';
      position: absolute;
      left: 0;
      width: 100%;
      height: 3px;
      background: var(--color-header-text, #ffffff);
      border-radius: 3px;
      transition: top 0.3s ease, transform 0.3s ease;
    }

    .hamburger::before { top: -8px; }
    .hamburger::after { top: 8px; }

    /* Hamburger Animation when Active */
    .nav-toggle.active .hamburger {
      background: transparent; /* ซ่อนขีดกลาง */
    }
    .nav-toggle.active .hamburger::before {
      top: 0;
      transform: rotate(45deg);
    }
    .nav-toggle.active .hamburger::after {
      top: 0;
      transform: rotate(-45deg);
    }


    /* --- Mobile Navigation Styles --- */
    @media (max-width: 992px) {
      .nav-toggle {
        display: block;
      }
      
      .site-nav {
        position: fixed;
        top: 0;
        right: -100%; /* ซ่อนเมนูไว้นอกจอทางขวา */
        width: 280px; /* ความกว้างของเมนู */
        height: 100vh;
        background-color: var(--color-header-bg, #004080);
        box-shadow: -5px 0 15px rgba(0,0,0,0.2);
        padding-top: 80px; /* เว้นที่สำหรับ header */
        transition: right 0.4s cubic-bezier(0.77, 0, 0.175, 1);
      }

      .site-nav.nav-open {
        right: 0; /* แสดงเมนูโดยเลื่อนเข้ามาจากทางขวา */
      }

      body.nav-lock-scroll {
        overflow: hidden; /* ป้องกันการ scroll ขณะเมนูเปิด */
      }

      .site-nav .nav-links {
        flex-direction: column;
        align-items: flex-start;
        gap: 0;
        width: 100%;
      }

      .site-nav li {
        width: 100%;
      }

      .site-nav a {
        padding: 1rem 1.5rem;
        width: 100%;
        border-radius: 0;
        border-bottom: 1px solid rgba(255, 255, 255, 0.1);
      }
      .site-nav a::after {
          display: none; /* ไม่ต้องใช้เส้นใต้บนมือถือ */
      }

      .site-nav a:hover,
      .site-nav li.active a {
          background-color: var(--color-primary, #0056b3);
      }

      /* จัดเรียง user section บนมือถือใหม่ */
      .nav-user-section {
        flex-direction: column;
        align-items: flex-start;
        gap: 1rem;
        padding: 1.5rem;
        margin-left: 0;
        margin-top: 1rem;
        border-left: none;
        border-top: 1px solid rgba(255, 255, 255, 0.1);
        width: 100%;
      }

      .logout-button {
          width: 100%;
          justify-content: center; /* จัดปุ่มให้อยู่กลาง */
      }
    }

    /* ปรับปรุง Dark Theme สำหรับ Mobile Nav */
    body.dark-theme .site-nav {
      background-color: #1e1e1e;
    }
    body.dark-theme .site-nav a {
      border-bottom-color: rgba(255, 255, 255, 0.08);
    }
    body.dark-theme .nav-user-section {
      border-top-color: rgba(255, 255, 255, 0.08);
    }
    body.dark-theme .site-nav a:hover,
    body.dark-theme .site-nav li.active a {
        background-color: #5ba5f5; /* var(--dt-color-primary) */
        color: #000;
    }
    
    .nav-user-section .button-small {
        padding: 0.5rem 1rem;
        font-size: 0.9rem;
    }
    
    /* =========================================== */
    /* === END: NEW NAVBAR & RESPONSIVE STYLES === */
    /* =========================================== */
  </style>
</head>
<body class="">

  <header class="site-header">
    <div class="container header-container">
      <h1 class="logo">
        <a href="<?= defined('DASHBOARD_PAGE') ? DASHBOARD_PAGE : '/hotel_booking/pages/index.php' ?>">
          Hotel Booking
        </a>
      </h1>

      <!-- Hamburger Menu Button (แสดงเฉพาะบนมือถือ) -->
      <button class="nav-toggle" aria-label="toggle navigation">
        <span class="hamburger"></span>
      </button>

      <nav class="site-nav">
        <ul class="nav-links">
          <?php if (function_exists('is_logged_in') && is_logged_in()): ?>
            <li><a href="/hotel_booking/pages/index.php"><i class="fa-solid fa-house-chimney icon"></i>หน้าหลัก</a></li>
            <li><a href="/hotel_booking/pages/booking.php"><i class="fa-solid fa-book-bookmark icon"></i>การจอง</a></li>
            <li><a href="/hotel_booking/pages/booking_calendar_view.php"><i class="fa-solid fa-calendar-days icon"></i>ปฏิทิน</a></li>
            
            <?php if (function_exists('get_current_user_role') && get_current_user_role() === 'admin'): ?>
              <li><a href="/hotel_booking/pages/report.php"><i class="fa-solid fa-chart-line icon"></i>รายงาน</a></li>
              <li><a href="/hotel_booking/pages/settings_management.php"><i class="fa-solid fa-gears icon"></i>ตั้งค่า</a></li>
            <?php endif; ?>

            <!-- User Info and Logout (จัดกลุ่มใหม่) -->
            <li class="nav-user-section">
                <div class="user-info">
                  <i class="fa-solid fa-circle-user user-icon"></i>
                  <span class="user-info-text">
                    <?= function_exists('get_current_username') ? h(get_current_username()) : '' ?> 
                    (<?= function_exists('get_current_user_role') ? h(ucfirst(get_current_user_role())) : '' ?>)
                  </span>
                </div>
                <div class="theme-toggle-container">
                    <label class="theme-toggle-switch" title="Toggle Dark Mode">
                      <input type="checkbox" id="themeToggleButton">
                      <span class="theme-toggle-slider"></span>
                    </label>
                </div>
                <a href="/hotel_booking/pages/logout.php" class="button-small alert logout-button">
                  <i class="fa-solid fa-right-from-bracket"></i>&nbsp;ออกจากระบบ
                </a>
            </li>

          <?php else: ?>
             <!-- ส่วนนี้จะแสดงเมื่อยังไม่ได้ login (ถ้ามี) -->
             <li><a href="/hotel_booking/pages/login.php"><i class="fa-solid fa-right-to-bracket icon"></i>เข้าสู่ระบบ</a></li>
          <?php endif; ?>
        </ul>
      </nav>
    </div>
  </header>

  <main class="site-content container">
    <?php
        // Flash Messages (คงไว้เหมือนเดิม)
        if (isset($_SESSION['error_message'])) {
            echo '<p class="message error">' . h($_SESSION['error_message']) . '</p>';
            unset($_SESSION['error_message']);
        }
        if (isset($_SESSION['success_message'])) {
             echo '<p class="message success">' . h($_SESSION['success_message']) . '</p>';
            unset($_SESSION['success_message']);
        }
        // Main Content
        if (isset($content)) {
            echo $content;
        }
    ?>
  </main>

    <!-- Modals (คงไว้เหมือนเดิมทั้งหมด) -->
    <div id="modal" class="modal-overlay"> ... </div>
    <div id="image-modal" class="modal-overlay"> ... </div>
    <div id="deposit-modal" class="modal-overlay"> ... </div>
    <div id="edit-addon-modal" class="modal-overlay"> ... </div>
    <div id="confirmBookingModal" class="modal-overlay"> ... </div>
    <div id="move-room-modal" class="modal-overlay"> ... </div>


  <footer class="site-footer">
    <div class="container">
      <p>&copy; <?= date('Y') ?> Hotel Booking System. All rights reserved.</p>
    </div>
  </footer>

  <script>
    document.addEventListener('DOMContentLoaded', function() {
        // --- START: โค้ดสำหรับเมนูบนมือถือ ---
        const navToggle = document.querySelector('.nav-toggle');
        const nav = document.querySelector('.site-nav');
        const body = document.body;

        navToggle.addEventListener('click', () => {
            nav.classList.toggle('nav-open');
            navToggle.classList.toggle('active');
            body.classList.toggle('nav-lock-scroll'); // ป้องกันการเลื่อนหน้าจอเมื่อเมนูเปิด
        });
        
        // --- START: โค้ดสำหรับไฮไลท์เมนูปัจจุบัน ---
        try {
            const currentLocation = window.location.pathname;
            const navLinks = document.querySelectorAll('.site-nav .nav-links a');
            
            navLinks.forEach(link => {
                const linkPath = new URL(link.href).pathname;
                if (linkPath === currentLocation) {
                    link.parentElement.classList.add('active');
                }
            });
        } catch(e) {
            console.error("Error highlighting active menu:", e);
        }
        // --- END: โค้ดสำหรับไฮไลท์เมนูปัจจุบัน ---

        AOS.init({ once: false });

        // --- START: Theme Management (ปรับปรุงเล็กน้อย) ---
        const themeToggleButton = document.getElementById('themeToggleButton');
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
            let preferredTheme = getAdminPreference();
            if (preferredTheme) {
                applyTheme(preferredTheme);
            } else {
                // ถ้าไม่มีค่าที่บันทึกไว้ ให้ใช้ค่า default เป็น light
                applyTheme('light');
            }
        }
        
        if (themeToggleButton) {
            // ตั้งค่าเริ่มต้นของปุ่มสลับธีมตาม theme ปัจจุบัน
            if (document.body.classList.contains('dark-theme')){
                themeToggleButton.checked = true;
            }

            themeToggleButton.addEventListener('change', function() {
                const newTheme = this.checked ? 'dark' : 'light';
                applyTheme(newTheme);
                // บันทึกค่าเฉพาะ admin
                if (isAdmin) {
                  setAdminPreference(newTheme);
                }
            });
        }
        initializeTheme();
        // --- END: Theme Management ---
    });
  </script>
</body>
</html>
