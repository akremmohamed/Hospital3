<?php
/**
 * HMS ERP - PROFESSIONAL SIDEBAR (Nursing + Finance + Clinical)
 * Modern design, real-time counters, RBAC-ready, fully responsive.
 * Enhanced with comprehensive menu items based on database schema.
 */

require_once __DIR__ . '/../../../../config/db_connection.php';
require_once __DIR__ . '/../../../../includes/auth_helper.php';

date_default_timezone_set('Africa/Addis_Ababa');
if ($pdo) $pdo->exec("SET time_zone = '+03:00'");

$user_id       = (int)($_SESSION['user_id'] ?? 0);
$branch_id     = (int)($_SESSION['branch_id'] ?? 0);
$user_type     = $_SESSION['user_type'] ?? '';
$is_admin      = ($user_type === 'admin' || $user_type === 'super_admin');

// ---- User display name & role ----
$first_name = $_SESSION['first_name'] ?? '';
$last_name  = $_SESSION['last_name'] ?? '';
$username   = $_SESSION['username'] ?? 'Clinical Staff';
$nurse_name = trim($first_name . ' ' . $last_name);
if (empty($nurse_name)) $nurse_name = $username;
$nurse_specialty = $_SESSION['specialization'] ?? ucfirst($user_type);

// ------------------------------------------------------------------
// CLINICAL / NURSING COUNTERS (real-time)
// ------------------------------------------------------------------
$active_inpatients = 0;
$occupied_beds     = 0;
$pending_samples   = 0;
$pending_triage     = 0;
$pending_appointments = 0;

try {
    $base_cond = $is_admin ? "" : " AND branch_id = ?";
    $params = $is_admin ? [] : [$branch_id];
    
    // Active inpatients
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM admissions WHERE deleted_at IS NULL AND status = 'admitted' AND discharged_at IS NULL" . $base_cond);
    $stmt->execute($params);
    $active_inpatients = (int)$stmt->fetchColumn();

    // Occupied beds
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM beds WHERE deleted_at IS NULL AND status = 'occupied'" . $base_cond);
    $stmt->execute($params);
    $occupied_beds = (int)$stmt->fetchColumn();

    // Pending lab samples (ordered)
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM lab_orders WHERE deleted_at IS NULL AND status IN ('ordered', 'pending')" . $base_cond);
    $stmt->execute($params);
    $pending_samples = (int)$stmt->fetchColumn();

    // Pending triage (active appointments needing triage)
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM appointments WHERE deleted_at IS NULL AND status IN ('scheduled', 'confirmed', 'checked_in') AND visit_type != 'emergency'" . $base_cond);
    $stmt->execute($params);
    $pending_triage = (int)$stmt->fetchColumn();

    // Pending appointments (scheduled but not checked in)
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM appointments WHERE deleted_at IS NULL AND status IN ('scheduled', 'confirmed')" . $base_cond);
    $stmt->execute($params);
    $pending_appointments = (int)$stmt->fetchColumn();

} catch (PDOException $e) {
    error_log('Clinical counters error: ' . $e->getMessage());
}

// ------------------------------------------------------------------
// FINANCE COUNTERS
// ------------------------------------------------------------------
$pending_billing_count  = 0;
$cashier_queue_count    = 0;
$unbatched_claims_count = 0;
$pending_expenses_count = 0;
$discrepancy_invoices_count = 0;
$pending_payments_count = 0;

try {
    $base_cond = $is_admin ? "" : " AND branch_id = ?";
    $params = $is_admin ? [] : [$branch_id];

    // Pending/partial invoices
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM invoices WHERE deleted_at IS NULL AND payment_status IN ('unpaid','partial')" . $base_cond);
    $stmt->execute($params);
    $pending_billing_count = (int)$stmt->fetchColumn();

    // Cashier queue (unpaid only)
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM invoices WHERE deleted_at IS NULL AND payment_status = 'unpaid'" . $base_cond);
    $stmt->execute($params);
    $cashier_queue_count = (int)$stmt->fetchColumn();

    // Unbatched insurance claims
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM invoices WHERE deleted_at IS NULL AND billing_type = 'insurance' AND payment_status IN ('unpaid','partial')" . $base_cond);
    $stmt->execute($params);
    $unbatched_claims_count = (int)$stmt->fetchColumn();

    // Pending expenses
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM expense_registry WHERE deleted_at IS NULL" . $base_cond);
    $stmt->execute($params);
    $pending_expenses_count = (int)$stmt->fetchColumn();

    // Discrepancy supplier orders
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM supplier_orders WHERE deleted_at IS NULL AND order_status IN ('draft', 'ordered')" . $base_cond);
    $stmt->execute($params);
    $discrepancy_invoices_count = (int)$stmt->fetchColumn();

    // Pending payments (unverified)
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE deleted_at IS NULL AND is_verified = 0" . $base_cond);
    $stmt->execute($params);
    $pending_payments_count = (int)$stmt->fetchColumn();

} catch (PDOException $e) {
    error_log('Finance counters error: ' . $e->getMessage());
}

// ------------------------------------------------------------------
// PHARMACY COUNTERS
// ------------------------------------------------------------------
$low_stock_count = 0;
$expiring_count = 0;
$pending_prescriptions = 0;

try {
    $base_cond = $is_admin ? "" : " AND branch_id = ?";
    $params = $is_admin ? [] : [$branch_id];

    // Low stock items
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM pharmacy_inventory WHERE deleted_at IS NULL AND quantity_in_hand <= reorder_level AND quantity_in_hand > 0" . $base_cond);
    $stmt->execute($params);
    $low_stock_count = (int)$stmt->fetchColumn();

    // Expiring within 30 days
    $stmt = $pdo->prepare("SELECT COUNT(DISTINCT medicine_id) FROM pharmacy_inventory WHERE deleted_at IS NULL AND expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) AND quantity_in_hand > 0" . $base_cond);
    $stmt->execute($params);
    $expiring_count = (int)$stmt->fetchColumn();

    // Pending prescriptions
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM prescriptions WHERE deleted_at IS NULL AND status IN ('pending_payment', 'active')" . $base_cond);
    $stmt->execute($params);
    $pending_prescriptions = (int)$stmt->fetchColumn();

} catch (PDOException $e) {
    error_log('Pharmacy counters error: ' . $e->getMessage());
}

// ------------------------------------------------------------------
// LAB COUNTERS
// ------------------------------------------------------------------
$pending_lab_orders = 0;
$pending_verification = 0;

try {
    $base_cond = $is_admin ? "" : " AND branch_id = ?";
    $params = $is_admin ? [] : [$branch_id];

    // Pending lab orders (payment or processing)
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM lab_orders WHERE deleted_at IS NULL AND status IN ('pending_payment', 'pending', 'ordered')" . $base_cond);
    $stmt->execute($params);
    $pending_lab_orders = (int)$stmt->fetchColumn();

    // Pending verification (results ready to verify)
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM lab_orders WHERE deleted_at IS NULL AND status = 'completed' AND deleted_at IS NULL" . $base_cond);
    // Simplified - would need a more specific verification status
    $stmt->execute($params);
    $pending_verification = (int)$stmt->fetchColumn();

} catch (PDOException $e) {
    error_log('Lab counters error: ' . $e->getMessage());
}

// ------------------------------------------------------------------
// RBAC PERMISSIONS
// ------------------------------------------------------------------
$can_view_finance     = $is_admin || user_has_permission($pdo, 'view_finance_module', $user_id);
$can_manage_billing   = $is_admin || user_has_permission($pdo, 'manage_patient_billing', $user_id);
$can_collect_payments = $is_admin || user_has_permission($pdo, 'collect_patient_payments', $user_id);
$can_manage_claims    = $is_admin || user_has_permission($pdo, 'manage_insurance_claims', $user_id);
$can_track_expenses   = $is_admin || user_has_permission($pdo, 'track_hospital_expenses', $user_id);
$can_manage_ap        = $is_admin || user_has_permission($pdo, 'manage_accounts_payable', $user_id);
$can_access_analytics = $is_admin || user_has_permission($pdo, 'access_finance_analytics', $user_id);
$can_view_patients    = $is_admin || user_has_permission($pdo, 'view_patient_list', $user_id);
$can_register_patient = $is_admin || user_has_permission($pdo, 'register_patient', $user_id);
$can_view_appointments = $is_admin || user_has_permission($pdo, 'view_appointments', $user_id);
$can_manage_appointments = $is_admin || user_has_permission($pdo, 'schedule_appointment', $user_id);
$can_view_pharmacy    = $is_admin || user_has_permission($pdo, 'view_prescriptions', $user_id);
$can_manage_pharmacy  = $is_admin || user_has_permission($pdo, 'update_inventory', $user_id);
$can_dispense         = $is_admin || user_has_permission($pdo, 'dispense_medication', $user_id);
$can_view_lab         = $is_admin || user_has_permission($pdo, 'view_lab_orders_queue', $user_id);
$can_manage_lab       = $is_admin || user_has_permission($pdo, 'enter_lab_results', $user_id);
$can_view_nursing     = $is_admin || user_has_permission($pdo, 'nursing_dashboard_access', $user_id);
$can_manage_nursing   = $is_admin || user_has_permission($pdo, 'manage_admissions', $user_id);
$can_view_admissions  = $is_admin || user_has_permission($pdo, 'view_admissions', $user_id);
$can_manage_birth_death = $is_admin || user_has_permission($pdo, 'registry_manage_access', $user_id);
$can_view_reports     = $is_admin || user_has_permission($pdo, 'view_reports', $user_id);
$can_manage_users     = $is_admin || user_has_permission($pdo, 'manage_users', $user_id);
?>

<aside class="left-sidebar" id="left-sidebar">


    <!-- MAIN NAVIGATION -->
    <div class="sidebar-nav">
        <ul class="metismenu" id="side-menu">
            
            <!-- DASHBOARD -->
            <li class="active">
                <a href="dashboard.php">
                    <i class="fas fa-tachometer-alt"></i>
                    <span>Dashboard</span>
                </a>
            </li>
            <!-- FINANCE & BILLING -->
            <?php if ($can_view_finance || $can_manage_billing || $can_collect_payments || $can_manage_claims || $can_track_expenses): ?>
            <li class="menu-title">FINANCE & BILLING</li>
            <li>
                <a href="javascript:void(0);" class="has-arrow">
                    <i class="fas fa-chart-line"></i>
                    <span>Finance Operations</span>
                    <span class="badge <?= ($pending_billing_count > 0 || $pending_payments_count > 0) ? 'bg-danger' : 'bg-secondary' ?>">
                        <?= $pending_billing_count + $pending_payments_count ?>
                    </span>
                </a>
                <ul class="sub-menu">
                    <?php if ($can_manage_billing): ?>
                        <li><a href="patient_billing.php"><i class="fas fa-file-invoice"></i> <span>Patient Billing Ledger</span> <span class="badge <?= $pending_billing_count > 0 ? 'bg-primary' : 'bg-secondary' ?>"><?= $pending_billing_count ?></span></a></li>
                    <?php endif; ?>
                    <?php if ($can_collect_payments): ?>
                        <li><a href="cashier_workspace.php"><i class="fas fa-cash-register"></i> <span>Cashier Terminal (POS)</span> <span class="badge <?= $cashier_queue_count > 0 ? 'bg-success' : 'bg-secondary' ?>"><?= $cashier_queue_count ?></span></a></li>
                    <?php endif; ?>
                    <?php if ($can_manage_claims): ?>
                        <li><a href="insurance_claims.php"><i class="fas fa-file-contract"></i> <span>Corporate Claims & Insurance</span> <span class="badge <?= $unbatched_claims_count > 0 ? 'bg-info' : 'bg-secondary' ?>"><?= $unbatched_claims_count ?></span></a></li>
                    <?php endif; ?>
                    <?php if ($can_track_expenses): ?>
                        <li><a href="expense_tracker.php"><i class="fas fa-receipt"></i> <span>Petty Cash & Expenses</span> <span class="badge <?= $pending_expenses_count > 0 ? 'bg-warning' : 'bg-secondary' ?>"><?= $pending_expenses_count ?></span></a></li>
                    <?php endif; ?>
                    <?php if ($can_manage_ap): ?>
                        <li><a href="accounts_payable.php"><i class="fas fa-truck"></i> <span>Accounts Payable (Vendors)</span> <span class="badge <?= $discrepancy_invoices_count > 0 ? 'bg-danger' : 'bg-secondary' ?>"><?= $discrepancy_invoices_count ?></span></a></li>
                    <?php endif; ?>
                    <?php if ($can_access_analytics): ?>
                        <li><a href="daily_cash_closure.php"><i class="fas fa-cash-register"></i> <span>Daily Cash Closure</span></a></li>
                    <?php endif; ?>
                </ul>
            </li>
            <?php endif; ?>

            <!-- REPORTS -->
            <?php if ($can_view_reports): ?>
            <li class="menu-title">REPORTS</li>
            <li>
                <a href="javascript:void(0);" class="has-arrow">
                    <i class="fas fa-file-alt"></i>
                    <span>Reports</span>
                </a>
                <ul class="sub-menu">
                    <li><a href="reports/daily_summary.php"><i class="fas fa-calendar-day"></i> <span>Daily Summary</span></a></li>
                    <li><a href="reports/patient_visits.php"><i class="fas fa-users"></i> <span>Patient Visits</span></a></li>
                    <li><a href="reports/financial_reports.php"><i class="fas fa-chart-bar"></i> <span>Financial Reports</span></a></li>
                    <li><a href="reports/pharmacy_reports.php"><i class="fas fa-prescription-bottle"></i> <span>Pharmacy Reports</span></a></li>
                    <li><a href="reports/lab_reports.php"><i class="fas fa-flask"></i> <span>Lab Reports</span></a></li>
                    <li><a href="reports/admission_reports.php"><i class="fas fa-procedures"></i> <span>Admission Reports</span></a></li>
                </ul>
            </li>
            <?php endif; ?>

            <!-- ADMINISTRATION -->
            <?php if ($is_admin): ?>
            <li class="menu-title">ADMINISTRATION</li>
            <li>
                <a href="javascript:void(0);" class="has-arrow">
                    <i class="fas fa-cogs"></i>
                    <span>System Admin</span>
                </a>
                <ul class="sub-menu">
                    <?php if ($can_manage_users): ?>
                        <li><a href="user_management.php"><i class="fas fa-users-cog"></i> <span>User Management</span></a></li>
                        <li><a href="role_permissions.php"><i class="fas fa-lock"></i> <span>Roles & Permissions</span></a></li>
                    <?php endif; ?>
                    <li><a href="branch_settings.php"><i class="fas fa-building"></i> <span>Branch Settings</span></a></li>
                    <li><a href="department_settings.php"><i class="fas fa-sitemap"></i> <span>Departments</span></a></li>
                    <li><a href="audit_logs.php"><i class="fas fa-history"></i> <span>Audit Logs</span></a></li>
                    <li><a href="system_settings.php"><i class="fas fa-sliders-h"></i> <span>System Settings</span></a></li>
                    <li><a href="backup_management.php"><i class="fas fa-database"></i> <span>Backup</span></a></li>
                </ul>
            </li>
            <?php endif; ?>

        </ul>
    </div>

    <div class="sidebar-footer">
        <p class="copyright">© 2020 – 2026 <br> HMS ERP v2.0</p>
    </div>
</aside>

<style>
/* ============================================= */
/* MODERN SIDEBAR – ATTRACTIVE & RESPONSIVE      */
/* ============================================= */
.left-sidebar {
    background: linear-gradient(180deg, #ffffff 0%, #fafbfc 100%);
    width: 280px;
    position: fixed;
    top: 70px;
    left: 0;
    height: calc(100% - 70px);
    z-index: 1020;
    transition: all 0.25s ease;
    box-shadow: 0 8px 20px rgba(0, 0, 0, 0.03), 2px 0 12px rgba(0, 0, 0, 0.05);
    border-right: 1px solid rgba(0, 0, 0, 0.05);
    display: flex;
    flex-direction: column;
    overflow: hidden;
}

/* Collapsed state */
body.erp-sidebar-collapsed .left-sidebar {
    width: 85px;
}

/* Profile card */
.sidebar-profile {
    padding: 20px 16px 12px;
    text-align: center;
    border-bottom: 1px solid #eef2f6;
    flex-shrink: 0;
}
.profile-avatar {
    position: relative;
    display: inline-block;
    margin-bottom: 8px;
}
.avatar-icon {
    width: 64px;
    height: 64px;
    background: linear-gradient(135deg, #4361ee 0%, #3b2f80 100%);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    box-shadow: 0 4px 12px rgba(67, 97, 238, 0.3);
}
.avatar-icon .fw-bold {
    font-size: 1.5rem;
}
.profile-status {
    position: absolute;
    bottom: 2px;
    right: 2px;
    width: 14px;
    height: 14px;
    background: #22c55e;
    border: 2px solid white;
    border-radius: 50%;
}
.profile-info h5 {
    font-size: 0.95rem;
    font-weight: 700;
    margin: 0 0 2px;
    color: #1e293b;
}
.profile-info p {
    font-size: 0.7rem;
    color: #64748b;
    margin: 0;
}

/* Stats grid */
.stats-grid {
    display: flex;
    justify-content: space-between;
    gap: 4px;
    margin-top: 12px;
    background: #f1f5f9;
    border-radius: 16px;
    padding: 6px 8px;
}
.stat-card {
    text-align: center;
    flex: 1;
    padding: 4px 2px;
}
.stat-number {
    font-size: 1.1rem;
    font-weight: 800;
    color: #0f172a;
    line-height: 1.2;
}
.stat-label {
    font-size: 0.6rem;
    text-transform: uppercase;
    font-weight: 600;
    color: #475569;
    letter-spacing: 0.3px;
}

/* Navigation */
.sidebar-nav {
    flex: 1;
    overflow-y: auto;
    overflow-x: hidden;
    padding: 4px 8px 8px;
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
.metismenu li a {
    display: flex;
    align-items: center;
    padding: 8px 12px;
    border-radius: 10px;
    color: #334155;
    font-size: 0.85rem;
    font-weight: 500;
    transition: all 0.2s;
    text-decoration: none;
    gap: 10px;
    white-space: nowrap;
}
.metismenu li a i {
    font-size: 1.1rem;
    width: 24px;
    text-align: center;
    color: #5b6e8c;
    flex-shrink: 0;
}
.metismenu li a .badge {
    margin-left: auto;
    font-size: 0.65rem;
    font-weight: 600;
    padding: 2px 8px;
    border-radius: 30px;
    flex-shrink: 0;
}
.metismenu li a:hover {
    background: #eef2ff;
    color: #4361ee;
}
.metismenu li a:hover i {
    color: #4361ee;
}
.metismenu li.active > a {
    background: #e0e7ff;
    color: #4361ee;
    font-weight: 600;
}
.metismenu li.active > a i {
    color: #4361ee;
}
.metismenu .menu-title {
    padding: 14px 12px 6px;
    font-size: 0.65rem;
    font-weight: 700;
    letter-spacing: 0.5px;
    color: #94a3b8;
    text-transform: uppercase;
}
.sub-menu {
    list-style: none;
    padding-left: 20px;
    display: none;
}
.sub-menu li a {
    padding: 6px 12px;
    font-size: 0.8rem;
}
.sub-menu li a i {
    font-size: 0.9rem;
    width: 20px;
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
    right: 12px;
    top: 50%;
    transform: translateY(-50%);
    transition: transform 0.2s;
    font-size: 0.8rem;
}
.has-arrow[aria-expanded="true"]::after {
    transform: translateY(-50%) rotate(180deg);
}

/* Footer */
.sidebar-footer {
    padding: 12px;
    border-top: 1px solid #e9edf2;
    font-size: 0.65rem;
    text-align: center;
    color: #94a3b8;
    flex-shrink: 0;
}
.sidebar-footer .copyright {
    margin: 0;
}

/* Collapsed overrides */
body.erp-sidebar-collapsed .sidebar-profile .profile-info,
body.erp-sidebar-collapsed .stats-grid,
body.erp-sidebar-collapsed .metismenu li a span:not(.badge),
body.erp-sidebar-collapsed .metismenu li a .badge,
body.erp-sidebar-collapsed .menu-title,
body.erp-sidebar-collapsed .sidebar-footer {
    display: none;
}
body.erp-sidebar-collapsed .metismenu li a {
    justify-content: center;
    padding: 10px 0;
}
body.erp-sidebar-collapsed .metismenu li a i {
    margin: 0;
    font-size: 1.3rem;
}
body.erp-sidebar-collapsed .has-arrow::after {
    display: none;
}
body.erp-sidebar-collapsed .sub-menu {
    display: none !important;
}

/* Mobile responsive */
@media (max-width: 768px) {
    .left-sidebar {
        left: -280px;
        top: 70px;
        height: calc(100% - 70px);
        box-shadow: 0 0 30px rgba(0,0,0,0.15);
    }
    body.sidebar-enable .left-sidebar {
        left: 0;
    }
    body.sidebar-enable {
        overflow: hidden;
    }
}

/* Small screens */
@media (max-width: 576px) {
    .left-sidebar {
        width: 260px;
        left: -260px;
    }
    .sidebar-profile .profile-avatar .avatar-icon {
        width: 50px;
        height: 50px;
    }
    .sidebar-profile .profile-avatar .avatar-icon .fw-bold {
        font-size: 1.2rem;
    }
    .stats-grid .stat-number {
        font-size: 0.95rem;
    }
}
</style>

<script>
(function() {
    'use strict';

    // Initialize MetisMenu (if available)
    if (typeof MetisMenu !== 'undefined') {
        const sideMenu = document.getElementById('side-menu');
        if (sideMenu && !sideMenu.hasAttribute('data-metismenu')) {
            new MetisMenu(sideMenu, {
                toggle: true,
                triggerElement: 'a.has-arrow',
                parentTrigger: 'li',
                subMenu: 'ul'
            });
            sideMenu.setAttribute('data-metismenu', 'true');
        }
    }

    // Sync collapsed state from localStorage (set by nav.php)
    const restoreCollapsedState = () => {
        if (window.innerWidth > 992) {
            const saved = localStorage.getItem('erp_sidebar_collapsed');
            if (saved === 'true') {
                document.body.classList.add('erp-sidebar-collapsed');
            } else {
                document.body.classList.remove('erp-sidebar-collapsed');
            }
        } else {
            document.body.classList.remove('erp-sidebar-collapsed');
        }
    };
    restoreCollapsedState();

    // Listen for storage changes (in case another tab updates)
    window.addEventListener('storage', (e) => {
        if (e.key === 'erp_sidebar_collapsed') {
            restoreCollapsedState();
        }
    });

    // Auto-close mobile sidebar on window resize
    window.addEventListener('resize', () => {
        if (window.innerWidth > 768) {
            document.body.classList.remove('sidebar-enable');
            document.body.style.overflow = '';
        }
        if (window.innerWidth > 992) {
            restoreCollapsedState();
        }
    });

    // Active link highlighting (respects expanded sub-menus)
    const currentPath = window.location.pathname.split('/').pop() || 'dashboard.php';
    const allLinks = document.querySelectorAll('#side-menu a:not(.has-arrow)');
    allLinks.forEach(link => {
        const href = link.getAttribute('href');
        if (href && href === currentPath) {
            link.classList.add('active');
            const parentSubMenu = link.closest('.sub-menu');
            if (parentSubMenu) {
                parentSubMenu.style.display = 'block';
                const parentArrow = parentSubMenu.previousElementSibling;
                if (parentArrow && parentArrow.classList.contains('has-arrow')) {
                    parentArrow.classList.add('active');
                    parentArrow.setAttribute('aria-expanded', 'true');
                }
            }
        }
    });

    // Click handler for sub-menu toggles (fallback)
    document.querySelectorAll('.has-arrow').forEach(function(arrow) {
        arrow.addEventListener('click', function(e) {
            e.preventDefault();
            const subMenu = this.nextElementSibling;
            if (subMenu && subMenu.classList.contains('sub-menu')) {
                const isExpanded = this.getAttribute('aria-expanded') === 'true';
                this.setAttribute('aria-expanded', !isExpanded);
                subMenu.style.display = isExpanded ? 'none' : 'block';
            }
        });
    });

    // Helper function to get user initials (JS version)
    window.getUserInitials = function(name) {
        if (!name) return '?';
        const parts = name.trim().split(' ');
        let initials = '';
        for (let i = 0; i < Math.min(parts.length, 2); i++) {
            if (parts[i]) initials += parts[i][0].toUpperCase();
        }
        return initials || '?';
    };
})();
</script>