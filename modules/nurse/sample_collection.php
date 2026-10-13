<?php
/**
 * ======================================================================
 * PHLEBOTOMY & LAB SAMPLE COLLECTION - PHASE 5 (FIXED)
 * Track active doctor lab orders, capture sample barcodes, transition statuses
 * Strict multi-tenancy | RBAC | CSRF | PDO transactions | Responsive
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
$nurse_name = $_SESSION['first_name'] ?? $_SESSION['username'] ?? 'Nurse';

if ($user_id <= 0) {
    header('Location: ../../index.php');
    exit;
}

$has_collection_access = user_has_permission($pdo, 'sample_collection_access', $user_id);
if (!$is_global_admin && !$has_collection_access) {
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

// ----------------------------------------------------------------------
// AJAX HANDLERS
// ----------------------------------------------------------------------
if (isset($_GET['action'])) {
    // 1) Fetch order details (items) for a given lab_order_id
    if ($_GET['action'] === 'fetch_order_details') {
        $lab_order_id = (int)($_GET['lab_order_id'] ?? 0);
        if ($lab_order_id <= 0) jsonResponse(false, 'Invalid order ID');

        try {
            // Verify order belongs to the branch
            $check = $pdo->prepare("SELECT id FROM lab_orders WHERE id = :id AND branch_id = :branch_id AND deleted_at IS NULL");
            $check->execute([':id' => $lab_order_id, ':branch_id' => $branch_id]);
            if (!$check->fetch()) jsonResponse(false, 'Order not found or access denied');

            // Fetch order header (patient, doctor) - Using correct column names from lab_orders table
            $orderSql = "SELECT lo.id, lo.order_number, lo.priority, lo.created_at, lo.status,
                               p.id AS patient_id, p.mr_number,
                               CONCAT(
                                   COALESCE(p.first_name, ''),
                                   ' ',
                                   COALESCE(p.middle_name, ''),
                                   ' ',
                                   COALESCE(p.last_name, '')
                               ) AS patient_name,
                               p.gender, p.date_of_birth,
                               u.first_name AS doctor_first, u.last_name AS doctor_last
                        FROM lab_orders lo
                        INNER JOIN patients p ON lo.patient_id = p.id AND p.deleted_at IS NULL
                        LEFT JOIN users u ON lo.doctor_id = u.id
                        WHERE lo.id = :id AND lo.deleted_at IS NULL";
            $orderStmt = $pdo->prepare($orderSql);
            $orderStmt->execute([':id' => $lab_order_id]);
            $order = $orderStmt->fetch(PDO::FETCH_ASSOC);
            if (!$order) jsonResponse(false, 'Order header not found');
            
            // Clean up patient name
            $order['patient_name'] = preg_replace('/\s+/', ' ', trim($order['patient_name'] ?? 'Unknown Patient'));

            // Fetch items
            $itemSql = "SELECT id, test_name, sample_type, status, sample_barcode
                        FROM lab_order_items
                        WHERE lab_order_id = :order_id AND deleted_at IS NULL
                        ORDER BY id ASC";
            $itemStmt = $pdo->prepare($itemSql);
            $itemStmt->execute([':order_id' => $lab_order_id]);
            $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);
            if (!is_array($items)) $items = [];

            // Age calculation
            $age = 'N/A';
            if (!empty($order['date_of_birth'])) {
                $dob = new DateTime($order['date_of_birth']);
                $now = new DateTime();
                $age = $dob->diff($now)->y;
            }
            $order['age'] = $age;

            jsonResponse(true, '', ['order' => $order, 'items' => $items]);
        } catch (PDOException $e) {
            jsonResponse(false, 'Database error: ' . $e->getMessage());
        }
        exit;
    }

    // 2) POST: Collect samples (update statuses with barcodes)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'collect_samples') {
        $incoming_token = $_POST['csrf_token'] ?? '';
        if (!hash_equals($csrf_token, $incoming_token)) {
            jsonResponse(false, 'Security token mismatch');
        }

        $lab_order_id = (int)($_POST['lab_order_id'] ?? 0);
        $items_data = isset($_POST['items']) ? json_decode($_POST['items'], true) : [];
        if (!$lab_order_id || empty($items_data)) {
            jsonResponse(false, 'Missing order or item data');
        }

        $pdo->beginTransaction();
        try {
            // Re-verify order belongs to branch and has items in 'ordered' status
            $checkOrder = $pdo->prepare("SELECT id FROM lab_orders WHERE id = :id AND branch_id = :branch_id AND deleted_at IS NULL FOR UPDATE");
            $checkOrder->execute([':id' => $lab_order_id, ':branch_id' => $branch_id]);
            if (!$checkOrder->fetch()) throw new PDOException('Order not valid');

            $all_updated = true;
            foreach ($items_data as $item) {
                $item_id = (int)($item['item_id'] ?? 0);
                $barcode = trim($item['barcode'] ?? '');
                if ($item_id <= 0 || empty($barcode)) {
                    throw new PDOException('Item ID or barcode missing for one of the tests');
                }

                // Fetch current status of this item
                $itemCheck = $pdo->prepare("SELECT status FROM lab_order_items WHERE id = :id AND lab_order_id = :order_id AND deleted_at IS NULL FOR UPDATE");
                $itemCheck->execute([':id' => $item_id, ':order_id' => $lab_order_id]);
                $current = $itemCheck->fetch(PDO::FETCH_ASSOC);
                if (!$current) throw new PDOException("Item #{$item_id} not found");
                if ($current['status'] !== 'ordered') {
                    throw new PDOException("Item #{$item_id} is already collected or processed");
                }

                // Check barcode uniqueness across the same order (optional but recommended)
                $dupCheck = $pdo->prepare("SELECT id FROM lab_order_items WHERE sample_barcode = :barcode AND lab_order_id = :order_id AND deleted_at IS NULL");
                $dupCheck->execute([':barcode' => $barcode, ':order_id' => $lab_order_id]);
                if ($dupCheck->fetch()) {
                    throw new PDOException("Barcode '{$barcode}' already used for another test in this order");
                }

                // Update item
                $update = $pdo->prepare("UPDATE lab_order_items 
                                         SET status = 'sample_collected', 
                                             sample_barcode = :barcode, 
                                             collected_by_user_id = :user_id, 
                                             collected_at = NOW() 
                                         WHERE id = :id");
                $update->execute([':barcode' => $barcode, ':user_id' => $user_id, ':id' => $item_id]);
            }

            // After items updated, check if all items of this order are now collected (or beyond)
            $remaining = $pdo->prepare("SELECT COUNT(*) FROM lab_order_items WHERE lab_order_id = :order_id AND status = 'ordered' AND deleted_at IS NULL");
            $remaining->execute([':order_id' => $lab_order_id]);
            $remaining_count = (int)$remaining->fetchColumn();
            if ($remaining_count == 0) {
                // All items collected: update parent order status to 'collected' and set specimen collection details
                $updateOrder = $pdo->prepare("UPDATE lab_orders 
                                              SET status = 'collected', 
                                                  specimen_collected_at = NOW(),
                                                  specimen_collected_by_user_id = :user_id,
                                                  updated_at = NOW() 
                                              WHERE id = :id");
                $updateOrder->execute([':user_id' => $user_id, ':id' => $lab_order_id]);
            }

            $pdo->commit();
            jsonResponse(true, 'Sample collection recorded successfully');
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            jsonResponse(false, 'Database error: ' . $e->getMessage());
        }
        exit;
    }
}

// ----------------------------------------------------------------------
// INITIAL PAGE LOAD: Fetch pending lab orders (with at least one item status = 'ordered')
// ----------------------------------------------------------------------
$pending_orders = [];

try {
    $sql = "SELECT DISTINCT
                lo.id AS lab_order_id,
                lo.order_number,
                lo.priority,
                lo.created_at,
                p.id AS patient_id,
                p.mr_number,
                CONCAT(
                    COALESCE(p.first_name, ''),
                    ' ',
                    COALESCE(p.middle_name, ''),
                    ' ',
                    COALESCE(p.last_name, '')
                ) AS patient_name,
                p.gender,
                p.date_of_birth
            FROM lab_orders lo
            INNER JOIN patients p ON lo.patient_id = p.id AND p.deleted_at IS NULL
            INNER JOIN lab_order_items loi ON lo.id = loi.lab_order_id
            WHERE lo.branch_id = :branch_id
              AND loi.status = 'ordered'
              AND loi.deleted_at IS NULL
              AND lo.deleted_at IS NULL
            ORDER BY 
                FIELD(lo.priority, 'stat', 'urgent', 'routine'),
                lo.id ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':branch_id' => $branch_id]);
    $pending_orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Clean up patient names
    foreach ($pending_orders as &$order) {
        $order['patient_name'] = preg_replace('/\s+/', ' ', trim($order['patient_name'] ?? 'Unknown Patient'));
    }
} catch (PDOException $e) {
    error_log('Pending orders fetch error: ' . $e->getMessage());
    $pending_orders = [];
}

// Safely count pending orders
$pending_count = is_array($pending_orders) ? count($pending_orders) : 0;

$current_date = date('l, F j, Y');
$current_time = date('g:i A');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include('assets/inc/head.php'); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sample Collection | Phlebotomy Hub</title>
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
        .card-header-custom { padding: 16px 20px; background: #f8fafc; border-bottom: 1px solid var(--gray-light); font-weight: 700; display: flex; justify-content: space-between; align-items: center; }
        .order-list-sidebar { max-height: calc(100vh - 220px); overflow-y: auto; }
        .order-card { padding: 14px 16px; border-bottom: 1px solid #f0f0f0; cursor: pointer; transition: all 0.2s; }
        .order-card:hover { background: #f8fafc; }
        .order-card.active { background: #e0e7ff; border-left: 3px solid var(--primary); }
        .priority-badge { display: inline-block; padding: 4px 8px; border-radius: 20px; font-size: 11px; font-weight: 700; }
        .priority-stat { background: #dc2626; color: white; }
        .priority-urgent { background: #f97316; color: white; }
        .priority-regular { background: #3b82f6; color: white; }
        .empty-state { text-align: center; padding: 40px; color: var(--gray); }
        .btn-submit { background: var(--primary); color: white; border: none; padding: 10px 20px; border-radius: 12px; font-weight: 600; width: 100%; transition: 0.2s; }
        .btn-submit:hover { background: var(--primary-dark); }
        .btn-submit:disabled { opacity: 0.6; cursor: not-allowed; }
        .toast-notification { position: fixed; bottom: 20px; right: 20px; background: white; border-radius: 12px; padding: 12px 20px; box-shadow: 0 10px 25px rgba(0,0,0,0.1); z-index: 1100; animation: slideIn 0.3s ease; border-left: 4px solid var(--success); }
        .toast-error { border-left-color: var(--danger); }
        @keyframes slideIn { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
        .loading-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(255,255,255,0.7); z-index: 2000; display: none; align-items: center; justify-content: center; }
        .loading-overlay.active { display: flex; }
        .spinner { width: 40px; height: 40px; border: 3px solid var(--gray-light); border-top-color: var(--primary); border-radius: 50%; animation: spin 0.8s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }
        .sample-item-row { background: #fefce8; border-radius: 12px; padding: 12px; margin-bottom: 12px; border: 1px solid #fde68a; }
        .sample-item-row.collected { background: #d1fae5; border-color: #34d399; }
        .apply-all-btn { background: #e2e8f0; border: none; border-radius: 8px; padding: 4px 12px; font-size: 12px; }
        .order-status-badge { font-size: 12px; padding: 4px 12px; }
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
                    <div class="welcome-title">Sample Collection & Phlebotomy</div>
                    <div class="welcome-subtitle">የላብራቶሪ ናሙና/ደም መሰብሰቢያ | Lab order tracking & barcode dispatch</div>
                </div>
                <div class="time-badge"><i class="far fa-calendar-alt"></i> <?php echo escapeHtml($current_date); ?> <i class="far fa-clock ms-2"></i> <?php echo escapeHtml($current_time); ?></div>
            </div>

            <div class="row g-4">
                <!-- LEFT: Pending lab orders -->
                <div class="col-lg-4 col-xl-3">
                    <div class="card-modern">
                        <div class="card-header-custom">
                            <h5 class="mb-0"><i class="fas fa-flask me-2"></i> በመጠባበቅ ላይ ያሉ ትዕዛዞች / Pending Orders</h5>
                            <span class="badge bg-primary"><?php echo $pending_count; ?></span>
                        </div>
                        <div class="order-list-sidebar" id="orderList">
                            <?php if ($pending_count > 0 && is_array($pending_orders)): ?>
                                <?php foreach ($pending_orders as $order): 
                                    $fullName = trim($order['patient_name'] ?? 'Unknown Patient');
                                    $priorityClass = '';
                                    $priorityText = '';
                                    if ($order['priority'] === 'stat') {
                                        $priorityClass = 'priority-stat';
                                        $priorityText = 'STAT';
                                    } elseif ($order['priority'] === 'urgent') {
                                        $priorityClass = 'priority-urgent';
                                        $priorityText = 'Urgent';
                                    } else {
                                        $priorityClass = 'priority-regular';
                                        $priorityText = 'Regular';
                                    }
                                ?>
                                    <div class="order-card" data-lab-order-id="<?php echo $order['lab_order_id']; ?>">
                                        <div class="d-flex justify-content-between align-items-start">
                                            <div>
                                                <div class="fw-bold"><?php echo escapeHtml($fullName); ?></div>
                                                <small class="text-muted">MRN: <?php echo escapeHtml($order['mr_number'] ?? ''); ?></small>
                                                <div class="mt-1"><span class="priority-badge <?php echo $priorityClass; ?>"><?php echo $priorityText; ?></span></div>
                                            </div>
                                            <div class="text-end">
                                                <small class="text-muted">#<?php echo escapeHtml($order['order_number']); ?></small><br>
                                                <small><?php echo date('H:i', strtotime($order['created_at'])); ?></small>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="empty-state"><i class="fas fa-check-circle fa-2x mb-2"></i><p>ምንም በመጠባበቅ ላይ ያሉ የላብራቶሪ ትዕዛዞች የሉም / No pending lab orders</p></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- RIGHT: Sample collection workspace -->
                <div class="col-lg-8 col-xl-9">
                    <div class="card-modern" style="min-height: 500px;">
                        <div id="emptyStateContainer" class="empty-state py-5">
                            <i class="fas fa-syringe fa-3x mb-3 text-muted"></i>
                            <h5>No Order Selected</h5>
                            <p class="text-muted">እባክዎ መጀመሪያ የላብራቶሪ ትዕዛዝ ይምረጡ / Please select a lab order from the left panel</p>
                        </div>
                        <div id="activeWorkspaceContainer" style="display: none;">
                            <!-- Order summary header -->
                            <div class="p-3 bg-white border-bottom">
                                <div class="row align-items-center">
                                    <div class="col-md-8">
                                        <h4 id="orderPatientName" class="mb-1">-</h4>
                                        <div class="text-muted small">
                                            MRN: <span id="orderMrn">-</span> | 
                                            Gender: <span id="orderGender">-</span> | 
                                            Age: <span id="orderAge">-</span>
                                        </div>
                                        <div class="small mt-1">📋 Order #<span id="orderNumber"></span> | 👨‍⚕️ Dr. <span id="orderDoctor"></span></div>
                                    </div>
                                    <div class="col-md-4 text-md-end mt-2 mt-md-0">
                                        <span class="badge bg-danger" id="orderPriorityBadge"></span>
                                        <span class="badge bg-secondary ms-1" id="orderStatusBadge"></span>
                                    </div>
                                </div>
                            </div>

                            <!-- Sample items checklist -->
                            <div class="p-3">
                                <form id="sampleCollectionForm">
                                    <input type="hidden" id="labOrderId" name="lab_order_id">
                                    <div id="itemsContainer">
                                        <!-- dynamic test items will appear here -->
                                    </div>
                                    <div class="mt-4">
                                        <button type="submit" id="submitCollectionBtn" class="btn-submit"><i class="fas fa-check-circle me-2"></i> Confirm Collection & Dispatch / ናሙናዎችን መዝግብና ላክ</button>
                                    </div>
                                </form>
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
const csrfToken = <?php echo json_encode($csrf_token); ?>;
let currentLabOrderId = null;

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

// Load order details and populate items
function loadOrderDetails(orderId) {
    showLoading(true);
    fetch(window.location.pathname + `?action=fetch_order_details&lab_order_id=${orderId}`)
        .then(res => res.json())
        .then(data => {
            showLoading(false);
            if (data.success && data.data) {
                const order = data.data.order;
                const items = data.data.items;
                currentLabOrderId = orderId;
                document.getElementById('labOrderId').value = orderId;
                document.getElementById('orderPatientName').innerText = order.patient_name || 'Unknown';
                document.getElementById('orderMrn').innerText = order.mr_number || '-';
                document.getElementById('orderGender').innerText = order.gender ? order.gender.charAt(0).toUpperCase() + order.gender.slice(1) : '-';
                document.getElementById('orderAge').innerText = order.age || 'N/A';
                document.getElementById('orderNumber').innerText = order.order_number || '';
                document.getElementById('orderDoctor').innerText = `${order.doctor_first || ''} ${order.doctor_last || ''}`.trim() || 'N/A';
                
                const priorityText = { stat: 'STAT', urgent: 'Urgent', routine: 'Routine' }[order.priority] || order.priority;
                document.getElementById('orderPriorityBadge').innerText = priorityText;
                
                // Show order status
                const statusMap = {
                    'pending_payment': 'Pending Payment',
                    'pending': 'Pending',
                    'approved': 'Approved',
                    'collected': 'Collected',
                    'in_progress': 'In Progress',
                    'completed': 'Completed',
                    'cancelled': 'Cancelled',
                    'rejected': 'Rejected'
                };
                document.getElementById('orderStatusBadge').innerText = statusMap[order.status] || order.status || 'Unknown';

                // Build items HTML
                let itemsHtml = '';
                let hasOrderedItems = false;
                items.forEach((item, idx) => {
                    const isOrdered = item.status === 'ordered';
                    if (isOrdered) hasOrderedItems = true;
                    const statusClass = isOrdered ? 'bg-warning' : 'bg-success';
                    const itemClass = !isOrdered ? 'collected' : '';
                    itemsHtml += `
                        <div class="sample-item-row ${itemClass}" data-item-id="${item.id}" data-sample-type="${escapeHtml(item.sample_type || '')}">
                            <div class="d-flex justify-content-between align-items-start flex-wrap">
                                <div class="flex-grow-1">
                                    <div class="fw-bold">${escapeHtml(item.test_name)}</div>
                                    <div class="small text-muted">Sample: ${escapeHtml(item.sample_type || 'N/A')} | Status: <span class="badge ${statusClass}">${escapeHtml(item.status)}</span></div>
                                </div>
                                <div class="ms-2" style="min-width: 200px;">
                                    <div class="input-group input-group-sm">
                                        <input type="text" class="form-control barcode-input" placeholder="Scan / Enter Barcode" data-item-id="${item.id}" value="${escapeHtml(item.sample_barcode || '')}" ${!isOrdered ? 'disabled' : ''}>
                                        <button class="btn btn-outline-secondary apply-same-type-btn" type="button" data-sample-type="${escapeHtml(item.sample_type || '')}" ${!isOrdered ? 'disabled' : ''}><i class="fas fa-copy"></i></button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    `;
                });
                document.getElementById('itemsContainer').innerHTML = itemsHtml;

                // Enable/disable submit button based on whether there are items to collect
                document.getElementById('submitCollectionBtn').disabled = !hasOrderedItems;

                // Focus on first enabled barcode field
                const firstInput = document.querySelector('#itemsContainer .barcode-input:not([disabled])');
                if (firstInput) firstInput.focus();

                // Attach "Apply to same sample type" buttons
                document.querySelectorAll('.apply-same-type-btn').forEach(btn => {
                    btn.addEventListener('click', (e) => {
                        const sampleType = btn.getAttribute('data-sample-type');
                        const sourceInput = btn.closest('.sample-item-row')?.querySelector('.barcode-input');
                        if (!sourceInput) return;
                        const barcodeValue = sourceInput.value;
                        if (!barcodeValue) {
                            showToast('Please enter a barcode first', 'error');
                            return;
                        }
                        document.querySelectorAll(`.sample-item-row[data-sample-type="${sampleType}"] .barcode-input`).forEach(input => {
                            if (!input.disabled) input.value = barcodeValue;
                        });
                        showToast(`Applied barcode to all ${sampleType} samples`, 'success');
                    });
                });

                document.getElementById('emptyStateContainer').style.display = 'none';
                document.getElementById('activeWorkspaceContainer').style.display = 'block';
            } else {
                showToast(data.message || 'Failed to load order details', 'error');
            }
        })
        .catch(err => { showLoading(false); showToast('Network error', 'error'); console.error(err); });
}

// Handle form submission
document.getElementById('sampleCollectionForm').addEventListener('submit', function(e) {
    e.preventDefault();
    const orderId = document.getElementById('labOrderId').value;
    if (!orderId) { showToast('Invalid order', 'error'); return; }

    const items = [];
    const barcodeInputs = document.querySelectorAll('#itemsContainer .barcode-input');
    let hasError = false;
    let hasItemsToCollect = false;
    
    barcodeInputs.forEach(input => {
        if (!input.disabled) {
            hasItemsToCollect = true;
            const itemId = input.getAttribute('data-item-id');
            const barcode = input.value.trim();
            if (!barcode) {
                showToast('እባክዎ ለሁሉም የተመረጡ ምርመራዎች የባርኮድ ቁጥር ያስገቡ! / Please enter barcode for all tests', 'error');
                hasError = true;
                input.focus();
                return;
            }
            items.push({ item_id: parseInt(itemId), barcode: barcode });
        }
    });
    
    if (hasError) return;
    if (!hasItemsToCollect) {
        showToast('No items available for collection. All items may already be collected.', 'error');
        return;
    }

    showLoading(true);
    fetch(window.location.pathname + '?action=collect_samples', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({
            lab_order_id: orderId,
            items: JSON.stringify(items),
            csrf_token: csrfToken
        })
    })
    .then(res => res.json())
    .then(data => {
        showLoading(false);
        if (data.success) {
            showToast(data.message);
            // Refresh order list and clear active workspace
            location.reload();
        } else {
            showToast(data.message || 'Submission failed', 'error');
        }
    })
    .catch(err => { showLoading(false); showToast('Network error', 'error'); console.error(err); });
});

// Order card click delegation
$(document).on('click', '.order-card', function() {
    const orderId = $(this).data('lab-order-id');
    if (!orderId) return;
    $('.order-card').removeClass('active');
    $(this).addClass('active');
    loadOrderDetails(orderId);
});

// Auto-select first order on page load
$(document).ready(() => {
    const firstCard = document.querySelector('.order-card');
    if (firstCard) {
        firstCard.classList.add('active');
        const firstId = firstCard.getAttribute('data-lab-order-id');
        if (firstId) loadOrderDetails(firstId);
    }
});

// Sidebar compatibility
if (typeof MetisMenu !== 'undefined') {
    const sideMenu = document.getElementById('side-menu');
    if (sideMenu && !sideMenu.hasAttribute('data-metismenu-initialized')) {
        new MetisMenu(sideMenu, { toggle: true, triggerElement: 'a.has-arrow', parentTrigger: 'li', subMenu: 'ul' });
        sideMenu.setAttribute('data-metismenu-initialized', 'true');
    }
}
document.getElementById('mobileMenuToggle')?.addEventListener('click', function(e) {
    e.preventDefault();
    document.body.classList.toggle('sidebar-enable');
});
window.addEventListener('resize', function() {
    if (window.innerWidth > 768 && document.body.classList.contains('sidebar-enable')) document.body.classList.remove('sidebar-enable');
});
</script>
</body>
</html>