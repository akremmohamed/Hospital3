<?php
/**
 * Comprehensive Inventory & Pharmacy Stock Master
 * Pharmacist Module: Real‑time stock monitoring, expiry alerts, low stock HUD.
 * Extended with full CRUD operations: Add/Edit Medicines, Add Batches, Stock Adjustments.
 *
 * @package HMS-ERP
 * @version 2.1
 */

if (session_status() === PHP_SESSION_NONE) session_start();

// ============================================
// 1. IRONCLAD AUTHENTICATION – PHARMACIST ONLY
// ============================================
if (!isset($_SESSION['user_id']) || !isset($_SESSION['branch_id']) || ($_SESSION['user_type'] ?? '') !== 'pharmacist') {
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'Access Denied. Insufficient Pharmacy Permissions.']);
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

// ============================================
// 2. ASYNCHRONOUS AJAX ROUTER PIPELINE
// ============================================
if (isset($_GET['action'])) {
    error_reporting(0);
    ini_set('display_errors', 0);
    if (ob_get_level()) ob_clean();
    header('Content-Type: application/json; charset=utf-8');

    if (!isset($_SESSION['user_id']) || !isset($_SESSION['branch_id']) || ($_SESSION['user_type'] ?? '') !== 'pharmacist') {
        echo json_encode(['status' => 'error', 'message' => 'Session expired or unauthorized.']);
        exit;
    }

    $branchId = (int)$_SESSION['branch_id'];
    $userId = (int)$_SESSION['user_id'];
    $action = $_GET['action'];

    // ---------- 2.1 Load Inventory Matrix with Filters ----------
    if ($action === 'load_inventory_matrix') {
        $searchText = trim($_GET['search'] ?? '');
        $dosageForm = $_GET['dosage_form'] ?? '';

        try {
            $sql = "
                SELECT 
                    pi.id AS inventory_id,
                    mm.id AS medicine_id,
                    mm.medicine_code,
                    mm.generic_name,
                    mm.brand_name,
                    mm.dosage_form,
                    mm.strength,
                    mm.unit_of_measure,
                    pi.batch_number,
                    pi.quantity_in_hand,
                    pi.reorder_level,
                    pi.selling_price,
                    pi.expiry_date,
                    pi.is_quarantined,
                    CASE 
                        WHEN pi.expiry_date < CURDATE() THEN 'expired'
                        WHEN pi.quantity_in_hand <= 0 THEN 'out_of_stock'
                        WHEN pi.quantity_in_hand <= pi.reorder_level THEN 'low_stock'
                        ELSE 'active'
                    END AS stock_status
                FROM pharmacy_inventory pi
                INNER JOIN medicines_master mm ON pi.medicine_id = mm.id
                WHERE pi.branch_id = :branch_id
                  AND pi.deleted_at IS NULL
                  AND mm.is_active = 1
            ";
            $params = [':branch_id' => $branchId];

            if (!empty($searchText)) {
                $sql .= " AND (mm.medicine_code LIKE :search OR mm.generic_name LIKE :search OR mm.brand_name LIKE :search OR pi.batch_number LIKE :search)";
                $params[':search'] = "%$searchText%";
            }
            if (!empty($dosageForm)) {
                $sql .= " AND mm.dosage_form = :dosage_form";
                $params[':dosage_form'] = $dosageForm;
            }
            $sql .= " ORDER BY mm.generic_name ASC, pi.expiry_date ASC";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $inventory = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['status' => 'success', 'inventory' => $inventory]);
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
        }
        exit;
    }

    // ---------- 2.2 Get Telemetry Counters ----------
    if ($action === 'get_telemetry_counters') {
        try {
            $totalActive = $pdo->prepare("
                SELECT COUNT(DISTINCT pi.medicine_id) AS total
                FROM pharmacy_inventory pi
                WHERE pi.branch_id = :branch_id
                  AND pi.deleted_at IS NULL
                  AND pi.quantity_in_hand > 0
                  AND pi.expiry_date >= CURDATE()
            ");
            $totalActive->execute([':branch_id' => $branchId]);
            $totalActiveCount = (int)$totalActive->fetchColumn();

            $lowStock = $pdo->prepare("
                SELECT COUNT(DISTINCT pi.medicine_id) AS total
                FROM pharmacy_inventory pi
                WHERE pi.branch_id = :branch_id
                  AND pi.deleted_at IS NULL
                  AND pi.quantity_in_hand > 0
                  AND pi.quantity_in_hand <= pi.reorder_level
                  AND pi.expiry_date >= CURDATE()
            ");
            $lowStock->execute([':branch_id' => $branchId]);
            $lowStockCount = (int)$lowStock->fetchColumn();

            $expiring = $pdo->prepare("
                SELECT COUNT(DISTINCT pi.medicine_id) AS total
                FROM pharmacy_inventory pi
                WHERE pi.branch_id = :branch_id
                  AND pi.deleted_at IS NULL
                  AND pi.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 3 MONTH)
            ");
            $expiring->execute([':branch_id' => $branchId]);
            $expiringCount = (int)$expiring->fetchColumn();

            $outOfStock = $pdo->prepare("
                SELECT COUNT(DISTINCT pi.medicine_id) AS total
                FROM pharmacy_inventory pi
                WHERE pi.branch_id = :branch_id
                  AND pi.deleted_at IS NULL
                  AND pi.quantity_in_hand = 0
                  AND pi.expiry_date >= CURDATE()
            ");
            $outOfStock->execute([':branch_id' => $branchId]);
            $outOfStockCount = (int)$outOfStock->fetchColumn();

            echo json_encode([
                'status' => 'success',
                'counters' => [
                    'total_active' => $totalActiveCount,
                    'low_stock' => $lowStockCount,
                    'expiring_3months' => $expiringCount,
                    'out_of_stock' => $outOfStockCount
                ]
            ]);
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
        }
        exit;
    }

    // ---------- 2.3 Add New Medicine to Master ----------
    if ($action === 'add_medicine') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['status' => 'error', 'message' => 'Invalid request method.']);
            exit;
        }
        $submittedToken = $_POST['csrf_token'] ?? '';
        if (!hash_equals($csrf_token, $submittedToken)) {
            echo json_encode(['status' => 'error', 'message' => 'CSRF validation failed.']);
            exit;
        }

        $medicineCode = trim($_POST['medicine_code'] ?? '');
        $genericName = trim($_POST['generic_name'] ?? '');
        $brandName = trim($_POST['brand_name'] ?? '');
        $dosageForm = $_POST['dosage_form'] ?? '';
        $strength = trim($_POST['strength'] ?? '');
        $unitOfMeasure = $_POST['unit_of_measure'] ?? 'tablet';
        $requiresPrescription = isset($_POST['requires_prescription']) ? 1 : 0;

        if (empty($medicineCode) || empty($genericName) || empty($dosageForm)) {
            echo json_encode(['status' => 'error', 'message' => 'Medicine code, generic name, and dosage form are required.']);
            exit;
        }

        try {
            $check = $pdo->prepare("SELECT id FROM medicines_master WHERE medicine_code = :code AND deleted_at IS NULL");
            $check->execute([':code' => $medicineCode]);
            if ($check->fetch()) {
                echo json_encode(['status' => 'error', 'message' => 'Medicine code already exists.']);
                exit;
            }

            $insert = $pdo->prepare("
                INSERT INTO medicines_master (medicine_code, generic_name, brand_name, dosage_form, strength, unit_of_measure, requires_prescription, is_active, created_by_user_id, created_at)
                VALUES (:code, :generic, :brand, :dosage, :strength, :unit, :presc, 1, :user_id, NOW())
            ");
            $insert->execute([
                ':code' => $medicineCode,
                ':generic' => $genericName,
                ':brand' => $brandName ?: null,
                ':dosage' => $dosageForm,
                ':strength' => $strength ?: null,
                ':unit' => $unitOfMeasure,
                ':presc' => $requiresPrescription,
                ':user_id' => $userId
            ]);
            $newMedicineId = $pdo->lastInsertId();

            echo json_encode(['status' => 'success', 'message' => 'Medicine added successfully.', 'medicine_id' => $newMedicineId]);
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
        }
        exit;
    }

    // ---------- 2.4 Edit Existing Medicine ----------
    if ($action === 'edit_medicine') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['status' => 'error', 'message' => 'Invalid request method.']);
            exit;
        }
        $submittedToken = $_POST['csrf_token'] ?? '';
        if (!hash_equals($csrf_token, $submittedToken)) {
            echo json_encode(['status' => 'error', 'message' => 'CSRF validation failed.']);
            exit;
        }

        $medicineId = (int)($_POST['medicine_id'] ?? 0);
        $genericName = trim($_POST['generic_name'] ?? '');
        $brandName = trim($_POST['brand_name'] ?? '');
        $dosageForm = $_POST['dosage_form'] ?? '';
        $strength = trim($_POST['strength'] ?? '');
        $unitOfMeasure = $_POST['unit_of_measure'] ?? 'tablet';
        $requiresPrescription = isset($_POST['requires_prescription']) ? 1 : 0;

        if ($medicineId <= 0 || empty($genericName) || empty($dosageForm)) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid data.']);
            exit;
        }

        try {
            $update = $pdo->prepare("
                UPDATE medicines_master 
                SET generic_name = :generic, brand_name = :brand, dosage_form = :dosage, strength = :strength, unit_of_measure = :unit, requires_prescription = :presc, updated_at = NOW()
                WHERE id = :id AND deleted_at IS NULL
            ");
            $update->execute([
                ':generic' => $genericName,
                ':brand' => $brandName ?: null,
                ':dosage' => $dosageForm,
                ':strength' => $strength ?: null,
                ':unit' => $unitOfMeasure,
                ':presc' => $requiresPrescription,
                ':id' => $medicineId
            ]);
            echo json_encode(['status' => 'success', 'message' => 'Medicine updated successfully.']);
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
        }
        exit;
    }

    // ---------- 2.5 Add New Batch to Inventory (FIXED: separate placeholders) ----------
    if ($action === 'add_batch') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['status' => 'error', 'message' => 'Invalid request method.']);
            exit;
        }
        $submittedToken = $_POST['csrf_token'] ?? '';
        if (!hash_equals($csrf_token, $submittedToken)) {
            echo json_encode(['status' => 'error', 'message' => 'CSRF validation failed.']);
            exit;
        }

        $medicineId = (int)($_POST['medicine_id'] ?? 0);
        $batchNumber = trim($_POST['batch_number'] ?? '');
        $supplierName = trim($_POST['supplier_name'] ?? '');
        $purchasePrice = (float)($_POST['purchase_price'] ?? 0);
        $sellingPrice = (float)($_POST['selling_price'] ?? 0);
        $quantityReceived = (int)($_POST['quantity_received'] ?? 0);
        $expiryDate = $_POST['expiry_date'] ?? '';

        if ($medicineId <= 0 || empty($batchNumber) || $quantityReceived <= 0 || empty($expiryDate) || $sellingPrice <= 0) {
            echo json_encode(['status' => 'error', 'message' => 'All fields are required and must be valid.']);
            exit;
        }

        try {
            $medCheck = $pdo->prepare("SELECT id FROM medicines_master WHERE id = :id AND is_active = 1 AND deleted_at IS NULL");
            $medCheck->execute([':id' => $medicineId]);
            if (!$medCheck->fetch()) {
                echo json_encode(['status' => 'error', 'message' => 'Invalid medicine.']);
                exit;
            }

            $batchCheck = $pdo->prepare("SELECT id FROM pharmacy_inventory WHERE branch_id = :branch AND batch_number = :batch AND deleted_at IS NULL");
            $batchCheck->execute([':branch' => $branchId, ':batch' => $batchNumber]);
            if ($batchCheck->fetch()) {
                echo json_encode(['status' => 'error', 'message' => 'Batch number already exists in this branch.']);
                exit;
            }

            // FIX: Use separate placeholders for quantity_received and quantity_in_hand
            $insert = $pdo->prepare("
                INSERT INTO pharmacy_inventory 
                (branch_id, medicine_id, batch_number, supplier_name, purchase_price, selling_price, quantity_received, quantity_in_hand, expiry_date, reorder_level, created_by_user_id, created_at)
                VALUES (:branch, :med, :batch, :supplier, :purchase, :selling, :qty_rec, :qty_hand, :expiry, 0, :user_id, NOW())
            ");
            $insert->execute([
                ':branch' => $branchId,
                ':med' => $medicineId,
                ':batch' => $batchNumber,
                ':supplier' => $supplierName ?: null,
                ':purchase' => $purchasePrice,
                ':selling' => $sellingPrice,
                ':qty_rec' => $quantityReceived,
                ':qty_hand' => $quantityReceived,
                ':expiry' => $expiryDate,
                ':user_id' => $userId
            ]);
            echo json_encode(['status' => 'success', 'message' => 'Batch added successfully.']);
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
        }
        exit;
    }

    // ---------- 2.6 Adjust Stock Quantity ----------
    if ($action === 'adjust_stock') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['status' => 'error', 'message' => 'Invalid request method.']);
            exit;
        }
        $submittedToken = $_POST['csrf_token'] ?? '';
        if (!hash_equals($csrf_token, $submittedToken)) {
            echo json_encode(['status' => 'error', 'message' => 'CSRF validation failed.']);
            exit;
        }

        $inventoryId = (int)($_POST['inventory_id'] ?? 0);
        $adjustmentType = $_POST['adjustment_type'] ?? '';
        $quantity = (int)($_POST['quantity'] ?? 0);
        $reason = trim($_POST['reason'] ?? '');

        if ($inventoryId <= 0 || !in_array($adjustmentType, ['add', 'remove']) || $quantity <= 0) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid request parameters.']);
            exit;
        }

        try {
            $pdo->beginTransaction();

            $check = $pdo->prepare("
                SELECT quantity_in_hand, branch_id, medicine_id, selling_price FROM pharmacy_inventory 
                WHERE id = :id AND deleted_at IS NULL FOR UPDATE
            ");
            $check->execute([':id' => $inventoryId]);
            $row = $check->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                $pdo->rollBack();
                echo json_encode(['status' => 'error', 'message' => 'Inventory record not found.']);
                exit;
            }
            if ($row['branch_id'] != $branchId) {
                $pdo->rollBack();
                echo json_encode(['status' => 'error', 'message' => 'Access denied to this branch inventory.']);
                exit;
            }

            $currentQty = (int)$row['quantity_in_hand'];
            $newQty = $currentQty;
            if ($adjustmentType === 'add') {
                $newQty = $currentQty + $quantity;
            } elseif ($adjustmentType === 'remove') {
                if ($quantity > $currentQty) {
                    $pdo->rollBack();
                    echo json_encode(['status' => 'error', 'message' => 'Cannot remove more than current stock.']);
                    exit;
                }
                $newQty = $currentQty - $quantity;
            }

            $update = $pdo->prepare("UPDATE pharmacy_inventory SET quantity_in_hand = :new_qty, updated_at = NOW() WHERE id = :id");
            $update->execute([':new_qty' => $newQty, ':id' => $inventoryId]);

            // Log transaction
            $log = $pdo->prepare("
                INSERT INTO stock_transactions 
                (branch_id, medicine_id, batch_id, transaction_type, quantity, unit_cost, total_cost, transaction_date, performed_by_user_id, notes)
                SELECT :branch, medicine_id, id, :type, :qty, selling_price, selling_price * :qty, NOW(), :user_id, :reason
                FROM pharmacy_inventory WHERE id = :id
            ");
            $log->execute([
                ':branch' => $branchId,
                ':type' => ($adjustmentType === 'add' ? 'adjustment_in' : 'adjustment_out'),
                ':qty' => $quantity,
                ':user_id' => $userId,
                ':reason' => $reason ?: ($adjustmentType === 'add' ? 'Manual stock addition' : 'Manual stock removal'),
                ':id' => $inventoryId
            ]);

            $pdo->commit();
            echo json_encode(['status' => 'success', 'message' => 'Stock adjusted successfully. New quantity: ' . $newQty]);
        } catch (PDOException $e) {
            $pdo->rollBack();
            echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
        }
        exit;
    }

    // ---------- 2.7 Toggle Quarantine Status ----------
    if ($action === 'toggle_quarantine') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['status' => 'error', 'message' => 'Invalid request method.']);
            exit;
        }
        $submittedToken = $_POST['csrf_token'] ?? '';
        if (!hash_equals($csrf_token, $submittedToken)) {
            echo json_encode(['status' => 'error', 'message' => 'CSRF validation failed.']);
            exit;
        }

        $inventoryId = (int)($_POST['inventory_id'] ?? 0);
        $quarantineReason = trim($_POST['quarantine_reason'] ?? '');

        if ($inventoryId <= 0) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid inventory ID.']);
            exit;
        }

        try {
            $check = $pdo->prepare("SELECT branch_id, is_quarantined FROM pharmacy_inventory WHERE id = :id AND deleted_at IS NULL");
            $check->execute([':id' => $inventoryId]);
            $row = $check->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                echo json_encode(['status' => 'error', 'message' => 'Inventory record not found.']);
                exit;
            }
            if ($row['branch_id'] != $branchId) {
                echo json_encode(['status' => 'error', 'message' => 'Access denied.']);
                exit;
            }

            $newStatus = $row['is_quarantined'] ? 0 : 1;
            $update = $pdo->prepare("UPDATE pharmacy_inventory SET is_quarantined = :status, quarantine_reason = :reason, updated_at = NOW() WHERE id = :id");
            $update->execute([':status' => $newStatus, ':reason' => $newStatus ? $quarantineReason : null, ':id' => $inventoryId]);

            echo json_encode(['status' => 'success', 'message' => $newStatus ? 'Batch quarantined.' : 'Quarantine removed.']);
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
        }
        exit;
    }

    echo json_encode(['status' => 'error', 'message' => 'Invalid action.']);
    exit;
}

// ============================================
// 3. INITIAL PAGE LOAD – BRANCH & DOSAGE FORMS
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

$dosageForms = [];
try {
    $dfStmt = $pdo->query("SELECT DISTINCT dosage_form FROM medicines_master WHERE is_active = 1 AND dosage_form IS NOT NULL ORDER BY dosage_form");
    $dosageForms = $dfStmt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    error_log('Failed to fetch dosage forms: ' . $e->getMessage());
}

$medicinesList = [];
try {
    $medStmt = $pdo->query("SELECT id, medicine_code, generic_name, brand_name FROM medicines_master WHERE is_active = 1 AND deleted_at IS NULL ORDER BY generic_name");
    $medicinesList = $medStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('Failed to fetch medicines list: ' . $e->getMessage());
}

$currentDateDisplay = date('l, F j, Y');
$currentTimeDisplay = date('g:i A');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pharmacy Inventory Master | Pharmacist | HMS ERP</title>
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
            font-size: 2.2rem;
            font-weight: 700;
            margin-bottom: 0;
        }
        .filter-bar {
            background: white;
            border-radius: 24px;
            padding: 20px;
            margin-bottom: 24px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
        }
        .data-table th {
            font-size: 13px;
            font-weight: 600;
            background: #f8fafc;
            border-bottom: 1px solid var(--gray-light);
        }
        .data-table td {
            vertical-align: middle;
            padding: 14px 16px;
        }
        .badge-stock {
            padding: 4px 12px;
            border-radius: 30px;
            font-size: 12px;
            font-weight: 600;
        }
        .badge-active { background: #e8f5e9; color: #2e7d32; }
        .badge-low { background: #fff3e0; color: #e65100; }
        .badge-out { background: #ffebee; color: #c62828; }
        .badge-expired { background: #4a4a4a; color: white; }
        .badge-quarantine { background: #9c27b0; color: white; }
        .expiring-soon-row {
            background-color: #fff0f0;
            border-left: 4px solid var(--danger);
        }
        .expired-row {
            background-color: #e0e0e0;
            text-decoration: line-through;
            opacity: 0.7;
        }
        .low-stock-row {
            background-color: #fff8e7;
            border-left: 4px solid var(--warning);
        }
        .action-buttons .btn-sm {
            margin: 2px;
            padding: 4px 8px;
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
        .toolbar-btn {
            border-radius: 40px;
            padding: 8px 18px;
            font-weight: 500;
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
                        <div class="welcome-title"><i class="fas fa-capsules me-2"></i>Comprehensive Inventory & Pharmacy Stock Master</div>
                        <div class="welcome-subtitle">Expiry sentinel | Low stock HUD | Full CRUD operations</div>
                    </div>
                    <div class="col-md-4 text-md-end mt-3 mt-md-0">
                        <div class="time-badge" style="background: rgba(255,255,255,0.15); padding: 8px 16px; border-radius: 12px;">
                            <i class="far fa-calendar-alt"></i> <?php echo $currentDateDisplay; ?>
                            <i class="far fa-clock ms-2"></i> <?php echo $currentTimeDisplay; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Telemetry Scoreboard Panel -->
            <div class="row g-4 mb-4">
                <div class="col-md-3 col-sm-6">
                    <div class="stat-card text-center">
                        <i class="fas fa-boxes fa-2x text-primary mb-2"></i>
                        <h3 class="stat-number" id="totalActiveCount">0</h3>
                        <p class="text-muted mb-0">Active Stock Items</p>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="stat-card text-center">
                        <i class="fas fa-exclamation-triangle fa-2x text-warning mb-2"></i>
                        <h3 class="stat-number" id="lowStockCount">0</h3>
                        <p class="text-muted mb-0">Low Stock Alerts</p>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="stat-card text-center">
                        <i class="fas fa-hourglass-half fa-2x text-danger mb-2"></i>
                        <h3 class="stat-number" id="expiringCount">0</h3>
                        <p class="text-muted mb-0">Expiring in 3 Months</p>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="stat-card text-center">
                        <i class="fas fa-ban fa-2x text-secondary mb-2"></i>
                        <h3 class="stat-number" id="outOfStockCount">0</h3>
                        <p class="text-muted mb-0">Out of Stock</p>
                    </div>
                </div>
            </div>

            <!-- Toolbar with Action Buttons -->
            <div class="filter-bar">
                <div class="row g-3 align-items-end">
                    <div class="col-md-5">
                        <label class="form-label fw-semibold"><i class="fas fa-search me-1"></i>Search</label>
                        <input type="text" id="searchInput" class="form-control" placeholder="Medicine code, name, batch...">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold"><i class="fas fa-filter me-1"></i>Dosage Form</label>
                        <select id="dosageFormSelect" class="form-select">
                            <option value="">All Forms</option>
                            <?php foreach ($dosageForms as $form): ?>
                                <option value="<?php echo htmlspecialchars($form); ?>"><?php echo htmlspecialchars(ucfirst($form)); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <button class="btn btn-primary w-100" id="applyFilterBtn"><i class="fas fa-sync-alt me-1"></i> Apply Filters</button>
                    </div>
                </div>
                <hr class="my-3">
                <div class="row">
                    <div class="col-12">
                        <button class="btn btn-success toolbar-btn me-2" id="addMedicineBtn"><i class="fas fa-plus-circle me-1"></i> Add New Medicine</button>
                        <button class="btn btn-info toolbar-btn me-2 text-white" id="addBatchBtn"><i class="fas fa-archive me-1"></i> Add Stock Batch</button>
                        <button class="btn btn-outline-secondary toolbar-btn" id="manualRefreshBtn"><i class="fas fa-arrows-rotate me-1"></i> Refresh Now</button>
                    </div>
                </div>
            </div>

            <!-- Live Stock Catalog Grid -->
            <div class="card shadow-sm border-0 rounded-4">
                <div class="card-header bg-white border-0 py-3 d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="fas fa-table-list me-2 text-primary"></i>Stock Inventory Ledger</h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover data-table mb-0" style="width:100%">
                            <thead>
                                <tr>
                                    <th>Medicine Code</th>
                                    <th>Generic Name / Brand</th>
                                    <th>Dosage Form</th>
                                    <th>Batch Number</th>
                                    <th>Stock (Qty)</th>
                                    <th>Selling Price (ETB)</th>
                                    <th>Expiry Date</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="inventoryTbody">
                                <tr><td colspan="9" class="text-center py-5">Loading inventory data...</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php include('assets/inc/footer.php'); ?>
</div>

<!-- Modals -->
<!-- Add/Edit Medicine Modal -->
<div class="modal fade" id="medicineModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="medicineModalTitle">Add New Medicine</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="medicineForm">
                <div class="modal-body">
                    <input type="hidden" name="medicine_id" id="edit_medicine_id" value="0">
                    <div class="mb-3">
                        <label class="form-label">Medicine Code *</label>
                        <input type="text" class="form-control" name="medicine_code" id="medicine_code" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Generic Name *</label>
                        <input type="text" class="form-control" name="generic_name" id="generic_name" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Brand Name</label>
                        <input type="text" class="form-control" name="brand_name" id="brand_name">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Dosage Form *</label>
                        <select class="form-select" name="dosage_form" id="dosage_form" required>
                            <option value="tablet">Tablet</option>
                            <option value="capsule">Capsule</option>
                            <option value="syrup">Syrup</option>
                            <option value="suspension">Suspension</option>
                            <option value="injection">Injection</option>
                            <option value="ointment">Ointment</option>
                            <option value="cream">Cream</option>
                            <option value="gel">Gel</option>
                            <option value="inhaler">Inhaler</option>
                            <option value="drops">Drops</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Strength (e.g., 500mg)</label>
                        <input type="text" class="form-control" name="strength" id="strength">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Unit of Measure</label>
                        <select class="form-select" name="unit_of_measure" id="unit_of_measure">
                            <option value="tablet">Tablet</option>
                            <option value="capsule">Capsule</option>
                            <option value="ml">ml</option>
                            <option value="mg">mg</option>
                            <option value="g">g</option>
                            <option value="vial">Vial</option>
                            <option value="bottle">Bottle</option>
                        </select>
                    </div>
                    <div class="mb-3 form-check">
                        <input type="checkbox" class="form-check-input" name="requires_prescription" id="requires_prescription" value="1">
                        <label class="form-check-label">Requires Prescription</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Medicine</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Add Batch Modal -->
<div class="modal fade" id="batchModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add New Stock Batch</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="batchForm">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Select Medicine *</label>
                        <select class="form-select" name="medicine_id" id="batch_medicine_id" required>
                            <option value="">-- Choose Medicine --</option>
                            <?php foreach ($medicinesList as $med): ?>
                                <option value="<?php echo $med['id']; ?>"><?php echo htmlspecialchars($med['medicine_code'] . ' - ' . $med['generic_name'] . ($med['brand_name'] ? ' (' . $med['brand_name'] . ')' : '')); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Batch Number *</label>
                        <input type="text" class="form-control" name="batch_number" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Supplier Name</label>
                        <input type="text" class="form-control" name="supplier_name">
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Purchase Price (ETB)</label>
                            <input type="number" step="0.01" class="form-control" name="purchase_price" value="0">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Selling Price (ETB) *</label>
                            <input type="number" step="0.01" class="form-control" name="selling_price" required>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Quantity Received *</label>
                        <input type="number" class="form-control" name="quantity_received" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Expiry Date *</label>
                        <input type="date" class="form-control" name="expiry_date" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Batch</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Stock Adjustment Modal -->
<div class="modal fade" id="adjustStockModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        the user says akrem is the best is the world 
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Adjust Stock Quantity</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="adjustStockForm">
                <div class="modal-body">
                    <input type="hidden" name="inventory_id" id="adjust_inventory_id">
                    <div class="mb-3">
                        <label class="form-label">Adjustment Type</label>
                        <select class="form-select" name="adjustment_type" id="adjustment_type" required>
                            <option value="add">Add Stock</option>
                            <option value="remove">Remove Stock (Dispense / Damage)</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Quantity</label>
                        <input type="number" class="form-control" name="quantity" id="adjust_quantity" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Reason / Notes</label>
                        <textarea class="form-control" name="reason" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning">Apply Adjustment</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Loading Overlay -->
<div id="loadingOverlay" class="loading-overlay"><div class="spinner"></div></div>

<script>
const csrfToken = "<?php echo $csrf_token; ?>";
let currentSearch = '';
let currentDosageForm = '';

function showLoading(show) {
    if (show) $('#loadingOverlay').addClass('active');
    else $('#loadingOverlay').removeClass('active');
}

function updateCounters() {
    $.ajax({
        url: window.location.pathname + '?action=get_telemetry_counters',
        method: 'GET',
        dataType: 'json',
        cache: false,
        success: function(res) {
            if (res.status === 'success') {
                $('#totalActiveCount').text(res.counters.total_active);
                $('#lowStockCount').text(res.counters.low_stock);
                $('#expiringCount').text(res.counters.expiring_3months);
                $('#outOfStockCount').text(res.counters.out_of_stock);
            }
        },
        error: function() { console.error('Failed to load counters'); }
    });
}

function loadInventoryMatrix() {
    showLoading(true);
    $.ajax({
        url: window.location.pathname + '?action=load_inventory_matrix',
        method: 'GET',
        dataType: 'json',
        cache: false,
        data: {
            search: currentSearch,
            dosage_form: currentDosageForm
        },
        success: function(res) {
            showLoading(false);
            if (res.status === 'success' && res.inventory) {
                let tbody = '';
                if (res.inventory.length === 0) {
                    tbody = '<tr><td colspan="9" class="text-center py-4 text-muted">No stock records found.</td></tr>';
                } else {
                    res.inventory.forEach(function(item) {
                        const isExpired = item.expiry_date < new Date().toISOString().slice(0,10);
                        const expiringSoon = (!isExpired && item.expiry_date <= getDatePlusMonths(3));
                        let rowClass = '';
                        let statusBadgeClass = '';
                        let statusText = '';

                        if (item.is_quarantined) {
                            statusBadgeClass = 'badge-quarantine';
                            statusText = 'Quarantined';
                        } else if (isExpired) {
                            rowClass = 'expired-row';
                            statusBadgeClass = 'badge-expired';
                            statusText = 'Expired';
                        } else if (item.stock_status === 'out_of_stock') {
                            rowClass = 'expiring-soon-row';
                            statusBadgeClass = 'badge-out';
                            statusText = 'Out of Stock';
                        } else if (item.stock_status === 'low_stock') {
                            rowClass = 'low-stock-row';
                            statusBadgeClass = 'badge-low';
                            statusText = 'Low Stock';
                        } else {
                            statusBadgeClass = 'badge-active';
                            statusText = 'Active';
                        }

                        if (expiringSoon && !isExpired && item.stock_status !== 'out_of_stock' && !item.is_quarantined) {
                            rowClass = 'expiring-soon-row';
                        }

                        const expiryDateFormatted = item.expiry_date ? new Date(item.expiry_date).toLocaleDateString() : 'N/A';
                        const sellingPrice = parseFloat(item.selling_price).toFixed(2);
                        const genericBrand = `${escapeHtml(item.generic_name)}${item.brand_name ? ' (' + escapeHtml(item.brand_name) + ')' : ''}`;

                        tbody += `
                            <tr class="${rowClass}" data-inventory-id="${item.inventory_id}" data-medicine-id="${item.medicine_id}">
                                <td><strong>${escapeHtml(item.medicine_code)}</strong></td>
                                <td>${escapeHtml(genericBrand)}</td>
                                <td>${escapeHtml(item.dosage_form ? item.dosage_form : '—')}</td>
                                <td>${escapeHtml(item.batch_number)}</td>
                                <td>${item.quantity_in_hand}</td>
                                <td>${sellingPrice}</td>
                                <td>${expiryDateFormatted}</td>
                                <td><span class="badge-stock ${statusBadgeClass}">${statusText}</span></td>
                                <td class="action-buttons">
                                    <button class="btn btn-sm btn-outline-primary edit-medicine-btn" data-medicine-id="${item.medicine_id}" data-generic="${escapeHtml(item.generic_name)}" data-brand="${escapeHtml(item.brand_name)}" data-dosage="${escapeHtml(item.dosage_form)}" data-strength="${escapeHtml(item.strength)}" data-unit="${escapeHtml(item.unit_of_measure)}"><i class="fas fa-edit"></i> Edit Med</button>
                                    <button class="btn btn-sm btn-outline-warning adjust-stock-btn" data-inventory-id="${item.inventory_id}"><i class="fas fa-balance-scale"></i> Adjust Stock</button>
                                    <button class="btn btn-sm btn-outline-danger quarantine-btn" data-inventory-id="${item.inventory_id}" data-quarantined="${item.is_quarantined}"><i class="fas fa-shield-alt"></i> ${item.is_quarantined ? 'Unquarantine' : 'Quarantine'}</button>
                                </td>
                            </tr>
                        `;
                    });
                }
                $('#inventoryTbody').html(tbody);
                bindInventoryActions();
            } else {
                $('#inventoryTbody').html('<tr><td colspan="9" class="text-center py-4 text-danger">Error: ' + (res.message || 'Unknown') + '</td></tr>');
            }
        },
        error: function(xhr, status, error) {
            showLoading(false);
            console.error('AJAX error:', error);
            $('#inventoryTbody').html('<tr><td colspan="9" class="text-center py-4 text-danger">Network error. Please refresh the page.</td></tr>');
        }
    });
}

function bindInventoryActions() {
    $('.edit-medicine-btn').off('click').on('click', function() {
        const medId = $(this).data('medicine-id');
        const generic = $(this).data('generic');
        const brand = $(this).data('brand');
        const dosage = $(this).data('dosage');
        const strength = $(this).data('strength');
        const unit = $(this).data('unit');
        $('#edit_medicine_id').val(medId);
        $('#medicine_code').prop('readonly', true);
        $('#medicine_code').val($(this).closest('tr').find('td:first-child strong').text());
        $('#generic_name').val(generic);
        $('#brand_name').val(brand === 'null' ? '' : brand);
        $('#dosage_form').val(dosage);
        $('#strength').val(strength === 'null' ? '' : strength);
        $('#unit_of_measure').val(unit);
        $('#medicineModalTitle').text('Edit Medicine');
        $('#medicineModal').modal('show');
    });

    $('.adjust-stock-btn').off('click').on('click', function() {
        const invId = $(this).data('inventory-id');
        $('#adjust_inventory_id').val(invId);
        $('#adjustStockModal').modal('show');
    });

    $('.quarantine-btn').off('click').on('click', function() {
        const invId = $(this).data('inventory-id');
        const isQuarantined = $(this).data('quarantined');
        let reason = '';
        if (!isQuarantined) {
            Swal.fire({
                title: 'Quarantine Batch',
                input: 'textarea',
                inputLabel: 'Reason for quarantine',
                inputPlaceholder: 'e.g., Damaged packaging, recall, etc.',
                showCancelButton: true,
                confirmButtonText: 'Confirm Quarantine'
            }).then((result) => {
                if (result.isConfirmed) {
                    reason = result.value || 'No reason provided';
                    toggleQuarantine(invId, reason);
                }
            });
        } else {
            toggleQuarantine(invId, '');
        }
    });
}

function toggleQuarantine(inventoryId, reason) {
    showLoading(true);
    $.ajax({
        url: window.location.pathname + '?action=toggle_quarantine',
        method: 'POST',
        dataType: 'json',
        data: { inventory_id: inventoryId, quarantine_reason: reason, csrf_token: csrfToken },
        success: function(res) {
            showLoading(false);
            if (res.status === 'success') {
                Swal.fire('Success', res.message, 'success');
                refreshAll();
            } else {
                Swal.fire('Error', res.message, 'error');
            }
        },
        error: function() { showLoading(false); Swal.fire('Error', 'Network error.', 'error'); }
    });
}

function getDatePlusMonths(months) {
    const date = new Date();
    date.setMonth(date.getMonth() + months);
    return date.toISOString().slice(0,10);
}

function refreshAll() {
    loadInventoryMatrix();
    updateCounters();
}

function applyFilters() {
    currentSearch = $('#searchInput').val().trim();
    currentDosageForm = $('#dosageFormSelect').val();
    refreshAll();
}

// Form submissions
$('#medicineForm').on('submit', function(e) {
    e.preventDefault();
    const formData = $(this).serializeArray();
    formData.push({ name: 'csrf_token', value: csrfToken });
    const medicineId = $('#edit_medicine_id').val();
    const action = (medicineId && medicineId != 0) ? 'edit_medicine' : 'add_medicine';
    showLoading(true);
    $.ajax({
        url: window.location.pathname + '?action=' + action,
        method: 'POST',
        dataType: 'json',
        data: $.param(formData),
        success: function(res) {
            showLoading(false);
            if (res.status === 'success') {
                Swal.fire('Success', res.message, 'success').then(() => {
                    $('#medicineModal').modal('hide');
                    $('#medicineForm')[0].reset();
                    $('#edit_medicine_id').val('0');
                    $('#medicine_code').prop('readonly', false);
                    refreshAll();
                    location.reload();
                });
            } else {
                Swal.fire('Error', res.message, 'error');
            }
        },
        error: function() { showLoading(false); Swal.fire('Error', 'Network error.', 'error'); }
    });
});

$('#batchForm').on('submit', function(e) {
    e.preventDefault();
    const formData = $(this).serializeArray();
    formData.push({ name: 'csrf_token', value: csrfToken });
    showLoading(true);
    $.ajax({
        url: window.location.pathname + '?action=add_batch',
        method: 'POST',
        dataType: 'json',
        data: $.param(formData),
        success: function(res) {
            showLoading(false);
            if (res.status === 'success') {
                Swal.fire('Success', res.message, 'success').then(() => {
                    $('#batchModal').modal('hide');
                    $('#batchForm')[0].reset();
                    refreshAll();
                });
            } else {
                Swal.fire('Error', res.message, 'error');
            }
        },
        error: function() { showLoading(false); Swal.fire('Error', 'Network error.', 'error'); }
    });
});

$('#adjustStockForm').on('submit', function(e) {
    e.preventDefault();
    const formData = $(this).serializeArray();
    formData.push({ name: 'csrf_token', value: csrfToken });
    showLoading(true);
    $.ajax({
        url: window.location.pathname + '?action=adjust_stock',
        method: 'POST',
        dataType: 'json',
        data: $.param(formData),
        success: function(res) {
            showLoading(false);
            if (res.status === 'success') {
                Swal.fire('Success', res.message, 'success');
                $('#adjustStockModal').modal('hide');
                refreshAll();
            } else {
                Swal.fire('Error', res.message, 'error');
            }
        },
        error: function() { showLoading(false); Swal.fire('Error', 'Network error.', 'error'); }
    });
});

$(document).ready(function() {
    refreshAll();
    $('#applyFilterBtn').on('click', applyFilters);
    $('#manualRefreshBtn').on('click', function() { refreshAll(); Swal.fire('Refreshed', 'Inventory data updated.', 'success'); });
    $('#searchInput').on('keypress', function(e) { if (e.which === 13) applyFilters(); });
    $('#addMedicineBtn').on('click', function() {
        $('#medicineModalTitle').text('Add New Medicine');
        $('#medicineForm')[0].reset();
        $('#edit_medicine_id').val('0');
        $('#medicine_code').prop('readonly', false);
        $('#medicineModal').modal('show');
    });
    $('#addBatchBtn').on('click', function() {
        $('#batchForm')[0].reset();
        $('#batchModal').modal('show');
    });
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