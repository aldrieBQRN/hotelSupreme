<?php
session_start();
include 'db_connect.php';

// Check if user is logged in
if (!isset($_SESSION['logged_in'])) {
    header("Location: login.php");
    exit;
}

// Calculate Next Room Number for Display (Add Modal)
$result_max = $conn->query("SELECT MAX(CAST(room_number AS UNSIGNED)) as max_num FROM rooms");
$row_max = $result_max->fetch_assoc();
$next_room_num = $row_max['max_num'] ? $row_max['max_num'] + 1 : 101;

// --- FILTER PARAMETERS ---
$search = isset($_GET['search']) ? mysqli_real_escape_string($conn, $_GET['search']) : '';
$type_filter = isset($_GET['type']) ? mysqli_real_escape_string($conn, $_GET['type']) : '';
$status_filter = isset($_GET['status']) ? mysqli_real_escape_string($conn, $_GET['status']) : '';

// --- PAGINATION VARIABLES ---
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$limit = 5; // Items per page
$offset = ($page - 1) * $limit;

// Build Query Condition
$where_clause = "WHERE 1=1";
if (!empty($search)) {
    $where_clause .= " AND room_number LIKE '%$search%'";
}
if (!empty($type_filter)) {
    $where_clause .= " AND room_type = '$type_filter'";
}
if (!empty($status_filter)) {
    $where_clause .= " AND status = '$status_filter'";
}

// --- EXPORT HANDLERS (Excel Only) ---
if (isset($_GET['export']) && $_GET['export'] == 'excel') {
    $export_sql = "SELECT r.room_number, r.room_type, r.status,
                   (SELECT inspection_datetime FROM room_inspections WHERE room_id = r.room_id ORDER BY inspection_datetime DESC LIMIT 1) as last_check,
                   (SELECT u.username FROM room_inspections ri JOIN users u ON ri.inspector_id = u.user_id WHERE ri.room_id = r.room_id ORDER BY ri.inspection_datetime DESC LIMIT 1) as inspector
                   FROM rooms r 
                   $where_clause
                   ORDER BY CAST(r.room_number AS UNSIGNED)";
    $result = $conn->query($export_sql);

    header("Content-Type: application/vnd.ms-excel");
    header("Content-Disposition: attachment; filename=HotelSupreme_Rooms_".date('Y-m-d').".xls");
    
    echo '<table border="1">';
    echo '<tr><td colspan="5" style="font-size: 14pt; font-weight: bold; text-align: center; height: 30px;">Hotel Supreme - Room List</td></tr>';
    echo '<tr><td colspan="5" style="text-align: center; font-style: italic;">Generated on ' . date('F j, Y g:i A') . '</td></tr>';
    echo '<tr><td colspan="5"></td></tr>';
    
    echo '<tr style="background-color: #f0f0f0;"><th>Room Number</th><th>Room Type</th><th>Status</th><th>Last Inspection</th><th>Inspector</th></tr>';
    while($row = $result->fetch_assoc()) {
        echo "<tr>
                <td>{$row['room_number']}</td>
                <td>{$row['room_type']}</td>
                <td>{$row['status']}</td>
                <td>{$row['last_check']}</td>
                <td>{$row['inspector']}</td>
              </tr>";
    }
    echo '</table>';
    exit;
}

// --- BACKEND HANDLERS ---

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    
    // 1. ADD ROOM
    if (isset($_POST['action']) && $_POST['action'] == 'add') {
        try {
            $room_type = mysqli_real_escape_string($conn, $_POST['room_type']);
            
            // Recalculate ID inside try block to be safe
            $result = $conn->query("SELECT MAX(CAST(room_number AS UNSIGNED)) as max_num FROM rooms");
            $row = $result->fetch_assoc();
            $next_num = $row['max_num'] ? $row['max_num'] + 1 : 101;
            $room_number = (string)$next_num;
            
            // Manual check for duplicates
            $check = $conn->query("SELECT room_id FROM rooms WHERE room_number = '$room_number'");
            if ($check->num_rows > 0) {
                throw new Exception("Room number $room_number already exists. Please try again.");
            }

            $stmt = $conn->prepare("INSERT INTO rooms (room_number, room_type, status) VALUES (?, ?, 'Vacant')");
            $stmt->bind_param("ss", $room_number, $room_type);
            $stmt->execute();

            // Deduct stock if successful
            $conn->query("UPDATE inventory SET stock_count = GREATEST(0, stock_count - 1)");
            $_SESSION['swal_success'] = "Room $room_number added successfully!";

        } catch (mysqli_sql_exception $e) {
            $_SESSION['swal_error'] = "Database Error: " . $e->getMessage();
        } catch (Exception $e) {
            $_SESSION['swal_error'] = $e->getMessage();
        }
        
        header("Location: rooms.php");
        exit;
    }

    // 2. UPDATE ROOM
    if (isset($_POST['action']) && $_POST['action'] == 'update') {
        try {
            $room_id = intval($_POST['room_id']);
            $room_type = mysqli_real_escape_string($conn, $_POST['room_type']);
            $status = mysqli_real_escape_string($conn, $_POST['status']);

            $stmt = $conn->prepare("UPDATE rooms SET room_type = ?, status = ? WHERE room_id = ?");
            $stmt->bind_param("ssi", $room_type, $status, $room_id);
            $stmt->execute();
            
            $_SESSION['swal_success'] = "Room updated successfully!";
            
        } catch (Exception $e) {
             $_SESSION['swal_error'] = "Error updating room: " . $e->getMessage();
        }
        header("Location: rooms.php");
        exit;
    }

    // 3. DELETE ROOM (UPDATED WITH BETTER ERROR HANDLING)
    if (isset($_POST['action']) && $_POST['action'] == 'delete') {
        $room_id = intval($_POST['room_id']);
        
        // Business Logic Check: Is it occupied?
        $check = $conn->query("SELECT * FROM guest_bookings WHERE room_id = $room_id AND status = 'Checked In'");
        
        if ($check->num_rows > 0) {
            $_SESSION['swal_error'] = "Cannot delete: Room is currently occupied by a guest.";
        } else {
            try {
                $stmt = $conn->prepare("DELETE FROM rooms WHERE room_id = ?");
                $stmt->bind_param("i", $room_id);
                $stmt->execute();
                
                $_SESSION['swal_success'] = "Room deleted successfully.";
            } catch (mysqli_sql_exception $e) {
                // Error code 1451 is "Cannot delete or update a parent row: a foreign key constraint fails"
                if ($e->getCode() == 1451) {
                    $_SESSION['swal_error'] = "Cannot delete: This room has linked records (Inspections or Damage Reports). Please clear them first.";
                } else {
                    $_SESSION['swal_error'] = "Database Error: " . $e->getMessage();
                }
            } catch (Exception $e) {
                $_SESSION['swal_error'] = "Error: " . $e->getMessage();
            }
        }
        header("Location: rooms.php");
        exit;
    }
}

// --- GET DATA FOR DISPLAY (With Pagination) ---

$count_sql = "SELECT COUNT(*) as total FROM rooms r $where_clause";
$count_result = $conn->query($count_sql);
$total_records = $count_result->fetch_assoc()['total'];
$total_pages = ceil($total_records / $limit);

$sql = "SELECT r.*, 
        (SELECT inspection_datetime 
         FROM room_inspections 
         WHERE room_id = r.room_id 
         ORDER BY inspection_datetime DESC LIMIT 1) as last_check,
        (SELECT u.username 
         FROM room_inspections ri 
         JOIN users u ON ri.inspector_id = u.user_id 
         WHERE ri.room_id = r.room_id 
         ORDER BY ri.inspection_datetime DESC LIMIT 1) as inspector
    FROM rooms r 
    $where_clause
    ORDER BY CAST(r.room_number AS UNSIGNED)
    LIMIT $limit OFFSET $offset";

$rooms = $conn->query($sql);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Room Management - Hotel Supreme</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
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
        
        .btn { padding: 12px 24px; border-radius: 10px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; border: none; text-decoration: none; transition: 0.3s; font-size: 14px; }
        .btn-primary { background: var(--primary); color: white; }
        .btn-primary:hover { background: var(--primary-dark); transform: translateY(-2px); }
        .btn-secondary { background: white; border: 2px solid var(--gray); color: var(--dark); }
        .btn-secondary:hover { border-color: var(--primary); background: var(--light); }
        .btn-danger { background: var(--danger); color: white; }
        .btn-danger:hover { background: #c23b3b; transform: translateY(-2px); }

        /* Stats Grid */
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: white; border-radius: 12px; padding: 20px; box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1); display: flex; align-items: center; gap: 15px; }
        .stat-icon { width: 50px; height: 50px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 24px; flex-shrink: 0; }
        .stat-content { flex: 1; }
        .stat-value { font-size: 28px; font-weight: 700; line-height: 1; margin-bottom: 0; }
        .stat-label { font-size: 14px; color: var(--secondary); margin-top: 4px; }
        
        /* Filters */
        .filters-section { background: white; border-radius: 16px; padding: 24px; margin-bottom: 30px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); }
        .filters-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; align-items: end; }
        .filter-group { flex: 1; }
        .filter-group label { display: block; font-size: 14px; font-weight: 500; color: var(--dark); margin-bottom: 8px; }
        .filter-input { width: 100%; padding: 12px 16px; border: 2px solid var(--gray); border-radius: 10px; font-size: 14px; }
        .select2-container { width: 100% !important; }
        .select2-container--default .select2-selection--single { height: 46px; border: 2px solid var(--gray); border-radius: 10px; padding: 8px 12px; display: flex; align-items: center; }
        .select2-container--default .select2-selection--single .select2-selection__arrow { height: 44px; right: 12px; }
        
        .filter-buttons { display: flex; gap: 10px; }
        .filter-buttons .btn { width: 100%; height: 46px; }

        /* Table */
        .table-container { background: white; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); }
        .table-responsive { overflow-x: auto; }
        .rooms-table { width: 100%; border-collapse: collapse; min-width: 800px; }
        .rooms-table thead { background: var(--light); }
        .rooms-table th { padding: 16px 20px; text-align: left; font-weight: 600; color: var(--dark); border-bottom: 2px solid var(--gray); white-space: nowrap; }
        .rooms-table td { padding: 16px 20px; vertical-align: middle; border-bottom: 1px solid var(--gray); }
        .rooms-table tr:hover { background: rgba(147, 51, 234, 0.05); }

        .room-number-badge { font-weight: 700; font-size: 16px; color: var(--primary); background: rgba(147, 51, 234, 0.1); padding: 6px 12px; border-radius: 8px; display: inline-block; }
        
        .status-badge { padding: 6px 12px; border-radius: 20px; font-size: 12px; font-weight: 600; text-transform: capitalize; display: inline-block;}
        .status-vacant { background: rgba(100, 116, 139, 0.1); color: var(--secondary); }
        .status-occupied { background: rgba(16, 185, 129, 0.1); color: var(--success); }
        .status-maintenance { background: rgba(239, 68, 68, 0.1); color: var(--danger); }
        .status-reserved { background: rgba(245, 158, 11, 0.1); color: var(--warning); }
        .inspector-badge { display: inline-flex; align-items: center; gap: 6px; font-size: 13px; color: var(--secondary); background: var(--light); padding: 4px 10px; border-radius: 20px; }

        .action-btn { width: 36px; height: 36px; border-radius: 8px; display: inline-flex; align-items: center; justify-content: center; background: var(--light); color: var(--secondary); cursor: pointer; transition: all 0.3s; border: none; margin-right: 5px; text-decoration: none; }
        .action-btn:hover { background: var(--primary); color: white; transform: translateY(-2px); }
        .action-btn.delete:hover { background: var(--danger); }

        /* Pagination */
        .pagination { display: flex; justify-content: center; align-items: center; padding: 24px; gap: 8px; flex-wrap: wrap; }
        .page-btn { width: 40px; height: 40px; border-radius: 8px; display: flex; align-items: center; justify-content: center; background: white; border: 1px solid var(--gray); color: var(--dark); text-decoration: none; transition: all 0.3s; }
        .page-btn.active { background: var(--primary); color: white; border-color: var(--primary); }
        .page-btn:hover:not(.active):not(.disabled) { background: var(--light); border-color: var(--primary); }
        .page-btn.disabled { opacity: 0.5; cursor: not-allowed; pointer-events: none; background: #f1f5f9; }

        /* Modal */
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center; }
        .modal.active { display: flex; }
        .modal-content { background: white; padding: 30px; border-radius: 16px; width: 100%; max-width: 450px; position: relative; }
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; margin-bottom: 8px; font-weight: 500; }
        .form-control { width: 100%; padding: 12px; border: 2px solid var(--gray); border-radius: 8px; font-size: 14px; }
        .form-control[readonly] { background-color: var(--light); color: var(--secondary); cursor: not-allowed; }

        /* Responsive Breakpoints */
        @media (max-width: 1024px) { .main-content { margin-left: 0; } }

        @media (max-width: 768px) {
            .container { padding: 20px; }
            .page-header { flex-direction: column; align-items: flex-start; gap: 15px; }
            .header-title { width: 100%; justify-content: flex-start; }
            .header-title h1 { font-size: 24px; }
            .action-buttons { width: 100%; gap: 10px; margin-top: 10px; }
            .action-buttons .btn { flex: 1; justify-content: center; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .filters-grid { grid-template-columns: 1fr; }
            .modal-content { width: 90%; margin: 20px; padding: 20px; }
        }

        @media (max-width: 480px) {
            .stats-grid { grid-template-columns: 1fr; }
            .room-number-badge { font-size: 14px; padding: 4px 8px; }
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
                    <h1><i class="fas fa-door-open"></i> Room Management</h1>
                </div>
                <div class="action-buttons">
                    <button class="btn btn-primary" onclick="openAddModal()">
                        <i class="fas fa-plus"></i> Add New Room
                    </button>
                    <button class="btn btn-secondary" onclick="exportData()">
                        <i class="fas fa-file-excel"></i> Export
                    </button>
                </div>
            </div>

            <div class="stats-grid">
                <?php
                $stats = $conn->query("SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN status='Vacant' THEN 1 ELSE 0 END) as vacant,
                    SUM(CASE WHEN status='Occupied' THEN 1 ELSE 0 END) as occupied,
                    SUM(CASE WHEN status='Maintenance' THEN 1 ELSE 0 END) as maintenance
                FROM rooms")->fetch_assoc();
                ?>
                
                <div class="stat-card">
                    <div class="stat-icon" style="background: rgba(147, 51, 234, 0.1); color: var(--primary);">
                        <i class="fas fa-door-open"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-value"><?php echo $stats['total']; ?></div>
                        <div class="stat-label">Total Rooms</div>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon" style="background: rgba(100, 116, 139, 0.1); color: var(--secondary);">
                        <i class="fas fa-door-open"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-value"><?php echo $stats['vacant']; ?></div>
                        <div class="stat-label">Vacant</div>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon" style="background: rgba(16, 185, 129, 0.1); color: var(--success);">
                        <i class="fas fa-bed"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-value"><?php echo $stats['occupied']; ?></div>
                        <div class="stat-label">Occupied</div>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon" style="background: rgba(239, 68, 68, 0.1); color: var(--danger);">
                        <i class="fas fa-tools"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-value"><?php echo $stats['maintenance']; ?></div>
                        <div class="stat-label">Maintenance</div>
                    </div>
                </div>
            </div>

            <div class="filters-section">
                <form method="GET" action="" id="filterForm">
                    <div class="filters-grid">
                        <div class="filter-group">
                            <label><i class="fas fa-search"></i> Room Number</label>
                            <input type="text" name="search" class="filter-input" placeholder="Search room #..." value="<?php echo htmlspecialchars($search); ?>">
                        </div>
                        <div class="filter-group">
                            <label><i class="fas fa-filter"></i> Room Type</label>
                            <select name="type" class="filter-input select2-main">
                                <option value="">All Types</option>
                                <option value="Superior family" <?php echo $type_filter == 'Superior family' ? 'selected' : ''; ?>>Superior family</option>
                                <option value="Executive suite" <?php echo $type_filter == 'Executive suite' ? 'selected' : ''; ?>>Executive suite</option>
                                <option value="Junior suite" <?php echo $type_filter == 'Junior suite' ? 'selected' : ''; ?>>Junior suite</option>
                                <option value="Double deluxe" <?php echo $type_filter == 'Double deluxe' ? 'selected' : ''; ?>>Double deluxe</option>
                                <option value="Family deluxe" <?php echo $type_filter == 'Family deluxe' ? 'selected' : ''; ?>>Family deluxe</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label><i class="fas fa-filter"></i> Status</label>
                            <select name="status" class="filter-input select2-main">
                                <option value="">All Statuses</option>
                                <option value="Vacant" <?php echo $status_filter == 'Vacant' ? 'selected' : ''; ?>>Vacant</option>
                                <option value="Occupied" <?php echo $status_filter == 'Occupied' ? 'selected' : ''; ?>>Occupied</option>
                                <option value="Maintenance" <?php echo $status_filter == 'Maintenance' ? 'selected' : ''; ?>>Maintenance</option>
                                <option value="Reserved" <?php echo $status_filter == 'Reserved' ? 'selected' : ''; ?>>Reserved</option>
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
                    <table class="rooms-table">
                        <thead>
                            <tr>
                                <th>Room Number</th>
                                <th>Room Type</th>
                                <th>Status</th>
                                <th>Last Inspection</th>
                                <th>Inspected By</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            if ($rooms->num_rows > 0) {
                                while($row = $rooms->fetch_assoc()) {
                                    $status_class = "status-" . strtolower($row['status']);
                                    $last_check = $row['last_check'] ? date('M j, Y g:i A', strtotime($row['last_check'])) : '-';
                                    $inspector = $row['inspector'] ? htmlspecialchars($row['inspector']) : '-';
                                    $roomData = htmlspecialchars(json_encode($row), ENT_QUOTES, 'UTF-8');
                                    ?>
                                    <tr>
                                        <td>
                                            <span class="room-number-badge"><?php echo htmlspecialchars($row['room_number']); ?></span>
                                        </td>
                                        <td><?php echo htmlspecialchars($row['room_type']); ?></td>
                                        <td>
                                            <span class="status-badge <?php echo $status_class; ?>">
                                                <?php echo htmlspecialchars($row['status']); ?>
                                            </span>
                                        </td>
                                        <td style="color: var(--secondary); font-size: 14px;"><?php echo $last_check; ?></td>
                                        <td>
                                            <?php if ($row['inspector']): ?>
                                                <span class="inspector-badge"><i class="fas fa-user-circle"></i> <?php echo $inspector; ?></span>
                                            <?php else: ?>
                                                <span style="color: var(--secondary);">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <button class="action-btn" onclick="openEditModal(<?php echo $roomData; ?>)" title="Edit">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <a href="rooms_inspection.php?room_id=<?php echo $row['room_id']; ?>" class="action-btn" title="Inspect">
                                                <i class="fas fa-clipboard-check"></i>
                                            </a>
                                            <button class="action-btn delete" onclick="deleteRoom(<?php echo $row['room_id']; ?>)" title="Delete">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </td>
                                    </tr>
                                    <?php
                                }
                            } else {
                                echo "<tr><td colspan='6' style='text-align:center; padding: 40px; color: var(--secondary);'>No rooms found matching filters</td></tr>";
                            }
                            ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($total_pages > 1): ?>
                <div class="pagination">
                    <?php 
                    $queryParams = $_GET;
                    ?>
                    
                    <?php 
                    $queryParams['page'] = $page - 1;
                    $prev_link = '?' . http_build_query($queryParams);
                    $prev_class = ($page <= 1) ? 'disabled' : ''; 
                    ?>
                    <a href="<?php echo $prev_link; ?>" class="page-btn <?php echo $prev_class; ?>">
                        <i class="fas fa-chevron-left"></i>
                    </a>

                    <?php for ($i = 1; $i <= $total_pages; $i++): 
                        $queryParams['page'] = $i;
                        $link = '?' . http_build_query($queryParams);
                        $active_class = ($page == $i) ? 'active' : '';
                    ?>
                        <a href="<?php echo $link; ?>" class="page-btn <?php echo $active_class; ?>"><?php echo $i; ?></a>
                    <?php endfor; ?>

                    <?php 
                    $queryParams['page'] = $page + 1;
                    $next_link = '?' . http_build_query($queryParams);
                    $next_class = ($page >= $total_pages) ? 'disabled' : ''; 
                    ?>
                    <a href="<?php echo $next_link; ?>" class="page-btn <?php echo $next_class; ?>">
                        <i class="fas fa-chevron-right"></i>
                    </a>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="modal" id="addRoomModal">
        <div class="modal-content">
            <h2 style="margin-bottom: 20px; font-size: 20px;"><i class="fas fa-plus-circle"></i> Add New Room</h2>
            <form method="POST">
                <input type="hidden" name="action" value="add">
                
                <div class="form-group">
                    <label>Room Number</label>
                    <input type="text" name="room_number" class="form-control" value="<?php echo $next_room_num; ?>" readonly>
                </div>

                <div class="form-group">
                    <label>Room Type</label>
                    <select name="room_type" class="form-control">
                        <option value="Superior family">Superior family</option>
                        <option value="Executive suite">Executive suite</option>
                        <option value="Junior suite">Junior suite</option>
                        <option value="Double deluxe">Double deluxe</option>
                        <option value="Family deluxe">Family deluxe</option>
                    </select>
                </div>
                <div style="text-align: right; margin-top: 20px; display: flex; gap: 10px; justify-content: flex-end;">
                    <button type="button" class="btn btn-secondary" onclick="closeAddModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Room</button>
                </div>
            </form>
        </div>
    </div>

    <div class="modal" id="editRoomModal">
        <div class="modal-content">
            <h2 style="margin-bottom: 20px; font-size: 20px;"><i class="fas fa-edit"></i> Edit Room</h2>
            <form method="POST">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="room_id" id="edit_room_id">
                
                <div class="form-group">
                    <label>Room Number</label>
                    <input type="text" name="room_number" id="edit_room_number" class="form-control" readonly>
                </div>
                <div class="form-group">
                    <label>Room Type</label>
                    <select name="room_type" id="edit_room_type" class="form-control">
                        <option value="Superior family">Superior family</option>
                        <option value="Executive suite">Executive suite</option>
                        <option value="Junior suite">Junior suite</option>
                        <option value="Double deluxe">Double deluxe</option>
                        <option value="Family deluxe">Family deluxe</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Status</label>
                    <select name="status" id="edit_status" class="form-control">
                        <option value="Vacant">Vacant</option>
                        <option value="Occupied">Occupied</option>
                        <option value="Maintenance">Maintenance</option>
                        <option value="Reserved">Reserved</option>
                    </select>
                </div>
                <div style="text-align: right; margin-top: 20px; display: flex; gap: 10px; justify-content: flex-end;">
                    <button type="button" class="btn btn-secondary" onclick="closeEditModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Room</button>
                </div>
            </form>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        $(document).ready(function() {
            $('.select2-main').select2({ placeholder: "Select option", allowClear: true, width: '100%' });
        });

        function exportData() {
            const params = new URLSearchParams(window.location.search);
            const currentQuery = params.toString();
            window.location.href = 'rooms.php?export=excel&' + currentQuery;
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

        function openAddModal() { document.getElementById('addRoomModal').classList.add('active'); }
        function closeAddModal() { document.getElementById('addRoomModal').classList.remove('active'); }

        function openEditModal(data) {
            document.getElementById('editRoomModal').classList.add('active');
            document.getElementById('edit_room_id').value = data.room_id;
            document.getElementById('edit_room_number').value = data.room_number;
            document.getElementById('edit_room_type').value = data.room_type;
            document.getElementById('edit_status').value = data.status;
        }
        function closeEditModal() { document.getElementById('editRoomModal').classList.remove('active'); }

        function deleteRoom(id) {
            Swal.fire({
                title: 'Are you sure?',
                text: "You won't be able to revert this!",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#ef4444',
                cancelButtonColor: '#64748b',
                confirmButtonText: 'Yes, delete it!'
            }).then((result) => {
                if (result.isConfirmed) {
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.action = '';
                    
                    const actionInput = document.createElement('input');
                    actionInput.type = 'hidden';
                    actionInput.name = 'action';
                    actionInput.value = 'delete';
                    
                    const idInput = document.createElement('input');
                    idInput.type = 'hidden';
                    idInput.name = 'room_id';
                    idInput.value = id;
                    
                    form.appendChild(actionInput);
                    form.appendChild(idInput);
                    document.body.appendChild(form);
                    form.submit();
                }
            })
        }

        window.onclick = function(e) {
            if (e.target == document.getElementById('addRoomModal')) closeAddModal();
            if (e.target == document.getElementById('editRoomModal')) closeEditModal();
        }
    </script>
</body>
</html>