<?php
/**
 * branch_management.php
 * Super Admin Module: Multi-Tenancy & Branch Administration
 * 
 * Strictly based on hms_erp.sql schema. Extends branch capabilities with a
 * dedicated branch_settings table for tax_rate, currency, fiscal dates and logo.
 * Enhanced UI/UX with sidebar compatibility, modern cards, and responsive design.
 * Fixed layout visibility when sidebar expanded.
 * 
 * @package HMS-ERP
 * @version 2.1
 */

if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../../config/db_connection.php';
require_once __DIR__ . '/../../includes/auth_helper.php';

// ============================================
// STRICT ACCESS CONTROL (SUPER ADMIN ONLY)
// ============================================
if (!isset($_SESSION['user_id']) || ($_SESSION['user_type'] ?? '') !== 'super_admin') {
    header('Location: ../../index.php?error=unauthorized');
    exit;
}

$user_id   = (int) $_SESSION['user_id'];
$csrf_token = $_SESSION['csrf_token'] ?? bin2hex(random_bytes(32));
$_SESSION['csrf_token'] = $csrf_token;

$db = getDB();
if (!$db) {
    die('Database connection failed. Please check configuration.');
}

// ============================================
// AJAX ROUTER (ALL SERVER-SIDE ACTIONS)
// ============================================
if (isset($_GET['action'])) {
    error_reporting(0);
    ini_set('display_errors', 0);
    if (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');

    $action = $_GET['action'];

    // ---------- GET BRANCHES (DataTables Server-Side) ----------
    if ($action === 'get_branches') {
        $draw   = (int) ($_GET['draw'] ?? 1);
        $start  = max(0, (int) ($_GET['start'] ?? 0));
        $length = min(500, (int) ($_GET['length'] ?? 25));
        $search = trim($_GET['search']['value'] ?? '');

        $where = "b.deleted_at IS NULL";
        $params = [];

        if ($search !== '') {
            $where .= " AND (b.name LIKE :search OR b.code LIKE :search OR b.location LIKE :search OR b.phone LIKE :search)";
            $params[':search'] = "%$search%";
        }

        // Total count query
        $countSql = "SELECT COUNT(*) FROM branches b WHERE $where";
        $countStmt = $db->prepare($countSql);
        $countStmt->execute($params);
        $totalRecords = (int) $countStmt->fetchColumn();

        // Data query with settings (subqueries for performance)
        $dataSql = "
            SELECT 
                b.id,
                b.code,
                b.name,
                b.location,
                b.phone,
                b.email,
                b.status,
                b.branch_type,
                b.created_at,
                (SELECT setting_value FROM branch_settings WHERE branch_id = b.id AND setting_key = 'logo_path') AS logo_path,
                (SELECT setting_value FROM branch_settings WHERE branch_id = b.id AND setting_key = 'tax_rate') AS tax_rate,
                (SELECT setting_value FROM branch_settings WHERE branch_id = b.id AND setting_key = 'currency') AS currency,
                (SELECT setting_value FROM branch_settings WHERE branch_id = b.id AND setting_key = 'fiscal_year_start') AS fiscal_year_start,
                (SELECT setting_value FROM branch_settings WHERE branch_id = b.id AND setting_key = 'fiscal_year_end') AS fiscal_year_end
            FROM branches b
            WHERE $where
            ORDER BY b.name ASC
            LIMIT :limit OFFSET :offset
        ";
        $dataStmt = $db->prepare($dataSql);
        foreach ($params as $key => $val) {
            $dataStmt->bindValue($key, $val);
        }
        $dataStmt->bindValue(':limit', $length, PDO::PARAM_INT);
        $dataStmt->bindValue(':offset', $start, PDO::PARAM_INT);
        $dataStmt->execute();
        $branches = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

        $data = [];
        foreach ($branches as $branch) {
            $logoHtml = !empty($branch['logo_path']) 
                ? '<img src="' . htmlspecialchars($branch['logo_path']) . '" alt="Logo" class="branch-logo-img">'
                : '<div class="branch-logo-placeholder"><i class="fas fa-hospital"></i></div>';
            $statusBadge = $branch['status'] === 'active' 
                ? '<span class="badge badge-success">Active</span>'
                : '<span class="badge badge-danger">Inactive / Suspended</span>';
            $actionButtons = '
                <div class="action-btns">
                    <button class="btn-edit-branch btn-icon" data-id="' . $branch['id'] . '" title="Edit Branch"><i class="fas fa-edit"></i></button>
                    <button class="btn-toggle-status btn-icon ' . ($branch['status'] === 'active' ? 'btn-warning' : 'btn-success') . '" data-id="' . $branch['id'] . '" data-status="' . $branch['status'] . '" title="Toggle Status">
                        <i class="fas ' . ($branch['status'] === 'active' ? 'fa-ban' : 'fa-check-circle') . '"></i>
                    </button>
                    <button class="btn-delete-branch btn-icon btn-danger" data-id="' . $branch['id'] . '" data-name="' . htmlspecialchars($branch['name']) . '" title="Delete Branch"><i class="fas fa-trash-alt"></i></button>
                </div>
            ';
            $data[] = [
                'logo'          => $logoHtml,
                'code'          => htmlspecialchars($branch['code']),
                'name'          => htmlspecialchars($branch['name']),
                'location'      => htmlspecialchars($branch['location'] ?? ''),
                'phone'         => htmlspecialchars($branch['phone'] ?? ''),
                'branch_type'   => htmlspecialchars(ucfirst(str_replace('_', ' ', $branch['branch_type'] ?? 'main_hospital'))),
                'status'        => $statusBadge,
                'created'       => date('d M Y', strtotime($branch['created_at'])),
                'actions'       => $actionButtons,
                // hidden fields for edit modal
                'email'         => htmlspecialchars($branch['email'] ?? ''),
                'tax_rate'      => $branch['tax_rate'] ?? '0.00',
                'currency'      => $branch['currency'] ?? 'ETB',
                'fiscal_start'  => $branch['fiscal_year_start'] ?? '',
                'fiscal_end'    => $branch['fiscal_year_end'] ?? '',
                'logo_path'     => $branch['logo_path'] ?? ''
            ];
        }

        echo json_encode([
            'draw'            => $draw,
            'recordsTotal'    => $totalRecords,
            'recordsFiltered' => $totalRecords,
            'data'            => $data
        ]);
        exit;
    }

    // ---------- CREATE BRANCH (POST) ----------
    if ($action === 'create_branch') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
            exit;
        }
        // CSRF validation
        $submittedToken = $_POST['csrf_token'] ?? '';
        if (!hash_equals($csrf_token, $submittedToken)) {
            echo json_encode(['success' => false, 'message' => 'Security validation failed. Refresh page and retry.']);
            exit;
        }

        $name       = trim($_POST['name'] ?? '');
        $code       = trim($_POST['code'] ?? '');
        $location   = trim($_POST['location'] ?? '');
        $phone      = trim($_POST['phone'] ?? '');
        $email      = trim($_POST['email'] ?? '');
        $branchType = trim($_POST['branch_type'] ?? 'main_hospital');
        $status     = trim($_POST['status'] ?? 'active');

        // Settings fields
        $taxRate        = trim($_POST['tax_rate'] ?? '0.00');
        $currency       = trim($_POST['currency'] ?? 'ETB');
        $fiscalStart    = !empty($_POST['fiscal_year_start']) ? $_POST['fiscal_year_start'] : null;
        $fiscalEnd      = !empty($_POST['fiscal_year_end']) ? $_POST['fiscal_year_end'] : null;

        if (empty($name) || empty($code)) {
            echo json_encode(['success' => false, 'message' => 'Branch name and code are required.']);
            exit;
        }

        // Check duplicate code
        $checkStmt = $db->prepare("SELECT id FROM branches WHERE code = :code AND deleted_at IS NULL");
        $checkStmt->execute([':code' => $code]);
        if ($checkStmt->fetchColumn()) {
            echo json_encode(['success' => false, 'message' => 'Branch code already exists. Please use a unique code.']);
            exit;
        }

        // Logo upload handling
        $logoPath = null;
        if (isset($_FILES['branch_logo']) && $_FILES['branch_logo']['error'] === UPLOAD_ERR_OK) {
            $uploadDir = $_SERVER['DOCUMENT_ROOT'] . '/../uploads/branch_logos/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = finfo_file($finfo, $_FILES['branch_logo']['tmp_name']);
            finfo_close($finfo);
            $allowedMimes = ['image/jpeg', 'image/png', 'image/jpg'];
            if (!in_array($mimeType, $allowedMimes)) {
                echo json_encode(['success' => false, 'message' => 'Only JPG, JPEG, PNG images allowed.']);
                exit;
            }
            if ($_FILES['branch_logo']['size'] > 2 * 1024 * 1024) {
                echo json_encode(['success' => false, 'message' => 'Logo size must be ≤ 2MB.']);
                exit;
            }
            $ext = pathinfo($_FILES['branch_logo']['name'], PATHINFO_EXTENSION);
            $safeName = preg_replace('/[^a-zA-Z0-9_\-]/', '', $code) . '_' . time() . '.' . $ext;
            $targetFile = $uploadDir . $safeName;
            if (move_uploaded_file($_FILES['branch_logo']['tmp_name'], $targetFile)) {
                $logoPath = '/uploads/branch_logos/' . $safeName;
            } else {
                echo json_encode(['success' => false, 'message' => 'Logo upload failed.']);
                exit;
            }
        } else {
            // Default placeholder
            $logoPath = '/assets/images/default-hospital-logo.png';
        }

        $db->beginTransaction();
        try {
            // Insert into branches table
            $branchStmt = $db->prepare("
                INSERT INTO branches 
                (code, name, location, phone, email, branch_type, status, created_by_user_id, created_at, updated_at)
                VALUES (:code, :name, :location, :phone, :email, :branch_type, :status, :created_by, NOW(), NOW())
            ");
            $branchStmt->execute([
                ':code' => $code,
                ':name' => $name,
                ':location' => $location,
                ':phone' => $phone,
                ':email' => $email,
                ':branch_type' => $branchType,
                ':status' => $status,
                ':created_by' => $user_id
            ]);
            $branchId = $db->lastInsertId();

            // Insert settings
            $settingsStmt = $db->prepare("
                INSERT INTO branch_settings (branch_id, setting_key, setting_value) 
                VALUES (:bid, :key, :value)
                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
            ");
            $settingsStmt->execute([':bid' => $branchId, ':key' => 'logo_path', ':value' => $logoPath]);
            $settingsStmt->execute([':bid' => $branchId, ':key' => 'tax_rate', ':value' => $taxRate]);
            $settingsStmt->execute([':bid' => $branchId, ':key' => 'currency', ':value' => $currency]);
            if ($fiscalStart) $settingsStmt->execute([':bid' => $branchId, ':key' => 'fiscal_year_start', ':value' => $fiscalStart]);
            if ($fiscalEnd) $settingsStmt->execute([':bid' => $branchId, ':key' => 'fiscal_year_end', ':value' => $fiscalEnd]);

            $db->commit();
            echo json_encode(['success' => true, 'message' => 'Branch created successfully.']);
        } catch (Exception $e) {
            $db->rollBack();
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        }
        exit;
    }

    // ---------- UPDATE BRANCH (EDIT) ----------
    if ($action === 'update_branch') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['success' => false, 'message' => 'Invalid method.']);
            exit;
        }
        $submittedToken = $_POST['csrf_token'] ?? '';
        if (!hash_equals($csrf_token, $submittedToken)) {
            echo json_encode(['success' => false, 'message' => 'CSRF validation failed.']);
            exit;
        }

        $branchId   = (int) ($_POST['branch_id'] ?? 0);
        $name       = trim($_POST['name'] ?? '');
        $code       = trim($_POST['code'] ?? '');
        $location   = trim($_POST['location'] ?? '');
        $phone      = trim($_POST['phone'] ?? '');
        $email      = trim($_POST['email'] ?? '');
        $branchType = trim($_POST['branch_type'] ?? 'main_hospital');
        $status     = trim($_POST['status'] ?? 'active');
        $taxRate    = trim($_POST['tax_rate'] ?? '0.00');
        $currency   = trim($_POST['currency'] ?? 'ETB');
        $fiscalStart = !empty($_POST['fiscal_year_start']) ? $_POST['fiscal_year_start'] : null;
        $fiscalEnd   = !empty($_POST['fiscal_year_end']) ? $_POST['fiscal_year_end'] : null;

        if ($branchId <= 0 || empty($name) || empty($code)) {
            echo json_encode(['success' => false, 'message' => 'Invalid branch data.']);
            exit;
        }

        // Check duplicate code excluding itself
        $dupStmt = $db->prepare("SELECT id FROM branches WHERE code = :code AND id != :bid AND deleted_at IS NULL");
        $dupStmt->execute([':code' => $code, ':bid' => $branchId]);
        if ($dupStmt->fetchColumn()) {
            echo json_encode(['success' => false, 'message' => 'Branch code already used by another branch.']);
            exit;
        }

        // Logo upload (if new file provided)
        $logoPath = null;
        if (isset($_FILES['branch_logo']) && $_FILES['branch_logo']['error'] === UPLOAD_ERR_OK) {
            $uploadDir = $_SERVER['DOCUMENT_ROOT'] . '/../uploads/branch_logos/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = finfo_file($finfo, $_FILES['branch_logo']['tmp_name']);
            finfo_close($finfo);
            if (!in_array($mimeType, ['image/jpeg', 'image/png', 'image/jpg'])) {
                echo json_encode(['success' => false, 'message' => 'Invalid image format.']);
                exit;
            }
            if ($_FILES['branch_logo']['size'] > 2 * 1024 * 1024) {
                echo json_encode(['success' => false, 'message' => 'Logo exceeds 2MB.']);
                exit;
            }
            $ext = pathinfo($_FILES['branch_logo']['name'], PATHINFO_EXTENSION);
            $safeName = preg_replace('/[^a-zA-Z0-9_\-]/', '', $code) . '_' . time() . '.' . $ext;
            $targetFile = $uploadDir . $safeName;
            if (move_uploaded_file($_FILES['branch_logo']['tmp_name'], $targetFile)) {
                $logoPath = '/uploads/branch_logos/' . $safeName;
            } else {
                echo json_encode(['success' => false, 'message' => 'Logo upload failed.']);
                exit;
            }
        }

        $db->beginTransaction();
        try {
            $updateBranch = $db->prepare("
                UPDATE branches SET
                    name = :name,
                    code = :code,
                    location = :location,
                    phone = :phone,
                    email = :email,
                    branch_type = :branch_type,
                    status = :status,
                    updated_at = NOW()
                WHERE id = :bid AND deleted_at IS NULL
            ");
            $updateBranch->execute([
                ':name' => $name,
                ':code' => $code,
                ':location' => $location,
                ':phone' => $phone,
                ':email' => $email,
                ':branch_type' => $branchType,
                ':status' => $status,
                ':bid' => $branchId
            ]);

            $settingsStmt = $db->prepare("
                INSERT INTO branch_settings (branch_id, setting_key, setting_value) 
                VALUES (:bid, :key, :value)
                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
            ");
            if ($logoPath) $settingsStmt->execute([':bid' => $branchId, ':key' => 'logo_path', ':value' => $logoPath]);
            $settingsStmt->execute([':bid' => $branchId, ':key' => 'tax_rate', ':value' => $taxRate]);
            $settingsStmt->execute([':bid' => $branchId, ':key' => 'currency', ':value' => $currency]);
            if ($fiscalStart) $settingsStmt->execute([':bid' => $branchId, ':key' => 'fiscal_year_start', ':value' => $fiscalStart]);
            if ($fiscalEnd) $settingsStmt->execute([':bid' => $branchId, ':key' => 'fiscal_year_end', ':value' => $fiscalEnd]);

            $db->commit();
            echo json_encode(['success' => true, 'message' => 'Branch updated successfully.']);
        } catch (Exception $e) {
            $db->rollBack();
            echo json_encode(['success' => false, 'message' => 'Update failed: ' . $e->getMessage()]);
        }
        exit;
    }

    // ---------- TOGGLE STATUS (Activate/Deactivate) ----------
    if ($action === 'toggle_status') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['success' => false, 'message' => 'Invalid method']);
            exit;
        }
        $submittedToken = $_POST['csrf_token'] ?? '';
        if (!hash_equals($csrf_token, $submittedToken)) {
            echo json_encode(['success' => false, 'message' => 'CSRF validation failed']);
            exit;
        }
        $branchId = (int) ($_POST['branch_id'] ?? 0);
        $newStatus = ($_POST['current_status'] ?? '') === 'active' ? 'inactive' : 'active';
        if ($branchId <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid branch ID']);
            exit;
        }
        $stmt = $db->prepare("UPDATE branches SET status = :status, updated_at = NOW() WHERE id = :bid AND deleted_at IS NULL");
        $stmt->execute([':status' => $newStatus, ':bid' => $branchId]);
        echo json_encode(['success' => true, 'message' => 'Branch status toggled successfully.', 'new_status' => $newStatus]);
        exit;
    }

    // ---------- DELETE BRANCH (Soft Delete) ----------
    if ($action === 'delete_branch') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['success' => false, 'message' => 'Invalid method']);
            exit;
        }
        $submittedToken = $_POST['csrf_token'] ?? '';
        if (!hash_equals($csrf_token, $submittedToken)) {
            echo json_encode(['success' => false, 'message' => 'Security token mismatch']);
            exit;
        }
        $branchId = (int) ($_POST['branch_id'] ?? 0);
        if ($branchId <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid branch ID']);
            exit;
        }
        $stmt = $db->prepare("UPDATE branches SET deleted_at = NOW() WHERE id = :bid");
        $stmt->execute([':bid' => $branchId]);
        echo json_encode(['success' => true, 'message' => 'Branch moved to recycle bin.']);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Invalid action']);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Multi-Tenancy & Branch Administration | HMS ERP</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root {
            --primary: #4361ee;
            --primary-dark: #3a0ca3;
            --success: #06d6a0;
            --danger: #ef476f;
            --warning: #f9c74f;
            --gray-light: #e9ecef;
            --dark: #1a1a2e;
        }
        body {
            font-family: 'Inter', sans-serif;
            background: #f5f7fb;
            margin: 0;
            padding: 0;
            overflow-x: hidden;
        }
        /* Ensure content area is always visible and respects sidebar width */
        .content-page {
            margin-left: 260px;
            margin-top: 70px;
            padding: 24px 32px;
            transition: margin-left 0.3s ease;
            min-height: calc(100vh - 70px);
            position: relative;
            z-index: 1;
            background: #f5f7fb;
        }
        /* When sidebar is collapsed */
        body.erp-sidebar-collapsed .content-page {
            margin-left: 80px;
        }
        /* Responsive: on mobile, no margin-left (sidebar hidden initially) */
        @media (max-width: 768px) {
            .content-page {
                margin-left: 0;
                padding: 16px;
            }
            body.erp-sidebar-collapsed .content-page {
                margin-left: 0;
            }
        }
        /* DataTables wrapper - prevent overflow */
        .dataTables_wrapper {
            overflow-x: auto;
        }
        .card-custom {
            background: white;
            border-radius: 24px;
            border: 1px solid var(--gray-light);
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
            overflow: hidden;
            margin-bottom: 24px;
        }
        .card-header-custom {
            background: white;
            padding: 20px 24px;
            border-bottom: 1px solid var(--gray-light);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 16px;
        }
        .btn-primary-custom {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            border: none;
            border-radius: 14px;
            padding: 10px 24px;
            font-weight: 600;
            color: white;
            transition: 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .btn-primary-custom:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(67,97,238,0.3);
        }
        .data-table th {
            font-size: 13px;
            font-weight: 600;
            background: #f8fafc;
            border-bottom: 1px solid var(--gray-light);
        }
        .data-table td {
            vertical-align: middle;
            padding: 12px 16px;
        }
        .badge-success {
            background: #d1fae5;
            color: #059669;
            padding: 4px 12px;
            border-radius: 40px;
            font-size: 12px;
            font-weight: 600;
        }
        .badge-danger {
            background: #fee2e2;
            color: #dc2626;
            padding: 4px 12px;
            border-radius: 40px;
            font-size: 12px;
            font-weight: 600;
        }
        .branch-logo-img {
            width: 40px;
            height: 40px;
            object-fit: cover;
            border-radius: 12px;
            border: 1px solid var(--gray-light);
        }
        .branch-logo-placeholder {
            width: 40px;
            height: 40px;
            background: #f1f5f9;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #94a3b8;
            font-size: 20px;
        }
        .action-btns {
            display: flex;
            gap: 8px;
        }
        .btn-icon {
            width: 32px;
            height: 32px;
            border-radius: 10px;
            border: 1px solid var(--gray-light);
            background: white;
            color: #475569;
            cursor: pointer;
            transition: 0.2s;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        .btn-icon:hover {
            background: #f8fafc;
            transform: translateY(-1px);
        }
        .btn-icon.btn-danger:hover {
            background: #fee2e2;
            border-color: var(--danger);
            color: var(--danger);
        }
        .modal-content {
            border-radius: 24px;
            border: none;
        }
        .form-control, .form-select {
            border-radius: 12px;
            border: 1px solid var(--gray-light);
            padding: 10px 14px;
            font-size: 14px;
        }
        .form-control:focus, .form-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(67,97,238,0.15);
        }
        .modal-header {
            border-bottom: 1px solid var(--gray-light);
            padding: 20px 24px;
            background: #fefefe;
        }
        .modal-footer {
            border-top: 1px solid var(--gray-light);
            padding: 16px 24px;
        }
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(255,255,255,0.7);
            z-index: 2000;
            display: none;
            align-items: center;
            justify-content: center;
        }
        .loading-overlay.active {
            display: flex;
        }
        .spinner {
            width: 40px;
            height: 40px;
            border: 3px solid var(--gray-light);
            border-top-color: var(--primary);
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        @media (max-width: 768px) {
            .content-page {
                padding: 16px;
            }
            .card-header-custom {
                flex-direction: column;
                align-items: stretch;
            }
        }
    </style>
</head>
<body>
<div id="wrapper">
    <?php include('assets/inc/nav.php'); ?>
    <?php include('assets/inc/sidebar.php'); ?>
    
    <div class="content-page">
        <div class="container-fluid">
            <div class="card-custom">
                <div class="card-header-custom">
                    <h4 class="mb-0"><i class="fas fa-building me-2 text-primary"></i> Multi-Tenancy & Branch Directory</h4>
                    <button class="btn-primary-custom" data-bs-toggle="modal" data-bs-target="#branchModal" onclick="resetBranchForm()">
                        <i class="fas fa-plus-circle"></i> Add New Branch
                    </button>
                </div>
                <div class="p-3">
                    <table id="branchesTable" class="data-table table table-hover" style="width:100%">
                        <thead>
                            <tr>
                                <th>Logo</th>
                                <th>Code</th>
                                <th>Branch Name</th>
                                <th>Location</th>
                                <th>Phone</th>
                                <th>Type</th>
                                <th>Status</th>
                                <th>Created</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- MODAL: Add / Edit Branch -->
<div class="modal fade" id="branchModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-light">
                <h5 class="modal-title"><i class="fas fa-hospital-user me-2"></i><span id="modalTitle">Add New Branch</span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="branchForm" enctype="multipart/form-data">
                <div class="modal-body">
                    <input type="hidden" name="branch_id" id="branchId" value="0">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Branch Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="name" id="branchName" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Branch Code <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="code" id="branchCode" required>
                            <small class="text-muted">Unique identifier</small>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Location / Address</label>
                            <textarea class="form-control" name="location" id="branchLocation" rows="2"></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Phone</label>
                            <input type="text" class="form-control" name="phone" id="branchPhone">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Email</label>
                            <input type="email" class="form-control" name="email" id="branchEmail">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Branch Type</label>
                            <select class="form-select" name="branch_type" id="branchType">
                                <option value="main_hospital">Main Hospital</option>
                                <option value="clinic">Clinic</option>
                                <option value="pharmacy">Pharmacy</option>
                                <option value="laboratory">Laboratory</option>
                                <option value="radiology_center">Radiology Center</option>
                                <option value="admin_office">Admin Office</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Status</label>
                            <select class="form-select" name="status" id="branchStatus">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive (Suspended)</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Tax Rate (%)</label>
                            <input type="number" step="0.01" class="form-control" name="tax_rate" id="taxRate" value="0.00">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Currency</label>
                            <select class="form-select" name="currency" id="currency">
                                <option value="ETB">Ethiopian Birr (ETB)</option>
                                <option value="USD">US Dollar (USD)</option>
                                <option value="EUR">Euro (EUR)</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Fiscal Year Start</label>
                            <input type="date" class="form-control" name="fiscal_year_start" id="fiscalStart">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Fiscal Year End</label>
                            <input type="date" class="form-control" name="fiscal_year_end" id="fiscalEnd">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Branch Logo (JPG, PNG, ≤2MB)</label>
                            <input type="file" class="form-control" name="branch_logo" id="branchLogo" accept="image/jpeg,image/png,image/jpg">
                            <div id="currentLogoPreview" class="mt-2" style="display:none;">
                                <img id="logoPreviewImg" src="" alt="Current Logo" style="max-height: 60px; border-radius: 8px; border: 1px solid #ddd;">
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="saveBranchBtn">Save Branch</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div id="loadingOverlay" class="loading-overlay"><div class="spinner"></div></div>

<script>
$(function() {
    var dataTable = $('#branchesTable').DataTable({
        processing: true,
        serverSide: true,
        ajax: {
            url: window.location.pathname + '?action=get_branches',
            type: 'GET',
            dataSrc: 'data'
        },
        columns: [
            { data: 'logo', orderable: false, searchable: false },
            { data: 'code' },
            { data: 'name' },
            { data: 'location' },
            { data: 'phone' },
            { data: 'branch_type' },
            { data: 'status' },
            { data: 'created' },
            { data: 'actions', orderable: false, searchable: false }
        ],
        language: { emptyTable: 'No branches found.' },
        order: [[2, 'asc']],
        responsive: true,
        pageLength: 25,
        lengthMenu: [[10, 25, 50, 100], [10, 25, 50, 100]]
    });

    window.resetBranchForm = function() {
        $('#branchForm')[0].reset();
        $('#branchId').val('0');
        $('#modalTitle').text('Add New Branch');
        $('#currentLogoPreview').hide();
        $('#branchLogo').val('');
        $('#taxRate').val('0.00');
        $('#currency').val('ETB');
        $('#fiscalStart').val('');
        $('#fiscalEnd').val('');
    };

    $(document).on('click', '.btn-edit-branch', function() {
        let row = dataTable.row($(this).closest('tr')).data();
        if (!row) return;
        $('#branchId').val(row.id);
        $('#branchName').val(row.name);
        $('#branchCode').val(row.code);
        $('#branchLocation').val(row.location);
        $('#branchPhone').val(row.phone);
        $('#branchEmail').val(row.email);
        $('#branchType').val(row.branch_type.toLowerCase().replace(' ', '_') || 'main_hospital');
        $('#branchStatus').val(row.status === 'Active' ? 'active' : 'inactive');
        $('#taxRate').val(row.tax_rate);
        $('#currency').val(row.currency);
        $('#fiscalStart').val(row.fiscal_start);
        $('#fiscalEnd').val(row.fiscal_end);
        if (row.logo_path && row.logo_path !== '/assets/images/default-hospital-logo.png') {
            $('#logoPreviewImg').attr('src', row.logo_path);
            $('#currentLogoPreview').show();
        } else {
            $('#currentLogoPreview').hide();
        }
        $('#modalTitle').text('Edit Branch Settings');
        $('#branchModal').modal('show');
    });

    $(document).on('click', '.btn-toggle-status', function() {
        let branchId = $(this).data('id');
        let currentStatus = $(this).data('status');
        Swal.fire({
            title: 'Confirm Status Change',
            text: `Are you sure you want to ${currentStatus === 'active' ? 'deactivate' : 'activate'} this branch?`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#4361ee',
            confirmButtonText: 'Yes, toggle'
        }).then(result => {
            if (result.isConfirmed) {
                showLoading(true);
                $.ajax({
                    url: window.location.pathname + '?action=toggle_status',
                    method: 'POST',
                    dataType: 'json',
                    data: {
                        branch_id: branchId,
                        current_status: currentStatus,
                        csrf_token: '<?php echo $csrf_token; ?>'
                    },
                    success: res => {
                        showLoading(false);
                        if (res.success) {
                            Swal.fire('Updated', res.message, 'success');
                            dataTable.ajax.reload(null, false);
                        } else {
                            Swal.fire('Error', res.message, 'error');
                        }
                    },
                    error: () => { showLoading(false); Swal.fire('Error', 'Network error', 'error'); }
                });
            }
        });
    });

    $(document).on('click', '.btn-delete-branch', function() {
        let branchId = $(this).data('id');
        let branchName = $(this).data('name');
        Swal.fire({
            title: 'Delete Branch?',
            text: `You are about to soft-delete "${branchName}". This action can be reversed by DB admin.`,
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            confirmButtonText: 'Yes, delete'
        }).then(result => {
            if (result.isConfirmed) {
                showLoading(true);
                $.ajax({
                    url: window.location.pathname + '?action=delete_branch',
                    method: 'POST',
                    dataType: 'json',
                    data: { branch_id: branchId, csrf_token: '<?php echo $csrf_token; ?>' },
                    success: res => {
                        showLoading(false);
                        if (res.success) {
                            Swal.fire('Deleted', res.message, 'success');
                            dataTable.ajax.reload(null, false);
                        } else {
                            Swal.fire('Error', res.message, 'error');
                        }
                    },
                    error: () => { showLoading(false); Swal.fire('Error', 'Network error', 'error'); }
                });
            }
        });
    });

    $('#branchForm').on('submit', function(e) {
        e.preventDefault();
        let formData = new FormData(this);
        let action = $('#branchId').val() == 0 ? 'create_branch' : 'update_branch';
        formData.append('action', action);
        showLoading(true);
        $.ajax({
            url: window.location.pathname + '?action=' + action,
            method: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            dataType: 'json',
            success: res => {
                showLoading(false);
                if (res.success) {
                    Swal.fire('Success', res.message, 'success');
                    $('#branchModal').modal('hide');
                    dataTable.ajax.reload(null, false);
                    resetBranchForm();
                } else {
                    Swal.fire('Validation Error', res.message, 'error');
                }
            },
            error: () => { showLoading(false); Swal.fire('System Error', 'Could not complete request. Check logs.', 'error'); }
        });
    });

    function showLoading(show) {
        if (show) $('#loadingOverlay').addClass('active');
        else $('#loadingOverlay').removeClass('active');
    }
});
</script>
<?php include('assets/inc/footer.php'); ?>
</body>
</html>