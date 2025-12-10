<?php
session_start();
include 'db_connect.php';

// Check if user is logged in
if (!isset($_SESSION['logged_in'])) {
    header("Location: login.php");
    exit;
}

// --- BACKEND HANDLERS ---

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    
    // 1. ADD ITEM
    if (isset($_POST['action']) && $_POST['action'] == 'add') {
        $item_name = $_POST['item_name'];
        $category = $_POST['category'];
        $stock_count = intval($_POST['stock_count']);
        $reorder_point = intval($_POST['reorder_point']);
        $description = $_POST['description'];
        
        $sql = "INSERT INTO inventory (item_name, category, stock_count, reorder_point, description, last_updated) 
                VALUES (?, ?, ?, ?, ?, CURDATE())";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ssiis", $item_name, $category, $stock_count, $reorder_point, $description);
        
        if ($stmt->execute()) {
            $_SESSION['swal_success'] = "Item added successfully!";
        } else {
            $_SESSION['swal_error'] = "Error adding item: " . $conn->error;
        }
        header("Location: inventory.php");
        exit;
    }

    // 2. UPDATE ITEM (Edit Details)
    if (isset($_POST['action']) && $_POST['action'] == 'update') {
        $item_id = intval($_POST['item_id']);
        $item_name = $_POST['item_name'];
        $category = $_POST['category'];
        $reorder_point = intval($_POST['reorder_point']);
        $description = $_POST['description'];
        
        $sql = "UPDATE inventory SET item_name=?, category=?, reorder_point=?, description=?, last_updated=CURDATE() WHERE item_id=?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ssisi", $item_name, $category, $reorder_point, $description, $item_id);
        
        if ($stmt->execute()) {
            $_SESSION['swal_success'] = "Item details updated!";
        } else {
            $_SESSION['swal_error'] = "Error updating item: " . $conn->error;
        }
        header("Location: inventory.php");
        exit;
    }

    // 3. RESTOCK (Update Quantity)
    if (isset($_POST['action']) && $_POST['action'] == 'restock') {
        $item_id = intval($_POST['item_id']);
        $quantity_to_add = intval($_POST['quantity']);
        
        $sql = "UPDATE inventory SET stock_count = stock_count + ?, last_updated = CURDATE() WHERE item_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ii", $quantity_to_add, $item_id);
        
        if ($stmt->execute()) {
            $_SESSION['swal_success'] = "Stock updated successfully!";
        } else {
            $_SESSION['swal_error'] = "Error updating stock: " . $conn->error;
        }
        header("Location: inventory.php");
        exit;
    }

    // 4. DELETE ITEM
    if (isset($_POST['action']) && $_POST['action'] == 'delete') {
        $item_id = intval($_POST['item_id']);
        
        $check = $conn->query("SELECT * FROM damage_reports WHERE item_id = $item_id");
        if ($check->num_rows > 0) {
            $_SESSION['swal_error'] = "Cannot delete: Item is referenced in damage reports.";
        } else {
            $stmt = $conn->prepare("DELETE FROM inventory WHERE item_id = ?");
            $stmt->bind_param("i", $item_id);
            
            if ($stmt->execute()) {
                $_SESSION['swal_success'] = "Item deleted successfully.";
            } else {
                $_SESSION['swal_error'] = "Error deleting item: " . $conn->error;
            }
        }
        header("Location: inventory.php");
        exit;
    }
}

// Handle search & filters
$search = isset($_GET['search']) ? mysqli_real_escape_string($conn, $_GET['search']) : '';
$category_filter = isset($_GET['category']) ? mysqli_real_escape_string($conn, $_GET['category']) : '';
$stock_status = isset($_GET['stock_status']) ? $_GET['stock_status'] : '';

// Pagination Variables
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$limit = 5; // Items per page
$offset = ($page - 1) * $limit;

// Base WHERE clause
$where_clause = "WHERE 1=1";
$params = [];
$types = "";

// Apply Filters
if (!empty($search)) {
    $where_clause .= " AND (item_name LIKE ? OR description LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $types .= "ss";
}

if (!empty($category_filter)) {
    $where_clause .= " AND category = ?";
    $params[] = $category_filter;
    $types .= "s";
}

if (!empty($stock_status)) {
    if ($stock_status == 'low') {
        $where_clause .= " AND stock_count <= reorder_point AND stock_count > 0";
    } elseif ($stock_status == 'critical') {
        $where_clause .= " AND stock_count = 0";
    } elseif ($stock_status == 'ok') {
        $where_clause .= " AND stock_count > reorder_point";
    }
}

// --- EXPORT HANDLERS (Excel Only) ---
if (isset($_GET['export']) && $_GET['export'] == 'excel') {
    $sql_export = "SELECT * FROM inventory $where_clause ORDER BY category, item_name";
    
    $stmt_export = $conn->prepare($sql_export);
    if (!empty($params)) {
        $stmt_export->bind_param($types, ...$params);
    }
    $stmt_export->execute();
    $result_export = $stmt_export->get_result();

    header("Content-Type: application/vnd.ms-excel");
    header("Content-Disposition: attachment; filename=HotelSupreme_Inventory_".date('Y-m-d').".xls");
    
    echo '<table border="1">';
    echo '<tr><td colspan="7" style="font-size: 14pt; font-weight: bold; text-align: center; height: 30px;">Hotel Supreme - Inventory List</td></tr>';
    echo '<tr><td colspan="7" style="text-align: center; font-style: italic;">Generated on ' . date('F j, Y g:i A') . '</td></tr>';
    echo '<tr><td colspan="7"></td></tr>';
    
    echo '<tr style="background-color: #f0f0f0;"><th>Item Name</th><th>Category</th><th>Stock Count</th><th>Reorder Point</th><th>Status</th><th>Last Updated</th><th>Description</th></tr>';
    
    while($row = $result_export->fetch_assoc()) {
        $status_text = 'In Stock';
        if ($row['stock_count'] <= 0) $status_text = 'Out of Stock';
        elseif ($row['stock_count'] <= $row['reorder_point']) $status_text = 'Low Stock';
        
        echo "<tr>
                <td>{$row['item_name']}</td>
                <td>{$row['category']}</td>
                <td>{$row['stock_count']}</td>
                <td>{$row['reorder_point']}</td>
                <td>{$status_text}</td>
                <td>{$row['last_updated']}</td>
                <td>{$row['description']}</td>
              </tr>";
    }
    echo '</table>';
    exit;
}

// 1. Get Total Count for Pagination
$count_sql = "SELECT COUNT(*) as total FROM inventory $where_clause";
$count_stmt = $conn->prepare($count_sql);
if (!empty($params)) {
    $count_stmt->bind_param($types, ...$params);
}
$count_stmt->execute();
$total_records = $count_stmt->get_result()->fetch_assoc()['total'];
$total_pages = ceil($total_records / $limit);

// 2. Get Data for Current Page
$sql = "SELECT * FROM inventory $where_clause ORDER BY category, item_name LIMIT ? OFFSET ?";
$params[] = $limit;
$params[] = $offset;
$types .= "ii";

$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventory Management - Hotel Supreme</title>
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
        .btn { padding: 12px 24px; border-radius: 10px; font-weight: 600; font-size: 14px; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; gap: 8px; transition: all 0.3s; border: 2px solid transparent; }
        .btn-primary { background: var(--primary); color: white; }
        .btn-primary:hover { background: var(--primary-dark); transform: translateY(-2px); }
        .btn-secondary { background: white; color: var(--dark); border-color: var(--gray); }
        .btn-secondary:hover { border-color: var(--primary); background: var(--light); }
        .btn-success { background: var(--success); color: white; }
        .btn-success:hover { background: #0da271; transform: translateY(-2px); }
        .btn-danger { background: var(--danger); color: white; }
        .btn-danger:hover { background: #c23b3b; transform: translateY(-2px); }

        /* Stats Grid */
        .inventory-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: white; border-radius: 12px; padding: 20px; box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1); display: flex; align-items: center; gap: 15px; }
        .stat-icon { width: 50px; height: 50px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 24px; flex-shrink: 0; }
        .stat-content { flex: 1; }
        .stat-value { font-size: 28px; font-weight: 700; line-height: 1; margin-bottom: 0; }
        .stat-label { font-size: 14px; color: var(--secondary); margin-top: 4px; }
        
        /* Filters */
        .filters-section { background: white; border-radius: 16px; padding: 24px; margin-bottom: 30px; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1); }
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
        .table-container { background: white; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1); }
        .table-responsive { overflow-x: auto; }
        .inventory-table { width: 100%; border-collapse: collapse; min-width: 1000px; }
        .inventory-table th { padding: 16px 20px; text-align: left; font-weight: 600; color: var(--dark); border-bottom: 2px solid var(--gray); background: var(--light); }
        .inventory-table tbody tr { border-bottom: 1px solid var(--gray); transition: background-color 0.3s; }
        .inventory-table tbody tr:hover { background: rgba(147, 51, 234, 0.05); }
        .inventory-table td { padding: 16px 20px; vertical-align: middle; }
        
        /* Status Badges */
        .stock-indicator { display: inline-flex; align-items: center; gap: 8px; padding: 6px 12px; border-radius: 20px; font-size: 12px; font-weight: 600; }
        .stock-ok { background: rgba(16, 185, 129, 0.1); color: var(--success); }
        .stock-low { background: rgba(245, 158, 11, 0.1); color: var(--warning); }
        .stock-critical { background: rgba(239, 68, 68, 0.1); color: var(--danger); }

        .action-btn { width: 36px; height: 36px; border-radius: 8px; display: inline-flex; align-items: center; justify-content: center; background: var(--light); color: var(--secondary); cursor: pointer; transition: all 0.3s; border: none; margin-right: 5px; }
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
        .modal-content { background: white; padding: 30px; border-radius: 16px; width: 100%; max-width: 500px; position: relative; }
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; margin-bottom: 8px; font-weight: 500; }
        .form-control { width: 100%; padding: 12px; border: 2px solid var(--gray); border-radius: 8px; font-size: 14px; }
        
        .empty-state { text-align: center; padding: 60px 20px; }
        .empty-state i { font-size: 64px; color: var(--gray); margin-bottom: 20px; }

        /* Responsive Design */
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
            .inventory-stats { grid-template-columns: repeat(2, 1fr); }
            .filters-grid { grid-template-columns: 1fr; }
            .modal-content { width: 90%; margin: 20px; padding: 20px; }
        }

        @media (max-width: 480px) {
            .inventory-stats { grid-template-columns: 1fr; }
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
                    <button class="menu-toggle" onclick="toggleSidebar()">
                        <i class="fas fa-bars"></i>
                    </button>
                    <h1><i class="fas fa-boxes"></i> Inventory Management</h1>
                </div>
                <div class="action-buttons">
                    <button class="btn btn-primary" onclick="openAddModal()">
                        <i class="fas fa-plus"></i> Add New Item
                    </button>
                    <button class="btn btn-secondary" onclick="exportData()">
                        <i class="fas fa-file-excel"></i> Export
                    </button>
                </div>
            </div>
            
            <?php
            $stats = $conn->query("SELECT 
                COUNT(*) as total_items,
                SUM(CASE WHEN stock_count <= reorder_point THEN 1 ELSE 0 END) as low_stock,
                SUM(CASE WHEN stock_count = 0 THEN 1 ELSE 0 END) as out_of_stock,
                SUM(stock_count) as total_stock
            FROM inventory")->fetch_assoc();
            ?>
            
            <div class="inventory-stats">
                <div class="stat-card">
                    <div class="stat-icon" style="background: rgba(147, 51, 234, 0.1); color: var(--primary);">
                        <i class="fas fa-box"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-value"><?php echo $stats['total_items']; ?></div>
                        <div class="stat-label">Total Items</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background: rgba(245, 158, 11, 0.1); color: var(--warning);">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-value"><?php echo $stats['low_stock']; ?></div>
                        <div class="stat-label">Low Stock</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background: rgba(239, 68, 68, 0.1); color: var(--danger);">
                        <i class="fas fa-times-circle"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-value"><?php echo $stats['out_of_stock']; ?></div>
                        <div class="stat-label">Out of Stock</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background: rgba(16, 185, 129, 0.1); color: var(--success);">
                        <i class="fas fa-cubes"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-value"><?php echo $stats['total_stock']; ?></div>
                        <div class="stat-label">Total Count</div>
                    </div>
                </div>
            </div>

            <div class="filters-section">
                <form method="GET" action="" id="filterForm">
                    <div class="filters-grid">
                        <div class="filter-group">
                            <label><i class="fas fa-search"></i> Search Items</label>
                            <input type="text" name="search" class="filter-input" placeholder="Search by item name..." value="<?php echo htmlspecialchars($search); ?>">
                        </div>
                        <div class="filter-group">
                            <label><i class="fas fa-filter"></i> Category</label>
                            <select name="category" class="filter-input select2-main">
                                <option value="">All Categories</option>
                                <?php
                                $categories = $conn->query("SELECT DISTINCT category FROM inventory WHERE category IS NOT NULL ORDER BY category");
                                while($cat = $categories->fetch_assoc()) {
                                    $selected = $cat['category'] == $category_filter ? 'selected' : '';
                                    echo "<option value='{$cat['category']}' $selected>{$cat['category']}</option>";
                                }
                                ?>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label><i class="fas fa-filter"></i> Stock Status</label>
                            <select name="stock_status" class="filter-input select2-main">
                                <option value="" <?php echo $stock_status == '' ? 'selected' : ''; ?>>All Status</option>
                                <option value="ok" <?php echo $stock_status == 'ok' ? 'selected' : ''; ?>>In Stock</option>
                                <option value="low" <?php echo $stock_status == 'low' ? 'selected' : ''; ?>>Low Stock</option>
                                <option value="critical" <?php echo $stock_status == 'critical' ? 'selected' : ''; ?>>Out of Stock</option>
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
                    <table class="inventory-table">
                        <thead>
                            <tr>
                                <th>Item Name</th>
                                <th>Category</th>
                                <th>Stock Count</th>
                                <th>Reorder Point</th>
                                <th>Last Updated</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            if ($result->num_rows > 0) {
                                while($row = $result->fetch_assoc()) {
                                    $stock_class = 'stock-ok';
                                    $status_text = 'In Stock';
                                    
                                    if ($row['stock_count'] <= 0) {
                                        $stock_class = 'stock-critical';
                                        $status_text = 'Out of Stock';
                                    } elseif ($row['stock_count'] <= $row['reorder_point']) {
                                        $stock_class = 'stock-low';
                                        $status_text = 'Low Stock';
                                    }
                                    
                                    $itemData = htmlspecialchars(json_encode($row), ENT_QUOTES, 'UTF-8');
                                    ?>
                                    <tr>
                                        <td>
                                            <div class="item-name"><?php echo htmlspecialchars($row['item_name']); ?></div>
                                            <?php if (!empty($row['description'])): ?>
                                                <div style="font-size: 12px; color: var(--secondary); margin-top: 4px;">
                                                    <?php echo htmlspecialchars($row['description']); ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td><span class="item-category"><?php echo htmlspecialchars($row['category']); ?></span></td>
                                        <td>
                                            <div style="font-weight: 700; font-size: 16px;"><?php echo $row['stock_count']; ?></div>
                                        </td>
                                        <td><?php echo $row['reorder_point']; ?></td>
                                        <td><?php echo $row['last_updated'] ? date('M j, Y', strtotime($row['last_updated'])) : '-'; ?></td>
                                        <td>
                                            <span class="stock-indicator <?php echo $stock_class; ?>">
                                                <i class="fas fa-circle" style="font-size: 8px;"></i>
                                                <?php echo $status_text; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="item-actions">
                                                <button class="action-btn" title="Edit" onclick="openEditModal(<?php echo $itemData; ?>)">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button class="action-btn" title="Restock" onclick="openRestockModal(<?php echo $itemData; ?>)">
                                                    <i class="fas fa-arrow-up"></i>
                                                </button>
                                                <button class="action-btn delete" onclick="deleteItem(<?php echo $row['item_id']; ?>)" title="Delete">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php
                                }
                            } else {
                                echo '<tr><td colspan="7"><div class="empty-state"><i class="fas fa-box-open"></i><h3>No items found</h3></div></td></tr>';
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

    <div class="modal" id="addModal">
        <div class="modal-content">
            <h2 style="margin-bottom: 20px;"><i class="fas fa-plus-circle"></i> Add New Item</h2>
            <form method="POST">
                <input type="hidden" name="action" value="add">
                <div class="form-group">
                    <label>Item Name</label>
                    <input type="text" name="item_name" class="form-control" required>
                </div>
                <div class="form-group">
                    <label>Category</label>
                    <input type="text" name="category" class="form-control" required list="categoryList">
                    <datalist id="categoryList">
                        <option value="Linens">
                        <option value="Toiletries">
                        <option value="Glassware">
                        <option value="Appliances">
                        <option value="Furniture">
                    </datalist>
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                    <div class="form-group">
                        <label>Initial Stock</label>
                        <input type="number" name="stock_count" class="form-control" required min="0">
                    </div>
                    <div class="form-group">
                        <label>Reorder Point</label>
                        <input type="number" name="reorder_point" class="form-control" required min="0">
                    </div>
                </div>
                <div class="form-group">
                    <label>Description (Optional)</label>
                    <textarea name="description" class="form-control" rows="2"></textarea>
                </div>
                <div style="text-align: right; margin-top: 20px;">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('addModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Item</button>
                </div>
            </form>
        </div>
    </div>

    <div class="modal" id="editModal">
        <div class="modal-content">
            <h2 style="margin-bottom: 20px;"><i class="fas fa-edit"></i> Edit Item Details</h2>
            <form method="POST">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="item_id" id="edit_item_id">
                <div class="form-group">
                    <label>Item Name</label>
                    <input type="text" name="item_name" id="edit_item_name" class="form-control" required>
                </div>
                <div class="form-group">
                    <label>Category</label>
                    <input type="text" name="category" id="edit_category" class="form-control" required list="categoryList">
                </div>
                <div class="form-group">
                    <label>Reorder Point</label>
                    <input type="number" name="reorder_point" id="edit_reorder_point" class="form-control" required min="0">
                </div>
                <div class="form-group">
                    <label>Description</label>
                    <textarea name="description" id="edit_description" class="form-control" rows="2"></textarea>
                </div>
                <div style="text-align: right; margin-top: 20px;">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('editModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <div class="modal" id="restockModal">
        <div class="modal-content">
            <h2 style="margin-bottom: 20px;"><i class="fas fa-box-open"></i> Restock Item</h2>
            <p id="restock_item_label" style="margin-bottom: 20px; font-weight: 500; color: var(--primary);"></p>
            <form method="POST">
                <input type="hidden" name="action" value="restock">
                <input type="hidden" name="item_id" id="restock_item_id">
                <div class="form-group">
                    <label>Quantity to Add</label>
                    <input type="number" name="quantity" class="form-control" required min="1" placeholder="e.g. 50">
                </div>
                <div style="text-align: right; margin-top: 20px;">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('restockModal')">Cancel</button>
                    <button type="submit" class="btn btn-success">Update Stock</button>
                </div>
            </form>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <script>
        $(document).ready(function() {
            $('.select2-main').select2({ placeholder: "Select category", allowClear: true, width: '100%' });
        });

        // Export Function (Excel Only)
        function exportData() {
            const params = new URLSearchParams(window.location.search);
            const currentQuery = params.toString();
            // Directly export to Excel without asking
            window.location.href = 'inventory.php?export=excel&' + currentQuery;
        }
        
        // Clear Filters Function
        function clearFilters() {
             window.location.href = window.location.pathname;
        }

        // SweetAlert Notifications
        <?php if(isset($_SESSION['swal_success'])): ?>
            Swal.fire({ icon: 'success', title: 'Success', text: '<?php echo $_SESSION['swal_success']; ?>', timer: 2000, showConfirmButton: false });
            <?php unset($_SESSION['swal_success']); ?>
        <?php endif; ?>
        <?php if(isset($_SESSION['swal_error'])): ?>
            Swal.fire({ icon: 'error', title: 'Error', text: '<?php echo $_SESSION['swal_error']; ?>' });
            <?php unset($_SESSION['swal_error']); ?>
        <?php endif; ?>

        function openAddModal() { document.getElementById('addModal').classList.add('active'); }
        
        function openEditModal(data) {
            document.getElementById('editModal').classList.add('active');
            document.getElementById('edit_item_id').value = data.item_id;
            document.getElementById('edit_item_name').value = data.item_name;
            document.getElementById('edit_category').value = data.category;
            document.getElementById('edit_reorder_point').value = data.reorder_point;
            document.getElementById('edit_description').value = data.description;
        }

        function openRestockModal(data) {
            document.getElementById('restockModal').classList.add('active');
            document.getElementById('restock_item_id').value = data.item_id;
            document.getElementById('restock_item_label').innerText = "Adding stock for: " + data.item_name;
        }

        function closeModal(id) { document.getElementById(id).classList.remove('active'); }

        function deleteItem(id) {
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
                    const form = document.createElement('form'); form.method = 'POST'; form.action = '';
                    const actionInput = document.createElement('input'); actionInput.type = 'hidden'; actionInput.name = 'action'; actionInput.value = 'delete';
                    const idInput = document.createElement('input'); idInput.type = 'hidden'; idInput.name = 'item_id'; idInput.value = id;
                    form.appendChild(actionInput); form.appendChild(idInput);
                    document.body.appendChild(form); form.submit();
                }
            })
        }

        window.onclick = function(e) {
            if(e.target.classList.contains('modal')) e.target.classList.remove('active');
        }
    </script>
</body>
</html>