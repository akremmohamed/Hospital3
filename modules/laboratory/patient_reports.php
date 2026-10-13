<?php
/**
 * patient_reports.php
 * 
 * HMS ERP - Patient Laboratory Reports
 * 
 * Features:
 * - Patient test history
 * - Result trends over time
 * - Abnormal/Critical result tracking
 * - Comprehensive patient lab report
 * - Print/Export patient reports
 * - Search and filter by patient
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
$pdo->exec("SET time_zone = '+03:00'");

// Validate user session
$user_id = (int)($_SESSION['user_id'] ?? 0);
$branch_id = (int)($_SESSION['branch_id'] ?? 0);
$user_type = $_SESSION['user_type'] ?? '';
$is_global_admin = ($user_type === 'super_admin');
$is_lab_staff = in_array($user_type, ['laboratorist', 'doctor', 'nurse', 'super_admin', 'admin']);

if ($user_id <= 0 || !$is_lab_staff) {
    header('Location: login.php');
    exit;
}

// ============================================
// 2. ACTION HANDLING
// ============================================
$action = $_GET['action'] ?? '';
$patient_id = (int)($_GET['patient_id'] ?? 0);
$date_from = $_GET['date_from'] ?? date('Y-m-d', strtotime('-90 days'));
$date_to = $_GET['date_to'] ?? date('Y-m-d');
$search = trim($_GET['search'] ?? '');
$success_message = '';
$error_message = '';

// ============================================
// 3. DATA FETCHING
// ============================================

// Build branch filter
$branch_filter = "";
$params = [];
if (!$is_global_admin && $branch_id > 0) {
    $branch_filter = " AND lo.branch_id = :branch_id";
    $params[':branch_id'] = $branch_id;
}

// Search filter
$search_filter = "";
if (!empty($search) && $patient_id === 0) {
    $search_filter = " AND (p.first_name LIKE :search OR p.last_name LIKE :search OR p.mr_number LIKE :search)";
    $params[':search'] = "%{$search}%";
}

// ============================================
// 3.1. PATIENT LIST (for selection)
// ============================================
$sql = "SELECT 
            p.id, p.mr_number, p.first_name, p.middle_name, p.last_name,
            p.gender, p.date_of_birth, p.phone, p.email,
            COUNT(lo.id) as test_count,
            MAX(lo.created_at) as last_visit
        FROM patients p
        LEFT JOIN lab_orders lo ON p.id = lo.patient_id AND lo.deleted_at IS NULL
        WHERE p.is_active = 1 
        AND p.deleted_at IS NULL" . 
        ($branch_id > 0 && !$is_global_admin ? " AND p.branch_id = :branch_id" : "") . 
        $search_filter . "
        GROUP BY p.id
        HAVING test_count > 0 OR :show_all = 1
        ORDER BY p.first_name ASC, p.last_name ASC
        LIMIT 100";
$stmt = $pdo->prepare($sql);
$stmt->execute(array_merge($params, [':show_all' => 1]));
$patients = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// 3.2. PATIENT LAB REPORTS (if patient selected)
// ============================================
$patient_info = null;
$lab_orders = [];
$test_results = [];
$trend_data = [];
$abnormal_results = [];
$critical_results = [];
$summary_stats = [
    'total_orders' => 0,
    'total_tests' => 0,
    'abnormal_count' => 0,
    'critical_count' => 0,
    'completed_count' => 0,
    'pending_count' => 0,
    'last_visit' => null,
    'first_visit' => null
];

if ($patient_id > 0) {
    // Get patient info
    $sql = "SELECT 
                p.*,
                CONCAT(p.first_name, ' ', COALESCE(p.middle_name, ''), ' ', p.last_name) as full_name,
                TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) as age
            FROM patients p
            WHERE p.id = :id AND p.deleted_at IS NULL";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':id' => $patient_id]);
    $patient_info = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($patient_info) {
        // Get lab orders for this patient
        $sql = "SELECT 
                    lo.id, lo.order_number, lo.order_date, lo.priority,
                    lo.status, lo.payment_status, lo.clinical_indication,
                    lo.specimen_type, lo.specimen_collected_at,
                    lo.created_at, lo.completed_at, lo.results_ready_at,
                    CONCAT(d.first_name, ' ', d.last_name) as doctor_name,
                    CONCAT(t.first_name, ' ', t.last_name) as technician_name
                FROM lab_orders lo
                LEFT JOIN users d ON lo.doctor_id = d.id
                LEFT JOIN users t ON lo.specimen_collected_by_user_id = t.id
                WHERE lo.patient_id = :patient_id
                AND lo.deleted_at IS NULL
                AND DATE(lo.created_at) BETWEEN :date_from AND :date_to
                ORDER BY lo.created_at DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':patient_id' => $patient_id,
            ':date_from' => $date_from,
            ':date_to' => $date_to
        ]);
        $lab_orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Get test results for this patient
        $sql = "SELECT 
                    ltc.id, ltc.lab_order_id, ltc.test_component_name,
                    ltc.component_code, ltc.result_numeric, ltc.result_text,
                    ltc.result_unit, ltc.reference_range_low,
                    ltc.reference_range_high, ltc.reference_range_text,
                    ltc.is_abnormal, ltc.is_critical, ltc.critical_flag_notes,
                    ltc.technician_notes, ltc.verified_by_user_id, ltc.verified_at,
                    ltc.created_at,
                    lo.order_number, lo.order_date, lo.priority,
                    lo.status as order_status,
                    lti.test_name as parent_test_name
                FROM lab_test_components ltc
                INNER JOIN lab_orders lo ON ltc.lab_order_id = lo.id
                LEFT JOIN lab_order_items lti ON ltc.lab_order_id = lti.lab_order_id
                WHERE lo.patient_id = :patient_id
                AND lo.deleted_at IS NULL
                AND ltc.deleted_at IS NULL
                AND DATE(lo.created_at) BETWEEN :date_from AND :date_to
                ORDER BY lo.created_at DESC, ltc.test_component_name ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':patient_id' => $patient_id,
            ':date_from' => $date_from,
            ':date_to' => $date_to
        ]);
        $test_results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Calculate summary statistics
        $summary_stats['total_orders'] = count($lab_orders);
        $summary_stats['total_tests'] = count($test_results);
        
        $order_ids = array_column($lab_orders, 'id');
        $completed_ids = array_filter($order_ids, function($id) use ($lab_orders) {
            foreach ($lab_orders as $o) {
                if ($o['id'] == $id && in_array($o['status'], ['completed', 'verified'])) {
                    return true;
                }
            }
            return false;
        });
        $summary_stats['completed_count'] = count($completed_ids);
        $summary_stats['pending_count'] = $summary_stats['total_orders'] - $summary_stats['completed_count'];

        foreach ($test_results as $result) {
            if ($result['is_abnormal']) $summary_stats['abnormal_count']++;
            if ($result['is_critical']) $summary_stats['critical_count']++;
        }

        if (!empty($lab_orders)) {
            $summary_stats['last_visit'] = $lab_orders[0]['created_at'];
            $summary_stats['first_visit'] = end($lab_orders)['created_at'];
        }

        // Get abnormal results
        $abnormal_results = array_filter($test_results, function($r) {
            return $r['is_abnormal'] == 1;
        });

        // Get critical results
        $critical_results = array_filter($test_results, function($r) {
            return $r['is_critical'] == 1;
        });

        // Prepare trend data for charts (group by test component name)
        $trend_data = [];
        foreach ($test_results as $result) {
            if ($result['result_numeric'] !== null && !empty($result['test_component_name'])) {
                $key = $result['test_component_name'];
                if (!isset($trend_data[$key])) {
                    $trend_data[$key] = [
                        'name' => $result['test_component_name'],
                        'unit' => $result['result_unit'] ?? '',
                        'values' => [],
                        'dates' => [],
                        'orders' => [],
                        'low' => $result['reference_range_low'],
                        'high' => $result['reference_range_high']
                    ];
                }
                // Get the order date
                $order_date = '';
                foreach ($lab_orders as $order) {
                    if ($order['id'] == $result['lab_order_id']) {
                        $order_date = $order['order_date'];
                        break;
                    }
                }
                $trend_data[$key]['values'][] = (float)$result['result_numeric'];
                $trend_data[$key]['dates'][] = $order_date ?: $result['created_at'];
                $trend_data[$key]['orders'][] = $result['lab_order_id'];
            }
        }

        // Sort trend data by most recent
        foreach ($trend_data as $key => $data) {
            array_multisort($data['dates'], SORT_ASC, $data['values'], $data['orders']);
            $trend_data[$key] = $data;
        }
    }
}

// ============================================
// 4. HELPER FUNCTIONS
// ============================================
function getStatusBadge($status) {
    $badges = [
        'pending_payment' => 'bg-warning',
        'pending' => 'bg-info',
        'ordered' => 'bg-primary',
        'approved' => 'bg-success',
        'collected' => 'bg-info',
        'in_progress' => 'bg-warning',
        'completed' => 'bg-success',
        'cancelled' => 'bg-danger',
        'rejected' => 'bg-danger',
        'verified' => 'bg-success'
    ];
    return $badges[$status] ?? 'bg-secondary';
}

function getPriorityBadge($priority) {
    $badges = [
        'stat' => 'bg-danger',
        'urgent' => 'bg-warning',
        'routine' => 'bg-secondary'
    ];
    return $badges[$priority] ?? 'bg-secondary';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Patient Reports | HMS ERP</title>
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
        .page-header h1 i { color: #06d6a0; }
        
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
        .btn:hover { background: #f3f4f6; border-color: #06d6a0; }
        .btn-primary { background: #06d6a0; color: white; border-color: #06d6a0; }
        .btn-primary:hover { background: #05b88a; border-color: #05b88a; color: white; }
        .btn-danger { background: #ef476f; color: white; border-color: #ef476f; }
        .btn-danger:hover { background: #d63e5e; border-color: #d63e5e; }
        .btn-sm { padding: 4px 10px; font-size: 12px; }
        .btn-outline { background: transparent; border-color: #e5e7eb; }
        
        .alert-success { background: #d1fae5; border: 1px solid #a7f3d0; color: #065f46; padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; }
        .alert-danger { background: #fee2e2; border: 1px solid #fecaca; color: #991b1b; padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; }
        .alert-warning { background: #fef3c7; border: 1px solid #fde68a; color: #92400e; padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; }
        
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
        .badge-success { background: #d1fae5; color: #059669; }
        .badge-danger { background: #fee2e2; color: #dc2626; }
        .badge-warning { background: #fef3c7; color: #d97706; }
        .badge-info { background: #dbeafe; color: #2563eb; }
        .badge-secondary { background: #f3f4f6; color: #6b7280; }
        .badge-primary { background: #dbeafe; color: #1e40af; }
        
        .bg-success { background: #d1fae5; color: #059669; }
        .bg-danger { background: #fee2e2; color: #dc2626; }
        .bg-warning { background: #fef3c7; color: #d97706; }
        .bg-info { background: #dbeafe; color: #2563eb; }
        .bg-secondary { background: #f3f4f6; color: #6b7280; }
        .bg-primary { background: #dbeafe; color: #1e40af; }
        
        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 12px;
            margin-bottom: 20px;
        }
        .stat-card {
            background: white;
            padding: 14px 16px;
            border-radius: 10px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08);
            border-left: 4px solid #4361ee;
        }
        .stat-card .stat-value {
            font-size: 24px;
            font-weight: 700;
        }
        .stat-card .stat-label {
            font-size: 12px;
            color: #6b7280;
        }
        .stat-card.primary { border-left-color: #4361ee; }
        .stat-card.success { border-left-color: #06d6a0; }
        .stat-card.danger { border-left-color: #ef476f; }
        .stat-card.warning { border-left-color: #f9c74f; }
        .stat-card.info { border-left-color: #4cc9f0; }
        .stat-value.primary { color: #4361ee; }
        .stat-value.success { color: #06d6a0; }
        .stat-value.danger { color: #ef476f; }
        .stat-value.warning { color: #d97706; }
        
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
            padding: 8px 12px;
            border-bottom: 1px solid #e5e7eb;
            vertical-align: middle;
        }
        .table-container tr:hover { background: #f9fafb; }
        .table-container .no-data {
            text-align: center;
            padding: 30px 0;
            color: #6b7280;
        }
        .table-container .no-data i {
            font-size: 36px;
            display: block;
            margin-bottom: 10px;
            color: #d1d5db;
        }
        
        /* Patient List */
        .patient-item {
            padding: 10px 12px;
            border-bottom: 1px solid #f3f4f6;
            display: flex;
            justify-content: space-between;
            align-items: center;
            cursor: pointer;
            transition: background 0.2s;
        }
        .patient-item:hover { background: #f9fafb; }
        .patient-item.selected { background: #dbeafe; }
        .patient-item .patient-name { font-weight: 600; }
        .patient-item .patient-meta { font-size: 12px; color: #6b7280; }
        .patient-item .patient-count { font-size: 12px; color: #6b7280; }
        
        /* Result Items */
        .result-item {
            padding: 8px 12px;
            border-bottom: 1px solid #f3f4f6;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 13px;
        }
        .result-item:last-child { border-bottom: none; }
        .result-item .result-value {
            font-weight: 600;
            font-size: 15px;
        }
        .result-item .result-value.abnormal { color: #d97706; }
        .result-item .result-value.critical { color: #ef476f; }
        .result-item .result-ref { font-size: 11px; color: #6b7280; }
        .result-item .result-status { font-size: 10px; }
        
        .text-muted { color: #6b7280; }
        .text-center { text-align: center; }
        .mt-2 { margin-top: 8px; }
        .mb-2 { margin-bottom: 8px; }
        .flex { display: flex; }
        .flex-wrap { flex-wrap: wrap; }
        .items-center { align-items: center; }
        .gap-2 { gap: 8px; }
        
        .chart-container { position: relative; height: 220px; }
        
        /* Patient Info Card */
        .patient-info {
            background: #f8fafc;
            padding: 16px 20px;
            border-radius: 8px;
            margin-bottom: 16px;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 12px;
        }
        .patient-info .info-item .label {
            font-size: 11px;
            color: #6b7280;
            display: block;
        }
        .patient-info .info-item .value {
            font-weight: 600;
        }
        
        /* Sidebar Patient List */
        .patient-list-container {
            max-height: 500px;
            overflow-y: auto;
        }
        .patient-list-container::-webkit-scrollbar { width: 4px; }
        .patient-list-container::-webkit-scrollbar-thumb { background: #e5e7eb; border-radius: 4px; }
        
        @media (max-width: 768px) {
            .filter-bar { flex-direction: column; align-items: stretch; }
            .filter-bar select, .filter-bar input { width: 100%; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .patient-info { grid-template-columns: 1fr 1fr; }
            .page-header h1 { font-size: 20px; }
        }
        @media (max-width: 480px) {
            .stats-grid { grid-template-columns: 1fr 1fr; gap: 8px; }
            .stat-card { padding: 10px 12px; }
            .stat-card .stat-value { font-size: 18px; }
            .patient-info { grid-template-columns: 1fr; }
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
            <h1><i class="fas fa-file-medical-alt"></i> Patient Reports</h1>
            <div>
                <?php if ($patient_id > 0 && $patient_info): ?>
                    <button class="btn btn-sm btn-primary" onclick="window.print()">
                        <i class="fas fa-print"></i> Print Report
                    </button>
                    <button class="btn btn-sm" onclick="exportReport()">
                        <i class="fas fa-file-export"></i> Export
                    </button>
                <?php endif; ?>
                <button class="btn btn-sm" onclick="window.location.reload();">
                    <i class="fas fa-sync-alt"></i>
                </button>
            </div>
        </div>

        <!-- Filter Bar -->
        <div class="filter-bar">
            <div>
                <span class="filter-label">Patient</span>
                <select id="patientSelect" onchange="selectPatient()" style="min-width:200px;">
                    <option value="">Select Patient</option>
                    <?php foreach ($patients as $p): ?>
                        <option value="<?php echo $p['id']; ?>" <?php echo $p['id'] == $patient_id ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($p['first_name'] . ' ' . $p['last_name']); ?>
                            (<?php echo htmlspecialchars($p['mr_number']); ?>)
                            - <?php echo $p['test_count']; ?> tests
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <span class="filter-label">Date From</span>
                <input type="date" id="dateFrom" value="<?php echo htmlspecialchars($date_from); ?>" onchange="applyFilters()">
            </div>
            <div>
                <span class="filter-label">Date To</span>
                <input type="date" id="dateTo" value="<?php echo htmlspecialchars($date_to); ?>" onchange="applyFilters()">
            </div>
            <div style="flex:1;min-width:150px;">
                <input type="text" class="form-control" id="searchInput" placeholder="Search patients..." 
                       value="<?php echo htmlspecialchars($search); ?>" onkeypress="if(event.key==='Enter') applyFilters()">
            </div>
            <button class="btn btn-primary" onclick="applyFilters()"><i class="fas fa-search"></i> Search</button>
            <button class="btn" onclick="resetFilters()"><i class="fas fa-times"></i> Reset</button>
        </div>

        <?php if ($patient_id > 0 && $patient_info): ?>
        <!-- ============================================ -->
        <!-- PATIENT REPORT VIEW -->
        <!-- ============================================ -->
        
        <!-- Patient Info -->
        <div class="patient-info">
            <div class="info-item">
                <span class="label">Patient</span>
                <span class="value"><?php echo htmlspecialchars($patient_info['full_name']); ?></span>
            </div>
            <div class="info-item">
                <span class="label">MRN</span>
                <span class="value"><?php echo htmlspecialchars($patient_info['mr_number']); ?></span>
            </div>
            <div class="info-item">
                <span class="label">Gender / Age</span>
                <span class="value"><?php echo ucfirst($patient_info['gender'] ?? 'N/A'); ?> / <?php echo $patient_info['age'] ?? 'N/A'; ?> yrs</span>
            </div>
            <div class="info-item">
                <span class="label">Phone</span>
                <span class="value"><?php echo htmlspecialchars($patient_info['phone'] ?? 'N/A'); ?></span>
            </div>
            <div class="info-item">
                <span class="label">Report Period</span>
                <span class="value"><?php echo date('M d, Y', strtotime($date_from)); ?> - <?php echo date('M d, Y', strtotime($date_to)); ?></span>
            </div>
            <div class="info-item">
                <span class="label">Last Visit</span>
                <span class="value"><?php echo $summary_stats['last_visit'] ? date('M d, Y', strtotime($summary_stats['last_visit'])) : 'N/A'; ?></span>
            </div>
        </div>

        <!-- Summary Stats -->
        <div class="stats-grid">
            <div class="stat-card primary">
                <div class="stat-value primary"><?php echo $summary_stats['total_orders']; ?></div>
                <div class="stat-label">Total Orders</div>
            </div>
            <div class="stat-card success">
                <div class="stat-value success"><?php echo $summary_stats['total_tests']; ?></div>
                <div class="stat-label">Total Tests</div>
            </div>
            <div class="stat-card <?php echo $summary_stats['abnormal_count'] > 0 ? 'warning' : 'success'; ?>">
                <div class="stat-value <?php echo $summary_stats['abnormal_count'] > 0 ? 'warning' : 'success'; ?>"><?php echo $summary_stats['abnormal_count']; ?></div>
                <div class="stat-label">Abnormal Results</div>
            </div>
            <div class="stat-card <?php echo $summary_stats['critical_count'] > 0 ? 'danger' : 'success'; ?>">
                <div class="stat-value <?php echo $summary_stats['critical_count'] > 0 ? 'danger' : 'success'; ?>"><?php echo $summary_stats['critical_count']; ?></div>
                <div class="stat-label">Critical Results</div>
            </div>
            <div class="stat-card info">
                <div class="stat-value info"><?php echo $summary_stats['completed_count']; ?></div>
                <div class="stat-label">Completed</div>
            </div>
            <div class="stat-card warning">
                <div class="stat-value warning"><?php echo $summary_stats['pending_count']; ?></div>
                <div class="stat-label">Pending</div>
            </div>
        </div>

        <!-- ============================================ -->
        <!-- TREND CHARTS -->
        <!-- ============================================ -->
        <?php if (!empty($trend_data)): ?>
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-chart-line"></i> Test Result Trends</h3>
                <span class="badge badge-secondary"><?php echo count($trend_data); ?> tests</span>
            </div>
            <div class="card-body">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
                    <?php 
                    $chart_count = 0;
                    foreach ($trend_data as $key => $data): 
                        if (count($data['values']) < 2) continue;
                        $chart_count++;
                        if ($chart_count > 4) break;
                    ?>
                    <div>
                        <div style="font-size:13px;font-weight:600;margin-bottom:4px;">
                            <?php echo htmlspecialchars($data['name']); ?>
                            <?php if ($data['unit']): ?>
                                <span class="text-muted" style="font-weight:400;">(<?php echo htmlspecialchars($data['unit']); ?>)</span>
                            <?php endif; ?>
                        </div>
                        <div class="chart-container">
                            <canvas id="trendChart_<?php echo $chart_count; ?>"></canvas>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php if ($chart_count === 0): ?>
                        <div class="no-data" style="grid-column:1/-1;padding:20px 0;">
                            <i class="fas fa-chart-line"></i>
                            Not enough data for trends
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- ============================================ -->
        <!-- CRITICAL RESULTS ALERT -->
        <!-- ============================================ -->
        <?php if (!empty($critical_results)): ?>
        <div class="alert-danger" style="padding:12px 16px;margin-bottom:16px;">
            <i class="fas fa-exclamation-triangle"></i> 
            <strong><?php echo count($critical_results); ?> critical result(s)</strong> found in this period.
            <?php foreach ($critical_results as $cr): ?>
                <div style="font-size:13px;margin-top:4px;">
                    • <?php echo htmlspecialchars($cr['test_component_name']); ?>: 
                    <strong><?php echo htmlspecialchars($cr['result_numeric'] ?? $cr['result_text']); ?></strong>
                    <?php if ($cr['reference_range_text']): ?>
                        (Ref: <?php echo htmlspecialchars($cr['reference_range_text']); ?>)
                    <?php endif; ?>
                    <span class="badge badge-danger">CRITICAL</span>
                </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- ============================================ -->
        <!-- TEST RESULTS TABLE -->
        <!-- ============================================ -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-flask"></i> Test Results</h3>
                <span class="badge badge-secondary"><?php echo count($test_results); ?> results</span>
            </div>
            <div class="card-body">
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Order #</th>
                                <th>Test</th>
                                <th>Result</th>
                                <th>Reference Range</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($test_results)): ?>
                                <tr>
                                    <td colspan="6" class="no-data">
                                        <i class="fas fa-inbox"></i>
                                        No test results found for this period
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($test_results as $result): ?>
                                    <tr>
                                        <td><?php echo date('M d, Y', strtotime($result['created_at'])); ?></td>
                                        <td>
                                            <a href="lab_orders_queue.php?action=view&id=<?php echo $result['lab_order_id']; ?>" style="color:#4361ee;text-decoration:none;">
                                                <?php echo htmlspecialchars($result['order_number']); ?>
                                            </a>
                                            <?php if ($result['priority'] === 'stat'): ?>
                                                <span class="badge badge-danger" style="font-size:9px;">STAT</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php echo htmlspecialchars($result['test_component_name']); ?>
                                            <?php if ($result['parent_test_name']): ?>
                                                <br><span class="text-muted" style="font-size:11px;"><?php echo htmlspecialchars($result['parent_test_name']); ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($result['result_numeric'] !== null): ?>
                                                <span class="result-value <?php echo $result['is_critical'] ? 'critical' : ($result['is_abnormal'] ? 'abnormal' : ''); ?>">
                                                    <?php echo htmlspecialchars($result['result_numeric']); ?>
                                                    <?php if ($result['result_unit']): ?>
                                                        <span class="text-muted" style="font-weight:400;font-size:12px;"><?php echo htmlspecialchars($result['result_unit']); ?></span>
                                                    <?php endif; ?>
                                                </span>
                                            <?php elseif ($result['result_text']): ?>
                                                <span class="result-value <?php echo $result['is_critical'] ? 'critical' : ($result['is_abnormal'] ? 'abnormal' : ''); ?>">
                                                    <?php echo htmlspecialchars($result['result_text']); ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($result['reference_range_text']): ?>
                                                <?php echo htmlspecialchars($result['reference_range_text']); ?>
                                            <?php elseif ($result['reference_range_low'] !== null && $result['reference_range_high'] !== null): ?>
                                                <?php echo $result['reference_range_low']; ?> - <?php echo $result['reference_range_high']; ?>
                                                <?php if ($result['result_unit']): ?>
                                                    <?php echo htmlspecialchars($result['result_unit']); ?>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($result['is_critical']): ?>
                                                <span class="badge badge-danger">Critical</span>
                                            <?php elseif ($result['is_abnormal']): ?>
                                                <span class="badge badge-warning">Abnormal</span>
                                            <?php else: ?>
                                                <span class="badge badge-success">Normal</span>
                                            <?php endif; ?>
                                            <?php if ($result['verified_by_user_id']): ?>
                                                <span class="badge badge-info" style="font-size:9px;">Verified</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- ============================================ -->
        <!-- ORDER HISTORY -->
        <!-- ============================================ -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-history"></i> Order History</h3>
                <span class="badge badge-secondary"><?php echo count($lab_orders); ?> orders</span>
            </div>
            <div class="card-body">
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Order #</th>
                                <th>Date</th>
                                <th>Priority</th>
                                <th>Doctor</th>
                                <th>Status</th>
                                <th>Payment</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($lab_orders)): ?>
                                <tr>
                                    <td colspan="6" class="no-data">
                                        <i class="fas fa-inbox"></i>
                                        No orders found
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($lab_orders as $order): ?>
                                    <tr>
                                        <td>
                                            <a href="lab_orders_queue.php?action=view&id=<?php echo $order['id']; ?>" style="color:#4361ee;text-decoration:none;">
                                                <?php echo htmlspecialchars($order['order_number']); ?>
                                            </a>
                                        </td>
                                        <td><?php echo date('M d, Y', strtotime($order['order_date'])); ?></td>
                                        <td>
                                            <span class="badge <?php echo getPriorityBadge($order['priority']); ?>">
                                                <?php echo strtoupper($order['priority']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo htmlspecialchars($order['doctor_name'] ?? 'N/A'); ?></td>
                                        <td>
                                            <span class="badge <?php echo getStatusBadge($order['status']); ?>">
                                                <?php echo ucfirst(str_replace('_', ' ', $order['status'] ?? 'Pending')); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge <?php echo $order['payment_status'] === 'paid' ? 'badge-success' : 'badge-warning'; ?>">
                                                <?php echo ucfirst($order['payment_status'] ?? 'Unpaid'); ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <?php else: ?>
        <!-- ============================================ -->
        <!-- PATIENT SELECTION VIEW -->
        <!-- ============================================ -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-users"></i> Select a Patient</h3>
                <span class="badge badge-secondary"><?php echo count($patients); ?> patients</span>
            </div>
            <div class="card-body">
                <?php if (empty($patients)): ?>
                    <div class="no-data">
                        <i class="fas fa-users"></i>
                        No patients found. Try adjusting your search.
                    </div>
                <?php else: ?>
                    <div class="patient-list-container">
                        <?php foreach ($patients as $p): ?>
                            <a href="?patient_id=<?php echo $p['id']; ?>&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>" 
                               style="text-decoration:none;color:inherit;">
                                <div class="patient-item <?php echo $p['id'] == $patient_id ? 'selected' : ''; ?>">
                                    <div>
                                        <div class="patient-name">
                                            <?php echo htmlspecialchars($p['first_name'] . ' ' . $p['last_name']); ?>
                                        </div>
                                        <div class="patient-meta">
                                            MRN: <?php echo htmlspecialchars($p['mr_number']); ?>
                                            <?php if ($p['gender']): ?>
                                                • <?php echo ucfirst($p['gender']); ?>
                                            <?php endif; ?>
                                            <?php if ($p['date_of_birth']): ?>
                                                • <?php echo date_diff(date_create($p['date_of_birth']), date_create('now'))->y; ?> yrs
                                            <?php endif; ?>
                                            <?php if ($p['phone']): ?>
                                                • <?php echo htmlspecialchars($p['phone']); ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div style="text-align:right;">
                                        <div class="patient-count">
                                            <?php echo $p['test_count']; ?> tests
                                        </div>
                                        <?php if ($p['last_visit']): ?>
                                            <div style="font-size:11px;color:#6b7280;">
                                                Last: <?php echo date('M d, Y', strtotime($p['last_visit'])); ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Footer -->
        <div style="text-align:center;padding:20px 0;color:#6b7280;font-size:12px;border-top:1px solid #e5e7eb;margin-top:20px;">
            <i class="fas fa-file-medical-alt"></i> Patient Reports &bull; HMS ERP &bull; 
            Generated: <?php echo date('Y-m-d H:i:s'); ?>
        </div>
    </div>
</div>

<script>
// ============================================
// FILTER FUNCTIONS
// ============================================
function applyFilters() {
    const patientId = document.getElementById('patientSelect').value;
    const dateFrom = document.getElementById('dateFrom').value;
    const dateTo = document.getElementById('dateTo').value;
    const search = document.getElementById('searchInput').value;
    
    let url = window.location.pathname + '?';
    if (patientId) url += 'patient_id=' + encodeURIComponent(patientId) + '&';
    if (dateFrom) url += 'date_from=' + encodeURIComponent(dateFrom) + '&';
    if (dateTo) url += 'date_to=' + encodeURIComponent(dateTo) + '&';
    if (search) url += 'search=' + encodeURIComponent(search) + '&';
    window.location.href = url;
}

function resetFilters() {
    window.location.href = window.location.pathname;
}

function selectPatient() {
    const patientId = document.getElementById('patientSelect').value;
    if (patientId) {
        const url = new URL(window.location.href);
        url.searchParams.set('patient_id', patientId);
        window.location.href = url.toString();
    }
}

function exportReport() {
    const url = new URL(window.location.href);
    url.searchParams.set('export', 'csv');
    window.location.href = url.toString();
}

// ============================================
// TREND CHARTS
// ============================================
document.addEventListener('DOMContentLoaded', function() {
    <?php 
    $chart_count = 0;
    foreach ($trend_data as $key => $data): 
        if (count($data['values']) < 2) continue;
        $chart_count++;
        if ($chart_count > 4) break;
    ?>
    const ctx<?php echo $chart_count; ?> = document.getElementById('trendChart_<?php echo $chart_count; ?>');
    if (ctx<?php echo $chart_count; ?>) {
        const labels<?php echo $chart_count; ?> = <?php echo json_encode(array_map(function($d) {
            return date('M d', strtotime($d));
        }, $data['dates'])); ?>;
        const values<?php echo $chart_count; ?> = <?php echo json_encode($data['values']); ?>;
        const low<?php echo $chart_count; ?> = <?php echo json_encode($data['low']); ?>;
        const high<?php echo $chart_count; ?> = <?php echo json_encode($data['high']); ?>;
        
        new Chart(ctx<?php echo $chart_count; ?>, {
            type: 'line',
            data: {
                labels: labels<?php echo $chart_count; ?>,
                datasets: [
                    {
                        label: 'Result',
                        data: values<?php echo $chart_count; ?>,
                        borderColor: '#4361ee',
                        backgroundColor: 'rgba(67, 97, 238, 0.1)',
                        fill: true,
                        tension: 0.3,
                        pointBackgroundColor: '#4361ee',
                        pointRadius: 4
                    },
                    <?php if ($data['low'] !== null && $data['high'] !== null): ?>
                    {
                        label: 'Range',
                        data: Array(values<?php echo $chart_count; ?>.length).fill(low<?php echo $chart_count; ?>),
                        borderColor: 'rgba(6, 214, 160, 0.5)',
                        borderDash: [5, 5],
                        borderWidth: 1,
                        pointRadius: 0,
                        fill: false
                    },
                    {
                        label: 'Range',
                        data: Array(values<?php echo $chart_count; ?>.length).fill(high<?php echo $chart_count; ?>),
                        borderColor: 'rgba(6, 214, 160, 0.5)',
                        borderDash: [5, 5],
                        borderWidth: 1,
                        pointRadius: 0,
                        fill: false
                    }
                    <?php endif; ?>
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false }
                },
                scales: {
                    y: {
                        beginAtZero: <?php echo $data['low'] !== null && $data['low'] > 0 ? 'false' : 'true'; ?>,
                        ticks: { font: { size: 9 } }
                    },
                    x: {
                        ticks: { font: { size: 9 }, maxTicksLimit: 6 }
                    }
                }
            }
        });
    }
    <?php endforeach; ?>
});
</script>

</body>
</html>