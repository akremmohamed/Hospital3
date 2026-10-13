<?php
// ============================================
// LABORATORY NAVIGATION BAR - HMS ERP
// Strict multi-tenancy branch isolation | PHP 8.2+ type-safe
// Seamless integration with laboratory operational core modules
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
$user_type = $_SESSION['user_type'] ?? '';

$nav_user = [
    'id'       => $user_id,
    'name'     => 'Lab Staff',
    'role'     => 'Laboratorist',
    'picture'  => '',
    'specialty'=> 'Clinical Laboratory'
];
$branch_name = 'All Branches';

// ----------------------------------------------------------------------
// Laboratory Alert Counters (using correct schema column names)
// ----------------------------------------------------------------------
$stat_orders_count = 0;           // STAT priority orders not completed
$pending_collections_count = 0;   // status = 'ordered' (doctor ordered, pending collection)
$sample_collected_count = 0;      // status = 'collected' (sample received)
$in_progress_count = 0;           // status = 'in_progress' (analysis underway)
$unverified_results_count = 0;    // completed but not verified by pathologist
$low_stock_alerts_count = 0;      // reagents/consumables below reorder level
$critical_results_count = 0;      // critical results pending doctor review
$pending_payment_count = 0;       // orders with unpaid status
$total_lab_alerts = 0;
$pending_qc_count = 0;            // pending quality control checks
$overdue_tat_count = 0;           // tests exceeding turnaround time

// Notification list
$notifications = [];
$notification_count = 0;

try {
    if (!($pdo instanceof PDO) || $user_id <= 0) {
        throw new RuntimeException('Database connection or user ID invalid');
    }

    // ------------------------------------------------------------------
    // 1. User Profile (with specialization from staff_profiles)
    // ------------------------------------------------------------------
    $stmt = $pdo->prepare("
        SELECT 
            u.first_name, u.middle_name, u.last_name, u.user_type, u.picture, u.branch_id,
            sp.specialization, sp.job_title
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
        $nav_user['role'] = ucfirst(str_replace('_', ' ', $userRow['user_type'] ?? 'laboratorist'));
        $nav_user['picture'] = $userRow['picture'] ?? '';
        $nav_user['specialty'] = $userRow['specialization'] ?? $userRow['job_title'] ?? 'Clinical Laboratory';

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

    // ==============================
    // LABORATORY ALERTS QUERIES
    // Using correct column names from lab_orders table
    // ==============================
    $params_branch = [];
    if (!$is_global_admin && $branch_id > 0) {
        $params_branch[':branch_id'] = $branch_id;
    }

    // 1) STAT orders (priority = 'stat' and status != 'completed' and != 'cancelled')
    $sql = "SELECT COUNT(*) FROM lab_orders 
            WHERE priority = 'stat' 
              AND status NOT IN ('completed', 'cancelled', 'rejected') 
              AND deleted_at IS NULL";
    if (!$is_global_admin && $branch_id > 0) $sql .= " AND branch_id = :branch_id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params_branch);
    $stat_orders_count = (int)$stmt->fetchColumn();

    // 2) Pending specimen collection (status = 'ordered' OR 'pending')
    $sql = "SELECT COUNT(*) FROM lab_orders 
            WHERE status IN ('ordered', 'pending') 
              AND deleted_at IS NULL";
    if (!$is_global_admin && $branch_id > 0) $sql .= " AND branch_id = :branch_id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params_branch);
    $pending_collections_count = (int)$stmt->fetchColumn();

    // 3) Sample collected (status = 'collected') - awaiting processing
    $sql = "SELECT COUNT(*) FROM lab_orders 
            WHERE status = 'collected' 
              AND deleted_at IS NULL";
    if (!$is_global_admin && $branch_id > 0) $sql .= " AND branch_id = :branch_id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params_branch);
    $sample_collected_count = (int)$stmt->fetchColumn();

    // 4) In progress (status = 'in_progress')
    $sql = "SELECT COUNT(*) FROM lab_orders 
            WHERE status = 'in_progress' 
              AND deleted_at IS NULL";
    if (!$is_global_admin && $branch_id > 0) $sql .= " AND branch_id = :branch_id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params_branch);
    $in_progress_count = (int)$stmt->fetchColumn();

    // 5) Unverified results (status = 'completed' but lab_test_results not verified)
    $sql = "SELECT COUNT(DISTINCT lo.id) 
            FROM lab_orders lo
            INNER JOIN lab_test_results ltr ON lo.id = ltr.lab_order_id
            WHERE lo.status = 'completed'
              AND ltr.verified_by_user_id IS NULL
              AND lo.deleted_at IS NULL
              AND ltr.deleted_at IS NULL";
    if (!$is_global_admin && $branch_id > 0) $sql .= " AND lo.branch_id = :branch_id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params_branch);
    $unverified_results_count = (int)$stmt->fetchColumn();

    // 6) Critical results pending doctor review
    $sql = "SELECT COUNT(DISTINCT lo.id) 
            FROM lab_orders lo
            INNER JOIN lab_test_results ltr ON lo.id = ltr.lab_order_id
            WHERE ltr.is_critical = 1 
              AND ltr.doctor_reviewed_at IS NULL
              AND lo.deleted_at IS NULL
              AND ltr.deleted_at IS NULL";
    if (!$is_global_admin && $branch_id > 0) $sql .= " AND lo.branch_id = :branch_id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params_branch);
    $critical_results_count = (int)$stmt->fetchColumn();

    // 7) Pending payment orders
    $sql = "SELECT COUNT(*) FROM lab_orders 
            WHERE payment_status IN ('unpaid', 'partial') 
              AND status NOT IN ('cancelled', 'rejected')
              AND deleted_at IS NULL";
    if (!$is_global_admin && $branch_id > 0) $sql .= " AND branch_id = :branch_id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params_branch);
    $pending_payment_count = (int)$stmt->fetchColumn();

    // 8) Low stock alerts (from pharmacy_inventory for lab reagents)
    try {
        $sql = "SELECT COUNT(*) FROM pharmacy_inventory pi
                JOIN medicines_master mm ON pi.medicine_id = mm.id
                WHERE pi.quantity_in_hand <= pi.reorder_level
                  AND pi.deleted_at IS NULL
                  AND mm.deleted_at IS NULL
                  AND mm.is_active = 1";
        if (!$is_global_admin && $branch_id > 0) $sql .= " AND pi.branch_id = :branch_id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params_branch);
        $low_stock_alerts_count = (int)$stmt->fetchColumn();
    } catch (PDOException $e) {
        $low_stock_alerts_count = 0;
    }

    // 9) Overdue TAT - tests exceeding expected turnaround time
    try {
        $sql = "SELECT COUNT(DISTINCT lo.id) 
                FROM lab_orders lo
                WHERE lo.status NOT IN ('completed', 'cancelled', 'rejected')
                  AND lo.order_date <= DATE_SUB(CURDATE(), INTERVAL 3 DAY)
                  AND lo.deleted_at IS NULL";
        if (!$is_global_admin && $branch_id > 0) $sql .= " AND lo.branch_id = :branch_id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params_branch);
        $overdue_tat_count = (int)$stmt->fetchColumn();
    } catch (PDOException $e) {
        $overdue_tat_count = 0;
    }

    // 10) Pending Quality Control checks
    try {
        $sql = "SELECT COUNT(*) FROM lab_test_results 
                WHERE (is_abnormal = 1 OR is_critical = 1)
                  AND verified_by_user_id IS NULL
                  AND deleted_at IS NULL";
        if (!$is_global_admin && $branch_id > 0) {
            $sql .= " AND lab_order_id IN (SELECT id FROM lab_orders WHERE branch_id = :branch_id)";
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params_branch);
        $pending_qc_count = (int)$stmt->fetchColumn();
    } catch (PDOException $e) {
        $pending_qc_count = 0;
    }

    // Calculate total lab alerts
    $total_lab_alerts = $stat_orders_count + $pending_collections_count 
                      + $sample_collected_count + $unverified_results_count 
                      + $critical_results_count + $low_stock_alerts_count 
                      + $pending_payment_count + $overdue_tat_count
                      + $pending_qc_count;

    // ------------------------------------------------------------------
    // 11. Build Notification List
    // ------------------------------------------------------------------
    if ($stat_orders_count > 0) {
        $notifications[] = "⚠️ <strong>{$stat_orders_count}</strong> STAT Orders pending immediate action → <a href='lab_orders_queue.php?filter=stat' class='text-primary'>View STAT</a>";
    }
    if ($pending_collections_count > 0) {
        $notifications[] = "🧪 <strong>{$pending_collections_count}</strong> Orders awaiting specimen collection → <a href='lab_orders_queue.php?status=ordered' class='text-primary'>Collect now</a>";
    }
    if ($sample_collected_count > 0) {
        $notifications[] = "📋 <strong>{$sample_collected_count}</strong> Samples collected awaiting processing → <a href='lab_orders_queue.php?status=collected' class='text-primary'>Process now</a>";
    }
    if ($in_progress_count > 0) {
        $notifications[] = "🔬 <strong>{$in_progress_count}</strong> Tests currently in analysis";
    }
    if ($unverified_results_count > 0) {
        $notifications[] = "✅ <strong>{$unverified_results_count}</strong> Results pending pathologist verification → <a href='result_verification.php' class='text-primary'>Verify now</a>";
    }
    if ($critical_results_count > 0) {
        $notifications[] = "🚨 <strong>{$critical_results_count}</strong> CRITICAL results requiring doctor review → <a href='critical_results.php' class='text-danger'>Review now</a>";
    }
    if ($pending_payment_count > 0) {
        $notifications[] = "💰 <strong>{$pending_payment_count}</strong> Orders with pending payment → <a href='lab_orders_queue.php?payment=unpaid' class='text-warning'>Check billing</a>";
    }
    if ($low_stock_alerts_count > 0) {
        $notifications[] = "📦 <strong>{$low_stock_alerts_count}</strong> Reagents/consumables below reorder level → <a href='lab_inventory.php' class='text-warning'>Replenish</a>";
    }
    if ($overdue_tat_count > 0) {
        $notifications[] = "⏰ <strong>{$overdue_tat_count}</strong> Tests exceeding expected TAT → <a href='lab_orders_queue.php?filter=overdue' class='text-danger'>Review</a>";
    }
    if ($pending_qc_count > 0) {
        $notifications[] = "🔍 <strong>{$pending_qc_count}</strong> Abnormal/Critical results pending QC verification → <a href='quality_control.php' class='text-warning'>Verify QC</a>";
    }

    // 12. Unread system notifications (from notifications_tracker)
    try {
        $sql = "SELECT title, message FROM notifications_tracker 
                WHERE user_id = :user_id AND is_read = 0 AND deleted_at IS NULL 
                ORDER BY created_at DESC LIMIT 5";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':user_id' => $user_id]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $notifications[] = htmlspecialchars($row['title'] . ': ' . $row['message'], ENT_QUOTES, 'UTF-8');
        }
    } catch (PDOException $e) {
        // notifications_tracker might not exist
    }

    // 13. Pending internal referrals (if lab staff receives referrals)
    try {
        $sql = "SELECT 
                    pr.id,
                    CONCAT(u_referrer.first_name, ' ', u_referrer.last_name) AS referrer_name,
                    CONCAT(p.first_name, ' ', p.last_name) AS patient_name
                FROM patient_referrals pr
                INNER JOIN users u_referrer ON u_referrer.id = pr.referred_from_user_id
                INNER JOIN patients p ON p.id = pr.patient_id
                WHERE pr.referred_to_user_id = :doctor_id
                  AND pr.status = 'pending'
                  AND pr.deleted_at IS NULL
                LIMIT 3";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':doctor_id' => $user_id]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $notifications[] = "🔄 Referral from Dr. " . htmlspecialchars($row['referrer_name']) .
                               " for patient " . htmlspecialchars($row['patient_name']);
        }
    } catch (PDOException $e) {
        // patient_referrals might not exist
    }

    // Total notification count
    $notification_count = count($notifications);
    if ($notification_count === 0) {
        $notifications[] = '✅ No pending alerts or notifications';
    }

} catch (Throwable $e) {
    error_log('Lab Navbar critical error: ' . $e->getMessage());
    // Guarantee all variables are initialised
    $stat_orders_count = 0;
    $pending_collections_count = 0;
    $sample_collected_count = 0;
    $in_progress_count = 0;
    $unverified_results_count = 0;
    $critical_results_count = 0;
    $pending_payment_count = 0;
    $low_stock_alerts_count = 0;
    $overdue_tat_count = 0;
    $pending_qc_count = 0;
    $total_lab_alerts = 0;
    $notification_count = 0;
    $notifications = ['⚠️ System alert service temporarily unavailable'];
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Laboratory Portal | HMS ERP</title>
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
        
        /* Lab Quick Stats Badges */
        .lab-stats-badge {
            display: flex;
            align-items: center;
            gap: 4px;
            background: var(--light-gray);
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            color: var(--text-muted);
            text-decoration: none;
            transition: all 0.2s;
        }
        .lab-stats-badge:hover {
            background: var(--border-gray);
            text-decoration: none;
        }
        .lab-stats-badge .count {
            color: var(--text-dark);
            font-weight: 700;
        }
        .lab-stats-badge.critical .count { color: var(--danger); }
        .lab-stats-badge.warning .count { color: #d97706; }
        .lab-stats-badge.success .count { color: var(--success); }
        .lab-stats-badge.info .count { color: var(--info); }
        .lab-stats-badge.overdue .count { color: #dc2626; animation: pulse-red 1.5s infinite; }
        
        .erp-quick-links { display: flex; gap: 6px; align-items: center; flex-wrap: wrap; }
        
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
            animation: pulse-badge 2s infinite;
        }
        @keyframes pulse-badge {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.1); }
        }
        @keyframes pulse-red {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.6; }
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
            min-width: 340px;
            max-height: 420px;
            overflow-y: auto;
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
        .dropdown-header { 
            padding: 12px 16px; 
            font-weight: 700; 
            font-size: 11px; 
            color: var(--text-muted); 
            border-bottom: 1px solid var(--border-gray); 
            text-transform: uppercase;
            position: sticky;
            top: 0;
            background: white;
            z-index: 2;
        }
        .dropdown-item-text { 
            padding: 10px 16px; 
            font-size: 13px; 
            color: var(--text-dark); 
            border-bottom: 1px solid var(--border-gray); 
            line-height: 1.4; 
        }
        .dropdown-item-text a { text-decoration: none; font-weight: 500; }
        .dropdown-item-text a:hover { text-decoration: underline; }
        .dropdown-item-text .alert-dot {
            display: inline-block;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            margin-right: 8px;
        }
        .dropdown-item-text .alert-dot.danger { background: var(--danger); }
        .dropdown-item-text .alert-dot.warning { background: #d97706; }
        .dropdown-item-text .alert-dot.info { background: var(--info); }
        .dropdown-item-text .alert-dot.success { background: var(--success); }
        
        .dropdown-item { 
            padding: 10px 16px; 
            display: block; 
            font-size: 13px; 
            color: var(--text-dark); 
            text-decoration: none; 
            transition: background 0.2s; 
        }
        .dropdown-item:hover { background: var(--light-gray); text-decoration: none; color: var(--primary); }
        .dropdown-divider { height: 1px; background: var(--border-gray); margin: 6px 0; }
        .text-danger { color: var(--danger) !important; }
        .text-warning { color: #d97706 !important; }
        .badge-warning { background: var(--warning); color: #92400e; }
        .badge-danger { background: var(--danger); color: white; }
        .badge-info { background: var(--info); color: #0c4e6e; }
        .badge-critical { background: #dc2626; color: white; animation: pulse-red 1.5s infinite; }
        
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
            .erp-topbar { padding: 0 12px; }
            .erp-profile-btn { padding: 4px 8px; max-width: 150px; }
            .erp-user-meta { display: none; }
            .erp-dropdown-menu { min-width: 300px; right: -60px; }
        }
        /* Dropdown scrollbar */
        .erp-dropdown-menu::-webkit-scrollbar { width: 4px; }
        .erp-dropdown-menu::-webkit-scrollbar-thumb { background: var(--border-gray); border-radius: 4px; }
        
        /* Notification count badge animation */
        .badge-pulse {
            animation: pulse-badge 2s infinite;
        }
    </style>
</head>
<body>

<div class="erp-topbar">
    <div class="erp-topbar-left">
        <button type="button" class="mobile-menu-toggle" id="mobileMenuToggle" title="Menu"><i class="fas fa-bars"></i></button>
        <button type="button" class="desktop-toggle-btn" id="desktopSidebarToggle" title="Toggle menu"><i class="fas fa-bars"></i></button>
        <div class="erp-brand">
            <div class="erp-logo"><i class="fas fa-flask"></i></div>
            <div class="erp-brand-text">
                <p class="erp-brand-title">🔬 Laboratory Portal</p>
                <p class="erp-branch"><i class="fas fa-map-marker-alt" style="font-size:8px;"></i> <?php echo htmlspecialchars($branch_name, ENT_QUOTES, 'UTF-8'); ?></p>
            </div>
        </div>
    </div>

    <div class="erp-topbar-right">

        <!-- Notifications Dropdown -->
        <div class="erp-notif">
            <button class="erp-icon-btn" id="notificationBtn" type="button" style="border:1px solid var(--border-gray);background:#fff;border-radius:50%;width:40px;height:40px;display:inline-flex;align-items:center;justify-content:center;cursor:pointer;position:relative;">
                <i class="fas fa-bell"></i>
                <?php if ($total_lab_alerts > 0): ?>
                    <span class="badge badge-pulse"><?php echo (int)$total_lab_alerts; ?></span>
                <?php endif; ?>
            </button>
            <div class="erp-dropdown-menu" id="notificationMenu">
                <div class="dropdown-header">
                    <i class="fas fa-bell me-2"></i> Lab Alerts & Notifications
                    <span style="float:right;font-weight:400;font-size:10px;color:var(--text-muted);">
                        <?php echo $total_lab_alerts; ?> active
                    </span>
                </div>
                <?php foreach ($notifications as $item): ?>
                    <div class="dropdown-item-text"><?php echo $item; ?></div>
                <?php endforeach; ?>
                <div class="dropdown-divider"></div>
                <a class="dropdown-item" href="notifications_manager.php"><i class="fas fa-arrow-right"></i> View all notifications</a>
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
                <a class="dropdown-item" href="dashboard.php"><i class="fas fa-chart-pie"></i> Lab Dashboard</a>
                <a class="dropdown-item" href="lab_orders_queue.php"><i class="fas fa-list"></i> Orders Queue</a>
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