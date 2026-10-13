<?php
/**
 * ======================================================================
 * PATIENT ELECTRONIC MEDICAL RECORD (EMR) HUB - emr_view.php
 * For Doctor Module – Complete patient history across all clinical domains
 * Strictly aligned with hms_erp.sql, patients.sql, and branch isolation.
 * SEARCH FIXED + TIMELINE LOADING FIXED
 * ======================================================================
 */

session_start();

// ============================================
// 1. BOOTSTRAP & SECURITY
// ============================================
$base_path = dirname(dirname(dirname(__FILE__))); // /Hospital3
require_once $base_path . '/config/db_connection.php';
require_once $base_path . '/includes/auth_helper.php';

if (!isset($pdo) || $pdo === null) {
    die('Database connection failed.');
}

date_default_timezone_set('Africa/Addis_Ababa');
try {
    $pdo->exec("SET time_zone = '+03:00'");
} catch (PDOException $e) {
    // ignore
}

$user_id = (int)($_SESSION['user_id'] ?? 0);
$branch_id = (int)($_SESSION['branch_id'] ?? 0);
$is_global_admin = ($_SESSION['user_type'] ?? '') === 'super_admin';

if ($user_id <= 0) {
    header('Location: ../../index.php');
    exit;
}

// Permission: view_patient_history
if (!$is_global_admin && !user_has_permission($pdo, 'view_patient_history', $user_id)) {
    header('Location: dashboard.php?error=unauthorized');
    exit;
}

// CSRF token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Helper functions
function jsonResponse($success, $message, $data = null, $code = 200) {
    if (ob_get_length()) ob_clean();
    http_response_code($code);
    header('Content-Type: application/json');
    $response = ['success' => $success, 'message' => $message];
    if ($data !== null) $response = array_merge($response, $data);
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

function escapeHtml($str) {
    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}

function calculateAge($dob) {
    if (empty($dob)) return 'N/A';
    $birth = new DateTime($dob);
    $today = new DateTime();
    $age = $birth->diff($today)->y;
    return $age;
}

// Helper to check if table exists (for conditional queries)
function tableExists($pdo, $tableName) {
    try {
        $result = $pdo->query("SHOW TABLES LIKE '{$tableName}'");
        return $result->rowCount() > 0;
    } catch (PDOException $e) {
        return false;
    }
}

// ============================================
// 2. AJAX HANDLERS (emr_action)
// ============================================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'emr_action') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(false, 'Invalid request method');
    }
    $action = $_POST['action'] ?? '';
    $csrf_actions = ['search_patients', 'load_patient_demographics', 'load_emr_timeline'];
    if (in_array($action, $csrf_actions)) {
        if (!isset($_POST['csrf_token']) || !hash_equals($csrf_token, (string)$_POST['csrf_token'])) {
            jsonResponse(false, 'CSRF mismatch');
        }
    }

    try {
        // ---------- 2.1 SEARCH PATIENTS (same as working reception dashboard) ----------
        if ($action === 'search_patients') {
            $query = trim($_POST['query'] ?? '');
            if (strlen($query) < 2) {
                jsonResponse(true, '', ['patients' => []]);
            }
            $like = "%{$query}%";
            $sql = "SELECT 
                        id, 
                        CONCAT(COALESCE(first_name, ''), ' ', COALESCE(middle_name, ''), ' ', COALESCE(last_name, '')) as name,
                        mr_number, 
                        phone, 
                        email, 
                        gender, 
                        date_of_birth as birth_date, 
                        address,
                        is_active
                    FROM patients 
                    WHERE deleted_at IS NULL 
                        AND is_active = 1
                        AND (first_name LIKE :like1 
                             OR last_name LIKE :like2 
                             OR mr_number LIKE :like3 
                             OR phone LIKE :like4)
                    LIMIT 20";
            $params = [
                ':like1' => $like,
                ':like2' => $like,
                ':like3' => $like,
                ':like4' => $like
            ];
            if (!$is_global_admin && $branch_id > 0) {
                $sql = str_replace("WHERE deleted_at IS NULL", "WHERE branch_id = :branch_id AND deleted_at IS NULL", $sql);
                $params[':branch_id'] = $branch_id;
            }
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $patients = $stmt->fetchAll(PDO::FETCH_ASSOC);
            jsonResponse(true, '', ['patients' => $patients]);
        }

        // ---------- 2.2 LOAD PATIENT DEMOGRAPHICS ----------
        if ($action === 'load_patient_demographics') {
            $patient_id = (int)($_POST['patient_id'] ?? 0);
            if ($patient_id <= 0) {
                jsonResponse(false, 'Invalid patient ID');
            }
            $sql = "
                SELECT 
                    id, mr_number, first_name, last_name, middle_name,
                    date_of_birth, gender, blood_group, phone, email, address,
                    emergency_contact_name, emergency_contact_phone, emergency_contact_relationship,
                    is_active, is_consent_given
                FROM patients
                WHERE id = :patient_id AND branch_id = :branch_id AND deleted_at IS NULL
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':patient_id' => $patient_id, ':branch_id' => $branch_id]);
            $patient = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$patient) {
                jsonResponse(false, 'Patient not found');
            }
            jsonResponse(true, '', ['patient' => $patient]);
        }

        // ---------- 2.3 LOAD COMPLETE EMR TIMELINE (robust version) ----------
        if ($action === 'load_emr_timeline') {
            $patient_id = (int)($_POST['patient_id'] ?? 0);
            if ($patient_id <= 0) {
                jsonResponse(false, 'Invalid patient ID');
            }
            $timeline = [
                'consultations' => [],
                'lab_orders' => [],
                'radiology_orders' => [],
                'prescriptions' => [],
                'admissions' => []
            ];

            // 1) Consultations (exists in hms_erp.sql)
            try {
                $sql = "
                    SELECT 
                        id, consultation_number, consultation_date, consultation_time,
                        chief_complaint, assessment, treatment_plan, status,
                        created_at
                    FROM consultations
                    WHERE patient_id = :patient_id AND branch_id = :branch_id AND deleted_at IS NULL
                    ORDER BY consultation_date DESC, consultation_time DESC
                ";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([':patient_id' => $patient_id, ':branch_id' => $branch_id]);
                $timeline['consultations'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (PDOException $e) {
                // table might not exist or other error – leave empty
                error_log("Consultations query failed: " . $e->getMessage());
                $timeline['consultations'] = [];
            }

            // 2) Lab Orders with results (lab_orders + lab_test_results)
            try {
                $sql = "
                    SELECT 
                        lo.id, lo.order_number, lo.order_date, lo.priority, lo.status,
                        lo.clinical_indication,
                        GROUP_CONCAT(DISTINCT ltr.test_name SEPARATOR ', ') AS tests,
                        GROUP_CONCAT(DISTINCT CONCAT(ltr.result_numeric, ' ', ltr.result_unit) SEPARATOR ', ') AS results
                    FROM lab_orders lo
                    LEFT JOIN lab_test_results ltr ON lo.id = ltr.lab_order_id AND ltr.deleted_at IS NULL
                    WHERE lo.patient_id = :patient_id AND lo.branch_id = :branch_id AND lo.deleted_at IS NULL
                      AND lo.status IN ('completed', 'approved')
                    GROUP BY lo.id
                    ORDER BY lo.order_date DESC
                ";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([':patient_id' => $patient_id, ':branch_id' => $branch_id]);
                $timeline['lab_orders'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (PDOException $e) {
                error_log("Lab orders query failed: " . $e->getMessage());
                $timeline['lab_orders'] = [];
            }

            // 3) Radiology Orders (only if table exists)
            if (tableExists($pdo, 'radiology_orders')) {
                try {
                    $sql = "
                        SELECT 
                            ro.id, ro.order_number, ro.order_date, ro.priority, ro.status,
                            ro.service_name, ro.interpretation_report, ro.clinical_history
                        FROM radiology_orders ro
                        WHERE ro.patient_id = :patient_id AND ro.branch_id = :branch_id AND ro.deleted_at IS NULL
                          AND ro.status IN ('completed', 'reviewed')
                        ORDER BY ro.order_date DESC
                    ";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([':patient_id' => $patient_id, ':branch_id' => $branch_id]);
                    $timeline['radiology_orders'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
                } catch (PDOException $e) {
                    error_log("Radiology orders query failed: " . $e->getMessage());
                    $timeline['radiology_orders'] = [];
                }
            }

            // 4) Prescriptions (prescriptions + prescription_items)
            try {
                $sql = "
                    SELECT 
                        p.id, p.prescription_number, p.prescription_date, p.clinical_notes, p.status,
                        GROUP_CONCAT(
                            CONCAT(pi.medicine_name, ' ', pi.dosage, ' ', pi.frequency, 
                                   IF(pi.duration_days > 0, CONCAT(' for ', pi.duration_days, ' days'), ''))
                            SEPARATOR '; '
                        ) AS medications
                    FROM prescriptions p
                    LEFT JOIN prescription_items pi ON p.id = pi.prescription_id AND pi.deleted_at IS NULL
                    WHERE p.patient_id = :patient_id AND p.branch_id = :branch_id AND p.deleted_at IS NULL
                    GROUP BY p.id
                    ORDER BY p.prescription_date DESC
                ";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([':patient_id' => $patient_id, ':branch_id' => $branch_id]);
                $timeline['prescriptions'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (PDOException $e) {
                error_log("Prescriptions query failed: " . $e->getMessage());
                $timeline['prescriptions'] = [];
            }

            // 5) Hospitalizations (admissions + optional ward_beds)
            try {
                $sql = "
                    SELECT 
                        a.id, a.admission_number, a.admitted_at, a.discharged_at,
                        a.primary_diagnosis, a.condition_status, a.status,
                        wb.bed_number, w.name AS ward_name
                    FROM admissions a
                    LEFT JOIN ward_beds wb ON a.bed_id = wb.id AND wb.deleted_at IS NULL
                    LEFT JOIN wards w ON wb.ward_id = w.id AND w.deleted_at IS NULL
                    WHERE a.patient_id = :patient_id AND a.branch_id = :branch_id AND a.deleted_at IS NULL
                    ORDER BY a.admitted_at DESC
                ";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([':patient_id' => $patient_id, ':branch_id' => $branch_id]);
                $timeline['admissions'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (PDOException $e) {
                // If ward_beds or wards missing, retry without those joins
                error_log("Admissions full query failed, trying simplified: " . $e->getMessage());
                try {
                    $sql = "
                        SELECT 
                            a.id, a.admission_number, a.admitted_at, a.discharged_at,
                            a.primary_diagnosis, a.condition_status, a.status,
                            NULL AS bed_number, NULL AS ward_name
                        FROM admissions a
                        WHERE a.patient_id = :patient_id AND a.branch_id = :branch_id AND a.deleted_at IS NULL
                        ORDER BY a.admitted_at DESC
                    ";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([':patient_id' => $patient_id, ':branch_id' => $branch_id]);
                    $timeline['admissions'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
                } catch (PDOException $e2) {
                    error_log("Admissions simplified query failed: " . $e2->getMessage());
                    $timeline['admissions'] = [];
                }
            }

            jsonResponse(true, '', ['timeline' => $timeline]);
        }

        jsonResponse(false, 'Unsupported action');
    } catch (PDOException $e) {
        error_log("EMR AJAX Error: " . $e->getMessage());
        jsonResponse(false, 'Database error: ' . $e->getMessage());
    } catch (Exception $e) {
        error_log("EMR AJAX General Error: " . $e->getMessage());
        jsonResponse(false, 'Error: ' . $e->getMessage());
    }
    exit;
}

// ============================================
// 3. INITIAL PAGE DATA (template only)
// ============================================
$doctor_name = $_SESSION['first_name'] ?? $_SESSION['username'] ?? 'Doctor';
$current_date = date('l, F j, Y');
$current_time = date('g:i A');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include($base_path . '/modules/doctor/assets/inc/head.php'); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EMR Viewer - Patient History | HMS ERP</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="<?php echo $base_path; ?>/modules/doctor/assets/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <style>
        /* Same styles as before (keep unchanged) */
        :root {
            --primary: #4361ee;
            --primary-dark: #3a0ca3;
            --success: #06d6a0;
            --warning: #f9c74f;
            --danger: #ef476f;
            --info: #4cc9f0;
            --dark: #1a1a2e;
            --gray: #6b7280;
            --light-gray: #f3f4f6;
            --border-gray: #e5e7eb;
        }
        * { margin:0; padding:0; box-sizing:border-box; }
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
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
        @media (max-width: 768px) { .content-page { margin-left: 0!important; padding: 16px; } }

        .page-header {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--primary) 100%);
            border-radius: 20px;
            padding: 20px 28px;
            margin-bottom: 28px;
            color: white;
        }
        .page-title { font-size: 24px; font-weight: 700; margin-bottom: 4px; }
        .page-subtitle { font-size: 14px; color: rgba(255,255,255,0.8); }

        .search-container {
            background: white;
            border-radius: 20px;
            padding: 20px 24px;
            margin-bottom: 28px;
            border: 1px solid var(--border-gray);
        }
        .search-input-group input {
            width: 100%;
            padding: 14px 20px;
            border: 1px solid var(--border-gray);
            border-radius: 14px;
            font-size: 14px;
        }
        .search-input-group input:focus {
            outline: none;
            border-color: var(--primary);
        }
        .search-results-list {
            max-height: 400px;
            overflow-y: auto;
            border: 1px solid var(--border-gray);
            border-radius: 12px;
            margin-top: 16px;
            display: none;
        }
        .search-result-item {
            padding: 16px;
            cursor: pointer;
            border-bottom: 1px solid var(--border-gray);
        }
        .search-result-item:hover {
            background: var(--light-gray);
        }
        .search-result-actions {
            display: flex;
            gap: 8px;
            margin-top: 8px;
        }
        .action-btn {
            padding: 6px 14px;
            border-radius: 10px;
            font-size: 12px;
            font-weight: 500;
            border: none;
            cursor: pointer;
        }
        .action-btn-view {
            background: var(--primary);
            color: white;
        }
        .action-btn-view:hover {
            background: var(--primary-dark);
        }

        .emr-container {
            display: flex;
            gap: 24px;
        }
        .demographics-card {
            background: white;
            border-radius: 20px;
            border: 1px solid var(--border-gray);
            overflow: hidden;
            flex-shrink: 0;
            width: 320px;
        }
        @media (max-width: 992px) {
            .emr-container { flex-direction: column; }
            .demographics-card { width: 100%; }
        }
        .profile-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 24px;
            text-align: center;
            color: white;
        }
        .profile-avatar {
            width: 100px;
            height: 100px;
            background: rgba(255,255,255,0.2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 16px;
            font-size: 48px;
        }
        .profile-name {
            font-size: 20px;
            font-weight: 700;
            margin-bottom: 4px;
        }
        .profile-mrn {
            font-size: 12px;
            opacity: 0.9;
        }
        .demographics-body { padding: 20px; }
        .info-row {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid var(--border-gray);
            font-size: 13px;
        }
        .info-label { font-weight: 600; color: var(--gray); }
        .info-value { color: var(--dark); }

        .history-panel {
            flex: 1;
            background: white;
            border-radius: 20px;
            border: 1px solid var(--border-gray);
            overflow: hidden;
        }
        .nav-tabs {
            border-bottom: 1px solid var(--border-gray);
            background: #f8fafc;
            padding: 0 20px;
            display: flex;
            flex-wrap: wrap;
        }
        .nav-tabs .nav-link {
            border: none;
            padding: 14px 20px;
            font-weight: 600;
            color: var(--gray);
            transition: 0.2s;
            background: transparent;
            border-bottom: 2px solid transparent;
        }
        .nav-tabs .nav-link.active {
            color: var(--primary);
            border-bottom-color: var(--primary);
            background: transparent;
        }
        .tab-content { padding: 24px; }
        .tab-pane { display: none; }
        .tab-pane.active { display: block; }

        .timeline-item {
            border-left: 3px solid var(--primary);
            padding: 16px;
            margin-bottom: 16px;
            background: var(--light-gray);
            border-radius: 12px;
        }
        .timeline-date {
            font-size: 12px;
            color: var(--gray);
            margin-bottom: 8px;
        }
        .consultation-card, .lab-card, .rad-card, .rx-card, .admission-card {
            border: 1px solid var(--border-gray);
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 16px;
        }
        .badge-status {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 10px;
            font-weight: 600;
        }
        .badge-completed { background: #d1fae5; color: #059669; }
        .badge-pending { background: #fef3c7; color: #d97706; }
        .badge-stat { background: #fee2e2; color: #dc2626; animation: pulse 1s infinite; }
        @keyframes pulse { 0%,100% { opacity: 1; } 50% { opacity: 0.7; } }

        .toast-notification {
            position: fixed; bottom: 20px; right: 20px;
            background: white; border-radius: 12px; padding: 12px 20px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
            z-index: 1100; animation: slideIn 0.3s;
            border-left: 4px solid var(--success);
        }
        .toast-error { border-left-color: var(--danger); }
        @keyframes slideIn { from { transform: translateX(100%); opacity:0; } to { transform: translateX(0); opacity:1; } }
        .loading-overlay {
            position: fixed; top:0; left:0; width:100%; height:100%;
            background: rgba(255,255,255,0.7); z-index:2000;
            display: none; align-items: center; justify-content: center;
        }
        .loading-overlay.active { display: flex; }
        .spinner {
            width: 40px; height: 40px;
            border: 3px solid var(--light-gray);
            border-top-color: var(--primary);
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
        .empty-state { text-align: center; padding: 40px; color: var(--gray); }
    </style>
</head>
<body>
<div id="wrapper">
    <?php include($base_path . '/modules/doctor/assets/inc/nav.php'); ?>
    <?php include($base_path . '/modules/doctor/assets/inc/sidebar.php'); ?>

    <div class="content-page">
        <div class="container-fluid">
            <div class="page-header">
                <div class="page-title"><i class="fas fa-folder-open me-2"></i> Electronic Medical Record (EMR)</div>
                <div class="page-subtitle">Complete patient history – consultations, labs, medications, hospitalizations</div>
            </div>

            <!-- Patient Search (Live) -->
            <div class="search-container">
                <div class="search-input-group">
                    <input type="text" id="globalSearch" placeholder="Search by Patient Name, MRN, or Phone Number..." autocomplete="off">
                </div>
                <div id="liveSearchResults" class="search-results-list"></div>
            </div>

            <div id="emrContent" style="display: none;">
                <div class="emr-container">
                    <!-- Left: Demographics Card -->
                    <div class="demographics-card" id="demographicsCard">
                        <div class="profile-header">
                            <div class="profile-avatar"><i class="fas fa-user-circle"></i></div>
                            <div class="profile-name" id="patientName">—</div>
                            <div class="profile-mrn" id="patientMrn">MRN: —</div>
                        </div>
                        <div class="demographics-body" id="demographicsBody">
                            <div class="info-row"><span class="info-label">Age / DOB:</span><span class="info-value" id="patientAgeDob">—</span></div>
                            <div class="info-row"><span class="info-label">Gender:</span><span class="info-value" id="patientGender">—</span></div>
                            <div class="info-row"><span class="info-label">Blood Group:</span><span class="info-value" id="patientBloodGroup">—</span></div>
                            <div class="info-row"><span class="info-label">Phone:</span><span class="info-value" id="patientPhone">—</span></div>
                            <div class="info-row"><span class="info-label">Email:</span><span class="info-value" id="patientEmail">—</span></div>
                            <div class="info-row"><span class="info-label">Address:</span><span class="info-value" id="patientAddress">—</span></div>
                            <div class="info-row"><span class="info-label">Emergency Contact:</span><span class="info-value" id="patientEmergency">—</span></div>
                            <div class="info-row"><span class="info-label">Consent:</span><span class="info-value" id="patientConsent">—</span></div>
                        </div>
                    </div>

                    <!-- Right: History Tabs -->
                    <div class="history-panel">
                        <ul class="nav nav-tabs" id="emrTabs" role="tablist">
                            <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#timeline-tab" type="button" role="tab">🕒 Clinical Timeline</button></li>
                            <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#consultations-tab" type="button" role="tab">📝 Consultation Notes</button></li>
                            <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#labs-tab" type="button" role="tab">🧪 Lab & Imaging</button></li>
                            <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#medications-tab" type="button" role="tab">💊 Medication History</button></li>
                            <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#hospitalizations-tab" type="button" role="tab">🛌 Hospitalization Records</button></li>
                        </ul>
                        <div class="tab-content">
                            <!-- Timeline Tab -->
                            <div class="tab-pane active" id="timeline-tab" role="tabpanel">
                                <div id="timelineContainer"><div class="empty-state">Select a patient to view timeline</div></div>
                            </div>
                            <!-- Consultations Tab -->
                            <div class="tab-pane" id="consultations-tab" role="tabpanel">
                                <div id="consultationsContainer"><div class="empty-state">No consultation records</div></div>
                            </div>
                            <!-- Lab & Imaging Tab -->
                            <div class="tab-pane" id="labs-tab" role="tabpanel">
                                <div id="labsContainer"><div class="empty-state">No lab or imaging records</div></div>
                            </div>
                            <!-- Medications Tab -->
                            <div class="tab-pane" id="medications-tab" role="tabpanel">
                                <div id="medicationsContainer"><div class="empty-state">No prescription history</div></div>
                            </div>
                            <!-- Hospitalizations Tab -->
                            <div class="tab-pane" id="hospitalizations-tab" role="tabpanel">
                                <div id="hospitalizationsContainer"><div class="empty-state">No hospitalization records</div></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div id="noPatientMessage" class="text-center py-5">
                <i class="fas fa-user-md fa-4x text-muted mb-3"></i>
                <h4>Select a patient to view medical records</h4>
                <p class="text-muted">Search by name or MRN above</p>
            </div>
        </div>
    </div>
    <?php include($base_path . '/modules/doctor/assets/inc/footer.php'); ?>
</div>

<div id="loadingOverlay" class="loading-overlay"><div class="spinner"></div></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
const doctorId = <?php echo $user_id; ?>;
const branchId = <?php echo $branch_id; ?>;
const csrfToken = <?php echo json_encode($csrf_token); ?>;
let currentPatientId = null;

function showToast(msg, type='success') {
    const t = document.createElement('div');
    t.className = `toast-notification ${type==='error'?'toast-error':''}`;
    t.innerHTML = `<i class="fas fa-${type==='error'?'exclamation-circle':'check-circle'}"></i> <span>${escapeHtml(msg)}</span>`;
    document.body.appendChild(t);
    setTimeout(()=>t.remove(),3000);
}
function showLoading(show) {
    document.getElementById('loadingOverlay').classList.toggle('active', show);
}
function escapeHtml(s) {
    if (!s) return '';
    return String(s).replace(/[&<>]/g, m => m==='&'?'&amp;':(m==='<'?'&lt;':'&gt;'));
}

// ============================================
// LIVE PATIENT SEARCH
// ============================================
let searchDebounceTimer;
const globalSearch = document.getElementById('globalSearch');
const liveSearchResults = document.getElementById('liveSearchResults');

function performLiveSearch() {
    const query = globalSearch.value.trim();
    if (query.length < 2) {
        liveSearchResults.style.display = 'none';
        return;
    }
    showLoading(true);
    $.ajax({
        url: window.location.pathname + '?ajax=emr_action',
        method: 'POST',
        data: {
            action: 'search_patients',
            query: query,
            branch_id: branchId,
            csrf_token: csrfToken
        },
        dataType: 'json',
        success: function(res) {
            showLoading(false);
            if (res.success && res.patients && res.patients.length > 0) {
                let html = '';
                res.patients.forEach(p => {
                    html += `<div class="search-result-item">
                        <div class="fw-bold">${escapeHtml(p.name)}</div>
                        <div class="small text-muted">MRN: ${escapeHtml(p.mr_number || 'N/A')} | Phone: ${escapeHtml(p.phone || 'N/A')}</div>
                        <div class="search-result-actions">
                            <button class="action-btn action-btn-view" onclick="loadPatientEMR(${p.id}, '${escapeHtml(p.name).replace(/'/g, "\\'")}')"><i class="fas fa-folder-open"></i> View EMR</button>
                        </div>
                    </div>`;
                });
                liveSearchResults.innerHTML = html;
                liveSearchResults.style.display = 'block';
            } else {
                liveSearchResults.innerHTML = '<div class="p-3 text-center text-muted">No patients found.</div>';
                liveSearchResults.style.display = 'block';
            }
        },
        error: function(xhr, status, error) {
            showLoading(false);
            console.error('Search error:', error);
            liveSearchResults.innerHTML = '<div class="p-3 text-center text-danger">Search failed. Please try again.</div>';
            liveSearchResults.style.display = 'block';
        }
    });
}

if (globalSearch) {
    globalSearch.addEventListener('input', () => {
        clearTimeout(searchDebounceTimer);
        searchDebounceTimer = setTimeout(performLiveSearch, 300);
    });
}

document.addEventListener('click', (e) => {
    if (globalSearch && liveSearchResults &&
        !globalSearch.contains(e.target) && !liveSearchResults.contains(e.target)) {
        liveSearchResults.style.display = 'none';
    }
});

// ============================================
// LOAD PATIENT EMR DATA
// ============================================
function loadPatientEMR(patientId, patientName) {
    currentPatientId = patientId;
    showLoading(true);
    
    // Load demographics first, then timeline
    loadPatientDemographics(patientId)
        .then(() => loadFullEMR(patientId))
        .then(() => {
            showLoading(false);
            document.getElementById('emrContent').style.display = 'block';
            document.getElementById('noPatientMessage').style.display = 'none';
            globalSearch.value = patientName;
            liveSearchResults.style.display = 'none';
        })
        .catch((err) => {
            showLoading(false);
            console.error('Load patient error:', err);
            showToast('Failed to load patient data', 'error');
        });
}

function loadPatientDemographics(patientId) {
    return $.post(window.location.pathname + '?ajax=emr_action', {
        action: 'load_patient_demographics',
        patient_id: patientId,
        csrf_token: csrfToken,
        branch_id: branchId
    }, function(res) {
        if (res.success && res.patient) {
            const p = res.patient;
            document.getElementById('patientName').textContent = escapeHtml(p.first_name + ' ' + p.last_name);
            document.getElementById('patientMrn').textContent = 'MRN: ' + escapeHtml(p.mr_number);
            const age = p.date_of_birth ? calculateAge(p.date_of_birth) + ' y' : 'N/A';
            document.getElementById('patientAgeDob').textContent = age + ' (' + (p.date_of_birth || '—') + ')';
            document.getElementById('patientGender').textContent = escapeHtml(p.gender || '—');
            document.getElementById('patientBloodGroup').textContent = escapeHtml(p.blood_group || '—');
            document.getElementById('patientPhone').textContent = escapeHtml(p.phone || '—');
            document.getElementById('patientEmail').textContent = escapeHtml(p.email || '—');
            document.getElementById('patientAddress').textContent = escapeHtml(p.address || '—');
            let emergency = '';
            if (p.emergency_contact_name) emergency += p.emergency_contact_name;
            if (p.emergency_contact_phone) emergency += ' (' + p.emergency_contact_phone + ')';
            if (p.emergency_contact_relationship) emergency += ' [' + p.emergency_contact_relationship + ']';
            document.getElementById('patientEmergency').textContent = emergency || '—';
            document.getElementById('patientConsent').textContent = p.is_consent_given ? '✅ Given' : '❌ Not given';
        } else {
            throw new Error('Demographics load failed');
        }
    });
}

function loadFullEMR(patientId) {
    return $.post(window.location.pathname + '?ajax=emr_action', {
        action: 'load_emr_timeline',
        patient_id: patientId,
        csrf_token: csrfToken,
        branch_id: branchId
    }, function(res) {
        if (res.success && res.timeline) {
            renderTimeline(res.timeline);
            renderConsultations(res.timeline.consultations);
            renderLabImaging(res.timeline.lab_orders, res.timeline.radiology_orders);
            renderMedications(res.timeline.prescriptions);
            renderHospitalizations(res.timeline.admissions);
        } else {
            throw new Error(res.message || 'Timeline load failed');
        }
    });
}

function renderTimeline(timeline) {
    let html = '';
    const events = [];

    // Collect all events with date
    if (timeline.consultations && timeline.consultations.length) {
        timeline.consultations.forEach(c => {
            events.push({
                date: c.consultation_date + ' ' + (c.consultation_time || '00:00:00'),
                type: 'consultation',
                title: 'Consultation: ' + (c.consultation_number || ''),
                description: c.chief_complaint || c.assessment || '',
                data: c
            });
        });
    }
    if (timeline.lab_orders && timeline.lab_orders.length) {
        timeline.lab_orders.forEach(l => {
            events.push({
                date: l.order_date,
                type: 'lab',
                title: 'Lab Order: ' + l.order_number,
                description: l.tests || l.clinical_indication || '',
                data: l
            });
        });
    }
    if (timeline.radiology_orders && timeline.radiology_orders.length) {
        timeline.radiology_orders.forEach(r => {
            events.push({
                date: r.order_date,
                type: 'radiology',
                title: 'Radiology: ' + (r.service_name || r.order_number),
                description: r.interpretation_report || r.clinical_history || '',
                data: r
            });
        });
    }
    if (timeline.prescriptions && timeline.prescriptions.length) {
        timeline.prescriptions.forEach(rx => {
            events.push({
                date: rx.prescription_date,
                type: 'prescription',
                title: 'Prescription: ' + rx.prescription_number,
                description: rx.medications || '',
                data: rx
            });
        });
    }
    if (timeline.admissions && timeline.admissions.length) {
        timeline.admissions.forEach(a => {
            events.push({
                date: a.admitted_at,
                type: 'admission',
                title: 'Hospitalization: ' + (a.admission_number || ''),
                description: a.primary_diagnosis || '',
                data: a
            });
        });
    }

    events.sort((a,b) => new Date(b.date) - new Date(a.date));
    if (events.length === 0) {
        html = '<div class="empty-state">No clinical events found</div>';
    } else {
        events.forEach(e => {
            let badgeClass = '';
            let icon = '';
            switch(e.type) {
                case 'consultation': badgeClass = 'badge-completed'; icon = '<i class="fas fa-stethoscope"></i>'; break;
                case 'lab': badgeClass = 'badge-completed'; icon = '<i class="fas fa-microscope"></i>'; break;
                case 'radiology': badgeClass = 'badge-completed'; icon = '<i class="fas fa-x-ray"></i>'; break;
                case 'prescription': badgeClass = 'badge-completed'; icon = '<i class="fas fa-prescription-bottle"></i>'; break;
                case 'admission': badgeClass = 'badge-completed'; icon = '<i class="fas fa-procedures"></i>'; break;
                default: badgeClass = 'badge-completed';
            }
            html += `
                <div class="timeline-item">
                    <div class="timeline-date">${new Date(e.date).toLocaleString()}</div>
                    <div class="d-flex align-items-center gap-2 mb-2">
                        ${icon} <strong>${escapeHtml(e.title)}</strong>
                        <span class="badge-status ${badgeClass}">${e.type}</span>
                    </div>
                    <div class="small text-muted">${escapeHtml(e.description.substring(0, 200))}${e.description.length > 200 ? '...' : ''}</div>
                </div>
            `;
        });
    }
    document.getElementById('timelineContainer').innerHTML = html;
}

function renderConsultations(consultations) {
    if (!consultations || consultations.length === 0) {
        document.getElementById('consultationsContainer').innerHTML = '<div class="empty-state">No consultation records</div>';
        return;
    }
    let html = '';
    consultations.forEach(c => {
        html += `
            <div class="consultation-card">
                <div class="d-flex justify-content-between mb-2">
                    <strong>${escapeHtml(c.consultation_number)}</strong>
                    <span class="small text-muted">${c.consultation_date} ${c.consultation_time ? c.consultation_time.substring(0,5) : ''}</span>
                </div>
                <div><strong>Chief Complaint:</strong> ${escapeHtml(c.chief_complaint || '—')}</div>
                <div><strong>Assessment:</strong> ${escapeHtml(c.assessment || '—')}</div>
                <div><strong>Plan:</strong> ${escapeHtml(c.treatment_plan || '—')}</div>
                <div class="mt-2"><span class="badge-status ${c.status === 'finalized' ? 'badge-completed' : 'badge-pending'}">${c.status}</span></div>
            </div>
        `;
    });
    document.getElementById('consultationsContainer').innerHTML = html;
}

function renderLabImaging(labOrders, radOrders) {
    let html = '';
    if ((!labOrders || labOrders.length === 0) && (!radOrders || radOrders.length === 0)) {
        document.getElementById('labsContainer').innerHTML = '<div class="empty-state">No lab or imaging records</div>';
        return;
    }
    if (labOrders && labOrders.length) {
        html += '<h6 class="mb-3">Laboratory Results</h6>';
        labOrders.forEach(l => {
            let priorityClass = l.priority === 'stat' ? 'badge-stat' : 'badge-completed';
            html += `
                <div class="lab-card">
                    <div class="d-flex justify-content-between">
                        <strong>${escapeHtml(l.order_number)}</strong>
                        <span><span class="badge-status ${priorityClass}">${l.priority}</span> <span class="badge-status badge-completed">${l.status}</span></span>
                    </div>
                    <div class="small text-muted">${l.order_date}</div>
                    <div><strong>Tests:</strong> ${escapeHtml(l.tests || '—')}</div>
                    <div><strong>Results:</strong> ${escapeHtml(l.results || '—')}</div>
                    ${l.clinical_indication ? `<div class="small text-muted mt-1">Indication: ${escapeHtml(l.clinical_indication)}</div>` : ''}
                </div>
            `;
        });
    }
    if (radOrders && radOrders.length) {
        html += '<h6 class="mb-3 mt-4">Radiology Reports</h6>';
        radOrders.forEach(r => {
            let priorityClass = r.priority === 'stat' ? 'badge-stat' : 'badge-completed';
            html += `
                <div class="rad-card">
                    <div class="d-flex justify-content-between">
                        <strong>${escapeHtml(r.order_number)}</strong>
                        <span><span class="badge-status ${priorityClass}">${r.priority}</span> <span class="badge-status badge-completed">${r.status}</span></span>
                    </div>
                    <div class="small text-muted">${r.order_date}</div>
                    <div><strong>Service:</strong> ${escapeHtml(r.service_name || '—')}</div>
                    <div><strong>Findings:</strong> ${escapeHtml(r.interpretation_report || r.clinical_history || '—')}</div>
                </div>
            `;
        });
    }
    document.getElementById('labsContainer').innerHTML = html;
}

function renderMedications(prescriptions) {
    if (!prescriptions || prescriptions.length === 0) {
        document.getElementById('medicationsContainer').innerHTML = '<div class="empty-state">No medication history</div>';
        return;
    }
    let html = '';
    prescriptions.forEach(rx => {
        html += `
            <div class="rx-card">
                <div class="d-flex justify-content-between">
                    <strong>${escapeHtml(rx.prescription_number)}</strong>
                    <span class="small text-muted">${rx.prescription_date}</span>
                </div>
                <div class="small text-muted">Status: ${rx.status}</div>
                <div><strong>Medications:</strong> ${escapeHtml(rx.medications || '—')}</div>
                ${rx.clinical_notes ? `<div class="small text-muted mt-1">Notes: ${escapeHtml(rx.clinical_notes)}</div>` : ''}
            </div>
        `;
    });
    document.getElementById('medicationsContainer').innerHTML = html;
}

function renderHospitalizations(admissions) {
    if (!admissions || admissions.length === 0) {
        document.getElementById('hospitalizationsContainer').innerHTML = '<div class="empty-state">No hospitalization records</div>';
        return;
    }
    let html = '';
    admissions.forEach(a => {
        const admitted = a.admitted_at ? new Date(a.admitted_at).toLocaleString() : '—';
        const discharged = a.discharged_at ? new Date(a.discharged_at).toLocaleString() : 'Active';
        let conditionClass = '';
        if (a.condition_status === 'critical') conditionClass = 'badge-stat';
        else if (a.condition_status === 'guarded') conditionClass = 'badge-pending';
        else conditionClass = 'badge-completed';
        html += `
            <div class="admission-card">
                <div class="d-flex justify-content-between">
                    <strong>${escapeHtml(a.admission_number || 'Admission')}</strong>
                    <span><span class="badge-status ${conditionClass}">${a.condition_status}</span></span>
                </div>
                <div class="small text-muted">Admitted: ${admitted} | Discharged: ${discharged}</div>
                <div><strong>Ward/Bed:</strong> ${escapeHtml(a.ward_name || '—')} / ${escapeHtml(a.bed_number || '—')}</div>
                <div><strong>Primary Diagnosis:</strong> ${escapeHtml(a.primary_diagnosis || '—')}</div>
                <div><strong>Status:</strong> ${a.status}</div>
            </div>
        `;
    });
    document.getElementById('hospitalizationsContainer').innerHTML = html;
}

function calculateAge(dob) {
    const birth = new Date(dob);
    const today = new Date();
    let age = today.getFullYear() - birth.getFullYear();
    const m = today.getMonth() - birth.getMonth();
    if (m < 0 || (m === 0 && today.getDate() < birth.getDate())) age--;
    return age;
}

// Initialize Bootstrap tabs manually
const triggerTabList = document.querySelectorAll('#emrTabs button');
triggerTabList.forEach(triggerEl => {
    triggerEl.addEventListener('click', event => {
        event.preventDefault();
        const targetId = triggerEl.getAttribute('data-bs-target');
        const targetPane = document.querySelector(targetId);
        document.querySelectorAll('#emrTabs button').forEach(btn => btn.classList.remove('active'));
        document.querySelectorAll('.tab-pane').forEach(pane => pane.classList.remove('active'));
        triggerEl.classList.add('active');
        if (targetPane) targetPane.classList.add('active');
    });
});
</script>
</body>
</html>