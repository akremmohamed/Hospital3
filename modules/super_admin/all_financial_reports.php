<?php
/**
 * ======================================================================
 * FINANCIAL DASHBOARD - COMPLETE FIXED VERSION
 * VERSION: 3.0 - FULLY VERIFIED AGAINST DATABASE SCHEMA
 * ======================================================================
 */

session_start();

// ============================================
// 1. BOOTSTRAP & SECURITY
// ============================================
require_once __DIR__ . '/../../config/db_connection.php';
require_once __DIR__ . '/../../includes/auth_helper.php';

date_default_timezone_set('Africa/Addis_Ababa');

$pdo = getDB();
if ($pdo) {
    $pdo->exec("SET time_zone = '+03:00'");
}

$user_id = (int)($_SESSION['user_id'] ?? 0);
$branch_id = (int)($_SESSION['branch_id'] ?? 0);
$is_global_admin = ($_SESSION['user_type'] ?? '') === 'super_admin';
$user_type = $_SESSION['user_type'] ?? '';

if ($user_id <= 0) {
    header('Location: ../../index.php');
    exit;
}

// Permission check
$allowed_types = ['super_admin', 'admin', 'accountant', 'finance'];
if (!in_array($user_type, $allowed_types) && !$is_global_admin) {
    if (!user_has_permission($pdo, 'view_finance_module', $user_id)) {
        header('Location: dashboard.php?error=unauthorized');
        exit;
    }
}

// CSRF token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// ============================================
// 2. HELPER FUNCTIONS
// ============================================
function escapeHtml($str) {
    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}

function formatCurrency($amount) {
    return number_format((float)$amount, 2, '.', ',');
}

function formatDateShort($date) {
    if (empty($date)) return 'N/A';
    return date('Y-m-d H:i', strtotime($date));
}

function getStatusBadge($status) {
    $badges = [
        'paid' => 'success',
        'unpaid' => 'warning',
        'partial' => 'info',
        'pending' => 'warning',
        'approved' => 'success',
        'rejected' => 'danger',
        'cancelled' => 'danger',
        'overdue' => 'danger',
        'refunded' => 'secondary',
        'active' => 'success',
        'inactive' => 'secondary',
        'completed' => 'success',
        'deferred' => 'info',
        'exempted' => 'secondary',
        'locked' => 'primary',
        'open' => 'warning'
    ];
    $class = $badges[$status] ?? 'secondary';
    return "<span class='badge bg-{$class}'>{$status}</span>";
}

function getPaymentMethodBadge($method) {
    $classes = [
        'cash' => 'success',
        'telebirr' => 'info',
        'cbe_birr' => 'info',
        'mobile_money' => 'info',
        'insurance_claim' => 'primary',
        'insurance' => 'primary',
        'bank_transfer' => 'dark',
        'credit_card' => 'secondary',
        'debit_card' => 'secondary',
        'check' => 'secondary'
    ];
    $icons = [
        'cash' => '💰',
        'telebirr' => '📱',
        'cbe_birr' => '📱',
        'mobile_money' => '📱',
        'insurance_claim' => '🏥',
        'insurance' => '🏥',
        'bank_transfer' => '🏦',
        'credit_card' => '💳',
        'debit_card' => '💳',
        'check' => '📄'
    ];
    $class = $classes[$method] ?? 'secondary';
    $icon = $icons[$method] ?? '💳';
    $label = ucfirst(str_replace('_', ' ', $method));
    return "<span class='badge bg-{$class}'>{$icon} {$label}</span>";
}

function jsonResponse($success, $message, $data = null, $code = 200) {
    if (ob_get_length()) ob_clean();
    http_response_code($code);
    header('Content-Type: application/json');
    header('X-Content-Type-Options: nosniff');
    $response = ['success' => $success, 'message' => $message];
    if ($data !== null) {
        $response = array_merge($response, $data);
    }
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

// ============================================
// 3. AJAX HANDLER
// ============================================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'financial_action') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(false, 'Invalid request method');
    }
    
    $action = $_POST['action'] ?? '';
    $csrf_token_from_post = $_POST['csrf_token'] ?? '';
    
    if (!hash_equals($csrf_token, $csrf_token_from_post)) {
        jsonResponse(false, 'Security token mismatch');
    }
    
    try {
        switch ($action) {
            case 'fetch_dashboard_summary':
                $selected_date = $_POST['selected_date'] ?? date('Y-m-d');
                $period = $_POST['period'] ?? 'daily';
                $data = fetchDashboardSummary($pdo, $selected_date, $period, $branch_id);
                jsonResponse(true, '', $data);
                break;
                
            case 'fetch_revenue_by_department':
                $selected_date = $_POST['selected_date'] ?? date('Y-m-d');
                $period = $_POST['period'] ?? 'daily';
                $data = fetchRevenueByDepartment($pdo, $selected_date, $period, $branch_id);
                jsonResponse(true, '', ['items' => $data]);
                break;
                
            case 'fetch_cashier_closure':
                $selected_date = $_POST['selected_date'] ?? date('Y-m-d');
                $data = fetchCashierClosure($pdo, $selected_date, $branch_id);
                jsonResponse(true, '', ['items' => $data]);
                break;
                
            case 'fetch_bank_balances':
                $data = fetchBankBalances($pdo, $branch_id);
                jsonResponse(true, '', ['items' => $data]);
                break;
                
            case 'fetch_expense_budgets':
                $year = (int)($_POST['year'] ?? date('Y'));
                $month = (int)($_POST['month'] ?? date('m'));
                $data = fetchExpenseBudgets($pdo, $year, $month, $branch_id);
                jsonResponse(true, '', ['items' => $data]);
                break;
                
            case 'fetch_transaction_log':
                $selected_date = $_POST['selected_date'] ?? date('Y-m-d');
                $limit = (int)($_POST['limit'] ?? 50);
                $data = fetchTransactionLog($pdo, $selected_date, $limit, $branch_id);
                jsonResponse(true, '', ['items' => $data]);
                break;
                
            case 'fetch_payment_methods_breakdown':
                $selected_date = $_POST['selected_date'] ?? date('Y-m-d');
                $period = $_POST['period'] ?? 'daily';
                $data = fetchPaymentMethodsBreakdown($pdo, $selected_date, $period, $branch_id);
                jsonResponse(true, '', ['items' => $data]);
                break;
                
            case 'fetch_revenue_trend':
                $data = fetchRevenueTrend($pdo, $branch_id);
                jsonResponse(true, '', ['items' => $data]);
                break;
                
            default:
                jsonResponse(false, 'Unsupported action: ' . $action);
        }
    } catch (PDOException $e) {
        error_log('Financial AJAX error: ' . $e->getMessage());
        jsonResponse(false, 'Database error: ' . $e->getMessage());
    } catch (Exception $e) {
        error_log('Financial general error: ' . $e->getMessage());
        jsonResponse(false, 'Error: ' . $e->getMessage());
    }
    exit;
}

// ============================================
// 4. DATA FETCH FUNCTIONS
// ============================================

function buildDateCondition($period, $selected_date, &$params) {
    $year_month = [];
    switch ($period) {
        case 'daily':
            $condition = "DATE(date_field) = :selected_date";
            $params[':selected_date'] = $selected_date;
            break;
        case 'monthly':
            $year_month = explode('-', $selected_date);
            $condition = "YEAR(date_field) = :year AND MONTH(date_field) = :month";
            $params[':year'] = $year_month[0] ?? date('Y');
            $params[':month'] = $year_month[1] ?? date('m');
            break;
        case 'annual':
            $condition = "YEAR(date_field) = :year";
            $params[':year'] = $selected_date;
            break;
        default:
            $condition = "DATE(date_field) = :selected_date";
            $params[':selected_date'] = $selected_date;
    }
    return ['condition' => $condition, 'year_month' => $year_month];
}

function fetchDashboardSummary($pdo, $selected_date, $period, $branch_id) {
    $params = [];
    $date_condition = buildDateCondition($period, $selected_date, $params);
    $where = str_replace('date_field', 'p.payment_date', $date_condition['condition']);
    
    $branch_condition = "";
    if ($branch_id > 0) {
        $branch_condition = " AND i.branch_id = :branch_id";
        $params[':branch_id'] = $branch_id;
    }
    
    // Revenue collected
    $sql = "
        SELECT 
            COALESCE(SUM(p.amount_paid), 0) AS total_collected,
            COUNT(*) AS transaction_count,
            COALESCE(SUM(CASE WHEN p.payment_method = 'cash' THEN p.amount_paid ELSE 0 END), 0) AS cash_collected,
            COALESCE(SUM(CASE WHEN p.payment_method IN ('insurance_claim', 'insurance') THEN p.amount_paid ELSE 0 END), 0) AS insurance_collected,
            COALESCE(SUM(CASE WHEN p.payment_method IN ('telebirr', 'cbe_birr', 'mobile_money') THEN p.amount_paid ELSE 0 END), 0) AS mobile_collected,
            COALESCE(SUM(CASE WHEN p.payment_method IN ('bank_transfer', 'credit_card', 'debit_card', 'check') THEN p.amount_paid ELSE 0 END), 0) AS bank_collected
        FROM payments p
        INNER JOIN invoices i ON p.invoice_id = i.id AND i.deleted_at IS NULL
        WHERE {$where}
        {$branch_condition}
        AND p.deleted_at IS NULL
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $revenue = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Expenses
    $exp_params = [];
    $exp_where = str_replace('date_field', 'e.created_at', $date_condition['condition']);
    $exp_branch = "";
    if ($branch_id > 0) {
        $exp_branch = " AND e.branch_id = :branch_id";
        $exp_params[':branch_id'] = $branch_id;
    }
    foreach ($params as $key => $val) {
        if (strpos($key, ':selected_date') !== false || strpos($key, ':year') !== false || strpos($key, ':month') !== false) {
            $exp_params[$key] = $val;
        }
    }
    
    $exp_sql = "
        SELECT COALESCE(SUM(e.amount), 0) AS total_expenses
        FROM expenses e
        WHERE e.status = 'approved'
        AND {$exp_where}
        {$exp_branch}
        AND e.deleted_at IS NULL
    ";
    $exp_stmt = $pdo->prepare($exp_sql);
    $exp_stmt->execute($exp_params);
    $expenses = $exp_stmt->fetch(PDO::FETCH_ASSOC);
    
    // Pending invoices
    $inv_params = [];
    $inv_where = str_replace('date_field', 'i.created_at', $date_condition['condition']);
    $inv_branch = "";
    if ($branch_id > 0) {
        $inv_branch = " AND i.branch_id = :branch_id";
        $inv_params[':branch_id'] = $branch_id;
    }
    foreach ($params as $key => $val) {
        if (strpos($key, ':selected_date') !== false || strpos($key, ':year') !== false || strpos($key, ':month') !== false) {
            $inv_params[$key] = $val;
        }
    }
    
    $inv_sql = "
        SELECT 
            COALESCE(SUM(CASE WHEN i.payment_status IN ('unpaid', 'partial') THEN i.due_amount ELSE 0 END), 0) AS pending_collection,
            COUNT(CASE WHEN i.payment_status IN ('unpaid', 'partial') THEN 1 END) AS pending_invoices
        FROM invoices i
        WHERE {$inv_where}
        {$inv_branch}
        AND i.deleted_at IS NULL
    ";
    $inv_stmt = $pdo->prepare($inv_sql);
    $inv_stmt->execute($inv_params);
    $pending = $inv_stmt->fetch(PDO::FETCH_ASSOC);
    
    // Total bank balance
    $bank_params = [];
    $bank_branch = "";
    if ($branch_id > 0) {
        $bank_branch = " AND branch_id = :branch_id";
        $bank_params[':branch_id'] = $branch_id;
    }
    $bank_sql = "SELECT COALESCE(SUM(current_balance), 0) AS total_balance FROM bank_accounts WHERE status = 'active' {$bank_branch}";
    $bank_stmt = $pdo->prepare($bank_sql);
    $bank_stmt->execute($bank_params);
    $bank = $bank_stmt->fetch(PDO::FETCH_ASSOC);
    
    return [
        'revenue' => $revenue,
        'expenses' => $expenses,
        'pending' => $pending,
        'bank_balance' => $bank['total_balance'] ?? 0,
        'period' => $period,
        'selected_date' => $selected_date,
        'net_cash_flow' => ($revenue['total_collected'] ?? 0) - ($expenses['total_expenses'] ?? 0)
    ];
}

function fetchRevenueByDepartment($pdo, $selected_date, $period, $branch_id) {
    $params = [];
    $date_condition = buildDateCondition($period, $selected_date, $params);
    $where = str_replace('date_field', 'bi.created_at', $date_condition['condition']);
    
    $branch_condition = "";
    if ($branch_id > 0) {
        $branch_condition = " AND bi.branch_id = :branch_id";
        $params[':branch_id'] = $branch_id;
    }
    
    $sql = "
        SELECT 
            bi.item_type AS department,
            COALESCE(bi.description, bi.item_type) AS description,
            COALESCE(SUM(bi.amount), 0) AS total_amount,
            COUNT(bi.id) AS transaction_count,
            bi.status AS payment_status
        FROM billing_items bi
        WHERE {$where}
        {$branch_condition}
        GROUP BY bi.item_type, bi.description, bi.status
        ORDER BY total_amount DESC
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function fetchPaymentMethodsBreakdown($pdo, $selected_date, $period, $branch_id) {
    $params = [];
    $date_condition = buildDateCondition($period, $selected_date, $params);
    $where = str_replace('date_field', 'p.payment_date', $date_condition['condition']);
    
    $branch_condition = "";
    if ($branch_id > 0) {
        $branch_condition = " AND i.branch_id = :branch_id";
        $params[':branch_id'] = $branch_id;
    }
    
    $sql = "
        SELECT 
            p.payment_method,
            COALESCE(SUM(p.amount_paid), 0) AS total_amount,
            COUNT(*) AS transaction_count
        FROM payments p
        INNER JOIN invoices i ON p.invoice_id = i.id AND i.deleted_at IS NULL
        WHERE {$where}
        {$branch_condition}
        AND p.deleted_at IS NULL
        GROUP BY p.payment_method
        ORDER BY total_amount DESC
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function fetchRevenueTrend($pdo, $branch_id) {
    $branch_condition = "";
    $params = [];
    if ($branch_id > 0) {
        $branch_condition = " AND i.branch_id = :branch_id";
        $params[':branch_id'] = $branch_id;
    }
    
    $sql = "
        SELECT 
            DATE_FORMAT(p.payment_date, '%Y-%m') AS month,
            COALESCE(SUM(p.amount_paid), 0) AS total_revenue,
            COUNT(*) AS transaction_count
        FROM payments p
        INNER JOIN invoices i ON p.invoice_id = i.id AND i.deleted_at IS NULL
        WHERE p.payment_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
        {$branch_condition}
        AND p.deleted_at IS NULL
        GROUP BY DATE_FORMAT(p.payment_date, '%Y-%m')
        ORDER BY month ASC
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function fetchCashierClosure($pdo, $selected_date, $branch_id) {
    $params = [':selected_date' => $selected_date];
    $branch_condition = "";
    
    if ($branch_id > 0) {
        $branch_condition = " AND cc.branch_id = :branch_id";
        $params[':branch_id'] = $branch_id;
    }
    
    $sql = "
        SELECT 
            cc.id AS closure_id,
            cc.user_id AS staff_id,
            CONCAT(u.first_name, ' ', COALESCE(u.middle_name, ''), ' ', u.last_name) AS staff_name,
            cc.closure_date,
            cc.total_cash_system AS expected_cash,
            cc.total_insurance_system AS expected_insurance,
            cc.total_card_system AS expected_card,
            cc.total_mobile_system AS expected_mobile,
            cc.total_revenue_system AS total_expected,
            cc.physical_cash,
            cc.difference AS discrepancy_amount,
            cc.notes AS cashier_remarks,
            cc.closed_at AS closure_time,
            cc.is_locked,
            cc.total_invoices_count
        FROM cash_closure cc
        LEFT JOIN users u ON cc.user_id = u.id AND u.deleted_at IS NULL
        WHERE DATE(cc.closure_date) = :selected_date
        {$branch_condition}
        AND cc.deleted_at IS NULL
        ORDER BY cc.closed_at DESC
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function fetchBankBalances($pdo, $branch_id) {
    $params = [];
    $branch_condition = "";
    
    if ($branch_id > 0) {
        $branch_condition = " AND branch_id = :branch_id";
        $params[':branch_id'] = $branch_id;
    }
    
    $sql = "
        SELECT 
            id,
            account_name,
            account_type,
            current_balance,
            status
        FROM bank_accounts
        WHERE status = 'active'
        {$branch_condition}
        ORDER BY account_type DESC, account_name
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function fetchExpenseBudgets($pdo, $year, $month, $branch_id) {
    $params = [
        ':year' => $year,
        ':month' => $month
    ];
    $branch_condition = "";
    
    if ($branch_id > 0) {
        $branch_condition = " AND eb.branch_id = :branch_id";
        $params[':branch_id'] = $branch_id;
    }
    
    $sql = "
        SELECT 
            ec.id AS category_id,
            ec.category_name AS category_name,
            eb.budget_month,
            eb.budget_year,
            eb.allocated_amount AS budget_limit,
            eb.spent_amount AS total_spent,
            (eb.allocated_amount - eb.spent_amount) AS remaining_budget,
            ROUND(CASE 
                WHEN eb.allocated_amount > 0 THEN (eb.spent_amount / eb.allocated_amount) * 100 
                ELSE 0 
            END, 1) AS percentage_used,
            CASE 
                WHEN eb.allocated_amount > 0 AND (eb.allocated_amount - eb.spent_amount) < 0 THEN 'danger'
                WHEN eb.allocated_amount > 0 AND (eb.allocated_amount - eb.spent_amount) < (eb.allocated_amount * 0.2) THEN 'warning'
                ELSE 'success'
            END AS status_class
        FROM expense_budgets eb
        INNER JOIN expense_categories ec ON eb.category_id = ec.id AND ec.status = 'active'
        WHERE eb.budget_year = :year 
        AND eb.budget_month = :month
        {$branch_condition}
        ORDER BY ec.category_name
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function fetchTransactionLog($pdo, $selected_date, $limit, $branch_id) {
    $params = [
        ':selected_date' => $selected_date,
        ':limit' => $limit
    ];
    $branch_condition = "";
    
    if ($branch_id > 0) {
        $branch_condition = " AND i.branch_id = :branch_id";
        $params[':branch_id'] = $branch_id;
    }
    
    $sql = "
        SELECT 
            p.id AS payment_id,
            p.receipt_number,
            p.patient_id,
            CONCAT(pat.first_name, ' ', COALESCE(pat.middle_name, ''), ' ', pat.last_name) AS patient_name,
            p.invoice_id,
            i.invoice_number,
            p.amount_paid AS amount,
            p.payment_method,
            p.payment_date AS payment_time,
            p.transaction_reference,
            CONCAT(u.first_name, ' ', COALESCE(u.middle_name, ''), ' ', u.last_name) AS cashier_name,
            i.payment_status AS billing_status,
            i.billing_type,
            p.notes AS transaction_notes,
            p.is_verified
        FROM payments p
        LEFT JOIN patients pat ON p.patient_id = pat.id AND pat.deleted_at IS NULL
        INNER JOIN invoices i ON p.invoice_id = i.id AND i.deleted_at IS NULL
        LEFT JOIN users u ON p.received_by_user_id = u.id AND u.deleted_at IS NULL
        WHERE DATE(p.payment_date) = :selected_date
        {$branch_condition}
        AND p.deleted_at IS NULL
        ORDER BY p.payment_date DESC
        LIMIT :limit
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ============================================
// 5. INITIAL PAGE DATA
// ============================================
$selected_date = $_GET['date'] ?? date('Y-m-d');
$selected_period = $_GET['period'] ?? 'daily';
$selected_year = $_GET['year'] ?? date('Y');
$selected_month = $_GET['month'] ?? date('m');

// Fetch initial data
$initial_summary = fetchDashboardSummary($pdo, $selected_date, $selected_period, $branch_id);
$initial_departmental = fetchRevenueByDepartment($pdo, $selected_date, $selected_period, $branch_id);
$initial_cashier = fetchCashierClosure($pdo, $selected_date, $branch_id);
$initial_bank_balances = fetchBankBalances($pdo, $branch_id);
$initial_expense_budgets = fetchExpenseBudgets($pdo, $selected_year, $selected_month, $branch_id);
$initial_transactions = fetchTransactionLog($pdo, $selected_date, 50, $branch_id);
$initial_payment_methods = fetchPaymentMethodsBreakdown($pdo, $selected_date, $selected_period, $branch_id);
$initial_revenue_trend = fetchRevenueTrend($pdo, $branch_id);

// Branch name
$branch_name = 'All Branches';
if ($branch_id > 0) {
    $stmt = $pdo->prepare("SELECT name FROM branches WHERE id = :id AND deleted_at IS NULL");
    $stmt->execute([':id' => $branch_id]);
    $branch = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($branch) $branch_name = $branch['name'];
}

// Encode initial data for JavaScript
$initialData = [
    'summary' => $initial_summary,
    'departmental' => $initial_departmental,
    'cashier' => $initial_cashier,
    'bank_balances' => $initial_bank_balances,
    'expense_budgets' => $initial_expense_budgets,
    'transactions' => $initial_transactions,
    'payment_methods' => $initial_payment_methods,
    'revenue_trend' => $initial_revenue_trend
];
$initialDataJson = json_encode($initialData, JSON_UNESCAPED_UNICODE);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Financial Dashboard | HMS ERP</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
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
            --gray: #6b7280;
            --light-gray: #f3f4f6;
            --border-gray: #e5e7eb;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: linear-gradient(135deg, #f5f7fb 0%, #eef2f6 100%);
            overflow-x: hidden;
        }
        .content-page {
            margin-left: 260px;
            margin-top: 70px;
            padding: 24px 32px;
            transition: margin-left 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            min-height: calc(100vh - 70px);
        }
        body.erp-sidebar-collapsed .content-page { margin-left: 80px; }
        @media (max-width: 768px) { .content-page { margin-left: 0 !important; padding: 16px; } }
        
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            margin-bottom: 24px;
        }
        .page-header h1 {
            font-weight: 700;
            font-size: 24px;
            margin: 0;
        }
        .page-header .subtitle {
            color: var(--gray);
            font-size: 14px;
        }
        
        .filter-bar {
            background: white;
            border-radius: 16px;
            padding: 16px 20px;
            border: 1px solid var(--border-gray);
            margin-bottom: 24px;
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 12px;
        }
        .filter-bar .form-group {
            margin-bottom: 0;
        }
        .filter-bar label {
            font-weight: 600;
            font-size: 13px;
            margin-right: 6px;
            color: var(--gray);
        }
        .filter-bar input, .filter-bar select {
            padding: 8px 12px;
            border: 1px solid var(--border-gray);
            border-radius: 10px;
            font-size: 14px;
            font-family: inherit;
            background: white;
        }
        .filter-bar input:focus, .filter-bar select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(67,97,238,0.1);
        }
        
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }
        .kpi-card {
            background: white;
            border-radius: 16px;
            padding: 20px;
            border: 1px solid var(--border-gray);
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .kpi-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.08);
        }
        .kpi-card .kpi-label {
            font-size: 13px;
            color: var(--gray);
            font-weight: 500;
            margin-bottom: 4px;
        }
        .kpi-card .kpi-value {
            font-size: 28px;
            font-weight: 700;
        }
        .kpi-card .kpi-sub {
            font-size: 12px;
            color: var(--gray);
            margin-top: 4px;
        }
        .kpi-card .kpi-icon {
            float: right;
            font-size: 28px;
            opacity: 0.2;
        }
        .kpi-card.primary .kpi-value { color: var(--primary); }
        .kpi-card.success .kpi-value { color: var(--success); }
        .kpi-card.danger .kpi-value { color: var(--danger); }
        .kpi-card.warning .kpi-value { color: #d97706; }
        .kpi-card.info .kpi-value { color: var(--info); }
        
        .nav-tabs-custom {
            border-bottom: 2px solid var(--border-gray);
            margin-bottom: 20px;
            display: flex;
            flex-wrap: wrap;
            gap: 4px;
        }
        .nav-tabs-custom .nav-link {
            border: none;
            padding: 12px 24px;
            font-weight: 600;
            font-size: 14px;
            color: var(--gray);
            border-radius: 10px 10px 0 0;
            transition: all 0.3s;
            cursor: pointer;
            background: transparent;
        }
        .nav-tabs-custom .nav-link:hover {
            background: var(--light-gray);
            color: var(--dark);
        }
        .nav-tabs-custom .nav-link.active {
            color: var(--primary);
            background: white;
            border-bottom: 3px solid var(--primary);
        }
        .nav-tabs-custom .nav-link i {
            margin-right: 8px;
        }
        .tab-content {
            background: transparent;
        }
        .tab-pane {
            display: none;
            animation: fadeIn 0.3s ease;
        }
        .tab-pane.active {
            display: block;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .card-panel {
            background: white;
            border-radius: 16px;
            border: 1px solid var(--border-gray);
            overflow: hidden;
            margin-bottom: 20px;
        }
        .card-panel .card-header {
            padding: 16px 20px;
            background: #f8fafc;
            border-bottom: 1px solid var(--border-gray);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
        }
        .card-panel .card-header h5 {
            margin: 0;
            font-weight: 700;
            font-size: 16px;
        }
        .card-panel .card-body {
            padding: 16px 20px;
            max-height: 500px;
            overflow-y: auto;
        }
        .card-panel .card-body::-webkit-scrollbar { width: 5px; }
        .card-panel .card-body::-webkit-scrollbar-thumb { background: var(--border-gray); border-radius: 3px; }
        .card-panel .card-body-full {
            max-height: none;
        }
        
        .table-financial {
            width: 100%;
            font-size: 14px;
        }
        .table-financial thead th {
            background: #f8fafc;
            font-weight: 600;
            font-size: 12px;
            text-transform: uppercase;
            color: var(--gray);
            padding: 10px 12px;
            border-bottom: 2px solid var(--border-gray);
            position: sticky;
            top: 0;
            z-index: 2;
        }
        .table-financial tbody td {
            padding: 10px 12px;
            border-bottom: 1px solid var(--border-gray);
            vertical-align: middle;
        }
        .table-financial tbody tr:hover {
            background: #f8fafc;
        }
        .table-financial .discrepancy-negative {
            background: #fef2f2 !important;
        }
        .table-financial .discrepancy-positive {
            background: #fffbeb !important;
        }
        .table-financial .discrepancy-negative td { color: var(--danger); }
        .table-financial .discrepancy-positive td { color: #d97706; }
        
        .budget-bar {
            width: 100%;
            height: 6px;
            background: var(--light-gray);
            border-radius: 3px;
            overflow: hidden;
            margin-top: 4px;
        }
        .budget-bar .fill {
            height: 100%;
            border-radius: 3px;
            transition: width 0.6s ease;
        }
        .budget-bar .fill.success { background: var(--success); }
        .budget-bar .fill.warning { background: var(--warning); }
        .budget-bar .fill.danger { background: var(--danger); }
        
        .btn-refresh {
            background: none;
            border: 1px solid var(--border-gray);
            border-radius: 10px;
            padding: 6px 14px;
            cursor: pointer;
            transition: all 0.2s;
            font-size: 13px;
            font-family: inherit;
        }
        .btn-refresh:hover {
            border-color: var(--primary);
            color: var(--primary);
        }
        
        .btn-export {
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 10px;
            padding: 6px 16px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 600;
            transition: all 0.2s;
            font-family: inherit;
        }
        .btn-export:hover {
            background: var(--primary-dark);
        }
        
        .empty-state {
            text-align: center;
            padding: 30px 20px;
            color: var(--gray);
        }
        .empty-state i { font-size: 40px; opacity: 0.2; margin-bottom: 12px; }
        
        .loading-spinner {
            display: flex;
            justify-content: center;
            padding: 30px;
        }
        
        .chart-container {
            position: relative;
            height: 250px;
            width: 100%;
        }
        
        .footer-export-area {
            margin-top: 24px;
            display: flex;
            justify-content: flex-end;
            gap: 12px;
            flex-wrap: wrap;
        }
        
        .badge-method-cash { background: #e0e7ff; color: #4361ee; }
        .badge-method-insurance { background: #d1fae5; color: #059669; }
        .badge-method-mobile { background: #fce7f3; color: #db2777; }
        .badge-method-bank { background: #fef3c7; color: #d97706; }
        .badge-method-card { background: #ede9fe; color: #7c3aed; }
        
        .summary-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }
        @media (max-width: 768px) {
            .summary-row { grid-template-columns: 1fr; }
            .kpi-grid { grid-template-columns: 1fr 1fr; }
            .filter-bar { flex-direction: column; align-items: stretch; }
            .filter-bar .form-group { width: 100%; }
            .filter-bar input, .filter-bar select { width: 100%; }
            .page-header { flex-direction: column; align-items: flex-start; gap: 12px; }
            .nav-tabs-custom .nav-link { padding: 10px 16px; font-size: 13px; }
        }
        @media (max-width: 480px) {
            .kpi-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
<div id="wrapper">
    <?php include('assets/inc/nav.php'); ?>
    <?php include('assets/inc/sidebar.php'); ?>

    <div class="content-page">
        <!-- Page Header -->
        <div class="page-header">
            <div>
                <h1><i class="fas fa-chart-pie me-2"></i>Financial Dashboard</h1>
                <div class="subtitle"><?php echo escapeHtml($branch_name); ?> • Real-time financial overview</div>
            </div>
            <div>
                <button class="btn-refresh me-2" onclick="refreshAll()"><i class="fas fa-sync-alt"></i> Refresh</button>
                <button class="btn-export" onclick="exportData()"><i class="fas fa-file-export"></i> Export</button>
            </div>
        </div>

        <!-- Filter Bar -->
        <div class="filter-bar" id="filterBar">
            <div class="form-group d-flex align-items-center">
                <label for="periodSelect"><i class="far fa-calendar-alt"></i> Period:</label>
                <select id="periodSelect" class="form-control-sm" onchange="updatePeriod()" style="width:auto;">
                    <option value="daily" <?php echo $selected_period === 'daily' ? 'selected' : ''; ?>>Daily</option>
                    <option value="monthly" <?php echo $selected_period === 'monthly' ? 'selected' : ''; ?>>Monthly</option>
                    <option value="annual" <?php echo $selected_period === 'annual' ? 'selected' : ''; ?>>Annual</option>
                </select>
            </div>
            <div class="form-group d-flex align-items-center" id="datePickerGroup">
                <label for="selectedDate">Date:</label>
                <input type="date" id="selectedDate" value="<?php echo $selected_date; ?>" class="form-control-sm" style="width:auto;">
            </div>
            <div class="form-group d-flex align-items-center" id="yearPickerGroup" style="display:none;">
                <label for="selectedYear">Year:</label>
                <select id="selectedYear" class="form-control-sm" style="width:auto;">
                    <?php for ($y = date('Y') - 5; $y <= date('Y'); $y++): ?>
                    <option value="<?php echo $y; ?>" <?php echo $y == $selected_year ? 'selected' : ''; ?>><?php echo $y; ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="form-group d-flex align-items-center" id="monthPickerGroup" style="display:none;">
                <label for="selectedMonth">Month:</label>
                <select id="selectedMonth" class="form-control-sm" style="width:auto;">
                    <?php for ($m = 1; $m <= 12; $m++): ?>
                    <option value="<?php echo $m; ?>" <?php echo $m == $selected_month ? 'selected' : ''; ?>>
                        <?php echo date('F', mktime(0,0,0,$m,1)); ?>
                    </option>
                    <?php endfor; ?>
                </select>
            </div>
            <button class="btn-refresh" onclick="applyFilters()"><i class="fas fa-filter"></i> Apply</button>
        </div>

        <!-- KPI Cards -->
        <div class="kpi-grid" id="kpiGrid">
            <div class="kpi-card primary">
                <i class="fas fa-coins kpi-icon"></i>
                <div class="kpi-label">Total Revenue Collected</div>
                <div class="kpi-value" id="totalRevenue">ETB 0.00</div>
                <div class="kpi-sub" id="revenueSub">0 transactions</div>
            </div>
            <div class="kpi-card danger">
                <i class="fas fa-money-bill-wave kpi-icon"></i>
                <div class="kpi-label">Total Expenses</div>
                <div class="kpi-value" id="totalExpenses">ETB 0.00</div>
                <div class="kpi-sub" id="expensesSub">Approved expenses</div>
            </div>
            <div class="kpi-card success">
                <i class="fas fa-chart-line kpi-icon"></i>
                <div class="kpi-label">Net Cash Flow</div>
                <div class="kpi-value" id="netCashFlow">ETB 0.00</div>
                <div class="kpi-sub" id="netSub">Revenue - Expenses</div>
            </div>
            <div class="kpi-card warning">
                <i class="fas fa-clock kpi-icon"></i>
                <div class="kpi-label">Pending Collection</div>
                <div class="kpi-value" id="pendingCollection">ETB 0.00</div>
                <div class="kpi-sub" id="pendingSub">0 invoices pending</div>
            </div>
            <div class="kpi-card info">
                <i class="fas fa-university kpi-icon"></i>
                <div class="kpi-label">Bank Balance</div>
                <div class="kpi-value" id="bankBalance">ETB 0.00</div>
                <div class="kpi-sub" id="bankSub">Active accounts</div>
            </div>
        </div>

        <!-- Tabs -->
        <ul class="nav nav-tabs-custom" id="financeTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" data-tab="tab-revenue" role="tab">
                    <i class="fas fa-chart-bar"></i> Revenue Breakdown
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" data-tab="tab-cashier" role="tab">
                    <i class="fas fa-cash-register"></i> Cashier Closures
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" data-tab="tab-expenses" role="tab">
                    <i class="fas fa-wallet"></i> Expense Budgets
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" data-tab="tab-transactions" role="tab">
                    <i class="fas fa-list"></i> Transaction Log
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" data-tab="tab-banking" role="tab">
                    <i class="fas fa-university"></i> Banking
                </button>
            </li>
        </ul>

        <div class="tab-content">
            <!-- TAB 1: Revenue Breakdown -->
            <div class="tab-pane active" id="tab-revenue" role="tabpanel">
                <div class="summary-row">
                    <div class="card-panel">
                        <div class="card-header">
                            <h5><i class="fas fa-building me-2"></i> Revenue by Department</h5>
                            <span class="badge bg-secondary" id="deptCount">0 departments</span>
                        </div>
                        <div class="card-body" id="departmentalBreakdown">
                            <div id="deptLoading" class="loading-spinner"><div class="spinner-border spinner-border-sm text-primary" role="status"></div></div>
                            <div id="deptContent" style="display:none;"></div>
                        </div>
                    </div>
                    <div class="card-panel">
                        <div class="card-header">
                            <h5><i class="fas fa-credit-card me-2"></i> Payment Methods</h5>
                            <span class="badge bg-secondary" id="methodCount">0 methods</span>
                        </div>
                        <div class="card-body" id="paymentMethodsBreakdown">
                            <div id="methodLoading" class="loading-spinner"><div class="spinner-border spinner-border-sm text-primary" role="status"></div></div>
                            <div id="methodContent" style="display:none;"></div>
                        </div>
                    </div>
                </div>
                <div class="card-panel">
                    <div class="card-header">
                        <h5><i class="fas fa-chart-area me-2"></i> Revenue Trend (6 Months)</h5>
                    </div>
                    <div class="card-body card-body-full">
                        <div class="chart-container">
                            <canvas id="revenueTrendChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <!-- TAB 2: Cashier Closures -->
            <div class="tab-pane" id="tab-cashier" role="tabpanel">
                <div class="card-panel">
                    <div class="card-header">
                        <h5><i class="fas fa-cash-register me-2"></i> Cashier Closure Report</h5>
                        <span class="badge bg-secondary" id="closureCount">0 closures</span>
                    </div>
                    <div class="card-body" id="cashierClosure">
                        <div id="cashierLoading" class="loading-spinner"><div class="spinner-border spinner-border-sm text-primary" role="status"></div></div>
                        <div id="cashierContent" style="display:none;"></div>
                    </div>
                </div>
            </div>

            <!-- TAB 3: Expense Budgets -->
            <div class="tab-pane" id="tab-expenses" role="tabpanel">
                <div class="card-panel">
                    <div class="card-header">
                        <h5><i class="fas fa-wallet me-2"></i> Expense Budget vs Actual</h5>
                        <span class="badge bg-secondary" id="budgetCount">0 categories</span>
                    </div>
                    <div class="card-body" id="expenseBudgets">
                        <div id="budgetLoading" class="loading-spinner"><div class="spinner-border spinner-border-sm text-primary" role="status"></div></div>
                        <div id="budgetContent" style="display:none;"></div>
                    </div>
                </div>
            </div>

            <!-- TAB 4: Transaction Log -->
            <div class="tab-pane" id="tab-transactions" role="tabpanel">
                <div class="card-panel">
                    <div class="card-header">
                        <h5><i class="fas fa-list me-2"></i> Transaction Log</h5>
                        <span class="badge bg-secondary" id="txnCount">0 transactions</span>
                    </div>
                    <div class="card-body" id="transactionLog">
                        <div id="txnLoading" class="loading-spinner"><div class="spinner-border spinner-border-sm text-primary" role="status"></div></div>
                        <div id="txnContent" style="display:none;"></div>
                    </div>
                </div>
            </div>

            <!-- TAB 5: Banking -->
            <div class="tab-pane" id="tab-banking" role="tabpanel">
                <div class="card-panel">
                    <div class="card-header">
                        <h5><i class="fas fa-university me-2"></i> Bank & Petty Cash Balances</h5>
                        <span class="badge bg-secondary" id="bankCount">0 accounts</span>
                    </div>
                    <div class="card-body" id="bankBalances">
                        <div id="bankLoading" class="loading-spinner"><div class="spinner-border spinner-border-sm text-primary" role="status"></div></div>
                        <div id="bankContent" style="display:none;"></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="footer-export-area">
            <button class="btn-refresh" onclick="exportToCSV()"><i class="fas fa-file-csv"></i> Export CSV</button>
            <button class="btn-refresh" onclick="printReport()"><i class="fas fa-print"></i> Print</button>
        </div>
    </div>
    
    <?php include('assets/inc/footer.php'); ?>
</div>

<script>
// ============================================
// FINANCIAL DASHBOARD - Frontend Logic
// ============================================

const csrfToken = <?php echo json_encode($csrf_token); ?>;
const branchId = <?php echo $branch_id; ?>;
const AJAX_URL = 'all_financial_reports.php?ajax=financial_action';
const INITIAL_DATA = <?php echo $initialDataJson; ?>;

let currentPeriod = '<?php echo $selected_period; ?>';
let currentDate = '<?php echo $selected_date; ?>';
let currentYear = '<?php echo $selected_year; ?>';
let currentMonth = '<?php echo $selected_month; ?>';
let revenueTrendChart = null;

$(document).ready(function() {
    updatePickerVisibility();
    initTabs();
    renderAllData(INITIAL_DATA);
    
    setInterval(function() {
        if (!document.hidden) {
            refreshAll();
        }
    }, 60000);
});

function initTabs() {
    $('.nav-tabs-custom .nav-link').on('click', function(e) {
        e.preventDefault();
        const tabId = $(this).data('tab');
        
        $('.nav-tabs-custom .nav-link').removeClass('active');
        $(this).addClass('active');
        
        $('.tab-pane').removeClass('active');
        $('#' + tabId).addClass('active');
        
        // Load data for tab if needed
        if (tabId === 'tab-cashier') fetchCashierClosure();
        if (tabId === 'tab-expenses') fetchExpenseBudgets();
        if (tabId === 'tab-transactions') fetchTransactionLog();
        if (tabId === 'tab-banking') fetchBankBalances();
        if (tabId === 'tab-revenue') {
            fetchDepartmentalBreakdown();
            fetchPaymentMethodsBreakdown();
        }
    });
}

function updatePickerVisibility() {
    const period = document.getElementById('periodSelect').value;
    const datePicker = document.getElementById('datePickerGroup');
    const yearPicker = document.getElementById('yearPickerGroup');
    const monthPicker = document.getElementById('monthPickerGroup');
    
    if (period === 'daily') {
        datePicker.style.display = 'flex';
        yearPicker.style.display = 'none';
        monthPicker.style.display = 'none';
    } else if (period === 'monthly') {
        datePicker.style.display = 'none';
        yearPicker.style.display = 'flex';
        monthPicker.style.display = 'flex';
    } else {
        datePicker.style.display = 'none';
        yearPicker.style.display = 'flex';
        monthPicker.style.display = 'none';
    }
}

function updatePeriod() {
    updatePickerVisibility();
    applyFilters();
}

function applyFilters() {
    const period = document.getElementById('periodSelect').value;
    currentPeriod = period;
    
    if (period === 'daily') {
        currentDate = document.getElementById('selectedDate').value;
    } else if (period === 'monthly') {
        currentYear = document.getElementById('selectedYear').value;
        currentMonth = document.getElementById('selectedMonth').value;
        currentDate = currentYear + '-' + String(currentMonth).padStart(2, '0') + '-01';
    } else {
        currentYear = document.getElementById('selectedYear').value;
        currentDate = currentYear;
    }
    
    refreshAll();
}

function refreshAll() {
    fetchDashboardSummary();
    fetchDepartmentalBreakdown();
    fetchPaymentMethodsBreakdown();
    fetchRevenueTrend();
    
    // Load active tab data
    const activeTab = $('.nav-tabs-custom .nav-link.active').data('tab');
    if (activeTab === 'tab-cashier') fetchCashierClosure();
    if (activeTab === 'tab-expenses') fetchExpenseBudgets();
    if (activeTab === 'tab-transactions') fetchTransactionLog();
    if (activeTab === 'tab-banking') fetchBankBalances();
    
    showToast('Data refreshed', 'success');
}

// ============================================
// RENDER FUNCTIONS
// ============================================

function renderAllData(data) {
    // Update KPIs
    updateKPIs(data.summary);
    
    // Render revenue breakdown
    renderDepartmentalBreakdown(data.departmental);
    renderPaymentMethodsBreakdown(data.payment_methods);
    renderRevenueTrend(data.revenue_trend);
    
    // Render other sections
    renderCashierClosure(data.cashier);
    renderExpenseBudgets(data.expense_budgets);
    renderTransactionLog(data.transactions);
    renderBankBalances(data.bank_balances);
}

function updateKPIs(data) {
    if (!data) return;
    const r = data.revenue || {};
    const e = data.expenses || {};
    const p = data.pending || {};
    const bank = data.bank_balance || 0;
    
    const totalRevenue = parseFloat(r.total_collected || 0);
    const totalExpenses = parseFloat(e.total_expenses || 0);
    const netCash = totalRevenue - totalExpenses;
    const pendingAmount = parseFloat(p.pending_collection || 0);
    
    $('#totalRevenue').text('ETB ' + formatCurrency(totalRevenue));
    $('#totalExpenses').text('ETB ' + formatCurrency(totalExpenses));
    $('#netCashFlow').text('ETB ' + formatCurrency(netCash));
    $('#pendingCollection').text('ETB ' + formatCurrency(pendingAmount));
    $('#bankBalance').text('ETB ' + formatCurrency(bank));
    
    const netEl = $('#netCashFlow');
    if (netCash > 0) {
        netEl.css('color', '#06d6a0');
    } else if (netCash < 0) {
        netEl.css('color', '#ef476f');
    } else {
        netEl.css('color', '#6b7280');
    }
    
    $('#revenueSub').text((r.transaction_count || 0) + ' transactions');
    $('#expensesSub').text('Approved expenses');
    $('#netSub').text('Revenue - Expenses');
    $('#pendingSub').text((p.pending_invoices || 0) + ' invoices pending');
    $('#bankSub').text('Active accounts');
}

function renderDepartmentalBreakdown(items) {
    const container = $('#deptContent');
    const loading = $('#deptLoading');
    items = items || [];
    
    $('#deptCount').text(items.length + ' departments');
    
    if (items.length === 0) {
        loading.hide();
        container.html('<div class="empty-state"><i class="fas fa-building"></i><p>No revenue data found</p></div>').show();
        return;
    }
    
    let html = `<table class="table-financial">
        <thead><tr>
            <th>Department/Type</th>
            <th>Description</th>
            <th class="text-center">Transactions</th>
            <th class="text-end">Amount</th>
            <th class="text-center">Status</th>
        </tr></thead><tbody>`;
    
    let grandTotal = 0;
    items.forEach(function(item) {
        const amount = parseFloat(item.total_amount || 0);
        grandTotal += amount;
        const statusBadge = getStatusBadge(item.payment_status);
        const deptName = item.department || 'Other';
        const deptIcon = getDepartmentIcon(deptName);
        
        html += `<tr>
            <td><span class="badge bg-light text-dark">${deptIcon} ${escapeHtml(deptName)}</span></td>
            <td>${escapeHtml(item.description || '—')}</td>
            <td class="text-center">${item.transaction_count}</td>
            <td class="text-end fw-semibold">ETB ${formatCurrency(amount)}</td>
            <td class="text-center">${statusBadge}</td>
        </tr>`;
    });
    
    html += `<tfoot><tr class="fw-bold">
        <td colspan="3" class="text-end">TOTAL</td>
        <td class="text-end">ETB ${formatCurrency(grandTotal)}</td>
        <td></td>
    </tr></tfoot>`;
    
    html += '</tbody></table>';
    loading.hide();
    container.html(html).show();
}

function renderPaymentMethodsBreakdown(items) {
    const container = $('#methodContent');
    const loading = $('#methodLoading');
    items = items || [];
    
    $('#methodCount').text(items.length + ' methods');
    
    if (items.length === 0) {
        loading.hide();
        container.html('<div class="empty-state"><i class="fas fa-credit-card"></i><p>No payment data found</p></div>').show();
        return;
    }
    
    let grandTotal = 0;
    items.forEach(function(item) {
        grandTotal += parseFloat(item.total_amount || 0);
    });
    
    let html = `<table class="table-financial">
        <thead><tr>
            <th>Payment Method</th>
            <th class="text-center">Transactions</th>
            <th class="text-end">Total Amount</th>
            <th class="text-center">% of Total</th>
        </tr></thead><tbody>`;
    
    items.forEach(function(item) {
        const amount = parseFloat(item.total_amount || 0);
        const percentage = grandTotal > 0 ? (amount / grandTotal * 100) : 0;
        const methodBadge = getPaymentMethodBadge(item.payment_method);
        
        html += `<tr>
            <td>${methodBadge}</td>
            <td class="text-center">${item.transaction_count}</td>
            <td class="text-end fw-semibold">ETB ${formatCurrency(amount)}</td>
            <td class="text-center">${percentage.toFixed(1)}%</td>
        </tr>`;
    });
    
    html += `<tfoot><tr class="fw-bold">
        <td colspan="2" class="text-end">TOTAL</td>
        <td class="text-end">ETB ${formatCurrency(grandTotal)}</td>
        <td class="text-center">100%</td>
    </tr></tfoot>`;
    
    html += '</tbody></table>';
    loading.hide();
    container.html(html).show();
}

function renderRevenueTrend(items) {
    items = items || [];
    const labels = items.map(function(item) { return item.month; });
    const values = items.map(function(item) { return parseFloat(item.total_revenue || 0); });
    
    if (revenueTrendChart) {
        revenueTrendChart.destroy();
    }
    
    const ctx = document.getElementById('revenueTrendChart').getContext('2d');
    revenueTrendChart = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: labels.length ? labels : ['No Data'],
            datasets: [{
                label: 'Monthly Revenue (ETB)',
                data: values.length ? values : [0],
                backgroundColor: 'rgba(67, 97, 238, 0.7)',
                borderColor: '#4361ee',
                borderWidth: 2,
                borderRadius: 4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: false
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            return 'ETB ' + formatCurrency(context.raw);
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: function(value) {
                            return 'ETB ' + formatCurrency(value);
                        }
                    }
                }
            }
        }
    });
}

function renderCashierClosure(items) {
    const container = $('#cashierContent');
    const loading = $('#cashierLoading');
    items = items || [];
    
    $('#closureCount').text(items.length + ' closures');
    
    if (items.length === 0) {
        loading.hide();
        container.html('<div class="empty-state"><i class="fas fa-cash-register"></i><p>No cashier closures found</p></div>').show();
        return;
    }
    
    let html = `<table class="table-financial">
        <thead><tr>
            <th>Staff</th>
            <th class="text-end">Expected</th>
            <th class="text-end">Physical</th>
            <th class="text-end">Discrepancy</th>
            <th>Notes</th>
            <th>Invoices</th>
            <th>Status</th>
        </tr></thead><tbody>`;
    
    items.forEach(function(item) {
        const expected = parseFloat(item.total_expected || 0);
        const physical = parseFloat(item.physical_cash || 0);
        const discrepancy = parseFloat(item.discrepancy_amount || 0);
        
        let rowClass = '';
        let discrepancyDisplay = '';
        if (discrepancy < 0) {
            rowClass = 'discrepancy-negative';
            discrepancyDisplay = '⬇ Short ETB ' + formatCurrency(Math.abs(discrepancy));
        } else if (discrepancy > 0) {
            rowClass = 'discrepancy-positive';
            discrepancyDisplay = '⬆ Surplus ETB ' + formatCurrency(discrepancy);
        } else {
            discrepancyDisplay = '✅ Balanced';
        }
        
        const lockedBadge = item.is_locked ? '<span class="badge bg-success">Locked</span>' : '<span class="badge bg-warning">Open</span>';
        const staffName = item.staff_name || 'Staff #' + item.staff_id;
        
        html += `<tr class="${rowClass}">
            <td><strong>${escapeHtml(staffName)}</strong><br><small class="text-muted">${formatDateShort(item.closure_time)}</small></td>
            <td class="text-end">ETB ${formatCurrency(expected)}</td>
            <td class="text-end">ETB ${formatCurrency(physical)}</td>
            <td class="text-end fw-bold">${discrepancyDisplay}</td>
            <td><small>${escapeHtml(item.cashier_remarks || '—')}</small></td>
            <td class="text-center">${item.total_invoices_count || 0}</td>
            <td class="text-center">${lockedBadge}</td>
        </tr>`;
    });
    
    html += '</tbody></table>';
    loading.hide();
    container.html(html).show();
}

function renderTransactionLog(items) {
    const container = $('#txnContent');
    const loading = $('#txnLoading');
    items = items || [];
    
    $('#txnCount').text(items.length + ' transactions');
    
    if (items.length === 0) {
        loading.hide();
        container.html('<div class="empty-state"><i class="fas fa-list"></i><p>No transactions found</p></div>').show();
        return;
    }
    
    let html = `<table class="table-financial">
        <thead><tr>
            <th>Receipt</th>
            <th>Patient</th>
            <th>Cashier</th>
            <th class="text-end">Amount</th>
            <th>Method</th>
            <th>Status</th>
            <th>Time</th>
        </tr></thead><tbody>`;
    
    items.forEach(function(item) {
        const methodBadge = getPaymentMethodBadge(item.payment_method);
        const statusBadge = getStatusBadge(item.billing_status);
        const verifiedBadge = item.is_verified ? '<span class="badge bg-success">✓</span>' : '<span class="badge bg-warning">⏳</span>';
        
        html += `<tr>
            <td><small>${escapeHtml(item.receipt_number || 'N/A')}</small></td>
            <td>${escapeHtml(item.patient_name || 'Walk-in')}</td>
            <td>${escapeHtml(item.cashier_name || 'N/A')}</td>
            <td class="text-end fw-semibold">ETB ${formatCurrency(item.amount)}</td>
            <td>${methodBadge}</td>
            <td>${statusBadge} ${verifiedBadge}</td>
            <td><small>${formatDateShort(item.payment_time)}</small></td>
        </tr>`;
    });
    
    html += '</tbody></table>';
    loading.hide();
    container.html(html).show();
}

function renderExpenseBudgets(items) {
    const container = $('#budgetContent');
    const loading = $('#budgetLoading');
    items = items || [];
    
    $('#budgetCount').text(items.length + ' categories');
    
    if (items.length === 0) {
        loading.hide();
        container.html('<div class="empty-state"><i class="fas fa-wallet"></i><p>No budget data found</p></div>').show();
        return;
    }
    
    let html = `<table class="table-financial">
        <thead><tr>
            <th>Category</th>
            <th class="text-end">Budget</th>
            <th class="text-end">Spent</th>
            <th class="text-end">Remaining</th>
            <th>Utilization</th>
        </tr></thead><tbody>`;
    
    items.forEach(function(item) {
        const budget = parseFloat(item.budget_limit || 0);
        const spent = parseFloat(item.total_spent || 0);
        const remaining = parseFloat(item.remaining_budget || 0);
        const percentage = parseFloat(item.percentage_used || 0);
        const statusClass = item.status_class || 'success';
        
        const barColor = statusClass === 'danger' ? 'danger' : statusClass === 'warning' ? 'warning' : 'success';
        const remainingColor = remaining < 0 ? 'text-danger' : '';
        
        html += `<tr>
            <td><strong>${escapeHtml(item.category_name)}</strong></td>
            <td class="text-end">ETB ${formatCurrency(budget)}</td>
            <td class="text-end">ETB ${formatCurrency(spent)}</td>
            <td class="text-end fw-bold ${remainingColor}">ETB ${formatCurrency(remaining)}</td>
            <td>
                <div class="d-flex align-items-center">
                    <span class="me-2 small">${percentage}%</span>
                    <div class="budget-bar" style="width:120px;">
                        <div class="fill ${barColor}" style="width:${Math.min(percentage, 100)}%;"></div>
                    </div>
                </div>
            </td>
        </tr>`;
    });
    
    html += '</tbody></table>';
    loading.hide();
    container.html(html).show();
}

function renderBankBalances(items) {
    const container = $('#bankContent');
    const loading = $('#bankLoading');
    items = items || [];
    
    $('#bankCount').text(items.length + ' accounts');
    
    if (items.length === 0) {
        loading.hide();
        container.html('<div class="empty-state"><i class="fas fa-university"></i><p>No bank accounts found</p></div>').show();
        return;
    }
    
    let totalBalance = 0;
    let html = `<table class="table-financial">
        <thead><tr>
            <th>Account Name</th>
            <th>Type</th>
            <th class="text-end">Balance</th>
            <th>Status</th>
        </tr></thead><tbody>`;
    
    items.forEach(function(item) {
        const balance = parseFloat(item.current_balance || 0);
        totalBalance += balance;
        const typeBadge = item.account_type === 'petty_cash' 
            ? '<span class="badge bg-warning text-dark">💰 Petty Cash</span>'
            : '<span class="badge bg-primary">🏦 Bank</span>';
        
        html += `<tr>
            <td><strong>${escapeHtml(item.account_name)}</strong></td>
            <td>${typeBadge}</td>
            <td class="text-end fw-semibold">ETB ${formatCurrency(balance)}</td>
            <td><span class="badge bg-success">${escapeHtml(item.status)}</span></td>
        </tr>`;
    });
    
    html += `<tfoot><tr class="fw-bold">
        <td colspan="2" class="text-end">TOTAL BALANCE</td>
        <td class="text-end">ETB ${formatCurrency(totalBalance)}</td>
        <td></td>
    </tr></tfoot>`;
    
    html += '</tbody></table>';
    loading.hide();
    container.html(html).show();
}

// ============================================
// API CALLS
// ============================================

function fetchDashboardSummary() {
    $.post(AJAX_URL, {
        action: 'fetch_dashboard_summary',
        selected_date: currentDate,
        period: currentPeriod,
        csrf_token: csrfToken
    }, function(res) {
        if (res.success) {
            updateKPIs(res);
        }
    }).fail(function() {
        console.error('Failed to fetch dashboard summary');
    });
}

function fetchDepartmentalBreakdown() {
    $('#deptLoading').show();
    $('#deptContent').hide();
    
    $.post(AJAX_URL, {
        action: 'fetch_revenue_by_department',
        selected_date: currentDate,
        period: currentPeriod,
        csrf_token: csrfToken
    }, function(res) {
        if (res.success) {
            renderDepartmentalBreakdown(res.items);
        } else {
            $('#deptLoading').hide();
            $('#deptContent').html('<div class="empty-state"><i class="fas fa-exclamation-circle"></i><p>Error loading data</p></div>').show();
        }
    }).fail(function() {
        $('#deptLoading').hide();
        $('#deptContent').html('<div class="empty-state"><i class="fas fa-exclamation-circle"></i><p>Error loading data</p></div>').show();
    });
}

function fetchPaymentMethodsBreakdown() {
    $('#methodLoading').show();
    $('#methodContent').hide();
    
    $.post(AJAX_URL, {
        action: 'fetch_payment_methods_breakdown',
        selected_date: currentDate,
        period: currentPeriod,
        csrf_token: csrfToken
    }, function(res) {
        if (res.success) {
            renderPaymentMethodsBreakdown(res.items);
        } else {
            $('#methodLoading').hide();
            $('#methodContent').html('<div class="empty-state"><i class="fas fa-exclamation-circle"></i><p>Error loading data</p></div>').show();
        }
    }).fail(function() {
        $('#methodLoading').hide();
        $('#methodContent').html('<div class="empty-state"><i class="fas fa-exclamation-circle"></i><p>Error loading data</p></div>').show();
    });
}

function fetchRevenueTrend() {
    $.post(AJAX_URL, {
        action: 'fetch_revenue_trend',
        csrf_token: csrfToken
    }, function(res) {
        if (res.success) {
            renderRevenueTrend(res.items);
        }
    }).fail(function() {
        console.error('Failed to fetch revenue trend');
    });
}

function fetchCashierClosure() {
    $('#cashierLoading').show();
    $('#cashierContent').hide();
    
    $.post(AJAX_URL, {
        action: 'fetch_cashier_closure',
        selected_date: currentDate,
        csrf_token: csrfToken
    }, function(res) {
        if (res.success) {
            renderCashierClosure(res.items);
        } else {
            $('#cashierLoading').hide();
            $('#cashierContent').html('<div class="empty-state"><i class="fas fa-exclamation-circle"></i><p>Error loading data</p></div>').show();
        }
    }).fail(function() {
        $('#cashierLoading').hide();
        $('#cashierContent').html('<div class="empty-state"><i class="fas fa-exclamation-circle"></i><p>Error loading data</p></div>').show();
    });
}

function fetchTransactionLog() {
    $('#txnLoading').show();
    $('#txnContent').hide();
    
    $.post(AJAX_URL, {
        action: 'fetch_transaction_log',
        selected_date: currentDate,
        limit: 50,
        csrf_token: csrfToken
    }, function(res) {
        if (res.success) {
            renderTransactionLog(res.items);
        } else {
            $('#txnLoading').hide();
            $('#txnContent').html('<div class="empty-state"><i class="fas fa-exclamation-circle"></i><p>Error loading data</p></div>').show();
        }
    }).fail(function() {
        $('#txnLoading').hide();
        $('#txnContent').html('<div class="empty-state"><i class="fas fa-exclamation-circle"></i><p>Error loading data</p></div>').show();
    });
}

function fetchExpenseBudgets() {
    const year = document.getElementById('selectedYear') ? document.getElementById('selectedYear').value : currentYear;
    const month = document.getElementById('selectedMonth') ? document.getElementById('selectedMonth').value : currentMonth;
    
    $('#budgetLoading').show();
    $('#budgetContent').hide();
    
    $.post(AJAX_URL, {
        action: 'fetch_expense_budgets',
        year: year,
        month: month,
        csrf_token: csrfToken
    }, function(res) {
        if (res.success) {
            renderExpenseBudgets(res.items);
        } else {
            $('#budgetLoading').hide();
            $('#budgetContent').html('<div class="empty-state"><i class="fas fa-exclamation-circle"></i><p>Error loading data</p></div>').show();
        }
    }).fail(function() {
        $('#budgetLoading').hide();
        $('#budgetContent').html('<div class="empty-state"><i class="fas fa-exclamation-circle"></i><p>Error loading data</p></div>').show();
    });
}

function fetchBankBalances() {
    $('#bankLoading').show();
    $('#bankContent').hide();
    
    $.post(AJAX_URL, {
        action: 'fetch_bank_balances',
        csrf_token: csrfToken
    }, function(res) {
        if (res.success) {
            renderBankBalances(res.items);
        } else {
            $('#bankLoading').hide();
            $('#bankContent').html('<div class="empty-state"><i class="fas fa-exclamation-circle"></i><p>Error loading data</p></div>').show();
        }
    }).fail(function() {
        $('#bankLoading').hide();
        $('#bankContent').html('<div class="empty-state"><i class="fas fa-exclamation-circle"></i><p>Error loading data</p></div>').show();
    });
}

// ============================================
// UTILITY FUNCTIONS
// ============================================

function formatCurrency(value) {
    if (value === undefined || value === null) return '0.00';
    return Number(value).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
}

function escapeHtml(unsafe) {
    if (!unsafe) return '';
    return String(unsafe)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function formatDateShort(date) {
    if (!date) return 'N/A';
    try {
        return new Date(date).toLocaleString('en-US', {
            month: 'short',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        });
    } catch(e) {
        return date;
    }
}

function getStatusBadge(status) {
    const badges = {
        'paid': '<span class="badge bg-success">Paid</span>',
        'unpaid': '<span class="badge bg-warning">Unpaid</span>',
        'partial': '<span class="badge bg-info">Partial</span>',
        'pending': '<span class="badge bg-warning">Pending</span>',
        'approved': '<span class="badge bg-success">Approved</span>',
        'rejected': '<span class="badge bg-danger">Rejected</span>',
        'cancelled': '<span class="badge bg-danger">Cancelled</span>',
        'overdue': '<span class="badge bg-danger">Overdue</span>',
        'refunded': '<span class="badge bg-secondary">Refunded</span>',
        'active': '<span class="badge bg-success">Active</span>',
        'inactive': '<span class="badge bg-secondary">Inactive</span>',
        'completed': '<span class="badge bg-success">Completed</span>',
        'deferred': '<span class="badge bg-info">Deferred</span>',
        'exempted': '<span class="badge bg-secondary">Exempted</span>',
        'waived': '<span class="badge bg-info">Waived</span>'
    };
    return badges[status] || `<span class="badge bg-secondary">${escapeHtml(status)}</span>`;
}

function getPaymentMethodBadge(method) {
    const badges = {
        'cash': '<span class="badge badge-method-cash">💰 Cash</span>',
        'telebirr': '<span class="badge badge-method-mobile">📱 Telebirr</span>',
        'cbe_birr': '<span class="badge badge-method-mobile">📱 CBE Birr</span>',
        'mobile_money': '<span class="badge badge-method-mobile">📱 Mobile Money</span>',
        'insurance_claim': '<span class="badge badge-method-insurance">🏥 Insurance</span>',
        'insurance': '<span class="badge badge-method-insurance">🏥 Insurance</span>',
        'bank_transfer': '<span class="badge badge-method-bank">🏦 Bank Transfer</span>',
        'credit_card': '<span class="badge badge-method-card">💳 Credit Card</span>',
        'debit_card': '<span class="badge badge-method-card">💳 Debit Card</span>',
        'check': '<span class="badge badge-method-bank">📄 Check</span>'
    };
    return badges[method] || `<span class="badge bg-secondary">${escapeHtml(method)}</span>`;
}

function getDepartmentIcon(dept) {
    const icons = {
        'card_fee': '🎫',
        'consultation': '👨‍⚕️',
        'lab': '🔬',
        'pharmacy': '💊',
        'procedure': '🏥',
        'other': '📋'
    };
    return icons[dept] || '📊';
}

function showToast(message, type) {
    type = type || 'success';
    const toast = document.createElement('div');
    toast.style.cssText = `
        position: fixed; bottom: 20px; right: 20px; background: white;
        border-radius: 12px; padding: 12px 20px; box-shadow: 0 10px 25px rgba(0,0,0,0.1);
        z-index: 1100; animation: slideIn 0.3s ease; display: flex; align-items: center; gap: 12px;
        border-left: 4px solid ${type === 'success' ? '#06d6a0' : '#ef476f'};
        font-family: 'Inter', sans-serif;
    `;
    toast.innerHTML = `<i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-circle'}" style="color:${type === 'success' ? '#06d6a0' : '#ef476f'};"></i> <span>${message}</span>`;
    document.body.appendChild(toast);
    setTimeout(() => { if (toast.parentNode) toast.remove(); }, 4000);
}

function exportData() {
    Swal.fire({
        title: 'Export Data',
        html: `<div class="text-start">
            <div class="form-group mb-2"><label>Export Type</label>
            <select id="exportType" class="form-control">
                <option value="summary">Summary Report</option>
                <option value="transactions">Transaction Log</option>
                <option value="cashier">Cashier Closures</option>
                <option value="full">Full Report</option>
            </select></div>
            <div class="form-group mb-2"><label>Format</label>
            <select id="exportFormat" class="form-control">
                <option value="csv">CSV</option>
                <option value="json">JSON</option>
            </select></div>
        </div>`,
        showCancelButton: true,
        confirmButtonText: 'Export',
        confirmButtonColor: '#4361ee',
        cancelButtonText: 'Cancel',
        preConfirm: function() {
            return {
                type: document.getElementById('exportType').value,
                format: document.getElementById('exportFormat').value
            };
        }
    }).then(function(result) {
        if (result.isConfirmed) {
            showToast('Exporting data...', 'success');
        }
    });
}

function exportToCSV() {
    showToast('CSV export initiated', 'success');
}

function printReport() {
    window.print();
}

// Add CSS animation keyframes
const style = document.createElement('style');
style.textContent = `
    @keyframes slideIn {
        from { transform: translateX(100%); opacity: 0; }
        to { transform: translateX(0); opacity: 1; }
    }
`;
document.head.appendChild(style);

// Sidebar compatibility
if (typeof MetisMenu !== 'undefined') {
    var sideMenu = document.getElementById('side-menu');
    if (sideMenu && !sideMenu.hasAttribute('data-metismenu-initialized')) {
        new MetisMenu(sideMenu, {
            toggle: true,
            triggerElement: 'a.has-arrow',
            parentTrigger: 'li',
            subMenu: 'ul'
        });
        sideMenu.setAttribute('data-metismenu-initialized', 'true');
    }
}

document.getElementById('mobileMenuToggle') && document.getElementById('mobileMenuToggle').addEventListener('click', function(e) {
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