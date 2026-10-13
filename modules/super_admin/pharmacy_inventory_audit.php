<?php
/**
 * Super Admin - Integrated Pharmacy POS & Store Inventory Auditing
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
if (isset($_GET['ajax']) && $_GET['ajax'] === 'inventory_audit_action') {
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
        // ---------- GET PENDING STOCK TRANSFERS ----------
        if ($action === 'get_pending_transfers') {
            $branch_filter = (int)($_POST['branch_id'] ?? 0);
            $sql = "SELECT bt.id, bt.from_branch_id, bt.to_branch_id, bt.transfer_reference_number,
                           bt.amount_or_value AS quantity, bt.item_reference_details, bt.created_at,
                           fb.name AS from_branch_name, tb.name AS to_branch_name,
                           u.first_name, u.last_name
                    FROM branch_transfers bt
                    JOIN branches fb ON bt.from_branch_id = fb.id
                    JOIN branches tb ON bt.to_branch_id = tb.id
                    JOIN users u ON bt.initiated_by_user_id = u.id
                    WHERE bt.transfer_status = 'pending' AND bt.transfer_type = 'pharmacy_stock'";
            if ($branch_filter > 0) {
                $sql .= " AND (bt.from_branch_id = :bid OR bt.to_branch_id = :bid)";
            }
            $stmt = $db->prepare($sql);
            if ($branch_filter > 0) $stmt->execute([':bid' => $branch_filter]);
            else $stmt->execute();
            $transfers = $stmt->fetchAll(PDO::FETCH_ASSOC);
            // Decode item details and fetch medicine name
            foreach ($transfers as &$t) {
                $details = json_decode($t['item_reference_details'], true);
                $t['medicine_id'] = $details['medicine_id'] ?? null;
                $t['batch_number'] = $details['batch_number'] ?? null;
                if ($t['medicine_id']) {
                    $medStmt = $db->prepare("SELECT generic_name, brand_name FROM medicines_master WHERE id = ?");
                    $medStmt->execute([$t['medicine_id']]);
                    $med = $medStmt->fetch(PDO::FETCH_ASSOC);
                    $t['medicine_name'] = ($med['generic_name'] ?? '') . ' ' . ($med['brand_name'] ?? '');
                }
            }
            echo json_encode(['success' => true, 'transfers' => $transfers]);
            exit;
        }

        // ---------- APPROVE STOCK TRANSFER (Atomic) ----------
        if ($action === 'approve_stock_transfer') {
            $transfer_id = (int)($_POST['transfer_id'] ?? 0);
            if (!$transfer_id) {
                echo json_encode(['success' => false, 'message' => 'Invalid transfer ID.']);
                exit;
            }
            $db->beginTransaction();
            try {
                // Lock and fetch transfer record
                $stmt = $db->prepare("SELECT from_branch_id, to_branch_id, amount_or_value, item_reference_details, transfer_status
                                      FROM branch_transfers WHERE id = :id AND transfer_type = 'pharmacy_stock' FOR UPDATE");
                $stmt->execute([':id' => $transfer_id]);
                $transfer = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$transfer || $transfer['transfer_status'] !== 'pending') {
                    throw new Exception('Transfer not found or already processed.');
                }
                $details = json_decode($transfer['item_reference_details'], true);
                $medicine_id = $details['medicine_id'];
                $batch_number = $details['batch_number'];
                $quantity = (int)$transfer['amount_or_value'];

                // Deduct from source branch inventory
                $deduct = $db->prepare("UPDATE pharmacy_inventory SET quantity_in_hand = quantity_in_hand - :qty, updated_at = NOW()
                                        WHERE branch_id = :bid AND medicine_id = :mid AND batch_number = :batch AND quantity_in_hand >= :qty");
                $deduct->execute([':qty' => $quantity, ':bid' => $transfer['from_branch_id'], ':mid' => $medicine_id, ':batch' => $batch_number]);
                if ($deduct->rowCount() == 0) {
                    throw new Exception('Insufficient stock in source branch or batch not found.');
                }

                // Add to destination branch inventory (update if exists, else insert)
                $checkDest = $db->prepare("SELECT id, quantity_in_hand FROM pharmacy_inventory 
                                           WHERE branch_id = :bid AND medicine_id = :mid AND batch_number = :batch");
                $checkDest->execute([':bid' => $transfer['to_branch_id'], ':mid' => $medicine_id, ':batch' => $batch_number]);
                $destInv = $checkDest->fetch(PDO::FETCH_ASSOC);
                if ($destInv) {
                    $add = $db->prepare("UPDATE pharmacy_inventory SET quantity_in_hand = quantity_in_hand + :qty, updated_at = NOW() WHERE id = :id");
                    $add->execute([':qty' => $quantity, ':id' => $destInv['id']]);
                } else {
                    // Copy inventory details from source branch (purchase_price, selling_price, expiry_date)
                    $srcInv = $db->prepare("SELECT purchase_price, selling_price, expiry_date FROM pharmacy_inventory 
                                            WHERE branch_id = :bid AND medicine_id = :mid AND batch_number = :batch");
                    $srcInv->execute([':bid' => $transfer['from_branch_id'], ':mid' => $medicine_id, ':batch' => $batch_number]);
                    $srcData = $srcInv->fetch(PDO::FETCH_ASSOC);
                    $insert = $db->prepare("INSERT INTO pharmacy_inventory (branch_id, medicine_id, batch_number, quantity_in_hand, expiry_date, purchase_price, selling_price, created_by_user_id, created_at, updated_at)
                                            VALUES (:bid, :mid, :batch, :qty, :exp, :pp, :sp, :uid, NOW(), NOW())");
                    $insert->execute([
                        ':bid' => $transfer['to_branch_id'], ':mid' => $medicine_id, ':batch' => $batch_number,
                        ':qty' => $quantity, ':exp' => $srcData['expiry_date'], ':pp' => $srcData['purchase_price'],
                        ':sp' => $srcData['selling_price'], ':uid' => $user_id
                    ]);
                }

                // Update transfer status
                $update = $db->prepare("UPDATE branch_transfers SET transfer_status = 'completed', approved_by_user_id = :admin, updated_at = NOW() WHERE id = :id");
                $update->execute([':admin' => $user_id, ':id' => $transfer_id]);

                $db->commit();
                echo json_encode(['success' => true, 'message' => 'Stock transfer approved and inventory updated.']);
            } catch (Exception $e) {
                $db->rollBack();
                echo json_encode(['success' => false, 'message' => 'Approval failed: ' . $e->getMessage()]);
            }
            exit;
        }

        // ---------- APPLY PRICE MARKUP (Global or per branch) ----------
        if ($action === 'apply_price_markup') {
            $markup_percent = (float)($_POST['markup_percent'] ?? 0);
            $branch_id_filter = (int)($_POST['branch_id'] ?? 0);
            if ($markup_percent < 0) {
                echo json_encode(['success' => false, 'message' => 'Markup percentage must be >= 0.']);
                exit;
            }
            $sql = "UPDATE pharmacy_inventory SET selling_price = purchase_price * (1 + :markup / 100), updated_at = NOW() WHERE purchase_price IS NOT NULL AND purchase_price > 0";
            $params = [':markup' => $markup_percent];
            if ($branch_id_filter > 0) {
                $sql .= " AND branch_id = :bid";
                $params[':bid'] = $branch_id_filter;
            }
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->rowCount();
            echo json_encode(['success' => true, 'message' => "Price markup applied to $rows inventory records. New selling price = purchase_price * (1 + $markup_percent/100)."]);
            exit;
        }

        // ---------- GET NARCOTICS & CONTROLLED SUBSTANCE AUDIT ----------
        if ($action === 'get_narcotics_audit') {
            $start_date = $_POST['start_date'] ?? date('Y-m-01');
            $end_date = $_POST['end_date'] ?? date('Y-m-t');
            $branch_filter = (int)($_POST['branch_id'] ?? 0);
            $sql = "SELECT 
                        p.id AS prescription_id, p.prescription_number, p.prescription_date,
                        pt.id AS patient_id, CONCAT(pt.first_name, ' ', pt.last_name) AS patient_name, pt.mr_number,
                        u.id AS doctor_id, CONCAT(u.first_name, ' ', u.last_name) AS doctor_name,
                        mm.generic_name, mm.brand_name, mm.dosage_form, mm.strength,
                        pi.quantity, pi.dosage, pi.frequency, pi.instructions,
                        bi.id AS billing_item_id, bi.status AS payment_status, bi.amount,
                        pv.visit_number, pv.visit_date
                    FROM prescriptions pr
                    JOIN prescription_items pi ON pr.id = pi.prescription_id
                    JOIN medicines_master mm ON pi.medicine_id = mm.id
                    JOIN patients pt ON pr.patient_id = pt.id
                    JOIN users u ON pr.doctor_id = u.id
                    LEFT JOIN patient_visits pv ON pr.visit_id = pv.id
                    LEFT JOIN billing_items bi ON bi.prescription_id = pr.id AND bi.item_type = 'pharmacy'
                    WHERE mm.is_controlled_substance = 1
                      AND DATE(pr.created_at) BETWEEN :start AND :end";
            if ($branch_filter > 0) {
                $sql .= " AND pr.branch_id = :bid";
            }
            $sql .= " ORDER BY pr.created_at DESC";
            $stmt = $db->prepare($sql);
            $params = [':start' => $start_date, ':end' => $end_date];
            if ($branch_filter > 0) $params[':bid'] = $branch_filter;
            $stmt->execute($params);
            $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'records' => $records]);
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
    <title>Pharmacy Inventory Auditing | Super Admin | HMS ERP</title>
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
        .stat-card { background: white; border-radius: 20px; padding: 20px; margin-bottom: 20px; border: 1px solid var(--gray-light); }
        .quick-btn { padding: 10px 20px; border-radius: 14px; font-size: 14px; font-weight: 600; border: none; cursor: pointer; transition: all 0.2s; }
        .quick-btn-primary { background: linear-gradient(135deg, var(--primary), var(--primary-dark)); color: white; }
        .quick-btn-outline { background: white; border: 1px solid var(--gray-light); color: var(--dark); }
        .loading-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(255,255,255,0.7); z-index: 2000; display: none; align-items: center; justify-content: center; }
        .loading-overlay.active { display: flex; }
        .spinner { width: 40px; height: 40px; border: 3px solid var(--gray-light); border-top-color: var(--primary); border-radius: 50%; animation: spin 0.8s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }
        .controlled-badge { background-color: #ef476f; color: white; padding: 2px 8px; border-radius: 12px; font-size: 10px; }
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
                        <div class="welcome-title">Integrated Pharmacy POS & Store Inventory Auditing</div>
                        <div class="welcome-subtitle">Super Admin Console: Multi-store transfers, price markup engine, narcotics audit trail.</div>
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

            <!-- Tabs -->
            <ul class="nav nav-tabs mb-4" id="auditTabs" role="tablist">
                <li class="nav-item"><button class="nav-link active" id="transfers-tab" data-bs-toggle="tab" data-bs-target="#transfers">Multi-Store Stock Approvals</button></li>
                <li class="nav-item"><button class="nav-link" id="price-tab" data-bs-toggle="tab" data-bs-target="#price">Drug Price Markup Engine</button></li>
                <li class="nav-item"><button class="nav-link" id="narcotics-tab" data-bs-toggle="tab" data-bs-target="#narcotics">Controlled Narcotics Registry</button></li>
            </ul>
            <div class="tab-content">
                <!-- TRANSFERS TAB -->
                <div class="tab-pane fade show active" id="transfers">
                    <div class="stat-card">
                        <h5 class="mb-3"><i class="fas fa-exchange-alt me-2"></i>Pending Stock Transfers</h5>
                        <div class="table-responsive">
                            <table id="transfersTable" class="table table-hover" style="width:100%">
                                <thead>
                                    <tr><th>ID</th><th>Reference</th><th>Medicine</th><th>Batch</th><th>Quantity</th><th>From Branch</th><th>To Branch</th><th>Requested By</th><th>Date</th><th>Actions</th></tr>
                                </thead>
                                <tbody><tr><td colspan="10" class="text-center">Loading... --</tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- PRICE MARKUP TAB -->
                <div class="tab-pane fade" id="price">
                    <div class="stat-card">
                        <h5 class="mb-3"><i class="fas fa-tags me-2"></i>Global Price Markup Engine</h5>
                        <p class="text-muted">Apply a uniform percentage markup to calculate selling price based on purchase price across all inventory batches.</p>
                        <form id="priceMarkupForm" class="row g-3 align-items-end">
                            <div class="col-md-4">
                                <label class="form-label">Markup Percentage (%)</label>
                                <input type="number" step="0.01" id="markupPercent" class="form-control" placeholder="e.g., 25" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Apply to Branch (optional)</label>
                                <select id="markupBranch" class="form-select">
                                    <option value="0">All Branches</option>
                                    <?php foreach ($branches as $branch): ?>
                                    <option value="<?php echo $branch['id']; ?>"><?php echo htmlspecialchars($branch['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <button type="button" class="quick-btn quick-btn-primary" id="applyMarkupBtn"><i class="fas fa-calculator me-2"></i>Apply Price Markup</button>
                            </div>
                        </form>
                        <hr>
                        <div class="alert alert-info mt-3">
                            <i class="fas fa-info-circle me-2"></i> This action recalculates selling_price = purchase_price * (1 + markup/100). It will affect all selected branch inventory records where purchase_price is defined.
                        </div>
                    </div>
                </div>

                <!-- NARCOTICS REGISTRY TAB -->
                <div class="tab-pane fade" id="narcotics">
                    <div class="stat-card">
                        <h5 class="mb-3"><i class="fas fa-capsules me-2"></i>Controlled Substance Dispensing Audit Trail</h5>
                        <div class="row mb-3">
                            <div class="col-md-4"><input type="date" id="narcStartDate" class="form-control" value="<?php echo date('Y-m-01'); ?>"></div>
                            <div class="col-md-4"><input type="date" id="narcEndDate" class="form-control" value="<?php echo date('Y-m-t'); ?>"></div>
                            <div class="col-md-4"><button class="quick-btn quick-btn-primary" id="loadNarcoticsBtn"><i class="fas fa-chart-line me-2"></i>Generate Audit Report</button></div>
                        </div>
                        <div class="table-responsive">
                            <table id="narcoticsTable" class="table table-hover" style="width:100%">
                                <thead>
                                    <tr><th>Prescription #</th><th>Patient (MRN)</th><th>Doctor</th><th>Medicine</th><th>Quantity</th><th>Dosage/Frequency</th><th>Payment Status</th><th>Visit Date</th><th>Action</th></tr>
                                </thead>
                                <tbody><tr><td colspan="9" class="text-center">Select date range and click Generate. --</tbody>
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

<script src="assets/js/vendor.min.js"></script>
<script src="assets/js/app.min.js"></script>
<script>
const csrfToken = "<?php echo $csrf_token; ?>";
let transfersTable, narcoticsTable;

$(function(){
    loadPendingTransfers();
    $('#globalBranchFilter, #refreshAllBtn').on('change click', function(){ loadPendingTransfers(); });
    $('#applyMarkupBtn').on('click', applyPriceMarkup);
    $('#loadNarcoticsBtn').on('click', loadNarcoticsAudit);
});

function loadPendingTransfers() {
    let branch = $('#globalBranchFilter').val();
    showLoading(true);
    $.ajax({
        url: window.location.pathname + '?ajax=inventory_audit_action',
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
                        <td>${escapeHtml(t.transfer_reference_number)}</td>
                        <td>${escapeHtml(t.medicine_name || 'N/A')} (ID:${t.medicine_id})</td>
                        <td>${escapeHtml(t.batch_number)}</td>
                        <td>${t.quantity}</td>
                        <td>${escapeHtml(t.from_branch_name)}</td>
                        <td>${escapeHtml(t.to_branch_name)}</td>
                        <td>${escapeHtml(t.first_name)} ${escapeHtml(t.last_name)}</td>
                        <td>${t.created_at}</td>
                        <td><button class="btn btn-sm btn-success approve-transfer" data-id="${t.id}"><i class="fas fa-check"></i> Approve</button>
                            <button class="btn btn-sm btn-danger reject-transfer" disabled><i class="fas fa-times"></i> Reject</button></td>
                    </tr>`;
                });
                $('#transfersTable tbody').html(rows);
                transfersTable = $('#transfersTable').DataTable({ order: [[0, 'desc']], pageLength: 10 });
                $('.approve-transfer').on('click', function(){ approveStockTransfer($(this).data('id')); });
            } else {
                $('#transfersTable tbody').html('<tr><td colspan="10">No pending transfers. --</tbody>');
            }
        },
        error: function(){ showLoading(false); Swal.fire('Error', 'Failed to load transfers', 'error'); }
    });
}

function approveStockTransfer(transferId){
    Swal.fire({
        title: 'Approve Stock Transfer',
        text: 'This will move stock between branches and update inventory. Proceed?',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Yes, approve'
    }).then(res => {
        if(res.isConfirmed){
            showLoading(true);
            $.ajax({
                url: window.location.pathname + '?ajax=inventory_audit_action',
                method: 'POST',
                dataType: 'json',
                data: { action: 'approve_stock_transfer', transfer_id: transferId, csrf_token: csrfToken },
                success: function(r){
                    showLoading(false);
                    if(r.success){
                        Swal.fire('Approved', r.message, 'success');
                        loadPendingTransfers();
                    } else {
                        Swal.fire('Error', r.message, 'error');
                    }
                },
                error: function(){ showLoading(false); Swal.fire('Error', 'Approval failed', 'error'); }
            });
        }
    });
}

function applyPriceMarkup() {
    let markup = $('#markupPercent').val();
    if(markup === '' || parseFloat(markup) < 0){
        Swal.fire('Invalid Input', 'Please enter a valid markup percentage (>= 0).', 'error');
        return;
    }
    let branch = $('#markupBranch').val();
    Swal.fire({
        title: 'Apply Price Markup',
        text: `Set selling price = purchase_price * (1 + ${markup}/100) for ${branch == 0 ? 'all branches' : 'selected branch'}?`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Yes, apply'
    }).then(res => {
        if(res.isConfirmed){
            showLoading(true);
            $.ajax({
                url: window.location.pathname + '?ajax=inventory_audit_action',
                method: 'POST',
                dataType: 'json',
                data: { action: 'apply_price_markup', markup_percent: markup, branch_id: branch, csrf_token: csrfToken },
                success: function(r){
                    showLoading(false);
                    if(r.success){
                        Swal.fire('Success', r.message, 'success');
                    } else {
                        Swal.fire('Error', r.message, 'error');
                    }
                },
                error: function(){ showLoading(false); Swal.fire('Error', 'Markup application failed', 'error'); }
            });
        }
    });
}

function loadNarcoticsAudit() {
    let start = $('#narcStartDate').val();
    let end = $('#narcEndDate').val();
    let branch = $('#globalBranchFilter').val();
    if(!start || !end){
        Swal.fire('Error', 'Please select both start and end dates.', 'error');
        return;
    }
    showLoading(true);
    $.ajax({
        url: window.location.pathname + '?ajax=inventory_audit_action',
        method: 'POST',
        dataType: 'json',
        data: { action: 'get_narcotics_audit', start_date: start, end_date: end, branch_id: branch, csrf_token: csrfToken },
        success: function(res){
            showLoading(false);
            if(res.success && res.records){
                if(narcoticsTable) narcoticsTable.destroy();
                let rows = '';
                res.records.forEach(r => {
                    let payStatus = r.payment_status === 'paid' ? '<span class="badge bg-success">Paid</span>' : '<span class="badge bg-warning">Unpaid</span>';
                    rows += `<tr>
                        <td>${escapeHtml(r.prescription_number)}</td>
                        <td>${escapeHtml(r.patient_name)} (${escapeHtml(r.mr_number)})</td>
                        <td>Dr. ${escapeHtml(r.doctor_name)}</td>
                        <td>${escapeHtml(r.generic_name)} ${escapeHtml(r.brand_name)}<br><small>${escapeHtml(r.dosage_form)} ${escapeHtml(r.strength)}</small></td>
                        <td>${r.quantity} ${escapeHtml(r.dosage)} / ${escapeHtml(r.frequency)}</td>
                        <td>${payStatus}</td>
                        <td>${r.visit_date}</td>
                        <td><button class="btn btn-sm btn-info view-details" data-pid="${r.prescription_id}"><i class="fas fa-eye"></i> Details</button></td>
                    </tr>`;
                });
                $('#narcoticsTable tbody').html(rows);
                narcoticsTable = $('#narcoticsTable').DataTable({ order: [[6, 'desc']], pageLength: 10 });
            } else {
                $('#narcoticsTable tbody').html('<tr><td colspan="9">No controlled substance records found. --</tbody>');
            }
        },
        error: function(){ showLoading(false); Swal.fire('Error', 'Failed to load narcotics audit', 'error'); }
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