<?php
/**
 * radiology_orders_queue.php
 * 
 * HMS ERP - Radiology Orders Queue Management
 * 
 * Features:
 * - View all radiology orders with filtering
 * - Create new radiology orders
 * - Update order status (pending_payment → scheduled → in_progress → completed → reviewed)
 * - Payment verification
 * - Order cancellation
 * - Print orders
 * - Modality-specific workflow
 * - Interpretation/report management
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

// Handle AJAX requests for order details
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_order' && isset($_GET['id'])) {
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
                    ro.cancellation_reason, ro.physician_review_notes,
                    CONCAT(p.first_name, ' ', COALESCE(p.middle_name, ''), ' ', p.last_name) as patient_name,
                    p.mr_number,
                    CONCAT(u.first_name, ' ', u.last_name) as doctor_name,
                    v.visit_number
                FROM radiology_orders ro
                LEFT JOIN patients p ON ro.patient_id = p.id
                LEFT JOIN users u ON ro.doctor_id = u.id
                LEFT JOIN patient_visits v ON ro.visit_id = v.id
                WHERE ro.id = :id AND ro.deleted_at IS NULL";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':id' => $order_id]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$order) {
            echo json_encode(['success' => false, 'message' => 'Order not found']);
            exit;
        }
        
        echo json_encode(['success' => true, 'order' => $order]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// Validate user session
$user_id = (int)($_SESSION['user_id'] ?? 0);
$branch_id = (int)($_SESSION['branch_id'] ?? 0);
$user_type = $_SESSION['user_type'] ?? '';
$is_global_admin = ($user_type === 'super_admin');
$is_radiologist = in_array($user_type, ['radiologist', 'super_admin', 'admin']);
$is_doctor = ($user_type === 'doctor');

if ($user_id <= 0) {
    header('Location: login.php');
    exit;
}

// ============================================
// 2. ACTION HANDLING
// ============================================
$action = $_GET['action'] ?? $_POST['action'] ?? '';
$order_id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$success_message = '';
$error_message = '';

// Handle create new radiology order
if ($action === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $patient_id = (int)($_POST['patient_id'] ?? 0);
    $doctor_id = (int)($_POST['doctor_id'] ?? 0);
    $visit_id = (int)($_POST['visit_id'] ?? 0);
    $consultation_id = (int)($_POST['consultation_id'] ?? 0);
    $service_code = trim($_POST['service_code'] ?? '');
    $modality = $_POST['modality'] ?? 'xray';
    $body_part = trim($_POST['body_part'] ?? '');
    $priority = $_POST['priority'] ?? 'routine';
    $clinical_history = trim($_POST['clinical_history'] ?? '');
    $notes = trim($_POST['notes'] ?? '');

    if ($patient_id <= 0 || $doctor_id <= 0 || empty($service_code)) {
        $error_message = 'Please select a patient, doctor, and at least one service.';
    } else {
        try {
            $pdo->beginTransaction();

            // Get service details
            $sql = "SELECT service_name, category, standard_price, insurance_price 
                    FROM radiology_services 
                    WHERE service_code = :service_code AND is_active = 1 AND deleted_at IS NULL";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':service_code' => $service_code]);
            $service = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$service) {
                throw new Exception('Invalid service selected.');
            }

            // Generate order number
            $order_number = 'RAD-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);

            // Insert radiology order
            $sql = "INSERT INTO radiology_orders (
                        branch_id, patient_id, doctor_id, visit_id, consultation_id,
                        order_number, service_name, service_code, modality, body_part,
                        priority, payment_status, status, clinical_history,
                        created_by_user_id, created_at
                    ) VALUES (
                        :branch_id, :patient_id, :doctor_id, :visit_id, :consultation_id,
                        :order_number, :service_name, :service_code, :modality, :body_part,
                        :priority, 'unpaid', 'pending_payment', :clinical_history,
                        :created_by, NOW()
                    )";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':branch_id' => $branch_id,
                ':patient_id' => $patient_id,
                ':doctor_id' => $doctor_id,
                ':visit_id' => $visit_id,
                ':consultation_id' => $consultation_id,
                ':order_number' => $order_number,
                ':service_name' => $service['service_name'],
                ':service_code' => $service_code,
                ':modality' => $modality,
                ':body_part' => $body_part,
                ':priority' => $priority,
                ':clinical_history' => $clinical_history,
                ':created_by' => $user_id
            ]);
            $order_id = $pdo->lastInsertId();

            // Update visit status
            if ($visit_id > 0) {
                $sql = "UPDATE patient_visits SET status = 'awaiting_lab', updated_at = NOW() WHERE id = :id";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([':id' => $visit_id]);
            }

            $pdo->commit();
            $success_message = "Radiology order #{$order_number} created successfully!";
            
            // Log audit
            log_audit($pdo, $branch_id, $user_id, 'INSERT', 'radiology_orders', $order_id, ['order_number' => $order_number]);

        } catch (Exception $e) {
            $pdo->rollBack();
            $error_message = 'Error: ' . $e->getMessage();
            error_log('Radiology Order Creation Error: ' . $e->getMessage());
        }
    }
}

// Handle status update
if ($action === 'update_status' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $order_id = (int)($_POST['order_id'] ?? 0);
    $new_status = $_POST['new_status'] ?? '';
    $scheduled_date = $_POST['scheduled_date'] ?? '';
    $scheduled_time = $_POST['scheduled_time'] ?? '';
    $findings = trim($_POST['findings'] ?? '');
    $impression = trim($_POST['impression'] ?? '');
    $interpretation_report = trim($_POST['interpretation_report'] ?? '');
    $physician_review_notes = trim($_POST['physician_review_notes'] ?? '');
    $reason = trim($_POST['reason'] ?? '');

    $allowed_statuses = ['pending_payment', 'scheduled', 'in_progress', 'completed', 'reviewed', 'cancelled'];
    
    if ($order_id <= 0 || !in_array($new_status, $allowed_statuses)) {
        $error_message = 'Invalid status or order ID.';
    } else {
        try {
            $pdo->beginTransaction();

            // Get current status and order number
            $sql = "SELECT status, order_number FROM radiology_orders WHERE id = :id AND deleted_at IS NULL";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':id' => $order_id]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$order) {
                throw new Exception('Order not found.');
            }

            $old_status = $order['status'];

            // Build update data
            $update_fields = "status = :new_status, updated_at = NOW()";
            $params = [
                ':id' => $order_id,
                ':new_status' => $new_status
            ];

            // Special handling for specific statuses
            if ($new_status === 'scheduled') {
                if (!empty($scheduled_date)) {
                    $update_fields .= ", scheduled_date = :scheduled_date";
                    $params[':scheduled_date'] = $scheduled_date;
                }
                if (!empty($scheduled_time)) {
                    $update_fields .= ", scheduled_time = :scheduled_time";
                    $params[':scheduled_time'] = $scheduled_time;
                }
            }

            if ($new_status === 'in_progress') {
                // No additional fields needed, just status change
            }

            if ($new_status === 'completed') {
                $update_fields .= ", performed_at = NOW()";
                if (!empty($findings)) {
                    $update_fields .= ", findings = :findings";
                    $params[':findings'] = $findings;
                }
                if (!empty($impression)) {
                    $update_fields .= ", impression = :impression";
                    $params[':impression'] = $impression;
                }
            }

            if ($new_status === 'reviewed') {
                $update_fields .= ", reviewed_at = NOW()";
                if (!empty($interpretation_report)) {
                    $update_fields .= ", interpretation_report = :interpretation_report";
                    $params[':interpretation_report'] = $interpretation_report;
                }
                if (!empty($physician_review_notes)) {
                    $update_fields .= ", physician_review_notes = :physician_review_notes";
                    $params[':physician_review_notes'] = $physician_review_notes;
                }
            }

            if ($new_status === 'cancelled') {
                $update_fields .= ", cancellation_reason = :reason";
                $params[':reason'] = $reason;
            }

            // Update order
            $sql = "UPDATE radiology_orders SET {$update_fields} WHERE id = :id";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            // If completed, update staff productivity
            if ($new_status === 'completed') {
                $sql = "INSERT INTO staff_productivity_logs 
                            (branch_id, user_id, log_date, action_metric_type, total_count_achieved, created_at)
                        VALUES 
                            (:branch_id, :user_id, CURDATE(), 'patients_treated', 1, NOW())
                        ON DUPLICATE KEY UPDATE
                            total_count_achieved = total_count_achieved + 1";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    ':branch_id' => $branch_id,
                    ':user_id' => $user_id
                ]);
            }

            $pdo->commit();
            $success_message = "Order #{$order['order_number']} status updated to " . ucfirst(str_replace('_', ' ', $new_status));

            // Log audit
            log_audit($pdo, $branch_id, $user_id, 'UPDATE', 'radiology_orders', $order_id, [
                'old_status' => $old_status,
                'new_status' => $new_status
            ]);

        } catch (Exception $e) {
            $pdo->rollBack();
            $error_message = 'Error: ' . $e->getMessage();
            error_log('Radiology Status Update Error: ' . $e->getMessage());
        }
    }
}

// Handle payment verification
if ($action === 'verify_payment' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $order_id = (int)($_POST['order_id'] ?? 0);
    $payment_status = $_POST['payment_status'] ?? 'paid';

    if ($order_id <= 0) {
        $error_message = 'Invalid order ID.';
    } else {
        try {
            $sql = "UPDATE radiology_orders 
                    SET payment_status = :payment_status, 
                        payment_verified_at = NOW(), 
                        payment_verified_by_user_id = :verified_by,
                        updated_at = NOW()
                    WHERE id = :id AND deleted_at IS NULL";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':payment_status' => $payment_status,
                ':verified_by' => $user_id,
                ':id' => $order_id
            ]);

            // If payment is verified and status is pending_payment, update to scheduled
            if ($payment_status === 'paid') {
                $sql = "UPDATE radiology_orders SET status = 'scheduled' 
                        WHERE id = :id AND status = 'pending_payment'";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([':id' => $order_id]);
            }

            $success_message = 'Payment verified successfully.';
            
            // Log audit
            log_audit($pdo, $branch_id, $user_id, 'UPDATE', 'radiology_orders', $order_id, [
                'payment_status' => $payment_status
            ]);

        } catch (PDOException $e) {
            $error_message = 'Database error: ' . $e->getMessage();
            error_log('Payment Verification Error: ' . $e->getMessage());
        }
    }
}

// Handle add interpretation/report
if ($action === 'add_interpretation' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $order_id = (int)($_POST['order_id'] ?? 0);
    $interpretation_report = trim($_POST['interpretation_report'] ?? '');
    $findings = trim($_POST['findings'] ?? '');
    $impression = trim($_POST['impression'] ?? '');

    if ($order_id <= 0 || empty($interpretation_report)) {
        $error_message = 'Please provide an interpretation report.';
    } else {
        try {
            $sql = "UPDATE radiology_orders 
                    SET interpretation_report = :report,
                        findings = :findings,
                        impression = :impression,
                        status = 'reviewed',
                        reviewed_at = NOW(),
                        updated_at = NOW()
                    WHERE id = :id AND deleted_at IS NULL";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':report' => $interpretation_report,
                ':findings' => $findings,
                ':impression' => $impression,
                ':id' => $order_id
            ]);

            $success_message = 'Interpretation added successfully.';
            
            // Log audit
            log_audit($pdo, $branch_id, $user_id, 'UPDATE', 'radiology_orders', $order_id, [
                'action' => 'add_interpretation'
            ]);

        } catch (PDOException $e) {
            $error_message = 'Database error: ' . $e->getMessage();
            error_log('Interpretation Error: ' . $e->getMessage());
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
$filter_status = $_GET['status'] ?? '';
$filter_modality = $_GET['modality'] ?? '';
$filter_priority = $_GET['priority'] ?? '';
$filter_payment = $_GET['payment'] ?? '';
$filter_patient = (int)($_GET['patient_id'] ?? 0);
$search = trim($_GET['search'] ?? '');

// Branch filter
$branch_filter = "";
$params = [];
if (!$is_global_admin && $branch_id > 0) {
    $branch_filter = " AND ro.branch_id = :branch_id";
    $params[':branch_id'] = $branch_id;
}

// Additional filters
if (!empty($filter_status)) {
    $branch_filter .= " AND ro.status = :status";
    $params[':status'] = $filter_status;
}
if (!empty($filter_modality)) {
    $branch_filter .= " AND ro.modality = :modality";
    $params[':modality'] = $filter_modality;
}
if (!empty($filter_priority)) {
    $branch_filter .= " AND ro.priority = :priority";
    $params[':priority'] = $filter_priority;
}
if (!empty($filter_payment)) {
    $branch_filter .= " AND ro.payment_status = :payment";
    $params[':payment'] = $filter_payment;
}
if ($filter_patient > 0) {
    $branch_filter .= " AND ro.patient_id = :patient_id";
    $params[':patient_id'] = $filter_patient;
}
if (!empty($search)) {
    $branch_filter .= " AND (ro.order_number LIKE :search OR p.first_name LIKE :search OR p.last_name LIKE :search OR ro.service_name LIKE :search)";
    $params[':search'] = "%{$search}%";
}

// Fetch radiology orders with details
$sql = "SELECT 
            ro.id, ro.order_number, ro.service_name, ro.service_code,
            ro.modality, ro.body_part, ro.priority, ro.payment_status,
            ro.status, ro.clinical_history, ro.findings, ro.impression,
            ro.interpretation_report, ro.image_attachment_path,
            ro.scheduled_date, ro.scheduled_time,
            ro.created_at, ro.updated_at, ro.performed_at, ro.reviewed_at,
            ro.cancellation_reason, ro.physician_review_notes,
            ro.patient_id, ro.doctor_id, ro.visit_id,
            CONCAT(p.first_name, ' ', COALESCE(p.middle_name, ''), ' ', p.last_name) as patient_name,
            p.mr_number,
            CONCAT(u.first_name, ' ', u.last_name) as doctor_name,
            u.user_type as doctor_type,
            v.visit_number
        FROM radiology_orders ro
        LEFT JOIN patients p ON ro.patient_id = p.id
        LEFT JOIN users u ON ro.doctor_id = u.id
        LEFT JOIN patient_visits v ON ro.visit_id = v.id
        WHERE ro.deleted_at IS NULL" . $branch_filter . "
        ORDER BY 
            CASE ro.priority 
                WHEN 'stat' THEN 1 
                WHEN 'urgent' THEN 2 
                ELSE 3 
            END,
            CASE ro.status
                WHEN 'pending_payment' THEN 1
                WHEN 'scheduled' THEN 2
                WHEN 'in_progress' THEN 3
                WHEN 'completed' THEN 4
                WHEN 'reviewed' THEN 5
                ELSE 6
            END,
            ro.created_at DESC
        LIMIT 100";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$radiology_orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch radiology services for dropdown
$sql = "SELECT id, service_code, service_name, category, standard_price, insurance_price, is_active 
        FROM radiology_services 
        WHERE is_active = 1 AND deleted_at IS NULL 
        ORDER BY category, service_name";
$services = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

// Group services by category
$services_by_category = [];
foreach ($services as $service) {
    $cat = $service['category'] ?? 'other';
    if (!isset($services_by_category[$cat])) {
        $services_by_category[$cat] = [];
    }
    $services_by_category[$cat][] = $service;
}

// Fetch patients for dropdown
$sql = "SELECT id, mr_number, first_name, middle_name, last_name 
        FROM patients 
        WHERE is_active = 1 AND deleted_at IS NULL 
        ORDER BY first_name, last_name 
        LIMIT 500";
$patients = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

// Fetch doctors for dropdown
$sql = "SELECT u.id, u.first_name, u.last_name, sp.specialization 
        FROM users u
        LEFT JOIN staff_profiles sp ON u.id = sp.user_id
        WHERE u.user_type = 'doctor' 
        AND u.is_active = 1 
        AND u.deleted_at IS NULL 
        ORDER BY u.first_name, u.last_name";
$doctors = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

// Get count statistics
$sql = "SELECT status, COUNT(*) as count 
        FROM radiology_orders 
        WHERE deleted_at IS NULL" . str_replace('ro.', '', $branch_filter) . "
        GROUP BY status";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$status_counts = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $status_counts[$row['status']] = (int)$row['count'];
}

// Get modality statistics
$sql = "SELECT modality, COUNT(*) as count 
        FROM radiology_orders 
        WHERE deleted_at IS NULL" . str_replace('ro.', '', $branch_filter) . "
        AND status NOT IN ('cancelled')
        GROUP BY modality";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$modality_counts = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $modality_counts[$row['modality']] = (int)$row['count'];
}

// Modality icons and colors
$modality_config = [
    'xray' => ['icon' => 'fa-x-ray', 'color' => '#2563eb', 'label' => 'X-Ray'],
    'ct' => ['icon' => 'fa-microscope', 'color' => '#7c3aed', 'label' => 'CT'],
    'mri' => ['icon' => 'fa-magnet', 'color' => '#db2777', 'label' => 'MRI'],
    'ultrasound' => ['icon' => 'fa-wave-square', 'color' => '#059669', 'label' => 'Ultrasound'],
    'mammography' => ['icon' => 'fa-x-ray', 'color' => '#d97706', 'label' => 'Mammography'],
    'fluoroscopy' => ['icon' => 'fa-video', 'color' => '#0284c7', 'label' => 'Fluoroscopy'],
    'angiography' => ['icon' => 'fa-heart', 'color' => '#dc2626', 'label' => 'Angiography']
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Radiology Orders Queue | HMS ERP</title>
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
        .btn-xs { padding: 2px 8px; font-size: 11px; }
        
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
        
        /* Filters */
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
        .stat-pill.pending_payment { border-left: 3px solid #f9c74f; }
        .stat-pill.scheduled { border-left: 3px solid #4cc9f0; }
        .stat-pill.in_progress { border-left: 3px solid #7209b7; }
        .stat-pill.completed { border-left: 3px solid #06d6a0; }
        .stat-pill.reviewed { border-left: 3px solid #1f2937; }
        .stat-pill.cancelled { border-left: 3px solid #ef476f; }
        
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
        
        /* Status Badges */
        .badge {
            padding: 3px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            display: inline-block;
        }
        .badge-pending_payment { background: #fef3c7; color: #92400e; }
        .badge-scheduled { background: #dbeafe; color: #1e40af; }
        .badge-in_progress { background: #ede9fe; color: #5b21b6; }
        .badge-completed { background: #d1fae5; color: #065f46; }
        .badge-reviewed { background: #d1fae5; color: #065f46; }
        .badge-cancelled { background: #fee2e2; color: #991b1b; }
        
        .badge-stat { background: #ef476f; color: white; font-size: 9px; padding: 1px 6px; border-radius: 10px; }
        .badge-urgent { background: #f9c74f; color: #92400e; }
        .badge-routine { background: #dbeafe; color: #1e40af; }
        
        .badge-payment-unpaid { background: #fee2e2; color: #991b1b; }
        .badge-payment-paid { background: #d1fae5; color: #065f46; }
        .badge-payment-partial { background: #fef3c7; color: #92400e; }
        .badge-payment-waived { background: #dbeafe; color: #1e40af; }
        .badge-payment-refunded { background: #f3e8ff; color: #6b21a5; }
        
        /* Modality badges */
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
            border-color: #7209b7;
            box-shadow: 0 0 0 3px rgba(114, 9, 183, 0.1);
        }
        select.form-control { appearance: auto; }
        textarea.form-control { resize: vertical; min-height: 60px; }
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }
        .form-row-3 {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 16px;
        }
        
        .order-actions {
            display: flex;
            gap: 4px;
            flex-wrap: wrap;
        }
        .order-actions .btn { padding: 2px 8px; font-size: 11px; }
        
        /* Report preview */
        .report-preview {
            background: #f9fafb;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 16px;
            margin-top: 12px;
            white-space: pre-wrap;
            font-family: 'Courier New', monospace;
            font-size: 13px;
            max-height: 200px;
            overflow-y: auto;
        }
        
        .text-muted { color: #6b7280; }
        .text-truncate { max-width: 150px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; display: inline-block; }
        .text-center { text-align: center; }
        .mt-2 { margin-top: 8px; }
        .mb-2 { margin-bottom: 8px; }
        .gap-2 { gap: 8px; }
        .flex { display: flex; }
        .flex-wrap { flex-wrap: wrap; }
        .items-center { align-items: center; }
        
        /* Responsive */
        @media (max-width: 768px) {
            .filter-bar { flex-direction: column; align-items: stretch; }
            .filter-bar select, .filter-bar input { width: 100%; }
            .form-row, .form-row-3 { grid-template-columns: 1fr; }
            .stats-bar { gap: 6px; }
            .stat-pill { font-size: 11px; padding: 4px 10px; }
            .page-header h1 { font-size: 20px; }
            .table-container { padding: 8px; overflow-x: auto; }
            .table-container table { font-size: 12px; }
            .table-container th, .table-container td { padding: 6px 8px; }
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
            <h1><i class="fas fa-x-ray"></i> Radiology Orders Queue</h1>
            <div>
                <button class="btn btn-primary" onclick="openModal('createOrderModal')">
                    <i class="fas fa-plus"></i> New Order
                </button>
                <button class="btn" onclick="window.location.reload();">
                    <i class="fas fa-sync-alt"></i>
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
            <div class="stat-pill pending_payment">
                <span class="count"><?php echo $status_counts['pending_payment'] ?? 0; ?></span>
                <span class="label">Pending Payment</span>
            </div>
            <div class="stat-pill scheduled">
                <span class="count"><?php echo $status_counts['scheduled'] ?? 0; ?></span>
                <span class="label">Scheduled</span>
            </div>
            <div class="stat-pill in_progress">
                <span class="count"><?php echo $status_counts['in_progress'] ?? 0; ?></span>
                <span class="label">In Progress</span>
            </div>
            <div class="stat-pill completed">
                <span class="count"><?php echo $status_counts['completed'] ?? 0; ?></span>
                <span class="label">Completed</span>
            </div>
            <div class="stat-pill reviewed">
                <span class="count"><?php echo $status_counts['reviewed'] ?? 0; ?></span>
                <span class="label">Reviewed</span>
            </div>
            <div class="stat-pill cancelled">
                <span class="count"><?php echo $status_counts['cancelled'] ?? 0; ?></span>
                <span class="label">Cancelled</span>
            </div>
        </div>

        <!-- Filter Bar -->
        <div class="filter-bar">
            <div>
                <span class="filter-label">Status</span>
                <select id="filterStatus" onchange="applyFilters()">
                    <option value="">All Status</option>
                    <option value="pending_payment" <?php echo $filter_status === 'pending_payment' ? 'selected' : ''; ?>>Pending Payment</option>
                    <option value="scheduled" <?php echo $filter_status === 'scheduled' ? 'selected' : ''; ?>>Scheduled</option>
                    <option value="in_progress" <?php echo $filter_status === 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                    <option value="completed" <?php echo $filter_status === 'completed' ? 'selected' : ''; ?>>Completed</option>
                    <option value="reviewed" <?php echo $filter_status === 'reviewed' ? 'selected' : ''; ?>>Reviewed</option>
                    <option value="cancelled" <?php echo $filter_status === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                </select>
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
                    <option value="emergency" <?php echo $filter_priority === 'emergency' ? 'selected' : ''; ?>>Emergency</option>
                    <option value="elective" <?php echo $filter_priority === 'elective' ? 'selected' : ''; ?>>Elective</option>
                </select>
            </div>
            <div>
                <span class="filter-label">Payment</span>
                <select id="filterPayment" onchange="applyFilters()">
                    <option value="">All</option>
                    <option value="unpaid" <?php echo $filter_payment === 'unpaid' ? 'selected' : ''; ?>>Unpaid</option>
                    <option value="paid" <?php echo $filter_payment === 'paid' ? 'selected' : ''; ?>>Paid</option>
                    <option value="partial" <?php echo $filter_payment === 'partial' ? 'selected' : ''; ?>>Partial</option>
                </select>
            </div>
            <div style="flex:1;min-width:200px;">
                <input type="text" class="form-control" id="filterSearch" placeholder="Search orders, patients, services..." 
                       value="<?php echo htmlspecialchars($search); ?>" onkeypress="if(event.key==='Enter') applyFilters()">
            </div>
            <button class="btn btn-primary" onclick="applyFilters()"><i class="fas fa-search"></i> Filter</button>
            <button class="btn" onclick="resetFilters()"><i class="fas fa-times"></i> Reset</button>
        </div>

        <!-- Orders Table -->
        <div class="table-container">
            <table id="ordersTable">
                <thead>
                    <tr>
                        <th>Order #</th>
                        <th>Patient</th>
                        <th>Service</th>
                        <th>Modality</th>
                        <th>Priority</th>
                        <th>Payment</th>
                        <th>Status</th>
                        <th>Created</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($radiology_orders)): ?>
                        <tr>
                            <td colspan="9" class="no-data">
                                <i class="fas fa-inbox"></i>
                                No radiology orders found
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($radiology_orders as $order): ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($order['order_number']); ?></strong>
                                    <?php if ($order['visit_number']): ?>
                                        <br><small class="text-muted">Visit: <?php echo htmlspecialchars($order['visit_number']); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <strong><?php echo htmlspecialchars($order['patient_name'] ?? 'Unknown'); ?></strong>
                                    <br><small class="text-muted">MRN: <?php echo htmlspecialchars($order['mr_number'] ?? 'N/A'); ?></small>
                                </td>
                                <td>
                                    <div><?php echo htmlspecialchars($order['service_name'] ?? 'N/A'); ?></div>
                                    <?php if ($order['body_part']): ?>
                                        <small class="text-muted"><?php echo htmlspecialchars($order['body_part']); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="modality-badge modality-<?php echo $order['modality'] ?? 'xray'; ?>">
                                        <i class="fas <?php echo $modality_config[$order['modality']]['icon'] ?? 'fa-x-ray'; ?>"></i>
                                        <?php echo ucfirst($order['modality'] ?? 'X-Ray'); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($order['priority'] === 'stat'): ?>
                                        <span class="badge badge-stat">STAT</span>
                                    <?php elseif ($order['priority'] === 'urgent'): ?>
                                        <span class="badge badge-urgent">Urgent</span>
                                    <?php elseif ($order['priority'] === 'emergency'): ?>
                                        <span class="badge badge-stat">Emergency</span>
                                    <?php else: ?>
                                        <span class="badge badge-routine"><?php echo ucfirst($order['priority'] ?? 'Routine'); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge badge-payment-<?php echo $order['payment_status'] ?? 'unpaid'; ?>">
                                        <?php echo ucfirst($order['payment_status'] ?? 'Unpaid'); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge badge-<?php echo str_replace('_', '', $order['status'] ?? 'pending_payment'); ?>">
                                        <?php echo ucfirst(str_replace('_', ' ', $order['status'] ?? 'Pending Payment')); ?>
                                    </span>
                                    <?php if (($order['status'] ?? '') === 'scheduled' && !empty($order['scheduled_date'])): ?>
                                        <br><small class="text-muted"><?php echo date('M d, Y', strtotime($order['scheduled_date'])); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php echo date('M d, H:i', strtotime($order['created_at'] ?? 'now')); ?>
                                </td>
                                <td>
                                    <div class="order-actions">
                                        <button class="btn btn-sm btn-primary" onclick="viewOrder(<?php echo $order['id']; ?>)">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <?php if (in_array($order['status'] ?? '', ['pending_payment', 'scheduled', 'in_progress', 'completed'])): ?>
                                            <button class="btn btn-sm btn-success" onclick="updateStatus(<?php echo $order['id']; ?>, '<?php echo $order['status']; ?>')">
                                                <i class="fas fa-arrow-right"></i>
                                            </button>
                                        <?php endif; ?>
                                        <?php if (($order['status'] ?? '') === 'pending_payment'): ?>
                                            <button class="btn btn-sm btn-warning" onclick="verifyPayment(<?php echo $order['id']; ?>)">
                                                <i class="fas fa-check-circle"></i>
                                            </button>
                                        <?php endif; ?>
                                        <?php if (($order['status'] ?? '') === 'completed'): ?>
                                            <button class="btn btn-sm btn-info" onclick="addInterpretation(<?php echo $order['id']; ?>)">
                                                <i class="fas fa-file-medical-alt"></i>
                                            </button>
                                        <?php endif; ?>
                                        <?php if (in_array($order['status'] ?? '', ['pending_payment', 'scheduled', 'in_progress'])): ?>
                                            <button class="btn btn-sm btn-danger" onclick="cancelOrder(<?php echo $order['id']; ?>)">
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

<!-- Create Order Modal -->
<div class="modal-overlay" id="createOrderModal">
    <div class="modal">
        <div class="modal-header">
            <h2><i class="fas fa-plus-circle"></i> New Radiology Order</h2>
            <button class="modal-close" onclick="closeModal('createOrderModal')">&times;</button>
        </div>
        <form method="POST" action="">
            <input type="hidden" name="action" value="create">
            <div class="modal-body">
                <div class="form-row">
                    <div class="form-group">
                        <label>Patient <span class="required">*</span></label>
                        <select name="patient_id" class="form-control" required>
                            <option value="">Select Patient</option>
                            <?php foreach ($patients as $patient): ?>
                                <option value="<?php echo $patient['id']; ?>">
                                    <?php echo htmlspecialchars($patient['first_name'] . ' ' . $patient['last_name'] . ' (' . $patient['mr_number'] . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Doctor <span class="required">*</span></label>
                        <select name="doctor_id" class="form-control" required>
                            <option value="">Select Doctor</option>
                            <?php foreach ($doctors as $doctor): ?>
                                <option value="<?php echo $doctor['id']; ?>">
                                    <?php echo htmlspecialchars($doctor['first_name'] . ' ' . $doctor['last_name']); ?>
                                    <?php if ($doctor['specialization']): ?>
                                        (<?php echo htmlspecialchars($doctor['specialization']); ?>)
                                    <?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Visit ID (Optional)</label>
                        <input type="number" name="visit_id" class="form-control" placeholder="Enter visit ID if known">
                    </div>
                    <div class="form-group">
                        <label>Consultation ID (Optional)</label>
                        <input type="number" name="consultation_id" class="form-control" placeholder="Enter consultation ID">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Modality <span class="required">*</span></label>
                        <select name="modality" class="form-control" required>
                            <option value="xray">X-Ray</option>
                            <option value="ct">CT Scan</option>
                            <option value="mri">MRI</option>
                            <option value="ultrasound">Ultrasound</option>
                            <option value="mammography">Mammography</option>
                            <option value="fluoroscopy">Fluoroscopy</option>
                            <option value="angiography">Angiography</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Priority</label>
                        <select name="priority" class="form-control">
                            <option value="routine">Routine</option>
                            <option value="urgent">Urgent</option>
                            <option value="stat">STAT</option>
                            <option value="emergency">Emergency</option>
                            <option value="elective">Elective</option>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label>Service <span class="required">*</span></label>
                    <select name="service_code" class="form-control" required>
                        <option value="">Select Service</option>
                        <?php foreach ($services_by_category as $category => $cat_services): ?>
                            <optgroup label="<?php echo ucfirst($category); ?>">
                                <?php foreach ($cat_services as $service): ?>
                                    <option value="<?php echo htmlspecialchars($service['service_code']); ?>">
                                        <?php echo htmlspecialchars($service['service_name']); ?>
                                        (<?php echo number_format($service['standard_price'], 2); ?> ETB)
                                    </option>
                                <?php endforeach; ?>
                            </optgroup>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Body Part</label>
                    <input type="text" name="body_part" class="form-control" placeholder="e.g., Chest, Abdomen, Knee">
                </div>
                <div class="form-group">
                    <label>Clinical History</label>
                    <textarea name="clinical_history" class="form-control" placeholder="Reason for examination, relevant history..."></textarea>
                </div>
                <div class="form-group">
                    <label>Notes</label>
                    <textarea name="notes" class="form-control" placeholder="Additional notes..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" onclick="closeModal('createOrderModal')">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Create Order</button>
            </div>
        </form>
    </div>
</div>

<!-- Update Status Modal -->
<div class="modal-overlay" id="updateStatusModal">
    <div class="modal" style="max-width:600px;">
        <div class="modal-header">
            <h2><i class="fas fa-exchange-alt"></i> Update Status</h2>
            <button class="modal-close" onclick="closeModal('updateStatusModal')">&times;</button>
        </div>
        <form method="POST" action="">
            <input type="hidden" name="action" value="update_status">
            <input type="hidden" name="order_id" id="updateOrderId">
            <div class="modal-body">
                <div class="form-group">
                    <label>Order #</label>
                    <div id="updateOrderNumber" style="font-weight:600;font-size:16px;"></div>
                </div>
                <div class="form-group">
                    <label>New Status <span class="required">*</span></label>
                    <select name="new_status" id="updateNewStatus" class="form-control" required>
                        <option value="">Select Status</option>
                        <option value="scheduled">Scheduled</option>
                        <option value="in_progress">In Progress</option>
                        <option value="completed">Completed</option>
                        <option value="reviewed">Reviewed</option>
                    </select>
                </div>
                <div class="form-row" id="scheduleGroup" style="display:none;">
                    <div class="form-group">
                        <label>Scheduled Date</label>
                        <input type="date" name="scheduled_date" class="form-control">
                    </div>
                    <div class="form-group">
                        <label>Scheduled Time</label>
                        <input type="time" name="scheduled_time" class="form-control">
                    </div>
                </div>
                <div class="form-group" id="findingsGroup" style="display:none;">
                    <label>Findings</label>
                    <textarea name="findings" class="form-control" placeholder="Radiological findings..."></textarea>
                </div>
                <div class="form-group" id="impressionGroup" style="display:none;">
                    <label>Impression</label>
                    <textarea name="impression" class="form-control" placeholder="Clinical impression..."></textarea>
                </div>
                <div class="form-group" id="reportGroup" style="display:none;">
                    <label>Interpretation Report <span class="required">*</span></label>
                    <textarea name="interpretation_report" class="form-control" placeholder="Detailed interpretation report..." rows="5"></textarea>
                </div>
                <div class="form-group" id="reviewNotesGroup" style="display:none;">
                    <label>Physician Review Notes</label>
                    <textarea name="physician_review_notes" class="form-control" placeholder="Reviewer notes..."></textarea>
                </div>
                <div class="form-group" id="reasonGroup" style="display:none;">
                    <label>Reason <span class="required">*</span></label>
                    <textarea name="reason" class="form-control" placeholder="Reason for cancellation..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" onclick="closeModal('updateStatusModal')">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Update Status</button>
            </div>
        </form>
    </div>
</div>

<!-- Verify Payment Modal -->
<div class="modal-overlay" id="verifyPaymentModal">
    <div class="modal" style="max-width:500px;">
        <div class="modal-header">
            <h2><i class="fas fa-check-circle"></i> Verify Payment</h2>
            <button class="modal-close" onclick="closeModal('verifyPaymentModal')">&times;</button>
        </div>
        <form method="POST" action="">
            <input type="hidden" name="action" value="verify_payment">
            <input type="hidden" name="order_id" id="paymentOrderId">
            <div class="modal-body">
                <div class="form-group">
                    <label>Order #</label>
                    <div id="paymentOrderNumber" style="font-weight:600;font-size:16px;"></div>
                </div>
                <div class="form-group">
                    <label>Payment Status <span class="required">*</span></label>
                    <select name="payment_status" class="form-control" required>
                        <option value="paid">Paid</option>
                        <option value="waived">Waived</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" onclick="closeModal('verifyPaymentModal')">Cancel</button>
                <button type="submit" class="btn btn-success"><i class="fas fa-check"></i> Verify Payment</button>
            </div>
        </form>
    </div>
</div>

<!-- Add Interpretation Modal -->
<div class="modal-overlay" id="interpretationModal">
    <div class="modal" style="max-width:700px;">
        <div class="modal-header">
            <h2><i class="fas fa-file-medical-alt"></i> Add Interpretation Report</h2>
            <button class="modal-close" onclick="closeModal('interpretationModal')">&times;</button>
        </div>
        <form method="POST" action="">
            <input type="hidden" name="action" value="add_interpretation">
            <input type="hidden" name="order_id" id="interpretationOrderId">
            <div class="modal-body">
                <div class="form-group">
                    <label>Order #</label>
                    <div id="interpretationOrderNumber" style="font-weight:600;font-size:16px;"></div>
                </div>
                <div class="form-group">
                    <label>Findings</label>
                    <textarea name="findings" class="form-control" placeholder="Radiological findings..." rows="3"></textarea>
                </div>
                <div class="form-group">
                    <label>Impression</label>
                    <textarea name="impression" class="form-control" placeholder="Clinical impression..." rows="3"></textarea>
                </div>
                <div class="form-group">
                    <label>Interpretation Report <span class="required">*</span></label>
                    <textarea name="interpretation_report" class="form-control" placeholder="Detailed interpretation report..." rows="6" required></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" onclick="closeModal('interpretationModal')">Cancel</button>
                <button type="submit" class="btn btn-info"><i class="fas fa-save"></i> Save Report</button>
            </div>
        </form>
    </div>
</div>

<!-- View Order Modal -->
<div class="modal-overlay" id="viewOrderModal">
    <div class="modal">
        <div class="modal-header">
            <h2><i class="fas fa-file-medical-alt"></i> Order Details</h2>
            <button class="modal-close" onclick="closeModal('viewOrderModal')">&times;</button>
        </div>
        <div class="modal-body" id="orderDetailsContent">
            <p class="text-muted text-center"><i class="fas fa-spinner fa-spin"></i> Loading order details...</p>
        </div>
        <div class="modal-footer">
            <button class="btn" onclick="closeModal('viewOrderModal')">Close</button>
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

// Close modal on overlay click
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
    const status = document.getElementById('filterStatus').value;
    const modality = document.getElementById('filterModality').value;
    const priority = document.getElementById('filterPriority').value;
    const payment = document.getElementById('filterPayment').value;
    const search = document.getElementById('filterSearch').value;
    
    let url = window.location.pathname + '?';
    if (status) url += 'status=' + encodeURIComponent(status) + '&';
    if (modality) url += 'modality=' + encodeURIComponent(modality) + '&';
    if (priority) url += 'priority=' + encodeURIComponent(priority) + '&';
    if (payment) url += 'payment=' + encodeURIComponent(payment) + '&';
    if (search) url += 'search=' + encodeURIComponent(search) + '&';
    window.location.href = url;
}

function resetFilters() {
    window.location.href = window.location.pathname;
}

// ============================================
// ORDER ACTION FUNCTIONS
// ============================================
function viewOrder(orderId) {
    openModal('viewOrderModal');
    document.getElementById('orderDetailsContent').innerHTML = '<p class="text-muted text-center"><i class="fas fa-spinner fa-spin"></i> Loading order details...</p>';
    
    fetch('?ajax=get_order&id=' + orderId)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const order = data.order;
                let html = `
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:16px;">
                        <div><strong>Order #:</strong> ${order.order_number}</div>
                        <div><strong>Status:</strong> <span class="badge badge-${order.status.replace('_', '')}">${order.status.replace('_', ' ').toUpperCase()}</span></div>
                        <div><strong>Patient:</strong> ${order.patient_name || 'Unknown'}</div>
                        <div><strong>MRN:</strong> ${order.mr_number || 'N/A'}</div>
                        <div><strong>Doctor:</strong> ${order.doctor_name || 'N/A'}</div>
                        <div><strong>Priority:</strong> ${order.priority ? order.priority.toUpperCase() : 'N/A'}</div>
                        <div><strong>Service:</strong> ${order.service_name || 'N/A'}</div>
                        <div><strong>Modality:</strong> ${order.modality ? order.modality.toUpperCase() : 'N/A'}</div>
                        <div><strong>Body Part:</strong> ${order.body_part || 'N/A'}</div>
                        <div><strong>Payment:</strong> ${order.payment_status ? order.payment_status.toUpperCase() : 'N/A'}</div>
                        <div><strong>Created:</strong> ${order.created_at ? new Date(order.created_at).toLocaleString() : 'N/A'}</div>
                        <div><strong>Scheduled:</strong> ${order.scheduled_date ? new Date(order.scheduled_date).toLocaleDateString() : 'Not scheduled'}</div>
                    </div>
                `;
                
                if (order.scheduled_time) {
                    html += `<div><strong>Scheduled Time:</strong> ${order.scheduled_time}</div>`;
                }
                
                if (order.clinical_history) {
                    html += `<hr><div><strong>Clinical History:</strong><br>${order.clinical_history}</div>`;
                }
                
                if (order.findings) {
                    html += `<hr><div><strong>Findings:</strong><br>${order.findings}</div>`;
                }
                
                if (order.impression) {
                    html += `<hr><div><strong>Impression:</strong><br>${order.impression}</div>`;
                }
                
                if (order.interpretation_report) {
                    html += `<hr><div><strong>Interpretation Report:</strong><br><div class="report-preview">${order.interpretation_report}</div></div>`;
                }
                
                if (order.physician_review_notes) {
                    html += `<hr><div><strong>Review Notes:</strong><br>${order.physician_review_notes}</div>`;
                }
                
                if (order.cancellation_reason) {
                    html += `<hr><div class="text-danger"><strong>Cancellation Reason:</strong><br>${order.cancellation_reason}</div>`;
                }
                
                document.getElementById('orderDetailsContent').innerHTML = html;
            } else {
                document.getElementById('orderDetailsContent').innerHTML = '<p class="text-danger"><i class="fas fa-exclamation-circle"></i> ' + data.message + '</p>';
            }
        })
        .catch(error => {
            document.getElementById('orderDetailsContent').innerHTML = '<p class="text-danger"><i class="fas fa-exclamation-circle"></i> Error loading order details. Please try again.</p>';
            console.error('Error:', error);
        });
}

function updateStatus(orderId, currentStatus) {
    document.getElementById('updateOrderId').value = orderId;
    
    // Get order number
    fetch('?ajax=get_order&id=' + orderId)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                document.getElementById('updateOrderNumber').textContent = data.order.order_number;
            }
        });
    
    // Show/hide fields based on status
    const statusSelect = document.getElementById('updateNewStatus');
    statusSelect.value = '';
    
    // Set available options based on current status
    const options = {
        'pending_payment': ['scheduled'],
        'scheduled': ['in_progress', 'cancelled'],
        'in_progress': ['completed', 'cancelled'],
        'completed': ['reviewed']
    };
    
    const available = options[currentStatus] || ['scheduled', 'in_progress', 'completed', 'reviewed'];
    statusSelect.innerHTML = '<option value="">Select Status</option>';
    available.forEach(status => {
        const label = status.replace('_', ' ').toUpperCase();
        statusSelect.innerHTML += `<option value="${status}">${label}</option>`;
    });
    
    // Hide all dynamic groups
    document.getElementById('scheduleGroup').style.display = 'none';
    document.getElementById('findingsGroup').style.display = 'none';
    document.getElementById('impressionGroup').style.display = 'none';
    document.getElementById('reportGroup').style.display = 'none';
    document.getElementById('reviewNotesGroup').style.display = 'none';
    document.getElementById('reasonGroup').style.display = 'none';
    
    statusSelect.onchange = function() {
        // Hide all first
        document.getElementById('scheduleGroup').style.display = 'none';
        document.getElementById('findingsGroup').style.display = 'none';
        document.getElementById('impressionGroup').style.display = 'none';
        document.getElementById('reportGroup').style.display = 'none';
        document.getElementById('reviewNotesGroup').style.display = 'none';
        document.getElementById('reasonGroup').style.display = 'none';
        
        if (this.value === 'scheduled') {
            document.getElementById('scheduleGroup').style.display = 'block';
        }
        if (this.value === 'completed') {
            document.getElementById('findingsGroup').style.display = 'block';
            document.getElementById('impressionGroup').style.display = 'block';
        }
        if (this.value === 'reviewed') {
            document.getElementById('reportGroup').style.display = 'block';
            document.getElementById('reviewNotesGroup').style.display = 'block';
        }
        if (this.value === 'cancelled') {
            document.getElementById('reasonGroup').style.display = 'block';
        }
    };
    
    openModal('updateStatusModal');
}

function verifyPayment(orderId) {
    document.getElementById('paymentOrderId').value = orderId;
    
    fetch('?ajax=get_order&id=' + orderId)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                document.getElementById('paymentOrderNumber').textContent = data.order.order_number;
            }
        });
    
    openModal('verifyPaymentModal');
}

function addInterpretation(orderId) {
    document.getElementById('interpretationOrderId').value = orderId;
    
    fetch('?ajax=get_order&id=' + orderId)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                document.getElementById('interpretationOrderNumber').textContent = data.order.order_number;
                // Pre-fill existing data if available
                if (data.order.findings) {
                    document.querySelector('#interpretationModal textarea[name="findings"]').value = data.order.findings;
                }
                if (data.order.impression) {
                    document.querySelector('#interpretationModal textarea[name="impression"]').value = data.order.impression;
                }
                if (data.order.interpretation_report) {
                    document.querySelector('#interpretationModal textarea[name="interpretation_report"]').value = data.order.interpretation_report;
                }
            }
        });
    
    openModal('interpretationModal');
}

function cancelOrder(orderId) {
    if (confirm('Are you sure you want to cancel this order?')) {
        document.getElementById('updateOrderId').value = orderId;
        document.getElementById('updateNewStatus').value = 'cancelled';
        document.getElementById('reasonGroup').style.display = 'block';
        
        fetch('?ajax=get_order&id=' + orderId)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    document.getElementById('updateOrderNumber').textContent = data.order.order_number;
                }
            });
        
        openModal('updateStatusModal');
    }
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
        openModal('createOrderModal');
    }
});
</script>

<!-- DataTables -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script>
$(document).ready(function() {
    var hasRows = $('#ordersTable tbody tr:not(.no-data)').length > 0;
    
    if (hasRows) {
        $('#ordersTable').DataTable({
            responsive: true,
            pageLength: 25,
            order: [[7, 'desc']],
            columnDefs: [
                { orderable: false, targets: [8] }
            ],
            language: {
                emptyTable: "No radiology orders found",
                search: "Search:",
                lengthMenu: "Show _MENU_ entries",
                info: "Showing _START_ to _END_ of _TOTAL_ entries"
            }
        });
    } else {
        $('#ordersTable').DataTable({
            responsive: true,
            pageLength: 25,
            order: [[7, 'desc']],
            columnDefs: [
                { orderable: false, targets: [8] }
            ],
            language: {
                emptyTable: "No radiology orders found"
            }
        });
    }
});
</script>

</body>
</html>