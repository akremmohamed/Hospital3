<?php
/**
 * ======================================================================
 * VISITOR PASS MANAGEMENT - HMS ERP (FIXED SEARCH)
 * Strict branch isolation | Secure PDO binding | Error logging
 * ======================================================================
 */

ob_start();
session_start();

// ============================================
// ERROR REPORTING (Production)
// ============================================
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../../logs/visitor_errors.log');

$log_dir = __DIR__ . '/../../logs/';
if (!is_dir($log_dir)) mkdir($log_dir, 0755, true);

// ============================================
// AUTHENTICATION & SESSION
// ============================================
$user_id = (int)($_SESSION['user_id'] ?? 0);
$user_branch_id = (int)($_SESSION['branch_id'] ?? 0);
$is_global_admin = ($_SESSION['user_type'] ?? '') === 'super_admin';
$user_name = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''));

if ($user_id <= 0 || $user_branch_id <= 0) {
    header('Location: ../../index.php');
    exit;
}

// ============================================
// CENTRALIZED DATABASE & HELPERS
// ============================================
require_once __DIR__ . '/../../config/db_connection.php';
require_once __DIR__ . '/../../includes/auth_helper.php';

date_default_timezone_set('Africa/Addis_Ababa');

if (!$pdo instanceof PDO) {
    die('Database connection failed. Contact administrator.');
}

try {
    $pdo->exec("SET time_zone = '+03:00'");
    $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
} catch (PDOException $e) {
    error_log('Timezone set failed: ' . $e->getMessage());
}

// ============================================
// RBAC PERMISSION CHECK
// ============================================
$can_manage_visitors = $is_global_admin || user_has_permission($pdo, 'visitor_pass_manage_access', $user_id);
if (!$can_manage_visitors) {
    header('Location: dashboard.php?error=unauthorized');
    exit;
}

// ============================================
// CSRF TOKEN
// ============================================
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// ============================================
// HELPER FUNCTIONS
// ============================================
function escapeHtml($str) {
    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}

function sanitizeInput($input) {
    if (is_array($input)) return array_map('sanitizeInput', $input);
    return trim(htmlspecialchars((string)$input, ENT_QUOTES, 'UTF-8'));
}

function validateCsrf($submitted, $session) {
    return !empty($submitted) && !empty($session) && hash_equals($session, $submitted);
}

function jsonResponse($success, $message, $data = null, $code = 200) {
    if (ob_get_length()) ob_clean();
    http_response_code($code);
    header('Content-Type: application/json');
    header('X-Content-Type-Options: nosniff');
    $response = ['success' => $success, 'message' => $message];
    if ($data !== null) $response = array_merge($response, $data);
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

function logAudit($pdo, $userId, $action, $targetTable, $recordId, $details, $branchId) {
    try {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
        $stmt = $pdo->prepare("
            INSERT INTO audit_logs 
            (branch_id, user_id, action_type, target_table, record_id, new_values, ip_address, user_agent, created_at)
            VALUES (:branch, :user, :action, :table, :record, :details, :ip, :ua, NOW())
        ");
        $stmt->execute([
            ':branch' => $branchId,
            ':user' => $userId,
            ':action' => $action,
            ':table' => $targetTable,
            ':record' => $recordId,
            ':details' => json_encode($details),
            ':ip' => $ip,
            ':ua' => $ua
        ]);
    } catch (Exception $e) {
        error_log('Audit log failed: ' . $e->getMessage());
    }
}

// ============================================
// CREATE / ENSURE visitor_passes TABLE
// ============================================
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `visitor_passes` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `branch_id` int(10) UNSIGNED NOT NULL,
            `admission_id` int(10) UNSIGNED NOT NULL,
            `visitor_name` varchar(100) NOT NULL,
            `visitor_phone` varchar(20) DEFAULT NULL,
            `relationship_to_patient` varchar(50) NOT NULL,
            `pass_number` varchar(50) NOT NULL,
            `valid_from` timestamp NOT NULL DEFAULT current_timestamp(),
            `valid_to` timestamp NOT NULL,
            `issued_by_user_id` int(10) UNSIGNED NOT NULL,
            `issued_at` timestamp NOT NULL DEFAULT current_timestamp(),
            `checked_in_at` timestamp NULL DEFAULT NULL,
            `checked_out_at` timestamp NULL DEFAULT NULL,
            `status` enum('active','checked_out','revoked','expired') NOT NULL DEFAULT 'active',
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            `deleted_at` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_pass_number` (`pass_number`),
            KEY `idx_admission_id` (`admission_id`),
            KEY `idx_branch_id` (`branch_id`),
            KEY `idx_status` (`status`),
            KEY `idx_valid_to` (`valid_to`),
            KEY `idx_visitor_phone` (`visitor_phone`),
            KEY `idx_pass_number_branch` (`pass_number`, `branch_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
} catch (PDOException $e) {
    error_log('Table creation failed: ' . $e->getMessage());
}

// ============================================
// BRANCH & HOSPITAL INFO
// ============================================
$branch_name = 'Main Branch';
try {
    $stmt = $pdo->prepare("SELECT name FROM branches WHERE id = ? AND deleted_at IS NULL");
    $stmt->execute([$user_branch_id]);
    if ($row = $stmt->fetch()) $branch_name = $row['name'];
} catch (PDOException $e) { error_log($e->getMessage()); }

$hospital_name = 'HMS Medical Center';
try {
    $stmt = $pdo->query("SELECT setting_value FROM hospital_settings WHERE setting_key = 'hospital_name' LIMIT 1");
    if ($stmt && $row = $stmt->fetch()) $hospital_name = $row['setting_value'];
} catch (PDOException $e) { error_log($e->getMessage()); }

// ============================================
// AJAX: SEARCH ADMITTED PATIENTS (FIXED)
// ============================================
if (isset($_GET['action']) && $_GET['action'] === 'search_patients') {
    $search = sanitizeInput($_GET['q'] ?? '');
    if (strlen($search) < 2) {
        jsonResponse(true, '', ['data' => []]);
    }

    try {
        $like = "%$search%";
        $sql = "
            SELECT 
                p.id, p.mr_number, p.first_name, p.middle_name, p.last_name,
                TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) as age, p.gender,
                a.id as admission_id, a.admission_number,
                b.bed_number as bed_code, w.name as ward_name,
                CASE WHEN b.bed_type IN ('icu', 'isolation') THEN 1 ELSE 0 END as restricted
            FROM patients p
            INNER JOIN admissions a ON a.patient_id = p.id 
                AND a.status = 'admitted' AND a.deleted_at IS NULL AND a.branch_id = :branch_id
            LEFT JOIN beds b ON a.bed_id = b.id AND b.deleted_at IS NULL
            LEFT JOIN wards w ON b.ward_id = w.id AND w.deleted_at IS NULL
            WHERE p.deleted_at IS NULL AND p.is_active = 1
                AND p.branch_id = :branch_id
                AND (
                    p.mr_number LIKE :like1 OR 
                    p.first_name LIKE :like2 OR 
                    p.last_name LIKE :like3 OR 
                    p.phone LIKE :like4 OR
                    CONCAT(p.first_name, ' ', COALESCE(p.middle_name,''), ' ', p.last_name) LIKE :like5
                )
            ORDER BY a.admitted_at DESC
            LIMIT 20
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':branch_id' => $user_branch_id,
            ':like1' => $like,
            ':like2' => $like,
            ':like3' => $like,
            ':like4' => $like,
            ':like5' => $like
        ]);
        $results = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $results[] = [
                'id' => $row['id'],
                'mrn' => $row['mr_number'],
                'name' => trim($row['first_name'] . ' ' . ($row['middle_name'] ?? '') . ' ' . $row['last_name']),
                'age' => $row['age'],
                'gender' => $row['gender'],
                'admission_id' => $row['admission_id'],
                'admission_no' => $row['admission_number'],
                'bed' => $row['bed_code'] ?? 'Not Assigned',
                'ward' => $row['ward_name'] ?? 'General Ward',
                'restricted' => (bool)($row['restricted'] ?? 0)
            ];
        }
        jsonResponse(true, '', ['data' => $results]);
    } catch (PDOException $e) {
        error_log('Patient search error: ' . $e->getMessage());
        jsonResponse(false, 'Unable to search patients. Please try again.');
    }
}

// ============================================
// AJAX: GET ACTIVE VISITORS (branch isolated)
// ============================================
if (isset($_GET['action']) && $_GET['action'] === 'get_active') {
    try {
        $sql = "
            SELECT vp.*,
                CONCAT(p.first_name, ' ', COALESCE(p.middle_name,''), ' ', p.last_name) as patient_name,
                p.mr_number, a.admission_number,
                b.bed_number as bed_code, w.name as ward_name,
                TIMESTAMPDIFF(MINUTE, NOW(), vp.valid_to) as mins_left
            FROM visitor_passes vp
            INNER JOIN admissions a ON vp.admission_id = a.id AND a.deleted_at IS NULL AND a.branch_id = :branch_id
            INNER JOIN patients p ON a.patient_id = p.id AND p.deleted_at IS NULL
            LEFT JOIN beds b ON a.bed_id = b.id AND b.deleted_at IS NULL
            LEFT JOIN wards w ON b.ward_id = w.id AND w.deleted_at IS NULL
            WHERE vp.status = 'active' AND vp.deleted_at IS NULL
            ORDER BY vp.issued_at DESC
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':branch_id' => $user_branch_id]);
        $visitors = $stmt->fetchAll(PDO::FETCH_ASSOC);
        jsonResponse(true, '', ['data' => $visitors]);
    } catch (PDOException $e) {
        error_log('Get active visitors error: ' . $e->getMessage());
        jsonResponse(false, 'Unable to load active visitors');
    }
}

// ============================================
// AJAX: CREATE VISITOR PASS (with audit)
// ============================================
if (isset($_POST['action']) && $_POST['action'] === 'create_pass') {
    if (!validateCsrf($_POST['csrf_token'] ?? '', $csrf_token)) {
        jsonResponse(false, 'Security token invalid');
    }

    $visitor_name = sanitizeInput($_POST['visitor_name'] ?? '');
    $visitor_phone = sanitizeInput($_POST['visitor_phone'] ?? '');
    $relationship = sanitizeInput($_POST['relationship'] ?? '');
    $admission_id = (int)($_POST['admission_id'] ?? 0);
    $hours = max(1, min(24, (int)($_POST['hours'] ?? 2)));

    if (empty($visitor_name) || empty($relationship) || $admission_id <= 0) {
        jsonResponse(false, 'Missing required fields');
    }

    try {
        $pdo->beginTransaction();

        // Verify admission belongs to this branch and patient not in restricted zone
        $check = $pdo->prepare("
            SELECT a.id, b.bed_type, a.patient_id
            FROM admissions a
            LEFT JOIN beds b ON a.bed_id = b.id AND b.deleted_at IS NULL
            WHERE a.id = :admission_id AND a.branch_id = :branch_id AND a.status = 'admitted' AND a.deleted_at IS NULL
        ");
        $check->execute([':admission_id' => $admission_id, ':branch_id' => $user_branch_id]);
        $admission = $check->fetch(PDO::FETCH_ASSOC);
        if (!$admission) throw new Exception('Invalid admission record');

        if (in_array($admission['bed_type'], ['icu', 'isolation'])) {
            throw new Exception('Visitors not allowed in ' . strtoupper($admission['bed_type']) . ' zone');
        }

        // Limit active visitors per patient
        $count = $pdo->prepare("
            SELECT COUNT(*) FROM visitor_passes 
            WHERE admission_id = :admission_id AND status = 'active' AND deleted_at IS NULL
        ");
        $count->execute([':admission_id' => $admission_id]);
        if ((int)$count->fetchColumn() >= 5) {
            throw new Exception('Maximum 5 active visitors allowed per patient');
        }

        // Generate pass number with branch code
        $branch_code = str_pad($user_branch_id, 2, '0', STR_PAD_LEFT);
        $date_code = date('Ymd');
        $rand = strtoupper(substr(uniqid(), -4));
        $pass_number = "VP-B{$branch_code}-{$date_code}-{$rand}";

        $valid_from = date('Y-m-d H:i:s');
        $valid_to = date('Y-m-d H:i:s', strtotime("+$hours hours"));

        $insert = $pdo->prepare("
            INSERT INTO visitor_passes 
            (branch_id, admission_id, visitor_name, visitor_phone, relationship_to_patient,
             pass_number, valid_from, valid_to, issued_by_user_id, issued_at, status)
            VALUES (:branch, :admission, :name, :phone, :rel, :pass, :from, :to, :user, NOW(), 'active')
        ");
        $insert->execute([
            ':branch' => $user_branch_id,
            ':admission' => $admission_id,
            ':name' => $visitor_name,
            ':phone' => $visitor_phone,
            ':rel' => $relationship,
            ':pass' => $pass_number,
            ':from' => $valid_from,
            ':to' => $valid_to,
            ':user' => $user_id
        ]);
        $pass_id = (int)$pdo->lastInsertId();

        logAudit($pdo, $user_id, 'CREATE', 'visitor_passes', $pass_id, [
            'pass_number' => $pass_number,
            'visitor_name' => $visitor_name,
            'patient_id' => $admission['patient_id'],
            'hours' => $hours
        ], $user_branch_id);

        $pdo->commit();
        jsonResponse(true, 'Visitor pass created', [
            'pass_id' => $pass_id,
            'pass_number' => $pass_number,
            'expiry' => $valid_to
        ]);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Create pass error: ' . $e->getMessage());
        jsonResponse(false, $e->getMessage());
    }
}

// ============================================
// AJAX: REVOKE VISITOR PASS (with audit)
// ============================================
if (isset($_POST['action']) && $_POST['action'] === 'revoke_pass') {
    if (!validateCsrf($_POST['csrf_token'] ?? '', $csrf_token)) {
        jsonResponse(false, 'Security token invalid');
    }

    $pass_id = (int)($_POST['pass_id'] ?? 0);
    if ($pass_id <= 0) jsonResponse(false, 'Invalid pass ID');

    try {
        $update = $pdo->prepare("
            UPDATE visitor_passes 
            SET status = 'revoked', deleted_at = NOW()
            WHERE id = :id AND branch_id = :branch_id AND status = 'active' AND deleted_at IS NULL
        ");
        $update->execute([':id' => $pass_id, ':branch_id' => $user_branch_id]);

        if ($update->rowCount() > 0) {
            logAudit($pdo, $user_id, 'REVOKE', 'visitor_passes', $pass_id, ['action' => 'revoked'], $user_branch_id);
            jsonResponse(true, 'Pass revoked successfully');
        } else {
            jsonResponse(false, 'Pass not found or already revoked');
        }
    } catch (PDOException $e) {
        error_log('Revoke pass error: ' . $e->getMessage());
        jsonResponse(false, 'Database error');
    }
}

// ============================================
// AJAX: GET PASS DATA FOR PRINTING
// ============================================
if (isset($_GET['action']) && $_GET['action'] === 'get_pass_data') {
    $pass_id = (int)($_GET['pass_id'] ?? 0);
    if ($pass_id <= 0) jsonResponse(false, 'Invalid pass ID');

    try {
        $sql = "
            SELECT vp.*,
                CONCAT(p.first_name, ' ', COALESCE(p.middle_name,''), ' ', p.last_name) as patient_name,
                p.mr_number, a.admission_number,
                b.bed_number as bed_code, w.name as ward_name,
                CONCAT(u.first_name, ' ', COALESCE(u.middle_name,''), ' ', u.last_name) as issued_by_name
            FROM visitor_passes vp
            INNER JOIN admissions a ON vp.admission_id = a.id AND a.branch_id = :branch_id AND a.deleted_at IS NULL
            INNER JOIN patients p ON a.patient_id = p.id AND p.deleted_at IS NULL
            LEFT JOIN beds b ON a.bed_id = b.id AND b.deleted_at IS NULL
            LEFT JOIN wards w ON b.ward_id = w.id AND w.deleted_at IS NULL
            LEFT JOIN users u ON vp.issued_by_user_id = u.id
            WHERE vp.id = :id AND vp.deleted_at IS NULL
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':id' => $pass_id, ':branch_id' => $user_branch_id]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($data) jsonResponse(true, '', ['data' => $data]);
        else jsonResponse(false, 'Pass not found');
    } catch (PDOException $e) {
        error_log('Get pass data error: ' . $e->getMessage());
        jsonResponse(false, 'Unable to load pass data');
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include('assets/inc/head.php'); ?>
    <meta charset="UTF-8">
    <title>Visitor Pass Management - HMS ERP</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        /* (CSS remains unchanged – keep your existing styles) */
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: #f0f2f5; }
        .content-page { margin-left: 260px; margin-top: 70px; padding: 25px 30px; transition: all 0.3s; }
        @media (max-width:768px){ .content-page { margin-left:0; padding:15px; } }
        .page-header { background: linear-gradient(135deg, #1a237e, #283593); border-radius: 20px; padding: 25px 30px; color: white; margin-bottom: 25px; }
        .card-custom { background: white; border-radius: 20px; border: none; box-shadow: 0 2px 10px rgba(0,0,0,0.05); overflow: hidden; margin-bottom: 25px; }
        .card-header-custom { padding: 18px 24px; background: #fafbfc; border-bottom: 1px solid #eef2f6; font-weight: 700; display: flex; justify-content: space-between; align-items: center; }
        .two-columns { display: grid; grid-template-columns: 1fr 1fr; gap: 25px; }
        @media (max-width:992px){ .two-columns { grid-template-columns: 1fr; } }
        .search-box { background: #f8fafc; border-radius: 16px; padding: 15px; margin-bottom: 20px; border: 1px solid #e2e8f0; }
        .search-input-wrapper { position: relative; }
        .search-input-wrapper i { position: absolute; left: 15px; top: 50%; transform: translateY(-50%); color: #94a3b8; }
        #patientSearch { width: 100%; padding: 12px 15px 12px 45px; border: 1px solid #e2e8f0; border-radius: 40px; font-size: 14px; }
        #patientSearch:focus { outline: none; border-color: #1a237e; box-shadow: 0 0 0 3px rgba(26,35,126,0.1); }
        .search-results { max-height: 320px; overflow-y: auto; border: 1px solid #e2e8f0; border-radius: 12px; margin-top: 12px; display: none; background: white; }
        .result-item { padding: 12px 15px; cursor: pointer; border-bottom: 1px solid #f0f2f5; transition: background 0.2s; }
        .result-item:hover { background: #f8fafc; }
        .selected-patient { background: linear-gradient(135deg, #1a237e, #283593); border-radius: 16px; padding: 18px; color: white; margin-bottom: 20px; display: none; }
        .duration-group { display: flex; gap: 10px; flex-wrap: wrap; }
        .duration-btn { flex: 1; text-align: center; padding: 10px; border: 1px solid #e2e8f0; border-radius: 10px; cursor: pointer; background: white; transition: all 0.2s; }
        .duration-btn.active { background: #1a237e; color: white; border-color: #1a237e; }
        .btn-submit { background: linear-gradient(135deg, #1a237e, #283593); color: white; border: none; padding: 12px 20px; border-radius: 12px; font-weight: 600; width: 100%; }
        .btn-submit:disabled { opacity: 0.6; cursor: not-allowed; }
        .btn-revoke { background: #ef4444; color: white; border: none; padding: 5px 12px; border-radius: 8px; font-size: 12px; cursor: pointer; }
        .restricted-warning { background: #fee2e2; border-left: 3px solid #ef4444; padding: 12px; border-radius: 10px; font-size: 13px; margin-top: 15px; display: none; }
        .badge-expiring { background: #fef3c7; color: #d97706; padding: 3px 8px; border-radius: 12px; font-size: 11px; }
        .loading-overlay { position: fixed; top:0; left:0; width:100%; height:100%; background: rgba(0,0,0,0.5); z-index:9999; display: none; align-items: center; justify-content: center; backdrop-filter: blur(4px); }
        .loading-overlay.active { display: flex; }
        .spinner { width: 50px; height: 50px; border: 4px solid #e5e7eb; border-top-color: #1a237e; border-radius: 50%; animation: spin 0.8s linear infinite; background: white; }
        @keyframes spin { to { transform: rotate(360deg); } }
        @media print { .no-print, #sidebar, .navbar-custom, .two-columns > div:first-child { display: none !important; } }
    </style>
</head>
<body>
<div id="wrapper">
    <?php include('assets/inc/nav.php'); ?>
    <?php include('assets/inc/sidebar.php'); ?>

    <div class="content-page">
        <div class="container-fluid">
            <div class="page-header">
                <div class="d-flex justify-content-between align-items-center">
                    <div><div class="page-title">Visitor Pass Management</div><div class="page-subtitle">Issue and manage visitor passes for admitted patients</div></div>
                    <div><span class="info-badge bg-white text-dark p-2 rounded"><i class="fas fa-building me-1"></i> <?php echo escapeHtml($branch_name); ?></span></div>
                </div>
            </div>

            <div class="two-columns">
                <!-- LEFT: Issue Pass -->
                <div>
                    <div class="card-custom">
                        <div class="card-header-custom"><span><i class="fas fa-id-card me-2"></i> Issue New Visitor Pass</span></div>
                        <div class="card-body-custom p-4">
                            <div class="search-box">
                                <div class="search-input-wrapper"><i class="fas fa-search"></i><input type="text" id="patientSearch" placeholder="Search patient by name, MRN, or phone..."></div>
                                <div id="searchResults" class="search-results"></div>
                            </div>
                            <div id="selectedPatientCard" class="selected-patient">
                                <div class="d-flex justify-content-between"><div><div class="patient-name" id="selectedPatientName">-</div><div class="small">MRN: <span id="selectedMRN">-</span> | Admission: <span id="selectedAdmissionNo">-</span></div></div><button class="btn btn-sm btn-light text-danger" onclick="clearSelectedPatient()"><i class="fas fa-times"></i></button></div>
                                <div class="mt-2"><span class="info-badge bg-white text-dark p-1 rounded"><i class="fas fa-bed"></i> Bed: <span id="selectedBed">-</span></span> <span class="info-badge bg-white text-dark p-1 rounded ms-2"><i class="fas fa-building"></i> Ward: <span id="selectedWard">-</span></span></div>
                            </div>
                            <div id="restrictedWarning" class="restricted-warning"><i class="fas fa-exclamation-triangle me-2"></i> Visitors are NOT permitted in ICU/Isolation zones.</div>
                            <form id="visitorForm">
                                <input type="hidden" id="admissionId" name="admission_id">
                                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                <input type="hidden" name="action" value="create_pass">
                                <div class="mb-3"><label class="form-label">Visitor Name <span class="text-danger">*</span></label><input type="text" id="visitorName" name="visitor_name" class="form-control" required></div>
                                <div class="mb-3"><label class="form-label">Phone Number</label><input type="tel" id="visitorPhone" name="visitor_phone" class="form-control"></div>
                                <div class="mb-3"><label class="form-label">Relationship <span class="text-danger">*</span></label><select id="relationship" name="relationship" class="form-select" required><option value="">Select</option><option>Spouse</option><option>Child</option><option>Parent</option><option>Sibling</option><option>Relative</option><option>Friend</option><option>Legal Guardian</option><option>Other</option></select></div>
                                <div class="mb-4"><label class="form-label">Pass Validity</label><div class="duration-group"><div class="duration-btn" data-hours="1">1 Hour</div><div class="duration-btn active" data-hours="2">2 Hours</div><div class="duration-btn" data-hours="4">4 Hours</div><div class="duration-btn" data-hours="8">8 Hours</div></div><input type="hidden" id="passHours" name="hours" value="2"></div>
                                <button type="submit" class="btn-submit" id="submitBtn"><i class="fas fa-print me-2"></i> Generate & Print Pass</button>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- RIGHT: Active Visitors -->
                <div>
                    <div class="card-custom">
                        <div class="card-header-custom"><span><i class="fas fa-users me-2"></i> Active Visitors</span><button class="btn btn-sm btn-outline-secondary" onclick="loadActiveVisitors()"><i class="fas fa-sync-alt"></i></button></div>
                        <div class="card-body-custom p-0"><div class="table-responsive"><table id="visitorsTable" class="data-table table table-hover" style="width:100%"><thead><tr><th>Pass No.</th><th>Visitor</th><th>Patient</th><th>Location</th><th>Valid Until</th><th></th></tr></thead><tbody><tr><td colspan="6" class="text-center py-4">Loading...</div     </tbody> </div></div>
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
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script>
let searchTimer, activeTable, selectedPatient = null, isProcessing = false;
const csrf = <?php echo json_encode($csrf_token); ?>;

function loadActiveVisitors() {
    $.getJSON(window.location.href, { action: 'get_active' }, function(res) {
        if (!activeTable) return;
        activeTable.clear();
        if (res.success && res.data.length === 0) activeTable.row.add(['<td colspan="6" class="text-center text-muted py-4">No active visitors</td>']);
        else if (res.success && res.data.length) {
            res.data.forEach(v => {
                let expiry = new Date(v.valid_to), now = new Date(), expiring = (expiry - now) < 30*60*1000;
                activeTable.row.add([
                    `<code>${escapeHtml(v.pass_number)}</code>`,
                    `<strong>${escapeHtml(v.visitor_name)}</strong><br><small>${escapeHtml(v.relationship_to_patient)}</small>`,
                    `${escapeHtml(v.patient_name)}<br><small>MRN: ${escapeHtml(v.mr_number)}</small>`,
                    `${escapeHtml(v.ward_name || 'General')}<br><small>Bed: ${escapeHtml(v.bed_code || 'N/A')}</small>`,
                    `<span class="${expiring ? 'badge-expiring' : ''}">${formatDate(v.valid_to)}<br><small>${v.mins_left > 0 ? v.mins_left + ' min left' : ''}</small></span>`,
                    `<button class="btn-revoke" onclick="revokePass(${v.id}, '${escapeHtml(v.visitor_name)}')"><i class="fas fa-ban"></i> Revoke</button>`
                ]);
            });
            activeTable.draw();
        }
    }).fail(() => console.error('Failed to load active visitors'));
}

function initTable() { activeTable = $('#visitorsTable').DataTable({ pageLength: 10, order: [[4,'asc']] }); loadActiveVisitors(); setInterval(loadActiveVisitors, 30000); }

$('#patientSearch').on('input', function() {
    clearTimeout(searchTimer); let q = $(this).val();
    if (q.length < 2) { $('#searchResults').hide(); return; }
    searchTimer = setTimeout(() => {
        $.getJSON(window.location.href, { action: 'search_patients', q: q }, function(res) {
            let html = '';
            if (res.success && res.data.length) {
                res.data.forEach(p => {
                    let restrictedBadge = p.restricted ? '<span class="text-danger ms-2"><i class="fas fa-exclamation-triangle"></i> ICU/Isolation</span>' : '';
                    html += `<div class="result-item" onclick='selectPatient(${JSON.stringify(p).replace(/'/g, "\\'")})'>
                        <div class="fw-bold">${escapeHtml(p.name)} ${restrictedBadge}</div>
                        <div class="small">MRN: ${escapeHtml(p.mrn)} | Age: ${p.age} | ${p.gender||'N/A'}</div>
                        <div class="small">Bed: ${escapeHtml(p.bed)} | Ward: ${escapeHtml(p.ward)}</div>
                    </div>`;
                });
            } else {
                html = '<div class="p-3 text-muted">No admitted patients found</div>';
            }
            $('#searchResults').html(html).show();
        }).fail(() => $('#searchResults').html('<div class="p-3 text-danger">Search error. Please try again.</div>').show());
    }, 400);
});

function selectPatient(p) {
    selectedPatient = p;
    $('#selectedPatientName').text(p.name); $('#selectedMRN').text(p.mrn); $('#selectedAdmissionNo').text(p.admission_no);
    $('#selectedBed').text(p.bed); $('#selectedWard').text(p.ward);
    $('#admissionId').val(p.admission_id);
    if (p.restricted) { $('#restrictedWarning').show(); $('#submitBtn').prop('disabled', true); }
    else { $('#restrictedWarning').hide(); $('#submitBtn').prop('disabled', false); }
    $('#selectedPatientCard').show(); $('#searchResults').hide(); $('#patientSearch').val('');
}

function clearSelectedPatient() { selectedPatient = null; $('#selectedPatientCard').hide(); $('#admissionId').val(''); $('#submitBtn').prop('disabled', false); $('#patientSearch').val('').focus(); }

$('.duration-btn').on('click', function() { $('.duration-btn').removeClass('active'); $(this).addClass('active'); $('#passHours').val($(this).data('hours')); });

$('#visitorForm').on('submit', function(e) {
    e.preventDefault(); if (isProcessing) return;
    if (!$('#admissionId').val()) return Swal.fire('Error', 'Select a patient first', 'error');
    if (!$('#visitorName').val().trim()) return Swal.fire('Error', 'Enter visitor name', 'error');
    if (!$('#relationship').val()) return Swal.fire('Error', 'Select relationship', 'error');
    isProcessing = true; $('#submitBtn').prop('disabled', true);
    Swal.fire({ title: 'Processing...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
    $.ajax({ url: window.location.href, type: 'POST', data: $(this).serialize(), dataType: 'json',
        success: function(res) {
            Swal.close(); isProcessing = false; $('#submitBtn').prop('disabled', false);
            if (res.success) {
                Swal.fire({ title: 'Success!', html: `Pass <strong>${res.pass_number}</strong><br>Valid until: ${formatDate(res.expiry)}`, icon: 'success', confirmButtonText: 'Print Pass', showCancelButton: true, cancelButtonText: 'Close' })
                    .then(result => { if (result.isConfirmed) printPass(res.pass_id); resetForm(); loadActiveVisitors(); });
            } else Swal.fire('Error', res.message, 'error');
        },
        error: () => { Swal.close(); isProcessing = false; $('#submitBtn').prop('disabled', false); Swal.fire('Error', 'Network error', 'error'); }
    });
});

function resetForm() { $('#visitorForm')[0].reset(); $('#passHours').val(2); $('.duration-btn').removeClass('active'); $('.duration-btn[data-hours="2"]').addClass('active'); clearSelectedPatient(); }

function revokePass(id, name) {
    Swal.fire({ title: 'Revoke Pass?', html: `Revoke pass for <strong>${escapeHtml(name)}</strong>?`, icon: 'warning', showCancelButton: true, confirmButtonText: 'Yes, Revoke', confirmButtonColor: '#ef4444' })
        .then(result => {
            if (!result.isConfirmed) return;
            Swal.fire({ title: 'Processing...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
            $.ajax({ url: window.location.href, type: 'POST', data: { action: 'revoke_pass', pass_id: id, csrf_token: csrf }, dataType: 'json',
                success: res => { Swal.close(); if (res.success) { Swal.fire('Revoked', res.message, 'success'); loadActiveVisitors(); } else Swal.fire('Error', res.message, 'error'); },
                error: () => { Swal.close(); Swal.fire('Error', 'Network error', 'error'); }
            });
        });
}

function printPass(passId) {
    Swal.fire({ title: 'Loading...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
    $.getJSON(window.location.href, { action: 'get_pass_data', pass_id: passId }, function(res) {
        Swal.close();
        if (!res.success) return Swal.fire('Error', 'Could not load pass data', 'error');
        let p = res.data;
        let win = window.open('', '_blank');
        win.document.write(`<!DOCTYPE html><html><head><title>Visitor Pass - ${escapeHtml(p.pass_number)}</title><style>body{font-family:'Courier New',monospace;background:#fff;display:flex;justify-content:center;align-items:center;min-height:100vh;padding:20px;}.pass{width:350px;background:#fff;border:2px solid #1a237e;border-radius:10px;padding:20px;}.header{text-align:center;border-bottom:1px dashed #ccc;padding-bottom:12px;margin-bottom:15px;}.hospital{font-size:18px;font-weight:700;color:#1a237e;}.title{font-size:14px;letter-spacing:2px;}.row{display:flex;justify-content:space-between;margin-bottom:8px;font-size:12px;padding:4px 0;border-bottom:1px dotted #eee;}.label{font-weight:700;}.expiry{background:#fff3cd;padding:10px;text-align:center;margin:15px 0;font-size:11px;}.footer{text-align:center;font-size:9px;margin-top:15px;padding-top:10px;border-top:1px dashed #ccc;}@media print{body{padding:0;}.pass{border:none;}}</style></head><body><div class="pass"><div class="header"><div class="hospital">${escapeHtml('<?php echo addslashes($hospital_name); ?>')}</div><div class="title">VISITOR PASS</div><div class="pass-no">${escapeHtml(p.pass_number)}</div></div><div class="row"><span class="label">Visitor:</span><span>${escapeHtml(p.visitor_name)}</span></div><div class="row"><span class="label">Phone:</span><span>${escapeHtml(p.visitor_phone || 'N/A')}</span></div><div class="row"><span class="label">Patient:</span><span>${escapeHtml(p.patient_name)}</span></div><div class="row"><span class="label">MRN:</span><span>${escapeHtml(p.mr_number)}</span></div><div class="row"><span class="label">Admission:</span><span>${escapeHtml(p.admission_number)}</span></div><div class="row"><span class="label">Location:</span><span>${escapeHtml(p.ward_name || 'General')} - Bed ${escapeHtml(p.bed_code || 'N/A')}</span></div><div class="row"><span class="label">Relationship:</span><span>${escapeHtml(p.relationship_to_patient || 'N/A')}</span></div><div class="expiry"><strong>Valid Until:</strong> ${new Date(p.valid_to).toLocaleString()}</div><div class="footer">Issued: ${new Date(p.issued_at).toLocaleString()}<br>Issued by: ${escapeHtml(p.issued_by_name)}</div></div><script>window.print();setTimeout(()=>window.close(),1500);<\/script></body></html>`);
        win.document.close();
    });
}

function formatDate(d) { return d ? new Date(d).toLocaleString() : 'N/A'; }
function escapeHtml(s) { if(!s) return ''; return String(s).replace(/[&<>]/g, m => m==='&'?'&amp;':(m==='<'?'&lt;':'&gt;')); }
initTable();
if (typeof MetisMenu !== 'undefined') { let sideMenu = document.getElementById('side-menu'); if (sideMenu && !sideMenu.hasAttribute('data-metismenu-initialized')) { new MetisMenu(sideMenu, { toggle: true }); sideMenu.setAttribute('data-metismenu-initialized', 'true'); } }
</script>
</body>
</html>