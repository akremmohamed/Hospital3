<?php
/**
 * pacs_viewer.php
 * 
 * HMS ERP - PACS Viewer (Picture Archiving and Communication System)
 * 
 * Features:
 * - View radiology images
 * - Image navigation (previous/next)
 * - Zoom, pan, rotate, flip
 * - Window level adjustment (brightness/contrast)
 * - Measurement tools (distance, angle)
 * - Image annotations
 * - Series viewer
 * - Thumbnail navigation
 * - DICOM metadata display
 * - Print images
 * - Download images
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
$order_id = isset($_GET['order_id']) ? (int)$_GET['order_id'] : 0;
$image_index = isset($_GET['index']) ? (int)$_GET['index'] : 0;
$view = $_GET['view'] ?? 'gallery'; // gallery, single, series

// Get branch name
$branch_name = 'All Branches';
if ($branch_id > 0) {
    $stmt = $pdo->prepare("SELECT name FROM branches WHERE id = :id AND deleted_at IS NULL");
    $stmt->execute([':id' => $branch_id]);
    $branch = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($branch) $branch_name = $branch['name'];
}

// ============================================
// 3. DATA FETCHING
// ============================================

// Get radiology order details
$order_data = null;
$images = [];

if ($order_id > 0) {
    $sql = "SELECT 
                ro.id, ro.order_number, ro.service_name, ro.service_code,
                ro.modality, ro.body_part, ro.priority, ro.status,
                ro.clinical_history, ro.findings, ro.impression,
                ro.interpretation_report, ro.image_attachment_path,
                ro.created_at, ro.performed_at, ro.reviewed_at,
                CONCAT(p.first_name, ' ', COALESCE(p.middle_name, ''), ' ', p.last_name) as patient_name,
                p.mr_number, p.date_of_birth, p.gender,
                CONCAT(u.first_name, ' ', u.last_name) as doctor_name
            FROM radiology_orders ro
            LEFT JOIN patients p ON ro.patient_id = p.id
            LEFT JOIN users u ON ro.doctor_id = u.id
            WHERE ro.id = :id AND ro.deleted_at IS NULL";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':id' => $order_id]);
    $order_data = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($order_data && !empty($order_data['image_attachment_path'])) {
        // Parse image paths - can be JSON array or comma-separated or single path
        $path = $order_data['image_attachment_path'];
        if (strpos($path, '[') === 0 || strpos($path, '{') === 0) {
            $images = json_decode($path, true);
            if (!is_array($images)) {
                $images = [];
            }
        } else if (strpos($path, ',') !== false) {
            $images = array_map('trim', explode(',', $path));
        } else {
            $images = [$path];
        }
        // Filter out empty values
        $images = array_filter($images);
    }
}

// If no order_id, get recent orders with images
$recent_orders = [];
if ($order_id === 0) {
    $branch_filter = "";
    $params = [];
    if (!$is_global_admin && $branch_id > 0) {
        $branch_filter = " AND ro.branch_id = :branch_id";
        $params[':branch_id'] = $branch_id;
    }
    
    $sql = "SELECT 
                ro.id, ro.order_number, ro.service_name, ro.modality,
                ro.image_attachment_path, ro.created_at,
                CONCAT(p.first_name, ' ', COALESCE(p.middle_name, ''), ' ', p.last_name) as patient_name
            FROM radiology_orders ro
            LEFT JOIN patients p ON ro.patient_id = p.id
            WHERE ro.image_attachment_path IS NOT NULL 
            AND ro.image_attachment_path != ''
            AND ro.deleted_at IS NULL" . $branch_filter . "
            ORDER BY ro.created_at DESC
            LIMIT 20";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $recent_orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Ensure image_index is within bounds
if ($image_index >= count($images) && count($images) > 0) {
    $image_index = 0;
}

// Get study list for same patient
$study_list = [];
if ($order_data && isset($order_data['patient_name'])) {
    $sql = "SELECT 
                ro.id, ro.order_number, ro.service_name, ro.modality,
                ro.image_attachment_path, ro.created_at, ro.status,
                ro.interpretation_report
            FROM radiology_orders ro
            WHERE ro.patient_id = (SELECT patient_id FROM radiology_orders WHERE id = :id)
            AND ro.deleted_at IS NULL
            AND ro.id != :id
            ORDER BY ro.created_at DESC
            LIMIT 10";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':id' => $order_id]);
    $study_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ============================================
// 4. HELPER FUNCTIONS
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
    // If path is relative, prepend base URL
    if (strpos($path, 'http') !== 0 && strpos($path, 'data:') !== 0) {
        // Check if it's a server path
        if (strpos($path, '/') === 0) {
            return $path;
        }
        return '/uploads/radiology/' . ltrim($path, '/');
    }
    return $path;
}

// Get single image path for display
$current_image = '';
if (count($images) > 0 && isset($images[$image_index])) {
    $current_image = $images[$image_index];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PACS Viewer | Radiology | HMS ERP</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;
            background: #0a0a0f;
            color: #ffffff;
            overflow: hidden;
            height: 100vh;
        }
        
        #wrapper { display: flex; height: 100vh; }
        #page-wrapper {
            flex: 1;
            margin-left: 260px;
            display: flex;
            flex-direction: column;
            height: 100vh;
            overflow: hidden;
            padding-top: 70px;
        }
        body.erp-sidebar-collapsed #page-wrapper { margin-left: 80px; }
        
        @media (max-width: 768px) {
            #page-wrapper {
                margin-left: 0 !important;
                padding-top: 60px;
            }
        }
        
        /* Viewer Toolbar */
        .viewer-toolbar {
            background: #1a1a2e;
            padding: 8px 16px;
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
            border-bottom: 1px solid #2a2a3e;
            flex-shrink: 0;
            min-height: 50px;
        }
        .viewer-toolbar .btn {
            padding: 6px 12px;
            border: 1px solid #2a2a3e;
            border-radius: 6px;
            background: #2a2a3e;
            color: #c0c0d0;
            cursor: pointer;
            font-size: 12px;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .viewer-toolbar .btn:hover {
            background: #3a3a5e;
            border-color: #4a4a6e;
            color: #ffffff;
        }
        .viewer-toolbar .btn.active {
            background: #4361ee;
            border-color: #4361ee;
            color: #ffffff;
        }
        .viewer-toolbar .btn-primary {
            background: #4361ee;
            border-color: #4361ee;
            color: #ffffff;
        }
        .viewer-toolbar .btn-primary:hover {
            background: #3a0ca3;
            border-color: #3a0ca3;
        }
        .viewer-toolbar .btn-danger {
            background: #ef476f;
            border-color: #ef476f;
            color: #ffffff;
        }
        .viewer-toolbar .btn-danger:hover {
            background: #d63e5e;
            border-color: #d63e5e;
        }
        .viewer-toolbar .btn-success {
            background: #06d6a0;
            border-color: #06d6a0;
            color: #ffffff;
        }
        .viewer-toolbar .btn-success:hover {
            background: #05b88a;
            border-color: #05b88a;
        }
        .viewer-toolbar .separator {
            width: 1px;
            height: 28px;
            background: #2a2a3e;
            margin: 0 4px;
        }
        .viewer-toolbar .toolbar-label {
            font-size: 11px;
            color: #6b7280;
            margin-right: 4px;
        }
        .viewer-toolbar .image-counter {
            font-size: 12px;
            color: #6b7280;
            margin-left: auto;
            padding: 4px 12px;
            background: #1a1a2e;
            border-radius: 4px;
        }
        
        /* Main Viewer Area */
        .viewer-main {
            flex: 1;
            display: flex;
            position: relative;
            background: #0a0a0f;
            overflow: hidden;
        }
        .viewer-canvas-container {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            overflow: hidden;
            background: #0a0a0f;
        }
        .viewer-canvas-container img {
            max-width: 100%;
            max-height: 100%;
            object-fit: contain;
            user-select: none;
            -webkit-user-drag: none;
            transition: transform 0.1s ease;
            cursor: grab;
        }
        .viewer-canvas-container img:active {
            cursor: grabbing;
        }
        .viewer-canvas-container .no-image {
            text-align: center;
            color: #3a3a5e;
        }
        .viewer-canvas-container .no-image i {
            font-size: 64px;
            display: block;
            margin-bottom: 16px;
        }
        .viewer-canvas-container .no-image h3 {
            font-size: 20px;
            font-weight: 600;
            margin-bottom: 8px;
        }
        .viewer-canvas-container .no-image p {
            color: #6b7280;
        }
        
        /* Overlay Info */
        .image-overlay-info {
            position: absolute;
            bottom: 16px;
            left: 16px;
            background: rgba(0,0,0,0.7);
            padding: 8px 14px;
            border-radius: 6px;
            font-size: 12px;
            color: #c0c0d0;
            pointer-events: none;
        }
        .image-overlay-info .highlight {
            color: #ffffff;
            font-weight: 600;
        }
        
        /* Navigation arrows */
        .nav-arrow {
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            background: rgba(0,0,0,0.6);
            border: none;
            color: #ffffff;
            width: 40px;
            height: 60px;
            cursor: pointer;
            font-size: 20px;
            transition: all 0.2s;
            z-index: 10;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 4px;
        }
        .nav-arrow:hover {
            background: rgba(67, 97, 238, 0.7);
        }
        .nav-arrow.left { left: 8px; }
        .nav-arrow.right { right: 8px; }
        .nav-arrow.disabled {
            opacity: 0.3;
            cursor: not-allowed;
        }
        
        /* Thumbnail Strip */
        .thumbnail-strip {
            background: #1a1a2e;
            padding: 8px 16px;
            display: flex;
            gap: 8px;
            overflow-x: auto;
            flex-shrink: 0;
            border-top: 1px solid #2a2a3e;
            min-height: 80px;
            align-items: center;
        }
        .thumbnail-strip::-webkit-scrollbar {
            height: 4px;
        }
        .thumbnail-strip::-webkit-scrollbar-track {
            background: #1a1a2e;
        }
        .thumbnail-strip::-webkit-scrollbar-thumb {
            background: #3a3a5e;
            border-radius: 2px;
        }
        .thumbnail-item {
            width: 60px;
            height: 60px;
            border-radius: 4px;
            overflow: hidden;
            cursor: pointer;
            border: 2px solid transparent;
            transition: all 0.2s;
            flex-shrink: 0;
            background: #0a0a0f;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .thumbnail-item img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .thumbnail-item:hover {
            border-color: #3a3a5e;
            transform: scale(1.05);
        }
        .thumbnail-item.active {
            border-color: #4361ee;
            box-shadow: 0 0 15px rgba(67, 97, 238, 0.3);
        }
        .thumbnail-item .thumb-placeholder {
            font-size: 20px;
            color: #3a3a5e;
        }
        .thumbnail-item .thumb-index {
            position: absolute;
            bottom: 2px;
            right: 4px;
            font-size: 9px;
            color: #6b7280;
            background: rgba(0,0,0,0.7);
            padding: 1px 4px;
            border-radius: 2px;
        }
        .thumbnail-item {
            position: relative;
        }
        
        /* Right Panel - Study Info */
        .study-panel {
            width: 300px;
            background: #1a1a2e;
            border-left: 1px solid #2a2a3e;
            padding: 16px;
            overflow-y: auto;
            flex-shrink: 0;
            display: none;
        }
        .study-panel.visible {
            display: block;
        }
        .study-panel::-webkit-scrollbar {
            width: 4px;
        }
        .study-panel::-webkit-scrollbar-track {
            background: #1a1a2e;
        }
        .study-panel::-webkit-scrollbar-thumb {
            background: #3a3a5e;
            border-radius: 2px;
        }
        
        .study-panel .panel-title {
            font-size: 14px;
            font-weight: 600;
            color: #ffffff;
            margin-bottom: 12px;
            border-bottom: 1px solid #2a2a3e;
            padding-bottom: 8px;
        }
        .study-panel .info-row {
            display: flex;
            justify-content: space-between;
            padding: 6px 0;
            font-size: 12px;
            border-bottom: 1px solid #1a1a2e;
        }
        .study-panel .info-row .label {
            color: #6b7280;
        }
        .study-panel .info-row .value {
            color: #c0c0d0;
            text-align: right;
            max-width: 60%;
        }
        
        /* Measurement tools */
        .measurement-tools {
            position: absolute;
            top: 16px;
            right: 16px;
            display: flex;
            flex-direction: column;
            gap: 4px;
            z-index: 10;
        }
        .measurement-tools .btn {
            padding: 6px 10px;
            border: 1px solid #2a2a3e;
            border-radius: 4px;
            background: rgba(0,0,0,0.7);
            color: #c0c0d0;
            cursor: pointer;
            font-size: 11px;
            transition: all 0.2s;
        }
        .measurement-tools .btn:hover {
            background: #3a3a5e;
        }
        .measurement-tools .btn.active {
            background: #4361ee;
            border-color: #4361ee;
            color: #ffffff;
        }
        
        /* Responsive */
        @media (max-width: 992px) {
            .study-panel {
                width: 250px;
            }
            .viewer-toolbar .btn span { display: none; }
        }
        @media (max-width: 768px) {
            .study-panel {
                width: 100%;
                max-height: 40vh;
                border-left: none;
                border-top: 1px solid #2a2a3e;
            }
            .viewer-main {
                flex-direction: column;
            }
            .thumbnail-strip {
                min-height: 60px;
                padding: 6px 12px;
            }
            .thumbnail-item {
                width: 48px;
                height: 48px;
            }
            .image-overlay-info {
                font-size: 10px;
                padding: 4px 10px;
            }
            .nav-arrow {
                width: 30px;
                height: 40px;
                font-size: 14px;
            }
            .measurement-tools {
                top: 8px;
                right: 8px;
            }
            .measurement-tools .btn {
                padding: 4px 6px;
                font-size: 10px;
            }
        }
        
        /* DICOM overlay */
        .dicom-overlay {
            position: absolute;
            top: 16px;
            left: 16px;
            font-size: 11px;
            color: rgba(255,255,255,0.5);
            pointer-events: none;
            line-height: 1.6;
            font-family: 'Courier New', monospace;
        }
        
        /* Loading spinner */
        .loading-spinner {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            color: #4361ee;
            font-size: 32px;
            animation: spin 1s linear infinite;
        }
        @keyframes spin {
            0% { transform: translate(-50%, -50%) rotate(0deg); }
            100% { transform: translate(-50%, -50%) rotate(360deg); }
        }
        
        /* Tooltip */
        .tooltip {
            position: relative;
        }
        .tooltip:hover::after {
            content: attr(data-tip);
            position: absolute;
            bottom: 100%;
            left: 50%;
            transform: translateX(-50%);
            background: rgba(0,0,0,0.9);
            color: #ffffff;
            padding: 4px 10px;
            border-radius: 4px;
            font-size: 10px;
            white-space: nowrap;
            margin-bottom: 4px;
        }
    </style>
</head>
<body>
<div id="wrapper">
    <?php include('assets/inc/nav.php'); ?>
    <?php include('assets/inc/sidebar.php'); ?>
    
    <div id="page-wrapper">
        <!-- Toolbar -->
        <div class="viewer-toolbar">
            <button class="btn" onclick="window.location.href='radiology_worklist.php'" title="Back to Worklist">
                <i class="fas fa-arrow-left"></i>
            </button>
            <div class="separator"></div>
            
            <button class="btn" id="zoomInBtn" title="Zoom In"><i class="fas fa-search-plus"></i></button>
            <button class="btn" id="zoomOutBtn" title="Zoom Out"><i class="fas fa-search-minus"></i></button>
            <button class="btn" id="zoomFitBtn" title="Fit to Screen"><i class="fas fa-expand"></i></button>
            <button class="btn" id="resetViewBtn" title="Reset View"><i class="fas fa-home"></i></button>
            <div class="separator"></div>
            
            <button class="btn" id="rotateLeftBtn" title="Rotate Left"><i class="fas fa-undo-alt"></i></button>
            <button class="btn" id="rotateRightBtn" title="Rotate Right"><i class="fas fa-redo-alt"></i></button>
            <button class="btn" id="flipHorizontalBtn" title="Flip Horizontal"><i class="fas fa-arrows-alt-h"></i></button>
            <button class="btn" id="flipVerticalBtn" title="Flip Vertical"><i class="fas fa-arrows-alt-v"></i></button>
            <div class="separator"></div>
            
            <button class="btn" id="brightnessUpBtn" title="Increase Brightness"><i class="fas fa-sun"></i>+</button>
            <button class="btn" id="brightnessDownBtn" title="Decrease Brightness"><i class="fas fa-sun"></i>-</button>
            <button class="btn" id="contrastUpBtn" title="Increase Contrast"><i class="fas fa-adjust"></i>+</button>
            <button class="btn" id="contrastDownBtn" title="Decrease Contrast"><i class="fas fa-adjust"></i>-</button>
            <div class="separator"></div>
            
            <button class="btn" id="togglePanelBtn" title="Toggle Info Panel"><i class="fas fa-info-circle"></i></button>
            <button class="btn btn-success" id="printBtn" title="Print Image"><i class="fas fa-print"></i></button>
            <button class="btn btn-primary" id="downloadBtn" title="Download Image"><i class="fas fa-download"></i></button>
            
            <span class="image-counter">
                <span id="currentImageIndex"><?php echo $image_index + 1; ?></span> / <span id="totalImages"><?php echo count($images); ?></span>
                <?php if (count($images) > 0): ?>
                    <span style="margin-left:8px;color:#6b7280;">| <?php echo basename($current_image); ?></span>
                <?php endif; ?>
            </span>
        </div>
        
        <!-- Main Viewer -->
        <div class="viewer-main">
            <div class="viewer-canvas-container" id="canvasContainer">
                <?php if (count($images) > 0 && !empty($current_image)): ?>
                    <img id="mainImage" src="<?php echo htmlspecialchars(getImageUrl($current_image)); ?>" 
                         alt="Radiology Image" 
                         style="transform: scale(1) rotate(0deg); filter: brightness(1) contrast(1);">
                    <div class="loading-spinner" id="loadingSpinner" style="display:none;">
                        <i class="fas fa-spinner"></i>
                    </div>
                    
                    <!-- Navigation arrows -->
                    <button class="nav-arrow left <?php echo $image_index <= 0 ? 'disabled' : ''; ?>" 
                            id="prevImageBtn" onclick="navigateImage(-1)">
                        <i class="fas fa-chevron-left"></i>
                    </button>
                    <button class="nav-arrow right <?php echo $image_index >= count($images) - 1 ? 'disabled' : ''; ?>" 
                            id="nextImageBtn" onclick="navigateImage(1)">
                        <i class="fas fa-chevron-right"></i>
                    </button>
                    
                    <!-- DICOM Overlay -->
                    <div class="dicom-overlay" id="dicomOverlay">
                        <?php if ($order_data): ?>
                            <div>Study: <?php echo htmlspecialchars($order_data['order_number']); ?></div>
                            <div>Patient: <?php echo htmlspecialchars($order_data['patient_name'] ?? 'N/A'); ?></div>
                            <div>Modality: <?php echo strtoupper($order_data['modality'] ?? 'N/A'); ?></div>
                            <div>Body Part: <?php echo htmlspecialchars($order_data['body_part'] ?? 'N/A'); ?></div>
                            <div>Image: <?php echo $image_index + 1; ?>/<?php echo count($images); ?></div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Measurement Tools -->
                    <div class="measurement-tools">
                        <button class="btn" id="measureDistanceBtn" data-tip="Distance"><i class="fas fa-ruler"></i></button>
                        <button class="btn" id="measureAngleBtn" data-tip="Angle"><i class="fas fa-vector-square"></i></button>
                        <button class="btn" id="measureAreaBtn" data-tip="Area"><i class="fas fa-square"></i></button>
                        <button class="btn" id="annotateBtn" data-tip="Annotate"><i class="fas fa-pen"></i></button>
                        <button class="btn" id="clearMeasurementsBtn" data-tip="Clear"><i class="fas fa-eraser"></i></button>
                    </div>
                    
                    <!-- Image Info Overlay -->
                    <div class="image-overlay-info" id="imageOverlayInfo">
                        <span class="highlight"><?php echo basename($current_image); ?></span>
                        <span style="margin:0 8px;">|</span>
                        <?php echo date('Y-m-d H:i:s'); ?>
                        <span style="margin:0 8px;">|</span>
                        Zoom: <span id="zoomLevelDisplay">100%</span>
                    </div>
                <?php else: ?>
                    <div class="no-image">
                        <i class="fas fa-image"></i>
                        <h3>No Images Available</h3>
                        <p>Select a study with images from the list below</p>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Study Panel -->
            <div class="study-panel" id="studyPanel">
                <div class="panel-title"><i class="fas fa-info-circle"></i> Study Information</div>
                
                <?php if ($order_data): ?>
                    <div class="info-row"><span class="label">Study ID</span><span class="value"><?php echo htmlspecialchars($order_data['order_number']); ?></span></div>
                    <div class="info-row"><span class="label">Patient</span><span class="value"><?php echo htmlspecialchars($order_data['patient_name'] ?? 'N/A'); ?></span></div>
                    <div class="info-row"><span class="label">MRN</span><span class="value"><?php echo htmlspecialchars($order_data['mr_number'] ?? 'N/A'); ?></span></div>
                    <div class="info-row"><span class="label">DOB / Gender</span><span class="value"><?php echo htmlspecialchars($order_data['date_of_birth'] ?? 'N/A'); ?> / <?php echo ucfirst($order_data['gender'] ?? 'N/A'); ?></span></div>
                    <div class="info-row"><span class="label">Modality</span><span class="value"><?php echo strtoupper($order_data['modality'] ?? 'N/A'); ?></span></div>
                    <div class="info-row"><span class="label">Body Part</span><span class="value"><?php echo htmlspecialchars($order_data['body_part'] ?? 'N/A'); ?></span></div>
                    <div class="info-row"><span class="label">Service</span><span class="value"><?php echo htmlspecialchars($order_data['service_name'] ?? 'N/A'); ?></span></div>
                    <div class="info-row"><span class="label">Status</span><span class="value"><?php echo ucfirst(str_replace('_', ' ', $order_data['status'] ?? 'N/A')); ?></span></div>
                    <div class="info-row"><span class="label">Ordered By</span><span class="value"><?php echo htmlspecialchars($order_data['doctor_name'] ?? 'N/A'); ?></span></div>
                    <div class="info-row"><span class="label">Created</span><span class="value"><?php echo date('Y-m-d H:i', strtotime($order_data['created_at'] ?? 'now')); ?></span></div>
                    <div class="info-row"><span class="label">Images</span><span class="value"><?php echo count($images); ?></span></div>
                    
                    <?php if (!empty($order_data['interpretation_report'])): ?>
                        <div class="panel-title" style="margin-top:16px;"><i class="fas fa-file-medical-alt"></i> Report</div>
                        <div style="font-size:12px;color:#c0c0d0;max-height:150px;overflow-y:auto;background:#0a0a0f;padding:8px;border-radius:4px;">
                            <?php echo nl2br(htmlspecialchars($order_data['interpretation_report'])); ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
                
                <?php if (!empty($study_list)): ?>
                    <div class="panel-title" style="margin-top:16px;"><i class="fas fa-folder-open"></i> Other Studies</div>
                    <?php foreach ($study_list as $study): ?>
                        <div style="padding:8px;border-bottom:1px solid #1a1a2e;font-size:12px;cursor:pointer;" 
                             onclick="window.location.href='pacs_viewer.php?order_id=<?php echo $study['id']; ?>'">
                            <div style="color:#c0c0d0;"><?php echo htmlspecialchars($study['order_number']); ?></div>
                            <div style="color:#6b7280;font-size:10px;"><?php echo htmlspecialchars($study['service_name'] ?? ''); ?> • <?php echo strtoupper($study['modality'] ?? ''); ?></div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Thumbnail Strip -->
        <div class="thumbnail-strip" id="thumbnailStrip">
            <?php if (count($images) > 0): ?>
                <?php foreach ($images as $idx => $img): ?>
                    <div class="thumbnail-item <?php echo $idx === $image_index ? 'active' : ''; ?>" 
                         onclick="navigateToImage(<?php echo $idx; ?>)">
                        <img src="<?php echo htmlspecialchars(getImageUrl($img)); ?>" 
                             alt="Thumbnail <?php echo $idx + 1; ?>"
                             onerror="this.style.display='none';this.parentElement.innerHTML='<span class=\'thumb-placeholder\'><i class=\'fas fa-image\'></i></span>';">
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div style="color:#3a3a5e;font-size:13px;padding:8px;width:100%;text-align:center;">
                    <i class="fas fa-info-circle"></i> No images in this study
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Recent Orders (if no order selected) -->
<?php if ($order_id === 0 && !empty($recent_orders)): ?>
    <div style="position:fixed;bottom:100px;right:24px;z-index:100;max-width:300px;background:#1a1a2e;border-radius:8px;border:1px solid #2a2a3e;padding:12px;box-shadow:0 10px 30px rgba(0,0,0,0.5);">
        <div style="font-size:12px;font-weight:600;color:#6b7280;margin-bottom:8px;">RECENT STUDIES</div>
        <?php foreach (array_slice($recent_orders, 0, 5) as $order): ?>
            <div style="padding:6px 0;border-bottom:1px solid #1a1a2e;font-size:12px;cursor:pointer;" 
                 onclick="window.location.href='pacs_viewer.php?order_id=<?php echo $order['id']; ?>'">
                <div style="color:#c0c0d0;"><?php echo htmlspecialchars($order['order_number']); ?></div>
                <div style="color:#6b7280;font-size:10px;"><?php echo htmlspecialchars($order['patient_name'] ?? 'Unknown'); ?> • <?php echo strtoupper($order['modality'] ?? ''); ?></div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<script>
// ============================================
// PACS Viewer JavaScript
// ============================================

(function() {
    'use strict';
    
    // DOM references
    const image = document.getElementById('mainImage');
    const container = document.getElementById('canvasContainer');
    const loadingSpinner = document.getElementById('loadingSpinner');
    const zoomLevelDisplay = document.getElementById('zoomLevelDisplay');
    const currentIndexSpan = document.getElementById('currentImageIndex');
    const totalImagesSpan = document.getElementById('totalImages');
    const prevBtn = document.getElementById('prevImageBtn');
    const nextBtn = document.getElementById('nextImageBtn');
    
    // State
    let zoomLevel = 1;
    let minZoom = 0.1;
    let maxZoom = 10;
    let rotation = 0;
    let flipH = false;
    let flipV = false;
    let brightness = 1;
    let contrast = 1;
    let panX = 0;
    let panY = 0;
    let isDragging = false;
    let dragStartX = 0;
    let dragStartY = 0;
    let dragStartPanX = 0;
    let dragStartPanY = 0;
    let currentImageIndex = <?php echo $image_index; ?>;
    let totalImages = <?php echo count($images); ?>;
    
    // Initialize image if exists
    if (image) {
        applyTransform();
        updateNavigationButtons();
    }
    
    // ============================================
    // IMAGE TRANSFORMS
    // ============================================
    
    function applyTransform() {
        if (!image) return;
        let transform = `scale(${zoomLevel}) rotate(${rotation}deg)`;
        if (flipH) transform += ' scaleX(-1)';
        if (flipV) transform += ' scaleY(-1)';
        if (panX !== 0 || panY !== 0) {
            transform += ` translate(${panX}px, ${panY}px)`;
        }
        image.style.transform = transform;
        image.style.filter = `brightness(${brightness}) contrast(${contrast})`;
        if (zoomLevelDisplay) {
            zoomLevelDisplay.textContent = Math.round(zoomLevel * 100) + '%';
        }
    }
    
    function resetView() {
        zoomLevel = 1;
        rotation = 0;
        flipH = false;
        flipV = false;
        brightness = 1;
        contrast = 1;
        panX = 0;
        panY = 0;
        applyTransform();
    }
    
    function zoomIn() {
        zoomLevel = Math.min(zoomLevel * 1.2, maxZoom);
        applyTransform();
    }
    
    function zoomOut() {
        zoomLevel = Math.max(zoomLevel / 1.2, minZoom);
        applyTransform();
    }
    
    function zoomFit() {
        if (!image || !container) return;
        const containerRect = container.getBoundingClientRect();
        const imgRect = image.getBoundingClientRect();
        const containerWidth = containerRect.width - 40;
        const containerHeight = containerRect.height - 40;
        const imgWidth = imgRect.width || image.naturalWidth || 800;
        const imgHeight = imgRect.height || image.naturalHeight || 600;
        const zoomX = containerWidth / imgWidth;
        const zoomY = containerHeight / imgHeight;
        zoomLevel = Math.min(zoomX, zoomY, 1);
        panX = 0;
        panY = 0;
        applyTransform();
    }
    
    function rotateLeft() {
        rotation -= 90;
        applyTransform();
    }
    
    function rotateRight() {
        rotation += 90;
        applyTransform();
    }
    
    function flipHorizontal() {
        flipH = !flipH;
        applyTransform();
    }
    
    function flipVertical() {
        flipV = !flipV;
        applyTransform();
    }
    
    function adjustBrightness(delta) {
        brightness = Math.max(0.1, Math.min(3, brightness + delta));
        applyTransform();
    }
    
    function adjustContrast(delta) {
        contrast = Math.max(0.1, Math.min(3, contrast + delta));
        applyTransform();
    }
    
    // ============================================
    // NAVIGATION
    // ============================================
    
    function navigateImage(direction) {
        const newIndex = currentImageIndex + direction;
        if (newIndex < 0 || newIndex >= totalImages) return;
        navigateToImage(newIndex);
    }
    
    function navigateToImage(index) {
        if (index < 0 || index >= totalImages) return;
        currentImageIndex = index;
        
        // Update URL without reload
        const url = new URL(window.location.href);
        url.searchParams.set('index', index);
        window.history.pushState({}, '', url);
        
        // Update image
        const images = <?php echo json_encode(array_values($images)); ?>;
        if (images[index]) {
            if (loadingSpinner) loadingSpinner.style.display = 'block';
            image.src = images[index];
            image.onload = function() {
                if (loadingSpinner) loadingSpinner.style.display = 'none';
                resetView();
                updateNavigationButtons();
                updateThumbnails();
                updateImageCounter();
                updateOverlayInfo(images[index]);
            };
            image.onerror = function() {
                if (loadingSpinner) loadingSpinner.style.display = 'none';
                console.error('Failed to load image:', images[index]);
            };
        }
    }
    
    function updateNavigationButtons() {
        if (prevBtn) {
            prevBtn.classList.toggle('disabled', currentImageIndex <= 0);
            prevBtn.disabled = currentImageIndex <= 0;
        }
        if (nextBtn) {
            nextBtn.classList.toggle('disabled', currentImageIndex >= totalImages - 1);
            nextBtn.disabled = currentImageIndex >= totalImages - 1;
        }
    }
    
    function updateThumbnails() {
        document.querySelectorAll('.thumbnail-item').forEach((item, idx) => {
            item.classList.toggle('active', idx === currentImageIndex);
        });
    }
    
    function updateImageCounter() {
        if (currentIndexSpan) currentIndexSpan.textContent = currentImageIndex + 1;
        if (totalImagesSpan) totalImagesSpan.textContent = totalImages;
    }
    
    function updateOverlayInfo(imagePath) {
        const overlay = document.getElementById('imageOverlayInfo');
        if (overlay) {
            const filename = imagePath.split('/').pop() || imagePath;
            overlay.innerHTML = `
                <span class="highlight">${filename}</span>
                <span style="margin:0 8px;">|</span>
                ${new Date().toLocaleString()}
                <span style="margin:0 8px;">|</span>
                Zoom: <span id="zoomLevelDisplay">${Math.round(zoomLevel * 100)}%</span>
            `;
        }
    }
    
    // ============================================
    // KEYBOARD SHORTCUTS
    // ============================================
    
    document.addEventListener('keydown', function(e) {
        switch(e.key) {
            case 'ArrowLeft': e.preventDefault(); navigateImage(-1); break;
            case 'ArrowRight': e.preventDefault(); navigateImage(1); break;
            case '+': e.preventDefault(); zoomIn(); break;
            case '-': e.preventDefault(); zoomOut(); break;
            case 'r': e.preventDefault(); resetView(); break;
            case 'R': e.preventDefault(); rotateRight(); break;
            case 'l': e.preventDefault(); rotateLeft(); break;
            case 'f': e.preventDefault(); flipHorizontal(); break;
            case 'v': e.preventDefault(); flipVertical(); break;
            case 'Escape': resetView(); break;
            case 'p': e.preventDefault(); window.print(); break;
        }
    });
    
    // ============================================
    // MOUSE DRAG (Pan)
    // ============================================
    
    if (image) {
        image.addEventListener('mousedown', function(e) {
            if (e.button === 0) {
                isDragging = true;
                dragStartX = e.clientX;
                dragStartY = e.clientY;
                dragStartPanX = panX;
                dragStartPanY = panY;
                image.style.cursor = 'grabbing';
            }
        });
        
        document.addEventListener('mousemove', function(e) {
            if (!isDragging) return;
            const dx = e.clientX - dragStartX;
            const dy = e.clientY - dragStartY;
            panX = dragStartPanX + dx;
            panY = dragStartPanY + dy;
            applyTransform();
        });
        
        document.addEventListener('mouseup', function() {
            if (isDragging) {
                isDragging = false;
                if (image) image.style.cursor = 'grab';
            }
        });
        
        // Mouse wheel zoom
        image.addEventListener('wheel', function(e) {
            e.preventDefault();
            if (e.deltaY < 0) {
                zoomIn();
            } else {
                zoomOut();
            }
        }, { passive: false });
    }
    
    // ============================================
    // TOUCH SUPPORT
    // ============================================
    
    let touchStartDistance = 0;
    let touchStartZoom = 1;
    
    if (image) {
        image.addEventListener('touchstart', function(e) {
            if (e.touches.length === 2) {
                const touch1 = e.touches[0];
                const touch2 = e.touches[1];
                touchStartDistance = Math.hypot(touch1.clientX - touch2.clientX, touch1.clientY - touch2.clientY);
                touchStartZoom = zoomLevel;
            }
        }, { passive: true });
        
        image.addEventListener('touchmove', function(e) {
            if (e.touches.length === 2) {
                e.preventDefault();
                const touch1 = e.touches[0];
                const touch2 = e.touches[1];
                const distance = Math.hypot(touch1.clientX - touch2.clientX, touch1.clientY - touch2.clientY);
                const scale = distance / touchStartDistance;
                zoomLevel = Math.min(Math.max(touchStartZoom * scale, minZoom), maxZoom);
                applyTransform();
            }
        }, { passive: false });
    }
    
    // ============================================
    // TOOLBAR BUTTONS
    // ============================================
    
    document.getElementById('zoomInBtn')?.addEventListener('click', zoomIn);
    document.getElementById('zoomOutBtn')?.addEventListener('click', zoomOut);
    document.getElementById('zoomFitBtn')?.addEventListener('click', zoomFit);
    document.getElementById('resetViewBtn')?.addEventListener('click', resetView);
    document.getElementById('rotateLeftBtn')?.addEventListener('click', rotateLeft);
    document.getElementById('rotateRightBtn')?.addEventListener('click', rotateRight);
    document.getElementById('flipHorizontalBtn')?.addEventListener('click', flipHorizontal);
    document.getElementById('flipVerticalBtn')?.addEventListener('click', flipVertical);
    document.getElementById('brightnessUpBtn')?.addEventListener('click', function() { adjustBrightness(0.1); });
    document.getElementById('brightnessDownBtn')?.addEventListener('click', function() { adjustBrightness(-0.1); });
    document.getElementById('contrastUpBtn')?.addEventListener('click', function() { adjustContrast(0.1); });
    document.getElementById('contrastDownBtn')?.addEventListener('click', function() { adjustContrast(-0.1); });
    
    // Toggle panel
    document.getElementById('togglePanelBtn')?.addEventListener('click', function() {
        document.getElementById('studyPanel').classList.toggle('visible');
    });
    
    // Print
    document.getElementById('printBtn')?.addEventListener('click', function() {
        window.print();
    });
    
    // Download
    document.getElementById('downloadBtn')?.addEventListener('click', function() {
        if (image) {
            const link = document.createElement('a');
            link.download = 'radiology_image.png';
            link.href = image.src;
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }
    });
    
    // ============================================
    // MEASUREMENT TOOLS (Visual only)
    // ============================================
    
    let measurementMode = null;
    
    document.getElementById('measureDistanceBtn')?.addEventListener('click', function() {
        this.classList.toggle('active');
        if (this.classList.contains('active')) {
            measurementMode = 'distance';
            document.getElementById('measureAngleBtn').classList.remove('active');
            document.getElementById('measureAreaBtn').classList.remove('active');
            document.getElementById('annotateBtn').classList.remove('active');
        } else {
            measurementMode = null;
        }
    });
    
    document.getElementById('measureAngleBtn')?.addEventListener('click', function() {
        this.classList.toggle('active');
        if (this.classList.contains('active')) {
            measurementMode = 'angle';
            document.getElementById('measureDistanceBtn').classList.remove('active');
            document.getElementById('measureAreaBtn').classList.remove('active');
            document.getElementById('annotateBtn').classList.remove('active');
        } else {
            measurementMode = null;
        }
    });
    
    document.getElementById('measureAreaBtn')?.addEventListener('click', function() {
        this.classList.toggle('active');
        if (this.classList.contains('active')) {
            measurementMode = 'area';
            document.getElementById('measureDistanceBtn').classList.remove('active');
            document.getElementById('measureAngleBtn').classList.remove('active');
            document.getElementById('annotateBtn').classList.remove('active');
        } else {
            measurementMode = null;
        }
    });
    
    document.getElementById('annotateBtn')?.addEventListener('click', function() {
        this.classList.toggle('active');
        if (this.classList.contains('active')) {
            measurementMode = 'annotate';
            document.getElementById('measureDistanceBtn').classList.remove('active');
            document.getElementById('measureAngleBtn').classList.remove('active');
            document.getElementById('measureAreaBtn').classList.remove('active');
        } else {
            measurementMode = null;
        }
    });
    
    document.getElementById('clearMeasurementsBtn')?.addEventListener('click', function() {
        // Remove any measurement overlays
        document.querySelectorAll('.measurement-overlay').forEach(el => el.remove());
        measurementMode = null;
        document.querySelectorAll('.measurement-tools .btn').forEach(btn => btn.classList.remove('active'));
    });
    
    // ============================================
    // FULLSCREEN
    // ============================================
    
    document.addEventListener('dblclick', function(e) {
        if (e.target.closest('.viewer-canvas-container')) {
            if (!document.fullscreenElement) {
                document.documentElement.requestFullscreen().catch(() => {});
            } else {
                document.exitFullscreen().catch(() => {});
            }
        }
    });
    
    // ============================================
    // WINDOW RESIZE
    // ============================================
    
    window.addEventListener('resize', function() {
        // Recalculate zoom fit if needed
    });
    
    // ============================================
    // INITIAL SETUP
    // ============================================
    
    // Show panel by default if there's data
    if (<?php echo $order_id > 0 && !empty($order_data) ? 'true' : 'false'; ?>) {
        document.getElementById('studyPanel')?.classList.add('visible');
    }
    
    // Load image if exists
    if (image && image.src) {
        image.onload = function() {
            if (loadingSpinner) loadingSpinner.style.display = 'none';
        };
        image.onerror = function() {
            if (loadingSpinner) loadingSpinner.style.display = 'none';
            console.error('Failed to load image');
        };
    }
    
    console.log('PACS Viewer initialized');
    
})();
</script>

</body>
</html>