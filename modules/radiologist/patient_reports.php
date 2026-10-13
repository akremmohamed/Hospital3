<?php
/**
 * patient_reports.php
 * 
 * HMS ERP - Patient Radiology Reports
 * FIXED: Search handling and parameter errors resolved
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
$has_radiology_access = in_array($user_type, ['radiologist', 'radiology_technician', 'super_admin', 'admin', 'doctor']);

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
$patient_id = isset($_GET['patient_id']) ? (int)$_GET['patient_id'] : 0;
$report_id = isset($_GET['report_id']) ? (int)$_GET['report_id'] : 0;
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$filter_modality = $_GET['modality'] ?? '';
$filter_status = $_GET['status'] ?? '';
$filter_date_from = $_GET['date_from'] ?? '';
$filter_date_to = $_GET['date_to'] ?? '';

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
    return $icons[$modality] ?? 'fa-image';
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

function getImageUrl($path) {
    if (empty($path)) return '';
    if (strpos($path, 'http') === 0 || strpos($path, 'data:') === 0) {
        return $path;
    }
    if (strpos($path, '/') === 0) {
        return $path;
    }
    return '/uploads/radiology/' . ltrim($path, '/');
}

function getFirstImage($path) {
    if (empty($path)) return null;
    if (strpos($path, '[') === 0 || strpos($path, '{') === 0) {
        $arr = json_decode($path, true);
        if (is_array($arr) && count($arr) > 0) {
            return $arr[0];
        }
        return null;
    }
    if (strpos($path, ',') !== false) {
        $parts = array_map('trim', explode(',', $path));
        return $parts[0] ?? null;
    }
    return $path;
}

function getImageCount($path) {
    if (empty($path)) return 0;
    if (strpos($path, '[') === 0 || strpos($path, '{') === 0) {
        $arr = json_decode($path, true);
        return is_array($arr) ? count($arr) : 0;
    }
    if (strpos($path, ',') !== false) {
        return count(array_filter(explode(',', $path)));
    }
    return 1;
}

function calculateAge($birthDate) {
    if (empty($birthDate)) return 'N/A';
    $today = new DateTime();
    $diff = $today->diff(new DateTime($birthDate));
    return $diff->y;
}

// ============================================
// 4. DATA FETCHING - FIXED
// ============================================

$patients = [];
$selected_patient = null;
$reports = [];
$report_details = null;

// Get patient if specified
if ($patient_id > 0) {
    $sql = "SELECT 
                id, mr_number, first_name, middle_name, last_name,
                date_of_birth, gender, phone, email, address,
                emergency_contact_name, emergency_contact_phone,
                emergency_contact_relationship
            FROM patients 
            WHERE id = :id AND deleted_at IS NULL";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':id' => $patient_id]);
    $selected_patient = $stmt->fetch(PDO::FETCH_ASSOC);
}

// If report_id is specified, get report details
if ($report_id > 0) {
    $sql = "SELECT 
                ro.id, ro.order_number, ro.service_name, ro.service_code,
                ro.modality, ro.body_part, ro.priority, ro.payment_status,
                ro.status, ro.clinical_history, ro.findings, ro.impression,
                ro.interpretation_report, ro.image_attachment_path,
                ro.created_at, ro.updated_at, ro.performed_at, ro.reviewed_at,
                ro.scheduled_date, ro.physician_review_notes,
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
    $stmt->execute([':id' => $report_id]);
    $report_details = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // If report found but no patient selected, load patient
    if ($report_details && !$selected_patient) {
        $patient_id = (int)($report_details['patient_id'] ?? 0);
        if ($patient_id > 0) {
            $sql = "SELECT 
                        id, mr_number, first_name, middle_name, last_name,
                        date_of_birth, gender, phone, email, address,
                        emergency_contact_name, emergency_contact_phone,
                        emergency_contact_relationship
                    FROM patients 
                    WHERE id = :id AND deleted_at IS NULL";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':id' => $patient_id]);
            $selected_patient = $stmt->fetch(PDO::FETCH_ASSOC);
        }
    }
}

// Build report query for selected patient
if ($selected_patient) {
    $branch_filter = "";
    $params = [
        ':patient_id' => $patient_id
    ];
    
    if (!$is_global_admin && $branch_id > 0) {
        $branch_filter = " AND ro.branch_id = :branch_id";
        $params[':branch_id'] = $branch_id;
    }
    
    if (!empty($filter_modality)) {
        $branch_filter .= " AND ro.modality = :modality_val";
        $params[':modality_val'] = $filter_modality;
    }
    if (!empty($filter_status)) {
        $branch_filter .= " AND ro.status = :status_val";
        $params[':status_val'] = $filter_status;
    }
    if (!empty($filter_date_from) && !empty($filter_date_to)) {
        $branch_filter .= " AND DATE(ro.created_at) BETWEEN :date_from AND :date_to";
        $params[':date_from'] = $filter_date_from;
        $params[':date_to'] = $filter_date_to;
    }
    
    $sql = "SELECT 
                ro.id, ro.order_number, ro.service_name, ro.service_code,
                ro.modality, ro.body_part, ro.priority, ro.payment_status,
                ro.status, ro.clinical_history, ro.findings, ro.impression,
                ro.interpretation_report, ro.image_attachment_path,
                ro.created_at, ro.updated_at, ro.performed_at, ro.reviewed_at,
                ro.scheduled_date, ro.physician_review_notes,
                CONCAT(u.first_name, ' ', u.last_name) as doctor_name
            FROM radiology_orders ro
            LEFT JOIN users u ON ro.doctor_id = u.id
            WHERE ro.patient_id = :patient_id 
            AND ro.deleted_at IS NULL" . $branch_filter . "
            ORDER BY ro.created_at DESC
            LIMIT 100";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $reports = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} elseif (!empty($search)) {
    // FIXED: Search patients with proper parameter handling
    $search_param = "%{$search}%";
    $sql = "SELECT 
                id, mr_number, first_name, middle_name, last_name,
                date_of_birth, gender, phone
            FROM patients 
            WHERE (first_name LIKE :search OR last_name LIKE :search OR mr_number LIKE :search)
            AND is_active = 1
            AND deleted_at IS NULL
            ORDER BY first_name, last_name
            LIMIT 50";
    $stmt = $pdo->prepare($sql);
    // FIXED: Use a single parameter for all LIKE conditions
    $stmt->execute([':search' => $search_param]);
    $patients = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} else {
    // Show recent patients
    $branch_filter = "";
    $params = [];
    if (!$is_global_admin && $branch_id > 0) {
        $branch_filter = " AND ro.branch_id = :branch_id";
        $params[':branch_id'] = $branch_id;
    }
    
    $sql = "SELECT DISTINCT 
                p.id, p.mr_number, p.first_name, p.middle_name, p.last_name,
                p.date_of_birth, p.gender, p.phone
            FROM patients p
            INNER JOIN radiology_orders ro ON ro.patient_id = p.id
            WHERE p.is_active = 1
            AND p.deleted_at IS NULL
            AND ro.deleted_at IS NULL" . $branch_filter . "
            ORDER BY p.created_at DESC
            LIMIT 30";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $patients = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get modality statistics for patient if selected
$modality_stats = [];
if ($selected_patient) {
    $sql = "SELECT 
                modality, COUNT(*) as count,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed
            FROM radiology_orders
            WHERE patient_id = :patient_id AND deleted_at IS NULL
            GROUP BY modality
            ORDER BY count DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':patient_id' => $patient_id]);
    $modality_stats = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Patient Reports | Radiology | HMS ERP</title>
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
        .btn-sm { padding: 4px 10px; font-size: 12px; }
        .btn-outline-primary { background: transparent; color: #7209b7; border-color: #7209b7; }
        .btn-outline-primary:hover { background: #7209b7; color: white; }
        .btn-danger { background: #ef476f; color: white; border-color: #ef476f; }
        .btn-danger:hover { background: #d63e5e; border-color: #d63e5e; }
        
        .search-bar {
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
        .search-bar input, .search-bar select {
            padding: 6px 12px;
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            font-size: 13px;
            background: white;
            flex: 1;
            min-width: 150px;
        }
        .search-bar .search-label {
            font-size: 12px;
            color: #6b7280;
            font-weight: 500;
        }
        .search-bar .search-hint {
            font-size: 11px;
            color: #6b7280;
        }
        
        .patient-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08);
            margin-bottom: 20px;
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            align-items: flex-start;
            border-left: 4px solid #7209b7;
        }
        .patient-card .patient-avatar {
            width: 64px;
            height: 64px;
            border-radius: 50%;
            background: linear-gradient(135deg, #7209b7, #4361ee);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
            font-weight: 700;
            flex-shrink: 0;
        }
        .patient-card .patient-info {
            flex: 1;
            min-width: 200px;
        }
        .patient-card .patient-info .name {
            font-size: 20px;
            font-weight: 700;
        }
        .patient-card .patient-info .details {
            display: flex;
            flex-wrap: wrap;
            gap: 16px;
            font-size: 13px;
            color: #6b7280;
            margin-top: 4px;
        }
        .patient-card .patient-info .details span { display: flex; align-items: center; gap: 4px; }
        .patient-card .patient-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        
        .report-card {
            background: white;
            border-radius: 12px;
            padding: 16px 20px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08);
            margin-bottom: 12px;
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            align-items: center;
            transition: all 0.2s;
            cursor: pointer;
            border-left: 3px solid #7209b7;
        }
        .report-card:hover {
            transform: translateX(4px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.12);
        }
        .report-card .report-thumb {
            width: 60px;
            height: 60px;
            border-radius: 8px;
            background: #0a0a0f;
            overflow: hidden;
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .report-card .report-thumb img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .report-card .report-thumb .no-thumb {
            color: #3a3a5e;
            font-size: 24px;
        }
        .report-card .report-info {
            flex: 1;
            min-width: 150px;
        }
        .report-card .report-info .order-number {
            font-weight: 600;
            font-size: 14px;
        }
        .report-card .report-info .meta {
            font-size: 12px;
            color: #6b7280;
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }
        .report-card .report-actions {
            display: flex;
            gap: 4px;
            flex-shrink: 0;
        }
        .report-card .report-actions .btn { padding: 2px 8px; font-size: 11px; }
        
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
        
        .badge-stat { background: #ef476f; color: white; font-size: 9px; padding: 1px 6px; border-radius: 10px; }
        .badge-urgent { background: #f9c74f; color: #92400e; }
        .badge-routine { background: #dbeafe; color: #1e40af; }
        
        .patient-list-item {
            background: white;
            border-radius: 8px;
            padding: 12px 16px;
            margin-bottom: 8px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08);
            cursor: pointer;
            transition: all 0.2s;
        }
        .patient-list-item:hover {
            transform: translateX(4px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.12);
        }
        .patient-list-item .patient-name {
            font-weight: 600;
        }
        .patient-list-item .patient-meta {
            font-size: 12px;
            color: #6b7280;
        }
        .patient-list-item .patient-arrow {
            color: #6b7280;
        }
        
        .report-detail {
            background: white;
            border-radius: 12px;
            padding: 24px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08);
        }
        .report-detail .report-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            flex-wrap: wrap;
            gap: 12px;
            padding-bottom: 16px;
            border-bottom: 1px solid #e5e7eb;
            margin-bottom: 16px;
        }
        .report-detail .report-header .title {
            font-size: 18px;
            font-weight: 700;
        }
        .report-detail .report-header .subtitle {
            font-size: 13px;
            color: #6b7280;
        }
        .report-detail .section {
            margin-bottom: 20px;
        }
        .report-detail .section .section-title {
            font-size: 14px;
            font-weight: 600;
            color: #374151;
            margin-bottom: 6px;
        }
        .report-detail .section .section-content {
            background: #f9fafb;
            padding: 12px 16px;
            border-radius: 6px;
            border: 1px solid #e5e7eb;
            white-space: pre-wrap;
            font-size: 14px;
            line-height: 1.6;
        }
        .report-detail .section .section-content.empty {
            color: #6b7280;
            font-style: italic;
        }
        .report-detail .meta-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 8px 16px;
            background: #f9fafb;
            padding: 12px 16px;
            border-radius: 6px;
            border: 1px solid #e5e7eb;
        }
        .report-detail .meta-grid .meta-item .label {
            font-size: 11px;
            color: #6b7280;
            text-transform: uppercase;
        }
        .report-detail .meta-grid .meta-item .value {
            font-size: 14px;
            font-weight: 500;
        }
        
        .no-data {
            text-align: center;
            padding: 40px 20px;
            color: #6b7280;
        }
        .no-data i {
            font-size: 48px;
            display: block;
            margin-bottom: 12px;
            color: #d1d5db;
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
            .search-bar { flex-direction: column; align-items: stretch; }
            .search-bar input, .search-bar select { width: 100%; }
            .patient-card { flex-direction: column; align-items: stretch; }
            .patient-card .patient-actions { justify-content: flex-start; }
            .report-card { flex-direction: column; align-items: stretch; }
            .report-card .report-actions { justify-content: flex-start; }
            .page-header h1 { font-size: 20px; }
            .report-detail .meta-grid { grid-template-columns: 1fr 1fr; }
        }
        @media (max-width: 480px) {
            .report-detail .meta-grid { grid-template-columns: 1fr; }
        }
        
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: #f3f4f6; border-radius: 4px; }
        ::-webkit-scrollbar-thumb { background: #e5e7eb; border-radius: 4px; }
        ::-webkit-scrollbar-thumb:hover { background: #6b7280; }
        
        .report-count {
            font-size: 13px;
            color: #6b7280;
            padding: 8px 0;
        }
        
        .search-result-info {
            font-size: 13px;
            color: #6b7280;
            padding: 4px 0 12px 0;
        }
        
        /* Loading spinner */
        .loading-spinner {
            display: inline-block;
            width: 16px;
            height: 16px;
            border: 2px solid #e5e7eb;
            border-top: 2px solid #7209b7;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
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
                    <i class="fas fa-file-medical-alt"></i> Patient Reports
                    <span class="subtitle">Radiology Reports by Patient</span>
                </h1>
            </div>
            <div>
                <button class="btn" onclick="window.location.reload();">
                    <i class="fas fa-sync-alt"></i> Refresh
                </button>
                <?php if ($selected_patient): ?>
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['export' => 'csv'])); ?>" class="btn btn-success">
                        <i class="fas fa-file-csv"></i> Export
                    </a>
                <?php endif; ?>
                <a href="radiology_worklist.php" class="btn btn-primary">
                    <i class="fas fa-arrow-left"></i> Back
                </a>
            </div>
        </div>

        <!-- Search Bar - FIXED: No auto-submit -->
        <div class="search-bar">
            <span class="search-label"><i class="fas fa-search"></i> Find Patient:</span>
            <input type="text" id="searchInput" 
                   placeholder="Search by name or MRN..." 
                   value="<?php echo htmlspecialchars($search); ?>"
                   onkeypress="if(event.key==='Enter') { event.preventDefault(); searchPatient(); }">
            <button class="btn btn-primary" onclick="searchPatient()">
                <i class="fas fa-search"></i> Search
            </button>
            <?php if (!empty($search) && empty($selected_patient)): ?>
                <button class="btn btn-danger" onclick="clearSearch()">
                    <i class="fas fa-times"></i> Clear
                </button>
            <?php endif; ?>
            <?php if ($selected_patient): ?>
                <button class="btn" onclick="clearPatient()">
                    <i class="fas fa-times"></i> Clear Patient
                </button>
            <?php endif; ?>
            <span class="search-hint"><i class="fas fa-info-circle"></i> Press Enter or click Search</span>
        </div>

        <?php if ($selected_patient): ?>
            <!-- Patient Info Card -->
            <div class="patient-card">
                <div class="patient-avatar">
                    <?php 
                    $initials = strtoupper(substr($selected_patient['first_name'] ?? '', 0, 1) . substr($selected_patient['last_name'] ?? '', 0, 1));
                    echo htmlspecialchars($initials ?: 'P');
                    ?>
                </div>
                <div class="patient-info">
                    <div class="name">
                        <?php echo htmlspecialchars($selected_patient['first_name'] . ' ' . ($selected_patient['middle_name'] ?? '') . ' ' . $selected_patient['last_name']); ?>
                    </div>
                    <div class="details">
                        <span><i class="fas fa-id-card"></i> MRN: <?php echo htmlspecialchars($selected_patient['mr_number'] ?? 'N/A'); ?></span>
                        <span><i class="fas fa-calendar-alt"></i> DOB: <?php echo htmlspecialchars($selected_patient['date_of_birth'] ?? 'N/A'); ?></span>
                        <span><i class="fas fa-venus-mars"></i> <?php echo ucfirst($selected_patient['gender'] ?? 'N/A'); ?></span>
                        <span><i class="fas fa-phone"></i> <?php echo htmlspecialchars($selected_patient['phone'] ?? 'N/A'); ?></span>
                        <span><i class="fas fa-file-medical"></i> <?php echo count($reports); ?> Reports</span>
                    </div>
                </div>
                <div class="patient-actions">
                    <a href="pacs_viewer.php?patient_id=<?php echo $patient_id; ?>" class="btn btn-sm btn-primary">
                        <i class="fas fa-eye"></i> View Images
                    </a>
                    <a href="study_comparison.php?patient_id=<?php echo $patient_id; ?>" class="btn btn-sm btn-outline-primary">
                        <i class="fas fa-not-equal"></i> Compare
                    </a>
                </div>
            </div>

            <!-- Filter Bar -->
            <div class="search-bar" style="padding:10px 16px;">
                <span class="search-label">Filter:</span>
                <select id="filterModality" onchange="applyFilters()">
                    <option value="">All Modalities</option>
                    <option value="xray" <?php echo $filter_modality === 'xray' ? 'selected' : ''; ?>>X-Ray</option>
                    <option value="ct" <?php echo $filter_modality === 'ct' ? 'selected' : ''; ?>>CT</option>
                    <option value="mri" <?php echo $filter_modality === 'mri' ? 'selected' : ''; ?>>MRI</option>
                    <option value="ultrasound" <?php echo $filter_modality === 'ultrasound' ? 'selected' : ''; ?>>Ultrasound</option>
                    <option value="mammography" <?php echo $filter_modality === 'mammography' ? 'selected' : ''; ?>>Mammography</option>
                    <option value="fluoroscopy" <?php echo $filter_modality === 'fluoroscopy' ? 'selected' : ''; ?>>Fluoroscopy</option>
                    <option value="angiography" <?php echo $filter_modality === 'angiography' ? 'selected' : ''; ?>>Angiography</option>
                </select>
                <select id="filterStatus" onchange="applyFilters()">
                    <option value="">All Status</option>
                    <option value="scheduled" <?php echo $filter_status === 'scheduled' ? 'selected' : ''; ?>>Scheduled</option>
                    <option value="in_progress" <?php echo $filter_status === 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                    <option value="completed" <?php echo $filter_status === 'completed' ? 'selected' : ''; ?>>Completed</option>
                    <option value="reviewed" <?php echo $filter_status === 'reviewed' ? 'selected' : ''; ?>>Reviewed</option>
                </select>
                <input type="date" id="filterDateFrom" value="<?php echo $filter_date_from; ?>" onchange="applyFilters()" placeholder="From">
                <input type="date" id="filterDateTo" value="<?php echo $filter_date_to; ?>" onchange="applyFilters()" placeholder="To">
                <button class="btn btn-sm btn-primary" onclick="applyFilters()"><i class="fas fa-filter"></i></button>
                <button class="btn btn-sm" onclick="resetFilters()"><i class="fas fa-times"></i></button>
            </div>

            <!-- Reports List -->
            <?php if (empty($reports)): ?>
                <div class="no-data">
                    <i class="fas fa-file-medical-alt"></i>
                    <h3>No Reports Found</h3>
                    <p>This patient has no radiology reports yet.</p>
                </div>
            <?php else: ?>
                <div class="report-count">
                    <i class="fas fa-list"></i> Showing <?php echo count($reports); ?> report(s)
                </div>
                
                <?php foreach ($reports as $report): 
                    $first_image = getFirstImage($report['image_attachment_path'] ?? '');
                    $image_count = getImageCount($report['image_attachment_path'] ?? '');
                ?>
                <div class="report-card" onclick="viewReport(<?php echo $report['id']; ?>)">
                    <div class="report-thumb">
                        <?php if ($first_image): ?>
                            <img src="<?php echo htmlspecialchars(getImageUrl($first_image)); ?>" 
                                 alt="<?php echo htmlspecialchars($report['order_number']); ?>"
                                 loading="lazy"
                                 onerror="this.style.display='none';this.parentElement.innerHTML='<span class=\'no-thumb\'><i class=\'fas fa-image\'></i></span>';">
                        <?php else: ?>
                            <span class="no-thumb"><i class="fas fa-image"></i></span>
                        <?php endif; ?>
                    </div>
                    <div class="report-info">
                        <div class="order-number">
                            <?php echo htmlspecialchars($report['order_number']); ?>
                            <span class="modality-badge modality-<?php echo $report['modality'] ?? 'xray'; ?>">
                                <?php echo strtoupper($report['modality'] ?? 'X-Ray'); ?>
                            </span>
                            <span class="badge badge-<?php echo getStatusBadge($report['status'] ?? ''); ?>">
                                <?php echo ucfirst(str_replace('_', ' ', $report['status'] ?? 'N/A')); ?>
                            </span>
                            <?php if (($report['priority'] ?? '') === 'stat'): ?>
                                <span class="badge-stat">STAT</span>
                            <?php elseif (($report['priority'] ?? '') === 'urgent'): ?>
                                <span class="badge-urgent">Urgent</span>
                            <?php endif; ?>
                        </div>
                        <div class="meta">
                            <span><i class="fas fa-microscope"></i> <?php echo htmlspecialchars($report['service_name'] ?? 'N/A'); ?></span>
                            <span><i class="fas fa-bone"></i> <?php echo htmlspecialchars($report['body_part'] ?? 'N/A'); ?></span>
                            <span><i class="fas fa-user-md"></i> <?php echo htmlspecialchars($report['doctor_name'] ?? 'N/A'); ?></span>
                            <span><i class="fas fa-calendar"></i> <?php echo date('M d, Y H:i', strtotime($report['created_at'] ?? 'now')); ?></span>
                            <?php if ($image_count > 0): ?>
                                <span><i class="fas fa-image"></i> <?php echo $image_count; ?> image(s)</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="report-actions">
                        <button class="btn btn-sm btn-primary" onclick="event.stopPropagation();viewReport(<?php echo $report['id']; ?>)">
                            <i class="fas fa-eye"></i>
                        </button>
                        <?php if ($first_image): ?>
                            <a href="pacs_viewer.php?order_id=<?php echo $report['id']; ?>" class="btn btn-sm btn-outline-primary" onclick="event.stopPropagation();">
                                <i class="fas fa-image"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>

        <?php elseif ($report_details): ?>
            <!-- Report Detail View -->
            <div class="report-detail" id="reportDetail">
                <div class="report-header">
                    <div>
                        <div class="title"><?php echo htmlspecialchars($report_details['order_number']); ?></div>
                        <div class="subtitle">
                            <?php echo htmlspecialchars($report_details['patient_name'] ?? 'Unknown Patient'); ?>
                            <span style="margin:0 8px;">|</span>
                            MRN: <?php echo htmlspecialchars($report_details['mr_number'] ?? 'N/A'); ?>
                            <span style="margin:0 8px;">|</span>
                            <?php echo date('M d, Y', strtotime($report_details['created_at'] ?? 'now')); ?>
                        </div>
                    </div>
                    <div>
                        <button class="btn btn-sm btn-success" onclick="window.print()">
                            <i class="fas fa-print"></i> Print
                        </button>
                        <button class="btn btn-sm btn-primary" onclick="window.location.href='patient_reports.php?patient_id=<?php echo $patient_id; ?>'">
                            <i class="fas fa-arrow-left"></i> Back
                        </button>
                    </div>
                </div>

                <div class="meta-grid">
                    <div class="meta-item">
                        <div class="label">Modality</div>
                        <div class="value">
                            <span class="modality-badge modality-<?php echo $report_details['modality'] ?? 'xray'; ?>">
                                <?php echo strtoupper($report_details['modality'] ?? 'X-Ray'); ?>
                            </span>
                        </div>
                    </div>
                    <div class="meta-item">
                        <div class="label">Status</div>
                        <div class="value">
                            <span class="badge badge-<?php echo getStatusBadge($report_details['status'] ?? ''); ?>">
                                <?php echo ucfirst(str_replace('_', ' ', $report_details['status'] ?? 'N/A')); ?>
                            </span>
                        </div>
                    </div>
                    <div class="meta-item">
                        <div class="label">Priority</div>
                        <div class="value">
                            <?php echo ucfirst($report_details['priority'] ?? 'N/A'); ?>
                        </div>
                    </div>
                    <div class="meta-item">
                        <div class="label">Body Part</div>
                        <div class="value"><?php echo htmlspecialchars($report_details['body_part'] ?? 'N/A'); ?></div>
                    </div>
                    <div class="meta-item">
                        <div class="label">Service</div>
                        <div class="value"><?php echo htmlspecialchars($report_details['service_name'] ?? 'N/A'); ?></div>
                    </div>
                    <div class="meta-item">
                        <div class="label">Doctor</div>
                        <div class="value"><?php echo htmlspecialchars($report_details['doctor_name'] ?? 'N/A'); ?></div>
                    </div>
                    <div class="meta-item">
                        <div class="label">Created</div>
                        <div class="value"><?php echo date('M d, Y H:i', strtotime($report_details['created_at'] ?? 'now')); ?></div>
                    </div>
                    <div class="meta-item">
                        <div class="label">Reviewed</div>
                        <div class="value"><?php echo $report_details['reviewed_at'] ? date('M d, Y H:i', strtotime($report_details['reviewed_at'])) : 'Not reviewed'; ?></div>
                    </div>
                </div>

                <?php if (!empty($report_details['clinical_history'])): ?>
                <div class="section">
                    <div class="section-title"><i class="fas fa-notes-medical"></i> Clinical History</div>
                    <div class="section-content"><?php echo nl2br(htmlspecialchars($report_details['clinical_history'])); ?></div>
                </div>
                <?php endif; ?>

                <?php if (!empty($report_details['findings'])): ?>
                <div class="section">
                    <div class="section-title"><i class="fas fa-search"></i> Findings</div>
                    <div class="section-content"><?php echo nl2br(htmlspecialchars($report_details['findings'])); ?></div>
                </div>
                <?php endif; ?>

                <?php if (!empty($report_details['impression'])): ?>
                <div class="section">
                    <div class="section-title"><i class="fas fa-lightbulb"></i> Impression</div>
                    <div class="section-content"><?php echo nl2br(htmlspecialchars($report_details['impression'])); ?></div>
                </div>
                <?php endif; ?>

                <?php if (!empty($report_details['interpretation_report'])): ?>
                <div class="section">
                    <div class="section-title"><i class="fas fa-file-medical-alt"></i> Interpretation Report</div>
                    <div class="section-content"><?php echo nl2br(htmlspecialchars($report_details['interpretation_report'])); ?></div>
                </div>
                <?php endif; ?>

                <?php if (!empty($report_details['physician_review_notes'])): ?>
                <div class="section">
                    <div class="section-title"><i class="fas fa-user-md"></i> Review Notes</div>
                    <div class="section-content"><?php echo nl2br(htmlspecialchars($report_details['physician_review_notes'])); ?></div>
                </div>
                <?php endif; ?>

                <?php if (!empty($report_details['image_attachment_path'])): ?>
                <div class="section">
                    <div class="section-title"><i class="fas fa-image"></i> Images</div>
                    <div style="display:flex;gap:8px;flex-wrap:wrap;">
                        <?php 
                        $images = [];
                        $path = $report_details['image_attachment_path'];
                        if (strpos($path, '[') === 0 || strpos($path, '{') === 0) {
                            $images = json_decode($path, true);
                            if (!is_array($images)) $images = [];
                        } else if (strpos($path, ',') !== false) {
                            $images = array_map('trim', explode(',', $path));
                        } else {
                            $images = [$path];
                        }
                        foreach ($images as $img):
                            if (empty($img)) continue;
                        ?>
                            <a href="<?php echo htmlspecialchars(getImageUrl($img)); ?>" target="_blank" class="btn btn-sm btn-outline-primary">
                                <i class="fas fa-image"></i> View Image
                            </a>
                        <?php endforeach; ?>
                        <a href="pacs_viewer.php?order_id=<?php echo $report_details['id']; ?>" class="btn btn-sm btn-primary">
                            <i class="fas fa-eye"></i> Open in PACS Viewer
                        </a>
                    </div>
                </div>
                <?php endif; ?>
            </div>

        <?php elseif (!empty($patients)): ?>
            <!-- Patient List -->
            <div style="background:white;border-radius:12px;padding:16px 20px;box-shadow:0 1px 3px rgba(0,0,0,0.08);">
                <h3 style="font-size:16px;font-weight:600;margin-bottom:12px;">
                    <i class="fas fa-users"></i> Patients with Radiology Reports
                    <span class="text-muted" style="font-size:13px;font-weight:400;margin-left:8px;"><?php echo count($patients); ?> found</span>
                </h3>
                <?php if (!empty($search)): ?>
                    <div class="search-result-info">
                        <i class="fas fa-search"></i> Results for "<strong><?php echo htmlspecialchars($search); ?></strong>"
                    </div>
                <?php endif; ?>
                <?php foreach ($patients as $patient): ?>
                    <div class="patient-list-item" onclick="window.location.href='?patient_id=<?php echo $patient['id']; ?>'">
                        <div>
                            <div class="patient-name">
                                <?php echo htmlspecialchars($patient['first_name'] . ' ' . ($patient['middle_name'] ?? '') . ' ' . $patient['last_name']); ?>
                            </div>
                            <div class="patient-meta">
                                MRN: <?php echo htmlspecialchars($patient['mr_number'] ?? 'N/A'); ?>
                                <?php if (!empty($patient['date_of_birth'])): ?>
                                    • Age: <?php echo calculateAge($patient['date_of_birth']); ?>
                                <?php endif; ?>
                                <?php if (!empty($patient['gender'])): ?>
                                    • <?php echo ucfirst($patient['gender']); ?>
                                <?php endif; ?>
                                <?php if (!empty($patient['phone'])): ?>
                                    • <?php echo htmlspecialchars($patient['phone']); ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="patient-arrow">
                            <i class="fas fa-chevron-right"></i>
                        </div>
                    </div>
                <?php endforeach; ?>
                <?php if (count($patients) >= 50): ?>
                    <div class="text-muted" style="font-size:12px;padding:8px 0;text-align:center;">
                        <i class="fas fa-info-circle"></i> Showing first 50 results. Please refine your search.
                    </div>
                <?php endif; ?>
            </div>

        <?php else: ?>
            <!-- No Results -->
            <div class="no-data">
                <?php if (!empty($search)): ?>
                    <i class="fas fa-search"></i>
                    <h3>No Patients Found</h3>
                    <p>No patients match "<strong><?php echo htmlspecialchars($search); ?></strong>".</p>
                    <p style="font-size:13px;margin-top:8px;">Try searching by first name, last name, or MRN.</p>
                    <button class="btn btn-primary mt-2" onclick="clearSearch()">
                        <i class="fas fa-times"></i> Clear Search
                    </button>
                <?php else: ?>
                    <i class="fas fa-search"></i>
                    <h3>Search for a Patient</h3>
                    <p>Enter a patient name or MRN above to view their radiology reports.</p>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
// ============================================
// SEARCH FUNCTIONS
// ============================================
function searchPatient() {
    const search = document.getElementById('searchInput').value.trim();
    if (search.length >= 2) {
        window.location.href = '?' + new URLSearchParams({ search: search }).toString();
    } else if (search.length === 0) {
        clearSearch();
    } else {
        // Show hint for minimum characters
        const searchInput = document.getElementById('searchInput');
        searchInput.style.borderColor = '#ef476f';
        searchInput.placeholder = 'Please enter at least 2 characters';
        setTimeout(() => {
            searchInput.style.borderColor = '';
            searchInput.placeholder = 'Search by name or MRN...';
        }, 2000);
    }
}

function clearSearch() {
    window.location.href = window.location.pathname;
}

function clearPatient() {
    window.location.href = window.location.pathname;
}

// ============================================
// FILTER FUNCTIONS
// ============================================
function applyFilters() {
    const patientId = <?php echo $patient_id; ?>;
    if (!patientId) return;
    
    const modality = document.getElementById('filterModality').value;
    const status = document.getElementById('filterStatus').value;
    const dateFrom = document.getElementById('filterDateFrom').value;
    const dateTo = document.getElementById('filterDateTo').value;
    
    let url = '?patient_id=' + patientId;
    if (modality) url += '&modality=' + encodeURIComponent(modality);
    if (status) url += '&status=' + encodeURIComponent(status);
    if (dateFrom) url += '&date_from=' + encodeURIComponent(dateFrom);
    if (dateTo) url += '&date_to=' + encodeURIComponent(dateTo);
    window.location.href = url;
}

function resetFilters() {
    const patientId = <?php echo $patient_id; ?>;
    if (patientId) {
        window.location.href = '?patient_id=' + patientId;
    }
}

// ============================================
// REPORT FUNCTIONS
// ============================================
function viewReport(reportId) {
    const patientId = <?php echo $patient_id; ?>;
    window.location.href = '?patient_id=' + patientId + '&report_id=' + reportId;
}

// ============================================
// KEYBOARD SHORTCUTS
// ============================================
document.addEventListener('keydown', function(e) {
    if (e.key === 'Enter') {
        const searchInput = document.getElementById('searchInput');
        if (searchInput && document.activeElement === searchInput) {
            e.preventDefault();
            searchPatient();
        }
    }
    if (e.key === 'Escape') {
        if (<?php echo $report_details ? 'true' : 'false'; ?>) {
            window.location.href = '?patient_id=<?php echo $patient_id; ?>';
        }
    }
    // Ctrl+F or Cmd+F - focus search
    if ((e.ctrlKey || e.metaKey) && e.key === 'f') {
        e.preventDefault();
        document.getElementById('searchInput').focus();
        document.getElementById('searchInput').select();
    }
});

// ============================================
// PRINT STYLES
// ============================================
const printStyles = document.createElement('style');
printStyles.textContent = `
    @media print {
        #wrapper { display: block; }
        #page-wrapper { margin-left: 0 !important; padding: 10px !important; padding-top: 10px !important; }
        .page-header .btn, .search-bar, .patient-card .patient-actions, .report-card .report-actions { display: none !important; }
        .report-detail { box-shadow: none !important; padding: 0 !important; }
        body { background: white !important; }
        .report-card { box-shadow: none !important; border: 1px solid #e5e7eb !important; }
    }
`;
document.head.appendChild(printStyles);

// ============================================
// INITIALIZATION
// ============================================
// Focus search input on page load if no patient selected
document.addEventListener('DOMContentLoaded', function() {
    const hasPatient = <?php echo $selected_patient ? 'true' : 'false'; ?>;
    const hasReport = <?php echo $report_details ? 'true' : 'false'; ?>;
    if (!hasPatient && !hasReport) {
        document.getElementById('searchInput').focus();
    }
});
</script>

</body>
</html>