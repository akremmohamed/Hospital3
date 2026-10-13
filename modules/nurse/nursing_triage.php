<?php
/**
 * Nurse Dashboard - Vital Signs Recording Module
 * HMS ERP System
 * 
 * Compatible with Reception Dashboard workflow:
 * - Patients routed from reception to triage appear here
 * - Nurses record vitals and route to doctors
 * - Integration with triage_records, patient_visits, appointments tables
 * 
 * @package HMS-ERP
 * @subpackage Nursing
 */

if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../../config/db_connection.php';
require_once __DIR__ . '/../../includes/auth_helper.php';

// =============================================================================
// AUTHENTICATION & INITIALIZATION
// =============================================================================
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../index.php');
    exit;
}

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrf_token = $_SESSION['csrf_token'];
$branch_id = (int)($_SESSION['branch_id'] ?? 0);
$user_id = (int)($_SESSION['user_id'] ?? 0);
$user_name = $_SESSION['first_name'] ?? $_SESSION['username'] ?? 'Nurse';
$user_type = $_SESSION['user_type'] ?? '';
$is_global_admin = $user_type === 'super_admin';
$is_nurse = ($user_type === 'nurse' || $is_global_admin);

// Permission check
if (!$is_nurse) {
    header('Location: ../unauthorized.php');
    exit;
}

$db_available = is_db_available();
if (!$db_available) {
    die('Database connection failed. Please contact system administrator.');
}

// =============================================================================
// UNIFIED AJAX HANDLER
// =============================================================================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'nurse_action') {
    error_reporting(0);
    ini_set('display_errors', 0);
    
    if (ob_get_level()) ob_clean();
    header('Content-Type: application/json; charset=utf-8');

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
        exit;
    }

    $submitted_token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $submitted_token)) {
        echo json_encode(['success' => false, 'message' => 'Security validation failed. Please refresh the page.']);
        exit;
    }

    $action = (string)($_POST['action'] ?? '');
    $db = getDB();
    
    if (!$db) {
        echo json_encode(['success' => false, 'message' => 'Database connection failed']);
        exit;
    }

    $request_branch = $is_global_admin ? (int)($_POST['branch_id'] ?? $branch_id) : $branch_id;

    // -------------------------------------------------------------------------
    // GET TRIAGE QUEUE - Patients waiting for vitals
    // -------------------------------------------------------------------------
    if ($action === 'get_triage_queue') {
        $sql = "SELECT 
                    pv.id AS visit_id,
                    pv.visit_number,
                    p.id AS patient_id,
                    p.first_name,
                    p.last_name,
                    p.middle_name,
                    p.mr_number,
                    p.phone,
                    p.date_of_birth,
                    p.gender,
                    pv.created_at AS arrived_at,
                    pv.status AS visit_status,
                    a.chief_complaint,
                    a.appointment_number,
                    COALESCE(d.name, 'General') AS department_name,
                    tr.id AS triage_id,
                    tr.priority AS triage_priority,
                    tr.blood_pressure_systolic,
                    tr.blood_pressure_diastolic,
                    tr.temperature_celsius,
                    tr.heart_rate
                FROM patient_visits pv
                INNER JOIN patients p ON p.id = pv.patient_id AND p.deleted_at IS NULL
                INNER JOIN invoices i ON pv.id = i.visit_id AND i.deleted_at IS NULL
                LEFT JOIN appointments a ON a.visit_id = pv.id AND a.deleted_at IS NULL
                LEFT JOIN departments d ON d.id = a.department_id
                LEFT JOIN triage_records tr ON tr.visit_id = pv.id AND tr.deleted_at IS NULL
                WHERE pv.status = 'checked_in'
                  AND i.payment_status = 'paid'
                  AND pv.branch_id = :b
                  AND pv.deleted_at IS NULL
                  AND (tr.id IS NULL OR (tr.blood_pressure_systolic IS NULL OR tr.blood_pressure_systolic = 0))
                ORDER BY 
                    CASE WHEN pv.is_priority = 1 THEN 1 ELSE 2 END,
                    pv.created_at ASC
                LIMIT 50";
        
        $stmt = $db->prepare($sql);
        $stmt->execute([':b' => $request_branch]);
        $queue = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Calculate age for each patient
        foreach ($queue as &$patient) {
            if (!empty($patient['date_of_birth'])) {
                $birthDate = new DateTime($patient['date_of_birth']);
                $today = new DateTime();
                $age = $birthDate->diff($today);
                $patient['age'] = $age->y;
                $patient['age_display'] = $age->y . ' years';
            } else {
                $patient['age'] = null;
                $patient['age_display'] = 'N/A';
            }
        }
        
        echo json_encode(['success' => true, 'queue' => $queue]);
        exit;
    }

    // -------------------------------------------------------------------------
    // GET TRIAGE RECORD BY VISIT ID
    // -------------------------------------------------------------------------
    if ($action === 'get_triage_record') {
        $visit_id = (int)($_POST['visit_id'] ?? 0);
        
        $sql = "SELECT 
                    id, visit_id, patient_id, priority, esi_level,
                    heart_rate, blood_pressure_systolic, blood_pressure_diastolic,
                    temperature_celsius, respiratory_rate, oxygen_saturation,
                    pain_score, consciousness, weight_kg, height_cm, bmi,
                    allergies, current_medications, chief_complaint,
                    history_of_present_illness, triage_notes, disposition
                FROM triage_records
                WHERE visit_id = :vid AND deleted_at IS NULL
                LIMIT 1";
        
        $stmt = $db->prepare($sql);
        $stmt->execute([':vid' => $visit_id]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC);
        
        echo json_encode(['success' => true, 'record' => $record]);
        exit;
    }

    // -------------------------------------------------------------------------
    // GET PATIENT DETAILS
    // -------------------------------------------------------------------------
    if ($action === 'get_patient_details') {
        $patient_id = (int)($_POST['patient_id'] ?? 0);
        
        $sql = "SELECT 
                    id, first_name, last_name, middle_name, mr_number,
                    phone, email, date_of_birth, gender, blood_group,
                    address, emergency_contact_name, emergency_contact_phone
                FROM patients
                WHERE id = :pid AND deleted_at IS NULL";
        
        $stmt = $db->prepare($sql);
        $stmt->execute([':pid' => $patient_id]);
        $patient = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($patient && !empty($patient['date_of_birth'])) {
            $birthDate = new DateTime($patient['date_of_birth']);
            $today = new DateTime();
            $age = $birthDate->diff($today);
            $patient['age'] = $age->y;
        }
        
        echo json_encode(['success' => true, 'patient' => $patient]);
        exit;
    }

    // -------------------------------------------------------------------------
    // SAVE TRIAGE VITALS
    // -------------------------------------------------------------------------
    if ($action === 'save_vitals') {
        $visit_id = (int)($_POST['visit_id'] ?? 0);
        $patient_id = (int)($_POST['patient_id'] ?? 0);
        
        // Vital signs data - priority uses enum: red, yellow, green, blue
        $priority = $_POST['priority'] ?? 'yellow';
        // Ensure priority is valid for database enum
        $valid_priorities = ['red', 'yellow', 'green', 'blue'];
        if (!in_array($priority, $valid_priorities)) {
            $priority = 'yellow';
        }
        
        $esi_level = !empty($_POST['esi_level']) ? (int)$_POST['esi_level'] : null;
        $heart_rate = !empty($_POST['heart_rate']) ? (int)$_POST['heart_rate'] : null;
        $blood_pressure_systolic = !empty($_POST['blood_pressure_systolic']) ? (int)$_POST['blood_pressure_systolic'] : null;
        $blood_pressure_diastolic = !empty($_POST['blood_pressure_diastolic']) ? (int)$_POST['blood_pressure_diastolic'] : null;
        $temperature_celsius = !empty($_POST['temperature_celsius']) ? (float)$_POST['temperature_celsius'] : null;
        $respiratory_rate = !empty($_POST['respiratory_rate']) ? (int)$_POST['respiratory_rate'] : null;
        $oxygen_saturation = !empty($_POST['oxygen_saturation']) ? (int)$_POST['oxygen_saturation'] : null;
        $pain_score = !empty($_POST['pain_score']) ? (int)$_POST['pain_score'] : null;
        $consciousness = $_POST['consciousness'] ?? null;
        $weight_kg = !empty($_POST['weight_kg']) ? (float)$_POST['weight_kg'] : null;
        $height_cm = !empty($_POST['height_cm']) ? (float)$_POST['height_cm'] : null;
        
        // Calculate BMI
        $bmi = null;
        if ($weight_kg && $height_cm && $height_cm > 0) {
            $height_m = $height_cm / 100;
            $bmi = round($weight_kg / ($height_m * $height_m), 1);
        }
        
        $allergies = trim($_POST['allergies'] ?? '');
        $current_medications = trim($_POST['current_medications'] ?? '');
        $chief_complaint = trim($_POST['chief_complaint'] ?? '');
        $history_of_present_illness = trim($_POST['history_of_present_illness'] ?? '');
        $triage_notes = trim($_POST['triage_notes'] ?? '');
        
        // Disposition and routing
        $disposition = $_POST['disposition'] ?? 'discharged';
        $send_to_doctor = isset($_POST['send_to_doctor']) && $_POST['send_to_doctor'] == '1';
        
        if ($visit_id === 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid visit ID']);
            exit;
        }
        
        $db->beginTransaction();
        
        try {
            // Check if triage record exists
            $checkStmt = $db->prepare("SELECT id FROM triage_records WHERE visit_id = :vid AND deleted_at IS NULL");
            $checkStmt->execute([':vid' => $visit_id]);
            $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($existing) {
                // Update existing triage record
                $updateStmt = $db->prepare("UPDATE triage_records SET
                    priority = :priority,
                    esi_level = :esi_level,
                    heart_rate = :heart_rate,
                    blood_pressure_systolic = :bp_sys,
                    blood_pressure_diastolic = :bp_dia,
                    temperature_celsius = :temp,
                    respiratory_rate = :resp,
                    oxygen_saturation = :o2,
                    pain_score = :pain,
                    consciousness = :conscious,
                    weight_kg = :weight,
                    height_cm = :height,
                    bmi = :bmi,
                    allergies = :allergies,
                    current_medications = :meds,
                    chief_complaint = :cc,
                    history_of_present_illness = :hpi,
                    triage_notes = :notes,
                    disposition = :disposition,
                    updated_at = NOW()
                    WHERE id = :id");
                
                $updateStmt->execute([
                    ':priority' => $priority,
                    ':esi_level' => $esi_level,
                    ':heart_rate' => $heart_rate,
                    ':bp_sys' => $blood_pressure_systolic,
                    ':bp_dia' => $blood_pressure_diastolic,
                    ':temp' => $temperature_celsius,
                    ':resp' => $respiratory_rate,
                    ':o2' => $oxygen_saturation,
                    ':pain' => $pain_score,
                    ':conscious' => $consciousness,
                    ':weight' => $weight_kg,
                    ':height' => $height_cm,
                    ':bmi' => $bmi,
                    ':allergies' => $allergies ?: null,
                    ':meds' => $current_medications ?: null,
                    ':cc' => $chief_complaint ?: null,
                    ':hpi' => $history_of_present_illness ?: null,
                    ':notes' => $triage_notes ?: null,
                    ':disposition' => $disposition,
                    ':id' => $existing['id']
                ]);
            } else {
                // Insert new triage record
                $insertStmt = $db->prepare("INSERT INTO triage_records
                    (branch_id, patient_id, visit_id, taken_by_user_id, priority, esi_level,
                     heart_rate, blood_pressure_systolic, blood_pressure_diastolic,
                     temperature_celsius, respiratory_rate, oxygen_saturation,
                     pain_score, consciousness, weight_kg, height_cm, bmi,
                     allergies, current_medications, chief_complaint,
                     history_of_present_illness, triage_notes, disposition,
                     created_at, updated_at)
                    VALUES
                    (:branch, :pid, :vid, :taken, :priority, :esi,
                     :hr, :bp_sys, :bp_dia, :temp, :resp, :o2,
                     :pain, :conscious, :weight, :height, :bmi,
                     :allergies, :meds, :cc, :hpi, :notes, :disposition,
                     NOW(), NOW())");
                
                $insertStmt->execute([
                    ':branch' => $request_branch,
                    ':pid' => $patient_id,
                    ':vid' => $visit_id,
                    ':taken' => $user_id,
                    ':priority' => $priority,
                    ':esi' => $esi_level,
                    ':hr' => $heart_rate,
                    ':bp_sys' => $blood_pressure_systolic,
                    ':bp_dia' => $blood_pressure_diastolic,
                    ':temp' => $temperature_celsius,
                    ':resp' => $respiratory_rate,
                    ':o2' => $oxygen_saturation,
                    ':pain' => $pain_score,
                    ':conscious' => $consciousness,
                    ':weight' => $weight_kg,
                    ':height' => $height_cm,
                    ':bmi' => $bmi,
                    ':allergies' => $allergies ?: null,
                    ':meds' => $current_medications ?: null,
                    ':cc' => $chief_complaint ?: null,
                    ':hpi' => $history_of_present_illness ?: null,
                    ':notes' => $triage_notes ?: null,
                    ':disposition' => $disposition
                ]);
            }
            
            // Update patient visit status based on routing
            if ($send_to_doctor) {
                $visitStmt = $db->prepare("UPDATE patient_visits 
                    SET status = 'in_consultation', consultation_started_at = NOW(), updated_at = NOW()
                    WHERE id = :vid");
                $visitStmt->execute([':vid' => $visit_id]);
                
                // Update appointment status
                $apptStmt = $db->prepare("UPDATE appointments 
                    SET status = 'in_progress', updated_at = NOW()
                    WHERE visit_id = :vid");
                $apptStmt->execute([':vid' => $visit_id]);
                
                $message = 'Vitals recorded successfully. Patient routed to doctor consultation.';
            } else {
                // Update visit to in_triage if not routing to doctor
                $visitStmt = $db->prepare("UPDATE patient_visits 
                    SET status = 'in_triage', updated_at = NOW()
                    WHERE id = :vid");
                $visitStmt->execute([':vid' => $visit_id]);
                
                $message = 'Vitals recorded successfully. Patient remains in triage.';
            }
            
            $db->commit();
            
            echo json_encode([
                'success' => true,
                'message' => $message,
                'routed_to_doctor' => $send_to_doctor
            ]);
            
        } catch (Exception $e) {
            $db->rollBack();
            error_log("Vitals save error: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Failed to save vitals: ' . $e->getMessage()]);
        }
        
        exit;
    }

    // -------------------------------------------------------------------------
    // ROUTE TO DOCTOR (without new vitals)
    // -------------------------------------------------------------------------
    if ($action === 'route_to_doctor') {
        $visit_id = (int)($_POST['visit_id'] ?? 0);
        
        if ($visit_id === 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid visit ID']);
            exit;
        }
        
        $db->beginTransaction();
        
        try {
            $visitStmt = $db->prepare("UPDATE patient_visits 
                SET status = 'in_consultation', consultation_started_at = NOW(), updated_at = NOW()
                WHERE id = :vid AND status IN ('checked_in', 'in_triage')");
            $visitStmt->execute([':vid' => $visit_id]);
            
            if ($visitStmt->rowCount() === 0) {
                throw new Exception('Patient not in triage or already routed');
            }
            
            $apptStmt = $db->prepare("UPDATE appointments 
                SET status = 'in_progress', updated_at = NOW()
                WHERE visit_id = :vid");
            $apptStmt->execute([':vid' => $visit_id]);
            
            $db->commit();
            
            echo json_encode(['success' => true, 'message' => 'Patient routed to doctor consultation']);
        } catch (Exception $e) {
            $db->rollBack();
            echo json_encode(['success' => false, 'message' => 'Failed to route patient: ' . $e->getMessage()]);
        }
        
        exit;
    }

    // -------------------------------------------------------------------------
    // GET DASHBOARD KPIs
    // -------------------------------------------------------------------------
    if ($action === 'kpis') {
        try {
            // Patients waiting for triage
            $waitingStmt = $db->prepare("SELECT COUNT(DISTINCT pv.id) 
                FROM patient_visits pv
                INNER JOIN invoices i ON pv.id = i.visit_id AND i.deleted_at IS NULL
                LEFT JOIN triage_records tr ON tr.visit_id = pv.id AND tr.deleted_at IS NULL
                WHERE pv.status = 'checked_in'
                  AND i.payment_status = 'paid'
                  AND pv.branch_id = :b
                  AND pv.deleted_at IS NULL
                  AND (tr.id IS NULL OR tr.blood_pressure_systolic IS NULL OR tr.blood_pressure_systolic = 0)");
            $waitingStmt->execute([':b' => $request_branch]);
            $waiting_triage = (int)$waitingStmt->fetchColumn();
            
            // Today's triage completed
            $completedStmt = $db->prepare("SELECT COUNT(DISTINCT pv.id)
                FROM patient_visits pv
                INNER JOIN triage_records tr ON tr.visit_id = pv.id AND tr.deleted_at IS NULL
                WHERE DATE(tr.created_at) = CURDATE()
                  AND pv.branch_id = :b
                  AND tr.blood_pressure_systolic IS NOT NULL
                  AND tr.blood_pressure_systolic > 0");
            $completedStmt->execute([':b' => $request_branch]);
            $triage_today = (int)$completedStmt->fetchColumn();
            
            // Critical patients currently in triage (red priority)
            $criticalStmt = $db->prepare("SELECT COUNT(DISTINCT pv.id)
                FROM patient_visits pv
                INNER JOIN triage_records tr ON tr.visit_id = pv.id AND tr.deleted_at IS NULL
                WHERE pv.status IN ('checked_in', 'in_triage')
                  AND pv.branch_id = :b
                  AND tr.priority = 'red'");
            $criticalStmt->execute([':b' => $request_branch]);
            $critical_patients = (int)$criticalStmt->fetchColumn();
            
            // Active consultations
            $activeStmt = $db->prepare("SELECT COUNT(*) FROM patient_visits 
                WHERE status = 'in_consultation' AND branch_id = :b AND deleted_at IS NULL");
            $activeStmt->execute([':b' => $request_branch]);
            $active_consultations = (int)$activeStmt->fetchColumn();
            
            echo json_encode([
                'success' => true,
                'kpis' => [
                    'waiting_triage' => $waiting_triage,
                    'triage_today' => $triage_today,
                    'critical_patients' => $critical_patients,
                    'active_consultations' => $active_consultations
                ]
            ]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action']);
    exit;
}

// =============================================================================
// PAGE LOAD - Get clinical thresholds for validation
// =============================================================================
$thresholds = [];
$pdo = getDB();

if ($pdo) {
    $thresholdStmt = $pdo->query("SELECT metric_name, min_normal, max_normal, critical_low, critical_high, alert_color 
                                   FROM clinical_thresholds");
    $thresholds = $thresholdStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format thresholds into associative array for JavaScript
    $thresholds_js = [];
    foreach ($thresholds as $t) {
        $thresholds_js[$t['metric_name']] = [
            'min_normal' => $t['min_normal'],
            'max_normal' => $t['max_normal'],
            'critical_low' => $t['critical_low'],
            'critical_high' => $t['critical_high']
        ];
    }
}

$current_date_display = date('l, F j, Y');
$current_time_display = date('g:i A');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nurse Dashboard - Vital Signs | HMS ERP</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
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
        
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #f5f7fb 0%, #eef2f6 100%);
            overflow-x: hidden;
        }
        
        .content-page {
            margin-left: 260px;
            margin-top: 70px;
            padding: 24px 32px;
            transition: margin-left 0.3s;
            min-height: calc(100vh - 70px);
        }
        
        body.erp-sidebar-collapsed .content-page { margin-left: 80px; }
        
        @media (max-width: 768px) {
            .content-page { margin-left: 0 !important; padding: 16px; }
        }
        
        .welcome-header {
            background: linear-gradient(135deg, #1e3a5f 0%, var(--primary) 100%);
            border-radius: 24px;
            padding: 28px 32px;
            color: white;
            margin-bottom: 28px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }
        
        .welcome-title { font-size: 26px; font-weight: 700; margin-bottom: 6px; }
        .welcome-subtitle { color: rgba(255,255,255,0.8); font-size: 14px; }
        
        .time-badge {
            background: rgba(255,255,255,0.15);
            padding: 10px 20px;
            border-radius: 14px;
            font-size: 13px;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            backdrop-filter: blur(10px);
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-bottom: 28px;
        }
        
        @media (max-width: 1000px) { .stats-grid { grid-template-columns: repeat(2, 1fr); } }
        @media (max-width: 600px) { .stats-grid { grid-template-columns: 1fr; } }
        
        .stat-card {
            background: white;
            border-radius: 20px;
            padding: 22px;
            transition: all 0.3s;
            border: 1px solid var(--gray-light);
            position: relative;
            overflow: hidden;
        }
        
        .stat-card:hover { transform: translateY(-3px); box-shadow: 0 15px 35px rgba(0,0,0,0.08); }
        
        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
        }
        
        .stat-card.primary::before { background: linear-gradient(90deg, var(--primary), var(--primary-dark)); }
        .stat-card.warning::before { background: linear-gradient(90deg, var(--warning), #d97706); }
        .stat-card.danger::before { background: linear-gradient(90deg, var(--danger), #c0392b); }
        .stat-card.info::before { background: linear-gradient(90deg, var(--info), #0891b2); }
        
        .stat-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 16px;
        }
        
        .stat-icon {
            width: 52px;
            height: 52px;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
        }
        
        .stat-icon.primary { background: rgba(67,97,238,0.1); color: var(--primary); }
        .stat-icon.warning { background: rgba(249,199,79,0.1); color: var(--warning); }
        .stat-icon.danger { background: rgba(239,71,111,0.1); color: var(--danger); }
        .stat-icon.info { background: rgba(76,201,240,0.1); color: var(--info); }
        
        .stat-value { font-size: 34px; font-weight: 800; color: var(--dark); line-height: 1.1; }
        .stat-label { font-size: 13px; color: var(--gray); font-weight: 500; margin-top: 4px; }
        
        .quick-actions {
            background: white;
            border-radius: 20px;
            padding: 20px 28px;
            margin-bottom: 28px;
            border: 1px solid var(--gray-light);
            display: flex;
            gap: 16px;
            flex-wrap: wrap;
        }
        
        .quick-btn {
            padding: 12px 28px;
            border-radius: 14px;
            font-size: 14px;
            font-weight: 600;
            border: none;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            transition: all 0.2s;
        }
        
        .quick-btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
        }
        
        .quick-btn-primary:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(67,97,238,0.3); }
        
        .quick-btn-success {
            background: linear-gradient(135deg, var(--success), #059669);
            color: white;
        }
        
        .quick-btn-success:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(6,214,160,0.3); }
        
        .quick-btn-outline {
            background: white;
            border: 1px solid var(--gray-light);
            color: var(--dark);
        }
        
        .quick-btn-outline:hover { border-color: var(--primary); color: var(--primary); background: #f8fafc; }
        
        .triage-queue-section {
            background: white;
            border-radius: 20px;
            border: 1px solid var(--gray-light);
            overflow: hidden;
        }
        
        .section-header {
            padding: 20px 24px;
            background: #f8fafc;
            border-bottom: 1px solid var(--gray-light);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
        }
        
        .section-header h3 { margin: 0; font-size: 18px; font-weight: 700; color: var(--dark); }
        
        .data-table { width: 100%; border-collapse: collapse; }
        
        .data-table th {
            padding: 14px 20px;
            font-size: 12px;
            font-weight: 600;
            color: var(--gray);
            text-transform: uppercase;
            background: #f8fafc;
            border-bottom: 1px solid var(--gray-light);
        }
        
        .data-table td {
            padding: 12px 20px;
            font-size: 13px;
            border-bottom: 1px solid #f3f4f6;
            vertical-align: middle;
        }
        
        .data-table tr:hover { background: #f8fafc; }
        
        .badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
        }
        
        .badge-primary { background: #e0e7ff; color: var(--primary); }
        .badge-success { background: #d1fae5; color: var(--success); }
        .badge-warning { background: #fef3c7; color: #d97706; }
        .badge-danger { background: #fee2e2; color: var(--danger); }
        .badge-secondary { background: #e5e7eb; color: #4b5563; }
        
        .priority-red { background: #fee2e2; color: #dc2626; }
        .priority-yellow { background: #fef3c7; color: #d97706; }
        .priority-green { background: #d1fae5; color: #059669; }
        .priority-blue { background: #dbeafe; color: #2563eb; }
        
        .emergency-alert {
            animation: blinkRed 0.8s ease-in-out infinite;
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
        }
        
        @keyframes blinkRed {
            0% { background-color: #fee2e2; color: #ef476f; }
            50% { background-color: #ef476f; color: white; }
            100% { background-color: #fee2e2; color: #ef476f; }
        }

        /* ========== FIXED MODAL STYLES ========== */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.6);
            z-index: 9999;
            align-items: center;
            justify-content: center;
            backdrop-filter: blur(4px);
            padding: 20px;
            overflow-y: auto;
        }
        
        .modal-overlay.active {
            display: flex;
        }
        
        .modal-container {
            background: white;
            border-radius: 24px;
            max-width: 950px;
            width: 100%;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.3);
            animation: modalSlideIn 0.3s ease-out;
            position: relative;
        }
        
        @keyframes modalSlideIn {
            from {
                opacity: 0;
                transform: translateY(-30px) scale(0.95);
            }
            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }
        
        .modal-header {
            padding: 20px 24px;
            border-bottom: 1px solid var(--gray-light);
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #f8fafc;
            border-radius: 24px 24px 0 0;
            position: sticky;
            top: 0;
            z-index: 10;
        }
        
        .modal-header h4 {
            margin: 0;
            font-weight: 700;
            color: var(--dark);
            font-size: 20px;
        }
        
        .close-modal {
            background: none;
            border: none;
            font-size: 28px;
            cursor: pointer;
            color: var(--gray);
            transition: color 0.2s;
            padding: 0 8px;
            line-height: 1;
        }
        
        .close-modal:hover {
            color: var(--danger);
        }
        
        .modal-body {
            padding: 24px;
        }
        
        .modal-footer {
            padding: 16px 24px;
            border-top: 1px solid var(--gray-light);
            display: flex;
            justify-content: flex-end;
            gap: 12px;
            background: #f8fafc;
            border-radius: 0 0 24px 24px;
            position: sticky;
            bottom: 0;
            z-index: 10;
        }
        
        /* ========== END MODAL STYLES ========== */
        
        .form-group { margin-bottom: 20px; }
        
        .form-group label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 8px;
            color: var(--dark);
        }
        
        .form-group input, 
        .form-group select, 
        .form-group textarea {
            width: 100%;
            padding: 10px 14px;
            border: 1px solid var(--gray-light);
            border-radius: 12px;
            font-size: 14px;
            transition: all 0.2s;
            font-family: inherit;
            background: white;
        }
        
        .form-group input:focus, 
        .form-group select:focus, 
        .form-group textarea:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(67,97,238,0.1);
        }
        
        .vitals-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 16px;
            background: #f8fafc;
            padding: 16px;
            border-radius: 16px;
            margin-bottom: 20px;
        }
        
        @media (max-width: 768px) { .vitals-grid { grid-template-columns: repeat(2, 1fr); } }
        @media (max-width: 500px) { .vitals-grid { grid-template-columns: 1fr; } }
        
        .vital-card {
            background: white;
            border-radius: 12px;
            padding: 12px;
            border: 1px solid var(--gray-light);
        }
        
        .vital-card label {
            font-size: 11px;
            text-transform: uppercase;
            font-weight: 600;
            color: var(--gray);
            margin-bottom: 6px;
            display: block;
        }
        
        .vital-card input {
            font-size: 16px;
            font-weight: 600;
            padding: 8px 10px;
            width: 100%;
            border: 1px solid var(--gray-light);
            border-radius: 8px;
            transition: all 0.2s;
            background: white;
        }
        
        .vital-card input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(67,97,238,0.1);
        }
        
        .vital-unit {
            font-size: 11px;
            color: var(--gray);
            margin-top: 4px;
        }
        
        .priority-group {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-top: 8px;
        }
        
        .priority-option {
            flex: 1;
            min-width: 100px;
            text-align: center;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .priority-option input { display: none; }
        
        .priority-label {
            display: block;
            padding: 10px 8px;
            border-radius: 12px;
            font-weight: 600;
            font-size: 13px;
            transition: all 0.2s;
            cursor: pointer;
        }
        
        .priority-red .priority-label { background: #fee2e2; color: #dc2626; border: 2px solid transparent; }
        .priority-yellow .priority-label { background: #fef3c7; color: #d97706; border: 2px solid transparent; }
        .priority-green .priority-label { background: #d1fae5; color: #059669; border: 2px solid transparent; }
        .priority-blue .priority-label { background: #dbeafe; color: #2563eb; border: 2px solid transparent; }
        
        .priority-option input:checked + .priority-label { border-color: currentColor; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
        
        .patient-info-card {
            background: linear-gradient(135deg, #f8fafc, #f1f5f9);
            border-radius: 16px;
            padding: 16px 20px;
            margin-bottom: 20px;
        }
        
        .patient-name-large { font-size: 20px; font-weight: 700; color: var(--dark); }
        .patient-detail-item { font-size: 12px; color: var(--gray); margin-top: 4px; }
        
        .warning-range { border-color: var(--warning) !important; background-color: #fef3c7 !important; }
        .critical-range { border-color: var(--danger) !important; background-color: #fee2e2 !important; }
        
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(255,255,255,0.8);
            z-index: 2000;
            display: none;
            align-items: center;
            justify-content: center;
        }
        
        .loading-overlay.active { display: flex; }
        
        .spinner {
            width: 50px;
            height: 50px;
            border: 4px solid var(--gray-light);
            border-top-color: var(--primary);
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }
        
        @keyframes spin { to { transform: rotate(360deg); } }
        
        .toast-notification {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: white;
            border-radius: 12px;
            padding: 14px 22px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.15);
            z-index: 1100;
            display: flex;
            align-items: center;
            gap: 12px;
            border-left: 4px solid var(--success);
            animation: slideIn 0.3s ease-out;
        }
        
        .toast-error { border-left-color: var(--danger); }
        
        @keyframes slideIn {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        
        .empty-state {
            text-align: center;
            padding: 30px;
            color: var(--gray);
        }
        
        .queue-count-badge {
            background: var(--primary);
            color: white;
            padding: 4px 14px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 600;
        }

        /* Fix for sidebar when modal is open - prevent scrolling */
        body.modal-open {
            overflow: hidden;
        }
    </style>
</head>
<body>

<div id="wrapper">
    <?php include('assets/inc/nav.php'); ?>
    <?php include('assets/inc/sidebar.php'); ?>

    <div class="content-page">
        <div class="container-fluid">

            <!-- Welcome Header -->
            <div class="welcome-header">
                <div class="row align-items-center">
                    <div class="col-md-8">
                        <div class="welcome-title">Welcome, Nurse <?php echo htmlspecialchars($user_name, ENT_QUOTES, 'UTF-8'); ?> 🩺</div>
                        <div class="welcome-subtitle">Record patient vitals, assess triage priority, and route to doctors.</div>
                    </div>
                    <div class="col-md-4 text-md-end mt-3 mt-md-0">
                        <div class="time-badge">
                            <i class="far fa-calendar-alt"></i> <?php echo htmlspecialchars($current_date_display); ?>
                            <i class="far fa-clock ms-2"></i> <?php echo htmlspecialchars($current_time_display); ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Stats Cards -->
            <div class="stats-grid">
                <div class="stat-card primary">
                    <div class="stat-header">
                        <div>
                            <div class="stat-value" id="kpiWaitingTriage">0</div>
                            <div class="stat-label">Waiting for Triage</div>
                        </div>
                        <div class="stat-icon primary"><i class="fas fa-hourglass-half"></i></div>
                    </div>
                    <div class="stat-footer">Patients needing vital signs</div>
                </div>
                <div class="stat-card warning">
                    <div class="stat-header">
                        <div>
                            <div class="stat-value" id="kpiTriageToday">0</div>
                            <div class="stat-label">Triage Today</div>
                        </div>
                        <div class="stat-icon warning"><i class="fas fa-clipboard-list"></i></div>
                    </div>
                    <div class="stat-footer">Vitals recorded today</div>
                </div>
                <div class="stat-card danger">
                    <div class="stat-header">
                        <div>
                            <div class="stat-value" id="kpiCritical">0</div>
                            <div class="stat-label">Critical Patients</div>
                        </div>
                        <div class="stat-icon danger"><i class="fas fa-exclamation-triangle"></i></div>
                    </div>
                    <div class="stat-footer">Red priority cases</div>
                </div>
                <div class="stat-card info">
                    <div class="stat-header">
                        <div>
                            <div class="stat-value" id="kpiActiveConsults">0</div>
                            <div class="stat-label">Active Consultations</div>
                        </div>
                        <div class="stat-icon info"><i class="fas fa-user-md"></i></div>
                    </div>
                    <div class="stat-footer">Patients with doctors</div>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="quick-actions">
                <button class="quick-btn quick-btn-primary" onclick="refreshQueue()">
                    <i class="fas fa-sync-alt"></i> Refresh Queue
                </button>
                <button class="quick-btn quick-btn-success" onclick="window.location.href='nursing_notes.php'">
                    <i class="fas fa-notes-medical"></i> Nursing Notes
                </button>
                <button class="quick-btn quick-btn-success" onclick="window.location.href='sample_collection.php'">
                    <i class="fas fa-flask"></i> Sample Collection
                </button>
            </div>

            <!-- Triage Queue Section -->
            <div class="triage-queue-section">
                <div class="section-header">
                    <h3><i class="fas fa-stethoscope me-2"></i> Triage Queue - Patients Awaiting Vitals</h3>
                    <span class="queue-count-badge" id="queueCount">Loading...</span>
                </div>
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Arrived</th>
                                <th>Patient Info</th>
                                <th>MRN / Phone</th>
                                <th>Department</th>
                                <th>Chief Complaint</th>
                                <th>Priority</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody id="triageQueueBody">
                            <tr><td colspan="7" class="text-center text-muted py-4">Loading triage queue...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <?php include('assets/inc/footer.php'); ?>
</div>

<div id="loadingOverlay" class="loading-overlay">
    <div class="spinner"></div>
</div>

<!-- ========== FIXED MODAL STRUCTURE ========== -->
<div id="vitalsModal" class="modal-overlay">
    <div class="modal-container">
        <div class="modal-header">
            <h4><i class="fas fa-stethoscope me-2"></i> Patient Triage & Vitals Assessment</h4>
            <button class="close-modal" onclick="closeVitalsModal()">&times;</button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="vitalsVisitId" value="">
            <input type="hidden" id="vitalsPatientId" value="">
            
            <!-- Patient Information Card -->
            <div class="patient-info-card" id="patientInfoCard">
                <div class="row align-items-center">
                    <div class="col-md-8">
                        <div class="patient-name-large" id="patientName">Loading...</div>
                        <div class="patient-detail-item">
                            <i class="fas fa-id-card me-1"></i> MRN: <span id="patientMrn">-</span>
                            <span class="mx-2">|</span>
                            <i class="fas fa-calendar-alt me-1"></i> Age: <span id="patientAge">-</span>
                            <span class="mx-2">|</span>
                            <i class="fas fa-venus-mars me-1"></i> <span id="patientGender">-</span>
                        </div>
                        <div class="patient-detail-item">
                            <i class="fas fa-phone me-1"></i> <span id="patientPhone">-</span>
                            <span class="mx-2">|</span>
                            <i class="fas fa-envelope me-1"></i> <span id="patientEmail">-</span>
                        </div>
                    </div>
                    <div class="col-md-4 text-md-end mt-2 mt-md-0">
                        <div class="badge badge-primary" id="departmentBadge">Department: -</div>
                    </div>
                </div>
            </div>

            <!-- Chief Complaint & History -->
            <div class="row">
                <div class="col-md-6">
                    <div class="form-group">
                        <label><i class="fas fa-comment-dots me-1"></i> Chief Complaint *</label>
                        <textarea id="chiefComplaint" class="form-control" rows="2" placeholder="Main reason for visit..."></textarea>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-group">
                        <label><i class="fas fa-notes-medical me-1"></i> History of Present Illness</label>
                        <textarea id="historyOfIllness" class="form-control" rows="2" placeholder="Additional history..."></textarea>
                    </div>
                </div>
            </div>

            <!-- Vital Signs Grid -->
            <div class="vitals-grid">
                <div class="vital-card">
                    <label>Heart Rate</label>
                    <input type="number" id="heartRate" class="form-control" placeholder="bpm">
                    <div class="vital-unit">beats per minute (60-100 normal)</div>
                </div>
                <div class="vital-card">
                    <label>Blood Pressure (Systolic)</label>
                    <input type="number" id="bpSystolic" class="form-control" placeholder="mmHg">
                    <div class="vital-unit">Systolic (90-120 normal)</div>
                </div>
                <div class="vital-card">
                    <label>Blood Pressure (Diastolic)</label>
                    <input type="number" id="bpDiastolic" class="form-control" placeholder="mmHg">
                    <div class="vital-unit">Diastolic (60-80 normal)</div>
                </div>
                <div class="vital-card">
                    <label>Temperature (°C)</label>
                    <input type="number" step="0.1" id="temperature" class="form-control" placeholder="°C">
                    <div class="vital-unit">(36.1-37.5 normal)</div>
                </div>
                <div class="vital-card">
                    <label>Respiratory Rate</label>
                    <input type="number" id="respiratoryRate" class="form-control" placeholder="breaths/min">
                    <div class="vital-unit">breaths per minute (12-20 normal)</div>
                </div>
                <div class="vital-card">
                    <label>Oxygen Saturation (SpO₂)</label>
                    <input type="number" id="oxygenSaturation" class="form-control" placeholder="%">
                    <div class="vital-unit">% (95-100 normal)</div>
                </div>
                <div class="vital-card">
                    <label>Pain Score (0-10)</label>
                    <input type="number" id="painScore" class="form-control" placeholder="0-10">
                    <div class="vital-unit">0 = no pain, 10 = worst pain</div>
                </div>
                <div class="vital-card">
                    <label>Weight (kg)</label>
                    <input type="number" step="0.1" id="weightKg" class="form-control" placeholder="kg">
                    <div class="vital-unit">kilograms</div>
                </div>
                <div class="vital-card">
                    <label>Height (cm)</label>
                    <input type="number" step="0.1" id="heightCm" class="form-control" placeholder="cm">
                    <div class="vital-unit">centimeters</div>
                </div>
            </div>

            <!-- Consciousness Level -->
            <div class="row">
                <div class="col-md-6">
                    <div class="form-group">
                        <label><i class="fas fa-brain me-1"></i> Consciousness Level</label>
                        <select id="consciousness" class="form-control">
                            <option value="">Select</option>
                            <option value="alert">Alert</option>
                            <option value="verbal">Responds to Verbal</option>
                            <option value="pain">Responds to Pain</option>
                            <option value="unresponsive">Unresponsive</option>
                        </select>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-group">
                        <label><i class="fas fa-chart-line me-1"></i> ESI Level (1-5)</label>
                        <select id="esiLevel" class="form-control">
                            <option value="">Select ESI Level</option>
                            <option value="1">Level 1 - Unstable / Life Threat</option>
                            <option value="2">Level 2 - High Risk / Severe Pain</option>
                            <option value="3">Level 3 - Stable / Multiple Resources</option>
                            <option value="4">Level 4 - Low Risk / One Resource</option>
                            <option value="5">Level 5 - Non-urgent / No Resources</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Triage Priority -->
            <div class="form-group">
                <label><i class="fas fa-flag-checkered me-1"></i> Triage Priority *</label>
                <div class="priority-group" id="priorityGroup">
                    <label class="priority-option priority-red">
                        <input type="radio" name="priority" value="red">
                        <span class="priority-label">🔴 Red (Resuscitation)</span>
                    </label>
                    <label class="priority-option priority-yellow">
                        <input type="radio" name="priority" value="yellow" checked>
                        <span class="priority-label">🟡 Yellow (Urgent)</span>
                    </label>
                    <label class="priority-option priority-green">
                        <input type="radio" name="priority" value="green">
                        <span class="priority-label">🟢 Green (Non-urgent)</span>
                    </label>
                    <label class="priority-option priority-blue">
                        <input type="radio" name="priority" value="blue">
                        <span class="priority-label">🔵 Blue (Expectant)</span>
                    </label>
                </div>
            </div>

            <!-- Allergies & Medications -->
            <div class="row">
                <div class="col-md-6">
                    <div class="form-group">
                        <label><i class="fas fa-allergies me-1"></i> Allergies</label>
                        <textarea id="allergies" class="form-control" rows="2" placeholder="Any known allergies?"></textarea>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-group">
                        <label><i class="fas fa-pills me-1"></i> Current Medications</label>
                        <textarea id="currentMedications" class="form-control" rows="2" placeholder="Current medications..."></textarea>
                    </div>
                </div>
            </div>

            <!-- Triage Notes -->
            <div class="form-group">
                <label><i class="fas fa-file-alt me-1"></i> Triage Notes</label>
                <textarea id="triageNotes" class="form-control" rows="2" placeholder="Additional observations..."></textarea>
            </div>

            <div class="alert alert-info mt-3" style="background:#e0f0fa; border-radius:12px; border:1px solid #b6d4fe;">
                <i class="fas fa-info-circle me-2"></i>
                <span id="routingInfo">After saving, patient will be routed to doctor consultation.</span>
            </div>
        </div>
        <div class="modal-footer">
            <button class="quick-btn quick-btn-outline" onclick="closeVitalsModal()">Cancel</button>
            <button class="quick-btn quick-btn-primary" onclick="saveVitals()">
                <i class="fas fa-save"></i> Save & Route to Doctor
            </button>
        </div>
    </div>
</div>
<!-- ========== END MODAL ========== -->

<script src="assets/js/vendor.min.js"></script>
<script src="assets/js/app.min.js"></script>
<script>
// =============================================================================
// GLOBALS & UTILITIES
// =============================================================================
const branchId = <?php echo (int)$branch_id; ?>;
const isGlobalAdmin = <?php echo $is_global_admin ? 'true' : 'false'; ?>;
const csrfToken = <?php echo json_encode($csrf_token); ?>;
const ajaxUrl = window.location.pathname + '?ajax=nurse_action';
const thresholds = <?php echo json_encode($thresholds_js); ?>;

let autoRefreshInterval = null;
let currentSelectedVisitId = null;

function showToast(message, type = 'success') {
    const toast = document.createElement('div');
    toast.className = `toast-notification ${type === 'error' ? 'toast-error' : ''}`;
    toast.innerHTML = `<i class="fas fa-${type === 'error' ? 'exclamation-circle' : 'check-circle'}"></i> <span>${escapeHtml(message)}</span>`;
    document.body.appendChild(toast);
    setTimeout(() => toast.remove(), 4000);
}

function showLoading(show) {
    const overlay = document.getElementById('loadingOverlay');
    if (overlay) overlay.classList.toggle('active', show);
}

function escapeHtml(str) {
    if (!str) return '';
    return String(str).replace(/[&<>]/g, m => m === '&' ? '&amp;' : (m === '<' ? '&lt;' : '&gt;'));
}

// =============================================================================
// KPI & QUEUE FETCHING
// =============================================================================
function fetchKPIs() {
    $.ajax({
        url: ajaxUrl,
        method: 'POST',
        dataType: 'json',
        data: { 
            action: 'kpis', 
            branch_id: branchId, 
            csrf_token: csrfToken 
        },
        success: res => {
            if (res.success && res.kpis) {
                document.getElementById('kpiWaitingTriage').textContent = (res.kpis.waiting_triage ?? 0).toLocaleString();
                document.getElementById('kpiTriageToday').textContent = (res.kpis.triage_today ?? 0).toLocaleString();
                document.getElementById('kpiCritical').textContent = (res.kpis.critical_patients ?? 0).toLocaleString();
                document.getElementById('kpiActiveConsults').textContent = (res.kpis.active_consultations ?? 0).toLocaleString();
            }
        },
        error: (xhr, status, error) => {
            console.error('KPI fetch error:', error);
        }
    });
}

function fetchTriageQueue() {
    $.ajax({
        url: ajaxUrl,
        method: 'POST',
        dataType: 'json',
        data: { 
            action: 'get_triage_queue', 
            branch_id: branchId, 
            csrf_token: csrfToken 
        },
        success: res => {
            const tbody = document.getElementById('triageQueueBody');
            const queueCountSpan = document.getElementById('queueCount');
            
            if (!tbody) return;
            
            if (!res.success || !res.queue || res.queue.length === 0) {
                tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted py-4">No patients waiting for triage</td></tr>';
                if (queueCountSpan) queueCountSpan.textContent = '0 waiting';
                return;
            }
            
            if (queueCountSpan) queueCountSpan.textContent = res.queue.length + ' waiting';
            
            tbody.innerHTML = res.queue.map(row => {
                // Priority indicator - using database enum values: red, yellow, green, blue
                let priorityHtml = '';
                if (row.triage_priority === 'red') {
                    priorityHtml = '<span class="emergency-alert"><i class="fas fa-exclamation-triangle me-1"></i>EMERGENCY</span>';
                } else if (row.triage_priority === 'yellow') {
                    priorityHtml = '<span class="badge badge-warning">Urgent</span>';
                } else if (row.triage_priority === 'green') {
                    priorityHtml = '<span class="badge badge-success">Non-urgent</span>';
                } else if (row.triage_priority === 'blue') {
                    priorityHtml = '<span class="badge badge-primary">Expectant</span>';
                } else {
                    priorityHtml = '<span class="badge badge-secondary">Pending</span>';
                }
                
                const arrivedTime = row.arrived_at ? new Date(row.arrived_at).toLocaleTimeString([], {hour:'2-digit', minute:'2-digit'}) : 'N/A';
                const fullName = [row.first_name, row.middle_name, row.last_name].filter(n => n).join(' ');
                const ageDisplay = row.age_display || 'N/A';
                const complaint = row.chief_complaint ? (row.chief_complaint.length > 50 ? row.chief_complaint.substring(0, 50) + '...' : row.chief_complaint) : 'Not specified';
                
                return `
                    <tr>
                        <td class="small text-muted">${arrivedTime}</td>
                        <td>
                            <strong>${escapeHtml(fullName)}</strong><br>
                            <span class="small text-muted">${ageDisplay} | ${escapeHtml(row.gender || 'N/A')}</span>
                        </td>
                        <td>
                            <span class="small">MRN: ${escapeHtml(row.mr_number || 'N/A')}</span><br>
                            <span class="small text-muted">${escapeHtml(row.phone || 'N/A')}</span>
                        </td>
                        <td>${escapeHtml(row.department_name || 'General')}</td>
                        <td class="small">${escapeHtml(complaint)}</td>
                        <td>${priorityHtml}</td>
                        <td>
                            <button class="quick-btn quick-btn-primary" style="padding:8px 16px;font-size:12px" onclick="openVitalsModal(${row.visit_id}, ${row.patient_id})">
                                <i class="fas fa-stethoscope"></i> Record Vitals
                            </button>
                        </td>
                    </tr>
                `;
            }).join('');
        },
        error: (xhr, status, error) => {
            console.error('Queue fetch error:', error);
            document.getElementById('triageQueueBody').innerHTML = '<tr><td colspan="7" class="text-center text-danger py-4">Error loading queue</td></tr>';
        }
    });
}

function refreshQueue() {
    fetchKPIs();
    fetchTriageQueue();
    showToast('Queue refreshed', 'success');
}

// =============================================================================
// VITALS MODAL FUNCTIONS
// =============================================================================
function openVitalsModalFromButton() {
    Swal.fire({
        title: 'Select Patient',
        text: 'Please select a patient from the triage queue above, or use the refresh button.',
        icon: 'info',
        confirmButtonText: 'OK'
    });
}

function validateVital(vitalName, value, inputElement) {
    const ranges = thresholds[vitalName];
    if (!ranges || !value) return;
    
    const numValue = parseFloat(value);
    if (isNaN(numValue)) return;
    
    if (ranges.critical_low !== null && numValue <= ranges.critical_low) {
        inputElement.classList.add('critical-range');
        inputElement.classList.remove('warning-range');
        return 'critical';
    } else if (ranges.critical_high !== null && numValue >= ranges.critical_high) {
        inputElement.classList.add('critical-range');
        inputElement.classList.remove('warning-range');
        return 'critical';
    } else if ((ranges.min_normal !== null && numValue < ranges.min_normal) ||
               (ranges.max_normal !== null && numValue > ranges.max_normal)) {
        inputElement.classList.add('warning-range');
        inputElement.classList.remove('critical-range');
        return 'warning';
    } else {
        inputElement.classList.remove('warning-range', 'critical-range');
        return 'normal';
    }
}

function attachVitalValidation() {
    const vitalFields = [
        { id: 'heartRate', name: 'pulse_rate' },
        { id: 'temperature', name: 'temperature' },
        { id: 'respiratoryRate', name: 'respiratory_rate' },
        { id: 'oxygenSaturation', name: 'oxygen_saturation' },
        { id: 'bpSystolic', name: 'systolic_bp' },
        { id: 'bpDiastolic', name: 'diastolic_bp' }
    ];
    
    vitalFields.forEach(field => {
        const element = document.getElementById(field.id);
        if (element) {
            element.addEventListener('input', function() {
                validateVital(field.name, this.value, this);
            });
        }
    });
}

function openVitalsModal(visitId, patientId) {
    currentSelectedVisitId = visitId;
    document.getElementById('vitalsVisitId').value = visitId;
    document.getElementById('vitalsPatientId').value = patientId;
    
    // Clear form
    document.getElementById('chiefComplaint').value = '';
    document.getElementById('historyOfIllness').value = '';
    document.getElementById('heartRate').value = '';
    document.getElementById('bpSystolic').value = '';
    document.getElementById('bpDiastolic').value = '';
    document.getElementById('temperature').value = '';
    document.getElementById('respiratoryRate').value = '';
    document.getElementById('oxygenSaturation').value = '';
    document.getElementById('painScore').value = '';
    document.getElementById('weightKg').value = '';
    document.getElementById('heightCm').value = '';
    document.getElementById('consciousness').value = '';
    document.getElementById('esiLevel').value = '';
    document.getElementById('allergies').value = '';
    document.getElementById('currentMedications').value = '';
    document.getElementById('triageNotes').value = '';
    
    // Reset priority to default (yellow)
    document.querySelectorAll('input[name="priority"]').forEach(radio => radio.checked = false);
    document.querySelector('input[name="priority"][value="yellow"]').checked = true;
    
    // Clear validation classes
    document.querySelectorAll('.warning-range, .critical-range').forEach(el => {
        el.classList.remove('warning-range', 'critical-range');
    });
    
    showLoading(true);
    
    // Fetch patient details
    $.ajax({
        url: ajaxUrl,
        method: 'POST',
        dataType: 'json',
        data: {
            action: 'get_patient_details',
            patient_id: patientId,
            branch_id: branchId,
            csrf_token: csrfToken
        },
        success: res => {
            if (res.success && res.patient) {
                const p = res.patient;
                document.getElementById('patientName').innerHTML = escapeHtml([p.first_name, p.middle_name, p.last_name].filter(n => n).join(' '));
                document.getElementById('patientMrn').textContent = p.mr_number || '-';
                document.getElementById('patientAge').textContent = p.age ? p.age + ' years' : '-';
                document.getElementById('patientGender').textContent = p.gender || '-';
                document.getElementById('patientPhone').textContent = p.phone || '-';
                document.getElementById('patientEmail').textContent = p.email || '-';
            }
        },
        error: () => {
            console.error('Failed to fetch patient details');
        },
        complete: () => {
            // Fetch existing triage record if any
            $.ajax({
                url: ajaxUrl,
                method: 'POST',
                dataType: 'json',
                data: {
                    action: 'get_triage_record',
                    visit_id: visitId,
                    branch_id: branchId,
                    csrf_token: csrfToken
                },
                success: res => {
                    if (res.success && res.record && Object.keys(res.record).length > 0) {
                        const r = res.record;
                        if (r.chief_complaint) document.getElementById('chiefComplaint').value = r.chief_complaint;
                        if (r.history_of_present_illness) document.getElementById('historyOfIllness').value = r.history_of_present_illness;
                        if (r.heart_rate) document.getElementById('heartRate').value = r.heart_rate;
                        if (r.blood_pressure_systolic) document.getElementById('bpSystolic').value = r.blood_pressure_systolic;
                        if (r.blood_pressure_diastolic) document.getElementById('bpDiastolic').value = r.blood_pressure_diastolic;
                        if (r.temperature_celsius) document.getElementById('temperature').value = r.temperature_celsius;
                        if (r.respiratory_rate) document.getElementById('respiratoryRate').value = r.respiratory_rate;
                        if (r.oxygen_saturation) document.getElementById('oxygenSaturation').value = r.oxygen_saturation;
                        if (r.pain_score) document.getElementById('painScore').value = r.pain_score;
                        if (r.weight_kg) document.getElementById('weightKg').value = r.weight_kg;
                        if (r.height_cm) document.getElementById('heightCm').value = r.height_cm;
                        if (r.consciousness) document.getElementById('consciousness').value = r.consciousness;
                        if (r.esi_level) document.getElementById('esiLevel').value = r.esi_level;
                        if (r.allergies) document.getElementById('allergies').value = r.allergies;
                        if (r.current_medications) document.getElementById('currentMedications').value = r.current_medications;
                        if (r.triage_notes) document.getElementById('triageNotes').value = r.triage_notes;
                        if (r.priority) {
                            document.querySelectorAll('input[name="priority"]').forEach(radio => radio.checked = false);
                            const priorityRadio = document.querySelector(`input[name="priority"][value="${r.priority}"]`);
                            if (priorityRadio) priorityRadio.checked = true;
                        }
                    }
                },
                complete: () => {
                    showLoading(false);
                    // Show modal
                    const modal = document.getElementById('vitalsModal');
                    modal.classList.add('active');
                    document.body.classList.add('modal-open');
                    attachVitalValidation();
                }
            });
        }
    });
}

function closeVitalsModal() {
    const modal = document.getElementById('vitalsModal');
    modal.classList.remove('active');
    document.body.classList.remove('modal-open');
    currentSelectedVisitId = null;
}

function saveVitals() {
    const visitId = document.getElementById('vitalsVisitId').value;
    const patientId = document.getElementById('vitalsPatientId').value;
    const priority = document.querySelector('input[name="priority"]:checked')?.value || 'yellow';
    
    if (!visitId || !patientId) {
        showToast('Invalid patient data', 'error');
        return;
    }
    
    if (!priority) {
        showToast('Please select a triage priority', 'error');
        return;
    }
    
    // Validate required fields
    const chiefComplaint = document.getElementById('chiefComplaint').value.trim();
    if (!chiefComplaint) {
        showToast('Please enter the chief complaint', 'error');
        document.getElementById('chiefComplaint').focus();
        return;
    }
    
    showLoading(true);
    
    const formData = {
        action: 'save_vitals',
        visit_id: visitId,
        patient_id: patientId,
        priority: priority,
        esi_level: document.getElementById('esiLevel').value,
        heart_rate: document.getElementById('heartRate').value,
        blood_pressure_systolic: document.getElementById('bpSystolic').value,
        blood_pressure_diastolic: document.getElementById('bpDiastolic').value,
        temperature_celsius: document.getElementById('temperature').value,
        respiratory_rate: document.getElementById('respiratoryRate').value,
        oxygen_saturation: document.getElementById('oxygenSaturation').value,
        pain_score: document.getElementById('painScore').value,
        consciousness: document.getElementById('consciousness').value,
        weight_kg: document.getElementById('weightKg').value,
        height_cm: document.getElementById('heightCm').value,
        allergies: document.getElementById('allergies').value,
        current_medications: document.getElementById('currentMedications').value,
        chief_complaint: chiefComplaint,
        history_of_present_illness: document.getElementById('historyOfIllness').value,
        triage_notes: document.getElementById('triageNotes').value,
        send_to_doctor: '1',
        branch_id: branchId,
        csrf_token: csrfToken
    };
    
    $.ajax({
        url: ajaxUrl,
        method: 'POST',
        dataType: 'json',
        data: formData,
        success: res => {
            showLoading(false);
            if (res.success) {
                showToast(res.message);
                closeVitalsModal();
                refreshQueue();
            } else {
                showToast(res.message || 'Failed to save vitals', 'error');
            }
        },
        error: (xhr, status, error) => {
            showLoading(false);
            console.error('Save error:', error);
            showToast('Network error - please try again', 'error');
        }
    });
}

// =============================================================================
// AUTO REFRESH & INITIALIZATION
// =============================================================================
function startAutoRefresh() {
    if (autoRefreshInterval) clearInterval(autoRefreshInterval);
    
    autoRefreshInterval = setInterval(() => {
        if (!document.hidden) {
            fetchKPIs();
            fetchTriageQueue();
        }
    }, 30000);
}

$(function() {
    fetchKPIs();
    fetchTriageQueue();
    startAutoRefresh();
    
    // Close modal on Escape key
    $(document).on('keydown', function(e) {
        if (e.key === 'Escape') {
            closeVitalsModal();
        }
    });
    
    // Close modal on backdrop click
    $(document).on('click', '.modal-overlay', function(e) {
        if (e.target === this) {
            closeVitalsModal();
        }
    });
});

// Cleanup interval on page unload
$(window).on('beforeunload', function() {
    if (autoRefreshInterval) clearInterval(autoRefreshInterval);
});
</script>
</body>
</html>