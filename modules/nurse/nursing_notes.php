<?php
/**
 * ======================================================================
 * NURSING PROGRESS NOTES & IO CHART - PHASE 4 (FIXED)
 * Shift notes, vitals timeline, Intake/Output fluid balance
 * Strict multi-tenancy | RBAC | CSRF | PDO | Responsive
 * ======================================================================
 */

session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../../config/db_connection.php';
require_once __DIR__ . '/../../includes/auth_helper.php';

date_default_timezone_set('Africa/Addis_Ababa');
if ($pdo) $pdo->exec("SET time_zone = '+03:00'");

$user_id = (int)($_SESSION['user_id'] ?? 0);
$branch_id = (int)($_SESSION['branch_id'] ?? 0);
$is_global_admin = ($_SESSION['user_type'] ?? '') === 'super_admin';
$nurse_name = $_SESSION['first_name'] ?? $_SESSION['username'] ?? 'Nurse';

if ($user_id <= 0) {
    header('Location: ../../index.php');
    exit;
}

$has_notes_access = user_has_permission($pdo, 'nursing_notes_access', $user_id);
if (!$is_global_admin && !$has_notes_access) {
    header('Location: dashboard.php?error=unauthorized');
    exit;
}

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

function jsonResponse($success, $message, $data = null, $code = 200) {
    if (ob_get_length()) ob_clean();
    http_response_code($code);
    header('Content-Type: application/json');
    $response = ['success' => $success, 'message' => $message];
    if ($data !== null) $response['data'] = $data;
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

function escapeHtml($str) {
    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}

// ----------------------------------------------------------------------
// AJAX ROUTERS
// ----------------------------------------------------------------------
if (isset($_GET['action'])) {
    // 1) Load full clinical dataset for a selected admission
    if ($_GET['action'] === 'load_patient_clinical_data') {
        $admission_id = (int)($_GET['admission_id'] ?? 0);
        if ($admission_id <= 0) jsonResponse(false, 'Invalid admission ID');

        try {
            // Verify admission belongs to branch and is active
            $check = $pdo->prepare("SELECT id FROM admissions WHERE id = :admission_id AND branch_id = :branch_id AND status = 'admitted' AND deleted_at IS NULL");
            $check->execute([':admission_id' => $admission_id, ':branch_id' => $branch_id]);
            if (!$check->fetch()) {
                jsonResponse(false, 'Admission not found or not active');
            }

            // ---- 1. Nursing progress notes ----
            $notesSql = "SELECT id, shift_type, progress_note, created_at, nurse_user_id
                         FROM nursing_notes_records
                         WHERE admission_id = :admission_id AND branch_id = :branch_id AND deleted_at IS NULL
                         ORDER BY created_at DESC";
            $notesStmt = $pdo->prepare($notesSql);
            $notesStmt->execute([':admission_id' => $admission_id, ':branch_id' => $branch_id]);
            $notes = $notesStmt->fetchAll(PDO::FETCH_ASSOC);
            if (!is_array($notes)) $notes = [];

            // ---- 2. Vitals log ----
            $vitalsSql = "SELECT id, temperature, pulse_rate, blood_pressure, 
                                 blood_pressure_systolic, blood_pressure_diastolic,
                                 respiratory_rate, oxygen_saturation AS spo2, 
                                 recorded_at, taken_by_user_id
                          FROM nursing_vitals_log
                          WHERE admission_id = :admission_id AND branch_id = :branch_id AND deleted_at IS NULL
                          ORDER BY recorded_at DESC";
            $vitalsStmt = $pdo->prepare($vitalsSql);
            $vitalsStmt->execute([':admission_id' => $admission_id, ':branch_id' => $branch_id]);
            $vitals = $vitalsStmt->fetchAll(PDO::FETCH_ASSOC);
            if (!is_array($vitals)) $vitals = [];

            // Format vitals to include formatted blood pressure
            foreach ($vitals as &$v) {
                // If blood_pressure is empty but we have systolic/diastolic, combine them
                if (empty($v['blood_pressure']) && !empty($v['blood_pressure_systolic']) && !empty($v['blood_pressure_diastolic'])) {
                    $v['blood_pressure'] = $v['blood_pressure_systolic'] . '/' . $v['blood_pressure_diastolic'];
                }
                // Ensure spo2 is set (it's oxygen_saturation in DB)
                if (!isset($v['spo2']) && isset($v['oxygen_saturation'])) {
                    $v['spo2'] = $v['oxygen_saturation'];
                }
            }

            // ---- 3. IO Chart records + today's aggregates ----
            $ioSql = "SELECT id, intake_ml, intake_type, output_ml, output_type, notes, recorded_at, recorded_by_user_id
                      FROM nursing_io_chart
                      WHERE admission_id = :admission_id AND branch_id = :branch_id AND deleted_at IS NULL
                      ORDER BY recorded_at DESC";
            $ioStmt = $pdo->prepare($ioSql);
            $ioStmt->execute([':admission_id' => $admission_id, ':branch_id' => $branch_id]);
            $ioRecords = $ioStmt->fetchAll(PDO::FETCH_ASSOC);
            if (!is_array($ioRecords)) $ioRecords = [];

            // Today's aggregates
            $today = date('Y-m-d');
            $aggSql = "SELECT 
                        COALESCE(SUM(intake_ml), 0) AS total_intake,
                        COALESCE(SUM(output_ml), 0) AS total_output
                       FROM nursing_io_chart
                       WHERE admission_id = :admission_id AND branch_id = :branch_id AND DATE(recorded_at) = :today AND deleted_at IS NULL";
            $aggStmt = $pdo->prepare($aggSql);
            $aggStmt->execute([':admission_id' => $admission_id, ':branch_id' => $branch_id, ':today' => $today]);
            $agg = $aggStmt->fetch(PDO::FETCH_ASSOC);
            $totalIntake = isset($agg['total_intake']) ? (int)$agg['total_intake'] : 0;
            $totalOutput = isset($agg['total_output']) ? (int)$agg['total_output'] : 0;
            $netBalance = $totalIntake - $totalOutput;

            // Patient summary
            $patSql = "SELECT 
                          p.id AS patient_id, p.mr_number, 
                          CONCAT(
                              COALESCE(p.first_name, ''),
                              ' ',
                              COALESCE(p.middle_name, ''),
                              ' ',
                              COALESCE(p.last_name, '')
                          ) AS patient_name,
                          p.gender, p.date_of_birth
                       FROM admissions a
                       INNER JOIN patients p ON a.patient_id = p.id
                       WHERE a.id = :admission_id AND p.deleted_at IS NULL";
            $patStmt = $pdo->prepare($patSql);
            $patStmt->execute([':admission_id' => $admission_id]);
            $patient = $patStmt->fetch(PDO::FETCH_ASSOC);
            if ($patient) {
                // Clean up patient name (remove extra spaces)
                $patient['patient_name'] = preg_replace('/\s+/', ' ', trim($patient['patient_name']));
                
                $age = 'N/A';
                if (!empty($patient['date_of_birth'])) {
                    $dob = new DateTime($patient['date_of_birth']);
                    $now = new DateTime();
                    $age = $dob->diff($now)->y;
                }
                $patient['age'] = $age;
            } else {
                $patient = null;
            }

            jsonResponse(true, '', [
                'patient' => $patient,
                'notes' => $notes,
                'vitals' => $vitals,
                'io_records' => $ioRecords,
                'totals' => [
                    'total_intake' => $totalIntake,
                    'total_output' => $totalOutput,
                    'net_balance' => $netBalance
                ]
            ]);
        } catch (PDOException $e) {
            jsonResponse(false, 'Database error: ' . $e->getMessage());
        }
        exit;
    }
}

// 2) POST: Submit progress note
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'submit_progress_note') {
    $incoming_token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($csrf_token, $incoming_token)) {
        jsonResponse(false, 'Security token mismatch');
    }
    $admission_id = (int)($_POST['admission_id'] ?? 0);
    $shift_type = $_POST['shift_type'] ?? '';
    $progress_note = trim($_POST['progress_note'] ?? '');
    $allowed_shifts = ['morning', 'afternoon', 'night'];
    if (!$admission_id || !in_array($shift_type, $allowed_shifts) || empty($progress_note)) {
        jsonResponse(false, 'Missing required fields');
    }
    try {
        $insert = $pdo->prepare("INSERT INTO nursing_notes_records 
            (branch_id, admission_id, nurse_user_id, shift_type, progress_note, created_at)
            VALUES (:branch_id, :admission_id, :nurse_id, :shift_type, :note, NOW())");
        $insert->execute([
            ':branch_id' => $branch_id,
            ':admission_id' => $admission_id,
            ':nurse_id' => $user_id,
            ':shift_type' => $shift_type,
            ':note' => $progress_note
        ]);
        jsonResponse(true, 'Progress note saved successfully');
    } catch (PDOException $e) {
        jsonResponse(false, 'Database error: ' . $e->getMessage());
    }
    exit;
}

// 3) POST: Submit recursive vitals
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'submit_recursive_vitals') {
    $incoming_token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($csrf_token, $incoming_token)) {
        jsonResponse(false, 'Security token mismatch');
    }
    $admission_id = (int)($_POST['admission_id'] ?? 0);
    $temperature = !empty($_POST['temperature']) ? (float)$_POST['temperature'] : null;
    $pulse_rate = !empty($_POST['pulse_rate']) ? (int)$_POST['pulse_rate'] : null;
    $blood_pressure = trim($_POST['blood_pressure'] ?? '');
    $respiratory_rate = !empty($_POST['respiratory_rate']) ? (int)$_POST['respiratory_rate'] : null;
    $spo2 = !empty($_POST['spo2']) ? (int)$_POST['spo2'] : null;
    
    // Parse blood pressure if in format "systolic/diastolic"
    $bp_systolic = null;
    $bp_diastolic = null;
    if (!empty($blood_pressure) && strpos($blood_pressure, '/') !== false) {
        $parts = explode('/', $blood_pressure);
        if (count($parts) == 2) {
            $bp_systolic = (int)trim($parts[0]);
            $bp_diastolic = (int)trim($parts[1]);
        }
    }
    
    if (!$admission_id) {
        jsonResponse(false, 'Missing admission ID');
    }
    try {
        // Get patient_id from admission
        $patIdStmt = $pdo->prepare("SELECT patient_id FROM admissions WHERE id = :admission_id");
        $patIdStmt->execute([':admission_id' => $admission_id]);
        $patient_id_row = $patIdStmt->fetch(PDO::FETCH_ASSOC);
        $patient_id = $patient_id_row ? $patient_id_row['patient_id'] : null;
        
        $insert = $pdo->prepare("INSERT INTO nursing_vitals_log 
            (branch_id, admission_id, patient_id, taken_by_user_id, 
             temperature, pulse_rate, blood_pressure, blood_pressure_systolic, blood_pressure_diastolic,
             respiratory_rate, oxygen_saturation, recorded_at)
            VALUES (
                :branch_id, :admission_id, :patient_id, :user_id, 
                :temp, :pulse, :bp, :bp_sys, :bp_dia,
                :rr, :spo2, NOW()
            )");
        $insert->execute([
            ':branch_id' => $branch_id,
            ':admission_id' => $admission_id,
            ':patient_id' => $patient_id,
            ':user_id' => $user_id,
            ':temp' => $temperature,
            ':pulse' => $pulse_rate,
            ':bp' => $blood_pressure,
            ':bp_sys' => $bp_systolic,
            ':bp_dia' => $bp_diastolic,
            ':rr' => $respiratory_rate,
            ':spo2' => $spo2
        ]);
        jsonResponse(true, 'Vitals recorded');
    } catch (PDOException $e) {
        jsonResponse(false, 'Database error: ' . $e->getMessage());
    }
    exit;
}

// 4) POST: Submit IO log
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'submit_io_log') {
    $incoming_token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($csrf_token, $incoming_token)) {
        jsonResponse(false, 'Security token mismatch');
    }
    $admission_id = (int)($_POST['admission_id'] ?? 0);
    $intake_ml = !empty($_POST['intake_ml']) ? (int)$_POST['intake_ml'] : 0;
    $intake_type = trim($_POST['intake_type'] ?? '');
    $output_ml = !empty($_POST['output_ml']) ? (int)$_POST['output_ml'] : 0;
    $output_type = trim($_POST['output_type'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    if (!$admission_id || ($intake_ml <= 0 && $output_ml <= 0)) {
        jsonResponse(false, 'Please enter intake or output value');
    }
    try {
        $insert = $pdo->prepare("INSERT INTO nursing_io_chart 
            (branch_id, admission_id, recorded_by_user_id, intake_ml, intake_type, output_ml, output_type, notes, recorded_at)
            VALUES (:branch_id, :admission_id, :user_id, :intake_ml, :intake_type, :output_ml, :output_type, :notes, NOW())");
        $insert->execute([
            ':branch_id' => $branch_id,
            ':admission_id' => $admission_id,
            ':user_id' => $user_id,
            ':intake_ml' => $intake_ml,
            ':intake_type' => $intake_type,
            ':output_ml' => $output_ml,
            ':output_type' => $output_type,
            ':notes' => $notes
        ]);
        jsonResponse(true, 'Fluid entry recorded');
    } catch (PDOException $e) {
        jsonResponse(false, 'Database error: ' . $e->getMessage());
    }
    exit;
}

// ----------------------------------------------------------------------
// INITIAL PAGE LOAD: Fetch active inpatients (left sidebar)
// ----------------------------------------------------------------------
$active_inpatients = [];

try {
    $sql = "SELECT 
                a.id AS admission_id,
                w.name AS ward_name,
                b.bed_number,
                p.id AS patient_id,
                p.mr_number,
                CONCAT(
                    COALESCE(p.first_name, ''),
                    ' ',
                    COALESCE(p.middle_name, ''),
                    ' ',
                    COALESCE(p.last_name, '')
                ) AS patient_name,
                p.gender,
                p.date_of_birth
            FROM admissions a
            INNER JOIN patients p ON a.patient_id = p.id AND p.deleted_at IS NULL
            LEFT JOIN beds b ON a.bed_id = b.id AND b.deleted_at IS NULL
            LEFT JOIN wards w ON b.ward_id = w.id AND w.deleted_at IS NULL
            WHERE a.branch_id = :branch_id
              AND a.status = 'admitted'
              AND a.discharged_at IS NULL
              AND a.deleted_at IS NULL
            ORDER BY a.admitted_at ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':branch_id' => $branch_id]);
    $active_inpatients = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Ensure it's always an array
    if (!is_array($active_inpatients)) {
        $active_inpatients = [];
    }
    
    // Clean up patient names
    foreach ($active_inpatients as &$inp) {
        $inp['patient_name'] = preg_replace('/\s+/', ' ', trim($inp['patient_name'] ?? 'Unknown Patient'));
    }
} catch (PDOException $e) {
    error_log('Active inpatient fetch error: ' . $e->getMessage());
    $active_inpatients = [];
}

// Get count safely
$inpatient_count = is_array($active_inpatients) ? count($active_inpatients) : 0;

$current_date = date('l, F j, Y');
$current_time = date('g:i A');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include('assets/inc/head.php'); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nursing Progress Notes & IO Chart | HMS ERP</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <style>
        :root {
            --primary: #4361ee;
            --primary-dark: #3a0ca3;
            --success: #06d6a0;
            --warning: #f9c74f;
            --danger: #ef476f;
            --info: #4cc9f0;
            --gray: #6c757d;
            --gray-light: #e9ecef;
        }
        body { font-family: 'Inter', sans-serif; background: #f5f7fb; overflow-x: hidden; }
        .content-page { margin-left: 260px; margin-top: 70px; padding: 20px 24px; transition: margin-left 0.3s; min-height: calc(100vh - 70px); }
        body.erp-sidebar-collapsed .content-page { margin-left: 80px; }
        @media (max-width: 768px) { .content-page { margin-left: 0 !important; padding: 16px; } }
        .welcome-header { background: linear-gradient(135deg, var(--primary-dark) 0%, var(--primary) 100%); border-radius: 24px; padding: 16px 24px; color: white; margin-bottom: 24px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; }
        .welcome-title { font-size: 20px; font-weight: 700; margin: 0; }
        .welcome-subtitle { font-size: 13px; opacity: 0.8; margin-top: 4px; }
        .time-badge { background: rgba(255,255,255,0.15); padding: 6px 14px; border-radius: 12px; font-size: 13px; display: inline-flex; align-items: center; gap: 8px; }
        .card-modern { background: white; border-radius: 20px; border: 1px solid var(--gray-light); overflow: hidden; margin-bottom: 24px; }
        .card-header-custom { padding: 16px 20px; background: #f8fafc; border-bottom: 1px solid var(--gray-light); font-weight: 700; display: flex; justify-content: space-between; align-items: center; }
        .patient-list-sidebar { max-height: calc(100vh - 220px); overflow-y: auto; }
        .patient-card { padding: 14px 16px; border-bottom: 1px solid #f0f0f0; cursor: pointer; transition: all 0.2s; }
        .patient-card:hover { background: #f8fafc; }
        .patient-card.active { background: #e0e7ff; border-left: 3px solid var(--primary); }
        .nav-tabs-custom { border-bottom: 1px solid var(--gray-light); background: #f8fafc; padding: 0 24px; }
        .nav-tabs-custom .nav-link { border: none; padding: 12px 20px; font-weight: 600; color: var(--gray); }
        .nav-tabs-custom .nav-link.active { color: var(--primary); border-bottom: 2px solid var(--primary); background: transparent; }
        .tab-content { padding: 24px; }
        .io-summary-card { background: #f0f9ff; border-radius: 16px; padding: 16px; margin-bottom: 20px; }
        .empty-state { text-align: center; padding: 40px; color: var(--gray); }
        .btn-submit { background: var(--primary); color: white; border: none; padding: 8px 16px; border-radius: 12px; transition: background 0.2s; }
        .btn-submit:hover { background: var(--primary-dark); color: white; }
        .toast-notification { position: fixed; bottom: 20px; right: 20px; background: white; border-radius: 12px; padding: 12px 20px; box-shadow: 0 10px 25px rgba(0,0,0,0.1); z-index: 1100; animation: slideIn 0.3s ease; border-left: 4px solid var(--success); }
        .toast-error { border-left-color: var(--danger); }
        @keyframes slideIn { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
        .loading-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(255,255,255,0.7); z-index: 2000; display: none; align-items: center; justify-content: center; }
        .loading-overlay.active { display: flex; }
        .spinner { width: 40px; height: 40px; border: 3px solid var(--gray-light); border-top-color: var(--primary); border-radius: 50%; animation: spin 0.8s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }
        .history-item { border-left: 3px solid var(--primary); padding-left: 12px; margin-bottom: 12px; }
        .vitals-row { background: #f8fafc; border-radius: 12px; padding: 8px 12px; margin-bottom: 8px; }
        .badge-gender { font-size: 11px; padding: 3px 10px; }
        .badge-male { background: #0d6efd; color: white; }
        .badge-female { background: #d63384; color: white; }
    </style>
</head>
<body>
<div id="wrapper">
    <?php include('assets/inc/nav.php'); ?>
    <?php include('assets/inc/sidebar.php'); ?>

    <div class="content-page">
        <div class="container-fluid">
            <div class="welcome-header">
                <div>
                    <div class="welcome-title">Nursing Progress Notes & IO Chart</div>
                    <div class="welcome-subtitle">Shift notes, Vitals timeline, Fluid balance</div>
                </div>
                <div class="time-badge"><i class="far fa-calendar-alt"></i> <?php echo escapeHtml($current_date); ?> <i class="far fa-clock ms-2"></i> <?php echo escapeHtml($current_time); ?></div>
            </div>

            <div class="row g-4">
                <!-- LEFT: Active inpatient roster -->
                <div class="col-lg-4 col-xl-3">
                    <div class="card-modern">
                        <div class="card-header-custom">
                            <h5 class="mb-0"><i class="fas fa-procedures me-2"></i> Active Inpatients</h5>
                            <span class="badge bg-primary"><?php echo $inpatient_count; ?></span>
                        </div>
                        <div class="patient-list-sidebar" id="patientList">
                            <?php if ($inpatient_count > 0 && is_array($active_inpatients)): ?>
                                <?php foreach ($active_inpatients as $inp): 
                                    $fullName = trim($inp['patient_name'] ?? 'Unknown Patient');
                                    $wardBed = ($inp['ward_name'] ? escapeHtml($inp['ward_name']) : 'Ward') . ' - ' . ($inp['bed_number'] ? escapeHtml($inp['bed_number']) : 'No bed');
                                    $genderBadge = '';
                                    if (isset($inp['gender'])) {
                                        if ($inp['gender'] === 'male') $genderBadge = '<span class="badge badge-male badge-gender">Male</span>';
                                        elseif ($inp['gender'] === 'female') $genderBadge = '<span class="badge badge-female badge-gender">Female</span>';
                                    }
                                ?>
                                    <div class="patient-card" data-admission-id="<?php echo $inp['admission_id']; ?>">
                                        <div class="d-flex justify-content-between align-items-start">
                                            <div>
                                                <div class="fw-bold"><?php echo escapeHtml($fullName); ?></div>
                                                <small class="text-muted">MRN: <?php echo escapeHtml($inp['mr_number'] ?? ''); ?> <?php echo $genderBadge; ?></small>
                                                <div class="mt-1"><span class="badge bg-secondary"><?php echo $wardBed; ?></span></div>
                                            </div>
                                            <i class="fas fa-chevron-right text-muted"></i>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="empty-state"><i class="fas fa-hospital-user fa-2x mb-2"></i><p>No active inpatients</p></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- RIGHT: Clinical workspace with tabs -->
                <div class="col-lg-8 col-xl-9">
                    <div class="card-modern" style="min-height: 500px;">
                        <div id="emptyStateContainer" class="empty-state py-5">
                            <i class="fas fa-notes-medical fa-3x mb-3 text-muted"></i>
                            <h5>No Patient Selected</h5>
                            <p class="text-muted">Please select a patient from the left panel</p>
                        </div>
                        <div id="activeWorkspaceContainer" style="display: none;">
                            <!-- Patient summary header -->
                            <div class="p-3 bg-white border-bottom">
                                <div class="row align-items-center">
                                    <div class="col-md-7">
                                        <h4 id="patientNameHeader" class="mb-1">-</h4>
                                        <div class="text-muted small">
                                            MRN: <span id="mrNumberHeader">-</span> | 
                                            Gender: <span id="genderHeader">-</span> | 
                                            Age: <span id="ageHeader">-</span>
                                        </div>
                                    </div>
                                    <div class="col-md-5 text-md-end mt-2 mt-md-0">
                                        <span class="badge bg-primary p-2"><i class="fas fa-notes-medical"></i> Nursing Progress Tracker</span>
                                    </div>
                                </div>
                            </div>

                            <!-- Tab navigation -->
                            <ul class="nav nav-tabs-custom" id="nursingTabs" role="tablist">
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link active" id="notes-tab" data-bs-toggle="tab" data-bs-target="#notes" type="button" role="tab">📝 Progress Notes</button>
                                </li>
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link" id="vitals-tab" data-bs-toggle="tab" data-bs-target="#vitals" type="button" role="tab">❤️ Vitals Timeline</button>
                                </li>
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link" id="io-tab" data-bs-toggle="tab" data-bs-target="#io" type="button" role="tab">💧 IO Fluid Chart</button>
                                </li>
                            </ul>
                            <div class="tab-content">
                                <!-- TAB 1: Progress Notes -->
                                <div class="tab-pane fade show active" id="notes" role="tabpanel">
                                    <div class="mb-4">
                                        <h6 class="fw-bold"><i class="fas fa-plus-circle me-2"></i> New Progress Note</h6>
                                        <form id="progressNoteForm">
                                            <input type="hidden" id="noteAdmissionId" name="admission_id">
                                            <div class="row g-2">
                                                <div class="col-md-3">
                                                    <select class="form-select" name="shift_type" id="shiftType" required>
                                                        <option value="">Shift Type</option>
                                                        <option value="morning">Morning</option>
                                                        <option value="afternoon">Afternoon</option>
                                                        <option value="night">Night</option>
                                                    </select>
                                                </div>
                                                <div class="col-md-7">
                                                    <textarea class="form-control" name="progress_note" id="progressNote" rows="2" placeholder="Enter clinical progress note..." required></textarea>
                                                </div>
                                                <div class="col-md-2">
                                                    <button type="submit" class="btn-submit w-100"><i class="fas fa-save"></i> Save</button>
                                                </div>
                                            </div>
                                        </form>
                                    </div>
                                    <h6 class="fw-bold mt-3"><i class="fas fa-history me-2"></i> Historical Notes</h6>
                                    <div id="notesHistoryContainer" class="mt-2" style="max-height: 350px; overflow-y: auto;">
                                        <!-- dynamic notes -->
                                    </div>
                                </div>

                                <!-- TAB 2: Vitals Timeline -->
                                <div class="tab-pane fade" id="vitals" role="tabpanel">
                                    <div class="mb-4 p-3 bg-light rounded">
                                        <h6 class="fw-bold"><i class="fas fa-heartbeat me-2 text-danger"></i> Record Vitals</h6>
                                        <form id="vitalsForm">
                                            <input type="hidden" id="vitalsAdmissionId" name="admission_id">
                                            <div class="row g-2">
                                                <div class="col-md-2"><input type="number" step="0.1" class="form-control" name="temperature" placeholder="Temp (°C)"></div>
                                                <div class="col-md-2"><input type="number" class="form-control" name="pulse_rate" placeholder="Pulse (bpm)"></div>
                                                <div class="col-md-3"><input type="text" class="form-control" name="blood_pressure" placeholder="BP (e.g., 120/80)"></div>
                                                <div class="col-md-2"><input type="number" class="form-control" name="respiratory_rate" placeholder="RR"></div>
                                                <div class="col-md-3"><input type="number" class="form-control" name="spo2" placeholder="SpO₂ %"></div>
                                            </div>
                                            <div class="row mt-2">
                                                <div class="col-12 text-end">
                                                    <button type="submit" class="btn-submit"><i class="fas fa-save"></i> Record Vitals</button>
                                                </div>
                                            </div>
                                        </form>
                                    </div>
                                    <h6 class="fw-bold"><i class="fas fa-chart-line me-2"></i> Vitals Timeline</h6>
                                    <div id="vitalsHistoryContainer" style="max-height: 350px; overflow-y: auto;">
                                        <!-- dynamic vitals log -->
                                    </div>
                                </div>

                                <!-- TAB 3: IO Fluid Chart -->
                                <div class="tab-pane fade" id="io" role="tabpanel">
                                    <div class="io-summary-card" id="ioSummary">
                                        <div class="row text-center">
                                            <div class="col-4"><strong>Total Intake</strong><br><span id="totalIntake">0</span> ml</div>
                                            <div class="col-4"><strong>Total Output</strong><br><span id="totalOutput">0</span> ml</div>
                                            <div class="col-4"><strong>Net Balance</strong><br><span id="netBalance" class="fw-bold">0</span> ml</div>
                                        </div>
                                    </div>
                                    <div class="mb-4">
                                        <h6 class="fw-bold"><i class="fas fa-plus-circle me-2"></i> Add Intake / Output</h6>
                                        <form id="ioForm">
                                            <input type="hidden" id="ioAdmissionId" name="admission_id">
                                            <div class="row g-2">
                                                <div class="col-md-2"><input type="number" class="form-control" name="intake_ml" placeholder="Intake (ml)"></div>
                                                <div class="col-md-2">
                                                    <select class="form-select" name="intake_type">
                                                        <option value="">Type</option>
                                                        <option value="iv_fluid">IV Fluid</option>
                                                        <option value="oral">Oral</option>
                                                        <option value="ng_tube">NG Tube</option>
                                                        <option value="blood_product">Blood Product</option>
                                                        <option value="other">Other</option>
                                                    </select>
                                                </div>
                                                <div class="col-md-2"><input type="number" class="form-control" name="output_ml" placeholder="Output (ml)"></div>
                                                <div class="col-md-2">
                                                    <select class="form-select" name="output_type">
                                                        <option value="">Type</option>
                                                        <option value="urine">Urine</option>
                                                        <option value="vomit">Vomit</option>
                                                        <option value="drainage">Drainage</option>
                                                        <option value="stool">Stool</option>
                                                        <option value="other">Other</option>
                                                    </select>
                                                </div>
                                                <div class="col-md-2"><input type="text" class="form-control" name="notes" placeholder="Notes"></div>
                                                <div class="col-md-2"><button type="submit" class="btn-submit w-100"><i class="fas fa-save"></i> Add</button></div>
                                            </div>
                                        </form>
                                    </div>
                                    <h6 class="fw-bold"><i class="fas fa-list me-2"></i> Recent IO Records</h6>
                                    <div id="ioRecordsContainer" style="max-height: 300px; overflow-y: auto;">
                                        <!-- dynamic records -->
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php include('assets/inc/footer.php'); ?>
</div>

<div id="loadingOverlay" class="loading-overlay"><div class="spinner"></div></div>

<script src="assets/js/vendor.min.js"></script>
<script src="assets/js/bootstrap.bundle.min.js"></script>
<script>
const csrfToken = <?php echo json_encode($csrf_token); ?>;
let currentAdmissionId = null;

function showToast(message, type = 'success') {
    const toast = document.createElement('div');
    toast.className = `toast-notification ${type === 'error' ? 'toast-error' : ''}`;
    toast.innerHTML = `<i class="fas fa-${type === 'error' ? 'exclamation-circle' : 'check-circle'}"></i> <span>${escapeHtml(message)}</span>`;
    document.body.appendChild(toast);
    setTimeout(() => toast.remove(), 3000);
}

function escapeHtml(unsafe) {
    if (!unsafe) return '';
    return String(unsafe).replace(/[&<>]/g, m => m === '&' ? '&amp;' : (m === '<' ? '&lt;' : '&gt;'));
}

function showLoading(show) {
    document.getElementById('loadingOverlay').classList.toggle('active', show);
}

function loadClinicalData(admissionId) {
    showLoading(true);
    fetch(window.location.pathname + `?action=load_patient_clinical_data&admission_id=${admissionId}`)
        .then(res => res.json())
        .then(data => {
            showLoading(false);
            if (data.success && data.data) {
                const d = data.data;
                const patient = d.patient;
                if (!patient) {
                    showToast('Patient data not found', 'error');
                    return;
                }
                document.getElementById('patientNameHeader').innerText = patient.patient_name || 'Unknown';
                document.getElementById('mrNumberHeader').innerText = patient.mr_number || '-';
                document.getElementById('genderHeader').innerText = patient.gender || '-';
                document.getElementById('ageHeader').innerText = patient.age || 'N/A';
                document.getElementById('noteAdmissionId').value = admissionId;
                document.getElementById('vitalsAdmissionId').value = admissionId;
                document.getElementById('ioAdmissionId').value = admissionId;

                // Progress notes
                const notesContainer = document.getElementById('notesHistoryContainer');
                const notes = d.notes || [];
                if (notes.length) {
                    let notesHtml = '';
                    notes.forEach(n => {
                        const shiftLabel = { morning: 'Morning', afternoon: 'Afternoon', night: 'Night' }[n.shift_type] || n.shift_type;
                        const dateStr = new Date(n.created_at).toLocaleString();
                        notesHtml += `<div class="history-item">
                            <div class="d-flex justify-content-between">
                                <span class="fw-bold">${shiftLabel} Shift</span>
                                <small class="text-muted">${dateStr}</small>
                            </div>
                            <div>${escapeHtml(n.progress_note)}</div>
                        </div>`;
                    });
                    notesContainer.innerHTML = notesHtml;
                } else {
                    notesContainer.innerHTML = '<div class="empty-state small">No notes recorded yet.</div>';
                }

                // Vitals
                const vitalsContainer = document.getElementById('vitalsHistoryContainer');
                const vitals = d.vitals || [];
                if (vitals.length) {
                    let vitalsHtml = '';
                    vitals.forEach(v => {
                        const bpDisplay = v.blood_pressure || (v.blood_pressure_systolic && v.blood_pressure_diastolic ? 
                            v.blood_pressure_systolic + '/' + v.blood_pressure_diastolic : '-');
                        vitalsHtml += `<div class="vitals-row d-flex justify-content-between align-items-center flex-wrap">
                            <div><strong>${new Date(v.recorded_at).toLocaleString()}</strong></div>
                            <div>Temp: ${v.temperature ?? '-'}°C | BP: ${bpDisplay} | Pulse: ${v.pulse_rate ?? '-'} | RR: ${v.respiratory_rate ?? '-'} | SpO₂: ${v.spo2 ?? '-'}%</div>
                        </div>`;
                    });
                    vitalsContainer.innerHTML = vitalsHtml;
                } else {
                    vitalsContainer.innerHTML = '<div class="empty-state small">No vitals recorded yet.</div>';
                }

                // IO totals
                document.getElementById('totalIntake').innerText = d.totals.total_intake;
                document.getElementById('totalOutput').innerText = d.totals.total_output;
                document.getElementById('netBalance').innerText = d.totals.net_balance;
                const balanceElem = document.getElementById('netBalance');
                if (d.totals.net_balance >= 0) balanceElem.style.color = '#059669';
                else balanceElem.style.color = '#dc2626';

                // IO records list
                const ioContainer = document.getElementById('ioRecordsContainer');
                const ioRecords = d.io_records || [];
                if (ioRecords.length) {
                    let ioHtml = '<table class="table table-sm table-hover"><thead><tr><th>Time</th><th>Intake (ml)</th><th>Type</th><th>Output (ml)</th><th>Type</th><th>Notes</th></tr></thead><tbody>';
                    ioRecords.forEach(io => {
                        const intakeTypeLabel = { iv_fluid: 'IV Fluid', oral: 'Oral', ng_tube: 'NG Tube', blood_product: 'Blood Product' }[io.intake_type] || io.intake_type || '-';
                        const outputTypeLabel = { urine: 'Urine', vomit: 'Vomit', drainage: 'Drainage', stool: 'Stool' }[io.output_type] || io.output_type || '-';
                        ioHtml += `<tr>
                            <td>${new Date(io.recorded_at).toLocaleTimeString()}</td>
                            <td>${io.intake_ml || 0}</td>
                            <td>${escapeHtml(intakeTypeLabel)}</td>
                            <td>${io.output_ml || 0}</td>
                            <td>${escapeHtml(outputTypeLabel)}</td>
                            <td>${escapeHtml(io.notes || '-')}</td>
                        </tr>`;
                    });
                    ioHtml += '</tbody></table>';
                    ioContainer.innerHTML = ioHtml;
                } else {
                    ioContainer.innerHTML = '<div class="empty-state small">No fluid records.</div>';
                }

                document.getElementById('emptyStateContainer').style.display = 'none';
                document.getElementById('activeWorkspaceContainer').style.display = 'block';
            } else {
                showToast(data.message || 'Failed to load data', 'error');
            }
        })
        .catch(err => { showLoading(false); showToast('Network error', 'error'); console.error(err); });
}

// Form submissions
$('#progressNoteForm').on('submit', function(e) {
    e.preventDefault();
    const admissionId = $('#noteAdmissionId').val();
    const shiftType = $('#shiftType').val();
    const progressNote = $('#progressNote').val();
    if (!admissionId || !shiftType || !progressNote) { showToast('Please fill all fields', 'error'); return; }
    showLoading(true);
    $.post(window.location.pathname + '?action=submit_progress_note', {
        admission_id: admissionId,
        shift_type: shiftType,
        progress_note: progressNote,
        csrf_token: csrfToken
    }, function(res) {
        showLoading(false);
        if (res.success) {
            showToast(res.message);
            $('#shiftType').val('');
            $('#progressNote').val('');
            loadClinicalData(admissionId);
        } else showToast(res.message, 'error');
    }).fail(() => { showLoading(false); showToast('Network error', 'error'); });
});

$('#vitalsForm').on('submit', function(e) {
    e.preventDefault();
    const admissionId = $('#vitalsAdmissionId').val();
    const formData = $(this).serializeArray();
    const dataObj = { admission_id: admissionId, csrf_token: csrfToken };
    formData.forEach(f => { dataObj[f.name] = f.value; });
    if (!admissionId) { showToast('Missing patient context', 'error'); return; }
    showLoading(true);
    $.post(window.location.pathname + '?action=submit_recursive_vitals', dataObj, function(res) {
        showLoading(false);
        if (res.success) {
            showToast(res.message);
            $('#vitalsForm')[0].reset();
            loadClinicalData(admissionId);
        } else showToast(res.message, 'error');
    }).fail(() => { showLoading(false); showToast('Network error', 'error'); });
});

$('#ioForm').on('submit', function(e) {
    e.preventDefault();
    const admissionId = $('#ioAdmissionId').val();
    const formData = $(this).serializeArray();
    const dataObj = { admission_id: admissionId, csrf_token: csrfToken };
    formData.forEach(f => { dataObj[f.name] = f.value; });
    if (!admissionId) { showToast('Missing patient context', 'error'); return; }
    showLoading(true);
    $.post(window.location.pathname + '?action=submit_io_log', dataObj, function(res) {
        showLoading(false);
        if (res.success) {
            showToast(res.message);
            $('#ioForm')[0].reset();
            loadClinicalData(admissionId);
        } else showToast(res.message, 'error');
    }).fail(() => { showLoading(false); showToast('Network error', 'error'); });
});

// Patient card click
$(document).on('click', '.patient-card', function() {
    const admissionId = $(this).data('admission-id');
    if (!admissionId) return;
    $('.patient-card').removeClass('active');
    $(this).addClass('active');
    currentAdmissionId = admissionId;
    loadClinicalData(admissionId);
});

$(document).ready(() => {
    const firstCard = document.querySelector('.patient-card');
    if (firstCard) {
        firstCard.classList.add('active');
        const firstId = firstCard.getAttribute('data-admission-id');
        if (firstId) loadClinicalData(firstId);
    }
});

// Sidebar compatibility
if (typeof MetisMenu !== 'undefined') {
    const sideMenu = document.getElementById('side-menu');
    if (sideMenu && !sideMenu.hasAttribute('data-metismenu-initialized')) {
        new MetisMenu(sideMenu, { toggle: true, triggerElement: 'a.has-arrow', parentTrigger: 'li', subMenu: 'ul' });
        sideMenu.setAttribute('data-metismenu-initialized', 'true');
    }
}
document.getElementById('mobileMenuToggle')?.addEventListener('click', function(e) {
    e.preventDefault();
    document.body.classList.toggle('sidebar-enable');
});
window.addEventListener('resize', function() {
    if (window.innerWidth > 768 && document.body.classList.contains('sidebar-enable')) document.body.classList.remove('sidebar-enable');
});
</script>
</body>
</html>