<?php
/**
 * radiology_dashboard.php
 * 
 * HMS ERP - Radiology Dashboard
 * Based on the radiology_orders and radiology_services tables
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

if ($user_id <= 0) {
    header('Location: login.php');
    exit;
}

// Get branch name
$branch_name = 'All Branches';
if ($branch_id > 0) {
    $stmt = $pdo->prepare("SELECT name FROM branches WHERE id = :id AND deleted_at IS NULL");
    $stmt->execute([':id' => $branch_id]);
    $branch = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($branch) $branch_name = $branch['name'];
}

// ============================================
// 2. RADIOLOGY DASHBOARD DATA FETCHING
// ============================================

// 2.1. WORKFLOW PHASE COUNTS (Radiology Order Pipeline)
$workflow_data = [
    'pending_payment' => 0,
    'scheduled' => 0,
    'in_progress' => 0,
    'completed' => 0,
    'reviewed' => 0,
    'cancelled' => 0
];

// 2.2. EXAM VOLUME STATISTICS
$exam_volume = [
    'total_exams' => 0,
    'today_exams' => 0,
    'this_week' => 0,
    'this_month' => 0,
    'stat_exams' => 0,
    'routine_exams' => 0,
    'urgent_exams' => 0
];

// 2.3. MODALITY BREAKDOWN
$modality_breakdown = [
    'xray' => 0,
    'ct' => 0,
    'mri' => 0,
    'ultrasound' => 0,
    'mammography' => 0,
    'fluoroscopy' => 0,
    'angiography' => 0
];

// 2.4. REVENUE & BILLING
$revenue_data = [
    'total_revenue' => 0,
    'today_revenue' => 0,
    'this_month_revenue' => 0,
    'pending_amount' => 0
];

// 2.5. QUALITY & REPORTING METRICS
$reporting_metrics = [
    'pending_interpretation' => 0,
    'pending_review' => 0,
    'tat_exceeded' => 0,
    'avg_tat_hours' => 0,
    'interpreted_today' => 0
];

// 2.6. RECENT ACTIVITIES
$recent_activities = [];
$notifications = [];

// Flag to track if data was loaded successfully
$data_loaded = false;

try {
    if (!($pdo instanceof PDO)) {
        throw new RuntimeException('Database connection failed');
    }

    // Build branch filter
    $branch_filter = "";
    $params = [];
    if (!$is_global_admin && $branch_id > 0) {
        $branch_filter = " AND branch_id = :branch_id";
        $params[':branch_id'] = $branch_id;
    }

    // ============================================
    // 2.1. WORKFLOW PHASE COUNTS
    // ============================================
    $sql = "SELECT status, COUNT(*) as count 
            FROM radiology_orders 
            WHERE deleted_at IS NULL" . $branch_filter . "
            GROUP BY status";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if (isset($workflow_data[$row['status']])) {
            $workflow_data[$row['status']] = (int)$row['count'];
        }
    }

    // ============================================
    // 2.2. EXAM VOLUME STATISTICS
    // ============================================
    // Total exams
    $sql = "SELECT COUNT(*) as total 
            FROM radiology_orders
            WHERE deleted_at IS NULL" . $branch_filter;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $exam_volume['total_exams'] = (int)$stmt->fetchColumn();

    // Today's exams
    $sql = "SELECT COUNT(*) as today 
            FROM radiology_orders
            WHERE deleted_at IS NULL" . $branch_filter . "
            AND DATE(created_at) = CURDATE()";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $exam_volume['today_exams'] = (int)$stmt->fetchColumn();

    // This week's exams
    $sql = "SELECT COUNT(*) as week 
            FROM radiology_orders
            WHERE deleted_at IS NULL" . $branch_filter . "
            AND YEARWEEK(created_at) = YEARWEEK(CURDATE())";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $exam_volume['this_week'] = (int)$stmt->fetchColumn();

    // This month's exams
    $sql = "SELECT COUNT(*) as month 
            FROM radiology_orders
            WHERE deleted_at IS NULL" . $branch_filter . "
            AND MONTH(created_at) = MONTH(CURDATE()) 
            AND YEAR(created_at) = YEAR(CURDATE())";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $exam_volume['this_month'] = (int)$stmt->fetchColumn();

    // Priority breakdown
    $sql = "SELECT priority, COUNT(*) as count 
            FROM radiology_orders 
            WHERE deleted_at IS NULL 
            AND status NOT IN ('cancelled')" . $branch_filter . "
            GROUP BY priority";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        switch ($row['priority']) {
            case 'stat':
                $exam_volume['stat_exams'] = (int)$row['count'];
                break;
            case 'urgent':
                $exam_volume['urgent_exams'] = (int)$row['count'];
                break;
            case 'routine':
                $exam_volume['routine_exams'] = (int)$row['count'];
                break;
        }
    }

    // ============================================
    // 2.3. MODALITY BREAKDOWN
    // ============================================
    $sql = "SELECT modality, COUNT(*) as count 
            FROM radiology_orders 
            WHERE deleted_at IS NULL" . $branch_filter . "
            AND status NOT IN ('cancelled')
            GROUP BY modality";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $modality = $row['modality'] ?? 'xray';
        if (isset($modality_breakdown[$modality])) {
            $modality_breakdown[$modality] = (int)$row['count'];
        }
    }

    // ============================================
    // 2.4. REVENUE & BILLING
    // ============================================
    try {
        // Total revenue from radiology orders that have service reference
        $sql = "SELECT SUM(rs.standard_price) as total 
                FROM radiology_orders ro
                INNER JOIN radiology_services rs ON ro.service_code = rs.service_code
                WHERE ro.deleted_at IS NULL 
                AND ro.status NOT IN ('cancelled')" . $branch_filter;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $revenue_data['total_revenue'] = (float)($stmt->fetchColumn() ?: 0);
    } catch (PDOException $e) {
        $revenue_data['total_revenue'] = 0;
    }

    // Today's revenue
    try {
        $sql = "SELECT SUM(rs.standard_price) as today 
                FROM radiology_orders ro
                INNER JOIN radiology_services rs ON ro.service_code = rs.service_code
                WHERE ro.deleted_at IS NULL 
                AND ro.status NOT IN ('cancelled')" . $branch_filter . "
                AND DATE(ro.created_at) = CURDATE()";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $revenue_data['today_revenue'] = (float)($stmt->fetchColumn() ?: 0);
    } catch (PDOException $e) {
        $revenue_data['today_revenue'] = 0;
    }

    // This month's revenue
    try {
        $sql = "SELECT SUM(rs.standard_price) as month 
                FROM radiology_orders ro
                INNER JOIN radiology_services rs ON ro.service_code = rs.service_code
                WHERE ro.deleted_at IS NULL 
                AND ro.status NOT IN ('cancelled')" . $branch_filter . "
                AND MONTH(ro.created_at) = MONTH(CURDATE()) 
                AND YEAR(ro.created_at) = YEAR(CURDATE())";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $revenue_data['this_month_revenue'] = (float)($stmt->fetchColumn() ?: 0);
    } catch (PDOException $e) {
        $revenue_data['this_month_revenue'] = 0;
    }

    // Pending payments
    $sql = "SELECT COUNT(*) as pending 
            FROM radiology_orders 
            WHERE payment_status IN ('unpaid', 'partial')
            AND status NOT IN ('cancelled')
            AND deleted_at IS NULL" . $branch_filter;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $revenue_data['pending_amount'] = (float)($stmt->fetchColumn() ?: 0);

    // ============================================
    // 2.5. QUALITY & REPORTING METRICS
    // ============================================
    // Pending interpretation (completed but not reviewed)
    $sql = "SELECT COUNT(*) as pending_interpretation 
            FROM radiology_orders
            WHERE status = 'completed'
            AND reviewed_at IS NULL
            AND deleted_at IS NULL" . $branch_filter;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $reporting_metrics['pending_interpretation'] = (int)$stmt->fetchColumn();

    // Pending review (in progress or scheduled)
    $sql = "SELECT COUNT(*) as pending_review 
            FROM radiology_orders
            WHERE status IN ('scheduled', 'in_progress')
            AND deleted_at IS NULL" . $branch_filter;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $reporting_metrics['pending_review'] = (int)$stmt->fetchColumn();

    // TAT exceeded (orders older than 2 days not completed)
    $sql = "SELECT COUNT(*) as tat_exceeded 
            FROM radiology_orders
            WHERE status NOT IN ('completed', 'reviewed', 'cancelled')
            AND created_at <= DATE_SUB(NOW(), INTERVAL 2 DAY)
            AND deleted_at IS NULL" . $branch_filter;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $reporting_metrics['tat_exceeded'] = (int)$stmt->fetchColumn();

    // Average TAT (completed orders)
    $sql = "SELECT AVG(TIMESTAMPDIFF(HOUR, created_at, performed_at)) as avg_tat
            FROM radiology_orders
            WHERE status IN ('completed', 'reviewed')
            AND performed_at IS NOT NULL
            AND deleted_at IS NULL" . $branch_filter;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $reporting_metrics['avg_tat_hours'] = round((float)($stmt->fetchColumn() ?: 0), 1);

    // Interpreted today
    $sql = "SELECT COUNT(*) as interpreted_today 
            FROM radiology_orders
            WHERE status = 'reviewed'
            AND DATE(reviewed_at) = CURDATE()
            AND deleted_at IS NULL" . $branch_filter;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $reporting_metrics['interpreted_today'] = (int)$stmt->fetchColumn();

    // ============================================
    // 2.6. RECENT ACTIVITIES
    // ============================================
    $sql = "SELECT 
                ro.id, ro.order_number, ro.status, ro.priority, ro.modality, ro.created_at,
                CONCAT(p.first_name, ' ', COALESCE(p.middle_name, ''), ' ', p.last_name) as patient_name,
                CONCAT(u.first_name, ' ', u.last_name) as doctor_name,
                ro.service_name
            FROM radiology_orders ro
            LEFT JOIN patients p ON ro.patient_id = p.id
            LEFT JOIN users u ON ro.doctor_id = u.id
            WHERE ro.deleted_at IS NULL" . $branch_filter . "
            ORDER BY ro.created_at DESC
            LIMIT 10";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $recent_activities = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Notifications
    $sql = "SELECT title, message, created_at 
            FROM notifications_tracker 
            WHERE user_id = :user_id 
            AND is_read = 0 
            AND deleted_at IS NULL 
            ORDER BY created_at DESC 
            LIMIT 5";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':user_id' => $user_id]);
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $data_loaded = true;

} catch (Throwable $e) {
    error_log('Radiology Dashboard Error: ' . $e->getMessage());
    $error_message = 'Unable to load dashboard data. Please try again later.';
}

// ============================================
// 3. CALCULATE SUMMARY STATISTICS
// ============================================
$total_active_orders = array_sum($workflow_data) - $workflow_data['cancelled'];
$completion_rate = $exam_volume['total_exams'] > 0 ? 
    round((($workflow_data['completed'] + $workflow_data['reviewed']) / $exam_volume['total_exams']) * 100, 1) : 0;
$pending_exams = $workflow_data['pending_payment'] + $workflow_data['scheduled'] + $workflow_data['in_progress'];
$total_alerts = $reporting_metrics['tat_exceeded'] + $reporting_metrics['pending_interpretation'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Radiology Dashboard | HMS ERP</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;
            background: #f0f2f5;
            color: #1f2937;
            overflow-x: hidden;
        }
        
        #wrapper {
            display: flex;
            min-height: 100vh;
        }
        
        #page-wrapper {
            flex: 1;
            margin-left: 260px;
            padding: 20px 24px 30px;
            transition: margin-left 0.3s ease;
            min-height: 100vh;
            padding-top: 90px;
        }
        
        body.erp-sidebar-collapsed #page-wrapper {
            margin-left: 80px;
        }
        
        @media (max-width: 768px) {
            #page-wrapper {
                margin-left: 0 !important;
                padding: 12px 16px 20px;
                padding-top: 80px;
            }
            body.sidebar-enable #page-wrapper {
                margin-left: 280px !important;
            }
        }
        
        .dashboard-container {
            max-width: 1440px;
            margin: 0 auto;
        }
        
        .dashboard-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
            flex-wrap: wrap;
            gap: 12px;
        }
        .dashboard-header h1 {
            font-size: 24px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .dashboard-header h1 i {
            color: #7209b7;
        }
        .dashboard-header .branch-badge {
            font-size: 13px;
            color: #6b7280;
            font-weight: 400;
        }
        .header-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
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
        .btn:hover {
            background: #f3f4f6;
            border-color: #7209b7;
        }
        .btn-primary {
            background: #7209b7;
            color: white;
            border-color: #7209b7;
        }
        .btn-primary:hover {
            background: #560bad;
            border-color: #560bad;
            color: white;
        }
        
        .error-message {
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #dc2626;
            padding: 16px 20px;
            border-radius: 12px;
            margin-bottom: 20px;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }
        .stat-card {
            background: white;
            padding: 18px 20px;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08);
            border-left: 4px solid #7209b7;
            transition: transform 0.2s;
        }
        .stat-card:hover { transform: translateY(-2px); }
        .stat-card .stat-icon {
            font-size: 18px;
            color: #6b7280;
            margin-bottom: 4px;
        }
        .stat-card .stat-value {
            font-size: 28px;
            font-weight: 700;
            margin: 2px 0;
        }
        .stat-card .stat-label {
            font-size: 13px;
            color: #6b7280;
        }
        .stat-card .stat-change {
            font-size: 12px;
            font-weight: 500;
            margin-top: 2px;
        }
        .stat-change.positive { color: #06d6a0; }
        .stat-change.negative { color: #ef476f; }
        
        .stat-card.primary { border-left-color: #7209b7; }
        .stat-card.success { border-left-color: #06d6a0; }
        .stat-card.warning { border-left-color: #f9c74f; }
        .stat-card.danger { border-left-color: #ef476f; }
        .stat-card.info { border-left-color: #4cc9f0; }
        .stat-card.purple { border-left-color: #8b5cf6; }
        .stat-card.teal { border-left-color: #14b8a6; }
        .stat-card.orange { border-left-color: #f97316; }
        
        .stat-value.danger { color: #ef476f; }
        .stat-value.success { color: #06d6a0; }
        .stat-value.warning { color: #d97706; }
        .stat-value.primary { color: #7209b7; }
        
        .dashboard-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 20px;
            margin-bottom: 24px;
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
        .badge-danger { background: #fee2e2; color: #dc2626; }
        .badge-warning { background: #fef3c7; color: #d97706; }
        .badge-success { background: #d1fae5; color: #059669; }
        .badge-info { background: #dbeafe; color: #2563eb; }
        .badge-secondary { background: #f3f4f6; color: #6b7280; }
        .badge-purple { background: #ede9fe; color: #7c3aed; }
        .badge-rose { background: #fce7f3; color: #db2777; }
        
        .dashboard-card .card-body {
            padding: 16px 20px;
        }
        
        .workflow-pipeline {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
            align-items: center;
        }
        .workflow-step {
            flex: 1;
            min-width: 75px;
            text-align: center;
            padding: 10px 6px;
            background: #f3f4f6;
            border-radius: 8px;
        }
        .workflow-step .step-number {
            font-size: 20px;
            font-weight: 700;
        }
        .workflow-step .step-label {
            font-size: 10px;
            color: #6b7280;
            margin-top: 2px;
        }
        .workflow-step.active { background: #ede9fe; }
        .workflow-step.completed { background: #d1fae5; }
        .workflow-step.pending { background: #fef3c7; }
        .workflow-step.blocked { background: #fee2e2; }
        .workflow-step .step-number.active { color: #7209b7; }
        .workflow-step .step-number.completed { color: #06d6a0; }
        .workflow-step .step-number.pending { color: #d97706; }
        .workflow-step .step-number.blocked { color: #ef476f; }
        
        .workflow-arrow {
            font-size: 16px;
            color: #6b7280;
            flex-shrink: 0;
        }
        
        .activity-item {
            padding: 10px 0;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 13px;
        }
        .activity-item:last-child { border-bottom: none; }
        .activity-status {
            font-size: 10px;
            padding: 2px 10px;
            border-radius: 12px;
            font-weight: 600;
            display: inline-block;
        }
        .status-pending_payment { background: #fef3c7; color: #d97706; }
        .status-scheduled { background: #dbeafe; color: #2563eb; }
        .status-in_progress { background: #ede9fe; color: #7c3aed; }
        .status-completed { background: #d1fae5; color: #059669; }
        .status-reviewed { background: #d1fae5; color: #059669; }
        .status-cancelled { background: #fee2e2; color: #dc2626; }
        .status-pending { background: #fef3c7; color: #d97706; }
        
        .modality-badge {
            font-size: 10px;
            padding: 2px 10px;
            border-radius: 12px;
            font-weight: 600;
            display: inline-block;
        }
        .modality-xray { background: #dbeafe; color: #2563eb; }
        .modality-ct { background: #ede9fe; color: #7c3aed; }
        .modality-mri { background: #fce7f3; color: #db2777; }
        .modality-ultrasound { background: #d1fae5; color: #059669; }
        .modality-mammography { background: #fef3c7; color: #d97706; }
        .modality-fluoroscopy { background: #e0f2fe; color: #0284c7; }
        .modality-angiography { background: #fee2e2; color: #dc2626; }
        
        .activity-time {
            font-size: 11px;
            color: #6b7280;
        }
        
        .alert-item {
            padding: 10px 0;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            align-items: flex-start;
            gap: 10px;
            font-size: 13px;
        }
        .alert-item:last-child { border-bottom: none; }
        .alert-icon { font-size: 16px; margin-top: 2px; }
        .alert-content { flex: 1; }
        .alert-title { font-weight: 600; }
        .text-danger { color: #ef476f; }
        .text-warning { color: #d97706; }
        .text-muted { color: #6b7280; }
        
        .chart-container {
            position: relative;
            height: 240px;
        }
        .chart-container.tall {
            height: 200px;
        }
        
        .no-data {
            text-align: center;
            padding: 30px 0;
            color: #6b7280;
        }
        .no-data i {
            font-size: 28px;
            display: block;
            margin-bottom: 8px;
            color: #d1d5db;
        }
        
        .modality-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 8px;
        }
        .modality-item {
            padding: 10px 8px;
            border-radius: 8px;
            text-align: center;
            background: #f3f4f6;
        }
        .modality-item .modality-count {
            font-size: 20px;
            font-weight: 700;
        }
        .modality-item .modality-name {
            font-size: 10px;
            color: #6b7280;
        }
        
        .dashboard-footer {
            text-align: center;
            padding: 20px 0;
            color: #6b7280;
            font-size: 12px;
            border-top: 1px solid #e5e7eb;
            margin-top: 20px;
        }
        
        @media (max-width: 992px) {
            .dashboard-grid {
                grid-template-columns: 1fr 1fr;
            }
        }
        @media (max-width: 768px) {
            #page-wrapper {
                padding: 12px 12px 20px;
                padding-top: 80px;
            }
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 10px;
            }
            .stat-card {
                padding: 14px 16px;
            }
            .stat-card .stat-value {
                font-size: 22px;
            }
            .dashboard-grid {
                grid-template-columns: 1fr;
                gap: 14px;
            }
            .workflow-pipeline {
                flex-wrap: nowrap;
                overflow-x: auto;
                padding-bottom: 8px;
            }
            .workflow-step {
                min-width: 60px;
                padding: 8px 4px;
            }
            .workflow-step .step-number {
                font-size: 16px;
            }
            .workflow-arrow {
                font-size: 12px;
            }
            .dashboard-header h1 {
                font-size: 20px;
            }
            .header-actions .btn span {
                display: none;
            }
            .modality-grid {
                grid-template-columns: repeat(3, 1fr);
                gap: 6px;
            }
        }
        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr 1fr;
                gap: 8px;
            }
            .stat-card {
                padding: 10px 12px;
            }
            .stat-card .stat-value {
                font-size: 18px;
            }
            .stat-card .stat-label {
                font-size: 11px;
            }
            .modality-grid {
                grid-template-columns: repeat(2, 1fr);
            }
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
        <div class="dashboard-container">
            <!-- Dashboard Header -->
            <div class="dashboard-header">
                <h1>
                    <i class="fas fa-x-ray"></i> Radiology Dashboard
                    <span class="branch-badge">
                        <i class="fas fa-map-marker-alt" style="font-size:12px;"></i>
                        <?php echo htmlspecialchars($branch_name, ENT_QUOTES, 'UTF-8'); ?>
                    </span>
                </h1>
                <div class="header-actions">
                    <a href="radiology_order_new.php" class="btn btn-primary">
                        <i class="fas fa-plus"></i> <span>New Order</span>
                    </a>
                    <a href="radiology_reports.php" class="btn">
                        <i class="fas fa-chart-bar"></i> <span>Reports</span>
                    </a>
                    <button class="btn" onclick="location.reload();" title="Refresh">
                        <i class="fas fa-sync-alt"></i>
                    </button>
                </div>
            </div>

            <?php if (isset($error_message)): ?>
                <div class="error-message">
                    <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error_message); ?>
                </div>
            <?php endif; ?>

            <!-- ============================================ -->
            <!-- 1. SUMMARY STATISTICS -->
            <!-- ============================================ -->
            <div class="stats-grid">
                <div class="stat-card primary">
                    <div class="stat-icon"><i class="fas fa-x-ray"></i></div>
                    <div class="stat-value"><?php echo number_format($exam_volume['total_exams']); ?></div>
                    <div class="stat-label">Total Exams</div>
                    <div class="stat-change <?php echo $exam_volume['today_exams'] > 0 ? 'positive' : ''; ?>">
                        <i class="fas fa-calendar-day"></i> <?php echo $exam_volume['today_exams']; ?> today
                    </div>
                </div>
                
                <div class="stat-card warning">
                    <div class="stat-icon"><i class="fas fa-clock"></i></div>
                    <div class="stat-value <?php echo $pending_exams > 0 ? 'warning' : ''; ?>"><?php echo number_format($pending_exams); ?></div>
                    <div class="stat-label">Pending Exams</div>
                    <div class="stat-change">
                        <i class="fas fa-hourglass-half"></i> <?php echo $workflow_data['in_progress']; ?> in progress
                    </div>
                </div>
                
                <div class="stat-card success">
                    <div class="stat-icon"><i class="fas fa-check-circle"></i></div>
                    <div class="stat-value success"><?php echo number_format($workflow_data['completed'] + $workflow_data['reviewed']); ?></div>
                    <div class="stat-label">Completed/Reviewed</div>
                    <div class="stat-change positive">
                        <i class="fas fa-percentage"></i> <?php echo $completion_rate; ?>% completion rate
                    </div>
                </div>
                
                <div class="stat-card danger">
                    <div class="stat-icon"><i class="fas fa-exclamation-triangle"></i></div>
                    <div class="stat-value danger"><?php echo number_format($total_alerts); ?></div>
                    <div class="stat-label">Active Alerts</div>
                    <div class="stat-change negative">
                        <i class="fas fa-bell"></i> <?php echo $reporting_metrics['pending_interpretation']; ?> pending review
                    </div>
                </div>
                
                <div class="stat-card teal">
                    <div class="stat-icon"><i class="fas fa-money-bill-wave"></i></div>
                    <div class="stat-value primary"><?php echo number_format($revenue_data['this_month_revenue'], 2); ?></div>
                    <div class="stat-label">Revenue (This Month)</div>
                    <div class="stat-change">
                        <i class="fas fa-wallet"></i> <?php echo number_format($revenue_data['today_revenue'], 2); ?> today
                    </div>
                </div>
                
                <div class="stat-card orange">
                    <div class="stat-icon"><i class="fas fa-hourglass-end"></i></div>
                    <div class="stat-value"><?php echo $reporting_metrics['avg_tat_hours']; ?>h</div>
                    <div class="stat-label">Avg. Turnaround Time</div>
                    <div class="stat-change <?php echo $reporting_metrics['tat_exceeded'] > 0 ? 'negative' : 'positive'; ?>">
                        <i class="fas fa-clock"></i> <?php echo $reporting_metrics['tat_exceeded']; ?> overdue
                    </div>
                </div>
            </div>

            <!-- ============================================ -->
            <!-- 2. WORKFLOW PIPELINE -->
            <!-- ============================================ -->
            <div class="dashboard-card" style="margin-bottom: 20px;">
                <div class="card-header">
                    <h3><i class="fas fa-sitemap"></i> Workflow Pipeline</h3>
                    <span class="badge badge-purple"><?php echo $total_active_orders; ?> active orders</span>
                </div>
                <div class="card-body">
                    <div class="workflow-pipeline">
                        <?php
                        $steps = [
                            'pending_payment' => ['label' => 'Pending Payment', 'icon' => 'fa-credit-card', 'color' => 'pending'],
                            'scheduled' => ['label' => 'Scheduled', 'icon' => 'fa-calendar-check', 'color' => 'active'],
                            'in_progress' => ['label' => 'In Progress', 'icon' => 'fa-spinner fa-spin', 'color' => 'active'],
                            'completed' => ['label' => 'Completed', 'icon' => 'fa-check-circle', 'color' => 'completed'],
                            'reviewed' => ['label' => 'Reviewed', 'icon' => 'fa-check-double', 'color' => 'completed']
                        ];
                        $step_count = count($steps);
                        $idx = 0;
                        foreach ($steps as $key => $step):
                            $count = $workflow_data[$key] ?? 0;
                            $color_class = $step['color'];
                        ?>
                            <div class="workflow-step <?php echo $color_class; ?>">
                                <div class="step-number <?php echo $color_class; ?>"><?php echo number_format($count); ?></div>
                                <div class="step-label"><i class="fas <?php echo $step['icon']; ?>"></i> <?php echo $step['label']; ?></div>
                            </div>
                            <?php if ($idx < $step_count - 1): ?>
                                <span class="workflow-arrow"><i class="fas fa-chevron-right"></i></span>
                            <?php endif; ?>
                        <?php 
                            $idx++;
                        endforeach; 
                        ?>
                    </div>
                </div>
            </div>

            <!-- ============================================ -->
            <!-- 3. MAIN DASHBOARD GRID -->
            <!-- ============================================ -->
            <div class="dashboard-grid">
                <!-- 3.1. Exam Volume Chart -->
                <div class="dashboard-card">
                    <div class="card-header">
                        <h3><i class="fas fa-chart-bar"></i> Exam Volume (Last 7 Days)</h3>
                        <span class="badge badge-secondary"><?php echo $exam_volume['this_week']; ?> this week</span>
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="examVolumeChart"></canvas>
                        </div>
                    </div>
                </div>

                <!-- 3.2. Modality Breakdown -->
                <div class="dashboard-card">
                    <div class="card-header">
                        <h3><i class="fas fa-th-large"></i> Modality Distribution</h3>
                        <span class="badge badge-info"><?php echo array_sum($modality_breakdown); ?> total</span>
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="modalityChart"></canvas>
                        </div>
                    </div>
                </div>

                <!-- 3.3. Priority Distribution -->
                <div class="dashboard-card">
                    <div class="card-header">
                        <h3><i class="fas fa-tachometer-alt"></i> Priority Distribution</h3>
                        <span class="badge badge-danger"><?php echo $exam_volume['stat_exams']; ?> STAT</span>
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="priorityChart"></canvas>
                        </div>
                    </div>
                </div>

                <!-- 3.4. Quality & Reporting Metrics -->
                <div class="dashboard-card">
                    <div class="card-header">
                        <h3><i class="fas fa-clipboard-check"></i> Reporting Summary</h3>
                        <span class="badge badge-info">QC Metrics</span>
                    </div>
                    <div class="card-body">
                        <div style="display:grid;grid-template-columns:repeat(2,1fr);gap:10px;">
                            <div style="padding:12px;background:#fef3c7;border-radius:6px;text-align:center;">
                                <div style="font-size:20px;font-weight:700;color:#d97706;"><?php echo $reporting_metrics['pending_interpretation']; ?></div>
                                <div style="font-size:11px;color:#6b7280;">Pending Interpretation</div>
                            </div>
                            <div style="padding:12px;background:#dbeafe;border-radius:6px;text-align:center;">
                                <div style="font-size:20px;font-weight:700;color:#2563eb;"><?php echo $reporting_metrics['pending_review']; ?></div>
                                <div style="font-size:11px;color:#6b7280;">Pending Review</div>
                            </div>
                            <div style="padding:12px;background:#fee2e2;border-radius:6px;text-align:center;">
                                <div style="font-size:20px;font-weight:700;color:#dc2626;"><?php echo $reporting_metrics['tat_exceeded']; ?></div>
                                <div style="font-size:11px;color:#6b7280;">TAT Exceeded</div>
                            </div>
                            <div style="padding:12px;background:#d1fae5;border-radius:6px;text-align:center;">
                                <div style="font-size:20px;font-weight:700;color:#059669;"><?php echo $reporting_metrics['interpreted_today']; ?></div>
                                <div style="font-size:11px;color:#6b7280;">Interpreted Today</div>
                            </div>
                        </div>
                        <div style="margin-top:12px;text-align:center;">
                            <a href="radiology_interpretation.php" class="btn" style="font-size:12px;">
                                <i class="fas fa-arrow-right"></i> Review Pending
                            </a>
                        </div>
                    </div>
                </div>

                <!-- 3.5. Recent Activities -->
                <div class="dashboard-card">
                    <div class="card-header">
                        <h3><i class="fas fa-history"></i> Recent Orders</h3>
                        <a href="radiology_orders.php" class="btn" style="padding:4px 12px;font-size:12px;">View All</a>
                    </div>
                    <div class="card-body" style="max-height:320px;overflow-y:auto;">
                        <?php if (empty($recent_activities)): ?>
                            <div class="no-data">
                                <i class="fas fa-inbox"></i>
                                No recent orders
                            </div>
                        <?php else: ?>
                            <?php foreach ($recent_activities as $activity): ?>
                                <div class="activity-item">
                                    <div>
                                        <strong><?php echo htmlspecialchars($activity['order_number'] ?? 'N/A'); ?></strong>
                                        <div style="font-size:12px;color:#6b7280;">
                                            <?php echo htmlspecialchars($activity['patient_name'] ?? 'Unknown Patient'); ?>
                                            <span style="margin:0 4px;">•</span>
                                            Dr. <?php echo htmlspecialchars($activity['doctor_name'] ?? 'N/A'); ?>
                                        </div>
                                        <div style="font-size:11px;color:#6b7280;margin-top:2px;">
                                            <?php echo htmlspecialchars($activity['service_name'] ?? 'Unknown Service'); ?>
                                        </div>
                                    </div>
                                    <div style="text-align:right;">
                                        <span class="modality-badge modality-<?php echo $activity['modality'] ?? 'xray'; ?>">
                                            <?php echo ucfirst($activity['modality'] ?? 'X-Ray'); ?>
                                        </span>
                                        <span class="activity-status status-<?php echo str_replace('_', '', $activity['status'] ?? 'pending'); ?>" style="display:block;margin-top:4px;">
                                            <?php echo ucfirst(str_replace('_', ' ', $activity['status'] ?? 'Pending')); ?>
                                        </span>
                                        <?php if (($activity['priority'] ?? '') === 'stat'): ?>
                                            <span class="badge badge-danger" style="font-size:9px;margin-top:2px;">STAT</span>
                                        <?php endif; ?>
                                        <div class="activity-time">
                                            <?php echo date('M d, H:i', strtotime($activity['created_at'] ?? 'now')); ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- 3.6. Notifications -->
                <div class="dashboard-card">
                    <div class="card-header">
                        <h3><i class="fas fa-bell"></i> Notifications</h3>
                        <span class="badge badge-secondary"><?php echo count($notifications); ?> unread</span>
                    </div>
                    <div class="card-body" style="max-height:250px;overflow-y:auto;">
                        <?php if (empty($notifications)): ?>
                            <div class="no-data" style="padding:15px 0;">
                                <i class="fas fa-check-circle" style="color:#06d6a0;"></i>
                                No new notifications
                            </div>
                        <?php else: ?>
                            <?php foreach ($notifications as $notif): ?>
                                <div class="alert-item" style="border-bottom:1px solid #e5e7eb;padding:8px 0;">
                                    <span class="alert-icon"><i class="fas fa-info-circle" style="color:#7209b7;"></i></span>
                                    <div class="alert-content">
                                        <div style="font-weight:500;font-size:13px;"><?php echo htmlspecialchars($notif['title'] ?? 'Notification'); ?></div>
                                        <div style="font-size:12px;color:#6b7280;">
                                            <?php echo htmlspecialchars($notif['message'] ?? ''); ?>
                                            <span style="float:right;"><?php echo date('M d, H:i', strtotime($notif['created_at'] ?? 'now')); ?></span>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- ============================================ -->
            <!-- 4. FOOTER -->
            <!-- ============================================ -->
            <div class="dashboard-footer">
                <i class="fas fa-x-ray"></i> Radiology Dashboard &bull; HMS ERP &bull; 
                Last updated: <?php echo date('Y-m-d H:i:s'); ?>
                <?php if ($branch_id > 0): ?>
                    &bull; Branch: <?php echo htmlspecialchars($branch_name, ENT_QUOTES, 'UTF-8'); ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- ============================================ -->
<!-- 5. CHARTS INITIALIZATION -->
<!-- ============================================ -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // 5.1. Exam Volume Chart (Last 7 Days)
    const ctx1 = document.getElementById('examVolumeChart');
    if (ctx1) {
        <?php
        // Get last 7 days exam counts
        $daily_counts = [];
        $chart_params = [];
        $chart_filter = "";
        if (!$is_global_admin && $branch_id > 0) {
            $chart_filter = " AND branch_id = :branch_id";
            $chart_params[':branch_id'] = $branch_id;
        }
        for ($i = 6; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-$i days"));
            $sql = "SELECT COUNT(*) as count 
                    FROM radiology_orders 
                    WHERE DATE(created_at) = :date 
                    AND deleted_at IS NULL" . $chart_filter;
            $stmt = $pdo->prepare($sql);
            $stmt->execute(array_merge([':date' => $date], $chart_params));
            $daily_counts[$date] = (int)$stmt->fetchColumn();
        }
        ?>
        new Chart(ctx1, {
            type: 'bar',
            data: {
                labels: [
                    <?php
                    $dates = array_keys($daily_counts);
                    foreach ($dates as $d) {
                        echo "'" . date('M d', strtotime($d)) . "',";
                    }
                    ?>
                ],
                datasets: [{
                    label: 'Exams',
                    data: [
                        <?php
                        foreach ($daily_counts as $count) {
                            echo $count . ',';
                        }
                        ?>
                    ],
                    backgroundColor: 'rgba(114, 9, 183, 0.7)',
                    borderColor: '#7209b7',
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

    // 5.2. Modality Breakdown Chart
    const ctx2 = document.getElementById('modalityChart');
    if (ctx2) {
        new Chart(ctx2, {
            type: 'doughnut',
            data: {
                labels: ['X-Ray', 'CT', 'MRI', 'Ultrasound', 'Mammography', 'Fluoroscopy', 'Angiography'],
                datasets: [{
                    data: [
                        <?php echo $modality_breakdown['xray']; ?>,
                        <?php echo $modality_breakdown['ct']; ?>,
                        <?php echo $modality_breakdown['mri']; ?>,
                        <?php echo $modality_breakdown['ultrasound']; ?>,
                        <?php echo $modality_breakdown['mammography']; ?>,
                        <?php echo $modality_breakdown['fluoroscopy']; ?>,
                        <?php echo $modality_breakdown['angiography']; ?>
                    ],
                    backgroundColor: [
                        '#2563eb', '#7c3aed', '#db2777', 
                        '#059669', '#d97706', '#0284c7', '#dc2626'
                    ],
                    borderWidth: 2,
                    borderColor: '#fff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'right',
                        labels: {
                            padding: 8,
                            usePointStyle: true,
                            pointStyle: 'circle',
                            font: { size: 11 }
                        }
                    }
                },
                cutout: '60%'
            }
        });
    }

    // 5.3. Priority Distribution Chart
    const ctx3 = document.getElementById('priorityChart');
    if (ctx3) {
        new Chart(ctx3, {
            type: 'doughnut',
            data: {
                labels: ['STAT', 'Urgent', 'Routine'],
                datasets: [{
                    data: [
                        <?php echo $exam_volume['stat_exams']; ?>,
                        <?php echo $exam_volume['urgent_exams']; ?>,
                        <?php echo $exam_volume['routine_exams']; ?>
                    ],
                    backgroundColor: ['#ef476f', '#f9c74f', '#7209b7'],
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
                        labels: {
                            padding: 12,
                            usePointStyle: true,
                            pointStyle: 'circle'
                        }
                    }
                },
                cutout: '65%'
            }
        });
    }
});

// Auto-refresh every 60 seconds
setTimeout(function() {
    location.reload();
}, 60000);
</script>

</body>
</html>