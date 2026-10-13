<?php
// ============================================
// DOCTOR NAVIGATION BAR - HMS ERP (Enterprise PDO Version v7)
// Strict multi-tenancy branch isolation | PHP 8.2+ type-safe
// Seamless integration with operational core modules
// ============================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../../../config/db_connection.php';
require_once __DIR__ . '/../../../../includes/auth_helper.php';

date_default_timezone_set('Africa/Addis_Ababa');
$pdo->exec("SET time_zone = '+03:00'");

// ----------------------------------------------------------------------
// Type-safe initialisations
// ----------------------------------------------------------------------
$user_id = (int)($_SESSION['user_id'] ?? 0);
$branch_id = (int)($_SESSION['branch_id'] ?? 0);
$is_global_admin = ($_SESSION['user_type'] ?? '') === 'super_admin';

$nav_user = [
    'id'       => $user_id,
    'name'     => 'Doctor User',
    'role'     => 'Doctor',
    'picture'  => '',
    'specialty'=> 'General Medicine'
];
$branch_name = 'All Branches';

$today_appointments = 0;
$waiting_patients = 0;
$admitted_patients = 0;
$critical_labs = 0;
$queue_count = 0;
$emergency_count = 0;
$reminder_count = 0;
$notification_count = 0;
$notifications = [];

try {
    if (!($pdo instanceof PDO) || $user_id <= 0) {
        throw new RuntimeException('Database connection or user ID invalid');
    }

    // ------------------------------------------------------------------
    // 1. Doctor Profile (with specialisation from staff_profiles)
    // ------------------------------------------------------------------
    $stmt = $pdo->prepare("
        SELECT 
            u.first_name, u.middle_name, u.last_name, u.user_type, u.picture, u.branch_id,
            sp.specialization
        FROM users u
        LEFT JOIN staff_profiles sp ON sp.user_id = u.id AND sp.deleted_at IS NULL
        WHERE u.id = :id AND u.deleted_at IS NULL
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
        $nav_user['name'] = $fullName ?: $nav_user['name'];
        $nav_user['role'] = ucfirst(str_replace('_', ' ', $userRow['user_type'] ?? 'doctor'));
        $nav_user['picture'] = $userRow['picture'] ?? '';
        $nav_user['specialty'] = $userRow['specialization'] ?? 'General Medicine';

        if ($branch_id <= 0 && !empty($userRow['branch_id'])) {
            $branch_id = (int)$userRow['branch_id'];
            $_SESSION['branch_id'] = $branch_id;
        }
    }

    // ------------------------------------------------------------------
    // 2. Branch Name
    // ------------------------------------------------------------------
    if ($branch_id > 0) {
        $stmt = $pdo->prepare("SELECT name FROM branches WHERE id = :id AND deleted_at IS NULL");
        $stmt->execute([':id' => $branch_id]);
        $branch = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($branch) $branch_name = $branch['name'];
    }

    // ------------------------------------------------------------------
    // 3. Today's Appointments (doctor-specific)
    // ------------------------------------------------------------------
    $sql = "SELECT COUNT(*) FROM appointments WHERE doctor_id = :doctor_id AND appointment_date = CURDATE() AND deleted_at IS NULL";
    $params = [':doctor_id' => $user_id];
    if (!$is_global_admin && $branch_id > 0) {
        $sql .= " AND branch_id = :branch_id";
        $params[':branch_id'] = $branch_id;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $today_appointments = (int)$stmt->fetchColumn();

    // ------------------------------------------------------------------
    // 4. Outpatient Waiting Queue (appointments pending/checked-in)
    // ------------------------------------------------------------------
    $sql = "SELECT COUNT(*) FROM appointments WHERE doctor_id = :doctor_id AND appointment_date = CURDATE() AND status IN ('pending', 'checked_in') AND deleted_at IS NULL";
    $params = [':doctor_id' => $user_id];
    if (!$is_global_admin && $branch_id > 0) {
        $sql .= " AND branch_id = :branch_id";
        $params[':branch_id'] = $branch_id;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $waiting_patients = (int)$stmt->fetchColumn();

    // ------------------------------------------------------------------
    // 5. Active Inpatients (admissions without discharge)
    // ------------------------------------------------------------------
    $sql = "SELECT COUNT(*) FROM admissions WHERE doctor_id = :doctor_id AND discharged_at IS NULL AND deleted_at IS NULL";
    $params = [':doctor_id' => $user_id];
    if (!$is_global_admin && $branch_id > 0) {
        $sql .= " AND branch_id = :branch_id";
        $params[':branch_id'] = $branch_id;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $admitted_patients = (int)$stmt->fetchColumn();

    // ------------------------------------------------------------------
    // 6. Critical Lab Orders (STAT + completed + pending doctor review)
    // ------------------------------------------------------------------
    $sql = "
        SELECT COUNT(DISTINCT lo.id)
        FROM lab_orders lo
        INNER JOIN lab_test_results ltr ON ltr.lab_order_id = lo.id
        WHERE lo.doctor_id = :doctor_id
          AND lo.status = 'completed'
          AND lo.priority = 'stat'
          AND ltr.doctor_reviewed_at IS NULL
          AND lo.deleted_at IS NULL
          AND ltr.deleted_at IS NULL
    ";
    $params = [':doctor_id' => $user_id];
    if (!$is_global_admin && $branch_id > 0) {
        $sql .= " AND lo.branch_id = :branch_id";
        $params[':branch_id'] = $branch_id;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $critical_labs = (int)$stmt->fetchColumn();

    // ------------------------------------------------------------------
    // 7. Live Queue (checked_in or in_consultation)
    // ------------------------------------------------------------------
    $sql = "SELECT COUNT(*) FROM appointments WHERE doctor_id = :doctor_id AND appointment_date = CURDATE() AND status IN ('checked_in', 'in_consultation') AND deleted_at IS NULL";
    $params = [':doctor_id' => $user_id];
    if (!$is_global_admin && $branch_id > 0) {
        $sql .= " AND branch_id = :branch_id";
        $params[':branch_id'] = $branch_id;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $queue_count = (int)$stmt->fetchColumn();

    // ------------------------------------------------------------------
    // 8. Emergency Status Tracker (Red triage priority for today)
    // ------------------------------------------------------------------
    $sql = "
        SELECT COUNT(DISTINCT a.patient_id)
        FROM appointments a
        INNER JOIN triage_records tr ON tr.visit_id = a.visit_id
        WHERE a.doctor_id = :doctor_id
          AND a.appointment_date = CURDATE()
          AND tr.priority = 'red'
          AND tr.deleted_at IS NULL
          AND a.deleted_at IS NULL
    ";
    $params = [':doctor_id' => $user_id];
    if (!$is_global_admin && $branch_id > 0) {
        $sql .= " AND a.branch_id = :branch_id";
        $params[':branch_id'] = $branch_id;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $emergency_count = (int)$stmt->fetchColumn();

    // ------------------------------------------------------------------
    // 9. Appointment Reminders (tomorrow's pending appointments)
    // ------------------------------------------------------------------
    $sql = "SELECT COUNT(*) FROM appointments WHERE doctor_id = :doctor_id AND appointment_date = DATE_ADD(CURDATE(), INTERVAL 1 DAY) AND status = 'pending' AND deleted_at IS NULL";
    $params = [':doctor_id' => $user_id];
    if (!$is_global_admin && $branch_id > 0) {
        $sql .= " AND branch_id = :branch_id";
        $params[':branch_id'] = $branch_id;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $reminder_count = (int)$stmt->fetchColumn();

    // ------------------------------------------------------------------
    // 10. Notification System – pending doctor referrals + unread alerts
    // ------------------------------------------------------------------
    // 10a. Pending internal referrals (referred_to_doctor_id = user)
    $sql = "
        SELECT 
            pr.id,
            CONCAT(u_referrer.first_name, ' ', u_referrer.last_name) AS referrer_name,
            CONCAT(p.first_name, ' ', p.last_name) AS patient_name
        FROM patient_referrals pr
        INNER JOIN users u_referrer ON u_referrer.id = pr.referred_from_user_id
        INNER JOIN patients p ON p.id = pr.patient_id
        WHERE pr.referred_to_user_id = :doctor_id
          AND pr.status = 'pending'
          AND pr.deleted_at IS NULL
    ";
    $params = [':doctor_id' => $user_id];
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $notifications[] = "🔄 Referral from Dr. " . htmlspecialchars($row['referrer_name']) .
                           " for patient " . htmlspecialchars($row['patient_name']);
    }

    // 10b. Unread system notifications (notifications_tracker)
    $sql = "SELECT title, message FROM notifications_tracker WHERE user_id = :user_id AND is_read = 0 AND deleted_at IS NULL ORDER BY created_at DESC LIMIT 10";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':user_id' => $user_id]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $notifications[] = htmlspecialchars($row['title'] . ': ' . $row['message'], ENT_QUOTES, 'UTF-8');
    }

    // 10c. Total notification count
    $notification_count = count($notifications);
    if ($notification_count === 0) {
        $notifications[] = 'No new alerts or pending referrals';
    }
} catch (Throwable $e) {
    error_log('Doctor Navbar critical error: ' . $e->getMessage());
    // Guarantee all variables are initialised (PHP 8.2 type-safety)
    $today_appointments = 0;
    $waiting_patients = 0;
    $admitted_patients = 0;
    $critical_labs = 0;
    $queue_count = 0;
    $emergency_count = 0;
    $reminder_count = 0;
    $notification_count = 0;
    $notifications = ['⚠️ System alert service temporarily unavailable'];
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Doctor Portal | HMS ERP</title>
    <!-- Font Awesome 6 (free CDN) for consistent icons -->
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
        <button type="button" class="mobile-menu-toggle" id="mobileMenuToggle" title="Menu"><i class="fas fa-bars"></i></button>
        <button type="button" class="desktop-toggle-btn" id="desktopSidebarToggle" title="Toggle menu"><i class="fas fa-bars"></i></button>
        <div class="erp-brand">
            <div class="erp-logo"><i class="fas fa-stethoscope"></i></div>
            <div class="erp-brand-text">
                <p class="erp-brand-title">Doctor Portal</p>
                <p class="erp-branch"><?php echo htmlspecialchars($branch_name, ENT_QUOTES, 'UTF-8'); ?></p>
            </div>
        </div>
    </div>

    <div class="erp-topbar-right">
        <!-- Quick Links with live clinical badges -->
        <div class="erp-quick-links">
            <a href="appointment_reminders.php" class="erp-quick-link"><i class="fas fa-bell"></i> Reminders<?php if ($reminder_count > 0): ?><span class="badge badge-warning"><?php echo (int)$reminder_count; ?></span><?php endif; ?></a>
        </div>

        <!-- Notifications Dropdown (real-time dynamic) -->
        <div class="erp-notif">
            <button class="erp-icon-btn" id="notificationBtn" type="button"><i class="fas fa-bell"></i></button>
            <?php if ($notification_count > 0): ?>
                <span class="badge"><?php echo (int)$notification_count; ?></span>
            <?php endif; ?>
            <div class="erp-dropdown-menu" id="notificationMenu">
                <div class="dropdown-header">Live Alerts &amp; Referrals</div>
                <?php foreach ($notifications as $item): ?>
                    <div class="dropdown-item-text"><?php echo htmlspecialchars($item, ENT_QUOTES, 'UTF-8'); ?></div>
                <?php endforeach; ?>
                <div class="dropdown-divider"></div>
                <a class="dropdown-item" href="notifications_manager.php">View all notifications →</a>
            </div>
        </div>

        <!-- Profile Dropdown -->
        <div class="erp-notif">
            <button class="erp-profile-btn" id="profileBtn" type="button">
                <span class="erp-avatar"><?php echo mb_strtoupper(mb_substr($nav_user['name'], 0, 1, 'UTF-8'), 'UTF-8'); ?></span>
                <span class="erp-user-meta">
                    <p class="erp-user-name"><?php echo htmlspecialchars($nav_user['name'], ENT_QUOTES, 'UTF-8'); ?></p>
                    <p class="erp-user-role"><?php echo htmlspecialchars($nav_user['specialty'], ENT_QUOTES, 'UTF-8'); ?></p>
                </span>
                <i class="fas fa-chevron-down" style="font-size: 10px; color: var(--text-muted);"></i>
            </button>
            <div class="erp-dropdown-menu" id="profileMenu">
                <a class="dropdown-item" href="profile_settings.php"><i class="fas fa-user-md"></i> My Profile</a>
                <a class="dropdown-item" href="doctor_schedule.php"><i class="fas fa-calendar-alt"></i> My Schedule</a>
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
})();
</script>
</body>
</html>