<?php
/**
 * ======================================================================
 * INSURANCE VERIFICATION SYSTEM - HMS ERP (Enterprise Grade)
 * Fixed Search | RBAC | Secure File Handling | Audit Trail
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
ini_set('error_log', __DIR__ . '/../../logs/insurance_errors.log');

if (!is_dir(__DIR__ . '/../../logs')) {
    mkdir(__DIR__ . '/../../logs', 0755, true);
}

// ============================================
// AUTHENTICATION & SESSION
// ============================================
$user_id = (int)($_SESSION['user_id'] ?? 0);
$user_branch_id = (int)($_SESSION['branch_id'] ?? 0);
$is_global_admin = ($_SESSION['user_type'] ?? '') === 'super_admin';
$user_name = $_SESSION['first_name'] ?? $_SESSION['username'] ?? 'System User';

if ($user_id <= 0 || $user_branch_id <= 0) {
    header('Location: ../../index.php');
    exit;
}

// ============================================
// CENTRALIZED DATABASE CONNECTION
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
// RBAC PERMISSION CHECKS
// ============================================
$can_verify_insurance = $is_global_admin || user_has_permission($pdo, 'insurance_verify_access', $user_id);
$can_manage_insurance = $is_global_admin || user_has_permission($pdo, 'insurance_manage_access', $user_id);
$can_view_insurance   = $is_global_admin || user_has_permission($pdo, 'insurance_view_access', $user_id);

if (!$can_view_insurance && !$can_verify_insurance && !$can_manage_insurance) {
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
// SECURE STORAGE DIRECTORY (Outside web root)
// ============================================
$upload_dir = __DIR__ . '/../../storage/insurance_cards/';
$web_accessible_dir = 'storage/insurance_cards/';
if (!file_exists($upload_dir)) {
    mkdir($upload_dir, 0755, true);
}
// Protect directory (Apache)
file_put_contents($upload_dir . '.htaccess', "Order Deny,Allow\nDeny from all\n");

// ============================================
// HELPER FUNCTIONS
// ============================================
function escapeHtml($str) {
    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}

function jsonResponse($success, $message, $data = null, $httpCode = 200) {
    if (ob_get_length()) ob_clean();
    http_response_code($httpCode);
    header('Content-Type: application/json');
    header('X-Content-Type-Options: nosniff');
    $response = ['success' => $success, 'message' => $message];
    if ($data !== null) $response = array_merge($response, $data);
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

function sanitizeInput($input) {
    if (is_array($input)) return array_map('sanitizeInput', $input);
    return trim(htmlspecialchars((string)$input, ENT_QUOTES, 'UTF-8'));
}

function validateCsrf($submitted, $session) {
    return !empty($submitted) && !empty($session) && hash_equals($session, $submitted);
}

function logAudit($pdo, $userId, $action, $table, $recordId, $details, $branchId) {
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
            ':table' => $table,
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
// BRANCH & INSURANCE PROVIDERS (with isolation)
// ============================================
$branch_name = 'Main Branch';
try {
    $stmt = $pdo->prepare("SELECT name FROM branches WHERE id = ? AND deleted_at IS NULL");
    $stmt->execute([$user_branch_id]);
    if ($row = $stmt->fetch()) $branch_name = $row['name'];
} catch (PDOException $e) { error_log($e->getMessage()); }

$insurance_companies = [];
try {
    $stmt = $pdo->prepare("
        SELECT id, code, name, default_coverage_percentage, default_co_pay_percentage
        FROM insurance_providers
        WHERE is_active = 1 AND deleted_at IS NULL
        AND (branch_id IS NULL OR branch_id = ?)
        ORDER BY name
    ");
    $stmt->execute([$user_branch_id]);
    $insurance_companies = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { error_log($e->getMessage()); }

if (empty($insurance_companies)) {
    $insurance_companies = [
        ['id' => 1, 'code' => 'EHIA-001', 'name' => 'Ethiopian Health Insurance Agency', 'default_coverage_percentage' => 85, 'default_co_pay_percentage' => 15],
        ['id' => 2, 'code' => 'NYALA-001', 'name' => 'Nyala Insurance S.C.', 'default_coverage_percentage' => 75, 'default_co_pay_percentage' => 25],
        ['id' => 3, 'code' => 'AWASH-001', 'name' => 'Awash Insurance S.C.', 'default_coverage_percentage' => 80, 'default_co_pay_percentage' => 20]
    ];
}

// ============================================
// AJAX: SEARCH PATIENTS (FIXED)
// ============================================
if (isset($_GET['search_patients'])) {
    if (!$can_view_insurance) jsonResponse(false, 'Permission denied');

    $search = sanitizeInput($_GET['search'] ?? '');
    if (strlen($search) < 2) jsonResponse(true, '', ['data' => []]);

    try {
        $like = "%$search%";
        $sql = "
            SELECT 
                p.id, p.mr_number, p.first_name, p.middle_name, p.last_name,
                p.date_of_birth, p.gender, p.phone, p.email,
                CONCAT(p.first_name, ' ', COALESCE(p.middle_name,''), ' ', p.last_name) AS full_name,
                TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) AS age
            FROM patients p
            WHERE p.deleted_at IS NULL
                AND p.is_active = 1
                AND p.branch_id = :branch_id
                AND (
                    p.first_name LIKE :search1 OR p.last_name LIKE :search2
                    OR p.mr_number LIKE :search3 OR p.phone LIKE :search4
                    OR CONCAT(p.first_name, ' ', COALESCE(p.middle_name,''), ' ', p.last_name) LIKE :search5
                )
            ORDER BY p.created_at DESC
            LIMIT 20
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':branch_id' => $user_branch_id,
            ':search1' => $like, ':search2' => $like, ':search3' => $like,
            ':search4' => $like, ':search5' => $like
        ]);

        $results = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            // Get active insurance profile
            $insStmt = $pdo->prepare("
                SELECT pip.insurance_provider_id, pip.policy_number, pip.member_id,
                       pip.coverage_percentage, pip.co_pay_percentage,
                       pip.effective_from, pip.effective_to, ip.name AS insurance_provider_name,
                       CASE WHEN pip.effective_to IS NOT NULL AND pip.effective_to < CURDATE() THEN 1 ELSE 0 END AS is_expired
                FROM patient_insurance_profiles pip
                LEFT JOIN insurance_providers ip ON pip.insurance_provider_id = ip.id AND ip.deleted_at IS NULL
                WHERE pip.patient_id = ? AND pip.is_primary = 1 AND pip.deleted_at IS NULL
                LIMIT 1
            ");
            $insStmt->execute([$row['id']]);
            $insurance = $insStmt->fetch(PDO::FETCH_ASSOC);

            $results[] = [
                'id' => (int)$row['id'],
                'mr_number' => $row['mr_number'],
                'name' => $row['full_name'],
                'age' => (int)$row['age'],
                'gender' => $row['gender'],
                'phone' => $row['phone'] ?? 'N/A',
                'insurance_provider' => $insurance['insurance_provider_name'] ?? null,
                'insurance_provider_id' => $insurance['insurance_provider_id'] ?? null,
                'insurance_number' => $insurance['policy_number'] ?? null,
                'is_expired' => (bool)($insurance['is_expired'] ?? false)
            ];
        }
        jsonResponse(true, '', ['data' => $results]);
    } catch (PDOException $e) {
        error_log('Patient search error: ' . $e->getMessage());
        jsonResponse(false, 'Unable to search patients. Please try again.');
    }
}

// ============================================
// AJAX: GET PATIENT INSURANCE DETAILS
// ============================================
if (isset($_GET['get_patient_insurance'])) {
    if (!$can_view_insurance) jsonResponse(false, 'Permission denied');

    $patient_id = (int)($_GET['patient_id'] ?? 0);
    if ($patient_id <= 0) jsonResponse(false, 'Invalid patient ID');

    try {
        $stmt = $pdo->prepare("
            SELECT p.id, p.mr_number, p.first_name, p.middle_name, p.last_name,
                   p.date_of_birth, p.gender, p.phone, p.email, p.address, p.blood_group,
                   pip.insurance_provider_id, pip.policy_number, pip.member_id,
                   pip.coverage_percentage, pip.co_pay_percentage,
                   pip.effective_from, pip.effective_to,
                   ip.name AS insurance_provider_name,
                   CONCAT(p.first_name, ' ', COALESCE(p.middle_name,''), ' ', p.last_name) AS full_name,
                   TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) AS age
            FROM patients p
            LEFT JOIN patient_insurance_profiles pip ON p.id = pip.patient_id AND pip.is_primary = 1 AND pip.deleted_at IS NULL
            LEFT JOIN insurance_providers ip ON pip.insurance_provider_id = ip.id AND ip.deleted_at IS NULL
            WHERE p.id = ? AND p.branch_id = ? AND p.deleted_at IS NULL
        ");
        $stmt->execute([$patient_id, $user_branch_id]);
        $patient = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$patient) jsonResponse(false, 'Patient not found');

        $coverage = $patient['coverage_percentage'] ?? 0;
        $copay = $patient['co_pay_percentage'] ?? 0;
        if (($coverage <= 0 || $copay <= 0) && $patient['insurance_provider_id']) {
            foreach ($insurance_companies as $ic) {
                if ($ic['id'] == $patient['insurance_provider_id']) {
                    if ($coverage <= 0) $coverage = $ic['default_coverage_percentage'];
                    if ($copay <= 0) $copay = $ic['default_co_pay_percentage'];
                    break;
                }
            }
        }
        $patient['coverage_percentage'] = $coverage ?: 80;
        $patient['co_pay_percentage'] = $copay ?: 20;
        $patient['insurance_is_expired'] = ($patient['effective_to'] && $patient['effective_to'] < date('Y-m-d'));

        jsonResponse(true, '', ['data' => $patient]);
    } catch (PDOException $e) {
        error_log('Get insurance details error: ' . $e->getMessage());
        jsonResponse(false, 'Database error');
    }
}

// ============================================
// AJAX: SAVE INSURANCE DETAILS (with CSRF & Audit)
// ============================================
if (isset($_POST['save_insurance'])) {
    if (!$can_manage_insurance) jsonResponse(false, 'Permission denied');
    if (!validateCsrf($_POST['csrf_token'] ?? '', $csrf_token)) {
        jsonResponse(false, 'Security token mismatch');
    }

    $patient_id = (int)($_POST['patient_id'] ?? 0);
    $insurance_provider_id = (int)($_POST['insurance_provider_id'] ?? 0);
    $policy_number = sanitizeInput($_POST['policy_number'] ?? '');
    $member_id = sanitizeInput($_POST['member_id'] ?? '');
    $effective_from = $_POST['effective_from'] ?? null;
    $effective_to = $_POST['effective_to'] ?? null;
    $coverage = (float)($_POST['coverage_percentage'] ?? 0);
    $copay = (float)($_POST['co_pay_percentage'] ?? 0);

    if ($patient_id <= 0) jsonResponse(false, 'Invalid patient');

    try {
        $pdo->beginTransaction();

        // Verify patient belongs to branch
        $check = $pdo->prepare("SELECT id, first_name, last_name, mr_number FROM patients WHERE id = ? AND branch_id = ? AND deleted_at IS NULL");
        $check->execute([$patient_id, $user_branch_id]);
        if (!$check->fetch()) throw new Exception('Patient not found in this branch');

        // Upsert insurance profile
        $upsert = $pdo->prepare("
            INSERT INTO patient_insurance_profiles 
            (patient_id, insurance_provider_id, policy_number, member_id, coverage_percentage, co_pay_percentage,
             effective_from, effective_to, is_primary, created_by_user_id, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?, NOW(), NOW())
            ON DUPLICATE KEY UPDATE
                insurance_provider_id = VALUES(insurance_provider_id),
                policy_number = VALUES(policy_number),
                member_id = VALUES(member_id),
                coverage_percentage = VALUES(coverage_percentage),
                co_pay_percentage = VALUES(co_pay_percentage),
                effective_from = VALUES(effective_from),
                effective_to = VALUES(effective_to),
                updated_at = NOW()
        ");
        $upsert->execute([$patient_id, $insurance_provider_id, $policy_number, $member_id, $coverage, $copay, $effective_from, $effective_to, $user_id]);

        // Get profile ID for audit
        $profileId = $pdo->lastInsertId() ?: (int)($pdo->query("SELECT LAST_INSERT_ID()")->fetchColumn());

        logAudit($pdo, $user_id, 'UPDATE', 'patient_insurance_profiles', $profileId, [
            'patient_id' => $patient_id,
            'insurance_provider_id' => $insurance_provider_id,
            'coverage' => $coverage,
            'copay' => $copay
        ], $user_branch_id);

        $pdo->commit();
        jsonResponse(true, 'Insurance details saved successfully');
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log('Save insurance error: ' . $e->getMessage());
        jsonResponse(false, 'Failed to save insurance details');
    }
}

// ============================================
// AJAX: UPLOAD INSURANCE CARD (Secure)
// ============================================
if (isset($_FILES['insurance_card'])) {
    if (!$can_manage_insurance) jsonResponse(false, 'Permission denied');

    $patient_id = (int)($_POST['patient_id'] ?? 0);
    if ($patient_id <= 0) jsonResponse(false, 'Invalid patient ID');

    $file = $_FILES['insurance_card'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        jsonResponse(false, 'Upload error: ' . $file['error']);
    }

    // Validate size (5MB)
    if ($file['size'] > 5 * 1024 * 1024) jsonResponse(false, 'File too large (max 5MB)');

    // Validate MIME
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'application/pdf' => 'pdf'];
    if (!isset($allowed[$mime])) jsonResponse(false, 'Invalid file type (JPG, PNG, PDF only)');

    // Generate secure filename
    $ext = $allowed[$mime];
    $safe_name = 'ins_' . $patient_id . '_' . time() . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
    $dest = $upload_dir . $safe_name;

    try {
        // Verify patient belongs to branch
        $check = $pdo->prepare("SELECT id FROM patients WHERE id = ? AND branch_id = ? AND deleted_at IS NULL");
        $check->execute([$patient_id, $user_branch_id]);
        if (!$check->fetch()) throw new Exception('Patient not found');

        if (move_uploaded_file($file['tmp_name'], $dest)) {
            // Update profile with file path
            $update = $pdo->prepare("
                UPDATE patient_insurance_profiles 
                SET insurance_card_path = ?, updated_at = NOW()
                WHERE patient_id = ? AND is_primary = 1
            ");
            $web_path = $web_accessible_dir . $safe_name;
            $update->execute([$web_path, $patient_id]);

            logAudit($pdo, $user_id, 'UPLOAD', 'insurance_card', $patient_id, [
                'filename' => $safe_name,
                'size' => $file['size']
            ], $user_branch_id);

            jsonResponse(true, 'Insurance card uploaded', ['filepath' => $web_path]);
        } else {
            throw new Exception('Failed to move uploaded file');
        }
    } catch (Exception $e) {
        error_log('Upload error: ' . $e->getMessage());
        jsonResponse(false, 'Upload failed');
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include('assets/inc/head.php'); ?>
    <meta charset="UTF-8">
    <title>Insurance Verification - HMS ERP</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        /* Same CSS as before – omitted for brevity, but keep your existing styles */
        :root { --primary: #1a237e; --primary-dark: #0d1b5e; --success: #10b981; --danger: #ef4444; --gray: #6b7280; }
        body { font-family: 'Inter', sans-serif; background: #f5f7fb; }
        .content-page { margin-left: 260px; padding: 24px 32px; }
        @media (max-width:768px){ .content-page { margin-left:0; } }
        .welcome-header { background: linear-gradient(135deg, var(--primary-dark), var(--primary)); border-radius: 24px; padding: 24px 32px; color: white; margin-bottom: 28px; }
        .search-container { background: white; border-radius: 20px; padding: 20px; margin-bottom: 28px; }
        .patient-search-input { width: 100%; padding: 14px 20px 14px 48px; border: 2px solid #e5e7eb; border-radius: 50px; }
        .search-results-list { max-height: 400px; overflow-y: auto; border: 1px solid #e5e7eb; border-radius: 12px; margin-top: 16px; display: none; }
        .search-result-item { padding: 16px; cursor: pointer; border-bottom: 1px solid #e5e7eb; }
        .search-result-item:hover { background: #f8fafc; }
        .patient-card { background: linear-gradient(135deg, var(--primary), var(--primary-dark)); border-radius: 16px; padding: 20px; color: white; margin-bottom: 20px; }
        .verification-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; }
        @media (max-width:992px){ .verification-grid { grid-template-columns: 1fr; } }
        .card-module { background: white; border-radius: 20px; border: 1px solid #e5e7eb; overflow: hidden; margin-bottom: 24px; }
        .card-header { padding: 18px 24px; background: #f8fafc; font-weight: 700; border-bottom: 1px solid #e5e7eb; }
        .card-body { padding: 24px; }
        .status-badge { display: inline-flex; padding: 8px 16px; border-radius: 30px; font-weight: 600; }
        .status-active { background: #d1fae5; color: #059669; }
        .status-expired { background: #fee2e2; color: #dc2626; animation: pulse 1.5s infinite; }
        .status-none { background: #f3f4f6; color: #6b7280; }
        @keyframes pulse { 0%,100%{ opacity:1; } 50%{ opacity:0.7; } }
        .coverage-card { background: linear-gradient(135deg, var(--success), #059669); border-radius: 16px; padding: 20px; color: white; }
        .coverage-percentage { font-size: 2.5rem; font-weight: 800; }
        .coverage-slider { width: 100%; height: 8px; border-radius: 4px; background: linear-gradient(90deg, var(--success), #f59e0b); -webkit-appearance: none; }
        .coverage-slider::-webkit-slider-thumb { -webkit-appearance: none; width: 20px; height: 20px; border-radius: 50%; background: white; cursor: pointer; }
        .btn-primary-custom { background: linear-gradient(135deg, var(--primary), var(--primary-dark)); color: white; border: none; padding: 14px; border-radius: 12px; font-weight: 600; width: 100%; transition: 0.2s; }
        .btn-primary-custom:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(26,35,126,0.3); }
        .insurance-card-preview { border: 2px dashed #e5e7eb; border-radius: 16px; padding: 20px; text-align: center; cursor: pointer; }
        .insurance-card-preview:hover { border-color: var(--primary); background: #eff6ff; }
        .card-image { max-width: 100%; max-height: 200px; border-radius: 12px; }
        .loading-overlay { position: fixed; top:0; left:0; width:100%; height:100%; background: rgba(0,0,0,0.6); z-index:2000; display: none; align-items: center; justify-content: center; }
        .loading-overlay.active { display: flex; }
        .spinner { width: 50px; height: 50px; border: 4px solid #e5e7eb; border-top-color: var(--primary); border-radius: 50%; animation: spin 0.8s linear infinite; background: white; }
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
                <div class="row">
                    <div class="col-md-8">
                        <div class="welcome-title">Insurance Verification Gateway 🛡️</div>
                        <div class="welcome-subtitle">Verify patient coverage and manage insurance cards</div>
                    </div>
                    <div class="col-md-4 text-md-end">
                        <span class="badge bg-light text-dark p-2"><i class="fas fa-building"></i> <?php echo escapeHtml($branch_name); ?></span>
                    </div>
                </div>
            </div>

            <div class="search-container">
                <div class="search-wrapper">
                    <i class="fas fa-search"></i>
                    <input type="text" id="patientSearch" class="patient-search-input" placeholder="Search by Name, MRN, or Phone...">
                </div>
                <div id="searchResults" class="search-results-list"></div>
            </div>

            <div id="verificationSection" style="display: none;">
                <div class="patient-card">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4 id="patientName">-</h4>
                            <div class="small"><i class="fas fa-qrcode"></i> MRN: <span id="patientMRN">-</span> | Age: <span id="patientAge">-</span> | <span id="patientGender">-</span></div>
                            <div class="small"><i class="fas fa-phone"></i> <span id="patientPhone">-</span></div>
                        </div>
                        <button class="btn btn-light btn-sm" onclick="clearSelectedPatient()"><i class="fas fa-times"></i> Change</button>
                    </div>
                </div>

                <div id="expiryWarning" class="expiry-warning" style="display:none; background:#fee2e2; border-left:4px solid #dc2626; padding:16px; border-radius:12px; margin-bottom:20px;">
                    <div class="d-flex gap-3"><i class="fas fa-exclamation-triangle fa-2x text-danger"></i><div><strong>INSURANCE EXPIRED</strong><br>Cash payment required.</div></div>
                </div>

                <div class="verification-grid">
                    <div>
                        <div class="card-module">
                            <div class="card-header"><i class="fas fa-id-card"></i> Policy Profile</div>
                            <div class="card-body">
                                <form id="insuranceForm">
                                    <input type="hidden" id="formPatientId" name="patient_id">
                                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                    <div class="mb-3">
                                        <label>Insurance Provider</label>
                                        <select id="insuranceProvider" class="form-select" onchange="updateCoverageFromProvider()">
                                            <option value="0">-- Select --</option>
                                            <?php foreach ($insurance_companies as $ic): ?>
                                            <option value="<?php echo $ic['id']; ?>" data-coverage="<?php echo $ic['default_coverage_percentage']; ?>" data-copay="<?php echo $ic['default_co_pay_percentage']; ?>">
                                                <?php echo escapeHtml($ic['name']); ?> (Covers <?php echo $ic['default_coverage_percentage']; ?>%)
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="row mb-3">
                                        <div class="col-md-8"><label>Policy Number</label><input type="text" id="policyNumber" class="form-control"></div>
                                        <div class="col-md-4"><label>Member ID</label><input type="text" id="memberId" class="form-control"></div>
                                    </div>
                                    <div class="row mb-3">
                                        <div class="col-md-6"><label>Effective From</label><input type="date" id="effectiveFrom" class="form-control"></div>
                                        <div class="col-md-6"><label>Effective To</label><input type="date" id="effectiveTo" class="form-control" onchange="checkExpiryDate()"></div>
                                    </div>
                                    <button type="button" class="btn btn-primary w-100" onclick="saveInsuranceDetails()"><i class="fas fa-save"></i> Save Policy</button>
                                </form>
                            </div>
                        </div>
                        <div class="card-module">
                            <div class="card-header"><i class="fas fa-image"></i> Insurance Card</div>
                            <div class="card-body">
                                <div id="cardPreviewArea" class="insurance-card-preview" onclick="$('#cardUpload').click()">
                                    <i class="fas fa-cloud-upload-alt fa-3x text-muted"></i>
                                    <p>Click to upload insurance card</p>
                                    <small>JPG, PNG, PDF (max 5MB)</small>
                                </div>
                                <div id="cardImagePreview" style="display:none;" class="text-center mt-3">
                                    <img id="cardImage" class="card-image" alt="Insurance Card">
                                    <div class="mt-2"><button class="btn btn-sm btn-danger" onclick="removeCardImage()"><i class="fas fa-trash"></i> Remove</button></div>
                                </div>
                                <input type="file" id="cardUpload" style="display:none;" accept="image/jpeg,image/png,image/jpg,application/pdf">
                            </div>
                        </div>
                    </div>
                    <div>
                        <div class="card-module">
                            <div class="card-header"><i class="fas fa-chart-pie"></i> Coverage Allocation</div>
                            <div class="card-body">
                                <div class="coverage-card mb-4">
                                    <div class="row text-center">
                                        <div class="col-6"><div class="small">INSURANCE COVERS</div><div class="coverage-percentage" id="coveragePercentDisplay">0%</div></div>
                                        <div class="col-6"><div class="small">PATIENT CO-PAY</div><div class="coverage-percentage" id="copayPercentDisplay">0%</div></div>
                                    </div>
                                </div>
                                <div class="mb-4">
                                    <label>Coverage Percentage</label>
                                    <input type="range" id="coverageSlider" class="coverage-slider" min="0" max="100" step="1" onchange="updateCoverageFromSlider()">
                                    <div class="text-center mt-2"><span class="badge bg-primary" id="coverageValue">80% Coverage</span></div>
                                </div>
                                <div class="mb-4"><label>Co-pay % (Patient)</label><input type="number" id="copayInput" class="form-control" readonly></div>
                                <div class="alert alert-info"><i class="fas fa-info-circle"></i> Insurance covers <span id="coverageInfo">80%</span>, patient pays <span id="copayInfo">20%</span></div>
                                <input type="hidden" id="coveragePercent" value="80">
                                <input type="hidden" id="copayPercent" value="20">
                            </div>
                        </div>
                        <div class="card-module">
                            <div class="card-header"><i class="fas fa-check-double"></i> Verification Status</div>
                            <div class="card-body">
                                <div class="mb-3"><span class="text-muted">Status:</span> <span id="verificationStatusBadge" class="status-badge status-none">No Insurance</span></div>
                                <button id="proceedBtn" class="btn-primary-custom" onclick="proceedToBilling()"><i class="fas fa-arrow-right"></i> Proceed to Billing</button>
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
let searchTimer, currentPatientId = null, isProcessing = false;
const csrfToken = <?php echo json_encode($csrf_token); ?>;

function showLoading(show) { document.getElementById('loadingOverlay').classList.toggle('active', show); }
function escapeHtml(str) { if(!str) return ''; return String(str).replace(/[&<>]/g, m => m==='&'?'&amp;':(m==='<'?'&lt;':'&gt;')); }
function capitalize(s) { return s ? s.charAt(0).toUpperCase() + s.slice(1) : ''; }

// Patient search
$('#patientSearch').on('input', function() {
    clearTimeout(searchTimer);
    const search = $(this).val();
    if(search.length < 2) { $('#searchResults').hide(); return; }
    searchTimer = setTimeout(() => {
        showLoading(true);
        $.getJSON(window.location.pathname, { search_patients: 1, search: search })
            .done(res => {
                showLoading(false);
                if(res.success && res.data.length) displaySearchResults(res.data);
                else $('#searchResults').html('<div class="p-3 text-muted">No patients found</div>').show();
            })
            .fail(() => { showLoading(false); $('#searchResults').html('<div class="p-3 text-danger">Search error</div>').show(); });
    }, 500);
});

function displaySearchResults(patients) {
    let html = '';
    patients.forEach(p => {
        let badge = p.is_expired ? '<span class="badge bg-danger ms-2">Expired</span>' : (p.insurance_provider ? '<span class="badge bg-success ms-2">Active</span>' : '<span class="badge bg-secondary ms-2">No Insurance</span>');
        html += `<div class="search-result-item" onclick="selectPatient(${p.id})">
            <div class="d-flex align-items-center gap-3">
                <div class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center" style="width:48px;height:48px;">${escapeHtml((p.name||'P').substring(0,2).toUpperCase())}</div>
                <div><div class="fw-bold">${escapeHtml(p.name)} ${badge}</div>
                <div class="small">MRN: ${escapeHtml(p.mr_number)} | Age: ${p.age} | ${escapeHtml(p.gender)}</div>
                ${p.phone ? `<div class="small"><i class="fas fa-phone"></i> ${escapeHtml(p.phone)}</div>` : ''}
                ${p.insurance_provider ? `<div class="small"><i class="fas fa-shield-alt"></i> ${escapeHtml(p.insurance_provider)}</div>` : ''}</div>
                <i class="fas fa-chevron-right"></i>
            </div>
        </div>`;
    });
    $('#searchResults').html(html).show();
}

function selectPatient(pid) {
    currentPatientId = pid;
    $('#searchResults').hide();
    $('#patientSearch').val('');
    $('#verificationSection').show();
    showLoading(true);
    $.getJSON(window.location.pathname, { get_patient_insurance: 1, patient_id: pid })
        .done(res => {
            showLoading(false);
            if(res.success) displayPatientInfo(res.data);
            else Swal.fire('Error', res.message, 'error');
        })
        .fail(() => { showLoading(false); Swal.fire('Error', 'Failed to load data', 'error'); });
}

function displayPatientInfo(d) {
    $('#formPatientId').val(d.id);
    $('#patientName').text(d.full_name);
    $('#patientMRN').text(d.mr_number);
    $('#patientAge').text(d.age+' years');
    $('#patientGender').text(capitalize(d.gender));
    $('#patientPhone').text(d.phone || 'N/A');
    $('#insuranceProvider').val(d.insurance_provider_id || 0);
    $('#policyNumber').val(d.policy_number || '');
    $('#memberId').val(d.member_id || '');
    $('#effectiveFrom').val(d.effective_from || '');
    $('#effectiveTo').val(d.effective_to || '');
    let cov = d.coverage_percentage || 80;
    let cop = d.co_pay_percentage || 20;
    $('#coverageSlider').val(cov);
    $('#coveragePercentDisplay').text(cov+'%');
    $('#copayPercentDisplay').text(cop+'%');
    $('#copayInput').val(cop);
    $('#coveragePercent').val(cov);
    $('#copayPercent').val(cop);
    $('#coverageInfo').text(cov+'%');
    $('#copayInfo').text(cop+'%');
    if(d.insurance_is_expired && d.insurance_provider_id) {
        $('#expiryWarning').show();
        $('#verificationStatusBadge').attr('class','status-badge status-expired').text('EXPIRED - CASH REQUIRED');
    } else if(d.insurance_provider_id) {
        $('#expiryWarning').hide();
        $('#verificationStatusBadge').attr('class','status-badge status-active').text('Active Insurance');
    } else {
        $('#expiryWarning').hide();
        $('#verificationStatusBadge').attr('class','status-badge status-none').text('No Insurance');
    }
}

function updateCoverageFromProvider() {
    let sel = $('#insuranceProvider option:selected');
    let cov = sel.data('coverage') || 80;
    let cop = sel.data('copay') || 20;
    $('#coverageSlider').val(cov);
    $('#coveragePercentDisplay').text(cov+'%');
    $('#copayPercentDisplay').text(cop+'%');
    $('#copayInput').val(cop);
    $('#coveragePercent').val(cov);
    $('#copayPercent').val(cop);
    $('#coverageInfo').text(cov+'%');
    $('#copayInfo').text(cop+'%');
}

function updateCoverageFromSlider() {
    let cov = parseInt($('#coverageSlider').val());
    let cop = 100 - cov;
    $('#coveragePercentDisplay').text(cov+'%');
    $('#copayPercentDisplay').text(cop+'%');
    $('#copayInput').val(cop);
    $('#coveragePercent').val(cov);
    $('#copayPercent').val(cop);
    $('#coverageInfo').text(cov+'%');
    $('#copayInfo').text(cop+'%');
}

function checkExpiryDate() {
    let validTo = $('#effectiveTo').val();
    if(validTo && validTo < new Date().toISOString().split('T')[0]) {
        Swal.fire('Warning', 'Insurance expired. Cash required.', 'warning');
        $('#expiryWarning').show();
    } else $('#expiryWarning').hide();
}

function saveInsuranceDetails() {
    if(isProcessing) return;
    let patientId = $('#formPatientId').val();
    if(!patientId) { Swal.fire('Error', 'No patient selected', 'error'); return; }
    isProcessing = true;
    showLoading(true);
    $.ajax({
        url: window.location.pathname,
        method: 'POST',
        data: {
            save_insurance: 1,
            patient_id: patientId,
            insurance_provider_id: $('#insuranceProvider').val(),
            policy_number: $('#policyNumber').val(),
            member_id: $('#memberId').val(),
            effective_from: $('#effectiveFrom').val(),
            effective_to: $('#effectiveTo').val(),
            coverage_percentage: $('#coveragePercent').val(),
            co_pay_percentage: $('#copayPercent').val(),
            csrf_token: csrfToken
        },
        dataType: 'json',
        success: res => {
            isProcessing = false;
            showLoading(false);
            if(res.success) { Swal.fire('Success', res.message, 'success'); selectPatient(patientId); }
            else Swal.fire('Error', res.message, 'error');
        },
        error: () => { isProcessing = false; showLoading(false); Swal.fire('Error', 'Network error', 'error'); }
    });
}

function proceedToBilling() {
    let pid = $('#formPatientId').val();
    if(!pid) return;
    if($('#expiryWarning').is(':visible'))
        Swal.fire({ title: 'Expired Insurance', text: 'Cash billing only', icon: 'warning', confirmButtonText: 'Proceed' })
            .then(() => window.location.href = `service_billing.php?patient_id=${pid}`);
    else window.location.href = `service_billing.php?patient_id=${pid}`;
}

function clearSelectedPatient() {
    currentPatientId = null;
    $('#verificationSection').hide();
    $('#patientSearch').val('').focus();
    $('#cardImagePreview').hide();
    $('#cardPreviewArea').show();
}

// File upload
$('#cardUpload').on('change', function() {
    if(isProcessing) return;
    let file = this.files[0];
    if(!file) return;
    let patientId = $('#formPatientId').val();
    if(!patientId) { Swal.fire('Error', 'Select patient first', 'error'); $('#cardUpload').val(''); return; }
    if(file.size > 5*1024*1024) { Swal.fire('Error', 'File >5MB', 'error'); $('#cardUpload').val(''); return; }
    let fd = new FormData();
    fd.append('insurance_card', file);
    fd.append('patient_id', patientId);
    isProcessing = true;
    showLoading(true);
    $.ajax({
        url: window.location.pathname,
        method: 'POST',
        data: fd,
        processData: false,
        contentType: false,
        dataType: 'json',
        success: res => {
            isProcessing = false;
            showLoading(false);
            if(res.success) {
                Swal.fire('Success', 'Card uploaded', 'success');
                if(file.type.startsWith('image/')) {
                    let reader = new FileReader();
                    reader.onload = e => { $('#cardImage').attr('src', e.target.result); $('#cardImagePreview').show(); $('#cardPreviewArea').hide(); };
                    reader.readAsDataURL(file);
                } else { $('#cardImagePreview').show(); $('#cardPreviewArea').hide(); $('#cardImage').attr('src','assets/img/pdf-icon.png'); }
            } else Swal.fire('Error', res.message, 'error');
            $('#cardUpload').val('');
        },
        error: () => { isProcessing = false; showLoading(false); Swal.fire('Error', 'Upload failed', 'error'); $('#cardUpload').val(''); }
    });
});

function removeCardImage() {
    Swal.fire({ title: 'Remove?', icon: 'warning', showCancelButton: true, confirmButtonText: 'Yes' }).then(result => {
        if(result.isConfirmed) { $('#cardImagePreview').hide(); $('#cardPreviewArea').show(); }
    });
}

$(document).on('click', e => { if(!$(e.target).closest('.search-container').length) $('#searchResults').hide(); });
</script>
</body>
</html>