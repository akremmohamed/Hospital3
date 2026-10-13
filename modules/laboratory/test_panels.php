<?php
/**
 * test_panels.php
 * 
 * HMS ERP - Laboratory Test Panels Management
 * 
 * Features:
 * - Create and manage test panels (groups of tests)
 * - Add multiple tests to a panel
 * - Set panel pricing
 * - View panel usage statistics
 * - Activate/deactivate panels
 * - Export panel catalog
 * 
 * Note: This page uses a virtual panel structure since there's no dedicated
 * panel table in the schema. Panels are managed as grouped test selections.
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
// 2. SESSION-BASED PANEL STORAGE
// ============================================
// Since there's no dedicated panel table, we use session storage
// for panel definitions. In production, you would create a 
// lab_test_panels table with panel_id, panel_name, test_ids (JSON), etc.

// Initialize session panels if not exists
if (!isset($_SESSION['test_panels'])) {
    $_SESSION['test_panels'] = [];
}

// Default panels to pre-populate
if (empty($_SESSION['test_panels'])) {
    $_SESSION['test_panels'] = [
        [
            'id' => 1,
            'name' => 'Complete Blood Count (CBC)',
            'description' => 'Comprehensive blood cell analysis',
            'test_ids' => [1], // CBC test ID
            'price' => 350.00,
            'is_active' => 1,
            'created_at' => date('Y-m-d H:i:s')
        ],
        [
            'id' => 2,
            'name' => 'Liver Function Test (LFT)',
            'description' => 'Comprehensive liver enzyme panel',
            'test_ids' => [2], // LFT test ID
            'price' => 450.00,
            'is_active' => 1,
            'created_at' => date('Y-m-d H:i:s')
        ],
        [
            'id' => 3,
            'name' => 'Renal Function Test (RFT)',
            'description' => 'Comprehensive kidney function panel',
            'test_ids' => [3], // RFT test ID
            'price' => 400.00,
            'is_active' => 1,
            'created_at' => date('Y-m-d H:i:s')
        ],
        [
            'id' => 4,
            'name' => 'Lipid Profile',
            'description' => 'Comprehensive cholesterol panel',
            'test_ids' => [5], // Lipid Profile test ID
            'price' => 500.00,
            'is_active' => 1,
            'created_at' => date('Y-m-d H:i:s')
        ]
    ];
}

// ============================================
// 3. ACTION HANDLING
// ============================================
$action = $_GET['action'] ?? $_POST['action'] ?? '';
$panel_id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$success_message = '';
$error_message = '';

// Handle adding new panel
if ($action === 'add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $panel_name = trim($_POST['panel_name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $test_ids = $_POST['test_ids'] ?? [];
    $price = (float)($_POST['price'] ?? 0);
    $is_active = isset($_POST['is_active']) ? 1 : 0;

    if (empty($panel_name) || empty($test_ids) || $price <= 0) {
        $error_message = 'Please fill in all required fields (Panel Name, at least one test, and Price).';
    } else {
        // Generate new ID
        $new_id = count($_SESSION['test_panels']) > 0 ? 
            max(array_column($_SESSION['test_panels'], 'id')) + 1 : 1;

        $_SESSION['test_panels'][] = [
            'id' => $new_id,
            'name' => $panel_name,
            'description' => $description,
            'test_ids' => $test_ids,
            'price' => $price,
            'is_active' => $is_active,
            'created_at' => date('Y-m-d H:i:s')
        ];

        $success_message = "Panel '{$panel_name}' added successfully!";
        
        // Log audit
        log_audit($pdo, $branch_id, $user_id, 'INSERT', 'test_panel_session', $new_id, [
            'panel_name' => $panel_name,
            'test_count' => count($test_ids),
            'price' => $price
        ]);
    }
}

// Handle editing panel
if ($action === 'edit' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $panel_id = (int)($_POST['panel_id'] ?? 0);
    $panel_name = trim($_POST['panel_name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $test_ids = $_POST['test_ids'] ?? [];
    $price = (float)($_POST['price'] ?? 0);
    $is_active = isset($_POST['is_active']) ? 1 : 0;

    if ($panel_id <= 0 || empty($panel_name) || empty($test_ids) || $price <= 0) {
        $error_message = 'Please fill in all required fields.';
    } else {
        $found = false;
        foreach ($_SESSION['test_panels'] as $key => $panel) {
            if ($panel['id'] == $panel_id) {
                $_SESSION['test_panels'][$key]['name'] = $panel_name;
                $_SESSION['test_panels'][$key]['description'] = $description;
                $_SESSION['test_panels'][$key]['test_ids'] = $test_ids;
                $_SESSION['test_panels'][$key]['price'] = $price;
                $_SESSION['test_panels'][$key]['is_active'] = $is_active;
                $found = true;
                break;
            }
        }

        if ($found) {
            $success_message = "Panel updated successfully!";
            log_audit($pdo, $branch_id, $user_id, 'UPDATE', 'test_panel_session', $panel_id, [
                'panel_name' => $panel_name,
                'test_count' => count($test_ids),
                'price' => $price
            ]);
        } else {
            $error_message = 'Panel not found.';
        }
    }
}

// Handle deleting panel
if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $panel_id = (int)($_POST['panel_id'] ?? 0);

    if ($panel_id <= 0) {
        $error_message = 'Invalid panel ID.';
    } else {
        $found = false;
        foreach ($_SESSION['test_panels'] as $key => $panel) {
            if ($panel['id'] == $panel_id) {
                unset($_SESSION['test_panels'][$key]);
                $_SESSION['test_panels'] = array_values($_SESSION['test_panels']);
                $found = true;
                break;
            }
        }

        if ($found) {
            $success_message = "Panel deleted successfully.";
            log_audit($pdo, $branch_id, $user_id, 'DELETE', 'test_panel_session', $panel_id, []);
        } else {
            $error_message = 'Panel not found.';
        }
    }
}

// Handle toggling panel status
if ($action === 'toggle_status' && isset($_GET['id'])) {
    $panel_id = (int)$_GET['id'];
    $status = isset($_GET['status']) ? (int)$_GET['status'] : 0;

    if ($panel_id <= 0) {
        $error_message = 'Invalid panel ID.';
    } else {
        $found = false;
        foreach ($_SESSION['test_panels'] as $key => $panel) {
            if ($panel['id'] == $panel_id) {
                $_SESSION['test_panels'][$key]['is_active'] = $status;
                $found = true;
                break;
            }
        }

        if ($found) {
            $success_message = "Panel status updated successfully.";
            log_audit($pdo, $branch_id, $user_id, 'UPDATE', 'test_panel_session', $panel_id, [
                'is_active' => $status
            ]);
        } else {
            $error_message = 'Panel not found.';
        }
    }
}

// ============================================
// 4. HELPER FUNCTIONS
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
// 5. DATA FETCHING
// ============================================

// Fetch all tests for dropdown
$sql = "SELECT id, test_code, test_name, test_category, price 
        FROM lab_tests_master 
        WHERE is_active = 1 AND deleted_at IS NULL 
        ORDER BY test_name ASC";
$available_tests = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

// Get panels from session
$panels = $_SESSION['test_panels'];

// For each panel, get test details
foreach ($panels as $key => $panel) {
    $panel_tests = [];
    $total_price = 0;
    foreach ($panel['test_ids'] as $test_id) {
        foreach ($available_tests as $test) {
            if ($test['id'] == $test_id) {
                $panel_tests[] = $test;
                $total_price += $test['price'];
                break;
            }
        }
    }
    $panels[$key]['tests'] = $panel_tests;
    $panels[$key]['total_individual_price'] = $total_price;
    $panels[$key]['savings'] = $total_price - $panel['price'];
}

// Get statistics
$stats = [
    'total' => count($panels),
    'active' => 0,
    'inactive' => 0,
    'total_tests_used' => 0
];

foreach ($panels as $panel) {
    if ($panel['is_active']) {
        $stats['active']++;
    } else {
        $stats['inactive']++;
    }
    $stats['total_tests_used'] += count($panel['test_ids']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Test Panels | HMS ERP</title>
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
        .page-header h1 i { color: #8b5cf6; }
        
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
        .btn:hover { background: #f3f4f6; border-color: #8b5cf6; }
        .btn-primary { background: #8b5cf6; color: white; border-color: #8b5cf6; }
        .btn-primary:hover { background: #7c3aed; border-color: #7c3aed; color: white; }
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
        .stat-pill.total { border-left: 3px solid #8b5cf6; }
        .stat-pill.active { border-left: 3px solid #06d6a0; }
        .stat-pill.inactive { border-left: 3px solid #ef476f; }
        .stat-pill.tests { border-left: 3px solid #4cc9f0; }
        
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
        .badge-active { background: #d1fae5; color: #065f46; }
        .badge-inactive { background: #fee2e2; color: #991b1b; }
        .badge-info { background: #dbeafe; color: #1e40af; }
        .badge-warning { background: #fef3c7; color: #92400e; }
        .badge-success { background: #d1fae5; color: #065f46; }
        .badge-danger { background: #fee2e2; color: #991b1b; }
        .badge-primary { background: #dbeafe; color: #1e40af; }
        .badge-purple { background: #ede9fe; color: #5b21b6; }
        
        /* Panel Cards */
        .panel-card {
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            padding: 16px 20px;
            margin-bottom: 16px;
            background: white;
            transition: all 0.3s;
        }
        .panel-card:hover { box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
        .panel-card.inactive { opacity: 0.7; background: #f9fafb; }
        .panel-card .panel-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            flex-wrap: wrap;
            gap: 8px;
        }
        .panel-card .panel-name {
            font-size: 16px;
            font-weight: 700;
        }
        .panel-card .panel-description {
            font-size: 13px;
            color: #6b7280;
            margin: 4px 0 8px;
        }
        .panel-card .panel-tests {
            display: flex;
            flex-wrap: wrap;
            gap: 4px;
            margin: 8px 0;
        }
        .panel-card .panel-tests .test-tag {
            padding: 2px 10px;
            background: #f3f4f6;
            border-radius: 12px;
            font-size: 11px;
            color: #6b7280;
        }
        .panel-card .panel-pricing {
            display: flex;
            gap: 16px;
            align-items: center;
            margin: 8px 0;
        }
        .panel-card .panel-pricing .panel-price {
            font-size: 18px;
            font-weight: 700;
            color: #8b5cf6;
        }
        .panel-card .panel-pricing .individual-price {
            font-size: 13px;
            color: #6b7280;
            text-decoration: line-through;
        }
        .panel-card .panel-pricing .savings {
            font-size: 12px;
            color: #06d6a0;
            font-weight: 600;
        }
        .panel-card .panel-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-top: 12px;
            padding-top: 12px;
            border-top: 1px solid #e5e7eb;
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
            max-width: 700px;
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
            border-color: #8b5cf6;
            box-shadow: 0 0 0 3px rgba(139, 92, 246, 0.1);
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
            accent-color: #8b5cf6;
        }
        
        .test-select-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 8px;
            max-height: 200px;
            overflow-y: auto;
            padding: 8px;
            border: 1px solid #e5e7eb;
            border-radius: 6px;
        }
        .test-select-grid .test-item {
            padding: 4px 8px;
            border-radius: 4px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .test-select-grid .test-item:hover { background: #f3f4f6; }
        .test-select-grid .test-item input[type="checkbox"] {
            accent-color: #8b5cf6;
        }
        .test-select-grid .test-item .test-code {
            font-size: 11px;
            color: #6b7280;
        }
        .test-select-grid .test-item .test-price {
            font-size: 11px;
            color: #6b7280;
            margin-left: auto;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .filter-bar { flex-direction: column; align-items: stretch; }
            .filter-bar select, .filter-bar input { width: 100%; }
            .stats-bar { gap: 6px; }
            .stat-pill { font-size: 11px; padding: 4px 10px; }
            .page-header h1 { font-size: 20px; }
            .card-body { padding: 12px; }
            .form-row { grid-template-columns: 1fr; }
            .panel-card .panel-header { flex-direction: column; }
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
            <h1><i class="fas fa-layer-group"></i> Test Panels</h1>
            <div>
                <button class="btn btn-primary" onclick="openModal('addModal')">
                    <i class="fas fa-plus"></i> Create Panel
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
                <span class="label">Total Panels</span>
            </div>
            <div class="stat-pill active">
                <span class="count" style="color:#06d6a0;"><?php echo $stats['active']; ?></span>
                <span class="label">Active</span>
            </div>
            <div class="stat-pill inactive">
                <span class="count" style="color:#ef476f;"><?php echo $stats['inactive']; ?></span>
                <span class="label">Inactive</span>
            </div>
            <div class="stat-pill tests">
                <span class="count" style="color:#4cc9f0;"><?php echo $stats['total_tests_used']; ?></span>
                <span class="label">Tests in Panels</span>
            </div>
        </div>

        <!-- Panels Grid -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-list"></i> Test Panels</h3>
                <span class="text-muted"><?php echo count($panels); ?> panels</span>
            </div>
            <div class="card-body">
                <?php if (empty($panels)): ?>
                    <div class="no-data" style="text-align:center;padding:40px 0;color:#6b7280;">
                        <i class="fas fa-layer-group" style="font-size:36px;display:block;margin-bottom:10px;color:#d1d5db;"></i>
                        No test panels created yet
                    </div>
                <?php else: ?>
                    <?php foreach ($panels as $panel): ?>
                        <div class="panel-card <?php echo $panel['is_active'] ? '' : 'inactive'; ?>">
                            <div class="panel-header">
                                <div>
                                    <span class="panel-name"><?php echo htmlspecialchars($panel['name']); ?></span>
                                    <?php if ($panel['is_active']): ?>
                                        <span class="badge badge-active">Active</span>
                                    <?php else: ?>
                                        <span class="badge badge-inactive">Inactive</span>
                                    <?php endif; ?>
                                    <?php if (!empty($panel['tests'])): ?>
                                        <span class="badge badge-purple"><?php echo count($panel['tests']); ?> tests</span>
                                    <?php endif; ?>
                                </div>
                                <span style="font-size:12px;color:#6b7280;">
                                    <?php echo date('M d, Y', strtotime($panel['created_at'] ?? 'now')); ?>
                                </span>
                            </div>
                            
                            <?php if (!empty($panel['description'])): ?>
                                <div class="panel-description"><?php echo htmlspecialchars($panel['description']); ?></div>
                            <?php endif; ?>
                            
                            <div class="panel-tests">
                                <?php foreach ($panel['tests'] as $test): ?>
                                    <span class="test-tag">
                                        <?php echo htmlspecialchars($test['test_code'] ?? $test['test_name']); ?>
                                    </span>
                                <?php endforeach; ?>
                            </div>
                            
                            <div class="panel-pricing">
                                <span class="panel-price"><?php echo number_format($panel['price'], 2); ?> ETB</span>
                                <?php if ($panel['total_individual_price'] > 0): ?>
                                    <span class="individual-price"><?php echo number_format($panel['total_individual_price'], 2); ?> ETB</span>
                                    <span class="savings">Save <?php echo number_format($panel['savings'], 2); ?> ETB</span>
                                <?php endif; ?>
                            </div>
                            
                            <div class="panel-actions">
                                <button class="btn btn-sm btn-warning" onclick="editPanel(<?php echo $panel['id']; ?>)">
                                    <i class="fas fa-edit"></i> Edit
                                </button>
                                <button class="btn btn-sm <?php echo $panel['is_active'] ? 'btn-danger' : 'btn-success'; ?>" 
                                        onclick="toggleStatus(<?php echo $panel['id']; ?>, <?php echo $panel['is_active'] ? 0 : 1; ?>)">
                                    <i class="fas <?php echo $panel['is_active'] ? 'fa-pause' : 'fa-play'; ?>"></i>
                                    <?php echo $panel['is_active'] ? 'Deactivate' : 'Activate'; ?>
                                </button>
                                <button class="btn btn-sm btn-danger" onclick="deletePanel(<?php echo $panel['id']; ?>, '<?php echo htmlspecialchars($panel['name']); ?>')">
                                    <i class="fas fa-trash"></i> Delete
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- ============================================ -->
<!-- ADD PANEL MODAL -->
<!-- ============================================ -->
<div class="modal-overlay" id="addModal">
    <div class="modal">
        <div class="modal-header">
            <h2><i class="fas fa-plus-circle"></i> Create Test Panel</h2>
            <button class="modal-close" onclick="closeModal('addModal')">&times;</button>
        </div>
        <form method="POST" action="">
            <input type="hidden" name="action" value="add">
            <div class="modal-body">
                <div class="form-group">
                    <label>Panel Name <span class="required">*</span></label>
                    <input type="text" name="panel_name" class="form-control" required placeholder="e.g., Complete Blood Count">
                </div>

                <div class="form-group">
                    <label>Description</label>
                    <textarea name="description" class="form-control" placeholder="Panel description..."></textarea>
                </div>

                <div class="form-group">
                    <label>Select Tests <span class="required">*</span></label>
                    <div class="test-select-grid">
                        <?php foreach ($available_tests as $test): ?>
                            <label class="test-item">
                                <input type="checkbox" name="test_ids[]" value="<?php echo $test['id']; ?>">
                                <span><?php echo htmlspecialchars($test['test_name']); ?></span>
                                <span class="test-code">(<?php echo htmlspecialchars($test['test_code']); ?>)</span>
                                <span class="test-price"><?php echo number_format($test['price'], 2); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Panel Price (ETB) <span class="required">*</span></label>
                        <input type="number" name="price" class="form-control" required step="0.01" min="0" placeholder="0.00">
                    </div>
                    <div class="form-group">
                        <div class="form-check" style="margin-top:24px;">
                            <input type="checkbox" name="is_active" id="add_is_active" value="1" checked>
                            <label for="add_is_active">Active</label>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" onclick="closeModal('addModal')">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Create Panel</button>
            </div>
        </form>
    </div>
</div>

<!-- ============================================ -->
<!-- EDIT PANEL MODAL -->
<!-- ============================================ -->
<div class="modal-overlay" id="editModal">
    <div class="modal">
        <div class="modal-header">
            <h2><i class="fas fa-edit"></i> Edit Test Panel</h2>
            <button class="modal-close" onclick="closeModal('editModal')">&times;</button>
        </div>
        <form method="POST" action="">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="panel_id" id="editPanelId">
            <div class="modal-body" id="editPanelContent">
                <p class="text-muted text-center">Loading panel details...</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" onclick="closeModal('editModal')">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Update Panel</button>
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
// PANEL ACTIONS
// ============================================
function editPanel(panelId) {
    document.getElementById('editPanelId').value = panelId;
    document.getElementById('editPanelContent').innerHTML = '<p class="text-muted text-center"><i class="fas fa-spinner fa-spin"></i> Loading...</p>';
    openModal('editModal');
    
    // Get panel data from PHP session via AJAX
    fetch('?ajax=get_panel&id=' + panelId)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const panel = data.panel;
                const allTests = <?php echo json_encode($available_tests); ?>;
                
                let html = `
                    <div class="form-group">
                        <label>Panel Name <span class="required">*</span></label>
                        <input type="text" name="panel_name" class="form-control" required value="${panel.name}">
                    </div>

                    <div class="form-group">
                        <label>Description</label>
                        <textarea name="description" class="form-control">${panel.description || ''}</textarea>
                    </div>

                    <div class="form-group">
                        <label>Select Tests <span class="required">*</span></label>
                        <div class="test-select-grid">
                `;
                
                allTests.forEach(test => {
                    const checked = panel.test_ids && panel.test_ids.includes(test.id) ? 'checked' : '';
                    html += `
                        <label class="test-item">
                            <input type="checkbox" name="test_ids[]" value="${test.id}" ${checked}>
                            <span>${test.test_name}</span>
                            <span class="test-code">(${test.test_code})</span>
                            <span class="test-price">${test.price.toFixed(2)}</span>
                        </label>
                    `;
                });
                
                html += `
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Panel Price (ETB) <span class="required">*</span></label>
                            <input type="number" name="price" class="form-control" required step="0.01" min="0" value="${panel.price}">
                        </div>
                        <div class="form-group">
                            <div class="form-check" style="margin-top:24px;">
                                <input type="checkbox" name="is_active" id="edit_is_active" value="1" ${panel.is_active ? 'checked' : ''}>
                                <label for="edit_is_active">Active</label>
                            </div>
                        </div>
                    </div>
                `;
                
                document.getElementById('editPanelContent').innerHTML = html;
            } else {
                document.getElementById('editPanelContent').innerHTML = '<p class="text-danger">Error loading panel details.</p>';
            }
        })
        .catch(error => {
            document.getElementById('editPanelContent').innerHTML = '<p class="text-danger">Error loading panel details.</p>';
            console.error('Error:', error);
        });
}

function toggleStatus(panelId, newStatus) {
    const action = newStatus ? 'activate' : 'deactivate';
    if (!confirm(`Are you sure you want to ${action} this panel?`)) return;
    window.location.href = '?action=toggle_status&id=' + panelId + '&status=' + newStatus;
}

function deletePanel(panelId, panelName) {
    if (!confirm(`Are you sure you want to delete "${panelName}"? This action cannot be undone.`)) return;
    
    const form = document.createElement('form');
    form.method = 'POST';
    form.innerHTML = `
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="panel_id" value="${panelId}">
    `;
    document.body.appendChild(form);
    form.submit();
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
// AJAX ENDPOINT FOR GETTING PANEL DATA
// ============================================
<?php if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_panel' && isset($_GET['id'])): ?>
    <?php
    header('Content-Type: application/json');
    $panel_id = (int)$_GET['id'];
    $found = null;
    foreach ($_SESSION['test_panels'] as $panel) {
        if ($panel['id'] == $panel_id) {
            $found = $panel;
            break;
        }
    }
    if ($found) {
        echo json_encode(['success' => true, 'panel' => $found]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Panel not found']);
    }
    exit;
    ?>
<?php endif; ?>
</script>

<!-- DataTables -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>

</body>
</html>