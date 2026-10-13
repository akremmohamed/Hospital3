<?php
/**
 * ======================================================================
 * DAILY CASH CLOSE - ENTERPRISE FINANCIAL RECONCILIATION ENGINE
 * Transactional Integrity | Shift Locking | Audit Trail | RBAC
 * ======================================================================
 */

// ============================================
// CRITICAL: No output before JSON for AJAX
// ============================================
$is_ajax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';

if ($is_ajax) {
    error_reporting(0);
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);
}

ob_start();
session_start();

// ============================================
// SECURE SESSION & RBAC
// ============================================
$user_id = (int)($_SESSION['user_id'] ?? 0);
$user_branch_id = (int)($_SESSION['branch_id'] ?? 0);
$is_global_admin = ($_SESSION['user_type'] ?? '') === 'super_admin';
$user_name = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''));

if ($user_id <= 0 || $user_branch_id <= 0) {
    if (!$is_ajax) header('Location: ../../index.php');
    else jsonResponse(false, 'Session expired. Please login again.');
    exit;
}

// ============================================
// DATABASE CONNECTION & HELPERS
// ============================================
require_once __DIR__ . '/../../config/db_connection.php';
require_once __DIR__ . '/../../includes/auth_helper.php';

date_default_timezone_set('Africa/Addis_Ababa');

if (!$pdo instanceof PDO) {
    die('Database connection failed. Contact administrator.');
}

try {
    $pdo->exec("SET time_zone = '+03:00'");
    $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
} catch (PDOException $e) {
    error_log('Timezone set failed: ' . $e->getMessage());
}

// ============================================
// RBAC PERMISSION CHECK
// ============================================
$can_close_cash = $is_global_admin || user_has_permission($pdo, 'daily_cash_close_access', $user_id);
if (!$can_close_cash) {
    if (!$is_ajax) header('Location: dashboard.php?error=unauthorized');
    else jsonResponse(false, 'Permission denied');
    exit;
}

// ============================================
// CSRF TOKEN
// ============================================
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// ============================================
// ERROR LOGGING (Secure)
// ============================================
$log_dir = __DIR__ . '/../../logs/';
if (!is_dir($log_dir)) mkdir($log_dir, 0755, true);
$error_log_path = $log_dir . 'finance_errors.log';

function secureErrorLog($message) {
    global $error_log_path;
    error_log(date('Y-m-d H:i:s') . ' - ' . $message . PHP_EOL, 3, $error_log_path);
}

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

function sanitizeInput($input) {
    return trim(htmlspecialchars((string)$input, ENT_QUOTES, 'UTF-8'));
}

function validateCsrf($submitted, $session) {
    return !empty($submitted) && !empty($session) && hash_equals($session, $submitted);
}

// ============================================
// AUDIT LOGGING FUNCTION
// ============================================
function logAudit($pdo, $userId, $action, $targetTable, $recordId, $details, $branchId) {
    try {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
        $stmt = $pdo->prepare("
            INSERT INTO audit_logs 
            (branch_id, user_id, action_type, target_table, record_id, new_values, ip_address, user_agent, created_at)
            VALUES (:branch, :user, :action, :table, :record, :details, :ip, :ua, NOW())
        ");
        $stmt->execute([
            ':branch' => $branchId,
            ':user' => $userId,
            ':action' => $action,
            ':table' => $targetTable,
            ':record' => $recordId,
            ':details' => json_encode($details),
            ':ip' => $ip,
            ':ua' => $ua
        ]);
    } catch (Exception $e) {
        secureErrorLog('Audit log failed: ' . $e->getMessage());
    }
}

// ============================================
// AJAX: PROCESS CASH CLOSURE (Atomic Transaction)
// ============================================
if ($is_ajax && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // Clear output buffers
    while (ob_get_level()) ob_end_clean();

    try {
        // CSRF Validation
        $csrf_input = $_POST['csrf_token'] ?? '';
        if (!validateCsrf($csrf_input, $csrf_token)) {
            jsonResponse(false, 'Security token mismatch. Please refresh the page.');
        }

        $physical_cash = (float)($_POST['physical_cash'] ?? 0);
        $notes = sanitizeInput($_POST['notes'] ?? '');

        if ($physical_cash < 0) {
            jsonResponse(false, 'Physical cash cannot be negative');
        }

        // Start transaction
        $pdo->beginTransaction();

        // 1. Check if already closed today (prevent duplicate)
        $checkStmt = $pdo->prepare("
            SELECT id, is_locked FROM cash_closure 
            WHERE user_id = :user_id AND closure_date = CURDATE() AND deleted_at IS NULL 
            FOR UPDATE
        ");
        $checkStmt->execute([':user_id' => $user_id]);
        if ($checkStmt->rowCount() > 0) {
            throw new Exception('Today\'s shift has already been closed.');
        }

        // 2. Fetch financial aggregates with high precision
        $stmt = $pdo->prepare("
            SELECT 
                COALESCE(SUM(CASE WHEN billing_type = 'cash' AND payment_status IN ('paid','partial') THEN paid_amount ELSE 0 END), 0) AS total_cash,
                COALESCE(SUM(CASE WHEN billing_type = 'insurance' THEN total_amount ELSE 0 END), 0) AS total_insurance,
                COALESCE(SUM(paid_amount), 0) AS total_revenue,
                COUNT(*) AS total_invoices,
                COALESCE(SUM(discount_amount), 0) AS total_discount
            FROM invoices
            WHERE branch_id = :branch_id 
                AND DATE(created_at) = CURDATE() 
                AND deleted_at IS NULL
        ");
        $stmt->execute([':branch_id' => $user_branch_id]);
        $totals = $stmt->fetch(PDO::FETCH_ASSOC);

        $total_cash = (float)$totals['total_cash'];
        $total_insurance = (float)$totals['total_insurance'];
        $total_revenue = (float)$totals['total_revenue'];
        $total_invoices = (int)$totals['total_invoices'];
        $total_discount = (float)$totals['total_discount'];

        // 3. Card & mobile payments (from payments table, more accurate)
        $cardStmt = $pdo->prepare("
            SELECT COALESCE(SUM(p.amount_paid), 0) AS total
            FROM payments p
            INNER JOIN invoices i ON p.invoice_id = i.id
            WHERE p.payment_method IN ('credit_card', 'debit_card')
                AND i.branch_id = :branch_id
                AND DATE(p.payment_date) = CURDATE()
                AND p.deleted_at IS NULL AND i.deleted_at IS NULL
        ");
        $cardStmt->execute([':branch_id' => $user_branch_id]);
        $total_card = (float)$cardStmt->fetchColumn();

        $mobileStmt = $pdo->prepare("
            SELECT COALESCE(SUM(p.amount_paid), 0) AS total
            FROM payments p
            INNER JOIN invoices i ON p.invoice_id = i.id
            WHERE p.payment_method = 'mobile_money'
                AND i.branch_id = :branch_id
                AND DATE(p.payment_date) = CURDATE()
                AND p.deleted_at IS NULL AND i.deleted_at IS NULL
        ");
        $mobileStmt->execute([':branch_id' => $user_branch_id]);
        $total_mobile = (float)$mobileStmt->fetchColumn();

        // 4. Calculate discrepancy with high precision
        $difference = round($physical_cash - $total_cash, 2);

        if (abs($difference) > 0.01 && empty($notes)) {
            throw new Exception('Discrepancy notes are required when cash difference exists.');
        }

        // 5. Create closure record
        $insertStmt = $pdo->prepare("
            INSERT INTO cash_closure 
            (user_id, branch_id, closure_date, closed_at,
             total_cash_system, total_insurance_system, total_card_system, total_mobile_system,
             total_revenue_system, total_invoices_count, physical_cash, difference, notes, is_locked)
            VALUES 
            (:user_id, :branch_id, CURDATE(), NOW(),
             :total_cash, :total_insurance, :total_card, :total_mobile,
             :total_revenue, :total_invoices, :physical_cash, :difference, :notes, 1)
        ");
        $insertStmt->execute([
            ':user_id' => $user_id,
            ':branch_id' => $user_branch_id,
            ':total_cash' => $total_cash,
            ':total_insurance' => $total_insurance,
            ':total_card' => $total_card,
            ':total_mobile' => $total_mobile,
            ':total_revenue' => $total_revenue,
            ':total_invoices' => $total_invoices,
            ':physical_cash' => $physical_cash,
            ':difference' => $difference,
            ':notes' => $notes
        ]);
        $closure_id = (int)$pdo->lastInsertId();

        // 6. (Optional) Mark shift as locked – if you have a shift_status table, update here.
        // For now, we rely on cash_closure.is_locked.

        // 7. Audit log
        logAudit($pdo, $user_id, 'CLOSE_SHIFT', 'cash_closure', $closure_id, [
            'physical_cash' => $physical_cash,
            'system_cash' => $total_cash,
            'difference' => $difference,
            'notes' => $notes,
            'total_revenue' => $total_revenue
        ], $user_branch_id);

        // 8. Commit transaction
        $pdo->commit();

        jsonResponse(true, 'Cash shift closed successfully', [
            'closure_id' => $closure_id,
            'difference' => $difference,
            'total_revenue' => $total_revenue
        ]);

    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        secureErrorLog('Cash closure PDO error: ' . $e->getMessage());
        jsonResponse(false, 'Database error occurred. Please contact administrator.');
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        secureErrorLog('Cash closure error: ' . $e->getMessage());
        jsonResponse(false, $e->getMessage());
    }
}

// ============================================
// IF NOT AJAX: DISPLAY DASHBOARD PAGE
// ============================================
while (ob_get_level()) ob_end_clean();
ob_start();

// Check if already closed today
$is_closed = false;
$existing_closure = null;
try {
    $checkStmt = $pdo->prepare("
        SELECT id, closed_at, total_cash_system, physical_cash, difference, notes, is_locked
        FROM cash_closure 
        WHERE user_id = :user_id AND closure_date = CURDATE() AND deleted_at IS NULL
    ");
    $checkStmt->execute([':user_id' => $user_id]);
    $existing_closure = $checkStmt->fetch(PDO::FETCH_ASSOC);
    $is_closed = !empty($existing_closure);
} catch (PDOException $e) {
    secureErrorLog('Check closure failed: ' . $e->getMessage());
}

// Financial aggregates for display
$total_cash = $total_insurance = $total_card = $total_mobile = $total_revenue = $total_discount = 0;
$total_invoices = 0;
$today_invoices = [];

try {
    // Total cash
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(paid_amount), 0) FROM invoices 
        WHERE billing_type = 'cash' AND payment_status IN ('paid','partial') 
        AND branch_id = ? AND DATE(created_at) = CURDATE() AND deleted_at IS NULL
    ");
    $stmt->execute([$user_branch_id]);
    $total_cash = (float)$stmt->fetchColumn();

    // Insurance total amount
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(total_amount), 0) FROM invoices 
        WHERE billing_type = 'insurance' AND branch_id = ? 
        AND DATE(created_at) = CURDATE() AND deleted_at IS NULL
    ");
    $stmt->execute([$user_branch_id]);
    $total_insurance = (float)$stmt->fetchColumn();

    // Card payments
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(p.amount_paid), 0) 
        FROM payments p INNER JOIN invoices i ON p.invoice_id = i.id
        WHERE p.payment_method IN ('credit_card','debit_card') AND i.branch_id = ?
        AND DATE(p.payment_date) = CURDATE() AND p.deleted_at IS NULL AND i.deleted_at IS NULL
    ");
    $stmt->execute([$user_branch_id]);
    $total_card = (float)$stmt->fetchColumn();

    // Mobile money
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(p.amount_paid), 0) 
        FROM payments p INNER JOIN invoices i ON p.invoice_id = i.id
        WHERE p.payment_method = 'mobile_money' AND i.branch_id = ?
        AND DATE(p.payment_date) = CURDATE() AND p.deleted_at IS NULL AND i.deleted_at IS NULL
    ");
    $stmt->execute([$user_branch_id]);
    $total_mobile = (float)$stmt->fetchColumn();

    // Total revenue (paid amounts)
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(paid_amount), 0) FROM invoices 
        WHERE payment_status IN ('paid','partial') AND branch_id = ? 
        AND DATE(created_at) = CURDATE() AND deleted_at IS NULL
    ");
    $stmt->execute([$user_branch_id]);
    $total_revenue = (float)$stmt->fetchColumn();

    // Total invoices
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM invoices 
        WHERE branch_id = ? AND DATE(created_at) = CURDATE() AND deleted_at IS NULL
    ");
    $stmt->execute([$user_branch_id]);
    $total_invoices = (int)$stmt->fetchColumn();

    // Total discount
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(discount_amount), 0) FROM invoices 
        WHERE branch_id = ? AND DATE(created_at) = CURDATE() AND deleted_at IS NULL
    ");
    $stmt->execute([$user_branch_id]);
    $total_discount = (float)$stmt->fetchColumn();

    // Today's invoices for listing
    $stmt = $pdo->prepare("
        SELECT i.id, i.invoice_number, i.total_amount AS total, i.paid_amount, i.due_amount,
               i.payment_status, i.billing_type,
               DATE_FORMAT(i.created_at, '%h:%i %p') AS time_only,
               CONCAT(p.first_name, ' ', COALESCE(p.middle_name,''), ' ', p.last_name) AS patient_name,
               p.mr_number
        FROM invoices i
        LEFT JOIN patients p ON i.patient_id = p.id AND p.deleted_at IS NULL
        WHERE i.branch_id = ? AND DATE(i.created_at) = CURDATE() AND i.deleted_at IS NULL
        ORDER BY i.created_at DESC
    ");
    $stmt->execute([$user_branch_id]);
    $today_invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    secureErrorLog('Financial aggregation failed: ' . $e->getMessage());
}

$branch_name = 'Main Branch';
try {
    $stmt = $pdo->prepare("SELECT name FROM branches WHERE id = ? AND deleted_at IS NULL");
    $stmt->execute([$user_branch_id]);
    if ($row = $stmt->fetch()) $branch_name = $row['name'];
} catch (PDOException $e) { secureErrorLog($e->getMessage()); }

$currency_symbol = 'ETB';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include('assets/inc/head.php'); ?>
    <meta charset="UTF-8">
    <title>Daily Cash Close - HMS ERP</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root { --primary: #1a237e; --primary-dark: #0d1b5e; --success: #10b981; --danger: #ef4444; --warning: #f59e0b; --gray: #6b7280; }
        body { font-family: 'Inter', sans-serif; background: #f5f7fb; }
        .content-page { margin-left: 260px; margin-top: 70px; padding: 24px 32px; }
        @media (max-width:768px){ .content-page { margin-left:0; } }
        .welcome-header { background: linear-gradient(135deg, var(--primary-dark), var(--primary)); border-radius: 24px; padding: 24px 32px; color: white; margin-bottom: 28px; }
        .stats-grid { display: grid; grid-template-columns: repeat(4,1fr); gap: 20px; margin-bottom: 28px; }
        .stat-card { background: white; border-radius: 20px; padding: 20px; border: 1px solid #e5e7eb; position: relative; overflow: hidden; }
        .stat-card::before { content: ''; position: absolute; top:0; left:0; right:0; height: 4px; }
        .stat-card.primary::before { background: var(--primary); }
        .stat-card.success::before { background: var(--success); }
        .stat-card.info::before { background: #3b82f6; }
        .stat-card.warning::before { background: var(--warning); }
        .stat-value { font-size: 28px; font-weight: 800; color: #1f2937; }
        .stat-label { font-size: 12px; color: var(--gray); text-transform: uppercase; }
        .closure-card { background: #f8fafc; border-radius: 24px; padding: 24px; margin-bottom: 28px; border: 1px solid #e5e7eb; }
        .discrepancy-badge { display: inline-block; padding: 12px 20px; border-radius: 16px; font-weight: 700; width: 100%; text-align: center; }
        .discrepancy-surplus { background: #d1fae5; color: #059669; border:1px solid #10b981; }
        .discrepancy-shortage { background: #fee2e2; color: #dc2626; border:1px solid #ef4444; animation: pulse 1.5s infinite; }
        .discrepancy-zero { background: #f3f4f6; color: #6b7280; }
        @keyframes pulse { 0%,100%{ opacity:1; } 50%{ opacity:0.7; } }
        .card-module { background: white; border-radius: 20px; border: 1px solid #e5e7eb; overflow: hidden; margin-bottom: 24px; }
        .card-header { padding: 18px 24px; background: #fafbfc; font-weight: 700; border-bottom: 1px solid #e5e7eb; }
        .badge-paid { background: #d1fae5; color: #059669; }
        .badge-partial { background: #fef3c7; color: #d97706; }
        .badge-pending { background: #fee2e2; color: #dc2626; }
        .btn-primary-custom { background: linear-gradient(135deg, var(--primary), var(--primary-dark)); color: white; border: none; padding: 14px 28px; border-radius: 14px; font-weight: 600; transition: 0.2s; }
        .btn-primary-custom:disabled { opacity: 0.6; cursor: not-allowed; }
        .loading-overlay { position: fixed; top:0; left:0; width:100%; height:100%; background: rgba(0,0,0,0.5); z-index:2000; display: none; align-items: center; justify-content: center; backdrop-filter: blur(4px); }
        .loading-overlay.active { display: flex; }
        .spinner { width: 50px; height: 50px; border: 4px solid #e5e7eb; border-top-color: var(--primary); border-radius: 50%; animation: spin 0.8s linear infinite; background: white; }
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
                <div class="row">
                    <div class="col-md-8">
                        <div class="welcome-title">Daily Cash Close 💰</div>
                        <div class="welcome-subtitle">End-of-day financial reconciliation & shift locking</div>
                    </div>
                    <div class="col-md-4 text-md-end">
                        <span class="badge bg-light text-dark p-2"><i class="fas fa-building"></i> <?php echo escapeHtml($branch_name); ?></span>
                        <span class="badge bg-light text-dark p-2 ms-2"><i class="fas fa-user"></i> <?php echo escapeHtml($user_name); ?></span>
                    </div>
                </div>
            </div>

            <!-- Stats Grid -->
            <div class="stats-grid">
                <div class="stat-card primary"><div class="stat-header"><div><div class="stat-value"><?php echo number_format($total_revenue,2); ?></div><div class="stat-label">Total Revenue (<?php echo $currency_symbol; ?>)</div></div><i class="fas fa-chart-line fa-2x text-gray"></i></div></div>
                <div class="stat-card success"><div class="stat-header"><div><div class="stat-value"><?php echo number_format($total_cash,2); ?></div><div class="stat-label">Cash Collections</div></div><i class="fas fa-money-bill-wave fa-2x"></i></div></div>
                <div class="stat-card info"><div class="stat-header"><div><div class="stat-value"><?php echo number_format($total_insurance,2); ?></div><div class="stat-label">Insurance Claims</div></div><i class="fas fa-shield-alt fa-2x"></i></div></div>
                <div class="stat-card warning"><div class="stat-header"><div><div class="stat-value"><?php echo number_format($total_discount,2); ?></div><div class="stat-label">Discounts Given</div></div><i class="fas fa-percent fa-2x"></i></div></div>
            </div>
            <div class="stats-grid">
                <div class="stat-card"><div class="stat-value"><?php echo number_format($total_card,2); ?></div><div class="stat-label">Card Payments</div><i class="fas fa-credit-card"></i></div>
                <div class="stat-card"><div class="stat-value"><?php echo number_format($total_mobile,2); ?></div><div class="stat-label">Mobile Money</div><i class="fas fa-mobile-alt"></i></div>
                <div class="stat-card"><div class="stat-value"><?php echo $total_invoices; ?></div><div class="stat-label">Total Invoices</div><i class="fas fa-receipt"></i></div>
                <div class="stat-card"><div class="stat-value"><?php echo number_format($total_cash + $total_card + $total_mobile,2); ?></div><div class="stat-label">Total Collected</div><i class="fas fa-hand-holding-usd"></i></div>
            </div>

            <?php if (!$is_closed): ?>
            <div class="closure-card">
                <h5><i class="fas fa-lock me-2"></i> End of Day Cash Closure</h5>
                <div class="row align-items-end">
                    <div class="col-md-3 mb-3">
                        <label class="form-label">System Cash Total (<?php echo $currency_symbol; ?>)</label>
                        <input type="text" id="systemCashTotal" class="form-control" value="<?php echo number_format($total_cash,2); ?>" readonly>
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="form-label">Physical Cash Counted <span class="text-danger">*</span></label>
                        <input type="number" id="physicalCash" class="form-control" step="0.01" value="<?php echo number_format($total_cash,2); ?>" placeholder="Enter cash amount">
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="form-label">Discrepancy</label>
                        <div id="discrepancyDisplay" class="discrepancy-badge discrepancy-zero">0.00 <?php echo $currency_symbol; ?> (Balanced)</div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <label>&nbsp;</label>
                        <button id="closeShiftBtn" class="btn-primary-custom w-100"><i class="fas fa-lock me-2"></i> Close Shift & Lock</button>
                    </div>
                    <div class="col-12 mb-3">
                        <label class="form-label">Discrepancy Notes <span id="notesRequired" class="text-danger" style="display:none;">* Required</span></label>
                        <textarea id="closeNotes" class="form-control" rows="2" placeholder="Optional notes..."></textarea>
                    </div>
                </div>
            </div>
            <?php else: ?>
            <div class="closure-card" style="background:#ecfdf5; border-color:#10b981;">
                <div class="row">
                    <div class="col-md-3"><div class="small text-muted">Shift Closed At</div><div class="h5"><?php echo date('h:i A', strtotime($existing_closure['closed_at'])); ?></div></div>
                    <div class="col-md-3"><div class="small text-muted">Physical Cash</div><div class="h5"><?php echo number_format($existing_closure['physical_cash'],2); ?> <?php echo $currency_symbol; ?></div></div>
                    <div class="col-md-3"><div class="small text-muted">System Cash</div><div class="h5"><?php echo number_format($existing_closure['total_cash_system'],2); ?> <?php echo $currency_symbol; ?></div></div>
                    <div class="col-md-3"><div class="small text-muted">Difference</div><div class="h5 <?php echo ($existing_closure['difference']>0)?'text-success':(($existing_closure['difference']<0)?'text-danger':''); ?>"><?php echo number_format($existing_closure['difference'],2); ?> <?php echo $currency_symbol; ?></div></div>
                </div>
                <?php if (!empty($existing_closure['notes'])): ?>
                <div class="mt-3 pt-2 border-top"><div class="small text-muted">Notes</div><div><?php echo escapeHtml($existing_closure['notes']); ?></div></div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- Transactions Table -->
            <div class="card-module">
                <div class="card-header"><i class="fas fa-list-alt me-2"></i> Today's Transactions <span class="badge bg-secondary ms-2"><?php echo count($today_invoices); ?> records</span></div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table id="transactionsTable" class="table table-hover mb-0" style="width:100%">
                            <thead class="bg-light"><tr><th>Invoice #</th><th>Patient</th><th>MRN</th><th>Total (<?php echo $currency_symbol; ?>)</th><th>Paid</th><th>Due</th><th>Type</th><th>Status</th></tr></thead>
                            <tbody>
                                <?php foreach ($today_invoices as $inv): ?>
                                <tr>
                                    <td><a href="print_invoice.php?invoice_id=<?php echo $inv['id']; ?>" target="_blank"><?php echo escapeHtml($inv['invoice_number']); ?></a></td>
                                    <td><?php echo escapeHtml($inv['patient_name'] ?? 'N/A'); ?></td>
                                    <td><?php echo escapeHtml($inv['mr_number'] ?? 'N/A'); ?></td>
                                    <td><?php echo number_format($inv['total'],2); ?></td>
                                    <td><?php echo number_format($inv['paid_amount'],2); ?></td>
                                    <td><?php echo number_format($inv['due_amount'],2); ?></td>
                                    <td><span class="badge <?php echo $inv['billing_type']=='cash'?'badge-paid':($inv['billing_type']=='insurance'?'bg-primary':'bg-secondary'); ?>"><?php echo ucfirst($inv['billing_type']); ?></span></td>
                                    <td><span class="badge <?php echo $inv['payment_status']=='paid'?'badge-paid':($inv['payment_status']=='partial'?'badge-partial':'badge-pending'); ?>"><?php echo ucfirst($inv['payment_status']); ?></span></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
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
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script>
$(function() {
    var isProcessing = false;
    var csrfToken = <?php echo json_encode($csrf_token); ?>;
    var systemCash = parseFloat(<?php echo json_encode($total_cash); ?>);
    var currency = '<?php echo $currency_symbol; ?>';

    $('#transactionsTable').DataTable({ pageLength: 25, order: [[0,'desc']] });

    function updateDiscrepancy() {
        let physical = parseFloat($('#physicalCash').val()) || 0;
        let diff = physical - systemCash;
        let diffAbs = Math.abs(diff).toFixed(2);
        let display = $('#discrepancyDisplay');
        display.removeClass('discrepancy-surplus discrepancy-shortage discrepancy-zero');
        if (diff > 0.01) { display.addClass('discrepancy-surplus').html('+' + diffAbs + ' ' + currency + ' (Surplus)'); }
        else if (diff < -0.01) { display.addClass('discrepancy-shortage').html('-' + diffAbs + ' ' + currency + ' (Shortage)'); }
        else { display.addClass('discrepancy-zero').html(diffAbs + ' ' + currency + ' (Balanced)'); }
        $('#notesRequired').toggle(Math.abs(diff) > 0.01);
        if (Math.abs(diff) > 0.01) $('#closeNotes').attr('placeholder', 'EXPLAIN DISCREPANCY: Reason for ' + (diff>0?'surplus':'shortage'));
        else $('#closeNotes').attr('placeholder', 'Optional notes...');
    }

    $('#physicalCash').on('input', updateDiscrepancy);
    updateDiscrepancy();

    $('#closeShiftBtn').on('click', function() {
        if (isProcessing) { Swal.fire('Info', 'Please wait...', 'info'); return; }
        let physical = parseFloat($('#physicalCash').val()) || 0;
        let diff = physical - systemCash;
        let notes = $('#closeNotes').val().trim();
        if (Math.abs(diff) > 0.01 && notes === '') {
            Swal.fire('Discrepancy Notes Required', 'Please explain the cash ' + (diff>0?'surplus':'shortage'), 'warning');
            $('#closeNotes').focus();
            return;
        }
        Swal.fire({
            title: 'Close Cash Shift?',
            html: `<div style="text-align:left">
                <p><strong>Cashier:</strong> <?php echo escapeHtml($user_name); ?></p>
                <p><strong>Date:</strong> <?php echo date('F d, Y'); ?></p><hr>
                <p><strong>System Cash:</strong> ${systemCash.toFixed(2)} ${currency}</p>
                <p><strong>Physical Cash:</strong> ${physical.toFixed(2)} ${currency}</p>
                <p><strong>Discrepancy:</strong> <span style="color:${Math.abs(diff)>0.01?'#dc2626':'#10b981'}">${diff>0?'+':''}${diff.toFixed(2)} ${currency}</span></p><hr>
                <p><strong>Total Invoices:</strong> <?php echo $total_invoices; ?></p>
                <p><strong>Total Revenue:</strong> <?php echo number_format($total_revenue,2); ?> ${currency}</p>
                <p class="text-danger"><i class="fas fa-exclamation-triangle"></i> This action cannot be undone.</p>
            </div>`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Yes, Close Shift',
            cancelButtonText: 'Cancel',
            confirmButtonColor: '#dc2626'
        }).then(result => {
            if (!result.isConfirmed) return;
            isProcessing = true;
            $('#closeShiftBtn').prop('disabled', true).html('<i class="fas fa-spinner fa-spin me-2"></i> Processing...');
            $.ajax({
                url: window.location.href,
                type: 'POST',
                data: { physical_cash: physical, notes: notes, csrf_token: csrfToken },
                dataType: 'json',
                timeout: 30000,
                success: function(res) {
                    isProcessing = false;
                    $('#closeShiftBtn').prop('disabled', false).html('<i class="fas fa-lock me-2"></i> Close Shift & Lock');
                    if (res.success) {
                        Swal.fire({ title: 'Success!', html: `Shift closed.<br>Revenue: ${parseFloat(res.total_revenue).toFixed(2)} ${currency}<br>Discrepancy: ${parseFloat(res.difference).toFixed(2)} ${currency}`, icon: 'success' })
                            .then(() => location.reload());
                    } else Swal.fire('Error', res.message, 'error');
                },
                error: function(xhr) {
                    isProcessing = false;
                    $('#closeShiftBtn').prop('disabled', false).html('<i class="fas fa-lock me-2"></i> Close Shift & Lock');
                    let msg = 'Server error. Please check logs.';
                    if (xhr.responseJSON?.message) msg = xhr.responseJSON.message;
                    Swal.fire('Error', msg, 'error');
                }
            });
        });
    });

    if (typeof MetisMenu !== 'undefined') {
        let sideMenu = document.getElementById('side-menu');
        if (sideMenu && !sideMenu.hasAttribute('data-metismenu-initialized')) {
            new MetisMenu(sideMenu, { toggle: true });
            sideMenu.setAttribute('data-metismenu-initialized', 'true');
        }
    }
});
</script>
</body>
</html>