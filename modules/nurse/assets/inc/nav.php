<?php
// ============================================
// NURSING NAVIGATION BAR - HMS ERP (Enterprise PDO Version)
// ============================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../../../config/db_connection.php';
require_once __DIR__ . '/../../../../includes/auth_helper.php';

$user_id = (int)($_SESSION['user_id'] ?? 0);
$branch_id = (int)($_SESSION['branch_id'] ?? 0);
$is_global_admin = ($_SESSION['user_type'] ?? '') === 'super_admin';

$nav_user = ['id' => $user_id, 'name' => 'Nurse User', 'role' => 'Nurse', 'picture' => ''];
$branch_name = 'All Branches';
$notification_count = 0;
$notifications = [];
$reminder_count = 0;
$emergency_count = 0;
$queue_count = 0;
$pending_vitals = 0;
$medication_alerts = 0;
$occupied_beds = 0;

try {
    if ($pdo instanceof PDO && $user_id > 0) {
        $stmt = $pdo->prepare("SELECT first_name, middle_name, last_name, user_type, picture, branch_id FROM users WHERE id = :id AND deleted_at IS NULL");
        $stmt->execute([':id' => $user_id]);
        $userRow = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($userRow) {
            $fullName = trim(($userRow['first_name'] ?? '') . ' ' . ($userRow['middle_name'] ?? '') . ' ' . ($userRow['last_name'] ?? ''));
            $fullName = preg_replace('/\s+/', ' ', $fullName);
            $nav_user['name'] = $fullName ?: $nav_user['name'];
            $nav_user['role'] = ucfirst(str_replace('_', ' ', $userRow['user_type'] ?? 'nurse'));
            $nav_user['picture'] = $userRow['picture'] ?? '';
            if ($branch_id <= 0 && !empty($userRow['branch_id'])) {
                $branch_id = (int)$userRow['branch_id'];
                $_SESSION['branch_id'] = $branch_id;
            }
        }

        if ($branch_id > 0) {
            $stmt = $pdo->prepare("SELECT name FROM branches WHERE id = :id AND deleted_at IS NULL");
            $stmt->execute([':id' => $branch_id]);
            $branch = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($branch) $branch_name = $branch['name'];
        }

        // Occupied beds
        $sql = "SELECT COUNT(*) FROM beds WHERE status = 'occupied' AND deleted_at IS NULL";
        $params = [];
        if (!$is_global_admin && $branch_id > 0) {
            $sql .= " AND branch_id = :branch_id";
            $params[':branch_id'] = $branch_id;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $occupied_beds = (int)$stmt->fetchColumn();

        // Pending vitals
        $sql = "
            SELECT COUNT(DISTINCT a.patient_id)
            FROM appointments a
            LEFT JOIN triage_records tr ON tr.patient_id = a.patient_id AND DATE(tr.triage_time) = CURDATE()
            WHERE a.appointment_date = CURDATE()
              AND a.status IN ('checked_in', 'in_progress')
              AND a.deleted_at IS NULL
              AND tr.id IS NULL
        ";
        $params = [];
        if (!$is_global_admin && $branch_id > 0) {
            $sql .= " AND a.branch_id = :branch_id";
            $params[':branch_id'] = $branch_id;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $pending_vitals = (int)$stmt->fetchColumn();

        // Medication alerts
        $sql = "SELECT COUNT(*) FROM prescriptions WHERE status IN ('active', 'partial') AND deleted_at IS NULL";
        $params = [];
        if (!$is_global_admin && $branch_id > 0) {
            $sql .= " AND branch_id = :branch_id";
            $params[':branch_id'] = $branch_id;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $medication_alerts = (int)$stmt->fetchColumn();

        // New appointments today
        $sql = "SELECT COUNT(*) FROM appointments WHERE DATE(created_at) = CURDATE() AND deleted_at IS NULL";
        $params = [];
        if (!$is_global_admin && $branch_id > 0) {
            $sql .= " AND branch_id = :branch_id";
            $params[':branch_id'] = $branch_id;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $new_appts = (int)$stmt->fetchColumn();

        // Lab results ready
        $sql = "SELECT COUNT(*) FROM lab_orders WHERE status = 'completed' AND deleted_at IS NULL";
        $params = [];
        if (!$is_global_admin && $branch_id > 0) {
            $sql .= " AND branch_id = :branch_id";
            $params[':branch_id'] = $branch_id;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $ready_labs = (int)$stmt->fetchColumn();

        $notification_count = $new_appts + $ready_labs;
        $notifications = [];
        if ($new_appts > 0) $notifications[] = $new_appts . ' new appointment(s) booked today';
        if ($ready_labs > 0) $notifications[] = $ready_labs . ' lab result(s) ready';
        if (empty($notifications)) $notifications[] = 'No new alerts';

        // Reminders (tomorrow)
        $sql = "
            SELECT COUNT(*) FROM appointments
            WHERE appointment_date = DATE_ADD(CURDATE(), INTERVAL 1 DAY)
              AND status = 'scheduled' AND deleted_at IS NULL
        ";
        $params = [];
        if (!$is_global_admin && $branch_id > 0) {
            $sql .= " AND branch_id = :branch_id";
            $params[':branch_id'] = $branch_id;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $reminder_count = (int)$stmt->fetchColumn();

        // Live queue
        $sql = "
            SELECT COUNT(*) FROM appointments
            WHERE appointment_date = CURDATE()
              AND status IN ('checked_in', 'in_progress')
              AND deleted_at IS NULL
        ";
        $params = [];
        if (!$is_global_admin && $branch_id > 0) {
            $sql .= " AND branch_id = :branch_id";
            $params[':branch_id'] = $branch_id;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $queue_count = (int)$stmt->fetchColumn();

        // Emergency count (red triage)
        $sql = "
            SELECT COUNT(*) FROM triage_records
            WHERE priority = 'red' AND DATE(triage_time) = CURDATE() AND deleted_at IS NULL
        ";
        $params = [];
        if (!$is_global_admin && $branch_id > 0) {
            $sql .= " AND branch_id = :branch_id";
            $params[':branch_id'] = $branch_id;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $emergency_count = (int)$stmt->fetchColumn();
    }
} catch (PDOException $e) {
    error_log('Navbar counters error: ' . $e->getMessage());
}
?>
<!DOCTYPE html>
<html>
<head>
<style>
    /* (same CSS as before – no changes needed) */
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
    * { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; }
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
    .erp-logo { width: 36px; height: 36px; border-radius: 10px; background: linear-gradient(135deg, var(--primary), var(--primary-dark)); color: #fff; display: inline-flex; align-items: center; justify-content: center; font-size: 18px; }
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
        -webkit-tap-highlight-color: transparent;
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
        min-width: 240px;
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
    .dropdown-item-text { padding: 10px 16px; font-size: 13px; color: var(--text-dark); border-bottom: 1px solid var(--border-gray); }
    .dropdown-item { padding: 10px 16px; display: block; font-size: 13px; color: var(--text-dark); text-decoration: none; transition: background 0.2s; }
    .dropdown-item:hover { background: var(--light-gray); text-decoration: none; color: var(--primary); }
    .dropdown-divider { height: 1px; background: var(--border-gray); margin: 6px 0; }
    .text-danger { color: var(--danger) !important; }
    @media (max-width: 992px) { .erp-brand-text { display: none; } .erp-quick-links { display: none; } .desktop-toggle-btn { display: none; } .mobile-menu-toggle { display: inline-flex; } }
    @media (min-width: 993px) { .mobile-menu-toggle { display: none; } .desktop-toggle-btn { display: inline-flex; } }
    @media (max-width: 768px) { .erp-topbar { padding: 0 16px; } .erp-profile-btn { padding: 4px 8px; max-width: 150px; } .erp-user-meta { display: none; } }
</style>
</head>
<body>

<div class="erp-topbar">
    <div class="erp-topbar-left">
        <button type="button" class="mobile-menu-toggle" id="mobileMenuToggle" title="Menu">
            <i class="fas fa-bars"></i>
        </button>
        <button type="button" class="desktop-toggle-btn" id="desktopSidebarToggle" title="Toggle menu">
            <i class="fas fa-bars"></i>
        </button>
        <div class="erp-brand">
            <div class="erp-logo"><i class="fas fa-user-nurse"></i></div>
            <div class="erp-brand-text">
                <p class="erp-brand-title">Nursing Station</p>
                <p class="erp-branch"><?php echo htmlspecialchars($branch_name, ENT_QUOTES, 'UTF-8'); ?></p>
            </div>
        </div>
    </div>

    <div class="erp-topbar-right">
        <div class="erp-quick-links">
            <a href="nursing_bed_management.php" class="erp-quick-link"><i class="fas fa-bed"></i> Beds<?php if ($occupied_beds > 0): ?><span class="badge badge-info"><?php echo (int)$occupied_beds; ?></span><?php endif; ?></a>
            <a href="nursing_pending_vitals.php" class="erp-quick-link"><i class="fas fa-heartbeat"></i> Vitals<?php if ($pending_vitals > 0): ?><span class="badge badge-warning"><?php echo (int)$pending_vitals; ?></span><?php endif; ?></a>
            <a href="nursing_medication_alerts.php" class="erp-quick-link"><i class="fas fa-capsules"></i> Meds<?php if ($medication_alerts > 0): ?><span class="badge badge-danger"><?php echo (int)$medication_alerts; ?></span><?php endif; ?></a>
        </div>
        <div class="erp-quick-links">
            <a href="waiting_room_display.php" class="erp-quick-link"><i class="fas fa-users"></i> Queue<?php if ($queue_count > 0): ?><span class="badge badge-info"><?php echo (int)$queue_count; ?></span><?php endif; ?></a>
            <a href="emergency_alerts.php" class="erp-quick-link"><i class="fas fa-ambulance"></i> Emergency<?php if ($emergency_count > 0): ?><span class="badge badge-danger"><?php echo (int)$emergency_count; ?></span><?php endif; ?></a>
            <a href="notifications_manager.php" class="erp-quick-link"><i class="fas fa-bell"></i> Reminders<?php if ($reminder_count > 0): ?><span class="badge badge-warning"><?php echo (int)$reminder_count; ?></span><?php endif; ?></a>
        </div>

        <div class="erp-notif">
            <button class="erp-icon-btn" id="notificationBtn" type="button"><i class="fas fa-bell"></i></button>
            <?php if ($notification_count > 0): ?><span class="badge"><?php echo (int)$notification_count; ?></span><?php endif; ?>
            <div class="erp-dropdown-menu" id="notificationMenu">
                <div class="dropdown-header">Recent Alerts</div>
                <?php foreach ($notifications as $item): ?>
                    <div class="dropdown-item-text"><?php echo htmlspecialchars($item, ENT_QUOTES, 'UTF-8'); ?></div>
                <?php endforeach; ?>
                <div class="dropdown-divider"></div>
                <a class="dropdown-item" href="notifications_manager.php">View all notifications →</a>
            </div>
        </div>

        <div class="erp-notif">
            <button class="erp-profile-btn" id="profileBtn" type="button">
                <span class="erp-avatar"><?php echo mb_strtoupper(mb_substr($nav_user['name'], 0, 1, 'UTF-8'), 'UTF-8'); ?></span>
                <span class="erp-user-meta"><p class="erp-user-name"><?php echo htmlspecialchars($nav_user['name'], ENT_QUOTES, 'UTF-8'); ?></p><p class="erp-user-role"><?php echo htmlspecialchars($nav_user['role'], ENT_QUOTES, 'UTF-8'); ?></p></span>
                <i class="fas fa-chevron-down" style="font-size: 10px; color: var(--text-muted);"></i>
            </button>
            <div class="erp-dropdown-menu" id="profileMenu">
                <a class="dropdown-item" href="profile_settings.php"><i class="fas fa-user-cog"></i> Profile Settings</a>
                <a class="dropdown-item" href="activity_log.php"><i class="fas fa-history"></i> Activity Log</a>
                <div class="dropdown-divider"></div>
                <a class="dropdown-item text-danger" href="assets/inc/logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a>
            </div>
        </div>
    </div>
</div>

<script>
(function() {
    'use strict';
    const mobileToggle = document.getElementById('mobileMenuToggle');
    const desktopToggle = document.getElementById('desktopSidebarToggle');
    const notificationBtn = document.getElementById('notificationBtn');
    const notificationMenu = document.getElementById('notificationMenu');
    const profileBtn = document.getElementById('profileBtn');
    const profileMenu = document.getElementById('profileMenu');

    function toggleSidebar(e) {
        if (e) { e.preventDefault(); e.stopPropagation(); }
        if (window.innerWidth <= 992) {
            document.body.classList.toggle('sidebar-enable');
            document.body.style.overflow = document.body.classList.contains('sidebar-enable') ? 'hidden' : '';
        } else {
            const isCollapsed = document.body.classList.contains('erp-sidebar-collapsed');
            if (isCollapsed) {
                document.body.classList.remove('erp-sidebar-collapsed');
                localStorage.setItem('erp_sidebar_collapsed', 'false');
            } else {
                document.body.classList.add('erp-sidebar-collapsed');
                localStorage.setItem('erp_sidebar_collapsed', 'true');
            }
        }
    }
    if (mobileToggle) mobileToggle.addEventListener('click', toggleSidebar);
    if (desktopToggle) desktopToggle.addEventListener('click', toggleSidebar);

    if (notificationBtn && notificationMenu) {
        notificationBtn.addEventListener('click', function(e) { e.stopPropagation(); notificationMenu.classList.toggle('show'); if (profileMenu) profileMenu.classList.remove('show'); });
    }
    if (profileBtn && profileMenu) {
        profileBtn.addEventListener('click', function(e) { e.stopPropagation(); profileMenu.classList.toggle('show'); if (notificationMenu) notificationMenu.classList.remove('show'); });
    }

    document.addEventListener('click', function(e) {
        if (notificationMenu && notificationBtn && !notificationBtn.contains(e.target)) notificationMenu.classList.remove('show');
        if (profileMenu && profileBtn && !profileBtn.contains(e.target)) profileMenu.classList.remove('show');
    });

    window.addEventListener('resize', function() {
        if (window.innerWidth > 992 && document.body.classList.contains('sidebar-enable')) {
            document.body.classList.remove('sidebar-enable');
            document.body.style.overflow = '';
        }
    });
    const savedState = localStorage.getItem('erp_sidebar_collapsed');
    if (savedState === 'true' && window.innerWidth > 992) document.body.classList.add('erp-sidebar-collapsed');
    console.log('✅ Navbar V6 Loaded - mobile toggle ready');
})();
</script>
</body>
</html>