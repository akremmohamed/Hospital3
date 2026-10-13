<?php
/**
 * radiology_reports.php
 * 
 * HMS ERP - Radiology Reports & Analytics
 * 
 * Features:
 * - Workload reports (daily/weekly/monthly)
 * - Turnaround Time (TAT) analysis
 * - Revenue reports
 * - Equipment utilization
 * - Modality distribution
 * - Export to PDF/Excel
 * - Interactive charts
 * - Date range filtering
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

// Validate user session
$user_id = (int)($_SESSION['user_id'] ?? 0);
$branch_id = (int)($_SESSION['branch_id'] ?? 0);
$user_type = $_SESSION['user_type'] ?? '';
$is_global_admin = ($user_type === 'super_admin');
$has_radiology_access = in_array($user_type, ['radiologist', 'radiology_technician', 'super_admin', 'admin']);

if ($user_id <= 0) {
    header('Location: login.php');
    exit;
}

if (!$has_radiology_access) {
    header('Location: dashboard.php?error=unauthorized');
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
// 2. GET PARAMETERS
// ============================================
$report_type = $_GET['type'] ?? 'workload';
$date_from = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
$date_to = $_GET['date_to'] ?? date('Y-m-d');
$period = $_GET['period'] ?? 'monthly'; // daily, weekly, monthly, yearly
$export = $_GET['export'] ?? '';

// ============================================
// 3. HELPER FUNCTIONS
// ============================================
function getModalityColor($modality) {
    $colors = [
        'xray' => '#2563eb',
        'ct' => '#7c3aed',
        'mri' => '#db2777',
        'ultrasound' => '#059669',
        'mammography' => '#d97706',
        'fluoroscopy' => '#0284c7',
        'angiography' => '#dc2626'
    ];
    return $colors[$modality] ?? '#6b7280';
}

function getModalityIcon($modality) {
    $icons = [
        'xray' => 'fa-x-ray',
        'ct' => 'fa-microscope',
        'mri' => 'fa-magnet',
        'ultrasound' => 'fa-wave-square',
        'mammography' => 'fa-x-ray',
        'fluoroscopy' => 'fa-video',
        'angiography' => 'fa-heart'
    ];
    return $icons[$modality] ?? 'fa-image';
}

function formatCurrency($amount) {
    return 'ETB ' . number_format($amount, 2);
}

function getStatusBadge($status) {
    $badges = [
        'completed' => 'success',
        'reviewed' => 'success',
        'in_progress' => 'warning',
        'scheduled' => 'info',
        'pending_payment' => 'secondary',
        'cancelled' => 'danger'
    ];
    return $badges[$status] ?? 'secondary';
}

// ============================================
// 4. DATA FETCHING
// ============================================

$branch_filter = "";
$params = [];
if (!$is_global_admin && $branch_id > 0) {
    $branch_filter = " AND branch_id = :branch_id";
    $params[':branch_id'] = $branch_id;
}

// ============================================
// 4a. WORKLOAD REPORT
// ============================================
$workload_data = [];
$workload_chart_data = [];

if ($report_type === 'workload' || $report_type === 'all') {
    // Get workload statistics
    $sql = "SELECT 
                COUNT(*) as total_orders,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN status = 'reviewed' THEN 1 ELSE 0 END) as reviewed,
                SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress,
                SUM(CASE WHEN status = 'scheduled' THEN 1 ELSE 0 END) as scheduled,
                SUM(CASE WHEN status = 'pending_payment' THEN 1 ELSE 0 END) as pending_payment,
                SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled,
                SUM(CASE WHEN priority = 'stat' THEN 1 ELSE 0 END) as stat_count,
                SUM(CASE WHEN priority = 'urgent' THEN 1 ELSE 0 END) as urgent_count,
                SUM(CASE WHEN priority = 'routine' THEN 1 ELSE 0 END) as routine_count,
                AVG(TIMESTAMPDIFF(HOUR, created_at, performed_at)) as avg_tat_hours
            FROM radiology_orders
            WHERE deleted_at IS NULL
            AND created_at BETWEEN :date_from AND :date_to" . $branch_filter;
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge($params, [
        ':date_from' => $date_from . ' 00:00:00',
        ':date_to' => $date_to . ' 23:59:59'
    ]));
    $workload_data = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$workload_data) {
        $workload_data = [
            'total_orders' => 0,
            'completed' => 0,
            'reviewed' => 0,
            'in_progress' => 0,
            'scheduled' => 0,
            'pending_payment' => 0,
            'cancelled' => 0,
            'stat_count' => 0,
            'urgent_count' => 0,
            'routine_count' => 0,
            'avg_tat_hours' => 0
        ];
    }
    
    // Daily workload for chart
    $sql = "SELECT 
                DATE(created_at) as date,
                COUNT(*) as total,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed
            FROM radiology_orders
            WHERE deleted_at IS NULL
            AND created_at BETWEEN :date_from AND :date_to" . $branch_filter . "
            GROUP BY DATE(created_at)
            ORDER BY date ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge($params, [
        ':date_from' => $date_from . ' 00:00:00',
        ':date_to' => $date_to . ' 23:59:59'
    ]));
    $workload_chart_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ============================================
// 4b. MODALITY DISTRIBUTION
// ============================================
$modality_data = [];
if ($report_type === 'modality' || $report_type === 'all') {
    $sql = "SELECT 
                modality,
                COUNT(*) as count,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress,
                SUM(CASE WHEN status = 'scheduled' THEN 1 ELSE 0 END) as scheduled
            FROM radiology_orders
            WHERE deleted_at IS NULL
            AND modality IS NOT NULL
            AND created_at BETWEEN :date_from AND :date_to" . $branch_filter . "
            GROUP BY modality
            ORDER BY count DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge($params, [
        ':date_from' => $date_from . ' 00:00:00',
        ':date_to' => $date_to . ' 23:59:59'
    ]));
    $modality_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ============================================
// 4c. REVENUE REPORT
// ============================================
$revenue_data = [];
$revenue_chart_data = [];
if ($report_type === 'revenue' || $report_type === 'all') {
    // Revenue from radiology services (using standard_price from radiology_services)
    $sql = "SELECT 
                SUM(rs.standard_price) as total_revenue,
                SUM(CASE WHEN ro.payment_status = 'paid' THEN rs.standard_price ELSE 0 END) as collected,
                SUM(CASE WHEN ro.payment_status IN ('unpaid', 'partial') THEN rs.standard_price ELSE 0 END) as pending
            FROM radiology_orders ro
            LEFT JOIN radiology_services rs ON ro.service_code = rs.service_code
            WHERE ro.deleted_at IS NULL
            AND ro.status NOT IN ('cancelled')
            AND ro.created_at BETWEEN :date_from AND :date_to" . $branch_filter;
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge($params, [
        ':date_from' => $date_from . ' 00:00:00',
        ':date_to' => $date_to . ' 23:59:59'
    ]));
    $revenue_data = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$revenue_data) {
        $revenue_data = ['total_revenue' => 0, 'collected' => 0, 'pending' => 0];
    }
    
    // Daily revenue for chart
    $sql = "SELECT 
                DATE(ro.created_at) as date,
                SUM(rs.standard_price) as revenue
            FROM radiology_orders ro
            LEFT JOIN radiology_services rs ON ro.service_code = rs.service_code
            WHERE ro.deleted_at IS NULL
            AND ro.status NOT IN ('cancelled')
            AND ro.created_at BETWEEN :date_from AND :date_to" . $branch_filter . "
            GROUP BY DATE(ro.created_at)
            ORDER BY date ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge($params, [
        ':date_from' => $date_from . ' 00:00:00',
        ':date_to' => $date_to . ' 23:59:59'
    ]));
    $revenue_chart_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ============================================
// 4d. TURNAROUND TIME (TAT) ANALYSIS
// ============================================
$tat_data = [];
if ($report_type === 'tat' || $report_type === 'all') {
    $sql = "SELECT 
                AVG(TIMESTAMPDIFF(HOUR, created_at, performed_at)) as avg_tat,
                MIN(TIMESTAMPDIFF(HOUR, created_at, performed_at)) as min_tat,
                MAX(TIMESTAMPDIFF(HOUR, created_at, performed_at)) as max_tat,
                COUNT(*) as total_completed,
                SUM(CASE WHEN TIMESTAMPDIFF(HOUR, created_at, performed_at) <= 24 THEN 1 ELSE 0 END) as within_24h,
                SUM(CASE WHEN TIMESTAMPDIFF(HOUR, created_at, performed_at) > 24 AND TIMESTAMPDIFF(HOUR, created_at, performed_at) <= 48 THEN 1 ELSE 0 END) as within_48h,
                SUM(CASE WHEN TIMESTAMPDIFF(HOUR, created_at, performed_at) > 48 AND TIMESTAMPDIFF(HOUR, created_at, performed_at) <= 72 THEN 1 ELSE 0 END) as within_72h,
                SUM(CASE WHEN TIMESTAMPDIFF(HOUR, created_at, performed_at) > 72 THEN 1 ELSE 0 END) as beyond_72h
            FROM radiology_orders
            WHERE deleted_at IS NULL
            AND status IN ('completed', 'reviewed')
            AND performed_at IS NOT NULL
            AND created_at BETWEEN :date_from AND :date_to" . $branch_filter;
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge($params, [
        ':date_from' => $date_from . ' 00:00:00',
        ':date_to' => $date_to . ' 23:59:59'
    ]));
    $tat_data = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$tat_data) {
        $tat_data = [
            'avg_tat' => 0,
            'min_tat' => 0,
            'max_tat' => 0,
            'total_completed' => 0,
            'within_24h' => 0,
            'within_48h' => 0,
            'within_72h' => 0,
            'beyond_72h' => 0
        ];
    }
}

// ============================================
// 4e. EQUIPMENT UTILIZATION
// ============================================
$equipment_data = [];
if ($report_type === 'equipment' || $report_type === 'all') {
    $sql = "SELECT 
                mea.id, mea.equipment_name, mea.model_number,
                mea.current_status,
                COUNT(ro.id) as total_orders,
                SUM(CASE WHEN ro.status = 'completed' THEN 1 ELSE 0 END) as completed_orders
            FROM medical_equipment_assets mea
            LEFT JOIN radiology_orders ro ON ro.modality = mea.equipment_name
                AND ro.deleted_at IS NULL
                AND ro.created_at BETWEEN :date_from AND :date_to
            WHERE mea.deleted_at IS NULL" . $branch_filter . "
            GROUP BY mea.id, mea.equipment_name, mea.model_number, mea.current_status
            ORDER BY total_orders DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge($params, [
        ':date_from' => $date_from . ' 00:00:00',
        ':date_to' => $date_to . ' 23:59:59'
    ]));
    $equipment_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ============================================
// 4f. DOCTOR REFERRAL ANALYSIS
// ============================================
$doctor_data = [];
if ($report_type === 'doctor' || $report_type === 'all') {
    $sql = "SELECT 
                CONCAT(u.first_name, ' ', u.last_name) as doctor_name,
                u.id as doctor_id,
                COUNT(ro.id) as total_referrals,
                SUM(CASE WHEN ro.status = 'completed' THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN ro.status = 'reviewed' THEN 1 ELSE 0 END) as reviewed,
                SUM(CASE WHEN ro.priority = 'stat' THEN 1 ELSE 0 END) as stat_orders
            FROM radiology_orders ro
            LEFT JOIN users u ON ro.doctor_id = u.id
            WHERE ro.deleted_at IS NULL
            AND ro.doctor_id IS NOT NULL
            AND ro.created_at BETWEEN :date_from AND :date_to" . $branch_filter . "
            GROUP BY u.id, u.first_name, u.last_name
            ORDER BY total_referrals DESC
            LIMIT 20";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge($params, [
        ':date_from' => $date_from . ' 00:00:00',
        ':date_to' => $date_to . ' 23:59:59'
    ]));
    $doctor_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ============================================
// 4g. BODY PART ANALYSIS
// ============================================
$body_part_data = [];
if ($report_type === 'bodypart' || $report_type === 'all') {
    $sql = "SELECT 
                body_part,
                COUNT(*) as count,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed
            FROM radiology_orders
            WHERE deleted_at IS NULL
            AND body_part IS NOT NULL AND body_part != ''
            AND created_at BETWEEN :date_from AND :date_to" . $branch_filter . "
            GROUP BY body_part
            ORDER BY count DESC
            LIMIT 15";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge($params, [
        ':date_from' => $date_from . ' 00:00:00',
        ':date_to' => $date_to . ' 23:59:59'
    ]));
    $body_part_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ============================================
// 5. EXPORT HANDLING
// ============================================
if ($export === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="radiology_report_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Date', 'Order #', 'Patient', 'Modality', 'Service', 'Status', 'Priority', 'Payment']);
    
    $sql = "SELECT 
                ro.order_number, ro.modality, ro.service_name, ro.status, ro.priority, ro.payment_status,
                ro.created_at, CONCAT(p.first_name, ' ', p.last_name) as patient_name
            FROM radiology_orders ro
            LEFT JOIN patients p ON ro.patient_id = p.id
            WHERE ro.deleted_at IS NULL
            AND ro.created_at BETWEEN :date_from AND :date_to" . $branch_filter . "
            ORDER BY ro.created_at DESC
            LIMIT 1000";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge($params, [
        ':date_from' => $date_from . ' 00:00:00',
        ':date_to' => $date_to . ' 23:59:59'
    ]));
    
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($output, [
            $row['created_at'],
            $row['order_number'],
            $row['patient_name'],
            $row['modality'] ?? 'N/A',
            $row['service_name'] ?? 'N/A',
            $row['status'] ?? 'N/A',
            $row['priority'] ?? 'N/A',
            $row['payment_status'] ?? 'N/A'
        ]);
    }
    fclose($output);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Radiology Reports | HMS ERP</title>
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
        .page-header h1 i { color: #7209b7; }
        .page-header .subtitle {
            font-size: 14px;
            color: #6b7280;
            font-weight: 400;
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
        .btn:hover { background: #f3f4f6; border-color: #7209b7; }
        .btn-primary { background: #7209b7; color: white; border-color: #7209b7; }
        .btn-primary:hover { background: #560bad; border-color: #560bad; color: white; }
        .btn-success { background: #06d6a0; color: white; border-color: #06d6a0; }
        .btn-success:hover { background: #05b88a; border-color: #05b88a; }
        .btn-sm { padding: 4px 10px; font-size: 12px; }
        
        .filter-bar {
            background: white;
            padding: 14px 18px;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08);
            margin-bottom: 20px;
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: center;
        }
        .filter-bar select, .filter-bar input {
            padding: 6px 12px;
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            font-size: 13px;
            background: white;
            min-width: 120px;
        }
        .filter-bar .filter-label {
            font-size: 12px;
            color: #6b7280;
            font-weight: 500;
        }
        
        .report-tabs {
            display: flex;
            gap: 4px;
            background: #f3f4f6;
            padding: 4px;
            border-radius: 8px;
            flex-wrap: wrap;
        }
        .report-tabs .tab-btn {
            padding: 6px 14px;
            border: none;
            background: transparent;
            border-radius: 6px;
            cursor: pointer;
            font-size: 12px;
            font-weight: 500;
            color: #6b7280;
            transition: all 0.2s;
        }
        .report-tabs .tab-btn:hover { background: #e5e7eb; }
        .report-tabs .tab-btn.active {
            background: white;
            color: #7209b7;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 12px;
            margin-bottom: 20px;
        }
        .stat-card {
            background: white;
            padding: 16px 18px;
            border-radius: 10px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08);
            text-align: center;
            border-left: 3px solid #7209b7;
        }
        .stat-card .stat-number {
            font-size: 26px;
            font-weight: 700;
        }
        .stat-card .stat-label {
            font-size: 12px;
            color: #6b7280;
        }
        .stat-card .stat-icon {
            font-size: 18px;
            margin-bottom: 4px;
        }
        .stat-card.success { border-left-color: #06d6a0; }
        .stat-card.success .stat-number { color: #06d6a0; }
        .stat-card.warning { border-left-color: #f9c74f; }
        .stat-card.warning .stat-number { color: #d97706; }
        .stat-card.danger { border-left-color: #ef476f; }
        .stat-card.danger .stat-number { color: #ef476f; }
        .stat-card.info { border-left-color: #4cc9f0; }
        .stat-card.info .stat-number { color: #4cc9f0; }
        .stat-card.primary { border-left-color: #7209b7; }
        .stat-card.primary .stat-number { color: #7209b7; }
        
        .report-section {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08);
            margin-bottom: 20px;
        }
        .report-section .section-title {
            font-size: 16px;
            font-weight: 600;
            margin-bottom: 16px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .chart-container {
            position: relative;
            height: 300px;
        }
        .chart-container.small {
            height: 250px;
        }
        
        .report-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        
        .table-wrap {
            overflow-x: auto;
        }
        .table-wrap table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }
        .table-wrap th {
            text-align: left;
            padding: 8px 12px;
            border-bottom: 2px solid #e5e7eb;
            font-weight: 600;
            color: #6b7280;
            font-size: 11px;
            text-transform: uppercase;
        }
        .table-wrap td {
            padding: 8px 12px;
            border-bottom: 1px solid #e5e7eb;
        }
        .table-wrap tr:hover { background: #f9fafb; }
        .table-wrap .text-right { text-align: right; }
        
        .badge {
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            display: inline-block;
        }
        .badge-success { background: #d1fae5; color: #065f46; }
        .badge-warning { background: #fef3c7; color: #92400e; }
        .badge-info { background: #dbeafe; color: #1e40af; }
        .badge-danger { background: #fee2e2; color: #991b1b; }
        .badge-secondary { background: #f3f4f6; color: #6b7280; }
        
        .modality-badge {
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 10px;
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
        
        .text-muted { color: #6b7280; }
        .text-center { text-align: center; }
        .mt-2 { margin-top: 8px; }
        .mb-2 { margin-bottom: 8px; }
        
        @media (max-width: 992px) {
            .report-grid { grid-template-columns: 1fr; }
        }
        @media (max-width: 768px) {
            .filter-bar { flex-direction: column; align-items: stretch; }
            .filter-bar select, .filter-bar input { width: 100%; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .page-header h1 { font-size: 20px; }
            .report-tabs .tab-btn { padding: 4px 10px; font-size: 11px; }
        }
        @media (max-width: 480px) {
            .stats-grid { grid-template-columns: 1fr 1fr; }
        }
        
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: #f3f4f6; border-radius: 4px; }
        ::-webkit-scrollbar-thumb { background: #e5e7eb; border-radius: 4px; }
        ::-webkit-scrollbar-thumb:hover { background: #6b7280; }
        
        .progress-bar {
            height: 8px;
            background: #e5e7eb;
            border-radius: 4px;
            overflow: hidden;
            margin-top: 4px;
        }
        .progress-bar .progress-fill {
            height: 100%;
            border-radius: 4px;
            transition: width 0.6s ease;
        }
    </style>
</head>
<body>
<div id="wrapper">
    <?php include('assets/inc/nav.php'); ?>
    <?php include('assets/inc/sidebar.php'); ?>
    
    <div id="page-wrapper">
        <div class="page-header">
            <div>
                <h1>
                    <i class="fas fa-chart-bar"></i> Radiology Reports
                    <span class="subtitle"><?php echo date('M d, Y', strtotime($date_from)); ?> - <?php echo date('M d, Y', strtotime($date_to)); ?></span>
                </h1>
            </div>
            <div>
                <button class="btn btn-success" onclick="window.print()">
                    <i class="fas fa-print"></i> Print
                </button>
                <a href="?<?php echo http_build_query(array_merge($_GET, ['export' => 'csv'])); ?>" class="btn btn-primary">
                    <i class="fas fa-file-csv"></i> Export CSV
                </a>
            </div>
        </div>

        <!-- Filter Bar -->
        <div class="filter-bar">
            <div>
                <span class="filter-label">Report Type</span>
                <select id="reportType" onchange="applyFilters()">
                    <option value="workload" <?php echo $report_type === 'workload' ? 'selected' : ''; ?>>Workload</option>
                    <option value="modality" <?php echo $report_type === 'modality' ? 'selected' : ''; ?>>Modality</option>
                    <option value="revenue" <?php echo $report_type === 'revenue' ? 'selected' : ''; ?>>Revenue</option>
                    <option value="tat" <?php echo $report_type === 'tat' ? 'selected' : ''; ?>>TAT Analysis</option>
                    <option value="equipment" <?php echo $report_type === 'equipment' ? 'selected' : ''; ?>>Equipment</option>
                    <option value="doctor" <?php echo $report_type === 'doctor' ? 'selected' : ''; ?>>Doctor Referrals</option>
                    <option value="bodypart" <?php echo $report_type === 'bodypart' ? 'selected' : ''; ?>>Body Parts</option>
                    <option value="all" <?php echo $report_type === 'all' ? 'selected' : ''; ?>>All Reports</option>
                </select>
            </div>
            <div>
                <span class="filter-label">Date From</span>
                <input type="date" id="dateFrom" value="<?php echo $date_from; ?>" onchange="applyFilters()">
            </div>
            <div>
                <span class="filter-label">To</span>
                <input type="date" id="dateTo" value="<?php echo $date_to; ?>" onchange="applyFilters()">
            </div>
            <button class="btn btn-primary" onclick="applyFilters()"><i class="fas fa-search"></i> Apply</button>
            <button class="btn" onclick="resetFilters()"><i class="fas fa-times"></i> Reset</button>
        </div>

        <!-- Report Tabs -->
        <div class="report-tabs">
            <button class="tab-btn <?php echo $report_type === 'workload' ? 'active' : ''; ?>" onclick="setReportType('workload')">
                <i class="fas fa-tachometer-alt"></i> Workload
            </button>
            <button class="tab-btn <?php echo $report_type === 'modality' ? 'active' : ''; ?>" onclick="setReportType('modality')">
                <i class="fas fa-th-large"></i> Modality
            </button>
            <button class="tab-btn <?php echo $report_type === 'revenue' ? 'active' : ''; ?>" onclick="setReportType('revenue')">
                <i class="fas fa-money-bill-wave"></i> Revenue
            </button>
            <button class="tab-btn <?php echo $report_type === 'tat' ? 'active' : ''; ?>" onclick="setReportType('tat')">
                <i class="fas fa-hourglass-half"></i> TAT
            </button>
            <button class="tab-btn <?php echo $report_type === 'equipment' ? 'active' : ''; ?>" onclick="setReportType('equipment')">
                <i class="fas fa-microscope"></i> Equipment
            </button>
            <button class="tab-btn <?php echo $report_type === 'doctor' ? 'active' : ''; ?>" onclick="setReportType('doctor')">
                <i class="fas fa-user-md"></i> Referrals
            </button>
            <button class="tab-btn <?php echo $report_type === 'bodypart' ? 'active' : ''; ?>" onclick="setReportType('bodypart')">
                <i class="fas fa-bone"></i> Body Parts
            </button>
            <button class="tab-btn <?php echo $report_type === 'all' ? 'active' : ''; ?>" onclick="setReportType('all')">
                <i class="fas fa-list"></i> All
            </button>
        </div>

        <!-- ============================================ -->
        <!-- WORKLOAD REPORT -->
        <!-- ============================================ -->
        <?php if ($report_type === 'workload' || $report_type === 'all'): ?>
        <div class="stats-grid">
            <div class="stat-card primary">
                <div class="stat-icon"><i class="fas fa-clipboard-list"></i></div>
                <div class="stat-number"><?php echo number_format($workload_data['total_orders'] ?? 0); ?></div>
                <div class="stat-label">Total Orders</div>
            </div>
            <div class="stat-card success">
                <div class="stat-icon"><i class="fas fa-check-circle"></i></div>
                <div class="stat-number"><?php echo number_format(($workload_data['completed'] ?? 0) + ($workload_data['reviewed'] ?? 0)); ?></div>
                <div class="stat-label">Completed / Reviewed</div>
            </div>
            <div class="stat-card warning">
                <div class="stat-icon"><i class="fas fa-clock"></i></div>
                <div class="stat-number"><?php echo number_format(($workload_data['in_progress'] ?? 0) + ($workload_data['scheduled'] ?? 0)); ?></div>
                <div class="stat-label">In Progress / Scheduled</div>
            </div>
            <div class="stat-card danger">
                <div class="stat-icon"><i class="fas fa-exclamation-triangle"></i></div>
                <div class="stat-number"><?php echo number_format($workload_data['stat_count'] ?? 0); ?></div>
                <div class="stat-label">STAT Orders</div>
            </div>
            <div class="stat-card info">
                <div class="stat-icon"><i class="fas fa-hourglass-end"></i></div>
                <div class="stat-number"><?php echo round($workload_data['avg_tat_hours'] ?? 0, 1); ?>h</div>
                <div class="stat-label">Avg. Turnaround Time</div>
            </div>
        </div>

        <div class="report-section">
            <div class="section-title">
                <span><i class="fas fa-chart-line"></i> Daily Workload Trend</span>
                <span class="text-muted" style="font-size:12px;"><?php echo count($workload_chart_data); ?> days</span>
            </div>
            <div class="chart-container">
                <canvas id="workloadChart"></canvas>
            </div>
        </div>

        <div class="report-grid">
            <div class="report-section">
                <div class="section-title"><i class="fas fa-chart-pie"></i> Status Distribution</div>
                <div class="chart-container small">
                    <canvas id="statusChart"></canvas>
                </div>
            </div>
            <div class="report-section">
                <div class="section-title"><i class="fas fa-chart-pie"></i> Priority Distribution</div>
                <div class="chart-container small">
                    <canvas id="priorityChart"></canvas>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- ============================================ -->
        <!-- MODALITY REPORT -->
        <!-- ============================================ -->
        <?php if ($report_type === 'modality' || $report_type === 'all'): ?>
        <div class="report-section">
            <div class="section-title">
                <span><i class="fas fa-th-large"></i> Modality Distribution</span>
                <span class="text-muted" style="font-size:12px;">Total: <?php echo array_sum(array_column($modality_data, 'count')); ?></span>
            </div>
            <div class="report-grid">
                <div class="chart-container">
                    <canvas id="modalityChart"></canvas>
                </div>
                <div>
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Modality</th>
                                    <th class="text-right">Total</th>
                                    <th class="text-right">Completed</th>
                                    <th class="text-right">In Progress</th>
                                    <th class="text-right">%</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $total_modality = array_sum(array_column($modality_data, 'count'));
                                foreach ($modality_data as $mod): 
                                ?>
                                <tr>
                                    <td>
                                        <span class="modality-badge modality-<?php echo $mod['modality'] ?? 'xray'; ?>">
                                            <i class="fas <?php echo getModalityIcon($mod['modality'] ?? 'xray'); ?>"></i>
                                            <?php echo ucfirst($mod['modality'] ?? 'X-Ray'); ?>
                                        </span>
                                    </td>
                                    <td class="text-right"><?php echo $mod['count']; ?></td>
                                    <td class="text-right"><?php echo $mod['completed'] ?? 0; ?></td>
                                    <td class="text-right"><?php echo $mod['in_progress'] ?? 0; ?></td>
                                    <td class="text-right">
                                        <?php echo $total_modality > 0 ? round(($mod['count'] / $total_modality) * 100, 1) : 0; ?>%
                                        <div class="progress-bar">
                                            <div class="progress-fill" style="width:<?php echo $total_modality > 0 ? round(($mod['count'] / $total_modality) * 100, 1) : 0; ?>%;background:<?php echo getModalityColor($mod['modality'] ?? 'xray'); ?>;"></div>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (empty($modality_data)): ?>
                                <tr><td colspan="5" class="text-center text-muted">No modality data available</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- ============================================ -->
        <!-- REVENUE REPORT -->
        <!-- ============================================ -->
        <?php if ($report_type === 'revenue' || $report_type === 'all'): ?>
        <div class="stats-grid">
            <div class="stat-card success">
                <div class="stat-icon"><i class="fas fa-money-bill-wave"></i></div>
                <div class="stat-number"><?php echo formatCurrency($revenue_data['total_revenue'] ?? 0); ?></div>
                <div class="stat-label">Total Revenue</div>
            </div>
            <div class="stat-card primary">
                <div class="stat-icon"><i class="fas fa-check-circle"></i></div>
                <div class="stat-number"><?php echo formatCurrency($revenue_data['collected'] ?? 0); ?></div>
                <div class="stat-label">Collected</div>
            </div>
            <div class="stat-card warning">
                <div class="stat-icon"><i class="fas fa-clock"></i></div>
                <div class="stat-number"><?php echo formatCurrency($revenue_data['pending'] ?? 0); ?></div>
                <div class="stat-label">Pending</div>
            </div>
        </div>

        <div class="report-section">
            <div class="section-title">
                <span><i class="fas fa-chart-bar"></i> Revenue Trend</span>
            </div>
            <div class="chart-container">
                <canvas id="revenueChart"></canvas>
            </div>
        </div>
        <?php endif; ?>

        <!-- ============================================ -->
        <!-- TAT REPORT -->
        <!-- ============================================ -->
        <?php if ($report_type === 'tat' || $report_type === 'all'): ?>
        <div class="stats-grid">
            <div class="stat-card info">
                <div class="stat-icon"><i class="fas fa-hourglass-half"></i></div>
                <div class="stat-number"><?php echo round($tat_data['avg_tat'] ?? 0, 1); ?>h</div>
                <div class="stat-label">Average TAT</div>
            </div>
            <div class="stat-card success">
                <div class="stat-icon"><i class="fas fa-arrow-up"></i></div>
                <div class="stat-number"><?php echo round($tat_data['min_tat'] ?? 0, 1); ?>h</div>
                <div class="stat-label">Minimum TAT</div>
            </div>
            <div class="stat-card danger">
                <div class="stat-icon"><i class="fas fa-arrow-down"></i></div>
                <div class="stat-number"><?php echo round($tat_data['max_tat'] ?? 0, 1); ?>h</div>
                <div class="stat-label">Maximum TAT</div>
            </div>
            <div class="stat-card primary">
                <div class="stat-icon"><i class="fas fa-check-double"></i></div>
                <div class="stat-number"><?php echo number_format($tat_data['total_completed'] ?? 0); ?></div>
                <div class="stat-label">Total Completed</div>
            </div>
        </div>

        <div class="report-section">
            <div class="section-title">
                <span><i class="fas fa-chart-bar"></i> TAT Distribution</span>
            </div>
            <div class="chart-container">
                <canvas id="tatChart"></canvas>
            </div>
            <div class="table-wrap mt-2">
                <table>
                    <thead>
                        <tr>
                            <th>Category</th>
                            <th class="text-right">Count</th>
                            <th class="text-right">Percentage</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $tat_categories = [
                            ['label' => '≤ 24 hours', 'key' => 'within_24h', 'color' => '#06d6a0'],
                            ['label' => '24-48 hours', 'key' => 'within_48h', 'color' => '#4cc9f0'],
                            ['label' => '48-72 hours', 'key' => 'within_72h', 'color' => '#f9c74f'],
                            ['label' => '> 72 hours', 'key' => 'beyond_72h', 'color' => '#ef476f']
                        ];
                        $total_completed = $tat_data['total_completed'] ?? 1;
                        foreach ($tat_categories as $cat):
                            $count = $tat_data[$cat['key']] ?? 0;
                            $pct = $total_completed > 0 ? round(($count / $total_completed) * 100, 1) : 0;
                        ?>
                        <tr>
                            <td><span style="display:inline-block;width:12px;height:12px;border-radius:3px;background:<?php echo $cat['color']; ?>;margin-right:8px;"></span><?php echo $cat['label']; ?></td>
                            <td class="text-right"><?php echo number_format($count); ?></td>
                            <td class="text-right"><?php echo $pct; ?>%</td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- ============================================ -->
        <!-- EQUIPMENT REPORT -->
        <!-- ============================================ -->
        <?php if ($report_type === 'equipment' || $report_type === 'all'): ?>
        <div class="report-section">
            <div class="section-title">
                <span><i class="fas fa-microscope"></i> Equipment Utilization</span>
            </div>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Equipment</th>
                            <th>Model</th>
                            <th>Status</th>
                            <th class="text-right">Total Orders</th>
                            <th class="text-right">Completed</th>
                            <th class="text-right">Utilization</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $max_orders = !empty($equipment_data) ? max(array_column($equipment_data, 'total_orders')) : 1;
                        foreach ($equipment_data as $eq):
                            $utilization = $max_orders > 0 ? round(($eq['total_orders'] / $max_orders) * 100, 1) : 0;
                            $status_colors = [
                                'operational' => 'success',
                                'under_maintenance' => 'warning',
                                'broken_down' => 'danger',
                                'decommissioned' => 'secondary'
                            ];
                        ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($eq['equipment_name']); ?></strong></td>
                            <td><?php echo htmlspecialchars($eq['model_number'] ?? 'N/A'); ?></td>
                            <td>
                                <span class="badge badge-<?php echo $status_colors[$eq['current_status']] ?? 'secondary'; ?>">
                                    <?php echo ucfirst(str_replace('_', ' ', $eq['current_status'] ?? 'Unknown')); ?>
                                </span>
                            </td>
                            <td class="text-right"><?php echo $eq['total_orders']; ?></td>
                            <td class="text-right"><?php echo $eq['completed_orders'] ?? 0; ?></td>
                            <td class="text-right">
                                <?php echo $utilization; ?>%
                                <div class="progress-bar">
                                    <div class="progress-fill" style="width:<?php echo $utilization; ?>%;background:<?php echo $utilization > 70 ? '#06d6a0' : ($utilization > 30 ? '#f9c74f' : '#ef476f'); ?>;"></div>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($equipment_data)): ?>
                        <tr><td colspan="6" class="text-center text-muted">No equipment data available</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- ============================================ -->
        <!-- DOCTOR REFERRAL REPORT -->
        <!-- ============================================ -->
        <?php if ($report_type === 'doctor' || $report_type === 'all'): ?>
        <div class="report-section">
            <div class="section-title">
                <span><i class="fas fa-user-md"></i> Top Referring Doctors</span>
            </div>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Doctor</th>
                            <th class="text-right">Total Referrals</th>
                            <th class="text-right">Completed</th>
                            <th class="text-right">Reviewed</th>
                            <th class="text-right">STAT</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $rank = 1;
                        foreach ($doctor_data as $doc): 
                        ?>
                        <tr>
                            <td><?php echo $rank++; ?></td>
                            <td><strong><?php echo htmlspecialchars($doc['doctor_name'] ?? 'Unknown'); ?></strong></td>
                            <td class="text-right"><?php echo $doc['total_referrals']; ?></td>
                            <td class="text-right"><?php echo $doc['completed'] ?? 0; ?></td>
                            <td class="text-right"><?php echo $doc['reviewed'] ?? 0; ?></td>
                            <td class="text-right"><span class="badge badge-danger"><?php echo $doc['stat_orders'] ?? 0; ?></span></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($doctor_data)): ?>
                        <tr><td colspan="6" class="text-center text-muted">No doctor referral data available</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- ============================================ -->
        <!-- BODY PART REPORT -->
        <!-- ============================================ -->
        <?php if ($report_type === 'bodypart' || $report_type === 'all'): ?>
        <div class="report-section">
            <div class="section-title">
                <span><i class="fas fa-bone"></i> Most Common Body Parts</span>
            </div>
            <div class="report-grid">
                <div class="chart-container">
                    <canvas id="bodyPartChart"></canvas>
                </div>
                <div>
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Body Part</th>
                                    <th class="text-right">Count</th>
                                    <th class="text-right">Completed</th>
                                    <th class="text-right">%</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $total_body = array_sum(array_column($body_part_data, 'count'));
                                foreach ($body_part_data as $bp): 
                                ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($bp['body_part']); ?></td>
                                    <td class="text-right"><?php echo $bp['count']; ?></td>
                                    <td class="text-right"><?php echo $bp['completed'] ?? 0; ?></td>
                                    <td class="text-right">
                                        <?php echo $total_body > 0 ? round(($bp['count'] / $total_body) * 100, 1) : 0; ?>%
                                        <div class="progress-bar">
                                            <div class="progress-fill" style="width:<?php echo $total_body > 0 ? round(($bp['count'] / $total_body) * 100, 1) : 0; ?>%;background:#7209b7;"></div>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (empty($body_part_data)): ?>
                                <tr><td colspan="4" class="text-center text-muted">No body part data available</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
// ============================================
// FILTER FUNCTIONS
// ============================================
function applyFilters() {
    const type = document.getElementById('reportType').value;
    const dateFrom = document.getElementById('dateFrom').value;
    const dateTo = document.getElementById('dateTo').value;
    
    let url = window.location.pathname + '?';
    if (type) url += 'type=' + encodeURIComponent(type) + '&';
    if (dateFrom) url += 'date_from=' + encodeURIComponent(dateFrom) + '&';
    if (dateTo) url += 'date_to=' + encodeURIComponent(dateTo) + '&';
    window.location.href = url;
}

function resetFilters() {
    window.location.href = window.location.pathname + '?type=workload';
}

function setReportType(type) {
    let url = window.location.pathname + '?type=' + type;
    const dateFrom = document.getElementById('dateFrom')?.value || '';
    const dateTo = document.getElementById('dateTo')?.value || '';
    if (dateFrom) url += '&date_from=' + dateFrom;
    if (dateTo) url += '&date_to=' + dateTo;
    window.location.href = url;
}

// ============================================
// CHARTS INITIALIZATION
// ============================================
document.addEventListener('DOMContentLoaded', function() {
    <?php if ($report_type === 'workload' || $report_type === 'all'): ?>
    // Workload Chart
    const ctx1 = document.getElementById('workloadChart');
    if (ctx1) {
        const labels = <?php echo json_encode(array_column($workload_chart_data, 'date')); ?>;
        const totals = <?php echo json_encode(array_column($workload_chart_data, 'total')); ?>;
        const completed = <?php echo json_encode(array_column($workload_chart_data, 'completed')); ?>;
        
        new Chart(ctx1, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [
                    {
                        label: 'Total Orders',
                        data: totals,
                        backgroundColor: 'rgba(114, 9, 183, 0.6)',
                        borderColor: '#7209b7',
                        borderWidth: 2,
                        borderRadius: 4
                    },
                    {
                        label: 'Completed',
                        data: completed,
                        backgroundColor: 'rgba(6, 214, 160, 0.6)',
                        borderColor: '#06d6a0',
                        borderWidth: 2,
                        borderRadius: 4
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'top',
                        labels: { usePointStyle: true, padding: 20 }
                    }
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

    // Status Distribution Chart
    const ctx2 = document.getElementById('statusChart');
    if (ctx2) {
        new Chart(ctx2, {
            type: 'doughnut',
            data: {
                labels: ['Completed', 'Reviewed', 'In Progress', 'Scheduled', 'Pending Payment', 'Cancelled'],
                datasets: [{
                    data: [
                        <?php echo $workload_data['completed'] ?? 0; ?>,
                        <?php echo $workload_data['reviewed'] ?? 0; ?>,
                        <?php echo $workload_data['in_progress'] ?? 0; ?>,
                        <?php echo $workload_data['scheduled'] ?? 0; ?>,
                        <?php echo $workload_data['pending_payment'] ?? 0; ?>,
                        <?php echo $workload_data['cancelled'] ?? 0; ?>
                    ],
                    backgroundColor: ['#06d6a0', '#4cc9f0', '#f9c74f', '#4361ee', '#6b7280', '#ef476f'],
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
                        labels: { usePointStyle: true, padding: 12 }
                    }
                },
                cutout: '60%'
            }
        });
    }

    // Priority Distribution Chart
    const ctx3 = document.getElementById('priorityChart');
    if (ctx3) {
        new Chart(ctx3, {
            type: 'doughnut',
            data: {
                labels: ['STAT', 'Urgent', 'Routine'],
                datasets: [{
                    data: [
                        <?php echo $workload_data['stat_count'] ?? 0; ?>,
                        <?php echo $workload_data['urgent_count'] ?? 0; ?>,
                        <?php echo $workload_data['routine_count'] ?? 0; ?>
                    ],
                    backgroundColor: ['#ef476f', '#f9c74f', '#4361ee'],
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
                        labels: { usePointStyle: true, padding: 12 }
                    }
                },
                cutout: '60%'
            }
        });
    }
    <?php endif; ?>

    <?php if ($report_type === 'modality' || $report_type === 'all'): ?>
    // Modality Distribution Chart
    const ctx4 = document.getElementById('modalityChart');
    if (ctx4) {
        const modalityLabels = <?php echo json_encode(array_column($modality_data, 'modality')); ?>;
        const modalityCounts = <?php echo json_encode(array_column($modality_data, 'count')); ?>;
        const modalityColors = modalityLabels.map(m => <?php echo json_encode(getModalityColor('xray')); ?>);
        
        new Chart(ctx4, {
            type: 'bar',
            data: {
                labels: modalityLabels.map(m => m ? m.toUpperCase() : 'Unknown'),
                datasets: [{
                    label: 'Orders',
                    data: modalityCounts,
                    backgroundColor: modalityColors.map(c => c + '80'),
                    borderColor: modalityColors,
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

    <?php if ($report_type === 'revenue' || $report_type === 'all'): ?>
    // Revenue Chart
    const ctx5 = document.getElementById('revenueChart');
    if (ctx5) {
        const revLabels = <?php echo json_encode(array_column($revenue_chart_data, 'date')); ?>;
        const revData = <?php echo json_encode(array_column($revenue_chart_data, 'revenue')); ?>;
        
        new Chart(ctx5, {
            type: 'line',
            data: {
                labels: revLabels,
                datasets: [{
                    label: 'Revenue (ETB)',
                    data: revData,
                    backgroundColor: 'rgba(6, 214, 160, 0.1)',
                    borderColor: '#06d6a0',
                    borderWidth: 2,
                    fill: true,
                    tension: 0.3,
                    pointBackgroundColor: '#06d6a0',
                    pointRadius: 3
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
                            callback: function(value) { return 'ETB ' + value.toLocaleString(); }
                        }
                    }
                }
            }
        });
    }
    <?php endif; ?>

    <?php if ($report_type === 'tat' || $report_type === 'all'): ?>
    // TAT Distribution Chart
    const ctx6 = document.getElementById('tatChart');
    if (ctx6) {
        new Chart(ctx6, {
            type: 'bar',
            data: {
                labels: ['≤ 24h', '24-48h', '48-72h', '> 72h'],
                datasets: [{
                    label: 'Orders',
                    data: [
                        <?php echo $tat_data['within_24h'] ?? 0; ?>,
                        <?php echo $tat_data['within_48h'] ?? 0; ?>,
                        <?php echo $tat_data['within_72h'] ?? 0; ?>,
                        <?php echo $tat_data['beyond_72h'] ?? 0; ?>
                    ],
                    backgroundColor: ['#06d6a0', '#4cc9f0', '#f9c74f', '#ef476f'],
                    borderWidth: 2,
                    borderColor: '#fff',
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

    <?php if ($report_type === 'bodypart' || $report_type === 'all'): ?>
    // Body Part Chart
    const ctx7 = document.getElementById('bodyPartChart');
    if (ctx7) {
        const bpLabels = <?php echo json_encode(array_column($body_part_data, 'body_part')); ?>;
        const bpCounts = <?php echo json_encode(array_column($body_part_data, 'count')); ?>;
        
        new Chart(ctx7, {
            type: 'bar',
            data: {
                labels: bpLabels,
                datasets: [{
                    label: 'Orders',
                    data: bpCounts,
                    backgroundColor: 'rgba(114, 9, 183, 0.6)',
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
    <?php endif; ?>
});
</script>

</body>
</html>