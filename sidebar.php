<?php if (!isset($_SESSION['logged_in'])) return; ?>

<style>
    /* Keeps your existing sidebar styles */
    .sidebar {
        width: var(--sidebar-width, 280px);
        background: white;
        border-right: 1px solid var(--gray, #e2e8f0);
        display: flex;
        flex-direction: column;
        position: fixed;
        height: 100vh;
        transition: transform 0.3s ease;
        z-index: 1000;
        top: 0;
        left: 0;
    }
    
    .sidebar-header {
        padding: 24px;
        background: linear-gradient(to right, var(--primary, #2563eb), var(--primary-dark, #1d4ed8));
        color: white;
    }
    
    .sidebar-header h2 { font-size: 20px; font-weight: 600; display: flex; align-items: center; gap: 10px; }
    .sidebar-header p { font-size: 14px; opacity: 0.9; margin-top: 4px; }
    
    .user-info { display: flex; align-items: center; gap: 12px; margin-top: 20px; padding-top: 20px; border-top: 1px solid rgba(255, 255, 255, 0.1); }
    .user-avatar { width: 40px; height: 40px; background: rgba(255, 255, 255, 0.2); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 18px; }
    .user-details h4 { font-size: 14px; font-weight: 600; }
    .user-details span { font-size: 12px; opacity: 0.8; display: block; }
    
    .nav-menu { flex: 1; padding: 20px 0; overflow-y: auto; }
    
    .nav-item { padding: 16px 24px; display: flex; align-items: center; gap: 12px; color: var(--secondary, #64748b); text-decoration: none; transition: all 0.3s; border-left: 4px solid transparent; }
    .nav-item:hover, .nav-item.active { background: rgba(37, 99, 235, 0.1); color: var(--primary, #2563eb); border-left-color: var(--primary, #2563eb); }
    .nav-item i { font-size: 18px; width: 24px; }
    .nav-item span { font-weight: 500; }
    
    .logout-btn { margin-top: auto; border-top: 1px solid var(--gray, #e2e8f0); }
    .logout-btn .nav-item { color: var(--danger, #ef4444); }
    .logout-btn .nav-item:hover { background: rgba(239, 68, 68, 0.1); color: var(--danger, #ef4444); border-left-color: var(--danger, #ef4444); }

    .menu-toggle { display: none; background: var(--primary, #2563eb); color: white; border: none; width: 44px; height: 44px; border-radius: 10px; font-size: 20px; cursor: pointer; align-items: center; justify-content: center; }
    .overlay { display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0, 0, 0, 0.5); z-index: 999; }
    
    @media (max-width: 1024px) {
        .sidebar { transform: translateX(-100%); }
        .sidebar.open { transform: translateX(0); }
        .menu-toggle { display: flex; }
        .overlay.open { display: block; }
    }
</style>

<div class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <h2><i class="fas fa-hotel"></i> Hotel Supreme</h2>
        <p>Management System</p>
        
        <div class="user-info">
            <div class="user-avatar"><i class="fas fa-user"></i></div>
            <div class="user-details">
                <h4><?php echo htmlspecialchars($_SESSION['username']); ?></h4>
                <span><?php echo htmlspecialchars($_SESSION['role']); ?></span>
            </div>
        </div>
    </div>
    
    <div class="nav-menu">
        <a href="menu.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'menu.php' ? 'active' : ''; ?>">
            <i class="fas fa-home"></i><span>Dashboard</span>
        </a>
        
        <a href="rooms.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'rooms.php' ? 'active' : ''; ?>">
            <i class="fas fa-door-open"></i><span>Rooms</span>
        </a>

        <a href="guest_list.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'guest_list.php' ? 'active' : ''; ?>">
            <i class="fas fa-users"></i><span>Guests</span>
        </a>
        
        <a href="inventory.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'inventory.php' ? 'active' : ''; ?>">
            <i class="fas fa-boxes"></i><span>Inventory</span>
        </a>
        
        <a href="reports.php" class="nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'reports.php' ? 'active' : ''; ?>">
            <i class="fas fa-chart-bar"></i><span>Reports</span>
        </a>
        
        <div class="logout-btn">
            <a href="logout.php" class="nav-item">
                <i class="fas fa-sign-out-alt"></i><span>Logout</span>
            </a>
        </div>
    </div>
</div>
<div class="overlay" id="overlay"></div>
<script>
function toggleSidebar() {
    document.getElementById('sidebar').classList.toggle('open');
    document.getElementById('overlay').classList.toggle('open');
}
document.getElementById('overlay').addEventListener('click', toggleSidebar);
</script>