<?php
/**
 * auth_helper.php
 * 
 * Centralized Authentication & Role-Based Access Control (RBAC) Helper
 * Provides session management, permission checking, and user role utilities.
 * 
 * NOTE: This file assumes db_connection.php has already been included,
 * which provides getDB() and is_db_available().
 * 
 * @package HMS-ERP
 * @version 2.2
 */

// Ensure session is started (idempotent)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ============================================
// Session & User Information Helpers
// ============================================

/**
 * Get the current user ID from session
 * @return int
 */
function getCurrentUserId() {
    return (int)($_SESSION['user_id'] ?? 0);
}

/**
 * Get the current user type (role) from session
 * @return string
 */
function getCurrentUserType() {
    return $_SESSION['user_type'] ?? '';
}

/**
 * Get the current branch ID from session
 * @return int
 */
function getCurrentBranchId() {
    return (int)($_SESSION['branch_id'] ?? 0);
}

/**
 * Check if the current user is a global administrator (super_admin)
 * @return bool
 */
function isSuperAdmin() {
    return getCurrentUserType() === 'super_admin';
}

/**
 * Check if the current user is an administrator (admin or super_admin)
 * @return bool
 */
function isAdmin() {
    $type = getCurrentUserType();
    return $type === 'admin' || $type === 'super_admin';
}

// ============================================
// RBAC Permission Helpers
// ============================================

/**
 * Fetch all permission keys for a given user (cached per request)
 * @param PDO $pdo
 * @param int $user_id
 * @return array List of permission keys
 */
function getUserPermissions(PDO $pdo, int $user_id) {
    static $cache = [];
    
    if (isset($cache[$user_id])) {
        return $cache[$user_id];
    }
    
    try {
        // Get the user's role (user_type)
        $stmt = $pdo->prepare("SELECT user_type FROM users WHERE id = ? AND deleted_at IS NULL");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            $cache[$user_id] = [];
            return [];
        }
        
        $role = $user['user_type'];
        
        // Query all permissions assigned to this role
        $sql = "SELECT sp.permission_key
                FROM role_permissions rp
                JOIN system_permissions sp ON rp.permission_id = sp.id
                WHERE rp.role_name = :role
                  AND rp.deleted_at IS NULL
                  AND sp.deleted_at IS NULL";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':role' => $role]);
        $permissions = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        $cache[$user_id] = $permissions;
        return $permissions;
        
    } catch (PDOException $e) {
        error_log("getUserPermissions error: " . $e->getMessage());
        return [];
    }
}

/**
 * Check if a user has a specific permission
 * @param PDO $pdo
 * @param string $permission_key Permission key (e.g., 'manage_patient_billing')
 * @param int|null $user_id (optional, defaults to current session user)
 * @return bool
 */
function user_has_permission(PDO $pdo, string $permission_key, ?int $user_id = null) {
    if ($user_id === null) {
        $user_id = getCurrentUserId();
    }
    
    if ($user_id <= 0) {
        return false;
    }
    
    // Super admins have all permissions
    $stmt = $pdo->prepare("SELECT user_type FROM users WHERE id = ? AND deleted_at IS NULL");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($user && $user['user_type'] === 'super_admin') {
        return true;
    }
    
    $permissions = getUserPermissions($pdo, $user_id);
    return in_array($permission_key, $permissions);
}

// ============================================
// Require / Guard Helpers
// ============================================

/**
 * Require user to be logged in; redirect to login page if not.
 * @param string $redirect URL to redirect after login (defaults to current page)
 */
function require_login(string $redirect = '') {
    if (getCurrentUserId() <= 0) {
        if (empty($redirect)) {
            $redirect = $_SERVER['REQUEST_URI'] ?? '';
        }
        $_SESSION['login_redirect'] = $redirect;
        header('Location: /login.php');
        exit;
    }
}

/**
 * Require a specific permission; if not granted, redirect to dashboard with error.
 * @param PDO $pdo
 * @param string $permission_key
 * @param int|null $user_id
 */
function require_permission(PDO $pdo, string $permission_key, ?int $user_id = null) {
    require_login();
    if (!user_has_permission($pdo, $permission_key, $user_id)) {
        $_SESSION['error'] = 'You do not have permission to access this page.';
        header('Location: dashboard.php');
        exit;
    }
}

// ============================================
// Additional User & Branch Helpers
// ============================================

/**
 * Get user's role name (user_type)
 * @param PDO $pdo
 * @param int $user_id
 * @return string|null
 */
function getUserRole(PDO $pdo, int $user_id) {
    $stmt = $pdo->prepare("SELECT user_type FROM users WHERE id = ? AND deleted_at IS NULL");
    $stmt->execute([$user_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? $row['user_type'] : null;
}

/**
 * Get branch name by ID
 * @param PDO $pdo
 * @param int $branch_id
 * @return string|null
 */
function getBranchName(PDO $pdo, int $branch_id) {
    $stmt = $pdo->prepare("SELECT name FROM branches WHERE id = ? AND deleted_at IS NULL");
    $stmt->execute([$branch_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? $row['name'] : null;
}

// ============================================
// CSRF Protection Helpers
// ============================================

/**
 * Generate a CSRF token (store in session if not exists, return current)
 * @return string
 */
function getCsrfToken() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Verify a submitted CSRF token against the session token.
 * @param string $submitted_token
 * @return bool
 */
function verifyCsrfToken(string $submitted_token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $submitted_token);
}

// ============================================
// Audit Log Helper
// ============================================

/**
 * Log a user action to audit_logs (simplified wrapper)
 * @param PDO $pdo
 * @param string $action_type INSERT/UPDATE/DELETE/LOGIN/LOGOUT
 * @param string $target_table
 * @param int|null $record_id
 * @param array|null $old_values
 * @param array|null $new_values
 * @param string|null $notes
 */
function audit_log(PDO $pdo, string $action_type, string $target_table, ?int $record_id = null, ?array $old_values = null, ?array $new_values = null, ?string $notes = null) {
    try {
        $user_id = getCurrentUserId();
        $branch_id = getCurrentBranchId();
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
        
        $sql = "INSERT INTO audit_logs 
                (branch_id, user_id, action_type, target_table, record_id, old_values, new_values, ip_address, user_agent, created_at) 
                VALUES 
                (:branch_id, :user_id, :action_type, :target_table, :record_id, :old_values, :new_values, :ip, :ua, NOW())";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':branch_id' => $branch_id,
            ':user_id' => $user_id,
            ':action_type' => $action_type,
            ':target_table' => $target_table,
            ':record_id' => $record_id,
            ':old_values' => $old_values ? json_encode($old_values) : null,
            ':new_values' => $new_values ? json_encode($new_values) : null,
            ':ip' => $ip,
            ':ua' => $user_agent
        ]);
    } catch (Exception $e) {
        error_log("Audit log failed: " . $e->getMessage());
    }
}