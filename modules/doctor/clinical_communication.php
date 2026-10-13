<?php
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

// RBAC: manage_patient_referrals OR doctor_consultation_access
if (!$is_global_admin && !user_has_permission($pdo, 'manage_patient_referrals', $user_id)
    && !user_has_permission($pdo, 'doctor_consultation_access', $user_id)) {
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

// ============================================
// 2. AJAX HANDLERS (communication_action)
// ============================================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'communication_action') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(false, 'Invalid request method');
    }
    $action = $_POST['action'] ?? '';
    $csrf_actions = ['load_doctors', 'search_patients', 'create_referral', 'fetch_incoming_referrals',
                     'update_referral_status', 'request_consultation', 'fetch_consultation_requests',
                     'acknowledge_consultation'];
    if (in_array($action, $csrf_actions)) {
        if (!isset($_POST['csrf_token']) || !hash_equals($csrf_token, (string)$_POST['csrf_token'])) {
            jsonResponse(false, 'CSRF mismatch');
        }
    }

    try {
        // ---------- 2.1 LOAD DOCTORS LIST (for referrals & consultations) ----------
        if ($action === 'load_doctors') {
            $sql = "
                SELECT id, CONCAT(first_name, ' ', last_name) AS full_name, user_type
                FROM users
                WHERE branch_id = :branch_id
                  AND user_type IN ('doctor', 'super_admin', 'admin')
                  AND id != :current_doctor
                  AND is_active = 1
                  AND deleted_at IS NULL
                ORDER BY first_name ASC
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':branch_id' => $branch_id, ':current_doctor' => $user_id]);
            $doctors = $stmt->fetchAll(PDO::FETCH_ASSOC);
            jsonResponse(true, '', ['doctors' => $doctors]);
        }

        // ---------- 2.2 SEARCH PATIENTS (mr_number or name) ----------
        if ($action === 'search_patients') {
            $term = trim($_POST['term'] ?? '');
            if (strlen($term) < 2) {
                jsonResponse(true, '', ['patients' => []]);
            }
            $sql = "
                SELECT 
                    p.id,
                    CONCAT(p.first_name, ' ', p.last_name, ' (MRN: ', p.mr_number, ')') AS text
                FROM patients p
                WHERE p.branch_id = :branch_id
                  AND p.is_active = 1
                  AND p.deleted_at IS NULL
                  AND (p.mr_number LIKE :term OR p.first_name LIKE :term OR p.last_name LIKE :term)
                ORDER BY p.first_name ASC
                LIMIT 30
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':branch_id' => $branch_id,
                ':term' => "%$term%"
            ]);
            $patients = $stmt->fetchAll(PDO::FETCH_ASSOC);
            jsonResponse(true, '', ['patients' => $patients]);
        }

        // ---------- 2.3 CREATE REFERRAL (patient_referrals) ----------
        if ($action === 'create_referral') {
            $patient_id = (int)($_POST['patient_id'] ?? 0);
            $target_doctor_id = (int)($_POST['target_doctor_id'] ?? 0);
            $referral_reason = trim($_POST['referral_reason'] ?? '');
            $visit_id = (int)($_POST['visit_id'] ?? 0);

            if ($patient_id <= 0 || $target_doctor_id <= 0 || empty($referral_reason)) {
                jsonResponse(false, 'Please fill all required fields');
            }

            try {
                $pdo->beginTransaction();
                // Verify patient belongs to branch
                $check = $pdo->prepare("SELECT id FROM patients WHERE id = ? AND branch_id = ? AND deleted_at IS NULL");
                $check->execute([$patient_id, $branch_id]);
                if (!$check->fetch()) {
                    $pdo->rollBack();
                    jsonResponse(false, 'Invalid patient');
                }
                // Verify target doctor exists
                $check_doc = $pdo->prepare("SELECT id FROM users WHERE id = ? AND branch_id = ? AND user_type IN ('doctor','super_admin','admin') AND is_active = 1 AND deleted_at IS NULL");
                $check_doc->execute([$target_doctor_id, $branch_id]);
                if (!$check_doc->fetch()) {
                    $pdo->rollBack();
                    jsonResponse(false, 'Target doctor not available');
                }

                $insert = $pdo->prepare("
                    INSERT INTO patient_referrals
                    (branch_id, patient_id, visit_id, referred_by_doctor_id, referred_to_doctor_id, referral_reason, referral_status, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, 'pending', NOW())
                ");
                $insert->execute([$branch_id, $patient_id, $visit_id ?: null, $user_id, $target_doctor_id, $referral_reason]);
                $pdo->commit();
                jsonResponse(true, 'Referral created successfully');
            } catch (PDOException $e) {
                $pdo->rollBack();
                jsonResponse(false, 'Database error: ' . $e->getMessage());
            }
        }

        // ---------- 2.4 FETCH INCOMING REFERRALS (for logged‑in doctor) ----------
        if ($action === 'fetch_incoming_referrals') {
            $sql = "
                SELECT 
                    pr.id AS referral_id,
                    CONCAT(p.first_name, ' ', p.last_name) AS patient_name,
                    p.mr_number,
                    CONCAT(u.first_name, ' ', u.last_name) AS referrer_doctor,
                    pr.referral_reason,
                    pr.referral_status,
                    pr.created_at
                FROM patient_referrals pr
                JOIN patients p ON pr.patient_id = p.id AND p.deleted_at IS NULL
                JOIN users u ON pr.referred_by_doctor_id = u.id AND u.deleted_at IS NULL
                WHERE pr.branch_id = :branch_id
                  AND pr.referred_to_doctor_id = :doctor_id
                  AND pr.deleted_at IS NULL
                ORDER BY pr.created_at DESC
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':branch_id' => $branch_id, ':doctor_id' => $user_id]);
            $referrals = $stmt->fetchAll(PDO::FETCH_ASSOC);
            jsonResponse(true, '', ['referrals' => $referrals]);
        }

        // ---------- 2.5 UPDATE REFERRAL STATUS (accept / complete) ----------
        if ($action === 'update_referral_status') {
            $referral_id = (int)($_POST['referral_id'] ?? 0);
            $new_status = $_POST['status'] ?? '';
            if (!in_array($new_status, ['accepted', 'completed'])) {
                jsonResponse(false, 'Invalid status');
            }
            try {
                $pdo->beginTransaction();
                $check = $pdo->prepare("
                    SELECT id FROM patient_referrals
                    WHERE id = ? AND branch_id = ? AND referred_to_doctor_id = ? AND referral_status = 'pending' AND deleted_at IS NULL
                ");
                $check->execute([$referral_id, $branch_id, $user_id]);
                if (!$check->fetch()) {
                    $pdo->rollBack();
                    jsonResponse(false, 'Referral not found or already processed');
                }
                $update = $pdo->prepare("UPDATE patient_referrals SET referral_status = ?, updated_at = NOW() WHERE id = ?");
                $update->execute([$new_status, $referral_id]);
                $pdo->commit();
                jsonResponse(true, 'Referral status updated');
            } catch (PDOException $e) {
                $pdo->rollBack();
                jsonResponse(false, 'Database error');
            }
        }

        // ---------- 2.6 REQUEST URGENT CONSULTATION (doctor_consultations) ----------
        if ($action === 'request_consultation') {
            $patient_id = (int)($_POST['patient_id'] ?? 0);
            $assigned_doctor_id = (int)($_POST['assigned_doctor_id'] ?? 0);
            $urgency_level = $_POST['urgency_level'] ?? 'routine';
            $clinical_question = trim($_POST['clinical_question'] ?? '');
            if (!in_array($urgency_level, ['routine', 'urgent', 'stat'])) {
                $urgency_level = 'routine';
            }
            if ($patient_id <= 0 || $assigned_doctor_id <= 0 || empty($clinical_question)) {
                jsonResponse(false, 'Please fill all required fields');
            }

            try {
                $pdo->beginTransaction();
                // Verify patient belongs to branch
                $check_pat = $pdo->prepare("SELECT id FROM patients WHERE id = ? AND branch_id = ? AND deleted_at IS NULL");
                $check_pat->execute([$patient_id, $branch_id]);
                if (!$check_pat->fetch()) {
                    $pdo->rollBack();
                    jsonResponse(false, 'Invalid patient');
                }
                // Verify target doctor exists
                $check_doc = $pdo->prepare("SELECT id FROM users WHERE id = ? AND branch_id = ? AND user_type IN ('doctor','super_admin','admin') AND is_active = 1 AND deleted_at IS NULL");
                $check_doc->execute([$assigned_doctor_id, $branch_id]);
                if (!$check_doc->fetch()) {
                    $pdo->rollBack();
                    jsonResponse(false, 'Target doctor not found');
                }

                $insert = $pdo->prepare("
                    INSERT INTO doctor_consultations
                    (branch_id, requested_by_user_id, assigned_doctor_id, patient_id, urgency_level, clinical_question_summary, status, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, 'open', NOW())
                ");
                $insert->execute([$branch_id, $user_id, $assigned_doctor_id, $patient_id, $urgency_level, $clinical_question]);
                $pdo->commit();
                jsonResponse(true, 'Consultation request sent');
            } catch (PDOException $e) {
                $pdo->rollBack();
                jsonResponse(false, 'Database error: ' . $e->getMessage());
            }
        }

        // ---------- 2.7 FETCH CONSULTATION REQUESTS (incoming) ----------
        if ($action === 'fetch_consultation_requests') {
            $sql = "
                SELECT 
                    dc.id AS request_id,
                    CONCAT(p.first_name, ' ', p.last_name) AS patient_name,
                    p.mr_number,
                    CONCAT(u.first_name, ' ', u.last_name) AS requester_doctor,
                    dc.urgency_level,
                    dc.clinical_question_summary,
                    dc.status,
                    dc.created_at
                FROM doctor_consultations dc
                JOIN patients p ON dc.patient_id = p.id AND p.deleted_at IS NULL
                JOIN users u ON dc.requested_by_user_id = u.id AND u.deleted_at IS NULL
                WHERE dc.branch_id = :branch_id
                  AND dc.assigned_doctor_id = :doctor_id
                  AND dc.status = 'open'
                  AND dc.deleted_at IS NULL
                ORDER BY FIELD(dc.urgency_level, 'stat', 'urgent', 'routine'), dc.created_at ASC
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':branch_id' => $branch_id, ':doctor_id' => $user_id]);
            $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
            jsonResponse(true, '', ['requests' => $requests]);
        }

        // ---------- 2.8 ACKNOWLEDGE CONSULTATION REQUEST ----------
        if ($action === 'acknowledge_consultation') {
            $request_id = (int)($_POST['request_id'] ?? 0);
            if ($request_id <= 0) {
                jsonResponse(false, 'Invalid request ID');
            }
            try {
                $pdo->beginTransaction();
                $check = $pdo->prepare("
                    SELECT id FROM doctor_consultations
                    WHERE id = ? AND assigned_doctor_id = ? AND status = 'open' AND deleted_at IS NULL
                ");
                $check->execute([$request_id, $user_id]);
                if (!$check->fetch()) {
                    $pdo->rollBack();
                    jsonResponse(false, 'Request not found or already acknowledged');
                }
                $update = $pdo->prepare("UPDATE doctor_consultations SET status = 'acknowledged', acknowledged_at = NOW() WHERE id = ?");
                $update->execute([$request_id]);
                $pdo->commit();
                jsonResponse(true, 'Consultation request acknowledged');
            } catch (PDOException $e) {
                $pdo->rollBack();
                jsonResponse(false, 'Database error');
            }
        }

        jsonResponse(false, 'Unsupported action');
    } catch (PDOException $e) {
        jsonResponse(false, 'Database error: ' . $e->getMessage());
    } catch (Exception $e) {
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
    <title>Clinical Communication - Referrals & Consultations | HMS ERP</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="<?php echo $base_path; ?>/modules/doctor/assets/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <style>
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

        .tabs-container {
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
        }
        .nav-tabs .nav-link {
            border: none;
            padding: 14px 24px;
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

        .referral-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
        }
        @media (max-width: 992px) {
            .referral-grid { grid-template-columns: 1fr; }
        }

        .card {
            background: white;
            border-radius: 16px;
            border: 1px solid var(--border-gray);
            overflow: hidden;
        }
        .card-header {
            padding: 16px 20px;
            background: #f8fafc;
            border-bottom: 1px solid var(--border-gray);
            font-weight: 700;
        }
        .card-body { padding: 20px; }
        .form-group { margin-bottom: 20px; }
        .form-group label { font-weight: 600; font-size: 13px; display: block; margin-bottom: 8px; color: var(--dark); }
        .form-group textarea, .form-group input, .form-group select {
            width: 100%; padding: 10px 14px; border: 1px solid var(--border-gray); border-radius: 12px; font-size: 14px;
        }
        .btn {
            padding: 10px 24px; border-radius: 12px; font-weight: 600; border: none; cursor: pointer;
        }
        .btn-primary { background: var(--primary); color: white; }
        .btn-primary:hover { background: var(--primary-dark); }
        .btn-outline { background: white; border: 1px solid var(--border-gray); color: var(--dark); }
        .btn-success { background: var(--success); color: white; }
        .btn-sm { padding: 6px 12px; font-size: 12px; }

        .data-table {
            width: 100%;
            border-collapse: collapse;
        }
        .data-table th, .data-table td {
            padding: 12px 12px;
            text-align: left;
            border-bottom: 1px solid var(--border-gray);
            font-size: 13px;
        }
        .data-table th {
            background: #f8fafc;
            font-weight: 600;
        }

        .consultation-request-item {
            border: 1px solid var(--border-gray);
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 16px;
            transition: 0.2s;
        }
        .urgent-item {
            border-left: 4px solid var(--warning);
            background: #fffbeb;
        }
        .stat-item {
            border-left: 4px solid var(--danger);
            background: #fee2e2;
            animation: pulseBg 1s ease-in-out infinite;
        }
        @keyframes pulseBg {
            0% { background-color: #fee2e2; }
            50% { background-color: #ffcccc; }
            100% { background-color: #fee2e2; }
        }
        .request-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
            flex-wrap: wrap;
        }
        .urgency-badge {
            background: var(--warning);
            color: #b45309;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
        }
        .stat-badge {
            background: var(--danger);
            color: white;
            animation: blink 0.8s infinite;
        }
        @keyframes blink {
            0%,100% { opacity: 1; }
            50% { opacity: 0.6; }
        }

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
                <div class="page-title"><i class="fas fa-comments me-2"></i> Clinical Communication Hub</div>
                <div class="page-subtitle">Internal patient referrals & inter‑doctor consultation requests</div>
            </div>

            <div class="tabs-container">
                <ul class="nav nav-tabs" id="communicationTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="referrals-tab" data-bs-toggle="tab" data-bs-target="#referrals" type="button" role="tab">🔄 Referrals (Inbound & Outbound)</button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="consultations-tab" data-bs-toggle="tab" data-bs-target="#consultations" type="button" role="tab">📢 Urgent Consultations</button>
                    </li>
                </ul>
                <div class="tab-content" id="communicationTabContent">
                    <!-- Referrals Tab -->
                    <div class="tab-pane fade show active" id="referrals" role="tabpanel">
                        <div class="referral-grid">
                            <!-- Left: New Referral Form -->
                            <div class="card">
                                <div class="card-header"><i class="fas fa-paper-plane me-2"></i> New Internal Referral</div>
                                <div class="card-body">
                                    <form id="newReferralForm">
                                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                        <div class="form-group">
                                            <label>Patient <span class="text-danger">*</span></label>
                                            <select id="referralPatient" name="patient_id" class="form-control patient-select" style="width: 100%;" required>
                                                <option value="">Search patient...</option>
                                            </select>
                                        </div>
                                        <div class="form-group">
                                            <label>Referring To (Doctor) <span class="text-danger">*</span></label>
                                            <select id="referralDoctor" name="target_doctor_id" class="form-control doctor-select" style="width: 100%;" required>
                                                <option value="">Select doctor...</option>
                                            </select>
                                        </div>
                                        <div class="form-group">
                                            <label>Reason for Referral <span class="text-danger">*</span></label>
                                            <textarea name="referral_reason" rows="4" class="form-control" required placeholder="Clinical justification, required specialty, urgency..."></textarea>
                                        </div>
                                        <div class="form-group">
                                            <label>Visit ID (optional)</label>
                                            <input type="number" name="visit_id" class="form-control" placeholder="Associated visit ID">
                                        </div>
                                        <div class="action-buttons">
                                            <button type="submit" class="btn btn-primary"><i class="fas fa-share"></i> Create Referral</button>
                                        </div>
                                    </form>
                                </div>
                            </div>

                            <!-- Right: Incoming Referrals List -->
                            <div class="card">
                                <div class="card-header"><i class="fas fa-inbox me-2"></i> Incoming Referrals</div>
                                <div class="card-body" id="incomingReferralsContainer">
                                    <div class="text-center text-muted py-4">Loading referrals...</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Urgent Consultations Tab -->
                    <div class="tab-pane fade" id="consultations" role="tabpanel">
                        <div class="row">
                            <div class="col-lg-5">
                                <div class="card mb-4">
                                    <div class="card-header"><i class="fas fa-ambulance me-2"></i> Request Consultation</div>
                                    <div class="card-body">
                                        <form id="consultationRequestForm">
                                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                            <div class="form-group">
                                                <label>Patient <span class="text-danger">*</span></label>
                                                <select id="consultPatient" name="patient_id" class="form-control patient-select" style="width: 100%;" required>
                                                    <option value="">Search patient...</option>
                                                </select>
                                            </div>
                                            <div class="form-group">
                                                <label>Request To (Doctor) <span class="text-danger">*</span></label>
                                                <select id="consultDoctor" name="assigned_doctor_id" class="form-control doctor-select" style="width: 100%;" required>
                                                    <option value="">Select doctor...</option>
                                                </select>
                                            </div>
                                            <div class="form-group">
                                                <label>Urgency Level <span class="text-danger">*</span></label>
                                                <select name="urgency_level" class="form-control" required>
                                                    <option value="routine">Routine</option>
                                                    <option value="urgent">Urgent</option>
                                                    <option value="stat">STAT (Critical)</option>
                                                </select>
                                            </div>
                                            <div class="form-group">
                                                <label>Clinical Question / Summary <span class="text-danger">*</span></label>
                                                <textarea name="clinical_question" rows="4" class="form-control" required placeholder="Brief summary of case, specific question or needed support..."></textarea>
                                            </div>
                                            <div class="action-buttons">
                                                <button type="submit" class="btn btn-primary"><i class="fas fa-bell"></i> Send Request</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                            <div class="col-lg-7">
                                <div class="card">
                                    <div class="card-header"><i class="fas fa-tasks me-2"></i> Incoming Consultation Requests</div>
                                    <div class="card-body" id="consultationRequestsContainer">
                                        <div class="text-center text-muted py-4">Loading requests...</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php include($base_path . '/modules/doctor/assets/inc/footer.php'); ?>
</div>

<div id="loadingOverlay" class="loading-overlay"><div class="spinner"></div></div>

<script src="assets/js/vendor.min.js"></script>
<script src="assets/js/bootstrap.bundle.min.js"></script>
<script>
const doctorId = <?php echo $user_id; ?>;
const branchId = <?php echo $branch_id; ?>;
const csrfToken = <?php echo json_encode($csrf_token); ?>;

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

// Load doctors list for selects
function loadDoctors(selectorId) {
    $.post(window.location.pathname + '?ajax=communication_action', {
        action: 'load_doctors',
        csrf_token: csrfToken,
        branch_id: branchId
    }, function(res) {
        if (res.success && res.doctors) {
            const select = $(selectorId);
            select.empty().append('<option value="">Select doctor...</option>');
            res.doctors.forEach(doc => {
                select.append(`<option value="${doc.id}">${escapeHtml(doc.full_name)}</option>`);
            });
        }
    }).fail(() => showToast('Failed to load doctors', 'error'));
}

// Patient search with Select2 (AJAX)
function initPatientSelect(selectorId) {
    $(selectorId).select2({
        placeholder: 'Search patient by name or MRN...',
        allowClear: true,
        minimumInputLength: 2,
        ajax: {
            url: window.location.pathname + '?ajax=communication_action',
            dataType: 'json',
            delay: 300,
            data: function(params) {
                return {
                    action: 'search_patients',
                    term: params.term,
                    csrf_token: csrfToken,
                    branch_id: branchId
                };
            },
            processResults: function(data) {
                if (data.success && data.patients) {
                    return {
                        results: data.patients.map(item => ({ id: item.id, text: item.text }))
                    };
                }
                return { results: [] };
            },
            cache: true
        }
    });
}

// Load incoming referrals
function loadIncomingReferrals() {
    $.post(window.location.pathname + '?ajax=communication_action', {
        action: 'fetch_incoming_referrals',
        csrf_token: csrfToken,
        branch_id: branchId
    }, function(res) {
        if (res.success && res.referrals) {
            const container = $('#incomingReferralsContainer');
            if (res.referrals.length === 0) {
                container.html('<div class="empty-state"><i class="fas fa-inbox fa-2x mb-2"></i><p>No pending referrals</p></div>');
                return;
            }
            let html = '<table class="data-table"><thead><tr><th>Patient</th><th>MRN</th><th>From</th><th>Reason</th><th>Date</th><th>Action</th></tr></thead><tbody>';
            res.referrals.forEach(r => {
                html += `<tr>
                            <td><strong>${escapeHtml(r.patient_name)}</strong></td>
                            <td>${escapeHtml(r.mr_number)}</td>
                            <td>${escapeHtml(r.referrer_doctor)}</td>
                            <td>${escapeHtml(r.referral_reason.substring(0,60))}${r.referral_reason.length>60?'...':''}</td>
                            <td>${new Date(r.created_at).toLocaleString()}</td>
                            <td>
                                <button class="btn btn-success btn-sm" onclick="updateReferralStatus(${r.referral_id}, 'accepted')"><i class="fas fa-check"></i> Accept</button>
                                <button class="btn btn-outline btn-sm ms-1" onclick="updateReferralStatus(${r.referral_id}, 'completed')"><i class="fas fa-flag-checkered"></i> Complete</button>
                             </td>
                        <tr>`;
            });
            html += '</tbody></table>';
            container.html(html);
        } else {
            $('#incomingReferralsContainer').html('<div class="empty-state">Error loading referrals</div>');
        }
    }).fail(() => showToast('Failed to load referrals', 'error'));
}

function updateReferralStatus(referralId, status) {
    showLoading(true);
    $.post(window.location.pathname + '?ajax=communication_action', {
        action: 'update_referral_status',
        referral_id: referralId,
        status: status,
        csrf_token: csrfToken,
        branch_id: branchId
    }, function(res) {
        showLoading(false);
        if (res.success) {
            showToast(res.message);
            loadIncomingReferrals();
        } else {
            showToast(res.message || 'Failed', 'error');
        }
    }).fail(() => { showLoading(false); showToast('Network error', 'error'); });
}

// Load consultation requests
function loadConsultationRequests() {
    $.post(window.location.pathname + '?ajax=communication_action', {
        action: 'fetch_consultation_requests',
        csrf_token: csrfToken,
        branch_id: branchId
    }, function(res) {
        if (res.success && res.requests) {
            const container = $('#consultationRequestsContainer');
            if (res.requests.length === 0) {
                container.html('<div class="empty-state"><i class="fas fa-bell-slash fa-2x mb-2"></i><p>No pending consultation requests</p></div>');
                return;
            }
            let html = '';
            res.requests.forEach(req => {
                let urgencyClass = '';
                let urgencyIcon = '';
                if (req.urgency_level === 'stat') {
                    urgencyClass = 'stat-item';
                    urgencyIcon = '<i class="fas fa-skull-crosswalk fa-beat-fw text-danger"></i> ';
                } else if (req.urgency_level === 'urgent') {
                    urgencyClass = 'urgent-item';
                    urgencyIcon = '<i class="fas fa-exclamation-triangle fa-beat-fw text-warning"></i> ';
                } else {
                    urgencyIcon = '<i class="fas fa-comment-medical"></i> ';
                }
                html += `
                    <div class="consultation-request-item ${urgencyClass}">
                        <div class="request-header">
                            <div><strong>${urgencyIcon} ${escapeHtml(req.patient_name)}</strong> (${escapeHtml(req.mr_number)})</div>
                            <div><span class="urgency-badge ${req.urgency_level === 'stat' ? 'stat-badge' : ''}">${req.urgency_level.toUpperCase()}</span></div>
                        </div>
                        <div class="small text-muted mb-2">Requested by: ${escapeHtml(req.requester_doctor)} on ${new Date(req.created_at).toLocaleString()}</div>
                        <div class="mb-2"><strong>Question:</strong> ${escapeHtml(req.clinical_question_summary)}</div>
                        <button class="btn btn-primary btn-sm" onclick="acknowledgeConsultation(${req.request_id})"><i class="fas fa-check-double"></i> Acknowledge & Join Case</button>
                    </div>
                `;
            });
            container.html(html);
        } else {
            $('#consultationRequestsContainer').html('<div class="empty-state">Error loading requests</div>');
        }
    }).fail(() => showToast('Failed to load consultation requests', 'error'));
}

function acknowledgeConsultation(requestId) {
    showLoading(true);
    $.post(window.location.pathname + '?ajax=communication_action', {
        action: 'acknowledge_consultation',
        request_id: requestId,
        csrf_token: csrfToken,
        branch_id: branchId
    }, function(res) {
        showLoading(false);
        if (res.success) {
            showToast(res.message);
            loadConsultationRequests();
        } else {
            showToast(res.message || 'Failed', 'error');
        }
    }).fail(() => { showLoading(false); showToast('Network error', 'error'); });
}

// Form submissions
$('#newReferralForm').on('submit', function(e) {
    e.preventDefault();
    const formData = {
        action: 'create_referral',
        patient_id: $('#referralPatient').val(),
        target_doctor_id: $('#referralDoctor').val(),
        referral_reason: $('textarea[name="referral_reason"]').val(),
        visit_id: $('input[name="visit_id"]').val(),
        csrf_token: csrfToken,
        branch_id: branchId
    };
    if (!formData.patient_id || !formData.target_doctor_id || !formData.referral_reason) {
        showToast('Please fill all required fields', 'error');
        return;
    }
    showLoading(true);
    $.post(window.location.pathname + '?ajax=communication_action', formData, function(res) {
        showLoading(false);
        if (res.success) {
            showToast(res.message);
            $('#newReferralForm')[0].reset();
            $('#referralPatient').val(null).trigger('change');
            $('#referralDoctor').val(null).trigger('change');
            loadIncomingReferrals();
        } else {
            showToast(res.message || 'Failed', 'error');
        }
    }).fail(() => { showLoading(false); showToast('Network error', 'error'); });
});

$('#consultationRequestForm').on('submit', function(e) {
    e.preventDefault();
    const formData = {
        action: 'request_consultation',
        patient_id: $('#consultPatient').val(),
        assigned_doctor_id: $('#consultDoctor').val(),
        urgency_level: $('select[name="urgency_level"]').val(),
        clinical_question: $('textarea[name="clinical_question"]').val(),
        csrf_token: csrfToken,
        branch_id: branchId
    };
    if (!formData.patient_id || !formData.assigned_doctor_id || !formData.clinical_question) {
        showToast('Please fill all required fields', 'error');
        return;
    }
    showLoading(true);
    $.post(window.location.pathname + '?ajax=communication_action', formData, function(res) {
        showLoading(false);
        if (res.success) {
            showToast(res.message);
            $('#consultationRequestForm')[0].reset();
            $('#consultPatient').val(null).trigger('change');
            $('#consultDoctor').val(null).trigger('change');
            loadConsultationRequests();
        } else {
            showToast(res.message || 'Failed', 'error');
        }
    }).fail(() => { showLoading(false); showToast('Network error', 'error'); });
});

// Tab switching handlers (reload data)
$('#referrals-tab').on('click', function() {
    loadIncomingReferrals();
});
$('#consultations-tab').on('click', function() {
    loadConsultationRequests();
});

// Initialization
$(document).ready(function() {
    loadDoctors('#referralDoctor');
    loadDoctors('#consultDoctor');
    initPatientSelect('#referralPatient');
    initPatientSelect('#consultPatient');
    loadIncomingReferrals();
    loadConsultationRequests();
    // Poll every 30 seconds
    setInterval(() => {
        if ($('#referrals-tab').parent().hasClass('active')) {
            loadIncomingReferrals();
        } else if ($('#consultations-tab').parent().hasClass('active')) {
            loadConsultationRequests();
        }
    }, 30000);
});
</script>
</body>
</html>