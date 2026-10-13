<?php
/**
 * Super Admin Navigation Bar - HMS ERP
 * Refactored for global command center with real-time telemetry, audit pulse, and security awareness.
 * Strict super_admin authentication, PDO prepared statements, and preserved UI/JS behavior.
 * FIXED: hamburger toggle, mobile scrolling, duplicate ID removed.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ============================================
// 1. IRONCLAD AUTHENTICATION GATE
// ============================================
if (!isset($_SESSION['user_id']) || ($_SESSION['user_type'] ?? '') !== 'super_admin') {
    header('Location: ../../index.php');
    exit;
}

require_once __DIR__ . '/../../../../config/db_connection.php';
require_once __DIR__ . '/../../../../includes/auth_helper.php';

date_default_timezone_set('Africa/Addis_Ababa');
$pdo->exec("SET time_zone = '+03:00'");

// CSRF protection token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// ----------------------------------------------------------------------
// Super Admin Session Variables
// ----------------------------------------------------------------------
$user_id   = (int)($_SESSION['user_id'] ?? 0);
$user_type = $_SESSION['user_type'] ?? 'super_admin';
$username  = $_SESSION['username'] ?? $_SESSION['first_name'] ?? 'Super Admin';
$full_name = $_SESSION['first_name'] && $_SESSION['last_name']
    ? $_SESSION['first_name'] . ' ' . $_SESSION['last_name']
    : $username;

// ----------------------------------------------------------------------
// Global Telemetry Metrics (Real-time)
// ----------------------------------------------------------------------
$critical_alerts_count = 0;
$live_inpatients       = 0;
$today_total_visits    = 0;
$recent_audits_count   = 0;

try {
    $sql = "SELECT COUNT(*) FROM lab_test_results WHERE is_critical = 1 AND DATE(created_at) = CURDATE() AND deleted_at IS NULL";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $critical_alerts_count = (int)$stmt->fetchColumn();

    $sql = "SELECT COUNT(*) FROM admissions WHERE status = 'admitted' AND deleted_at IS NULL";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $live_inpatients = (int)$stmt->fetchColumn();

    $sql = "SELECT COUNT(*) FROM patient_visits WHERE DATE(created_at) = CURDATE() AND deleted_at IS NULL";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $today_total_visits = (int)$stmt->fetchColumn();

    $sql = "SELECT COUNT(*) FROM audit_logs WHERE DATE(created_at) = CURDATE()";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $recent_audits_count = (int)$stmt->fetchColumn();

} catch (PDOException $e) {
    error_log('Super Admin Navbar Metric Error: ' . $e->getMessage());
}

// ----------------------------------------------------------------------
// Real-time Notification Drop-down Content (Recent Audit Logs)
// ----------------------------------------------------------------------
$notifications = [];
try {
    $sql = "SELECT al.action_type, al.target_table, al.ip_address, al.created_at, u.username
            FROM audit_logs al
            LEFT JOIN users u ON al.user_id = u.id
            ORDER BY al.created_at DESC
            LIMIT 5";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $icon = match ($row['action_type'] ?? '') {
            'INSERT' => 'fa-plus-circle',
            'UPDATE' => 'fa-pen',
            'DELETE' => 'fa-trash-alt',
            default => 'fa-shield-halved',
        };
        $actor = htmlspecialchars($row['username'] ?? 'System', ENT_QUOTES, 'UTF-8');
        $action = htmlspecialchars($row['action_type'] ?? 'ACTION', ENT_QUOTES, 'UTF-8');
        $table = htmlspecialchars($row['target_table'] ?? 'unknown', ENT_QUOTES, 'UTF-8');
        $time = date('H:i:s', strtotime($row['created_at']));
        $notifications[] = "<i class='fas {$icon}' style='margin-right: 8px;'></i> <strong>{$actor}</strong> {$action} on <code>{$table}</code> at {$time}";
    }
    if (empty($notifications)) {
        $notifications[] = "No recent audit events recorded.";
    }
} catch (PDOException $e) {
    $notifications[] = "⚠️ Audit log service temporarily unavailable.";
}
$notification_count = count($notifications);

$branch_context = "HQ Command Center (All Branches Hub)";
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <meta name="csrf-token" content="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
    <title>Super Admin Console | HMS ERP</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        :root {
            --primary: #4361ee;
            --primary-dark: #3a0ca3;
            --success: #06d6a0;
            --warning: #f9c74f;
            --danger: #ef476f;
            --info: #4cc9f0;
            --gray: #6b7280;
            --light-gray: #f3f4f6;
            --border-gray: #e5e7eb;
            --text-dark: #1f2937;
            --text-muted: #6b7280;
            --header-height: 70px;
        }
        * {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            box-sizing: border-box;
        }
        body {
            margin: 0;
            padding-top: var(--header-height);
            background: #f9fafb;
            overflow-x: hidden;
        }
        .erp-topbar {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1030;
            height: var(--header-height);
            background: #ffffff;
            border-bottom: 1px solid var(--border-gray);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 24px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
        }
        .erp-topbar-left, .erp-topbar-right { display: flex; align-items: center; gap: 16px; }
        .erp-brand { display: flex; align-items: center; gap: 12px; }
        .erp-logo {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: #fff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
        }
        .erp-brand-text { line-height: 1.2; }
        .erp-brand-title { margin: 0; font-size: 15px; font-weight: 700; color: var(--text-dark); }
        .erp-branch { margin: 0; font-size: 10px; color: var(--text-muted); }
        .mobile-menu-toggle, .desktop-toggle-btn {
            border: 1px solid var(--border-gray);
            background: #fff;
            color: var(--text-dark);
            border-radius: 10px;
            width: 40px;
            height: 40px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .mobile-menu-toggle:active, .desktop-toggle-btn:active { transform: scale(0.96); }
        .erp-quick-links { display: flex; gap: 8px; align-items: center; }
        .erp-quick-link {
            border: 1px solid var(--border-gray);
            background: #fff;
            color: var(--text-dark);
            border-radius: 30px;
            padding: 6px 14px;
            font-size: 12px;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.2s;
        }
        .erp-quick-link:hover { text-decoration: none; background: var(--light-gray); color: var(--primary); border-color: var(--primary); }
        .erp-notif { position: relative; }
        .erp-notif .badge {
            position: absolute;
            top: -5px;
            right: -5px;
            font-size: 9px;
            padding: 3px 5px;
            border-radius: 20px;
            background: var(--danger);
            color: white;
            min-width: 16px;
            text-align: center;
        }
        .erp-profile-btn {
            border: 1px solid var(--border-gray);
            background: #fff;
            border-radius: 40px;
            padding: 4px 12px 4px 6px;
            display: flex;
            align-items: center;
            gap: 10px;
            cursor: pointer;
            transition: all 0.2s;
        }
        .erp-avatar {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 14px;
        }
        .erp-user-meta { line-height: 1.2; }
        .erp-user-name { margin: 0; font-size: 13px; font-weight: 700; color: var(--text-dark); }
        .erp-user-role { margin: 0; font-size: 10px; color: var(--text-muted); }
        .erp-dropdown-menu {
            min-width: 280px;
            border-radius: 12px;
            border: 1px solid var(--border-gray);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
            background: white;
            display: none;
            position: absolute;
            top: 100%;
            right: 0;
            margin-top: 8px;
            z-index: 1031;
        }
        .erp-dropdown-menu.show { display: block; }
        .dropdown-header { padding: 12px 16px; font-weight: 700; font-size: 11px; color: var(--text-muted); border-bottom: 1px solid var(--border-gray); text-transform: uppercase; }
        .dropdown-item-text { padding: 10px 16px; font-size: 13px; color: var(--text-dark); border-bottom: 1px solid var(--border-gray); line-height: 1.4; }
        .dropdown-item-text a { text-decoration: none; font-weight: 500; }
        .dropdown-item-text a:hover { text-decoration: underline; }
        .dropdown-item { padding: 10px 16px; display: block; font-size: 13px; color: var(--text-dark); text-decoration: none; transition: background 0.2s; }
        .dropdown-item:hover { background: var(--light-gray); text-decoration: none; color: var(--primary); }
        .dropdown-divider { height: 1px; background: var(--border-gray); margin: 6px 0; }
        .text-danger { color: var(--danger) !important; }
        .badge-warning { background: var(--warning); color: #92400e; }
        .badge-danger { background: var(--danger); color: white; }
        .badge-info { background: var(--info); color: #0c4e6e; }
        .badge-critical { background: #dc2626; color: white; animation: pulse-red 1.5s infinite; }
        @keyframes pulse-red { 0%, 100% { opacity: 1; } 50% { opacity: 0.7; } }
        @media (max-width: 992px) {
            .erp-brand-text { display: none; }
            .erp-quick-links { display: none; }
            .desktop-toggle-btn { display: none; }
            .mobile-menu-toggle { display: inline-flex; }
        }
        @media (min-width: 993px) {
            .mobile-menu-toggle { display: none; }
            .desktop-toggle-btn { display: inline-flex; }
        }
        @media (max-width: 768px) {
            .erp-topbar { padding: 0 16px; }
            .erp-profile-btn { padding: 4px 8px; max-width: 150px; }
            .erp-user-meta { display: none; }
        }
    </style>
</head>
<body>

<div class="erp-topbar">
    <div class="erp-topbar-left">
        <!-- Mobile toggle button (different ID from sidebar's toggle) -->
        <button type="button" class="mobile-menu-toggle" id="navMobileToggle" title="Menu"><i class="fas fa-bars"></i></button>
        <button type="button" class="desktop-toggle-btn" id="desktopSidebarToggle" title="Toggle menu"><i class="fas fa-bars"></i></button>
        <div class="erp-brand">
            <div class="erp-logo"><i class="fas fa-crown"></i></div>
            <div class="erp-brand-text">
                <p class="erp-brand-title">Super Admin Console</p>
                <p class="erp-branch"><?php echo htmlspecialchars($branch_context, ENT_QUOTES, 'UTF-8'); ?></p>
            </div>
        </div>
    </div>

    <div class="erp-topbar-right">
        <div class="erp-quick-links">
            <a href="audit_logs_viewer.php" class="erp-quick-link"><i class="fas fa-shield-alt"></i> Audit Trail <?php if ($recent_audits_count > 0): ?><span class="badge badge-info"><?php echo (int)$recent_audits_count; ?></span><?php endif; ?></a>
            <a href="bed_capacity.php" class="erp-quick-link"><i class="fas fa-bed"></i> Inpatients <?php if ($live_inpatients > 0): ?><span class="badge badge-warning"><?php echo (int)$live_inpatients; ?></span><?php endif; ?></a>
            <a href="lab_critical_alerts.php" class="erp-quick-link"><i class="fas fa-flask"></i> Critical Labs <?php if ($critical_alerts_count > 0): ?><span class="badge badge-danger"><?php echo (int)$critical_alerts_count; ?></span><?php endif; ?></a>
        </div>

        <div class="erp-notif">
            <button class="erp-icon-btn" id="notificationBtn" type="button"><i class="fas fa-bell"></i></button>
            <?php if ($notification_count > 0): ?>
                <span class="badge"><?php echo (int)$notification_count; ?></span>
            <?php endif; ?>
            <div class="erp-dropdown-menu" id="notificationMenu">
                <div class="dropdown-header">Live Security Pulse (Recent Audit Logs)</div>
                <?php foreach ($notifications as $item): ?>
                    <div class="dropdown-item-text"><?php echo $item; ?></div>
                <?php endforeach; ?>
                <div class="dropdown-divider"></div>
                <a class="dropdown-item" href="audit_logs_viewer.php">View full audit trail →</a>
            </div>
        </div>

        <div class="erp-notif">
            <button class="erp-profile-btn" id="profileBtn" type="button">
                <span class="erp-avatar"><?php echo mb_strtoupper(mb_substr($full_name, 0, 1, 'UTF-8'), 'UTF-8'); ?></span>
                <span class="erp-user-meta">
                    <p class="erp-user-name"><?php echo htmlspecialchars($full_name, ENT_QUOTES, 'UTF-8'); ?></p>
                    <p class="erp-user-role">Super Admin</p>
                </span>
                <i class="fas fa-chevron-down" style="font-size: 10px; color: var(--text-muted);"></i>
            </button>
            <div class="erp-dropdown-menu" id="profileMenu">
                <a class="dropdown-item" href="profile_settings.php"><i class="fas fa-user-cog"></i> My Profile</a>
                <a class="dropdown-item" href="user_permission_rules.php"><i class="fas fa-shield-halved"></i> RBAC Manager</a>
                <a class="dropdown-item" href="audit_logs_viewer.php"><i class="fas fa-history"></i> Audit Logs</a>
                <div class="dropdown-divider"></div>
                <a class="dropdown-item text-danger" href="assets/inc/logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a>
            </div>
        </div>
    </div>
</div>

<script>
(function() {
    'use strict';
    
    document.addEventListener('DOMContentLoaded', function() {
        // Get elements
        const navMobileToggle = document.getElementById('navMobileToggle');
        const desktopToggle = document.getElementById('desktopSidebarToggle');
        const notificationBtn = document.getElementById('notificationBtn');
        const notificationMenu = document.getElementById('notificationMenu');
        const profileBtn = document.getElementById('profileBtn');
        const profileMenu = document.getElementById('profileMenu');
        const sidebarWrapper = document.getElementById('sidebar-wrapper');

        // Function to toggle sidebar on mobile
        function toggleMobileSidebar() {
            if (!sidebarWrapper) return;
            // Toggle the class that shows/hides sidebar
            document.body.classList.toggle('sidebar-enable');
            // Control body scroll
            if (document.body.classList.contains('sidebar-enable')) {
                document.body.style.overflow = 'hidden';
            } else {
                document.body.style.overflow = '';
            }
        }

        // Function to toggle sidebar on desktop (collapse/expand)
        function toggleDesktopSidebar() {
            const isCollapsed = document.body.classList.contains('erp-sidebar-collapsed');
            if (isCollapsed) {
                document.body.classList.remove('erp-sidebar-collapsed');
                localStorage.setItem('erp_sidebar_collapsed', 'false');
            } else {
                document.body.classList.add('erp-sidebar-collapsed');
                localStorage.setItem('erp_sidebar_collapsed', 'true');
            }
        }

        // Attach events
        if (navMobileToggle) {
            navMobileToggle.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                toggleMobileSidebar();
            });
        }

        if (desktopToggle) {
            desktopToggle.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                toggleDesktopSidebar();
            });
        }

        // Dropdown toggles
        if (notificationBtn && notificationMenu) {
            notificationBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                notificationMenu.classList.toggle('show');
                if (profileMenu) profileMenu.classList.remove('show');
            });
        }

        if (profileBtn && profileMenu) {
            profileBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                profileMenu.classList.toggle('show');
                if (notificationMenu) notificationMenu.classList.remove('show');
            });
        }

        // Close dropdowns on outside click
        document.addEventListener('click', function(e) {
            if (notificationMenu && notificationBtn && !notificationBtn.contains(e.target) && !notificationMenu.contains(e.target)) {
                notificationMenu.classList.remove('show');
            }
            if (profileMenu && profileBtn && !profileBtn.contains(e.target) && !profileMenu.contains(e.target)) {
                profileMenu.classList.remove('show');
            }
        });

        // On window resize, remove mobile sidebar if screen becomes desktop
        window.addEventListener('resize', function() {
            if (window.innerWidth > 992) {
                if (document.body.classList.contains('sidebar-enable')) {
                    document.body.classList.remove('sidebar-enable');
                    document.body.style.overflow = '';
                }
            }
        });

        // Restore desktop collapsed state
        const savedState = localStorage.getItem('erp_sidebar_collapsed');
        if (savedState === 'true' && window.innerWidth > 992) {
            document.body.classList.add('erp-sidebar-collapsed');
        }
    });
})();
</script>
</body>
</html>