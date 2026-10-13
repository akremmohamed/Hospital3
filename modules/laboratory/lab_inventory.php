<?php
/**
 * lab_inventory.php
 * 
 * HMS ERP - Laboratory Inventory Management
 * 
 * Features:
 * - View all lab reagents/consumables with stock levels
 * - Add new inventory items
 * - Update stock quantities
 * - View low stock alerts
 * - Track expiring items
 * - Batch management
 * - Stock transaction history
 * - Export inventory reports
 * - Reorder management
 * 
 * @package HMS_ERP
 * @version 1.0
 */

// ============================================
// 1. SECURITY & SESSION VALIDATION
// ============================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../config/db_connection.php';
require_once __DIR__ . '/../../includes/auth_helper.php';

date_default_timezone_set('Africa/Addis_Ababa');
$pdo->exec("SET time_zone = '+03:00'");

// Validate user session
$user_id = (int)($_SESSION['user_id'] ?? 0);
$branch_id = (int)($_SESSION['branch_id'] ?? 0);
$user_type = $_SESSION['user_type'] ?? '';
$is_global_admin = ($user_type === 'super_admin');
$is_lab_staff = in_array($user_type, ['laboratorist', 'pharmacist', 'super_admin', 'admin']);

if ($user_id <= 0 || !$is_lab_staff) {
    header('Location: login.php');
    exit;
}

// ============================================
// 2. ACTION HANDLING
// ============================================
$action = $_GET['action'] ?? $_POST['action'] ?? '';
$inventory_id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$success_message = '';
$error_message = '';

// Handle adding new inventory item
if ($action === 'add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $medicine_id = (int)($_POST['medicine_id'] ?? 0);
    $batch_number = trim($_POST['batch_number'] ?? '');
    $supplier_name = trim($_POST['supplier_name'] ?? '');
    $purchase_invoice_number = trim($_POST['purchase_invoice_number'] ?? '');
    $purchase_price = (float)($_POST['purchase_price'] ?? 0);
    $selling_price = (float)($_POST['selling_price'] ?? 0);
    $manufacturing_date = $_POST['manufacturing_date'] ?? null;
    $expiry_date = $_POST['expiry_date'] ?? null;
    $quantity_received = (int)($_POST['quantity_received'] ?? 0);
    $reorder_level = (int)($_POST['reorder_level'] ?? 0);
    $reorder_quantity = (int)($_POST['reorder_quantity'] ?? 0);
    $notes = trim($_POST['notes'] ?? '');

    if ($medicine_id <= 0 || empty($batch_number) || empty($expiry_date) || $quantity_received <= 0) {
        $error_message = 'Please fill in all required fields.';
    } else {
        try {
            $pdo->beginTransaction();

            // Check for duplicate batch
            $sql = "SELECT id FROM pharmacy_inventory 
                    WHERE medicine_id = :medicine_id AND batch_number = :batch_number AND deleted_at IS NULL";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':medicine_id' => $medicine_id, ':batch_number' => $batch_number]);
            if ($stmt->fetch()) {
                throw new Exception('A batch with this number already exists for this medicine.');
            }

            // Insert inventory item
            $sql = "INSERT INTO pharmacy_inventory (
                        branch_id, medicine_id, batch_number, supplier_name,
                        purchase_invoice_number, purchase_price, selling_price,
                        manufacturing_date, expiry_date, quantity_received,
                        quantity_in_hand, reorder_level, reorder_quantity,
                        notes, created_by_user_id, created_at
                    ) VALUES (
                        :branch_id, :medicine_id, :batch_number, :supplier_name,
                        :purchase_invoice_number, :purchase_price, :selling_price,
                        :manufacturing_date, :expiry_date, :quantity_received,
                        :quantity_in_hand, :reorder_level, :reorder_quantity,
                        :notes, :created_by, NOW()
                    )";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':branch_id' => $branch_id,
                ':medicine_id' => $medicine_id,
                ':batch_number' => $batch_number,
                ':supplier_name' => $supplier_name,
                ':purchase_invoice_number' => $purchase_invoice_number,
                ':purchase_price' => $purchase_price,
                ':selling_price' => $selling_price,
                ':manufacturing_date' => $manufacturing_date,
                ':expiry_date' => $expiry_date,
                ':quantity_received' => $quantity_received,
                ':quantity_in_hand' => $quantity_received,
                ':reorder_level' => $reorder_level,
                ':reorder_quantity' => $reorder_quantity,
                ':notes' => $notes,
                ':created_by' => $user_id
            ]);
            $inventory_id = $pdo->lastInsertId();

            // Log stock transaction
            $sql = "INSERT INTO stock_transactions (
                        branch_id, medicine_id, batch_id, transaction_type,
                        quantity, unit_cost, total_cost, reference_type,
                        reference_id, transaction_date, performed_by_user_id,
                        notes, created_at
                    ) VALUES (
                        :branch_id, :medicine_id, :batch_id, 'purchase',
                        :quantity, :unit_cost, :total_cost, 'purchase_inventory',
                        :reference_id, NOW(), :performed_by, :notes, NOW()
                    )";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':branch_id' => $branch_id,
                ':medicine_id' => $medicine_id,
                ':batch_id' => $inventory_id,
                ':quantity' => $quantity_received,
                ':unit_cost' => $purchase_price,
                ':total_cost' => $purchase_price * $quantity_received,
                ':reference_id' => $inventory_id,
                ':performed_by' => $user_id,
                ':notes' => $notes
            ]);

            $pdo->commit();
            $success_message = "Inventory item added successfully!";

            // Log audit
            log_audit($pdo, $branch_id, $user_id, 'INSERT', 'pharmacy_inventory', $inventory_id, [
                'medicine_id' => $medicine_id,
                'batch_number' => $batch_number,
                'quantity' => $quantity_received
            ]);

        } catch (Exception $e) {
            $pdo->rollBack();
            $error_message = 'Error: ' . $e->getMessage();
            error_log('Inventory Add Error: ' . $e->getMessage());
        }
    }
}

// Handle updating inventory quantity
if ($action === 'update_quantity' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $inventory_id = (int)($_POST['inventory_id'] ?? 0);
    $adjustment_type = $_POST['adjustment_type'] ?? 'adjustment_in';
    $quantity = (int)($_POST['quantity'] ?? 0);
    $reason = trim($_POST['reason'] ?? '');

    if ($inventory_id <= 0 || $quantity <= 0) {
        $error_message = 'Invalid input.';
    } else {
        try {
            $pdo->beginTransaction();

            // Get current inventory
            $sql = "SELECT pi.*, mm.generic_name, mm.brand_name 
                    FROM pharmacy_inventory pi
                    INNER JOIN medicines_master mm ON pi.medicine_id = mm.id
                    WHERE pi.id = :id AND pi.deleted_at IS NULL";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':id' => $inventory_id]);
            $inventory = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$inventory) {
                throw new Exception('Inventory item not found.');
            }

            // Calculate new quantity
            $new_quantity = $inventory['quantity_in_hand'];
            if ($adjustment_type === 'adjustment_in') {
                $new_quantity += $quantity;
            } elseif ($adjustment_type === 'adjustment_out') {
                if ($quantity > $inventory['quantity_in_hand']) {
                    throw new Exception('Cannot deduct more than available stock.');
                }
                $new_quantity -= $quantity;
            } else {
                throw new Exception('Invalid adjustment type.');
            }

            // Update inventory
            $sql = "UPDATE pharmacy_inventory 
                    SET quantity_in_hand = :quantity,
                        updated_at = NOW()
                    WHERE id = :id";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':quantity' => $new_quantity,
                ':id' => $inventory_id
            ]);

            // Log transaction
            $sql = "INSERT INTO stock_transactions (
                        branch_id, medicine_id, batch_id, transaction_type,
                        quantity, unit_cost, total_cost, reference_type,
                        reference_id, transaction_date, performed_by_user_id,
                        notes, created_at
                    ) VALUES (
                        :branch_id, :medicine_id, :batch_id, :transaction_type,
                        :quantity, :unit_cost, 0, 'stock_adjustment',
                        :reference_id, NOW(), :performed_by, :notes, NOW()
                    )";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':branch_id' => $branch_id,
                ':medicine_id' => $inventory['medicine_id'],
                ':batch_id' => $inventory_id,
                ':transaction_type' => $adjustment_type,
                ':quantity' => $quantity,
                ':unit_cost' => $inventory['purchase_price'],
                ':reference_id' => $inventory_id,
                ':performed_by' => $user_id,
                ':notes' => $reason
            ]);

            $pdo->commit();
            $success_message = "Stock updated successfully!";

            // Log audit
            log_audit($pdo, $branch_id, $user_id, 'UPDATE', 'pharmacy_inventory', $inventory_id, [
                'adjustment_type' => $adjustment_type,
                'quantity' => $quantity,
                'new_quantity' => $new_quantity
            ]);

        } catch (Exception $e) {
            $pdo->rollBack();
            $error_message = 'Error: ' . $e->getMessage();
        }
    }
}

// Handle deleting inventory item (soft delete)
if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $inventory_id = (int)($_POST['inventory_id'] ?? 0);

    if ($inventory_id <= 0) {
        $error_message = 'Invalid inventory ID.';
    } else {
        try {
            $sql = "UPDATE pharmacy_inventory 
                    SET deleted_at = NOW(), updated_at = NOW()
                    WHERE id = :id AND deleted_at IS NULL";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':id' => $inventory_id]);

            $success_message = "Inventory item deleted successfully.";

            // Log audit
            log_audit($pdo, $branch_id, $user_id, 'DELETE', 'pharmacy_inventory', $inventory_id, []);

        } catch (Exception $e) {
            $error_message = 'Error: ' . $e->getMessage();
        }
    }
}

// ============================================
// 3. HELPER FUNCTIONS
// ============================================
function log_audit($pdo, $branch_id, $user_id, $action, $table, $record_id, $details) {
    try {
        $sql = "INSERT INTO audit_logs (
                    branch_id, user_id, action_type, target_table, record_id,
                    new_values, ip_address, user_agent, created_at
                ) VALUES (
                    :branch_id, :user_id, :action_type, :target_table, :record_id,
                    :new_values, :ip_address, :user_agent, NOW()
                )";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':branch_id' => $branch_id,
            ':user_id' => $user_id,
            ':action_type' => $action,
            ':target_table' => $table,
            ':record_id' => $record_id,
            ':new_values' => json_encode($details),
            ':ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            ':user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
        ]);
    } catch (Exception $e) {
        error_log('Audit log error: ' . $e->getMessage());
    }
}

// ============================================
// 4. DATA FETCHING
// ============================================

// Build filter parameters
$filter_status = $_GET['status'] ?? 'all';
$filter_category = $_GET['category'] ?? '';
$search = trim($_GET['search'] ?? '');

// Branch filter
$branch_filter = "";
$params = [];
if (!$is_global_admin && $branch_id > 0) {
    $branch_filter = " AND pi.branch_id = :branch_id";
    $params[':branch_id'] = $branch_id;
}

// Additional filters
if ($filter_status === 'low_stock') {
    $branch_filter .= " AND pi.quantity_in_hand <= pi.reorder_level AND pi.quantity_in_hand > 0";
} elseif ($filter_status === 'out_of_stock') {
    $branch_filter .= " AND pi.quantity_in_hand = 0";
} elseif ($filter_status === 'expiring') {
    $branch_filter .= " AND pi.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) AND pi.expiry_date > CURDATE()";
} elseif ($filter_status === 'expired') {
    $branch_filter .= " AND pi.expiry_date < CURDATE()";
}
if (!empty($filter_category)) {
    $branch_filter .= " AND mm.dosage_form = :category";
    $params[':category'] = $filter_category;
}
if (!empty($search)) {
    $branch_filter .= " AND (mm.generic_name LIKE :search OR mm.brand_name LIKE :search OR mm.medicine_code LIKE :search OR pi.batch_number LIKE :search)";
    $params[':search'] = "%{$search}%";
}

// Fetch inventory items - FIXED: removed pi.generic_name from ORDER BY
$sql = "SELECT 
            pi.id, pi.branch_id, pi.medicine_id, pi.batch_number,
            pi.supplier_name, pi.purchase_invoice_number,
            pi.purchase_price, pi.selling_price,
            pi.manufacturing_date, pi.expiry_date,
            pi.quantity_received, pi.quantity_in_hand,
            pi.reorder_level, pi.reorder_quantity,
            pi.last_restocked_date, pi.is_quarantined,
            pi.quarantine_reason, pi.notes,
            pi.created_at, pi.updated_at,
            mm.medicine_code, mm.generic_name, mm.brand_name,
            mm.dosage_form, mm.strength, mm.unit_of_measure,
            mm.pack_size, mm.manufacturer,
            CONCAT(u.first_name, ' ', u.last_name) as created_by_name
        FROM pharmacy_inventory pi
        INNER JOIN medicines_master mm ON pi.medicine_id = mm.id
        LEFT JOIN users u ON pi.created_by_user_id = u.id
        WHERE pi.deleted_at IS NULL" . $branch_filter . "
        ORDER BY 
            CASE 
                WHEN pi.quantity_in_hand <= pi.reorder_level AND pi.quantity_in_hand > 0 THEN 1
                WHEN pi.quantity_in_hand = 0 THEN 2
                WHEN pi.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN 3
                ELSE 4
            END,
            mm.generic_name ASC,
            pi.expiry_date ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$inventory_items = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get medicines for dropdown
$sql = "SELECT id, medicine_code, generic_name, brand_name, dosage_form, unit_of_measure 
        FROM medicines_master 
        WHERE is_active = 1 AND deleted_at IS NULL 
        ORDER BY generic_name ASC";
$medicines = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

// Get statistics
$stats = [
    'total_items' => 0,
    'low_stock' => 0,
    'out_of_stock' => 0,
    'expiring_soon' => 0,
    'expired' => 0,
    'total_value' => 0
];

foreach ($inventory_items as $item) {
    $stats['total_items']++;
    $stats['total_value'] += $item['quantity_in_hand'] * $item['purchase_price'];
    if ($item['quantity_in_hand'] == 0) {
        $stats['out_of_stock']++;
    } elseif ($item['quantity_in_hand'] <= $item['reorder_level']) {
        $stats['low_stock']++;
    }
    if ($item['expiry_date'] < date('Y-m-d')) {
        $stats['expired']++;
    } elseif ($item['expiry_date'] <= date('Y-m-d', strtotime('+30 days'))) {
        $stats['expiring_soon']++;
    }
}

// Get unique dosage forms for filter
$sql = "SELECT DISTINCT dosage_form FROM medicines_master WHERE is_active = 1 AND deleted_at IS NULL ORDER BY dosage_form";
$dosage_forms = $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN);

// Get recent stock transactions
$sql = "SELECT 
            st.*,
            mm.generic_name,
            CONCAT(u.first_name, ' ', u.last_name) as performed_by_name
        FROM stock_transactions st
        INNER JOIN medicines_master mm ON st.medicine_id = mm.id
        LEFT JOIN users u ON st.performed_by_user_id = u.id
        WHERE st.branch_id = :branch_id
        AND st.deleted_at IS NULL
        ORDER BY st.created_at DESC
        LIMIT 20";
$stmt = $pdo->prepare($sql);
$stmt->execute([':branch_id' => $branch_id]);
$recent_transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lab Inventory | HMS ERP</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;
            background: #f0f2f5;
            color: #1f2937;
            overflow-x: hidden;
        }
        
        #wrapper { display: flex; min-height: 100vh; }
        #page-wrapper {
            flex: 1;
            margin-left: 260px;
            padding: 20px 24px 30px;
            transition: margin-left 0.3s ease;
            min-height: 100vh;
            padding-top: 90px;
        }
        body.erp-sidebar-collapsed #page-wrapper { margin-left: 80px; }
        
        @media (max-width: 768px) {
            #page-wrapper {
                margin-left: 0 !important;
                padding: 12px 12px 20px;
                padding-top: 80px;
            }
            body.sidebar-enable #page-wrapper { margin-left: 280px !important; }
        }
        
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 12px;
        }
        .page-header h1 {
            font-size: 24px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .page-header h1 i { color: #06d6a0; }
        
        .btn {
            padding: 8px 16px;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            background: white;
            cursor: pointer;
            font-size: 13px;
            font-weight: 500;
            transition: all 0.2s;
            text-decoration: none;
            color: #1f2937;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .btn:hover { background: #f3f4f6; border-color: #06d6a0; }
        .btn-primary { background: #06d6a0; color: white; border-color: #06d6a0; }
        .btn-primary:hover { background: #05b88a; border-color: #05b88a; color: white; }
        .btn-success { background: #06d6a0; color: white; border-color: #06d6a0; }
        .btn-success:hover { background: #05b88a; border-color: #05b88a; }
        .btn-danger { background: #ef476f; color: white; border-color: #ef476f; }
        .btn-danger:hover { background: #d63e5e; border-color: #d63e5e; }
        .btn-warning { background: #f9c74f; color: #1f2937; border-color: #f9c74f; }
        .btn-warning:hover { background: #f0b832; border-color: #f0b832; }
        .btn-sm { padding: 4px 10px; font-size: 12px; }
        .btn-outline { background: transparent; border-color: #e5e7eb; }
        
        .alert-success {
            background: #d1fae5;
            border: 1px solid #a7f3d0;
            color: #065f46;
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 16px;
        }
        .alert-danger {
            background: #fee2e2;
            border: 1px solid #fecaca;
            color: #991b1b;
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 16px;
        }
        .alert-warning {
            background: #fef3c7;
            border: 1px solid #fde68a;
            color: #92400e;
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 16px;
        }
        
        /* Stats Bar */
        .stats-bar {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 20px;
        }
        .stat-pill {
            background: white;
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 13px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08);
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .stat-pill .count {
            font-weight: 700;
            font-size: 16px;
        }
        .stat-pill .label { color: #6b7280; }
        .stat-pill.total { border-left: 3px solid #4361ee; }
        .stat-pill.low-stock { border-left: 3px solid #f9c74f; }
        .stat-pill.out-of-stock { border-left: 3px solid #ef476f; }
        .stat-pill.expiring { border-left: 3px solid #f9c74f; }
        .stat-pill.expired { border-left: 3px solid #ef476f; }
        .stat-pill.value { border-left: 3px solid #06d6a0; }
        
        /* Filter Bar */
        .filter-bar {
            background: white;
            padding: 16px 20px;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08);
            margin-bottom: 20px;
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            align-items: center;
        }
        .filter-bar select, .filter-bar input {
            padding: 6px 12px;
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            font-size: 13px;
            background: white;
            min-width: 130px;
        }
        .filter-bar .filter-label {
            font-size: 12px;
            color: #6b7280;
            font-weight: 500;
        }
        
        /* Cards */
        .card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08);
            overflow: hidden;
            margin-bottom: 20px;
        }
        .card-header {
            padding: 14px 20px;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .card-header h3 {
            font-size: 15px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .card-body { padding: 16px 20px; }
        
        .badge {
            padding: 3px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            display: inline-block;
        }
        .badge-success { background: #d1fae5; color: #065f46; }
        .badge-warning { background: #fef3c7; color: #92400e; }
        .badge-danger { background: #fee2e2; color: #991b1b; }
        .badge-info { background: #dbeafe; color: #1e40af; }
        
        .badge-stock-normal { background: #d1fae5; color: #065f46; }
        .badge-stock-low { background: #fef3c7; color: #92400e; }
        .badge-stock-critical { background: #fee2e2; color: #991b1b; }
        .badge-stock-out { background: #ef476f; color: white; }
        
        .badge-expiry-normal { background: #d1fae5; color: #065f46; }
        .badge-expiry-soon { background: #fef3c7; color: #92400e; }
        .badge-expiry-expired { background: #ef476f; color: white; }
        
        /* Table */
        .table-container { overflow-x: auto; }
        .table-container table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }
        .table-container th {
            text-align: left;
            padding: 10px 12px;
            border-bottom: 2px solid #e5e7eb;
            font-weight: 600;
            color: #6b7280;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            white-space: nowrap;
        }
        .table-container td {
            padding: 10px 12px;
            border-bottom: 1px solid #e5e7eb;
            vertical-align: middle;
        }
        .table-container tr:hover { background: #f9fafb; }
        .table-container .no-data {
            text-align: center;
            padding: 40px 0;
            color: #6b7280;
        }
        .table-container .no-data i {
            font-size: 36px;
            display: block;
            margin-bottom: 10px;
            color: #d1d5db;
        }
        
        .order-actions {
            display: flex;
            gap: 4px;
            flex-wrap: wrap;
        }
        .order-actions .btn { padding: 2px 8px; font-size: 11px; }
        
        .progress-bar {
            height: 6px;
            border-radius: 3px;
            background: #e5e7eb;
            overflow: hidden;
            width: 100px;
        }
        .progress-bar .fill {
            height: 100%;
            border-radius: 3px;
            transition: width 0.3s;
        }
        .progress-bar .fill.green { background: #06d6a0; }
        .progress-bar .fill.yellow { background: #f9c74f; }
        .progress-bar .fill.red { background: #ef476f; }
        
        /* Modal */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.5);
            z-index: 1050;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }
        .modal-overlay.active { display: flex; }
        .modal {
            background: white;
            border-radius: 12px;
            max-width: 700px;
            width: 100%;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 20px 60px rgba(0,0,0,0.2);
        }
        .modal-header {
            padding: 16px 20px;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            background: white;
            z-index: 1;
            border-radius: 12px 12px 0 0;
        }
        .modal-header h2 { font-size: 18px; font-weight: 600; }
        .modal-close {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: #6b7280;
        }
        .modal-close:hover { color: #1f2937; }
        .modal-body { padding: 20px; }
        .modal-footer {
            padding: 16px 20px;
            border-top: 1px solid #e5e7eb;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            position: sticky;
            bottom: 0;
            background: white;
            border-radius: 0 0 12px 12px;
        }
        
        .form-group { margin-bottom: 16px; }
        .form-group label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 4px;
            color: #374151;
        }
        .form-group .required { color: #ef476f; }
        .form-control {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            font-size: 13px;
            transition: border-color 0.2s;
        }
        .form-control:focus {
            outline: none;
            border-color: #06d6a0;
            box-shadow: 0 0 0 3px rgba(6, 214, 160, 0.1);
        }
        select.form-control { appearance: auto; }
        textarea.form-control { resize: vertical; min-height: 60px; }
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }
        
        .text-muted { color: #6b7280; }
        .text-center { text-align: center; }
        .mt-2 { margin-top: 8px; }
        .mb-2 { margin-bottom: 8px; }
        .flex { display: flex; }
        .flex-wrap { flex-wrap: wrap; }
        .items-center { align-items: center; }
        .gap-2 { gap: 8px; }
        .w-full { width: 100%; }
        
        /* Recent Transactions */
        .transaction-item {
            padding: 8px 12px;
            border-bottom: 1px solid #f3f4f6;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 13px;
        }
        .transaction-item:last-child { border-bottom: none; }
        .transaction-item .trans-type {
            font-size: 10px;
            padding: 2px 8px;
            border-radius: 10px;
            font-weight: 600;
        }
        .trans-purchase { background: #dbeafe; color: #1e40af; }
        .trans-adjustment_in { background: #d1fae5; color: #065f46; }
        .trans-adjustment_out { background: #fee2e2; color: #991b1b; }
        .trans-dispensed { background: #fef3c7; color: #92400e; }
        .trans-return { background: #f3e8ff; color: #6b21a5; }
        .trans-expired { background: #fef2f2; color: #dc2626; }
        
        /* Responsive */
        @media (max-width: 768px) {
            .filter-bar { flex-direction: column; align-items: stretch; }
            .filter-bar select, .filter-bar input { width: 100%; }
            .stats-bar { gap: 6px; }
            .stat-pill { font-size: 11px; padding: 4px 10px; }
            .page-header h1 { font-size: 20px; }
            .card-body { padding: 12px; }
            .table-container { padding: 0; overflow-x: auto; }
            .form-row { grid-template-columns: 1fr; }
        }
        
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: #f3f4f6; border-radius: 4px; }
        ::-webkit-scrollbar-thumb { background: #e5e7eb; border-radius: 4px; }
        ::-webkit-scrollbar-thumb:hover { background: #6b7280; }
    </style>
</head>
<body>
<div id="wrapper">
    <?php include('assets/inc/nav.php'); ?>
    <?php include('assets/inc/sidebar.php'); ?>
    
    <div id="page-wrapper">
        <div class="page-header">
            <h1><i class="fas fa-boxes"></i> Lab Inventory</h1>
            <div>
                <button class="btn btn-primary" onclick="openModal('addModal')">
                    <i class="fas fa-plus"></i> Add Item
                </button>
                <button class="btn" onclick="exportCSV()">
                    <i class="fas fa-file-export"></i> Export
                </button>
                <button class="btn" onclick="window.location.reload();">
                    <i class="fas fa-sync-alt"></i> Refresh
                </button>
            </div>
        </div>

        <?php if ($success_message): ?>
            <div class="alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success_message); ?></div>
        <?php endif; ?>
        <?php if ($error_message): ?>
            <div class="alert-danger"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error_message); ?></div>
        <?php endif; ?>

        <?php if ($stats['low_stock'] > 0 || $stats['out_of_stock'] > 0 || $stats['expiring_soon'] > 0): ?>
            <div class="alert-warning">
                <i class="fas fa-exclamation-triangle"></i> 
                <?php if ($stats['out_of_stock'] > 0): ?>
                    <strong><?php echo $stats['out_of_stock']; ?> item(s) out of stock</strong>
                <?php endif; ?>
                <?php if ($stats['low_stock'] > 0): ?>
                    <?php echo $stats['out_of_stock'] > 0 ? ' • ' : ''; ?>
                    <strong><?php echo $stats['low_stock']; ?> item(s) low stock</strong>
                <?php endif; ?>
                <?php if ($stats['expiring_soon'] > 0): ?>
                    <?php echo ($stats['out_of_stock'] > 0 || $stats['low_stock'] > 0) ? ' • ' : ''; ?>
                    <strong><?php echo $stats['expiring_soon']; ?> item(s) expiring soon</strong>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- Stats Bar -->
        <div class="stats-bar">
            <div class="stat-pill total">
                <span class="count"><?php echo $stats['total_items']; ?></span>
                <span class="label">Total Items</span>
            </div>
            <div class="stat-pill low-stock">
                <span class="count" style="color:#d97706;"><?php echo $stats['low_stock']; ?></span>
                <span class="label">Low Stock</span>
            </div>
            <div class="stat-pill out-of-stock">
                <span class="count" style="color:#ef476f;"><?php echo $stats['out_of_stock']; ?></span>
                <span class="label">Out of Stock</span>
            </div>
            <div class="stat-pill expiring">
                <span class="count" style="color:#d97706;"><?php echo $stats['expiring_soon']; ?></span>
                <span class="label">Expiring Soon</span>
            </div>
            <div class="stat-pill expired">
                <span class="count" style="color:#ef476f;"><?php echo $stats['expired']; ?></span>
                <span class="label">Expired</span>
            </div>
            <div class="stat-pill value">
                <span class="count" style="color:#06d6a0;"><?php echo number_format($stats['total_value'], 2); ?></span>
                <span class="label">Total Value (ETB)</span>
            </div>
        </div>

        <!-- Filter Bar -->
        <div class="filter-bar">
            <div>
                <span class="filter-label">Status</span>
                <select id="filterStatus" onchange="applyFilters()">
                    <option value="all">All</option>
                    <option value="low_stock" <?php echo $filter_status === 'low_stock' ? 'selected' : ''; ?>>Low Stock</option>
                    <option value="out_of_stock" <?php echo $filter_status === 'out_of_stock' ? 'selected' : ''; ?>>Out of Stock</option>
                    <option value="expiring" <?php echo $filter_status === 'expiring' ? 'selected' : ''; ?>>Expiring Soon</option>
                    <option value="expired" <?php echo $filter_status === 'expired' ? 'selected' : ''; ?>>Expired</option>
                </select>
            </div>
            <div>
                <span class="filter-label">Dosage Form</span>
                <select id="filterCategory" onchange="applyFilters()">
                    <option value="">All</option>
                    <?php foreach ($dosage_forms as $form): ?>
                        <option value="<?php echo htmlspecialchars($form); ?>" <?php echo $filter_category === $form ? 'selected' : ''; ?>>
                            <?php echo ucfirst(str_replace('_', ' ', $form)); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="flex:1;min-width:150px;">
                <input type="text" class="form-control" id="filterSearch" placeholder="Search by name, code, batch..." 
                       value="<?php echo htmlspecialchars($search); ?>" onkeypress="if(event.key==='Enter') applyFilters()">
            </div>
            <button class="btn btn-primary" onclick="applyFilters()"><i class="fas fa-search"></i> Filter</button>
            <button class="btn" onclick="resetFilters()"><i class="fas fa-times"></i> Reset</button>
        </div>

        <!-- Inventory Table -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-list"></i> Inventory Items</h3>
                <span class="text-muted"><?php echo count($inventory_items); ?> items</span>
            </div>
            <div class="card-body">
                <div class="table-container">
                    <table id="inventoryTable">
                        <thead>
                            <tr>
                                <th>Item</th>
                                <th>Batch</th>
                                <th>Stock</th>
                                <th>Status</th>
                                <th>Expiry</th>
                                <th>Price</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($inventory_items)): ?>
                                <tr>
                                    <td colspan="7" class="no-data">
                                        <i class="fas fa-inbox"></i>
                                        No inventory items found
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($inventory_items as $item): ?>
                                    <?php 
                                    $stock_percentage = $item['reorder_level'] > 0 ? 
                                        min(100, ($item['quantity_in_hand'] / ($item['reorder_level'] * 2)) * 100) : 100;
                                    $stock_class = $item['quantity_in_hand'] == 0 ? 'out' : 
                                        ($item['quantity_in_hand'] <= $item['reorder_level'] ? 'critical' : 
                                        ($item['quantity_in_hand'] <= $item['reorder_level'] * 1.5 ? 'low' : 'normal'));
                                    $expiry_days = (strtotime($item['expiry_date']) - time()) / 86400;
                                    $expiry_class = $expiry_days < 0 ? 'expired' : 
                                        ($expiry_days <= 30 ? 'soon' : 'normal');
                                    ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo htmlspecialchars($item['generic_name']); ?></strong>
                                            <?php if ($item['brand_name']): ?>
                                                <br><small class="text-muted"><?php echo htmlspecialchars($item['brand_name']); ?></small>
                                            <?php endif; ?>
                                            <br><small class="text-muted">Code: <?php echo htmlspecialchars($item['medicine_code']); ?></small>
                                        </td>
                                        <td>
                                            <span style="font-weight:500;"><?php echo htmlspecialchars($item['batch_number']); ?></span>
                                            <?php if ($item['manufacturer']): ?>
                                                <br><small class="text-muted"><?php echo htmlspecialchars($item['manufacturer']); ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="flex items-center gap-2">
                                                <span style="font-weight:600;font-size:16px;"><?php echo $item['quantity_in_hand']; ?></span>
                                                <span class="text-muted"><?php echo strtoupper(str_replace('_', ' ', $item['unit_of_measure'] ?? '')); ?></span>
                                            </div>
                                            <div class="progress-bar">
                                                <div class="fill <?php echo $stock_class === 'normal' ? 'green' : ($stock_class === 'low' ? 'yellow' : 'red'); ?>" 
                                                     style="width: <?php echo $stock_percentage; ?>%;"></div>
                                            </div>
                                            <small class="text-muted">Reorder: <?php echo $item['reorder_level']; ?></small>
                                        </td>
                                        <td>
                                            <?php if ($item['quantity_in_hand'] == 0): ?>
                                                <span class="badge badge-stock-out">Out of Stock</span>
                                            <?php elseif ($item['quantity_in_hand'] <= $item['reorder_level']): ?>
                                                <span class="badge badge-stock-critical">Critical</span>
                                            <?php elseif ($item['quantity_in_hand'] <= $item['reorder_level'] * 1.5): ?>
                                                <span class="badge badge-stock-low">Low</span>
                                            <?php else: ?>
                                                <span class="badge badge-stock-normal">Adequate</span>
                                            <?php endif; ?>
                                            <?php if ($item['is_quarantined']): ?>
                                                <br><span class="badge badge-danger">Quarantined</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($expiry_class === 'expired'): ?>
                                                <span class="badge badge-expiry-expired">Expired</span>
                                            <?php elseif ($expiry_class === 'soon'): ?>
                                                <span class="badge badge-expiry-soon"><?php echo round($expiry_days); ?> days</span>
                                            <?php else: ?>
                                                <span class="badge badge-expiry-normal"><?php echo date('M d, Y', strtotime($item['expiry_date'])); ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div>Purchase: <?php echo number_format($item['purchase_price'], 2); ?></div>
                                            <div>Sale: <?php echo number_format($item['selling_price'], 2); ?></div>
                                            <small class="text-muted"><?php echo date('M d, Y', strtotime($item['created_at'])); ?></small>
                                        </td>
                                        <td>
                                            <div class="order-actions">
                                                <button class="btn btn-sm btn-warning" onclick="adjustStock(<?php echo $item['id']; ?>, '<?php echo htmlspecialchars($item['generic_name']); ?>')">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button class="btn btn-sm btn-danger" onclick="deleteItem(<?php echo $item['id']; ?>)">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Recent Transactions -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-history"></i> Recent Stock Transactions</h3>
                <span class="text-muted">Last 20 transactions</span>
            </div>
            <div class="card-body">
                <?php if (empty($recent_transactions)): ?>
                    <div class="no-data" style="padding:20px 0;">
                        <i class="fas fa-inbox"></i>
                        No recent transactions
                    </div>
                <?php else: ?>
                    <?php foreach ($recent_transactions as $trans): ?>
                        <div class="transaction-item">
                            <div>
                                <span class="trans-type trans-<?php echo str_replace('_', '', $trans['transaction_type']); ?>">
                                    <?php echo ucfirst(str_replace('_', ' ', $trans['transaction_type'])); ?>
                                </span>
                                <strong><?php echo htmlspecialchars($trans['generic_name']); ?></strong>
                                <span class="text-muted">x<?php echo $trans['quantity']; ?></span>
                                <?php if ($trans['notes']): ?>
                                    <span class="text-muted">- <?php echo htmlspecialchars($trans['notes']); ?></span>
                                <?php endif; ?>
                            </div>
                            <div>
                                <span class="text-muted"><?php echo date('M d, H:i', strtotime($trans['created_at'])); ?></span>
                                <br>
                                <small class="text-muted">By: <?php echo htmlspecialchars($trans['performed_by_name'] ?? 'System'); ?></small>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- ============================================ -->
<!-- ADD INVENTORY MODAL -->
<!-- ============================================ -->
<div class="modal-overlay" id="addModal">
    <div class="modal">
        <div class="modal-header">
            <h2><i class="fas fa-plus-circle"></i> Add Inventory Item</h2>
            <button class="modal-close" onclick="closeModal('addModal')">&times;</button>
        </div>
        <form method="POST" action="">
            <input type="hidden" name="action" value="add">
            <div class="modal-body">
                <div class="form-group">
                    <label>Medicine <span class="required">*</span></label>
                    <select name="medicine_id" class="form-control" required>
                        <option value="">Select Medicine</option>
                        <?php foreach ($medicines as $medicine): ?>
                            <option value="<?php echo $medicine['id']; ?>">
                                <?php echo htmlspecialchars($medicine['generic_name']); ?>
                                <?php if ($medicine['brand_name']): ?>
                                    (<?php echo htmlspecialchars($medicine['brand_name']); ?>)
                                <?php endif; ?>
                                - <?php echo htmlspecialchars($medicine['medicine_code']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Batch Number <span class="required">*</span></label>
                        <input type="text" name="batch_number" class="form-control" required placeholder="e.g., BATCH-2024-001">
                    </div>
                    <div class="form-group">
                        <label>Supplier Name</label>
                        <input type="text" name="supplier_name" class="form-control" placeholder="e.g., Pharma Ethiopia PLC">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Purchase Invoice Number</label>
                        <input type="text" name="purchase_invoice_number" class="form-control" placeholder="e.g., INV-2024-001">
                    </div>
                    <div class="form-group">
                        <label>Quantity Received <span class="required">*</span></label>
                        <input type="number" name="quantity_received" class="form-control" required min="1">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Purchase Price (ETB)</label>
                        <input type="number" name="purchase_price" class="form-control" step="0.01" min="0" placeholder="0.00">
                    </div>
                    <div class="form-group">
                        <label>Selling Price (ETB)</label>
                        <input type="number" name="selling_price" class="form-control" step="0.01" min="0" placeholder="0.00">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Manufacturing Date</label>
                        <input type="date" name="manufacturing_date" class="form-control">
                    </div>
                    <div class="form-group">
                        <label>Expiry Date <span class="required">*</span></label>
                        <input type="date" name="expiry_date" class="form-control" required>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Reorder Level</label>
                        <input type="number" name="reorder_level" class="form-control" min="0" value="10">
                    </div>
                    <div class="form-group">
                        <label>Reorder Quantity</label>
                        <input type="number" name="reorder_quantity" class="form-control" min="0" value="20">
                    </div>
                </div>

                <div class="form-group">
                    <label>Notes</label>
                    <textarea name="notes" class="form-control" placeholder="Additional notes..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" onclick="closeModal('addModal')">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Add Item</button>
            </div>
        </form>
    </div>
</div>

<!-- ============================================ -->
<!-- ADJUST STOCK MODAL -->
<!-- ============================================ -->
<div class="modal-overlay" id="adjustModal">
    <div class="modal" style="max-width:500px;">
        <div class="modal-header">
            <h2><i class="fas fa-edit"></i> Adjust Stock</h2>
            <button class="modal-close" onclick="closeModal('adjustModal')">&times;</button>
        </div>
        <form method="POST" action="">
            <input type="hidden" name="action" value="update_quantity">
            <input type="hidden" name="inventory_id" id="adjustInventoryId">
            <div class="modal-body">
                <div style="background:#f0fdf4;padding:12px 16px;border-radius:8px;margin-bottom:16px;">
                    <div class="flex flex-wrap gap-2">
                        <div><strong>Item:</strong> <span id="adjustItemName"></span></div>
                    </div>
                </div>

                <div class="form-group">
                    <label>Adjustment Type <span class="required">*</span></label>
                    <select name="adjustment_type" class="form-control" required>
                        <option value="adjustment_in">Add Stock</option>
                        <option value="adjustment_out">Remove Stock</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>Quantity <span class="required">*</span></label>
                    <input type="number" name="quantity" class="form-control" required min="1" placeholder="Enter quantity">
                </div>

                <div class="form-group">
                    <label>Reason</label>
                    <textarea name="reason" class="form-control" placeholder="Reason for adjustment..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" onclick="closeModal('adjustModal')">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Update Stock</button>
            </div>
        </form>
    </div>
</div>

<script>
// ============================================
// MODAL FUNCTIONS
// ============================================
function openModal(id) {
    document.getElementById(id).classList.add('active');
    document.body.style.overflow = 'hidden';
}

function closeModal(id) {
    document.getElementById(id).classList.remove('active');
    document.body.style.overflow = '';
}

document.querySelectorAll('.modal-overlay').forEach(overlay => {
    overlay.addEventListener('click', function(e) {
        if (e.target === this) {
            this.classList.remove('active');
            document.body.style.overflow = '';
        }
    });
});

// ============================================
// INVENTORY ACTIONS
// ============================================
function adjustStock(inventoryId, itemName) {
    document.getElementById('adjustInventoryId').value = inventoryId;
    document.getElementById('adjustItemName').textContent = itemName;
    openModal('adjustModal');
}

function deleteItem(inventoryId) {
    if (!confirm('Are you sure you want to delete this inventory item? This action cannot be undone.')) return;
    
    const form = document.createElement('form');
    form.method = 'POST';
    form.innerHTML = `
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="inventory_id" value="${inventoryId}">
    `;
    document.body.appendChild(form);
    form.submit();
}

// ============================================
// FILTER FUNCTIONS
// ============================================
function applyFilters() {
    const status = document.getElementById('filterStatus').value;
    const category = document.getElementById('filterCategory').value;
    const search = document.getElementById('filterSearch').value;
    
    let url = window.location.pathname + '?';
    if (status) url += 'status=' + encodeURIComponent(status) + '&';
    if (category) url += 'category=' + encodeURIComponent(category) + '&';
    if (search) url += 'search=' + encodeURIComponent(search) + '&';
    window.location.href = url;
}

function resetFilters() {
    window.location.href = window.location.pathname;
}

// ============================================
// EXPORT CSV
// ============================================
function exportCSV() {
    window.location.href = 'ajax/export_inventory_csv.php' + window.location.search;
}

// ============================================
// KEYBOARD SHORTCUTS
// ============================================
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal-overlay.active').forEach(modal => {
            modal.classList.remove('active');
        });
        document.body.style.overflow = '';
    }
    if (e.ctrlKey && e.key === 'n') {
        e.preventDefault();
        openModal('addModal');
    }
});

// ============================================
// DATATABLES INITIALIZATION
// ============================================
$(document).ready(function() {
    var hasRows = $('#inventoryTable tbody tr:not(.no-data)').length > 0;
    
    if (hasRows) {
        $('#inventoryTable').DataTable({
            responsive: true,
            pageLength: 25,
            order: [[6, 'desc']],
            columnDefs: [
                { orderable: false, targets: [6] }
            ],
            language: {
                emptyTable: "No inventory items found",
                search: "Search:",
                lengthMenu: "Show _MENU_ entries",
                info: "Showing _START_ to _END_ of _TOTAL_ entries"
            }
        });
    } else {
        $('#inventoryTable').DataTable({
            responsive: true,
            pageLength: 25,
            order: [[6, 'desc']],
            columnDefs: [
                { orderable: false, targets: [6] }
            ],
            language: {
                emptyTable: "No inventory items found"
            }
        });
    }
});
</script>

<!-- DataTables -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>

</body>
</html>