<?php
/**
 * Super Admin - Advanced Insurance Credentialing & Claims Desk
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
// AJAX ROUTER
// ============================================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'claims_action') {
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
        // ---------- GET PRE-AUTHORIZATION QUEUE ----------
        if ($action === 'get_pre_auth_queue') {
            $branch_filter = (int)($_POST['branch_id'] ?? 0);
            $sql = "SELECT ic.id AS claim_id, i.id AS invoice_id, i.invoice_number, i.total_amount, i.billing_type,
                           p.id AS patient_id, CONCAT(p.first_name, ' ', p.last_name) AS patient_name, p.mr_number,
                           ip.name AS provider_name, ic.package_id, icp.package_name, ic.claimed_amount, ic.claim_status,
                           i.invoice_date
                    FROM insurance_claims ic
                    JOIN invoices i ON ic.invoice_id = i.id
                    JOIN patients p ON i.patient_id = p.id
                    JOIN insurance_providers ip ON ic.provider_id = ip.id
                    LEFT JOIN insurance_packages icp ON ic.package_id = icp.id
                    WHERE ic.claim_status = 'pending_pre_auth'";
            if ($branch_filter > 0) $sql .= " AND i.branch_id = :bid";
            $stmt = $db->prepare($sql);
            if ($branch_filter > 0) $stmt->execute([':bid' => $branch_filter]);
            else $stmt->execute();
            $queue = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'queue' => $queue]);
            exit;
        }

        // ---------- APPROVE PRE-AUTHORIZATION ----------
        if ($action === 'approve_pre_auth') {
            $claim_id = (int)($_POST['claim_id'] ?? 0);
            if (!$claim_id) {
                echo json_encode(['success' => false, 'message' => 'Invalid claim ID.']);
                exit;
            }
            $db->beginTransaction();
            try {
                $stmt = $db->prepare("SELECT claim_status FROM insurance_claims WHERE id = :id FOR UPDATE");
                $stmt->execute([':id' => $claim_id]);
                $claim = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$claim || $claim['claim_status'] !== 'pending_pre_auth') {
                    throw new Exception('Claim not found or already processed.');
                }
                $auth_code = 'AUTH-' . strtoupper(uniqid() . bin2hex(random_bytes(4)));
                $update = $db->prepare("UPDATE insurance_claims SET claim_status = 'pre_auth_approved', pre_auth_code = :code, updated_at = NOW() WHERE id = :id");
                $update->execute([':code' => $auth_code, ':id' => $claim_id]);
                $db->commit();
                echo json_encode(['success' => true, 'message' => 'Pre-authorization approved. Code: ' . $auth_code]);
            } catch (Exception $e) {
                $db->rollBack();
                echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            }
            exit;
        }

        // ---------- GET REJECTION LEDGER ----------
        if ($action === 'get_rejection_ledger') {
            $branch_filter = (int)($_POST['branch_id'] ?? 0);
            $sql = "SELECT ic.id AS claim_id, i.id AS invoice_id, i.invoice_number, i.total_amount,
                           p.id AS patient_id, CONCAT(p.first_name, ' ', p.last_name) AS patient_name, p.mr_number,
                           ip.name AS provider_name, ic.claimed_amount, ic.rejection_reason, ic.resubmission_count,
                           i.invoice_date
                    FROM insurance_claims ic
                    JOIN invoices i ON ic.invoice_id = i.id
                    JOIN patients p ON i.patient_id = p.id
                    JOIN insurance_providers ip ON ic.provider_id = ip.id
                    WHERE ic.claim_status = 'rejected'";
            if ($branch_filter > 0) $sql .= " AND i.branch_id = :bid";
            $stmt = $db->prepare($sql);
            if ($branch_filter > 0) $stmt->execute([':bid' => $branch_filter]);
            else $stmt->execute();
            $rejections = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'rejections' => $rejections]);
            exit;
        }

        // ---------- RESUBMIT CLAIM CORRECTION ----------
        if ($action === 'resubmit_claim_correction') {
            $claim_id = (int)($_POST['claim_id'] ?? 0);
            $new_claimed_amount = (float)($_POST['claimed_amount'] ?? 0);
            $package_id = (int)($_POST['package_id'] ?? 0);
            if (!$claim_id || $new_claimed_amount <= 0) {
                echo json_encode(['success' => false, 'message' => 'Invalid claim or amount.']);
                exit;
            }
            $db->beginTransaction();
            try {
                $stmt = $db->prepare("SELECT claim_status, resubmission_count FROM insurance_claims WHERE id = :id FOR UPDATE");
                $stmt->execute([':id' => $claim_id]);
                $claim = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$claim || $claim['claim_status'] !== 'rejected') {
                    throw new Exception('Only rejected claims can be resubmitted.');
                }
                $new_count = $claim['resubmission_count'] + 1;
                $update = $db->prepare("UPDATE insurance_claims SET claimed_amount = :amount, package_id = :pkg, claim_status = 'submitted', rejection_reason = NULL, resubmission_count = :count, updated_at = NOW() WHERE id = :id");
                $update->execute([':amount' => $new_claimed_amount, ':pkg' => $package_id ?: null, ':count' => $new_count, ':id' => $claim_id]);
                $db->commit();
                echo json_encode(['success' => true, 'message' => 'Claim resubmitted successfully.']);
            } catch (Exception $e) {
                $db->rollBack();
                echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            }
            exit;
        }

        // ---------- GET INSURANCE KPIs ----------
        if ($action === 'get_insurance_kpis') {
            $branch_filter = (int)($_POST['branch_id'] ?? 0);
            $pendingAuthSql = "SELECT COUNT(*) FROM insurance_claims WHERE claim_status = 'pending_pre_auth'";
            $rejectedSql = "SELECT COUNT(*) FROM insurance_claims WHERE claim_status = 'rejected'";
            $settledSumSql = "SELECT COALESCE(SUM(claimed_amount), 0) FROM insurance_claims WHERE claim_status = 'settled'";
            $totalSubmittedSql = "SELECT COUNT(*) FROM insurance_claims WHERE claim_status IN ('submitted', 'pre_auth_approved', 'billed', 'settled', 'rejected')";
            if ($branch_filter > 0) {
                $pendingAuthSql .= " AND branch_id = (SELECT branch_id FROM invoices WHERE id = insurance_claims.invoice_id)";
                $rejectedSql .= " AND branch_id = (SELECT branch_id FROM invoices WHERE id = insurance_claims.invoice_id)";
                $settledSumSql .= " AND branch_id = (SELECT branch_id FROM invoices WHERE id = insurance_claims.invoice_id)";
                $totalSubmittedSql .= " AND branch_id = (SELECT branch_id FROM invoices WHERE id = insurance_claims.invoice_id)";
            }
            $pendingAuth = (int)$db->query($pendingAuthSql)->fetchColumn();
            $rejected = (int)$db->query($rejectedSql)->fetchColumn();
            $settledSum = (float)$db->query($settledSumSql)->fetchColumn();
            $totalSubmitted = (int)$db->query($totalSubmittedSql)->fetchColumn();
            $denialRate = ($totalSubmitted > 0) ? round(($rejected / $totalSubmitted) * 100, 2) : 0;
            echo json_encode([
                'success' => true,
                'pending_pre_auth' => $pendingAuth,
                'rejected_claims' => $rejected,
                'settled_amount' => number_format($settledSum, 2),
                'denial_rate' => $denialRate
            ]);
            exit;
        }

        // ---------- GET SETTLED CLAIMS HISTORY ----------
        if ($action === 'get_settled_claims') {
            $branch_filter = (int)($_POST['branch_id'] ?? 0);
            $sql = "SELECT ic.id AS claim_id, i.id AS invoice_id, i.invoice_number, i.total_amount,
                           p.id AS patient_id, CONCAT(p.first_name, ' ', p.last_name) AS patient_name, p.mr_number,
                           ip.name AS provider_name, ic.claimed_amount, ic.pre_auth_code, ic.claim_status, ic.updated_at AS settled_date
                    FROM insurance_claims ic
                    JOIN invoices i ON ic.invoice_id = i.id
                    JOIN patients p ON i.patient_id = p.id
                    JOIN insurance_providers ip ON ic.provider_id = ip.id
                    WHERE ic.claim_status = 'settled'";
            if ($branch_filter > 0) $sql .= " AND i.branch_id = :bid";
            $sql .= " ORDER BY ic.updated_at DESC";
            $stmt = $db->prepare($sql);
            if ($branch_filter > 0) $stmt->execute([':bid' => $branch_filter]);
            else $stmt->execute();
            $settled = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'settled' => $settled]);
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
    <title>Insurance Claims Desk | Super Admin | HMS ERP</title>
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
        .quick-btn-danger { background: linear-gradient(135deg, var(--danger), #c0392b); color: white; }
        .loading-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(255,255,255,0.7); z-index: 2000; display: none; align-items: center; justify-content: center; }
        .loading-overlay.active { display: flex; }
        .spinner { width: 40px; height: 40px; border: 3px solid var(--gray-light); border-top-color: var(--primary); border-radius: 50%; animation: spin 0.8s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }
        .rejection-badge { background: #fee2e2; color: #ef476f; padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 600; }
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
                        <div class="welcome-title">Advanced Insurance Credentialing & Claims Desk</div>
                        <div class="welcome-subtitle">Super Admin Console: Pre-authorization, rejection management, claim telemetry.</div>
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
            <div class="stats-grid" id="kpiContainer">
                <div class="stat-card"><div class="stat-value" id="pendingPreAuth">0</div><div class="stat-label">Pending Pre-Authorization</div></div>
                <div class="stat-card"><div class="stat-value" id="rejectedClaims">0</div><div class="stat-label">Rejected Claims</div></div>
                <div class="stat-card"><div class="stat-value" id="settledAmount">0.00 ETB</div><div class="stat-label">Settled Claims Value</div></div>
                <div class="stat-card"><div class="stat-value" id="denialRate">0%</div><div class="stat-label">Denial Rate</div></div>
            </div>

            <!-- Tabs -->
            <ul class="nav nav-tabs mb-4" id="claimsTabs" role="tablist">
                <li class="nav-item"><button class="nav-link active" id="preauth-tab" data-bs-toggle="tab" data-bs-target="#preauth">Pre-Authorization Portal</button></li>
                <li class="nav-item"><button class="nav-link" id="rejections-tab" data-bs-toggle="tab" data-bs-target="#rejections">Denials & Rejections Desk</button></li>
                <li class="nav-item"><button class="nav-link" id="settled-tab" data-bs-toggle="tab" data-bs-target="#settled">Settled Claims History</button></li>
            </ul>
            <div class="tab-content">
                <!-- PRE-AUTHORIZATION PORTAL TAB -->
                <div class="tab-pane fade show active" id="preauth">
                    <div class="stat-card">
                        <h5 class="mb-3"><i class="fas fa-file-signature me-2"></i>Clinical Pre-Authorization Requests</h5>
                        <div class="table-responsive">
                            <table id="preauthTable" class="table table-hover" style="width:100%">
                                <thead>
                                    <tr><th>Claim ID</th><th>Invoice #</th><th>Patient</th><th>MRN</th><th>Provider</th><th>Package</th><th>Total Amount (ETB)</th><th>Claimed Amount (ETB)</th><th>Invoice Date</th><th>Actions</th></tr>
                                </thead>
                                <tbody><tr><td colspan="10" class="text-center">Loading... --</tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- DENIALS & REJECTIONS DESK TAB -->
                <div class="tab-pane fade" id="rejections">
                    <div class="stat-card">
                        <h5 class="mb-3"><i class="fas fa-ban me-2"></i>Rejected Claims Ledger</h5>
                        <div class="table-responsive">
                            <table id="rejectionsTable" class="table table-hover" style="width:100%">
                                <thead>
                                    <tr><th>Claim ID</th><th>Invoice #</th><th>Patient</th><th>MRN</th><th>Provider</th><th>Claimed Amount (ETB)</th><th>Rejection Reason</th><th>Resubmissions</th><th>Invoice Date</th><th>Actions</th></tr>
                                </thead>
                                <tbody><tr><td colspan="10" class="text-center">Loading... --</tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- SETTLED CLAIMS HISTORY TAB -->
                <div class="tab-pane fade" id="settled">
                    <div class="stat-card">
                        <h5 class="mb-3"><i class="fas fa-check-circle me-2"></i>Settled Claims History</h5>
                        <div class="table-responsive">
                            <table id="settledTable" class="table table-hover" style="width:100%">
                                <thead>
                                    <tr><th>Claim ID</th><th>Invoice #</th><th>Patient</th><th>MRN</th><th>Provider</th><th>Claimed Amount (ETB)</th><th>Pre-Auth Code</th><th>Settled Date</th></tr>
                                </thead>
                                <tbody><tr><td colspan="8" class="text-center">Loading... --</tbody>
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

<!-- MODAL: Resubmit Claim Correction -->
<div id="resubmitModal" class="modal fade" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content"><div class="modal-header"><h5>Resubmit Claim Correction</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body"><form id="resubmitForm"><input type="hidden" name="claim_id" id="resubmitClaimId"><div class="mb-2"><label>Claimed Amount (ETB) *</label><input type="number" step="0.01" name="claimed_amount" id="resubmitAmount" class="form-control" required></div><div class="mb-2"><label>Insurance Package</label><select name="package_id" id="resubmitPackage" class="form-select"><option value="0">Select Package (optional)</option></select></div><input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>"></form></div>
    <div class="modal-footer"><button class="quick-btn quick-btn-outline" data-bs-dismiss="modal">Cancel</button><button class="quick-btn quick-btn-primary" id="confirmResubmitBtn">Resubmit Claim</button></div></div></div>
</div>

<script src="assets/js/vendor.min.js"></script>
<script src="assets/js/app.min.js"></script>
<script>
const csrfToken = "<?php echo $csrf_token; ?>";
let preauthTable, rejectionsTable, settledTable;

$(function(){
    loadKPIs();
    loadPreAuthQueue();
    loadRejections();
    loadSettledHistory();
    loadPackagesDropdown();
    $('#globalBranchFilter, #refreshAllBtn').on('change click', function(){ loadKPIs(); loadPreAuthQueue(); loadRejections(); loadSettledHistory(); });
    $('#confirmResubmitBtn').on('click', submitResubmission);
    $(document).on('click', '.approve-preauth', function(){ approvePreAuth($(this).data('id')); });
    $(document).on('click', '.resubmit-claim', function(){ openResubmitModal($(this).data('id'), $(this).data('amount'), $(this).data('package')); });
});

function loadKPIs() {
    let branch = $('#globalBranchFilter').val();
    $.ajax({
        url: window.location.pathname + '?ajax=claims_action',
        method: 'POST',
        dataType: 'json',
        data: { action: 'get_insurance_kpis', branch_id: branch, csrf_token: csrfToken },
        success: function(res){
            if(res.success){
                $('#pendingPreAuth').text(res.pending_pre_auth);
                $('#rejectedClaims').text(res.rejected_claims);
                $('#settledAmount').text(parseFloat(res.settled_amount).toLocaleString() + ' ETB');
                $('#denialRate').text(res.denial_rate + '%');
            }
        }
    });
}

function loadPreAuthQueue() {
    let branch = $('#globalBranchFilter').val();
    showLoading(true);
    $.ajax({
        url: window.location.pathname + '?ajax=claims_action',
        method: 'POST',
        dataType: 'json',
        data: { action: 'get_pre_auth_queue', branch_id: branch, csrf_token: csrfToken },
        success: function(res){
            showLoading(false);
            if(res.success && res.queue){
                if(preauthTable) preauthTable.destroy();
                let rows = '';
                res.queue.forEach(q => {
                    rows += `<tr>
                        <td>${q.claim_id}</td>
                        <td>${escapeHtml(q.invoice_number)}</td>
                        <td>${escapeHtml(q.patient_name)}</td><td>${escapeHtml(q.mr_number)}</td>
                        <td>${escapeHtml(q.provider_name)}</td><td>${escapeHtml(q.package_name || '—')}</td>
                        <td>${parseFloat(q.total_amount).toFixed(2)}</td><td>${parseFloat(q.claimed_amount).toFixed(2)}</td>
                        <td>${q.invoice_date}</td>
                        <td><button class="btn btn-sm btn-success approve-preauth" data-id="${q.claim_id}"><i class="fas fa-check"></i> Approve Pre-Auth</button></td>
                    </tr>`;
                });
                $('#preauthTable tbody').html(rows);
                preauthTable = $('#preauthTable').DataTable({ order: [[0, 'desc']], pageLength: 10 });
            } else {
                $('#preauthTable tbody').html('<tr><td colspan="10">No pending pre-authorizations. --</tbody>');
            }
        },
        error: function(){ showLoading(false); Swal.fire('Error', 'Failed to load pre-auth queue', 'error'); }
    });
}

function approvePreAuth(claimId){
    Swal.fire({
        title: 'Approve Pre-Authorization',
        text: 'This will generate a unique authorization code and approve the claim for billing. Proceed?',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Yes, approve'
    }).then(res => {
        if(res.isConfirmed){
            showLoading(true);
            $.ajax({
                url: window.location.pathname + '?ajax=claims_action',
                method: 'POST',
                dataType: 'json',
                data: { action: 'approve_pre_auth', claim_id: claimId, csrf_token: csrfToken },
                success: function(r){
                    showLoading(false);
                    if(r.success){
                        Swal.fire('Approved', r.message, 'success');
                        loadKPIs();
                        loadPreAuthQueue();
                        loadRejections();
                        loadSettledHistory();
                    } else {
                        Swal.fire('Error', r.message, 'error');
                    }
                },
                error: function(){ showLoading(false); Swal.fire('Error', 'Approval failed', 'error'); }
            });
        }
    });
}

function loadRejections() {
    let branch = $('#globalBranchFilter').val();
    $.ajax({
        url: window.location.pathname + '?ajax=claims_action',
        method: 'POST',
        dataType: 'json',
        data: { action: 'get_rejection_ledger', branch_id: branch, csrf_token: csrfToken },
        success: function(res){
            if(res.success && res.rejections){
                if(rejectionsTable) rejectionsTable.destroy();
                let rows = '';
                res.rejections.forEach(rj => {
                    rows += `<tr>
                        <td>${rj.claim_id}</td>
                        <td>${escapeHtml(rj.invoice_number)}</td><td>${escapeHtml(rj.patient_name)}</td><td>${escapeHtml(rj.mr_number)}</td>
                        <td>${escapeHtml(rj.provider_name)}</td><td>${parseFloat(rj.claimed_amount).toFixed(2)}</td>
                        <td><span class="rejection-badge">${escapeHtml(rj.rejection_reason)}</span></td><td>${rj.resubmission_count}</td>
                        <td>${rj.invoice_date}</td>
                        <td><button class="btn btn-sm btn-warning resubmit-claim" data-id="${rj.claim_id}" data-amount="${rj.claimed_amount}" data-package="0"><i class="fas fa-redo-alt"></i> Resubmit</button></td>
                    </tr>`;
                });
                $('#rejectionsTable tbody').html(rows);
                rejectionsTable = $('#rejectionsTable').DataTable({ order: [[0, 'desc']], pageLength: 10 });
            } else {
                $('#rejectionsTable tbody').html('<tr><td colspan="10">No rejected claims. --</tbody>');
            }
        }
    });
}

function loadSettledHistory() {
    let branch = $('#globalBranchFilter').val();
    $.ajax({
        url: window.location.pathname + '?ajax=claims_action',
        method: 'POST',
        dataType: 'json',
        data: { action: 'get_settled_claims', branch_id: branch, csrf_token: csrfToken },
        success: function(res){
            if(res.success && res.settled){
                if(settledTable) settledTable.destroy();
                let rows = '';
                res.settled.forEach(s => {
                    rows += `<tr>
                        <td>${s.claim_id}</td>
                        <td>${escapeHtml(s.invoice_number)}</td><td>${escapeHtml(s.patient_name)}</td><td>${escapeHtml(s.mr_number)}</td>
                        <td>${escapeHtml(s.provider_name)}</td><td>${parseFloat(s.claimed_amount).toFixed(2)}</td>
                        <td>${escapeHtml(s.pre_auth_code || '—')}</td><td>${s.settled_date}</td>
                    </tr>`;
                });
                $('#settledTable tbody').html(rows);
                settledTable = $('#settledTable').DataTable({ order: [[0, 'desc']], pageLength: 10 });
            } else {
                $('#settledTable tbody').html('<tr><td colspan="8">No settled claims. --</tbody>');
            }
        }
    });
}

function loadPackagesDropdown() {
    $.ajax({
        url: window.location.pathname + '?ajax=claims_action',
        method: 'POST',
        dataType: 'json',
        data: { action: 'get_insurance_kpis', branch_id: 0, csrf_token: csrfToken }, // Not ideal but we need a separate endpoint. Instead fetch providers/packages.
        success: function(){
            // Actually we need a dedicated endpoint, but for simplicity we'll fetch from rejection ledger or use static.
        }
    });
    // We'll load packages via separate AJAX when opening modal
}

function openResubmitModal(claimId, currentAmount, currentPackage){
    $('#resubmitClaimId').val(claimId);
    $('#resubmitAmount').val(currentAmount);
    // Load packages dropdown
    let branch = $('#globalBranchFilter').val();
    $.ajax({
        url: window.location.pathname + '?ajax=claims_action',
        method: 'POST',
        dataType: 'json',
        data: { action: 'get_packages', branch_id: branch, csrf_token: csrfToken },
        success: function(res){
            if(res.success && res.packages){
                let opts = '<option value="0">None</option>';
                res.packages.forEach(pkg => {
                    opts += `<option value="${pkg.id}" ${currentPackage == pkg.id ? 'selected' : ''}>${escapeHtml(pkg.package_name)} (Provider: ${escapeHtml(pkg.provider_name)})</option>`;
                });
                $('#resubmitPackage').html(opts);
            } else {
                $('#resubmitPackage').html('<option value="0">No packages available</option>');
            }
        }
    });
    $('#resubmitModal').modal('show');
}

function submitResubmission(){
    let claimId = $('#resubmitClaimId').val();
    let amount = $('#resubmitAmount').val();
    let packageId = $('#resubmitPackage').val();
    if(!amount || parseFloat(amount) <= 0){
        Swal.fire('Error', 'Valid claimed amount required.', 'error');
        return;
    }
    Swal.fire({
        title: 'Resubmit Claim',
        text: 'This will increment resubmission count and set status to "submitted". Proceed?',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Yes, resubmit'
    }).then(res => {
        if(res.isConfirmed){
            showLoading(true);
            $.ajax({
                url: window.location.pathname + '?ajax=claims_action',
                method: 'POST',
                dataType: 'json',
                data: { action: 'resubmit_claim_correction', claim_id: claimId, claimed_amount: amount, package_id: packageId, csrf_token: csrfToken },
                success: function(r){
                    showLoading(false);
                    if(r.success){
                        Swal.fire('Resubmitted', r.message, 'success');
                        $('#resubmitModal').modal('hide');
                        loadKPIs();
                        loadPreAuthQueue();
                        loadRejections();
                        loadSettledHistory();
                    } else {
                        Swal.fire('Error', r.message, 'error');
                    }
                },
                error: function(){ showLoading(false); Swal.fire('Error', 'Resubmission failed', 'error'); }
            });
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