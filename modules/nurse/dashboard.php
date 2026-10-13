<?php
/**
 * ======================================================================
 * NURSING DASHBOARD - CLINICAL SPLIT-SCREEN WORKSPACE (Phase 6)
 * - Real-time HUD: Triage Queue, Occupied Beds, Active Inpatients, Pending Phlebotomy
 * - Left panel: Quick queues (Outpatient Triage & Active Inpatients)
 * - Right panel: HUD cards + full roster tables
 * - 100% English UI, branch isolation, CSRF protection, auto-refresh (15s)
 * - FEATURES: Bed Management, Patient Notes, Sample Collection (Lab integration)
 * ======================================================================
 */

session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);   // Turn off in production

require_once __DIR__ . '/../../config/db_connection.php';
require_once __DIR__ . '/../../includes/auth_helper.php';

date_default_timezone_set('Africa/Addis_Ababa');
if ($pdo) $pdo->exec("SET time_zone = '+03:00'");

$user_id = (int)($_SESSION['user_id'] ?? 0);
$branch_id = (int)($_SESSION['branch_id'] ?? 0);
$is_global_admin = ($_SESSION['user_type'] ?? '') === 'super_admin';
$user_name = $_SESSION['first_name'] ?? $_SESSION['username'] ?? 'Nurse';
$user_type = $_SESSION['user_type'] ?? '';

if ($user_id <= 0) {
    header('Location: ../../index.php');
    exit;
}

if (!$is_global_admin && !user_has_permission($pdo, 'nursing_dashboard_access', $user_id)) {
    header('Location: dashboard.php?error=unauthorized');
    exit;
}

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

function jsonResponse($success, $message, $data = null, $code = 200) {
    if (ob_get_length()) ob_clean();
    http_response_code($code);
    header('Content-Type: application/json');
    $response = ['success' => $success, 'message' => $message];
    if ($data !== null) $response = array_merge($response, $data);
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

function escapeHtml($str) {
    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}

// ----------------------------------------------------------------------
// AJAX HANDLER (nursing_action)
// ----------------------------------------------------------------------
if (isset($_GET['ajax']) && $_GET['ajax'] === 'nursing_action') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(false, 'Invalid request method');
    }

    $action = $_POST['action'] ?? '';
    if (in_array($action, ['record_vitals', 'start_triage', 'complete_triage', 'assign_bed'])) {
        if (!hash_equals($csrf_token, (string)($_POST['csrf_token'] ?? ''))) {
            jsonResponse(false, 'Security token mismatch');
        }
    }

    try {
        // ---------- HUD METRICS (real-time counters) ----------
        if ($action === 'fetch_hud_metrics') {
            $metrics = [
                'triage_queue' => 0,
                'occupied_beds' => 0,
                'available_beds' => 0,
                'active_inpatients' => 0,
                'pending_phlebotomy' => 0,
                'critical_patients' => 0,
                'pending_lab_samples' => 0
            ];

            // 1. Triage Queue Count
            $sql = "SELECT COUNT(DISTINCT pv.id)
                    FROM patient_visits pv
                    LEFT JOIN triage_records tr ON tr.visit_id = pv.id AND tr.deleted_at IS NULL
                    WHERE pv.status IN ('checked_in', 'pending', 'in_triage')
                      AND pv.deleted_at IS NULL
                      AND (tr.id IS NULL OR tr.disposition IS NULL OR tr.disposition = '')";
            $params = [];
            if (!$is_global_admin && $branch_id > 0) {
                $sql .= " AND pv.branch_id = :branch_id";
                $params[':branch_id'] = $branch_id;
            }
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $metrics['triage_queue'] = (int)$stmt->fetchColumn();

            // 2. Occupied Beds
            $sql = "SELECT COUNT(*) FROM beds WHERE status = 'occupied' AND deleted_at IS NULL";
            $params = [];
            if (!$is_global_admin && $branch_id > 0) {
                $sql .= " AND branch_id = :branch_id";
                $params[':branch_id'] = $branch_id;
            }
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $metrics['occupied_beds'] = (int)$stmt->fetchColumn();

            // 3. Available Beds
            $sql = "SELECT COUNT(*) FROM beds WHERE status = 'available' AND deleted_at IS NULL";
            $params = [];
            if (!$is_global_admin && $branch_id > 0) {
                $sql .= " AND branch_id = :branch_id";
                $params[':branch_id'] = $branch_id;
            }
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $metrics['available_beds'] = (int)$stmt->fetchColumn();

            // 4. Active Inpatients
            $sql = "SELECT COUNT(*) FROM admissions
                    WHERE discharged_at IS NULL
                      AND status = 'admitted'
                      AND deleted_at IS NULL";
            $params = [];
            if (!$is_global_admin && $branch_id > 0) {
                $sql .= " AND branch_id = :branch_id";
                $params[':branch_id'] = $branch_id;
            }
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $metrics['active_inpatients'] = (int)$stmt->fetchColumn();

            // 5. Pending Phlebotomy Samples
            $sql = "SELECT COUNT(DISTINCT id) FROM lab_orders
                    WHERE status IN ('pending_payment', 'pending', 'approved')
                      AND deleted_at IS NULL";
            $params = [];
            if (!$is_global_admin && $branch_id > 0) {
                $sql .= " AND branch_id = :branch_id";
                $params[':branch_id'] = $branch_id;
            }
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $metrics['pending_phlebotomy'] = (int)$stmt->fetchColumn();

            // 6. Critical Patients
            $sql = "SELECT COUNT(*) FROM admissions
                    WHERE condition_status IN ('critical', 'serious')
                      AND status = 'admitted'
                      AND deleted_at IS NULL";
            $params = [];
            if (!$is_global_admin && $branch_id > 0) {
                $sql .= " AND branch_id = :branch_id";
                $params[':branch_id'] = $branch_id;
            }
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $metrics['critical_patients'] = (int)$stmt->fetchColumn();

            // 7. Pending Lab Samples (samples collected but not processed)
            $sql = "SELECT COUNT(DISTINCT loi.id)
                    FROM lab_order_items loi
                    INNER JOIN lab_orders lo ON loi.lab_order_id = lo.id AND lo.deleted_at IS NULL
                    WHERE loi.status = 'sample_collected'
                      AND loi.deleted_at IS NULL";
            $params = [];
            if (!$is_global_admin && $branch_id > 0) {
                $sql .= " AND lo.branch_id = :branch_id";
                $params[':branch_id'] = $branch_id;
            }
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $metrics['pending_lab_samples'] = (int)$stmt->fetchColumn();

            jsonResponse(true, '', ['metrics' => $metrics]);
        }

        // ---------- FETCH TRIAGE QUEUE ----------
        if ($action === 'fetch_triage_queue') {
            $sql = "SELECT
                        pv.id AS visit_id,
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
                        pv.created_at AS checkin_time,
                        pv.visit_type,
                        COALESCE(tr.priority, 'green') AS priority,
                        a.chief_complaint,
                        tr.id AS triage_id,
                        tr.disposition,
                        tr.blood_pressure_systolic
                    FROM patient_visits pv
                    INNER JOIN patients p ON pv.patient_id = p.id AND p.deleted_at IS NULL AND p.is_active = 1
                    LEFT JOIN triage_records tr ON pv.id = tr.visit_id AND tr.deleted_at IS NULL
                    LEFT JOIN appointments a ON pv.id = a.visit_id AND a.deleted_at IS NULL
                    WHERE pv.status IN ('checked_in', 'pending', 'in_triage')
                      AND pv.deleted_at IS NULL
                      AND (tr.id IS NULL OR tr.disposition IS NULL OR tr.disposition = '')
                    ORDER BY 
                        CASE WHEN tr.id IS NULL THEN 0 ELSE 1 END,
                        FIELD(COALESCE(tr.priority, 'green'), 'red', 'yellow', 'green', 'blue'), 
                        pv.created_at ASC";
            $params = [];
            if (!$is_global_admin && $branch_id > 0) {
                $sql .= " AND pv.branch_id = :branch_id";
                $params[':branch_id'] = $branch_id;
            }
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $queue = [];
            $num = 1;
            foreach ($rows as $r) {
                $patient_name = preg_replace('/\s+/', ' ', trim($r['patient_name'] ?? 'Unknown Patient'));
                
                $age = 'N/A';
                if (!empty($r['date_of_birth'])) {
                    $dob = new DateTime($r['date_of_birth']);
                    $now = new DateTime();
                    $age = $dob->diff($now)->y;
                }
                
                $status_label = 'Waiting';
                if (!empty($r['triage_id']) && empty($r['disposition'])) {
                    $status_label = 'In Triage';
                }
                
                $queue[] = [
                    'queue_number'   => 'Q' . str_pad($num++, 3, '0', STR_PAD_LEFT),
                    'visit_id'       => $r['visit_id'],
                    'patient_id'     => $r['patient_id'],
                    'patient_name'   => $patient_name,
                    'mr_number'      => $r['mr_number'],
                    'gender'         => $r['gender'],
                    'age'            => $age,
                    'priority'       => $r['priority'] ?: 'green',
                    'visit_type'     => $r['visit_type'] ?? 'regular',
                    'chief_complaint'=> $r['chief_complaint'] ?? '',
                    'checkin_time'   => date('H:i', strtotime($r['checkin_time'])),
                    'status_label'   => $status_label,
                    'triage_id'      => $r['triage_id']
                ];
            }
            jsonResponse(true, '', ['queue' => $queue]);
        }

        // ---------- FETCH ACTIVE INPATIENTS ----------
        if ($action === 'fetch_inpatients') {
            $sql = "SELECT
                        a.id AS admission_id,
                        a.admission_number,
                        a.admitted_at,
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
                        b.id AS bed_id,
                        b.bed_number AS bed_code,
                        b.bed_type,
                        b.room_id,
                        w.id AS ward_id,
                        w.name AS ward_name,
                        CONCAT(
                            COALESCE(d.first_name, ''),
                            ' ',
                            COALESCE(d.middle_name, ''),
                            ' ',
                            COALESCE(d.last_name, '')
                        ) AS doctor_name,
                        a.primary_diagnosis,
                        a.admission_type,
                        a.admission_notes
                    FROM admissions a
                    INNER JOIN patients p ON a.patient_id = p.id AND p.deleted_at IS NULL AND p.is_active = 1
                    LEFT JOIN beds b ON a.bed_id = b.id AND b.deleted_at IS NULL
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

            $inpatients = [];
            foreach ($rows as $r) {
                $patient_name = preg_replace('/\s+/', ' ', trim($r['patient_name'] ?? 'Unknown Patient'));
                $doctor_name = preg_replace('/\s+/', ' ', trim($r['doctor_name'] ?? 'Unassigned'));
                
                $age = 'N/A';
                if (!empty($r['date_of_birth'])) {
                    $dob = new DateTime($r['date_of_birth']);
                    $now = new DateTime();
                    $age = $dob->diff($now)->y;
                }
                $inpatients[] = [
                    'admission_id'     => $r['admission_id'],
                    'admission_number' => $r['admission_number'],
                    'patient_id'       => $r['patient_id'],
                    'patient_name'     => $patient_name,
                    'mr_number'        => $r['mr_number'],
                    'age'              => $age,
                    'gender'           => $r['gender'],
                    'bed_id'           => $r['bed_id'],
                    'bed_code'         => $r['bed_code'] ?: 'Not Assigned',
                    'bed_type'         => $r['bed_type'] ?? '',
                    'room_id'          => $r['room_id'],
                    'ward_id'          => $r['ward_id'],
                    'ward_name'        => $r['ward_name'] ?? '',
                    'doctor_name'      => $doctor_name,
                    'condition_status' => $r['condition_status'] ?? 'stable',
                    'primary_diagnosis'=> $r['primary_diagnosis'] ?? '',
                    'admission_type'   => $r['admission_type'] ?? 'elective',
                    'admission_notes'  => $r['admission_notes'] ?? '',
                    'admitted_date'    => date('d M Y', strtotime($r['admitted_at']))
                ];
            }
            jsonResponse(true, '', ['inpatients' => $inpatients]);
        }

        // ---------- FETCH BED MANAGEMENT DATA ----------
        if ($action === 'fetch_bed_management') {
            $sql = "SELECT 
                        b.id AS bed_id,
                        b.bed_number,
                        b.bed_type,
                        b.status,
                        b.daily_rate,
                        b.is_active,
                        b.notes,
                        w.id AS ward_id,
                        w.name AS ward_name,
                        w.ward_type,
                        r.id AS room_id,
                        r.room_number,
                        p.id AS patient_id,
                        p.mr_number,
                        CONCAT(
                            COALESCE(p.first_name, ''),
                            ' ',
                            COALESCE(p.middle_name, ''),
                            ' ',
                            COALESCE(p.last_name, '')
                        ) AS patient_name,
                        a.id AS admission_id,
                        a.admission_number,
                        a.condition_status
                    FROM beds b
                    INNER JOIN wards w ON b.ward_id = w.id AND w.deleted_at IS NULL
                    INNER JOIN rooms r ON b.room_id = r.id AND r.deleted_at IS NULL
                    LEFT JOIN admissions a ON b.id = a.bed_id AND a.discharged_at IS NULL AND a.status = 'admitted' AND a.deleted_at IS NULL
                    LEFT JOIN patients p ON a.patient_id = p.id AND p.deleted_at IS NULL
                    WHERE b.deleted_at IS NULL";
            $params = [];
            if (!$is_global_admin && $branch_id > 0) {
                $sql .= " AND b.branch_id = :branch_id";
                $params[':branch_id'] = $branch_id;
            }
            $sql .= " ORDER BY w.name, r.room_number, b.bed_number";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $beds = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Clean up patient names
            foreach ($beds as &$bed) {
                if (!empty($bed['patient_name'])) {
                    $bed['patient_name'] = preg_replace('/\s+/', ' ', trim($bed['patient_name']));
                }
            }

            // Get ward summaries
            $wardSql = "SELECT 
                            w.id AS ward_id,
                            w.name AS ward_name,
                            w.ward_type,
                            w.total_beds,
                            COUNT(b.id) AS total_beds_count,
                            SUM(CASE WHEN b.status = 'occupied' THEN 1 ELSE 0 END) AS occupied_count,
                            SUM(CASE WHEN b.status = 'available' THEN 1 ELSE 0 END) AS available_count,
                            SUM(CASE WHEN b.status = 'maintenance' THEN 1 ELSE 0 END) AS maintenance_count,
                            SUM(CASE WHEN b.status = 'cleaning' THEN 1 ELSE 0 END) AS cleaning_count,
                            SUM(CASE WHEN b.status = 'reserved' THEN 1 ELSE 0 END) AS reserved_count
                        FROM wards w
                        LEFT JOIN beds b ON w.id = b.ward_id AND b.deleted_at IS NULL
                        WHERE w.deleted_at IS NULL";
            if (!$is_global_admin && $branch_id > 0) {
                $wardSql .= " AND w.branch_id = :branch_id";
                $params[':branch_id'] = $branch_id;
            }
            $wardSql .= " GROUP BY w.id, w.name, w.ward_type, w.total_beds
                        ORDER BY w.name";
            $wardStmt = $pdo->prepare($wardSql);
            $wardStmt->execute($params);
            $wards = $wardStmt->fetchAll(PDO::FETCH_ASSOC);

            jsonResponse(true, '', ['beds' => $beds, 'wards' => $wards]);
        }

        // ---------- FETCH PATIENT NOTES ----------
        if ($action === 'fetch_patient_notes') {
            $patient_id = (int)($_POST['patient_id'] ?? 0);
            $admission_id = (int)($_POST['admission_id'] ?? 0);
            
            if ($patient_id <= 0 && $admission_id <= 0) {
                jsonResponse(false, 'Missing patient or admission ID');
            }

            $notes = [];

            // Nursing Notes
            $sql = "SELECT 
                        nn.id,
                        nn.shift_type,
                        nn.progress_note,
                        nn.clinical_observations,
                        nn.created_at,
                        CONCAT(
                            COALESCE(u.first_name, ''),
                            ' ',
                            COALESCE(u.last_name, '')
                        ) AS nurse_name
                    FROM nursing_notes_records nn
                    LEFT JOIN users u ON nn.nurse_user_id = u.id
                    WHERE nn.admission_id = :admission_id
                      AND nn.deleted_at IS NULL
                    ORDER BY nn.created_at DESC
                    LIMIT 20";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':admission_id' => $admission_id]);
            $nursing_notes = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Admission Progress Notes (Doctor Notes)
            $sql = "SELECT 
                        apn.id,
                        apn.round_type,
                        apn.progress_note,
                        apn.clinical_assessment,
                        apn.plan_modifications,
                        apn.vitals_summary,
                        apn.created_at,
                        CONCAT(
                            COALESCE(u.first_name, ''),
                            ' ',
                            COALESCE(u.last_name, '')
                        ) AS doctor_name
                    FROM admission_progress_notes apn
                    LEFT JOIN users u ON apn.doctor_id = u.id
                    WHERE apn.admission_id = :admission_id
                      AND apn.deleted_at IS NULL
                    ORDER BY apn.created_at DESC
                    LIMIT 20";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':admission_id' => $admission_id]);
            $doctor_notes = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $notes = [
                'nursing_notes' => $nursing_notes,
                'doctor_notes' => $doctor_notes
            ];

            jsonResponse(true, '', ['notes' => $notes]);
        }

        // ---------- FETCH PENDING LAB SAMPLES ----------
        if ($action === 'fetch_pending_lab_samples') {
            $sql = "SELECT 
                        lo.id AS lab_order_id,
                        lo.order_number,
                        lo.priority,
                        lo.created_at,
                        p.id AS patient_id,
                        p.mr_number,
                        CONCAT(
                            COALESCE(p.first_name, ''),
                            ' ',
                            COALESCE(p.middle_name, ''),
                            ' ',
                            COALESCE(p.last_name, '')
                        ) AS patient_name,
                        loi.id AS item_id,
                        loi.test_name,
                        loi.sample_type,
                        loi.sample_barcode,
                        loi.status,
                        loi.collected_at,
                        CONCAT(
                            COALESCE(u.first_name, ''),
                            ' ',
                            COALESCE(u.last_name, '')
                        ) AS collected_by
                    FROM lab_order_items loi
                    INNER JOIN lab_orders lo ON loi.lab_order_id = lo.id AND lo.deleted_at IS NULL
                    INNER JOIN patients p ON lo.patient_id = p.id AND p.deleted_at IS NULL
                    LEFT JOIN users u ON loi.collected_by_user_id = u.id
                    WHERE loi.status IN ('sample_collected', 'ordered')
                      AND loi.deleted_at IS NULL";
            $params = [];
            if (!$is_global_admin && $branch_id > 0) {
                $sql .= " AND lo.branch_id = :branch_id";
                $params[':branch_id'] = $branch_id;
            }
            $sql .= " ORDER BY 
                        CASE loi.status WHEN 'ordered' THEN 0 ELSE 1 END,
                        lo.priority DESC,
                        loi.created_at ASC
                    LIMIT 50";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $samples = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Clean up patient names
            foreach ($samples as &$sample) {
                if (!empty($sample['patient_name'])) {
                    $sample['patient_name'] = preg_replace('/\s+/', ' ', trim($sample['patient_name']));
                }
            }

            jsonResponse(true, '', ['samples' => $samples]);
        }

        // ---------- ASSIGN BED ----------
        if ($action === 'assign_bed') {
            $admission_id = (int)($_POST['admission_id'] ?? 0);
            $bed_id = (int)($_POST['bed_id'] ?? 0);
            
            if ($admission_id <= 0 || $bed_id <= 0) {
                jsonResponse(false, 'Invalid admission or bed ID');
            }

            // Check if bed is available
            $stmt = $pdo->prepare("SELECT status FROM beds WHERE id = ? AND deleted_at IS NULL");
            $stmt->execute([$bed_id]);
            $bed = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$bed || $bed['status'] !== 'available') {
                jsonResponse(false, 'Bed is not available');
            }

            // Update admission with bed
            $stmt = $pdo->prepare("UPDATE admissions SET bed_id = ?, updated_at = NOW() WHERE id = ? AND deleted_at IS NULL");
            $stmt->execute([$bed_id, $admission_id]);

            // Update bed status to occupied
            $stmt = $pdo->prepare("UPDATE beds SET status = 'occupied', updated_at = NOW() WHERE id = ?");
            $stmt->execute([$bed_id]);

            jsonResponse(true, 'Bed assigned successfully');
        }

        // ---------- UNASSIGN BED ----------
        if ($action === 'unassign_bed') {
            $admission_id = (int)($_POST['admission_id'] ?? 0);
            $bed_id = (int)($_POST['bed_id'] ?? 0);
            
            if ($admission_id <= 0 || $bed_id <= 0) {
                jsonResponse(false, 'Invalid admission or bed ID');
            }

            // Update admission remove bed
            $stmt = $pdo->prepare("UPDATE admissions SET bed_id = NULL, updated_at = NOW() WHERE id = ? AND deleted_at IS NULL");
            $stmt->execute([$admission_id]);

            // Update bed status to available
            $stmt = $pdo->prepare("UPDATE beds SET status = 'available', updated_at = NOW() WHERE id = ?");
            $stmt->execute([$bed_id]);

            jsonResponse(true, 'Bed unassigned successfully');
        }

        // ---------- UPDATE BED STATUS ----------
        if ($action === 'update_bed_status') {
            $bed_id = (int)($_POST['bed_id'] ?? 0);
            $status = $_POST['status'] ?? '';
            
            if ($bed_id <= 0 || empty($status)) {
                jsonResponse(false, 'Invalid bed ID or status');
            }

            $valid_statuses = ['available', 'occupied', 'maintenance', 'cleaning', 'reserved'];
            if (!in_array($status, $valid_statuses)) {
                jsonResponse(false, 'Invalid status');
            }

            $stmt = $pdo->prepare("UPDATE beds SET status = ?, updated_at = NOW() WHERE id = ? AND deleted_at IS NULL");
            $stmt->execute([$status, $bed_id]);

            jsonResponse(true, 'Bed status updated successfully');
        }

        jsonResponse(false, 'Unsupported action');
    } catch (PDOException $e) {
        error_log('Nursing AJAX error: ' . $e->getMessage());
        jsonResponse(false, 'Database error occurred');
    }
    exit;
}

// ----------------------------------------------------------------------
// INITIAL PAGE DATA (branch isolated)
// ----------------------------------------------------------------------
$initial_metrics = [
    'triage_queue' => 0,
    'occupied_beds' => 0,
    'available_beds' => 0,
    'active_inpatients' => 0,
    'pending_phlebotomy' => 0,
    'critical_patients' => 0,
    'pending_lab_samples' => 0
];
$initial_triage_queue = [];
$initial_inpatients = [];
$initial_beds = [];
$initial_wards = [];
$initial_pending_samples = [];

try {
    // Triage queue count
    $sql = "SELECT COUNT(DISTINCT pv.id)
            FROM patient_visits pv
            LEFT JOIN triage_records tr ON tr.visit_id = pv.id AND tr.deleted_at IS NULL
            WHERE pv.status IN ('checked_in', 'pending', 'in_triage')
              AND pv.deleted_at IS NULL
              AND (tr.id IS NULL OR tr.disposition IS NULL OR tr.disposition = '')";
    $params = [];
    if (!$is_global_admin && $branch_id > 0) {
        $sql .= " AND pv.branch_id = :branch_id";
        $params[':branch_id'] = $branch_id;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $initial_metrics['triage_queue'] = (int)$stmt->fetchColumn();

    // Occupied beds
    $sql = "SELECT COUNT(*) FROM beds WHERE status = 'occupied' AND deleted_at IS NULL";
    $params = [];
    if (!$is_global_admin && $branch_id > 0) {
        $sql .= " AND branch_id = :branch_id";
        $params[':branch_id'] = $branch_id;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $initial_metrics['occupied_beds'] = (int)$stmt->fetchColumn();

    // Available beds
    $sql = "SELECT COUNT(*) FROM beds WHERE status = 'available' AND deleted_at IS NULL";
    $params = [];
    if (!$is_global_admin && $branch_id > 0) {
        $sql .= " AND branch_id = :branch_id";
        $params[':branch_id'] = $branch_id;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $initial_metrics['available_beds'] = (int)$stmt->fetchColumn();

    // Active inpatients
    $sql = "SELECT COUNT(*) FROM admissions WHERE discharged_at IS NULL AND status = 'admitted' AND deleted_at IS NULL";
    $params = [];
    if (!$is_global_admin && $branch_id > 0) {
        $sql .= " AND branch_id = :branch_id";
        $params[':branch_id'] = $branch_id;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $initial_metrics['active_inpatients'] = (int)$stmt->fetchColumn();

    // Pending phlebotomy
    $sql = "SELECT COUNT(DISTINCT id) FROM lab_orders WHERE status IN ('pending_payment', 'pending', 'approved') AND deleted_at IS NULL";
    $params = [];
    if (!$is_global_admin && $branch_id > 0) {
        $sql .= " AND branch_id = :branch_id";
        $params[':branch_id'] = $branch_id;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $initial_metrics['pending_phlebotomy'] = (int)$stmt->fetchColumn();

    // Critical patients
    $sql = "SELECT COUNT(*) FROM admissions WHERE condition_status IN ('critical', 'serious') AND status = 'admitted' AND deleted_at IS NULL";
    $params = [];
    if (!$is_global_admin && $branch_id > 0) {
        $sql .= " AND branch_id = :branch_id";
        $params[':branch_id'] = $branch_id;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $initial_metrics['critical_patients'] = (int)$stmt->fetchColumn();

    // Pending lab samples
    $sql = "SELECT COUNT(DISTINCT loi.id)
            FROM lab_order_items loi
            INNER JOIN lab_orders lo ON loi.lab_order_id = lo.id AND lo.deleted_at IS NULL
            WHERE loi.status IN ('sample_collected', 'ordered')
              AND loi.deleted_at IS NULL";
    $params = [];
    if (!$is_global_admin && $branch_id > 0) {
        $sql .= " AND lo.branch_id = :branch_id";
        $params[':branch_id'] = $branch_id;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $initial_metrics['pending_lab_samples'] = (int)$stmt->fetchColumn();

    // Triage queue full list
    $sql = "SELECT
                pv.id AS visit_id,
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
                pv.created_at AS checkin_time,
                pv.visit_type,
                COALESCE(tr.priority, 'green') AS priority,
                a.chief_complaint,
                tr.id AS triage_id,
                tr.disposition
            FROM patient_visits pv
            INNER JOIN patients p ON pv.patient_id = p.id AND p.deleted_at IS NULL AND p.is_active = 1
            LEFT JOIN triage_records tr ON pv.id = tr.visit_id AND tr.deleted_at IS NULL
            LEFT JOIN appointments a ON pv.id = a.visit_id AND a.deleted_at IS NULL
            WHERE pv.status IN ('checked_in', 'pending', 'in_triage')
              AND pv.deleted_at IS NULL
              AND (tr.id IS NULL OR tr.disposition IS NULL OR tr.disposition = '')
            ORDER BY 
                CASE WHEN tr.id IS NULL THEN 0 ELSE 1 END,
                FIELD(COALESCE(tr.priority, 'green'), 'red', 'yellow', 'green', 'blue'), 
                pv.created_at ASC";
    $params = [];
    if (!$is_global_admin && $branch_id > 0) {
        $sql .= " AND pv.branch_id = :branch_id";
        $params[':branch_id'] = $branch_id;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $num = 1;
    foreach ($rows as $r) {
        $patient_name = preg_replace('/\s+/', ' ', trim($r['patient_name'] ?? 'Unknown Patient'));
        $age = 'N/A';
        if (!empty($r['date_of_birth'])) {
            $dob = new DateTime($r['date_of_birth']);
            $now = new DateTime();
            $age = $dob->diff($now)->y;
        }
        $status_label = 'Waiting';
        if (!empty($r['triage_id']) && empty($r['disposition'])) {
            $status_label = 'In Triage';
        }
        $initial_triage_queue[] = [
            'queue_number' => 'Q' . str_pad($num++, 3, '0', STR_PAD_LEFT),
            'visit_id'     => $r['visit_id'],
            'patient_id'   => $r['patient_id'],
            'patient_name' => $patient_name,
            'mr_number'    => $r['mr_number'],
            'gender'       => $r['gender'],
            'age'          => $age,
            'priority'     => $r['priority'] ?: 'green',
            'chief_complaint' => $r['chief_complaint'] ?? '',
            'checkin_time' => date('H:i', strtotime($r['checkin_time'])),
            'status_label' => $status_label,
            'triage_id'    => $r['triage_id']
        ];
    }

    // Active inpatients full list
    $sql = "SELECT
                a.id AS admission_id,
                a.admission_number,
                a.admitted_at,
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
                b.id AS bed_id,
                b.bed_number AS bed_code,
                b.bed_type,
                w.id AS ward_id,
                w.name AS ward_name,
                CONCAT(
                    COALESCE(d.first_name, ''),
                    ' ',
                    COALESCE(d.middle_name, ''),
                    ' ',
                    COALESCE(d.last_name, '')
                ) AS doctor_name,
                a.primary_diagnosis,
                a.admission_type,
                a.admission_notes
            FROM admissions a
            INNER JOIN patients p ON a.patient_id = p.id AND p.deleted_at IS NULL AND p.is_active = 1
            LEFT JOIN beds b ON a.bed_id = b.id AND b.deleted_at IS NULL
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
        
        $age = 'N/A';
        if (!empty($r['date_of_birth'])) {
            $dob = new DateTime($r['date_of_birth']);
            $now = new DateTime();
            $age = $dob->diff($now)->y;
        }
        $initial_inpatients[] = [
            'admission_id'     => $r['admission_id'],
            'admission_number' => $r['admission_number'],
            'patient_id'       => $r['patient_id'],
            'patient_name'     => $patient_name,
            'mr_number'        => $r['mr_number'],
            'age'              => $age,
            'gender'           => $r['gender'],
            'bed_id'           => $r['bed_id'],
            'bed_code'         => $r['bed_code'] ?: 'Not Assigned',
            'bed_type'         => $r['bed_type'] ?? '',
            'ward_id'          => $r['ward_id'],
            'ward_name'        => $r['ward_name'] ?? '',
            'doctor_name'      => $doctor_name,
            'condition_status' => $r['condition_status'] ?? 'stable',
            'primary_diagnosis'=> $r['primary_diagnosis'] ?? '',
            'admission_type'   => $r['admission_type'] ?? 'elective',
            'admission_notes'  => $r['admission_notes'] ?? '',
            'admitted_date'    => date('d M Y', strtotime($r['admitted_at']))
        ];
    }

    // Bed Management Data
    $sql = "SELECT 
                b.id AS bed_id,
                b.bed_number,
                b.bed_type,
                b.status,
                b.daily_rate,
                b.is_active,
                b.notes,
                w.id AS ward_id,
                w.name AS ward_name,
                w.ward_type,
                r.id AS room_id,
                r.room_number,
                p.id AS patient_id,
                p.mr_number,
                CONCAT(
                    COALESCE(p.first_name, ''),
                    ' ',
                    COALESCE(p.middle_name, ''),
                    ' ',
                    COALESCE(p.last_name, '')
                ) AS patient_name,
                a.id AS admission_id,
                a.admission_number,
                a.condition_status
            FROM beds b
            INNER JOIN wards w ON b.ward_id = w.id AND w.deleted_at IS NULL
            INNER JOIN rooms r ON b.room_id = r.id AND r.deleted_at IS NULL
            LEFT JOIN admissions a ON b.id = a.bed_id AND a.discharged_at IS NULL AND a.status = 'admitted' AND a.deleted_at IS NULL
            LEFT JOIN patients p ON a.patient_id = p.id AND p.deleted_at IS NULL
            WHERE b.deleted_at IS NULL";
    $params = [];
    if (!$is_global_admin && $branch_id > 0) {
        $sql .= " AND b.branch_id = :branch_id";
        $params[':branch_id'] = $branch_id;
    }
    $sql .= " ORDER BY w.name, r.room_number, b.bed_number";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $initial_beds = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($initial_beds as &$bed) {
        if (!empty($bed['patient_name'])) {
            $bed['patient_name'] = preg_replace('/\s+/', ' ', trim($bed['patient_name']));
        }
    }

    // Ward summaries
    $wardSql = "SELECT 
                    w.id AS ward_id,
                    w.name AS ward_name,
                    w.ward_type,
                    w.total_beds,
                    COUNT(b.id) AS total_beds_count,
                    SUM(CASE WHEN b.status = 'occupied' THEN 1 ELSE 0 END) AS occupied_count,
                    SUM(CASE WHEN b.status = 'available' THEN 1 ELSE 0 END) AS available_count,
                    SUM(CASE WHEN b.status = 'maintenance' THEN 1 ELSE 0 END) AS maintenance_count,
                    SUM(CASE WHEN b.status = 'cleaning' THEN 1 ELSE 0 END) AS cleaning_count,
                    SUM(CASE WHEN b.status = 'reserved' THEN 1 ELSE 0 END) AS reserved_count
                FROM wards w
                LEFT JOIN beds b ON w.id = b.ward_id AND b.deleted_at IS NULL
                WHERE w.deleted_at IS NULL";
    $params = [];
    if (!$is_global_admin && $branch_id > 0) {
        $wardSql .= " AND w.branch_id = :branch_id";
        $params[':branch_id'] = $branch_id;
    }
    $wardSql .= " GROUP BY w.id, w.name, w.ward_type, w.total_beds
                ORDER BY w.name";
    $wardStmt = $pdo->prepare($wardSql);
    $wardStmt->execute($params);
    $initial_wards = $wardStmt->fetchAll(PDO::FETCH_ASSOC);

    // Pending Lab Samples
    $sql = "SELECT 
                lo.id AS lab_order_id,
                lo.order_number,
                lo.priority,
                lo.created_at,
                p.id AS patient_id,
                p.mr_number,
                CONCAT(
                    COALESCE(p.first_name, ''),
                    ' ',
                    COALESCE(p.middle_name, ''),
                    ' ',
                    COALESCE(p.last_name, '')
                ) AS patient_name,
                loi.id AS item_id,
                loi.test_name,
                loi.sample_type,
                loi.sample_barcode,
                loi.status,
                loi.collected_at,
                CONCAT(
                    COALESCE(u.first_name, ''),
                    ' ',
                    COALESCE(u.last_name, '')
                ) AS collected_by
            FROM lab_order_items loi
            INNER JOIN lab_orders lo ON loi.lab_order_id = lo.id AND lo.deleted_at IS NULL
            INNER JOIN patients p ON lo.patient_id = p.id AND p.deleted_at IS NULL
            LEFT JOIN users u ON loi.collected_by_user_id = u.id
            WHERE loi.status IN ('sample_collected', 'ordered')
              AND loi.deleted_at IS NULL";
    $params = [];
    if (!$is_global_admin && $branch_id > 0) {
        $sql .= " AND lo.branch_id = :branch_id";
        $params[':branch_id'] = $branch_id;
    }
    $sql .= " ORDER BY 
                CASE loi.status WHEN 'ordered' THEN 0 ELSE 1 END,
                lo.priority DESC,
                loi.created_at ASC
            LIMIT 50";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $initial_pending_samples = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($initial_pending_samples as &$sample) {
        if (!empty($sample['patient_name'])) {
            $sample['patient_name'] = preg_replace('/\s+/', ' ', trim($sample['patient_name']));
        }
    }

} catch (PDOException $e) {
    error_log('Dashboard initial data error: ' . $e->getMessage());
}

$current_date = date('l, F j, Y');
$current_time = date('g:i A');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include('assets/inc/head.php'); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nursing Dashboard | HMS ERP</title>
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
            --critical: #dc3545;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: #f5f7fb; overflow-x: hidden; }
        .content-page { margin-left: 260px; margin-top: 70px; padding: 20px 24px; transition: margin-left 0.3s; min-height: calc(100vh - 70px); }
        body.erp-sidebar-collapsed .content-page { margin-left: 80px; }
        @media (max-width: 768px) { .content-page { margin-left: 0 !important; padding: 16px; } }
        .welcome-header { background: linear-gradient(135deg, var(--primary-dark) 0%, var(--primary) 100%); border-radius: 24px; padding: 16px 24px; color: white; margin-bottom: 24px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; }
        .welcome-title { font-size: 22px; font-weight: 700; margin: 0; }
        .welcome-subtitle { font-size: 14px; opacity: 0.85; }
        .time-badge { background: rgba(255,255,255,0.15); padding: 6px 14px; border-radius: 12px; font-size: 13px; display: inline-flex; align-items: center; gap: 8px; }
        .hud-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 28px; }
        .hud-card { background: white; border-radius: 20px; padding: 18px 20px; transition: all 0.3s; border: 1px solid var(--gray-light); cursor: pointer; position: relative; }
        .hud-card:hover { transform: translateY(-3px); box-shadow: 0 10px 30px rgba(0,0,0,0.08); }
        .hud-card::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 4px; border-radius: 20px 20px 0 0; }
        .hud-card.primary::before { background: linear-gradient(90deg, var(--primary-light), var(--primary)); }
        .hud-card.success::before { background: linear-gradient(90deg, var(--success), #059669); }
        .hud-card.warning::before { background: linear-gradient(90deg, var(--warning), #d97706); }
        .hud-card.info::before { background: linear-gradient(90deg, var(--info), #0891b2); }
        .hud-card.danger::before { background: linear-gradient(90deg, var(--danger), #c0392b); }
        .hud-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px; }
        .hud-icon { width: 44px; height: 44px; border-radius: 14px; display: flex; align-items: center; justify-content: center; font-size: 20px; }
        .hud-icon.primary { background: rgba(67,97,238,0.1); color: var(--primary); }
        .hud-icon.success { background: rgba(6,214,160,0.1); color: var(--success); }
        .hud-icon.warning { background: rgba(249,199,79,0.1); color: var(--warning); }
        .hud-icon.info { background: rgba(76,201,240,0.1); color: var(--info); }
        .hud-icon.danger { background: rgba(239,71,111,0.1); color: var(--danger); }
        .hud-value { font-size: 28px; font-weight: 800; color: var(--dark); line-height: 1.2; }
        .hud-label { font-size: 12px; color: var(--gray); font-weight: 500; }
        .quick-queue-card { background: white; border-radius: 20px; border: 1px solid var(--gray-light); overflow: hidden; margin-bottom: 24px; }
        .quick-queue-header { background: #f8fafc; padding: 12px 16px; border-bottom: 1px solid var(--gray-light); font-weight: 600; font-size: 16px; display: flex; justify-content: space-between; align-items: center; }
        .quick-queue-list { max-height: 300px; overflow-y: auto; }
        .quick-queue-item { padding: 10px 16px; border-bottom: 1px solid #f0f0f0; display: flex; justify-content: space-between; align-items: center; }
        .quick-queue-item:last-child { border-bottom: none; }
        .quick-queue-item:hover { background: #f8fafc; }
        .patient-info h6 { margin: 0 0 2px; font-size: 14px; font-weight: 600; }
        .patient-info p { margin: 0; font-size: 12px; color: var(--gray); }
        .badge-priority { padding: 2px 10px; border-radius: 20px; font-size: 10px; font-weight: 600; text-transform: uppercase; }
        .badge-red { background: #fee2e2; color: var(--danger); }
        .badge-yellow { background: #fef3c7; color: #d97706; }
        .badge-green { background: #d1fae5; color: var(--success); }
        .badge-blue { background: #dbeafe; color: var(--info); }
        .badge-in-triage { background: #ede9fe; color: #7c3aed; }
        .action-icon { color: var(--primary); margin-left: 8px; font-size: 14px; cursor: pointer; display: inline-block; }
        .action-icon:hover { color: var(--primary-dark); transform: scale(1.1); }
        .full-table-section { background: white; border-radius: 20px; border: 1px solid var(--gray-light); overflow: hidden; margin-bottom: 28px; }
        .section-header { padding: 16px 20px; background: #f8fafc; border-bottom: 1px solid var(--gray-light); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; }
        .section-header h3 { margin: 0; font-size: 18px; font-weight: 700; }
        .data-table { width: 100%; border-collapse: collapse; }
        .data-table th { padding: 10px 16px; font-size: 11px; font-weight: 600; color: var(--gray); text-transform: uppercase; background: #f8fafc; border-bottom: 1px solid var(--gray-light); text-align: left; }
        .data-table td { padding: 10px 16px; font-size: 13px; border-bottom: 1px solid #f3f4f6; vertical-align: middle; }
        .data-table tr:hover { background: #f8fafc; }
        .btn-sm-icon { padding: 4px 10px; border-radius: 8px; font-size: 12px; margin: 0 2px; display: inline-block; border: 1px solid var(--primary); color: var(--primary); background: white; text-decoration: none; }
        .btn-sm-icon:hover { background: var(--primary); color: white; }
        .status-badge { display: inline-block; padding: 2px 10px; border-radius: 20px; font-size: 11px; font-weight: 600; }
        .status-red { background: #fee2e2; color: var(--danger); }
        .status-yellow { background: #fef3c7; color: #d97706; }
        .status-green { background: #d1fae5; color: var(--success); }
        .status-blue { background: #dbeafe; color: var(--info); }
        .status-critical { background: #fee2e2; color: #dc3545; animation: pulseCritical 2s infinite; }
        @keyframes pulseCritical { 0%, 100% { opacity: 1; } 50% { opacity: 0.6; } }
        .bed-status-available { background: #d1fae5; color: #065f46; }
        .bed-status-occupied { background: #fee2e2; color: #dc2626; }
        .bed-status-maintenance { background: #fef3c7; color: #92400e; }
        .bed-status-cleaning { background: #dbeafe; color: #1e40af; }
        .bed-status-reserved { background: #ede9fe; color: #5b21b6; }
        .toast-notification { position: fixed; bottom: 20px; right: 20px; background: white; border-radius: 12px; padding: 12px 20px; box-shadow: 0 10px 25px rgba(0,0,0,0.1); z-index: 1100; animation: slideIn 0.3s ease; border-left: 4px solid var(--success); max-width: 400px; }
        .toast-error { border-left-color: var(--danger); }
        @keyframes slideIn { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
        .loading-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(255,255,255,0.7); z-index: 2000; display: none; align-items: center; justify-content: center; }
        .loading-overlay.active { display: flex; }
        .spinner { width: 40px; height: 40px; border: 3px solid var(--gray-light); border-top-color: var(--primary); border-radius: 50%; animation: spin 0.8s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }
        .empty-state { text-align: center; padding: 30px; color: var(--gray); }
        .empty-state i { font-size: 40px; margin-bottom: 12px; opacity: 0.3; }
        @media (max-width: 1200px) { .hud-grid { grid-template-columns: repeat(2, 1fr); } }
        @media (max-width: 768px) { .hud-grid { grid-template-columns: 1fr; } }
        .scrollable-table { max-height: 400px; overflow-y: auto; }
        .scrollable-table::-webkit-scrollbar { width: 4px; }
        .scrollable-table::-webkit-scrollbar-track { background: #f1f1f1; border-radius: 4px; }
        .scrollable-table::-webkit-scrollbar-thumb { background: var(--primary); border-radius: 4px; }
        .bed-status-dot { width: 10px; height: 10px; border-radius: 50%; display: inline-block; margin-right: 6px; }
        .bed-status-dot.available { background: var(--success); }
        .bed-status-dot.occupied { background: var(--danger); }
        .bed-status-dot.maintenance { background: var(--warning); }
        .bed-status-dot.cleaning { background: var(--info); }
        .bed-status-dot.reserved { background: #7c3aed; }
        .sample-status-ordered { background: #fef3c7; color: #92400e; }
        .sample-status-collected { background: #dbeafe; color: #1e40af; }
        .sample-status-processing { background: #ede9fe; color: #5b21b6; }
        .note-entry { border-left: 3px solid var(--primary); padding-left: 12px; margin-bottom: 12px; }
        .note-entry.nursing { border-left-color: var(--success); }
        .note-entry.doctor { border-left-color: var(--primary); }
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
                    <div class="welcome-title">Nursing Dashboard, <?php echo escapeHtml($user_name); ?> 🩺</div>
                    <div class="welcome-subtitle">Clinical overview, triage workflow & inpatient management</div>
                </div>
                <div class="time-badge">
                    <i class="far fa-calendar-alt"></i> <?php echo escapeHtml($current_date); ?>
                    <i class="far fa-clock ms-2"></i> <?php echo escapeHtml($current_time); ?>
                </div>
            </div>

            <!-- HUD Metrics -->
            <div class="hud-grid">
                <div class="hud-card primary" onclick="window.location.href='nursing_triage.php'">
                    <div class="hud-header">
                        <div><div class="hud-value" id="hudTriageQueue"><?php echo $initial_metrics['triage_queue']; ?></div><div class="hud-label">Triage Queue</div></div>
                        <div class="hud-icon primary"><i class="fas fa-list-ul"></i></div>
                    </div>
                </div>
                <div class="hud-card success" onclick="window.location.href='#bedManagementSection'">
                    <div class="hud-header">
                        <div>
                            <div class="hud-value"><span id="hudOccupiedBeds"><?php echo $initial_metrics['occupied_beds']; ?></span>/<span id="hudAvailableBeds"><?php echo $initial_metrics['available_beds']; ?></span></div>
                            <div class="hud-label">Beds (Occ/Avail)</div>
                        </div>
                        <div class="hud-icon success"><i class="fas fa-bed"></i></div>
                    </div>
                </div>
                <div class="hud-card warning" onclick="window.location.href='inpatient_list.php'">
                    <div class="hud-header">
                        <div><div class="hud-value" id="hudActiveInpatients"><?php echo $initial_metrics['active_inpatients']; ?></div><div class="hud-label">Active Inpatients</div></div>
                        <div class="hud-icon warning"><i class="fas fa-procedures"></i></div>
                    </div>
                </div>
                <div class="hud-card info" onclick="window.location.href='sample_collection.php'">
                    <div class="hud-header">
                        <div><div class="hud-value" id="hudPendingPhlebotomy"><?php echo $initial_metrics['pending_phlebotomy']; ?></div><div class="hud-label">Pending Phlebotomy</div></div>
                        <div class="hud-icon info"><i class="fas fa-vial"></i></div>
                    </div>
                </div>
            </div>

            <!-- Second Row HUD -->
            <div class="hud-grid" style="margin-top: -12px;">
                <div class="hud-card danger" onclick="window.location.href='critical_patients.php'">
                    <div class="hud-header">
                        <div><div class="hud-value" id="hudCriticalPatients"><?php echo $initial_metrics['critical_patients']; ?></div><div class="hud-label">Critical Patients</div></div>
                        <div class="hud-icon danger"><i class="fas fa-exclamation-triangle"></i></div>
                    </div>
                </div>
                <div class="hud-card info" onclick="window.location.href='lab_samples.php'">
                    <div class="hud-header">
                        <div><div class="hud-value" id="hudPendingLabSamples"><?php echo $initial_metrics['pending_lab_samples']; ?></div><div class="hud-label">Pending Lab Samples</div></div>
                        <div class="hud-icon info"><i class="fas fa-flask"></i></div>
                    </div>
                </div>
                <div class="hud-card" style="background: linear-gradient(135deg, #f8fafc, #f1f5f9); cursor: default; border: 1px dashed var(--gray-light);" onclick="window.location.href='nursing_notes.php'">
                    <div class="hud-header">
                        <div><div class="hud-value"><i class="fas fa-notes-medical" style="font-size:24px; color:var(--primary);"></i></div><div class="hud-label">Patient Notes</div></div>
                        <div class="hud-icon" style="background: rgba(67,97,238,0.1); color: var(--primary);"><i class="fas fa-pen"></i></div>
                    </div>
                </div>
                <div class="hud-card" style="background: linear-gradient(135deg, #f8fafc, #f1f5f9); cursor: default; border: 1px dashed var(--gray-light);" onclick="window.location.href='sample_collection.php'">
                    <div class="hud-header">
                        <div><div class="hud-value"><i class="fas fa-syringe" style="font-size:24px; color:var(--success);"></i></div><div class="hud-label">Sample Collection</div></div>
                        <div class="hud-icon" style="background: rgba(6,214,160,0.1); color: var(--success);"><i class="fas fa-flask"></i></div>
                    </div>
                </div>
            </div>

            <div class="row">
                <!-- LEFT PANEL: Quick Queues -->
                <div class="col-lg-4">
                    <!-- Outpatient Triage Quick List -->
                    <div class="quick-queue-card">
                        <div class="quick-queue-header">
                            <span><i class="fas fa-stethoscope me-2"></i> Outpatient Triage Queue</span>
                            <span class="badge bg-secondary" id="quickTriageCount"><?php echo count($initial_triage_queue); ?></span>
                        </div>
                        <div class="quick-queue-list" id="quickTriageList">
                            <?php if (!empty($initial_triage_queue)): ?>
                                <?php foreach (array_slice($initial_triage_queue, 0, 5) as $item): ?>
                                <div class="quick-queue-item">
                                    <div class="patient-info">
                                        <h6><?php echo escapeHtml($item['patient_name']); ?></h6>
                                        <p>MRN: <?php echo escapeHtml($item['mr_number']); ?> • <?php echo $item['age']; ?> yrs</p>
                                    </div>
                                    <div>
                                        <span class="badge-priority badge-<?php echo $item['priority']; ?>"><?php echo ucfirst($item['priority']); ?></span>
                                        <?php if ($item['status_label'] === 'In Triage'): ?>
                                            <span class="badge-priority badge-in-triage">In Triage</span>
                                        <?php endif; ?>
                                        <a href="nursing_triage.php?visit_id=<?php echo $item['visit_id']; ?>" class="action-icon" title="Start Triage"><i class="fas fa-heartbeat"></i></a>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="empty-state">
                                    <i class="fas fa-check-circle"></i>
                                    <p>No pending triage</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Active Inpatients Quick List -->
                    <div class="quick-queue-card">
                        <div class="quick-queue-header">
                            <span><i class="fas fa-hospital-user me-2"></i> Active Inpatients</span>
                            <span class="badge bg-secondary" id="quickInpatientCount"><?php echo count($initial_inpatients); ?></span>
                        </div>
                        <div class="quick-queue-list" id="quickInpatientList">
                            <?php if (!empty($initial_inpatients)): ?>
                                <?php foreach (array_slice($initial_inpatients, 0, 5) as $item): ?>
                                <div class="quick-queue-item">
                                    <div class="patient-info">
                                        <h6><?php echo escapeHtml($item['patient_name']); ?></h6>
                                        <p><?php echo escapeHtml($item['bed_code']); ?> • <?php echo escapeHtml($item['doctor_name']); ?></p>
                                    </div>
                                    <div>
                                        <?php if ($item['condition_status'] === 'critical' || $item['condition_status'] === 'serious'): ?>
                                            <span class="badge-priority badge-red" title="Critical Condition">⚠️</span>
                                        <?php endif; ?>
                                        <a href="ward_nursing_hub.php?admission_id=<?php echo $item['admission_id']; ?>" class="action-icon" title="Bed Management"><i class="fas fa-bed"></i></a>
                                        <a href="medication_administration.php?admission_id=<?php echo $item['admission_id']; ?>" class="action-icon" title="Medication Administration"><i class="fas fa-capsules"></i></a>
                                        <a href="nursing_notes.php?admission_id=<?php echo $item['admission_id']; ?>" class="action-icon" title="Nursing Notes"><i class="fas fa-notes-medical"></i></a>
                                        <a href="#" class="action-icon" onclick="viewPatientNotes(<?php echo $item['admission_id']; ?>, <?php echo $item['patient_id']; ?>)" title="View All Notes"><i class="fas fa-file-alt"></i></a>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="empty-state">
                                    <i class="fas fa-user-md"></i>
                                    <p>No active inpatients</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Pending Lab Samples Quick List -->
                    <div class="quick-queue-card">
                        <div class="quick-queue-header">
                            <span><i class="fas fa-flask me-2"></i> Pending Lab Samples</span>
                            <span class="badge bg-secondary" id="quickSampleCount"><?php echo count($initial_pending_samples); ?></span>
                        </div>
                        <div class="quick-queue-list" id="quickSampleList">
                            <?php if (!empty($initial_pending_samples)): ?>
                                <?php foreach (array_slice($initial_pending_samples, 0, 5) as $sample): ?>
                                <div class="quick-queue-item">
                                    <div class="patient-info">
                                        <h6><?php echo escapeHtml($sample['patient_name']); ?></h6>
                                        <p><?php echo escapeHtml($sample['test_name']); ?> • <?php echo escapeHtml($sample['sample_type'] ?? 'N/A'); ?></p>
                                    </div>
                                    <div>
                                        <span class="badge-priority <?php echo $sample['status'] === 'ordered' ? 'badge-yellow' : 'badge-blue'; ?>">
                                            <?php echo ucfirst(str_replace('_', ' ', $sample['status'])); ?>
                                        </span>
                                        <?php if ($sample['status'] === 'sample_collected'): ?>
                                            <span class="badge-priority badge-green">Collected</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="empty-state">
                                    <i class="fas fa-check-circle"></i>
                                    <p>No pending lab samples</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- RIGHT PANEL: Full Tables -->
                <div class="col-lg-8">
                    <!-- Full Outpatient Triage Queue Table -->
                    <div class="full-table-section">
                        <div class="section-header">
                            <h3><i class="fas fa-stethoscope me-2"></i> Outpatient Triage Queue (Full List)</h3>
                            <div>
                                <button class="btn btn-sm btn-outline-primary" onclick="refreshData()"><i class="fas fa-sync-alt"></i> Refresh</button>
                                <button class="btn btn-sm btn-primary" onclick="window.location.href='nursing_triage.php'"><i class="fas fa-plus"></i> New Triage</button>
                            </div>
                        </div>
                        <div class="scrollable-table">
                            <table class="data-table" id="triageFullTable">
                                <thead>
                                    <tr><th>Queue #</th><th>Patient</th><th>MRN</th><th>Age/Gender</th><th>Complaint</th><th>Priority</th><th>Status</th><th>Action</th></tr>
                                </thead>
                                <tbody id="triageFullBody">
                                    <?php if (!empty($initial_triage_queue)): ?>
                                        <?php foreach ($initial_triage_queue as $row): ?>
                                        <tr>
                                            <td><?php echo escapeHtml($row['queue_number']); ?></td>
                                            <td><strong><?php echo escapeHtml($row['patient_name']); ?></strong></td>
                                            <td><?php echo escapeHtml($row['mr_number']); ?></td>
                                            <td><?php echo $row['age']; ?> yrs / <?php echo ucfirst($row['gender'] ?? 'N/A'); ?></td>
                                            <td><?php echo escapeHtml(substr($row['chief_complaint'] ?? '', 0, 30)); ?></td>
                                            <td><span class="status-badge status-<?php echo $row['priority']; ?>"><?php echo ucfirst($row['priority']); ?></span></td>
                                            <td>
                                                <?php if ($row['status_label'] === 'In Triage'): ?>
                                                    <span class="badge-priority badge-in-triage">In Triage</span>
                                                <?php else: ?>
                                                    <span class="badge-priority badge-green">Waiting</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <a href="nursing_triage.php?visit_id=<?php echo $row['visit_id']; ?>" class="btn-sm-icon"><i class="fas fa-heartbeat"></i> Triage</a>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr><td colspan="8" class="text-center text-muted py-3">No patients awaiting triage</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Full Active Inpatients Table -->
                    <div class="full-table-section">
                        <div class="section-header">
                            <h3><i class="fas fa-hospital-user me-2"></i> Active Inpatients (Full Roster)</h3>
                            <span class="text-muted small">Total: <?php echo count($initial_inpatients); ?></span>
                        </div>
                        <div class="scrollable-table">
                            <table class="data-table" id="inpatientFullTable">
                                <thead>
                                    <tr><th>Admission</th><th>Patient</th><th>MRN</th><th>Age/Gender</th><th>Bed / Ward</th><th>Doctor</th><th>Status</th><th>Actions</th></tr>
                                </thead>
                                <tbody id="inpatientFullBody">
                                    <?php if (!empty($initial_inpatients)): ?>
                                        <?php foreach ($initial_inpatients as $row): ?>
                                        <tr>
                                            <td><?php echo escapeHtml($row['admission_number']); ?></td>
                                            <td><strong><?php echo escapeHtml($row['patient_name']); ?></strong></td>
                                            <td><?php echo escapeHtml($row['mr_number']); ?></td>
                                            <td><?php echo $row['age']; ?> yrs / <?php echo ucfirst($row['gender'] ?? 'N/A'); ?></td>
                                            <td><?php echo escapeHtml($row['bed_code']); ?><br><small class="text-muted"><?php echo escapeHtml($row['ward_name']); ?></small></td>
                                            <td>Dr. <?php echo escapeHtml($row['doctor_name']); ?></td>
                                            <td>
                                                <?php
                                                $statusClass = 'status-green';
                                                if ($row['condition_status'] === 'critical' || $row['condition_status'] === 'serious') {
                                                    $statusClass = 'status-critical';
                                                } elseif ($row['condition_status'] === 'guarded') {
                                                    $statusClass = 'status-yellow';
                                                }
                                                ?>
                                                <span class="status-badge <?php echo $statusClass; ?>"><?php echo ucfirst($row['condition_status'] ?? 'stable'); ?></span>
                                            </td>
                                            <td>
                                                <a href="ward_nursing_hub.php?admission_id=<?php echo $row['admission_id']; ?>" class="btn-sm-icon" title="Bed Management"><i class="fas fa-bed"></i></a>
                                                <a href="medication_administration.php?admission_id=<?php echo $row['admission_id']; ?>" class="btn-sm-icon" title="Medication"><i class="fas fa-capsules"></i></a>
                                                <a href="nursing_notes.php?admission_id=<?php echo $row['admission_id']; ?>" class="btn-sm-icon" title="Nursing Notes"><i class="fas fa-notes-medical"></i></a>
                                                <a href="#" class="btn-sm-icon" onclick="viewPatientNotes(<?php echo $row['admission_id']; ?>, <?php echo $row['patient_id']; ?>)" title="All Notes"><i class="fas fa-file-alt"></i></a>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr><td colspan="8" class="text-center text-muted py-3">No active inpatients</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Bed Management Section -->
                    <div class="full-table-section" id="bedManagementSection">
                        <div class="section-header">
                            <h3><i class="fas fa-bed me-2"></i> Bed Management</h3>
                            <div>
                                <button class="btn btn-sm btn-outline-primary" onclick="refreshBeds()"><i class="fas fa-sync-alt"></i> Refresh</button>
                                <button class="btn btn-sm btn-primary" onclick="window.location.href='ward_nursing_hub.php'"><i class="fas fa-edit"></i> Manage Beds</button>
                            </div>
                        </div>
                        <div class="scrollable-table">
                            <table class="data-table" id="bedManagementTable">
                                <thead>
                                    <tr><th>Ward</th><th>Room</th><th>Bed</th><th>Type</th><th>Status</th><th>Patient</th><th>Rate</th></tr>
                                </thead>
                                <tbody id="bedManagementBody">
                                    <?php if (!empty($initial_beds)): ?>
                                        <?php foreach ($initial_beds as $bed): ?>
                                        <tr>
                                            <td><?php echo escapeHtml($bed['ward_name'] ?? 'N/A'); ?></td>
                                            <td><?php echo escapeHtml($bed['room_number'] ?? 'N/A'); ?></td>
                                            <td><strong><?php echo escapeHtml($bed['bed_number']); ?></strong></td>
                                            <td><?php echo ucfirst(escapeHtml($bed['bed_type'] ?? 'standard')); ?></td>
                                            <td>
                                                <span class="bed-status-dot <?php echo $bed['status']; ?>"></span>
                                                <span class="badge bed-status-<?php echo $bed['status']; ?>"><?php echo ucfirst($bed['status']); ?></span>
                                            </td>
                                            <td>
                                                <?php if (!empty($bed['patient_name'])): ?>
                                                    <?php echo escapeHtml($bed['patient_name']); ?>
                                                    <?php if ($bed['condition_status'] === 'critical' || $bed['condition_status'] === 'serious'): ?>
                                                        <span class="badge-priority badge-red" style="font-size:8px;">Critical</span>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <span class="text-muted">—</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo number_format($bed['daily_rate'] ?? 0, 2); ?> ETB</td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr><td colspan="7" class="text-center text-muted py-3">No beds found</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Ward Summary -->
                    <div class="full-table-section">
                        <div class="section-header">
                            <h3><i class="fas fa-building me-2"></i> Ward Summary</h3>
                        </div>
                        <div class="scrollable-table">
                            <table class="data-table" id="wardSummaryTable">
                                <thead>
                                    <tr><th>Ward</th><th>Type</th><th>Total</th><th>Occupied</th><th>Available</th><th>Maintenance</th><th>Cleaning</th><th>Reserved</th></tr>
                                </thead>
                                <tbody id="wardSummaryBody">
                                    <?php if (!empty($initial_wards)): ?>
                                        <?php foreach ($initial_wards as $ward): ?>
                                        <tr>
                                            <td><strong><?php echo escapeHtml($ward['ward_name']); ?></strong></td>
                                            <td><?php echo ucfirst(escapeHtml($ward['ward_type'] ?? 'general')); ?></td>
                                            <td><?php echo $ward['total_beds_count'] ?? 0; ?></td>
                                            <td class="text-danger"><?php echo $ward['occupied_count'] ?? 0; ?></td>
                                            <td class="text-success"><?php echo $ward['available_count'] ?? 0; ?></td>
                                            <td class="text-warning"><?php echo $ward['maintenance_count'] ?? 0; ?></td>
                                            <td class="text-info"><?php echo $ward['cleaning_count'] ?? 0; ?></td>
                                            <td class="text-primary"><?php echo $ward['reserved_count'] ?? 0; ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr><td colspan="8" class="text-center text-muted py-3">No wards found</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Pending Lab Samples Table -->
                    <div class="full-table-section">
                        <div class="section-header">
                            <h3><i class="fas fa-flask me-2"></i> Pending Lab Samples</h3>
                            <div>
                                <button class="btn btn-sm btn-outline-primary" onclick="refreshSamples()"><i class="fas fa-sync-alt"></i> Refresh</button>
                                <button class="btn btn-sm btn-primary" onclick="window.location.href='sample_collection.php'"><i class="fas fa-syringe"></i> Collection</button>
                            </div>
                        </div>
                        <div class="scrollable-table">
                            <table class="data-table" id="sampleFullTable">
                                <thead>
                                    <tr><th>Order #</th><th>Patient</th><th>Test</th><th>Sample Type</th><th>Priority</th><th>Status</th><th>Collected</th></tr>
                                </thead>
                                <tbody id="sampleFullBody">
                                    <?php if (!empty($initial_pending_samples)): ?>
                                        <?php foreach ($initial_pending_samples as $sample): ?>
                                        <tr>
                                            <td><?php echo escapeHtml($sample['order_number']); ?></td>
                                            <td><strong><?php echo escapeHtml($sample['patient_name']); ?></strong><br><small class="text-muted"><?php echo escapeHtml($sample['mr_number']); ?></small></td>
                                            <td><?php echo escapeHtml($sample['test_name']); ?></td>
                                            <td><?php echo escapeHtml($sample['sample_type'] ?? 'N/A'); ?></td>
                                            <td>
                                                <?php
                                                $priorityClass = 'badge-green';
                                                if ($sample['priority'] === 'stat') $priorityClass = 'badge-red';
                                                elseif ($sample['priority'] === 'urgent') $priorityClass = 'badge-yellow';
                                                ?>
                                                <span class="badge-priority <?php echo $priorityClass; ?>"><?php echo strtoupper($sample['priority']); ?></span>
                                            </td>
                                            <td>
                                                <span class="badge-priority <?php echo $sample['status'] === 'ordered' ? 'badge-yellow' : 'badge-blue'; ?>">
                                                    <?php echo ucfirst(str_replace('_', ' ', $sample['status'])); ?>
                                                </span>
                                                <?php if (!empty($sample['sample_barcode'])): ?>
                                                    <br><small class="text-muted">Barcode: <?php echo escapeHtml($sample['sample_barcode']); ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if (!empty($sample['collected_at'])): ?>
                                                    <?php echo date('d M Y H:i', strtotime($sample['collected_at'])); ?>
                                                    <?php if (!empty($sample['collected_by'])): ?>
                                                        <br><small>by <?php echo escapeHtml($sample['collected_by']); ?></small>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <span class="text-muted">Pending</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr><td colspan="7" class="text-center text-muted py-3">No pending lab samples</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php include('assets/inc/footer.php'); ?>
</div>

<div id="loadingOverlay" class="loading-overlay"><div class="spinner"></div></div>

<!-- Patient Notes Modal -->
<div class="modal fade" id="patientNotesModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-notes-medical me-2"></i> Patient Notes</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="notesLoading" class="text-center py-4">
                    <div class="spinner-border text-primary" role="status"></div>
                    <p class="mt-2">Loading notes...</p>
                </div>
                <div id="notesContent" style="display:none;">
                    <h6 class="fw-bold text-success"><i class="fas fa-user-md me-2"></i> Nursing Notes</h6>
                    <div id="nursingNotesContainer" class="mb-4"></div>
                    <h6 class="fw-bold text-primary"><i class="fas fa-user-doctor me-2"></i> Doctor Notes</h6>
                    <div id="doctorNotesContainer"></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary" onclick="window.location.href='nursing_notes.php'">Add Nursing Note</button>
            </div>
        </div>
    </div>
</div>

<script src="assets/js/vendor.min.js"></script>
<script src="assets/js/bootstrap.bundle.min.js"></script>
<script>
const csrfToken = <?php echo json_encode($csrf_token); ?>;
const branchId = <?php echo (int)$branch_id; ?>;
let refreshInterval = null;

function showToast(message, type = 'success') {
    const toast = document.createElement('div');
    toast.className = `toast-notification ${type === 'error' ? 'toast-error' : ''}`;
    toast.innerHTML = `<i class="fas fa-${type === 'error' ? 'exclamation-circle' : 'check-circle'}"></i> <span>${escapeHtml(message)}</span>`;
    document.body.appendChild(toast);
    setTimeout(() => toast.remove(), 3500);
}

function showLoading(show) {
    document.getElementById('loadingOverlay').classList.toggle('active', show);
}

function escapeHtml(unsafe) {
    if (!unsafe) return '';
    return String(unsafe).replace(/[&<>]/g, m => m === '&' ? '&amp;' : (m === '<' ? '&lt;' : '&gt;'));
}

// View Patient Notes
function viewPatientNotes(admissionId, patientId) {
    const modal = new bootstrap.Modal(document.getElementById('patientNotesModal'));
    document.getElementById('notesLoading').style.display = 'block';
    document.getElementById('notesContent').style.display = 'none';
    modal.show();

    $.post(window.location.pathname + '?ajax=nursing_action', {
        action: 'fetch_patient_notes',
        admission_id: admissionId,
        patient_id: patientId,
        branch_id: branchId,
        csrf_token: csrfToken
    }, function(res) {
        document.getElementById('notesLoading').style.display = 'none';
        if (res.success && res.data && res.data.notes) {
            const notes = res.data.notes;
            
            // Nursing Notes
            let nursingHtml = '';
            if (notes.nursing_notes && notes.nursing_notes.length > 0) {
                notes.nursing_notes.forEach(n => {
                    const shiftLabel = { morning: 'Morning', afternoon: 'Afternoon', night: 'Night' }[n.shift_type] || n.shift_type;
                    nursingHtml += `
                        <div class="note-entry nursing">
                            <div class="d-flex justify-content-between">
                                <span class="fw-bold">${shiftLabel} Shift</span>
                                <small class="text-muted">${new Date(n.created_at).toLocaleString()}</small>
                            </div>
                            <div>${escapeHtml(n.progress_note)}</div>
                            ${n.clinical_observations ? `<div class="text-muted small">Observations: ${escapeHtml(n.clinical_observations)}</div>` : ''}
                            <small class="text-muted">— Nurse ${escapeHtml(n.nurse_name || 'N/A')}</small>
                        </div>
                    `;
                });
            } else {
                nursingHtml = '<div class="text-muted">No nursing notes found.</div>';
            }
            document.getElementById('nursingNotesContainer').innerHTML = nursingHtml;

            // Doctor Notes
            let doctorHtml = '';
            if (notes.doctor_notes && notes.doctor_notes.length > 0) {
                notes.doctor_notes.forEach(n => {
                    const roundLabel = { morning: 'Morning', evening: 'Evening', night: 'Night', emergency: 'Emergency' }[n.round_type] || n.round_type;
                    doctorHtml += `
                        <div class="note-entry doctor">
                            <div class="d-flex justify-content-between">
                                <span class="fw-bold">${roundLabel} Round</span>
                                <small class="text-muted">${new Date(n.created_at).toLocaleString()}</small>
                            </div>
                            <div>${escapeHtml(n.progress_note)}</div>
                            ${n.clinical_assessment ? `<div class="text-muted small">Assessment: ${escapeHtml(n.clinical_assessment)}</div>` : ''}
                            ${n.plan_modifications ? `<div class="text-muted small">Plan: ${escapeHtml(n.plan_modifications)}</div>` : ''}
                            ${n.vitals_summary ? `<div class="text-muted small">Vitals: ${escapeHtml(n.vitals_summary)}</div>` : ''}
                            <small class="text-muted">— Dr. ${escapeHtml(n.doctor_name || 'N/A')}</small>
                        </div>
                    `;
                });
            } else {
                doctorHtml = '<div class="text-muted">No doctor notes found.</div>';
            }
            document.getElementById('doctorNotesContainer').innerHTML = doctorHtml;

            document.getElementById('notesContent').style.display = 'block';
        } else {
            showToast(res.message || 'Failed to load notes', 'error');
        }
    }).fail(() => {
        document.getElementById('notesLoading').style.display = 'none';
        showToast('Network error loading notes', 'error');
    });
}

// Fetch HUD Metrics
function fetchHUDMetrics() {
    $.post(window.location.pathname + '?ajax=nursing_action', {
        action: 'fetch_hud_metrics',
        branch_id: branchId,
        csrf_token: csrfToken
    }, function(res) {
        if (res.success && res.metrics) {
            $('#hudTriageQueue').text(res.metrics.triage_queue);
            $('#hudOccupiedBeds').text(res.metrics.occupied_beds);
            $('#hudAvailableBeds').text(res.metrics.available_beds);
            $('#hudActiveInpatients').text(res.metrics.active_inpatients);
            $('#hudPendingPhlebotomy').text(res.metrics.pending_phlebotomy);
            $('#hudCriticalPatients').text(res.metrics.critical_patients);
            $('#hudPendingLabSamples').text(res.metrics.pending_lab_samples);
            $('#quickTriageCount').text(res.metrics.triage_queue);
            $('#quickInpatientCount').text(res.metrics.active_inpatients);
            $('#quickSampleCount').text(res.metrics.pending_lab_samples);
        }
    }).fail(() => console.error('HUD metrics fetch failed'));
}

// Fetch Triage Queue
function fetchTriageQueue() {
    $.post(window.location.pathname + '?ajax=nursing_action', {
        action: 'fetch_triage_queue',
        branch_id: branchId,
        csrf_token: csrfToken
    }, function(res) {
        if (res.success && res.queue) {
            let fullHtml = '';
            if (res.queue.length === 0) {
                fullHtml = '<tr><td colspan="8" class="text-center text-muted py-3">No patients awaiting triage</td></tr>';
            } else {
                res.queue.forEach(row => {
                    const statusBadge = row.status_label === 'In Triage' ? 
                        '<span class="badge-priority badge-in-triage">In Triage</span>' : 
                        '<span class="badge-priority badge-green">Waiting</span>';
                    fullHtml += `
                        <tr>
                            <td>${escapeHtml(row.queue_number)}</td>
                            <td><strong>${escapeHtml(row.patient_name)}</strong></td>
                            <td>${escapeHtml(row.mr_number)}</td>
                            <td>${row.age} yrs / ${escapeHtml(row.gender)}</td>
                            <td>${escapeHtml(row.chief_complaint ? row.chief_complaint.substring(0, 30) : '')}</td>
                            <td><span class="status-badge status-${row.priority}">${row.priority.charAt(0).toUpperCase() + row.priority.slice(1)}</span></td>
                            <td>${statusBadge}</td>
                            <td>
                                <a href="nursing_triage.php?visit_id=${row.visit_id}" class="btn-sm-icon"><i class="fas fa-heartbeat"></i> Triage</a>
                            </td>
                        </tr>
                    `;
                });
            }
            $('#triageFullBody').html(fullHtml);

            // Update left quick list
            let quickHtml = '';
            const quickItems = res.queue.slice(0, 5);
            if (quickItems.length === 0) {
                quickHtml = '<div class="empty-state"><i class="fas fa-check-circle"></i><p>No pending triage</p></div>';
            } else {
                quickItems.forEach(row => {
                    const statusBadge = row.status_label === 'In Triage' ? 
                        '<span class="badge-priority badge-in-triage">In Triage</span>' : '';
                    quickHtml += `
                        <div class="quick-queue-item">
                            <div class="patient-info">
                                <h6>${escapeHtml(row.patient_name)}</h6>
                                <p>MRN: ${escapeHtml(row.mr_number)} • ${row.age} yrs</p>
                            </div>
                            <div>
                                <span class="badge-priority badge-${row.priority}">${row.priority.charAt(0).toUpperCase() + row.priority.slice(1)}</span>
                                ${statusBadge}
                                <a href="nursing_triage.php?visit_id=${row.visit_id}" class="action-icon" title="Start Triage"><i class="fas fa-heartbeat"></i></a>
                            </div>
                        </div>
                    `;
                });
            }
            $('#quickTriageList').html(quickHtml);
        }
    }).fail(() => console.error('Triage queue fetch failed'));
}

// Fetch Inpatients
function fetchInpatients() {
    $.post(window.location.pathname + '?ajax=nursing_action', {
        action: 'fetch_inpatients',
        branch_id: branchId,
        csrf_token: csrfToken
    }, function(res) {
        if (res.success && res.inpatients) {
            let fullHtml = '';
            if (res.inpatients.length === 0) {
                fullHtml = '<tr><td colspan="8" class="text-center text-muted py-3">No active inpatients</td></tr>';
            } else {
                res.inpatients.forEach(row => {
                    let statusClass = 'status-green';
                    if (row.condition_status === 'critical' || row.condition_status === 'serious') {
                        statusClass = 'status-critical';
                    } else if (row.condition_status === 'guarded') {
                        statusClass = 'status-yellow';
                    }
                    fullHtml += `
                        <tr>
                            <td>${escapeHtml(row.admission_number)}</td>
                            <td><strong>${escapeHtml(row.patient_name)}</strong></td>
                            <td>${escapeHtml(row.mr_number)}</td>
                            <td>${row.age} yrs / ${escapeHtml(row.gender)}</td>
                            <td>${escapeHtml(row.bed_code)}<br><small class="text-muted">${escapeHtml(row.ward_name)}</small></td>
                            <td>Dr. ${escapeHtml(row.doctor_name)}</td>
                            <td><span class="status-badge ${statusClass}">${escapeHtml(row.condition_status)}</span></td>
                            <td>
                                <a href="ward_nursing_hub.php?admission_id=${row.admission_id}" class="btn-sm-icon" title="Bed Management"><i class="fas fa-bed"></i></a>
                                <a href="medication_administration.php?admission_id=${row.admission_id}" class="btn-sm-icon" title="Medication"><i class="fas fa-capsules"></i></a>
                                <a href="nursing_notes.php?admission_id=${row.admission_id}" class="btn-sm-icon" title="Nursing Notes"><i class="fas fa-notes-medical"></i></a>
                                <a href="#" class="btn-sm-icon" onclick="viewPatientNotes(${row.admission_id}, ${row.patient_id})" title="All Notes"><i class="fas fa-file-alt"></i></a>
                            </td>
                        </tr>
                    `;
                });
            }
            $('#inpatientFullBody').html(fullHtml);

            // Update left quick list
            let quickHtml = '';
            const quickItems = res.inpatients.slice(0, 5);
            if (quickItems.length === 0) {
                quickHtml = '<div class="empty-state"><i class="fas fa-user-md"></i><p>No active inpatients</p></div>';
            } else {
                quickItems.forEach(row => {
                    const isCritical = row.condition_status === 'critical' || row.condition_status === 'serious';
                    quickHtml += `
                        <div class="quick-queue-item">
                            <div class="patient-info">
                                <h6>${escapeHtml(row.patient_name)} ${isCritical ? '<span class="badge-priority badge-red" title="Critical">⚠️</span>' : ''}</h6>
                                <p>${escapeHtml(row.bed_code)} • ${escapeHtml(row.doctor_name)}</p>
                            </div>
                            <div>
                                <a href="ward_nursing_hub.php?admission_id=${row.admission_id}" class="action-icon" title="Bed Management"><i class="fas fa-bed"></i></a>
                                <a href="medication_administration.php?admission_id=${row.admission_id}" class="action-icon" title="Medication"><i class="fas fa-capsules"></i></a>
                                <a href="nursing_notes.php?admission_id=${row.admission_id}" class="action-icon" title="Nursing Notes"><i class="fas fa-notes-medical"></i></a>
                                <a href="#" class="action-icon" onclick="viewPatientNotes(${row.admission_id}, ${row.patient_id})" title="All Notes"><i class="fas fa-file-alt"></i></a>
                            </div>
                        </div>
                    `;
                });
            }
            $('#quickInpatientList').html(quickHtml);
        }
    }).fail(() => console.error('Inpatients fetch failed'));
}

// Fetch Bed Management
function fetchBeds() {
    $.post(window.location.pathname + '?ajax=nursing_action', {
        action: 'fetch_bed_management',
        branch_id: branchId,
        csrf_token: csrfToken
    }, function(res) {
        if (res.success) {
            if (res.beds) {
                let bedHtml = '';
                res.beds.forEach(bed => {
                    bedHtml += `
                        <tr>
                            <td>${escapeHtml(bed.ward_name || 'N/A')}</td>
                            <td>${escapeHtml(bed.room_number || 'N/A')}</td>
                            <td><strong>${escapeHtml(bed.bed_number)}</strong></td>
                            <td>${escapeHtml(bed.bed_type || 'standard')}</td>
                            <td>
                                <span class="bed-status-dot ${bed.status}"></span>
                                <span class="badge bed-status-${bed.status}">${escapeHtml(bed.status)}</span>
                            </td>
                            <td>${bed.patient_name ? escapeHtml(bed.patient_name) : '<span class="text-muted">—</span>'}</td>
                            <td>${bed.daily_rate ? Number(bed.daily_rate).toFixed(2) : '0.00'} ETB</td>
                        </tr>
                    `;
                });
                $('#bedManagementBody').html(bedHtml || '<tr><td colspan="7" class="text-center text-muted py-3">No beds found</td></tr>');
            }
            if (res.wards) {
                let wardHtml = '';
                res.wards.forEach(ward => {
                    wardHtml += `
                        <tr>
                            <td><strong>${escapeHtml(ward.ward_name)}</strong></td>
                            <td>${escapeHtml(ward.ward_type || 'general')}</td>
                            <td>${ward.total_beds_count || 0}</td>
                            <td class="text-danger">${ward.occupied_count || 0}</td>
                            <td class="text-success">${ward.available_count || 0}</td>
                            <td class="text-warning">${ward.maintenance_count || 0}</td>
                            <td class="text-info">${ward.cleaning_count || 0}</td>
                            <td class="text-primary">${ward.reserved_count || 0}</td>
                        </tr>
                    `;
                });
                $('#wardSummaryBody').html(wardHtml || '<tr><td colspan="8" class="text-center text-muted py-3">No wards found</td></tr>');
            }
        }
    }).fail(() => console.error('Bed fetch failed'));
}

// Fetch Pending Lab Samples
function fetchSamples() {
    $.post(window.location.pathname + '?ajax=nursing_action', {
        action: 'fetch_pending_lab_samples',
        branch_id: branchId,
        csrf_token: csrfToken
    }, function(res) {
        if (res.success && res.samples) {
            let fullHtml = '';
            if (res.samples.length === 0) {
                fullHtml = '<tr><td colspan="7" class="text-center text-muted py-3">No pending lab samples</td></tr>';
            } else {
                res.samples.forEach(sample => {
                    const priorityClass = sample.priority === 'stat' ? 'badge-red' : (sample.priority === 'urgent' ? 'badge-yellow' : 'badge-green');
                    const statusClass = sample.status === 'ordered' ? 'badge-yellow' : 'badge-blue';
                    fullHtml += `
                        <tr>
                            <td>${escapeHtml(sample.order_number)}</td>
                            <td><strong>${escapeHtml(sample.patient_name)}</strong><br><small class="text-muted">${escapeHtml(sample.mr_number)}</small></td>
                            <td>${escapeHtml(sample.test_name)}</td>
                            <td>${escapeHtml(sample.sample_type || 'N/A')}</td>
                            <td><span class="badge-priority ${priorityClass}">${escapeHtml(sample.priority || 'routine')}</span></td>
                            <td>
                                <span class="badge-priority ${statusClass}">${escapeHtml(sample.status)}</span>
                                ${sample.sample_barcode ? `<br><small class="text-muted">Barcode: ${escapeHtml(sample.sample_barcode)}</small>` : ''}
                            </td>
                            <td>
                                ${sample.collected_at ? 
                                    `${new Date(sample.collected_at).toLocaleString()}` + 
                                    (sample.collected_by ? `<br><small>by ${escapeHtml(sample.collected_by)}</small>` : '') :
                                    '<span class="text-muted">Pending</span>'}
                            </td>
                        </tr>
                    `;
                });
            }
            $('#sampleFullBody').html(fullHtml);

            // Update quick list
            let quickHtml = '';
            const quickItems = res.samples.slice(0, 5);
            if (quickItems.length === 0) {
                quickHtml = '<div class="empty-state"><i class="fas fa-check-circle"></i><p>No pending lab samples</p></div>';
            } else {
                quickItems.forEach(sample => {
                    quickHtml += `
                        <div class="quick-queue-item">
                            <div class="patient-info">
                                <h6>${escapeHtml(sample.patient_name)}</h6>
                                <p>${escapeHtml(sample.test_name)} • ${escapeHtml(sample.sample_type || 'N/A')}</p>
                            </div>
                            <div>
                                <span class="badge-priority ${sample.status === 'ordered' ? 'badge-yellow' : 'badge-blue'}">
                                    ${escapeHtml(sample.status)}
                                </span>
                            </div>
                        </div>
                    `;
                });
            }
            $('#quickSampleList').html(quickHtml);
        }
    }).fail(() => console.error('Samples fetch failed'));
}

function refreshBeds() {
    showLoading(true);
    fetchBeds();
    setTimeout(() => {
        showLoading(false);
        showToast('Bed data refreshed');
    }, 500);
}

function refreshSamples() {
    showLoading(true);
    fetchSamples();
    setTimeout(() => {
        showLoading(false);
        showToast('Sample data refreshed');
    }, 500);
}

function refreshData() {
    showLoading(true);
    fetchHUDMetrics();
    fetchTriageQueue();
    fetchInpatients();
    fetchBeds();
    fetchSamples();
    setTimeout(() => {
        showLoading(false);
        showToast('Dashboard refreshed');
    }, 500);
}

function startPolling() {
    if (refreshInterval) clearInterval(refreshInterval);
    refreshInterval = setInterval(() => {
        fetchHUDMetrics();
        fetchTriageQueue();
        fetchInpatients();
        fetchBeds();
        fetchSamples();
    }, 30000);
}

$(document).ready(function() {
    startPolling();
    fetchHUDMetrics();
    fetchTriageQueue();
    fetchInpatients();
    fetchBeds();
    fetchSamples();
});

// Sidebar & MetisMenu
if (typeof MetisMenu !== 'undefined') {
    const sideMenu = document.getElementById('side-menu');
    if (sideMenu && !sideMenu.hasAttribute('data-metismenu-initialized')) {
        new MetisMenu(sideMenu, { toggle: true, triggerElement: 'a.has-arrow', parentTrigger: 'li', subMenu: 'ul' });
        sideMenu.setAttribute('data-metismenu-initialized', 'true');
    }
}

// Clean up interval on page unload
window.addEventListener('beforeunload', function() {
    if (refreshInterval) clearInterval(refreshInterval);
});
</script>
</body>
</html>