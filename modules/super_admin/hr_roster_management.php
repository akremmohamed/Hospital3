<?php
/**
 * Super Admin - Human Resource & Roster Scheduling Module
 * HMS ERP System
 * 
 * @package HMS-ERP
 * @version 1.0
 */

if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../../config/db_connection.php';
require_once __DIR__ . '/../../includes/auth_helper.php';

// ============================================
// AUTHENTICATION & RBAC - SUPER ADMIN ONLY
// ============================================
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../index.php');
    exit;
}
if (($_SESSION['user_type'] ?? '') !== 'super_admin') {
    die('Access Denied. Super Admin privileges required.');
}

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

$branch_id = (int)($_SESSION['branch_id'] ?? 0);
$user_id   = (int)($_SESSION['user_id'] ?? 0);
$user_name = $_SESSION['first_name'] ?? $_SESSION['username'] ?? 'Super Admin';

$db_available = is_db_available();
if (!$db_available) die('Database connection failed.');

$pdo = getDB();

// ============================================
// AJAX ROUTER
// ============================================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'hr_roster_action') {
    error_reporting(0);
    ini_set('display_errors', 0);
    if (ob_get_level()) ob_clean();
    header('Content-Type: application/json; charset=utf-8');

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
        exit;
    }

    $submitted = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $submitted)) {
        echo json_encode(['success' => false, 'message' => 'Security validation failed. Refresh the page.']);
        exit;
    }

    $action = (string)($_POST['action'] ?? '');
    $db = getDB();
    if (!$db) {
        echo json_encode(['success' => false, 'message' => 'Database connection failed']);
        exit;
    }

    // ---------- GET EMPLOYEES FOR MATRIX ----------
    if ($action === 'get_employees_for_matrix') {
        $branch_filter = (int)($_POST['branch_id'] ?? 0);
        $sql = "SELECT u.id, u.first_name, u.last_name, u.user_type, d.name AS department_name
                FROM users u
                LEFT JOIN departments d ON u.department_id = d.id
                WHERE u.user_type IN ('doctor', 'nurse', 'pharmacist', 'laboratorist', 'radiologist')
                  AND u.deleted_at IS NULL
                  AND u.is_active = 1";
        if ($branch_filter > 0) {
            $sql .= " AND u.branch_id = :branch_id";
            $stmt = $db->prepare($sql);
            $stmt->execute([':branch_id' => $branch_filter]);
        } else {
            $stmt = $db->query($sql);
        }
        $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'employees' => $employees]);
        exit;
    }

    // ---------- GET MONTHLY ROSTER DATA ----------
    if ($action === 'get_month_rosters') {
        $year = (int)($_POST['year'] ?? date('Y'));
        $month = (int)($_POST['month'] ?? date('m'));
        $branch_filter = (int)($_POST['branch_id'] ?? 0);
        $start_date = date('Y-m-d', strtotime("$year-$month-01"));
        $end_date = date('Y-m-t', strtotime($start_date));

        $sql = "SELECT dr.id, dr.user_id, dr.duty_date, dr.shift_type_id, dr.department_id,
                       st.name AS shift_name, st.start_time, st.end_time,
                       d.name AS department_name
                FROM duty_rosters dr
                LEFT JOIN shift_types st ON dr.shift_type_id = st.id
                LEFT JOIN departments d ON dr.department_id = d.id
                WHERE dr.duty_date BETWEEN :start_date AND :end_date
                  AND dr.deleted_at IS NULL";
        if ($branch_filter > 0) {
            $sql .= " AND dr.branch_id = :branch_id";
        }
        $stmt = $db->prepare($sql);
        $params = [':start_date' => $start_date, ':end_date' => $end_date];
        if ($branch_filter > 0) {
            $params[':branch_id'] = $branch_filter;
        }
        $stmt->execute($params);
        $rosters = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'rosters' => $rosters]);
        exit;
    }

    // ---------- ASSIGN / UPDATE ROSTER NODE ----------
    if ($action === 'assign_roster_node') {
        $user_id_assign = (int)($_POST['user_id'] ?? 0);
        $duty_date = $_POST['duty_date'] ?? '';
        $shift_type_id = (int)($_POST['shift_type_id'] ?? 0);
        $department_id = (int)($_POST['department_id'] ?? 0);
        $branch_assign = (int)($_POST['branch_id'] ?? 0);

        if (!$user_id_assign || !$duty_date || !$shift_type_id) {
            echo json_encode(['success' => false, 'message' => 'Missing required fields.']);
            exit;
        }

        // Get shift details
        $shiftStmt = $db->prepare("SELECT name, start_time, end_time FROM shift_types WHERE id = :id AND deleted_at IS NULL");
        $shiftStmt->execute([':id' => $shift_type_id]);
        $shift = $shiftStmt->fetch(PDO::FETCH_ASSOC);
        if (!$shift) {
            echo json_encode(['success' => false, 'message' => 'Invalid shift type.']);
            exit;
        }

        $db->beginTransaction();
        try {
            // Check for existing assignment on same date for this user
            $checkStmt = $db->prepare("SELECT id FROM duty_rosters WHERE user_id = :uid AND duty_date = :date AND deleted_at IS NULL");
            $checkStmt->execute([':uid' => $user_id_assign, ':date' => $duty_date]);
            $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                // Update existing record
                $updateStmt = $db->prepare("UPDATE duty_rosters SET shift_type_id = :sid, department_id = :did, start_time = :st, end_time = :et, updated_at = NOW() WHERE id = :id");
                $updateStmt->execute([
                    ':sid' => $shift_type_id,
                    ':did' => $department_id ?: null,
                    ':st' => $shift['start_time'],
                    ':et' => $shift['end_time'],
                    ':id' => $existing['id']
                ]);
                $message = 'Roster updated successfully.';
            } else {
                // Insert new record
                $insertStmt = $db->prepare("INSERT INTO duty_rosters
                    (branch_id, department_id, user_id, shift_type_id, duty_date, start_time, end_time, duty_type, created_by_user_id, created_at, updated_at)
                    VALUES (:bid, :did, :uid, :sid, :date, :st, :et, 'regular', :cby, NOW(), NOW())");
                $insertStmt->execute([
                    ':bid' => $branch_assign,
                    ':did' => $department_id ?: null,
                    ':uid' => $user_id_assign,
                    ':sid' => $shift_type_id,
                    ':date' => $duty_date,
                    ':st' => $shift['start_time'],
                    ':et' => $shift['end_time'],
                    ':cby' => $user_id
                ]);
                $message = 'Roster assigned successfully.';
            }
            $db->commit();
            echo json_encode(['success' => true, 'message' => $message]);
        } catch (Exception $e) {
            $db->rollBack();
            echo json_encode(['success' => false, 'message' => 'Assignment failed: ' . $e->getMessage()]);
        }
        exit;
    }

    // ---------- DELETE ROSTER NODE ----------
    if ($action === 'delete_roster_node') {
        $roster_id = (int)($_POST['roster_id'] ?? 0);
        if (!$roster_id) {
            echo json_encode(['success' => false, 'message' => 'Invalid roster ID.']);
            exit;
        }
        $deleteStmt = $db->prepare("UPDATE duty_rosters SET deleted_at = NOW() WHERE id = :id");
        $deleteStmt->execute([':id' => $roster_id]);
        echo json_encode(['success' => true, 'message' => 'Roster entry deleted.']);
        exit;
    }

    // ---------- GET SHIFT TYPES (for dropdown) ----------
    if ($action === 'get_shifts') {
        $branch_filter = (int)($_POST['branch_id'] ?? 0);
        $sql = "SELECT id, name, start_time, end_time, code FROM shift_types WHERE deleted_at IS NULL AND is_active = 1";
        if ($branch_filter > 0) {
            $sql .= " AND branch_id = :branch_id";
            $stmt = $db->prepare($sql);
            $stmt->execute([':branch_id' => $branch_filter]);
        } else {
            $stmt = $db->query($sql);
        }
        $shifts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'shifts' => $shifts]);
        exit;
    }

    // ---------- SAVE SHIFT (CREATE / UPDATE) ----------
    if ($action === 'save_shift') {
        $shift_id = (int)($_POST['shift_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $code = trim($_POST['code'] ?? '');
        $start_time = $_POST['start_time'] ?? '';
        $end_time = $_POST['end_time'] ?? '';
        $branch_shift = (int)($_POST['branch_id'] ?? 0);

        if (!$name || !$code || !$start_time || !$end_time || !$branch_shift) {
            echo json_encode(['success' => false, 'message' => 'All fields are required.']);
            exit;
        }

        if ($start_time >= $end_time) {
            echo json_encode(['success' => false, 'message' => 'End time must be after start time.']);
            exit;
        }

        try {
            if ($shift_id > 0) {
                $stmt = $db->prepare("UPDATE shift_types SET name = :name, code = :code, start_time = :st, end_time = :et, updated_at = NOW() WHERE id = :id");
                $stmt->execute([':name' => $name, ':code' => $code, ':st' => $start_time, ':et' => $end_time, ':id' => $shift_id]);
                echo json_encode(['success' => true, 'message' => 'Shift updated successfully.']);
            } else {
                $stmt = $db->prepare("INSERT INTO shift_types (branch_id, name, code, start_time, end_time, is_active, created_by_user_id, created_at, updated_at)
                                      VALUES (:bid, :name, :code, :st, :et, 1, :uid, NOW(), NOW())");
                $stmt->execute([':bid' => $branch_shift, ':name' => $name, ':code' => $code, ':st' => $start_time, ':et' => $end_time, ':uid' => $user_id]);
                echo json_encode(['success' => true, 'message' => 'Shift created successfully.']);
            }
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        }
        exit;
    }

    // ---------- DELETE SHIFT ----------
    if ($action === 'delete_shift') {
        $shift_id = (int)($_POST['shift_id'] ?? 0);
        if (!$shift_id) {
            echo json_encode(['success' => false, 'message' => 'Invalid shift ID.']);
            exit;
        }
        $deleteStmt = $db->prepare("UPDATE shift_types SET deleted_at = NOW(), is_active = 0 WHERE id = :id");
        $deleteStmt->execute([':id' => $shift_id]);
        echo json_encode(['success' => true, 'message' => 'Shift deleted successfully.']);
        exit;
    }

    // ---------- GET EMPLOYEES (MASTER LIST) ----------
    if ($action === 'get_employees_master') {
        $branch_filter = (int)($_POST['branch_id'] ?? 0);
        $sql = "SELECT u.id, u.first_name, u.last_name, u.email, u.phone, u.user_type, u.is_active,
                       b.name AS branch_name, d.name AS department_name,
                       sp.license_number, sp.specialization, sp.job_title, sp.employment_status, sp.joining_date,
                       sp.emergency_contact_name, sp.emergency_contact_phone
                FROM users u
                LEFT JOIN branches b ON u.branch_id = b.id
                LEFT JOIN departments d ON u.department_id = d.id
                LEFT JOIN staff_profiles sp ON u.id = sp.user_id
                WHERE u.user_type NOT IN ('patient', 'super_admin')
                  AND u.deleted_at IS NULL";
        if ($branch_filter > 0) {
            $sql .= " AND u.branch_id = :branch_id";
            $stmt = $db->prepare($sql);
            $stmt->execute([':branch_id' => $branch_filter]);
        } else {
            $stmt = $db->query($sql);
        }
        $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'employees' => $employees]);
        exit;
    }

    // ---------- GET SINGLE EMPLOYEE DETAILS FOR EDIT ----------
    if ($action === 'get_employee_details') {
        $emp_id = (int)($_POST['employee_id'] ?? 0);
        if (!$emp_id) {
            echo json_encode(['success' => false, 'message' => 'Invalid employee ID.']);
            exit;
        }
        $stmt = $db->prepare("SELECT u.id, u.first_name, u.last_name, u.email, u.phone, u.user_type, u.branch_id, u.department_id,
                                     sp.license_number, sp.specialization, sp.job_title, sp.employment_status, sp.joining_date,
                                     sp.emergency_contact_name, sp.emergency_contact_phone
                              FROM users u
                              LEFT JOIN staff_profiles sp ON u.id = sp.user_id
                              WHERE u.id = :id AND u.deleted_at IS NULL");
        $stmt->execute([':id' => $emp_id]);
        $employee = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'employee' => $employee]);
        exit;
    }

    // ---------- UPDATE EMPLOYEE (MASTER) ----------
    if ($action === 'update_employee') {
        $emp_id = (int)($_POST['employee_id'] ?? 0);
        $first_name = trim($_POST['first_name'] ?? '');
        $last_name = trim($_POST['last_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $branch_emp = (int)($_POST['branch_id'] ?? 0);
        $department_emp = (int)($_POST['department_id'] ?? 0);
        $user_type = $_POST['user_type'] ?? '';
        $license_number = trim($_POST['license_number'] ?? '');
        $specialization = trim($_POST['specialization'] ?? '');
        $job_title = trim($_POST['job_title'] ?? '');
        $employment_status = $_POST['employment_status'] ?? 'active';
        $joining_date = $_POST['joining_date'] ?? null;
        $emergency_name = trim($_POST['emergency_contact_name'] ?? '');
        $emergency_phone = trim($_POST['emergency_contact_phone'] ?? '');

        if (!$emp_id || !$first_name || !$last_name || !$email || !$branch_emp) {
            echo json_encode(['success' => false, 'message' => 'Required fields missing.']);
            exit;
        }

        $db->beginTransaction();
        try {
            $updateUser = $db->prepare("UPDATE users SET first_name = :fn, last_name = :ln, email = :em, phone = :ph, branch_id = :bid, department_id = :did, user_type = :ut, updated_at = NOW() WHERE id = :id");
            $updateUser->execute([
                ':fn' => $first_name, ':ln' => $last_name, ':em' => $email, ':ph' => $phone ?: null,
                ':bid' => $branch_emp, ':did' => $department_emp ?: null, ':ut' => $user_type, ':id' => $emp_id
            ]);

            // Check if staff_profile exists
            $checkSp = $db->prepare("SELECT id FROM staff_profiles WHERE user_id = :uid");
            $checkSp->execute([':uid' => $emp_id]);
            if ($checkSp->fetch()) {
                $updateSp = $db->prepare("UPDATE staff_profiles SET license_number = :lic, specialization = :spec, job_title = :jt, employment_status = :est, joining_date = :jd, emergency_contact_name = :ecn, emergency_contact_phone = :ecp, updated_at = NOW() WHERE user_id = :uid");
                $updateSp->execute([
                    ':lic' => $license_number ?: null, ':spec' => $specialization ?: null, ':jt' => $job_title ?: null,
                    ':est' => $employment_status, ':jd' => $joining_date, ':ecn' => $emergency_name ?: null,
                    ':ecp' => $emergency_phone ?: null, ':uid' => $emp_id
                ]);
            } else {
                $insertSp = $db->prepare("INSERT INTO staff_profiles (user_id, license_number, specialization, job_title, employment_status, joining_date, emergency_contact_name, emergency_contact_phone, created_by_user_id, created_at, updated_at) VALUES (:uid, :lic, :spec, :jt, :est, :jd, :ecn, :ecp, :cby, NOW(), NOW())");
                $insertSp->execute([
                    ':uid' => $emp_id, ':lic' => $license_number ?: null, ':spec' => $specialization ?: null,
                    ':jt' => $job_title ?: null, ':est' => $employment_status, ':jd' => $joining_date,
                    ':ecn' => $emergency_name ?: null, ':ecp' => $emergency_phone ?: null, ':cby' => $user_id
                ]);
            }
            $db->commit();
            echo json_encode(['success' => true, 'message' => 'Employee updated successfully.']);
        } catch (Exception $e) {
            $db->rollBack();
            echo json_encode(['success' => false, 'message' => 'Update failed: ' . $e->getMessage()]);
        }
        exit;
    }

    // ---------- CREATE NEW EMPLOYEE ----------
    if ($action === 'create_employee') {
        $first_name = trim($_POST['first_name'] ?? '');
        $last_name = trim($_POST['last_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $branch_emp = (int)($_POST['branch_id'] ?? 0);
        $department_emp = (int)($_POST['department_id'] ?? 0);
        $user_type = $_POST['user_type'] ?? 'receptionist';
        $license_number = trim($_POST['license_number'] ?? '');
        $specialization = trim($_POST['specialization'] ?? '');
        $job_title = trim($_POST['job_title'] ?? '');
        $employment_status = $_POST['employment_status'] ?? 'active';
        $joining_date = $_POST['joining_date'] ?? date('Y-m-d');
        $emergency_name = trim($_POST['emergency_contact_name'] ?? '');
        $emergency_phone = trim($_POST['emergency_contact_phone'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $password_raw = trim($_POST['password'] ?? '');
        $default_password = $password_raw ?: 'ChangeMe@123';

        if (!$first_name || !$last_name || !$email || !$branch_emp || !$username) {
            echo json_encode(['success' => false, 'message' => 'Required fields missing.']);
            exit;
        }

        $password_hash = password_hash($default_password, PASSWORD_DEFAULT);
        $db->beginTransaction();
        try {
            $insertUser = $db->prepare("INSERT INTO users (branch_id, department_id, username, email, password_hash, first_name, last_name, phone, user_type, is_active, created_by_user_id, created_at, updated_at) VALUES (:bid, :did, :un, :em, :pwd, :fn, :ln, :ph, :ut, 1, :cby, NOW(), NOW())");
            $insertUser->execute([
                ':bid' => $branch_emp, ':did' => $department_emp ?: null, ':un' => $username, ':em' => $email,
                ':pwd' => $password_hash, ':fn' => $first_name, ':ln' => $last_name, ':ph' => $phone ?: null,
                ':ut' => $user_type, ':cby' => $user_id
            ]);
            $new_emp_id = $db->lastInsertId();

            $insertSp = $db->prepare("INSERT INTO staff_profiles (user_id, license_number, specialization, job_title, employment_status, joining_date, emergency_contact_name, emergency_contact_phone, created_by_user_id, created_at, updated_at) VALUES (:uid, :lic, :spec, :jt, :est, :jd, :ecn, :ecp, :cby, NOW(), NOW())");
            $insertSp->execute([
                ':uid' => $new_emp_id, ':lic' => $license_number ?: null, ':spec' => $specialization ?: null,
                ':jt' => $job_title ?: null, ':est' => $employment_status, ':jd' => $joining_date,
                ':ecn' => $emergency_name ?: null, ':ecp' => $emergency_phone ?: null, ':cby' => $user_id
            ]);
            $db->commit();
            echo json_encode(['success' => true, 'message' => "Employee created successfully. Default password: $default_password"]);
        } catch (Exception $e) {
            $db->rollBack();
            echo json_encode(['success' => false, 'message' => 'Creation failed: ' . $e->getMessage()]);
        }
        exit;
    }

    // ---------- TOGGLE EMPLOYEE ACTIVE STATUS ----------
    if ($action === 'toggle_employee_status') {
        $emp_id = (int)($_POST['employee_id'] ?? 0);
        $current = (int)($_POST['current_status'] ?? 1);
        $new_status = $current ? 0 : 1;
        $stmt = $db->prepare("UPDATE users SET is_active = :stat, updated_at = NOW() WHERE id = :id");
        $stmt->execute([':stat' => $new_status, ':id' => $emp_id]);
        echo json_encode(['success' => true, 'message' => 'Employee status updated.']);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action']);
    exit;
}

// ============================================
// BRANCH LIST FOR SUPER ADMIN FILTER
// ============================================
$branches = [];
if ($pdo) {
    $branchStmt = $pdo->query("SELECT id, name FROM branches WHERE deleted_at IS NULL AND status = 'active' ORDER BY name");
    $branches = $branchStmt->fetchAll(PDO::FETCH_ASSOC);
}
$current_date_display = date('l, F j, Y');
$current_time_display = date('g:i A');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HR & Roster Management - Super Admin | HMS ERP</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root {
            --primary: #4361ee;
            --primary-dark: #3a0ca3;
            --success: #06d6a0;
            --warning: #f9c74f;
            --danger: #ef476f;
            --info: #4cc9f0;
            --dark: #1a1a2e;
            --gray: #6c757d;
            --gray-light: #e9ecef;
        }
        body { font-family: 'Inter', sans-serif; background: linear-gradient(135deg, #f5f7fb 0%, #eef2f6 100%); overflow-x: hidden; }
        .content-page { margin-left: 260px; margin-top: 70px; padding: 24px 32px; transition: margin-left 0.3s; min-height: calc(100vh - 70px); }
        body.erp-sidebar-collapsed .content-page { margin-left: 80px; }
        @media (max-width: 768px) { .content-page { margin-left: 0 !important; } }
        .welcome-header { background: linear-gradient(135deg, var(--primary-dark) 0%, var(--primary) 100%); border-radius: 24px; padding: 24px 32px; color: white; margin-bottom: 28px; }
        .welcome-title { font-size: 24px; font-weight: 700; margin-bottom: 4px; }
        .stat-card { background: white; border-radius: 20px; padding: 20px; margin-bottom: 20px; border: 1px solid var(--gray-light); }
        .filter-bar { background: white; border-radius: 20px; padding: 16px 24px; margin-bottom: 24px; display: flex; gap: 16px; flex-wrap: wrap; align-items: flex-end; }
        .roster-matrix-container { overflow-x: auto; background: white; border-radius: 20px; padding: 20px; border: 1px solid var(--gray-light); }
        .roster-table { min-width: 800px; width: 100%; border-collapse: collapse; font-size: 13px; }
        .roster-table th, .roster-table td { border: 1px solid var(--gray-light); padding: 10px 8px; vertical-align: middle; text-align: center; }
        .roster-table th { background: #f8fafc; font-weight: 600; position: sticky; top: 0; }
        .roster-table .staff-cell { background: #f8fafc; font-weight: 600; text-align: left; position: sticky; left: 0; background-color: white; z-index: 1; }
        .shift-badge { background: #e0e7ff; color: var(--primary); border-radius: 20px; padding: 4px 8px; font-size: 11px; font-weight: 600; display: inline-block; cursor: pointer; transition: 0.2s; }
        .shift-badge:hover { background: var(--primary); color: white; }
        .empty-cell { background: #fef9e3; cursor: pointer; }
        .empty-cell:hover { background: #fff3cd; }
        .btn-sm-custom { padding: 4px 12px; font-size: 12px; border-radius: 12px; }
        .quick-btn { padding: 10px 20px; border-radius: 14px; font-size: 14px; font-weight: 600; border: none; cursor: pointer; transition: all 0.2s; }
        .quick-btn-primary { background: linear-gradient(135deg, var(--primary), var(--primary-dark)); color: white; }
        .quick-btn-outline { background: white; border: 1px solid var(--gray-light); color: var(--dark); }
        .loading-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(255,255,255,0.7); z-index: 2000; display: none; align-items: center; justify-content: center; }
        .loading-overlay.active { display: flex; }
        .spinner { width: 40px; height: 40px; border: 3px solid var(--gray-light); border-top-color: var(--primary); border-radius: 50%; animation: spin 0.8s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }
    </style>
</head>
<body>
<div id="wrapper">
    <?php include('assets/inc/nav.php'); ?>
    <?php include('assets/inc/sidebar.php'); ?>
    <div class="content-page">
        <div class="container-fluid">
            <div class="welcome-header">
                <div class="row align-items-center">
                    <div class="col-md-8">
                        <div class="welcome-title">Human Resource & Roster Scheduling</div>
                        <div class="welcome-subtitle">Super Admin Console: Manage employees, shifts, and duty rosters.</div>
                    </div>
                    <div class="col-md-4 text-md-end mt-3 mt-md-0">
                        <div class="time-badge" style="background: rgba(255,255,255,0.15); padding: 8px 16px; border-radius: 12px;">
                            <i class="far fa-calendar-alt"></i> <?php echo htmlspecialchars($current_date_display); ?>
                            <i class="far fa-clock ms-2"></i> <?php echo htmlspecialchars($current_time_display); ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Branch Filter (Super Admin) -->
            <div class="filter-bar">
                <div style="min-width: 200px;">
                    <label class="form-label fw-semibold">Select Branch</label>
                    <select id="globalBranchFilter" class="form-select">
                        <option value="0">All Branches</option>
                        <?php foreach ($branches as $branch): ?>
                        <option value="<?php echo $branch['id']; ?>"><?php echo htmlspecialchars($branch['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="form-label fw-semibold">Month for Roster</label>
                    <input type="month" id="rosterMonthPicker" class="form-control" value="<?php echo date('Y-m'); ?>">
                </div>
                <div>
                    <button class="quick-btn quick-btn-primary" id="refreshRosterBtn"><i class="fas fa-sync-alt me-2"></i>Refresh Roster</button>
                </div>
            </div>

            <!-- Tabs -->
            <ul class="nav nav-tabs mb-4" id="hrTabs" role="tablist">
                <li class="nav-item"><button class="nav-link active" id="employees-tab" data-bs-toggle="tab" data-bs-target="#employees">Employee Master File</button></li>
                <li class="nav-item"><button class="nav-link" id="shifts-tab" data-bs-toggle="tab" data-bs-target="#shifts">Shift Configuration</button></li>
                <li class="nav-item"><button class="nav-link" id="roster-tab" data-bs-toggle="tab" data-bs-target="#roster">Roster Assignment Matrix</button></li>
            </ul>
            <div class="tab-content">
                <!-- EMPLOYEE MASTER FILE TAB -->
                <div class="tab-pane fade show active" id="employees">
                    <div class="stat-card">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h5 class="mb-0"><i class="fas fa-users me-2"></i>Staff Directory</h5>
                            <button class="quick-btn quick-btn-primary" id="addEmployeeBtn"><i class="fas fa-plus me-2"></i>Add New Employee</button>
                        </div>
                        <div class="table-responsive">
                            <table id="employeesTable" class="table table-hover" style="width:100%">
                                <thead>
                                    <tr><th>ID</th><th>Name</th><th>Email</th><th>Phone</th><th>Branch</th><th>Department</th><th>Role</th><th>License</th><th>Status</th><th>Actions</th></tr>
                                </thead>
                                <tbody><tr><td colspan="10" class="text-center">Loading...</td></tr></tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- SHIFT CONFIGURATION TAB -->
                <div class="tab-pane fade" id="shifts">
                    <div class="stat-card">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h5 class="mb-0"><i class="fas fa-clock me-2"></i>Shift Types Master</h5>
                            <button class="quick-btn quick-btn-primary" id="addShiftBtn"><i class="fas fa-plus me-2"></i>New Shift</button>
                        </div>
                        <div class="table-responsive">
                            <table id="shiftsTable" class="table table-hover">
                                <thead><tr><th>ID</th><th>Name</th><th>Code</th><th>Start Time</th><th>End Time</th><th>Actions</th></tr></thead>
                                <tbody><tr><td colspan="6" class="text-center">Loading shifts...</td></tr></tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- ROSTER ASSIGNMENT MATRIX TAB -->
                <div class="tab-pane fade" id="roster">
                    <div class="roster-matrix-container">
                        <div id="rosterMatrixGrid">Loading roster matrix...</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php include('assets/inc/footer.php'); ?>
</div>

<div id="loadingOverlay" class="loading-overlay"><div class="spinner"></div></div>

<!-- MODAL: Employee Add/Edit -->
<div id="employeeModal" class="modal fade" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header"><h5 id="employeeModalTitle">Add Employee</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <form id="employeeForm">
                    <input type="hidden" name="employee_id" id="empId">
                    <div class="row">
                        <div class="col-md-6"><div class="mb-2"><label>First Name *</label><input type="text" name="first_name" id="empFirstName" class="form-control" required></div></div>
                        <div class="col-md-6"><div class="mb-2"><label>Last Name *</label><input type="text" name="last_name" id="empLastName" class="form-control" required></div></div>
                        <div class="col-md-6"><div class="mb-2"><label>Email *</label><input type="email" name="email" id="empEmail" class="form-control" required></div></div>
                        <div class="col-md-6"><div class="mb-2"><label>Phone</label><input type="text" name="phone" id="empPhone" class="form-control"></div></div>
                        <div class="col-md-6"><div class="mb-2"><label>Username *</label><input type="text" name="username" id="empUsername" class="form-control"></div></div>
                        <div class="col-md-6"><div class="mb-2"><label>Password (leave blank for default)</label><input type="password" name="password" id="empPassword" class="form-control" placeholder="ChangeMe@123"></div></div>
                        <div class="col-md-6"><div class="mb-2"><label>Branch *</label><select name="branch_id" id="empBranch" class="form-select" required><?php foreach($branches as $b){ echo "<option value='{$b['id']}'>{$b['name']}</option>"; } ?></select></div></div>
                        <div class="col-md-6"><div class="mb-2"><label>Department</label><select name="department_id" id="empDepartment" class="form-select"><option value="0">None</option></select></div></div>
                        <div class="col-md-6"><div class="mb-2"><label>User Role *</label><select name="user_type" id="empUserType" class="form-select" required><option value="doctor">Doctor</option><option value="nurse">Nurse</option><option value="pharmacist">Pharmacist</option><option value="laboratorist">Laboratorist</option><option value="receptionist">Receptionist</option><option value="accountant">Accountant</option></select></div></div>
                        <div class="col-md-6"><div class="mb-2"><label>License Number</label><input type="text" name="license_number" id="empLicense" class="form-control"></div></div>
                        <div class="col-md-6"><div class="mb-2"><label>Specialization</label><input type="text" name="specialization" id="empSpecialization" class="form-control"></div></div>
                        <div class="col-md-6"><div class="mb-2"><label>Job Title</label><input type="text" name="job_title" id="empJobTitle" class="form-control"></div></div>
                        <div class="col-md-6"><div class="mb-2"><label>Employment Status</label><select name="employment_status" id="empEmploymentStatus" class="form-select"><option value="active">Active</option><option value="on_leave">On Leave</option><option value="suspended">Suspended</option></select></div></div>
                        <div class="col-md-6"><div class="mb-2"><label>Joining Date</label><input type="date" name="joining_date" id="empJoiningDate" class="form-control"></div></div>
                        <div class="col-md-6"><div class="mb-2"><label>Emergency Contact Name</label><input type="text" name="emergency_contact_name" id="empEmergencyName" class="form-control"></div></div>
                        <div class="col-md-6"><div class="mb-2"><label>Emergency Contact Phone</label><input type="text" name="emergency_contact_phone" id="empEmergencyPhone" class="form-control"></div></div>
                    </div>
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                </form>
            </div>
            <div class="modal-footer"><button class="quick-btn quick-btn-outline" data-bs-dismiss="modal">Cancel</button><button class="quick-btn quick-btn-primary" id="saveEmployeeBtn">Save Employee</button></div>
        </div>
    </div>
</div>

<!-- MODAL: Shift Add/Edit -->
<div id="shiftModal" class="modal fade" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content"><div class="modal-header"><h5 id="shiftModalTitle">Shift Configuration</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body"><form id="shiftForm"><input type="hidden" name="shift_id" id="shiftId"><div class="mb-2"><label>Shift Name</label><input type="text" name="name" id="shiftName" class="form-control" required></div><div class="mb-2"><label>Code</label><input type="text" name="code" id="shiftCode" class="form-control" required></div><div class="mb-2"><label>Start Time</label><input type="time" name="start_time" id="shiftStart" class="form-control" required></div><div class="mb-2"><label>End Time</label><input type="time" name="end_time" id="shiftEnd" class="form-control" required></div><div class="mb-2"><label>Branch</label><select name="branch_id" id="shiftBranch" class="form-select"><?php foreach($branches as $b){ echo "<option value='{$b['id']}'>{$b['name']}</option>"; } ?></select></div><input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>"></form></div>
    <div class="modal-footer"><button class="quick-btn quick-btn-outline" data-bs-dismiss="modal">Cancel</button><button class="quick-btn quick-btn-primary" id="saveShiftBtn">Save Shift</button></div></div></div>
</div>

<!-- MODAL: Roster Assignment -->
<div id="rosterAssignModal" class="modal fade" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><div class="modal-header"><h5>Assign Shift</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
<div class="modal-body"><form id="rosterAssignForm"><input type="hidden" id="assignUserId"><input type="hidden" id="assignDate"><div class="mb-2"><label>Staff Member</label><input type="text" id="assignStaffName" class="form-control" disabled></div><div class="mb-2"><label>Duty Date</label><input type="text" id="assignDateDisplay" class="form-control" disabled></div><div class="mb-2"><label>Shift Type</label><select id="assignShiftId" class="form-select" required><option value="">Select Shift</option></select></div><div class="mb-2"><label>Department (optional)</label><select id="assignDepartmentId" class="form-select"><option value="0">None</option></select></div><input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>"><input type="hidden" id="assignBranchId" value="0"></form></div>
<div class="modal-footer"><button class="quick-btn quick-btn-outline" data-bs-dismiss="modal">Cancel</button><button class="quick-btn quick-btn-primary" id="confirmAssignBtn">Assign</button><button class="quick-btn quick-btn-danger" id="deleteAssignBtn" style="display:none;">Delete Assignment</button></div></div></div></div>

<script src="assets/js/vendor.min.js"></script>
<script src="assets/js/app.min.js"></script>
<script>
const csrfToken = "<?php echo $csrf_token; ?>";
let employeesTable, shiftsTable;
let currentRosterBranch = 0, currentRosterMonth = '<?php echo date('Y-m'); ?>';
let currentMatrixEmployees = [], currentRosters = [];

$(function(){
    loadDepartmentsForSelect();
    loadShiftsTable();
    loadEmployeesTable();
    $('#globalBranchFilter').on('change', function(){ loadEmployeesTable(); loadShiftsTable(); refreshRosterMatrix(); });
    $('#rosterMonthPicker').on('change', function(){ refreshRosterMatrix(); });
    $('#refreshRosterBtn').on('click', refreshRosterMatrix);
    $('#addEmployeeBtn').on('click', function(){ resetEmployeeForm(); $('#employeeModal').modal('show'); });
    $('#addShiftBtn').on('click', function(){ $('#shiftId').val(''); $('#shiftName, #shiftCode, #shiftStart, #shiftEnd').val(''); $('#shiftModal').modal('show'); });
    $('#saveEmployeeBtn').on('click', saveEmployee);
    $('#saveShiftBtn').on('click', saveShift);
    $('#confirmAssignBtn').on('click', submitAssignment);
    $('#deleteAssignBtn').on('click', deleteAssignment);
    refreshRosterMatrix();
});

function loadDepartmentsForSelect() {
    $.ajax({ url: window.location.pathname + '?ajax=hr_roster_action', method: 'POST', dataType: 'json', data: { action: 'get_departments', branch_id: 0, csrf_token: csrfToken }, success: function(res){
        if(res.success && res.departments){
            let opts = '<option value="0">None</option>';
            res.departments.forEach(d => { opts += `<option value="${d.id}">${escapeHtml(d.name)}</option>`; });
            $('#empDepartment, #assignDepartmentId').html(opts);
        }
    }});
}

function loadEmployeesTable() {
    let branch = $('#globalBranchFilter').val();
    $.ajax({ url: window.location.pathname + '?ajax=hr_roster_action', method: 'POST', dataType: 'json', data: { action: 'get_employees_master', branch_id: branch, csrf_token: csrfToken }, success: function(res){
        if(res.success && res.employees){
            let rows = '';
            res.employees.forEach(emp => {
                let statusBadge = emp.is_active ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>';
                rows += `<tr>
                    <td>${emp.id}</td>
                    <td>${escapeHtml(emp.first_name)} ${escapeHtml(emp.last_name)}</td>
                    <td>${escapeHtml(emp.email)}</td>
                    <td>${escapeHtml(emp.phone)}</td>
                    <td>${escapeHtml(emp.branch_name)}</td>
                    <td>${escapeHtml(emp.department_name)}</td>
                    <td>${emp.user_type}</td>
                    <td>${escapeHtml(emp.license_number)}</td>
                    <td>${statusBadge}</td>
                    <td><button class="btn btn-sm btn-outline-primary edit-employee-btn" data-id="${emp.id}"><i class="fas fa-edit"></i></button> <button class="btn btn-sm btn-outline-secondary toggle-status-btn" data-id="${emp.id}" data-status="${emp.is_active}"><i class="fas fa-power-off"></i></button></td>
                </tr>`;
            });
            $('#employeesTable tbody').html(rows);
            $('.edit-employee-btn').on('click', function(){ loadEmployeeForEdit($(this).data('id')); });
            $('.toggle-status-btn').on('click', function(){ toggleEmployeeStatus($(this).data('id'), $(this).data('status')); });
        } else { $('#employeesTable tbody').html('<tr><td colspan="10">No employees found.</td></tr>'); }
    }});
}

function loadEmployeeForEdit(id) {
    $.ajax({ url: window.location.pathname + '?ajax=hr_roster_action', method: 'POST', dataType: 'json', data: { action: 'get_employee_details', employee_id: id, csrf_token: csrfToken }, success: function(res){
        if(res.success && res.employee){
            let e = res.employee;
            $('#empId').val(e.id); $('#empFirstName').val(e.first_name); $('#empLastName').val(e.last_name); $('#empEmail').val(e.email); $('#empPhone').val(e.phone);
            $('#empBranch').val(e.branch_id); $('#empDepartment').val(e.department_id || 0); $('#empUserType').val(e.user_type);
            $('#empLicense').val(e.license_number); $('#empSpecialization').val(e.specialization); $('#empJobTitle').val(e.job_title);
            $('#empEmploymentStatus').val(e.employment_status); $('#empJoiningDate').val(e.joining_date); $('#empEmergencyName').val(e.emergency_contact_name);
            $('#empEmergencyPhone').val(e.emergency_contact_phone); $('#empUsername').val(''); $('#empPassword').val('');
            $('#employeeModalTitle').text('Edit Employee'); $('#employeeModal').modal('show');
        }
    }});
}
function resetEmployeeForm() { $('#employeeForm')[0].reset(); $('#empId').val(''); $('#employeeModalTitle').text('Add Employee'); $('#empUsername').val(''); $('#empPassword').val(''); }
function saveEmployee() {
    let formData = $('#employeeForm').serialize();
    let action = $('#empId').val() ? 'update_employee' : 'create_employee';
    formData += '&action=' + action;
    showLoading(true);
    $.ajax({ url: window.location.pathname + '?ajax=hr_roster_action', method: 'POST', data: formData, dataType: 'json', success: function(res){
        showLoading(false);
        if(res.success){ Swal.fire('Success', res.message, 'success'); $('#employeeModal').modal('hide'); loadEmployeesTable(); }
        else Swal.fire('Error', res.message, 'error');
    }, error: function(){ showLoading(false); Swal.fire('Error', 'Network error', 'error'); } });
}
function toggleEmployeeStatus(id, current){
    Swal.fire({ title: 'Confirm', text: 'Toggle active status?', icon: 'question', showCancelButton: true }).then(res=>{
        if(res.isConfirmed){
            $.ajax({ url: window.location.pathname + '?ajax=hr_roster_action', method: 'POST', dataType: 'json', data: { action: 'toggle_employee_status', employee_id: id, current_status: current, csrf_token: csrfToken }, success: function(r){
                if(r.success){ loadEmployeesTable(); Swal.fire('Updated', r.message, 'success'); } else Swal.fire('Error', r.message, 'error');
            }});
        }
    });
}
function loadShiftsTable() {
    let branch = $('#globalBranchFilter').val();
    $.ajax({ url: window.location.pathname + '?ajax=hr_roster_action', method: 'POST', dataType: 'json', data: { action: 'get_shifts', branch_id: branch, csrf_token: csrfToken }, success: function(res){
        if(res.success && res.shifts){
            let rows = '';
            res.shifts.forEach(s => { rows += `<tr><td>${s.id}</td><td>${escapeHtml(s.name)}</td><td>${escapeHtml(s.code)}</td><td>${s.start_time}</td><td>${s.end_time}</td><td><button class="btn btn-sm btn-warning edit-shift" data-id="${s.id}" data-name="${escapeHtml(s.name)}" data-code="${escapeHtml(s.code)}" data-start="${s.start_time}" data-end="${s.end_time}"><i class="fas fa-edit"></i></button> <button class="btn btn-sm btn-danger delete-shift" data-id="${s.id}"><i class="fas fa-trash"></i></button></td></tr>`; });
            $('#shiftsTable tbody').html(rows);
            $('.edit-shift').on('click', function(){ openShiftEdit($(this).data('id'), $(this).data('name'), $(this).data('code'), $(this).data('start'), $(this).data('end')); });
            $('.delete-shift').on('click', function(){ deleteShift($(this).data('id')); });
        } else { $('#shiftsTable tbody').html('<tr><td colspan="6">No shifts configured.</td></tr>'); }
    }});
}
function openShiftEdit(id, name, code, start, end){ $('#shiftId').val(id); $('#shiftName').val(name); $('#shiftCode').val(code); $('#shiftStart').val(start); $('#shiftEnd').val(end); $('#shiftModal').modal('show'); }
function saveShift(){
    let data = $('#shiftForm').serialize() + '&action=save_shift';
    showLoading(true);
    $.ajax({ url: window.location.pathname + '?ajax=hr_roster_action', method: 'POST', data: data, dataType: 'json', success: function(res){
        showLoading(false); if(res.success){ Swal.fire('Success', res.message, 'success'); $('#shiftModal').modal('hide'); loadShiftsTable(); } else Swal.fire('Error', res.message, 'error');
    }, error: function(){ showLoading(false); Swal.fire('Error', 'Network error', 'error'); } });
}
function deleteShift(id){
    Swal.fire({ title: 'Delete Shift?', text: 'This will soft-delete the shift. Existing rosters may be affected.', icon: 'warning', showCancelButton: true }).then(res=>{
        if(res.isConfirmed){
            $.ajax({ url: window.location.pathname + '?ajax=hr_roster_action', method: 'POST', dataType: 'json', data: { action: 'delete_shift', shift_id: id, csrf_token: csrfToken }, success: function(r){
                if(r.success){ loadShiftsTable(); Swal.fire('Deleted', r.message, 'success'); } else Swal.fire('Error', r.message, 'error');
            }});
        }
    });
}
function refreshRosterMatrix() {
    currentRosterBranch = $('#globalBranchFilter').val();
    currentRosterMonth = $('#rosterMonthPicker').val();
    if(!currentRosterMonth) return;
    showLoading(true);
    Promise.all([
        $.ajax({ url: window.location.pathname + '?ajax=hr_roster_action', method: 'POST', dataType: 'json', data: { action: 'get_employees_for_matrix', branch_id: currentRosterBranch, csrf_token: csrfToken } }),
        $.ajax({ url: window.location.pathname + '?ajax=hr_roster_action', method: 'POST', dataType: 'json', data: { action: 'get_month_rosters', year: currentRosterMonth.split('-')[0], month: currentRosterMonth.split('-')[1], branch_id: currentRosterBranch, csrf_token: csrfToken } })
    ]).then(([empRes, rosterRes]) => {
        showLoading(false);
        if(empRes.success) currentMatrixEmployees = empRes.employees;
        if(rosterRes.success) currentRosters = rosterRes.rosters;
        renderRosterMatrix();
    }).catch(()=>{ showLoading(false); $('#rosterMatrixGrid').html('<div class="alert alert-danger">Failed to load roster data.</div>'); });
}
function renderRosterMatrix() {
    if(!currentMatrixEmployees.length){ $('#rosterMatrixGrid').html('<div class="alert alert-info">No clinical staff found for selected branch.</div>'); return; }
    let yearMonth = currentRosterMonth.split('-');
    let year = parseInt(yearMonth[0]), month = parseInt(yearMonth[1])-1;
    let daysInMonth = new Date(year, month+1, 0).getDate();
    let daysArray = [];
    for(let d=1; d<=daysInMonth; d++) daysArray.push(d);
    let html = '<table class="roster-table"><thead><tr><th style="min-width:180px">Staff / Date</th>';
    daysArray.forEach(day => html += `<th>${day}</th>`);
    html += '</tr></thead><tbody>';
    currentMatrixEmployees.forEach(emp => {
        html += `<tr><td class="staff-cell"><strong>${escapeHtml(emp.first_name)} ${escapeHtml(emp.last_name)}</strong><br><small>${emp.user_type}</small></td>`;
        daysArray.forEach(day => {
            let dateStr = `${year}-${String(month+1).padStart(2,'0')}-${String(day).padStart(2,'0')}`;
            let roster = currentRosters.find(r => r.user_id == emp.id && r.duty_date == dateStr);
            let cellContent = '';
            if(roster){
                cellContent = `<span class="shift-badge" data-roster-id="${roster.id}" data-user-id="${emp.id}" data-date="${dateStr}" data-shift-id="${roster.shift_type_id}" data-dept-id="${roster.department_id || 0}">${escapeHtml(roster.shift_name || 'Shift')}<br><small>${roster.start_time} - ${roster.end_time}</small></span>`;
            } else {
                cellContent = `<div class="empty-cell" data-user-id="${emp.id}" data-date="${dateStr}" style="padding:8px; border-radius:8px;">+ Assign</div>`;
            }
            html += `<td>${cellContent}</td>`;
        });
        html += '</tr>';
    });
    html += '</tbody></table>';
    $('#rosterMatrixGrid').html(html);
    $('.shift-badge').on('click', function(){ openRosterAssignModal($(this).data('user-id'), $(this).data('date'), $(this).data('roster-id'), $(this).data('shift-id'), $(this).data('dept-id')); });
    $('.empty-cell').on('click', function(){ openRosterAssignModal($(this).data('user-id'), $(this).data('date'), 0, 0, 0); });
}
function openRosterAssignModal(userId, date, rosterId, shiftId, deptId) {
    let staff = currentMatrixEmployees.find(e => e.id == userId);
    if(!staff) return;
    $('#assignUserId').val(userId);
    $('#assignDate').val(date);
    $('#assignStaffName').val(staff.first_name + ' ' + staff.last_name);
    $('#assignDateDisplay').val(date);
    $('#assignBranchId').val(currentRosterBranch);
    let branchForShifts = currentRosterBranch || 0;
    $.ajax({ url: window.location.pathname + '?ajax=hr_roster_action', method: 'POST', dataType: 'json', data: { action: 'get_shifts', branch_id: branchForShifts, csrf_token: csrfToken }, success: function(res){
        if(res.success && res.shifts){
            let opts = '<option value="">Select Shift</option>';
            res.shifts.forEach(s => { opts += `<option value="${s.id}" ${s.id == shiftId ? 'selected' : ''}>${escapeHtml(s.name)} (${s.start_time}-${s.end_time})</option>`; });
            $('#assignShiftId').html(opts);
        } else $('#assignShiftId').html('<option value="">No shifts available</option>');
    }});
    if(rosterId > 0) $('#deleteAssignBtn').show().data('roster-id', rosterId);
    else $('#deleteAssignBtn').hide();
    $('#rosterAssignModal').modal('show');
}
function submitAssignment() {
    let userId = $('#assignUserId').val();
    let dutyDate = $('#assignDate').val();
    let shiftId = $('#assignShiftId').val();
    let deptId = $('#assignDepartmentId').val();
    if(!userId || !dutyDate || !shiftId){ Swal.fire('Error', 'Please select shift', 'error'); return; }
    showLoading(true);
    $.ajax({ url: window.location.pathname + '?ajax=hr_roster_action', method: 'POST', dataType: 'json', data: { action: 'assign_roster_node', user_id: userId, duty_date: dutyDate, shift_type_id: shiftId, department_id: deptId, branch_id: currentRosterBranch, csrf_token: csrfToken }, success: function(res){
        showLoading(false);
        if(res.success){ Swal.fire('Success', res.message, 'success'); $('#rosterAssignModal').modal('hide'); refreshRosterMatrix(); }
        else Swal.fire('Error', res.message, 'error');
    }, error: function(){ showLoading(false); Swal.fire('Error', 'Network error', 'error'); } });
}
function deleteAssignment(){
    let rosterId = $('#deleteAssignBtn').data('roster-id');
    if(!rosterId) return;
    Swal.fire({ title: 'Remove Assignment?', icon: 'warning', showCancelButton: true }).then(res=>{
        if(res.isConfirmed){
            showLoading(true);
            $.ajax({ url: window.location.pathname + '?ajax=hr_roster_action', method: 'POST', dataType: 'json', data: { action: 'delete_roster_node', roster_id: rosterId, csrf_token: csrfToken }, success: function(r){
                showLoading(false); if(r.success){ Swal.fire('Deleted', r.message, 'success'); $('#rosterAssignModal').modal('hide'); refreshRosterMatrix(); } else Swal.fire('Error', r.message, 'error');
            }, error: function(){ showLoading(false); Swal.fire('Error', 'Network error', 'error'); } });
        }
    });
}
function escapeHtml(str) { if(!str) return ''; return String(str).replace(/[&<>]/g, m => m === '&' ? '&amp;' : (m === '<' ? '&lt;' : '&gt;')); }
function showLoading(show){ if(show) $('#loadingOverlay').addClass('active'); else $('#loadingOverlay').removeClass('active'); }
</script>
</body>
</html>