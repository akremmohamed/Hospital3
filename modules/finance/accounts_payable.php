<?php
/**
 * accounts_payable.php
 * 
 * Supplier Invoices & Accounts Payable
 * 
 * Performs 3‑Way Matching between Purchase Order (PO), Goods Received Note (GRN),
 * and Supplier Invoice before authorizing payments.
 * 
 * Automatically creates/alters required tables/columns to match module needs.
 * Enhanced with: Multi-branch support, supplier management, aging reports,
 * payment scheduling, and advanced filtering.
 * 
 * Required permissions:
 *   - manage_accounts_payable : view and run matching
 *   - authorize_supplier_payments : approve settlements
 * 
 * @package HMS_ERP
 */

session_start();

// ----------------------------------------------------------------------
// 1. BOOTSTRAP & SECURITY
// ----------------------------------------------------------------------
require_once __DIR__ . '/../../config/db_connection.php';
require_once __DIR__ . '/../../includes/auth_helper.php';

date_default_timezone_set('Africa/Addis_Ababa');
if ($pdo) {
    $pdo->exec("SET time_zone = '+03:00'");
}

$user_id = (int)($_SESSION['user_id'] ?? 0);
$branch_id = (int)($_SESSION['branch_id'] ?? 0);
$user_type = $_SESSION['user_type'] ?? '';
$is_global_admin = ($user_type === 'super_admin' || $user_type === 'admin');

if ($user_id <= 0) {
    header('Location: ../../index.php');
    exit;
}

$can_view = $is_global_admin || user_has_permission($pdo, 'manage_accounts_payable', $user_id);
$can_settle = $is_global_admin || user_has_permission($pdo, 'authorize_supplier_payments', $user_id);

if (!$can_view) {
    header('Location: dashboard.php?error=unauthorized');
    exit;
}

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// ----------------------------------------------------------------------
// 2. CREATE/ALTER TABLES TO MATCH MODULE REQUIREMENTS (based on actual schema)
// ----------------------------------------------------------------------
try {
    // Ensure supplier_orders has payment_status column
    $check_payment = $pdo->query("SHOW COLUMNS FROM supplier_orders LIKE 'payment_status'");
    if ($check_payment->rowCount() == 0) {
        $pdo->exec("ALTER TABLE `supplier_orders` ADD COLUMN `payment_status` enum('unpaid','paid','partially_paid') NOT NULL DEFAULT 'unpaid' AFTER `order_status`");
    }

    // Ensure supplier_orders has total_quantity column (optional, for quantity matching)
    $check_qty = $pdo->query("SHOW COLUMNS FROM supplier_orders LIKE 'total_quantity'");
    if ($check_qty->rowCount() == 0) {
        $pdo->exec("ALTER TABLE `supplier_orders` ADD COLUMN `total_quantity` decimal(12,2) DEFAULT NULL AFTER `total_amount`");
    }

    // Ensure supplier_orders has notes column
    $check_notes = $pdo->query("SHOW COLUMNS FROM supplier_orders LIKE 'notes'");
    if ($check_notes->rowCount() == 0) {
        $pdo->exec("ALTER TABLE `supplier_orders` ADD COLUMN `notes` text DEFAULT NULL AFTER `received_date`");
    }

    // Ensure supplier_orders has received_date column
    $check_recv = $pdo->query("SHOW COLUMNS FROM supplier_orders LIKE 'received_date'");
    if ($check_recv->rowCount() == 0) {
        $pdo->exec("ALTER TABLE `supplier_orders` ADD COLUMN `received_date` date DEFAULT NULL AFTER `delivery_date`");
    }

    // Create purchase_invoices table if it doesn't exist
    $pdo->exec("CREATE TABLE IF NOT EXISTS `purchase_invoices` (
        `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
        `branch_id` int(10) UNSIGNED NOT NULL,
        `supplier_order_id` int(10) UNSIGNED NOT NULL,
        `invoice_number` varchar(100) NOT NULL,
        `invoice_amount` decimal(12,2) NOT NULL,
        `received_date` date NOT NULL,
        `due_date` date DEFAULT NULL,
        `status` enum('pending_matching','matched_verified','discrepancy','settled','cancelled') NOT NULL DEFAULT 'pending_matching',
        `created_by_user_id` int(10) UNSIGNED NOT NULL,
        `settled_by_user_id` int(10) UNSIGNED DEFAULT NULL,
        `settled_at` timestamp NULL DEFAULT NULL,
        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
        `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
        `deleted_at` timestamp NULL DEFAULT NULL,
        PRIMARY KEY (`id`),
        KEY `idx_branch_id` (`branch_id`),
        KEY `idx_supplier_order_id` (`supplier_order_id`),
        KEY `idx_status` (`status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Add deleted_at column if it doesn't exist
    try {
        $pdo->exec("ALTER TABLE `purchase_invoices` ADD COLUMN `deleted_at` timestamp NULL DEFAULT NULL AFTER `updated_at`");
    } catch (PDOException $e) {
        // Column already exists
    }

    // Add due_date column if it doesn't exist
    try {
        $pdo->exec("ALTER TABLE `purchase_invoices` ADD COLUMN `due_date` date DEFAULT NULL AFTER `received_date`");
    } catch (PDOException $e) {
        // Column already exists
    }

    // Add settled_by_user_id and settled_at columns
    try {
        $pdo->exec("ALTER TABLE `purchase_invoices` ADD COLUMN `settled_by_user_id` int(10) UNSIGNED DEFAULT NULL AFTER `created_by_user_id`");
        $pdo->exec("ALTER TABLE `purchase_invoices` ADD COLUMN `settled_at` timestamp NULL DEFAULT NULL AFTER `settled_by_user_id`");
    } catch (PDOException $e) {
        // Columns already exist
    }

    // Create suppliers table if it doesn't exist
    $pdo->exec("CREATE TABLE IF NOT EXISTS `suppliers` (
        `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
        `supplier_name` varchar(191) NOT NULL,
        `supplier_code` varchar(50) DEFAULT NULL,
        `tax_number` varchar(50) DEFAULT NULL,
        `phone` varchar(20) DEFAULT NULL,
        `email` varchar(100) DEFAULT NULL,
        `address` text DEFAULT NULL,
        `status` enum('active','inactive') NOT NULL DEFAULT 'active',
        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
        `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
        `deleted_at` timestamp NULL DEFAULT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_supplier_code` (`supplier_code`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Insert default suppliers if none exist
    $check_suppliers = $pdo->query("SELECT COUNT(*) FROM suppliers")->fetchColumn();
    if ($check_suppliers == 0) {
        $pdo->exec("INSERT INTO suppliers (supplier_name, supplier_code, phone, email, status) VALUES 
            ('Pharma Ethiopia PLC', 'SUP-001', '+251-11-555-0100', 'orders@pharmaethiopia.com', 'active'),
            ('MedTech Solutions', 'SUP-002', '+251-11-555-0200', 'sales@medtech.com', 'active'),
            ('Global Medical Supplies', 'SUP-003', '+251-11-555-0300', 'info@globalmed.com', 'active'),
            ('Ethio Lab Equipment', 'SUP-004', '+251-11-555-0400', 'contact@ethiolab.com', 'active'),
            ('CleanCare Solutions', 'SUP-005', '+251-11-555-0500', 'orders@cleancare.com', 'active')");
    }

} catch (PDOException $e) {
    error_log('Database setup error: ' . $e->getMessage());
    die('Database setup error. Please contact administrator.');
}

// ----------------------------------------------------------------------
// Helper functions
// ----------------------------------------------------------------------
function jsonResponse($success, $message, $data = null, $code = 200) {
    if (ob_get_length()) ob_clean();
    http_response_code($code);
    header('Content-Type: application/json');
    $response = ['success' => $success, 'message' => $message];
    if ($data !== null) $response = array_merge($response, $data);
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

function escapeHtml($str) {
    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}

function formatMoney($amount) {
    return number_format((float)$amount, 2);
}

function applyBranchFilter(&$sql, &$params, $branch_id, $is_global_admin, $table_alias) {
    if (!$is_global_admin && $branch_id > 0) {
        $sql .= " AND {$table_alias}.branch_id = :branch_id";
        $params[':branch_id'] = $branch_id;
    }
}

// ----------------------------------------------------------------------
// 3. AJAX HANDLERS
// ----------------------------------------------------------------------

// GET endpoints
if (isset($_GET['ajax']) && $_GET['ajax'] === 'payable_action') {
    $action = $_GET['action'] ?? '';

    // Fetch all pending invoices for left panel (refresh)
    if ($action === 'fetch_invoices') {
        try {
            $filter = $_GET['filter'] ?? 'all';
            $search = trim($_GET['search'] ?? '');
            
            $sql = "SELECT pi.id, pi.invoice_number, pi.invoice_amount, pi.received_date, pi.due_date, pi.status,
                           so.purchase_order_number AS po_number, so.total_amount AS po_total, so.payment_status,
                           so.supplier_name, so.order_date,
                           s.supplier_code, s.phone AS supplier_phone,
                           (DATEDIFF(CURDATE(), pi.received_date)) AS aging_days
                    FROM purchase_invoices pi
                    JOIN supplier_orders so ON pi.supplier_order_id = so.id
                    LEFT JOIN suppliers s ON s.supplier_name = so.supplier_name
                    WHERE (pi.deleted_at IS NULL OR pi.deleted_at = '0000-00-00 00:00:00')";
            
            $params = [];
            
            if (!$is_global_admin && $branch_id > 0) {
                $sql .= " AND pi.branch_id = :branch_id";
                $params[':branch_id'] = $branch_id;
            }
            
            if ($filter === 'pending') {
                $sql .= " AND pi.status = 'pending_matching'";
            } elseif ($filter === 'matched') {
                $sql .= " AND pi.status = 'matched_verified'";
            } elseif ($filter === 'discrepancy') {
                $sql .= " AND pi.status = 'discrepancy'";
            } elseif ($filter === 'settled') {
                $sql .= " AND pi.status = 'settled'";
            } elseif ($filter === 'all') {
                $sql .= " AND pi.status IN ('pending_matching', 'matched_verified', 'discrepancy')";
            }
            
            if (!empty($search)) {
                $sql .= " AND (so.supplier_name LIKE :search OR pi.invoice_number LIKE :search OR so.purchase_order_number LIKE :search)";
                $params[':search'] = '%' . $search . '%';
            }
            
            $sql .= " AND so.payment_status != 'paid'
                      ORDER BY FIELD(pi.status, 'discrepancy', 'pending_matching', 'matched_verified'), pi.received_date ASC";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);
            jsonResponse(true, '', ['invoices' => $invoices]);
        } catch (PDOException $e) {
            error_log('fetch_invoices error: ' . $e->getMessage());
            jsonResponse(false, 'Database error: ' . $e->getMessage());
        }
    }

    // Fetch HUD metrics
    if ($action === 'fetch_metrics') {
        try {
            // 1. Total accounts payable outstanding (sum of unpaid invoices)
            $sql = "SELECT COALESCE(SUM(pi.invoice_amount), 0) AS total_payable
                    FROM purchase_invoices pi
                    JOIN supplier_orders so ON pi.supplier_order_id = so.id
                    WHERE (pi.deleted_at IS NULL OR pi.deleted_at = '0000-00-00 00:00:00')
                      AND so.payment_status != 'paid'";
            $params = [];
            if (!$is_global_admin && $branch_id > 0) {
                $sql .= " AND pi.branch_id = :branch_id";
                $params[':branch_id'] = $branch_id;
            }
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $total_payable = (float)$stmt->fetchColumn();

            // 2. Discrepancy alert count
            $sql = "SELECT COUNT(*) FROM purchase_invoices
                    WHERE (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')
                      AND status = 'discrepancy'";
            $params = [];
            if (!$is_global_admin && $branch_id > 0) {
                $sql .= " AND branch_id = :branch_id";
                $params[':branch_id'] = $branch_id;
            }
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $discrepancy_count = (int)$stmt->fetchColumn();

            // 3. Safe verified invoices awaiting settlement
            $sql = "SELECT COUNT(pi.id)
                    FROM purchase_invoices pi
                    JOIN supplier_orders so ON pi.supplier_order_id = so.id
                    WHERE (pi.deleted_at IS NULL OR pi.deleted_at = '0000-00-00 00:00:00')
                      AND pi.status = 'matched_verified' 
                      AND so.payment_status != 'paid'";
            $params = [];
            if (!$is_global_admin && $branch_id > 0) {
                $sql .= " AND pi.branch_id = :branch_id";
                $params[':branch_id'] = $branch_id;
            }
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $verified_count = (int)$stmt->fetchColumn();

            // 4. Overdue invoices (aging > 30 days)
            $sql = "SELECT COUNT(*) 
                    FROM purchase_invoices pi
                    JOIN supplier_orders so ON pi.supplier_order_id = so.id
                    WHERE (pi.deleted_at IS NULL OR pi.deleted_at = '0000-00-00 00:00:00')
                      AND DATEDIFF(CURDATE(), pi.received_date) > 30
                      AND so.payment_status != 'paid'";
            $params = [];
            if (!$is_global_admin && $branch_id > 0) {
                $sql .= " AND pi.branch_id = :branch_id";
                $params[':branch_id'] = $branch_id;
            }
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $overdue_count = (int)$stmt->fetchColumn();

            jsonResponse(true, '', [
                'total_payable' => $total_payable,
                'discrepancy_count' => $discrepancy_count,
                'verified_count' => $verified_count,
                'overdue_count' => $overdue_count
            ]);
        } catch (PDOException $e) {
            error_log('fetch_metrics error: ' . $e->getMessage());
            jsonResponse(false, 'Database error: ' . $e->getMessage());
        }
    }

    // 3-Way Matching audit
    if ($action === 'execute_3way_match') {
        $invoice_id = (int)($_GET['invoice_id'] ?? 0);
        if ($invoice_id <= 0) jsonResponse(false, 'Invalid invoice ID');

        try {
            // Fetch invoice details and associated PO
            $sql = "SELECT pi.id, pi.invoice_number, pi.invoice_amount, pi.supplier_order_id, pi.status,
                           so.purchase_order_number AS po_number, so.total_amount AS po_total, so.total_quantity AS po_quantity,
                           so.supplier_name, so.order_date,
                           s.phone AS supplier_phone, s.email AS supplier_email
                    FROM purchase_invoices pi
                    JOIN supplier_orders so ON pi.supplier_order_id = so.id
                    LEFT JOIN suppliers s ON s.supplier_name = so.supplier_name
                    WHERE pi.id = :id AND (pi.deleted_at IS NULL OR pi.deleted_at = '0000-00-00 00:00:00')";
            $params = [':id' => $invoice_id];
            if (!$is_global_admin && $branch_id > 0) {
                $sql .= " AND pi.branch_id = :branch_id";
                $params[':branch_id'] = $branch_id;
            }
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$invoice) jsonResponse(false, 'Invoice not found');

            // Get GRN aggregated quantity received from stock_transactions
            $grn_sql = "SELECT COALESCE(SUM(quantity), 0) AS received_quantity
                        FROM stock_transactions
                        WHERE reference_id = :order_id AND transaction_type IN ('purchase', 'stock_in')
                        AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')";
            $grn_stmt = $pdo->prepare($grn_sql);
            $grn_stmt->execute([':order_id' => $invoice['supplier_order_id']]);
            $grn_qty = (float)$grn_stmt->fetchColumn();

            // Get procurement items for detailed matching
            $items_sql = "SELECT item_type, reference_id, quantity, batch_number, expiry_date, purchase_price
                          FROM procurement_items
                          WHERE supplier_order_id = :order_id
                          AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')";
            $items_stmt = $pdo->prepare($items_sql);
            $items_stmt->execute([':order_id' => $invoice['supplier_order_id']]);
            $procurement_items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);

            $match_status = '';
            $discrepancy_msg = '';
            $amount_match = (abs((float)$invoice['po_total'] - (float)$invoice['invoice_amount']) < 0.01);
            $po_qty = $invoice['po_quantity'];
            $qty_match = ($po_qty === null) || (abs((float)$po_qty - $grn_qty) < 0.01);

            if ($amount_match && $qty_match) {
                $match_status = 'matched_verified';
                $discrepancy_msg = '';
            } else {
                $match_status = 'discrepancy';
                if (!$amount_match) {
                    $diff = (float)$invoice['invoice_amount'] - (float)$invoice['po_total'];
                    $discrepancy_msg = "Audit Warning: Billed Invoice Amount exceeds the original Purchase Order Authorized Total by " . number_format($diff, 2) . " ETB.";
                } elseif (!$qty_match && $po_qty !== null) {
                    $diff_qty = $grn_qty - (float)$po_qty;
                    $discrepancy_msg = "Quantity mismatch: Ordered {$po_qty} units, received {$grn_qty} units. Difference of " . abs($diff_qty) . " units.";
                }
            }

            // Update the invoice status in DB
            $update = $pdo->prepare("UPDATE purchase_invoices SET status = :status, updated_at = NOW() WHERE id = :id");
            $update->execute([':status' => $match_status, ':id' => $invoice_id]);

            // Calculate aging
            $aging_days = (new DateTime($invoice['received_date']))->diff(new DateTime())->days;

            jsonResponse(true, '', [
                'invoice' => $invoice,
                'po_total' => (float)$invoice['po_total'],
                'po_quantity' => $po_qty !== null ? (float)$po_qty : null,
                'grn_quantity' => $grn_qty,
                'invoice_amount' => (float)$invoice['invoice_amount'],
                'match_status' => $match_status,
                'discrepancy_msg' => $discrepancy_msg,
                'aging_days' => $aging_days,
                'procurement_items' => $procurement_items
            ]);
        } catch (PDOException $e) {
            error_log('execute_3way_match error: ' . $e->getMessage());
            jsonResponse(false, 'Database error: ' . $e->getMessage());
        }
    }

    // Fetch supplier list
    if ($action === 'fetch_suppliers') {
        try {
            $sql = "SELECT id, supplier_name, supplier_code, phone, email, status 
                    FROM suppliers 
                    WHERE (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')
                    ORDER BY supplier_name";
            $stmt = $pdo->prepare($sql);
            $stmt->execute();
            $suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);
            jsonResponse(true, '', ['suppliers' => $suppliers]);
        } catch (PDOException $e) {
            error_log('fetch_suppliers error: ' . $e->getMessage());
            jsonResponse(false, 'Database error: ' . $e->getMessage());
        }
    }

    // Fetch aging summary
    if ($action === 'fetch_aging_summary') {
        try {
            $sql = "SELECT 
                        SUM(CASE WHEN DATEDIFF(CURDATE(), received_date) <= 30 THEN invoice_amount ELSE 0 END) AS current,
                        SUM(CASE WHEN DATEDIFF(CURDATE(), received_date) BETWEEN 31 AND 60 THEN invoice_amount ELSE 0 END) AS aging_31_60,
                        SUM(CASE WHEN DATEDIFF(CURDATE(), received_date) BETWEEN 61 AND 90 THEN invoice_amount ELSE 0 END) AS aging_61_90,
                        SUM(CASE WHEN DATEDIFF(CURDATE(), received_date) > 90 THEN invoice_amount ELSE 0 END) AS aging_90_plus
                    FROM purchase_invoices pi
                    JOIN supplier_orders so ON pi.supplier_order_id = so.id
                    WHERE (pi.deleted_at IS NULL OR pi.deleted_at = '0000-00-00 00:00:00')
                      AND so.payment_status != 'paid'";
            $params = [];
            if (!$is_global_admin && $branch_id > 0) {
                $sql .= " AND pi.branch_id = :branch_id";
                $params[':branch_id'] = $branch_id;
            }
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $aging = $stmt->fetch(PDO::FETCH_ASSOC);
            jsonResponse(true, '', ['aging' => $aging]);
        } catch (PDOException $e) {
            error_log('fetch_aging_summary error: ' . $e->getMessage());
            jsonResponse(false, 'Database error: ' . $e->getMessage());
        }
    }

    exit;
}

// POST AJAX actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    $incoming_token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($csrf_token, $incoming_token)) {
        jsonResponse(false, 'Security token mismatch');
    }
    $action = $_POST['action'] ?? '';

    // Approve settlement (disbursement)
    if ($action === 'approve_payable_settlement') {
        if (!$can_settle) jsonResponse(false, 'Permission denied. Only authorized officers can settle payments.');
        $invoice_id = (int)($_POST['invoice_id'] ?? 0);
        if ($invoice_id <= 0) jsonResponse(false, 'Invalid invoice ID');

        try {
            $pdo->beginTransaction();

            // Fetch the supplier_order_id and status
            $sql = "SELECT supplier_order_id, status FROM purchase_invoices WHERE id = :id AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')";
            $params = [':id' => $invoice_id];
            if (!$is_global_admin && $branch_id > 0) {
                $sql .= " AND branch_id = :branch_id";
                $params[':branch_id'] = $branch_id;
            }
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $inv = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$inv) {
                $pdo->rollBack();
                jsonResponse(false, 'Invoice not found');
            }
            if ($inv['status'] !== 'matched_verified') {
                $pdo->rollBack();
                jsonResponse(false, 'Invoice must be matched and verified before settlement.');
            }

            // Update supplier_orders
            $upd_order = $pdo->prepare("UPDATE supplier_orders SET payment_status = 'paid', order_status = 'received', updated_at = NOW() WHERE id = :order_id");
            $upd_order->execute([':order_id' => $inv['supplier_order_id']]);

            // Update purchase_invoices
            $upd_inv = $pdo->prepare("UPDATE purchase_invoices 
                                     SET status = 'settled', settled_by_user_id = :user_id, settled_at = NOW(), updated_at = NOW() 
                                     WHERE id = :id");
            $upd_inv->execute([':user_id' => $user_id, ':id' => $invoice_id]);

            // Log settlement
            $log_sql = "INSERT INTO status_transition_logs 
                        (visit_id, entity_type, entity_id, old_status, new_status, changed_by_user_id, changed_at, created_at)
                        VALUES (0, 'purchase_invoice', :invoice_id, :old_status, 'settled', :user_id, NOW(), NOW())";
            $log_stmt = $pdo->prepare($log_sql);
            $log_stmt->execute([
                ':invoice_id' => $invoice_id,
                ':old_status' => $inv['status'],
                ':user_id' => $user_id
            ]);

            $pdo->commit();
            jsonResponse(true, 'Payment authorized and payable ledger closed successfully.');
        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log('Settlement error: ' . $e->getMessage());
            jsonResponse(false, 'Database error: ' . $e->getMessage());
        }
    }

    // Mark invoice as disputed (manual override)
    if ($action === 'mark_disputed') {
        if (!$can_settle) jsonResponse(false, 'Permission denied.');
        $invoice_id = (int)($_POST['invoice_id'] ?? 0);
        $dispute_reason = trim($_POST['dispute_reason'] ?? '');
        
        if ($invoice_id <= 0 || empty($dispute_reason)) {
            jsonResponse(false, 'Invalid parameters');
        }

        try {
            $update = $pdo->prepare("UPDATE purchase_invoices 
                                    SET status = 'discrepancy', updated_at = NOW() 
                                    WHERE id = :id AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')");
            $params = [':id' => $invoice_id];
            if (!$is_global_admin && $branch_id > 0) {
                $update .= " AND branch_id = :branch_id";
                $params[':branch_id'] = $branch_id;
            }
            $update->execute($params);
            
            if ($update->rowCount() > 0) {
                jsonResponse(true, 'Invoice marked as disputed.');
            } else {
                jsonResponse(false, 'Invoice not found or already processed.');
            }
        } catch (PDOException $e) {
            error_log('mark_disputed error: ' . $e->getMessage());
            jsonResponse(false, 'Database error: ' . $e->getMessage());
        }
    }

    // Delete invoice (soft delete)
    if ($action === 'delete_invoice') {
        if (!$can_settle) jsonResponse(false, 'Permission denied.');
        $invoice_id = (int)($_POST['invoice_id'] ?? 0);
        if ($invoice_id <= 0) jsonResponse(false, 'Invalid invoice ID');

        try {
            $update = $pdo->prepare("UPDATE purchase_invoices 
                                    SET deleted_at = NOW() 
                                    WHERE id = :id AND status IN ('pending_matching', 'discrepancy')");
            $params = [':id' => $invoice_id];
            if (!$is_global_admin && $branch_id > 0) {
                $update .= " AND branch_id = :branch_id";
                $params[':branch_id'] = $branch_id;
            }
            $update->execute($params);
            
            if ($update->rowCount() > 0) {
                jsonResponse(true, 'Invoice deleted successfully.');
            } else {
                jsonResponse(false, 'Invoice not found or cannot be deleted.');
            }
        } catch (PDOException $e) {
            error_log('delete_invoice error: ' . $e->getMessage());
            jsonResponse(false, 'Database error: ' . $e->getMessage());
        }
    }

    jsonResponse(false, 'Unsupported action');
    exit;
}

// ----------------------------------------------------------------------
// 4. INITIAL PAGE DATA (for display, but mainly AJAX driven)
// ----------------------------------------------------------------------
$current_date = date('l, F j, Y');
$current_time = date('g:i A');
$suppliers = [];

try {
    $sql = "SELECT id, supplier_name, supplier_code FROM suppliers WHERE (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00') AND status = 'active' ORDER BY supplier_name";
    $suppliers = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('Fetch suppliers error: ' . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Accounts Payable | HMS ERP</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
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
            --light-bg: #f5f7fb;
            --white: #ffffff;
            --gray: #6c757d;
            --gray-light: #e9ecef;
            --orange: #f9844a;
            --purple: #7209b7;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif; background: linear-gradient(135deg, #f5f7fb 0%, #eef2f6 100%); overflow-x: hidden; }
        .content-page { margin-left: 280px; margin-top: 70px; padding: 24px 32px; transition: margin-left 0.3s cubic-bezier(0.4, 0, 0.2, 1); min-height: calc(100vh - 70px); }
        body.erp-sidebar-collapsed .content-page { margin-left: 85px; }
        @media (max-width: 768px) { .content-page { margin-left: 0 !important; padding: 16px; } }
        
        .welcome-header { background: linear-gradient(135deg, var(--primary-dark) 0%, var(--primary) 100%); border-radius: 24px; padding: 20px 28px; color: white; margin-bottom: 24px; }
        .welcome-title { font-size: 22px; font-weight: 700; margin-bottom: 4px; }
        .welcome-subtitle { color: rgba(255,255,255,0.7); font-size: 13px; }
        .time-badge { background: rgba(255,255,255,0.2); display: inline-block; padding: 8px 16px; border-radius: 30px; font-size: 13px; backdrop-filter: blur(4px); }
        
        .stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 28px; }
        @media (max-width: 992px) { .stats-grid { grid-template-columns: repeat(2, 1fr); } }
        @media (max-width: 576px) { .stats-grid { grid-template-columns: 1fr; } }
        
        .stat-card { background: white; border-radius: 16px; padding: 16px 20px; border: 1px solid var(--gray-light); }
        .stat-card.primary { border-left: 4px solid var(--primary); }
        .stat-card.warning { border-left: 4px solid var(--warning); }
        .stat-card.success { border-left: 4px solid var(--success); }
        .stat-card.danger { border-left: 4px solid var(--danger); }
        .stat-value { font-size: 28px; font-weight: 800; color: var(--dark); }
        .stat-label { font-size: 12px; color: var(--gray); font-weight: 500; margin-top: 2px; }
        
        .dashboard-grid { display: grid; grid-template-columns: 1fr 480px; gap: 24px; }
        @media (max-width: 1200px) { .dashboard-grid { grid-template-columns: 1fr; } }
        
        .card-modern { background: white; border-radius: 20px; border: 1px solid var(--gray-light); overflow: hidden; margin-bottom: 24px; }
        .card-header-custom { padding: 16px 20px; background: #f8fafc; border-bottom: 1px solid var(--gray-light); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; }
        .card-header-custom h3 { margin: 0; font-size: 16px; font-weight: 700; color: var(--dark); }
        
        .invoice-list { max-height: 600px; overflow-y: auto; }
        .invoice-list::-webkit-scrollbar { width: 6px; }
        .invoice-list::-webkit-scrollbar-thumb { background: var(--gray-light); border-radius: 3px; }
        
        .invoice-item { padding: 12px 16px; border-bottom: 1px solid #f0f2f5; cursor: pointer; transition: all 0.2s; }
        .invoice-item:hover { background: #f8fafc; }
        .invoice-item.active { background: #e0e7ff; border-left: 3px solid var(--primary); }
        
        .badge-status { display: inline-block; padding: 4px 10px; border-radius: 20px; font-size: 10px; font-weight: 600; }
        .badge-pending { background: #e9ecef; color: #495057; }
        .badge-matched { background: #d1fae5; color: #059669; }
        .badge-discrepancy { background: #fee2e2; color: #dc2626; }
        .badge-settled { background: #d1fae5; color: #059669; }
        
        .action-btn { padding: 8px 18px; border-radius: 12px; font-size: 13px; font-weight: 600; border: none; cursor: pointer; transition: all 0.2s; }
        .action-btn-primary { background: var(--primary); color: white; }
        .action-btn-primary:hover { background: var(--primary-dark); }
        .action-btn-success { background: var(--success); color: white; }
        .action-btn-success:hover { background: #059669; }
        .action-btn-danger { background: var(--danger); color: white; }
        .action-btn-danger:hover { background: #dc2626; }
        .action-btn-outline { background: white; border: 1px solid var(--gray-light); color: var(--dark); }
        .action-btn-outline:hover { border-color: var(--primary); color: var(--primary); }
        .action-btn-sm { padding: 4px 12px; font-size: 12px; }
        
        .toast-notification { position: fixed; bottom: 20px; right: 20px; background: white; border-radius: 12px; padding: 12px 20px; box-shadow: 0 10px 25px rgba(0,0,0,0.1); z-index: 1100; animation: slideIn 0.3s ease; display: flex; align-items: center; gap: 12px; border-left: 4px solid var(--success); }
        .toast-error { border-left-color: var(--danger); }
        @keyframes slideIn { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
        
        .loading-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(255,255,255,0.7); z-index: 2000; display: none; align-items: center; justify-content: center; }
        .loading-overlay.active { display: flex; }
        .spinner { width: 40px; height: 40px; border: 3px solid var(--gray-light); border-top-color: var(--primary); border-radius: 50%; animation: spin 0.8s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }
        
        .empty-state { text-align: center; padding: 40px; color: var(--gray); }
        .detail-section { padding: 20px; }
        .match-table { width: 100%; border-collapse: collapse; margin-top: 10px; font-size: 13px; }
        .match-table th, .match-table td { padding: 10px 8px; border: 1px solid var(--gray-light); vertical-align: middle; }
        .match-table th { background: #f8fafc; font-weight: 600; font-size: 12px; }
        .match-pass { color: var(--success); font-weight: 700; }
        .match-fail { color: var(--danger); font-weight: 700; }
        .discrepancy-box { background: #fee2e2; padding: 10px 15px; border-radius: 10px; margin: 15px 0; border-left: 4px solid var(--danger); }
        .aging-badge { display: inline-block; padding: 2px 8px; border-radius: 12px; font-size: 10px; font-weight: 600; }
        .aging-overdue { background: #fee2e2; color: #dc2626; }
        .aging-warning { background: #fef3c7; color: #d97706; }
        .aging-normal { background: #d1fae5; color: #059669; }
        
        .search-box { display: flex; gap: 8px; align-items: center; }
        .search-box input { width: 140px; }
        .filter-dropdown { min-width: 130px; }
        
        .form-control { border-radius: 12px; border-color: var(--gray-light); padding: 10px 14px; }
        .form-control:focus { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(67,97,238,0.1); }
        .form-control-sm { padding: 6px 12px; font-size: 13px; }
        
        .modal-content { border-radius: 20px; border: none; }
        .modal-header { border-bottom: 1px solid var(--gray-light); padding: 20px 24px; }
        .modal-body { padding: 24px; }
        .modal-footer { border-top: 1px solid var(--gray-light); padding: 16px 24px; }
        
        .badge { display: inline-block; padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: 600; }
        
        .text-success { color: #059669 !important; }
        .text-danger { color: #dc2626 !important; }
        .text-warning { color: #d97706 !important; }
        
        @media (max-width: 576px) {
            .content-page { padding: 12px; }
            .welcome-header { padding: 16px; }
            .match-table { font-size: 11px; }
            .match-table th, .match-table td { padding: 6px 4px; }
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
                    <div class="col-md-7">
                        <div class="welcome-title">Accounts Payable & 3‑Way Matching 📄</div>
                        <div class="welcome-subtitle">Audit supplier invoices against purchase orders and goods receipts before settlement.</div>
                        <div class="small mt-2"><i class="fas fa-sync-alt"></i> Real‑time updates every 15 seconds.</div>
                    </div>
                    <div class="col-md-5 text-md-end mt-3 mt-md-0">
                        <div class="time-badge"><i class="far fa-calendar-alt"></i> <?php echo escapeHtml($current_date); ?> <i class="far fa-clock ms-2"></i> <?php echo escapeHtml($current_time); ?></div>
                    </div>
                </div>
            </div>

            <!-- HUD Metrics -->
            <div class="stats-grid" id="metricsContainer">
                <div class="stat-card primary">
                    <div><div class="stat-value" id="totalPayable">--</div><div class="stat-label">Total Payable (ETB)</div></div>
                </div>
                <div class="stat-card warning">
                    <div><div class="stat-value" id="discrepancyCount">--</div><div class="stat-label">Discrepancy Alerts</div></div>
                </div>
                <div class="stat-card success">
                    <div><div class="stat-value" id="verifiedCount">--</div><div class="stat-label">Verified Awaiting Settlement</div></div>
                </div>
                <div class="stat-card danger">
                    <div><div class="stat-value" id="overdueCount">--</div><div class="stat-label">Overdue (>30 days)</div></div>
                </div>
            </div>

            <div class="dashboard-grid">
                <!-- LEFT PANEL: Vendor Invoices Roster -->
                <div class="card-modern">
                    <div class="card-header-custom">
                        <h3><i class="fas fa-file-invoice me-2"></i> Outstanding Supplier Invoices</h3>
                        <div class="d-flex gap-2 align-items-center flex-wrap">
                            <div class="search-box">
                                <input type="text" id="invoiceSearch" class="form-control form-control-sm" placeholder="Search..." style="width:120px;">
                                <button class="btn btn-sm btn-outline" onclick="searchInvoices()"><i class="fas fa-search"></i></button>
                            </div>
                            <select id="filterDropdown" class="form-select form-select-sm filter-dropdown" onchange="refreshInvoiceList()">
                                <option value="all">All</option>
                                <option value="pending">Pending</option>
                                <option value="matched">Verified</option>
                                <option value="discrepancy">Discrepancy</option>
                            </select>
                            <button class="action-btn action-btn-outline action-btn-sm" onclick="refreshInvoiceList()"><i class="fas fa-sync-alt"></i></button>
                        </div>
                    </div>
                    <div class="invoice-list" id="invoiceListContainer">
                        <div class="empty-state">Loading invoices...</div>
                    </div>
                </div>

                <!-- RIGHT PANEL: 3-Way Verification Audit Desk -->
                <div class="card-modern">
                    <div class="card-header-custom">
                        <h3><i class="fas fa-search me-2"></i> 3‑Way Matching Audit Desk</h3>
                    </div>
                    <div id="auditWorkspace">
                        <div class="empty-state">
                            <i class="fas fa-hand-pointer fa-2x mb-2 text-muted"></i>
                            <p>Select an outstanding vendor invoice from the aging roster queue to run the programmatic 3‑Way Matching audit engine.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php include('assets/inc/footer.php'); ?>
</div>

<div id="loadingOverlay" class="loading-overlay"><div class="spinner"></div></div>
<input type="hidden" id="csrfToken" value="<?php echo escapeHtml($csrf_token); ?>">
<input type="hidden" id="isAdmin" value="<?php echo $is_global_admin ? '1' : '0'; ?>">
<input type="hidden" id="canSettle" value="<?php echo $can_settle ? '1' : '0'; ?>">

<!-- Dispute Modal -->
<div class="modal fade" id="disputeModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-exclamation-triangle text-danger"></i> Mark as Disputed</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form id="disputeForm">
                    <input type="hidden" name="csrf_token" id="disputeCsrf" value="<?php echo escapeHtml($csrf_token); ?>">
                    <input type="hidden" name="invoice_id" id="disputeInvoiceId">
                    <div class="mb-3">
                        <label>Dispute Reason <span class="text-danger">*</span></label>
                        <textarea class="form-control" id="disputeReason" rows="3" placeholder="Explain the reason for disputing this invoice..." required></textarea>
                    </div>
                    <div class="alert alert-warning">
                        <i class="fas fa-info-circle"></i> Marking as disputed will flag this invoice for review and prevent settlement until resolved.
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" onclick="submitDispute()"><i class="fas fa-flag"></i> Mark as Disputed</button>
            </div>
        </div>
    </div>
</div>

<script>
let currentInvoiceId = null;
let currentMatchStatus = null;
let currentInvoiceData = null;
let refreshTimer = null;
const canSettle = <?php echo $can_settle ? 'true' : 'false'; ?>;

function showToast(message, type = 'success') {
    const toast = document.createElement('div');
    toast.className = `toast-notification ${type === 'error' ? 'toast-error' : ''}`;
    toast.innerHTML = `<i class="fas fa-${type === 'error' ? 'exclamation-circle' : 'check-circle'}"></i> <span>${escapeHtml(message)}</span>`;
    document.body.appendChild(toast);
    setTimeout(() => toast.remove(), 4000);
}

function showLoading(show) {
    document.getElementById('loadingOverlay').classList.toggle('active', show);
}

function escapeHtml(str) {
    if (!str) return '';
    return String(str).replace(/[&<>]/g, m => m === '&' ? '&amp;' : (m === '<' ? '&lt;' : '&gt;'));
}

function formatMoney(amount) {
    return parseFloat(amount || 0).toFixed(2);
}

function getStatusBadge(status) {
    const classes = {
        'pending_matching': 'badge-pending',
        'matched_verified': 'badge-matched',
        'discrepancy': 'badge-discrepancy',
        'settled': 'badge-settled'
    };
    const labels = {
        'pending_matching': 'Pending',
        'matched_verified': 'Verified',
        'discrepancy': 'Discrepancy',
        'settled': 'Settled'
    };
    return `<span class="badge-status ${classes[status] || ''}">${labels[status] || status}</span>`;
}

function getAgingBadge(days) {
    if (days > 30) return `<span class="aging-badge aging-overdue">${days} days overdue</span>`;
    if (days > 15) return `<span class="aging-badge aging-warning">${days} days</span>`;
    return `<span class="aging-badge aging-normal">${days} days</span>`;
}

function refreshMetrics() {
    $.ajax({
        url: window.location.pathname + '?ajax=payable_action&action=fetch_metrics&csrf_token=' + encodeURIComponent($('#csrfToken').val()),
        method: 'GET',
        success: function(res) {
            if (res.success) {
                $('#totalPayable').text(formatMoney(res.total_payable));
                $('#discrepancyCount').text(res.discrepancy_count);
                $('#verifiedCount').text(res.verified_count);
                $('#overdueCount').text(res.overdue_count);
            }
        },
        error: function() { console.error('Failed to load metrics'); }
    });
}

function refreshInvoiceList() {
    const filter = $('#filterDropdown').val() || 'all';
    const search = $('#invoiceSearch').val() || '';
    
    showLoading(true);
    $.ajax({
        url: window.location.pathname + '?ajax=payable_action&action=fetch_invoices&filter=' + filter + '&search=' + encodeURIComponent(search) + '&csrf_token=' + encodeURIComponent($('#csrfToken').val()),
        method: 'GET',
        success: function(res) {
            showLoading(false);
            if (res.success && res.invoices) {
                const container = $('#invoiceListContainer');
                container.empty();
                if (res.invoices.length === 0) {
                    container.html('<div class="empty-state">No outstanding invoices.</div>');
                } else {
                    res.invoices.forEach(inv => {
                        const statusBadge = getStatusBadge(inv.status);
                        const aging = parseInt(inv.aging_days) || 0;
                        const agingBadge = getAgingBadge(aging);
                        
                        const row = $(`
                            <div class="invoice-item" data-invoice-id="${inv.id}">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <div class="fw-bold">${escapeHtml(inv.supplier_name)}</div>
                                        <div class="small text-muted">Invoice: ${escapeHtml(inv.invoice_number)} · PO: ${escapeHtml(inv.po_number)}</div>
                                        <div class="small">Received: ${inv.received_date} (${agingBadge})</div>
                                        ${inv.due_date ? `<div class="small text-muted">Due: ${inv.due_date}</div>` : ''}
                                    </div>
                                    <div class="text-end">
                                        <div class="fw-bold">${formatMoney(inv.invoice_amount)} ETB</div>
                                        ${statusBadge}
                                        ${inv.status === 'discrepancy' ? '<div class="small text-danger"><i class="fas fa-exclamation-triangle"></i></div>' : ''}
                                    </div>
                                </div>
                            </div>
                        `);
                        row.data('invoice-id', inv.id);
                        row.on('click', function() {
                            $('.invoice-item').removeClass('active');
                            $(this).addClass('active');
                            currentInvoiceId = inv.id;
                            run3WayMatch(inv.id);
                        });
                        container.append(row);
                    });
                }
            } else {
                showToast('Failed to load invoices: ' + (res.message || 'Unknown error'), 'error');
            }
        },
        error: function(xhr) {
            showLoading(false);
            let msg = 'Network error';
            if (xhr.responseJSON && xhr.responseJSON.message) msg = xhr.responseJSON.message;
            showToast(msg, 'error');
        }
    });
}

function searchInvoices() {
    refreshInvoiceList();
}

function run3WayMatch(invoiceId) {
    showLoading(true);
    $.ajax({
        url: window.location.pathname + '?ajax=payable_action&action=execute_3way_match&invoice_id=' + invoiceId + '&csrf_token=' + encodeURIComponent($('#csrfToken').val()),
        method: 'GET',
        success: function(res) {
            showLoading(false);
            if (res.success) {
                currentMatchStatus = res.match_status;
                currentInvoiceData = res;
                renderAuditWorkspace(res);
            } else {
                showToast(res.message || 'Matching failed', 'error');
                $('#auditWorkspace').html('<div class="empty-state">Failed to perform 3-way match.</div>');
            }
        },
        error: function() { showLoading(false); showToast('Server error', 'error'); }
    });
}

function renderAuditWorkspace(data) {
    const inv = data.invoice;
    const poTotal = data.po_total;
    const invoiceAmount = data.invoice_amount;
    const grnQty = data.grn_quantity;
    const poQty = data.po_quantity;
    const matchStatus = data.match_status;
    const discrepancyMsg = data.discrepancy_msg;
    const agingDays = data.aging_days || 0;

    const amountMatch = (Math.abs(poTotal - invoiceAmount) < 0.01);
    const qtyMatch = (poQty === null) || (Math.abs(poQty - grnQty) < 0.01);

    // Procurement items display
    let itemsHtml = '';
    if (data.procurement_items && data.procurement_items.length > 0) {
        itemsHtml = '<h6 class="mt-3 mb-2">Procurement Items</h6><div class="table-responsive"><table class="match-table"><thead><tr><th>Type</th><th>Quantity</th><th>Batch</th><th>Expiry</th><th>Price</th></tr></thead><tbody>';
        data.procurement_items.forEach(item => {
            itemsHtml += `
                <tr>
                    <td>${escapeHtml(item.item_type)}</td>
                    <td>${item.quantity}</td>
                    <td>${escapeHtml(item.batch_number || 'N/A')}</td>
                    <td>${item.expiry_date || 'N/A'}</td>
                    <td>${formatMoney(item.purchase_price)} ETB</td>
                </tr>
            `;
        });
        itemsHtml += '</tbody></table></div>';
    }

    const html = `
        <div class="detail-section">
            <div class="d-flex justify-content-between align-items-start mb-3">
                <div>
                    <h5 class="mb-0">${escapeHtml(inv.supplier_name)}</h5>
                    <div class="small text-muted">Invoice: ${escapeHtml(inv.invoice_number)} | PO: ${escapeHtml(inv.po_number)}</div>
                    ${inv.supplier_phone ? `<div class="small text-muted"><i class="fas fa-phone"></i> ${escapeHtml(inv.supplier_phone)}</div>` : ''}
                    ${inv.supplier_email ? `<div class="small text-muted"><i class="fas fa-envelope"></i> ${escapeHtml(inv.supplier_email)}</div>` : ''}
                </div>
                <div class="text-end">
                    <div>${getStatusBadge(matchStatus)}</div>
                    <div class="small ${agingDays > 30 ? 'text-danger' : 'text-muted'}">
                        ${agingDays} days in accounts payable
                    </div>
                </div>
            </div>
            
            <table class="match-table">
                <thead><tr><th>Parameter</th><th>Purchase Order (PO)</th><th>Goods Received (GRN)</th><th>Supplier Invoice</th><th>Match</th></tr></thead>
                <tbody>
                    <tr>
                        <td><strong>Total Amount (ETB)</strong></td>
                        <td>${formatMoney(poTotal)}</td>
                        <td>—</td>
                        <td>${formatMoney(invoiceAmount)}</td>
                        <td class="${amountMatch ? 'match-pass' : 'match-fail'}">${amountMatch ? '✓ Matched' : '✗ Mismatch'}</td>
                    </tr>
                    ${poQty !== null ? `
                    <tr>
                        <td><strong>Quantity (units)</strong></td>
                        <td>${poQty}</td>
                        <td>${grnQty}</td>
                        <td>—</td>
                        <td class="${qtyMatch ? 'match-pass' : 'match-fail'}">${qtyMatch ? '✓ Matched' : '✗ Mismatch'}</td>
                    </tr>
                    ` : ''}
                </tbody>
            </table>
            
            ${itemsHtml}
            
            ${matchStatus === 'discrepancy' && discrepancyMsg ? `
                <div class="discrepancy-box">
                    <i class="fas fa-exclamation-triangle me-2"></i> ${escapeHtml(discrepancyMsg)}
                </div>
            ` : ''}
            
            ${matchStatus === 'matched_verified' ? `
                <div class="alert alert-success mt-3">
                    <i class="fas fa-check-circle"></i> 3‑Way Match passed. The invoice is verified and ready for settlement.
                </div>
            ` : ''}
            
            <div class="mt-4 d-flex justify-content-end gap-2 flex-wrap">
                ${matchStatus === 'matched_verified' && canSettle ? `
                    <button class="action-btn action-btn-success" id="settleBtn">
                        <i class="fas fa-check"></i> Authorize Disbursement
                    </button>
                ` : ''}
                ${matchStatus !== 'settled' && canSettle ? `
                    <button class="action-btn action-btn-outline" id="disputeBtn">
                        <i class="fas fa-flag"></i> Mark as Disputed
                    </button>
                    <button class="action-btn action-btn-outline" id="deleteBtn">
                        <i class="fas fa-trash"></i> Delete
                    </button>
                ` : ''}
                <button class="action-btn action-btn-outline" onclick="refreshInvoiceList()">
                    <i class="fas fa-sync-alt"></i> Refresh
                </button>
            </div>
        </div>
    `;
    $('#auditWorkspace').html(html);
    
    if (matchStatus === 'matched_verified' && canSettle) {
        $('#settleBtn').on('click', function() {
            Swal.fire({
                title: 'Authorize Payment?',
                text: 'Are you sure you want to authorize payment for this supplier invoice? This will release funds and close the payable ledger.',
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Yes, Authorize',
                cancelButtonText: 'Cancel',
                confirmButtonColor: '#059669'
            }).then((result) => {
                if (result.isConfirmed) {
                    const csrf = $('#csrfToken').val();
                    showLoading(true);
                    $.ajax({
                        url: window.location.pathname,
                        method: 'POST',
                        data: {
                            action: 'approve_payable_settlement',
                            invoice_id: currentInvoiceId,
                            csrf_token: csrf
                        },
                        dataType: 'json',
                        success: function(res) {
                            showLoading(false);
                            if (res.success) {
                                showToast(res.message);
                                refreshInvoiceList();
                                refreshMetrics();
                                $('#auditWorkspace').html('<div class="empty-state">Settlement completed. The invoice has been closed.</div>');
                                currentInvoiceId = null;
                            } else {
                                showToast(res.message || 'Settlement failed', 'error');
                            }
                        },
                        error: function() { showLoading(false); showToast('Server error', 'error'); }
                    });
                }
            });
        });
    }
    
    if (canSettle) {
        $('#disputeBtn').on('click', function() {
            $('#disputeInvoiceId').val(currentInvoiceId);
            $('#disputeReason').val('');
            $('#disputeModal').modal('show');
        });
        
        $('#deleteBtn').on('click', function() {
            Swal.fire({
                title: 'Delete Invoice?',
                text: 'This will permanently delete the invoice record. This action cannot be undone.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Yes, Delete',
                cancelButtonText: 'Cancel',
                confirmButtonColor: '#dc2626'
            }).then((result) => {
                if (result.isConfirmed) {
                    const csrf = $('#csrfToken').val();
                    showLoading(true);
                    $.ajax({
                        url: window.location.pathname,
                        method: 'POST',
                        data: {
                            action: 'delete_invoice',
                            invoice_id: currentInvoiceId,
                            csrf_token: csrf
                        },
                        dataType: 'json',
                        success: function(res) {
                            showLoading(false);
                            if (res.success) {
                                showToast(res.message);
                                refreshInvoiceList();
                                refreshMetrics();
                                $('#auditWorkspace').html('<div class="empty-state">Invoice deleted.</div>');
                                currentInvoiceId = null;
                            } else {
                                showToast(res.message || 'Delete failed', 'error');
                            }
                        },
                        error: function() { showLoading(false); showToast('Server error', 'error'); }
                    });
                }
            });
        });
    }
}

function submitDispute() {
    const invoiceId = $('#disputeInvoiceId').val();
    const reason = $('#disputeReason').val().trim();
    const csrf = $('#disputeCsrf').val();
    
    if (!reason) {
        showToast('Please provide a reason for the dispute.', 'error');
        return;
    }
    
    showLoading(true);
    $.ajax({
        url: window.location.pathname,
        method: 'POST',
        data: {
            action: 'mark_disputed',
            invoice_id: invoiceId,
            dispute_reason: reason,
            csrf_token: csrf
        },
        dataType: 'json',
        success: function(res) {
            showLoading(false);
            if (res.success) {
                showToast(res.message);
                $('#disputeModal').modal('hide');
                refreshInvoiceList();
                refreshMetrics();
                if (currentInvoiceId) {
                    run3WayMatch(currentInvoiceId);
                }
            } else {
                showToast(res.message || 'Failed to mark as disputed', 'error');
            }
        },
        error: function() { showLoading(false); showToast('Server error', 'error'); }
    });
}

// Enter key on search
$('#invoiceSearch').on('keypress', function(e) {
    if (e.which === 13) {
        searchInvoices();
    }
});

// Initial load and auto‑refresh
refreshInvoiceList();
refreshMetrics();

if (refreshTimer) clearInterval(refreshTimer);
refreshTimer = setInterval(() => {
    refreshInvoiceList();
    refreshMetrics();
}, 15000);

$(document).ready(function() {
    // Auto-select first row if exists
    setTimeout(() => {
        const firstRow = $('.invoice-item:first');
        if (firstRow.length) {
            firstRow.click();
        }
    }, 1000);
});

// Sidebar toggle compatibility
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