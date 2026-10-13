<?php
/**
 * user_permission_rules.php
 * Super Admin Module: User, Role & Dynamic Permissions Management
 * 
 * Strictly based on hms_erp.sql schema. Manages system users, role-based permissions,
 * and audit logs using the actual `users`, `role_permissions`, `system_permissions`
 * and `audit_logs` tables. Enhanced UI/UX with sidebar compatibility.
 * 
 * @package HMS-ERP
 * @version 2.4 (Fixed layout visibility when sidebar expanded)
 */

if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../../config/db_connection.php';
require_once __DIR__ . '/../../includes/auth_helper.php';

// ============================================
// STRICT SUPER ADMIN ACCESS CONTROL
// ============================================
if (!isset($_SESSION['user_id']) || ($_SESSION['user_type'] ?? '') !== 'super_admin') {
    header('Location: ../../index.php?error=unauthorized');
    exit;
}

$user_id   = (int) $_SESSION['user_id'];
$csrf_token = $_SESSION['csrf_token'] ?? bin2hex(random_bytes(32));
$_SESSION['csrf_token'] = $csrf_token;

$db = getDB();
if (!$db) {
    die('Database connection failed. Please check configuration.');
}

// Helper to get a default branch ID (used for audit logs when no specific branch context)
function getDefaultBranchId(PDO $pdo): int {
    static $defaultBranchId = null;
    if ($defaultBranchId === null) {
        $stmt = $pdo->query("SELECT id FROM branches WHERE status = 'active' AND deleted_at IS NULL ORDER BY id LIMIT 1");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $defaultBranchId = $row ? (int)$row['id'] : 1;
    }
    return $defaultBranchId;
}

// ============================================
// AJAX ROUTER (ALL SERVER-SIDE ACTIONS)
// ============================================
if (isset($_GET['action'])) {
    error_reporting(0);
    ini_set('display_errors', 0);
    if (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');

    $action = $_GET['action'];

    // ---------- GET USERS (DataTables Server-Side) ----------
    if ($action === 'get_users') {
        try {
            $draw   = (int) ($_GET['draw'] ?? 1);
            $start  = max(0, (int) ($_GET['start'] ?? 0));
            $length = min(500, (int) ($_GET['length'] ?? 25));
            $search = trim($_GET['search']['value'] ?? '');

            $where = "u.deleted_at IS NULL";
            $params = [];

            if ($search !== '') {
                $where .= " AND (u.first_name LIKE :search OR u.last_name LIKE :search OR u.email LIKE :search OR u.username LIKE :search)";
                $params[':search'] = "%$search%";
            }

            $countSql = "SELECT COUNT(*) FROM users u WHERE $where";
            $countStmt = $db->prepare($countSql);
            $countStmt->execute($params);
            $totalRecords = (int) $countStmt->fetchColumn();

            $dataSql = "
                SELECT u.id, u.username, u.email, u.first_name, u.last_name, u.user_type,
                       u.is_active, u.last_login_at, u.created_at, u.branch_id, u.department_id,
                       b.name AS branch_name, d.name AS department_name
                FROM users u
                LEFT JOIN branches b ON u.branch_id = b.id
                LEFT JOIN departments d ON u.department_id = d.id
                WHERE $where
                ORDER BY u.created_at DESC
                LIMIT :limit OFFSET :offset
            ";
            $dataStmt = $db->prepare($dataSql);
            foreach ($params as $key => $val) {
                $dataStmt->bindValue($key, $val);
            }
            $dataStmt->bindValue(':limit', $length, PDO::PARAM_INT);
            $dataStmt->bindValue(':offset', $start, PDO::PARAM_INT);
            $dataStmt->execute();
            $users = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

            $data = [];
            foreach ($users as $user) {
                $fullName = trim($user['first_name'] . ' ' . $user['last_name']);
                $statusBadge = $user['is_active'] 
                    ? '<span class="badge badge-success">Active</span>'
                    : '<span class="badge badge-danger">Suspended</span>';
                $actionButtons = '
                    <div class="action-btns">
                        <button class="btn-edit-user btn-icon" data-id="' . $user['id'] . '" data-branch="' . $user['branch_id'] . '" data-dept="' . $user['department_id'] . '" data-status="' . $user['is_active'] . '" data-username="' . htmlspecialchars($user['username']) . '" data-email="' . htmlspecialchars($user['email']) . '" data-first="' . htmlspecialchars($user['first_name']) . '" data-last="' . htmlspecialchars($user['last_name']) . '" data-type="' . htmlspecialchars($user['user_type']) . '" title="Edit User"><i class="fas fa-edit"></i></button>
                        <button class="btn-toggle-status btn-icon ' . ($user['is_active'] ? 'btn-warning' : 'btn-success') . '" data-id="' . $user['id'] . '" data-status="' . $user['is_active'] . '" title="Toggle Status">
                            <i class="fas ' . ($user['is_active'] ? 'fa-ban' : 'fa-check-circle') . '"></i>
                        </button>
                        <button class="btn-reset-pwd btn-icon" data-id="' . $user['id'] . '" data-name="' . htmlspecialchars($fullName) . '" title="Reset Password"><i class="fas fa-key"></i></button>
                    </div>
                ';
                $data[] = [
                    'id'            => $user['id'],
                    'username'      => htmlspecialchars($user['username'] ?? 'N/A'),
                    'full_name'     => htmlspecialchars($fullName),
                    'email'         => htmlspecialchars($user['email'] ?? ''),
                    'user_type'     => htmlspecialchars(ucfirst($user['user_type'] ?? '')),
                    'branch'        => htmlspecialchars($user['branch_name'] ?? ''),
                    'department'    => htmlspecialchars($user['department_name'] ?? ''),
                    'status'        => $statusBadge,
                    'last_login'    => $user['last_login_at'] ? date('d M Y, H:i', strtotime($user['last_login_at'])) : 'Never',
                    'actions'       => $actionButtons,
                    'branch_id'     => $user['branch_id'],
                    'department_id' => $user['department_id'],
                    'is_active'     => $user['is_active']
                ];
            }

            echo json_encode([
                'draw'            => $draw,
                'recordsTotal'    => $totalRecords,
                'recordsFiltered' => $totalRecords,
                'data'            => $data
            ]);
        } catch (Exception $e) {
            echo json_encode(['error' => true, 'message' => $e->getMessage()]);
        }
        exit;
    }

    // ---------- SAVE USER (CREATE / UPDATE) ----------
    if ($action === 'save_user') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
            exit;
        }
        $submittedToken = $_POST['csrf_token'] ?? '';
        if (!hash_equals($csrf_token, $submittedToken)) {
            echo json_encode(['success' => false, 'message' => 'Security validation failed.']);
            exit;
        }

        $userId     = (int) ($_POST['user_id'] ?? 0);
        $username   = trim($_POST['username'] ?? '');
        $email      = trim($_POST['email'] ?? '');
        $first_name = trim($_POST['first_name'] ?? '');
        $last_name  = trim($_POST['last_name'] ?? '');
        $branch_id  = (int) ($_POST['branch_id'] ?? 0);
        $department_id = !empty($_POST['department_id']) ? (int) $_POST['department_id'] : null;
        $user_type  = trim($_POST['user_type'] ?? '');
        $password   = $_POST['password'] ?? '';
        $is_active  = isset($_POST['is_active']) ? (int) $_POST['is_active'] : 1;

        if (empty($username) || empty($email) || empty($first_name) || empty($last_name) || empty($user_type) || $branch_id <= 0) {
            echo json_encode(['success' => false, 'message' => 'All required fields must be filled.']);
            exit;
        }

        $checkSql = "SELECT id FROM users WHERE (username = :uname OR email = :email) AND id != :uid AND deleted_at IS NULL";
        $checkStmt = $db->prepare($checkSql);
        $checkStmt->execute([':uname' => $username, ':email' => $email, ':uid' => $userId]);
        if ($checkStmt->fetchColumn()) {
            echo json_encode(['success' => false, 'message' => 'Username or email already exists.']);
            exit;
        }

        $db->beginTransaction();
        try {
            if ($userId === 0) {
                if (empty($password)) {
                    echo json_encode(['success' => false, 'message' => 'Password is required for new users.']);
                    exit;
                }
                $passwordHash = password_hash($password, PASSWORD_BCRYPT);
                $insertStmt = $db->prepare("
                    INSERT INTO users 
                    (username, email, first_name, last_name, branch_id, department_id, user_type, password_hash, is_active, created_by_user_id, created_at, updated_at)
                    VALUES (:uname, :email, :fn, :ln, :bid, :did, :utype, :pwd, :active, :cby, NOW(), NOW())
                ");
                $insertStmt->execute([
                    ':uname' => $username,
                    ':email' => $email,
                    ':fn'    => $first_name,
                    ':ln'    => $last_name,
                    ':bid'   => $branch_id,
                    ':did'   => $department_id,
                    ':utype' => $user_type,
                    ':pwd'   => $passwordHash,
                    ':active'=> $is_active,
                    ':cby'   => $user_id
                ]);
                $newId = $db->lastInsertId();
                // Audit log – branch_id from the user's branch
                $logStmt = $db->prepare("INSERT INTO audit_logs (branch_id, user_id, action_type, target_table, record_id, ip_address, user_agent, created_at) VALUES (?, ?, 'INSERT', 'users', ?, ?, ?, NOW())");
                $logStmt->execute([$branch_id, $user_id, $newId, $_SERVER['REMOTE_ADDR'] ?? '', $_SERVER['HTTP_USER_AGENT'] ?? '']);
            } else {
                $updateSql = "UPDATE users SET username = :uname, email = :email, first_name = :fn, last_name = :ln, branch_id = :bid, department_id = :did, user_type = :utype, is_active = :active, updated_at = NOW() WHERE id = :uid";
                $params = [
                    ':uname' => $username,
                    ':email' => $email,
                    ':fn'    => $first_name,
                    ':ln'    => $last_name,
                    ':bid'   => $branch_id,
                    ':did'   => $department_id,
                    ':utype' => $user_type,
                    ':active'=> $is_active,
                    ':uid'   => $userId
                ];
                if (!empty($password)) {
                    $updateSql = "UPDATE users SET username = :uname, email = :email, first_name = :fn, last_name = :ln, branch_id = :bid, department_id = :did, user_type = :utype, is_active = :active, password_hash = :pwd, updated_at = NOW() WHERE id = :uid";
                    $params[':pwd'] = password_hash($password, PASSWORD_BCRYPT);
                }
                $updateStmt = $db->prepare($updateSql);
                $updateStmt->execute($params);
                // Audit log – branch_id from the user's branch
                $logStmt = $db->prepare("INSERT INTO audit_logs (branch_id, user_id, action_type, target_table, record_id, ip_address, user_agent, created_at) VALUES (?, ?, 'UPDATE', 'users', ?, ?, ?, NOW())");
                $logStmt->execute([$branch_id, $user_id, $userId, $_SERVER['REMOTE_ADDR'] ?? '', $_SERVER['HTTP_USER_AGENT'] ?? '']);
            }
            $db->commit();
            echo json_encode(['success' => true, 'message' => 'User saved successfully.']);
        } catch (Exception $e) {
            $db->rollBack();
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        }
        exit;
    }

    // ---------- TOGGLE USER STATUS (ACTIVE/SUSPEND) ----------
    if ($action === 'toggle_user_status') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['success' => false, 'message' => 'Invalid method']);
            exit;
        }
        $submittedToken = $_POST['csrf_token'] ?? '';
        if (!hash_equals($csrf_token, $submittedToken)) {
            echo json_encode(['success' => false, 'message' => 'CSRF validation failed']);
            exit;
        }
        $targetUserId = (int) ($_POST['user_id'] ?? 0);
        $currentStatus = (int) ($_POST['current_status'] ?? 0);
        $newStatus = $currentStatus ? 0 : 1;
        if ($targetUserId <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid user ID']);
            exit;
        }
        // Fetch user's branch_id for audit log
        $branchStmt = $db->prepare("SELECT branch_id FROM users WHERE id = :uid AND deleted_at IS NULL");
        $branchStmt->execute([':uid' => $targetUserId]);
        $userBranch = (int) $branchStmt->fetchColumn();
        if ($userBranch <= 0) {
            $userBranch = getDefaultBranchId($db);
        }

        $stmt = $db->prepare("UPDATE users SET is_active = :status, updated_at = NOW() WHERE id = :uid AND deleted_at IS NULL");
        $stmt->execute([':status' => $newStatus, ':uid' => $targetUserId]);

        $logStmt = $db->prepare("INSERT INTO audit_logs (branch_id, user_id, action_type, target_table, record_id, ip_address, user_agent, created_at) VALUES (?, ?, 'UPDATE', 'users', ?, ?, ?, NOW())");
        $logStmt->execute([$userBranch, $user_id, $targetUserId, $_SERVER['REMOTE_ADDR'] ?? '', $_SERVER['HTTP_USER_AGENT'] ?? '']);
        echo json_encode(['success' => true, 'message' => 'User status updated.', 'new_status' => $newStatus]);
        exit;
    }

    // ---------- GET ROLES (Distinct role_name from role_permissions) ----------
    if ($action === 'get_roles') {
        $stmt = $db->query("SELECT DISTINCT role_name FROM role_permissions WHERE deleted_at IS NULL ORDER BY role_name");
        $roles = $stmt->fetchAll(PDO::FETCH_COLUMN);
        echo json_encode(['success' => true, 'roles' => $roles]);
        exit;
    }

    // ---------- GET PERMISSIONS GROUPED BY MODULE ----------
    if ($action === 'get_permissions') {
        $stmt = $db->query("SELECT id, permission_key, permission_name, module_category FROM system_permissions WHERE deleted_at IS NULL ORDER BY module_category, permission_name");
        $permissions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $grouped = [];
        foreach ($permissions as $perm) {
            $module = $perm['module_category'] ?? 'general';
            if (empty($module)) $module = 'other';
            $grouped[$module][] = $perm;
        }
        echo json_encode(['success' => true, 'permissions_grouped' => $grouped]);
        exit;
    }

    // ---------- GET PERMISSIONS FOR A SPECIFIC ROLE ----------
    if ($action === 'get_role_permissions') {
        $roleName = trim($_GET['role_name'] ?? '');
        if (empty($roleName)) {
            echo json_encode(['success' => false, 'message' => 'Role name required']);
            exit;
        }
        $stmt = $db->prepare("SELECT permission_id FROM role_permissions WHERE role_name = :role AND deleted_at IS NULL");
        $stmt->execute([':role' => $roleName]);
        $assigned = $stmt->fetchAll(PDO::FETCH_COLUMN);
        echo json_encode(['success' => true, 'assigned_permissions' => $assigned]);
        exit;
    }

    // ---------- SAVE ROLE PERMISSIONS (Atomic Replace) ----------
    if ($action === 'save_role_permissions') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['success' => false, 'message' => 'Invalid method']);
            exit;
        }
        $submittedToken = $_POST['csrf_token'] ?? '';
        if (!hash_equals($csrf_token, $submittedToken)) {
            echo json_encode(['success' => false, 'message' => 'CSRF validation failed']);
            exit;
        }
        $roleName = trim($_POST['role_name'] ?? '');
        $permIds  = $_POST['permission_ids'] ?? [];
        if (!is_array($permIds)) $permIds = [];
        if (empty($roleName)) {
            echo json_encode(['success' => false, 'message' => 'Role name is required']);
            exit;
        }
        $db->beginTransaction();
        try {
            $delStmt = $db->prepare("DELETE FROM role_permissions WHERE role_name = :role AND deleted_at IS NULL");
            $delStmt->execute([':role' => $roleName]);

            $insertStmt = $db->prepare("INSERT INTO role_permissions (role_name, permission_id, granted_by_user_id, created_at) VALUES (:role, :pid, :gby, NOW())");
            foreach ($permIds as $pid) {
                $insertStmt->execute([':role' => $roleName, ':pid' => (int)$pid, ':gby' => $user_id]);
            }

            // Audit log – use default branch (global action)
            $defaultBranch = getDefaultBranchId($db);
            $logStmt = $db->prepare("INSERT INTO audit_logs (branch_id, user_id, action_type, target_table, record_id, ip_address, user_agent, created_at) VALUES (?, ?, 'UPDATE', 'role_permissions', 0, ?, ?, NOW())");
            $logStmt->execute([$defaultBranch, $user_id, $_SERVER['REMOTE_ADDR'] ?? '', $_SERVER['HTTP_USER_AGENT'] ?? '']);

            $db->commit();
            echo json_encode(['success' => true, 'message' => 'Permissions saved successfully.']);
        } catch (Exception $e) {
            $db->rollBack();
            echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        }
        exit;
    }

    // ---------- GET AUDIT LOGS (DataTables Server-Side) ----------
    if ($action === 'get_audit_logs') {
        try {
            $draw   = (int) ($_GET['draw'] ?? 1);
            $start  = max(0, (int) ($_GET['start'] ?? 0));
            $length = min(500, (int) ($_GET['length'] ?? 25));
            $search = trim($_GET['search']['value'] ?? '');

            $where = "1=1";
            $params = [];

            if ($search !== '') {
                $where .= " AND (CONCAT(u.first_name, ' ', u.last_name) LIKE :search OR al.action_type LIKE :search OR al.target_table LIKE :search OR al.ip_address LIKE :search)";
                $params[':search'] = "%$search%";
            }

            $countSql = "SELECT COUNT(*) FROM audit_logs al LEFT JOIN users u ON al.user_id = u.id WHERE $where";
            $countStmt = $db->prepare($countSql);
            $countStmt->execute($params);
            $totalRecords = (int) $countStmt->fetchColumn();

            $dataSql = "
                SELECT al.id, al.action_type, al.target_table, al.record_id, al.ip_address, al.user_agent, al.created_at,
                       CONCAT(u.first_name, ' ', u.last_name) AS user_name
                FROM audit_logs al
                LEFT JOIN users u ON al.user_id = u.id
                WHERE $where
                ORDER BY al.created_at DESC
                LIMIT :limit OFFSET :offset
            ";
            $dataStmt = $db->prepare($dataSql);
            foreach ($params as $key => $val) {
                $dataStmt->bindValue($key, $val);
            }
            $dataStmt->bindValue(':limit', $length, PDO::PARAM_INT);
            $dataStmt->bindValue(':offset', $start, PDO::PARAM_INT);
            $dataStmt->execute();
            $logs = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

            $data = [];
            foreach ($logs as $log) {
                $data[] = [
                    'timestamp'   => date('d M Y H:i:s', strtotime($log['created_at'])),
                    'user'        => htmlspecialchars($log['user_name'] ?? 'System'),
                    'action'      => htmlspecialchars($log['action_type']),
                    'target'      => htmlspecialchars($log['target_table'] . ' (ID:' . $log['record_id'] . ')'),
                    'ip'          => htmlspecialchars($log['ip_address'] ?? ''),
                    'user_agent'  => htmlspecialchars(substr($log['user_agent'] ?? '', 0, 80))
                ];
            }

            echo json_encode([
                'draw'            => $draw,
                'recordsTotal'    => $totalRecords,
                'recordsFiltered' => $totalRecords,
                'data'            => $data
            ]);
        } catch (Exception $e) {
            echo json_encode([
                'draw'            => 1,
                'recordsTotal'    => 0,
                'recordsFiltered' => 0,
                'data'            => [],
                'error'           => $e->getMessage()
            ]);
        }
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Invalid action']);
    exit;
}

// ============================================
// INITIAL PAGE LOAD – Fetch Branches & Departments for dropdowns
// ============================================
$branches = [];
$departments = [];
$allRoles = [];
try {
    $branchStmt = $db->query("SELECT id, name FROM branches WHERE status = 'active' AND deleted_at IS NULL ORDER BY name");
    $branches = $branchStmt->fetchAll(PDO::FETCH_ASSOC);
    $deptStmt = $db->query("SELECT id, name FROM departments WHERE is_active = 1 AND deleted_at IS NULL ORDER BY name");
    $departments = $deptStmt->fetchAll(PDO::FETCH_ASSOC);
    $roleStmt = $db->query("SELECT DISTINCT role_name FROM role_permissions WHERE deleted_at IS NULL ORDER BY role_name");
    $allRoles = $roleStmt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    // ignore
}

$userId = 0; // for the modal
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User, Role & Permissions Management | HMS ERP</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root {
            --primary: #4361ee;
            --primary-dark: #3a0ca3;
            --success: #06d6a0;
            --danger: #ef476f;
            --warning: #f9c74f;
            --gray-light: #e9ecef;
            --dark: #1a1a2e;
        }
        body {
            font-family: 'Inter', sans-serif;
            background: #f5f7fb;
            margin: 0;
            padding: 0;
            overflow-x: hidden;
        }
        /* Ensure content area is always visible and respects sidebar width */
        .content-page {
            margin-left: 260px;
            margin-top: 70px;
            padding: 24px 32px;
            transition: margin-left 0.3s ease;
            min-height: calc(100vh - 70px);
            position: relative;
            z-index: 1;
            background: #f5f7fb;
        }
        /* When sidebar is collapsed */
        body.erp-sidebar-collapsed .content-page {
            margin-left: 80px;
        }
        /* Responsive: on mobile, no margin-left (sidebar hidden initially) */
        @media (max-width: 768px) {
            .content-page {
                margin-left: 0;
                padding: 16px;
            }
            body.erp-sidebar-collapsed .content-page {
                margin-left: 0;
            }
        }
        /* Table containers - prevent overflow */
        .dataTables_wrapper {
            overflow-x: auto;
        }
        .card-custom {
            background: white;
            border-radius: 24px;
            border: 1px solid var(--gray-light);
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
            overflow: hidden;
            margin-bottom: 28px;
        }
        .card-header-custom {
            background: white;
            padding: 20px 24px;
            border-bottom: 1px solid var(--gray-light);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 16px;
        }
        .btn-primary-custom {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            border: none;
            border-radius: 14px;
            padding: 10px 24px;
            font-weight: 600;
            color: white;
            transition: 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .btn-primary-custom:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(67,97,238,0.3);
        }
        .btn-icon {
            width: 34px;
            height: 34px;
            border-radius: 10px;
            border: 1px solid var(--gray-light);
            background: white;
            color: #475569;
            cursor: pointer;
            transition: 0.2s;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        .btn-icon:hover {
            background: #f8fafc;
            transform: translateY(-1px);
        }
        .btn-icon.btn-warning {
            border-color: #f9c74f;
            color: #d97706;
        }
        .btn-icon.btn-success {
            border-color: #06d6a0;
            color: #059669;
        }
        .action-btns {
            display: flex;
            gap: 8px;
        }
        .badge-success {
            background: #d1fae5;
            color: #059669;
            padding: 4px 12px;
            border-radius: 40px;
            font-size: 12px;
            font-weight: 600;
        }
        .badge-danger {
            background: #fee2e2;
            color: #dc2626;
            padding: 4px 12px;
            border-radius: 40px;
            font-size: 12px;
            font-weight: 600;
        }
        .permission-grid {
            max-height: 550px;
            overflow-y: auto;
            border: 1px solid var(--gray-light);
            border-radius: 20px;
            padding: 20px;
            background: #fefefe;
        }
        .module-group {
            margin-bottom: 28px;
        }
        .module-group h6 {
            background: #f1f5f9;
            padding: 10px 16px;
            border-radius: 14px;
            font-weight: 700;
            font-size: 14px;
            margin-bottom: 16px;
        }
        .form-check {
            margin-bottom: 12px;
            padding-left: 2em;
        }
        .form-check-input {
            margin-top: 0.2em;
        }
        .form-check-label {
            font-size: 13px;
        }
        .form-check-label small {
            font-size: 11px;
        }
        .modal-content {
            border-radius: 24px;
            border: none;
        }
        .form-control, .form-select {
            border-radius: 12px;
            border: 1px solid var(--gray-light);
            padding: 10px 14px;
            font-size: 14px;
        }
        .form-control:focus, .form-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(67,97,238,0.15);
        }
        .modal-header {
            border-bottom: 1px solid var(--gray-light);
            padding: 20px 24px;
            background: #fefefe;
        }
        .modal-footer {
            border-top: 1px solid var(--gray-light);
            padding: 16px 24px;
        }
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(255,255,255,0.7);
            z-index: 2000;
            display: none;
            align-items: center;
            justify-content: center;
        }
        .loading-overlay.active {
            display: flex;
        }
        .spinner {
            width: 40px;
            height: 40px;
            border: 3px solid var(--gray-light);
            border-top-color: var(--primary);
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        @media (max-width: 768px) {
            .card-header-custom {
                flex-direction: column;
                align-items: stretch;
            }
            .permission-grid {
                max-height: 400px;
            }
        }
    </style>
</head>
<body>
<div id="wrapper">
    <?php include('assets/inc/nav.php'); ?>
    <?php include('assets/inc/sidebar.php'); ?>
    
    <div class="content-page">
        <div class="container-fluid">
            <!-- Users Management Card -->
            <div class="card-custom">
                <div class="card-header-custom">
                    <h4 class="mb-0"><i class="fas fa-users me-2 text-primary"></i> System Users & Staff</h4>
                    <button class="btn-primary-custom" data-bs-toggle="modal" data-bs-target="#userModal" onclick="resetUserForm()">
                        <i class="fas fa-plus-circle"></i> Add New User
                    </button>
                </div>
                <div class="p-3">
                    <table id="usersTable" class="table table-hover" style="width:100%">
                        <thead>
                            <tr>
                                <th>Username</th>
                                <th>Full Name</th>
                                <th>Email</th>
                                <th>Role</th>
                                <th>Branch</th>
                                <th>Department</th>
                                <th>Status</th>
                                <th>Last Login</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>

            <!-- Roles & Permissions Card -->
            <div class="card-custom">
                <div class="card-header-custom">
                    <h4 class="mb-0"><i class="fas fa-shield-alt me-2 text-primary"></i> Role-Based Permissions Matrix</h4>
                    <div class="d-flex gap-2 align-items-center">
                        <select id="roleSelector" class="form-select" style="width:220px;">
                            <option value="">Select Role</option>
                            <?php foreach ($allRoles as $role): ?>
                                <option value="<?php echo htmlspecialchars($role); ?>"><?php echo htmlspecialchars(ucfirst($role)); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button class="btn-primary-custom" id="savePermissionsBtn"><i class="fas fa-save"></i> Save Permissions</button>
                    </div>
                </div>
                <div class="p-3">
                    <div id="permissionsContainer" class="permission-grid">
                        <div class="text-muted text-center py-5">Select a role to view and edit permissions.</div>
                    </div>
                </div>
            </div>

            <!-- Audit Logs Card -->
            <div class="card-custom">
                <div class="card-header-custom">
                    <h4 class="mb-0"><i class="fas fa-history me-2 text-primary"></i> Security Audit Trail</h4>
                </div>
                <div class="p-3">
                    <table id="auditLogsTable" class="table table-hover" style="width:100%">
                        <thead>
                            <tr>
                                <th>Timestamp</th>
                                <th>User</th>
                                <th>Action</th>
                                <th>Target</th>
                                <th>IP Address</th>
                                <th>User Agent</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- MODAL: Add / Edit User -->
<div class="modal fade" id="userModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-light">
                <h5 class="modal-title"><i class="fas fa-user-plus me-2"></i><span id="userModalTitle">Add New User</span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="userForm">
                <div class="modal-body">
                    <input type="hidden" name="user_id" id="userId" value="0">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Username <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="username" id="username" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Email <span class="text-danger">*</span></label>
                            <input type="email" class="form-control" name="email" id="email" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">First Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="first_name" id="firstName" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Last Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="last_name" id="lastName" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Branch <span class="text-danger">*</span></label>
                            <select class="form-select" name="branch_id" id="branchId" required>
                                <option value="">Select Branch</option>
                                <?php foreach ($branches as $branch): ?>
                                    <option value="<?php echo $branch['id']; ?>"><?php echo htmlspecialchars($branch['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Department</label>
                            <select class="form-select" name="department_id" id="departmentId">
                                <option value="">None</option>
                                <?php foreach ($departments as $dept): ?>
                                    <option value="<?php echo $dept['id']; ?>"><?php echo htmlspecialchars($dept['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">System Role <span class="text-danger">*</span></label>
                            <select class="form-select" name="user_type" id="userType" required>
                                <option value="">Select Role</option>
                                <?php foreach ($allRoles as $role): ?>
                                    <option value="<?php echo htmlspecialchars($role); ?>"><?php echo htmlspecialchars(ucfirst($role)); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Status</label>
                            <select class="form-select" name="is_active" id="isActive">
                                <option value="1">Active</option>
                                <option value="0">Suspended</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Password <?php echo ($userId == 0) ? '<span class="text-danger">*</span>' : '(Leave blank to keep unchanged)'; ?></label>
                            <input type="password" class="form-control" name="password" id="password" autocomplete="off">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save User</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div id="loadingOverlay" class="loading-overlay"><div class="spinner"></div></div>

<script>
$(function() {
    // DataTables initialization
    let usersTable = $('#usersTable').DataTable({
        processing: true,
        serverSide: true,
        ajax: { url: window.location.pathname + '?action=get_users', type: 'GET', dataSrc: 'data' },
        columns: [
            { data: 'username' }, { data: 'full_name' }, { data: 'email' }, { data: 'user_type' },
            { data: 'branch' }, { data: 'department' }, { data: 'status' }, { data: 'last_login' },
            { data: 'actions', orderable: false, searchable: false }
        ],
        order: [[1, 'asc']],
        responsive: true,
        pageLength: 25,
        lengthMenu: [[10, 25, 50, 100], [10, 25, 50, 100]]
    });

    let auditTable = $('#auditLogsTable').DataTable({
        processing: true,
        serverSide: true,
        ajax: { url: window.location.pathname + '?action=get_audit_logs', type: 'GET', dataSrc: 'data' },
        columns: [
            { data: 'timestamp' }, { data: 'user' }, { data: 'action' }, { data: 'target' },
            { data: 'ip' }, { data: 'user_agent' }
        ],
        order: [[0, 'desc']],
        responsive: true,
        pageLength: 25
    });

    window.resetUserForm = function() {
        $('#userForm')[0].reset();
        $('#userId').val('0');
        $('#userModalTitle').text('Add New User');
        $('#password').prop('required', false);
        $('#password').attr('placeholder', 'Enter password for new user');
        $('#branchId').val('');
        $('#departmentId').val('');
    };

    $(document).on('click', '.btn-edit-user', function() {
        let row = usersTable.row($(this).closest('tr')).data();
        if (!row) return;
        $('#userId').val(row.id);
        $('#username').val(row.username);
        $('#email').val(row.email);
        $('#firstName').val(row.full_name.split(' ')[0] || '');
        $('#lastName').val(row.full_name.split(' ').slice(1).join(' ') || '');
        $('#branchId').val(row.branch_id).trigger('change');
        $('#departmentId').val(row.department_id);
        $('#userType').val(row.user_type.toLowerCase());
        $('#isActive').val(row.is_active === 'Active' ? '1' : '0');
        $('#userModalTitle').text('Edit User');
        $('#password').prop('required', false);
        $('#password').attr('placeholder', 'Leave blank to keep unchanged');
        $('#userModal').modal('show');
    });

    $(document).on('click', '.btn-toggle-status', function() {
        let userId = $(this).data('id');
        let currentStatus = $(this).data('status');
        Swal.fire({
            title: 'Confirm Status Change',
            text: `Are you sure you want to ${currentStatus ? 'suspend' : 'activate'} this user?`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#4361ee',
            confirmButtonText: 'Yes, proceed'
        }).then(result => {
            if (result.isConfirmed) {
                showLoading(true);
                $.ajax({
                    url: window.location.pathname + '?action=toggle_user_status',
                    method: 'POST',
                    dataType: 'json',
                    data: {
                        user_id: userId,
                        current_status: currentStatus,
                        csrf_token: '<?php echo $csrf_token; ?>'
                    },
                    success: res => {
                        showLoading(false);
                        if (res.success) {
                            Swal.fire('Updated', res.message, 'success');
                            usersTable.ajax.reload(null, false);
                        } else {
                            Swal.fire('Error', res.message, 'error');
                        }
                    },
                    error: () => { showLoading(false); Swal.fire('Error', 'Network error', 'error'); }
                });
            }
        });
    });

    $(document).on('click', '.btn-reset-pwd', function() {
        let userId = $(this).data('id');
        let userName = $(this).data('name');
        Swal.fire({
            title: 'Reset Password',
            html: `Enter new password for <strong>${escapeHtml(userName)}</strong>`,
            input: 'password',
            inputAttributes: { autocapitalize: 'off', required: true },
            showCancelButton: true,
            confirmButtonText: 'Set Password',
            preConfirm: (newPwd) => {
                if (!newPwd) { Swal.showValidationMessage('Password required'); return false; }
                return newPwd;
            }
        }).then(result => {
            if (result.isConfirmed) {
                showLoading(true);
                $.ajax({
                    url: window.location.pathname + '?action=save_user',
                    method: 'POST',
                    dataType: 'json',
                    data: {
                        user_id: userId,
                        password: result.value,
                        csrf_token: '<?php echo $csrf_token; ?>',
                        username: 'dummy_temp',
                        email: 'dummy@temp.com',
                        first_name: 'dummy',
                        last_name: 'user',
                        branch_id: 1,
                        user_type: 'doctor'
                    },
                    success: res => {
                        showLoading(false);
                        if (res.success) Swal.fire('Success', 'Password updated successfully', 'success');
                        else Swal.fire('Error', res.message, 'error');
                    },
                    error: () => { showLoading(false); Swal.fire('Error', 'Network error', 'error'); }
                });
            }
        });
    });

    $('#userForm').on('submit', function(e) {
        e.preventDefault();
        let formData = new FormData(this);
        formData.append('action', 'save_user');
        showLoading(true);
        $.ajax({
            url: window.location.pathname + '?action=save_user',
            method: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            dataType: 'json',
            success: res => {
                showLoading(false);
                if (res.success) {
                    Swal.fire('Success', res.message, 'success');
                    $('#userModal').modal('hide');
                    usersTable.ajax.reload(null, false);
                } else {
                    Swal.fire('Error', res.message, 'error');
                }
            },
            error: () => { showLoading(false); Swal.fire('System Error', 'Could not save user.', 'error'); }
        });
    });

    let currentRole = null;
    let permissionsGrouped = {};
    $.ajax({
        url: window.location.pathname + '?action=get_permissions',
        method: 'GET',
        dataType: 'json',
        success: res => {
            if (res.success) permissionsGrouped = res.permissions_grouped;
        }
    });

    $('#roleSelector').on('change', function() {
        currentRole = $(this).val();
        if (!currentRole) {
            $('#permissionsContainer').html('<div class="text-muted text-center py-5">Select a role to view and edit permissions.</div>');
            return;
        }
        showLoading(true);
        $.ajax({
            url: window.location.pathname + '?action=get_role_permissions&role_name=' + encodeURIComponent(currentRole),
            method: 'GET',
            dataType: 'json',
            success: res => {
                showLoading(false);
                if (res.success) {
                    let assigned = res.assigned_permissions;
                    renderPermissionMatrix(assigned);
                } else {
                    $('#permissionsContainer').html('<div class="alert alert-danger">Failed to load permissions.</div>');
                }
            },
            error: () => { showLoading(false); $('#permissionsContainer').html('<div class="alert alert-danger">Network error.</div>'); }
        });
    });

    function renderPermissionMatrix(assignedIds) {
        if (!permissionsGrouped || Object.keys(permissionsGrouped).length === 0) {
            $('#permissionsContainer').html('<div class="text-muted text-center py-5">Loading permission definitions...</div>');
            return;
        }
        let html = '';
        for (let module in permissionsGrouped) {
            let moduleDisplay = module.charAt(0).toUpperCase() + module.slice(1).replace(/_/g, ' ');
            html += `<div class="module-group"><h6><i class="fas fa-cog me-2"></i>${escapeHtml(moduleDisplay)}</h6>`;
            permissionsGrouped[module].forEach(perm => {
                let isChecked = assignedIds.includes(perm.id);
                html += `
                    <div class="form-check">
                        <input class="form-check-input permission-checkbox" type="checkbox" value="${perm.id}" id="perm_${perm.id}" ${isChecked ? 'checked' : ''}>
                        <label class="form-check-label" for="perm_${perm.id}">
                            <strong>${escapeHtml(perm.permission_name)}</strong><br>
                            <small class="text-muted">${escapeHtml(perm.permission_key)}</small>
                        </label>
                    </div>
                `;
            });
            html += `</div>`;
        }
        $('#permissionsContainer').html(html);
    }

    $('#savePermissionsBtn').on('click', function() {
        if (!currentRole) {
            Swal.fire('Warning', 'Please select a role first.', 'warning');
            return;
        }
        let selectedPerms = [];
        $('.permission-checkbox:checked').each(function() {
            selectedPerms.push($(this).val());
        });
        showLoading(true);
        $.ajax({
            url: window.location.pathname + '?action=save_role_permissions',
            method: 'POST',
            dataType: 'json',
            data: {
                role_name: currentRole,
                permission_ids: selectedPerms,
                csrf_token: '<?php echo $csrf_token; ?>'
            },
            success: res => {
                showLoading(false);
                if (res.success) {
                    Swal.fire('Saved', 'Permissions updated successfully.', 'success');
                } else {
                    Swal.fire('Error', res.message, 'error');
                }
            },
            error: () => { showLoading(false); Swal.fire('Error', 'Network error while saving.', 'error'); }
        });
    });

    function escapeHtml(str) {
        if (!str) return '';
        return String(str).replace(/[&<>]/g, m => m === '&' ? '&amp;' : (m === '<' ? '&lt;' : '&gt;'));
    }

    function showLoading(show) {
        if (show) $('#loadingOverlay').addClass('active');
        else $('#loadingOverlay').removeClass('active');
    }
});
</script>
<?php include('assets/inc/footer.php'); ?>
</body>
</html>