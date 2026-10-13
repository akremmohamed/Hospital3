<?php
/**
 * cashier_workspace.php
 * 
 * Cashier Desk & Point of Sale (POS) Receipting
 * 
 * Real‑time automatic refresh (polling every 15 seconds).
 * Works with actual database schema (no deleted_at on invoices/payments).
 * Enhanced with: Multi-branch support, insurance claims, partial payments,
 * receipt printing, payment history, and advanced filtering.
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

$has_permission = $is_global_admin || user_has_permission($pdo, 'collect_patient_payments', $user_id);
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
// 2. AJAX HANDLERS (all placed BEFORE any HTML output)
// ----------------------------------------------------------------------

// Refresh endpoint for the left panel (returns only the list HTML)
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
            $billingTypeClass = $inv['billing_type'] === 'insurance' ? 'badge-insurance' : ($inv['billing_type'] === 'corporate' ? 'badge-corporate' : 'badge-cash');
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
if (isset($_GET['ajax']) && $_GET['ajax'] === 'cashier_action') {
    
    // Fetch pending invoice details for a visit
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'fetch_pending_invoice') {
        $invoice_id = (int)($_GET['invoice_id'] ?? 0);
        if ($invoice_id <= 0) {
            jsonResponse(false, 'Invalid invoice ID');
        }
        
        try {
            // Verify invoice exists and belongs to branch
            $check_sql = "SELECT id FROM invoices WHERE id = :invoice_id";
            $params = [':invoice_id' => $invoice_id];
            if (!$is_global_admin && $branch_id > 0) {
                $check_sql .= " AND branch_id = :branch_id";
                $params[':branch_id'] = $branch_id;
            }
            $check_stmt = $pdo->prepare($check_sql);
            $check_stmt->execute($params);
            if (!$check_stmt->fetch()) {
                jsonResponse(false, 'Invoice not found or access denied.');
            }
            
            // Fetch invoice header with patient and visit details
            $sql = "SELECT i.id AS invoice_id, i.invoice_number, i.total_amount, i.paid_amount,
                           (i.total_amount - i.paid_amount) AS due_amount,
                           i.payment_status, i.billing_type, i.invoice_date,
                           i.discount_amount, i.tax_amount, i.subtotal,
                           i.co_pay_percentage, i.co_pay_amount, i.insurance_approved_amount,
                           i.insurance_claim_reference, i.insurance_approval_date,
                           i.notes,
                           pv.visit_number,
                           p.id AS patient_id, p.mr_number,
                           CONCAT(p.first_name, ' ', IFNULL(p.middle_name, ''), ' ', p.last_name) AS patient_name,
                           p.gender, p.date_of_birth, p.phone, p.email,
                           b.name AS branch_name
                    FROM invoices i
                    LEFT JOIN patient_visits pv ON i.visit_id = pv.id
                    JOIN patients p ON i.patient_id = p.id
                    JOIN branches b ON i.branch_id = b.id
                    WHERE i.id = :invoice_id";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':invoice_id' => $invoice_id]);
            $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$invoice) {
                jsonResponse(false, 'Invoice data not found.');
            }
            
            // Only allow payment if status is unpaid or partial
            if (!in_array($invoice['payment_status'], ['unpaid', 'partial'])) {
                jsonResponse(false, 'This invoice is already paid or cancelled.');
            }
            
            // Fetch invoice items
            $items_sql = "SELECT id, service_name, service_category, quantity, unit_price, 
                                 discount_amount, tax_amount, total_price
                          FROM invoice_items
                          WHERE invoice_id = :invoice_id
                          ORDER BY id ASC";
            $items_stmt = $pdo->prepare($items_sql);
            $items_stmt->execute([':invoice_id' => $invoice_id]);
            $items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Fetch payment history
            $payments_sql = "SELECT p.id, p.receipt_number, p.amount_paid, p.payment_method, 
                                    p.payment_date, p.received_by_user_id, p.notes, p.is_verified,
                                    CONCAT(u.first_name, ' ', u.last_name) AS cashier_name
                             FROM payments p
                             JOIN users u ON p.received_by_user_id = u.id
                             WHERE p.invoice_id = :invoice_id AND p.deleted_at IS NULL
                             ORDER BY p.payment_date DESC LIMIT 10";
            $payments_stmt = $pdo->prepare($payments_sql);
            $payments_stmt->execute([':invoice_id' => $invoice_id]);
            $payments = $payments_stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Fetch insurance claim if exists
            $claim_sql = "SELECT claim_number, claim_status, insurance_coverage_percentage,
                                 insurance_covered_amount, patient_co_pay_percentage,
                                 patient_co_pay_amount, claimed_amount, approved_amount
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
            error_log('Cashier fetch_pending_invoice error: ' . $e->getMessage());
            jsonResponse(false, 'Database error: ' . $e->getMessage());
        }
    }
    
    // Process settlement (payment)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'process_settlement') {
        $incoming_token = $_POST['csrf_token'] ?? '';
        if (!hash_equals($csrf_token, $incoming_token)) {
            jsonResponse(false, 'Security token mismatch');
        }
        
        $invoice_id = (int)($_POST['invoice_id'] ?? 0);
        $payment_method = $_POST['payment_method'] ?? '';
        $amount_paid = (float)($_POST['amount_paid'] ?? 0);
        $reference_number = trim($_POST['reference_number'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        $is_full_payment = (int)($_POST['is_full_payment'] ?? 0);
        
        if ($invoice_id <= 0 || !in_array($payment_method, ['cash', 'telebirr', 'cbe_birr', 'credit_card', 'debit_card', 'mobile_money', 'bank_transfer', 'insurance_claim', 'check']) || $amount_paid <= 0) {
            jsonResponse(false, 'Invalid transaction parameters');
        }
        
        if ($payment_method !== 'cash' && empty($reference_number)) {
            jsonResponse(false, 'Transaction reference number is required for this payment method.');
        }
        
        try {
            // Fetch invoice details
            $inv_sql = "SELECT i.id, i.invoice_number, i.total_amount, i.paid_amount, i.payment_status,
                               i.patient_id, i.visit_id, i.branch_id
                        FROM invoices i
                        WHERE i.id = :invoice_id";
            $params = [':invoice_id' => $invoice_id];
            if (!$is_global_admin && $branch_id > 0) {
                $inv_sql .= " AND i.branch_id = :branch_id";
                $params[':branch_id'] = $branch_id;
            }
            $inv_stmt = $pdo->prepare($inv_sql);
            $inv_stmt->execute($params);
            $invoice = $inv_stmt->fetch(PDO::FETCH_ASSOC);
            if (!$invoice) {
                jsonResponse(false, 'Invoice not found.');
            }
            
            $current_paid = (float)$invoice['paid_amount'];
            $total = (float)$invoice['total_amount'];
            $remaining = $total - $current_paid;
            
            if ($amount_paid > $remaining + 0.01) {
                jsonResponse(false, 'Amount paid exceeds remaining balance.');
            }
            
            $new_paid = $current_paid + $amount_paid;
            $new_status = ($new_paid >= $total - 0.01) ? 'paid' : 'partial';
            
            // Generate unique receipt number
            $receipt_number = 'RCP-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
            $unique = false;
            for ($i = 0; $i < 5; $i++) {
                $check = $pdo->prepare("SELECT id FROM payments WHERE receipt_number = ? AND deleted_at IS NULL");
                $check->execute([$receipt_number]);
                if (!$check->fetch()) {
                    $unique = true;
                    break;
                }
                $receipt_number = 'RCP-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
            }
            if (!$unique) {
                jsonResponse(false, 'Unable to generate unique receipt number. Please try again.');
            }
            
            $pdo->beginTransaction();
            
            // Insert payment record
            $insert_payment = "INSERT INTO payments 
                              (branch_id, invoice_id, patient_id, visit_id, receipt_number, 
                               amount_paid, payment_method, transaction_reference, 
                               received_by_user_id, notes, is_verified, created_at, updated_at)
                              VALUES 
                              (:branch_id, :invoice_id, :patient_id, :visit_id, :receipt_number, 
                               :amount_paid, :payment_method, :reference, 
                               :user_id, :notes, 1, NOW(), NOW())";
            $pay_stmt = $pdo->prepare($insert_payment);
            $pay_stmt->execute([
                ':branch_id' => $invoice['branch_id'],
                ':invoice_id' => $invoice_id,
                ':patient_id' => $invoice['patient_id'],
                ':visit_id' => $invoice['visit_id'],
                ':receipt_number' => $receipt_number,
                ':amount_paid' => $amount_paid,
                ':payment_method' => $payment_method,
                ':reference' => $reference_number ?: null,
                ':user_id' => $user_id,
                ':notes' => $notes
            ]);
            
            // Update invoice paid_amount and status
            $update_inv = "UPDATE invoices 
                           SET paid_amount = :new_paid, payment_status = :new_status, updated_at = NOW()
                           WHERE id = :invoice_id";
            $upd_stmt = $pdo->prepare($update_inv);
            $upd_stmt->execute([
                ':new_paid' => $new_paid,
                ':new_status' => $new_status,
                ':invoice_id' => $invoice_id
            ]);
            
            // Update visit billing status if visit exists
            if ($invoice['visit_id']) {
                $visit_status = $new_status === 'paid' ? 'paid' : 'partial';
                $upd_visit = $pdo->prepare("UPDATE patient_visits SET billing_status = :status WHERE id = :id");
                $upd_visit->execute([':status' => $visit_status, ':id' => $invoice['visit_id']]);
            }
            
            // Log status transition
            $log_sql = "INSERT INTO status_transition_logs 
                        (visit_id, entity_type, entity_id, old_status, new_status, changed_by_user_id, changed_at, created_at)
                        VALUES (:visit_id, 'invoice', :invoice_id, :old_status, :new_status, :user_id, NOW(), NOW())";
            $log_stmt = $pdo->prepare($log_sql);
            $log_stmt->execute([
                ':visit_id' => $invoice['visit_id'] ?: 0,
                ':invoice_id' => $invoice_id,
                ':old_status' => $invoice['payment_status'],
                ':new_status' => $new_status,
                ':user_id' => $user_id
            ]);
            
            $pdo->commit();
            
            // Prepare receipt data
            $receipt_data = [
                'receipt_number' => $receipt_number,
                'invoice_number' => $invoice['invoice_number'],
                'patient_name' => '',
                'payment_method' => $payment_method,
                'amount_paid' => $amount_paid,
                'reference' => $reference_number,
                'date' => date('Y-m-d H:i:s'),
                'new_balance' => $total - $new_paid
            ];
            
            // Fetch patient name
            $pat_sql = "SELECT CONCAT(first_name, ' ', IFNULL(middle_name, ''), ' ', last_name) AS full_name FROM patients WHERE id = ?";
            $pat_stmt = $pdo->prepare($pat_sql);
            $pat_stmt->execute([$invoice['patient_id']]);
            $patient = $pat_stmt->fetch(PDO::FETCH_ASSOC);
            $receipt_data['patient_name'] = $patient['full_name'] ?? 'Patient';
            
            // Fetch invoice items for receipt
            $item_sql = "SELECT service_name, quantity, unit_price, total_price FROM invoice_items WHERE invoice_id = ?";
            $item_stmt = $pdo->prepare($item_sql);
            $item_stmt->execute([$invoice_id]);
            $receipt_data['items'] = $item_stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Check if invoice is fully paid
            $receipt_data['is_fully_paid'] = ($new_status === 'paid');
            
            jsonResponse(true, 'Payment processed successfully.', ['receipt' => $receipt_data]);
        } catch (PDOException $e) {
            if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
            error_log('Payment settlement error: ' . $e->getMessage());
            jsonResponse(false, 'Database error: ' . $e->getMessage());
        }
    }
    
    // Void/refund payment
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'void_payment') {
        $incoming_token = $_POST['csrf_token'] ?? '';
        if (!hash_equals($csrf_token, $incoming_token)) {
            jsonResponse(false, 'Security token mismatch');
        }
        
        $payment_id = (int)($_POST['payment_id'] ?? 0);
        $reason = trim($_POST['reason'] ?? '');
        
        if ($payment_id <= 0) {
            jsonResponse(false, 'Invalid payment ID');
        }
        
        try {
            $pdo->beginTransaction();
            
            // Get payment details
            $pay_sql = "SELECT p.id, p.invoice_id, p.amount_paid, p.patient_id, p.visit_id, p.branch_id,
                               i.total_amount, i.paid_amount, i.payment_status
                        FROM payments p
                        JOIN invoices i ON p.invoice_id = i.id
                        WHERE p.id = :payment_id AND p.deleted_at IS NULL";
            $params = [':payment_id' => $payment_id];
            if (!$is_global_admin && $branch_id > 0) {
                $pay_sql .= " AND p.branch_id = :branch_id";
                $params[':branch_id'] = $branch_id;
            }
            $pay_stmt = $pdo->prepare($pay_sql);
            $pay_stmt->execute($params);
            $payment = $pay_stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$payment) {
                $pdo->rollBack();
                jsonResponse(false, 'Payment not found.');
            }
            
            // Create refund payment (negative amount)
            $refund_receipt = 'RFD-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
            $refund_sql = "INSERT INTO payments 
                          (branch_id, invoice_id, patient_id, visit_id, receipt_number, 
                           amount_paid, payment_method, received_by_user_id, notes, is_verified, created_at, updated_at)
                          VALUES 
                          (:branch_id, :invoice_id, :patient_id, :visit_id, :receipt_number, 
                           :amount_paid, :payment_method, :user_id, :notes, 1, NOW(), NOW())";
            $refund_stmt = $pdo->prepare($refund_sql);
            $refund_stmt->execute([
                ':branch_id' => $payment['branch_id'],
                ':invoice_id' => $payment['invoice_id'],
                ':patient_id' => $payment['patient_id'],
                ':visit_id' => $payment['visit_id'],
                ':receipt_number' => $refund_receipt,
                ':amount_paid' => -$payment['amount_paid'],
                ':payment_method' => $payment['payment_method'] . '_refund',
                ':user_id' => $user_id,
                ':notes' => 'Refund: ' . $reason
            ]);
            
            // Update invoice paid amount
            $new_paid = max(0, $payment['paid_amount'] - $payment['amount_paid']);
            $new_status = ($new_paid >= $payment['total_amount']) ? 'paid' : ($new_paid > 0 ? 'partial' : 'unpaid');
            
            $upd_inv = $pdo->prepare("UPDATE invoices 
                                      SET paid_amount = :paid_amount, payment_status = :status, updated_at = NOW()
                                      WHERE id = :invoice_id");
            $upd_inv->execute([
                ':paid_amount' => $new_paid,
                ':status' => $new_status,
                ':invoice_id' => $payment['invoice_id']
            ]);
            
            $pdo->commit();
            jsonResponse(true, 'Payment voided/refunded successfully.');
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('void_payment error: ' . $e->getMessage());
            jsonResponse(false, 'Database error: ' . $e->getMessage());
        }
    }
    
    // Fetch daily summary
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'fetch_daily_summary') {
        try {
            $today = date('Y-m-d');
            $sql = "SELECT 
                        COUNT(*) AS transaction_count,
                        COALESCE(SUM(amount_paid), 0) AS total_collected,
                        COALESCE(SUM(CASE WHEN payment_method = 'cash' THEN amount_paid END), 0) AS cash_total,
                        COALESCE(SUM(CASE WHEN payment_method IN ('telebirr', 'cbe_birr', 'mobile_money') THEN amount_paid END), 0) AS mobile_total,
                        COALESCE(SUM(CASE WHEN payment_method IN ('credit_card', 'debit_card') THEN amount_paid END), 0) AS card_total,
                        COALESCE(SUM(CASE WHEN payment_method = 'bank_transfer' THEN amount_paid END), 0) AS bank_total,
                        COALESCE(SUM(CASE WHEN payment_method = 'insurance_claim' THEN amount_paid END), 0) AS insurance_total
                    FROM payments
                    WHERE DATE(payment_date) = :today AND deleted_at IS NULL";
            $params = [':today' => $today];
            if (!$is_global_admin && $branch_id > 0) {
                $sql .= " AND branch_id = :branch_id";
                $params[':branch_id'] = $branch_id;
            }
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $summary = $stmt->fetch(PDO::FETCH_ASSOC);
            
            jsonResponse(true, '', ['summary' => $summary]);
        } catch (PDOException $e) {
            error_log('fetch_daily_summary error: ' . $e->getMessage());
            jsonResponse(false, 'Database error: ' . $e->getMessage());
        }
    }
    
    exit;
}

// ----------------------------------------------------------------------
// 3. INITIAL PAGE DATA: Fetch unsettled invoices (unpaid or partial)
// ----------------------------------------------------------------------
$current_date = date('l, F j, Y');
$current_time = date('g:i A');
$unsettled_invoices = [];
$daily_summary = ['transaction_count' => 0, 'total_collected' => 0, 'cash_total' => 0, 'mobile_total' => 0, 'card_total' => 0, 'bank_total' => 0, 'insurance_total' => 0];

try {
    // Get daily summary
    $today = date('Y-m-d');
    $sql = "SELECT 
                COUNT(*) AS transaction_count,
                COALESCE(SUM(amount_paid), 0) AS total_collected,
                COALESCE(SUM(CASE WHEN payment_method = 'cash' THEN amount_paid END), 0) AS cash_total,
                COALESCE(SUM(CASE WHEN payment_method IN ('telebirr', 'cbe_birr', 'mobile_money') THEN amount_paid END), 0) AS mobile_total,
                COALESCE(SUM(CASE WHEN payment_method IN ('credit_card', 'debit_card') THEN amount_paid END), 0) AS card_total,
                COALESCE(SUM(CASE WHEN payment_method = 'bank_transfer' THEN amount_paid END), 0) AS bank_total,
                COALESCE(SUM(CASE WHEN payment_method = 'insurance_claim' THEN amount_paid END), 0) AS insurance_total
            FROM payments
            WHERE DATE(payment_date) = :today AND deleted_at IS NULL";
    $params = [':today' => $today];
    if (!$is_global_admin && $branch_id > 0) {
        $sql .= " AND branch_id = :branch_id";
        $params[':branch_id'] = $branch_id;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $daily_summary = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$daily_summary) {
        $daily_summary = ['transaction_count' => 0, 'total_collected' => 0, 'cash_total' => 0, 'mobile_total' => 0, 'card_total' => 0, 'bank_total' => 0, 'insurance_total' => 0];
    }

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
    error_log('Cashier workspace init error: ' . $e->getMessage());
    $unsettled_invoices = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cashier Workspace | HMS ERP</title>
    <!-- Use CDNs to avoid 404 errors -->
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
        
        .stats-row { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 24px; }
        @media (max-width: 992px) { .stats-row { grid-template-columns: repeat(2, 1fr); } }
        @media (max-width: 576px) { .stats-row { grid-template-columns: 1fr; } }
        
        .stat-mini { background: white; border-radius: 16px; padding: 16px 20px; border: 1px solid var(--gray-light); }
        .stat-mini .number { font-size: 28px; font-weight: 800; color: var(--dark); }
        .stat-mini .label { font-size: 12px; color: var(--gray); font-weight: 500; }
        .stat-mini .sub { font-size: 11px; color: var(--gray); margin-top: 4px; }
        
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
        
        .payment-method-card { border: 2px solid var(--gray-light); border-radius: 16px; padding: 16px; cursor: pointer; transition: all 0.2s; text-align: center; }
        .payment-method-card:hover { border-color: var(--primary); background: #f8faff; }
        .payment-method-card.selected { border-color: var(--primary); background: #f0f4ff; }
        .payment-method-card i { font-size: 28px; margin-bottom: 6px; display: block; }
        .payment-method-card .method-label { font-size: 12px; font-weight: 500; }
        
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
        
        .search-box { display: flex; gap: 8px; align-items: center; }
        .search-box input { width: 140px; }
        .filter-dropdown { min-width: 140px; }
        
        /* Print styles for receipt */
        @media print {
            body * { visibility: hidden; }
            #receiptModal .modal-content, #receiptModal .modal-content * { visibility: visible; }
            #receiptModal { position: absolute; top: 0; left: 0; width: 100%; margin: 0; padding: 0; }
            .modal-backdrop { display: none; }
            .modal { position: relative; display: block; }
        }
        .receipt-container { font-family: monospace; padding: 20px; max-width: 320px; margin: 0 auto; }
        .receipt-header { text-align: center; margin-bottom: 20px; }
        .receipt-line { border-top: 1px dashed #000; margin: 10px 0; }
        .receipt-item { display: flex; justify-content: space-between; font-size: 12px; margin: 4px 0; }
        .receipt-total { font-size: 14px; font-weight: bold; }
        
        .badge { display: inline-block; padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: 600; }
        .badge-paid { background: #d1fae5; color: #059669; }
        .badge-unpaid { background: #fef3c7; color: #d97706; }
        .badge-partial { background: #fef3c7; color: #d97706; }
        .badge-cancelled { background: #fee2e2; color: #dc2626; }
        .badge-overdue { background: #fee2e2; color: #dc2626; }
        .badge-insurance { background: #dbeafe; color: #2563eb; }
        .badge-corporate { background: #ede9fe; color: #7c3aed; }
        .badge-cash { background: #d1fae5; color: #059669; }
        .badge-verified { background: #d1fae5; color: #059669; }
        
        .payment-method-badge { display: inline-block; padding: 2px 10px; border-radius: 12px; font-size: 10px; font-weight: 600; }
        .method-cash { background: #d1fae5; color: #059669; }
        .method-card { background: #e0e7ff; color: #4361ee; }
        .method-mobile { background: #fef3c7; color: #d97706; }
        .method-bank { background: #ede9fe; color: #7c3aed; }
        .method-insurance { background: #fce7f3; color: #db2777; }
        .method-check { background: #f3f4f6; color: #6b7280; }
        
        .payment-history { background: #f8fafc; border-radius: 12px; padding: 12px 16px; margin-top: 12px; }
        .payment-history .payment-item { border-bottom: 1px solid #e9edf2; padding: 6px 0; font-size: 12px; }
        .payment-history .payment-item:last-child { border-bottom: none; }
        
        @media (max-width: 576px) {
            .content-page { padding: 12px; }
            .welcome-header { padding: 16px; }
            .info-row { flex-direction: column; }
            .info-label { width: 100%; }
            .payment-method-card { padding: 10px; }
            .payment-method-card i { font-size: 22px; }
        }
        
        .form-control { border-radius: 12px; border-color: var(--gray-light); padding: 10px 14px; }
        .form-control:focus { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(67,97,238,0.1); }
        .form-control-sm { padding: 6px 12px; font-size: 13px; }
        
        .modal-content { border-radius: 20px; border: none; }
        .modal-header { border-bottom: 1px solid var(--gray-light); padding: 20px 24px; }
        .modal-body { padding: 24px; }
        .modal-footer { border-top: 1px solid var(--gray-light); padding: 16px 24px; }
        
        .text-success { color: #059669 !important; }
        .text-danger { color: #dc2626 !important; }
        .text-warning { color: #d97706 !important; }
        .bg-danger { background-color: #ef476f !important; }
        .bg-primary { background-color: #4361ee !important; }
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
                        <div class="welcome-title">Cashier Workspace & POS Receipting 💵</div>
                        <div class="welcome-subtitle">Process patient payments, accept multiple payment methods, and print official receipts.</div>
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
                    <div class="number"><?php echo number_format($daily_summary['total_collected'] ?? 0, 2); ?></div>
                    <div class="label">Today's Collections (ETB)</div>
                    <div class="sub"><?php echo $daily_summary['transaction_count'] ?? 0; ?> transactions</div>
                </div>
                <div class="stat-mini">
                    <div class="number text-success"><?php echo number_format($daily_summary['cash_total'] ?? 0, 2); ?></div>
                    <div class="label">Cash</div>
                </div>
                <div class="stat-mini">
                    <div class="number text-primary"><?php echo number_format($daily_summary['mobile_total'] ?? 0, 2); ?></div>
                    <div class="label">Digital/Mobile</div>
                </div>
                <div class="stat-mini">
                    <div class="number text-warning"><?php echo number_format($daily_summary['card_total'] ?? 0, 2); ?></div>
                    <div class="label">Card/Insurance</div>
                </div>
            </div>

            <div class="dashboard-grid" id="mainGrid">
                <!-- LEFT PANEL: Unsettled Invoices (auto‑refreshes) -->
                <div class="card-modern">
                    <div class="card-header-custom">
                        <h3><i class="fas fa-credit-card me-2"></i> Unsettled Invoices</h3>
                        <div class="d-flex gap-2 align-items-center flex-wrap">
                            <div class="search-box">
                                <input type="text" id="invoiceSearch" class="form-control form-control-sm" placeholder="Search..." style="width:120px;">
                                <button class="btn btn-sm btn-outline" onclick="searchInvoices()"><i class="fas fa-search"></i></button>
                            </div>
                            <select id="filterDropdown" class="form-select form-select-sm filter-dropdown" onchange="refreshInvoiceList()">
                                <option value="all">All</option>
                                <option value="unpaid">Unpaid</option>
                                <option value="partial">Partial</option>
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

                <!-- RIGHT PANEL: Payment Collection Terminal -->
                <div class="card-modern">
                    <div class="card-header-custom">
                        <h3><i class="fas fa-cash-register me-2"></i> Payment Terminal</h3>
                        <div>
                            <button class="action-btn action-btn-outline action-btn-sm" onclick="refreshInvoiceList()"><i class="fas fa-sync-alt"></i></button>
                        </div>
                    </div>
                    <div id="paymentTerminal">
                        <div class="empty-state">
                            <i class="fas fa-hand-pointer fa-2x mb-2 text-muted"></i>
                            <p>Select an unsettled invoice from the list to activate the point of sale payment terminal.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php include('assets/inc/footer.php'); ?>
</div>

<!-- Receipt Modal -->
<div class="modal fade" id="receiptModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-receipt"></i> Payment Receipt</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="receiptContent"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary" onclick="window.print();"><i class="fas fa-print"></i> Print Receipt</button>
            </div>
        </div>
    </div>
</div>

<!-- Void Payment Modal -->
<div class="modal fade" id="voidModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-times-circle text-danger"></i> Void/Refund Payment</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form id="voidForm">
                    <input type="hidden" name="csrf_token" id="voidCsrf" value="<?php echo escapeHtml($csrf_token); ?>">
                    <input type="hidden" name="payment_id" id="voidPaymentId">
                    <div class="mb-3">
                        <label>Reason for Void/Refund <span class="text-danger">*</span></label>
                        <textarea class="form-control" id="voidReason" rows="3" placeholder="Please explain why this payment is being voided/refunded..." required></textarea>
                    </div>
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle"></i> This action will reverse the payment and update the invoice balance. This cannot be undone.
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" onclick="voidPayment()"><i class="fas fa-times"></i> Void/Refund Payment</button>
            </div>
        </div>
    </div>
</div>

<div id="loadingOverlay" class="loading-overlay"><div class="spinner"></div></div>
<input type="hidden" id="csrfToken" value="<?php echo escapeHtml($csrf_token); ?>">
<input type="hidden" id="isAdmin" value="<?php echo $is_global_admin ? '1' : '0'; ?>">

<script>
let currentActiveInvoiceId = null;
let currentActiveRow = null;
let currentTotalDue = 0;
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

// Real‑time refresh: fetches updated invoice list from server
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
            
            if (currentActiveInvoiceId) {
                const targetRow = $(`.invoice-item[data-invoice-id="${currentActiveInvoiceId}"]`);
                if (targetRow.length) {
                    loadInvoice(currentActiveInvoiceId, targetRow[0]);
                } else {
                    currentActiveInvoiceId = null;
                    $('#paymentTerminal').html(`<div class="empty-state"><i class="fas fa-hand-pointer fa-2x mb-2 text-muted"></i><p>Select an unsettled invoice from the list to activate the point of sale payment terminal.</p></div>`);
                }
            }
            requestAnimationFrame(() => {
                const grid = document.getElementById('mainGrid');
                if (grid) {
                    grid.style.display = 'grid';
                    grid.style.gridTemplateColumns = '380px 1fr';
                }
            });
        },
        error: function(xhr, status, error) {
            showLoading(false);
            console.error('Refresh error:', status, error);
            showToast('Failed to refresh invoice list', 'error');
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
        url: window.location.pathname + '?ajax=cashier_action&action=fetch_pending_invoice&invoice_id=' + invoiceId,
        method: 'GET',
        success: function(res) {
            showLoading(false);
            if (res.success && res.items !== undefined) {
                currentInvoiceData = res;
                currentTotalDue = res.total_due;
                renderPaymentTerminal(res.invoice, res.items, res.payments, res.claim, res.total_due);
            } else {
                showToast(res.message || 'Failed to load invoice details', 'error');
                $('#paymentTerminal').html(`<div class="empty-state"><i class="fas fa-exclamation-triangle fa-2x mb-2 text-warning"></i><p>${escapeHtml(res.message || 'Error loading invoice')}</p></div>`);
                if (currentActiveRow) $(currentActiveRow).removeClass('active');
                currentActiveInvoiceId = null;
            }
        },
        error: function(xhr, status, error) {
            showLoading(false);
            console.error('AJAX error:', status, error);
            showToast('Network error: ' + error, 'error');
            $('#paymentTerminal').html(`<div class="empty-state"><i class="fas fa-exclamation-triangle fa-2x mb-2 text-warning"></i><p>Network error. Please check console.</p></div>`);
        }
    });
}

function renderPaymentTerminal(invoice, items, payments, claim, totalDue) {
    let itemsHtml = '';
    if (items && items.length > 0) {
        items.forEach(item => {
            itemsHtml += `
                <tr>
                    <td>${escapeHtml(item.service_name)}</td>
                    <td class="text-center">${item.quantity}</td>
                    <td class="text-end">${formatMoney(item.unit_price)}</td>
                    <td class="text-end">${formatMoney(item.total_price)}</td>
                </tr>
            `;
        });
    } else {
        itemsHtml = '<tr><td colspan="4" class="text-center text-muted">No items found for this invoice.</td></tr>';
    }
    
    // Payment history
    let paymentsHtml = '';
    if (payments && payments.length > 0) {
        paymentsHtml = `
            <div class="payment-history">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <strong><i class="fas fa-history"></i> Payment History</strong>
                    <span class="badge bg-primary">${payments.length} payments</span>
                </div>
        `;
        payments.forEach(p => {
            paymentsHtml += `
                <div class="payment-item d-flex justify-content-between align-items-center">
                    <div>
                        ${getMethodBadge(p.payment_method)}
                        <span class="small">${escapeHtml(p.receipt_number)}</span>
                        ${p.is_verified ? '<span class="badge badge-verified">✓</span>' : ''}
                    </div>
                    <div class="text-end">
                        <strong>${formatMoney(p.amount_paid)} ETB</strong>
                        <div class="small text-muted">${new Date(p.payment_date).toLocaleString()}</div>
                    </div>
                </div>
            `;
        });
        paymentsHtml += '</div>';
    } else {
        paymentsHtml = '<div class="payment-history"><div class="text-muted small">No payments recorded yet.</div></div>';
    }
    
    // Claim info
    let claimHtml = '';
    if (claim) {
        claimHtml = `
            <div class="alert alert-info py-2 mb-3" style="font-size:12px;">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <strong><i class="fas fa-file-contract"></i> Claim:</strong> ${escapeHtml(claim.claim_number)}
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
            <div class="info-row">
                <div class="info-label">Billing Type:</div>
                <div class="info-value">
                    <span class="badge ${invoice.billing_type === 'insurance' ? 'badge-insurance' : (invoice.billing_type === 'corporate' ? 'badge-corporate' : 'badge-cash')}">
                        ${escapeHtml(invoice.billing_type || 'cash')}
                    </span>
                    ${getStatusBadge(invoice.payment_status)}
                </div>
            </div>
            <div class="info-row">
                <div class="info-label">Invoice Date:</div>
                <div class="info-value">${new Date(invoice.invoice_date).toLocaleDateString()}</div>
            </div>
            ${invoice.notes ? `
            <div class="info-row">
                <div class="info-label">Notes:</div>
                <div class="info-value small text-muted">${escapeHtml(invoice.notes)}</div>
            </div>` : ''}
            
            <hr>
            
            ${claimHtml}
            
            <h6 class="fw-bold mb-3">Invoice Items</h6>
            <div class="table-responsive">
                <table class="table table-sm">
                    <thead>
                        <tr><th>Description</th><th class="text-center">Qty</th><th class="text-end">Unit Price</th><th class="text-end">Total</th></tr>
                    </thead>
                    <tbody>
                        ${itemsHtml}
                    </tbody>
                    <tfoot>
                        <tr><td colspan="3" class="text-end fw-bold">Subtotal:</td><td class="text-end">${formatMoney(invoice.subtotal || invoice.total_amount)}</td></tr>
                        ${invoice.discount_amount && invoice.discount_amount > 0 ? `<tr><td colspan="3" class="text-end fw-bold text-danger">Discount:</td><td class="text-end text-danger">-${formatMoney(invoice.discount_amount)}</td></tr>` : ''}
                        ${invoice.tax_amount && invoice.tax_amount > 0 ? `<tr><td colspan="3" class="text-end fw-bold">Tax:</td><td class="text-end">${formatMoney(invoice.tax_amount)}</td></tr>` : ''}
                        <tr class="table-active"><td colspan="3" class="text-end fw-bold">Total Amount:</td><td class="text-end fw-bold">${formatMoney(invoice.total_amount)}</td></tr>
                        <tr><td colspan="3" class="text-end fw-bold">Paid Already:</td><td class="text-end">${formatMoney(invoice.paid_amount)}</td></tr>
                        <tr><td colspan="3" class="text-end fw-bold text-danger">Balance Due:</td><td class="text-end fw-bold text-danger">${formatMoney(totalDue)}</td></tr>
                    </tfoot>
                </table>
            </div>
            
            ${paymentsHtml}
            
            <hr>
            <h6 class="fw-bold mb-3">Payment Method</h6>
            <div class="row g-2 mb-3">
                <div class="col-3">
                    <div class="payment-method-card" data-method="cash">
                        <i class="fas fa-money-bill-wave"></i>
                        <div class="method-label">Cash</div>
                    </div>
                </div>
                <div class="col-3">
                    <div class="payment-method-card" data-method="telebirr">
                        <i class="fas fa-mobile-alt"></i>
                        <div class="method-label">Telebirr</div>
                    </div>
                </div>
                <div class="col-3">
                    <div class="payment-method-card" data-method="cbe_birr">
                        <i class="fas fa-building-columns"></i>
                        <div class="method-label">CBE Birr</div>
                    </div>
                </div>
                <div class="col-3">
                    <div class="payment-method-card" data-method="mobile_money">
                        <i class="fas fa-wallet"></i>
                        <div class="method-label">Mobile Money</div>
                    </div>
                </div>
                <div class="col-3">
                    <div class="payment-method-card" data-method="credit_card">
                        <i class="fas fa-credit-card"></i>
                        <div class="method-label">Credit Card</div>
                    </div>
                </div>
                <div class="col-3">
                    <div class="payment-method-card" data-method="debit_card">
                        <i class="fas fa-bank"></i>
                        <div class="method-label">Debit Card</div>
                    </div>
                </div>
                <div class="col-3">
                    <div class="payment-method-card" data-method="bank_transfer">
                        <i class="fas fa-university"></i>
                        <div class="method-label">Bank Transfer</div>
                    </div>
                </div>
                <div class="col-3">
                    <div class="payment-method-card" data-method="insurance_claim">
                        <i class="fas fa-shield-alt"></i>
                        <div class="method-label">Insurance</div>
                    </div>
                </div>
                <div class="col-3">
                    <div class="payment-method-card" data-method="check">
                        <i class="fas fa-file-invoice"></i>
                        <div class="method-label">Check</div>
                    </div>
                </div>
            </div>
            <input type="hidden" id="selectedPaymentMethod" value="">
            
            <div class="mb-3">
                <label class="form-label">Amount Tendered / Paid</label>
                <div class="input-group">
                    <span class="input-group-text">ETB</span>
                    <input type="number" step="0.01" id="amountPaid" class="form-control" value="${totalDue.toFixed(2)}">
                </div>
                <div id="changeDue" class="small mt-1"></div>
            </div>
            
            <div id="referenceField" style="display: none;" class="mb-3">
                <label class="form-label">Transaction Reference Number</label>
                <input type="text" id="referenceNumber" class="form-control" placeholder="Transaction ID, Check Number, etc.">
            </div>
            
            <div class="mb-3">
                <label class="form-label">Notes (Optional)</label>
                <input type="text" id="paymentNotes" class="form-control" placeholder="Any additional notes about this payment">
            </div>
            
            <div class="mb-3 form-check">
                <input type="checkbox" class="form-check-input" id="fullPaymentCheck">
                <label class="form-check-label" for="fullPaymentCheck">
                    Mark as Full Payment (settles entire balance)
                </label>
            </div>
            
            <div class="d-flex justify-content-end gap-2">
                <button class="action-btn action-btn-outline" id="voidPaymentBtn" style="display:none;">
                    <i class="fas fa-times"></i> Void/Refund
                </button>
                <button class="action-btn action-btn-success" id="processPaymentBtn">
                    <i class="fas fa-check"></i> Process Payment & Print Receipt
                </button>
            </div>
        </div>
    `;
    
    $('#paymentTerminal').html(html);
    
    // Payment method selection
    $('.payment-method-card').on('click', function() {
        $('.payment-method-card').removeClass('selected');
        $(this).addClass('selected');
        const method = $(this).data('method');
        $('#selectedPaymentMethod').val(method);
        if (method !== 'cash') {
            $('#referenceField').show();
        } else {
            $('#referenceField').hide();
            $('#referenceNumber').val('');
        }
    });
    
    // Amount input change
    $('#amountPaid').on('input', function() { computeChange(); });
    
    // Full payment checkbox
    $('#fullPaymentCheck').on('change', function() {
        if ($(this).is(':checked')) {
            $('#amountPaid').val(totalDue.toFixed(2));
            computeChange();
        }
    });
    
    function computeChange() {
        const paid = parseFloat($('#amountPaid').val()) || 0;
        if (paid >= totalDue) {
            const change = paid - totalDue;
            $('#changeDue').html(`<span class="text-success">Change due: ${formatMoney(change)}</span>`);
        } else {
            $('#changeDue').html(`<span class="text-warning">Remaining balance: ${formatMoney(totalDue - paid)}</span>`);
        }
    }
    
    // Process payment
    $('#processPaymentBtn').on('click', function() {
        const selectedMethod = $('#selectedPaymentMethod').val();
        if (!selectedMethod) {
            showToast('Please select a payment method.', 'error');
            return;
        }
        const amountPaid = parseFloat($('#amountPaid').val()) || 0;
        if (amountPaid <= 0) {
            showToast('Please enter a valid amount.', 'error');
            return;
        }
        if (amountPaid < totalDue) {
            if (!confirm('Partial payment will leave a remaining balance of ' + formatMoney(totalDue - amountPaid) + ' ETB. Continue?')) return;
        }
        let reference = $('#referenceNumber').val();
        if (selectedMethod !== 'cash' && !reference) {
            showToast('Transaction reference number is required for this payment method.', 'error');
            return;
        }
        
        const isFullPayment = $('#fullPaymentCheck').is(':checked') ? 1 : 0;
        const csrf = $('#csrfToken').val();
        showLoading(true);
        $.ajax({
            url: window.location.pathname + '?ajax=cashier_action',
            method: 'POST',
            data: {
                action: 'process_settlement',
                invoice_id: currentActiveInvoiceId,
                payment_method: selectedMethod,
                amount_paid: amountPaid,
                reference_number: reference,
                notes: $('#paymentNotes').val() || '',
                is_full_payment: isFullPayment,
                csrf_token: csrf
            },
            dataType: 'json',
            success: function(res) {
                showLoading(false);
                if (res.success && res.receipt) {
                    showToast(res.message);
                    displayReceipt(res.receipt);
                    refreshInvoiceList();
                    $('#paymentTerminal').html(`<div class="empty-state"><i class="fas fa-check-circle fa-2x mb-2 text-success"></i><p>Payment completed. Receipt printed.<br>Select another invoice to continue.</p></div>`);
                    currentActiveInvoiceId = null;
                } else {
                    showToast(res.message || 'Payment processing failed', 'error');
                }
            },
            error: function(xhr, status, error) {
                showLoading(false);
                console.error('Payment error:', xhr.responseText);
                showToast('Server error: ' + error, 'error');
            }
        });
    });
    
    // Void payment button (for viewing previous payments)
    // This would be implemented for admin/supervisor role
    computeChange();
}

function displayReceipt(receipt) {
    let itemsHtml = '';
    if (receipt.items && receipt.items.length > 0) {
        receipt.items.forEach(item => {
            itemsHtml += `<div class="receipt-item"><span>${escapeHtml(item.service_name)} x ${item.quantity}</span><span>${formatMoney(item.total_price)}</span></div>`;
        });
    } else {
        itemsHtml = '<div class="receipt-item"><span>No items</span><span>0.00</span></div>';
    }
    
    const methodLabels = {
        'cash': 'Cash',
        'telebirr': 'Telebirr',
        'cbe_birr': 'CBE Birr',
        'credit_card': 'Credit Card',
        'debit_card': 'Debit Card',
        'mobile_money': 'Mobile Money',
        'bank_transfer': 'Bank Transfer',
        'insurance_claim': 'Insurance Claim',
        'check': 'Check'
    };
    const methodText = methodLabels[receipt.payment_method] || receipt.payment_method;
    
    const receiptHtml = `
        <div class="receipt-container">
            <div class="receipt-header">
                <strong>Central General Hospital</strong><br>
                <small>Bole Road, Addis Ababa</small><br>
                <small>Tel: +251-11-555-1000</small>
                <div class="receipt-line"></div>
                <strong>OFFICIAL PAYMENT RECEIPT</strong><br>
                <small>Receipt #: ${escapeHtml(receipt.receipt_number)}</small><br>
                <small>Invoice #: ${escapeHtml(receipt.invoice_number)}</small><br>
                <small>Date: ${escapeHtml(receipt.date)}</small>
                <div class="receipt-line"></div>
            </div>
            <div><strong>Patient:</strong> ${escapeHtml(receipt.patient_name)}</div>
            <div class="receipt-line"></div>
            ${itemsHtml}
            <div class="receipt-line"></div>
            <div class="receipt-item receipt-total"><strong>Total Paid:</strong><strong>${formatMoney(receipt.amount_paid)}</strong></div>
            <div class="receipt-item"><span>Payment Method:</span><span>${methodText}</span></div>
            ${receipt.reference ? `<div class="receipt-item"><span>Reference:</span><span>${escapeHtml(receipt.reference)}</span></div>` : ''}
            ${receipt.new_balance !== undefined ? `<div class="receipt-item"><span>Remaining Balance:</span><span>${formatMoney(receipt.new_balance)}</span></div>` : ''}
            ${receipt.is_fully_paid ? `<div class="receipt-item"><span>Status:</span><span class="text-success">FULLY PAID</span></div>` : ''}
            <div class="receipt-line"></div>
            <div class="text-center small">Thank you for choosing our hospital</div>
            <div class="text-center small">Cashier: <?php echo escapeHtml($_SESSION['first_name'] ?? 'Cashier'); ?></div>
        </div>
    `;
    $('#receiptContent').html(receiptHtml);
    const receiptModal = new bootstrap.Modal(document.getElementById('receiptModal'));
    receiptModal.show();
}

function voidPayment() {
    const paymentId = $('#voidPaymentId').val();
    const reason = $('#voidReason').val().trim();
    const csrf = $('#voidCsrf').val();
    
    if (!reason) {
        showToast('Please provide a reason for voiding this payment.', 'error');
        return;
    }
    
    Swal.fire({
        title: 'Confirm Void/Refund',
        text: 'Are you sure you want to void/refund this payment? This action cannot be undone.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Yes, Void/Refund',
        cancelButtonText: 'Cancel',
        confirmButtonColor: '#dc2626'
    }).then((result) => {
        if (result.isConfirmed) {
            showLoading(true);
            $.ajax({
                url: window.location.pathname + '?ajax=cashier_action',
                method: 'POST',
                data: {
                    action: 'void_payment',
                    payment_id: paymentId,
                    reason: reason,
                    csrf_token: csrf
                },
                dataType: 'json',
                success: function(res) {
                    showLoading(false);
                    if (res.success) {
                        showToast(res.message);
                        $('#voidModal').modal('hide');
                        refreshInvoiceList();
                    } else {
                        showToast(res.message || 'Void failed', 'error');
                    }
                },
                error: function() {
                    showLoading(false);
                    showToast('Server error', 'error');
                }
            });
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
    if (firstItem.length) {
        firstItem.click();
    }
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

// Auto-refresh daily stats
setInterval(function() {
    $.ajax({
        url: window.location.pathname + '?ajax=cashier_action&action=fetch_daily_summary&csrf_token=' + encodeURIComponent($('#csrfToken').val()),
        method: 'GET',
        dataType: 'json',
        success: function(res) {
            if (res.success && res.summary) {
                $('.stat-mini').eq(0).find('.number').text(formatMoney(res.summary.total_collected || 0));
                $('.stat-mini').eq(0).find('.sub').text((res.summary.transaction_count || 0) + ' transactions');
                $('.stat-mini').eq(1).find('.number').text(formatMoney(res.summary.cash_total || 0));
                $('.stat-mini').eq(2).find('.number').text(formatMoney((res.summary.mobile_total || 0) + (res.summary.bank_total || 0)));
                $('.stat-mini').eq(3).find('.number').text(formatMoney((res.summary.card_total || 0) + (res.summary.insurance_total || 0)));
            }
        }
    });
}, 60000);
</script>
</body>
</html>