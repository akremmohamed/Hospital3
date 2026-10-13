<?php
/**
 * ======================================================================
 * SERVICE BILLING SYSTEM - HMS ERP (Fixed Foreign Key Issue)
 * ======================================================================
 */

ob_start();
session_start();

// ============================================
// ERROR REPORTING
// ============================================
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../../logs/billing_errors.log');

if (!is_dir(__DIR__ . '/../../logs')) {
    mkdir(__DIR__ . '/../../logs', 0755, true);
}

// ============================================
// SECURE SESSION CONFIGURATION
// ============================================
$user_id = (int)($_SESSION['user_id'] ?? 0);
$user_branch_id = (int)($_SESSION['branch_id'] ?? 0);
$is_global_admin = ($_SESSION['user_type'] ?? '') === 'super_admin';
$user_name = $_SESSION['first_name'] ?? $_SESSION['username'] ?? 'System User';

if ($user_id <= 0 || $user_branch_id <= 0) {
    header('Location: ../../index.php');
    exit;
}

// ============================================
// PDO DATABASE CONNECTION
// ============================================
require_once __DIR__ . '/../../config/db_connection.php';
require_once __DIR__ . '/../../includes/auth_helper.php';

date_default_timezone_set('Africa/Addis_Ababa');

$db_available = isset($pdo) && $pdo instanceof PDO;

if (!$db_available) {
    die('Database connection failed. Please contact system administrator.');
}

try {
    $pdo->exec("SET time_zone = '+03:00'");
    $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
} catch (PDOException $e) {
    error_log('Timezone set failed: ' . $e->getMessage());
}

// ============================================
// PERMISSION CHECK
// ============================================
$can_create_invoice = $is_global_admin || user_has_permission($pdo, 'create_invoice', $user_id);
$can_view_invoices = $is_global_admin || user_has_permission($pdo, 'view_invoices', $user_id);
$can_process_payment = $is_global_admin || user_has_permission($pdo, 'process_payment', $user_id);

if (!$can_create_invoice && !$can_process_payment) {
    header('Location: dashboard.php?error=unauthorized');
    exit;
}

// ============================================
// CSRF TOKEN
// ============================================
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// ============================================
// TAX RATE (Default 15% VAT)
// ============================================
$tax_rate = 15.00;
try {
    $tax_stmt = $pdo->prepare("SELECT rate FROM tax_categories WHERE code = 'VAT-STD' AND is_active = 1 LIMIT 1");
    $tax_stmt->execute();
    $tax_row = $tax_stmt->fetch(PDO::FETCH_ASSOC);
    if ($tax_row) {
        $tax_rate = (float)$tax_row['rate'];
    }
} catch (PDOException $e) {
    error_log('Tax rate fetch failed: ' . $e->getMessage());
}

// ============================================
// BRANCH INFO
// ============================================
$branch_name = 'Main Branch';
try {
    $branch_stmt = $pdo->prepare("SELECT name FROM branches WHERE id = :id AND deleted_at IS NULL LIMIT 1");
    $branch_stmt->execute([':id' => $user_branch_id]);
    $br = $branch_stmt->fetch(PDO::FETCH_ASSOC);
    if ($br) $branch_name = $br['name'];
} catch (PDOException $e) {
    error_log('Branch fetch failed: ' . $e->getMessage());
}

// ============================================
// SERVICES
// ============================================
$services = [];
try {
    $svc_query = "
        SELECT id, service_code as code, service_name as name, standard_price as price, 
               is_taxable, tax_rate, service_category
        FROM service_tariffs 
        WHERE is_active = 1 AND deleted_at IS NULL 
        AND (branch_id IS NULL OR branch_id = :branch_id)
        ORDER BY service_name ASC LIMIT 100
    ";
    $svc_stmt = $pdo->prepare($svc_query);
    $svc_stmt->execute([':branch_id' => $user_branch_id]);
    
    while ($svc = $svc_stmt->fetch(PDO::FETCH_ASSOC)) {
        $services[] = [
            'id' => (int)$svc['id'],
            'code' => $svc['code'] ?? '',
            'name' => $svc['name'],
            'price' => (float)($svc['price'] ?? 0),
            'is_taxable' => isset($svc['is_taxable']) ? (int)$svc['is_taxable'] : 1,
            'tax_rate' => (float)($svc['tax_rate'] ?? $tax_rate),
            'service_category' => $svc['service_category'] ?? 'consultation'
        ];
    }
} catch (PDOException $e) {
    error_log('Services fetch failed: ' . $e->getMessage());
}

if (empty($services)) {
    $services = [
        ['id' => 1, 'code' => 'CONS-GEN', 'name' => 'General Consultation', 'price' => 500.00, 'is_taxable' => 1, 'tax_rate' => $tax_rate, 'service_category' => 'consultation'],
        ['id' => 2, 'code' => 'CONS-SPEC', 'name' => 'Specialist Consultation', 'price' => 800.00, 'is_taxable' => 1, 'tax_rate' => $tax_rate, 'service_category' => 'consultation'],
    ];
}

// ============================================
// HELPER FUNCTIONS
// ============================================
function escapeHtml($str) {
    if ($str === null) return '';
    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}

function validateCsrfToken($submitted_token, $session_token) {
    return !empty($submitted_token) && !empty($session_token) && hash_equals($session_token, $submitted_token);
}

function sanitizeInput($input) {
    if (is_array($input)) {
        return array_map('sanitizeInput', $input);
    }
    return trim(htmlspecialchars((string)$input, ENT_QUOTES, 'UTF-8'));
}

function jsonResponse($success, $message, $data = null, $httpCode = 200) {
    if (ob_get_length()) ob_clean();
    http_response_code($httpCode);
    header('Content-Type: application/json');
    header('X-Content-Type-Options: nosniff');
    $response = ['success' => $success, 'message' => $message];
    if ($data !== null) {
        $response = array_merge($response, $data);
    }
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

// ============================================
// AJAX: SEARCH PATIENTS
// ============================================
if (isset($_GET['search_patients'])) {
    $search = sanitizeInput($_GET['search'] ?? '');
    $results = [];
    
    if (strlen($search) < 2) {
        jsonResponse(true, '', ['data' => []]);
    }
    
    try {
        $search_param = "%$search%";
        
        $stmt = $pdo->prepare("
            SELECT 
                p.id, 
                p.mr_number, 
                CONCAT(
                    COALESCE(p.first_name, ''), 
                    ' ', 
                    COALESCE(p.middle_name, ''), 
                    ' ', 
                    COALESCE(p.last_name, '')
                ) as name,
                p.first_name, 
                p.middle_name,
                p.last_name,
                TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) AS age,
                p.gender, 
                p.phone, 
                p.email,
                p.blood_group,
                p.address
            FROM patients p
            WHERE p.deleted_at IS NULL 
                AND p.is_active = 1
                AND p.branch_id = :branch_id
                AND (
                    p.first_name LIKE :search1 
                    OR p.last_name LIKE :search2 
                    OR p.mr_number LIKE :search3 
                    OR p.phone LIKE :search4
                    OR CONCAT(p.first_name, ' ', COALESCE(p.middle_name, ''), ' ', p.last_name) LIKE :search5
                )
            ORDER BY p.created_at DESC
            LIMIT 20
        ");
        
        $stmt->execute([
            ':branch_id' => $user_branch_id,
            ':search1' => $search_param,
            ':search2' => $search_param,
            ':search3' => $search_param,
            ':search4' => $search_param,
            ':search5' => $search_param
        ]);
        
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $results[] = [
                'id' => (int)$row['id'],
                'mr_number' => $row['mr_number'] ?? 'N/A',
                'name' => $row['name'] ?? 'Unknown',
                'first_name' => $row['first_name'] ?? '',
                'middle_name' => $row['middle_name'] ?? '',
                'last_name' => $row['last_name'] ?? '',
                'age' => (int)$row['age'],
                'gender' => $row['gender'] ?? 'N/A',
                'phone' => $row['phone'] ?? 'N/A',
                'email' => $row['email'] ?? 'N/A',
                'blood_group' => $row['blood_group'] ?? 'N/A',
                'address' => $row['address'] ?? ''
            ];
        }
        
        jsonResponse(true, '', ['data' => $results]);
        
    } catch (PDOException $e) {
        error_log('Patient search error: ' . $e->getMessage());
        jsonResponse(false, 'Unable to search patients. Please try again.', ['data' => []]);
    }
}

// ============================================
// AJAX: CREATE INVOICE (WITH VISIT CREATION)
// ============================================
if (isset($_POST['create_invoice'])) {
    // Validate permission
    if (!$can_create_invoice && !$can_process_payment) {
        jsonResponse(false, 'Permission denied');
    }
    
    // CSRF Validation
    $submitted_token = $_POST['csrf_token'] ?? '';
    if (!validateCsrfToken($submitted_token, $csrf_token)) {
        error_log("CSRF validation failed - User ID: {$user_id}");
        jsonResponse(false, 'Security validation failed. Please refresh the page.');
    }
    
    // Validate inputs
    $patient_id = (int)($_POST['patient_id'] ?? 0);
    $billing_type = sanitizeInput($_POST['billing_type'] ?? 'cash');
    $discount_amount = (float)($_POST['discount_amount'] ?? 0);
    $paid_amount = (float)($_POST['paid_amount'] ?? 0);
    $payment_method = sanitizeInput($_POST['payment_method'] ?? 'cash');
    $notes = sanitizeInput($_POST['notes'] ?? '');
    $items_json = $_POST['items'] ?? '[]';
    $items = json_decode($items_json, true);
    
    if ($patient_id <= 0) {
        jsonResponse(false, 'Invalid patient selection');
    }
    
    if (empty($items)) {
        jsonResponse(false, 'No items in cart');
    }
    
    try {
        // Start transaction
        $pdo->beginTransaction();
        
        // Verify patient exists and belongs to branch
        $verify_stmt = $pdo->prepare("
            SELECT id, first_name, last_name, mr_number 
            FROM patients 
            WHERE id = :id AND branch_id = :branch_id AND deleted_at IS NULL
        ");
        $verify_stmt->execute([
            ':id' => $patient_id,
            ':branch_id' => $user_branch_id
        ]);
        
        if ($verify_stmt->rowCount() === 0) {
            throw new Exception('Patient not found in this branch');
        }
        $patient = $verify_stmt->fetch(PDO::FETCH_ASSOC);
        
        // ============================================
        // CREATE A PATIENT VISIT (Required for foreign key)
        // ============================================
        $visit_number = 'VIS-' . date('Ymd') . '-' . str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
        $visit_date = date('Y-m-d');
        $visit_time = date('H:i:s');
        
        $visit_stmt = $pdo->prepare("
            INSERT INTO patient_visits 
            (branch_id, patient_id, visit_number, visit_date, visit_time, visit_type, 
             visit_mode, payment_mode, billing_status, status, created_by_user_id, created_at)
            VALUES 
            (:branch_id, :patient_id, :visit_number, :visit_date, :visit_time, 'walk_in',
             'opd', :payment_mode, 'unpaid', 'pending', :user_id, NOW())
        ");
        
        $visit_stmt->execute([
            ':branch_id' => $user_branch_id,
            ':patient_id' => $patient_id,
            ':visit_number' => $visit_number,
            ':visit_date' => $visit_date,
            ':visit_time' => $visit_time,
            ':payment_mode' => $billing_type,
            ':user_id' => $user_id
        ]);
        
        $visit_id = (int)$pdo->lastInsertId();
        
        // ============================================
        // VALIDATE SERVICES AND CALCULATE TOTALS
        // ============================================
        $validated_items = [];
        $subtotal = 0.00;
        $tax_amount = 0.00;
        
        foreach ($items as $item) {
            $service_id = (int)($item['service_id'] ?? 0);
            $quantity = max(1, (int)($item['quantity'] ?? 1));
            
            $service_stmt = $pdo->prepare("
                SELECT id, service_name, standard_price, is_taxable, tax_rate, service_category
                FROM service_tariffs 
                WHERE id = :id 
                    AND is_active = 1 
                    AND deleted_at IS NULL
                    AND (branch_id IS NULL OR branch_id = :branch_id)
                LIMIT 1
            ");
            $service_stmt->execute([
                ':id' => $service_id,
                ':branch_id' => $user_branch_id
            ]);
            
            $service = $service_stmt->fetch(PDO::FETCH_ASSOC);
            if (!$service) {
                throw new Exception("Service ID {$service_id} not found");
            }
            
            $price = (float)$service['standard_price'];
            $is_taxable = (int)$service['is_taxable'];
            $tax_rate_val = (float)($service['tax_rate'] ?? $tax_rate);
            $item_total = $price * $quantity;
            $item_tax = $is_taxable ? round($item_total * ($tax_rate_val / 100), 2) : 0;
            
            $subtotal += $item_total;
            $tax_amount += $item_tax;
            
            $validated_items[] = [
                'service_id' => $service_id,
                'service_name' => $service['service_name'],
                'service_category' => $service['service_category'] ?? 'consultation',
                'quantity' => $quantity,
                'unit_price' => $price,
                'tax_amount' => $item_tax
            ];
        }
        
        // Calculate totals
        $total_amount = round($subtotal + $tax_amount - $discount_amount, 2);
        $due_amount = max(0, round($total_amount - $paid_amount, 2));
        $payment_status = $due_amount <= 0 ? 'paid' : ($paid_amount > 0 ? 'partial' : 'unpaid');
        
        // Generate invoice number
        $invoice_number = 'INV-' . date('Ymd') . '-' . str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
        
        // ============================================
        // INSERT INVOICE (WITH visit_id)
        // ============================================
        $stmt = $pdo->prepare("
            INSERT INTO invoices 
            (branch_id, patient_id, visit_id, invoice_number, billing_type, subtotal, discount_amount, 
             tax_amount, total_amount, paid_amount, due_amount, payment_status, notes, 
             created_by_user_id, created_at, updated_at)
            VALUES 
            (:branch_id, :patient_id, :visit_id, :invoice_number, :billing_type, :subtotal, :discount_amount,
             :tax_amount, :total_amount, :paid_amount, :due_amount, :payment_status, :notes,
             :created_by, NOW(), NOW())
        ");
        
        $stmt->execute([
            ':branch_id' => $user_branch_id,
            ':patient_id' => $patient_id,
            ':visit_id' => $visit_id,
            ':invoice_number' => $invoice_number,
            ':billing_type' => $billing_type,
            ':subtotal' => round($subtotal, 2),
            ':discount_amount' => round($discount_amount, 2),
            ':tax_amount' => round($tax_amount, 2),
            ':total_amount' => $total_amount,
            ':paid_amount' => round($paid_amount, 2),
            ':due_amount' => $due_amount,
            ':payment_status' => $payment_status,
            ':notes' => $notes,
            ':created_by' => $user_id
        ]);
        
        $invoice_id = (int)$pdo->lastInsertId();
        
        // ============================================
        // INSERT INVOICE ITEMS
        // ============================================
        $item_stmt = $pdo->prepare("
            INSERT INTO invoice_items 
            (invoice_id, service_tariff_id, service_name, service_category, quantity, 
             unit_price, discount_amount, tax_amount, created_at)
            VALUES 
            (:invoice_id, :service_tariff_id, :service_name, :service_category, :quantity,
             :unit_price, 0, :tax_amount, NOW())
        ");
        
        foreach ($validated_items as $item) {
            $item_stmt->execute([
                ':invoice_id' => $invoice_id,
                ':service_tariff_id' => $item['service_id'],
                ':service_name' => $item['service_name'],
                ':service_category' => $item['service_category'],
                ':quantity' => $item['quantity'],
                ':unit_price' => round($item['unit_price'], 2),
                ':tax_amount' => round($item['tax_amount'], 2)
            ]);
        }
        
        // ============================================
        // RECORD PAYMENT IF APPLICABLE
        // ============================================
        if ($paid_amount > 0) {
            $receipt_number = 'RCP-' . date('Ymd') . '-' . str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
            
            $payment_stmt = $pdo->prepare("
                INSERT INTO payments 
                (branch_id, invoice_id, patient_id, receipt_number, payment_method, amount_paid, 
                 payment_date, received_by_user_id, is_verified, created_at)
                VALUES 
                (:branch_id, :invoice_id, :patient_id, :receipt_number, :payment_method, :amount_paid,
                 NOW(), :received_by, 1, NOW())
            ");
            
            $payment_stmt->execute([
                ':branch_id' => $user_branch_id,
                ':invoice_id' => $invoice_id,
                ':patient_id' => $patient_id,
                ':receipt_number' => $receipt_number,
                ':payment_method' => $payment_method,
                ':amount_paid' => round($paid_amount, 2),
                ':received_by' => $user_id
            ]);
        }
        
        // Commit transaction
        $pdo->commit();
        
        // Return success
        jsonResponse(true, 'Invoice created successfully', [
            'invoice_id' => $invoice_id,
            'invoice_number' => $invoice_number,
            'visit_id' => $visit_id,
            'total' => $total_amount,
            'paid' => round($paid_amount, 2),
            'due' => $due_amount,
            'payment_status' => $payment_status
        ]);
        
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Invoice creation error: ' . $e->getMessage());
        jsonResponse(false, 'Database error: ' . $e->getMessage());
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Invoice creation error: ' . $e->getMessage());
        jsonResponse(false, $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include('assets/inc/head.php'); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Billing - HMS ERP</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        :root { --primary: #1a237e; --primary-dark: #0d1b5e; --success: #10b981; --danger: #ef4444; --warning: #f59e0b; --gray: #6b7280; --gray-light: #f3f4f6; --dark: #1f2937; }
        body { font-family: 'Inter', sans-serif; background: linear-gradient(135deg, #f5f7fb 0%, #eef2f6 100%); }
        .content-page { margin-left: 260px; margin-top: 70px; padding: 24px 32px; min-height: calc(100vh - 70px); }
        @media (max-width: 768px) { .content-page { margin-left: 0; padding: 16px; } }
        .welcome-header { background: linear-gradient(135deg, var(--primary-dark) 0%, var(--primary) 100%); border-radius: 24px; padding: 24px 32px; color: white; margin-bottom: 28px; }
        .welcome-title { font-size: 24px; font-weight: 700; }
        .welcome-subtitle { color: rgba(255,255,255,0.7); font-size: 14px; }
        .search-container { background: white; border-radius: 20px; padding: 20px 24px; margin-bottom: 28px; border: 1px solid var(--gray-light); }
        .search-wrapper { position: relative; }
        .search-wrapper i { position: absolute; left: 16px; top: 50%; transform: translateY(-50%); color: var(--gray); }
        .patient-search-input { width: 100%; padding: 14px 20px 14px 48px; font-size: 1rem; border: 2px solid #e5e7eb; border-radius: 50px; transition: all 0.3s; }
        .patient-search-input:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(26,35,126,0.1); }
        .search-results-list { max-height: 400px; overflow-y: auto; border: 1px solid var(--gray-light); border-radius: 12px; margin-top: 16px; display: none; }
        .search-result-item { padding: 16px; cursor: pointer; border-bottom: 1px solid var(--gray-light); transition: background 0.2s; }
        .search-result-item:hover { background: #f8fafc; }
        .pos-grid { display: grid; grid-template-columns: 1fr 380px; gap: 24px; }
        @media (max-width: 1200px) { .pos-grid { grid-template-columns: 1fr; } }
        .card-module { background: white; border-radius: 20px; border: 1px solid var(--gray-light); overflow: hidden; margin-bottom: 24px; }
        .card-header { padding: 18px 24px; background: #f8fafc; border-bottom: 1px solid var(--gray-light); font-weight: 700; }
        .card-body { padding: 24px; }
        .patient-card { background: linear-gradient(135deg, var(--primary), var(--primary-dark)); border-radius: 16px; padding: 20px; color: white; margin-bottom: 20px; }
        .service-select-row { display: flex; gap: 12px; margin-bottom: 20px; }
        .service-select-row select { flex: 2; padding: 12px 16px; border: 1px solid var(--gray-light); border-radius: 12px; }
        .service-select-row button { flex: 1; }
        .cart-table { width: 100%; font-size: 13px; border-collapse: collapse; }
        .cart-table th { padding: 12px; background: #f8fafc; border-bottom: 1px solid var(--gray-light); text-align: left; }
        .cart-table td { padding: 12px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
        .cart-table input { width: 70px; padding: 6px; border: 1px solid var(--gray-light); border-radius: 8px; text-align: center; }
        .remove-item { color: var(--danger); cursor: pointer; transition: opacity 0.2s; }
        .remove-item:hover { opacity: 0.7; }
        .summary-card { background: #f8fafc; border-radius: 20px; padding: 20px; position: sticky; top: 90px; }
        .summary-row { display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #e5e7eb; }
        .summary-row.grand-total { font-size: 1.3rem; font-weight: 800; color: var(--primary); border-bottom: none; padding-top: 15px; }
        .form-control, .form-select { border-radius: 10px; border: 1px solid #e5e7eb; padding: 10px 12px; }
        .btn-primary-custom { background: linear-gradient(135deg, var(--primary), var(--primary-dark)); color: white; border: none; padding: 14px 24px; border-radius: 12px; font-weight: 600; width: 100%; cursor: pointer; transition: all 0.2s; }
        .btn-primary-custom:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(26,35,126,0.3); }
        .btn-primary-custom:disabled { opacity: 0.6; cursor: not-allowed; transform: none; }
        .btn-print-custom { background: linear-gradient(135deg, var(--success), #059669); color: white; border: none; padding: 14px 24px; border-radius: 12px; font-weight: 600; width: 100%; cursor: pointer; margin-top: 12px; }
        .btn-secondary-custom { background: #6b7280; color: white; border: none; padding: 14px 24px; border-radius: 12px; font-weight: 600; width: 100%; cursor: pointer; margin-top: 12px; }
        .loading-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); z-index: 2000; display: none; align-items: center; justify-content: center; backdrop-filter: blur(4px); }
        .loading-overlay.active { display: flex; }
        .spinner { width: 50px; height: 50px; border: 4px solid var(--gray-light); border-top-color: var(--primary); border-radius: 50%; animation: spin 0.8s linear infinite; background: white; padding: 10px; border-radius: 50%; }
        @keyframes spin { to { transform: rotate(360deg); } }
        .empty-cart { text-align: center; padding: 40px; color: var(--gray); }
        .text-muted { color: var(--gray); }
        .small { font-size: 11px; }
        .fw-bold { font-weight: 700; }
        .mt-3 { margin-top: 16px; }
        .mt-4 { margin-top: 24px; }
        .mb-0 { margin-bottom: 0; }
        .me-2 { margin-right: 8px; }
        .ms-2 { margin-left: 8px; }
        .text-center { text-align: center; }
        .text-danger { color: var(--danger) !important; }
        hr { margin: 12px 0; border-color: #e5e7eb; }
    </style>
</head>
<body>

<div id="wrapper">
    <?php include('assets/inc/nav.php'); ?>
    <?php include('assets/inc/sidebar.php'); ?>

    <div class="content-page">
        <div class="container-fluid">
            
            <div class="welcome-header">
                <div class="row align-items-center">
                    <div class="col-md-8">
                        <div class="welcome-title">Service Billing & POS 💳</div>
                        <div class="welcome-subtitle">Process patient payments and generate invoices</div>
                    </div>
                    <div class="col-md-4 text-md-end mt-3 mt-md-0">
                        <span class="badge bg-light text-dark p-2">
                            <i class="fas fa-building me-1"></i> <?php echo escapeHtml($branch_name); ?>
                        </span>
                    </div>
                </div>
            </div>
            
            <div class="search-container">
                <div class="search-wrapper">
                    <i class="fas fa-search"></i>
                    <input type="text" id="patientSearch" class="patient-search-input" 
                           placeholder="Search patient by Name, MRN, or Phone number..." autocomplete="off">
                </div>
                <div id="searchResults" class="search-results-list"></div>
            </div>
            
            <div id="billingSection" style="display: none;">
                <div class="patient-card">
                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
                        <div>
                            <h4 class="mb-1" id="patientName">-</h4>
                            <div class="d-flex flex-wrap gap-3 small">
                                <span><i class="fas fa-qrcode"></i> MRN: <span id="patientMRN">-</span></span>
                                <span><i class="fas fa-calendar-alt"></i> Age: <span id="patientAge">-</span></span>
                                <span><i class="fas fa-venus-mars"></i> <span id="patientGender">-</span></span>
                                <span><i class="fas fa-phone"></i> <span id="patientPhone">-</span></span>
                            </div>
                        </div>
                        <button class="btn btn-light btn-sm" onclick="clearSelectedPatient()">
                            <i class="fas fa-times me-1"></i> Change Patient
                        </button>
                    </div>
                </div>
                
                <div class="pos-grid">
                    <div>
                        <div class="card-module">
                            <div class="card-header"><i class="fas fa-cart-plus me-2"></i> Service Selection</div>
                            <div class="card-body">
                                <div class="service-select-row">
                                    <select id="serviceSelect" class="form-select">
                                        <option value="">-- Select a service --</option>
                                        <?php foreach ($services as $svc): ?>
                                        <option value="<?php echo $svc['id']; ?>" 
                                                data-price="<?php echo $svc['price']; ?>"
                                                data-name="<?php echo escapeHtml($svc['name']); ?>"
                                                data-taxable="<?php echo $svc['is_taxable']; ?>"
                                                data-tax-rate="<?php echo $svc['tax_rate']; ?>">
                                            <?php echo escapeHtml($svc['name']); ?> - ETB <?php echo number_format($svc['price'], 2); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button class="btn btn-success" onclick="addToCart()"><i class="fas fa-plus"></i> Add</button>
                                </div>
                            </div>
                        </div>
                        
                        <div class="card-module">
                            <div class="card-header"><i class="fas fa-shopping-cart me-2"></i> Current Cart</div>
                            <div class="card-body" style="padding: 0;">
                                <div style="overflow-x: auto;">
                                    <table class="cart-table">
                                        <thead>
                                            <tr><th>Service</th><th width="80">Qty</th><th width="100">Price</th><th width="100">Total</th><th width="40"></th></tr>
                                        </thead>
                                        <tbody id="cartBody"><tr><td colspan="5" class="empty-cart">Cart is empty</td></tr></tbody>
                                     </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div>
                        <div class="summary-card">
                            <h5 class="mb-3"><i class="fas fa-receipt me-2"></i> Invoice Summary</h5>
                            <div class="summary-row"><span>Subtotal:</span><span class="fw-bold" id="summarySubtotal">ETB 0.00</span></div>
                            <div class="summary-row"><span>Tax (VAT <?php echo $tax_rate; ?>%):</span><span id="summaryTax">ETB 0.00</span></div>
                            <div class="summary-row"><span>Discount:</span><div class="d-flex align-items-center gap-2"><input type="number" id="discountInput" class="form-control form-control-sm" style="width: 100px;" value="0" step="1" min="0" onchange="updateTotals()"><span>ETB</span></div></div>
                            <div class="summary-row grand-total"><span>Grand Total:</span><span id="summaryGrandTotal">ETB 0.00</span></div>
                            <hr>
                            <div class="summary-row"><span>Payment Method:</span><select id="paymentMethod" class="form-select form-select-sm"><option value="cash">Cash</option><option value="mobile_money">Mobile Money</option><option value="bank_transfer">Bank Transfer</option></select></div>
                            <div class="summary-row"><span>Amount Paid:</span><div class="d-flex align-items-center gap-2"><input type="number" id="paidAmount" class="form-control form-control-sm" style="width: 100px;" value="0" step="1" min="0" onchange="updateTotals()"><span>ETB</span></div></div>
                            <div class="summary-row" style="border-top: 2px solid var(--gray-light); margin-top: 8px; padding-top: 12px;"><span class="fw-bold">Due Amount:</span><span class="fw-bold text-danger" id="summaryDue">ETB 0.00</span></div>
                            <div class="mt-3"><label class="small text-muted">Notes</label><textarea id="invoiceNotes" class="form-control form-control-sm" rows="2"></textarea></div>
                            <div class="mt-4">
                                <button id="processBtn" class="btn-primary-custom" onclick="processPayment()"><i class="fas fa-save me-2"></i> Save Invoice</button>
                                <div id="printButtonGroup" style="display: none;">
                                    <button class="btn-print-custom" onclick="printLastInvoice()"><i class="fas fa-print me-2"></i> Print Receipt</button>
                                    <button class="btn-secondary-custom" onclick="startNewTransaction()"><i class="fas fa-plus me-2"></i> New Transaction</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <?php include('assets/inc/footer.php'); ?>
</div>

<div id="loadingOverlay" class="loading-overlay"><div class="spinner"></div></div>

<script src="assets/js/vendor.min.js"></script>
<script src="assets/js/bootstrap.bundle.min.js"></script>

<script>
let searchDebounceTimer;
let currentPatient = null;
let cart = [];
let isProcessing = false;
let lastInvoiceId = null;

const csrfToken = <?php echo json_encode($csrf_token); ?>;
const taxRate = <?php echo $tax_rate; ?>;

function escapeHtml(str) {
    if (!str) return '';
    return String(str).replace(/[&<>]/g, function(m) {
        return m === '&' ? '&amp;' : (m === '<' ? '&lt;' : '&gt;');
    });
}

function capitalizeFirst(str) {
    if (!str) return '';
    return str.charAt(0).toUpperCase() + str.slice(1);
}

function showLoading(show) {
    document.getElementById('loadingOverlay').classList.toggle('active', show);
}

function updateTotals() {
    let subtotal = 0, taxTotal = 0;
    cart.forEach(item => {
        const itemTotal = item.price * item.quantity;
        subtotal += itemTotal;
        if (item.is_taxable) taxTotal += itemTotal * (item.tax_rate / 100);
    });
    const discount = parseFloat($('#discountInput').val()) || 0;
    const grandTotal = subtotal + taxTotal - discount;
    const paid = parseFloat($('#paidAmount').val()) || 0;
    const due = Math.max(0, grandTotal - paid);
    
    $('#summarySubtotal').text(`ETB ${subtotal.toFixed(2)}`);
    $('#summaryTax').text(`ETB ${taxTotal.toFixed(2)}`);
    $('#summaryGrandTotal').text(`ETB ${grandTotal.toFixed(2)}`);
    $('#summaryDue').text(`ETB ${due.toFixed(2)}`);
}

function renderCart() {
    const tbody = $('#cartBody');
    if (cart.length === 0) {
        tbody.html('<tr><td colspan="5" class="empty-cart">Cart is empty</td></tr>');
        return;
    }
    let html = '';
    cart.forEach((item, index) => {
        const itemTotal = item.price * item.quantity;
        html += `<tr>
            <td>${escapeHtml(item.name)}</td>
            <td class="text-center"><input type="number" value="${item.quantity}" min="1" onchange="updateQuantity(${index}, this.value)"></td>
            <td class="text-end">ETB ${item.price.toFixed(2)}</td>
            <td class="text-end">ETB ${itemTotal.toFixed(2)}</td>
            <td class="text-center"><i class="fas fa-trash-alt remove-item" onclick="removeFromCart(${index})"></i></td>
        </tr>`;
    });
    tbody.html(html);
}

function addToCart() {
    const select = $('#serviceSelect');
    const option = select.find('option:selected');
    const serviceId = select.val();
    if (!serviceId) { Swal.fire('Error', 'Please select a service', 'error'); return; }
    
    const existing = cart.find(item => item.service_id === parseInt(serviceId));
    if (existing) { existing.quantity++; }
    else {
        cart.push({
            service_id: parseInt(serviceId),
            name: option.data('name'),
            price: parseFloat(option.data('price')),
            quantity: 1,
            is_taxable: option.data('taxable') === 1,
            tax_rate: parseFloat(option.data('tax-rate') || taxRate)
        });
    }
    renderCart();
    updateTotals();
    select.val('');
}

function removeFromCart(index) { cart.splice(index, 1); renderCart(); updateTotals(); }
function updateQuantity(index, quantity) {
    quantity = parseInt(quantity);
    if (isNaN(quantity) || quantity <= 0) cart.splice(index, 1);
    else cart[index].quantity = quantity;
    renderCart();
    updateTotals();
}

function clearSelectedPatient() {
    currentPatient = null;
    cart = [];
    renderCart();
    updateTotals();
    $('#billingSection').hide();
    $('#patientSearch').val('').focus();
    $('#printButtonGroup').hide();
    $('#discountInput').val(0);
    $('#paidAmount').val(0);
    $('#invoiceNotes').val('');
}

function selectPatient(patient) {
    currentPatient = patient;
    $('#patientName').text(patient.name);
    $('#patientMRN').text(patient.mr_number);
    $('#patientAge').text(patient.age ? `${patient.age} years` : 'N/A');
    $('#patientGender').text(patient.gender ? capitalizeFirst(patient.gender) : 'N/A');
    $('#patientPhone').text(patient.phone || 'N/A');
    $('#searchResults').hide();
    $('#patientSearch').val('');
    $('#billingSection').show();
    $('#printButtonGroup').hide();
    updateTotals();
}

$('#patientSearch').on('input', function() {
    clearTimeout(searchDebounceTimer);
    const search = $(this).val();
    if (search.length < 2) { $('#searchResults').hide(); return; }
    
    searchDebounceTimer = setTimeout(() => {
        showLoading(true);
        $.ajax({
            url: window.location.pathname,
            type: 'GET',
            data: { search_patients: 1, search: search },
            dataType: 'json',
            success: function(res) {
                showLoading(false);
                if (res.success && res.data && res.data.length > 0) {
                    let html = '';
                    res.data.forEach(p => {
                        html += `<div class="search-result-item" onclick='selectPatient(${JSON.stringify(p)})'>
                            <div class="fw-bold">${escapeHtml(p.name)}</div>
                            <div class="small text-muted">MRN: ${escapeHtml(p.mr_number)} | Age: ${p.age || 'N/A'}</div>
                        </div>`;
                    });
                    $('#searchResults').html(html).show();
                } else {
                    $('#searchResults').html('<div class="p-3 text-center text-muted">No patients found</div>').show();
                }
            },
            error: function() { showLoading(false); $('#searchResults').html('<div class="p-3 text-center text-danger">Error</div>').show(); }
        });
    }, 500);
});

$(document).on('click', function(e) {
    if (!$(e.target).closest('.search-container').length) $('#searchResults').hide();
});

function printLastInvoice() {
    if (lastInvoiceId) window.open(`print_receipt.php?invoice_id=${lastInvoiceId}`, '_blank');
    else Swal.fire('Error', 'No invoice to print', 'error');
}

function startNewTransaction() {
    clearSelectedPatient();
    $('#printButtonGroup').hide();
    Swal.fire('Ready', 'Start a new transaction', 'info');
}

function processPayment() {
    if (isProcessing) { Swal.fire('Info', 'Please wait...', 'info'); return; }
    if (!currentPatient) { Swal.fire('Error', 'Please select a patient', 'error'); return; }
    if (cart.length === 0) { Swal.fire('Error', 'Please add services', 'error'); return; }
    
    isProcessing = true;
    const btn = $('#processBtn');
    const originalHtml = btn.html();
    btn.html('<span class="spinner-border spinner-border-sm me-2"></span> Processing...').prop('disabled', true);
    
    $.ajax({
        url: window.location.pathname,
        type: 'POST',
        data: {
            create_invoice: 1,
            patient_id: currentPatient.id,
            billing_type: $('#paymentMethod').val() === 'cash' ? 'cash' : 'insurance',
            discount_amount: parseFloat($('#discountInput').val()) || 0,
            paid_amount: parseFloat($('#paidAmount').val()) || 0,
            payment_method: $('#paymentMethod').val(),
            notes: $('#invoiceNotes').val(),
            items: JSON.stringify(cart.map(i => ({ service_id: i.service_id, quantity: i.quantity }))),
            csrf_token: csrfToken
        },
        dataType: 'json',
        success: function(res) {
            isProcessing = false;
            btn.html(originalHtml).prop('disabled', false);
            if (res.success) {
                lastInvoiceId = res.invoice_id;
                Swal.fire({
                    title: 'Success!',
                    html: `Invoice #${res.invoice_number}<br>Total: ETB ${res.total}<br>Paid: ETB ${res.paid}<br>Due: ETB ${res.due}`,
                    icon: 'success',
                    confirmButtonText: 'Print Receipt'
                }).then(() => {
                    window.open(`print_receipt.php?invoice_id=${res.invoice_id}`, '_blank');
                    clearSelectedPatient();
                });
            } else {
                Swal.fire('Error', res.message, 'error');
            }
        },
        error: function(xhr) {
            isProcessing = false;
            btn.html(originalHtml).prop('disabled', false);
            let msg = 'Network error';
            try { const r = JSON.parse(xhr.responseText); if (r.message) msg = r.message; } catch(e) {}
            Swal.fire('Error', msg, 'error');
        }
    });
}
</script>
</body>
</html>