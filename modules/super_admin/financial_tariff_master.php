<?php
/**
 * Super Admin - Enterprise Financial Master & Tariff Pricing
 * HMS ERP System - FIXED VERSION 3.0
 * 
 * @package HMS-ERP
 * @version 3.0
 */

if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../../config/db_connection.php';
require_once __DIR__ . '/../../includes/auth_helper.php';

// ============================================
// AUTHENTICATION & RBAC - SUPER ADMIN ONLY
// ============================================
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../index.php');
    exit;
}
if (($_SESSION['user_type'] ?? '') !== 'super_admin' && ($_SESSION['user_type'] ?? '') !== 'admin') {
    die('Access Denied. Super Admin or Admin privileges required.');
}

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

$branch_id = (int)($_SESSION['branch_id'] ?? 0);
$user_id   = (int)($_SESSION['user_id'] ?? 0);
$user_name = $_SESSION['first_name'] ?? $_SESSION['username'] ?? 'Admin';
$user_type = $_SESSION['user_type'] ?? '';

$db_available = is_db_available();
if (!$db_available) die('Database connection failed.');

$pdo = getDB();

// ============================================
// HELPER FUNCTIONS
// ============================================
function escapeHtml($str) {
    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}

function formatCurrency($amount) {
    return number_format((float)$amount, 2, '.', ',');
}

function jsonResponse($success, $message, $data = null) {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    $response = ['success' => $success, 'message' => $message];
    if ($data !== null) {
        $response = array_merge($response, $data);
    }
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

// ============================================
// AJAX ROUTER
// ============================================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'financial_action') {
    error_reporting(0);
    ini_set('display_errors', 0);
    
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(false, 'Invalid request method.');
    }

    $submitted = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $submitted)) {
        jsonResponse(false, 'Security validation failed. Refresh the page.');
    }

    $action = (string)($_POST['action'] ?? '');
    $db = getDB();
    if (!$db) {
        jsonResponse(false, 'Database connection failed');
    }

    // ---------- GET SERVICE TARIFFS ----------
    if ($action === 'get_tariffs') {
        $branch_filter = (int)($_POST['branch_id'] ?? 0);
        $sql = "SELECT st.id, st.branch_id, st.service_code AS item_code, st.service_name AS item_name,
                       st.service_category AS category, st.standard_price AS base_price, 
                       st.insurance_price, st.cost_price, st.is_taxable, st.tax_rate,
                       st.insurance_coverage_percentage, st.is_active, st.created_at, st.updated_at,
                       b.name AS branch_name
                FROM service_tariffs st
                LEFT JOIN branches b ON st.branch_id = b.id AND b.deleted_at IS NULL
                WHERE st.deleted_at IS NULL";
        if ($branch_filter > 0) {
            $sql .= " AND st.branch_id = :branch_id";
            $stmt = $db->prepare($sql);
            $stmt->execute([':branch_id' => $branch_filter]);
        } else {
            $stmt = $db->query($sql);
        }
        $tariffs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        jsonResponse(true, '', ['tariffs' => $tariffs]);
    }

    // ---------- SAVE TARIFF ----------
    if ($action === 'save_tariff') {
        $tariff_id = (int)($_POST['tariff_id'] ?? 0);
        $branch_id_tariff = (int)($_POST['branch_id'] ?? 0);
        $item_code = trim($_POST['item_code'] ?? '');
        $item_name = trim($_POST['item_name'] ?? '');
        $category = $_POST['category'] ?? 'consultation';
        $base_price = (float)($_POST['base_price'] ?? 0);
        $insurance_price = (float)($_POST['insurance_price'] ?? 0);
        $cost_price = (float)($_POST['cost_price'] ?? 0);
        $is_taxable = isset($_POST['is_taxable']) ? 1 : 0;
        $tax_rate = (float)($_POST['tax_rate'] ?? 15);
        $insurance_coverage = (float)($_POST['insurance_coverage'] ?? 80);
        $is_active = isset($_POST['is_active']) ? 1 : 0;

        if (!$branch_id_tariff || !$item_code || !$item_name || $base_price < 0) {
            jsonResponse(false, 'Missing required fields or invalid price.');
        }

        // Check unique item_code per branch
        $checkStmt = $db->prepare("SELECT id FROM service_tariffs WHERE branch_id = :bid AND service_code = :code AND deleted_at IS NULL AND id != :tid");
        $checkStmt->execute([':bid' => $branch_id_tariff, ':code' => $item_code, ':tid' => $tariff_id]);
        if ($checkStmt->fetch()) {
            jsonResponse(false, 'Item code already exists for this branch.');
        }

        try {
            if ($tariff_id > 0) {
                $stmt = $db->prepare("UPDATE service_tariffs SET 
                    branch_id = :bid, service_code = :code, service_name = :name, 
                    service_category = :cat, standard_price = :price, 
                    insurance_price = :ins_price, cost_price = :cost,
                    is_taxable = :taxable, tax_rate = :tax_rate,
                    insurance_coverage_percentage = :ins_cov,
                    is_active = :act, updated_at = NOW() 
                    WHERE id = :id");
                $stmt->execute([
                    ':bid' => $branch_id_tariff, ':code' => $item_code, ':name' => $item_name,
                    ':cat' => $category, ':price' => $base_price,
                    ':ins_price' => $insurance_price ?: null,
                    ':cost' => $cost_price ?: null,
                    ':taxable' => $is_taxable, ':tax_rate' => $tax_rate,
                    ':ins_cov' => $insurance_coverage,
                    ':act' => $is_active, ':id' => $tariff_id
                ]);
                jsonResponse(true, 'Tariff updated successfully.');
            } else {
                $stmt = $db->prepare("INSERT INTO service_tariffs 
                    (branch_id, service_code, service_name, service_category, 
                     standard_price, insurance_price, cost_price,
                     is_taxable, tax_rate, insurance_coverage_percentage,
                     is_active, effective_from, created_by_user_id, created_at, updated_at) 
                    VALUES 
                    (:bid, :code, :name, :cat, :price, :ins_price, :cost,
                     :taxable, :tax_rate, :ins_cov, :act, CURDATE(), :uid, NOW(), NOW())");
                $stmt->execute([
                    ':bid' => $branch_id_tariff, ':code' => $item_code, ':name' => $item_name,
                    ':cat' => $category, ':price' => $base_price,
                    ':ins_price' => $insurance_price ?: null,
                    ':cost' => $cost_price ?: null,
                    ':taxable' => $is_taxable, ':tax_rate' => $tax_rate,
                    ':ins_cov' => $insurance_coverage,
                    ':act' => $is_active, ':uid' => $user_id
                ]);
                jsonResponse(true, 'Tariff created successfully.');
            }
        } catch (PDOException $e) {
            jsonResponse(false, 'Database error: ' . $e->getMessage());
        }
    }

    // ---------- TOGGLE TARIFF ----------
    if ($action === 'toggle_tariff') {
        $tariff_id = (int)($_POST['tariff_id'] ?? 0);
        $current = (int)($_POST['current_status'] ?? 1);
        $new = $current ? 0 : 1;
        $stmt = $db->prepare("UPDATE service_tariffs SET is_active = :act, updated_at = NOW() WHERE id = :id");
        $stmt->execute([':act' => $new, ':id' => $tariff_id]);
        jsonResponse(true, 'Tariff status updated.');
    }

    // ---------- DELETE TARIFF ----------
    if ($action === 'delete_tariff') {
        $tariff_id = (int)($_POST['tariff_id'] ?? 0);
        $stmt = $db->prepare("UPDATE service_tariffs SET deleted_at = NOW() WHERE id = :id");
        $stmt->execute([':id' => $tariff_id]);
        jsonResponse(true, 'Tariff deleted successfully.');
    }

    // ---------- GET INSURANCE PROVIDERS ----------
    if ($action === 'get_providers') {
        $branch_filter = (int)($_POST['branch_id'] ?? 0);
        $sql = "SELECT id, branch_id, name, code, default_coverage_percentage, 
                       default_co_pay_percentage, contract_start_date, contract_end_date, 
                       is_active, contact_person, phone, email, credit_limit, current_balance,
                       payment_terms_days, claim_submission_deadline_days, requires_pre_authorization,
                       created_at, updated_at
                FROM insurance_providers 
                WHERE deleted_at IS NULL";
        if ($branch_filter > 0) {
            $sql .= " AND branch_id = :branch_id";
            $stmt = $db->prepare($sql);
            $stmt->execute([':branch_id' => $branch_filter]);
        } else {
            $stmt = $db->query($sql);
        }
        $providers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        jsonResponse(true, '', ['providers' => $providers]);
    }

    // ---------- SAVE INSURANCE PROVIDER ----------
    if ($action === 'save_provider') {
        $provider_id = (int)($_POST['provider_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $code = trim($_POST['code'] ?? '');
        $coverage = (float)($_POST['coverage_percentage'] ?? 0);
        $copay = (float)($_POST['copay_percentage'] ?? 0);
        $contact_person = trim($_POST['contact_person'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $start_date = $_POST['contract_start_date'] ?? null;
        $end_date = $_POST['contract_end_date'] ?? null;
        $branch_provider = (int)($_POST['branch_id'] ?? 0);
        $credit_limit = (float)($_POST['credit_limit'] ?? 0);
        $payment_terms = (int)($_POST['payment_terms_days'] ?? 30);
        $claim_deadline = (int)($_POST['claim_deadline_days'] ?? 30);
        $pre_auth = isset($_POST['requires_pre_authorization']) ? 1 : 0;

        if (!$name || !$code) {
            jsonResponse(false, 'Provider name and code are required.');
        }
        if (abs(($coverage + $copay) - 100) > 0.01) {
            jsonResponse(false, 'Coverage percentage and patient copay must sum to 100%.');
        }

        try {
            if ($provider_id > 0) {
                $stmt = $db->prepare("UPDATE insurance_providers SET 
                    name = :name, code = :code, 
                    default_coverage_percentage = :cov, default_co_pay_percentage = :cop, 
                    contact_person = :cp, phone = :ph, email = :em, 
                    contract_start_date = :sd, contract_end_date = :ed,
                    credit_limit = :cl, payment_terms_days = :pt,
                    claim_submission_deadline_days = :cd,
                    requires_pre_authorization = :pa,
                    updated_at = NOW() 
                    WHERE id = :id");
                $stmt->execute([
                    ':name' => $name, ':code' => $code,
                    ':cov' => $coverage, ':cop' => $copay,
                    ':cp' => $contact_person ?: null, ':ph' => $phone ?: null,
                    ':em' => $email ?: null, ':sd' => $start_date, ':ed' => $end_date,
                    ':cl' => $credit_limit, ':pt' => $payment_terms,
                    ':cd' => $claim_deadline, ':pa' => $pre_auth,
                    ':id' => $provider_id
                ]);
                jsonResponse(true, 'Provider updated successfully.');
            } else {
                $stmt = $db->prepare("INSERT INTO insurance_providers 
                    (branch_id, name, code, default_coverage_percentage, default_co_pay_percentage, 
                     contact_person, phone, email, contract_start_date, contract_end_date,
                     credit_limit, payment_terms_days, claim_submission_deadline_days,
                     requires_pre_authorization, is_active, created_by_user_id, created_at, updated_at) 
                    VALUES 
                    (:bid, :name, :code, :cov, :cop, :cp, :ph, :em, :sd, :ed,
                     :cl, :pt, :cd, :pa, 1, :uid, NOW(), NOW())");
                $stmt->execute([
                    ':bid' => $branch_provider, ':name' => $name, ':code' => $code,
                    ':cov' => $coverage, ':cop' => $copay,
                    ':cp' => $contact_person ?: null, ':ph' => $phone ?: null,
                    ':em' => $email ?: null, ':sd' => $start_date, ':ed' => $end_date,
                    ':cl' => $credit_limit, ':pt' => $payment_terms,
                    ':cd' => $claim_deadline, ':pa' => $pre_auth,
                    ':uid' => $user_id
                ]);
                jsonResponse(true, 'Provider created successfully.');
            }
        } catch (PDOException $e) {
            jsonResponse(false, 'Database error: ' . $e->getMessage());
        }
    }

    // ---------- TOGGLE PROVIDER ----------
    if ($action === 'toggle_provider') {
        $provider_id = (int)($_POST['provider_id'] ?? 0);
        $current = (int)($_POST['current_status'] ?? 1);
        $new = $current ? 0 : 1;
        $stmt = $db->prepare("UPDATE insurance_providers SET is_active = :act, updated_at = NOW() WHERE id = :id");
        $stmt->execute([':act' => $new, ':id' => $provider_id]);
        jsonResponse(true, 'Provider status updated.');
    }

    // ---------- DELETE PROVIDER ----------
    if ($action === 'delete_provider') {
        $provider_id = (int)($_POST['provider_id'] ?? 0);
        $stmt = $db->prepare("UPDATE insurance_providers SET deleted_at = NOW() WHERE id = :id");
        $stmt->execute([':id' => $provider_id]);
        jsonResponse(true, 'Provider deleted successfully.');
    }

    // ---------- GET PENDING EXPENSES ----------
    if ($action === 'get_pending_expenses') {
        $branch_filter = (int)($_POST['branch_id'] ?? 0);
        $sql = "SELECT e.id, e.branch_id, 
                       COALESCE(ec.category_name, 'Uncategorized') AS category, 
                       e.amount, e.description, e.status, e.expense_date, e.created_at,
                       u.first_name, u.last_name, u.username,
                       e.requested_by_user_id, e.approved_by_user_id,
                       b.name AS branch_name
                FROM expenses e
                LEFT JOIN expense_categories ec ON e.category_id = ec.id
                LEFT JOIN users u ON e.requested_by_user_id = u.id
                LEFT JOIN branches b ON e.branch_id = b.id
                WHERE (e.status = 'pending' OR e.status IS NULL) AND e.deleted_at IS NULL";
        if ($branch_filter > 0) {
            $sql .= " AND e.branch_id = :branch_id";
            $stmt = $db->prepare($sql);
            $stmt->execute([':branch_id' => $branch_filter]);
        } else {
            $stmt = $db->query($sql);
        }
        $expenses = $stmt->fetchAll(PDO::FETCH_ASSOC);
        jsonResponse(true, '', ['expenses' => $expenses]);
    }

    // ---------- APPROVE / REJECT EXPENSE ----------
    if ($action === 'review_expense') {
        $expense_id = (int)($_POST['expense_id'] ?? 0);
        $decision = $_POST['decision'] ?? '';
        if (!in_array($decision, ['approved', 'rejected'])) {
            jsonResponse(false, 'Invalid decision.');
        }
        $db->beginTransaction();
        try {
            $stmt = $db->prepare("UPDATE expenses SET 
                status = :stat, approved_by_user_id = :uid, 
                approved_at = NOW(), updated_at = NOW() 
                WHERE id = :id");
            $stmt->execute([':stat' => $decision, ':uid' => $user_id, ':id' => $expense_id]);
            $db->commit();
            jsonResponse(true, 'Expense ' . $decision . ' successfully.');
        } catch (Exception $e) {
            $db->rollBack();
            jsonResponse(false, 'Update failed: ' . $e->getMessage());
        }
    }

    // ---------- GET CASH FLOW CHART DATA ----------
    if ($action === 'get_cashflow_chart_data') {
        $branch_filter = (int)($_POST['branch_id'] ?? 0);
        $year = (int)($_POST['year'] ?? date('Y'));
        $month = (int)($_POST['month'] ?? date('m'));
        $days_in_month = cal_days_in_month(CAL_GREGORIAN, $month, $year);
        $revenue_by_day = array_fill(1, $days_in_month, 0);
        $expense_by_day = array_fill(1, $days_in_month, 0);

        // Revenue from billing_items (paid)
        $revSql = "SELECT DAY(created_at) as day, COALESCE(SUM(amount), 0) as total
                   FROM billing_items
                   WHERE status = 'paid'
                     AND MONTH(created_at) = :month
                     AND YEAR(created_at) = :year
                     AND deleted_at IS NULL";
        $revParams = [':month' => $month, ':year' => $year];
        if ($branch_filter > 0) {
            $revSql .= " AND branch_id = :branch_id";
            $revParams[':branch_id'] = $branch_filter;
        }
        $revStmt = $db->prepare($revSql);
        $revStmt->execute($revParams);
        while ($row = $revStmt->fetch(PDO::FETCH_ASSOC)) {
            $revenue_by_day[(int)$row['day']] = (float)$row['total'];
        }

        // Expenditure from expenses (approved)
        $expSql = "SELECT DAY(expense_date) as day, COALESCE(SUM(amount), 0) as total
                   FROM expenses
                   WHERE status = 'approved'
                     AND MONTH(expense_date) = :month
                     AND YEAR(expense_date) = :year
                     AND deleted_at IS NULL";
        $expParams = [':month' => $month, ':year' => $year];
        if ($branch_filter > 0) {
            $expSql .= " AND branch_id = :branch_id";
            $expParams[':branch_id'] = $branch_filter;
        }
        $expStmt = $db->prepare($expSql);
        $expStmt->execute($expParams);
        while ($row = $expStmt->fetch(PDO::FETCH_ASSOC)) {
            $expense_by_day[(int)$row['day']] = (float)$row['total'];
        }

        $days_labels = [];
        for ($d = 1; $d <= $days_in_month; $d++) {
            $days_labels[] = (string)$d;
        }

        jsonResponse(true, '', [
            'labels' => $days_labels,
            'revenue' => array_values($revenue_by_day),
            'expenses' => array_values($expense_by_day),
            'month' => date('F', mktime(0,0,0,$month,1)),
            'year' => $year
        ]);
    }

    // ---------- GET DASHBOARD METRICS (KPI) ----------
    if ($action === 'get_financial_kpis') {
        $branch_filter = (int)($_POST['branch_id'] ?? 0);
        $month = (int)($_POST['month'] ?? date('m'));
        $year = (int)($_POST['year'] ?? date('Y'));

        // Total revenue month-to-date from billing_items
        $revTotalSql = "SELECT COALESCE(SUM(amount), 0) as total 
                        FROM billing_items 
                        WHERE status = 'paid' 
                          AND MONTH(created_at) = :month 
                          AND YEAR(created_at) = :year 
                          AND deleted_at IS NULL";
        $revParams = [':month' => $month, ':year' => $year];
        if ($branch_filter > 0) {
            $revTotalSql .= " AND branch_id = :bid";
            $revParams[':bid'] = $branch_filter;
        }
        $revStmt = $db->prepare($revTotalSql);
        $revStmt->execute($revParams);
        $total_revenue = (float)$revStmt->fetchColumn();

        // Total expenditure month-to-date from expenses
        $expTotalSql = "SELECT COALESCE(SUM(amount), 0) as total 
                        FROM expenses 
                        WHERE status = 'approved' 
                          AND MONTH(expense_date) = :month 
                          AND YEAR(expense_date) = :year 
                          AND deleted_at IS NULL";
        $expParams = [':month' => $month, ':year' => $year];
        if ($branch_filter > 0) {
            $expTotalSql .= " AND branch_id = :bid";
            $expParams[':bid'] = $branch_filter;
        }
        $expStmt = $db->prepare($expTotalSql);
        $expStmt->execute($expParams);
        $total_expenses = (float)$expStmt->fetchColumn();

        // Net profit
        $net_profit = $total_revenue - $total_expenses;

        // Pending expenses count
        $pendingSql = "SELECT COUNT(*) FROM expenses WHERE (status = 'pending' OR status IS NULL) AND deleted_at IS NULL";
        if ($branch_filter > 0) {
            $pendingSql .= " AND branch_id = :bid";
            $pendStmt = $db->prepare($pendingSql);
            $pendStmt->execute([':bid' => $branch_filter]);
        } else {
            $pendStmt = $db->prepare($pendingSql);
            $pendStmt->execute();
        }
        $pending_count = (int)$pendStmt->fetchColumn();

        jsonResponse(true, '', [
            'total_revenue' => number_format($total_revenue, 2),
            'total_expenses' => number_format($total_expenses, 2),
            'net_profit' => number_format($net_profit, 2),
            'pending_expenses' => $pending_count
        ]);
    }

    // ---------- GET BRANCH SUMMARY ----------
    if ($action === 'get_branch_summary') {
        $sql = "SELECT 
                    b.id, b.name,
                    (SELECT COALESCE(SUM(amount), 0) FROM billing_items 
                     WHERE branch_id = b.id AND status = 'paid' 
                       AND MONTH(created_at) = MONTH(CURDATE()) 
                       AND YEAR(created_at) = YEAR(CURDATE())
                       AND deleted_at IS NULL) AS monthly_revenue,
                    (SELECT COALESCE(SUM(amount), 0) FROM expenses 
                     WHERE branch_id = b.id AND status = 'approved' 
                       AND MONTH(expense_date) = MONTH(CURDATE()) 
                       AND YEAR(expense_date) = YEAR(CURDATE())
                       AND deleted_at IS NULL) AS monthly_expenses,
                    (SELECT COUNT(*) FROM patients 
                     WHERE branch_id = b.id AND deleted_at IS NULL) AS total_patients
                FROM branches b
                WHERE b.deleted_at IS NULL AND b.status = 'active'
                ORDER BY b.name";
        $stmt = $db->query($sql);
        $branches_summary = $stmt->fetchAll(PDO::FETCH_ASSOC);
        jsonResponse(true, '', ['branches' => $branches_summary]);
    }

    // ---------- GET EXPENSE CATEGORIES ----------
    if ($action === 'get_expense_categories') {
        $sql = "SELECT id, category_name, status FROM expense_categories WHERE status = 'active' ORDER BY category_name";
        $stmt = $db->query($sql);
        $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
        jsonResponse(true, '', ['categories' => $categories]);
    }

    jsonResponse(false, 'Unknown action');
    exit;
}

// ============================================
// BRANCH LIST FOR SUPER ADMIN FILTER
// ============================================
$branches = [];
if ($pdo) {
    $branchStmt = $pdo->query("SELECT id, name FROM branches WHERE deleted_at IS NULL AND status = 'active' ORDER BY name");
    $branches = $branchStmt->fetchAll(PDO::FETCH_ASSOC);
}

// Expense categories for dropdown
$expense_categories = [];
if ($pdo) {
    $catStmt = $pdo->query("SELECT id, category_name FROM expense_categories WHERE status = 'active' ORDER BY category_name");
    $expense_categories = $catStmt->fetchAll(PDO::FETCH_ASSOC);
}

$current_date_display = date('l, F j, Y');
$current_time_display = date('g:i A');
$current_month = date('F');
$current_year = date('Y');

// Get branch name for display
$branch_name = 'All Branches';
if ($branch_id > 0) {
    $stmt = $pdo->prepare("SELECT name FROM branches WHERE id = :id AND deleted_at IS NULL");
    $stmt->execute([':id' => $branch_id]);
    $branch = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($branch) $branch_name = $branch['name'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Financial Master & Tariff Pricing | Super Admin | HMS ERP</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
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
            --gray: #6c757d;
            --gray-light: #e9ecef;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: linear-gradient(135deg, #f5f7fb 0%, #eef2f6 100%); overflow-x: hidden; }
        .content-page { margin-left: 260px; margin-top: 70px; padding: 24px 32px; transition: margin-left 0.3s; min-height: calc(100vh - 70px); }
        body.erp-sidebar-collapsed .content-page { margin-left: 80px; }
        @media (max-width: 768px) { .content-page { margin-left: 0 !important; padding: 16px; } }
        
        .welcome-header { background: linear-gradient(135deg, var(--primary-dark) 0%, var(--primary) 100%); border-radius: 24px; padding: 24px 32px; color: white; margin-bottom: 28px; }
        .welcome-title { font-size: 24px; font-weight: 700; margin-bottom: 4px; }
        .welcome-subtitle { font-size: 14px; opacity: 0.85; }
        .time-badge { background: rgba(255,255,255,0.15); padding: 8px 16px; border-radius: 12px; font-size: 14px; display: inline-block; }
        
        .filter-bar { background: white; border-radius: 20px; padding: 16px 24px; margin-bottom: 24px; display: flex; gap: 16px; flex-wrap: wrap; align-items: flex-end; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .stat-card { background: white; border-radius: 20px; padding: 20px; margin-bottom: 20px; border: 1px solid var(--gray-light); box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        
        .kpi-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 28px; }
        .kpi-box { background: white; border-radius: 20px; padding: 20px; border-left: 5px solid var(--primary); box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .kpi-box.success { border-left-color: var(--success); }
        .kpi-box.danger { border-left-color: var(--danger); }
        .kpi-box.warning { border-left-color: var(--warning); }
        .kpi-box.info { border-left-color: var(--info); }
        .kpi-value { font-size: 28px; font-weight: 800; color: var(--dark); }
        .kpi-label { font-size: 13px; color: var(--gray); }
        
        .quick-btn { padding: 10px 20px; border-radius: 14px; font-size: 14px; font-weight: 600; border: none; cursor: pointer; transition: all 0.2s; display: inline-flex; align-items: center; gap: 8px; }
        .quick-btn-primary { background: linear-gradient(135deg, var(--primary), var(--primary-dark)); color: white; }
        .quick-btn-primary:hover { transform: translateY(-2px); box-shadow: 0 4px 15px rgba(67,97,238,0.3); color: white; }
        .quick-btn-outline { background: white; border: 1px solid var(--gray-light); color: var(--dark); }
        .quick-btn-outline:hover { border-color: var(--primary); color: var(--primary); }
        .quick-btn-success { background: var(--success); color: white; }
        .quick-btn-success:hover { transform: translateY(-2px); box-shadow: 0 4px 15px rgba(6,214,160,0.3); color: white; }
        .quick-btn-danger { background: var(--danger); color: white; }
        .quick-btn-danger:hover { transform: translateY(-2px); box-shadow: 0 4px 15px rgba(239,71,111,0.3); color: white; }
        
        .loading-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(255,255,255,0.7); z-index: 2000; display: none; align-items: center; justify-content: center; }
        .loading-overlay.active { display: flex; }
        .spinner { width: 40px; height: 40px; border: 3px solid var(--gray-light); border-top-color: var(--primary); border-radius: 50%; animation: spin 0.8s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }
        
        .chart-container { background: white; border-radius: 20px; padding: 20px; margin-top: 20px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .chart-container canvas { max-height: 350px; }
        
        .nav-tabs { border-bottom: 2px solid var(--gray-light); }
        .nav-tabs .nav-link { border: none; padding: 12px 24px; font-weight: 600; color: var(--gray); border-radius: 10px 10px 0 0; transition: all 0.3s; }
        .nav-tabs .nav-link:hover { background: var(--gray-light); color: var(--dark); }
        .nav-tabs .nav-link.active { color: var(--primary); background: white; border-bottom: 3px solid var(--primary); }
        .nav-tabs .nav-link i { margin-right: 8px; }
        
        .table-financial { width: 100%; font-size: 14px; }
        .table-financial thead th { background: #f8fafc; font-weight: 600; font-size: 12px; text-transform: uppercase; color: var(--gray); padding: 10px 12px; border-bottom: 2px solid #e5e7eb; }
        .table-financial tbody td { padding: 10px 12px; border-bottom: 1px solid var(--gray-light); vertical-align: middle; }
        .table-financial tbody tr:hover { background: #f8fafc; }
        
        .badge-active { background: #d1fae5; color: #059669; padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 600; }
        .badge-inactive { background: #fee2e2; color: #dc2626; padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 600; }
        .badge-pending { background: #fef3c7; color: #d97706; padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 600; }
        .badge-approved { background: #d1fae5; color: #059669; padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 600; }
        .badge-rejected { background: #fee2e2; color: #dc2626; padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 600; }
        
        .footer-actions { margin-top: 24px; display: flex; justify-content: flex-end; gap: 12px; flex-wrap: wrap; }
        
        .tab-pane { animation: fadeIn 0.3s ease; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        
        .empty-state { text-align: center; padding: 40px 20px; color: var(--gray); }
        .empty-state i { font-size: 48px; opacity: 0.2; margin-bottom: 12px; }
        
        @media (max-width: 768px) {
            .kpi-grid { grid-template-columns: 1fr 1fr; }
            .filter-bar { flex-direction: column; align-items: stretch; }
            .filter-bar > div { width: 100%; }
            .nav-tabs .nav-link { padding: 10px 16px; font-size: 13px; }
        }
        @media (max-width: 480px) {
            .kpi-grid { grid-template-columns: 1fr; }
            .welcome-header { padding: 16px 20px; }
            .welcome-title { font-size: 20px; }
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
                    <div class="col-md-8">
                        <div class="welcome-title"><i class="fas fa-coins me-2"></i>Enterprise Financial Master & Tariff Pricing</div>
                        <div class="welcome-subtitle">Super Admin Console: Manage service tariffs, insurance contracts, expense approvals, and cash flow analytics.</div>
                    </div>
                    <div class="col-md-4 text-md-end mt-3 mt-md-0">
                        <div class="time-badge">
                            <i class="far fa-calendar-alt"></i> <?php echo escapeHtml($current_date_display); ?>
                            <i class="far fa-clock ms-2"></i> <?php echo escapeHtml($current_time_display); ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Filter Bar -->
            <div class="filter-bar">
                <div style="min-width: 200px; flex:1;">
                    <label class="form-label fw-semibold mb-1"><i class="fas fa-building"></i> Select Branch</label>
                    <select id="globalBranchFilter" class="form-select">
                        <option value="0">All Branches</option>
                        <?php foreach ($branches as $branch): ?>
                        <option value="<?php echo $branch['id']; ?>"><?php echo escapeHtml($branch['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="form-label fw-semibold mb-1"><i class="far fa-calendar"></i> Month</label>
                    <select id="monthFilter" class="form-select">
                        <?php for ($m = 1; $m <= 12; $m++): ?>
                        <option value="<?php echo $m; ?>" <?php echo $m == date('m') ? 'selected' : ''; ?>>
                            <?php echo date('F', mktime(0,0,0,$m,1)); ?>
                        </option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div>
                    <label class="form-label fw-semibold mb-1"><i class="far fa-calendar"></i> Year</label>
                    <select id="yearFilter" class="form-select">
                        <?php for ($y = date('Y') - 3; $y <= date('Y'); $y++): ?>
                        <option value="<?php echo $y; ?>" <?php echo $y == date('Y') ? 'selected' : ''; ?>><?php echo $y; ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div>
                    <button class="quick-btn quick-btn-primary" onclick="refreshAllFinancialData()">
                        <i class="fas fa-sync-alt"></i> Refresh
                    </button>
                    <a href="all_financial_reports.php" class="quick-btn quick-btn-outline ms-2">
                        <i class="fas fa-file-alt"></i> Full Reports
                    </a>
                </div>
            </div>

            <!-- KPI Cards -->
            <div class="kpi-grid" id="kpiContainer">
                <div class="kpi-box success">
                    <div class="kpi-value" id="totalRevenue">0.00 ETB</div>
                    <div class="kpi-label"><i class="fas fa-arrow-up text-success"></i> Total Revenue (MTD)</div>
                </div>
                <div class="kpi-box danger">
                    <div class="kpi-value" id="totalExpenses">0.00 ETB</div>
                    <div class="kpi-label"><i class="fas fa-arrow-down text-danger"></i> Total Expenditure (MTD)</div>
                </div>
                <div class="kpi-box info">
                    <div class="kpi-value" id="netProfit">0.00 ETB</div>
                    <div class="kpi-label"><i class="fas fa-chart-line text-info"></i> Net Profit (MTD)</div>
                </div>
            </div>

            <!-- Tabs -->
            <ul class="nav nav-tabs mb-4" id="financialTabs" role="tablist">
                <li class="nav-item">
                    <button class="nav-link active" id="tariffs-tab" data-bs-toggle="tab" data-bs-target="#tariffs">
                        <i class="fas fa-tags"></i> Service Tariff Master
                    </button>
                </li>
                <li class="nav-item">
                    <button class="nav-link" id="insurance-tab" data-bs-toggle="tab" data-bs-target="#insurance">
                        <i class="fas fa-file-contract"></i> Insurance Contracts
                    </button>
                </li>
            </ul>
            
            <div class="tab-content">
                <!-- ===================== SERVICE TARIFF MASTER ===================== -->
                <div class="tab-pane fade show active" id="tariffs">
                    <div class="stat-card">
                        <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                            <h5 class="mb-0"><i class="fas fa-tags me-2"></i>Service Pricing Catalog</h5>
                            <div>
                                <button class="quick-btn quick-btn-primary" id="addTariffBtn">
                                    <i class="fas fa-plus"></i> Add Tariff
                                </button>
                            </div>
                        </div>
                        <div class="table-responsive">
                            <table id="tariffsTable" class="table table-hover table-financial" style="width:100%">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Branch</th>
                                        <th>Item Code</th>
                                        <th>Service Name</th>
                                        <th>Category</th>
                                        <th>Base Price</th>
                                        <th>Insurance Price</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr><td colspan="9" class="text-center text-muted">Loading tariffs...</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- ===================== INSURANCE CONTRACTS ===================== -->
                <div class="tab-pane fade" id="insurance">
                    <div class="stat-card">
                        <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                            <h5 class="mb-0"><i class="fas fa-file-contract me-2"></i>Insurance Providers & Coverage Rules</h5>
                            <button class="quick-btn quick-btn-primary" id="addProviderBtn">
                                <i class="fas fa-plus"></i> Add Provider
                            </button>
                        </div>
                        <div class="table-responsive">
                            <table id="providersTable" class="table table-hover table-financial" style="width:100%">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Name</th>
                                        <th>Code</th>
                                        <th>Coverage</th>
                                        <th>Copay</th>
                                        <th>Contract</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr><td colspan="8" class="text-center text-muted">Loading providers...</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

            <div class="footer-actions">
                <button class="quick-btn quick-btn-outline" onclick="exportData()"><i class="fas fa-file-export"></i> Export Data</button>
                <button class="quick-btn quick-btn-outline" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
            </div>
        </div>
    </div>
    
    <?php include('assets/inc/footer.php'); ?>
</div>

<!-- Loading Overlay -->
<div id="loadingOverlay" class="loading-overlay"><div class="spinner"></div></div>

<!-- ===================== MODAL: Tariff Add/Edit ===================== -->
<div id="tariffModal" class="modal fade" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 id="tariffModalTitle"><i class="fas fa-tag me-2"></i>Tariff Configuration</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="tariffForm">
                    <input type="hidden" name="tariff_id" id="tariffId">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    
                    <div class="row">
                        <div class="col-md-6 mb-2">
                            <label class="form-label fw-semibold">Branch *</label>
                            <select name="branch_id" id="tariffBranch" class="form-select" required>
                                <?php foreach($branches as $b): ?>
                                <option value="<?php echo $b['id']; ?>"><?php echo escapeHtml($b['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-2">
                            <label class="form-label fw-semibold">Category *</label>
                            <select name="category" id="tariffCategory" class="form-select" required>
                                <option value="consultation">Consultation</option>
                                <option value="lab">Laboratory</option>
                                <option value="radiology">Radiology</option>
                                <option value="admission">Ward / Admission</option>
                                <option value="procedure">Procedure</option>
                                <option value="pharmacy">Pharmacy</option>
                                <option value="surgery">Surgery</option>
                                <option value="ambulance">Ambulance</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-2">
                            <label class="form-label fw-semibold">Item Code *</label>
                            <input type="text" name="item_code" id="tariffCode" class="form-control" required placeholder="e.g., CONS-GEN-001">
                        </div>
                        <div class="col-md-6 mb-2">
                            <label class="form-label fw-semibold">Service Name *</label>
                            <input type="text" name="item_name" id="tariffName" class="form-control" required placeholder="e.g., General Consultation">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-4 mb-2">
                            <label class="form-label fw-semibold">Base Price (ETB) *</label>
                            <input type="number" step="0.01" name="base_price" id="tariffPrice" class="form-control" required>
                        </div>
                        <div class="col-md-4 mb-2">
                            <label class="form-label fw-semibold">Insurance Price (ETB)</label>
                            <input type="number" step="0.01" name="insurance_price" id="tariffInsurancePrice" class="form-control">
                        </div>
                        <div class="col-md-4 mb-2">
                            <label class="form-label fw-semibold">Cost Price (ETB)</label>
                            <input type="number" step="0.01" name="cost_price" id="tariffCostPrice" class="form-control">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-4 mb-2">
                            <label class="form-label fw-semibold">Tax Rate (%)</label>
                            <input type="number" step="0.01" name="tax_rate" id="tariffTaxRate" class="form-control" value="15">
                        </div>
                        <div class="col-md-4 mb-2">
                            <label class="form-label fw-semibold">Insurance Coverage (%)</label>
                            <input type="number" step="0.01" name="insurance_coverage" id="tariffInsuranceCoverage" class="form-control" value="80">
                        </div>
                        <div class="col-md-4 mb-2 d-flex align-items-end">
                            <div class="form-check">
                                <input type="checkbox" name="is_taxable" id="tariffTaxable" class="form-check-input" checked>
                                <label class="form-check-label">Taxable</label>
                            </div>
                            <div class="form-check ms-3">
                                <input type="checkbox" name="is_active" id="tariffActive" class="form-check-input" checked>
                                <label class="form-check-label">Active</label>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button class="quick-btn quick-btn-outline" data-bs-dismiss="modal">Cancel</button>
                <button class="quick-btn quick-btn-primary" id="saveTariffBtn"><i class="fas fa-save"></i> Save Tariff</button>
            </div>
        </div>
    </div>
</div>

<!-- ===================== MODAL: Insurance Provider ===================== -->
<div id="providerModal" class="modal fade" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 id="providerModalTitle"><i class="fas fa-building me-2"></i>Insurance Provider</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="providerForm">
                    <input type="hidden" name="provider_id" id="providerId">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    
                    <div class="row">
                        <div class="col-md-6 mb-2">
                            <label class="form-label fw-semibold">Branch *</label>
                            <select name="branch_id" id="providerBranch" class="form-select" required>
                                <?php foreach($branches as $b): ?>
                                <option value="<?php echo $b['id']; ?>"><?php echo escapeHtml($b['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-2">
                            <label class="form-label fw-semibold">Provider Code *</label>
                            <input type="text" name="code" id="providerCode" class="form-control" required placeholder="e.g., EHIA">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-12 mb-2">
                            <label class="form-label fw-semibold">Provider Name *</label>
                            <input type="text" name="name" id="providerName" class="form-control" required placeholder="e.g., Ethiopian Health Insurance Agency">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-2">
                            <label class="form-label fw-semibold">Coverage Percentage (%) *</label>
                            <input type="number" step="0.01" name="coverage_percentage" id="providerCoverage" class="form-control" required>
                        </div>
                        <div class="col-md-6 mb-2">
                            <label class="form-label fw-semibold">Patient Copay Percentage (%) *</label>
                            <input type="number" step="0.01" name="copay_percentage" id="providerCopay" class="form-control" required>
                            <small class="text-muted">Coverage + Copay must equal 100%</small>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-2">
                            <label class="form-label fw-semibold">Contact Person</label>
                            <input type="text" name="contact_person" id="providerContact" class="form-control">
                        </div>
                        <div class="col-md-6 mb-2">
                            <label class="form-label fw-semibold">Phone</label>
                            <input type="text" name="phone" id="providerPhone" class="form-control">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-2">
                            <label class="form-label fw-semibold">Email</label>
                            <input type="email" name="email" id="providerEmail" class="form-control">
                        </div>
                        <div class="col-md-6 mb-2">
                            <label class="form-label fw-semibold">Credit Limit (ETB)</label>
                            <input type="number" step="0.01" name="credit_limit" id="providerCreditLimit" class="form-control" value="0">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-2">
                            <label class="form-label fw-semibold">Contract Start Date</label>
                            <input type="date" name="contract_start_date" id="providerStartDate" class="form-control">
                        </div>
                        <div class="col-md-6 mb-2">
                            <label class="form-label fw-semibold">Contract End Date</label>
                            <input type="date" name="contract_end_date" id="providerEndDate" class="form-control">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-4 mb-2">
                            <label class="form-label fw-semibold">Payment Terms (Days)</label>
                            <input type="number" name="payment_terms_days" id="providerPaymentTerms" class="form-control" value="30">
                        </div>
                        <div class="col-md-4 mb-2">
                            <label class="form-label fw-semibold">Claim Deadline (Days)</label>
                            <input type="number" name="claim_deadline_days" id="providerClaimDeadline" class="form-control" value="30">
                        </div>
                        <div class="col-md-4 mb-2 d-flex align-items-end">
                            <div class="form-check">
                                <input type="checkbox" name="requires_pre_authorization" id="providerPreAuth" class="form-check-input">
                                <label class="form-check-label">Requires Pre-Authorization</label>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button class="quick-btn quick-btn-outline" data-bs-dismiss="modal">Cancel</button>
                <button class="quick-btn quick-btn-primary" id="saveProviderBtn"><i class="fas fa-save"></i> Save Provider</button>
            </div>
        </div>
    </div>
</div>

<script src="assets/js/vendor.min.js"></script>
<script src="assets/js/app.min.js"></script>
<script>
// ============================================
// CONFIGURATION
// ============================================
const csrfToken = "<?php echo $csrf_token; ?>";
const AJAX_URL = window.location.pathname + '?ajax=financial_action';
const branchId = <?php echo $branch_id; ?>;
let tariffsTable, providersTable, expensesTable, cashFlowChart;

// ============================================
// DOCUMENT READY
// ============================================
$(function() {
    // Load all data
    loadFinancialKPIs();
    loadTariffsTable();
    loadProvidersTable();
    loadPendingExpensesTable();
    loadCashFlowChart();
    loadBranchSummary();
    
    // Event listeners
    $('#globalBranchFilter, #monthFilter, #yearFilter').on('change', function() {
        refreshAllFinancialData();
    });
    
    // Tariff buttons
    $('#addTariffBtn').on('click', function() { resetTariffForm(); $('#tariffModal').modal('show'); });
    $('#saveTariffBtn').on('click', saveTariff);
    
    // Provider buttons
    $('#addProviderBtn').on('click', function() { resetProviderForm(); $('#providerModal').modal('show'); });
    $('#saveProviderBtn').on('click', saveProvider);
});

// ============================================
// REFRESH FUNCTIONS
// ============================================
function refreshAllFinancialData() {
    showLoading(true);
    loadFinancialKPIs();
    loadTariffsTable();
    loadProvidersTable();
    loadPendingExpensesTable();
    loadCashFlowChart();
    loadBranchSummary();
    setTimeout(function() {
        showLoading(false);
        showToast('Data refreshed successfully', 'success');
    }, 500);
}

// ============================================
// LOADING OVERLAY
// ============================================
function showLoading(show) {
    if (show) {
        $('#loadingOverlay').addClass('active');
    } else {
        $('#loadingOverlay').removeClass('active');
    }
}

// ============================================
// TOAST NOTIFICATION
// ============================================
function showToast(message, type) {
    type = type || 'success';
    const toast = document.createElement('div');
    toast.style.cssText = `
        position: fixed; bottom: 20px; right: 20px; background: white;
        border-radius: 12px; padding: 12px 20px; box-shadow: 0 10px 25px rgba(0,0,0,0.15);
        z-index: 9999; animation: slideIn 0.3s ease; display: flex; align-items: center; gap: 12px;
        border-left: 4px solid ${type === 'success' ? '#06d6a0' : '#ef476f'};
        font-family: 'Inter', sans-serif;
        max-width: 400px;
    `;
    toast.innerHTML = `<i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-circle'}" style="color:${type === 'success' ? '#06d6a0' : '#ef476f'};"></i> <span>${message}</span>`;
    document.body.appendChild(toast);
    setTimeout(function() {
        if (toast.parentNode) toast.remove();
    }, 4000);
}

// ============================================
// KPI FUNCTIONS
// ============================================
function loadFinancialKPIs() {
    let branch = $('#globalBranchFilter').val();
    let month = $('#monthFilter').val();
    let year = $('#yearFilter').val();
    
    $.ajax({
        url: AJAX_URL,
        method: 'POST',
        dataType: 'json',
        data: { 
            action: 'get_financial_kpis', 
            branch_id: branch,
            month: month,
            year: year,
            csrf_token: csrfToken 
        },
        success: function(res) {
            if (res.success) {
                $('#totalRevenue').text(res.total_revenue + ' ETB');
                $('#totalExpenses').text(res.total_expenses + ' ETB');
                
                let netProfit = parseFloat(res.net_profit);
                $('#netProfit').text(res.net_profit + ' ETB');
                if (netProfit >= 0) {
                    $('#netProfit').css('color', '#06d6a0');
                } else {
                    $('#netProfit').css('color', '#ef476f');
                }
                $('#pendingExpenses').text(res.pending_expenses);
            }
        },
        error: function() {
            console.error('Failed to load KPIs');
        }
    });
}

// ============================================
// TARIFF FUNCTIONS
// ============================================
function loadTariffsTable() {
    let branch = $('#globalBranchFilter').val();
    let tbody = $('#tariffsTable tbody');
    tbody.html('<tr><td colspan="9" class="text-center text-muted">Loading tariffs...</td></tr>');
    
    $.ajax({
        url: AJAX_URL,
        method: 'POST',
        dataType: 'json',
        data: { action: 'get_tariffs', branch_id: branch, csrf_token: csrfToken },
        success: function(res) {
            if (res.success && res.tariffs && res.tariffs.length > 0) {
                let rows = '';
                res.tariffs.forEach(function(t) {
                    let statusBadge = t.is_active ? 
                        '<span class="badge-active"><i class="fas fa-check-circle"></i> Active</span>' : 
                        '<span class="badge-inactive"><i class="fas fa-times-circle"></i> Inactive</span>';
                    let basePrice = parseFloat(t.base_price || 0).toFixed(2);
                    let insPrice = parseFloat(t.insurance_price || 0).toFixed(2);
                    
                    rows += `<tr>
                        <td><span class="fw-semibold">${t.id}</span></td>
                        <td>${escapeHtml(t.branch_name || 'N/A')}</td>
                        <td><code>${escapeHtml(t.item_code)}</code></td>
                        <td>${escapeHtml(t.item_name)}</td>
                        <td><span class="badge bg-light text-dark">${escapeHtml(t.category)}</span></td>
                        <td class="fw-semibold">${basePrice}</td>
                        <td>${insPrice}</td>
                        <td>${statusBadge}</td>
                        <td>
                            <button class="btn btn-sm btn-outline-primary edit-tariff" 
                                data-id="${t.id}" 
                                data-code="${escapeHtml(t.item_code)}" 
                                data-name="${escapeHtml(t.item_name)}" 
                                data-cat="${escapeHtml(t.category)}" 
                                data-price="${t.base_price}"
                                data-ins-price="${t.insurance_price || 0}"
                                data-cost-price="${t.cost_price || 0}"
                                data-taxable="${t.is_taxable || 0}"
                                data-tax-rate="${t.tax_rate || 15}"
                                data-ins-cov="${t.insurance_coverage_percentage || 80}"
                                data-active="${t.is_active}" 
                                data-branch="${t.branch_id}">
                                <i class="fas fa-edit"></i>
                            </button>
                            <button class="btn btn-sm btn-outline-secondary toggle-tariff" 
                                data-id="${t.id}" data-status="${t.is_active}">
                                <i class="fas fa-power-off"></i>
                            </button>
                            <button class="btn btn-sm btn-outline-danger delete-tariff" 
                                data-id="${t.id}" data-name="${escapeHtml(t.item_name)}">
                                <i class="fas fa-trash"></i>
                            </button>
                        </td>
                    </tr>`;
                });
                tbody.html(rows);
                
                // Bind events
                $('.edit-tariff').on('click', function() { 
                    openTariffEdit(
                        $(this).data('id'), $(this).data('code'), $(this).data('name'),
                        $(this).data('cat'), $(this).data('price'), $(this).data('ins-price'),
                        $(this).data('cost-price'), $(this).data('taxable'), $(this).data('tax-rate'),
                        $(this).data('ins-cov'), $(this).data('active'), $(this).data('branch')
                    ); 
                });
                $('.toggle-tariff').on('click', function() { 
                    toggleTariffStatus($(this).data('id'), $(this).data('status')); 
                });
                $('.delete-tariff').on('click', function() { 
                    deleteTariff($(this).data('id'), $(this).data('name')); 
                });
            } else {
                tbody.html('<tr><td colspan="9" class="text-center text-muted">No tariffs found.</td></tr>');
            }
        },
        error: function() {
            tbody.html('<tr><td colspan="9" class="text-center text-danger">Error loading tariffs.</td></tr>');
        }
    });
}

function openTariffEdit(id, code, name, cat, price, insPrice, costPrice, taxable, taxRate, insCov, active, branchId) {
    $('#tariffId').val(id);
    $('#tariffCode').val(code);
    $('#tariffName').val(name);
    $('#tariffCategory').val(cat);
    $('#tariffPrice').val(price);
    $('#tariffInsurancePrice').val(insPrice || 0);
    $('#tariffCostPrice').val(costPrice || 0);
    $('#tariffTaxRate').val(taxRate || 15);
    $('#tariffInsuranceCoverage').val(insCov || 80);
    $('#tariffBranch').val(branchId);
    $('#tariffTaxable').prop('checked', taxable == 1);
    $('#tariffActive').prop('checked', active == 1);
    $('#tariffModalTitle').html('<i class="fas fa-edit me-2"></i>Edit Tariff');
    $('#tariffModal').modal('show');
}

function resetTariffForm() {
    $('#tariffForm')[0].reset();
    $('#tariffId').val('');
    $('#tariffModalTitle').html('<i class="fas fa-plus me-2"></i>Add Tariff');
    $('#tariffActive').prop('checked', true);
    $('#tariffTaxable').prop('checked', true);
    $('#tariffTaxRate').val(15);
    $('#tariffInsuranceCoverage').val(80);
}

function saveTariff() {
    let formData = $('#tariffForm').serialize();
    formData += '&action=save_tariff';
    
    showLoading(true);
    $.ajax({
        url: AJAX_URL,
        method: 'POST',
        data: formData,
        dataType: 'json',
        success: function(res) {
            showLoading(false);
            if (res.success) {
                Swal.fire('Success', res.message, 'success');
                $('#tariffModal').modal('hide');
                loadTariffsTable();
                loadFinancialKPIs();
            } else {
                Swal.fire('Error', res.message, 'error');
            }
        },
        error: function() {
            showLoading(false);
            Swal.fire('Error', 'Network error', 'error');
        }
    });
}

function toggleTariffStatus(id, current) {
    Swal.fire({
        title: 'Confirm',
        text: 'Toggle tariff active status?',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Yes, toggle'
    }).then(function(result) {
        if (result.isConfirmed) {
            $.ajax({
                url: AJAX_URL,
                method: 'POST',
                dataType: 'json',
                data: { 
                    action: 'toggle_tariff', 
                    tariff_id: id, 
                    current_status: current, 
                    csrf_token: csrfToken 
                },
                success: function(res) {
                    if (res.success) {
                        loadTariffsTable();
                        Swal.fire('Updated', res.message, 'success');
                    } else {
                        Swal.fire('Error', res.message, 'error');
                    }
                }
            });
        }
    });
}

function deleteTariff(id, name) {
    Swal.fire({
        title: 'Delete Tariff',
        text: `Are you sure you want to delete "${name}"?`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#ef476f',
        confirmButtonText: 'Yes, delete'
    }).then(function(result) {
        if (result.isConfirmed) {
            $.ajax({
                url: AJAX_URL,
                method: 'POST',
                dataType: 'json',
                data: { 
                    action: 'delete_tariff', 
                    tariff_id: id, 
                    csrf_token: csrfToken 
                },
                success: function(res) {
                    if (res.success) {
                        loadTariffsTable();
                        Swal.fire('Deleted', res.message, 'success');
                    } else {
                        Swal.fire('Error', res.message, 'error');
                    }
                }
            });
        }
    });
}

// ============================================
// PROVIDER FUNCTIONS
// ============================================
function loadProvidersTable() {
    let branch = $('#globalBranchFilter').val();
    let tbody = $('#providersTable tbody');
    tbody.html('<tr><td colspan="8" class="text-center text-muted">Loading providers...</td></tr>');
    
    $.ajax({
        url: AJAX_URL,
        method: 'POST',
        dataType: 'json',
        data: { action: 'get_providers', branch_id: branch, csrf_token: csrfToken },
        success: function(res) {
            if (res.success && res.providers && res.providers.length > 0) {
                let rows = '';
                res.providers.forEach(function(p) {
                    let statusBadge = p.is_active ? 
                        '<span class="badge-active"><i class="fas fa-check-circle"></i> Active</span>' : 
                        '<span class="badge-inactive"><i class="fas fa-times-circle"></i> Inactive</span>';
                    let contractPeriod = (p.contract_start_date ? p.contract_start_date : 'N/A') + ' → ' + 
                                       (p.contract_end_date ? p.contract_end_date : 'N/A');
                    
                    rows += `<tr>
                        <td><span class="fw-semibold">${p.id}</span></td>
                        <td>${escapeHtml(p.name)}</td>
                        <td><code>${escapeHtml(p.code)}</code></td>
                        <td>${parseFloat(p.default_coverage_percentage || 0).toFixed(2)}%</td>
                        <td>${parseFloat(p.default_co_pay_percentage || 0).toFixed(2)}%</td>
                        <td><small>${contractPeriod}</small></td>
                        <td>${statusBadge}</td>
                        <td>
                            <button class="btn btn-sm btn-outline-primary edit-provider" 
                                data-id="${p.id}" 
                                data-name="${escapeHtml(p.name)}" 
                                data-code="${escapeHtml(p.code)}" 
                                data-cov="${p.default_coverage_percentage || 0}" 
                                data-cop="${p.default_co_pay_percentage || 0}" 
                                data-contact="${escapeHtml(p.contact_person)}" 
                                data-phone="${escapeHtml(p.phone)}" 
                                data-email="${escapeHtml(p.email)}" 
                                data-start="${p.contract_start_date}" 
                                data-end="${p.contract_end_date}"
                                data-credit="${p.credit_limit || 0}"
                                data-terms="${p.payment_terms_days || 30}"
                                data-deadline="${p.claim_submission_deadline_days || 30}"
                                data-pre-auth="${p.requires_pre_authorization || 0}">
                                <i class="fas fa-edit"></i>
                            </button>
                            <button class="btn btn-sm btn-outline-secondary toggle-provider" 
                                data-id="${p.id}" data-status="${p.is_active}">
                                <i class="fas fa-power-off"></i>
                            </button>
                            <button class="btn btn-sm btn-outline-danger delete-provider" 
                                data-id="${p.id}" data-name="${escapeHtml(p.name)}">
                                <i class="fas fa-trash"></i>
                            </button>
                        </td>
                    </tr>`;
                });
                tbody.html(rows);
                
                $('.edit-provider').on('click', function() { 
                    openProviderEdit(
                        $(this).data('id'), $(this).data('name'), $(this).data('code'),
                        $(this).data('cov'), $(this).data('cop'), $(this).data('contact'),
                        $(this).data('phone'), $(this).data('email'), $(this).data('start'),
                        $(this).data('end'), $(this).data('credit'), $(this).data('terms'),
                        $(this).data('deadline'), $(this).data('pre-auth')
                    ); 
                });
                $('.toggle-provider').on('click', function() { 
                    toggleProviderStatus($(this).data('id'), $(this).data('status')); 
                });
                $('.delete-provider').on('click', function() { 
                    deleteProvider($(this).data('id'), $(this).data('name')); 
                });
            } else {
                tbody.html('<tr><td colspan="8" class="text-center text-muted">No providers found.</td></tr>');
            }
        },
        error: function() {
            tbody.html('<tr><td colspan="8" class="text-center text-danger">Error loading providers.</td></tr>');
        }
    });
}

function openProviderEdit(id, name, code, cov, cop, contact, phone, email, start, end, credit, terms, deadline, preAuth) {
    $('#providerId').val(id);
    $('#providerName').val(name);
    $('#providerCode').val(code);
    $('#providerCoverage').val(cov);
    $('#providerCopay').val(cop);
    $('#providerContact').val(contact || '');
    $('#providerPhone').val(phone || '');
    $('#providerEmail').val(email || '');
    $('#providerStartDate').val(start);
    $('#providerEndDate').val(end);
    $('#providerCreditLimit').val(credit || 0);
    $('#providerPaymentTerms').val(terms || 30);
    $('#providerClaimDeadline').val(deadline || 30);
    $('#providerPreAuth').prop('checked', preAuth == 1);
    $('#providerModalTitle').html('<i class="fas fa-edit me-2"></i>Edit Insurance Provider');
    $('#providerModal').modal('show');
}

function resetProviderForm() {
    $('#providerForm')[0].reset();
    $('#providerId').val('');
    $('#providerModalTitle').html('<i class="fas fa-plus me-2"></i>Add Insurance Provider');
    $('#providerCoverage').val(80);
    $('#providerCopay').val(20);
    $('#providerPaymentTerms').val(30);
    $('#providerClaimDeadline').val(30);
    $('#providerPreAuth').prop('checked', false);
}

function saveProvider() {
    let formData = $('#providerForm').serialize();
    formData += '&action=save_provider';
    
    showLoading(true);
    $.ajax({
        url: AJAX_URL,
        method: 'POST',
        data: formData,
        dataType: 'json',
        success: function(res) {
            showLoading(false);
            if (res.success) {
                Swal.fire('Success', res.message, 'success');
                $('#providerModal').modal('hide');
                loadProvidersTable();
            } else {
                Swal.fire('Error', res.message, 'error');
            }
        },
        error: function() {
            showLoading(false);
            Swal.fire('Error', 'Network error', 'error');
        }
    });
}

function toggleProviderStatus(id, current) {
    Swal.fire({
        title: 'Confirm',
        text: 'Toggle provider active status?',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Yes, toggle'
    }).then(function(result) {
        if (result.isConfirmed) {
            $.ajax({
                url: AJAX_URL,
                method: 'POST',
                dataType: 'json',
                data: { 
                    action: 'toggle_provider', 
                    provider_id: id, 
                    current_status: current, 
                    csrf_token: csrfToken 
                },
                success: function(res) {
                    if (res.success) {
                        loadProvidersTable();
                        Swal.fire('Updated', res.message, 'success');
                    } else {
                        Swal.fire('Error', res.message, 'error');
                    }
                }
            });
        }
    });
}

function deleteProvider(id, name) {
    Swal.fire({
        title: 'Delete Provider',
        text: `Are you sure you want to delete "${name}"?`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#ef476f',
        confirmButtonText: 'Yes, delete'
    }).then(function(result) {
        if (result.isConfirmed) {
            $.ajax({
                url: AJAX_URL,
                method: 'POST',
                dataType: 'json',
                data: { 
                    action: 'delete_provider', 
                    provider_id: id, 
                    csrf_token: csrfToken 
                },
                success: function(res) {
                    if (res.success) {
                        loadProvidersTable();
                        Swal.fire('Deleted', res.message, 'success');
                    } else {
                        Swal.fire('Error', res.message, 'error');
                    }
                }
            });
        }
    });
}

// ============================================
// EXPENSE FUNCTIONS
// ============================================
function loadPendingExpensesTable() {
    let branch = $('#globalBranchFilter').val();
    let tbody = $('#expensesTable tbody');
    tbody.html('<tr><td colspan="8" class="text-center text-muted">Loading pending expenses...</td></tr>');
    
    $.ajax({
        url: AJAX_URL,
        method: 'POST',
        dataType: 'json',
        data: { action: 'get_pending_expenses', branch_id: branch, csrf_token: csrfToken },
        success: function(res) {
            if (res.success && res.expenses && res.expenses.length > 0) {
                let rows = '';
                res.expenses.forEach(function(e) {
                    let requestedBy = escapeHtml(e.first_name || '') + ' ' + escapeHtml(e.last_name || '');
                    let branchName = escapeHtml(e.branch_name || 'Branch #' + e.branch_id);
                    let categoryName = escapeHtml(e.category || 'Uncategorized');
                    let expenseDate = e.expense_date || e.created_at || 'N/A';
                    
                    rows += `<tr>
                        <td><span class="fw-semibold">${e.id}</span></td>
                        <td>${branchName}</td>
                        <td>${categoryName}</td>
                        <td class="fw-semibold">${parseFloat(e.amount || 0).toFixed(2)}</td>
                        <td>${escapeHtml(e.description || '—')}</td>
                        <td>${requestedBy || 'N/A'}</td>
                        <td><small>${expenseDate}</small></td>
                        <td>
                            <button class="btn btn-sm btn-success approve-expense" data-id="${e.id}">
                                <i class="fas fa-check"></i> Approve
                            </button>
                            <button class="btn btn-sm btn-danger reject-expense" data-id="${e.id}">
                                <i class="fas fa-times"></i> Reject
                            </button>
                        </td>
                    </tr>`;
                });
                tbody.html(rows);
                
                $('.approve-expense').on('click', function() { reviewExpense($(this).data('id'), 'approved'); });
                $('.reject-expense').on('click', function() { reviewExpense($(this).data('id'), 'rejected'); });
            } else {
                tbody.html('<tr><td colspan="8" class="text-center text-muted">No pending expenses found.</td></tr>');
            }
        },
        error: function() {
            tbody.html('<tr><td colspan="8" class="text-center text-danger">Error loading expenses.</td></tr>');
        }
    });
}

function reviewExpense(expenseId, decision) {
    Swal.fire({
        title: `Confirm ${decision}`,
        text: `Are you sure you want to ${decision} this expense?`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: `Yes, ${decision}`,
        confirmButtonColor: decision === 'approved' ? '#06d6a0' : '#ef476f'
    }).then(function(result) {
        if (result.isConfirmed) {
            showLoading(true);
            $.ajax({
                url: AJAX_URL,
                method: 'POST',
                dataType: 'json',
                data: { 
                    action: 'review_expense', 
                    expense_id: expenseId, 
                    decision: decision, 
                    csrf_token: csrfToken 
                },
                success: function(res) {
                    showLoading(false);
                    if (res.success) {
                        Swal.fire('Success', res.message, 'success');
                        loadPendingExpensesTable();
                        loadFinancialKPIs();
                    } else {
                        Swal.fire('Error', res.message, 'error');
                    }
                },
                error: function() {
                    showLoading(false);
                    Swal.fire('Error', 'Network error', 'error');
                }
            });
        }
    });
}

// ============================================
// CHART FUNCTIONS
// ============================================
function loadCashFlowChart() {
    let branch = $('#globalBranchFilter').val();
    let month = $('#monthFilter').val();
    let year = $('#yearFilter').val();
    
    $.ajax({
        url: AJAX_URL,
        method: 'POST',
        dataType: 'json',
        data: { 
            action: 'get_cashflow_chart_data', 
            branch_id: branch,
            month: month,
            year: year,
            csrf_token: csrfToken 
        },
        success: function(res) {
            if (res.success) {
                $('#chartPeriodLabel').text(res.month + ' ' + res.year);
                
                if (cashFlowChart) cashFlowChart.destroy();
                
                const ctx = document.getElementById('cashFlowChart').getContext('2d');
                cashFlowChart = new Chart(ctx, {
                    type: 'line',
                    data: {
                        labels: res.labels,
                        datasets: [
                            {
                                label: 'Revenue (ETB)',
                                data: res.revenue,
                                borderColor: '#06d6a0',
                                backgroundColor: 'rgba(6,214,160,0.1)',
                                fill: true,
                                tension: 0.3,
                                borderWidth: 3,
                                pointRadius: 3
                            },
                            {
                                label: 'Expenses (ETB)',
                                data: res.expenses,
                                borderColor: '#ef476f',
                                backgroundColor: 'rgba(239,71,111,0.1)',
                                fill: true,
                                tension: 0.3,
                                borderWidth: 3,
                                pointRadius: 3
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: true,
                        plugins: {
                            legend: {
                                position: 'top',
                                labels: {
                                    usePointStyle: true,
                                    padding: 20
                                }
                            },
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        return context.dataset.label + ': ' + parseFloat(context.raw).toFixed(2) + ' ETB';
                                    }
                                }
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                ticks: {
                                    callback: function(value) {
                                        return value.toFixed(0);
                                    }
                                }
                            }
                        }
                    }
                });
            }
        },
        error: function() {
            console.error('Failed to load chart data');
        }
    });
}

// ============================================
// BRANCH SUMMARY
// ============================================
function loadBranchSummary() {
    $.ajax({
        url: AJAX_URL,
        method: 'POST',
        dataType: 'json',
        data: { action: 'get_branch_summary', csrf_token: csrfToken },
        success: function(res) {
            if (res.success && res.branches && res.branches.length > 0) {
                let html = '<div class="list-group list-group-flush">';
                res.branches.forEach(function(b) {
                    let profit = parseFloat(b.monthly_revenue || 0) - parseFloat(b.monthly_expenses || 0);
                    let profitClass = profit >= 0 ? 'text-success' : 'text-danger';
                    html += `<div class="list-group-item d-flex justify-content-between align-items-center">
                        <div>
                            <strong>${escapeHtml(b.name)}</strong>
                            <div><small class="text-muted">${b.total_patients || 0} patients</small></div>
                        </div>
                        <div class="text-end">
                            <div class="small">Revenue: ${parseFloat(b.monthly_revenue || 0).toFixed(2)} ETB</div>
                            <div class="small">Expenses: ${parseFloat(b.monthly_expenses || 0).toFixed(2)} ETB</div>
                            <div class="fw-bold ${profitClass}">${profit >= 0 ? '+' : ''}${profit.toFixed(2)} ETB</div>
                        </div>
                    </div>`;
                });
                html += '</div>';
                $('#branchSummaryContent').html(html);
            } else {
                $('#branchSummaryContent').html('<div class="empty-state"><i class="fas fa-building"></i><p>No branch data available</p></div>');
            }
        },
        error: function() {
            $('#branchSummaryContent').html('<div class="empty-state"><i class="fas fa-exclamation-circle"></i><p>Error loading branch data</p></div>');
        }
    });
}

// ============================================
// UTILITY FUNCTIONS
// ============================================
function escapeHtml(str) {
    if (!str) return '';
    return String(str).replace(/[&<>]/g, function(m) {
        return m === '&' ? '&amp;' : (m === '<' ? '&lt;' : '&gt;');
    });
}

function formatCurrency(value) {
    if (value === undefined || value === null) return '0.00';
    return Number(value).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
}

function exportData() {
    Swal.fire({
        title: 'Export Data',
        html: `<div class="text-start">
            <div class="form-group mb-2">
                <label class="fw-semibold">Export Type</label>
                <select id="exportType" class="form-control">
                    <option value="tariffs">Service Tariffs</option>
                    <option value="providers">Insurance Providers</option>
                    <option value="expenses">Expense Reports</option>
                    <option value="all">All Data</option>
                </select>
            </div>
            <div class="form-group mb-2">
                <label class="fw-semibold">Format</label>
                <select id="exportFormat" class="form-control">
                    <option value="csv">CSV</option>
                    <option value="json">JSON</option>
                </select>
            </div>
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

// Add CSS for slide-in animation
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