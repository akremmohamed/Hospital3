<?php
/**
 * ======================================================================
 * HMS ERP - SIDEBAR NAVIGATION (NURSING STYLE)
 * Fully compatible with all nursing modules
 * Professional white/light theme, mobile responsive
 * ======================================================================
 */

require_once __DIR__ . '/../../../../config/db_connection.php';
require_once __DIR__ . '/../../../../includes/auth_helper.php';

date_default_timezone_set('Africa/Addis_Ababa');
if ($pdo) $pdo->exec("SET time_zone = '+03:00'");

$user_id = (int)($_SESSION['user_id'] ?? 0);
$branch_id = (int)($_SESSION['branch_id'] ?? 0);
$is_global_admin = ($_SESSION['user_type'] ?? '') === 'super_admin';
$nurse_name = $_SESSION['first_name'] ?? $_SESSION['username'] ?? 'Nurse';
$nurse_specialty = 'Ward Nurse';

// ----------------------------------------------------------------------
// DYNAMIC COUNTERS (Real-time, multi-tenant)
// ----------------------------------------------------------------------
$active_inpatients = 0;
$occupied_beds = 0;
$pending_samples = 0;
$medications_due = 0;
$triage_waiting = 0;

try {
    // 1) Active inpatients (admitted and not discharged)
    $sql = "SELECT COUNT(*) FROM admissions WHERE branch_id = :branch_id AND status = 'admitted' AND discharged_at IS NULL AND deleted_at IS NULL";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':branch_id' => $branch_id]);
    $active_inpatients = (int)$stmt->fetchColumn();

    // 2) Occupied beds (beds with status = 'occupied')
    $sql = "SELECT COUNT(*) FROM beds WHERE branch_id = :branch_id AND status = 'occupied' AND deleted_at IS NULL";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':branch_id' => $branch_id]);
    $occupied_beds = (int)$stmt->fetchColumn();

    // 3) Pending lab collections (lab_order_items with status = 'ordered' or 'sample_collected')
    $sql = "SELECT COUNT(DISTINCT loi.id) 
            FROM lab_order_items loi
            INNER JOIN lab_orders lo ON loi.lab_order_id = lo.id
            WHERE lo.branch_id = :branch_id 
              AND loi.status IN ('ordered', 'sample_collected')
              AND lo.deleted_at IS NULL 
              AND loi.deleted_at IS NULL";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':branch_id' => $branch_id]);
    $pending_samples = (int)$stmt->fetchColumn();

    // 4) Medications due (active prescription items)
    $sql = "SELECT COUNT(DISTINCT pi.id)
            FROM prescription_items pi
            INNER JOIN prescriptions p ON pi.prescription_id = p.id
            WHERE p.branch_id = :branch_id 
              AND p.status IN ('active', 'partial', 'pending_payment')
              AND p.deleted_at IS NULL
              AND pi.deleted_at IS NULL";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':branch_id' => $branch_id]);
    $medications_due = (int)$stmt->fetchColumn();

    // 5) Triage waiting
    $sql = "SELECT COUNT(DISTINCT pv.id)
            FROM patient_visits pv
            LEFT JOIN triage_records tr ON tr.visit_id = pv.id AND tr.deleted_at IS NULL
            WHERE pv.status IN ('checked_in', 'pending', 'in_triage')
              AND pv.deleted_at IS NULL
              AND (tr.id IS NULL OR tr.disposition IS NULL OR tr.disposition = '')
              AND pv.branch_id = :branch_id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':branch_id' => $branch_id]);
    $triage_waiting = (int)$stmt->fetchColumn();

} catch (PDOException $e) {
    error_log('Sidebar counter error: ' . $e->getMessage());
}
?>
<!-- ================================================ -->
<!-- SIDEBAR - NURSING STYLE (White/Light Theme)     -->
<!-- ================================================ -->
<aside class="left-sidebar" id="left-sidebar">

    <nav class="sidebar-nav">
        <ul class="metismenu" id="side-menu">
            <!-- DASHBOARD -->
            <li>
                <a href="dashboard.php" class="waves-effect">
                    <i class="fas fa-tachometer-alt"></i>
                    <span>Dashboard</span>
                </a>
            </li>

            <!-- CLINICAL WORKSPACE GROUP -->
            <li class="menu-title">CLINICAL WORKSPACE</li>
            <li>
                <a href="nursing_triage.php">
                    <i class="fas fa-stethoscope"></i>
                    <span>Patient Triage</span>
                    <?php if ($triage_waiting > 0): ?>
                        <span class="badge bg-warning rounded-pill float-end"><?php echo $triage_waiting; ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <li>
                <a href="ward_nursing_hub.php">
                    <i class="fas fa-bed"></i>
                    <span>Live Bed Map</span>
                    <?php if ($occupied_beds > 0): ?>
                        <span class="badge bg-danger rounded-pill float-end"><?php echo $occupied_beds; ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <li>
                <a href="medication_administration.php">
                    <i class="fas fa-capsules"></i>
                    <span>Medication Administration (MAR)</span>
                    <?php if ($medications_due > 0): ?>
                        <span class="badge bg-warning rounded-pill float-end"><?php echo $medications_due; ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <li>
                <a href="nursing_notes.php">
                    <i class="fas fa-notes-medical"></i>
                    <span>Nursing Progress Notes &amp; IO</span>
                    <?php if ($active_inpatients > 0): ?>
                        <span class="badge bg-info rounded-pill float-end"><?php echo $active_inpatients; ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <li>
                <a href="sample_collection.php">
                    <i class="fas fa-syringe"></i>
                    <span>Lab Sample Collection</span>
                    <?php if ($pending_samples > 0): ?>
                        <span class="badge bg-warning rounded-pill float-end"><?php echo $pending_samples; ?></span>
                    <?php endif; ?>
                </a>
            </li>

            <!-- PATIENT MANAGEMENT -->
            <li class="menu-title">PATIENT MANAGEMENT</li>
            <li>
                <a href="waiting_room_display.php">
                    <i class="fas fa-users"></i>
                    <span>Waiting Room Display</span>
                </a>
            </li>
            <li>
                <a href="inpatient_list.php">
                    <i class="fas fa-hospital-user"></i>
                    <span>Inpatient Roster</span>
                    <?php if ($active_inpatients > 0): ?>
                        <span class="badge bg-primary rounded-pill float-end"><?php echo $active_inpatients; ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <li>
                <a href="discharge_summary.php">
                    <i class="fas fa-file-alt"></i>
                    <span>Discharge Summary</span>
                </a>
            </li>

            <!-- LABORATORY -->
            <li class="menu-title">LABORATORY</li>
            <li>
                <a href="javascript: void(0);" class="has-arrow">
                    <i class="fas fa-flask"></i>
                    <span>Lab Management</span>
                </a>
                <ul class="sub-menu">
                    <li><a href="lab_orders.php">Order Management</a></li>
                    <li><a href="results_entry.php">Results Entry</a></li>
                    <li><a href="sample_collection.php">Sample Collection</a></li>
                </ul>
            </li>

            <!-- REPORTS & ANALYTICS -->
            <li class="menu-title">REPORTS &amp; ANALYTICS</li>
            <li>
                <a href="javascript: void(0);" class="has-arrow">
                    <i class="fas fa-chart-line"></i>
                    <span>Reports</span>
                </a>
                <ul class="sub-menu">
                    <li><a href="nurse_reports.php">Nursing Reports</a></li>
                    <li><a href="clinical_summary.php">Clinical Summary</a></li>
                    <li><a href="patient_statistics.php">Patient Statistics</a></li>
                </ul>
            </li>

            <!-- RECORDS -->
            <li class="menu-title">RECORDS</li>
            <li>
                <a href="medical_records.php">
                    <i class="fas fa-folder-open"></i>
                    <span>Medical Records</span>
                </a>
            </li>
            <li>
                <a href="patient_kyc.php">
                    <i class="fas fa-id-card"></i>
                    <span>Patient KYC</span>
                </a>
            </li>
        </ul>
    </nav>
    <div class="sidebar-footer">
        <p>&copy; 2020 - <?php echo date('Y'); ?><br>HMS ERP</p>
    </div>
</aside>

<style>
    /* ============================================ */
    /* NURSING SIDEBAR - WHITE/LIGHT THEME        */
    /* ============================================ */
    .left-sidebar {
        background: #ffffff;
        width: 260px;
        position: fixed;
        top: 70px;
        left: 0;
        height: calc(100% - 70px);
        z-index: 1020;
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        box-shadow: 2px 0 8px rgba(0, 0, 0, 0.06);
        border-right: 1px solid #e9ecef;
        display: flex;
        flex-direction: column;
        overflow: hidden;
    }
    body.erp-sidebar-collapsed .left-sidebar {
        width: 80px;
    }

    /* Mobile sidebar - hidden by default */
    @media (max-width: 992px) {
        .left-sidebar {
            left: -280px;
            width: 280px;
            box-shadow: 2px 0 20px rgba(0, 0, 0, 0.15);
        }
        body.sidebar-enable .left-sidebar {
            left: 0;
        }
    }

    /* Sidebar overlay for mobile */
    .sidebar-overlay {
        display: none;
        position: fixed;
        top: 70px;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0, 0, 0, 0.4);
        z-index: 1019;
        opacity: 0;
        transition: opacity 0.3s ease;
    }
    .sidebar-overlay.active {
        display: block;
        opacity: 1;
    }
    @media (max-width: 992px) {
        .sidebar-overlay.active {
            display: block;
        }
    }

    /* Profile Section */
    .sidebar-profile {
        padding: 20px 15px 16px;
        text-align: center;
        border-bottom: 1px solid #e9ecef;
        flex-shrink: 0;
        background: #ffffff;
    }
    .avatar-circle {
        width: 56px;
        height: 56px;
        border-radius: 50%;
        background: linear-gradient(135deg, #4361ee, #3a0ca3);
        color: white;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 22px;
        font-weight: 700;
        margin-bottom: 10px;
        box-shadow: 0 4px 12px rgba(67, 97, 238, 0.3);
    }
    .profile-info h5 {
        font-size: 16px;
        font-weight: 600;
        margin: 0 0 2px 0;
        color: #1f2937;
    }
    .profile-info p {
        font-size: 12px;
        color: #6b7280;
        margin: 0;
    }

    .stats-row {
        display: flex;
        justify-content: space-around;
        background: #f8f9fa;
        border-radius: 12px;
        padding: 8px 0;
        margin-top: 12px;
    }
    .stat-item {
        text-align: center;
        padding: 0 6px;
    }
    .stat-number {
        font-size: 18px;
        font-weight: 700;
        color: #1f2937;
        display: block;
        line-height: 1.2;
    }
    .stat-label {
        font-size: 9px;
        text-transform: uppercase;
        font-weight: 600;
        color: #6b7280;
        display: block;
        letter-spacing: 0.5px;
        margin-top: 2px;
    }

    /* Navigation */
    .sidebar-nav {
        flex: 1;
        overflow-y: auto;
        padding: 8px 0;
        background: #ffffff;
    }
    .sidebar-nav::-webkit-scrollbar {
        width: 4px;
    }
    .sidebar-nav::-webkit-scrollbar-track {
        background: transparent;
    }
    .sidebar-nav::-webkit-scrollbar-thumb {
        background: #d1d5db;
        border-radius: 4px;
    }

    .metismenu {
        list-style: none;
        padding: 0;
        margin: 0;
    }
    .metismenu li {
        list-style: none;
    }
    .metismenu li a {
        display: flex;
        align-items: center;
        padding: 10px 20px;
        color: #4b5563;
        font-size: 14px;
        font-weight: 500;
        transition: all 0.2s;
        text-decoration: none;
        position: relative;
        border-left: 3px solid transparent;
        background: transparent;
    }
    .metismenu li a i {
        font-size: 18px;
        min-width: 32px;
        text-align: center;
        color: #6b7280;
        transition: color 0.2s;
    }
    .metismenu li a:hover {
        background: #f3f4f6;
        color: #4361ee;
    }
    .metismenu li a:hover i {
        color: #4361ee;
    }
    .metismenu li.active > a {
        background: #e0e7ff;
        color: #4361ee;
        border-left-color: #4361ee;
    }
    .metismenu li.active > a i {
        color: #4361ee;
    }

    .metismenu .badge {
        margin-left: auto;
        font-size: 10px;
        padding: 3px 8px;
        border-radius: 20px;
        font-weight: 600;
    }
    .badge.bg-warning { background: #f9c74f; color: #1f2937; }
    .badge.bg-danger { background: #ef476f; color: white; }
    .badge.bg-info { background: #4cc9f0; color: white; }
    .badge.bg-primary { background: #4361ee; color: white; }
    .badge.bg-success { background: #06d6a0; color: white; }

    .menu-title {
        padding: 14px 20px 6px;
        font-size: 10px;
        font-weight: 700;
        color: #9ca3af;
        text-transform: uppercase;
        letter-spacing: 0.8px;
    }

    .sub-menu {
        padding-left: 0;
        list-style: none;
        display: none;
        background: #fafafa;
    }
    .sub-menu li a {
        padding: 8px 20px 8px 52px;
        font-size: 13px;
        font-weight: 400;
        border-left: none;
        background: transparent;
    }
    .sub-menu li a i {
        font-size: 14px;
        min-width: 28px;
        color: #6b7280;
    }
    .sub-menu li a:hover {
        background: #f0f0f0;
        color: #4361ee;
    }
    .sub-menu li.active > a {
        background: #e0e7ff;
        color: #4361ee;
    }
    .sub-menu li.active > a i {
        color: #4361ee;
    }

    .has-arrow {
        position: relative;
        cursor: pointer;
    }
    .has-arrow::after {
        content: '\f107';
        font-family: 'Font Awesome 6 Free';
        font-weight: 900;
        position: absolute;
        right: 20px;
        top: 50%;
        transform: translateY(-50%);
        transition: transform 0.3s;
        font-size: 12px;
        color: #9ca3af;
    }
    .has-arrow[aria-expanded="true"]::after {
        transform: translateY(-50%) rotate(180deg);
    }

    /* Footer */
    .sidebar-footer {
        padding: 12px;
        border-top: 1px solid #e9ecef;
        font-size: 10px;
        flex-shrink: 0;
        background: #ffffff;
        text-align: center;
        color: #6b7280;
    }
    .sidebar-footer p {
        margin: 0;
        line-height: 1.5;
    }

    /* ============================================ */
    /* COLLAPSED STATE */
    /* ============================================ */
    body.erp-sidebar-collapsed .left-sidebar {
        width: 80px;
    }
    body.erp-sidebar-collapsed .sidebar-profile .profile-info,
    body.erp-sidebar-collapsed .sidebar-profile .stats-row,
    body.erp-sidebar-collapsed .metismenu li a span:not(.badge),
    body.erp-sidebar-collapsed .menu-title,
    body.erp-sidebar-collapsed .sidebar-footer,
    body.erp-sidebar-collapsed .metismenu .badge,
    body.erp-sidebar-collapsed .has-arrow::after {
        display: none !important;
    }
    body.erp-sidebar-collapsed .metismenu li a {
        justify-content: center;
        padding: 12px 0;
        border-left: none;
    }
    body.erp-sidebar-collapsed .metismenu li a i {
        font-size: 22px;
        margin: 0;
    }
    body.erp-sidebar-collapsed .sub-menu {
        display: none !important;
    }
    body.erp-sidebar-collapsed .avatar-circle {
        width: 40px;
        height: 40px;
        font-size: 16px;
    }
    body.erp-sidebar-collapsed .sidebar-profile {
        padding: 12px 0;
    }

    /* ============================================ */
    /* RESPONSIVE */
    /* ============================================ */
    @media (max-width: 992px) {
        .left-sidebar {
            left: -280px;
            width: 280px;
            box-shadow: 2px 0 20px rgba(0, 0, 0, 0.15);
        }
        body.sidebar-enable .left-sidebar {
            left: 0;
        }
        body.sidebar-enable .sidebar-overlay {
            display: block;
            opacity: 1;
        }
        /* Reset collapsed state on mobile */
        body.erp-sidebar-collapsed .left-sidebar {
            width: 280px;
        }
        body.erp-sidebar-collapsed .sidebar-profile .profile-info,
        body.erp-sidebar-collapsed .sidebar-profile .stats-row,
        body.erp-sidebar-collapsed .metismenu li a span:not(.badge),
        body.erp-sidebar-collapsed .menu-title,
        body.erp-sidebar-collapsed .sidebar-footer,
        body.erp-sidebar-collapsed .has-arrow::after {
            display: block !important;
        }
        body.erp-sidebar-collapsed .metismenu li a {
            justify-content: flex-start;
            padding: 10px 20px;
        }
        body.erp-sidebar-collapsed .metismenu li a i {
            font-size: 18px;
            min-width: 32px;
        }
        body.erp-sidebar-collapsed .avatar-circle {
            width: 56px;
            height: 56px;
            font-size: 22px;
        }
        body.erp-sidebar-collapsed .sidebar-profile {
            padding: 20px 15px 16px;
        }
    }

    @media (max-width: 480px) {
        .left-sidebar {
            width: 260px;
            left: -260px;
        }
        body.sidebar-enable .left-sidebar {
            left: 0;
        }
        body.erp-sidebar-collapsed .left-sidebar {
            width: 260px;
        }
        .sidebar-profile {
            padding: 16px 12px 12px;
        }
        .avatar-circle {
            width: 48px;
            height: 48px;
            font-size: 18px;
        }
        .profile-info h5 {
            font-size: 14px;
        }
        .stat-number {
            font-size: 15px;
        }
        .metismenu li a {
            padding: 8px 16px;
            font-size: 13px;
        }
        .metismenu li a i {
            font-size: 16px;
            min-width: 28px;
        }
        .sub-menu li a {
            padding: 6px 16px 6px 44px;
            font-size: 12px;
        }
        .menu-title {
            padding: 10px 16px 4px;
            font-size: 9px;
        }
    }
</style>

<script>
(function() {
    'use strict';

    // MetisMenu initialization
    if (typeof MetisMenu !== 'undefined') {
        var sideMenu = document.getElementById('side-menu');
        if (sideMenu && !sideMenu.hasAttribute('data-metismenu-initialized')) {
            new MetisMenu(sideMenu, {
                toggle: true,
                triggerElement: 'a.has-arrow',
                parentTrigger: 'li',
                subMenu: 'ul'
            });
            sideMenu.setAttribute('data-metismenu-initialized', 'true');
        }
    }

    // Desktop sidebar collapse
    function setSidebarCollapsed(collapsed) {
        if (collapsed) {
            document.body.classList.add('erp-sidebar-collapsed');
            localStorage.setItem('erp_sidebar_collapsed', 'true');
        } else {
            document.body.classList.remove('erp-sidebar-collapsed');
            localStorage.setItem('erp_sidebar_collapsed', 'false');
        }
        window.dispatchEvent(new Event('resize'));
    }

    var desktopToggle = document.getElementById('desktopSidebarToggle');
    if (desktopToggle) {
        desktopToggle.addEventListener('click', function(e) {
            e.preventDefault();
            var isCollapsed = document.body.classList.contains('erp-sidebar-collapsed');
            setSidebarCollapsed(!isCollapsed);
        });
    }

    // Restore sidebar state
    if (window.innerWidth > 992) {
        var saved = localStorage.getItem('erp_sidebar_collapsed');
        if (saved === 'true') {
            document.body.classList.add('erp-sidebar-collapsed');
        }
    }

    // Active menu highlighting
    function setActiveMenu() {
        var currentPath = window.location.pathname.split('/').pop();
        if (currentPath === '' || currentPath === 'index.php') currentPath = 'dashboard.php';
        
        var allLinks = document.querySelectorAll('#side-menu a:not(.has-arrow)');
        allLinks.forEach(function(link) {
            var href = link.getAttribute('href');
            if (href && href === currentPath) {
                link.classList.add('active');
                var parentLi = link.closest('li');
                if (parentLi && parentLi.parentElement && parentLi.parentElement.classList.contains('sub-menu')) {
                    var parentAnchor = parentLi.parentElement.previousElementSibling;
                    if (parentAnchor && parentAnchor.classList.contains('has-arrow')) {
                        parentAnchor.classList.add('active');
                        parentAnchor.setAttribute('aria-expanded', 'true');
                        var subMenu = parentAnchor.nextElementSibling;
                        if (subMenu && subMenu.classList.contains('sub-menu')) {
                            subMenu.style.display = 'block';
                        }
                    }
                }
            } else {
                link.classList.remove('active');
            }
        });
    }

    // Mobile toggle - handled by nav.php but we keep it here for redundancy
    var mobileToggle = document.getElementById('mobileMenuToggle');
    if (mobileToggle) {
        mobileToggle.addEventListener('click', function(e) {
            e.preventDefault();
            document.body.classList.toggle('sidebar-enable');
            document.body.style.overflow = document.body.classList.contains('sidebar-enable') ? 'hidden' : '';
        });
    }

    document.addEventListener('DOMContentLoaded', setActiveMenu);
    console.log('✅ Sidebar Loaded - white/light theme, mobile ready');
})();
</script>