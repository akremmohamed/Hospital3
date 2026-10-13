<?php
/**
 * Pharmacist Navigation Sidebar
 * Replaces default sidebar with pharmacist‑specific menu and dynamic badges.
 *
 * @package HMS-ERP
 * @version 1.1 (removed conflicting toggle listeners)
 */

// Prevent direct access
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ============================================
// 1. IRONCLAD ROLE ACCESS CONTROL
// ============================================
if (!isset($_SESSION['user_id']) || !isset($_SESSION['branch_id']) || ($_SESSION['user_type'] ?? '') !== 'pharmacist') {
    die('Access Denied. Pharmacist clearance rules required.');
}

date_default_timezone_set('Africa/Addis_Ababa');

require_once __DIR__ . '/../../../../config/db_connection.php';
$pdo = getDB();
if (!$pdo) {
    error_log('Database connection failed in pharmacy_sidebar.php');
    $pending_dispense_count = 0;
    $low_stock_count = 0;
    $inbound_transfers_count = 0;
    $pharmacist_name = 'Pharmacist';
    $branch_name = 'Unknown Branch';
} else {
    $branch_id = (int)$_SESSION['branch_id'];
    $user_id   = (int)$_SESSION['user_id'];

    // Pending dispense count
    $pending_sql = "
        SELECT COUNT(*) AS total
        FROM prescriptions p
        INNER JOIN patient_visits pv ON p.visit_id = pv.id
        WHERE pv.branch_id = :branch_id
          AND p.status IN ('pending_payment', 'active')
          AND DATE(p.created_at) = CURDATE()
          AND p.deleted_at IS NULL
    ";
    $pending_stmt = $pdo->prepare($pending_sql);
    $pending_stmt->execute([':branch_id' => $branch_id]);
    $pending_dispense_count = (int)$pending_stmt->fetchColumn();

    // Low stock alerts
    $low_stock_sql = "
        SELECT COUNT(DISTINCT pi.medicine_id) AS total
        FROM pharmacy_inventory pi
        WHERE pi.branch_id = :branch_id
          AND pi.deleted_at IS NULL
          AND pi.quantity_in_hand > 0
          AND pi.quantity_in_hand <= pi.reorder_level
          AND pi.expiry_date >= CURDATE()
          AND pi.is_quarantined = 0
    ";
    $low_stock_stmt = $pdo->prepare($low_stock_sql);
    $low_stock_stmt->execute([':branch_id' => $branch_id]);
    $low_stock_count = (int)$low_stock_stmt->fetchColumn();

    // Inbound transfer requests pending
    $inbound_sql = "
        SELECT COUNT(*) AS total
        FROM branch_transfers
        WHERE to_branch_id = :branch_id
          AND transfer_type = 'pharmacy_stock'
          AND transfer_status = 'pending'
          AND deleted_at IS NULL
    ";
    $inbound_stmt = $pdo->prepare($inbound_sql);
    $inbound_stmt->execute([':branch_id' => $branch_id]);
    $inbound_transfers_count = (int)$inbound_stmt->fetchColumn();

    // Pharmacist name and branch name
    $user_stmt = $pdo->prepare("SELECT CONCAT(first_name, ' ', COALESCE(middle_name, ''), ' ', last_name) AS name FROM users WHERE id = :id");
    $user_stmt->execute([':id' => $user_id]);
    $pharmacist_name = $user_stmt->fetchColumn() ?: 'Pharmacist';

    $branch_stmt = $pdo->prepare("SELECT name FROM branches WHERE id = :id AND deleted_at IS NULL");
    $branch_stmt->execute([':id' => $branch_id]);
    $branch_name = $branch_stmt->fetchColumn() ?: 'Unknown Branch';
}

$current_file = basename($_SERVER['PHP_SELF'] ?? 'pharmacy_dashboard.php');
?>
<!DOCTYPE html>
<html>
<head>
<style>
    /* Your existing CSS – unchanged (keep exactly as you had) */
    :root {
        --primary: #4361ee;
        --primary-dark: #3a0ca3;
        --success: #06d6a0;
        --warning: #f9c74f;
        --danger: #ef476f;
        --info: #4cc9f0;
        --gray: #6b7280;
        --light-gray: #f8fafc;
        --border-gray: #e5e7eb;
        --text-dark: #1f2937;
        --text-muted: #6b7280;
        --sidebar-width: 260px;
        --sidebar-collapsed-width: 80px;
        --header-height: 70px;
    }
    * { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; }
    .left-side-menu {
        position: fixed;
        top: var(--header-height);
        left: 0;
        bottom: 0;
        width: var(--sidebar-width);
        background: #ffffff;
        border-right: 1px solid var(--border-gray);
        z-index: 1021;
        overflow-y: auto;
        overflow-x: hidden;
        transition: width 0.3s ease;
        box-shadow: 0 0 15px rgba(0, 0, 0, 0.03);
    }
    @media (max-width: 991.98px) {
        .left-side-menu {
            transform: translateX(-100%);
            transition: transform 0.3s ease-in-out;
            position: fixed;
            top: 70px;
            height: calc(100vh - 70px);
            width: 260px !important;
            z-index: 1050;
            display: block !important;
        }
        body.sidebar-enable .left-side-menu { transform: translateX(0); }
        .sidebar-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(15, 23, 42, 0.5);
            backdrop-filter: blur(3px);
            z-index: 1040;
            display: none;
            cursor: pointer;
        }
        body.sidebar-enable .sidebar-overlay { display: block; }
        body.sidebar-enable { overflow: hidden; }
    }
    @media (min-width: 992px) {
        body.erp-sidebar-collapsed .left-side-menu { width: var(--sidebar-collapsed-width); }
        body.erp-sidebar-collapsed .doctor-info,
        body.erp-sidebar-collapsed .specialty-badge span:not(i),
        body.erp-sidebar-collapsed .stats-preview,
        body.erp-sidebar-collapsed .menu-title,
        body.erp-sidebar-collapsed #side-menu > li.nav-item > a span:not(.menu-arrow),
        body.erp-sidebar-collapsed .menu-arrow { display: none !important; }
        body.erp-sidebar-collapsed .doctor-header { padding: 16px 0; text-align: center; }
        body.erp-sidebar-collapsed .doctor-avatar { justify-content: center; }
        body.erp-sidebar-collapsed .avatar-icon { width: 40px; height: 40px; font-size: 16px; }
        body.erp-sidebar-collapsed .specialty-badge { justify-content: center; padding: 5px 0; }
        body.erp-sidebar-collapsed #side-menu > li.nav-item > a { justify-content: center; padding: 14px 0; }
        body.erp-sidebar-collapsed #side-menu > li.nav-item > a i { font-size: 1.2rem; width: auto; }
    }
    .left-side-menu::-webkit-scrollbar { width: 4px; }
    .left-side-menu::-webkit-scrollbar-track { background: transparent; }
    .left-side-menu::-webkit-scrollbar-thumb { background: #d1d5db; border-radius: 4px; }
    .left-side-menu::-webkit-scrollbar-thumb:hover { background: var(--primary); }
    .doctor-header { padding: 20px 16px; border-bottom: 1px solid var(--border-gray); background: linear-gradient(135deg, #ffffff, #fafbfc); }
    .doctor-avatar { display: flex; align-items: center; gap: 12px; margin-bottom: 12px; }
    .avatar-icon { width: 48px; height: 48px; background: linear-gradient(135deg, var(--primary), var(--primary-dark)); border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; font-size: 20px; flex-shrink: 0; }
    .doctor-info { flex: 1; min-width: 0; }
    .doctor-info h3 { font-size: 14px; font-weight: 700; color: var(--text-dark); margin: 0 0 4px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .doctor-info p { font-size: 11px; color: var(--text-muted); margin: 0; }
    .specialty-badge { background: var(--light-gray); border-radius: 20px; padding: 5px 10px; font-size: 10px; display: inline-flex; align-items: center; gap: 6px; color: var(--text-dark); font-weight: 500; margin-bottom: 12px; }
    .stats-preview { background: var(--light-gray); border-radius: 12px; padding: 10px; }
    .stat-row { display: flex; justify-content: space-between; align-items: center; font-size: 11px; padding: 5px 0; color: var(--text-muted); }
    .stat-value { font-weight: 700; color: var(--text-dark); background: white; padding: 2px 8px; border-radius: 20px; font-size: 10px; }
    .stat-value.critical { background: var(--danger); color: white; }
    .menu-title { color: var(--text-muted); font-weight: 700; letter-spacing: 0.75px; font-size: 0.65rem; text-transform: uppercase; padding: 20px 16px 8px 16px; }
    #side-menu { list-style: none; padding: 0; margin: 0; }
    #side-menu > li.nav-item { list-style: none; }
    #side-menu > li.nav-item > a {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 11px 16px;
        color: var(--text-dark);
        font-weight: 500;
        font-size: 0.85rem;
        text-decoration: none;
        border-left: 3px solid transparent;
        transition: all 0.2s ease;
        cursor: pointer;
    }
    #side-menu > li.nav-item > a:hover { background: var(--light-gray); color: var(--primary); border-left-color: var(--primary); }
    #side-menu > li.nav-item.active > a { background: linear-gradient(90deg, rgba(67, 97, 238, 0.08), transparent); color: var(--primary); border-left-color: var(--primary); font-weight: 600; }
    #side-menu > li.nav-item > a i { width: 22px; font-size: 1rem; color: var(--text-muted); text-align: center; }
    #side-menu > li.nav-item.active > a i { color: var(--primary); }
    .menu-arrow { margin-left: auto; border: solid var(--text-muted); border-width: 0 2px 2px 0; display: inline-block; padding: 3px; transform: rotate(45deg); transition: transform 0.2s; }
    #side-menu > li.nav-item.open > a .menu-arrow { transform: rotate(-135deg); }
    .nav-second-level { display: none; list-style: none; padding: 0; margin: 0; background: #fafbfc; }
    #side-menu > li.nav-item.open > .nav-second-level { display: block; }
    .nav-second-level li a {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 9px 16px 9px 48px;
        color: var(--text-muted);
        font-weight: 500;
        font-size: 0.8rem;
        text-decoration: none;
        border-left: 2px solid transparent;
        transition: all 0.2s;
    }
    .nav-second-level li a:hover { background: var(--light-gray); color: var(--primary); border-left-color: var(--primary); padding-left: 52px; }
    .nav-second-level li a.active { color: var(--primary); font-weight: 600; background: var(--light-gray); border-left-color: var(--primary); }
    .badge-pending {
        margin-left: auto;
        background: var(--danger);
        color: white;
        font-size: 9px;
        padding: 2px 6px;
        border-radius: 20px;
        font-weight: 600;
        animation: pulse 1.5s infinite;
    }
    .badge-lowstock {
        margin-left: auto;
        background: var(--warning);
        color: #92400e;
        font-size: 9px;
        padding: 2px 6px;
        border-radius: 20px;
        font-weight: 600;
    }
    .badge-inbound {
        margin-left: auto;
        background: var(--info);
        color: #0c4e6e;
        font-size: 9px;
        padding: 2px 6px;
        border-radius: 20px;
        font-weight: 600;
    }
    @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.7; } }
</style>
</head>
<body>

<div class="left-side-menu">
    <div class="slimscroll-menu">
        <div id="sidebar-menu">
            <ul class="metismenu" id="side-menu">

                <li class="menu-title">Pharmacy Operations</li>

                <!-- 1. Operational Dashboard -->
                <li class="nav-item <?php echo ($current_file == 'pharmacy_dashboard.php') ? 'active open' : ''; ?>">
                    <a href="dashboard.php">
                        <i class="fa-solid fa-gauge-high"></i>
                        <span>Operational Dashboard</span>
                    </a>
                </li>

                <!-- 2. Prescription Dispensary Desk -->
                <li class="nav-item <?php echo ($current_file == 'prescription_dispensary.php') ? 'active open' : ''; ?>">
                    <a href="prescription_dispensary.php">
                        <i class="fa-solid fa-capsules"></i>
                        <span>Prescription Dispensary Desk</span>
                        <?php if ($pending_dispense_count > 0): ?>
                            <span class="badge-pending"><?php echo $pending_dispense_count; ?></span>
                        <?php endif; ?>
                    </a>
                </li>

                <!-- 3. Comprehensive Stock Inventory -->
                <li class="nav-item <?php echo ($current_file == 'pharmacy_inventory.php') ? 'active open' : ''; ?>">
                    <a href="pharmacy_inventory.php">
                        <i class="fa-solid fa-boxes-stacked"></i>
                        <span>Comprehensive Stock Inventory</span>
                        <?php if ($low_stock_count > 0): ?>
                            <span class="badge-lowstock"><?php echo $low_stock_count; ?></span>
                        <?php endif; ?>
                    </a>
                </li>

                <!-- 4. Integrated POS & Retail Billing -->
                <li class="nav-item <?php echo ($current_file == 'pharmacy_billing.php') ? 'active open' : ''; ?>">
                    <a href="pharmacy_billing.php">
                        <i class="fa-solid fa-file-invoice-dollar"></i>
                        <span>Integrated POS & Retail Billing</span>
                    </a>
                </li>

                <!-- 5. Inter-Branch Stock Transfers -->
                <li class="nav-item <?php echo ($current_file == 'pharmacy_transfers.php') ? 'active open' : ''; ?>">
                    <a href="pharmacy_transfers.php">
                        <i class="fa-solid fa-truck-ramp-box"></i>
                        <span>Inter-Branch Stock Transfers</span>
                        <?php if ($inbound_transfers_count > 0): ?>
                            <span class="badge-inbound"><?php echo $inbound_transfers_count; ?></span>
                        <?php endif; ?>
                    </a>
                </li>

                <!-- 6. Narcotics Forensic Audit Logs -->
                <li class="nav-item <?php echo ($current_file == 'pharmacy_forensic_audit.php') ? 'active open' : ''; ?>">
                    <a href="pharmacy_forensic_audit.php">
                        <i class="fa-solid fa-shield-halved"></i>
                        <span>Narcotics Forensic Audit Logs</span>
                    </a>
                </li>

            </ul>
        </div>
        <div class="clearfix"></div>
    </div>
</div>

<!-- Overlay element for mobile -->
<div class="sidebar-overlay"></div>

<!-- FontAwesome 6 -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

<script>
(function() {
    'use strict';
    const currentPage = window.location.pathname.split('/').pop() || 'pharmacy_dashboard.php';
    let overlay = document.querySelector('.sidebar-overlay');
    if (!overlay) {
        overlay = document.createElement('div');
        overlay.className = 'sidebar-overlay';
        document.body.appendChild(overlay);
    }

    // Ensure the overlay can close the sidebar (without toggling)
    overlay.addEventListener('click', function() {
        document.body.classList.remove('sidebar-enable');
        document.body.style.overflow = '';
    });

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && window.innerWidth <= 992 && document.body.classList.contains('sidebar-enable')) {
            document.body.classList.remove('sidebar-enable');
            document.body.style.overflow = '';
        }
    });

    // Active menu highlighting
    function setActiveMenu() {
        document.querySelectorAll('#side-menu .nav-item').forEach(item => item.classList.remove('active', 'open'));
        document.querySelectorAll('#side-menu .nav-second-level a').forEach(link => link.classList.remove('active'));
        const currentLink = document.querySelector(`#side-menu a[href="${currentPage}"]`);
        if (currentLink) {
            currentLink.classList.add('active');
            const parentLi = currentLink.closest('li.nav-item');
            if (parentLi) parentLi.classList.add('active', 'open');
        }
    }

    setActiveMenu();
})();
</script>
</body>
</html>