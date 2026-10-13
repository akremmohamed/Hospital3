<?php

session_start();

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
$user_name = $_SESSION['first_name'] ?? $_SESSION['username'] ?? 'Cashier';

if ($user_id <= 0) {
    header('Location: ../../index.php');
    exit;
}

$has_permission = $is_global_admin || user_has_permission($pdo, 'cashier_desk_access', $user_id);
if (!$has_permission) {
    header('Location: dashboard.php?error=unauthorized');
    exit;
}

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// ============================================
// HELPER FUNCTIONS
// ============================================
function jsonResponse($success, $message, $data = null, $code = 200) {
    if (ob_get_length()) ob_clean();
    http_response_code($code);
    header('Content-Type: application/json');
    header('X-Content-Type-Options: nosniff');
    $response = ['success' => $success, 'message' => $message];
    if ($data !== null) $response = array_merge($response, $data);
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

function escapeHtml($str) {
    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}

function formatMoney($amount) {
    return number_format((float)$amount, 2, '.', ',');
}

function generateReceiptNumber($branch_id) {
    return 'RCP-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
}

function generateInvoiceNumber() {
    return 'INV-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
}

function validatePaymentMethod($method) {
    $valid = ['cash', 'telebirr', 'cbe_birr', 'bank_transfer', 'insurance_claim', 'credit_card', 'debit_card', 'mobile_money', 'check'];
    return in_array($method, $valid) ? $method : 'cash';
}

function applyBranchFilter(&$sql, &$params, $branch_id, $is_global_admin, $alias) {
    if (!$is_global_admin && $branch_id > 0) {
        $sql .= " AND {$alias}.branch_id = :branch_id";
        $params[':branch_id'] = $branch_id;
    }
}

// ============================================
// AJAX HANDLERS
// ============================================
$is_ajax = isset($_GET['ajax']) && $_GET['ajax'] === 'cashier_action';

if ($is_ajax) {
    $incoming_token = $_GET['csrf_token'] ?? '';
    if (!hash_equals($csrf_token, $incoming_token)) {
        jsonResponse(false, 'Security token mismatch');
    }
    $action = $_GET['action'] ?? '';

    // ---------- METRICS ----------
    if ($action === 'fetch_metrics') {
        try {
            $today = date('Y-m-d');
            $params = [];

            // Today's collections - using correct column names from payments table
            $sql = "SELECT 
                        COUNT(*) AS transaction_count,
                        COALESCE(SUM(amount_paid), 0) AS total_collected,
                        COALESCE(SUM(CASE WHEN payment_method = 'cash' THEN amount_paid END), 0) AS cash_collected,
                        COALESCE(SUM(CASE WHEN payment_method = 'telebirr' THEN amount_paid END), 0) AS telebirr_collected,
                        COALESCE(SUM(CASE WHEN payment_method = 'cbe_birr' THEN amount_paid END), 0) AS cbe_birr_collected,
                        COALESCE(SUM(CASE WHEN payment_method IN ('credit_card', 'debit_card') THEN amount_paid END), 0) AS card_collected,
                        COALESCE(SUM(CASE WHEN payment_method = 'insurance_claim' THEN amount_paid END), 0) AS insurance_collected,
                        COALESCE(SUM(CASE WHEN payment_method = 'mobile_money' THEN amount_paid END), 0) AS mobile_collected,
                        COALESCE(SUM(CASE WHEN payment_method = 'bank_transfer' THEN amount_paid END), 0) AS bank_transfer_collected,
                        COALESCE(SUM(CASE WHEN payment_method = 'check' THEN amount_paid END), 0) AS check_collected
                    FROM payments
                    WHERE DATE(payment_date) = :today AND deleted_at IS NULL";
            $params[':today'] = $today;
            applyBranchFilter($sql, $params, $branch_id, $is_global_admin, 'payments');
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $today_metrics = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

            // Pending invoices
            $sql2 = "SELECT 
                        COUNT(*) AS pending_count,
                        COALESCE(SUM(total_amount - paid_amount), 0) AS pending_amount
                    FROM invoices
                    WHERE payment_status IN ('unpaid', 'partial') AND deleted_at IS NULL";
            $params2 = [];
            applyBranchFilter($sql2, $params2, $branch_id, $is_global_admin, 'invoices');
            $stmt2 = $pdo->prepare($sql2);
            $stmt2->execute($params2);
            $pending = $stmt2->fetch(PDO::FETCH_ASSOC) ?: ['pending_count' => 0, 'pending_amount' => 0];

            // Today's expenses
            $sql3 = "SELECT COALESCE(SUM(amount), 0) AS expenses FROM expense_registry WHERE DATE(expense_date) = :today AND deleted_at IS NULL";
            $params3 = [':today' => $today];
            applyBranchFilter($sql3, $params3, $branch_id, $is_global_admin, 'expense_registry');
            $stmt3 = $pdo->prepare($sql3);
            $stmt3->execute($params3);
            $expenses = (float)$stmt3->fetchColumn();

            // Pending approvals
            $approvals = ['total' => 0, 'lab' => 0, 'radiology' => 0, 'prescription' => 0, 'procedure' => 0, 'insurance' => 0, 'expense' => 0, 'management' => 0, 'unverified_payments' => 0];

            $sql4 = "SELECT COUNT(*) FROM lab_orders WHERE payment_status IN ('unpaid', 'partial') AND status = 'pending_payment' AND deleted_at IS NULL";
            $p4 = [];
            applyBranchFilter($sql4, $p4, $branch_id, $is_global_admin, 'lab_orders');
            $stmt4 = $pdo->prepare($sql4);
            $stmt4->execute($p4);
            $approvals['lab'] = (int)$stmt4->fetchColumn();

            $sql5 = "SELECT COUNT(*) FROM radiology_orders WHERE payment_status IN ('unpaid', 'partial') AND status = 'pending_payment' AND deleted_at IS NULL";
            $p5 = [];
            applyBranchFilter($sql5, $p5, $branch_id, $is_global_admin, 'radiology_orders');
            $stmt5 = $pdo->prepare($sql5);
            $stmt5->execute($p5);
            $approvals['radiology'] = (int)$stmt5->fetchColumn();

            $sql6 = "SELECT COUNT(*) FROM prescriptions WHERE payment_status IN ('unpaid', 'partial') AND status = 'pending_payment' AND deleted_at IS NULL";
            $p6 = [];
            applyBranchFilter($sql6, $p6, $branch_id, $is_global_admin, 'prescriptions');
            $stmt6 = $pdo->prepare($sql6);
            $stmt6->execute($p6);
            $approvals['prescription'] = (int)$stmt6->fetchColumn();

            $sql7 = "SELECT COUNT(*) FROM procedure_orders WHERE payment_status IN ('unpaid', 'partial') AND execution_status = 'pending_payment' AND deleted_at IS NULL";
            $p7 = [];
            applyBranchFilter($sql7, $p7, $branch_id, $is_global_admin, 'procedure_orders');
            $stmt7 = $pdo->prepare($sql7);
            $stmt7->execute($p7);
            $approvals['procedure'] = (int)$stmt7->fetchColumn();

            $sql8 = "SELECT COUNT(*) FROM invoice_claims ic INNER JOIN invoices i ON ic.invoice_id = i.id WHERE ic.claim_status IN ('draft', 'submitted') AND ic.deleted_at IS NULL";
            $p8 = [];
            applyBranchFilter($sql8, $p8, $branch_id, $is_global_admin, 'i');
            $stmt8 = $pdo->prepare($sql8);
            $stmt8->execute($p8);
            $approvals['insurance'] = (int)$stmt8->fetchColumn();

            $sql9 = "SELECT COUNT(*) FROM expenses WHERE status = 'pending' AND deleted_at IS NULL";
            $p9 = [];
            applyBranchFilter($sql9, $p9, $branch_id, $is_global_admin, 'expenses');
            $stmt9 = $pdo->prepare($sql9);
            $stmt9->execute($p9);
            $approvals['expense'] = (int)$stmt9->fetchColumn();

            $sql10 = "SELECT COUNT(*) FROM management_approvals WHERE approval_status = 'pending' AND deleted_at IS NULL";
            $p10 = [];
            applyBranchFilter($sql10, $p10, $branch_id, $is_global_admin, 'management_approvals');
            $stmt10 = $pdo->prepare($sql10);
            $stmt10->execute($p10);
            $approvals['management'] = (int)$stmt10->fetchColumn();

            // Using correct column name: is_verified (not verified)
            $sql11 = "SELECT COUNT(*) FROM payments WHERE is_verified = 0 AND deleted_at IS NULL";
            $p11 = [];
            applyBranchFilter($sql11, $p11, $branch_id, $is_global_admin, 'payments');
            $stmt11 = $pdo->prepare($sql11);
            $stmt11->execute($p11);
            $approvals['unverified_payments'] = (int)$stmt11->fetchColumn();

            $approvals['total'] = array_sum($approvals);

            // Weekly trend - using payment_date
            $sql12 = "SELECT DATE(payment_date) AS date, COALESCE(SUM(amount_paid), 0) AS total, COUNT(*) AS count
                      FROM payments WHERE payment_date >= DATE_SUB(NOW(), INTERVAL 7 DAY) AND deleted_at IS NULL
                      GROUP BY DATE(payment_date) ORDER BY DATE(payment_date) ASC";
            $p12 = [];
            applyBranchFilter($sql12, $p12, $branch_id, $is_global_admin, 'payments');
            $stmt12 = $pdo->prepare($sql12);
            $stmt12->execute($p12);
            $weekly_trend = $stmt12->fetchAll(PDO::FETCH_ASSOC);

            // Payment distribution
            $sql13 = "SELECT payment_method, COALESCE(SUM(amount_paid), 0) AS total, COUNT(*) AS count
                      FROM payments WHERE DATE(payment_date) = :today AND deleted_at IS NULL
                      GROUP BY payment_method ORDER BY total DESC";
            $p13 = [':today' => $today];
            applyBranchFilter($sql13, $p13, $branch_id, $is_global_admin, 'payments');
            $stmt13 = $pdo->prepare($sql13);
            $stmt13->execute($p13);
            $payment_distribution = $stmt13->fetchAll(PDO::FETCH_ASSOC);

            jsonResponse(true, '', [
                'today' => [
                    'transactions' => (int)($today_metrics['transaction_count'] ?? 0),
                    'total' => (float)($today_metrics['total_collected'] ?? 0),
                    'cash' => (float)($today_metrics['cash_collected'] ?? 0),
                    'telebirr' => (float)($today_metrics['telebirr_collected'] ?? 0),
                    'cbe_birr' => (float)($today_metrics['cbe_birr_collected'] ?? 0),
                    'card' => (float)($today_metrics['card_collected'] ?? 0),
                    'insurance' => (float)($today_metrics['insurance_collected'] ?? 0),
                    'mobile' => (float)($today_metrics['mobile_collected'] ?? 0),
                    'bank_transfer' => (float)($today_metrics['bank_transfer_collected'] ?? 0),
                    'check' => (float)($today_metrics['check_collected'] ?? 0),
                    'expenses' => $expenses,
                    'net' => (float)($today_metrics['total_collected'] ?? 0) - $expenses
                ],
                'pending' => [
                    'count' => (int)($pending['pending_count'] ?? 0),
                    'amount' => (float)($pending['pending_amount'] ?? 0)
                ],
                'approvals' => $approvals,
                'weekly_trend' => $weekly_trend,
                'payment_distribution' => $payment_distribution
            ]);
        } catch (PDOException $e) {
            error_log('fetch_metrics error: ' . $e->getMessage());
            jsonResponse(false, 'Database error: ' . $e->getMessage());
        }
    }

    // ---------- PENDING INVOICES ----------
    if ($action === 'fetch_pending_invoices') {
        try {
            $sql = "SELECT 
                        i.id, i.invoice_number, i.invoice_date, i.total_amount, i.paid_amount,
                        (i.total_amount - i.paid_amount) AS due_amount, i.payment_status, i.billing_type,
                        CONCAT(p.first_name, ' ', COALESCE(p.middle_name, ''), ' ', p.last_name) AS patient_name,
                        p.mr_number, p.id AS patient_id, p.phone AS patient_phone
                    FROM invoices i
                    INNER JOIN patients p ON i.patient_id = p.id AND p.deleted_at IS NULL
                    WHERE i.payment_status IN ('unpaid', 'partial') AND i.deleted_at IS NULL
                    AND (i.total_amount - i.paid_amount) > 0
                    ORDER BY i.invoice_date ASC";
            $params = [];
            applyBranchFilter($sql, $params, $branch_id, $is_global_admin, 'i');
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);
            jsonResponse(true, '', ['invoices' => $invoices]);
        } catch (PDOException $e) {
            jsonResponse(false, 'Database error: ' . $e->getMessage());
        }
    }

    // ---------- PROCESS PAYMENT ----------
    if ($action === 'process_payment') {
        try {
            $invoice_id = (int)($_POST['invoice_id'] ?? 0);
            $amount_paid = (float)($_POST['amount_paid'] ?? 0);
            $payment_method = validatePaymentMethod($_POST['payment_method'] ?? 'cash');
            $transaction_reference = trim($_POST['transaction_reference'] ?? '');
            $notes = trim($_POST['notes'] ?? '');
            $is_full_payment = (int)($_POST['is_full_payment'] ?? 0);

            if ($invoice_id <= 0 || $amount_paid <= 0) {
                jsonResponse(false, 'Invalid invoice or amount');
            }

            $pdo->beginTransaction();

            $stmt = $pdo->prepare("SELECT id, patient_id, visit_id, total_amount, paid_amount, payment_status, branch_id FROM invoices WHERE id = :id AND deleted_at IS NULL");
            $stmt->execute([':id' => $invoice_id]);
            $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$invoice) { $pdo->rollBack(); jsonResponse(false, 'Invoice not found'); }

            $new_paid = $invoice['paid_amount'] + $amount_paid;
            if ($new_paid > $invoice['total_amount'] && !$is_full_payment) {
                $pdo->rollBack();
                jsonResponse(false, 'Amount exceeds remaining balance. Use full payment or correct amount.');
            }

            $payment_status = 'partial';
            if ($new_paid >= $invoice['total_amount'] || $is_full_payment) {
                $payment_status = 'paid';
                $new_paid = $invoice['total_amount'];
            }

            $receipt_number = generateReceiptNumber($invoice['branch_id']);

            $insert_sql = "INSERT INTO payments (branch_id, invoice_id, patient_id, visit_id, receipt_number, payment_method, 
                         amount_paid, transaction_reference, payment_date, received_by_user_id, notes, is_verified, created_at, updated_at)
                         VALUES (:branch_id, :invoice_id, :patient_id, :visit_id, :receipt_number, :payment_method,
                         :amount_paid, :transaction_reference, NOW(), :user_id, :notes, 1, NOW(), NOW())";
            $stmt = $pdo->prepare($insert_sql);
            $stmt->execute([
                ':branch_id' => $invoice['branch_id'],
                ':invoice_id' => $invoice_id,
                ':patient_id' => $invoice['patient_id'],
                ':visit_id' => $invoice['visit_id'] ?: 0,
                ':receipt_number' => $receipt_number,
                ':payment_method' => $payment_method,
                ':amount_paid' => $amount_paid,
                ':transaction_reference' => $transaction_reference,
                ':user_id' => $user_id,
                ':notes' => $notes
            ]);
            $payment_id = $pdo->lastInsertId();

            $stmt = $pdo->prepare("UPDATE invoices SET paid_amount = :paid_amount, payment_status = :status, updated_at = NOW() WHERE id = :id");
            $stmt->execute([':paid_amount' => $new_paid, ':status' => $payment_status, ':id' => $invoice_id]);

            if ($invoice['visit_id']) {
                $stmt = $pdo->prepare("UPDATE patient_visits SET billing_status = :status WHERE id = :id");
                $stmt->execute([':status' => $payment_status === 'paid' ? 'paid' : 'partial', ':id' => $invoice['visit_id']]);
            }

            $pdo->commit();
            jsonResponse(true, 'Payment processed successfully', [
                'payment_id' => $payment_id,
                'receipt_number' => $receipt_number,
                'payment_status' => $payment_status,
                'new_balance' => $invoice['total_amount'] - $new_paid,
                'amount_paid' => $amount_paid
            ]);
        } catch (PDOException $e) {
            $pdo->rollBack();
            jsonResponse(false, 'Database error: ' . $e->getMessage());
        }
    }

    // ---------- FETCH APPROVALS ----------
    if ($action === 'fetch_approvals') {
        try {
            $type = $_GET['type'] ?? 'all';
            $limit = (int)($_GET['limit'] ?? 50);
            $params = [];
            $sql_parts = [];

            if ($type === 'all' || $type === 'lab') {
                $sql_parts[] = "SELECT 'lab' AS approval_type, lo.id, lo.order_number AS reference_number, 
                               CONCAT('Lab Order #', lo.order_number) AS description, lo.created_at, 
                               lo.payment_status AS status, 0 AS amount, 'routine' AS priority,
                               CONCAT(p.first_name, ' ', COALESCE(p.middle_name, ''), ' ', p.last_name) AS patient_name,
                               p.id AS patient_id
                               FROM lab_orders lo
                               INNER JOIN patients p ON lo.patient_id = p.id AND p.deleted_at IS NULL
                               WHERE lo.payment_status IN ('unpaid', 'partial') AND lo.status = 'pending_payment' AND lo.deleted_at IS NULL";
                applyBranchFilter($sql_parts[count($sql_parts)-1], $params, $branch_id, $is_global_admin, 'lo');
            }
            if ($type === 'all' || $type === 'radiology') {
                $sql_parts[] = "SELECT 'radiology' AS approval_type, ro.id, ro.order_number AS reference_number,
                               CONCAT('Radiology #', ro.order_number) AS description, ro.created_at,
                               ro.payment_status AS status, 0 AS amount, ro.priority,
                               CONCAT(p.first_name, ' ', COALESCE(p.middle_name, ''), ' ', p.last_name) AS patient_name,
                               p.id AS patient_id
                               FROM radiology_orders ro
                               INNER JOIN patients p ON ro.patient_id = p.id AND p.deleted_at IS NULL
                               WHERE ro.payment_status IN ('unpaid', 'partial') AND ro.status = 'pending_payment' AND ro.deleted_at IS NULL";
                applyBranchFilter($sql_parts[count($sql_parts)-1], $params, $branch_id, $is_global_admin, 'ro');
            }
            if ($type === 'all' || $type === 'prescription') {
                $sql_parts[] = "SELECT 'prescription' AS approval_type, pr.id, pr.prescription_number AS reference_number,
                               CONCAT('Prescription #', pr.prescription_number) AS description, pr.created_at,
                               pr.payment_status AS status, 0 AS amount, 'routine' AS priority,
                               CONCAT(p.first_name, ' ', COALESCE(p.middle_name, ''), ' ', p.last_name) AS patient_name,
                               p.id AS patient_id
                               FROM prescriptions pr
                               INNER JOIN patients p ON pr.patient_id = p.id AND p.deleted_at IS NULL
                               WHERE pr.payment_status IN ('unpaid', 'partial') AND pr.status = 'pending_payment' AND pr.deleted_at IS NULL";
                applyBranchFilter($sql_parts[count($sql_parts)-1], $params, $branch_id, $is_global_admin, 'pr');
            }
            if ($type === 'all' || $type === 'procedure') {
                $sql_parts[] = "SELECT 'procedure' AS approval_type, po.id, po.procedure_number AS reference_number,
                               CONCAT('Procedure #', po.procedure_number) AS description, po.created_at,
                               po.payment_status AS status, 0 AS amount, po.priority,
                               CONCAT(p.first_name, ' ', COALESCE(p.middle_name, ''), ' ', p.last_name) AS patient_name,
                               p.id AS patient_id
                               FROM procedure_orders po
                               INNER JOIN patients p ON po.patient_id = p.id AND p.deleted_at IS NULL
                               WHERE po.payment_status IN ('unpaid', 'partial') AND po.execution_status = 'pending_payment' AND po.deleted_at IS NULL";
                applyBranchFilter($sql_parts[count($sql_parts)-1], $params, $branch_id, $is_global_admin, 'po');
            }
            if ($type === 'all' || $type === 'insurance') {
                $sql_parts[] = "SELECT 'insurance' AS approval_type, ic.id, ic.claim_number AS reference_number,
                               CONCAT('Claim #', ic.claim_number) AS description, ic.created_at,
                               ic.claim_status AS status, COALESCE(ic.claimed_amount, 0) AS amount, 'routine' AS priority,
                               CONCAT(p.first_name, ' ', COALESCE(p.middle_name, ''), ' ', p.last_name) AS patient_name,
                               p.id AS patient_id
                               FROM invoice_claims ic
                               INNER JOIN invoices i ON ic.invoice_id = i.id
                               INNER JOIN patients p ON i.patient_id = p.id AND p.deleted_at IS NULL
                               WHERE ic.claim_status IN ('draft', 'submitted') AND ic.deleted_at IS NULL";
                applyBranchFilter($sql_parts[count($sql_parts)-1], $params, $branch_id, $is_global_admin, 'i');
            }
            if ($type === 'all' || $type === 'expense') {
                $sql_parts[] = "SELECT 'expense' AS approval_type, e.id, CONCAT('EXP-', e.id) AS reference_number,
                               CONCAT('Expense #', e.id) AS description, e.created_at,
                               e.status AS status, COALESCE(e.amount, 0) AS amount, 'routine' AS priority,
                               CONCAT(u.first_name, ' ', u.last_name) AS patient_name, 0 AS patient_id
                               FROM expenses e
                               LEFT JOIN users u ON e.requested_by_user_id = u.id
                               WHERE e.status = 'pending' AND e.deleted_at IS NULL";
                applyBranchFilter($sql_parts[count($sql_parts)-1], $params, $branch_id, $is_global_admin, 'e');
            }
            if ($type === 'all' || $type === 'management') {
                $sql_parts[] = "SELECT 'management' AS approval_type, ma.id, CONCAT('APPR-', ma.id) AS reference_number,
                               CONCAT(REPLACE(ma.request_type, '_', ' '), ' - ', ma.source_table) AS description, ma.created_at,
                               ma.approval_status AS status, 0 AS amount, 'routine' AS priority,
                               CONCAT(u.first_name, ' ', u.last_name) AS patient_name, 0 AS patient_id
                               FROM management_approvals ma
                               LEFT JOIN users u ON ma.requested_by_user_id = u.id
                               WHERE ma.approval_status = 'pending' AND ma.deleted_at IS NULL";
                applyBranchFilter($sql_parts[count($sql_parts)-1], $params, $branch_id, $is_global_admin, 'ma');
            }
            if ($type === 'all' || $type === 'unverified') {
                $sql_parts[] = "SELECT 'unverified' AS approval_type, p.id, p.receipt_number AS reference_number,
                               CONCAT('Payment #', p.receipt_number) AS description, p.created_at,
                               'pending_verification' AS status, COALESCE(p.amount_paid, 0) AS amount, 'routine' AS priority,
                               CONCAT(pat.first_name, ' ', COALESCE(pat.middle_name, ''), ' ', pat.last_name) AS patient_name,
                               p.patient_id
                               FROM payments p
                               INNER JOIN patients pat ON p.patient_id = pat.id AND pat.deleted_at IS NULL
                               WHERE p.is_verified = 0 AND p.deleted_at IS NULL";
                applyBranchFilter($sql_parts[count($sql_parts)-1], $params, $branch_id, $is_global_admin, 'p');
            }

            if (empty($sql_parts)) {
                jsonResponse(true, '', ['approvals' => [], 'total_count' => 0]);
            }

            $combined = implode(" UNION ALL ", $sql_parts) . " ORDER BY created_at DESC LIMIT " . (int)$limit;
            $stmt = $pdo->prepare($combined);
            $stmt->execute($params);
            $approvals = $stmt->fetchAll(PDO::FETCH_ASSOC);
            jsonResponse(true, '', ['approvals' => $approvals, 'total_count' => count($approvals)]);
        } catch (PDOException $e) {
            jsonResponse(false, 'Database error: ' . $e->getMessage());
        }
    }

    // ---------- APPROVE REQUEST ----------
    if ($action === 'approve_request') {
        try {
            $type = $_POST['type'] ?? '';
            $id = (int)($_POST['id'] ?? 0);
            $approve = (int)($_POST['approve'] ?? 1);
            $notes = trim($_POST['notes'] ?? '');

            if (empty($type) || $id <= 0) {
                jsonResponse(false, 'Invalid request');
            }

            $pdo->beginTransaction();
            $table = '';
            $status_field = 'status';
            $status_value = $approve ? 'approved' : 'rejected';

            switch ($type) {
                case 'lab': $table = 'lab_orders'; $status_field = 'status'; $status_value = $approve ? 'approved' : 'cancelled'; break;
                case 'radiology': $table = 'radiology_orders'; $status_field = 'status'; $status_value = $approve ? 'approved' : 'cancelled'; break;
                case 'prescription': $table = 'prescriptions'; $status_field = 'status'; $status_value = $approve ? 'active' : 'cancelled'; break;
                case 'procedure': $table = 'procedure_orders'; $status_field = 'execution_status'; $status_value = $approve ? 'scheduled' : 'cancelled'; break;
                case 'insurance': $table = 'invoice_claims'; $status_field = 'claim_status'; $status_value = $approve ? 'submitted' : 'rejected'; break;
                case 'expense': $table = 'expenses'; $status_field = 'status'; $status_value = $approve ? 'approved' : 'rejected'; break;
                case 'management': $table = 'management_approvals'; $status_field = 'approval_status'; $status_value = $approve ? 'approved' : 'rejected'; break;
                case 'unverified': $table = 'payments'; $status_field = 'is_verified'; $status_value = $approve ? 1 : 0; break;
                default: $pdo->rollBack(); jsonResponse(false, 'Unknown type');
            }

            $sql = "UPDATE {$table} SET {$status_field} = :status";
            if ($type === 'unverified') $sql .= ", verified_by_user_id = :user_id, verified_at = NOW()";
            if ($type === 'expense' && $approve) $sql .= ", approved_by_user_id = :user_id, approved_at = NOW()";
            if ($type === 'management') $sql .= ", reviewed_at = NOW(), admin_remarks = :notes, approved_by_user_id = :user_id";
            if ($type === 'insurance' && $approve) $sql .= ", submission_date = CURDATE(), submitted_by_user_id = :user_id";
            if ($type === 'insurance' && !$approve) $sql .= ", rejection_reason = :notes";
            $sql .= ", updated_at = NOW() WHERE id = :id";

            $stmt = $pdo->prepare($sql);
            $params = [':status' => $status_value, ':id' => $id, ':user_id' => $user_id];
            if ($type === 'management' || ($type === 'insurance' && !$approve)) $params[':notes'] = $notes;
            $stmt->execute($params);

            $pdo->commit();
            jsonResponse(true, $approve ? 'Approved successfully' : 'Rejected successfully');
        } catch (PDOException $e) {
            $pdo->rollBack();
            jsonResponse(false, 'Database error: ' . $e->getMessage());
        }
    }

    // ---------- SEARCH PATIENTS ----------
    if ($action === 'search_patients') {
        try {
            $search = trim($_GET['search'] ?? '');
            $results = [];
            if (strlen($search) >= 2) {
                $sql = "SELECT id, first_name, last_name, middle_name, mr_number, phone FROM patients 
                        WHERE (first_name LIKE :search OR last_name LIKE :search OR mr_number LIKE :search OR phone LIKE :search)
                        AND branch_id = :branch_id AND deleted_at IS NULL LIMIT 20";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([':search' => '%' . $search . '%', ':branch_id' => $branch_id]);
                while ($p = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $results[] = [
                        'id' => $p['id'],
                        'text' => trim($p['first_name'] . ' ' . ($p['middle_name'] ?? '') . ' ' . $p['last_name']) . ' (' . $p['mr_number'] . ')',
                        'mr_number' => $p['mr_number'],
                        'phone' => $p['phone']
                    ];
                }
            }
            jsonResponse(true, '', $results);
        } catch (PDOException $e) {
            jsonResponse(false, 'Database error: ' . $e->getMessage());
        }
    }

    // ---------- CREATE INVOICE ----------
    if ($action === 'create_invoice') {
        try {
            $patient_id = (int)($_POST['patient_id'] ?? 0);
            $visit_id = (int)($_POST['visit_id'] ?? 0);
            $items = json_decode($_POST['items'] ?? '[]', true);
            $billing_type = $_POST['billing_type'] ?? 'cash';
            $discount = (float)($_POST['discount_amount'] ?? 0);
            $notes = trim($_POST['notes'] ?? '');

            if ($patient_id <= 0 || empty($items)) {
                jsonResponse(false, 'Invalid patient or items');
            }

            $pdo->beginTransaction();
            $subtotal = 0;
            foreach ($items as $item) {
                $subtotal += ($item['amount'] ?? 0) * ($item['quantity'] ?? 1);
            }
            $total = $subtotal - $discount;

            $invoice_number = generateInvoiceNumber();

            if ($visit_id <= 0) {
                $visit_number = 'VIS-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
                $stmt = $pdo->prepare("INSERT INTO patient_visits (branch_id, patient_id, visit_number, visit_date, visit_time, visit_type, payment_mode, billing_status, status, created_by_user_id, created_at, updated_at)
                                       VALUES (:branch_id, :patient_id, :visit_number, CURDATE(), CURTIME(), 'regular', :payment_mode, 'unpaid', 'pending', :user_id, NOW(), NOW())");
                $stmt->execute([
                    ':branch_id' => $branch_id,
                    ':patient_id' => $patient_id,
                    ':visit_number' => $visit_number,
                    ':payment_mode' => $billing_type === 'insurance' ? 'insurance' : 'cash',
                    ':user_id' => $user_id
                ]);
                $visit_id = $pdo->lastInsertId();
            }

            $stmt = $pdo->prepare("INSERT INTO invoices (branch_id, patient_id, visit_id, invoice_number, invoice_date, subtotal, discount_amount, tax_amount, total_amount, paid_amount, payment_status, billing_type, notes, created_by_user_id, created_at, updated_at)
                                   VALUES (:branch_id, :patient_id, :visit_id, :invoice_number, NOW(), :subtotal, :discount, 0, :total, 0, 'unpaid', :billing_type, :notes, :user_id, NOW(), NOW())");
            $stmt->execute([
                ':branch_id' => $branch_id,
                ':patient_id' => $patient_id,
                ':visit_id' => $visit_id,
                ':invoice_number' => $invoice_number,
                ':subtotal' => $subtotal,
                ':discount' => $discount,
                ':total' => $total,
                ':billing_type' => $billing_type,
                ':notes' => $notes,
                ':user_id' => $user_id
            ]);
            $invoice_id = $pdo->lastInsertId();

            foreach ($items as $item) {
                $stmt = $pdo->prepare("INSERT INTO invoice_items (invoice_id, service_tariff_id, service_name, service_category, quantity, unit_price, discount_amount, tax_amount, total_price, created_at)
                                       VALUES (:invoice_id, 1, :service_name, 'other', :quantity, :unit_price, 0, 0, :total_price, NOW())");
                $stmt->execute([
                    ':invoice_id' => $invoice_id,
                    ':service_name' => $item['service_name'] ?? 'Service',
                    ':quantity' => $item['quantity'] ?? 1,
                    ':unit_price' => $item['amount'] ?? 0,
                    ':total_price' => ($item['amount'] ?? 0) * ($item['quantity'] ?? 1)
                ]);
            }

            $pdo->commit();
            jsonResponse(true, 'Invoice created successfully', [
                'invoice_id' => $invoice_id,
                'invoice_number' => $invoice_number,
                'total_amount' => $total
            ]);
        } catch (PDOException $e) {
            $pdo->rollBack();
            jsonResponse(false, 'Database error: ' . $e->getMessage());
        }
    }

    // ---------- FETCH RECENT PAYMENTS ----------
    if ($action === 'fetch_recent_payments') {
        try {
            $sql = "SELECT p.id, p.receipt_number, p.amount_paid, p.payment_method, p.payment_date, p.is_verified,
                           CONCAT(pat.first_name, ' ', COALESCE(pat.middle_name, ''), ' ', pat.last_name) AS patient_name,
                           pat.mr_number, i.invoice_number, u.first_name AS cashier_name
                    FROM payments p
                    INNER JOIN invoices i ON p.invoice_id = i.id AND i.deleted_at IS NULL
                    INNER JOIN patients pat ON p.patient_id = pat.id AND pat.deleted_at IS NULL
                    INNER JOIN users u ON p.received_by_user_id = u.id AND u.deleted_at IS NULL
                    WHERE p.deleted_at IS NULL
                    ORDER BY p.created_at DESC LIMIT 30";
            $params = [];
            applyBranchFilter($sql, $params, $branch_id, $is_global_admin, 'p');
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
            jsonResponse(true, '', ['payments' => $payments]);
        } catch (PDOException $e) {
            jsonResponse(false, 'Database error: ' . $e->getMessage());
        }
    }

    // ---------- CASH CLOSURE ----------
    if ($action === 'perform_cash_closure') {
        try {
            $closure_date = $_POST['closure_date'] ?? date('Y-m-d');
            $physical_cash = (float)($_POST['physical_cash'] ?? 0);
            $notes = trim($_POST['notes'] ?? '');

            $pdo->beginTransaction();

            $stmt = $pdo->prepare("SELECT id FROM cash_closure WHERE branch_id = :branch_id AND closure_date = :date AND deleted_at IS NULL");
            $stmt->execute([':branch_id' => $branch_id, ':date' => $closure_date]);
            if ($stmt->fetch()) { $pdo->rollBack(); jsonResponse(false, 'Closure already exists for this date'); }

            $sql = "SELECT COALESCE(SUM(CASE WHEN payment_method = 'cash' THEN amount_paid END), 0) AS total_cash,
                           COALESCE(SUM(CASE WHEN payment_method = 'insurance_claim' THEN amount_paid END), 0) AS total_insurance,
                           COALESCE(SUM(CASE WHEN payment_method IN ('credit_card', 'debit_card') THEN amount_paid END), 0) AS total_card,
                           COALESCE(SUM(CASE WHEN payment_method IN ('telebirr', 'cbe_birr', 'mobile_money', 'bank_transfer', 'check') THEN amount_paid END), 0) AS total_mobile,
                           COUNT(*) AS invoice_count, COALESCE(SUM(amount_paid), 0) AS total_revenue
                    FROM payments WHERE DATE(payment_date) = :date AND branch_id = :branch_id AND deleted_at IS NULL";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':date' => $closure_date, ':branch_id' => $branch_id]);
            $totals = $stmt->fetch(PDO::FETCH_ASSOC);

            $difference = $physical_cash - ($totals['total_cash'] ?? 0);

            $insert_sql = "INSERT INTO cash_closure (user_id, branch_id, closure_date, closed_at, total_cash_system, total_insurance_system,
                         total_card_system, total_mobile_system, total_revenue_system, total_invoices_count,
                         physical_cash, difference, notes, is_locked, created_at)
                         VALUES (:user_id, :branch_id, :date, NOW(), :total_cash, :total_insurance,
                         :total_card, :total_mobile, :total_revenue, :invoice_count,
                         :physical_cash, :difference, :notes, 1, NOW())";
            $stmt = $pdo->prepare($insert_sql);
            $stmt->execute([
                ':user_id' => $user_id, ':branch_id' => $branch_id, ':date' => $closure_date,
                ':total_cash' => $totals['total_cash'] ?? 0,
                ':total_insurance' => $totals['total_insurance'] ?? 0,
                ':total_card' => $totals['total_card'] ?? 0,
                ':total_mobile' => $totals['total_mobile'] ?? 0,
                ':total_revenue' => $totals['total_revenue'] ?? 0,
                ':invoice_count' => $totals['invoice_count'] ?? 0,
                ':physical_cash' => $physical_cash,
                ':difference' => $difference,
                ':notes' => $notes
            ]);

            $pdo->commit();
            jsonResponse(true, 'Cash closure completed', [
                'total_cash' => $totals['total_cash'] ?? 0,
                'difference' => $difference,
                'total_revenue' => $totals['total_revenue'] ?? 0,
                'invoice_count' => $totals['invoice_count'] ?? 0
            ]);
        } catch (PDOException $e) {
            $pdo->rollBack();
            jsonResponse(false, 'Database error: ' . $e->getMessage());
        }
    }

    // ---------- FETCH CLOSURE HISTORY ----------
    if ($action === 'fetch_closure_history') {
        try {
            $sql = "SELECT cc.*, CONCAT(u.first_name, ' ', u.last_name) AS closed_by, b.name AS branch_name
                    FROM cash_closure cc
                    INNER JOIN users u ON cc.user_id = u.id AND u.deleted_at IS NULL
                    INNER JOIN branches b ON cc.branch_id = b.id AND b.deleted_at IS NULL
                    WHERE cc.branch_id = :branch_id AND cc.deleted_at IS NULL
                    ORDER BY cc.created_at DESC LIMIT 30";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':branch_id' => $branch_id]);
            $closures = $stmt->fetchAll(PDO::FETCH_ASSOC);
            jsonResponse(true, '', ['closures' => $closures]);
        } catch (PDOException $e) {
            jsonResponse(false, 'Database error: ' . $e->getMessage());
        }
    }

    // ---------- SEARCH INVOICES ----------
    if ($action === 'search_invoices') {
        try {
            $search = '%' . trim($_GET['search'] ?? '') . '%';
            $sql = "SELECT i.id, i.invoice_number, i.invoice_date, i.total_amount, i.paid_amount,
                           (i.total_amount - i.paid_amount) AS due_amount, i.payment_status, i.billing_type,
                           CONCAT(p.first_name, ' ', COALESCE(p.middle_name, ''), ' ', p.last_name) AS patient_name,
                           p.mr_number, p.id AS patient_id, p.phone
                    FROM invoices i
                    INNER JOIN patients p ON i.patient_id = p.id AND p.deleted_at IS NULL
                    WHERE (i.invoice_number LIKE :search OR p.first_name LIKE :search OR p.last_name LIKE :search OR p.mr_number LIKE :search)
                    AND i.deleted_at IS NULL
                    ORDER BY i.created_at DESC LIMIT 20";
            $params = [':search' => $search];
            applyBranchFilter($sql, $params, $branch_id, $is_global_admin, 'i');
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);
            jsonResponse(true, '', ['invoices' => $invoices]);
        } catch (PDOException $e) {
            jsonResponse(false, 'Database error: ' . $e->getMessage());
        }
    }

    // ---------- DAILY TRANSACTIONS ----------
    if ($action === 'fetch_daily_transactions') {
        try {
            $date = $_GET['date'] ?? date('Y-m-d');
            $sql = "SELECT p.id, p.receipt_number, p.amount_paid, p.payment_method, p.payment_date, p.notes, p.transaction_reference,
                           CONCAT(pat.first_name, ' ', COALESCE(pat.middle_name, ''), ' ', pat.last_name) AS patient_name,
                           pat.mr_number, i.invoice_number, u.first_name AS cashier_name, i.billing_type
                    FROM payments p
                    INNER JOIN invoices i ON p.invoice_id = i.id AND i.deleted_at IS NULL
                    INNER JOIN patients pat ON p.patient_id = pat.id AND pat.deleted_at IS NULL
                    INNER JOIN users u ON p.received_by_user_id = u.id AND u.deleted_at IS NULL
                    WHERE DATE(p.payment_date) = :date AND p.deleted_at IS NULL
                    ORDER BY p.created_at DESC";
            $params = [':date' => $date];
            applyBranchFilter($sql, $params, $branch_id, $is_global_admin, 'p');
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $sql2 = "SELECT COALESCE(SUM(amount_paid), 0) AS total, COUNT(*) AS count,
                           COALESCE(SUM(CASE WHEN payment_method = 'cash' THEN amount_paid END), 0) AS cash_total,
                           COALESCE(SUM(CASE WHEN payment_method IN ('telebirr', 'cbe_birr', 'mobile_money') THEN amount_paid END), 0) AS digital_total,
                           COALESCE(SUM(CASE WHEN payment_method IN ('credit_card', 'debit_card') THEN amount_paid END), 0) AS card_total
                    FROM payments WHERE DATE(payment_date) = :date AND branch_id = :branch_id AND deleted_at IS NULL";
            $stmt2 = $pdo->prepare($sql2);
            $stmt2->execute([':date' => $date, ':branch_id' => $branch_id]);
            $totals = $stmt2->fetch(PDO::FETCH_ASSOC);

            jsonResponse(true, '', [
                'transactions' => $transactions,
                'total' => (float)($totals['total'] ?? 0),
                'count' => (int)($totals['count'] ?? 0),
                'cash_total' => (float)($totals['cash_total'] ?? 0),
                'digital_total' => (float)($totals['digital_total'] ?? 0),
                'card_total' => (float)($totals['card_total'] ?? 0)
            ]);
        } catch (PDOException $e) {
            jsonResponse(false, 'Database error: ' . $e->getMessage());
        }
    }

    jsonResponse(false, 'Invalid action');
    exit;
}

// ============================================
// PAGE DATA
// ============================================
$today = date('Y-m-d');
$current_date = date('l, F j, Y');
$current_time = date('g:i A');

// Fetch initial metrics for display
$initial_metrics = [
    'today' => ['transactions' => 0, 'total' => 0, 'cash' => 0, 'telebirr' => 0, 'cbe_birr' => 0, 'card' => 0, 'insurance' => 0, 'mobile' => 0, 'bank_transfer' => 0, 'check' => 0, 'expenses' => 0, 'net' => 0],
    'pending' => ['count' => 0, 'amount' => 0],
    'approvals' => ['total' => 0, 'lab' => 0, 'radiology' => 0, 'prescription' => 0, 'procedure' => 0, 'insurance' => 0, 'expense' => 0, 'management' => 0, 'unverified_payments' => 0]
];

try {
    // Using correct column names from payments table
    $sql = "SELECT COUNT(*) AS transaction_count, COALESCE(SUM(amount_paid), 0) AS total_collected,
                   COALESCE(SUM(CASE WHEN payment_method = 'cash' THEN amount_paid END), 0) AS cash_collected,
                   COALESCE(SUM(CASE WHEN payment_method = 'telebirr' THEN amount_paid END), 0) AS telebirr_collected,
                   COALESCE(SUM(CASE WHEN payment_method = 'cbe_birr' THEN amount_paid END), 0) AS cbe_birr_collected,
                   COALESCE(SUM(CASE WHEN payment_method IN ('credit_card', 'debit_card') THEN amount_paid END), 0) AS card_collected,
                   COALESCE(SUM(CASE WHEN payment_method = 'insurance_claim' THEN amount_paid END), 0) AS insurance_collected,
                   COALESCE(SUM(CASE WHEN payment_method = 'mobile_money' THEN amount_paid END), 0) AS mobile_collected,
                   COALESCE(SUM(CASE WHEN payment_method = 'bank_transfer' THEN amount_paid END), 0) AS bank_transfer_collected,
                   COALESCE(SUM(CASE WHEN payment_method = 'check' THEN amount_paid END), 0) AS check_collected
            FROM payments WHERE DATE(payment_date) = :today AND branch_id = :branch_id AND deleted_at IS NULL";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':today' => $today, ':branch_id' => $branch_id]);
    if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $initial_metrics['today']['transactions'] = (int)($row['transaction_count'] ?? 0);
        $initial_metrics['today']['total'] = (float)($row['total_collected'] ?? 0);
        $initial_metrics['today']['cash'] = (float)($row['cash_collected'] ?? 0);
        $initial_metrics['today']['telebirr'] = (float)($row['telebirr_collected'] ?? 0);
        $initial_metrics['today']['cbe_birr'] = (float)($row['cbe_birr_collected'] ?? 0);
        $initial_metrics['today']['card'] = (float)($row['card_collected'] ?? 0);
        $initial_metrics['today']['insurance'] = (float)($row['insurance_collected'] ?? 0);
        $initial_metrics['today']['mobile'] = (float)($row['mobile_collected'] ?? 0);
        $initial_metrics['today']['bank_transfer'] = (float)($row['bank_transfer_collected'] ?? 0);
        $initial_metrics['today']['check'] = (float)($row['check_collected'] ?? 0);
    }

    $sql = "SELECT COALESCE(SUM(amount), 0) AS expenses FROM expense_registry WHERE DATE(expense_date) = :today AND branch_id = :branch_id AND deleted_at IS NULL";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':today' => $today, ':branch_id' => $branch_id]);
    $initial_metrics['today']['expenses'] = (float)$stmt->fetchColumn();
    $initial_metrics['today']['net'] = $initial_metrics['today']['total'] - $initial_metrics['today']['expenses'];

    $sql = "SELECT COUNT(*) AS pending_count, COALESCE(SUM(total_amount - paid_amount), 0) AS pending_amount
            FROM invoices WHERE branch_id = :branch_id AND payment_status IN ('unpaid', 'partial') AND deleted_at IS NULL";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':branch_id' => $branch_id]);
    if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $initial_metrics['pending']['count'] = (int)($row['pending_count'] ?? 0);
        $initial_metrics['pending']['amount'] = (float)($row['pending_amount'] ?? 0);
    }

    // Approvals
    $sqls = [
        'lab' => "SELECT COUNT(*) FROM lab_orders WHERE branch_id = :branch_id AND payment_status IN ('unpaid', 'partial') AND status = 'pending_payment' AND deleted_at IS NULL",
        'radiology' => "SELECT COUNT(*) FROM radiology_orders WHERE branch_id = :branch_id AND payment_status IN ('unpaid', 'partial') AND status = 'pending_payment' AND deleted_at IS NULL",
        'prescription' => "SELECT COUNT(*) FROM prescriptions WHERE branch_id = :branch_id AND payment_status IN ('unpaid', 'partial') AND status = 'pending_payment' AND deleted_at IS NULL",
        'procedure' => "SELECT COUNT(*) FROM procedure_orders WHERE branch_id = :branch_id AND payment_status IN ('unpaid', 'partial') AND execution_status = 'pending_payment' AND deleted_at IS NULL",
        'expense' => "SELECT COUNT(*) FROM expenses WHERE branch_id = :branch_id AND status = 'pending' AND deleted_at IS NULL",
        'management' => "SELECT COUNT(*) FROM management_approvals WHERE branch_id = :branch_id AND approval_status = 'pending' AND deleted_at IS NULL",
        'unverified_payments' => "SELECT COUNT(*) FROM payments WHERE branch_id = :branch_id AND is_verified = 0 AND deleted_at IS NULL"
    ];
    foreach ($sqls as $key => $sql) {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':branch_id' => $branch_id]);
        $initial_metrics['approvals'][$key] = (int)$stmt->fetchColumn();
    }
    $sql = "SELECT COUNT(*) FROM invoice_claims ic INNER JOIN invoices i ON ic.invoice_id = i.id WHERE i.branch_id = :branch_id AND ic.claim_status IN ('draft', 'submitted') AND ic.deleted_at IS NULL";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':branch_id' => $branch_id]);
    $initial_metrics['approvals']['insurance'] = (int)$stmt->fetchColumn();
    $initial_metrics['approvals']['total'] = array_sum($initial_metrics['approvals']);

} catch (PDOException $e) {
    error_log('Cashier init error: ' . $e->getMessage());
}

$initial_data_json = json_encode($initial_metrics, JSON_UNESCAPED_UNICODE);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cashier Dashboard | HMS ERP</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
            --border-gray: #e5e7eb;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif; background: linear-gradient(135deg, #f5f7fb 0%, #eef2f6 100%); overflow-x: hidden; }
        .content-page { margin-left: 260px; margin-top: 70px; padding: 24px 32px; transition: margin-left 0.3s; min-height: calc(100vh - 70px); }
        body.erp-sidebar-collapsed .content-page { margin-left: 80px; }
        @media (max-width: 768px) { .content-page { margin-left: 0 !important; padding: 16px; } }
        
        .welcome-header { background: linear-gradient(135deg, var(--primary-dark) 0%, var(--primary) 100%); border-radius: 24px; padding: 20px 28px; color: white; margin-bottom: 24px; }
        .welcome-title { font-size: 22px; font-weight: 700; }
        .welcome-subtitle { color: rgba(255,255,255,0.7); font-size: 13px; }
        .time-badge { background: rgba(255,255,255,0.2); display: inline-block; padding: 8px 16px; border-radius: 30px; font-size: 13px; backdrop-filter: blur(4px); }
        
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 24px; }
        .stat-card { background: white; border-radius: 16px; padding: 18px 20px; border: 1px solid var(--border-gray); transition: all 0.3s; cursor: pointer; position: relative; }
        .stat-card:hover { transform: translateY(-3px); box-shadow: 0 8px 25px rgba(0,0,0,0.08); }
        .stat-card .stat-value { font-size: 28px; font-weight: 800; color: var(--dark); }
        .stat-card .stat-label { font-size: 13px; color: var(--gray); font-weight: 500; }
        .stat-card .stat-icon { position: absolute; top: 16px; right: 16px; font-size: 24px; opacity: 0.15; }
        .stat-card.blue { border-left: 4px solid var(--primary); }
        .stat-card.green { border-left: 4px solid var(--success); }
        .stat-card.orange { border-left: 4px solid #f9844a; }
        .stat-card.red { border-left: 4px solid var(--danger); }
        .stat-card.purple { border-left: 4px solid #7209b7; }
        .stat-card.teal { border-left: 4px solid #06d6a0; }
        
        .nav-tabs-custom { border-bottom: 2px solid var(--border-gray); margin-bottom: 20px; display: flex; flex-wrap: wrap; gap: 4px; }
        .nav-tabs-custom .nav-link { border: none; padding: 12px 24px; font-weight: 600; font-size: 14px; color: var(--gray); border-radius: 10px 10px 0 0; transition: all 0.3s; cursor: pointer; background: transparent; }
        .nav-tabs-custom .nav-link:hover { background: var(--gray-light); color: var(--dark); }
        .nav-tabs-custom .nav-link.active { color: var(--primary); background: white; border-bottom: 3px solid var(--primary); }
        .nav-tabs-custom .nav-link i { margin-right: 8px; }
        .tab-pane { display: none; animation: fadeIn 0.3s ease; }
        .tab-pane.active { display: block; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        
        .card-panel { background: white; border-radius: 16px; border: 1px solid var(--border-gray); overflow: hidden; margin-bottom: 20px; }
        .card-panel .card-header { padding: 14px 20px; background: #f8fafc; border-bottom: 1px solid var(--border-gray); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; }
        .card-panel .card-header h5 { margin: 0; font-weight: 700; font-size: 16px; }
        .card-panel .card-body { padding: 16px 20px; max-height: 450px; overflow-y: auto; }
        .card-panel .card-body::-webkit-scrollbar { width: 5px; }
        .card-panel .card-body::-webkit-scrollbar-thumb { background: var(--border-gray); border-radius: 3px; }
        
        .table-financial { width: 100%; font-size: 14px; }
        .table-financial thead th { background: #f8fafc; font-weight: 600; font-size: 11px; text-transform: uppercase; color: var(--gray); padding: 8px 12px; border-bottom: 2px solid var(--border-gray); position: sticky; top: 0; z-index: 2; }
        .table-financial tbody td { padding: 8px 12px; border-bottom: 1px solid var(--border-gray); vertical-align: middle; }
        .table-financial tbody tr:hover { background: #f8fafc; }
        
        .btn { padding: 8px 18px; border-radius: 10px; font-weight: 600; border: none; cursor: pointer; transition: all 0.2s; font-family: inherit; font-size: 13px; }
        .btn-primary { background: var(--primary); color: white; }
        .btn-primary:hover { background: var(--primary-dark); }
        .btn-success { background: var(--success); color: white; }
        .btn-success:hover { background: #059669; }
        .btn-danger { background: var(--danger); color: white; }
        .btn-danger:hover { background: #dc2626; }
        .btn-warning { background: var(--warning); color: var(--dark); }
        .btn-warning:hover { background: #d97706; color: white; }
        .btn-outline { background: white; border: 1px solid var(--border-gray); color: var(--dark); }
        .btn-outline:hover { border-color: var(--primary); color: var(--primary); }
        .btn-sm { padding: 4px 12px; font-size: 12px; }
        
        .badge { display: inline-block; padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: 600; }
        .badge-paid { background: #d1fae5; color: #059669; }
        .badge-unpaid { background: #fef3c7; color: #d97706; }
        .badge-partial { background: #fef3c7; color: #d97706; }
        .badge-cancelled { background: #fee2e2; color: #dc2626; }
        .badge-pending { background: #e0e7ff; color: #4361ee; }
        .badge-approved { background: #d1fae5; color: #059669; }
        .badge-rejected { background: #fee2e2; color: #dc2626; }
        .badge-draft { background: #f3f4f6; color: #6b7280; }
        .badge-submitted { background: #fef3c7; color: #d97706; }
        .badge-verified { background: #d1fae5; color: #059669; }
        .badge-unverified { background: #fee2e2; color: #dc2626; }
        
        .method-badge { display: inline-block; padding: 2px 10px; border-radius: 12px; font-size: 10px; font-weight: 600; }
        .method-cash { background: #d1fae5; color: #059669; }
        .method-card { background: #e0e7ff; color: #4361ee; }
        .method-mobile { background: #fef3c7; color: #d97706; }
        .method-bank { background: #ede9fe; color: #7c3aed; }
        .method-insurance { background: #fce7f3; color: #db2777; }
        
        .type-badge { display: inline-block; padding: 2px 10px; border-radius: 12px; font-size: 10px; font-weight: 600; }
        .type-lab { background: #e0e7ff; color: #4361ee; }
        .type-radiology { background: #fce7f3; color: #db2777; }
        .type-prescription { background: #d1fae5; color: #059669; }
        .type-procedure { background: #ede9fe; color: #7c3aed; }
        .type-insurance { background: #fef3c7; color: #d97706; }
        .type-expense { background: #fee2e2; color: #dc2626; }
        .type-management { background: #e0e7ff; color: #4361ee; }
        .type-unverified { background: #fee2e2; color: #dc2626; }
        
        .empty-state { text-align: center; padding: 30px 20px; color: var(--gray); }
        .empty-state i { font-size: 36px; opacity: 0.2; margin-bottom: 8px; }
        
        .loading-spinner { display: flex; justify-content: center; padding: 30px; }
        
        .modal-content { border-radius: 16px; border: none; }
        .modal-header { border-bottom: 1px solid var(--border-gray); padding: 16px 24px; }
        .modal-body { padding: 20px 24px; max-height: 70vh; overflow-y: auto; }
        .modal-footer { border-top: 1px solid var(--border-gray); padding: 16px 24px; }
        
        .form-control, .form-select { border-radius: 10px; border: 1px solid var(--border-gray); padding: 8px 14px; font-family: inherit; }
        .form-control:focus, .form-select:focus { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(67,97,238,0.1); }
        
        .invoice-item-row { background: #f8fafc; border-radius: 8px; padding: 8px; margin-bottom: 8px; }
        
        .receipt-preview { font-family: 'Courier New', monospace; font-size: 13px; }
        .receipt-preview hr { border-top: 1px dashed #ccc; }
        
        .loading-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(255,255,255,0.7); z-index: 9999; display: none; align-items: center; justify-content: center; }
        .loading-overlay.active { display: flex; }
        .spinner { width: 40px; height: 40px; border: 3px solid var(--gray-light); border-top-color: var(--primary); border-radius: 50%; animation: spin 0.8s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }
        
        .toast-notification { position: fixed; bottom: 20px; right: 20px; background: white; border-radius: 12px; padding: 12px 20px; box-shadow: 0 10px 25px rgba(0,0,0,0.15); z-index: 9999; animation: slideIn 0.3s ease; display: flex; align-items: center; gap: 12px; border-left: 4px solid var(--success); max-width: 400px; }
        .toast-error { border-left-color: var(--danger); }
        @keyframes slideIn { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
        
        .chart-container { height: 200px; width: 100%; }
        
        .quick-actions { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 16px; }
        
        @media (max-width: 768px) {
            .stats-grid { grid-template-columns: 1fr 1fr; }
            .nav-tabs-custom .nav-link { padding: 10px 16px; font-size: 13px; }
        }
        @media (max-width: 480px) {
            .stats-grid { grid-template-columns: 1fr; }
            .welcome-header { padding: 16px 20px; }
            .welcome-title { font-size: 18px; }
        }
    </style>
</head>
<body>
<div id="wrapper">
    <?php include('assets/inc/nav.php'); ?>
    <?php include('assets/inc/sidebar.php'); ?>

    <div class="content-page">
        <div class="container-fluid">
            <!-- Welcome Header -->
            <div class="welcome-header">
                <div class="row align-items-center">
                    <div class="col-md-7">
                        <div class="welcome-title"><i class="fas fa-cash-register me-2"></i>Cashier Dashboard</div>
                        <div class="welcome-subtitle">Process payments, manage invoices, track collections, and approve requests.</div>
                    </div>
                    <div class="col-md-5 text-md-end mt-3 mt-md-0">
                        <div class="time-badge"><i class="far fa-calendar-alt"></i> <?php echo escapeHtml($current_date); ?> <i class="far fa-clock ms-2"></i> <?php echo escapeHtml($current_time); ?></div>
                    </div>
                </div>
            </div>

            <!-- Quick Stats -->
            <div class="quick-actions">
                <button class="btn btn-primary" onclick="openPaymentModal()"><i class="fas fa-hand-holding-usd"></i> Process Payment</button>
                <button class="btn btn-success" onclick="openInvoiceModal()"><i class="fas fa-file-invoice"></i> Create Invoice</button>
                <button class="btn btn-warning" onclick="openCashClosureModal()"><i class="fas fa-cash-register"></i> Cash Closure</button>
                <button class="btn btn-danger" onclick="openApprovalModal()"><i class="fas fa-check-double"></i> Approvals <span class="badge bg-light text-dark" id="approvalBadge">0</span></button>
                <button class="btn btn-outline" onclick="refreshDashboard()"><i class="fas fa-sync-alt"></i> Refresh</button>
            </div>

            <!-- KPI Cards -->
            <div class="stats-grid" id="kpiGrid">
                <div class="stat-card blue" onclick="viewDailyTransactions()">
                    <i class="fas fa-coins stat-icon"></i>
                    <div class="stat-value" id="todayTotal">0.00</div>
                    <div class="stat-label">Today's Collections (ETB)</div>
                    <small class="text-muted" id="todayCount">0 transactions</small>
                </div>
                <div class="stat-card orange" onclick="viewPendingInvoices()">
                    <i class="fas fa-clock stat-icon"></i>
                    <div class="stat-value" id="pendingCount">0</div>
                    <div class="stat-label">Pending Invoices</div>
                    <small class="text-muted" id="pendingAmount">Due: 0.00 ETB</small>
                </div>
                <div class="stat-card red" onclick="openApprovalModal()">
                    <i class="fas fa-check-double stat-icon"></i>
                    <div class="stat-value" id="approvalTotal">0</div>
                    <div class="stat-label">Pending Approvals</div>
                    <small class="text-muted">Needs your action</small>
                </div>
                <div class="stat-card green">
                    <i class="fas fa-chart-line stat-icon"></i>
                    <div class="stat-value" id="todayNet">0.00</div>
                    <div class="stat-label">Net Income (ETB)</div>
                    <small class="text-muted" id="netExpenses">Expenses: 0.00</small>
                </div>
            </div>

            <!-- Tabs -->
            <ul class="nav nav-tabs-custom" id="dashboardTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" data-tab="tab-invoices" role="tab">
                        <i class="fas fa-file-invoice"></i> Invoices
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" data-tab="tab-payments" role="tab">
                        <i class="fas fa-history"></i> Payments
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" data-tab="tab-approvals" role="tab">
                        <i class="fas fa-check-double"></i> Approvals
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" data-tab="tab-closures" role="tab">
                        <i class="fas fa-cash-register"></i> Cash Closures
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" data-tab="tab-analytics" role="tab">
                        <i class="fas fa-chart-area"></i> Analytics
                    </button>
                </li>
            </ul>

            <div class="tab-content">
                <!-- TAB 1: Invoices -->
                <div class="tab-pane active" id="tab-invoices" role="tabpanel">
                    <div class="card-panel">
                        <div class="card-header">
                            <h5><i class="fas fa-file-invoice me-2"></i>Pending Invoices</h5>
                            <div class="d-flex gap-2">
                                <input type="text" id="invoiceSearch" class="form-control form-control-sm" placeholder="Search..." style="width:200px;">
                                <button class="btn btn-sm btn-outline" onclick="searchInvoices()"><i class="fas fa-search"></i></button>
                            </div>
                        </div>
                        <div class="card-body" id="pendingInvoicesContainer">
                            <div class="loading-spinner"><div class="spinner-border spinner-border-sm text-primary" role="status"></div></div>
                        </div>
                    </div>
                </div>

                <!-- TAB 2: Payments -->
                <div class="tab-pane" id="tab-payments" role="tabpanel">
                    <div class="card-panel">
                        <div class="card-header">
                            <h5><i class="fas fa-history me-2"></i>Recent Payments</h5>
                            <span class="text-muted small">Last 30 transactions</span>
                        </div>
                        <div class="card-body" id="recentPaymentsContainer">
                            <div class="loading-spinner"><div class="spinner-border spinner-border-sm text-primary" role="status"></div></div>
                        </div>
                    </div>
                </div>

                <!-- TAB 3: Approvals -->
                <div class="tab-pane" id="tab-approvals" role="tabpanel">
                    <div class="card-panel">
                        <div class="card-header">
                            <h5><i class="fas fa-check-double me-2"></i>Pending Approvals</h5>
                            <div class="d-flex gap-2">
                                <select id="approvalFilter" class="form-select form-select-sm" style="width:auto;" onchange="loadApprovals()">
                                    <option value="all">All Types</option>
                                    <option value="lab">Lab Orders</option>
                                    <option value="radiology">Radiology</option>
                                    <option value="prescription">Prescriptions</option>
                                    <option value="procedure">Procedures</option>
                                    <option value="insurance">Insurance Claims</option>
                                    <option value="expense">Expenses</option>
                                    <option value="management">Management</option>
                                    <option value="unverified">Unverified Payments</option>
                                </select>
                                <button class="btn btn-sm btn-success" onclick="bulkApprove()"><i class="fas fa-check-double"></i> Bulk Approve</button>
                            </div>
                        </div>
                        <div class="card-body" id="approvalsContainer">
                            <div class="loading-spinner"><div class="spinner-border spinner-border-sm text-primary" role="status"></div></div>
                        </div>
                    </div>
                </div>

                <!-- TAB 4: Cash Closures -->
                <div class="tab-pane" id="tab-closures" role="tabpanel">
                    <div class="card-panel">
                        <div class="card-header">
                            <h5><i class="fas fa-cash-register me-2"></i>Cash Closure History</h5>
                            <button class="btn btn-sm btn-outline" onclick="loadClosureHistory()"><i class="fas fa-sync-alt"></i> Refresh</button>
                        </div>
                        <div class="card-body" id="closureHistoryContainer">
                            <div class="loading-spinner"><div class="spinner-border spinner-border-sm text-primary" role="status"></div></div>
                        </div>
                    </div>
                </div>

                <!-- TAB 5: Analytics -->
                <div class="tab-pane" id="tab-analytics" role="tabpanel">
                    <div class="row">
                        <div class="col-lg-6 mb-3">
                            <div class="card-panel">
                                <div class="card-header">
                                    <h5><i class="fas fa-chart-bar me-2"></i>Weekly Revenue Trend</h5>
                                </div>
                                <div class="card-body">
                                    <div class="chart-container"><canvas id="weeklyTrendChart"></canvas></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-6 mb-3">
                            <div class="card-panel">
                                <div class="card-header">
                                    <h5><i class="fas fa-chart-pie me-2"></i>Payment Distribution</h5>
                                </div>
                                <div class="card-body">
                                    <div class="chart-container"><canvas id="paymentDistributionChart"></canvas></div>
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

<!-- Loading Overlay -->
<div id="loadingOverlay" class="loading-overlay"><div class="spinner"></div></div>
<input type="hidden" id="csrfToken" value="<?php echo escapeHtml($csrf_token); ?>">
<input type="hidden" id="initialData" value='<?php echo $initial_data_json; ?>'>

<!-- ===================== PAYMENT MODAL ===================== -->
<div class="modal fade" id="paymentModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-hand-holding-usd me-2"></i>Process Payment</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="paymentForm">
                    <input type="hidden" name="invoice_id" id="payInvoiceId">
                    <input type="hidden" name="csrf_token" value="<?php echo escapeHtml($csrf_token); ?>">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Invoice Number</label>
                            <input type="text" class="form-control" id="payInvoiceNumber" readonly>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Patient</label>
                            <input type="text" class="form-control" id="payPatientName" readonly>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Total Amount</label>
                            <input type="text" class="form-control" id="payTotalAmount" readonly>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Paid Amount</label>
                            <input type="text" class="form-control" id="payPaidAmount" readonly>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Due Amount</label>
                            <input type="text" class="form-control" id="payDueAmount" readonly>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Amount Paid <span class="text-danger">*</span></label>
                            <input type="number" step="0.01" class="form-control" id="payAmount" name="amount_paid" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Payment Method <span class="text-danger">*</span></label>
                            <select class="form-select" id="payMethod" name="payment_method" required>
                                <option value="cash">Cash</option>
                                <option value="telebirr">Telebirr</option>
                                <option value="cbe_birr">CBE Birr</option>
                                <option value="credit_card">Credit Card</option>
                                <option value="debit_card">Debit Card</option>
                                <option value="mobile_money">Mobile Money</option>
                                <option value="bank_transfer">Bank Transfer</option>
                                <option value="insurance_claim">Insurance Claim</option>
                                <option value="check">Check</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Transaction Reference</label>
                            <input type="text" class="form-control" id="payTransactionRef" name="transaction_reference" placeholder="Optional">
                        </div>
                        <div class="col-md-12">
                            <label class="form-label fw-semibold">Notes</label>
                            <input type="text" class="form-control" id="payNotes" name="notes" placeholder="Optional">
                        </div>
                        <div class="col-md-12">
                            <div class="form-check">
                                <input type="checkbox" class="form-check-input" id="payFullPayment" name="is_full_payment" value="1">
                                <label class="form-check-label" for="payFullPayment">Mark as Full Payment</label>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="processPayment()"><i class="fas fa-check"></i> Process Payment</button>
            </div>
        </div>
    </div>
</div>

<!-- ===================== INVOICE MODAL ===================== -->
<div class="modal fade" id="invoiceModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-file-invoice me-2"></i>Create Invoice</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="invoiceForm">
                    <input type="hidden" name="csrf_token" value="<?php echo escapeHtml($csrf_token); ?>">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Patient <span class="text-danger">*</span></label>
                            <select class="form-select" id="invPatient" name="patient_id" style="width:100%;"></select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Visit (Optional)</label>
                            <select class="form-select" id="invVisit" name="visit_id">
                                <option value="">Select visit...</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Billing Type</label>
                            <select class="form-select" id="invBillingType" name="billing_type">
                                <option value="cash">Cash</option>
                                <option value="insurance">Insurance</option>
                                <option value="corporate">Corporate</option>
                                <option value="package">Package</option>
                                <option value="free_treatment">Free Treatment</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Discount (ETB)</label>
                            <input type="number" step="0.01" class="form-control" id="invDiscount" name="discount_amount" value="0">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Department</label>
                            <select class="form-select" id="invDepartment" name="department_id">
                                <option value="">Select...</option>
                            </select>
                        </div>
                        <div class="col-md-12">
                            <label class="form-label fw-semibold">Items <span class="text-danger">*</span></label>
                            <div id="invoiceItemsContainer">
                                <div class="invoice-item-row">
                                    <div class="row g-2">
                                        <div class="col-md-5"><input type="text" class="form-control" name="item_service[]" placeholder="Service name" required></div>
                                        <div class="col-md-3"><input type="number" step="0.01" class="form-control" name="item_amount[]" placeholder="Amount" required></div>
                                        <div class="col-md-2"><input type="number" class="form-control" name="item_quantity[]" placeholder="Qty" value="1"></div>
                                        <div class="col-md-2"><button type="button" class="btn btn-danger btn-sm" onclick="removeInvoiceItem(this)"><i class="fas fa-times"></i></button></div>
                                    </div>
                                </div>
                            </div>
                            <button type="button" class="btn btn-outline btn-sm mt-2" onclick="addInvoiceItem()"><i class="fas fa-plus"></i> Add Item</button>
                        </div>
                        <div class="col-md-12">
                            <label class="form-label fw-semibold">Notes</label>
                            <input type="text" class="form-control" id="invNotes" name="notes" placeholder="Optional">
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-success" onclick="createInvoice()"><i class="fas fa-file-invoice"></i> Create Invoice</button>
            </div>
        </div>
    </div>
</div>

<!-- ===================== CASH CLOSURE MODAL ===================== -->
<div class="modal fade" id="cashClosureModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-cash-register me-2"></i>Cash Closure</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="cashClosureForm">
                    <input type="hidden" name="csrf_token" value="<?php echo escapeHtml($csrf_token); ?>">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Closure Date</label>
                        <input type="date" class="form-control" name="closure_date" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Physical Cash Count (ETB) <span class="text-danger">*</span></label>
                        <input type="number" step="0.01" class="form-control" name="physical_cash" id="closePhysicalCash" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Notes</label>
                        <input type="text" class="form-control" name="notes" placeholder="Optional">
                    </div>
                    <div class="alert alert-info">
                        <strong>System Totals:</strong><br>
                        Cash: <span id="closeSystemCash">0.00</span> ETB<br>
                        Total Revenue: <span id="closeSystemRevenue">0.00</span> ETB<br>
                        Transactions: <span id="closeTransactionCount">0</span>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-warning" onclick="performCashClosure()"><i class="fas fa-check"></i> Close Cash</button>
            </div>
        </div>
    </div>
</div>

<!-- ===================== RECEIPT MODAL ===================== -->
<div class="modal fade" id="receiptModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-receipt me-2"></i>Payment Receipt</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="receiptContent" class="receipt-preview text-center">
                    <h6>Central General Hospital</h6>
                    <p class="small text-muted">Bole Road, Addis Ababa, Ethiopia</p>
                    <hr>
                    <div class="text-start" id="receiptDetails"></div>
                    <hr>
                    <p class="small text-muted">Thank you for choosing us!</p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
            </div>
        </div>
    </div>
</div>

<!-- ===================== DAILY TRANSACTIONS MODAL ===================== -->
<div class="modal fade" id="dailyTransactionsModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-calendar-day me-2"></i>Daily Transactions</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row mb-3">
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Date</label>
                        <input type="date" class="form-control" id="transDate" value="<?php echo date('Y-m-d'); ?>" onchange="loadDailyTransactions()">
                    </div>
                    <div class="col-md-8 text-end">
                        <h5>Total: <span id="transTotal">0.00</span> ETB | <span id="transCount">0</span> transactions</h5>
                        <small>Cash: <span id="transCashTotal">0.00</span> | Digital: <span id="transDigitalTotal">0.00</span> | Card: <span id="transCardTotal">0.00</span></small>
                    </div>
                </div>
                <div id="dailyTransContainer" style="max-height:500px; overflow-y:auto;">
                    <div class="loading-spinner"><div class="spinner-border spinner-border-sm text-primary" role="status"></div></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary" onclick="loadDailyTransactions()"><i class="fas fa-sync-alt"></i> Refresh</button>
            </div>
        </div>
    </div>
</div>

<script>
// ============================================
// CONFIGURATION
// ============================================
const csrfToken = $('#csrfToken').val();
const INITIAL_DATA = JSON.parse($('#initialData').val());
const AJAX_URL = window.location.pathname + '?ajax=cashier_action&csrf_token=' + encodeURIComponent(csrfToken);
let weeklyChart = null;
let paymentChart = null;
let autoRefreshInterval = null;

// ============================================
// UTILITY FUNCTIONS
// ============================================
function escapeHtml(str) { if (!str) return ''; return String(str).replace(/[&<>]/g, m => m === '&' ? '&amp;' : (m === '<' ? '&lt;' : '&gt;')); }
function formatMoney(amount) { return parseFloat(amount || 0).toFixed(2); }

function showToast(message, type = 'success') {
    const toast = document.createElement('div');
    toast.className = `toast-notification ${type === 'error' ? 'toast-error' : ''}`;
    toast.innerHTML = `<i class="fas fa-${type === 'error' ? 'exclamation-circle' : 'check-circle'}"></i> <span>${escapeHtml(message)}</span>`;
    document.body.appendChild(toast);
    setTimeout(() => toast.remove(), 3000);
}

function showLoading(show) { document.getElementById('loadingOverlay').classList.toggle('active', show); }

function getStatusBadge(status) {
    const map = {
        'paid': 'badge-paid', 'unpaid': 'badge-unpaid', 'partial': 'badge-partial',
        'cancelled': 'badge-cancelled', 'pending': 'badge-pending', 'approved': 'badge-approved',
        'rejected': 'badge-rejected', 'draft': 'badge-draft', 'submitted': 'badge-submitted',
        'pending_payment': 'badge-pending', 'verified': 'badge-verified', 'unverified': 'badge-unverified'
    };
    const display = status === 'pending_payment' ? 'Pending Payment' : (status || 'Unknown');
    return `<span class="badge ${map[status] || ''}">${display}</span>`;
}

function getMethodBadge(method) {
    const map = {
        'cash': 'method-cash', 'telebirr': 'method-mobile', 'cbe_birr': 'method-mobile',
        'credit_card': 'method-card', 'debit_card': 'method-card', 'mobile_money': 'method-mobile',
        'bank_transfer': 'method-bank', 'insurance_claim': 'method-insurance', 'check': 'method-bank'
    };
    return `<span class="method-badge ${map[method] || ''}">${escapeHtml(method || 'Unknown')}</span>`;
}

function getTypeBadge(type) {
    const map = {
        'lab': 'type-lab', 'radiology': 'type-radiology', 'prescription': 'type-prescription',
        'procedure': 'type-procedure', 'insurance': 'type-insurance', 'expense': 'type-expense',
        'management': 'type-management', 'unverified': 'type-unverified'
    };
    const labels = {
        'lab': 'Lab', 'radiology': 'Radiology', 'prescription': 'Rx',
        'procedure': 'Procedure', 'insurance': 'Insurance', 'expense': 'Expense',
        'management': 'Management', 'unverified': 'Unverified'
    };
    return `<span class="type-badge ${map[type] || ''}">${labels[type] || type}</span>`;
}

// ============================================
// INITIALIZATION
// ============================================
$(document).ready(function() {
    initTabs();
    updateKPIs(INITIAL_DATA);
    loadPendingInvoices();
    loadRecentPayments();
    loadApprovals();
    loadClosureHistory();
    loadAnalytics();
    
    // Auto-refresh every 30 seconds
    if (autoRefreshInterval) clearInterval(autoRefreshInterval);
    autoRefreshInterval = setInterval(refreshDashboard, 30000);
    
    // Invoice search on enter
    $('#invoiceSearch').on('keypress', function(e) { if (e.which === 13) searchInvoices(); });
});

function initTabs() {
    $('.nav-tabs-custom .nav-link').on('click', function(e) {
        e.preventDefault();
        const tabId = $(this).data('tab');
        $('.nav-tabs-custom .nav-link').removeClass('active');
        $(this).addClass('active');
        $('.tab-pane').removeClass('active');
        $('#' + tabId).addClass('active');
        
        // Load data for specific tabs
        if (tabId === 'tab-analytics') loadAnalytics();
        if (tabId === 'tab-approvals') loadApprovals();
        if (tabId === 'tab-closures') loadClosureHistory();
    });
}

function updateKPIs(data) {
    const today = data.today || {};
    const pending = data.pending || {};
    const approvals = data.approvals || {};
    
    $('#todayTotal').text(formatMoney(today.total));
    $('#todayCount').text(today.transactions + ' transactions');
    $('#pendingCount').text(pending.count);
    $('#pendingAmount').text('Due: ' + formatMoney(pending.amount) + ' ETB');
    $('#approvalTotal').text(approvals.total);
    $('#approvalBadge').text(approvals.total);
    $('#todayNet').text(formatMoney(today.net));
    $('#netExpenses').text('Expenses: ' + formatMoney(today.expenses));
}

// ============================================
// INVOICE FUNCTIONS
// ============================================
function loadPendingInvoices() {
    const container = $('#pendingInvoicesContainer');
    container.html('<div class="loading-spinner"><div class="spinner-border spinner-border-sm text-primary" role="status"></div></div>');
    
    $.ajax({
        url: AJAX_URL + '&action=fetch_pending_invoices',
        method: 'GET',
        dataType: 'json',
        success: function(res) {
            if (res.success && res.invoices && res.invoices.length > 0) {
                let html = `<table class="table-financial">
                    <thead><tr>
                        <th>Invoice</th><th>Patient</th><th>MRN</th><th class="text-end">Total</th>
                        <th class="text-end">Due</th><th>Status</th><th>Action</th>
                    </tr></thead><tbody>`;
                res.invoices.forEach(inv => {
                    html += `<tr>
                        <td><strong>${escapeHtml(inv.invoice_number)}</strong></td>
                        <td>${escapeHtml(inv.patient_name)}</td>
                        <td>${escapeHtml(inv.mr_number)}</td>
                        <td class="text-end">${formatMoney(inv.total_amount)}</td>
                        <td class="text-end"><strong>${formatMoney(inv.due_amount)}</strong></td>
                        <td>${getStatusBadge(inv.payment_status)}</td>
                        <td><button class="btn btn-primary btn-sm" onclick="openPaymentModal(${inv.id})"><i class="fas fa-hand-holding-usd"></i> Pay</button></td>
                    </tr>`;
                });
                html += '</tbody></table>';
                container.html(html);
            } else {
                container.html('<div class="empty-state"><i class="fas fa-check-circle"></i><p>No pending invoices</p></div>');
            }
        },
        error: function() { container.html('<div class="empty-state"><i class="fas fa-exclamation-circle"></i><p>Error loading invoices</p></div>'); }
    });
}

function searchInvoices() {
    const search = $('#invoiceSearch').val();
    if (search.length < 2) { loadPendingInvoices(); return; }
    
    const container = $('#pendingInvoicesContainer');
    container.html('<div class="loading-spinner"><div class="spinner-border spinner-border-sm text-primary" role="status"></div></div>');
    
    $.ajax({
        url: AJAX_URL + '&action=search_invoices&search=' + encodeURIComponent(search),
        method: 'GET',
        dataType: 'json',
        success: function(res) {
            if (res.success && res.invoices && res.invoices.length > 0) {
                let html = `<table class="table-financial">
                    <thead><tr><th>Invoice</th><th>Patient</th><th>MRN</th><th class="text-end">Total</th>
                        <th class="text-end">Due</th><th>Status</th><th>Action</th></tr></thead><tbody>`;
                res.invoices.forEach(inv => {
                    html += `<tr>
                        <td><strong>${escapeHtml(inv.invoice_number)}</strong></td>
                        <td>${escapeHtml(inv.patient_name)}</td>
                        <td>${escapeHtml(inv.mr_number)}</td>
                        <td class="text-end">${formatMoney(inv.total_amount)}</td>
                        <td class="text-end"><strong>${formatMoney(inv.due_amount)}</strong></td>
                        <td>${getStatusBadge(inv.payment_status)}</td>
                        <td><button class="btn btn-primary btn-sm" onclick="openPaymentModal(${inv.id})"><i class="fas fa-hand-holding-usd"></i> Pay</button></td>
                    </tr>`;
                });
                html += '</tbody></table>';
                container.html(html);
            } else {
                container.html('<div class="empty-state"><i class="fas fa-search"></i><p>No invoices found</p></div>');
            }
        },
        error: function() { container.html('<div class="empty-state"><i class="fas fa-exclamation-circle"></i><p>Search failed</p></div>'); }
    });
}

// ============================================
// PAYMENT FUNCTIONS
// ============================================
function openPaymentModal(invoiceId = null) {
    if (invoiceId) {
        $.ajax({
            url: AJAX_URL + '&action=fetch_pending_invoices',
            method: 'GET',
            dataType: 'json',
            success: function(res) {
                if (res.success && res.invoices) {
                    const inv = res.invoices.find(i => i.id === invoiceId);
                    if (inv) populatePaymentModal(inv);
                }
                $('#paymentModal').modal('show');
            },
            error: function() { $('#paymentModal').modal('show'); }
        });
    } else {
        $('#payInvoiceId').val('');
        $('#payInvoiceNumber').val('Select an invoice');
        $('#payPatientName').val('');
        $('#payTotalAmount').val('0.00');
        $('#payPaidAmount').val('0.00');
        $('#payDueAmount').val('0.00');
        $('#payAmount').val('');
        $('#payMethod').val('cash');
        $('#payTransactionRef').val('');
        $('#payNotes').val('');
        $('#payFullPayment').prop('checked', false);
        $('#paymentModal').modal('show');
    }
}

function populatePaymentModal(inv) {
    $('#payInvoiceId').val(inv.id);
    $('#payInvoiceNumber').val(inv.invoice_number);
    $('#payPatientName').val(inv.patient_name);
    $('#payTotalAmount').val(formatMoney(inv.total_amount));
    $('#payPaidAmount').val(formatMoney(inv.paid_amount));
    $('#payDueAmount').val(formatMoney(inv.due_amount));
    $('#payAmount').val(inv.due_amount);
}

function processPayment() {
    const invoiceId = $('#payInvoiceId').val();
    if (!invoiceId) { showToast('Please select an invoice', 'error'); return; }
    const amount = parseFloat($('#payAmount').val());
    if (isNaN(amount) || amount <= 0) { showToast('Please enter a valid amount', 'error'); return; }
    const method = $('#payMethod').val();
    if (!method) { showToast('Please select a payment method', 'error'); return; }

    showLoading(true);
    $.ajax({
        url: AJAX_URL + '&action=process_payment',
        method: 'POST',
        dataType: 'json',
        data: {
            invoice_id: invoiceId,
            amount_paid: amount,
            payment_method: method,
            transaction_reference: $('#payTransactionRef').val(),
            notes: $('#payNotes').val(),
            is_full_payment: $('#payFullPayment').is(':checked') ? 1 : 0
        },
        success: function(res) {
            showLoading(false);
            if (res.success) {
                showToast(res.message);
                $('#paymentModal').modal('hide');
                refreshDashboard();
                showReceipt({
                    receipt_number: res.receipt_number,
                    amount_paid: res.amount_paid || amount,
                    new_balance: res.new_balance || 0,
                    patient_name: $('#payPatientName').val(),
                    invoice_number: $('#payInvoiceNumber').val(),
                    payment_method: $('#payMethod option:selected').text()
                });
            } else {
                showToast(res.message || 'Payment failed', 'error');
            }
        },
        error: function() { showLoading(false); showToast('Network error', 'error'); }
    });
}

function showReceipt(data) {
    const html = `
        <p><strong>Receipt #:</strong> ${escapeHtml(data.receipt_number)}</p>
        <p><strong>Date:</strong> ${new Date().toLocaleString()}</p>
        <p><strong>Patient:</strong> ${escapeHtml(data.patient_name)}</p>
        <p><strong>Invoice:</strong> ${escapeHtml(data.invoice_number)}</p>
        <p><strong>Amount Paid:</strong> ${formatMoney(data.amount_paid)} ETB</p>
        <p><strong>Payment Method:</strong> ${escapeHtml(data.payment_method)}</p>
        <p><strong>New Balance:</strong> ${formatMoney(data.new_balance)} ETB</p>
        <p><strong>Cashier:</strong> <?php echo escapeHtml($user_name); ?></p>
    `;
    $('#receiptDetails').html(html);
    $('#receiptModal').modal('show');
}

// ============================================
// RECENT PAYMENTS
// ============================================
function loadRecentPayments() {
    const container = $('#recentPaymentsContainer');
    container.html('<div class="loading-spinner"><div class="spinner-border spinner-border-sm text-primary" role="status"></div></div>');
    
    $.ajax({
        url: AJAX_URL + '&action=fetch_recent_payments',
        method: 'GET',
        dataType: 'json',
        success: function(res) {
            if (res.success && res.payments && res.payments.length > 0) {
                let html = `<table class="table-financial">
                    <thead><tr><th>Receipt</th><th>Patient</th><th class="text-end">Amount</th><th>Method</th><th>Cashier</th><th>Time</th></tr></thead><tbody>`;
                res.payments.forEach(p => {
                    const verifiedBadge = p.is_verified ? '✓' : '!';
                    html += `<tr>
                        <td>${escapeHtml(p.receipt_number)} ${verifiedBadge}</td>
                        <td>${escapeHtml(p.patient_name)}</td>
                        <td class="text-end"><strong>${formatMoney(p.amount_paid)}</strong></td>
                        <td>${getMethodBadge(p.payment_method)}</td>
                        <td>${escapeHtml(p.cashier_name)}</td>
                        <td>${new Date(p.payment_date).toLocaleTimeString()}</td>
                    </tr>`;
                });
                html += '</tbody></table>';
                container.html(html);
            } else {
                container.html('<div class="empty-state"><i class="fas fa-history"></i><p>No recent payments</p></div>');
            }
        },
        error: function() { container.html('<div class="empty-state"><i class="fas fa-exclamation-circle"></i><p>Error loading payments</p></div>'); }
    });
}

// ============================================
// APPROVAL FUNCTIONS
// ============================================
function loadApprovals() {
    const type = $('#approvalFilter').val();
    const container = $('#approvalsContainer');
    container.html('<div class="loading-spinner"><div class="spinner-border spinner-border-sm text-primary" role="status"></div></div>');
    
    $.ajax({
        url: AJAX_URL + '&action=fetch_approvals&type=' + type,
        method: 'GET',
        dataType: 'json',
        success: function(res) {
            if (res.success && res.approvals && res.approvals.length > 0) {
                let html = `<table class="table-financial">
                    <thead><tr>
                        <th><input type="checkbox" id="selectAllApprovals" onclick="toggleSelectAll()"></th>
                        <th>Type</th><th>Reference</th><th>Patient</th><th>Description</th>
                        <th>Status</th><th>Date</th><th>Actions</th>
                    </tr></thead><tbody>`;
                res.approvals.forEach(a => {
                    html += `<tr>
                        <td><input type="checkbox" class="approval-checkbox" data-type="${a.approval_type}" data-id="${a.id}"></td>
                        <td>${getTypeBadge(a.approval_type)}</td>
                        <td>${escapeHtml(a.reference_number)}</td>
                        <td>${escapeHtml(a.patient_name || 'N/A')}</td>
                        <td>${escapeHtml(a.description)}</td>
                        <td>${getStatusBadge(a.status)}</td>
                        <td>${new Date(a.created_at).toLocaleDateString()}</td>
                        <td>
                            <button class="btn btn-success btn-sm" onclick="approveRequest('${a.approval_type}', ${a.id}, 1)"><i class="fas fa-check"></i></button>
                            <button class="btn btn-danger btn-sm" onclick="approveRequest('${a.approval_type}', ${a.id}, 0)"><i class="fas fa-times"></i></button>
                        </td>
                    </tr>`;
                });
                html += '</tbody></table>';
                container.html(html);
            } else {
                container.html('<div class="empty-state"><i class="fas fa-check-double"></i><p>No pending approvals</p></div>');
            }
        },
        error: function() { container.html('<div class="empty-state"><i class="fas fa-exclamation-circle"></i><p>Error loading approvals</p></div>'); }
    });
}

function toggleSelectAll() {
    $('.approval-checkbox').prop('checked', $('#selectAllApprovals').is(':checked'));
}

function approveRequest(type, id, approve) {
    const action = approve ? 'Approve' : 'Reject';
    Swal.fire({
        title: `${action} Request`,
        text: `Are you sure you want to ${action.toLowerCase()} this ${type} request?`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: `Yes, ${action}`,
        confirmButtonColor: approve ? '#059669' : '#dc2626',
        cancelButtonText: 'Cancel'
    }).then(result => {
        if (result.isConfirmed) {
            showLoading(true);
            $.ajax({
                url: AJAX_URL + '&action=approve_request',
                method: 'POST',
                dataType: 'json',
                data: { type: type, id: id, approve: approve ? 1 : 0, notes: '' },
                success: function(res) {
                    showLoading(false);
                    if (res.success) { showToast(res.message); loadApprovals(); refreshDashboard(); }
                    else showToast(res.message || 'Action failed', 'error');
                },
                error: function() { showLoading(false); showToast('Network error', 'error'); }
            });
        }
    });
}

function bulkApprove() {
    const selected = [];
    $('.approval-checkbox:checked').each(function() {
        selected.push({ type: $(this).data('type'), id: $(this).data('id') });
    });
    if (selected.length === 0) { showToast('Please select items to approve', 'error'); return; }
    
    Swal.fire({
        title: 'Bulk Approve',
        text: `Approve ${selected.length} item(s)?`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Yes, Approve All',
        confirmButtonColor: '#059669'
    }).then(result => {
        if (result.isConfirmed) {
            showLoading(true);
            let approved = 0, failed = 0;
            const promises = selected.map(item => {
                return $.ajax({
                    url: AJAX_URL + '&action=approve_request',
                    method: 'POST',
                    dataType: 'json',
                    data: { type: item.type, id: item.id, approve: 1, notes: '' }
                }).then(res => { if (res.success) approved++; else failed++; });
            });
            Promise.all(promises).then(() => {
                showLoading(false);
                showToast(`Bulk approve: ${approved} approved, ${failed} failed`);
                loadApprovals();
                refreshDashboard();
            });
        }
    });
}

// ============================================
// CASH CLOSURE FUNCTIONS
// ============================================
function openCashClosureModal() {
    $.ajax({
        url: AJAX_URL + '&action=fetch_metrics',
        method: 'GET',
        dataType: 'json',
        success: function(res) {
            if (res.success) {
                $('#closeSystemCash').text(formatMoney(res.today.cash));
                $('#closeSystemRevenue').text(formatMoney(res.today.total));
                $('#closeTransactionCount').text(res.today.transactions);
                $('#closePhysicalCash').val(res.today.cash);
            }
            $('#cashClosureModal').modal('show');
        },
        error: function() { $('#cashClosureModal').modal('show'); }
    });
}

function performCashClosure() {
    const physicalCash = parseFloat($('#closePhysicalCash').val());
    if (isNaN(physicalCash) || physicalCash < 0) {
        showToast('Please enter a valid physical cash count', 'error');
        return;
    }
    
    showLoading(true);
    $.ajax({
        url: AJAX_URL + '&action=perform_cash_closure',
        method: 'POST',
        dataType: 'json',
        data: {
            closure_date: $('input[name="closure_date"]').val(),
            physical_cash: physicalCash,
            notes: $('input[name="notes"]').val()
        },
        success: function(res) {
            showLoading(false);
            if (res.success) {
                showToast(res.message);
                $('#cashClosureModal').modal('hide');
                refreshDashboard();
                Swal.fire({
                    title: 'Cash Closure Complete',
                    html: `
                        <p><strong>Total Cash:</strong> ${formatMoney(res.total_cash)} ETB</p>
                        <p><strong>Difference:</strong> ${formatMoney(res.difference)} ETB</p>
                        <p><strong>Total Revenue:</strong> ${formatMoney(res.total_revenue)} ETB</p>
                        <p><strong>Invoices:</strong> ${res.invoice_count}</p>
                    `,
                    icon: 'success',
                    confirmButtonText: 'OK',
                    confirmButtonColor: '#4361ee'
                });
            } else {
                showToast(res.message || 'Closure failed', 'error');
            }
        },
        error: function() { showLoading(false); showToast('Network error', 'error'); }
    });
}

function loadClosureHistory() {
    const container = $('#closureHistoryContainer');
    container.html('<div class="loading-spinner"><div class="spinner-border spinner-border-sm text-primary" role="status"></div></div>');
    
    $.ajax({
        url: AJAX_URL + '&action=fetch_closure_history',
        method: 'GET',
        dataType: 'json',
        success: function(res) {
            if (res.success && res.closures && res.closures.length > 0) {
                let html = `<table class="table-financial">
                    <thead><tr><th>Date</th><th>Closed By</th><th class="text-end">System Cash</th>
                        <th class="text-end">Physical Cash</th><th class="text-end">Diff</th>
                        <th class="text-end">Revenue</th><th>Status</th></tr></thead><tbody>`;
                res.closures.forEach(c => {
                    const diff = parseFloat(c.difference);
                    const diffClass = diff === 0 ? '' : (diff > 0 ? 'text-success' : 'text-danger');
                    html += `<tr>
                        <td>${escapeHtml(c.closure_date)}</td>
                        <td>${escapeHtml(c.closed_by)}</td>
                        <td class="text-end">${formatMoney(c.total_cash_system)}</td>
                        <td class="text-end">${formatMoney(c.physical_cash)}</td>
                        <td class="text-end ${diffClass}">${formatMoney(c.difference)}</td>
                        <td class="text-end">${formatMoney(c.total_revenue_system)}</td>
                        <td><span class="badge badge-approved">Closed</span></td>
                    </tr>`;
                });
                html += '</tbody></table>';
                container.html(html);
            } else {
                container.html('<div class="empty-state"><i class="fas fa-cash-register"></i><p>No closures found</p></div>');
            }
        },
        error: function() { container.html('<div class="empty-state"><i class="fas fa-exclamation-circle"></i><p>Error loading closures</p></div>'); }
    });
}

// ============================================
// ANALYTICS FUNCTIONS
// ============================================
function loadAnalytics() {
    $.ajax({
        url: AJAX_URL + '&action=fetch_metrics',
        method: 'GET',
        dataType: 'json',
        success: function(res) {
            if (res.success) {
                renderWeeklyChart(res.weekly_trend);
                renderPaymentChart(res.payment_distribution);
            }
        },
        error: function() { console.error('Failed to load analytics'); }
    });
}

function renderWeeklyChart(data) {
    const ctx = document.getElementById('weeklyTrendChart').getContext('2d');
    const labels = data.map(d => new Date(d.date).toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric' }));
    const values = data.map(d => parseFloat(d.total || 0));
    
    if (weeklyChart) weeklyChart.destroy();
    
    weeklyChart = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: labels.length ? labels : ['No Data'],
            datasets: [{
                label: 'Revenue (ETB)',
                data: values.length ? values : [0],
                backgroundColor: 'rgba(67, 97, 238, 0.6)',
                borderColor: '#4361ee',
                borderWidth: 2,
                borderRadius: 4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: function(context) { return 'ETB ' + formatMoney(context.raw); }
                    }
                }
            },
            scales: {
                y: { beginAtZero: true, ticks: { callback: function(value) { return 'ETB ' + formatMoney(value); } } }
            }
        }
    });
}

function renderPaymentChart(data) {
    const ctx = document.getElementById('paymentDistributionChart').getContext('2d');
    const labels = data.map(d => d.payment_method || 'Unknown');
    const values = data.map(d => parseFloat(d.total || 0));
    const colors = ['#4361ee', '#06d6a0', '#f9c74f', '#ef476f', '#4cc9f0', '#7209b7', '#f9844a'];
    
    if (paymentChart) paymentChart.destroy();
    
    if (values.length === 0 || values.every(v => v === 0)) {
        paymentChart = new Chart(ctx, {
            type: 'doughnut',
            data: { labels: ['No Data'], datasets: [{ data: [1], backgroundColor: ['#e5e7eb'] }] },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } } }
        });
        return;
    }
    
    paymentChart = new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: labels,
            datasets: [{
                data: values,
                backgroundColor: colors.slice(0, labels.length),
                borderWidth: 0
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'bottom', labels: { usePointStyle: true, padding: 12 } },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            const total = context.dataset.data.reduce((a, b) => a + b, 0);
                            const percentage = total > 0 ? (context.raw / total * 100).toFixed(1) : 0;
                            return context.label + ': ETB ' + formatMoney(context.raw) + ' (' + percentage + '%)';
                        }
                    }
                }
            }
        }
    });
}

// ============================================
// DAILY TRANSACTIONS
// ============================================
function viewDailyTransactions() {
    $('#transDate').val('<?php echo date('Y-m-d'); ?>');
    loadDailyTransactions();
    $('#dailyTransactionsModal').modal('show');
}

function loadDailyTransactions() {
    const date = $('#transDate').val();
    if (!date) return;
    
    const container = $('#dailyTransContainer');
    container.html('<div class="loading-spinner"><div class="spinner-border spinner-border-sm text-primary" role="status"></div></div>');
    
    $.ajax({
        url: AJAX_URL + '&action=fetch_daily_transactions&date=' + date,
        method: 'GET',
        dataType: 'json',
        success: function(res) {
            if (res.success && res.transactions && res.transactions.length > 0) {
                let html = `<table class="table-financial">
                    <thead><tr><th>Receipt</th><th>Patient</th><th>MRN</th><th>Invoice</th>
                        <th class="text-end">Amount</th><th>Method</th><th>Cashier</th><th>Time</th></tr></thead><tbody>`;
                res.transactions.forEach(t => {
                    html += `<tr>
                        <td>${escapeHtml(t.receipt_number)}</td>
                        <td>${escapeHtml(t.patient_name)}</td>
                        <td>${escapeHtml(t.mr_number)}</td>
                        <td>${escapeHtml(t.invoice_number)}</td>
                        <td class="text-end"><strong>${formatMoney(t.amount_paid)}</strong></td>
                        <td>${getMethodBadge(t.payment_method)}</td>
                        <td>${escapeHtml(t.cashier_name)}</td>
                        <td>${new Date(t.payment_date).toLocaleTimeString()}</td>
                    </tr>`;
                });
                html += '</tbody></table>';
                container.html(html);
                $('#transTotal').text(formatMoney(res.total));
                $('#transCount').text(res.count);
                $('#transCashTotal').text(formatMoney(res.cash_total || 0));
                $('#transDigitalTotal').text(formatMoney(res.digital_total || 0));
                $('#transCardTotal').text(formatMoney(res.card_total || 0));
            } else {
                container.html('<div class="empty-state"><i class="fas fa-calendar-day"></i><p>No transactions for this date</p></div>');
                $('#transTotal').text('0.00');
                $('#transCount').text('0');
            }
        },
        error: function() { container.html('<div class="empty-state"><i class="fas fa-exclamation-circle"></i><p>Error loading transactions</p></div>'); }
    });
}

// ============================================
// VIEW FUNCTIONS
// ============================================
function viewPendingInvoices() {
    $('.nav-tabs-custom .nav-link[data-tab="tab-invoices"]').click();
}

// ============================================
// INVOICE MODAL
// ============================================
function openInvoiceModal() {
    $('#invoiceModal').modal('show');
    
    if ($('#invPatient').data('select2')) $('#invPatient').select2('destroy');
    $('#invPatient').select2({
        placeholder: 'Search patient...',
        minimumInputLength: 2,
        ajax: {
            url: AJAX_URL + '&action=search_patients',
            dataType: 'json',
            delay: 300,
            data: function(params) { return { search: params.term }; },
            processResults: function(data) {
                if (!data.success) return { results: [] };
                return { results: data.map(p => ({ id: p.id, text: p.text })) };
            }
        },
        allowClear: true
    });
    
    $('#invVisit').val('');
    $('#invBillingType').val('cash');
    $('#invDiscount').val('0');
    $('#invNotes').val('');
    $('#invoiceItemsContainer').html(`
        <div class="invoice-item-row">
            <div class="row g-2">
                <div class="col-md-5"><input type="text" class="form-control" name="item_service[]" placeholder="Service name" required></div>
                <div class="col-md-3"><input type="number" step="0.01" class="form-control" name="item_amount[]" placeholder="Amount" required></div>
                <div class="col-md-2"><input type="number" class="form-control" name="item_quantity[]" placeholder="Qty" value="1"></div>
                <div class="col-md-2"><button type="button" class="btn btn-danger btn-sm" onclick="removeInvoiceItem(this)"><i class="fas fa-times"></i></button></div>
            </div>
        </div>
    `);
}

function addInvoiceItem() {
    const row = `
        <div class="invoice-item-row">
            <div class="row g-2">
                <div class="col-md-5"><input type="text" class="form-control" name="item_service[]" placeholder="Service name" required></div>
                <div class="col-md-3"><input type="number" step="0.01" class="form-control" name="item_amount[]" placeholder="Amount" required></div>
                <div class="col-md-2"><input type="number" class="form-control" name="item_quantity[]" placeholder="Qty" value="1"></div>
                <div class="col-md-2"><button type="button" class="btn btn-danger btn-sm" onclick="removeInvoiceItem(this)"><i class="fas fa-times"></i></button></div>
            </div>
        </div>
    `;
    $('#invoiceItemsContainer').append(row);
}

function removeInvoiceItem(btn) {
    if ($('.invoice-item-row').length <= 1) {
        showToast('At least one item is required', 'error');
        return;
    }
    $(btn).closest('.invoice-item-row').remove();
}

function createInvoice() {
    const patientId = $('#invPatient').val();
    if (!patientId) { showToast('Please select a patient', 'error'); return; }
    
    const items = [];
    let hasError = false;
    $('.invoice-item-row').each(function() {
        const service = $(this).find('input[name="item_service[]"]').val();
        const amount = parseFloat($(this).find('input[name="item_amount[]"]').val());
        const quantity = parseInt($(this).find('input[name="item_quantity[]"]').val()) || 1;
        if (service && amount > 0) {
            items.push({ service_name: service, amount: amount, quantity: quantity });
        } else if (service || amount) { hasError = true; }
    });
    if (items.length === 0) { showToast('Please add valid items', 'error'); return; }
    if (hasError) { showToast('Some items have incomplete data', 'error'); return; }
    
    showLoading(true);
    $.ajax({
        url: AJAX_URL + '&action=create_invoice',
        method: 'POST',
        dataType: 'json',
        data: {
            patient_id: patientId,
            visit_id: $('#invVisit').val() || 0,
            items: JSON.stringify(items),
            billing_type: $('#invBillingType').val(),
            discount_amount: $('#invDiscount').val() || 0,
            notes: $('#invNotes').val(),
            department_id: $('#invDepartment').val() || 0
        },
        success: function(res) {
            showLoading(false);
            if (res.success) {
                showToast(res.message);
                $('#invoiceModal').modal('hide');
                refreshDashboard();
                Swal.fire({
                    title: 'Invoice Created!',
                    html: `<p><strong>Invoice:</strong> ${escapeHtml(res.invoice_number)}</p><p><strong>Total:</strong> ${formatMoney(res.total_amount)} ETB</p>`,
                    icon: 'success',
                    confirmButtonText: 'OK',
                    confirmButtonColor: '#4361ee'
                });
            } else {
                showToast(res.message || 'Creation failed', 'error');
            }
        },
        error: function() { showLoading(false); showToast('Network error', 'error'); }
    });
}

// ============================================
// REFRESH
// ============================================
function refreshDashboard() {
    $.ajax({
        url: AJAX_URL + '&action=fetch_metrics',
        method: 'GET',
        dataType: 'json',
        success: function(res) {
            if (res.success) {
                updateKPIs(res);
                loadPendingInvoices();
                loadRecentPayments();
                loadApprovals();
                loadClosureHistory();
                if ($('#tab-analytics').hasClass('active')) loadAnalytics();
            }
        },
        error: function() { console.error('Refresh failed'); }
    });
}

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
    if (window.innerWidth > 768 && document.body.classList.contains('sidebar-enable')) {
        document.body.classList.remove('sidebar-enable');
    }
});
</script>
</body>
</html>