<?php
/**
 * image_archive.php
 * 
 * HMS ERP - Radiology Image Archive
 * 
 * Features:
 * - Browse all radiology images
 * - Search by patient, date, modality
 * - Filter by date range, modality, body part
 * - View image thumbnails
 * - Sort by date, modality, patient
 * - Batch image management
 * - Export images
 * - Delete/Archive images
 * - Image metadata display
 * - Quick access to PACS Viewer
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

// Handle delete image
if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $order_id = (int)($_POST['order_id'] ?? 0);
    $image_path = trim($_POST['image_path'] ?? '');
    
    if ($order_id <= 0 || empty($image_path)) {
        $error_message = 'Invalid request.';
    } else {
        try {
            // Get current image paths
            $sql = "SELECT image_attachment_path FROM radiology_orders WHERE id = :id AND deleted_at IS NULL";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':id' => $order_id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$row) {
                throw new Exception('Order not found.');
            }
            
            $paths = [];
            if (!empty($row['image_attachment_path'])) {
                $path = $row['image_attachment_path'];
                if (strpos($path, '[') === 0 || strpos($path, '{') === 0) {
                    $paths = json_decode($path, true);
                    if (!is_array($paths)) $paths = [];
                } else if (strpos($path, ',') !== false) {
                    $paths = array_map('trim', explode(',', $path));
                } else {
                    $paths = [$path];
                }
            }
            
            // Remove the image
            $paths = array_filter($paths, function($p) use ($image_path) {
                return $p !== $image_path;
            });
            
            // Update database
            $new_path = !empty($paths) ? json_encode(array_values($paths)) : null;
            $sql = "UPDATE radiology_orders SET image_attachment_path = :path, updated_at = NOW() WHERE id = :id";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':path' => $new_path,
                ':id' => $order_id
            ]);
            
            // Try to delete physical file
            $full_path = $_SERVER['DOCUMENT_ROOT'] . '/uploads/radiology/' . ltrim($image_path, '/');
            if (file_exists($full_path)) {
                @unlink($full_path);
            }
            
            $success_message = 'Image deleted successfully.';
        } catch (Exception $e) {
            $error_message = 'Error: ' . $e->getMessage();
            error_log('Image Archive Delete Error: ' . $e->getMessage());
        }
    }
}

// Handle batch delete
if ($action === 'batch_delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $order_ids = $_POST['order_ids'] ?? [];
    
    if (empty($order_ids)) {
        $error_message = 'Please select at least one item.';
    } else {
        try {
            $placeholders = implode(',', array_fill(0, count($order_ids), '?'));
            $sql = "UPDATE radiology_orders SET image_attachment_path = NULL, updated_at = NOW() 
                    WHERE id IN ($placeholders) AND deleted_at IS NULL";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($order_ids);
            
            $success_message = count($order_ids) . ' item(s) archived successfully.';
        } catch (PDOException $e) {
            $error_message = 'Database error: ' . $e->getMessage();
            error_log('Batch Delete Error: ' . $e->getMessage());
        }
    }
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

function formatFileSize($bytes) {
    if ($bytes === null || $bytes === 0) return 'N/A';
    $units = ['B', 'KB', 'MB', 'GB'];
    $i = floor(log($bytes, 1024));
    return round($bytes / pow(1024, $i), 1) . ' ' . $units[$i];
}

// ============================================
// 4. DATA FETCHING
// ============================================

// Build filter parameters
$filter_modality = $_GET['modality'] ?? '';
$filter_body_part = $_GET['body_part'] ?? '';
$filter_date_from = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
$filter_date_to = $_GET['date_to'] ?? date('Y-m-d');
$filter_status = $_GET['status'] ?? '';
$search = trim($_GET['search'] ?? '');
$sort = $_GET['sort'] ?? 'newest';
$view_mode = $_GET['view'] ?? 'grid'; // grid, list

// Branch filter
$branch_filter = "";
$params = [];
if (!$is_global_admin && $branch_id > 0) {
    $branch_filter = " AND ro.branch_id = :branch_id";
    $params[':branch_id'] = $branch_id;
}

// Additional filters
if (!empty($filter_modality)) {
    $branch_filter .= " AND ro.modality = :modality_val";
    $params[':modality_val'] = $filter_modality;
}
if (!empty($filter_body_part)) {
    $branch_filter .= " AND ro.body_part LIKE :body_part_val";
    $params[':body_part_val'] = "%{$filter_body_part}%";
}
if (!empty($filter_date_from) && !empty($filter_date_to)) {
    $branch_filter .= " AND DATE(ro.created_at) BETWEEN :date_from AND :date_to";
    $params[':date_from'] = $filter_date_from;
    $params[':date_to'] = $filter_date_to;
}
if (!empty($filter_status)) {
    $branch_filter .= " AND ro.status = :status_val";
    $params[':status_val'] = $filter_status;
}
if (!empty($search)) {
    $branch_filter .= " AND (ro.order_number LIKE :search_val OR p.first_name LIKE :search_val OR p.last_name LIKE :search_val OR ro.service_name LIKE :search_val)";
    $params[':search_val'] = "%{$search}%";
}

// Sorting
$sort_clause = "ORDER BY ro.created_at DESC";
switch ($sort) {
    case 'oldest':
        $sort_clause = "ORDER BY ro.created_at ASC";
        break;
    case 'patient':
        $sort_clause = "ORDER BY p.first_name ASC, p.last_name ASC";
        break;
    case 'modality':
        $sort_clause = "ORDER BY ro.modality ASC";
        break;
    case 'newest':
    default:
        $sort_clause = "ORDER BY ro.created_at DESC";
        break;
}

// Fetch images with their orders
$sql = "SELECT 
            ro.id, ro.order_number, ro.service_name, ro.service_code,
            ro.modality, ro.body_part, ro.priority, ro.status,
            ro.image_attachment_path, ro.interpretation_report,
            ro.created_at, ro.updated_at, ro.performed_at, ro.reviewed_at,
            CONCAT(p.first_name, ' ', COALESCE(p.middle_name, ''), ' ', p.last_name) as patient_name,
            p.mr_number, p.date_of_birth, p.gender,
            CONCAT(u.first_name, ' ', u.last_name) as doctor_name
        FROM radiology_orders ro
        LEFT JOIN patients p ON ro.patient_id = p.id
        LEFT JOIN users u ON ro.doctor_id = u.id
        WHERE ro.image_attachment_path IS NOT NULL 
        AND ro.image_attachment_path != ''
        AND ro.deleted_at IS NULL" . $branch_filter . "
        " . $sort_clause . "
        LIMIT 100";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$archives = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get statistics
$stats_params = [];
$stats_filter = "";
if (!$is_global_admin && $branch_id > 0) {
    $stats_filter .= " AND branch_id = :stats_branch_id";
    $stats_params[':stats_branch_id'] = $branch_id;
}

$sql = "SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN modality = 'xray' THEN 1 ELSE 0 END) as xray,
            SUM(CASE WHEN modality = 'ct' THEN 1 ELSE 0 END) as ct,
            SUM(CASE WHEN modality = 'mri' THEN 1 ELSE 0 END) as mri,
            SUM(CASE WHEN modality = 'ultrasound' THEN 1 ELSE 0 END) as ultrasound,
            SUM(CASE WHEN modality = 'mammography' THEN 1 ELSE 0 END) as mammography,
            SUM(CASE WHEN modality = 'fluoroscopy' THEN 1 ELSE 0 END) as fluoroscopy,
            SUM(CASE WHEN modality = 'angiography' THEN 1 ELSE 0 END) as angiography
        FROM radiology_orders
        WHERE image_attachment_path IS NOT NULL 
        AND image_attachment_path != ''
        AND deleted_at IS NULL" . $stats_filter;
$stmt = $pdo->prepare($sql);
$stmt->execute($stats_params);
$stats = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$stats) {
    $stats = ['total' => 0, 'xray' => 0, 'ct' => 0, 'mri' => 0, 'ultrasound' => 0, 'mammography' => 0, 'fluoroscopy' => 0, 'angiography' => 0];
}

// Get unique body parts for filter
$body_parts = [];
$sql = "SELECT DISTINCT body_part FROM radiology_orders 
        WHERE body_part IS NOT NULL AND body_part != '' AND deleted_at IS NULL 
        ORDER BY body_part ASC LIMIT 50";
$body_parts = $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Image Archive | Radiology | HMS ERP</title>
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
        
        /* Stats */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            gap: 10px;
            margin-bottom: 20px;
        }
        .stat-card {
            background: white;
            padding: 12px 14px;
            border-radius: 10px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08);
            text-align: center;
            border-left: 3px solid #7209b7;
        }
        .stat-card .stat-number {
            font-size: 22px;
            font-weight: 700;
        }
        .stat-card .stat-label {
            font-size: 11px;
            color: #6b7280;
        }
        .stat-card.xray { border-left-color: #2563eb; }
        .stat-card.ct { border-left-color: #7c3aed; }
        .stat-card.mri { border-left-color: #db2777; }
        .stat-card.ultrasound { border-left-color: #059669; }
        .stat-card.mammography { border-left-color: #d97706; }
        .stat-card.total { border-left-color: #7209b7; }
        
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
        .view-toggle {
            display: flex;
            gap: 4px;
            background: #f3f4f6;
            padding: 3px;
            border-radius: 6px;
        }
        .view-toggle .btn {
            padding: 4px 10px;
            border: none;
            background: transparent;
            border-radius: 4px;
            font-size: 12px;
        }
        .view-toggle .btn.active {
            background: white;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        
        /* Grid View */
        .image-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
            gap: 16px;
        }
        .image-card {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08);
            transition: transform 0.2s, box-shadow 0.2s;
            cursor: pointer;
        }
        .image-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.12);
        }
        .image-card .thumb-container {
            height: 180px;
            background: #0a0a0f;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            overflow: hidden;
        }
        .image-card .thumb-container img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .image-card .thumb-container .no-thumb {
            color: #3a3a5e;
            font-size: 40px;
        }
        .image-card .thumb-container .image-count-badge {
            position: absolute;
            top: 8px;
            right: 8px;
            background: rgba(0,0,0,0.7);
            color: white;
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 11px;
        }
        .image-card .thumb-container .modality-badge {
            position: absolute;
            bottom: 8px;
            left: 8px;
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: 600;
            background: rgba(0,0,0,0.7);
            color: white;
        }
        .image-card .card-body {
            padding: 12px 14px;
        }
        .image-card .card-body .patient-name {
            font-weight: 600;
            font-size: 14px;
        }
        .image-card .card-body .order-number {
            font-size: 12px;
            color: #6b7280;
        }
        .image-card .card-body .meta-row {
            display: flex;
            justify-content: space-between;
            font-size: 12px;
            color: #6b7280;
            margin-top: 4px;
        }
        .image-card .card-body .actions {
            margin-top: 10px;
            display: flex;
            gap: 4px;
            flex-wrap: wrap;
        }
        .image-card .card-body .actions .btn {
            padding: 2px 8px;
            font-size: 11px;
        }
        
        /* List View */
        .image-list {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        .image-list-item {
            background: white;
            border-radius: 8px;
            padding: 12px 16px;
            display: flex;
            align-items: center;
            gap: 16px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08);
            transition: background 0.2s;
        }
        .image-list-item:hover {
            background: #f9fafb;
        }
        .image-list-item .thumb-small {
            width: 60px;
            height: 60px;
            border-radius: 6px;
            background: #0a0a0f;
            overflow: hidden;
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .image-list-item .thumb-small img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .image-list-item .thumb-small .no-thumb {
            color: #3a3a5e;
            font-size: 20px;
        }
        .image-list-item .info {
            flex: 1;
            min-width: 0;
        }
        .image-list-item .info .patient-name {
            font-weight: 600;
        }
        .image-list-item .info .order-number {
            font-size: 12px;
            color: #6b7280;
        }
        .image-list-item .info .details {
            font-size: 12px;
            color: #6b7280;
        }
        .image-list-item .info .details span {
            margin-right: 12px;
        }
        .image-list-item .actions {
            display: flex;
            gap: 4px;
            flex-shrink: 0;
        }
        .image-list-item .actions .btn {
            padding: 2px 8px;
            font-size: 11px;
        }
        
        .no-data {
            text-align: center;
            padding: 60px 20px;
            color: #6b7280;
        }
        .no-data i {
            font-size: 48px;
            display: block;
            margin-bottom: 12px;
            color: #d1d5db;
        }
        
        /* Selection checkbox */
        .select-checkbox {
            width: 18px;
            height: 18px;
            accent-color: #7209b7;
            cursor: pointer;
        }
        
        .batch-actions {
            background: white;
            padding: 12px 18px;
            border-radius: 8px;
            margin-bottom: 16px;
            display: none;
            align-items: center;
            gap: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08);
        }
        .batch-actions.visible {
            display: flex;
        }
        .batch-actions .selected-count {
            font-weight: 600;
            font-size: 14px;
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
            .image-grid { grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); }
            .stats-grid { grid-template-columns: repeat(3, 1fr); }
            .page-header h1 { font-size: 20px; }
            .image-list-item { flex-wrap: wrap; }
            .image-list-item .info { width: 100%; }
            .image-list-item .actions { width: 100%; justify-content: flex-end; }
        }
        @media (max-width: 480px) {
            .stats-grid { grid-template-columns: 1fr 1fr; }
            .image-grid { grid-template-columns: 1fr 1fr; }
        }
        
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: #f3f4f6; border-radius: 4px; }
        ::-webkit-scrollbar-thumb { background: #e5e7eb; border-radius: 4px; }
        ::-webkit-scrollbar-thumb:hover { background: #6b7280; }
        
        /* Checkbox styling in grid */
        .image-card .card-checkbox {
            position: absolute;
            top: 8px;
            left: 8px;
            z-index: 2;
        }
        .image-card .card-checkbox input {
            width: 18px;
            height: 18px;
            accent-color: #7209b7;
            cursor: pointer;
        }
        .image-card .thumb-container {
            position: relative;
        }
        
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
        .status-completed { background: #d1fae5; color: #065f46; }
        .status-reviewed { background: #d1fae5; color: #065f46; }
        .status-in_progress { background: #fef3c7; color: #d97706; }
        .status-scheduled { background: #dbeafe; color: #1e40af; }
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
                    <i class="fas fa-archive"></i> Image Archive
                    <span class="subtitle"><?php echo $stats['total'] ?? 0; ?> images</span>
                </h1>
            </div>
            <div>
                <button class="btn" onclick="window.location.reload();">
                    <i class="fas fa-sync-alt"></i> Refresh
                </button>
                <a href="pacs_viewer.php" class="btn btn-primary">
                    <i class="fas fa-eye"></i> PACS Viewer
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
            <div class="stat-card total">
                <div class="stat-number"><?php echo $stats['total'] ?? 0; ?></div>
                <div class="stat-label"><i class="fas fa-images"></i> Total</div>
            </div>
            <div class="stat-card xray">
                <div class="stat-number"><?php echo $stats['xray'] ?? 0; ?></div>
                <div class="stat-label"><i class="fas fa-x-ray"></i> X-Ray</div>
            </div>
            <div class="stat-card ct">
                <div class="stat-number"><?php echo $stats['ct'] ?? 0; ?></div>
                <div class="stat-label"><i class="fas fa-microscope"></i> CT</div>
            </div>
            <div class="stat-card mri">
                <div class="stat-number"><?php echo $stats['mri'] ?? 0; ?></div>
                <div class="stat-label"><i class="fas fa-magnet"></i> MRI</div>
            </div>
            <div class="stat-card ultrasound">
                <div class="stat-number"><?php echo $stats['ultrasound'] ?? 0; ?></div>
                <div class="stat-label"><i class="fas fa-wave-square"></i> Ultrasound</div>
            </div>
            <div class="stat-card mammography">
                <div class="stat-number"><?php echo $stats['mammography'] ?? 0; ?></div>
                <div class="stat-label"><i class="fas fa-x-ray"></i> Mammography</div>
            </div>
        </div>

        <!-- Filter Bar -->
        <div class="filter-bar">
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
                <span class="filter-label">Body Part</span>
                <select id="filterBodyPart" onchange="applyFilters()">
                    <option value="">All</option>
                    <?php foreach ($body_parts as $part): ?>
                        <?php if (!empty($part)): ?>
                            <option value="<?php echo htmlspecialchars($part); ?>" <?php echo $filter_body_part === $part ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($part); ?>
                            </option>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <span class="filter-label">Status</span>
                <select id="filterStatus" onchange="applyFilters()">
                    <option value="">All</option>
                    <option value="scheduled" <?php echo $filter_status === 'scheduled' ? 'selected' : ''; ?>>Scheduled</option>
                    <option value="in_progress" <?php echo $filter_status === 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                    <option value="completed" <?php echo $filter_status === 'completed' ? 'selected' : ''; ?>>Completed</option>
                    <option value="reviewed" <?php echo $filter_status === 'reviewed' ? 'selected' : ''; ?>>Reviewed</option>
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
            <div>
                <span class="filter-label">Sort</span>
                <select id="filterSort" onchange="applyFilters()">
                    <option value="newest" <?php echo $sort === 'newest' ? 'selected' : ''; ?>>Newest First</option>
                    <option value="oldest" <?php echo $sort === 'oldest' ? 'selected' : ''; ?>>Oldest First</option>
                    <option value="patient" <?php echo $sort === 'patient' ? 'selected' : ''; ?>>By Patient</option>
                    <option value="modality" <?php echo $sort === 'modality' ? 'selected' : ''; ?>>By Modality</option>
                </select>
            </div>
            <button class="btn btn-primary" onclick="applyFilters()"><i class="fas fa-search"></i> Filter</button>
            <button class="btn" onclick="resetFilters()"><i class="fas fa-times"></i> Reset</button>
            <div class="view-toggle">
                <button class="btn <?php echo $view_mode === 'grid' ? 'active' : ''; ?>" onclick="setView('grid')" title="Grid View">
                    <i class="fas fa-th"></i>
                </button>
                <button class="btn <?php echo $view_mode === 'list' ? 'active' : ''; ?>" onclick="setView('list')" title="List View">
                    <i class="fas fa-list"></i>
                </button>
            </div>
        </div>

        <!-- Batch Actions -->
        <div class="batch-actions" id="batchActions">
            <span class="selected-count"><span id="selectedCount">0</span> selected</span>
            <button class="btn btn-danger btn-sm" onclick="batchDelete()">
                <i class="fas fa-trash"></i> Delete Selected
            </button>
            <button class="btn btn-sm" onclick="clearSelection()">
                <i class="fas fa-times"></i> Clear Selection
            </button>
        </div>

        <!-- Archive Content -->
        <?php if (empty($archives)): ?>
            <div class="no-data">
                <i class="fas fa-archive"></i>
                <h3>No Images Found</h3>
                <p>Try adjusting your filters or upload images from radiology orders.</p>
            </div>
        <?php else: ?>
            <div id="archiveContainer" class="<?php echo $view_mode === 'grid' ? 'image-grid' : 'image-list'; ?>">
                <?php foreach ($archives as $archive): 
                    $image_count = getImageCount($archive['image_attachment_path']);
                    $first_image = getFirstImage($archive['image_attachment_path']);
                ?>
                    <?php if ($view_mode === 'grid'): ?>
                        <!-- Grid View Card -->
                        <div class="image-card" data-id="<?php echo $archive['id']; ?>">
                            <div class="thumb-container" onclick="window.location.href='pacs_viewer.php?order_id=<?php echo $archive['id']; ?>'">
                                <?php if ($first_image): ?>
                                    <img src="<?php echo htmlspecialchars(getImageUrl($first_image)); ?>" 
                                         alt="<?php echo htmlspecialchars($archive['order_number']); ?>"
                                         loading="lazy"
                                         onerror="this.style.display='none';this.parentElement.querySelector('.no-thumb').style.display='flex';">
                                    <div class="no-thumb" style="display:none;position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);">
                                        <i class="fas fa-image"></i>
                                    </div>
                                <?php else: ?>
                                    <div class="no-thumb" style="display:flex;position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);">
                                        <i class="fas fa-image"></i>
                                    </div>
                                <?php endif; ?>
                                <?php if ($image_count > 1): ?>
                                    <span class="image-count-badge">+<?php echo $image_count - 1; ?></span>
                                <?php endif; ?>
                                <span class="modality-badge modality-<?php echo $archive['modality'] ?? 'xray'; ?>">
                                    <?php echo strtoupper($archive['modality'] ?? 'X-Ray'); ?>
                                </span>
                                <div class="card-checkbox" onclick="event.stopPropagation();">
                                    <input type="checkbox" class="select-checkbox" value="<?php echo $archive['id']; ?>" onchange="updateSelection()">
                                </div>
                            </div>
                            <div class="card-body">
                                <div class="patient-name"><?php echo htmlspecialchars($archive['patient_name'] ?? 'Unknown Patient'); ?></div>
                                <div class="order-number"><?php echo htmlspecialchars($archive['order_number']); ?></div>
                                <div class="meta-row">
                                    <span><?php echo htmlspecialchars($archive['service_name'] ?? ''); ?></span>
                                    <span><?php echo date('M d, Y', strtotime($archive['created_at'] ?? 'now')); ?></span>
                                </div>
                                <div class="actions">
                                    <a href="pacs_viewer.php?order_id=<?php echo $archive['id']; ?>" class="btn btn-sm btn-primary">
                                        <i class="fas fa-eye"></i> View
                                    </a>
                                    <button class="btn btn-sm btn-danger" onclick="deleteImage(<?php echo $archive['id']; ?>, '<?php echo addslashes($first_image); ?>')">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    <?php else: ?>
                        <!-- List View Item -->
                        <div class="image-list-item" data-id="<?php echo $archive['id']; ?>">
                            <input type="checkbox" class="select-checkbox" value="<?php echo $archive['id']; ?>" onchange="updateSelection()">
                            <div class="thumb-small" onclick="window.location.href='pacs_viewer.php?order_id=<?php echo $archive['id']; ?>'">
                                <?php if ($first_image): ?>
                                    <img src="<?php echo htmlspecialchars(getImageUrl($first_image)); ?>" 
                                         alt="<?php echo htmlspecialchars($archive['order_number']); ?>"
                                         loading="lazy"
                                         onerror="this.style.display='none';this.parentElement.innerHTML='<i class=\'fas fa-image no-thumb\'></i>';">
                                <?php else: ?>
                                    <i class="fas fa-image no-thumb"></i>
                                <?php endif; ?>
                            </div>
                            <div class="info" onclick="window.location.href='pacs_viewer.php?order_id=<?php echo $archive['id']; ?>'">
                                <div class="patient-name"><?php echo htmlspecialchars($archive['patient_name'] ?? 'Unknown Patient'); ?></div>
                                <div class="order-number"><?php echo htmlspecialchars($archive['order_number']); ?></div>
                                <div class="details">
                                    <span class="modality-badge modality-<?php echo $archive['modality'] ?? 'xray'; ?>">
                                        <?php echo strtoupper($archive['modality'] ?? 'X-Ray'); ?>
                                    </span>
                                    <span><?php echo htmlspecialchars($archive['service_name'] ?? ''); ?></span>
                                    <span><?php echo htmlspecialchars($archive['body_part'] ?? ''); ?></span>
                                    <span><?php echo $image_count; ?> image(s)</span>
                                    <span class="status-badge status-<?php echo str_replace('_', '', $archive['status'] ?? 'scheduled'); ?>">
                                        <?php echo ucfirst(str_replace('_', ' ', $archive['status'] ?? 'Scheduled')); ?>
                                    </span>
                                    <span><?php echo date('M d, Y', strtotime($archive['created_at'] ?? 'now')); ?></span>
                                </div>
                            </div>
                            <div class="actions">
                                <a href="pacs_viewer.php?order_id=<?php echo $archive['id']; ?>" class="btn btn-sm btn-primary">
                                    <i class="fas fa-eye"></i>
                                </a>
                                <button class="btn btn-sm btn-danger" onclick="deleteImage(<?php echo $archive['id']; ?>, '<?php echo addslashes($first_image); ?>')">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
            
            <div style="text-align:center;margin-top:16px;color:#6b7280;font-size:13px;">
                Showing <?php echo count($archives); ?> of <?php echo $stats['total'] ?? 0; ?> images
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal-overlay" id="deleteModal">
    <div class="modal" style="max-width:500px;">
        <div class="modal-header">
            <h2><i class="fas fa-exclamation-triangle"></i> Delete Image</h2>
            <button class="modal-close" onclick="closeModal('deleteModal')">&times;</button>
        </div>
        <form method="POST" action="">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="order_id" id="deleteOrderId">
            <input type="hidden" name="image_path" id="deleteImagePath">
            <div class="modal-body">
                <p>Are you sure you want to delete this image?</p>
                <p style="font-size:13px;color:#6b7280;">This action cannot be undone. The image will be removed from the archive.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" onclick="closeModal('deleteModal')">Cancel</button>
                <button type="submit" class="btn btn-danger"><i class="fas fa-trash"></i> Delete</button>
            </div>
        </form>
    </div>
</div>

<style>
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
    max-width: 500px;
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
</style>

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
    const modality = document.getElementById('filterModality').value;
    const bodyPart = document.getElementById('filterBodyPart').value;
    const status = document.getElementById('filterStatus').value;
    const dateFrom = document.getElementById('filterDateFrom').value;
    const dateTo = document.getElementById('filterDateTo').value;
    const search = document.getElementById('filterSearch').value;
    const sort = document.getElementById('filterSort').value;
    const view = '<?php echo $view_mode; ?>';
    
    let url = window.location.pathname + '?view=' + view;
    if (modality) url += '&modality=' + encodeURIComponent(modality);
    if (bodyPart) url += '&body_part=' + encodeURIComponent(bodyPart);
    if (status) url += '&status=' + encodeURIComponent(status);
    if (dateFrom) url += '&date_from=' + encodeURIComponent(dateFrom);
    if (dateTo) url += '&date_to=' + encodeURIComponent(dateTo);
    if (search) url += '&search=' + encodeURIComponent(search);
    if (sort) url += '&sort=' + encodeURIComponent(sort);
    window.location.href = url;
}

function resetFilters() {
    window.location.href = window.location.pathname + '?view=<?php echo $view_mode; ?>';
}

function setView(view) {
    let url = window.location.pathname + '?view=' + view;
    const modality = document.getElementById('filterModality')?.value || '';
    const bodyPart = document.getElementById('filterBodyPart')?.value || '';
    const status = document.getElementById('filterStatus')?.value || '';
    const dateFrom = document.getElementById('filterDateFrom')?.value || '';
    const dateTo = document.getElementById('filterDateTo')?.value || '';
    const search = document.getElementById('filterSearch')?.value || '';
    const sort = document.getElementById('filterSort')?.value || '';
    if (modality) url += '&modality=' + encodeURIComponent(modality);
    if (bodyPart) url += '&body_part=' + encodeURIComponent(bodyPart);
    if (status) url += '&status=' + encodeURIComponent(status);
    if (dateFrom) url += '&date_from=' + encodeURIComponent(dateFrom);
    if (dateTo) url += '&date_to=' + encodeURIComponent(dateTo);
    if (search) url += '&search=' + encodeURIComponent(search);
    if (sort) url += '&sort=' + encodeURIComponent(sort);
    window.location.href = url;
}

// ============================================
// SELECTION FUNCTIONS
// ============================================
let selectedIds = new Set();

function updateSelection() {
    selectedIds.clear();
    document.querySelectorAll('.select-checkbox:checked').forEach(cb => {
        selectedIds.add(cb.value);
    });
    
    const count = selectedIds.size;
    document.getElementById('selectedCount').textContent = count;
    document.getElementById('batchActions').classList.toggle('visible', count > 0);
}

function clearSelection() {
    document.querySelectorAll('.select-checkbox').forEach(cb => {
        cb.checked = false;
    });
    selectedIds.clear();
    document.getElementById('selectedCount').textContent = 0;
    document.getElementById('batchActions').classList.remove('visible');
}

function batchDelete() {
    if (selectedIds.size === 0) return;
    if (confirm('Are you sure you want to delete ' + selectedIds.size + ' item(s)? This action cannot be undone.')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = '';
        const actionInput = document.createElement('input');
        actionInput.type = 'hidden';
        actionInput.name = 'action';
        actionInput.value = 'batch_delete';
        form.appendChild(actionInput);
        
        selectedIds.forEach(id => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'order_ids[]';
            input.value = id;
            form.appendChild(input);
        });
        
        document.body.appendChild(form);
        form.submit();
    }
}

// ============================================
// DELETE FUNCTIONS
// ============================================
function deleteImage(orderId, imagePath) {
    document.getElementById('deleteOrderId').value = orderId;
    document.getElementById('deleteImagePath').value = imagePath;
    openModal('deleteModal');
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

</body>
</html>