<?php
/**
 * report_approval.php
 * 
 * HMS ERP - Radiology Report Approval Module
 * COMPLETELY FIXED: DataTables column count and _DT_CellIndex errors resolved
 * 
 * @package HMS_ERP
 * @version 3.0
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

// Restrict access - only radiologists and admins can approve reports
if (!$is_radiologist && !$is_global_admin) {
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

// Handle approval
if ($action === 'approve' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $order_id = (int)($_POST['order_id'] ?? 0);
    $approval_notes = trim($_POST['approval_notes'] ?? '');
    
    if ($order_id <= 0) {
        $error_message = 'Invalid order ID.';
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

            if ($order['status'] !== 'completed') {
                throw new Exception('Only completed reports can be approved.');
            }

            $sql = "UPDATE radiology_orders 
                    SET status = 'reviewed', 
                        reviewed_at = NOW(),
                        physician_review_notes = CONCAT(
                            COALESCE(physician_review_notes, ''),
                            '\n\n[APPROVED on ',
                            NOW(),
                            ' by ',
                            (SELECT CONCAT(first_name, ' ', last_name) FROM users WHERE id = :user_id),
                            ']',
                            CASE WHEN :notes != '' THEN CONCAT('\nNotes: ', :notes) ELSE '' END
                        ),
                        updated_at = NOW()
                    WHERE id = :id AND deleted_at IS NULL";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':id' => $order_id,
                ':user_id' => $user_id,
                ':notes' => $approval_notes
            ]);

            $pdo->commit();
            $success_message = "Report #{$order['order_number']} approved successfully.";

        } catch (Exception $e) {
            $pdo->rollBack();
            $error_message = 'Error: ' . $e->getMessage();
            error_log('Report Approval Error: ' . $e->getMessage());
        }
    }
}

// Handle rejection
if ($action === 'reject' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $order_id = (int)($_POST['order_id'] ?? 0);
    $rejection_reason = trim($_POST['rejection_reason'] ?? '');
    
    if ($order_id <= 0) {
        $error_message = 'Invalid order ID.';
    } elseif (empty($rejection_reason)) {
        $error_message = 'Please provide a reason for rejection.';
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

            if ($order['status'] !== 'completed') {
                throw new Exception('Only completed reports can be rejected.');
            }

            $sql = "UPDATE radiology_orders 
                    SET status = 'in_progress',
                        physician_review_notes = CONCAT(
                            COALESCE(physician_review_notes, ''),
                            '\n\n[REJECTED on ',
                            NOW(),
                            ' by ',
                            (SELECT CONCAT(first_name, ' ', last_name) FROM users WHERE id = :user_id),
                            ']',
                            '\nReason: ',
                            :reason
                        ),
                        updated_at = NOW()
                    WHERE id = :id AND deleted_at IS NULL";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':id' => $order_id,
                ':user_id' => $user_id,
                ':reason' => $rejection_reason
            ]);

            $pdo->commit();
            $success_message = "Report #{$order['order_number']} rejected. Report returned to In Progress status.";

        } catch (Exception $e) {
            $pdo->rollBack();
            $error_message = 'Error: ' . $e->getMessage();
            error_log('Report Rejection Error: ' . $e->getMessage());
        }
    }
}

// Helper function for audit logging
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
                    ro.created_at, ro.updated_at, ro.performed_at, ro.reviewed_at,
                    ro.physician_review_notes,
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
$filter_modality = $_GET['modality'] ?? '';
$filter_priority = $_GET['priority'] ?? '';
$filter_date_from = $_GET['date_from'] ?? date('Y-m-d', strtotime('-7 days'));
$filter_date_to = $_GET['date_to'] ?? date('Y-m-d');
$search = trim($_GET['search'] ?? '');

// Branch filter
$branch_filter = "";
$params = [];
if (!$is_global_admin && $branch_id > 0) {
    $branch_filter = " AND ro.branch_id = :branch_id";
    $params[':branch_id'] = $branch_id;
}

// Status filter
$filter_status = $_GET['status'] ?? 'pending_approval';
if ($filter_status === 'pending_approval') {
    $status_filter = " AND ro.status = 'completed'";
} elseif ($filter_status === 'approved') {
    $status_filter = " AND ro.status = 'reviewed'";
} elseif ($filter_status === 'all') {
    $status_filter = " AND ro.status IN ('completed', 'reviewed')";
} else {
    $status_filter = " AND ro.status = 'completed'";
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
if (!empty($filter_date_from) && !empty($filter_date_to)) {
    $branch_filter .= " AND DATE(ro.created_at) BETWEEN :date_from AND :date_to";
    $params[':date_from'] = $filter_date_from;
    $params[':date_to'] = $filter_date_to;
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
            ro.created_at DESC
        LIMIT 200";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$reports = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get statistics
$stats_params = [];
$stats_filter = "";
if (!$is_global_admin && $branch_id > 0) {
    $stats_filter .= " AND branch_id = :stats_branch_id";
    $stats_params[':stats_branch_id'] = $branch_id;
}

$sql = "SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as pending_approval,
            SUM(CASE WHEN status = 'reviewed' THEN 1 ELSE 0 END) as approved,
            SUM(CASE WHEN priority = 'stat' AND status = 'completed' THEN 1 ELSE 0 END) as stat_pending
        FROM radiology_orders
        WHERE deleted_at IS NULL" . $stats_filter . "
        AND status IN ('completed', 'reviewed')";
$stmt = $pdo->prepare($sql);
$stmt->execute($stats_params);
$stats = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$stats) {
    $stats = ['total' => 0, 'pending_approval' => 0, 'approved' => 0, 'stat_pending' => 0];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Report Approval | Radiology | HMS ERP</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
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
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 12px;
            margin-bottom: 20px;
        }
        .stat-card {
            background: white;
            padding: 16px 20px;
            border-radius: 10px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08);
            text-align: center;
            border-left: 3px solid #7209b7;
        }
        .stat-card .stat-number {
            font-size: 28px;
            font-weight: 700;
        }
        .stat-card .stat-label {
            font-size: 12px;
            color: #6b7280;
        }
        .stat-card .stat-icon {
            font-size: 20px;
            margin-bottom: 4px;
        }
        .stat-card.pending { border-left-color: #f9c74f; }
        .stat-card.pending .stat-number { color: #d97706; }
        .stat-card.approved { border-left-color: #06d6a0; }
        .stat-card.approved .stat-number { color: #06d6a0; }
        .stat-card.stat_pending { border-left-color: #ef476f; }
        .stat-card.stat_pending .stat-number { color: #ef476f; }
        .stat-card.total { border-left-color: #7209b7; }
        .stat-card.total .stat-number { color: #7209b7; }
        
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
        .view-tabs .tab-btn .badge-tab {
            background: #ef476f;
            color: white;
            border-radius: 10px;
            padding: 0 6px;
            font-size: 10px;
            margin-left: 4px;
        }
        .view-tabs .tab-btn .badge-tab.approved-badge {
            background: #06d6a0;
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
        .badge-completed { background: #d1fae5; color: #065f46; }
        .badge-reviewed { background: #d1fae5; color: #065f46; }
        
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
        textarea.form-control { resize: vertical; min-height: 80px; }
        
        .report-preview {
            background: #f9fafb;
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            padding: 12px;
            white-space: pre-wrap;
            font-size: 13px;
            max-height: 300px;
            overflow-y: auto;
            font-family: 'Courier New', monospace;
        }
        
        .text-muted { color: #6b7280; }
        .text-center { text-align: center; }
        .text-danger { color: #ef476f; }
        .text-success { color: #06d6a0; }
        
        @media (max-width: 768px) {
            .filter-bar { flex-direction: column; align-items: stretch; }
            .filter-bar select, .filter-bar input { width: 100%; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .page-header h1 { font-size: 20px; }
            .table-container { padding: 8px; overflow-x: auto; }
            .table-container table { font-size: 12px; }
            .table-container th, .table-container td { padding: 6px 8px; }
            .view-tabs .tab-btn { padding: 4px 10px; font-size: 11px; }
        }
        @media (max-width: 480px) {
            .stats-grid { grid-template-columns: 1fr 1fr; }
        }
        
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: #f3f4f6; border-radius: 4px; }
        ::-webkit-scrollbar-thumb { background: #e5e7eb; border-radius: 4px; }
        ::-webkit-scrollbar-thumb:hover { background: #6b7280; }
        
        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
        }
        .status-badge.pending { background: #fef3c7; color: #92400e; }
        .status-badge.approved { background: #d1fae5; color: #065f46; }
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
                    <i class="fas fa-check-double"></i> Report Approval
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
                <div class="stat-icon"><i class="fas fa-clock"></i></div>
                <div class="stat-number"><?php echo $stats['pending_approval'] ?? 0; ?></div>
                <div class="stat-label">Pending Approval</div>
            </div>
            <div class="stat-card approved">
                <div class="stat-icon"><i class="fas fa-check-circle"></i></div>
                <div class="stat-number"><?php echo $stats['approved'] ?? 0; ?></div>
                <div class="stat-label">Approved</div>
            </div>
            <div class="stat-card stat_pending">
                <div class="stat-icon"><i class="fas fa-exclamation-triangle"></i></div>
                <div class="stat-number"><?php echo $stats['stat_pending'] ?? 0; ?></div>
                <div class="stat-label">STAT Pending</div>
            </div>
            <div class="stat-card total">
                <div class="stat-icon"><i class="fas fa-tasks"></i></div>
                <div class="stat-number"><?php echo $stats['total'] ?? 0; ?></div>
                <div class="stat-label">Total Reports</div>
            </div>
        </div>

        <!-- Filter Bar -->
        <div class="filter-bar">
            <div class="view-tabs">
                <button class="tab-btn <?php echo $filter_status === 'pending_approval' ? 'active' : ''; ?>" onclick="setView('pending_approval')">
                    Pending
                    <?php if (($stats['pending_approval'] ?? 0) > 0): ?>
                        <span class="badge-tab"><?php echo $stats['pending_approval']; ?></span>
                    <?php endif; ?>
                </button>
                <button class="tab-btn <?php echo $filter_status === 'approved' ? 'active' : ''; ?>" onclick="setView('approved')">
                    Approved
                    <span class="badge-tab approved-badge"><?php echo $stats['approved'] ?? 0; ?></span>
                </button>
                <button class="tab-btn <?php echo $filter_status === 'all' ? 'active' : ''; ?>" onclick="setView('all')">All</button>
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
            <div>
                <span class="filter-label">Date From</span>
                <input type="date" id="filterDateFrom" value="<?php echo $filter_date_from; ?>" onchange="applyFilters()">
            </div>
            <div>
                <span class="filter-label">To</span>
                <input type="date" id="filterDateTo" value="<?php echo $filter_date_to; ?>" onchange="applyFilters()">
            </div>
            <div style="flex:1;min-width:150px;">
                <input type="text" class="form-control" id="filterSearch" placeholder="Search orders..." 
                       value="<?php echo htmlspecialchars($search); ?>" onkeypress="if(event.key==='Enter') applyFilters()">
            </div>
            <button class="btn btn-primary" onclick="applyFilters()"><i class="fas fa-search"></i> Filter</button>
            <button class="btn" onclick="resetFilters()"><i class="fas fa-times"></i> Reset</button>
        </div>

        <!-- Reports Table - CRITICAL: Exactly 8 columns -->
        <div class="table-container">
            <table id="approvalTable" class="display" style="width:100%">
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
                                <i class="fas fa-check-circle" style="color:#06d6a0;"></i>
                                No reports pending approval
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($reports as $report): ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($report['order_number'] ?? ''); ?></strong>
                                </td>
                                <td>
                                    <?php echo htmlspecialchars($report['patient_name'] ?? 'Unknown'); ?>
                                    <br><span class="text-muted" style="font-size:11px;">
                                        MRN: <?php echo htmlspecialchars($report['mr_number'] ?? 'N/A'); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php echo htmlspecialchars($report['service_name'] ?? 'N/A'); ?>
                                    <?php if (!empty($report['body_part'])): ?>
                                        <br><span class="text-muted" style="font-size:11px;"><?php echo htmlspecialchars($report['body_part']); ?></span>
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
                                    <?php if (($report['status'] ?? '') === 'completed'): ?>
                                        <span class="status-badge pending">
                                            <i class="fas fa-clock"></i> Pending
                                        </span>
                                    <?php elseif (($report['status'] ?? '') === 'reviewed'): ?>
                                        <span class="status-badge approved">
                                            <i class="fas fa-check-circle"></i> Approved
                                        </span>
                                    <?php else: ?>
                                        <span class="badge badge-<?php echo str_replace('_', '', $report['status'] ?? ''); ?>">
                                            <?php echo ucfirst(str_replace('_', ' ', $report['status'] ?? 'Unknown')); ?>
                                        </span>
                                    <?php endif; ?>
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
                                        <?php if (($report['status'] ?? '') === 'completed'): ?>
                                            <button class="btn btn-sm btn-success" onclick="approveReport(<?php echo $report['id']; ?>)">
                                                <i class="fas fa-check"></i>
                                            </button>
                                            <button class="btn btn-sm btn-danger" onclick="rejectReport(<?php echo $report['id']; ?>)">
                                                <i class="fas fa-times"></i>
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

<!-- Approve Report Modal -->
<div class="modal-overlay" id="approveModal">
    <div class="modal" style="max-width:600px;">
        <div class="modal-header">
            <h2><i class="fas fa-check-circle"></i> Approve Report</h2>
            <button class="modal-close" onclick="closeModal('approveModal')">&times;</button>
        </div>
        <form method="POST" action="">
            <input type="hidden" name="action" value="approve">
            <input type="hidden" name="order_id" id="approveOrderId">
            <div class="modal-body">
                <div class="form-group">
                    <label>Order #</label>
                    <div id="approveOrderNumber" style="font-weight:600;font-size:16px;"></div>
                </div>
                <div class="form-group">
                    <label>Patient</label>
                    <div id="approvePatientName" style="font-weight:500;"></div>
                </div>
                <div class="form-group">
                    <label>Approval Notes (Optional)</label>
                    <textarea name="approval_notes" class="form-control" placeholder="Add any approval notes..." rows="3"></textarea>
                </div>
                <div class="alert alert-success" style="background:#d1fae5;padding:12px;border-radius:6px;color:#065f46;">
                    <i class="fas fa-info-circle"></i> Approving will mark as <strong>Reviewed</strong>.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" onclick="closeModal('approveModal')">Cancel</button>
                <button type="submit" class="btn btn-success"><i class="fas fa-check"></i> Approve</button>
            </div>
        </form>
    </div>
</div>

<!-- Reject Report Modal -->
<div class="modal-overlay" id="rejectModal">
    <div class="modal" style="max-width:600px;">
        <div class="modal-header">
            <h2><i class="fas fa-times-circle"></i> Reject Report</h2>
            <button class="modal-close" onclick="closeModal('rejectModal')">&times;</button>
        </div>
        <form method="POST" action="">
            <input type="hidden" name="action" value="reject">
            <input type="hidden" name="order_id" id="rejectOrderId">
            <div class="modal-body">
                <div class="form-group">
                    <label>Order #</label>
                    <div id="rejectOrderNumber" style="font-weight:600;font-size:16px;"></div>
                </div>
                <div class="form-group">
                    <label>Patient</label>
                    <div id="rejectPatientName" style="font-weight:500;"></div>
                </div>
                <div class="form-group">
                    <label>Rejection Reason <span class="required">*</span></label>
                    <textarea name="rejection_reason" class="form-control" placeholder="Please provide a reason..." rows="4" required></textarea>
                </div>
                <div class="alert alert-danger" style="background:#fee2e2;padding:12px;border-radius:6px;color:#991b1b;">
                    <i class="fas fa-exclamation-circle"></i> Rejecting returns to <strong>In Progress</strong>.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" onclick="closeModal('rejectModal')">Cancel</button>
                <button type="submit" class="btn btn-danger"><i class="fas fa-times"></i> Reject</button>
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
function setView(view) {
    let url = window.location.pathname + '?status=' + view;
    const modality = document.getElementById('filterModality').value;
    const priority = document.getElementById('filterPriority').value;
    const dateFrom = document.getElementById('filterDateFrom').value;
    const dateTo = document.getElementById('filterDateTo').value;
    const search = document.getElementById('filterSearch').value;
    if (modality) url += '&modality=' + encodeURIComponent(modality);
    if (priority) url += '&priority=' + encodeURIComponent(priority);
    if (dateFrom) url += '&date_from=' + encodeURIComponent(dateFrom);
    if (dateTo) url += '&date_to=' + encodeURIComponent(dateTo);
    if (search) url += '&search=' + encodeURIComponent(search);
    window.location.href = url;
}

function applyFilters() {
    const status = '<?php echo $filter_status; ?>';
    const modality = document.getElementById('filterModality').value;
    const priority = document.getElementById('filterPriority').value;
    const dateFrom = document.getElementById('filterDateFrom').value;
    const dateTo = document.getElementById('filterDateTo').value;
    const search = document.getElementById('filterSearch').value;
    
    let url = window.location.pathname + '?status=' + status;
    if (modality) url += '&modality=' + encodeURIComponent(modality);
    if (priority) url += '&priority=' + encodeURIComponent(priority);
    if (dateFrom) url += '&date_from=' + encodeURIComponent(dateFrom);
    if (dateTo) url += '&date_to=' + encodeURIComponent(dateTo);
    if (search) url += '&search=' + encodeURIComponent(search);
    window.location.href = url;
}

function resetFilters() {
    window.location.href = window.location.pathname + '?status=pending_approval';
}

// ============================================
// REPORT FUNCTIONS
// ============================================
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
                    html += `<div style="margin-bottom:12px;"><strong>Findings:</strong><br><div class="report-preview">${r.findings}</div></div>`;
                }
                
                if (r.impression) {
                    html += `<div style="margin-bottom:12px;"><strong>Impression:</strong><br><div class="report-preview">${r.impression}</div></div>`;
                }
                
                if (r.interpretation_report) {
                    html += `<div style="margin-bottom:12px;"><strong>Interpretation Report:</strong><br><div class="report-preview">${r.interpretation_report}</div></div>`;
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

function approveReport(orderId) {
    document.getElementById('approveOrderId').value = orderId;
    
    fetch('?ajax=get_report&id=' + orderId)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                document.getElementById('approveOrderNumber').textContent = data.report.order_number;
                document.getElementById('approvePatientName').textContent = data.report.patient_name || 'Unknown';
            }
        });
    
    openModal('approveModal');
}

function rejectReport(orderId) {
    document.getElementById('rejectOrderId').value = orderId;
    
    fetch('?ajax=get_report&id=' + orderId)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                document.getElementById('rejectOrderNumber').textContent = data.report.order_number;
                document.getElementById('rejectPatientName').textContent = data.report.patient_name || 'Unknown';
            }
        });
    
    openModal('rejectModal');
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
});
</script>

<!-- DataTables - CRITICAL: Proper initialization with exact column count -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script>
$(document).ready(function() {
    // CRITICAL: Count columns to verify
    var $table = $('#approvalTable');
    var headerCols = $table.find('thead tr th').length;
    console.log('Header columns: ' + headerCols);
    
    // CRITICAL: Destroy any existing DataTable instance
    if ($.fn.DataTable.isDataTable('#approvalTable')) {
        $('#approvalTable').DataTable().destroy();
        console.log('Destroyed existing DataTable');
    }
    
    // CRITICAL: Verify each row has correct column count
    var allRowsValid = true;
    $table.find('tbody tr').each(function() {
        var cols = $(this).find('td').length;
        if (cols !== headerCols) {
            console.warn('Row has ' + cols + ' columns, expected ' + headerCols);
            allRowsValid = false;
        }
    });
    
    // CRITICAL: Only initialize if column count is correct (8)
    if (headerCols === 8) {
        $('#approvalTable').DataTable({
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
        console.log('DataTable initialized successfully');
    } else {
        console.error('CRITICAL ERROR: Expected 8 columns, got ' + headerCols);
        // Fallback: Show error in console but still try to initialize
        try {
            $('#approvalTable').DataTable({
                responsive: true,
                pageLength: 25,
                order: [[6, 'desc']],
                columnDefs: [
                    { orderable: false, targets: [7] }
                ],
                language: {
                    emptyTable: "No reports found"
                }
            });
        } catch(e) {
            console.error('DataTable initialization failed:', e);
        }
    }
});
</script>

</body>
</html>