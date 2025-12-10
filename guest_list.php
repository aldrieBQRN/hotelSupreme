<?php
session_start();
include 'db_connect.php';

// Check if user is logged in
if (!isset($_SESSION['logged_in'])) {
    header("Location: login.php");
    exit;
}

// --- FILTER PARAMETERS ---
$search = isset($_GET['search']) ? mysqli_real_escape_string($conn, $_GET['search']) : '';
$room_filter = isset($_GET['room']) ? mysqli_real_escape_string($conn, $_GET['room']) : '';
$status_filter = isset($_GET['status']) ? mysqli_real_escape_string($conn, $_GET['status']) : '';

// Build Query Condition
$where_clause = "WHERE 1=1";
if (!empty($status_filter)) {
    $where_clause .= " AND g.status = '$status_filter'";
}
if (!empty($search)) {
    $where_clause .= " AND g.guest_name LIKE '%$search%'";
}
if (!empty($room_filter)) {
    $where_clause .= " AND g.room_id = '$room_filter'";
}

// --- EXPORT HANDLERS (Excel Only) ---
if (isset($_GET['export']) && $_GET['export'] == 'excel') {
    // Export gets ALL data matching filters (no pagination)
    $export_sql = "SELECT g.*, r.room_number, r.room_type 
                   FROM guest_bookings g 
                   JOIN rooms r ON g.room_id = r.room_id 
                   $where_clause 
                   ORDER BY g.created_at ASC"; 
    $result = $conn->query($export_sql);

    header("Content-Type: application/vnd.ms-excel");
    header("Content-Disposition: attachment; filename=HotelSupreme_Guests_".date('Y-m-d').".xls");
    
    echo '<table border="1">';
    echo '<tr><td colspan="11" style="font-size: 14pt; font-weight: bold; text-align: center; height: 30px;">Hotel Supreme - Guest List</td></tr>';
    echo '<tr><td colspan="11" style="text-align: center; font-style: italic;">Generated on ' . date('F j, Y g:i A') . '</td></tr>';
    echo '<tr><td colspan="11"></td></tr>';
    
    echo '<tr style="background-color: #f0f0f0;">
            <th>Booking ID</th>
            <th>Guest Name</th>
            <th>Room</th>
            <th>Plan</th>
            <th>Source</th>
            <th>Pax</th>
            <th>Check-in</th>
            <th>Check-out</th>
            <th>Status</th>
            <th>Booked On</th>
            <th>Remarks</th>
          </tr>';
          
    while($row = $result->fetch_assoc()) {
        $booked_on = $row['created_at'] ? date('Y-m-d H:i', strtotime($row['created_at'])) : '-';
        echo "<tr>
                <td>{$row['booking_id']}</td>
                <td>{$row['guest_name']}</td>
                <td>{$row['room_number']} ({$row['room_type']})</td>
                <td>{$row['plan']}</td>
                <td>{$row['source']}</td>
                <td>{$row['pax']}</td>
                <td>{$row['check_in_date']}</td>
                <td>{$row['check_out_date']}</td>
                <td>{$row['status']}</td>
                <td>{$booked_on}</td>
                <td>{$row['remarks']}</td>
              </tr>";
    }
    echo '</table>';
    exit;
}

// --- PAGINATION LOGIC ---
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$limit = 5; // Rows per page
$offset = ($page - 1) * $limit;

// 1. Get Total Count for Pagination
$count_sql = "SELECT COUNT(*) as total FROM guest_bookings g $where_clause";
$count_result = $conn->query($count_sql);
$total_records = $count_result->fetch_assoc()['total'];
$total_pages = ceil($total_records / $limit);

// --- BACKEND HANDLERS (POST) ---

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    
    // 1. Handle Check-In
    if (isset($_POST['action']) && $_POST['action'] == 'checkin') {
        $guest_name = mysqli_real_escape_string($conn, $_POST['guest_name']);
        $room_id = mysqli_real_escape_string($conn, $_POST['room_id']);
        $check_in = mysqli_real_escape_string($conn, $_POST['check_in_date']);
        $check_out = mysqli_real_escape_string($conn, $_POST['check_out_date']);
        $plan = mysqli_real_escape_string($conn, $_POST['plan']);
        $source = mysqli_real_escape_string($conn, $_POST['source']);
        $pax = intval($_POST['pax']);
        $status = mysqli_real_escape_string($conn, $_POST['status']);
        $remarks = mysqli_real_escape_string($conn, $_POST['remarks']);

        if (strtotime($check_out) <= strtotime($check_in)) {
            $_SESSION['swal_error'] = "Error: Check-out date must be AFTER the check-in date.";
            header("Location: guest_list.php");
            exit;
        }

        $conn->begin_transaction();
        try {
            $sql = "INSERT INTO guest_bookings (room_id, guest_name, plan, source, pax, check_in_date, check_out_date, status, remarks) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("isssissss", $room_id, $guest_name, $plan, $source, $pax, $check_in, $check_out, $status, $remarks);
            $stmt->execute();

            $new_room_status = ($status == 'Checked In') ? 'Occupied' : 'Reserved';
            $conn->query("UPDATE rooms SET status = '$new_room_status' WHERE room_id = '$room_id'");

            $conn->commit();
            $_SESSION['swal_success'] = "Guest checked in successfully!";
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['swal_error'] = "Error: " . $e->getMessage();
        }
        header("Location: guest_list.php");
        exit;
    }

    // 2. Handle Update (Edit Guest)
    if (isset($_POST['action']) && $_POST['action'] == 'update') {
        $booking_id = intval($_POST['booking_id']);
        $guest_name = mysqli_real_escape_string($conn, $_POST['guest_name']);
        $new_room_id = intval($_POST['room_id']);
        $old_room_id = intval($_POST['old_room_id']); 
        $check_in = mysqli_real_escape_string($conn, $_POST['check_in_date']);
        $check_out = mysqli_real_escape_string($conn, $_POST['check_out_date']);
        $plan = mysqli_real_escape_string($conn, $_POST['plan']);
        $source = mysqli_real_escape_string($conn, $_POST['source']);
        $pax = intval($_POST['pax']);
        $status = mysqli_real_escape_string($conn, $_POST['status']);
        $remarks = mysqli_real_escape_string($conn, $_POST['remarks']);

        if (strtotime($check_out) <= strtotime($check_in)) {
            $_SESSION['swal_error'] = "Error: Check-out date must be AFTER the check-in date.";
            header("Location: guest_list.php");
            exit;
        }

        $conn->begin_transaction();
        try {
            $sql = "UPDATE guest_bookings SET 
                    guest_name=?, room_id=?, plan=?, source=?, pax=?, 
                    check_in_date=?, check_out_date=?, status=?, remarks=? 
                    WHERE booking_id=?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("sisssssssi", $guest_name, $new_room_id, $plan, $source, $pax, $check_in, $check_out, $status, $remarks, $booking_id);
            $stmt->execute();

            if ($new_room_id != $old_room_id) {
                $conn->query("UPDATE rooms SET status = 'Vacant' WHERE room_id = $old_room_id");
            }

            $room_status_update = 'Vacant';
            if ($status == 'Checked In') $room_status_update = 'Occupied';
            elseif ($status == 'Reserved') $room_status_update = 'Reserved';
            elseif ($status == 'Checked Out') $room_status_update = 'Vacant';

            if ($status != 'Checked Out') {
                $conn->query("UPDATE rooms SET status = '$room_status_update' WHERE room_id = $new_room_id");
            } elseif ($status == 'Checked Out' && $new_room_id == $old_room_id) {
                 $conn->query("UPDATE rooms SET status = 'Vacant' WHERE room_id = $new_room_id");
            }

            $conn->commit();
            $_SESSION['swal_success'] = "Guest details updated successfully!";
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['swal_error'] = "Error: " . $e->getMessage();
        }
        header("Location: guest_list.php");
        exit;
    }

    // 3. Handle Check-Out
    if (isset($_POST['action']) && $_POST['action'] == 'checkout') {
        $booking_id = intval($_POST['booking_id']);
        $room_id = intval($_POST['room_id']);

        $conn->begin_transaction();
        try {
            $conn->query("UPDATE guest_bookings SET status = 'Checked Out' WHERE booking_id = $booking_id");
            $conn->query("UPDATE rooms SET status = 'Vacant' WHERE room_id = $room_id");
            $conn->commit();
            $_SESSION['swal_success'] = "Guest checked out successfully!";
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['swal_error'] = "Error: " . $e->getMessage();
        }
        header("Location: guest_list.php");
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Guest Management - Hotel Supreme</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">

    <style>
        /* Shared Styles */
        :root {
            --primary: #9333ea; 
            --primary-dark: #7e22ce; 
            --secondary: #64748b;
            --success: #10b981; 
            --warning: #f59e0b; 
            --danger: #ef4444;
            --light: #f8fafc; 
            --dark: #1e293b; 
            --gray: #e2e8f0; 
            --sidebar-width: 280px;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', sans-serif; background: var(--light); color: var(--dark); min-height: 100vh; }
        .main-content { margin-left: var(--sidebar-width); min-height: 100vh; transition: margin-left 0.3s ease; }
        .container { max-width: 1400px; margin: 0 auto; padding: 30px; }
        
        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; }
        .header-title { display: flex; align-items: center; gap: 15px; }
        .page-header h1 { font-size: 32px; font-weight: 700; color: var(--dark); display: flex; align-items: center; gap: 12px; margin: 0; }
        .page-header h1 i { color: var(--primary); }
        .action-buttons { display: flex; gap: 12px; align-items: center; }
        .btn { padding: 12px 24px; border-radius: 10px; font-weight: 600; font-size: 14px; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; gap: 8px; transition: all 0.3s; border: 2px solid transparent; text-decoration: none; }
        .btn-primary { background: var(--primary); color: white; }
        .btn-primary:hover { background: var(--primary-dark); transform: translateY(-2px); }
        .btn-success { background: var(--success); color: white; }
        .btn-success:hover { background: #0da271; transform: translateY(-2px); }
        .btn-secondary { background: white; color: var(--dark); border-color: var(--gray); }
        .btn-secondary:hover { border-color: var(--primary); background: var(--light); }
        .btn-danger { background: var(--danger); color: white; }
        .btn-danger:hover { background: #c23b3b; transform: translateY(-2px); }

        .guest-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: white; border-radius: 12px; padding: 20px; box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1); display: flex; align-items: center; gap: 15px; }
        .stat-icon { width: 50px; height: 50px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 24px; flex-shrink: 0; }
        .stat-content { flex: 1; }
        .stat-value { font-size: 28px; font-weight: 700; line-height: 1; }
        .stat-label { font-size: 14px; color: var(--secondary); margin-top: 4px; }
        
        .filters-section { background: white; border-radius: 16px; padding: 24px; margin-bottom: 30px; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1); }
        .filters-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; align-items: end; }
        .filter-group { flex: 1; }
        .filter-group label { display: block; font-size: 14px; font-weight: 500; color: var(--dark); margin-bottom: 8px; }
        .filter-input { width: 100%; padding: 12px 16px; border: 2px solid var(--gray); border-radius: 10px; font-size: 14px; }
        
        .select2-container { width: 100% !important; }
        .select2-container--default .select2-selection--single { height: 46px; border: 2px solid var(--gray); border-radius: 10px; padding: 8px 12px; display: flex; align-items: center; }
        .select2-container--default .select2-selection--single .select2-selection__arrow { height: 44px; right: 12px; }
        .select2-container--default .select2-selection--single:focus { border-color: var(--primary); }
        
        .filter-buttons { display: flex; gap: 10px; }
        .filter-buttons .btn { width: 100%; height: 46px; }

        .table-container { background: white; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1); }
        .table-responsive { overflow-x: auto; }
        .guest-table { width: 100%; border-collapse: collapse; min-width: 1200px; }
        .guest-table th { padding: 16px 20px; text-align: left; font-weight: 600; color: var(--dark); border-bottom: 2px solid var(--gray); white-space: nowrap; background: var(--light); }
        .guest-table tbody tr { border-bottom: 1px solid var(--gray); transition: background-color 0.3s; }
        .guest-table tbody tr:hover { background: rgba(147, 51, 234, 0.05); }
        .guest-table td { padding: 16px 20px; vertical-align: middle; }
        .guest-info { display: flex; align-items: center; gap: 12px; }
        .guest-details h4 { font-weight: 600; color: var(--dark); margin-bottom: 2px; }
        .guest-details p { font-size: 12px; color: var(--secondary); }
        /* Room badge background to Purple RGBA */
        .room-badge { display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; background: rgba(147, 51, 234, 0.1); color: var(--primary); border-radius: 20px; font-size: 12px; font-weight: 600; }
        .status-badge { padding: 6px 12px; border-radius: 20px; font-size: 12px; font-weight: 600; display: inline-block; }
        .status-checked-in { background: rgba(16, 185, 129, 0.1); color: var(--success); }
        .status-checked-out { background: rgba(100, 116, 139, 0.1); color: var(--secondary); }
        .status-reserved { background: rgba(245, 158, 11, 0.1); color: var(--warning); }
        .plan-badge, .source-badge { padding: 4px 8px; background: var(--light); color: var(--secondary); border-radius: 6px; font-size: 12px; font-weight: 500; }
        .pax-badge { width: 32px; height: 32px; border-radius: 50%; background: var(--light); color: var(--dark); display: flex; align-items: center; justify-content: center; font-weight: 600; font-size: 14px; }
        .date-cell .date { font-weight: 600; color: var(--dark); }
        .date-cell .days { font-size: 12px; color: var(--secondary); margin-top: 2px; }
        .remarks-cell { max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .guest-actions { display: flex; gap: 8px; }
        .action-btn { width: 36px; height: 36px; border-radius: 8px; display: flex; align-items: center; justify-content: center; background: var(--light); color: var(--secondary); cursor: pointer; transition: all 0.3s; border: none; }
        .action-btn:hover { background: var(--primary); color: white; transform: translateY(-2px); }
        
        /* Pagination Styles */
        .pagination { display: flex; justify-content: center; align-items: center; padding: 24px; gap: 8px; flex-wrap: wrap; }
        .page-btn { width: 40px; height: 40px; border-radius: 8px; display: flex; align-items: center; justify-content: center; background: white; border: 1px solid var(--gray); color: var(--dark); text-decoration: none; transition: all 0.3s; }
        .page-btn.active { background: var(--primary); color: white; border-color: var(--primary); }
        .page-btn:hover:not(.active):not(.disabled) { background: var(--light); border-color: var(--primary); }
        .page-btn.disabled { opacity: 0.5; cursor: not-allowed; pointer-events: none; background: #f1f5f9; }
        
        .empty-state { text-align: center; padding: 60px 20px; }
        .empty-state i { font-size: 64px; color: var(--gray); margin-bottom: 20px; }
        
        .modal { display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0, 0, 0, 0.5); z-index: 1000; align-items: center; justify-content: center; }
        .modal.active { display: flex; }
        .modal-content { background: white; border-radius: 16px; width: 90%; max-width: 500px; max-height: 90vh; overflow-y: auto; position: relative; }
        .modal-header { padding: 24px; border-bottom: 1px solid var(--gray); display: flex; justify-content: space-between; align-items: center; }
        .modal-header h3 { font-size: 20px; font-weight: 600; color: var(--dark); }
        .modal-close { background: none; border: none; font-size: 20px; color: var(--secondary); cursor: pointer; transition: color 0.3s; }
        .modal-close:hover { color: var(--danger); }
        .modal-body { padding: 24px; }
        .modal-footer { padding: 24px; border-top: 1px solid var(--gray); display: flex; justify-content: flex-end; gap: 12px; }
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; margin-bottom: 8px; font-weight: 500; }
        .form-control { width: 100%; padding: 12px 16px; border: 2px solid var(--gray); border-radius: 10px; font-size: 14px; }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }

        /* Custom Table for SweetAlert View */
        .swal-table-container { width: 100%; overflow: hidden; border-radius: 10px; border: 1px solid #e2e8f0; margin-top: 15px; }
        .swal-table { width: 100%; border-collapse: collapse; text-align: left; font-size: 14px; }
        .swal-table th { background: #f8fafc; color: #64748b; padding: 12px 15px; width: 35%; font-weight: 600; border-bottom: 1px solid #e2e8f0; }
        .swal-table td { padding: 12px 15px; color: #1e293b; border-bottom: 1px solid #e2e8f0; }
        .swal-table tr:last-child th, .swal-table tr:last-child td { border-bottom: none; }

        @media (max-width: 1024px) { 
            .main-content { margin-left: 0; } 
        }

        @media (max-width: 768px) {
            .container { padding: 20px; }
            .page-header { flex-direction: column; align-items: flex-start; gap: 15px; }
            .header-title { width: 100%; justify-content: flex-start; }
            .header-title h1 { font-size: 24px; }
            .action-buttons { width: 100%; gap: 10px; margin-top: 10px; }
            .action-buttons .btn { flex: 1; justify-content: center; }
            .guest-stats { grid-template-columns: repeat(2, 1fr); }
            .filters-grid { grid-template-columns: 1fr; }
            .modal-content { width: 90%; margin: 20px; padding: 20px; }
            .form-row { grid-template-columns: 1fr; }
        }

        @media (max-width: 480px) {
            .guest-stats { grid-template-columns: 1fr; }
            .room-badge { font-size: 14px; padding: 4px 8px; }
            .page-btn { width: 32px; height: 32px; font-size: 14px; }
        }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>
    
    <div class="main-content">
        <div class="container">
            <div class="page-header">
                <div class="header-title">
                    <button class="menu-toggle" onclick="toggleSidebar()"><i class="fas fa-bars"></i></button>
                    <h1><i class="fas fa-users"></i> Guest Management</h1>
                </div>
                <div class="action-buttons">
                    <button class="btn btn-primary" onclick="openCheckinModal()">
                        <i class="fas fa-user-plus"></i> New Check-in
                    </button>
                    <button class="btn btn-secondary" onclick="exportData()">
                        <i class="fas fa-file-excel"></i> Export
                    </button>
                </div>
            </div>
            
            <div class="guest-stats">
                <?php
                $stats = $conn->query("
                    SELECT 
                        COUNT(*) as total_guests,
                        SUM(CASE WHEN status = 'Checked In' THEN 1 ELSE 0 END) as checked_in,
                        SUM(CASE WHEN status = 'Reserved' THEN 1 ELSE 0 END) as reserved,
                        SUM(CASE WHEN DATE(check_in_date) = CURDATE() THEN 1 ELSE 0 END) as arrivals_today
                    FROM guest_bookings
                ")->fetch_assoc();
                ?>
                <div class="stat-card">
                    <div class="stat-icon" style="background: rgba(147, 51, 234, 0.1); color: var(--primary);"><i class="fas fa-users"></i></div>
                    <div class="stat-content">
                        <div class="stat-value"><?php echo $stats['total_guests']; ?></div>
                        <div class="stat-label">Total Guests</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background: rgba(16, 185, 129, 0.1); color: var(--success);"><i class="fas fa-bed"></i></div>
                    <div class="stat-content">
                        <div class="stat-value"><?php echo $stats['checked_in']; ?></div>
                        <div class="stat-label">Currently Checked In</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background: rgba(245, 158, 11, 0.1); color: var(--warning);"><i class="fas fa-calendar-check"></i></div>
                    <div class="stat-content">
                        <div class="stat-value"><?php echo $stats['reserved']; ?></div>
                        <div class="stat-label">Reservations</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background: rgba(239, 68, 68, 0.1); color: var(--danger);"><i class="fas fa-sign-in-alt"></i></div>
                    <div class="stat-content">
                        <div class="stat-value"><?php echo $stats['arrivals_today']; ?></div>
                        <div class="stat-label">Arrivals Today</div>
                    </div>
                </div>
            </div>
            
            <div class="filters-section">
                <form method="GET" action="" id="filterForm">
                    <div class="filters-grid">
                        <div class="filter-group">
                            <label><i class="fas fa-search"></i> Search Guests</label>
                            <input type="text" name="search" class="filter-input" placeholder="Search by guest name..." value="<?php echo htmlspecialchars($search); ?>">
                        </div>
                        <div class="filter-group">
                            <label><i class="fas fa-bed"></i> Room Number</label>
                            <select name="room" class="filter-input select2-main">
                                <option value="">All Rooms</option>
                                <?php
                                $rooms = $conn->query("SELECT room_id, room_number FROM rooms ORDER BY room_number");
                                while($room = $rooms->fetch_assoc()) {
                                    $selected = $room['room_id'] == $room_filter ? 'selected' : '';
                                    echo "<option value='{$room['room_id']}' $selected>{$room['room_number']}</option>";
                                }
                                ?>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label><i class="fas fa-filter"></i> Status</label>
                            <select name="status" class="filter-input select2-main">
                                <option value="">All Statuses</option>
                                <option value="Checked In" <?php echo $status_filter == 'Checked In' ? 'selected' : ''; ?>>Checked In</option>
                                <option value="Reserved" <?php echo $status_filter == 'Reserved' ? 'selected' : ''; ?>>Reserved</option>
                                <option value="Checked Out" <?php echo $status_filter == 'Checked Out' ? 'selected' : ''; ?>>Checked Out</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label style="color: transparent;">Actions</label>
                            <div class="filter-buttons">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-search"></i> Apply
                                </button>
                                <button type="button" class="btn btn-danger" onclick="clearFilters()">
                                    <i class="fas fa-undo"></i> Clear
                                </button>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
            
            <div class="table-container">
                <div class="table-responsive">
                    <table class="guest-table">
                        <thead>
                            <tr>
                                <th>Guest</th>
                                <th>Room</th>
                                <th>Plan</th>
                                <th>Source</th>
                                <th>Pax</th>
                                <th>Check-in</th>
                                <th>Check-out</th>
                                <th>Status</th>
                                <th>Remarks</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            // Fetch Data with Pagination Limit and Offset
                            $sql = "SELECT g.*, r.room_number, r.room_type 
                                    FROM guest_bookings g 
                                    JOIN rooms r ON g.room_id = r.room_id 
                                    $where_clause 
                                    ORDER BY g.created_at ASC 
                                    LIMIT $limit OFFSET $offset";
                            
                            $result = $conn->query($sql);
                            
                            if ($result->num_rows > 0) {
                                while($row = $result->fetch_assoc()) {
                                    $check_in = new DateTime($row['check_in_date']);
                                    $check_out = new DateTime($row['check_out_date']);
                                    $interval = $check_in->diff($check_out);
                                    $nights = $interval->days;
                                    $status_class = "status-".strtolower(str_replace(' ', '-', $row['status']));
                                    $rowData = htmlspecialchars(json_encode($row), ENT_QUOTES, 'UTF-8');
                                    ?>
                                    <tr>
                                        <td>
                                            <div class="guest-info">
                                                <div class="guest-details">
                                                    <h4><?php echo htmlspecialchars($row['guest_name']); ?></h4>
                                                    <p><?php echo htmlspecialchars($row['room_type']); ?></p>
                                                </div>
                                            </div>
                                        </td>
                                        <td><span class="room-badge"><i class="fas fa-door-closed"></i> <?php echo htmlspecialchars($row['room_number']); ?></span></td>
                                        <td><span class="plan-badge"><?php echo htmlspecialchars($row['plan']); ?></span></td>
                                        <td><span class="source-badge"><?php echo htmlspecialchars($row['source']); ?></span></td>
                                        <td><div class="pax-badge"><?php echo $row['pax']; ?></div></td>
                                        <td class="date-cell">
                                            <div class="date"><?php echo date('M j, Y', strtotime($row['check_in_date'])); ?></div>
                                        </td>
                                        <td class="date-cell">
                                            <div class="date"><?php echo date('M j, Y', strtotime($row['check_out_date'])); ?></div>
                                        </td>
                                        <td><span class="status-badge <?php echo $status_class; ?>"><?php echo $row['status']; ?></span></td>
                                        <td class="remarks-cell" title="<?php echo htmlspecialchars($row['remarks']); ?>">
                                            <?php echo !empty($row['remarks']) ? htmlspecialchars(substr($row['remarks'], 0, 30)).'...' : '<span style="color: var(--secondary);">-</span>'; ?>
                                        </td>
                                        <td>
                                            <div class="guest-actions">
                                                <button class="action-btn" title="View Details" onclick="viewGuest(<?php echo $rowData; ?>)">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                                <button class="action-btn" title="Edit" onclick="openEditModal(<?php echo $rowData; ?>)">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <?php if ($row['status'] == 'Checked In'): ?>
                                                <button class="action-btn" title="Check Out" style="color: var(--success);" onclick="checkoutGuest(<?php echo $row['booking_id']; ?>, <?php echo $row['room_id']; ?>)">
                                                    <i class="fas fa-sign-out-alt"></i>
                                                </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php
                                }
                            } else {
                                echo '<tr><td colspan="10"><div class="empty-state"><i class="fas fa-users-slash"></i><h3>No guests found</h3></div></td></tr>';
                            }
                            ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($total_pages > 1): ?>
                <div class="pagination">
                    <?php 
                    $queryParams = $_GET;
                    unset($queryParams['page']); // Remove page param to rebuild it
                    $baseUrl = '?' . http_build_query($queryParams);
                    $baseUrl .= !empty($queryParams) ? '&' : '';
                    ?>
                    
                    <?php 
                    // Previous Link
                    $prev_class = ($page <= 1) ? 'disabled' : ''; 
                    ?>
                    <a href="<?php echo $baseUrl . 'page=' . ($page - 1); ?>" class="page-btn <?php echo $prev_class; ?>">
                        <i class="fas fa-chevron-left"></i>
                    </a>

                    <?php for ($i = 1; $i <= $total_pages; $i++): 
                        $active_class = ($page == $i) ? 'active' : '';
                    ?>
                        <a href="<?php echo $baseUrl . 'page=' . $i; ?>" class="page-btn <?php echo $active_class; ?>"><?php echo $i; ?></a>
                    <?php endfor; ?>

                    <?php 
                    // Next Link
                    $next_class = ($page >= $total_pages) ? 'disabled' : ''; 
                    ?>
                    <a href="<?php echo $baseUrl . 'page=' . ($page + 1); ?>" class="page-btn <?php echo $next_class; ?>">
                        <i class="fas fa-chevron-right"></i>
                    </a>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <div class="modal" id="checkinModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-user-plus"></i> New Guest Check-in</h3>
                <button class="modal-close" onclick="closeCheckinModal()">&times;</button>
            </div>
            <div class="modal-body">
                <form id="checkinForm" method="POST" action="">
                    <input type="hidden" name="action" value="checkin">
                    
                    <div class="form-group">
                        <label>Guest Name *</label>
                        <input type="text" class="form-control" name="guest_name" required>
                    </div>
                    <div class="form-group">
                        <label>Room *</label>
                        <select class="form-control select2-modal" name="room_id" required style="width: 100%;">
                            <option value="">Select Room</option>
                            <?php
                            $available_rooms = $conn->query("
                                SELECT r.room_id, r.room_number, r.room_type, r.status 
                                FROM rooms r 
                                WHERE r.status IN ('Vacant', 'Reserved') 
                                ORDER BY r.room_number
                            ");
                            while($room = $available_rooms->fetch_assoc()) {
                                $statusLabel = $room['status'] == 'Reserved' ? ' (Reserved)' : '';
                                echo "<option value='{$room['room_id']}'>{$room['room_number']} - {$room['room_type']}{$statusLabel}</option>";
                            }
                            ?>
                        </select>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Check-in Date *</label>
                            <input type="text" class="form-control flatpickr" name="check_in_date" required>
                        </div>
                        <div class="form-group">
                            <label>Check-out Date *</label>
                            <input type="text" class="form-control flatpickr" name="check_out_date" required>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Plan</label>
                            <select class="form-control" name="plan">
                                <option value="Bed & Breakfast">Bed & Breakfast</option>
                                <option value="Full Board">Full Board</option>
                                <option value="Room Only">Room Only</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Source</label>
                            <select class="form-control" name="source">
                                <option value="Walk-in">Walk-in</option>
                                <option value="Agoda">Agoda</option>
                                <option value="Booking.com">Booking.com</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Number of Pax</label>
                            <input type="number" class="form-control" name="pax" min="1" max="10" value="1">
                        </div>
                        <div class="form-group">
                            <label>Status</label>
                            <select class="form-control" name="status">
                                <option value="Checked In">Checked In</option>
                                <option value="Reserved">Reserved</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Remarks</label>
                        <textarea class="form-control" name="remarks" rows="3" placeholder="Optional remarks..."></textarea>
                    </div>
                    <div class="modal-footer" style="padding: 0; border: none;">
                        <button type="button" class="btn btn-secondary" onclick="closeCheckinModal()">Cancel</button>
                        <button type="submit" class="btn btn-primary">Check In Guest</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal" id="editModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-edit"></i> Edit Guest Details</h3>
                <button class="modal-close" onclick="closeEditModal()">&times;</button>
            </div>
            <div class="modal-body">
                <form id="editForm" method="POST" action="">
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="booking_id" id="edit_booking_id">
                    <input type="hidden" name="old_room_id" id="edit_old_room_id">
                    
                    <div class="form-group">
                        <label>Guest Name *</label>
                        <input type="text" class="form-control" name="guest_name" id="edit_guest_name" required>
                    </div>
                    <div class="form-group">
                        <label>Room *</label>
                        <select class="form-control select2-modal" name="room_id" id="edit_room_id" required style="width: 100%;">
                            <?php
                            $all_rooms = $conn->query("SELECT room_id, room_number, room_type, status FROM rooms ORDER BY room_number");
                            while($room = $all_rooms->fetch_assoc()) {
                                $statusLabel = $room['status'] == 'Vacant' ? '' : ' (' . $room['status'] . ')';
                                echo "<option value='{$room['room_id']}' data-status='{$room['status']}'>{$room['room_number']} - {$room['room_type']}{$statusLabel}</option>";
                            }
                            ?>
                        </select>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Check-in Date</label>
                            <input type="text" class="form-control flatpickr" name="check_in_date" id="edit_check_in" required>
                        </div>
                        <div class="form-group">
                            <label>Check-out Date</label>
                            <input type="text" class="form-control flatpickr" name="check_out_date" id="edit_check_out" required>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Plan</label>
                            <select class="form-control" name="plan" id="edit_plan">
                                <option value="Bed & Breakfast">Bed & Breakfast</option>
                                <option value="Full Board">Full Board</option>
                                <option value="Room Only">Room Only</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Source</label>
                            <select class="form-control" name="source" id="edit_source">
                                <option value="Walk-in">Walk-in</option>
                                <option value="Agoda">Agoda</option>
                                <option value="Booking.com">Booking.com</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Pax</label>
                            <input type="number" class="form-control" name="pax" id="edit_pax" min="1">
                        </div>
                        <div class="form-group">
                            <label>Status</label>
                            <select class="form-control" name="status" id="edit_status">
                                <option value="Checked In">Checked In</option>
                                <option value="Reserved">Reserved</option>
                                <option value="Checked Out">Checked Out</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Remarks</label>
                        <textarea class="form-control" name="remarks" id="edit_remarks" rows="3" placeholder="Optional remarks..."></textarea>
                    </div>
                    <div class="modal-footer" style="padding: 0; border: none;">
                        <button type="button" class="btn btn-secondary" onclick="closeEditModal()">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Details</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <script>
        var storedRoomOptions = [];

        $(document).ready(function() {
            $('.select2-main').select2({ placeholder: "Select option", allowClear: true, width: '100%' });
            
            $('#checkinForm .select2-modal').select2({ dropdownParent: $('#checkinModal'), placeholder: "Select Room", width: '100%' });
            
            var $editSelect = $('#editForm .select2-modal');
            $editSelect.select2({ dropdownParent: $('#editModal'), placeholder: "Select Room", width: '100%' });

            $editSelect.find('option').each(function() {
                storedRoomOptions.push({
                    val: $(this).val(),
                    text: $(this).text(),
                    status: $(this).data('status')
                });
            });

            flatpickr('.flatpickr', { dateFormat: "Y-m-d" });
        });

        function exportData() {
            const params = new URLSearchParams(window.location.search);
            const currentQuery = params.toString();
            window.location.href = 'guest_list.php?export=excel&' + currentQuery;
        }
        
        function clearFilters() {
             window.location.href = window.location.pathname;
        }

        <?php if(isset($_SESSION['swal_success'])): ?>
            Swal.fire({ icon: 'success', title: 'Success', text: '<?php echo $_SESSION['swal_success']; ?>', timer: 2000, showConfirmButton: false });
            <?php unset($_SESSION['swal_success']); ?>
        <?php endif; ?>
        <?php if(isset($_SESSION['swal_error'])): ?>
            Swal.fire({ icon: 'error', title: 'Error', text: '<?php echo $_SESSION['swal_error']; ?>' });
            <?php unset($_SESSION['swal_error']); ?>
        <?php endif; ?>

        function openCheckinModal() { document.getElementById('checkinModal').classList.add('active'); }
        
        function closeCheckinModal() { 
            document.getElementById('checkinModal').classList.remove('active'); 
            document.getElementById('checkinForm').reset();
            $('#checkinForm .select2-modal').val(null).trigger('change');
        }

        function checkoutGuest(bookingId, roomId) {
            Swal.fire({
                title: 'Check Out Guest?', text: "Room will become vacant.", icon: 'warning',
                showCancelButton: true, confirmButtonColor: '#10b981', cancelButtonColor: '#ef4444', confirmButtonText: 'Yes, check out!'
            }).then((result) => {
                if (result.isConfirmed) {
                    const form = document.createElement('form'); form.method = 'POST'; form.action = '';
                    const inputs = [{name: 'action', value: 'checkout'}, {name: 'booking_id', value: bookingId}, {name: 'room_id', value: roomId}];
                    inputs.forEach(d => { const i = document.createElement('input'); i.type = 'hidden'; i.name = d.name; i.value = d.value; form.appendChild(i); });
                    document.body.appendChild(form); form.submit();
                }
            })
        }

        function viewGuest(data) {
            let statusBadge = '';
            if(data.status === 'Checked In') statusBadge = '<span class="status-badge status-checked-in">Checked In</span>';
            else if(data.status === 'Reserved') statusBadge = '<span class="status-badge status-reserved">Reserved</span>';
            else statusBadge = '<span class="status-badge status-checked-out">' + data.status + '</span>';

            Swal.fire({
                title: `<div style="text-align:left; font-size:20px; font-weight:700;"><i class="fas fa-user-circle" style="color:#9333ea; margin-right:8px;"></i> ${data.guest_name}</div>`,
                html: `
                    <div class="swal-table-container">
                        <table class="swal-table">
                            <tbody>
                                <tr><th>Booking ID</th><td>#${String(data.booking_id).padStart(4, '0')}</td></tr>
                                <tr><th>Room</th><td><strong>${data.room_number}</strong> (${data.room_type})</td></tr>
                                <tr><th>Plan & Source</th><td>${data.plan} <span style="color:#cbd5e1">|</span> ${data.source}</td></tr>
                                <tr><th>Guests (Pax)</th><td>${data.pax} person(s)</td></tr>
                                <tr><th>Check-in Date</th><td>${data.check_in_date}</td></tr>
                                <tr><th>Check-out Date</th><td>${data.check_out_date}</td></tr>
                                <tr><th>Status</th><td>${statusBadge}</td></tr>
                                <tr><th>Booked On</th><td>${data.created_at || 'N/A'}</td></tr>
                                <tr><th>Remarks</th><td>${data.remarks || '<span style="color:#94a3b8; font-style:italic;">No remarks</span>'}</td></tr>
                            </tbody>
                        </table>
                    </div>
                `,
                width: '500px',
                showCloseButton: true,
                focusConfirm: false,
                confirmButtonText: 'Close',
                confirmButtonColor: '#9333ea'
            });
        }

        function openEditModal(data) {
            document.getElementById('editModal').classList.add('active');
            document.getElementById('edit_booking_id').value = data.booking_id;
            document.getElementById('edit_guest_name').value = data.guest_name;
            document.getElementById('edit_old_room_id').value = data.room_id;
            document.getElementById('edit_check_in').value = data.check_in_date;
            document.getElementById('edit_check_out').value = data.check_out_date;
            document.getElementById('edit_plan').value = data.plan;
            document.getElementById('edit_source').value = data.source;
            document.getElementById('edit_pax').value = data.pax;
            document.getElementById('edit_status').value = data.status;
            document.getElementById('edit_remarks').value = data.remarks;
            
            var $editSelect = $('#edit_room_id');
            $editSelect.empty();
            
            storedRoomOptions.forEach(function(opt) {
                if (opt.status === 'Vacant' || opt.status === 'Reserved' || opt.val == data.room_id) {
                    var optionHTML = '<option value="' + opt.val + '" data-status="' + opt.status + '">' + opt.text + '</option>';
                    $editSelect.append(optionHTML);
                }
            });

            $editSelect.val(data.room_id).trigger('change');
        }

        function closeEditModal() { document.getElementById('editModal').classList.remove('active'); }

        function validateFormDates(formId) {
            const form = document.getElementById(formId);
            form.addEventListener('submit', function(e) {
                const checkIn = form.querySelector('[name="check_in_date"]').value;
                const checkOut = form.querySelector('[name="check_out_date"]').value;
                
                if(checkIn && checkOut) {
                    const d1 = new Date(checkIn);
                    const d2 = new Date(checkOut);
                    if(d2 <= d1) {
                        e.preventDefault();
                        Swal.fire({
                            icon: 'error',
                            title: 'Invalid Dates',
                            text: 'Check-out date must be AFTER the check-in date.'
                        });
                    }
                }
            });
        }

        validateFormDates('checkinForm');
        validateFormDates('editForm');

        window.onclick = function(event) {
            if (event.target == document.getElementById('checkinModal')) closeCheckinModal();
            if (event.target == document.getElementById('editModal')) closeEditModal();
        }
    </script>
</body>
</html>