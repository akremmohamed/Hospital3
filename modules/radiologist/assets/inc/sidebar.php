<?php
/**
 * radiology_sidebar.php
 * 
 * ERP Sidebar Navigation Component – Radiology Module Integrated
 * 
 * This file renders the main navigation sidebar for the HMS ERP radiology module.
 * It features dynamic counters for the Radiology Information System (RIS) phases,
 * multi-branch isolation, role-based visibility, and MetisMenu support.
 * 
 * Uses correct column names from hms_erp.sql schema:
 * - radiology_orders.status, radiology_orders.priority, radiology_orders.payment_status
 * - radiology_orders.is_critical, radiology_orders.approval_status
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

// Role-based access control
$is_radiologist = ($user_type === 'radiologist' || $user_type === 'super_admin');
$is_radiology_tech = ($user_type === 'radiology_technician' || $user_type === 'super_admin');
$has_radiology_access = $is_radiologist || $is_radiology_tech;

// Ensure database connection ($pdo) is available
if (!isset($pdo) || !($pdo instanceof PDO)) {
    require_once __DIR__ . '/../../../../config/db_connection.php';
}

// ----------------------------------------------------------------------
// 2. Dynamic RIS Counter Calculations (Branch‑scoped, super_admin bypass)
// Using correct column names from the schema
// ----------------------------------------------------------------------
$orders_queue_count = 0;          // status = 'pending' (payment verified, awaiting scan)
$scan_acquisition_count = 0;      // status = 'image_captured' (scan complete, ready for reporting)
$worklist_count = 0;              // status = 'image_captured' OR 'in_progress' (ready for interpretation)
$reporting_count = 0;             // status = 'in_progress' (currently being reported)
$pending_approval_count = 0;      // status = 'reported' AND approval_status = 'pending'
$critical_findings_count = 0;     // is_critical = 1 AND status != 'cancelled'
$pending_payment_count = 0;       // payment_status IN ('unpaid', 'partial')
$archived_reports_count = 0;      // status = 'reported' OR 'completed'
$overdue_reports_count = 0;       // reports exceeding turnaround time
$total_radiology_alerts = 0;

try {
    // 2.1. Orders Queue: radiology_orders with status = 'pending' (payment verified, awaiting scan)
    $sql = "SELECT COUNT(*) FROM radiology_orders 
            WHERE status = 'pending' 
              AND deleted_at IS NULL";
    $params = [];
    if (!$is_global_admin && $branch_id > 0) {
        $sql .= " AND branch_id = :branch_id";
        $params[':branch_id'] = $branch_id;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $orders_queue_count = (int)$stmt->fetchColumn();

    // 2.2. Scan Acquisition Queue: radiology_orders with status = 'image_captured' (ready for reporting)
    // Also includes 'pending' if technician role to show what's waiting
    $sql = "SELECT COUNT(*) FROM radiology_orders 
            WHERE status = 'image_captured' 
              AND deleted_at IS NULL";
    $params = [];
    if (!$is_global_admin && $branch_id > 0) {
        $sql .= " AND branch_id = :branch_id";
        $params[':branch_id'] = $branch_id;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $scan_acquisition_count = (int)$stmt->fetchColumn();

    // 2.3. Radiology Worklist: orders ready for interpretation (image_captured or in_progress)
    $sql = "SELECT COUNT(*) FROM radiology_orders 
            WHERE status IN ('image_captured', 'in_progress') 
              AND deleted_at IS NULL";
    $params = [];
    if (!$is_global_admin && $branch_id > 0) {
        $sql .= " AND branch_id = :branch_id";
        $params[':branch_id'] = $branch_id;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $worklist_count = (int)$stmt->fetchColumn();

    // 2.4. Reporting in Progress: status = 'in_progress'
    $sql = "SELECT COUNT(*) FROM radiology_orders 
            WHERE status = 'in_progress' 
              AND deleted_at IS NULL";
    $params = [];
    if (!$is_global_admin && $branch_id > 0) {
        $sql .= " AND branch_id = :branch_id";
        $params[':branch_id'] = $branch_id;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $reporting_count = (int)$stmt->fetchColumn();

    // 2.5. Pending Approval: reported but not yet approved
    $sql = "SELECT COUNT(*) FROM radiology_orders 
            WHERE status = 'reported' 
              AND (approval_status = 'pending' OR approval_status IS NULL)
              AND deleted_at IS NULL";
    $params = [];
    if (!$is_global_admin && $branch_id > 0) {
        $sql .= " AND branch_id = :branch_id";
        $params[':branch_id'] = $branch_id;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $pending_approval_count = (int)$stmt->fetchColumn();

    // 2.6. Critical Findings: is_critical = 1 and not cancelled
    $sql = "SELECT COUNT(*) FROM radiology_orders 
            WHERE is_critical = 1 
              AND status NOT IN ('cancelled', 'rejected')
              AND deleted_at IS NULL";
    $params = [];
    if (!$is_global_admin && $branch_id > 0) {
        $sql .= " AND branch_id = :branch_id";
        $params[':branch_id'] = $branch_id;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $critical_findings_count = (int)$stmt->fetchColumn();

    // 2.7. Pending Payment Orders
    $sql = "SELECT COUNT(*) FROM radiology_orders 
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

    // 2.8. Archived Reports: completed or reported
    $sql = "SELECT COUNT(*) FROM radiology_orders 
            WHERE status IN ('reported', 'completed') 
              AND deleted_at IS NULL";
    $params = [];
    if (!$is_global_admin && $branch_id > 0) {
        $sql .= " AND branch_id = :branch_id";
        $params[':branch_id'] = $branch_id;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $archived_reports_count = (int)$stmt->fetchColumn();

    // 2.9. Overdue Reports - exceeding expected turnaround time
    try {
        $sql = "SELECT COUNT(DISTINCT ro.id) 
                FROM radiology_orders ro
                WHERE ro.status NOT IN ('completed', 'reported', 'cancelled', 'rejected')
                  AND ro.order_date <= DATE_SUB(CURDATE(), INTERVAL 3 DAY)
                  AND ro.deleted_at IS NULL";
        $params = [];
        if (!$is_global_admin && $branch_id > 0) {
            $sql .= " AND ro.branch_id = :branch_id";
            $params[':branch_id'] = $branch_id;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $overdue_reports_count = (int)$stmt->fetchColumn();
    } catch (PDOException $e) {
        $overdue_reports_count = 0;
    }

    // Calculate total alerts
    $total_radiology_alerts = $orders_queue_count + $scan_acquisition_count 
                           + $worklist_count + $reporting_count 
                           + $pending_approval_count + $critical_findings_count 
                           + $pending_payment_count + $overdue_reports_count;

} catch (PDOException $e) {
    // Log error but do not break sidebar rendering
    error_log('Radiology Sidebar counter error: ' . $e->getMessage());
}

// ----------------------------------------------------------------------
// 3. User Profile Data for Sidebar Display
// ----------------------------------------------------------------------
$sidebar_user_name = 'Radiology Staff';
$sidebar_user_role = 'Radiologist';
$sidebar_user_initials = 'RS';

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
        
        // Set appropriate role
        if ($userRow['user_type'] === 'radiologist') {
            $sidebar_user_role = 'Radiologist';
        } elseif ($userRow['user_type'] === 'radiology_technician') {
            $sidebar_user_role = 'Radiology Technician';
        } else {
            $sidebar_user_role = ucfirst(str_replace('_', ' ', $userRow['user_type'] ?? 'radiologist'));
        }
        
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
            <i class="fas fa-x-ray"></i>
            <span class="logo-text">Rad HMS</span>
        </a>
    </div>

    <!-- Sidebar User Info -->
    <div class="sidebar-user">
        <div class="user-avatar"><?php echo $sidebar_user_initials; ?></div>
        <div class="user-info">
            <div class="user-name"><?php echo htmlspecialchars($sidebar_user_name, ENT_QUOTES, 'UTF-8'); ?></div>
            <div class="user-role"><?php echo htmlspecialchars($sidebar_user_role, ENT_QUOTES, 'UTF-8'); ?></div>
        </div>
    </div>

    <div class="sidebar-menu">
        <ul id="side-menu" class="metismenu">
            <!-- ==================== DASHBOARD ==================== -->
            <li>
                <a href="dashboard.php">
                    <i class="fas fa-tachometer-alt"></i>
                    <span>Dashboard</span>
                    <?php if ($total_radiology_alerts > 0): ?>
                        <span class="badge bg-danger ms-auto animated"><?php echo $total_radiology_alerts; ?></span>
                    <?php endif; ?>
                </a>
            </li>

            <!-- ==================== RADIOLOGY WORKSPACE ==================== -->
            <li class="menu-title">
                <span>Radiology Workspace</span>
            </li>

            <!-- Orders Queue -->
            <li>
                <a href="radiology_orders_queue.php" class="has-arrow">
                    <i class="fas fa-clipboard-list"></i>
                    <span>Orders Queue</span>
                    <?php if ($orders_queue_count > 0): ?>
                        <span class="badge bg-warning ms-auto"><?php echo $orders_queue_count; ?></span>
                    <?php else: ?>
                        <span class="badge bg-secondary ms-auto">0</span>
                    <?php endif; ?>
                </a>
                <ul class="mm-collapse">
                    <li><a href="radiology_orders_queue.php?filter=all">All Orders</a></li>
                    <li><a href="radiology_orders_queue.php?filter=stat">STAT Orders</a></li>
                    <?php if ($pending_payment_count > 0): ?>
                        <li><a href="radiology_orders_queue.php?payment=unpaid" class="text-warning">💰 Pending Payment</a></li>
                    <?php endif; ?>
                    <?php if ($overdue_reports_count > 0): ?>
                        <li><a href="radiology_orders_queue.php?filter=overdue" class="text-danger">⏰ Overdue</a></li>
                    <?php endif; ?>
                </ul>
            </li>

            <!-- Scan Acquisition (Tech & Admin only) -->
            <?php if ($is_radiology_tech || $is_global_admin): ?>
            <li>
                <a href="scan_acquisition.php">
                    <i class="fas fa-camera"></i>
                    <span>Scan Acquisition</span>
                    <?php if ($orders_queue_count > 0): ?>
                        <span class="badge bg-info ms-auto"><?php echo $orders_queue_count; ?></span>
                    <?php else: ?>
                        <span class="badge bg-secondary ms-auto">0</span>
                    <?php endif; ?>
                </a>
            </li>
            <?php endif; ?>

            <!-- Radiology Worklist -->
            <li>
                <a href="radiology_worklist.php" class="has-arrow">
                    <i class="fas fa-list-ul"></i>
                    <span>Radiology Worklist</span>
                    <?php if ($worklist_count > 0): ?>
                        <span class="badge bg-primary ms-auto"><?php echo $worklist_count; ?></span>
                    <?php else: ?>
                        <span class="badge bg-secondary ms-auto">0</span>
                    <?php endif; ?>
                </a>
                <ul class="mm-collapse">
                    <li><a href="radiology_worklist.php?filter=all">All Cases</a></li>
                    <li><a href="radiology_worklist.php?status=image_captured">📷 Image Captured</a></li>
                    <?php if ($reporting_count > 0): ?>
                        <li><a href="radiology_worklist.php?status=in_progress" class="text-info">✍️ In Progress</a></li>
                    <?php endif; ?>
                    <?php if ($pending_approval_count > 0): ?>
                        <li><a href="radiology_worklist.php?filter=pending_approval" class="text-warning">⏳ Pending Approval</a></li>
                    <?php endif; ?>
                </ul>
            </li>

            <!-- Reporting Module -->
            <li>
                <a href="reporting_module.php">
                    <i class="fas fa-file-signature"></i>
                    <span>Reporting</span>
                    <?php if ($reporting_count > 0): ?>
                        <span class="badge bg-success ms-auto"><?php echo $reporting_count; ?></span>
                    <?php else: ?>
                        <span class="badge bg-secondary ms-auto">0</span>
                    <?php endif; ?>
                </a>
            </li>

            <!-- Critical Findings -->
            <li>
                <a href="critical_findings.php">
                    <i class="fas fa-exclamation-triangle"></i>
                    <span>Critical Findings</span>
                    <?php if ($critical_findings_count > 0): ?>
                        <span class="badge bg-danger ms-auto animated"><?php echo $critical_findings_count; ?></span>
                    <?php else: ?>
                        <span class="badge bg-secondary ms-auto">0</span>
                    <?php endif; ?>
                </a>
            </li>

            <!-- Report Approval (Admin & Senior Radiologist) -->
            <?php if ($is_global_admin || $is_radiologist): ?>
            <li>
                <a href="report_approval.php">
                    <i class="fas fa-check-double"></i>
                    <span>Report Approval</span>
                    <?php if ($pending_approval_count > 0): ?>
                        <span class="badge bg-warning ms-auto"><?php echo $pending_approval_count; ?></span>
                    <?php else: ?>
                        <span class="badge bg-secondary ms-auto">0</span>
                    <?php endif; ?>
                </a>
            </li>
            <?php endif; ?>

            <!-- ==================== IMAGE MANAGEMENT ==================== -->
            <li class="menu-title">
                <span>Image Management</span>
            </li>

            <!-- PACS Viewer -->
            <li>
                <a href="pacs_viewer.php">
                    <i class="fas fa-eye"></i>
                    <span>PACS Viewer</span>
                </a>
            </li>

            <!-- Image Archive -->
            <li>
                <a href="image_archive.php">
                    <i class="fas fa-archive"></i>
                    <span>Image Archive</span>
                    <?php if ($archived_reports_count > 0): ?>
                        <span class="badge bg-secondary ms-auto"><?php echo $archived_reports_count; ?></span>
                    <?php endif; ?>
                </a>
            </li>

            <!-- Study Comparison -->
            <li>
                <a href="study_comparison.php">
                    <i class="fas fa-not-equal"></i>
                    <span>Study Comparison</span>
                </a>
            </li>

            <!-- ==================== REPORTS & ANALYTICS ==================== -->
            <li class="menu-title">
                <span>Reports & Analytics</span>
            </li>

            <!-- Radiology Reports -->
            <li>
                <a href="radiology_reports.php" class="has-arrow">
                    <i class="fas fa-chart-bar"></i>
                    <span>Radiology Reports</span>
                </a>
                <ul class="mm-collapse">
                    <li><a href="radiology_reports.php?type=workload">Workload Report</a></li>
                    <li><a href="radiology_reports.php?type=tat">TAT Analysis</a></li>
                    <li><a href="radiology_reports.php?type=revenue">Revenue Report</a></li>
                    <li><a href="radiology_reports.php?type=utilization">Equipment Utilization</a></li>
                </ul>
            </li>

            <!-- Patient Reports -->
            <li>
                <a href="patient_reports.php">
                    <i class="fas fa-file-medical-alt"></i>
                    <span>Patient Reports</span>
                </a>
            </li>

            <!-- ==================== ADMINISTRATION ==================== -->
            <?php if ($is_global_admin): ?>
            <li class="menu-title">
                <span>Administration</span>
            </li>

            <!-- Equipment Management -->
            <li>
                <a href="equipment_management.php">
                    <i class="fas fa-microscope"></i>
                    <span>Equipment Management</span>
                </a>
            </li>

            <!-- Modality Configuration -->
            <li>
                <a href="modality_config.php">
                    <i class="fas fa-sliders-h"></i>
                    <span>Modality Configuration</span>
                </a>
            </li>

            <!-- Protocol Templates -->
            <li>
                <a href="protocol_templates.php">
                    <i class="fas fa-file-alt"></i>
                    <span>Protocol Templates</span>
                </a>
            </li>
            <?php endif; ?>
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
    .text-warning {
        color: #d97706 !important;
    }
    .text-warning:hover {
        color: #b45309 !important;
    }
    .text-info {
        color: #0dcaf0 !important;
    }
    .text-info:hover {
        color: #0a9dbd !important;
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