<?php
/**
 * reference_ranges.php
 * 
 * HMS ERP - Reference Ranges Management
 * 
 * Features:
 * - View all reference ranges for tests
 * - Add/edit reference ranges
 * - Set normal ranges (min/max)
 * - Set critical values
 * - Set alert colors
 * - View test components with ranges
 * - Export reference ranges
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
// 2. ACTION HANDLING
// ============================================
$action = $_GET['action'] ?? $_POST['action'] ?? '';
$range_id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$success_message = '';
$error_message = '';

// Handle adding new reference range
if ($action === 'add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $metric_name = trim($_POST['metric_name'] ?? '');
    $min_normal = !empty($_POST['min_normal']) ? (float)$_POST['min_normal'] : null;
    $max_normal = !empty($_POST['max_normal']) ? (float)$_POST['max_normal'] : null;
    $critical_low = !empty($_POST['critical_low']) ? (float)$_POST['critical_low'] : null;
    $critical_high = !empty($_POST['critical_high']) ? (float)$_POST['critical_high'] : null;
    $alert_color = $_POST['alert_color'] ?? 'danger';
    $test_component_id = !empty($_POST['test_component_id']) ? (int)$_POST['test_component_id'] : null;

    if (empty($metric_name)) {
        $error_message = 'Please enter a metric name.';
    } else {
        try {
            // Check for duplicate
            $sql = "SELECT id FROM clinical_thresholds WHERE metric_name = :metric_name";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':metric_name' => $metric_name]);
            if ($stmt->fetch()) {
                throw new Exception('A reference range with this metric name already exists.');
            }

            $sql = "INSERT INTO clinical_thresholds (
                        metric_name, min_normal, max_normal,
                        critical_low, critical_high, alert_color,
                        updated_at
                    ) VALUES (
                        :metric_name, :min_normal, :max_normal,
                        :critical_low, :critical_high, :alert_color,
                        NOW()
                    )";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':metric_name' => $metric_name,
                ':min_normal' => $min_normal,
                ':max_normal' => $max_normal,
                ':critical_low' => $critical_low,
                ':critical_high' => $critical_high,
                ':alert_color' => $alert_color
            ]);
            $range_id = $pdo->lastInsertId();

            $success_message = "Reference range '{$metric_name}' added successfully!";

            // Log audit
            log_audit($pdo, $branch_id, $user_id, 'INSERT', 'clinical_thresholds', $range_id, [
                'metric_name' => $metric_name,
                'min_normal' => $min_normal,
                'max_normal' => $max_normal
            ]);

        } catch (Exception $e) {
            $error_message = 'Error: ' . $e->getMessage();
            error_log('Reference Range Add Error: ' . $e->getMessage());
        }
    }
}

// Handle editing reference range
if ($action === 'edit' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $range_id = (int)($_POST['range_id'] ?? 0);
    $metric_name = trim($_POST['metric_name'] ?? '');
    $min_normal = !empty($_POST['min_normal']) ? (float)$_POST['min_normal'] : null;
    $max_normal = !empty($_POST['max_normal']) ? (float)$_POST['max_normal'] : null;
    $critical_low = !empty($_POST['critical_low']) ? (float)$_POST['critical_low'] : null;
    $critical_high = !empty($_POST['critical_high']) ? (float)$_POST['critical_high'] : null;
    $alert_color = $_POST['alert_color'] ?? 'danger';

    if ($range_id <= 0 || empty($metric_name)) {
        $error_message = 'Please fill in all required fields.';
    } else {
        try {
            // Check for duplicate (excluding current)
            $sql = "SELECT id FROM clinical_thresholds 
                    WHERE metric_name = :metric_name AND id != :id";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':metric_name' => $metric_name, ':id' => $range_id]);
            if ($stmt->fetch()) {
                throw new Exception('A reference range with this metric name already exists.');
            }

            $sql = "UPDATE clinical_thresholds SET
                        metric_name = :metric_name,
                        min_normal = :min_normal,
                        max_normal = :max_normal,
                        critical_low = :critical_low,
                        critical_high = :critical_high,
                        alert_color = :alert_color,
                        updated_at = NOW()
                    WHERE id = :id";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':metric_name' => $metric_name,
                ':min_normal' => $min_normal,
                ':max_normal' => $max_normal,
                ':critical_low' => $critical_low,
                ':critical_high' => $critical_high,
                ':alert_color' => $alert_color,
                ':id' => $range_id
            ]);

            $success_message = "Reference range updated successfully!";

            // Log audit
            log_audit($pdo, $branch_id, $user_id, 'UPDATE', 'clinical_thresholds', $range_id, [
                'metric_name' => $metric_name
            ]);

        } catch (Exception $e) {
            $error_message = 'Error: ' . $e->getMessage();
            error_log('Reference Range Edit Error: ' . $e->getMessage());
        }
    }
}

// Handle deleting reference range
if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $range_id = (int)($_POST['range_id'] ?? 0);

    if ($range_id <= 0) {
        $error_message = 'Invalid reference range ID.';
    } else {
        try {
            $sql = "DELETE FROM clinical_thresholds WHERE id = :id";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':id' => $range_id]);

            $success_message = "Reference range deleted successfully.";

            // Log audit
            log_audit($pdo, $branch_id, $user_id, 'DELETE', 'clinical_thresholds', $range_id, []);

        } catch (Exception $e) {
            $error_message = 'Error: ' . $e->getMessage();
        }
    }
}

// ============================================
// 3. HELPER FUNCTIONS
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

// ============================================
// 4. DATA FETCHING
// ============================================

// Build filter parameters
$filter_metric = $_GET['metric'] ?? '';
$search = trim($_GET['search'] ?? '');

// Fetch reference ranges
$sql = "SELECT * FROM clinical_thresholds";
$where = [];
$params = [];

if (!empty($filter_metric)) {
    $where[] = "metric_name LIKE :metric";
    $params[':metric'] = "%{$filter_metric}%";
}
if (!empty($search)) {
    $where[] = "metric_name LIKE :search";
    $params[':search'] = "%{$search}%";
}

if (!empty($where)) {
    $sql .= " WHERE " . implode(" AND ", $where);
}

$sql .= " ORDER BY metric_name ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$reference_ranges = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get test components that have reference ranges
$sql = "SELECT 
            ltc.id, ltc.test_component_name, ltc.component_code,
            ltc.reference_range_low, ltc.reference_range_high,
            ltc.reference_range_text, ltc.result_unit,
            ltc.result_numeric, ltc.is_abnormal, ltc.is_critical,
            lo.order_number
        FROM lab_test_components ltc
        LEFT JOIN lab_orders lo ON ltc.lab_order_id = lo.id
        WHERE ltc.reference_range_low IS NOT NULL 
           OR ltc.reference_range_high IS NOT NULL
           OR ltc.reference_range_text IS NOT NULL
        ORDER BY ltc.test_component_name ASC
        LIMIT 50";
$test_components = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

// Get statistics
$stats = [
    'total' => count($reference_ranges),
    'has_min' => 0,
    'has_max' => 0,
    'has_critical_low' => 0,
    'has_critical_high' => 0
];

foreach ($reference_ranges as $range) {
    if ($range['min_normal'] !== null) $stats['has_min']++;
    if ($range['max_normal'] !== null) $stats['has_max']++;
    if ($range['critical_low'] !== null) $stats['has_critical_low']++;
    if ($range['critical_high'] !== null) $stats['has_critical_high']++;
}

// Get unique metric names for filter
$sql = "SELECT DISTINCT metric_name FROM clinical_thresholds ORDER BY metric_name";
$metrics = $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN);

// Handle AJAX request for getting range details
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_range' && isset($_GET['id'])) {
    header('Content-Type: application/json');
    $range_id = (int)$_GET['id'];
    
    try {
        $sql = "SELECT * FROM clinical_thresholds WHERE id = :id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':id' => $range_id]);
        $range = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($range) {
            echo json_encode(['success' => true, 'range' => $range]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Range not found']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reference Ranges | HMS ERP</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
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
        .page-header h1 i { color: #f97316; }
        
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
        .btn:hover { background: #f3f4f6; border-color: #f97316; }
        .btn-primary { background: #f97316; color: white; border-color: #f97316; }
        .btn-primary:hover { background: #ea580c; border-color: #ea580c; color: white; }
        .btn-success { background: #06d6a0; color: white; border-color: #06d6a0; }
        .btn-success:hover { background: #05b88a; border-color: #05b88a; }
        .btn-danger { background: #ef476f; color: white; border-color: #ef476f; }
        .btn-danger:hover { background: #d63e5e; border-color: #d63e5e; }
        .btn-warning { background: #f9c74f; color: #1f2937; border-color: #f9c74f; }
        .btn-warning:hover { background: #f0b832; border-color: #f0b832; }
        .btn-sm { padding: 4px 10px; font-size: 12px; }
        .btn-outline { background: transparent; border-color: #e5e7eb; }
        
        .alert-success {
            background: #d1fae5;
            border: 1px solid #a7f3d0;
            color: #065f46;
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 16px;
        }
        .alert-danger {
            background: #fee2e2;
            border: 1px solid #fecaca;
            color: #991b1b;
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 16px;
        }
        
        /* Stats Bar */
        .stats-bar {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 20px;
        }
        .stat-pill {
            background: white;
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 13px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08);
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .stat-pill .count {
            font-weight: 700;
            font-size: 16px;
        }
        .stat-pill .label { color: #6b7280; }
        .stat-pill.total { border-left: 3px solid #f97316; }
        .stat-pill.min { border-left: 3px solid #4cc9f0; }
        .stat-pill.max { border-left: 3px solid #06d6a0; }
        .stat-pill.critical-low { border-left: 3px solid #ef476f; }
        .stat-pill.critical-high { border-left: 3px solid #ef476f; }
        
        /* Filter Bar */
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
        
        /* Cards */
        .card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08);
            overflow: hidden;
            margin-bottom: 20px;
        }
        .card-header {
            padding: 14px 20px;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .card-header h3 {
            font-size: 15px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .card-body { padding: 16px 20px; }
        
        .badge {
            padding: 3px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            display: inline-block;
        }
        .badge-danger { background: #fee2e2; color: #991b1b; }
        .badge-warning { background: #fef3c7; color: #92400e; }
        .badge-success { background: #d1fae5; color: #065f46; }
        .badge-info { background: #dbeafe; color: #1e40af; }
        .badge-primary { background: #dbeafe; color: #1e40af; }
        
        .badge-color-danger { background: #ef476f; color: white; }
        .badge-color-warning { background: #f9c74f; color: #1f2937; }
        .badge-color-success { background: #06d6a0; color: white; }
        .badge-color-info { background: #4cc9f0; color: #1f2937; }
        
        /* Table */
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
            white-space: nowrap;
        }
        .table-container td {
            padding: 10px 12px;
            border-bottom: 1px solid #e5e7eb;
            vertical-align: middle;
        }
        .table-container tr:hover { background: #f9fafb; }
        .table-container .no-data {
            text-align: center;
            padding: 40px 0;
            color: #6b7280;
        }
        .table-container .no-data i {
            font-size: 36px;
            display: block;
            margin-bottom: 10px;
            color: #d1d5db;
        }
        
        .order-actions {
            display: flex;
            gap: 4px;
            flex-wrap: wrap;
        }
        .order-actions .btn { padding: 2px 8px; font-size: 11px; }
        
        /* Range Display */
        .range-display {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .range-display .range-bar {
            width: 120px;
            height: 8px;
            border-radius: 4px;
            background: #e5e7eb;
            position: relative;
        }
        .range-display .range-bar .range-fill {
            height: 100%;
            border-radius: 4px;
            background: #06d6a0;
        }
        .range-display .range-bar .critical-low-mark {
            position: absolute;
            left: 0;
            top: -4px;
            width: 2px;
            height: 16px;
            background: #ef476f;
        }
        .range-display .range-bar .critical-high-mark {
            position: absolute;
            right: 0;
            top: -4px;
            width: 2px;
            height: 16px;
            background: #ef476f;
        }
        
        /* Modal */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.5);
            z-index: 1050;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }
        .modal-overlay.active { display: flex; }
        .modal {
            background: white;
            border-radius: 12px;
            max-width: 600px;
            width: 100%;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 20px 60px rgba(0,0,0,0.2);
        }
        .modal-header {
            padding: 16px 20px;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            background: white;
            z-index: 1;
            border-radius: 12px 12px 0 0;
        }
        .modal-header h2 { font-size: 18px; font-weight: 600; }
        .modal-close {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: #6b7280;
        }
        .modal-close:hover { color: #1f2937; }
        .modal-body { padding: 20px; }
        .modal-footer {
            padding: 16px 20px;
            border-top: 1px solid #e5e7eb;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            position: sticky;
            bottom: 0;
            background: white;
            border-radius: 0 0 12px 12px;
        }
        
        .form-group { margin-bottom: 16px; }
        .form-group label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 4px;
            color: #374151;
        }
        .form-group .required { color: #ef476f; }
        .form-control {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            font-size: 13px;
            transition: border-color 0.2s;
        }
        .form-control:focus {
            outline: none;
            border-color: #f97316;
            box-shadow: 0 0 0 3px rgba(249, 115, 22, 0.1);
        }
        select.form-control { appearance: auto; }
        textarea.form-control { resize: vertical; min-height: 60px; }
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }
        .form-check {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .form-check input[type="checkbox"] {
            width: 16px;
            height: 16px;
            accent-color: #f97316;
        }
        
        .text-muted { color: #6b7280; }
        .text-center { text-align: center; }
        .mt-2 { margin-top: 8px; }
        .mb-2 { margin-bottom: 8px; }
        .flex { display: flex; }
        .flex-wrap { flex-wrap: wrap; }
        .items-center { align-items: center; }
        .gap-2 { gap: 8px; }
        .w-full { width: 100%; }
        
        /* Test Components Section */
        .component-item {
            padding: 8px 12px;
            border-bottom: 1px solid #f3f4f6;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 13px;
        }
        .component-item:last-child { border-bottom: none; }
        .component-item .component-range {
            font-size: 12px;
            color: #6b7280;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .filter-bar { flex-direction: column; align-items: stretch; }
            .filter-bar select, .filter-bar input { width: 100%; }
            .stats-bar { gap: 6px; }
            .stat-pill { font-size: 11px; padding: 4px 10px; }
            .page-header h1 { font-size: 20px; }
            .card-body { padding: 12px; }
            .table-container { padding: 0; overflow-x: auto; }
            .form-row { grid-template-columns: 1fr; }
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
            <h1><i class="fas fa-ruler-vertical"></i> Reference Ranges</h1>
            <div>
                <button class="btn btn-primary" onclick="openModal('addModal')">
                    <i class="fas fa-plus"></i> Add Range
                </button>
                <button class="btn" onclick="exportCSV()">
                    <i class="fas fa-file-export"></i> Export
                </button>
                <button class="btn" onclick="window.location.reload();">
                    <i class="fas fa-sync-alt"></i> Refresh
                </button>
            </div>
        </div>

        <?php if ($success_message): ?>
            <div class="alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success_message); ?></div>
        <?php endif; ?>
        <?php if ($error_message): ?>
            <div class="alert-danger"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error_message); ?></div>
        <?php endif; ?>

        <!-- Stats Bar -->
        <div class="stats-bar">
            <div class="stat-pill total">
                <span class="count"><?php echo $stats['total']; ?></span>
                <span class="label">Total Ranges</span>
            </div>
            <div class="stat-pill min">
                <span class="count" style="color:#4cc9f0;"><?php echo $stats['has_min']; ?></span>
                <span class="label">With Min</span>
            </div>
            <div class="stat-pill max">
                <span class="count" style="color:#06d6a0;"><?php echo $stats['has_max']; ?></span>
                <span class="label">With Max</span>
            </div>
            <div class="stat-pill critical-low">
                <span class="count" style="color:#ef476f;"><?php echo $stats['has_critical_low']; ?></span>
                <span class="label">Critical Low</span>
            </div>
            <div class="stat-pill critical-high">
                <span class="count" style="color:#ef476f;"><?php echo $stats['has_critical_high']; ?></span>
                <span class="label">Critical High</span>
            </div>
        </div>

        <!-- Filter Bar -->
        <div class="filter-bar">
            <div>
                <span class="filter-label">Metric</span>
                <select id="filterMetric" onchange="applyFilters()">
                    <option value="">All</option>
                    <?php foreach ($metrics as $metric): ?>
                        <option value="<?php echo htmlspecialchars($metric); ?>" <?php echo $filter_metric === $metric ? 'selected' : ''; ?>>
                            <?php echo ucfirst(str_replace('_', ' ', $metric)); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="flex:1;min-width:150px;">
                <input type="text" class="form-control" id="filterSearch" placeholder="Search metric name..." 
                       value="<?php echo htmlspecialchars($search); ?>" onkeypress="if(event.key==='Enter') applyFilters()">
            </div>
            <button class="btn btn-primary" onclick="applyFilters()"><i class="fas fa-search"></i> Filter</button>
            <button class="btn" onclick="resetFilters()"><i class="fas fa-times"></i> Reset</button>
        </div>

        <!-- Reference Ranges Table -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-list"></i> Reference Ranges</h3>
                <span class="text-muted"><?php echo count($reference_ranges); ?> ranges</span>
            </div>
            <div class="card-body">
                <div class="table-container">
                    <table id="rangesTable">
                        <thead>
                            <tr>
                                <th>Metric</th>
                                <th>Normal Range</th>
                                <th>Critical Range</th>
                                <th>Alert Color</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($reference_ranges)): ?>
                                <tr>
                                    <td colspan="5" class="no-data">
                                        <i class="fas fa-ruler-vertical"></i>
                                        No reference ranges found
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($reference_ranges as $range): ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo htmlspecialchars($range['metric_name']); ?></strong>
                                        </td>
                                        <td>
                                            <?php if ($range['min_normal'] !== null || $range['max_normal'] !== null): ?>
                                                <div class="range-display">
                                                    <span>
                                                        <?php 
                                                        echo ($range['min_normal'] !== null ? $range['min_normal'] : '∞');
                                                        echo ' - ';
                                                        echo ($range['max_normal'] !== null ? $range['max_normal'] : '∞');
                                                        ?>
                                                    </span>
                                                    <div class="range-bar">
                                                        <div class="range-fill" style="width:60%;"></div>
                                                        <?php if ($range['critical_low'] !== null): ?>
                                                            <div class="critical-low-mark" style="left:10%;"></div>
                                                        <?php endif; ?>
                                                        <?php if ($range['critical_high'] !== null): ?>
                                                            <div class="critical-high-mark" style="right:10%;"></div>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            <?php else: ?>
                                                <span class="text-muted">Not set</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($range['critical_low'] !== null || $range['critical_high'] !== null): ?>
                                                <?php 
                                                echo ($range['critical_low'] !== null ? '< ' . $range['critical_low'] : '');
                                                echo ($range['critical_low'] !== null && $range['critical_high'] !== null ? ' or ' : '');
                                                echo ($range['critical_high'] !== null ? '> ' . $range['critical_high'] : '');
                                                ?>
                                            <?php else: ?>
                                                <span class="text-muted">Not set</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge badge-color-<?php echo $range['alert_color'] ?? 'danger'; ?>">
                                                <?php echo ucfirst($range['alert_color'] ?? 'danger'); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="order-actions">
                                                <button class="btn btn-sm btn-warning" onclick="editRange(<?php echo $range['id']; ?>)">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button class="btn btn-sm btn-danger" onclick="deleteRange(<?php echo $range['id']; ?>, '<?php echo htmlspecialchars($range['metric_name']); ?>')">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Test Components with Reference Ranges -->
        <?php if (!empty($test_components)): ?>
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-flask"></i> Test Components with Reference Ranges</h3>
                <span class="text-muted"><?php echo count($test_components); ?> components</span>
            </div>
            <div class="card-body">
                <?php foreach ($test_components as $component): ?>
                    <div class="component-item">
                        <div>
                            <strong><?php echo htmlspecialchars($component['test_component_name']); ?></strong>
                            <?php if ($component['component_code']): ?>
                                <span class="text-muted">(<?php echo htmlspecialchars($component['component_code']); ?>)</span>
                            <?php endif; ?>
                            <?php if ($component['order_number']): ?>
                                <span class="text-muted">- Order: <?php echo htmlspecialchars($component['order_number']); ?></span>
                            <?php endif; ?>
                            <?php if ($component['result_unit']): ?>
                                <span class="text-muted">(<?php echo htmlspecialchars($component['result_unit']); ?>)</span>
                            <?php endif; ?>
                        </div>
                        <div class="component-range">
                            <?php if ($component['reference_range_text']): ?>
                                <?php echo htmlspecialchars($component['reference_range_text']); ?>
                            <?php elseif ($component['reference_range_low'] !== null && $component['reference_range_high'] !== null): ?>
                                <?php echo $component['reference_range_low'] . ' - ' . $component['reference_range_high']; ?>
                                <?php if ($component['result_unit']): ?>
                                    <?php echo ' ' . htmlspecialchars($component['result_unit']); ?>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="text-muted">No range set</span>
                            <?php endif; ?>
                            <?php if ($component['is_abnormal']): ?>
                                <span class="badge badge-warning">Abnormal</span>
                            <?php endif; ?>
                            <?php if ($component['is_critical']): ?>
                                <span class="badge badge-danger">Critical</span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ============================================ -->
<!-- ADD RANGE MODAL -->
<!-- ============================================ -->
<div class="modal-overlay" id="addModal">
    <div class="modal">
        <div class="modal-header">
            <h2><i class="fas fa-plus-circle"></i> Add Reference Range</h2>
            <button class="modal-close" onclick="closeModal('addModal')">&times;</button>
        </div>
        <form method="POST" action="">
            <input type="hidden" name="action" value="add">
            <div class="modal-body">
                <div class="form-group">
                    <label>Metric Name <span class="required">*</span></label>
                    <input type="text" name="metric_name" class="form-control" required placeholder="e.g., blood_glucose, temperature">
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Normal Range Min</label>
                        <input type="number" name="min_normal" class="form-control" step="any" placeholder="e.g., 70">
                    </div>
                    <div class="form-group">
                        <label>Normal Range Max</label>
                        <input type="number" name="max_normal" class="form-control" step="any" placeholder="e.g., 100">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Critical Low</label>
                        <input type="number" name="critical_low" class="form-control" step="any" placeholder="e.g., 50">
                    </div>
                    <div class="form-group">
                        <label>Critical High</label>
                        <input type="number" name="critical_high" class="form-control" step="any" placeholder="e.g., 300">
                    </div>
                </div>

                <div class="form-group">
                    <label>Alert Color</label>
                    <select name="alert_color" class="form-control">
                        <option value="danger">Red (Danger)</option>
                        <option value="warning">Yellow (Warning)</option>
                        <option value="info">Blue (Info)</option>
                        <option value="success">Green (Success)</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" onclick="closeModal('addModal')">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Add Range</button>
            </div>
        </form>
    </div>
</div>

<!-- ============================================ -->
<!-- EDIT RANGE MODAL -->
<!-- ============================================ -->
<div class="modal-overlay" id="editModal">
    <div class="modal">
        <div class="modal-header">
            <h2><i class="fas fa-edit"></i> Edit Reference Range</h2>
            <button class="modal-close" onclick="closeModal('editModal')">&times;</button>
        </div>
        <form method="POST" action="">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="range_id" id="editRangeId">
            <div class="modal-body" id="editFormContent">
                <p class="text-muted text-center">Loading range details...</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" onclick="closeModal('editModal')">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Update Range</button>
            </div>
        </form>
    </div>
</div>

<script>
// ============================================
// MODAL FUNCTIONS
// ============================================
function openModal(id) {
    document.getElementById(id).classList.add('active');
    document.body.style.overflow = 'hidden';
}

function closeModal(id) {
    document.getElementById(id).classList.remove('active');
    document.body.style.overflow = '';
}

document.querySelectorAll('.modal-overlay').forEach(overlay => {
    overlay.addEventListener('click', function(e) {
        if (e.target === this) {
            this.classList.remove('active');
            document.body.style.overflow = '';
        }
    });
});

// ============================================
// RANGE ACTIONS
// ============================================
function editRange(rangeId) {
    document.getElementById('editRangeId').value = rangeId;
    document.getElementById('editFormContent').innerHTML = '<p class="text-muted text-center"><i class="fas fa-spinner fa-spin"></i> Loading...</p>';
    openModal('editModal');
    
    fetch('?ajax=get_range&id=' + rangeId)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const range = data.range;
                let html = `
                    <div class="form-group">
                        <label>Metric Name <span class="required">*</span></label>
                        <input type="text" name="metric_name" class="form-control" required value="${range.metric_name}">
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Normal Range Min</label>
                            <input type="number" name="min_normal" class="form-control" step="any" value="${range.min_normal || ''}">
                        </div>
                        <div class="form-group">
                            <label>Normal Range Max</label>
                            <input type="number" name="max_normal" class="form-control" step="any" value="${range.max_normal || ''}">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Critical Low</label>
                            <input type="number" name="critical_low" class="form-control" step="any" value="${range.critical_low || ''}">
                        </div>
                        <div class="form-group">
                            <label>Critical High</label>
                            <input type="number" name="critical_high" class="form-control" step="any" value="${range.critical_high || ''}">
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Alert Color</label>
                        <select name="alert_color" class="form-control">
                            <option value="danger" ${range.alert_color === 'danger' ? 'selected' : ''}>Red (Danger)</option>
                            <option value="warning" ${range.alert_color === 'warning' ? 'selected' : ''}>Yellow (Warning)</option>
                            <option value="info" ${range.alert_color === 'info' ? 'selected' : ''}>Blue (Info)</option>
                            <option value="success" ${range.alert_color === 'success' ? 'selected' : ''}>Green (Success)</option>
                        </select>
                    </div>
                `;
                document.getElementById('editFormContent').innerHTML = html;
            } else {
                document.getElementById('editFormContent').innerHTML = '<p class="text-danger">Error loading range details.</p>';
            }
        })
        .catch(error => {
            document.getElementById('editFormContent').innerHTML = '<p class="text-danger">Error loading range details.</p>';
            console.error('Error:', error);
        });
}

function deleteRange(rangeId, metricName) {
    if (!confirm(`Are you sure you want to delete the reference range for "${metricName}"? This action cannot be undone.`)) return;
    
    const form = document.createElement('form');
    form.method = 'POST';
    form.innerHTML = `
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="range_id" value="${rangeId}">
    `;
    document.body.appendChild(form);
    form.submit();
}

// ============================================
// FILTER FUNCTIONS
// ============================================
function applyFilters() {
    const metric = document.getElementById('filterMetric').value;
    const search = document.getElementById('filterSearch').value;
    
    let url = window.location.pathname + '?';
    if (metric) url += 'metric=' + encodeURIComponent(metric) + '&';
    if (search) url += 'search=' + encodeURIComponent(search) + '&';
    window.location.href = url;
}

function resetFilters() {
    window.location.href = window.location.pathname;
}

// ============================================
// EXPORT CSV
// ============================================
function exportCSV() {
    window.location.href = 'ajax/export_reference_ranges_csv.php' + window.location.search;
}

// ============================================
// KEYBOARD SHORTCUTS
// ============================================
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal-overlay.active').forEach(modal => {
            modal.classList.remove('active');
        });
        document.body.style.overflow = '';
    }
    if (e.ctrlKey && e.key === 'n') {
        e.preventDefault();
        openModal('addModal');
    }
});

// ============================================
// DATATABLES INITIALIZATION
// ============================================
$(document).ready(function() {
    var hasRows = $('#rangesTable tbody tr:not(.no-data)').length > 0;
    
    if (hasRows) {
        $('#rangesTable').DataTable({
            responsive: true,
            pageLength: 25,
            order: [[0, 'asc']],
            columnDefs: [
                { orderable: false, targets: [4] }
            ],
            language: {
                emptyTable: "No reference ranges found",
                search: "Search:",
                lengthMenu: "Show _MENU_ entries",
                info: "Showing _START_ to _END_ of _TOTAL_ entries"
            }
        });
    } else {
        $('#rangesTable').DataTable({
            responsive: true,
            pageLength: 25,
            order: [[0, 'asc']],
            columnDefs: [
                { orderable: false, targets: [4] }
            ],
            language: {
                emptyTable: "No reference ranges found"
            }
        });
    }
});
</script>

<!-- DataTables -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>

</body>
</html>