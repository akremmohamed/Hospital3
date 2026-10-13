<?php
/**
 * HMS ERP - TOP NAVIGATION BAR (Responsive, Mobile-Friendly, Finance Ready)
 * Fully compatible with sidebar.php toggles and localStorage.
 * Enhanced with complete financial notifications and multi-role support.
 */

require_once __DIR__ . '/../../../../config/db_connection.php';
require_once __DIR__ . '/../../../../includes/auth_helper.php';

date_default_timezone_set('Africa/Addis_Ababa');
if ($pdo) {
    $pdo->exec("SET time_zone = '+03:00'");
}

// ---- Authentication & Multi-tenant context ----
if (!isset($_SESSION['user_id']) || !isset($_SESSION['branch_id'])) {
    header('Location: /login.php');
    exit;
}

$user_id   = (int)$_SESSION['user_id'];
$branch_id = (int)$_SESSION['branch_id'];
$user_type = $_SESSION['user_type'] ?? '';
$is_super_admin = ($user_type === 'super_admin');
$is_admin = ($user_type === 'admin' || $user_type === 'super_admin');

// ---- Fetch user details & branch name ----
$user_name = 'Staff';
$user_role_label = ucfirst($user_type);
$branch_name = 'Branch';
$user_avatar = null;

try {
    $stmt = $pdo->prepare("SELECT first_name, last_name, picture, user_type FROM users WHERE id = ? AND deleted_at IS NULL");
    $stmt->execute([$user_id]);
    $user_data = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($user_data) {
        $user_name = trim($user_data['first_name'] . ' ' . $user_data['last_name']) ?: $user_data['user_type'];
        $user_role_label = ucfirst($user_data['user_type']);
        $user_avatar = $user_data['picture'];
    }

    $stmt = $pdo->prepare("SELECT name FROM branches WHERE id = ? AND deleted_at IS NULL");
    $stmt->execute([$branch_id]);
    $branch_row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($branch_row) {
        $branch_name = $branch_row['name'];
    }
} catch (PDOException $e) {
    error_log('Nav user/branch fetch error: ' . $e->getMessage());
}

// ---- Financial Notification Counters (real-time, multi-tenant) ----
$discrepancy_count = 0;
$high_value_pending = 0;
$pending_expense_vouchers = 0;
$pending_claims = 0;
$expiring_medicines = 0;
$low_stock_items = 0;
$pending_lab_results = 0;
$pending_admissions = 0;

try {
    $base_cond = $is_admin ? "" : " AND branch_id = ?";
    $params = $is_admin ? [] : [$branch_id];
    
    // 1) Supplier order discrepancies (draft or ordered)
    $sql = "SELECT COUNT(*) FROM supplier_orders WHERE deleted_at IS NULL AND order_status IN ('draft', 'ordered')";
    if (!$is_admin) { $sql .= " AND branch_id = ?"; }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($is_admin ? [] : [$branch_id]);
    $discrepancy_count = (int)$stmt->fetchColumn();

    // 2) High‑value pending liabilities (invoices > 5000 ETB not fully paid)
    $sql = "SELECT COUNT(*) FROM invoices WHERE deleted_at IS NULL AND total_amount > 5000 AND payment_status IN ('unpaid', 'partial')";
    if (!$is_admin) { $sql .= " AND branch_id = ?"; }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($is_admin ? [] : [$branch_id]);
    $high_value_pending = (int)$stmt->fetchColumn();

    // 3) Pending expense vouchers awaiting approval
    $sql = "SELECT COUNT(*) FROM management_approvals WHERE deleted_at IS NULL AND request_type = 'large_expense_authorization' AND approval_status = 'pending'";
    if (!$is_admin) { $sql .= " AND branch_id = ?"; }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($is_admin ? [] : [$branch_id]);
    $pending_expense_vouchers = (int)$stmt->fetchColumn();

    // 4) Pending insurance claims
    $sql = "SELECT COUNT(*) FROM insurance_claims WHERE claim_status IN ('draft', 'submitted', 'pending_review') AND deleted_at IS NULL";
    if (!$is_admin) { $sql .= " AND branch_id = ?"; }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($is_admin ? [] : [$branch_id]);
    $pending_claims = (int)$stmt->fetchColumn();

    // 5) Expiring medicines (within 30 days)
    $sql = "SELECT COUNT(DISTINCT medicine_id) FROM pharmacy_inventory WHERE deleted_at IS NULL AND expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) AND quantity_in_hand > 0";
    if (!$is_admin) { $sql .= " AND branch_id = ?"; }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($is_admin ? [] : [$branch_id]);
    $expiring_medicines = (int)$stmt->fetchColumn();

    // 6) Low stock items (below reorder level)
    $sql = "SELECT COUNT(*) FROM pharmacy_inventory WHERE deleted_at IS NULL AND quantity_in_hand <= reorder_level AND quantity_in_hand > 0";
    if (!$is_admin) { $sql .= " AND branch_id = ?"; }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($is_admin ? [] : [$branch_id]);
    $low_stock_items = (int)$stmt->fetchColumn();

    // 7) Pending lab results
    $sql = "SELECT COUNT(*) FROM lab_orders WHERE deleted_at IS NULL AND status = 'in_progress'";
    if (!$is_admin) { $sql .= " AND branch_id = ?"; }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($is_admin ? [] : [$branch_id]);
    $pending_lab_results = (int)$stmt->fetchColumn();

    // 8) Pending admissions awaiting bed assignment
    $sql = "SELECT COUNT(*) FROM admissions WHERE deleted_at IS NULL AND status = 'admitted' AND bed_id IS NULL";
    if (!$is_admin) { $sql .= " AND branch_id = ?"; }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($is_admin ? [] : [$branch_id]);
    $pending_admissions = (int)$stmt->fetchColumn();

} catch (PDOException $e) {
    error_log('Finance counters error: ' . $e->getMessage());
}

$total_notification_count = $discrepancy_count + $high_value_pending + $pending_expense_vouchers + 
                            $pending_claims + $expiring_medicines + $low_stock_items + 
                            $pending_lab_results + $pending_admissions;

// ---- RBAC checks for finance quick actions ----
$can_view_finance     = $is_admin || user_has_permission($pdo, 'view_finance_module', $user_id);
$can_collect_payments = $is_admin || user_has_permission($pdo, 'collect_patient_payments', $user_id);
$can_manage_claims    = $is_admin || user_has_permission($pdo, 'manage_insurance_claims', $user_id);
$can_track_expenses   = $is_admin || user_has_permission($pdo, 'track_hospital_expenses', $user_id);
$can_manage_billing   = $is_admin || user_has_permission($pdo, 'manage_patient_billing', $user_id);
$can_manage_inventory = $is_admin || user_has_permission($pdo, 'update_inventory', $user_id);
$can_manage_lab       = $is_admin || user_has_permission($pdo, 'view_lab_orders_queue', $user_id);

// ---- User initials for avatar ----
function getUserInitials($name) {
    $parts = explode(' ', trim($name));
    $initials = '';
    foreach ($parts as $part) {
        if (!empty($part)) {
            $initials .= strtoupper($part[0]);
        }
    }
    return substr($initials, 0, 2);
}
$user_initials = getUserInitials($user_name);
?>

<!-- ================================================ -->
<!-- TOP NAVIGATION BAR – RESPONSIVE & MOBILE-FRIENDLY -->
<!-- ================================================ -->
<nav class="navbar navbar-expand-lg navbar-light bg-white shadow-sm fixed-top px-3">
    <div class="container-fluid">
        <!-- Left side: toggle buttons + brand -->
        <div class="d-flex align-items-center">
            <!-- Mobile menu toggle (opens sidebar as off-canvas) -->
            <button class="btn btn-link text-dark me-3 d-lg-none" id="mobileMenuToggle" type="button">
                <i class="fas fa-bars fa-lg"></i>
            </button>
            <!-- Desktop sidebar collapse toggle -->
            <button class="btn btn-link text-dark me-3 d-none d-lg-block" id="desktopSidebarToggle" type="button">
                <i class="fas fa-bars fa-lg"></i>
            </button>
            <a class="navbar-brand fw-bold text-primary" href="dashboard.php">
                <i class="fas fa-hospital-user me-2"></i> HMS ERP
            </a>
        </div>

        <!-- Center: branch context badge (hidden on mobile) -->
        <div class="d-none d-md-block">
            <span class="badge bg-light text-dark border px-3 py-2 rounded-pill">
                <i class="fas fa-building me-1"></i> <?= htmlspecialchars($branch_name) ?>
            </span>
        </div>

        <!-- Right side: notifications, finance actions, profile -->
        <div class="d-flex align-items-center">
            <!-- Notification Bell with dynamic counters -->
            <div class="dropdown me-2 me-md-3">
                <button class="btn btn-link text-dark position-relative" type="button" id="notificationDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="fas fa-bell fa-lg"></i>
                    <?php if ($total_notification_count > 0): ?>
                        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">
                            <?= min($total_notification_count, 99) ?>
                            <span class="visually-hidden">unread alerts</span>
                        </span>
                    <?php endif; ?>
                </button>
                <div class="dropdown-menu dropdown-menu-end shadow-lg border-0 p-0" style="width: 360px;" aria-labelledby="notificationDropdown">
                    <div class="dropdown-header bg-light fw-semibold py-2 d-flex justify-content-between align-items-center">
                        <span><i class="fas fa-chart-line me-2"></i> System Alerts</span>
                        <span class="badge bg-secondary rounded-pill"><?= $total_notification_count ?></span>
                    </div>
                    <div class="list-group list-group-flush" style="max-height: 400px; overflow-y: auto;">
                        
                        <!-- Financial Alerts Section -->
                        <div class="dropdown-header bg-light-subtle py-1 small text-uppercase text-muted">Financial</div>
                        
                        <!-- Discrepancy alert -->
                        <a href="accounts_payable.php" class="dropdown-item list-group-item-action d-flex align-items-center gap-3 py-2 <?= $discrepancy_count > 0 ? '' : 'opacity-50' ?>">
                            <div class="flex-shrink-0">
                                <i class="fas fa-file-invoice-dollar fa-fw text-warning"></i>
                            </div>
                            <div class="flex-grow-1">
                                <small class="fw-bold d-block">Supplier Invoice Discrepancies</small>
                                <span class="text-muted small"><?= $discrepancy_count ?> purchase order(s) require review</span>
                            </div>
                            <?php if ($discrepancy_count > 0): ?>
                                <span class="badge bg-danger rounded-pill"><?= $discrepancy_count ?></span>
                            <?php endif; ?>
                        </a>
                        
                        <!-- High-value pending liabilities -->
                        <a href="patient_billing.php?filter=high_value" class="dropdown-item list-group-item-action d-flex align-items-center gap-3 py-2 <?= $high_value_pending > 0 ? '' : 'opacity-50' ?>">
                            <div class="flex-shrink-0">
                                <i class="fas fa-coins fa-fw text-danger"></i>
                            </div>
                            <div class="flex-grow-1">
                                <small class="fw-bold d-block">High-Value Outstanding Bills</small>
                                <span class="text-muted small"><?= $high_value_pending ?> invoices > 5,000 ETB pending</span>
                            </div>
                            <?php if ($high_value_pending > 0): ?>
                                <span class="badge bg-danger rounded-pill"><?= $high_value_pending ?></span>
                            <?php endif; ?>
                        </a>
                        
                        <!-- Pending expense vouchers -->
                        <a href="expense_tracker.php?filter=pending" class="dropdown-item list-group-item-action d-flex align-items-center gap-3 py-2 <?= $pending_expense_vouchers > 0 ? '' : 'opacity-50' ?>">
                            <div class="flex-shrink-0">
                                <i class="fas fa-receipt fa-fw text-info"></i>
                            </div>
                            <div class="flex-grow-1">
                                <small class="fw-bold d-block">Expense Vouchers Awaiting Approval</small>
                                <span class="text-muted small"><?= $pending_expense_vouchers ?> request(s) need executive sign-off</span>
                            </div>
                            <?php if ($pending_expense_vouchers > 0): ?>
                                <span class="badge bg-danger rounded-pill"><?= $pending_expense_vouchers ?></span>
                            <?php endif; ?>
                        </a>
                        
                        <!-- Pending insurance claims -->
                        <a href="insurance_claims.php?filter=pending" class="dropdown-item list-group-item-action d-flex align-items-center gap-3 py-2 <?= $pending_claims > 0 ? '' : 'opacity-50' ?>">
                            <div class="flex-shrink-0">
                                <i class="fas fa-file-contract fa-fw text-primary"></i>
                            </div>
                            <div class="flex-grow-1">
                                <small class="fw-bold d-block">Pending Insurance Claims</small>
                                <span class="text-muted small"><?= $pending_claims ?> claim(s) awaiting processing</span>
                            </div>
                            <?php if ($pending_claims > 0): ?>
                                <span class="badge bg-primary rounded-pill"><?= $pending_claims ?></span>
                            <?php endif; ?>
                        </a>

                        <!-- Inventory Alerts Section -->
                        <div class="dropdown-header bg-light-subtle py-1 small text-uppercase text-muted">Inventory</div>
                        
                        <!-- Expiring medicines -->
                        <a href="pharmacy_inventory.php?filter=expiring" class="dropdown-item list-group-item-action d-flex align-items-center gap-3 py-2 <?= $expiring_medicines > 0 ? '' : 'opacity-50' ?>">
                            <div class="flex-shrink-0">
                                <i class="fas fa-clock fa-fw text-warning"></i>
                            </div>
                            <div class="flex-grow-1">
                                <small class="fw-bold d-block">Expiring Medicines</small>
                                <span class="text-muted small"><?= $expiring_medicines ?> item(s) expire within 30 days</span>
                            </div>
                            <?php if ($expiring_medicines > 0): ?>
                                <span class="badge bg-warning rounded-pill"><?= $expiring_medicines ?></span>
                            <?php endif; ?>
                        </a>
                        
                        <!-- Low stock items -->
                        <a href="pharmacy_inventory.php?filter=low_stock" class="dropdown-item list-group-item-action d-flex align-items-center gap-3 py-2 <?= $low_stock_items > 0 ? '' : 'opacity-50' ?>">
                            <div class="flex-shrink-0">
                                <i class="fas fa-exclamation-triangle fa-fw text-danger"></i>
                            </div>
                            <div class="flex-grow-1">
                                <small class="fw-bold d-block">Low Stock Items</small>
                                <span class="text-muted small"><?= $low_stock_items ?> item(s) below reorder level</span>
                            </div>
                            <?php if ($low_stock_items > 0): ?>
                                <span class="badge bg-danger rounded-pill"><?= $low_stock_items ?></span>
                            <?php endif; ?>
                        </a>

                        <!-- Clinical Alerts Section -->
                        <div class="dropdown-header bg-light-subtle py-1 small text-uppercase text-muted">Clinical</div>
                        
                        <!-- Pending lab results -->
                        <a href="lab_dashboard.php?filter=pending" class="dropdown-item list-group-item-action d-flex align-items-center gap-3 py-2 <?= $pending_lab_results > 0 ? '' : 'opacity-50' ?>">
                            <div class="flex-shrink-0">
                                <i class="fas fa-flask fa-fw text-info"></i>
                            </div>
                            <div class="flex-grow-1">
                                <small class="fw-bold d-block">Pending Lab Results</small>
                                <span class="text-muted small"><?= $pending_lab_results ?> test(s) in progress</span>
                            </div>
                            <?php if ($pending_lab_results > 0): ?>
                                <span class="badge bg-info rounded-pill"><?= $pending_lab_results ?></span>
                            <?php endif; ?>
                        </a>
                        
                        <!-- Pending admissions -->
                        <a href="admissions.php?filter=no_bed" class="dropdown-item list-group-item-action d-flex align-items-center gap-3 py-2 <?= $pending_admissions > 0 ? '' : 'opacity-50' ?>">
                            <div class="flex-shrink-0">
                                <i class="fas fa-procedures fa-fw text-danger"></i>
                            </div>
                            <div class="flex-grow-1">
                                <small class="fw-bold d-block">Patients Awaiting Bed Assignment</small>
                                <span class="text-muted small"><?= $pending_admissions ?> admission(s) need bed allocation</span>
                            </div>
                            <?php if ($pending_admissions > 0): ?>
                                <span class="badge bg-danger rounded-pill"><?= $pending_admissions ?></span>
                            <?php endif; ?>
                        </a>
                    </div>
                    <div class="dropdown-footer text-center p-2 bg-light">
                        <a href="dashboard.php" class="small text-decoration-none">View full dashboard →</a>
                    </div>
                </div>
            </div>

            <!-- Finance Quick Actions Dropdown (RBAC protected, hidden on very small screens if needed) -->
            <?php if ($can_view_finance || $can_collect_payments || $can_manage_claims || $can_track_expenses): ?>
            <div class="dropdown me-2 me-md-3 d-none d-sm-block">
                <button class="btn btn-outline-primary rounded-pill px-3" type="button" id="financeActionsDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="fas fa-chart-simple me-1"></i> <span class="d-none d-md-inline">Finance</span> <i class="fas fa-chevron-down ms-1 small"></i>
                </button>
                <ul class="dropdown-menu dropdown-menu-end shadow border-0" aria-labelledby="financeActionsDropdown">
                    <?php if ($can_collect_payments): ?>
                        <li><a class="dropdown-item" href="cashier_workspace.php"><i class="fas fa-cash-register fa-fw me-2"></i> Collect Cashier Payment</a></li>
                    <?php endif; ?>
                    <?php if ($can_manage_billing): ?>
                        <li><a class="dropdown-item" href="patient_billing.php"><i class="fas fa-file-invoice fa-fw me-2"></i> Patient Billing Ledger</a></li>
                    <?php endif; ?>
                    <?php if ($can_manage_claims): ?>
                        <li><a class="dropdown-item" href="insurance_claims.php"><i class="fas fa-file-contract fa-fw me-2"></i> Process Claims Batch</a></li>
                    <?php endif; ?>
                    <?php if ($can_track_expenses): ?>
                        <li><a class="dropdown-item" href="expense_tracker.php"><i class="fas fa-receipt fa-fw me-2"></i> Review Urgent Expenses</a></li>
                    <?php endif; ?>
                    <?php if ($can_view_finance): ?>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="finance_dashboard.php"><i class="fas fa-chart-pie fa-fw me-2"></i> Finance Dashboard</a></li>
                        <li><a class="dropdown-item" href="daily_cash_closure.php"><i class="fas fa-cash-register fa-fw me-2"></i> Daily Cash Closure</a></li>
                        <li><a class="dropdown-item" href="accounts_payable.php"><i class="fas fa-truck fa-fw me-2"></i> Accounts Payable</a></li>
                    <?php endif; ?>
                </ul>
            </div>
            <?php endif; ?>

            <!-- Quick Actions Dropdown (Additional) -->
            <div class="dropdown me-2 me-md-3 d-none d-md-block">
                <button class="btn btn-link text-dark" type="button" id="quickActionsDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="fas fa-plus-circle fa-lg"></i>
                </button>
                <ul class="dropdown-menu dropdown-menu-end shadow border-0" aria-labelledby="quickActionsDropdown">
                    <li><a class="dropdown-item" href="register_patient.php"><i class="fas fa-user-plus fa-fw me-2"></i> Register New Patient</a></li>
                    <li><a class="dropdown-item" href="schedule_appointment.php"><i class="fas fa-calendar-plus fa-fw me-2"></i> Schedule Appointment</a></li>
                    <li><a class="dropdown-item" href="create_consultation.php"><i class="fas fa-stethoscope fa-fw me-2"></i> Start Consultation</a></li>
                    <li><a class="dropdown-item" href="create_invoice.php"><i class="fas fa-file-invoice-dollar fa-fw me-2"></i> Create Invoice</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item" href="admit_patient.php"><i class="fas fa-procedures fa-fw me-2"></i> Admit Patient</a></li>
                    <?php if ($can_manage_inventory): ?>
                        <li><a class="dropdown-item" href="add_stock.php"><i class="fas fa-boxes fa-fw me-2"></i> Add Inventory Stock</a></li>
                    <?php endif; ?>
                    <?php if ($can_manage_lab): ?>
                        <li><a class="dropdown-item" href="order_lab_test.php"><i class="fas fa-flask fa-fw me-2"></i> Order Lab Test</a></li>
                    <?php endif; ?>
                </ul>
            </div>

            <!-- User Profile Dropdown -->
            <div class="dropdown">
                <button class="btn btn-link text-dark d-flex align-items-center gap-2 text-decoration-none" type="button" id="userDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                    <div class="avatar-circle bg-primary text-white rounded-circle d-flex align-items-center justify-content-center" style="width: 38px; height: 38px;">
                        <?php if (!empty($user_avatar) && file_exists($user_avatar)): ?>
                            <img src="<?= htmlspecialchars($user_avatar) ?>" alt="Avatar" class="rounded-circle" style="width: 36px; height: 36px; object-fit: cover;">
                        <?php else: ?>
                            <span class="fw-bold small"><?= htmlspecialchars($user_initials) ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="d-none d-md-block text-start">
                        <div class="fw-semibold small"><?= htmlspecialchars($user_name) ?></div>
                        <div class="text-muted small"><?= htmlspecialchars($user_role_label) ?></div>
                    </div>
                    <i class="fas fa-chevron-down small d-none d-md-block"></i>
                </button>
                <ul class="dropdown-menu dropdown-menu-end shadow border-0" aria-labelledby="userDropdown">
                    <li><a class="dropdown-item" href="profile_settings.php"><i class="fas fa-user-circle fa-fw me-2"></i> My Profile</a></li>
                    <li><a class="dropdown-item" href="change_password.php"><i class="fas fa-key fa-fw me-2"></i> Change Password</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item" href="branch_switcher.php"><i class="fas fa-building fa-fw me-2"></i> Switch Branch</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item text-danger" href="assets/inc/logout.php"><i class="fas fa-sign-out-alt fa-fw me-2"></i> Sign Out</a></li>
                </ul>
            </div>
        </div>
    </div>
</nav>

<style>
    /* Avatar styling */
    .avatar-circle {
        background: linear-gradient(135deg, #4361ee, #3a0ca3);
        transition: all 0.2s;
        overflow: hidden;
    }
    .avatar-circle img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }
    .navbar {
        height: 70px;
        border-bottom: 1px solid rgba(0,0,0,0.05);
    }
    .dropdown-menu {
        margin-top: 0.5rem;
        border-radius: 16px;
    }
    .dropdown-item:active {
        background-color: #eef2ff;
    }
    .btn-outline-primary {
        border-color: #dee2e6;
        color: #1f2937;
    }
    .btn-outline-primary:hover {
        background-color: #f8fafc;
        border-color: #cbd5e1;
        color: #0f172a;
    }
    /* Ensure sidebar toggle icons are visible */
    .btn-link.text-dark:focus {
        box-shadow: none;
    }
    /* Notification dropdown scroll */
    .list-group-item-action {
        transition: background-color 0.15s;
    }
    .list-group-item-action:hover {
        background-color: #f8fafc;
    }
    .dropdown-header {
        font-weight: 600;
        color: #64748b;
        background-color: #f1f5f9;
    }
    /* Responsive adjustments */
    @media (max-width: 576px) {
        .navbar-brand {
            font-size: 1rem;
        }
        .navbar-brand i {
            font-size: 1.2rem;
        }
        .avatar-circle {
            width: 32px !important;
            height: 32px !important;
        }
        .avatar-circle span {
            font-size: 0.7rem;
        }
        .dropdown-menu {
            width: 320px !important;
        }
    }
    @media (max-width: 768px) {
        .navbar {
            padding-left: 0.75rem !important;
            padding-right: 0.75rem !important;
        }
        .container-fluid {
            padding-left: 0 !important;
            padding-right: 0 !important;
        }
    }
</style>

<script>
    (function() {
        'use strict';

        // Mobile sidebar toggle (adds class to body to open off-canvas sidebar)
        const mobileToggle = document.getElementById('mobileMenuToggle');
        if (mobileToggle) {
            mobileToggle.addEventListener('click', function(e) {
                e.preventDefault();
                document.body.classList.toggle('sidebar-enable');
                document.body.style.overflow = document.body.classList.contains('sidebar-enable') ? 'hidden' : '';
            });
        }

        // Desktop sidebar collapse toggle (triggers sidebar collapsed mode)
        const desktopToggle = document.getElementById('desktopSidebarToggle');
        if (desktopToggle) {
            desktopToggle.addEventListener('click', function(e) {
                e.preventDefault();
                document.body.classList.toggle('erp-sidebar-collapsed');
                localStorage.setItem('erp_sidebar_collapsed', document.body.classList.contains('erp-sidebar-collapsed'));
                // Trigger resize event for any layout recalculations
                window.dispatchEvent(new Event('resize'));
            });
        }

        // Restore desktop sidebar state on page load
        const restoreCollapsedState = () => {
            if (window.innerWidth > 992) {
                const saved = localStorage.getItem('erp_sidebar_collapsed');
                if (saved === 'true') {
                    document.body.classList.add('erp-sidebar-collapsed');
                } else {
                    document.body.classList.remove('erp-sidebar-collapsed');
                }
            } else {
                // On smaller screens, ensure collapsed class is removed to avoid layout issues
                document.body.classList.remove('erp-sidebar-collapsed');
            }
        };
        restoreCollapsedState();

        // Listen for storage changes (if multiple tabs)
        window.addEventListener('storage', (e) => {
            if (e.key === 'erp_sidebar_collapsed') {
                restoreCollapsedState();
            }
        });

        // Auto-close mobile sidebar when window is resized above mobile breakpoint
        window.addEventListener('resize', function() {
            if (window.innerWidth > 768 && document.body.classList.contains('sidebar-enable')) {
                document.body.classList.remove('sidebar-enable');
                document.body.style.overflow = '';
            }
            restoreCollapsedState();
        });

        // Initialize Bootstrap dropdowns if Bootstrap is loaded
        if (typeof bootstrap !== 'undefined') {
            var dropdownElementList = [].slice.call(document.querySelectorAll('.dropdown-toggle'));
            dropdownElementList.map(function(dropdownToggleEl) {
                new bootstrap.Dropdown(dropdownToggleEl);
            });
        }

        // Auto-refresh notification counter (every 60 seconds)
        if (typeof EventSource !== 'undefined') {
            // Optional: Implement SSE or periodic AJAX refresh
            setInterval(function() {
                fetch('api/notification_count.php')
                    .then(response => response.json())
                    .then(data => {
                        // Update badge counts dynamically
                        // Implementation depends on your API structure
                    })
                    .catch(() => {});
            }, 60000);
        }
    })();
</script>