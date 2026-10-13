<?php
/**
 * Patient Directory - HMS ERP System
 * Enhanced: Complete integration with all database tables
 * FIXED: Search functionality, Quick View, and all missing features
 * 
 * @package HMS-ERP
 * @version 5.0
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

// Error reporting for production
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

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
    die('Database connection failed. Please contact system administrator.');
}

// Permission checks - ENHANCED with granular permissions
$can_view_patients = $is_global_admin;
$can_manage_patients = $is_global_admin;
$can_edit_patient = $is_global_admin;
$can_view_insurance = $is_global_admin;
$can_manage_insurance = $is_global_admin;
$can_view_kyc = $is_global_admin;
$can_manage_kyc = $is_global_admin;
$can_manage_family = $is_global_admin;
$can_view_financial = $is_global_admin;
$can_view_medical_records = $is_global_admin;

if (!$is_global_admin && $user_id > 0) {
    $can_view_patients = user_has_permission($pdo, 'patient_view_access', $user_id);
    $can_manage_patients = user_has_permission($pdo, 'patient_manage_access', $user_id);
    $can_edit_patient = user_has_permission($pdo, 'patient_edit_access', $user_id);
    $can_view_insurance = user_has_permission($pdo, 'insurance_view_access', $user_id);
    $can_manage_insurance = user_has_permission($pdo, 'insurance_manage_access', $user_id);
    $can_view_kyc = user_has_permission($pdo, 'view_patient_kyc', $user_id);
    $can_manage_kyc = user_has_permission($pdo, 'patient_kyc_manage_access', $user_id);
    $can_manage_family = user_has_permission($pdo, 'patient_family_manage_access', $user_id);
    $can_view_financial = user_has_permission($pdo, 'view_billing_info', $user_id);
    $can_view_medical_records = user_has_permission($pdo, 'view_patient_history', $user_id);
}

// If user has no permission to view patients, redirect
if (!$can_view_patients && !$can_manage_patients) {
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
    if (ob_get_length()) ob_clean();
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// Helper function for XSS prevention
function escape_html($string) {
    if ($string === null) return '';
    return htmlspecialchars((string)$string, ENT_QUOTES, 'UTF-8');
}

// Helper function to apply branch isolation
function apply_branch_filter(&$sql, &$params, $branch_id, $is_global_admin, $table_alias = '') {
    if (!$is_global_admin && $branch_id > 0) {
        $prefix = $table_alias ? $table_alias . '.' : '';
        $sql .= " AND {$prefix}branch_id = :branch_id";
        $params[':branch_id'] = $branch_id;
    }
}

// Helper to get status badge
function getStatusBadge($status, $type = 'patient') {
    $badges = [
        'patient' => [
            'active' => 'status-active',
            'inactive' => 'status-inactive'
        ],
        'insurance' => [
            'active' => 'status-insured',
            'expired' => 'status-expired',
            'suspended' => 'status-expiring'
        ],
        'payment' => [
            'paid' => 'badge-success',
            'partial' => 'badge-warning',
            'unpaid' => 'badge-danger'
        ]
    ];
    
    $class = $badges[$type][$status] ?? 'badge-secondary';
    return '<span class="status-badge ' . $class . '">' . ucfirst($status) . '</span>';
}

// Get branch name for display
$branch_name = 'Main Branch';
if ($user_branch_id > 0) {
    try {
        $stmt = $pdo->prepare("SELECT name FROM branches WHERE id = :id AND deleted_at IS NULL");
        $stmt->execute([':id' => $user_branch_id]);
        $br = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($br && !empty($br['name'])) {
            $branch_name = $br['name'];
        }
    } catch (PDOException $e) {
        error_log("Branch name query failed: " . $e->getMessage());
    }
}

// ============================================
// AJAX: Get Summary Stats (Secured)
// ============================================
if (isset($_GET['get_summary_stats'])) {
    if (!$db_available) {
        json_exit(['success' => false, 'message' => 'Database unavailable']);
    }
    
    try {
        // Total patients with branch isolation
        $total_sql = "SELECT COUNT(*) FROM patients WHERE deleted_at IS NULL";
        $total_params = [];
        apply_branch_filter($total_sql, $total_params, $user_branch_id, $is_global_admin, '');
        $stmt = $pdo->prepare($total_sql);
        $stmt->execute($total_params);
        $total = (int)$stmt->fetchColumn();
        
        // Active patients
        $active_sql = "SELECT COUNT(*) FROM patients WHERE deleted_at IS NULL AND is_active = 1";
        $active_params = [];
        apply_branch_filter($active_sql, $active_params, $user_branch_id, $is_global_admin, '');
        $stmt = $pdo->prepare($active_sql);
        $stmt->execute($active_params);
        $active = (int)$stmt->fetchColumn();
        
        $inactive = $total - $active;
        
        // Admitted patients
        $admitted_sql = "SELECT COUNT(DISTINCT p.id) FROM patients p 
                         INNER JOIN admissions a ON a.patient_id = p.id 
                         WHERE p.deleted_at IS NULL 
                         AND a.status = 'admitted' AND a.deleted_at IS NULL";
        $admitted_params = [];
        apply_branch_filter($admitted_sql, $admitted_params, $user_branch_id, $is_global_admin, 'p');
        $stmt = $pdo->prepare($admitted_sql);
        $stmt->execute($admitted_params);
        $admitted = (int)$stmt->fetchColumn();
        
        // Patients with insurance
        $insured_sql = "SELECT COUNT(DISTINCT p.id) FROM patients p
                        INNER JOIN patient_insurance_profiles pip ON p.id = pip.patient_id
                        WHERE p.deleted_at IS NULL AND pip.deleted_at IS NULL
                        AND pip.status = 'active' AND pip.effective_to >= CURDATE()";
        $insured_params = [];
        apply_branch_filter($insured_sql, $insured_params, $user_branch_id, $is_global_admin, 'p');
        $stmt = $pdo->prepare($insured_sql);
        $stmt->execute($insured_params);
        $insured = (int)$stmt->fetchColumn();
        
        json_exit([
            'success' => true, 
            'total' => $total, 
            'active' => $active, 
            'inactive' => $inactive, 
            'admitted' => $admitted,
            'insured' => $insured
        ]);
        
    } catch (PDOException $e) {
        error_log("Stats query failed: " . $e->getMessage());
        json_exit(['success' => false, 'message' => 'Failed to load statistics']);
    }
}

// ============================================
// AJAX: Get Patient Details for Modal (FIXED)
// ============================================
if (isset($_GET['get_patient_details'])) {
    if (!$db_available) {
        json_exit(['success' => false, 'message' => 'Database unavailable']);
    }
    
    $patient_id = (int)($_GET['patient_id'] ?? 0);
    if ($patient_id <= 0) {
        json_exit(['success' => false, 'message' => 'Invalid patient ID']);
    }
    
    try {
        // Branch isolation check
        if (!$is_global_admin && $user_branch_id > 0) {
            $chk = $pdo->prepare("SELECT id FROM patients WHERE id = :id AND branch_id = :branch_id AND deleted_at IS NULL LIMIT 1");
            $chk->execute([':id' => $patient_id, ':branch_id' => $user_branch_id]);
            if (!$chk->fetch()) {
                json_exit(['success' => false, 'message' => 'Access denied']);
            }
        }
        
        // Get patient with all related data
        $sql = "SELECT 
                    p.*,
                    b.name AS branch_name,
                    pip.id AS insurance_profile_id,
                    pip.insurance_provider_id,
                    ip.name AS insurance_provider_name,
                    pip.policy_number,
                    pip.member_id,
                    pip.group_number,
                    pip.coverage_percentage,
                    pip.co_pay_percentage,
                    pip.deductible_amount,
                    pip.effective_from AS insurance_valid_from,
                    pip.effective_to AS insurance_valid_to,
                    pip.status AS insurance_status,
                    (SELECT COUNT(*) FROM patient_relationships 
                     WHERE (primary_patient_id = p.id OR related_patient_id = p.id) 
                     AND deleted_at IS NULL) AS family_count,
                    (SELECT COUNT(*) FROM patient_visits 
                     WHERE patient_id = p.id AND deleted_at IS NULL) AS visit_count,
                    (SELECT COUNT(*) FROM consultations 
                     WHERE patient_id = p.id AND deleted_at IS NULL) AS consultation_count,
                    (SELECT COUNT(*) FROM lab_orders 
                     WHERE patient_id = p.id AND deleted_at IS NULL) AS lab_count,
                    (SELECT COUNT(*) FROM prescriptions 
                     WHERE patient_id = p.id AND deleted_at IS NULL) AS prescription_count,
                    (SELECT COUNT(*) FROM invoices 
                     WHERE patient_id = p.id AND deleted_at IS NULL 
                     AND payment_status IN ('unpaid', 'partial')) AS pending_invoices,
                    (SELECT COALESCE(SUM(due_amount), 0) FROM invoices 
                     WHERE patient_id = p.id AND deleted_at IS NULL 
                     AND payment_status IN ('unpaid', 'partial')) AS total_due,
                    (SELECT COUNT(*) FROM patient_kyc_documents 
                     WHERE patient_id = p.id AND deleted_at IS NULL) AS kyc_count
                FROM patients p
                LEFT JOIN branches b ON b.id = p.branch_id
                LEFT JOIN patient_insurance_profiles pip ON p.id = pip.patient_id 
                    AND pip.deleted_at IS NULL AND pip.status = 'active'
                LEFT JOIN insurance_providers ip ON pip.insurance_provider_id = ip.id 
                    AND ip.deleted_at IS NULL
                WHERE p.id = :patient_id AND p.deleted_at IS NULL";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':patient_id' => $patient_id]);
        $patient = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$patient) {
            json_exit(['success' => false, 'message' => 'Patient not found']);
        }
        
        // Calculate age
        $age = 'N/A';
        if (!empty($patient['date_of_birth']) && $patient['date_of_birth'] !== '0000-00-00') {
            try {
                $birthDate = new DateTime($patient['date_of_birth']);
                $today = new DateTime();
                $age = $birthDate->diff($today)->y;
            } catch (Exception $e) {
                $age = 'N/A';
            }
        }
        
        // Get latest vitals
        $vitals = null;
        $vitals_stmt = $pdo->prepare("
            SELECT triage_time, blood_pressure_systolic, blood_pressure_diastolic, 
                   heart_rate, temperature_celsius, oxygen_saturation, priority 
            FROM triage_records 
            WHERE patient_id = :patient_id AND deleted_at IS NULL 
            ORDER BY triage_time DESC LIMIT 1
        ");
        $vitals_stmt->execute([':patient_id' => $patient_id]);
        $vitals = $vitals_stmt->fetch(PDO::FETCH_ASSOC);
        
        // Get latest consultation
        $consultation = null;
        $cons_stmt = $pdo->prepare("
            SELECT consultation_date, final_diagnosis, provisional_diagnosis, chief_complaint
            FROM consultations 
            WHERE patient_id = :patient_id AND deleted_at IS NULL 
            ORDER BY consultation_date DESC LIMIT 1
        ");
        $cons_stmt->execute([':patient_id' => $patient_id]);
        $consultation = $cons_stmt->fetch(PDO::FETCH_ASSOC);
        
        // Get latest admission
        $admission = null;
        $adm_stmt = $pdo->prepare("
            SELECT admission_number, admission_type, condition_status, status, admitted_at,
                   w.name as ward_name, b.bed_number
            FROM admissions a
            LEFT JOIN wards w ON w.id = a.ward_id
            LEFT JOIN beds b ON b.id = a.bed_id
            WHERE a.patient_id = :patient_id AND a.deleted_at IS NULL
            ORDER BY a.admitted_at DESC LIMIT 1
        ");
        $adm_stmt->execute([':patient_id' => $patient_id]);
        $admission = $adm_stmt->fetch(PDO::FETCH_ASSOC);
        
        $full_name = trim(($patient['first_name'] ?? '') . ' ' . ($patient['middle_name'] ?? '') . ' ' . ($patient['last_name'] ?? ''));
        if (empty($full_name)) $full_name = 'Unknown Patient';
        
        json_exit([
            'success' => true,
            'patient' => $patient,
            'age' => $age,
            'vitals' => $vitals,
            'consultation' => $consultation,
            'admission' => $admission,
            'kyc_count' => (int)($patient['kyc_count'] ?? 0),
            'full_name' => $full_name
        ]);
        
    } catch (PDOException $e) {
        error_log("Patient details fetch error: " . $e->getMessage());
        json_exit(['success' => false, 'message' => 'Failed to load patient details: ' . $e->getMessage()]);
    }
}

// ============================================
// AJAX: Soft Delete Patient (Secured with CSRF & Branch Isolation)
// ============================================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'delete_patient') {
    // Verify permission
    if (!$can_manage_patients) {
        json_exit(['success' => false, 'message' => 'You do not have permission to delete patients']);
    }
    
    if (!$db_available) {
        json_exit(['success' => false, 'message' => 'Database unavailable']);
    }
    
    // CSRF Validation
    $submitted_token = $_POST['csrf_token'] ?? '';
    if (!isset($_SESSION['csrf_token']) || $submitted_token !== $_SESSION['csrf_token']) {
        error_log("CSRF validation failed for patient deletion");
        json_exit(['success' => false, 'message' => 'Security validation failed. Please refresh the page and try again.']);
    }
    
    $patient_id = (int)($_POST['patient_id'] ?? 0);
    if ($patient_id <= 0) {
        json_exit(['success' => false, 'message' => 'Invalid patient ID']);
    }
    
    try {
        // Branch isolation check
        if (!$is_global_admin && $user_branch_id > 0) {
            $chk = $pdo->prepare("SELECT id FROM patients WHERE id = :id AND branch_id = :branch_id AND deleted_at IS NULL LIMIT 1");
            $chk->execute([':id' => $patient_id, ':branch_id' => $user_branch_id]);
            if (!$chk->fetch()) {
                json_exit(['success' => false, 'message' => 'Access denied - Patient not in your branch']);
            }
        }
        
        // Check for active admissions
        $admission_check = $pdo->prepare("SELECT id FROM admissions WHERE patient_id = :patient_id AND status = 'admitted' AND deleted_at IS NULL LIMIT 1");
        $admission_check->execute([':patient_id' => $patient_id]);
        if ($admission_check->fetch()) {
            json_exit(['success' => false, 'message' => 'Cannot archive patient with active admission. Please discharge patient first.']);
        }
        
        // Check for pending invoices
        $invoice_check = $pdo->prepare("SELECT id FROM invoices WHERE patient_id = :patient_id AND payment_status IN ('unpaid', 'partial') AND deleted_at IS NULL LIMIT 1");
        $invoice_check->execute([':patient_id' => $patient_id]);
        if ($invoice_check->fetch()) {
            json_exit(['success' => false, 'message' => 'Cannot archive patient with pending invoices. Please settle all invoices first.']);
        }
        
        $pdo->beginTransaction();
        
        // Soft delete patient
        $del = $pdo->prepare("UPDATE patients SET deleted_at = NOW(), is_active = 0, updated_at = NOW() WHERE id = :id AND deleted_at IS NULL");
        $del->execute([':id' => $patient_id]);
        
        if ($del->rowCount() > 0) {
            // Record in audit log
            try {
                $audit_stmt = $pdo->prepare("
                    INSERT INTO audit_logs (branch_id, user_id, action_type, target_table, record_id, ip_address, created_at)
                    VALUES (:branch_id, :user_id, 'DELETE', 'patients', :record_id, :ip, NOW())
                ");
                $audit_stmt->execute([
                    ':branch_id' => $user_branch_id,
                    ':user_id' => $user_id,
                    ':record_id' => $patient_id,
                    ':ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
                ]);
            } catch (Exception $e) {
                // Audit log may not exist, continue
            }
            
            $pdo->commit();
            json_exit(['success' => true, 'message' => 'Patient archived successfully']);
        } else {
            $pdo->rollBack();
            json_exit(['success' => false, 'message' => 'Patient not found or already archived']);
        }
        
    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log("Patient deletion failed: " . $e->getMessage());
        json_exit(['success' => false, 'message' => 'Deletion failed. Please try again.']);
    }
}


// ============================================
// AJAX: DataTables Server-Side Processing (FIXED SEARCH)
// ============================================
if (isset($_GET['ajax_data'])) {
    if (!$db_available) {
        json_exit([
            'draw' => isset($_GET['draw']) ? (int)$_GET['draw'] : 1,
            'recordsTotal' => 0,
            'recordsFiltered' => 0,
            'data' => [],
            'error' => 'Database connection failed'
        ]);
    }
    
    try {
        $draw = (int)($_GET['draw'] ?? 1);
        $start = max(0, (int)($_GET['start'] ?? 0));
        $length = min(200, max(10, (int)($_GET['length'] ?? 15)));
        $search = trim($_GET['search_term'] ?? '');
        $like = '%' . $search . '%';
        
        // Build WHERE clause with branch isolation
        $where = "p.deleted_at IS NULL";
        $params = [];
        
        // Apply branch isolation
        if (!$is_global_admin && $user_branch_id > 0) {
            $where .= " AND p.branch_id = :branch_id";
            $params[':branch_id'] = $user_branch_id;
        }
        
        // Add search conditions - FIXED: Added CONCAT for full name search
        if ($search !== '') {
            $where .= " AND (p.first_name LIKE :search_first 
                        OR p.last_name LIKE :search_last 
                        OR p.mr_number LIKE :search_mrn 
                        OR p.phone LIKE :search_phone
                        OR p.national_id LIKE :search_national
                        OR p.email LIKE :search_email
                        OR CONCAT(p.first_name, ' ', COALESCE(p.middle_name, ''), ' ', p.last_name) LIKE :search_full
                        OR CONCAT(p.first_name, ' ', p.last_name) LIKE :search_full2)";
            $params[':search_first'] = $like;
            $params[':search_last'] = $like;
            $params[':search_mrn'] = $like;
            $params[':search_phone'] = $like;
            $params[':search_national'] = $like;
            $params[':search_email'] = $like;
            $params[':search_full'] = $like;
            $params[':search_full2'] = $like;
        }
        
        // Total count (without filters)
        $totalStmt = $pdo->query("SELECT COUNT(*) FROM patients WHERE deleted_at IS NULL");
        $total = (int)$totalStmt->fetchColumn();
        
        // Filtered count with branch isolation
        $filterSql = "SELECT COUNT(DISTINCT p.id) FROM patients p WHERE $where";
        $filterStmt = $pdo->prepare($filterSql);
        $filterStmt->execute($params);
        $filtered = (int)$filterStmt->fetchColumn();
        
        // Main query with pagination - ENHANCED with additional fields
        $sql = "SELECT 
                    p.id, 
                    p.mr_number, 
                    p.first_name, 
                    p.middle_name, 
                    p.last_name, 
                    p.date_of_birth, 
                    p.gender, 
                    p.phone, 
                    p.email, 
                    p.is_active,
                    p.national_id,
                    p.blood_group,
                    p.emergency_contact_name,
                    p.emergency_contact_phone,
                    p.is_free_treatment_eligible,
                    p.created_at,
                    TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) AS age,
                    (SELECT COUNT(*) FROM patient_visits WHERE patient_id = p.id AND deleted_at IS NULL) AS visit_count,
                    (SELECT COUNT(*) FROM consultations WHERE patient_id = p.id AND deleted_at IS NULL) AS consultation_count,
                    (SELECT COUNT(*) FROM lab_orders WHERE patient_id = p.id AND deleted_at IS NULL) AS lab_count,
                    (SELECT COUNT(*) FROM prescriptions WHERE patient_id = p.id AND deleted_at IS NULL) AS prescription_count,
                    (SELECT COUNT(*) FROM invoices WHERE patient_id = p.id AND deleted_at IS NULL AND payment_status IN ('unpaid', 'partial')) AS pending_invoices,
                    (SELECT COALESCE(SUM(due_amount), 0) FROM invoices WHERE patient_id = p.id AND deleted_at IS NULL AND payment_status IN ('unpaid', 'partial')) AS total_due,
                    (SELECT pip.status FROM patient_insurance_profiles pip 
                     WHERE pip.patient_id = p.id AND pip.deleted_at IS NULL 
                     AND pip.status = 'active' AND pip.effective_to >= CURDATE() 
                     LIMIT 1) AS insurance_status,
                    (SELECT COUNT(*) FROM patient_relationships 
                     WHERE (primary_patient_id = p.id OR related_patient_id = p.id) 
                     AND deleted_at IS NULL) AS family_count
                FROM patients p 
                WHERE $where 
                ORDER BY p.created_at DESC 
                LIMIT :start, :length";
        
        $stmt = $pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':start', $start, PDO::PARAM_INT);
        $stmt->bindValue(':length', $length, PDO::PARAM_INT);
        $stmt->execute();
        
        $data = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $id = (int)$row['id'];
            
            // Proper output escaping for XSS prevention
            $first_name = escape_html($row['first_name'] ?? '');
            $middle_name = escape_html($row['middle_name'] ?? '');
            $last_name = escape_html($row['last_name'] ?? '');
            $mr_number = escape_html($row['mr_number'] ?? '');
            $phone = escape_html($row['phone'] ?? 'N/A');
            $email = escape_html($row['email'] ?? 'N/A');
            $gender = escape_html(ucfirst($row['gender'] ?? 'N/A'));
            $age = $row['age'] ?? 'N/A';
            $national_id = escape_html($row['national_id'] ?? 'N/A');
            $blood_group = escape_html($row['blood_group'] ?? 'N/A');
            
            $full_name = trim($first_name . ' ' . $middle_name . ' ' . $last_name);
            $full_name = preg_replace('/\s+/', ' ', $full_name);
            if (empty($full_name)) $full_name = 'Unknown';
            
            $initials = strtoupper(substr($first_name, 0, 1) . substr($last_name, 0, 1));
            if (empty($initials)) $initials = 'PT';
            
            // Enhanced status badges
            $statusBadge = $row['is_active'] == 1 
                ? '<span class="status-badge status-active"><i class="fas fa-circle fa-2xs me-1"></i>Active</span>'
                : '<span class="status-badge status-inactive"><i class="fas fa-circle fa-2xs me-1"></i>Inactive</span>';
            
            // Insurance badge
            $insuranceBadge = '';
            if (!empty($row['insurance_status']) && $row['insurance_status'] == 'active') {
                $insuranceBadge = '<span class="status-badge status-insured ms-1"><i class="fas fa-shield-alt fa-2xs me-1"></i>Insured</span>';
            }
            
            // Family count badge
            $familyBadge = '';
            if ($row['family_count'] > 0) {
                $familyBadge = '<span class="status-badge status-info ms-1"><i class="fas fa-users fa-2xs me-1"></i> ' . $row['family_count'] . '</span>';
            }
            
            // Due amount badge
            $dueBadge = '';
            if ($row['total_due'] > 0) {
                $dueBadge = '<span class="status-badge status-due ms-1"><i class="fas fa-exclamation-triangle fa-2xs me-1"></i>ETB ' . number_format($row['total_due'], 2) . '</span>';
            }
            
            // Free treatment badge
            $freeBadge = '';
            if ($row['is_free_treatment_eligible'] == 1) {
                $freeBadge = '<span class="status-badge status-insured ms-1"><i class="fas fa-hand-holding-heart fa-2xs me-1"></i>Free</span>';
            }
            
            // Build actions based on permissions
            $actions = '<div class="action-buttons">';
            $actions .= '<button class="action-btn view-btn" onclick="viewPatient(' . $id . ')" title="Quick View"><i class="fas fa-eye"></i></button>';
            
            if ($can_view_medical_records || $is_global_admin) {
                $actions .= '<a href="patient_history.php?id=' . $id . '" class="action-btn history-btn" title="View History"><i class="fas fa-history"></i></a>';
            }
            
            if ($can_manage_family || $is_global_admin) {
                $actions .= '<a href="family_linkage.php?id=' . $id . '" class="action-btn family-btn" title="Family Linkage"><i class="fas fa-users"></i></a>';
            }
            
            if ($can_edit_patient || $is_global_admin) {
                $actions .= '<a href="update_patient.php?id=' . $id . '" class="action-btn edit-btn" title="Edit Patient"><i class="fas fa-edit"></i></a>';
            }
            
            if ($can_manage_patients || $is_global_admin) {
                $actions .= '<button class="action-btn delete-btn" onclick="deletePatient(' . $id . ', \'' . addslashes($full_name) . '\')" title="Archive Patient"><i class="fas fa-trash-alt"></i></button>';
            }
            
            $actions .= '</div>';
            
            $data[] = [
                'mr_number' => '<span class="mrn-code">' . $mr_number . '</span>',
                'patient' => '
                    <div class="patient-cell">
                        <div class="patient-avatar" style="background: linear-gradient(135deg, #667eea, #764ba2);">
                            <span style="color: white; font-weight: 600;">' . $initials . '</span>
                        </div>
                        <div class="patient-info">
                            <div class="patient-name">' . $full_name . '</div>
                            <div class="patient-meta">' . $gender . ' • ' . $age . ' yrs</div>
                            <div class="patient-meta small">ID: ' . $national_id . ' • Blood: ' . $blood_group . '</div>
                        </div>
                    </div>
                ',
                'contact' => '
                    <div class="contact-cell">
                        <div><i class="fas fa-phone-alt contact-icon"></i> ' . $phone . '</div>
                        <div class="contact-email"><i class="fas fa-envelope contact-icon"></i> ' . $email . '</div>
                        ' . (!empty($row['emergency_contact_name']) ? '
                        <div class="contact-email small text-muted"><i class="fas fa-ambulance contact-icon"></i> ' . escape_html($row['emergency_contact_name']) . ' (' . escape_html($row['emergency_contact_phone'] ?? 'N/A') . ')</div>
                        ' : '') . '
                    </div>
                ',
                'insurance' => '
                    <div class="insurance-cell">
                        <div>' . $insuranceBadge . $freeBadge . '</div>
                        <div class="insurance-id text-muted small">' . ($row['is_free_treatment_eligible'] ? '⚠️ Free Treatment Eligible' : 'Private/Cash') . '</div>
                    </div>
                ',
                'status' => '
                    <div class="status-cell">
                        ' . $statusBadge . '
                        ' . $insuranceBadge . '
                        ' . $familyBadge . '
                        ' . $dueBadge . '
                        ' . $freeBadge . '
                        <div class="mt-1 small text-muted">
                            Visits: ' . ($row['visit_count'] ?? 0) . ' | Cons: ' . ($row['consultation_count'] ?? 0) . '
                        </div>
                    </div>
                ',
                'actions' => $actions
            ];
        }
        
        json_exit([
            'draw' => $draw,
            'recordsTotal' => $total,
            'recordsFiltered' => $filtered,
            'data' => $data
        ]);
        
    } catch (Exception $e) {
        error_log("DataTables AJAX error: " . $e->getMessage());
        json_exit([
            'draw' => isset($_GET['draw']) ? (int)$_GET['draw'] : 1,
            'recordsTotal' => 0,
            'recordsFiltered' => 0,
            'data' => [],
            'error' => $e->getMessage()
        ]);
    }
}

// ============================================
// AJAX: Get Insurance Providers for Dropdown
// ============================================
if (isset($_GET['get_insurance_providers'])) {
    try {
        $stmt = $pdo->query("SELECT id, name, code FROM insurance_providers WHERE is_active = 1 AND deleted_at IS NULL ORDER BY name ASC");
        $providers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        json_exit(['success' => true, 'providers' => $providers]);
    } catch (PDOException $e) {
        json_exit(['success' => false, 'message' => 'Failed to load insurance providers']);
    }
}

// ============================================
// AJAX: Get KYC Document Types
// ============================================
if (isset($_GET['get_kyc_types'])) {
    try {
        $stmt = $pdo->query("SELECT id, code, name, is_mandatory FROM kyc_document_types WHERE is_active = 1 AND deleted_at IS NULL ORDER BY display_order ASC");
        $types = $stmt->fetchAll(PDO::FETCH_ASSOC);
        json_exit(['success' => true, 'types' => $types]);
    } catch (PDOException $e) {
        json_exit(['success' => false, 'message' => 'Failed to load KYC types']);
    }
}

// ============================================
// AJAX: Upload KYC Document
// ============================================
if (isset($_POST['upload_kyc_document'])) {
    if (!$can_manage_kyc && !$is_global_admin) {
        json_exit(['success' => false, 'message' => 'Permission denied']);
    }
    
    if (!$db_available) {
        json_exit(['success' => false, 'message' => 'Database unavailable']);
    }
    
    // CSRF Validation
    $submitted_token = $_POST['csrf_token'] ?? '';
    if (!isset($_SESSION['csrf_token']) || $submitted_token !== $_SESSION['csrf_token']) {
        json_exit(['success' => false, 'message' => 'Security validation failed']);
    }
    
    $patient_id = (int)($_POST['patient_id'] ?? 0);
    $document_type_id = (int)($_POST['document_type_id'] ?? 0);
    $document_number = trim(escape_html($_POST['document_number'] ?? ''));
    $expiry_date = !empty($_POST['expiry_date']) ? $_POST['expiry_date'] : null;
    
    if ($patient_id <= 0 || $document_type_id <= 0) {
        json_exit(['success' => false, 'message' => 'Invalid patient or document type']);
    }
    
    if (!isset($_FILES['kyc_file']) || $_FILES['kyc_file']['error'] !== UPLOAD_ERR_OK) {
        json_exit(['success' => false, 'message' => 'File upload failed']);
    }
    
    // Validate file
    $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'application/pdf'];
    $file_type = $_FILES['kyc_file']['type'] ?? '';
    if (!in_array($file_type, $allowed_types)) {
        json_exit(['success' => false, 'message' => 'Invalid file type. Allowed: JPEG, PNG, GIF, PDF']);
    }
    
    $max_size = 10 * 1024 * 1024; // 10MB
    if ($_FILES['kyc_file']['size'] > $max_size) {
        json_exit(['success' => false, 'message' => 'File too large. Maximum size: 10MB']);
    }
    
    // Get document type name
    $type_stmt = $pdo->prepare("SELECT name FROM kyc_document_types WHERE id = :id AND deleted_at IS NULL");
    $type_stmt->execute([':id' => $document_type_id]);
    $type = $type_stmt->fetch(PDO::FETCH_ASSOC);
    if (!$type) {
        json_exit(['success' => false, 'message' => 'Invalid document type']);
    }
    
    try {
        // Create upload directory if not exists
        $upload_dir = __DIR__ . '/../../uploads/kyc/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }
        
        $file_extension = pathinfo($_FILES['kyc_file']['name'], PATHINFO_EXTENSION);
        $file_name = 'KYC_' . $patient_id . '_' . time() . '_' . uniqid() . '.' . $file_extension;
        $file_path = $upload_dir . $file_name;
        
        if (!move_uploaded_file($_FILES['kyc_file']['tmp_name'], $file_path)) {
            json_exit(['success' => false, 'message' => 'Failed to save file']);
        }
        
        $pdo->beginTransaction();
        
        $sql = "INSERT INTO patient_kyc_documents 
                (patient_id, document_type_id, document_name, document_number, 
                 file_path, file_type, file_size, original_filename, 
                 expiry_date, uploaded_by_user_id, status, created_at) 
                VALUES 
                (:patient_id, :document_type_id, :document_name, :document_number,
                 :file_path, :file_type, :file_size, :original_filename,
                 :expiry_date, :user_id, 'pending', NOW())";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':patient_id' => $patient_id,
            ':document_type_id' => $document_type_id,
            ':document_name' => $type['name'],
            ':document_number' => !empty($document_number) ? $document_number : null,
            ':file_path' => 'uploads/kyc/' . $file_name,
            ':file_type' => $file_type,
            ':file_size' => $_FILES['kyc_file']['size'],
            ':original_filename' => $_FILES['kyc_file']['name'],
            ':expiry_date' => $expiry_date,
            ':user_id' => $user_id
        ]);
        
        $pdo->commit();
        json_exit(['success' => true, 'message' => 'Document uploaded successfully']);
        
    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log("KYC upload failed: " . $e->getMessage());
        json_exit(['success' => false, 'message' => 'Upload failed: ' . $e->getMessage()]);
    }
}

// ============================================
// AJAX: Get Patient KYC Documents
// ============================================
if (isset($_GET['get_patient_kyc'])) {
    if (!$can_view_kyc && !$is_global_admin) {
        json_exit(['success' => false, 'message' => 'Permission denied']);
    }
    
    $patient_id = (int)($_GET['patient_id'] ?? 0);
    if ($patient_id <= 0) {
        json_exit(['success' => false, 'message' => 'Invalid patient ID']);
    }
    
    try {
        $sql = "SELECT kd.*, dt.name AS document_type_name, 
                       CONCAT(u.first_name, ' ', u.last_name) AS uploaded_by_name
                FROM patient_kyc_documents kd
                LEFT JOIN kyc_document_types dt ON kd.document_type_id = dt.id
                LEFT JOIN users u ON kd.uploaded_by_user_id = u.id
                WHERE kd.patient_id = :patient_id AND kd.deleted_at IS NULL
                ORDER BY kd.created_at DESC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':patient_id' => $patient_id]);
        $documents = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        json_exit(['success' => true, 'documents' => $documents]);
        
    } catch (PDOException $e) {
        json_exit(['success' => false, 'message' => 'Failed to load documents']);
    }
}

// ============================================
// AJAX: Delete KYC Document
// ============================================
if (isset($_POST['delete_kyc_document'])) {
    if (!$can_manage_kyc && !$is_global_admin) {
        json_exit(['success' => false, 'message' => 'Permission denied']);
    }
    
    $document_id = (int)($_POST['document_id'] ?? 0);
    if ($document_id <= 0) {
        json_exit(['success' => false, 'message' => 'Invalid document ID']);
    }
    
    try {
        $stmt = $pdo->prepare("UPDATE patient_kyc_documents SET deleted_at = NOW() WHERE id = :id AND deleted_at IS NULL");
        $stmt->execute([':id' => $document_id]);
        
        if ($stmt->rowCount() > 0) {
            json_exit(['success' => true, 'message' => 'Document deleted successfully']);
        } else {
            json_exit(['success' => false, 'message' => 'Document not found']);
        }
    } catch (PDOException $e) {
        json_exit(['success' => false, 'message' => 'Delete failed']);
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include('assets/inc/head.php'); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Patient Directory | HMS ERP</title>
    
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.dataTables.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    
    <style>
        /* ... CSS Styles ... */
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        :root {
            --primary: #4361ee;
            --primary-dark: #3a0ca3;
            --secondary: #06d6a0;
            --danger: #ef476f;
            --warning: #f9c74f;
            --info: #4cc9f0;
            --dark: #1a1a2e;
            --gray: #6c757d;
            --success: #06d6a0;
            --card-bg: rgba(255, 255, 255, 0.95);
            --shadow-sm: 0 2px 8px rgba(0,0,0,0.05);
            --shadow-md: 0 4px 20px rgba(0,0,0,0.08);
            --shadow-lg: 0 8px 40px rgba(0,0,0,0.12);
        }
        
        body {
            font-family: 'Plus Jakarta Sans', 'Inter', sans-serif;
            background: linear-gradient(145deg, #f0f4f8 0%, #e2e8f0 100%);
            min-height: 100vh;
        }
        
        .content-page {
            margin-left: 260px;
            padding: 28px 32px;
            transition: all 0.3s ease;
        }
        
        body.erp-sidebar-collapsed .content-page {
            margin-left: 80px;
        }
        
        @media (max-width: 768px) {
            .content-page { margin-left: 0; padding: 20px; }
        }
        
        /* Loading States */
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.6);
            z-index: 9999;
            display: none;
            align-items: center;
            justify-content: center;
            backdrop-filter: blur(4px);
        }
        
        .loading-overlay.active {
            display: flex;
        }
        
        .loading-spinner {
            background: white;
            padding: 25px 35px;
            border-radius: 20px;
            text-align: center;
            box-shadow: var(--shadow-lg);
        }
        
        .loading-spinner .spinner-border {
            width: 40px;
            height: 40px;
        }
        
        /* Header */
        .glass-header {
            background: linear-gradient(135deg, rgba(67, 97, 238, 0.95) 0%, rgba(58, 12, 163, 0.95) 100%);
            backdrop-filter: blur(10px);
            border-radius: 28px;
            padding: 28px 36px;
            margin-bottom: 32px;
            color: white;
            box-shadow: var(--shadow-lg);
            position: relative;
            overflow: hidden;
        }
        
        .glass-header::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -20%;
            width: 300px;
            height: 300px;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
            border-radius: 50%;
        }
        
        .greeting-text {
            font-size: 28px;
            font-weight: 700;
            letter-spacing: -0.5px;
        }
        
        .greeting-sub {
            font-size: 14px;
            opacity: 0.8;
            margin-top: 6px;
        }
        
        .time-badge {
            background: rgba(255,255,255,0.15);
            backdrop-filter: blur(5px);
            padding: 8px 18px;
            border-radius: 40px;
            font-size: 13px;
            font-weight: 500;
        }
        
        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 24px;
            margin-bottom: 32px;
        }
        
        @media (max-width: 1100px) {
            .stats-grid { grid-template-columns: repeat(3, 1fr); }
        }
        
        @media (max-width: 700px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
        }
        
        @media (max-width: 500px) {
            .stats-grid { grid-template-columns: 1fr; }
        }
        
        .stat-card {
            background: var(--card-bg);
            backdrop-filter: blur(10px);
            border-radius: 24px;
            padding: 22px 24px;
            transition: all 0.3s ease;
            border: 1px solid rgba(255,255,255,0.3);
            box-shadow: var(--shadow-sm);
            position: relative;
            overflow: hidden;
            cursor: pointer;
        }
        
        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-lg);
        }
        
        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: linear-gradient(90deg, var(--primary), var(--secondary));
        }
        
        .stat-icon {
            width: 52px;
            height: 52px;
            border-radius: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
        }
        
        .stat-icon.primary { background: linear-gradient(135deg, #e8eaff, #d0d4ff); color: var(--primary); }
        .stat-icon.success { background: linear-gradient(135deg, #d1fae5, #a7f3d0); color: var(--success); }
        .stat-icon.warning { background: linear-gradient(135deg, #fef3c7, #fde68a); color: var(--warning); }
        .stat-icon.info { background: linear-gradient(135deg, #e0f0fa, #b8e0f0); color: var(--info); }
        .stat-icon.insurance { background: linear-gradient(135deg, #e0e7ff, #c7d2fe); color: #4f46e5; }
        
        .stat-value {
            font-size: 34px;
            font-weight: 800;
            color: var(--dark);
            line-height: 1.2;
        }
        
        .stat-label {
            font-size: 13px;
            color: var(--gray);
            font-weight: 500;
            margin-top: 4px;
        }
        
        /* Search Section - FIXED */
        .search-section {
            background: var(--card-bg);
            backdrop-filter: blur(10px);
            border-radius: 24px;
            padding: 20px 28px;
            margin-bottom: 28px;
            border: 1px solid rgba(255,255,255,0.3);
            box-shadow: var(--shadow-sm);
        }
        
        .search-wrapper {
            position: relative;
            flex: 1;
        }
        
        .search-wrapper i {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--gray);
            font-size: 16px;
            z-index: 1;
        }
        
        .search-input {
            width: 100%;
            padding: 14px 20px 14px 48px;
            border: 1px solid #e2e8f0;
            border-radius: 60px;
            font-size: 14px;
            transition: all 0.2s;
            background: white;
            color: var(--dark);
        }
        
        .search-input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(67,97,238,0.1);
        }
        
        .search-input::placeholder {
            color: #9ca3af;
        }
        
        /* DataTable Wrapper */
        .data-table-wrapper {
            background: var(--card-bg);
            backdrop-filter: blur(10px);
            border-radius: 24px;
            overflow: hidden;
            box-shadow: var(--shadow-md);
            border: 1px solid rgba(255,255,255,0.3);
        }
        
        .dataTables_wrapper {
            padding: 20px 24px;
        }
        
        /* Patient Cell */
        .patient-cell {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .patient-avatar {
            width: 44px;
            height: 44px;
            border-radius: 50%;
            overflow: hidden;
            background: linear-gradient(135deg, #667eea, #764ba2);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 16px;
            flex-shrink: 0;
        }
        
        .patient-name {
            font-weight: 700;
            color: var(--dark);
            font-size: 14px;
        }
        
        .patient-meta {
            font-size: 11px;
            color: var(--gray);
            margin-top: 2px;
        }
        
        /* Status Badges */
        .status-badge {
            display: inline-flex;
            align-items: center;
            padding: 3px 10px;
            border-radius: 30px;
            font-size: 10px;
            font-weight: 600;
            margin: 1px;
            text-decoration: none;
        }
        
        .status-active { background: #d1fae5; color: #065f46; }
        .status-inactive { background: #f1f5f9; color: #475569; }
        .status-insured { background: #dbeafe; color: #1e40af; }
        .status-expired { background: #fee2e2; color: #991b1b; }
        .status-expiring { background: #fef3c7; color: #92400e; }
        .status-due { background: #fee2e2; color: #991b1b; }
        .status-info { background: #e0f0fa; color: #0c4a6e; }
        
        /* Contact Cell */
        .contact-icon {
            width: 20px;
            color: var(--gray);
            font-size: 11px;
        }
        
        .contact-email {
            font-size: 11px;
            color: var(--gray);
            margin-top: 2px;
        }
        
        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 4px;
            flex-wrap: wrap;
        }
        
        .action-btn {
            width: 30px;
            height: 30px;
            border-radius: 8px;
            border: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s;
            background: #f8fafc;
            color: #475569;
            text-decoration: none;
            font-size: 12px;
        }
        
        .action-btn:hover { transform: translateY(-2px); text-decoration: none; }
        .view-btn:hover { background: var(--primary); color: white; }
        .edit-btn:hover { background: var(--warning); color: white; }
        .history-btn:hover { background: var(--info); color: white; }
        .family-btn:hover { background: #8b5cf6; color: white; }
        .delete-btn:hover { background: var(--danger); color: white; }
        
        /* Modal Styles */
        .modal-content {
            border-radius: 28px;
            border: none;
            overflow: hidden;
        }
        
        .modal-header {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
            border: none;
            padding: 20px 28px;
        }
        
        .modal-header .close {
            color: white;
            opacity: 0.8;
            font-size: 28px;
            background: none;
            border: none;
            cursor: pointer;
        }
        
        .modal-body {
            padding: 28px;
            max-height: 70vh;
            overflow-y: auto;
        }
        
        /* Form Styles */
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            font-weight: 600;
            font-size: 13px;
            color: var(--dark);
            margin-bottom: 8px;
            display: block;
        }
        
        .form-control, .custom-select {
            border-radius: 14px;
            border: 1px solid #e2e8f0;
            padding: 12px 16px;
            transition: all 0.2s;
            width: 100%;
            font-size: 14px;
        }
        
        .form-control:focus, .custom-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(67,97,238,0.1);
            outline: none;
        }
        
        .btn {
            padding: 10px 24px;
            border-radius: 40px;
            font-weight: 600;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            border: none;
            color: white;
        }
        
        .btn-primary:hover {
            background: linear-gradient(135deg, var(--primary-dark), var(--primary));
            transform: translateY(-1px);
            color: white;
        }
        
        .btn-outline-secondary {
            border: 1px solid #e2e8f0;
            background: white;
            color: var(--gray);
        }
        
        .btn-outline-secondary:hover {
            background: #f8f9fa;
            border-color: var(--primary);
        }
        
        /* Patient Detail View - FIXED */
        .detail-section {
            margin-bottom: 24px;
        }
        
        .detail-section h6 {
            font-weight: 700;
            color: var(--dark);
            border-bottom: 2px solid var(--primary);
            padding-bottom: 8px;
            margin-bottom: 16px;
        }
        
        .detail-row {
            display: flex;
            padding: 6px 0;
            border-bottom: 1px solid #f0f0f0;
        }
        
        .detail-label {
            width: 40%;
            font-weight: 600;
            color: var(--gray);
            font-size: 13px;
        }
        
        .detail-value {
            width: 60%;
            font-size: 13px;
            color: var(--dark);
        }
        
        .profile-avatar-large {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea, #764ba2);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 32px;
            font-weight: 700;
            color: white;
        }
        
        .profile-name {
            font-size: 24px;
            font-weight: 700;
        }
        
        .profile-meta {
            color: var(--gray);
            font-size: 14px;
        }
        
        /* DataTables custom styling */
        .dataTables_wrapper .dataTables_paginate .paginate_button.current {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            border-color: var(--primary);
            color: white !important;
        }
        
        .dataTables_wrapper .dataTables_paginate .paginate_button:hover {
            background: var(--primary-dark);
            border-color: var(--primary-dark);
            color: white !important;
        }
        
        .dt-buttons .btn {
            margin-right: 5px;
        }
        
        .mrn-code {
            background: #f0f4ff;
            padding: 4px 10px;
            border-radius: 30px;
            font-size: 11px;
            font-weight: 600;
            color: var(--primary);
            font-family: monospace;
            display: inline-block;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .detail-row { flex-direction: column; }
            .detail-label { width: 100%; }
            .detail-value { width: 100%; }
        }
        
        @media (max-width: 500px) {
            .stats-grid { grid-template-columns: 1fr; }
        }
        
        /* DataTable search fix */
        .dataTables_filter {
            display: none !important;
        }
        
        /* KYC Doc Item */
        .kyc-doc-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 16px;
            background: #f8fafc;
            border-radius: 12px;
            margin-bottom: 8px;
        }
        
        .kyc-doc-item .doc-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .kyc-doc-item .doc-info i {
            font-size: 24px;
            color: var(--primary);
        }
        
        .kyc-doc-item .doc-status {
            font-size: 11px;
            font-weight: 600;
        }
    </style>
</head>
<body>

<div id="wrapper">
    <?php include('assets/inc/nav.php'); ?>
    <?php include('assets/inc/sidebar.php'); ?>

    <div class="content-page">
        <div class="container-fluid">
            
            <!-- Loading Overlay -->
            <div id="loadingOverlay" class="loading-overlay">
                <div class="loading-spinner">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p class="mt-2 mb-0">Processing...</p>
                </div>
            </div>
            
            <!-- Header -->
            <div class="glass-header">
                <div class="d-flex justify-content-between align-items-center flex-wrap">
                    <div>
                        <div class="greeting-text">
                            Welcome back, <?php echo htmlspecialchars($user_name, ENT_QUOTES, 'UTF-8'); ?> 👋
                        </div>
                        <div class="greeting-sub">
                            Manage your patient directory • <?php echo htmlspecialchars($branch_name, ENT_QUOTES, 'UTF-8'); ?>
                        </div>
                    </div>
                    <div class="mt-3 mt-md-0">
                        <div class="time-badge">
                            <i class="far fa-calendar-alt me-2"></i> <?php echo date('l, F j, Y'); ?>
                            <i class="far fa-clock ms-2 me-1"></i> <?php echo date('g:i A'); ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Stats -->
            <div class="stats-grid" id="statsGrid">
                <div class="stat-card">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="stat-value" id="statTotal">0</div>
                            <div class="stat-label">Total Patients</div>
                        </div>
                        <div class="stat-icon primary"><i class="fas fa-users"></i></div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="stat-value text-success" id="statActive">0</div>
                            <div class="stat-label">Active</div>
                        </div>
                        <div class="stat-icon success"><i class="fas fa-check-circle"></i></div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="stat-value text-secondary" id="statInactive">0</div>
                            <div class="stat-label">Inactive</div>
                        </div>
                        <div class="stat-icon warning"><i class="fas fa-ban"></i></div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="stat-value text-info" id="statAdmitted">0</div>
                            <div class="stat-label">Admitted</div>
                        </div>
                        <div class="stat-icon info"><i class="fas fa-procedures"></i></div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="stat-value text-primary" id="statInsured">0</div>
                            <div class="stat-label">Insured</div>
                        </div>
                        <div class="stat-icon insurance"><i class="fas fa-shield-alt"></i></div>
                    </div>
                </div>
            </div>
            
            <!-- Search Section - FIXED -->
            <div class="search-section">
                <div class="row align-items-center g-3">
                    <div class="col-md-12">
                        <div class="search-wrapper">
                            <i class="fas fa-search"></i>
                            <input type="text" id="globalSearch" class="search-input" placeholder="Search by name, MRN, phone, national ID, or email..." autocomplete="off">
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- DataTable -->
            <div class="data-table-wrapper">
                <table id="patientsTable" class="table table-hover mb-0" style="width:100%">
                    <thead>
                        <tr>
                            <th>MRN</th>
                            <th>Patient</th>
                            <th>Contact</th>
                            <th>Insurance</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr><td colspan="6" class="text-center py-5">
                            <div class="spinner-border text-primary" role="status"></div>
                            <p class="mt-2 text-muted">Loading patient data...</p>
                        </td>
                    </tbody>
                </table>
            </div>
            
        </div>
    </div>
    
    <?php include('assets/inc/footer.php'); ?>
</div>

<!-- Patient Quick View Modal - FIXED -->
<div class="modal fade" id="patientDetailModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-user me-2"></i> Patient Details</h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body" id="patientDetailContent">
                <div class="text-center py-4">
                    <div class="spinner-border text-primary" role="status"></div>
                    <p class="mt-2 text-muted">Loading patient details...</p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-dismiss="modal">Close</button>
                <a href="#" id="viewFullProfileBtn" class="btn btn-primary">View Full Profile</a>
            </div>
        </div>
    </div>
</div>

<!-- KYC Upload Modal -->
<div class="modal fade" id="kycUploadModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-id-card me-2"></i> Upload KYC Document</h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body">
                <form id="kycUploadForm" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="patient_id" id="kycPatientId" value="">
                    
                    <div class="form-group">
                        <label>Document Type *</label>
                        <select name="document_type_id" id="kycDocumentType" class="custom-select" required>
                            <option value="">Select Document Type</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Document Number</label>
                        <input type="text" name="document_number" class="form-control" placeholder="e.g., Policy Number, ID Number">
                    </div>
                    <div class="form-group">
                        <label>Expiry Date</label>
                        <input type="date" name="expiry_date" class="form-control">
                    </div>
                    <div class="form-group">
                        <label>File *</label>
                        <input type="file" name="kyc_file" class="form-control" accept=".jpg,.jpeg,.png,.gif,.pdf" required>
                        <small class="text-muted">Allowed: JPG, PNG, GIF, PDF. Max size: 10MB</small>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="submitKycBtn">Upload Document</button>
            </div>
        </div>
    </div>
</div>

<script src="assets/js/vendor.min.js"></script>
<script src="assets/js/app.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/dataTables.buttons.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.html5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.print.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/pdfmake.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/vfs_fonts.js"></script>

<script>
// Global configuration
const userBranchId = <?php echo (int)$user_branch_id; ?>;
const isGlobalAdmin = <?php echo $is_global_admin ? 'true' : 'false'; ?>;
const canManagePatients = <?php echo $can_manage_patients ? 'true' : 'false'; ?>;
const canEditPatient = <?php echo $can_edit_patient ? 'true' : 'false'; ?>;
const canViewMedicalRecords = <?php echo $can_view_medical_records || $is_global_admin ? 'true' : 'false'; ?>;
const csrfToken = <?php echo json_encode($csrf_token); ?>;

let patientTable = null;
let searchDebounce = null;
let currentPatientId = null;

// Show/hide loading overlay
function showLoading(show) {
    const overlay = document.getElementById('loadingOverlay');
    if (overlay) {
        overlay.classList.toggle('active', show);
    }
}

// Escape HTML for XSS prevention
function escapeHtml(str) {
    if (!str) return '';
    return String(str).replace(/[&<>]/g, function(m) {
        if (m === '&') return '&amp;';
        if (m === '<') return '&lt;';
        if (m === '>') return '&gt;';
        return m;
    });
}

// Format date
function formatDate(dateStr) {
    if (!dateStr) return 'N/A';
    try {
        const d = new Date(dateStr);
        return d.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
    } catch(e) {
        return dateStr;
    }
}

function formatDateTime(dateStr) {
    if (!dateStr) return 'N/A';
    try {
        const d = new Date(dateStr);
        return d.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
    } catch(e) {
        return dateStr;
    }
}

// Get status badge HTML
function getStatusBadge(status, type) {
    const badges = {
        'patient': {
            'active': '<span class="status-badge status-active"><i class="fas fa-circle fa-2xs me-1"></i>Active</span>',
            'inactive': '<span class="status-badge status-inactive"><i class="fas fa-circle fa-2xs me-1"></i>Inactive</span>'
        },
        'insurance': {
            'active': '<span class="status-badge status-insured"><i class="fas fa-shield-alt fa-2xs me-1"></i>Active</span>',
            'expired': '<span class="status-badge status-expired"><i class="fas fa-shield-alt fa-2xs me-1"></i>Expired</span>',
            'suspended': '<span class="status-badge status-expiring"><i class="fas fa-shield-alt fa-2xs me-1"></i>Suspended</span>'
        },
        'payment': {
            'paid': '<span class="badge badge-success">Paid</span>',
            'partial': '<span class="badge badge-warning">Partial</span>',
            'unpaid': '<span class="badge badge-danger">Unpaid</span>'
        }
    };
    return badges[type]?.[status] || '<span class="badge badge-secondary">' + status + '</span>';
}

$(function() {
    initDataTable();
    bindEvents();
    loadStats();
    loadInsuranceProviders();
    loadKycTypes();
});

function initDataTable() {
    patientTable = $('#patientsTable').DataTable({
        processing: true,
        serverSide: true,
        searching: false, // Disable default search
        pageLength: 15,
        dom: "<'row'<'col-sm-12 col-md-6'l><'col-sm-12 col-md-6'B>><'row'<'col-sm-12'tr>><'row'<'col-sm-12 col-md-5'i><'col-sm-12 col-md-7'p>>",
        buttons: [
            { extend: 'excelHtml5', text: '<i class="fas fa-file-excel me-1"></i>Excel', className: 'btn btn-sm btn-success' },
            { extend: 'pdfHtml5', text: '<i class="fas fa-file-pdf me-1"></i>PDF', className: 'btn btn-sm btn-danger', orientation: 'landscape' },
            { extend: 'print', text: '<i class="fas fa-print me-1"></i>Print', className: 'btn btn-sm btn-secondary' }
        ],
        ajax: { 
            url: window.location.pathname, 
            type: 'GET', 
            data: function(d) { 
                d.ajax_data = 1; 
                d.search_term = $('#globalSearch').val(); // Pass search term
            },
            beforeSend: function() {
                showLoading(true);
            },
            complete: function() {
                showLoading(false);
            },
            error: function(xhr, status, error) {
                console.error('DataTables AJAX Error:', error);
                showLoading(false);
                let errorMsg = 'Failed to load patient data. Please refresh the page.';
                if (xhr.responseJSON && xhr.responseJSON.error) {
                    errorMsg = xhr.responseJSON.error;
                }
                Swal.fire('Error', errorMsg, 'error');
                $('#patientsTable tbody').html('<tr><td colspan="6" class="text-center text-danger">' + errorMsg + '</td></tr>');
            }
        },
        columns: [
            { data: 'mr_number', orderable: true },
            { data: 'patient', orderable: false },
            { data: 'contact', orderable: false },
            { data: 'insurance', orderable: false },
            { data: 'status', orderable: false },
            { data: 'actions', orderable: false }
        ],
        order: [[0, 'asc']],
        language: { 
            emptyTable: "No patients found", 
            zeroRecords: "No matching patients found",
            processing: '<div class="spinner-border text-primary" role="status"></div>'
        }
    });
}

function loadStats() {
    $.getJSON(window.location.pathname + '?get_summary_stats=1')
        .done(function(res) {
            if (res.success) {
                $('#statTotal').text((res.total || 0).toLocaleString());
                $('#statActive').text((res.active || 0).toLocaleString());
                $('#statInactive').text((res.inactive || 0).toLocaleString());
                $('#statAdmitted').text((res.admitted || 0).toLocaleString());
                $('#statInsured').text((res.insured || 0).toLocaleString());
            }
        })
        .fail(function() {
            console.error('Failed to load stats');
        });
}

function loadInsuranceProviders() {
    $.getJSON(window.location.pathname + '?get_insurance_providers=1')
        .done(function(res) {
            if (res.success && res.providers) {
                // We'll use this when adding insurance to registration
            }
        });
}

function loadKycTypes() {
    $.getJSON(window.location.pathname + '?get_kyc_types=1')
        .done(function(res) {
            if (res.success && res.types) {
                const select = $('#kycDocumentType');
                select.empty().append('<option value="">Select Document Type</option>');
                res.types.forEach(function(type) {
                    select.append('<option value="' + type.id + '">' + escapeHtml(type.name) + (type.is_mandatory ? ' *' : '') + '</option>');
                });
            }
        });
}

function bindEvents() {
    // Search with debounce - FIXED
    $('#globalSearch').on('input', function() {
        clearTimeout(searchDebounce);
        searchDebounce = setTimeout(function() { 
            if (patientTable) {
                patientTable.ajax.reload(); 
            }
        }, 500);
    });
    
    // Enter key triggers search immediately
    $('#globalSearch').on('keydown', function(e) {
        if (e.key === 'Enter') {
            clearTimeout(searchDebounce);
            if (patientTable) {
                patientTable.ajax.reload();
            }
        }
    });

    // KYC upload
    $('#submitKycBtn').on('click', function() {
        const formData = new FormData($('#kycUploadForm')[0]);
        formData.append('upload_kyc_document', 1);
        
        const file = $('#kycUploadForm input[type="file"]')[0].files[0];
        if (!file) {
            Swal.fire('Error', 'Please select a file to upload', 'error');
            return;
        }
        
        showLoading(true);
        
        $.ajax({
            url: window.location.pathname,
            method: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            dataType: 'json'
        })
        .done(function(res) {
            showLoading(false);
            if (res.success) {
                Swal.fire('Success', res.message, 'success');
                $('#kycUploadModal').modal('hide');
                $('#kycUploadForm')[0].reset();
                if (currentPatientId) {
                    viewPatient(currentPatientId);
                }
            } else {
                Swal.fire('Error', res.message, 'error');
            }
        })
        .fail(function(xhr) {
            showLoading(false);
            Swal.fire('Error', 'Upload failed. Please try again.', 'error');
        });
    });
}

// View patient details - FIXED
function viewPatient(id) {
    currentPatientId = id;
    $('#patientDetailContent').html(`
        <div class="text-center py-4">
            <div class="spinner-border text-primary" role="status"></div>
            <p class="mt-2 text-muted">Loading patient details...</p>
        </div>
    `);
    $('#patientDetailModal').modal('show');
    
    $.getJSON(window.location.pathname + '?get_patient_details=1&patient_id=' + id + '&csrf_token=' + csrfToken)
        .done(function(res) {
            if (res.success) {
                renderPatientDetails(res);
            } else {
                $('#patientDetailContent').html('<div class="text-center text-danger py-4">' + escapeHtml(res.message || 'Failed to load patient details') + '</div>');
            }
        })
        .fail(function(xhr) {
            console.error('Patient details error:', xhr);
            let errorMsg = 'Failed to load patient details';
            try {
                const res = JSON.parse(xhr.responseText);
                if (res.message) errorMsg = res.message;
            } catch(e) {}
            $('#patientDetailContent').html('<div class="text-center text-danger py-4">' + escapeHtml(errorMsg) + '</div>');
        });
}

function renderPatientDetails(res) {
    const p = res.patient;
    const fullName = res.full_name || 'Unknown Patient';
    const initials = fullName.split(' ').map(n => n[0]).join('').toUpperCase().substring(0, 2) || 'PT';
    const age = res.age || 'N/A';
    const gender = p.gender ? p.gender.charAt(0).toUpperCase() + p.gender.slice(1) : 'N/A';
    
    let insuranceInfo = 'No insurance on file';
    if (p.insurance_provider_name) {
        insuranceInfo = p.insurance_provider_name + ' (Policy: ' + (p.policy_number || 'N/A') + ')';
        if (p.coverage_percentage) {
            insuranceInfo += ' | Coverage: ' + p.coverage_percentage + '%';
        }
        if (p.insurance_status) {
            insuranceInfo += ' | Status: ' + p.insurance_status;
        }
    }
    
    // Build admission info
    let admissionInfo = '';
    if (res.admission && res.admission.status === 'admitted') {
        admissionInfo = `
            <div class="detail-section">
                <h6><i class="fas fa-procedures me-2"></i>Current Admission</h6>
                <div class="detail-row"><span class="detail-label">Admission #</span><span class="detail-value">${escapeHtml(res.admission.admission_number || 'N/A')}</span></div>
                <div class="detail-row"><span class="detail-label">Type</span><span class="detail-value">${escapeHtml(res.admission.admission_type || 'N/A')}</span></div>
                <div class="detail-row"><span class="detail-label">Condition</span><span class="detail-value">${escapeHtml(res.admission.condition_status || 'N/A')}</span></div>
                <div class="detail-row"><span class="detail-label">Ward</span><span class="detail-value">${escapeHtml(res.admission.ward_name || 'N/A')}</span></div>
                <div class="detail-row"><span class="detail-label">Bed</span><span class="detail-value">${escapeHtml(res.admission.bed_number || 'N/A')}</span></div>
                <div class="detail-row"><span class="detail-label">Admitted</span><span class="detail-value">${formatDateTime(res.admission.admitted_at)}</span></div>
            </div>
        `;
    }
    
    let html = `
        <div class="text-center mb-4">
            <div class="profile-avatar-large d-inline-flex">${initials}</div>
            <h4 class="profile-name mt-2">${escapeHtml(fullName)}</h4>
            <div class="profile-meta">MRN: ${escapeHtml(p.mr_number || 'N/A')} • ${gender} • ${age} yrs</div>
            <div class="mt-2">
                ${p.is_active == 1 ? getStatusBadge('active', 'patient') : getStatusBadge('inactive', 'patient')}
                ${p.insurance_status && p.insurance_status == 'active' ? getStatusBadge('active', 'insurance') : ''}
                ${p.total_due > 0 ? '<span class="status-badge status-due"><i class="fas fa-exclamation-triangle me-1"></i>Due: ETB ' + parseFloat(p.total_due).toFixed(2) + '</span>' : ''}
                ${p.family_count > 0 ? '<span class="status-badge status-info"><i class="fas fa-users me-1"></i>Family: ' + p.family_count + '</span>' : ''}
                ${p.is_free_treatment_eligible ? '<span class="status-badge status-insured"><i class="fas fa-hand-holding-heart me-1"></i>Free Treatment</span>' : ''}
                ${res.admission && res.admission.status === 'admitted' ? '<span class="status-badge status-active"><i class="fas fa-hospital me-1"></i>Admitted</span>' : ''}
            </div>
        </div>
        
        <div class="row">
            <div class="col-md-6">
                <div class="detail-section">
                    <h6><i class="fas fa-address-card me-2"></i>Personal Information</h6>
                    <div class="detail-row"><span class="detail-label">Full Name</span><span class="detail-value">${escapeHtml(fullName)}</span></div>
                    <div class="detail-row"><span class="detail-label">MRN</span><span class="detail-value">${escapeHtml(p.mr_number || 'N/A')}</span></div>
                    <div class="detail-row"><span class="detail-label">Gender</span><span class="detail-value">${gender}</span></div>
                    <div class="detail-row"><span class="detail-label">Date of Birth</span><span class="detail-value">${formatDate(p.date_of_birth)}</span></div>
                    <div class="detail-row"><span class="detail-label">Age</span><span class="detail-value">${age} years</span></div>
                    <div class="detail-row"><span class="detail-label">Blood Group</span><span class="detail-value">${escapeHtml(p.blood_group || 'N/A')}</span></div>
                    <div class="detail-row"><span class="detail-label">National ID</span><span class="detail-value">${escapeHtml(p.national_id || 'N/A')}</span></div>
                    <div class="detail-row"><span class="detail-label">Branch</span><span class="detail-value">${escapeHtml(p.branch_name || 'N/A')}</span></div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="detail-section">
                    <h6><i class="fas fa-phone me-2"></i>Contact Information</h6>
                    <div class="detail-row"><span class="detail-label">Phone</span><span class="detail-value">${escapeHtml(p.phone || 'N/A')}</span></div>
                    <div class="detail-row"><span class="detail-label">Email</span><span class="detail-value">${escapeHtml(p.email || 'N/A')}</span></div>
                    <div class="detail-row"><span class="detail-label">Address</span><span class="detail-value">${escapeHtml(p.address || 'N/A')}</span></div>
                </div>
                ${p.emergency_contact_name ? `
                <div class="detail-section">
                    <h6><i class="fas fa-ambulance me-2"></i>Emergency Contact</h6>
                    <div class="detail-row"><span class="detail-label">Name</span><span class="detail-value">${escapeHtml(p.emergency_contact_name)}</span></div>
                    <div class="detail-row"><span class="detail-label">Phone</span><span class="detail-value">${escapeHtml(p.emergency_contact_phone || 'N/A')}</span></div>
                    <div class="detail-row"><span class="detail-label">Relationship</span><span class="detail-value">${escapeHtml(p.emergency_contact_relationship || 'N/A')}</span></div>
                </div>
                ` : ''}
            </div>
        </div>
        
        <div class="row">
            <div class="col-md-6">
                <div class="detail-section">
                    <h6><i class="fas fa-shield-alt me-2"></i>Insurance</h6>
                    <div class="detail-row"><span class="detail-label">Provider</span><span class="detail-value">${escapeHtml(p.insurance_provider_name || 'Private/Cash')}</span></div>
                    ${p.policy_number ? `<div class="detail-row"><span class="detail-label">Policy #</span><span class="detail-value">${escapeHtml(p.policy_number)}</span></div>` : ''}
                    ${p.member_id ? `<div class="detail-row"><span class="detail-label">Member ID</span><span class="detail-value">${escapeHtml(p.member_id)}</span></div>` : ''}
                    ${p.coverage_percentage ? `<div class="detail-row"><span class="detail-label">Coverage</span><span class="detail-value">${p.coverage_percentage}%</span></div>` : ''}
                    ${p.co_pay_percentage ? `<div class="detail-row"><span class="detail-label">Co-pay</span><span class="detail-value">${p.co_pay_percentage}%</span></div>` : ''}
                    ${p.insurance_valid_from ? `<div class="detail-row"><span class="detail-label">Valid From</span><span class="detail-value">${formatDate(p.insurance_valid_from)}</span></div>` : ''}
                    ${p.insurance_valid_to ? `<div class="detail-row"><span class="detail-label">Valid To</span><span class="detail-value">${formatDate(p.insurance_valid_to)}</span></div>` : ''}
                    ${p.insurance_status ? `<div class="detail-row"><span class="detail-label">Status</span><span class="detail-value">${getStatusBadge(p.insurance_status, 'insurance')}</span></div>` : ''}
                    ${p.is_free_treatment_eligible ? `<div class="detail-row"><span class="detail-label">Free Treatment</span><span class="detail-value"><span class="status-badge status-insured">Eligible</span></span></div>` : ''}
                </div>
            </div>
            <div class="col-md-6">
                <div class="detail-section">
                    <h6><i class="fas fa-chart-bar me-2"></i>Statistics</h6>
                    <div class="detail-row"><span class="detail-label">Total Visits</span><span class="detail-value">${p.visit_count || 0}</span></div>
                    <div class="detail-row"><span class="detail-label">Consultations</span><span class="detail-value">${p.consultation_count || 0}</span></div>
                    <div class="detail-row"><span class="detail-label">Lab Tests</span><span class="detail-value">${p.lab_count || 0}</span></div>
                    <div class="detail-row"><span class="detail-label">Prescriptions</span><span class="detail-value">${p.prescription_count || 0}</span></div>
                    <div class="detail-row"><span class="detail-label">Pending Invoices</span><span class="detail-value">${p.pending_invoices || 0}</span></div>
                    <div class="detail-row"><span class="detail-label">Total Due</span><span class="detail-value ${p.total_due > 0 ? 'text-danger fw-bold' : ''}">ETB ${(p.total_due || 0).toFixed(2)}</span></div>
                    <div class="detail-row"><span class="detail-label">Family Members</span><span class="detail-value">${p.family_count || 0}</span></div>
                    <div class="detail-row"><span class="detail-label">KYC Documents</span><span class="detail-value">${p.kyc_count || 0}</span></div>
                </div>
                ${res.vitals ? `
                <div class="detail-section">
                    <h6><i class="fas fa-heartbeat me-2"></i>Latest Vitals</h6>
                    <div class="detail-row"><span class="detail-label">BP</span><span class="detail-value">${res.vitals.blood_pressure_systolic || '—'}/${res.vitals.blood_pressure_diastolic || '—'}</span></div>
                    <div class="detail-row"><span class="detail-label">Heart Rate</span><span class="detail-value">${res.vitals.heart_rate || '—'} bpm</span></div>
                    <div class="detail-row"><span class="detail-label">Temp</span><span class="detail-value">${res.vitals.temperature_celsius || '—'} °C</span></div>
                    <div class="detail-row"><span class="detail-label">SpO₂</span><span class="detail-value">${res.vitals.oxygen_saturation || '—'}%</span></div>
                    <div class="detail-row"><span class="detail-label">Priority</span><span class="detail-value">${res.vitals.priority ? res.vitals.priority.toUpperCase() : '—'}</span></div>
                    <div class="detail-row"><span class="detail-label">Recorded</span><span class="detail-value">${formatDateTime(res.vitals.triage_time)}</span></div>
                </div>
                ` : ''}
                ${res.consultation ? `
                <div class="detail-section">
                    <h6><i class="fas fa-stethoscope me-2"></i>Latest Consultation</h6>
                    <div class="detail-row"><span class="detail-label">Date</span><span class="detail-value">${formatDate(res.consultation.consultation_date)}</span></div>
                    <div class="detail-row"><span class="detail-label">Chief Complaint</span><span class="detail-value">${escapeHtml(res.consultation.chief_complaint || '—')}</span></div>
                    <div class="detail-row"><span class="detail-label">Final Diagnosis</span><span class="detail-value">${escapeHtml(res.consultation.final_diagnosis || '—')}</span></div>
                    <div class="detail-row"><span class="detail-label">Provisional</span><span class="detail-value">${escapeHtml(res.consultation.provisional_diagnosis || '—')}</span></div>
                </div>
                ` : ''}
                ${admissionInfo}
            </div>
        </div>
    `;
    
    $('#patientDetailContent').html(html);
    $('#viewFullProfileBtn').attr('href', 'patient_history.php?id=' + currentPatientId);
}

// Delete patient
function deletePatient(id, name) {
    if (!canManagePatients) {
        Swal.fire('Error', 'You do not have permission to delete patients', 'error');
        return;
    }
    
    Swal.fire({ 
        title: 'Archive Patient?', 
        html: 'Are you sure you want to archive <strong>' + escapeHtml(name) + '</strong>?<br><small>This will soft-delete the record and can be restored later.<br><br><strong>Note:</strong> Patients with active admissions or pending invoices cannot be archived.</small>', 
        icon: 'warning', 
        showCancelButton: true, 
        confirmButtonColor: '#d33', 
        confirmButtonText: '<i class="fas fa-trash-alt me-1"></i>Archive', 
        cancelButtonText: 'Cancel' 
    })
    .then(function(result) {
        if (result.isConfirmed) {
            showLoading(true);
            $.ajax({ 
                url: window.location.pathname + '?ajax=delete_patient', 
                method: 'POST', 
                data: { 
                    patient_id: id,
                    csrf_token: csrfToken
                }, 
                dataType: 'json' 
            })
            .done(function(res) { 
                showLoading(false);
                if (res.success) { 
                    Swal.fire('Archived', res.message, 'success'); 
                    if (patientTable) patientTable.ajax.reload(); 
                    loadStats(); 
                } else { 
                    Swal.fire('Error', res.message, 'error'); 
                } 
            })
            .fail(function(xhr) { 
                showLoading(false);
                Swal.fire('Error', 'Server error. Please try again.', 'error'); 
            });
        }
    });
}

// Open KYC upload modal
function openKycModal(patientId) {
    $('#kycPatientId').val(patientId);
    $('#kycUploadModal').modal('show');
}

// Open family linkage
function openFamilyLinkage(patientId) {
    window.location.href = 'family_linkage.php?id=' + patientId;
}

// Make functions globally accessible
window.viewPatient = viewPatient;
window.deletePatient = deletePatient;
window.openKycModal = openKycModal;
window.openFamilyLinkage = openFamilyLinkage;
</script>

</body>
</html>