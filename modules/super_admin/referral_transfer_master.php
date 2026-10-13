<?php
/**
 * Super Admin - Advanced Referral & Inter-Branch Transfer System
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

$user_id   = (int)($_SESSION['user_id'] ?? 0);
$user_name = $_SESSION['first_name'] ?? $_SESSION['username'] ?? 'Super Admin';

$db_available = is_db_available();
if (!$db_available) die('Database connection failed.');

$pdo = getDB();

// ============================================
// CREATE REQUIRED TABLES IF NOT EXIST
// ============================================
try {
    // Inter-branch transfers table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `inter_branch_transfers` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `patient_id` int(10) UNSIGNED NOT NULL,
            `source_branch_id` int(10) UNSIGNED NOT NULL,
            `destination_branch_id` int(10) UNSIGNED NOT NULL,
            `visit_id` int(10) UNSIGNED NOT NULL,
            `transfer_reason` text NOT NULL,
            `financial_status` enum('pending_clearance','cleared') NOT NULL DEFAULT 'pending_clearance',
            `status` enum('pending_approval','approved','rejected') NOT NULL DEFAULT 'pending_approval',
            `initiated_by` int(10) UNSIGNED NOT NULL,
            `approved_by` int(10) UNSIGNED DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            `deleted_at` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `patient_id` (`patient_id`),
            KEY `source_branch_id` (`source_branch_id`),
            KEY `destination_branch_id` (`destination_branch_id`),
            KEY `visit_id` (`visit_id`),
            KEY `initiated_by` (`initiated_by`),
            KEY `approved_by` (`approved_by`),
            CONSTRAINT `fk_transfer_patient` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_transfer_source_branch` FOREIGN KEY (`source_branch_id`) REFERENCES `branches` (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_transfer_dest_branch` FOREIGN KEY (`destination_branch_id`) REFERENCES `branches` (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_transfer_visit` FOREIGN KEY (`visit_id`) REFERENCES `patient_visits` (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_transfer_initiated_by` FOREIGN KEY (`initiated_by`) REFERENCES `users` (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_transfer_approved_by` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // Outbound referrals table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `outbound_referrals` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `branch_id` int(10) UNSIGNED NOT NULL,
            `patient_id` int(10) UNSIGNED NOT NULL,
            `referred_to_hospital` varchar(255) NOT NULL,
            `referred_by_doctor_id` int(10) UNSIGNED NOT NULL,
            `icd10_diagnosis_code` varchar(50) DEFAULT NULL,
            `clinical_reason` text NOT NULL,
            `urgency_level` enum('normal','urgent','critical') NOT NULL DEFAULT 'normal',
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            `deleted_at` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `branch_id` (`branch_id`),
            KEY `patient_id` (`patient_id`),
            KEY `referred_by_doctor_id` (`referred_by_doctor_id`),
            CONSTRAINT `fk_outbound_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_outbound_patient` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_outbound_doctor` FOREIGN KEY (`referred_by_doctor_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
} catch (PDOException $e) {
    error_log("Referral transfer table creation: " . $e->getMessage());
}

// ============================================
// AJAX ROUTER
// ============================================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'referral_action') {
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

    try {
        // ---------- GET PENDING INTER-BRANCH TRANSFERS ----------
        if ($action === 'get_pending_transfers') {
            $branch_filter = (int)($_POST['branch_id'] ?? 0);
            $sql = "SELECT t.id, t.patient_id, t.source_branch_id, t.destination_branch_id, t.visit_id,
                           t.transfer_reason, t.financial_status, t.status, t.created_at,
                           p.first_name, p.last_name, p.mr_number,
                           sb.name AS source_branch_name,
                           db.name AS dest_branch_name,
                           u.first_name AS init_first, u.last_name AS init_last
                    FROM inter_branch_transfers t
                    JOIN patients p ON t.patient_id = p.id
                    JOIN branches sb ON t.source_branch_id = sb.id
                    JOIN branches db ON t.destination_branch_id = db.id
                    JOIN users u ON t.initiated_by = u.id
                    WHERE t.status = 'pending_approval' AND t.deleted_at IS NULL";
            if ($branch_filter > 0) $sql .= " AND (t.source_branch_id = :bid OR t.destination_branch_id = :bid)";
            $stmt = $db->prepare($sql);
            if ($branch_filter > 0) $stmt->execute([':bid' => $branch_filter]);
            else $stmt->execute();
            $transfers = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'transfers' => $transfers]);
            exit;
        }

        // ---------- APPROVE INTER-BRANCH TRANSFER (Atomic Transaction) ----------
        if ($action === 'approve_branch_transfer') {
            $transfer_id = (int)($_POST['transfer_id'] ?? 0);
            if (!$transfer_id) {
                echo json_encode(['success' => false, 'message' => 'Invalid transfer ID.']);
                exit;
            }

            $db->beginTransaction();
            try {
                // Fetch transfer details with lock
                $stmt = $db->prepare("SELECT patient_id, destination_branch_id, visit_id FROM inter_branch_transfers WHERE id = :id AND status = 'pending_approval' FOR UPDATE");
                $stmt->execute([':id' => $transfer_id]);
                $transfer = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$transfer) {
                    throw new Exception('Transfer not found or already processed.');
                }

                // Update patient_visits branch_id to destination branch
                $updateVisit = $db->prepare("UPDATE patient_visits SET branch_id = :dest_branch, updated_at = NOW() WHERE id = :visit_id");
                $updateVisit->execute([':dest_branch' => $transfer['destination_branch_id'], ':visit_id' => $transfer['visit_id']]);

                // Update transfer status
                $updateTransfer = $db->prepare("UPDATE inter_branch_transfers SET status = 'approved', approved_by = :admin, updated_at = NOW() WHERE id = :id");
                $updateTransfer->execute([':admin' => $user_id, ':id' => $transfer_id]);

                // (Optional) Flag billing items as transferred - we can add a column 'transferred_to_branch' or just note
                // For accounting isolation, we could insert a record into a branch_transfer_ledger, but simplified here
                $db->commit();
                echo json_encode(['success' => true, 'message' => 'Patient transfer approved. Branch assignment updated.']);
            } catch (Exception $e) {
                $db->rollBack();
                echo json_encode(['success' => false, 'message' => 'Transfer approval failed: ' . $e->getMessage()]);
            }
            exit;
        }

        // ---------- GET OUTBOUND REFERRALS ----------
        if ($action === 'get_outbound_referrals') {
            $branch_filter = (int)($_POST['branch_id'] ?? 0);
            $sql = "SELECT r.id, r.referred_to_hospital, r.icd10_diagnosis_code, r.clinical_reason,
                           r.urgency_level, r.created_at,
                           p.first_name, p.last_name, p.mr_number,
                           b.name AS branch_name,
                           u.first_name AS doctor_first, u.last_name AS doctor_last
                    FROM outbound_referrals r
                    JOIN patients p ON r.patient_id = p.id
                    JOIN branches b ON r.branch_id = b.id
                    JOIN users u ON r.referred_by_doctor_id = u.id
                    WHERE r.deleted_at IS NULL";
            if ($branch_filter > 0) $sql .= " AND r.branch_id = :bid";
            $stmt = $db->prepare($sql);
            if ($branch_filter > 0) $stmt->execute([':bid' => $branch_filter]);
            else $stmt->execute();
            $referrals = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'referrals' => $referrals]);
            exit;
        }

        // ---------- GET REFERRAL STATISTICS (Analytics HUD) ----------
        if ($action === 'get_referral_stats') {
            $branch_filter = (int)($_POST['branch_id'] ?? 0);
            $current_month = date('m');
            $current_year = date('Y');

            // Total internal transfers approved this month
            $transSql = "SELECT COUNT(*) FROM inter_branch_transfers WHERE status = 'approved' AND MONTH(created_at) = :month AND YEAR(created_at) = :year";
            if ($branch_filter > 0) $transSql .= " AND (source_branch_id = :bid OR destination_branch_id = :bid)";
            $transStmt = $db->prepare($transSql);
            $transParams = [':month' => $current_month, ':year' => $current_year];
            if ($branch_filter > 0) $transParams[':bid'] = $branch_filter;
            $transStmt->execute($transParams);
            $total_transfers = (int)$transStmt->fetchColumn();

            // Total outbound referrals issued this month
            $refSql = "SELECT COUNT(*) FROM outbound_referrals WHERE MONTH(created_at) = :month AND YEAR(created_at) = :year";
            if ($branch_filter > 0) $refSql .= " AND branch_id = :bid";
            $refStmt = $db->prepare($refSql);
            $refParams = [':month' => $current_month, ':year' => $current_year];
            if ($branch_filter > 0) $refParams[':bid'] = $branch_filter;
            $refStmt->execute($refParams);
            $total_referrals = (int)$refStmt->fetchColumn();

            // Top receiving external hospital
            $topSql = "SELECT referred_to_hospital, COUNT(*) as cnt FROM outbound_referrals WHERE deleted_at IS NULL";
            if ($branch_filter > 0) $topSql .= " AND branch_id = :bid";
            $topSql .= " GROUP BY referred_to_hospital ORDER BY cnt DESC LIMIT 1";
            $topStmt = $db->prepare($topSql);
            if ($branch_filter > 0) $topStmt->execute([':bid' => $branch_filter]);
            else $topStmt->execute();
            $topHospital = $topStmt->fetch(PDO::FETCH_ASSOC);
            $top_hospital_name = $topHospital ? $topHospital['referred_to_hospital'] : 'N/A';

            // Primary diagnosis driving referrals (top ICD10 code)
            $diagSql = "SELECT icd10_diagnosis_code, COUNT(*) as cnt FROM outbound_referrals WHERE icd10_diagnosis_code IS NOT NULL AND icd10_diagnosis_code != ''";
            if ($branch_filter > 0) $diagSql .= " AND branch_id = :bid";
            $diagSql .= " GROUP BY icd10_diagnosis_code ORDER BY cnt DESC LIMIT 1";
            $diagStmt = $db->prepare($diagSql);
            if ($branch_filter > 0) $diagStmt->execute([':bid' => $branch_filter]);
            else $diagStmt->execute();
            $topDiag = $diagStmt->fetch(PDO::FETCH_ASSOC);
            $top_diagnosis = $topDiag ? $topDiag['icd10_diagnosis_code'] : 'N/A';

            echo json_encode([
                'success' => true,
                'total_transfers' => $total_transfers,
                'total_referrals' => $total_referrals,
                'top_hospital' => $top_hospital_name,
                'top_diagnosis' => $top_diagnosis
            ]);
            exit;
        }

        echo json_encode(['success' => false, 'message' => 'Unknown action']);
        exit;
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        exit;
    }
}

// ============================================
// BRANCH LIST FOR FILTER
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
    <title>Referral & Transfer Master | Super Admin | HMS ERP</title>
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
                        <div class="welcome-title">Advanced Referral & Inter-Branch Transfer System</div>
                        <div class="welcome-subtitle">Super Admin Console: Manage internal patient transfers, external referrals, and clinical quality analytics.</div>
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
                <div class="stat-card"><div class="stat-value" id="totalTransfers">0</div><div class="stat-label">Internal Transfers (MTD)</div></div>
                <div class="stat-card"><div class="stat-value" id="totalReferrals">0</div><div class="stat-label">External Referrals (MTD)</div></div>
                <div class="stat-card"><div class="stat-value" id="topHospital">--</div><div class="stat-label">Top Receiving Hospital</div></div>
                <div class="stat-card"><div class="stat-value" id="topDiagnosis">--</div><div class="stat-label">Primary Diagnosis</div></div>
            </div>

            <!-- Tabs -->
            <ul class="nav nav-tabs mb-4" id="transferTabs" role="tablist">
                <li class="nav-item"><button class="nav-link active" id="transfers-tab" data-bs-toggle="tab" data-bs-target="#transfers">Cross-Branch Transfer Hub</button></li>
                <li class="nav-item"><button class="nav-link" id="referrals-tab" data-bs-toggle="tab" data-bs-target="#referrals">Outbound Referrals Registry</button></li>
                <li class="nav-item"><button class="nav-link" id="analytics-tab" data-bs-toggle="tab" data-bs-target="#analytics">Clinical Quality Analytics</button></li>
            </ul>
            <div class="tab-content">
                <!-- INTER-BRANCH TRANSFER HUB -->
                <div class="tab-pane fade show active" id="transfers">
                    <div class="stat-card">
                        <h5 class="mb-3"><i class="fas fa-exchange-alt me-2"></i>Pending Inter-Branch Patient Transfers</h5>
                        <div class="table-responsive">
                            <table id="transfersTable" class="table table-hover" style="width:100%">
                                <thead>
                                    <tr><th>ID</th><th>Patient</th><th>MRN</th><th>Source Branch</th><th>Destination Branch</th><th>Reason</th><th>Financial Status</th><th>Requested By</th><th>Date</th><th>Actions</th></tr>
                                </thead>
                                <tbody><tr><td colspan="10" class="text-center">Loading...</td></tr></tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- OUTBOUND REFERRALS REGISTRY -->
                <div class="tab-pane fade" id="referrals">
                    <div class="stat-card">
                        <h5 class="mb-3"><i class="fas fa-external-link-alt me-2"></i>External Referral Log</h5>
                        <div class="table-responsive">
                            <table id="referralsTable" class="table table-hover" style="width:100%">
                                <thead>
                                    <tr><th>ID</th><th>Patient</th><th>MRN</th><th>Referred To</th><th>Doctor</th><th>ICD10 Code</th><th>Clinical Reason</th><th>Urgency</th><th>Branch</th><th>Date</th></tr>
                                </thead>
                                <tbody><tr><td colspan="10" class="text-center">Loading...</td></tr></tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- CLINICAL QUALITY ANALYTICS (Graphs/Insights) -->
                <div class="tab-pane fade" id="analytics">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="stat-card">
                                <h5><i class="fas fa-chart-pie me-2"></i>Referral by Urgency</h5>
                                <canvas id="urgencyChart" height="250"></canvas>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="stat-card">
                                <h5><i class="fas fa-hospital me-2"></i>Top 5 Referral Destinations</h5>
                                <canvas id="hospitalChart" height="250"></canvas>
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
<script src="assets/js/app.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
const csrfToken = "<?php echo $csrf_token; ?>";
let transfersTable, referralsTable, urgencyChart, hospitalChart;

$(function(){
    loadStats();
    loadTransfers();
    loadReferrals();
    loadAnalyticsCharts();
    $('#globalBranchFilter, #refreshAllBtn').on('change click', function(){ loadStats(); loadTransfers(); loadReferrals(); loadAnalyticsCharts(); });
});

function loadStats() {
    let branch = $('#globalBranchFilter').val();
    $.ajax({
        url: window.location.pathname + '?ajax=referral_action',
        method: 'POST',
        dataType: 'json',
        data: { action: 'get_referral_stats', branch_id: branch, csrf_token: csrfToken },
        success: function(res){
            if(res.success){
                $('#totalTransfers').text(res.total_transfers);
                $('#totalReferrals').text(res.total_referrals);
                $('#topHospital').text(res.top_hospital);
                $('#topDiagnosis').text(res.top_diagnosis);
            }
        }
    });
}

function loadTransfers() {
    let branch = $('#globalBranchFilter').val();
    showLoading(true);
    $.ajax({
        url: window.location.pathname + '?ajax=referral_action',
        method: 'POST',
        dataType: 'json',
        data: { action: 'get_pending_transfers', branch_id: branch, csrf_token: csrfToken },
        success: function(res){
            showLoading(false);
            if(res.success && res.transfers){
                if(transfersTable) transfersTable.destroy();
                let rows = '';
                res.transfers.forEach(t => {
                    rows += `<tr>
                                <td>${t.id}</td>
                                <td>${escapeHtml(t.first_name)} ${escapeHtml(t.last_name)}</td>
                                <td>${escapeHtml(t.mr_number)}</td>
                                <td>${escapeHtml(t.source_branch_name)}</td>
                                <td>${escapeHtml(t.dest_branch_name)}</td>
                                <td>${escapeHtml(t.transfer_reason)}</td>
                                <td><span class="badge bg-${t.financial_status === 'cleared' ? 'success' : 'warning'}">${t.financial_status}</span></td>
                                <td>${escapeHtml(t.init_first)} ${escapeHtml(t.init_last)}</td>
                                <td>${t.created_at}</td>
                                <td><button class="btn btn-sm btn-success approve-transfer" data-id="${t.id}"><i class="fas fa-check"></i> Approve Transfer</button>
                                    <button class="btn btn-sm btn-danger reject-transfer" data-id="${t.id}" disabled><i class="fas fa-times"></i> Reject (N/A)</button></td>
                            </tr>`;
                });
                $('#transfersTable tbody').html(rows);
                transfersTable = $('#transfersTable').DataTable({ order: [[0, 'desc']], pageLength: 10 });
                $('.approve-transfer').on('click', function(){ approveTransfer($(this).data('id')); });
            } else {
                $('#transfersTable tbody').html('<tr><td colspan="10">No pending transfers. </td></tr>');
            }
        },
        error: function(){ showLoading(false); Swal.fire('Error', 'Failed to load transfers', 'error'); }
    });
}

function approveTransfer(transferId){
    Swal.fire({
        title: 'Approve Transfer',
        text: 'This will move the patient to the destination branch and update their active visit. Proceed?',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Yes, approve'
    }).then(res => {
        if(res.isConfirmed){
            showLoading(true);
            $.ajax({
                url: window.location.pathname + '?ajax=referral_action',
                method: 'POST',
                dataType: 'json',
                data: { action: 'approve_branch_transfer', transfer_id: transferId, csrf_token: csrfToken },
                success: function(r){
                    showLoading(false);
                    if(r.success){
                        Swal.fire('Approved', r.message, 'success');
                        loadTransfers();
                        loadStats();
                    } else {
                        Swal.fire('Error', r.message, 'error');
                    }
                },
                error: function(){ showLoading(false); Swal.fire('Error', 'Approval failed', 'error'); }
            });
        }
    });
}

function loadReferrals() {
    let branch = $('#globalBranchFilter').val();
    $.ajax({
        url: window.location.pathname + '?ajax=referral_action',
        method: 'POST',
        dataType: 'json',
        data: { action: 'get_outbound_referrals', branch_id: branch, csrf_token: csrfToken },
        success: function(res){
            if(res.success && res.referrals){
                if(referralsTable) referralsTable.destroy();
                let rows = '';
                res.referrals.forEach(r => {
                    let urgencyBadge = r.urgency_level === 'critical' ? 'danger' : (r.urgency_level === 'urgent' ? 'warning' : 'info');
                    rows += `<tr>
                                <td>${r.id}</td>
                                <td>${escapeHtml(r.first_name)} ${escapeHtml(r.last_name)}</td>
                                <td>${escapeHtml(r.mr_number)}</td>
                                <td>${escapeHtml(r.referred_to_hospital)}</td>
                                <td>Dr. ${escapeHtml(r.doctor_first)} ${escapeHtml(r.doctor_last)}</td>
                                <td>${escapeHtml(r.icd10_diagnosis_code || '—')}</td>
                                <td>${escapeHtml(r.clinical_reason)}</td>
                                <td><span class="badge bg-${urgencyBadge}">${r.urgency_level}</span></td>
                                <td>${escapeHtml(r.branch_name)}</td>
                                <td>${r.created_at}</td>
                            </tr>`;
                });
                $('#referralsTable tbody').html(rows);
                referralsTable = $('#referralsTable').DataTable({ order: [[0, 'desc']], pageLength: 10 });
            } else {
                $('#referralsTable tbody').html('<tr><td colspan="10">No referrals found.</td></tr>');
            }
        }
    });
}

function loadAnalyticsCharts() {
    // Fetch urgency distribution and top hospitals via separate AJAX or compute from referrals table data
    let branch = $('#globalBranchFilter').val();
    $.ajax({
        url: window.location.pathname + '?ajax=referral_action',
        method: 'POST',
        dataType: 'json',
        data: { action: 'get_outbound_referrals', branch_id: branch, csrf_token: csrfToken },
        success: function(res){
            if(res.success && res.referrals){
                // Urgency counts
                let urgencyCount = { normal:0, urgent:0, critical:0 };
                let hospitalCount = {};
                res.referrals.forEach(r => {
                    urgencyCount[r.urgency_level]++;
                    hospitalCount[r.referred_to_hospital] = (hospitalCount[r.referred_to_hospital] || 0) + 1;
                });
                // Urgency chart
                if(urgencyChart) urgencyChart.destroy();
                const ctxUrgency = document.getElementById('urgencyChart').getContext('2d');
                urgencyChart = new Chart(ctxUrgency, {
                    type: 'pie',
                    data: { labels: ['Normal', 'Urgent', 'Critical'], datasets: [{ data: [urgencyCount.normal, urgencyCount.urgent, urgencyCount.critical], backgroundColor: ['#4cc9f0', '#f9c74f', '#ef476f'] }] },
                    options: { responsive: true }
                });
                // Top 5 hospitals
                let sortedHospitals = Object.entries(hospitalCount).sort((a,b) => b[1] - a[1]).slice(0,5);
                if(hospitalChart) hospitalChart.destroy();
                const ctxHospital = document.getElementById('hospitalChart').getContext('2d');
                hospitalChart = new Chart(ctxHospital, {
                    type: 'bar',
                    data: { labels: sortedHospitals.map(h => h[0]), datasets: [{ label: 'Referrals', data: sortedHospitals.map(h => h[1]), backgroundColor: '#4361ee' }] },
                    options: { responsive: true, scales: { y: { beginAtZero: true } } }
                });
            }
        }
    });
}

function escapeHtml(str) {
    if(!str) return '';
    return String(str).replace(/[&<>]/g, m => m === '&' ? '&amp;' : (m === '<' ? '&lt;' : '&gt;'));
}
function showLoading(show){ if(show) $('#loadingOverlay').addClass('active'); else $('#loadingOverlay').removeClass('active'); }
</script>
</body>
</html>