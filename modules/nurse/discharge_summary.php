<?php
/**
 * ======================================================================
 * DISCHARGE SUMMARY MANAGEMENT - COMPLETE PATIENT DISCHARGE SYSTEM
 * - View all discharged patients with discharge summaries
 * - Create, edit, and print discharge summaries
 * - Manage discharge conditions, medications, and follow-up
 * - Integration with admissions and patient records
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

// Check permissions
$has_access = $is_global_admin || 
              user_has_permission($pdo, 'manage_discharge_summary', $user_id) ||
              user_has_permission($pdo, 'discharge_summary_access', $user_id) ||
              user_has_permission($pdo, 'finalize_patient_discharge', $user_id) ||
              user_has_permission($pdo, 'manage_admissions', $user_id) ||
              user_has_permission($pdo, 'view_admissions', $user_id);

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

function getDischargeConditionLabel($condition) {
    $map = [
        'improved' => 'Improved',
        'stabilized' => 'Stabilized',
        'relieved' => 'Relieved',
        'ama' => 'AMA (Against Medical Advice)',
        'expired' => 'Expired',
        'transferred' => 'Transferred'
    ];
    return $map[$condition] ?? ucfirst($condition);
}

function getDischargeConditionBadge($condition) {
    $map = [
        'improved' => 'success',
        'stabilized' => 'info',
        'relieved' => 'primary',
        'ama' => 'warning',
        'expired' => 'danger',
        'transferred' => 'secondary'
    ];
    return $map[$condition] ?? 'secondary';
}

function formatDate($date) {
    if (empty($date)) return 'N/A';
    return date('d M Y', strtotime($date));
}

function formatDateTime($date) {
    if (empty($date)) return 'N/A';
    return date('d M Y H:i', strtotime($date));
}

// ======================================================================
// AJAX HANDLERS
// ======================================================================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'discharge_action') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(false, 'Invalid request method');
    }

    $action = $_POST['action'] ?? '';
    
    $csrf_actions = ['save_discharge', 'delete_discharge'];
    if (in_array($action, $csrf_actions)) {
        if (!hash_equals($csrf_token, (string)($_POST['csrf_token'] ?? ''))) {
            jsonResponse(false, 'Security token mismatch');
        }
    }

    try {
        // ---------- FETCH DISCHARGED PATIENTS ----------
        if ($action === 'fetch_discharged_patients') {
            $search_term = trim($_POST['search'] ?? '');
            $condition_filter = $_POST['discharge_condition'] ?? '';
            $date_from = $_POST['date_from'] ?? '';
            $date_to = $_POST['date_to'] ?? '';
            
            $sql = "SELECT
                        a.id AS admission_id,
                        a.admission_number,
                        a.admitted_at,
                        a.discharged_at,
                        a.discharge_condition,
                        a.discharge_status,
                        a.discharge_summary,
                        a.discharge_instructions,
                        a.follow_up_date,
                        a.home_medications,
                        a.warning_signs,
                        a.procedures,
                        a.primary_diagnosis,
                        a.condition_status,
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
                        ) AS doctor_name,
                        d.id AS doctor_id,
                        CONCAT(
                            COALESCE(adm.first_name, ''),
                            ' ',
                            COALESCE(adm.middle_name, ''),
                            ' ',
                            COALESCE(adm.last_name, '')
                        ) AS discharged_by_name
                    FROM admissions a
                    INNER JOIN patients p ON a.patient_id = p.id AND p.deleted_at IS NULL
                    LEFT JOIN beds b ON a.bed_id = b.id AND b.deleted_at IS NULL
                    LEFT JOIN wards w ON b.ward_id = w.id AND w.deleted_at IS NULL
                    LEFT JOIN users d ON a.doctor_id = d.id AND d.deleted_at IS NULL
                    LEFT JOIN users adm ON a.created_by_user_id = adm.id AND adm.deleted_at IS NULL
                    WHERE a.deleted_at IS NULL
                      AND a.status = 'discharged'
                      AND a.discharged_at IS NOT NULL";

            $params = [];
            
            if (!$is_global_admin && $branch_id > 0) {
                $sql .= " AND a.branch_id = :branch_id";
                $params[':branch_id'] = $branch_id;
            }
            
            if (!empty($condition_filter)) {
                $sql .= " AND a.discharge_condition = :condition";
                $params[':condition'] = $condition_filter;
            }
            
            if (!empty($date_from)) {
                $sql .= " AND DATE(a.discharged_at) >= :date_from";
                $params[':date_from'] = $date_from;
            }
            
            if (!empty($date_to)) {
                $sql .= " AND DATE(a.discharged_at) <= :date_to";
                $params[':date_to'] = $date_to;
            }
            
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

            $sql .= " ORDER BY a.discharged_at DESC LIMIT 100";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $discharged_patients = [];
            foreach ($rows as $r) {
                $patient_name = preg_replace('/\s+/', ' ', trim($r['patient_name'] ?? 'Unknown Patient'));
                $doctor_name = preg_replace('/\s+/', ' ', trim($r['doctor_name'] ?? 'Unassigned'));
                $discharged_by = preg_replace('/\s+/', ' ', trim($r['discharged_by_name'] ?? 'Unknown'));
                $age = calculateAge($r['date_of_birth'] ?? null);
                $discharged_at = strtotime($r['discharged_at'] ?? 'now');
                $admitted_at = strtotime($r['admitted_at'] ?? 'now');
                $length_of_stay = floor(($discharged_at - $admitted_at) / 86400);
                
                $discharged_patients[] = [
                    'admission_id' => (int)$r['admission_id'],
                    'admission_number' => $r['admission_number'],
                    'patient_id' => (int)$r['patient_id'],
                    'patient_name' => $patient_name,
                    'mr_number' => $r['mr_number'] ?? '',
                    'age' => $age,
                    'gender' => $r['gender'] ?? 'N/A',
                    'phone' => $r['phone'] ?? '',
                    'email' => $r['email'] ?? '',
                    'address' => $r['address'] ?? '',
                    'bed_code' => $r['bed_code'] ?: 'Not Assigned',
                    'ward_name' => $r['ward_name'] ?? 'Unassigned',
                    'doctor_name' => $doctor_name,
                    'doctor_id' => $r['doctor_id'] ? (int)$r['doctor_id'] : null,
                    'discharged_by_name' => $discharged_by,
                    'discharge_condition' => $r['discharge_condition'] ?? 'improved',
                    'discharge_status' => $r['discharge_status'] ?? 'discharged',
                    'discharge_summary' => $r['discharge_summary'] ?? '',
                    'discharge_instructions' => $r['discharge_instructions'] ?? '',
                    'follow_up_date' => $r['follow_up_date'] ?? '',
                    'home_medications' => $r['home_medications'] ?? '',
                    'warning_signs' => $r['warning_signs'] ?? '',
                    'procedures' => $r['procedures'] ?? '',
                    'primary_diagnosis' => $r['primary_diagnosis'] ?? '',
                    'condition_status' => $r['condition_status'] ?? 'stable',
                    'discharged_date' => date('d M Y H:i', $discharged_at),
                    'admitted_date' => date('d M Y H:i', $admitted_at),
                    'length_of_stay' => $length_of_stay,
                    'has_summary' => !empty($r['discharge_summary'])
                ];
            }
            
            jsonResponse(true, '', ['patients' => $discharged_patients]);
        }

        // ---------- FETCH ADMITTED PATIENTS (for discharge) ----------
        if ($action === 'fetch_admitted_patients') {
            $search = trim($_POST['search'] ?? '');
            
            $sql = "SELECT
                        a.id AS admission_id,
                        a.admission_number,
                        a.admitted_at,
                        a.condition_status,
                        a.primary_diagnosis,
                        a.admission_type,
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
                        b.bed_number AS bed_code,
                        w.name AS ward_name,
                        CONCAT(
                            COALESCE(d.first_name, ''),
                            ' ',
                            COALESCE(d.middle_name, ''),
                            ' ',
                            COALESCE(d.last_name, '')
                        ) AS doctor_name
                    FROM admissions a
                    INNER JOIN patients p ON a.patient_id = p.id AND p.deleted_at IS NULL
                    LEFT JOIN beds b ON a.bed_id = b.id AND b.deleted_at IS NULL
                    LEFT JOIN wards w ON b.ward_id = w.id AND w.deleted_at IS NULL
                    LEFT JOIN users d ON a.doctor_id = d.id AND d.deleted_at IS NULL
                    WHERE a.deleted_at IS NULL
                      AND a.status = 'admitted'";
            
            $params = [];
            
            if (!$is_global_admin && $branch_id > 0) {
                $sql .= " AND a.branch_id = :branch_id";
                $params[':branch_id'] = $branch_id;
            }
            
            if (!empty($search)) {
                $sql .= " AND (p.mr_number LIKE :search_mrn
                           OR p.first_name LIKE :search_first
                           OR p.last_name LIKE :search_last)";
                $searchLike = '%' . $search . '%';
                $params[':search_mrn'] = $searchLike;
                $params[':search_first'] = $searchLike;
                $params[':search_last'] = $searchLike;
            }
            
            $sql .= " ORDER BY a.admitted_at ASC LIMIT 50";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $patients = [];
            foreach ($rows as $r) {
                $patient_name = preg_replace('/\s+/', ' ', trim($r['patient_name'] ?? 'Unknown Patient'));
                $doctor_name = preg_replace('/\s+/', ' ', trim($r['doctor_name'] ?? 'Unassigned'));
                $age = calculateAge($r['date_of_birth'] ?? null);
                
                $patients[] = [
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
                    'admitted_date' => date('d M Y', strtotime($r['admitted_at'])),
                    'days_admitted' => floor((time() - strtotime($r['admitted_at'])) / 86400)
                ];
            }
            
            jsonResponse(true, '', ['patients' => $patients]);
        }

        // ---------- FETCH SINGLE DISCHARGE DETAIL ----------
        if ($action === 'fetch_discharge_detail') {
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
                        b.bed_number AS bed_code,
                        w.name AS ward_name,
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
                        ) AS discharged_by_name
                    FROM admissions a
                    INNER JOIN patients p ON a.patient_id = p.id AND p.deleted_at IS NULL
                    LEFT JOIN beds b ON a.bed_id = b.id AND b.deleted_at IS NULL
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
                jsonResponse(false, 'Patient record not found');
            }

            $patient['patient_name'] = preg_replace('/\s+/', ' ', trim($patient['patient_name'] ?? 'Unknown Patient'));
            $patient['doctor_name'] = preg_replace('/\s+/', ' ', trim($patient['doctor_name'] ?? 'Unassigned'));
            $patient['discharged_by_name'] = preg_replace('/\s+/', ' ', trim($patient['discharged_by_name'] ?? 'Unknown'));
            $patient['age'] = calculateAge($patient['date_of_birth'] ?? null);

            jsonResponse(true, '', ['patient' => $patient]);
        }

        // ---------- CREATE/UPDATE DISCHARGE SUMMARY ----------
        if ($action === 'save_discharge') {
            $admission_id = (int)($_POST['admission_id'] ?? 0);
            $discharge_condition = $_POST['discharge_condition'] ?? 'improved';
            $discharge_summary = trim($_POST['discharge_summary'] ?? '');
            $discharge_instructions = trim($_POST['discharge_instructions'] ?? '');
            $follow_up_date = !empty($_POST['follow_up_date']) ? $_POST['follow_up_date'] : null;
            $home_medications = trim($_POST['home_medications'] ?? '');
            $warning_signs = trim($_POST['warning_signs'] ?? '');
            $procedures = trim($_POST['procedures'] ?? '');

            if ($admission_id <= 0) {
                jsonResponse(false, 'Invalid admission ID');
            }

            $valid_conditions = ['improved', 'stabilized', 'relieved', 'ama', 'expired', 'transferred'];
            if (!in_array($discharge_condition, $valid_conditions)) {
                jsonResponse(false, 'Invalid discharge condition');
            }

            try {
                $pdo->beginTransaction();

                // Check if admission exists and is admitted
                $stmt = $pdo->prepare("SELECT bed_id, patient_id, visit_id, status FROM admissions WHERE id = :id AND deleted_at IS NULL FOR UPDATE");
                $stmt->execute([':id' => $admission_id]);
                $admission = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$admission) {
                    throw new Exception('Admission not found');
                }

                if ($admission['status'] === 'discharged') {
                    // Update existing discharge
                    $stmt = $pdo->prepare("UPDATE admissions 
                        SET discharge_condition = :condition,
                            discharge_summary = :summary,
                            discharge_instructions = :instructions,
                            follow_up_date = :follow_up,
                            home_medications = :medications,
                            warning_signs = :warning_signs,
                            procedures = :procedures,
                            updated_at = NOW()
                        WHERE id = :id");
                } else {
                    // Create new discharge
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
                            procedures = :procedures,
                            updated_at = NOW()
                        WHERE id = :id");

                    // Free the bed if assigned
                    if ($admission['bed_id']) {
                        $stmtBed = $pdo->prepare("UPDATE beds SET status = 'available', updated_at = NOW() WHERE id = :id");
                        $stmtBed->execute([':id' => $admission['bed_id']]);

                        // Update bed allocation
                        $stmtAlloc = $pdo->prepare("UPDATE bed_allocations 
                            SET status = 'discharged', check_out_date = NOW(), updated_at = NOW() 
                            WHERE patient_id = :patient_id AND status = 'occupied' AND deleted_at IS NULL
                            ORDER BY id DESC LIMIT 1");
                        $stmtAlloc->execute([':patient_id' => $admission['patient_id']]);
                    }

                    // Update patient visit
                    if ($admission['visit_id']) {
                        $stmtVisit = $pdo->prepare("UPDATE patient_visits SET status = 'completed' WHERE id = :id");
                        $stmtVisit->execute([':id' => $admission['visit_id']]);
                    }
                }

                $stmt->execute([
                    ':condition' => $discharge_condition,
                    ':summary' => $discharge_summary,
                    ':instructions' => $discharge_instructions,
                    ':follow_up' => $follow_up_date,
                    ':medications' => $home_medications,
                    ':warning_signs' => $warning_signs,
                    ':procedures' => $procedures,
                    ':id' => $admission_id
                ]);

                $pdo->commit();
                jsonResponse(true, 'Discharge summary saved successfully');
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                jsonResponse(false, $e->getMessage());
            }
        }

        // ---------- DELETE DISCHARGE (Revert to admitted) ----------
        if ($action === 'delete_discharge') {
            $admission_id = (int)($_POST['admission_id'] ?? 0);
            
            if ($admission_id <= 0) {
                jsonResponse(false, 'Invalid admission ID');
            }

            try {
                $pdo->beginTransaction();

                // Get admission details - FIXED: removed ward_id from SELECT
                $stmt = $pdo->prepare("SELECT bed_id, patient_id, visit_id FROM admissions WHERE id = :id AND status = 'discharged' AND deleted_at IS NULL FOR UPDATE");
                $stmt->execute([':id' => $admission_id]);
                $admission = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$admission) {
                    throw new Exception('Discharged admission not found');
                }

                // Revert to admitted status
                $stmt = $pdo->prepare("UPDATE admissions 
                    SET status = 'admitted',
                        discharged_at = NULL,
                        discharge_condition = NULL,
                        discharge_status = NULL,
                        discharge_summary = NULL,
                        discharge_instructions = NULL,
                        follow_up_date = NULL,
                        home_medications = NULL,
                        warning_signs = NULL,
                        procedures = NULL,
                        updated_at = NOW()
                    WHERE id = :id");
                $stmt->execute([':id' => $admission_id]);

                // If bed exists, mark as occupied again
                if ($admission['bed_id']) {
                    // Get bed details including ward_id from beds table
                    $stmtBed = $pdo->prepare("SELECT ward_id, bed_number FROM beds WHERE id = :id AND deleted_at IS NULL");
                    $stmtBed->execute([':id' => $admission['bed_id']]);
                    $bed = $stmtBed->fetch(PDO::FETCH_ASSOC);
                    
                    if ($bed) {
                        // Update bed status to occupied
                        $stmtUpdateBed = $pdo->prepare("UPDATE beds SET status = 'occupied', updated_at = NOW() WHERE id = :id");
                        $stmtUpdateBed->execute([':id' => $admission['bed_id']]);

                        // Create new bed allocation
                        $stmtAlloc = $pdo->prepare("INSERT INTO bed_allocations 
                            (patient_id, ward_id, visit_id, bed_number, check_in_date, status, assigned_by_user_id, created_at, updated_at)
                            VALUES 
                            (:patient_id, :ward_id, :visit_id, :bed_number, NOW(), 'occupied', :assigned_by, NOW(), NOW())");
                        $stmtAlloc->execute([
                            ':patient_id' => $admission['patient_id'],
                            ':ward_id' => $bed['ward_id'],
                            ':visit_id' => $admission['visit_id'],
                            ':bed_number' => $bed['bed_number'],
                            ':assigned_by' => $user_id
                        ]);
                    }
                }

                // Revert patient visit status
                if ($admission['visit_id']) {
                    $stmtVisit = $pdo->prepare("UPDATE patient_visits SET status = 'in_consultation' WHERE id = :id");
                    $stmtVisit->execute([':id' => $admission['visit_id']]);
                }

                $pdo->commit();
                jsonResponse(true, 'Discharge reverted successfully');
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                jsonResponse(false, $e->getMessage());
            }
        }

        jsonResponse(false, 'Unsupported action');
    } catch (PDOException $e) {
        error_log('Discharge Summary AJAX error: ' . $e->getMessage());
        jsonResponse(false, 'Database error: ' . $e->getMessage());
    } catch (Exception $e) {
        error_log('Discharge Summary AJAX error: ' . $e->getMessage());
        jsonResponse(false, $e->getMessage());
    }
    exit;
}

// ======================================================================
// INITIAL PAGE DATA
// ======================================================================
$discharged_patients = [];
$condition_counts = [
    'improved' => 0,
    'stabilized' => 0,
    'relieved' => 0,
    'ama' => 0,
    'expired' => 0,
    'transferred' => 0
];

try {
    $params = [];
    
    $sql = "SELECT
                a.id AS admission_id,
                a.admission_number,
                a.discharged_at,
                a.discharge_condition,
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
                CONCAT(
                    COALESCE(d.first_name, ''),
                    ' ',
                    COALESCE(d.middle_name, ''),
                    ' ',
                    COALESCE(d.last_name, '')
                ) AS doctor_name
            FROM admissions a
            INNER JOIN patients p ON a.patient_id = p.id AND p.deleted_at IS NULL
            LEFT JOIN users d ON a.doctor_id = d.id AND d.deleted_at IS NULL
            WHERE a.deleted_at IS NULL
              AND a.status = 'discharged'
              AND a.discharged_at IS NOT NULL";
    
    if (!$is_global_admin && $branch_id > 0) {
        $sql .= " AND a.branch_id = :branch_id";
        $params[':branch_id'] = $branch_id;
    }
    
    $sql .= " ORDER BY a.discharged_at DESC LIMIT 50";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $r) {
        $patient_name = preg_replace('/\s+/', ' ', trim($r['patient_name'] ?? 'Unknown Patient'));
        $doctor_name = preg_replace('/\s+/', ' ', trim($r['doctor_name'] ?? 'Unassigned'));
        $age = calculateAge($r['date_of_birth'] ?? null);
        
        $condition = $r['discharge_condition'] ?? 'improved';
        if (isset($condition_counts[$condition])) {
            $condition_counts[$condition]++;
        }
        
        $discharged_patients[] = [
            'admission_id' => (int)$r['admission_id'],
            'admission_number' => $r['admission_number'],
            'patient_id' => (int)$r['patient_id'],
            'patient_name' => $patient_name,
            'mr_number' => $r['mr_number'] ?? '',
            'age' => $age,
            'gender' => $r['gender'] ?? 'N/A',
            'phone' => $r['phone'] ?? '',
            'doctor_name' => $doctor_name,
            'discharge_condition' => $condition,
            'discharged_date' => date('d M Y H:i', strtotime($r['discharged_at'])),
            'has_summary' => !empty($r['discharge_summary'])
        ];
    }
} catch (PDOException $e) {
    error_log('Discharge Summary initial data error: ' . $e->getMessage());
}

$current_date = date('l, F j, Y');
$current_time = date('g:i A');
$total_discharged = count($discharged_patients);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include('assets/inc/head.php'); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Discharge Summary | HMS ERP</title>
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
            cursor: default;
        }
        .stat-card:hover { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(0,0,0,0.06); }
        .stat-number { font-size: 26px; font-weight: 800; line-height: 1.2; }
        .stat-label { font-size: 11px; color: var(--gray); font-weight: 500; text-transform: uppercase; letter-spacing: 0.5px; }
        .stat-card.total .stat-number { color: var(--primary); }
        .stat-card.improved .stat-number { color: #059669; }
        .stat-card.stabilized .stat-number { color: #2563eb; }
        .stat-card.ama .stat-number { color: #d97706; }
        .stat-card.expired .stat-number { color: #dc2626; }
        
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
        .data-table tr.no-summary { background: #fef3c7; }
        .data-table tr.no-summary:hover { background: #fde68a; }
        
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
        .status-improved { background: #d1fae5; color: #059669; }
        .status-stabilized { background: #dbeafe; color: #2563eb; }
        .status-relieved { background: #e0e7ff; color: #3730a3; }
        .status-ama { background: #fef3c7; color: #d97706; }
        .status-expired { background: #fee2e2; color: #dc2626; }
        .status-transferred { background: #e5e7eb; color: #4b5563; }
        
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
        
        .text-truncate-2 {
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        
        .print-header {
            border-bottom: 2px solid var(--gray-light);
            padding-bottom: 16px;
            margin-bottom: 16px;
        }
        .print-header .hospital-name {
            font-size: 24px;
            font-weight: 800;
            color: var(--primary-dark);
        }
        .print-header .hospital-address {
            font-size: 12px;
            color: var(--gray);
        }
        .print-section {
            margin-bottom: 16px;
        }
        .print-section .section-title {
            font-weight: 700;
            font-size: 14px;
            border-bottom: 1px solid var(--gray-light);
            padding-bottom: 4px;
            margin-bottom: 8px;
        }
        .print-section .section-content {
            font-size: 13px;
            line-height: 1.6;
        }
        .print-footer {
            border-top: 2px solid var(--gray-light);
            padding-top: 16px;
            margin-top: 16px;
            font-size: 12px;
            color: var(--gray);
            text-align: center;
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
                    <div class="welcome-title">Discharge Summary</div>
                    <div class="welcome-subtitle">Manage patient discharge summaries and follow-up care</div>
                </div>
                <div class="time-badge">
                    <i class="far fa-calendar-alt"></i> <?php echo escapeHtml($current_date); ?>
                    <i class="far fa-clock ms-2"></i> <?php echo escapeHtml($current_time); ?>
                </div>
            </div>

            <!-- Stats -->
            <div class="stats-grid">
                <div class="stat-card total">
                    <div class="stat-number" id="statTotal"><?php echo $total_discharged; ?></div>
                    <div class="stat-label">Total Discharged</div>
                </div>
                <div class="stat-card improved">
                    <div class="stat-number" id="statImproved"><?php echo $condition_counts['improved']; ?></div>
                    <div class="stat-label">Improved</div>
                </div>
                <div class="stat-card stabilized">
                    <div class="stat-number" id="statStabilized"><?php echo $condition_counts['stabilized']; ?></div>
                    <div class="stat-label">Stabilized</div>
                </div>
                <div class="stat-card ama">
                    <div class="stat-number" id="statAMA"><?php echo $condition_counts['ama']; ?></div>
                    <div class="stat-label">AMA</div>
                </div>
                <div class="stat-card expired">
                    <div class="stat-number" id="statExpired"><?php echo $condition_counts['expired']; ?></div>
                    <div class="stat-label">Expired</div>
                </div>
            </div>

            <!-- Filter Bar -->
            <div class="filter-bar">
                <div style="flex:1; min-width:180px;">
                    <input type="text" class="form-control" id="searchInput" placeholder="🔍 Search by name, MRN, or phone...">
                </div>
                <div style="min-width:140px;">
                    <select class="form-select" id="conditionFilter">
                        <option value="">All Conditions</option>
                        <option value="improved">Improved</option>
                        <option value="stabilized">Stabilized</option>
                        <option value="relieved">Relieved</option>
                        <option value="ama">AMA</option>
                        <option value="expired">Expired</option>
                        <option value="transferred">Transferred</option>
                    </select>
                </div>
                <div style="min-width:130px;">
                    <input type="date" class="form-control" id="dateFrom" placeholder="Date From">
                </div>
                <div style="min-width:130px;">
                    <input type="date" class="form-control" id="dateTo" placeholder="Date To">
                </div>
                <button class="btn btn-primary btn-sm" onclick="applyFilters()"><i class="fas fa-filter"></i> Filter</button>
                <button class="btn btn-outline-secondary btn-sm" onclick="resetFilters()"><i class="fas fa-times"></i> Reset</button>
                <button class="btn btn-outline-primary btn-sm ms-auto" onclick="refreshData()"><i class="fas fa-sync-alt"></i> Refresh</button>
                <button class="btn btn-success btn-sm" onclick="openNewDischargeModal()"><i class="fas fa-plus"></i> New Discharge</button>
            </div>

            <!-- Table -->
            <div class="table-container">
                <div class="table-header">
                    <h3><i class="fas fa-sign-out-alt me-2"></i> Discharged Patients</h3>
                    <span class="text-muted small" id="recordCount"><?php echo $total_discharged; ?> patients</span>
                </div>
                <div class="scrollable-table">
                    <table class="data-table" id="dischargeTable">
                        <thead>
                            <tr>
                                <th>Admission #</th>
                                <th>Patient</th>
                                <th>MRN</th>
                                <th>Age/Gender</th>
                                <th>Condition</th>
                                <th>Discharge Date</th>
                                <th>Doctor</th>
                                <th>Summary</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="dischargeBody">
                            <?php if (!empty($discharged_patients)): ?>
                                <?php foreach ($discharged_patients as $row): 
                                    $trClass = $row['has_summary'] ? '' : 'no-summary';
                                ?>
                                <tr class="<?php echo $trClass; ?>" data-admission-id="<?php echo $row['admission_id']; ?>">
                                    <td><strong><?php echo escapeHtml($row['admission_number']); ?></strong></td>
                                    <td>
                                        <strong><?php echo escapeHtml($row['patient_name']); ?></strong>
                                        <?php if (!$row['has_summary']): ?>
                                            <span class="badge bg-warning text-dark" style="font-size:8px;">NO SUMMARY</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo escapeHtml($row['mr_number']); ?></td>
                                    <td><?php echo $row['age']; ?> yrs<br><small class="text-muted"><?php echo ucfirst($row['gender'] ?? 'N/A'); ?></small></td>
                                    <td>
                                        <span class="status-badge status-<?php echo $row['discharge_condition'] ?? 'improved'; ?>">
                                            <?php echo getDischargeConditionLabel($row['discharge_condition'] ?? 'improved'); ?>
                                        </span>
                                    </td>
                                    <td><?php echo escapeHtml($row['discharged_date']); ?></td>
                                    <td><?php echo escapeHtml($row['doctor_name']); ?></td>
                                    <td>
                                        <?php if ($row['has_summary']): ?>
                                            <span class="badge bg-success">Complete</span>
                                        <?php else: ?>
                                            <span class="badge bg-warning text-dark">Pending</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <button class="btn-sm-icon" onclick="viewDischarge(<?php echo $row['admission_id']; ?>)" title="View Summary">
                                            <i class="fas fa-file-medical-alt"></i>
                                        </button>
                                        <button class="btn-sm-icon success" onclick="editDischarge(<?php echo $row['admission_id']; ?>)" title="Edit Summary">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button class="btn-sm-icon" onclick="printDischarge(<?php echo $row['admission_id']; ?>)" title="Print Summary">
                                            <i class="fas fa-print"></i>
                                        </button>
                                        <button class="btn-sm-icon danger" onclick="deleteDischarge(<?php echo $row['admission_id']; ?>)" title="Revert Discharge">
                                            <i class="fas fa-undo"></i>
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="9" class="text-center text-muted py-4">
                                        <i class="fas fa-sign-out-alt fa-2x d-block mb-2" style="opacity:0.3;"></i>
                                        No discharged patients found
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

<!-- New Discharge Modal -->
<div class="modal fade" id="newDischargeModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-sign-out-alt me-2 text-success"></i> <span id="dischargeModalTitle">New Discharge</span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <!-- Patient Search -->
                <div class="mb-3" id="patientSearchSection">
                    <label class="form-label fw-bold">Search Admitted Patient</label>
                    <div class="input-group">
                        <input type="text" class="form-control" id="admittedSearch" placeholder="Search by name or MRN..." onkeyup="if(event.key==='Enter') searchAdmittedPatients()">
                        <button class="btn btn-outline-secondary" type="button" onclick="searchAdmittedPatients()">
                            <i class="fas fa-search"></i>
                        </button>
                    </div>
                    <div id="admittedSearchResults" class="mt-2 border rounded" style="max-height:200px;overflow-y:auto;display:none;"></div>
                    <input type="hidden" id="selectedAdmissionId" value="">
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
                            <span class="text-muted ms-2" id="displayAdmissionDate">-</span>
                        </div>
                        <button type="button" class="btn btn-sm btn-outline-danger" onclick="clearSelectedPatient()">
                            <i class="fas fa-times"></i> Change
                        </button>
                    </div>
                </div>

                <hr>

                <form id="dischargeForm">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label fw-bold form-label-required">Discharge Condition</label>
                                <select name="discharge_condition" id="dischargeCondition" class="form-select" required>
                                    <option value="improved">Improved</option>
                                    <option value="stabilized">Stabilized</option>
                                    <option value="relieved">Relieved</option>
                                    <option value="ama">AMA (Against Medical Advice)</option>
                                    <option value="transferred">Transferred</option>
                                    <option value="expired">Expired</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label fw-bold">Follow-up Date</label>
                                <input type="date" name="follow_up_date" id="followUpDate" class="form-control">
                            </div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Procedures Performed</label>
                        <textarea name="procedures" id="dischargeProcedures" class="form-control" rows="2" placeholder="List procedures performed during admission..."></textarea>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold form-label-required">Discharge Summary</label>
                        <textarea name="discharge_summary" id="dischargeSummary" class="form-control" rows="4" required placeholder="Clinical summary at discharge..."></textarea>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Discharge Instructions</label>
                        <textarea name="discharge_instructions" id="dischargeInstructions" class="form-control" rows="3" placeholder="Instructions for patient/caregiver..."></textarea>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Home Medications</label>
                        <textarea name="home_medications" id="homeMedications" class="form-control" rows="2" placeholder="Medications to continue at home..."></textarea>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Warning Signs</label>
                        <textarea name="warning_signs" id="warningSigns" class="form-control" rows="2" placeholder="Warning signs to watch for..."></textarea>
                    </div>

                    <input type="hidden" id="dischargeAdmissionId" value="">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-success" onclick="saveDischarge()"><i class="fas fa-save"></i> Save Discharge</button>
            </div>
        </div>
    </div>
</div>

<!-- View Discharge Modal -->
<div class="modal fade" id="viewDischargeModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-file-medical-alt me-2 text-primary"></i> Discharge Summary</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="viewLoading" class="text-center py-4">
                    <div class="spinner-border text-primary" role="status"></div>
                    <p class="mt-2">Loading discharge details...</p>
                </div>
                <div id="viewContent" style="display:none;">
                    <!-- Print Area -->
                    <div id="printArea">
                        <div class="print-header">
                            <div class="hospital-name">Central General Hospital</div>
                            <div class="hospital-address">Bole Road, Addis Ababa, Ethiopia | Phone: +251-11-555-1000</div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="print-section">
                                    <div class="section-title">Patient Information</div>
                                    <div class="section-content">
                                        <strong>Name:</strong> <span id="viewName">-</span><br>
                                        <strong>MRN:</strong> <span id="viewMRN">-</span><br>
                                        <strong>Age / Gender:</strong> <span id="viewAgeGender">-</span><br>
                                        <strong>Phone:</strong> <span id="viewPhone">-</span><br>
                                        <strong>Address:</strong> <span id="viewAddress">-</span>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="print-section">
                                    <div class="section-title">Admission Information</div>
                                    <div class="section-content">
                                        <strong>Admission #:</strong> <span id="viewAdmissionNumber">-</span><br>
                                        <strong>Admitted:</strong> <span id="viewAdmittedDate">-</span><br>
                                        <strong>Discharged:</strong> <span id="viewDischargedDate">-</span><br>
                                        <strong>Length of Stay:</strong> <span id="viewLengthOfStay">-</span><br>
                                        <strong>Ward / Bed:</strong> <span id="viewWardBed">-</span><br>
                                        <strong>Doctor:</strong> <span id="viewDoctor">-</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="print-section">
                            <div class="section-title">Primary Diagnosis</div>
                            <div class="section-content" id="viewDiagnosis">-</div>
                        </div>

                        <div class="print-section">
                            <div class="section-title">Procedures Performed</div>
                            <div class="section-content" id="viewProcedures">-</div>
                        </div>

                        <div class="print-section">
                            <div class="section-title">Discharge Summary</div>
                            <div class="section-content" id="viewSummary">-</div>
                        </div>

                        <div class="print-section">
                            <div class="section-title">Discharge Condition</div>
                            <div class="section-content"><span class="status-badge" id="viewCondition">-</span></div>
                        </div>

                        <div class="print-section">
                            <div class="section-title">Discharge Instructions</div>
                            <div class="section-content" id="viewInstructions">-</div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="print-section">
                                    <div class="section-title">Home Medications</div>
                                    <div class="section-content" id="viewMedications">-</div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="print-section">
                                    <div class="section-title">Warning Signs</div>
                                    <div class="section-content" id="viewWarningSigns">-</div>
                                </div>
                            </div>
                        </div>

                        <div class="print-section">
                            <div class="section-title">Follow-up</div>
                            <div class="section-content" id="viewFollowUp">-</div>
                        </div>

                        <div class="print-footer">
                            <p>This is a computer-generated discharge summary. Please verify all information.</p>
                            <p>Generated on: <?php echo date('d M Y H:i'); ?></p>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary" onclick="printDischargeSummary()"><i class="fas fa-print"></i> Print</button>
                <button type="button" class="btn btn-success" onclick="editDischargeFromModal()"><i class="fas fa-edit"></i> Edit</button>
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
let currentAdmissionId = null;
let isEditMode = false;

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

function getDischargeConditionLabel(condition) {
    const map = {
        'improved': 'Improved',
        'stabilized': 'Stabilized',
        'relieved': 'Relieved',
        'ama': 'AMA',
        'expired': 'Expired',
        'transferred': 'Transferred'
    };
    return map[condition] || condition;
}

// ======================================================================
// FETCH DISCHARGED PATIENTS
// ======================================================================
function fetchDischargedPatients() {
    const search = document.getElementById('searchInput').value;
    const condition = document.getElementById('conditionFilter').value;
    const dateFrom = document.getElementById('dateFrom').value;
    const dateTo = document.getElementById('dateTo').value;

    showLoading(true);
    $.post(window.location.pathname + '?ajax=discharge_action', {
        action: 'fetch_discharged_patients',
        branch_id: branchId,
        search: search,
        discharge_condition: condition,
        date_from: dateFrom,
        date_to: dateTo,
        csrf_token: csrfToken
    }, function(res) {
        showLoading(false);
        if (res.success && res.data && res.data.patients) {
            renderDischargedPatients(res.data.patients);
            updateStats(res.data.patients);
            document.getElementById('recordCount').textContent = res.data.patients.length + ' patients';
        } else {
            showToast(res.message || 'Failed to load data', 'error');
        }
    }).fail(function() {
        showLoading(false);
        showToast('Network error. Please try again.', 'error');
    });
}

function renderDischargedPatients(patients) {
    const tbody = document.getElementById('dischargeBody');
    if (!patients || patients.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="9" class="text-center text-muted py-4">
                    <i class="fas fa-sign-out-alt fa-2x d-block mb-2" style="opacity:0.3;"></i>
                    No discharged patients found
                </td>
            </tr>
        `;
        return;
    }

    let html = '';
    patients.forEach(row => {
        const trClass = row.has_summary ? '' : 'no-summary';
        const conditionLabel = getDischargeConditionLabel(row.discharge_condition || 'improved');
        
        html += `
            <tr class="${trClass}" data-admission-id="${row.admission_id}">
                <td><strong>${escapeHtml(row.admission_number)}</strong></td>
                <td>
                    <strong>${escapeHtml(row.patient_name)}</strong>
                    ${!row.has_summary ? '<span class="badge bg-warning text-dark" style="font-size:8px;">NO SUMMARY</span>' : ''}
                </td>
                <td>${escapeHtml(row.mr_number)}</td>
                <td>${row.age} yrs<br><small class="text-muted">${escapeHtml(row.gender || 'N/A')}</small></td>
                <td>
                    <span class="status-badge status-${row.discharge_condition || 'improved'}">
                        ${conditionLabel}
                    </span>
                </td>
                <td>${escapeHtml(row.discharged_date)}</td>
                <td>${escapeHtml(row.doctor_name)}</td>
                <td>
                    ${row.has_summary ? 
                        '<span class="badge bg-success">Complete</span>' : 
                        '<span class="badge bg-warning text-dark">Pending</span>'
                    }
                </td>
                <td>
                    <button class="btn-sm-icon" onclick="viewDischarge(${row.admission_id})" title="View Summary">
                        <i class="fas fa-file-medical-alt"></i>
                    </button>
                    <button class="btn-sm-icon success" onclick="editDischarge(${row.admission_id})" title="Edit Summary">
                        <i class="fas fa-edit"></i>
                    </button>
                    <button class="btn-sm-icon" onclick="printDischarge(${row.admission_id})" title="Print Summary">
                        <i class="fas fa-print"></i>
                    </button>
                    <button class="btn-sm-icon danger" onclick="deleteDischarge(${row.admission_id})" title="Revert Discharge">
                        <i class="fas fa-undo"></i>
                    </button>
                </td>
            </tr>
        `;
    });
    tbody.innerHTML = html;
}

function updateStats(patients) {
    let total = patients.length;
    let improved = 0, stabilized = 0, ama = 0, expired = 0;
    
    patients.forEach(p => {
        switch(p.discharge_condition) {
            case 'improved': improved++; break;
            case 'stabilized': stabilized++; break;
            case 'ama': ama++; break;
            case 'expired': expired++; break;
        }
    });
    
    document.getElementById('statTotal').textContent = total;
    document.getElementById('statImproved').textContent = improved;
    document.getElementById('statStabilized').textContent = stabilized;
    document.getElementById('statAMA').textContent = ama;
    document.getElementById('statExpired').textContent = expired;
}

// ======================================================================
// SEARCH ADMITTED PATIENTS (for new discharge)
// ======================================================================
function searchAdmittedPatients() {
    const search = document.getElementById('admittedSearch').value.trim();
    const resultsContainer = document.getElementById('admittedSearchResults');
    
    if (search.length < 2) {
        resultsContainer.style.display = 'none';
        showToast('Please enter at least 2 characters', 'warning');
        return;
    }

    showLoading(true);
    $.post(window.location.pathname + '?ajax=discharge_action', {
        action: 'fetch_admitted_patients',
        branch_id: branchId,
        search: search,
        csrf_token: csrfToken
    }, function(res) {
        showLoading(false);
        if (res.success && res.data && res.data.patients) {
            if (res.data.patients.length > 0) {
                renderAdmittedResults(res.data.patients);
            } else {
                resultsContainer.innerHTML = '<div class="p-3 text-muted text-center">No admitted patients found. All patients may already have discharge summaries.</div>';
                resultsContainer.style.display = 'block';
            }
        } else {
            resultsContainer.innerHTML = `<div class="p-3 text-danger text-center">${escapeHtml(res.message || 'Search failed')}</div>`;
            resultsContainer.style.display = 'block';
        }
    }).fail(function() {
        showLoading(false);
        resultsContainer.innerHTML = '<div class="p-3 text-danger text-center">Network error. Please try again.</div>';
        resultsContainer.style.display = 'block';
        showToast('Network error', 'error');
    });
}

function renderAdmittedResults(patients) {
    const container = document.getElementById('admittedSearchResults');
    
    let html = '';
    patients.forEach(p => {
        const genderLabel = p.gender ? p.gender.charAt(0).toUpperCase() + p.gender.slice(1) : 'N/A';
        html += `
            <div class="patient-search-result p-2 border-bottom" onclick="selectAdmittedPatient(${p.admission_id}, '${escapeHtml(p.patient_name)}', '${escapeHtml(p.mr_number)}', ${p.age || 'null'}, '${escapeHtml(genderLabel)}', '${escapeHtml(p.admitted_date)}')" style="cursor:pointer;">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <strong>${escapeHtml(p.patient_name)}</strong>
                        <span class="text-muted ms-2">MRN: ${escapeHtml(p.mr_number)}</span>
                    </div>
                    <div>
                        <span class="badge bg-secondary">${p.age || 'N/A'} yrs</span>
                        <span class="badge bg-light text-dark">${genderLabel}</span>
                        <span class="badge bg-info">Day ${p.days_admitted || 0}</span>
                    </div>
                </div>
                <div class="text-muted small">
                    Admitted: ${escapeHtml(p.admitted_date)} | Ward: ${escapeHtml(p.ward_name || 'N/A')} | Bed: ${escapeHtml(p.bed_code || 'N/A')}
                    <br>Diagnosis: ${escapeHtml(p.primary_diagnosis || 'N/A')}
                </div>
            </div>
        `;
    });
    container.innerHTML = html;
    container.style.display = 'block';
}

function selectAdmittedPatient(admissionId, name, mrn, age, gender, admittedDate) {
    document.getElementById('selectedAdmissionId').value = admissionId;
    document.getElementById('dischargeAdmissionId').value = admissionId;
    document.getElementById('selectedPatientName').value = name;
    document.getElementById('displayPatientName').textContent = name;
    document.getElementById('displayPatientMRN').textContent = 'MRN: ' + mrn;
    document.getElementById('displayPatientAge').textContent = age ? age + ' yrs' : 'N/A';
    document.getElementById('displayPatientGender').textContent = gender;
    document.getElementById('displayAdmissionDate').textContent = 'Admitted: ' + admittedDate;
    document.getElementById('selectedPatientDisplay').style.display = 'block';
    document.getElementById('admittedSearchResults').style.display = 'none';
    document.getElementById('admittedSearch').value = '';
    showToast('Patient selected: ' + name);
}

function clearSelectedPatient() {
    document.getElementById('selectedAdmissionId').value = '';
    document.getElementById('dischargeAdmissionId').value = '';
    document.getElementById('selectedPatientName').value = '';
    document.getElementById('selectedPatientDisplay').style.display = 'none';
    document.getElementById('admittedSearch').value = '';
}

// Enter key for patient search
document.getElementById('admittedSearch').addEventListener('keypress', function(e) {
    if (e.key === 'Enter') {
        e.preventDefault();
        searchAdmittedPatients();
    }
});

// ======================================================================
// OPEN NEW DISCHARGE MODAL
// ======================================================================
function openNewDischargeModal() {
    isEditMode = false;
    document.getElementById('dischargeModalTitle').textContent = 'New Discharge';
    document.getElementById('dischargeForm').reset();
    document.getElementById('dischargeAdmissionId').value = '';
    document.getElementById('selectedAdmissionId').value = '';
    document.getElementById('selectedPatientName').value = '';
    document.getElementById('selectedPatientDisplay').style.display = 'none';
    document.getElementById('admittedSearchResults').style.display = 'none';
    document.getElementById('admittedSearch').value = '';
    document.getElementById('patientSearchSection').style.display = 'block';
    document.getElementById('dischargeCondition').value = 'improved';
    $('#newDischargeModal').modal('show');
}

// ======================================================================
// SAVE DISCHARGE
// ======================================================================
function saveDischarge() {
    const admissionId = document.getElementById('dischargeAdmissionId').value;
    const condition = document.getElementById('dischargeCondition').value;
    const summary = document.getElementById('dischargeSummary').value.trim();
    const instructions = document.getElementById('dischargeInstructions').value.trim();
    const followUp = document.getElementById('followUpDate').value;
    const medications = document.getElementById('homeMedications').value.trim();
    const warningSigns = document.getElementById('warningSigns').value.trim();
    const procedures = document.getElementById('dischargeProcedures').value.trim();

    if (!admissionId) {
        showToast('Please select a patient', 'error');
        return;
    }

    if (!summary) {
        showToast('Please enter a discharge summary', 'error');
        return;
    }

    showLoading(true);
    $.post(window.location.pathname + '?ajax=discharge_action', {
        action: 'save_discharge',
        admission_id: admissionId,
        discharge_condition: condition,
        discharge_summary: summary,
        discharge_instructions: instructions,
        follow_up_date: followUp,
        home_medications: medications,
        warning_signs: warningSigns,
        procedures: procedures,
        csrf_token: csrfToken
    }, function(res) {
        showLoading(false);
        if (res.success) {
            $('#newDischargeModal').modal('hide');
            showToast(res.message);
            fetchDischargedPatients();
        } else {
            showToast(res.message || 'Save failed', 'error');
        }
    }).fail(function() {
        showLoading(false);
        showToast('Network error', 'error');
    });
}

// ======================================================================
// VIEW DISCHARGE
// ======================================================================
function viewDischarge(admissionId) {
    const modal = new bootstrap.Modal(document.getElementById('viewDischargeModal'));
    document.getElementById('viewLoading').style.display = 'block';
    document.getElementById('viewContent').style.display = 'none';
    currentAdmissionId = admissionId;
    modal.show();

    $.post(window.location.pathname + '?ajax=discharge_action', {
        action: 'fetch_discharge_detail',
        admission_id: admissionId,
        branch_id: branchId,
        csrf_token: csrfToken
    }, function(res) {
        document.getElementById('viewLoading').style.display = 'none';
        if (res.success && res.data && res.data.patient) {
            const p = res.data.patient;
            
            // Patient Info
            document.getElementById('viewName').textContent = p.patient_name || '-';
            document.getElementById('viewMRN').textContent = p.mr_number || '-';
            document.getElementById('viewAgeGender').textContent = (p.age || 'N/A') + ' yrs / ' + (p.gender ? p.gender.charAt(0).toUpperCase() + p.gender.slice(1) : 'N/A');
            document.getElementById('viewPhone').textContent = p.phone || '-';
            document.getElementById('viewAddress').textContent = p.address || '-';
            
            // Admission Info
            document.getElementById('viewAdmissionNumber').textContent = p.admission_number || '-';
            document.getElementById('viewAdmittedDate').textContent = p.admitted_at ? new Date(p.admitted_at).toLocaleString() : '-';
            document.getElementById('viewDischargedDate').textContent = p.discharged_at ? new Date(p.discharged_at).toLocaleString() : '-';
            document.getElementById('viewWardBed').textContent = (p.ward_name || 'N/A') + ' / ' + (p.bed_code || 'N/A');
            document.getElementById('viewDoctor').textContent = p.doctor_name || '-';
            
            // Calculate length of stay
            if (p.admitted_at && p.discharged_at) {
                const days = Math.floor((new Date(p.discharged_at) - new Date(p.admitted_at)) / (1000 * 60 * 60 * 24));
                document.getElementById('viewLengthOfStay').textContent = days + ' days';
            } else {
                document.getElementById('viewLengthOfStay').textContent = '-';
            }
            
            // Clinical Info
            document.getElementById('viewDiagnosis').textContent = p.primary_diagnosis || '-';
            document.getElementById('viewProcedures').textContent = p.procedures || 'None documented';
            document.getElementById('viewSummary').textContent = p.discharge_summary || 'No summary available';
            
            const conditionLabel = getDischargeConditionLabel(p.discharge_condition || 'improved');
            const conditionClass = p.discharge_condition || 'improved';
            document.getElementById('viewCondition').textContent = conditionLabel;
            document.getElementById('viewCondition').className = 'status-badge status-' + conditionClass;
            
            document.getElementById('viewInstructions').textContent = p.discharge_instructions || 'None provided';
            document.getElementById('viewMedications').textContent = p.home_medications || 'None prescribed';
            document.getElementById('viewWarningSigns').textContent = p.warning_signs || 'None documented';
            document.getElementById('viewFollowUp').textContent = p.follow_up_date ? 'Follow-up on ' + new Date(p.follow_up_date).toLocaleDateString() : 'No follow-up scheduled';
            
            document.getElementById('viewContent').style.display = 'block';
        } else {
            showToast(res.message || 'Failed to load discharge details', 'error');
        }
    }).fail(function() {
        document.getElementById('viewLoading').style.display = 'none';
        showToast('Network error', 'error');
    });
}

// ======================================================================
// EDIT DISCHARGE
// ======================================================================
function editDischarge(admissionId) {
    // Close view modal if open
    if ($('#viewDischargeModal').hasClass('show')) {
        $('#viewDischargeModal').modal('hide');
    }
    
    isEditMode = true;
    document.getElementById('dischargeModalTitle').textContent = 'Edit Discharge Summary';
    document.getElementById('patientSearchSection').style.display = 'none';
    document.getElementById('dischargeAdmissionId').value = admissionId;
    document.getElementById('selectedAdmissionId').value = admissionId;
    
    // Load discharge data into form
    showLoading(true);
    $.post(window.location.pathname + '?ajax=discharge_action', {
        action: 'fetch_discharge_detail',
        admission_id: admissionId,
        branch_id: branchId,
        csrf_token: csrfToken
    }, function(res) {
        showLoading(false);
        if (res.success && res.data && res.data.patient) {
            const p = res.data.patient;
            
            document.getElementById('displayPatientName').textContent = p.patient_name || '-';
            document.getElementById('displayPatientMRN').textContent = 'MRN: ' + (p.mr_number || '-');
            document.getElementById('displayPatientAge').textContent = (p.age || 'N/A') + ' yrs';
            document.getElementById('displayPatientGender').textContent = p.gender ? p.gender.charAt(0).toUpperCase() + p.gender.slice(1) : 'N/A';
            document.getElementById('displayAdmissionDate').textContent = 'Admitted: ' + (p.admitted_at ? new Date(p.admitted_at).toLocaleDateString() : 'N/A');
            document.getElementById('selectedPatientDisplay').style.display = 'block';
            
            document.getElementById('dischargeCondition').value = p.discharge_condition || 'improved';
            document.getElementById('dischargeSummary').value = p.discharge_summary || '';
            document.getElementById('dischargeInstructions').value = p.discharge_instructions || '';
            document.getElementById('followUpDate').value = p.follow_up_date || '';
            document.getElementById('homeMedications').value = p.home_medications || '';
            document.getElementById('warningSigns').value = p.warning_signs || '';
            document.getElementById('dischargeProcedures').value = p.procedures || '';
            
            $('#newDischargeModal').modal('show');
        } else {
            showToast(res.message || 'Failed to load discharge details', 'error');
        }
    }).fail(function() {
        showLoading(false);
        showToast('Network error', 'error');
    });
}

function editDischargeFromModal() {
    if (currentAdmissionId) {
        $('#viewDischargeModal').modal('hide');
        // Small delay to allow modal to close
        setTimeout(() => {
            editDischarge(currentAdmissionId);
        }, 300);
    }
}

// ======================================================================
// PRINT DISCHARGE
// ======================================================================
function printDischarge(admissionId) {
    // First load the data
    showLoading(true);
    $.post(window.location.pathname + '?ajax=discharge_action', {
        action: 'fetch_discharge_detail',
        admission_id: admissionId,
        branch_id: branchId,
        csrf_token: csrfToken
    }, function(res) {
        showLoading(false);
        if (res.success && res.data && res.data.patient) {
            const p = res.data.patient;
            
            // Build print content
            const conditionLabel = getDischargeConditionLabel(p.discharge_condition || 'improved');
            const conditionClass = p.discharge_condition || 'improved';
            const lengthOfStay = p.admitted_at && p.discharged_at ? 
                Math.floor((new Date(p.discharged_at) - new Date(p.admitted_at)) / (1000 * 60 * 60 * 24)) + ' days' : '-';
            
            const printContent = `
                <div class="print-header">
                    <div class="hospital-name">Central General Hospital</div>
                    <div class="hospital-address">Bole Road, Addis Ababa, Ethiopia | Phone: +251-11-555-1000</div>
                </div>

                <div class="row">
                    <div class="col-md-6">
                        <div class="print-section">
                            <div class="section-title">Patient Information</div>
                            <div class="section-content">
                                <strong>Name:</strong> ${escapeHtml(p.patient_name || '-')}<br>
                                <strong>MRN:</strong> ${escapeHtml(p.mr_number || '-')}<br>
                                <strong>Age / Gender:</strong> ${p.age || 'N/A'} yrs / ${p.gender ? p.gender.charAt(0).toUpperCase() + p.gender.slice(1) : 'N/A'}<br>
                                <strong>Phone:</strong> ${escapeHtml(p.phone || '-')}<br>
                                <strong>Address:</strong> ${escapeHtml(p.address || '-')}
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="print-section">
                            <div class="section-title">Admission Information</div>
                            <div class="section-content">
                                <strong>Admission #:</strong> ${escapeHtml(p.admission_number || '-')}<br>
                                <strong>Admitted:</strong> ${p.admitted_at ? new Date(p.admitted_at).toLocaleString() : '-'}<br>
                                <strong>Discharged:</strong> ${p.discharged_at ? new Date(p.discharged_at).toLocaleString() : '-'}<br>
                                <strong>Length of Stay:</strong> ${lengthOfStay}<br>
                                <strong>Ward / Bed:</strong> ${escapeHtml(p.ward_name || 'N/A')} / ${escapeHtml(p.bed_code || 'N/A')}<br>
                                <strong>Doctor:</strong> ${escapeHtml(p.doctor_name || '-')}
                            </div>
                        </div>
                    </div>
                </div>

                <div class="print-section">
                    <div class="section-title">Primary Diagnosis</div>
                    <div class="section-content">${escapeHtml(p.primary_diagnosis || '-')}</div>
                </div>

                <div class="print-section">
                    <div class="section-title">Procedures Performed</div>
                    <div class="section-content">${escapeHtml(p.procedures || 'None documented')}</div>
                </div>

                <div class="print-section">
                    <div class="section-title">Discharge Summary</div>
                    <div class="section-content">${escapeHtml(p.discharge_summary || 'No summary available')}</div>
                </div>

                <div class="print-section">
                    <div class="section-title">Discharge Condition</div>
                    <div class="section-content"><span class="status-badge status-${conditionClass}">${conditionLabel}</span></div>
                </div>

                <div class="print-section">
                    <div class="section-title">Discharge Instructions</div>
                    <div class="section-content">${escapeHtml(p.discharge_instructions || 'None provided')}</div>
                </div>

                <div class="row">
                    <div class="col-md-6">
                        <div class="print-section">
                            <div class="section-title">Home Medications</div>
                            <div class="section-content">${escapeHtml(p.home_medications || 'None prescribed')}</div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="print-section">
                            <div class="section-title">Warning Signs</div>
                            <div class="section-content">${escapeHtml(p.warning_signs || 'None documented')}</div>
                        </div>
                    </div>
                </div>

                <div class="print-section">
                    <div class="section-title">Follow-up</div>
                    <div class="section-content">${p.follow_up_date ? 'Follow-up on ' + new Date(p.follow_up_date).toLocaleDateString() : 'No follow-up scheduled'}</div>
                </div>

                <div class="print-footer">
                    <p>This is a computer-generated discharge summary. Please verify all information.</p>
                    <p>Generated on: ${new Date().toLocaleString()}</p>
                </div>
            `;

            const printWindow = window.open('', '_blank', 'width=800,height=600');
            printWindow.document.write(`
                <html>
                    <head>
                        <title>Discharge Summary</title>
                        <style>
                            body { font-family: Arial, sans-serif; padding: 40px; max-width: 800px; margin: 0 auto; }
                            .print-header { border-bottom: 2px solid #333; padding-bottom: 16px; margin-bottom: 16px; }
                            .hospital-name { font-size: 24px; font-weight: 800; color: #1a1a2e; }
                            .hospital-address { font-size: 12px; color: #6c757d; }
                            .print-section { margin-bottom: 16px; }
                            .section-title { font-weight: 700; font-size: 14px; border-bottom: 1px solid #e9ecef; padding-bottom: 4px; margin-bottom: 8px; }
                            .section-content { font-size: 13px; line-height: 1.6; }
                            .print-footer { border-top: 2px solid #e9ecef; padding-top: 16px; margin-top: 16px; font-size: 12px; color: #6c757d; text-align: center; }
                            .status-badge { display: inline-block; padding: 2px 10px; border-radius: 20px; font-size: 12px; font-weight: 600; }
                            .status-improved { background: #d1fae5; color: #059669; }
                            .status-stabilized { background: #dbeafe; color: #2563eb; }
                            .status-relieved { background: #e0e7ff; color: #3730a3; }
                            .status-ama { background: #fef3c7; color: #d97706; }
                            .status-expired { background: #fee2e2; color: #dc2626; }
                            .status-transferred { background: #e5e7eb; color: #4b5563; }
                            .row { display: flex; flex-wrap: wrap; margin: 0 -8px; }
                            .col-md-6 { flex: 0 0 50%; padding: 0 8px; box-sizing: border-box; }
                            @media print { body { padding: 20px; } }
                        </style>
                    </head>
                    <body>
                        ${printContent}
                        <script>
                            window.onload = function() { window.print(); window.close(); }
                        <\/script>
                    </body>
                </html>
            `);
            printWindow.document.close();
        } else {
            showToast(res.message || 'Failed to load discharge details', 'error');
        }
    }).fail(function() {
        showLoading(false);
        showToast('Network error', 'error');
    });
}

function printDischargeSummary() {
    // If viewing modal, trigger print
    const printContent = document.getElementById('printArea');
    if (printContent) {
        // We need to clone the content to avoid printing modal styles
        const content = printContent.innerHTML;
        const printWindow = window.open('', '_blank', 'width=800,height=600');
        printWindow.document.write(`
            <html>
                <head>
                    <title>Discharge Summary</title>
                    <style>
                        body { font-family: Arial, sans-serif; padding: 40px; max-width: 800px; margin: 0 auto; }
                        .print-header { border-bottom: 2px solid #333; padding-bottom: 16px; margin-bottom: 16px; }
                        .hospital-name { font-size: 24px; font-weight: 800; color: #1a1a2e; }
                        .hospital-address { font-size: 12px; color: #6c757d; }
                        .print-section { margin-bottom: 16px; }
                        .section-title { font-weight: 700; font-size: 14px; border-bottom: 1px solid #e9ecef; padding-bottom: 4px; margin-bottom: 8px; }
                        .section-content { font-size: 13px; line-height: 1.6; }
                        .print-footer { border-top: 2px solid #e9ecef; padding-top: 16px; margin-top: 16px; font-size: 12px; color: #6c757d; text-align: center; }
                        .status-badge { display: inline-block; padding: 2px 10px; border-radius: 20px; font-size: 12px; font-weight: 600; }
                        .status-improved { background: #d1fae5; color: #059669; }
                        .status-stabilized { background: #dbeafe; color: #2563eb; }
                        .status-relieved { background: #e0e7ff; color: #3730a3; }
                        .status-ama { background: #fef3c7; color: #d97706; }
                        .status-expired { background: #fee2e2; color: #dc2626; }
                        .status-transferred { background: #e5e7eb; color: #4b5563; }
                        .row { display: flex; flex-wrap: wrap; margin: 0 -8px; }
                        .col-md-6 { flex: 0 0 50%; padding: 0 8px; box-sizing: border-box; }
                        @media print { body { padding: 20px; } }
                    </style>
                </head>
                <body>
                    ${content}
                    <script>
                        window.onload = function() { window.print(); window.close(); }
                    <\/script>
                </body>
            </html>
        `);
        printWindow.document.close();
    }
}

// ======================================================================
// DELETE/REVERT DISCHARGE
// ======================================================================
function deleteDischarge(admissionId) {
    Swal.fire({
        title: 'Revert Discharge?',
        text: 'This will revert the patient back to "Admitted" status. The bed will be reassigned and the discharge summary will be removed. Are you sure?',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Yes, revert discharge',
        cancelButtonText: 'Cancel'
    }).then(result => {
        if (result.isConfirmed) {
            showLoading(true);
            $.post(window.location.pathname + '?ajax=discharge_action', {
                action: 'delete_discharge',
                admission_id: admissionId,
                csrf_token: csrfToken
            }, function(res) {
                showLoading(false);
                if (res.success) {
                    showToast(res.message);
                    fetchDischargedPatients();
                } else {
                    showToast(res.message || 'Revert failed', 'error');
                }
            }).fail(function() {
                showLoading(false);
                showToast('Network error', 'error');
            });
        }
    });
}

// ======================================================================
// FILTERS & REFRESH
// ======================================================================
function applyFilters() {
    fetchDischargedPatients();
}

function resetFilters() {
    document.getElementById('searchInput').value = '';
    document.getElementById('conditionFilter').value = '';
    document.getElementById('dateFrom').value = '';
    document.getElementById('dateTo').value = '';
    fetchDischargedPatients();
}

function refreshData() {
    fetchDischargedPatients();
    showToast('Data refreshed');
}

// ======================================================================
// AUTO REFRESH
// ======================================================================
function startPolling() {
    if (refreshInterval) clearInterval(refreshInterval);
    refreshInterval = setInterval(() => {
        fetchDischargedPatients();
    }, 60000);
}

// ======================================================================
// DOCUMENT READY
// ======================================================================
$(document).ready(function() {
    startPolling();
    fetchDischargedPatients();

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

console.log('Discharge Summary loaded successfully.');
</script>
</body>
</html>