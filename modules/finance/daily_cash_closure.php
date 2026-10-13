<?php
/**
 * daily_cash_closure.php
 * 
 * Daily Cash Closure & Reconciliation
 * 
 * Performs end-of-day cash reconciliation by comparing system totals
 * against physical cash counts. Generates closure reports and tracks
 * discrepancies.
 * 
 * Enhanced with: Multi-branch support, detailed breakdowns, audit trails,
 * closure history, and export functionality.
 * 
 * Required permissions:
 *   - daily_cash_close_access : perform cash closure
 *   - view_finance_module : view closure history
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

$can_close = $is_global_admin || user_has_permission($pdo, 'daily_cash_close_access', $user_id);
$can_view = $is_global_admin || user_has_permission($pdo, 'view_finance_module', $user_id);

if (!$can_close && !$can_view) {
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
// 2. AJAX HANDLERS
// ----------------------------------------------------------------------

// GET endpoints
if (isset($_GET['ajax']) && $_GET['ajax'] === 'closure_action') {
    $action = $_GET['action'] ?? '';

    // Fetch closure history
    if ($action === 'fetch_history') {
        try {
            $limit = (int)($_GET['limit'] ?? 30);
            $date_from = $_GET['date_from'] ?? '';
            $date_to = $_GET['date_to'] ?? '';
            
            $sql = "SELECT cc.id, cc.user_id, cc.branch_id, cc.closure_date, cc.closed_at, 
                           cc.total_cash_system, cc.total_insurance_system, cc.total_card_system,
                           cc.total_mobile_system, cc.total_revenue_system, cc.total_invoices_count,
                           cc.physical_cash, cc.difference, cc.notes, cc.is_locked, cc.created_at,
                           CONCAT(u.first_name, ' ', u.last_name) AS closed_by,
                           b.name AS branch_name
                    FROM cash_closure cc
                    INNER JOIN users u ON cc.user_id = u.id
                    INNER JOIN branches b ON cc.branch_id = b.id
                    WHERE (cc.deleted_at IS NULL OR cc.deleted_at = '0000-00-00 00:00:00')";
            
            $params = [];
            
            if (!$is_global_admin && $branch_id > 0) {
                $sql .= " AND cc.branch_id = :branch_id";
                $params[':branch_id'] = $branch_id;
            }
            
            if (!empty($date_from)) {
                $sql .= " AND cc.closure_date >= :date_from";
                $params[':date_from'] = $date_from;
            }
            
            if (!empty($date_to)) {
                $sql .= " AND cc.closure_date <= :date_to";
                $params[':date_to'] = $date_to;
            }
            
            $sql .= " ORDER BY cc.created_at DESC LIMIT :limit";
            $params[':limit'] = $limit;
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
            jsonResponse(true, '', ['history' => $history]);
        } catch (PDOException $e) {
            error_log('fetch_history error: ' . $e->getMessage());
            jsonResponse(false, 'Database error: ' . $e->getMessage());
        }
    }

    // Fetch closure summary for a specific date
    if ($action === 'fetch_closure_summary') {
        $date = $_GET['date'] ?? date('Y-m-d');
        
        try {
            // Check if closure already exists
            $check_sql = "SELECT cc.id, cc.closed_at, cc.physical_cash, cc.difference, cc.notes, 
                                 cc.total_cash_system, cc.total_insurance_system, cc.total_card_system,
                                 cc.total_mobile_system, cc.total_revenue_system, cc.total_invoices_count,
                                 CONCAT(u.first_name, ' ', u.last_name) AS closed_by
                          FROM cash_closure cc
                          JOIN users u ON cc.user_id = u.id
                          WHERE cc.closure_date = :date AND (cc.deleted_at IS NULL OR cc.deleted_at = '0000-00-00 00:00:00')";
            $params = [':date' => $date];
            if (!$is_global_admin && $branch_id > 0) {
                $check_sql .= " AND cc.branch_id = :branch_id";
                $params[':branch_id'] = $branch_id;
            }
            $check_stmt = $pdo->prepare($check_sql);
            $check_stmt->execute($params);
            $existing = $check_stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($existing) {
                jsonResponse(true, 'Closure already exists', ['exists' => true, 'closure' => $existing]);
            }
            
            // Get system totals for the date
            $sql = "SELECT 
                        COUNT(*) AS transaction_count,
                        COALESCE(SUM(p.amount_paid), 0) AS total_collected,
                        COALESCE(SUM(CASE WHEN p.payment_method = 'cash' THEN p.amount_paid END), 0) AS cash_total,
                        COALESCE(SUM(CASE WHEN p.payment_method = 'insurance_claim' THEN p.amount_paid END), 0) AS insurance_total,
                        COALESCE(SUM(CASE WHEN p.payment_method IN ('credit_card', 'debit_card') THEN p.amount_paid END), 0) AS card_total,
                        COALESCE(SUM(CASE WHEN p.payment_method IN ('telebirr', 'cbe_birr', 'mobile_money', 'bank_transfer', 'check') THEN p.amount_paid END), 0) AS mobile_total,
                        COUNT(DISTINCT p.invoice_id) AS invoice_count
                    FROM payments p
                    WHERE DATE(p.payment_date) = :date AND (p.deleted_at IS NULL OR p.deleted_at = '0000-00-00 00:00:00')";
            $params = [':date' => $date];
            if (!$is_global_admin && $branch_id > 0) {
                $sql .= " AND p.branch_id = :branch_id";
                $params[':branch_id'] = $branch_id;
            }
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $totals = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Get daily expenses
            $exp_sql = "SELECT COALESCE(SUM(er.amount), 0) AS total_expenses
                        FROM expense_registry er
                        WHERE DATE(er.expense_date) = :date AND (er.deleted_at IS NULL OR er.deleted_at = '0000-00-00 00:00:00')";
            $params = [':date' => $date];
            if (!$is_global_admin && $branch_id > 0) {
                $exp_sql .= " AND er.branch_id = :branch_id";
                $params[':branch_id'] = $branch_id;
            }
            $exp_stmt = $pdo->prepare($exp_sql);
            $exp_stmt->execute($params);
            $expenses = (float)$exp_stmt->fetchColumn();
            
            jsonResponse(true, '', [
                'exists' => false,
                'date' => $date,
                'totals' => $totals,
                'expenses' => $expenses,
                'net_revenue' => (float)$totals['total_collected'] - $expenses
            ]);
        } catch (PDOException $e) {
            error_log('fetch_closure_summary error: ' . $e->getMessage());
            jsonResponse(false, 'Database error: ' . $e->getMessage());
        }
    }

    // Fetch daily transactions for review
    if ($action === 'fetch_daily_transactions') {
        $date = $_GET['date'] ?? date('Y-m-d');
        
        try {
            $sql = "SELECT 
                        p.id,
                        p.receipt_number,
                        p.amount_paid,
                        p.payment_method,
                        p.payment_date,
                        p.notes,
                        p.transaction_reference,
                        CONCAT(pat.first_name, ' ', COALESCE(pat.middle_name, ''), ' ', pat.last_name) AS patient_name,
                        pat.mr_number,
                        i.invoice_number,
                        CONCAT(u.first_name, ' ', u.last_name) AS cashier_name
                    FROM payments p
                    INNER JOIN invoices i ON p.invoice_id = i.id
                    INNER JOIN patients pat ON p.patient_id = pat.id
                    INNER JOIN users u ON p.received_by_user_id = u.id
                    WHERE DATE(p.payment_date) = :date AND (p.deleted_at IS NULL OR p.deleted_at = '0000-00-00 00:00:00')";
            $params = [':date' => $date];
            if (!$is_global_admin && $branch_id > 0) {
                $sql .= " AND p.branch_id = :branch_id";
                $params[':branch_id'] = $branch_id;
            }
            $sql .= " ORDER BY p.created_at DESC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
            jsonResponse(true, '', ['transactions' => $transactions]);
        } catch (PDOException $e) {
            error_log('fetch_daily_transactions error: ' . $e->getMessage());
            jsonResponse(false, 'Database error: ' . $e->getMessage());
        }
    }

    // Fetch closure statistics
    if ($action === 'fetch_stats') {
        try {
            $sql = "SELECT 
                        COUNT(*) AS total_closures,
                        COALESCE(SUM(cc.total_revenue_system), 0) AS total_revenue,
                        COALESCE(SUM(cc.difference), 0) AS total_difference,
                        COALESCE(AVG(cc.difference), 0) AS avg_difference,
                        COUNT(CASE WHEN ABS(cc.difference) > 10 THEN 1 END) AS large_discrepancies
                    FROM cash_closure cc
                    WHERE (cc.deleted_at IS NULL OR cc.deleted_at = '0000-00-00 00:00:00')";
            $params = [];
            if (!$is_global_admin && $branch_id > 0) {
                $sql .= " AND cc.branch_id = :branch_id";
                $params[':branch_id'] = $branch_id;
            }
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $stats = $stmt->fetch(PDO::FETCH_ASSOC);
            jsonResponse(true, '', ['stats' => $stats]);
        } catch (PDOException $e) {
            error_log('fetch_stats error: ' . $e->getMessage());
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

    // Perform cash closure
    if ($action === 'perform_closure') {
        if (!$can_close) jsonResponse(false, 'Permission denied. Only authorized staff can perform cash closure.');
        
        $closure_date = $_POST['closure_date'] ?? date('Y-m-d');
        $physical_cash = (float)($_POST['physical_cash'] ?? 0);
        $physical_insurance = (float)($_POST['physical_insurance'] ?? 0);
        $physical_card = (float)($_POST['physical_card'] ?? 0);
        $physical_mobile = (float)($_POST['physical_mobile'] ?? 0);
        $notes = trim($_POST['notes'] ?? '');
        $difference_reason = trim($_POST['difference_reason'] ?? '');
        
        if ($physical_cash < 0) {
            jsonResponse(false, 'Invalid physical cash amount.');
        }
        
        // Calculate difference to check if reason is required
        try {
            // Get system totals to calculate difference
            $sql = "SELECT 
                        COALESCE(SUM(CASE WHEN p.payment_method = 'cash' THEN p.amount_paid END), 0) AS total_cash,
                        COALESCE(SUM(CASE WHEN p.payment_method = 'insurance_claim' THEN p.amount_paid END), 0) AS total_insurance,
                        COALESCE(SUM(CASE WHEN p.payment_method IN ('credit_card', 'debit_card') THEN p.amount_paid END), 0) AS total_card,
                        COALESCE(SUM(CASE WHEN p.payment_method IN ('telebirr', 'cbe_birr', 'mobile_money', 'bank_transfer', 'check') THEN p.amount_paid END), 0) AS total_mobile,
                        COALESCE(SUM(p.amount_paid), 0) AS total_revenue
                    FROM payments p
                    WHERE DATE(p.payment_date) = :date AND (p.deleted_at IS NULL OR p.deleted_at = '0000-00-00 00:00:00')";
            $params = [':date' => $closure_date];
            if (!$is_global_admin && $branch_id > 0) {
                $sql .= " AND p.branch_id = :branch_id";
                $params[':branch_id'] = $branch_id;
            }
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $totals = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $total_difference = ($physical_cash + $physical_insurance + $physical_card + $physical_mobile) - (float)$totals['total_revenue'];
            
            // Require reason if difference is not zero (allow small tolerance of 0.01)
            if (abs($total_difference) > 0.01 && empty($difference_reason)) {
                jsonResponse(false, 'A reason is required for the cash difference. Please explain why there is a discrepancy.');
            }
            
        } catch (PDOException $e) {
            error_log('Difference calculation error: ' . $e->getMessage());
            jsonResponse(false, 'Database error: ' . $e->getMessage());
        }
        
        try {
            $pdo->beginTransaction();
            
            // Check if already closed for this date
            $check_sql = "SELECT id FROM cash_closure WHERE closure_date = :date AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')";
            $params = [':date' => $closure_date];
            if (!$is_global_admin && $branch_id > 0) {
                $check_sql .= " AND branch_id = :branch_id";
                $params[':branch_id'] = $branch_id;
            }
            $check_stmt = $pdo->prepare($check_sql);
            $check_stmt->execute($params);
            if ($check_stmt->fetch()) {
                $pdo->rollBack();
                jsonResponse(false, 'Cash closure already performed for this date.');
            }

            // Recalculate system totals
            $sql = "SELECT 
                        COALESCE(SUM(CASE WHEN p.payment_method = 'cash' THEN p.amount_paid END), 0) AS total_cash,
                        COALESCE(SUM(CASE WHEN p.payment_method = 'insurance_claim' THEN p.amount_paid END), 0) AS total_insurance,
                        COALESCE(SUM(CASE WHEN p.payment_method IN ('credit_card', 'debit_card') THEN p.amount_paid END), 0) AS total_card,
                        COALESCE(SUM(CASE WHEN p.payment_method IN ('telebirr', 'cbe_birr', 'mobile_money', 'bank_transfer', 'check') THEN p.amount_paid END), 0) AS total_mobile,
                        COUNT(*) AS invoice_count,
                        COALESCE(SUM(p.amount_paid), 0) AS total_revenue
                    FROM payments p
                    WHERE DATE(p.payment_date) = :date AND (p.deleted_at IS NULL OR p.deleted_at = '0000-00-00 00:00:00')";
            $params = [':date' => $closure_date];
            if (!$is_global_admin && $branch_id > 0) {
                $sql .= " AND p.branch_id = :branch_id";
                $params[':branch_id'] = $branch_id;
            }
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $totals = $stmt->fetch(PDO::FETCH_ASSOC);

            // Calculate differences
            $cash_difference = $physical_cash - (float)$totals['total_cash'];
            $insurance_difference = $physical_insurance - (float)$totals['total_insurance'];
            $card_difference = $physical_card - (float)$totals['total_card'];
            $mobile_difference = $physical_mobile - (float)$totals['total_mobile'];
            $total_difference = $cash_difference + $insurance_difference + $card_difference + $mobile_difference;
            
            // Prepare notes with difference reason if provided
            $final_notes = $notes;
            if (!empty($difference_reason)) {
                $final_notes = ($final_notes ? $final_notes . "\n" : '') . 'Difference Reason: ' . $difference_reason;
            }

            // Insert closure record
            $insert_sql = "INSERT INTO cash_closure 
                          (user_id, branch_id, closure_date, closed_at, 
                           total_cash_system, total_insurance_system, total_card_system, total_mobile_system,
                           total_revenue_system, total_invoices_count,
                           physical_cash, difference, notes, is_locked, created_at)
                          VALUES 
                          (:user_id, :branch_id, :date, NOW(),
                           :total_cash, :total_insurance, :total_card, :total_mobile,
                           :total_revenue, :invoice_count,
                           :physical_cash, :difference, :notes, 1, NOW())";
            
            $insert_stmt = $pdo->prepare($insert_sql);
            $insert_stmt->execute([
                ':user_id' => $user_id,
                ':branch_id' => $branch_id,
                ':date' => $closure_date,
                ':total_cash' => $totals['total_cash'],
                ':total_insurance' => $totals['total_insurance'],
                ':total_card' => $totals['total_card'],
                ':total_mobile' => $totals['total_mobile'],
                ':total_revenue' => $totals['total_revenue'],
                ':invoice_count' => $totals['invoice_count'],
                ':physical_cash' => $physical_cash,
                ':difference' => $total_difference,
                ':notes' => $final_notes
            ]);
            
            $closure_id = $pdo->lastInsertId();
            
            // Log the closure
            $log_sql = "INSERT INTO status_transition_logs 
                        (visit_id, entity_type, entity_id, old_status, new_status, changed_by_user_id, changed_at, created_at)
                        VALUES (0, 'cash_closure', :closure_id, 'open', 'closed', :user_id, NOW(), NOW())";
            $log_stmt = $pdo->prepare($log_sql);
            $log_stmt->execute([
                ':closure_id' => $closure_id,
                ':user_id' => $user_id
            ]);

            $pdo->commit();
            
            jsonResponse(true, 'Cash closure completed successfully.', [
                'closure_id' => $closure_id,
                'total_difference' => $total_difference,
                'cash_difference' => $cash_difference,
                'total_revenue' => $totals['total_revenue']
            ]);
        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log('perform_closure error: ' . $e->getMessage());
            jsonResponse(false, 'Database error: ' . $e->getMessage());
        }
    }

    // Delete closure (soft delete)
    if ($action === 'delete_closure') {
        if (!$is_global_admin) jsonResponse(false, 'Permission denied. Only super admins can delete closures.');
        
        $closure_id = (int)($_POST['closure_id'] ?? 0);
        if ($closure_id <= 0) jsonResponse(false, 'Invalid closure ID');

        try {
            $sql = "UPDATE cash_closure SET deleted_at = NOW() WHERE id = :id";
            $params = [':id' => $closure_id];
            if (!$is_global_admin && $branch_id > 0) {
                $sql .= " AND branch_id = :branch_id";
                $params[':branch_id'] = $branch_id;
            }
            $update = $pdo->prepare($sql);
            $update->execute($params);
            
            if ($update->rowCount() > 0) {
                jsonResponse(true, 'Closure deleted successfully.');
            } else {
                jsonResponse(false, 'Closure not found or cannot be deleted.');
            }
        } catch (PDOException $e) {
            error_log('delete_closure error: ' . $e->getMessage());
            jsonResponse(false, 'Database error: ' . $e->getMessage());
        }
    }

    jsonResponse(false, 'Unsupported action');
    exit;
}

// ----------------------------------------------------------------------
// 3. INITIAL PAGE DATA
// ----------------------------------------------------------------------
$current_date = date('l, F j, Y');
$current_time = date('g:i A');
$today = date('Y-m-d');
$closure_stats = ['total_closures' => 0, 'total_revenue' => 0, 'total_difference' => 0, 'avg_difference' => 0, 'large_discrepancies' => 0];

try {
    // Get closure statistics
    $stats_sql = "SELECT 
                    COUNT(*) AS total_closures,
                    COALESCE(SUM(cc.total_revenue_system), 0) AS total_revenue,
                    COALESCE(SUM(cc.difference), 0) AS total_difference,
                    COALESCE(AVG(cc.difference), 0) AS avg_difference,
                    COUNT(CASE WHEN ABS(cc.difference) > 10 THEN 1 END) AS large_discrepancies
                  FROM cash_closure cc
                  WHERE (cc.deleted_at IS NULL OR cc.deleted_at = '0000-00-00 00:00:00')";
    $params = [];
    if (!$is_global_admin && $branch_id > 0) {
        $stats_sql .= " AND cc.branch_id = :branch_id";
        $params[':branch_id'] = $branch_id;
    }
    $stats_stmt = $pdo->prepare($stats_sql);
    $stats_stmt->execute($params);
    $closure_stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);
    if (!$closure_stats) {
        $closure_stats = ['total_closures' => 0, 'total_revenue' => 0, 'total_difference' => 0, 'avg_difference' => 0, 'large_discrepancies' => 0];
    }

    // Check if today's closure exists
    $check_sql = "SELECT id, closed_at, physical_cash, difference 
                  FROM cash_closure 
                  WHERE closure_date = :date AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')";
    $params = [':date' => $today];
    if (!$is_global_admin && $branch_id > 0) {
        $check_sql .= " AND branch_id = :branch_id";
        $params[':branch_id'] = $branch_id;
    }
    $check_stmt = $pdo->prepare($check_sql);
    $check_stmt->execute($params);
    $today_closure = $check_stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('Initial data fetch error: ' . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Daily Cash Closure | HMS ERP</title>
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
        .stat-sub { font-size: 11px; color: var(--gray); margin-top: 2px; }
        
        .dashboard-grid { display: grid; grid-template-columns: 1fr 440px; gap: 24px; }
        @media (max-width: 1200px) { .dashboard-grid { grid-template-columns: 1fr; } }
        
        .card-modern { background: white; border-radius: 20px; border: 1px solid var(--gray-light); overflow: hidden; margin-bottom: 24px; }
        .card-header-custom { padding: 16px 20px; background: #f8fafc; border-bottom: 1px solid var(--gray-light); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; }
        .card-header-custom h3 { margin: 0; font-size: 16px; font-weight: 700; color: var(--dark); }
        
        .closure-list { max-height: 500px; overflow-y: auto; }
        .closure-list::-webkit-scrollbar { width: 6px; }
        .closure-list::-webkit-scrollbar-thumb { background: var(--gray-light); border-radius: 3px; }
        
        .closure-item { padding: 12px 16px; border-bottom: 1px solid #f0f2f5; cursor: pointer; transition: all 0.2s; }
        .closure-item:hover { background: #f8fafc; }
        .closure-item.active { background: #e0e7ff; border-left: 3px solid var(--primary); }
        
        .action-btn { padding: 8px 18px; border-radius: 12px; font-size: 13px; font-weight: 600; border: none; cursor: pointer; transition: all 0.2s; }
        .action-btn-primary { background: var(--primary); color: white; }
        .action-btn-primary:hover { background: var(--primary-dark); }
        .action-btn-success { background: var(--success); color: white; }
        .action-btn-success:hover { background: #059669; }
        .action-btn-danger { background: var(--danger); color: white; }
        .action-btn-danger:hover { background: #dc2626; }
        .action-btn-warning { background: var(--warning); color: var(--dark); }
        .action-btn-warning:hover { background: #d97706; color: white; }
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
        .info-row { display: flex; margin-bottom: 10px; font-size: 13px; }
        .info-label { width: 140px; font-weight: 600; color: var(--gray); flex-shrink: 0; }
        .info-value { flex: 1; color: var(--dark); }
        
        .summary-box { background: #f8fafc; border-radius: 12px; padding: 16px; margin: 10px 0; }
        .summary-row { display: flex; justify-content: space-between; padding: 4px 0; font-size: 13px; }
        .summary-row.total { font-weight: 700; border-top: 2px solid var(--gray-light); padding-top: 8px; margin-top: 4px; }
        
        .difference-positive { color: var(--success); }
        .difference-negative { color: var(--danger); }
        .difference-zero { color: var(--gray); }
        
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
        .badge-closed { background: #d1fae5; color: #059669; }
        .badge-discrepancy { background: #fee2e2; color: #dc2626; }
        
        .text-success { color: #059669 !important; }
        .text-danger { color: #dc2626 !important; }
        .text-warning { color: #d97706 !important; }
        
        .required-field::after {
            content: ' *';
            color: #dc2626;
        }
        
        @media (max-width: 576px) {
            .content-page { padding: 12px; }
            .welcome-header { padding: 16px; }
            .info-row { flex-direction: column; }
            .info-label { width: 100%; }
        }
        
        .transactions-scroll { max-height: 300px; overflow-y: auto; }
        .transactions-scroll::-webkit-scrollbar { width: 4px; }
        .transactions-scroll::-webkit-scrollbar-thumb { background: var(--gray-light); border-radius: 2px; }
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
                        <div class="welcome-title">Daily Cash Closure & Reconciliation 💰</div>
                        <div class="welcome-subtitle">End-of-day cash reconciliation by comparing system totals against physical cash counts.</div>
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
                    <div><div class="stat-value" id="totalClosures">--</div><div class="stat-label">Total Closures</div></div>
                </div>
                <div class="stat-card success">
                    <div><div class="stat-value" id="totalRevenue">--</div><div class="stat-label">Total Revenue Closed (ETB)</div></div>
                </div>
                <div class="stat-card warning">
                    <div><div class="stat-value" id="avgDifference">--</div><div class="stat-label">Average Difference (ETB)</div></div>
                </div>
                <div class="stat-card danger">
                    <div><div class="stat-value" id="largeDiscrepancies">--</div><div class="stat-label">Large Discrepancies (>10 ETB)</div></div>
                </div>
            </div>

            <div class="dashboard-grid">
                <!-- LEFT PANEL: Closure History -->
                <div class="card-modern">
                    <div class="card-header-custom">
                        <h3><i class="fas fa-history me-2"></i> Closure History</h3>
                        <div class="d-flex gap-2 align-items-center flex-wrap">
                            <div class="search-box">
                                <input type="date" id="dateFrom" class="form-control form-control-sm" style="width:130px;">
                                <input type="date" id="dateTo" class="form-control form-control-sm" style="width:130px;">
                                <button class="btn btn-sm btn-outline" onclick="refreshHistory()"><i class="fas fa-search"></i></button>
                            </div>
                            <button class="action-btn action-btn-outline action-btn-sm" onclick="refreshHistory()"><i class="fas fa-sync-alt"></i></button>
                        </div>
                    </div>
                    <div class="closure-list" id="historyListContainer">
                        <div class="empty-state">Loading closure history...</div>
                    </div>
                </div>

                <!-- RIGHT PANEL: Closure Action Desk -->
                <div class="card-modern">
                    <div class="card-header-custom">
                        <h3><i class="fas fa-cash-register me-2"></i> Cash Closure Desk</h3>
                        <div>
                            <button class="action-btn action-btn-outline action-btn-sm" onclick="refreshSummary()"><i class="fas fa-sync-alt"></i></button>
                        </div>
                    </div>
                    <div id="closureWorkspace">
                        <div class="empty-state">
                            <i class="fas fa-hand-pointer fa-2x mb-2 text-muted"></i>
                            <p>Select a date to perform cash closure or view details of existing closures.</p>
                            <div class="mt-3">
                                <input type="date" id="closureDate" class="form-control form-control-sm" value="<?php echo $today; ?>" style="max-width:200px;margin:0 auto;">
                                <button class="action-btn action-btn-primary mt-2" onclick="loadClosureSummary()">
                                    <i class="fas fa-chart-line"></i> Load Summary
                                </button>
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
<input type="hidden" id="csrfToken" value="<?php echo escapeHtml($csrf_token); ?>">
<input type="hidden" id="isAdmin" value="<?php echo $is_global_admin ? '1' : '0'; ?>">
<input type="hidden" id="canClose" value="<?php echo $can_close ? '1' : '0'; ?>">

<!-- Closure Details Modal -->
<div class="modal fade" id="closureDetailModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-file-invoice me-2"></i> Closure Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="closureDetailBody"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary" onclick="window.print()"><i class="fas fa-print"></i> Print Report</button>
            </div>
        </div>
    </div>
</div>

<script>
let currentClosureId = null;
let refreshTimer = null;
let currentDifference = 0;
const canClose = <?php echo $can_close ? 'true' : 'false'; ?>;

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

function getDifferenceClass(diff) {
    diff = parseFloat(diff);
    if (diff > 0.01) return 'difference-positive';
    if (diff < -0.01) return 'difference-negative';
    return 'difference-zero';
}

function getDifferenceText(diff) {
    diff = parseFloat(diff);
    if (diff > 0.01) return 'Over';
    if (diff < -0.01) return 'Short';
    return 'Balanced';
}

function refreshMetrics() {
    $.ajax({
        url: window.location.pathname + '?ajax=closure_action&action=fetch_stats&csrf_token=' + encodeURIComponent($('#csrfToken').val()),
        method: 'GET',
        success: function(res) {
            if (res.success && res.stats) {
                $('#totalClosures').text(res.stats.total_closures || 0);
                $('#totalRevenue').text(formatMoney(res.stats.total_revenue || 0));
                $('#avgDifference').text(formatMoney(res.stats.avg_difference || 0));
                $('#largeDiscrepancies').text(res.stats.large_discrepancies || 0);
            }
        },
        error: function() { console.error('Failed to load metrics'); }
    });
}

function refreshHistory() {
    const dateFrom = $('#dateFrom').val() || '';
    const dateTo = $('#dateTo').val() || '';
    
    showLoading(true);
    $.ajax({
        url: window.location.pathname + '?ajax=closure_action&action=fetch_history&date_from=' + dateFrom + '&date_to=' + dateTo + '&csrf_token=' + encodeURIComponent($('#csrfToken').val()),
        method: 'GET',
        success: function(res) {
            showLoading(false);
            if (res.success && res.history) {
                const container = $('#historyListContainer');
                container.empty();
                if (res.history.length === 0) {
                    container.html('<div class="empty-state">No closure records found.</div>');
                } else {
                    res.history.forEach(closure => {
                        const diff = parseFloat(closure.difference);
                        const diffClass = getDifferenceClass(diff);
                        const diffText = getDifferenceText(diff);
                        const diffDisplay = diff > 0 ? '+' + formatMoney(diff) : formatMoney(diff);
                        
                        const row = $(`
                            <div class="closure-item" data-closure-id="${closure.id}">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <div class="fw-bold">${escapeHtml(closure.closure_date)}</div>
                                        <div class="small text-muted">Closed by: ${escapeHtml(closure.closed_by)}</div>
                                        <div class="small text-muted">Branch: ${escapeHtml(closure.branch_name)}</div>
                                        ${closure.notes ? `<div class="small text-muted"><i class="fas fa-info-circle"></i> ${escapeHtml(closure.notes.substring(0, 50))}${closure.notes.length > 50 ? '...' : ''}</div>` : ''}
                                    </div>
                                    <div class="text-end">
                                        <div class="fw-bold">${formatMoney(closure.total_revenue_system)} ETB</div>
                                        <div class="small ${diffClass}">
                                            ${diffText}: ${diffDisplay} ETB
                                        </div>
                                        <span class="badge ${Math.abs(diff) > 10 ? 'badge-discrepancy' : 'badge-closed'}">
                                            ${Math.abs(diff) > 10 ? '⚠ Discrepancy' : '✓ Closed'}
                                        </span>
                                    </div>
                                </div>
                            </div>
                        `);
                        row.data('closure-id', closure.id);
                        row.on('click', function() {
                            $('.closure-item').removeClass('active');
                            $(this).addClass('active');
                            currentClosureId = closure.id;
                            viewClosureDetails(closure.id);
                        });
                        container.append(row);
                    });
                }
            } else {
                showToast('Failed to load history: ' + (res.message || 'Unknown error'), 'error');
            }
        },
        error: function(xhr) {
            showLoading(false);
            showToast('Network error', 'error');
        }
    });
}

function loadClosureSummary() {
    const date = $('#closureDate').val();
    if (!date) {
        showToast('Please select a date.', 'error');
        return;
    }
    
    showLoading(true);
    $.ajax({
        url: window.location.pathname + '?ajax=closure_action&action=fetch_closure_summary&date=' + date + '&csrf_token=' + encodeURIComponent($('#csrfToken').val()),
        method: 'GET',
        success: function(res) {
            showLoading(false);
            if (res.success) {
                if (res.exists) {
                    renderExistingClosure(res.closure);
                } else {
                    renderClosureForm(res);
                }
            } else {
                showToast(res.message || 'Failed to load summary', 'error');
            }
        },
        error: function() { showLoading(false); showToast('Network error', 'error'); }
    });
}

function renderClosureForm(data) {
    const totals = data.totals;
    const expenses = data.expenses || 0;
    const netRevenue = data.net_revenue || 0;
    const date = data.date;
    
    // Calculate current difference based on physical entries
    function calculateDifference() {
        const physicalCash = parseFloat($('#physicalCash').val()) || 0;
        const physicalInsurance = parseFloat($('#physicalInsurance').val()) || 0;
        const physicalCard = parseFloat($('#physicalCard').val()) || 0;
        const physicalMobile = parseFloat($('#physicalMobile').val()) || 0;
        const physicalTotal = physicalCash + physicalInsurance + physicalCard + physicalMobile;
        const systemTotal = parseFloat(totals.total_collected) || 0;
        return physicalTotal - systemTotal;
    }
    
    // Update difference display
    function updateDifferenceDisplay() {
        const diff = calculateDifference();
        currentDifference = diff;
        const diffClass = getDifferenceClass(diff);
        const diffText = getDifferenceText(diff);
        const diffDisplay = diff > 0 ? '+' + formatMoney(diff) : formatMoney(diff);
        
        $('#differenceDisplay').removeClass('difference-positive difference-negative difference-zero')
            .addClass(diffClass)
            .text(diffText + ': ' + diffDisplay + ' ETB');
        
        // Show/hide reason field based on difference
        if (Math.abs(diff) > 0.01) {
            $('#differenceReasonGroup').show();
            $('#differenceReason').prop('required', true);
        } else {
            $('#differenceReasonGroup').hide();
            $('#differenceReason').prop('required', false);
        }
    }
    
    const html = `
        <div class="detail-section">
            <h6 class="fw-bold mb-3">Cash Closure for ${date}</h6>
            
            <div class="summary-box">
                <div class="summary-row"><span>Total Transactions:</span><span>${totals.transaction_count || 0}</span></div>
                <div class="summary-row"><span>Invoices Processed:</span><span>${totals.invoice_count || 0}</span></div>
                <div class="summary-row"><span>Total Revenue (System):</span><span><strong>${formatMoney(totals.total_collected)} ETB</strong></span></div>
                <div class="summary-row"><span>Total Expenses:</span><span>${formatMoney(expenses)} ETB</span></div>
                <div class="summary-row total"><span>Net Revenue:</span><span><strong>${formatMoney(netRevenue)} ETB</strong></span></div>
            </div>
            
            <div class="row mt-3">
                <div class="col-md-6">
                    <div class="summary-box">
                        <h6 class="fw-bold mb-2">System Breakdown</h6>
                        <div class="summary-row"><span>Cash:</span><span>${formatMoney(totals.cash_total || 0)} ETB</span></div>
                        <div class="summary-row"><span>Insurance:</span><span>${formatMoney(totals.insurance_total || 0)} ETB</span></div>
                        <div class="summary-row"><span>Card:</span><span>${formatMoney(totals.card_total || 0)} ETB</span></div>
                        <div class="summary-row"><span>Digital/Mobile:</span><span>${formatMoney(totals.mobile_total || 0)} ETB</span></div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="summary-box">
                        <h6 class="fw-bold mb-2">Physical Count</h6>
                        <div class="mb-2">
                            <label class="form-label small">Cash</label>
                            <input type="number" step="0.01" id="physicalCash" class="form-control form-control-sm" value="${totals.cash_total || 0}" oninput="updateDifferenceDisplay()">
                        </div>
                        <div class="mb-2">
                            <label class="form-label small">Insurance Claims</label>
                            <input type="number" step="0.01" id="physicalInsurance" class="form-control form-control-sm" value="${totals.insurance_total || 0}" oninput="updateDifferenceDisplay()">
                        </div>
                        <div class="mb-2">
                            <label class="form-label small">Card Payments</label>
                            <input type="number" step="0.01" id="physicalCard" class="form-control form-control-sm" value="${totals.card_total || 0}" oninput="updateDifferenceDisplay()">
                        </div>
                        <div class="mb-2">
                            <label class="form-label small">Digital/Mobile</label>
                            <input type="number" step="0.01" id="physicalMobile" class="form-control form-control-sm" value="${totals.mobile_total || 0}" oninput="updateDifferenceDisplay()">
                        </div>
                        <div class="summary-row total mt-2">
                            <span><strong>Difference:</strong></span>
                            <span id="differenceDisplay" class="difference-zero">Balanced: 0.00 ETB</span>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="mb-2">
                <label class="form-label small">Notes</label>
                <input type="text" id="closureNotes" class="form-control form-control-sm" placeholder="Any notes about this closure...">
            </div>
            
            <div class="mb-2" id="differenceReasonGroup" style="display:none;">
                <label class="form-label small required-field">Reason for Difference</label>
                <textarea id="differenceReason" class="form-control form-control-sm" rows="2" placeholder="Please explain the reason for the cash difference..."></textarea>
                <small class="text-muted">A reason is required when there is a difference between system and physical totals.</small>
            </div>
            
            ${canClose ? `
            <button class="action-btn action-btn-success w-100 mt-3" id="performClosureBtn">
                <i class="fas fa-check"></i> Perform Cash Closure
            </button>
            ` : `
            <div class="alert alert-warning mt-3">
                <i class="fas fa-lock"></i> You do not have permission to perform cash closures.
            </div>
            `}
            
            <button class="action-btn action-btn-outline w-100 mt-2" onclick="loadClosureSummary()">
                <i class="fas fa-sync-alt"></i> Refresh
            </button>
        </div>
    `;
    $('#closureWorkspace').html(html);
    
    // Initialize difference display
    updateDifferenceDisplay();
    
    if (canClose) {
        $('#performClosureBtn').on('click', function() {
            const physicalCash = parseFloat($('#physicalCash').val()) || 0;
            const physicalInsurance = parseFloat($('#physicalInsurance').val()) || 0;
            const physicalCard = parseFloat($('#physicalCard').val()) || 0;
            const physicalMobile = parseFloat($('#physicalMobile').val()) || 0;
            const notes = $('#closureNotes').val() || '';
            const differenceReason = $('#differenceReason').val() || '';
            
            // Check if difference exists and reason is provided
            const diff = calculateDifference();
            if (Math.abs(diff) > 0.01 && !differenceReason) {
                showToast('Please provide a reason for the cash difference.', 'error');
                $('#differenceReason').focus();
                return;
            }
            
            const csrf = $('#csrfToken').val();
            
            Swal.fire({
                title: 'Confirm Cash Closure',
                html: `
                    <div class="text-start">
                        <p><strong>Date:</strong> ${date}</p>
                        <p><strong>Physical Cash:</strong> ${formatMoney(physicalCash)} ETB</p>
                        <p><strong>Physical Insurance:</strong> ${formatMoney(physicalInsurance)} ETB</p>
                        <p><strong>Physical Card:</strong> ${formatMoney(physicalCard)} ETB</p>
                        <p><strong>Physical Digital:</strong> ${formatMoney(physicalMobile)} ETB</p>
                        <hr>
                        <p><strong>System Total:</strong> ${formatMoney(totals.total_collected)} ETB</p>
                        <p><strong>Physical Total:</strong> ${formatMoney(physicalCash + physicalInsurance + physicalCard + physicalMobile)} ETB</p>
                        <p><strong>Difference:</strong> ${formatMoney(diff)} ETB</p>
                        ${differenceReason ? `<p><strong>Difference Reason:</strong> ${escapeHtml(differenceReason)}</p>` : ''}
                    </div>
                `,
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Yes, Close Cash',
                cancelButtonText: 'Cancel',
                confirmButtonColor: '#059669'
            }).then((result) => {
                if (result.isConfirmed) {
                    showLoading(true);
                    $.ajax({
                        url: window.location.pathname,
                        method: 'POST',
                        data: {
                            action: 'perform_closure',
                            closure_date: date,
                            physical_cash: physicalCash,
                            physical_insurance: physicalInsurance,
                            physical_card: physicalCard,
                            physical_mobile: physicalMobile,
                            notes: notes,
                            difference_reason: differenceReason,
                            csrf_token: csrf
                        },
                        dataType: 'json',
                        success: function(res) {
                            showLoading(false);
                            if (res.success) {
                                showToast(res.message);
                                refreshHistory();
                                refreshMetrics();
                                loadClosureSummary();
                                
                                Swal.fire({
                                    title: 'Closure Complete!',
                                    html: `
                                        <p><strong>Total Revenue:</strong> ${formatMoney(res.total_revenue)} ETB</p>
                                        <p><strong>Total Difference:</strong> ${formatMoney(res.total_difference)} ETB</p>
                                        <p><strong>Cash Difference:</strong> ${formatMoney(res.cash_difference)} ETB</p>
                                    `,
                                    icon: 'success',
                                    confirmButtonText: 'OK',
                                    confirmButtonColor: '#4361ee'
                                });
                            } else {
                                showToast(res.message || 'Closure failed', 'error');
                            }
                        },
                        error: function() { showLoading(false); showToast('Server error', 'error'); }
                    });
                }
            });
        });
    }
    
    // Make updateDifferenceDisplay available globally for oninput events
    window.updateDifferenceDisplay = updateDifferenceDisplay;
}

function renderExistingClosure(closure) {
    const diff = parseFloat(closure.difference);
    const diffClass = getDifferenceClass(diff);
    const diffText = getDifferenceText(diff);
    const diffDisplay = diff > 0 ? '+' + formatMoney(diff) : formatMoney(diff);
    
    const html = `
        <div class="detail-section">
            <div class="d-flex justify-content-between align-items-start mb-3">
                <h6 class="fw-bold mb-0">Closure for ${escapeHtml(closure.closure_date)}</h6>
                <span class="badge ${Math.abs(diff) > 10 ? 'badge-discrepancy' : 'badge-closed'}">
                    ${Math.abs(diff) > 10 ? '⚠ Discrepancy' : '✓ Closed'}
                </span>
            </div>
            
            <div class="summary-box">
                <div class="summary-row"><span>Closed By:</span><span>${escapeHtml(closure.closed_by)}</span></div>
                <div class="summary-row"><span>Closed At:</span><span>${new Date(closure.closed_at).toLocaleString()}</span></div>
                <div class="summary-row"><span>Total Invoices:</span><span>${closure.total_invoices_count || 0}</span></div>
                <div class="summary-row total"><span>Total Revenue:</span><span><strong>${formatMoney(closure.total_revenue_system)} ETB</strong></span></div>
            </div>
            
            <div class="row mt-3">
                <div class="col-md-6">
                    <div class="summary-box">
                        <h6 class="fw-bold mb-2">System Breakdown</h6>
                        <div class="summary-row"><span>Cash:</span><span>${formatMoney(closure.total_cash_system)} ETB</span></div>
                        <div class="summary-row"><span>Insurance:</span><span>${formatMoney(closure.total_insurance_system)} ETB</span></div>
                        <div class="summary-row"><span>Card:</span><span>${formatMoney(closure.total_card_system)} ETB</span></div>
                        <div class="summary-row"><span>Digital/Mobile:</span><span>${formatMoney(closure.total_mobile_system)} ETB</span></div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="summary-box">
                        <h6 class="fw-bold mb-2">Physical Count</h6>
                        <div class="summary-row"><span>Cash:</span><span>${formatMoney(closure.physical_cash)} ETB</span></div>
                        <div class="summary-row total"><span>Difference:</span>
                            <span class="${diffClass}">${diffText}: ${diffDisplay} ETB</span>
                        </div>
                        ${closure.notes ? `
                        <div class="summary-row"><span>Notes:</span><span class="small">${escapeHtml(closure.notes)}</span></div>
                        ` : ''}
                    </div>
                </div>
            </div>
            
            <div class="mt-3 d-flex gap-2">
                <button class="action-btn action-btn-outline action-btn-sm" onclick="loadClosureSummary()">
                    <i class="fas fa-sync-alt"></i> Refresh
                </button>
                <button class="action-btn action-btn-outline action-btn-sm" onclick="viewClosureDetails(${closure.id})">
                    <i class="fas fa-eye"></i> View Details
                </button>
            </div>
        </div>
    `;
    $('#closureWorkspace').html(html);
}

function viewClosureDetails(closureId) {
    // Find the closure data from the history list
    const closureItem = $(`.closure-item[data-closure-id="${closureId}"]`);
    if (!closureItem.length) {
        showToast('Closure not found.', 'error');
        return;
    }
    
    // We need to fetch the full details from the server
    $.ajax({
        url: window.location.pathname + '?ajax=closure_action&action=fetch_history&csrf_token=' + encodeURIComponent($('#csrfToken').val()),
        method: 'GET',
        success: function(res) {
            if (res.success && res.history) {
                const closure = res.history.find(c => c.id === closureId);
                if (closure) {
                    renderClosureDetailModal(closure);
                } else {
                    showToast('Closure not found.', 'error');
                }
            }
        },
        error: function() { showToast('Network error', 'error'); }
    });
}

function renderClosureDetailModal(closure) {
    const diff = parseFloat(closure.difference);
    const diffClass = getDifferenceClass(diff);
    const diffText = getDifferenceText(diff);
    const diffDisplay = diff > 0 ? '+' + formatMoney(diff) : formatMoney(diff);
    
    const html = `
        <div class="detail-section">
            <div class="row">
                <div class="col-md-6">
                    <div class="info-row">
                        <div class="info-label">Closure ID:</div>
                        <div class="info-value"><strong>#${closure.id}</strong></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Date:</div>
                        <div class="info-value">${escapeHtml(closure.closure_date)}</div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Closed By:</div>
                        <div class="info-value">${escapeHtml(closure.closed_by)}</div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Branch:</div>
                        <div class="info-value">${escapeHtml(closure.branch_name)}</div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="info-row">
                        <div class="info-label">Closed At:</div>
                        <div class="info-value">${new Date(closure.closed_at).toLocaleString()}</div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Invoices:</div>
                        <div class="info-value">${closure.total_invoices_count || 0}</div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Total Revenue:</div>
                        <div class="info-value"><strong>${formatMoney(closure.total_revenue_system)} ETB</strong></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Difference:</div>
                        <div class="info-value ${diffClass}"><strong>${diffText}: ${diffDisplay} ETB</strong></div>
                    </div>
                </div>
            </div>
            
            <hr>
            <h6 class="fw-bold mb-3">Breakdown</h6>
            <div class="row">
                <div class="col-md-6">
                    <div class="summary-box">
                        <h6 class="fw-bold mb-2">System Totals</h6>
                        <div class="summary-row"><span>Cash:</span><span>${formatMoney(closure.total_cash_system)} ETB</span></div>
                        <div class="summary-row"><span>Insurance:</span><span>${formatMoney(closure.total_insurance_system)} ETB</span></div>
                        <div class="summary-row"><span>Card:</span><span>${formatMoney(closure.total_card_system)} ETB</span></div>
                        <div class="summary-row"><span>Digital/Mobile:</span><span>${formatMoney(closure.total_mobile_system)} ETB</span></div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="summary-box">
                        <h6 class="fw-bold mb-2">Physical Count</h6>
                        <div class="summary-row"><span>Cash:</span><span>${formatMoney(closure.physical_cash)} ETB</span></div>
                        <div class="summary-row"><span>Total Physical:</span><span>${formatMoney(closure.physical_cash)} ETB</span></div>
                        ${closure.notes ? `
                        <div class="summary-row"><span>Notes:</span><span class="small">${escapeHtml(closure.notes)}</span></div>
                        ` : ''}
                    </div>
                </div>
            </div>
            
            <div class="mt-3 text-center">
                <button class="action-btn action-btn-outline action-btn-sm" onclick="window.print()">
                    <i class="fas fa-print"></i> Print Report
                </button>
            </div>
        </div>
    `;
    $('#closureDetailBody').html(html);
    $('#closureDetailModal').modal('show');
}

// Auto-refresh
refreshHistory();
refreshMetrics();

if (refreshTimer) clearInterval(refreshTimer);
refreshTimer = setInterval(() => {
    refreshHistory();
    refreshMetrics();
}, 15000);

$(document).ready(function() {
    // Auto-select first row if exists
    setTimeout(() => {
        const firstItem = $('.closure-item:first');
        if (firstItem.length) {
            firstItem.click();
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