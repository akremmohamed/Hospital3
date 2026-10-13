<?php
/**
 * ======================================================================
 * MEDICATION ADMINISTRATION RECORD (MAR) - PHASE 4 (SCHEMA-FIXED)
 * Active inpatient medication administration and real-time logging
 * Strict multi-tenancy | RBAC | CSRF | PDO transactions | Responsive
 * 
 * DATABASE TABLES USED:
 * - admissions, patients, prescriptions, prescription_items, medication_logs
 * - medicines_master, users
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

if ($user_id <= 0) {
    header('Location: ../../index.php');
    exit;
}

$has_mar_access = user_has_permission($pdo, 'medication_administration_access', $user_id);
if (!$is_global_admin && !$has_mar_access) {
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

function calculateAge($dob) {
    if (empty($dob)) return 'N/A';
    try {
        $birthDate = new DateTime($dob);
        $today = new DateTime();
        $age = $birthDate->diff($today);
        return $age->y;
    } catch (Exception $e) {
        return 'N/A';
    }
}

function getMedicineDetails($medicine_id, $pdo) {
    if (!$medicine_id) return null;
    try {
        $stmt = $pdo->prepare("SELECT generic_name, brand_name, dosage_form, strength, is_controlled_substance 
                               FROM medicines_master WHERE id = ? AND deleted_at IS NULL");
        $stmt->execute([$medicine_id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return null;
    }
}

// ----------------------------------------------------------------------
// AJAX HANDLERS
// ----------------------------------------------------------------------
if (isset($_GET['action'])) {
    // 1) Fetch active medication orders + administration history for a given admission
    if ($_GET['action'] === 'fetch_patient_mar') {
        $admission_id = (int)($_GET['admission_id'] ?? 0);
        if ($admission_id <= 0) jsonResponse(false, 'Invalid admission ID');

        try {
            // Validate admission belongs to branch and is active
            $checkAdm = $pdo->prepare("SELECT id, patient_id, visit_id FROM admissions 
                                       WHERE id = :admission_id AND branch_id = :branch_id 
                                       AND status = 'admitted' AND deleted_at IS NULL");
            $checkAdm->execute([':admission_id' => $admission_id, ':branch_id' => $branch_id]);
            $admission = $checkAdm->fetch(PDO::FETCH_ASSOC);
            if (!$admission) {
                jsonResponse(false, 'Admission record not found or not active');
            }

            // Fetch prescriptions for this admission via visit_id
            $sql = "SELECT 
                        p.id AS prescription_id,
                        p.prescription_number,
                        p.prescription_date,
                        p.status AS prescription_status,
                        p.clinical_notes
                    FROM prescriptions p
                    WHERE p.visit_id = :visit_id
                      AND p.deleted_at IS NULL
                      AND p.status IN ('active', 'partial')
                    ORDER BY p.prescription_date DESC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':visit_id' => $admission['visit_id']]);
            $prescriptions = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $all_items = [];
            
            foreach ($prescriptions as $prescription) {
                // Fetch prescription items for this prescription
                $itemSql = "SELECT 
                                pi.id AS prescription_item_id,
                                pi.medicine_id,
                                pi.medicine_name,
                                pi.dosage,
                                pi.frequency,
                                pi.duration_days,
                                pi.duration_text,
                                pi.quantity,
                                pi.quantity_dispensed,
                                pi.unit_of_measure,
                                pi.instructions,
                                pi.meal_relation,
                                pi.is_controlled_substance,
                                pi.is_dispensed,
                                pi.dispensed_quantity,
                                pi.dispensed_at,
                                pi.dispensed_by_user_id
                            FROM prescription_items pi
                            WHERE pi.prescription_id = :prescription_id
                              AND pi.deleted_at IS NULL
                              AND pi.is_dispensed = 1
                            ORDER BY pi.id ASC";
                $itemStmt = $pdo->prepare($itemSql);
                $itemStmt->execute([':prescription_id' => $prescription['prescription_id']]);
                $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);
                
                foreach ($items as &$item) {
                    // Get medicine details if medicine_id exists
                    if ($item['medicine_id']) {
                        $medicine = getMedicineDetails($item['medicine_id'], $pdo);
                        if ($medicine) {
                            $item['generic_name'] = $medicine['generic_name'] ?? '';
                            $item['brand_name'] = $medicine['brand_name'] ?? '';
                            $item['dosage_form'] = $medicine['dosage_form'] ?? '';
                            $item['strength'] = $medicine['strength'] ?? '';
                            if (!empty($medicine['is_controlled_substance'])) {
                                $item['is_controlled_substance'] = 1;
                            }
                        }
                    }
                    
                    // Fetch administration logs for this item
                    $logSql = "SELECT 
                                    ml.id AS log_id,
                                    ml.status,
                                    ml.administered_at,
                                    ml.notes,
                                    u.first_name, 
                                    u.last_name
                                FROM medication_logs ml
                                LEFT JOIN users u ON ml.administered_by_user_id = u.id
                                WHERE ml.prescription_item_id = :item_id
                                  AND ml.admission_id = :admission_id
                                  AND ml.deleted_at IS NULL
                                ORDER BY ml.administered_at DESC
                                LIMIT 20";
                    $logStmt = $pdo->prepare($logSql);
                    $logStmt->execute([':item_id' => $item['prescription_item_id'], ':admission_id' => $admission_id]);
                    $item['logs'] = $logStmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    // Calculate remaining quantity
                    $dispensed = (float)($item['quantity_dispensed'] ?? 0);
                    $total = (float)($item['quantity'] ?? 1);
                    $item['remaining_quantity'] = $total - $dispensed;
                    
                    // Add prescription reference
                    $item['prescription_number'] = $prescription['prescription_number'] ?? '';
                    $item['prescription_date'] = $prescription['prescription_date'] ?? '';
                }
                
                $all_items = array_merge($all_items, $items);
            }

            // Get patient demographics
            $patientSql = "SELECT 
                                p.id AS patient_id,
                                p.mr_number,
                                CONCAT(p.first_name, ' ', COALESCE(p.middle_name,''), ' ', p.last_name) AS patient_name,
                                p.gender,
                                p.date_of_birth,
                                p.phone,
                                p.blood_group
                            FROM admissions a
                            INNER JOIN patients p ON a.patient_id = p.id
                            WHERE a.id = :admission_id";
            $patientStmt = $pdo->prepare($patientSql);
            $patientStmt->execute([':admission_id' => $admission_id]);
            $patient = $patientStmt->fetch(PDO::FETCH_ASSOC);
            if (!$patient) jsonResponse(false, 'Patient data not found');

            $patient['age'] = calculateAge($patient['date_of_birth'] ?? '');

            jsonResponse(true, '', ['prescriptions' => $all_items, 'patient' => $patient]);
        } catch (PDOException $e) {
            error_log("MAR fetch error: " . $e->getMessage());
            jsonResponse(false, 'Database error: ' . $e->getMessage());
        }
        exit;
    }

    // 2) Record medication administration (POST only)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'record_administration') {
        $incoming_token = $_POST['csrf_token'] ?? '';
        if (!hash_equals($csrf_token, $incoming_token)) {
            jsonResponse(false, 'Security token mismatch');
        }

        $admission_id = (int)($_POST['admission_id'] ?? 0);
        $prescription_item_id = (int)($_POST['prescription_item_id'] ?? 0);
        $status = $_POST['status'] ?? 'given';
        $notes = trim($_POST['notes'] ?? '');

        if ($admission_id <= 0 || $prescription_item_id <= 0) {
            jsonResponse(false, 'Missing admission or medication item');
        }
        
        // Validate status - must be one of: given, refused, held, missed
        $valid_statuses = ['given', 'refused', 'held', 'missed'];
        if (!in_array($status, $valid_statuses)) {
            $status = 'given';
        }

        try {
            $pdo->beginTransaction();

            // Verify that the admission exists and is active
            $checkAdm = $pdo->prepare("SELECT id, patient_id FROM admissions 
                                       WHERE id = :admission_id AND branch_id = :branch_id 
                                       AND status = 'admitted' AND deleted_at IS NULL");
            $checkAdm->execute([':admission_id' => $admission_id, ':branch_id' => $branch_id]);
            $admission = $checkAdm->fetch(PDO::FETCH_ASSOC);
            if (!$admission) {
                throw new PDOException('Admission not found or not active');
            }

            // Verify prescription item exists and belongs to this admission
            $checkItem = $pdo->prepare("
                SELECT pi.id, pi.medicine_name, pi.quantity_dispensed, pi.quantity
                FROM prescription_items pi
                INNER JOIN prescriptions p ON pi.prescription_id = p.id
                INNER JOIN patient_visits pv ON p.visit_id = pv.id
                INNER JOIN admissions a ON a.visit_id = pv.id
                WHERE pi.id = :item_id 
                  AND a.id = :admission_id
                  AND pi.deleted_at IS NULL
            ");
            $checkItem->execute([':item_id' => $prescription_item_id, ':admission_id' => $admission_id]);
            $item = $checkItem->fetch(PDO::FETCH_ASSOC);
            if (!$item) {
                throw new PDOException('Medication item not found for this admission');
            }

            // Check if there's remaining quantity to administer
            $dispensed = (float)($item['quantity_dispensed'] ?? 0);
            $total = (float)($item['quantity'] ?? 1);
            $remaining = $total - $dispensed;
            if ($remaining <= 0 && $status === 'given') {
                throw new PDOException('No remaining quantity to administer. All doses have been dispensed.');
            }

            // Insert log entry into medication_logs
            $insertSql = "INSERT INTO medication_logs 
                            (branch_id, admission_id, prescription_item_id, administered_by_user_id, 
                             status, administered_at, notes, created_at, updated_at)
                          VALUES 
                            (:branch_id, :admission_id, :prescription_item_id, :user_id, 
                             :status, NOW(), :notes, NOW(), NOW())";
            $insertStmt = $pdo->prepare($insertSql);
            $insertStmt->execute([
                ':branch_id' => $branch_id,
                ':admission_id' => $admission_id,
                ':prescription_item_id' => $prescription_item_id,
                ':user_id' => $user_id,
                ':status' => $status,
                ':notes' => $notes
            ]);

            // Update prescription_item quantity_dispensed if status is 'given'
            if ($status === 'given') {
                $updateItem = $pdo->prepare("
                    UPDATE prescription_items 
                    SET quantity_dispensed = quantity_dispensed + 1,
                        updated_at = NOW()
                    WHERE id = :item_id
                ");
                $updateItem->execute([':item_id' => $prescription_item_id]);
            }

            $pdo->commit();
            jsonResponse(true, 'Medication administration recorded successfully');
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log("Medication administration error: " . $e->getMessage());
            jsonResponse(false, 'Database error: ' . $e->getMessage());
        }
        exit;
    }

    // 3) Get medication administration summary for dashboard
    if ($_GET['action'] === 'get_mar_summary') {
        try {
            $sql = "SELECT 
                        COUNT(DISTINCT ml.id) AS total_administrations,
                        SUM(CASE WHEN ml.status = 'given' THEN 1 ELSE 0 END) AS given_count,
                        SUM(CASE WHEN ml.status = 'refused' THEN 1 ELSE 0 END) AS refused_count,
                        SUM(CASE WHEN ml.status = 'held' THEN 1 ELSE 0 END) AS held_count,
                        COUNT(DISTINCT ml.prescription_item_id) AS unique_medications
                    FROM medication_logs ml
                    WHERE ml.branch_id = :branch_id
                      AND DATE(ml.administered_at) = CURDATE()
                      AND ml.deleted_at IS NULL";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':branch_id' => $branch_id]);
            $summary = $stmt->fetch(PDO::FETCH_ASSOC);
            
            jsonResponse(true, '', ['summary' => $summary]);
        } catch (PDOException $e) {
            jsonResponse(false, 'Database error: ' . $e->getMessage());
        }
        exit;
    }
}

// ----------------------------------------------------------------------
// INITIAL PAGE LOAD: Fetch active inpatients for sidebar
// ----------------------------------------------------------------------
$active_inpatients = [];

try {
    $sql = "SELECT 
                a.id AS admission_id,
                w.name AS ward_name,
                b.bed_number,
                p.id AS patient_id,
                p.mr_number,
                CONCAT(p.first_name, ' ', COALESCE(p.middle_name,''), ' ', p.last_name) AS patient_name,
                p.gender,
                p.date_of_birth,
                a.condition_status
            FROM admissions a
            INNER JOIN patients p ON a.patient_id = p.id AND p.deleted_at IS NULL AND p.is_active = 1
            LEFT JOIN beds b ON a.bed_id = b.id AND b.deleted_at IS NULL
            LEFT JOIN wards w ON b.ward_id = w.id AND w.deleted_at IS NULL
            WHERE a.branch_id = :branch_id
              AND a.status = 'admitted'
              AND a.discharged_at IS NULL
              AND a.deleted_at IS NULL
            ORDER BY 
                CASE WHEN a.condition_status IN ('critical', 'serious') THEN 1 ELSE 2 END,
                a.admitted_at ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':branch_id' => $branch_id]);
    $active_inpatients = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Ensure it's always an array
    if (!is_array($active_inpatients)) {
        $active_inpatients = [];
    }
    
    // Calculate age for each patient
    foreach ($active_inpatients as &$inp) {
        $inp['age'] = calculateAge($inp['date_of_birth'] ?? '');
    }
} catch (PDOException $e) {
    error_log('Active inpatient fetch error: ' . $e->getMessage());
    $active_inpatients = [];
}

// Always ensure $active_inpatients is an array
if (!is_array($active_inpatients)) {
    $active_inpatients = [];
}

$current_date = date('l, F j, Y');
$current_time = date('g:i A');
$active_count = count($active_inpatients);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include('assets/inc/head.php'); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Medication Administration Record | HMS ERP</title>
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
            --controlled: #dc3545;
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
        .card-header-custom { padding: 16px 20px; background: #f8fafc; border-bottom: 1px solid var(--gray-light); font-weight: 700; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px; }
        .patient-list-sidebar { max-height: calc(100vh - 220px); overflow-y: auto; }
        .patient-list-sidebar::-webkit-scrollbar { width: 4px; }
        .patient-list-sidebar::-webkit-scrollbar-track { background: #f1f1f1; border-radius: 4px; }
        .patient-list-sidebar::-webkit-scrollbar-thumb { background: var(--primary); border-radius: 4px; }
        .patient-card { padding: 14px 16px; border-bottom: 1px solid #f0f0f0; cursor: pointer; transition: all 0.2s; }
        .patient-card:hover { background: #f8fafc; }
        .patient-card.active { background: #e0e7ff; border-left: 3px solid var(--primary); }
        .patient-card.critical { background: #fef2f2; border-left: 3px solid var(--danger); }
        .patient-card.critical:hover { background: #fee2e2; }
        .medication-item { 
            background: #f8fafc; 
            border-left: 4px solid var(--info); 
            margin-bottom: 16px; 
            border-radius: 12px; 
            padding: 16px; 
            transition: 0.2s;
        }
        .medication-item:hover { background: #f1f5f9; }
        .medication-item.controlled { border-left-color: var(--controlled); background: #fef2f2; }
        .medication-item.controlled:hover { background: #fee2e2; }
        .medication-item .med-name { font-weight: 700; font-size: 16px; }
        .medication-item .dosage-freq { font-size: 13px; color: #4b5563; }
        .medication-item .controlled-badge { background: var(--controlled); color: white; padding: 2px 10px; border-radius: 20px; font-size: 10px; font-weight: 700; margin-left: 8px; }
        .medication-item .remaining-badge { background: #e0e7ff; color: var(--primary); padding: 2px 10px; border-radius: 20px; font-size: 10px; font-weight: 600; }
        .btn-give { background: var(--success); color: white; border: none; padding: 6px 16px; border-radius: 12px; font-size: 12px; font-weight: 600; transition: 0.2s; }
        .btn-give:hover { background: #059669; }
        .btn-refuse { background: var(--warning); color: #000; border: none; padding: 6px 16px; border-radius: 12px; font-size: 12px; font-weight: 600; transition: 0.2s; }
        .btn-refuse:hover { background: #eab308; }
        .btn-hold { background: var(--info); color: white; border: none; padding: 6px 16px; border-radius: 12px; font-size: 12px; font-weight: 600; transition: 0.2s; }
        .btn-hold:hover { background: #0ea5e9; }
        .log-history { background: #f9fafb; border-radius: 10px; padding: 8px 12px; margin-top: 8px; font-size: 12px; max-height: 120px; overflow-y: auto; }
        .log-history .log-entry { padding: 4px 0; border-bottom: 1px solid #f1f5f9; }
        .log-history .log-entry:last-child { border-bottom: none; }
        .badge-given { background: #d1fae5; color: #059669; }
        .badge-refused { background: #fef3c7; color: #d97706; }
        .badge-held { background: #dbeafe; color: #2563eb; }
        .badge-missed { background: #fee2e2; color: #dc2626; }
        .empty-state { text-align: center; padding: 40px; color: var(--gray); }
        .empty-state i { font-size: 48px; opacity: 0.3; margin-bottom: 16px; }
        .modal-custom { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 2000; align-items: center; justify-content: center; backdrop-filter: blur(4px); }
        .modal-custom.active { display: flex; }
        .modal-content-custom { background: white; border-radius: 24px; max-width: 500px; width: 90%; padding: 24px; }
        .toast-notification { position: fixed; bottom: 20px; right: 20px; background: white; border-radius: 12px; padding: 12px 20px; box-shadow: 0 10px 25px rgba(0,0,0,0.1); z-index: 1100; animation: slideIn 0.3s ease; border-left: 4px solid var(--success); max-width: 400px; }
        .toast-error { border-left-color: var(--danger); }
        @keyframes slideIn { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0%); opacity: 1; } }
        .loading-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(255,255,255,0.7); z-index: 2000; display: none; align-items: center; justify-content: center; }
        .loading-overlay.active { display: flex; }
        .spinner { width: 40px; height: 40px; border: 3px solid var(--gray-light); border-top-color: var(--primary); border-radius: 50%; animation: spin 0.8s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }
        .action-buttons { display: flex; gap: 6px; flex-wrap: wrap; }
        .status-indicator { display: inline-block; width: 10px; height: 10px; border-radius: 50%; margin-right: 6px; }
        .status-given { background: var(--success); }
        .status-refused { background: var(--warning); }
        .status-held { background: var(--info); }
        .status-missed { background: var(--danger); }
        .search-box { position: relative; }
        .search-box input { width: 100%; padding: 8px 12px 8px 35px; border: 1px solid var(--gray-light); border-radius: 12px; font-size: 13px; }
        .search-box i { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--gray); }
        .patient-badge-critical { background: var(--danger); color: white; padding: 2px 8px; border-radius: 12px; font-size: 9px; font-weight: 700; margin-left: 6px; }
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
                    <div class="welcome-title">Medication Administration Record (MAR)</div>
                    <div class="welcome-subtitle">Electronic Medication Administration Record - Inpatient Medication Management</div>
                </div>
                <div class="time-badge"><i class="far fa-calendar-alt"></i> <?php echo escapeHtml($current_date); ?> <i class="far fa-clock ms-2"></i> <?php echo escapeHtml($current_time); ?></div>
            </div>

            <div class="row g-4">
                <!-- LEFT: Active inpatient roster -->
                <div class="col-lg-4 col-xl-3">
                    <div class="card-modern">
                        <div class="card-header-custom">
                            <h5 class="mb-0"><i class="fas fa-procedures me-2"></i> Active Inpatients</h5>
                            <span class="badge bg-primary"><?php echo $active_count; ?></span>
                        </div>
                        <div class="search-box p-2">
                            <i class="fas fa-search"></i>
                            <input type="text" id="patientSearch" placeholder="Search patients..." class="form-control" style="padding-left: 35px;">
                        </div>
                        <div class="patient-list-sidebar" id="patientList">
                            <?php if (is_array($active_inpatients) && count($active_inpatients) > 0): ?>
                                <?php foreach ($active_inpatients as $inp): 
                                    $fullName = trim($inp['patient_name'] ?? '');
                                    $wardBed = ($inp['ward_name'] ? escapeHtml($inp['ward_name']) : 'Ward') . ' - ' . ($inp['bed_number'] ? escapeHtml($inp['bed_number']) : 'No bed');
                                    $isCritical = in_array($inp['condition_status'] ?? '', ['critical', 'serious']);
                                    $criticalClass = $isCritical ? 'critical' : '';
                                ?>
                                    <div class="patient-card <?php echo $criticalClass; ?>" data-admission-id="<?php echo $inp['admission_id']; ?>" data-patient-name="<?php echo strtolower($fullName); ?>">
                                        <div class="d-flex justify-content-between align-items-start">
                                            <div>
                                                <div class="fw-bold">
                                                    <?php echo escapeHtml($fullName); ?>
                                                    <?php if ($isCritical): ?>
                                                        <span class="patient-badge-critical"><i class="fas fa-exclamation-triangle"></i> CRITICAL</span>
                                                    <?php endif; ?>
                                                </div>
                                                <small class="text-muted">MRN: <?php echo escapeHtml($inp['mr_number']); ?> | <?php echo $inp['age']; ?> yrs</small>
                                                <div class="mt-1">
                                                    <span class="badge bg-secondary"><?php echo $wardBed; ?></span>
                                                    <?php if (!empty($inp['condition_status'])): ?>
                                                        <span class="badge bg-light text-dark"><?php echo ucfirst($inp['condition_status']); ?></span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <i class="fas fa-chevron-right text-muted"></i>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="empty-state"><i class="fas fa-hospital-user"></i><p>No active inpatients</p></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- RIGHT: Medication Administration Workspace -->
                <div class="col-lg-8 col-xl-9">
                    <div class="card-modern" style="min-height: 500px;">
                        <div id="emptyStateContainer" class="empty-state py-5">
                            <i class="fas fa-notes-medical"></i>
                            <h5>No Patient Selected</h5>
                            <p class="text-muted">Please select a patient from the left panel</p>
                        </div>
                        <div id="activeMARContainer" style="display: none;">
                            <div class="p-3 bg-white border-bottom">
                                <div class="row align-items-center">
                                    <div class="col-md-7">
                                        <h4 id="patientNameHeader" class="mb-1">-</h4>
                                        <div class="text-muted small">
                                            MRN: <span id="mrNumberHeader">-</span> | 
                                            Gender: <span id="genderHeader">-</span> | 
                                            Age: <span id="ageHeader">-</span> |
                                            Blood Group: <span id="bloodGroupHeader">-</span>
                                        </div>
                                    </div>
                                    <div class="col-md-5 text-md-end mt-2 mt-md-0">
                                        <span class="badge bg-primary p-2"><i class="fas fa-bed"></i> Inpatient MAR</span>
                                        <span class="badge bg-info p-2 ms-1"><i class="fas fa-clock"></i> Today</span>
                                    </div>
                                </div>
                            </div>
                            <div id="medicationListContainer" class="p-3" style="max-height: 65vh; overflow-y: auto;">
                                <!-- Dynamic medication cards will be injected here -->
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php include('assets/inc/footer.php'); ?>
</div>

<!-- MODAL for medication administration confirmation -->
<div id="adminModal" class="modal-custom">
    <div class="modal-content-custom">
        <h5 class="mb-3"><i class="fas fa-syringe me-2"></i> Medication Administration</h5>
        <p id="modalMedName" class="fw-bold mb-2">-</p>
        <div class="mb-3">
            <label class="form-label fw-semibold">Action</label>
            <div class="d-flex gap-2 flex-wrap">
                <button class="btn btn-success btn-sm action-status-btn active" data-status="given"><i class="fas fa-check"></i> Given</button>
                <button class="btn btn-warning btn-sm action-status-btn" data-status="refused"><i class="fas fa-times"></i> Refused</button>
                <button class="btn btn-info btn-sm action-status-btn" data-status="held"><i class="fas fa-pause"></i> Held</button>
                <button class="btn btn-danger btn-sm action-status-btn" data-status="missed"><i class="fas fa-clock"></i> Missed</button>
            </div>
            <input type="hidden" id="selectedStatus" value="given">
        </div>
        <div class="mb-3">
            <label class="form-label">Nurse's Notes</label>
            <textarea id="modalNotes" class="form-control" rows="3" placeholder="Optional notes about administration..."></textarea>
        </div>
        <div class="d-flex justify-content-end gap-2">
            <button id="cancelAdminBtn" class="btn btn-outline-secondary">Cancel</button>
            <button id="confirmAdminBtn" class="btn btn-success">Confirm Administration</button>
        </div>
    </div>
</div>

<div id="loadingOverlay" class="loading-overlay"><div class="spinner"></div></div>

<script src="assets/js/vendor.min.js"></script>
<script src="assets/js/bootstrap.bundle.min.js"></script>
<script>
const csrfToken = <?php echo json_encode($csrf_token); ?>;
let currentAdmissionId = null;
let currentPrescriptionItemId = null;
let currentMedName = '';

// Status badge mapping
const statusBadgeMap = {
    'given': 'badge-given',
    'refused': 'badge-refused',
    'held': 'badge-held',
    'missed': 'badge-missed'
};

const statusIconMap = {
    'given': '✅',
    'refused': '❌',
    'held': '⏸️',
    'missed': '⏰'
};

function showToast(message, type) {
    type = type || 'success';
    const toast = document.createElement('div');
    toast.className = 'toast-notification' + (type === 'error' ? ' toast-error' : '');
    toast.innerHTML = '<i class="fas fa-' + (type === 'error' ? 'exclamation-circle' : 'check-circle') + '"></i> <span>' + escapeHtml(message) + '</span>';
    document.body.appendChild(toast);
    setTimeout(function() { toast.remove(); }, 3000);
}

function escapeHtml(unsafe) {
    if (!unsafe) return '';
    return String(unsafe).replace(/[&<>]/g, function(m) {
        return m === '&' ? '&amp;' : (m === '<' ? '&lt;' : '&gt;');
    });
}

function showLoading(show) {
    var overlay = document.getElementById('loadingOverlay');
    if (overlay) {
        overlay.style.display = show ? 'flex' : 'none';
    }
}

function loadPatientMAR(admissionId) {
    showLoading(true);
    fetch(window.location.pathname + '?action=fetch_patient_mar&admission_id=' + admissionId)
        .then(function(res) { return res.json(); })
        .then(function(data) {
            showLoading(false);
            if (data.success && data.data) {
                currentAdmissionId = admissionId;
                var patient = data.data.patient;
                var prescriptions = data.data.prescriptions || [];

                document.getElementById('patientNameHeader').innerText = patient.patient_name || 'Unknown';
                document.getElementById('mrNumberHeader').innerText = patient.mr_number || '-';
                document.getElementById('genderHeader').innerText = patient.gender ? patient.gender.charAt(0).toUpperCase() + patient.gender.slice(1) : '-';
                document.getElementById('ageHeader').innerText = patient.age || '-';
                document.getElementById('bloodGroupHeader').innerText = patient.blood_group || 'Unknown';

                var container = document.getElementById('medicationListContainer');
                if (!prescriptions || prescriptions.length === 0) {
                    container.innerHTML = '<div class="empty-state"><i class="fas fa-prescription-bottle"></i><p>No active medication orders for this patient</p></div>';
                } else {
                    var html = '';
                    for (var i = 0; i < prescriptions.length; i++) {
                        var med = prescriptions[i];
                        var logs = med.logs || [];
                        var logHtml = '';
                        if (logs.length) {
                            logHtml = '<div class="log-history mt-2"><strong><i class="fas fa-history"></i> Administration History:</strong>';
                            for (var j = 0; j < logs.length; j++) {
                                var log = logs[j];
                                var adminBy = ((log.first_name || '') + ' ' + (log.last_name || '')).trim() || 'Unknown';
                                var statusClass = statusBadgeMap[log.status] || 'badge-given';
                                var statusIcon = statusIconMap[log.status] || '✅';
                                logHtml += '<div class="log-entry">';
                                logHtml += '    <span class="badge ' + statusClass + '">' + statusIcon + ' ' + (log.status ? log.status.charAt(0).toUpperCase() + log.status.slice(1) : 'Given') + '</span>';
                                logHtml += '    ' + new Date(log.administered_at).toLocaleString() + ' - ' + escapeHtml(adminBy);
                                if (log.notes) {
                                    logHtml += '    <br><small class="text-muted">📝 ' + escapeHtml(log.notes) + '</small>';
                                }
                                logHtml += '</div>';
                            }
                            logHtml += '</div>';
                        }

                        var isControlled = med.is_controlled_substance == 1;
                        var remaining = med.remaining_quantity || 0;
                        var isFullyDispensed = remaining <= 0;

                        html += '<div class="medication-item' + (isControlled ? ' controlled' : '') + '" data-prescription-item-id="' + med.prescription_item_id + '">';
                        html += '    <div class="d-flex justify-content-between align-items-start flex-wrap">';
                        html += '        <div style="flex:1; min-width: 0;">';
                        html += '            <div class="med-name">';
                        html += '                ' + escapeHtml(med.medicine_name);
                        if (isControlled) {
                            html += '                <span class="controlled-badge"><i class="fas fa-exclamation-triangle"></i> CONTROLLED</span>';
                        }
                        if (isFullyDispensed) {
                            html += '                <span class="remaining-badge">✅ Fully Dispensed</span>';
                        } else {
                            html += '                <span class="remaining-badge">📦 ' + remaining + ' remaining</span>';
                        }
                        html += '            </div>';
                        html += '            <div class="dosage-freq">';
                        html += '                ' + escapeHtml(med.dosage || '') + ' | ' + escapeHtml(med.frequency || '');
                        if (med.duration_days) {
                            html += '                | ' + med.duration_days + ' days';
                        }
                        if (med.unit_of_measure) {
                            html += '                | ' + escapeHtml(med.unit_of_measure);
                        }
                        html += '            </div>';
                        if (med.instructions) {
                            html += '            <div class="small text-muted mt-1">📋 ' + escapeHtml(med.instructions) + '</div>';
                        }
                        if (med.meal_relation) {
                            html += '            <div class="small text-muted">🍽️ ' + escapeHtml(med.meal_relation.replace('_', ' ')) + '</div>';
                        }
                        if (med.prescription_number) {
                            html += '            <div class="small text-muted">📄 Rx: ' + escapeHtml(med.prescription_number) + '</div>';
                        }
                        html += logHtml;
                        html += '        </div>';
                        html += '        <div class="action-buttons mt-2 mt-md-0">';
                        if (!isFullyDispensed) {
                            html += '            <button class="btn-give give-med-btn" data-prescription-item-id="' + med.prescription_item_id + '" data-med-name="' + escapeHtml(med.medicine_name) + '">';
                            html += '                <i class="fas fa-check-circle"></i> Give';
                            html += '            </button>';
                            html += '            <button class="btn-refuse give-med-btn" data-prescription-item-id="' + med.prescription_item_id + '" data-med-name="' + escapeHtml(med.medicine_name) + '" data-status="refused">';
                            html += '                <i class="fas fa-times"></i> Refuse';
                            html += '            </button>';
                            html += '            <button class="btn-hold give-med-btn" data-prescription-item-id="' + med.prescription_item_id + '" data-med-name="' + escapeHtml(med.medicine_name) + '" data-status="held">';
                            html += '                <i class="fas fa-pause"></i> Hold';
                            html += '            </button>';
                        } else {
                            html += '            <span class="badge bg-secondary">All doses given</span>';
                        }
                        html += '        </div>';
                        html += '    </div>';
                        html += '</div>';
                    }
                    container.innerHTML = html;
                    attachGiveButtons();
                }
                document.getElementById('emptyStateContainer').style.display = 'none';
                document.getElementById('activeMARContainer').style.display = 'block';
            } else {
                showToast(data.message || 'Failed to load medication data', 'error');
            }
        })
        ['catch'](function(err) { 
            showLoading(false); 
            showToast('Network error', 'error'); 
            console.error(err); 
        });
}

function attachGiveButtons() {
    var buttons = document.querySelectorAll('.give-med-btn');
    for (var i = 0; i < buttons.length; i++) {
        buttons[i].removeEventListener('click', giveClickHandler);
        buttons[i].addEventListener('click', giveClickHandler);
    }
}

function giveClickHandler(e) {
    var btn = e.currentTarget;
    var prescriptionItemId = parseInt(btn.getAttribute('data-prescription-item-id'));
    var medName = btn.getAttribute('data-med-name');
    var status = btn.getAttribute('data-status') || 'given';
    
    if (!currentAdmissionId || !prescriptionItemId) {
        showToast('Missing admission or medication context', 'error');
        return;
    }
    
    currentPrescriptionItemId = prescriptionItemId;
    currentMedName = medName;
    
    document.getElementById('selectedStatus').value = status;
    var statusBtns = document.querySelectorAll('.action-status-btn');
    for (var i = 0; i < statusBtns.length; i++) {
        statusBtns[i].classList.remove('active');
        if (statusBtns[i].getAttribute('data-status') === status) {
            statusBtns[i].classList.add('active');
        }
    }
    
    document.getElementById('modalMedName').innerHTML = '<i class="fas fa-capsules"></i> ' + escapeHtml(medName) + ' <span class="badge ' + (status === 'given' ? 'badge-success' : status === 'refused' ? 'badge-warning' : status === 'held' ? 'badge-info' : 'badge-danger') + ' ms-2">' + status.charAt(0).toUpperCase() + status.slice(1) + '</span>';
    document.getElementById('modalNotes').value = '';
    document.getElementById('adminModal').classList.add('active');
}

// Status button selection
var statusBtns = document.querySelectorAll('.action-status-btn');
for (var i = 0; i < statusBtns.length; i++) {
    statusBtns[i].addEventListener('click', function() {
        var btns = document.querySelectorAll('.action-status-btn');
        for (var j = 0; j < btns.length; j++) {
            btns[j].classList.remove('active');
        }
        this.classList.add('active');
        document.getElementById('selectedStatus').value = this.getAttribute('data-status');
        
        var status = this.getAttribute('data-status');
        var medName = currentMedName;
        document.getElementById('modalMedName').innerHTML = '<i class="fas fa-capsules"></i> ' + escapeHtml(medName) + ' <span class="badge ' + (status === 'given' ? 'badge-success' : status === 'refused' ? 'badge-warning' : status === 'held' ? 'badge-info' : 'badge-danger') + ' ms-2">' + status.charAt(0).toUpperCase() + status.slice(1) + '</span>';
    });
}

function recordAdministration() {
    if (!currentAdmissionId || !currentPrescriptionItemId) {
        showToast('Missing required data', 'error');
        return;
    }
    
    var status = document.getElementById('selectedStatus').value || 'given';
    var notes = document.getElementById('modalNotes').value.trim();
    
    showLoading(true);
    fetch(window.location.pathname + '?action=record_administration', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({
            admission_id: currentAdmissionId,
            prescription_item_id: currentPrescriptionItemId,
            status: status,
            notes: notes,
            csrf_token: csrfToken
        })
    })
    .then(function(res) { return res.json(); })
    .then(function(data) {
        showLoading(false);
        if (data.success) {
            showToast(data.message);
            document.getElementById('adminModal').classList.remove('active');
            loadPatientMAR(currentAdmissionId);
        } else {
            showToast(data.message || 'Administration failed', 'error');
        }
    })
    ['catch'](function(err) {
        showLoading(false);
        showToast('Network error', 'error');
        console.error(err);
    });
}

// Event listeners
document.getElementById('cancelAdminBtn').addEventListener('click', function() {
    document.getElementById('adminModal').classList.remove('active');
});

document.getElementById('confirmAdminBtn').addEventListener('click', recordAdministration);

// Patient card click delegation
$(document).on('click', '.patient-card', function() {
    var admissionId = $(this).data('admission-id');
    if (!admissionId) return;
    $('.patient-card').removeClass('active');
    $(this).addClass('active');
    loadPatientMAR(admissionId);
});

// Patient search filter
var patientSearch = document.getElementById('patientSearch');
if (patientSearch) {
    patientSearch.addEventListener('input', function() {
        var query = this.value.toLowerCase();
        var cards = document.querySelectorAll('.patient-card');
        for (var i = 0; i < cards.length; i++) {
            var card = cards[i];
            var name = card.getAttribute('data-patient-name') || '';
            var mrNumber = card.textContent.toLowerCase();
            if (name.indexOf(query) !== -1 || mrNumber.indexOf(query) !== -1) {
                card.style.display = '';
            } else {
                card.style.display = 'none';
            }
        }
    });
}

// Close modal when clicking outside
document.getElementById('adminModal').addEventListener('click', function(e) {
    if (e.target === document.getElementById('adminModal')) {
        document.getElementById('adminModal').classList.remove('active');
    }
});

$(document).ready(function() {
    var firstCard = document.querySelector('.patient-card:not([style*="display: none"])');
    if (firstCard) {
        firstCard.classList.add('active');
        var firstAdmissionId = firstCard.getAttribute('data-admission-id');
        if (firstAdmissionId) loadPatientMAR(firstAdmissionId);
    }
});

// Sidebar toggle compatibility
if (typeof MetisMenu !== 'undefined') {
    var sideMenu = document.getElementById('side-menu');
    if (sideMenu && !sideMenu.hasAttribute('data-metismenu-initialized')) {
        new MetisMenu(sideMenu, { toggle: true, triggerElement: 'a.has-arrow', parentTrigger: 'li', subMenu: 'ul' });
        sideMenu.setAttribute('data-metismenu-initialized', 'true');
    }
}
</script>
</body>
</html>
