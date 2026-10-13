<?php
/**
 * radiology_worklist.php
 * 
 * HMS ERP - Radiology Worklist
 * Fully fixed version with proper DataTables integration and PDO parameter handling
 * 
 * @package HMS_ERP
 * @version 2.0
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
// 2. ACTION HANDLING
// ============================================
$action = $_GET['action'] ?? $_POST['action'] ?? '';
$order_id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$success_message = '';
$error_message = '';

// Handle status update
if ($action === 'update_status' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $order_id = (int)($_POST['order_id'] ?? 0);
    $new_status = $_POST['new_status'] ?? '';
    $findings = trim($_POST['findings'] ?? '');
    $impression = trim($_POST['impression'] ?? '');
    $interpretation_report = trim($_POST['interpretation_report'] ?? '');
    $physician_review_notes = trim($_POST['physician_review_notes'] ?? '');

    $allowed_statuses = ['in_progress', 'completed', 'reviewed'];
    
    if ($order_id <= 0 || !in_array($new_status, $allowed_statuses)) {
        $error_message = 'Invalid status or order ID.';
    } else {
        try {
            $pdo->beginTransaction();

            $sql = "SELECT status, order_number FROM radiology_orders WHERE id = :id AND deleted_at IS NULL";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':id' => $order_id]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$order) {
                throw new Exception('Order not found.');
            }

            $old_status = $order['status'];
            $update_fields = "status = :new_status, updated_at = NOW()";
            $params = [':id' => $order_id, ':new_status' => $new_status];

            if ($new_status === 'in_progress') {
                $update_fields .= ", performed_at = NOW(), performed_by_user_id = :performed_by";
                $params[':performed_by'] = $user_id;
            }

            if ($new_status === 'completed') {
                $update_fields .= ", performed_at = NOW(), performed_by_user_id = :performed_by";
                $params[':performed_by'] = $user_id;
                if (!empty($findings)) {
                    $update_fields .= ", findings = :findings";
                    $params[':findings'] = $findings;
                }
                if (!empty($impression)) {
                    $update_fields .= ", impression = :impression";
                    $params[':impression'] = $impression;
                }
            }

            if ($new_status === 'reviewed') {
                $update_fields .= ", reviewed_at = NOW()";
                if (!empty($interpretation_report)) {
                    $update_fields .= ", interpretation_report = :interpretation_report";
                    $params[':interpretation_report'] = $interpretation_report;
                }
                if (!empty($physician_review_notes)) {
                    $update_fields .= ", physician_review_notes = :physician_review_notes";
                    $params[':physician_review_notes'] = $physician_review_notes;
                }
            }

            $sql = "UPDATE radiology_orders SET {$update_fields} WHERE id = :id";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            $pdo->commit();
            $success_message = "Order #{$order['order_number']} updated to " . ucfirst(str_replace('_', ' ', $new_status));

        } catch (Exception $e) {
            $pdo->rollBack();
            $error_message = 'Error: ' . $e->getMessage();
            error_log('Radiology Worklist Update Error: ' . $e->getMessage());
        }
    }
}

// Handle AJAX request for order details
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_order_details' && isset($_GET['id'])) {
    header('Content-Type: application/json');
    $order_id = (int)$_GET['id'];
    
    try {
        $sql = "SELECT 
                    ro.id, ro.order_number, ro.service_name, ro.service_code,
                    ro.modality, ro.body_part, ro.priority, ro.payment_status,
                    ro.status, ro.clinical_history, ro.findings, ro.impression,
                    ro.interpretation_report, ro.image_attachment_path,
                    ro.scheduled_date, ro.scheduled_time,
                    ro.created_at, ro.updated_at, ro.performed_at, ro.reviewed_at,
                    ro.cancellation_reason, ro.physician_review_notes,
                    CONCAT(p.first_name, ' ', COALESCE(p.middle_name, ''), ' ', p.last_name) as patient_name,
                    p.mr_number, p.date_of_birth, p.gender, p.phone,
                    CONCAT(u.first_name, ' ', u.last_name) as doctor_name,
                    v.visit_number
                FROM radiology_orders ro
                LEFT JOIN patients p ON ro.patient_id = p.id
                LEFT JOIN users u ON ro.doctor_id = u.id
                LEFT JOIN patient_visits v ON ro.visit_id = v.id
                WHERE ro.id = :id AND ro.deleted_at IS NULL";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':id' => $order_id]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$order) {
            echo json_encode(['success' => false, 'message' => 'Order not found']);
            exit;
        }
        
        echo json_encode(['success' => true, 'order' => $order]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// ============================================
// 3. HELPER FUNCTIONS
// ============================================
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
    return $icons[$modality] ?? 'fa-x-ray';
}

// ============================================
// 4. DATA FETCHING - FIXED PARAMETER HANDLING
// ============================================

// Build filter parameters from URL (including status=image_captured)
$filter_status = $_GET['status'] ?? $_GET['filter'] ?? '';
$filter_modality = $_GET['modality'] ?? '';
$filter_priority = $_GET['priority'] ?? '';
$filter_date_from = $_GET['date_from'] ?? date('Y-m-d');
$filter_date_to = $_GET['date_to'] ?? date('Y-m-d');
$search = trim($_GET['search'] ?? '');

// Handle special filter values
$status_filter_value = '';
if (!empty($filter_status)) {
    // Map filter values to actual status values
    $status_map = [
        'image_captured' => 'image_captured',
        'in_progress' => 'in_progress',
        'completed' => 'completed',
        'reviewed' => 'reviewed',
        'scheduled' => 'scheduled',
        'pending' => 'pending',
        'pending_payment' => 'pending_payment',
        'unreported' => ['image_captured', 'in_progress'],
        'pending_approval' => 'reported',
        'overdue' => 'overdue' // special case
    ];
    
    if (isset($status_map[$filter_status])) {
        $status_filter_value = $status_map[$filter_status];
    } else {
        $status_filter_value = $filter_status;
    }
}

// ============================================
// 4a. MAIN QUERY - Build with proper parameter handling
// ============================================
$main_params = [];
$main_where = "ro.deleted_at IS NULL";

// Branch filter
if (!$is_global_admin && $branch_id > 0) {
    $main_where .= " AND ro.branch_id = :branch_id";
    $main_params[':branch_id'] = $branch_id;
}

// Status filter - handle special cases
if (!empty($filter_status)) {
    if ($filter_status === 'unreported') {
        $main_where .= " AND ro.status IN ('image_captured', 'in_progress')";
    } elseif ($filter_status === 'pending_approval') {
        $main_where .= " AND ro.status = 'reported' AND (ro.approval_status = 'pending' OR ro.approval_status IS NULL)";
    } elseif ($filter_status === 'overdue') {
        $main_where .= " AND ro.status NOT IN ('completed', 'reported', 'cancelled', 'rejected')
                        AND ro.created_at <= DATE_SUB(NOW(), INTERVAL 3 DAY)";
    } elseif ($filter_status === 'all') {
        // Show all active statuses
        $main_where .= " AND ro.status IN ('scheduled', 'pending', 'image_captured', 'in_progress', 'completed', 'reviewed')";
    } elseif (is_array($status_filter_value)) {
        $placeholders = implode(',', array_fill(0, count($status_filter_value), '?'));
        $main_where .= " AND ro.status IN ($placeholders)";
        foreach ($status_filter_value as $idx => $val) {
            $main_params[":status_$idx"] = $val;
        }
    } else {
        $main_where .= " AND ro.status = :status_filter";
        $main_params[':status_filter'] = $status_filter_value;
    }
} else {
    // Default: show active worklist items
    $main_where .= " AND ro.status IN ('scheduled', 'pending', 'image_captured', 'in_progress', 'completed', 'reviewed')";
}

// Modality filter
if (!empty($filter_modality)) {
    $main_where .= " AND ro.modality = :modality_filter";
    $main_params[':modality_filter'] = $filter_modality;
}

// Priority filter
if (!empty($filter_priority)) {
    $main_where .= " AND ro.priority = :priority_filter";
    $main_params[':priority_filter'] = $filter_priority;
}

// Date range filter
if (!empty($filter_date_from) && !empty($filter_date_to)) {
    $main_where .= " AND DATE(ro.created_at) BETWEEN :date_from AND :date_to";
    $main_params[':date_from'] = $filter_date_from;
    $main_params[':date_to'] = $filter_date_to;
}

// Search filter
if (!empty($search)) {
    $main_where .= " AND (ro.order_number LIKE :search OR p.first_name LIKE :search OR p.last_name LIKE :search OR ro.service_name LIKE :search)";
    $main_params[':search'] = "%{$search}%";
}

// Main query
$sql = "SELECT 
            ro.id, ro.order_number, ro.service_name, ro.service_code,
            ro.modality, ro.body_part, ro.priority, ro.payment_status,
            ro.status, ro.clinical_history, ro.findings, ro.impression,
            ro.interpretation_report, ro.image_attachment_path,
            ro.scheduled_date, ro.scheduled_time,
            ro.created_at, ro.updated_at, ro.performed_at, ro.reviewed_at,
            ro.cancellation_reason, ro.physician_review_notes,
            CONCAT(p.first_name, ' ', COALESCE(p.middle_name, ''), ' ', p.last_name) as patient_name,
            p.mr_number, p.date_of_birth, p.gender,
            CONCAT(u.first_name, ' ', u.last_name) as doctor_name,
            u.user_type as doctor_type,
            v.visit_number,
            DATEDIFF(CURDATE(), DATE(ro.created_at)) as days_waiting
        FROM radiology_orders ro
        LEFT JOIN patients p ON ro.patient_id = p.id
        LEFT JOIN users u ON ro.doctor_id = u.id
        LEFT JOIN patient_visits v ON ro.visit_id = v.id
        WHERE {$main_where}
        ORDER BY 
            CASE ro.priority 
                WHEN 'stat' THEN 1 
                WHEN 'emergency' THEN 2
                WHEN 'urgent' THEN 3 
                ELSE 4 
            END,
            CASE ro.status
                WHEN 'scheduled' THEN 1
                WHEN 'pending' THEN 2
                WHEN 'image_captured' THEN 3
                WHEN 'in_progress' THEN 4
                WHEN 'completed' THEN 5
                WHEN 'reviewed' THEN 6
                ELSE 7
            END,
            ro.created_at ASC
        LIMIT 200";

$stmt = $pdo->prepare($sql);
$stmt->execute($main_params);
$worklist_orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// 4b. STATS QUERY - SEPARATE PARAMETERS
// ============================================
$stats_params = [];
$stats_where = "deleted_at IS NULL";

if (!$is_global_admin && $branch_id > 0) {
    $stats_where .= " AND branch_id = :stats_branch_id";
    $stats_params[':stats_branch_id'] = $branch_id;
}

$stats_where .= " AND status IN ('scheduled', 'pending', 'image_captured', 'in_progress', 'completed', 'reviewed')";

$sql = "SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status = 'scheduled' THEN 1 ELSE 0 END) as scheduled,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN status = 'image_captured' THEN 1 ELSE 0 END) as image_captured,
            SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
            SUM(CASE WHEN status = 'reviewed' THEN 1 ELSE 0 END) as reviewed,
            SUM(CASE WHEN priority = 'stat' AND status IN ('scheduled', 'pending', 'image_captured', 'in_progress') THEN 1 ELSE 0 END) as stat_pending
        FROM radiology_orders
        WHERE {$stats_where}";
$stmt = $pdo->prepare($sql);
$stmt->execute($stats_params);
$workload_stats = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$workload_stats) {
    $workload_stats = [
        'total' => 0,
        'scheduled' => 0,
        'pending' => 0,
        'image_captured' => 0,
        'in_progress' => 0,
        'completed' => 0,
        'reviewed' => 0,
        'stat_pending' => 0
    ];
}

// ============================================
// 4c. MODALITY STATS - SEPARATE PARAMETERS
// ============================================
$modality_params = [];
$modality_where = "deleted_at IS NULL";

if (!$is_global_admin && $branch_id > 0) {
    $modality_where .= " AND branch_id = :mod_branch_id";
    $modality_params[':mod_branch_id'] = $branch_id;
}

$modality_where .= " AND status IN ('scheduled', 'pending', 'image_captured', 'in_progress', 'completed', 'reviewed')";

$sql = "SELECT modality, COUNT(*) as count 
        FROM radiology_orders 
        WHERE {$modality_where}
        GROUP BY modality";
$stmt = $pdo->prepare($sql);
$stmt->execute($modality_params);
$modality_stats = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $modality_stats[$row['modality']] = (int)$row['count'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Radiology Worklist | HMS ERP</title>
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
        .btn-danger { background: #ef476f; color: white; border-color: #ef476f; }
        .btn-danger:hover { background: #d63e5e; border-color: #d63e5e; }
        .btn-warning { background: #f9c74f; color: #1f2937; border-color: #f9c74f; }
        .btn-warning:hover { background: #f0b832; border-color: #f0b832; }
        .btn-info { background: #4cc9f0; color: white; border-color: #4cc9f0; }
        .btn-info:hover { background: #3ab8df; border-color: #3ab8df; }
        .btn-sm { padding: 4px 10px; font-size: 12px; }
        .btn-xs { padding: 2px 8px; font-size: 11px; }
        
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
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
            gap: 12px;
            margin-bottom: 20px;
        }
        .stat-card {
            background: white;
            padding: 14px 16px;
            border-radius: 10px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08);
            text-align: center;
            border-left: 3px solid #7209b7;
        }
        .stat-card .stat-number {
            font-size: 24px;
            font-weight: 700;
        }
        .stat-card .stat-label {
            font-size: 11px;
            color: #6b7280;
        }
        .stat-card.scheduled { border-left-color: #4cc9f0; }
        .stat-card.scheduled .stat-number { color: #4cc9f0; }
        .stat-card.pending { border-left-color: #f9c74f; }
        .stat-card.pending .stat-number { color: #d97706; }
        .stat-card.image_captured { border-left-color: #8b5cf6; }
        .stat-card.image_captured .stat-number { color: #8b5cf6; }
        .stat-card.in_progress { border-left-color: #f97316; }
        .stat-card.in_progress .stat-number { color: #f97316; }
        .stat-card.completed { border-left-color: #06d6a0; }
        .stat-card.completed .stat-number { color: #06d6a0; }
        .stat-card.reviewed { border-left-color: #7209b7; }
        .stat-card.reviewed .stat-number { color: #7209b7; }
        .stat-card.stat_pending { border-left-color: #ef476f; }
        .stat-card.stat_pending .stat-number { color: #ef476f; }
        
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
        
        .table-container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08);
            overflow: hidden;
            padding: 16px 20px;
        }
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
        .table-container tr.stat-priority { background: #fef2f2; }
        .table-container tr.stat-priority:hover { background: #fee2e2; }
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
        
        .badge {
            padding: 3px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            display: inline-block;
        }
        .badge-scheduled { background: #dbeafe; color: #1e40af; }
        .badge-pending { background: #fef3c7; color: #92400e; }
        .badge-image_captured { background: #ede9fe; color: #5b21b6; }
        .badge-in_progress { background: #fef3c7; color: #d97706; }
        .badge-completed { background: #d1fae5; color: #065f46; }
        .badge-reviewed { background: #d1fae5; color: #065f46; }
        
        .badge-stat { background: #ef476f; color: white; font-size: 9px; padding: 1px 6px; border-radius: 10px; }
        .badge-urgent { background: #f9c74f; color: #92400e; }
        .badge-routine { background: #dbeafe; color: #1e40af; }
        .badge-emergency { background: #ef476f; color: white; }
        
        .badge-payment-unpaid { background: #fee2e2; color: #991b1b; }
        .badge-payment-paid { background: #d1fae5; color: #065f46; }
        
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
        
        .patient-info { display: flex; flex-direction: column; }
        .patient-name { font-weight: 600; }
        .patient-detail { font-size: 11px; color: #6b7280; }
        
        .order-actions {
            display: flex;
            gap: 4px;
            flex-wrap: wrap;
        }
        .order-actions .btn { padding: 2px 8px; font-size: 11px; }
        
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
            max-width: 800px;
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
            border-color: #7209b7;
            box-shadow: 0 0 0 3px rgba(114, 9, 183, 0.1);
        }
        textarea.form-control { resize: vertical; min-height: 60px; }
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }
        
        .detail-section {
            margin-bottom: 16px;
            padding-bottom: 16px;
            border-bottom: 1px solid #e5e7eb;
        }
        .detail-section:last-child { border-bottom: none; margin-bottom: 0; }
        .detail-section .section-title {
            font-size: 14px;
            font-weight: 600;
            color: #374151;
            margin-bottom: 8px;
        }
        .detail-grid {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 8px;
        }
        .detail-item .detail-label {
            font-size: 11px;
            color: #6b7280;
        }
        .detail-item .detail-value {
            font-size: 14px;
            font-weight: 500;
        }
        .report-text {
            background: #f9fafb;
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            padding: 12px;
            white-space: pre-wrap;
            font-size: 13px;
            max-height: 200px;
            overflow-y: auto;
            font-family: 'Courier New', monospace;
        }
        
        .text-muted { color: #6b7280; }
        .text-center { text-align: center; }
        .mt-2 { margin-top: 8px; }
        .mb-2 { margin-bottom: 8px; }
        .flex { display: flex; }
        .flex-wrap { flex-wrap: wrap; }
        .items-center { align-items: center; }
        .gap-2 { gap: 8px; }
        
        @media (max-width: 768px) {
            .filter-bar { flex-direction: column; align-items: stretch; }
            .filter-bar select, .filter-bar input { width: 100%; }
            .form-row { grid-template-columns: 1fr; }
            .detail-grid { grid-template-columns: 1fr 1fr; }
            .stats-grid { grid-template-columns: repeat(3, 1fr); }
            .page-header h1 { font-size: 20px; }
            .table-container { padding: 8px; overflow-x: auto; }
            .table-container table { font-size: 12px; }
            .table-container th, .table-container td { padding: 6px 8px; }
        }
        @media (max-width: 480px) {
            .stats-grid { grid-template-columns: 1fr 1fr; }
            .detail-grid { grid-template-columns: 1fr; }
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
            <div>
                <h1>
                    <i class="fas fa-clipboard-list"></i> Radiology Worklist
                    <span class="subtitle"><?php echo date('l, F j, Y'); ?></span>
                    <?php if (!empty($filter_status)): ?>
                        <span class="badge" style="background:#7209b7;color:white;font-size:12px;margin-left:8px;">
                            <?php echo ucfirst(str_replace('_', ' ', $filter_status)); ?>
                        </span>
                    <?php endif; ?>
                </h1>
            </div>
            <div>
                <button class="btn" onclick="window.location.reload();">
                    <i class="fas fa-sync-alt"></i> Refresh
                </button>
                <a href="radiology_orders_queue.php" class="btn btn-primary">
                    <i class="fas fa-plus"></i> New Order
                </a>
            </div>
        </div>

        <?php if ($success_message): ?>
            <div class="alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success_message); ?></div>
        <?php endif; ?>
        <?php if ($error_message): ?>
            <div class="alert-danger"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error_message); ?></div>
        <?php endif; ?>

        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-card scheduled">
                <div class="stat-number"><?php echo $workload_stats['scheduled'] ?? 0; ?></div>
                <div class="stat-label"><i class="fas fa-clock"></i> Scheduled</div>
            </div>
            <div class="stat-card pending">
                <div class="stat-number"><?php echo $workload_stats['pending'] ?? 0; ?></div>
                <div class="stat-label"><i class="fas fa-hourglass-half"></i> Pending</div>
            </div>
            <div class="stat-card image_captured">
                <div class="stat-number"><?php echo $workload_stats['image_captured'] ?? 0; ?></div>
                <div class="stat-label"><i class="fas fa-images"></i> Captured</div>
            </div>
            <div class="stat-card in_progress">
                <div class="stat-number"><?php echo $workload_stats['in_progress'] ?? 0; ?></div>
                <div class="stat-label"><i class="fas fa-spinner fa-spin"></i> In Progress</div>
            </div>
            <div class="stat-card completed">
                <div class="stat-number"><?php echo $workload_stats['completed'] ?? 0; ?></div>
                <div class="stat-label"><i class="fas fa-check-circle"></i> Completed</div>
            </div>
            <div class="stat-card reviewed">
                <div class="stat-number"><?php echo $workload_stats['reviewed'] ?? 0; ?></div>
                <div class="stat-label"><i class="fas fa-check-double"></i> Reviewed</div>
            </div>
            <div class="stat-card stat_pending">
                <div class="stat-number"><?php echo $workload_stats['stat_pending'] ?? 0; ?></div>
                <div class="stat-label"><i class="fas fa-exclamation-triangle"></i> STAT Pending</div>
            </div>
            <div class="stat-card" style="border-left-color:#6b7280;">
                <div class="stat-number"><?php echo $workload_stats['total'] ?? 0; ?></div>
                <div class="stat-label"><i class="fas fa-tasks"></i> Total</div>
            </div>
        </div>

        <!-- Filter Bar -->
        <div class="filter-bar">
            <div>
                <span class="filter-label">Status</span>
                <select id="filterStatus" onchange="applyFilters()">
                    <option value="">All Status</option>
                    <option value="scheduled" <?php echo $filter_status === 'scheduled' ? 'selected' : ''; ?>>Scheduled</option>
                    <option value="pending" <?php echo $filter_status === 'pending' ? 'selected' : ''; ?>>Pending</option>
                    <option value="image_captured" <?php echo $filter_status === 'image_captured' ? 'selected' : ''; ?>>Image Captured</option>
                    <option value="in_progress" <?php echo $filter_status === 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                    <option value="completed" <?php echo $filter_status === 'completed' ? 'selected' : ''; ?>>Completed</option>
                    <option value="reviewed" <?php echo $filter_status === 'reviewed' ? 'selected' : ''; ?>>Reviewed</option>
                </select>
            </div>
            <div>
                <span class="filter-label">Modality</span>
                <select id="filterModality" onchange="applyFilters()">
                    <option value="">All</option>
                    <option value="xray" <?php echo $filter_modality === 'xray' ? 'selected' : ''; ?>>X-Ray</option>
                    <option value="ct" <?php echo $filter_modality === 'ct' ? 'selected' : ''; ?>>CT</option>
                    <option value="mri" <?php echo $filter_modality === 'mri' ? 'selected' : ''; ?>>MRI</option>
                    <option value="ultrasound" <?php echo $filter_modality === 'ultrasound' ? 'selected' : ''; ?>>Ultrasound</option>
                    <option value="mammography" <?php echo $filter_modality === 'mammography' ? 'selected' : ''; ?>>Mammography</option>
                    <option value="fluoroscopy" <?php echo $filter_modality === 'fluoroscopy' ? 'selected' : ''; ?>>Fluoroscopy</option>
                    <option value="angiography" <?php echo $filter_modality === 'angiography' ? 'selected' : ''; ?>>Angiography</option>
                </select>
            </div>
            <div>
                <span class="filter-label">Priority</span>
                <select id="filterPriority" onchange="applyFilters()">
                    <option value="">All</option>
                    <option value="stat" <?php echo $filter_priority === 'stat' ? 'selected' : ''; ?>>STAT</option>
                    <option value="urgent" <?php echo $filter_priority === 'urgent' ? 'selected' : ''; ?>>Urgent</option>
                    <option value="routine" <?php echo $filter_priority === 'routine' ? 'selected' : ''; ?>>Routine</option>
                    <option value="emergency" <?php echo $filter_priority === 'emergency' ? 'selected' : ''; ?>>Emergency</option>
                </select>
            </div>
            <div>
                <span class="filter-label">Date From</span>
                <input type="date" id="filterDateFrom" value="<?php echo $filter_date_from; ?>" onchange="applyFilters()">
            </div>
            <div>
                <span class="filter-label">To</span>
                <input type="date" id="filterDateTo" value="<?php echo $filter_date_to; ?>" onchange="applyFilters()">
            </div>
            <div style="flex:1;min-width:150px;">
                <input type="text" class="form-control" id="filterSearch" placeholder="Search orders, patients..." 
                       value="<?php echo htmlspecialchars($search); ?>" onkeypress="if(event.key==='Enter') applyFilters()">
            </div>
            <button class="btn btn-primary" onclick="applyFilters()"><i class="fas fa-search"></i> Filter</button>
            <button class="btn" onclick="resetFilters()"><i class="fas fa-times"></i> Reset</button>
        </div>

        <!-- Worklist Table -->
        <div class="table-container">
            <table id="worklistTable">
                <thead>
                    <tr>
                        <th>Order #</th>
                        <th>Patient</th>
                        <th>Exam</th>
                        <th>Modality</th>
                        <th>Priority</th>
                        <th>Status</th>
                        <th>Ordered</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($worklist_orders)): ?>
                        <tr>
                            <td colspan="8" class="no-data">
                                <i class="fas fa-inbox"></i>
                                No orders found in the worklist
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($worklist_orders as $order): ?>
                            <tr class="<?php echo ($order['priority'] ?? '') === 'stat' ? 'stat-priority' : ''; ?>">
                                <td>
                                    <strong><?php echo htmlspecialchars($order['order_number']); ?></strong>
                                    <?php if (($order['days_waiting'] ?? 0) > 1): ?>
                                        <br><span class="text-muted" style="font-size:10px;color:#ef476f;">
                                            <i class="fas fa-clock"></i> <?php echo $order['days_waiting']; ?>d
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="patient-info">
                                        <span class="patient-name"><?php echo htmlspecialchars($order['patient_name'] ?? 'Unknown'); ?></span>
                                        <span class="patient-detail">
                                            MRN: <?php echo htmlspecialchars($order['mr_number'] ?? 'N/A'); ?>
                                            <?php if (!empty($order['gender'])): ?>
                                                • <?php echo ucfirst($order['gender']); ?>
                                            <?php endif; ?>
                                            <?php if (!empty($order['date_of_birth'])): ?>
                                                • <?php echo date_diff(date_create($order['date_of_birth']), date_create('today'))->y; ?>y
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                </td>
                                <td>
                                    <div><?php echo htmlspecialchars($order['service_name'] ?? 'N/A'); ?></div>
                                    <?php if (!empty($order['body_part'])): ?>
                                        <span class="text-muted" style="font-size:11px;"><?php echo htmlspecialchars($order['body_part']); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="modality-badge modality-<?php echo $order['modality'] ?? 'xray'; ?>">
                                        <i class="fas <?php echo getModalityIcon($order['modality'] ?? 'xray'); ?>"></i>
                                        <?php echo ucfirst($order['modality'] ?? 'X-Ray'); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if (($order['priority'] ?? '') === 'stat'): ?>
                                        <span class="badge badge-stat">STAT</span>
                                    <?php elseif (($order['priority'] ?? '') === 'emergency'): ?>
                                        <span class="badge badge-emergency">Emergency</span>
                                    <?php elseif (($order['priority'] ?? '') === 'urgent'): ?>
                                        <span class="badge badge-urgent">Urgent</span>
                                    <?php else: ?>
                                        <span class="badge badge-routine"><?php echo ucfirst($order['priority'] ?? 'Routine'); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge badge-<?php echo str_replace('_', '', $order['status'] ?? 'scheduled'); ?>">
                                        <?php echo ucfirst(str_replace('_', ' ', $order['status'] ?? 'Scheduled')); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php echo date('M d, H:i', strtotime($order['created_at'] ?? 'now')); ?>
                                    <br><span class="text-muted" style="font-size:10px;">
                                        <?php echo htmlspecialchars($order['doctor_name'] ?? 'N/A'); ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="order-actions">
                                        <button class="btn btn-sm btn-primary" onclick="viewOrder(<?php echo $order['id']; ?>)">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <?php if (($order['status'] ?? '') === 'scheduled' || ($order['status'] ?? '') === 'pending'): ?>
                                            <button class="btn btn-sm btn-warning" onclick="startExam(<?php echo $order['id']; ?>)">
                                                <i class="fas fa-play"></i>
                                            </button>
                                        <?php endif; ?>
                                        <?php if (($order['status'] ?? '') === 'image_captured' || ($order['status'] ?? '') === 'in_progress'): ?>
                                            <button class="btn btn-sm btn-success" onclick="completeExam(<?php echo $order['id']; ?>)">
                                                <i class="fas fa-check"></i>
                                            </button>
                                        <?php endif; ?>
                                        <?php if (($order['status'] ?? '') === 'completed'): ?>
                                            <button class="btn btn-sm btn-info" onclick="reviewExam(<?php echo $order['id']; ?>)">
                                                <i class="fas fa-file-medical-alt"></i>
                                            </button>
                                        <?php endif; ?>
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

<!-- ============================================ -->
<!-- MODALS -->
<!-- ============================================ -->

<!-- View Order Modal -->
<div class="modal-overlay" id="viewOrderModal">
    <div class="modal">
        <div class="modal-header">
            <h2><i class="fas fa-file-medical-alt"></i> Exam Details</h2>
            <button class="modal-close" onclick="closeModal('viewOrderModal')">&times;</button>
        </div>
        <div class="modal-body" id="orderDetailsContent">
            <p class="text-muted text-center"><i class="fas fa-spinner fa-spin"></i> Loading order details...</p>
        </div>
        <div class="modal-footer">
            <button class="btn" onclick="closeModal('viewOrderModal')">Close</button>
            <button class="btn btn-primary" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
        </div>
    </div>
</div>

<!-- Start Exam Modal -->
<div class="modal-overlay" id="startExamModal">
    <div class="modal" style="max-width:500px;">
        <div class="modal-header">
            <h2><i class="fas fa-play"></i> Start Exam</h2>
            <button class="modal-close" onclick="closeModal('startExamModal')">&times;</button>
        </div>
        <form method="POST" action="">
            <input type="hidden" name="action" value="update_status">
            <input type="hidden" name="order_id" id="startExamOrderId">
            <input type="hidden" name="new_status" value="in_progress">
            <div class="modal-body">
                <div class="form-group">
                    <label>Order #</label>
                    <div id="startExamOrderNumber" style="font-weight:600;font-size:16px;"></div>
                </div>
                <div class="form-group">
                    <label>Patient</label>
                    <div id="startExamPatient" style="font-weight:500;"></div>
                </div>
                <div class="form-group">
                    <label>Modality</label>
                    <div id="startExamModality"></div>
                </div>
                <div class="alert alert-info" style="background:#dbeafe;padding:12px;border-radius:6px;color:#1e40af;">
                    <i class="fas fa-info-circle"></i> You are about to start this examination. The status will be updated to <strong>In Progress</strong>.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" onclick="closeModal('startExamModal')">Cancel</button>
                <button type="submit" class="btn btn-warning"><i class="fas fa-play"></i> Start Exam</button>
            </div>
        </form>
    </div>
</div>

<!-- Complete Exam Modal -->
<div class="modal-overlay" id="completeExamModal">
    <div class="modal" style="max-width:700px;">
        <div class="modal-header">
            <h2><i class="fas fa-check-circle"></i> Complete Exam</h2>
            <button class="modal-close" onclick="closeModal('completeExamModal')">&times;</button>
        </div>
        <form method="POST" action="">
            <input type="hidden" name="action" value="update_status">
            <input type="hidden" name="order_id" id="completeExamOrderId">
            <input type="hidden" name="new_status" value="completed">
            <div class="modal-body">
                <div class="form-group">
                    <label>Order #</label>
                    <div id="completeExamOrderNumber" style="font-weight:600;font-size:16px;"></div>
                </div>
                <div class="form-group">
                    <label>Findings</label>
                    <textarea name="findings" class="form-control" placeholder="Radiological findings..." rows="3"></textarea>
                </div>
                <div class="form-group">
                    <label>Impression</label>
                    <textarea name="impression" class="form-control" placeholder="Clinical impression..." rows="3"></textarea>
                </div>
                <div class="alert alert-success" style="background:#d1fae5;padding:12px;border-radius:6px;color:#065f46;">
                    <i class="fas fa-check-circle"></i> Completing this exam will mark it as <strong>Completed</strong>. You can add an interpretation report later.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" onclick="closeModal('completeExamModal')">Cancel</button>
                <button type="submit" class="btn btn-success"><i class="fas fa-check"></i> Complete Exam</button>
            </div>
        </form>
    </div>
</div>

<!-- Review Exam Modal -->
<div class="modal-overlay" id="reviewExamModal">
    <div class="modal" style="max-width:800px;">
        <div class="modal-header">
            <h2><i class="fas fa-file-medical-alt"></i> Review & Interpret</h2>
            <button class="modal-close" onclick="closeModal('reviewExamModal')">&times;</button>
        </div>
        <form method="POST" action="">
            <input type="hidden" name="action" value="update_status">
            <input type="hidden" name="order_id" id="reviewExamOrderId">
            <input type="hidden" name="new_status" value="reviewed">
            <div class="modal-body">
                <div class="form-row">
                    <div class="form-group">
                        <label>Order #</label>
                        <div id="reviewExamOrderNumber" style="font-weight:600;font-size:16px;"></div>
                    </div>
                    <div class="form-group">
                        <label>Patient</label>
                        <div id="reviewExamPatient" style="font-weight:500;"></div>
                    </div>
                </div>
                <div class="form-group">
                    <label>Findings (from exam)</label>
                    <div id="reviewExamFindings" style="background:#f9fafb;padding:10px;border-radius:6px;border:1px solid #e5e7eb;min-height:50px;"></div>
                </div>
                <div class="form-group">
                    <label>Impression (from exam)</label>
                    <div id="reviewExamImpression" style="background:#f9fafb;padding:10px;border-radius:6px;border:1px solid #e5e7eb;min-height:50px;"></div>
                </div>
                <div class="form-group">
                    <label>Interpretation Report <span class="required">*</span></label>
                    <textarea name="interpretation_report" class="form-control" placeholder="Detailed interpretation report..." rows="5" required></textarea>
                </div>
                <div class="form-group">
                    <label>Physician Review Notes</label>
                    <textarea name="physician_review_notes" class="form-control" placeholder="Additional review notes..." rows="3"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" onclick="closeModal('reviewExamModal')">Cancel</button>
                <button type="submit" class="btn btn-info"><i class="fas fa-save"></i> Save Report & Review</button>
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
// FILTER FUNCTIONS
// ============================================
function applyFilters() {
    const status = document.getElementById('filterStatus').value;
    const modality = document.getElementById('filterModality').value;
    const priority = document.getElementById('filterPriority').value;
    const dateFrom = document.getElementById('filterDateFrom').value;
    const dateTo = document.getElementById('filterDateTo').value;
    const search = document.getElementById('filterSearch').value;
    
    let url = window.location.pathname + '?';
    if (status) url += 'status=' + encodeURIComponent(status) + '&';
    if (modality) url += 'modality=' + encodeURIComponent(modality) + '&';
    if (priority) url += 'priority=' + encodeURIComponent(priority) + '&';
    if (dateFrom) url += 'date_from=' + encodeURIComponent(dateFrom) + '&';
    if (dateTo) url += 'date_to=' + encodeURIComponent(dateTo) + '&';
    if (search) url += 'search=' + encodeURIComponent(search) + '&';
    window.location.href = url;
}

function resetFilters() {
    window.location.href = window.location.pathname;
}

// ============================================
// ORDER ACTION FUNCTIONS
// ============================================
function viewOrder(orderId) {
    openModal('viewOrderModal');
    document.getElementById('orderDetailsContent').innerHTML = '<p class="text-muted text-center"><i class="fas fa-spinner fa-spin"></i> Loading order details...</p>';
    
    fetch('?ajax=get_order_details&id=' + orderId)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const o = data.order;
                let html = `
                    <div class="detail-section">
                        <div class="detail-grid">
                            <div class="detail-item">
                                <div class="detail-label">Order #</div>
                                <div class="detail-value">${o.order_number}</div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Status</div>
                                <div class="detail-value"><span class="badge badge-${o.status.replace('_', '')}">${o.status.replace('_', ' ').toUpperCase()}</span></div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Priority</div>
                                <div class="detail-value">${o.priority ? o.priority.toUpperCase() : 'N/A'}</div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Patient</div>
                                <div class="detail-value">${o.patient_name || 'Unknown'}</div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">MRN</div>
                                <div class="detail-value">${o.mr_number || 'N/A'}</div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">DOB / Gender</div>
                                <div class="detail-value">${o.date_of_birth || 'N/A'} / ${o.gender ? o.gender.charAt(0).toUpperCase() + o.gender.slice(1) : 'N/A'}</div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Modality</div>
                                <div class="detail-value"><span class="modality-badge modality-${o.modality || 'xray'}">${o.modality ? o.modality.toUpperCase() : 'X-Ray'}</span></div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Service</div>
                                <div class="detail-value">${o.service_name || 'N/A'}</div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Body Part</div>
                                <div class="detail-value">${o.body_part || 'N/A'}</div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Doctor</div>
                                <div class="detail-value">${o.doctor_name || 'N/A'}</div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Created</div>
                                <div class="detail-value">${o.created_at ? new Date(o.created_at).toLocaleString() : 'N/A'}</div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Payment</div>
                                <div class="detail-value"><span class="badge badge-payment-${o.payment_status || 'unpaid'}">${(o.payment_status || 'Unpaid').toUpperCase()}</span></div>
                            </div>
                        </div>
                    </div>
                `;
                
                if (o.clinical_history) {
                    html += `
                        <div class="detail-section">
                            <div class="section-title"><i class="fas fa-notes-medical"></i> Clinical History</div>
                            <div>${o.clinical_history}</div>
                        </div>
                    `;
                }
                
                if (o.findings) {
                    html += `
                        <div class="detail-section">
                            <div class="section-title"><i class="fas fa-search"></i> Findings</div>
                            <div class="report-text">${o.findings}</div>
                        </div>
                    `;
                }
                
                if (o.impression) {
                    html += `
                        <div class="detail-section">
                            <div class="section-title"><i class="fas fa-lightbulb"></i> Impression</div>
                            <div class="report-text">${o.impression}</div>
                        </div>
                    `;
                }
                
                if (o.interpretation_report) {
                    html += `
                        <div class="detail-section">
                            <div class="section-title"><i class="fas fa-file-medical-alt"></i> Interpretation Report</div>
                            <div class="report-text">${o.interpretation_report}</div>
                        </div>
                    `;
                }
                
                if (o.physician_review_notes) {
                    html += `
                        <div class="detail-section">
                            <div class="section-title"><i class="fas fa-user-md"></i> Review Notes</div>
                            <div>${o.physician_review_notes}</div>
                        </div>
                    `;
                }
                
                if (o.image_attachment_path) {
                    html += `
                        <div class="detail-section">
                            <div class="section-title"><i class="fas fa-image"></i> Images</div>
                            <div><a href="${o.image_attachment_path}" target="_blank" class="btn btn-sm btn-primary"><i class="fas fa-external-link-alt"></i> View Images</a></div>
                        </div>
                    `;
                }
                
                document.getElementById('orderDetailsContent').innerHTML = html;
            } else {
                document.getElementById('orderDetailsContent').innerHTML = '<p class="text-danger"><i class="fas fa-exclamation-circle"></i> ' + data.message + '</p>';
            }
        })
        .catch(error => {
            document.getElementById('orderDetailsContent').innerHTML = '<p class="text-danger"><i class="fas fa-exclamation-circle"></i> Error loading order details.</p>';
            console.error('Error:', error);
        });
}

function startExam(orderId) {
    document.getElementById('startExamOrderId').value = orderId;
    
    fetch('?ajax=get_order_details&id=' + orderId)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const o = data.order;
                document.getElementById('startExamOrderNumber').textContent = o.order_number;
                document.getElementById('startExamPatient').textContent = o.patient_name || 'Unknown';
                document.getElementById('startExamModality').innerHTML = '<span class="modality-badge modality-' + (o.modality || 'xray') + '">' + (o.modality ? o.modality.toUpperCase() : 'X-Ray') + '</span>';
            }
        });
    
    openModal('startExamModal');
}

function completeExam(orderId) {
    document.getElementById('completeExamOrderId').value = orderId;
    
    fetch('?ajax=get_order_details&id=' + orderId)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                document.getElementById('completeExamOrderNumber').textContent = data.order.order_number;
            }
        });
    
    openModal('completeExamModal');
}

function reviewExam(orderId) {
    document.getElementById('reviewExamOrderId').value = orderId;
    
    fetch('?ajax=get_order_details&id=' + orderId)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const o = data.order;
                document.getElementById('reviewExamOrderNumber').textContent = o.order_number;
                document.getElementById('reviewExamPatient').textContent = o.patient_name || 'Unknown';
                document.getElementById('reviewExamFindings').textContent = o.findings || 'No findings recorded.';
                document.getElementById('reviewExamImpression').textContent = o.impression || 'No impression recorded.';
            }
        });
    
    openModal('reviewExamModal');
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
    if (e.ctrlKey && e.key === 'r') {
        e.preventDefault();
        window.location.reload();
    }
});
</script>

<!-- DataTables - FIXED with proper initialization -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script>
$(document).ready(function() {
    // Check if table exists and has data
    var $table = $('#worklistTable');
    var hasRows = $table.find('tbody tr:not(.no-data)').length > 0;
    
    // Destroy any existing DataTable instance
    if ($.fn.DataTable.isDataTable('#worklistTable')) {
        $('#worklistTable').DataTable().destroy();
    }
    
    // Initialize DataTable
    if (hasRows) {
        $('#worklistTable').DataTable({
            responsive: true,
            pageLength: 25,
            order: [[6, 'desc']],
            columnDefs: [
                { orderable: false, targets: [7] }
            ],
            language: {
                emptyTable: "No orders in the worklist",
                search: "Search:",
                lengthMenu: "Show _MENU_ entries",
                info: "Showing _START_ to _END_ of _TOTAL_ entries"
            },
            drawCallback: function() {
                // Re-apply any custom styling after draw
            }
        });
    } else {
        // Initialize with empty data message
        $('#worklistTable').DataTable({
            responsive: true,
            pageLength: 25,
            order: [[6, 'desc']],
            columnDefs: [
                { orderable: false, targets: [7] }
            ],
            language: {
                emptyTable: "No orders found in the worklist",
                search: "Search:",
                lengthMenu: "Show _MENU_ entries",
                info: "Showing _START_ to _END_ of _TOTAL_ entries"
            }
        });
    }
});
</script>

</body>
</html>