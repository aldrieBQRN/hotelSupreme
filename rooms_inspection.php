<?php
session_start();
include 'db_connect.php';

// Check if user is logged in
if (!isset($_SESSION['logged_in'])) {
    header("Location: login.php");
    exit;
}

// Get selected room ID from URL if available
$selected_room_id = isset($_GET['room_id']) ? intval($_GET['room_id']) : 0;

// Handle form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $room_id = mysqli_real_escape_string($conn, $_POST['room_id']);
    $inspector_id = $_SESSION['user_id'];
    $notes = mysqli_real_escape_string($conn, $_POST['notes']);
    
    // Start transaction
    $conn->begin_transaction();
    
    try {
        // Create the inspection record
        $sql_inspection = "INSERT INTO room_inspections (room_id, inspector_id, inspection_datetime, is_occupied, notes) 
                           VALUES (?, ?, NOW(), 1, ?)";
        $stmt = $conn->prepare($sql_inspection);
        $stmt->bind_param("iis", $room_id, $inspector_id, $notes);
        $stmt->execute();
        
        $last_inspection_id = $conn->insert_id;
        
        // Process checklist items
        if (isset($_POST['item_status'])) {
            foreach ($_POST['item_status'] as $item_id => $status) {
                if ($status != 'Good') {
                    $issue_type = $status; // This will catch 'Replacement Needed' automatically
                    
                    $sql_report = "INSERT INTO damage_reports (inspection_id, item_id, room_id, issue_type, quantity, status) 
                                   VALUES (?, ?, ?, ?, 1, 'Pending')";
                    $stmt2 = $conn->prepare($sql_report);
                    $stmt2->bind_param("iiis", $last_inspection_id, $item_id, $room_id, $issue_type);
                    $stmt2->execute();
                }
            }
        }
        
        // Update room status if damages were found
        if (isset($_POST['room_status']) && $_POST['room_status'] == 'Maintenance') {
            $sql_update = "UPDATE rooms SET status = 'Maintenance' WHERE room_id = ?";
            $stmt3 = $conn->prepare($sql_update);
            $stmt3->bind_param("i", $room_id);
            $stmt3->execute();
        }
        
        $conn->commit();
        $_SESSION['swal_success'] = "Inspection completed successfully! Issues have been reported.";
        header("Location: rooms_inspection.php");
        exit;
        
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['swal_error'] = "Error: " . $e->getMessage();
        header("Location: rooms_inspection.php?room_id=" . $room_id);
        exit;
    }
}

// Get rooms for dropdown
$rooms_result = $conn->query("
    SELECT r.room_id, r.room_number, r.room_type, r.status 
    FROM rooms r 
    WHERE r.status IN ('Occupied', 'Vacant', 'Maintenance')
    ORDER BY r.room_number
");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Room Inspection - Hotel Supreme</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <style>
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

        body {
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            background: var(--light);
            color: var(--dark);
            min-height: 100vh;
        }

        .main-content {
            margin-left: var(--sidebar-width);
            min-height: 100vh;
            transition: margin-left 0.3s ease;
        }

        .container { max-width: 1400px; margin: 0 auto; padding: 30px; }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            flex-wrap: wrap;
            gap: 20px;
        }

        .header-title { display: flex; align-items: center; gap: 15px; }
        .page-header h1 { font-size: 32px; font-weight: 700; color: var(--dark); display: flex; align-items: center; gap: 12px; margin: 0; }
        .page-header h1 i { color: var(--primary); }

        .back-btn {
            padding: 12px 24px;
            border-radius: 10px;
            font-weight: 600;
            font-size: 14px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s;
            border: 2px solid var(--gray);
            background: white;
            color: var(--dark);
            text-decoration: none;
        }
        .back-btn:hover { border-color: var(--primary); background: var(--light); }

        /* Inspection Form */
        .inspection-form { background: white; border-radius: 16px; padding: 30px; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1); }
        .form-section { margin-bottom: 30px; padding-bottom: 30px; border-bottom: 1px solid var(--gray); }
        .form-section:last-child { border-bottom: none; margin-bottom: 0; padding-bottom: 0; }
        .form-section h2 { font-size: 20px; font-weight: 600; color: var(--dark); margin-bottom: 20px; display: flex; align-items: center; gap: 10px; }

        .form-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-bottom: 20px; }
        .form-group { flex: 1; }
        .form-group label { display: block; font-size: 14px; font-weight: 500; color: var(--dark); margin-bottom: 8px; }
        .form-group label .required { color: var(--danger); }
        .form-control { width: 100%; padding: 12px 16px; border: 2px solid var(--gray); border-radius: 10px; font-size: 14px; transition: all 0.3s; }
        .form-control:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(147, 51, 234, 0.1); }
        .form-control[readonly] { background: var(--light); color: var(--secondary); }

        .room-info-card { background: var(--light); border-radius: 12px; padding: 20px; margin-top: 10px; }
        .room-info-row { display: flex; justify-content: space-between; margin-bottom: 8px; font-size: 14px; }
        .info-label { color: var(--secondary); }
        .info-value { font-weight: 500; color: var(--dark); }

        .status-badge { padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 600; display: inline-block; }
        .status-occupied { background: rgba(16, 185, 129, 0.1); color: var(--success); }
        .status-vacant { background: rgba(100, 116, 139, 0.1); color: var(--secondary); }
        .status-maintenance { background: rgba(245, 158, 11, 0.1); color: var(--warning); }

        /* Checklist Table Styles */
        .checklist-container { background: var(--light); border-radius: 12px; padding: 20px; }
        /* Scrollable categories for mobile */
        .checklist-categories { 
            display: flex; 
            gap: 10px; 
            margin-bottom: 20px; 
            overflow-x: auto; 
            padding-bottom: 10px; 
            border-bottom: 1px solid var(--gray);
            -webkit-overflow-scrolling: touch; /* Smooth scroll on iOS */
        }
        .category-tab { 
            padding: 10px 20px; 
            background: transparent; 
            border-bottom: 3px solid transparent; 
            font-size: 14px; 
            font-weight: 600; 
            color: var(--secondary); 
            cursor: pointer; 
            transition: all 0.3s; 
            white-space: nowrap; 
        }
        .category-tab:hover { color: var(--primary); }
        .category-tab.active { color: var(--primary); border-bottom-color: var(--primary); }

        /* Responsive Table Wrapper */
        .table-responsive { overflow-x: auto; -webkit-overflow-scrolling: touch; }
        
        .checklist-table { width: 100%; border-collapse: collapse; background: white; border-radius: 12px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.05); min-width: 600px; /* Ensure min width for scrolling */ }
        .checklist-table th { text-align: left; padding: 16px 24px; background: #f8fafc; color: var(--secondary); font-weight: 600; font-size: 13px; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 2px solid var(--gray); }
        .checklist-table td { padding: 16px 24px; border-bottom: 1px solid var(--gray); vertical-align: middle; }
        .checklist-table tr:last-child td { border-bottom: none; }
        .item-name-cell { font-weight: 600; color: var(--dark); font-size: 15px; }

        /* Status Radio Group */
        .status-radio-group { display: flex; gap: 15px; flex-wrap: wrap; }
        .radio-option { position: relative; cursor: pointer; }
        .radio-option input { display: none; }
        
        .radio-content { display: flex; align-items: center; gap: 8px; padding: 8px 16px; border-radius: 8px; border: 2px solid var(--gray); color: var(--secondary); font-size: 13px; font-weight: 500; transition: all 0.2s; white-space: nowrap; }
        .radio-content:hover { border-color: #cbd5e1; }
        
        /* Selected States */
        .radio-option input:checked + .radio-content { border-color: currentColor; background-color: rgba(0,0,0,0.02); }

        .radio-option.good { color: var(--success); }
        .radio-option.good input:checked + .radio-content { background-color: rgba(16, 185, 129, 0.1); border-color: var(--success); }

        .radio-option.missing { color: var(--warning); }
        .radio-option.missing input:checked + .radio-content { background-color: rgba(245, 158, 11, 0.1); border-color: var(--warning); }

        .radio-option.damaged { color: var(--danger); }
        .radio-option.damaged input:checked + .radio-content { background-color: rgba(239, 68, 68, 0.1); border-color: var(--danger); }
        
        /* Replacement Style */
        .radio-option.replacement { color: var(--primary); }
        .radio-option.replacement input:checked + .radio-content { background-color: rgba(147, 51, 234, 0.1); border-color: var(--primary); }

        /* Action Buttons */
        .action-buttons { display: flex; gap: 15px; justify-content: flex-end; margin-top: 30px; }
        .btn { padding: 12px 24px; border-radius: 10px; font-weight: 600; font-size: 14px; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; gap: 8px; transition: all 0.3s; border: 2px solid transparent; }
        .btn-primary { background: var(--primary); color: white; }
        .btn-primary:hover { background: var(--primary-dark); transform: translateY(-2px); }
        .btn-secondary { background: white; color: var(--dark); border-color: var(--gray); }
        .btn-secondary:hover { border-color: var(--primary); background: var(--light); }

        /* Summary Section */
        .summary-section { background: var(--light); border-radius: 12px; padding: 20px; margin-top: 20px; }
        .summary-row { display: flex; justify-content: space-between; align-items: center; padding: 10px 0; border-bottom: 1px solid var(--gray); }
        .summary-row:last-child { border-bottom: none; }
        .summary-label { color: var(--secondary); }
        .summary-value { font-weight: 600; color: var(--dark); }
        .summary-value.danger { color: var(--danger); }
        .summary-value.warning { color: var(--warning); }
        .summary-value.primary { color: var(--primary); }

        /* Responsive Design */
        @media (max-width: 1024px) { 
            .main-content { margin-left: 0; } 
        }

        @media (max-width: 768px) {
            .container { padding: 20px; }
            
            .page-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }
            
            .header-title { width: 100%; justify-content: flex-start; }
            .header-title h1 { font-size: 24px; }
            
            .back-btn {
                width: 100%;
                justify-content: center;
                margin-top: 10px;
            }
            
            .form-row { grid-template-columns: 1fr; }
            
            .inspection-form { padding: 20px; }
            
            .action-buttons {
                flex-direction: column;
                gap: 10px;
            }
            
            .action-buttons .btn {
                width: 100%;
            }
            
            /* Better Status Radio Layout on Mobile */
            .status-radio-group { gap: 8px; }
            .radio-content { padding: 8px 12px; font-size: 12px; }
        }
        
        @media (max-width: 480px) {
            .status-radio-group {
                flex-direction: column;
                align-items: stretch;
            }
            
            .radio-content { justify-content: center; }
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
                    <h1><i class="fas fa-clipboard-check"></i> Room Inspection</h1>
                </div>
                <a href="rooms.php" class="back-btn">
                    <i class="fas fa-arrow-left"></i> Back to Rooms
                </a>
            </div>
            
            <form method="POST" action="" class="inspection-form" id="inspectionForm">
                <div class="form-section">
                    <h2><i class="fas fa-info-circle"></i> Basic Information</h2>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Room Number <span class="required">*</span></label>
                            <select class="form-control" name="room_id" id="roomSelect" required>
                                <option value="">Select Room</option>
                                <?php while($room = $rooms_result->fetch_assoc()): ?>
                                    <option value="<?php echo $room['room_id']; ?>" 
                                            data-status="<?php echo $room['status']; ?>"
                                            data-type="<?php echo $room['room_type']; ?>"
                                            <?php echo ($room['room_id'] == $selected_room_id) ? 'selected' : ''; ?>>
                                        <?php echo $room['room_number']; ?> - <?php echo $room['room_type']; ?> 
                                        (<?php echo $room['status']; ?>)
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label>Inspector</label>
                            <input type="text" class="form-control" 
                                   value="<?php echo htmlspecialchars($_SESSION['username']); ?>" 
                                   readonly>
                            <input type="hidden" name="inspector_id" value="<?php echo $_SESSION['user_id']; ?>">
                        </div>
                    </div>
                    
                    <div id="roomInfo" class="room-info-card" style="display: none;">
                        <div class="room-info-row">
                            <span class="info-label">Room Type:</span>
                            <span class="info-value" id="roomType"></span>
                        </div>
                        <div class="room-info-row">
                            <span class="info-label">Current Status:</span>
                            <span class="status-badge" id="roomStatus"></span>
                        </div>
                        <div class="room-info-row">
                            <span class="info-label">Last Inspection:</span>
                            <span class="info-value" id="lastInspection">Not available</span>
                        </div>
                    </div>
                    
                    <div class="form-group" style="margin-top: 20px;">
                        <label>General Notes</label>
                        <textarea class="form-control" name="notes" rows="3" 
                                  placeholder="Enter any general observations, comments, or special notes..."></textarea>
                    </div>
                </div>
                
                <div class="form-section">
                    <h2><i class="fas fa-clipboard-list"></i> Room Condition Checklist</h2>
                    
                    <div class="checklist-container">
                        <div class="checklist-categories" id="categoryTabs">
                            </div>
                        
                        <div id="checklistContent">
                            <?php
                            // Get all inventory items
                            $items_result = $conn->query("
                                SELECT item_id, item_name, category 
                                FROM inventory 
                                ORDER BY category, item_name
                            ");
                            
                            $items_by_category = [];
                            while($item = $items_result->fetch_assoc()) {
                                $category = $item['category'] ?: 'Uncategorized';
                                $items_by_category[$category][] = $item;
                            }
                            
                            foreach ($items_by_category as $category => $items) {
                                $category_id = strtolower(str_replace(' ', '-', $category));
                                ?>
                                <div class="category-items" id="category-<?php echo $category_id; ?>" style="display: none;" data-category-name="<?php echo htmlspecialchars($category); ?>">
                                    <div class="table-responsive">
                                        <table class="checklist-table">
                                            <thead>
                                                <tr>
                                                    <th style="width: 40%;">Item Name</th>
                                                    <th>Status Condition</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($items as $item): ?>
                                                    <tr>
                                                        <td class="item-name-cell"><?php echo htmlspecialchars($item['item_name']); ?></td>
                                                        <td>
                                                            <div class="status-radio-group">
                                                                <label class="radio-option good">
                                                                    <input type="radio" name="item_status[<?php echo $item['item_id']; ?>]" value="Good" checked>
                                                                    <div class="radio-content">
                                                                        <i class="fas fa-check-circle"></i> Good
                                                                    </div>
                                                                </label>
                                                                
                                                                <label class="radio-option missing">
                                                                    <input type="radio" name="item_status[<?php echo $item['item_id']; ?>]" value="Missing">
                                                                    <div class="radio-content">
                                                                        <i class="fas fa-search"></i> Missing
                                                                    </div>
                                                                </label>
                                                                
                                                                <label class="radio-option damaged">
                                                                    <input type="radio" name="item_status[<?php echo $item['item_id']; ?>]" value="Damaged">
                                                                    <div class="radio-content">
                                                                        <i class="fas fa-exclamation-triangle"></i> Damaged
                                                                    </div>
                                                                </label>
                                                                
                                                                <label class="radio-option replacement">
                                                                    <input type="radio" name="item_status[<?php echo $item['item_id']; ?>]" value="Replacement Needed">
                                                                    <div class="radio-content">
                                                                        <i class="fas fa-sync"></i> Replace
                                                                    </div>
                                                                </label>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                                <?php
                            }
                            ?>
                        </div>
                    </div>
                </div>
                
                <div class="form-section">
                    <h2><i class="fas fa-cogs"></i> Room Status</h2>
                    <div class="form-group">
                        <label>After Inspection, Room Should Be:</label>
                        <select class="form-control" name="room_status" id="roomStatusSelect">
                            <option value="Vacant">Ready for Next Guest (Vacant)</option>
                            <option value="Occupied">Keep as Occupied</option>
                            <option value="Maintenance">Send to Maintenance</option>
                        </select>
                        <small class="info-label" style="display: block; margin-top: 8px;">
                            <i class="fas fa-info-circle"></i> Select "Send to Maintenance" if major issues were found
                        </small>
                    </div>
                </div>
                
                <div class="summary-section" id="summarySection" style="display: none;">
                    <div class="summary-row">
                        <span class="summary-label">Items Checked:</span>
                        <span class="summary-value" id="totalItems">0</span>
                    </div>
                    <div class="summary-row">
                        <span class="summary-label">Good Condition:</span>
                        <span class="summary-value" id="goodItems">0</span>
                    </div>
                    <div class="summary-row">
                        <span class="summary-label">Missing Items:</span>
                        <span class="summary-value warning" id="missingItems">0</span>
                    </div>
                    <div class="summary-row">
                        <span class="summary-label">Damaged Items:</span>
                        <span class="summary-value danger" id="damagedItems">0</span>
                    </div>
                    <div class="summary-row">
                        <span class="summary-label">Replacement Needed:</span>
                        <span class="summary-value primary" id="replacementItems">0</span>
                    </div>
                </div>
                
                <div class="action-buttons">
                    <button type="button" class="btn btn-secondary" onclick="resetForm()">
                        <i class="fas fa-redo"></i> Reset Form
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-paper-plane"></i> Submit Inspection
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        // Check for Session Messages (SweetAlert2)
        <?php if(isset($_SESSION['swal_success'])): ?>
            Swal.fire({
                icon: 'success',
                title: 'Success',
                text: '<?php echo $_SESSION['swal_success']; ?>',
                timer: 2000,
                showConfirmButton: false
            });
            <?php unset($_SESSION['swal_success']); ?>
        <?php endif; ?>

        <?php if(isset($_SESSION['swal_error'])): ?>
            Swal.fire({
                icon: 'error',
                title: 'Error',
                text: '<?php echo $_SESSION['swal_error']; ?>',
            });
            <?php unset($_SESSION['swal_error']); ?>
        <?php endif; ?>

        // DOM elements
        const roomSelect = document.getElementById('roomSelect');
        const roomInfo = document.getElementById('roomInfo');
        const roomType = document.getElementById('roomType');
        const roomStatus = document.getElementById('roomStatus');
        const categoryTabs = document.getElementById('categoryTabs');
        const summarySection = document.getElementById('summarySection');
        const form = document.getElementById('inspectionForm');
        
        // Initialize categories
        const categoryDivs = document.querySelectorAll('.category-items');
        let firstCategory = null;
        
        categoryDivs.forEach((div, index) => {
            const categoryId = div.id.replace('category-', '');
            const categoryName = div.dataset.categoryName;
            
            const tab = document.createElement('div');
            tab.className = `category-tab ${index === 0 ? 'active' : ''}`;
            tab.textContent = categoryName;
            tab.onclick = () => switchCategory(categoryId);
            tab.dataset.target = categoryId;
            categoryTabs.appendChild(tab);
            
            if (index === 0) {
                firstCategory = categoryId;
                div.style.display = 'block';
            }
        });
        
        // Switch category function
        function switchCategory(categoryId) {
            categoryDivs.forEach(div => div.style.display = 'none');
            document.querySelectorAll('.category-tab').forEach(tab => tab.classList.remove('active'));
            document.getElementById(`category-${categoryId}`).style.display = 'block';
            const activeTab = Array.from(document.querySelectorAll('.category-tab')).find(t => t.dataset.target === categoryId);
            if(activeTab) activeTab.classList.add('active');
        }
        
        // Room selection change
        roomSelect.addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            
            if (selectedOption.value) {
                roomInfo.style.display = 'block';
                roomType.textContent = selectedOption.dataset.type || 'N/A';
                
                const status = selectedOption.dataset.status || 'Unknown';
                roomStatus.textContent = status;
                roomStatus.className = `status-badge status-${status.toLowerCase()}`;
                
                loadRoomHistory(selectedOption.value);
                summarySection.style.display = 'block';
                updateSummary();
            } else {
                roomInfo.style.display = 'none';
                summarySection.style.display = 'none';
            }
        });
        
        // Load room history (simulated)
        function loadRoomHistory(roomId) {
            const inspections = ['2 hours ago', 'Yesterday', '3 days ago', 'Last Week'];
            const lastInspection = inspections[Math.floor(Math.random() * inspections.length)];
            document.getElementById('lastInspection').textContent = lastInspection;
        }
        
        // Update summary
        function updateSummary() {
            const items = document.querySelectorAll('input[type="radio"]:checked');
            let total = items.length;
            let good = 0;
            let missing = 0;
            let damaged = 0;
            let replacement = 0;
            
            items.forEach(radio => {
                if (radio.value === 'Good') good++;
                if (radio.value === 'Missing') missing++;
                if (radio.value === 'Damaged') damaged++;
                if (radio.value === 'Replacement Needed') replacement++;
            });
            
            document.getElementById('totalItems').textContent = total;
            document.getElementById('goodItems').textContent = good;
            document.getElementById('missingItems').textContent = missing;
            document.getElementById('damagedItems').textContent = damaged;
            document.getElementById('replacementItems').textContent = replacement;
            
            // Update room status suggestion
            const roomStatusSelect = document.getElementById('roomStatusSelect');
            if (damaged > 3 || missing > 2 || replacement > 3) {
                roomStatusSelect.value = 'Maintenance';
            } else if (damaged > 0 || missing > 0 || replacement > 0) {
                roomStatusSelect.value = 'Vacant';
            } else {
                roomStatusSelect.value = 'Occupied';
            }
        }
        
        // Add event listeners to all radio buttons
        document.addEventListener('change', function(e) {
            if (e.target.type === 'radio' && e.target.name.startsWith('item_status')) {
                updateSummary();
            }
        });
        
        // Form functions
        function resetForm() {
            Swal.fire({
                title: 'Reset Inspection Form?',
                text: "All entered data will be lost.",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#ef4444',
                cancelButtonColor: '#64748b',
                confirmButtonText: 'Yes, reset it!'
            }).then((result) => {
                if (result.isConfirmed) {
                    form.reset();
                    roomInfo.style.display = 'none';
                    summarySection.style.display = 'none';
                    
                    document.querySelectorAll('input[type="radio"]').forEach(radio => {
                        if (radio.value === 'Good') radio.checked = true;
                    });
                    
                    if (firstCategory) switchCategory(firstCategory);
                    updateSummary();
                    
                    Swal.fire({ icon: 'success', title: 'Form Reset', text: 'The form has been cleared.', timer: 1500, showConfirmButton: false });
                }
            });
        }
        
        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            if (firstCategory) switchCategory(firstCategory);
            if (roomSelect.value) roomSelect.dispatchEvent(new Event('change'));
        });
    </script>
</body>
</html>