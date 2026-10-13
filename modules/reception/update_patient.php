<?php
/**
 * Update Patient - HMS ERP System
 * Enhanced: Complete integration with all database tables
 * FIXED: HTTP 500 error with proper error handling
 * 
 * @package HMS-ERP
 * @version 3.1
 * @requires patient_edit_access permission
 */

// Start session before any output
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Enable error reporting for debugging - ADD THIS FOR DEBUGGING
error_reporting(E_ALL);
ini_set('display_errors', 1);  // CHANGE: Set to 1 for debugging
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error.log');

// Load configuration and helpers
require_once __DIR__ . '/../../config/db_connection.php';
require_once __DIR__ . '/../../includes/auth_helper.php';

// Set timezone
date_default_timezone_set('Africa/Addis_Ababa');

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

// Permission check for editing patients
$can_edit_patient = $is_global_admin;
$can_manage_insurance = $is_global_admin;
$can_manage_kyc = $is_global_admin;
$can_manage_family = $is_global_admin;

if (!$is_global_admin && $db_available && $user_id > 0) {
    $can_edit_patient = user_has_permission($pdo, 'patient_edit_access', $user_id);
    $can_manage_insurance = user_has_permission($pdo, 'insurance_manage_access', $user_id);
    $can_manage_kyc = user_has_permission($pdo, 'patient_kyc_manage_access', $user_id);
    $can_manage_family = user_has_permission($pdo, 'patient_family_manage_access', $user_id);
}

// If user has no permission, redirect
if (!$can_edit_patient) {
    header('Location: dashboard.php?error=unauthorized');
    exit;
}

// Get patient ID from request
$patient_id = (int)($_GET['id'] ?? 0);

if ($patient_id <= 0) {
    header('Location: patient_directory.php?msg=invalid_patient');
    exit;
}

// CSRF Token setup
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// ============================================
// HELPER FUNCTIONS
// ============================================

function escape_html($string) {
    if ($string === null) return '';
    return htmlspecialchars((string)$string, ENT_QUOTES, 'UTF-8');
}

function sanitize_phone($phone) {
    if (empty($phone)) return null;
    return preg_replace('/[^0-9+]/', '', $phone);
}

// ============================================
// VERIFY PATIENT EXISTS AND BRANCH ACCESS
// ============================================
$patient = null;
$patient_check = null;

try {
    // Check if patient exists and belongs to user's branch
    $check_sql = "SELECT id, branch_id, first_name, last_name, mr_number, is_active 
                  FROM patients 
                  WHERE id = :patient_id AND deleted_at IS NULL";
    $check_stmt = $pdo->prepare($check_sql);
    $check_stmt->execute([':patient_id' => $patient_id]);
    $patient_check = $check_stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$patient_check) {
        header('Location: patient_directory.php?msg=patient_not_found');
        exit;
    }
    
    // Branch access control - prevent cross-branch editing
    if (!$is_global_admin && $user_branch_id > 0 && $patient_check['branch_id'] != $user_branch_id) {
        header('Location: patient_directory.php?msg=access_denied');
        exit;
    }
    
} catch (PDOException $e) {
    error_log("UPDATE PATIENT: Patient verification error - " . $e->getMessage());
    die('Error verifying patient data: ' . $e->getMessage());
}

// ============================================
// FETCH FULL PATIENT DATA WITH ALL RELATED INFO
// ============================================
try {
    $sql = "
        SELECT 
            p.id, 
            p.branch_id, 
            p.mr_number, 
            p.first_name, 
            p.middle_name, 
            p.last_name,
            p.phone, 
            p.email, 
            p.address, 
            p.national_id, 
            p.gender, 
            p.date_of_birth, 
            p.blood_group,
            p.emergency_contact_name, 
            p.emergency_contact_phone, 
            p.emergency_contact_relationship,
            p.is_free_treatment_eligible,
            p.free_treatment_letter_number,
            p.free_treatment_letter_issued_by,
            p.free_treatment_valid_from,
            p.free_treatment_valid_to,
            p.is_active,
            p.is_consent_given,
            p.consent_given_at,
            p.created_at,
            p.created_by_user_id,
            p.updated_at,
            pip.id AS insurance_profile_id,
            pip.insurance_provider_id,
            pip.policy_number AS insurance_number,
            pip.member_id AS insurance_member_id,
            pip.group_number AS insurance_group_number,
            pip.coverage_percentage,
            pip.co_pay_percentage,
            pip.deductible_amount,
            pip.deductible_remaining,
            pip.max_coverage_per_year,
            pip.max_coverage_per_admission,
            pip.annual_deductible_remaining,
            pip.out_of_pocket_max,
            pip.out_of_pocket_year_to_date,
            pip.effective_from AS insurance_valid_from,
            pip.effective_to AS insurance_valid_to,
            pip.benefit_year_start,
            pip.benefit_year_end,
            pip.status AS insurance_status,
            pip.is_primary,
            ip.name AS insurance_provider_name,
            ip.code AS insurance_code,
            (SELECT COUNT(*) FROM patient_relationships 
             WHERE (primary_patient_id = p.id OR related_patient_id = p.id) 
             AND deleted_at IS NULL) AS family_count,
            (SELECT COUNT(*) FROM patient_visits 
             WHERE patient_id = p.id AND deleted_at IS NULL) AS visit_count,
            (SELECT COUNT(*) FROM consultations 
             WHERE patient_id = p.id AND deleted_at IS NULL) AS consultation_count,
            (SELECT COUNT(*) FROM patient_kyc_documents 
             WHERE patient_id = p.id AND deleted_at IS NULL) AS kyc_count,
            (SELECT COUNT(*) FROM invoices 
             WHERE patient_id = p.id AND deleted_at IS NULL 
             AND payment_status IN ('unpaid', 'partial')) AS pending_invoices,
            (SELECT COALESCE(SUM(due_amount), 0) FROM invoices 
             WHERE patient_id = p.id AND deleted_at IS NULL 
             AND payment_status IN ('unpaid', 'partial')) AS total_due
        FROM patients p
        LEFT JOIN patient_insurance_profiles pip ON p.id = pip.patient_id 
            AND pip.deleted_at IS NULL AND pip.status = 'active'
        LEFT JOIN insurance_providers ip ON pip.insurance_provider_id = ip.id 
            AND ip.deleted_at IS NULL
        WHERE p.id = :patient_id AND p.deleted_at IS NULL
        LIMIT 1
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':patient_id' => $patient_id]);
    $patient = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$patient) {
        header('Location: patient_directory.php?msg=patient_not_found');
        exit;
    }
    
} catch (PDOException $e) {
    error_log("Patient fetch error: " . $e->getMessage());
    die('Error loading patient data: ' . $e->getMessage());
}

// ============================================
// FETCH FAMILY MEMBERS FOR DISPLAY
// ============================================
$family_members = [];
try {
    $stmt = $pdo->prepare("
        SELECT 
            pr.id,
            pr.relationship_type,
            pr.is_emergency_contact,
            CASE 
                WHEN pr.primary_patient_id = :patient_id1 THEN pr.related_patient_id
                ELSE pr.primary_patient_id
            END AS relative_id,
            CONCAT(p.first_name, ' ', COALESCE(p.middle_name, ''), ' ', p.last_name) AS relative_name,
            p.mr_number AS relative_mrn,
            p.phone AS relative_phone,
            p.is_active AS relative_active
        FROM patient_relationships pr
        INNER JOIN patients p ON (
            (pr.primary_patient_id = :patient_id2 AND p.id = pr.related_patient_id) OR
            (pr.related_patient_id = :patient_id3 AND p.id = pr.primary_patient_id)
        )
        WHERE (pr.primary_patient_id = :patient_id4 OR pr.related_patient_id = :patient_id5)
        AND pr.deleted_at IS NULL
        AND p.deleted_at IS NULL
        LIMIT 20
    ");
    $stmt->execute([
        ':patient_id1' => $patient_id,
        ':patient_id2' => $patient_id,
        ':patient_id3' => $patient_id,
        ':patient_id4' => $patient_id,
        ':patient_id5' => $patient_id
    ]);
    $family_members = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Family members fetch error: " . $e->getMessage());
}

// ============================================
// FETCH INSURANCE PROVIDERS FOR DROPDOWN
// ============================================
$insurance_providers = [];
try {
    $stmt = $pdo->query("
        SELECT id, name, code, default_coverage_percentage, default_co_pay_percentage 
        FROM insurance_providers 
        WHERE is_active = 1 AND deleted_at IS NULL 
        ORDER BY name ASC
    ");
    $insurance_providers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Insurance providers fetch error: " . $e->getMessage());
}

// Calculate age for display
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

// Get initials
$initials = 'PT';
if (!empty($patient['first_name']) && !empty($patient['last_name'])) {
    $initials = strtoupper(substr($patient['first_name'], 0, 1) . substr($patient['last_name'], 0, 1));
} elseif (!empty($patient['first_name'])) {
    $initials = strtoupper(substr($patient['first_name'], 0, 2));
}

// ============================================
// PROCESS UPDATE FORM SUBMISSION - FIXED WITH BETTER ERROR HANDLING
// ============================================
$errors = [];
$success_message = '';
$old_data = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do_update'])) {
    
    try {
        // Store old data for audit
        $old_data = $patient;
        
        // Validate CSRF token
        $submitted_token = $_POST['csrf_token'] ?? '';
        if (!isset($_SESSION['csrf_token']) || $submitted_token !== $_SESSION['csrf_token']) {
            $errors[] = 'Security validation failed. Please refresh the page and try again.';
        }
        
        // Basic Info
        $first_name = trim($_POST['first_name'] ?? '');
        $last_name = trim($_POST['last_name'] ?? '');
        $middle_name = trim($_POST['middle_name'] ?? '');
        $mrn = trim($_POST['mr_number'] ?? '');
        
        // Contact Info
        $phone_raw = trim($_POST['phone'] ?? '');
        $phone = !empty($phone_raw) ? sanitize_phone($phone_raw) : null;
        $email = trim($_POST['email'] ?? '');
        $national_id = trim($_POST['national_id'] ?? '');
        $gender = !empty($_POST['gender']) ? $_POST['gender'] : null;
        $address = trim($_POST['address'] ?? '');
        $date_of_birth = !empty($_POST['date_of_birth']) ? $_POST['date_of_birth'] : null;
        $blood_group = !empty($_POST['blood_group']) ? $_POST['blood_group'] : null;
        
        // Emergency Contact
        $emergency_contact_name = trim($_POST['emergency_contact_name'] ?? '');
        $emergency_contact_phone_raw = trim($_POST['emergency_contact_phone'] ?? '');
        $emergency_contact_phone = !empty($emergency_contact_phone_raw) ? sanitize_phone($emergency_contact_phone_raw) : null;
        $emergency_contact_relationship = trim($_POST['emergency_contact_relationship'] ?? '');
        
        // Free Treatment
        $is_free_treatment_eligible = isset($_POST['is_free_treatment_eligible']) ? 1 : 0;
        $free_treatment_letter_number = trim($_POST['free_treatment_letter_number'] ?? '');
        $free_treatment_letter_issued_by = trim($_POST['free_treatment_letter_issued_by'] ?? '');
        $free_treatment_valid_from = !empty($_POST['free_treatment_valid_from']) ? $_POST['free_treatment_valid_from'] : null;
        $free_treatment_valid_to = !empty($_POST['free_treatment_valid_to']) ? $_POST['free_treatment_valid_to'] : null;
        
        // Insurance Details
        $insurance_provider_id = !empty($_POST['insurance_provider_id']) ? (int)$_POST['insurance_provider_id'] : null;
        $insurance_number = trim($_POST['insurance_number'] ?? '');
        $insurance_member_id = trim($_POST['insurance_member_id'] ?? '');
        $insurance_group_number = trim($_POST['insurance_group_number'] ?? '');
        $insurance_coverage_percentage = !empty($_POST['insurance_coverage_percentage']) ? (float)$_POST['insurance_coverage_percentage'] : null;
        $insurance_co_pay_percentage = !empty($_POST['insurance_co_pay_percentage']) ? (int)$_POST['insurance_co_pay_percentage'] : null;
        $insurance_deductible = !empty($_POST['insurance_deductible']) ? (float)$_POST['insurance_deductible'] : 0;
        $insurance_deductible_remaining = !empty($_POST['insurance_deductible_remaining']) ? (float)$_POST['insurance_deductible_remaining'] : null;
        $insurance_max_coverage_per_year = !empty($_POST['insurance_max_coverage_per_year']) ? (float)$_POST['insurance_max_coverage_per_year'] : null;
        $insurance_max_coverage_per_admission = !empty($_POST['insurance_max_coverage_per_admission']) ? (float)$_POST['insurance_max_coverage_per_admission'] : null;
        $insurance_annual_deductible_remaining = !empty($_POST['insurance_annual_deductible_remaining']) ? (float)$_POST['insurance_annual_deductible_remaining'] : null;
        $insurance_out_of_pocket_max = !empty($_POST['insurance_out_of_pocket_max']) ? (float)$_POST['insurance_out_of_pocket_max'] : null;
        $insurance_out_of_pocket_year_to_date = !empty($_POST['insurance_out_of_pocket_year_to_date']) ? (float)$_POST['insurance_out_of_pocket_year_to_date'] : 0;
        $insurance_valid_from = !empty($_POST['insurance_valid_from']) ? $_POST['insurance_valid_from'] : null;
        $insurance_valid_to = !empty($_POST['insurance_valid_to']) ? $_POST['insurance_valid_to'] : null;
        $insurance_benefit_year_start = !empty($_POST['insurance_benefit_year_start']) ? $_POST['insurance_benefit_year_start'] : null;
        $insurance_benefit_year_end = !empty($_POST['insurance_benefit_year_end']) ? $_POST['insurance_benefit_year_end'] : null;
        $insurance_is_primary = isset($_POST['insurance_is_primary']) ? 1 : 0;
        $insurance_status = $_POST['insurance_status'] ?? 'active';
        
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        $is_consent_given = isset($_POST['is_consent_given']) ? 1 : 0;
        
        // Validation
        if ($first_name === '') $errors[] = 'First Name is required.';
        if ($last_name === '') $errors[] = 'Last Name is required.';
        if ($mrn === '') $errors[] = 'MRN is required.';
        
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Invalid email format.';
        }
        
        if ($date_of_birth && !strtotime($date_of_birth)) {
            $errors[] = 'Invalid birth date format.';
        }
        
        if ($date_of_birth && strtotime($date_of_birth) > time()) {
            $errors[] = 'Date of birth cannot be in the future.';
        }
        
        if ($insurance_valid_from && $insurance_valid_to && strtotime($insurance_valid_from) > strtotime($insurance_valid_to)) {
            $errors[] = 'Insurance valid from date cannot be after valid to date.';
        }
        
        if ($free_treatment_valid_from && $free_treatment_valid_to && strtotime($free_treatment_valid_from) > strtotime($free_treatment_valid_to)) {
            $errors[] = 'Free treatment valid from date cannot be after valid to date.';
        }
        
        if ($insurance_benefit_year_start && $insurance_benefit_year_end && strtotime($insurance_benefit_year_start) > strtotime($insurance_benefit_year_end)) {
            $errors[] = 'Benefit year start cannot be after end.';
        }
        
        // Phone number validation (Ethiopian format)
        if ($phone && !preg_match('/^(09|07|01)[0-9]{8}$/', $phone)) {
            $errors[] = 'Invalid phone number format. Use Ethiopian format (09XXXXXXXX).';
        }
        
        // Process update if no errors
        if (empty($errors) && $pdo) {
            
            $pdo->beginTransaction();
            
            // Get current consent_given_at value before update
            $consent_check = $pdo->prepare("SELECT consent_given_at FROM patients WHERE id = :id AND deleted_at IS NULL");
            $consent_check->execute([':id' => $patient_id]);
            $current_consent = $consent_check->fetch(PDO::FETCH_ASSOC);
            
            // Determine if consent_given_at should be updated
            $consent_given_at = null;
            if ($is_consent_given == 1) {
                if (empty($current_consent['consent_given_at']) || $current_consent['consent_given_at'] === null) {
                    $consent_given_at = date('Y-m-d H:i:s');
                } else {
                    $consent_given_at = $current_consent['consent_given_at'];
                }
            }
            
            // 1. UPDATE patients table - FIXED
            $updateSql = "
                UPDATE patients SET 
                    first_name = :first_name,
                    last_name = :last_name,
                    middle_name = :middle_name,
                    mr_number = :mr_number,
                    phone = :phone,
                    email = :email,
                    national_id = :national_id,
                    gender = :gender,
                    address = :address,
                    date_of_birth = :date_of_birth,
                    blood_group = :blood_group,
                    emergency_contact_name = :emergency_contact_name,
                    emergency_contact_phone = :emergency_contact_phone,
                    emergency_contact_relationship = :emergency_contact_relationship,
                    is_free_treatment_eligible = :is_free_treatment_eligible,
                    free_treatment_letter_number = :free_treatment_letter_number,
                    free_treatment_letter_issued_by = :free_treatment_letter_issued_by,
                    free_treatment_valid_from = :free_treatment_valid_from,
                    free_treatment_valid_to = :free_treatment_valid_to,
                    is_active = :is_active,
                    is_consent_given = :is_consent_given,
                    consent_given_at = :consent_given_at,
                    updated_at = NOW()
                WHERE id = :patient_id AND deleted_at IS NULL
            ";
            
            $updateStmt = $pdo->prepare($updateSql);
            $updateStmt->execute([
                ':first_name' => $first_name,
                ':last_name' => $last_name,
                ':middle_name' => $middle_name ?: null,
                ':mr_number' => $mrn,
                ':phone' => $phone,
                ':email' => $email ?: null,
                ':national_id' => $national_id ?: null,
                ':gender' => $gender,
                ':address' => $address ?: null,
                ':date_of_birth' => $date_of_birth,
                ':blood_group' => $blood_group ?: null,
                ':emergency_contact_name' => $emergency_contact_name ?: null,
                ':emergency_contact_phone' => $emergency_contact_phone,
                ':emergency_contact_relationship' => $emergency_contact_relationship ?: null,
                ':is_free_treatment_eligible' => $is_free_treatment_eligible,
                ':free_treatment_letter_number' => $free_treatment_letter_number ?: null,
                ':free_treatment_letter_issued_by' => $free_treatment_letter_issued_by ?: null,
                ':free_treatment_valid_from' => $free_treatment_valid_from,
                ':free_treatment_valid_to' => $free_treatment_valid_to,
                ':is_active' => $is_active,
                ':is_consent_given' => $is_consent_given,
                ':consent_given_at' => $consent_given_at,
                ':patient_id' => $patient_id
            ]);
            
            // 2. Handle insurance profile
            if ($insurance_provider_id && $insurance_number) {
                // Check if insurance profile exists
                $checkStmt = $pdo->prepare("SELECT id FROM patient_insurance_profiles WHERE patient_id = :patient_id AND deleted_at IS NULL LIMIT 1");
                $checkStmt->execute([':patient_id' => $patient_id]);
                $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);
                
                if ($existing) {
                    // Update existing
                    $insSql = "
                        UPDATE patient_insurance_profiles SET 
                            insurance_provider_id = :provider_id,
                            policy_number = :policy_number,
                            member_id = :member_id,
                            group_number = :group_number,
                            coverage_percentage = :coverage_percentage,
                            co_pay_percentage = :co_pay_percentage,
                            deductible_amount = :deductible_amount,
                            deductible_remaining = :deductible_remaining,
                            max_coverage_per_year = :max_coverage_per_year,
                            max_coverage_per_admission = :max_coverage_per_admission,
                            annual_deductible_remaining = :annual_deductible_remaining,
                            out_of_pocket_max = :out_of_pocket_max,
                            out_of_pocket_year_to_date = :out_of_pocket_year_to_date,
                            effective_from = :effective_from,
                            effective_to = :effective_to,
                            benefit_year_start = :benefit_year_start,
                            benefit_year_end = :benefit_year_end,
                            status = :status,
                            is_primary = :is_primary,
                            updated_at = NOW()
                        WHERE patient_id = :patient_id AND deleted_at IS NULL
                    ";
                    $insStmt = $pdo->prepare($insSql);
                    $insStmt->execute([
                        ':provider_id' => $insurance_provider_id,
                        ':policy_number' => $insurance_number,
                        ':member_id' => $insurance_member_id ?: null,
                        ':group_number' => $insurance_group_number ?: null,
                        ':coverage_percentage' => $insurance_coverage_percentage,
                        ':co_pay_percentage' => $insurance_co_pay_percentage,
                        ':deductible_amount' => $insurance_deductible,
                        ':deductible_remaining' => $insurance_deductible_remaining,
                        ':max_coverage_per_year' => $insurance_max_coverage_per_year,
                        ':max_coverage_per_admission' => $insurance_max_coverage_per_admission,
                        ':annual_deductible_remaining' => $insurance_annual_deductible_remaining,
                        ':out_of_pocket_max' => $insurance_out_of_pocket_max,
                        ':out_of_pocket_year_to_date' => $insurance_out_of_pocket_year_to_date,
                        ':effective_from' => $insurance_valid_from,
                        ':effective_to' => $insurance_valid_to,
                        ':benefit_year_start' => $insurance_benefit_year_start,
                        ':benefit_year_end' => $insurance_benefit_year_end,
                        ':status' => $insurance_status,
                        ':is_primary' => $insurance_is_primary,
                        ':patient_id' => $patient_id
                    ]);
                } else {
                    // Insert new
                    $insSql = "
                        INSERT INTO patient_insurance_profiles (
                            patient_id, insurance_provider_id, policy_number, member_id, group_number,
                            coverage_percentage, co_pay_percentage, deductible_amount, deductible_remaining,
                            max_coverage_per_year, max_coverage_per_admission,
                            annual_deductible_remaining, out_of_pocket_max, out_of_pocket_year_to_date,
                            effective_from, effective_to, benefit_year_start, benefit_year_end,
                            status, is_primary, created_by_user_id, created_at
                        ) VALUES (
                            :patient_id, :provider_id, :policy_number, :member_id, :group_number,
                            :coverage_percentage, :co_pay_percentage, :deductible_amount, :deductible_remaining,
                            :max_coverage_per_year, :max_coverage_per_admission,
                            :annual_deductible_remaining, :out_of_pocket_max, :out_of_pocket_year_to_date,
                            :effective_from, :effective_to, :benefit_year_start, :benefit_year_end,
                            :status, :is_primary, :user_id, NOW()
                        )
                    ";
                    $insStmt = $pdo->prepare($insSql);
                    $insStmt->execute([
                        ':patient_id' => $patient_id,
                        ':provider_id' => $insurance_provider_id,
                        ':policy_number' => $insurance_number,
                        ':member_id' => $insurance_member_id ?: null,
                        ':group_number' => $insurance_group_number ?: null,
                        ':coverage_percentage' => $insurance_coverage_percentage,
                        ':co_pay_percentage' => $insurance_co_pay_percentage,
                        ':deductible_amount' => $insurance_deductible,
                        ':deductible_remaining' => $insurance_deductible_remaining,
                        ':max_coverage_per_year' => $insurance_max_coverage_per_year,
                        ':max_coverage_per_admission' => $insurance_max_coverage_per_admission,
                        ':annual_deductible_remaining' => $insurance_annual_deductible_remaining,
                        ':out_of_pocket_max' => $insurance_out_of_pocket_max,
                        ':out_of_pocket_year_to_date' => $insurance_out_of_pocket_year_to_date,
                        ':effective_from' => $insurance_valid_from,
                        ':effective_to' => $insurance_valid_to,
                        ':benefit_year_start' => $insurance_benefit_year_start,
                        ':benefit_year_end' => $insurance_benefit_year_end,
                        ':status' => $insurance_status,
                        ':is_primary' => $insurance_is_primary,
                        ':user_id' => $user_id
                    ]);
                }
            } else {
                // No insurance - soft delete existing
                if (!empty($patient['insurance_profile_id'])) {
                    $delStmt = $pdo->prepare("UPDATE patient_insurance_profiles SET deleted_at = NOW() WHERE patient_id = :patient_id AND deleted_at IS NULL");
                    $delStmt->execute([':patient_id' => $patient_id]);
                }
            }
            
            // 3. Log to audit trail (try-catch to prevent failure)
            try {
                if (!empty($old_data)) {
                    $auditSql = "
                        INSERT INTO audit_logs (
                            branch_id, user_id, action_type, target_table, record_id,
                            old_values, new_values, ip_address, user_agent, created_at
                        ) VALUES (
                            :branch_id, :user_id, 'UPDATE', 'patients', :record_id,
                            :old_values, :new_values, :ip, :agent, NOW()
                        )
                    ";
                    $auditStmt = $pdo->prepare($auditSql);
                    $auditStmt->execute([
                        ':branch_id' => $user_branch_id,
                        ':user_id' => $user_id,
                        ':record_id' => $patient_id,
                        ':old_values' => json_encode($old_data),
                        ':new_values' => json_encode(array_merge($old_data, [
                            'first_name' => $first_name,
                            'last_name' => $last_name,
                            'middle_name' => $middle_name,
                            'phone' => $phone,
                            'email' => $email,
                            'national_id' => $national_id,
                            'gender' => $gender,
                            'address' => $address,
                            'date_of_birth' => $date_of_birth,
                            'blood_group' => $blood_group,
                            'emergency_contact_name' => $emergency_contact_name,
                            'is_free_treatment_eligible' => $is_free_treatment_eligible,
                            'is_active' => $is_active
                        ])),
                        ':ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                        ':agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
                    ]);
                }
            } catch (Exception $e) {
                // Audit table may not exist or have different structure, continue
                error_log("Audit log failed (non-critical): " . $e->getMessage());
            }
            
            $pdo->commit();
            $success_message = "Patient information updated successfully!";
            
            // Refresh patient data for display
            $refreshStmt = $pdo->prepare("SELECT * FROM patients WHERE id = :patient_id AND deleted_at IS NULL");
            $refreshStmt->execute([':patient_id' => $patient_id]);
            $refreshed = $refreshStmt->fetch(PDO::FETCH_ASSOC);
            if ($refreshed) {
                foreach ($refreshed as $key => $value) {
                    if (isset($patient[$key])) {
                        $patient[$key] = $value;
                    }
                }
            }
            
            // Refresh family count
            $familyCountStmt = $pdo->prepare("
                SELECT COUNT(*) FROM patient_relationships 
                WHERE (primary_patient_id = :id OR related_patient_id = :id) 
                AND deleted_at IS NULL
            ");
            $familyCountStmt->execute([':id' => $patient_id]);
            $patient['family_count'] = (int)$familyCountStmt->fetchColumn();
            
        }
    } catch (PDOException $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $errors[] = 'Database error: ' . $e->getMessage();
        error_log("UPDATE PATIENT ERROR: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    } catch (Exception $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $errors[] = 'Error: ' . $e->getMessage();
        error_log("UPDATE PATIENT GENERAL ERROR: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    }
}

// Re-fetch data after successful update
if ($success_message) {
    try {
        $refreshSql = "
            SELECT 
                p.*,
                pip.id AS insurance_profile_id,
                pip.insurance_provider_id,
                pip.policy_number AS insurance_number,
                pip.member_id AS insurance_member_id,
                pip.group_number AS insurance_group_number,
                pip.coverage_percentage,
                pip.co_pay_percentage,
                pip.deductible_amount,
                pip.deductible_remaining,
                pip.max_coverage_per_year,
                pip.max_coverage_per_admission,
                pip.annual_deductible_remaining,
                pip.out_of_pocket_max,
                pip.out_of_pocket_year_to_date,
                pip.effective_from AS insurance_valid_from,
                pip.effective_to AS insurance_valid_to,
                pip.benefit_year_start,
                pip.benefit_year_end,
                pip.status AS insurance_status,
                pip.is_primary,
                ip.name AS insurance_provider_name
            FROM patients p
            LEFT JOIN patient_insurance_profiles pip ON p.id = pip.patient_id 
                AND pip.deleted_at IS NULL AND pip.status = 'active'
            LEFT JOIN insurance_providers ip ON pip.insurance_provider_id = ip.id 
                AND ip.deleted_at IS NULL
            WHERE p.id = :patient_id AND p.deleted_at IS NULL
        ";
        $refreshStmt = $pdo->prepare($refreshSql);
        $refreshStmt->execute([':patient_id' => $patient_id]);
        $refreshed = $refreshStmt->fetch(PDO::FETCH_ASSOC);
        if ($refreshed) {
            $patient = array_merge($patient, $refreshed);
        }
    } catch (PDOException $e) {
        error_log("Refresh error: " . $e->getMessage());
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include('assets/inc/head.php'); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Patient | <?php echo htmlspecialchars($patient['mr_number'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?></title>
    
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        :root {
            --primary: #4361ee;
            --primary-dark: #3a0ca3;
            --secondary: #06d6a0;
            --danger: #ef476f;
            --warning: #f9c74f;
            --dark: #1a1a2e;
            --gray: #6c757d;
            --card-bg: rgba(255, 255, 255, 0.95);
        }
        
        body {
            font-family: 'Inter', sans-serif;
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
        
        .page-header {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            border-radius: 24px;
            padding: 24px 32px;
            margin-bottom: 28px;
            color: white;
        }
        
        .patient-summary {
            display: flex;
            align-items: center;
            gap: 20px;
        }
        
        .patient-avatar-lg {
            width: 64px;
            height: 64px;
            border-radius: 50%;
            background: rgba(255,255,255,0.2);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
            font-weight: 700;
            color: white;
            flex-shrink: 0;
        }
        
        .form-card {
            background: var(--card-bg);
            backdrop-filter: blur(10px);
            border-radius: 24px;
            overflow: hidden;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            margin-bottom: 30px;
        }
        
        .form-card-header {
            padding: 20px 28px;
            border-bottom: 1px solid #e2e8f0;
            background: #f8fafc;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .form-card-body {
            padding: 28px;
        }
        
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
            width: 100%;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            padding: 10px 14px;
            font-size: 14px;
            transition: all 0.2s;
        }
        
        textarea.form-control {
            resize: vertical;
            min-height: 80px;
        }
        
        .form-control:focus, .custom-select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.1);
        }
        
        .section-title {
            font-size: 16px;
            font-weight: 700;
            margin: 24px 0 16px 0;
            padding-bottom: 8px;
            border-bottom: 2px solid var(--primary);
            display: inline-block;
        }
        
        .btn-save {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            border: none;
            padding: 12px 32px;
            border-radius: 40px;
            font-weight: 600;
            color: white;
            transition: all 0.2s;
        }
        
        .btn-save:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(67, 97, 238, 0.3);
            color: white;
        }
        
        .required:after {
            content: " *";
            color: var(--danger);
        }
        
        .alert-danger {
            background: #fee2e2;
            border: none;
            border-radius: 16px;
            padding: 16px 20px;
            margin-bottom: 24px;
            color: #991b1b;
        }
        
        .alert-success {
            background: #d1fae5;
            border: none;
            border-radius: 16px;
            padding: 16px 20px;
            margin-bottom: 24px;
            color: #065f46;
        }
        
        hr {
            margin: 24px 0;
            border-color: #e2e8f0;
        }
        
        .info-note {
            font-size: 12px;
            color: #6c757d;
            margin-top: 4px;
        }
        
        .row {
            display: flex;
            flex-wrap: wrap;
            margin-right: -15px;
            margin-left: -15px;
        }
        
        .col-md-1, .col-md-2, .col-md-3, .col-md-4, .col-md-5, .col-md-6, .col-md-7, .col-md-8, .col-md-9, .col-md-10, .col-md-11, .col-md-12 {
            position: relative;
            width: 100%;
            padding-right: 15px;
            padding-left: 15px;
        }
        
        @media (min-width: 768px) {
            .col-md-1 { flex: 0 0 8.333333%; max-width: 8.333333%; }
            .col-md-2 { flex: 0 0 16.666667%; max-width: 16.666667%; }
            .col-md-3 { flex: 0 0 25%; max-width: 25%; }
            .col-md-4 { flex: 0 0 33.333333%; max-width: 33.333333%; }
            .col-md-5 { flex: 0 0 41.666667%; max-width: 41.666667%; }
            .col-md-6 { flex: 0 0 50%; max-width: 50%; }
            .col-md-7 { flex: 0 0 58.333333%; max-width: 58.333333%; }
            .col-md-8 { flex: 0 0 66.666667%; max-width: 66.666667%; }
            .col-md-9 { flex: 0 0 75%; max-width: 75%; }
            .col-md-10 { flex: 0 0 83.333333%; max-width: 83.333333%; }
            .col-md-11 { flex: 0 0 91.666667%; max-width: 91.666667%; }
            .col-md-12 { flex: 0 0 100%; max-width: 100%; }
        }
        
        .mt-2 { margin-top: 0.5rem; }
        .mt-3 { margin-top: 1rem; }
        .mt-4 { margin-top: 1.5rem; }
        .mb-0 { margin-bottom: 0; }
        .mb-3 { margin-bottom: 1rem; }
        .ms-2 { margin-left: 0.5rem; }
        .me-1 { margin-right: 0.25rem; }
        .me-2 { margin-right: 0.5rem; }
        .text-end { text-align: right; }
        .text-muted { color: #6c757d; }
        .text-primary { color: var(--primary); }
        .small { font-size: 0.875rem; }
        .d-flex { display: flex; }
        .justify-content-between { justify-content: space-between; }
        .align-items-center { align-items: center; }
        .flex-wrap { flex-wrap: wrap; }
        .gap-2 { gap: 0.5rem; }
        
        .form-check {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .form-check-input {
            width: 18px;
            height: 18px;
            margin: 0;
        }
        
        .btn-outline-secondary {
            border: 1px solid #e2e8f0;
            background: white;
            padding: 10px 24px;
            border-radius: 40px;
            font-weight: 500;
            transition: all 0.2s;
        }
        
        .btn-outline-secondary:hover {
            background: #f8f9fa;
            border-color: var(--primary);
        }
        
        .btn-outline-secondary i {
            margin-right: 6px;
        }
        
        .stat-badge {
            display: inline-block;
            background: rgba(255,255,255,0.2);
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            margin: 2px;
            color: white;
        }
        
        .stat-badge .num {
            font-weight: 700;
        }
        
        .family-member-tag {
            display: inline-flex;
            align-items: center;
            background: #f0f4ff;
            padding: 4px 12px 4px 8px;
            border-radius: 20px;
            font-size: 12px;
            margin: 2px;
        }
        
        .family-member-tag .avatar-sm {
            width: 24px;
            height: 24px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea, #764ba2);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 10px;
            margin-right: 6px;
        }
        
        .badge-info {
            background: #e0f0fa;
            color: #0c4a6e;
            padding: 1px 8px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: 600;
        }
        
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
        
        .loading-overlay.active { display: flex; }
        
        .loading-spinner {
            background: white;
            padding: 25px 35px;
            border-radius: 20px;
            text-align: center;
            box-shadow: 0 8px 40px rgba(0,0,0,0.12);
        }
        
        .loading-spinner .spinner-border { width: 40px; height: 40px; }
        
        @media (max-width: 576px) {
            .patient-summary { flex-direction: column; text-align: center; }
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
                    <p class="mt-2 mb-0">Saving changes...</p>
                </div>
            </div>
            
            <!-- Page Header -->
            <div class="page-header">
                <div class="d-flex justify-content-between align-items-center flex-wrap">
                    <div class="patient-summary">
                        <div class="patient-avatar-lg"><?php echo htmlspecialchars($initials, ENT_QUOTES, 'UTF-8'); ?></div>
                        <div>
                            <h4 class="mb-1"><i class="fas fa-user-edit me-2"></i> Edit Patient</h4>
                            <p class="mb-0 opacity-75">
                                MRN: <?php echo htmlspecialchars($patient['mr_number'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?> 
                                • Age: <?php echo $age; ?> years
                            </p>
                            <div class="mt-1">
                                <span class="stat-badge"><i class="fas fa-users me-1"></i> Family: <span class="num"><?php echo $patient['family_count'] ?? 0; ?></span></span>
                                <span class="stat-badge"><i class="fas fa-file-invoice me-1"></i> Visits: <span class="num"><?php echo $patient['visit_count'] ?? 0; ?></span></span>
                                <span class="stat-badge"><i class="fas fa-stethoscope me-1"></i> Consultations: <span class="num"><?php echo $patient['consultation_count'] ?? 0; ?></span></span>
                                <?php if (($patient['total_due'] ?? 0) > 0): ?>
                                <span class="stat-badge" style="background:#fee2e2;color:#991b1b;">
                                    <i class="fas fa-exclamation-triangle me-1"></i> Due: ETB <?php echo number_format($patient['total_due'] ?? 0, 2); ?>
                                </span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="mt-2 mt-md-0">
                        <a href="patient_directory.php" class="btn btn-outline-light btn-sm">
                            <i class="fas fa-arrow-left me-1"></i> Back to Directory
                        </a>
                        <a href="patient_history.php?id=<?php echo $patient_id; ?>" class="btn btn-outline-light btn-sm ms-2">
                            <i class="fas fa-user me-1"></i> View Profile
                        </a>
                    </div>
                </div>
            </div>
            
            <!-- Alerts -->
            <?php if ($success_message): ?>
                <div class="alert-success">
                    <i class="fas fa-check-circle me-2"></i> <?php echo htmlspecialchars($success_message, ENT_QUOTES, 'UTF-8'); ?>
                </div>
            <?php endif; ?>
            
            <?php if (!empty($errors)): ?>
                <div class="alert-danger">
                    <strong><i class="fas fa-exclamation-triangle me-2"></i> Please fix the following:</strong>
                    <ul class="mb-0 mt-2">
                        <?php foreach ($errors as $e): ?>
                            <li><?php echo htmlspecialchars($e, ENT_QUOTES, 'UTF-8'); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
            
            <!-- Update Form -->
            <form method="POST" class="form-card" id="updateForm">
                <div class="form-card-header">
                    <h6 class="mb-0"><i class="fas fa-user me-2 text-primary"></i> Personal Information</h6>
                    <span class="text-muted small">Last updated: <?php echo date('M d, Y H:i', strtotime($patient['updated_at'] ?? 'now')); ?></span>
                </div>
                <div class="form-card-body">
                    <input type="hidden" name="do_update" value="1">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                    
                    <!-- Personal Information -->
                    <div class="row">
                        <div class="col-md-4">
                            <div class="form-group">
                                <label class="required">First Name</label>
                                <input class="form-control" name="first_name" value="<?php echo htmlspecialchars($patient['first_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" required>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Middle Name</label>
                                <input class="form-control" name="middle_name" value="<?php echo htmlspecialchars($patient['middle_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label class="required">Last Name</label>
                                <input class="form-control" name="last_name" value="<?php echo htmlspecialchars($patient['last_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" required>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-3">
                            <div class="form-group">
                                <label class="required">MRN</label>
                                <input class="form-control" name="mr_number" value="<?php echo htmlspecialchars($patient['mr_number'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" required>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label>Gender</label>
                                <select class="custom-select" name="gender">
                                    <option value="">Select</option>
                                    <option value="male" <?php echo ($patient['gender'] ?? '')==='male'?'selected':''; ?>>Male</option>
                                    <option value="female" <?php echo ($patient['gender'] ?? '')==='female'?'selected':''; ?>>Female</option>
                                    <option value="other" <?php echo ($patient['gender'] ?? '')==='other'?'selected':''; ?>>Other</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label>Date of Birth</label>
                                <input type="date" class="form-control" name="date_of_birth" value="<?php echo htmlspecialchars($patient['date_of_birth'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label>Blood Group</label>
                                <select class="custom-select" name="blood_group">
                                    <option value="">Select</option>
                                    <option value="A+" <?php echo ($patient['blood_group'] ?? '')==='A+'?'selected':''; ?>>A+</option>
                                    <option value="A-" <?php echo ($patient['blood_group'] ?? '')==='A-'?'selected':''; ?>>A-</option>
                                    <option value="B+" <?php echo ($patient['blood_group'] ?? '')==='B+'?'selected':''; ?>>B+</option>
                                    <option value="B-" <?php echo ($patient['blood_group'] ?? '')==='B-'?'selected':''; ?>>B-</option>
                                    <option value="AB+" <?php echo ($patient['blood_group'] ?? '')==='AB+'?'selected':''; ?>>AB+</option>
                                    <option value="AB-" <?php echo ($patient['blood_group'] ?? '')==='AB-'?'selected':''; ?>>AB-</option>
                                    <option value="O+" <?php echo ($patient['blood_group'] ?? '')==='O+'?'selected':''; ?>>O+</option>
                                    <option value="O-" <?php echo ($patient['blood_group'] ?? '')==='O-'?'selected':''; ?>>O-</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Phone</label>
                                <input class="form-control" name="phone" value="<?php echo htmlspecialchars($patient['phone'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="09XXXXXXXX">
                                <div class="info-note">Ethiopian format: 09XXXXXXXX</div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Email</label>
                                <input type="email" class="form-control" name="email" value="<?php echo htmlspecialchars($patient['email'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>National ID</label>
                                <input class="form-control" name="national_id" value="<?php echo htmlspecialchars($patient['national_id'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>Address</label>
                        <textarea class="form-control" name="address" rows="2"><?php echo htmlspecialchars($patient['address'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
                    </div>
                    
                    <hr>
                    
                    <!-- Emergency Contact -->
                    <h6 class="section-title"><i class="fas fa-phone-alt me-2 text-primary"></i> Emergency Contact</h6>
                    <div class="row">
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Emergency Contact Name</label>
                                <input class="form-control" name="emergency_contact_name" value="<?php echo htmlspecialchars($patient['emergency_contact_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Emergency Contact Phone</label>
                                <input class="form-control" name="emergency_contact_phone" value="<?php echo htmlspecialchars($patient['emergency_contact_phone'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="09XXXXXXXX">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Relationship</label>
                                <input class="form-control" name="emergency_contact_relationship" value="<?php echo htmlspecialchars($patient['emergency_contact_relationship'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="e.g., Spouse, Parent">
                            </div>
                        </div>
                    </div>
                    
                    <hr>
                    
                    <!-- Insurance Details -->
                    <h6 class="section-title"><i class="fas fa-shield-alt me-2 text-primary"></i> Insurance Details</h6>
                    <div class="row">
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Insurance Provider</label>
                                <select class="custom-select" name="insurance_provider_id" id="insuranceProvider">
                                    <option value="">No Insurance / Private</option>
                                    <?php foreach ($insurance_providers as $provider): ?>
                                        <option value="<?php echo $provider['id']; ?>" 
                                            <?php echo ($patient['insurance_provider_id'] ?? '') == $provider['id'] ? 'selected' : ''; ?>
                                            data-coverage="<?php echo $provider['default_coverage_percentage'] ?? ''; ?>"
                                            data-copay="<?php echo $provider['default_co_pay_percentage'] ?? ''; ?>">
                                            <?php echo htmlspecialchars($provider['name'], ENT_QUOTES, 'UTF-8'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Policy Number</label>
                                <input class="form-control" name="insurance_number" value="<?php echo htmlspecialchars($patient['insurance_number'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Member ID</label>
                                <input class="form-control" name="insurance_member_id" value="<?php echo htmlspecialchars($patient['insurance_member_id'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-3">
                            <div class="form-group">
                                <label>Group Number</label>
                                <input class="form-control" name="insurance_group_number" value="<?php echo htmlspecialchars($patient['insurance_group_number'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label>Coverage (%)</label>
                                <input type="number" step="0.01" min="0" max="100" class="form-control" name="insurance_coverage_percentage" 
                                    value="<?php echo htmlspecialchars($patient['coverage_percentage'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label>Co-pay (%)</label>
                                <input type="number" min="0" max="100" class="form-control" name="insurance_co_pay_percentage" 
                                    value="<?php echo htmlspecialchars($patient['co_pay_percentage'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label>Deductible (ETB)</label>
                                <input type="number" step="0.01" min="0" class="form-control" name="insurance_deductible" 
                                    value="<?php echo htmlspecialchars($patient['deductible_amount'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-3">
                            <div class="form-group">
                                <label>Deductible Remaining</label>
                                <input type="number" step="0.01" min="0" class="form-control" name="insurance_deductible_remaining" 
                                    value="<?php echo htmlspecialchars($patient['deductible_remaining'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label>Max Coverage/Year</label>
                                <input type="number" step="0.01" min="0" class="form-control" name="insurance_max_coverage_per_year" 
                                    value="<?php echo htmlspecialchars($patient['max_coverage_per_year'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label>Max Coverage/Admission</label>
                                <input type="number" step="0.01" min="0" class="form-control" name="insurance_max_coverage_per_admission" 
                                    value="<?php echo htmlspecialchars($patient['max_coverage_per_admission'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label>Out of Pocket Max</label>
                                <input type="number" step="0.01" min="0" class="form-control" name="insurance_out_of_pocket_max" 
                                    value="<?php echo htmlspecialchars($patient['out_of_pocket_max'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-3">
                            <div class="form-group">
                                <label>Valid From</label>
                                <input type="date" class="form-control" name="insurance_valid_from" 
                                    value="<?php echo htmlspecialchars($patient['insurance_valid_from'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label>Valid To</label>
                                <input type="date" class="form-control" name="insurance_valid_to" 
                                    value="<?php echo htmlspecialchars($patient['insurance_valid_to'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label>Benefit Year Start</label>
                                <input type="date" class="form-control" name="insurance_benefit_year_start" 
                                    value="<?php echo htmlspecialchars($patient['benefit_year_start'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label>Benefit Year End</label>
                                <input type="date" class="form-control" name="insurance_benefit_year_end" 
                                    value="<?php echo htmlspecialchars($patient['benefit_year_end'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Insurance Status</label>
                                <select class="custom-select" name="insurance_status">
                                    <option value="active" <?php echo ($patient['insurance_status'] ?? '') === 'active' ? 'selected' : ''; ?>>Active</option>
                                    <option value="expired" <?php echo ($patient['insurance_status'] ?? '') === 'expired' ? 'selected' : ''; ?>>Expired</option>
                                    <option value="suspended" <?php echo ($patient['insurance_status'] ?? '') === 'suspended' ? 'selected' : ''; ?>>Suspended</option>
                                    <option value="cancelled" <?php echo ($patient['insurance_status'] ?? '') === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <div class="form-check mt-4">
                                    <input type="checkbox" class="form-check-input" id="insurance_is_primary" name="insurance_is_primary" 
                                        <?php echo ($patient['is_primary'] ?? 0) == 1 ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="insurance_is_primary">Primary Insurance</label>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <hr>
                    
                    <!-- Free Treatment -->
                    <h6 class="section-title"><i class="fas fa-hand-holding-heart me-2 text-primary"></i> Free Treatment Eligibility</h6>
                    <div class="row">
                        <div class="col-md-3">
                            <div class="form-check mt-2">
                                <input type="checkbox" class="form-check-input" id="is_free_treatment_eligible" name="is_free_treatment_eligible" 
                                    <?php echo ($patient['is_free_treatment_eligible'] ?? 0) == 1 ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="is_free_treatment_eligible">Eligible for Free Treatment</label>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label>Letter Number</label>
                                <input class="form-control" name="free_treatment_letter_number" 
                                    value="<?php echo htmlspecialchars($patient['free_treatment_letter_number'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label>Issued By</label>
                                <input class="form-control" name="free_treatment_letter_issued_by" 
                                    value="<?php echo htmlspecialchars($patient['free_treatment_letter_issued_by'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label>Valid To</label>
                                <input type="date" class="form-control" name="free_treatment_valid_to" 
                                    value="<?php echo htmlspecialchars($patient['free_treatment_valid_to'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                        </div>
                    </div>
                    
                    <hr>
                    
                    <!-- Status -->
                    <h6 class="section-title"><i class="fas fa-toggle-on me-2 text-primary"></i> Status</h6>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-check">
                                <input type="checkbox" class="form-check-input" id="is_active" name="is_active" 
                                    <?php echo ($patient['is_active'] ?? 0) == 1 ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="is_active">Active Patient</label>
                                <div class="small text-muted mt-1">Inactive patients will not appear in search results</div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-check">
                                <input type="checkbox" class="form-check-input" id="is_consent_given" name="is_consent_given" 
                                    <?php echo ($patient['is_consent_given'] ?? 0) == 1 ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="is_consent_given">Consent Given</label>
                                <div class="small text-muted mt-1">Patient consent for treatment and data processing</div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Family Members (Display Only) -->
                    <?php if (!empty($family_members)): ?>
                    <hr>
                    <h6 class="section-title"><i class="fas fa-users me-2 text-primary"></i> Family Members (<?php echo count($family_members); ?>)</h6>
                    <div class="mb-3">
                        <?php foreach ($family_members as $member): ?>
                            <span class="family-member-tag">
                                <span class="avatar-sm"><?php echo strtoupper(substr($member['relative_name'] ?? 'U', 0, 1)); ?></span>
                                <?php echo htmlspecialchars($member['relative_name'] ?? 'Unknown', ENT_QUOTES, 'UTF-8'); ?>
                                <span class="text-muted ms-1">(<?php echo htmlspecialchars($member['relationship_type'] ?? '—', ENT_QUOTES, 'UTF-8'); ?>)</span>
                                <?php if ($member['is_emergency_contact']): ?>
                                    <span class="badge-info ms-1" style="font-size:9px;">Emergency</span>
                                <?php endif; ?>
                            </span>
                        <?php endforeach; ?>
                        <?php if ($can_manage_family): ?>
                            <a href="patient_history.php?id=<?php echo $patient_id; ?>#family" class="btn btn-sm btn-outline-secondary ms-2">
                                <i class="fas fa-edit me-1"></i> Manage
                            </a>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Submit Buttons -->
                    <div class="text-end mt-4">
                        <a href="patient_directory.php" class="btn btn-outline-secondary me-2">
                            <i class="fas fa-times me-1"></i> Cancel
                        </a>
                        <button type="submit" class="btn-save" id="submitBtn">
                            <i class="fas fa-save me-2"></i> Save Changes
                        </button>
                    </div>
                </div>
            </form>
            
        </div>
    </div>
    
    <?php include('assets/inc/footer.php'); ?>
</div>

<script src="assets/js/vendor.min.js"></script>
<script src="assets/js/app.min.js"></script>

<script>
// Auto-fill insurance fields when provider is selected
document.getElementById('insuranceProvider')?.addEventListener('change', function() {
    const selected = this.options[this.selectedIndex];
    const coverage = selected.dataset.coverage;
    const copay = selected.dataset.copay;
    
    if (coverage) {
        const coverageInput = document.querySelector('input[name="insurance_coverage_percentage"]');
        if (coverageInput && !coverageInput.value) {
            coverageInput.value = coverage;
        }
    }
    if (copay) {
        const copayInput = document.querySelector('input[name="insurance_co_pay_percentage"]');
        if (copayInput && !copayInput.value) {
            copayInput.value = copay;
        }
    }
});

// Show loading overlay on form submit
document.getElementById('updateForm')?.addEventListener('submit', function() {
    const overlay = document.getElementById('loadingOverlay');
    if (overlay) {
        overlay.classList.add('active');
    }
});

// Auto-validate phone number on input
document.querySelector('input[name="phone"]')?.addEventListener('input', function() {
    this.value = this.value.replace(/[^0-9+]/g, '');
});

<?php if ($success_message): ?>
// Show success message with auto-redirect
Swal.fire({
    icon: 'success',
    title: 'Success',
    text: '<?php echo htmlspecialchars($success_message, ENT_QUOTES, 'UTF-8'); ?>',
    confirmButtonColor: '#4361ee',
    timer: 3000,
    timerProgressBar: true
}).then(() => {
    window.location.href = 'patient_history.php?id=<?php echo $patient_id; ?>';
});
<?php endif; ?>

// Form validation before submit
document.getElementById('submitBtn')?.addEventListener('click', function(e) {
    const firstName = document.querySelector('input[name="first_name"]')?.value?.trim();
    const lastName = document.querySelector('input[name="last_name"]')?.value?.trim();
    const mrn = document.querySelector('input[name="mr_number"]')?.value?.trim();
    const phone = document.querySelector('input[name="phone"]')?.value?.trim();
    
    if (!firstName || !lastName) {
        e.preventDefault();
        Swal.fire('Error', 'First name and last name are required', 'error');
        return;
    }
    
    if (!mrn) {
        e.preventDefault();
        Swal.fire('Error', 'MRN is required', 'error');
        return;
    }
    
    if (phone && !/^[0-9+]+$/.test(phone)) {
        e.preventDefault();
        Swal.fire('Error', 'Phone number contains invalid characters', 'error');
        return;
    }
});
</script>

</body>
</html>