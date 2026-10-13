<?php
// ============================================
// DOCTOR SIDEBAR - HMS ERP (Phase 5 Edition)
// RBAC-driven navigation | Synchronized with core modules
// ============================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../../../config/db_connection.php';
require_once __DIR__ . '/../../../../includes/auth_helper.php';

$user_id = (int)($_SESSION['user_id'] ?? 0);
$branch_id = (int)($_SESSION['branch_id'] ?? 0);
$is_global_admin = ($_SESSION['user_type'] ?? '') === 'super_admin';

// Default values
$doctor_name = 'Doctor';
$doctor_specialty = 'General Medicine';
$today_appointments = 0;
$waiting_patients = 0;
$in_consultation = 0;
$admitted_patients = 0;
$critical_labs = 0;
$completed_today = 0;

try {
    if ($pdo instanceof PDO && $user_id > 0) {
        // Doctor details (fallback)
        $stmt = $pdo->prepare("SELECT first_name, last_name, specialist, medical_degree FROM users WHERE id = :id AND user_type = 'doctor' AND deleted_at IS NULL");
        $stmt->execute([':id' => $user_id]);
        $doc = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($doc) {
            $doctor_name = trim(($doc['first_name'] ?? 'Doctor') . ' ' . ($doc['last_name'] ?? ''));
            $doctor_specialty = !empty($doc['specialist']) ? $doc['specialist'] : ($doc['medical_degree'] ?? 'General Medicine');
        }

        // Today's appointments (appointments table)
        $sql = "SELECT COUNT(*) FROM appointments WHERE doctor_id = :doctor_id AND appointment_date = CURDATE() AND deleted_at IS NULL";
        $params = [':doctor_id' => $user_id];
        if (!$is_global_admin && $branch_id > 0) {
            $sql .= " AND branch_id = :branch_id";
            $params[':branch_id'] = $branch_id;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $today_appointments = (int)$stmt->fetchColumn();

        // Waiting patients (patient_visits via appointments)
        $sql = "SELECT COUNT(DISTINCT pv.id) 
                FROM patient_visits pv
                JOIN appointments a ON pv.id = a.visit_id
                WHERE a.doctor_id = :doctor_id
                  AND pv.visit_date = CURDATE()
                  AND pv.status = 'checked_in'
                  AND pv.queue_status IN ('waiting', 'called')
                  AND pv.deleted_at IS NULL";
        $params = [':doctor_id' => $user_id];
        if (!$is_global_admin && $branch_id > 0) {
            $sql .= " AND pv.branch_id = :branch_id";
            $params[':branch_id'] = $branch_id;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $waiting_patients = (int)$stmt->fetchColumn();

        // In consultation (active OPD visit)
        $sql = "SELECT COUNT(DISTINCT pv.id) 
                FROM patient_visits pv
                JOIN appointments a ON pv.id = a.visit_id
                WHERE a.doctor_id = :doctor_id
                  AND pv.visit_date = CURDATE()
                  AND pv.status = 'in_consultation'
                  AND pv.deleted_at IS NULL";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $in_consultation = (int)$stmt->fetchColumn();

        // Completed today
        $sql = "SELECT COUNT(*) FROM appointments WHERE doctor_id = :doctor_id AND appointment_date = CURDATE() AND status = 'completed' AND deleted_at IS NULL";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $completed_today = (int)$stmt->fetchColumn();

        // Active inpatient admissions (under this doctor, not discharged)
        $sql = "SELECT COUNT(*) FROM admissions 
                WHERE doctor_id = :doctor_id 
                  AND status = 'admitted' 
                  AND discharged_at IS NULL 
                  AND deleted_at IS NULL";
        $params_adm = [':doctor_id' => $user_id];
        if (!$is_global_admin && $branch_id > 0) {
            $sql .= " AND branch_id = :branch_id";
            $params_adm[':branch_id'] = $branch_id;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params_adm);
        $admitted_patients = (int)$stmt->fetchColumn();

        // Critical lab results (unreviewed)
        $sql = "SELECT COUNT(DISTINCT ltr.id) 
                FROM lab_test_results ltr
                JOIN lab_orders lo ON ltr.lab_order_id = lo.id
                WHERE lo.doctor_id = :doctor_id 
                  AND ltr.is_critical = 1 
                  AND ltr.doctor_reviewed_at IS NULL 
                  AND ltr.deleted_at IS NULL";
        $params_crit = [':doctor_id' => $user_id];
        if (!$is_global_admin && $branch_id > 0) {
            $sql .= " AND lo.branch_id = :branch_id";
            $params_crit[':branch_id'] = $branch_id;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params_crit);
        $critical_labs = (int)$stmt->fetchColumn();
    }
} catch (PDOException $e) {
    error_log('Doctor Sidebar counters error: ' . $e->getMessage());
}

// Permission helpers for each major module (using existing user_has_permission)
function canViewOutpatient() {
    global $pdo, $user_id, $is_global_admin;
    return $is_global_admin || user_has_permission($pdo, 'consultation_access', $user_id);
}
function canViewDiagnostics() {
    global $pdo, $user_id, $is_global_admin;
    return $is_global_admin || user_has_permission($pdo, 'diagnostics_access', $user_id);
}
function canViewInpatient() {
    global $pdo, $user_id, $is_global_admin;
    return $is_global_admin || user_has_permission($pdo, 'inpatient_management_access', $user_id);
}
function canViewCommunication() {
    global $pdo, $user_id, $is_global_admin;
    return $is_global_admin || user_has_permission($pdo, 'doctor_consultation_access', $user_id);
}
function canViewMedicalRecords() {
    global $pdo, $user_id, $is_global_admin;
    return $is_global_admin || user_has_permission($pdo, 'emr_access', $user_id);
}
function canViewDischarge() {
    global $pdo, $user_id, $is_global_admin;
    return $is_global_admin || user_has_permission($pdo, 'discharge_summary_access', $user_id);
}

$current_file = basename($_SERVER['PHP_SELF'] ?? 'dashboard.php');
?>
<!DOCTYPE html>
<html>
<head>
<style>
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
    .badge-waiting, .badge-admitted, .badge-critical {
        margin-left: auto;
        font-size: 9px;
        padding: 2px 6px;
        border-radius: 20px;
        font-weight: 600;
    }
    .badge-waiting { background: var(--warning); color: #92400e; }
    .badge-admitted { background: var(--info); color: #0c4e6e; }
    .badge-critical { background: var(--danger); color: white; animation: pulse 1.5s infinite; }
    @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.7; } }
</style>
</head>
<body>

<div class="left-side-menu">
    <div class="slimscroll-menu">
        <div id="sidebar-menu">
            <ul class="metismenu" id="side-menu">

                <li class="menu-title">Clinical Workspace</li>

                <!-- Dashboard (always visible) -->
                <li class="nav-item <?php echo ($current_file == 'dashboard.php') ? 'active open' : ''; ?>">
                    <a href="dashboard.php"><i class="fas fa-th-large" title="Dashboard"></i><span>Dashboard</span></a>
                </li>

                <!-- Outpatient Consultation Hub -->
                <?php if (canViewOutpatient()): ?>
                <li class="nav-item <?php echo ($current_file == 'current_consultation.php') ? 'active open' : ''; ?>">
                    <a href="current_consultation.php"><i class="fas fa-user-md" title="Outpatient Consultation"></i><span>Outpatient Consultation</span>
                    <?php if ($waiting_patients > 0): ?><span class="badge-waiting"><?php echo (int)$waiting_patients; ?></span><?php endif; ?>
                    </a>
                </li>
                <?php endif; ?>

                <!-- Ward Management -->
                <?php if (canViewOutpatient()): ?>
                <li class="nav-item <?php echo ($current_file == 'ward_management.php') ? 'active open' : ''; ?>">
                    <a href="ward_management.php"><i class="fas fa-bed" title="Ward Management"></i><span>Ward Management</span>
                    <?php if ($waiting_patients > 0): ?><span class="badge-waiting"><?php echo (int)$waiting_patients; ?></span><?php endif; ?>
                    </a>
                </li>
                <?php endif; ?>

                <!-- Diagnostics Review Hub -->
                <?php if (canViewDiagnostics()): ?>
                <li class="nav-item <?php echo ($current_file == 'diagnostics_review.php') ? 'active open' : ''; ?>">
                    <a href="diagnostics_review.php"><i class="fas fa-microscope" title="Diagnostics Review"></i><span>Diagnostics Review</span>
                    <?php if ($critical_labs > 0): ?><span class="badge-critical"><?php echo (int)$critical_labs; ?></span><?php endif; ?>
                    </a>
                </li>
                <?php endif; ?>

                <!-- Inpatient & Ward Management -->
                <?php if (canViewInpatient()): ?>
                <li class="nav-item <?php echo ($current_file == 'ward_management.php') ? 'active open' : ''; ?>">
                    <a href="ward_management.php"><i class="fas fa-procedures" title="Inpatient & Ward"></i><span>Inpatient & Ward</span>
                    <?php if ($admitted_patients > 0): ?><span class="badge-admitted"><?php echo (int)$admitted_patients; ?></span><?php endif; ?>
                    </a>
                </li>
                <?php endif; ?>

                <!-- Clinical Communication -->
                <?php if (canViewCommunication()): ?>
                <li class="nav-item <?php echo ($current_file == 'clinical_communication.php') ? 'active open' : ''; ?>">
                    <a href="clinical_communication.php"><i class="fas fa-exchange-alt" title="Clinical Communication"></i><span>Clinical Communication</span></a>
                </li>
                <?php endif; ?>

                <li class="menu-title">Patient Records</li>

                <!-- Medical Records (EMR) -->
                <?php if (canViewMedicalRecords()): ?>
                <li class="nav-item <?php echo ($current_file == 'emr_view.php') ? 'active open' : ''; ?>">
                    <a href="emr_view.php"><i class="fas fa-folder-open" title="Medical Records"></i><span>Medical Records</span></a>
                </li>
                <?php endif; ?>

                <!-- Discharge Summary -->
                <?php if (canViewDischarge()): ?>
                <li class="nav-item <?php echo ($current_file == 'discharge_summary.php') ? 'active open' : ''; ?>">
                    <a href="discharge_summary.php"><i class="fas fa-door-open" title="Discharge Summary"></i><span>Discharge Summary</span></a>
                </li>
                <?php endif; ?>

            </ul>
        </div>
        <div class="clearfix"></div>
    </div>
</div>

<!-- Overlay element for mobile -->
<div class="sidebar-overlay"></div>

<script>
(function() {
    'use strict';
    const currentPage = window.location.pathname.split('/').pop() || 'dashboard.php';
    let overlay = document.querySelector('.sidebar-overlay');
    if (!overlay) { overlay = document.createElement('div'); overlay.className = 'sidebar-overlay'; document.body.appendChild(overlay); }

    function toggleSidebar(e) {
        if (e) e.preventDefault();
        if (window.innerWidth <= 992) {
            document.body.classList.toggle('sidebar-enable');
            document.body.style.overflow = document.body.classList.contains('sidebar-enable') ? 'hidden' : '';
        }
    }
    const mobileToggle = document.getElementById('mobileMenuToggle');
    const desktopToggle = document.getElementById('desktopSidebarToggle');
    if (mobileToggle) mobileToggle.addEventListener('click', toggleSidebar);
    if (desktopToggle) desktopToggle.addEventListener('click', toggleSidebar);

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

    // Submenu toggle – desktop only (mobile uses CSS)
    if (window.innerWidth > 992) {
        const menuItems = document.querySelectorAll('#side-menu > li.nav-item > a');
        menuItems.forEach(item => {
            const submenu = item.nextElementSibling;
            if (submenu && submenu.classList.contains('nav-second-level')) {
                item.addEventListener('click', function(e) {
                    e.preventDefault();
                    const parentLi = this.closest('li.nav-item');
                    document.querySelectorAll('#side-menu > li.nav-item').forEach(li => {
                        if (li !== parentLi && li.classList.contains('open')) li.classList.remove('open');
                    });
                    parentLi.classList.toggle('open');
                });
            }
        });
    }

    const savedState = localStorage.getItem('erp_sidebar_collapsed');
    if (savedState === 'true' && window.innerWidth > 992) {
        document.body.classList.add('erp-sidebar-collapsed');
    }

    setActiveMenu();
    console.log('✅ Doctor Sidebar V6 – Phase 5 Edition Loaded');
})();
</script>
</body>
</html>