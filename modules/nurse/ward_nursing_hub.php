<?php
/**
 * ======================================================================
 * WARD NURSING HUB - INPATIENT BED MANAGEMENT (SCHEMA-FIXED)
 * Uses correct tables: wards, beds, rooms, admissions, patients
 * Live bed map, pending admissions, bed assignment with transaction safety
 * Multi‑tenancy | RBAC | CSRF | Ajax | Responsive
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

$has_nursing_access = user_has_permission($pdo, 'ward_nursing_access', $user_id);
if (!$is_global_admin && !$has_nursing_access) {
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

// ----------------------------------------------------------------------
// AJAX HANDLERS
// ----------------------------------------------------------------------
if (isset($_GET['action'])) {
    // 1. Fetch bed map for a selected ward (using 'beds' table)
    if ($_GET['action'] === 'fetch_bed_map') {
        $ward_id = (int)($_GET['ward_id'] ?? 0);
        if ($ward_id <= 0) jsonResponse(false, 'Invalid ward ID');

        try {
            $sql = "SELECT 
                        b.id AS bed_id,
                        b.bed_number,
                        b.bed_type,
                        b.status AS bed_status,
                        b.daily_rate,
                        r.room_number,
                        r.room_type,
                        a.id AS admission_id,
                        a.admission_number,
                        a.condition_status,
                        p.id AS patient_id,
                        p.mr_number,
                        CONCAT(p.first_name, ' ', COALESCE(p.middle_name, ''), ' ', p.last_name) AS patient_name,
                        p.gender,
                        p.date_of_birth
                    FROM beds b
                    LEFT JOIN rooms r ON b.room_id = r.id AND r.deleted_at IS NULL
                    LEFT JOIN admissions a ON a.bed_id = b.id 
                        AND a.status = 'admitted' 
                        AND a.deleted_at IS NULL
                    LEFT JOIN patients p ON a.patient_id = p.id AND p.deleted_at IS NULL
                    WHERE b.ward_id = :ward_id 
                      AND b.branch_id = :branch_id 
                      AND b.deleted_at IS NULL
                    ORDER BY CAST(b.bed_number AS UNSIGNED), b.bed_number";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':ward_id' => $ward_id, ':branch_id' => $branch_id]);
            $beds = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Calculate age for each occupied bed
            foreach ($beds as &$bed) {
                if (!empty($bed['date_of_birth'])) {
                    $bed['age'] = calculateAge($bed['date_of_birth']);
                } else {
                    $bed['age'] = 'N/A';
                }
            }
            
            jsonResponse(true, '', ['beds' => $beds]);
        } catch (PDOException $e) {
            jsonResponse(false, 'Database error: ' . $e->getMessage());
        }
        exit;
    }

    // 2. Fetch pending admissions (bed not yet assigned)
    if ($_GET['action'] === 'fetch_pending_admissions') {
        try {
            $sql = "SELECT a.id AS admission_id, a.admission_number, a.admission_type, a.condition_status,
                           a.visit_id,
                           p.id AS patient_id, p.mr_number, p.first_name, p.middle_name, p.last_name, 
                           p.gender, p.date_of_birth,
                           p.phone,
                           u.first_name AS doctor_first_name, u.last_name AS doctor_last_name
                    FROM admissions a
                    INNER JOIN patients p ON a.patient_id = p.id AND p.deleted_at IS NULL
                    LEFT JOIN users u ON a.doctor_id = u.id AND u.deleted_at IS NULL
                    WHERE a.branch_id = :branch_id
                      AND a.bed_id IS NULL
                      AND a.status = 'admitted'
                      AND a.deleted_at IS NULL
                    ORDER BY 
                        CASE WHEN a.condition_status IN ('critical', 'serious') THEN 1 ELSE 2 END,
                        a.admitted_at ASC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':branch_id' => $branch_id]);
            $pending = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Calculate age for each pending patient
            foreach ($pending as &$p) {
                if (!empty($p['date_of_birth'])) {
                    $p['age'] = calculateAge($p['date_of_birth']);
                } else {
                    $p['age'] = 'N/A';
                }
                $p['doctor_name'] = trim(($p['doctor_first_name'] ?? '') . ' ' . ($p['doctor_last_name'] ?? ''));
                if (empty($p['doctor_name'])) $p['doctor_name'] = 'Unassigned';
            }
            
            jsonResponse(true, '', ['pending' => $pending]);
        } catch (PDOException $e) {
            jsonResponse(false, 'Database error: ' . $e->getMessage());
        }
        exit;
    }
}

// 3. Assign bed (POST) – atomic transaction with FOR UPDATE
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'assign_bed') {
    $incoming_token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($csrf_token, $incoming_token)) {
        jsonResponse(false, 'Security token mismatch');
    }

    $admission_id = (int)($_POST['admission_id'] ?? 0);
    $bed_id = (int)($_POST['bed_id'] ?? 0);
    if ($admission_id <= 0 || $bed_id <= 0) {
        jsonResponse(false, 'Invalid admission or bed ID');
    }

    try {
        $pdo->beginTransaction();

        // Lock bed row (use 'status' to check occupancy)
        $checkBed = $pdo->prepare("SELECT status FROM beds WHERE id = :bed_id AND branch_id = :branch_id FOR UPDATE");
        $checkBed->execute([':bed_id' => $bed_id, ':branch_id' => $branch_id]);
        $bed = $checkBed->fetch(PDO::FETCH_ASSOC);
        if (!$bed) throw new PDOException('Bed not found');
        if ($bed['status'] !== 'available') throw new PDOException('አልጋው ቀድሞውኑ ተይዟል ወይም ለአገልግሎት አይገኝም / Bed not available');

        // Lock admission row (check it still has no bed)
        $checkAdm = $pdo->prepare("SELECT id, patient_id, visit_id FROM admissions WHERE id = :admission_id AND branch_id = :branch_id AND bed_id IS NULL AND status = 'admitted' AND deleted_at IS NULL FOR UPDATE");
        $checkAdm->execute([':admission_id' => $admission_id, ':branch_id' => $branch_id]);
        $admission = $checkAdm->fetch(PDO::FETCH_ASSOC);
        if (!$admission) {
            throw new PDOException('የመግቢያ ምዝገባ አልተገኘም ወይም አልጋ ተመድቦለት / Admission not valid');
        }

        // Update admission with bed_id
        $updAdm = $pdo->prepare("UPDATE admissions SET bed_id = :bed_id, updated_at = NOW() WHERE id = :admission_id");
        $updAdm->execute([':bed_id' => $bed_id, ':admission_id' => $admission_id]);

        // Mark bed as occupied (change status to 'occupied')
        $updBed = $pdo->prepare("UPDATE beds SET status = 'occupied', updated_at = NOW() WHERE id = :bed_id");
        $updBed->execute([':bed_id' => $bed_id]);

        // Insert into bed_allocations for tracking
        $insertAlloc = $pdo->prepare("INSERT INTO bed_allocations 
            (patient_id, ward_id, visit_id, bed_number, check_in_date, status, assigned_by_user_id, created_at, updated_at)
            SELECT 
                :patient_id,
                (SELECT ward_id FROM beds WHERE id = :bed_id),
                :visit_id,
                (SELECT bed_number FROM beds WHERE id = :bed_id),
                NOW(),
                'occupied',
                :assigned_by,
                NOW(),
                NOW()");
        $insertAlloc->execute([
            ':patient_id' => $admission['patient_id'],
            ':bed_id' => $bed_id,
            ':visit_id' => $admission['visit_id'],
            ':assigned_by' => $user_id
        ]);

        $pdo->commit();
        jsonResponse(true, 'ታካሚው በአልጋው ላይ ተመዝግቧል / Patient assigned successfully');
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        jsonResponse(false, $e->getMessage());
    }
    exit;
}

// 4. Discharge patient (free bed)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'discharge_patient') {
    $incoming_token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($csrf_token, $incoming_token)) {
        jsonResponse(false, 'Security token mismatch');
    }

    $admission_id = (int)($_POST['admission_id'] ?? 0);
    $discharge_condition = $_POST['discharge_condition'] ?? 'improved';
    $discharge_notes = $_POST['discharge_notes'] ?? '';

    if ($admission_id <= 0) {
        jsonResponse(false, 'Invalid admission ID');
    }

    try {
        $pdo->beginTransaction();

        // Get admission details with bed
        $getAdm = $pdo->prepare("SELECT a.id, a.bed_id, a.patient_id, a.visit_id 
                                 FROM admissions a 
                                 WHERE a.id = :admission_id AND a.status = 'admitted' AND a.deleted_at IS NULL");
        $getAdm->execute([':admission_id' => $admission_id]);
        $admission = $getAdm->fetch(PDO::FETCH_ASSOC);
        if (!$admission) throw new PDOException('Admission not found or already discharged');

        // Update admission with discharge info
        $updAdm = $pdo->prepare("UPDATE admissions 
            SET status = 'discharged', 
                discharged_at = NOW(), 
                discharge_condition = :condition,
                discharge_status = :status,
                discharge_summary = :notes,
                updated_at = NOW() 
            WHERE id = :admission_id");
        $updAdm->execute([
            ':admission_id' => $admission_id,
            ':condition' => $discharge_condition,
            ':status' => 'discharged',
            ':notes' => $discharge_notes
        ]);

        // Free the bed if assigned
        if ($admission['bed_id']) {
            $updBed = $pdo->prepare("UPDATE beds SET status = 'available', updated_at = NOW() WHERE id = :bed_id");
            $updBed->execute([':bed_id' => $admission['bed_id']]);

            // Update bed allocation
            $updAlloc = $pdo->prepare("UPDATE bed_allocations 
                SET status = 'discharged', check_out_date = NOW(), updated_at = NOW() 
                WHERE bed_number = (SELECT bed_number FROM beds WHERE id = :bed_id) 
                  AND status = 'occupied' AND deleted_at IS NULL");
            $updAlloc->execute([':bed_id' => $admission['bed_id']]);
        }

        $pdo->commit();
        jsonResponse(true, 'ታካሚው ከሆስፒታል ወጥቷል / Patient discharged successfully');
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        jsonResponse(false, $e->getMessage());
    }
    exit;
}

// ----------------------------------------------------------------------
// INITIAL PAGE LOAD (PHP)
// ----------------------------------------------------------------------
$wards = [];
$pending_admissions = [];

try {
    // Get wards for the branch
    $stmt = $pdo->prepare("SELECT id, name FROM wards WHERE branch_id = :branch_id AND deleted_at IS NULL ORDER BY name");
    $stmt->execute([':branch_id' => $branch_id]);
    $wards = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get pending admissions
    $sql = "SELECT a.id AS admission_id, a.admission_number, a.admission_type, a.condition_status,
                   a.visit_id,
                   p.id AS patient_id, p.mr_number, p.first_name, p.middle_name, p.last_name, 
                   p.gender, p.date_of_birth,
                   p.phone,
                   u.first_name AS doctor_first_name, u.last_name AS doctor_last_name
            FROM admissions a
            INNER JOIN patients p ON a.patient_id = p.id AND p.deleted_at IS NULL
            LEFT JOIN users u ON a.doctor_id = u.id AND u.deleted_at IS NULL
            WHERE a.branch_id = :branch_id
              AND a.bed_id IS NULL
              AND a.status = 'admitted'
              AND a.deleted_at IS NULL
            ORDER BY 
                CASE WHEN a.condition_status IN ('critical', 'serious') THEN 1 ELSE 2 END,
                a.admitted_at ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':branch_id' => $branch_id]);
    $pending_admissions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate age for each pending patient
    foreach ($pending_admissions as &$p) {
        if (!empty($p['date_of_birth'])) {
            $p['age'] = calculateAge($p['date_of_birth']);
        } else {
            $p['age'] = 'N/A';
        }
        $p['doctor_name'] = trim(($p['doctor_first_name'] ?? '') . ' ' . ($p['doctor_last_name'] ?? ''));
        if (empty($p['doctor_name'])) $p['doctor_name'] = 'Unassigned';
    }
} catch (PDOException $e) {
    error_log('Initial load error: ' . $e->getMessage());
}

$current_date = date('l, F j, Y');
$current_time = date('g:i A');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include('assets/inc/head.php'); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ward Nursing Hub | HMS ERP</title>
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
        .card-header-custom { padding: 16px 20px; background: #f8fafc; border-bottom: 1px solid var(--gray-light); font-weight: 700; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px; }
        .pending-item { padding: 14px 16px; border-bottom: 1px solid #f0f0f0; transition: background 0.2s; cursor: default; }
        .pending-item:hover { background: #f8fafc; }
        .pending-item.active { background: #e0e7ff; border-left: 3px solid var(--primary); }
        .pending-item.critical { background: #fef2f2; border-left: 3px solid var(--danger); }
        .pending-item.critical:hover { background: #fee2e2; }
        .bed-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); gap: 16px; padding: 16px; }
        .bed-card { background: white; border-radius: 16px; border: 1px solid var(--gray-light); padding: 12px; transition: all 0.2s; cursor: pointer; position: relative; }
        .bed-card.available { background: #f0fdf4; border-color: #86efac; }
        .bed-card.occupied { background: #fef2f2; border-color: #fecaca; cursor: not-allowed; }
        .bed-card.maintenance { background: #fffbeb; border-color: #fde68a; cursor: not-allowed; }
        .bed-card.cleaning { background: #eff6ff; border-color: #93c5fd; cursor: not-allowed; }
        .bed-card.reserved { background: #f5f3ff; border-color: #c4b5fd; cursor: not-allowed; }
        .bed-card.available:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.08); }
        .bed-number { font-size: 18px; font-weight: 700; color: var(--dark); }
        .bed-status { font-size: 11px; font-weight: 600; padding: 3px 8px; border-radius: 20px; display: inline-block; margin-top: 8px; }
        .status-available { background: #d1fae5; color: #059669; }
        .status-occupied { background: #fee2e2; color: #dc2626; }
        .status-maintenance { background: #fef3c7; color: #d97706; }
        .status-cleaning { background: #dbeafe; color: #2563eb; }
        .status-reserved { background: #ede9fe; color: #7c3aed; }
        .patient-name { font-size: 13px; font-weight: 600; margin-top: 8px; word-break: break-word; }
        .patient-mrn { font-size: 10px; color: var(--gray); }
        .bed-type-badge { font-size: 9px; padding: 2px 8px; border-radius: 10px; background: #e2e8f0; color: #475569; display: inline-block; margin-top: 4px; }
        .condition-badge { font-size: 9px; padding: 2px 8px; border-radius: 10px; font-weight: 600; }
        .condition-critical { background: #fee2e2; color: #dc2626; animation: pulse 2s infinite; }
        .condition-serious { background: #fef3c7; color: #d97706; }
        .condition-guarded { background: #dbeafe; color: #2563eb; }
        .condition-stable { background: #d1fae5; color: #059669; }
        @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.6; } }
        .empty-state { text-align: center; padding: 40px; color: var(--gray); }
        .btn-assign { background: var(--primary); color: white; border: none; padding: 6px 12px; border-radius: 12px; font-size: 12px; font-weight: 500; transition: 0.2s; }
        .btn-assign:hover { background: var(--primary-dark); }
        .btn-discharge { background: var(--danger); color: white; border: none; padding: 4px 10px; border-radius: 10px; font-size: 11px; font-weight: 500; transition: 0.2s; }
        .btn-discharge:hover { background: #dc2626; }
        .toast-notification { position: fixed; bottom: 20px; right: 20px; background: white; border-radius: 12px; padding: 12px 20px; box-shadow: 0 10px 25px rgba(0,0,0,0.1); z-index: 1100; animation: slideIn 0.3s ease; border-left: 4px solid var(--success); max-width: 400px; }
        .toast-error { border-left-color: var(--danger); }
        @keyframes slideIn { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
        .loading-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(255,255,255,0.7); z-index: 2000; display: none; align-items: center; justify-content: center; }
        .loading-overlay.active { display: flex; }
        .spinner { width: 40px; height: 40px; border: 3px solid var(--gray-light); border-top-color: var(--primary); border-radius: 50%; animation: spin 0.8s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }
        .badge-pending { background: var(--warning); color: #92400e; padding: 4px 10px; border-radius: 20px; font-size: 12px; font-weight: 600; }
        .badge-critical { background: var(--danger); color: white; padding: 4px 10px; border-radius: 20px; font-size: 12px; font-weight: 600; }
        .legend { display: flex; gap: 16px; flex-wrap: wrap; padding: 8px 16px 16px; font-size: 12px; }
        .legend-item { display: flex; align-items: center; gap: 6px; }
        .legend-color { width: 16px; height: 16px; border-radius: 4px; }
        .legend-color.available { background: #86efac; }
        .legend-color.occupied { background: #fecaca; }
        .legend-color.maintenance { background: #fde68a; }
        .legend-color.cleaning { background: #93c5fd; }
        .legend-color.reserved { background: #c4b5fd; }
        @media (max-width: 768px) { .bed-grid { grid-template-columns: repeat(auto-fill, minmax(130px, 1fr)); } }
        .scrollable-list { max-height: 65vh; overflow-y: auto; }
        .scrollable-list::-webkit-scrollbar { width: 4px; }
        .scrollable-list::-webkit-scrollbar-track { background: #f1f1f1; border-radius: 4px; }
        .scrollable-list::-webkit-scrollbar-thumb { background: var(--primary); border-radius: 4px; }
        .bed-stats { display: flex; gap: 12px; flex-wrap: wrap; padding: 8px 16px; font-size: 13px; }
        .bed-stats span { background: #f8fafc; padding: 4px 12px; border-radius: 12px; }
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
                    <div class="welcome-title">Ward Nursing Hub</div>
                    <div class="welcome-subtitle">አልጋ አስተዳደርና ታካሚ ምዝገባ / Bed Management & Admission Check‑in</div>
                </div>
                <div class="time-badge"><i class="far fa-calendar-alt"></i> <?php echo escapeHtml($current_date); ?> <i class="far fa-clock ms-2"></i> <?php echo escapeHtml($current_time); ?></div>
            </div>

            <div class="row g-4">
                <!-- LEFT: Pending Admissions Queue -->
                <div class="col-lg-5 col-xl-4">
                    <div class="card-modern">
                        <div class="card-header-custom">
                            <h5 class="mb-0"><i class="fas fa-hourglass-half me-2"></i> በመጠባበቅ ላይ ያሉ / Pending Admissions</h5>
                            <div>
                                <span class="badge-pending" id="pendingCount"><?php echo count($pending_admissions); ?></span>
                                <span class="badge-critical ms-1" id="criticalCount" style="display:<?php echo count(array_filter($pending_admissions, function($p) { return in_array($p['condition_status'] ?? '', ['critical', 'serious']); })) > 0 ? 'inline-block' : 'none'; ?>">
                                    <i class="fas fa-exclamation-triangle"></i> Critical
                                </span>
                            </div>
                        </div>
                        <div class="scrollable-list" id="pendingList">
                            <?php if (count($pending_admissions) > 0): ?>
                                <?php foreach ($pending_admissions as $adm): 
                                    $fullName = trim(($adm['first_name'] ?? '') . ' ' . ($adm['middle_name'] ?? '') . ' ' . ($adm['last_name'] ?? ''));
                                    $admission_type_am = match($adm['admission_type']) {
                                        'emergency' => 'ድንገተኛ',
                                        'elective' => 'ቀጠሮ',
                                        'transfer' => 'ዝውውር',
                                        default => 'ምልከታ'
                                    };
                                    $isCritical = in_array($adm['condition_status'] ?? '', ['critical', 'serious']);
                                    $conditionClass = $isCritical ? 'critical' : '';
                                    $conditionBadge = '';
                                    if ($adm['condition_status'] ?? '') {
                                        $conditionBadge = '<span class="condition-badge condition-' . $adm['condition_status'] . '">' . ucfirst($adm['condition_status']) . '</span>';
                                    }
                                ?>
                                <div class="pending-item <?php echo $conditionClass; ?>" data-admission-id="<?php echo $adm['admission_id']; ?>">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <h6 class="mb-1">
                                                <?php echo escapeHtml($fullName); ?>
                                                <?php if ($isCritical): ?>
                                                    <span class="condition-badge condition-critical"><i class="fas fa-exclamation-triangle"></i> CRITICAL</span>
                                                <?php endif; ?>
                                            </h6>
                                            <small class="text-muted">MRN: <?php echo escapeHtml($adm['mr_number']); ?> | <?php echo ucfirst($adm['gender']); ?> | <?php echo $adm['age']; ?> yrs</small>
                                            <div class="mt-1">
                                                <span class="badge bg-light text-dark"><?php echo $admission_type_am; ?></span>
                                                <?php echo $conditionBadge; ?>
                                                <span class="badge bg-light text-muted">Dr. <?php echo escapeHtml($adm['doctor_name']); ?></span>
                                            </div>
                                        </div>
                                        <button class="btn-assign assign-bed-btn" data-admission-id="<?php echo $adm['admission_id']; ?>" data-patient-name="<?php echo escapeHtml($fullName); ?>">
                                            <i class="fas fa-bed"></i> አልጋ መድብ
                                        </button>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="empty-state"><i class="fas fa-check-circle fa-2x mb-2 text-success"></i><p>ምንም በመጠባበቅ ላይ ያሉ ታካሚዎች የሉም / No pending admissions</p></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- RIGHT: Ward Selector + Bed Map -->
                <div class="col-lg-7 col-xl-8">
                    <div class="card-modern">
                        <div class="card-header-custom">
                            <h5 class="mb-0"><i class="fas fa-map me-2"></i> አልጋ ካርታ / Bed Map</h5>
                            <div class="d-flex gap-2 align-items-center flex-wrap">
                                <label class="mb-0 me-1 fw-semibold" style="font-size:13px;">ክፍል / Ward:</label>
                                <select id="wardSelect" class="form-select" style="width: auto; min-width: 180px;">
                                    <?php foreach ($wards as $ward): ?>
                                        <option value="<?php echo $ward['id']; ?>"><?php echo escapeHtml($ward['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button id="refreshBedMapBtn" class="btn btn-outline-primary btn-sm"><i class="fas fa-sync-alt"></i></button>
                            </div>
                        </div>
                        <div id="bedStatsContainer" class="bed-stats"></div>
                        <div id="bedMapContainer" style="min-height: 400px;">
                            <div class="text-center p-5 text-muted"><i class="fas fa-spinner fa-spin fa-2x"></i><br>Loading bed map...</div>
                        </div>
                        <div class="legend">
                            <span class="legend-item"><span class="legend-color available"></span> ክፍት / Available</span>
                            <span class="legend-item"><span class="legend-color occupied"></span> ተይዟል / Occupied</span>
                            <span class="legend-item"><span class="legend-color maintenance"></span> ጥገና / Maintenance</span>
                            <span class="legend-item"><span class="legend-color cleaning"></span> ጽዳት / Cleaning</span>
                            <span class="legend-item"><span class="legend-color reserved"></span> ተይዟል / Reserved</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php include('assets/inc/footer.php'); ?>
</div>

<!-- Discharge Modal -->
<div class="modal fade" id="dischargeModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-hospital-user me-2"></i>Discharge Patient</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="dischargeForm">
                    <div class="mb-3">
                        <label class="form-label">Patient</label>
                        <p class="fw-bold" id="dischargePatientName">-</p>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Discharge Condition *</label>
                        <select name="discharge_condition" id="dischargeCondition" class="form-select" required>
                            <option value="improved">Improved</option>
                            <option value="stabilized">Stabilized</option>
                            <option value="relieved">Relieved</option>
                            <option value="ama">AMA (Against Medical Advice)</option>
                            <option value="transferred">Transferred</option>
                            <option value="expired">Expired</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Discharge Notes</label>
                        <textarea name="discharge_notes" id="dischargeNotes" class="form-control" rows="3"></textarea>
                    </div>
                    <input type="hidden" id="dischargeAdmissionId" value="">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" onclick="confirmDischarge()"><i class="fas fa-check"></i> Discharge Patient</button>
            </div>
        </div>
    </div>
</div>

<div id="loadingOverlay" class="loading-overlay"><div class="spinner"></div></div>

<script src="assets/js/vendor.min.js"></script>
<script src="assets/js/bootstrap.bundle.min.js"></script>
<script>
const csrfToken = <?php echo json_encode($csrf_token); ?>;
let selectedAdmissionId = null;
let selectedPatientName = null;

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

function loadPendingAdmissions() {
    fetch(window.location.pathname + '?action=fetch_pending_admissions')
        .then(res => res.json())
        .then(data => {
            if (data.success && data.data && data.data.pending) {
                const container = document.getElementById('pendingList');
                const countSpan = document.getElementById('pendingCount');
                const criticalSpan = document.getElementById('criticalCount');
                
                countSpan.innerText = data.data.pending.length;
                
                const criticalCount = data.data.pending.filter(p => ['critical', 'serious'].includes(p.condition_status || '')).length;
                if (criticalCount > 0) {
                    criticalSpan.style.display = 'inline-block';
                    criticalSpan.innerText = '⚠️ ' + criticalCount + ' Critical';
                } else {
                    criticalSpan.style.display = 'none';
                }
                
                if (data.data.pending.length === 0) {
                    container.innerHTML = '<div class="empty-state"><i class="fas fa-check-circle fa-2x mb-2 text-success"></i><p>ምንም በመጠባበቅ ላይ ያሉ ታካሚዎች የሉም / No pending admissions</p></div>';
                } else {
                    let html = '';
                    data.data.pending.forEach(adm => {
                        const fullName = `${adm.first_name || ''} ${adm.middle_name || ''} ${adm.last_name || ''}`.trim();
                        let admissionType = '';
                        switch(adm.admission_type) {
                            case 'emergency': admissionType = 'ድንገተኛ'; break;
                            case 'elective': admissionType = 'ቀጠሮ'; break;
                            case 'transfer': admissionType = 'ዝውውር'; break;
                            default: admissionType = 'ምልከታ';
                        }
                        const isCritical = ['critical', 'serious'].includes(adm.condition_status || '');
                        const conditionClass = isCritical ? 'critical' : '';
                        let conditionBadge = '';
                        if (adm.condition_status) {
                            conditionBadge = `<span class="condition-badge condition-${adm.condition_status}">${adm.condition_status.charAt(0).toUpperCase() + adm.condition_status.slice(1)}</span>`;
                        }
                        html += `
                            <div class="pending-item ${conditionClass}" data-admission-id="${adm.admission_id}">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <h6 class="mb-1">
                                            ${escapeHtml(fullName)}
                                            ${isCritical ? '<span class="condition-badge condition-critical"><i class="fas fa-exclamation-triangle"></i> CRITICAL</span>' : ''}
                                        </h6>
                                        <small class="text-muted">MRN: ${escapeHtml(adm.mr_number)} | ${escapeHtml(adm.gender)} | ${adm.age} yrs</small>
                                        <div class="mt-1">
                                            <span class="badge bg-light text-dark">${admissionType}</span>
                                            ${conditionBadge}
                                            <span class="badge bg-light text-muted">Dr. ${escapeHtml(adm.doctor_name)}</span>
                                        </div>
                                    </div>
                                    <button class="btn-assign assign-bed-btn" data-admission-id="${adm.admission_id}" data-patient-name="${escapeHtml(fullName)}">
                                        <i class="fas fa-bed"></i> አልጋ መድብ
                                    </button>
                                </div>
                            </div>
                        `;
                    });
                    container.innerHTML = html;
                    attachAssignBedButtons();
                }
            }
        }).catch(err => console.error('fetch pending error', err));
}

function attachAssignBedButtons() {
    document.querySelectorAll('.assign-bed-btn').forEach(btn => {
        btn.removeEventListener('click', assignBedClickHandler);
        btn.addEventListener('click', assignBedClickHandler);
    });
}

function assignBedClickHandler(e) {
    e.stopPropagation();
    const btn = e.currentTarget;
    selectedAdmissionId = parseInt(btn.getAttribute('data-admission-id'));
    selectedPatientName = btn.getAttribute('data-patient-name');
    document.querySelectorAll('.pending-item').forEach(el => el.classList.remove('active'));
    const parentItem = btn.closest('.pending-item');
    if (parentItem) parentItem.classList.add('active');
    showToast(`ታካሚ: ${selectedPatientName} - እባክዎ ከታች ካለው ካርታ አልጋ ይምረጡ / Select a bed from the map below`, 'info');
}

function loadBedMap(wardId) {
    showLoading(true);
    fetch(window.location.pathname + `?action=fetch_bed_map&ward_id=${wardId}`)
        .then(res => res.json())
        .then(data => {
            showLoading(false);
            if (data.success && data.data && data.data.beds) {
                renderBedGrid(data.data.beds);
                renderBedStats(data.data.beds);
            } else {
                document.getElementById('bedMapContainer').innerHTML = `<div class="empty-state"><i class="fas fa-exclamation-triangle fa-2x mb-2"></i><p>${data.message || 'Failed to load bed map'}</p></div>`;
                document.getElementById('bedStatsContainer').innerHTML = '';
            }
        })
        .catch(err => {
            showLoading(false);
            document.getElementById('bedMapContainer').innerHTML = `<div class="empty-state"><i class="fas fa-wifi fa-2x mb-2"></i><p>Network error. Please refresh.</p></div>`;
            console.error(err);
        });
}

function renderBedStats(beds) {
    const total = beds.length;
    const available = beds.filter(b => b.bed_status === 'available').length;
    const occupied = beds.filter(b => b.bed_status === 'occupied').length;
    const maintenance = beds.filter(b => b.bed_status === 'maintenance').length;
    const cleaning = beds.filter(b => b.bed_status === 'cleaning').length;
    const reserved = beds.filter(b => b.bed_status === 'reserved').length;
    
    document.getElementById('bedStatsContainer').innerHTML = `
        <span><strong>Total:</strong> ${total}</span>
        <span style="color:#059669;"><strong>Available:</strong> ${available}</span>
        <span style="color:#dc2626;"><strong>Occupied:</strong> ${occupied}</span>
        ${maintenance > 0 ? `<span style="color:#d97706;"><strong>Maintenance:</strong> ${maintenance}</span>` : ''}
        ${cleaning > 0 ? `<span style="color:#2563eb;"><strong>Cleaning:</strong> ${cleaning}</span>` : ''}
        ${reserved > 0 ? `<span style="color:#7c3aed;"><strong>Reserved:</strong> ${reserved}</span>` : ''}
    `;
}

function renderBedGrid(beds) {
    if (!beds.length) {
        document.getElementById('bedMapContainer').innerHTML = '<div class="empty-state"><i class="fas fa-bed fa-2x mb-2"></i><p>በዚህ ክፍል ውስጥ ምንም አልጋዎች የሉም / No beds in this ward</p></div>';
        return;
    }
    let html = '<div class="bed-grid">';
    beds.forEach(bed => {
        let statusClass = 'available';
        let statusText = 'ክፍት / Available';
        let statusBadge = 'status-available';
        let occupiedInfo = '';
        let clickable = true;
        
        const statusMap = {
            'available': { class: 'available', text: 'ክፍት / Available', badge: 'status-available', clickable: true },
            'occupied': { class: 'occupied', text: 'ተይዟል / Occupied', badge: 'status-occupied', clickable: false },
            'maintenance': { class: 'maintenance', text: 'በጥገና / Maintenance', badge: 'status-maintenance', clickable: false },
            'cleaning': { class: 'cleaning', text: 'በጽዳት / Cleaning', badge: 'status-cleaning', clickable: false },
            'reserved': { class: 'reserved', text: 'ተይዟል / Reserved', badge: 'status-reserved', clickable: false }
        };
        
        const info = statusMap[bed.bed_status] || statusMap['available'];
        statusClass = info.class;
        statusText = info.text;
        statusBadge = info.badge;
        clickable = info.clickable && selectedAdmissionId;
        
        if (bed.bed_status === 'occupied' && bed.patient_name) {
            const conditionClass = bed.condition_status ? `condition-${bed.condition_status}` : '';
            occupiedInfo = `
                <div class="patient-name">${escapeHtml(bed.patient_name)}</div>
                <div class="patient-mrn">MRN: ${escapeHtml(bed.mr_number)} | ${escapeHtml(bed.gender)} | ${bed.age} yrs</div>
                ${bed.condition_status ? `<span class="condition-badge ${conditionClass}">${escapeHtml(bed.condition_status)}</span>` : ''}
                <div style="margin-top:4px;">
                    <button class="btn-discharge" onclick="event.stopPropagation();openDischargeModal(${bed.admission_id}, '${escapeHtml(bed.patient_name)}')">
                        <i class="fas fa-sign-out-alt"></i> Discharge
                    </button>
                </div>
            `;
        } else if (bed.bed_status === 'occupied') {
            occupiedInfo = `<div class="patient-name text-danger">Occupied (data missing)</div>`;
        }
        
        const bedTypeDisplay = bed.bed_type ? `<span class="bed-type-badge">${escapeHtml(bed.bed_type)}</span>` : '';
        const onclickAttr = clickable ? `onclick="assignBed(${selectedAdmissionId}, ${bed.bed_id}, '${escapeHtml(bed.bed_number)}')"` : '';
        
        html += `
            <div class="bed-card ${statusClass}" data-bed-id="${bed.bed_id}" ${onclickAttr} style="${clickable ? 'cursor: pointer;' : ''}">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <span class="bed-number"><i class="fas fa-bed"></i> ${escapeHtml(bed.bed_number)}</span>
                        ${bedTypeDisplay}
                    </div>
                    <span class="bed-status ${statusBadge}">${statusText}</span>
                </div>
                ${occupiedInfo}
                ${bed.daily_rate ? `<small class="text-muted">${escapeHtml(bed.daily_rate)} ETB/day</small>` : ''}
                ${!clickable && selectedAdmissionId && bed.bed_status === 'available' ? '<small class="text-muted">(ለምደባ አይገኝም)</small>' : ''}
            </div>
        `;
    });
    html += '</div>';
    document.getElementById('bedMapContainer').innerHTML = html;
}

function assignBed(admissionId, bedId, bedNumber) {
    if (!admissionId || !bedId) return;
    if (!confirm(`ታካሚውን "${selectedPatientName}" አልጋ ቁጥር ${bedNumber} ላይ ማረፍ ይፈልጋሉ? / Assign patient "${selectedPatientName}" to bed ${bedNumber}?`)) return;

    showLoading(true);
    fetch(window.location.pathname + '?action=assign_bed', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({
            admission_id: admissionId,
            bed_id: bedId,
            csrf_token: csrfToken
        })
    })
    .then(res => res.json())
    .then(data => {
        showLoading(false);
        if (data.success) {
            showToast(data.message);
            selectedAdmissionId = null;
            selectedPatientName = null;
            loadPendingAdmissions();
            const currentWard = document.getElementById('wardSelect').value;
            if (currentWard) loadBedMap(currentWard);
        } else {
            showToast(data.message || 'Assignment failed', 'error');
        }
    })
    .catch(err => {
        showLoading(false);
        showToast('Network error', 'error');
        console.error(err);
    });
}

function openDischargeModal(admissionId, patientName) {
    document.getElementById('dischargeAdmissionId').value = admissionId;
    document.getElementById('dischargePatientName').textContent = patientName;
    document.getElementById('dischargeCondition').value = 'improved';
    document.getElementById('dischargeNotes').value = '';
    $('#dischargeModal').modal('show');
}

function confirmDischarge() {
    const admissionId = document.getElementById('dischargeAdmissionId').value;
    const condition = document.getElementById('dischargeCondition').value;
    const notes = document.getElementById('dischargeNotes').value;

    if (!admissionId) {
        showToast('Invalid admission', 'error');
        return;
    }

    if (!confirm('Are you sure you want to discharge this patient?')) return;

    showLoading(true);
    fetch(window.location.pathname + '?action=discharge_patient', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({
            admission_id: admissionId,
            discharge_condition: condition,
            discharge_notes: notes,
            csrf_token: csrfToken
        })
    })
    .then(res => res.json())
    .then(data => {
        showLoading(false);
        if (data.success) {
            $('#dischargeModal').modal('hide');
            showToast(data.message);
            loadPendingAdmissions();
            const currentWard = document.getElementById('wardSelect').value;
            if (currentWard) loadBedMap(currentWard);
        } else {
            showToast(data.message || 'Discharge failed', 'error');
        }
    })
    .catch(err => {
        showLoading(false);
        showToast('Network error', 'error');
        console.error(err);
    });
}

function onWardChange() {
    const wardId = document.getElementById('wardSelect').value;
    if (wardId) loadBedMap(wardId);
}

let autoRefreshInterval = null;
function startAutoRefresh() {
    if (autoRefreshInterval) clearInterval(autoRefreshInterval);
    autoRefreshInterval = setInterval(() => {
        const currentWard = document.getElementById('wardSelect').value;
        if (currentWard) loadBedMap(currentWard);
        loadPendingAdmissions();
    }, 30000);
}

document.addEventListener('DOMContentLoaded', () => {
    const wardSelect = document.getElementById('wardSelect');
    if (wardSelect.options.length > 0) {
        loadBedMap(wardSelect.value);
    } else {
        document.getElementById('bedMapContainer').innerHTML = '<div class="empty-state"><i class="fas fa-building fa-2x mb-2"></i><p>ምንም ክፍሎች አልተገኙም / No wards available</p></div>';
    }
    wardSelect.addEventListener('change', onWardChange);
    document.getElementById('refreshBedMapBtn').addEventListener('click', onWardChange);
    attachAssignBedButtons();
    startAutoRefresh();
});
</script>
</body>
</html>