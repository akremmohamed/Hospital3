<?php
/**
 * ======================================================================
 * DOCTOR DUTY ROTA & CLINIC TIMETABLE TRACKER - HMS ERP
 * Fixed: Removed non‑existent column 'assigned_room_number'
 * Strict multi‑tenancy | PHP 8.2+ type‑safe | Full AJAX support
 * ======================================================================
 */

session_start();

// ----------------------------------------------------------------------
// 1. BOOTSTRAP & SECURITY
// ----------------------------------------------------------------------
require_once __DIR__ . '/../../config/db_connection.php';
require_once __DIR__ . '/../../includes/auth_helper.php';

date_default_timezone_set('Africa/Addis_Ababa');
$pdo->exec("SET time_zone = '+03:00'");

$user_id = (int)($_SESSION['user_id'] ?? 0);
$branch_id = (int)($_SESSION['branch_id'] ?? 0);
$is_global_admin = ($_SESSION['user_type'] ?? '') === 'super_admin';
$doctor_name = $_SESSION['first_name'] ?? $_SESSION['username'] ?? 'Doctor';

if ($user_id <= 0) {
    header('Location: ../../index.php');
    exit;
}

// Permission check
$has_view_permission = user_has_permission($pdo, 'view_doctor_schedule', $user_id);
$has_manage_permission = user_has_permission($pdo, 'manage_duty_roster', $user_id);
if (!$is_global_admin && !$has_view_permission && !$has_manage_permission) {
    header('Location: dashboard.php?error=unauthorized');
    exit;
}

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// ----------------------------------------------------------------------
// Helper functions
// ----------------------------------------------------------------------
function jsonResponse($success, $message, $data = null, $code = 200) {
    if (ob_get_length()) ob_clean();
    http_response_code($code);
    header('Content-Type: application/json');
    $response = ['success' => $success, 'message' => $message];
    if ($data !== null) $response = array_merge($response, $data);
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

function escapeHtml($str) {
    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}

// ----------------------------------------------------------------------
// 2. AJAX HANDLERS
// ----------------------------------------------------------------------
if (isset($_GET['ajax']) && $_GET['ajax'] === 'schedule_action') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(false, 'Invalid request method');
    $action = $_POST['action'] ?? '';
    $incoming_token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($csrf_token, $incoming_token)) {
        jsonResponse(false, 'Security token mismatch');
    }

    try {
        // ---------- 2.1 Fetch Weekly Calendar ----------
        if ($action === 'fetch_weekly') {
            $week_offset = (int)($_POST['week_offset'] ?? 0);
            $now = new DateTime();
            if ($week_offset != 0) {
                $now->modify(($week_offset > 0 ? "+$week_offset weeks" : "$week_offset weeks"));
            }
            // Get Monday of current week
            $monday = clone $now;
            $monday->modify('monday this week');
            $sunday = clone $monday;
            $sunday->modify('+6 days');

            $start_date = $monday->format('Y-m-d');
            $end_date = $sunday->format('Y-m-d');

            $sql = "SELECT 
                        dr.id,
                        dr.duty_date,
                        dr.duty_type,
                        dr.start_time,
                        dr.end_time,
                        dr.notes,
                        st.name AS shift_name,
                        d.name AS department_name
                    FROM duty_rosters dr
                    LEFT JOIN shift_types st ON dr.shift_type_id = st.id
                    LEFT JOIN departments d ON dr.department_id = d.id
                    WHERE dr.user_id = :doctor_id
                      AND dr.duty_date BETWEEN :start_date AND :end_date
                      AND dr.deleted_at IS NULL";
            $params = [
                ':doctor_id' => $user_id,
                ':start_date' => $start_date,
                ':end_date' => $end_date
            ];
            if (!$is_global_admin && $branch_id > 0) {
                $sql .= " AND dr.branch_id = :branch_id";
                $params[':branch_id'] = $branch_id;
            }
            $sql .= " ORDER BY dr.duty_date ASC, dr.start_time ASC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rosters = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (!is_array($rosters)) $rosters = [];

            // Build week days array
            $week_days = [];
            for ($i = 0; $i < 7; $i++) {
                $day = clone $monday;
                $day->modify("+$i days");
                $week_days[] = [
                    'date' => $day->format('Y-m-d'),
                    'day_name' => $day->format('l'),
                    'display_date' => $day->format('M j, Y')
                ];
            }

            // Map rosters to days
            $rosters_by_date = [];
            foreach ($rosters as $roster) {
                $date = $roster['duty_date'];
                if (!isset($rosters_by_date[$date])) $rosters_by_date[$date] = [];
                $rosters_by_date[$date][] = $roster;
            }

            $week_display = $monday->format('M j') . ' - ' . $sunday->format('M j, Y');
            jsonResponse(true, '', [
                'week_days' => $week_days,
                'rosters_by_date' => $rosters_by_date,
                'week_display' => $week_display,
                'week_offset' => $week_offset
            ]);
        }

        // ---------- 2.2 Fetch Monthly Roster List ----------
        if ($action === 'fetch_monthly') {
            $month_offset = (int)($_POST['month_offset'] ?? 0);
            $now = new DateTime();
            if ($month_offset != 0) {
                $now->modify(($month_offset > 0 ? "+$month_offset months" : "$month_offset months"));
            }
            $year = $now->format('Y');
            $month = $now->format('m');
            $start_date = "$year-$month-01";
            $end_date = date('Y-m-t', strtotime($start_date));

            $sql = "SELECT 
                        dr.id,
                        dr.duty_date,
                        dr.duty_type,
                        dr.start_time,
                        dr.end_time,
                        dr.notes,
                        st.name AS shift_name,
                        d.name AS department_name
                    FROM duty_rosters dr
                    LEFT JOIN shift_types st ON dr.shift_type_id = st.id
                    LEFT JOIN departments d ON dr.department_id = d.id
                    WHERE dr.user_id = :doctor_id
                      AND dr.duty_date BETWEEN :start_date AND :end_date
                      AND dr.deleted_at IS NULL";
            $params = [
                ':doctor_id' => $user_id,
                ':start_date' => $start_date,
                ':end_date' => $end_date
            ];
            if (!$is_global_admin && $branch_id > 0) {
                $sql .= " AND dr.branch_id = :branch_id";
                $params[':branch_id'] = $branch_id;
            }
            $sql .= " ORDER BY dr.duty_date ASC, dr.start_time ASC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rosters = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (!is_array($rosters)) $rosters = [];

            $month_display = $now->format('F Y');
            jsonResponse(true, '', [
                'rosters' => $rosters,
                'month_display' => $month_display,
                'month_offset' => $month_offset,
                'year' => $year,
                'month' => $month
            ]);
        }

        // ---------- 2.3 Toggle On-Call Status for Today ----------
        if ($action === 'toggle_oncall') {
            if (!$has_manage_permission && !$is_global_admin) {
                jsonResponse(false, 'Insufficient permissions to modify duty status');
            }
            $today = date('Y-m-d');
            // Check if a duty roster exists for today
            $check_sql = "SELECT id, duty_type FROM duty_rosters 
                          WHERE user_id = :doctor_id AND duty_date = :today AND deleted_at IS NULL";
            $params = [':doctor_id' => $user_id, ':today' => $today];
            if (!$is_global_admin && $branch_id > 0) {
                $check_sql .= " AND branch_id = :branch_id";
                $params[':branch_id'] = $branch_id;
            }
            $stmt = $pdo->prepare($check_sql);
            $stmt->execute($params);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);

            $pdo->beginTransaction();
            try {
                if ($existing) {
                    $new_type = ($existing['duty_type'] === 'on_call') ? 'regular' : 'on_call';
                    $update_sql = "UPDATE duty_rosters SET duty_type = :duty_type, updated_at = NOW() WHERE id = :id";
                    $update_stmt = $pdo->prepare($update_sql);
                    $update_stmt->execute([':duty_type' => $new_type, ':id' => $existing['id']]);
                } else {
                    // Create a new duty roster entry for today with on-call status
                    $insert_sql = "INSERT INTO duty_rosters 
                                   (branch_id, department_id, user_id, shift_type_id, duty_date, duty_type, start_time, end_time, created_by_user_id, created_at)
                                   VALUES 
                                   (:branch_id, NULL, :user_id, NULL, :duty_date, 'on_call', '00:00:00', '23:59:59', :created_by, NOW())";
                    $insert_stmt = $pdo->prepare($insert_sql);
                    $insert_stmt->execute([
                        ':branch_id' => $branch_id,
                        ':user_id' => $user_id,
                        ':duty_date' => $today,
                        ':created_by' => $user_id
                    ]);
                    $new_type = 'on_call';
                }
                $pdo->commit();
                jsonResponse(true, 'On-call status updated', ['new_status' => $new_type]);
            } catch (PDOException $e) {
                $pdo->rollBack();
                jsonResponse(false, 'Database error: ' . $e->getMessage());
            }
        }

        jsonResponse(false, 'Unsupported action');
    } catch (PDOException $e) {
        jsonResponse(false, 'Database error: ' . $e->getMessage());
    }
    exit;
}

// ----------------------------------------------------------------------
// 3. INITIAL DATA LOAD (Type-safe)
// ----------------------------------------------------------------------
$today_duty = null;
$weekly_total_hours = 0;
$on_call_today = false;
$current_week_display = '';
$weekly_rosters = [];
$monthly_rosters = [];
$month_display = '';

try {
    // ---------- Today's Duty ----------
    $today = date('Y-m-d');
    $sql_today = "SELECT 
                        dr.duty_type,
                        dr.start_time,
                        dr.end_time,
                        st.name AS shift_name
                  FROM duty_rosters dr
                  LEFT JOIN shift_types st ON dr.shift_type_id = st.id
                  WHERE dr.user_id = :doctor_id AND dr.duty_date = :today AND dr.deleted_at IS NULL";
    $params = [':doctor_id' => $user_id, ':today' => $today];
    if (!$is_global_admin && $branch_id > 0) {
        $sql_today .= " AND dr.branch_id = :branch_id";
        $params[':branch_id'] = $branch_id;
    }
    $stmt = $pdo->prepare($sql_today);
    $stmt->execute($params);
    $today_duty = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($today_duty)) $today_duty = null;

    // ---------- Weekly Total Hours (current week Monday - Sunday) ----------
    $now = new DateTime();
    $monday = clone $now;
    $monday->modify('monday this week');
    $sunday = clone $monday;
    $sunday->modify('+6 days');
    $start_week = $monday->format('Y-m-d');
    $end_week = $sunday->format('Y-m-d');
    $current_week_display = $monday->format('M j') . ' - ' . $sunday->format('M j, Y');

    $sql_week = "SELECT start_time, end_time FROM duty_rosters 
                 WHERE user_id = :doctor_id 
                   AND duty_date BETWEEN :start_date AND :end_date 
                   AND deleted_at IS NULL";
    $params_week = [':doctor_id' => $user_id, ':start_date' => $start_week, ':end_date' => $end_week];
    if (!$is_global_admin && $branch_id > 0) {
        $sql_week .= " AND branch_id = :branch_id";
        $params_week[':branch_id'] = $branch_id;
    }
    $stmt = $pdo->prepare($sql_week);
    $stmt->execute($params_week);
    $week_rosters = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (is_array($week_rosters)) {
        foreach ($week_rosters as $roster) {
            if (!empty($roster['start_time']) && !empty($roster['end_time'])) {
                $start = new DateTime($roster['start_time']);
                $end = new DateTime($roster['end_time']);
                $interval = $start->diff($end);
                $hours = $interval->h + ($interval->days * 24);
                $weekly_total_hours += $hours;
            }
        }
    }

    // ---------- On-Call Status for Today ----------
    if ($today_duty && $today_duty['duty_type'] === 'on_call') {
        $on_call_today = true;
    }

    // ---------- Weekly Calendar Data (initial load for current week) ----------
    $sql_weekly = "SELECT 
                        dr.id,
                        dr.duty_date,
                        dr.duty_type,
                        dr.start_time,
                        dr.end_time,
                        dr.notes,
                        st.name AS shift_name,
                        d.name AS department_name
                   FROM duty_rosters dr
                   LEFT JOIN shift_types st ON dr.shift_type_id = st.id
                   LEFT JOIN departments d ON dr.department_id = d.id
                   WHERE dr.user_id = :doctor_id
                     AND dr.duty_date BETWEEN :start_date AND :end_date
                     AND dr.deleted_at IS NULL";
    $params_weekly = [
        ':doctor_id' => $user_id,
        ':start_date' => $start_week,
        ':end_date' => $end_week
    ];
    if (!$is_global_admin && $branch_id > 0) {
        $sql_weekly .= " AND dr.branch_id = :branch_id";
        $params_weekly[':branch_id'] = $branch_id;
    }
    $sql_weekly .= " ORDER BY dr.duty_date ASC, dr.start_time ASC";
    $stmt = $pdo->prepare($sql_weekly);
    $stmt->execute($params_weekly);
    $weekly_rosters = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($weekly_rosters)) $weekly_rosters = [];

    // Build week days for calendar
    $week_days = [];
    for ($i = 0; $i < 7; $i++) {
        $day = clone $monday;
        $day->modify("+$i days");
        $week_days[] = [
            'date' => $day->format('Y-m-d'),
            'day_name' => $day->format('l'),
            'display_date' => $day->format('M j, Y')
        ];
    }
    $rosters_by_date = [];
    foreach ($weekly_rosters as $roster) {
        $date = $roster['duty_date'];
        if (!isset($rosters_by_date[$date])) $rosters_by_date[$date] = [];
        $rosters_by_date[$date][] = $roster;
    }

    // ---------- Monthly Roster List (current month) ----------
    $current_month = date('Y-m');
    $month_start = date('Y-m-01');
    $month_end = date('Y-m-t');
    $sql_monthly = "SELECT 
                        dr.id,
                        dr.duty_date,
                        dr.duty_type,
                        dr.start_time,
                        dr.end_time,
                        dr.notes,
                        st.name AS shift_name,
                        d.name AS department_name
                    FROM duty_rosters dr
                    LEFT JOIN shift_types st ON dr.shift_type_id = st.id
                    LEFT JOIN departments d ON dr.department_id = d.id
                    WHERE dr.user_id = :doctor_id
                      AND dr.duty_date BETWEEN :start_date AND :end_date
                      AND dr.deleted_at IS NULL";
    $params_monthly = [
        ':doctor_id' => $user_id,
        ':start_date' => $month_start,
        ':end_date' => $month_end
    ];
    if (!$is_global_admin && $branch_id > 0) {
        $sql_monthly .= " AND dr.branch_id = :branch_id";
        $params_monthly[':branch_id'] = $branch_id;
    }
    $sql_monthly .= " ORDER BY dr.duty_date ASC, dr.start_time ASC";
    $stmt = $pdo->prepare($sql_monthly);
    $stmt->execute($params_monthly);
    $monthly_rosters = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($monthly_rosters)) $monthly_rosters = [];

    $month_display = date('F Y');
} catch (PDOException $e) {
    error_log('Doctor Schedule init error: ' . $e->getMessage());
    // Ensure variables are arrays to avoid count() errors
    $weekly_rosters = [];
    $rosters_by_date = [];
    $week_days = [];
    $monthly_rosters = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include('assets/inc/head.php'); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Doctor Schedule | Duty Rota & Timetable</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <style>
        :root {
            --primary: #4361ee;
            --primary-dark: #3a0ca3;
            --success: #06d6a0;
            --warning: #f9c74f;
            --danger: #ef476f;
            --info: #4cc9f0;
            --dark: #1a1a2e;
            --gray: #6c757d;
            --gray-light: #e9ecef;
        }
        body { font-family: 'Inter', sans-serif; background: #f5f7fb; overflow-x: hidden; }
        .content-page { margin-left: 260px; margin-top: 70px; padding: 24px 32px; transition: margin-left 0.3s; min-height: calc(100vh - 70px); }
        body.erp-sidebar-collapsed .content-page { margin-left: 80px; }
        @media (max-width: 768px) { .content-page { margin-left: 0 !important; padding: 16px; } }
        .page-title { font-size: 24px; font-weight: 700; color: var(--dark); margin-bottom: 24px; }
        .stats-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin-bottom: 28px; }
        @media (max-width: 768px) { .stats-grid { grid-template-columns: 1fr; } }
        .stat-card { background: white; border-radius: 20px; padding: 20px; border: 1px solid var(--gray-light); transition: all 0.3s; }
        .stat-card:hover { transform: translateY(-3px); box-shadow: 0 10px 25px rgba(0,0,0,0.05); }
        .stat-icon { width: 48px; height: 48px; border-radius: 14px; display: flex; align-items: center; justify-content: center; font-size: 22px; margin-bottom: 16px; }
        .stat-icon.primary { background: rgba(67,97,238,0.1); color: var(--primary); }
        .stat-icon.success { background: rgba(6,214,160,0.1); color: var(--success); }
        .stat-icon.warning { background: rgba(249,199,79,0.1); color: var(--warning); }
        .stat-value { font-size: 28px; font-weight: 800; color: var(--dark); }
        .stat-label { font-size: 13px; color: var(--gray); margin-top: 4px; }
        .card-modern { background: white; border-radius: 20px; border: 1px solid var(--gray-light); overflow: hidden; margin-bottom: 24px; }
        .card-header-custom { padding: 18px 24px; background: #f8fafc; border-bottom: 1px solid var(--gray-light); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; }
        .card-header-custom h3 { margin: 0; font-size: 18px; font-weight: 700; color: var(--dark); }
        .nav-tabs-custom { border-bottom: 1px solid var(--gray-light); padding: 0 24px; background: #f8fafc; }
        .nav-tabs-custom .nav-link { border: none; padding: 14px 20px; font-weight: 600; color: var(--gray); transition: all 0.2s; cursor: pointer; }
        .nav-tabs-custom .nav-link:hover { color: var(--primary); }
        .nav-tabs-custom .nav-link.active { color: var(--primary); border-bottom: 2px solid var(--primary); background: transparent; }
        .tab-content { padding: 24px; }
        .calendar-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 12px; }
        .calendar-day { background: #f8fafc; border-radius: 16px; padding: 12px; border: 1px solid var(--gray-light); }
        .calendar-day-header { font-weight: 700; font-size: 14px; color: var(--dark); margin-bottom: 8px; padding-bottom: 6px; border-bottom: 1px solid var(--gray-light); }
        .calendar-date { font-size: 12px; color: var(--gray); margin-bottom: 8px; }
        .duty-item { background: white; border-radius: 10px; padding: 8px; margin-bottom: 6px; border-left: 3px solid var(--primary); font-size: 12px; }
        .duty-item.on-call { border-left-color: var(--danger); background: #fff5f5; }
        .duty-item .time { font-weight: 600; color: var(--dark); }
        .duty-item .dept { color: var(--gray); font-size: 10px; }
        .data-table { width: 100%; }
        .data-table th { padding: 12px 16px; font-size: 12px; font-weight: 600; color: var(--gray); background: #f8fafc; border-bottom: 1px solid var(--gray-light); }
        .data-table td { padding: 12px 16px; font-size: 13px; border-bottom: 1px solid #f3f4f6; vertical-align: middle; }
        .data-table tr:hover { background: #f8fafc; }
        .badge-oncall { background: var(--danger); color: white; padding: 4px 8px; border-radius: 12px; font-size: 10px; }
        .badge-regular { background: var(--success); color: white; padding: 4px 8px; border-radius: 12px; font-size: 10px; }
        .badge-emergency { background: var(--warning); color: #92400e; padding: 4px 8px; border-radius: 12px; font-size: 10px; }
        .switch { position: relative; display: inline-block; width: 52px; height: 26px; }
        .switch input { opacity: 0; width: 0; height: 0; }
        .slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: #ccc; transition: .3s; border-radius: 34px; }
        .slider:before { position: absolute; content: ""; height: 20px; width: 20px; left: 3px; bottom: 3px; background-color: white; transition: .3s; border-radius: 50%; }
        input:checked + .slider { background-color: var(--danger); }
        input:checked + .slider:before { transform: translateX(26px); }
        .action-btn { padding: 6px 14px; border-radius: 10px; font-size: 12px; font-weight: 500; border: none; cursor: pointer; transition: all 0.2s; }
        .action-btn-outline { background: white; border: 1px solid var(--gray-light); color: var(--dark); }
        .action-btn-outline:hover { border-color: var(--primary); color: var(--primary); }
        .toast-notification { position: fixed; bottom: 20px; right: 20px; background: white; border-radius: 12px; padding: 12px 20px; box-shadow: 0 10px 25px rgba(0,0,0,0.1); z-index: 1100; animation: slideIn 0.3s ease; display: flex; align-items: center; gap: 12px; border-left: 4px solid var(--success); }
        .toast-error { border-left-color: var(--danger); }
        @keyframes slideIn { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
        .loading-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(255,255,255,0.7); z-index: 2000; display: none; align-items: center; justify-content: center; }
        .loading-overlay.active { display: flex; }
        .spinner { width: 40px; height: 40px; border: 3px solid var(--gray-light); border-top-color: var(--primary); border-radius: 50%; animation: spin 0.8s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }
        .empty-state { text-align: center; padding: 40px; color: var(--gray); }
        .text-muted { color: var(--gray) !important; }
        .me-2 { margin-right: 8px; }
        .mt-2 { margin-top: 8px; }
    </style>
</head>
<body>
<div id="wrapper">
    <?php include('assets/inc/nav.php'); ?>
    <?php include('assets/inc/sidebar.php'); ?>

    <div class="content-page">
        <div class="container-fluid">
            <div class="page-title"><i class="fas fa-calendar-alt me-2"></i>My Duty Rota & Schedule</div>

            <!-- Summary Cards -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon primary"><i class="fas fa-clock"></i></div>
                    <div class="stat-value" id="todayDutyDisplay">
                        <?php 
                        if ($today_duty && !empty($today_duty['shift_name'])) {
                            echo escapeHtml($today_duty['shift_name']);
                        } elseif ($today_duty && $today_duty['start_time'] && $today_duty['end_time']) {
                            echo date('g:i A', strtotime($today_duty['start_time'])) . ' - ' . date('g:i A', strtotime($today_duty['end_time']));
                        } else {
                            echo 'No duty assigned';
                        }
                        ?>
                    </div>
                    <div class="stat-label">Today's Duty</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon success"><i class="fas fa-chart-line"></i></div>
                    <div class="stat-value" id="weeklyHours"><?php echo $weekly_total_hours; ?></div>
                    <div class="stat-label">Weekly Total Hours</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon warning"><i class="fas fa-phone-alt"></i></div>
                    <div class="d-flex align-items-center justify-content-between">
                        <div class="stat-value" id="onCallStatusText"><?php echo $on_call_today ? 'Active' : 'Inactive'; ?></div>
                        <label class="switch">
                            <input type="checkbox" id="onCallToggle" <?php echo $on_call_today ? 'checked' : ''; ?> <?php echo ($has_manage_permission || $is_global_admin) ? '' : 'disabled'; ?>>
                            <span class="slider"></span>
                        </label>
                    </div>
                    <div class="stat-label">On-Call Status (Today)</div>
                    <?php if (!$has_manage_permission && !$is_global_admin): ?>
                        <small class="text-muted">(No permission to toggle)</small>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Tabs Section -->
            <div class="card-modern">
                <ul class="nav nav-tabs-custom" id="scheduleTab" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="weekly-tab" data-bs-toggle="tab" data-bs-target="#weekly" type="button" role="tab">📅 Weekly Calendar View</button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="monthly-tab" data-bs-toggle="tab" data-bs-target="#monthly" type="button" role="tab">📋 Monthly Duty Roster List</button>
                    </li>
                </ul>
                <div class="tab-content">
                    <!-- Weekly Calendar Tab -->
                    <div class="tab-pane fade show active" id="weekly" role="tabpanel">
                        <div class="d-flex justify-content-between align-items-center mb-4">
                            <button class="action-btn action-btn-outline" id="prevWeekBtn"><i class="fas fa-chevron-left"></i> Previous Week</button>
                            <h5 class="mb-0" id="weekRangeDisplay"><?php echo $current_week_display; ?></h5>
                            <button class="action-btn action-btn-outline" id="nextWeekBtn">Next Week <i class="fas fa-chevron-right"></i></button>
                        </div>
                        <div id="weeklyCalendarContainer">
                            <div class="calendar-grid" id="weeklyCalendarGrid">
                                <?php if (is_array($week_days) && count($week_days) > 0): ?>
                                    <?php foreach ($week_days as $day): ?>
                                        <div class="calendar-day">
                                            <div class="calendar-day-header"><?php echo $day['day_name']; ?></div>
                                            <div class="calendar-date"><?php echo $day['display_date']; ?></div>
                                            <?php 
                                            $date = $day['date'];
                                            if (isset($rosters_by_date[$date]) && is_array($rosters_by_date[$date])):
                                                foreach ($rosters_by_date[$date] as $duty):
                                                    $is_oncall = ($duty['duty_type'] === 'on_call');
                                                    $time_str = '';
                                                    if (!empty($duty['start_time']) && !empty($duty['end_time'])) {
                                                        $time_str = date('g:i A', strtotime($duty['start_time'])) . ' - ' . date('g:i A', strtotime($duty['end_time']));
                                                    } elseif (!empty($duty['shift_name'])) {
                                                        $time_str = $duty['shift_name'];
                                                    }
                                            ?>
                                                <div class="duty-item <?php echo $is_oncall ? 'on-call' : ''; ?>">
                                                    <div class="time"><?php echo escapeHtml($time_str); ?></div>
                                                    <?php if (!empty($duty['department_name'])): ?>
                                                        <div class="dept"><?php echo escapeHtml($duty['department_name']); ?></div>
                                                    <?php endif; ?>
                                                    <?php if ($is_oncall): ?>
                                                        <span class="badge-oncall">On-Call</span>
                                                    <?php endif; ?>
                                                </div>
                                            <?php 
                                                endforeach;
                                            else: 
                                            ?>
                                                <div class="duty-item" style="background: #fff; border-left-color: #ccc;">
                                                    <div class="time text-muted">No duty</div>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="empty-state col-12">No schedule data available</div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Monthly Roster List Tab -->
                    <div class="tab-pane fade" id="monthly" role="tabpanel">
                        <div class="d-flex justify-content-between align-items-center mb-4">
                            <button class="action-btn action-btn-outline" id="prevMonthBtn"><i class="fas fa-chevron-left"></i> Previous Month</button>
                            <h5 class="mb-0" id="monthDisplay"><?php echo $month_display; ?></h5>
                            <button class="action-btn action-btn-outline" id="nextMonthBtn">Next Month <i class="fas fa-chevron-right"></i></button>
                        </div>
                        <div class="table-responsive" id="monthlyRosterContainer">
                            <table class="data-table">
                                <thead>
                                    <tr><th>Date</th><th>Day</th><th>Shift / Hours</th><th>Department</th><th>Type</th><th>Notes</th></tr>
                                </thead>
                                <tbody id="monthlyRosterBody">
                                    <?php if (is_array($monthly_rosters) && count($monthly_rosters) > 0): ?>
                                        <?php foreach ($monthly_rosters as $roster): 
                                            $day_name = date('l', strtotime($roster['duty_date']));
                                            $time_display = '';
                                            if (!empty($roster['start_time']) && !empty($roster['end_time'])) {
                                                $time_display = date('g:i A', strtotime($roster['start_time'])) . ' - ' . date('g:i A', strtotime($roster['end_time']));
                                            } elseif (!empty($roster['shift_name'])) {
                                                $time_display = $roster['shift_name'];
                                            }
                                            $type_badge = '';
                                            if ($roster['duty_type'] === 'on_call') $type_badge = '<span class="badge-oncall">On-Call</span>';
                                            elseif ($roster['duty_type'] === 'emergency') $type_badge = '<span class="badge-emergency">Emergency</span>';
                                            else $type_badge = '<span class="badge-regular">Regular</span>';
                                        ?>
                                            <tr>
                                                <td><?php echo date('M j, Y', strtotime($roster['duty_date'])); ?></td>
                                                <td><?php echo $day_name; ?></td>
                                                <td><?php echo escapeHtml($time_display); ?></td>
                                                <td><?php echo escapeHtml($roster['department_name'] ?? '—'); ?></td>
                                                <td><?php echo $type_badge; ?></td>
                                                <td><?php echo escapeHtml($roster['notes'] ?? '—'); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr><td colspan="6" class="empty-state">No duty records found for this month</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
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
<script src="assets/js/bootstrap.bundle.min.js"></script>
<script>
const csrfToken = <?php echo json_encode($csrf_token); ?>;
let currentWeekOffset = 0;
let currentMonthOffset = 0;

function showToast(message, type = 'success') {
    const toast = document.createElement('div');
    toast.className = `toast-notification ${type === 'error' ? 'toast-error' : ''}`;
    toast.innerHTML = `<i class="fas fa-${type === 'error' ? 'exclamation-circle' : 'check-circle'}"></i> <span>${escapeHtml(message)}</span>`;
    document.body.appendChild(toast);
    setTimeout(() => toast.remove(), 3000);
}

function showLoading(show) {
    document.getElementById('loadingOverlay').classList.toggle('active', show);
}

function escapeHtml(unsafe) {
    if (!unsafe) return '';
    return String(unsafe).replace(/[&<>]/g, m => m === '&' ? '&amp;' : (m === '<' ? '&lt;' : '&gt;'));
}

// Force re‑initialize Bootstrap tabs (fix for frozen buttons)
function initTabs() {
    if (typeof bootstrap !== 'undefined') {
        const tabEls = document.querySelectorAll('#scheduleTab .nav-link');
        tabEls.forEach(tabEl => {
            let tab = bootstrap.Tab.getInstance(tabEl);
            if (tab) tab.dispose();
            new bootstrap.Tab(tabEl);
        });
        console.log('Bootstrap tabs re‑initialized');
    } else {
        console.warn('Bootstrap JS not loaded, tabs may not work');
    }
}

// Weekly Calendar AJAX
function loadWeeklyCalendar() {
    showLoading(true);
    $.post(window.location.pathname + '?ajax=schedule_action', {
        action: 'fetch_weekly',
        week_offset: currentWeekOffset,
        csrf_token: csrfToken
    }, function(res) {
        showLoading(false);
        if (res.success) {
            $('#weekRangeDisplay').text(res.data.week_display);
            let html = '<div class="calendar-grid">';
            res.data.week_days.forEach(day => {
                let date = day.date;
                let rosters = res.data.rosters_by_date[date] || [];
                html += `<div class="calendar-day">
                            <div class="calendar-day-header">${escapeHtml(day.day_name)}</div>
                            <div class="calendar-date">${escapeHtml(day.display_date)}</div>`;
                if (rosters.length > 0) {
                    rosters.forEach(duty => {
                        let isOncall = (duty.duty_type === 'on_call');
                        let timeStr = '';
                        if (duty.start_time && duty.end_time) {
                            let start = new Date('1970-01-01T' + duty.start_time);
                            let end = new Date('1970-01-01T' + duty.end_time);
                            timeStr = start.toLocaleTimeString([], {hour:'2-digit', minute:'2-digit'}) + ' - ' + end.toLocaleTimeString([], {hour:'2-digit', minute:'2-digit'});
                        } else if (duty.shift_name) {
                            timeStr = duty.shift_name;
                        }
                        html += `<div class="duty-item ${isOncall ? 'on-call' : ''}">
                                    <div class="time">${escapeHtml(timeStr)}</div>`;
                        if (duty.department_name) html += `<div class="dept">${escapeHtml(duty.department_name)}</div>`;
                        if (isOncall) html += `<span class="badge-oncall">On-Call</span>`;
                        html += `</div>`;
                    });
                } else {
                    html += `<div class="duty-item" style="background:#fff; border-left-color:#ccc;"><div class="time text-muted">No duty</div></div>`;
                }
                html += `</div>`;
            });
            html += '</div>';
            $('#weeklyCalendarGrid').html(html);
        } else {
            showToast(res.message || 'Failed to load weekly schedule', 'error');
        }
    }).fail(function(jqXHR, textStatus, errorThrown) {
        showLoading(false);
        showToast('Network error: ' + errorThrown, 'error');
        console.error('Weekly AJAX error:', textStatus, errorThrown);
    });
}

// Monthly Roster AJAX
function loadMonthlyRoster() {
    showLoading(true);
    $.post(window.location.pathname + '?ajax=schedule_action', {
        action: 'fetch_monthly',
        month_offset: currentMonthOffset,
        csrf_token: csrfToken
    }, function(res) {
        showLoading(false);
        if (res.success) {
            $('#monthDisplay').text(res.data.month_display);
            let tbody = $('#monthlyRosterBody');
            tbody.empty();
            if (res.data.rosters.length > 0) {
                res.data.rosters.forEach(roster => {
                    let dateObj = new Date(roster.duty_date);
                    let dayName = dateObj.toLocaleDateString('en-US', { weekday: 'long' });
                    let timeDisplay = '';
                    if (roster.start_time && roster.end_time) {
                        let start = new Date('1970-01-01T' + roster.start_time);
                        let end = new Date('1970-01-01T' + roster.end_time);
                        timeDisplay = start.toLocaleTimeString([], {hour:'2-digit', minute:'2-digit'}) + ' - ' + end.toLocaleTimeString([], {hour:'2-digit', minute:'2-digit'});
                    } else if (roster.shift_name) {
                        timeDisplay = roster.shift_name;
                    }
                    let typeBadge = '';
                    if (roster.duty_type === 'on_call') typeBadge = '<span class="badge-oncall">On-Call</span>';
                    else if (roster.duty_type === 'emergency') typeBadge = '<span class="badge-emergency">Emergency</span>';
                    else typeBadge = '<span class="badge-regular">Regular</span>';
                    tbody.append(`
                        <tr>
                            <td>${escapeHtml(dateObj.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }))}</td>
                            <td>${escapeHtml(dayName)}</td>
                            <td>${escapeHtml(timeDisplay)}</td>
                            <td>${escapeHtml(roster.department_name || '—')}</td>
                            <td>${typeBadge}</td>
                            <td>${escapeHtml(roster.notes || '—')}</td>
                        </tr>
                    `);
                });
            } else {
                tbody.append('<tr><td colspan="6" class="empty-state">No duty records found for this month</td></tr>');
            }
        } else {
            showToast(res.message || 'Failed to load monthly roster', 'error');
        }
    }).fail(function(jqXHR, textStatus, errorThrown) {
        showLoading(false);
        showToast('Network error: ' + errorThrown, 'error');
        console.error('Monthly AJAX error:', textStatus, errorThrown);
    });
}

// Toggle On-Call Status
function toggleOnCallStatus(checked) {
    showLoading(true);
    $.post(window.location.pathname + '?ajax=schedule_action', {
        action: 'toggle_oncall',
        csrf_token: csrfToken
    }, function(res) {
        showLoading(false);
        if (res.success) {
            showToast(res.message);
            $('#onCallStatusText').text(res.data.new_status === 'on_call' ? 'Active' : 'Inactive');
            loadWeeklyCalendar(); // refresh weekly view
        } else {
            showToast(res.message || 'Failed to update on-call status', 'error');
            $('#onCallToggle').prop('checked', !checked);
        }
    }).fail(function() {
        showLoading(false);
        showToast('Network error', 'error');
        $('#onCallToggle').prop('checked', !checked);
    });
}

// Event handlers
$(document).ready(function() {
    initTabs(); // fixes frozen tab buttons

    $('#prevWeekBtn').click(function() { currentWeekOffset--; loadWeeklyCalendar(); });
    $('#nextWeekBtn').click(function() { currentWeekOffset++; loadWeeklyCalendar(); });
    $('#prevMonthBtn').click(function() { currentMonthOffset--; loadMonthlyRoster(); });
    $('#nextMonthBtn').click(function() { currentMonthOffset++; loadMonthlyRoster(); });

    $('#onCallToggle').change(function() {
        toggleOnCallStatus($(this).is(':checked'));
    });

    // Initial loads (data already present from PHP, but AJAX ensures consistency)
    loadWeeklyCalendar();
    loadMonthlyRoster();
});

// Sidebar toggle compatibility (avoid MetisMenu interfering with tabs)
if (typeof MetisMenu !== 'undefined') {
    const sideMenu = document.getElementById('side-menu');
    if (sideMenu && !sideMenu.hasAttribute('data-metismenu-initialized')) {
        new MetisMenu(sideMenu, { toggle: true, triggerElement: 'a.has-arrow', parentTrigger: 'li', subMenu: 'ul' });
        sideMenu.setAttribute('data-metismenu-initialized', 'true');
    }
}
document.getElementById('mobileMenuToggle')?.addEventListener('click', function(e) {
    e.preventDefault();
    document.body.classList.toggle('sidebar-enable');
});
window.addEventListener('resize', function() {
    if (window.innerWidth > 768 && document.body.classList.contains('sidebar-enable')) document.body.classList.remove('sidebar-enable');
});
</script>
</body>
</html>