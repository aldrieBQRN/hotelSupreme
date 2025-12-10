<?php
session_start();
include 'db_connect.php';

// Check if user is logged in
if (!isset($_SESSION['logged_in'])) {
    header("Location: login.php");
    exit;
}

// --- PARSE FILTERS ---

// 1. Date Range Handling
$default_from = date('Y-m-d', strtotime('-7 days'));
$default_to = date('Y-m-d');

// Check if we have a range from the URL, otherwise use defaults
$date_range_str = isset($_GET['date_range']) ? $_GET['date_range'] : "$default_from to $default_to";

// Split the range string into from/to dates
$dates = explode(" to ", $date_range_str);
$date_from = isset($dates[0]) ? $dates[0] : $default_from;
$date_to = isset($dates[1]) ? $dates[1] : $date_from;

// 2. Other Filters
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$issue_type = isset($_GET['issue_type']) ? $_GET['issue_type'] : '';

// --- PAGINATION VARIABLES ---
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;

// This is used by the print iframe to fetch all records
$is_print_view = isset($_GET['view']) && $_GET['view'] == 'all';
$limit = $is_print_view ? 1000000 : 5; // If print view, show huge number, else show 5
$offset = ($page - 1) * $limit;

// --- BACKEND HANDLERS ---

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    
    // 1. UPDATE REPORT
    if (isset($_POST['action']) && $_POST['action'] == 'update') {
        $report_id = intval($_POST['report_id']);
        $issue_type_post = mysqli_real_escape_string($conn, $_POST['issue_type']);
        $quantity = intval($_POST['quantity']);
        $status = mysqli_real_escape_string($conn, $_POST['status']);
        
        $stmt = $conn->prepare("UPDATE damage_reports SET issue_type = ?, quantity = ?, status = ? WHERE report_id = ?");
        $stmt->bind_param("sisi", $issue_type_post, $quantity, $status, $report_id);
        
        if ($stmt->execute()) {
            $_SESSION['swal_success'] = "Report updated successfully!";
        } else {
            $_SESSION['swal_error'] = "Error updating report: " . $conn->error;
        }
        header("Location: reports.php?date_range=" . urlencode($date_range_str));
        exit;
    }

    // 2. RESOLVE REPORT
    if (isset($_POST['action']) && $_POST['action'] == 'resolve') {
        $report_id = intval($_POST['report_id']);
        
        $check_sql = "SELECT item_id, quantity FROM damage_reports WHERE report_id = $report_id";
        $check_result = $conn->query($check_sql);
        
        if ($check_result->num_rows > 0) {
            $report_data = $check_result->fetch_assoc();
            $item_id = $report_data['item_id'];
            $qty_to_deduct = $report_data['quantity'];
            
            $stock_query = "SELECT stock_count, item_name FROM inventory WHERE item_id = $item_id";
            $stock_result = $conn->query($stock_query);
            
            if ($stock_result->num_rows > 0) {
                $stock_data = $stock_result->fetch_assoc();
                $current_stock = $stock_data['stock_count'];
                
                if ($current_stock >= $qty_to_deduct) {
                    $update_inventory = "UPDATE inventory 
                                         SET stock_count = stock_count - $qty_to_deduct 
                                         WHERE item_id = $item_id";
                    
                    if ($conn->query($update_inventory)) {
                        $conn->query("UPDATE damage_reports SET status = 'Resolved' WHERE report_id = $report_id");
                        $_SESSION['swal_success'] = "Issue resolved! Inventory stock has been deducted.";
                    } else {
                        $_SESSION['swal_error'] = "Error updating inventory: " . $conn->error;
                    }
                } else {
                    $_SESSION['swal_error'] = "Cannot resolve: Insufficient stock for " . $stock_data['item_name'] . ".";
                }
            } else {
                 $_SESSION['swal_error'] = "Item not found in inventory.";
            }
        }
        header("Location: reports.php?date_range=" . urlencode($date_range_str));
        exit;
    }

    // 3. DELETE REPORT
    if (isset($_POST['action']) && $_POST['action'] == 'delete') {
        $report_id = intval($_POST['report_id']);
        if ($conn->query("DELETE FROM damage_reports WHERE report_id = $report_id")) {
            $_SESSION['swal_success'] = "Report deleted successfully.";
        } else {
            $_SESSION['swal_error'] = "Error deleting report: " . $conn->error;
        }
        header("Location: reports.php?date_range=" . urlencode($date_range_str));
        exit;
    }
}

// --- DATA FETCHING FOR CHARTS (Not Paginated) ---

$trend_sql = "SELECT report_date, 
                     COUNT(*) as total, 
                     SUM(CASE WHEN status = 'Resolved' THEN 1 ELSE 0 END) as resolved 
              FROM damage_reports 
              WHERE report_date BETWEEN '$date_from' AND '$date_to' 
              GROUP BY report_date 
              ORDER BY report_date";
$trend_result = $conn->query($trend_sql);

$trend_dates = [];
$trend_reported = [];
$trend_resolved = [];

while($row = $trend_result->fetch_assoc()) {
    $trend_dates[] = date('M j', strtotime($row['report_date']));
    $trend_reported[] = $row['total'];
    $trend_resolved[] = $row['resolved'];
}

// --- EXPORT HANDLERS ---

function buildExportQuery($conn, $date_from, $date_to, $status_filter, $issue_type) {
    $sql = "SELECT d.report_id, r.room_number, r.room_type, i.category, i.item_name, d.issue_type, d.quantity, d.report_date, d.status 
            FROM damage_reports d
            JOIN rooms r ON d.room_id = r.room_id
            JOIN inventory i ON d.item_id = i.item_id
            WHERE d.report_date BETWEEN '$date_from' AND '$date_to'";
    if (!empty($issue_type)) $sql .= " AND d.issue_type = '$issue_type'";
    if (!empty($status_filter)) $sql .= " AND d.status = '$status_filter'";
    $sql .= " ORDER BY d.report_date DESC";
    return $conn->query($sql);
}

// EXCEL EXPORT ONLY
if (isset($_GET['export']) && $_GET['export'] == 'excel') {
    header("Content-Type: application/vnd.ms-excel");
    header("Content-Disposition: attachment; filename=HotelSupreme_Reports_".date('Y-m-d').".xls");
    $result = buildExportQuery($conn, $date_from, $date_to, $status_filter, $issue_type);
    
    echo '<table border="1">';
    echo '<tr><td colspan="9" style="font-size: 14pt; font-weight: bold; text-align: center; height: 30px;">Hotel Supreme - Reports</td></tr>';
    echo '<tr><td colspan="9" style="text-align: center; font-style: italic;">Generated on ' . date('F j, Y g:i A') . '</td></tr>';
    echo '<tr><td colspan="9"></td></tr>'; // Spacer row
    
    echo '<tr style="background-color: #f0f0f0;"><th>Report ID</th><th>Room</th><th>Type</th><th>Category</th><th>Item</th><th>Issue</th><th>Qty</th><th>Date</th><th>Status</th></tr>';
    
    while($row = $result->fetch_assoc()) {
        echo "<tr>
                <td>#{$row['report_id']}</td>
                <td>{$row['room_number']}</td>
                <td>{$row['room_type']}</td>
                <td>{$row['category']}</td>
                <td>{$row['item_name']}</td>
                <td>{$row['issue_type']}</td>
                <td>{$row['quantity']}</td>
                <td>{$row['report_date']}</td>
                <td>{$row['status']}</td>
              </tr>";
    }
    echo '</table>';
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports & Analytics - Hotel Supreme</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
        
        /* Header */
        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; }
        .header-title { display: flex; align-items: center; gap: 15px; }
        .page-header h1 { font-size: 32px; font-weight: 700; color: var(--dark); display: flex; align-items: center; gap: 12px; margin: 0; }
        .page-header h1 i { color: var(--primary); }

        /* Stats Grid */
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: white; border-radius: 12px; padding: 20px; box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1); display: flex; align-items: center; gap: 15px; }
        .stat-icon { width: 50px; height: 50px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 24px; flex-shrink: 0; }
        .stat-content { flex: 1; }
        .stat-value { font-size: 28px; font-weight: 700; line-height: 1; margin-bottom: 0; }
        .stat-label { font-size: 14px; color: var(--secondary); margin-top: 4px; }

        /* Charts */
        .charts-section { display: grid; grid-template-columns: repeat(auto-fit, minmax(500px, 1fr)); gap: 30px; margin-bottom: 40px; }
        .chart-card { background: white; border-radius: 16px; padding: 24px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); }
        .chart-header { margin-bottom: 15px; }
        .chart-container { position: relative; height: 320px; width: 100%; }
        .chart-no-data { height: 300px; display: flex; flex-direction: column; align-items: center; justify-content: center; color: var(--secondary); background: var(--light); border-radius: 8px; }

        /* Filters */
        .filters-section { background: white; border-radius: 16px; padding: 24px; margin-bottom: 30px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); }
        .filters-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; align-items: end; }
        .filter-group { flex: 1; }
        .filter-group label { display: block; font-size: 14px; font-weight: 500; color: var(--dark); margin-bottom: 8px; }
        .filter-input { width: 100%; padding: 12px 16px; border: 2px solid var(--gray); border-radius: 10px; font-size: 14px; }
        .filter-group .select2-container { width: 100% !important; }
        .select2-container--default .select2-selection--single { height: 48px; border: 2px solid var(--gray); border-radius: 10px; padding: 8px 12px; display: flex; align-items: center; }
        .select2-container--default .select2-selection--single .select2-selection__arrow { height: 46px; right: 12px; }
        
        .filter-buttons { display: flex; gap: 10px; }
        .filter-buttons .btn { width: 100%; height: 46px; }
        
        /* Updated Buttons */
        .btn { padding: 12px 24px; border-radius: 10px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; gap: 8px; border: none; font-size: 14px; transition: 0.3s; text-decoration: none; }
        .btn-apply { height: 48px; width: 100%; background: var(--primary); color: white; border-radius: 10px; font-weight: 600; border: none; cursor: pointer; }
        .btn-primary { background: var(--primary); color: white; }
        .btn-primary:hover { background: var(--primary-dark); transform: translateY(-2px); }
        .btn-secondary { background: white; color: var(--dark); border: 2px solid var(--gray); }
        .btn-secondary:hover { border-color: var(--primary); background: var(--light); }
        .btn-danger { background: var(--danger); color: white; }
        .btn-danger:hover { background: #c23b3b; transform: translateY(-2px); }

        /* Table */
        .table-container { background: white; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); }
        .table-responsive { overflow-x: auto; }
        .reports-table { width: 100%; border-collapse: collapse; min-width: 1200px; }
        .reports-table th { padding: 16px 20px; text-align: left; font-weight: 600; background: var(--light); border-bottom: 2px solid var(--gray); }
        .reports-table td { padding: 16px 20px; border-bottom: 1px solid var(--gray); vertical-align: middle; }
        .status-badge, .issue-badge { padding: 6px 12px; border-radius: 20px; font-size: 12px; font-weight: 600; }
        .issue-damaged { background: rgba(239, 68, 68, 0.1); color: var(--danger); }
        .issue-missing { background: rgba(245, 158, 11, 0.1); color: var(--warning); }
        .issue-replacement-needed { background: rgba(147, 51, 234, 0.1); color: var(--primary); }
        .status-pending { background: rgba(245, 158, 11, 0.1); color: var(--warning); }
        .status-resolved { background: rgba(16, 185, 129, 0.1); color: var(--success); }
        
        .action-btn { width: 36px; height: 36px; border-radius: 8px; display: inline-flex; align-items: center; justify-content: center; background: var(--light); color: var(--secondary); cursor: pointer; transition: 0.3s; border: none; margin-right: 5px; }
        .action-btn:hover { background: var(--primary); color: white; transform: translateY(-2px); }
        .action-btn.delete:hover { background: var(--danger); }

        /* Pagination */
        .pagination { display: flex; justify-content: center; align-items: center; padding: 24px; gap: 8px; flex-wrap: wrap; }
        .page-btn { width: 40px; height: 40px; border-radius: 8px; display: flex; align-items: center; justify-content: center; background: white; border: 1px solid var(--gray); color: var(--dark); text-decoration: none; transition: all 0.3s; }
        .page-btn.active { background: var(--primary); color: white; border-color: var(--primary); }
        .page-btn.active { background: var(--primary); color: white; border-color: var(--primary); }
        .page-btn:hover:not(.active):not(.disabled) { background: var(--light); border-color: var(--primary); }
        .page-btn.disabled { opacity: 0.5; cursor: not-allowed; pointer-events: none; background: #f1f5f9; }

        /* Export */
        .export-section { background: white; border-radius: 16px; padding: 24px; margin-top: 30px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); }
        .export-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; }
        .export-option { background: var(--light); border-radius: 12px; padding: 20px; text-align: center; cursor: pointer; transition: 0.3s; border: 2px solid transparent; text-decoration: none; color: inherit; display: block; }
        .export-option:hover { border-color: var(--primary); transform: translateY(-2px); }
        .export-option i { font-size: 32px; color: var(--primary); margin-bottom: 12px; }

        /* Modal */
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center; }
        .modal.active { display: flex; }
        .modal-content { background: white; padding: 30px; border-radius: 16px; width: 100%; max-width: 500px; }
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; margin-bottom: 8px; font-weight: 500; }
        .form-control { width: 100%; padding: 12px 16px; border: 2px solid var(--gray); border-radius: 10px; font-size: 14px; transition: border-color 0.3s; }
        .form-control:focus { outline: none; border-color: var(--primary); }
        
        /* Custom Table for SweetAlert View */
        .swal-table-container { width: 100%; overflow: hidden; border-radius: 10px; border: 1px solid #e2e8f0; margin-top: 15px; }
        .swal-table { width: 100%; border-collapse: collapse; text-align: left; font-size: 14px; }
        .swal-table th { background: #f8fafc; color: #64748b; padding: 12px 15px; width: 35%; font-weight: 600; border-bottom: 1px solid #e2e8f0; }
        .swal-table td { padding: 12px 15px; color: #1e293b; border-bottom: 1px solid #e2e8f0; }
        .swal-table tr:last-child th, .swal-table tr:last-child td { border-bottom: none; }
        
        .empty-state { text-align: center; padding: 60px 20px; }
        .empty-state i { font-size: 64px; color: var(--gray); margin-bottom: 20px; }

        /* Print Header - Hidden by default */
        .print-header { display: none; margin-bottom: 20px; padding-bottom: 20px; border-bottom: 2px solid #000; }
        .print-header h1 { font-size: 24px; margin: 0; }
        .print-header p { font-size: 14px; margin: 5px 0 0 0; color: #666; }

        /* Print Styles */
        @media print {
            @page { size: landscape; margin: 1cm; }
            body { background: white; color: black; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            
            .sidebar, .page-header, .filters-section, .export-section, .action-btn, .pagination, .swal2-container, .stats-grid, .charts-section { display: none !important; }
            
            .main-content { margin-left: 0; padding: 0; width: 100%; }
            .container { padding: 0; max-width: 100%; }
            
            .print-header { display: block; }
            
            .table-container { box-shadow: none; overflow: visible; }
            .reports-table { width: 100%; min-width: 0 !important; border-collapse: collapse; border: 1px solid #000; font-size: 11px; table-layout: fixed; }
            .reports-table th { background-color: #f3f4f6 !important; border: 1px solid #000; color: black; padding: 6px; }
            .reports-table td { border: 1px solid #000; padding: 6px; color: black; white-space: normal !important; word-wrap: break-word; }
            
            .reports-table th:last-child, 
            .reports-table td:last-child { display: none; }
            
            .status-badge, .issue-badge { border: 1px solid #ccc; padding: 2px 4px; display: inline-block; }
        }

        /* Responsive Design */
        @media (max-width: 1024px) { 
            .main-content { margin-left: 0; } 
        }

        @media (max-width: 768px) {
            .container { padding: 20px; }
            .page-header { flex-direction: column; align-items: flex-start; gap: 15px; }
            .header-title { width: 100%; justify-content: flex-start; }
            .header-title h1 { font-size: 24px; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .filters-grid { grid-template-columns: 1fr; }
            .modal-content { width: 90%; margin: 20px; padding: 20px; }
            .charts-section { grid-template-columns: 1fr; min-width: 0; }
            .chart-card { min-width: 0; overflow: hidden; }
        }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>
    
    <div class="main-content">
        <div class="container">
            <div class="print-header">
                <h1>Hotel Supreme - Reports</h1>
                <p>Generated on <?php echo date('F j, Y g:i A'); ?></p>
            </div>

            <div class="page-header">
                <div class="header-title">
                    <button class="menu-toggle" onclick="toggleSidebar()"><i class="fas fa-bars"></i></button>
                    <h1><i class="fas fa-chart-bar"></i> Reports & Analytics</h1>
                </div>
            </div>
            
            <div class="stats-grid">
                <?php
                $stats = $conn->query("
                    SELECT 
                        COUNT(*) as total_reports,
                        SUM(CASE WHEN issue_type = 'Damaged' THEN 1 ELSE 0 END) as damaged,
                        SUM(CASE WHEN issue_type = 'Missing' THEN 1 ELSE 0 END) as missing,
                        SUM(CASE WHEN issue_type = 'Replacement Needed' THEN 1 ELSE 0 END) as replacement,
                        SUM(CASE WHEN status = 'Resolved' THEN 1 ELSE 0 END) as resolved
                    FROM damage_reports
                    WHERE report_date BETWEEN '$date_from' AND '$date_to'
                ")->fetch_assoc();
                $has_data = $stats['total_reports'] > 0;
                ?>
                
                <div class="stat-card">
                    <div class="stat-icon" style="background: rgba(147, 51, 234, 0.1); color: var(--primary);">
                        <i class="fas fa-file-alt"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-value"><?php echo $stats['total_reports'] ?: 0; ?></div>
                        <div class="stat-label">Total Reports</div>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon" style="background: rgba(239, 68, 68, 0.1); color: var(--danger);">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-value"><?php echo $stats['damaged'] ?: 0; ?></div>
                        <div class="stat-label">Damaged</div>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon" style="background: rgba(245, 158, 11, 0.1); color: var(--warning);">
                        <i class="fas fa-search"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-value"><?php echo $stats['missing'] ?: 0; ?></div>
                        <div class="stat-label">Missing</div>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon" style="background: rgba(16, 185, 129, 0.1); color: var(--success);">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-value"><?php echo $stats['resolved'] ?: 0; ?></div>
                        <div class="stat-label">Resolved</div>
                    </div>
                </div>
            </div>
            
            <div class="charts-section">
                <div class="chart-card">
                    <div class="chart-header"><h3>Issues by Type</h3></div>
                    <?php if($has_data): ?>
                    <div class="chart-container"><canvas id="issuesChart"></canvas></div>
                    <?php else: ?>
                    <div class="chart-no-data"><i class="fas fa-chart-pie"></i><p>No data available</p></div>
                    <?php endif; ?>
                </div>

                <div class="chart-card">
                    <div class="chart-header"><h3>Resolution Trend</h3></div>
                    <?php if($has_data): ?>
                    <div class="chart-container"><canvas id="resolutionChart"></canvas></div>
                    <?php else: ?>
                    <div class="chart-no-data"><i class="fas fa-chart-line"></i><p>No data available</p></div>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="filters-section">
                <form method="GET" action="">
                    <div class="filters-grid">
                        <div class="filter-group">
                            <label>Date Range</label>
                            <input type="text" name="date_range" class="filter-input flatpickr-range" value="<?php echo htmlspecialchars($date_range_str); ?>" placeholder="Select dates...">
                        </div>
                        <div class="filter-group">
                            <label>Issue Type</label>
                            <select name="issue_type" class="filter-input select2">
                                <option value="">All Types</option>
                                <option value="Damaged" <?php echo $issue_type == 'Damaged' ? 'selected' : ''; ?>>Damaged</option>
                                <option value="Missing" <?php echo $issue_type == 'Missing' ? 'selected' : ''; ?>>Missing</option>
                                <option value="Replacement Needed" <?php echo $issue_type == 'Replacement Needed' ? 'selected' : ''; ?>>Replacement Needed</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label>Status</label>
                            <select name="status" class="filter-input select2">
                                <option value="">All Status</option>
                                <option value="Pending" <?php echo $status_filter == 'Pending' ? 'selected' : ''; ?>>Pending</option>
                                <option value="Resolved" <?php echo $status_filter == 'Resolved' ? 'selected' : ''; ?>>Resolved</option>
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
                    <table class="reports-table">
                        <thead>
                            <tr>
                                <th>Report ID</th><th>Room</th><th>Item</th><th>Issue</th><th>Qty</th><th>Date</th><th>Status</th><th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            // 1. Get Total Count for Pagination (using same filters)
                            $count_sql = "SELECT COUNT(*) as total FROM damage_reports d WHERE d.report_date BETWEEN ? AND ?";
                            $count_params = [$date_from, $date_to];
                            $count_types = "ss";
                            if (!empty($issue_type)) { $count_sql .= " AND d.issue_type = ?"; $count_params[] = $issue_type; $count_types .= "s"; }
                            if (!empty($status_filter)) { $count_sql .= " AND d.status = ?"; $count_params[] = $status_filter; $count_types .= "s"; }
                            
                            $count_stmt = $conn->prepare($count_sql);
                            $count_stmt->bind_param($count_types, ...$count_params);
                            $count_stmt->execute();
                            $total_records = $count_stmt->get_result()->fetch_assoc()['total'];
                            $total_pages = ceil($total_records / $limit);

                            // 2. Fetch Data with Pagination
                            $sql = "SELECT d.*, r.room_number, r.room_type, i.item_name, i.category, u.username as inspector
                                    FROM damage_reports d
                                    JOIN rooms r ON d.room_id = r.room_id
                                    JOIN inventory i ON d.item_id = i.item_id
                                    LEFT JOIN room_inspections ri ON d.inspection_id = ri.inspection_id
                                    LEFT JOIN users u ON ri.inspector_id = u.user_id
                                    WHERE d.report_date BETWEEN ? AND ?";
                            
                            $params = [$date_from, $date_to];
                            $types = "ss";
                            if (!empty($issue_type)) { $sql .= " AND d.issue_type = ?"; $params[] = $issue_type; $types .= "s"; }
                            if (!empty($status_filter)) { $sql .= " AND d.status = ?"; $params[] = $status_filter; $types .= "s"; }
                            
                            $sql .= " ORDER BY d.report_date DESC LIMIT ? OFFSET ?";
                            $params[] = $limit;
                            $params[] = $offset;
                            $types .= "ii";

                            $stmt = $conn->prepare($sql);
                            $stmt->bind_param($types, ...$params);
                            $stmt->execute();
                            $result = $stmt->get_result();
                            
                            if ($result->num_rows > 0) {
                                while($row = $result->fetch_assoc()) {
                                    $issue_class = "issue-" . strtolower(str_replace(' ', '-', $row['issue_type']));
                                    $status_class = "status-" . strtolower($row['status']);
                                    $reportData = htmlspecialchars(json_encode($row), ENT_QUOTES, 'UTF-8');
                                    ?>
                                    <tr>
                                        <td><span class="report-id">#<?php echo str_pad($row['report_id'], 4, '0', STR_PAD_LEFT); ?></span></td>
                                        <td><span class="room-badge"><?php echo htmlspecialchars($row['room_number']); ?></span></td>
                                        <td><div class="item-name"><?php echo htmlspecialchars($row['item_name']); ?></div><small><?php echo htmlspecialchars($row['category']); ?></small></td>
                                        <td><span class="issue-badge <?php echo $issue_class; ?>"><?php echo $row['issue_type']; ?></span></td>
                                        <td class="quantity-cell"><?php echo $row['quantity']; ?></td>
                                        <td><?php echo date('M j, Y', strtotime($row['report_date'])); ?></td>
                                        <td><span class="status-badge <?php echo $status_class; ?>"><?php echo $row['status']; ?></span></td>
                                        <td>
                                            <div class="report-actions">
                                                <button class="action-btn" title="View" onclick="viewReport(<?php echo $reportData; ?>)"><i class="fas fa-eye"></i></button>
                                                <button class="action-btn" title="Edit" onclick="openEditModal(<?php echo $reportData; ?>)"><i class="fas fa-edit"></i></button>
                                                <button class="action-btn delete" title="Delete" onclick="deleteReport(<?php echo $row['report_id']; ?>)"><i class="fas fa-trash"></i></button>
                                                <?php if($row['status'] == 'Pending'): ?>
                                                <button class="action-btn" title="Resolve" onclick="resolveReport(<?php echo $row['report_id']; ?>)" style="color:var(--success)"><i class="fas fa-check"></i></button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php
                                }
                            } else {
                                echo '<tr><td colspan="8"><div class="empty-state"><i class="fas fa-clipboard-check"></i><h3>No reports found</h3></div></td></tr>';
                            }
                            ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($total_pages > 1 && !$is_print_view): ?>
                <div class="pagination">
                    <?php 
                    $queryParams = $_GET;
                    unset($queryParams['page']); // Remove page param to rebuild it
                    $baseUrl = '?' . http_build_query($queryParams);
                    $baseUrl .= !empty($queryParams) ? '&' : '';
                    ?>
                    
                    <?php 
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
                    $next_class = ($page >= $total_pages) ? 'disabled' : ''; 
                    ?>
                    <a href="<?php echo $baseUrl . 'page=' . ($page + 1); ?>" class="page-btn <?php echo $next_class; ?>">
                        <i class="fas fa-chevron-right"></i>
                    </a>
                </div>
                <?php endif; ?>
            </div>
            
            <div class="export-section">
                <h3 style="margin-bottom: 20px;"><i class="fas fa-download"></i> Export Reports</h3>
                <div class="export-grid">
                    <a href="#" onclick="printFullReport(); return false;" class="export-option">
                        <i class="fas fa-file-pdf"></i>
                        <h4>Export as PDF</h4>
                        <p>Save via Print dialog</p>
                    </a>
                    
                    <a href="?<?php echo http_build_query($_GET); ?>&export=excel" class="export-option">
                        <i class="fas fa-file-excel"></i>
                        <h4>Export as Excel</h4>
                        <p>Spreadsheet format</p>
                    </a>
                    
                    <a href="#" onclick="printFullReport(); return false;" class="export-option">
                        <i class="fas fa-print"></i>
                        <h4>Print Report</h4>
                        <p>Direct to printer</p>
                    </a>
                </div>
            </div>
        </div>
    </div>
    
    <iframe id="printFrame" style="display:none; width:0; height:0;"></iframe>

    <div class="modal" id="editModal">
        <div class="modal-content">
            <h2 style="margin-bottom: 20px;"><i class="fas fa-edit"></i> Edit Report</h2>
            <form method="POST">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="report_id" id="edit_report_id">
                
                <div class="form-group">
                    <label>Issue Type</label>
                    <select name="issue_type" id="edit_issue_type" class="form-control">
                        <option value="Damaged">Damaged</option>
                        <option value="Missing">Missing</option>
                        <option value="Replacement Needed">Replacement Needed</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Quantity</label>
                    <input type="number" name="quantity" id="edit_quantity" class="form-control" min="1" required>
                </div>
                <div class="form-group">
                    <label>Status</label>
                    <select name="status" id="edit_status" class="form-control">
                        <option value="Pending">Pending</option>
                        <option value="Resolved">Resolved</option>
                    </select>
                </div>
                <div style="text-align: right; margin-top: 25px; display: flex; justify-content: flex-end; gap: 10px;">
                    <button type="button" class="btn btn-secondary" onclick="closeEditModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Report</button>
                </div>
            </form>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <script>
        $(document).ready(function() {
            $('.select2').select2({ placeholder: "Select option", allowClear: false, minimumResultsForSearch: 10 });
            
            // Initialize Flatpickr in range mode
            flatpickr('.flatpickr-range', { 
                mode: "range",
                dateFormat: "Y-m-d",
                defaultDate: ["<?php echo $date_from; ?>", "<?php echo $date_to; ?>"]
            });
        });
        
        // JS Function to handle printing without reload
        function printFullReport() {
            // 1. Get current URL parameters
            const params = new URLSearchParams(window.location.search);
            // 2. Add 'view=all' to disable pagination
            params.set('view', 'all');
            const url = 'reports.php?' + params.toString();
            
            // 3. Load into hidden iframe
            const iframe = document.getElementById('printFrame');
            
            // Set up the onload handler before setting src
            iframe.onload = function() {
                // Wait a brief moment for content to fully render inside the frame
                setTimeout(function() {
                    // Trigger print on the iframe's window
                    iframe.contentWindow.focus();
                    iframe.contentWindow.print();
                }, 500);
            };
            
            iframe.src = url;
        }

        // Clear Filters Function
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

        <?php if($has_data): ?>
        // 1. UPDATED Issues Chart (Doughnut)
        const ctx1 = document.getElementById('issuesChart').getContext('2d');
        new Chart(ctx1, {
            type: 'doughnut',
            data: {
                labels: ['Damaged', 'Missing', 'Replacement Needed'],
                datasets: [{
                    data: [
                        <?php echo $stats['damaged']?:0; ?>, 
                        <?php echo $stats['missing']?:0; ?>, 
                        <?php echo $stats['replacement']?:0; ?>
                    ],
                    backgroundColor: ['#ef4444', '#f59e0b', '#9333ea'],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '50%',
                plugins: {
                    legend: { 
                        position: 'bottom',
                        labels: { padding: 20, usePointStyle: true }
                    }
                }
            }
        });

        // 2. Trend Chart (Line)
        const ctx2 = document.getElementById('resolutionChart').getContext('2d');
        new Chart(ctx2, {
            type: 'line',
            data: {
                labels: <?php echo json_encode($trend_dates); ?>,
                datasets: [
                    {
                        label: 'Issues Reported',
                        data: <?php echo json_encode($trend_reported); ?>,
                        borderColor: '#ef4444',
                        backgroundColor: 'rgba(239, 68, 68, 0.1)',
                        tension: 0.4,
                        fill: true
                    },
                    {
                        label: 'Issues Resolved',
                        data: <?php echo json_encode($trend_resolved); ?>,
                        borderColor: '#10b981',
                        backgroundColor: 'rgba(16, 185, 129, 0.1)',
                        tension: 0.4,
                        fill: true
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'top' }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: { stepSize: 1 }
                    }
                }
            }
        });
        <?php endif; ?>

        function viewReport(data) {
            Swal.fire({
                title: `<div style="text-align:left; font-size:20px; font-weight:700;"><i class="fas fa-file-alt" style="color:#9333ea; margin-right:8px;"></i> Report #${String(data.report_id).padStart(4,'0')}</div>`,
                html: `
                    <div class="swal-table-container">
                        <table class="swal-table">
                            <tbody>
                                <tr><th>Room</th><td><strong>${data.room_number}</strong> (${data.room_type})</td></tr>
                                <tr><th>Item</th><td>${data.item_name} <span style="color:#64748b; font-size:12px">(${data.category})</span></td></tr>
                                <tr><th>Issue Type</th><td><span class="issue-badge issue-${data.issue_type.toLowerCase().replace(/ /g, '-')}">${data.issue_type}</span></td></tr>
                                <tr><th>Quantity</th><td>${data.quantity}</td></tr>
                                <tr><th>Status</th><td><span class="status-badge status-${data.status.toLowerCase()}">${data.status}</span></td></tr>
                                <tr><th>Date Reported</th><td>${data.report_date}</td></tr>
                                <tr><th>Inspector</th><td>${data.inspector ? data.inspector : '<span style="color:#ccc">N/A</span>'}</td></tr>
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
            document.getElementById('edit_report_id').value = data.report_id;
            document.getElementById('edit_issue_type').value = data.issue_type;
            document.getElementById('edit_quantity').value = data.quantity;
            document.getElementById('edit_status').value = data.status;
        }
        function closeEditModal() { document.getElementById('editModal').classList.remove('active'); }

        function resolveReport(id) {
            Swal.fire({ title: 'Mark as Resolved?', text: "This will deduct the item quantity from inventory.", icon: 'question', showCancelButton: true, confirmButtonColor: '#10b981', confirmButtonText: 'Yes, Resolve' }).then((result) => {
                if(result.isConfirmed) {
                    const form = document.createElement('form'); form.method = 'POST';
                    form.innerHTML = `<input type="hidden" name="action" value="resolve"><input type="hidden" name="report_id" value="${id}">`;
                    document.body.appendChild(form); form.submit();
                }
            });
        }

        function deleteReport(id) {
            Swal.fire({ title: 'Delete Report?', text: "This cannot be undone.", icon: 'warning', showCancelButton: true, confirmButtonColor: '#ef4444', confirmButtonText: 'Delete' }).then((result) => {
                if(result.isConfirmed) {
                    const form = document.createElement('form'); form.method = 'POST';
                    form.innerHTML = `<input type="hidden" name="action" value="delete"><input type="hidden" name="report_id" value="${id}">`;
                    document.body.appendChild(form); form.submit();
                }
            });
        }
        
        window.onclick = function(e) { if(e.target.classList.contains('modal')) closeEditModal(); }
    </script>
</body>
</html>