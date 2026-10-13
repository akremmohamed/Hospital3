<?php
/**
 * Super Admin - Vital Statistics & Legal Registries
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
// ENSURE REQUIRED WORKFLOW COLUMNS EXIST (extend schema safely)
// ============================================
try {
    // Add approval_status to birth_records if missing
    $checkBirth = $pdo->query("SHOW COLUMNS FROM `birth_records` LIKE 'approval_status'");
    if ($checkBirth->rowCount() == 0) {
        $pdo->exec("ALTER TABLE `birth_records` ADD COLUMN `approval_status` ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending' AFTER `status`");
        $pdo->exec("ALTER TABLE `birth_records` ADD COLUMN `approved_by_user_id` INT UNSIGNED NULL AFTER `approval_status`, ADD FOREIGN KEY (`approved_by_user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL");
    }
    // Add approval_status to death_records if missing
    $checkDeath = $pdo->query("SHOW COLUMNS FROM `death_records` LIKE 'approval_status'");
    if ($checkDeath->rowCount() == 0) {
        $pdo->exec("ALTER TABLE `death_records` ADD COLUMN `approval_status` ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending' AFTER `status`");
        $pdo->exec("ALTER TABLE `death_records` ADD COLUMN `approved_by_user_id` INT UNSIGNED NULL AFTER `approval_status`, ADD FOREIGN KEY (`approved_by_user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL");
    }
} catch (PDOException $e) {
    // Columns may already exist, ignore errors
}

// ============================================
// AJAX ROUTER
// ============================================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'vital_action') {
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

    // ---------- GET BIRTH REGISTRY (pending) ----------
    if ($action === 'get_birth_registry') {
        $branch_filter = (int)($_POST['branch_id'] ?? 0);
        $sql = "SELECT br.id, br.certificate_number, br.baby_name, br.baby_gender, br.baby_weight_kg,
                       br.birth_date, br.birth_time, br.delivery_type, br.approval_status,
                       p.id AS mother_id, CONCAT(p.first_name, ' ', p.last_name) AS mother_name,
                       u.id AS doctor_id, CONCAT(u.first_name, ' ', u.last_name) AS doctor_name,
                       br.created_at
                FROM birth_records br
                LEFT JOIN patients p ON br.mother_id = p.id
                LEFT JOIN users u ON br.attending_doctor_id = u.id
                WHERE br.approval_status = 'pending'";
        if ($branch_filter > 0) $sql .= " AND br.branch_id = :bid";
        $stmt = $db->prepare($sql);
        if ($branch_filter > 0) $stmt->execute([':bid' => $branch_filter]);
        else $stmt->execute();
        $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'records' => $records]);
        exit;
    }

    // ---------- APPROVE BIRTH RECORD & GENERATE CERTIFICATE ----------
    if ($action === 'approve_birth') {
        $record_id = (int)($_POST['record_id'] ?? 0);
        if (!$record_id) {
            echo json_encode(['success' => false, 'message' => 'Invalid record ID.']);
            exit;
        }

        // Fetch record
        $stmt = $db->prepare("SELECT branch_id, baby_name, birth_date FROM birth_records WHERE id = :id AND approval_status = 'pending'");
        $stmt->execute([':id' => $record_id]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$record) {
            echo json_encode(['success' => false, 'message' => 'Record not found or already processed.']);
            exit;
        }

        // Generate unique certificate number: BRTH-YYYY-XXXXX
        $year = date('Y', strtotime($record['birth_date']));
        $counter = $db->query("SELECT COUNT(*) + 1 FROM birth_records WHERE YEAR(birth_date) = $year")->fetchColumn();
        $cert_number = 'BRTH-' . $year . '-' . str_pad($counter, 5, '0', STR_PAD_LEFT);

        $db->beginTransaction();
        try {
            $update = $db->prepare("UPDATE birth_records SET approval_status = 'approved', approved_by_user_id = :uid, certificate_number = :cert, updated_at = NOW() WHERE id = :id");
            $update->execute([':uid' => $user_id, ':cert' => $cert_number, ':id' => $record_id]);
            $db->commit();
            echo json_encode(['success' => true, 'message' => 'Birth record approved and certificate generated.', 'certificate_number' => $cert_number]);
        } catch (Exception $e) {
            $db->rollBack();
            echo json_encode(['success' => false, 'message' => 'Approval failed: ' . $e->getMessage()]);
        }
        exit;
    }

    // ---------- GET DEATH REGISTRY (pending) ----------
    if ($action === 'get_death_registry') {
        $branch_filter = (int)($_POST['branch_id'] ?? 0);
        $sql = "SELECT dr.id, dr.certificate_number, dr.death_date, dr.death_time, dr.cause_of_death,
                       dr.immediate_cause, dr.manner_of_death, dr.approval_status,
                       p.id AS patient_id, CONCAT(p.first_name, ' ', p.last_name) AS deceased_name, p.mr_number,
                       u.id AS doctor_id, CONCAT(u.first_name, ' ', u.last_name) AS certifying_doctor,
                       dr.created_at
                FROM death_records dr
                LEFT JOIN patients p ON dr.patient_id = p.id
                LEFT JOIN users u ON dr.certifying_doctor_id = u.id
                WHERE dr.approval_status = 'pending'";
        if ($branch_filter > 0) $sql .= " AND dr.branch_id = :bid";
        $stmt = $db->prepare($sql);
        if ($branch_filter > 0) $stmt->execute([':bid' => $branch_filter]);
        else $stmt->execute();
        $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'records' => $records]);
        exit;
    }

    // ---------- APPROVE DEATH RECORD, GENERATE CERTIFICATE, DEACTIVATE PATIENT ----------
    if ($action === 'approve_death') {
        $record_id = (int)($_POST['record_id'] ?? 0);
        if (!$record_id) {
            echo json_encode(['success' => false, 'message' => 'Invalid record ID.']);
            exit;
        }

        // Fetch record
        $stmt = $db->prepare("SELECT branch_id, patient_id, death_date FROM death_records WHERE id = :id AND approval_status = 'pending'");
        $stmt->execute([':id' => $record_id]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$record) {
            echo json_encode(['success' => false, 'message' => 'Record not found or already processed.']);
            exit;
        }

        // Generate unique certificate number: DTH-YYYY-XXXXX
        $year = date('Y', strtotime($record['death_date']));
        $counter = $db->query("SELECT COUNT(*) + 1 FROM death_records WHERE YEAR(death_date) = $year")->fetchColumn();
        $cert_number = 'DTH-' . $year . '-' . str_pad($counter, 5, '0', STR_PAD_LEFT);

        $db->beginTransaction();
        try {
            // Update death record
            $update = $db->prepare("UPDATE death_records SET approval_status = 'approved', approved_by_user_id = :uid, certificate_number = :cert, updated_at = NOW() WHERE id = :id");
            $update->execute([':uid' => $user_id, ':cert' => $cert_number, ':id' => $record_id]);

            // Deactivate patient (prevent future appointments/check-ins)
            $deactivate = $db->prepare("UPDATE patients SET is_active = 0, updated_at = NOW() WHERE id = :pid");
            $deactivate->execute([':pid' => $record['patient_id']]);

            $db->commit();
            echo json_encode(['success' => true, 'message' => 'Death record approved, patient deactivated, certificate generated.', 'certificate_number' => $cert_number]);
        } catch (Exception $e) {
            $db->rollBack();
            echo json_encode(['success' => false, 'message' => 'Approval failed: ' . $e->getMessage()]);
        }
        exit;
    }

    // ---------- GET LEGAL STATISTICS (Dashboard HUD) ----------
    if ($action === 'get_legal_stats') {
        $branch_filter = (int)($_POST['branch_id'] ?? 0);
        $current_year = date('Y');

        // Total births certified (approved)
        $birthSql = "SELECT COUNT(*) FROM birth_records WHERE approval_status = 'approved' AND YEAR(birth_date) = :year";
        if ($branch_filter > 0) $birthSql .= " AND branch_id = :bid";
        $birthStmt = $db->prepare($birthSql);
        $birthParams = [':year' => $current_year];
        if ($branch_filter > 0) $birthParams[':bid'] = $branch_filter;
        $birthStmt->execute($birthParams);
        $total_births = (int)$birthStmt->fetchColumn();

        // Total deaths documented (approved)
        $deathSql = "SELECT COUNT(*) FROM death_records WHERE approval_status = 'approved' AND YEAR(death_date) = :year";
        if ($branch_filter > 0) $deathSql .= " AND branch_id = :bid";
        $deathStmt = $db->prepare($deathSql);
        $deathParams = [':year' => $current_year];
        if ($branch_filter > 0) $deathParams[':bid'] = $branch_filter;
        $deathStmt->execute($deathParams);
        $total_deaths = (int)$deathStmt->fetchColumn();

        // Average birth weight (kg)
        $weightSql = "SELECT AVG(baby_weight_kg) FROM birth_records WHERE approval_status = 'approved' AND baby_weight_kg IS NOT NULL";
        if ($branch_filter > 0) $weightSql .= " AND branch_id = :bid";
        $weightStmt = $db->prepare($weightSql);
        if ($branch_filter > 0) $weightStmt->execute([':bid' => $branch_filter]);
        else $weightStmt->execute();
        $avg_weight = round((float)$weightStmt->fetchColumn(), 2);

        // Top 5 leading causes of death
        $causeSql = "SELECT cause_of_death, COUNT(*) as total FROM death_records WHERE approval_status = 'approved' AND cause_of_death IS NOT NULL";
        if ($branch_filter > 0) $causeSql .= " AND branch_id = :bid";
        $causeSql .= " GROUP BY cause_of_death ORDER BY total DESC LIMIT 5";
        $causeStmt = $db->prepare($causeSql);
        if ($branch_filter > 0) $causeStmt->execute([':bid' => $branch_filter]);
        else $causeStmt->execute();
        $top_causes = $causeStmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'total_births' => $total_births,
            'total_deaths' => $total_deaths,
            'avg_birth_weight' => $avg_weight,
            'top_causes' => $top_causes
        ]);
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
    <title>Vital Statistics & Legal Registries | Super Admin | HMS ERP</title>
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
        .filter-bar { background: white; border-radius: 20px; padding: 16px 24px; margin-bottom: 24px; display: flex; gap: 16px; flex-wrap: wrap; align-items: flex-end; }
        .stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; margin-bottom: 28px; }
        .stat-card { background: white; border-radius: 20px; padding: 20px; border-left: 5px solid var(--primary); transition: all 0.2s; }
        .stat-value { font-size: 32px; font-weight: 800; color: var(--dark); }
        .stat-label { font-size: 13px; color: var(--gray); }
        .quick-btn { padding: 10px 20px; border-radius: 14px; font-size: 14px; font-weight: 600; border: none; cursor: pointer; transition: all 0.2s; }
        .quick-btn-primary { background: linear-gradient(135deg, var(--primary), var(--primary-dark)); color: white; }
        .quick-btn-outline { background: white; border: 1px solid var(--gray-light); color: var(--dark); }
        .loading-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(255,255,255,0.7); z-index: 2000; display: none; align-items: center; justify-content: center; }
        .loading-overlay.active { display: flex; }
        .spinner { width: 40px; height: 40px; border: 3px solid var(--gray-light); border-top-color: var(--primary); border-radius: 50%; animation: spin 0.8s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }
        /* Print styles for certificate */
        @media print {
            body * { visibility: hidden; }
            .certificate-print-area, .certificate-print-area * { visibility: visible; }
            .certificate-print-area { position: absolute; top: 0; left: 0; width: 100%; margin: 0; padding: 20px; }
            .no-print { display: none !important; }
        }
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
                        <div class="welcome-title">Vital Statistics & Legal Registries</div>
                        <div class="welcome-subtitle">Super Admin Console: Birth & death certification, legal document issuance, civil registration analytics.</div>
                    </div>
                    <div class="col-md-4 text-md-end mt-3 mt-md-0">
                        <div class="time-badge" style="background: rgba(255,255,255,0.15); padding: 8px 16px; border-radius: 12px;">
                            <i class="far fa-calendar-alt"></i> <?php echo htmlspecialchars($current_date_display); ?>
                            <i class="far fa-clock ms-2"></i> <?php echo htmlspecialchars($current_time_display); ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Branch Filter -->
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
                    <button class="quick-btn quick-btn-primary" id="refreshAllBtn"><i class="fas fa-sync-alt me-2"></i>Refresh Data</button>
                </div>
            </div>

            <!-- Analytics HUD -->
            <div class="stats-grid" id="statsContainer">
                <div class="stat-card"><div class="stat-value" id="totalBirths">0</div><div class="stat-label">Total Births Certified (Year)</div></div>
                <div class="stat-card"><div class="stat-value" id="totalDeaths">0</div><div class="stat-label">Total Deaths Documented (Year)</div></div>
                <div class="stat-card"><div class="stat-value" id="avgBirthWeight">0 kg</div><div class="stat-label">Average Birth Weight</div></div>
                <div class="stat-card"><div class="stat-value" id="topCause">--</div><div class="stat-label">Leading Cause of Death</div></div>
            </div>

            <!-- Tabs -->
            <ul class="nav nav-tabs mb-4" id="vitalTabs" role="tablist">
                <li class="nav-item"><button class="nav-link active" id="birth-tab" data-bs-toggle="tab" data-bs-target="#birth">Maternity Birth Approvals</button></li>
                <li class="nav-item"><button class="nav-link" id="death-tab" data-bs-toggle="tab" data-bs-target="#death">Mortuary Legal Death Registry</button></li>
            </ul>
            <div class="tab-content">
                <!-- BIRTH REGISTRY TAB -->
                <div class="tab-pane fade show active" id="birth">
                    <div class="stat-card">
                        <h5 class="mb-3"><i class="fas fa-baby-carriage me-2"></i>Pending Birth Registrations</h5>
                        <div class="table-responsive">
                            <table id="birthTable" class="table table-hover" style="width:100%">
                                <thead>
                                    <tr><th>ID</th><th>Mother Name</th><th>Child Name</th><th>Gender</th><th>Weight (kg)</th><th>Birth Date/Time</th><th>Delivery Type</th><th>Attending Doctor</th><th>Actions</th></tr>
                                </thead>
                                <tbody><tr><td colspan="9" class="text-center">Loading...</td></tr></tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- DEATH REGISTRY TAB -->
                <div class="tab-pane fade" id="death">
                    <div class="stat-card">
                        <h5 class="mb-3"><i class="fas fa-skull me-2"></i>Pending Death Certifications</h5>
                        <div class="table-responsive">
                            <table id="deathTable" class="table table-hover" style="width:100%">
                                <thead>
                                    <tr><th>ID</th><th>Deceased Name</th><th>MRN</th><th>Date/Time of Death</th><th>Primary Cause</th><th>Immediate Cause</th><th>Certifying Doctor</th><th>Actions</th></tr>
                                </thead>
                                <tbody><tr><td colspan="8" class="text-center">Loading...</td></tr></tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php include('assets/inc/footer.php'); ?>
</div>

<div id="loadingOverlay" class="loading-overlay"><div class="spinner"></div></div>

<!-- MODAL: Certificate Display (Print) -->
<div id="certificateModal" class="modal fade" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 id="certificateTitle">Legal Certificate</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="certificateContent"></div>
            <div class="modal-footer">
                <button class="quick-btn quick-btn-outline" data-bs-dismiss="modal">Close</button>
                <button class="quick-btn quick-btn-primary" id="printCertificateBtn"><i class="fas fa-print me-2"></i>Print Certificate</button>
            </div>
        </div>
    </div>
</div>

<script src="assets/js/vendor.min.js"></script>
<script src="assets/js/app.min.js"></script>
<script>
const csrfToken = "<?php echo $csrf_token; ?>";
let birthTable, deathTable;

$(function(){
    loadStats();
    loadBirthRegistry();
    loadDeathRegistry();
    $('#globalBranchFilter, #refreshAllBtn').on('change click', function(){ loadStats(); loadBirthRegistry(); loadDeathRegistry(); });
    $('#printCertificateBtn').on('click', function(){ window.print(); });
});

function loadStats() {
    let branch = $('#globalBranchFilter').val();
    $.ajax({ url: window.location.pathname + '?ajax=vital_action', method: 'POST', dataType: 'json', data: { action: 'get_legal_stats', branch_id: branch, csrf_token: csrfToken }, success: function(res){
        if(res.success){
            $('#totalBirths').text(res.total_births);
            $('#totalDeaths').text(res.total_deaths);
            $('#avgBirthWeight').text(res.avg_birth_weight + ' kg');
            if(res.top_causes && res.top_causes.length > 0){
                $('#topCause').text(res.top_causes[0].cause_of_death.substring(0, 30));
            } else { $('#topCause').text('N/A'); }
        }
    }});
}

function loadBirthRegistry() {
    let branch = $('#globalBranchFilter').val();
    $.ajax({ url: window.location.pathname + '?ajax=vital_action', method: 'POST', dataType: 'json', data: { action: 'get_birth_registry', branch_id: branch, csrf_token: csrfToken }, success: function(res){
        if(res.success && res.records){
            let rows = '';
            res.records.forEach(r => {
                let birthDateTime = r.birth_date + ' ' + (r.birth_time || '00:00:00');
                rows += `<tr>
                            <td>${r.id}</td>
                            <td>${escapeHtml(r.mother_name)}</td>
                            <td>${escapeHtml(r.baby_name || 'Unnamed')}</td>
                            <td>${r.baby_gender}</td>
                            <td>${r.baby_weight_kg ? parseFloat(r.baby_weight_kg).toFixed(2) : '—'}</td>
                            <td>${birthDateTime}</td>
                            <td>${r.delivery_type}</td>
                            <td>${escapeHtml(r.doctor_name)}</td>
                            <td><button class="btn btn-sm btn-success approve-birth" data-id="${r.id}" data-name="${escapeHtml(r.baby_name || 'Child')}"><i class="fas fa-check"></i> Approve & Issue Certificate</button>
                                <button class="btn btn-sm btn-info view-birth-cert" data-id="${r.id}" data-type="birth" style="display:none;">View</button>
                             </td>
                          </tr>`;
            });
            $('#birthTable tbody').html(rows);
            $('.approve-birth').on('click', function(){ approveBirth($(this).data('id'), $(this).data('name')); });
        } else { $('#birthTable tbody').html('<tr><td colspan="9">No pending birth registrations.</td></tr>'); }
    }});
}

function approveBirth(recordId, childName){
    Swal.fire({ title: 'Approve Birth Registration', text: `Issue legal birth certificate for ${childName}?`, icon: 'question', showCancelButton: true }).then(res=>{
        if(res.isConfirmed){
            showLoading(true);
            $.ajax({ url: window.location.pathname + '?ajax=vital_action', method: 'POST', dataType: 'json', data: { action: 'approve_birth', record_id: recordId, csrf_token: csrfToken }, success: function(r){
                showLoading(false);
                if(r.success){
                    Swal.fire('Success', r.message, 'success');
                    loadBirthRegistry();
                    loadStats();
                    // Optionally show certificate
                    showCertificate(recordId, 'birth', r.certificate_number);
                } else { Swal.fire('Error', r.message, 'error'); }
            }, error: function(){ showLoading(false); Swal.fire('Error', 'Network error', 'error'); } });
        }
    });
}

function loadDeathRegistry() {
    let branch = $('#globalBranchFilter').val();
    $.ajax({ url: window.location.pathname + '?ajax=vital_action', method: 'POST', dataType: 'json', data: { action: 'get_death_registry', branch_id: branch, csrf_token: csrfToken }, success: function(res){
        if(res.success && res.records){
            let rows = '';
            res.records.forEach(r => {
                let deathDateTime = r.death_date + ' ' + (r.death_time || '00:00:00');
                rows += `<tr>
                            <td>${r.id}</td>
                            <td>${escapeHtml(r.deceased_name)}</td>
                            <td>${escapeHtml(r.mr_number)}</td>
                            <td>${deathDateTime}</td>
                            <td>${escapeHtml(r.cause_of_death)}</td>
                            <td>${escapeHtml(r.immediate_cause || '—')}</td>
                            <td>${escapeHtml(r.certifying_doctor)}</td>
                            <td><button class="btn btn-sm btn-success approve-death" data-id="${r.id}" data-name="${escapeHtml(r.deceased_name)}"><i class="fas fa-check"></i> Approve & Deactivate Patient</button>
                             </td>
                          </tr>`;
            });
            $('#deathTable tbody').html(rows);
            $('.approve-death').on('click', function(){ approveDeath($(this).data('id'), $(this).data('name')); });
        } else { $('#deathTable tbody').html('<tr><td colspan="8">No pending death registrations.</td></tr>'); }
    }});
}

function approveDeath(recordId, deceasedName){
    Swal.fire({ title: 'Approve Death Record', text: `Legalize death certificate for ${deceasedName}. This will deactivate the patient account.`, icon: 'warning', showCancelButton: true }).then(res=>{
        if(res.isConfirmed){
            showLoading(true);
            $.ajax({ url: window.location.pathname + '?ajax=vital_action', method: 'POST', dataType: 'json', data: { action: 'approve_death', record_id: recordId, csrf_token: csrfToken }, success: function(r){
                showLoading(false);
                if(r.success){
                    Swal.fire('Success', r.message, 'success');
                    loadDeathRegistry();
                    loadStats();
                    showCertificate(recordId, 'death', r.certificate_number);
                } else { Swal.fire('Error', r.message, 'error'); }
            }, error: function(){ showLoading(false); Swal.fire('Error', 'Network error', 'error'); } });
        }
    });
}

function showCertificate(recordId, type, certNumber){
    // Fetch full record details via AJAX to populate modal
    let action = (type === 'birth') ? 'get_birth_record' : 'get_death_record';
    $.ajax({ url: window.location.pathname + '?ajax=vital_action', method: 'POST', dataType: 'json', data: { action: action, record_id: recordId, csrf_token: csrfToken }, success: function(res){
        if(res.success){
            let html = `<div class="certificate-print-area" style="border:2px solid #333; padding:20px; margin:10px; font-family:serif;">
                            <h2 style="text-align:center;">${type === 'birth' ? 'BIRTH CERTIFICATE' : 'DEATH CERTIFICATE'}</h2>
                            <hr>`;
            if(type === 'birth'){
                html += `<p><strong>Certificate Number:</strong> ${certNumber}</p>
                         <p><strong>Child's Name:</strong> ${escapeHtml(res.baby_name || 'Not named')}</p>
                         <p><strong>Gender:</strong> ${res.baby_gender}</p>
                         <p><strong>Birth Weight:</strong> ${res.baby_weight_kg} kg</p>
                         <p><strong>Date & Time of Birth:</strong> ${res.birth_date} ${res.birth_time}</p>
                         <p><strong>Mother's Name:</strong> ${escapeHtml(res.mother_name)}</p>
                         <p><strong>Attending Doctor:</strong> ${escapeHtml(res.doctor_name)}</p>
                         <p><strong>Place of Birth:</strong> ${escapeHtml(res.place_of_birth)}</p>`;
            } else {
                html += `<p><strong>Certificate Number:</strong> ${certNumber}</p>
                         <p><strong>Deceased Name:</strong> ${escapeHtml(res.deceased_name)}</p>
                         <p><strong>Date & Time of Death:</strong> ${res.death_date} ${res.death_time}</p>
                         <p><strong>Primary Cause:</strong> ${escapeHtml(res.cause_of_death)}</p>
                         <p><strong>Immediate Cause:</strong> ${escapeHtml(res.immediate_cause || 'N/A')}</p>
                         <p><strong>Certifying Doctor:</strong> ${escapeHtml(res.doctor_name)}</p>
                         <p><strong>Manner of Death:</strong> ${res.manner_of_death}</p>`;
            }
            html += `<hr><div style="text-align:center; margin-top:30px;">
                        <p>Issued by HMS ERP System - Authorized Signature</p>
                        <p>Date of Issue: ${new Date().toLocaleDateString()}</p>
                     </div></div>`;
            $('#certificateContent').html(html);
            $('#certificateModal').modal('show');
        }
    }});
}

function escapeHtml(str) { if(!str) return ''; return String(str).replace(/[&<>]/g, m => m === '&' ? '&amp;' : (m === '<' ? '&lt;' : '&gt;')); }
function showLoading(show){ if(show) $('#loadingOverlay').addClass('active'); else $('#loadingOverlay').removeClass('active'); }
</script>
</body>
</html>