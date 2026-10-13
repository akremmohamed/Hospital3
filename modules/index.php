<?php
// Start session with security settings
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_only_cookies', 1);
    ini_set('session.cookie_secure', isset($_SERVER['HTTPS']) ? 1 : 0);
    ini_set('session.cookie_samesite', 'Strict');
    session_start();
}

// Database configuration - MATCHES YOUR EXPORT
define('DB_HOST', 'localhost');
define('DB_NAME', 'hms_erp');
define('DB_USER', 'root');
define('DB_PASS', '');

// Simple PDO connection
function getDB() {
    static $db = null;
    if ($db === null) {
        try {
            $db = new PDO(
                "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
                DB_USER,
                DB_PASS,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
        } catch (PDOException $e) {
            error_log("DB Error: " . $e->getMessage());
            return null;
        }
    }
    return $db;
}

// CSRF Token
function getCSRFToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

$error = '';
$success = '';

// Handle Login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'login') {
    $login = trim($_POST['login_input'] ?? '');
    $password = $_POST['password'] ?? '';
    $remember = isset($_POST['remember_me']);
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    
    if (empty($login) || empty($password)) {
        $error = 'Please enter both username/email and password.';
    } else {
        $db = getDB();
        
        if ($db) {
            try {
                $stmt = $db->prepare("
                    SELECT 
                        u.id, u.branch_id, u.username, u.email, u.password_hash,
                        u.first_name, u.last_name, u.user_type, u.is_active,
                        b.name as branch_name, b.status as branch_status
                    FROM users u
                    LEFT JOIN branches b ON u.branch_id = b.id
                    WHERE (u.email = :login OR u.username = :login)
                    AND u.deleted_at IS NULL
                ");
                $stmt->execute([':login' => $login]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$user) {
                    $error = 'Invalid username/email or password.';
                } elseif ($user['is_active'] != 1) {
                    $error = 'Your account is deactivated. Contact administrator.';
                } elseif (!password_verify($password, $user['password_hash'])) {
                    $error = 'Invalid username/email or password.';
                } else {
                    session_regenerate_id(true);
                    
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['branch_id'] = $user['branch_id'];
                    $_SESSION['branch_name'] = $user['branch_name'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['email'] = $user['email'];
                    $_SESSION['first_name'] = $user['first_name'];
                    $_SESSION['last_name'] = $user['last_name'];
                    $_SESSION['user_type'] = $user['user_type'];
                    $_SESSION['full_name'] = trim($user['first_name'] . ' ' . $user['last_name']);
                    $_SESSION['logged_in'] = true;
                    
                    $upd = $db->prepare("UPDATE users SET last_login_at = NOW(), last_login_ip = :ip WHERE id = :id");
                    $upd->execute([':ip' => $ip, ':id' => $user['id']]);
                    
                    $role = $user['user_type'];
                    if ($role === 'super_admin') {
                        header('Location: super_admin/dashboard.php');
                    } elseif ($role === 'receptionist') {
                        header('Location: reception/dashboard.php');
                    } elseif ($role === 'nurse') {
                        header('Location: nurse/dashboard.php');
                    } elseif ($role === 'doctor') {
                        header('Location: doctor/dashboard.php');
                    } elseif ($role === 'pharmacist') {
                        header('Location: pharmacist/dashboard.php');
                    } elseif ($role === 'laboratorist') {
                        header('Location: laboratory/dashboard.php');
                    } elseif ($role === 'radiologist') {
                        header('Location: radiologist/dashboard.php');
                    } elseif ($role === 'accountant') {
                        header('Location: finance/dashboard.php');
                    } else {
                        header('Location: dashboard.php');
                    }
                    exit();
                }
            } catch (PDOException $e) {
                error_log("Login error: " . $e->getMessage());
                $error = 'Database error. Please try again.';
            }
        } else {
            $error = 'Cannot connect to database.';
        }
    }
}

// Handle logout
if (isset($_GET['logout']) && $_GET['logout'] === 'success') {
    $success = 'You have been logged out successfully.';
    session_destroy();
    session_start();
}

// Check if already logged in
if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
    $role = $_SESSION['user_type'] ?? '';
    if ($role === 'super_admin') {
        header('Location: super_admin/dashboard.php');
    } elseif ($role === 'receptionist') {
        header('Location: reception/dashboard.php');
    } elseif ($role === 'nurse') {
        header('Location: nurse/dashboard.php');
    } elseif ($role === 'doctor') {
        header('Location: doctor/dashboard.php');
    } else {
        header('Location: dashboard.php');
    }
    exit();
}

$csrf_token = getCSRFToken();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HMS ERP - Login</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1rem;
        }
        .login-card {
            background: white;
            border-radius: 24px;
            box-shadow: 0 20px 35px -10px rgba(0,0,0,0.2);
            width: 100%;
            max-width: 440px;
        }
        .logo-wrapper {
            background: rgba(255,255,255,0.2);
            backdrop-filter: blur(4px);
            width: 80px;
            height: 80px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto;
        }
        .logo-icon {
            font-size: 40px;
            font-weight: 500;
            color: white;
        }
        .input-field {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            transition: all 0.2s;
            font-size: 14px;
        }
        .input-field:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102,126,234,0.1);
        }
        .btn-primary {
            background: #4f46e5;
            color: white;
            font-weight: 600;
            padding: 12px;
            border-radius: 12px;
            border: none;
            width: 100%;
            cursor: pointer;
            transition: background 0.2s;
        }
        .btn-primary:hover {
            background: #4338ca;
        }
        .alert-error {
            background: #fee2e2;
            border-left: 4px solid #dc2626;
            color: #b91c1c;
            padding: 10px 14px;
            border-radius: 10px;
            font-size: 13px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .alert-success {
            background: #dcfce7;
            border-left: 4px solid #16a34a;
            color: #166534;
            padding: 10px 14px;
            border-radius: 10px;
            font-size: 13px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .demo-info {
            background: #e0f2fe;
            border-left: 4px solid #0284c7;
            padding: 12px;
            border-radius: 12px;
            font-size: 12px;
            color: #0c4a6e;
            margin-bottom: 20px;
        }
        .demo-info code {
            background: #bae6fd;
            padding: 2px 6px;
            border-radius: 6px;
            font-family: monospace;
        }
        .toggle-pwd {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            cursor: pointer;
            color: #9ca3af;
        }
        .relative { position: relative; }
        .flex { display: flex; }
        .items-center { align-items: center; }
        .justify-between { justify-content: space-between; }
        .gap-3 { gap: 12px; }
        .mt-2 { margin-top: 8px; }
        .mb-2 { margin-bottom: 8px; }
        .mb-4 { margin-bottom: 16px; }
        .mb-6 { margin-bottom: 24px; }
        .mt-6 { margin-top: 24px; }
        .text-center { text-align: center; }
        .text-sm { font-size: 13px; }
        .text-xs { font-size: 11px; }
        .font-bold { font-weight: 700; }
        .cursor-pointer { cursor: pointer; }
        .w-4 { width: 16px; }
        .h-4 { height: 16px; }
        .ml-2 { margin-left: 8px; }
        .text-indigo-600 { color: #4f46e5; }
        .hover\:text-indigo-800:hover { color: #3730a3; }
        .text-gray-500 { color: #6b7280; }
        .text-gray-700 { color: #374151; }
        .text-white { color: white; }
        .text-white\/70 { color: rgba(255,255,255,0.7); }
        .text-white\/50 { color: rgba(255,255,255,0.5); }
        .rounded-full { border-radius: 9999px; }
        .mx-auto { margin-left: auto; margin-right: auto; }
        .p-8 { padding: 32px; }
        .pt-6 { padding-top: 24px; }
        .pb-6 { padding-bottom: 24px; }
        .px-4 { padding-left: 16px; padding-right: 16px; }
        .bg-white\/20 { background: rgba(255,255,255,0.2); }
        .backdrop-blur { backdrop-filter: blur(4px); }
        .shadow-lg { box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1); }
        button, input { font-family: inherit; }
    </style>
</head>
<body>
    <div style="width:100%; max-width:440px;">
        <!-- Logo area -->
        <div class="text-center mb-6">
            <div class="logo-wrapper">
                <span class="logo-icon">🏥</span>
            </div>
            <h1 class="text-white text-3xl font-bold mt-3">HMS ERP</h1>
            <p class="text-white/70 text-sm">Hospital Management System</p>
        </div>

        <!-- Login Card -->
        <div class="login-card p-8">
            <center>
            <h2 class="text-2xl font-bold text-gray-700 mb-2">Welcome Back</h2>
            <p class="text-gray-500 text-sm mb-6">Please login to your account</p>
             </center>
            <?php if ($error): ?>
                <div class="alert-error mb-4">
                    <span>⚠️</span> <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert-success mb-4">
                    <span>✅</span> <?php echo htmlspecialchars($success); ?>
                </div>
            <?php endif; ?>
            <form method="POST" action="">
                <input type="hidden" name="action" value="login">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">

                <div class="mb-4">
                    <label class="block text-gray-700 text-sm font-semibold mb-2">📧 Username or Email</label>
                    <input type="text" name="login_input" required class="input-field" placeholder="Enter username or email">
                </div>

                <div class="mb-4">
                    <label class="block text-gray-700 text-sm font-semibold mb-2">🔒 Password</label>
                    <div class="relative">
                        <input type="password" name="password" id="password" required class="input-field" placeholder="Enter password">
                        <button type="button" id="togglePassword" class="toggle-pwd" aria-label="Toggle password visibility">
                            👁️
                        </button>
                    </div>
                </div>

                <div class="flex items-center justify-between mb-6">
                    <label class="flex items-center cursor-pointer">
                        <input type="checkbox" name="remember_me" class="w-4 h-4 text-indigo-600 rounded">
                        <span class="ml-2 text-sm text-gray-600">Remember me</span>
                    </label>
                    <a href="#" id="forgotLink" class="text-sm text-indigo-600 hover:text-indigo-800">Forgot password?</a>
                </div>

                <button type="submit" class="btn-primary">
                    Sign In
                </button>
            </form>
        </div>

        <p class="text-center text-white/50 text-xs mt-6">© 2025 HMS ERP. All rights reserved.</p>
    </div>

    <!-- Forgot Password Modal -->
    <div id="forgotModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); align-items:center; justify-content:center; z-index:50; padding:1rem;">
        <div style="background:white; border-radius:24px; max-width:440px; width:100%; padding:24px;">
            <h3 style="font-size:1.5rem; font-weight:700; margin-bottom:8px;">Reset Password</h3>
            <p style="color:#6b7280; font-size:13px; margin-bottom:16px;">Enter your email and we'll send you a reset link.</p>
            <form method="POST">
                <input type="hidden" name="action" value="forgot">
                <input type="email" name="reset_email" required
                       style="width:100%; padding:12px 16px; border:1px solid #e2e8f0; border-radius:12px; margin-bottom:16px;"
                       placeholder="your@email.com">
                <div style="display:flex; gap:12px;">
                    <button type="button" id="closeModalBtn" style="flex:1; padding:10px; border:1px solid #d1d5db; border-radius:12px; background:white; cursor:pointer;">Cancel</button>
                    <button type="submit" style="flex:1; padding:10px; background:#4f46e5; color:white; border-radius:12px; border:none; cursor:pointer;">Send</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Toggle password visibility
        const toggleBtn = document.getElementById('togglePassword');
        const passwordField = document.getElementById('password');
        if (toggleBtn && passwordField) {
            toggleBtn.addEventListener('click', function() {
                const type = passwordField.getAttribute('type') === 'password' ? 'text' : 'password';
                passwordField.setAttribute('type', type);
                toggleBtn.textContent = type === 'password' ? '👁️' : '🙈';
            });
        }

        // Forgot password modal
        const modal = document.getElementById('forgotModal');
        const forgotLink = document.getElementById('forgotLink');
        const closeModalBtn = document.getElementById('closeModalBtn');

        if (forgotLink) {
            forgotLink.addEventListener('click', (e) => {
                e.preventDefault();
                if (modal) {
                    modal.style.display = 'flex';
                }
            });
        }
        if (closeModalBtn) {
            closeModalBtn.addEventListener('click', () => {
                if (modal) modal.style.display = 'none';
            });
        }
        if (modal) {
            modal.addEventListener('click', (e) => {
                if (e.target === modal) modal.style.display = 'none';
            });
        }
    </script>
</body>
</html>

<?php
// Handle forgot password
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'forgot') {
    $email = trim($_POST['reset_email'] ?? '');
    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $db = getDB();
        if ($db) {
            try {
                $stmt = $db->prepare("SELECT id, email FROM users WHERE email = :email AND deleted_at IS NULL");
                $stmt->execute([':email' => $email]);
                if ($stmt->fetch()) {
                    $token = bin2hex(random_bytes(32));
                    $db->exec("CREATE TABLE IF NOT EXISTS password_resets (
                        email VARCHAR(191) NOT NULL,
                        token VARCHAR(191) NOT NULL,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        INDEX idx_email (email)
                    )");
                    $ins = $db->prepare("INSERT INTO password_resets (email, token) VALUES (:email, :token) ON DUPLICATE KEY UPDATE token = :token, created_at = NOW()");
                    $ins->execute([':email' => $email, ':token' => $token]);
                    error_log("Password reset for: $email - token: $token");
                }
            } catch (PDOException $e) {
                error_log("Password reset error: " . $e->getMessage());
            }
        }
    }
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}
?>