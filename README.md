# Hotel Booking System - ระบบจัดการการจองโรงแรม

![PHP](https://img.shields.io/badge/PHP-7.4%2B-blue?style=for-the-badge&logo=php)
![MySQL](https://img.shields.io/badge/MySQL-5.7%2B-orange?style=for-the-badge&logo=mysql)
![JavaScript](https://img.shields.io/badge/JavaScript-ES6-yellow?style=for-the-badge&logo=javascript)
![License](https://img.shields.io/badge/License-MIT-green?style=for-the-badge)

ระบบจัดการการจองโรงแรม (Hotel Booking System) ที่ถูกออกแบบมาเพื่อการใช้งานจริงในโรงแรม มีฟังก์ชันการทำงานที่ครบวงจรตั้งแต่การแสดงสถานะห้องพักแบบเรียลไทม์, การสร้างการจองที่ยืดหยุ่น, การจัดการการเงิน, ไปจนถึงระบบรายงานสำหรับผู้บริหาร

---

### ✨ คุณสมบัติหลัก (Key Features)

* **หน้า Dashboard อัจฉริยะ:**
    * แสดงสถานะห้องพักทั้งหมดแบบเรียลไทม์ (ว่าง, ไม่ว่าง, รอเช็คอิน, มีจองล่วงหน้า, เกินกำหนด)
    * อัปเดตสถานะอัตโนมัติ (Polling)
    * มุมมองแบบ Grid และแบบตารางที่ปรับเปลี่ยนได้
    * ไอคอนแจ้งเตือนสำหรับห้องที่ใกล้ถึงเวลาเช็คเอาท์และห้องที่มียอดค้างชำระ

* **ระบบการจองที่ยืดหยุ่น:**
    * รองรับการจองห้องเดี่ยว และการจองหลายห้องในชื่อผู้จองเดียว (Multi-room Booking)
    * รองรับการจองแบบค้างคืน (Overnight) และชั่วคราว (Short-stay)
    * สร้างการจองจากหน้า Dashboard หรือหน้าปฏิทินได้โดยตรง

* **การจัดการการจองแบบกลุ่ม (Group Booking Management):**
    * ระบบผูกการจองหลายห้องไว้เป็นกลุ่มเดียวกันอัตโนมัติ
    * สามารถสร้างกลุ่มการจองจากห้องที่จองแยกกันได้
    * จัดการข้อมูลลูกค้า, หมายเหตุ, และสลิปของทั้งกลุ่มได้ในที่เดียว

* **การจัดการการเงินและสลิป:**
    * รองรับการแนบสลิปหลายใบต่อหนึ่งกลุ่มการจอง
    * จัดการยอดชำระ, ยอดค้างชำระ, และการคืนมัดจำได้อย่างเป็นระบบ
    * มีหน้าสำหรับออกใบเสร็จรับเงิน/บิลเงินสด (Cash Bill) ที่สามารถบันทึกเป็นรูปภาพหรือสั่งพิมพ์ได้

* **การจัดการห้องพักและการจองขั้นสูง:**
    * **ย้ายห้อง (Move Room):** สามารถย้ายการจองที่ Active อยู่ไปยังห้องอื่นที่ว่างได้
    * **ขยายเวลา (Extend Stay):** รองรับการขยายเวลาเป็นชั่วโมง หรือเพิ่มเป็นคืน
    * **แก้ไขการจอง:** แก้ไขข้อมูลลูกค้า, บริการเสริม, และยอดชำระเพิ่มเติมได้

* **หน้าจัดการสำหรับผู้ดูแลระบบ (`settings_management.php`):**
    * จัดการผู้ใช้งานและสิทธิ์ (Admin/Staff)
    * จัดการบริการเสริม (Add-ons) และราคา
    * จัดการราคาห้องพักแต่ละห้องได้โดยตรง
    * ตั้งค่าระบบกลาง เช่น ราคาต่อชั่วโมงเริ่มต้น

* **ระบบรายงานสำหรับผู้บริหาร (`report.php`):**
    * แสดงผล KPI ที่สำคัญ เช่น Occupancy Rate, ADR, RevPAR
    * กราฟแสดงแนวโน้มรายได้และสัดส่วนรายได้ตามโซน
    * ตารางประวัติการเข้าพักทั้งหมดพร้อมระบบค้นหาและ Pagination
    * **รายงานปิดยอด (Cash Out Report):** เครื่องมือสำหรับสรุปยอดเงินสด/เงินโอนตามช่วงเวลาที่กำหนด เพื่อการกระทบยอดประจำวัน/ประจำกะ

* **ระบบอัตโนมัติเบื้องหลัง:**
    * สคริปต์สำหรับลบไฟล์เก่าที่ไม่ใช้งานแล้วอัตโนมัติ (`cron_delete_archived_files.php`) เพื่อจัดการพื้นที่ Server

---

### 🚀 เทคโนโลยีที่ใช้ (Technology Stack)

* **Backend:** PHP (v7.4+)
* **Database:** MySQL / MariaDB
* **Frontend:** HTML5, CSS3, JavaScript (Vanilla JS, AJAX)
* **Libraries:**
    * [Chart.js](https://www.chartjs.org/) สำหรับการแสดงผลกราฟในหน้ารายงาน
    * [html2canvas](https://html2canvas.hertzen.com/) สำหรับแปลง HTML เป็นรูปภาพ (ในหน้าสรุปการจองและบิลเงินสด)
    * [Animate On Scroll (AOS)](https://michalsnik.github.io/aos/) สำหรับ Animation การแสดงผล

---

### ⚙️ วิธีการทำงานของระบบ (System Workflow)

1.  **หน้าหลัก (Dashboard):** `index.php` เป็นหน้าเริ่มต้นหลังล็อกอิน จะแสดงสถานะห้องพักทั้งหมด โดยใช้ JavaScript เรียกไปยัง `api.php?action=get_room_statuses` ทุกๆ 30 วินาทีเพื่ออัปเดตสถานะแบบเรียลไทม์
2.  **การสร้าง/แก้ไขการจอง:** ผู้ใช้จะถูกส่งไปยังหน้า `booking.php` ซึ่งเป็นฟอร์มหลัก การกดบันทึกจะส่งข้อมูลผ่าน AJAX ไปยัง `api.php`
3.  **API หลัก:** `api.php` คือหัวใจของระบบ จัดการ Logic ทั้งหมด เช่น:
    * `action=create`: สร้างการจอง, สร้าง `booking_group`, บันทึก `booking_addons` และ `booking_group_receipts`
    * `action=update`: จัดการการเช็คอิน, การเช็คเอาท์, และการลบการจอง
    * `action=extend_stay`: จัดการการขยายเวลา
    * `action=move_booking`: จัดการการย้ายห้อง
    * และ action อื่นๆ อีกมากมาย...
4.  **การแสดงผล:** ไฟล์ PHP ในโฟลเดอร์ `pages/` จะถูกเรียกใช้เพื่อแสดงหน้าเว็บต่างๆ โดยทุกไฟล์จะ `require` ไฟล์ `templates/layout.php` เพื่อสร้างโครงหน้าเว็บที่เหมือนกัน
5.  **ฐานข้อมูล:** `bootstrap.php` ทำหน้าที่เชื่อมต่อฐานข้อมูลและตั้งค่าพื้นฐานของระบบ `api.php` จะติดต่อกับฐานข้อมูลผ่านไฟล์นี้

---

### 🔧 การติดตั้งและตั้งค่า (Installation & Setup)

1.  **Prerequisites:**
    * Web Server (เช่น Apache, Nginx)
    * PHP (เวอร์ชัน 7.4 หรือสูงกว่า) พร้อม Extension `pdo_mysql` และ `gd`
    * MySQL หรือ MariaDB Database Server

2.  **Database Setup:**
    * สร้างฐานข้อมูลใหม่ใน MySQL/MariaDB (เช่น `resortbn_booking`)
    * นำเข้า (Import) ไฟล์ `.sql` ที่ได้รับมาเข้าสู่ฐานข้อมูลที่สร้างขึ้นผ่านเครื่องมืออย่าง phpMyAdmin

3.  **File Placement:**
    * อัปโหลดไฟล์และโฟลเดอร์ทั้งหมดในโปรเจกต์ `hotel_booking/` ไปยัง Server ของคุณ (เช่น ใน `public_html/hotel_booking/`)

4.  **Configuration (สำคัญที่สุด):**
    * เปิดไฟล์ `bootstrap.php`
    * แก้ไขค่าคงที่ (Constants) สำหรับการเชื่อมต่อฐานข้อมูลให้ถูกต้อง:
        ```php
        define('DB_HOST', 'localhost');
        define('DB_NAME', 'resortbn_booking'); // << ชื่อฐานข้อมูลของคุณ
        define('DB_USER', 'resortbn_root');   // << ชื่อผู้ใช้ฐานข้อมูลของคุณ
        define('DB_PASS', 'Kaokam9119@kao');  // << รหัสผ่านฐานข้อมูลของคุณ
        ```
    * แก้ไขที่อยู่ของเว็บไซต์ให้ถูกต้อง:
        ```php
        // ตัวอย่างเช่นถ้าเว็บไซต์ของคุณคือ [https://myhotel.com](https://myhotel.com) และวางโปรเจกต์ไว้ที่ root
        define('BASE_URL', '[https://myhotel.com](https://myhotel.com)');
        define('BASE_PATH', '/');

        // หรือถ้าเว็บไซต์ของคุณคือ [https://myhotel.com/booking-system](https://myhotel.com/booking-system)
        define('BASE_URL', '[https://myhotel.com](https://myhotel.com)');
        define('BASE_PATH', '/booking-system/');
        ```

5.  **Web Server Configuration (ถ้าจำเป็น):**
    * เพื่อให้ระบบทำงานได้สมบูรณ์ อาจจะต้องมีการตั้งค่า `.htaccess` (สำหรับ Apache) เพื่อจัดการ URL Routing และความปลอดภัยเบื้องต้น

6.  **ตั้งค่า Cron Job (สำหรับ Production):**
    * เพื่อให้ระบบลบไฟล์เก่าอัตโนมัติ ให้ตั้งค่า Cron Job บน Server ของคุณให้เรียกไฟล์ `cron_delete_archived_files.php` วันละ 1 ครั้ง (เช่น เวลาตี 3)
    * **ตัวอย่างคำสั่ง Cron:**
        ```bash
        0 3 * * * /usr/bin/php /path/to/your/project/hotel_booking/cron_delete_archived_files.php >/dev/null 2>&1
        ```
        *(หมายเหตุ: path ไปยัง php และโปรเจกต์อาจแตกต่างกันไปในแต่ละ Server)*

---

### 🗂️ โครงสร้างไฟล์และโฟลเดอร์

```
hotel_booking/
├── bootstrap.php                  # ไฟล์ตั้งค่าหลัก, เชื่อมต่อ DB, จัดการ Session
├── templates/
│   └── layout.php                 # โครง HTML หลักสำหรับทุกหน้า
├── pages/
│   ├── index.php                  # หน้า Dashboard หลัก
│   ├── booking.php                # ฟอร์มสร้าง/แก้ไขการจอง
│   ├── api.php                    # Endpoints สำหรับจัดการข้อมูลทั้งหมด (Backend Logic)
│   ├── report.php                 # หน้ารายงานสำหรับผู้บริหาร
│   ├── settings_management.php    # หน้าจัดการการตั้งค่าต่างๆ
│   └── ... (ไฟล์หน้าอื่นๆ)
├── assets/
│   ├── css/main.css               # ไฟล์สไตล์หลัก
│   └── js/main.js                 # ไฟล์ JavaScript หลัก
└── uploads/
    ├── receipts/                  # โฟลเดอร์เก็บสลิปการจอง
    └── deposit/                   # โฟลเดอร์เก็บสลิปการคืนมัดจำ
```

---

### 🧰 เครื่องมือสำหรับนักพัฒนา

* **Developer Control Panel (`pages/all_control.php`):**
    * เครื่องมือสำหรับเข้าถึงและแก้ไขข้อมูลในฐานข้อมูลโดยตรง
    * เข้าถึงได้ด้วยรหัสผ่านที่กำหนดไว้ในไฟล์ (`DEV_PASSWORD`)
    * **คำเตือน:** เป็นเครื่องมือที่ทรงพลังและมีความเสี่ยงสูง **ห้ามมีไฟล์นี้อยู่บน Production Server เด็ดขาด** ควรใช้ในขั้นตอนการพัฒนาและดีบักเท่านั้น

* **Cron Job Script (`cron_delete_archived_files.php`):**
    * สคริปต์สำหรับทำงานเบื้องหลังเพื่อลบไฟล์สลิปและข้อมูลกลุ่มการจองที่ถูกเก็บในประวัติไว้นานกว่า 3 เดือน เพื่อรักษาพื้นที่จัดเก็บบน Server

---

### 📜 สิทธิ์การใช้งาน (License)

โปรเจกต์นี้อยู่ภายใต้สิทธิ์การใช้งานแบบ MIT License