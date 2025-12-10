# Hotel Supreme Management System

![Version](https://img.shields.io/badge/version-1.0.0-blue.svg)
![PHP](https://img.shields.io/badge/PHP-8.0%2B-777BB4?logo=php&logoColor=white)
![MySQL](https://img.shields.io/badge/MySQL-Database-4479A1?logo=mysql&logoColor=white)
![Status](https://img.shields.io/badge/Maintained-Yes-green.svg)

**Hotel Supreme** is a comprehensive, web-based Hotel Management System (HMS) designed to streamline daily hotel operations. It features a unified interface for handling room management, guest reservations, inventory tracking, housekeeping inspections, and analytical reporting.

---

## Key Features

### 1. Interactive Dashboard
* **Real-time Stats:** View total guests, occupied rooms, pending damage reports, and low stock items at a glance.
* **Quick Actions:** One-click access to common tasks like Check-ins, Inspections, and Reports.
* **Live Clock:** Displays current system time and date.

### 2. Room Management
* **Status Tracking:** Monitor live room statuses: *Vacant, Occupied, Reserved,* or *Maintenance*.
* **CRUD Operations:** Easily add, edit, and delete room records.
* **Filtering & Search:** Filter by room type or status, and search by room number.
* **Excel Export:** Download the full room list for offline records.

### 3. Guest Management
* **Check-in/Check-out:** Seamless workflow for guest arrival and departure.
* **Booking Details:** Capture booking source (e.g., Agoda, Walk-in), meal plans, and pax count.
* **Conflict Prevention:** Automatic validation ensures check-out dates are after check-in dates.
* **Guest History:** Maintain records of past and current stays.

### 4. Housekeeping & Inspections
* **Digital Checklist:** Interactive form for inspecting room amenities (Linens, Toiletries, Electronics).
* **Damage Reporting:** Log damaged, missing, or replacement-needed items directly during inspection.
* **Auto-Maintenance:** Automatically updates room status to "Maintenance" if critical issues are flagged.

### 5. Inventory Control
* **Stock Management:** Track quantities for categories like Linens, Toiletries, and Furniture.
* **Low Stock Alerts:** Visual indicators for items that fall below their reorder point.
* **Restocking:** Simple interface to add stock to existing items.
* **Usage Integration:** Resolving damage reports automatically deducts replacement items from inventory.

### 6. Reports & Analytics
* **Visual Charts:** Interactive Doughnut and Line charts (powered by Chart.js) for issue types and resolution trends.
* **Advanced Filtering:** Filter reports by date range, issue type (Damaged/Missing), and status.
* **Export Options:** Export reports to **Excel** or print directly to **PDF**.

---

## Tech Stack

* **Backend:** PHP (Native)
* **Database:** MySQL
* **Frontend:** HTML5, CSS3, JavaScript (Vanilla & jQuery)
* **UI Libraries:**
    * [SweetAlert2](https://sweetalert2.github.io/) - Beautiful popup notifications and confirmations.
    * [Chart.js](https://www.chartjs.org/) - Interactive data visualization.
    * [Select2](https://select2.org/) - Enhanced dropdown boxes with search.
    * [Flatpickr](https://flatpickr.js.org/) - Lightweight and powerful datetime picker.
    * [FontAwesome](https://fontawesome.com/) - Comprehensive icon library.

---

## Project Structure

```text
hotel-supreme/
├── db_connect.php       # Database connection settings
├── index.php            # Entry point (redirects to login)
├── login.php            # User authentication
├── logout.php           # Session destruction
├── menu.php             # Main Dashboard
├── sidebar.php          # Navigation sidebar component
├── rooms.php            # Room management logic & view
├── rooms_inspection.php # Housekeeping inspection form
├── guest_list.php       # Guest check-in/out management
├── inventory.php        # Inventory tracking
├── reports.php          # Analytics and reporting
└── README.md            # Project documentation
