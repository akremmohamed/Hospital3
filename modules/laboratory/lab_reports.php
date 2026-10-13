<?php
/**
 * lab_reports.php
 * 
 * HMS ERP - Laboratory Reports & Analytics
 * 
 * Features:
 * - Test volume reports (daily, weekly, monthly)
 * - Turnaround time (TAT) analysis
 * - Revenue reports
 * - Test utilization statistics
 * - Staff productivity reports
 * - Abnormal/Critical results summary
 * - Export reports (PDF/CSV)
 * - Date range filtering
 * - Chart visualizations
 * 
 * @package HMS_ERP
 * @version 1.0
 */

// ============================================
// 1. SECURITY & SESSION VALIDATION
// ============================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../config/db_connection.php';
require_once __DIR__ . '/../../includes/auth_helper.php';

date_default_timezone_set('Africa/Addis_Ababa');
$pdo->exec("SET time_zone = '+03:00'");

// Validate user session
$user_id = (int)($_SESSION['user_id'] ?? 0);
$branch_id = (int)($_SESSION['branch_id'] ?? 0);
$user_type = $_SESSION['user_type'] ?? '';
$is_global_admin = ($user_type === 'super_admin');
$is_lab_staff = in_array($user_type, ['laboratorist', 'super_admin', 'admin']);

if ($user_id <= 0 || !$is_lab_staff) {
    header('Location: login.php');
    exit;
}

// ============================================
// 2. DATE RANGE FILTERS
// ============================================
$report_type = $_GET['report_type'] ?? 'volume';
$date_from = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
$date_to = $_GET['date_to'] ?? date('Y-m-d');
$period = $_GET['period'] ?? 'monthly';

// Build branch filter
$branch_filter = "";
$params = [];
if (!$is_global_admin && $branch_id > 0) {
    $branch_filter = " AND branch_id = :branch_id";
    $params[':branch_id'] = $branch_id;
}

// ============================================
// 3. REPORT DATA FETCHING
// ============================================

// ============================================
// 3.1. TEST VOLUME REPORT
// ============================================
$volume_data = [];
$volume_stats = [
    'total' => 0,
    'daily_avg' => 0,
    'weekly_avg' => 0,
    'monthly_avg' => 0,
    'stat_count' => 0,
    'urgent_count' => 0,
    'routine_count' => 0
];

try {
    // Total orders in date range
    $sql = "SELECT COUNT(*) as total 
            FROM lab_orders 
            WHERE DATE(created_at) BETWEEN :date_from AND :date_to 
            AND deleted_at IS NULL" . $branch_filter;
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge([':date_from' => $date_from, ':date_to' => $date_to], $params));
    $volume_stats['total'] = (int)$stmt->fetchColumn();

    // Daily average
    $days_diff = max(1, (strtotime($date_to) - strtotime($date_from)) / 86400 + 1);
    $volume_stats['daily_avg'] = round($volume_stats['total'] / $days_diff, 1);

    // Priority breakdown
    $sql = "SELECT priority, COUNT(*) as count 
            FROM lab_orders 
            WHERE DATE(created_at) BETWEEN :date_from AND :date_to 
            AND deleted_at IS NULL" . $branch_filter . "
            GROUP BY priority";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge([':date_from' => $date_from, ':date_to' => $date_to], $params));
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if ($row['priority'] === 'stat') $volume_stats['stat_count'] = (int)$row['count'];
        elseif ($row['priority'] === 'urgent') $volume_stats['urgent_count'] = (int)$row['count'];
        elseif ($row['priority'] === 'routine') $volume_stats['routine_count'] = (int)$row['count'];
    }

    // Daily breakdown for charts
    $sql = "SELECT DATE(created_at) as date, COUNT(*) as count 
            FROM lab_orders 
            WHERE DATE(created_at) BETWEEN :date_from AND :date_to 
            AND deleted_at IS NULL" . $branch_filter . "
            GROUP BY DATE(created_at) 
            ORDER BY date ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge([':date_from' => $date_from, ':date_to' => $date_to], $params));
    $volume_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log('Volume report error: ' . $e->getMessage());
}

// ============================================
// 3.2. TURNAROUND TIME (TAT) REPORT
// ============================================
$tat_data = [];
$tat_stats = [
    'avg_tat_hours' => 0,
    'min_tat_hours' => 0,
    'max_tat_hours' => 0,
    'on_time_percentage' => 0,
    'exceeded_count' => 0,
    'total_completed' => 0
];

try {
    // Completed orders with TAT
    $sql = "SELECT 
                id, order_number, priority,
                TIMESTAMPDIFF(HOUR, created_at, completed_at) as tat_hours,
                created_at, completed_at
            FROM lab_orders 
            WHERE status = 'completed' 
            AND completed_at IS NOT NULL
            AND DATE(created_at) BETWEEN :date_from AND :date_to 
            AND deleted_at IS NULL" . $branch_filter;
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge([':date_from' => $date_from, ':date_to' => $date_to], $params));
    $tat_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $tat_stats['total_completed'] = count($tat_data);
    
    if ($tat_stats['total_completed'] > 0) {
        $tat_hours = array_column($tat_data, 'tat_hours');
        $tat_stats['avg_tat_hours'] = round(array_sum($tat_hours) / count($tat_hours), 1);
        $tat_stats['min_tat_hours'] = min($tat_hours);
        $tat_stats['max_tat_hours'] = max($tat_hours);
        
        // Count exceeded TAT (> 24 hours)
        $tat_stats['exceeded_count'] = count(array_filter($tat_hours, function($h) { return $h > 24; }));
        $tat_stats['on_time_percentage'] = round((($tat_stats['total_completed'] - $tat_stats['exceeded_count']) / $tat_stats['total_completed']) * 100, 1);
    }

} catch (PDOException $e) {
    error_log('TAT report error: ' . $e->getMessage());
}

// ============================================
// 3.3. REVENUE REPORT
// ============================================
$revenue_data = [];
$revenue_stats = [
    'total_revenue' => 0,
    'total_paid' => 0,
    'total_pending' => 0,
    'avg_per_test' => 0,
    'cash_amount' => 0,
    'insurance_amount' => 0
];

try {
    // Revenue from lab orders with test pricing
    $sql = "SELECT 
                lo.id, lo.order_number, lo.payment_status,
                ltm.price as test_price,
                lo.payment_verified_at,
                lo.created_at
            FROM lab_orders lo
            INNER JOIN lab_tests_master ltm ON lo.test_id = ltm.id
            WHERE DATE(lo.created_at) BETWEEN :date_from AND :date_to 
            AND lo.deleted_at IS NULL
            AND lo.status != 'cancelled'" . $branch_filter;
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge([':date_from' => $date_from, ':date_to' => $date_to], $params));
    $revenue_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($revenue_data as $order) {
        $price = (float)($order['test_price'] ?? 0);
        $revenue_stats['total_revenue'] += $price;
        
        if ($order['payment_status'] === 'paid') {
            $revenue_stats['total_paid'] += $price;
        } else {
            $revenue_stats['total_pending'] += $price;
        }
    }

    if (count($revenue_data) > 0) {
        $revenue_stats['avg_per_test'] = round($revenue_stats['total_revenue'] / count($revenue_data), 2);
    }

    // Payment method breakdown
    $sql = "SELECT 
                p.payment_method,
                SUM(p.amount_paid) as total
            FROM payments p
            INNER JOIN lab_orders lo ON lo.id IN (
                SELECT lab_order_id FROM invoice_items WHERE invoice_id = p.invoice_id
            )
            WHERE DATE(p.payment_date) BETWEEN :date_from AND :date_to 
            AND p.deleted_at IS NULL
            AND p.is_active = 1" . str_replace('branch_id', 'lo.branch_id', $branch_filter) . "
            GROUP BY p.payment_method";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge([':date_from' => $date_from, ':date_to' => $date_to], $params));
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if (in_array($row['payment_method'], ['cash', 'telebirr', 'cbe_birr', 'mobile_money'])) {
            $revenue_stats['cash_amount'] += (float)$row['total'];
        } elseif ($row['payment_method'] === 'insurance_claim') {
            $revenue_stats['insurance_amount'] += (float)$row['total'];
        }
    }

} catch (PDOException $e) {
    error_log('Revenue report error: ' . $e->getMessage());
}

// ============================================
// 3.4. TEST UTILIZATION REPORT
// ============================================
$utilization_data = [];

try {
    $sql = "SELECT 
                ltm.test_code,
                ltm.test_name,
                ltm.test_category,
                COUNT(lo.id) as order_count,
                AVG(TIMESTAMPDIFF(HOUR, lo.created_at, lo.completed_at)) as avg_tat
            FROM lab_tests_master ltm
            LEFT JOIN lab_orders lo ON ltm.id = lo.test_id 
                AND DATE(lo.created_at) BETWEEN :date_from AND :date_to
                AND lo.deleted_at IS NULL
                AND lo.status = 'completed'
            WHERE ltm.deleted_at IS NULL
            AND ltm.is_active = 1
            GROUP BY ltm.id
            ORDER BY order_count DESC
            LIMIT 20";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':date_from' => $date_from, ':date_to' => $date_to]);
    $utilization_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log('Utilization report error: ' . $e->getMessage());
}

// ============================================
// 3.5. ABNORMAL/CRITICAL RESULTS REPORT
// ============================================
$abnormal_stats = [
    'total_abnormal' => 0,
    'total_critical' => 0,
    'total_verified' => 0,
    'pending_review' => 0
];

try {
    // Abnormal results
    $sql = "SELECT COUNT(DISTINCT lo.id) as count 
            FROM lab_orders lo
            INNER JOIN lab_test_components ltc ON lo.id = ltc.lab_order_id
            WHERE ltc.is_abnormal = 1
            AND DATE(lo.created_at) BETWEEN :date_from AND :date_to
            AND lo.deleted_at IS NULL
            AND ltc.deleted_at IS NULL" . $branch_filter;
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge([':date_from' => $date_from, ':date_to' => $date_to], $params));
    $abnormal_stats['total_abnormal'] = (int)$stmt->fetchColumn();

    // Critical results
    $sql = "SELECT COUNT(DISTINCT lo.id) as count 
            FROM lab_orders lo
            INNER JOIN lab_test_components ltc ON lo.id = ltc.lab_order_id
            WHERE ltc.is_critical = 1
            AND DATE(lo.created_at) BETWEEN :date_from AND :date_to
            AND lo.deleted_at IS NULL
            AND ltc.deleted_at IS NULL" . $branch_filter;
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge([':date_from' => $date_from, ':date_to' => $date_to], $params));
    $abnormal_stats['total_critical'] = (int)$stmt->fetchColumn();

    // Verified results
    $sql = "SELECT COUNT(DISTINCT lo.id) as count 
            FROM lab_orders lo
            INNER JOIN lab_test_components ltc ON lo.id = ltc.lab_order_id
            WHERE ltc.verified_by_user_id IS NOT NULL
            AND DATE(lo.created_at) BETWEEN :date_from AND :date_to
            AND lo.deleted_at IS NULL
            AND ltc.deleted_at IS NULL" . $branch_filter;
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge([':date_from' => $date_from, ':date_to' => $date_to], $params));
    $abnormal_stats['total_verified'] = (int)$stmt->fetchColumn();

    // Pending review (critical not reviewed)
    $sql = "SELECT COUNT(DISTINCT lo.id) as count 
            FROM lab_orders lo
            INNER JOIN lab_test_components ltc ON lo.id = ltc.lab_order_id
            WHERE ltc.is_critical = 1
            AND ltc.verified_by_user_id IS NULL
            AND DATE(lo.created_at) BETWEEN :date_from AND :date_to
            AND lo.deleted_at IS NULL
            AND ltc.deleted_at IS NULL" . $branch_filter;
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge([':date_from' => $date_from, ':date_to' => $date_to], $params));
    $abnormal_stats['pending_review'] = (int)$stmt->fetchColumn();

} catch (PDOException $e) {
    error_log('Abnormal report error: ' . $e->getMessage());
}

// ============================================
// 3.6. STAFF PRODUCTIVITY REPORT
// ============================================
$productivity_data = [];

try {
    $sql = "SELECT 
                u.id, u.first_name, u.last_name,
                COUNT(DISTINCT lo.id) as orders_processed,
                COUNT(DISTINCT lo.patient_id) as patients_served,
                AVG(TIMESTAMPDIFF(HOUR, lo.created_at, lo.completed_at)) as avg_tat
            FROM users u
            LEFT JOIN lab_orders lo ON u.id = lo.specimen_collected_by_user_id
                AND DATE(lo.created_at) BETWEEN :date_from AND :date_to
                AND lo.status IN ('completed', 'verified')
                AND lo.deleted_at IS NULL
            WHERE u.user_type IN ('laboratorist', 'nurse')
            AND u.is_active = 1
            AND u.deleted_at IS NULL
            GROUP BY u.id
            ORDER BY orders_processed DESC
            LIMIT 10";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':date_from' => $date_from, ':date_to' => $date_to]);
    $productivity_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log('Productivity report error: ' . $e->getMessage());
}

// ============================================
// 4. HELPER FUNCTIONS
// ============================================
function log_audit($pdo, $branch_id, $user_id, $action, $table, $record_id, $details) {
    try {
        $sql = "INSERT INTO audit_logs (
                    branch_id, user_id, action_type, target_table, record_id,
                    new_values, ip_address, user_agent, created_at
                ) VALUES (
                    :branch_id, :user_id, :action_type, :target_table, :record_id,
                    :new_values, :ip_address, :user_agent, NOW()
                )";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':branch_id' => $branch_id,
            ':user_id' => $user_id,
            ':action_type' => $action,
            ':target_table' => $table,
            ':record_id' => $record_id,
            ':new_values' => json_encode($details),
            ':ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            ':user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
        ]);
    } catch (Exception $e) {
        error_log('Audit log error: ' . $e->getMessage());
    }
}

// Format date range for display
$date_range_display = date('M d, Y', strtotime($date_from)) . ' - ' . date('M d, Y', strtotime($date_to));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lab Reports | HMS ERP</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;
            background: #f0f2f5;
            color: #1f2937;
            overflow-x: hidden;
        }
        
        #wrapper { display: flex; min-height: 100vh; }
        #page-wrapper {
            flex: 1;
            margin-left: 260px;
            padding: 20px 24px 30px;
            transition: margin-left 0.3s ease;
            min-height: 100vh;
            padding-top: 90px;
        }
        body.erp-sidebar-collapsed #page-wrapper { margin-left: 80px; }
        
        @media (max-width: 768px) {
            #page-wrapper {
                margin-left: 0 !important;
                padding: 12px 12px 20px;
                padding-top: 80px;
            }
            body.sidebar-enable #page-wrapper { margin-left: 280px !important; }
        }
        
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 12px;
        }
        .page-header h1 {
            font-size: 24px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .page-header h1 i { color: #4361ee; }
        
        .btn {
            padding: 8px 16px;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            background: white;
            cursor: pointer;
            font-size: 13px;
            font-weight: 500;
            transition: all 0.2s;
            text-decoration: none;
            color: #1f2937;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .btn:hover { background: #f3f4f6; border-color: #4361ee; }
        .btn-primary { background: #4361ee; color: white; border-color: #4361ee; }
        .btn-primary:hover { background: #3a0ca3; border-color: #3a0ca3; color: white; }
        .btn-success { background: #06d6a0; color: white; border-color: #06d6a0; }
        .btn-success:hover { background: #05b88a; border-color: #05b88a; }
        .btn-sm { padding: 4px 10px; font-size: 12px; }
        
        /* Report Type Tabs */
        .report-tabs {
            display: flex;
            gap: 4px;
            background: white;
            padding: 4px;
            border-radius: 10px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08);
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        .report-tabs .tab {
            padding: 8px 16px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 500;
            color: #6b7280;
            transition: all 0.2s;
            border: none;
            background: transparent;
        }
        .report-tabs .tab:hover { background: #f3f4f6; }
        .report-tabs .tab.active {
            background: #4361ee;
            color: white;
        }
        
        .filter-bar {
            background: white;
            padding: 16px 20px;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08);
            margin-bottom: 20px;
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            align-items: center;
        }
        .filter-bar select, .filter-bar input {
            padding: 6px 12px;
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            font-size: 13px;
            background: white;
            min-width: 130px;
        }
        .filter-bar .filter-label {
            font-size: 12px;
            color: #6b7280;
            font-weight: 500;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 16px;
            margin-bottom: 20px;
        }
        .stat-card {
            background: white;
            padding: 16px 20px;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08);
            border-left: 4px solid #4361ee;
        }
        .stat-card .stat-value {
            font-size: 28px;
            font-weight: 700;
            margin: 4px 0 2px;
        }
        .stat-card .stat-label {
            font-size: 13px;
            color: #6b7280;
        }
        .stat-card .stat-change {
            font-size: 12px;
            font-weight: 500;
        }
        .stat-card.primary { border-left-color: #4361ee; }
        .stat-card.success { border-left-color: #06d6a0; }
        .stat-card.warning { border-left-color: #f9c74f; }
        .stat-card.danger { border-left-color: #ef476f; }
        .stat-card.info { border-left-color: #4cc9f0; }
        .stat-card.purple { border-left-color: #8b5cf6; }
        .stat-value.primary { color: #4361ee; }
        .stat-value.success { color: #06d6a0; }
        .stat-value.danger { color: #ef476f; }
        .stat-value.warning { color: #d97706; }
        .stat-value.purple { color: #8b5cf6; }
        
        .dashboard-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        .dashboard-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08);
            overflow: hidden;
        }
        .dashboard-card .card-header {
            padding: 14px 20px;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .dashboard-card .card-header h3 {
            font-size: 15px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .dashboard-card .card-header .badge {
            font-size: 11px;
            padding: 3px 10px;
            border-radius: 20px;
            font-weight: 600;
        }
        .badge-secondary { background: #f3f4f6; color: #6b7280; }
        .badge-success { background: #d1fae5; color: #059669; }
        .badge-danger { background: #fee2e2; color: #dc2626; }
        .badge-warning { background: #fef3c7; color: #d97706; }
        
        .dashboard-card .card-body { padding: 16px 20px; }
        
        .chart-container { position: relative; height: 250px; }
        
        .table-container { overflow-x: auto; }
        .table-container table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }
        .table-container th {
            text-align: left;
            padding: 10px 12px;
            border-bottom: 2px solid #e5e7eb;
            font-weight: 600;
            color: #6b7280;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .table-container td {
            padding: 8px 12px;
            border-bottom: 1px solid #e5e7eb;
        }
        .table-container tr:hover { background: #f9fafb; }
        
        .no-data {
            text-align: center;
            padding: 40px 0;
            color: #6b7280;
        }
        .no-data i { font-size: 36px; display: block; margin-bottom: 10px; color: #d1d5db; }
        
        .text-muted { color: #6b7280; }
        .text-center { text-align: center; }
        .mt-2 { margin-top: 8px; }
        
        .list-item {
            padding: 8px 12px;
            border-bottom: 1px solid #f3f4f6;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 13px;
        }
        .list-item:last-child { border-bottom: none; }
        .list-item .item-value { font-weight: 600; }
        
        @media (max-width: 768px) {
            .filter-bar { flex-direction: column; align-items: stretch; }
            .filter-bar select, .filter-bar input { width: 100%; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .dashboard-grid { grid-template-columns: 1fr; }
            .page-header h1 { font-size: 20px; }
            .report-tabs .tab { font-size: 12px; padding: 6px 12px; }
        }
        @media (max-width: 480px) {
            .stats-grid { grid-template-columns: 1fr 1fr; gap: 10px; }
            .stat-card { padding: 12px 14px; }
            .stat-card .stat-value { font-size: 20px; }
        }
        
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: #f3f4f6; border-radius: 4px; }
        ::-webkit-scrollbar-thumb { background: #e5e7eb; border-radius: 4px; }
        ::-webkit-scrollbar-thumb:hover { background: #6b7280; }
    </style>
</head>
<body>
<div id="wrapper">
    <?php include('assets/inc/nav.php'); ?>
    <?php include('assets/inc/sidebar.php'); ?>
    
    <div id="page-wrapper">
        <div class="page-header">
            <h1><i class="fas fa-chart-bar"></i> Lab Reports</h1>
            <div>
                <button class="btn btn-sm" onclick="exportCSV()"><i class="fas fa-file-export"></i> Export CSV</button>
                <button class="btn btn-sm" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
                <button class="btn btn-sm" onclick="window.location.reload();"><i class="fas fa-sync-alt"></i></button>
            </div>
        </div>

        <!-- Report Type Tabs -->
        <div class="report-tabs">
            <button class="tab <?php echo $report_type === 'volume' ? 'active' : ''; ?>" onclick="changeReport('volume')">
                <i class="fas fa-flask"></i> Volume
            </button>
            <button class="tab <?php echo $report_type === 'tat' ? 'active' : ''; ?>" onclick="changeReport('tat')">
                <i class="fas fa-clock"></i> TAT
            </button>
            <button class="tab <?php echo $report_type === 'revenue' ? 'active' : ''; ?>" onclick="changeReport('revenue')">
                <i class="fas fa-money-bill-wave"></i> Revenue
            </button>
            <button class="tab <?php echo $report_type === 'utilization' ? 'active' : ''; ?>" onclick="changeReport('utilization')">
                <i class="fas fa-chart-pie"></i> Utilization
            </button>
            <button class="tab <?php echo $report_type === 'quality' ? 'active' : ''; ?>" onclick="changeReport('quality')">
                <i class="fas fa-clipboard-check"></i> Quality
            </button>
            <button class="tab <?php echo $report_type === 'productivity' ? 'active' : ''; ?>" onclick="changeReport('productivity')">
                <i class="fas fa-users"></i> Productivity
            </button>
        </div>

        <!-- Filter Bar -->
        <div class="filter-bar">
            <div>
                <span class="filter-label">Date From</span>
                <input type="date" id="dateFrom" value="<?php echo htmlspecialchars($date_from); ?>" onchange="applyFilters()">
            </div>
            <div>
                <span class="filter-label">Date To</span>
                <input type="date" id="dateTo" value="<?php echo htmlspecialchars($date_to); ?>" onchange="applyFilters()">
            </div>
            <div>
                <span class="filter-label">Period</span>
                <select id="periodSelect" onchange="applyFilters()">
                    <option value="daily" <?php echo $period === 'daily' ? 'selected' : ''; ?>>Daily</option>
                    <option value="weekly" <?php echo $period === 'weekly' ? 'selected' : ''; ?>>Weekly</option>
                    <option value="monthly" <?php echo $period === 'monthly' ? 'selected' : ''; ?>>Monthly</option>
                </select>
            </div>
            <button class="btn btn-primary" onclick="applyFilters()"><i class="fas fa-search"></i> Apply</button>
            <button class="btn" onclick="resetFilters()"><i class="fas fa-times"></i> Reset</button>
            <span class="text-muted" style="margin-left:auto;font-size:12px;">
                <i class="fas fa-calendar-alt"></i> <?php echo $date_range_display; ?>
            </span>
        </div>

        <!-- ============================================ -->
        <!-- REPORT: VOLUME -->
        <!-- ============================================ -->
        <?php if ($report_type === 'volume'): ?>
        <!-- Stats -->
        <div class="stats-grid">
            <div class="stat-card primary">
                <div class="stat-value primary"><?php echo number_format($volume_stats['total']); ?></div>
                <div class="stat-label">Total Orders</div>
                <div class="stat-change"><i class="fas fa-calendar-day"></i> <?php echo $volume_stats['daily_avg']; ?> / day avg</div>
            </div>
            <div class="stat-card danger">
                <div class="stat-value danger"><?php echo number_format($volume_stats['stat_count']); ?></div>
                <div class="stat-label">STAT Orders</div>
                <div class="stat-change"><i class="fas fa-clock"></i> <?php echo $volume_stats['total'] > 0 ? round(($volume_stats['stat_count'] / $volume_stats['total']) * 100, 1) : 0; ?>% of total</div>
            </div>
            <div class="stat-card warning">
                <div class="stat-value warning"><?php echo number_format($volume_stats['urgent_count']); ?></div>
                <div class="stat-label">Urgent Orders</div>
                <div class="stat-change"><i class="fas fa-exclamation-circle"></i> <?php echo $volume_stats['total'] > 0 ? round(($volume_stats['urgent_count'] / $volume_stats['total']) * 100, 1) : 0; ?>% of total</div>
            </div>
            <div class="stat-card success">
                <div class="stat-value success"><?php echo number_format($volume_stats['routine_count']); ?></div>
                <div class="stat-label">Routine Orders</div>
                <div class="stat-change"><i class="fas fa-check-circle"></i> <?php echo $volume_stats['total'] > 0 ? round(($volume_stats['routine_count'] / $volume_stats['total']) * 100, 1) : 0; ?>% of total</div>
            </div>
        </div>

        <!-- Volume Chart -->
        <div class="dashboard-card">
            <div class="card-header">
                <h3><i class="fas fa-chart-line"></i> Daily Test Volume</h3>
                <span class="badge badge-secondary"><?php echo count($volume_data); ?> days</span>
            </div>
            <div class="card-body">
                <div class="chart-container">
                    <canvas id="volumeChart"></canvas>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- ============================================ -->
        <!-- REPORT: TAT -->
        <!-- ============================================ -->
        <?php if ($report_type === 'tat'): ?>
        <!-- Stats -->
        <div class="stats-grid">
            <div class="stat-card primary">
                <div class="stat-value primary"><?php echo $tat_stats['avg_tat_hours']; ?>h</div>
                <div class="stat-label">Avg Turnaround Time</div>
                <div class="stat-change"><i class="fas fa-hourglass-half"></i> <?php echo $tat_stats['total_completed']; ?> completed</div>
            </div>
            <div class="stat-card success">
                <div class="stat-value success"><?php echo $tat_stats['min_tat_hours']; ?>h</div>
                <div class="stat-label">Fastest TAT</div>
                <div class="stat-change"><i class="fas fa-rocket"></i> Best performance</div>
            </div>
            <div class="stat-card danger">
                <div class="stat-value danger"><?php echo $tat_stats['max_tat_hours']; ?>h</div>
                <div class="stat-label">Slowest TAT</div>
                <div class="stat-change"><i class="fas fa-turtle"></i> Needs review</div>
            </div>
            <div class="stat-card <?php echo $tat_stats['on_time_percentage'] >= 80 ? 'success' : 'danger'; ?>">
                <div class="stat-value <?php echo $tat_stats['on_time_percentage'] >= 80 ? 'success' : 'danger'; ?>"><?php echo $tat_stats['on_time_percentage']; ?>%</div>
                <div class="stat-label">On-Time Performance</div>
                <div class="stat-change"><i class="fas fa-check-circle"></i> <?php echo $tat_stats['exceeded_count']; ?> exceeded</div>
            </div>
        </div>

        <!-- TAT Distribution -->
        <div class="dashboard-grid">
            <div class="dashboard-card">
                <div class="card-header">
                    <h3><i class="fas fa-chart-bar"></i> TAT Distribution</h3>
                    <span class="badge badge-secondary"><?php echo $tat_stats['total_completed']; ?> orders</span>
                </div>
                <div class="card-body">
                    <div class="chart-container">
                        <canvas id="tatChart"></canvas>
                    </div>
                </div>
            </div>
            <div class="dashboard-card">
                <div class="card-header">
                    <h3><i class="fas fa-list"></i> TAT Breakdown</h3>
                    <span class="badge badge-secondary">Categories</span>
                </div>
                <div class="card-body">
                    <?php
                    $tat_categories = [
                        '0-4 hours' => 0,
                        '4-8 hours' => 0,
                        '8-12 hours' => 0,
                        '12-24 hours' => 0,
                        '24-48 hours' => 0,
                        '48+ hours' => 0
                    ];
                    foreach ($tat_data as $order) {
                        $h = $order['tat_hours'];
                        if ($h <= 4) $tat_categories['0-4 hours']++;
                        elseif ($h <= 8) $tat_categories['4-8 hours']++;
                        elseif ($h <= 12) $tat_categories['8-12 hours']++;
                        elseif ($h <= 24) $tat_categories['12-24 hours']++;
                        elseif ($h <= 48) $tat_categories['24-48 hours']++;
                        else $tat_categories['48+ hours']++;
                    }
                    foreach ($tat_categories as $label => $count):
                        $pct = $tat_stats['total_completed'] > 0 ? round(($count / $tat_stats['total_completed']) * 100, 1) : 0;
                        $color = $pct > 30 ? '#ef476f' : ($pct > 20 ? '#f9c74f' : '#06d6a0');
                    ?>
                    <div class="list-item">
                        <span><?php echo $label; ?></span>
                        <span class="item-value"><?php echo $count; ?> <span class="text-muted">(<?php echo $pct; ?>%)</span></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- ============================================ -->
        <!-- REPORT: REVENUE -->
        <!-- ============================================ -->
        <?php if ($report_type === 'revenue'): ?>
        <!-- Stats -->
        <div class="stats-grid">
            <div class="stat-card success">
                <div class="stat-value success"><?php echo number_format($revenue_stats['total_revenue'], 2); ?></div>
                <div class="stat-label">Total Revenue</div>
                <div class="stat-change"><i class="fas fa-file-invoice"></i> <?php echo count($revenue_data); ?> orders</div>
            </div>
            <div class="stat-card primary">
                <div class="stat-value primary"><?php echo number_format($revenue_stats['total_paid'], 2); ?></div>
                <div class="stat-label">Collected</div>
                <div class="stat-change"><i class="fas fa-check-circle"></i> <?php echo $revenue_stats['total_revenue'] > 0 ? round(($revenue_stats['total_paid'] / $revenue_stats['total_revenue']) * 100, 1) : 0; ?>%</div>
            </div>
            <div class="stat-card danger">
                <div class="stat-value danger"><?php echo number_format($revenue_stats['total_pending'], 2); ?></div>
                <div class="stat-label">Pending</div>
                <div class="stat-change"><i class="fas fa-clock"></i> <?php echo $revenue_stats['total_revenue'] > 0 ? round(($revenue_stats['total_pending'] / $revenue_stats['total_revenue']) * 100, 1) : 0; ?>%</div>
            </div>
            <div class="stat-card warning">
                <div class="stat-value warning"><?php echo number_format($revenue_stats['avg_per_test'], 2); ?></div>
                <div class="stat-label">Avg per Test</div>
                <div class="stat-change"><i class="fas fa-calculator"></i> Average price</div>
            </div>
        </div>

        <!-- Revenue Chart -->
        <div class="dashboard-grid">
            <div class="dashboard-card">
                <div class="card-header">
                    <h3><i class="fas fa-chart-bar"></i> Daily Revenue</h3>
                    <span class="badge badge-secondary"><?php echo count($revenue_data); ?> orders</span>
                </div>
                <div class="card-body">
                    <div class="chart-container">
                        <canvas id="revenueChart"></canvas>
                    </div>
                </div>
            </div>
            <div class="dashboard-card">
                <div class="card-header">
                    <h3><i class="fas fa-chart-pie"></i> Payment Method</h3>
                    <span class="badge badge-secondary">Breakdown</span>
                </div>
                <div class="card-body">
                    <div class="chart-container">
                        <canvas id="paymentChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- ============================================ -->
        <!-- REPORT: UTILIZATION -->
        <!-- ============================================ -->
        <?php if ($report_type === 'utilization'): ?>
        <div class="dashboard-card">
            <div class="card-header">
                <h3><i class="fas fa-chart-pie"></i> Test Utilization</h3>
                <span class="badge badge-secondary">Top 20 tests</span>
            </div>
            <div class="card-body">
                <?php if (empty($utilization_data)): ?>
                    <div class="no-data">
                        <i class="fas fa-chart-pie"></i>
                        No utilization data available
                    </div>
                <?php else: ?>
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>Test Code</th>
                                    <th>Test Name</th>
                                    <th>Category</th>
                                    <th>Orders</th>
                                    <th>Avg TAT (hrs)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($utilization_data as $test): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($test['test_code']); ?></td>
                                        <td><?php echo htmlspecialchars($test['test_name']); ?></td>
                                        <td><span class="badge badge-secondary" style="font-size:10px;"><?php echo ucfirst(str_replace('_', ' ', $test['test_category'])); ?></span></td>
                                        <td><strong><?php echo number_format($test['order_count']); ?></strong></td>
                                        <td><?php echo $test['avg_tat'] ? round($test['avg_tat'], 1) . 'h' : 'N/A'; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- ============================================ -->
        <!-- REPORT: QUALITY -->
        <!-- ============================================ -->
        <?php if ($report_type === 'quality'): ?>
        <div class="stats-grid">
            <div class="stat-card danger">
                <div class="stat-value danger"><?php echo number_format($abnormal_stats['total_abnormal']); ?></div>
                <div class="stat-label">Abnormal Results</div>
                <div class="stat-change"><i class="fas fa-exclamation-circle"></i> Needs review</div>
            </div>
            <div class="stat-card danger">
                <div class="stat-value danger"><?php echo number_format($abnormal_stats['total_critical']); ?></div>
                <div class="stat-label">Critical Results</div>
                <div class="stat-change"><i class="fas fa-bell"></i> Immediate attention</div>
            </div>
            <div class="stat-card success">
                <div class="stat-value success"><?php echo number_format($abnormal_stats['total_verified']); ?></div>
                <div class="stat-label">Verified Results</div>
                <div class="stat-change"><i class="fas fa-check-double"></i> QC passed</div>
            </div>
            <div class="stat-card warning">
                <div class="stat-value warning"><?php echo number_format($abnormal_stats['pending_review']); ?></div>
                <div class="stat-label">Pending Review</div>
                <div class="stat-change"><i class="fas fa-clock"></i> Awaiting doctor review</div>
            </div>
        </div>

        <div class="dashboard-grid">
            <div class="dashboard-card">
                <div class="card-header">
                    <h3><i class="fas fa-chart-pie"></i> Result Quality Distribution</h3>
                    <span class="badge badge-secondary">QC Metrics</span>
                </div>
                <div class="card-body">
                    <div class="chart-container">
                        <canvas id="qualityChart"></canvas>
                    </div>
                </div>
            </div>
            <div class="dashboard-card">
                <div class="card-header">
                    <h3><i class="fas fa-list"></i> Quality Summary</h3>
                    <span class="badge badge-secondary">Stats</span>
                </div>
                <div class="card-body">
                    <div class="list-item">
                        <span>Abnormal Results</span>
                        <span class="item-value" style="color:#ef476f;"><?php echo number_format($abnormal_stats['total_abnormal']); ?></span>
                    </div>
                    <div class="list-item">
                        <span>Critical Results</span>
                        <span class="item-value" style="color:#ef476f;"><?php echo number_format($abnormal_stats['total_critical']); ?></span>
                    </div>
                    <div class="list-item">
                        <span>Verified by QC</span>
                        <span class="item-value" style="color:#06d6a0;"><?php echo number_format($abnormal_stats['total_verified']); ?></span>
                    </div>
                    <div class="list-item">
                        <span>Pending Review</span>
                        <span class="item-value" style="color:#d97706;"><?php echo number_format($abnormal_stats['pending_review']); ?></span>
                    </div>
                    <div class="list-item" style="border-top:2px solid #e5e7eb;margin-top:4px;padding-top:12px;">
                        <span><strong>Verification Rate</strong></span>
                        <span class="item-value">
                            <?php 
                            $total_abnormal = $abnormal_stats['total_abnormal'] + $abnormal_stats['total_critical'];
                            echo $total_abnormal > 0 ? round(($abnormal_stats['total_verified'] / $total_abnormal) * 100, 1) . '%' : 'N/A';
                            ?>
                        </span>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- ============================================ -->
        <!-- REPORT: PRODUCTIVITY -->
        <!-- ============================================ -->
        <?php if ($report_type === 'productivity'): ?>
        <div class="dashboard-card">
            <div class="card-header">
                <h3><i class="fas fa-users"></i> Staff Productivity</h3>
                <span class="badge badge-secondary">Top 10 staff</span>
            </div>
            <div class="card-body">
                <?php if (empty($productivity_data)): ?>
                    <div class="no-data">
                        <i class="fas fa-users"></i>
                        No productivity data available
                    </div>
                <?php else: ?>
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>Staff</th>
                                    <th>Orders Processed</th>
                                    <th>Patients Served</th>
                                    <th>Avg TAT (hrs)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($productivity_data as $staff): ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo htmlspecialchars($staff['first_name'] . ' ' . $staff['last_name']); ?></strong>
                                        </td>
                                        <td><strong><?php echo number_format($staff['orders_processed']); ?></strong></td>
                                        <td><?php echo number_format($staff['patients_served']); ?></td>
                                        <td><?php echo $staff['avg_tat'] ? round($staff['avg_tat'], 1) . 'h' : 'N/A'; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Footer -->
        <div style="text-align:center;padding:20px 0;color:#6b7280;font-size:12px;border-top:1px solid #e5e7eb;margin-top:20px;">
            <i class="fas fa-chart-bar"></i> Lab Reports &bull; HMS ERP &bull; 
            Generated: <?php echo date('Y-m-d H:i:s'); ?>
            <?php if ($branch_id > 0): ?>
                &bull; Branch: <?php echo htmlspecialchars($branch_name ?? 'Current'); ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
// ============================================
// REPORT TYPE SWITCHING
// ============================================
function changeReport(type) {
    const url = new URL(window.location.href);
    url.searchParams.set('report_type', type);
    window.location.href = url.toString();
}

function applyFilters() {
    const dateFrom = document.getElementById('dateFrom').value;
    const dateTo = document.getElementById('dateTo').value;
    const period = document.getElementById('periodSelect').value;
    const type = '<?php echo $report_type; ?>';
    
    let url = window.location.pathname + '?';
    url += 'report_type=' + encodeURIComponent(type) + '&';
    if (dateFrom) url += 'date_from=' + encodeURIComponent(dateFrom) + '&';
    if (dateTo) url += 'date_to=' + encodeURIComponent(dateTo) + '&';
    if (period) url += 'period=' + encodeURIComponent(period) + '&';
    window.location.href = url;
}

function resetFilters() {
    const type = '<?php echo $report_type; ?>';
    window.location.href = window.location.pathname + '?report_type=' + encodeURIComponent(type);
}

function exportCSV() {
    const url = new URL(window.location.href);
    url.searchParams.set('export', 'csv');
    window.location.href = url.toString();
}

// ============================================
// CHARTS INITIALIZATION
// ============================================
document.addEventListener('DOMContentLoaded', function() {
    <?php if ($report_type === 'volume'): ?>
    // Volume Chart
    const ctx1 = document.getElementById('volumeChart');
    if (ctx1) {
        const labels = <?php echo json_encode(array_column($volume_data, 'date')); ?>;
        const data = <?php echo json_encode(array_column($volume_data, 'count')); ?>;
        
        new Chart(ctx1, {
            type: 'bar',
            data: {
                labels: labels.map(d => {
                    const date = new Date(d);
                    return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
                }),
                datasets: [{
                    label: 'Orders',
                    data: data,
                    backgroundColor: 'rgba(67, 97, 238, 0.7)',
                    borderColor: '#4361ee',
                    borderWidth: 2,
                    borderRadius: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: { stepSize: 1 }
                    }
                }
            }
        });
    }
    <?php endif; ?>

    <?php if ($report_type === 'tat'): ?>
    // TAT Distribution Chart
    const ctx2 = document.getElementById('tatChart');
    if (ctx2) {
        const tatLabels = ['0-4h', '4-8h', '8-12h', '12-24h', '24-48h', '48h+'];
        const tatData = [
            <?php echo $tat_categories['0-4 hours']; ?>,
            <?php echo $tat_categories['4-8 hours']; ?>,
            <?php echo $tat_categories['8-12 hours']; ?>,
            <?php echo $tat_categories['12-24 hours']; ?>,
            <?php echo $tat_categories['24-48 hours']; ?>,
            <?php echo $tat_categories['48+ hours']; ?>
        ];
        
        new Chart(ctx2, {
            type: 'bar',
            data: {
                labels: tatLabels,
                datasets: [{
                    label: 'Orders',
                    data: tatData,
                    backgroundColor: ['#06d6a0', '#4cc9f0', '#4361ee', '#f9c74f', '#ef476f', '#dc2626'],
                    borderRadius: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: { stepSize: 1 }
                    }
                }
            }
        });
    }
    <?php endif; ?>

    <?php if ($report_type === 'revenue'): ?>
    // Revenue Chart
    const ctx3 = document.getElementById('revenueChart');
    if (ctx3) {
        const revLabels = <?php echo json_encode(array_column($volume_data, 'date')); ?>;
        const revData = <?php 
            // Calculate daily revenue
            $daily_revenue = [];
            foreach ($volume_data as $day) {
                $date = $day['date'];
                $total = 0;
                foreach ($revenue_data as $order) {
                    if (date('Y-m-d', strtotime($order['created_at'])) === $date) {
                        $total += (float)($order['test_price'] ?? 0);
                    }
                }
                $daily_revenue[] = $total;
            }
            echo json_encode($daily_revenue);
        ?>;
        
        new Chart(ctx3, {
            type: 'line',
            data: {
                labels: revLabels.map(d => {
                    const date = new Date(d);
                    return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
                }),
                datasets: [{
                    label: 'Revenue (ETB)',
                    data: revData,
                    borderColor: '#06d6a0',
                    backgroundColor: 'rgba(6, 214, 160, 0.1)',
                    fill: true,
                    tension: 0.4,
                    pointBackgroundColor: '#06d6a0'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) { return value.toFixed(0); }
                        }
                    }
                }
            }
        });
    }

    // Payment Method Chart
    const ctx4 = document.getElementById('paymentChart');
    if (ctx4) {
        new Chart(ctx4, {
            type: 'doughnut',
            data: {
                labels: ['Cash', 'Insurance', 'Other'],
                datasets: [{
                    data: [
                        <?php echo $revenue_stats['cash_amount']; ?>,
                        <?php echo $revenue_stats['insurance_amount']; ?>,
                        <?php echo max(0, $revenue_stats['total_paid'] - $revenue_stats['cash_amount'] - $revenue_stats['insurance_amount']); ?>
                    ],
                    backgroundColor: ['#06d6a0', '#4361ee', '#f9c74f'],
                    borderWidth: 2,
                    borderColor: '#fff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { padding: 12, usePointStyle: true }
                    }
                },
                cutout: '65%'
            }
        });
    }
    <?php endif; ?>

    <?php if ($report_type === 'quality'): ?>
    // Quality Chart
    const ctx5 = document.getElementById('qualityChart');
    if (ctx5) {
        new Chart(ctx5, {
            type: 'doughnut',
            data: {
                labels: ['Abnormal', 'Critical', 'Verified', 'Pending Review'],
                datasets: [{
                    data: [
                        <?php echo max(0, $abnormal_stats['total_abnormal'] - $abnormal_stats['total_critical']); ?>,
                        <?php echo $abnormal_stats['total_critical']; ?>,
                        <?php echo $abnormal_stats['total_verified']; ?>,
                        <?php echo $abnormal_stats['pending_review']; ?>
                    ],
                    backgroundColor: ['#f9c74f', '#ef476f', '#06d6a0', '#4cc9f0'],
                    borderWidth: 2,
                    borderColor: '#fff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { padding: 12, usePointStyle: true }
                    }
                },
                cutout: '65%'
            }
        });
    }
    <?php endif; ?>
});
</script>

</body>
</html>