<?php
/**
 * ======================================================================
 * LOGOUT - HMS ERP (Enterprise Secure Logout)
 * Error-tolerant: Always destroys session and redirects
 * ======================================================================
 */

// Start output buffering to prevent header issues
ob_start();

// Initialize session if not already active
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ============================================
// COLLECT USER INFO BEFORE DESTROYING SESSION
// ============================================
$user_id = $_SESSION['user_id'] ?? 0;
$user_branch_id = $_SESSION['branch_id'] ?? 0;
$ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';

// ============================================
// ATTEMPT AUDIT LOGGING (fail silently)
// ============================================
try {
    // Only try to include database files if they exist
    $db_config_path = __DIR__ . '/../../../config/db_connection.php';
    $auth_helper_path = __DIR__ . '/../../../includes/auth_helper.php';
    
    if (file_exists($db_config_path) && file_exists($auth_helper_path)) {
        require_once $db_config_path;
        require_once $auth_helper_path;
        
        if (isset($pdo) && $pdo instanceof PDO && $user_id > 0) {
            // Check if audit_logs table exists
            $check = $pdo->query("SHOW TABLES LIKE 'audit_logs'");
            if ($check && $check->rowCount() > 0) {
                $stmt = $pdo->prepare("
                    INSERT INTO audit_logs 
                    (branch_id, user_id, action_type, target_table, record_id, 
                     new_values, ip_address, user_agent, created_at)
                    VALUES 
                    (:branch, :user, 'LOGOUT', 'session', 0, 
                     :details, :ip, :ua, NOW())
                ");
                $stmt->execute([
                    ':branch' => $user_branch_id,
                    ':user' => $user_id,
                    ':details' => json_encode(['action' => 'user_logout']),
                    ':ip' => $ip_address,
                    ':ua' => $user_agent
                ]);
            }
        }
    }
} catch (Exception $e) {
    // Silent fail – never block logout
    error_log("Logout audit failed (non-critical): " . $e->getMessage());
}

// ============================================
// COMPLETE SESSION DESTRUCTION
// ============================================

// Unset all session variables
$_SESSION = [];

// Delete session cookie
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}

// Destroy the session
session_destroy();

// Clear the superglobal
unset($_SESSION);

// ============================================
// PREVENT CACHING
// ============================================
header('Cache-Control: no-store, no-cache, must-revalidate, private');
header('Pragma: no-cache');
header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');

// ============================================
// REDIRECT TO LOGIN PAGE
// ============================================
// Try multiple possible paths in case file structure varies
$login_paths = [
    '../../../index.php'   // fallback if you know the base URL
];

$redirect_url = '../../../index.php'; // default
foreach ($login_paths as $path) {
    if (file_exists($path)) {
        $redirect_url = $path;
        break;
    }
}

header('Location: ' . $redirect_url);
exit;