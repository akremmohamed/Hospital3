<?php
/**
 * Update Appointment - HMS ERP System
 * Refactored for centralized DB connectivity and RBAC enforcement
 * 
 * @package HMS-ERP
 * @version 2.0
 * @requires appointment_edit_access permission
 */

// Start session before any output
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Load configuration and helpers
require_once __DIR__ . '/../../config/db_connection.php';
require_once __DIR__ . '/../../includes/auth_helper.php';

// Set timezone
date_default_timezone_set('Africa/Addis_Ababa');

// Enable error reporting for debugging (remove in production)
error_reporting(E_ALL);
ini_set('display_errors', 0);

// ============================================
// AUTHENTICATION & AUTHORIZATION
// ============================================

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../index.php');
    exit;
}

// Get user information from session
$user_id = (int)($_SESSION['user_id'] ?? 0);
$user_branch_id = (int)($_SESSION['branch_id'] ?? 0);
$is_global_admin = ($_SESSION['user_type'] ?? '') === 'super_admin';
$user_name = $_SESSION['first_name'] ?? $_SESSION['username'] ?? 'User';

// Check database availability
global $pdo;
$db_available = ($pdo instanceof PDO);

if (!$db_available) {
    die('Database connection failed. Please check your configuration.');
}

// Permission check for editing appointments
$can_edit_appointment = $is_global_admin;

if (!$is_global_admin && $db_available && $user_id > 0) {
    $can_edit_appointment = user_has_permission($pdo, 'appointment_edit_access', $user_id);
}

// If user has no permission, redirect
if (!$can_edit_appointment) {
    header('Location: dashboard.php?error=unauthorized');
    exit;
}

// Get appointment ID from request
$appointment_id = (int)($_GET['id'] ?? 0);

if ($appointment_id <= 0) {
    header('Location: calendar_view.php?error=invalid_id');
    exit;
}

// CSRF Token setup
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Helper function for JSON responses
function json_exit(array $payload, int $code = 200): void {
    if (ob_get_length()) {
        ob_clean();
    }
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// ============================================
// FETCH APPOINTMENT DATA
// ============================================
$appointment = null;
$patient = null;
$doctor = null;
$department = null;

try {
    $sql = "
        SELECT 
            a.id,
            a.appointment_number,
            a.appointment_date,
            a.appointment_time,
            a.status,
            a.notes,
            a.patient_id,
            a.doctor_id,
            a.department_id,
            a.branch_id,
            a.created_by_user_id,
            a.created_at,
            p.id AS patient_id,
            p.mr_number,
            p.first_name AS patient_first_name,
            p.middle_name AS patient_middle_name,
            p.last_name AS patient_last_name,
            p.phone AS patient_phone,
            p.email AS patient_email,
            p.gender AS patient_gender,
            p.date_of_birth AS patient_dob,
            d.first_name AS doctor_first_name,
            d.middle_name AS doctor_middle_name,
            d.last_name AS doctor_last_name,
            sp.specialization AS doctor_specialty,
            dep.name AS department_name,
            dep.code AS department_code
        FROM appointments a
        INNER JOIN patients p ON a.patient_id = p.id AND p.deleted_at IS NULL
        LEFT JOIN users d ON a.doctor_id = d.id AND d.deleted_at IS NULL
        LEFT JOIN staff_profiles sp ON d.id = sp.user_id
        LEFT JOIN departments dep ON a.department_id = dep.id AND dep.deleted_at IS NULL
        WHERE a.id = :appointment_id 
          AND a.branch_id = :branch_id 
          AND a.deleted_at IS NULL
        LIMIT 1
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':appointment_id' => $appointment_id,
        ':branch_id' => $user_branch_id
    ]);
    $appointment = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$appointment) {
        header('Location: calendar_view.php?error=not_found');
        exit;
    }
    
    // Format patient name
    $patient_name = trim(
        ($appointment['patient_first_name'] ?? '') . ' ' . 
        ($appointment['patient_middle_name'] ?? '') . ' ' . 
        ($appointment['patient_last_name'] ?? '')
    );
    if (empty($patient_name)) {
        $patient_name = 'Unknown Patient';
    }
    
    // Format doctor name
    $doctor_name = '';
    if (!empty($appointment['doctor_first_name'])) {
        $doctor_name = 'Dr. ' . trim(
            ($appointment['doctor_first_name'] ?? '') . ' ' . 
            ($appointment['doctor_middle_name'] ?? '') . ' ' . 
            ($appointment['doctor_last_name'] ?? '')
        );
    }
    
} catch (PDOException $e) {
    error_log("Appointment fetch error: " . $e->getMessage());
    die('Error loading appointment data');
}

// ============================================
// FETCH DEPARTMENTS FOR DROPDOWN
// ============================================
$departments = [];
try {
    $dept_stmt = $pdo->prepare("
        SELECT id, name, code 
        FROM departments 
        WHERE branch_id = :branch_id AND is_active = 1 AND deleted_at IS NULL 
        ORDER BY name
    ");
    $dept_stmt->execute([':branch_id' => $user_branch_id]);
    $departments = $dept_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Departments fetch error: " . $e->getMessage());
}

// ============================================
// FETCH DOCTORS FOR DROPDOWN (by department)
// ============================================
$doctors_by_dept = [];
try {
    $doc_stmt = $pdo->prepare("
        SELECT DISTINCT u.id, 
               CONCAT(u.first_name, ' ', COALESCE(u.middle_name, ''), ' ', u.last_name) AS name,
               sp.specialization
        FROM users u
        LEFT JOIN staff_profiles sp ON u.id = sp.user_id
        WHERE u.user_type = 'doctor' 
          AND u.is_active = 1 
          AND u.deleted_at IS NULL
          AND u.branch_id = :branch_id
        ORDER BY u.first_name, u.last_name
    ");
    $doc_stmt->execute([':branch_id' => $user_branch_id]);
    $all_doctors = $doc_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Group doctors by department (simplified - using staff_profiles department_id)
    foreach ($all_doctors as $doc) {
        $doctors_by_dept[$doc['id']] = $doc;
    }
} catch (PDOException $e) {
    error_log("Doctors fetch error: " . $e->getMessage());
}

// ============================================
// PROCESS UPDATE FORM SUBMISSION
// ============================================
$errors = [];
$success_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_appointment'])) {
    
    // Validate CSRF token
    $submitted_token = $_POST['csrf_token'] ?? '';
    if (!isset($_SESSION['csrf_token']) || $submitted_token !== $_SESSION['csrf_token']) {
        $errors[] = 'Security validation failed. Please refresh the page and try again.';
    }
    
    $patient_id = (int)($_POST['patient_id'] ?? 0);
    $doctor_id = (int)($_POST['doctor_id'] ?? 0);
    $department_id = (int)($_POST['department_id'] ?? 0);
    $appointment_date = trim($_POST['appointment_date'] ?? '');
    $appointment_time = trim($_POST['appointment_time'] ?? '');
    $status = trim($_POST['status'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    $chief_complaint = trim($_POST['chief_complaint'] ?? '');
    
    // Validation
    $valid_statuses = ['scheduled', 'confirmed', 'checked_in', 'in_progress', 'completed', 'cancelled', 'no_show'];
    
    if ($patient_id <= 0) $errors[] = 'Patient is required.';
    if ($doctor_id <= 0) $errors[] = 'Doctor is required.';
    if ($department_id <= 0) $errors[] = 'Department is required.';
    if (empty($appointment_date) || !DateTime::createFromFormat('Y-m-d', $appointment_date)) {
        $errors[] = 'Valid appointment date is required.';
    }
    if (empty($appointment_time) || !preg_match('/^\d{2}:\d{2}$/', $appointment_time)) {
        $errors[] = 'Valid appointment time is required.';
    }
    if (!in_array($status, $valid_statuses, true)) {
        $errors[] = 'Invalid status selected.';
    }
    
    if (!$errors) {
        try {
            $pdo->beginTransaction();
            
            $time_with_seconds = $appointment_time . ':00';
            
            // Check for conflicts (only if doctor and date/time changed)
            $old_date = $appointment['appointment_date'];
            $old_time = $appointment['appointment_time'];
            $old_doctor_id = $appointment['doctor_id'];
            
            $date_changed = ($old_date != $appointment_date);
            $time_changed = ($old_time != $time_with_seconds);
            $doctor_changed = ($old_doctor_id != $doctor_id);
            
            if (($doctor_changed || $date_changed || $time_changed) && $doctor_id > 0) {
                $conflict_stmt = $pdo->prepare("
                    SELECT id FROM appointments
                    WHERE branch_id = :branch_id 
                      AND doctor_id = :doctor_id 
                      AND appointment_date = :date 
                      AND appointment_time = :time
                      AND deleted_at IS NULL 
                      AND status NOT IN ('cancelled', 'no_show')
                      AND id != :id
                    LIMIT 1
                ");
                $conflict_stmt->execute([
                    ':branch_id' => $user_branch_id,
                    ':doctor_id' => $doctor_id,
                    ':date' => $appointment_date,
                    ':time' => $time_with_seconds,
                    ':id' => $appointment_id
                ]);
                $conflict = $conflict_stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($conflict) {
                    throw new Exception('Doctor already has an appointment at this time slot.');
                }
            }
            
            // Build notes with chief complaint
            $full_notes = $notes;
            if (!empty($chief_complaint)) {
                $full_notes = ($full_notes ? $full_notes . "\n\n" : '') . "[Chief Complaint] " . $chief_complaint;
            }
            
            // Update appointment
            $update_stmt = $pdo->prepare("
                UPDATE appointments 
                SET patient_id = :patient_id,
                    doctor_id = :doctor_id,
                    department_id = :department_id,
                    appointment_date = :appointment_date,
                    appointment_time = :appointment_time,
                    status = :status,
                    notes = :notes,
                    updated_at = NOW()
                WHERE id = :id AND branch_id = :branch_id AND deleted_at IS NULL
            ");
            
            $update_stmt->execute([
                ':patient_id' => $patient_id,
                ':doctor_id' => $doctor_id,
                ':department_id' => $department_id,
                ':appointment_date' => $appointment_date,
                ':appointment_time' => $time_with_seconds,
                ':status' => $status,
                ':notes' => $full_notes ?: null,
                ':id' => $appointment_id,
                ':branch_id' => $user_branch_id
            ]);
            
            if ($update_stmt->rowCount() === 0 && !$conflict) {
                // No changes were made
                $success_message = "No changes were made to the appointment.";
            } else {
                // Log the action for audit trail
                if (function_exists('log_user_action')) {
                    log_user_action($pdo, 'UPDATE', 'appointments', $appointment_id, null, [
                        'action' => 'update_appointment',
                        'updated_fields' => [
                            'patient_id' => $patient_id,
                            'doctor_id' => $doctor_id,
                            'department_id' => $department_id,
                            'appointment_date' => $appointment_date,
                            'appointment_time' => $time_with_seconds,
                            'status' => $status
                        ]
                    ]);
                }
                
                $pdo->commit();
                $success_message = "Appointment updated successfully!";
                
                // Refresh appointment data
                $refresh_stmt = $pdo->prepare("
                    SELECT * FROM appointments WHERE id = :id AND deleted_at IS NULL LIMIT 1
                ");
                $refresh_stmt->execute([':id' => $appointment_id]);
                $refreshed = $refresh_stmt->fetch(PDO::FETCH_ASSOC);
                if ($refreshed) {
                    foreach ($refreshed as $key => $value) {
                        if (isset($appointment[$key])) {
                            $appointment[$key] = $value;
                        }
                    }
                }
            }
            
        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = $e->getMessage();
            error_log("Update appointment error: " . $e->getMessage());
        }
    }
}

// Get status badge class for display
function getStatusBadgeClass($status) {
    return match($status) {
        'confirmed' => 'badge-success',
        'checked_in' => 'badge-info',
        'in_progress' => 'badge-primary',
        'completed' => 'badge-secondary',
        'cancelled' => 'badge-danger',
        'no_show' => 'badge-dark',
        default => 'badge-warning'
    };
}

function getStatusText($status) {
    return match($status) {
        'confirmed' => 'Confirmed',
        'checked_in' => 'Checked In',
        'in_progress' => 'In Progress',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
        'no_show' => 'No Show',
        default => 'Scheduled'
    };
}

$current_time_display = date('g:i A');
$current_date_display = date('l, F j, Y');
$branch_name = '';
$branch_stmt = $pdo->prepare("SELECT name FROM branches WHERE id = :id AND deleted_at IS NULL LIMIT 1");
$branch_stmt->execute([':id' => $user_branch_id]);
$branch_result = $branch_stmt->fetch(PDO::FETCH_ASSOC);
if ($branch_result) $branch_name = $branch_result['name'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include('assets/inc/head.php'); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Update Appointment | HMS ERP</title>
    
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        :root {
            --primary: #4361ee;
            --primary-dark: #3a0ca3;
            --secondary: #06d6a0;
            --danger: #ef476f;
            --warning: #f9c74f;
            --info: #4cc9f0;
            --dark: #1a1a2e;
            --gray: #6c757d;
            --success: #06d6a0;
            --card-bg: rgba(255, 255, 255, 0.95);
            --shadow-sm: 0 2px 8px rgba(0,0,0,0.05);
            --shadow-md: 0 4px 20px rgba(0,0,0,0.08);
            --shadow-lg: 0 8px 40px rgba(0,0,0,0.12);
        }
        
        body {
            font-family: 'Plus Jakarta Sans', 'Inter', sans-serif;
            background: linear-gradient(145deg, #f0f4f8 0%, #e2e8f0 100%);
            min-height: 100vh;
        }
        
        .content-page {
            margin-left: 260px;
            padding: 28px 32px;
            transition: all 0.3s ease;
        }
        
        body.erp-sidebar-collapsed .content-page {
            margin-left: 80px;
        }
        
        @media (max-width: 768px) {
            .content-page { margin-left: 0; padding: 20px; }
        }
        
        .page-header {
            background: linear-gradient(135deg, rgba(67, 97, 238, 0.95) 0%, rgba(58, 12, 163, 0.95) 100%);
            backdrop-filter: blur(10px);
            border-radius: 28px;
            padding: 28px 32px;
            margin-bottom: 28px;
            color: white;
            position: relative;
            overflow: hidden;
        }
        
        .page-header::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -20%;
            width: 300px;
            height: 300px;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
            border-radius: 50%;
        }
        
        .greeting-text {
            font-size: 24px;
            font-weight: 700;
            letter-spacing: -0.5px;
        }
        
        .greeting-sub {
            font-size: 13px;
            opacity: 0.8;
            margin-top: 4px;
        }
        
        .time-badge {
            background: rgba(255,255,255,0.15);
            backdrop-filter: blur(5px);
            padding: 8px 18px;
            border-radius: 40px;
            font-size: 13px;
            font-weight: 500;
        }
        
        .form-card {
            background: var(--card-bg);
            backdrop-filter: blur(10px);
            border-radius: 24px;
            overflow: hidden;
            box-shadow: var(--shadow-md);
            border: 1px solid rgba(255,255,255,0.3);
            margin-bottom: 30px;
        }
        
        .form-card-header {
            padding: 20px 28px;
            border-bottom: 1px solid #e2e8f0;
            background: #f8fafc;
        }
        
        .form-card-body {
            padding: 28px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            font-weight: 600;
            font-size: 13px;
            color: var(--dark);
            margin-bottom: 8px;
            display: block;
        }
        
        .form-group label i {
            width: 20px;
            color: var(--primary);
        }
        
        .form-control, .custom-select {
            width: 100%;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            padding: 10px 14px;
            font-size: 14px;
            transition: all 0.2s;
            background: white;
        }
        
        textarea.form-control {
            resize: vertical;
            min-height: 80px;
        }
        
        .form-control:focus, .custom-select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.1);
        }
        
        .badge {
            padding: 6px 12px;
            border-radius: 30px;
            font-weight: 600;
            font-size: 11px;
        }
        
        .badge-success { background: #d1fae5; color: #065f46; }
        .badge-warning { background: #fef3c7; color: #92400e; }
        .badge-info { background: #e0e7ff; color: #3730a3; }
        .badge-danger { background: #fee2e2; color: #991b1b; }
        .badge-secondary { background: #f1f5f9; color: #475569; }
        .badge-primary { background: #dbeafe; color: #1e40af; }
        .badge-dark { background: #e2e8f0; color: #1e293b; }
        
        .section-title {
            font-size: 16px;
            font-weight: 700;
            margin: 24px 0 16px 0;
            padding-bottom: 8px;
            border-bottom: 2px solid var(--primary);
            display: inline-block;
        }
        
        .btn-save {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            border: none;
            padding: 12px 32px;
            border-radius: 40px;
            font-weight: 600;
            color: white;
            transition: all 0.2s;
        }
        
        .btn-save:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(67, 97, 238, 0.3);
        }
        
        .btn-cancel {
            background: white;
            border: 1px solid #e2e8f0;
            padding: 12px 32px;
            border-radius: 40px;
            font-weight: 600;
            color: var(--gray);
            transition: all 0.2s;
        }
        
        .btn-cancel:hover {
            border-color: var(--primary);
            color: var(--primary);
        }
        
        .required:after {
            content: " *";
            color: var(--danger);
        }
        
        .alert-danger {
            background: #fee2e2;
            border: none;
            border-radius: 16px;
            padding: 16px 20px;
            margin-bottom: 24px;
            color: #991b1b;
        }
        
        .alert-success {
            background: #d1fae5;
            border: none;
            border-radius: 16px;
            padding: 16px 20px;
            margin-bottom: 24px;
            color: #065f46;
        }
        
        hr {
            margin: 24px 0;
            border-color: #e2e8f0;
        }
        
        .info-note {
            font-size: 12px;
            color: #6c757d;
            margin-top: 4px;
        }
        
        .row {
            display: flex;
            flex-wrap: wrap;
            margin-right: -15px;
            margin-left: -15px;
        }
        
        .col-md-4, .col-md-6 {
            position: relative;
            width: 100%;
            padding-right: 15px;
            padding-left: 15px;
        }
        
        @media (min-width: 768px) {
            .col-md-4 { flex: 0 0 33.333333%; max-width: 33.333333%; }
            .col-md-6 { flex: 0 0 50%; max-width: 50%; }
        }
        
        .mt-2 { margin-top: 0.5rem; }
        .mt-3 { margin-top: 1rem; }
        .mt-4 { margin-top: 1.5rem; }
        .mb-0 { margin-bottom: 0; }
        .mb-3 { margin-bottom: 1rem; }
        .ms-2 { margin-left: 0.5rem; }
        .me-1 { margin-right: 0.25rem; }
        .me-2 { margin-right: 0.5rem; }
        .text-end { text-align: right; }
        .text-muted { color: #6c757d; }
        .text-primary { color: var(--primary); }
        .small { font-size: 0.875rem; }
        .d-flex { display: flex; }
        .justify-content-between { justify-content: space-between; }
        .align-items-center { align-items: center; }
        .flex-wrap { flex-wrap: wrap; }
        .gap-2 { gap: 0.5rem; }
        .gap-3 { gap: 1rem; }
    </style>
</head>
<body>

<div id="wrapper">
    <?php include('assets/inc/nav.php'); ?>
    <?php include('assets/inc/sidebar.php'); ?>

    <div class="content-page">
        <div class="container-fluid">
            
            <div class="page-header">
                <div class="d-flex justify-content-between align-items-center flex-wrap">
                    <div>
                        <div class="greeting-text">
                            <i class="fas fa-calendar-edit me-3"></i>Edit Appointment
                        </div>
                        <div class="greeting-sub">
                            Modify appointment details • <?php echo htmlspecialchars($branch_name, ENT_QUOTES, 'UTF-8'); ?>
                        </div>
                    </div>
                    <div class="mt-3 mt-md-0">
                        <div class="time-badge">
                            <i class="far fa-calendar-alt me-2"></i> <?php echo $current_date_display; ?>
                            <i class="far fa-clock ms-2 me-1"></i> <?php echo $current_time_display; ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <?php if ($success_message): ?>
                <div class="alert-success">
                    <i class="fas fa-check-circle me-2"></i> <?php echo htmlspecialchars($success_message, ENT_QUOTES, 'UTF-8'); ?>
                </div>
            <?php endif; ?>
            
            <?php if ($errors): ?>
                <div class="alert-danger">
                    <strong><i class="fas fa-exclamation-triangle me-2"></i> Please fix the following:</strong>
                    <ul class="mb-0 mt-2">
                        <?php foreach ($errors as $e): ?>
                            <li><?php echo htmlspecialchars($e, ENT_QUOTES, 'UTF-8'); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
            
            <div class="form-card">
                <div class="form-card-header">
                    <h6 class="mb-0"><i class="fas fa-edit me-2 text-primary"></i> Appointment Information</h6>
                </div>
                <div class="form-card-body">
                    <form method="POST" action="">
                        <input type="hidden" name="update_appointment" value="1">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                        
                        <!-- Appointment Number Display -->
                        <div class="row">
                            <div class="col-md-12">
                                <div class="form-group">
                                    <label><i class="fas fa-hashtag"></i> Appointment Number</label>
                                    <input type="text" class="form-control" value="<?php echo htmlspecialchars($appointment['appointment_number'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?>" disabled>
                                    <div class="info-note">Appointment number is auto-generated and cannot be changed</div>
                                </div>
                            </div>
                        </div>
                        
                        <hr>
                        
                        <!-- Patient Information -->
                        <h6 class="section-title"><i class="fas fa-user me-2 text-primary"></i> Patient Information</h6>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="required"><i class="fas fa-user"></i> Patient Name</label>
                                    <input type="text" class="form-control" value="<?php echo htmlspecialchars($patient_name, ENT_QUOTES, 'UTF-8'); ?>" disabled>
                                    <input type="hidden" name="patient_id" value="<?php echo (int)$appointment['patient_id']; ?>">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label><i class="fas fa-qrcode"></i> MRN</label>
                                    <input type="text" class="form-control" value="<?php echo htmlspecialchars($appointment['mr_number'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?>" disabled>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label><i class="fas fa-phone"></i> Phone</label>
                                    <input type="text" class="form-control" value="<?php echo htmlspecialchars($appointment['patient_phone'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?>" disabled>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label><i class="fas fa-envelope"></i> Email</label>
                                    <input type="text" class="form-control" value="<?php echo htmlspecialchars($appointment['patient_email'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?>" disabled>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label><i class="fas fa-venus-mars"></i> Gender</label>
                                    <input type="text" class="form-control" value="<?php echo htmlspecialchars(ucfirst($appointment['patient_gender'] ?? 'N/A'), ENT_QUOTES, 'UTF-8'); ?>" disabled>
                                </div>
                            </div>
                        </div>
                        
                        <hr>
                        
                        <!-- Appointment Details -->
                        <h6 class="section-title"><i class="fas fa-calendar-alt me-2 text-primary"></i> Appointment Details</h6>
                        
                        <div class="row">
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label class="required"><i class="fas fa-building"></i> Department</label>
                                    <select name="department_id" class="custom-select" id="departmentSelect" required>
                                        <option value="">Select Department</option>
                                        <?php foreach ($departments as $dept): ?>
                                            <option value="<?php echo (int)$dept['id']; ?>" 
                                                <?php echo ($appointment['department_id'] == $dept['id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($dept['name'], ENT_QUOTES, 'UTF-8'); ?> 
                                                (<?php echo htmlspecialchars($dept['code'], ENT_QUOTES, 'UTF-8'); ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label class="required"><i class="fas fa-user-md"></i> Doctor</label>
                                    <select name="doctor_id" id="doctorSelect" class="custom-select" required>
                                        <option value="">Select Doctor</option>
                                        <?php foreach ($all_doctors as $doc): ?>
                                            <option value="<?php echo (int)$doc['id']; ?>" 
                                                <?php echo ($appointment['doctor_id'] == $doc['id']) ? 'selected' : ''; ?>>
                                                Dr. <?php echo htmlspecialchars($doc['name'], ENT_QUOTES, 'UTF-8'); ?>
                                                (<?php echo htmlspecialchars($doc['specialization'] ?? 'General', ENT_QUOTES, 'UTF-8'); ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label class="required"><i class="fas fa-flag"></i> Status</label>
                                    <select name="status" class="custom-select" required>
                                        <option value="scheduled" <?php echo ($appointment['status'] ?? '') == 'scheduled' ? 'selected' : ''; ?>>Scheduled</option>
                                        <option value="confirmed" <?php echo ($appointment['status'] ?? '') == 'confirmed' ? 'selected' : ''; ?>>Confirmed</option>
                                        <option value="checked_in" <?php echo ($appointment['status'] ?? '') == 'checked_in' ? 'selected' : ''; ?>>Checked In</option>
                                        <option value="in_progress" <?php echo ($appointment['status'] ?? '') == 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                                        <option value="completed" <?php echo ($appointment['status'] ?? '') == 'completed' ? 'selected' : ''; ?>>Completed</option>
                                        <option value="cancelled" <?php echo ($appointment['status'] ?? '') == 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                                        <option value="no_show" <?php echo ($appointment['status'] ?? '') == 'no_show' ? 'selected' : ''; ?>>No Show</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="required"><i class="fas fa-calendar-day"></i> Appointment Date</label>
                                    <input type="date" name="appointment_date" class="form-control" 
                                           value="<?php echo htmlspecialchars($appointment['appointment_date'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" 
                                           min="<?php echo date('Y-m-d'); ?>" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="required"><i class="fas fa-clock"></i> Appointment Time</label>
                                    <input type="time" name="appointment_time" class="form-control" 
                                           value="<?php echo htmlspecialchars(substr($appointment['appointment_time'] ?? '09:00:00', 0, 5), ENT_QUOTES, 'UTF-8'); ?>" required>
                                </div>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label><i class="fas fa-comment-medical"></i> Chief Complaint</label>
                            <textarea name="chief_complaint" class="form-control" rows="2" 
                                      placeholder="Describe the patient's symptoms or reason for visit..."><?php 
                                // Extract chief complaint from notes (assuming format "[Chief Complaint] text")
                                $notes = $appointment['notes'] ?? '';
                                if (preg_match('/\[Chief Complaint\]\s*(.+?)(?=\n\n|$)/s', $notes, $matches)) {
                                    echo htmlspecialchars(trim($matches[1]), ENT_QUOTES, 'UTF-8');
                                }
                            ?></textarea>
                        </div>
                        
                        <div class="form-group">
                            <label><i class="fas fa-sticky-note"></i> Additional Notes</label>
                            <textarea name="notes" class="form-control" rows="3" 
                                      placeholder="Any additional notes or instructions..."><?php 
                                // Remove chief complaint from notes for display
                                $notes = $appointment['notes'] ?? '';
                                $clean_notes = preg_replace('/\[Chief Complaint\].*?(\n\n|$)/s', '', $notes);
                                echo htmlspecialchars(trim($clean_notes), ENT_QUOTES, 'UTF-8');
                            ?></textarea>
                        </div>
                        
                        <hr>
                        
                        <div class="d-flex justify-content-end gap-3">
                            <a href="calendar_view.php" class="btn-cancel">
                                <i class="fas fa-arrow-left me-2"></i>Cancel
                            </a>
                            <button type="submit" class="btn-save">
                                <i class="fas fa-save me-2"></i>Save Changes
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            
        </div>
    </div>
    
    <?php include('assets/inc/footer.php'); ?>
</div>

<script src="assets/js/vendor.min.js"></script>
<script src="assets/js/app.min.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const departmentSelect = document.getElementById('departmentSelect');
    const doctorSelect = document.getElementById('doctorSelect');
    const allDoctors = <?php echo json_encode($all_doctors); ?>;
    const currentDoctorId = <?php echo (int)($appointment['doctor_id'] ?? 0); ?>;
    
    function filterDoctorsByDepartment() {
        const selectedDept = departmentSelect.value;
        
        if (!selectedDept) {
            // Show all doctors if no department selected
            doctorSelect.innerHTML = '<option value="">Select Doctor</option>';
            allDoctors.forEach(function(doc) {
                const selected = (doc.id == currentDoctorId) ? 'selected' : '';
                doctorSelect.innerHTML += `<option value="${doc.id}" ${selected}>Dr. ${escapeHtml(doc.name)} (${escapeHtml(doc.specialization || 'General')})</option>`;
            });
            return;
        }
        
        // Filter doctors by department (simplified - in production, you'd have a department_user table)
        // For now, we'll show all doctors but indicate they might not be in the department
        doctorSelect.innerHTML = '<option value="">Select Doctor</option>';
        
        // In a real implementation, you would fetch doctors by department via AJAX
        // For now, we'll show all doctors
        allDoctors.forEach(function(doc) {
            const selected = (doc.id == currentDoctorId) ? 'selected' : '';
            doctorSelect.innerHTML += `<option value="${doc.id}" ${selected}>Dr. ${escapeHtml(doc.name)} (${escapeHtml(doc.specialization || 'General')})</option>`;
        });
    }
    
    function escapeHtml(str) {
        if (!str) return '';
        return String(str).replace(/[&<>]/g, function(m) {
            if (m === '&') return '&amp;';
            if (m === '<') return '&lt;';
            if (m === '>') return '&gt;';
            return m;
        });
    }
    
    if (departmentSelect) {
        departmentSelect.addEventListener('change', filterDoctorsByDepartment);
    }
    
    // Set min date for appointment
    const dateInput = document.querySelector('input[name="appointment_date"]');
    if (dateInput) {
        const today = new Date().toISOString().split('T')[0];
        dateInput.min = today;
    }
});

<?php if ($success_message): ?>
Swal.fire({
    icon: 'success',
    title: 'Success',
    text: '<?php echo htmlspecialchars($success_message, ENT_QUOTES, 'UTF-8'); ?>',
    confirmButtonColor: '#4361ee',
    timer: 3000,
    timerProgressBar: true
}).then(() => {
    window.location.href = 'calendar_view.php';
});
<?php endif; ?>
</script>

</body>
</html>