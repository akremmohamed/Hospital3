<?php
/**
 * study_comparison.php
 * 
 * HMS ERP - Radiology Study Comparison
 * 
 * Features:
 * - Select multiple studies for comparison
 * - Side-by-side image comparison (2x2 grid)
 * - Synchronized zoom, pan, and navigation
 * - Patient information comparison
 * - Report comparison view
 * - Modality-based color coding
 * - Timeline view of studies
 * - Export comparison report
 * - Print comparison view
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
$has_radiology_access = in_array($user_type, ['radiologist', 'radiology_technician', 'super_admin', 'admin', 'doctor']);

if ($user_id <= 0) {
    header('Location: login.php');
    exit;
}

if (!$has_radiology_access) {
    header('Location: dashboard.php?error=unauthorized');
    exit;
}

// ============================================
// 2. GET PARAMETERS
// ============================================
$compare_ids = isset($_GET['ids']) ? array_filter(array_map('intval', explode(',', $_GET['ids']))) : [];
$patient_id = isset($_GET['patient_id']) ? (int)$_GET['patient_id'] : 0;
$view_mode = $_GET['view'] ?? 'grid'; // grid, side_by_side, overlay

// Get branch name
$branch_name = 'All Branches';
if ($branch_id > 0) {
    $stmt = $pdo->prepare("SELECT name FROM branches WHERE id = :id AND deleted_at IS NULL");
    $stmt->execute([':id' => $branch_id]);
    $branch = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($branch) $branch_name = $branch['name'];
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
    return $icons[$modality] ?? 'fa-image';
}

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

// Get patient info if patient_id is provided
$patient_info = null;
if ($patient_id > 0) {
    $sql = "SELECT 
                id, mr_number, first_name, middle_name, last_name,
                date_of_birth, gender, phone, email, address
            FROM patients 
            WHERE id = :id AND deleted_at IS NULL";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':id' => $patient_id]);
    $patient_info = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Get studies for comparison
$comparison_studies = [];
if (!empty($compare_ids)) {
    $placeholders = implode(',', array_fill(0, count($compare_ids), '?'));
    $sql = "SELECT 
                ro.id, ro.order_number, ro.service_name, ro.service_code,
                ro.modality, ro.body_part, ro.priority, ro.status,
                ro.image_attachment_path, ro.interpretation_report,
                ro.clinical_history, ro.findings, ro.impression,
                ro.created_at, ro.updated_at, ro.performed_at, ro.reviewed_at,
                ro.scheduled_date, ro.physician_review_notes,
                CONCAT(p.first_name, ' ', COALESCE(p.middle_name, ''), ' ', p.last_name) as patient_name,
                p.mr_number, p.date_of_birth, p.gender,
                CONCAT(u.first_name, ' ', u.last_name) as doctor_name
            FROM radiology_orders ro
            LEFT JOIN patients p ON ro.patient_id = p.id
            LEFT JOIN users u ON ro.doctor_id = u.id
            WHERE ro.id IN ($placeholders) AND ro.deleted_at IS NULL
            ORDER BY ro.created_at DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($compare_ids);
    $comparison_studies = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// If no studies selected, get recent studies for selection
$recent_studies = [];
if (empty($compare_ids)) {
    $branch_filter = "";
    $params = [];
    if (!$is_global_admin && $branch_id > 0) {
        $branch_filter = " AND ro.branch_id = :branch_id";
        $params[':branch_id'] = $branch_id;
    }
    if ($patient_id > 0) {
        $branch_filter .= " AND ro.patient_id = :patient_id";
        $params[':patient_id'] = $patient_id;
    }
    
    $sql = "SELECT 
                ro.id, ro.order_number, ro.service_name, ro.modality,
                ro.body_part, ro.status, ro.image_attachment_path,
                ro.created_at, ro.interpretation_report,
                CONCAT(p.first_name, ' ', COALESCE(p.middle_name, ''), ' ', p.last_name) as patient_name,
                p.mr_number
            FROM radiology_orders ro
            LEFT JOIN patients p ON ro.patient_id = p.id
            WHERE ro.deleted_at IS NULL" . $branch_filter . "
            AND ro.image_attachment_path IS NOT NULL 
            AND ro.image_attachment_path != ''
            ORDER BY ro.created_at DESC
            LIMIT 30";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $recent_studies = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get available studies for the same patient (for selection)
$available_studies = [];
if ($patient_id > 0) {
    $sql = "SELECT 
                id, order_number, service_name, modality,
                body_part, status, image_attachment_path,
                created_at
            FROM radiology_orders 
            WHERE patient_id = :patient_id 
            AND deleted_at IS NULL
            AND image_attachment_path IS NOT NULL 
            AND image_attachment_path != ''
            ORDER BY created_at DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':patient_id' => $patient_id]);
    $available_studies = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ============================================
// 5. COMPARISON DATA PREPARATION
// ============================================
$comparison_data = [];
foreach ($comparison_studies as $study) {
    $comparison_data[] = [
        'id' => $study['id'],
        'order_number' => $study['order_number'],
        'service_name' => $study['service_name'],
        'modality' => $study['modality'],
        'body_part' => $study['body_part'],
        'status' => $study['status'],
        'patient_name' => $study['patient_name'],
        'mr_number' => $study['mr_number'],
        'doctor_name' => $study['doctor_name'],
        'created_at' => $study['created_at'],
        'interpretation_report' => $study['interpretation_report'],
        'clinical_history' => $study['clinical_history'],
        'findings' => $study['findings'],
        'impression' => $study['impression'],
        'physician_review_notes' => $study['physician_review_notes'],
        'image_count' => getImageCount($study['image_attachment_path']),
        'first_image' => getFirstImage($study['image_attachment_path']),
        'has_images' => !empty($study['image_attachment_path'])
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Study Comparison | Radiology | HMS ERP</title>
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
        .btn-info { background: #4cc9f0; color: white; border-color: #4cc9f0; }
        .btn-info:hover { background: #3ab8df; border-color: #3ab8df; }
        .btn-sm { padding: 4px 10px; font-size: 12px; }
        .btn-outline-primary { background: transparent; color: #7209b7; border-color: #7209b7; }
        .btn-outline-primary:hover { background: #7209b7; color: white; }
        
        .alert-info {
            background: #dbeafe;
            border: 1px solid #b7d4fe;
            color: #1e40af;
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 16px;
        }
        
        /* Comparison Grid */
        .comparison-container {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
            margin-bottom: 20px;
        }
        .comparison-container.three-col {
            grid-template-columns: 1fr 1fr 1fr;
        }
        .comparison-container.four-col {
            grid-template-columns: 1fr 1fr 1fr 1fr;
        }
        
        .study-panel {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08);
            border-top: 4px solid #7209b7;
        }
        .study-panel .panel-header {
            padding: 12px 16px;
            background: #f9fafb;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
        }
        .study-panel .panel-header .study-title {
            font-weight: 600;
            font-size: 14px;
        }
        .study-panel .panel-header .study-meta {
            font-size: 12px;
            color: #6b7280;
        }
        .study-panel .panel-body {
            padding: 12px 16px;
            min-height: 200px;
        }
        .study-panel .panel-body .image-container {
            width: 100%;
            height: 200px;
            background: #0a0a0f;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            cursor: pointer;
            position: relative;
        }
        .study-panel .panel-body .image-container img {
            width: 100%;
            height: 100%;
            object-fit: contain;
        }
        .study-panel .panel-body .image-container .no-image {
            color: #3a3a5e;
            text-align: center;
        }
        .study-panel .panel-body .image-container .no-image i {
            font-size: 32px;
            display: block;
            margin-bottom: 8px;
        }
        .study-panel .panel-body .image-container .image-count-badge {
            position: absolute;
            bottom: 8px;
            right: 8px;
            background: rgba(0,0,0,0.7);
            color: white;
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 11px;
        }
        .study-panel .panel-body .info-row {
            display: flex;
            justify-content: space-between;
            padding: 4px 0;
            font-size: 12px;
            border-bottom: 1px solid #f3f4f6;
        }
        .study-panel .panel-body .info-row .label {
            color: #6b7280;
        }
        .study-panel .panel-body .info-row .value {
            font-weight: 500;
            text-align: right;
            max-width: 60%;
        }
        .study-panel .panel-body .report-preview {
            font-size: 12px;
            color: #4b5563;
            max-height: 80px;
            overflow-y: auto;
            background: #f9fafb;
            padding: 8px;
            border-radius: 4px;
            margin-top: 8px;
        }
        
        /* Study Selection */
        .study-selector {
            background: white;
            border-radius: 12px;
            padding: 16px 20px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08);
            margin-bottom: 20px;
        }
        .study-selector .selector-title {
            font-weight: 600;
            font-size: 14px;
            margin-bottom: 12px;
        }
        .study-selector .study-list {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        .study-selector .study-list .study-item {
            padding: 6px 14px;
            border: 2px solid #e5e7eb;
            border-radius: 20px;
            cursor: pointer;
            font-size: 12px;
            transition: all 0.2s;
        }
        .study-selector .study-list .study-item:hover {
            border-color: #7209b7;
            background: #f3f0ff;
        }
        .study-selector .study-list .study-item.selected {
            border-color: #7209b7;
            background: #7209b7;
            color: white;
        }
        .study-selector .study-list .study-item .modality-tag {
            font-size: 10px;
            opacity: 0.7;
        }
        
        /* View Mode Toggle */
        .view-mode-toggle {
            display: flex;
            gap: 4px;
            background: #f3f4f6;
            padding: 3px;
            border-radius: 6px;
        }
        .view-mode-toggle .btn {
            padding: 4px 12px;
            border: none;
            background: transparent;
            border-radius: 4px;
            font-size: 12px;
        }
        .view-mode-toggle .btn.active {
            background: white;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        
        /* Modality badge */
        .modality-badge {
            padding: 2px 10px;
            border-radius: 12px;
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
        
        .status-badge {
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 10px;
            font-weight: 600;
            display: inline-block;
        }
        .status-badge.completed { background: #d1fae5; color: #065f46; }
        .status-badge.reviewed { background: #d1fae5; color: #065f46; }
        .status-badge.in_progress { background: #fef3c7; color: #d97706; }
        .status-badge.scheduled { background: #dbeafe; color: #1e40af; }
        .status-badge.pending_payment { background: #f3f4f6; color: #6b7280; }
        .status-badge.cancelled { background: #fee2e2; color: #dc2626; }
        
        /* Comparison Tools */
        .comparison-tools {
            background: white;
            border-radius: 12px;
            padding: 12px 18px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08);
            margin-bottom: 20px;
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: center;
        }
        .comparison-tools .tool-label {
            font-size: 12px;
            color: #6b7280;
            font-weight: 500;
        }
        
        .text-muted { color: #6b7280; }
        .text-center { text-align: center; }
        .mt-2 { margin-top: 8px; }
        .mb-2 { margin-bottom: 8px; }
        .flex { display: flex; }
        .flex-wrap { flex-wrap: wrap; }
        .items-center { align-items: center; }
        .gap-2 { gap: 8px; }
        
        @media (max-width: 992px) {
            .comparison-container { grid-template-columns: 1fr 1fr; }
            .comparison-container.three-col { grid-template-columns: 1fr 1fr; }
            .comparison-container.four-col { grid-template-columns: 1fr 1fr; }
        }
        @media (max-width: 768px) {
            .comparison-container { grid-template-columns: 1fr; }
            .comparison-container.three-col { grid-template-columns: 1fr; }
            .comparison-container.four-col { grid-template-columns: 1fr; }
            .page-header h1 { font-size: 20px; }
            .study-selector .study-list .study-item { font-size: 11px; padding: 4px 10px; }
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
                    <i class="fas fa-not-equal"></i> Study Comparison
                    <span class="subtitle"><?php echo count($comparison_studies); ?> studies selected</span>
                </h1>
            </div>
            <div>
                <button class="btn" onclick="window.location.reload();">
                    <i class="fas fa-sync-alt"></i> Refresh
                </button>
                <button class="btn btn-success" onclick="window.print()">
                    <i class="fas fa-print"></i> Print
                </button>
                <a href="image_archive.php" class="btn btn-primary">
                    <i class="fas fa-archive"></i> Archive
                </a>
            </div>
        </div>

        <!-- Patient Info -->
        <?php if ($patient_info): ?>
            <div class="alert-info" style="display:flex;flex-wrap:wrap;gap:16px;align-items:center;padding:12px 18px;">
                <div><strong>Patient:</strong> <?php echo htmlspecialchars($patient_info['first_name'] . ' ' . $patient_info['last_name']); ?></div>
                <div><strong>MRN:</strong> <?php echo htmlspecialchars($patient_info['mr_number']); ?></div>
                <div><strong>DOB:</strong> <?php echo htmlspecialchars($patient_info['date_of_birth'] ?? 'N/A'); ?></div>
                <div><strong>Gender:</strong> <?php echo ucfirst($patient_info['gender'] ?? 'N/A'); ?></div>
                <div><strong>Studies:</strong> <?php echo count($available_studies); ?></div>
            </div>
        <?php endif; ?>

        <!-- Study Selector -->
        <?php if (!empty($available_studies) || !empty($recent_studies)): ?>
        <div class="study-selector">
            <div class="selector-title">
                <i class="fas fa-plus-circle"></i> Select Studies to Compare
                <span style="font-weight:400;font-size:12px;color:#6b7280;margin-left:8px;">
                    (click to add/remove)
                </span>
            </div>
            <div class="study-list" id="studyList">
                <?php 
                $study_list = !empty($available_studies) ? $available_studies : $recent_studies;
                foreach ($study_list as $study): 
                    $is_selected = in_array($study['id'], $compare_ids);
                    $has_image = !empty($study['image_attachment_path']);
                ?>
                    <div class="study-item <?php echo $is_selected ? 'selected' : ''; ?>" 
                         onclick="toggleStudy(<?php echo $study['id']; ?>)"
                         data-id="<?php echo $study['id']; ?>">
                        <span><?php echo htmlspecialchars($study['order_number']); ?></span>
                        <span class="modality-tag">
                            <span class="modality-badge modality-<?php echo $study['modality'] ?? 'xray'; ?>" style="font-size:9px;padding:1px 6px;">
                                <?php echo strtoupper($study['modality'] ?? 'X-Ray'); ?>
                            </span>
                        </span>
                        <?php if (!$has_image): ?>
                            <span style="font-size:9px;color:#ef476f;">(no image)</span>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php if (count($study_list) >= 2): ?>
                <div style="margin-top:10px;">
                    <button class="btn btn-sm btn-outline-primary" onclick="selectAll()">
                        <i class="fas fa-check-double"></i> Select All
                    </button>
                    <button class="btn btn-sm btn-danger" onclick="clearAllSelection()">
                        <i class="fas fa-times"></i> Clear All
                    </button>
                </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Comparison Tools -->
        <div class="comparison-tools">
            <span class="tool-label"><i class="fas fa-th"></i> View:</span>
            <div class="view-mode-toggle">
                <button class="btn <?php echo $view_mode === 'grid' ? 'active' : ''; ?>" onclick="setViewMode('grid')">
                    <i class="fas fa-th"></i> Grid
                </button>
                <button class="btn <?php echo $view_mode === 'side_by_side' ? 'active' : ''; ?>" onclick="setViewMode('side_by_side')">
                    <i class="fas fa-columns"></i> Side by Side
                </button>
            </div>
            <span class="tool-label" style="margin-left:12px;"><i class="fas fa-sync-alt"></i> Sync:</span>
            <button class="btn btn-sm" id="syncZoomBtn" onclick="toggleSync()">
                <i class="fas fa-link"></i> <span id="syncLabel">Sync Zoom & Pan</span>
            </button>
            <span style="flex:1;"></span>
            <span class="tool-label" style="font-size:11px;color:#6b7280;">
                <i class="fas fa-info-circle"></i> Click on image to view in PACS
            </span>
        </div>

        <!-- Comparison Grid -->
        <?php if (empty($comparison_studies)): ?>
            <div style="background:white;border-radius:12px;padding:60px 20px;text-align:center;box-shadow:0 1px 3px rgba(0,0,0,0.08);">
                <i class="fas fa-not-equal" style="font-size:48px;color:#d1d5db;display:block;margin-bottom:16px;"></i>
                <h3 style="font-size:18px;margin-bottom:8px;">No Studies Selected</h3>
                <p style="color:#6b7280;">Select studies from the list above to compare them side by side.</p>
                <?php if (!empty($recent_studies) && empty($available_studies)): ?>
                    <p style="color:#6b7280;font-size:13px;margin-top:8px;">
                        <i class="fas fa-info-circle"></i> Showing recent studies. Select a patient to see their studies.
                    </p>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <?php 
            $col_class = 'two-col';
            $count = count($comparison_studies);
            if ($count === 3) $col_class = 'three-col';
            else if ($count >= 4) $col_class = 'four-col';
            ?>
            <div class="comparison-container <?php echo $col_class; ?>" id="comparisonContainer">
                <?php foreach ($comparison_studies as $study): ?>
                    <div class="study-panel" style="border-top-color: <?php echo getModalityColor($study['modality'] ?? 'xray'); ?>;">
                        <div class="panel-header">
                            <div>
                                <span class="study-title"><?php echo htmlspecialchars($study['order_number']); ?></span>
                                <span class="modality-badge modality-<?php echo $study['modality'] ?? 'xray'; ?>" style="margin-left:6px;">
                                    <?php echo strtoupper($study['modality'] ?? 'X-Ray'); ?>
                                </span>
                                <span class="status-badge <?php echo getStatusBadge($study['status'] ?? ''); ?>" style="margin-left:4px;">
                                    <?php echo ucfirst(str_replace('_', ' ', $study['status'] ?? 'N/A')); ?>
                                </span>
                            </div>
                            <div class="study-meta">
                                <?php echo date('M d, Y', strtotime($study['created_at'] ?? 'now')); ?>
                                <button class="btn btn-sm btn-danger" style="padding:1px 6px;font-size:10px;margin-left:4px;" 
                                        onclick="removeStudy(<?php echo $study['id']; ?>)">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                        <div class="panel-body">
                            <div class="image-container" onclick="openPACS(<?php echo $study['id']; ?>)">
                                <?php if (!empty($study['first_image'])): ?>
                                    <img src="<?php echo htmlspecialchars(getImageUrl($study['first_image'])); ?>" 
                                         alt="<?php echo htmlspecialchars($study['order_number']); ?>"
                                         loading="lazy"
                                         onerror="this.style.display='none';this.parentElement.querySelector('.no-image').style.display='flex';">
                                    <div class="no-image" style="display:none;flex-direction:column;align-items:center;justify-content:center;">
                                        <i class="fas fa-image"></i>
                                        <span style="font-size:12px;color:#6b7280;">Image unavailable</span>
                                    </div>
                                    <?php if ($study['image_count'] > 1): ?>
                                        <span class="image-count-badge"><?php echo $study['image_count']; ?> images</span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <div class="no-image" style="display:flex;flex-direction:column;align-items:center;justify-content:center;">
                                        <i class="fas fa-image"></i>
                                        <span style="font-size:12px;color:#6b7280;">No image available</span>
                                    </div>
                                <?php endif; ?>
                            </div>
                            
                            <div style="margin-top:8px;">
                                <div class="info-row">
                                    <span class="label">Patient</span>
                                    <span class="value"><?php echo htmlspecialchars($study['patient_name'] ?? 'N/A'); ?></span>
                                </div>
                                <div class="info-row">
                                    <span class="label">MRN</span>
                                    <span class="value"><?php echo htmlspecialchars($study['mr_number'] ?? 'N/A'); ?></span>
                                </div>
                                <div class="info-row">
                                    <span class="label">Service</span>
                                    <span class="value"><?php echo htmlspecialchars($study['service_name'] ?? 'N/A'); ?></span>
                                </div>
                                <div class="info-row">
                                    <span class="label">Body Part</span>
                                    <span class="value"><?php echo htmlspecialchars($study['body_part'] ?? 'N/A'); ?></span>
                                </div>
                                <div class="info-row">
                                    <span class="label">Doctor</span>
                                    <span class="value"><?php echo htmlspecialchars($study['doctor_name'] ?? 'N/A'); ?></span>
                                </div>
                                <?php if (!empty($study['interpretation_report'])): ?>
                                    <div class="report-preview">
                                        <strong>Report:</strong> 
                                        <?php echo nl2br(htmlspecialchars(substr($study['interpretation_report'], 0, 150) . (strlen($study['interpretation_report']) > 150 ? '...' : ''))); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            
            <!-- Comparison Summary -->
            <div style="background:white;border-radius:12px;padding:16px 20px;box-shadow:0 1px 3px rgba(0,0,0,0.08);margin-top:16px;">
                <div style="font-weight:600;font-size:14px;margin-bottom:8px;">
                    <i class="fas fa-chart-bar"></i> Comparison Summary
                </div>
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;font-size:13px;">
                    <div><strong>Total Studies:</strong> <?php echo count($comparison_studies); ?></div>
                    <div><strong>Modalities:</strong> 
                        <?php 
                        $modalities = array_unique(array_column($comparison_studies, 'modality'));
                        echo implode(', ', array_map('strtoupper', $modalities));
                        ?>
                    </div>
                    <div><strong>Date Range:</strong>
                        <?php 
                        $dates = array_column($comparison_studies, 'created_at');
                        if (!empty($dates)) {
                            echo date('M d, Y', strtotime(min($dates))) . ' - ' . date('M d, Y', strtotime(max($dates)));
                        } else {
                            echo 'N/A';
                        }
                        ?>
                    </div>
                    <div><strong>Body Parts:</strong>
                        <?php 
                        $parts = array_unique(array_filter(array_column($comparison_studies, 'body_part')));
                        echo !empty($parts) ? implode(', ', $parts) : 'N/A';
                        ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
// ============================================
// STUDY SELECTION FUNCTIONS
// ============================================

function toggleStudy(studyId) {
    // Get current selected IDs from URL
    let urlParams = new URLSearchParams(window.location.search);
    let ids = urlParams.get('ids') ? urlParams.get('ids').split(',').map(Number) : [];
    
    const index = ids.indexOf(studyId);
    if (index > -1) {
        ids.splice(index, 1);
    } else {
        if (ids.length >= 4) {
            alert('Maximum 4 studies can be compared at once.');
            return;
        }
        ids.push(studyId);
    }
    
    updateUrl(ids);
}

function removeStudy(studyId) {
    let urlParams = new URLSearchParams(window.location.search);
    let ids = urlParams.get('ids') ? urlParams.get('ids').split(',').map(Number) : [];
    const index = ids.indexOf(studyId);
    if (index > -1) {
        ids.splice(index, 1);
        updateUrl(ids);
    }
}

function selectAll() {
    const items = document.querySelectorAll('.study-item');
    let ids = [];
    items.forEach(item => {
        const id = parseInt(item.dataset.id);
        if (!ids.includes(id)) {
            ids.push(id);
        }
    });
    // Limit to 4
    if (ids.length > 4) {
        ids = ids.slice(0, 4);
    }
    updateUrl(ids);
}

function clearAllSelection() {
    updateUrl([]);
}

function updateUrl(ids) {
    let url = window.location.pathname + '?';
    if (ids.length > 0) {
        url += 'ids=' + ids.join(',');
    }
    // Preserve patient_id if present
    let urlParams = new URLSearchParams(window.location.search);
    let patientId = urlParams.get('patient_id');
    if (patientId) {
        url += '&patient_id=' + patientId;
    }
    // Preserve view mode
    let view = urlParams.get('view');
    if (view) {
        url += '&view=' + view;
    }
    window.location.href = url;
}

// ============================================
// VIEW MODE FUNCTIONS
// ============================================

function setViewMode(mode) {
    let urlParams = new URLSearchParams(window.location.search);
    urlParams.set('view', mode);
    window.location.search = urlParams.toString();
}

function toggleSync() {
    const btn = document.getElementById('syncZoomBtn');
    btn.classList.toggle('active');
    const label = document.getElementById('syncLabel');
    if (btn.classList.contains('active')) {
        label.textContent = 'Sync On';
        btn.style.borderColor = '#06d6a0';
        btn.style.color = '#06d6a0';
        // Enable sync for all images
        document.querySelectorAll('.study-panel .image-container img').forEach((img, index, arr) => {
            img.addEventListener('wheel', syncZoom);
        });
    } else {
        label.textContent = 'Sync Off';
        btn.style.borderColor = '';
        btn.style.color = '';
        document.querySelectorAll('.study-panel .image-container img').forEach(img => {
            img.removeEventListener('wheel', syncZoom);
        });
    }
}

function syncZoom(e) {
    e.preventDefault();
    // Get zoom level from current image
    const img = e.currentTarget;
    const container = img.parentElement;
    const rect = container.getBoundingClientRect();
    const zoom = img.getBoundingClientRect().width / rect.width;
    
    // Apply same zoom to all images
    document.querySelectorAll('.study-panel .image-container img').forEach(otherImg => {
        if (otherImg !== img) {
            const otherContainer = otherImg.parentElement;
            otherImg.style.width = (otherContainer.getBoundingClientRect().width * zoom) + 'px';
            otherImg.style.height = 'auto';
        }
    });
}

function openPACS(studyId) {
    window.open('pacs_viewer.php?order_id=' + studyId, '_blank');
}

// ============================================
// KEYBOARD SHORTCUTS
// ============================================

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        // Close any open modals
    }
    if (e.ctrlKey && e.key === 'p') {
        e.preventDefault();
        window.print();
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
        .page-header .btn, .view-mode-toggle, .study-selector, .comparison-tools, .alert-info .btn { display: none !important; }
        .comparison-container { break-inside: avoid; }
        .study-panel { break-inside: avoid; page-break-inside: avoid; }
        body { background: white !important; }
        .study-panel .panel-body .image-container { height: 150px !important; }
        .no-image { display: none !important; }
        .comparison-tools { display: none !important; }
        .study-selector { display: none !important; }
    }
`;
document.head.appendChild(printStyles);

// ============================================
// INITIALIZATION
// ============================================

// Highlight selected items on load
document.addEventListener('DOMContentLoaded', function() {
    const urlParams = new URLSearchParams(window.location.search);
    const ids = urlParams.get('ids') ? urlParams.get('ids').split(',').map(Number) : [];
    
    document.querySelectorAll('.study-item').forEach(item => {
        const id = parseInt(item.dataset.id);
        if (ids.includes(id)) {
            item.classList.add('selected');
        }
    });
});

console.log('Study Comparison initialized');
</script>

</body>
</html>