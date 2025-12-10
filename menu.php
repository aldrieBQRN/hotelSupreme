<?php
session_start();
include 'db_connect.php';

// Check if user is logged in
if (!isset($_SESSION['logged_in'])) {
    header("Location: login.php");
    exit;
}

// Get user info
$username = $_SESSION['username'];
$role = $_SESSION['role'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hotel Supreme - Dashboard</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            /* CHANGED: Primary colors set to Purple */
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

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            background: var(--light);
            color: var(--dark);
            min-height: 100vh;
        }

        /* Main Content Layout */
        .main-content {
            margin-left: var(--sidebar-width);
            min-height: 100vh;
            transition: margin-left 0.3s ease;
        }
        
        .container {
            max-width: 1400px; 
            margin: 0 auto; 
            padding: 30px;
        }

        /* Standardized Page Header */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
        }

        .header-title {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .page-header h1 {
            font-size: 32px;
            font-weight: 700;
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: 12px;
            margin: 0;
        }
        
        .page-header h1 i {
            color: var(--primary);
        }

        .header-actions {
            display: flex;
            gap: 16px;
            align-items: center;
        }

        .date-time {
            background: white;
            padding: 12px 20px;
            border-radius: 10px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
            font-size: 14px;
            font-weight: 500;
            color: var(--secondary);
            display: flex;
            align-items: center;
            gap: 8px;
            border: 1px solid var(--gray);
        }

        /* Dashboard Stats */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 24px;
            margin-bottom: 40px;
        }

        .stat-card {
            background: white;
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            transition: transform 0.3s, box-shadow 0.3s;
            cursor: pointer;
            border-top: 4px solid var(--primary);
            position: relative;
            overflow: hidden;
        }

        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 20px rgba(0, 0, 0, 0.15);
        }

        .stat-card.danger { border-color: var(--danger); }
        .stat-card.warning { border-color: var(--warning); }
        .stat-card.success { border-color: var(--success); }
        .stat-card.secondary { border-color: var(--secondary); }

        .stat-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 16px;
            position: relative;
            z-index: 1;
        }

        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            flex-shrink: 0;
        }

        /* Stat Icon Colors */
        .stat-card .stat-icon { background: rgba(147, 51, 234, 0.1); color: var(--primary); }
        .stat-card.danger .stat-icon { background: rgba(239, 68, 68, 0.1); color: var(--danger); }
        .stat-card.warning .stat-icon { background: rgba(245, 158, 11, 0.1); color: var(--warning); }
        .stat-card.success .stat-icon { background: rgba(16, 185, 129, 0.1); color: var(--success); }

        .stat-value {
            font-size: 32px;
            font-weight: 700;
            margin-bottom: 8px;
            line-height: 1.2;
        }

        .stat-label {
            font-size: 14px;
            color: var(--secondary);
            margin-bottom: 4px;
        }

        .stat-change {
            font-size: 12px;
            font-weight: 600;
        }

        .stat-change.positive { color: var(--success); }
        .stat-change.negative { color: var(--danger); }

        /* Quick Actions */
        .quick-actions {
            margin-top: 40px;
        }

        .quick-actions h2 {
            font-size: 24px;
            margin-bottom: 24px;
            color: var(--dark);
            font-weight: 600;
        }

        .actions-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 20px;
        }

        .action-btn {
            background: white;
            border: 2px solid transparent; /* Transparent border for hover effect */
            border-radius: 16px;
            padding: 30px 20px;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 15px;
            text-decoration: none;
            color: var(--dark);
            transition: all 0.3s;
            cursor: pointer;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
        }

        .action-btn:hover {
            border-color: var(--primary);
            transform: translateY(-4px);
            box-shadow: 0 10px 15px -3px rgba(147, 51, 234, 0.1);
        }

        .action-btn i {
            font-size: 36px;
            color: var(--primary);
            margin-bottom: 5px;
        }

        .action-btn span {
            font-weight: 600;
            text-align: center;
            font-size: 15px;
        }

        /* Responsive Design */
        @media (max-width: 1024px) {
            .main-content {
                margin-left: 0;
            }
        }

        @media (max-width: 768px) {
            .container {
                padding: 20px;
            }
            
            /* Header Stack */
            .page-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }
            
            .header-title {
                width: 100%;
                justify-content: flex-start;
            }
            
            .page-header h1 {
                font-size: 24px;
            }

            .header-actions {
                width: 100%;
            }

            .date-time {
                width: 100%;
                justify-content: center;
            }
            
            /* Grid adjustments */
            .stats-grid {
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); /* Allow smaller cards */
            }
            
            .actions-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr; /* Stack vertically on very small screens */
            }

            .actions-grid {
                grid-template-columns: 1fr;
            }
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
                    <h1><i class="fas fa-chart-line"></i> Dashboard Overview</h1>
                </div>
                
                <div class="header-actions">
                    <div class="date-time">
                        <i class="far fa-calendar-alt"></i>
                        <span id="currentDateTime"><?php echo date('F j, Y - H:i'); ?></span>
                    </div>
                </div>
            </div>
            
            <div class="stats-grid">
                <?php
                // Get stats from database
                $stats = [
                    'total_guests' => $conn->query("SELECT COUNT(*) as count FROM guest_bookings WHERE status = 'Checked In'")->fetch_assoc()['count'],
                    'occupied_rooms' => $conn->query("SELECT COUNT(*) as count FROM rooms WHERE status = 'Occupied'")->fetch_assoc()['count'],
                    'pending_damages' => $conn->query("SELECT COUNT(*) as count FROM damage_reports WHERE status = 'Pending' AND issue_type = 'Damaged'")->fetch_assoc()['count'],
                    'low_stock_items' => $conn->query("SELECT COUNT(*) as count FROM inventory WHERE stock_count <= reorder_point")->fetch_assoc()['count']
                ];
                ?>
                
                <div class="stat-card">
                    <div class="stat-header">
                        <div>
                            <div class="stat-label">Total Guests</div>
                            <div class="stat-value"><?php echo $stats['total_guests']; ?></div>
                            <div class="stat-change positive">+2 today</div>
                        </div>
                        <div class="stat-icon">
                            <i class="fas fa-users"></i>
                        </div>
                    </div>
                </div>
                
                <div class="stat-card success">
                    <div class="stat-header">
                        <div>
                            <div class="stat-label">Occupied Rooms</div>
                            <div class="stat-value"><?php echo $stats['occupied_rooms']; ?></div>
                            <div class="stat-change">Active Stays</div>
                        </div>
                        <div class="stat-icon">
                            <i class="fas fa-bed"></i>
                        </div>
                    </div>
                </div>
                
                <div class="stat-card danger">
                    <div class="stat-header">
                        <div>
                            <div class="stat-label">Pending Damages</div>
                            <div class="stat-value"><?php echo $stats['pending_damages']; ?></div>
                            <div class="stat-change negative">Needs attention</div>
                        </div>
                        <div class="stat-icon">
                            <i class="fas fa-exclamation-triangle"></i>
                        </div>
                    </div>
                </div>
                
                <div class="stat-card warning">
                    <div class="stat-header">
                        <div>
                            <div class="stat-label">Low Stock Items</div>
                            <div class="stat-value"><?php echo $stats['low_stock_items']; ?></div>
                            <div class="stat-change">Need reorder</div>
                        </div>
                        <div class="stat-icon">
                            <i class="fas fa-box-open"></i>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="quick-actions">
                <h2>Quick Actions</h2>
                <div class="actions-grid">
                    <a href="guest_list.php" class="action-btn">
                        <i class="fas fa-user-plus"></i>
                        <span>New Guest Check-in</span>
                    </a>
                    
                    <a href="rooms_inspection.php" class="action-btn">
                        <i class="fas fa-clipboard-list"></i>
                        <span>Room Inspection</span>
                    </a>
                    
                    <a href="inventory.php" class="action-btn">
                        <i class="fas fa-warehouse"></i>
                        <span>Inventory Management</span>
                    </a>
                    
                    <a href="reports.php" class="action-btn">
                        <i class="fas fa-chart-pie"></i>
                        <span>View Reports</span>
                    </a>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Update date and time every minute
        function updateDateTime() {
            const now = new Date();
            const options = { 
                weekday: 'long', 
                year: 'numeric', 
                month: 'long', 
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit'
            };
            document.getElementById('currentDateTime').textContent = now.toLocaleDateString('en-US', options);
        }
        
        // Update immediately and then every minute
        updateDateTime();
        setInterval(updateDateTime, 10000);
    </script>
</body>
</html>