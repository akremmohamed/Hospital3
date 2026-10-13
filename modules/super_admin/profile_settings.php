<?php
/**
 * Super Admin Profile Settings
 * Module: profile_settings.php
 *
 * Secure profile management with cryptographic password upgrade, 2FA, and session telemetry.
 * Strictly based on hms_erp.sql.
 *
 * @package HMS-ERP
 * @subpackage SuperAdmin
 * @version 1.0
 */

if (session_status() === PHP_SESSION_NONE) session_start();

// ============================================
// 1. IRONCLAD AUTHENTICATION GATE
// ============================================
if (!isset($_SESSION['user_id']) || ($_SESSION['user_type'] ?? '') !== 'super_admin') {
    header('Location: ../../index.php');
    exit;
}

require_once __DIR__ . '/../../config/db_connection.php';
require_once __DIR__ . '/../../includes/auth_helper.php';

$pdo = getDB();
if (!$pdo) {
    die('Database connection failed. Contact IT support.');
}

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

$user_id = (int) $_SESSION['user_id'];

// Ensure two_factor_secret column exists (add if missing)
try {
    $result = $pdo->query("SHOW COLUMNS FROM users LIKE 'two_factor_secret'");
    if ($result->rowCount() === 0) {
        $pdo->exec("ALTER TABLE users ADD COLUMN two_factor_secret VARCHAR(255) DEFAULT NULL");
    }
    $result2 = $pdo->query("SHOW COLUMNS FROM users LIKE 'two_factor_enabled'");
    if ($result2->rowCount() === 0) {
        $pdo->exec("ALTER TABLE users ADD COLUMN two_factor_enabled TINYINT(1) NOT NULL DEFAULT 0");
    }
} catch (PDOException $e) {
    // Ignore if already exists
}

// ============================================
// 2. AJAX ROUTER (POST actions)
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action'])) {
    error_reporting(0);
    ini_set('display_errors', 0);
    if (ob_get_level()) ob_clean();
    header('Content-Type: application/json; charset=utf-8');

    $action = $_GET['action'];
    $submittedToken = $_POST['csrf_token'] ?? '';
    if (!hash_equals($csrf_token, $submittedToken)) {
        echo json_encode(['success' => false, 'message' => 'CSRF validation failed. Refresh the page.']);
        exit;
    }

    try {
        // ---------- UPDATE PROFILE (name, email, phone) ----------
        if ($action === 'update_profile') {
            $first_name = trim($_POST['first_name'] ?? '');
            $last_name  = trim($_POST['last_name'] ?? '');
            $email      = trim($_POST['email'] ?? '');
            $phone      = trim($_POST['phone'] ?? '');

            if (empty($first_name) || empty($last_name) || empty($email)) {
                echo json_encode(['success' => false, 'message' => 'First name, last name and email are required.']);
                exit;
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                echo json_encode(['success' => false, 'message' => 'Invalid email format.']);
                exit;
            }

            // Update only allowed fields, ensure is_active remains 1 and branch_id unchanged
            $stmt = $pdo->prepare("UPDATE users SET first_name = :fn, last_name = :ln, email = :em, phone = :ph, updated_at = NOW() WHERE id = :uid AND user_type = 'super_admin' AND is_active = 1");
            $stmt->execute([
                ':fn' => $first_name,
                ':ln' => $last_name,
                ':em' => $email,
                ':ph' => $phone,
                ':uid' => $user_id
            ]);
            if ($stmt->rowCount() === 0 && $stmt->rowCount() !== 0) {
                // If no rows affected but user exists, it's fine (data unchanged)
            }
            // Refresh session name
            $_SESSION['first_name'] = $first_name;
            $_SESSION['last_name'] = $last_name;

            echo json_encode(['success' => true, 'message' => 'Profile updated successfully.']);
            exit;
        }

        // ---------- UPDATE PASSWORD (with current password verification) ----------
        if ($action === 'update_password') {
            $current_password = $_POST['current_password'] ?? '';
            $new_password     = $_POST['new_password'] ?? '';
            $confirm_password = $_POST['confirm_password'] ?? '';

            if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
                echo json_encode(['success' => false, 'message' => 'All password fields are required.']);
                exit;
            }
            if ($new_password !== $confirm_password) {
                echo json_encode(['success' => false, 'message' => 'New password and confirmation do not match.']);
                exit;
            }
            if (strlen($new_password) < 8) {
                echo json_encode(['success' => false, 'message' => 'Password must be at least 8 characters.']);
                exit;
            }

            // Fetch current hash
            $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE id = :uid AND user_type = 'super_admin' AND is_active = 1");
            $stmt->execute([':uid' => $user_id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row || !password_verify($current_password, $row['password_hash'])) {
                echo json_encode(['success' => false, 'message' => 'Current password is incorrect.']);
                exit;
            }

            $newHash = password_hash($new_password, PASSWORD_ARGON2ID);
            $updateStmt = $pdo->prepare("UPDATE users SET password_hash = :hash, updated_at = NOW() WHERE id = :uid");
            $updateStmt->execute([':hash' => $newHash, ':uid' => $user_id]);

            echo json_encode(['success' => true, 'message' => 'Password changed successfully.']);
            exit;
        }

        // ---------- TOGGLE 2FA (enable/disable) ----------
        if ($action === 'toggle_2fa') {
            $enable = (int) ($_POST['enable'] ?? 0);
            $token  = $_POST['token'] ?? '';

            if ($enable === 1) {
                // Enable 2FA: require token verification
                if (empty($token)) {
                    echo json_encode(['success' => false, 'message' => 'Verification code required to enable 2FA.']);
                    exit;
                }
                // Fetch current secret (should have been generated when QR was displayed)
                $stmt = $pdo->prepare("SELECT two_factor_secret FROM users WHERE id = :uid");
                $stmt->execute([':uid' => $user_id]);
                $secret = $stmt->fetchColumn();
                if (!$secret) {
                    echo json_encode(['success' => false, 'message' => 'No 2FA secret found. Please request setup again.']);
                    exit;
                }
                // Verify TOTP
                require_once __DIR__ . '/../../vendor/autoload.php'; // optional: if using a library
                // Manual verification using OTP library or built-in function? We'll implement a simple TOTP class.
                // For reliability, we'll use the built-in function via a simple TOTP verifier.
                $valid = verifyTOTP($secret, $token);
                if (!$valid) {
                    echo json_encode(['success' => false, 'message' => 'Invalid verification code.']);
                    exit;
                }
                $upd = $pdo->prepare("UPDATE users SET two_factor_enabled = 1, updated_at = NOW() WHERE id = :uid");
                $upd->execute([':uid' => $user_id]);
                echo json_encode(['success' => true, 'message' => 'Two-factor authentication enabled.']);
            } else {
                // Disable 2FA
                $upd = $pdo->prepare("UPDATE users SET two_factor_enabled = 0, two_factor_secret = NULL, updated_at = NOW() WHERE id = :uid");
                $upd->execute([':uid' => $user_id]);
                echo json_encode(['success' => true, 'message' => 'Two-factor authentication disabled.']);
            }
            exit;
        }

        // ---------- GENERATE 2FA SECRET (for QR) ----------
        if ($action === 'generate_2fa_secret') {
            // Generate new secret and store temporarily
            $secret = generateTOTPSecret();
            $upd = $pdo->prepare("UPDATE users SET two_factor_secret = :secret, two_factor_enabled = 0 WHERE id = :uid");
            $upd->execute([':secret' => $secret, ':uid' => $user_id]);

            $issuer = 'HMS-ERP';
            $label = 'SuperAdmin_' . $user_id;
            $otpauth = "otpauth://totp/{$issuer}:{$label}?secret={$secret}&issuer={$issuer}";
            $qrUrl = "https://quickchart.io/qr?text=" . urlencode($otpauth) . "&size=200";

            echo json_encode(['success' => true, 'secret' => $secret, 'qr_code' => $qrUrl]);
            exit;
        }

        echo json_encode(['success' => false, 'message' => 'Invalid action.']);
        exit;
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        exit;
    }
}

// ============================================
// 3. LOAD USER DATA & TELEMETRY
// ============================================
$user = [];
$branch_name = '';
$department_name = '';
$last_login_formatted = 'Never';

try {
    $stmt = $pdo->prepare("
        SELECT u.id, u.first_name, u.last_name, u.email, u.phone, u.username, u.last_login_at, u.is_active, u.two_factor_enabled,
               b.name AS branch_name, d.name AS department_name
        FROM users u
        LEFT JOIN branches b ON u.branch_id = b.id
        LEFT JOIN departments d ON u.department_id = d.id
        WHERE u.id = :uid AND u.user_type = 'super_admin'
    ");
    $stmt->execute([':uid' => $user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        die('Super Admin account not found.');
    }
    $branch_name = $user['branch_name'] ?? 'Headquarters';
    $department_name = $user['department_name'] ?? 'Administration';
    if ($user['last_login_at']) {
        $last_login_formatted = date('F j, Y, g:i A', strtotime($user['last_login_at']));
    }
} catch (PDOException $e) {
    die('Error loading profile.');
}

// Helper functions for TOTP
function generateTOTPSecret($length = 16) {
    $bytes = random_bytes($length);
    return strtoupper(substr(base32_encode($bytes), 0, $length));
}
function base32_encode($data) {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bin = '';
    foreach (str_split($data) as $char) {
        $bin .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
    }
    $result = '';
    foreach (str_split($bin, 5) as $chunk) {
        if (strlen($chunk) < 5) $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
        $result .= $alphabet[bindec($chunk)];
    }
    return $result;
}
function verifyTOTP($secret, $token) {
    // Simple TOTP verification using time window (30 sec)
    // We'll use a small library alternative: manual implementation with hash_hmac
    // Use OATH standard: 30 seconds window, 6 digits
    $timeSlice = floor(time() / 30);
    for ($i = -1; $i <= 1; $i++) {
        $check = $timeSlice + $i;
        $calc = oath_hotp($secret, $check);
        if ($calc == $token) return true;
    }
    return false;
}
function oath_hotp($secret, $counter) {
    $secret = base32_decode_fixed($secret);
    $counter = pack('N', $counter);
    $hash = hash_hmac('sha1', $counter, $secret, true);
    $offset = ord($hash[19]) & 0x0f;
    $code = ((ord($hash[$offset]) & 0x7f) << 24) |
            ((ord($hash[$offset+1]) & 0xff) << 16) |
            ((ord($hash[$offset+2]) & 0xff) << 8) |
            (ord($hash[$offset+3]) & 0xff);
    $code = $code % 1000000;
    return str_pad($code, 6, '0', STR_PAD_LEFT);
}
function base32_decode_fixed($input) {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $output = '';
    $buffer = 0;
    $bits = 0;
    for ($i = 0; $i < strlen($input); $i++) {
        $pos = strpos($alphabet, $input[$i]);
        if ($pos === false) continue;
        $buffer = ($buffer << 5) | $pos;
        $bits += 5;
        if ($bits >= 8) {
            $output .= chr(($buffer >> ($bits - 8)) & 0xFF);
            $bits -= 8;
        }
    }
    return $output;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Super Admin Profile Settings | HMS ERP</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root {
            --primary: #4361ee;
            --primary-dark: #3a0ca3;
            --success: #06d6a0;
            --danger: #ef476f;
            --gray-light: #e9ecef;
            --dark: #1a1a2e;
        }
        body {
            font-family: 'Inter', sans-serif;
            background: #f5f7fb;
            margin: 0;
            padding: 0;
        }
        .content-page {
            margin-left: 260px;
            margin-top: 70px;
            padding: 24px 32px;
            transition: 0.3s;
            min-height: calc(100vh - 70px);
            position: relative;
            z-index: 1;
            background: #f5f7fb;
        }
        body.erp-sidebar-collapsed .content-page { margin-left: 80px; }
        @media (max-width: 768px) {
            .content-page { margin-left: 0 !important; padding: 16px; }
        }
        .welcome-header {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--primary) 100%);
            border-radius: 24px;
            padding: 24px 32px;
            color: white;
            margin-bottom: 28px;
        }
        .profile-card {
            background: white;
            border-radius: 24px;
            border: 1px solid var(--gray-light);
            overflow: hidden;
            margin-bottom: 24px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }
        .profile-avatar {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 36px;
            color: white;
            margin-bottom: 16px;
        }
        .stat-badge {
            background: #f1f5f9;
            border-radius: 30px;
            padding: 6px 14px;
            font-size: 12px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .nav-tabs .nav-link {
            font-weight: 600;
            color: #475569;
            border: none;
            padding: 12px 20px;
        }
        .nav-tabs .nav-link.active {
            color: var(--primary);
            border-bottom: 2px solid var(--primary);
            background: transparent;
        }
        .form-control, .form-select {
            border-radius: 12px;
            border: 1px solid var(--gray-light);
            padding: 10px 14px;
        }
        .form-control:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(67,97,238,0.15);
        }
        .btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            border: none;
            border-radius: 14px;
            padding: 10px 24px;
            font-weight: 600;
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
        .loading-overlay.active { display: flex; }
        .spinner {
            width: 40px;
            height: 40px;
            border: 3px solid var(--gray-light);
            border-top-color: var(--primary);
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
        .qr-container {
            background: white;
            padding: 20px;
            border-radius: 20px;
            text-align: center;
            border: 1px solid var(--gray-light);
        }
        .badge-telemetry {
            background: #f1f5f9;
            padding: 8px 16px;
            border-radius: 40px;
            font-size: 12px;
        }
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
                        <div class="welcome-title"><i class="fas fa-user-shield me-2"></i>Profile Settings & Security Center</div>
                        <div class="welcome-subtitle">Manage your personal information, cryptographic credentials, and multi‑factor authentication.</div>
                    </div>
                    <div class="col-md-4 text-md-end mt-3 mt-md-0">
                        <div class="time-badge" style="background: rgba(255,255,255,0.15); padding: 8px 16px; border-radius: 12px;">
                            <i class="far fa-calendar-alt"></i> <?php echo date('l, F j, Y'); ?>
                            <i class="far fa-clock ms-2"></i> <?php echo date('g:i A'); ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row g-4">
                <!-- LEFT COLUMN: Profile Telemetry -->
                <div class="col-lg-4">
                    <div class="profile-card p-4 text-center">
                        <div class="profile-avatar mx-auto">
                            <i class="fas fa-crown"></i>
                        </div>
                        <h4 class="mt-3"><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></h4>
                        <p class="text-muted">Super Administrator</p>
                        <div class="d-flex justify-content-center gap-2 flex-wrap">
                            <div class="stat-badge"><i class="fas fa-building"></i> <?php echo htmlspecialchars($branch_name); ?></div>
                            <div class="stat-badge"><i class="fas fa-diagram-project"></i> <?php echo htmlspecialchars($department_name); ?></div>
                        </div>
                        <hr>
                        <div class="text-start mt-3">
                            <div class="mb-2"><i class="fas fa-user-circle me-2 text-primary"></i> <strong>Username:</strong> <?php echo htmlspecialchars($user['username']); ?></div>
                            <div class="mb-2"><i class="fas fa-envelope me-2 text-primary"></i> <strong>Email:</strong> <?php echo htmlspecialchars($user['email']); ?></div>
                            <div class="mb-2"><i class="fas fa-phone me-2 text-primary"></i> <strong>Phone:</strong> <?php echo htmlspecialchars($user['phone'] ?? 'Not set'); ?></div>
                        </div>
                        <hr>
                        <div class="alert alert-success d-flex align-items-center" role="alert">
                            <i class="fas fa-check-circle me-2"></i>
                            <div>Account Status: <strong>Active (Super Admin)</strong></div>
                        </div>
                        <div class="badge-telemetry w-100 text-center">
                            <i class="fas fa-history me-1"></i> Last login: <?php echo $last_login_formatted; ?>
                        </div>
                    </div>
                </div>

                <!-- RIGHT COLUMN: Tabs for Profile / Password / 2FA -->
                <div class="col-lg-8">
                    <div class="profile-card p-4">
                        <ul class="nav nav-tabs mb-4" id="settingsTab" role="tablist">
                            <li class="nav-item" role="presentation">
                                <button class="nav-link active" id="profile-tab" data-bs-toggle="tab" data-bs-target="#profile" type="button" role="tab"><i class="fas fa-user-edit me-2"></i>Personal Info</button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="password-tab" data-bs-toggle="tab" data-bs-target="#password" type="button" role="tab"><i class="fas fa-key me-2"></i>Change Password</button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="twofa-tab" data-bs-toggle="tab" data-bs-target="#twofa" type="button" role="tab"><i class="fas fa-shield-alt me-2"></i>Two-Factor Authentication</button>
                            </li>
                        </ul>
                        <div class="tab-content">
                            <!-- PROFILE TAB -->
                            <div class="tab-pane fade show active" id="profile" role="tabpanel">
                                <form id="profileForm">
                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <label class="form-label">First Name *</label>
                                            <input type="text" name="first_name" id="first_name" class="form-control" value="<?php echo htmlspecialchars($user['first_name']); ?>" required>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">Last Name *</label>
                                            <input type="text" name="last_name" id="last_name" class="form-control" value="<?php echo htmlspecialchars($user['last_name']); ?>" required>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">Email *</label>
                                            <input type="email" name="email" id="email" class="form-control" value="<?php echo htmlspecialchars($user['email']); ?>" required>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">Phone</label>
                                            <input type="text" name="phone" id="phone" class="form-control" value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>">
                                        </div>
                                        <div class="col-12">
                                            <hr>
                                            <button type="submit" class="btn btn-primary"><i class="fas fa-save me-2"></i>Save Profile Changes</button>
                                        </div>
                                    </div>
                                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                </form>
                            </div>

                            <!-- PASSWORD TAB -->
                            <div class="tab-pane fade" id="password" role="tabpanel">
                                <form id="passwordForm">
                                    <div class="mb-3">
                                        <label class="form-label">Current Password *</label>
                                        <input type="password" name="current_password" id="current_password" class="form-control" required autocomplete="off">
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">New Password * (min. 8 characters)</label>
                                        <input type="password" name="new_password" id="new_password" class="form-control" required>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Confirm New Password *</label>
                                        <input type="password" name="confirm_password" id="confirm_password" class="form-control" required>
                                    </div>
                                    <button type="submit" class="btn btn-primary"><i class="fas fa-lock me-2"></i>Update Password</button>
                                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                </form>
                            </div>

                            <!-- 2FA TAB -->
                            <div class="tab-pane fade" id="twofa" role="tabpanel">
                                <div id="twofaStatus">
                                    <?php if ($user['two_factor_enabled']): ?>
                                        <div class="alert alert-success">✅ Two-factor authentication is <strong>ENABLED</strong>. Your account is extra secure.</div>
                                        <button id="disable2faBtn" class="btn btn-danger"><i class="fas fa-times-circle me-2"></i>Disable 2FA</button>
                                    <?php else: ?>
                                        <div class="alert alert-warning">⚠️ Two-factor authentication is <strong>DISABLED</strong>. Enable it for enhanced security.</div>
                                        <div id="setup2faArea">
                                            <button id="setup2faBtn" class="btn btn-primary"><i class="fas fa-qrcode me-2"></i>Set Up Two-Factor Authentication</button>
                                        </div>
                                        <div id="qrcodeArea" style="display:none; margin-top:20px;" class="qr-container">
                                            <h6>Scan QR Code with Google Authenticator or any TOTP app</h6>
                                            <img id="qrCodeImg" src="" alt="QR Code" style="max-width:200px; margin:10px auto;">
                                            <div class="mt-2">
                                                <label>Manual secret key:</label>
                                                <code id="manualSecret"></code>
                                            </div>
                                            <div class="mt-3">
                                                <label>Enter verification code to enable 2FA</label>
                                                <input type="text" id="verificationToken" class="form-control" placeholder="6-digit code" maxlength="6">
                                                <button id="confirm2faBtn" class="btn btn-success mt-2"><i class="fas fa-check me-2"></i>Verify & Enable</button>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <input type="hidden" id="csrf_token_2fa" value="<?php echo $csrf_token; ?>">
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

<script>
function showLoading(show) {
    if (show) $('#loadingOverlay').addClass('active');
    else $('#loadingOverlay').removeClass('active');
}

// Profile update
$('#profileForm').on('submit', function(e) {
    e.preventDefault();
    showLoading(true);
    $.ajax({
        url: window.location.pathname + '?action=update_profile',
        method: 'POST',
        dataType: 'json',
        data: $(this).serialize(),
        success: function(res) {
            showLoading(false);
            if (res.success) {
                Swal.fire('Success', res.message, 'success').then(() => location.reload());
            } else {
                Swal.fire('Error', res.message, 'error');
            }
        },
        error: function() { showLoading(false); Swal.fire('Error', 'Network error', 'error'); }
    });
});

// Password change
$('#passwordForm').on('submit', function(e) {
    e.preventDefault();
    const newPwd = $('#new_password').val();
    const confirmPwd = $('#confirm_password').val();
    if (newPwd !== confirmPwd) {
        Swal.fire('Error', 'New password and confirmation do not match', 'error');
        return;
    }
    showLoading(true);
    $.ajax({
        url: window.location.pathname + '?action=update_password',
        method: 'POST',
        dataType: 'json',
        data: $(this).serialize(),
        success: function(res) {
            showLoading(false);
            if (res.success) {
                Swal.fire('Success', res.message, 'success').then(() => {
                    $('#passwordForm')[0].reset();
                });
            } else {
                Swal.fire('Error', res.message, 'error');
            }
        },
        error: function() { showLoading(false); Swal.fire('Error', 'Network error', 'error'); }
    });
});

// 2FA Setup
let generatedSecret = null;
$('#setup2faBtn').on('click', function() {
    showLoading(true);
    $.ajax({
        url: window.location.pathname + '?action=generate_2fa_secret',
        method: 'POST',
        dataType: 'json',
        data: { csrf_token: $('#csrf_token_2fa').val() },
        success: function(res) {
            showLoading(false);
            if (res.success) {
                generatedSecret = res.secret;
                $('#qrCodeImg').attr('src', res.qr_code);
                $('#manualSecret').text(res.secret);
                $('#qrcodeArea').show();
                $('#setup2faArea').hide();
            } else {
                Swal.fire('Error', res.message, 'error');
            }
        },
        error: function() { showLoading(false); Swal.fire('Error', 'Could not generate 2FA secret', 'error'); }
    });
});

$('#confirm2faBtn').on('click', function() {
    const token = $('#verificationToken').val();
    if (!token || token.length !== 6) {
        Swal.fire('Error', 'Please enter a valid 6-digit code', 'error');
        return;
    }
    showLoading(true);
    $.ajax({
        url: window.location.pathname + '?action=toggle_2fa',
        method: 'POST',
        dataType: 'json',
        data: {
            enable: 1,
            token: token,
            csrf_token: $('#csrf_token_2fa').val()
        },
        success: function(res) {
            showLoading(false);
            if (res.success) {
                Swal.fire('Success', res.message, 'success').then(() => location.reload());
            } else {
                Swal.fire('Error', res.message, 'error');
            }
        },
        error: function() { showLoading(false); Swal.fire('Error', 'Network error', 'error'); }
    });
});

$('#disable2faBtn').on('click', function() {
    Swal.fire({
        title: 'Disable 2FA?',
        text: 'Two-factor authentication will be turned off. Your account will be less secure.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        confirmButtonText: 'Yes, disable'
    }).then((result) => {
        if (result.isConfirmed) {
            showLoading(true);
            $.ajax({
                url: window.location.pathname + '?action=toggle_2fa',
                method: 'POST',
                dataType: 'json',
                data: {
                    enable: 0,
                    csrf_token: $('#csrf_token_2fa').val()
                },
                success: function(res) {
                    showLoading(false);
                    if (res.success) {
                        Swal.fire('Disabled', res.message, 'success').then(() => location.reload());
                    } else {
                        Swal.fire('Error', res.message, 'error');
                    }
                },
                error: function() { showLoading(false); Swal.fire('Error', 'Network error', 'error'); }
            });
        }
    });
});
</script>
</body>
</html>