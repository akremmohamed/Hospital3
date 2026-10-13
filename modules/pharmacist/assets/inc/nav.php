<?php
/**
 * Pharmacist Operational Header Navbar
 * Fixed hamburger menu toggle – works on desktop & mobile.
 *
 * @package HMS-ERP
 * @version 1.2
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ============================================
// 1. IRONCLAD ROLE VERIFICATION
// ============================================
if (!isset($_SESSION['user_id']) || !isset($_SESSION['branch_id']) || ($_SESSION['user_type'] ?? '') !== 'pharmacist') {
    die('Security Access Violation: Pharmacist credentials required.');
}

require_once __DIR__ . '/../../../../config/db_connection.php';
require_once __DIR__ . '/../../../../includes/auth_helper.php';

date_default_timezone_set('Africa/Addis_Ababa');
$pdo->exec("SET time_zone = '+03:00'");

// ----------------------------------------------------------------------
// Type‑safe initialisations
// ----------------------------------------------------------------------
$user_id   = (int)$_SESSION['user_id'];
$branch_id = (int)$_SESSION['branch_id'];

$pharmacist_name = 'Pharmacist';
$branch_name     = 'Pharmacy Hub';
$urgent_prescriptions_count = 0;
$critical_expiry_count      = 0;
$inbound_transfers_pending  = 0;
$notification_total         = 0;
$notifications              = [];

try {
    if (!($pdo instanceof PDO) || $user_id <= 0) {
        throw new RuntimeException('Database connection invalid');
    }

    // Pharmacist Profile
    $stmt = $pdo->prepare("
        SELECT first_name, middle_name, last_name, picture
        FROM users
        WHERE id = :id AND user_type = 'pharmacist' AND deleted_at IS NULL
    ");
    $stmt->execute([':id' => $user_id]);
    $userRow = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($userRow) {
        $fullName = trim(
            ($userRow['first_name'] ?? '') . ' ' .
            ($userRow['middle_name'] ?? '') . ' ' .
            ($userRow['last_name'] ?? '')
        );
        $fullName = preg_replace('/\s+/', ' ', $fullName);
        $pharmacist_name = $fullName ?: 'Pharmacist';
    }

    // Branch Name
    $stmt = $pdo->prepare("SELECT name FROM branches WHERE id = :id AND deleted_at IS NULL");
    $stmt->execute([':id' => $branch_id]);
    $branch = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($branch) $branch_name = $branch['name'];

    // Urgent Prescriptions Today
    $sql = "
        SELECT COUNT(*) AS total
        FROM prescriptions p
        INNER JOIN patient_visits pv ON p.visit_id = pv.id
        WHERE pv.branch_id = :branch_id
          AND p.status IN ('pending_payment', 'active')
          AND DATE(p.created_at) = CURDATE()
          AND p.deleted_at IS NULL
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':branch_id' => $branch_id]);
    $urgent_prescriptions_count = (int)$stmt->fetchColumn();

    // Critical Expiry Alerts
    $sql = "
        SELECT COUNT(DISTINCT pi.id) AS total
        FROM pharmacy_inventory pi
        WHERE pi.branch_id = :branch_id
          AND pi.deleted_at IS NULL
          AND pi.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 3 MONTH)
          AND pi.expiry_date >= CURDATE()
          AND pi.is_quarantined = 0
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':branch_id' => $branch_id]);
    $critical_expiry_count = (int)$stmt->fetchColumn();

    // Inbound Transfer Requests Pending
    $sql = "
        SELECT COUNT(*) AS total
        FROM branch_transfers
        WHERE to_branch_id = :branch_id
          AND transfer_type = 'pharmacy_stock'
          AND transfer_status = 'pending'
          AND deleted_at IS NULL
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':branch_id' => $branch_id]);
    $inbound_transfers_pending = (int)$stmt->fetchColumn();

    // Build Notifications Array
    if ($urgent_prescriptions_count > 0) {
        $notifications[] = [
            'type'  => 'prescription',
            'text'  => "⚠️ {$urgent_prescriptions_count} pending prescription(s) awaiting dispensation",
            'link'  => 'prescription_dispensary.php',
            'badge' => 'danger'
        ];
    }
    if ($critical_expiry_count > 0) {
        $notifications[] = [
            'type'  => 'expiry',
            'text'  => "📅 {$critical_expiry_count} item(s) expiring within 3 months",
            'link'  => 'pharmacy_inventory.php',
            'badge' => 'warning'
        ];
    }
    if ($inbound_transfers_pending > 0) {
        $notifications[] = [
            'type'  => 'transfer',
            'text'  => "🚚 {$inbound_transfers_pending} inbound stock transfer request(s) pending approval",
            'link'  => 'pharmacy_transfers.php',
            'badge' => 'info'
        ];
    }
    if (empty($notifications)) {
        $notifications[] = [
            'type'  => 'info',
            'text'  => 'No pending alerts. All pharmacy metrics are normal.',
            'link'  => '#',
            'badge' => 'secondary'
        ];
    }

    $notification_total = $urgent_prescriptions_count + $critical_expiry_count + $inbound_transfers_pending;

} catch (Throwable $e) {
    error_log('Pharmacy header critical error: ' . $e->getMessage());
    $urgent_prescriptions_count = 0;
    $critical_expiry_count      = 0;
    $inbound_transfers_pending  = 0;
    $notification_total         = 0;
    $notifications = [['text' => '⚠️ System alert service temporarily unavailable', 'link' => '#', 'badge' => 'secondary']];
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Pharmacy Control | HMS ERP</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        /* Your existing CSS (unchanged) – keep as is */
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
        .erp-icon-btn {
            border: 1px solid var(--border-gray);
            background: #fff;
            border-radius: 30px;
            width: 40px;
            height: 40px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s;
            font-size: 18px;
            color: var(--text-dark);
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
            min-width: 260px;
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
        .dropdown-item-text { padding: 10px 16px; font-size: 13px; color: var(--text-dark); border-bottom: 1px solid var(--border-gray); display: flex; align-items: center; gap: 8px; }
        .dropdown-item { padding: 10px 16px; display: flex; align-items: center; gap: 10px; font-size: 13px; color: var(--text-dark); text-decoration: none; transition: background 0.2s; }
        .dropdown-item:hover { background: var(--light-gray); text-decoration: none; color: var(--primary); }
        .dropdown-divider { height: 1px; background: var(--border-gray); margin: 6px 0; }
        .text-danger { color: var(--danger) !important; }
        .badge-warning { background: var(--warning); color: #92400e; }
        .badge-danger { background: var(--danger); color: white; }
        .badge-info { background: var(--info); color: #0c4e6e; }
        .badge-secondary { background: var(--gray); color: white; }
        @media (max-width: 992px) {
            .erp-brand-text { display: none; }
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
        <button type="button" class="mobile-menu-toggle" id="mobileMenuToggle" title="Menu"><i class="fas fa-bars"></i></button>
        <button type="button" class="desktop-toggle-btn" id="desktopSidebarToggle" title="Toggle menu"><i class="fas fa-bars"></i></button>
        <div class="erp-brand">
            <div class="erp-logo"><i class="fas fa-capsules"></i></div>
            <div class="erp-brand-text">
                <p class="erp-brand-title">Pharmacy Control Hub</p>
                <p class="erp-branch"><?php echo htmlspecialchars($branch_name, ENT_QUOTES, 'UTF-8'); ?></p>
            </div>
        </div>
    </div>

    <div class="erp-topbar-right">
        <!-- Notifications Dropdown -->
        <div class="erp-notif">
            <button class="erp-icon-btn" id="notificationBtn" type="button"><i class="fas fa-bell"></i></button>
            <?php if ($notification_total > 0): ?>
                <span class="badge"><?php echo (int)$notification_total; ?></span>
            <?php endif; ?>
            <div class="erp-dropdown-menu" id="notificationMenu">
                <div class="dropdown-header">Pharmacy Alerts &amp; Actions</div>
                <?php foreach ($notifications as $item): ?>
                    <a class="dropdown-item-text" href="<?php echo htmlspecialchars($item['link'], ENT_QUOTES, 'UTF-8'); ?>">
                        <span class="badge badge-<?php echo $item['badge']; ?>">●</span>
                        <?php echo htmlspecialchars($item['text'], ENT_QUOTES, 'UTF-8'); ?>
                    </a>
                <?php endforeach; ?>
                <div class="dropdown-divider"></div>
                <a class="dropdown-item" href="dashboard.php"><i class="fas fa-tachometer-alt"></i> Go to Dashboard</a>
            </div>
        </div>

        <!-- Profile Dropdown -->
        <div class="erp-notif">
            <button class="erp-profile-btn" id="profileBtn" type="button">
                <span class="erp-avatar"><?php echo mb_strtoupper(mb_substr($pharmacist_name, 0, 1, 'UTF-8'), 'UTF-8'); ?></span>
                <span class="erp-user-meta">
                    <p class="erp-user-name"><?php echo htmlspecialchars($pharmacist_name, ENT_QUOTES, 'UTF-8'); ?></p>
                    <p class="erp-user-role">On-Duty Pharmacist</p>
                </span>
                <i class="fas fa-chevron-down" style="font-size: 10px; color: var(--text-muted);"></i>
            </button>
            <div class="erp-dropdown-menu" id="profileMenu">
                <a class="dropdown-item" href="profile_settings.php"><i class="fas fa-user-md"></i> Profile Settings</a>
                <a class="dropdown-item" href="activity_log.php"><i class="fas fa-history"></i> Activity Log</a>
                <div class="dropdown-divider"></div>
                <a class="dropdown-item text-danger" href="assets/inc/logout.php"><i class="fas fa-sign-out-alt"></i> Secure Logout</a>
            </div>
        </div>
    </div>
</div>

<!-- SINGLE SIDEBAR TOGGLE SCRIPT – works on all devices -->
<script>
(function() {
    'use strict';

    // Wait for DOM to be fully ready
    document.addEventListener('DOMContentLoaded', function() {
        const mobileToggle = document.getElementById('mobileMenuToggle');
        const desktopToggle = document.getElementById('desktopSidebarToggle');
        const body = document.body;

        // Helper function to toggle sidebar
        function toggleSidebar(e) {
            if (e) {
                e.preventDefault();
                e.stopPropagation();
            }

            if (window.innerWidth <= 992) {
                // Mobile: show/hide overlay sidebar
                body.classList.toggle('sidebar-enable');
                body.style.overflow = body.classList.contains('sidebar-enable') ? 'hidden' : '';
            } else {
                // Desktop: collapse/expand sidebar
                const isCollapsed = body.classList.contains('erp-sidebar-collapsed');
                if (isCollapsed) {
                    body.classList.remove('erp-sidebar-collapsed');
                    localStorage.setItem('erp_sidebar_collapsed', 'false');
                } else {
                    body.classList.add('erp-sidebar-collapsed');
                    localStorage.setItem('erp_sidebar_collapsed', 'true');
                }
            }
        }

        // Attach event listeners to both hamburger buttons
        if (mobileToggle) mobileToggle.addEventListener('click', toggleSidebar);
        if (desktopToggle) desktopToggle.addEventListener('click', toggleSidebar);

        // Mobile overlay close (the overlay is created in sidebar.php)
        const overlay = document.querySelector('.sidebar-overlay');
        if (overlay) {
            overlay.addEventListener('click', function() {
                body.classList.remove('sidebar-enable');
                body.style.overflow = '';
            });
        }

        // Close mobile sidebar on Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && window.innerWidth <= 992 && body.classList.contains('sidebar-enable')) {
                body.classList.remove('sidebar-enable');
                body.style.overflow = '';
            }
        });

        // Restore desktop collapsed state from localStorage
        const savedState = localStorage.getItem('erp_sidebar_collapsed');
        if (savedState === 'true' && window.innerWidth > 992) {
            body.classList.add('erp-sidebar-collapsed');
        }

        // Notification and profile dropdown toggles
        const notificationBtn = document.getElementById('notificationBtn');
        const notificationMenu = document.getElementById('notificationMenu');
        const profileBtn = document.getElementById('profileBtn');
        const profileMenu = document.getElementById('profileMenu');

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

        // Close dropdowns when clicking outside
        document.addEventListener('click', function(e) {
            if (notificationMenu && notificationBtn && !notificationBtn.contains(e.target)) {
                notificationMenu.classList.remove('show');
            }
            if (profileMenu && profileBtn && !profileBtn.contains(e.target)) {
                profileMenu.classList.remove('show');
            }
        });
    });
})();
</script>
</body>
</html>