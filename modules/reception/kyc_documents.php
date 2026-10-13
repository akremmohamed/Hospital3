<?php
/**
 * KYC Documents - HMS ERP System
 * Enhanced with complete database integration and advanced features
 * 
 * @package HMS-ERP
 * @version 3.0
 * @requires patient_kyc_manage_access permission
 */

// Start session before any output
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Load configuration and helpers
require_once __DIR__ . '/../../config/db_connection.php';
require_once __DIR__ . '/../../includes/auth_helper.php';

// Set timezone
date_default_timezone_set('Africa/Addis_Ababa');

// Enable error reporting for debugging (remove in production)
error_reporting(E_ALL);
ini_set('display_errors', 0);

// ============================================
// AUTHENTICATION & AUTHORIZATION
// ============================================

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../index.php');
    exit;
}

// Get user information from session
$user_id = (int)($_SESSION['user_id'] ?? 0);
$user_branch_id = (int)($_SESSION['branch_id'] ?? 0);
$is_global_admin = ($_SESSION['user_type'] ?? '') === 'super_admin';
$user_name = $_SESSION['first_name'] ?? $_SESSION['username'] ?? 'User';
$user_type = $_SESSION['user_type'] ?? '';

// Check database availability
global $pdo;
$db_available = ($pdo instanceof PDO);

if (!$db_available) {
    die('Database connection failed. Please check your configuration.');
}

// Permission check for managing KYC documents
$can_manage_kyc = $is_global_admin;

if (!$is_global_admin && $db_available && $user_id > 0) {
    $can_manage_kyc = user_has_permission($pdo, 'patient_kyc_manage_access', $user_id);
}

// Also check view-only permission
$can_view_kyc = $is_global_admin;
if (!$is_global_admin && $db_available && $user_id > 0) {
    $can_view_kyc = user_has_permission($pdo, 'view_patient_kyc', $user_id) || $can_manage_kyc;
}

// If user has no permission, redirect
if (!$can_view_kyc && !$can_manage_kyc) {
    header('Location: dashboard.php?error=unauthorized');
    exit;
}

// CSRF Token setup
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Helper function for JSON responses
function json_exit(array $payload, int $code = 200): void {
    if (ob_get_length()) {
        ob_clean();
    }
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// Secure file storage configuration
define('KYC_STORAGE_PATH', dirname(__DIR__, 2) . '/storage/kyc_documents/');
define('KYC_ALLOWED_TYPES', ['image/jpeg', 'image/png', 'image/jpg', 'application/pdf']);
define('KYC_MAX_FILE_SIZE', 10 * 1024 * 1024); // 10MB
define('KYC_ALLOWED_EXTENSIONS', ['jpg', 'jpeg', 'png', 'pdf']);

// ============================================
// PREVIEW DOCUMENT - With Branch Access Control
// ============================================
if (isset($_GET['preview_id'])) {
    if (!$db_available) {
        http_response_code(500);
        echo 'Database unavailable';
        exit;
    }
    
    $doc_id = (int)($_GET['preview_id'] ?? 0);
    if ($doc_id <= 0) {
        http_response_code(404);
        echo 'Invalid document ID';
        exit;
    }
    
    // Validate CSRF token for preview (optional but recommended)
    $token = $_GET['csrf_token'] ?? '';
    if ($token !== $_SESSION['csrf_token']) {
        error_log("KYC preview: CSRF validation warning for user {$user_id}");
        // Continue but log - preview is read-only
    }

    try {
        // Fetch document with patient branch info for access control
        $stmt = $pdo->prepare("
            SELECT d.file_path, d.file_type, d.original_filename, p.branch_id,
                   d.patient_id, d.document_name, d.document_number
            FROM patient_kyc_documents d
            INNER JOIN patients p ON d.patient_id = p.id
            WHERE d.id = :doc_id AND d.deleted_at IS NULL
            LIMIT 1
        ");
        $stmt->execute([':doc_id' => $doc_id]);
        $doc = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$doc) {
            http_response_code(404);
            echo 'Document not found';
            exit;
        }
        
        // Branch access control
        if (!$is_global_admin && $user_branch_id > 0 && $doc['branch_id'] != $user_branch_id) {
            http_response_code(403);
            echo 'Access denied';
            exit;
        }

        $full_path = KYC_STORAGE_PATH . basename($doc['file_path']);
        
        if (!file_exists($full_path) || !is_file($full_path)) {
            http_response_code(404);
            echo 'File not found on server';
            exit;
        }

        $mime = $doc['file_type'] ?: 'application/octet-stream';
        $safe_name = preg_replace('/[^a-zA-Z0-9._-]/', '_', $doc['original_filename'] ?? 'document');

        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($full_path));
        header('Content-Disposition: inline; filename="' . $safe_name . '"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: public, max-age=3600');
        readfile($full_path);
        exit;
        
    } catch (PDOException $e) {
        error_log("Preview error: " . $e->getMessage());
        http_response_code(500);
        echo 'Error loading document';
        exit;
    }
}

// ============================================
// AJAX: PATIENT SEARCH - With Branch Isolation
// ============================================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'patient_search') {
    if (!$db_available) {
        json_exit(['success' => false, 'items' => []]);
    }
    
    $term = trim((string)($_GET['q'] ?? ''));
    $like = '%' . $term . '%';

    try {
        $sql = "
            SELECT 
                p.id, 
                p.mr_number, 
                p.first_name, 
                p.middle_name, 
                p.last_name, 
                p.national_id, 
                p.branch_id,
                p.phone,
                p.email,
                p.date_of_birth,
                p.gender,
                COALESCE(ip.name, '') AS insurance_provider
            FROM patients p
            LEFT JOIN patient_insurance_profiles pip ON p.id = pip.patient_id 
                AND pip.deleted_at IS NULL AND pip.status = 'active'
            LEFT JOIN insurance_providers ip ON pip.insurance_provider_id = ip.id 
                AND ip.deleted_at IS NULL
            WHERE p.deleted_at IS NULL
              AND (:term = '' OR p.first_name LIKE :like1 OR p.middle_name LIKE :like2 
                   OR p.last_name LIKE :like3 OR p.mr_number LIKE :like4 OR p.national_id LIKE :like5
                   OR p.phone LIKE :like6 OR p.email LIKE :like7)
        ";
        
        $params = [
            ':term' => $term,
            ':like1' => $like,
            ':like2' => $like,
            ':like3' => $like,
            ':like4' => $like,
            ':like5' => $like,
            ':like6' => $like,
            ':like7' => $like
        ];
        
        // Branch isolation
        if (!$is_global_admin && $user_branch_id > 0) {
            $sql .= " AND p.branch_id = :branch_id";
            $params[':branch_id'] = $user_branch_id;
        }
        
        $sql .= " ORDER BY p.created_at DESC LIMIT 20";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $items = [];
        foreach ($results as $row) {
            $name = trim(($row['first_name'] ?? '') . ' ' . ($row['middle_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
            if (empty($name)) $name = 'Unknown Patient';
            $items[] = [
                'id' => (int)$row['id'],
                'name' => htmlspecialchars($name, ENT_QUOTES, 'UTF-8'),
                'mr_number' => htmlspecialchars($row['mr_number'] ?? '', ENT_QUOTES, 'UTF-8'),
                'national_id' => htmlspecialchars($row['national_id'] ?? '', ENT_QUOTES, 'UTF-8'),
                'insurance_provider' => htmlspecialchars($row['insurance_provider'] ?? '', ENT_QUOTES, 'UTF-8'),
                'phone' => htmlspecialchars($row['phone'] ?? '', ENT_QUOTES, 'UTF-8'),
                'email' => htmlspecialchars($row['email'] ?? '', ENT_QUOTES, 'UTF-8'),
                'dob' => $row['date_of_birth'] ?? '',
                'gender' => $row['gender'] ?? ''
            ];
        }
        json_exit(['success' => true, 'items' => $items]);
        
    } catch (PDOException $e) {
        error_log("Patient search error: " . $e->getMessage());
        json_exit(['success' => false, 'items' => []]);
    }
}

// ============================================
// AJAX: GET PATIENT KYC STATUS
// ============================================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'kyc_status') {
    if (!$db_available) {
        json_exit(['success' => false, 'message' => 'Database unavailable'], 500);
    }
    
    $patient_id = (int)($_GET['patient_id'] ?? 0);
    if ($patient_id <= 0) {
        json_exit(['success' => false, 'message' => 'Invalid patient ID'], 400);
    }
    
    try {
        // Verify patient access
        $verify_stmt = $pdo->prepare("SELECT branch_id FROM patients WHERE id = :id AND deleted_at IS NULL LIMIT 1");
        $verify_stmt->execute([':id' => $patient_id]);
        $patient_check = $verify_stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$patient_check) {
            json_exit(['success' => false, 'message' => 'Patient not found'], 404);
        }
        
        if (!$is_global_admin && $user_branch_id > 0 && $patient_check['branch_id'] != $user_branch_id) {
            json_exit(['success' => false, 'message' => 'Access denied'], 403);
        }
        
        // Get KYC completion status
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(*) as total_documents,
                SUM(CASE WHEN status = 'verified' THEN 1 ELSE 0 END) as verified_count,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_count,
                SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected_count,
                SUM(CASE WHEN status = 'expired' THEN 1 ELSE 0 END) as expired_count,
                SUM(CASE WHEN dt.is_mandatory = 1 AND d.status = 'verified' THEN 1 ELSE 0 END) as mandatory_verified
            FROM patient_kyc_documents d
            LEFT JOIN kyc_document_types dt ON d.document_type_id = dt.id
            WHERE d.patient_id = :patient_id AND d.deleted_at IS NULL
        ");
        $stmt->execute([':patient_id' => $patient_id]);
        $status = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Check if all mandatory documents are verified
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as mandatory_total
            FROM kyc_document_types
            WHERE is_mandatory = 1 AND is_active = 1 AND deleted_at IS NULL
        ");
        $stmt->execute();
        $mandatory_total = $stmt->fetchColumn();
        
        $is_kyc_complete = ($status['mandatory_verified'] ?? 0) >= $mandatory_total;
        
        json_exit([
            'success' => true,
            'data' => [
                'total_documents' => (int)$status['total_documents'],
                'verified_count' => (int)$status['verified_count'],
                'pending_count' => (int)$status['pending_count'],
                'rejected_count' => (int)$status['rejected_count'],
                'expired_count' => (int)$status['expired_count'],
                'mandatory_verified' => (int)$status['mandatory_verified'],
                'mandatory_total' => (int)$mandatory_total,
                'is_kyc_complete' => $is_kyc_complete
            ]
        ]);
        
    } catch (PDOException $e) {
        error_log("KYC status error: " . $e->getMessage());
        json_exit(['success' => false, 'message' => 'Failed to fetch status'], 500);
    }
}

// ============================================
// AJAX: UPLOAD DOCUMENT - With CSRF and Branch Validation
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['document_file'])) {
    if (!$db_available) {
        json_exit(['success' => false, 'message' => 'Database unavailable'], 500);
    }
    
    // Validate CSRF token
    $submitted_token = $_POST['csrf_token'] ?? '';
    if (!isset($_SESSION['csrf_token']) || $submitted_token !== $_SESSION['csrf_token']) {
        error_log("KYC upload: CSRF validation failed for user {$user_id}");
        json_exit(['success' => false, 'message' => 'Security validation failed. Please refresh the page and try again.'], 403);
    }
    
    // Check permission for upload
    if (!$can_manage_kyc) {
        json_exit(['success' => false, 'message' => 'You do not have permission to upload documents'], 403);
    }
    
    $patient_id = (int)($_POST['patient_id'] ?? 0);
    $document_type_id = (int)($_POST['document_type_id'] ?? 1);
    $document_name = trim($_POST['document_name'] ?? '');
    $document_number = trim($_POST['document_number'] ?? '');
    $verification_notes = trim($_POST['verification_notes'] ?? '');
    $expiry_date = trim($_POST['expiry_date'] ?? '');
    
    if ($patient_id <= 0) {
        json_exit(['success' => false, 'message' => 'Invalid patient ID'], 400);
    }
    
    // Verify patient belongs to user's branch
    try {
        $verify_stmt = $pdo->prepare("SELECT branch_id FROM patients WHERE id = :id AND deleted_at IS NULL LIMIT 1");
        $verify_stmt->execute([':id' => $patient_id]);
        $patient_check = $verify_stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$patient_check) {
            json_exit(['success' => false, 'message' => 'Patient not found'], 404);
        }
        
        if (!$is_global_admin && $user_branch_id > 0 && $patient_check['branch_id'] != $user_branch_id) {
            json_exit(['success' => false, 'message' => 'Access denied - Patient belongs to different branch'], 403);
        }
    } catch (PDOException $e) {
        json_exit(['success' => false, 'message' => 'Patient verification failed'], 500);
    }
    
    // Validate document type exists
    try {
        $type_stmt = $pdo->prepare("SELECT id, name, is_mandatory, requires_expiry FROM kyc_document_types WHERE id = :id AND is_active = 1 AND deleted_at IS NULL");
        $type_stmt->execute([':id' => $document_type_id]);
        $doc_type = $type_stmt->fetch(PDO::FETCH_ASSOC);
        if (!$doc_type) {
            json_exit(['success' => false, 'message' => 'Invalid document type'], 400);
        }
    } catch (PDOException $e) {
        json_exit(['success' => false, 'message' => 'Document type verification failed'], 500);
    }
    
    // Validate expiry date if required
    if ($doc_type['requires_expiry'] && empty($expiry_date)) {
        json_exit(['success' => false, 'message' => 'Expiry date is required for this document type'], 400);
    }
    if (!empty($expiry_date)) {
        $dt = DateTime::createFromFormat('Y-m-d', $expiry_date);
        if (!$dt || $dt->format('Y-m-d') !== $expiry_date) {
            json_exit(['success' => false, 'message' => 'Invalid expiry date format'], 400);
        }
        if ($dt < new DateTime()) {
            json_exit(['success' => false, 'message' => 'Expiry date cannot be in the past'], 400);
        }
    }
    
    // Validate file upload
    if (!isset($_FILES['document_file']) || $_FILES['document_file']['error'] !== UPLOAD_ERR_OK) {
        json_exit(['success' => false, 'message' => 'File upload failed or no file selected'], 400);
    }
    
    $file = $_FILES['document_file'];
    $file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    
    // Validate file type
    if (!in_array($file['type'], KYC_ALLOWED_TYPES)) {
        json_exit(['success' => false, 'message' => 'Invalid file type. Allowed: PDF, JPG, JPEG, PNG'], 400);
    }
    
    if (!in_array($file_extension, KYC_ALLOWED_EXTENSIONS)) {
        json_exit(['success' => false, 'message' => 'Invalid file extension. Allowed: jpg, jpeg, png, pdf'], 400);
    }
    
    // Validate file size
    if ($file['size'] > KYC_MAX_FILE_SIZE) {
        json_exit(['success' => false, 'message' => 'File too large. Maximum 10MB allowed'], 400);
    }
    
    // Create storage directory if not exists
    if (!is_dir(KYC_STORAGE_PATH)) {
        mkdir(KYC_STORAGE_PATH, 0755, true);
    }
    
    // Generate secure filename
    $stored_filename = $patient_id . '_' . time() . '_' . bin2hex(random_bytes(16)) . '.' . $file_extension;
    $relative_path = 'storage/kyc_documents/' . $stored_filename;
    $full_path = KYC_STORAGE_PATH . $stored_filename;
    
    // Move uploaded file
    if (!move_uploaded_file($file['tmp_name'], $full_path)) {
        json_exit(['success' => false, 'message' => 'Failed to save uploaded file'], 500);
    }
    
    // Set secure file permissions
    chmod($full_path, 0644);
    
    try {
        $stmt = $pdo->prepare("
            INSERT INTO patient_kyc_documents (
                patient_id, document_type_id, document_name, document_number,
                file_path, file_type, file_size, original_filename,
                expiry_date, verification_notes, status, uploaded_by_user_id, created_at
            ) VALUES (
                :patient_id, :doc_type_id, :doc_name, :doc_number,
                :file_path, :file_type, :file_size, :original_filename,
                :expiry_date, :notes, 'pending', :user_id, NOW()
            )
        ");
        $stmt->execute([
            ':patient_id' => $patient_id,
            ':doc_type_id' => $document_type_id,
            ':doc_name' => !empty($document_name) ? $document_name : null,
            ':doc_number' => !empty($document_number) ? $document_number : null,
            ':file_path' => $relative_path,
            ':file_type' => $file['type'],
            ':file_size' => $file['size'],
            ':original_filename' => $file['name'],
            ':expiry_date' => !empty($expiry_date) ? $expiry_date : null,
            ':notes' => !empty($verification_notes) ? $verification_notes : null,
            ':user_id' => $user_id
        ]);
        
        $doc_id = $pdo->lastInsertId();
        
        // Log the action
        if (function_exists('log_user_action')) {
            log_user_action($pdo, 'INSERT', 'patient_kyc_documents', $doc_id, null, [
                'patient_id' => $patient_id,
                'document_type_id' => $document_type_id,
                'file_size' => $file['size']
            ]);
        }
        
        // Check if this completed KYC requirements
        $status_stmt = $pdo->prepare("
            SELECT COUNT(*) as mandatory_total
            FROM kyc_document_types
            WHERE is_mandatory = 1 AND is_active = 1 AND deleted_at IS NULL
        ");
        $status_stmt->execute();
        $mandatory_total = $status_stmt->fetchColumn();
        
        $complete_stmt = $pdo->prepare("
            SELECT COUNT(DISTINCT d.document_type_id) as completed
            FROM patient_kyc_documents d
            INNER JOIN kyc_document_types dt ON d.document_type_id = dt.id
            WHERE d.patient_id = :patient_id 
              AND d.status = 'verified' 
              AND dt.is_mandatory = 1 
              AND d.deleted_at IS NULL
        ");
        $complete_stmt->execute([':patient_id' => $patient_id]);
        $completed = $complete_stmt->fetchColumn();
        
        $kyc_complete = ($completed >= $mandatory_total);
        
        json_exit([
            'success' => true, 
            'message' => 'Document uploaded successfully',
            'document_id' => $doc_id,
            'kyc_complete' => $kyc_complete
        ]);
        
    } catch (PDOException $e) {
        error_log("Upload insert error: " . $e->getMessage());
        // Delete uploaded file if database insert fails
        if (file_exists($full_path)) {
            unlink($full_path);
        }
        json_exit(['success' => false, 'message' => 'Database error: ' . $e->getMessage()], 500);
    }
}

// ============================================
// AJAX: UPDATE DOCUMENT STATUS - With CSRF Validation
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    if (!$db_available) {
        json_exit(['success' => false, 'message' => 'Database unavailable'], 500);
    }
    
    // Validate CSRF token
    $submitted_token = $_POST['csrf_token'] ?? '';
    if (!isset($_SESSION['csrf_token']) || $submitted_token !== $_SESSION['csrf_token']) {
        error_log("KYC status update: CSRF validation failed for user {$user_id}");
        json_exit(['success' => false, 'message' => 'Security validation failed. Please refresh the page and try again.'], 403);
    }
    
    // Check permission for update
    if (!$can_manage_kyc) {
        json_exit(['success' => false, 'message' => 'You do not have permission to update documents'], 403);
    }
    
    $doc_id = (int)($_POST['doc_id'] ?? 0);
    $status = (string)($_POST['status'] ?? '');
    $expiry_date_raw = trim((string)($_POST['expiry_date'] ?? ''));
    $verification_notes = trim((string)($_POST['verification_notes'] ?? ''));
    $patient_id = (int)($_POST['patient_id'] ?? 0);
    
    $valid_statuses = ['pending', 'verified', 'rejected'];
    
    if ($doc_id <= 0 || !in_array($status, $valid_statuses, true)) {
        json_exit(['success' => false, 'message' => 'Invalid request.'], 422);
    }
    
    // Verify document belongs to user's branch
    try {
        $verify_stmt = $pdo->prepare("
            SELECT d.id, p.branch_id, d.document_type_id, d.patient_id,
                   dt.requires_expiry, dt.is_mandatory
            FROM patient_kyc_documents d
            INNER JOIN patients p ON d.patient_id = p.id
            LEFT JOIN kyc_document_types dt ON d.document_type_id = dt.id
            WHERE d.id = :doc_id AND d.deleted_at IS NULL
            LIMIT 1
        ");
        $verify_stmt->execute([':doc_id' => $doc_id]);
        $doc_check = $verify_stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$doc_check) {
            json_exit(['success' => false, 'message' => 'Document not found'], 404);
        }
        
        if (!$is_global_admin && $user_branch_id > 0 && $doc_check['branch_id'] != $user_branch_id) {
            json_exit(['success' => false, 'message' => 'Access denied'], 403);
        }
        
        // If verifying, check expiry date requirement
        if ($status === 'verified' && $doc_check['requires_expiry']) {
            if (empty($expiry_date_raw)) {
                json_exit(['success' => false, 'message' => 'Expiry date is required for this document type'], 422);
            }
        }
    } catch (PDOException $e) {
        json_exit(['success' => false, 'message' => 'Document verification failed'], 500);
    }
    
    $expiry_date = null;
    if ($expiry_date_raw !== '') {
        $dt = DateTime::createFromFormat('Y-m-d', $expiry_date_raw);
        if (!$dt || $dt->format('Y-m-d') !== $expiry_date_raw) {
            json_exit(['success' => false, 'message' => 'Invalid expiry date format.'], 422);
        }
        $expiry_date = $expiry_date_raw;
    }
    
    try {
        $pdo->beginTransaction();
        
        if ($status === 'verified') {
            $sql = "UPDATE patient_kyc_documents 
                    SET status = :status, expiry_date = :expiry_date, 
                        verified_by_user_id = :user_id, verified_at = NOW(), 
                        verification_notes = :notes,
                        updated_at = NOW()
                    WHERE id = :doc_id AND deleted_at IS NULL";
        } else {
            $sql = "UPDATE patient_kyc_documents 
                    SET status = :status, expiry_date = :expiry_date, 
                        verified_by_user_id = NULL, verified_at = NULL, 
                        verification_notes = :notes,
                        updated_at = NOW()
                    WHERE id = :doc_id AND deleted_at IS NULL";
        }
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':status' => $status,
            ':expiry_date' => $expiry_date,
            ':notes' => !empty($verification_notes) ? $verification_notes : null,
            ':user_id' => $user_id,
            ':doc_id' => $doc_id
        ]);
        
        // Check if KYC is now complete
        $status_stmt = $pdo->prepare("
            SELECT COUNT(*) as mandatory_total
            FROM kyc_document_types
            WHERE is_mandatory = 1 AND is_active = 1 AND deleted_at IS NULL
        ");
        $status_stmt->execute();
        $mandatory_total = $status_stmt->fetchColumn();
        
        $complete_stmt = $pdo->prepare("
            SELECT COUNT(DISTINCT d.document_type_id) as completed
            FROM patient_kyc_documents d
            INNER JOIN kyc_document_types dt ON d.document_type_id = dt.id
            WHERE d.patient_id = :patient_id 
              AND d.status = 'verified' 
              AND dt.is_mandatory = 1 
              AND d.deleted_at IS NULL
        ");
        $complete_stmt->execute([':patient_id' => $doc_check['patient_id']]);
        $completed = $complete_stmt->fetchColumn();
        
        $kyc_complete = ($completed >= $mandatory_total);
        
        $pdo->commit();
        
        json_exit([
            'success' => true, 
            'message' => 'Document status updated successfully.',
            'kyc_complete' => $kyc_complete
        ]);
        
    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log("Status update error: " . $e->getMessage());
        json_exit(['success' => false, 'message' => 'Update failed: ' . $e->getMessage()], 500);
    }
}

// ============================================
// AJAX: DELETE DOCUMENT (Soft Delete) - With CSRF Validation
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_document'])) {
    if (!$db_available) {
        json_exit(['success' => false, 'message' => 'Database unavailable'], 500);
    }
    
    // Validate CSRF token
    $submitted_token = $_POST['csrf_token'] ?? '';
    if (!isset($_SESSION['csrf_token']) || $submitted_token !== $_SESSION['csrf_token']) {
        error_log("KYC delete: CSRF validation failed for user {$user_id}");
        json_exit(['success' => false, 'message' => 'Security validation failed. Please refresh the page and try again.'], 403);
    }
    
    // Check permission for delete
    if (!$can_manage_kyc) {
        json_exit(['success' => false, 'message' => 'You do not have permission to delete documents'], 403);
    }
    
    $doc_id = (int)($_POST['doc_id'] ?? 0);
    if ($doc_id <= 0) {
        json_exit(['success' => false, 'message' => 'Invalid document ID.'], 422);
    }
    
    // Verify document belongs to user's branch
    try {
        $verify_stmt = $pdo->prepare("
            SELECT d.id, p.branch_id, d.file_path, d.patient_id
            FROM patient_kyc_documents d
            INNER JOIN patients p ON d.patient_id = p.id
            WHERE d.id = :doc_id AND d.deleted_at IS NULL
            LIMIT 1
        ");
        $verify_stmt->execute([':doc_id' => $doc_id]);
        $doc_check = $verify_stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$doc_check) {
            json_exit(['success' => false, 'message' => 'Document not found'], 404);
        }
        
        if (!$is_global_admin && $user_branch_id > 0 && $doc_check['branch_id'] != $user_branch_id) {
            json_exit(['success' => false, 'message' => 'Access denied'], 403);
        }
        
        $pdo->beginTransaction();
        
        // Soft delete the document record
        $stmt = $pdo->prepare("UPDATE patient_kyc_documents SET deleted_at = NOW(), updated_at = NOW() WHERE id = :id AND deleted_at IS NULL");
        $stmt->execute([':id' => $doc_id]);
        
        // Log the deletion
        if (function_exists('log_user_action')) {
            log_user_action($pdo, 'DELETE', 'patient_kyc_documents', $doc_id, null, [
                'patient_id' => $doc_check['patient_id'],
                'file_path' => $doc_check['file_path']
            ]);
        }
        
        // Note: We don't delete the physical file immediately for audit purposes
        // You may add a cleanup job later
        
        $pdo->commit();
        
        json_exit(['success' => true, 'message' => 'Document deleted successfully.']);
        
    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log("Delete error: " . $e->getMessage());
        json_exit(['success' => false, 'message' => 'Delete failed: ' . $e->getMessage()], 500);
    }
}

// ============================================
// AJAX: BULK VERIFY DOCUMENTS
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_verify'])) {
    if (!$db_available) {
        json_exit(['success' => false, 'message' => 'Database unavailable'], 500);
    }
    
    // Validate CSRF token
    $submitted_token = $_POST['csrf_token'] ?? '';
    if (!isset($_SESSION['csrf_token']) || $submitted_token !== $_SESSION['csrf_token']) {
        error_log("KYC bulk verify: CSRF validation failed for user {$user_id}");
        json_exit(['success' => false, 'message' => 'Security validation failed.'], 403);
    }
    
    // Check permission
    if (!$can_manage_kyc) {
        json_exit(['success' => false, 'message' => 'You do not have permission to verify documents'], 403);
    }
    
    $patient_id = (int)($_POST['patient_id'] ?? 0);
    $doc_ids = $_POST['doc_ids'] ?? [];
    
    if ($patient_id <= 0 || empty($doc_ids)) {
        json_exit(['success' => false, 'message' => 'Invalid request'], 422);
    }
    
    try {
        // Verify patient belongs to user's branch
        $verify_stmt = $pdo->prepare("SELECT branch_id FROM patients WHERE id = :id AND deleted_at IS NULL LIMIT 1");
        $verify_stmt->execute([':id' => $patient_id]);
        $patient_check = $verify_stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$patient_check) {
            json_exit(['success' => false, 'message' => 'Patient not found'], 404);
        }
        
        if (!$is_global_admin && $user_branch_id > 0 && $patient_check['branch_id'] != $user_branch_id) {
            json_exit(['success' => false, 'message' => 'Access denied'], 403);
        }
        
        $placeholders = implode(',', array_fill(0, count($doc_ids), '?'));
        $sql = "UPDATE patient_kyc_documents 
                SET status = 'verified', verified_by_user_id = ?, verified_at = NOW(), updated_at = NOW()
                WHERE id IN ($placeholders) 
                AND patient_id = ? 
                AND deleted_at IS NULL
                AND status = 'pending'";
        
        $params = array_merge([$user_id], $doc_ids, [$patient_id]);
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        
        $affected = $stmt->rowCount();
        
        json_exit([
            'success' => true, 
            'message' => "Successfully verified $affected document(s)",
            'verified_count' => $affected
        ]);
        
    } catch (PDOException $e) {
        error_log("Bulk verify error: " . $e->getMessage());
        json_exit(['success' => false, 'message' => 'Verification failed: ' . $e->getMessage()], 500);
    }
}

// ============================================
// GET DOCUMENT TYPES FOR DROPDOWN
// ============================================
$document_types = [];
if ($db_available) {
    try {
        $stmt = $pdo->query("
            SELECT id, code, name, is_mandatory, requires_expiry, display_order, description
            FROM kyc_document_types 
            WHERE is_active = 1 AND deleted_at IS NULL 
            ORDER BY display_order ASC
        ");
        $document_types = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Document types fetch error: " . $e->getMessage());
    }
}

// ============================================
// MAIN PAGE - FETCH PATIENT AND DOCUMENTS
// ============================================
$patient_id = (int)($_GET['patient_id'] ?? 0);
$patient = null;
$documents = [];
$required_missing = true;
$kyc_complete = false;
$kyc_summary = [];

if ($patient_id > 0 && $db_available) {
    try {
        $stmt = $pdo->prepare("
            SELECT p.id, p.branch_id, p.mr_number, p.first_name, p.middle_name, p.last_name, 
                   p.national_id, p.phone, p.email, p.date_of_birth, p.gender,
                   p.is_active, p.is_consent_given, p.created_at,
                   COALESCE(ip.name, '') AS insurance_provider
            FROM patients p
            LEFT JOIN patient_insurance_profiles pip ON p.id = pip.patient_id 
                AND pip.deleted_at IS NULL AND pip.status = 'active'
            LEFT JOIN insurance_providers ip ON pip.insurance_provider_id = ip.id 
                AND ip.deleted_at IS NULL
            WHERE p.id = :id AND p.deleted_at IS NULL
            LIMIT 1
        ");
        $stmt->execute([':id' => $patient_id]);
        $patient = $stmt->fetch(PDO::FETCH_ASSOC);

        // Branch access control
        if ($patient && !$is_global_admin && $user_branch_id > 0 && $patient['branch_id'] != $user_branch_id) {
            $patient = null;
        }

        if ($patient) {
            // Get documents with document type info
            $doc_stmt = $pdo->prepare("
                SELECT d.*, dt.name AS document_type_name, dt.is_mandatory, dt.requires_expiry,
                       u.first_name AS verified_by_first_name, u.last_name AS verified_by_last_name,
                       u2.first_name AS uploaded_by_first_name, u2.last_name AS uploaded_by_last_name
                FROM patient_kyc_documents d
                LEFT JOIN kyc_document_types dt ON d.document_type_id = dt.id
                LEFT JOIN users u ON d.verified_by_user_id = u.id
                LEFT JOIN users u2 ON d.uploaded_by_user_id = u2.id
                WHERE d.patient_id = :patient_id AND d.deleted_at IS NULL
                ORDER BY 
                    CASE d.status 
                        WHEN 'pending' THEN 1
                        WHEN 'verified' THEN 2
                        WHEN 'expired' THEN 3
                        WHEN 'rejected' THEN 4
                        ELSE 5
                    END,
                    d.created_at DESC
            ");
            $doc_stmt->execute([':patient_id' => $patient_id]);
            $documents = $doc_stmt->fetchAll(PDO::FETCH_ASSOC);

            // Get KYC summary
            $summary_stmt = $pdo->prepare("
                SELECT 
                    dt.id as type_id,
                    dt.name as type_name,
                    dt.is_mandatory,
                    dt.requires_expiry,
                    COUNT(d.id) as document_count,
                    SUM(CASE WHEN d.status = 'verified' THEN 1 ELSE 0 END) as verified_count
                FROM kyc_document_types dt
                LEFT JOIN patient_kyc_documents d ON d.document_type_id = dt.id 
                    AND d.patient_id = :patient_id 
                    AND d.deleted_at IS NULL
                WHERE dt.is_active = 1 AND dt.deleted_at IS NULL
                GROUP BY dt.id, dt.name, dt.is_mandatory, dt.requires_expiry
                ORDER BY dt.display_order ASC
            ");
            $summary_stmt->execute([':patient_id' => $patient_id]);
            $kyc_summary = $summary_stmt->fetchAll(PDO::FETCH_ASSOC);

            // Check if KYC is complete
            $complete_stmt = $pdo->prepare("
                SELECT 
                    SUM(CASE WHEN dt.is_mandatory = 1 THEN 1 ELSE 0 END) as mandatory_total,
                    SUM(CASE WHEN dt.is_mandatory = 1 AND d.status = 'verified' THEN 1 ELSE 0 END) as mandatory_verified
                FROM kyc_document_types dt
                LEFT JOIN patient_kyc_documents d ON d.document_type_id = dt.id 
                    AND d.patient_id = :patient_id 
                    AND d.deleted_at IS NULL
                    AND d.status = 'verified'
                WHERE dt.is_active = 1 AND dt.deleted_at IS NULL
            ");
            $complete_stmt->execute([':patient_id' => $patient_id]);
            $completion = $complete_stmt->fetch(PDO::FETCH_ASSOC);
            
            $mandatory_total = (int)$completion['mandatory_total'];
            $mandatory_verified = (int)$completion['mandatory_verified'];
            $kyc_complete = ($mandatory_total > 0 && $mandatory_verified >= $mandatory_total);
            $required_missing = !$kyc_complete;

            // Check for expiring documents
            $expiring_stmt = $pdo->prepare("
                SELECT COUNT(*) as expiring_count
                FROM patient_kyc_documents
                WHERE patient_id = :patient_id 
                  AND status = 'verified'
                  AND expiry_date IS NOT NULL
                  AND expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
                  AND deleted_at IS NULL
            ");
            $expiring_stmt->execute([':patient_id' => $patient_id]);
            $expiring_count = $expiring_stmt->fetchColumn();
        }
    } catch (PDOException $e) {
        error_log("Patient fetch error: " . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include('assets/inc/head.php'); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>KYC Documents | Patient Document Management</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        body { background: linear-gradient(145deg, #f0f4f8 0%, #e2e8f0 100%); }
        .content-page { margin-left: 260px; padding: 28px 32px; transition: all 0.3s ease; }
        body.erp-sidebar-collapsed .content-page { margin-left: 80px; }
        @media (max-width: 768px) { .content-page { margin-left: 0; padding: 20px; } }
        
        .card { border: 0; border-radius: 20px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); margin-bottom: 24px; overflow: hidden; }
        .card-header { background: #f8fafc; border-bottom: 1px solid #e2e8f0; padding: 16px 24px; font-weight: 700; }
        .card-body { padding: 24px; }
        
        .search-box { position: relative; }
        .search-box input { padding-left: 42px; border-radius: 12px; border: 1px solid #e2e8f0; }
        .search-box input:focus { border-color: #4361ee; box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.1); }
        .search-box i { position: absolute; left: 15px; top: 50%; transform: translateY(-50%); color: #94a3b8; }
        .search-results { position: absolute; z-index: 25; width: 100%; background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; margin-top: 8px; max-height: 320px; overflow-y: auto; box-shadow: 0 10px 25px -5px rgba(0,0,0,0.1); }
        .search-item { padding: 12px 16px; cursor: pointer; border-bottom: 1px solid #f1f5f9; transition: background 0.2s; }
        .search-item:hover { background: #eef2ff; }
        .search-item:last-child { border-bottom: none; }
        
        .kyc-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(350px, 1fr)); gap: 24px; }
        .doc-card { transition: transform 0.2s, box-shadow 0.2s; }
        .doc-card:hover { transform: translateY(-4px); box-shadow: 0 12px 30px rgba(0,0,0,0.12); }
        
        .doc-preview { 
            height: 200px; 
            border-radius: 12px; 
            background: #f8fafc; 
            border: 1px solid #e2e8f0; 
            display: flex; 
            align-items: center; 
            justify-content: center; 
            overflow: hidden; 
            cursor: pointer; 
            transition: all 0.2s;
            position: relative;
        }
        .doc-preview:hover { border-color: #4361ee; background: #eef2ff; }
        .doc-preview img { 
            width: 100%; 
            height: 100%; 
            object-fit: contain;
            background: #fff;
        }
        .doc-preview .preview-placeholder {
            text-align: center;
            padding: 20px;
        }
        .doc-preview .preview-placeholder i {
            font-size: 48px;
            color: #94a3b8;
            margin-bottom: 10px;
            display: block;
        }
        
        .badge-pending { background: #f59e0b; color: #fff; }
        .badge-verified { background: #10b981; color: #fff; }
        .badge-rejected { background: #ef4444; color: #fff; }
        .badge-expired { background: #6b7280; color: #fff; }
        
        .btn-primary { background: linear-gradient(135deg, #4361ee, #3a0ca3); border: none; border-radius: 12px; padding: 10px 24px; font-weight: 600; transition: all 0.2s; }
        .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(67, 97, 238, 0.3); }
        .btn-outline-primary:hover { background: #4361ee; color: white; }
        
        .form-control, .form-select { border-radius: 10px; border: 1px solid #e2e8f0; padding: 10px 14px; }
        .form-control:focus, .form-select:focus { border-color: #4361ee; box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.1); }
        
        hr { margin: 20px 0; border-color: #e2e8f0; }
        
        .document-filename {
            font-size: 12px;
            word-break: break-all;
            background: #f1f5f9;
            padding: 4px 8px;
            border-radius: 6px;
            display: inline-block;
            max-width: 100%;
        }
        
        .btn-outline-secondary {
            border: 1px solid #e2e8f0;
            background: white;
            padding: 8px 20px;
            border-radius: 12px;
            transition: all 0.2s;
        }
        .btn-outline-secondary:hover {
            background: #f8f9fa;
            border-color: #4361ee;
        }
        
        .btn-sm { padding: 5px 12px; font-size: 12px; }
        
        /* KYC Summary Styles */
        .kyc-summary-item {
            display: flex;
            align-items: center;
            padding: 8px 12px;
            border-radius: 8px;
            margin-bottom: 4px;
            background: #f8fafc;
            transition: background 0.2s;
        }
        .kyc-summary-item:hover { background: #f1f5f9; }
        .kyc-summary-item .status-icon { width: 24px; text-align: center; }
        .kyc-summary-item .status-icon.complete { color: #10b981; }
        .kyc-summary-item .status-icon.pending { color: #f59e0b; }
        .kyc-summary-item .status-icon.rejected { color: #ef4444; }
        .kyc-summary-item .status-icon.missing { color: #94a3b8; }
        
        .kyc-progress {
            height: 8px;
            border-radius: 4px;
            background: #e2e8f0;
            overflow: hidden;
        }
        .kyc-progress-bar {
            height: 100%;
            border-radius: 4px;
            background: linear-gradient(90deg, #10b981, #34d399);
            transition: width 0.6s ease;
        }
        
        /* Loading spinner */
        .spinner-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(255,255,255,0.8);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 9999;
        }
        .spinner-overlay.active { display: flex; }
    </style>
</head>
<body>
<div id="wrapper">
    <?php include('assets/inc/nav.php'); ?>
    <?php include('assets/inc/sidebar.php'); ?>

    <div class="content-page">
        <div class="container-fluid">
            <!-- Loading Spinner -->
            <div class="spinner-overlay" id="loadingSpinner">
                <div class="spinner-border text-primary" role="status" style="width: 3rem; height: 3rem;">
                    <span class="visually-hidden">Loading...</span>
                </div>
            </div>

            <div class="row mb-4">
                <div class="col-12">
                    <div class="d-flex justify-content-between align-items-center flex-wrap" style="gap: 10px;">
                        <div>
                            <h4 class="mb-1"><i class="fas fa-id-card text-primary me-2"></i> KYC Document Management</h4>
                            <p class="text-muted mb-0">Upload and verify patient KYC files (PDF, JPG, PNG)</p>
                        </div>
                        <a class="btn btn-outline-secondary" href="patient_directory.php">
                            <i class="fas fa-arrow-left me-1"></i> Back to Directory
                        </a>
                    </div>
                </div>
            </div>

            <!-- Patient Search Card -->
            <div class="card">
                <div class="card-body">
                    <label class="fw-bold mb-2"><i class="fas fa-search me-2 text-primary"></i> Search Patient</label>
                    <div class="search-box">
                        <i class="fas fa-search"></i>
                        <input type="text" id="patientSearchInput" class="form-control" autocomplete="off" placeholder="Search by name, MRN, National ID, Phone or Email...">
                        <div id="patientSearchResults" class="search-results" style="display:none;"></div>
                    </div>
                    <small class="text-muted d-block mt-2"><i class="fas fa-info-circle me-1"></i> Search for a patient to manage their KYC documents</small>
                </div>
            </div>

            <?php if ($patient): ?>
                <?php 
                    $full_name = trim(($patient['first_name'] ?? '') . ' ' . ($patient['middle_name'] ?? '') . ' ' . ($patient['last_name'] ?? ''));
                    if (empty($full_name)) $full_name = 'Unknown Patient';
                    
                    // Calculate age
                    $age = '';
                    if (!empty($patient['date_of_birth'])) {
                        $dob = new DateTime($patient['date_of_birth']);
                        $now = new DateTime();
                        $diff = $now->diff($dob);
                        $age = $diff->y . ' years';
                    }
                ?>
                <!-- Patient Info Card -->
                <div class="card">
                    <div class="card-body">
                        <div class="d-flex justify-content-between flex-wrap" style="gap: 12px;">
                            <div>
                                <h5 class="mb-1">
                                    <?php echo htmlspecialchars($full_name, ENT_QUOTES, 'UTF-8'); ?>
                                    <?php if ($kyc_complete): ?>
                                        <span class="badge bg-success text-white ms-2"><i class="fas fa-check-circle me-1"></i> KYC Complete</span>
                                    <?php else: ?>
                                        <span class="badge bg-warning text-white ms-2"><i class="fas fa-exclamation-triangle me-1"></i> KYC Incomplete</span>
                                    <?php endif; ?>
                                    <?php if (!$patient['is_active']): ?>
                                        <span class="badge bg-secondary text-white ms-2"><i class="fas fa-user-slash me-1"></i> Inactive</span>
                                    <?php endif; ?>
                                </h5>
                                <div class="text-muted small">
                                    <i class="fas fa-qrcode me-1"></i> MRN: <strong><?php echo htmlspecialchars($patient['mr_number'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?></strong>
                                    • <i class="fas fa-id-card me-1"></i> National ID: <?php echo htmlspecialchars($patient['national_id'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?>
                                    • <i class="fas fa-shield-alt me-1"></i> Insurance: <?php echo htmlspecialchars($patient['insurance_provider'] ?: 'N/A', ENT_QUOTES, 'UTF-8'); ?>
                                </div>
                                <div class="text-muted small">
                                    <i class="fas fa-phone me-1"></i> <?php echo htmlspecialchars($patient['phone'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?>
                                    • <i class="fas fa-envelope me-1"></i> <?php echo htmlspecialchars($patient['email'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?>
                                    <?php if ($age): ?>
                                        • <i class="fas fa-calendar-alt me-1"></i> <?php echo $age; ?>
                                    <?php endif; ?>
                                    <?php if ($patient['gender']): ?>
                                        • <i class="fas fa-<?php echo $patient['gender'] === 'male' ? 'mars' : ($patient['gender'] === 'female' ? 'venus' : 'genderless'); ?> me-1"></i> 
                                        <?php echo ucfirst($patient['gender']); ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="d-flex" style="gap: 8px;">
                                <a class="btn btn-outline-primary btn-sm" href="patient_history.php?id=<?php echo (int)$patient['id']; ?>">
                                    <i class="fas fa-user me-1"></i> Full Profile
                                </a>
                                <button class="btn btn-outline-info btn-sm" id="refreshKycStatus">
                                    <i class="fas fa-sync me-1"></i> Refresh Status
                                </button>
                            </div>
                        </div>
                        
                        <!-- KYC Progress -->
                        <div class="mt-3">
                            <div class="d-flex justify-content-between align-items-center">
                                <span class="small fw-bold">KYC Completion</span>
                                <span class="small">
                                    <?php 
                                        $total_mandatory = 0;
                                        $completed_mandatory = 0;
                                        foreach ($kyc_summary as $item) {
                                            if ($item['is_mandatory']) {
                                                $total_mandatory++;
                                                if ($item['verified_count'] > 0) $completed_mandatory++;
                                            }
                                        }
                                        $progress_percent = $total_mandatory > 0 ? round(($completed_mandatory / $total_mandatory) * 100) : 0;
                                    ?>
                                    <?php echo $completed_mandatory; ?>/<?php echo $total_mandatory; ?> mandatory documents
                                </span>
                            </div>
                            <div class="kyc-progress">
                                <div class="kyc-progress-bar" style="width: <?php echo $progress_percent; ?>%;"></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- KYC Summary Card -->
                <div class="card">
                    <div class="card-header">
                        <i class="fas fa-list-check me-2 text-primary"></i> KYC Requirements Summary
                        <span class="ms-2 badge <?php echo $kyc_complete ? 'bg-success' : 'bg-warning'; ?>">
                            <?php echo $kyc_complete ? 'COMPLETE' : 'INCOMPLETE'; ?>
                        </span>
                    </div>
                    <div class="card-body">
                        <div class="row g-2">
                            <?php foreach ($kyc_summary as $item): ?>
                                <?php
                                    $is_complete = $item['verified_count'] > 0;
                                    $status_class = $is_complete ? 'complete' : ($item['document_count'] > 0 ? 'pending' : 'missing');
                                    $status_icon = $is_complete ? 'fa-check-circle' : ($item['document_count'] > 0 ? 'fa-clock' : 'fa-times-circle');
                                    $status_text = $is_complete ? 'Verified' : ($item['document_count'] > 0 ? 'Pending' : 'Not Uploaded');
                                ?>
                                <div class="col-md-4 col-lg-3">
                                    <div class="kyc-summary-item">
                                        <span class="status-icon <?php echo $status_class; ?>">
                                            <i class="fas <?php echo $status_icon; ?>"></i>
                                        </span>
                                        <div class="ms-2 flex-grow-1">
                                            <div class="small fw-bold"><?php echo htmlspecialchars($item['type_name'], ENT_QUOTES, 'UTF-8'); ?></div>
                                            <div class="small text-muted">
                                                <?php echo $status_text; ?>
                                                <?php if ($item['is_mandatory']): ?>
                                                    <span class="text-danger">*</span>
                                                <?php endif; ?>
                                                <?php if ($item['requires_expiry'] && $is_complete): ?>
                                                    <i class="fas fa-calendar-alt ms-1 text-muted" title="Requires expiry date"></i>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            <div class="col-12 text-muted small mt-2">
                                <i class="fas fa-asterisk text-danger me-1"></i> Mandatory documents
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Upload Form Card -->
                <?php if ($can_manage_kyc): ?>
                <div class="card">
                    <div class="card-header">
                        <i class="fas fa-upload me-2 text-primary"></i> Upload New Document
                    </div>
                    <div class="card-body">
                        <form id="kycUploadForm" enctype="multipart/form-data" method="POST" action="">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="patient_id" value="<?php echo (int)$patient['id']; ?>">
                            <div class="row g-3">
                                <div class="col-md-4">
                                    <label class="form-label">Document Type *</label>
                                    <select name="document_type_id" class="form-select" required id="documentTypeSelect">
                                        <option value="">Select Document Type</option>
                                        <?php foreach ($document_types as $type): ?>
                                            <option value="<?php echo $type['id']; ?>" 
                                                    data-requires-expiry="<?php echo $type['requires_expiry']; ?>"
                                                    data-mandatory="<?php echo $type['is_mandatory']; ?>">
                                                <?php echo htmlspecialchars($type['name'], ENT_QUOTES, 'UTF-8'); ?>
                                                <?php echo $type['is_mandatory'] ? ' (Required)' : ''; ?>
                                                <?php echo $type['requires_expiry'] ? ' [Has Expiry]' : ''; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Document Name</label>
                                    <input type="text" name="document_name" class="form-control" placeholder="e.g., Front Copy">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Document Number</label>
                                    <input type="text" name="document_number" class="form-control" placeholder="Optional ID/Policy #">
                                </div>
                            </div>
                            <div class="row g-3 mt-1">
                                <div class="col-md-4">
                                    <label class="form-label">File *</label>
                                    <input type="file" name="document_file" class="form-control" accept=".pdf,.jpg,.jpeg,.png" required>
                                    <small class="text-muted">Accepted: PDF, JPG, JPEG, PNG (Max 10MB)</small>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Expiry Date</label>
                                    <input type="date" name="expiry_date" class="form-control" id="expiryDateInput">
                                    <small class="text-muted" id="expiryDateHelp">Required for documents with expiry</small>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Verification Notes</label>
                                    <textarea name="verification_notes" class="form-control" rows="2" placeholder="Additional notes or description"></textarea>
                                </div>
                            </div>
                            <div class="mt-4">
                                <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i> Upload Document</button>
                                <button type="reset" class="btn btn-outline-secondary ms-2"><i class="fas fa-undo me-1"></i> Reset</button>
                            </div>
                        </form>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Documents List Card -->
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center flex-wrap" style="gap: 8px;">
                        <span><i class="fas fa-folder-open me-2 text-primary"></i> Uploaded Documents</span>
                        <span class="text-muted small"><?php echo count($documents); ?> document(s) 
                            <?php if ($expiring_count > 0): ?>
                                <span class="badge bg-warning text-white ms-2"><i class="fas fa-clock me-1"></i> <?php echo $expiring_count; ?> expiring soon</span>
                            <?php endif; ?>
                        </span>
                    </div>
                    <div class="card-body">
                        <?php if (empty($documents)): ?>
                            <div class="text-center text-muted py-5">
                                <i class="fas fa-folder-open fa-3x mb-3 opacity-25"></i>
                                <p>No KYC documents uploaded yet.</p>
                                <?php if ($can_manage_kyc): ?>
                                    <small>Use the form above to upload documents.</small>
                                <?php else: ?>
                                    <small>You do not have permission to upload documents.</small>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <div class="kyc-grid">
                                <?php foreach ($documents as $doc): ?>
                                    <?php
                                        $doc_id = (int)$doc['id'];
                                        $ext = strtolower(pathinfo((string)$doc['original_filename'], PATHINFO_EXTENSION));
                                        $is_image = in_array($ext, ['jpg', 'jpeg', 'png'], true);
                                        $is_pdf = $ext === 'pdf';
                                        $preview_url = 'kyc_documents.php?preview_id=' . $doc_id . '&csrf_token=' . urlencode($csrf_token);
                                        
                                        $status_text = ucfirst(str_replace('_', ' ', $doc['status'] ?? 'pending'));
                                        $status_class = match($doc['status'] ?? '') {
                                            'verified' => 'badge-verified',
                                            'rejected' => 'badge-rejected',
                                            'expired' => 'badge-expired',
                                            default => 'badge-pending'
                                        };
                                        
                                        $doc_type_name = $doc['document_type_name'] ?? 'Document';
                                        $doc_name = !empty($doc['document_name']) ? $doc['document_name'] : $doc_type_name;
                                        
                                        $is_expired = ($doc['status'] === 'verified' && !empty($doc['expiry_date']) && $doc['expiry_date'] !== '0000-00-00' && strtotime($doc['expiry_date']) < time());
                                        if ($is_expired && $doc['status'] !== 'expired') {
                                            // Status should be updated to expired, but we'll display it
                                            $status_text = 'Expired';
                                            $status_class = 'badge-expired';
                                        }
                                        
                                        $verified_by = '';
                                        if ($doc['verified_by_first_name']) {
                                            $verified_by = trim($doc['verified_by_first_name'] . ' ' . ($doc['verified_by_last_name'] ?? ''));
                                        }
                                        
                                        $uploaded_by = '';
                                        if ($doc['uploaded_by_first_name']) {
                                            $uploaded_by = trim($doc['uploaded_by_first_name'] . ' ' . ($doc['uploaded_by_last_name'] ?? ''));
                                        }
                                    ?>
                                    <div class="doc-card card">
                                        <div class="card-body p-3">
                                            <div class="doc-preview js-doc-preview" 
                                                 data-url="<?php echo htmlspecialchars($preview_url, ENT_QUOTES, 'UTF-8'); ?>" 
                                                 data-type="<?php echo $is_image ? 'image' : ($is_pdf ? 'pdf' : 'file'); ?>">
                                                <?php if ($is_image): ?>
                                                    <img src="<?php echo htmlspecialchars($preview_url, ENT_QUOTES, 'UTF-8'); ?>" alt="Document preview" onerror="this.parentElement.innerHTML='<div class=\'preview-placeholder\'><i class=\'fas fa-image\'></i><div class=\'small\'>Preview not available</div></div>'">
                                                <?php elseif ($is_pdf): ?>
                                                    <div class="preview-placeholder">
                                                        <i class="fas fa-file-pdf" style="font-size: 48px; color: #ef4444;"></i>
                                                        <div class="small text-muted mt-2">Click to preview PDF</div>
                                                    </div>
                                                <?php else: ?>
                                                    <div class="preview-placeholder">
                                                        <i class="fas fa-file-alt" style="font-size: 48px; color: #6b7280;"></i>
                                                        <div class="small text-muted mt-2"><?php echo strtoupper($ext ?: 'FILE'); ?> Document</div>
                                                    </div>
                                                <?php endif; ?>
                                                
                                                <?php if ($doc['is_mandatory']): ?>
                                                    <span class="position-absolute top-0 start-0 badge bg-danger m-2">Required</span>
                                                <?php endif; ?>
                                                <?php if ($is_expired): ?>
                                                    <span class="position-absolute top-0 end-0 badge bg-secondary m-2">Expired</span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="mt-3">
                                                <div class="fw-bold"><?php echo htmlspecialchars($doc_name, ENT_QUOTES, 'UTF-8'); ?></div>
                                                <div class="text-muted small mt-1">
                                                    <span class="document-filename"><i class="far fa-file me-1"></i> <?php echo htmlspecialchars($doc['original_filename'] ?? 'Unknown', ENT_QUOTES, 'UTF-8'); ?></span>
                                                </div>
                                                <div class="text-muted small">
                                                    <span class="badge <?php echo $status_class; ?>"><?php echo htmlspecialchars($status_text, ENT_QUOTES, 'UTF-8'); ?></span>
                                                    <?php if ($doc['document_number']): ?>
                                                        <span class="ms-2"><i class="fas fa-hashtag me-1"></i> <?php echo htmlspecialchars($doc['document_number'], ENT_QUOTES, 'UTF-8'); ?></span>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="text-muted small mt-1">
                                                    <i class="far fa-calendar-alt me-1"></i> Uploaded: <?php echo htmlspecialchars(date('d M Y H:i', strtotime($doc['created_at'])), ENT_QUOTES, 'UTF-8'); ?>
                                                    <?php if ($uploaded_by): ?>
                                                        by <?php echo htmlspecialchars($uploaded_by, ENT_QUOTES, 'UTF-8'); ?>
                                                    <?php endif; ?>
                                                </div>
                                                <?php if ($doc['verified_at']): ?>
                                                    <div class="text-muted small">
                                                        <i class="fas fa-check-circle text-success me-1"></i> Verified: <?php echo htmlspecialchars(date('d M Y', strtotime($doc['verified_at'])), ENT_QUOTES, 'UTF-8'); ?>
                                                        <?php if ($verified_by): ?>
                                                            by <?php echo htmlspecialchars($verified_by, ENT_QUOTES, 'UTF-8'); ?>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endif; ?>
                                                <?php if (!empty($doc['expiry_date']) && $doc['expiry_date'] !== '0000-00-00'): ?>
                                                    <div class="small mt-1 <?php echo $is_expired ? 'text-danger fw-bold' : 'text-muted'; ?>">
                                                        <i class="far fa-hourglass-half me-1"></i> Expires: <?php echo htmlspecialchars(date('d M Y', strtotime($doc['expiry_date'])), ENT_QUOTES, 'UTF-8'); ?>
                                                        <?php if (!$is_expired && strtotime($doc['expiry_date']) < strtotime('+30 days')): ?>
                                                            <span class="badge bg-warning text-dark ms-1">Expiring Soon</span>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endif; ?>
                                                <?php if (!empty($doc['verification_notes'])): ?>
                                                    <div class="small text-muted mt-1"><i class="fas fa-info-circle me-1"></i> <?php echo htmlspecialchars(substr($doc['verification_notes'], 0, 80), ENT_QUOTES, 'UTF-8'); ?></div>
                                                <?php endif; ?>
                                                <?php if (!empty($doc['file_size'])): ?>
                                                    <div class="small text-muted mt-1">
                                                        <i class="fas fa-weight-hanging me-1"></i> <?php echo round($doc['file_size'] / 1024, 1); ?> KB
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                            <?php if ($can_manage_kyc): ?>
                                            <hr>
                                            <form class="doc-status-form">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                                                <input type="hidden" name="update_status" value="1">
                                                <input type="hidden" name="doc_id" value="<?php echo $doc_id; ?>">
                                                <input type="hidden" name="patient_id" value="<?php echo (int)$patient['id']; ?>">
                                                <div class="row g-2">
                                                    <div class="col-6">
                                                        <label class="small mb-1">Status</label>
                                                        <select name="status" class="form-select form-select-sm">
                                                            <option value="pending" <?php echo $doc['status'] === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                                            <option value="verified" <?php echo $doc['status'] === 'verified' ? 'selected' : ''; ?>>Verified</option>
                                                            <option value="rejected" <?php echo $doc['status'] === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                                                        </select>
                                                    </div>
                                                    <div class="col-6">
                                                        <label class="small mb-1">Expiry Date</label>
                                                        <input type="date" name="expiry_date" class="form-control form-control-sm" value="<?php echo htmlspecialchars($doc['expiry_date'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" <?php echo $doc['requires_expiry'] ? 'required' : ''; ?>>
                                                    </div>
                                                </div>
                                                <div class="mt-2">
                                                    <label class="small mb-1">Verification Notes</label>
                                                    <textarea name="verification_notes" class="form-control form-control-sm" rows="2" placeholder="Add verification notes..."><?php echo htmlspecialchars($doc['verification_notes'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
                                                </div>
                                                <div class="mt-3 d-flex gap-2">
                                                    <button type="submit" class="btn btn-sm btn-outline-primary flex-grow-1"><i class="fas fa-save me-1"></i> Update</button>
                                                    <button type="button" class="btn btn-sm btn-outline-danger delete-doc" data-id="<?php echo $doc_id; ?>"><i class="fas fa-trash me-1"></i> Delete</button>
                                                </div>
                                            </form>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Bulk Actions -->
                <?php if ($can_manage_kyc && !empty($documents)): ?>
                <div class="card">
                    <div class="card-header">
                        <i class="fas fa-tasks me-2 text-primary"></i> Bulk Actions
                    </div>
                    <div class="card-body">
                        <div class="row g-3 align-items-end">
                            <div class="col-md-6">
                                <label class="form-label">Select Documents to Verify</label>
                                <div class="d-flex flex-wrap gap-2">
                                    <button class="btn btn-sm btn-outline-secondary select-all-docs">Select All Pending</button>
                                    <button class="btn btn-sm btn-outline-secondary deselect-all-docs">Deselect All</button>
                                </div>
                                <div class="mt-2" id="bulkDocList">
                                    <?php foreach ($documents as $doc): ?>
                                        <?php if ($doc['status'] === 'pending'): ?>
                                            <div class="form-check form-check-inline">
                                                <input class="form-check-input bulk-doc-check" type="checkbox" name="bulk_doc_ids[]" value="<?php echo $doc['id']; ?>" id="bulk_<?php echo $doc['id']; ?>">
                                                <label class="form-check-label small" for="bulk_<?php echo $doc['id']; ?>">
                                                    <?php echo htmlspecialchars(substr($doc['document_name'] ?? $doc['document_type_name'] ?? 'Doc', 0, 20), ENT_QUOTES, 'UTF-8'); ?>
                                                </label>
                                            </div>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <button class="btn btn-success bulk-verify-btn" data-patient-id="<?php echo (int)$patient['id']; ?>">
                                    <i class="fas fa-check-double me-1"></i> Bulk Verify Selected
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            <?php else: ?>
                <div class="card">
                    <div class="card-body text-center text-muted py-5">
                        <i class="fas fa-user-search fa-3x mb-3 opacity-25"></i>
                        <p>Select a patient from the search above to manage KYC documents.</p>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        <?php include('assets/inc/footer.php'); ?>
    </div>
</div>

<script src="assets/js/vendor.min.js"></script>
<script src="assets/js/app.min.js"></script>
<script>
const csrfToken = <?php echo json_encode($csrf_token); ?>;
const canManageKyc = <?php echo $can_manage_kyc ? 'true' : 'false'; ?>;

(function() {
    const patientInput = document.getElementById('patientSearchInput');
    const patientResults = document.getElementById('patientSearchResults');
    const loadingSpinner = document.getElementById('loadingSpinner');
    let debounceTimer = null;

    function escapeHtml(str) {
        if (!str) return '';
        return String(str).replace(/[&<>]/g, function(m) {
            if (m === '&') return '&amp;';
            if (m === '<') return '&lt;';
            if (m === '>') return '&gt;';
            return m;
        });
    }

    function renderPatientResults(items) {
        if (!items || !items.length) {
            patientResults.innerHTML = '<div class="search-item text-muted">No patient found.</div>';
            patientResults.style.display = 'block';
            return;
        }
        patientResults.innerHTML = items.map(function(p) {
            var title = escapeHtml(p.name) + ' (' + escapeHtml(p.mr_number || 'No MRN') + ')';
            var sub = 'National ID: ' + escapeHtml(p.national_id || 'N/A') + ' | Insurance: ' + escapeHtml(p.insurance_provider || 'N/A');
            if (p.phone) sub += ' | Phone: ' + escapeHtml(p.phone);
            if (p.dob) sub += ' | DOB: ' + escapeHtml(p.dob);
            return '<div class="search-item" data-id="' + p.id + '"><div class="fw-bold">' + title + '</div><div class="small text-muted">' + sub + '</div></div>';
        }).join('');
        patientResults.style.display = 'block';
    }

    if (patientInput) {
        patientInput.addEventListener('input', function() {
            var q = patientInput.value.trim();
            clearTimeout(debounceTimer);
            if (!q) {
                patientResults.style.display = 'none';
                return;
            }
            debounceTimer = setTimeout(function() {
                loadingSpinner.classList.add('active');
                fetch('kyc_documents.php?ajax=patient_search&q=' + encodeURIComponent(q))
                    .then(function(r) { return r.json(); })
                    .then(function(res) { 
                        renderPatientResults(res.items || []); 
                        loadingSpinner.classList.remove('active');
                    })
                    .catch(function() {
                        patientResults.innerHTML = '<div class="search-item text-danger">Search failed. Please try again.</div>';
                        patientResults.style.display = 'block';
                        loadingSpinner.classList.remove('active');
                    });
            }, 300);
        });

        patientResults.addEventListener('click', function(e) {
            var item = e.target.closest('.search-item[data-id]');
            if (!item) return;
            window.location.href = 'kyc_documents.php?patient_id=' + item.getAttribute('data-id');
        });

        document.addEventListener('click', function(e) {
            if (patientResults && !patientResults.contains(e.target) && e.target !== patientInput) {
                patientResults.style.display = 'none';
            }
        });
    }

    // Document Type selection - show expiry date hint
    const docTypeSelect = document.getElementById('documentTypeSelect');
    const expiryDateInput = document.getElementById('expiryDateInput');
    const expiryDateHelp = document.getElementById('expiryDateHelp');
    
    if (docTypeSelect && expiryDateInput) {
        docTypeSelect.addEventListener('change', function() {
            var selected = this.options[this.selectedIndex];
            var requiresExpiry = selected.getAttribute('data-requires-expiry') === '1';
            if (requiresExpiry) {
                expiryDateInput.setAttribute('required', 'required');
                expiryDateInput.style.borderColor = '#dc3545';
                expiryDateHelp.innerHTML = '<span class="text-danger">* Required for this document type</span>';
            } else {
                expiryDateInput.removeAttribute('required');
                expiryDateInput.style.borderColor = '';
                expiryDateHelp.innerHTML = 'Optional - only required for documents with expiry';
            }
        });
    }

    // Upload Form Handler
    const uploadForm = document.getElementById('kycUploadForm');
    if (uploadForm) {
        uploadForm.addEventListener('submit', function(e) {
            e.preventDefault();
            var formData = new FormData(uploadForm);

            Swal.fire({
                title: 'Uploading...',
                text: 'Please wait while the document is uploaded.',
                allowOutsideClick: false,
                didOpen: function() { Swal.showLoading(); }
            });

            fetch(window.location.pathname, { method: 'POST', body: formData })
                .then(function(r) { return r.json(); })
                .then(function(res) {
                    if (!res.success) throw new Error(res.message || 'Upload failed');
                    Swal.fire('Success', res.message || 'Document uploaded successfully', 'success')
                        .then(function() { window.location.reload(); });
                })
                .catch(function(err) {
                    Swal.fire('Error', err.message, 'error');
                });
        });
    }

    // Document Preview
    document.querySelectorAll('.js-doc-preview').forEach(function(el) {
        el.addEventListener('click', function() {
            var url = el.getAttribute('data-url');
            var type = el.getAttribute('data-type');
            
            if (type === 'image') {
                Swal.fire({
                    title: 'Document Preview',
                    imageUrl: url,
                    imageAlt: 'Document Preview',
                    width: 800,
                    showCloseButton: true,
                    showConfirmButton: false
                });
            } else if (type === 'pdf') {
                Swal.fire({
                    title: 'PDF Document',
                    html: '<iframe src="' + url + '" style="width:100%;height:70vh;border:0;" title="PDF Preview"></iframe>',
                    width: 900,
                    height: '80vh',
                    showCloseButton: true,
                    showConfirmButton: false
                });
            } else {
                Swal.fire({
                    title: 'Document',
                    text: 'This file type cannot be previewed directly.',
                    icon: 'info',
                    showCancelButton: true,
                    confirmButtonText: 'Download',
                    cancelButtonText: 'Close'
                }).then(function(result) {
                    if (result.isConfirmed) {
                        window.open(url, '_blank');
                    }
                });
            }
        });
    });

    // Update Status Form
    document.querySelectorAll('.doc-status-form').forEach(function(form) {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            var formData = new FormData(form);
            
            Swal.fire({
                title: 'Updating...',
                allowOutsideClick: false,
                didOpen: function() { Swal.showLoading(); }
            });

            fetch(window.location.pathname, { method: 'POST', body: formData })
                .then(function(r) { return r.json(); })
                .then(function(res) {
                    if (!res.success) throw new Error(res.message || 'Update failed');
                    var msg = res.message || 'Document status updated';
                    if (res.kyc_complete) {
                        msg += ' 🎉 KYC is now complete!';
                    }
                    Swal.fire('Saved', msg, 'success')
                        .then(function() { window.location.reload(); });
                })
                .catch(function(err) {
                    Swal.fire('Error', err.message, 'error');
                });
        });
    });

    // Delete Document
    document.querySelectorAll('.delete-doc').forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            var docId = btn.getAttribute('data-id');
            
            Swal.fire({
                title: 'Delete Document?',
                text: 'This action cannot be undone.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                confirmButtonText: 'Yes, delete',
                cancelButtonText: 'Cancel'
            }).then(function(result) {
                if (result.isConfirmed) {
                    var formData = new FormData();
                    formData.append('csrf_token', csrfToken);
                    formData.append('delete_document', '1');
                    formData.append('doc_id', docId);
                    
                    fetch(window.location.pathname, { method: 'POST', body: formData })
                        .then(function(r) { return r.json(); })
                        .then(function(res) {
                            if (!res.success) throw new Error(res.message || 'Delete failed');
                            Swal.fire('Deleted', res.message || 'Document deleted successfully', 'success')
                                .then(function() { window.location.reload(); });
                        })
                        .catch(function(err) {
                            Swal.fire('Error', err.message, 'error');
                        });
                }
            });
        });
    });

    // Bulk Verify
    const bulkVerifyBtn = document.querySelector('.bulk-verify-btn');
    if (bulkVerifyBtn) {
        bulkVerifyBtn.addEventListener('click', function() {
            var checked = document.querySelectorAll('.bulk-doc-check:checked');
            if (checked.length === 0) {
                Swal.fire('Info', 'Please select at least one document to verify.', 'info');
                return;
            }
            
            var docIds = Array.from(checked).map(function(cb) { return cb.value; });
            var patientId = this.getAttribute('data-patient-id');
            
            Swal.fire({
                title: 'Verify Documents?',
                text: 'You are about to verify ' + docIds.length + ' document(s).',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#10b981',
                confirmButtonText: 'Yes, verify all',
                cancelButtonText: 'Cancel'
            }).then(function(result) {
                if (result.isConfirmed) {
                    var formData = new FormData();
                    formData.append('csrf_token', csrfToken);
                    formData.append('bulk_verify', '1');
                    formData.append('patient_id', patientId);
                    docIds.forEach(function(id) {
                        formData.append('doc_ids[]', id);
                    });
                    
                    loadingSpinner.classList.add('active');
                    fetch(window.location.pathname, { method: 'POST', body: formData })
                        .then(function(r) { return r.json(); })
                        .then(function(res) {
                            loadingSpinner.classList.remove('active');
                            if (!res.success) throw new Error(res.message || 'Verification failed');
                            Swal.fire('Success', res.message, 'success')
                                .then(function() { window.location.reload(); });
                        })
                        .catch(function(err) {
                            loadingSpinner.classList.remove('active');
                            Swal.fire('Error', err.message, 'error');
                        });
                }
            });
        });
    }

    // Select All Pending
    const selectAllBtn = document.querySelector('.select-all-docs');
    if (selectAllBtn) {
        selectAllBtn.addEventListener('click', function() {
            document.querySelectorAll('.bulk-doc-check').forEach(function(cb) {
                cb.checked = true;
            });
        });
    }

    // Deselect All
    const deselectAllBtn = document.querySelector('.deselect-all-docs');
    if (deselectAllBtn) {
        deselectAllBtn.addEventListener('click', function() {
            document.querySelectorAll('.bulk-doc-check').forEach(function(cb) {
                cb.checked = false;
            });
        });
    }

    // Refresh KYC Status
    const refreshBtn = document.getElementById('refreshKycStatus');
    if (refreshBtn) {
        refreshBtn.addEventListener('click', function() {
            var patientId = <?php echo $patient_id ?: 0; ?>;
            if (!patientId) return;
            
            loadingSpinner.classList.add('active');
            fetch('kyc_documents.php?ajax=kyc_status&patient_id=' + patientId)
                .then(function(r) { return r.json(); })
                .then(function(res) {
                    loadingSpinner.classList.remove('active');
                    if (res.success && res.data) {
                        Swal.fire({
                            title: 'KYC Status',
                            html: '<div class="text-start">' +
                                '<p><strong>Total Documents:</strong> ' + res.data.total_documents + '</p>' +
                                '<p><strong>Verified:</strong> ' + res.data.verified_count + '</p>' +
                                '<p><strong>Pending:</strong> ' + res.data.pending_count + '</p>' +
                                '<p><strong>Rejected:</strong> ' + res.data.rejected_count + '</p>' +
                                '<p><strong>Expired:</strong> ' + res.data.expired_count + '</p>' +
                                '<p><strong>Mandatory Verified:</strong> ' + res.data.mandatory_verified + '/' + res.data.mandatory_total + '</p>' +
                                '<hr>' +
                                '<p class="fw-bold ' + (res.data.is_kyc_complete ? 'text-success' : 'text-warning') + '">' +
                                'KYC Status: ' + (res.data.is_kyc_complete ? '✅ COMPLETE' : '⏳ INCOMPLETE') +
                                '</p>' +
                                '</div>',
                            icon: res.data.is_kyc_complete ? 'success' : 'info',
                            confirmButtonText: 'OK'
                        }).then(function() {
                            window.location.reload();
                        });
                    } else {
                        Swal.fire('Error', res.message || 'Failed to fetch status', 'error');
                    }
                })
                .catch(function(err) {
                    loadingSpinner.classList.remove('active');
                    Swal.fire('Error', err.message, 'error');
                });
        });
    }

    // Auto-refresh KYC status every 30 seconds (optional)
    // setInterval(function() { if (document.getElementById('refreshKycStatus')) { document.getElementById('refreshKycStatus').click(); } }, 30000);

})();
</script>
</body>
</html>