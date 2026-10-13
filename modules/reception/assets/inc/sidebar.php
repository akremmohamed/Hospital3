<?php
/**
 * Sidebar Menu - HMS ERP System
 * Super Admin Style with Dashboard v15.2 Compatibility
 * 
 * @package HMS-ERP
 * @version 3.1
 */

// Use global PDO connection
global $pdo;

// Initialize variables
$live_queue_count = 0;
$emergency_count = 0;
$critical_alerts_count = 0;
$live_inpatients = 0;
$today_visits = 0;
$pending_card_fees = 0;

$branch_id = (int)($_SESSION['branch_id'] ?? 0);
$user_type = $_SESSION['user_type'] ?? '';
$user_id = (int)($_SESSION['user_id'] ?? 0);
$is_global_admin = ($user_type === 'super_admin');

// Load user permissions dynamically from RBAC system
$user_permissions = [];

if (isset($pdo) && $pdo instanceof PDO && $user_id > 0) {
    try {
        $permStmt = $pdo->prepare("
            SELECT DISTINCT sp.permission_key
            FROM users u
            INNER JOIN role_permissions rp ON u.user_type = rp.role_name
            INNER JOIN system_permissions sp ON rp.permission_id = sp.id
            WHERE u.id = :user_id
                AND u.is_active = 1
                AND u.deleted_at IS NULL
                AND rp.deleted_at IS NULL
                AND sp.deleted_at IS NULL
        ");
        $permStmt->execute([':user_id' => $user_id]);
        $user_permissions = $permStmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) {
        error_log("Permissions query failed: " . $e->getMessage());
        $user_permissions = [];
    }
}

// Helper function to check if user has specific permission
if (!function_exists('sidebar_has_permission')) {
    function sidebar_has_permission($permission_key, $user_permissions, $is_global_admin = false) {
        if ($is_global_admin) return true;
        return in_array($permission_key, $user_permissions);
    }
}

// Helper function to check if user has any permission from a list
if (!function_exists('sidebar_has_any_permission')) {
    function sidebar_has_any_permission($permission_keys, $user_permissions, $is_global_admin = false) {
        if ($is_global_admin) return true;
        foreach ($permission_keys as $key) {
            if (in_array($key, $user_permissions)) {
                return true;
            }
        }
        return false;
    }
}

// Fetch dynamic counts for badges
if (isset($pdo) && $pdo instanceof PDO) {
    // Global metrics for Super Admin
    if ($is_global_admin) {
        try {
            // Critical lab results
            $sql = "SELECT COUNT(*) FROM lab_test_results WHERE is_critical = 1 AND DATE(created_at) = CURDATE() AND deleted_at IS NULL";
            $stmt = $pdo->prepare($sql);
            $stmt->execute();
            $critical_alerts_count = (int)$stmt->fetchColumn();

            // Active admissions
            $sql = "SELECT COUNT(*) FROM admissions WHERE status = 'admitted' AND deleted_at IS NULL";
            $stmt = $pdo->prepare($sql);
            $stmt->execute();
            $live_inpatients = (int)$stmt->fetchColumn();

            // Today's visits
            $sql = "SELECT COUNT(*) FROM patient_visits WHERE DATE(created_at) = CURDATE() AND deleted_at IS NULL";
            $stmt = $pdo->prepare($sql);
            $stmt->execute();
            $today_visits = (int)$stmt->fetchColumn();

            // Pending card fees
            $sql = "SELECT COUNT(DISTINCT i.id) FROM invoices i 
                    INNER JOIN patient_visits pv ON pv.id = i.visit_id 
                    WHERE i.payment_status = 'unpaid' AND i.deleted_at IS NULL AND pv.visit_type != 'emergency'";
            $stmt = $pdo->prepare($sql);
            $stmt->execute();
            $pending_card_fees = (int)$stmt->fetchColumn();

        } catch (PDOException $e) {
            error_log("Sidebar global metrics failed: " . $e->getMessage());
        }
    }

    // Live queue count (checked-in patients)
    $can_view_queue = sidebar_has_permission('view_live_queue', $user_permissions, $is_global_admin);
    if ($can_view_queue) {
        try {
            if ($branch_id > 0 && !$is_global_admin) {
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) AS c 
                    FROM appointments 
                    WHERE appointment_date = CURDATE() 
                        AND status IN ('checked_in', 'in_progress') 
                        AND deleted_at IS NULL
                        AND branch_id = :branch_id
                ");
                $stmt->execute([':branch_id' => $branch_id]);
            } else {
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) AS c 
                    FROM appointments 
                    WHERE appointment_date = CURDATE() 
                        AND status IN ('checked_in', 'in_progress') 
                        AND deleted_at IS NULL
                ");
                $stmt->execute();
            }
            $live_queue_count = (int)$stmt->fetchColumn();
        } catch (PDOException $e) {
            error_log("Sidebar queue count failed: " . $e->getMessage());
        }
    }

    // Emergency count from triage_records priority 'red'
    $can_view_emergency = sidebar_has_permission('view_emergency_alerts', $user_permissions, $is_global_admin);
    if ($can_view_emergency) {
        try {
            if ($branch_id > 0 && !$is_global_admin) {
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) AS c 
                    FROM triage_records tr
                    INNER JOIN patient_visits pv ON tr.visit_id = pv.id
                    WHERE tr.priority = 'red' 
                        AND DATE(tr.triage_time) = CURDATE() 
                        AND tr.deleted_at IS NULL
                        AND pv.branch_id = :branch_id
                ");
                $stmt->execute([':branch_id' => $branch_id]);
            } else {
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) AS c 
                    FROM triage_records 
                    WHERE priority = 'red' 
                        AND DATE(triage_time) = CURDATE() 
                        AND deleted_at IS NULL
                ");
                $stmt->execute();
            }
            $emergency_count = (int)$stmt->fetchColumn();
        } catch (PDOException $e) {
            error_log("Sidebar emergency count failed: " . $e->getMessage());
        }
    }
}

// ============================================
// PERMISSION-BASED MENU VISIBILITY
// ============================================

// Dashboard - Always visible
$show_dashboard = true;

// Patient Management Module
$show_patient_management = sidebar_has_any_permission([
    'view_patient_list',
    'register_patient',
    'edit_patient',
    'view_patient_kyc',
    'manage_family_linkage'
], $user_permissions, $is_global_admin);

$show_patient_directory = sidebar_has_permission('view_patient_list', $user_permissions, $is_global_admin);
$show_family_linkage = sidebar_has_permission('manage_family_linkage', $user_permissions, $is_global_admin);
$show_kyc_documents = sidebar_has_permission('view_patient_kyc', $user_permissions, $is_global_admin);

// Appointment Desk Module
$show_appointment_desk = sidebar_has_any_permission([
    'schedule_appointment',
    'cancel_appointment',
    'view_appointments',
    'send_reminders'
], $user_permissions, $is_global_admin);

$show_calendar_view = sidebar_has_permission('view_appointments', $user_permissions, $is_global_admin);
$show_send_reminders = sidebar_has_permission('send_reminders', $user_permissions, $is_global_admin);

// Triage & Flow Control Module
$show_triage_flow = sidebar_has_any_permission([
    'view_triage_queue',
    'perform_triage',
    'view_live_queue',
    'view_emergency_alerts'
], $user_permissions, $is_global_admin);

$show_waiting_room = sidebar_has_permission('view_waiting_room', $user_permissions, $is_global_admin);
$show_live_queue = sidebar_has_permission('view_live_queue', $user_permissions, $is_global_admin);
$show_emergency_alerts = sidebar_has_permission('view_emergency_alerts', $user_permissions, $is_global_admin);

// Billing & Cashier Module
$show_billing_cashier = sidebar_has_any_permission([
    'view_invoices',
    'create_invoice',
    'process_payment',
    'view_insurance_claims',
    'generate_report'
], $user_permissions, $is_global_admin);

$show_service_billing = sidebar_has_permission('create_invoice', $user_permissions, $is_global_admin);
$show_insurance_verification = sidebar_has_permission('view_insurance_claims', $user_permissions, $is_global_admin);
$show_daily_cash_close = sidebar_has_permission('generate_report', $user_permissions, $is_global_admin);

// In-Patient Admissions Module
$show_inpatient_admissions = sidebar_has_any_permission([
    'view_admissions',
    'manage_admissions',
    'assign_bed'
], $user_permissions, $is_global_admin);

$show_visitor_pass = sidebar_has_permission('manage_visitors', $user_permissions, $is_global_admin);
$show_active_admissions = sidebar_has_permission('view_admissions', $user_permissions, $is_global_admin);

// Emergency & Logs Module
$show_emergency_logs = sidebar_has_any_permission([
    'view_emergency_logs',
    'manage_birth_records',
    'manage_death_records'
], $user_permissions, $is_global_admin);

$show_birth_death_registry = sidebar_has_permission('manage_birth_records', $user_permissions, $is_global_admin) ||
                             sidebar_has_permission('manage_death_records', $user_permissions, $is_global_admin);

// Reports Module
$show_reports = sidebar_has_any_permission([
    'view_reports',
    'export_data',
    'view_audit_logs'
], $user_permissions, $is_global_admin);

// Administration Module
$show_administration = sidebar_has_any_permission([
    'manage_users',
    'manage_roles',
    'manage_branches',
    'manage_departments',
    'view_audit_logs'
], $user_permissions, $is_global_admin);

// Super Admin Only Modules
$show_super_admin_modules = $is_global_admin;
?>

<style>
/* ============================================
   SIDEBAR STYLES - SUPER ADMIN STYLE
   ============================================ */
    
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

.left-side-menu {
    position: fixed;
    top: 70px;
    left: 0;
    bottom: 0;
    width: 260px;
    background: #ffffff;
    border-right: 1px solid #e5e7eb;
    z-index: 100;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    overflow-y: auto;
    overflow-x: hidden;
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
}

.left-side-menu::-webkit-scrollbar {
    width: 4px;
}

.left-side-menu::-webkit-scrollbar-track {
    background: #f1f1f1;
}

.left-side-menu::-webkit-scrollbar-thumb {
    background: #cbd5e1;
    border-radius: 4px;
}

.left-side-menu::-webkit-scrollbar-thumb:hover {
    background: #94a3b8;
}

.slimscroll-menu {
    padding: 16px 0 20px 0;
}

/* Brand / Logo Area */
.sidebar-brand {
    padding: 1.2rem 1rem;
    text-align: center;
    border-bottom: 1px solid #e5e7eb;
}

.sidebar-brand .logo {
    font-size: 1.25rem;
    font-weight: 700;
    text-decoration: none;
    color: #1e293b;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
}

.sidebar-brand .logo i {
    color: #4361ee;
}

.sidebar-brand .logo .logo-text {
    font-size: 14px;
}

.menu-title {
    font-size: 0.7rem;
    font-weight: 700;
    letter-spacing: 0.5px;
    text-transform: uppercase;
    color: #94a3b8;
    padding: 12px 20px 8px 20px;
    margin: 0;
    white-space: nowrap;
}

#side-menu {
    list-style: none;
    margin: 0;
    padding: 0;
}

#side-menu > li {
    position: relative;
    margin: 0;
    padding: 0;
    list-style: none;
}

#side-menu > li > a {
    display: flex;
    align-items: center;
    padding: 10px 20px;
    color: #334155;
    font-size: 0.9rem;
    font-weight: 500;
    text-decoration: none;
    border-left: 3px solid transparent;
    transition: all 0.2s ease;
    gap: 12px;
    white-space: nowrap;
}

#side-menu > li > a i {
    width: 20px;
    font-size: 1rem;
    color: #64748b;
    text-align: center;
    transition: all 0.2s ease;
    flex-shrink: 0;
}

#side-menu > li > a span {
    flex: 1;
}

#side-menu > li > a .menu-arrow {
    float: right;
    transition: transform 0.2s ease;
    flex-shrink: 0;
}

#side-menu > li > a .menu-arrow:before {
    content: "\f107";
    font-family: "Font Awesome 6 Free";
    font-weight: 900;
    font-size: 0.75rem;
}

#side-menu > li.open > a .menu-arrow {
    transform: rotate(180deg);
}

#side-menu > li > a:hover {
    background: #f1f5f9;
    color: #4361ee;
    border-left-color: #4361ee;
}

#side-menu > li > a:hover i {
    color: #4361ee;
}

#side-menu > li.active > a,
#side-menu > li > a.active {
    background: linear-gradient(90deg, rgba(67, 97, 238, 0.08), rgba(67, 97, 238, 0.02));
    color: #4361ee;
    border-left-color: #4361ee;
    font-weight: 600;
}

#side-menu > li.active > a i,
#side-menu > li > a.active i {
    color: #4361ee;
}

.nav-second-level {
    display: none;
    list-style: none;
    margin: 0;
    padding: 4px 0;
    background: #f8fafc;
}

#side-menu > li.open > .nav-second-level {
    display: block;
}

.nav-second-level li {
    list-style: none;
    margin: 0;
    padding: 0;
}

.nav-second-level li a {
    display: flex;
    align-items: center;
    padding: 8px 20px 8px 52px;
    color: #475569;
    font-size: 0.85rem;
    font-weight: 500;
    text-decoration: none;
    gap: 10px;
    transition: all 0.2s ease;
    white-space: nowrap;
}

.nav-second-level li a i {
    width: 16px;
    font-size: 0.75rem;
    color: #94a3b8;
    flex-shrink: 0;
}

.nav-second-level li a:hover {
    background: #f1f5f9;
    color: #4361ee;
    padding-left: 56px;
}

.nav-second-level li a:hover i {
    color: #4361ee;
}

.nav-second-level li a.active {
    color: #4361ee;
    background: rgba(67, 97, 238, 0.05);
    font-weight: 600;
}

.nav-second-level li a.active i {
    color: #4361ee;
}

.badge {
    display: inline-block;
    padding: 2px 8px;
    font-size: 0.65rem;
    font-weight: 600;
    border-radius: 20px;
    margin-left: 8px;
    flex-shrink: 0;
}

.badge-info {
    background: #4361ee;
    color: white;
}

.badge-danger {
    background: #ef476f;
    color: white;
    animation: pulse 1.5s infinite;
}

.badge-warning {
    background: #f59e0b;
    color: white;
}

.badge-success {
    background: #06d6a0;
    color: white;
}

.badge-critical {
    background: #dc2626;
    color: white;
    animation: pulse 1.5s infinite;
}

@keyframes pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.7; }
}

.nav-second-level hr {
    margin: 8px 20px;
    border: none;
    border-top: 1px solid #e2e8f0;
}

/* ============================================
   COLLAPSED SIDEBAR STATE
   ============================================ */
body.erp-sidebar-collapsed .left-side-menu {
    width: 80px;
}

body.erp-sidebar-collapsed .left-side-menu .menu-title {
    opacity: 0;
    visibility: hidden;
    padding: 12px 0 8px 0;
    text-align: center;
    font-size: 0;
}

body.erp-sidebar-collapsed .left-side-menu #side-menu > li > a {
    justify-content: center;
    padding: 12px;
}

body.erp-sidebar-collapsed .left-side-menu #side-menu > li > a span:not(.badge) {
    display: none;
}

body.erp-sidebar-collapsed .left-side-menu #side-menu > li > a .menu-arrow {
    display: none;
}

body.erp-sidebar-collapsed .left-side-menu #side-menu > li > a .badge {
    position: absolute;
    top: 4px;
    right: 8px;
    display: inline-block;
}

body.erp-sidebar-collapsed .left-side-menu .nav-second-level {
    display: none !important;
}

body.erp-sidebar-collapsed .left-side-menu #side-menu > li > a {
    position: relative;
}

body.erp-sidebar-collapsed .left-side-menu #side-menu > li > a:hover::after {
    content: attr(data-tooltip);
    position: absolute;
    left: 100%;
    top: 50%;
    transform: translateY(-50%);
    background: #1e293b;
    color: white;
    padding: 6px 12px;
    border-radius: 8px;
    font-size: 12px;
    white-space: nowrap;
    z-index: 1000;
    margin-left: 8px;
    box-shadow: 0 4px 6px rgba(0,0,0,0.1);
}

body.erp-sidebar-collapsed .content-page {
    margin-left: 80px;
}

/* ============================================
   MOBILE SIDEBAR
   ============================================ */
@media (max-width: 768px) {
    .left-side-menu {
        transform: translateX(-100%);
        transition: transform 0.3s ease;
        z-index: 1050;
        width: 280px;
    }
    
    body.sidebar-enable {
        overflow: hidden;
    }
    
    body.sidebar-enable .left-side-menu {
        transform: translateX(0);
        box-shadow: 2px 0 10px rgba(0,0,0,0.1);
    }
    
    body.sidebar-enable::before {
        content: '';
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0,0,0,0.5);
        z-index: 1040;
    }
    
    .content-page {
        margin-left: 0 !important;
    }
    
    body.erp-sidebar-collapsed .content-page {
        margin-left: 0;
    }
    
    /* Ensure sidebar brand shows on mobile */
    .sidebar-brand .logo-text {
        display: inline-block !important;
    }
}

.content-page {
    margin-left: 260px;
    margin-top: 70px;
    padding: 24px 32px;
    transition: margin-left 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    min-height: calc(100vh - 70px);
}

/* Mobile toggle button */
.sidebar-toggler {
    position: fixed;
    bottom: 1rem;
    left: 0.5rem;
    z-index: 1100;
    display: none;
}

@media (max-width: 768px) {
    .sidebar-toggler {
        display: block;
    }
}
</style>

<div class="left-side-menu">
    <div class="slimscroll-menu">
        <div id="sidebar-menu">
            <ul class="metismenu" id="side-menu">
                <?php if ($is_global_admin): ?>
                <li class="menu-title">Enterprise Core</li>
                <?php else: ?>
                <li class="menu-title">Navigation</li>
                <?php endif; ?>
                
                <!-- Dashboard - Always visible -->
                <li class="nav-item">
                    <a href="dashboard.php" data-tooltip="Dashboard" <?php echo basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'class="active"' : ''; ?>>
                        <i class="fas fa-tachometer-alt"></i>
                        <span><?php echo $is_global_admin ? 'Main Control Panel' : 'Dashboard'; ?></span>
                    </a>
                </li>

                <?php if ($is_global_admin): ?>
                <!-- Super Admin Enterprise Modules -->
                <li class="menu-title">Enterprise Core Dashboards</li>
                <li>
                    <a href="executive_kpi_dashboard.php" data-tooltip="Executive KPI Dashboard" <?php echo basename($_SERVER['PHP_SELF']) == 'executive_kpi_dashboard.php' ? 'class="active"' : ''; ?>>
                        <i class="fas fa-chart-line"></i>
                        <span>Executive KPI Dashboard</span>
                    </a>
                </li>
                <li>
                    <a href="branch_management.php" data-tooltip="Multi-Branch Operations" <?php echo basename($_SERVER['PHP_SELF']) == 'branch_management.php' ? 'class="active"' : ''; ?>>
                        <i class="fas fa-building"></i>
                        <span>Multi-Branch Operations</span>
                    </a>
                </li>
                <li>
                    <a href="financial_tariff_master.php" data-tooltip="Global Financial Master" <?php echo basename($_SERVER['PHP_SELF']) == 'financial_tariff_master.php' ? 'class="active"' : ''; ?>>
                        <i class="fas fa-coins"></i>
                        <span>Global Financial Master</span>
                    </a>
                </li>

                <!-- Governance & Security -->
                <li class="menu-title">Governance & Security</li>
                <li>
                    <a href="user_permission_rules.php" data-tooltip="Dynamic Access Control (RBAC)" <?php echo basename($_SERVER['PHP_SELF']) == 'user_permission_rules.php' ? 'class="active"' : ''; ?>>
                        <i class="fas fa-shield-alt"></i>
                        <span>Dynamic Access Control (RBAC)</span>
                    </a>
                </li>
                <li>
                    <a href="system_maintenance.php" data-tooltip="System Maintenance Mode" <?php echo basename($_SERVER['PHP_SELF']) == 'system_maintenance.php' ? 'class="active"' : ''; ?>>
                        <i class="fas fa-wrench"></i>
                        <span>System Maintenance</span>
                    </a>
                </li>

                <!-- HR & Capacity Planning -->
                <li class="menu-title">HR & Capacity Planning</li>
                <li>
                    <a href="hr_roster_management.php" data-tooltip="HR Profile Provisioning" <?php echo basename($_SERVER['PHP_SELF']) == 'hr_roster_management.php' ? 'class="active"' : ''; ?>>
                        <i class="fas fa-users"></i>
                        <span>HR Profile Provisioning</span>
                    </a>
                </li>
                <li>
                    <a href="clinical_infrastructure.php" data-tooltip="Clinical Infrastructure" <?php echo basename($_SERVER['PHP_SELF']) == 'clinical_infrastructure.php' ? 'class="active"' : ''; ?>>
                        <i class="fas fa-calendar-alt"></i>
                        <span>Clinical Infrastructure</span>
                    </a>
                </li>
                <li>
                    <a href="vital_legal_registries.php" data-tooltip="Vital & Legal Registries" <?php echo basename($_SERVER['PHP_SELF']) == 'vital_legal_registries.php' ? 'class="active"' : ''; ?>>
                        <i class="fas fa-book"></i>
                        <span>Birth & Legal Death Registries</span>
                    </a>
                </li>

                <!-- Clinical Workflow -->
                <li class="menu-title">Clinical Workflow</li>
                <li>
                    <a href="clinical_template_builder.php" data-tooltip="Clinical Template Builder" <?php echo basename($_SERVER['PHP_SELF']) == 'clinical_template_builder.php' ? 'class="active"' : ''; ?>>
                        <i class="fas fa-file-alt"></i>
                        <span>Clinical Template Builder</span>
                    </a>
                </li>
                <li>
                    <a href="referral_transfer_master.php" data-tooltip="Referral & Transfer Master" <?php echo basename($_SERVER['PHP_SELF']) == 'referral_transfer_master.php' ? 'class="active"' : ''; ?>>
                        <i class="fas fa-exchange-alt"></i>
                        <span>Referral & Transfer Master</span>
                    </a>
                </li>
                <li>
                    <a href="equipment_asset_manager.php" data-tooltip="Biomedical Equipment" <?php echo basename($_SERVER['PHP_SELF']) == 'equipment_asset_manager.php' ? 'class="active"' : ''; ?>>
                        <i class="fas fa-wrench"></i>
                        <span>Biomedical Equipment</span>
                    </a>
                </li>

                <!-- Supply Chain & Audits -->
                <li class="menu-title">Supply Chain & Audits</li>
                <li>
                    <a href="pharmacy_supply_master.php" data-tooltip="Centralized Procurement" <?php echo basename($_SERVER['PHP_SELF']) == 'pharmacy_supply_master.php' ? 'class="active"' : ''; ?>>
                        <i class="fas fa-shopping-cart"></i>
                        <span>Centralized Procurement</span>
                    </a>
                </li>
                <li>
                    <a href="pharmacy_inventory_audit.php" data-tooltip="Pharmacy Stock & POS Audit" <?php echo basename($_SERVER['PHP_SELF']) == 'pharmacy_inventory_audit.php' ? 'class="active"' : ''; ?>>
                        <i class="fas fa-pills"></i>
                        <span>Pharmacy Stock & POS Audit</span>
                    </a>
                </li>
                <li>
                    <a href="insurance_claims_desk.php" data-tooltip="Advanced Insurance Claims" <?php echo basename($_SERVER['PHP_SELF']) == 'insurance_claims_desk.php' ? 'class="active"' : ''; ?>>
                        <i class="fas fa-file-invoice"></i>
                        <span>Advanced Insurance Claims</span>
                    </a>
                </li>
                <?php endif; ?>

                <!-- Standard Modules (for all users) -->
                <?php if (!$is_global_admin): ?>
                <!-- Patient Management -->
                <?php if ($show_patient_management): ?>
                <li>
                    <a href="javascript:void(0);" data-tooltip="Patient Management">
                        <i class="fas fa-user-plus"></i>
                        <span>Patient Management</span>
                        <span class="menu-arrow"></span>
                    </a>
                    <ul class="nav-second-level">
                        <?php if ($show_patient_directory): ?>
                        <li><a href="patient_directory.php" <?php echo basename($_SERVER['PHP_SELF']) == 'patient_directory.php' ? 'class="active"' : ''; ?>><i class="fas fa-search"></i> Patient Search/Directory</a></li>
                        <?php endif; ?>
                        <?php if ($show_family_linkage): ?>
                        <li><a href="family_linkage.php" <?php echo basename($_SERVER['PHP_SELF']) == 'family_linkage.php' ? 'class="active"' : ''; ?>><i class="fas fa-users"></i> Family/Group Linkage</a></li>
                        <?php endif; ?>
                        <?php if ($show_kyc_documents): ?>
                        <li><a href="kyc_documents.php" <?php echo basename($_SERVER['PHP_SELF']) == 'kyc_documents.php' ? 'class="active"' : ''; ?>><i class="fas fa-id-card"></i> KYC & Documents</a></li>
                        <?php endif; ?>
                    </ul>
                </li>
                <?php endif; ?>

                <!-- Appointment Desk -->
                <?php if ($show_appointment_desk): ?>
                <li>
                    <a href="javascript:void(0);">
                        <i class="fas fa-calendar-check"></i>
                        <span>Appointment Desk</span>
                        <span class="menu-arrow"></span>
                    </a>
                    <ul class="nav-second-level">
                        <?php if ($show_calendar_view): ?>
                        <li><a href="calendar_view.php" <?php echo basename($_SERVER['PHP_SELF']) == 'calendar_view.php' ? 'class="active"' : ''; ?>><i class="fas fa-calendar-alt"></i> Calendar View</a></li>
                        <?php endif; ?>
                        <?php if ($show_send_reminders): ?>
                        <li><a href="notifications_manager.php" <?php echo basename($_SERVER['PHP_SELF']) == 'notifications_manager.php' ? 'class="active"' : ''; ?>><i class="fas fa-envelope"></i> SMS/Email Reminders</a></li>
                        <?php endif; ?>
                    </ul>
                </li>
                <?php endif; ?>

                <!-- Triage & Flow Control -->
                <?php if ($show_triage_flow): ?>
                <li>
                    <a href="javascript:void(0);">
                        <i class="fas fa-heartbeat"></i>
                        <span>Triage & Flow Control</span>
                        <span class="menu-arrow"></span>
                    </a>
                    <ul class="nav-second-level">
                        <?php if ($show_live_queue): ?>
                        <li><a href="live_queue.php" <?php echo basename($_SERVER['PHP_SELF']) == 'live_queue.php' ? 'class="active"' : ''; ?>>
                            <i class="fas fa-users"></i> Live Queue 
                            <?php if ($live_queue_count > 0): ?>
                                <span class="badge badge-info"><?php echo (int)$live_queue_count; ?></span>
                            <?php endif; ?>
                        </a></li>
                        <?php endif; ?>
                        <?php if ($show_emergency_alerts): ?>
                        <li><a href="emergency_alerts.php" <?php echo basename($_SERVER['PHP_SELF']) == 'emergency_alerts.php' ? 'class="active"' : ''; ?>>
                            <i class="fas fa-ambulance"></i> Emergency Alerts
                            <?php if ($emergency_count > 0): ?>
                                <span class="badge badge-danger"><?php echo (int)$emergency_count; ?></span>
                            <?php endif; ?>
                        </a></li>
                        <?php endif; ?>
                    </ul>
                </li>
                <?php endif; ?>

                <!-- Billing & Cashier -->
                <?php if ($show_billing_cashier): ?>
                <li>
                    <a href="javascript:void(0);">
                        <i class="fas fa-receipt"></i>
                        <span>Billing & Cashier</span>
                        <span class="menu-arrow"></span>
                    </a>
                    <ul class="nav-second-level">
                        <?php if ($show_service_billing): ?>
                        <li><a href="service_billing.php" <?php echo basename($_SERVER['PHP_SELF']) == 'service_billing.php' ? 'class="active"' : ''; ?>><i class="fas fa-shopping-cart"></i> Service Billing</a></li>
                        <?php endif; ?>
                        <?php if ($show_insurance_verification): ?>
                        <li><a href="insurance_verification.php" <?php echo basename($_SERVER['PHP_SELF']) == 'insurance_verification.php' ? 'class="active"' : ''; ?>><i class="fas fa-shield-alt"></i> Insurance Verification</a></li>
                        <?php endif; ?>
                        <?php if ($show_daily_cash_close): ?>
                        <li><a href="daily_cash_close.php" <?php echo basename($_SERVER['PHP_SELF']) == 'daily_cash_close.php' ? 'class="active"' : ''; ?>><i class="fas fa-cash-register"></i> Daily Cash Close</a></li>
                        <?php endif; ?>
                    </ul>
                </li>
                <?php endif; ?>

                <!-- In-Patient Admissions -->
                <?php if ($show_inpatient_admissions): ?>
                <li>
                    <a href="javascript:void(0);">
                        <i class="fas fa-procedures"></i>
                        <span>In-Patient Admissions</span>
                        <span class="menu-arrow"></span>
                    </a>
                    <ul class="nav-second-level">
                        <?php if ($show_active_admissions): ?>
                        <li><a href="admissions.php" <?php echo basename($_SERVER['PHP_SELF']) == 'admissions.php' ? 'class="active"' : ''; ?>><i class="fas fa-hospital-user"></i> Active Admissions</a></li>
                        <?php endif; ?>
                        <?php if ($show_visitor_pass): ?>
                        <li><a href="visitor_pass.php" <?php echo basename($_SERVER['PHP_SELF']) == 'visitor_pass.php' ? 'class="active"' : ''; ?>><i class="fas fa-id-card"></i> Visitor Pass</a></li>
                        <?php endif; ?>
                    </ul>
                </li>
                <?php endif; ?>
                
                <!-- Emergency & Logs -->
                <?php if ($show_emergency_logs): ?>
                <li>
                    <a href="javascript:void(0);">
                        <i class="fas fa-ambulance"></i>
                        <span>Emergency & Logs</span>
                        <span class="menu-arrow"></span>
                    </a>
                    <ul class="nav-second-level">
                        <?php if ($show_birth_death_registry): ?>
                        <li><a href="birth_death_registry.php" <?php echo basename($_SERVER['PHP_SELF']) == 'birth_death_registry.php' ? 'class="active"' : ''; ?>><i class="fas fa-baby"></i> Death/Birth Registry</a></li>
                        <?php endif; ?>
                    </ul>
                </li>
                <?php endif; ?>

                <!-- Reports -->
                <?php if ($show_reports): ?>
                <li>
                    <a href="javascript:void(0);">
                        <i class="fas fa-chart-bar"></i>
                        <span>Reports</span>
                        <span class="menu-arrow"></span>
                    </a>
                    <ul class="nav-second-level">
                        <li><a href="reports.php" <?php echo basename($_SERVER['PHP_SELF']) == 'reports.php' ? 'class="active"' : ''; ?>><i class="fas fa-file-alt"></i> All Reports</a></li>
                        <?php if (sidebar_has_permission('export_data', $user_permissions, $is_global_admin)): ?>
                        <li><a href="export_data.php" <?php echo basename($_SERVER['PHP_SELF']) == 'export_data.php' ? 'class="active"' : ''; ?>><i class="fas fa-file-export"></i> Export Data</a></li>
                        <?php endif; ?>
                        <?php if (sidebar_has_permission('view_audit_logs', $user_permissions, $is_global_admin)): ?>
                        <li><a href="audit_logs.php" <?php echo basename($_SERVER['PHP_SELF']) == 'audit_logs.php' ? 'class="active"' : ''; ?>><i class="fas fa-history"></i> Audit Logs</a></li>
                        <?php endif; ?>
                    </ul>
                </li>
                <?php endif; ?>

                <!-- Administration -->
                <?php if ($show_administration): ?>
                <li>
                    <a href="javascript:void(0);">
                        <i class="fas fa-cog"></i>
                        <span>Administration</span>
                        <span class="menu-arrow"></span>
                    </a>
                    <ul class="nav-second-level">
                        <?php if (sidebar_has_permission('manage_users', $user_permissions, $is_global_admin)): ?>
                        <li><a href="users.php" <?php echo basename($_SERVER['PHP_SELF']) == 'users.php' ? 'class="active"' : ''; ?>><i class="fas fa-users-cog"></i> User Management</a></li>
                        <?php endif; ?>
                        <?php if (sidebar_has_permission('manage_roles', $user_permissions, $is_global_admin)): ?>
                        <li><a href="roles.php" <?php echo basename($_SERVER['PHP_SELF']) == 'roles.php' ? 'class="active"' : ''; ?>><i class="fas fa-user-tag"></i> Roles & Permissions</a></li>
                        <?php endif; ?>
                        <?php if (sidebar_has_permission('manage_branches', $user_permissions, $is_global_admin)): ?>
                        <li><a href="branches.php" <?php echo basename($_SERVER['PHP_SELF']) == 'branches.php' ? 'class="active"' : ''; ?>><i class="fas fa-store"></i> Branches</a></li>
                        <?php endif; ?>
                        <?php if (sidebar_has_permission('manage_departments', $user_permissions, $is_global_admin)): ?>
                        <li><a href="departments.php" <?php echo basename($_SERVER['PHP_SELF']) == 'departments.php' ? 'class="active"' : ''; ?>><i class="fas fa-building"></i> Departments</a></li>
                        <?php endif; ?>
                        <?php if (sidebar_has_permission('view_audit_logs', $user_permissions, $is_global_admin)): ?>
                        <li><a href="audit_logs.php" <?php echo basename($_SERVER['PHP_SELF']) == 'audit_logs.php' ? 'class="active"' : ''; ?>><i class="fas fa-history"></i> Audit Logs</a></li>
                        <?php endif; ?>
                    </ul>
                </li>
                <?php endif; ?>
                <?php endif; ?>
            </ul>
        </div>
        <div class="clearfix"></div>
    </div>
</div>

<script>
// Toggle submenu on menu item click
document.addEventListener('DOMContentLoaded', function() {
    const menuItems = document.querySelectorAll('#side-menu > li > a');
    
    menuItems.forEach(item => {
        item.addEventListener('click', function(e) {
            const submenu = this.nextElementSibling;
            if (submenu && submenu.classList.contains('nav-second-level')) {
                e.preventDefault();
                
                const parent = this.closest('li');
                parent.classList.toggle('open');
            }
        });
    });
    
    // Set active class based on current URL
    const currentPage = window.location.pathname.split('/').pop() || 'dashboard.php';
    const menuLinks = document.querySelectorAll('#side-menu a');
    
    menuLinks.forEach(link => {
        const href = link.getAttribute('href');
        if (href && href === currentPage) {
            link.classList.add('active');
            let parentLi = link.closest('li');
            if (parentLi && parentLi.parentElement && parentLi.parentElement.classList.contains('nav-second-level')) {
                let parentMenu = parentLi.closest('li');
                if (parentMenu) {
                    parentMenu.classList.add('active');
                    parentMenu.classList.add('open');
                }
            }
        }
    });
});
</script>