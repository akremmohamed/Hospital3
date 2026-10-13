<?php
/**
 * sidebar.php
 * 
 * ERP Sidebar Navigation Component – Laboratory Module Integrated
 * 
 * This file renders the main navigation sidebar for the HMS ERP system.
 * It features dynamic counters for the Laboratory Information System (LIS) phases,
 * multi-branch isolation, role-based visibility, and MetisMenu support.
 * 
 * Uses correct column names from hms_erp.sql schema:
 * - lab_orders.status, lab_orders.priority, lab_orders.payment_status
 * - lab_test_results.verified_by_user_id, lab_test_results.is_critical
 * - pharmacy_inventory.quantity_in_hand, pharmacy_inventory.reorder_level
 * 
 * @package HMS_ERP
 */

// ----------------------------------------------------------------------
// 1. Security & Session Validation
// ----------------------------------------------------------------------
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Set timezone to Addis Ababa (Africa/Addis_Ababa)
date_default_timezone_set('Africa/Addis_Ababa');

// Ensure the user is logged in and branch context is available
if (empty($_SESSION['user_id']) || empty($_SESSION['branch_id'])) {
    // Silent fallback – sidebar will not display branch‑sensitive counts
    $branch_id = 0;
    $user_id = 0;
    $is_global_admin = false;
    $user_type = '';
} else {
    $user_id = (int)$_SESSION['user_id'];
    $branch_id = (int)$_SESSION['branch_id'];
    $user_type = $_SESSION['user_type'] ?? '';
    $is_global_admin = ($user_type === 'super_admin');
}

// Ensure database connection ($pdo) is available
if (!isset($pdo) || !($pdo instanceof PDO)) {
    require_once __DIR__ . '/../../config/db_connection.php';
}

// ----------------------------------------------------------------------
// 2. Dynamic LIS Counter Calculations (Branch‑scoped, super_admin bypass)
// Using correct column names from the schema
// ----------------------------------------------------------------------
$orders_queue_count = 0;          // status = 'ordered' OR 'pending' (awaiting collection)
$reception_queue_count = 0;       // status = 'collected' (sample received)
$result_entry_count = 0;          // status = 'in_progress' (analysis underway)
$verification_queue_count = 0;    // status = 'completed' AND verified_by_user_id IS NULL
$critical_low_reagents = 0;       // pharmacy_inventory.quantity_in_hand <= reorder_level
$critical_results_pending = 0;    // is_critical = 1 AND doctor_reviewed_at IS NULL
$pending_payment_count = 0;       // payment_status IN ('unpaid', 'partial')
$overdue_tat_count = 0;           // tests exceeding turnaround time
$pending_qc_count = 0;            // pending quality control checks
$total_lab_alerts = 0;

try {
    // 2.1. Orders Queue: lab_orders with status = 'ordered' OR 'pending'
    $sql = "SELECT COUNT(*) FROM lab_orders 
            WHERE status IN ('ordered', 'pending') 
              AND deleted_at IS NULL";
    $params = [];
    if (!$is_global_admin && $branch_id > 0) {
        $sql .= " AND branch_id = :branch_id";
        $params[':branch_id'] = $branch_id;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $orders_queue_count = (int)$stmt->fetchColumn();

    // 2.2. Specimen Reception Queue: lab_orders with status = 'collected'
    $sql = "SELECT COUNT(*) FROM lab_orders 
            WHERE status = 'collected' 
              AND deleted_at IS NULL";
    $params = [];
    if (!$is_global_admin && $branch_id > 0) {
        $sql .= " AND branch_id = :branch_id";
        $params[':branch_id'] = $branch_id;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $reception_queue_count = (int)$stmt->fetchColumn();

    // 2.3. Result Entry Queue: lab_orders with status = 'in_progress'
    $sql = "SELECT COUNT(*) FROM lab_orders 
            WHERE status = 'in_progress' 
              AND deleted_at IS NULL";
    $params = [];
    if (!$is_global_admin && $branch_id > 0) {
        $sql .= " AND branch_id = :branch_id";
        $params[':branch_id'] = $branch_id;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $result_entry_count = (int)$stmt->fetchColumn();

    // 2.4. Result Verification Queue: completed orders whose test results are not yet verified
    $sql = "SELECT COUNT(DISTINCT lo.id)
            FROM lab_orders lo
            INNER JOIN lab_test_results ltr ON lo.id = ltr.lab_order_id
            WHERE lo.status = 'completed'
              AND ltr.verified_by_user_id IS NULL
              AND lo.deleted_at IS NULL
              AND ltr.deleted_at IS NULL";
    $params = [];
    if (!$is_global_admin && $branch_id > 0) {
        $sql .= " AND lo.branch_id = :branch_id";
        $params[':branch_id'] = $branch_id;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $verification_queue_count = (int)$stmt->fetchColumn();

    // 2.5. Critical Results Pending Doctor Review
    $sql = "SELECT COUNT(DISTINCT lo.id)
            FROM lab_orders lo
            INNER JOIN lab_test_results ltr ON lo.id = ltr.lab_order_id
            WHERE ltr.is_critical = 1
              AND ltr.doctor_reviewed_at IS NULL
              AND lo.deleted_at IS NULL
              AND ltr.deleted_at IS NULL";
    $params = [];
    if (!$is_global_admin && $branch_id > 0) {
        $sql .= " AND lo.branch_id = :branch_id";
        $params[':branch_id'] = $branch_id;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $critical_results_pending = (int)$stmt->fetchColumn();

    // 2.6. Pending Payment Orders
    $sql = "SELECT COUNT(*) FROM lab_orders 
            WHERE payment_status IN ('unpaid', 'partial') 
              AND status NOT IN ('cancelled', 'rejected')
              AND deleted_at IS NULL";
    $params = [];
    if (!$is_global_admin && $branch_id > 0) {
        $sql .= " AND branch_id = :branch_id";
        $params[':branch_id'] = $branch_id;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $pending_payment_count = (int)$stmt->fetchColumn();

    // 2.7. Critical Low Reagents (pharmacy_inventory)
    try {
        $sql = "SELECT COUNT(*) FROM pharmacy_inventory pi
                JOIN medicines_master mm ON pi.medicine_id = mm.id
                WHERE pi.quantity_in_hand <= pi.reorder_level
                  AND pi.deleted_at IS NULL
                  AND mm.deleted_at IS NULL
                  AND mm.is_active = 1";
        $params = [];
        if (!$is_global_admin && $branch_id > 0) {
            $sql .= " AND pi.branch_id = :branch_id";
            $params[':branch_id'] = $branch_id;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $critical_low_reagents = (int)$stmt->fetchColumn();
    } catch (PDOException $e) {
        $critical_low_reagents = 0;
    }

    // 2.8. Overdue TAT - tests exceeding expected turnaround time
    try {
        $sql = "SELECT COUNT(DISTINCT lo.id) 
                FROM lab_orders lo
                WHERE lo.status NOT IN ('completed', 'cancelled', 'rejected')
                  AND lo.order_date <= DATE_SUB(CURDATE(), INTERVAL 3 DAY)
                  AND lo.deleted_at IS NULL";
        $params = [];
        if (!$is_global_admin && $branch_id > 0) {
            $sql .= " AND lo.branch_id = :branch_id";
            $params[':branch_id'] = $branch_id;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $overdue_tat_count = (int)$stmt->fetchColumn();
    } catch (PDOException $e) {
        $overdue_tat_count = 0;
    }

    // 2.9. Pending Quality Control checks
    try {
        $sql = "SELECT COUNT(*) FROM lab_test_results 
                WHERE (is_abnormal = 1 OR is_critical = 1)
                  AND verified_by_user_id IS NULL
                  AND deleted_at IS NULL";
        if (!$is_global_admin && $branch_id > 0) {
            $sql .= " AND lab_order_id IN (SELECT id FROM lab_orders WHERE branch_id = :branch_id)";
            $params[':branch_id'] = $branch_id;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $pending_qc_count = (int)$stmt->fetchColumn();
    } catch (PDOException $e) {
        $pending_qc_count = 0;
    }

    // Calculate total alerts
    $total_lab_alerts = $orders_queue_count + $reception_queue_count + $result_entry_count 
                      + $verification_queue_count + $critical_results_pending 
                      + $pending_payment_count + $critical_low_reagents
                      + $overdue_tat_count + $pending_qc_count;

} catch (PDOException $e) {
    // Log error but do not break sidebar rendering
    error_log('Sidebar counter error: ' . $e->getMessage());
}

// ----------------------------------------------------------------------
// 3. User Profile Data for Sidebar Display
// ----------------------------------------------------------------------
$sidebar_user_name = 'Lab Staff';
$sidebar_user_role = 'Laboratorist';
$sidebar_user_initials = 'LS';

try {
    $stmt = $pdo->prepare("
        SELECT 
            u.first_name, u.middle_name, u.last_name, u.user_type,
            sp.specialization, sp.job_title
        FROM users u
        LEFT JOIN staff_profiles sp ON sp.user_id = u.id AND sp.deleted_at IS NULL
        WHERE u.id = :id AND u.deleted_at IS NULL
    ");
    $stmt->execute([':id' => $user_id]);
    $userRow = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($userRow) {
        $fullName = trim(
            ($userRow['first_name'] ?? '') . ' ' .
            ($userRow['middle_name'] ?? '') . ' ' .
            ($userRow['last_name'] ?? '')
        );
        $fullName = preg_replace('/\s+/', ' ', $fullName);
        $sidebar_user_name = $fullName ?: $sidebar_user_name;
        $sidebar_user_role = ucfirst(str_replace('_', ' ', $userRow['user_type'] ?? 'laboratorist'));
        if (!empty($userRow['specialization'])) {
            $sidebar_user_role = $userRow['specialization'];
        } elseif (!empty($userRow['job_title'])) {
            $sidebar_user_role = $userRow['job_title'];
        }
        $sidebar_user_initials = mb_strtoupper(mb_substr($fullName ?: $sidebar_user_name, 0, 1, 'UTF-8'), 'UTF-8');
        if (strpos($fullName, ' ') !== false) {
            $parts = explode(' ', trim($fullName));
            if (count($parts) >= 2) {
                $sidebar_user_initials = mb_strtoupper(mb_substr($parts[0], 0, 1, 'UTF-8') . mb_substr($parts[1], 0, 1, 'UTF-8'), 'UTF-8');
            }
        }
    }
} catch (PDOException $e) {
    // Use defaults
}
?>
<!-- Sidebar Navigation -->
<aside id="sidebar-wrapper">
    <div class="sidebar-brand">
        <a href="dashboard.php" class="logo">
            <i class="fas fa-flask"></i>
            <span class="logo-text">Lab HMS</span>
        </a>
    </div>

    <div class="sidebar-menu">
        <ul id="side-menu" class="metismenu">
            <!-- ==================== DASHBOARD ==================== -->
            <li>
                <a href="dashboard.php">
                    <i class="fas fa-tachometer-alt"></i>
                    <span>Dashboard</span>
                    <?php if ($total_lab_alerts > 0): ?>
                        <span class="badge bg-danger ms-auto animated"><?php echo $total_lab_alerts; ?></span>
                    <?php endif; ?>
                </a>
            </li>

            <!-- ==================== Stock management WORKSPACE ==================== -->
            <li class="menu-title">
                <span>Stock management</span>
            </li>

            <!-- Phase 1: Stock Management -->
            <li>
                <a href="lab_inventory.php" class="has-arrow">
                    <i class="fas fa-boxes"></i>
                    <span>Stock Management</span>
                    <?php if ($critical_low_reagents > 0): ?>
                        <span class="badge bg-danger ms-auto"><?php echo $critical_low_reagents; ?></span>
                    <?php else: ?>
                        <span class="badge bg-secondary ms-auto">0</span>
                    <?php endif; ?>
                </a>
                <ul class="mm-collapse">
                    <li><a href="lab_inventory.php?action=reorder">In Stock request</a></li>
                    <li><a href="lab_inventory.php?action=expiring">Available Stock </a></li>   
                </ul>
            </li>

            <!-- ==================== TEST MANAGEMENT ==================== -->
            <li class="menu-title">
                <span>Test Management</span>
            </li>

            <!-- Test Catalog -->
            <li>
                <a href="test_catalog.php">
                    <i class="fas fa-book-medical"></i>
                    <span>Test Catalog</span>
                </a>
            </li>

            <!-- Test Panels -->
            <li>
                <a href="test_panels.php">
                    <i class="fas fa-layer-group"></i>
                    <span>Test Panels</span>
                </a>
            </li>

            <!-- Reference Ranges -->
            <li>
                <a href="reference_ranges.php">
                    <i class="fas fa-ruler-vertical"></i>
                    <span>Reference Ranges</span>
                </a>
            </li>

            <!-- ==================== REPORTS & ANALYTICS ==================== -->
            <li class="menu-title">
                <span>Reports & Analytics</span>
            </li>

            <!-- Lab Reports -->
            <li>
                <a href="lab_reports.php">
                    <i class="fas fa-chart-bar"></i>
                    <span>Lab Reports</span>
                </a>
            </li>

            <!-- Patient Reports -->
            <li>
                <a href="patient_reports.php">
                    <i class="fas fa-file-medical-alt"></i>
                    <span>Patient Reports</span>
                </a>
            </li>
        </ul>
    </div>
</aside>

<!-- Sidebar Toggle Button for Mobile -->
<div class="sidebar-toggler">
    <button class="btn btn-link text-dark" id="mobileMenuToggle">
        <i class="fas fa-bars"></i>
    </button>
</div>

<!-- MetisMenu CDN -->
<link href="https://cdn.jsdelivr.net/npm/metismenujs@1.3.1/dist/metismenujs.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/metismenujs@1.3.1/dist/metismenujs.min.js"></script>

<script>
/**
 * Sidebar JavaScript Logic
 * Preserves MetisMenu, localStorage collapsed state, active menu highlighting,
 * and mobile responsive behavior.
 */
(function() {
    'use strict';

    // Ensure MetisMenu library is loaded before initializing
    function initMetisMenu() {
        if (typeof MetisMenu !== 'undefined') {
            try {
                const sideMenu = document.getElementById('side-menu');
                if (sideMenu && !sideMenu.hasAttribute('data-metismenu-initialized')) {
                    new MetisMenu(sideMenu, {
                        toggle: true,
                        triggerElement: 'a.has-arrow',
                        parentTrigger: 'li',
                        subMenu: 'ul'
                    });
                    sideMenu.setAttribute('data-metismenu-initialized', 'true');
                }
            } catch (e) {
                console.warn('MetisMenu initialization failed:', e);
            }
        } else {
            // Retry after a short delay if MetisMenu not yet loaded
            setTimeout(initMetisMenu, 200);
        }
    }

    // Set active menu item based on current URL
    function setActiveMenu() {
        const currentPath = window.location.pathname.split('/').pop();
        const menuLinks = document.querySelectorAll('#side-menu a');
        menuLinks.forEach(link => {
            const href = link.getAttribute('href');
            if (href && href === currentPath) {
                link.classList.add('active');
                // Expand parent li.mm-active if necessary
                let parent = link.closest('li');
                while (parent) {
                    if (parent.classList.contains('mm-active')) {
                        parent.classList.add('mm-active');
                    } else if (parent.closest('ul') && parent.closest('ul').classList.contains('mm-collapse')) {
                        parent.classList.add('mm-active');
                    }
                    parent = parent.parentElement;
                }
            }
        });
    }

    // Handle sidebar collapse state (desktop only)
    function handleSidebarCollapse() {
        const body = document.body;
        const collapsedState = localStorage.getItem('erp_sidebar_collapsed');
        if (collapsedState === 'true') {
            body.classList.add('erp-sidebar-collapsed');
        } else {
            body.classList.remove('erp-sidebar-collapsed');
        }

        // Toggle button (if exists) – often placed in topbar
        const toggleBtn = document.querySelector('.sidebar-collapse-toggle');
        if (toggleBtn) {
            toggleBtn.addEventListener('click', function(e) {
                e.preventDefault();
                body.classList.toggle('erp-sidebar-collapsed');
                localStorage.setItem('erp_sidebar_collapsed', body.classList.contains('erp-sidebar-collapsed'));
            });
        }
    }

    // Mobile sidebar toggle
    function initMobileToggle() {
        const mobileToggle = document.getElementById('mobileMenuToggle');
        if (mobileToggle) {
            mobileToggle.addEventListener('click', function(e) {
                e.preventDefault();
                document.body.classList.toggle('sidebar-enable');
            });
        }

        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', function(event) {
            const sidebar = document.getElementById('sidebar-wrapper');
            const toggle = document.getElementById('mobileMenuToggle');
            if (window.innerWidth <= 768 && sidebar && toggle) {
                if (!sidebar.contains(event.target) && !toggle.contains(event.target)) {
                    document.body.classList.remove('sidebar-enable');
                }
            }
        });
    }

    // Auto-collapse on window resize
    function handleResize() {
        if (window.innerWidth > 768) {
            document.body.classList.remove('sidebar-enable');
        }
    }

    // Initialize everything when DOM is ready
    document.addEventListener('DOMContentLoaded', function() {
        initMetisMenu();
        setActiveMenu();
        handleSidebarCollapse();
        initMobileToggle();
        window.addEventListener('resize', handleResize);
    });
})();
</script>

<!-- Additional Styles for Sidebar -->
<style>
    /* Sidebar base styles */
    #sidebar-wrapper {
        position: fixed;
        top: 0;
        left: 0;
        width: 260px;
        height: 100%;
        background: #fff;
        border-right: 1px solid #e9ecef;
        z-index: 1000;
        transition: all 0.3s ease;
        box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
        overflow-y: auto;
        display: flex;
        flex-direction: column;
    }
    body.erp-sidebar-collapsed #sidebar-wrapper {
        width: 80px;
    }
    body.erp-sidebar-collapsed .sidebar-brand .logo-text,
    body.erp-sidebar-collapsed .sidebar-user,
    body.erp-sidebar-collapsed .metismenu span:not(.badge) {
        display: none;
    }
    body.erp-sidebar-collapsed .metismenu .badge {
        display: none;
    }
    body.erp-sidebar-collapsed .metismenu a {
        text-align: center;
        padding: 0.75rem 0;
    }
    body.erp-sidebar-collapsed .metismenu i {
        margin-right: 0;
        font-size: 1.25rem;
    }
    body.erp-sidebar-collapsed .metismenu .has-arrow::after {
        display: none;
    }
    
    .sidebar-brand {
        padding: 1.2rem 1rem;
        text-align: center;
        border-bottom: 1px solid #e9ecef;
        flex-shrink: 0;
    }
    .sidebar-brand .logo {
        font-size: 1.25rem;
        font-weight: 700;
        text-decoration: none;
        color: #2c3e50;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 10px;
    }
    .sidebar-brand .logo i {
        color: #4361ee;
        font-size: 1.5rem;
    }
    .sidebar-brand .logo .logo-text {
        font-size: 1.1rem;
    }
    
    .sidebar-user {
        padding: 1rem;
        border-bottom: 1px solid #e9ecef;
        display: flex;
        align-items: center;
        gap: 10px;
        flex-shrink: 0;
    }
    .sidebar-user .user-avatar {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        background: linear-gradient(135deg, #4361ee, #3a0ca3);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 16px;
        font-weight: 700;
        color: white;
        flex-shrink: 0;
    }
    .sidebar-user .user-info .user-name {
        font-size: 13px;
        font-weight: 600;
        color: #2c3e50;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        max-width: 140px;
    }
    .sidebar-user .user-info .user-role {
        font-size: 10px;
        color: #6c757d;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        max-width: 140px;
    }
    
    .sidebar-menu {
        padding: 0.5rem 0;
        flex: 1;
        overflow-y: auto;
    }
    .metismenu {
        display: flex;
        flex-direction: column;
        list-style: none;
        padding: 0;
        margin: 0;
    }
    .metismenu .menu-title {
        padding: 0.75rem 1rem 0.25rem;
        font-size: 0.65rem;
        text-transform: uppercase;
        font-weight: 700;
        color: #6c757d;
        letter-spacing: 0.5px;
    }
    .metismenu a {
        display: flex;
        align-items: center;
        padding: 0.625rem 1rem;
        color: #495057;
        text-decoration: none;
        transition: all 0.2s;
        border-left: 3px solid transparent;
        font-size: 0.875rem;
        position: relative;
    }
    .metismenu a:hover {
        background-color: #f8f9fa;
        color: #0d6efd;
    }
    .metismenu a.active {
        background-color: #e7f1ff;
        color: #0d6efd;
        border-left-color: #0d6efd;
    }
    .metismenu a .badge {
        margin-left: auto;
        font-size: 0.6rem;
        padding: 0.2rem 0.5rem;
        border-radius: 20px;
        flex-shrink: 0;
    }
    .metismenu a .badge.animated {
        animation: pulse 2s infinite;
    }
    .metismenu i {
        width: 2rem;
        font-size: 1rem;
        margin-right: 0.5rem;
        text-align: center;
        flex-shrink: 0;
    }
    .metismenu .mm-collapse {
        padding-left: 0.5rem;
    }
    .metismenu .mm-collapse a {
        padding-left: 2.5rem;
        font-size: 0.8rem;
    }
    .metismenu .mm-collapse a i {
        width: 1.5rem;
        font-size: 0.75rem;
    }
    .metismenu .has-arrow::after {
        position: absolute;
        right: 1rem;
        top: 50%;
        transform: translateY(-50%) rotate(-90deg);
        transition: transform 0.3s ease;
    }
    .metismenu .mm-active > .has-arrow::after {
        transform: translateY(-50%) rotate(0deg);
    }
    
    @keyframes pulse {
        0%, 100% { transform: scale(1); }
        50% { transform: scale(1.1); }
    }
    
    .text-danger {
        color: #dc3545 !important;
    }
    .text-danger:hover {
        color: #b02a37 !important;
    }
    
    .sidebar-toggler {
        position: fixed;
        bottom: 1rem;
        left: 0.5rem;
        z-index: 1100;
        display: none;
    }
    .sidebar-toggler .btn {
        background: white;
        border: 1px solid #e9ecef;
        border-radius: 50%;
        width: 40px;
        height: 40px;
        display: flex;
        align-items: center;
        justify-content: center;
        box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        color: #2c3e50;
        font-size: 1.1rem;
        text-decoration: none;
        transition: all 0.2s;
    }
    .sidebar-toggler .btn:hover {
        background: #f8f9fa;
        transform: scale(1.05);
    }
    
    /* Scrollbar styling */
    #sidebar-wrapper::-webkit-scrollbar {
        width: 5px;
    }
    #sidebar-wrapper::-webkit-scrollbar-track {
        background: #f1f1f1;
    }
    #sidebar-wrapper::-webkit-scrollbar-thumb {
        background: #c1c1c1;
        border-radius: 4px;
    }
    #sidebar-wrapper::-webkit-scrollbar-thumb:hover {
        background: #a8a8a8;
    }
    
    /* Mobile Responsive */
    @media (max-width: 768px) {
        #sidebar-wrapper {
            transform: translateX(-100%);
            width: 280px;
            box-shadow: 0 0 15px rgba(0,0,0,0.1);
        }
        body.sidebar-enable #sidebar-wrapper {
            transform: translateX(0);
        }
        .sidebar-toggler {
            display: block;
        }
        body.erp-sidebar-collapsed #sidebar-wrapper {
            width: 280px;
            transform: translateX(-100%);
        }
        body.erp-sidebar-collapsed.sidebar-enable #sidebar-wrapper {
            transform: translateX(0);
        }
        body.erp-sidebar-collapsed .sidebar-brand .logo-text,
        body.erp-sidebar-collapsed .sidebar-user,
        body.erp-sidebar-collapsed .metismenu span:not(.badge) {
            display: inline-block;
        }
        body.erp-sidebar-collapsed .metismenu a {
            text-align: left;
            padding: 0.625rem 1rem;
        }
        body.erp-sidebar-collapsed .metismenu i {
            margin-right: 0.5rem;
        }
        body.erp-sidebar-collapsed .metismenu .badge {
            display: inline-block;
        }
        body.erp-sidebar-collapsed .metismenu .has-arrow::after {
            display: block;
        }
        .sidebar-user .user-info .user-name {
            max-width: 180px;
        }
        .sidebar-user .user-info .user-role {
            max-width: 180px;
        }
    }
    
    @media (min-width: 769px) {
        .sidebar-toggler {
            display: none !important;
        }
    }
</style>