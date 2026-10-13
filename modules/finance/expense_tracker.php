<?php
/**
 * expense_tracker.php
 * 
 * Petty Cash & Expense Management Module
 * 
 * Automatically creates required tables if they don't exist.
 * Provides full workflow: log expenses, approve/reject, debit account, file uploads.
 * Enhanced with: Multi-branch support, expense analytics, budget tracking,
 * approval workflows, and advanced filtering.
 * 
 * Required permissions:
 *   - track_hospital_expenses : view and log expenses
 *   - approve_hospital_expenses : approve/reject expenses (managerial)
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

$can_view = $is_global_admin || user_has_permission($pdo, 'track_hospital_expenses', $user_id);
$can_approve = $is_global_admin || user_has_permission($pdo, 'approve_hospital_expenses', $user_id);
$can_manage_budget = $is_global_admin || user_has_permission($pdo, 'manage_budgets', $user_id);

if (!$can_view) {
    header('Location: dashboard.php?error=unauthorized');
    exit;
}

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// ----------------------------------------------------------------------
// 2. CREATE MISSING TABLES (if not exist)
// ----------------------------------------------------------------------
try {
    // Table: expense_categories
    $pdo->exec("CREATE TABLE IF NOT EXISTS `expense_categories` (
        `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
        `category_name` varchar(100) NOT NULL,
        `status` enum('active','inactive') NOT NULL DEFAULT 'active',
        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Table: bank_accounts (petty cash accounts)
    $pdo->exec("CREATE TABLE IF NOT EXISTS `bank_accounts` (
        `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
        `branch_id` int(10) UNSIGNED NOT NULL,
        `account_name` varchar(100) NOT NULL,
        `account_type` enum('petty_cash','bank') NOT NULL DEFAULT 'petty_cash',
        `current_balance` decimal(12,2) NOT NULL DEFAULT 0.00,
        `status` enum('active','inactive') NOT NULL DEFAULT 'active',
        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
        `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
        PRIMARY KEY (`id`),
        KEY `idx_branch_id` (`branch_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Table: expenses (with deleted_at column)
    $pdo->exec("CREATE TABLE IF NOT EXISTS `expenses` (
        `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
        `branch_id` int(10) UNSIGNED NOT NULL,
        `category_id` int(10) UNSIGNED NOT NULL,
        `account_id` int(10) UNSIGNED NOT NULL,
        `amount` decimal(12,2) NOT NULL,
        `description` text NOT NULL,
        `attachment_path` varchar(500) DEFAULT NULL,
        `status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
        `requested_by_user_id` int(10) UNSIGNED NOT NULL,
        `approved_by_user_id` int(10) UNSIGNED DEFAULT NULL,
        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
        `approved_at` timestamp NULL DEFAULT NULL,
        `deleted_at` timestamp NULL DEFAULT NULL,
        PRIMARY KEY (`id`),
        KEY `idx_branch_id` (`branch_id`),
        KEY `idx_category_id` (`category_id`),
        KEY `idx_account_id` (`account_id`),
        KEY `idx_status` (`status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Table: expense_budgets
    $pdo->exec("CREATE TABLE IF NOT EXISTS `expense_budgets` (
        `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
        `branch_id` int(10) UNSIGNED NOT NULL,
        `category_id` int(10) UNSIGNED NOT NULL,
        `budget_year` int(4) NOT NULL,
        `budget_month` int(2) NOT NULL,
        `allocated_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
        `spent_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
        `created_by_user_id` int(10) UNSIGNED NOT NULL,
        `updated_by_user_id` int(10) UNSIGNED DEFAULT NULL,
        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
        `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_branch_category_month` (`branch_id`,`category_id`,`budget_year`,`budget_month`),
        KEY `idx_category_id` (`category_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Add deleted_at column to existing expenses table if it doesn't exist
    try {
        $pdo->exec("ALTER TABLE `expenses` ADD COLUMN `deleted_at` timestamp NULL DEFAULT NULL AFTER `approved_at`");
    } catch (PDOException $e) {
        // Column already exists - ignore
    }

    // Insert default categories if table is empty
    $check_cats = $pdo->query("SELECT COUNT(*) FROM expense_categories")->fetchColumn();
    if ($check_cats == 0) {
        $pdo->exec("INSERT INTO expense_categories (category_name) VALUES 
            ('Office Supplies'), ('Cleaning Supplies'), ('Fuel & Transport'), 
            ('Maintenance & Repairs'), ('Staff Welfare'), ('Emergency Provisions'), 
            ('Utilities'), ('Printing & Stationery'), ('Medical Supplies'), 
            ('Equipment Maintenance'), ('Training & Development'), ('IT & Software')");
    }

    // Insert default petty cash account for the current branch if none exists
    $check_acc = $pdo->prepare("SELECT COUNT(*) FROM bank_accounts WHERE branch_id = ? AND account_type = 'petty_cash'");
    $check_acc->execute([$branch_id]);
    if ($check_acc->fetchColumn() == 0) {
        $pdo->prepare("INSERT INTO bank_accounts (branch_id, account_name, account_type, current_balance) VALUES (?, 'Main Petty Cash', 'petty_cash', 10000.00)")->execute([$branch_id]);
        $pdo->prepare("INSERT INTO bank_accounts (branch_id, account_name, account_type, current_balance) VALUES (?, 'Emergency Cash Reserve', 'petty_cash', 5000.00)")->execute([$branch_id]);
    }

    // Create default budgets for current month
    $current_month = date('n');
    $current_year = date('Y');
    $check_budget = $pdo->prepare("SELECT COUNT(*) FROM expense_budgets WHERE branch_id = ? AND budget_year = ? AND budget_month = ?");
    $check_budget->execute([$branch_id, $current_year, $current_month]);
    if ($check_budget->fetchColumn() == 0) {
        $categories = $pdo->query("SELECT id FROM expense_categories WHERE status = 'active'")->fetchAll(PDO::FETCH_COLUMN);
        $insert_budget = $pdo->prepare("INSERT INTO expense_budgets (branch_id, category_id, budget_year, budget_month, allocated_amount, spent_amount, created_by_user_id) VALUES (?, ?, ?, ?, 0, 0, ?)");
        foreach ($categories as $cat_id) {
            $insert_budget->execute([$branch_id, $cat_id, $current_year, $current_month, $user_id]);
        }
    }

} catch (PDOException $e) {
    error_log('Table creation error: ' . $e->getMessage());
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
if (isset($_GET['ajax']) && $_GET['ajax'] === 'expense_action') {
    $action = $_GET['action'] ?? '';

    // Fetch all expenses for the left table
    if ($action === 'fetch_expenses') {
        try {
            $filter = $_GET['filter'] ?? 'all';
            $search = trim($_GET['search'] ?? '');
            
            $sql = "SELECT e.id, e.amount, e.description, e.status, e.attachment_path, e.created_at, e.approved_at,
                           ec.category_name,
                           CONCAT(u.first_name, ' ', u.last_name) AS requester_name,
                           ba.account_name,
                           CONCAT(app.first_name, ' ', app.last_name) AS approver_name
                    FROM expenses e
                    LEFT JOIN expense_categories ec ON e.category_id = ec.id
                    LEFT JOIN bank_accounts ba ON e.account_id = ba.id
                    LEFT JOIN users u ON e.requested_by_user_id = u.id
                    LEFT JOIN users app ON e.approved_by_user_id = app.id
                    WHERE 1=1";
            
            $params = [];
            
            // Only show non-deleted records
            $sql .= " AND (e.deleted_at IS NULL OR e.deleted_at = '0000-00-00 00:00:00')";
            
            if (!$is_global_admin && $branch_id > 0) {
                $sql .= " AND e.branch_id = :branch_id";
                $params[':branch_id'] = $branch_id;
            }
            
            if ($filter === 'pending') {
                $sql .= " AND e.status = 'pending'";
            } elseif ($filter === 'approved') {
                $sql .= " AND e.status = 'approved'";
            } elseif ($filter === 'rejected') {
                $sql .= " AND e.status = 'rejected'";
            }
            
            if (!empty($search)) {
                $sql .= " AND (e.description LIKE :search OR ec.category_name LIKE :search OR e.id LIKE :search)";
                $params[':search'] = '%' . $search . '%';
            }
            
            $sql .= " ORDER BY FIELD(e.status, 'pending') DESC, e.created_at DESC";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $expenses = $stmt->fetchAll(PDO::FETCH_ASSOC);
            jsonResponse(true, '', ['expenses' => $expenses]);
        } catch (PDOException $e) {
            error_log('fetch_expenses error: ' . $e->getMessage());
            jsonResponse(false, 'Database error: ' . $e->getMessage());
        }
    }

    // Fetch HUD summary
    if ($action === 'fetch_summary') {
        try {
            // Petty cash balance
            $bal_sql = "SELECT COALESCE(SUM(current_balance), 0) AS petty_balance
                        FROM bank_accounts
                        WHERE account_type = 'petty_cash' AND status = 'active'";
            $params = [];
            if (!$is_global_admin && $branch_id > 0) {
                $bal_sql .= " AND branch_id = :branch_id";
                $params[':branch_id'] = $branch_id;
            }
            $bal_stmt = $pdo->prepare($bal_sql);
            $bal_stmt->execute($params);
            $petty_balance = (float)$bal_stmt->fetchColumn();

            // Total bank accounts balance
            $bank_sql = "SELECT COALESCE(SUM(current_balance), 0) AS bank_balance
                        FROM bank_accounts
                        WHERE account_type = 'bank' AND status = 'active'";
            $params = [];
            if (!$is_global_admin && $branch_id > 0) {
                $bank_sql .= " AND branch_id = :branch_id";
                $params[':branch_id'] = $branch_id;
            }
            $bank_stmt = $pdo->prepare($bank_sql);
            $bank_stmt->execute($params);
            $bank_balance = (float)$bank_stmt->fetchColumn();

            // Pending expenses count
            $pend_sql = "SELECT COUNT(*) FROM expenses WHERE status = 'pending' AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')";
            $params = [];
            if (!$is_global_admin && $branch_id > 0) {
                $pend_sql .= " AND branch_id = :branch_id";
                $params[':branch_id'] = $branch_id;
            }
            $pend_stmt = $pdo->prepare($pend_sql);
            $pend_stmt->execute($params);
            $pending_count = (int)$pend_stmt->fetchColumn();

            // Approved today sum
            $today_sql = "SELECT COALESCE(SUM(amount), 0) FROM expenses
                          WHERE status = 'approved' AND DATE(approved_at) = CURDATE() 
                          AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')";
            $params = [];
            if (!$is_global_admin && $branch_id > 0) {
                $today_sql .= " AND branch_id = :branch_id";
                $params[':branch_id'] = $branch_id;
            }
            $today_stmt = $pdo->prepare($today_sql);
            $today_stmt->execute($params);
            $approved_today = (float)$today_stmt->fetchColumn();

            // This month total
            $month_sql = "SELECT COALESCE(SUM(amount), 0) FROM expenses
                          WHERE status = 'approved' AND MONTH(approved_at) = MONTH(CURDATE()) 
                          AND YEAR(approved_at) = YEAR(CURDATE()) 
                          AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')";
            $params = [];
            if (!$is_global_admin && $branch_id > 0) {
                $month_sql .= " AND branch_id = :branch_id";
                $params[':branch_id'] = $branch_id;
            }
            $month_stmt = $pdo->prepare($month_sql);
            $month_stmt->execute($params);
            $month_total = (float)$month_stmt->fetchColumn();

            jsonResponse(true, '', [
                'petty_balance' => $petty_balance,
                'bank_balance' => $bank_balance,
                'pending_count' => $pending_count,
                'approved_today' => $approved_today,
                'month_total' => $month_total
            ]);
        } catch (PDOException $e) {
            error_log('fetch_summary error: ' . $e->getMessage());
            jsonResponse(false, 'Database error: ' . $e->getMessage());
        }
    }

    // Fetch single expense details
    if ($action === 'fetch_expense_details') {
        $expense_id = (int)($_GET['expense_id'] ?? 0);
        if ($expense_id <= 0) jsonResponse(false, 'Invalid expense ID');
        try {
            $sql = "SELECT e.*, ec.category_name, ba.account_name,
                           CONCAT(req.first_name, ' ', req.last_name) AS requester_name,
                           CONCAT(app.first_name, ' ', app.last_name) AS approver_name
                    FROM expenses e
                    LEFT JOIN expense_categories ec ON e.category_id = ec.id
                    LEFT JOIN bank_accounts ba ON e.account_id = ba.id
                    LEFT JOIN users req ON e.requested_by_user_id = req.id
                    LEFT JOIN users app ON e.approved_by_user_id = app.id
                    WHERE e.id = :id 
                    AND (e.deleted_at IS NULL OR e.deleted_at = '0000-00-00 00:00:00')";
            $params = [':id' => $expense_id];
            if (!$is_global_admin && $branch_id > 0) {
                $sql .= " AND e.branch_id = :branch_id";
                $params[':branch_id'] = $branch_id;
            }
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $expense = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$expense) jsonResponse(false, 'Expense not found');
            jsonResponse(true, '', ['expense' => $expense]);
        } catch (PDOException $e) {
            error_log('fetch_expense_details error: ' . $e->getMessage());
            jsonResponse(false, 'Database error: ' . $e->getMessage());
        }
    }

    // Fetch budget data
    if ($action === 'fetch_budgets') {
        try {
            $year = (int)($_GET['year'] ?? date('Y'));
            $month = (int)($_GET['month'] ?? date('n'));
            
            $sql = "SELECT eb.*, ec.category_name
                    FROM expense_budgets eb
                    JOIN expense_categories ec ON eb.category_id = ec.id
                    WHERE eb.budget_year = :year AND eb.budget_month = :month
                    AND ec.status = 'active'";
            $params = [':year' => $year, ':month' => $month];
            if (!$is_global_admin && $branch_id > 0) {
                $sql .= " AND eb.branch_id = :branch_id";
                $params[':branch_id'] = $branch_id;
            }
            $sql .= " ORDER BY ec.category_name";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $budgets = $stmt->fetchAll(PDO::FETCH_ASSOC);
            jsonResponse(true, '', ['budgets' => $budgets]);
        } catch (PDOException $e) {
            error_log('fetch_budgets error: ' . $e->getMessage());
            jsonResponse(false, 'Database error: ' . $e->getMessage());
        }
    }

    // Fetch expense analytics
    if ($action === 'fetch_analytics') {
        try {
            $period = $_GET['period'] ?? 'month';
            
            $sql = "SELECT 
                        ec.category_name,
                        COALESCE(SUM(e.amount), 0) AS total_spent,
                        COUNT(e.id) AS expense_count,
                        COALESCE(AVG(e.amount), 0) AS avg_amount
                    FROM expense_categories ec
                    LEFT JOIN expenses e ON ec.id = e.category_id 
                        AND e.status = 'approved' 
                        AND (e.deleted_at IS NULL OR e.deleted_at = '0000-00-00 00:00:00')";
            
            if ($period === 'month') {
                $sql .= " AND MONTH(e.approved_at) = MONTH(CURDATE()) AND YEAR(e.approved_at) = YEAR(CURDATE())";
            } elseif ($period === 'quarter') {
                $sql .= " AND QUARTER(e.approved_at) = QUARTER(CURDATE()) AND YEAR(e.approved_at) = YEAR(CURDATE())";
            } elseif ($period === 'year') {
                $sql .= " AND YEAR(e.approved_at) = YEAR(CURDATE())";
            }
            
            $params = [];
            if (!$is_global_admin && $branch_id > 0) {
                $sql .= " AND e.branch_id = :branch_id";
                $params[':branch_id'] = $branch_id;
            }
            
            $sql .= " GROUP BY ec.id ORDER BY total_spent DESC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $analytics = $stmt->fetchAll(PDO::FETCH_ASSOC);
            jsonResponse(true, '', ['analytics' => $analytics]);
        } catch (PDOException $e) {
            error_log('fetch_analytics error: ' . $e->getMessage());
            jsonResponse(false, 'Database error: ' . $e->getMessage());
        }
    }

    jsonResponse(false, 'Unknown action');
    exit;
}

// POST AJAX actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    $incoming_token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($csrf_token, $incoming_token)) {
        jsonResponse(false, 'Security token mismatch');
    }
    $action = $_POST['action'] ?? '';

    // Log new expense
    if ($action === 'log_expense') {
        if (!$can_view) jsonResponse(false, 'Permission denied');

        $category_id = (int)($_POST['category_id'] ?? 0);
        $account_id  = (int)($_POST['account_id'] ?? 0);
        $amount      = (float)($_POST['amount'] ?? 0);
        $description = trim($_POST['description'] ?? '');
        $expense_date = trim($_POST['expense_date'] ?? date('Y-m-d'));

        if ($category_id <= 0 || $account_id <= 0 || $amount <= 0 || empty($description)) {
            jsonResponse(false, 'Please fill all required fields (category, account, amount, description).');
        }

        // Handle file upload
        $attachment_path = null;
        if (isset($_FILES['receipt']) && $_FILES['receipt']['error'] !== UPLOAD_ERR_NO_FILE) {
            $file = $_FILES['receipt'];
            $allowed = ['jpg', 'jpeg', 'png', 'pdf', 'doc', 'docx'];
            $max_size = 5 * 1024 * 1024; // 5MB
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, $allowed)) jsonResponse(false, 'Invalid file type. Allowed: jpg, jpeg, png, pdf, doc, docx');
            if ($file['size'] > $max_size) jsonResponse(false, 'File too large. Max 5MB');
            $safe_name = 'receipt_' . date('Ymd_His') . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
            $upload_dir = __DIR__ . '/../../uploads/receipts/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
            $upload_path = $upload_dir . $safe_name;
            if (move_uploaded_file($file['tmp_name'], $upload_path)) {
                $attachment_path = 'uploads/receipts/' . $safe_name;
            } else {
                jsonResponse(false, 'Failed to upload file. Check directory permissions.');
            }
        }

        try {
            $pdo->beginTransaction();
            
            $sql = "INSERT INTO expenses
                    (branch_id, category_id, account_id, amount, description, attachment_path, status, requested_by_user_id, created_at)
                    VALUES (:branch_id, :cat_id, :acc_id, :amount, :desc, :attachment, 'pending', :user_id, :created_at)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':branch_id' => $branch_id,
                ':cat_id' => $category_id,
                ':acc_id' => $account_id,
                ':amount' => $amount,
                ':desc' => $description,
                ':attachment' => $attachment_path,
                ':user_id' => $user_id,
                ':created_at' => $expense_date . ' ' . date('H:i:s')
            ]);
            
            // Update budget spent amount
            $month = date('n', strtotime($expense_date));
            $year = date('Y', strtotime($expense_date));
            $update_budget = $pdo->prepare("UPDATE expense_budgets 
                                           SET spent_amount = spent_amount + :amount, updated_at = NOW() 
                                           WHERE branch_id = :branch_id AND category_id = :cat_id 
                                           AND budget_year = :year AND budget_month = :month");
            $update_budget->execute([
                ':amount' => $amount,
                ':branch_id' => $branch_id,
                ':cat_id' => $category_id,
                ':year' => $year,
                ':month' => $month
            ]);
            
            $pdo->commit();
            jsonResponse(true, 'Expense logged successfully and pending approval.');
        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log('Expense log error: ' . $e->getMessage());
            jsonResponse(false, 'Database error: ' . $e->getMessage());
        }
    }

    // Approve expense (with balance check and debit)
    if ($action === 'approve_expense') {
        if (!$can_approve) jsonResponse(false, 'Permission denied. Only managers can approve.');
        $expense_id = (int)($_POST['expense_id'] ?? 0);
        if ($expense_id <= 0) jsonResponse(false, 'Invalid expense ID');

        try {
            $pdo->beginTransaction();

            $fetch = $pdo->prepare("SELECT e.id, e.amount, e.account_id, e.status, e.category_id, ba.current_balance, ba.id AS bank_id
                                    FROM expenses e
                                    JOIN bank_accounts ba ON e.account_id = ba.id
                                    WHERE e.id = :id AND e.branch_id = :branch_id AND e.status = 'pending' 
                                    AND (e.deleted_at IS NULL OR e.deleted_at = '0000-00-00 00:00:00')");
            $fetch->execute([':id' => $expense_id, ':branch_id' => $branch_id]);
            $exp = $fetch->fetch(PDO::FETCH_ASSOC);
            if (!$exp) {
                $pdo->rollBack();
                jsonResponse(false, 'Expense not found or already processed.');
            }
            if ($exp['current_balance'] < $exp['amount']) {
                $pdo->rollBack();
                jsonResponse(false, 'Insufficient balance in the selected account to cover this expense.');
            }

            $update = $pdo->prepare("UPDATE expenses SET status = 'approved', approved_by_user_id = :approver, approved_at = NOW()
                                     WHERE id = :id");
            $update->execute([':approver' => $user_id, ':id' => $expense_id]);

            $debit = $pdo->prepare("UPDATE bank_accounts SET current_balance = current_balance - :amount, updated_at = NOW()
                                    WHERE id = :account_id");
            $debit->execute([':amount' => $exp['amount'], ':account_id' => $exp['account_id']]);

            $pdo->commit();
            jsonResponse(true, 'Expense approved and account debited successfully.');
        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log('Approval error: ' . $e->getMessage());
            jsonResponse(false, 'Database error: ' . $e->getMessage());
        }
    }

    // Reject expense
    if ($action === 'reject_expense') {
        if (!$can_approve) jsonResponse(false, 'Permission denied.');
        $expense_id = (int)($_POST['expense_id'] ?? 0);
        if ($expense_id <= 0) jsonResponse(false, 'Invalid expense ID');

        try {
            $pdo->beginTransaction();
            
            // Get expense details to revert budget
            $fetch = $pdo->prepare("SELECT category_id, amount, DATE(created_at) as expense_date 
                                   FROM expenses 
                                   WHERE id = :id AND branch_id = :branch_id AND status = 'pending' 
                                   AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')");
            $fetch->execute([':id' => $expense_id, ':branch_id' => $branch_id]);
            $exp = $fetch->fetch(PDO::FETCH_ASSOC);
            
            $update = $pdo->prepare("UPDATE expenses SET status = 'rejected', approved_by_user_id = :approver, approved_at = NOW()
                                     WHERE id = :id AND branch_id = :branch_id AND status = 'pending'");
            $update->execute([':approver' => $user_id, ':id' => $expense_id, ':branch_id' => $branch_id]);
            
            if ($update->rowCount() > 0 && $exp) {
                // Revert budget spent amount
                $month = date('n', strtotime($exp['expense_date']));
                $year = date('Y', strtotime($exp['expense_date']));
                $update_budget = $pdo->prepare("UPDATE expense_budgets 
                                               SET spent_amount = spent_amount - :amount, updated_at = NOW() 
                                               WHERE branch_id = :branch_id AND category_id = :cat_id 
                                               AND budget_year = :year AND budget_month = :month");
                $update_budget->execute([
                    ':amount' => $exp['amount'],
                    ':branch_id' => $branch_id,
                    ':cat_id' => $exp['category_id'],
                    ':year' => $year,
                    ':month' => $month
                ]);
                
                $pdo->commit();
                jsonResponse(true, 'Expense rejected.');
            } else {
                $pdo->rollBack();
                jsonResponse(false, 'Expense not found or already processed.');
            }
        } catch (PDOException $e) {
            $pdo->rollBack();
            jsonResponse(false, 'Database error: ' . $e->getMessage());
        }
    }

    // Delete expense (soft delete)
    if ($action === 'delete_expense') {
        if (!$can_approve) jsonResponse(false, 'Permission denied.');
        $expense_id = (int)($_POST['expense_id'] ?? 0);
        if ($expense_id <= 0) jsonResponse(false, 'Invalid expense ID');

        try {
            // Only allow deletion of pending expenses
            $update = $pdo->prepare("UPDATE expenses SET deleted_at = NOW()
                                     WHERE id = :id AND branch_id = :branch_id AND status = 'pending'");
            $update->execute([':id' => $expense_id, ':branch_id' => $branch_id]);
            if ($update->rowCount() > 0) {
                jsonResponse(true, 'Expense deleted successfully.');
            } else {
                jsonResponse(false, 'Expense not found or cannot be deleted.');
            }
        } catch (PDOException $e) {
            jsonResponse(false, 'Database error: ' . $e->getMessage());
        }
    }

    // Update budget allocation
    if ($action === 'update_budget') {
        if (!$can_manage_budget) jsonResponse(false, 'Permission denied.');
        
        $budget_id = (int)($_POST['budget_id'] ?? 0);
        $allocated_amount = (float)($_POST['allocated_amount'] ?? 0);
        $year = (int)($_POST['year'] ?? date('Y'));
        $month = (int)($_POST['month'] ?? date('n'));
        
        if ($budget_id <= 0 || $allocated_amount < 0) {
            jsonResponse(false, 'Invalid parameters');
        }
        
        try {
            $update = $pdo->prepare("UPDATE expense_budgets 
                                    SET allocated_amount = :amount, updated_by_user_id = :user_id, updated_at = NOW() 
                                    WHERE id = :id");
            $update->execute([':amount' => $allocated_amount, ':user_id' => $user_id, ':id' => $budget_id]);
            if ($update->rowCount() > 0) {
                jsonResponse(true, 'Budget updated successfully.');
            } else {
                jsonResponse(false, 'Budget not found.');
            }
        } catch (PDOException $e) {
            jsonResponse(false, 'Database error: ' . $e->getMessage());
        }
    }

    jsonResponse(false, 'Unsupported action');
    exit;
}

// ----------------------------------------------------------------------
// 4. INITIAL PAGE DATA (for dropdowns)
// ----------------------------------------------------------------------
$current_date = date('l, F j, Y');
$current_time = date('g:i A');
$current_month = date('n');
$current_year = date('Y');

$categories = [];
$accounts = [];
$budgets = [];

try {
    $cat_sql = "SELECT id, category_name FROM expense_categories WHERE status = 'active' ORDER BY category_name";
    $categories = $pdo->query($cat_sql)->fetchAll(PDO::FETCH_ASSOC);

    $acc_sql = "SELECT id, account_name, account_type, current_balance FROM bank_accounts WHERE status = 'active'";
    $params = [];
    if (!$is_global_admin && $branch_id > 0) {
        $acc_sql .= " AND branch_id = :branch_id";
        $params[':branch_id'] = $branch_id;
    }
    $acc_sql .= " ORDER BY account_name";
    $acc_stmt = $pdo->prepare($acc_sql);
    $acc_stmt->execute($params);
    $accounts = $acc_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Fetch budgets for current month
    $budget_sql = "SELECT eb.*, ec.category_name 
                  FROM expense_budgets eb
                  JOIN expense_categories ec ON eb.category_id = ec.id
                  WHERE eb.budget_year = :year AND eb.budget_month = :month AND ec.status = 'active'";
    $params = [':year' => $current_year, ':month' => $current_month];
    if (!$is_global_admin && $branch_id > 0) {
        $budget_sql .= " AND eb.branch_id = :branch_id";
        $params[':branch_id'] = $branch_id;
    }
    $budget_stmt = $pdo->prepare($budget_sql);
    $budget_stmt->execute($params);
    $budgets = $budget_stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log('Initial data fetch error: ' . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Expense Tracker | HMS ERP</title>
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
        
        .stats-grid { display: grid; grid-template-columns: repeat(5, 1fr); gap: 16px; margin-bottom: 28px; }
        @media (max-width: 1200px) { .stats-grid { grid-template-columns: repeat(3, 1fr); } }
        @media (max-width: 768px) { .stats-grid { grid-template-columns: repeat(2, 1fr); } }
        @media (max-width: 576px) { .stats-grid { grid-template-columns: 1fr; } }
        
        .stat-card { background: white; border-radius: 16px; padding: 16px 20px; border: 1px solid var(--gray-light); }
        .stat-card.primary { border-left: 4px solid var(--primary); }
        .stat-card.warning { border-left: 4px solid var(--warning); }
        .stat-card.success { border-left: 4px solid var(--success); }
        .stat-card.danger { border-left: 4px solid var(--danger); }
        .stat-card.info { border-left: 4px solid var(--info); }
        .stat-value { font-size: 28px; font-weight: 800; color: var(--dark); }
        .stat-label { font-size: 12px; color: var(--gray); font-weight: 500; margin-top: 2px; }
        .stat-sub { font-size: 11px; color: var(--gray); margin-top: 2px; }
        
        .dashboard-grid { display: grid; grid-template-columns: 1fr 420px; gap: 24px; }
        @media (max-width: 1200px) { .dashboard-grid { grid-template-columns: 1fr; } }
        
        .card-modern { background: white; border-radius: 20px; border: 1px solid var(--gray-light); overflow: hidden; margin-bottom: 24px; }
        .card-header-custom { padding: 16px 20px; background: #f8fafc; border-bottom: 1px solid var(--gray-light); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; }
        .card-header-custom h3 { margin: 0; font-size: 16px; font-weight: 700; color: var(--dark); }
        
        .expense-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .expense-table th, .expense-table td { padding: 10px 10px; border-bottom: 1px solid #f0f2f5; vertical-align: middle; }
        .expense-table th { background: #f8fafc; font-weight: 600; font-size: 11px; color: var(--gray); text-transform: uppercase; letter-spacing: 0.3px; }
        .expense-table tr:hover { background: #f8fafc; cursor: pointer; }
        .expense-table tr.table-active { background: #e0e7ff; }
        .expense-table .text-end { text-align: right; }
        
        .status-badge { display: inline-block; padding: 3px 10px; border-radius: 20px; font-size: 10px; font-weight: 600; }
        .status-pending { background: #fef3c7; color: #d97706; }
        .status-approved { background: #d1fae5; color: #059669; }
        .status-rejected { background: #fee2e2; color: #dc2626; }
        
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
        .info-row { display: flex; margin-bottom: 10px; font-size: 13px; }
        .info-label { width: 130px; font-weight: 600; color: var(--gray); flex-shrink: 0; }
        .info-value { flex: 1; color: var(--dark); }
        
        .tab-buttons { display: flex; border-bottom: 1px solid var(--gray-light); margin-bottom: 16px; flex-wrap: wrap; }
        .tab-btn { background: none; border: none; padding: 10px 16px; cursor: pointer; font-weight: 500; font-size: 13px; color: var(--gray); transition: all 0.2s; }
        .tab-btn:hover { color: var(--dark); }
        .tab-btn.active { color: var(--primary); border-bottom: 2px solid var(--primary); }
        .tab-pane { display: none; padding: 0 4px; }
        .tab-pane.active { display: block; }
        
        .search-box { display: flex; gap: 8px; align-items: center; }
        .search-box input { width: 140px; }
        .filter-dropdown { min-width: 130px; }
        
        .budget-bar { height: 6px; background: #e9ecef; border-radius: 3px; overflow: hidden; margin-top: 4px; }
        .budget-bar .fill { height: 100%; border-radius: 3px; }
        .budget-bar .fill.danger { background: #dc2626; }
        .budget-bar .fill.warning { background: #d97706; }
        .budget-bar .fill.success { background: #059669; }
        
        .form-control { border-radius: 12px; border-color: var(--gray-light); padding: 10px 14px; }
        .form-control:focus { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(67,97,238,0.1); }
        .form-control-sm { padding: 6px 12px; font-size: 13px; }
        
        .modal-content { border-radius: 20px; border: none; }
        .modal-header { border-bottom: 1px solid var(--gray-light); padding: 20px 24px; }
        .modal-body { padding: 24px; }
        .modal-footer { border-top: 1px solid var(--gray-light); padding: 16px 24px; }
        
        .badge { display: inline-block; padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: 600; }
        .badge-pending { background: #fef3c7; color: #d97706; }
        .badge-approved { background: #d1fae5; color: #059669; }
        .badge-rejected { background: #fee2e2; color: #dc2626; }
        
        .text-success { color: #059669 !important; }
        .text-danger { color: #dc2626 !important; }
        .text-warning { color: #d97706 !important; }
        
        @media (max-width: 576px) {
            .content-page { padding: 12px; }
            .welcome-header { padding: 16px; }
            .expense-table { font-size: 11px; }
            .expense-table th, .expense-table td { padding: 6px 4px; }
            .info-row { flex-direction: column; }
            .info-label { width: 100%; }
        }
        
        .budget-scroll { max-height: 300px; overflow-y: auto; }
        .budget-scroll::-webkit-scrollbar { width: 4px; }
        .budget-scroll::-webkit-scrollbar-thumb { background: var(--gray-light); border-radius: 2px; }
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
                        <div class="welcome-title">Expense Tracker & Petty Cash Management 💸</div>
                        <div class="welcome-subtitle">Log operational expenses, track approvals, and audit cash movements.</div>
                        <div class="small mt-2"><i class="fas fa-sync-alt"></i> Real‑time updates every 15 seconds.</div>
                    </div>
                    <div class="col-md-5 text-md-end mt-3 mt-md-0">
                        <div class="time-badge"><i class="far fa-calendar-alt"></i> <?php echo escapeHtml($current_date); ?> <i class="far fa-clock ms-2"></i> <?php echo escapeHtml($current_time); ?></div>
                    </div>
                </div>
            </div>

            <!-- HUD Cards -->
            <div class="stats-grid" id="hudContainer">
                <div class="stat-card primary">
                    <div><div class="stat-value" id="pettyBalance">--</div><div class="stat-label">Petty Cash Balance (ETB)</div></div>
                </div>
                <div class="stat-card info">
                    <div><div class="stat-value" id="bankBalance">--</div><div class="stat-label">Bank Balance (ETB)</div></div>
                </div>
                <div class="stat-card warning">
                    <div><div class="stat-value" id="pendingCount">--</div><div class="stat-label">Pending Approval Claims</div></div>
                </div>
                <div class="stat-card success">
                    <div><div class="stat-value" id="approvedToday">--</div><div class="stat-label">Approved Today (ETB)</div></div>
                </div>
                <div class="stat-card danger">
                    <div><div class="stat-value" id="monthTotal">--</div><div class="stat-label">This Month (ETB)</div></div>
                </div>
            </div>

            <div class="dashboard-grid">
                <!-- LEFT PANEL: Expense Ledger -->
                <div class="card-modern">
                    <div class="card-header-custom">
                        <h3><i class="fas fa-receipt me-2"></i> Expense Ledger</h3>
                        <div class="d-flex gap-2 align-items-center flex-wrap">
                            <div class="search-box">
                                <input type="text" id="expenseSearch" class="form-control form-control-sm" placeholder="Search..." style="width:120px;">
                                <button class="btn btn-sm btn-outline" onclick="searchExpenses()"><i class="fas fa-search"></i></button>
                            </div>
                            <select id="filterDropdown" class="form-select form-select-sm filter-dropdown" onchange="refreshExpenseTable()">
                                <option value="all">All</option>
                                <option value="pending">Pending</option>
                                <option value="approved">Approved</option>
                                <option value="rejected">Rejected</option>
                            </select>
                            <button class="action-btn action-btn-outline action-btn-sm" onclick="refreshExpenseTable()"><i class="fas fa-sync-alt"></i></button>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="expense-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Category</th>
                                    <th>Description</th>
                                    <th class="text-end">Amount</th>
                                    <th>Receipt</th>
                                    <th>Requester</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody id="expenseTableBody">
                                <tr><td colspan="7" class="empty-state">Loading...</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- RIGHT PANEL: Expense Action Desk -->
                <div class="card-modern">
                    <div class="card-header-custom">
                        <h3><i class="fas fa-tasks me-2"></i> Action Workspace</h3>
                    </div>
                    <div class="p-3">
                        <div class="tab-buttons">
                            <button class="tab-btn active" data-tab="log"><i class="fas fa-plus"></i> Log</button>
                            <button class="tab-btn" data-tab="review"><i class="fas fa-eye"></i> Review</button>
                            <button class="tab-btn" data-tab="budget"><i class="fas fa-chart-pie"></i> Budget</button>
                        </div>

                        <!-- Tab 1: Log Expense -->
                        <div id="logTab" class="tab-pane active">
                            <form id="expenseForm" enctype="multipart/form-data">
                                <input type="hidden" name="csrf_token" id="csrfToken" value="<?php echo escapeHtml($csrf_token); ?>">
                                <div class="mb-2">
                                    <label class="form-label">Category *</label>
                                    <select name="category_id" id="categoryId" class="form-control form-control-sm" required>
                                        <option value="">-- Select --</option>
                                        <?php foreach ($categories as $cat): ?>
                                            <option value="<?php echo $cat['id']; ?>"><?php echo escapeHtml($cat['category_name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="mb-2">
                                    <label class="form-label">Account (Petty Cash/Bank) *</label>
                                    <select name="account_id" id="accountId" class="form-control form-control-sm" required>
                                        <option value="">-- Select --</option>
                                        <?php foreach ($accounts as $acc): ?>
                                            <option value="<?php echo $acc['id']; ?>"><?php echo escapeHtml($acc['account_name']); ?> (<?php echo formatMoney($acc['current_balance']); ?> ETB)</option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="row">
                                    <div class="col-md-6 mb-2">
                                        <label class="form-label">Amount (ETB) *</label>
                                        <input type="number" step="0.01" name="amount" id="amount" class="form-control form-control-sm" required>
                                    </div>
                                    <div class="col-md-6 mb-2">
                                        <label class="form-label">Expense Date *</label>
                                        <input type="date" name="expense_date" id="expenseDate" class="form-control form-control-sm" value="<?php echo date('Y-m-d'); ?>" required>
                                    </div>
                                </div>
                                <div class="mb-2">
                                    <label class="form-label">Description *</label>
                                    <textarea name="description" id="description" rows="2" class="form-control form-control-sm" required placeholder="Brief description of the expense..."></textarea>
                                </div>
                                <div class="mb-2">
                                    <label class="form-label">Receipt Attachment (jpg, png, pdf, doc, max 5MB)</label>
                                    <input type="file" name="receipt" id="receipt" class="form-control form-control-sm" accept=".jpg,.jpeg,.png,.pdf,.doc,.docx">
                                </div>
                                <button type="submit" class="action-btn action-btn-primary w-100">Log Expense & Submit for Approval</button>
                            </form>
                        </div>

                        <!-- Tab 2: Review Voucher Details -->
                        <div id="reviewTab" class="tab-pane">
                            <div id="voucherDetails">
                                <div class="empty-state">Select an expense row from the ledger to review details.</div>
                            </div>
                        </div>

                        <!-- Tab 3: Budget Management -->
                        <div id="budgetTab" class="tab-pane">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <h6 class="mb-0">Budget Allocation <?php echo date('F Y'); ?></h6>
                                <div>
                                    <select id="budgetMonth" class="form-select form-select-sm" style="width:100px;display:inline-block;" onchange="loadBudgets()">
                                        <?php for ($m = 1; $m <= 12; $m++): ?>
                                            <option value="<?php echo $m; ?>" <?php echo $m == $current_month ? 'selected' : ''; ?>>
                                                <?php echo date('M', mktime(0,0,0,$m,1)); ?>
                                            </option>
                                        <?php endfor; ?>
                                    </select>
                                    <select id="budgetYear" class="form-select form-select-sm" style="width:90px;display:inline-block;" onchange="loadBudgets()">
                                        <?php for ($y = $current_year - 2; $y <= $current_year + 1; $y++): ?>
                                            <option value="<?php echo $y; ?>" <?php echo $y == $current_year ? 'selected' : ''; ?>>
                                                <?php echo $y; ?>
                                            </option>
                                        <?php endfor; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="budget-scroll" id="budgetContainer">
                                <?php if (count($budgets) > 0): ?>
                                    <?php foreach ($budgets as $budget): 
                                        $used_pct = $budget['allocated_amount'] > 0 ? round(($budget['spent_amount'] / $budget['allocated_amount']) * 100) : 0;
                                        $bar_class = $used_pct >= 90 ? 'danger' : ($used_pct >= 70 ? 'warning' : 'success');
                                    ?>
                                        <div class="d-flex justify-content-between align-items-center mb-2">
                                            <span style="font-size:12px;"><?php echo escapeHtml($budget['category_name']); ?></span>
                                            <span style="font-size:12px;white-space:nowrap;">
                                                <?php echo formatMoney($budget['spent_amount']); ?> / <?php echo formatMoney($budget['allocated_amount']); ?>
                                                <span class="badge <?php echo $bar_class === 'danger' ? 'bg-danger' : ($bar_class === 'warning' ? 'bg-warning' : 'bg-success'); ?>" style="font-size:9px;">
                                                    <?php echo $used_pct; ?>%
                                                </span>
                                            </span>
                                        </div>
                                        <div class="budget-bar">
                                            <div class="fill <?php echo $bar_class; ?>" style="width:<?php echo min($used_pct, 100); ?>%;"></div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="empty-state">No budget data available.</div>
                                <?php endif; ?>
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

<script>
let currentSelectedExpenseId = null;
let currentExpenseData = null;
const canApprove = <?php echo $can_approve ? 'true' : 'false'; ?>;
const canManageBudget = <?php echo $can_manage_budget ? 'true' : 'false'; ?>;
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
        'pending': 'status-pending',
        'approved': 'status-approved',
        'rejected': 'status-rejected'
    };
    const labels = {
        'pending': 'Pending Approval',
        'approved': 'Approved',
        'rejected': 'Rejected'
    };
    return `<span class="status-badge ${classes[status] || ''}">${labels[status] || status}</span>`;
}

function refreshHUD() {
    $.ajax({
        url: window.location.pathname + '?ajax=expense_action&action=fetch_summary&csrf_token=' + encodeURIComponent($('#csrfToken').val()),
        method: 'GET',
        success: function(res) {
            if (res.success) {
                $('#pettyBalance').text(formatMoney(res.petty_balance));
                $('#bankBalance').text(formatMoney(res.bank_balance));
                $('#pendingCount').text(res.pending_count);
                $('#approvedToday').text(formatMoney(res.approved_today));
                $('#monthTotal').text(formatMoney(res.month_total));
            } else {
                console.error('HUD error:', res.message);
            }
        },
        error: function() { console.error('HUD refresh failed'); }
    });
}

function refreshExpenseTable() {
    const filter = $('#filterDropdown').val() || 'all';
    const search = $('#expenseSearch').val() || '';
    
    showLoading(true);
    $.ajax({
        url: window.location.pathname + '?ajax=expense_action&action=fetch_expenses&filter=' + filter + '&search=' + encodeURIComponent(search) + '&csrf_token=' + encodeURIComponent($('#csrfToken').val()),
        method: 'GET',
        success: function(res) {
            showLoading(false);
            if (res.success && res.expenses) {
                const tbody = $('#expenseTableBody');
                tbody.empty();
                if (res.expenses.length === 0) {
                    tbody.html('<tr><td colspan="7" class="empty-state">No expenses found.</td></tr>');
                } else {
                    res.expenses.forEach(exp => {
                        let attachmentHtml = '—';
                        if (exp.attachment_path) {
                            attachmentHtml = `<a href="../../${exp.attachment_path}" target="_blank" class="text-primary"><i class="fas fa-paperclip"></i></a>`;
                        }
                        const row = $(`
                            <tr data-expense-id="${exp.id}">
                                <td>${exp.id}</td>
                                <td>${escapeHtml(exp.category_name)}</td>
                                <td>${escapeHtml(exp.description.substring(0, 50))}${exp.description.length > 50 ? '…' : ''}</td>
                                <td class="text-end">${formatMoney(exp.amount)}</td>
                                <td>${attachmentHtml}</td>
                                <td>${escapeHtml(exp.requester_name || 'Unknown')}</td>
                                <td>${getStatusBadge(exp.status)}</td>
                            </tr>
                        `);
                        row.on('click', function() {
                            $('.expense-table tr').removeClass('table-active');
                            $(this).addClass('table-active');
                            currentSelectedExpenseId = exp.id;
                            loadExpenseDetails(exp.id);
                            $('.tab-btn').removeClass('active');
                            $('.tab-pane').removeClass('active');
                            $('.tab-btn[data-tab="review"]').addClass('active');
                            $('#reviewTab').addClass('active');
                        });
                        tbody.append(row);
                    });
                }
            } else {
                showToast('Failed to load expenses: ' + (res.message || 'Unknown error'), 'error');
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

function searchExpenses() {
    refreshExpenseTable();
}

function loadExpenseDetails(expenseId) {
    showLoading(true);
    $.ajax({
        url: window.location.pathname + '?ajax=expense_action&action=fetch_expense_details&expense_id=' + expenseId + '&csrf_token=' + encodeURIComponent($('#csrfToken').val()),
        method: 'GET',
        success: function(res) {
            showLoading(false);
            if (res.success && res.expense) {
                currentExpenseData = res.expense;
                renderExpenseDetails(res.expense);
            } else {
                $('#voucherDetails').html('<div class="empty-state">Failed to load details: ' + (res.message || '') + '</div>');
            }
        },
        error: function() { 
            showLoading(false); 
            $('#voucherDetails').html('<div class="empty-state">Network error.</div>'); 
        }
    });
}

function renderExpenseDetails(exp) {
    const statusBadge = getStatusBadge(exp.status);
    const attachmentHtml = exp.attachment_path ? 
        `<a href="../../${exp.attachment_path}" target="_blank" class="btn btn-sm btn-outline-primary"><i class="fas fa-download"></i> View Receipt</a>` : 
        'No attachment';
    
    const canAct = (canApprove && exp.status === 'pending');
    const canDelete = (canApprove && exp.status === 'pending');
    
    const actionButtons = canAct ? `
        <div class="mt-3 d-flex gap-2 flex-wrap">
            <button class="action-btn action-btn-success" id="approveBtn"><i class="fas fa-check"></i> Authorize & Debit</button>
            <button class="action-btn action-btn-danger" id="rejectBtn"><i class="fas fa-times"></i> Reject</button>
            ${canDelete ? `<button class="action-btn action-btn-outline" id="deleteBtn"><i class="fas fa-trash"></i> Delete</button>` : ''}
        </div>
    ` : (exp.status === 'approved' ? `
        <div class="mt-3">
            <span class="badge badge-approved"><i class="fas fa-check-circle"></i> Approved on ${new Date(exp.approved_at).toLocaleString()}</span>
            ${exp.approver_name ? ` by ${escapeHtml(exp.approver_name)}` : ''}
        </div>
    ` : (exp.status === 'rejected' ? `
        <div class="mt-3">
            <span class="badge badge-rejected"><i class="fas fa-times-circle"></i> Rejected on ${new Date(exp.approved_at).toLocaleString()}</span>
            ${exp.approver_name ? ` by ${escapeHtml(exp.approver_name)}` : ''}
        </div>
    ` : ''));
    
    const html = `
        <div class="detail-section">
            <div class="info-row"><div class="info-label">Voucher ID:</div><div class="info-value"><strong>#${exp.id}</strong></div></div>
            <div class="info-row"><div class="info-label">Category:</div><div class="info-value">${escapeHtml(exp.category_name)}</div></div>
            <div class="info-row"><div class="info-label">Account:</div><div class="info-value">${escapeHtml(exp.account_name)}</div></div>
            <div class="info-row"><div class="info-label">Amount:</div><div class="info-value"><strong>${formatMoney(exp.amount)} ETB</strong></div></div>
            <div class="info-row"><div class="info-label">Description:</div><div class="info-value">${escapeHtml(exp.description)}</div></div>
            <div class="info-row"><div class="info-label">Expense Date:</div><div class="info-value">${new Date(exp.created_at).toLocaleDateString()}</div></div>
            <div class="info-row"><div class="info-label">Requester:</div><div class="info-value">${escapeHtml(exp.requester_name || 'Unknown')}</div></div>
            <div class="info-row"><div class="info-label">Status:</div><div class="info-value">${statusBadge}</div></div>
            <div class="info-row"><div class="info-label">Attachment:</div><div class="info-value">${attachmentHtml}</div></div>
            ${actionButtons}
        </div>
    `;
    $('#voucherDetails').html(html);
    
    if (canAct) {
        $('#approveBtn').on('click', function() {
            Swal.fire({
                title: 'Approve Expense?',
                text: 'This will debit the selected account by ' + formatMoney(exp.amount) + ' ETB.',
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Yes, Approve',
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
                            action: 'approve_expense',
                            expense_id: currentSelectedExpenseId,
                            csrf_token: csrf
                        },
                        dataType: 'json',
                        success: function(res) {
                            showLoading(false);
                            if (res.success) {
                                showToast(res.message);
                                refreshExpenseTable();
                                refreshHUD();
                                loadExpenseDetails(currentSelectedExpenseId);
                            } else {
                                showToast(res.message || 'Approval failed', 'error');
                            }
                        },
                        error: function() { showLoading(false); showToast('Server error', 'error'); }
                    });
                }
            });
        });
        $('#rejectBtn').on('click', function() {
            Swal.fire({
                title: 'Reject Expense?',
                text: 'This will reject the expense request.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Yes, Reject',
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
                            action: 'reject_expense',
                            expense_id: currentSelectedExpenseId,
                            csrf_token: csrf
                        },
                        dataType: 'json',
                        success: function(res) {
                            showLoading(false);
                            if (res.success) {
                                showToast(res.message);
                                refreshExpenseTable();
                                refreshHUD();
                                loadExpenseDetails(currentSelectedExpenseId);
                            } else {
                                showToast(res.message || 'Rejection failed', 'error');
                            }
                        },
                        error: function() { showLoading(false); showToast('Server error', 'error'); }
                    });
                }
            });
        });
        if ($('#deleteBtn').length) {
            $('#deleteBtn').on('click', function() {
                Swal.fire({
                    title: 'Delete Expense?',
                    text: 'This will permanently delete the expense record.',
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
                                action: 'delete_expense',
                                expense_id: currentSelectedExpenseId,
                                csrf_token: csrf
                            },
                            dataType: 'json',
                            success: function(res) {
                                showLoading(false);
                                if (res.success) {
                                    showToast(res.message);
                                    refreshExpenseTable();
                                    refreshHUD();
                                    $('#voucherDetails').html('<div class="empty-state">Expense deleted.</div>');
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
}

function loadBudgets() {
    const month = $('#budgetMonth').val();
    const year = $('#budgetYear').val();
    
    if (!month || !year) return;
    
    showLoading(true);
    $.ajax({
        url: window.location.pathname + '?ajax=expense_action&action=fetch_budgets&year=' + year + '&month=' + month + '&csrf_token=' + encodeURIComponent($('#csrfToken').val()),
        method: 'GET',
        success: function(res) {
            showLoading(false);
            if (res.success && res.budgets) {
                const container = $('#budgetContainer');
                container.empty();
                if (res.budgets.length === 0) {
                    container.html('<div class="empty-state">No budget data available.</div>');
                } else {
                    res.budgets.forEach(budget => {
                        const used_pct = budget.allocated_amount > 0 ? Math.round((budget.spent_amount / budget.allocated_amount) * 100) : 0;
                        const bar_class = used_pct >= 90 ? 'danger' : (used_pct >= 70 ? 'warning' : 'success');
                        const pct_display = used_pct > 100 ? 100 : used_pct;
                        container.append(`
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <span style="font-size:12px;">${escapeHtml(budget.category_name)}</span>
                                <span style="font-size:12px;white-space:nowrap;">
                                    ${formatMoney(budget.spent_amount)} / ${formatMoney(budget.allocated_amount)}
                                    <span class="badge ${bar_class === 'danger' ? 'bg-danger' : (bar_class === 'warning' ? 'bg-warning' : 'bg-success')}" style="font-size:9px;">
                                        ${used_pct}%
                                    </span>
                                </span>
                            </div>
                            <div class="budget-bar">
                                <div class="fill ${bar_class}" style="width:${Math.min(pct_display, 100)}%;"></div>
                            </div>
                        `);
                    });
                }
            } else {
                showToast('Failed to load budgets', 'error');
            }
        },
        error: function() { showLoading(false); showToast('Network error', 'error'); }
    });
}

// Tab switching
$('.tab-btn').on('click', function() {
    const tab = $(this).data('tab');
    $('.tab-btn').removeClass('active');
    $(this).addClass('active');
    $('.tab-pane').removeClass('active');
    $('#' + tab + 'Tab').addClass('active');
    
    if (tab === 'review' && currentSelectedExpenseId) {
        loadExpenseDetails(currentSelectedExpenseId);
    }
    if (tab === 'budget') {
        loadBudgets();
    }
});

// Expense form submission
$('#expenseForm').on('submit', function(e) {
    e.preventDefault();
    const formData = new FormData(this);
    formData.append('action', 'log_expense');
    showLoading(true);
    $.ajax({
        url: window.location.pathname,
        method: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        dataType: 'json',
        success: function(res) {
            showLoading(false);
            if (res.success) {
                showToast(res.message);
                $('#expenseForm')[0].reset();
                $('#expenseDate').val(new Date().toISOString().split('T')[0]);
                refreshExpenseTable();
                refreshHUD();
                // Switch to review tab
                $('.tab-btn').removeClass('active');
                $('.tab-pane').removeClass('active');
                $('.tab-btn[data-tab="review"]').addClass('active');
                $('#reviewTab').addClass('active');
                // Load the newly created expense
                setTimeout(refreshExpenseTable, 500);
            } else {
                showToast(res.message || 'Failed to log expense', 'error');
            }
        },
        error: function() { showLoading(false); showToast('Server error', 'error'); }
    });
});

// Enter key on search
$('#expenseSearch').on('keypress', function(e) {
    if (e.which === 13) {
        searchExpenses();
    }
});

// Auto-refresh
refreshExpenseTable();
refreshHUD();
loadBudgets();

if (refreshTimer) clearInterval(refreshTimer);
refreshTimer = setInterval(() => {
    refreshExpenseTable();
    refreshHUD();
}, 15000);

$(document).ready(function() {
    // Auto-select first row if exists
    setTimeout(() => {
        const firstRow = $('.expense-table tbody tr:first');
        if (firstRow.length && !$('.expense-table tbody .empty-state').length) {
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