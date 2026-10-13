<?php
/**
 * test_catalog.php
 * 
 * HMS ERP - Laboratory Test Catalog Management
 * 
 * Features:
 * - View all lab tests with details
 * - Add new tests
 * - Edit existing tests
 * - Toggle test active status
 * - Filter by category and status
 * - Export test catalog
 * - Manage test pricing
 * - View test usage statistics
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
// 2. ACTION HANDLING
// ============================================
$action = $_GET['action'] ?? $_POST['action'] ?? '';
$test_id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$success_message = '';
$error_message = '';

// Handle adding new test
if ($action === 'add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $test_code = trim($_POST['test_code'] ?? '');
    $test_name = trim($_POST['test_name'] ?? '');
    $test_category = $_POST['test_category'] ?? 'other';
    $sample_type = $_POST['sample_type'] ?? 'blood';
    $sample_volume_ml = !empty($_POST['sample_volume_ml']) ? (float)$_POST['sample_volume_ml'] : null;
    $normal_range = trim($_POST['normal_range'] ?? '');
    $reference_range_low = !empty($_POST['reference_range_low']) ? (float)$_POST['reference_range_low'] : null;
    $reference_range_high = !empty($_POST['reference_range_high']) ? (float)$_POST['reference_range_high'] : null;
    $unit = trim($_POST['unit'] ?? '');
    $turnaround_time_hours = !empty($_POST['turnaround_time_hours']) ? (int)$_POST['turnaround_time_hours'] : null;
    $fasting_required = isset($_POST['fasting_required']) ? 1 : 0;
    $fasting_hours = !empty($_POST['fasting_hours']) ? (int)$_POST['fasting_hours'] : null;
    $special_precautions = trim($_POST['special_precautions'] ?? '');
    $price = (float)($_POST['price'] ?? 0);
    $insurance_price = !empty($_POST['insurance_price']) ? (float)$_POST['insurance_price'] : null;
    $display_order = (int)($_POST['display_order'] ?? 0);
    $department_id = !empty($_POST['department_id']) ? (int)$_POST['department_id'] : null;

    if (empty($test_code) || empty($test_name) || $price <= 0) {
        $error_message = 'Please fill in all required fields (Test Code, Test Name, and Price).';
    } else {
        try {
            // Check for duplicate test code
            $sql = "SELECT id FROM lab_tests_master WHERE test_code = :test_code AND deleted_at IS NULL";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':test_code' => $test_code]);
            if ($stmt->fetch()) {
                throw new Exception('A test with this code already exists.');
            }

            $sql = "INSERT INTO lab_tests_master (
                        test_code, test_name, test_category, sample_type,
                        sample_volume_ml, normal_range, reference_range_low,
                        reference_range_high, unit, turnaround_time_hours,
                        fasting_required, fasting_hours, special_precautions,
                        price, insurance_price, display_order, department_id,
                        is_active, created_by_user_id, created_at
                    ) VALUES (
                        :test_code, :test_name, :test_category, :sample_type,
                        :sample_volume_ml, :normal_range, :reference_range_low,
                        :reference_range_high, :unit, :turnaround_time_hours,
                        :fasting_required, :fasting_hours, :special_precautions,
                        :price, :insurance_price, :display_order, :department_id,
                        1, :created_by, NOW()
                    )";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':test_code' => $test_code,
                ':test_name' => $test_name,
                ':test_category' => $test_category,
                ':sample_type' => $sample_type,
                ':sample_volume_ml' => $sample_volume_ml,
                ':normal_range' => $normal_range,
                ':reference_range_low' => $reference_range_low,
                ':reference_range_high' => $reference_range_high,
                ':unit' => $unit,
                ':turnaround_time_hours' => $turnaround_time_hours,
                ':fasting_required' => $fasting_required,
                ':fasting_hours' => $fasting_hours,
                ':special_precautions' => $special_precautions,
                ':price' => $price,
                ':insurance_price' => $insurance_price,
                ':display_order' => $display_order,
                ':department_id' => $department_id,
                ':created_by' => $user_id
            ]);
            $test_id = $pdo->lastInsertId();

            $success_message = "Test '{$test_name}' added successfully!";

            // Log audit
            log_audit($pdo, $branch_id, $user_id, 'INSERT', 'lab_tests_master', $test_id, [
                'test_code' => $test_code,
                'test_name' => $test_name,
                'price' => $price
            ]);

        } catch (Exception $e) {
            $error_message = 'Error: ' . $e->getMessage();
            error_log('Test Add Error: ' . $e->getMessage());
        }
    }
}

// Handle editing test
if ($action === 'edit' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $test_id = (int)($_POST['test_id'] ?? 0);
    $test_code = trim($_POST['test_code'] ?? '');
    $test_name = trim($_POST['test_name'] ?? '');
    $test_category = $_POST['test_category'] ?? 'other';
    $sample_type = $_POST['sample_type'] ?? 'blood';
    $sample_volume_ml = !empty($_POST['sample_volume_ml']) ? (float)$_POST['sample_volume_ml'] : null;
    $normal_range = trim($_POST['normal_range'] ?? '');
    $reference_range_low = !empty($_POST['reference_range_low']) ? (float)$_POST['reference_range_low'] : null;
    $reference_range_high = !empty($_POST['reference_range_high']) ? (float)$_POST['reference_range_high'] : null;
    $unit = trim($_POST['unit'] ?? '');
    $turnaround_time_hours = !empty($_POST['turnaround_time_hours']) ? (int)$_POST['turnaround_time_hours'] : null;
    $fasting_required = isset($_POST['fasting_required']) ? 1 : 0;
    $fasting_hours = !empty($_POST['fasting_hours']) ? (int)$_POST['fasting_hours'] : null;
    $special_precautions = trim($_POST['special_precautions'] ?? '');
    $price = (float)($_POST['price'] ?? 0);
    $insurance_price = !empty($_POST['insurance_price']) ? (float)$_POST['insurance_price'] : null;
    $display_order = (int)($_POST['display_order'] ?? 0);
    $department_id = !empty($_POST['department_id']) ? (int)$_POST['department_id'] : null;
    $is_active = isset($_POST['is_active']) ? 1 : 0;

    if ($test_id <= 0 || empty($test_code) || empty($test_name) || $price <= 0) {
        $error_message = 'Please fill in all required fields.';
    } else {
        try {
            // Check for duplicate test code (excluding current test)
            $sql = "SELECT id FROM lab_tests_master 
                    WHERE test_code = :test_code AND id != :id AND deleted_at IS NULL";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':test_code' => $test_code, ':id' => $test_id]);
            if ($stmt->fetch()) {
                throw new Exception('A test with this code already exists.');
            }

            $sql = "UPDATE lab_tests_master SET
                        test_code = :test_code,
                        test_name = :test_name,
                        test_category = :test_category,
                        sample_type = :sample_type,
                        sample_volume_ml = :sample_volume_ml,
                        normal_range = :normal_range,
                        reference_range_low = :reference_range_low,
                        reference_range_high = :reference_range_high,
                        unit = :unit,
                        turnaround_time_hours = :turnaround_time_hours,
                        fasting_required = :fasting_required,
                        fasting_hours = :fasting_hours,
                        special_precautions = :special_precautions,
                        price = :price,
                        insurance_price = :insurance_price,
                        display_order = :display_order,
                        department_id = :department_id,
                        is_active = :is_active,
                        updated_at = NOW()
                    WHERE id = :id AND deleted_at IS NULL";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':test_code' => $test_code,
                ':test_name' => $test_name,
                ':test_category' => $test_category,
                ':sample_type' => $sample_type,
                ':sample_volume_ml' => $sample_volume_ml,
                ':normal_range' => $normal_range,
                ':reference_range_low' => $reference_range_low,
                ':reference_range_high' => $reference_range_high,
                ':unit' => $unit,
                ':turnaround_time_hours' => $turnaround_time_hours,
                ':fasting_required' => $fasting_required,
                ':fasting_hours' => $fasting_hours,
                ':special_precautions' => $special_precautions,
                ':price' => $price,
                ':insurance_price' => $insurance_price,
                ':display_order' => $display_order,
                ':department_id' => $department_id,
                ':is_active' => $is_active,
                ':id' => $test_id
            ]);

            $success_message = "Test updated successfully!";

            // Log audit
            log_audit($pdo, $branch_id, $user_id, 'UPDATE', 'lab_tests_master', $test_id, [
                'test_code' => $test_code,
                'test_name' => $test_name,
                'price' => $price
            ]);

        } catch (Exception $e) {
            $error_message = 'Error: ' . $e->getMessage();
            error_log('Test Edit Error: ' . $e->getMessage());
        }
    }
}

// Handle toggling test status
if ($action === 'toggle_status' && isset($_GET['id'])) {
    $test_id = (int)$_GET['id'];
    $status = isset($_GET['status']) ? (int)$_GET['status'] : 0;

    if ($test_id <= 0) {
        $error_message = 'Invalid test ID.';
    } else {
        try {
            $sql = "UPDATE lab_tests_master 
                    SET is_active = :status, updated_at = NOW()
                    WHERE id = :id AND deleted_at IS NULL";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':status' => $status, ':id' => $test_id]);

            $success_message = "Test status updated successfully.";

            // Log audit
            log_audit($pdo, $branch_id, $user_id, 'UPDATE', 'lab_tests_master', $test_id, [
                'is_active' => $status
            ]);

        } catch (Exception $e) {
            $error_message = 'Error: ' . $e->getMessage();
        }
    }
}

// Handle deleting test
if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $test_id = (int)($_POST['test_id'] ?? 0);

    if ($test_id <= 0) {
        $error_message = 'Invalid test ID.';
    } else {
        try {
            // Check if test is being used in lab_orders
            $sql = "SELECT COUNT(*) FROM lab_orders WHERE test_id = :test_id AND deleted_at IS NULL";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':test_id' => $test_id]);
            $usage_count = (int)$stmt->fetchColumn();

            if ($usage_count > 0) {
                throw new Exception("Cannot delete this test. It is referenced in {$usage_count} lab order(s).");
            }

            $sql = "UPDATE lab_tests_master 
                    SET deleted_at = NOW(), updated_at = NOW()
                    WHERE id = :id AND deleted_at IS NULL";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':id' => $test_id]);

            $success_message = "Test deleted successfully.";

            // Log audit
            log_audit($pdo, $branch_id, $user_id, 'DELETE', 'lab_tests_master', $test_id, []);

        } catch (Exception $e) {
            $error_message = 'Error: ' . $e->getMessage();
        }
    }
}

// ============================================
// 3. HELPER FUNCTIONS
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
// 4. DATA FETCHING
// ============================================

// Build filter parameters
$filter_category = $_GET['category'] ?? '';
$filter_status = $_GET['status'] ?? '';
$search = trim($_GET['search'] ?? '');

// Additional filters
$filter_sql = "";
$params = [];

if (!empty($filter_category)) {
    $filter_sql .= " AND test_category = :category";
    $params[':category'] = $filter_category;
}
if ($filter_status === 'active') {
    $filter_sql .= " AND is_active = 1";
} elseif ($filter_status === 'inactive') {
    $filter_sql .= " AND is_active = 0";
}
if (!empty($search)) {
    $filter_sql .= " AND (test_code LIKE :search OR test_name LIKE :search)";
    $params[':search'] = "%{$search}%";
}

// Fetch test categories for filter
$sql = "SELECT DISTINCT test_category FROM lab_tests_master WHERE deleted_at IS NULL ORDER BY test_category";
$categories = $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN);

// Fetch tests
$sql = "SELECT 
            ltm.id, ltm.test_code, ltm.test_name, ltm.test_category,
            ltm.sample_type, ltm.sample_volume_ml,
            ltm.normal_range, ltm.reference_range_low, ltm.reference_range_high,
            ltm.unit, ltm.turnaround_time_hours,
            ltm.fasting_required, ltm.fasting_hours,
            ltm.special_precautions,
            ltm.price, ltm.insurance_price,
            ltm.is_active, ltm.display_order,
            ltm.department_id, ltm.created_at, ltm.updated_at,
            d.name as department_name,
            CONCAT(u.first_name, ' ', u.last_name) as created_by_name,
            (SELECT COUNT(*) FROM lab_orders WHERE test_id = ltm.id AND deleted_at IS NULL) as usage_count
        FROM lab_tests_master ltm
        LEFT JOIN departments d ON ltm.department_id = d.id
        LEFT JOIN users u ON ltm.created_by_user_id = u.id
        WHERE ltm.deleted_at IS NULL" . $filter_sql . "
        ORDER BY ltm.display_order ASC, ltm.test_name ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$tests = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get sample types for dropdown
$sample_types = ['blood', 'serum', 'plasma', 'urine', 'stool', 'sputum', 'csf', 'swab', 'tissue', 'saliva', 'bone_marrow', 'other'];

// Get departments for dropdown
$sql = "SELECT id, name FROM departments WHERE is_active = 1 AND deleted_at IS NULL ORDER BY name";
$departments = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

// Get statistics
$stats = [
    'total' => 0,
    'active' => 0,
    'inactive' => 0,
    'categories' => count($categories)
];

foreach ($tests as $test) {
    $stats['total']++;
    if ($test['is_active']) {
        $stats['active']++;
    } else {
        $stats['inactive']++;
    }
}

// Get category breakdown
$sql = "SELECT test_category, COUNT(*) as count 
        FROM lab_tests_master 
        WHERE deleted_at IS NULL 
        GROUP BY test_category 
        ORDER BY count DESC";
$category_breakdown = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

// Handle AJAX request for getting test details
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_test' && isset($_GET['id'])) {
    header('Content-Type: application/json');
    $test_id = (int)$_GET['id'];
    
    try {
        $sql = "SELECT * FROM lab_tests_master WHERE id = :id AND deleted_at IS NULL";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':id' => $test_id]);
        $test = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($test) {
            echo json_encode(['success' => true, 'test' => $test]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Test not found']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Test Catalog | HMS ERP</title>
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
        .page-header h1 i { color: #4cc9f0; }
        
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
        .btn:hover { background: #f3f4f6; border-color: #4cc9f0; }
        .btn-primary { background: #4cc9f0; color: white; border-color: #4cc9f0; }
        .btn-primary:hover { background: #3ab0d6; border-color: #3ab0d6; color: white; }
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
        .stat-pill.total { border-left: 3px solid #4cc9f0; }
        .stat-pill.active { border-left: 3px solid #06d6a0; }
        .stat-pill.inactive { border-left: 3px solid #ef476f; }
        .stat-pill.categories { border-left: 3px solid #f9c74f; }
        
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
        
        .order-actions {
            display: flex;
            gap: 4px;
            flex-wrap: wrap;
        }
        .order-actions .btn { padding: 2px 8px; font-size: 11px; }
        
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
            border-color: #4cc9f0;
            box-shadow: 0 0 0 3px rgba(76, 201, 240, 0.1);
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
            accent-color: #4cc9f0;
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
        
        .category-breakdown {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
        }
        .category-tag {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            background: #f3f4f6;
            color: #6b7280;
        }
        .category-tag .count {
            font-weight: 700;
            color: #1f2937;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .filter-bar { flex-direction: column; align-items: stretch; }
            .filter-bar select, .filter-bar input { width: 100%; }
            .stats-bar { gap: 6px; }
            .stat-pill { font-size: 11px; padding: 4px 10px; }
            .page-header h1 { font-size: 20px; }
            .card-body { padding: 12px; }
            .table-container { padding: 0; overflow-x: auto; }
            .form-row { grid-template-columns: 1fr; }
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
            <h1><i class="fas fa-book-medical"></i> Test Catalog</h1>
            <div>
                <button class="btn btn-primary" onclick="openModal('addModal')">
                    <i class="fas fa-plus"></i> Add Test
                </button>
                <button class="btn" onclick="exportCSV()">
                    <i class="fas fa-file-export"></i> Export
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
                <span class="label">Total Tests</span>
            </div>
            <div class="stat-pill active">
                <span class="count" style="color:#06d6a0;"><?php echo $stats['active']; ?></span>
                <span class="label">Active</span>
            </div>
            <div class="stat-pill inactive">
                <span class="count" style="color:#ef476f;"><?php echo $stats['inactive']; ?></span>
                <span class="label">Inactive</span>
            </div>
            <div class="stat-pill categories">
                <span class="count" style="color:#d97706;"><?php echo $stats['categories']; ?></span>
                <span class="label">Categories</span>
            </div>
        </div>

        <!-- Category Breakdown -->
        <?php if (!empty($category_breakdown)): ?>
            <div style="background:white;padding:12px 20px;border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,0.08);margin-bottom:20px;">
                <span style="font-size:12px;color:#6b7280;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;margin-right:12px;">
                    Categories:
                </span>
                <div class="category-breakdown">
                    <?php foreach ($category_breakdown as $cat): ?>
                        <span class="category-tag">
                            <?php echo ucfirst(str_replace('_', ' ', $cat['test_category'])); ?>
                            <span class="count">(<?php echo $cat['count']; ?>)</span>
                        </span>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Filter Bar -->
        <div class="filter-bar">
            <div>
                <span class="filter-label">Category</span>
                <select id="filterCategory" onchange="applyFilters()">
                    <option value="">All</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?php echo htmlspecialchars($cat); ?>" <?php echo $filter_category === $cat ? 'selected' : ''; ?>>
                            <?php echo ucfirst(str_replace('_', ' ', $cat)); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <span class="filter-label">Status</span>
                <select id="filterStatus" onchange="applyFilters()">
                    <option value="">All</option>
                    <option value="active" <?php echo $filter_status === 'active' ? 'selected' : ''; ?>>Active</option>
                    <option value="inactive" <?php echo $filter_status === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                </select>
            </div>
            <div style="flex:1;min-width:150px;">
                <input type="text" class="form-control" id="filterSearch" placeholder="Search by code or name..." 
                       value="<?php echo htmlspecialchars($search); ?>" onkeypress="if(event.key==='Enter') applyFilters()">
            </div>
            <button class="btn btn-primary" onclick="applyFilters()"><i class="fas fa-search"></i> Filter</button>
            <button class="btn" onclick="resetFilters()"><i class="fas fa-times"></i> Reset</button>
        </div>

        <!-- Test Table -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-list"></i> Test Catalog</h3>
                <span class="text-muted"><?php echo count($tests); ?> tests</span>
            </div>
            <div class="card-body">
                <div class="table-container">
                    <table id="testTable">
                        <thead>
                            <tr>
                                <th>Code</th>
                                <th>Test Name</th>
                                <th>Category</th>
                                <th>Sample</th>
                                <th>Price (ETB)</th>
                                <th>TAT</th>
                                <th>Status</th>
                                <th>Usage</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($tests)): ?>
                                <tr>
                                    <td colspan="9" class="no-data">
                                        <i class="fas fa-inbox"></i>
                                        No tests found
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($tests as $test): ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo htmlspecialchars($test['test_code']); ?></strong>
                                        </td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($test['test_name']); ?></strong>
                                            <?php if ($test['department_name']): ?>
                                                <br><small class="text-muted"><?php echo htmlspecialchars($test['department_name']); ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge badge-info">
                                                <?php echo ucfirst(str_replace('_', ' ', $test['test_category'])); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php echo ucfirst(str_replace('_', ' ', $test['sample_type'])); ?>
                                            <?php if ($test['sample_volume_ml']): ?>
                                                <br><small class="text-muted"><?php echo $test['sample_volume_ml']; ?> ml</small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div><strong><?php echo number_format($test['price'], 2); ?></strong></div>
                                            <?php if ($test['insurance_price']): ?>
                                                <small class="text-muted">Ins: <?php echo number_format($test['insurance_price'], 2); ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($test['turnaround_time_hours']): ?>
                                                <?php echo $test['turnaround_time_hours']; ?> hrs
                                            <?php else: ?>
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
                                            <?php if ($test['fasting_required']): ?>
                                                <br><span class="badge badge-warning" style="font-size:9px;">Fasting</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($test['is_active']): ?>
                                                <span class="badge badge-active">Active</span>
                                            <?php else: ?>
                                                <span class="badge badge-inactive">Inactive</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <?php if ($test['usage_count'] > 0): ?>
                                                <span class="badge badge-primary"><?php echo $test['usage_count']; ?></span>
                                            <?php else: ?>
                                                <span class="text-muted">0</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="order-actions">
                                                <button class="btn btn-sm btn-warning" onclick="editTest(<?php echo $test['id']; ?>)">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button class="btn btn-sm <?php echo $test['is_active'] ? 'btn-danger' : 'btn-success'; ?>" 
                                                        onclick="toggleStatus(<?php echo $test['id']; ?>, <?php echo $test['is_active'] ? 0 : 1; ?>)">
                                                    <i class="fas <?php echo $test['is_active'] ? 'fa-pause' : 'fa-play'; ?>"></i>
                                                </button>
                                                <?php if ($test['usage_count'] == 0): ?>
                                                    <button class="btn btn-sm btn-danger" onclick="deleteTest(<?php echo $test['id']; ?>, '<?php echo htmlspecialchars($test['test_name']); ?>')">
                                                        <i class="fas fa-trash"></i>
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
    </div>
</div>

<!-- ============================================ -->
<!-- ADD TEST MODAL -->
<!-- ============================================ -->
<div class="modal-overlay" id="addModal">
    <div class="modal">
        <div class="modal-header">
            <h2><i class="fas fa-plus-circle"></i> Add Test</h2>
            <button class="modal-close" onclick="closeModal('addModal')">&times;</button>
        </div>
        <form method="POST" action="">
            <input type="hidden" name="action" value="add">
            <div class="modal-body">
                <div class="form-row">
                    <div class="form-group">
                        <label>Test Code <span class="required">*</span></label>
                        <input type="text" name="test_code" class="form-control" required placeholder="e.g., CBC">
                    </div>
                    <div class="form-group">
                        <label>Test Name <span class="required">*</span></label>
                        <input type="text" name="test_name" class="form-control" required placeholder="e.g., Complete Blood Count">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Category <span class="required">*</span></label>
                        <select name="test_category" class="form-control" required>
                            <option value="hematology">Hematology</option>
                            <option value="biochemistry">Biochemistry</option>
                            <option value="microbiology">Microbiology</option>
                            <option value="pathology">Pathology</option>
                            <option value="immunology">Immunology</option>
                            <option value="serology">Serology</option>
                            <option value="urinalysis">Urinalysis</option>
                            <option value="stool_analysis">Stool Analysis</option>
                            <option value="hormone">Hormone</option>
                            <option value="toxicology">Toxicology</option>
                            <option value="histopathology">Histopathology</option>
                            <option value="pcr">PCR</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Sample Type <span class="required">*</span></label>
                        <select name="sample_type" class="form-control" required>
                            <option value="blood">Blood</option>
                            <option value="serum">Serum</option>
                            <option value="plasma">Plasma</option>
                            <option value="urine">Urine</option>
                            <option value="stool">Stool</option>
                            <option value="sputum">Sputum</option>
                            <option value="csf">CSF</option>
                            <option value="swab">Swab</option>
                            <option value="tissue">Tissue</option>
                            <option value="saliva">Saliva</option>
                            <option value="bone_marrow">Bone Marrow</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Sample Volume (ml)</label>
                        <input type="number" name="sample_volume_ml" class="form-control" step="0.1" min="0" placeholder="e.g., 5">
                    </div>
                    <div class="form-group">
                        <label>Turnaround Time (hours)</label>
                        <input type="number" name="turnaround_time_hours" class="form-control" min="1" placeholder="e.g., 24">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Reference Range Low</label>
                        <input type="number" name="reference_range_low" class="form-control" step="any" placeholder="e.g., 4.5">
                    </div>
                    <div class="form-group">
                        <label>Reference Range High</label>
                        <input type="number" name="reference_range_high" class="form-control" step="any" placeholder="e.g., 11.0">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Unit</label>
                        <input type="text" name="unit" class="form-control" placeholder="e.g., g/dL, mg/dL">
                    </div>
                    <div class="form-group">
                        <label>Normal Range Text</label>
                        <input type="text" name="normal_range" class="form-control" placeholder="e.g., WBC:4.5-11.0">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Price (ETB) <span class="required">*</span></label>
                        <input type="number" name="price" class="form-control" required step="0.01" min="0" placeholder="0.00">
                    </div>
                    <div class="form-group">
                        <label>Insurance Price (ETB)</label>
                        <input type="number" name="insurance_price" class="form-control" step="0.01" min="0" placeholder="0.00">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Department</label>
                        <select name="department_id" class="form-control">
                            <option value="">None</option>
                            <?php foreach ($departments as $dept): ?>
                                <option value="<?php echo $dept['id']; ?>"><?php echo htmlspecialchars($dept['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Display Order</label>
                        <input type="number" name="display_order" class="form-control" min="0" value="0">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <div class="form-check">
                            <input type="checkbox" name="fasting_required" id="fasting_required" value="1">
                            <label for="fasting_required">Fasting Required</label>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Fasting Hours</label>
                        <input type="number" name="fasting_hours" class="form-control" min="0" placeholder="e.g., 8">
                    </div>
                </div>

                <div class="form-group">
                    <label>Special Precautions</label>
                    <textarea name="special_precautions" class="form-control" placeholder="Any special handling or patient preparation instructions..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" onclick="closeModal('addModal')">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Add Test</button>
            </div>
        </form>
    </div>
</div>

<!-- ============================================ -->
<!-- EDIT TEST MODAL -->
<!-- ============================================ -->
<div class="modal-overlay" id="editModal">
    <div class="modal">
        <div class="modal-header">
            <h2><i class="fas fa-edit"></i> Edit Test</h2>
            <button class="modal-close" onclick="closeModal('editModal')">&times;</button>
        </div>
        <form method="POST" action="">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="test_id" id="editTestId">
            <div class="modal-body" id="editFormContent">
                <p class="text-muted text-center">Loading test details...</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" onclick="closeModal('editModal')">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Update Test</button>
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
// TEST ACTIONS
// ============================================
function editTest(testId) {
    document.getElementById('editTestId').value = testId;
    document.getElementById('editFormContent').innerHTML = '<p class="text-muted text-center"><i class="fas fa-spinner fa-spin"></i> Loading...</p>';
    openModal('editModal');
    
    fetch('?ajax=get_test&id=' + testId)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const test = data.test;
                let html = `
                    <div class="form-row">
                        <div class="form-group">
                            <label>Test Code <span class="required">*</span></label>
                            <input type="text" name="test_code" class="form-control" required value="${test.test_code}">
                        </div>
                        <div class="form-group">
                            <label>Test Name <span class="required">*</span></label>
                            <input type="text" name="test_name" class="form-control" required value="${test.test_name}">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Category <span class="required">*</span></label>
                            <select name="test_category" class="form-control" required>
                                <option value="hematology" ${test.test_category === 'hematology' ? 'selected' : ''}>Hematology</option>
                                <option value="biochemistry" ${test.test_category === 'biochemistry' ? 'selected' : ''}>Biochemistry</option>
                                <option value="microbiology" ${test.test_category === 'microbiology' ? 'selected' : ''}>Microbiology</option>
                                <option value="pathology" ${test.test_category === 'pathology' ? 'selected' : ''}>Pathology</option>
                                <option value="immunology" ${test.test_category === 'immunology' ? 'selected' : ''}>Immunology</option>
                                <option value="serology" ${test.test_category === 'serology' ? 'selected' : ''}>Serology</option>
                                <option value="urinalysis" ${test.test_category === 'urinalysis' ? 'selected' : ''}>Urinalysis</option>
                                <option value="stool_analysis" ${test.test_category === 'stool_analysis' ? 'selected' : ''}>Stool Analysis</option>
                                <option value="hormone" ${test.test_category === 'hormone' ? 'selected' : ''}>Hormone</option>
                                <option value="toxicology" ${test.test_category === 'toxicology' ? 'selected' : ''}>Toxicology</option>
                                <option value="histopathology" ${test.test_category === 'histopathology' ? 'selected' : ''}>Histopathology</option>
                                <option value="pcr" ${test.test_category === 'pcr' ? 'selected' : ''}>PCR</option>
                                <option value="other" ${test.test_category === 'other' ? 'selected' : ''}>Other</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Sample Type <span class="required">*</span></label>
                            <select name="sample_type" class="form-control" required>
                                <option value="blood" ${test.sample_type === 'blood' ? 'selected' : ''}>Blood</option>
                                <option value="serum" ${test.sample_type === 'serum' ? 'selected' : ''}>Serum</option>
                                <option value="plasma" ${test.sample_type === 'plasma' ? 'selected' : ''}>Plasma</option>
                                <option value="urine" ${test.sample_type === 'urine' ? 'selected' : ''}>Urine</option>
                                <option value="stool" ${test.sample_type === 'stool' ? 'selected' : ''}>Stool</option>
                                <option value="sputum" ${test.sample_type === 'sputum' ? 'selected' : ''}>Sputum</option>
                                <option value="csf" ${test.sample_type === 'csf' ? 'selected' : ''}>CSF</option>
                                <option value="swab" ${test.sample_type === 'swab' ? 'selected' : ''}>Swab</option>
                                <option value="tissue" ${test.sample_type === 'tissue' ? 'selected' : ''}>Tissue</option>
                                <option value="saliva" ${test.sample_type === 'saliva' ? 'selected' : ''}>Saliva</option>
                                <option value="bone_marrow" ${test.sample_type === 'bone_marrow' ? 'selected' : ''}>Bone Marrow</option>
                                <option value="other" ${test.sample_type === 'other' ? 'selected' : ''}>Other</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Sample Volume (ml)</label>
                            <input type="number" name="sample_volume_ml" class="form-control" step="0.1" min="0" value="${test.sample_volume_ml || ''}">
                        </div>
                        <div class="form-group">
                            <label>Turnaround Time (hours)</label>
                            <input type="number" name="turnaround_time_hours" class="form-control" min="1" value="${test.turnaround_time_hours || ''}">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Reference Range Low</label>
                            <input type="number" name="reference_range_low" class="form-control" step="any" value="${test.reference_range_low || ''}">
                        </div>
                        <div class="form-group">
                            <label>Reference Range High</label>
                            <input type="number" name="reference_range_high" class="form-control" step="any" value="${test.reference_range_high || ''}">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Unit</label>
                            <input type="text" name="unit" class="form-control" value="${test.unit || ''}">
                        </div>
                        <div class="form-group">
                            <label>Normal Range Text</label>
                            <input type="text" name="normal_range" class="form-control" value="${test.normal_range || ''}">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Price (ETB) <span class="required">*</span></label>
                            <input type="number" name="price" class="form-control" required step="0.01" min="0" value="${test.price}">
                        </div>
                        <div class="form-group">
                            <label>Insurance Price (ETB)</label>
                            <input type="number" name="insurance_price" class="form-control" step="0.01" min="0" value="${test.insurance_price || ''}">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Department</label>
                            <select name="department_id" class="form-control">
                                <option value="">None</option>
                                <?php foreach ($departments as $dept): ?>
                                    <option value="<?php echo $dept['id']; ?>" ${test.department_id == <?php echo $dept['id']; ?> ? 'selected' : ''}>
                                        <?php echo htmlspecialchars($dept['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Display Order</label>
                            <input type="number" name="display_order" class="form-control" min="0" value="${test.display_order || 0}">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <div class="form-check">
                                <input type="checkbox" name="fasting_required" id="edit_fasting_required" value="1" ${test.fasting_required ? 'checked' : ''}>
                                <label for="edit_fasting_required">Fasting Required</label>
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Fasting Hours</label>
                            <input type="number" name="fasting_hours" class="form-control" min="0" value="${test.fasting_hours || ''}">
                        </div>
                    </div>

                    <div class="form-group">
                        <div class="form-check">
                            <input type="checkbox" name="is_active" id="edit_is_active" value="1" ${test.is_active ? 'checked' : ''}>
                            <label for="edit_is_active">Active</label>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Special Precautions</label>
                        <textarea name="special_precautions" class="form-control">${test.special_precautions || ''}</textarea>
                    </div>
                `;
                document.getElementById('editFormContent').innerHTML = html;
            } else {
                document.getElementById('editFormContent').innerHTML = '<p class="text-danger">Error loading test details.</p>';
            }
        })
        .catch(error => {
            document.getElementById('editFormContent').innerHTML = '<p class="text-danger">Error loading test details.</p>';
            console.error('Error:', error);
        });
}

function toggleStatus(testId, newStatus) {
    const action = newStatus ? 'activate' : 'deactivate';
    if (!confirm(`Are you sure you want to ${action} this test?`)) return;
    window.location.href = '?action=toggle_status&id=' + testId + '&status=' + newStatus;
}

function deleteTest(testId, testName) {
    if (!confirm(`Are you sure you want to delete "${testName}"? This action cannot be undone.`)) return;
    
    const form = document.createElement('form');
    form.method = 'POST';
    form.innerHTML = `
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="test_id" value="${testId}">
    `;
    document.body.appendChild(form);
    form.submit();
}

// ============================================
// FILTER FUNCTIONS
// ============================================
function applyFilters() {
    const category = document.getElementById('filterCategory').value;
    const status = document.getElementById('filterStatus').value;
    const search = document.getElementById('filterSearch').value;
    
    let url = window.location.pathname + '?';
    if (category) url += 'category=' + encodeURIComponent(category) + '&';
    if (status) url += 'status=' + encodeURIComponent(status) + '&';
    if (search) url += 'search=' + encodeURIComponent(search) + '&';
    window.location.href = url;
}

function resetFilters() {
    window.location.href = window.location.pathname;
}

// ============================================
// EXPORT CSV
// ============================================
function exportCSV() {
    window.location.href = 'ajax/export_test_catalog_csv.php' + window.location.search;
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
// DATATABLES INITIALIZATION
// ============================================
$(document).ready(function() {
    var hasRows = $('#testTable tbody tr:not(.no-data)').length > 0;
    
    if (hasRows) {
        $('#testTable').DataTable({
            responsive: true,
            pageLength: 25,
            order: [[0, 'asc']],
            columnDefs: [
                { orderable: false, targets: [8] }
            ],
            language: {
                emptyTable: "No tests found",
                search: "Search:",
                lengthMenu: "Show _MENU_ entries",
                info: "Showing _START_ to _END_ of _TOTAL_ entries"
            }
        });
    } else {
        $('#testTable').DataTable({
            responsive: true,
            pageLength: 25,
            order: [[0, 'asc']],
            columnDefs: [
                { orderable: false, targets: [8] }
            ],
            language: {
                emptyTable: "No tests found"
            }
        });
    }
});
</script>

<!-- DataTables -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>

</body>
</html>