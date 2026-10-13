<?php
/**
 * Super Admin - Pharmacy, Medication & Medical Supplies Master
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
// AJAX ROUTER
// ============================================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'pharmacy_action') {
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

    // ---------- GET DRUGS (medicines_master) ----------
    if ($action === 'get_drugs') {
        $branch_filter = (int)($_POST['branch_id'] ?? 0);
        // medicines_master is branch-independent? In schema, no branch_id in medicines_master.
        $sql = "SELECT id, generic_name, brand_name, dosage_form, strength, is_active 
                FROM medicines_master WHERE deleted_at IS NULL";
        $stmt = $db->query($sql);
        $drugs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'drugs' => $drugs]);
        exit;
    }

    // ---------- SAVE DRUG (CREATE/UPDATE) ----------
    if ($action === 'save_drug') {
        $drug_id = (int)($_POST['drug_id'] ?? 0);
        $generic_name = trim($_POST['generic_name'] ?? '');
        $brand_name = trim($_POST['brand_name'] ?? '');
        $dosage_form = $_POST['dosage_form'] ?? 'tablet';
        $strength = trim($_POST['strength'] ?? '');
        $is_active = isset($_POST['is_active']) ? 1 : 0;

        if (!$generic_name || !$brand_name) {
            echo json_encode(['success' => false, 'message' => 'Generic name and brand name are required.']);
            exit;
        }

        // Check for duplicate (generic_name + brand_name + strength)
        $check = $db->prepare("SELECT id FROM medicines_master WHERE generic_name = :gn AND brand_name = :bn AND strength = :str AND deleted_at IS NULL AND id != :id");
        $check->execute([':gn' => $generic_name, ':bn' => $brand_name, ':str' => $strength, ':id' => $drug_id]);
        if ($check->fetch()) {
            echo json_encode(['success' => false, 'message' => 'Drug already exists (same generic name, brand name and strength).']);
            exit;
        }

        if ($drug_id > 0) {
            $stmt = $db->prepare("UPDATE medicines_master SET generic_name = :gn, brand_name = :bn, dosage_form = :df, strength = :str, is_active = :act, updated_at = NOW() WHERE id = :id");
            $stmt->execute([':gn' => $generic_name, ':bn' => $brand_name, ':df' => $dosage_form, ':str' => $strength, ':act' => $is_active, ':id' => $drug_id]);
            echo json_encode(['success' => true, 'message' => 'Drug updated successfully.']);
        } else {
            $stmt = $db->prepare("INSERT INTO medicines_master (medicine_code, generic_name, brand_name, dosage_form, strength, is_active, created_by_user_id, created_at, updated_at) VALUES (CONCAT('DRG-', UNIX_TIMESTAMP()), :gn, :bn, :df, :str, :act, :uid, NOW(), NOW())");
            $stmt->execute([':gn' => $generic_name, ':bn' => $brand_name, ':df' => $dosage_form, ':str' => $strength, ':act' => $is_active, ':uid' => $user_id]);
            echo json_encode(['success' => true, 'message' => 'Drug created successfully.']);
        }
        exit;
    }

    // ---------- GET PENDING PROCUREMENT ORDERS (SUPPLIER ORDERS) ----------
    if ($action === 'get_pending_orders') {
        $branch_filter = (int)($_POST['branch_id'] ?? 0);
        $sql = "SELECT so.id, so.purchase_order_number, so.supplier_name, so.total_amount, so.order_date, so.order_status,
                       s.supplier_name as supplier_contact, s.phone
                FROM supplier_orders so
                LEFT JOIN suppliers s ON so.supplier_name = s.supplier_name
                WHERE so.order_status = 'draft' AND so.deleted_at IS NULL";
        if ($branch_filter > 0) {
            $sql .= " AND so.branch_id = :branch_id";
            $stmt = $db->prepare($sql);
            $stmt->execute([':branch_id' => $branch_filter]);
        } else {
            $stmt = $db->query($sql);
        }
        $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
        // For each order, fetch its items from procurement_items
        foreach ($orders as &$order) {
            $itemStmt = $db->prepare("SELECT pi.id, pi.item_type, pi.reference_id, pi.batch_number, pi.quantity, pi.expiry_date, pi.purchase_price, pi.selling_price,
                                             mm.generic_name, mm.brand_name
                                      FROM procurement_items pi
                                      LEFT JOIN medicines_master mm ON pi.reference_id = mm.id AND pi.item_type = 'medicine'
                                      WHERE pi.supplier_order_id = :oid");
            $itemStmt->execute([':oid' => $order['id']]);
            $order['items'] = $itemStmt->fetchAll(PDO::FETCH_ASSOC);
        }
        echo json_encode(['success' => true, 'orders' => $orders]);
        exit;
    }

    // ---------- APPROVE PROCUREMENT ORDER (INJECT INTO INVENTORY) ----------
    if ($action === 'approve_order') {
        $order_id = (int)($_POST['order_id'] ?? 0);
        if (!$order_id) {
            echo json_encode(['success' => false, 'message' => 'Invalid order ID.']);
            exit;
        }

        // Fetch order and its items
        $orderStmt = $db->prepare("SELECT branch_id, supplier_name, total_amount FROM supplier_orders WHERE id = :id AND order_status = 'draft' AND deleted_at IS NULL");
        $orderStmt->execute([':id' => $order_id]);
        $order = $orderStmt->fetch(PDO::FETCH_ASSOC);
        if (!$order) {
            echo json_encode(['success' => false, 'message' => 'Order not found or already processed.']);
            exit;
        }

        $itemStmt = $db->prepare("SELECT * FROM procurement_items WHERE supplier_order_id = :oid");
        $itemStmt->execute([':oid' => $order_id]);
        $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);
        if (empty($items)) {
            echo json_encode(['success' => false, 'message' => 'No items in this order.']);
            exit;
        }

        $db->beginTransaction();
        try {
            // Update order status to 'ordered' (approved)
            $updateOrder = $db->prepare("UPDATE supplier_orders SET order_status = 'ordered', updated_at = NOW() WHERE id = :id");
            $updateOrder->execute([':id' => $order_id]);

            // Process each item
            foreach ($items as $item) {
                if ($item['item_type'] == 'medicine') {
                    $medicine_id = $item['reference_id'];
                    $batch = $item['batch_number'];
                    $qty = (int)$item['quantity'];
                    $expiry = $item['expiry_date'];
                    $purchase_price = (float)$item['purchase_price'];
                    $selling_price = (float)$item['selling_price'];

                    // Check if inventory batch already exists (medicine_id + batch_number)
                    $invStmt = $db->prepare("SELECT id, quantity_in_hand FROM pharmacy_inventory WHERE medicine_id = :mid AND batch_number = :batch AND branch_id = :bid AND deleted_at IS NULL");
                    $invStmt->execute([':mid' => $medicine_id, ':batch' => $batch, ':bid' => $order['branch_id']]);
                    $inventory = $invStmt->fetch(PDO::FETCH_ASSOC);
                    if ($inventory) {
                        $newQty = $inventory['quantity_in_hand'] + $qty;
                        $updInv = $db->prepare("UPDATE pharmacy_inventory SET quantity_in_hand = :qty, updated_at = NOW() WHERE id = :id");
                        $updInv->execute([':qty' => $newQty, ':id' => $inventory['id']]);
                    } else {
                        $insInv = $db->prepare("INSERT INTO pharmacy_inventory (branch_id, medicine_id, batch_number, supplier_name, purchase_price, selling_price, expiry_date, quantity_received, quantity_in_hand, reorder_level, created_by_user_id, created_at, updated_at)
                                                VALUES (:bid, :mid, :batch, :sup, :pp, :sp, :exp, :qty, :qty, 10, :uid, NOW(), NOW())");
                        $insInv->execute([
                            ':bid' => $order['branch_id'],
                            ':mid' => $medicine_id,
                            ':batch' => $batch,
                            ':sup' => $order['supplier_name'],
                            ':pp' => $purchase_price,
                            ':sp' => $selling_price,
                            ':exp' => $expiry,
                            ':qty' => $qty,
                            ':uid' => $user_id
                        ]);
                    }
                }
                // For medical supplies (if table existed, but we skip as not in schema)
            }
            $db->commit();
            echo json_encode(['success' => true, 'message' => 'Order approved and inventory updated.']);
        } catch (Exception $e) {
            $db->rollBack();
            echo json_encode(['success' => false, 'message' => 'Approval failed: ' . $e->getMessage()]);
        }
        exit;
    }

    // ---------- GET INVENTORY ALERTS (EXPIRY & REORDER) ----------
    if ($action === 'get_inventory_alerts') {
        $branch_filter = (int)($_POST['branch_id'] ?? 0);
        // Expiry within 90 days
        $expSql = "SELECT pi.id, pi.batch_number, pi.expiry_date, pi.quantity_in_hand, mm.generic_name, mm.brand_name
                   FROM pharmacy_inventory pi
                   JOIN medicines_master mm ON pi.medicine_id = mm.id
                   WHERE pi.deleted_at IS NULL AND pi.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 90 DAY)";
        if ($branch_filter > 0) $expSql .= " AND pi.branch_id = :bid";
        $expStmt = $db->prepare($expSql);
        if ($branch_filter > 0) $expStmt->execute([':bid' => $branch_filter]);
        else $expStmt->execute();
        $expiring = $expStmt->fetchAll(PDO::FETCH_ASSOC);

        // Reorder alerts (quantity_in_hand <= reorder_level)
        $reorderSql = "SELECT pi.id, pi.batch_number, pi.quantity_in_hand, pi.reorder_level, mm.generic_name, mm.brand_name
                       FROM pharmacy_inventory pi
                       JOIN medicines_master mm ON pi.medicine_id = mm.id
                       WHERE pi.deleted_at IS NULL AND pi.quantity_in_hand <= pi.reorder_level";
        if ($branch_filter > 0) $reorderSql .= " AND pi.branch_id = :bid";
        $reorderStmt = $db->prepare($reorderSql);
        if ($branch_filter > 0) $reorderStmt->execute([':bid' => $branch_filter]);
        else $reorderStmt->execute();
        $reorder = $reorderStmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'expiring' => $expiring, 'reorder' => $reorder]);
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
    <title>Pharmacy, Medication & Supplies Master | Super Admin | HMS ERP</title>
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
        .alert-card { background: white; border-radius: 20px; padding: 20px; margin-bottom: 24px; border-left: 5px solid var(--warning); }
        .stat-card { background: white; border-radius: 20px; padding: 20px; margin-bottom: 20px; border: 1px solid var(--gray-light); }
        .quick-btn { padding: 10px 20px; border-radius: 14px; font-size: 14px; font-weight: 600; border: none; cursor: pointer; transition: all 0.2s; }
        .quick-btn-primary { background: linear-gradient(135deg, var(--primary), var(--primary-dark)); color: white; }
        .quick-btn-outline { background: white; border: 1px solid var(--gray-light); color: var(--dark); }
        .expiry-warning { color: #d97706; font-weight: 600; }
        .reorder-warning { color: #ef476f; font-weight: 600; }
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
                        <div class="welcome-title">Pharmacy, Medication & Medical Supplies Master</div>
                        <div class="welcome-subtitle">Super Admin Console: Drug dictionary, procurement approval, inventory alerts.</div>
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
            <ul class="nav nav-tabs mb-4" id="pharmacyTabs" role="tablist">
                <li class="nav-item"><button class="nav-link active" id="dictionary-tab" data-bs-toggle="tab" data-bs-target="#dictionary">Drug Dictionary</button></li>
                <li class="nav-item"><button class="nav-link" id="procurement-tab" data-bs-toggle="tab" data-bs-target="#procurement">Procurement Approvals</button></li>
                <li class="nav-item"><button class="nav-link" id="alerts-tab" data-bs-toggle="tab" data-bs-target="#alerts">Expiry & Reorder Alerts</button></li>
            </ul>
            <div class="tab-content">
                <!-- DRUG DICTIONARY TAB -->
                <div class="tab-pane fade show active" id="dictionary">
                    <div class="stat-card">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h5 class="mb-0"><i class="fas fa-capsules me-2"></i>Global Drug Master</h5>
                            <button class="quick-btn quick-btn-primary" id="addDrugBtn"><i class="fas fa-plus me-2"></i>Add Drug</button>
                        </div>
                        <div class="table-responsive">
                            <table id="drugsTable" class="table table-hover" style="width:100%">
                                <thead><tr><th>ID</th><th>Generic Name</th><th>Brand Name</th><th>Form</th><th>Strength</th><th>Status</th><th>Actions</th></tr></thead>
                                <tbody><tr><td colspan="7" class="text-center">Loading drugs...</td></tr></tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- PROCUREMENT APPROVALS TAB -->
                <div class="tab-pane fade" id="procurement">
                    <div class="stat-card">
                        <h5 class="mb-3"><i class="fas fa-file-invoice me-2"></i>Pending Procurement Orders</h5>
                        <div class="table-responsive">
                            <table id="procurementTable" class="table table-hover" style="width:100%">
                                <thead><tr><th>PO Number</th><th>Supplier</th><th>Total Amount (ETB)</th><th>Order Date</th><th>Items</th><th>Actions</th></tr></thead>
                                <tbody><tr><td colspan="6" class="text-center">Loading pending orders...</td></tr></tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- EXPIRY & REORDER ALERTS TAB -->
                <div class="tab-pane fade" id="alerts">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="alert-card">
                                <h5><i class="fas fa-hourglass-half text-warning me-2"></i>Expiring Within 90 Days</h5>
                                <div class="table-responsive">
                                    <table id="expiryTable" class="table table-sm">
                                        <thead><tr><th>Drug</th><th>Batch</th><th>Expiry Date</th><th>Stock</th></tr></thead>
                                        <tbody><tr><td colspan="4" class="text-center">Loading...</td></tr></tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="alert-card">
                                <h5><i class="fas fa-exclamation-triangle text-danger me-2"></i>Reorder Level Alerts</h5>
                                <div class="table-responsive">
                                    <table id="reorderTable" class="table table-sm">
                                        <thead><tr><th>Drug</th><th>Batch</th><th>Current Stock</th><th>Reorder Level</th></tr></thead>
                                        <tbody><tr><td colspan="4" class="text-center">Loading...</td></tr></tbody>
                                    </table>
                                </div>
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

<!-- MODAL: Drug Add/Edit -->
<div id="drugModal" class="modal fade" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content"><div class="modal-header"><h5 id="drugModalTitle">Drug Configuration</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body"><form id="drugForm"><input type="hidden" name="drug_id" id="drugId"><div class="mb-2"><label>Generic Name *</label><input type="text" name="generic_name" id="drugGeneric" class="form-control" required></div><div class="mb-2"><label>Brand Name *</label><input type="text" name="brand_name" id="drugBrand" class="form-control" required></div><div class="mb-2"><label>Dosage Form</label><select name="dosage_form" id="drugFormType" class="form-select"><option value="tablet">Tablet</option><option value="capsule">Capsule</option><option value="syrup">Syrup</option><option value="injection">Injection</option><option value="ointment">Ointment</option></select></div><div class="mb-2"><label>Strength</label><input type="text" name="strength" id="drugStrength" class="form-control" placeholder="e.g., 500mg"></div><div class="form-check mb-2"><input type="checkbox" name="is_active" id="drugActive" class="form-check-input" checked><label class="form-check-label">Active</label></div><input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>"></form></div>
    <div class="modal-footer"><button class="quick-btn quick-btn-outline" data-bs-dismiss="modal">Cancel</button><button class="quick-btn quick-btn-primary" id="saveDrugBtn">Save Drug</button></div></div></div>
</div>

<script src="assets/js/vendor.min.js"></script>
<script src="assets/js/app.min.js"></script>
<script>
const csrfToken = "<?php echo $csrf_token; ?>";
let drugsTable, procurementTable, expiryTable, reorderTable;

$(function(){
    loadDrugsTable();
    loadProcurementTable();
    loadAlertsTables();
    $('#globalBranchFilter, #refreshAllBtn').on('change click', function(){ loadDrugsTable(); loadProcurementTable(); loadAlertsTables(); });
    $('#addDrugBtn').on('click', function(){ resetDrugForm(); $('#drugModal').modal('show'); });
    $('#saveDrugBtn').on('click', saveDrug);
});

function loadDrugsTable() {
    let branch = $('#globalBranchFilter').val();
    $.ajax({ url: window.location.pathname + '?ajax=pharmacy_action', method: 'POST', dataType: 'json', data: { action: 'get_drugs', branch_id: branch, csrf_token: csrfToken }, success: function(res){
        if(res.success && res.drugs){
            let rows = '';
            res.drugs.forEach(d => {
                let statusBadge = d.is_active ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>';
                rows += `<tr>
                            <td>${d.id}</td>
                            <td>${escapeHtml(d.generic_name)}</td>
                            <td>${escapeHtml(d.brand_name)}</td>
                            <td>${d.dosage_form}</td>
                            <td>${escapeHtml(d.strength)}</td>
                            <td>${statusBadge}</td>
                            <td><button class="btn btn-sm btn-outline-primary edit-drug" data-id="${d.id}" data-generic="${escapeHtml(d.generic_name)}" data-brand="${escapeHtml(d.brand_name)}" data-form="${d.dosage_form}" data-strength="${escapeHtml(d.strength)}" data-active="${d.is_active}"><i class="fas fa-edit"></i></button></td>
                         </tr>`;
            });
            $('#drugsTable tbody').html(rows);
            $('.edit-drug').on('click', function(){ openDrugEdit($(this).data('id'), $(this).data('generic'), $(this).data('brand'), $(this).data('form'), $(this).data('strength'), $(this).data('active')); });
        } else { $('#drugsTable tbody').html('<tr><td colspan="7">No drugs found.</td></tr>'); }
    }});
}
function openDrugEdit(id, generic, brand, form, strength, active){
    $('#drugId').val(id);
    $('#drugGeneric').val(generic);
    $('#drugBrand').val(brand);
    $('#drugFormType').val(form);
    $('#drugStrength').val(strength);
    $('#drugActive').prop('checked', active == 1);
    $('#drugModalTitle').text('Edit Drug');
    $('#drugModal').modal('show');
}
function resetDrugForm(){ $('#drugForm')[0].reset(); $('#drugId').val(''); $('#drugModalTitle').text('Add Drug'); $('#drugActive').prop('checked', true); }
function saveDrug(){
    let formData = $('#drugForm').serialize();
    let action = $('#drugId').val() ? 'save_drug' : 'save_drug';
    formData += '&action=' + action;
    showLoading(true);
    $.ajax({ url: window.location.pathname + '?ajax=pharmacy_action', method: 'POST', data: formData, dataType: 'json', success: function(res){
        showLoading(false);
        if(res.success){ Swal.fire('Success', res.message, 'success'); $('#drugModal').modal('hide'); loadDrugsTable(); }
        else Swal.fire('Error', res.message, 'error');
    }, error: function(){ showLoading(false); Swal.fire('Error', 'Network error', 'error'); } });
}

function loadProcurementTable() {
    let branch = $('#globalBranchFilter').val();
    $.ajax({ url: window.location.pathname + '?ajax=pharmacy_action', method: 'POST', dataType: 'json', data: { action: 'get_pending_orders', branch_id: branch, csrf_token: csrfToken }, success: function(res){
        if(res.success && res.orders){
            let rows = '';
            res.orders.forEach(o => {
                let itemsHtml = '<ul class="list-unstyled mb-0">';
                o.items.forEach(it => { itemsHtml += `<li>${escapeHtml(it.generic_name)} ${escapeHtml(it.brand_name)} (${it.quantity} units)</li>`; });
                itemsHtml += '</ul>';
                rows += `<tr>
                            <td>${escapeHtml(o.purchase_order_number)}</td>
                            <td>${escapeHtml(o.supplier_name)}</td>
                            <td>${parseFloat(o.total_amount).toFixed(2)}</td>
                            <td>${o.order_date}</td>
                            <td>${itemsHtml}</td>
                            <td><button class="btn btn-sm btn-success approve-order" data-id="${o.id}"><i class="fas fa-check"></i> Approve & Inject Stock</button></td>
                         </tr>`;
            });
            $('#procurementTable tbody').html(rows);
            $('.approve-order').on('click', function(){ approveOrder($(this).data('id')); });
        } else { $('#procurementTable tbody').html('<tr><td colspan="6">No pending orders.</td></tr>'); }
    }});
}
function approveOrder(orderId){
    Swal.fire({ title: 'Approve Order', text: 'This will add all items to pharmacy inventory. Proceed?', icon: 'question', showCancelButton: true }).then(res=>{
        if(res.isConfirmed){
            showLoading(true);
            $.ajax({ url: window.location.pathname + '?ajax=pharmacy_action', method: 'POST', dataType: 'json', data: { action: 'approve_order', order_id: orderId, csrf_token: csrfToken }, success: function(r){
                showLoading(false);
                if(r.success){ Swal.fire('Success', r.message, 'success'); loadProcurementTable(); loadAlertsTables(); }
                else Swal.fire('Error', r.message, 'error');
            }, error: function(){ showLoading(false); Swal.fire('Error', 'Network error', 'error'); } });
        }
    });
}

function loadAlertsTables() {
    let branch = $('#globalBranchFilter').val();
    $.ajax({ url: window.location.pathname + '?ajax=pharmacy_action', method: 'POST', dataType: 'json', data: { action: 'get_inventory_alerts', branch_id: branch, csrf_token: csrfToken }, success: function(res){
        if(res.success){
            // Expiry table
            let expiryRows = '';
            if(res.expiring.length > 0){
                res.expiring.forEach(e => {
                    expiryRows += `<tr class="expiry-warning"><td>${escapeHtml(e.generic_name)} ${escapeHtml(e.brand_name)}</td><td>${escapeHtml(e.batch_number)}</td><td>${e.expiry_date}</td><td>${e.quantity_in_hand}</td></tr>`;
                });
            } else { expiryRows = '<tr><td colspan="4">No expiring stock within 90 days.</td></tr>'; }
            $('#expiryTable tbody').html(expiryRows);

            // Reorder table
            let reorderRows = '';
            if(res.reorder.length > 0){
                res.reorder.forEach(r => {
                    reorderRows += `<tr class="reorder-warning"><td>${escapeHtml(r.generic_name)} ${escapeHtml(r.brand_name)}</td><td>${escapeHtml(r.batch_number)}</td><td>${r.quantity_in_hand}</td><td>${r.reorder_level}</td></tr>`;
                });
            } else { reorderRows = '<tr><td colspan="4">All stock levels are above reorder threshold.</td></tr>'; }
            $('#reorderTable tbody').html(reorderRows);
        }
    }});
}

function escapeHtml(str) { if(!str) return ''; return String(str).replace(/[&<>]/g, m => m === '&' ? '&amp;' : (m === '<' ? '&lt;' : '&gt;')); }
function showLoading(show){ if(show) $('#loadingOverlay').addClass('active'); else $('#loadingOverlay').removeClass('active'); }
</script>
</body>
</html>