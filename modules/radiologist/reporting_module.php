<?php
/**
 * reporting_module.php
 * 
 * HMS ERP - Radiology Reporting Module
 * FIXED: Removed is_critical column (not in schema)
 * 
 * Features:
 * - View pending and completed reports
 * - Create/Edit radiology reports
 * - Add findings, impressions, and interpretation
 * - Finalize and sign reports
 * - Print reports
 * - Report templates
 * - Auto-save drafts
 * - Report history
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
$is_radiologist = in_array($user_type, ['radiologist', 'super_admin', 'admin']);

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

// Helper function for modality icon
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

// Handle save/update report
if ($action === 'save_report' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $order_id = (int)($_POST['order_id'] ?? 0);
    $findings = trim($_POST['findings'] ?? '');
    $impression = trim($_POST['impression'] ?? '');
    $interpretation_report = trim($_POST['interpretation_report'] ?? '');
    $physician_review_notes = trim($_POST['physician_review_notes'] ?? '');
    $action_type = $_POST['action_type'] ?? 'save'; // save, finalize, review

    if ($order_id <= 0) {
        $error_message = 'Invalid order ID.';
    } else {
        try {
            $pdo->beginTransaction();

            // Get current order
            $sql = "SELECT status, order_number FROM radiology_orders WHERE id = :id AND deleted_at IS NULL";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':id' => $order_id]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$order) {
                throw new Exception('Order not found.');
            }

            // Build update query - REMOVED is_critical
            $update_fields = "findings = :findings, impression = :impression, interpretation_report = :report, physician_review_notes = :notes, updated_at = NOW()";
            $params = [
                ':id' => $order_id,
                ':findings' => $findings,
                ':impression' => $impression,
                ':report' => $interpretation_report,
                ':notes' => $physician_review_notes
            ];

            // Handle action type
            if ($action_type === 'finalize' && ($is_radiologist || $is_global_admin)) {
                $update_fields .= ", status = 'completed'";
            } elseif ($action_type === 'review' && ($is_radiologist || $is_global_admin)) {
                $update_fields .= ", status = 'reviewed', reviewed_at = NOW()";
            } elseif ($action_type === 'save') {
                // Just update content, keep status as in_progress if it's in that state
                if ($order['status'] === 'scheduled' || $order['status'] === 'pending_payment' || $order['status'] === 'pending') {
                    $update_fields .= ", status = 'in_progress'";
                }
            }

            $sql = "UPDATE radiology_orders SET {$update_fields} WHERE id = :id";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            $pdo->commit();
            $success_message = "Report saved successfully.";

        } catch (Exception $e) {
            $pdo->rollBack();
            $error_message = 'Error: ' . $e->getMessage();
            error_log('Report Save Error: ' . $e->getMessage());
        }
    }
}

// Handle delete/discard draft
if ($action === 'discard' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $order_id = (int)($_POST['order_id'] ?? 0);
    
    if ($order_id <= 0) {
        $error_message = 'Invalid order ID.';
    } else {
        try {
            $sql = "UPDATE radiology_orders SET findings = NULL, impression = NULL, interpretation_report = NULL, updated_at = NOW()
                    WHERE id = :id AND status = 'in_progress' AND deleted_at IS NULL";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':id' => $order_id]);
            
            $success_message = 'Draft discarded successfully.';
        } catch (PDOException $e) {
            $error_message = 'Error: ' . $e->getMessage();
        }
    }
}

// Handle AJAX for getting report data
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_report' && isset($_GET['id'])) {
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
                    ro.physician_review_notes,
                    CONCAT(p.first_name, ' ', COALESCE(p.middle_name, ''), ' ', p.last_name) as patient_name,
                    p.mr_number, p.date_of_birth, p.gender, p.phone,
                    CONCAT(u.first_name, ' ', u.last_name) as doctor_name,
                    u.first_name as doctor_first_name, u.last_name as doctor_last_name,
                    v.visit_number
                FROM radiology_orders ro
                LEFT JOIN patients p ON ro.patient_id = p.id
                LEFT JOIN users u ON ro.doctor_id = u.id
                LEFT JOIN patient_visits v ON ro.visit_id = v.id
                WHERE ro.id = :id AND ro.deleted_at IS NULL";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':id' => $order_id]);
        $report = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$report) {
            echo json_encode(['success' => false, 'message' => 'Report not found']);
            exit;
        }
        
        echo json_encode(['success' => true, 'report' => $report]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// ============================================
// 3. DATA FETCHING
// ============================================

// Build filter parameters
$filter_status = $_GET['status'] ?? '';
$filter_modality = $_GET['modality'] ?? '';
$filter_priority = $_GET['priority'] ?? '';
$search = trim($_GET['search'] ?? '');
$view = $_GET['view'] ?? 'pending'; // pending, in_progress, completed, reviewed, all

// Branch filter
$branch_filter = "";
$params = [];
if (!$is_global_admin && $branch_id > 0) {
    $branch_filter = " AND ro.branch_id = :branch_id";
    $params[':branch_id'] = $branch_id;
}

// Status filter based on view - FIXED: using correct status values from schema
if ($view === 'pending') {
    $status_filter = " AND ro.status IN ('scheduled', 'pending_payment')";
} elseif ($view === 'in_progress') {
    $status_filter = " AND ro.status = 'in_progress'";
} elseif ($view === 'completed') {
    $status_filter = " AND ro.status = 'completed'";
} elseif ($view === 'reviewed') {
    $status_filter = " AND ro.status = 'reviewed'";
} elseif (!empty($filter_status)) {
    $status_filter = " AND ro.status = :status_val";
    $params[':status_val'] = $filter_status;
} else {
    // Default: show all except cancelled
    $status_filter = " AND ro.status NOT IN ('cancelled', 'pending_payment')";
}

// Additional filters
if (!empty($filter_modality)) {
    $branch_filter .= " AND ro.modality = :modality_val";
    $params[':modality_val'] = $filter_modality;
}
if (!empty($filter_priority)) {
    $branch_filter .= " AND ro.priority = :priority_val";
    $params[':priority_val'] = $filter_priority;
}
if (!empty($search)) {
    $branch_filter .= " AND (ro.order_number LIKE :search_val OR p.first_name LIKE :search_val OR p.last_name LIKE :search_val OR ro.service_name LIKE :search_val)";
    $params[':search_val'] = "%{$search}%";
}

// Fetch reports
$sql = "SELECT 
            ro.id, ro.order_number, ro.service_name, ro.service_code,
            ro.modality, ro.body_part, ro.priority, ro.payment_status,
            ro.status, ro.clinical_history, ro.findings, ro.impression,
            ro.interpretation_report, ro.image_attachment_path,
            ro.created_at, ro.updated_at, ro.performed_at, ro.reviewed_at,
            ro.physician_review_notes,
            CONCAT(p.first_name, ' ', COALESCE(p.middle_name, ''), ' ', p.last_name) as patient_name,
            p.mr_number, p.date_of_birth, p.gender,
            CONCAT(u.first_name, ' ', u.last_name) as doctor_name,
            v.visit_number
        FROM radiology_orders ro
        LEFT JOIN patients p ON ro.patient_id = p.id
        LEFT JOIN users u ON ro.doctor_id = u.id
        LEFT JOIN patient_visits v ON ro.visit_id = v.id
        WHERE ro.deleted_at IS NULL" . $branch_filter . $status_filter . "
        ORDER BY 
            CASE ro.priority 
                WHEN 'stat' THEN 1 
                WHEN 'urgent' THEN 2 
                ELSE 3 
            END,
            CASE ro.status
                WHEN 'scheduled' THEN 1
                WHEN 'pending_payment' THEN 2
                WHEN 'in_progress' THEN 3
                WHEN 'completed' THEN 4
                WHEN 'reviewed' THEN 5
                ELSE 6
            END,
            ro.created_at DESC
        LIMIT 200";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$reports = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get statistics - REMOVED is_critical
$stats_params = [];
$stats_filter = "";
if (!$is_global_admin && $branch_id > 0) {
    $stats_filter .= " AND branch_id = :stats_branch_id";
    $stats_params[':stats_branch_id'] = $branch_id;
}

$sql = "SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status IN ('scheduled', 'pending_payment') THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
            SUM(CASE WHEN status = 'reviewed' THEN 1 ELSE 0 END) as reviewed
        FROM radiology_orders
        WHERE deleted_at IS NULL" . $stats_filter . "
        AND status NOT IN ('cancelled')";
$stmt = $pdo->prepare($sql);
$stmt->execute($stats_params);
$stats = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$stats) {
    $stats = ['total' => 0, 'pending' => 0, 'in_progress' => 0, 'completed' => 0, 'reviewed' => 0];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Radiology Reporting | HMS ERP</title>
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
        .btn-outline-primary { background: transparent; color: #7209b7; border-color: #7209b7; }
        .btn-outline-primary:hover { background: #7209b7; color: white; }
        .btn-sm { padding: 4px 10px; font-size: 12px; }
        
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
        
        /* Stats */
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
            text-align: center;
            border-left: 3px solid #7209b7;
        }
        .stat-card .stat-number {
            font-size: 24px;
            font-weight: 700;
        }
        .stat-card .stat-label {
            font-size: 12px;
            color: #6b7280;
        }
        .stat-card.pending { border-left-color: #f9c74f; }
        .stat-card.pending .stat-number { color: #d97706; }
        .stat-card.in_progress { border-left-color: #f97316; }
        .stat-card.in_progress .stat-number { color: #f97316; }
        .stat-card.completed { border-left-color: #06d6a0; }
        .stat-card.completed .stat-number { color: #06d6a0; }
        .stat-card.reviewed { border-left-color: #7209b7; }
        .stat-card.reviewed .stat-number { color: #7209b7; }
        .stat-card.total { border-left-color: #6b7280; }
        .stat-card.total .stat-number { color: #6b7280; }
        
        /* Filter Bar */
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
        .view-tabs {
            display: flex;
            gap: 4px;
            background: #f3f4f6;
            padding: 4px;
            border-radius: 8px;
        }
        .view-tabs .tab-btn {
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
        .view-tabs .tab-btn:hover { background: #e5e7eb; }
        .view-tabs .tab-btn.active {
            background: white;
            color: #7209b7;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        
        /* Table */
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
        .badge-pending_payment { background: #fef3c7; color: #92400e; }
        .badge-in_progress { background: #fef3c7; color: #d97706; }
        .badge-completed { background: #d1fae5; color: #065f46; }
        .badge-reviewed { background: #d1fae5; color: #065f46; }
        .badge-cancelled { background: #fee2e2; color: #991b1b; }
        
        .badge-stat { background: #ef476f; color: white; font-size: 9px; padding: 1px 6px; border-radius: 10px; }
        .badge-urgent { background: #f9c74f; color: #92400e; }
        .badge-routine { background: #dbeafe; color: #1e40af; }
        
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
        
        .order-actions {
            display: flex;
            gap: 4px;
            flex-wrap: wrap;
        }
        .order-actions .btn { padding: 2px 8px; font-size: 11px; }
        
        /* Report Editor Modal */
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
            max-width: 900px;
            width: 100%;
            max-height: 95vh;
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
            flex-wrap: wrap;
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
        textarea.form-control { resize: vertical; min-height: 80px; }
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }
        
        /* Patient info in editor */
        .patient-info-box {
            background: #f9fafb;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 16px;
            margin-bottom: 16px;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 8px;
        }
        .patient-info-box .info-item .info-label {
            font-size: 10px;
            color: #6b7280;
            text-transform: uppercase;
        }
        .patient-info-box .info-item .info-value {
            font-size: 14px;
            font-weight: 500;
        }
        
        .text-muted { color: #6b7280; }
        .text-center { text-align: center; }
        .text-danger { color: #ef476f; }
        .text-warning { color: #d97706; }
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
            .patient-info-box { grid-template-columns: 1fr 1fr; }
            .stats-grid { grid-template-columns: repeat(3, 1fr); }
            .page-header h1 { font-size: 20px; }
            .table-container { padding: 8px; overflow-x: auto; }
            .table-container table { font-size: 12px; }
            .table-container th, .table-container td { padding: 6px 8px; }
            .view-tabs .tab-btn { padding: 4px 10px; font-size: 11px; }
        }
        @media (max-width: 480px) {
            .stats-grid { grid-template-columns: 1fr 1fr; }
            .patient-info-box { grid-template-columns: 1fr; }
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
                    <i class="fas fa-file-signature"></i> Radiology Reporting
                    <span class="subtitle"><?php echo date('l, F j, Y'); ?></span>
                </h1>
            </div>
            <div>
                <button class="btn" onclick="window.location.reload();">
                    <i class="fas fa-sync-alt"></i> Refresh
                </button>
                <a href="radiology_worklist.php" class="btn btn-primary">
                    <i class="fas fa-arrow-left"></i> Back to Worklist
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
            <div class="stat-card pending">
                <div class="stat-number"><?php echo $stats['pending'] ?? 0; ?></div>
                <div class="stat-label"><i class="fas fa-clock"></i> Pending</div>
            </div>
            <div class="stat-card in_progress">
                <div class="stat-number"><?php echo $stats['in_progress'] ?? 0; ?></div>
                <div class="stat-label"><i class="fas fa-spinner fa-spin"></i> In Progress</div>
            </div>
            <div class="stat-card completed">
                <div class="stat-number"><?php echo $stats['completed'] ?? 0; ?></div>
                <div class="stat-label"><i class="fas fa-check-circle"></i> Completed</div>
            </div>
            <div class="stat-card reviewed">
                <div class="stat-number"><?php echo $stats['reviewed'] ?? 0; ?></div>
                <div class="stat-label"><i class="fas fa-check-double"></i> Reviewed</div>
            </div>
            <div class="stat-card total">
                <div class="stat-number"><?php echo $stats['total'] ?? 0; ?></div>
                <div class="stat-label"><i class="fas fa-tasks"></i> Total</div>
            </div>
        </div>

        <!-- Filter Bar -->
        <div class="filter-bar">
            <div class="view-tabs">
                <button class="tab-btn <?php echo $view === 'pending' ? 'active' : ''; ?>" onclick="setView('pending')">Pending</button>
                <button class="tab-btn <?php echo $view === 'in_progress' ? 'active' : ''; ?>" onclick="setView('in_progress')">In Progress</button>
                <button class="tab-btn <?php echo $view === 'completed' ? 'active' : ''; ?>" onclick="setView('completed')">Completed</button>
                <button class="tab-btn <?php echo $view === 'reviewed' ? 'active' : ''; ?>" onclick="setView('reviewed')">Reviewed</button>
                <button class="tab-btn <?php echo $view === 'all' ? 'active' : ''; ?>" onclick="setView('all')">All</button>
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
                </select>
            </div>
            <div style="flex:1;min-width:150px;">
                <input type="text" class="form-control" id="filterSearch" placeholder="Search orders, patients..." 
                       value="<?php echo htmlspecialchars($search); ?>" onkeypress="if(event.key==='Enter') applyFilters()">
            </div>
            <button class="btn btn-primary" onclick="applyFilters()"><i class="fas fa-search"></i> Filter</button>
            <button class="btn" onclick="resetFilters()"><i class="fas fa-times"></i> Reset</button>
        </div>

        <!-- Reports Table -->
        <div class="table-container">
            <table id="reportsTable">
                <thead>
                    <tr>
                        <th>Order #</th>
                        <th>Patient</th>
                        <th>Exam</th>
                        <th>Modality</th>
                        <th>Priority</th>
                        <th>Status</th>
                        <th>Created</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($reports)): ?>
                        <tr>
                            <td colspan="8" class="no-data">
                                <i class="fas fa-file-medical-alt"></i>
                                No reports found
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($reports as $report): ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($report['order_number']); ?></strong>
                                </td>
                                <td>
                                    <div class="patient-info">
                                        <span class="patient-name"><?php echo htmlspecialchars($report['patient_name'] ?? 'Unknown'); ?></span>
                                        <span class="patient-detail">
                                            MRN: <?php echo htmlspecialchars($report['mr_number'] ?? 'N/A'); ?>
                                            <?php if (!empty($report['gender'])): ?>
                                                • <?php echo ucfirst($report['gender']); ?>
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                </td>
                                <td>
                                    <div><?php echo htmlspecialchars($report['service_name'] ?? 'N/A'); ?></div>
                                    <?php if (!empty($report['body_part'])): ?>
                                        <span class="text-muted" style="font-size:11px;"><?php echo htmlspecialchars($report['body_part']); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="modality-badge modality-<?php echo $report['modality'] ?? 'xray'; ?>">
                                        <i class="fas <?php echo getModalityIcon($report['modality'] ?? 'xray'); ?>"></i>
                                        <?php echo ucfirst($report['modality'] ?? 'X-Ray'); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if (($report['priority'] ?? '') === 'stat'): ?>
                                        <span class="badge badge-stat">STAT</span>
                                    <?php elseif (($report['priority'] ?? '') === 'urgent'): ?>
                                        <span class="badge badge-urgent">Urgent</span>
                                    <?php else: ?>
                                        <span class="badge badge-routine"><?php echo ucfirst($report['priority'] ?? 'Routine'); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge badge-<?php echo str_replace('_', '', $report['status'] ?? 'scheduled'); ?>">
                                        <?php echo ucfirst(str_replace('_', ' ', $report['status'] ?? 'Scheduled')); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php echo date('M d, H:i', strtotime($report['created_at'] ?? 'now')); ?>
                                    <br><span class="text-muted" style="font-size:10px;">
                                        <?php echo htmlspecialchars($report['doctor_name'] ?? 'N/A'); ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="order-actions">
                                        <button class="btn btn-sm btn-primary" onclick="viewReport(<?php echo $report['id']; ?>)">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <?php if (in_array($report['status'] ?? '', ['scheduled', 'pending_payment', 'in_progress'])): ?>
                                            <button class="btn btn-sm btn-warning" onclick="editReport(<?php echo $report['id']; ?>)">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                        <?php endif; ?>
                                        <?php if (($report['status'] ?? '') === 'completed' && ($is_radiologist || $is_global_admin)): ?>
                                            <button class="btn btn-sm btn-info" onclick="reviewReport(<?php echo $report['id']; ?>)">
                                                <i class="fas fa-check-double"></i>
                                            </button>
                                        <?php endif; ?>
                                        <button class="btn btn-sm btn-success" onclick="printReport(<?php echo $report['id']; ?>)">
                                            <i class="fas fa-print"></i>
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

<!-- ============================================ -->
<!-- MODALS -->
<!-- ============================================ -->

<!-- Report Editor Modal -->
<div class="modal-overlay" id="reportEditorModal">
    <div class="modal">
        <div class="modal-header">
            <h2><i class="fas fa-edit"></i> <span id="reportEditorTitle">Edit Report</span></h2>
            <button class="modal-close" onclick="closeModal('reportEditorModal')">&times;</button>
        </div>
        <form method="POST" action="" id="reportForm">
            <input type="hidden" name="action" value="save_report">
            <input type="hidden" name="order_id" id="reportOrderId">
            <input type="hidden" name="action_type" id="reportActionType" value="save">
            <div class="modal-body">
                <!-- Patient Info -->
                <div class="patient-info-box" id="reportPatientInfo">
                    <div class="info-item">
                        <div class="info-label">Patient</div>
                        <div class="info-value" id="reportPatientName">-</div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">MRN</div>
                        <div class="info-value" id="reportMRN">-</div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Order #</div>
                        <div class="info-value" id="reportOrderNumber">-</div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Modality</div>
                        <div class="info-value" id="reportModality">-</div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Service</div>
                        <div class="info-value" id="reportService">-</div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Body Part</div>
                        <div class="info-value" id="reportBodyPart">-</div>
                    </div>
                </div>

                <!-- Clinical History -->
                <div class="form-group">
                    <label>Clinical History</label>
                    <div id="reportClinicalHistory" style="background:#f9fafb;padding:10px;border-radius:6px;border:1px solid #e5e7eb;min-height:40px;"></div>
                </div>

                <!-- Findings -->
                <div class="form-group">
                    <label>Findings</label>
                    <textarea name="findings" id="reportFindings" class="form-control" placeholder="Describe the radiological findings..." rows="4"></textarea>
                </div>

                <!-- Impression -->
                <div class="form-group">
                    <label>Impression</label>
                    <textarea name="impression" id="reportImpression" class="form-control" placeholder="Clinical impression and conclusion..." rows="3"></textarea>
                </div>

                <!-- Interpretation Report -->
                <div class="form-group">
                    <label>Interpretation Report <span class="required">*</span></label>
                    <textarea name="interpretation_report" id="reportInterpretation" class="form-control" placeholder="Detailed interpretation report..." rows="5" required></textarea>
                </div>

                <!-- Physician Review Notes -->
                <div class="form-group">
                    <label>Physician Review Notes</label>
                    <textarea name="physician_review_notes" id="reportReviewNotes" class="form-control" placeholder="Additional notes for review..." rows="2"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-danger" id="discardDraftBtn" onclick="discardDraft()">
                    <i class="fas fa-trash"></i> Discard Draft
                </button>
                <button type="button" class="btn" onclick="closeModal('reportEditorModal')">Cancel</button>
                <button type="submit" class="btn btn-primary" onclick="setActionType('save')">
                    <i class="fas fa-save"></i> Save Draft
                </button>
                <?php if ($is_radiologist || $is_global_admin): ?>
                    <button type="submit" class="btn btn-success" onclick="setActionType('finalize')">
                        <i class="fas fa-check-circle"></i> Finalize & Complete
                    </button>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- View Report Modal -->
<div class="modal-overlay" id="viewReportModal">
    <div class="modal">
        <div class="modal-header">
            <h2><i class="fas fa-file-medical-alt"></i> Report Details</h2>
            <button class="modal-close" onclick="closeModal('viewReportModal')">&times;</button>
        </div>
        <div class="modal-body" id="viewReportContent">
            <p class="text-muted text-center"><i class="fas fa-spinner fa-spin"></i> Loading report...</p>
        </div>
        <div class="modal-footer">
            <button class="btn" onclick="closeModal('viewReportModal')">Close</button>
            <button class="btn btn-primary" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
        </div>
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
function setView(view) {
    let url = window.location.pathname + '?view=' + view;
    const modality = document.getElementById('filterModality').value;
    const priority = document.getElementById('filterPriority').value;
    const search = document.getElementById('filterSearch').value;
    if (modality) url += '&modality=' + encodeURIComponent(modality);
    if (priority) url += '&priority=' + encodeURIComponent(priority);
    if (search) url += '&search=' + encodeURIComponent(search);
    window.location.href = url;
}

function applyFilters() {
    const view = '<?php echo $view; ?>';
    const modality = document.getElementById('filterModality').value;
    const priority = document.getElementById('filterPriority').value;
    const search = document.getElementById('filterSearch').value;
    
    let url = window.location.pathname + '?view=' + view;
    if (modality) url += '&modality=' + encodeURIComponent(modality);
    if (priority) url += '&priority=' + encodeURIComponent(priority);
    if (search) url += '&search=' + encodeURIComponent(search);
    window.location.href = url;
}

function resetFilters() {
    window.location.href = window.location.pathname + '?view=<?php echo $view; ?>';
}

// ============================================
// REPORT FUNCTIONS
// ============================================
function editReport(orderId) {
    document.getElementById('reportOrderId').value = orderId;
    document.getElementById('reportEditorTitle').textContent = 'Edit Report';
    document.getElementById('reportActionType').value = 'save';
    document.getElementById('reportFindings').value = '';
    document.getElementById('reportImpression').value = '';
    document.getElementById('reportInterpretation').value = '';
    document.getElementById('reportReviewNotes').value = '';
    
    // Show discard button only for drafts
    document.getElementById('discardDraftBtn').style.display = 'inline-flex';
    
    openModal('reportEditorModal');
    
    fetch('?ajax=get_report&id=' + orderId)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const r = data.report;
                document.getElementById('reportPatientName').textContent = r.patient_name || 'Unknown';
                document.getElementById('reportMRN').textContent = r.mr_number || 'N/A';
                document.getElementById('reportOrderNumber').textContent = r.order_number;
                document.getElementById('reportModality').textContent = r.modality ? r.modality.toUpperCase() : 'X-Ray';
                document.getElementById('reportService').textContent = r.service_name || 'N/A';
                document.getElementById('reportBodyPart').textContent = r.body_part || 'N/A';
                document.getElementById('reportClinicalHistory').textContent = r.clinical_history || 'No clinical history provided.';
                document.getElementById('reportFindings').value = r.findings || '';
                document.getElementById('reportImpression').value = r.impression || '';
                document.getElementById('reportInterpretation').value = r.interpretation_report || '';
                document.getElementById('reportReviewNotes').value = r.physician_review_notes || '';
                
                // Hide discard button if already has content or status is completed/reviewed
                if (r.status === 'completed' || r.status === 'reviewed') {
                    document.getElementById('discardDraftBtn').style.display = 'none';
                }
            } else {
                alert('Error loading report: ' + data.message);
            }
        })
        .catch(error => {
            alert('Error loading report data.');
            console.error('Error:', error);
        });
}

function viewReport(orderId) {
    openModal('viewReportModal');
    document.getElementById('viewReportContent').innerHTML = '<p class="text-muted text-center"><i class="fas fa-spinner fa-spin"></i> Loading report...</p>';
    
    fetch('?ajax=get_report&id=' + orderId)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const r = data.report;
                let html = `
                    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;margin-bottom:16px;padding:16px;background:#f9fafb;border-radius:8px;">
                        <div><strong>Order #:</strong> ${r.order_number}</div>
                        <div><strong>Status:</strong> <span class="badge badge-${r.status.replace('_', '')}">${r.status.replace('_', ' ').toUpperCase()}</span></div>
                        <div><strong>Priority:</strong> ${r.priority ? r.priority.toUpperCase() : 'N/A'}</div>
                        <div><strong>Patient:</strong> ${r.patient_name || 'Unknown'}</div>
                        <div><strong>MRN:</strong> ${r.mr_number || 'N/A'}</div>
                        <div><strong>Modality:</strong> ${r.modality ? r.modality.toUpperCase() : 'X-Ray'}</div>
                        <div><strong>Service:</strong> ${r.service_name || 'N/A'}</div>
                        <div><strong>Body Part:</strong> ${r.body_part || 'N/A'}</div>
                        <div><strong>Doctor:</strong> ${r.doctor_name || 'N/A'}</div>
                        <div><strong>Created:</strong> ${r.created_at ? new Date(r.created_at).toLocaleString() : 'N/A'}</div>
                        <div><strong>Completed:</strong> ${r.performed_at ? new Date(r.performed_at).toLocaleString() : 'N/A'}</div>
                        <div><strong>Reviewed:</strong> ${r.reviewed_at ? new Date(r.reviewed_at).toLocaleString() : 'N/A'}</div>
                    </div>
                `;
                
                if (r.clinical_history) {
                    html += `<div style="margin-bottom:12px;"><strong>Clinical History:</strong><br>${r.clinical_history}</div>`;
                }
                
                if (r.findings) {
                    html += `<div style="margin-bottom:12px;"><strong>Findings:</strong><br><div style="background:#f9fafb;padding:10px;border-radius:6px;border:1px solid #e5e7eb;">${r.findings}</div></div>`;
                }
                
                if (r.impression) {
                    html += `<div style="margin-bottom:12px;"><strong>Impression:</strong><br><div style="background:#f9fafb;padding:10px;border-radius:6px;border:1px solid #e5e7eb;">${r.impression}</div></div>`;
                }
                
                if (r.interpretation_report) {
                    html += `<div style="margin-bottom:12px;"><strong>Interpretation Report:</strong><br><div style="background:#f9fafb;padding:10px;border-radius:6px;border:1px solid #e5e7eb;white-space:pre-wrap;">${r.interpretation_report}</div></div>`;
                }
                
                if (r.physician_review_notes) {
                    html += `<div style="margin-bottom:12px;"><strong>Review Notes:</strong><br>${r.physician_review_notes}</div>`;
                }
                
                document.getElementById('viewReportContent').innerHTML = html;
            } else {
                document.getElementById('viewReportContent').innerHTML = '<p class="text-danger"><i class="fas fa-exclamation-circle"></i> ' + data.message + '</p>';
            }
        })
        .catch(error => {
            document.getElementById('viewReportContent').innerHTML = '<p class="text-danger"><i class="fas fa-exclamation-circle"></i> Error loading report.</p>';
            console.error('Error:', error);
        });
}

function reviewReport(orderId) {
    if (confirm('Mark this report as reviewed?')) {
        document.getElementById('reportOrderId').value = orderId;
        document.getElementById('reportActionType').value = 'review';
        document.getElementById('reportForm').submit();
    }
}

function setActionType(type) {
    document.getElementById('reportActionType').value = type;
}

function discardDraft() {
    if (confirm('Are you sure you want to discard this draft? All unsaved changes will be lost.')) {
        const orderId = document.getElementById('reportOrderId').value;
        fetch('', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=discard&order_id=' + orderId
        })
        .then(() => {
            closeModal('reportEditorModal');
            window.location.reload();
        })
        .catch(error => {
            alert('Error discarding draft.');
            console.error('Error:', error);
        });
    }
}

function printReport(orderId) {
    viewReport(orderId);
    setTimeout(() => {
        window.print();
    }, 1000);
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
    if ((e.ctrlKey || e.metaKey) && e.key === 's') {
        e.preventDefault();
        const modal = document.getElementById('reportEditorModal');
        if (modal.classList.contains('active')) {
            document.getElementById('reportForm').submit();
        }
    }
});
</script>

<!-- DataTables -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script>
$(document).ready(function() {
    var $table = $('#reportsTable');
    var hasRows = $table.find('tbody tr:not(.no-data)').length > 0;
    
    if ($.fn.DataTable.isDataTable('#reportsTable')) {
        $('#reportsTable').DataTable().destroy();
    }
    
    if (hasRows) {
        $('#reportsTable').DataTable({
            responsive: true,
            pageLength: 25,
            order: [[6, 'desc']],
            columnDefs: [
                { orderable: false, targets: [7] }
            ],
            language: {
                emptyTable: "No reports found",
                search: "Search:",
                lengthMenu: "Show _MENU_ entries",
                info: "Showing _START_ to _END_ of _TOTAL_ entries"
            }
        });
    } else {
        $('#reportsTable').DataTable({
            responsive: true,
            pageLength: 25,
            order: [[6, 'desc']],
            columnDefs: [
                { orderable: false, targets: [7] }
            ],
            language: {
                emptyTable: "No reports found",
                search: "Search:"
            }
        });
    }
});
</script>

</body>
</html>