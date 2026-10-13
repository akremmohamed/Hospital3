<?php
/**
 * ======================================================================
 * ADMISSIONS MANAGEMENT - COMPLETE INPATIENT ADMISSION SYSTEM
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

if ($user_id <= 0) {
    header('Location: ../../index.php');
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

function generateAdmissionNumber($pdo, $branch_id) {
    $prefix = 'ADM-' . date('Ymd') . '-';
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM admissions WHERE admission_number LIKE :prefix AND branch_id = :branch_id");
    $stmt->execute([':prefix' => $prefix . '%', ':branch_id' => $branch_id]);
    $count = (int)$stmt->fetchColumn() + 1;
    return $prefix . str_pad($count, 4, '0', STR_PAD_LEFT);
}

// ======================================================================
// AJAX HANDLERS
// ======================================================================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'admission_action') {
    $action = $_POST['action'] ?? $_GET['action'] ?? '';
    
    // CSRF for POST state-changing actions only
    $csrf_actions = ['create_admission', 'update_admission', 'delete_admission', 'assign_bed', 'unassign_bed'];
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($action, $csrf_actions)) {
        if (!hash_equals($csrf_token, (string)($_POST['csrf_token'] ?? ''))) {
            jsonResponse(false, 'Security token mismatch');
        }
    }

    // Handle GET requests for patient search
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'search_patients') {
        $search = trim($_GET['search'] ?? '');
        if (strlen($search) < 2) {
            jsonResponse(false, 'Please enter at least 2 characters');
        }

        try {
            $sql = "SELECT 
                        id,
                        mr_number,
                        CONCAT(
                            COALESCE(first_name, ''),
                            ' ',
                            COALESCE(middle_name, ''),
                            ' ',
                            COALESCE(last_name, '')
                        ) AS full_name,
                        first_name,
                        middle_name,
                        last_name,
                        gender,
                        date_of_birth,
                        phone,
                        address,
                        national_id,
                        blood_group,
                        emergency_contact_name,
                        emergency_contact_phone,
                        emergency_contact_relationship
                    FROM patients 
                    WHERE (mr_number LIKE :search1
                        OR first_name LIKE :search2
                        OR last_name LIKE :search3
                        OR phone LIKE :search4)
                      AND is_active = 1
                      AND deleted_at IS NULL";
            
            $params = [];
            if (!$is_global_admin && $branch_id > 0) {
                $sql .= " AND branch_id = :branch_id";
                $params[':branch_id'] = $branch_id;
            }
            
            $sql .= " ORDER BY first_name, last_name LIMIT 20";
            
            $stmt = $pdo->prepare($sql);
            $searchTerm = '%' . $search . '%';
            $params[':search1'] = $searchTerm;
            $params[':search2'] = $searchTerm;
            $params[':search3'] = $searchTerm;
            $params[':search4'] = $searchTerm;
            $stmt->execute($params);
            $patients = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($patients as &$p) {
                $p['full_name'] = preg_replace('/\s+/', ' ', trim($p['full_name'] ?? 'Unknown'));
                $p['age'] = calculateAge($p['date_of_birth'] ?? null);
                $p['id'] = (int)$p['id'];
            }

            jsonResponse(true, '', ['patients' => $patients]);
        } catch (PDOException $e) {
            error_log('Patient search error: ' . $e->getMessage());
            jsonResponse(false, 'Database error: ' . $e->getMessage());
        }
        exit;
    }

    // Only allow POST for other actions
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(false, 'Invalid request method');
    }

    try {
        // ---------- FETCH INPATIENTS ----------
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
            
            // FIXED: Use separate parameter names for each LIKE condition
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
                    'days_admitted' => floor((time() - $admitted_at) / 86400)
                ];
            }
            
            jsonResponse(true, '', ['inpatients' => $inpatients]);
        }

        // ---------- SEARCH PATIENTS (POST fallback) ----------
        if ($action === 'search_patients') {
            $search = trim($_POST['search'] ?? '');
            if (strlen($search) < 2) {
                jsonResponse(false, 'Please enter at least 2 characters');
            }

            $sql = "SELECT 
                        id,
                        mr_number,
                        CONCAT(
                            COALESCE(first_name, ''),
                            ' ',
                            COALESCE(middle_name, ''),
                            ' ',
                            COALESCE(last_name, '')
                        ) AS full_name,
                        first_name,
                        middle_name,
                        last_name,
                        gender,
                        date_of_birth,
                        phone,
                        address,
                        national_id,
                        blood_group,
                        emergency_contact_name,
                        emergency_contact_phone,
                        emergency_contact_relationship
                    FROM patients 
                    WHERE (mr_number LIKE :search1
                        OR first_name LIKE :search2
                        OR last_name LIKE :search3
                        OR phone LIKE :search4)
                      AND is_active = 1
                      AND deleted_at IS NULL";
            
            $params = [];
            if (!$is_global_admin && $branch_id > 0) {
                $sql .= " AND branch_id = :branch_id";
                $params[':branch_id'] = $branch_id;
            }
            
            $sql .= " ORDER BY first_name, last_name LIMIT 20";
            
            $stmt = $pdo->prepare($sql);
            $searchTerm = '%' . $search . '%';
            $params[':search1'] = $searchTerm;
            $params[':search2'] = $searchTerm;
            $params[':search3'] = $searchTerm;
            $params[':search4'] = $searchTerm;
            $stmt->execute($params);
            $patients = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($patients as &$p) {
                $p['full_name'] = preg_replace('/\s+/', ' ', trim($p['full_name'] ?? 'Unknown'));
                $p['age'] = calculateAge($p['date_of_birth'] ?? null);
                $p['id'] = (int)$p['id'];
            }

            jsonResponse(true, '', ['patients' => $patients]);
        }

        // ---------- FETCH AVAILABLE BEDS ----------
        if ($action === 'fetch_available_beds') {
            $ward_id = (int)($_POST['ward_id'] ?? 0);
            if ($ward_id <= 0) {
                jsonResponse(false, 'Invalid ward ID');
            }

            $sql = "SELECT 
                        b.id, 
                        b.bed_number, 
                        b.bed_type, 
                        b.daily_rate,
                        r.room_number,
                        r.room_type
                    FROM beds b
                    LEFT JOIN rooms r ON b.room_id = r.id AND r.deleted_at IS NULL
                    WHERE b.ward_id = :ward_id 
                      AND b.status = 'available' 
                      AND b.deleted_at IS NULL";
            
            $params = [];
            if (!$is_global_admin && $branch_id > 0) {
                $sql .= " AND b.branch_id = :branch_id";
                $params[':branch_id'] = $branch_id;
            }
            
            $sql .= " ORDER BY b.bed_number";
            
            $stmt = $pdo->prepare($sql);
            $params[':ward_id'] = $ward_id;
            $stmt->execute($params);
            $beds = $stmt->fetchAll(PDO::FETCH_ASSOC);

            jsonResponse(true, '', ['beds' => $beds]);
        }

        // ---------- FETCH WARDS ----------
        if ($action === 'fetch_wards') {
            $sql = "SELECT id, name, ward_type, total_beds FROM wards WHERE deleted_at IS NULL AND is_active = 1";
            $params = [];
            if (!$is_global_admin && $branch_id > 0) {
                $sql .= " AND branch_id = :branch_id";
                $params[':branch_id'] = $branch_id;
            }
            $sql .= " ORDER BY name";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $wards = $stmt->fetchAll(PDO::FETCH_ASSOC);
            jsonResponse(true, '', ['wards' => $wards]);
        }

        // ---------- FETCH DOCTORS ----------
        if ($action === 'fetch_doctors') {
            $sql = "SELECT id, CONCAT(first_name, ' ', COALESCE(middle_name, ''), ' ', last_name) AS full_name 
                    FROM users 
                    WHERE user_type = 'doctor' 
                      AND is_active = 1 
                      AND deleted_at IS NULL";
            $params = [];
            if (!$is_global_admin && $branch_id > 0) {
                $sql .= " AND branch_id = :branch_id";
                $params[':branch_id'] = $branch_id;
            }
            $sql .= " ORDER BY first_name, last_name";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $doctors = $stmt->fetchAll(PDO::FETCH_ASSOC);
            jsonResponse(true, '', ['doctors' => $doctors]);
        }

        // ---------- FETCH NURSES ----------
        if ($action === 'fetch_nurses') {
            $sql = "SELECT id, CONCAT(first_name, ' ', COALESCE(middle_name, ''), ' ', last_name) AS full_name 
                    FROM users 
                    WHERE user_type = 'nurse' 
                      AND is_active = 1 
                      AND deleted_at IS NULL";
            $params = [];
            if (!$is_global_admin && $branch_id > 0) {
                $sql .= " AND branch_id = :branch_id";
                $params[':branch_id'] = $branch_id;
            }
            $sql .= " ORDER BY first_name, last_name";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $nurses = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $nurses_array = [['id' => 0, 'full_name' => 'Unassigned']];
            foreach ($nurses as $n) {
                $n['full_name'] = preg_replace('/\s+/', ' ', trim($n['full_name']));
                $nurses_array[] = $n;
            }
            
            jsonResponse(true, '', ['nurses' => $nurses_array]);
        }

        // ---------- FETCH SINGLE ADMISSION DETAIL ----------
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
                        d.id AS doctor_id,
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

        // ---------- CREATE ADMISSION ----------
        if ($action === 'create_admission') {
            $patient_id = (int)($_POST['patient_id'] ?? 0);
            $doctor_id = (int)($_POST['doctor_id'] ?? 0);
            $ward_id = (int)($_POST['ward_id'] ?? 0);
            $bed_id = (int)($_POST['bed_id'] ?? 0);
            $admission_type = $_POST['admission_type'] ?? 'elective';
            $condition_status = $_POST['condition_status'] ?? 'stable';
            $primary_diagnosis = trim($_POST['primary_diagnosis'] ?? '');
            $admitting_diagnosis = trim($_POST['admitting_diagnosis'] ?? '');
            $admission_notes = trim($_POST['admission_notes'] ?? '');
            $expected_discharge_at = !empty($_POST['expected_discharge_at']) ? $_POST['expected_discharge_at'] : null;
            $visit_id = (int)($_POST['visit_id'] ?? 0);

            if ($patient_id <= 0 || $doctor_id <= 0 || empty($primary_diagnosis)) {
                jsonResponse(false, 'Please fill all required fields');
            }

            $valid_types = ['emergency', 'elective', 'transfer', 'observation'];
            if (!in_array($admission_type, $valid_types)) {
                jsonResponse(false, 'Invalid admission type');
            }

            $valid_conditions = ['stable', 'guarded', 'critical', 'serious', 'deceased'];
            if (!in_array($condition_status, $valid_conditions)) {
                jsonResponse(false, 'Invalid condition status');
            }

            try {
                $pdo->beginTransaction();

                if ($bed_id > 0) {
                    $stmt = $pdo->prepare("SELECT status, ward_id FROM beds WHERE id = :id AND status = 'available' AND deleted_at IS NULL FOR UPDATE");
                    $stmt->execute([':id' => $bed_id]);
                    $bed = $stmt->fetch(PDO::FETCH_ASSOC);
                    if (!$bed) {
                        throw new Exception('Selected bed is not available');
                    }
                    $ward_id = $bed['ward_id'];
                }

                if ($ward_id > 0 && $bed_id <= 0) {
                    $stmt = $pdo->prepare("SELECT id FROM wards WHERE id = :id AND is_active = 1 AND deleted_at IS NULL");
                    $stmt->execute([':id' => $ward_id]);
                    if (!$stmt->fetch()) {
                        throw new Exception('Invalid ward selected');
                    }
                }

                $admission_number = generateAdmissionNumber($pdo, $branch_id);

                if ($visit_id <= 0) {
                    $visit_number = 'VIS-' . date('Ymd') . '-' . str_pad(rand(1000, 9999), 4, '0', STR_PAD_LEFT);
                    $stmt = $pdo->prepare("INSERT INTO patient_visits 
                        (branch_id, patient_id, visit_number, visit_date, visit_time, visit_type, status, created_by_user_id, created_at)
                        VALUES 
                        (:branch_id, :patient_id, :visit_number, CURDATE(), CURTIME(), 'emergency', 'checked_in', :created_by, NOW())");
                    $stmt->execute([
                        ':branch_id' => $branch_id,
                        ':patient_id' => $patient_id,
                        ':visit_number' => $visit_number,
                        ':created_by' => $user_id
                    ]);
                    $visit_id = $pdo->lastInsertId();
                }

                $stmt = $pdo->prepare("INSERT INTO admissions 
                    (branch_id, patient_id, doctor_id, visit_id, bed_id, admission_number, admission_type, 
                     admitted_at, expected_discharge_at, primary_diagnosis, admitting_diagnosis, 
                     condition_status, status, is_active, admission_notes, created_by_user_id, created_at)
                    VALUES 
                    (:branch_id, :patient_id, :doctor_id, :visit_id, :bed_id, :admission_number, :admission_type,
                     NOW(), :expected_discharge_at, :primary_diagnosis, :admitting_diagnosis,
                     :condition_status, 'admitted', 1, :admission_notes, :created_by, NOW())");
                
                $stmt->execute([
                    ':branch_id' => $branch_id,
                    ':patient_id' => $patient_id,
                    ':doctor_id' => $doctor_id,
                    ':visit_id' => $visit_id,
                    ':bed_id' => $bed_id ?: null,
                    ':admission_number' => $admission_number,
                    ':admission_type' => $admission_type,
                    ':expected_discharge_at' => $expected_discharge_at,
                    ':primary_diagnosis' => $primary_diagnosis,
                    ':admitting_diagnosis' => $admitting_diagnosis,
                    ':condition_status' => $condition_status,
                    ':admission_notes' => $admission_notes,
                    ':created_by' => $user_id
                ]);

                $admission_id = $pdo->lastInsertId();

                if ($bed_id > 0) {
                    $stmt = $pdo->prepare("UPDATE beds SET status = 'occupied', updated_at = NOW() WHERE id = :id");
                    $stmt->execute([':id' => $bed_id]);

                    $stmt = $pdo->prepare("SELECT bed_number FROM beds WHERE id = :id");
                    $stmt->execute([':id' => $bed_id]);
                    $bed_number = $stmt->fetchColumn();

                    $stmt = $pdo->prepare("INSERT INTO bed_allocations 
                        (patient_id, ward_id, visit_id, bed_number, check_in_date, status, assigned_by_user_id, created_at, updated_at)
                        VALUES 
                        (:patient_id, :ward_id, :visit_id, :bed_number, NOW(), 'occupied', :assigned_by, NOW(), NOW())");
                    $stmt->execute([
                        ':patient_id' => $patient_id,
                        ':ward_id' => $ward_id,
                        ':visit_id' => $visit_id,
                        ':bed_number' => $bed_number,
                        ':assigned_by' => $user_id
                    ]);
                }

                $pdo->commit();
                jsonResponse(true, 'Patient admitted successfully', ['admission_id' => $admission_id]);
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                jsonResponse(false, $e->getMessage());
            }
        }

        // ---------- UPDATE ADMISSION ----------
        if ($action === 'update_admission') {
            $admission_id = (int)($_POST['admission_id'] ?? 0);
            $doctor_id = (int)($_POST['doctor_id'] ?? 0);
            $condition_status = $_POST['condition_status'] ?? '';
            $primary_diagnosis = trim($_POST['primary_diagnosis'] ?? '');
            $admission_notes = trim($_POST['admission_notes'] ?? '');
            $expected_discharge_at = !empty($_POST['expected_discharge_at']) ? $_POST['expected_discharge_at'] : null;

            if ($admission_id <= 0 || $doctor_id <= 0 || empty($primary_diagnosis)) {
                jsonResponse(false, 'Please fill all required fields');
            }

            $stmt = $pdo->prepare("UPDATE admissions 
                SET doctor_id = :doctor_id,
                    condition_status = :condition_status,
                    primary_diagnosis = :primary_diagnosis,
                    admission_notes = CONCAT(COALESCE(admission_notes, ''), '\n[', NOW(), '] ', :notes),
                    expected_discharge_at = :expected_discharge_at,
                    updated_at = NOW()
                WHERE id = :id AND deleted_at IS NULL");
            
            $stmt->execute([
                ':doctor_id' => $doctor_id,
                ':condition_status' => $condition_status,
                ':primary_diagnosis' => $primary_diagnosis,
                ':notes' => $admission_notes,
                ':expected_discharge_at' => $expected_discharge_at,
                ':id' => $admission_id
            ]);

            jsonResponse(true, 'Admission updated successfully');
        }

        // ---------- DELETE ADMISSION ----------
        if ($action === 'delete_admission') {
            $admission_id = (int)($_POST['admission_id'] ?? 0);
            if ($admission_id <= 0) {
                jsonResponse(false, 'Invalid admission ID');
            }

            try {
                $pdo->beginTransaction();

                $stmt = $pdo->prepare("SELECT bed_id, patient_id FROM admissions WHERE id = :id AND status = 'admitted' AND deleted_at IS NULL FOR UPDATE");
                $stmt->execute([':id' => $admission_id]);
                $admission = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$admission) {
                    throw new Exception('Admission not found');
                }

                $stmt = $pdo->prepare("UPDATE admissions SET deleted_at = NOW(), status = 'cancelled' WHERE id = :id");
                $stmt->execute([':id' => $admission_id]);

                if ($admission['bed_id']) {
                    $stmt = $pdo->prepare("UPDATE beds SET status = 'available', updated_at = NOW() WHERE id = :id");
                    $stmt->execute([':id' => $admission['bed_id']]);

                    $stmt = $pdo->prepare("UPDATE bed_allocations 
                        SET status = 'discharged', check_out_date = NOW(), updated_at = NOW() 
                        WHERE patient_id = :patient_id AND status = 'occupied' AND deleted_at IS NULL
                        ORDER BY id DESC LIMIT 1");
                    $stmt->execute([':patient_id' => $admission['patient_id']]);
                }

                $pdo->commit();
                jsonResponse(true, 'Admission cancelled successfully');
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                jsonResponse(false, $e->getMessage());
            }
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
        error_log('Admissions AJAX error: ' . $e->getMessage());
        jsonResponse(false, 'Database error: ' . $e->getMessage());
    } catch (Exception $e) {
        error_log('Admissions AJAX error: ' . $e->getMessage());
        jsonResponse(false, $e->getMessage());
    }
    exit;
}

// ======================================================================
// INITIAL PAGE DATA
// ======================================================================
$wards = [];
$doctors = [];
$nurses = [];
$recent_admissions = [];

try {
    $params = [];
    
    $sql = "SELECT id, name, ward_type, total_beds FROM wards WHERE deleted_at IS NULL AND is_active = 1";
    if (!$is_global_admin && $branch_id > 0) {
        $sql .= " AND branch_id = :branch_id";
        $params[':branch_id'] = $branch_id;
    }
    $sql .= " ORDER BY name";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $wards = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $sql = "SELECT id, first_name, middle_name, last_name 
            FROM users 
            WHERE user_type = 'doctor' 
              AND is_active = 1 
              AND deleted_at IS NULL";
    if (!$is_global_admin && $branch_id > 0) {
        $sql .= " AND branch_id = :branch_id";
        $params[':branch_id'] = $branch_id;
    }
    $sql .= " ORDER BY first_name, last_name";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $doctors = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $sql = "SELECT id, first_name, middle_name, last_name 
            FROM users 
            WHERE user_type = 'nurse' 
              AND is_active = 1 
              AND deleted_at IS NULL";
    if (!$is_global_admin && $branch_id > 0) {
        $sql .= " AND branch_id = :branch_id";
        $params[':branch_id'] = $branch_id;
    }
    $sql .= " ORDER BY first_name, last_name";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $nurses = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $sql = "SELECT 
                a.id AS admission_id,
                a.admission_number,
                a.admitted_at,
                a.condition_status,
                a.admission_type,
                a.primary_diagnosis,
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
            LEFT JOIN wards w ON b.ward_id = w.id AND w.deleted_at IS NULL
            LEFT JOIN users d ON a.doctor_id = d.id AND d.deleted_at IS NULL
            WHERE a.deleted_at IS NULL
              AND a.status IN ('admitted', 'transferred')";
    
    $params = [];
    if (!$is_global_admin && $branch_id > 0) {
        $sql .= " AND a.branch_id = :branch_id";
        $params[':branch_id'] = $branch_id;
    }
    
    $sql .= " ORDER BY a.admitted_at DESC LIMIT 20";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $r) {
        $patient_name = preg_replace('/\s+/', ' ', trim($r['patient_name'] ?? 'Unknown Patient'));
        $doctor_name = preg_replace('/\s+/', ' ', trim($r['doctor_name'] ?? 'Unassigned'));
        $age = calculateAge($r['date_of_birth'] ?? null);
        
        $recent_admissions[] = [
            'admission_id' => (int)$r['admission_id'],
            'admission_number' => $r['admission_number'],
            'patient_id' => (int)$r['patient_id'],
            'patient_name' => $patient_name,
            'mr_number' => $r['mr_number'] ?? '',
            'age' => $age,
            'gender' => $r['gender'] ?? 'N/A',
            'phone' => $r['phone'] ?? '',
            'bed_code' => $r['bed_code'] ?: 'Not Assigned',
            'ward_name' => $r['ward_name'] ?? 'Unassigned',
            'doctor_name' => $doctor_name,
            'condition_status' => $r['condition_status'] ?? 'stable',
            'primary_diagnosis' => $r['primary_diagnosis'] ?? '',
            'admission_type' => $r['admission_type'] ?? 'elective',
            'admitted_date' => date('d M Y H:i', strtotime($r['admitted_at']))
        ];
    }
} catch (PDOException $e) {
    error_log('Admissions initial data error: ' . $e->getMessage());
}

$total_admissions = count($recent_admissions);
$current_date = date('l, F j, Y');
$current_time = date('g:i A');

$today_count = 0;
foreach ($recent_admissions as $adm) {
    if (date('Y-m-d', strtotime($adm['admitted_date'])) === date('Y-m-d')) {
        $today_count++;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admissions Management | HMS ERP</title>
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
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }
        .stat-card {
            background: white;
            border-radius: 16px;
            padding: 14px 18px;
            border: 1px solid var(--gray-light);
            transition: all 0.3s;
        }
        .stat-card:hover { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(0,0,0,0.06); }
        .stat-number { font-size: 26px; font-weight: 800; line-height: 1.2; }
        .stat-label { font-size: 11px; color: var(--gray); font-weight: 500; text-transform: uppercase; letter-spacing: 0.5px; }
        .stat-card.total .stat-number { color: var(--primary); }
        .stat-card.today .stat-number { color: var(--success); }
        .stat-card.emergency .stat-number { color: var(--danger); }
        .stat-card.elective .stat-number { color: var(--info); }
        
        .card-modern {
            background: white;
            border-radius: 20px;
            border: 1px solid var(--gray-light);
            overflow: hidden;
            margin-bottom: 24px;
        }
        .card-header-custom {
            padding: 14px 20px;
            background: #f8fafc;
            border-bottom: 1px solid var(--gray-light);
            font-weight: 700;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
        }
        
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
        @keyframes pulseCritical { 0%, 100% { opacity: 1; } 50% { opacity: 0.6; } }
        
        .bed-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 10px;
            font-weight: 600;
            background: #dbeafe;
            color: #1e40af;
        }
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
        
        .modal-content { border-radius: 16px; border: none; }
        .modal-header { border-bottom: 1px solid var(--gray-light); padding: 16px 24px; }
        .modal-body { padding: 24px; }
        .modal-footer { border-top: 1px solid var(--gray-light); padding: 16px 24px; }
        
        .scrollable-table { max-height: 500px; overflow-y: auto; }
        .scrollable-table::-webkit-scrollbar { width: 4px; }
        .scrollable-table::-webkit-scrollbar-track { background: #f1f1f1; border-radius: 4px; }
        .scrollable-table::-webkit-scrollbar-thumb { background: var(--primary); border-radius: 4px; }
        
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
        
        .text-truncate-2 {
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        
        .patient-search-result {
            padding: 10px 14px;
            border-bottom: 1px solid #f3f4f6;
            cursor: pointer;
            transition: background 0.2s;
        }
        .patient-search-result:hover { background: #f8fafc; }
        .patient-search-result:last-child { border-bottom: none; }
        .patient-search-result.selected { background: #e0e7ff; border-left: 3px solid var(--primary); }
        
        .form-label-required::after {
            content: ' *';
            color: var(--danger);
        }
        
        @media (max-width: 768px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .data-table { font-size: 12px; }
            .data-table th,
            .data-table td { padding: 6px 8px; }
        }
        @media (max-width: 480px) {
            .stats-grid { grid-template-columns: 1fr; }
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
                    <div class="welcome-title">Admissions Management</div>
                    <div class="welcome-subtitle">Register new admissions, assign beds, and manage inpatient care</div>
                </div>
                <div class="time-badge">
                    <i class="far fa-calendar-alt"></i> <?php echo escapeHtml($current_date); ?>
                    <i class="far fa-clock ms-2"></i> <?php echo escapeHtml($current_time); ?>
                </div>
            </div>

            <!-- Stats -->
            <div class="stats-grid">
                <div class="stat-card total">
                    <div class="stat-number" id="statTotal"><?php echo $total_admissions; ?></div>
                    <div class="stat-label">Total Inpatients</div>
                </div>
                <div class="stat-card today">
                    <div class="stat-number" id="statToday"><?php echo $today_count; ?></div>
                    <div class="stat-label">Today's Admissions</div>
                </div>
                <div class="stat-card emergency">
                    <div class="stat-number" id="statEmergency">0</div>
                    <div class="stat-label">Emergency</div>
                </div>
                <div class="stat-card elective">
                    <div class="stat-number" id="statElective">0</div>
                    <div class="stat-label">Elective</div>
                </div>
            </div>

            <!-- Action Buttons -->
            <div class="mb-3">
                <button class="btn btn-primary" onclick="openNewAdmissionModal()">
                    <i class="fas fa-plus"></i> New Admission
                </button>
                <button class="btn btn-outline-secondary" onclick="refreshData()">
                    <i class="fas fa-sync-alt"></i> Refresh
                </button>
            </div>

            <!-- Search Bar -->
            <div class="card-modern mb-3">
                <div class="card-header-custom">
                    <h5 class="mb-0"><i class="fas fa-search me-2"></i> Search Admissions</h5>
                </div>
                <div class="p-3">
                    <div class="input-group">
                        <input type="text" class="form-control" id="admissionSearch" placeholder="Search by patient name, MRN, or phone number..." onkeyup="filterAdmissions(this.value)">
                        <button class="btn btn-primary" onclick="filterAdmissions(document.getElementById('admissionSearch').value)">
                            <i class="fas fa-search"></i> Search
                        </button>
                        <button class="btn btn-outline-secondary" onclick="clearSearch()">
                            <i class="fas fa-times"></i> Clear
                        </button>
                    </div>
                    <div class="mt-2 d-flex gap-2 flex-wrap">
                        <select class="form-select form-select-sm" style="width:auto;" id="wardFilter" onchange="applyFilters()">
                            <option value="">All Wards</option>
                            <?php foreach ($wards as $ward): ?>
                                <option value="<?php echo $ward['id']; ?>"><?php echo escapeHtml($ward['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select class="form-select form-select-sm" style="width:auto;" id="conditionFilter" onchange="applyFilters()">
                            <option value="">All Conditions</option>
                            <option value="stable">Stable</option>
                            <option value="guarded">Guarded</option>
                            <option value="serious">Serious</option>
                            <option value="critical">Critical</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Recent Admissions Table -->
            <div class="card-modern">
                <div class="card-header-custom">
                    <h5 class="mb-0"><i class="fas fa-procedures me-2"></i> Active Admissions</h5>
                    <span class="text-muted small" id="recordCount"><?php echo $total_admissions; ?> patients</span>
                </div>
                <div class="scrollable-table">
                    <table class="data-table" id="admissionTable">
                        <thead>
                            <tr>
                                <th>Admission #</th>
                                <th>Patient</th>
                                <th>MRN</th>
                                <th>Age/Gender</th>
                                <th>Ward / Bed</th>
                                <th>Diagnosis</th>
                                <th>Status</th>
                                <th>Doctor</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="admissionBody">
                            <?php if (!empty($recent_admissions)): ?>
                                <?php foreach ($recent_admissions as $row): 
                                    $isCritical = $row['condition_status'] === 'critical' || $row['condition_status'] === 'serious';
                                    $trClass = $isCritical ? 'critical-row' : '';
                                ?>
                                <tr class="<?php echo $trClass; ?>" data-admission-id="<?php echo $row['admission_id']; ?>">
                                    <td><strong><?php echo escapeHtml($row['admission_number']); ?></strong></td>
                                    <td>
                                        <strong><?php echo escapeHtml($row['patient_name']); ?></strong>
                                        <?php if ($row['condition_status'] === 'critical'): ?>
                                            <span class="badge bg-danger" style="font-size:8px;">⚠️</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo escapeHtml($row['mr_number']); ?></td>
                                    <td><?php echo $row['age']; ?> yrs<br><small class="text-muted"><?php echo ucfirst($row['gender'] ?? 'N/A'); ?></small></td>
                                    <td>
                                        <?php if (!empty($row['bed_code']) && $row['bed_code'] !== 'Not Assigned'): ?>
                                            <span class="bed-badge"><?php echo escapeHtml($row['bed_code']); ?></span>
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
                                    </td>
                                    <td><?php echo escapeHtml($row['doctor_name']); ?></td>
                                    <td>
                                        <button class="btn-sm-icon" onclick="viewAdmission(<?php echo $row['admission_id']; ?>)" title="View">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <button class="btn-sm-icon success" onclick="editAdmission(<?php echo $row['admission_id']; ?>)" title="Edit">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button class="btn-sm-icon" onclick="assignBed(<?php echo $row['admission_id']; ?>)" title="Assign Bed">
                                            <i class="fas fa-bed"></i>
                                        </button>
                                        <button class="btn-sm-icon danger" onclick="deleteAdmission(<?php echo $row['admission_id']; ?>)" title="Cancel">
                                            <i class="fas fa-times"></i>
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="9" class="text-center text-muted py-4">
                                        <i class="fas fa-procedures fa-2x d-block mb-2" style="opacity:0.3;"></i>
                                        No admissions found
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

<!-- New Admission Modal -->
<div class="modal fade" id="newAdmissionModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-plus-circle me-2 text-primary"></i> New Admission</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="admissionForm">
                    <!-- Patient Search -->
                    <div class="mb-3">
                        <label class="form-label fw-bold form-label-required">Patient</label>
                        <div class="input-group">
                            <input type="text" class="form-control" id="patientSearch" placeholder="Search by name, MRN, or phone..." onkeyup="if(event.key==='Enter') searchPatients()">
                            <button class="btn btn-outline-secondary" type="button" onclick="searchPatients()">
                                <i class="fas fa-search"></i>
                            </button>
                        </div>
                        <div id="patientSearchResults" class="mt-2 border rounded" style="max-height:200px;overflow-y:auto;display:none;"></div>
                        <input type="hidden" id="selectedPatientId" value="">
                        <input type="hidden" id="selectedPatientName" value="">
                    </div>

                    <!-- Selected Patient Display -->
                    <div id="selectedPatientDisplay" class="mb-3 p-3 bg-light rounded" style="display:none;">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <strong id="displayPatientName">-</strong>
                                <span class="text-muted ms-2" id="displayPatientMRN">-</span>
                                <span class="text-muted ms-2" id="displayPatientAge">-</span>
                                <span class="text-muted ms-2" id="displayPatientGender">-</span>
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-danger" onclick="clearSelectedPatient()">
                                <i class="fas fa-times"></i> Change
                            </button>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label form-label-required">Admission Type</label>
                                <select name="admission_type" id="admissionType" class="form-select" required>
                                    <option value="elective">Elective</option>
                                    <option value="emergency">Emergency</option>
                                    <option value="transfer">Transfer</option>
                                    <option value="observation">Observation</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label form-label-required">Condition Status</label>
                                <select name="condition_status" id="conditionStatus" class="form-select" required>
                                    <option value="stable">Stable</option>
                                    <option value="guarded">Guarded</option>
                                    <option value="serious">Serious</option>
                                    <option value="critical">Critical</option>
                                    <option value="deceased">Deceased</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label form-label-required">Attending Doctor</label>
                        <select name="doctor_id" id="doctorId" class="form-select" required>
                            <option value="">Select Doctor</option>
                            <?php foreach ($doctors as $doc): 
                                $fullName = trim(($doc['first_name'] ?? '') . ' ' . ($doc['middle_name'] ?? '') . ' ' . ($doc['last_name'] ?? ''));
                                $fullName = preg_replace('/\s+/', ' ', $fullName);
                            ?>
                                <option value="<?php echo $doc['id']; ?>">Dr. <?php echo escapeHtml($fullName); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label form-label-required">Primary Diagnosis</label>
                        <input type="text" name="primary_diagnosis" id="primaryDiagnosis" class="form-control" required placeholder="e.g., Acute Appendicitis">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Admitting Diagnosis</label>
                        <input type="text" name="admitting_diagnosis" id="admittingDiagnosis" class="form-control" placeholder="Initial diagnosis at admission">
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Expected Discharge</label>
                                <input type="date" name="expected_discharge_at" id="expectedDischarge" class="form-control">
                            </div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Admission Notes</label>
                        <textarea name="admission_notes" id="admissionNotes" class="form-control" rows="2" placeholder="Additional notes..."></textarea>
                    </div>

                    <hr>
                    <h6 class="fw-bold text-primary"><i class="fas fa-bed me-2"></i> Bed Assignment</h6>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Ward</label>
                                <select name="ward_id" id="admissionWard" class="form-select">
                                    <option value="">Select Ward (Optional)</option>
                                    <?php foreach ($wards as $ward): ?>
                                        <option value="<?php echo $ward['id']; ?>"><?php echo escapeHtml($ward['name']); ?> (<?php echo $ward['total_beds'] ?? 0; ?> beds)</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Bed</label>
                                <select name="bed_id" id="admissionBed" class="form-select">
                                    <option value="">Select Ward first</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <input type="hidden" name="visit_id" id="visitId" value="0">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="submitAdmission()"><i class="fas fa-save"></i> Admit Patient</button>
            </div>
        </div>
    </div>
</div>

<!-- Edit Admission Modal -->
<div class="modal fade" id="editAdmissionModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-edit me-2 text-primary"></i> Edit Admission</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="editAdmissionForm">
                    <div class="mb-3">
                        <label class="form-label">Patient</label>
                        <p class="fw-bold" id="editPatientName">-</p>
                    </div>
                    <div class="mb-3">
                        <label class="form-label form-label-required">Attending Doctor</label>
                        <select name="doctor_id" id="editDoctorId" class="form-select" required>
                            <option value="">Select Doctor</option>
                            <?php foreach ($doctors as $doc): 
                                $fullName = trim(($doc['first_name'] ?? '') . ' ' . ($doc['middle_name'] ?? '') . ' ' . ($doc['last_name'] ?? ''));
                                $fullName = preg_replace('/\s+/', ' ', $fullName);
                            ?>
                                <option value="<?php echo $doc['id']; ?>">Dr. <?php echo escapeHtml($fullName); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label form-label-required">Condition Status</label>
                        <select name="condition_status" id="editConditionStatus" class="form-select" required>
                            <option value="stable">Stable</option>
                            <option value="guarded">Guarded</option>
                            <option value="serious">Serious</option>
                            <option value="critical">Critical</option>
                            <option value="deceased">Deceased</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label form-label-required">Primary Diagnosis</label>
                        <input type="text" name="primary_diagnosis" id="editPrimaryDiagnosis" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Expected Discharge</label>
                        <input type="date" name="expected_discharge_at" id="editExpectedDischarge" class="form-control">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Add Notes</label>
                        <textarea name="admission_notes" id="editAdmissionNotes" class="form-control" rows="3" placeholder="Add clinical notes..."></textarea>
                    </div>
                    <input type="hidden" id="editAdmissionId" value="">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="submitEditAdmission()"><i class="fas fa-save"></i> Update</button>
            </div>
        </div>
    </div>
</div>

<!-- Assign Bed Modal -->
<div class="modal fade" id="assignBedModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-bed me-2 text-primary"></i> Assign Bed</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="assignBedForm">
                    <div class="mb-3">
                        <label class="form-label">Patient</label>
                        <p class="fw-bold" id="assignBedPatientName">-</p>
                    </div>
                    <div class="mb-3">
                        <label class="form-label form-label-required">Ward</label>
                        <select name="ward_id" id="assignBedWard" class="form-select" required>
                            <option value="">Select Ward</option>
                            <?php foreach ($wards as $ward): ?>
                                <option value="<?php echo $ward['id']; ?>"><?php echo escapeHtml($ward['name']); ?> (<?php echo $ward['total_beds'] ?? 0; ?> beds)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label form-label-required">Bed</label>
                        <select name="bed_id" id="assignBedSelect" class="form-select" required>
                            <option value="">Select Ward first</option>
                        </select>
                    </div>
                    <input type="hidden" id="assignBedAdmissionId" value="">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="confirmAssignBed()"><i class="fas fa-check"></i> Assign Bed</button>
            </div>
        </div>
    </div>
</div>

<!-- View Admission Modal -->
<div class="modal fade" id="viewAdmissionModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-user-injured me-2 text-primary"></i> Admission Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="viewLoading" class="text-center py-4">
                    <div class="spinner-border text-primary" role="status"></div>
                    <p class="mt-2">Loading admission details...</p>
                </div>
                <div id="viewContent" style="display:none;">
                    <div class="row">
                        <div class="col-md-6">
                            <h6 class="fw-bold text-primary"><i class="fas fa-user me-2"></i>Patient Information</h6>
                            <div class="mb-2"><small class="text-muted">Name</small><br><strong id="viewPatientName">-</strong></div>
                            <div class="mb-2"><small class="text-muted">MRN</small><br><span id="viewMRN">-</span></div>
                            <div class="mb-2"><small class="text-muted">Age / Gender</small><br><span id="viewAgeGender">-</span></div>
                            <div class="mb-2"><small class="text-muted">Phone</small><br><span id="viewPhone">-</span></div>
                            <div class="mb-2"><small class="text-muted">Address</small><br><span id="viewAddress">-</span></div>
                        </div>
                        <div class="col-md-6">
                            <h6 class="fw-bold text-success"><i class="fas fa-hospital-user me-2"></i>Admission Information</h6>
                            <div class="mb-2"><small class="text-muted">Admission #</small><br><strong id="viewAdmissionNumber">-</strong></div>
                            <div class="mb-2"><small class="text-muted">Admission Date</small><br><span id="viewAdmissionDate">-</span></div>
                            <div class="mb-2"><small class="text-muted">Admission Type</small><br><span id="viewAdmissionType">-</span></div>
                            <div class="mb-2"><small class="text-muted">Condition Status</small><br><span id="viewCondition">-</span></div>
                            <div class="mb-2"><small class="text-muted">Ward / Bed</small><br><span id="viewWardBed">-</span></div>
                            <div class="mb-2"><small class="text-muted">Attending Doctor</small><br><span id="viewDoctor">-</span></div>
                            <div class="mb-2"><small class="text-muted">Primary Diagnosis</small><br><span id="viewDiagnosis">-</span></div>
                            <div class="mb-2"><small class="text-muted">Admission Notes</small><br><span id="viewNotes" style="font-size:13px;">-</span></div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
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
let allAdmissions = <?php echo json_encode($recent_admissions); ?>;

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
// SEARCH/FILTER ADMISSIONS (Client-side)
// ======================================================================
function filterAdmissions(query) {
    const tbody = document.getElementById('admissionBody');
    const rows = tbody.querySelectorAll('tr:not(.no-results-row)');
    const search = query.toLowerCase().trim();
    let visibleCount = 0;
    
    rows.forEach(row => {
        const text = row.textContent.toLowerCase();
        if (search === '' || text.includes(search)) {
            row.style.display = '';
            visibleCount++;
        } else {
            row.style.display = 'none';
        }
    });
    
    document.getElementById('recordCount').textContent = visibleCount + ' patients';
    
    const emptyRow = tbody.querySelector('.no-results-row');
    if (visibleCount === 0) {
        if (!emptyRow) {
            const tr = document.createElement('tr');
            tr.className = 'no-results-row';
            tr.innerHTML = `<td colspan="9" class="text-center text-muted py-4">
                <i class="fas fa-search fa-2x d-block mb-2" style="opacity:0.3;"></i>
                No admissions found matching your search
            </td>`;
            tbody.appendChild(tr);
        }
    } else if (emptyRow) {
        emptyRow.remove();
    }
}

function clearSearch() {
    document.getElementById('admissionSearch').value = '';
    document.getElementById('wardFilter').value = '';
    document.getElementById('conditionFilter').value = '';
    filterAdmissions('');
}

function applyFilters() {
    const search = document.getElementById('admissionSearch').value;
    const wardFilter = document.getElementById('wardFilter').value;
    const conditionFilter = document.getElementById('conditionFilter').value;
    
    const tbody = document.getElementById('admissionBody');
    const rows = tbody.querySelectorAll('tr:not(.no-results-row)');
    let visibleCount = 0;
    
    rows.forEach(row => {
        let show = true;
        const text = row.textContent.toLowerCase();
        const searchTerm = search.toLowerCase().trim();
        
        if (searchTerm !== '' && !text.includes(searchTerm)) {
            show = false;
        }
        
        if (show && wardFilter !== '') {
            const wardCell = row.querySelector('td:nth-child(5)');
            if (wardCell) {
                const wardText = wardCell.textContent.toLowerCase();
                const wardName = document.querySelector(`#wardFilter option[value="${wardFilter}"]`)?.textContent || '';
                if (!wardText.includes(wardName.toLowerCase())) {
                    show = false;
                }
            }
        }
        
        if (show && conditionFilter !== '') {
            const statusCell = row.querySelector('td:nth-child(7)');
            if (statusCell) {
                const statusText = statusCell.textContent.toLowerCase().trim();
                if (!statusText.includes(conditionFilter)) {
                    show = false;
                }
            }
        }
        
        row.style.display = show ? '' : 'none';
        if (show) visibleCount++;
    });
    
    document.getElementById('recordCount').textContent = visibleCount + ' patients';
    
    const emptyRow = tbody.querySelector('.no-results-row');
    if (visibleCount === 0) {
        if (!emptyRow) {
            const tr = document.createElement('tr');
            tr.className = 'no-results-row';
            tr.innerHTML = `<td colspan="9" class="text-center text-muted py-4">
                <i class="fas fa-search fa-2x d-block mb-2" style="opacity:0.3;"></i>
                No admissions found matching your filters
            </td>`;
            tbody.appendChild(tr);
        }
    } else if (emptyRow) {
        emptyRow.remove();
    }
}

// ======================================================================
// SEARCH PATIENTS (AJAX - for New Admission modal)
// ======================================================================
function searchPatients() {
    const search = document.getElementById('patientSearch').value.trim();
    const resultsContainer = document.getElementById('patientSearchResults');
    
    if (search.length < 2) {
        resultsContainer.style.display = 'none';
        showToast('Please enter at least 2 characters', 'warning');
        return;
    }

    showLoading(true);
    
    $.ajax({
        url: window.location.pathname + '?ajax=admission_action&action=search_patients&search=' + encodeURIComponent(search) + '&branch_id=' + branchId,
        method: 'GET',
        dataType: 'json',
        success: function(res) {
            showLoading(false);
            if (res.success && res.data && res.data.patients) {
                if (res.data.patients.length > 0) {
                    renderPatientResults(res.data.patients);
                } else {
                    resultsContainer.innerHTML = '<div class="p-3 text-muted text-center">No patients found. Please check the name or MRN.</div>';
                    resultsContainer.style.display = 'block';
                }
            } else {
                resultsContainer.innerHTML = `<div class="p-3 text-danger text-center">${escapeHtml(res.message || 'Search failed')}</div>`;
                resultsContainer.style.display = 'block';
            }
        },
        error: function(xhr) {
            showLoading(false);
            let errorMsg = 'Network error. Please try again.';
            try {
                const res = JSON.parse(xhr.responseText);
                if (res && res.message) errorMsg = res.message;
            } catch(e) {}
            resultsContainer.innerHTML = `<div class="p-3 text-danger text-center">${escapeHtml(errorMsg)}</div>`;
            resultsContainer.style.display = 'block';
            showToast(errorMsg, 'error');
        }
    });
}

function renderPatientResults(patients) {
    const container = document.getElementById('patientSearchResults');
    if (patients.length === 0) {
        container.innerHTML = '<div class="p-3 text-muted text-center">No patients found</div>';
        container.style.display = 'block';
        return;
    }

    let html = '';
    patients.forEach(p => {
        const genderLabel = p.gender ? p.gender.charAt(0).toUpperCase() + p.gender.slice(1) : 'N/A';
        const phoneDisplay = p.phone ? '| ' + escapeHtml(p.phone) : '';
        const addressDisplay = p.address ? '| ' + escapeHtml(p.address.substring(0, 50)) : '';
        html += `
            <div class="patient-search-result" onclick="selectPatient(${p.id}, '${escapeHtml(p.full_name)}', '${escapeHtml(p.mr_number)}', ${p.age || 'null'}, '${escapeHtml(genderLabel)}')">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <strong>${escapeHtml(p.full_name)}</strong>
                        <span class="text-muted ms-2">MRN: ${escapeHtml(p.mr_number)}</span>
                    </div>
                    <div>
                        <span class="badge bg-secondary">${p.age || 'N/A'} yrs</span>
                        <span class="badge bg-light text-dark">${genderLabel}</span>
                    </div>
                </div>
                <div class="text-muted small">
                    ${phoneDisplay} ${addressDisplay}
                </div>
            </div>
        `;
    });
    container.innerHTML = html;
    container.style.display = 'block';
}

function selectPatient(id, name, mrn, age, gender) {
    document.getElementById('selectedPatientId').value = id;
    document.getElementById('selectedPatientName').value = name;
    document.getElementById('displayPatientName').textContent = name;
    document.getElementById('displayPatientMRN').textContent = 'MRN: ' + mrn;
    document.getElementById('displayPatientAge').textContent = age ? age + ' yrs' : 'N/A';
    document.getElementById('displayPatientGender').textContent = gender;
    document.getElementById('selectedPatientDisplay').style.display = 'block';
    document.getElementById('patientSearchResults').style.display = 'none';
    document.getElementById('patientSearch').value = '';
    showToast('Patient selected: ' + name);
}

function clearSelectedPatient() {
    document.getElementById('selectedPatientId').value = '';
    document.getElementById('selectedPatientName').value = '';
    document.getElementById('selectedPatientDisplay').style.display = 'none';
    document.getElementById('patientSearch').value = '';
}

// Enter key for patient search
document.getElementById('patientSearch').addEventListener('keypress', function(e) {
    if (e.key === 'Enter') {
        e.preventDefault();
        searchPatients();
    }
});

// ======================================================================
// LOAD BEDS ON WARD SELECT
// ======================================================================
function loadAvailableBeds(wardSelectId, bedSelectId) {
    const wardId = document.getElementById(wardSelectId).value;
    const bedSelect = document.getElementById(bedSelectId);
    
    if (!wardId) {
        bedSelect.innerHTML = '<option value="">Select Ward first</option>';
        return;
    }

    bedSelect.innerHTML = '<option value="">Loading beds...</option>';

    $.post(window.location.pathname + '?ajax=admission_action', {
        action: 'fetch_available_beds',
        ward_id: wardId,
        branch_id: branchId,
        csrf_token: csrfToken
    }, function(res) {
        if (res.success && res.data && res.data.beds) {
            let html = '<option value="">Select Bed</option>';
            if (res.data.beds.length === 0) {
                html = '<option value="">No available beds in this ward</option>';
            } else {
                res.data.beds.forEach(bed => {
                    const roomInfo = bed.room_number ? ` (Room ${escapeHtml(bed.room_number)})` : '';
                    html += `<option value="${bed.id}">${escapeHtml(bed.bed_number)}${roomInfo} - ${bed.bed_type || 'Standard'} (${escapeHtml(bed.daily_rate || 0)} ETB/day)</option>`;
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
}

// Ward change listeners
document.getElementById('admissionWard').addEventListener('change', function() {
    loadAvailableBeds('admissionWard', 'admissionBed');
});

document.getElementById('assignBedWard').addEventListener('change', function() {
    loadAvailableBeds('assignBedWard', 'assignBedSelect');
});

// ======================================================================
// NEW ADMISSION
// ======================================================================
function openNewAdmissionModal() {
    document.getElementById('admissionForm').reset();
    document.getElementById('selectedPatientId').value = '';
    document.getElementById('selectedPatientName').value = '';
    document.getElementById('selectedPatientDisplay').style.display = 'none';
    document.getElementById('patientSearchResults').style.display = 'none';
    document.getElementById('admissionBed').innerHTML = '<option value="">Select Ward first</option>';
    document.getElementById('visitId').value = '0';
    $('#newAdmissionModal').modal('show');
}

function submitAdmission() {
    const patientId = document.getElementById('selectedPatientId').value;
    const doctorId = document.getElementById('doctorId').value;
    const admissionType = document.getElementById('admissionType').value;
    const conditionStatus = document.getElementById('conditionStatus').value;
    const primaryDiagnosis = document.getElementById('primaryDiagnosis').value.trim();
    const wardId = document.getElementById('admissionWard').value;
    const bedId = document.getElementById('admissionBed').value;

    if (!patientId) {
        showToast('Please select a patient', 'error');
        return;
    }
    if (!doctorId) {
        showToast('Please select an attending doctor', 'error');
        return;
    }
    if (!primaryDiagnosis) {
        showToast('Please enter a primary diagnosis', 'error');
        return;
    }

    const formData = {
        action: 'create_admission',
        patient_id: patientId,
        doctor_id: doctorId,
        admission_type: admissionType,
        condition_status: conditionStatus,
        primary_diagnosis: primaryDiagnosis,
        admitting_diagnosis: document.getElementById('admittingDiagnosis').value,
        admission_notes: document.getElementById('admissionNotes').value,
        expected_discharge_at: document.getElementById('expectedDischarge').value,
        ward_id: wardId || 0,
        bed_id: bedId || 0,
        visit_id: document.getElementById('visitId').value || 0,
        branch_id: branchId,
        csrf_token: csrfToken
    };

    showLoading(true);
    $.post(window.location.pathname + '?ajax=admission_action', formData, function(res) {
        showLoading(false);
        if (res.success) {
            $('#newAdmissionModal').modal('hide');
            showToast(res.message);
            refreshData();
        } else {
            showToast(res.message || 'Admission failed', 'error');
        }
    }).fail(function() {
        showLoading(false);
        showToast('Network error', 'error');
    });
}

// ======================================================================
// EDIT ADMISSION
// ======================================================================
function editAdmission(admissionId) {
    const modal = new bootstrap.Modal(document.getElementById('editAdmissionModal'));
    document.getElementById('editAdmissionId').value = admissionId;
    
    const row = document.querySelector(`tr[data-admission-id="${admissionId}"]`);
    if (row) {
        const nameCell = row.querySelector('td:nth-child(2)');
        document.getElementById('editPatientName').textContent = nameCell ? nameCell.textContent.trim().replace(/[⚠️]/g, '').trim() : 'Patient';
    }
    
    showLoading(true);
    $.post(window.location.pathname + '?ajax=admission_action', {
        action: 'fetch_inpatient_detail',
        admission_id: admissionId,
        branch_id: branchId,
        csrf_token: csrfToken
    }, function(res) {
        showLoading(false);
        if (res.success && res.data && res.data.patient) {
            const p = res.data.patient;
            document.getElementById('editDoctorId').value = p.doctor_id || '';
            document.getElementById('editConditionStatus').value = p.condition_status || 'stable';
            document.getElementById('editPrimaryDiagnosis').value = p.primary_diagnosis || '';
            document.getElementById('editExpectedDischarge').value = p.expected_discharge_at ? p.expected_discharge_at.split(' ')[0] : '';
            document.getElementById('editAdmissionNotes').value = '';
            modal.show();
        } else {
            showToast(res.message || 'Failed to load admission details', 'error');
        }
    }).fail(function() {
        showLoading(false);
        showToast('Network error', 'error');
    });
}

function submitEditAdmission() {
    const admissionId = document.getElementById('editAdmissionId').value;
    const doctorId = document.getElementById('editDoctorId').value;
    const conditionStatus = document.getElementById('editConditionStatus').value;
    const primaryDiagnosis = document.getElementById('editPrimaryDiagnosis').value.trim();
    const expectedDischarge = document.getElementById('editExpectedDischarge').value;
    const notes = document.getElementById('editAdmissionNotes').value.trim();

    if (!doctorId || !primaryDiagnosis) {
        showToast('Please fill all required fields', 'error');
        return;
    }

    showLoading(true);
    $.post(window.location.pathname + '?ajax=admission_action', {
        action: 'update_admission',
        admission_id: admissionId,
        doctor_id: doctorId,
        condition_status: conditionStatus,
        primary_diagnosis: primaryDiagnosis,
        expected_discharge_at: expectedDischarge || null,
        admission_notes: notes || 'Updated admission details',
        csrf_token: csrfToken
    }, function(res) {
        showLoading(false);
        if (res.success) {
            $('#editAdmissionModal').modal('hide');
            showToast(res.message);
            refreshData();
        } else {
            showToast(res.message || 'Update failed', 'error');
        }
    }).fail(function() {
        showLoading(false);
        showToast('Network error', 'error');
    });
}

// ======================================================================
// ASSIGN BED
// ======================================================================
function assignBed(admissionId) {
    const modal = new bootstrap.Modal(document.getElementById('assignBedModal'));
    
    const row = document.querySelector(`tr[data-admission-id="${admissionId}"]`);
    if (row) {
        const nameCell = row.querySelector('td:nth-child(2)');
        document.getElementById('assignBedPatientName').textContent = nameCell ? nameCell.textContent.trim().replace(/[⚠️]/g, '').trim() : 'Patient';
    }
    
    document.getElementById('assignBedAdmissionId').value = admissionId;
    document.getElementById('assignBedWard').value = '';
    document.getElementById('assignBedSelect').innerHTML = '<option value="">Select Ward first</option>';
    modal.show();
}

function confirmAssignBed() {
    const admissionId = document.getElementById('assignBedAdmissionId').value;
    const bedId = document.getElementById('assignBedSelect').value;

    if (!admissionId || !bedId) {
        showToast('Please select a bed', 'error');
        return;
    }

    if (!confirm('Assign this bed to the patient?')) {
        return;
    }

    showLoading(true);
    $.post(window.location.pathname + '?ajax=admission_action', {
        action: 'assign_bed',
        admission_id: admissionId,
        bed_id: bedId,
        csrf_token: csrfToken
    }, function(res) {
        showLoading(false);
        if (res.success) {
            $('#assignBedModal').modal('hide');
            showToast(res.message);
            refreshData();
        } else {
            showToast(res.message || 'Assignment failed', 'error');
        }
    }).fail(function() {
        showLoading(false);
        showToast('Network error', 'error');
    });
}

// ======================================================================
// VIEW ADMISSION
// ======================================================================
function viewAdmission(admissionId) {
    const modal = new bootstrap.Modal(document.getElementById('viewAdmissionModal'));
    document.getElementById('viewLoading').style.display = 'block';
    document.getElementById('viewContent').style.display = 'none';
    modal.show();

    $.post(window.location.pathname + '?ajax=admission_action', {
        action: 'fetch_inpatient_detail',
        admission_id: admissionId,
        branch_id: branchId,
        csrf_token: csrfToken
    }, function(res) {
        document.getElementById('viewLoading').style.display = 'none';
        if (res.success && res.data && res.data.patient) {
            const p = res.data.patient;
            
            document.getElementById('viewPatientName').textContent = p.patient_name || '-';
            document.getElementById('viewMRN').textContent = p.mr_number || '-';
            document.getElementById('viewAgeGender').textContent = (p.age || 'N/A') + ' yrs / ' + (p.gender ? p.gender.charAt(0).toUpperCase() + p.gender.slice(1) : 'N/A');
            document.getElementById('viewPhone').textContent = p.phone || '-';
            document.getElementById('viewAddress').textContent = p.address || '-';
            
            document.getElementById('viewAdmissionNumber').textContent = p.admission_number || '-';
            document.getElementById('viewAdmissionDate').textContent = p.admitted_at ? new Date(p.admitted_at).toLocaleString() : '-';
            document.getElementById('viewAdmissionType').textContent = p.admission_type ? p.admission_type.charAt(0).toUpperCase() + p.admission_type.slice(1) : '-';
            document.getElementById('viewCondition').innerHTML = p.condition_status ? 
                `<span class="status-badge status-${p.condition_status}">${p.condition_status.charAt(0).toUpperCase() + p.condition_status.slice(1)}</span>` : '-';
            document.getElementById('viewWardBed').textContent = (p.ward_name || 'Unassigned') + ' / ' + (p.bed_code || 'Not Assigned');
            document.getElementById('viewDoctor').textContent = p.doctor_name || '-';
            document.getElementById('viewDiagnosis').textContent = p.primary_diagnosis || '-';
            document.getElementById('viewNotes').textContent = p.admission_notes || '-';
            
            document.getElementById('viewContent').style.display = 'block';
        } else {
            showToast(res.message || 'Failed to load details', 'error');
        }
    }).fail(function() {
        document.getElementById('viewLoading').style.display = 'none';
        showToast('Network error', 'error');
    });
}

// ======================================================================
// DELETE ADMISSION
// ======================================================================
function deleteAdmission(admissionId) {
    if (!confirm('Are you sure you want to cancel this admission? This will free the bed and mark the admission as cancelled.')) {
        return;
    }

    showLoading(true);
    $.post(window.location.pathname + '?ajax=admission_action', {
        action: 'delete_admission',
        admission_id: admissionId,
        csrf_token: csrfToken
    }, function(res) {
        showLoading(false);
        if (res.success) {
            showToast(res.message);
            refreshData();
        } else {
            showToast(res.message || 'Delete failed', 'error');
        }
    }).fail(function() {
        showLoading(false);
        showToast('Network error', 'error');
    });
}

// ======================================================================
// REFRESH DATA
// ======================================================================
function refreshData() {
    showLoading(true);
    fetchAdmissions();
    setTimeout(() => showLoading(false), 1000);
}

function fetchAdmissions() {
    $.post(window.location.pathname + '?ajax=admission_action', {
        action: 'fetch_inpatients',
        branch_id: branchId,
        csrf_token: csrfToken
    }, function(res) {
        if (res.success && res.data && res.data.inpatients) {
            allAdmissions = res.data.inpatients;
            renderAdmissions(allAdmissions);
            updateStats(allAdmissions);
            document.getElementById('recordCount').textContent = allAdmissions.length + ' patients';
        }
    }).fail(function() {
        console.error('Failed to fetch admissions');
    });
}

function renderAdmissions(admissions) {
    const tbody = document.getElementById('admissionBody');
    if (!admissions || admissions.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="9" class="text-center text-muted py-4">
                    <i class="fas fa-procedures fa-2x d-block mb-2" style="opacity:0.3;"></i>
                    No admissions found
                </td>
            </tr>
        `;
        return;
    }

    let html = '';
    admissions.forEach(row => {
        const isCritical = row.condition_status === 'critical' || row.condition_status === 'serious';
        const trClass = isCritical ? 'critical-row' : '';
        const conditionLabel = row.condition_status || 'stable';
        
        html += `
            <tr class="${trClass}" data-admission-id="${row.admission_id}">
                <td><strong>${escapeHtml(row.admission_number)}</strong></td>
                <td>
                    <strong>${escapeHtml(row.patient_name)}</strong>
                    ${row.condition_status === 'critical' ? '<span class="badge bg-danger" style="font-size:8px;">⚠️</span>' : ''}
                </td>
                <td>${escapeHtml(row.mr_number)}</td>
                <td>${row.age} yrs<br><small class="text-muted">${escapeHtml(row.gender || 'N/A')}</small></td>
                <td>
                    ${row.bed_code && row.bed_code !== 'Not Assigned' ? 
                        `<span class="bed-badge">${escapeHtml(row.bed_code)}</span>
                         <br><small class="text-muted">${escapeHtml(row.ward_name)}</small>` : 
                        `<span class="bed-badge unassigned">Unassigned</span>`
                    }
                </td>
                <td class="text-truncate-2" style="max-width:150px;">${escapeHtml((row.primary_diagnosis || '').substring(0, 40))}</td>
                <td>
                    <span class="status-badge status-${conditionLabel}">
                        ${conditionLabel.charAt(0).toUpperCase() + conditionLabel.slice(1)}
                    </span>
                </td>
                <td>${escapeHtml(row.doctor_name)}</td>
                <td>
                    <button class="btn-sm-icon" onclick="viewAdmission(${row.admission_id})" title="View">
                        <i class="fas fa-eye"></i>
                    </button>
                    <button class="btn-sm-icon success" onclick="editAdmission(${row.admission_id})" title="Edit">
                        <i class="fas fa-edit"></i>
                    </button>
                    <button class="btn-sm-icon" onclick="assignBed(${row.admission_id})" title="Assign Bed">
                        <i class="fas fa-bed"></i>
                    </button>
                    <button class="btn-sm-icon danger" onclick="deleteAdmission(${row.admission_id})" title="Cancel">
                        <i class="fas fa-times"></i>
                    </button>
                </td>
            </tr>
        `;
    });
    tbody.innerHTML = html;
    
    applyFilters();
}

function updateStats(admissions) {
    let total = admissions.length;
    let today = 0;
    let emergency = 0;
    let elective = 0;
    const todayStr = new Date().toDateString();
    
    admissions.forEach(p => {
        if (p.admission_type === 'emergency') emergency++;
        if (p.admission_type === 'elective' || p.admission_type === 'observation') elective++;
        if (p.admitted_date && new Date(p.admitted_date).toDateString() === todayStr) {
            today++;
        }
    });
    
    document.getElementById('statTotal').textContent = total;
    document.getElementById('statToday').textContent = today;
    document.getElementById('statEmergency').textContent = emergency;
    document.getElementById('statElective').textContent = elective;
}

// ======================================================================
// AUTO REFRESH
// ======================================================================
function startPolling() {
    if (refreshInterval) clearInterval(refreshInterval);
    refreshInterval = setInterval(() => {
        fetchAdmissions();
    }, 30000);
}

// ======================================================================
// DOCUMENT READY
// ======================================================================
$(document).ready(function() {
    startPolling();
    fetchAdmissions();
});

window.addEventListener('beforeunload', function() {
    if (refreshInterval) clearInterval(refreshInterval);
});

console.log('Admissions Management loaded successfully.');
</script>
</body>
</html>