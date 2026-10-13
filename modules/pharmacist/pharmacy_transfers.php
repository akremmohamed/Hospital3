<?php
/**
 * Inter-Branch Stock Requisition & Transfer Matrix
 * Pharmacist Module: Cross-branch stock lookup, transfer requests, approval workflow.
 * Uses branch_transfers, pharmacy_inventory, medicines_master, branches tables.
 *
 * @package HMS-ERP
 * @version 1.1 (removed auto-refresh)
 */

if (session_status() === PHP_SESSION_NONE) session_start();

// ============================================
// 1. IRONCLAD AUTHENTICATION – PHARMACIST ONLY
// ============================================
if (!isset($_SESSION['user_id']) || !isset($_SESSION['branch_id']) || ($_SESSION['user_type'] ?? '') !== 'pharmacist') {
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized Access. Strict Pharmacist Permissions Required.']);
        exit;
    }
    header('Location: ../login.php');
    exit;
}

require_once __DIR__ . '/../../config/db_connection.php';
date_default_timezone_set('Africa/Addis_Ababa');
$pdo = getDB();
if (!$pdo) die('Database connection failed.');

// CSRF token for mutation requests
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Ensure branch_transfers table has required columns (idempotent)
try {
    $checkColumns = $pdo->query("SHOW COLUMNS FROM branch_transfers LIKE 'item_reference_details'");
    if ($checkColumns->rowCount() === 0) {
        $pdo->exec("ALTER TABLE branch_transfers ADD COLUMN item_reference_details TEXT NULL AFTER amount_or_value");
    }
    $checkStatus = $pdo->query("SHOW COLUMNS FROM branch_transfers LIKE 'transfer_status'");
    if ($checkStatus->rowCount() === 0) {
        $pdo->exec("ALTER TABLE branch_transfers ADD COLUMN transfer_status ENUM('pending','approved','rejected','completed') NOT NULL DEFAULT 'pending'");
    }
    // Add medicine_id column for pharmacy stock tracking
    $checkMedId = $pdo->query("SHOW COLUMNS FROM branch_transfers LIKE 'medicine_id'");
    if ($checkMedId->rowCount() === 0) {
        $pdo->exec("ALTER TABLE branch_transfers ADD COLUMN medicine_id INT UNSIGNED NULL, ADD COLUMN quantity_transferred INT NOT NULL DEFAULT 0");
    }
} catch (PDOException $e) {
    error_log('Table alteration failed: ' . $e->getMessage());
}

// ============================================
// 2. ASYNCHRONOUS AJAX ROUTER PIPELINE
// ============================================
if (isset($_GET['action'])) {
    error_reporting(0);
    ini_set('display_errors', 0);
    if (ob_get_level()) ob_clean();
    header('Content-Type: application/json; charset=utf-8');

    // Re-authenticate for every AJAX call
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['branch_id']) || ($_SESSION['user_type'] ?? '') !== 'pharmacist') {
        echo json_encode(['status' => 'error', 'message' => 'Session expired or unauthorized.']);
        exit;
    }

    $branchId = (int)$_SESSION['branch_id'];
    $userId = (int)$_SESSION['user_id'];
    $action = $_GET['action'];

    // ---------- 2.1 Load Current Branch Stock Grid ----------
    if ($action === 'load_stock_grid') {
        $search = trim($_GET['search'] ?? '');
        try {
            $sql = "
                SELECT 
                    mm.id AS medicine_id,
                    mm.medicine_code,
                    mm.generic_name,
                    mm.brand_name,
                    SUM(pi.quantity_in_hand) AS total_quantity
                FROM pharmacy_inventory pi
                INNER JOIN medicines_master mm ON pi.medicine_id = mm.id
                WHERE pi.branch_id = :branch_id
                  AND pi.deleted_at IS NULL
                  AND mm.is_active = 1
            ";
            $params = [':branch_id' => $branchId];
            if (!empty($search)) {
                $sql .= " AND (mm.medicine_code LIKE :search OR mm.generic_name LIKE :search OR mm.brand_name LIKE :search)";
                $params[':search'] = "%$search%";
            }
            $sql .= " GROUP BY mm.id ORDER BY mm.generic_name ASC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $stock = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['status' => 'success', 'stock' => $stock]);
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
        }
        exit;
    }

    // ---------- 2.2 Search External Branches for Stock ----------
    if ($action === 'search_external_stock') {
        $itemCode = trim($_GET['item_code'] ?? '');
        if (empty($itemCode)) {
            echo json_encode(['status' => 'error', 'message' => 'Please enter an item code.']);
            exit;
        }
        try {
            $sql = "
                SELECT 
                    b.id AS branch_id,
                    b.name AS branch_name,
                    SUM(pi.quantity_in_hand) AS available_quantity,
                    mm.medicine_code,
                    mm.generic_name
                FROM pharmacy_inventory pi
                INNER JOIN medicines_master mm ON pi.medicine_id = mm.id
                INNER JOIN branches b ON pi.branch_id = b.id
                WHERE mm.medicine_code = :code
                  AND pi.branch_id != :current_branch
                  AND pi.deleted_at IS NULL
                  AND b.deleted_at IS NULL
                  AND pi.quantity_in_hand > 0
                GROUP BY b.id, mm.id
                ORDER BY b.name
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':code' => $itemCode, ':current_branch' => $branchId]);
            $externalBranches = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['status' => 'success', 'external_stock' => $externalBranches, 'item_code' => $itemCode]);
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
        }
        exit;
    }

    // ---------- 2.3 Submit Requisition (Transfer Request) ----------
    if ($action === 'submit_requisition') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['status' => 'error', 'message' => 'Invalid request method.']);
            exit;
        }
        $submittedToken = $_POST['csrf_token'] ?? '';
        if (!hash_equals($csrf_token, $submittedToken)) {
            echo json_encode(['status' => 'error', 'message' => 'CSRF validation failed.']);
            exit;
        }

        $sourceBranchId = (int)($_POST['source_branch_id'] ?? 0);
        $medicineId = (int)($_POST['medicine_id'] ?? 0);
        $quantity = (int)($_POST['quantity'] ?? 0);
        $itemCode = trim($_POST['item_code'] ?? '');
        $medicineName = trim($_POST['medicine_name'] ?? '');

        if ($sourceBranchId <= 0 || $medicineId <= 0 || $quantity <= 0) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid transfer parameters.']);
            exit;
        }

        // Verify source branch has sufficient stock
        try {
            $pdo->beginTransaction();
            $stockCheck = $pdo->prepare("
                SELECT SUM(quantity_in_hand) AS total
                FROM pharmacy_inventory
                WHERE branch_id = :branch_id AND medicine_id = :medicine_id AND deleted_at IS NULL
            ");
            $stockCheck->execute([':branch_id' => $sourceBranchId, ':medicine_id' => $medicineId]);
            $available = (int)$stockCheck->fetchColumn();
            if ($available < $quantity) {
                $pdo->rollBack();
                echo json_encode(['status' => 'error', 'message' => 'Insufficient stock at source branch. Available: ' . $available]);
                exit;
            }

            // Create transfer request (destination = current branch)
            $transferNumber = 'TRF-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
            $insert = $pdo->prepare("
                INSERT INTO branch_transfers 
                (from_branch_id, to_branch_id, transfer_type, amount_or_value, item_reference_details, transfer_reference_number, transfer_status, initiated_by_user_id, medicine_id, quantity_transferred, created_at)
                VALUES (:from, :to, 'pharmacy_stock', 0, :details, :ref, 'pending', :user_id, :med_id, :qty, NOW())
            ");
            $details = json_encode(['medicine_id' => $medicineId, 'item_code' => $itemCode, 'medicine_name' => $medicineName, 'quantity' => $quantity]);
            $insert->execute([
                ':from' => $sourceBranchId,
                ':to' => $branchId,
                ':details' => $details,
                ':ref' => $transferNumber,
                ':user_id' => $userId,
                ':med_id' => $medicineId,
                ':qty' => $quantity
            ]);
            $pdo->commit();
            echo json_encode(['status' => 'success', 'message' => 'Requisition submitted. Awaiting approval from source branch.']);
        } catch (PDOException $e) {
            $pdo->rollBack();
            echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
        }
        exit;
    }

    // ---------- 2.4 Process Transfer Approval (Approve/Reject) ----------
    if ($action === 'process_transfer_approval') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['status' => 'error', 'message' => 'Invalid request method.']);
            exit;
        }
        $submittedToken = $_POST['csrf_token'] ?? '';
        if (!hash_equals($csrf_token, $submittedToken)) {
            echo json_encode(['status' => 'error', 'message' => 'CSRF validation failed.']);
            exit;
        }

        $transferId = (int)($_POST['transfer_id'] ?? 0);
        $actionType = $_POST['action_type'] ?? ''; // 'approve' or 'reject'
        $rejectionReason = trim($_POST['rejection_reason'] ?? '');

        if ($transferId <= 0 || !in_array($actionType, ['approve', 'reject'])) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid request.']);
            exit;
        }

        try {
            $pdo->beginTransaction();

            // Fetch transfer details with locking
            $fetch = $pdo->prepare("
                SELECT bt.*, 
                       mm.medicine_code,
                       pi.quantity_in_hand AS source_stock
                FROM branch_transfers bt
                INNER JOIN medicines_master mm ON bt.medicine_id = mm.id
                LEFT JOIN pharmacy_inventory pi ON pi.branch_id = bt.from_branch_id AND pi.medicine_id = bt.medicine_id AND pi.deleted_at IS NULL
                WHERE bt.id = :id FOR UPDATE
            ");
            $fetch->execute([':id' => $transferId]);
            $transfer = $fetch->fetch(PDO::FETCH_ASSOC);
            if (!$transfer) {
                $pdo->rollBack();
                echo json_encode(['status' => 'error', 'message' => 'Transfer request not found.']);
                exit;
            }

            // Security: only source branch pharmacist can approve (from_branch_id == current branch)
            if ($transfer['from_branch_id'] != $branchId) {
                $pdo->rollBack();
                echo json_encode(['status' => 'error', 'message' => 'You are not authorized to process this transfer.']);
                exit;
            }

            if ($transfer['transfer_status'] !== 'pending') {
                $pdo->rollBack();
                echo json_encode(['status' => 'error', 'message' => 'This transfer has already been processed.']);
                exit;
            }

            if ($actionType === 'reject') {
                // Update status to rejected
                $update = $pdo->prepare("UPDATE branch_transfers SET transfer_status = 'rejected', rejection_reason = :reason, updated_at = NOW() WHERE id = :id");
                $update->execute([':reason' => $rejectionReason ?: 'Rejected by source branch', ':id' => $transferId]);
                $pdo->commit();
                echo json_encode(['status' => 'success', 'message' => 'Transfer request rejected.']);
                exit;
            }

            // Approve: transfer stock
            if ($actionType === 'approve') {
                // Check source stock again (for safety)
                $sourceStockCheck = $pdo->prepare("
                    SELECT SUM(quantity_in_hand) AS total
                    FROM pharmacy_inventory
                    WHERE branch_id = :branch_id AND medicine_id = :medicine_id AND deleted_at IS NULL
                ");
                $sourceStockCheck->execute([
                    ':branch_id' => $transfer['from_branch_id'],
                    ':medicine_id' => $transfer['medicine_id']
                ]);
                $availableQty = (int)$sourceStockCheck->fetchColumn();
                if ($availableQty < $transfer['quantity_transferred']) {
                    $pdo->rollBack();
                    echo json_encode(['status' => 'error', 'message' => 'Insufficient stock at source branch now. Available: ' . $availableQty]);
                    exit;
                }

                // Deduct from source branch (oldest batches first - simplify by deducting from any batch)
                $deduct = $pdo->prepare("
                    UPDATE pharmacy_inventory 
                    SET quantity_in_hand = quantity_in_hand - :qty, updated_at = NOW()
                    WHERE branch_id = :branch_id AND medicine_id = :med_id AND quantity_in_hand > 0 AND deleted_at IS NULL
                    LIMIT 1
                ");
                $deduct->execute([
                    ':qty' => $transfer['quantity_transferred'],
                    ':branch_id' => $transfer['from_branch_id'],
                    ':med_id' => $transfer['medicine_id']
                ]);

                // Add to destination branch (if no batch exists, create a dummy batch)
                $destCheck = $pdo->prepare("
                    SELECT id FROM pharmacy_inventory 
                    WHERE branch_id = :branch_id AND medicine_id = :med_id AND deleted_at IS NULL
                    LIMIT 1
                ");
                $destCheck->execute([
                    ':branch_id' => $transfer['to_branch_id'],
                    ':med_id' => $transfer['medicine_id']
                ]);
                if ($destCheck->fetch()) {
                    $add = $pdo->prepare("
                        UPDATE pharmacy_inventory 
                        SET quantity_in_hand = quantity_in_hand + :qty, updated_at = NOW()
                        WHERE branch_id = :branch_id AND medicine_id = :med_id AND deleted_at IS NULL
                        LIMIT 1
                    ");
                } else {
                    // Create new batch entry with default values
                    $add = $pdo->prepare("
                        INSERT INTO pharmacy_inventory 
                        (branch_id, medicine_id, batch_number, quantity_in_hand, selling_price, expiry_date, created_by_user_id, created_at)
                        VALUES (:branch_id, :med_id, 'TRANSFER-BATCH', :qty, 0, DATE_ADD(NOW(), INTERVAL 1 YEAR), :user_id, NOW())
                    ");
                }
                $add->execute([
                    ':branch_id' => $transfer['to_branch_id'],
                    ':med_id' => $transfer['medicine_id'],
                    ':qty' => $transfer['quantity_transferred'],
                    ':user_id' => $userId
                ]);

                // Update transfer status
                $update = $pdo->prepare("
                    UPDATE branch_transfers 
                    SET transfer_status = 'completed', updated_at = NOW(), actual_delivery_date = CURDATE()
                    WHERE id = :id
                ");
                $update->execute([':id' => $transferId]);

                $pdo->commit();
                echo json_encode(['status' => 'success', 'message' => 'Transfer approved and stock moved.']);
                exit;
            }
        } catch (PDOException $e) {
            $pdo->rollBack();
            echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
        }
        exit;
    }

    // ---------- 2.5 Load Transfer Tables (Outbound/Inbound) ----------
    if ($action === 'load_transfer_tables') {
        try {
            // Outbound requests (requests sent BY current branch TO others) - where to_branch_id = current
            $outboundSql = "
                SELECT 
                    bt.id,
                    bt.transfer_reference_number,
                    bt.quantity_transferred,
                    bt.transfer_status,
                    bt.created_at,
                    bt.rejection_reason,
                    b_from.name AS source_branch,
                    b_to.name AS destination_branch,
                    mm.medicine_code,
                    mm.generic_name
                FROM branch_transfers bt
                INNER JOIN branches b_from ON bt.from_branch_id = b_from.id
                INNER JOIN branches b_to ON bt.to_branch_id = b_to.id
                INNER JOIN medicines_master mm ON bt.medicine_id = mm.id
                WHERE bt.to_branch_id = :current_branch
                  AND bt.transfer_type = 'pharmacy_stock'
                ORDER BY bt.created_at DESC
            ";
            $outboundStmt = $pdo->prepare($outboundSql);
            $outboundStmt->execute([':current_branch' => $branchId]);
            $outboundRequests = $outboundStmt->fetchAll(PDO::FETCH_ASSOC);

            // Inbound requests (requests FROM other branches TO current branch, pending)
            $inboundSql = "
                SELECT 
                    bt.id,
                    bt.transfer_reference_number,
                    bt.quantity_transferred,
                    bt.transfer_status,
                    bt.created_at,
                    b_from.name AS source_branch,
                    b_to.name AS destination_branch,
                    mm.medicine_code,
                    mm.generic_name
                FROM branch_transfers bt
                INNER JOIN branches b_from ON bt.from_branch_id = b_from.id
                INNER JOIN branches b_to ON bt.to_branch_id = b_to.id
                INNER JOIN medicines_master mm ON bt.medicine_id = mm.id
                WHERE bt.from_branch_id = :current_branch
                  AND bt.transfer_status = 'pending'
                  AND bt.transfer_type = 'pharmacy_stock'
                ORDER BY bt.created_at ASC
            ";
            $inboundStmt = $pdo->prepare($inboundSql);
            $inboundStmt->execute([':current_branch' => $branchId]);
            $inboundRequests = $inboundStmt->fetchAll(PDO::FETCH_ASSOC);

            // Counters
            $pendingOutbound = $pdo->prepare("SELECT COUNT(*) FROM branch_transfers WHERE to_branch_id = :branch AND transfer_status = 'pending' AND transfer_type = 'pharmacy_stock'");
            $pendingOutbound->execute([':branch' => $branchId]);
            $pendingOutboundCount = (int)$pendingOutbound->fetchColumn();

            $pendingInbound = $pdo->prepare("SELECT COUNT(*) FROM branch_transfers WHERE from_branch_id = :branch AND transfer_status = 'pending' AND transfer_type = 'pharmacy_stock'");
            $pendingInbound->execute([':branch' => $branchId]);
            $pendingInboundCount = (int)$pendingInbound->fetchColumn();

            $transferredThisMonth = $pdo->prepare("
                SELECT SUM(quantity_transferred) FROM branch_transfers 
                WHERE transfer_status = 'completed' 
                  AND (from_branch_id = :branch OR to_branch_id = :branch)
                  AND MONTH(created_at) = MONTH(CURDATE())
            ");
            $transferredThisMonth->execute([':branch' => $branchId]);
            $transferredTotal = (int)$transferredThisMonth->fetchColumn();

            echo json_encode([
                'status' => 'success',
                'outbound' => $outboundRequests,
                'inbound' => $inboundRequests,
                'counters' => [
                    'pending_outbound' => $pendingOutboundCount,
                    'pending_inbound' => $pendingInboundCount,
                    'transferred_month' => $transferredTotal
                ]
            ]);
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
        }
        exit;
    }

    echo json_encode(['status' => 'error', 'message' => 'Invalid action.']);
    exit;
}

// ============================================
// 3. INITIAL PAGE LOAD – BRANCH INFO & DROPDOWNS
// ============================================
$branchId = (int)$_SESSION['branch_id'];
$branchName = 'Unknown Branch';
try {
    $branchStmt = $pdo->prepare("SELECT name FROM branches WHERE id = :id AND deleted_at IS NULL");
    $branchStmt->execute([':id' => $branchId]);
    $branchRow = $branchStmt->fetch(PDO::FETCH_ASSOC);
    if ($branchRow) {
        $branchName = htmlspecialchars($branchRow['name']);
    }
} catch (PDOException $e) {
    error_log('Failed to fetch branch name: ' . $e->getMessage());
}

// Fetch all branches except current for requisition dropdown
$otherBranches = [];
try {
    $branchList = $pdo->prepare("SELECT id, name FROM branches WHERE id != :current AND deleted_at IS NULL ORDER BY name");
    $branchList->execute([':current' => $branchId]);
    $otherBranches = $branchList->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('Failed to fetch branches: ' . $e->getMessage());
}

$currentDateDisplay = date('l, F j, Y');
$currentTimeDisplay = date('g:i A');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inter-Branch Stock Transfers | Pharmacist | HMS ERP</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
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
        body {
            font-family: 'Inter', sans-serif;
            background: #f5f7fb;
            margin: 0;
            padding: 0;
            overflow-x: hidden;
        }
        .content-page {
            margin-left: 260px;
            margin-top: 70px;
            padding: 24px 32px;
            transition: margin-left 0.3s;
            min-height: calc(100vh - 70px);
            background: #f5f7fb;
        }
        body.erp-sidebar-collapsed .content-page {
            margin-left: 80px;
        }
        @media (max-width: 768px) {
            .content-page {
                margin-left: 0 !important;
                padding: 16px;
            }
        }
        .welcome-header {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--primary) 100%);
            border-radius: 24px;
            padding: 24px 32px;
            color: white;
            margin-bottom: 28px;
        }
        .stat-card {
            background: white;
            border-radius: 24px;
            padding: 20px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
            transition: transform 0.2s;
            height: 100%;
        }
        .stat-card:hover {
            transform: translateY(-3px);
        }
        .stat-number {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0;
        }
        .nav-tabs-custom .nav-link {
            border-radius: 30px;
            padding: 8px 24px;
            font-weight: 500;
        }
        .nav-tabs-custom .nav-link.active {
            background: var(--primary);
            color: white;
        }
        .data-table th {
            font-size: 13px;
            font-weight: 600;
            background: #f8fafc;
            border-bottom: 1px solid var(--gray-light);
        }
        .data-table td {
            vertical-align: middle;
            padding: 12px 16px;
        }
        .badge-status {
            padding: 4px 12px;
            border-radius: 30px;
            font-size: 12px;
            font-weight: 600;
        }
        .badge-pending { background: #fff3e0; color: #e65100; }
        .badge-completed { background: #e8f5e9; color: #2e7d32; }
        .badge-rejected { background: #ffebee; color: #c62828; }
        .search-panel {
            background: white;
            border-radius: 24px;
            padding: 20px;
            margin-bottom: 24px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
        }
        .btn-transfer {
            background: var(--info);
            color: white;
            border: none;
            border-radius: 30px;
            padding: 4px 12px;
            font-size: 12px;
        }
        .btn-approve {
            background: var(--success);
            color: white;
            border: none;
            border-radius: 30px;
            padding: 4px 12px;
            font-size: 12px;
        }
        .btn-reject {
            background: var(--danger);
            color: white;
            border: none;
            border-radius: 30px;
            padding: 4px 12px;
            font-size: 12px;
        }
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(255,255,255,0.7);
            z-index: 2000;
            display: none;
            align-items: center;
            justify-content: center;
        }
        .loading-overlay.active { display: flex; }
        .spinner {
            width: 40px;
            height: 40px;
            border: 3px solid var(--gray-light);
            border-top-color: var(--primary);
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
        .refresh-btn {
            background: white;
            border: 1px solid var(--gray-light);
            border-radius: 40px;
            padding: 6px 16px;
            font-size: 13px;
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
                        <div class="welcome-title"><i class="fas fa-exchange-alt me-2"></i>Inter-Branch Stock Requisition & Transfer Matrix</div>
                        <div class="welcome-subtitle">Cross‑branch stock lookup | Requisition workflow | Approval matrix</div>
                    </div>
                    <div class="col-md-4 text-md-end mt-3 mt-md-0">
                        <div class="time-badge" style="background: rgba(255,255,255,0.15); padding: 8px 16px; border-radius: 12px;">
                            <i class="far fa-calendar-alt"></i> <?php echo $currentDateDisplay; ?>
                            <i class="far fa-clock ms-2"></i> <?php echo $currentTimeDisplay; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Live Telemetry Analytics Dashboard -->
            <div class="row g-4 mb-4">
                <div class="col-md-3 col-sm-6">
                    <div class="stat-card text-center">
                        <i class="fas fa-paper-plane fa-2x text-primary mb-2"></i>
                        <h3 class="stat-number" id="pendingOutboundCount">0</h3>
                        <p class="text-muted mb-0">Pending Outbound Requests</p>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="stat-card text-center">
                        <i class="fas fa-inbox fa-2x text-warning mb-2"></i>
                        <h3 class="stat-number" id="pendingInboundCount">0</h3>
                        <p class="text-muted mb-0">Inbound Actionable Requests</p>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="stat-card text-center">
                        <i class="fas fa-truck fa-2x text-success mb-2"></i>
                        <h3 class="stat-number" id="transferredMonthCount">0</h3>
                        <p class="text-muted mb-0">Transferred This Month</p>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="stat-card text-center">
                        <i class="fas fa-store fa-2x text-info mb-2"></i>
                        <h3 class="stat-number"><?php echo $branchName; ?></h3>
                        <p class="text-muted mb-0">Connected Branch Node</p>
                    </div>
                </div>
            </div>

            <!-- Cross-Branch Sentinel Search Panel -->
            <div class="search-panel">
                <h5 class="mb-3"><i class="fas fa-globe me-2 text-primary"></i>Cross-Branch Stock Lookup</h5>
                <div class="row g-3 align-items-end">
                    <div class="col-md-8">
                        <label class="form-label fw-semibold">Medicine Code / SKU</label>
                        <input type="text" id="externalSearchCode" class="form-control" placeholder="Enter medicine code (e.g., PARA-500)">
                    </div>
                    <div class="col-md-4">
                        <button class="btn btn-primary w-100" id="searchExternalBtn"><i class="fas fa-search me-1"></i> Find in Other Branches</button>
                    </div>
                </div>
            </div>

            <!-- Current Branch Stock Table -->
            <div class="card shadow-sm border-0 rounded-4 mb-4">
                <div class="card-header bg-white border-0 py-3 d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="fas fa-warehouse me-2 text-primary"></i>Current Branch Stock (<?php echo $branchName; ?>)</h5>
                    <div>
                        <input type="text" id="stockSearch" class="form-control form-control-sm d-inline-block me-2" style="width:200px;" placeholder="Filter stock...">
                        <button class="btn refresh-btn" id="manualRefreshBtn"><i class="fas fa-sync-alt me-1"></i> Refresh All</button>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover data-table mb-0" style="width:100%">
                            <thead>
                                <tr>
                                    <th>Medicine Code</th>
                                    <th>Generic Name / Brand</th>
                                    <th>Available Quantity</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody id="stockTbody">
                                <tr><td colspan="4" class="text-center py-5">Loading stock data...</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Transfer Request Tabs -->
            <ul class="nav nav-tabs nav-tabs-custom mb-3" id="transferTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="outbound-tab" data-bs-toggle="tab" data-bs-target="#outbound" type="button" role="tab">Outbound Requests (Sent by Us)</button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="inbound-tab" data-bs-toggle="tab" data-bs-target="#inbound" type="button" role="tab">Inbound Requests (Awaiting Approval)</button>
                </li>
            </ul>
            <div class="tab-content">
                <div class="tab-pane fade show active" id="outbound" role="tabpanel">
                    <div class="card shadow-sm border-0 rounded-4">
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover data-table mb-0">
                                    <thead>
                                        <tr>
                                            <th>Reference</th>
                                            <th>Medicine</th>
                                            <th>Quantity</th>
                                            <th>Source Branch</th>
                                            <th>Destination</th>
                                            <th>Status</th>
                                            <th>Requested On</th>
                                        </tr>
                                    </thead>
                                    <tbody id="outboundTbody">
                                        <tr><td colspan="7" class="text-center py-4">Loading...</td></tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="tab-pane fade" id="inbound" role="tabpanel">
                    <div class="card shadow-sm border-0 rounded-4">
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover data-table mb-0">
                                    <thead>
                                        <tr>
                                            <th>Reference</th>
                                            <th>Medicine</th>
                                            <th>Requested Qty</th>
                                            <th>Requesting Branch</th>
                                            <th>Requested On</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody id="inboundTbody">
                                        <tr><td colspan="6" class="text-center py-4">Loading...</td></tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php include('assets/inc/footer.php'); ?>
</div>

<!-- External Stock Modal -->
<div class="modal fade" id="externalStockModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Stock Availability in Other Branches</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="externalStockBody">
                Loading...
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Requisition Modal -->
<div class="modal fade" id="requisitionModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Initiate Inter-Branch Requisition</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="requisitionForm">
                <div class="modal-body">
                    <input type="hidden" name="medicine_id" id="req_medicine_id">
                    <input type="hidden" name="item_code" id="req_item_code">
                    <input type="hidden" name="medicine_name" id="req_medicine_name">
                    <div class="mb-3">
                        <label class="form-label">Source Branch (Supplier)</label>
                        <select class="form-select" name="source_branch_id" id="req_source_branch" required>
                            <option value="">Select branch</option>
                            <?php foreach ($otherBranches as $branch): ?>
                                <option value="<?php echo $branch['id']; ?>"><?php echo htmlspecialchars($branch['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Medicine</label>
                        <input type="text" class="form-control" id="req_medicine_display" readonly>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Quantity to Request</label>
                        <input type="number" class="form-control" name="quantity" id="req_quantity" required min="1">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Send Requisition</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Loading Overlay -->
<div id="loadingOverlay" class="loading-overlay"><div class="spinner"></div></div>

<script>
const csrfToken = "<?php echo $csrf_token; ?>";

function showLoading(show) {
    if (show) $('#loadingOverlay').addClass('active');
    else $('#loadingOverlay').removeClass('active');
}

// Load current branch stock grid
function loadStockGrid() {
    const search = $('#stockSearch').val().trim();
    showLoading(true);
    $.ajax({
        url: window.location.pathname + '?action=load_stock_grid',
        method: 'GET',
        dataType: 'json',
        data: { search: search },
        cache: false,
        success: function(res) {
            showLoading(false);
            if (res.status === 'success') {
                let tbody = '';
                if (res.stock.length === 0) {
                    tbody = '<tr><td colspan="4" class="text-center py-4">No stock records found.</td></tr>';
                } else {
                    res.stock.forEach(function(item) {
                        tbody += `
                            <tr>
                                <td><strong>${escapeHtml(item.medicine_code)}</strong></td>
                                <td>${escapeHtml(item.generic_name)}${item.brand_name ? ' (' + escapeHtml(item.brand_name) + ')' : ''}</td>
                                <td>${item.total_quantity}</td>
                                <td><button class="btn-transfer" data-medicine-id="${item.medicine_id}" data-medicine-code="${escapeHtml(item.medicine_code)}" data-medicine-name="${escapeHtml(item.generic_name)}"><i class="fas fa-truck me-1"></i>Request from Other Branch</button></td>
                            </tr>
                        `;
                    });
                }
                $('#stockTbody').html(tbody);
                // Bind request buttons
                $('.btn-transfer').off('click').on('click', function() {
                    const medId = $(this).data('medicine-id');
                    const medCode = $(this).data('medicine-code');
                    const medName = $(this).data('medicine-name');
                    $('#req_medicine_id').val(medId);
                    $('#req_item_code').val(medCode);
                    $('#req_medicine_name').val(medName);
                    $('#req_medicine_display').val(`${medCode} - ${medName}`);
                    $('#requisitionModal').modal('show');
                });
            } else {
                $('#stockTbody').html('<tr><td colspan="4" class="text-center text-danger">Error loading stock.</td></tr>');
            }
        },
        error: function() { showLoading(false); $('#stockTbody').html('<tr><td colspan="4" class="text-center text-danger">Network error.</td></tr>'); }
    });
}

// Search external stock
$('#searchExternalBtn').on('click', function() {
    const itemCode = $('#externalSearchCode').val().trim();
    if (!itemCode) {
        Swal.fire('Error', 'Please enter a medicine code.', 'error');
        return;
    }
    showLoading(true);
    $.ajax({
        url: window.location.pathname + '?action=search_external_stock',
        method: 'GET',
        dataType: 'json',
        data: { item_code: itemCode },
        success: function(res) {
            showLoading(false);
            if (res.status === 'success') {
                let html = `<p><strong>Medicine Code:</strong> ${escapeHtml(res.item_code)}</p>`;
                if (res.external_stock.length === 0) {
                    html += '<div class="alert alert-warning">No stock available in other branches.</div>';
                } else {
                    html += '<table class="table table-sm"><thead><tr><th>Branch</th><th>Available Quantity</th></tr></thead><tbody>';
                    res.external_stock.forEach(function(branch) {
                        html += `<tr><td>${escapeHtml(branch.branch_name)}</td><td>${branch.available_quantity}</td></tr>`;
                    });
                    html += '</tbody></table>';
                }
                $('#externalStockBody').html(html);
                $('#externalStockModal').modal('show');
            } else {
                Swal.fire('Error', res.message, 'error');
            }
        },
        error: function() { showLoading(false); Swal.fire('Error', 'Network error.', 'error'); }
    });
});

// Submit requisition
$('#requisitionForm').on('submit', function(e) {
    e.preventDefault();
    const formData = $(this).serializeArray();
    formData.push({ name: 'csrf_token', value: csrfToken });
    showLoading(true);
    $.ajax({
        url: window.location.pathname + '?action=submit_requisition',
        method: 'POST',
        dataType: 'json',
        data: $.param(formData),
        success: function(res) {
            showLoading(false);
            if (res.status === 'success') {
                Swal.fire('Success', res.message, 'success').then(() => {
                    $('#requisitionModal').modal('hide');
                    loadTransferTables();
                });
            } else {
                Swal.fire('Error', res.message, 'error');
            }
        },
        error: function() { showLoading(false); Swal.fire('Error', 'Network error.', 'error'); }
    });
});

// Load transfer tables (outbound & inbound)
function loadTransferTables() {
    $.ajax({
        url: window.location.pathname + '?action=load_transfer_tables',
        method: 'GET',
        dataType: 'json',
        cache: false,
        success: function(res) {
            if (res.status === 'success') {
                // Update counters
                $('#pendingOutboundCount').text(res.counters.pending_outbound);
                $('#pendingInboundCount').text(res.counters.pending_inbound);
                $('#transferredMonthCount').text(res.counters.transferred_month);

                // Outbound table
                let outboundHtml = '';
                if (res.outbound.length === 0) {
                    outboundHtml = '<tr><td colspan="7" class="text-center py-4">No outbound requests found.</td></tr>';
                } else {
                    res.outbound.forEach(function(req) {
                        let statusBadge = '';
                        if (req.transfer_status === 'pending') statusBadge = 'badge-pending';
                        else if (req.transfer_status === 'completed') statusBadge = 'badge-completed';
                        else if (req.transfer_status === 'rejected') statusBadge = 'badge-rejected';
                        outboundHtml += `
                            <tr>
                                <td>${escapeHtml(req.transfer_reference_number)}</td>
                                <td>${escapeHtml(req.medicine_code)} - ${escapeHtml(req.generic_name)}</td>
                                <td>${req.quantity_transferred}</td>
                                <td>${escapeHtml(req.source_branch)}</td>
                                <td>${escapeHtml(req.destination_branch)}</td>
                                <td><span class="badge-status ${statusBadge}">${req.transfer_status.toUpperCase()}</span></td>
                                <td>${new Date(req.created_at).toLocaleString()}</td>
                            </tr>
                        `;
                    });
                }
                $('#outboundTbody').html(outboundHtml);

                // Inbound table (only pending)
                let inboundHtml = '';
                if (res.inbound.length === 0) {
                    inboundHtml = '<tr><td colspan="6" class="text-center py-4">No pending inbound requests.</td></tr>';
                } else {
                    res.inbound.forEach(function(req) {
                        inboundHtml += `
                            <tr data-transfer-id="${req.id}">
                                <td>${escapeHtml(req.transfer_reference_number)}</td>
                                <td>${escapeHtml(req.medicine_code)} - ${escapeHtml(req.generic_name)}</td>
                                <td>${req.quantity_transferred}</td>
                                <td>${escapeHtml(req.source_branch)}</td>
                                <td>${new Date(req.created_at).toLocaleString()}</td>
                                <td>
                                    <button class="btn-approve me-1" data-transfer-id="${req.id}" data-action="approve"><i class="fas fa-check-circle"></i> Approve</button>
                                    <button class="btn-reject" data-transfer-id="${req.id}" data-action="reject"><i class="fas fa-times-circle"></i> Reject</button>
                                </td>
                            </tr>
                        `;
                    });
                }
                $('#inboundTbody').html(inboundHtml);

                // Bind approval buttons
                $('.btn-approve, .btn-reject').off('click').on('click', function() {
                    const transferId = $(this).data('transfer-id');
                    const action = $(this).data('action');
                    if (action === 'approve') {
                        processTransfer(transferId, 'approve');
                    } else {
                        processTransfer(transferId, 'reject');
                    }
                });
            } else {
                console.error('Failed to load transfers');
            }
        },
        error: function() { console.error('Error loading transfers'); }
    });
}

function processTransfer(transferId, action) {
    if (action === 'reject') {
        Swal.fire({
            title: 'Rejection Reason',
            input: 'textarea',
            inputLabel: 'Reason for rejection',
            showCancelButton: true,
            confirmButtonText: 'Confirm Rejection'
        }).then((result) => {
            if (result.isConfirmed) {
                const reason = result.value || 'No reason provided';
                sendApprovalRequest(transferId, action, reason);
            }
        });
    } else {
        Swal.fire({
            title: 'Confirm Transfer',
            text: 'Approve this transfer and move stock?',
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#06d6a0',
            confirmButtonText: 'Yes, Approve'
        }).then((result) => {
            if (result.isConfirmed) {
                sendApprovalRequest(transferId, action, '');
            }
        });
    }
}

function sendApprovalRequest(transferId, action, reason) {
    showLoading(true);
    $.ajax({
        url: window.location.pathname + '?action=process_transfer_approval',
        method: 'POST',
        dataType: 'json',
        data: {
            transfer_id: transferId,
            action_type: action,
            rejection_reason: reason,
            csrf_token: csrfToken
        },
        success: function(res) {
            showLoading(false);
            if (res.status === 'success') {
                Swal.fire('Success', res.message, 'success');
                loadTransferTables();
                loadStockGrid(); // refresh stock as quantities may have changed
            } else {
                Swal.fire('Error', res.message, 'error');
            }
        },
        error: function() { showLoading(false); Swal.fire('Error', 'Network error.', 'error'); }
    });
}

// Manual refresh (no auto-refresh)
$(document).ready(function() {
    loadStockGrid();
    loadTransferTables();
    $('#manualRefreshBtn').on('click', function() {
        loadStockGrid();
        loadTransferTables();
        Swal.fire('Refreshed', 'All data updated.', 'success');
    });
    $('#stockSearch').on('keyup', function() { loadStockGrid(); });
});

function escapeHtml(str) {
    if (!str) return '';
    return String(str).replace(/[&<>]/g, function(m) {
        if (m === '&') return '&amp;';
        if (m === '<') return '&lt;';
        if (m === '>') return '&gt;';
        return m;
    });
}
</script>
</body>
</html>