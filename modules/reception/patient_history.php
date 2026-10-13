<?php
/**
 * Patient History - HMS ERP System
 * Complete with ALL database features integrated
 * 
 * @package HMS-ERP
 * @version 3.0
 */

// Start session before any output
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Enable error reporting for debugging (remove in production)
error_reporting(E_ALL);
ini_set('display_errors', 0);

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

// Get patient ID from request
$patient_id = (int)($_GET['id'] ?? 0);

if ($patient_id <= 0) {
    header('Location: patient_directory.php?error=invalid_id');
    exit;
}

// Permission checks
$can_view_patient = $is_global_admin;
$can_edit_patient = $is_global_admin;
$can_view_financial = $is_global_admin;
$can_view_medical = $is_global_admin;
$can_manage_kyc = $is_global_admin;
$can_manage_family = $is_global_admin;
$can_view_insurance = $is_global_admin;
$can_manage_insurance = $is_global_admin;

if (!$is_global_admin && $user_id > 0) {
    $can_view_patient = user_has_permission($pdo, 'patient_view_access', $user_id);
    $can_edit_patient = user_has_permission($pdo, 'patient_edit_access', $user_id);
    $can_view_financial = user_has_permission($pdo, 'view_billing_info', $user_id);
    $can_view_medical = user_has_permission($pdo, 'view_patient_history', $user_id);
    $can_manage_kyc = user_has_permission($pdo, 'patient_kyc_manage_access', $user_id);
    $can_manage_family = user_has_permission($pdo, 'patient_family_manage_access', $user_id);
    $can_view_insurance = user_has_permission($pdo, 'insurance_view_access', $user_id);
    $can_manage_insurance = user_has_permission($pdo, 'insurance_manage_access', $user_id);
}

// CSRF Token setup for AJAX calls
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

function json_exit(array $payload, int $code = 200): void {
    if (ob_get_length()) ob_clean();
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function getStatusBadge($status, $type = 'default') {
    $classes = [
        'active' => 'badge-success',
        'inactive' => 'badge-secondary',
        'admitted' => 'badge-info',
        'discharged' => 'badge-success',
        'pending' => 'badge-warning',
        'completed' => 'badge-success',
        'cancelled' => 'badge-danger',
        'paid' => 'badge-success',
        'unpaid' => 'badge-danger',
        'partial' => 'badge-warning',
        'verified' => 'badge-success',
        'rejected' => 'badge-danger',
        'expired' => 'badge-danger',
        'scheduled' => 'badge-info',
        'in_progress' => 'badge-warning',
        'finalized' => 'badge-success',
        'draft' => 'badge-secondary',
        'approved' => 'badge-success',
        'reported' => 'badge-success',
        'dispensed' => 'badge-success',
        'urgent' => 'badge-danger',
        'stat' => 'badge-danger',
        'routine' => 'badge-info',
        'improved' => 'badge-success',
        'stabilized' => 'badge-info',
        'relieved' => 'badge-success',
        'ama' => 'badge-warning',
        'expired' => 'badge-danger',
        'transferred' => 'badge-info'
    ];
    
    $class = $classes[$status] ?? 'badge-secondary';
    return '<span class="badge ' . $class . '">' . ucfirst(str_replace('_', ' ', $status)) . '</span>';
}

function getPriorityBadge($priority) {
    $colors = [
        'red' => 'priority-red',
        'yellow' => 'priority-yellow',
        'green' => 'priority-green',
        'blue' => 'priority-blue',
        'urgent' => 'priority-red',
        'stat' => 'priority-red',
        'routine' => 'priority-green'
    ];
    $class = $colors[strtolower($priority)] ?? 'priority-green';
    return '<span class="' . $class . '">' . strtoupper($priority) . '</span>';
}

function formatDate($date) {
    if (!$date || $date === '0000-00-00' || $date === '0000-00-00 00:00:00') return 'N/A';
    try {
        $d = new DateTime($date);
        return $d->format('M d, Y H:i');
    } catch (Exception $e) {
        return $date;
    }
}

function formatDateShort($date) {
    if (!$date || $date === '0000-00-00') return 'N/A';
    try {
        $d = new DateTime($date);
        return $d->format('M d, Y');
    } catch (Exception $e) {
        return $date;
    }
}

// ============================================
// FETCH PATIENT DATA WITH ALL RELATED INFO
// ============================================
$patient = null;

try {
    $sql = "SELECT 
                p.id, 
                p.branch_id, 
                p.mr_number, 
                p.first_name, 
                p.middle_name, 
                p.last_name,
                p.date_of_birth, 
                p.gender, 
                p.phone, 
                p.email, 
                p.address, 
                p.national_id, 
                p.blood_group, 
                p.is_active,
                p.emergency_contact_name, 
                p.emergency_contact_phone, 
                p.emergency_contact_relationship,
                p.is_free_treatment_eligible,
                p.free_treatment_letter_number,
                p.free_treatment_letter_issued_by,
                p.free_treatment_valid_from,
                p.free_treatment_valid_to,
                p.created_at,
                b.name AS branch_name,
                TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) AS age
            FROM patients p
            LEFT JOIN branches b ON b.id = p.branch_id
            WHERE p.id = :patient_id AND p.deleted_at IS NULL";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':patient_id' => $patient_id]);
    $patient = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$patient) {
        header('Location: patient_directory.php?error=not_found');
        exit;
    }
    
    // Branch access control
    if (!$is_global_admin && $user_branch_id > 0 && $patient['branch_id'] != $user_branch_id) {
        header('Location: patient_directory.php?error=access_denied');
        exit;
    }
    
} catch (PDOException $e) {
    error_log("Patient fetch error: " . $e->getMessage());
    die('Error loading patient data: ' . $e->getMessage());
}

// Full name
$full_name = trim(
    ($patient['first_name'] ?? '') . ' ' . 
    ($patient['middle_name'] ?? '') . ' ' . 
    ($patient['last_name'] ?? '')
);
if (empty(trim($full_name))) {
    $full_name = 'Unknown Patient';
}

// Age
$age = $patient['age'] ?? 'N/A';

// ============================================
// FETCH INSURANCE PROFILES (ALL ACTIVE)
// ============================================
$insurance_profiles = [];
$insurance_badge = '';

try {
    $stmt = $pdo->prepare("
        SELECT 
            pip.id,
            pip.insurance_provider_id,
            ip.name AS provider_name,
            ip.code AS provider_code,
            pip.policy_number,
            pip.member_id,
            pip.group_number,
            pip.coverage_percentage,
            pip.co_pay_percentage,
            pip.deductible_amount,
            pip.deductible_remaining,
            pip.max_coverage_per_year,
            pip.max_coverage_per_admission,
            pip.annual_deductible_remaining,
            pip.out_of_pocket_max,
            pip.out_of_pocket_year_to_date,
            pip.effective_from,
            pip.effective_to,
            pip.benefit_year_start,
            pip.benefit_year_end,
            pip.status,
            pip.is_primary,
            pip.verified_at
        FROM patient_insurance_profiles pip
        LEFT JOIN insurance_providers ip ON pip.insurance_provider_id = ip.id
        WHERE pip.patient_id = :patient_id 
        AND pip.deleted_at IS NULL
        AND pip.status = 'active'
        ORDER BY pip.is_primary DESC, pip.effective_from DESC
    ");
    $stmt->execute([':patient_id' => $patient_id]);
    $insurance_profiles = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Generate insurance badge
    if (!empty($insurance_profiles)) {
        $primary = array_filter($insurance_profiles, function($i) { return $i['is_primary'] == 1; });
        $primary = !empty($primary) ? reset($primary) : $insurance_profiles[0];
        
        if (!empty($primary['effective_to']) && $primary['effective_to'] !== '0000-00-00') {
            $valid_to = $primary['effective_to'];
            $today = date('Y-m-d');
            $in30 = date('Y-m-d', strtotime('+30 days'));
            if ($valid_to < $today) {
                $insurance_badge = '<span class="status-badge status-expired"><i class="fas fa-shield-alt me-1"></i>Insurance Expired</span>';
            } elseif ($valid_to <= $in30) {
                $insurance_badge = '<span class="status-badge status-expiring"><i class="fas fa-shield-alt me-1"></i>Insurance Expiring</span>';
            } else {
                $insurance_badge = '<span class="status-badge status-insured"><i class="fas fa-shield-alt me-1"></i>Insured</span>';
            }
        } elseif (!empty($primary['provider_name'])) {
            $insurance_badge = '<span class="status-badge status-insured"><i class="fas fa-shield-alt me-1"></i>' . escape_html($primary['provider_name']) . '</span>';
        }
    }
} catch (PDOException $e) {
    error_log("Insurance profiles fetch error: " . $e->getMessage());
}

// ============================================
// FETCH ACTIVE ADMISSION WITH BED DETAILS
// ============================================
$active_admission = null;

try {
    $stmt = $pdo->prepare("
        SELECT 
            a.id,
            a.admission_number,
            a.admission_type,
            a.admitted_at,
            a.primary_diagnosis,
            a.condition_status,
            a.status,
            a.admission_notes,
            b.bed_number,
            b.bed_type,
            b.daily_rate AS bed_rate,
            r.room_number,
            r.room_type,
            w.name AS ward_name,
            w.ward_type,
            CONCAT(u.first_name, ' ', u.last_name) AS admitting_doctor
        FROM admissions a
        LEFT JOIN beds b ON a.bed_id = b.id
        LEFT JOIN rooms r ON b.room_id = r.id
        LEFT JOIN wards w ON r.ward_id = w.id
        LEFT JOIN users u ON a.doctor_id = u.id
        WHERE a.patient_id = :patient_id 
        AND a.status = 'admitted' 
        AND a.deleted_at IS NULL
        LIMIT 1
    ");
    $stmt->execute([':patient_id' => $patient_id]);
    $active_admission = $stmt->fetch(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("Admission fetch error: " . $e->getMessage());
}

// ============================================
// FETCH FAMILY LINKAGE
// ============================================
$family_members = [];
$family_count = 0;
$family_due_flag = 0;

try {
    $stmt = $pdo->prepare("
        SELECT 
            pr.id,
            pr.relationship_type,
            pr.is_emergency_contact,
            pr.notes,
            CASE 
                WHEN pr.primary_patient_id = :patient_id1 THEN pr.related_patient_id
                ELSE pr.primary_patient_id
            END AS relative_id,
            CASE 
                WHEN pr.primary_patient_id = :patient_id2 THEN 'primary'
                ELSE 'related'
            END AS direction,
            CONCAT(p.first_name, ' ', COALESCE(p.middle_name, ''), ' ', p.last_name) AS relative_name,
            p.mr_number AS relative_mrn,
            p.phone AS relative_phone,
            p.is_active AS relative_active,
            COALESCE(inv.due, 0) AS relative_due
        FROM patient_relationships pr
        INNER JOIN patients p ON (
            (pr.primary_patient_id = :patient_id3 AND p.id = pr.related_patient_id) OR
            (pr.related_patient_id = :patient_id4 AND p.id = pr.primary_patient_id)
        )
        LEFT JOIN (
            SELECT patient_id, SUM(CASE WHEN payment_status IN ('unpaid', 'partial') THEN due_amount ELSE 0 END) AS due
            FROM invoices WHERE deleted_at IS NULL GROUP BY patient_id
        ) inv ON inv.patient_id = p.id
        WHERE (pr.primary_patient_id = :patient_id5 OR pr.related_patient_id = :patient_id6)
        AND pr.deleted_at IS NULL
        AND p.deleted_at IS NULL
    ");
    $stmt->execute([
        ':patient_id1' => $patient_id,
        ':patient_id2' => $patient_id,
        ':patient_id3' => $patient_id,
        ':patient_id4' => $patient_id,
        ':patient_id5' => $patient_id,
        ':patient_id6' => $patient_id
    ]);
    $family_members = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $family_count = count($family_members);
    foreach ($family_members as $member) {
        if ($member['relative_due'] > 0) {
            $family_due_flag = 1;
            break;
        }
    }
} catch (PDOException $e) {
    error_log("Family linkage error: " . $e->getMessage());
}

// ============================================
// FETCH LATEST VITALS
// ============================================
$latest_vitals = null;
try {
    $vitals_stmt = $pdo->prepare("
        SELECT triage_time, blood_pressure_systolic, blood_pressure_diastolic, 
               heart_rate, temperature_celsius, oxygen_saturation, 
               respiratory_rate, pain_score, consciousness, priority, esi_level,
               chief_complaint, triage_notes, disposition, waiting_time_minutes
        FROM triage_records 
        WHERE patient_id = :patient_id AND deleted_at IS NULL 
        ORDER BY triage_time DESC LIMIT 1
    ");
    $vitals_stmt->execute([':patient_id' => $patient_id]);
    $latest_vitals = $vitals_stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Vitals fetch error: " . $e->getMessage());
}

// ============================================
// FETCH LATEST CONSULTATION
// ============================================
$last_consultation = null;
try {
    $stmt = $pdo->prepare("
        SELECT 
            c.id,
            c.consultation_date,
            c.consultation_time,
            c.consultation_type,
            c.chief_complaint,
            c.history_of_present_illness,
            c.provisional_diagnosis,
            c.final_diagnosis,
            c.treatment_plan,
            c.recommendations,
            c.status,
            c.follow_up_required,
            c.follow_up_date,
            CONCAT(u.first_name, ' ', u.last_name) AS doctor_name
        FROM consultations c
        LEFT JOIN users u ON c.doctor_id = u.id
        WHERE c.patient_id = :patient_id AND c.deleted_at IS NULL
        ORDER BY c.consultation_date DESC, c.consultation_time DESC
        LIMIT 1
    ");
    $stmt->execute([':patient_id' => $patient_id]);
    $last_consultation = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Consultation fetch error: " . $e->getMessage());
}

// ============================================
// FETCH OUTSTANDING BALANCE
// ============================================
$due_amount = 0.0;
$pending_count = 0;

try {
    $stmt = $pdo->prepare("
        SELECT 
            COALESCE(SUM(CASE WHEN payment_status IN ('unpaid', 'partial') THEN due_amount ELSE 0 END), 0) AS due,
            COALESCE(SUM(CASE WHEN payment_status = 'unpaid' THEN 1 ELSE 0 END), 0) AS pending_count
        FROM invoices 
        WHERE patient_id = :patient_id AND deleted_at IS NULL
    ");
    $stmt->execute([':patient_id' => $patient_id]);
    $ledger = $stmt->fetch(PDO::FETCH_ASSOC);
    $due_amount = (float)($ledger['due'] ?? 0);
    $pending_count = (int)($ledger['pending_count'] ?? 0);
} catch (PDOException $e) {
    error_log("Ledger fetch error: " . $e->getMessage());
}

// ============================================
// FETCH KYC DOCUMENTS COUNT
// ============================================
$kyc_count = 0;
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM patient_kyc_documents WHERE patient_id = :patient_id AND deleted_at IS NULL");
    $stmt->execute([':patient_id' => $patient_id]);
    $kyc_count = (int)$stmt->fetchColumn();
} catch (PDOException $e) {
    error_log("KYC count error: " . $e->getMessage());
}

// ============================================
// FETCH DATA FOR TABS
// ============================================

// 1. Vitals History
$vitals_data = [];
try {
    $vitals_sql = "SELECT 
                        id,
                        triage_time, 
                        blood_pressure_systolic, 
                        blood_pressure_diastolic, 
                        heart_rate, 
                        temperature_celsius, 
                        oxygen_saturation, 
                        respiratory_rate,
                        pain_score,
                        consciousness,
                        priority, 
                        esi_level,
                        chief_complaint,
                        triage_notes,
                        disposition,
                        waiting_time_minutes,
                        CONCAT(u.first_name, ' ', u.last_name) AS taken_by
                    FROM triage_records tr
                    LEFT JOIN users u ON tr.taken_by_user_id = u.id
                    WHERE tr.patient_id = :patient_id AND tr.deleted_at IS NULL 
                    ORDER BY tr.triage_time DESC
                    LIMIT 100";
    
    $vitals_stmt = $pdo->prepare($vitals_sql);
    $vitals_stmt->execute([':patient_id' => $patient_id]);
    $vitals_data = $vitals_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Vitals fetch error: " . $e->getMessage());
}

// 2. Consultations
$consultations = [];
try {
    $cons_sql = "SELECT 
                    c.id,
                    c.consultation_number,
                    c.consultation_date,
                    c.consultation_time,
                    c.consultation_type,
                    c.chief_complaint,
                    c.history_of_present_illness,
                    c.past_medical_history,
                    c.surgical_history,
                    c.family_history,
                    c.social_history,
                    c.medication_history,
                    c.allergy_history,
                    c.physical_examination,
                    c.systemic_examination,
                    c.provisional_diagnosis,
                    c.final_diagnosis,
                    c.differential_diagnosis,
                    c.clinical_impression,
                    c.assessment,
                    c.treatment_plan,
                    c.recommendations,
                    c.icd10_codes,
                    c.follow_up_required,
                    c.follow_up_date,
                    c.follow_up_instructions,
                    c.referral_required,
                    c.referral_notes,
                    c.status,
                    d.name AS department_name,
                    CONCAT(u.first_name, ' ', u.last_name) AS doctor_name
                FROM consultations c
                LEFT JOIN users u ON c.doctor_id = u.id
                LEFT JOIN departments d ON c.referral_to_department_id = d.id
                WHERE c.patient_id = :patient_id AND c.deleted_at IS NULL
                ORDER BY c.consultation_date DESC, c.consultation_time DESC
                LIMIT 100";
    
    $cons_stmt = $pdo->prepare($cons_sql);
    $cons_stmt->execute([':patient_id' => $patient_id]);
    $consultations = $cons_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Consultations fetch error: " . $e->getMessage());
}

// 3. Lab Orders
$lab_orders = [];
try {
    $lab_sql = "SELECT 
                    lo.id,
                    lo.order_number,
                    lo.order_date,
                    lo.priority,
                    lo.status,
                    lo.payment_status,
                    lo.clinical_indication,
                    lo.specimen_type,
                    lo.specimen_collected_at,
                    lo.results_ready_at,
                    lo.completed_at,
                    lo.notes,
                    ltm.test_name,
                    ltm.test_code,
                    ltm.test_category,
                    ltm.reference_range_low,
                    ltm.reference_range_high,
                    ltm.unit,
                    CONCAT(u.first_name, ' ', u.last_name) AS doctor_name,
                    CONCAT(col.first_name, ' ', col.last_name) AS collected_by
                FROM lab_orders lo
                LEFT JOIN lab_tests_master ltm ON lo.test_id = ltm.id
                LEFT JOIN users u ON lo.doctor_id = u.id
                LEFT JOIN users col ON lo.specimen_collected_by_user_id = col.id
                WHERE lo.patient_id = :patient_id AND lo.deleted_at IS NULL
                ORDER BY lo.order_date DESC, lo.created_at DESC
                LIMIT 100";
    
    $lab_stmt = $pdo->prepare($lab_sql);
    $lab_stmt->execute([':patient_id' => $patient_id]);
    $lab_orders = $lab_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Lab orders fetch error: " . $e->getMessage());
}

// 4. Lab Results (for lab tab)
$lab_results = [];
try {
    $result_sql = "SELECT 
                        ltr.id,
                        ltr.test_name,
                        ltr.test_category,
                        ltr.result_numeric,
                        ltr.result_text,
                        ltr.result_unit,
                        ltr.reference_range_low,
                        ltr.reference_range_high,
                        ltr.reference_range_text,
                        ltr.is_abnormal,
                        ltr.is_critical,
                        ltr.critical_flag_notes,
                        ltr.technician_notes,
                        ltr.verified_at,
                        lo.order_number,
                        lo.order_date,
                        CONCAT(v.first_name, ' ', v.last_name) AS verified_by
                    FROM lab_test_results ltr
                    LEFT JOIN lab_orders lo ON ltr.lab_order_id = lo.id
                    LEFT JOIN users v ON ltr.verified_by_user_id = v.id
                    WHERE lo.patient_id = :patient_id 
                    AND lo.deleted_at IS NULL
                    AND ltr.deleted_at IS NULL
                    ORDER BY lo.order_date DESC, ltr.created_at DESC
                    LIMIT 100";
    
    $result_stmt = $pdo->prepare($result_sql);
    $result_stmt->execute([':patient_id' => $patient_id]);
    $lab_results = $result_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Lab results fetch error: " . $e->getMessage());
}

// 5. Radiology Orders
$radiology_orders = [];
try {
    $check = $pdo->query("SHOW TABLES LIKE 'radiology_orders'");
    if ($check->rowCount() > 0) {
        $rad_sql = "SELECT 
                        ro.id,
                        ro.order_number,
                        ro.order_date,
                        ro.modality,
                        ro.body_part,
                        ro.service_name,
                        ro.service_code,
                        ro.priority,
                        ro.status,
                        ro.payment_status,
                        ro.findings,
                        ro.impression,
                        ro.interpretation_report,
                        ro.clinical_history,
                        ro.scheduled_date,
                        ro.scheduled_time,
                        ro.performed_at,
                        ro.reviewed_at,
                        ro.physician_review_notes,
                        CONCAT(u.first_name, ' ', u.last_name) AS doctor_name,
                        CONCAT(p.first_name, ' ', p.last_name) AS performed_by
                    FROM radiology_orders ro
                    LEFT JOIN users u ON ro.doctor_id = u.id
                    LEFT JOIN users p ON ro.performed_by_user_id = p.id
                    WHERE ro.patient_id = :patient_id AND ro.deleted_at IS NULL
                    ORDER BY ro.order_date DESC, ro.created_at DESC
                    LIMIT 100";
        
        $rad_stmt = $pdo->prepare($rad_sql);
        $rad_stmt->execute([':patient_id' => $patient_id]);
        $radiology_orders = $rad_stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    error_log("Radiology orders fetch error: " . $e->getMessage());
}

// 6. Procedures
$procedures = [];
try {
    $proc_sql = "SELECT 
                    po.id,
                    po.procedure_number,
                    po.procedure_name,
                    po.priority,
                    po.execution_status,
                    po.payment_status,
                    po.scheduled_date,
                    po.scheduled_time,
                    po.performed_at,
                    po.completed_at,
                    po.procedure_findings,
                    po.complications,
                    po.pre_procedure_instructions,
                    po.post_procedure_instructions,
                    po.clinical_notes,
                    po.follow_up_required,
                    po.follow_up_date,
                    CONCAT(u.first_name, ' ', u.last_name) AS doctor_name,
                    CONCAT(p.first_name, ' ', p.last_name) AS performed_by
                FROM procedure_orders po
                LEFT JOIN users u ON po.doctor_id = u.id
                LEFT JOIN users p ON po.performed_by_user_id = p.id
                WHERE po.patient_id = :patient_id AND po.deleted_at IS NULL
                ORDER BY po.created_at DESC
                LIMIT 100";
    
    $proc_stmt = $pdo->prepare($proc_sql);
    $proc_stmt->execute([':patient_id' => $patient_id]);
    $procedures = $proc_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Procedures fetch error: " . $e->getMessage());
}

// 7. Prescriptions
$prescriptions = [];
try {
    $rx_sql = "SELECT 
                    p.id,
                    p.prescription_number,
                    p.prescription_date,
                    p.status,
                    p.payment_status,
                    p.clinical_notes,
                    p.dispensed_at,
                    CONCAT(u.first_name, ' ', u.last_name) AS doctor_name,
                    CONCAT(d.first_name, ' ', d.last_name) AS dispensed_by,
                    COUNT(pi.id) AS item_count
                FROM prescriptions p
                LEFT JOIN users u ON p.doctor_id = u.id
                LEFT JOIN users d ON p.dispensed_by_user_id = d.id
                LEFT JOIN prescription_items pi ON p.id = pi.prescription_id AND pi.deleted_at IS NULL
                WHERE p.patient_id = :patient_id AND p.deleted_at IS NULL
                GROUP BY p.id
                ORDER BY p.prescription_date DESC, p.created_at DESC
                LIMIT 100";
    
    $rx_stmt = $pdo->prepare($rx_sql);
    $rx_stmt->execute([':patient_id' => $patient_id]);
    $prescriptions = $rx_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Prescriptions fetch error: " . $e->getMessage());
}

// 8. Prescription Items (for detailed view)
$prescription_items = [];
try {
    $item_sql = "SELECT 
                    pi.id,
                    pi.prescription_id,
                    pi.medicine_id,
                    pi.medicine_name,
                    pi.dosage,
                    pi.frequency,
                    pi.duration_days,
                    pi.duration_text,
                    pi.quantity,
                    pi.quantity_dispensed,
                    pi.unit_of_measure,
                    pi.instructions,
                    pi.meal_relation,
                    pi.is_controlled_substance,
                    pi.is_dispensed,
                    pi.dispensed_at,
                    mm.generic_name,
                    mm.brand_name
                FROM prescription_items pi
                LEFT JOIN medicines_master mm ON pi.medicine_id = mm.id
                WHERE pi.prescription_id IN (
                    SELECT id FROM prescriptions 
                    WHERE patient_id = :patient_id AND deleted_at IS NULL
                )
                AND pi.deleted_at IS NULL
                ORDER BY pi.created_at DESC
                LIMIT 200";
    
    $item_stmt = $pdo->prepare($item_sql);
    $item_stmt->execute([':patient_id' => $patient_id]);
    $prescription_items = $item_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Prescription items fetch error: " . $e->getMessage());
}

// 9. Invoices
$invoices = [];
try {
    $inv_sql = "SELECT 
                    i.id,
                    i.invoice_number,
                    i.invoice_date,
                    i.due_date,
                    i.subtotal,
                    i.discount_amount,
                    i.tax_amount,
                    i.total_amount,
                    i.paid_amount,
                    i.due_amount,
                    i.payment_status,
                    i.billing_type,
                    i.notes,
                    i.created_at,
                    CONCAT(u.first_name, ' ', u.last_name) AS created_by,
                    COUNT(pi.id) AS item_count
                FROM invoices i
                LEFT JOIN users u ON i.created_by_user_id = u.id
                LEFT JOIN invoice_items pi ON i.id = pi.invoice_id
                WHERE i.patient_id = :patient_id AND i.deleted_at IS NULL
                GROUP BY i.id
                ORDER BY i.invoice_date DESC, i.created_at DESC
                LIMIT 100";
    
    $inv_stmt = $pdo->prepare($inv_sql);
    $inv_stmt->execute([':patient_id' => $patient_id]);
    $invoices = $inv_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Invoices fetch error: " . $e->getMessage());
}

// 10. Invoice Items (for detailed view)
$invoice_items = [];
try {
    $item_sql = "SELECT 
                    ii.id,
                    ii.invoice_id,
                    ii.service_name,
                    ii.service_category,
                    ii.quantity,
                    ii.unit_price,
                    ii.discount_amount,
                    ii.tax_amount,
                    ii.total_price
                FROM invoice_items ii
                WHERE ii.invoice_id IN (
                    SELECT id FROM invoices 
                    WHERE patient_id = :patient_id AND deleted_at IS NULL
                )
                ORDER BY ii.created_at DESC
                LIMIT 200";
    
    $item_stmt = $pdo->prepare($item_sql);
    $item_stmt->execute([':patient_id' => $patient_id]);
    $invoice_items = $item_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Invoice items fetch error: " . $e->getMessage());
}

// 11. Payments
$payments = [];
try {
    $pay_sql = "SELECT 
                    py.id,
                    py.receipt_number,
                    py.payment_method,
                    py.amount_paid,
                    py.transaction_reference,
                    py.payment_date,
                    py.notes,
                    py.is_verified,
                    CONCAT(u.first_name, ' ', u.last_name) AS received_by,
                    i.invoice_number
                FROM payments py
                LEFT JOIN users u ON py.received_by_user_id = u.id
                LEFT JOIN invoices i ON py.invoice_id = i.id
                WHERE py.patient_id = :patient_id AND py.deleted_at IS NULL
                ORDER BY py.payment_date DESC
                LIMIT 100";
    
    $pay_stmt = $pdo->prepare($pay_sql);
    $pay_stmt->execute([':patient_id' => $patient_id]);
    $payments = $pay_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Payments fetch error: " . $e->getMessage());
}

// 12. KYC Documents
$kyc_documents = [];
try {
    $check = $pdo->query("SHOW TABLES LIKE 'patient_kyc_documents'");
    if ($check->rowCount() > 0) {
        $kyc_sql = "SELECT 
                        kd.id,
                        kd.document_type_id,
                        dt.name AS document_type_name,
                        dt.code AS document_type_code,
                        kd.document_name,
                        kd.document_number,
                        kd.file_path,
                        kd.file_type,
                        kd.file_size,
                        kd.original_filename,
                        kd.status,
                        kd.expiry_date,
                        kd.verification_notes,
                        kd.verified_at,
                        kd.uploaded_at,
                        CONCAT(u.first_name, ' ', u.last_name) AS uploaded_by,
                        CONCAT(v.first_name, ' ', v.last_name) AS verified_by
                    FROM patient_kyc_documents kd
                    LEFT JOIN kyc_document_types dt ON kd.document_type_id = dt.id
                    LEFT JOIN users u ON kd.uploaded_by_user_id = u.id
                    LEFT JOIN users v ON kd.verified_by_user_id = v.id
                    WHERE kd.patient_id = :patient_id AND kd.deleted_at IS NULL
                    ORDER BY kd.uploaded_at DESC
                    LIMIT 100";
        
        $kyc_stmt = $pdo->prepare($kyc_sql);
        $kyc_stmt->execute([':patient_id' => $patient_id]);
        $kyc_documents = $kyc_stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    error_log("KYC documents fetch error: " . $e->getMessage());
}

// 13. Appointments
$appointments = [];
try {
    $app_sql = "SELECT 
                    a.id,
                    a.appointment_number,
                    a.appointment_date,
                    a.appointment_time,
                    a.visit_type,
                    a.billing_status,
                    a.priority,
                    a.status,
                    a.chief_complaint,
                    a.queue_token_number,
                    a.estimated_wait_minutes,
                    a.notes,
                    a.created_at,
                    d.name AS department_name,
                    CONCAT(u.first_name, ' ', u.last_name) AS doctor_name
                FROM appointments a
                LEFT JOIN departments d ON a.department_id = d.id
                LEFT JOIN users u ON a.doctor_id = u.id
                WHERE a.patient_id = :patient_id AND a.deleted_at IS NULL
                ORDER BY a.appointment_date DESC, a.appointment_time DESC
                LIMIT 100";
    
    $app_stmt = $pdo->prepare($app_sql);
    $app_stmt->execute([':patient_id' => $patient_id]);
    $appointments = $app_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Appointments fetch error: " . $e->getMessage());
}

// 14. Visits
$visits = [];
try {
    $visit_sql = "SELECT 
                    v.id,
                    v.visit_number,
                    v.visit_date,
                    v.visit_time,
                    v.visit_type,
                    v.visit_mode,
                    v.payment_mode,
                    v.billing_status,
                    v.status,
                    v.queue_token_number,
                    v.triage_priority,
                    v.assigned_room_number,
                    v.created_at,
                    CONCAT(u.first_name, ' ', u.last_name) AS created_by
                FROM patient_visits v
                LEFT JOIN users u ON v.created_by_user_id = u.id
                WHERE v.patient_id = :patient_id AND v.deleted_at IS NULL
                ORDER BY v.visit_date DESC, v.visit_time DESC
                LIMIT 100";
    
    $visit_stmt = $pdo->prepare($visit_sql);
    $visit_stmt->execute([':patient_id' => $patient_id]);
    $visits = $visit_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Visits fetch error: " . $e->getMessage());
}

// 15. Admission History
$admission_history = [];
try {
    $ad_sql = "SELECT 
                    a.id,
                    a.admission_number,
                    a.admission_type,
                    a.admitted_at,
                    a.expected_discharge_at,
                    a.discharged_at,
                    a.primary_diagnosis,
                    a.admitting_diagnosis,
                    a.discharge_diagnosis,
                    a.condition_status,
                    a.status,
                    a.discharge_condition,
                    a.discharge_status,
                    a.discharge_summary,
                    a.discharge_instructions,
                    a.follow_up_date,
                    a.home_medications,
                    CONCAT(u.first_name, ' ', u.last_name) AS doctor_name,
                    b.bed_number,
                    w.name AS ward_name
                FROM admissions a
                LEFT JOIN users u ON a.doctor_id = u.id
                LEFT JOIN beds b ON a.bed_id = b.id
                LEFT JOIN wards w ON b.ward_id = w.id
                WHERE a.patient_id = :patient_id AND a.deleted_at IS NULL
                ORDER BY a.admitted_at DESC
                LIMIT 50";
    
    $ad_stmt = $pdo->prepare($ad_sql);
    $ad_stmt->execute([':patient_id' => $patient_id]);
    $admission_history = $ad_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Admission history fetch error: " . $e->getMessage());
}

// 16. Birth Records
$birth_records = [];
try {
    $birth_sql = "SELECT 
                    br.id,
                    br.certificate_number,
                    br.baby_name,
                    br.baby_gender,
                    br.baby_weight_kg,
                    br.baby_condition,
                    br.birth_date,
                    br.birth_time,
                    br.delivery_type,
                    br.father_name,
                    br.father_national_id,
                    br.father_occupation,
                    br.place_of_birth,
                    br.attending_midwife,
                    br.is_twin,
                    br.twin_order,
                    br.birth_notes,
                    br.status,
                    br.approval_status,
                    CONCAT(d.first_name, ' ', d.last_name) AS attending_doctor
                FROM birth_records br
                LEFT JOIN users d ON br.attending_doctor_id = d.id
                WHERE br.mother_id = :patient_id AND br.deleted_at IS NULL
                ORDER BY br.birth_date DESC
                LIMIT 50";
    
    $birth_stmt = $pdo->prepare($birth_sql);
    $birth_stmt->execute([':patient_id' => $patient_id]);
    $birth_records = $birth_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Birth records fetch error: " . $e->getMessage());
}

// 17. Death Records
$death_records = [];
try {
    $death_sql = "SELECT 
                    dr.id,
                    dr.certificate_number,
                    dr.death_date,
                    dr.death_time,
                    dr.cause_of_death,
                    dr.immediate_cause,
                    dr.contributing_factors,
                    dr.manner_of_death,
                    dr.place_of_death,
                    dr.death_notes,
                    dr.status,
                    dr.approval_status,
                    CONCAT(u.first_name, ' ', u.last_name) AS certifying_doctor
                FROM death_records dr
                LEFT JOIN users u ON dr.certifying_doctor_id = u.id
                WHERE dr.patient_id = :patient_id AND dr.deleted_at IS NULL
                ORDER BY dr.death_date DESC
                LIMIT 50";
    
    $death_stmt = $pdo->prepare($death_sql);
    $death_stmt->execute([':patient_id' => $patient_id]);
    $death_records = $death_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Death records fetch error: " . $e->getMessage());
}

$is_print = isset($_GET['print']) && (int)$_GET['print'] === 1;

// ============================================
// PRINT VIEW
// ============================================
if ($is_print) {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>Patient Profile - <?php echo htmlspecialchars($full_name, ENT_QUOTES, 'UTF-8'); ?></title>
        <style>
            body { font-family: Arial, sans-serif; margin: 20px; font-size: 12px; }
            .print-header { text-align: center; margin-bottom: 20px; border-bottom: 2px solid #333; padding-bottom: 10px; }
            .patient-info { margin-bottom: 20px; }
            .section { margin-bottom: 20px; }
            .section h4 { background: #f0f0f0; padding: 5px 10px; margin-bottom: 10px; }
            table { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
            th, td { border: 1px solid #ddd; padding: 5px 8px; text-align: left; }
            th { background-color: #f2f2f2; }
            .info-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; }
            .info-item { padding: 5px; border-bottom: 1px solid #eee; }
            .label { font-weight: bold; }
            @media print {
                .no-print { display: none; }
                body { margin: 0; padding: 10px; }
                .section { page-break-inside: avoid; }
            }
        </style>
    </head>
    <body>
        <div class="print-header">
            <h2><?php echo htmlspecialchars($full_name, ENT_QUOTES, 'UTF-8'); ?></h2>
            <p>MRN: <?php echo htmlspecialchars($patient['mr_number'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?> | Printed: <?php echo date('Y-m-d H:i:s'); ?></p>
        </div>
        
        <div class="patient-info">
            <div class="info-grid">
                <div class="info-item"><span class="label">Gender:</span> <?php echo htmlspecialchars(ucfirst($patient['gender'] ?? 'N/A'), ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="info-item"><span class="label">Age:</span> <?php echo htmlspecialchars($age, ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="info-item"><span class="label">Phone:</span> <?php echo htmlspecialchars($patient['phone'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="info-item"><span class="label">Email:</span> <?php echo htmlspecialchars($patient['email'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="info-item"><span class="label">Blood Group:</span> <?php echo htmlspecialchars($patient['blood_group'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="info-item"><span class="label">Branch:</span> <?php echo htmlspecialchars($patient['branch_name'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?></div>
            </div>
        </div>
        
        <div class="section">
            <h4>Vitals History</h4>
            <table>
                <thead><tr><th>Date/Time</th><th>BP</th><th>HR</th><th>Temp</th><th>SpO₂</th><th>Priority</th><th>Chief Complaint</th></tr></thead>
                <tbody>
                <?php foreach (array_slice($vitals_data, 0, 20) as $v): ?>
                <tr>
                    <td><?php echo htmlspecialchars($v['triage_time'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars(($v['blood_pressure_systolic'] ?? '—') . '/' . ($v['blood_pressure_diastolic'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars($v['heart_rate'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars($v['temperature_celsius'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars($v['oxygen_saturation'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars($v['priority'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars(substr($v['chief_complaint'] ?? '—', 0, 50), ENT_QUOTES, 'UTF-8'); ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <?php if (!empty($consultations)): ?>
        <div class="section">
            <h4>Recent Consultations</h4>
            <table>
                <thead><tr><th>Date</th><th>Doctor</th><th>Type</th><th>Diagnosis</th></tr></thead>
                <tbody>
                <?php foreach (array_slice($consultations, 0, 10) as $c): ?>
                <tr>
                    <td><?php echo htmlspecialchars($c['consultation_date'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars($c['doctor_name'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars($c['consultation_type'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars(substr($c['final_diagnosis'] ?? $c['provisional_diagnosis'] ?? '—', 0, 50), ENT_QUOTES, 'UTF-8'); ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
        
        <p style="text-align:center;margin-top:20px;font-style:italic;font-size:11px;">
            This is a computer-generated document. For complete medical history, refer to the electronic medical record system.
        </p>
    </body>
    </html>
    <?php
    exit;
}

// ============================================
// AJAX: Add Family Member
// ============================================
if (isset($_POST['add_family_member'])) {
    if (!$can_manage_family && !$is_global_admin) {
        json_exit(['success' => false, 'message' => 'Permission denied']);
    }
    
    $submitted_token = $_POST['csrf_token'] ?? '';
    if (!isset($_SESSION['csrf_token']) || $submitted_token !== $_SESSION['csrf_token']) {
        json_exit(['success' => false, 'message' => 'Security validation failed']);
    }
    
    $related_patient_id = (int)($_POST['related_patient_id'] ?? 0);
    $relationship_type = $_POST['relationship_type'] ?? '';
    $is_emergency_contact = isset($_POST['is_emergency_contact']) ? 1 : 0;
    $notes = trim(escape_html($_POST['notes'] ?? ''));
    
    if ($related_patient_id <= 0 || empty($relationship_type)) {
        json_exit(['success' => false, 'message' => 'Please select a patient and relationship type']);
    }
    
    if ($related_patient_id == $patient_id) {
        json_exit(['success' => false, 'message' => 'Cannot link a patient to themselves']);
    }
    
    try {
        // Check if relationship already exists
        $check = $pdo->prepare("
            SELECT id FROM patient_relationships 
            WHERE (primary_patient_id = :p1 AND related_patient_id = :p2)
            OR (primary_patient_id = :p3 AND related_patient_id = :p4)
            AND deleted_at IS NULL
        ");
        $check->execute([
            ':p1' => $patient_id,
            ':p2' => $related_patient_id,
            ':p3' => $related_patient_id,
            ':p4' => $patient_id
        ]);
        
        if ($check->fetch()) {
            json_exit(['success' => false, 'message' => 'Relationship already exists']);
        }
        
        $stmt = $pdo->prepare("
            INSERT INTO patient_relationships 
            (primary_patient_id, related_patient_id, relationship_type, 
             is_emergency_contact, notes, created_by_user_id, created_at)
            VALUES (:primary, :related, :type, :emergency, :notes, :user_id, NOW())
        ");
        $stmt->execute([
            ':primary' => $patient_id,
            ':related' => $related_patient_id,
            ':type' => $relationship_type,
            ':emergency' => $is_emergency_contact,
            ':notes' => $notes,
            ':user_id' => $user_id
        ]);
        
        json_exit(['success' => true, 'message' => 'Family member added successfully']);
        
    } catch (PDOException $e) {
        error_log("Add family member error: " . $e->getMessage());
        json_exit(['success' => false, 'message' => 'Failed to add family member']);
    }
}

// ============================================
// AJAX: Remove Family Member
// ============================================
if (isset($_POST['remove_family_member'])) {
    if (!$can_manage_family && !$is_global_admin) {
        json_exit(['success' => false, 'message' => 'Permission denied']);
    }
    
    $submitted_token = $_POST['csrf_token'] ?? '';
    if (!isset($_SESSION['csrf_token']) || $submitted_token !== $_SESSION['csrf_token']) {
        json_exit(['success' => false, 'message' => 'Security validation failed']);
    }
    
    $relationship_id = (int)($_POST['relationship_id'] ?? 0);
    
    if ($relationship_id <= 0) {
        json_exit(['success' => false, 'message' => 'Invalid relationship ID']);
    }
    
    try {
        $stmt = $pdo->prepare("UPDATE patient_relationships SET deleted_at = NOW() WHERE id = :id AND deleted_at IS NULL");
        $stmt->execute([':id' => $relationship_id]);
        
        if ($stmt->rowCount() > 0) {
            json_exit(['success' => true, 'message' => 'Family member removed successfully']);
        } else {
            json_exit(['success' => false, 'message' => 'Relationship not found']);
        }
    } catch (PDOException $e) {
        json_exit(['success' => false, 'message' => 'Failed to remove family member']);
    }
}

// ============================================
// AJAX: Verify KYC Document
// ============================================
if (isset($_POST['verify_kyc_document'])) {
    if (!$can_manage_kyc && !$is_global_admin) {
        json_exit(['success' => false, 'message' => 'Permission denied']);
    }
    
    $submitted_token = $_POST['csrf_token'] ?? '';
    if (!isset($_SESSION['csrf_token']) || $submitted_token !== $_SESSION['csrf_token']) {
        json_exit(['success' => false, 'message' => 'Security validation failed']);
    }
    
    $document_id = (int)($_POST['document_id'] ?? 0);
    $status = $_POST['status'] ?? '';
    $notes = trim(escape_html($_POST['verification_notes'] ?? ''));
    
    if ($document_id <= 0 || !in_array($status, ['verified', 'rejected'])) {
        json_exit(['success' => false, 'message' => 'Invalid input']);
    }
    
    try {
        $stmt = $pdo->prepare("
            UPDATE patient_kyc_documents 
            SET status = :status, 
                verification_notes = :notes,
                verified_by_user_id = :user_id,
                verified_at = NOW()
            WHERE id = :id AND deleted_at IS NULL
        ");
        $stmt->execute([
            ':id' => $document_id,
            ':status' => $status,
            ':notes' => $notes,
            ':user_id' => $user_id
        ]);
        
        if ($stmt->rowCount() > 0) {
            json_exit(['success' => true, 'message' => 'Document ' . $status . ' successfully']);
        } else {
            json_exit(['success' => false, 'message' => 'Document not found']);
        }
    } catch (PDOException $e) {
        json_exit(['success' => false, 'message' => 'Failed to verify document']);
    }
}

// ============================================
// AJAX: Get Prescription Items
// ============================================
if (isset($_GET['get_prescription_items'])) {
    $prescription_id = (int)($_GET['prescription_id'] ?? 0);
    if ($prescription_id <= 0) {
        json_exit(['success' => false, 'message' => 'Invalid prescription ID']);
    }
    
    try {
        $stmt = $pdo->prepare("
            SELECT 
                pi.id,
                pi.medicine_name,
                pi.dosage,
                pi.frequency,
                pi.duration_days,
                pi.duration_text,
                pi.quantity,
                pi.quantity_dispensed,
                pi.unit_of_measure,
                pi.instructions,
                pi.meal_relation,
                pi.is_controlled_substance,
                pi.is_dispensed,
                pi.dispensed_at,
                mm.generic_name,
                mm.brand_name
            FROM prescription_items pi
            LEFT JOIN medicines_master mm ON pi.medicine_id = mm.id
            WHERE pi.prescription_id = :prescription_id AND pi.deleted_at IS NULL
            ORDER BY pi.created_at ASC
        ");
        $stmt->execute([':prescription_id' => $prescription_id]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        json_exit(['success' => true, 'items' => $items]);
    } catch (PDOException $e) {
        json_exit(['success' => false, 'message' => 'Failed to load prescription items']);
    }
}

// ============================================
// AJAX: Get Invoice Items
// ============================================
if (isset($_GET['get_invoice_items'])) {
    $invoice_id = (int)($_GET['invoice_id'] ?? 0);
    if ($invoice_id <= 0) {
        json_exit(['success' => false, 'message' => 'Invalid invoice ID']);
    }
    
    try {
        $stmt = $pdo->prepare("
            SELECT 
                id,
                service_name,
                service_category,
                quantity,
                unit_price,
                discount_amount,
                tax_amount,
                total_price
            FROM invoice_items
            WHERE invoice_id = :invoice_id
            ORDER BY created_at ASC
        ");
        $stmt->execute([':invoice_id' => $invoice_id]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        json_exit(['success' => true, 'items' => $items]);
    } catch (PDOException $e) {
        json_exit(['success' => false, 'message' => 'Failed to load invoice items']);
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include('assets/inc/head.php'); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Patient Profile | <?php echo htmlspecialchars($full_name, ENT_QUOTES, 'UTF-8'); ?></title>
    
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <style>
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
        
        /* Profile Hero */
        .profile-hero {
            background: linear-gradient(135deg, rgba(67, 97, 238, 0.95) 0%, rgba(58, 12, 163, 0.95) 100%);
            backdrop-filter: blur(10px);
            border-radius: 28px;
            padding: 28px 32px;
            margin-bottom: 28px;
            color: white;
            position: relative;
            overflow: hidden;
        }
        
        .profile-hero::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -20%;
            width: 300px;
            height: 300px;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
            border-radius: 50%;
        }
        
        .profile-avatar {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            overflow: hidden;
            background: linear-gradient(135deg, #667eea, #764ba2);
            border: 3px solid rgba(255,255,255,0.3);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        
        .avatar-fallback {
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 32px;
            font-weight: 700;
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
        }
        
        .profile-name {
            font-size: 28px;
            font-weight: 700;
            letter-spacing: -0.5px;
        }
        
        .profile-meta {
            font-size: 13px;
            opacity: 0.8;
            margin-top: 4px;
        }
        
        /* Status Badges */
        .status-badge {
            display: inline-flex;
            align-items: center;
            padding: 5px 12px;
            border-radius: 30px;
            font-size: 11px;
            font-weight: 600;
            margin: 2px;
            text-decoration: none;
        }
        
        .status-active { background: #d1fae5; color: #065f46; }
        .status-inactive { background: #f1f5f9; color: #475569; }
        .status-admitted { background: #e0e7ff; color: #3730a3; }
        .status-due { background: #fee2e2; color: #991b1b; }
        .status-expired { background: #fee2e2; color: #991b1b; }
        .status-expiring { background: #fef3c7; color: #92400e; }
        .status-insured { background: #dbeafe; color: #1e40af; }
        .status-info { background: #e0f0fa; color: #0c4a6e; }
        
        /* Info Grid */
        .info-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-top: 20px;
        }
        
        @media (max-width: 992px) {
            .info-grid { grid-template-columns: repeat(2, 1fr); }
        }
        
        @media (max-width: 576px) {
            .info-grid { grid-template-columns: 1fr; }
        }
        
        .info-card {
            background: rgba(255,255,255,0.1);
            backdrop-filter: blur(5px);
            border-radius: 20px;
            padding: 16px 20px;
            border: 1px solid rgba(255,255,255,0.1);
        }
        
        .info-label {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 1px;
            opacity: 0.6;
            margin-bottom: 8px;
        }
        
        .info-value {
            font-size: 15px;
            font-weight: 600;
        }
        
        /* Tabs */
        .tabs-container {
            background: white;
            border-radius: 16px;
            padding: 8px;
            margin-bottom: 24px;
            box-shadow: var(--shadow-sm);
            border: 1px solid #e9ecef;
            overflow-x: auto;
        }
        
        .tabs-wrapper {
            display: flex;
            flex-wrap: wrap;
            gap: 4px;
            min-width: min-content;
        }
        
        .tab-btn {
            padding: 10px 18px;
            border: none;
            background: transparent;
            border-radius: 10px;
            font-weight: 600;
            font-size: 12px;
            transition: all 0.2s;
            cursor: pointer;
            color: #6c757d;
            white-space: nowrap;
        }
        
        .tab-btn i { margin-right: 6px; font-size: 13px; }
        
        .tab-btn.active {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
            box-shadow: 0 2px 8px rgba(67, 97, 238, 0.3);
        }
        
        .tab-btn:not(.active):hover {
            background: #f8f9fa;
            color: var(--primary);
        }
        
        .tab-badge {
            background: var(--danger);
            color: white;
            border-radius: 20px;
            padding: 1px 8px;
            font-size: 10px;
            margin-left: 4px;
        }
        
        /* Content Card */
        .content-card {
            background: white;
            border-radius: 20px;
            overflow: hidden;
            box-shadow: var(--shadow-md);
            border: 1px solid #e9ecef;
        }
        
        .card-header-custom {
            padding: 18px 24px;
            border-bottom: 1px solid #e9ecef;
            background: #f8f9fa;
        }
        
        .card-header-custom h6 {
            margin: 0;
            font-weight: 700;
            color: #1a1a2e;
        }
        
        .card-header-custom small {
            font-size: 12px;
            color: #6c757d;
        }
        
        .card-body-custom {
            padding: 0;
            overflow-x: auto;
        }
        
        /* Data Table */
        .data-table {
            width: 100%;
            font-size: 13px;
        }
        
        .data-table th {
            padding: 12px 16px;
            background: #f8f9fa;
            font-weight: 600;
            color: #495057;
            border-bottom: 1px solid #e9ecef;
            text-align: left;
            white-space: nowrap;
        }
        
        .data-table td {
            padding: 10px 16px;
            border-bottom: 1px solid #f0f0f0;
            vertical-align: middle;
        }
        
        .data-table tr:hover {
            background: #f8f9fa;
        }
        
        .data-table .table-sm th,
        .data-table .table-sm td {
            padding: 6px 12px;
            font-size: 12px;
        }
        
        /* Priority Badges */
        .priority-red { background: #dc3545; color: white; padding: 3px 10px; border-radius: 20px; font-size: 10px; font-weight: 600; display: inline-block; }
        .priority-yellow { background: #ffc107; color: #000; padding: 3px 10px; border-radius: 20px; font-size: 10px; font-weight: 600; display: inline-block; }
        .priority-green { background: #28a745; color: white; padding: 3px 10px; border-radius: 20px; font-size: 10px; font-weight: 600; display: inline-block; }
        .priority-blue { background: #17a2b8; color: white; padding: 3px 10px; border-radius: 20px; font-size: 10px; font-weight: 600; display: inline-block; }
        
        /* Badges */
        .badge-success { background: #28a745; color: white; padding: 3px 10px; border-radius: 20px; font-size: 10px; font-weight: 600; display: inline-block; }
        .badge-warning { background: #ffc107; color: #000; padding: 3px 10px; border-radius: 20px; font-size: 10px; font-weight: 600; display: inline-block; }
        .badge-danger { background: #dc3545; color: white; padding: 3px 10px; border-radius: 20px; font-size: 10px; font-weight: 600; display: inline-block; }
        .badge-info { background: #17a2b8; color: white; padding: 3px 10px; border-radius: 20px; font-size: 10px; font-weight: 600; display: inline-block; }
        .badge-secondary { background: #6c757d; color: white; padding: 3px 10px; border-radius: 20px; font-size: 10px; font-weight: 600; display: inline-block; }
        .badge-primary { background: var(--primary); color: white; padding: 3px 10px; border-radius: 20px; font-size: 10px; font-weight: 600; display: inline-block; }
        
        /* Buttons */
        .btn-back, .btn-edit, .btn-print, .btn-action {
            padding: 8px 20px;
            border-radius: 10px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            font-weight: 500;
            transition: all 0.2s;
            border: none;
        }
        
        .btn-back { background: rgba(255,255,255,0.2); color: white; }
        .btn-back:hover { background: rgba(255,255,255,0.3); color: white; text-decoration: none; }
        .btn-edit { background: white; color: #667eea; }
        .btn-edit:hover { background: #f8f9fa; color: #667eea; text-decoration: none; }
        .btn-print { background: rgba(255,255,255,0.2); color: white; }
        .btn-print:hover { background: rgba(255,255,255,0.3); color: white; text-decoration: none; }
        .btn-action { background: #f8f9fa; color: var(--dark); }
        .btn-action:hover { background: #e9ecef; }
        
        .refresh-btn {
            background: #f8f9fa;
            border: 1px solid #e9ecef;
            padding: 6px 14px;
            border-radius: 10px;
            font-size: 12px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .refresh-btn:hover { background: #e9ecef; }
        
        /* KYC Document */
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
        
        .kyc-doc-item .doc-actions {
            display: flex;
            gap: 6px;
        }
        
        /* Family Member */
        .family-member-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 16px;
            background: #f8fafc;
            border-radius: 12px;
            margin-bottom: 8px;
        }
        
        .family-member-item .member-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .family-member-item .member-avatar {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea, #764ba2);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 14px;
        }
        
        /* Modal */
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
        
        @media (max-width: 992px) {
            .tabs-container { overflow-x: auto; white-space: nowrap; }
            .tabs-wrapper { flex-wrap: nowrap; }
        }
        
        /* Loading */
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
            box-shadow: var(--shadow-lg);
        }
        
        .loading-spinner .spinner-border { width: 40px; height: 40px; }
        
        /* Insurance card */
        .insurance-card {
            background: #f8fafc;
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 10px;
            border-left: 4px solid var(--primary);
        }
        
        .insurance-card.primary {
            border-left-color: var(--secondary);
            background: #f0fdf4;
        }
        
        .insurance-card .ins-label {
            font-size: 11px;
            color: var(--gray);
            font-weight: 500;
        }
        
        .insurance-card .ins-value {
            font-size: 14px;
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
            
            <!-- Profile Hero -->
            <div class="profile-hero">
                <div class="d-flex justify-content-between align-items-start flex-wrap">
                    <div class="d-flex gap-4 align-items-center flex-wrap">
                        <div class="profile-avatar">
                            <div class="avatar-fallback"><?php echo htmlspecialchars($initials ?? 'PT', ENT_QUOTES, 'UTF-8'); ?></div>
                        </div>
                        <div>
                            <div class="profile-name"><?php echo htmlspecialchars($full_name, ENT_QUOTES, 'UTF-8'); ?></div>
                            <div class="profile-meta">
                                MRN: <strong><?php echo htmlspecialchars($patient['mr_number'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?></strong>
                                • <?php echo htmlspecialchars(ucfirst($patient['gender'] ?? 'N/A'), ENT_QUOTES, 'UTF-8'); ?>
                                • Age: <?php echo htmlspecialchars($age, ENT_QUOTES, 'UTF-8'); ?>
                                • Branch: <?php echo htmlspecialchars($patient['branch_name'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?>
                            </div>
                            <div class="mt-3">
                                <?php if ($patient['is_active'] == 1): ?>
                                    <span class="status-badge status-active"><i class="fas fa-check-circle me-1"></i>Active</span>
                                <?php else: ?>
                                    <span class="status-badge status-inactive"><i class="fas fa-ban me-1"></i>Inactive</span>
                                <?php endif; ?>
                                
                                <?php if ($active_admission): ?>
                                    <span class="status-badge status-admitted"><i class="fas fa-procedures me-1"></i>Admitted</span>
                                <?php endif; ?>
                                
                                <?php if ($due_amount > 0): ?>
                                    <span class="status-badge status-due"><i class="fas fa-exclamation-triangle me-1"></i>Due: ETB <?php echo number_format($due_amount, 2); ?></span>
                                <?php endif; ?>
                                
                                <?php echo $insurance_badge; ?>
                                
                                <?php if ($family_count > 0): ?>
                                    <a href="#family" class="status-badge status-info" onclick="switchTab('family')">
                                        <i class="fas fa-users me-1"></i>Family (<?php echo $family_count; ?>)
                                    </a>
                                <?php else: ?>
                                    <button class="status-badge status-info" onclick="openFamilyModal()">
                                        <i class="fas fa-users me-1"></i>Add Family Link
                                    </button>
                                <?php endif; ?>
                                
                                <?php if ($kyc_count > 0): ?>
                                    <a href="#kyc" class="status-badge status-insured" onclick="switchTab('kyc')">
                                        <i class="fas fa-id-card me-1"></i>KYC (<?php echo $kyc_count; ?>)
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="mt-3 mt-md-0 no-print">
                        <a href="patient_history.php?id=<?php echo $patient_id; ?>&print=1" class="btn-print" target="_blank">
                            <i class="fas fa-print"></i> Print
                        </a>
                        <a href="patient_directory.php" class="btn-back ms-2">
                            <i class="fas fa-arrow-left"></i> Back
                        </a>
                        <?php if ($can_edit_patient || $is_global_admin): ?>
                        <a href="update_patient.php?id=<?php echo $patient_id; ?>" class="btn-edit ms-2">
                            <i class="fas fa-edit"></i> Edit
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Info Cards -->
                <div class="info-grid">
                    <div class="info-card">
                        <div class="info-label"><i class="fas fa-phone me-1"></i> CONTACT</div>
                        <div class="info-value"><?php echo htmlspecialchars($patient['phone'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?></div>
                        <div class="small mt-1 opacity-75"><?php echo htmlspecialchars($patient['email'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?></div>
                        <?php if (!empty($patient['emergency_contact_name'])): ?>
                            <div class="small mt-2 pt-1 border-top border-white-50">
                                <i class="fas fa-ambulance me-1"></i> Emergency: <?php echo htmlspecialchars($patient['emergency_contact_name'], ENT_QUOTES, 'UTF-8'); ?>
                                (<?php echo htmlspecialchars($patient['emergency_contact_phone'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?>)
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="info-card">
                        <div class="info-label"><i class="fas fa-id-card me-1"></i> IDENTITY</div>
                        <div class="info-value">National ID: <?php echo htmlspecialchars($patient['national_id'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?></div>
                        <div class="small mt-1 opacity-75">Blood: <?php echo htmlspecialchars($patient['blood_group'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?></div>
                        <?php if ($patient['is_free_treatment_eligible']): ?>
                            <div class="small mt-1 opacity-75"><i class="fas fa-hand-holding-heart me-1"></i> Free Treatment Eligible</div>
                        <?php endif; ?>
                    </div>
                    <div class="info-card">
                        <div class="info-label"><i class="fas fa-heartbeat me-1"></i> LATEST VITALS</div>
                        <div class="info-value">
                            <?php if ($latest_vitals && !empty($latest_vitals['blood_pressure_systolic'])): ?>
                                BP: <?php echo htmlspecialchars($latest_vitals['blood_pressure_systolic'] ?? '—', ENT_QUOTES, 'UTF-8'); ?>/<?php echo htmlspecialchars($latest_vitals['blood_pressure_diastolic'] ?? '—', ENT_QUOTES, 'UTF-8'); ?>
                                • HR: <?php echo htmlspecialchars($latest_vitals['heart_rate'] ?? '—', ENT_QUOTES, 'UTF-8'); ?>
                            <?php else: ?>
                                No vitals recorded
                            <?php endif; ?>
                        </div>
                        <div class="small mt-1 opacity-75">
                            <?php if ($latest_vitals && $latest_vitals['priority']): ?>
                                Priority: <?php echo strtoupper($latest_vitals['priority']); ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="info-card">
                        <div class="info-label"><i class="fas fa-stethoscope me-1"></i> LATEST CONSULTATION</div>
                        <div class="info-value">
                            <?php 
                                $dx_text = $last_consultation['final_diagnosis'] ?? $last_consultation['provisional_diagnosis'] ?? '—';
                                echo htmlspecialchars(substr($dx_text, 0, 40), ENT_QUOTES, 'UTF-8'); 
                            ?>
                        </div>
                        <div class="small mt-1 opacity-75">
                            <?php if ($last_consultation): ?>
                                Dr. <?php echo htmlspecialchars($last_consultation['doctor_name'] ?? '—', ENT_QUOTES, 'UTF-8'); ?>
                                • <?php echo htmlspecialchars($last_consultation['consultation_date'] ?? '—', ENT_QUOTES, 'UTF-8'); ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Tabs Navigation -->
            <div class="tabs-container no-print">
                <div class="tabs-wrapper">
                    <button class="tab-btn active" data-tab="overview"><i class="fas fa-id-card"></i> Overview</button>
                    <button class="tab-btn" data-tab="vitals"><i class="fas fa-heartbeat"></i> Vitals <span class="tab-badge"><?php echo count($vitals_data); ?></span></button>
                    <button class="tab-btn" data-tab="consultations"><i class="fas fa-stethoscope"></i> Consultations <span class="tab-badge"><?php echo count($consultations); ?></span></button>
                    <button class="tab-btn" data-tab="laboratory"><i class="fas fa-flask"></i> Laboratory <span class="tab-badge"><?php echo count($lab_orders); ?></span></button>
                    <button class="tab-btn" data-tab="radiology"><i class="fas fa-x-ray"></i> Radiology <span class="tab-badge"><?php echo count($radiology_orders); ?></span></button>
                    <button class="tab-btn" data-tab="procedures"><i class="fas fa-syringe"></i> Procedures <span class="tab-badge"><?php echo count($procedures); ?></span></button>
                    <button class="tab-btn" data-tab="prescriptions"><i class="fas fa-prescription-bottle"></i> Prescriptions <span class="tab-badge"><?php echo count($prescriptions); ?></span></button>
                    <button class="tab-btn" data-tab="admissions"><i class="fas fa-hospital"></i> Admissions <span class="tab-badge"><?php echo count($admission_history); ?></span></button>
                    <button class="tab-btn" data-tab="financial"><i class="fas fa-file-invoice-dollar"></i> Financial <span class="tab-badge"><?php echo count($invoices); ?></span></button>
                    <button class="tab-btn" data-tab="appointments"><i class="fas fa-calendar-check"></i> Appointments <span class="tab-badge"><?php echo count($appointments); ?></span></button>
                    <button class="tab-btn" data-tab="visits"><i class="fas fa-walking"></i> Visits <span class="tab-badge"><?php echo count($visits); ?></span></button>
                    <button class="tab-btn" data-tab="family"><i class="fas fa-users"></i> Family <span class="tab-badge"><?php echo $family_count; ?></span></button>
                    <button class="tab-btn" data-tab="kyc"><i class="fas fa-id-card"></i> KYC <span class="tab-badge"><?php echo $kyc_count; ?></span></button>
                    <?php if (!empty($birth_records)): ?>
                    <button class="tab-btn" data-tab="birth"><i class="fas fa-baby"></i> Birth</button>
                    <?php endif; ?>
                    <?php if (!empty($death_records)): ?>
                    <button class="tab-btn" data-tab="death"><i class="fas fa-cross"></i> Death</button>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Content Area -->
            <div class="content-card">
                <div class="card-header-custom d-flex justify-content-between align-items-center no-print">
                    <div>
                        <h6 id="sectionTitle">Overview</h6>
                        <small id="sectionHint" class="text-muted">Patient summary and key information</small>
                    </div>
                    <button class="refresh-btn" id="refreshSection">
                        <i class="fas fa-sync-alt me-1"></i> Refresh
                    </button>
                </div>
                <div class="card-body-custom" id="tabContent">
                    <div class="text-center py-5">
                        <div class="spinner-border text-primary" role="status"></div>
                        <p class="mt-2 text-muted">Loading data...</p>
                    </div>
                </div>
            </div>
            
        </div>
    </div>
    
    <?php include('assets/inc/footer.php'); ?>
</div>

<!-- Family Modal -->
<div class="modal fade" id="familyModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-users me-2"></i> Add Family Member</h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body">
                <form id="familyForm">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="add_family_member" value="1">
                    
                    <div class="form-group">
                        <label>Patient to Link *</label>
                        <input type="text" id="familyPatientSearch" class="form-control" placeholder="Search by MRN or name...">
                        <input type="hidden" name="related_patient_id" id="familyPatientId">
                        <div id="familySearchResults" style="display:none;position:absolute;background:white;border:1px solid #ddd;border-radius:8px;max-height:200px;overflow-y:auto;z-index:1000;width:100%;"></div>
                    </div>
                    <div class="form-group">
                        <label>Relationship *</label>
                        <select name="relationship_type" class="custom-select" required>
                            <option value="">Select Relationship</option>
                            <option value="spouse">Spouse</option>
                            <option value="child">Child</option>
                            <option value="parent">Parent</option>
                            <option value="sibling">Sibling</option>
                            <option value="guardian">Guardian</option>
                            <option value="employee">Employee</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" id="isEmergencyContact" name="is_emergency_contact" value="1">
                            <label class="form-check-label" for="isEmergencyContact">Is Emergency Contact</label>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Notes</label>
                        <textarea name="notes" class="form-control" rows="2"></textarea>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="submitFamilyBtn">Add Family Member</button>
            </div>
        </div>
    </div>
</div>

<script src="assets/js/vendor.min.js"></script>
<script src="assets/js/app.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
const patientId = <?php echo (int)$patient_id; ?>;
const canManageFamily = <?php echo $can_manage_family || $is_global_admin ? 'true' : 'false'; ?>;
const canManageKyc = <?php echo $can_manage_kyc || $is_global_admin ? 'true' : 'false'; ?>;
const csrfToken = <?php echo json_encode($csrf_token); ?>;

let activeTab = 'overview';

// Preloaded data from PHP
const vitalsData = <?php echo json_encode($vitals_data); ?>;
const consultationsData = <?php echo json_encode($consultations); ?>;
const labOrdersData = <?php echo json_encode($lab_orders); ?>;
const labResultsData = <?php echo json_encode($lab_results); ?>;
const radiologyData = <?php echo json_encode($radiology_orders); ?>;
const proceduresData = <?php echo json_encode($procedures); ?>;
const prescriptionsData = <?php echo json_encode($prescriptions); ?>;
const prescriptionItemsData = <?php echo json_encode($prescription_items); ?>;
const invoicesData = <?php echo json_encode($invoices); ?>;
const invoiceItemsData = <?php echo json_encode($invoice_items); ?>;
const paymentsData = <?php echo json_encode($payments); ?>;
const kycData = <?php echo json_encode($kyc_documents); ?>;
const familyData = <?php echo json_encode($family_members); ?>;
const appointmentsData = <?php echo json_encode($appointments); ?>;
const visitsData = <?php echo json_encode($visits); ?>;
const admissionHistoryData = <?php echo json_encode($admission_history); ?>;
const birthRecordsData = <?php echo json_encode($birth_records); ?>;
const deathRecordsData = <?php echo json_encode($death_records); ?>;
const insuranceProfilesData = <?php echo json_encode($insurance_profiles); ?>;
const activeAdmissionData = <?php echo json_encode($active_admission); ?>;

const titles = {
    overview: { title: 'Overview', hint: 'Patient summary and key information' },
    vitals: { title: 'Vitals History', hint: 'BP / HR / Temp / SpO₂ from triage records' },
    consultations: { title: 'Consultation Notes', hint: 'Doctor encounters and diagnoses' },
    laboratory: { title: 'Laboratory Results', hint: 'Lab orders and test results' },
    radiology: { title: 'Radiology / Imaging', hint: 'X-ray, CT, MRI, Ultrasound reports' },
    procedures: { title: 'Procedures', hint: 'Surgical and medical procedures history' },
    prescriptions: { title: 'Prescriptions', hint: 'Medications prescribed' },
    admissions: { title: 'Admissions', hint: 'Inpatient admission history' },
    financial: { title: 'Financial Ledger', hint: 'Invoices and payment status' },
    appointments: { title: 'Appointments', hint: 'Scheduled appointments' },
    visits: { title: 'Patient Visits', hint: 'Visit history and status' },
    family: { title: 'Family Linkage', hint: 'Patient family relationships' },
    kyc: { title: 'KYC Documents', hint: 'Identity, insurance, consent documents' },
    birth: { title: 'Birth Records', hint: 'Maternity and birth certificates' },
    death: { title: 'Death Records', hint: 'Death certificates' }
};

function setHeader(tab) {
    const titleEl = document.getElementById('sectionTitle');
    const hintEl = document.getElementById('sectionHint');
    if (titleEl) titleEl.textContent = titles[tab]?.title || 'History';
    if (hintEl) hintEl.textContent = titles[tab]?.hint || '';
}

function setActiveButton(tab) {
    document.querySelectorAll('.tab-btn').forEach(btn => {
        btn.classList.toggle('active', btn.dataset.tab === tab);
    });
}

function escapeHtml(str) {
    if (!str) return '';
    return String(str).replace(/[&<>]/g, function(m) {
        if (m === '&') return '&amp;';
        if (m === '<') return '&lt;';
        if (m === '>') return '&gt;';
        return m;
    });
}

function formatDate(dateStr) {
    if (!dateStr || dateStr === '0000-00-00' || dateStr === '0000-00-00 00:00:00') return 'N/A';
    try {
        const d = new Date(dateStr);
        return d.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
    } catch(e) {
        return dateStr;
    }
}

function formatDateTime(dateStr) {
    if (!dateStr || dateStr === '0000-00-00 00:00:00') return 'N/A';
    try {
        const d = new Date(dateStr);
        return d.toLocaleString('en-US', { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
    } catch(e) {
        return dateStr;
    }
}

function getStatusBadge(status) {
    const classes = {
        'active': 'badge-success',
        'inactive': 'badge-secondary',
        'admitted': 'badge-info',
        'discharged': 'badge-success',
        'pending': 'badge-warning',
        'completed': 'badge-success',
        'cancelled': 'badge-danger',
        'paid': 'badge-success',
        'unpaid': 'badge-danger',
        'partial': 'badge-warning',
        'verified': 'badge-success',
        'rejected': 'badge-danger',
        'expired': 'badge-danger',
        'scheduled': 'badge-info',
        'in_progress': 'badge-warning',
        'finalized': 'badge-success',
        'draft': 'badge-secondary',
        'approved': 'badge-success',
        'reported': 'badge-success',
        'dispensed': 'badge-success'
    };
    const cls = classes[status] || 'badge-secondary';
    return '<span class="' + cls + '">' + escapeHtml(status.replace('_', ' ')) + '</span>';
}

function getPriorityBadge(priority) {
    const colors = {
        'red': 'priority-red',
        'yellow': 'priority-yellow',
        'green': 'priority-green',
        'blue': 'priority-blue',
        'urgent': 'priority-red',
        'stat': 'priority-red',
        'routine': 'priority-green'
    };
    const cls = colors[priority] || 'priority-green';
    return '<span class="' + cls + '">' + escapeHtml(priority.toUpperCase()) + '</span>';
}

// ============================================
// RENDER FUNCTIONS
// ============================================

function renderOverview() {
    let html = '<div style="padding:24px;">';
    
    // Patient Summary
    html += `
        <div class="row">
            <div class="col-md-6">
                <h6 class="text-primary"><i class="fas fa-user me-2"></i>Personal Information</h6>
                <table class="table table-sm">
                    <tr><td style="width:40%;font-weight:600;">Full Name</td><td>${escapeHtml('<?php echo addslashes($full_name); ?>')}</td></tr>
                    <tr><td style="font-weight:600;">MRN</td><td>${escapeHtml('<?php echo addslashes($patient['mr_number'] ?? 'N/A'); ?>')}</td></tr>
                    <tr><td style="font-weight:600;">Gender</td><td>${escapeHtml('<?php echo addslashes(ucfirst($patient['gender'] ?? 'N/A')); ?>')}</td></tr>
                    <tr><td style="font-weight:600;">Date of Birth</td><td>${formatDate('<?php echo addslashes($patient['date_of_birth'] ?? ''); ?>')}</td></tr>
                    <tr><td style="font-weight:600;">Age</td><td><?php echo $age; ?></td></tr>
                    <tr><td style="font-weight:600;">Blood Group</td><td>${escapeHtml('<?php echo addslashes($patient['blood_group'] ?? 'N/A'); ?>')}</td></tr>
                    <tr><td style="font-weight:600;">National ID</td><td>${escapeHtml('<?php echo addslashes($patient['national_id'] ?? 'N/A'); ?>')}</td></tr>
                    <tr><td style="font-weight:600;">Branch</td><td>${escapeHtml('<?php echo addslashes($patient['branch_name'] ?? 'N/A'); ?>')}</td></tr>
                </table>
            </div>
            <div class="col-md-6">
                <h6 class="text-primary"><i class="fas fa-phone me-2"></i>Contact</h6>
                <table class="table table-sm">
                    <tr><td style="width:40%;font-weight:600;">Phone</td><td>${escapeHtml('<?php echo addslashes($patient['phone'] ?? 'N/A'); ?>')}</td></tr>
                    <tr><td style="font-weight:600;">Email</td><td>${escapeHtml('<?php echo addslashes($patient['email'] ?? 'N/A'); ?>')}</td></tr>
                    <tr><td style="font-weight:600;">Address</td><td>${escapeHtml('<?php echo addslashes($patient['address'] ?? 'N/A'); ?>')}</td></tr>
                </table>
                
                <?php if (!empty($patient['emergency_contact_name'])): ?>
                <h6 class="text-primary mt-3"><i class="fas fa-ambulance me-2"></i>Emergency Contact</h6>
                <table class="table table-sm">
                    <tr><td style="width:40%;font-weight:600;">Name</td><td>${escapeHtml('<?php echo addslashes($patient['emergency_contact_name']); ?>')}</td></tr>
                    <tr><td style="font-weight:600;">Phone</td><td>${escapeHtml('<?php echo addslashes($patient['emergency_contact_phone'] ?? 'N/A'); ?>')}</td></tr>
                    <tr><td style="font-weight:600;">Relationship</td><td>${escapeHtml('<?php echo addslashes($patient['emergency_contact_relationship'] ?? 'N/A'); ?>')}</td></tr>
                </table>
                <?php endif; ?>
                
                <?php if ($patient['is_free_treatment_eligible']): ?>
                <h6 class="text-primary mt-3"><i class="fas fa-hand-holding-heart me-2"></i>Free Treatment</h6>
                <table class="table table-sm">
                    <tr><td style="width:40%;font-weight:600;">Eligible</td><td><span class="badge-success">Yes</span></td></tr>
                    <?php if (!empty($patient['free_treatment_letter_number'])): ?>
                    <tr><td style="font-weight:600;">Letter #</td><td><?php echo htmlspecialchars($patient['free_treatment_letter_number'], ENT_QUOTES, 'UTF-8'); ?></td></tr>
                    <?php endif; ?>
                    <?php if (!empty($patient['free_treatment_valid_to'])): ?>
                    <tr><td style="font-weight:600;">Valid Until</td><td><?php echo htmlspecialchars($patient['free_treatment_valid_to'], ENT_QUOTES, 'UTF-8'); ?></td></tr>
                    <?php endif; ?>
                </table>
                <?php endif; ?>
            </div>
        </div>
    `;
    
    // Statistics
    html += `
        <div class="row mt-4">
            <div class="col-12">
                <h6 class="text-primary"><i class="fas fa-chart-bar me-2"></i>Statistics</h6>
            </div>
            <div class="col-md-3 col-6">
                <div class="card bg-light">
                    <div class="card-body text-center">
                        <h4>${vitalsData.length}</h4>
                        <small class="text-muted">Vitals</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="card bg-light">
                    <div class="card-body text-center">
                        <h4>${consultationsData.length}</h4>
                        <small class="text-muted">Consultations</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="card bg-light">
                    <div class="card-body text-center">
                        <h4>${labOrdersData.length}</h4>
                        <small class="text-muted">Lab Tests</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="card bg-light">
                    <div class="card-body text-center">
                        <h4>${prescriptionsData.length}</h4>
                        <small class="text-muted">Prescriptions</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3 col-6 mt-2">
                <div class="card bg-light">
                    <div class="card-body text-center">
                        <h4>${invoicesData.length}</h4>
                        <small class="text-muted">Invoices</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3 col-6 mt-2">
                <div class="card bg-light">
                    <div class="card-body text-center">
                        <h4>${familyData.length}</h4>
                        <small class="text-muted">Family Members</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3 col-6 mt-2">
                <div class="card bg-light">
                    <div class="card-body text-center">
                        <h4>${kycData.length}</h4>
                        <small class="text-muted">KYC Documents</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3 col-6 mt-2">
                <div class="card bg-light">
                    <div class="card-body text-center">
                        <h4>${admissionHistoryData.length}</h4>
                        <small class="text-muted">Admissions</small>
                    </div>
                </div>
            </div>
        </div>
    `;
    
    // Insurance
    if (insuranceProfilesData.length > 0) {
        html += `
            <div class="mt-4">
                <h6 class="text-primary"><i class="fas fa-shield-alt me-2"></i>Insurance Profiles</h6>
                <div class="row">
        `;
        insuranceProfilesData.forEach(function(ins) {
            html += `
                <div class="col-md-6">
                    <div class="insurance-card ${ins.is_primary ? 'primary' : ''}">
                        <div class="d-flex justify-content-between">
                            <span class="ins-label">Provider</span>
                            <span class="ins-value">${escapeHtml(ins.provider_name || 'N/A')}</span>
                        </div>
                        <div class="d-flex justify-content-between">
                            <span class="ins-label">Policy #</span>
                            <span class="ins-value">${escapeHtml(ins.policy_number || 'N/A')}</span>
                        </div>
                        ${ins.member_id ? `
                        <div class="d-flex justify-content-between">
                            <span class="ins-label">Member ID</span>
                            <span class="ins-value">${escapeHtml(ins.member_id)}</span>
                        </div>
                        ` : ''}
                        ${ins.coverage_percentage ? `
                        <div class="d-flex justify-content-between">
                            <span class="ins-label">Coverage</span>
                            <span class="ins-value">${ins.coverage_percentage}%</span>
                        </div>
                        ` : ''}
                        ${ins.effective_from ? `
                        <div class="d-flex justify-content-between">
                            <span class="ins-label">Valid</span>
                            <span class="ins-value">${formatDate(ins.effective_from)} - ${formatDate(ins.effective_to)}</span>
                        </div>
                        ` : ''}
                        <div class="d-flex justify-content-between">
                            <span class="ins-label">Status</span>
                            <span class="ins-value">${getStatusBadge(ins.status)} ${ins.is_primary ? '<span class="badge-primary">Primary</span>' : ''}</span>
                        </div>
                    </div>
                </div>
            `;
        });
        html += `</div></div>`;
    }
    
    // Active Admission
    if (activeAdmissionData) {
        html += `
            <div class="mt-4">
                <h6 class="text-primary"><i class="fas fa-procedures me-2"></i>Active Admission</h6>
                <div class="card border-info">
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-4"><strong>Admission #:</strong> ${escapeHtml(activeAdmissionData.admission_number || 'N/A')}</div>
                            <div class="col-md-4"><strong>Type:</strong> ${escapeHtml(activeAdmissionData.admission_type || 'N/A')}</div>
                            <div class="col-md-4"><strong>Admitted:</strong> ${formatDateTime(activeAdmissionData.admitted_at)}</div>
                            <div class="col-md-4"><strong>Ward:</strong> ${escapeHtml(activeAdmissionData.ward_name || 'N/A')}</div>
                            <div class="col-md-4"><strong>Bed:</strong> ${escapeHtml(activeAdmissionData.bed_number || 'N/A')}</div>
                            <div class="col-md-4"><strong>Doctor:</strong> ${escapeHtml(activeAdmissionData.admitting_doctor || 'N/A')}</div>
                            <div class="col-md-12"><strong>Diagnosis:</strong> ${escapeHtml(activeAdmissionData.primary_diagnosis || 'N/A')}</div>
                        </div>
                    </div>
                </div>
            </div>
        `;
    }
    
    // Latest Vitals
    if (vitalsData.length > 0) {
        const v = vitalsData[0];
        html += `
            <div class="mt-4">
                <h6 class="text-primary"><i class="fas fa-heartbeat me-2"></i>Latest Vitals</h6>
                <div class="row">
                    <div class="col-md-3"><strong>BP:</strong> ${v.blood_pressure_systolic || '—'}/${v.blood_pressure_diastolic || '—'}</div>
                    <div class="col-md-3"><strong>HR:</strong> ${v.heart_rate || '—'} bpm</div>
                    <div class="col-md-3"><strong>Temp:</strong> ${v.temperature_celsius || '—'} °C</div>
                    <div class="col-md-3"><strong>SpO₂:</strong> ${v.oxygen_saturation || '—'}%</div>
                    ${v.priority ? `<div class="col-md-3"><strong>Priority:</strong> ${getPriorityBadge(v.priority)}</div>` : ''}
                    ${v.chief_complaint ? `<div class="col-md-9"><strong>Chief Complaint:</strong> ${escapeHtml(v.chief_complaint)}</div>` : ''}
                </div>
            </div>
        `;
    }
    
    html += '</div>';
    return html;
}

function renderVitals() {
    if (!vitalsData || vitalsData.length === 0) {
        return '<div class="text-center text-muted py-5"><i class="fas fa-heartbeat fa-3x mb-3 d-block"></i>No vitals recorded for this patient</div>';
    }
    
    let html = '<div style="padding:16px;"><table class="data-table">';
    html += '<thead><tr>';
    html += '<th>Date/Time</th><th>Priority</th><th>BP</th><th>HR</th><th>Temp</th><th>SpO₂</th><th>Pain</th><th>Chief Complaint</th>';
    html += '</tr></thead><tbody>';
    
    vitalsData.forEach(function(row) {
        const dateTime = row.triage_time ? new Date(row.triage_time).toLocaleString() : '—';
        const bp = (row.blood_pressure_systolic && row.blood_pressure_diastolic) 
            ? row.blood_pressure_systolic + '/' + row.blood_pressure_diastolic 
            : '—';
        
        html += '<tr>';
        html += '<td>' + escapeHtml(dateTime) + '</td>';
        html += '<td>' + getPriorityBadge(row.priority || 'green') + '</td>';
        html += '<td>' + escapeHtml(bp) + '</td>';
        html += '<td>' + escapeHtml(row.heart_rate || '—') + '</td>';
        html += '<td>' + escapeHtml(row.temperature_celsius ? row.temperature_celsius + '°C' : '—') + '</td>';
        html += '<td>' + escapeHtml(row.oxygen_saturation ? row.oxygen_saturation + '%' : '—') + '</td>';
        html += '<td>' + escapeHtml(row.pain_score || '—') + '</td>';
        html += '<td>' + escapeHtml(row.chief_complaint ? row.chief_complaint.substring(0, 50) : '—') + '</td>';
        html += '</tr>';
    });
    
    html += '</tbody></table></div>';
    return html;
}

function renderConsultations() {
    if (!consultationsData || consultationsData.length === 0) {
        return '<div class="text-center text-muted py-5"><i class="fas fa-stethoscope fa-3x mb-3 d-block"></i>No consultations recorded</div>';
    }
    
    let html = '<div style="padding:16px;"><table class="data-table">';
    html += '<thead><tr>';
    html += '<th>Date</th><th>Doctor</th><th>Type</th><th>Chief Complaint</th><th>Diagnosis</th><th>Status</th></tr>';
    html += '</thead><tbody>';
    
    consultationsData.forEach(function(row) {
        const diagnosis = (row.final_diagnosis || row.provisional_diagnosis || '—').substring(0, 50);
        
        html += '<tr>';
        html += '<td>' + escapeHtml(row.consultation_date || '—') + '</td>';
        html += '<td>' + escapeHtml(row.doctor_name || '—') + '</td>';
        html += '<td>' + escapeHtml((row.consultation_type || '—').replace('_', ' ')) + '</td>';
        html += '<td>' + escapeHtml(row.chief_complaint ? row.chief_complaint.substring(0, 40) : '—') + '</td>';
        html += '<td>' + escapeHtml(diagnosis) + '</td>';
        html += '<td>' + getStatusBadge(row.status || 'draft') + '</td>';
        html += '</tr>';
    });
    
    html += '</tbody></table></div>';
    return html;
}

function renderLaboratory() {
    if (!labOrdersData || labOrdersData.length === 0) {
        return '<div class="text-center text-muted py-5"><i class="fas fa-flask fa-3x mb-3 d-block"></i>No laboratory orders found</div>';
    }
    
    let html = '<div style="padding:16px;"><table class="data-table">';
    html += '<thead><tr>';
    html += '<th>Date</th><th>Order #</th><th>Test</th><th>Doctor</th><th>Status</th><th>Payment</th>';
    html += '</tr></thead><tbody>';
    
    labOrdersData.forEach(function(row) {
        html += '<tr>';
        html += '<td>' + escapeHtml(row.order_date || '—') + '</td>';
        html += '<td>' + escapeHtml(row.order_number || '—') + '</td>';
        html += '<td>' + escapeHtml(row.test_name || '—') + '</td>';
        html += '<td>' + escapeHtml(row.doctor_name || '—') + '</td>';
        html += '<td>' + getStatusBadge(row.status || 'pending') + '</td>';
        html += '<td>' + getStatusBadge(row.payment_status || 'unpaid') + '</td>';
        html += '</tr>';
    });
    
    html += '</tbody></table></div>';
    
    // Show lab results if available
    if (labResultsData.length > 0) {
        html += '<div style="padding:0 16px 16px;"><h6 class="text-primary mt-3"><i class="fas fa-file-medical me-2"></i>Test Results</h6>';
        html += '<table class="data-table">';
        html += '<thead><tr><th>Test</th><th>Result</th><th>Reference</th><th>Status</th></tr></thead><tbody>';
        labResultsData.forEach(function(row) {
            const result = row.result_numeric !== null ? row.result_numeric + (row.result_unit ? ' ' + row.result_unit : '') : (row.result_text || '—');
            const isAbnormal = row.is_abnormal ? 'bg-danger text-white' : '';
            html += '<tr class="' + isAbnormal + '">';
            html += '<td>' + escapeHtml(row.test_name || '—') + '</td>';
            html += '<td><strong>' + escapeHtml(result) + '</strong></td>';
            html += '<td>' + escapeHtml(row.reference_range_text || (row.reference_range_low + ' - ' + row.reference_range_high) || '—') + '</td>';
            html += '<td>' + (row.is_critical ? '<span class="badge-danger">Critical</span>' : (row.is_abnormal ? '<span class="badge-warning">Abnormal</span>' : '<span class="badge-success">Normal</span>')) + '</td>';
            html += '</tr>';
        });
        html += '</tbody></table></div>';
    }
    
    return html;
}

function renderRadiology() {
    if (!radiologyData || radiologyData.length === 0) {
        return '<div class="text-center text-muted py-5"><i class="fas fa-x-ray fa-3x mb-3 d-block"></i>No radiology orders found</div>';
    }
    
    let html = '<div style="padding:16px;"><table class="data-table">';
    html += '<thead><tr>';
    html += '<th>Date</th><th>Order #</th><th>Modality</th><th>Body Part</th><th>Doctor</th><th>Status</th><th>Findings</th>';
    html += '</tr></thead><tbody>';
    
    radiologyData.forEach(function(row) {
        html += '<tr>';
        html += '<td>' + escapeHtml(row.order_date || '—') + '</td>';
        html += '<td>' + escapeHtml(row.order_number || '—') + '</td>';
        html += '<td>' + escapeHtml((row.modality || '—').toUpperCase()) + '</td>';
        html += '<td>' + escapeHtml(row.body_part || '—') + '</td>';
        html += '<td>' + escapeHtml(row.doctor_name || '—') + '</td>';
        html += '<td>' + getStatusBadge(row.status || 'scheduled') + '</td>';
        html += '<td>' + escapeHtml(row.findings ? row.findings.substring(0, 40) : '—') + '</td>';
        html += '</tr>';
    });
    
    html += '</tbody></table></div>';
    return html;
}

function renderProcedures() {
    if (!proceduresData || proceduresData.length === 0) {
        return '<div class="text-center text-muted py-5"><i class="fas fa-syringe fa-3x mb-3 d-block"></i>No procedures recorded</div>';
    }
    
    let html = '<div style="padding:16px;"><table class="data-table">';
    html += '<thead><tr>';
    html += '<th>Date</th><th>Procedure</th><th>Doctor</th><th>Status</th><th>Payment</th>';
    html += '</tr></thead><tbody>';
    
    proceduresData.forEach(function(row) {
        const date = row.scheduled_date || (row.performed_at ? new Date(row.performed_at).toLocaleDateString() : '—');
        
        html += '<tr>';
        html += '<td>' + escapeHtml(date) + '</td>';
        html += '<td><strong>' + escapeHtml(row.procedure_name || '—') + '</strong></td>';
        html += '<td>' + escapeHtml(row.doctor_name || '—') + '</td>';
        html += '<td>' + getStatusBadge(row.execution_status || 'scheduled') + '</td>';
        html += '<td>' + getStatusBadge(row.payment_status || 'unpaid') + '</td>';
        html += '</tr>';
    });
    
    html += '</tbody></table></div>';
    return html;
}

function renderPrescriptions() {
    if (!prescriptionsData || prescriptionsData.length === 0) {
        return '<div class="text-center text-muted py-5"><i class="fas fa-prescription-bottle fa-3x mb-3 d-block"></i>No prescriptions found</div>';
    }
    
    let html = '<div style="padding:16px;"><table class="data-table">';
    html += '<thead><tr>';
    html += '<th>Date</th><th>Prescription #</th><th>Doctor</th><th>Items</th><th>Status</th><th>Payment</th>';
    html += '</tr></thead><tbody>';
    
    prescriptionsData.forEach(function(row) {
        html += '<tr>';
        html += '<td>' + escapeHtml(row.prescription_date || '—') + '</td>';
        html += '<td>' + escapeHtml(row.prescription_number || '—') + '</td>';
        html += '<td>' + escapeHtml(row.doctor_name || '—') + '</td>';
        html += '<td>' + (row.item_count || 0) + '</td>';
        html += '<td>' + getStatusBadge(row.status || 'pending') + '</td>';
        html += '<td>' + getStatusBadge(row.payment_status || 'unpaid') + '</td>';
        html += '</tr>';
    });
    
    html += '</tbody></table></div>';
    
    // Show prescription items
    if (prescriptionItemsData.length > 0) {
        html += '<div style="padding:0 16px 16px;"><h6 class="text-primary mt-3"><i class="fas fa-capsules me-2"></i>Medication Items</h6>';
        html += '<table class="data-table table-sm">';
        html += '<thead><tr><th>Medicine</th><th>Dosage</th><th>Frequency</th><th>Duration</th><th>Dispensed</th></tr></thead><tbody>';
        prescriptionItemsData.forEach(function(row) {
            const name = row.brand_name || row.generic_name || row.medicine_name || '—';
            html += '<tr>';
            html += '<td>' + escapeHtml(name) + '</td>';
            html += '<td>' + escapeHtml(row.dosage || '—') + '</td>';
            html += '<td>' + escapeHtml(row.frequency || '—') + '</td>';
            html += '<td>' + escapeHtml(row.duration_days ? row.duration_days + ' days' : (row.duration_text || '—')) + '</td>';
            html += '<td>' + (row.is_dispensed ? '<span class="badge-success">Yes</span>' : '<span class="badge-secondary">No</span>') + '</td>';
            html += '</tr>';
        });
        html += '</tbody></table></div>';
    }
    
    return html;
}

function renderAdmissions() {
    if (!admissionHistoryData || admissionHistoryData.length === 0) {
        return '<div class="text-center text-muted py-5"><i class="fas fa-hospital fa-3x mb-3 d-block"></i>No admissions found</div>';
    }
    
    let html = '<div style="padding:16px;"><table class="data-table">';
    html += '<thead><tr>';
    html += '<th>Admission #</th><th>Type</th><th>Admitted</th><th>Discharged</th><th>Ward</th><th>Bed</th><th>Doctor</th><th>Status</th>';
    html += '</tr></thead><tbody>';
    
    admissionHistoryData.forEach(function(row) {
        html += '<tr>';
        html += '<td>' + escapeHtml(row.admission_number || '—') + '</td>';
        html += '<td>' + escapeHtml(row.admission_type || '—') + '</td>';
        html += '<td>' + formatDateTime(row.admitted_at) + '</td>';
        html += '<td>' + formatDateTime(row.discharged_at) + '</td>';
        html += '<td>' + escapeHtml(row.ward_name || '—') + '</td>';
        html += '<td>' + escapeHtml(row.bed_number || '—') + '</td>';
        html += '<td>' + escapeHtml(row.doctor_name || '—') + '</td>';
        html += '<td>' + getStatusBadge(row.status || '—') + '</td>';
        html += '</tr>';
    });
    
    html += '</tbody></table></div>';
    return html;
}

function renderFinancial() {
    if (!invoicesData || invoicesData.length === 0) {
        return '<div class="text-center text-muted py-5"><i class="fas fa-file-invoice fa-3x mb-3 d-block"></i>No invoices found</div>';
    }
    
    let html = '<div style="padding:16px;"><table class="data-table">';
    html += '<thead><tr>';
    html += '<th>Invoice #</th><th>Date</th><th>Total</th><th>Paid</th><th>Due</th><th>Status</th><th>Type</th>';
    html += '</tr></thead><tbody>';
    
    let totalInvoices = 0, totalPaid = 0, totalDue = 0;
    invoicesData.forEach(function(row) {
        totalInvoices += parseFloat(row.total_amount || 0);
        totalPaid += parseFloat(row.paid_amount || 0);
        totalDue += parseFloat(row.due_amount || 0);
        
        const due = parseFloat(row.due_amount || 0);
        html += '<tr>';
        html += '<td>' + escapeHtml(row.invoice_number || '—') + '</td>';
        html += '<td>' + formatDate(row.invoice_date) + '</td>';
        html += '<td>ETB ' + parseFloat(row.total_amount || 0).toFixed(2) + '</td>';
        html += '<td>ETB ' + parseFloat(row.paid_amount || 0).toFixed(2) + '</td>';
        html += '<td class="' + (due > 0 ? 'text-danger fw-bold' : 'text-success') + '">ETB ' + due.toFixed(2) + '</td>';
        html += '<td>' + getStatusBadge(row.payment_status || 'unpaid') + '</td>';
        html += '<td>' + escapeHtml(row.billing_type || 'cash') + '</td>';
        html += '</tr>';
    });
    
    html += `
        <tfoot>
            <tr style="font-weight:bold;background:#f8f9fa;">
                <td colspan="2">Totals</td>
                <td>ETB ${totalInvoices.toFixed(2)}</td>
                <td>ETB ${totalPaid.toFixed(2)}</td>
                <td>ETB ${totalDue.toFixed(2)}</td>
                <td colspan="2"></td>
            </tr>
        </tfoot>
    `;
    html += '</tbody></table></div>';
    
    // Payments
    if (paymentsData.length > 0) {
        html += '<div style="padding:0 16px 16px;"><h6 class="text-primary mt-3"><i class="fas fa-credit-card me-2"></i>Payment History</h6>';
        html += '<table class="data-table table-sm">';
        html += '<thead><tr><th>Receipt #</th><th>Date</th><th>Amount</th><th>Method</th><th>Invoice</th></tr></thead><tbody>';
        paymentsData.forEach(function(row) {
            html += '<tr>';
            html += '<td>' + escapeHtml(row.receipt_number || '—') + '</td>';
            html += '<td>' + formatDateTime(row.payment_date) + '</td>';
            html += '<td>ETB ' + parseFloat(row.amount_paid || 0).toFixed(2) + '</td>';
            html += '<td>' + escapeHtml(row.payment_method || '—') + '</td>';
            html += '<td>' + escapeHtml(row.invoice_number || '—') + '</td>';
            html += '</tr>';
        });
        html += '</tbody></table></div>';
    }
    
    return html;
}

function renderAppointments() {
    if (!appointmentsData || appointmentsData.length === 0) {
        return '<div class="text-center text-muted py-5"><i class="fas fa-calendar-check fa-3x mb-3 d-block"></i>No appointments found</div>';
    }
    
    let html = '<div style="padding:16px;"><table class="data-table">';
    html += '<thead><tr>';
    html += '<th>Date</th><th>Time</th><th>Department</th><th>Doctor</th><th>Type</th><th>Status</th><th>Billing</th>';
    html += '</tr></thead><tbody>';
    
    appointmentsData.forEach(function(row) {
        html += '<tr>';
        html += '<td>' + escapeHtml(row.appointment_date || '—') + '</td>';
        html += '<td>' + escapeHtml(row.appointment_time || '—') + '</td>';
        html += '<td>' + escapeHtml(row.department_name || '—') + '</td>';
        html += '<td>' + escapeHtml(row.doctor_name || '—') + '</td>';
        html += '<td>' + escapeHtml(row.visit_type || '—') + '</td>';
        html += '<td>' + getStatusBadge(row.status || 'scheduled') + '</td>';
        html += '<td>' + getStatusBadge(row.billing_status || 'unpaid') + '</td>';
        html += '</tr>';
    });
    
    html += '</tbody></table></div>';
    return html;
}

function renderVisits() {
    if (!visitsData || visitsData.length === 0) {
        return '<div class="text-center text-muted py-5"><i class="fas fa-walking fa-3x mb-3 d-block"></i>No visits found</div>';
    }
    
    let html = '<div style="padding:16px;"><table class="data-table">';
    html += '<thead><tr>';
    html += '<th>Date</th><th>Time</th><th>Visit #</th><th>Type</th><th>Mode</th><th>Status</th><th>Billing</th>';
    html += '</tr></thead><tbody>';
    
    visitsData.forEach(function(row) {
        html += '<tr>';
        html += '<td>' + escapeHtml(row.visit_date || '—') + '</td>';
        html += '<td>' + escapeHtml(row.visit_time || '—') + '</td>';
        html += '<td>' + escapeHtml(row.visit_number || '—') + '</td>';
        html += '<td>' + escapeHtml(row.visit_type || '—') + '</td>';
        html += '<td>' + escapeHtml(row.visit_mode || '—') + '</td>';
        html += '<td>' + getStatusBadge(row.status || 'pending') + '</td>';
        html += '<td>' + getStatusBadge(row.billing_status || 'unpaid') + '</td>';
        html += '</tr>';
    });
    
    html += '</tbody></table></div>';
    return html;
}

function renderFamily() {
    if (!familyData || familyData.length === 0) {
        return `
            <div class="text-center text-muted py-5">
                <i class="fas fa-users fa-3x mb-3 d-block"></i>
                <p>No family members linked</p>
                ${canManageFamily ? '<button class="btn btn-primary" onclick="openFamilyModal()"><i class="fas fa-plus me-2"></i>Add Family Member</button>' : ''}
            </div>
        `;
    }
    
    let html = '<div style="padding:16px;">';
    html += `<div class="mb-3">${canManageFamily ? '<button class="btn btn-primary btn-sm" onclick="openFamilyModal()"><i class="fas fa-plus me-2"></i>Add Family Member</button>' : ''}</div>`;
    html += '<div class="row">';
    
    familyData.forEach(function(member) {
        const name = member.relative_name || 'Unknown';
        const initials = name.split(' ').map(n => n[0]).join('').toUpperCase().substring(0, 2) || 'FM';
        const hasDue = parseFloat(member.relative_due || 0) > 0;
        
        html += `
            <div class="col-md-6">
                <div class="family-member-item">
                    <div class="member-info">
                        <div class="member-avatar">${initials}</div>
                        <div>
                            <div><strong>${escapeHtml(name)}</strong> <span class="text-muted small">(${escapeHtml(member.relationship_type || '—')})</span></div>
                            <div class="small text-muted">MRN: ${escapeHtml(member.relative_mrn || 'N/A')}</div>
                            ${member.is_emergency_contact ? '<span class="badge-info">Emergency Contact</span>' : ''}
                            ${hasDue ? '<span class="badge-danger">Due: ETB ' + parseFloat(member.relative_due).toFixed(2) + '</span>' : ''}
                        </div>
                    </div>
                    ${canManageFamily ? `
                    <button class="btn btn-sm btn-danger" onclick="removeFamilyMember(${member.id})" title="Remove">
                        <i class="fas fa-times"></i>
                    </button>
                    ` : ''}
                </div>
            </div>
        `;
    });
    
    html += '</div></div>';
    return html;
}

function renderKYC() {
    if (!kycData || kycData.length === 0) {
        return `
            <div class="text-center text-muted py-5">
                <i class="fas fa-id-card fa-3x mb-3 d-block"></i>
                <p>No KYC documents uploaded</p>
                ${canManageKyc ? '<button class="btn btn-primary" onclick="openKycModal()"><i class="fas fa-upload me-2"></i>Upload Document</button>' : ''}
            </div>
        `;
    }
    
    let html = '<div style="padding:16px;">';
    html += `<div class="mb-3">${canManageKyc ? '<button class="btn btn-primary btn-sm" onclick="openKycModal()"><i class="fas fa-upload me-2"></i>Upload Document</button>' : ''}</div>`;
    
    kycData.forEach(function(doc) {
        const statusClass = doc.status === 'verified' ? 'badge-success' : (doc.status === 'rejected' ? 'badge-danger' : 'badge-warning');
        const isExpired = doc.expiry_date && doc.expiry_date < new Date().toISOString().split('T')[0];
        
        html += `
            <div class="kyc-doc-item">
                <div class="doc-info">
                    <i class="fas fa-file-${doc.file_type && doc.file_type.includes('pdf') ? 'pdf' : 'image'}"></i>
                    <div>
                        <div><strong>${escapeHtml(doc.document_type_name || doc.document_name || 'Document')}</strong></div>
                        <div class="small text-muted">
                            ${doc.document_number ? '#' + escapeHtml(doc.document_number) : ''}
                            ${doc.expiry_date ? ' • Exp: ' + formatDate(doc.expiry_date) + (isExpired ? ' <span class="badge-danger">Expired</span>' : '') : ''}
                            ${doc.uploaded_by ? ' • By: ' + escapeHtml(doc.uploaded_by) : ''}
                        </div>
                    </div>
                </div>
                <div class="doc-actions">
                    <span class="${statusClass}">${escapeHtml(doc.status || 'pending')}</span>
                    <a href="${escapeHtml(doc.file_path || '#')}" target="_blank" class="btn btn-sm btn-outline-secondary" title="View">
                        <i class="fas fa-eye"></i>
                    </a>
                    ${canManageKyc && doc.status !== 'verified' ? `
                        <button class="btn btn-sm btn-success" onclick="verifyKyc(${doc.id}, 'verified')" title="Verify">
                            <i class="fas fa-check"></i>
                        </button>
                        <button class="btn btn-sm btn-danger" onclick="verifyKyc(${doc.id}, 'rejected')" title="Reject">
                            <i class="fas fa-times"></i>
                        </button>
                    ` : ''}
                    ${canManageKyc ? `
                        <button class="btn btn-sm btn-danger" onclick="deleteKyc(${doc.id})" title="Delete">
                            <i class="fas fa-trash"></i>
                        </button>
                    ` : ''}
                </div>
            </div>
        `;
    });
    
    html += '</div>';
    return html;
}

function renderBirth() {
    if (!birthRecordsData || birthRecordsData.length === 0) {
        return '<div class="text-center text-muted py-5"><i class="fas fa-baby fa-3x mb-3 d-block"></i>No birth records found</div>';
    }
    
    let html = '<div style="padding:16px;"><table class="data-table">';
    html += '<thead><tr>';
    html += '<th>Certificate #</th><th>Baby Name</th><th>Gender</th><th>Birth Date</th><th>Weight</th><th>Delivery</th><th>Status</th>';
    html += '</tr></thead><tbody>';
    
    birthRecordsData.forEach(function(row) {
        html += '<tr>';
        html += '<td>' + escapeHtml(row.certificate_number || '—') + '</td>';
        html += '<td>' + escapeHtml(row.baby_name || '—') + '</td>';
        html += '<td>' + escapeHtml(row.baby_gender || '—') + '</td>';
        html += '<td>' + escapeHtml(row.birth_date || '—') + '</td>';
        html += '<td>' + (row.baby_weight_kg ? row.baby_weight_kg + ' kg' : '—') + '</td>';
        html += '<td>' + escapeHtml(row.delivery_type || '—') + '</td>';
        html += '<td>' + getStatusBadge(row.approval_status || 'pending') + '</td>';
        html += '</tr>';
    });
    
    html += '</tbody></table></div>';
    return html;
}

function renderDeath() {
    if (!deathRecordsData || deathRecordsData.length === 0) {
        return '<div class="text-center text-muted py-5"><i class="fas fa-cross fa-3x mb-3 d-block"></i>No death records found</div>';
    }
    
    let html = '<div style="padding:16px;"><table class="data-table">';
    html += '<thead><tr>';
    html += '<th>Certificate #</th><th>Death Date</th><th>Cause</th><th>Manner</th><th>Place</th><th>Status</th>';
    html += '</tr></thead><tbody>';
    
    deathRecordsData.forEach(function(row) {
        html += '<tr>';
        html += '<td>' + escapeHtml(row.certificate_number || '—') + '</td>';
        html += '<td>' + escapeHtml(row.death_date || '—') + '</td>';
        html += '<td>' + escapeHtml(row.cause_of_death ? row.cause_of_death.substring(0, 40) : '—') + '</td>';
        html += '<td>' + escapeHtml(row.manner_of_death || '—') + '</td>';
        html += '<td>' + escapeHtml(row.place_of_death || '—') + '</td>';
        html += '<td>' + getStatusBadge(row.approval_status || 'pending') + '</td>';
        html += '</tr>';
    });
    
    html += '</tbody></table></div>';
    return html;
}

// ============================================
// TAB SWITCHING
// ============================================

function loadTab(tab) {
    activeTab = tab;
    setHeader(tab);
    setActiveButton(tab);
    
    const box = document.getElementById('tabContent');
    if (!box) return;
    
    let html = '';
    switch(tab) {
        case 'overview': html = renderOverview(); break;
        case 'vitals': html = renderVitals(); break;
        case 'consultations': html = renderConsultations(); break;
        case 'laboratory': html = renderLaboratory(); break;
        case 'radiology': html = renderRadiology(); break;
        case 'procedures': html = renderProcedures(); break;
        case 'prescriptions': html = renderPrescriptions(); break;
        case 'admissions': html = renderAdmissions(); break;
        case 'financial': html = renderFinancial(); break;
        case 'appointments': html = renderAppointments(); break;
        case 'visits': html = renderVisits(); break;
        case 'family': html = renderFamily(); break;
        case 'kyc': html = renderKYC(); break;
        case 'birth': html = renderBirth(); break;
        case 'death': html = renderDeath(); break;
        default: html = renderOverview();
    }
    
    box.innerHTML = html;
    
    // Scroll to top of content
    box.scrollTop = 0;
}

// Expose switchTab globally
window.switchTab = loadTab;

// ============================================
// FAMILY FUNCTIONS
// ============================================

function openFamilyModal() {
    $('#familyModal').modal('show');
    $('#familyPatientId').val('');
    $('#familyPatientSearch').val('');
    $('#familySearchResults').hide();
}

function openKycModal() {
    // Open KYC upload modal (reuse from directory)
    if (typeof openKycModalGlobal === 'function') {
        openKycModalGlobal(patientId);
    } else {
        // Fallback - redirect to upload page
        window.location.href = 'upload_kyc.php?patient_id=' + patientId;
    }
}

function removeFamilyMember(id) {
    if (!confirm('Remove this family member?')) return;
    
    showLoading(true);
    $.ajax({
        url: window.location.pathname,
        method: 'POST',
        data: {
            remove_family_member: 1,
            relationship_id: id,
            csrf_token: csrfToken
        },
        dataType: 'json'
    })
    .done(function(res) {
        showLoading(false);
        if (res.success) {
            Swal.fire('Success', res.message, 'success');
            loadTab('family');
        } else {
            Swal.fire('Error', res.message, 'error');
        }
    })
    .fail(function() {
        showLoading(false);
        Swal.fire('Error', 'Failed to remove family member', 'error');
    });
}

function verifyKyc(docId, status) {
    const action = status === 'verified' ? 'verify' : 'reject';
    if (!confirm('Are you sure you want to ' + action + ' this document?')) return;
    
    showLoading(true);
    $.ajax({
        url: window.location.pathname,
        method: 'POST',
        data: {
            verify_kyc_document: 1,
            document_id: docId,
            status: status,
            csrf_token: csrfToken
        },
        dataType: 'json'
    })
    .done(function(res) {
        showLoading(false);
        if (res.success) {
            Swal.fire('Success', res.message, 'success');
            loadTab('kyc');
        } else {
            Swal.fire('Error', res.message, 'error');
        }
    })
    .fail(function() {
        showLoading(false);
        Swal.fire('Error', 'Failed to verify document', 'error');
    });
}

function deleteKyc(docId) {
    if (!confirm('Delete this document?')) return;
    
    showLoading(true);
    $.ajax({
        url: window.location.pathname,
        method: 'POST',
        data: {
            delete_kyc_document: 1,
            document_id: docId,
            csrf_token: csrfToken
        },
        dataType: 'json'
    })
    .done(function(res) {
        showLoading(false);
        if (res.success) {
            Swal.fire('Success', res.message, 'success');
            loadTab('kyc');
        } else {
            Swal.fire('Error', res.message, 'error');
        }
    })
    .fail(function() {
        showLoading(false);
        Swal.fire('Error', 'Failed to delete document', 'error');
    });
}

// ============================================
// FAMILY SEARCH
// ============================================

$('#familyPatientSearch').on('input', function() {
    const query = $(this).val().trim();
    const results = $('#familySearchResults');
    
    if (query.length < 2) {
        results.hide();
        return;
    }
    
    $.ajax({
        url: '../api/search_patients.php',
        data: { q: query, exclude: patientId },
        dataType: 'json'
    })
    .done(function(res) {
        if (res.success && res.data.length > 0) {
            let html = '';
            res.data.forEach(function(p) {
                html += `
                    <div class="p-2 border-bottom" style="cursor:pointer;" onclick="selectFamilyPatient(${p.id}, '${escapeHtml(p.name)} (${escapeHtml(p.mr_number)})')">
                        <strong>${escapeHtml(p.name)}</strong><br>
                        <small class="text-muted">MRN: ${escapeHtml(p.mr_number)}</small>
                    </div>
                `;
            });
            results.html(html).show();
        } else {
            results.html('<div class="p-2 text-muted">No patients found</div>').show();
        }
    })
    .fail(function() {
        results.hide();
    });
});

function selectFamilyPatient(id, name) {
    $('#familyPatientId').val(id);
    $('#familyPatientSearch').val(name);
    $('#familySearchResults').hide();
}

// ============================================
// EVENT BINDINGS
// ============================================

$(function() {
    // Tab clicks
    $('.tab-btn').on('click', function() {
        loadTab($(this).data('tab'));
    });
    
    // Refresh
    $('#refreshSection').on('click', function() {
        loadTab(activeTab);
    });
    
    // Family form submission
    $('#submitFamilyBtn').on('click', function() {
        const patientId = $('#familyPatientId').val();
        const relationship = $('select[name="relationship_type"]').val();
        
        if (!patientId || patientId == 0) {
            Swal.fire('Error', 'Please select a patient to link', 'error');
            return;
        }
        if (!relationship) {
            Swal.fire('Error', 'Please select a relationship type', 'error');
            return;
        }
        
        showLoading(true);
        const formData = $('#familyForm').serialize();
        
        $.ajax({
            url: window.location.pathname,
            method: 'POST',
            data: formData,
            dataType: 'json'
        })
        .done(function(res) {
            showLoading(false);
            if (res.success) {
                Swal.fire('Success', res.message, 'success');
                $('#familyModal').modal('hide');
                $('#familyForm')[0].reset();
                loadTab('family');
            } else {
                Swal.fire('Error', res.message, 'error');
            }
        })
        .fail(function() {
            showLoading(false);
            Swal.fire('Error', 'Failed to add family member', 'error');
        });
    });
    
    // Close search results on click outside
    $(document).on('click', function(e) {
        if (!$(e.target).closest('#familyPatientSearch').length && !$(e.target).closest('#familySearchResults').length) {
            $('#familySearchResults').hide();
        }
    });
});

// ============================================
// LOADING HELPER
// ============================================

function showLoading(show) {
    const overlay = document.getElementById('loadingOverlay');
    if (overlay) {
        overlay.classList.toggle('active', show);
    }
}

// ============================================
// INITIAL LOAD
// ============================================
loadTab('overview');
</script>

</body>
</html>