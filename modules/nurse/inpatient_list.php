<?php
/**
 * ======================================================================
 * INPATIENT LIST MANAGEMENT - COMPLETE INPATIENT ROSTER (Production Ready)
 * - View all active inpatients with bed assignments
 * - Manage patient admissions, transfers, and discharges
 * - Filter by ward, condition status, and search
 * - Detailed patient view with clinical summary
 * - Bed management integration
 * - Multi-tenancy | RBAC | CSRF | PDO | Responsive
 * ======================================================================
 */

session_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../../logs/php_errors.log');

require_once __DIR__ . '/../../config/db_connection.php';
require_once __DIR__ . '/../../includes/auth_helper.php';

date_default_timezone_set('Africa/Addis_Ababa');
if ($pdo) $pdo->exec("SET time_zone = '+03:00'");

$user_id = (int)($_SESSION['user_id'] ?? 0);
$branch_id = (int)($_SESSION['branch_id'] ?? 0);
$is_global_admin = ($_SESSION['user_type'] ?? '') === 'super_admin';
$user_name = $_SESSION['first_name'] ?? $_SESSION['username'] ?? 'User';
$user_type = $_SESSION['user_type'] ?? '';

if ($user_id <= 0) {
    header('Location: ../../index.php');
    exit;
}

$has_access = $is_global_admin || 
              user_has_permission($pdo, 'view_inpatient_admissions', $user_id) ||
              user_has_permission($pdo, 'view_admissions', $user_id) ||
              user_has_permission($pdo, 'ward_nursing_access', $user_id) ||
              user_has_permission($pdo, 'inpatient_management_access', $user_id) ||
              user_has_permission($pdo, 'manage_admissions', $user_id);

if (!$has_access) {
    header('Location: dashboard.php?error=unauthorized');
    exit;
}

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// ======================================================================
// HELPER FUNCTIONS
// ======================================================================

function jsonResponse($success, $message, $data = null, $code = 200) {
    if (ob_get_length()) ob_clean();
    http_response_code($code);
    header('Content-Type: application/json');
    $response = ['success' => $success, 'message' => $message];
    if ($data !== null) $response['data'] = $data;
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

function escapeHtml($str) {
    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}

function calculateAge($dob) {
    if (empty($dob)) return 'N/A';
    try {
        $birthDate = new DateTime($dob);
        $today = new DateTime();
        $age = $birthDate->diff($today);
        return $age->y;
    } catch (Exception $e) {
        return 'N/A';
    }
}

function getConditionBadge($condition) {
    $map = [
        'critical' => 'danger',
        'serious' => 'warning',
        'guarded' => 'info',
        'stable' => 'success',
        'deceased' => 'dark'
    ];
    return $map[$condition] ?? 'secondary';
}

function getAdmissionTypeLabel($type) {
    $map = [
        'emergency' => 'Emergency',
        'elective' => 'Elective',
        'transfer' => 'Transfer',
        'observation' => 'Observation'
    ];
    return $map[$type] ?? ucfirst($type);
}

function formatCurrency($amount) {
    return number_format((float)$amount, 2) . ' ETB';
}

// ======================================================================
// AJAX HANDLERS
// ======================================================================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'inpatient_action') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(false, 'Invalid request method');
    }

    $action = $_POST['action'] ?? '';
    
    $csrf_actions = ['transfer_patient', 'discharge_patient', 'update_condition', 'update_admission_notes', 'assign_bed', 'unassign_bed'];
    if (in_array($action, $csrf_actions)) {
        if (!hash_equals($csrf_token, (string)($_POST['csrf_token'] ?? ''))) {
            jsonResponse(false, 'Security token mismatch');
        }
    }

    try {
        // ---------- FETCH INPATIENT LIST ----------
        if ($action === 'fetch_inpatients') {
            $ward_filter = $_POST['ward_id'] ?? '';
            $condition_filter = $_POST['condition_status'] ?? '';
            $search_term = trim($_POST['search'] ?? '');
            
            $sql = "SELECT
                        a.id AS admission_id,
                        a.admission_number,
                        a.admitted_at,
                        a.condition_status,
                        a.admission_type,
                        a.primary_diagnosis,
                        a.admission_notes,
                        a.expected_discharge_at,
                        a.discharge_condition,
                        a.discharge_status,
                        a.discharge_summary,
                        p.id AS patient_id,
                        p.mr_number,
                        CONCAT(
                            COALESCE(p.first_name, ''),
                            ' ',
                            COALESCE(p.middle_name, ''),
                            ' ',
                            COALESCE(p.last_name, '')
                        ) AS patient_name,
                        p.gender,
                        p.date_of_birth,
                        p.phone,
                        p.address,
                        p.emergency_contact_name,
                        p.emergency_contact_phone,
                        b.id AS bed_id,
                        b.bed_number AS bed_code,
                        b.bed_type,
                        b.daily_rate AS bed_rate,
                        r.room_number,
                        r.room_type,
                        w.id AS ward_id,
                        w.name AS ward_name,
                        w.ward_type,
                        CONCAT(
                            COALESCE(d.first_name, ''),
                            ' ',
                            COALESCE(d.middle_name, ''),
                            ' ',
                            COALESCE(d.last_name, '')
                        ) AS doctor_name,
                        d.id AS doctor_id,
                        CONCAT(
                            COALESCE(adm.first_name, ''),
                            ' ',
                            COALESCE(adm.middle_name, ''),
                            ' ',
                            COALESCE(adm.last_name, '')
                        ) AS admitted_by_name
                    FROM admissions a
                    INNER JOIN patients p ON a.patient_id = p.id AND p.deleted_at IS NULL
                    LEFT JOIN beds b ON a.bed_id = b.id AND b.deleted_at IS NULL
                    LEFT JOIN rooms r ON b.room_id = r.id AND r.deleted_at IS NULL
                    LEFT JOIN wards w ON b.ward_id = w.id AND w.deleted_at IS NULL
                    LEFT JOIN users d ON a.doctor_id = d.id AND d.deleted_at IS NULL
                    LEFT JOIN users adm ON a.created_by_user_id = adm.id AND adm.deleted_at IS NULL
                    WHERE a.deleted_at IS NULL
                      AND a.status IN ('admitted', 'transferred')";

            $params = [];
            
            if (!$is_global_admin && $branch_id > 0) {
                $sql .= " AND a.branch_id = :branch_id";
                $params[':branch_id'] = $branch_id;
            }
            
            if (!empty($ward_filter)) {
                $sql .= " AND w.id = :ward_id";
                $params[':ward_id'] = $ward_filter;
            }
            
            if (!empty($condition_filter)) {
                $sql .= " AND a.condition_status = :condition";
                $params[':condition'] = $condition_filter;
            }
            
            // FIXED: Use unique parameter names for each LIKE condition
            if (!empty($search_term)) {
                $sql .= " AND (p.mr_number LIKE :search_mrn
                           OR p.first_name LIKE :search_first
                           OR p.last_name LIKE :search_last
                           OR p.phone LIKE :search_phone)";
                $searchLike = '%' . $search_term . '%';
                $params[':search_mrn'] = $searchLike;
                $params[':search_first'] = $searchLike;
                $params[':search_last'] = $searchLike;
                $params[':search_phone'] = $searchLike;
            }

            $sql .= " ORDER BY 
                        CASE a.condition_status 
                            WHEN 'critical' THEN 1
                            WHEN 'serious' THEN 2
                            WHEN 'guarded' THEN 3
                            WHEN 'stable' THEN 4
                            ELSE 5
                        END ASC,
                        a.admitted_at DESC";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $inpatients = [];
            foreach ($rows as $r) {
                $patient_name = preg_replace('/\s+/', ' ', trim($r['patient_name'] ?? 'Unknown Patient'));
                $doctor_name = preg_replace('/\s+/', ' ', trim($r['doctor_name'] ?? 'Unassigned'));
                $admitted_by = preg_replace('/\s+/', ' ', trim($r['admitted_by_name'] ?? 'Unknown'));
                
                $age = calculateAge($r['date_of_birth'] ?? null);
                $admitted_at = strtotime($r['admitted_at'] ?? 'now');
                
                $inpatients[] = [
                    'admission_id' => (int)$r['admission_id'],
                    'admission_number' => $r['admission_number'],
                    'patient_id' => (int)$r['patient_id'],
                    'patient_name' => $patient_name,
                    'mr_number' => $r['mr_number'] ?? '',
                    'age' => $age,
                    'gender' => $r['gender'] ?? 'N/A',
                    'phone' => $r['phone'] ?? '',
                    'address' => $r['address'] ?? '',
                    'emergency_contact_name' => $r['emergency_contact_name'] ?? '',
                    'emergency_contact_phone' => $r['emergency_contact_phone'] ?? '',
                    'bed_id' => $r['bed_id'] ? (int)$r['bed_id'] : null,
                    'bed_code' => $r['bed_code'] ?: 'Not Assigned',
                    'bed_type' => $r['bed_type'] ?? '',
                    'bed_rate' => (float)($r['bed_rate'] ?? 0),
                    'room_number' => $r['room_number'] ?? '',
                    'room_type' => $r['room_type'] ?? '',
                    'ward_id' => $r['ward_id'] ? (int)$r['ward_id'] : null,
                    'ward_name' => $r['ward_name'] ?? 'Unassigned',
                    'ward_type' => $r['ward_type'] ?? '',
                    'doctor_name' => $doctor_name,
                    'doctor_id' => $r['doctor_id'] ? (int)$r['doctor_id'] : null,
                    'admitted_by_name' => $admitted_by,
                    'condition_status' => $r['condition_status'] ?? 'stable',
                    'primary_diagnosis' => $r['primary_diagnosis'] ?? '',
                    'admission_type' => $r['admission_type'] ?? 'elective',
                    'admission_notes' => $r['admission_notes'] ?? '',
                    'admitted_date' => date('d M Y H:i', $admitted_at),
                    'expected_discharge_at' => $r['expected_discharge_at'] ? date('d M Y', strtotime($r['expected_discharge_at'])) : 'N/A',
                    'days_admitted' => floor((time() - $admitted_at) / 86400),
                    'discharge_condition' => $r['discharge_condition'] ?? '',
                    'discharge_status' => $r['discharge_status'] ?? '',
                    'discharge_summary' => $r['discharge_summary'] ?? ''
                ];
            }
            
            jsonResponse(true, '', ['inpatients' => $inpatients]);
        }

        // ---------- FETCH SINGLE INPATIENT DETAIL ----------
        if ($action === 'fetch_inpatient_detail') {
            $admission_id = (int)($_POST['admission_id'] ?? 0);
            if ($admission_id <= 0) {
                jsonResponse(false, 'Invalid admission ID');
            }

            $sql = "SELECT
                        a.*,
                        p.id AS patient_id,
                        p.mr_number,
                        CONCAT(
                            COALESCE(p.first_name, ''),
                            ' ',
                            COALESCE(p.middle_name, ''),
                            ' ',
                            COALESCE(p.last_name, '')
                        ) AS patient_name,
                        p.gender,
                        p.date_of_birth,
                        p.phone,
                        p.email,
                        p.address,
                        p.emergency_contact_name,
                        p.emergency_contact_phone,
                        p.emergency_contact_relationship,
                        p.blood_group,
                        p.national_id,
                        b.id AS bed_id,
                        b.bed_number AS bed_code,
                        b.bed_type,
                        b.daily_rate AS bed_rate,
                        r.room_number,
                        r.room_type,
                        w.id AS ward_id,
                        w.name AS ward_name,
                        w.ward_type,
                        CONCAT(
                            COALESCE(d.first_name, ''),
                            ' ',
                            COALESCE(d.middle_name, ''),
                            ' ',
                            COALESCE(d.last_name, '')
                        ) AS doctor_name,
                        CONCAT(
                            COALESCE(adm.first_name, ''),
                            ' ',
                            COALESCE(adm.middle_name, ''),
                            ' ',
                            COALESCE(adm.last_name, '')
                        ) AS created_by_name
                    FROM admissions a
                    INNER JOIN patients p ON a.patient_id = p.id AND p.deleted_at IS NULL
                    LEFT JOIN beds b ON a.bed_id = b.id AND b.deleted_at IS NULL
                    LEFT JOIN rooms r ON b.room_id = r.id AND r.deleted_at IS NULL
                    LEFT JOIN wards w ON b.ward_id = w.id AND w.deleted_at IS NULL
                    LEFT JOIN users d ON a.doctor_id = d.id AND d.deleted_at IS NULL
                    LEFT JOIN users adm ON a.created_by_user_id = adm.id AND adm.deleted_at IS NULL
                    WHERE a.id = :admission_id
                      AND a.deleted_at IS NULL";
            
            $params = [':admission_id' => $admission_id];
            if (!$is_global_admin && $branch_id > 0) {
                $sql .= " AND a.branch_id = :branch_id";
                $params[':branch_id'] = $branch_id;
            }
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $patient = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$patient) {
                jsonResponse(false, 'Patient admission not found');
            }

            $patient['patient_name'] = preg_replace('/\s+/', ' ', trim($patient['patient_name'] ?? 'Unknown Patient'));
            $patient['doctor_name'] = preg_replace('/\s+/', ' ', trim($patient['doctor_name'] ?? 'Unassigned'));
            $patient['created_by_name'] = preg_replace('/\s+/', ' ', trim($patient['created_by_name'] ?? 'Unknown'));
            $patient['age'] = calculateAge($patient['date_of_birth'] ?? null);

            jsonResponse(true, '', ['patient' => $patient]);
        }

        // ---------- FETCH AVAILABLE BEDS FOR TRANSFER ----------
        if ($action === 'fetch_available_beds') {
            $ward_id = (int)($_POST['ward_id'] ?? 0);
            if ($ward_id <= 0) {
                jsonResponse(false, 'Invalid ward ID');
            }

            $sql = "SELECT b.id, b.bed_number, b.bed_type, b.daily_rate, r.room_number
                    FROM beds b
                    LEFT JOIN rooms r ON b.room_id = r.id AND r.deleted_at IS NULL
                    WHERE b.ward_id = :ward_id 
                      AND b.status = 'available' 
                      AND b.deleted_at IS NULL";
            
            $params = [':ward_id' => $ward_id];
            if (!$is_global_admin && $branch_id > 0) {
                $sql .= " AND b.branch_id = :branch_id";
                $params[':branch_id'] = $branch_id;
            }
            
            $sql .= " ORDER BY b.bed_number";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $beds = $stmt->fetchAll(PDO::FETCH_ASSOC);

            jsonResponse(true, '', ['beds' => $beds]);
        }

        // ---------- TRANSFER PATIENT ----------
        if ($action === 'transfer_patient') {
            $admission_id = (int)($_POST['admission_id'] ?? 0);
            $new_ward_id = (int)($_POST['new_ward_id'] ?? 0);
            $new_bed_id = (int)($_POST['new_bed_id'] ?? 0);
            $transfer_reason = trim($_POST['transfer_reason'] ?? '');

            if ($admission_id <= 0 || $new_ward_id <= 0 || $new_bed_id <= 0) {
                jsonResponse(false, 'Missing required fields for transfer');
            }

            try {
                $pdo->beginTransaction();

                $stmt = $pdo->prepare("SELECT bed_id, patient_id, visit_id FROM admissions WHERE id = :id AND status = 'admitted' AND deleted_at IS NULL FOR UPDATE");
                $stmt->execute([':id' => $admission_id]);
                $admission = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$admission) {
                    throw new Exception('Admission not found or not active');
                }

                $stmt = $pdo->prepare("SELECT status FROM beds WHERE id = :id AND deleted_at IS NULL FOR UPDATE");
                $stmt->execute([':id' => $new_bed_id]);
                $bed = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$bed || $bed['status'] !== 'available') {
                    throw new Exception('New bed is not available');
                }

                if ($admission['bed_id']) {
                    $stmt = $pdo->prepare("UPDATE beds SET status = 'available', updated_at = NOW() WHERE id = :id");
                    $stmt->execute([':id' => $admission['bed_id']]);

                    $stmt = $pdo->prepare("UPDATE bed_allocations 
                        SET status = 'discharged', check_out_date = NOW(), updated_at = NOW() 
                        WHERE patient_id = :patient_id AND status = 'occupied' AND deleted_at IS NULL
                        ORDER BY id DESC LIMIT 1");
                    $stmt->execute([':patient_id' => $admission['patient_id']]);
                }

                $transfer_note = "\n[TRANSFER: " . date('Y-m-d H:i:s') . "] " . ($transfer_reason ?: 'Ward transfer') . 
                                " - New Bed: " . $new_bed_id;
                $stmt = $pdo->prepare("UPDATE admissions 
                    SET bed_id = :bed_id, updated_at = NOW(), admission_notes = CONCAT(COALESCE(admission_notes, ''), :note)
                    WHERE id = :id");
                $stmt->execute([
                    ':bed_id' => $new_bed_id,
                    ':note' => $transfer_note,
                    ':id' => $admission_id
                ]);

                $stmt = $pdo->prepare("UPDATE beds SET status = 'occupied', updated_at = NOW() WHERE id = :id");
                $stmt->execute([':id' => $new_bed_id]);

                $stmt = $pdo->prepare("INSERT INTO bed_allocations 
                    (patient_id, ward_id, visit_id, bed_number, check_in_date, status, assigned_by_user_id, created_at, updated_at)
                    SELECT 
                        :patient_id,
                        :ward_id,
                        :visit_id,
                        (SELECT bed_number FROM beds WHERE id = :bed_id),
                        NOW(),
                        'occupied',
                        :assigned_by,
                        NOW(),
                        NOW()");
                $stmt->execute([
                    ':patient_id' => $admission['patient_id'],
                    ':ward_id' => $new_ward_id,
                    ':visit_id' => $admission['visit_id'],
                    ':bed_id' => $new_bed_id,
                    ':assigned_by' => $user_id
                ]);

                $pdo->commit();
                jsonResponse(true, 'Patient transferred successfully');
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                jsonResponse(false, $e->getMessage());
            }
        }

        // ---------- DISCHARGE PATIENT ----------
        if ($action === 'discharge_patient') {
            $admission_id = (int)($_POST['admission_id'] ?? 0);
            $discharge_condition = $_POST['discharge_condition'] ?? 'improved';
            $discharge_summary = trim($_POST['discharge_summary'] ?? '');
            $discharge_instructions = trim($_POST['discharge_instructions'] ?? '');
            $follow_up_date = !empty($_POST['follow_up_date']) ? $_POST['follow_up_date'] : null;
            $home_medications = trim($_POST['home_medications'] ?? '');
            $warning_signs = trim($_POST['warning_signs'] ?? '');

            if ($admission_id <= 0) {
                jsonResponse(false, 'Invalid admission ID');
            }

            $valid_conditions = ['improved', 'stabilized', 'relieved', 'ama', 'expired', 'transferred'];
            if (!in_array($discharge_condition, $valid_conditions)) {
                jsonResponse(false, 'Invalid discharge condition');
            }

            try {
                $pdo->beginTransaction();

                $stmt = $pdo->prepare("SELECT bed_id, patient_id, visit_id FROM admissions WHERE id = :id AND status = 'admitted' AND deleted_at IS NULL FOR UPDATE");
                $stmt->execute([':id' => $admission_id]);
                $admission = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$admission) {
                    throw new Exception('Admission not found or already discharged');
                }

                $stmt = $pdo->prepare("UPDATE admissions 
                    SET status = 'discharged',
                        discharged_at = NOW(),
                        discharge_condition = :condition,
                        discharge_status = 'discharged',
                        discharge_summary = :summary,
                        discharge_instructions = :instructions,
                        follow_up_date = :follow_up,
                        home_medications = :medications,
                        warning_signs = :warning_signs,
                        updated_at = NOW()
                    WHERE id = :id");
                $stmt->execute([
                    ':condition' => $discharge_condition,
                    ':summary' => $discharge_summary,
                    ':instructions' => $discharge_instructions,
                    ':follow_up' => $follow_up_date,
                    ':medications' => $home_medications,
                    ':warning_signs' => $warning_signs,
                    ':id' => $admission_id
                ]);

                if ($admission['bed_id']) {
                    $stmt = $pdo->prepare("UPDATE beds SET status = 'available', updated_at = NOW() WHERE id = :id");
                    $stmt->execute([':id' => $admission['bed_id']]);

                    $stmt = $pdo->prepare("UPDATE bed_allocations 
                        SET status = 'discharged', check_out_date = NOW(), updated_at = NOW() 
                        WHERE patient_id = :patient_id AND status = 'occupied' AND deleted_at IS NULL
                        ORDER BY id DESC LIMIT 1");
                    $stmt->execute([':patient_id' => $admission['patient_id']]);
                }

                if ($admission['visit_id']) {
                    $stmt = $pdo->prepare("UPDATE patient_visits SET status = 'completed' WHERE id = :id");
                    $stmt->execute([':id' => $admission['visit_id']]);
                }

                $pdo->commit();
                jsonResponse(true, 'Patient discharged successfully');
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                jsonResponse(false, $e->getMessage());
            }
        }

        // ---------- UPDATE CONDITION STATUS ----------
        if ($action === 'update_condition') {
            $admission_id = (int)($_POST['admission_id'] ?? 0);
            $condition_status = $_POST['condition_status'] ?? '';
            $condition_notes = trim($_POST['condition_notes'] ?? '');

            $valid_conditions = ['stable', 'guarded', 'critical', 'serious', 'deceased'];
            if ($admission_id <= 0 || !in_array($condition_status, $valid_conditions)) {
                jsonResponse(false, 'Invalid input');
            }

            $note = "\n[CONDITION UPDATE: " . date('Y-m-d H:i:s') . "] Status changed to " . strtoupper($condition_status) . 
                    ($condition_notes ? " - " . $condition_notes : "");

            $stmt = $pdo->prepare("UPDATE admissions 
                SET condition_status = :condition,
                    admission_notes = CONCAT(COALESCE(admission_notes, ''), :note),
                    updated_at = NOW()
                WHERE id = :id AND deleted_at IS NULL");
            
            $stmt->execute([
                ':condition' => $condition_status,
                ':note' => $note,
                ':id' => $admission_id
            ]);

            jsonResponse(true, 'Condition status updated successfully');
        }

        // ---------- UPDATE ADMISSION NOTES ----------
        if ($action === 'update_admission_notes') {
            $admission_id = (int)($_POST['admission_id'] ?? 0);
            $notes = trim($_POST['notes'] ?? '');

            if ($admission_id <= 0 || empty($notes)) {
                jsonResponse(false, 'Invalid input');
            }

            $stmt = $pdo->prepare("UPDATE admissions 
                SET admission_notes = CONCAT(COALESCE(admission_notes, ''), '\n[', NOW(), '] ', :notes),
                    updated_at = NOW()
                WHERE id = :id AND deleted_at IS NULL");
            $stmt->execute([
                ':notes' => $notes,
                ':id' => $admission_id
            ]);

            jsonResponse(true, 'Notes added successfully');
        }

        // ---------- ASSIGN BED ----------
        if ($action === 'assign_bed') {
            $admission_id = (int)($_POST['admission_id'] ?? 0);
            $bed_id = (int)($_POST['bed_id'] ?? 0);
            
            if ($admission_id <= 0 || $bed_id <= 0) {
                jsonResponse(false, 'Invalid admission or bed ID');
            }

            try {
                $pdo->beginTransaction();

                $stmt = $pdo->prepare("SELECT id, patient_id, visit_id FROM admissions WHERE id = :id AND bed_id IS NULL AND status = 'admitted' AND deleted_at IS NULL FOR UPDATE");
                $stmt->execute([':id' => $admission_id]);
                $admission = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$admission) {
                    throw new Exception('Admission not found or already has a bed assigned');
                }

                $stmt = $pdo->prepare("SELECT status, bed_number, ward_id FROM beds WHERE id = :id AND status = 'available' AND deleted_at IS NULL FOR UPDATE");
                $stmt->execute([':id' => $bed_id]);
                $bed = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$bed) {
                    throw new Exception('Bed is not available');
                }

                $stmt = $pdo->prepare("UPDATE admissions SET bed_id = :bed_id, updated_at = NOW() WHERE id = :id");
                $stmt->execute([':bed_id' => $bed_id, ':id' => $admission_id]);

                $stmt = $pdo->prepare("UPDATE beds SET status = 'occupied', updated_at = NOW() WHERE id = :id");
                $stmt->execute([':id' => $bed_id]);

                $stmt = $pdo->prepare("INSERT INTO bed_allocations 
                    (patient_id, ward_id, visit_id, bed_number, check_in_date, status, assigned_by_user_id, created_at, updated_at)
                    VALUES (:patient_id, :ward_id, :visit_id, :bed_number, NOW(), 'occupied', :assigned_by, NOW(), NOW())");
                $stmt->execute([
                    ':patient_id' => $admission['patient_id'],
                    ':ward_id' => $bed['ward_id'],
                    ':visit_id' => $admission['visit_id'],
                    ':bed_number' => $bed['bed_number'],
                    ':assigned_by' => $user_id
                ]);

                $pdo->commit();
                jsonResponse(true, 'Bed assigned successfully');
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                jsonResponse(false, $e->getMessage());
            }
        }

        // ---------- UNASSIGN BED ----------
        if ($action === 'unassign_bed') {
            $admission_id = (int)($_POST['admission_id'] ?? 0);
            
            if ($admission_id <= 0) {
                jsonResponse(false, 'Invalid admission ID');
            }

            try {
                $pdo->beginTransaction();

                $stmt = $pdo->prepare("SELECT id, bed_id, patient_id FROM admissions WHERE id = :id AND bed_id IS NOT NULL AND status = 'admitted' AND deleted_at IS NULL FOR UPDATE");
                $stmt->execute([':id' => $admission_id]);
                $admission = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$admission) {
                    throw new Exception('Admission not found or no bed assigned');
                }

                $stmt = $pdo->prepare("UPDATE admissions SET bed_id = NULL, updated_at = NOW() WHERE id = :id");
                $stmt->execute([':id' => $admission_id]);

                $stmt = $pdo->prepare("UPDATE beds SET status = 'available', updated_at = NOW() WHERE id = :id");
                $stmt->execute([':id' => $admission['bed_id']]);

                $stmt = $pdo->prepare("UPDATE bed_allocations 
                    SET status = 'discharged', check_out_date = NOW(), updated_at = NOW() 
                    WHERE patient_id = :patient_id AND status = 'occupied' AND deleted_at IS NULL
                    ORDER BY id DESC LIMIT 1");
                $stmt->execute([':patient_id' => $admission['patient_id']]);

                $pdo->commit();
                jsonResponse(true, 'Bed unassigned successfully');
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                jsonResponse(false, $e->getMessage());
            }
        }

        jsonResponse(false, 'Unsupported action');
    } catch (PDOException $e) {
        error_log('Inpatient AJAX error: ' . $e->getMessage());
        jsonResponse(false, 'Database error occurred. Please try again.');
    } catch (Exception $e) {
        error_log('Inpatient AJAX error: ' . $e->getMessage());
        jsonResponse(false, $e->getMessage());
    }
    exit;
}

// ======================================================================
// INITIAL PAGE DATA
// ======================================================================
$wards = [];
$inpatients = [];
$condition_counts = [
    'critical' => 0,
    'serious' => 0,
    'guarded' => 0,
    'stable' => 0
];

try {
    $sql = "SELECT id, name FROM wards WHERE deleted_at IS NULL";
    $params = [];
    if (!$is_global_admin && $branch_id > 0) {
        $sql .= " AND branch_id = :branch_id";
        $params[':branch_id'] = $branch_id;
    }
    $sql .= " ORDER BY name";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $wards = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $sql = "SELECT
                a.id AS admission_id,
                a.admission_number,
                a.admitted_at,
                a.condition_status,
                a.admission_type,
                a.primary_diagnosis,
                a.expected_discharge_at,
                p.id AS patient_id,
                p.mr_number,
                CONCAT(
                    COALESCE(p.first_name, ''),
                    ' ',
                    COALESCE(p.middle_name, ''),
                    ' ',
                    COALESCE(p.last_name, '')
                ) AS patient_name,
                p.gender,
                p.date_of_birth,
                p.phone,
                b.id AS bed_id,
                b.bed_number AS bed_code,
                b.bed_type,
                r.room_number,
                w.id AS ward_id,
                w.name AS ward_name,
                CONCAT(
                    COALESCE(d.first_name, ''),
                    ' ',
                    COALESCE(d.middle_name, ''),
                    ' ',
                    COALESCE(d.last_name, '')
                ) AS doctor_name
            FROM admissions a
            INNER JOIN patients p ON a.patient_id = p.id AND p.deleted_at IS NULL AND p.is_active = 1
            LEFT JOIN beds b ON a.bed_id = b.id AND b.deleted_at IS NULL
            LEFT JOIN rooms r ON b.room_id = r.id AND r.deleted_at IS NULL
            LEFT JOIN wards w ON b.ward_id = w.id AND w.deleted_at IS NULL
            LEFT JOIN users d ON a.doctor_id = d.id AND d.deleted_at IS NULL
            WHERE a.discharged_at IS NULL
              AND a.status = 'admitted'
              AND a.deleted_at IS NULL";
    
    $params = [];
    if (!$is_global_admin && $branch_id > 0) {
        $sql .= " AND a.branch_id = :branch_id";
        $params[':branch_id'] = $branch_id;
    }
    
    $sql .= " ORDER BY 
                CASE a.condition_status 
                    WHEN 'critical' THEN 1
                    WHEN 'serious' THEN 2
                    WHEN 'guarded' THEN 3
                    ELSE 4
                END ASC,
                a.admitted_at DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $r) {
        $patient_name = preg_replace('/\s+/', ' ', trim($r['patient_name'] ?? 'Unknown Patient'));
        $doctor_name = preg_replace('/\s+/', ' ', trim($r['doctor_name'] ?? 'Unassigned'));
        
        $age = calculateAge($r['date_of_birth'] ?? null);
        
        $condition = $r['condition_status'] ?? 'stable';
        if (isset($condition_counts[$condition])) {
            $condition_counts[$condition]++;
        }
        
        $inpatients[] = [
            'admission_id' => (int)$r['admission_id'],
            'admission_number' => $r['admission_number'],
            'patient_id' => (int)$r['patient_id'],
            'patient_name' => $patient_name,
            'mr_number' => $r['mr_number'] ?? '',
            'age' => $age,
            'gender' => $r['gender'] ?? 'N/A',
            'phone' => $r['phone'] ?? '',
            'bed_id' => $r['bed_id'] ? (int)$r['bed_id'] : null,
            'bed_code' => $r['bed_code'] ?: 'Not Assigned',
            'bed_type' => $r['bed_type'] ?? '',
            'room_number' => $r['room_number'] ?? '',
            'ward_id' => $r['ward_id'] ? (int)$r['ward_id'] : null,
            'ward_name' => $r['ward_name'] ?? 'Unassigned',
            'doctor_name' => $doctor_name,
            'condition_status' => $condition,
            'primary_diagnosis' => $r['primary_diagnosis'] ?? '',
            'admission_type' => $r['admission_type'] ?? 'elective',
            'admitted_date' => date('d M Y', strtotime($r['admitted_at'])),
            'expected_discharge_at' => $r['expected_discharge_at'] ? date('d M Y', strtotime($r['expected_discharge_at'])) : 'N/A',
            'days_admitted' => floor((time() - strtotime($r['admitted_at'])) / 86400)
        ];
    }
} catch (PDOException $e) {
    error_log('Inpatient list initial data error: ' . $e->getMessage());
}

$current_date = date('l, F j, Y');
$current_time = date('g:i A');
$total_inpatients = count($inpatients);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include('assets/inc/head.php'); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inpatient List | HMS ERP</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root {
            --primary: #4361ee;
            --primary-dark: #3a0ca3;
            --primary-light: #667eea;
            --success: #06d6a0;
            --warning: #f9c74f;
            --danger: #ef476f;
            --info: #4cc9f0;
            --dark: #1a1a2e;
            --gray: #6c757d;
            --gray-light: #e9ecef;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: #f5f7fb; overflow-x: hidden; }
        .content-page { margin-left: 260px; margin-top: 70px; padding: 20px 24px; transition: margin-left 0.3s; min-height: calc(100vh - 70px); }
        body.erp-sidebar-collapsed .content-page { margin-left: 80px; }
        @media (max-width: 768px) { .content-page { margin-left: 0 !important; padding: 16px; } }
        
        .welcome-header {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--primary) 100%);
            border-radius: 24px;
            padding: 16px 24px;
            color: white;
            margin-bottom: 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
        }
        .welcome-title { font-size: 22px; font-weight: 700; margin: 0; }
        .welcome-subtitle { font-size: 14px; opacity: 0.85; }
        .time-badge { background: rgba(255,255,255,0.15); padding: 6px 14px; border-radius: 12px; font-size: 13px; display: inline-flex; align-items: center; gap: 8px; }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }
        .stat-card {
            background: white;
            border-radius: 16px;
            padding: 14px 18px;
            border: 1px solid var(--gray-light);
            transition: all 0.3s;
            cursor: default;
        }
        .stat-card:hover { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(0,0,0,0.06); }
        .stat-number { font-size: 26px; font-weight: 800; line-height: 1.2; }
        .stat-label { font-size: 11px; color: var(--gray); font-weight: 500; text-transform: uppercase; letter-spacing: 0.5px; }
        .stat-card.critical .stat-number { color: #dc3545; }
        .stat-card.serious .stat-number { color: #d97706; }
        .stat-card.guarded .stat-number { color: #2563eb; }
        .stat-card.stable .stat-number { color: #059669; }
        .stat-card.total .stat-number { color: var(--primary); }
        
        .filter-bar {
            background: white;
            border-radius: 16px;
            padding: 14px 18px;
            border: 1px solid var(--gray-light);
            margin-bottom: 24px;
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: center;
        }
        .filter-bar .form-control,
        .filter-bar .form-select {
            border-radius: 10px;
            border: 1px solid var(--gray-light);
            padding: 7px 12px;
            font-size: 13px;
        }
        .filter-bar .form-control:focus,
        .filter-bar .form-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.15);
        }
        
        .table-container {
            background: white;
            border-radius: 20px;
            border: 1px solid var(--gray-light);
            overflow: hidden;
        }
        .table-header {
            padding: 14px 20px;
            background: #f8fafc;
            border-bottom: 1px solid var(--gray-light);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }
        .table-header h3 { margin: 0; font-size: 18px; font-weight: 700; }
        .data-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .data-table th {
            padding: 10px 12px;
            font-size: 10px;
            font-weight: 600;
            color: var(--gray);
            text-transform: uppercase;
            background: #f8fafc;
            border-bottom: 2px solid var(--gray-light);
            text-align: left;
            position: sticky;
            top: 0;
            z-index: 5;
            white-space: nowrap;
        }
        .data-table td { padding: 10px 12px; border-bottom: 1px solid #f3f4f6; vertical-align: middle; }
        .data-table tr:hover { background: #f8fafc; }
        .data-table tr.critical-row { background: #fef2f2; }
        .data-table tr.critical-row:hover { background: #fee2e2; }
        
        .scrollable-table { max-height: 550px; overflow-y: auto; }
        .scrollable-table::-webkit-scrollbar { width: 4px; }
        .scrollable-table::-webkit-scrollbar-track { background: #f1f1f1; border-radius: 4px; }
        .scrollable-table::-webkit-scrollbar-thumb { background: var(--primary); border-radius: 4px; }
        
        .status-badge {
            display: inline-block;
            padding: 2px 10px;
            border-radius: 20px;
            font-size: 10px;
            font-weight: 600;
            text-transform: capitalize;
        }
        .status-critical { background: #fee2e2; color: #dc2626; animation: pulseCritical 2s infinite; }
        .status-serious { background: #fef3c7; color: #d97706; }
        .status-guarded { background: #dbeafe; color: #2563eb; }
        .status-stable { background: #d1fae5; color: #059669; }
        .status-deceased { background: #e5e7eb; color: #4b5563; }
        @keyframes pulseCritical { 0%, 100% { opacity: 1; } 50% { opacity: 0.6; } }
        
        .bed-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 10px;
            font-weight: 600;
            background: #e5e7eb;
            color: #4b5563;
        }
        .bed-badge.assigned { background: #dbeafe; color: #1e40af; }
        .bed-badge.unassigned { background: #fef3c7; color: #92400e; }
        
        .btn-sm-icon {
            padding: 3px 8px;
            border-radius: 6px;
            font-size: 11px;
            margin: 0 1px;
            display: inline-block;
            border: 1px solid var(--primary);
            color: var(--primary);
            background: white;
            text-decoration: none;
            transition: all 0.2s;
            cursor: pointer;
        }
        .btn-sm-icon:hover { background: var(--primary); color: white; }
        .btn-sm-icon.danger { border-color: var(--danger); color: var(--danger); }
        .btn-sm-icon.danger:hover { background: var(--danger); color: white; }
        .btn-sm-icon.success { border-color: var(--success); color: var(--success); }
        .btn-sm-icon.success:hover { background: var(--success); color: white; }
        .btn-sm-icon.warning { border-color: var(--warning); color: #d97706; }
        .btn-sm-icon.warning:hover { background: var(--warning); color: white; }
        
        .toast-notification {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: white;
            border-radius: 12px;
            padding: 12px 20px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
            z-index: 1100;
            animation: slideIn 0.3s ease;
            border-left: 4px solid var(--success);
            max-width: 400px;
        }
        .toast-error { border-left-color: var(--danger); }
        @keyframes slideIn { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
        
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
        .loading-overlay.active { display: flex; }
        .spinner {
            width: 40px;
            height: 40px;
            border: 3px solid var(--gray-light);
            border-top-color: var(--primary);
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
        
        .empty-state { text-align: center; padding: 40px; color: var(--gray); }
        .empty-state i { font-size: 40px; margin-bottom: 12px; opacity: 0.3; }
        
        .modal-content { border-radius: 16px; border: none; }
        .modal-header { border-bottom: 1px solid var(--gray-light); padding: 16px 24px; }
        .modal-body { padding: 24px; }
        .modal-footer { border-top: 1px solid var(--gray-light); padding: 16px 24px; }
        .detail-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px 16px;
        }
        .detail-item { margin-bottom: 6px; }
        .detail-item .label { font-size: 10px; color: var(--gray); text-transform: uppercase; font-weight: 600; letter-spacing: 0.3px; }
        .detail-item .value { font-size: 13px; font-weight: 500; }
        .detail-section { margin-bottom: 16px; }
        .detail-section h6 { font-size: 14px; font-weight: 700; margin-bottom: 8px; }
        
        @media (max-width: 992px) {
            .detail-grid { grid-template-columns: 1fr; }
        }
        @media (max-width: 768px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .filter-bar .form-control,
            .filter-bar .form-select { width: 100%; }
            .data-table { font-size: 12px; }
            .data-table th,
            .data-table td { padding: 6px 8px; }
        }
        @media (max-width: 480px) {
            .stats-grid { grid-template-columns: 1fr; }
        }
        
        .text-truncate-2 {
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
    </style>
</head>
<body>
<div id="wrapper">
    <?php include('assets/inc/nav.php'); ?>
    <?php include('assets/inc/sidebar.php'); ?>

    <div class="content-page">
        <div class="container-fluid">
            <!-- Welcome Header -->
            <div class="welcome-header">
                <div>
                    <div class="welcome-title">Inpatient List</div>
                    <div class="welcome-subtitle">Active inpatients roster with bed assignments and clinical status</div>
                </div>
                <div class="time-badge">
                    <i class="far fa-calendar-alt"></i> <?php echo escapeHtml($current_date); ?>
                    <i class="far fa-clock ms-2"></i> <?php echo escapeHtml($current_time); ?>
                </div>
            </div>

            <!-- Stats -->
            <div class="stats-grid">
                <div class="stat-card total">
                    <div class="stat-number" id="statTotal"><?php echo $total_inpatients; ?></div>
                    <div class="stat-label">Total Inpatients</div>
                </div>
                <div class="stat-card critical">
                    <div class="stat-number" id="statCritical"><?php echo $condition_counts['critical']; ?></div>
                    <div class="stat-label">Critical</div>
                </div>
                <div class="stat-card serious">
                    <div class="stat-number" id="statSerious"><?php echo $condition_counts['serious']; ?></div>
                    <div class="stat-label">Serious</div>
                </div>
                <div class="stat-card guarded">
                    <div class="stat-number" id="statGuarded"><?php echo $condition_counts['guarded']; ?></div>
                    <div class="stat-label">Guarded</div>
                </div>
                <div class="stat-card stable">
                    <div class="stat-number" id="statStable"><?php echo $condition_counts['stable']; ?></div>
                    <div class="stat-label">Stable</div>
                </div>
            </div>

            <!-- Filter Bar -->
            <div class="filter-bar">
                <div style="flex:1; min-width:180px;">
                    <input type="text" class="form-control" id="searchInput" placeholder="🔍 Search by name, MRN, or phone...">
                </div>
                <div style="min-width:140px;">
                    <select class="form-select" id="wardFilter">
                        <option value="">All Wards</option>
                        <?php foreach ($wards as $ward): ?>
                            <option value="<?php echo $ward['id']; ?>"><?php echo escapeHtml($ward['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="min-width:130px;">
                    <select class="form-select" id="conditionFilter">
                        <option value="">All Conditions</option>
                        <option value="critical">Critical</option>
                        <option value="serious">Serious</option>
                        <option value="guarded">Guarded</option>
                        <option value="stable">Stable</option>
                        <option value="deceased">Deceased</option>
                    </select>
                </div>
                <button class="btn btn-primary btn-sm" onclick="applyFilters()"><i class="fas fa-filter"></i> Filter</button>
                <button class="btn btn-outline-secondary btn-sm" onclick="resetFilters()"><i class="fas fa-times"></i> Reset</button>
                <button class="btn btn-outline-primary btn-sm ms-auto" onclick="refreshData()"><i class="fas fa-sync-alt"></i> Refresh</button>
                <a href="admissions.php" class="btn btn-success btn-sm"><i class="fas fa-plus"></i> New Admission</a>
            </div>

            <!-- Table -->
            <div class="table-container">
                <div class="table-header">
                    <h3><i class="fas fa-procedures me-2"></i> Active Inpatients</h3>
                    <span class="text-muted small" id="recordCount"><?php echo $total_inpatients; ?> patients</span>
                </div>
                <div class="scrollable-table">
                    <table class="data-table" id="inpatientTable">
                        <thead>
                            <tr>
                                <th>Admission #</th>
                                <th>Patient</th>
                                <th>MRN</th>
                                <th>Age/Gender</th>
                                <th>Ward / Bed</th>
                                <th>Diagnosis</th>
                                <th>Status</th>
                                <th>Attending</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="inpatientBody">
                            <?php if (!empty($inpatients)): ?>
                                <?php foreach ($inpatients as $row): 
                                    $isCritical = $row['condition_status'] === 'critical' || $row['condition_status'] === 'serious';
                                    $trClass = $isCritical ? 'critical-row' : '';
                                ?>
                                <tr class="<?php echo $trClass; ?>" data-admission-id="<?php echo $row['admission_id']; ?>">
                                    <td><strong><?php echo escapeHtml($row['admission_number']); ?></strong></td>
                                    <td>
                                        <strong><?php echo escapeHtml($row['patient_name']); ?></strong>
                                        <?php if ($row['condition_status'] === 'critical'): ?>
                                            <span class="badge bg-danger" style="font-size:8px;">⚠️ CRITICAL</span>
                                        <?php elseif ($row['condition_status'] === 'serious'): ?>
                                            <span class="badge bg-warning text-dark" style="font-size:8px;">⚠️ SERIOUS</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo escapeHtml($row['mr_number']); ?></td>
                                    <td><?php echo $row['age']; ?> yrs<br><small class="text-muted"><?php echo ucfirst($row['gender'] ?? 'N/A'); ?></small></td>
                                    <td>
                                        <?php if (!empty($row['bed_code']) && $row['bed_code'] !== 'Not Assigned'): ?>
                                            <span class="bed-badge assigned"><?php echo escapeHtml($row['bed_code']); ?></span>
                                            <br><small class="text-muted"><?php echo escapeHtml($row['ward_name']); ?></small>
                                        <?php else: ?>
                                            <span class="bed-badge unassigned">Unassigned</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-truncate-2" style="max-width:150px;"><?php echo escapeHtml(substr($row['primary_diagnosis'] ?? '', 0, 40)); ?></td>
                                    <td>
                                        <span class="status-badge status-<?php echo $row['condition_status'] ?? 'stable'; ?>">
                                            <?php echo ucfirst($row['condition_status'] ?? 'stable'); ?>
                                        </span>
                                        <br><small class="text-muted">Day <?php echo $row['days_admitted']; ?></small>
                                    </td>
                                    <td><?php echo escapeHtml($row['doctor_name']); ?></td>
                                    <td>
                                        <button class="btn-sm-icon" onclick="viewPatient(<?php echo $row['admission_id']; ?>)" title="View Details">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <button class="btn-sm-icon success" onclick="editPatient(<?php echo $row['admission_id']; ?>)" title="Edit Status">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button class="btn-sm-icon" onclick="transferPatient(<?php echo $row['admission_id']; ?>)" title="Transfer">
                                            <i class="fas fa-exchange-alt"></i>
                                        </button>
                                        <button class="btn-sm-icon danger" onclick="dischargePatient(<?php echo $row['admission_id']; ?>)" title="Discharge">
                                            <i class="fas fa-sign-out-alt"></i>
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="9" class="text-center text-muted py-4">
                                        <i class="fas fa-procedures fa-2x d-block mb-2" style="opacity:0.3;"></i>
                                        No active inpatients found
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <?php include('assets/inc/footer.php'); ?>
</div>

<!-- Loading Overlay -->
<div id="loadingOverlay" class="loading-overlay"><div class="spinner"></div></div>

<!-- Patient Detail Modal -->
<div class="modal fade" id="patientDetailModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-user-injured me-2"></i> Patient Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="detailLoading" class="text-center py-4">
                    <div class="spinner-border text-primary" role="status"></div>
                    <p class="mt-2">Loading patient details...</p>
                </div>
                <div id="detailContent" style="display:none;">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="detail-section">
                                <h6 class="text-primary"><i class="fas fa-user me-2"></i>Personal Information</h6>
                                <div class="detail-grid">
                                    <div class="detail-item"><div class="label">Name</div><div class="value" id="detailName">-</div></div>
                                    <div class="detail-item"><div class="label">MRN</div><div class="value" id="detailMRN">-</div></div>
                                    <div class="detail-item"><div class="label">Age</div><div class="value" id="detailAge">-</div></div>
                                    <div class="detail-item"><div class="label">Gender</div><div class="value" id="detailGender">-</div></div>
                                    <div class="detail-item"><div class="label">Blood Group</div><div class="value" id="detailBloodGroup">-</div></div>
                                    <div class="detail-item"><div class="label">Phone</div><div class="value" id="detailPhone">-</div></div>
                                    <div class="detail-item"><div class="label">National ID</div><div class="value" id="detailNationalId">-</div></div>
                                    <div class="detail-item"><div class="label">Address</div><div class="value" id="detailAddress">-</div></div>
                                </div>
                            </div>
                            <div class="detail-section">
                                <h6 class="text-primary"><i class="fas fa-phone me-2"></i>Emergency Contact</h6>
                                <div class="detail-grid">
                                    <div class="detail-item"><div class="label">Name</div><div class="value" id="detailEmergencyName">-</div></div>
                                    <div class="detail-item"><div class="label">Phone</div><div class="value" id="detailEmergencyPhone">-</div></div>
                                    <div class="detail-item"><div class="label">Relationship</div><div class="value" id="detailEmergencyRelation">-</div></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="detail-section">
                                <h6 class="text-success"><i class="fas fa-hospital-user me-2"></i>Admission Details</h6>
                                <div class="detail-grid">
                                    <div class="detail-item"><div class="label">Admission #</div><div class="value" id="detailAdmissionNumber">-</div></div>
                                    <div class="detail-item"><div class="label">Admission Date</div><div class="value" id="detailAdmissionDate">-</div></div>
                                    <div class="detail-item"><div class="label">Admission Type</div><div class="value" id="detailAdmissionType">-</div></div>
                                    <div class="detail-item"><div class="label">Condition Status</div><div class="value" id="detailCondition">-</div></div>
                                    <div class="detail-item"><div class="label">Ward</div><div class="value" id="detailWard">-</div></div>
                                    <div class="detail-item"><div class="label">Bed</div><div class="value" id="detailBed">-</div></div>
                                    <div class="detail-item"><div class="label">Attending Doctor</div><div class="value" id="detailDoctor">-</div></div>
                                    <div class="detail-item"><div class="label">Admitted By</div><div class="value" id="detailAdmittedBy">-</div></div>
                                    <div class="detail-item"><div class="label">Days Admitted</div><div class="value" id="detailDaysAdmitted">-</div></div>
                                    <div class="detail-item"><div class="label">Expected Discharge</div><div class="value" id="detailExpectedDischarge">-</div></div>
                                </div>
                            </div>
                            <div class="detail-section">
                                <h6 class="text-success"><i class="fas fa-notes-medical me-2"></i>Clinical Information</h6>
                                <div class="detail-item"><div class="label">Primary Diagnosis</div><div class="value" id="detailDiagnosis">-</div></div>
                                <div class="detail-item"><div class="label">Admission Notes</div><div class="value" id="detailNotes" style="max-height:80px;overflow-y:auto;font-size:13px;background:#f8fafc;padding:8px;border-radius:8px;">-</div></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary" onclick="editPatientFromModal()"><i class="fas fa-edit"></i> Edit</button>
                <button type="button" class="btn btn-danger" onclick="dischargePatientFromModal()"><i class="fas fa-sign-out-alt"></i> Discharge</button>
            </div>
        </div>
    </div>
</div>

<!-- Discharge Modal -->
<div class="modal fade" id="dischargeModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-sign-out-alt me-2 text-danger"></i> Discharge Patient</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="dischargeForm">
                    <div class="mb-3">
                        <label class="form-label">Patient</label>
                        <p class="fw-bold" id="dischargePatientName">-</p>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Discharge Condition *</label>
                        <select name="discharge_condition" id="dischargeCondition" class="form-select" required>
                            <option value="improved">Improved</option>
                            <option value="stabilized">Stabilized</option>
                            <option value="relieved">Relieved</option>
                            <option value="ama">AMA (Against Medical Advice)</option>
                            <option value="transferred">Transferred</option>
                            <option value="expired">Expired</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Discharge Summary</label>
                        <textarea name="discharge_summary" id="dischargeSummary" class="form-control" rows="3" placeholder="Clinical summary at discharge..."></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Discharge Instructions</label>
                        <textarea name="discharge_instructions" id="dischargeInstructions" class="form-control" rows="2" placeholder="Instructions for patient/caregiver..."></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Follow-up Date</label>
                        <input type="date" name="follow_up_date" id="dischargeFollowUp" class="form-control">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Home Medications</label>
                        <textarea name="home_medications" id="dischargeMedications" class="form-control" rows="2" placeholder="Medications to continue at home..."></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Warning Signs</label>
                        <textarea name="warning_signs" id="dischargeWarningSigns" class="form-control" rows="2" placeholder="Warning signs to watch for..."></textarea>
                    </div>
                    <input type="hidden" id="dischargeAdmissionId" value="">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" onclick="confirmDischarge()"><i class="fas fa-check"></i> Discharge Patient</button>
            </div>
        </div>
    </div>
</div>

<!-- Transfer Modal -->
<div class="modal fade" id="transferModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-exchange-alt me-2 text-primary"></i> Transfer Patient</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="transferForm">
                    <div class="mb-3">
                        <label class="form-label">Patient</label>
                        <p class="fw-bold" id="transferPatientName">-</p>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Destination Ward *</label>
                        <select name="new_ward_id" id="transferWard" class="form-select" required>
                            <option value="">Select Ward</option>
                            <?php foreach ($wards as $ward): ?>
                                <option value="<?php echo $ward['id']; ?>"><?php echo escapeHtml($ward['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Destination Bed *</label>
                        <select name="new_bed_id" id="transferBed" class="form-select" required>
                            <option value="">Select Ward first</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Transfer Reason</label>
                        <textarea name="transfer_reason" id="transferReason" class="form-control" rows="2" placeholder="Reason for transfer..."></textarea>
                    </div>
                    <input type="hidden" id="transferAdmissionId" value="">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="confirmTransfer()"><i class="fas fa-check"></i> Transfer</button>
            </div>
        </div>
    </div>
</div>

<!-- Edit Modal -->
<div class="modal fade" id="editModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-edit me-2 text-primary"></i> Update Patient Status</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="editForm">
                    <div class="mb-3">
                        <label class="form-label">Patient</label>
                        <p class="fw-bold" id="editPatientName">-</p>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Condition Status *</label>
                        <select name="condition_status" id="editCondition" class="form-select" required>
                            <option value="stable">Stable</option>
                            <option value="guarded">Guarded</option>
                            <option value="serious">Serious</option>
                            <option value="critical">Critical</option>
                            <option value="deceased">Deceased</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Clinical Notes</label>
                        <textarea name="notes" id="editNotes" class="form-control" rows="3" placeholder="Add clinical notes..."></textarea>
                    </div>
                    <input type="hidden" id="editAdmissionId" value="">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="confirmEdit()"><i class="fas fa-save"></i> Update</button>
            </div>
        </div>
    </div>
</div>

<script src="assets/js/vendor.min.js"></script>
<script src="assets/js/bootstrap.bundle.min.js"></script>
<script>
// ======================================================================
// GLOBALS
// ======================================================================
const csrfToken = <?php echo json_encode($csrf_token); ?>;
const branchId = <?php echo (int)$branch_id; ?>;
let refreshInterval = null;
let currentPatientId = null;

// ======================================================================
// UTILITY FUNCTIONS
// ======================================================================
function showToast(message, type = 'success') {
    const toast = document.createElement('div');
    toast.className = `toast-notification ${type === 'error' ? 'toast-error' : ''}`;
    const icon = type === 'error' ? 'exclamation-circle' : (type === 'warning' ? 'exclamation-triangle' : 'check-circle');
    toast.innerHTML = `<i class="fas fa-${icon}"></i> <span>${escapeHtml(message)}</span>`;
    document.body.appendChild(toast);
    setTimeout(() => toast.remove(), 3500);
}

function escapeHtml(unsafe) {
    if (!unsafe) return '';
    return String(unsafe).replace(/[&<>]/g, m => m === '&' ? '&amp;' : (m === '<' ? '&lt;' : '&gt;'));
}

function showLoading(show) {
    document.getElementById('loadingOverlay').classList.toggle('active', show);
}

// ======================================================================
// FETCH INPATIENTS
// ======================================================================
function fetchInpatients() {
    const search = document.getElementById('searchInput').value;
    const wardId = document.getElementById('wardFilter').value;
    const condition = document.getElementById('conditionFilter').value;

    showLoading(true);
    $.post(window.location.pathname + '?ajax=inpatient_action', {
        action: 'fetch_inpatients',
        branch_id: branchId,
        search: search,
        ward_id: wardId,
        condition_status: condition,
        csrf_token: csrfToken
    }, function(res) {
        showLoading(false);
        if (res.success && res.data && res.data.inpatients) {
            renderInpatients(res.data.inpatients);
            updateStats(res.data.inpatients);
            document.getElementById('recordCount').textContent = res.data.inpatients.length + ' patients';
        } else {
            showToast(res.message || 'Failed to load patients', 'error');
        }
    }).fail(function() {
        showLoading(false);
        showToast('Network error. Please try again.', 'error');
    });
}

function renderInpatients(inpatients) {
    const tbody = document.getElementById('inpatientBody');
    if (!inpatients || inpatients.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="9" class="text-center text-muted py-4">
                    <i class="fas fa-procedures fa-2x d-block mb-2" style="opacity:0.3;"></i>
                    No active inpatients found
                </td>
            </tr>
        `;
        return;
    }

    let html = '';
    inpatients.forEach(row => {
        const isCritical = row.condition_status === 'critical' || row.condition_status === 'serious';
        const trClass = isCritical ? 'critical-row' : '';
        const conditionLabel = row.condition_status || 'stable';
        
        html += `
            <tr class="${trClass}" data-admission-id="${row.admission_id}">
                <td><strong>${escapeHtml(row.admission_number)}</strong></td>
                <td>
                    <strong>${escapeHtml(row.patient_name)}</strong>
                    ${row.condition_status === 'critical' ? '<span class="badge bg-danger" style="font-size:8px;">⚠️ CRITICAL</span>' : ''}
                    ${row.condition_status === 'serious' ? '<span class="badge bg-warning text-dark" style="font-size:8px;">⚠️ SERIOUS</span>' : ''}
                </td>
                <td>${escapeHtml(row.mr_number)}</td>
                <td>${row.age} yrs<br><small class="text-muted">${escapeHtml(row.gender || 'N/A')}</small></td>
                <td>
                    ${row.bed_code && row.bed_code !== 'Not Assigned' ? 
                        `<span class="bed-badge assigned">${escapeHtml(row.bed_code)}</span>
                         <br><small class="text-muted">${escapeHtml(row.ward_name)}</small>` : 
                        `<span class="bed-badge unassigned">Unassigned</span>`
                    }
                </td>
                <td class="text-truncate-2" style="max-width:150px;">${escapeHtml((row.primary_diagnosis || '').substring(0, 40))}</td>
                <td>
                    <span class="status-badge status-${conditionLabel}">
                        ${conditionLabel.charAt(0).toUpperCase() + conditionLabel.slice(1)}
                    </span>
                    <br><small class="text-muted">Day ${row.days_admitted || 0}</small>
                </td>
                <td>${escapeHtml(row.doctor_name)}</td>
                <td>
                    <button class="btn-sm-icon" onclick="viewPatient(${row.admission_id})" title="View Details">
                        <i class="fas fa-eye"></i>
                    </button>
                    <button class="btn-sm-icon success" onclick="editPatient(${row.admission_id})" title="Edit Status">
                        <i class="fas fa-edit"></i>
                    </button>
                    <button class="btn-sm-icon" onclick="transferPatient(${row.admission_id})" title="Transfer">
                        <i class="fas fa-exchange-alt"></i>
                    </button>
                    <button class="btn-sm-icon danger" onclick="dischargePatient(${row.admission_id})" title="Discharge">
                        <i class="fas fa-sign-out-alt"></i>
                    </button>
                </td>
            </tr>
        `;
    });
    tbody.innerHTML = html;
}

function updateStats(inpatients) {
    let total = inpatients.length;
    let critical = 0, serious = 0, guarded = 0, stable = 0;
    
    inpatients.forEach(p => {
        switch(p.condition_status) {
            case 'critical': critical++; break;
            case 'serious': serious++; break;
            case 'guarded': guarded++; break;
            default: stable++;
        }
    });
    
    document.getElementById('statTotal').textContent = total;
    document.getElementById('statCritical').textContent = critical;
    document.getElementById('statSerious').textContent = serious;
    document.getElementById('statGuarded').textContent = guarded;
    document.getElementById('statStable').textContent = stable;
}

// ======================================================================
// VIEW PATIENT DETAIL
// ======================================================================
function viewPatient(admissionId) {
    const modal = new bootstrap.Modal(document.getElementById('patientDetailModal'));
    document.getElementById('detailLoading').style.display = 'block';
    document.getElementById('detailContent').style.display = 'none';
    currentPatientId = admissionId;
    modal.show();

    $.post(window.location.pathname + '?ajax=inpatient_action', {
        action: 'fetch_inpatient_detail',
        admission_id: admissionId,
        branch_id: branchId,
        csrf_token: csrfToken
    }, function(res) {
        document.getElementById('detailLoading').style.display = 'none';
        if (res.success && res.data && res.data.patient) {
            const p = res.data.patient;
            
            // Personal Info
            document.getElementById('detailName').textContent = p.patient_name || '-';
            document.getElementById('detailMRN').textContent = p.mr_number || '-';
            document.getElementById('detailAge').textContent = p.age || '-';
            document.getElementById('detailGender').textContent = p.gender ? p.gender.charAt(0).toUpperCase() + p.gender.slice(1) : '-';
            document.getElementById('detailBloodGroup').textContent = p.blood_group || '-';
            document.getElementById('detailPhone').textContent = p.phone || '-';
            document.getElementById('detailNationalId').textContent = p.national_id || '-';
            document.getElementById('detailAddress').textContent = p.address || '-';
            
            // Emergency Contact
            document.getElementById('detailEmergencyName').textContent = p.emergency_contact_name || '-';
            document.getElementById('detailEmergencyPhone').textContent = p.emergency_contact_phone || '-';
            document.getElementById('detailEmergencyRelation').textContent = p.emergency_contact_relationship || '-';
            
            // Admission Details
            document.getElementById('detailAdmissionNumber').textContent = p.admission_number || '-';
            document.getElementById('detailAdmissionDate').textContent = p.admitted_at ? new Date(p.admitted_at).toLocaleString() : '-';
            document.getElementById('detailAdmissionType').textContent = p.admission_type ? 
                p.admission_type.charAt(0).toUpperCase() + p.admission_type.slice(1) : '-';
            document.getElementById('detailCondition').innerHTML = p.condition_status ? 
                `<span class="status-badge status-${p.condition_status}">${p.condition_status.charAt(0).toUpperCase() + p.condition_status.slice(1)}</span>` : '-';
            document.getElementById('detailWard').textContent = p.ward_name || '-';
            document.getElementById('detailBed').textContent = p.bed_code || 'Not Assigned';
            document.getElementById('detailDoctor').textContent = p.doctor_name || '-';
            document.getElementById('detailAdmittedBy').textContent = p.created_by_name || '-';
            document.getElementById('detailDaysAdmitted').textContent = p.admitted_at ? 
                Math.floor((Date.now() - new Date(p.admitted_at).getTime()) / (1000 * 60 * 60 * 24)) + ' days' : '-';
            document.getElementById('detailExpectedDischarge').textContent = p.expected_discharge_at ? 
                new Date(p.expected_discharge_at).toLocaleDateString() : 'Not specified';
            
            // Clinical Info
            document.getElementById('detailDiagnosis').textContent = p.primary_diagnosis || '-';
            document.getElementById('detailNotes').textContent = p.admission_notes || '-';
            
            document.getElementById('detailContent').style.display = 'block';
        } else {
            showToast(res.message || 'Failed to load patient details', 'error');
        }
    }).fail(function() {
        document.getElementById('detailLoading').style.display = 'none';
        showToast('Network error', 'error');
    });
}

function editPatientFromModal() {
    if (currentPatientId) {
        $('#patientDetailModal').modal('hide');
        setTimeout(() => editPatient(currentPatientId), 300);
    }
}

function dischargePatientFromModal() {
    if (currentPatientId) {
        $('#patientDetailModal').modal('hide');
        setTimeout(() => dischargePatient(currentPatientId), 300);
    }
}

// ======================================================================
// EDIT PATIENT STATUS
// ======================================================================
function editPatient(admissionId) {
    const modal = new bootstrap.Modal(document.getElementById('editModal'));
    
    const row = document.querySelector(`tr[data-admission-id="${admissionId}"]`);
    if (row) {
        const nameCell = row.querySelector('td:nth-child(2)');
        document.getElementById('editPatientName').textContent = nameCell ? nameCell.textContent.trim().replace(/[🔴⚠️]/g, '').trim() : 'Patient';
    }
    
    document.getElementById('editAdmissionId').value = admissionId;
    document.getElementById('editCondition').value = '';
    document.getElementById('editNotes').value = '';
    modal.show();
}

function confirmEdit() {
    const admissionId = document.getElementById('editAdmissionId').value;
    const condition = document.getElementById('editCondition').value;
    const notes = document.getElementById('editNotes').value;

    if (!admissionId || !condition) {
        showToast('Please select a condition status', 'error');
        return;
    }

    showLoading(true);
    $.post(window.location.pathname + '?ajax=inpatient_action', {
        action: 'update_condition',
        admission_id: admissionId,
        condition_status: condition,
        condition_notes: notes,
        csrf_token: csrfToken
    }, function(res) {
        showLoading(false);
        if (res.success) {
            $('#editModal').modal('hide');
            showToast(res.message);
            fetchInpatients();
        } else {
            showToast(res.message || 'Update failed', 'error');
        }
    }).fail(function() {
        showLoading(false);
        showToast('Network error', 'error');
    });
}

// ======================================================================
// TRANSFER PATIENT
// ======================================================================
function transferPatient(admissionId) {
    const modal = new bootstrap.Modal(document.getElementById('transferModal'));
    
    const row = document.querySelector(`tr[data-admission-id="${admissionId}"]`);
    if (row) {
        const nameCell = row.querySelector('td:nth-child(2)');
        document.getElementById('transferPatientName').textContent = nameCell ? nameCell.textContent.trim().replace(/[🔴⚠️]/g, '').trim() : 'Patient';
    }
    
    document.getElementById('transferAdmissionId').value = admissionId;
    document.getElementById('transferWard').value = '';
    document.getElementById('transferBed').innerHTML = '<option value="">Select Ward first</option>';
    document.getElementById('transferReason').value = '';
    modal.show();
}

// Load beds when ward is selected for transfer
document.getElementById('transferWard').addEventListener('change', function() {
    const wardId = this.value;
    const bedSelect = document.getElementById('transferBed');
    bedSelect.innerHTML = '<option value="">Loading beds...</option>';
    
    if (!wardId) {
        bedSelect.innerHTML = '<option value="">Select Ward first</option>';
        return;
    }

    $.post(window.location.pathname + '?ajax=inpatient_action', {
        action: 'fetch_available_beds',
        ward_id: wardId,
        branch_id: branchId,
        csrf_token: csrfToken
    }, function(res) {
        if (res.success && res.data && res.data.beds) {
            let html = '<option value="">Select Bed</option>';
            if (res.data.beds.length === 0) {
                html = '<option value="">No available beds</option>';
            } else {
                res.data.beds.forEach(bed => {
                    const roomInfo = bed.room_number ? ` (Room ${escapeHtml(bed.room_number)})` : '';
                    html += `<option value="${bed.id}">${escapeHtml(bed.bed_number)}${roomInfo} - ${bed.bed_type || 'Standard'}</option>`;
                });
            }
            bedSelect.innerHTML = html;
        } else {
            bedSelect.innerHTML = '<option value="">No available beds</option>';
            showToast(res.message || 'No available beds in this ward', 'warning');
        }
    }).fail(function() {
        bedSelect.innerHTML = '<option value="">Error loading beds</option>';
        showToast('Network error loading beds', 'error');
    });
});

function confirmTransfer() {
    const admissionId = document.getElementById('transferAdmissionId').value;
    const wardId = document.getElementById('transferWard').value;
    const bedId = document.getElementById('transferBed').value;
    const reason = document.getElementById('transferReason').value;

    if (!admissionId || !wardId || !bedId) {
        showToast('Please select a ward and bed', 'error');
        return;
    }

    if (!confirm('Are you sure you want to transfer this patient? This will free the current bed.')) {
        return;
    }

    showLoading(true);
    $.post(window.location.pathname + '?ajax=inpatient_action', {
        action: 'transfer_patient',
        admission_id: admissionId,
        new_ward_id: wardId,
        new_bed_id: bedId,
        transfer_reason: reason,
        csrf_token: csrfToken
    }, function(res) {
        showLoading(false);
        if (res.success) {
            $('#transferModal').modal('hide');
            showToast(res.message);
            fetchInpatients();
        } else {
            showToast(res.message || 'Transfer failed', 'error');
        }
    }).fail(function() {
        showLoading(false);
        showToast('Network error', 'error');
    });
}

// ======================================================================
// DISCHARGE PATIENT
// ======================================================================
function dischargePatient(admissionId) {
    const modal = new bootstrap.Modal(document.getElementById('dischargeModal'));
    
    const row = document.querySelector(`tr[data-admission-id="${admissionId}"]`);
    if (row) {
        const nameCell = row.querySelector('td:nth-child(2)');
        document.getElementById('dischargePatientName').textContent = nameCell ? nameCell.textContent.trim().replace(/[🔴⚠️]/g, '').trim() : 'Patient';
    }
    
    document.getElementById('dischargeAdmissionId').value = admissionId;
    document.getElementById('dischargeCondition').value = 'improved';
    document.getElementById('dischargeSummary').value = '';
    document.getElementById('dischargeInstructions').value = '';
    document.getElementById('dischargeFollowUp').value = '';
    document.getElementById('dischargeMedications').value = '';
    document.getElementById('dischargeWarningSigns').value = '';
    modal.show();
}

function confirmDischarge() {
    const admissionId = document.getElementById('dischargeAdmissionId').value;
    const condition = document.getElementById('dischargeCondition').value;
    const summary = document.getElementById('dischargeSummary').value;
    const instructions = document.getElementById('dischargeInstructions').value;
    const followUp = document.getElementById('dischargeFollowUp').value;
    const medications = document.getElementById('dischargeMedications').value;
    const warningSigns = document.getElementById('dischargeWarningSigns').value;

    if (!admissionId) {
        showToast('Invalid patient', 'error');
        return;
    }

    if (!confirm('Are you sure you want to discharge this patient? This action will free the bed.')) {
        return;
    }

    showLoading(true);
    $.post(window.location.pathname + '?ajax=inpatient_action', {
        action: 'discharge_patient',
        admission_id: admissionId,
        discharge_condition: condition,
        discharge_summary: summary,
        discharge_instructions: instructions,
        follow_up_date: followUp,
        home_medications: medications,
        warning_signs: warningSigns,
        csrf_token: csrfToken
    }, function(res) {
        showLoading(false);
        if (res.success) {
            $('#dischargeModal').modal('hide');
            showToast(res.message);
            fetchInpatients();
        } else {
            showToast(res.message || 'Discharge failed', 'error');
        }
    }).fail(function() {
        showLoading(false);
        showToast('Network error', 'error');
    });
}

// ======================================================================
// FILTERS & REFRESH
// ======================================================================
function applyFilters() {
    fetchInpatients();
}

function resetFilters() {
    document.getElementById('searchInput').value = '';
    document.getElementById('wardFilter').value = '';
    document.getElementById('conditionFilter').value = '';
    fetchInpatients();
}

function refreshData() {
    fetchInpatients();
    showToast('Data refreshed');
}

// ======================================================================
// AUTO REFRESH
// ======================================================================
function startPolling() {
    if (refreshInterval) clearInterval(refreshInterval);
    refreshInterval = setInterval(() => {
        fetchInpatients();
    }, 30000);
}

// ======================================================================
// DOCUMENT READY
// ======================================================================
$(document).ready(function() {
    startPolling();
    fetchInpatients();

    document.getElementById('searchInput').addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            applyFilters();
        }
    });
});

// Sidebar & MetisMenu
if (typeof MetisMenu !== 'undefined') {
    const sideMenu = document.getElementById('side-menu');
    if (sideMenu && !sideMenu.hasAttribute('data-metismenu-initialized')) {
        new MetisMenu(sideMenu, { toggle: true, triggerElement: 'a.has-arrow', parentTrigger: 'li', subMenu: 'ul' });
        sideMenu.setAttribute('data-metismenu-initialized', 'true');
    }
}

window.addEventListener('beforeunload', function() {
    if (refreshInterval) clearInterval(refreshInterval);
});

console.log('Inpatient List loaded successfully.');
</script>
</body>
</html>