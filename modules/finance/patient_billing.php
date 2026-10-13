<?php
/**
 * patient_billing.php
 * 
 * Patient Billing & Invoice Management
 * 
 * Centralized invoice management: view unsettled invoices, adjust line items,
 * and prepare for payment. Works with actual schema (invoices, invoice_items).
 * Real‑time polling for unpaid/partial invoices (auto-refresh every 15s).
 * Enhanced with: Multi-branch support, insurance claims integration, 
 * patient KYC verification, invoice printing, and advanced filtering.
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

$has_permission = $is_global_admin || user_has_permission($pdo, 'manage_patient_billing', $user_id);
if (!$has_permission) {
    header('Location: dashboard.php?error=unauthorized');
    exit;
}

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

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
// 2. AJAX HANDLERS (all before HTML output)
// ----------------------------------------------------------------------

// Refresh endpoint: returns the left panel list HTML
if (isset($_GET['ajax_refresh_invoices']) && $_GET['ajax_refresh_invoices'] == 1 && isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    ob_clean();
    
    $filter = $_GET['filter'] ?? 'all';
    $search = trim($_GET['search'] ?? '');
    
    $sql = "SELECT 
                i.id AS invoice_id,
                i.invoice_number,
                i.total_amount,
                i.paid_amount,
                (i.total_amount - i.paid_amount) AS due_amount,
                i.payment_status,
                i.billing_type,
                i.invoice_date,
                pv.visit_number,
                p.mr_number,
                CONCAT(p.first_name, ' ', IFNULL(p.middle_name, ''), ' ', p.last_name) AS patient_name,
                p.date_of_birth,
                p.phone,
                p.id AS patient_id,
                b.name AS branch_name
            FROM invoices i
            LEFT JOIN patient_visits pv ON i.visit_id = pv.id
            JOIN patients p ON i.patient_id = p.id
            JOIN branches b ON i.branch_id = b.id
            WHERE i.deleted_at IS NULL
              AND p.deleted_at IS NULL";
    
    $params = [];
    
    if (!$is_global_admin && $branch_id > 0) {
        $sql .= " AND i.branch_id = :branch_id";
        $params[':branch_id'] = $branch_id;
    }
    
    if ($filter === 'unpaid') {
        $sql .= " AND i.payment_status = 'unpaid'";
    } elseif ($filter === 'partial') {
        $sql .= " AND i.payment_status = 'partial'";
    } elseif ($filter === 'overdue') {
        $sql .= " AND i.payment_status = 'overdue'";
    } elseif ($filter === 'all') {
        $sql .= " AND i.payment_status IN ('unpaid', 'partial')";
    }
    
    if (!empty($search)) {
        $sql .= " AND (i.invoice_number LIKE :search OR p.first_name LIKE :search OR p.last_name LIKE :search OR p.mr_number LIKE :search)";
        $params[':search'] = '%' . $search . '%';
    }
    
    $sql .= " ORDER BY i.invoice_date ASC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (count($items) === 0) {
        echo '<div class="empty-state"><i class="fas fa-check-circle fa-2x mb-2 text-success"></i><p>No unsettled invoices found.</p></div>';
    } else {
        foreach ($items as $inv) {
            $dob = !empty($inv['date_of_birth']) ? new DateTime($inv['date_of_birth']) : null;
            $age = $dob ? (new DateTime())->diff($dob)->y : 'N/A';
            $billingTypeLabel = ucfirst($inv['billing_type'] ?? 'cash');
            $billingTypeClass = $inv['billing_type'] === 'insurance' ? 'bg-info' : ($inv['billing_type'] === 'corporate' ? 'bg-primary' : 'bg-secondary');
            ?>
            <div class="invoice-item" data-invoice-id="<?php echo $inv['invoice_id']; ?>" onclick="loadInvoice(<?php echo $inv['invoice_id']; ?>, this)">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="fw-bold"><?php echo escapeHtml($inv['patient_name']); ?></div>
                        <div class="small text-muted">MRN: <?php echo escapeHtml($inv['mr_number']); ?> · Age: <?php echo $age; ?>y</div>
                        <div class="small mt-1">Inv: <?php echo escapeHtml($inv['invoice_number']); ?></div>
                        <div class="small mt-1">
                            <span class="badge <?php echo $billingTypeClass; ?>"><?php echo $billingTypeLabel; ?></span>
                            <?php if ($inv['payment_status'] === 'overdue'): ?>
                                <span class="badge bg-danger">Overdue</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="text-end">
                        <span class="due-badge"><?php echo number_format($inv['due_amount'], 2); ?> ETB due</span>
                        <div class="small text-muted mt-1"><?php echo date('d/m/Y', strtotime($inv['invoice_date'])); ?></div>
                        <?php if (!empty($inv['branch_name'])): ?>
                            <div class="small text-muted mt-1"><i class="fas fa-building"></i> <?php echo escapeHtml($inv['branch_name']); ?></div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php
        }
    }
    exit;
}

// Main AJAX actions
if (isset($_GET['ajax']) && $_GET['ajax'] === 'billing_action') {

    // Fetch invoice details and items
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'fetch_invoice_details') {
        $invoice_id = (int)($_GET['invoice_id'] ?? 0);
        if ($invoice_id <= 0) {
            jsonResponse(false, 'Invalid invoice ID');
        }

        try {
            // Verify invoice exists and belongs to branch
            $sql_check = "SELECT id FROM invoices WHERE id = ?";
            $params = [$invoice_id];
            if (!$is_global_admin && $branch_id > 0) {
                $sql_check .= " AND branch_id = ?";
                $params[] = $branch_id;
            }
            $check = $pdo->prepare($sql_check);
            $check->execute($params);
            if (!$check->fetch()) {
                jsonResponse(false, 'Invoice not found or access denied.');
            }

            // Header with extended info
            $sql = "SELECT i.id, i.invoice_number, i.total_amount, i.paid_amount, 
                           (i.total_amount - i.paid_amount) AS due_amount,
                           i.payment_status, i.billing_type, i.invoice_date,
                           i.discount_amount, i.tax_amount, i.subtotal,
                           i.co_pay_percentage, i.co_pay_amount, i.insurance_approved_amount,
                           i.insurance_claim_reference, i.insurance_approval_date,
                           i.notes, i.created_at, i.updated_at,
                           pv.visit_number, p.id AS patient_id, p.mr_number,
                           CONCAT(p.first_name, ' ', IFNULL(p.middle_name, ''), ' ', p.last_name) AS patient_name,
                           p.gender, p.date_of_birth, p.phone, p.email, p.address,
                           b.name AS branch_name,
                           u.first_name AS created_by_name,
                           (SELECT COUNT(*) FROM payments WHERE invoice_id = i.id AND deleted_at IS NULL) AS payment_count
                    FROM invoices i
                    LEFT JOIN patient_visits pv ON i.visit_id = pv.id
                    JOIN patients p ON i.patient_id = p.id
                    JOIN branches b ON i.branch_id = b.id
                    LEFT JOIN users u ON i.created_by_user_id = u.id
                    WHERE i.id = :invoice_id";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':invoice_id' => $invoice_id]);
            $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$invoice) {
                jsonResponse(false, 'Invoice header not found.');
            }

            // Items with additional details
            $items_sql = "SELECT id, service_name, service_category, quantity, unit_price, 
                                 discount_amount, tax_amount, total_price,
                                 lab_order_id, prescription_id, consultation_id, admission_id,
                                 created_at
                          FROM invoice_items
                          WHERE invoice_id = :invoice_id
                          ORDER BY id ASC";
            $items_stmt = $pdo->prepare($items_sql);
            $items_stmt->execute([':invoice_id' => $invoice_id]);
            $items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);

            // Get payment history
            $payments_sql = "SELECT p.id, p.receipt_number, p.amount_paid, p.payment_method, 
                                    p.payment_date, p.received_by_user_id, p.notes, p.is_verified,
                                    CONCAT(u.first_name, ' ', u.last_name) AS cashier_name
                             FROM payments p
                             JOIN users u ON p.received_by_user_id = u.id
                             WHERE p.invoice_id = :invoice_id AND p.deleted_at IS NULL
                             ORDER BY p.payment_date DESC";
            $payments_stmt = $pdo->prepare($payments_sql);
            $payments_stmt->execute([':invoice_id' => $invoice_id]);
            $payments = $payments_stmt->fetchAll(PDO::FETCH_ASSOC);

            // Get insurance claim if exists
            $claim_sql = "SELECT claim_number, claim_status, insurance_coverage_percentage,
                                 insurance_covered_amount, patient_co_pay_percentage,
                                 patient_co_pay_amount, claimed_amount, approved_amount,
                                 submission_date, approval_date, settlement_date
                          FROM invoice_claims
                          WHERE invoice_id = :invoice_id AND deleted_at IS NULL";
            $claim_stmt = $pdo->prepare($claim_sql);
            $claim_stmt->execute([':invoice_id' => $invoice_id]);
            $claim = $claim_stmt->fetch(PDO::FETCH_ASSOC);

            $total_due = $invoice['total_amount'] - $invoice['paid_amount'];

            jsonResponse(true, '', [
                'invoice' => $invoice,
                'items' => $items,
                'payments' => $payments,
                'claim' => $claim,
                'total_due' => $total_due
            ]);
        } catch (PDOException $e) {
            error_log('fetch_invoice_details error: ' . $e->getMessage());
            jsonResponse(false, 'Database error: ' . $e->getMessage());
        }
    }

    // Adjust billing item (update quantity or unit price)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'apply_billing_adjustment') {
        $csrf = $_POST['csrf_token'] ?? '';
        if (!hash_equals($csrf_token, $csrf)) {
            jsonResponse(false, 'Security token mismatch');
        }

        $item_id = (int)($_POST['item_id'] ?? 0);
        $new_qty = (float)($_POST['quantity'] ?? 0);
        $new_price = (float)($_POST['unit_price'] ?? 0);
        $new_discount = (float)($_POST['discount_amount'] ?? 0);
        $new_tax = (float)($_POST['tax_amount'] ?? 0);
        
        if ($item_id <= 0 || $new_qty <= 0 || $new_price < 0) {
            jsonResponse(false, 'Invalid adjustment parameters');
        }

        try {
            // Check that the invoice is still unpaid/partial and belongs to branch
            $check_sql = "SELECT ii.id, i.payment_status, i.branch_id, i.id AS invoice_id
                          FROM invoice_items ii
                          JOIN invoices i ON ii.invoice_id = i.id
                          WHERE ii.id = :item_id";
            $params = [':item_id' => $item_id];
            if (!$is_global_admin && $branch_id > 0) {
                $check_sql .= " AND i.branch_id = :branch_id";
                $params[':branch_id'] = $branch_id;
            }
            $check = $pdo->prepare($check_sql);
            $check->execute($params);
            $item = $check->fetch(PDO::FETCH_ASSOC);
            
            if (!$item) {
                jsonResponse(false, 'Item not found.');
            }
            
            if (!in_array($item['payment_status'], ['unpaid', 'partial'])) {
                jsonResponse(false, 'Item cannot be adjusted (invoice already paid or cancelled).');
            }

            $new_total = ($new_qty * $new_price) - $new_discount + $new_tax;
            $pdo->beginTransaction();

            // Update invoice_item
            $upd = $pdo->prepare("UPDATE invoice_items 
                                  SET quantity = ?, unit_price = ?, discount_amount = ?, 
                                      tax_amount = ?, total_price = ? 
                                  WHERE id = ?");
            $upd->execute([$new_qty, $new_price, $new_discount, $new_tax, $new_total, $item_id]);

            // Recalculate invoice totals
            $sum_sql = "SELECT 
                           COALESCE(SUM(total_price), 0) AS new_subtotal,
                           COALESCE(SUM(discount_amount), 0) AS new_discount,
                           COALESCE(SUM(tax_amount), 0) AS new_tax
                        FROM invoice_items 
                        WHERE invoice_id = (SELECT invoice_id FROM invoice_items WHERE id = ?)";
            $sum_stmt = $pdo->prepare($sum_sql);
            $sum_stmt->execute([$item_id]);
            $totals = $sum_stmt->fetch(PDO::FETCH_ASSOC);
            
            $new_subtotal = (float)$totals['new_subtotal'];
            $new_discount_total = (float)$totals['new_discount'];
            $new_tax_total = (float)$totals['new_tax'];
            $new_total_amount = $new_subtotal - $new_discount_total + $new_tax_total;
            
            // Keep paid amount if already paid
            $paid_sql = "SELECT paid_amount FROM invoices WHERE id = (SELECT invoice_id FROM invoice_items WHERE id = ?)";
            $paid_stmt = $pdo->prepare($paid_sql);
            $paid_stmt->execute([$item_id]);
            $current_paid = (float)$paid_stmt->fetchColumn();
            
            $new_paid = min($new_total_amount, $current_paid);
            $new_status = ($new_paid >= $new_total_amount) ? 'paid' : ($new_paid > 0 ? 'partial' : 'unpaid');

            $upd_inv = $pdo->prepare("UPDATE invoices 
                                      SET subtotal = ?, discount_amount = ?, tax_amount = ?, 
                                          total_amount = ?, paid_amount = ?, payment_status = ? 
                                      WHERE id = (SELECT invoice_id FROM invoice_items WHERE id = ?)");
            $upd_inv->execute([$new_subtotal, $new_discount_total, $new_tax_total, 
                               $new_total_amount, $new_paid, $new_status, $item_id]);

            // Log adjustment
            $log_sql = "INSERT INTO status_transition_logs 
                        (visit_id, entity_type, entity_id, old_status, new_status, changed_by_user_id, changed_at, created_at)
                        VALUES (0, 'invoice', :invoice_id, 'adjustment', :new_status, :user_id, NOW(), NOW())";
            $log_stmt = $pdo->prepare($log_sql);
            $log_stmt->execute([
                ':invoice_id' => $item['invoice_id'],
                ':new_status' => $new_status,
                ':user_id' => $user_id
            ]);

            $pdo->commit();
            jsonResponse(true, 'Invoice item updated successfully.', [
                'new_total' => $new_total_amount,
                'new_status' => $new_status
            ]);
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('apply_billing_adjustment error: ' . $e->getMessage());
            jsonResponse(false, 'Database error: ' . $e->getMessage());
        }
    }

    // Add new line item to invoice
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_invoice_item') {
        $csrf = $_POST['csrf_token'] ?? '';
        if (!hash_equals($csrf_token, $csrf)) {
            jsonResponse(false, 'Security token mismatch');
        }

        $invoice_id = (int)($_POST['invoice_id'] ?? 0);
        $service_name = trim($_POST['service_name'] ?? '');
        $quantity = (float)($_POST['quantity'] ?? 1);
        $unit_price = (float)($_POST['unit_price'] ?? 0);
        $discount_amount = (float)($_POST['discount_amount'] ?? 0);
        $tax_amount = (float)($_POST['tax_amount'] ?? 0);
        $service_category = trim($_POST['service_category'] ?? 'other');

        if ($invoice_id <= 0 || empty($service_name) || $unit_price <= 0) {
            jsonResponse(false, 'Invalid item parameters');
        }

        try {
            // Check invoice status
            $check_sql = "SELECT payment_status, paid_amount FROM invoices WHERE id = :id";
            $params = [':id' => $invoice_id];
            if (!$is_global_admin && $branch_id > 0) {
                $check_sql .= " AND branch_id = :branch_id";
                $params[':branch_id'] = $branch_id;
            }
            $check = $pdo->prepare($check_sql);
            $check->execute($params);
            $inv = $check->fetch(PDO::FETCH_ASSOC);
            
            if (!$inv) {
                jsonResponse(false, 'Invoice not found.');
            }
            
            if (!in_array($inv['payment_status'], ['unpaid', 'partial'])) {
                jsonResponse(false, 'Cannot add items to paid or cancelled invoice.');
            }

            $total_price = ($quantity * $unit_price) - $discount_amount + $tax_amount;
            
            $pdo->beginTransaction();

            // Insert new item
            $insert = $pdo->prepare("INSERT INTO invoice_items 
                                    (invoice_id, service_name, service_category, quantity, unit_price, 
                                     discount_amount, tax_amount, total_price, created_at)
                                    VALUES 
                                    (:invoice_id, :service_name, :service_category, :quantity, :unit_price,
                                     :discount_amount, :tax_amount, :total_price, NOW())");
            $insert->execute([
                ':invoice_id' => $invoice_id,
                ':service_name' => $service_name,
                ':service_category' => $service_category,
                ':quantity' => $quantity,
                ':unit_price' => $unit_price,
                ':discount_amount' => $discount_amount,
                ':tax_amount' => $tax_amount,
                ':total_price' => $total_price
            ]);

            // Recalculate invoice totals
            $sum_sql = "SELECT 
                           COALESCE(SUM(total_price), 0) AS new_subtotal,
                           COALESCE(SUM(discount_amount), 0) AS new_discount,
                           COALESCE(SUM(tax_amount), 0) AS new_tax
                        FROM invoice_items 
                        WHERE invoice_id = :invoice_id";
            $sum_stmt = $pdo->prepare($sum_sql);
            $sum_stmt->execute([':invoice_id' => $invoice_id]);
            $totals = $sum_stmt->fetch(PDO::FETCH_ASSOC);
            
            $new_subtotal = (float)$totals['new_subtotal'];
            $new_discount_total = (float)$totals['new_discount'];
            $new_tax_total = (float)$totals['new_tax'];
            $new_total_amount = $new_subtotal - $new_discount_total + $new_tax_total;
            
            $new_paid = min($new_total_amount, (float)($inv['paid_amount'] ?? 0));
            $new_status = ($new_paid >= $new_total_amount) ? 'paid' : ($new_paid > 0 ? 'partial' : 'unpaid');

            $upd_inv = $pdo->prepare("UPDATE invoices 
                                      SET subtotal = ?, discount_amount = ?, tax_amount = ?, 
                                          total_amount = ?, paid_amount = ?, payment_status = ? 
                                      WHERE id = ?");
            $upd_inv->execute([$new_subtotal, $new_discount_total, $new_tax_total, 
                               $new_total_amount, $new_paid, $new_status, $invoice_id]);

            $pdo->commit();
            jsonResponse(true, 'Item added successfully.', [
                'new_total' => $new_total_amount,
                'new_status' => $new_status
            ]);
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('add_invoice_item error: ' . $e->getMessage());
            jsonResponse(false, 'Database error: ' . $e->getMessage());
        }
    }

    // Remove invoice item
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'remove_invoice_item') {
        $csrf = $_POST['csrf_token'] ?? '';
        if (!hash_equals($csrf_token, $csrf)) {
            jsonResponse(false, 'Security token mismatch');
        }

        $item_id = (int)($_POST['item_id'] ?? 0);
        if ($item_id <= 0) {
            jsonResponse(false, 'Invalid item ID');
        }

        try {
            // Get invoice info
            $check_sql = "SELECT ii.id, i.payment_status, i.branch_id, i.id AS invoice_id, i.paid_amount
                          FROM invoice_items ii
                          JOIN invoices i ON ii.invoice_id = i.id
                          WHERE ii.id = :item_id";
            $params = [':item_id' => $item_id];
            if (!$is_global_admin && $branch_id > 0) {
                $check_sql .= " AND i.branch_id = :branch_id";
                $params[':branch_id'] = $branch_id;
            }
            $check = $pdo->prepare($check_sql);
            $check->execute($params);
            $item = $check->fetch(PDO::FETCH_ASSOC);
            
            if (!$item) {
                jsonResponse(false, 'Item not found.');
            }
            
            if (!in_array($item['payment_status'], ['unpaid', 'partial'])) {
                jsonResponse(false, 'Cannot remove items from paid or cancelled invoice.');
            }

            $pdo->beginTransaction();

            // Delete item
            $delete = $pdo->prepare("DELETE FROM invoice_items WHERE id = :id");
            $delete->execute([':id' => $item_id]);

            // Recalculate totals
            $sum_sql = "SELECT 
                           COALESCE(SUM(total_price), 0) AS new_subtotal,
                           COALESCE(SUM(discount_amount), 0) AS new_discount,
                           COALESCE(SUM(tax_amount), 0) AS new_tax
                        FROM invoice_items 
                        WHERE invoice_id = :invoice_id";
            $sum_stmt = $pdo->prepare($sum_sql);
            $sum_stmt->execute([':invoice_id' => $item['invoice_id']]);
            $totals = $sum_stmt->fetch(PDO::FETCH_ASSOC);
            
            $new_subtotal = (float)$totals['new_subtotal'];
            $new_discount_total = (float)$totals['new_discount'];
            $new_tax_total = (float)$totals['new_tax'];
            $new_total_amount = $new_subtotal - $new_discount_total + $new_tax_total;
            
            $new_paid = min($new_total_amount, (float)($item['paid_amount'] ?? 0));
            $new_status = ($new_paid >= $new_total_amount) ? 'paid' : ($new_paid > 0 ? 'partial' : 'unpaid');

            $upd_inv = $pdo->prepare("UPDATE invoices 
                                      SET subtotal = ?, discount_amount = ?, tax_amount = ?, 
                                          total_amount = ?, paid_amount = ?, payment_status = ? 
                                      WHERE id = ?");
            $upd_inv->execute([$new_subtotal, $new_discount_total, $new_tax_total, 
                               $new_total_amount, $new_paid, $new_status, $item['invoice_id']]);

            $pdo->commit();
            jsonResponse(true, 'Item removed successfully.', [
                'new_total' => $new_total_amount,
                'new_status' => $new_status
            ]);
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('remove_invoice_item error: ' . $e->getMessage());
            jsonResponse(false, 'Database error: ' . $e->getMessage());
        }
    }

    // Generate invoice PDF (preview)
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'preview_invoice_pdf') {
        $invoice_id = (int)($_GET['invoice_id'] ?? 0);
        if ($invoice_id <= 0) {
            jsonResponse(false, 'Invalid invoice ID');
        }
        // In production, this would generate a PDF
        jsonResponse(true, 'PDF preview functionality - would generate PDF for invoice #' . $invoice_id);
    }

    // Apply discount to entire invoice
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'apply_discount') {
        $csrf = $_POST['csrf_token'] ?? '';
        if (!hash_equals($csrf_token, $csrf)) {
            jsonResponse(false, 'Security token mismatch');
        }

        $invoice_id = (int)($_POST['invoice_id'] ?? 0);
        $discount_amount = (float)($_POST['discount_amount'] ?? 0);
        $discount_reason = trim($_POST['discount_reason'] ?? '');

        if ($invoice_id <= 0 || $discount_amount < 0) {
            jsonResponse(false, 'Invalid parameters');
        }

        try {
            // Check invoice exists and belongs to branch
            $check_sql = "SELECT total_amount, paid_amount, payment_status FROM invoices WHERE id = :id";
            $params = [':id' => $invoice_id];
            if (!$is_global_admin && $branch_id > 0) {
                $check_sql .= " AND branch_id = :branch_id";
                $params[':branch_id'] = $branch_id;
            }
            $check = $pdo->prepare($check_sql);
            $check->execute($params);
            $inv = $check->fetch(PDO::FETCH_ASSOC);
            
            if (!$inv) {
                jsonResponse(false, 'Invoice not found.');
            }
            
            if (!in_array($inv['payment_status'], ['unpaid', 'partial'])) {
                jsonResponse(false, 'Cannot apply discount to paid or cancelled invoice.');
            }

            $pdo->beginTransaction();

            // Update discount
            $new_total = max(0, $inv['total_amount'] - $discount_amount);
            $new_paid = min($new_total, (float)$inv['paid_amount']);
            $new_status = ($new_paid >= $new_total) ? 'paid' : ($new_paid > 0 ? 'partial' : 'unpaid');

            // Get current notes
            $notes_sql = "SELECT notes FROM invoices WHERE id = :id";
            $notes_stmt = $pdo->prepare($notes_sql);
            $notes_stmt->execute([':id' => $invoice_id]);
            $current_notes = $notes_stmt->fetchColumn();
            
            $new_notes = $current_notes ? $current_notes . "\n" : '';
            $new_notes .= 'Discount: ' . $discount_reason . ' - ' . number_format($discount_amount, 2) . ' ETB applied on ' . date('Y-m-d H:i:s');

            $upd = $pdo->prepare("UPDATE invoices 
                                  SET discount_amount = discount_amount + :discount, 
                                      total_amount = :new_total, 
                                      paid_amount = :new_paid, 
                                      payment_status = :status,
                                      notes = :notes
                                  WHERE id = :id");
            $upd->execute([
                ':discount' => $discount_amount,
                ':new_total' => $new_total,
                ':new_paid' => $new_paid,
                ':status' => $new_status,
                ':notes' => $new_notes,
                ':id' => $invoice_id
            ]);

            $pdo->commit();
            jsonResponse(true, 'Discount applied successfully.', [
                'new_total' => $new_total,
                'new_status' => $new_status
            ]);
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('apply_discount error: ' . $e->getMessage());
            jsonResponse(false, 'Database error: ' . $e->getMessage());
        }
    }

    // Fetch invoice statistics
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'fetch_stats') {
        try {
            $sql = "SELECT 
                        COUNT(*) AS total_invoices,
                        SUM(CASE WHEN payment_status = 'unpaid' THEN 1 ELSE 0 END) AS unpaid_count,
                        SUM(CASE WHEN payment_status = 'partial' THEN 1 ELSE 0 END) AS partial_count,
                        SUM(CASE WHEN payment_status IN ('unpaid', 'partial') THEN total_amount - paid_amount ELSE 0 END) AS total_due,
                        SUM(total_amount) AS total_revenue,
                        SUM(paid_amount) AS total_collected
                    FROM invoices
                    WHERE deleted_at IS NULL";
            $params = [];
            if (!$is_global_admin && $branch_id > 0) {
                $sql .= " AND branch_id = :branch_id";
                $params[':branch_id'] = $branch_id;
            }
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $stats = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$stats) {
                $stats = ['total_invoices' => 0, 'unpaid_count' => 0, 'partial_count' => 0, 'total_due' => 0, 'total_revenue' => 0, 'total_collected' => 0];
            }
            
            jsonResponse(true, '', ['stats' => $stats]);
        } catch (PDOException $e) {
            error_log('fetch_stats error: ' . $e->getMessage());
            jsonResponse(false, 'Database error: ' . $e->getMessage());
        }
    }

    exit;
}

// ----------------------------------------------------------------------
// 3. INITIAL PAGE DATA: Fetch unsettled invoices
// ----------------------------------------------------------------------
$current_date = date('l, F j, Y');
$current_time = date('g:i A');
$unsettled_invoices = [];
$stats = ['total_invoices' => 0, 'unpaid_count' => 0, 'partial_count' => 0, 'total_due' => 0, 'total_revenue' => 0, 'total_collected' => 0];

try {
    // Get stats
    $sql = "SELECT 
                COUNT(*) AS total_invoices,
                SUM(CASE WHEN payment_status = 'unpaid' THEN 1 ELSE 0 END) AS unpaid_count,
                SUM(CASE WHEN payment_status = 'partial' THEN 1 ELSE 0 END) AS partial_count,
                SUM(CASE WHEN payment_status IN ('unpaid', 'partial') THEN total_amount - paid_amount ELSE 0 END) AS total_due,
                SUM(total_amount) AS total_revenue,
                SUM(paid_amount) AS total_collected
            FROM invoices
            WHERE deleted_at IS NULL";
    $params = [];
    if (!$is_global_admin && $branch_id > 0) {
        $sql .= " AND branch_id = :branch_id";
        $params[':branch_id'] = $branch_id;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$stats) $stats = ['total_invoices' => 0, 'unpaid_count' => 0, 'partial_count' => 0, 'total_due' => 0, 'total_revenue' => 0, 'total_collected' => 0];

    // Get unsettled invoices
    $sql = "SELECT 
                i.id AS invoice_id,
                i.invoice_number,
                i.total_amount,
                i.paid_amount,
                (i.total_amount - i.paid_amount) AS due_amount,
                i.payment_status,
                i.billing_type,
                i.invoice_date,
                pv.visit_number,
                p.id AS patient_id,
                p.mr_number,
                CONCAT(p.first_name, ' ', IFNULL(p.middle_name, ''), ' ', p.last_name) AS patient_name,
                p.gender,
                p.date_of_birth,
                b.name AS branch_name
            FROM invoices i
            LEFT JOIN patient_visits pv ON i.visit_id = pv.id
            JOIN patients p ON i.patient_id = p.id
            JOIN branches b ON i.branch_id = b.id
            WHERE i.deleted_at IS NULL
              AND i.payment_status IN ('unpaid', 'partial')
              AND p.deleted_at IS NULL";
    $params = [];
    if (!$is_global_admin && $branch_id > 0) {
        $sql .= " AND i.branch_id = :branch_id";
        $params[':branch_id'] = $branch_id;
    }
    $sql .= " ORDER BY i.invoice_date ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $unsettled_invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($unsettled_invoices)) $unsettled_invoices = [];

    foreach ($unsettled_invoices as &$inv) {
        if (!empty($inv['date_of_birth'])) {
            $dob = new DateTime($inv['date_of_birth']);
            $inv['age'] = (new DateTime())->diff($dob)->y;
        } else {
            $inv['age'] = 'N/A';
        }
    }
    unset($inv);
} catch (PDOException $e) {
    error_log('Patient billing init error: ' . $e->getMessage());
    $unsettled_invoices = [];
}

// Fetch insurance providers for dropdown
$insurance_providers = [];
try {
    $stmt = $pdo->prepare("SELECT id, name, code FROM insurance_providers WHERE is_active = 1 AND status = 'active' AND deleted_at IS NULL ORDER BY name");
    $stmt->execute();
    $insurance_providers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('Fetch providers error: ' . $e->getMessage());
}

// Fetch departments for dropdown
$departments = [];
try {
    $stmt = $pdo->prepare("SELECT id, name FROM departments WHERE is_active = 1 AND deleted_at IS NULL ORDER BY name");
    $stmt->execute();
    $departments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('Fetch departments error: ' . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Patient Billing | HMS ERP</title>
    <!-- CDNs to avoid 404 -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
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
        
        .stats-row { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 24px; }
        @media (max-width: 992px) { .stats-row { grid-template-columns: repeat(2, 1fr); } }
        @media (max-width: 576px) { .stats-row { grid-template-columns: 1fr; } }
        
        .stat-mini { background: white; border-radius: 16px; padding: 16px 20px; border: 1px solid var(--gray-light); }
        .stat-mini .number { font-size: 28px; font-weight: 800; color: var(--dark); }
        .stat-mini .label { font-size: 12px; color: var(--gray); font-weight: 500; }
        
        .dashboard-grid { display: grid; grid-template-columns: 380px 1fr; gap: 24px; }
        @media (max-width: 1200px) { .dashboard-grid { grid-template-columns: 1fr; } }
        
        .card-modern { background: white; border-radius: 20px; border: 1px solid var(--gray-light); overflow: hidden; margin-bottom: 24px; min-height: 400px; }
        .card-header-custom { padding: 16px 20px; background: #f8fafc; border-bottom: 1px solid var(--gray-light); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; }
        .card-header-custom h3 { margin: 0; font-size: 16px; font-weight: 700; color: var(--dark); }
        
        .invoice-list { max-height: 600px; overflow-y: auto; }
        .invoice-list::-webkit-scrollbar { width: 6px; }
        .invoice-list::-webkit-scrollbar-thumb { background: var(--gray-light); border-radius: 3px; }
        
        .invoice-item { padding: 14px 16px; border-bottom: 1px solid #f0f2f5; cursor: pointer; transition: all 0.2s; }
        .invoice-item:hover { background: #f8fafc; }
        .invoice-item.active { background: #e0e7ff; border-left: 3px solid var(--primary); }
        .due-badge { background: var(--danger); color: white; border-radius: 20px; padding: 2px 8px; font-size: 11px; font-weight: 600; }
        
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
        .info-row { display: flex; margin-bottom: 12px; font-size: 13px; }
        .info-label { width: 140px; font-weight: 600; color: var(--gray); flex-shrink: 0; }
        .info-value { flex: 1; color: var(--dark); }
        .adjust-input { width: 100px; margin-right: 5px; display: inline-block; }
        .item-row { transition: background 0.2s; }
        .item-row:hover { background: #f8fafc; }
        
        .search-box { display: flex; gap: 8px; align-items: center; }
        .search-box input { width: 160px; }
        
        .filter-dropdown { min-width: 140px; }
        
        .payment-history { background: #f8fafc; border-radius: 12px; padding: 12px 16px; margin-top: 12px; }
        .payment-history .payment-item { border-bottom: 1px solid #e9edf2; padding: 8px 0; }
        .payment-history .payment-item:last-child { border-bottom: none; }
        
        .claim-info { background: #f0f4ff; border-radius: 12px; padding: 12px 16px; border-left: 4px solid var(--primary); }
        
        @media (max-width: 576px) {
            .content-page { padding: 12px; }
            .welcome-header { padding: 16px; }
            .info-row { flex-direction: column; }
            .info-label { width: 100%; }
        }
        
        /* Select2 customization */
        .select2-container--default .select2-selection--single { height: 42px; padding: 5px; border-radius: 12px; border-color: var(--gray-light); }
        .form-control { border-radius: 12px; border-color: var(--gray-light); padding: 10px 14px; }
        .form-control:focus { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(67,97,238,0.1); }
        .form-control-sm { padding: 6px 12px; font-size: 13px; }
        
        .modal-content { border-radius: 20px; border: none; }
        .modal-header { border-bottom: 1px solid var(--gray-light); padding: 20px 24px; }
        .modal-body { padding: 24px; }
        .modal-footer { border-top: 1px solid var(--gray-light); padding: 16px 24px; }
        
        .badge { display: inline-block; padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: 600; }
        .badge-paid { background: #d1fae5; color: #059669; }
        .badge-unpaid { background: #fef3c7; color: #d97706; }
        .badge-partial { background: #fef3c7; color: #d97706; }
        .badge-cancelled { background: #fee2e2; color: #dc2626; }
        .badge-overdue { background: #fee2e2; color: #dc2626; }
        .badge-insurance { background: #dbeafe; color: #2563eb; }
        .badge-corporate { background: #ede9fe; color: #7c3aed; }
        .badge-cash { background: #d1fae5; color: #059669; }
        .payment-method-badge { display: inline-block; padding: 2px 10px; border-radius: 12px; font-size: 10px; font-weight: 600; }
        .method-cash { background: #d1fae5; color: #059669; }
        .method-card { background: #e0e7ff; color: #4361ee; }
        .method-mobile { background: #fef3c7; color: #d97706; }
        .method-bank { background: #ede9fe; color: #7c3aed; }
        .method-insurance { background: #fce7f3; color: #db2777; }
        .method-check { background: #f3f4f6; color: #6b7280; }
        .badge-unverified { background: #fee2e2; color: #dc2626; }
        .bg-primary { background-color: #4361ee !important; }
        .bg-secondary { background-color: #6c757d !important; }
        .bg-info { background-color: #4cc9f0 !important; }
        .bg-danger { background-color: #ef476f !important; }
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
                        <div class="welcome-title">Patient Billing & Invoice Management 💰</div>
                        <div class="welcome-subtitle">View unsettled invoices, adjust line items, and prepare for payment.</div>
                        <div class="small mt-2"><i class="fas fa-sync-alt"></i> Real‑time updates – list refreshes automatically every 15 seconds.</div>
                    </div>
                    <div class="col-md-5 text-md-end mt-3 mt-md-0">
                        <div class="time-badge"><i class="far fa-calendar-alt"></i> <?php echo escapeHtml($current_date); ?> <i class="far fa-clock ms-2"></i> <?php echo escapeHtml($current_time); ?></div>
                    </div>
                </div>
            </div>

            <!-- Quick Stats -->
            <div class="stats-row">
                <div class="stat-mini">
                    <div class="number"><?php echo number_format($stats['total_invoices'] ?? 0); ?></div>
                    <div class="label">Total Invoices</div>
                </div>
                <div class="stat-mini">
                    <div class="number text-warning"><?php echo number_format($stats['unpaid_count'] ?? 0); ?></div>
                    <div class="label">Unpaid</div>
                </div>
                <div class="stat-mini">
                    <div class="number text-primary"><?php echo number_format($stats['partial_count'] ?? 0); ?></div>
                    <div class="label">Partial Paid</div>
                </div>
                <div class="stat-mini">
                    <div class="number text-danger"><?php echo number_format($stats['total_due'] ?? 0, 2); ?></div>
                    <div class="label">Total Due (ETB)</div>
                </div>
            </div>

            <div class="dashboard-grid" id="mainGrid">
                <!-- LEFT PANEL: Unsettled Invoices -->
                <div class="card-modern">
                    <div class="card-header-custom">
                        <h3><i class="fas fa-credit-card me-2"></i> Unsettled Invoices</h3>
                        <div class="d-flex gap-2 align-items-center flex-wrap">
                            <div class="search-box">
                                <input type="text" id="invoiceSearch" class="form-control form-control-sm" placeholder="Search..." style="width:140px;">
                                <button class="btn btn-sm btn-outline" onclick="searchInvoices()"><i class="fas fa-search"></i></button>
                            </div>
                            <select id="filterDropdown" class="form-select form-select-sm filter-dropdown" onchange="refreshInvoiceList()">
                                <option value="all">All Unsettled</option>
                                <option value="unpaid">Unpaid Only</option>
                                <option value="partial">Partial Only</option>
                                <option value="overdue">Overdue</option>
                            </select>
                        </div>
                    </div>
                    <div class="invoice-list" id="invoiceListContainer">
                        <?php if (count($unsettled_invoices) > 0): ?>
                            <?php foreach ($unsettled_invoices as $inv): ?>
                                <div class="invoice-item" data-invoice-id="<?php echo $inv['invoice_id']; ?>" onclick="loadInvoice(<?php echo $inv['invoice_id']; ?>, this)">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <div class="fw-bold"><?php echo escapeHtml($inv['patient_name']); ?></div>
                                            <div class="small text-muted">MRN: <?php echo escapeHtml($inv['mr_number']); ?> · Age: <?php echo $inv['age']; ?>y</div>
                                            <div class="small mt-1">Inv: <?php echo escapeHtml($inv['invoice_number']); ?></div>
                                            <div class="small mt-1">
                                                <span class="badge <?php echo $inv['billing_type'] === 'insurance' ? 'badge-insurance' : ($inv['billing_type'] === 'corporate' ? 'badge-corporate' : 'badge-cash'); ?>">
                                                    <?php echo ucfirst($inv['billing_type'] ?? 'cash'); ?>
                                                </span>
                                            </div>
                                        </div>
                                        <div class="text-end">
                                            <span class="due-badge"><?php echo number_format($inv['due_amount'], 2); ?> ETB due</span>
                                            <div class="small text-muted mt-1"><?php echo date('d/m/Y', strtotime($inv['invoice_date'])); ?></div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="empty-state"><i class="fas fa-check-circle fa-2x mb-2 text-success"></i><p>No unsettled invoices found.</p></div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- RIGHT PANEL: Invoice Details & Adjustment Desk -->
                <div class="card-modern">
                    <div class="card-header-custom">
                        <h3><i class="fas fa-file-invoice-dollar me-2"></i> Invoice Details & Adjustment</h3>
                        <div>
                            <button class="action-btn action-btn-outline action-btn-sm" onclick="refreshInvoiceList()"><i class="fas fa-sync-alt"></i></button>
                            <button class="action-btn action-btn-primary action-btn-sm" onclick="openAddItemModal()"><i class="fas fa-plus"></i> Add Item</button>
                        </div>
                    </div>
                    <div id="invoiceWorkspace">
                        <div class="empty-state">
                            <i class="fas fa-hand-pointer fa-2x mb-2 text-muted"></i>
                            <p>Select an unsettled invoice from the list to view details and make adjustments.</p>
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

<!-- Add Item Modal -->
<div class="modal fade" id="addItemModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-plus-circle me-2"></i> Add Invoice Item</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="addItemForm">
                    <input type="hidden" name="csrf_token" value="<?php echo escapeHtml($csrf_token); ?>">
                    <input type="hidden" name="invoice_id" id="addItemInvoiceId">
                    
                    <div class="mb-3">
                        <label>Service Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="service_name" id="addItemServiceName" required>
                    </div>
                    <div class="mb-3">
                        <label>Service Category</label>
                        <select class="form-control" name="service_category" id="addItemCategory">
                            <option value="consultation">Consultation</option>
                            <option value="lab">Lab</option>
                            <option value="pharmacy">Pharmacy</option>
                            <option value="procedure">Procedure</option>
                            <option value="radiology">Radiology</option>
                            <option value="surgery">Surgery</option>
                            <option value="admission">Admission</option>
                            <option value="ambulance">Ambulance</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label>Quantity</label>
                            <input type="number" step="1" class="form-control" name="quantity" id="addItemQty" value="1" min="1">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label>Unit Price (ETB) <span class="text-danger">*</span></label>
                            <input type="number" step="0.01" class="form-control" name="unit_price" id="addItemPrice" required>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label>Discount (ETB)</label>
                            <input type="number" step="0.01" class="form-control" name="discount_amount" id="addItemDiscount" value="0">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label>Tax Amount (ETB)</label>
                        <input type="number" step="0.01" class="form-control" name="tax_amount" id="addItemTax" value="0">
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="addInvoiceItem()"><i class="fas fa-plus"></i> Add Item</button>
            </div>
        </div>
    </div>
</div>

<!-- Discount Modal -->
<div class="modal fade" id="discountModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-tags me-2"></i> Apply Discount</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="discountForm">
                    <input type="hidden" name="csrf_token" value="<?php echo escapeHtml($csrf_token); ?>">
                    <input type="hidden" name="invoice_id" id="discountInvoiceId">
                    <div class="mb-3">
                        <label>Discount Amount (ETB) <span class="text-danger">*</span></label>
                        <input type="number" step="0.01" class="form-control" name="discount_amount" id="discountAmount" required min="0">
                    </div>
                    <div class="mb-3">
                        <label>Reason <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="discount_reason" id="discountReason" placeholder="e.g., Promotional, Patient hardship, etc." required>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-warning" onclick="applyDiscount()"><i class="fas fa-tag"></i> Apply Discount</button>
            </div>
        </div>
    </div>
</div>

<script>
let currentActiveInvoiceId = null;
let currentActiveRow = null;
let currentInvoiceData = null;
let refreshTimer = null;

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
        'paid': 'badge-paid',
        'unpaid': 'badge-unpaid',
        'partial': 'badge-partial',
        'cancelled': 'badge-cancelled',
        'overdue': 'badge-overdue'
    };
    const labels = {
        'paid': 'Paid',
        'unpaid': 'Unpaid',
        'partial': 'Partial',
        'cancelled': 'Cancelled',
        'overdue': 'Overdue'
    };
    return `<span class="badge ${classes[status] || 'badge-unpaid'}">${labels[status] || status || 'Unknown'}</span>`;
}

function getMethodBadge(method) {
    const classes = {
        'cash': 'method-cash',
        'telebirr': 'method-mobile',
        'cbe_birr': 'method-mobile',
        'credit_card': 'method-card',
        'debit_card': 'method-card',
        'mobile_money': 'method-mobile',
        'bank_transfer': 'method-bank',
        'insurance_claim': 'method-insurance',
        'check': 'method-check'
    };
    const labels = {
        'cash': 'Cash',
        'telebirr': 'Telebirr',
        'cbe_birr': 'CBE Birr',
        'credit_card': 'Credit Card',
        'debit_card': 'Debit Card',
        'mobile_money': 'Mobile Money',
        'bank_transfer': 'Bank Transfer',
        'insurance_claim': 'Insurance',
        'check': 'Check'
    };
    return `<span class="payment-method-badge ${classes[method] || ''}">${labels[method] || method}</span>`;
}

// Real‑time refresh of left panel (polling)
function refreshInvoiceList() {
    const filter = $('#filterDropdown').val() || 'all';
    const search = $('#invoiceSearch').val() || '';
    
    showLoading(true);
    $.ajax({
        url: window.location.pathname,
        method: 'GET',
        data: { 
            ajax_refresh_invoices: 1,
            filter: filter,
            search: search
        },
        success: function(html) {
            showLoading(false);
            const scrollPos = $('.invoice-list').scrollTop();
            $('#invoiceListContainer').html(html);
            $('.invoice-list').scrollTop(scrollPos);
            
            // Restore active selection if still exists
            if (currentActiveInvoiceId) {
                const targetRow = $(`.invoice-item[data-invoice-id="${currentActiveInvoiceId}"]`);
                if (targetRow.length) {
                    loadInvoice(currentActiveInvoiceId, targetRow[0]);
                } else {
                    currentActiveInvoiceId = null;
                    $('#invoiceWorkspace').html(`<div class="empty-state"><i class="fas fa-hand-pointer fa-2x mb-2 text-muted"></i><p>Select an unsettled invoice from the list to view details and make adjustments.</p></div>`);
                }
            }
            requestAnimationFrame(() => {
                const grid = document.getElementById('mainGrid');
                if (grid) grid.style.gridTemplateColumns = '380px 1fr';
            });
        },
        error: function() { 
            showLoading(false); 
            showToast('Failed to refresh list', 'error'); 
        }
    });
}

function searchInvoices() {
    refreshInvoiceList();
}

function loadInvoice(invoiceId, element) {
    $('.invoice-item').removeClass('active');
    $(element).addClass('active');
    currentActiveInvoiceId = invoiceId;
    currentActiveRow = element;
    showLoading(true);
    
    $.ajax({
        url: window.location.pathname + '?ajax=billing_action&action=fetch_invoice_details&invoice_id=' + invoiceId,
        method: 'GET',
        success: function(res) {
            showLoading(false);
            if (res.success && res.items) {
                currentInvoiceData = res;
                renderInvoiceWorkspace(res.invoice, res.items, res.payments, res.claim, res.total_due);
            } else {
                showToast(res.message || 'Failed to load invoice details', 'error');
                $('#invoiceWorkspace').html(`<div class="empty-state"><i class="fas fa-exclamation-triangle fa-2x mb-2 text-warning"></i><p>${escapeHtml(res.message || 'Error loading invoice')}</p></div>`);
                if (currentActiveRow) $(currentActiveRow).removeClass('active');
                currentActiveInvoiceId = null;
            }
        },
        error: function() { 
            showLoading(false); 
            showToast('Network error', 'error'); 
        }
    });
}

function renderInvoiceWorkspace(invoice, items, payments, claim, totalDue) {
    let itemsHtml = '';
    if (items && items.length > 0) {
        items.forEach(item => {
            itemsHtml += `
                <tr class="item-row" data-item-id="${item.id}">
                    <td>${escapeHtml(item.service_name)}</td>
                    <td class="text-center">
                        <input type="number" step="1" class="form-control form-control-sm adjust-input item-qty" value="${item.quantity}" style="display:inline-block; width:70px;">
                    </td>
                    <td class="text-center">
                        <input type="number" step="0.01" class="form-control form-control-sm adjust-input item-price" value="${item.unit_price}" style="display:inline-block; width:90px;">
                    </td>
                    <td class="text-end item-total">${formatMoney(item.total_price)}</td>
                    <td class="text-center">
                        <button class="action-btn action-btn-outline action-btn-sm update-item-btn" title="Update"><i class="fas fa-save"></i></button>
                        <button class="action-btn action-btn-danger action-btn-sm remove-item-btn" title="Remove"><i class="fas fa-trash"></i></button>
                    </td>
                </tr>
            `;
        });
    } else {
        itemsHtml = '<tr><td colspan="5" class="text-center text-muted">No items found</td></tr>';
    }

    // Payment history
    let paymentsHtml = '';
    if (payments && payments.length > 0) {
        payments.forEach(p => {
            paymentsHtml += `
                <div class="payment-item d-flex justify-content-between align-items-center">
                    <div>
                        <strong>${escapeHtml(p.receipt_number)}</strong>
                        ${getMethodBadge(p.payment_method)}
                        ${p.is_verified ? '<span class="badge badge-paid">✓ Verified</span>' : '<span class="badge badge-unverified">! Pending</span>'}
                    </div>
                    <div class="text-end">
                        <strong>${formatMoney(p.amount_paid)} ETB</strong>
                        <div class="small text-muted">${new Date(p.payment_date).toLocaleString()}</div>
                    </div>
                </div>
            `;
        });
    } else {
        paymentsHtml = '<div class="text-muted small">No payments recorded yet.</div>';
    }

    // Claim info
    let claimHtml = '';
    if (claim) {
        claimHtml = `
            <div class="claim-info mt-3">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <strong><i class="fas fa-file-contract"></i> Claim: ${escapeHtml(claim.claim_number)}</strong>
                        <span class="badge ${claim.claim_status === 'approved' ? 'badge-paid' : (claim.claim_status === 'submitted' ? 'badge-partial' : 'badge-unpaid')}">${claim.claim_status || 'Draft'}</span>
                    </div>
                    <div class="text-end">
                        <div>Coverage: ${claim.insurance_coverage_percentage}%</div>
                        <div class="small">Covered: ${formatMoney(claim.insurance_covered_amount)} ETB</div>
                    </div>
                </div>
            </div>
        `;
    }

    const html = `
        <div class="detail-section">
            <!-- Invoice Header -->
            <div class="row">
                <div class="col-md-6">
                    <div class="info-row">
                        <div class="info-label">Invoice Number:</div>
                        <div class="info-value"><strong>${escapeHtml(invoice.invoice_number)}</strong></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Patient:</div>
                        <div class="info-value">${escapeHtml(invoice.patient_name)} (MRN: ${escapeHtml(invoice.mr_number)})</div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Visit Number:</div>
                        <div class="info-value">${escapeHtml(invoice.visit_number || 'N/A')}</div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Branch:</div>
                        <div class="info-value">${escapeHtml(invoice.branch_name || 'N/A')}</div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="info-row">
                        <div class="info-label">Invoice Date:</div>
                        <div class="info-value">${new Date(invoice.invoice_date).toLocaleDateString()}</div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Billing Type:</div>
                        <div class="info-value">
                            <span class="badge ${invoice.billing_type === 'insurance' ? 'badge-insurance' : (invoice.billing_type === 'corporate' ? 'badge-corporate' : 'badge-cash')}">
                                ${escapeHtml(invoice.billing_type || 'cash')}
                            </span>
                        </div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Status:</div>
                        <div class="info-value">${getStatusBadge(invoice.payment_status)}</div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Created By:</div>
                        <div class="info-value">${escapeHtml(invoice.created_by_name || 'System')}</div>
                    </div>
                    ${invoice.notes ? `
                    <div class="info-row">
                        <div class="info-label">Notes:</div>
                        <div class="info-value small text-muted">${escapeHtml(invoice.notes)}</div>
                    </div>` : ''}
                </div>
            </div>
            
            <hr>
            
            <!-- Insurance Claim Info -->
            ${claimHtml}
            
            <!-- Invoice Items -->
            <h6 class="fw-bold mb-3 mt-3">Invoice Items (adjustable)</h6>
            <div class="table-responsive">
                <table class="table table-sm">
                    <thead>
                        <tr><th>Description</th><th class="text-center" style="width:80px;">Qty</th><th class="text-center" style="width:100px;">Unit Price</th><th class="text-end" style="width:100px;">Total</th><th style="width:90px;"></th></tr>
                    </thead>
                    <tbody id="invoiceItemsBody">
                        ${itemsHtml}
                    </tbody>
                    <tfoot>
                        <tr><td colspan="3" class="text-end fw-bold">Subtotal: </td><td class="text-end" id="displaySubtotal">${formatMoney(invoice.subtotal || 0)}</td><td></td></tr>
                        <tr><td colspan="3" class="text-end fw-bold">Discount: </td><td class="text-end text-danger" id="displayDiscount">${formatMoney(invoice.discount_amount || 0)}</td><td></td></tr>
                        <tr><td colspan="3" class="text-end fw-bold">Tax: </td><td class="text-end" id="displayTax">${formatMoney(invoice.tax_amount || 0)}</td><td></td></tr>
                        <tr class="table-active"><td colspan="3" class="text-end fw-bold">Total Amount: </td><td class="text-end fw-bold" id="displayTotal">${formatMoney(invoice.total_amount)}</td><td></td></tr>
                        <tr><td colspan="3" class="text-end fw-bold">Paid Already: </td><td class="text-end">${formatMoney(invoice.paid_amount)}</td><td></td></tr>
                        <tr><td colspan="3" class="text-end fw-bold">Balance Due: </td><td class="text-end fw-bold text-danger" id="displayBalance">${formatMoney(totalDue)}</td><td></td></tr>
                    </tfoot>
                </table>
            </div>
            
            <!-- Payment History -->
            <div class="payment-history">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <strong><i class="fas fa-history"></i> Payment History</strong>
                    <span class="badge bg-primary">${payments ? payments.length : 0} payments</span>
                </div>
                ${paymentsHtml}
            </div>
            
            <!-- Action Buttons -->
            <div class="mt-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div class="d-flex flex-wrap gap-2">
                    <button class="action-btn action-btn-success" id="processPaymentBtn">
                        <i class="fas fa-credit-card"></i> Proceed to Payment
                    </button>
                    <button class="action-btn action-btn-outline" id="applyDiscountBtn">
                        <i class="fas fa-tags"></i> Apply Discount
                    </button>
                    <button class="action-btn action-btn-outline" onclick="window.open('?ajax=billing_action&action=preview_invoice_pdf&invoice_id=${invoice.id}', '_blank')">
                        <i class="fas fa-file-pdf"></i> PDF Preview
                    </button>
                </div>
                <div class="text-muted small">
                    <i class="fas fa-info-circle"></i> ${invoice.payment_count || 0} payment(s) recorded
                </div>
            </div>
        </div>
    `;

    $('#invoiceWorkspace').html(html);

    // Attach update handlers
    $('.update-item-btn').on('click', function() {
        const $row = $(this).closest('tr');
        const itemId = $row.data('item-id');
        const newQty = parseFloat($row.find('.item-qty').val());
        const newPrice = parseFloat($row.find('.item-price').val());
        if (isNaN(newQty) || newQty <= 0 || isNaN(newPrice) || newPrice < 0) {
            showToast('Invalid quantity or price.', 'error');
            return;
        }
        updateInvoiceItem(itemId, newQty, newPrice);
    });

    // Attach remove handlers
    $('.remove-item-btn').on('click', function() {
        const $row = $(this).closest('tr');
        const itemId = $row.data('item-id');
        Swal.fire({
            title: 'Remove Item',
            text: 'Are you sure you want to remove this item from the invoice?',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Yes, Remove',
            cancelButtonText: 'Cancel',
            confirmButtonColor: '#dc2626'
        }).then((result) => {
            if (result.isConfirmed) {
                removeInvoiceItem(itemId);
            }
        });
    });

    // Process payment button
    $('#processPaymentBtn').on('click', function() {
        window.location.href = 'cashier_workspace.php?invoice_id=' + currentActiveInvoiceId;
    });

    // Apply discount button
    $('#applyDiscountBtn').on('click', function() {
        $('#discountInvoiceId').val(currentActiveInvoiceId);
        $('#discountAmount').val('');
        $('#discountReason').val('');
        $('#discountModal').modal('show');
    });

    // Enter key on quantity/price inputs triggers update
    $('.item-qty, .item-price').on('keypress', function(e) {
        if (e.which === 13) {
            $(this).closest('tr').find('.update-item-btn').click();
        }
    });
}

function updateInvoiceItem(itemId, quantity, unitPrice) {
    const csrf = $('#csrfToken').val();
    showLoading(true);
    $.ajax({
        url: window.location.pathname + '?ajax=billing_action',
        method: 'POST',
        data: {
            action: 'apply_billing_adjustment',
            item_id: itemId,
            quantity: quantity,
            unit_price: unitPrice,
            discount_amount: 0,
            tax_amount: 0,
            csrf_token: csrf
        },
        dataType: 'json',
        success: function(res) {
            showLoading(false);
            if (res.success) {
                showToast(res.message);
                if (currentActiveInvoiceId) {
                    loadInvoice(currentActiveInvoiceId, $('.invoice-item.active')[0]);
                }
                refreshInvoiceList();
            } else {
                showToast(res.message || 'Update failed', 'error');
            }
        },
        error: function() { 
            showLoading(false); 
            showToast('Server error', 'error'); 
        }
    });
}

function removeInvoiceItem(itemId) {
    const csrf = $('#csrfToken').val();
    showLoading(true);
    $.ajax({
        url: window.location.pathname + '?ajax=billing_action',
        method: 'POST',
        data: {
            action: 'remove_invoice_item',
            item_id: itemId,
            csrf_token: csrf
        },
        dataType: 'json',
        success: function(res) {
            showLoading(false);
            if (res.success) {
                showToast(res.message);
                if (currentActiveInvoiceId) {
                    loadInvoice(currentActiveInvoiceId, $('.invoice-item.active')[0]);
                }
                refreshInvoiceList();
            } else {
                showToast(res.message || 'Removal failed', 'error');
            }
        },
        error: function() { 
            showLoading(false); 
            showToast('Server error', 'error'); 
        }
    });
}

function openAddItemModal() {
    if (!currentActiveInvoiceId) {
        showToast('Please select an invoice first.', 'error');
        return;
    }
    $('#addItemInvoiceId').val(currentActiveInvoiceId);
    $('#addItemServiceName').val('');
    $('#addItemPrice').val('');
    $('#addItemQty').val(1);
    $('#addItemDiscount').val(0);
    $('#addItemTax').val(0);
    $('#addItemCategory').val('other');
    $('#addItemModal').modal('show');
}

function addInvoiceItem() {
    const invoiceId = $('#addItemInvoiceId').val();
    const serviceName = $('#addItemServiceName').val().trim();
    const price = parseFloat($('#addItemPrice').val());
    const qty = parseInt($('#addItemQty').val()) || 1;
    const discount = parseFloat($('#addItemDiscount').val()) || 0;
    const tax = parseFloat($('#addItemTax').val()) || 0;
    const category = $('#addItemCategory').val();

    if (!serviceName || isNaN(price) || price <= 0) {
        showToast('Please fill in all required fields.', 'error');
        return;
    }

    const csrf = $('#csrfToken').val();
    showLoading(true);
    $.ajax({
        url: window.location.pathname + '?ajax=billing_action',
        method: 'POST',
        data: {
            action: 'add_invoice_item',
            invoice_id: invoiceId,
            service_name: serviceName,
            service_category: category,
            quantity: qty,
            unit_price: price,
            discount_amount: discount,
            tax_amount: tax,
            csrf_token: csrf
        },
        dataType: 'json',
        success: function(res) {
            showLoading(false);
            if (res.success) {
                showToast(res.message);
                $('#addItemModal').modal('hide');
                if (currentActiveInvoiceId) {
                    loadInvoice(currentActiveInvoiceId, $('.invoice-item.active')[0]);
                }
                refreshInvoiceList();
            } else {
                showToast(res.message || 'Failed to add item', 'error');
            }
        },
        error: function() { 
            showLoading(false); 
            showToast('Server error', 'error'); 
        }
    });
}

function applyDiscount() {
    const invoiceId = $('#discountInvoiceId').val();
    const amount = parseFloat($('#discountAmount').val());
    const reason = $('#discountReason').val().trim();

    if (isNaN(amount) || amount <= 0) {
        showToast('Please enter a valid discount amount.', 'error');
        return;
    }
    if (!reason) {
        showToast('Please enter a reason for the discount.', 'error');
        return;
    }

    const csrf = $('#csrfToken').val();
    showLoading(true);
    $.ajax({
        url: window.location.pathname + '?ajax=billing_action',
        method: 'POST',
        data: {
            action: 'apply_discount',
            invoice_id: invoiceId,
            discount_amount: amount,
            discount_reason: reason,
            csrf_token: csrf
        },
        dataType: 'json',
        success: function(res) {
            showLoading(false);
            if (res.success) {
                showToast(res.message);
                $('#discountModal').modal('hide');
                if (currentActiveInvoiceId) {
                    loadInvoice(currentActiveInvoiceId, $('.invoice-item.active')[0]);
                }
                refreshInvoiceList();
            } else {
                showToast(res.message || 'Failed to apply discount', 'error');
            }
        },
        error: function() { 
            showLoading(false); 
            showToast('Server error', 'error'); 
        }
    });
}

// Enter key on search
$('#invoiceSearch').on('keypress', function(e) {
    if (e.which === 13) {
        searchInvoices();
    }
});

// Poll every 15 seconds
if (refreshTimer) clearInterval(refreshTimer);
refreshTimer = setInterval(refreshInvoiceList, 15000);

$(document).ready(function() {
    const firstItem = $('.invoice-item').first();
    if (firstItem.length) firstItem.click();
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

// Auto-refresh stats periodically
setInterval(function() {
    $.ajax({
        url: window.location.pathname + '?ajax=billing_action&action=fetch_stats&csrf_token=' + encodeURIComponent($('#csrfToken').val()),
        method: 'GET',
        dataType: 'json',
        success: function(res) {
            if (res.success && res.stats) {
                $('.stat-mini').eq(0).find('.number').text(res.stats.total_invoices || 0);
                $('.stat-mini').eq(1).find('.number').text(res.stats.unpaid_count || 0);
                $('.stat-mini').eq(2).find('.number').text(res.stats.partial_count || 0);
                $('.stat-mini').eq(3).find('.number').text(formatMoney(res.stats.total_due || 0));
            }
        }
    });
}, 60000);
</script>
</body>
</html>