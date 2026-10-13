<?php
/**
 * ======================================================================
 * PROFILE SETTINGS - HMS ERP (Enterprise Grade)
 * PDO | Transactions | Audit Trail | Branch Isolation | CSRF Protected
 * ======================================================================
 */

ob_start();
session_start();

// ============================================
// ERROR REPORTING (Production)
// ============================================
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../../logs/profile_errors.log');

$log_dir = __DIR__ . '/../../logs/';
if (!is_dir($log_dir)) mkdir($log_dir, 0755, true);

// ============================================
// TIMEZONE
// ============================================
date_default_timezone_set('Africa/Addis_Ababa');

// ============================================
// SESSION VALIDATION
// ============================================
$user_id = (int)($_SESSION['user_id'] ?? 0);
$user_branch_id = (int)($_SESSION['branch_id'] ?? 0);
$is_global_admin = ($_SESSION['user_type'] ?? '') === 'super_admin';

if ($user_id <= 0) {
    header('Location: ../../index.php');
    exit;
}

// ============================================
// CENTRALIZED PDO CONNECTION & HELPERS
// ============================================
require_once __DIR__ . '/../../config/db_connection.php';
require_once __DIR__ . '/../../includes/auth_helper.php';

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
// CSRF TOKEN
// ============================================
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// ============================================
// HELPER FUNCTIONS
// ============================================
function escapeHtml($str) {
    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}

function sanitizeInput($input) {
    return trim(htmlspecialchars((string)$input, ENT_QUOTES, 'UTF-8'));
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
        error_log('Audit log failed: ' . $e->getMessage());
    }
}

function validateCsrf($submitted, $session) {
    return !empty($submitted) && !empty($session) && hash_equals($session, $submitted);
}

// ============================================
// FETCH USER DATA (with branch isolation)
// ============================================
$user = null;
try {
    $stmt = $pdo->prepare("
        SELECT id, first_name, middle_name, last_name, email, phone, mobile, user_type as type,
               employee_id, username, branch_id, gender, birth_date, address, picture, is_active, created_at
        FROM users
        WHERE id = :id AND deleted_at IS NULL
        LIMIT 1
    ");
    $stmt->execute([':id' => $user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    // Enforce branch isolation: non-super_admin must belong to same branch as session
    if ($user && !$is_global_admin && (int)$user['branch_id'] !== $user_branch_id) {
        error_log("SECURITY: User ID {$user_id} attempted to access profile of different branch (session: {$user_branch_id}, user branch: {$user['branch_id']})");
        header('Location: dashboard.php?error=unauthorized');
        exit;
    }
} catch (PDOException $e) {
    error_log('Fetch user failed: ' . $e->getMessage());
}

// ============================================
// BRANCH NAME (for display)
// ============================================
$branch_name = 'Main Branch';
try {
    $stmt = $pdo->prepare("SELECT name FROM branches WHERE id = :id AND deleted_at IS NULL");
    $stmt->execute([':id' => $user_branch_id]);
    if ($row = $stmt->fetch()) $branch_name = $row['name'];
} catch (PDOException $e) {
    error_log($e->getMessage());
}

// ============================================
// AJAX HANDLERS (PDO with transactions)
// ============================================
if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(false, 'Invalid request method');
    }

    // CSRF validation
    if (!validateCsrf($_POST['csrf_token'] ?? '', $csrf_token)) {
        error_log("CSRF validation failed for user {$user_id}");
        jsonResponse(false, 'Security token mismatch. Please refresh the page.');
    }

    $action = $_POST['action'] ?? '';

    // ------------------------ UPDATE PROFILE ------------------------
    if ($action === 'update_profile') {
        $first_name = sanitizeInput($_POST['first_name'] ?? '');
        $middle_name = sanitizeInput($_POST['middle_name'] ?? '');
        $last_name = sanitizeInput($_POST['last_name'] ?? '');
        $email = sanitizeInput($_POST['email'] ?? '');
        $phone = sanitizeInput($_POST['phone'] ?? '');
        $address = sanitizeInput($_POST['address'] ?? '');
        $current_password = $_POST['current_password'] ?? '';

        if (empty($first_name) || empty($last_name)) {
            jsonResponse(false, 'First name and last name are required');
        }
        if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            jsonResponse(false, 'Invalid email format');
        }

        try {
            // Verify current password using password_hash column
            $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE id = :id AND deleted_at IS NULL");
            $stmt->execute([':id' => $user_id]);
            $userData = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$userData || !password_verify($current_password, $userData['password_hash'])) {
                jsonResponse(false, 'Current password is incorrect');
            }

            $pdo->beginTransaction();

            $updateStmt = $pdo->prepare("
                UPDATE users 
                SET first_name = :first_name, middle_name = :middle_name, last_name = :last_name,
                    email = :email, phone = :phone, address = :address, updated_at = NOW()
                WHERE id = :id
            ");
            $updateStmt->execute([
                ':first_name' => $first_name,
                ':middle_name' => $middle_name,
                ':last_name' => $last_name,
                ':email' => $email ?: null,
                ':phone' => $phone ?: null,
                ':address' => $address ?: null,
                ':id' => $user_id
            ]);

            // Update session variables
            $_SESSION['first_name'] = $first_name;
            $_SESSION['last_name'] = $last_name;

            // Audit log
            logAudit($pdo, $user_id, 'UPDATE_PROFILE', 'users', $user_id, [
                'fields' => ['first_name', 'middle_name', 'last_name', 'email', 'phone', 'address']
            ], $user_branch_id);

            $pdo->commit();
            jsonResponse(true, 'Profile updated successfully');

        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Profile update PDO error: ' . $e->getMessage());
            jsonResponse(false, 'Database error occurred. Please try again later.');
        }
    }

    // ------------------------ UPDATE PASSWORD ------------------------
    if ($action === 'update_password') {
        $current_password = $_POST['current_password'] ?? '';
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

        if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
            jsonResponse(false, 'All password fields are required');
        }
        if ($new_password !== $confirm_password) {
            jsonResponse(false, 'New password and confirmation do not match');
        }

        // Password complexity
        if (strlen($new_password) < 8) {
            jsonResponse(false, 'Password must be at least 8 characters');
        }
        if (!preg_match('/[A-Z]/', $new_password)) {
            jsonResponse(false, 'Password must contain at least one uppercase letter');
        }
        if (!preg_match('/[a-z]/', $new_password)) {
            jsonResponse(false, 'Password must contain at least one lowercase letter');
        }
        if (!preg_match('/[0-9]/', $new_password)) {
            jsonResponse(false, 'Password must contain at least one number');
        }

        try {
            // Verify current password using password_hash column
            $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE id = :id AND deleted_at IS NULL");
            $stmt->execute([':id' => $user_id]);
            $userData = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$userData || !password_verify($current_password, $userData['password_hash'])) {
                jsonResponse(false, 'Current password is incorrect');
            }

            $hashed = password_hash($new_password, PASSWORD_DEFAULT);

            $pdo->beginTransaction();

            $updateStmt = $pdo->prepare("UPDATE users SET password_hash = :password, updated_at = NOW() WHERE id = :id");
            $updateStmt->execute([':password' => $hashed, ':id' => $user_id]);

            // Audit log
            logAudit($pdo, $user_id, 'CHANGE_PASSWORD', 'users', $user_id, ['action' => 'password_changed'], $user_branch_id);

            $pdo->commit();
            jsonResponse(true, 'Password updated successfully. Please login again.');

        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Password update PDO error: ' . $e->getMessage());
            jsonResponse(false, 'Database error occurred. Please try again later.');
        }
    }

    jsonResponse(false, 'Invalid action');
}

// ============================================
// PAGE DISPLAY (non-AJAX)
// ============================================
$display_name = '';
if ($user) {
    $display_name = trim(($user['first_name'] ?? '') . ' ' . ($user['middle_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include('assets/inc/head.php'); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile Settings - HMS ERP</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #1a237e;
            --primary-dark: #0d1b5e;
            --primary-light: #283593;
            --success: #10b981;
            --danger: #ef4444;
            --gray: #6b7280;
            --gray-light: #e5e7eb;
            --dark: #1f2937;
            --white: #ffffff;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: #f5f7fb; }
        .content-page { margin-left: 260px; margin-top: 70px; padding: 24px 32px; transition: margin-left 0.3s; min-height: calc(100vh - 70px); }
        @media (max-width: 768px) { .content-page { margin-left: 0 !important; padding: 16px; } }
        .welcome-header { background: linear-gradient(135deg, var(--primary-dark) 0%, var(--primary) 100%); border-radius: 24px; padding: 24px 32px; color: white; margin-bottom: 28px; }
        .profile-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 28px; }
        @media (max-width: 992px) { .profile-grid { grid-template-columns: 1fr; } }
        .card-module { background: var(--white); border-radius: 20px; border: 1px solid var(--gray-light); overflow: hidden; margin-bottom: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
        .card-header { padding: 18px 24px; background: #f8fafc; border-bottom: 1px solid var(--gray-light); font-weight: 700; color: var(--dark); }
        .card-body { padding: 24px; }
        .profile-avatar { width: 80px; height: 80px; border-radius: 50%; background: linear-gradient(135deg, var(--primary), var(--primary-light)); display: flex; align-items: center; justify-content: center; color: white; font-size: 2rem; font-weight: 700; margin: 0 auto; }
        .info-row { display: flex; margin-bottom: 16px; padding-bottom: 12px; border-bottom: 1px solid var(--gray-light); }
        .info-label { width: 120px; font-weight: 600; color: var(--gray); }
        .info-value { flex: 1; color: var(--dark); font-weight: 500; }
        .form-control { border-radius: 10px; border: 1px solid var(--gray-light); padding: 10px 14px; font-size: 14px; width: 100%; }
        .form-control:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(26,35,126,0.1); }
        .form-label { font-weight: 600; margin-bottom: 6px; display: block; color: var(--dark); }
        .password-input-wrapper { position: relative; }
        .password-toggle { position: absolute; right: 12px; top: 50%; transform: translateY(-50%); cursor: pointer; color: var(--gray); }
        .btn-primary-custom { background: linear-gradient(135deg, var(--primary), var(--primary-dark)); color: white; border: none; padding: 12px 24px; border-radius: 12px; font-weight: 600; width: 100%; transition: all 0.2s; }
        .btn-primary-custom:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(26,35,126,0.3); }
        .badge-active { background: #d1fae5; color: #059669; padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: 600; display: inline-block; }
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
                <div class="row align-items-center">
                    <div class="col-md-8">
                        <div class="welcome-title">Profile Settings 👤</div>
                        <div class="welcome-subtitle">Manage your account information and security settings</div>
                    </div>
                    <div class="col-md-4 text-md-end mt-3 mt-md-0">
                        <span class="badge bg-light text-dark p-2"><i class="fas fa-building me-1"></i> <?php echo escapeHtml($branch_name); ?></span>
                    </div>
                </div>
            </div>

            <div class="profile-grid">
                <!-- LEFT: Profile Info Card -->
                <div class="card-module">
                    <div class="card-header"><i class="fas fa-user-circle me-2"></i> Profile Information</div>
                    <div class="card-body">
                        <div class="text-center mb-4">
                            <div class="profile-avatar"><?php echo strtoupper(substr($display_name, 0, 1) ?: 'U'); ?></div>
                            <h5 class="mt-3 mb-1"><?php echo escapeHtml($display_name ?: 'User'); ?></h5>
                            <span class="badge-active"><i class="fas fa-circle me-1" style="font-size: 8px;"></i> Active</span>
                        </div>
                        <div class="info-row"><div class="info-label"><i class="fas fa-user-tag me-2"></i> Role:</div><div class="info-value"><?php echo escapeHtml(ucfirst(str_replace('_', ' ', $user['type'] ?? 'User'))); ?></div></div>
                        <div class="info-row"><div class="info-label"><i class="fas fa-id-card me-2"></i> Employee ID:</div><div class="info-value"><?php echo escapeHtml($user['employee_id'] ?? 'N/A'); ?></div></div>
                        <div class="info-row"><div class="info-label"><i class="fas fa-envelope me-2"></i> Email:</div><div class="info-value"><?php echo escapeHtml($user['email'] ?? 'N/A'); ?></div></div>
                        <div class="info-row"><div class="info-label"><i class="fas fa-phone me-2"></i> Phone:</div><div class="info-value"><?php echo escapeHtml($user['phone'] ?? $user['mobile'] ?? 'N/A'); ?></div></div>
                        <div class="info-row"><div class="info-label"><i class="fas fa-calendar-alt me-2"></i> Member Since:</div><div class="info-value"><?php echo date('F d, Y', strtotime($user['created_at'] ?? 'now')); ?></div></div>
                    </div>
                </div>

                <!-- RIGHT: Update Forms -->
                <div>
                    <!-- Update Profile -->
                    <div class="card-module">
                        <div class="card-header"><i class="fas fa-edit me-2"></i> Update Profile Information</div>
                        <div class="card-body">
                            <form id="profileForm">
                                <input type="hidden" name="action" value="update_profile">
                                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                <div class="row">
                                    <div class="col-md-6 mb-3"><label class="form-label">First Name <span class="text-danger">*</span></label><input type="text" name="first_name" class="form-control" value="<?php echo escapeHtml($user['first_name'] ?? ''); ?>" required></div>
                                    <div class="col-md-6 mb-3"><label class="form-label">Middle Name</label><input type="text" name="middle_name" class="form-control" value="<?php echo escapeHtml($user['middle_name'] ?? ''); ?>"></div>
                                </div>
                                <div class="row">
                                    <div class="col-md-6 mb-3"><label class="form-label">Last Name <span class="text-danger">*</span></label><input type="text" name="last_name" class="form-control" value="<?php echo escapeHtml($user['last_name'] ?? ''); ?>" required></div>
                                    <div class="col-md-6 mb-3"><label class="form-label">Email Address</label><input type="email" name="email" class="form-control" value="<?php echo escapeHtml($user['email'] ?? ''); ?>"></div>
                                </div>
                                <div class="row">
                                    <div class="col-md-6 mb-3"><label class="form-label">Phone Number</label><input type="tel" name="phone" class="form-control" value="<?php echo escapeHtml($user['phone'] ?? $user['mobile'] ?? ''); ?>"></div>
                                    <div class="col-md-6 mb-3"><label class="form-label">Address</label><input type="text" name="address" class="form-control" value="<?php echo escapeHtml($user['address'] ?? ''); ?>"></div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Current Password <span class="text-danger">*</span></label>
                                    <div class="password-input-wrapper">
                                        <input type="password" name="current_password" id="currentPassword" class="form-control" placeholder="Enter your current password to save changes" required>
                                        <i class="fas fa-eye-slash password-toggle" onclick="togglePassword('currentPassword')"></i>
                                    </div>
                                </div>
                                <button type="submit" class="btn-primary-custom"><i class="fas fa-save me-2"></i> Update Profile</button>
                            </form>
                        </div>
                    </div>

                    <!-- Change Password -->
                    <div class="card-module">
                        <div class="card-header"><i class="fas fa-lock me-2"></i> Change Password</div>
                        <div class="card-body">
                            <form id="passwordForm">
                                <input type="hidden" name="action" value="update_password">
                                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                <div class="mb-3">
                                    <label class="form-label">Current Password <span class="text-danger">*</span></label>
                                    <div class="password-input-wrapper">
                                        <input type="password" name="current_password" id="currentPwd" class="form-control" required>
                                        <i class="fas fa-eye-slash password-toggle" onclick="togglePassword('currentPwd')"></i>
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">New Password <span class="text-danger">*</span></label>
                                    <div class="password-input-wrapper">
                                        <input type="password" name="new_password" id="newPassword" class="form-control" required>
                                        <i class="fas fa-eye-slash password-toggle" onclick="togglePassword('newPassword')"></i>
                                    </div>
                                    <div class="small text-muted mt-1"><i class="fas fa-info-circle"></i> 8+ chars, uppercase, lowercase, number</div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Confirm New Password <span class="text-danger">*</span></label>
                                    <div class="password-input-wrapper">
                                        <input type="password" name="confirm_password" id="confirmPassword" class="form-control" required>
                                        <i class="fas fa-eye-slash password-toggle" onclick="togglePassword('confirmPassword')"></i>
                                    </div>
                                </div>
                                <button type="submit" class="btn-primary-custom"><i class="fas fa-key me-2"></i> Change Password</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php include('assets/inc/footer.php'); ?>
</div>

<div id="loadingOverlay" class="loading-overlay"><div class="spinner"></div></div>

<script src="assets/js/vendor.min.js"></script>
<script src="assets/js/app.min.js"></script>
<script>
const csrfToken = <?php echo json_encode($csrf_token); ?>;

function showLoading(show) { document.getElementById('loadingOverlay').classList.toggle('active', show); }
function togglePassword(fieldId) {
    let field = document.getElementById(fieldId);
    let icon = field.nextElementSibling;
    if (field.type === 'password') { field.type = 'text'; icon.classList.remove('fa-eye-slash'); icon.classList.add('fa-eye'); }
    else { field.type = 'password'; icon.classList.remove('fa-eye'); icon.classList.add('fa-eye-slash'); }
}

$('#profileForm').on('submit', function(e) {
    e.preventDefault();
    if (!$('#currentPassword').val()) return Swal.fire('Error', 'Current password required', 'error');
    Swal.fire({ title: 'Confirm Update', text: 'Update your profile?', icon: 'question', showCancelButton: true, confirmButtonText: 'Yes' }).then(result => {
        if (result.isConfirmed) {
            showLoading(true);
            $.ajax({ url: window.location.pathname, type: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, data: $(this).serialize(), dataType: 'json',
                success: res => { showLoading(false); if (res.success) Swal.fire('Success', res.message, 'success').then(() => location.reload()); else Swal.fire('Error', res.message, 'error'); },
                error: () => { showLoading(false); Swal.fire('Error', 'Network error', 'error'); }
            });
        }
    });
});

$('#passwordForm').on('submit', function(e) {
    e.preventDefault();
    let newPwd = $('#newPassword').val(), confirm = $('#confirmPassword').val();
    if (newPwd !== confirm) return Swal.fire('Error', 'Passwords do not match', 'error');
    if (newPwd.length < 8) return Swal.fire('Error', 'Password must be 8+ characters', 'error');
    if (!/[A-Z]/.test(newPwd)) return Swal.fire('Error', 'Need uppercase letter', 'error');
    if (!/[a-z]/.test(newPwd)) return Swal.fire('Error', 'Need lowercase letter', 'error');
    if (!/[0-9]/.test(newPwd)) return Swal.fire('Error', 'Need a number', 'error');

    Swal.fire({ title: 'Confirm Password Change', text: 'You will be logged out', icon: 'warning', showCancelButton: true, confirmButtonText: 'Yes', confirmButtonColor: '#dc2626' }).then(result => {
        if (result.isConfirmed) {
            showLoading(true);
            $.ajax({ url: window.location.pathname, type: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, data: $(this).serialize(), dataType: 'json',
                success: res => { showLoading(false); if (res.success) Swal.fire('Success', res.message, 'success').then(() => window.location.href = '../../index.php'); else Swal.fire('Error', res.message, 'error'); },
                error: () => { showLoading(false); Swal.fire('Error', 'Network error', 'error'); }
            });
        }
    });
});

if (typeof MetisMenu !== 'undefined') { let sideMenu = document.getElementById('side-menu'); if (sideMenu && !sideMenu.hasAttribute('data-metismenu-initialized')) { new MetisMenu(sideMenu, { toggle: true }); sideMenu.setAttribute('data-metismenu-initialized', 'true'); } }
</script>
</body>
</html>