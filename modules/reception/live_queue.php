<?php
/**
 * Live Queue Management - HMS ERP System
 * For Reception Role - Real-time patient flow management
 * 
 * @package HMS-ERP
 * @subpackage Reception
 */

if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../../config/db_connection.php';
require_once __DIR__ . '/../../includes/auth_helper.php';

// =============================================================================
// AUTHENTICATION & INITIALIZATION
// =============================================================================
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../index.php');
    exit;
}

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrf_token = $_SESSION['csrf_token'];
$branch_id = (int)($_SESSION['branch_id'] ?? 0);
$user_id = (int)($_SESSION['user_id'] ?? 0);
$is_global_admin = ($_SESSION['user_type'] ?? '') === 'super_admin';
$user_name = $_SESSION['first_name'] ?? $_SESSION['username'] ?? 'Receptionist';

$db_available = is_db_available();
if (!$db_available) {
    die('Database connection failed. Please contact system administrator.');
}

// =============================================================================
// AJAX HANDLER
// =============================================================================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'queue_action') {
    error_reporting(0);
    ini_set('display_errors', 0);
    
    if (ob_get_level()) ob_clean();
    header('Content-Type: application/json; charset=utf-8');

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
        exit;
    }

    $submitted_token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $submitted_token)) {
        echo json_encode(['success' => false, 'message' => 'Security validation failed.']);
        exit;
    }

    $action = (string)($_POST['action'] ?? '');
    $db = getDB();
    
    if (!$db) {
        echo json_encode(['success' => false, 'message' => 'Database connection failed']);
        exit;
    }

    $request_branch = $is_global_admin ? (int)($_POST['branch_id'] ?? $branch_id) : $branch_id;

    // -------------------------------------------------------------------------
    // GET FULL QUEUE DATA
    // -------------------------------------------------------------------------
    if ($action === 'get_full_queue') {
        try {
            $data = [];
            
            // 1. Nurse Triage Queue (patients waiting for nurse triage)
            $nurse_sql = "
                SELECT 
                    pv.id AS visit_id,
                    pv.visit_number,
                    p.id AS patient_id,
                    CONCAT(COALESCE(p.first_name,''), ' ', COALESCE(p.middle_name,''), ' ', COALESCE(p.last_name,'')) AS patient_name,
                    p.mr_number,
                    p.phone,
                    p.gender,
                    p.date_of_birth,
                    COALESCE(d.name, 'General') AS department_name,
                    pv.created_at AS arrived_at,
                    pv.status,
                    a.chief_complaint,
                    a.priority AS appointment_priority,
                    COALESCE(a.doctor_id, 0) AS doctor_id,
                    CONCAT('Dr. ', COALESCE(doc.first_name,''), ' ', COALESCE(doc.last_name,'')) AS doctor_name,
                    TIMESTAMPDIFF(MINUTE, pv.created_at, NOW()) AS waiting_minutes
                FROM patient_visits pv
                INNER JOIN patients p ON p.id = pv.patient_id
                LEFT JOIN appointments a ON a.visit_id = pv.id AND a.deleted_at IS NULL
                LEFT JOIN departments d ON d.id = a.department_id
                LEFT JOIN users doc ON doc.id = a.doctor_id
                LEFT JOIN triage_records tr ON pv.id = tr.visit_id AND tr.deleted_at IS NULL
                WHERE pv.status = 'checked_in'
                  AND pv.branch_id = ?
                  AND pv.deleted_at IS NULL
                  AND pv.visit_type != 'emergency'
                  AND (tr.id IS NULL OR tr.deleted_at IS NOT NULL)
                  AND NOT EXISTS (
                      SELECT 1 FROM admissions adm 
                      WHERE adm.visit_id = pv.id AND adm.deleted_at IS NULL
                  )
                ORDER BY pv.created_at ASC
                LIMIT 100
            ";
            $nurse_stmt = $db->prepare($nurse_sql);
            $nurse_stmt->execute([$request_branch]);
            $data['nurse_queue'] = $nurse_stmt->fetchAll(PDO::FETCH_ASSOC);

            // 2. Doctor Consultation Queue (patients waiting for doctor)
            $doctor_sql = "
                SELECT 
                    pv.id AS visit_id,
                    pv.visit_number,
                    p.id AS patient_id,
                    CONCAT(COALESCE(p.first_name,''), ' ', COALESCE(p.middle_name,''), ' ', COALESCE(p.last_name,'')) AS patient_name,
                    p.mr_number,
                    p.phone,
                    p.gender,
                    p.date_of_birth,
                    COALESCE(d.name, 'General') AS department_name,
                    pv.created_at AS arrived_at,
                    pv.status,
                    a.chief_complaint,
                    a.priority AS appointment_priority,
                    COALESCE(a.doctor_id, 0) AS doctor_id,
                    CONCAT('Dr. ', COALESCE(doc.first_name,''), ' ', COALESCE(doc.last_name,'')) AS doctor_name,
                    TIMESTAMPDIFF(MINUTE, pv.created_at, NOW()) AS waiting_minutes,
                    tr.esi_level,
                    tr.priority AS triage_priority,
                    tr.blood_pressure_systolic,
                    tr.blood_pressure_diastolic,
                    tr.heart_rate,
                    tr.temperature_celsius,
                    tr.oxygen_saturation
                FROM patient_visits pv
                INNER JOIN patients p ON p.id = pv.patient_id
                LEFT JOIN appointments a ON a.visit_id = pv.id AND a.deleted_at IS NULL
                LEFT JOIN departments d ON d.id = a.department_id
                LEFT JOIN users doc ON doc.id = a.doctor_id
                INNER JOIN triage_records tr ON pv.id = tr.visit_id AND tr.deleted_at IS NULL
                WHERE pv.status = 'checked_in'
                  AND pv.branch_id = ?
                  AND pv.deleted_at IS NULL
                  AND pv.visit_type != 'emergency'
                  AND tr.blood_pressure_systolic IS NOT NULL 
                  AND tr.blood_pressure_systolic > 0
                  AND NOT EXISTS (
                      SELECT 1 FROM admissions adm 
                      WHERE adm.visit_id = pv.id AND adm.deleted_at IS NULL
                  )
                ORDER BY 
                  CASE 
                      WHEN tr.priority = 'red' THEN 1
                      WHEN tr.priority = 'yellow' THEN 2
                      WHEN tr.priority = 'green' THEN 3
                      ELSE 4
                  END,
                  pv.created_at ASC
                LIMIT 100
            ";
            $doctor_stmt = $db->prepare($doctor_sql);
            $doctor_stmt->execute([$request_branch]);
            $data['doctor_queue'] = $doctor_stmt->fetchAll(PDO::FETCH_ASSOC);

            // 3. Emergency Queue (priority patients)
            $emergency_sql = "
                SELECT 
                    pv.id AS visit_id,
                    pv.visit_number,
                    p.id AS patient_id,
                    CONCAT(COALESCE(p.first_name,''), ' ', COALESCE(p.middle_name,''), ' ', COALESCE(p.last_name,'')) AS patient_name,
                    p.mr_number,
                    p.phone,
                    COALESCE(d.name, 'Emergency') AS department_name,
                    pv.created_at AS arrived_at,
                    pv.status,
                    a.chief_complaint,
                    a.priority AS appointment_priority,
                    TIMESTAMPDIFF(MINUTE, pv.created_at, NOW()) AS waiting_minutes,
                    tr.esi_level,
                    tr.priority AS triage_priority,
                    tr.blood_pressure_systolic,
                    tr.blood_pressure_diastolic,
                    tr.heart_rate,
                    tr.temperature_celsius,
                    tr.oxygen_saturation
                FROM patient_visits pv
                INNER JOIN patients p ON p.id = pv.patient_id
                LEFT JOIN appointments a ON a.visit_id = pv.id AND a.deleted_at IS NULL
                LEFT JOIN departments d ON d.id = a.department_id
                LEFT JOIN triage_records tr ON pv.id = tr.visit_id AND tr.deleted_at IS NULL
                WHERE pv.visit_type = 'emergency'
                  AND pv.branch_id = ?
                  AND pv.deleted_at IS NULL
                  AND pv.status IN ('checked_in', 'in_triage', 'in_consultation')
                  AND NOT EXISTS (
                      SELECT 1 FROM admissions adm 
                      WHERE adm.visit_id = pv.id AND adm.deleted_at IS NULL
                  )
                ORDER BY 
                  CASE 
                      WHEN tr.priority = 'red' THEN 1
                      WHEN tr.priority = 'yellow' THEN 2
                      WHEN tr.priority = 'green' THEN 3
                      ELSE 4
                  END,
                  pv.created_at ASC
                LIMIT 50
            ";
            $emergency_stmt = $db->prepare($emergency_sql);
            $emergency_stmt->execute([$request_branch]);
            $data['emergency_queue'] = $emergency_stmt->fetchAll(PDO::FETCH_ASSOC);

            // 4. Active Consultations (patients currently with doctors)
            $active_sql = "
                SELECT 
                    pv.id AS visit_id,
                    pv.visit_number,
                    p.id AS patient_id,
                    CONCAT(COALESCE(p.first_name,''), ' ', COALESCE(p.middle_name,''), ' ', COALESCE(p.last_name,'')) AS patient_name,
                    p.mr_number,
                    COALESCE(d.name, 'General') AS department_name,
                    CONCAT('Dr. ', COALESCE(doc.first_name,''), ' ', COALESCE(doc.last_name,'')) AS doctor_name,
                    pv.consultation_started_at,
                    TIMESTAMPDIFF(MINUTE, pv.consultation_started_at, NOW()) AS consultation_duration,
                    a.chief_complaint
                FROM patient_visits pv
                INNER JOIN patients p ON p.id = pv.patient_id
                LEFT JOIN appointments a ON a.visit_id = pv.id AND a.deleted_at IS NULL
                LEFT JOIN departments d ON d.id = a.department_id
                LEFT JOIN users doc ON doc.id = a.doctor_id
                WHERE pv.status = 'in_consultation'
                  AND pv.branch_id = ?
                  AND pv.deleted_at IS NULL
                  AND NOT EXISTS (
                      SELECT 1 FROM admissions adm 
                      WHERE adm.visit_id = pv.id AND adm.deleted_at IS NULL
                  )
                ORDER BY pv.consultation_started_at ASC
                LIMIT 50
            ";
            $active_stmt = $db->prepare($active_sql);
            $active_stmt->execute([$request_branch]);
            $data['active_consultations'] = $active_stmt->fetchAll(PDO::FETCH_ASSOC);

            // 5. Department-wise Queue Counts
            $dept_sql = "
                SELECT 
                    d.id AS department_id,
                    d.name AS department_name,
                    COUNT(CASE WHEN pv.status = 'checked_in' AND (tr.blood_pressure_systolic IS NULL OR tr.blood_pressure_systolic = 0) THEN 1 END) AS triage_waiting,
                    COUNT(CASE WHEN pv.status = 'checked_in' AND tr.blood_pressure_systolic IS NOT NULL AND tr.blood_pressure_systolic > 0 THEN 1 END) AS doctor_waiting,
                    COUNT(CASE WHEN pv.status = 'in_consultation' THEN 1 END) AS active_consultations,
                    COUNT(CASE WHEN pv.visit_type = 'emergency' THEN 1 END) AS emergency_count
                FROM departments d
                LEFT JOIN appointments a ON a.department_id = d.id AND a.deleted_at IS NULL
                LEFT JOIN patient_visits pv ON pv.id = a.visit_id AND pv.branch_id = ? AND pv.deleted_at IS NULL
                LEFT JOIN triage_records tr ON pv.id = tr.visit_id AND tr.deleted_at IS NULL
                WHERE d.is_active = 1 
                  AND d.deleted_at IS NULL
                  AND d.branch_id = ?
                GROUP BY d.id, d.name
                ORDER BY d.name
            ";
            $dept_stmt = $db->prepare($dept_sql);
            $dept_stmt->execute([$request_branch, $request_branch]);
            $data['department_stats'] = $dept_stmt->fetchAll(PDO::FETCH_ASSOC);

            // 6. Waiting Time Statistics
            $wait_sql = "
                SELECT 
                    AVG(TIMESTAMPDIFF(MINUTE, pv.created_at, NOW())) AS avg_wait,
                    MIN(TIMESTAMPDIFF(MINUTE, pv.created_at, NOW())) AS min_wait,
                    MAX(TIMESTAMPDIFF(MINUTE, pv.created_at, NOW())) AS max_wait,
                    COUNT(*) AS total_waiting
                FROM patient_visits pv
                WHERE pv.status = 'checked_in'
                  AND pv.branch_id = ?
                  AND pv.deleted_at IS NULL
                  AND pv.visit_type != 'emergency'
            ";
            $wait_stmt = $db->prepare($wait_sql);
            $wait_stmt->execute([$request_branch]);
            $data['waiting_stats'] = $wait_stmt->fetch(PDO::FETCH_ASSOC);

            // 7. Doctor Availability Status - Simplified to avoid subquery issues
            $doc_status_sql = "
                SELECT 
                    u.id AS doctor_id,
                    CONCAT(COALESCE(u.first_name,''), ' ', COALESCE(u.middle_name,''), ' ', COALESCE(u.last_name,'')) AS doctor_name,
                    COALESCE(sp.specialization, 'General Medicine') AS specialization,
                    COUNT(DISTINCT CASE WHEN pv.status = 'in_consultation' THEN pv.id END) AS active_patients,
                    COUNT(DISTINCT CASE WHEN pv.status = 'checked_in' THEN pv.id END) AS waiting_patients,
                    CASE 
                        WHEN COUNT(DISTINCT CASE WHEN pv.status = 'in_consultation' THEN pv.id END) >= 3 THEN 'busy'
                        WHEN COUNT(DISTINCT CASE WHEN pv.status = 'in_consultation' THEN pv.id END) >= 1 THEN 'available'
                        ELSE 'free'
                    END AS availability_status
                FROM users u
                LEFT JOIN staff_profiles sp ON u.id = sp.user_id
                LEFT JOIN appointments a ON a.doctor_id = u.id AND a.deleted_at IS NULL
                LEFT JOIN patient_visits pv ON pv.id = a.visit_id 
                    AND pv.branch_id = ? 
                    AND pv.deleted_at IS NULL
                    AND pv.status IN ('checked_in', 'in_consultation')
                WHERE u.user_type = 'doctor' 
                  AND u.is_active = 1 
                  AND u.deleted_at IS NULL
                  AND u.branch_id = ?
                GROUP BY u.id, u.first_name, u.middle_name, u.last_name, sp.specialization
                ORDER BY doctor_name
            ";
            $doc_status_stmt = $db->prepare($doc_status_sql);
            $doc_status_stmt->execute([$request_branch, $request_branch]);
            $data['doctor_status'] = $doc_status_stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['success' => true, 'data' => $data]);
            
        } catch (PDOException $e) {
            error_log("Queue data error: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    // -------------------------------------------------------------------------
    // MOVE PATIENT TO NEXT STAGE
    // -------------------------------------------------------------------------
    if ($action === 'move_patient') {
        $visit_id = (int)($_POST['visit_id'] ?? 0);
        $target = $_POST['target'] ?? '';
        
        if ($visit_id === 0 || empty($target)) {
            echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
            exit;
        }

        try {
            $db->beginTransaction();

            // Get visit details
            $visit_sql = "SELECT pv.*, a.doctor_id AS appointment_doctor_id
                          FROM patient_visits pv
                          LEFT JOIN appointments a ON a.visit_id = pv.id AND a.deleted_at IS NULL
                          WHERE pv.id = ? AND pv.deleted_at IS NULL";
            $visit_stmt = $db->prepare($visit_sql);
            $visit_stmt->execute([$visit_id]);
            $visit = $visit_stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$visit) {
                throw new Exception('Visit not found');
            }

            $status_update = '';
            $appt_status = '';
            
            switch ($target) {
                case 'triage':
                    $status_update = "status = 'in_triage'";
                    $appt_status = "status = 'checked_in'";
                    
                    // Create triage record if not exists
                    $check_sql = "SELECT id FROM triage_records WHERE visit_id = ? AND deleted_at IS NULL";
                    $check_stmt = $db->prepare($check_sql);
                    $check_stmt->execute([$visit_id]);
                    if (!$check_stmt->fetch()) {
                        $triage_sql = "INSERT INTO triage_records 
                                       (branch_id, patient_id, visit_id, taken_by_user_id, priority, created_at, updated_at)
                                       VALUES (?, ?, ?, ?, 'yellow', NOW(), NOW())";
                        $triage_stmt = $db->prepare($triage_sql);
                        $triage_stmt->execute([
                            $request_branch,
                            $visit['patient_id'],
                            $visit_id,
                            $user_id
                        ]);
                    }
                    break;
                    
                case 'doctor':
                    $status_update = "status = 'in_consultation', consultation_started_at = NOW()";
                    $appt_status = "status = 'in_progress'";
                    break;
                    
                case 'complete':
                    $status_update = "status = 'completed', consultation_completed_at = NOW(), completed_at = NOW()";
                    $appt_status = "status = 'completed'";
                    break;
                    
                default:
                    throw new Exception('Invalid target action');
            }

            // Update patient visit
            if (!empty($status_update)) {
                $update_sql = "UPDATE patient_visits SET $status_update, updated_at = NOW() WHERE id = ?";
                $update_stmt = $db->prepare($update_sql);
                $update_stmt->execute([$visit_id]);
            }

            // Update appointment if exists
            if (!empty($appt_status) && !empty($visit['appointment_doctor_id'])) {
                $appt_sql = "UPDATE appointments SET $appt_status, updated_at = NOW() WHERE visit_id = ?";
                $appt_stmt = $db->prepare($appt_sql);
                $appt_stmt->execute([$visit_id]);
            }

            $db->commit();
            
            echo json_encode([
                'success' => true,
                'message' => 'Patient moved successfully',
                'visit_id' => $visit_id,
                'new_status' => $target
            ]);
            
        } catch (Exception $e) {
            $db->rollBack();
            error_log("Move patient error: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    // -------------------------------------------------------------------------
    // CALL PATIENT
    // -------------------------------------------------------------------------
    if ($action === 'call_patient') {
        $visit_id = (int)($_POST['visit_id'] ?? 0);
        $room_number = trim($_POST['room_number'] ?? '');
        
        if ($visit_id === 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid visit ID']);
            exit;
        }

        try {
            // Update room assignment
            $sql = "UPDATE patient_visits 
                    SET assigned_room_number = ?, called_at = NOW(), queue_status = 'called', updated_at = NOW()
                    WHERE id = ? AND deleted_at IS NULL";
            $stmt = $db->prepare($sql);
            $stmt->execute([$room_number ?: null, $visit_id]);

            // Get patient details for display
            $info_sql = "
                SELECT 
                    p.first_name, p.last_name, p.mr_number,
                    CONCAT('Dr. ', COALESCE(u.first_name,''), ' ', COALESCE(u.last_name,'')) AS doctor_name,
                    d.name AS department_name
                FROM patient_visits pv
                INNER JOIN patients p ON p.id = pv.patient_id
                LEFT JOIN appointments a ON a.visit_id = pv.id AND a.deleted_at IS NULL
                LEFT JOIN users u ON u.id = a.doctor_id
                LEFT JOIN departments d ON d.id = a.department_id
                WHERE pv.id = ?
            ";
            $info_stmt = $db->prepare($info_sql);
            $info_stmt->execute([$visit_id]);
            $patient_info = $info_stmt->fetch(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'message' => 'Patient called',
                'patient' => $patient_info,
                'room' => $room_number ?: 'Consultation Room'
            ]);
            
        } catch (PDOException $e) {
            error_log("Call patient error: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    // -------------------------------------------------------------------------
    // SEARCH QUEUE
    // -------------------------------------------------------------------------
    if ($action === 'search_queue') {
        $query = trim($_POST['query'] ?? '');
        $queue_type = $_POST['queue_type'] ?? 'all';
        
        if (strlen($query) < 2) {
            echo json_encode(['success' => true, 'patients' => []]);
            exit;
        }
        
        $like = "%{$query}%";
        
        $sql = "
            SELECT 
                pv.id AS visit_id,
                pv.visit_number,
                p.id AS patient_id,
                CONCAT(COALESCE(p.first_name,''), ' ', COALESCE(p.middle_name,''), ' ', COALESCE(p.last_name,'')) AS patient_name,
                p.mr_number,
                p.phone,
                pv.status,
                pv.created_at AS arrived_at,
                TIMESTAMPDIFF(MINUTE, pv.created_at, NOW()) AS waiting_minutes
            FROM patient_visits pv
            INNER JOIN patients p ON p.id = pv.patient_id
            WHERE pv.branch_id = ?
              AND pv.deleted_at IS NULL
              AND pv.status IN ('checked_in', 'in_triage', 'in_consultation')
        ";
        
        if ($queue_type !== 'all') {
            if ($queue_type === 'triage') {
                $sql .= " AND pv.status = 'in_triage'";
            } elseif ($queue_type === 'doctor') {
                $sql .= " AND pv.status = 'in_consultation'";
            } elseif ($queue_type === 'waiting') {
                $sql .= " AND pv.status = 'checked_in'";
            }
        }
        
        $sql .= " AND (
            p.first_name LIKE ? 
            OR p.last_name LIKE ? 
            OR p.mr_number LIKE ?
        ) ORDER BY pv.created_at ASC LIMIT 30";
        
        $stmt = $db->prepare($sql);
        $stmt->execute([$request_branch, $like, $like, $like]);
        
        echo json_encode(['success' => true, 'patients' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    // -------------------------------------------------------------------------
    // GET QUEUE STATISTICS
    // -------------------------------------------------------------------------
    if ($action === 'get_stats') {
        try {
            $stats = [];
            
            // Total waiting
            $wait_sql = "SELECT COUNT(*) AS total_waiting
                         FROM patient_visits pv
                         WHERE pv.status = 'checked_in'
                           AND pv.branch_id = ?
                           AND pv.deleted_at IS NULL";
            $wait_stmt = $db->prepare($wait_sql);
            $wait_stmt->execute([$request_branch]);
            $stats['total_waiting'] = (int)$wait_stmt->fetchColumn();
            
            // In triage
            $triage_sql = "SELECT COUNT(*) AS in_triage
                           FROM patient_visits pv
                           WHERE pv.status = 'in_triage'
                             AND pv.branch_id = ?
                             AND pv.deleted_at IS NULL";
            $triage_stmt = $db->prepare($triage_sql);
            $triage_stmt->execute([$request_branch]);
            $stats['in_triage'] = (int)$triage_stmt->fetchColumn();
            
            // In consultation
            $consult_sql = "SELECT COUNT(*) AS in_consultation
                            FROM patient_visits pv
                            WHERE pv.status = 'in_consultation'
                              AND pv.branch_id = ?
                              AND pv.deleted_at IS NULL";
            $consult_stmt = $db->prepare($consult_sql);
            $consult_stmt->execute([$request_branch]);
            $stats['in_consultation'] = (int)$consult_stmt->fetchColumn();
            
            // Emergency
            $emergency_sql = "SELECT COUNT(*) AS emergency_waiting
                              FROM patient_visits pv
                              WHERE pv.visit_type = 'emergency'
                                AND pv.status IN ('checked_in', 'in_triage', 'in_consultation')
                                AND pv.branch_id = ?
                                AND pv.deleted_at IS NULL";
            $emergency_stmt = $db->prepare($emergency_sql);
            $emergency_stmt->execute([$request_branch]);
            $stats['emergency_waiting'] = (int)$emergency_stmt->fetchColumn();
            
            echo json_encode(['success' => true, 'stats' => $stats]);
            
        } catch (PDOException $e) {
            error_log("Stats error: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action']);
    exit;
}

// =============================================================================
// PAGE INITIALIZATION
// =============================================================================
$pdo = getDB();
$current_date_display = date('l, F j, Y');
$current_time_display = date('g:i A');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Live Queue - Reception | HMS ERP</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root {
            --primary: #4361ee;
            --primary-dark: #3a0ca3;
            --success: #06d6a0;
            --warning: #f9c74f;
            --danger: #ef476f;
            --info: #4cc9f0;
            --dark: #1a1a2e;
            --gray: #6c757d;
            --gray-light: #e9ecef;
            --triage-red: #ef476f;
            --triage-yellow: #f9c74f;
            --triage-green: #06d6a0;
            --triage-blue: #4cc9f0;
        }
        
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #f5f7fb 0%, #eef2f6 100%);
            overflow-x: hidden;
        }
        
        .content-page {
            margin-left: 260px;
            margin-top: 70px;
            padding: 24px 32px;
            transition: margin-left 0.3s;
            min-height: calc(100vh - 70px);
        }
        
        body.erp-sidebar-collapsed .content-page { margin-left: 80px; }
        
        @media (max-width: 768px) {
            .content-page { margin-left: 0 !important; padding: 16px; }
        }
        
        .queue-header {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--primary) 100%);
            border-radius: 24px;
            padding: 28px 32px;
            color: white;
            margin-bottom: 28px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }
        
        .queue-header h1 { font-size: 26px; font-weight: 700; margin-bottom: 6px; }
        .queue-header p { color: rgba(255,255,255,0.8); font-size: 14px; }
        
        .live-indicator {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            background: rgba(255,255,255,0.15);
            padding: 8px 18px;
            border-radius: 20px;
            font-size: 13px;
        }
        
        .live-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: #06d6a0;
            animation: pulse 1.5s ease-in-out infinite;
        }
        
        @keyframes pulse {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.5; transform: scale(0.8); }
        }
        
        .stats-bar {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }
        
        .stat-item {
            background: white;
            border-radius: 16px;
            padding: 16px 20px;
            border: 1px solid var(--gray-light);
            text-align: center;
        }
        
        .stat-item .number {
            font-size: 28px;
            font-weight: 800;
            color: var(--dark);
        }
        
        .stat-item .label {
            font-size: 12px;
            color: var(--gray);
            font-weight: 500;
            margin-top: 2px;
        }
        
        .stat-item .number.red { color: var(--triage-red); }
        .stat-item .number.yellow { color: #d97706; }
        .stat-item .number.green { color: var(--success); }
        .stat-item .number.blue { color: var(--info); }
        
        .queue-controls {
            background: white;
            border-radius: 20px;
            padding: 16px 24px;
            margin-bottom: 24px;
            border: 1px solid var(--gray-light);
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 16px;
        }
        
        .queue-controls .search-box {
            flex: 1;
            min-width: 200px;
            position: relative;
        }
        
        .queue-controls .search-box input {
            width: 100%;
            padding: 10px 16px 10px 40px;
            border: 1px solid var(--gray-light);
            border-radius: 12px;
            font-size: 14px;
            transition: all 0.2s;
        }
        
        .queue-controls .search-box input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(67,97,238,0.1);
        }
        
        .queue-controls .search-box i {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--gray);
        }
        
        .queue-tabs {
            display: flex;
            gap: 6px;
            background: #f1f4f9;
            padding: 4px;
            border-radius: 12px;
        }
        
        .queue-tabs button {
            padding: 8px 18px;
            border: none;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            background: transparent;
            color: var(--gray);
        }
        
        .queue-tabs button:hover { color: var(--dark); }
        .queue-tabs button.active {
            background: white;
            color: var(--primary);
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
        }
        
        .filter-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            border: 1px solid var(--gray-light);
            background: white;
        }
        
        .filter-badge:hover { border-color: var(--primary); }
        .filter-badge.active { background: var(--primary); color: white; border-color: var(--primary); }
        
        .queue-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
            margin-bottom: 24px;
        }
        
        @media (max-width: 1200px) { .queue-grid { grid-template-columns: 1fr; } }
        
        .queue-panel {
            background: white;
            border-radius: 20px;
            border: 1px solid var(--gray-light);
            overflow: hidden;
        }
        
        .queue-panel .panel-header {
            padding: 16px 20px;
            background: #f8fafc;
            border-bottom: 1px solid var(--gray-light);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }
        
        .queue-panel .panel-header h3 {
            margin: 0;
            font-size: 16px;
            font-weight: 700;
            color: var(--dark);
        }
        
        .queue-panel .panel-header .count {
            background: var(--primary);
            color: white;
            padding: 2px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .queue-panel .panel-body {
            padding: 16px 20px;
            max-height: 500px;
            overflow-y: auto;
        }
        
        .queue-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px 16px;
            border-radius: 12px;
            margin-bottom: 8px;
            background: #f8fafc;
            transition: all 0.2s;
            border-left: 4px solid transparent;
            position: relative;
        }
        
        .queue-item:hover {
            background: #f1f5f9;
            transform: translateX(4px);
        }
        
        .queue-item.priority-red { border-left-color: var(--triage-red); background: #fef2f2; }
        .queue-item.priority-yellow { border-left-color: var(--triage-yellow); background: #fffbeb; }
        .queue-item.priority-green { border-left-color: var(--triage-green); background: #ecfdf5; }
        .queue-item.priority-blue { border-left-color: var(--triage-blue); background: #eff6ff; }
        .queue-item.emergency { border-left-color: var(--danger); background: #fee2e2; }
        
        .queue-item .patient-info {
            display: flex;
            flex-direction: column;
            gap: 2px;
            flex: 1;
            min-width: 0;
        }
        
        .queue-item .patient-name {
            font-weight: 600;
            font-size: 14px;
            color: var(--dark);
        }
        
        .queue-item .patient-details {
            font-size: 12px;
            color: var(--gray);
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            align-items: center;
        }
        
        .queue-item .patient-details .mrn {
            background: #e2e8f0;
            padding: 1px 8px;
            border-radius: 10px;
            font-size: 10px;
            font-weight: 600;
        }
        
        .queue-item .patient-details .dept {
            color: var(--primary);
        }
        
        .queue-item .wait-time {
            font-size: 12px;
            color: var(--gray);
            white-space: nowrap;
            margin: 0 12px;
        }
        
        .queue-item .wait-time.long {
            color: var(--danger);
            font-weight: 600;
        }
        
        .queue-item .actions {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
        }
        
        .queue-item .actions .btn {
            padding: 6px 14px;
            border-radius: 10px;
            font-size: 11px;
            font-weight: 600;
            border: none;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .btn-call {
            background: var(--warning);
            color: #000;
        }
        .btn-call:hover { background: #eab308; }
        
        .btn-triage {
            background: var(--info);
            color: white;
        }
        .btn-triage:hover { background: #0ea5e9; }
        
        .btn-doctor {
            background: var(--primary);
            color: white;
        }
        .btn-doctor:hover { background: var(--primary-dark); }
        
        .btn-complete {
            background: var(--success);
            color: white;
        }
        .btn-complete:hover { background: #059669; }
        
        .btn-danger-sm {
            background: var(--danger);
            color: white;
        }
        .btn-danger-sm:hover { background: #dc2626; }
        
        .priority-badge {
            display: inline-block;
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
        }
        
        .priority-badge.red { background: var(--triage-red); color: white; }
        .priority-badge.yellow { background: var(--triage-yellow); color: #000; }
        .priority-badge.green { background: var(--triage-green); color: white; }
        .priority-badge.blue { background: var(--triage-blue); color: white; }
        .priority-badge.emergency { background: var(--danger); color: white; animation: blinkRed 1s ease-in-out infinite; }
        
        @keyframes blinkRed {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }
        
        .status-badge {
            display: inline-block;
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: 600;
        }
        .status-badge.waiting { background: #e0e7ff; color: #3730a3; }
        .status-badge.triage { background: #fef3c7; color: #92400e; }
        .status-badge.consultation { background: #d1fae5; color: #065f46; }
        
        .doctor-status-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
            gap: 12px;
            padding: 16px 20px;
        }
        
        .doctor-status-card {
            background: #f8fafc;
            border-radius: 14px;
            padding: 14px 16px;
            text-align: center;
            border: 1px solid var(--gray-light);
        }
        
        .doctor-status-card .doc-name {
            font-weight: 600;
            font-size: 13px;
            color: var(--dark);
        }
        
        .doctor-status-card .doc-specialty {
            font-size: 11px;
            color: var(--gray);
        }
        
        .doctor-status-card .doc-status {
            margin-top: 8px;
            display: flex;
            justify-content: center;
            gap: 12px;
            font-size: 12px;
        }
        
        .doctor-status-card .doc-status .status-badge {
            font-size: 10px;
            padding: 2px 12px;
        }
        
        .doctor-status-card .doc-status .free { background: #d1fae5; color: #065f46; }
        .doctor-status-card .doc-status .busy { background: #fee2e2; color: #991b1b; }
        .doctor-status-card .doc-status .available { background: #fef3c7; color: #92400e; }
        
        .doctor-status-card .doc-count {
            font-size: 20px;
            font-weight: 700;
            color: var(--dark);
        }
        
        .doctor-status-card .doc-count.green { color: var(--success); }
        .doctor-status-card .doc-count.red { color: var(--danger); }
        .doctor-status-card .doc-count.yellow { color: #d97706; }
        
        .dept-stats {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 12px;
            padding: 16px 20px;
        }
        
        .dept-stat {
            background: #f8fafc;
            border-radius: 12px;
            padding: 12px 16px;
            border: 1px solid var(--gray-light);
        }
        
        .dept-stat .dept-name {
            font-weight: 600;
            font-size: 13px;
            color: var(--dark);
        }
        
        .dept-stat .dept-counts {
            display: flex;
            gap: 16px;
            margin-top: 6px;
            font-size: 12px;
        }
        
        .dept-stat .dept-counts span {
            display: flex;
            align-items: center;
            gap: 4px;
        }
        
        .dept-stat .dept-counts .num { font-weight: 700; }
        
        .call-patient-display {
            text-align: center;
            padding: 30px;
        }
        
        .call-patient-display .patient-name-display {
            font-size: 48px;
            font-weight: 800;
            color: var(--dark);
            margin: 16px 0;
        }
        
        .call-patient-display .room-display {
            font-size: 32px;
            font-weight: 700;
            color: var(--primary);
        }
        
        .call-patient-display .details-display {
            font-size: 16px;
            color: var(--gray);
        }
        
        @media (max-width: 768px) {
            .queue-item {
                flex-direction: column;
                align-items: flex-start;
                gap: 8px;
            }
            .queue-item .actions {
                width: 100%;
                justify-content: flex-start;
            }
            .queue-controls {
                flex-direction: column;
                align-items: stretch;
            }
            .queue-tabs {
                overflow-x: auto;
                flex-wrap: nowrap;
            }
        }
        
        .panel-body::-webkit-scrollbar {
            width: 4px;
        }
        .panel-body::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 4px;
        }
        .panel-body::-webkit-scrollbar-thumb {
            background: var(--primary);
            border-radius: 4px;
        }
        
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(255,255,255,0.8);
            z-index: 2000;
            display: none;
            align-items: center;
            justify-content: center;
        }
        
        .loading-overlay.active { display: flex; }
        
        .spinner {
            width: 50px;
            height: 50px;
            border: 4px solid var(--gray-light);
            border-top-color: var(--primary);
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }
        
        @keyframes spin { to { transform: rotate(360deg); } }
        
        .toast-notification {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: white;
            border-radius: 12px;
            padding: 14px 22px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.15);
            z-index: 1100;
            display: flex;
            align-items: center;
            gap: 12px;
            border-left: 4px solid var(--success);
            animation: slideIn 0.3s ease-out;
        }
        
        .toast-error { border-left-color: var(--danger); }
    </style>
</head>
<body>

<div id="wrapper">
    <?php include('assets/inc/nav.php'); ?>
    <?php include('assets/inc/sidebar.php'); ?>

    <div class="content-page">
        <div class="container-fluid">

            <!-- Queue Header -->
            <div class="queue-header">
                <div class="row align-items-center">
                    <div class="col-md-8">
                        <h1><i class="fas fa-people-arrows me-3"></i> Live Patient Queue</h1>
                        <p>Real-time monitoring and management of patient flow across clinical departments.</p>
                    </div>
                    <div class="col-md-4 text-md-end mt-3 mt-md-0">
                        <div class="live-indicator">
                            <span class="live-dot"></span>
                            LIVE · <?php echo htmlspecialchars($current_date_display); ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Statistics Bar -->
            <div class="stats-bar" id="statsBar">
                <div class="stat-item">
                    <div class="number" id="statWaiting">0</div>
                    <div class="label">Total Waiting</div>
                </div>
                <div class="stat-item">
                    <div class="number yellow" id="statTriage">0</div>
                    <div class="label">In Triage</div>
                </div>
                <div class="stat-item">
                    <div class="number blue" id="statConsultation">0</div>
                    <div class="label">In Consultation</div>
                </div>
                <div class="stat-item">
                    <div class="number red" id="statEmergency">0</div>
                    <div class="label">Emergency Waiting</div>
                </div>
                <div class="stat-item">
                    <div class="number" id="statAvgWait">0</div>
                    <div class="label">Avg Wait (min)</div>
                </div>
                <div class="stat-item">
                    <div class="number" id="statMaxWait">0</div>
                    <div class="label">Max Wait (min)</div>
                </div>
            </div>

            <!-- Queue Controls -->
            <div class="queue-controls">
                <div class="search-box">
                    <i class="fas fa-search"></i>
                    <input type="text" id="queueSearch" placeholder="Search patient name, MRN, or phone..." autocomplete="off">
                    <div id="searchResults" style="display:none; position:absolute; top:100%; left:0; right:0; background:white; border-radius:12px; box-shadow:0 10px 30px rgba(0,0,0,0.15); z-index:100; max-height:300px; overflow-y:auto;"></div>
                </div>
                
                <div class="queue-tabs" id="queueTabs">
                    <button class="active" data-queue="all">All Queues</button>
                    <button data-queue="waiting">Waiting</button>
                    <button data-queue="triage">Triage</button>
                    <button data-queue="doctor">Doctor</button>
                    <button data-queue="emergency">Emergency</button>
                </div>
                
                <div style="display:flex; gap:8px; margin-left:auto;">
                    <button class="filter-badge" data-filter="all">All</button>
                    <button class="filter-badge" data-filter="red">🔴 Red</button>
                    <button class="filter-badge" data-filter="yellow">🟡 Yellow</button>
                    <button class="filter-badge" data-filter="green">🟢 Green</button>
                    <button class="filter-badge" data-filter="blue">🔵 Blue</button>
                </div>
                
                <button class="btn btn-primary btn-sm" onclick="refreshQueue()" style="border-radius:10px;">
                    <i class="fas fa-sync-alt"></i> Refresh
                </button>
            </div>

            <!-- Queue Grid -->
            <div class="queue-grid" id="queueGrid">
                <!-- Nurse Triage Queue -->
                <div class="queue-panel" id="nurseQueuePanel">
                    <div class="panel-header">
                        <h3><i class="fas fa-stethoscope me-2" style="color:var(--info);"></i> Nurse Triage Queue</h3>
                        <span class="count" id="nurseCount">0</span>
                    </div>
                    <div class="panel-body" id="nurseQueueBody">
                        <div class="text-center text-muted py-4">Loading triage queue...</div>
                    </div>
                </div>

                <!-- Doctor Consultation Queue -->
                <div class="queue-panel" id="doctorQueuePanel">
                    <div class="panel-header">
                        <h3><i class="fas fa-user-md me-2" style="color:var(--primary);"></i> Doctor Consultation Queue</h3>
                        <span class="count" id="doctorCount">0</span>
                    </div>
                    <div class="panel-body" id="doctorQueueBody">
                        <div class="text-center text-muted py-4">Loading doctor queue...</div>
                    </div>
                </div>

                <!-- Emergency Queue (Full Width) -->
                <div class="queue-panel" style="grid-column: 1 / -1;">
                    <div class="panel-header">
                        <h3><i class="fas fa-ambulance me-2" style="color:var(--danger);"></i> Emergency Queue</h3>
                        <span class="count" id="emergencyCount">0</span>
                    </div>
                    <div class="panel-body" id="emergencyQueueBody">
                        <div class="text-center text-muted py-4">Loading emergency queue...</div>
                    </div>
                </div>

                <!-- Active Consultations -->
                <div class="queue-panel" id="activePanel">
                    <div class="panel-header">
                        <h3><i class="fas fa-check-circle me-2" style="color:var(--success);"></i> Active Consultations</h3>
                        <span class="count" id="activeCount">0</span>
                    </div>
                    <div class="panel-body" id="activeBody">
                        <div class="text-center text-muted py-4">No active consultations...</div>
                    </div>
                </div>

                <!-- Doctor Status -->
                <div class="queue-panel" id="doctorStatusPanel">
                    <div class="panel-header">
                        <h3><i class="fas fa-user-md me-2" style="color:var(--primary);"></i> Doctor Status</h3>
                    </div>
                    <div class="doctor-status-grid" id="doctorStatusBody">
                        <div class="text-center text-muted py-4 w-100">Loading doctor status...</div>
                    </div>
                </div>
            </div>

            <!-- Department Statistics -->
            <div class="queue-panel" style="margin-top:24px;">
                <div class="panel-header">
                    <h3><i class="fas fa-building me-2" style="color:var(--primary);"></i> Department Queue Status</h3>
                </div>
                <div class="dept-stats" id="deptStatsBody">
                    <div class="text-center text-muted py-4">Loading department stats...</div>
                </div>
            </div>

        </div>
    </div>

    <?php include('assets/inc/footer.php'); ?>
</div>

<!-- Loading Overlay -->
<div id="loadingOverlay" class="loading-overlay">
    <div class="spinner"></div>
</div>

<!-- Call Patient Modal -->
<div class="modal fade" id="callModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-bullhorn me-2"></i>Call Patient</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="call-patient-display">
                    <div class="details-display" id="callDepartment">Department</div>
                    <div class="patient-name-display" id="callPatientName">Patient Name</div>
                    <div class="room-display" id="callRoom">Room</div>
                    <div class="details-display mt-3" id="callMRN">MRN</div>
                    <div class="mt-4">
                        <button class="btn btn-primary" onclick="confirmCall()"><i class="fas fa-check"></i> Confirm Call</button>
                        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="assets/js/vendor.min.js"></script>
<script src="assets/js/app.min.js"></script>

<script>
// =============================================================================
// GLOBALS
// =============================================================================
const branchId = <?php echo (int)$branch_id; ?>;
const csrfToken = <?php echo json_encode($csrf_token); ?>;
const ajaxUrl = window.location.pathname + '?ajax=queue_action';

let refreshInterval = null;
let currentFilter = 'all';
let currentQueueTab = 'all';
let callPatientId = null;
let callPatientName = '';
let callMRN = '';

// =============================================================================
// UTILITY FUNCTIONS
// =============================================================================
function showToast(message, type) {
    type = type || 'success';
    var toast = document.createElement('div');
    toast.className = 'toast-notification' + (type === 'error' ? ' toast-error' : '');
    toast.innerHTML = '<i class="fas fa-' + (type === 'error' ? 'exclamation-circle' : 'check-circle') + '"></i> <span>' + escapeHtml(message) + '</span>';
    document.body.appendChild(toast);
    setTimeout(function() { toast.remove(); }, 4000);
}

function escapeHtml(str) {
    if (!str) return '';
    return String(str).replace(/[&<>]/g, function(m) {
        return m === '&' ? '&amp;' : (m === '<' ? '&lt;' : '&gt;');
    });
}

function showLoading(show) {
    var overlay = document.getElementById('loadingOverlay');
    if (overlay) {
        overlay.style.display = show ? 'flex' : 'none';
    }
}

function getPriorityBadge(priority, isEmergency) {
    isEmergency = isEmergency || false;
    if (isEmergency) {
        return '<span class="priority-badge emergency"><i class="fas fa-exclamation-triangle me-1"></i>EMERGENCY</span>';
    }
    var map = {
        'red': '<span class="priority-badge red">🔴 Red</span>',
        'yellow': '<span class="priority-badge yellow">🟡 Yellow</span>',
        'green': '<span class="priority-badge green">🟢 Green</span>',
        'blue': '<span class="priority-badge blue">🔵 Blue</span>'
    };
    return map[priority] || '';
}

function getStatusBadge(status) {
    var map = {
        'checked_in': 'waiting',
        'in_triage': 'triage',
        'in_consultation': 'consultation'
    };
    var cls = map[status] || 'waiting';
    return '<span class="status-badge ' + cls + '">' + status.replace('_', ' ') + '</span>';
}

function getPriorityClass(priority) {
    var map = {
        'red': 'priority-red',
        'yellow': 'priority-yellow',
        'green': 'priority-green',
        'blue': 'priority-blue'
    };
    return map[priority] || '';
}

// =============================================================================
// QUEUE DATA FETCHING
// =============================================================================
function fetchQueueData() {
    showLoading(true);
    
    $.ajax({
        url: ajaxUrl,
        method: 'POST',
        dataType: 'json',
        data: {
            action: 'get_full_queue',
            branch_id: branchId,
            csrf_token: csrfToken
        },
        success: function(res) {
            showLoading(false);
            if (res.success && res.data) {
                renderQueueData(res.data);
            } else {
                showToast(res.message || 'Failed to load queue data', 'error');
            }
        },
        error: function(xhr, status, error) {
            showLoading(false);
            console.error('Queue fetch error:', error);
            showToast('Failed to load queue data - ' + error, 'error');
        }
    });
}

function renderQueueData(data) {
    // Update stats
    updateStats(data);
    
    // Render nurse queue
    renderNurseQueue(data.nurse_queue || []);
    
    // Render doctor queue
    renderDoctorQueue(data.doctor_queue || []);
    
    // Render emergency queue
    renderEmergencyQueue(data.emergency_queue || []);
    
    // Render active consultations
    renderActiveConsultations(data.active_consultations || []);
    
    // Render doctor status
    renderDoctorStatus(data.doctor_status || []);
    
    // Render department stats
    renderDepartmentStats(data.department_stats || []);
}

function updateStats(data) {
    var stats = data.waiting_stats || {};
    var totalWaiting = stats.total_waiting || 0;
    
    document.getElementById('statWaiting').textContent = totalWaiting;
    document.getElementById('statTriage').textContent = data.nurse_queue ? data.nurse_queue.length : 0;
    document.getElementById('statConsultation').textContent = data.active_consultations ? data.active_consultations.length : 0;
    document.getElementById('statEmergency').textContent = data.emergency_queue ? data.emergency_queue.length : 0;
    document.getElementById('statAvgWait').textContent = Math.round(stats.avg_wait || 0);
    document.getElementById('statMaxWait').textContent = Math.round(stats.max_wait || 0);
}

function renderNurseQueue(queue) {
    var container = document.getElementById('nurseQueueBody');
    var countEl = document.getElementById('nurseCount');
    
    countEl.textContent = queue.length;
    
    if (queue.length === 0) {
        container.innerHTML = '<div class="text-center text-muted py-4">✅ No patients waiting for triage</div>';
        return;
    }
    
    var html = '';
    for (var i = 0; i < queue.length; i++) {
        var item = queue[i];
        var priorityClass = getPriorityClass(item.triage_priority || item.appointment_priority);
        html += '<div class="queue-item ' + priorityClass + '">';
        html += '    <div class="patient-info">';
        html += '        <div class="patient-name">' + escapeHtml(item.patient_name);
        if (item.appointment_priority === 'stat') {
            html += '            <span class="priority-badge emergency" style="margin-left:8px;">STAT</span>';
        }
        html += '        </div>';
        html += '        <div class="patient-details">';
        html += '            <span class="mrn">' + escapeHtml(item.mr_number) + '</span>';
        html += '            <span>' + escapeHtml(item.phone || 'N/A') + '</span>';
        html += '            <span class="dept">' + escapeHtml(item.department_name) + '</span>';
        if (item.chief_complaint) {
            var cc = escapeHtml(item.chief_complaint);
            html += '            <span class="text-muted">' + (cc.length > 30 ? cc.substring(0, 30) + '...' : cc) + '</span>';
        }
        html += '        </div>';
        html += '    </div>';
        html += '    <div class="wait-time' + (item.waiting_minutes > 30 ? ' long' : '') + '">';
        html += '        <i class="far fa-clock"></i> ' + (item.waiting_minutes || 0) + ' min';
        html += '    </div>';
        html += '    <div class="actions">';
        html += '        <button class="btn btn-triage" onclick="movePatient(' + item.visit_id + ', \'triage\')">';
        html += '            <i class="fas fa-stethoscope"></i> Start Triage';
        html += '        </button>';
        html += '        <button class="btn btn-call" onclick="openCallModal(' + item.visit_id + ', \'' + escapeHtml(item.patient_name) + '\', \'' + escapeHtml(item.mr_number) + '\')">';
        html += '            <i class="fas fa-bullhorn"></i> Call';
        html += '        </button>';
        html += '    </div>';
        html += '</div>';
    }
    container.innerHTML = html;
}

function renderDoctorQueue(queue) {
    var container = document.getElementById('doctorQueueBody');
    var countEl = document.getElementById('doctorCount');
    
    countEl.textContent = queue.length;
    
    if (queue.length === 0) {
        container.innerHTML = '<div class="text-center text-muted py-4">✅ No patients waiting for doctor</div>';
        return;
    }
    
    var html = '';
    for (var i = 0; i < queue.length; i++) {
        var item = queue[i];
        var priorityClass = getPriorityClass(item.triage_priority);
        html += '<div class="queue-item ' + priorityClass + '">';
        html += '    <div class="patient-info">';
        html += '        <div class="patient-name">' + escapeHtml(item.patient_name);
        html += '            ' + getPriorityBadge(item.triage_priority);
        if (item.appointment_priority === 'stat') {
            html += '            <span class="priority-badge emergency" style="margin-left:8px;">STAT</span>';
        }
        html += '        </div>';
        html += '        <div class="patient-details">';
        html += '            <span class="mrn">' + escapeHtml(item.mr_number) + '</span>';
        html += '            <span>' + escapeHtml(item.phone || 'N/A') + '</span>';
        html += '            <span class="dept">' + escapeHtml(item.department_name) + '</span>';
        if (item.doctor_name) {
            html += '            <span>→ ' + escapeHtml(item.doctor_name) + '</span>';
        }
        html += '        </div>';
        html += '        <div class="patient-details" style="font-size:11px; color:var(--gray);">';
        html += '            <span>BP: ' + (item.blood_pressure_systolic || '?') + '/' + (item.blood_pressure_diastolic || '?') + '</span>';
        html += '            <span>HR: ' + (item.heart_rate || '?') + '</span>';
        html += '            <span>Temp: ' + (item.temperature_celsius || '?') + '°C</span>';
        html += '            <span>ESI: ' + (item.esi_level || '?') + '</span>';
        html += '        </div>';
        html += '    </div>';
        html += '    <div class="wait-time' + (item.waiting_minutes > 30 ? ' long' : '') + '">';
        html += '        <i class="far fa-clock"></i> ' + (item.waiting_minutes || 0) + ' min';
        html += '    </div>';
        html += '    <div class="actions">';
        html += '        <button class="btn btn-doctor" onclick="movePatient(' + item.visit_id + ', \'doctor\')">';
        html += '            <i class="fas fa-user-md"></i> Start Consult';
        html += '        </button>';
        html += '        <button class="btn btn-call" onclick="openCallModal(' + item.visit_id + ', \'' + escapeHtml(item.patient_name) + '\', \'' + escapeHtml(item.mr_number) + '\')">';
        html += '            <i class="fas fa-bullhorn"></i> Call';
        html += '        </button>';
        html += '    </div>';
        html += '</div>';
    }
    container.innerHTML = html;
}

function renderEmergencyQueue(queue) {
    var container = document.getElementById('emergencyQueueBody');
    var countEl = document.getElementById('emergencyCount');
    
    countEl.textContent = queue.length;
    
    if (queue.length === 0) {
        container.innerHTML = '<div class="text-center text-muted py-4">✅ No emergency patients in queue</div>';
        return;
    }
    
    var html = '';
    for (var i = 0; i < queue.length; i++) {
        var item = queue[i];
        html += '<div class="queue-item emergency">';
        html += '    <div class="patient-info">';
        html += '        <div class="patient-name">' + escapeHtml(item.patient_name);
        html += '            <span class="priority-badge emergency"><i class="fas fa-exclamation-triangle me-1"></i>EMERGENCY</span>';
        if (item.triage_priority) {
            html += '            ' + getPriorityBadge(item.triage_priority);
        }
        html += '        </div>';
        html += '        <div class="patient-details">';
        html += '            <span class="mrn">' + escapeHtml(item.mr_number) + '</span>';
        html += '            <span>' + escapeHtml(item.phone || 'N/A') + '</span>';
        html += '            <span class="dept">' + escapeHtml(item.department_name) + '</span>';
        html += '        </div>';
        html += '        <div class="patient-details" style="font-size:11px; color:var(--gray);">';
        html += '            <span>BP: ' + (item.blood_pressure_systolic || '?') + '/' + (item.blood_pressure_diastolic || '?') + '</span>';
        html += '            <span>HR: ' + (item.heart_rate || '?') + '</span>';
        html += '            <span>Temp: ' + (item.temperature_celsius || '?') + '°C</span>';
        html += '            <span>SpO2: ' + (item.oxygen_saturation || '?') + '%</span>';
        if (item.chief_complaint) {
            var cc = escapeHtml(item.chief_complaint);
            html += '            <span>' + (cc.length > 30 ? cc.substring(0, 30) + '...' : cc) + '</span>';
        }
        html += '        </div>';
        html += '    </div>';
        html += '    <div class="wait-time' + (item.waiting_minutes > 15 ? ' long' : '') + '">';
        html += '        <i class="far fa-clock"></i> ' + (item.waiting_minutes || 0) + ' min';
        html += '    </div>';
        html += '    <div class="actions">';
        html += '        <button class="btn btn-danger-sm" onclick="movePatient(' + item.visit_id + ', \'doctor\')">';
        html += '            <i class="fas fa-ambulance"></i> Rush to Doctor';
        html += '        </button>';
        html += '        <button class="btn btn-call" onclick="openCallModal(' + item.visit_id + ', \'' + escapeHtml(item.patient_name) + '\', \'' + escapeHtml(item.mr_number) + '\')">';
        html += '            <i class="fas fa-bullhorn"></i> Call';
        html += '        </button>';
        html += '    </div>';
        html += '</div>';
    }
    container.innerHTML = html;
}

function renderActiveConsultations(active) {
    var container = document.getElementById('activeBody');
    var countEl = document.getElementById('activeCount');
    
    countEl.textContent = active.length;
    
    if (active.length === 0) {
        container.innerHTML = '<div class="text-center text-muted py-4">No active consultations</div>';
        return;
    }
    
    var html = '';
    for (var i = 0; i < active.length; i++) {
        var item = active[i];
        html += '<div class="queue-item" style="border-left-color: var(--success); background: #ecfdf5;">';
        html += '    <div class="patient-info">';
        html += '        <div class="patient-name">' + escapeHtml(item.patient_name);
        html += '            <span class="status-badge consultation">In Progress</span>';
        html += '        </div>';
        html += '        <div class="patient-details">';
        html += '            <span class="mrn">' + escapeHtml(item.mr_number) + '</span>';
        html += '            <span class="dept">' + escapeHtml(item.department_name) + '</span>';
        html += '            <span>' + escapeHtml(item.doctor_name || 'N/A') + '</span>';
        if (item.chief_complaint) {
            var cc = escapeHtml(item.chief_complaint);
            html += '            <span class="text-muted">' + (cc.length > 30 ? cc.substring(0, 30) + '...' : cc) + '</span>';
        }
        html += '        </div>';
        html += '    </div>';
        html += '    <div class="wait-time">';
        html += '        <i class="far fa-clock"></i> ' + (item.consultation_duration || 0) + ' min';
        html += '    </div>';
        html += '    <div class="actions">';
        html += '        <button class="btn btn-complete" onclick="movePatient(' + item.visit_id + ', \'complete\')">';
        html += '            <i class="fas fa-check"></i> Complete';
        html += '        </button>';
        html += '    </div>';
        html += '</div>';
    }
    container.innerHTML = html;
}

function renderDoctorStatus(doctors) {
    var container = document.getElementById('doctorStatusBody');
    
    if (!doctors || doctors.length === 0) {
        container.innerHTML = '<div class="text-center text-muted py-4">No doctors available today</div>';
        return;
    }
    
    var html = '';
    for (var i = 0; i < doctors.length; i++) {
        var doc = doctors[i];
        var statusClass = doc.availability_status || 'free';
        var statusLabel = {
            'free': '🟢 Available',
            'available': '🟡 Partially Available',
            'busy': '🔴 Busy'
        }[statusClass] || '🟢 Available';
        
        html += '<div class="doctor-status-card">';
        html += '    <div class="doc-name">Dr. ' + escapeHtml(doc.doctor_name) + '</div>';
        html += '    <div class="doc-specialty">' + escapeHtml(doc.specialization) + '</div>';
        html += '    <div class="doc-status">';
        html += '        <span class="status-badge ' + statusClass + '">' + statusLabel + '</span>';
        html += '    </div>';
        html += '    <div style="display:flex; justify-content:center; gap:20px; margin-top:8px;">';
        html += '        <div><span class="doc-count ' + (doc.active_patients > 2 ? 'red' : 'green') + '">' + (doc.active_patients || 0) + '</span><br><small>Active</small></div>';
        html += '        <div><span class="doc-count ' + (doc.waiting_patients > 2 ? 'yellow' : 'green') + '">' + (doc.waiting_patients || 0) + '</span><br><small>Waiting</small></div>';
        html += '    </div>';
        html += '</div>';
    }
    container.innerHTML = html;
}

function renderDepartmentStats(depts) {
    var container = document.getElementById('deptStatsBody');
    
    if (!depts || depts.length === 0) {
        container.innerHTML = '<div class="text-center text-muted py-4">No department data available</div>';
        return;
    }
    
    var html = '';
    for (var i = 0; i < depts.length; i++) {
        var dept = depts[i];
        html += '<div class="dept-stat">';
        html += '    <div class="dept-name">' + escapeHtml(dept.department_name) + '</div>';
        html += '    <div class="dept-counts">';
        html += '        <span>🚑 <span class="num">' + (dept.emergency_count || 0) + '</span> Emergency</span>';
        html += '        <span>🩺 <span class="num">' + (dept.triage_waiting || 0) + '</span> Triage</span>';
        html += '        <span>👨‍⚕️ <span class="num">' + (dept.doctor_waiting || 0) + '</span> Doctor</span>';
        html += '        <span>✅ <span class="num">' + (dept.active_consultations || 0) + '</span> Active</span>';
        html += '    </div>';
        html += '</div>';
    }
    container.innerHTML = html;
}

// =============================================================================
// PATIENT MOVEMENT
// =============================================================================
function movePatient(visitId, target) {
    var actions = {
        'triage': { title: 'Send to Triage', text: 'Move patient to nurse triage for assessment?', btn: 'Start Triage' },
        'doctor': { title: 'Send to Doctor', text: 'Move patient to doctor for consultation?', btn: 'Start Consultation' },
        'complete': { title: 'Complete Consultation', text: 'Mark consultation as completed?', btn: 'Complete' }
    };
    
    var action = actions[target];
    if (!action) return;
    
    Swal.fire({
        title: action.title,
        text: action.text,
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: action.btn,
        confirmButtonColor: '#4361ee',
        cancelButtonText: 'Cancel'
    }).then(function(result) {
        if (result.isConfirmed) {
            showLoading(true);
            
            $.ajax({
                url: ajaxUrl,
                method: 'POST',
                dataType: 'json',
                data: {
                    action: 'move_patient',
                    visit_id: visitId,
                    target: target,
                    branch_id: branchId,
                    csrf_token: csrfToken
                },
                success: function(res) {
                    showLoading(false);
                    if (res.success) {
                        showToast(res.message);
                        fetchQueueData();
                    } else {
                        showToast(res.message || 'Failed to move patient', 'error');
                    }
                },
                error: function() {
                    showLoading(false);
                    showToast('Network error', 'error');
                }
            });
        }
    });
}

// =============================================================================
// CALL PATIENT
// =============================================================================
function openCallModal(visitId, patientName, mrn) {
    callPatientId = visitId;
    callPatientName = patientName;
    callMRN = mrn;
    
    // Get patient details
    $.ajax({
        url: ajaxUrl,
        method: 'POST',
        dataType: 'json',
        data: {
            action: 'call_patient',
            visit_id: visitId,
            room_number: '',
            branch_id: branchId,
            csrf_token: csrfToken
        },
        success: function(res) {
            if (res.success && res.patient) {
                document.getElementById('callPatientName').textContent = res.patient.first_name + ' ' + res.patient.last_name;
                document.getElementById('callMRN').textContent = 'MRN: ' + res.patient.mr_number;
                document.getElementById('callDepartment').textContent = res.patient.department_name || 'General';
                document.getElementById('callRoom').textContent = '📍 ' + (res.room || 'Consultation Room');
            }
        }
    });
    
    $('#callModal').modal('show');
}

function confirmCall() {
    $('#callModal').modal('hide');
    showToast('🔊 ' + callPatientName + ' has been called.', 'success');
    
    Swal.fire({
        icon: 'info',
        title: 'Patient Called',
        html: '<div style="font-size:32px;font-weight:800;">' + escapeHtml(callPatientName) + '</div>' +
               '<div style="font-size:18px;">Please proceed to the consultation room.</div>' +
               '<div style="font-size:14px;color:var(--gray);margin-top:8px;">MRN: ' + escapeHtml(callMRN) + '</div>',
        timer: 5000,
        timerProgressBar: true,
        confirmButtonColor: '#4361ee'
    });
}

// =============================================================================
// SEARCH
// =============================================================================
var searchDebounce = null;
var searchInput = document.getElementById('queueSearch');
var searchResults = document.getElementById('searchResults');

if (searchInput) {
    searchInput.addEventListener('input', function() {
        clearTimeout(searchDebounce);
        var query = this.value.trim();
        
        if (query.length < 2) {
            searchResults.style.display = 'none';
            return;
        }
        
        searchDebounce = setTimeout(function() {
            $.ajax({
                url: ajaxUrl,
                method: 'POST',
                dataType: 'json',
                data: {
                    action: 'search_queue',
                    query: query,
                    queue_type: currentQueueTab,
                    branch_id: branchId,
                    csrf_token: csrfToken
                },
                success: function(res) {
                    if (!res.success || !res.patients || res.patients.length === 0) {
                        searchResults.innerHTML = '<div class="p-3 text-center text-muted">No patients found.</div>';
                        searchResults.style.display = 'block';
                        return;
                    }
                    
                    var html = '';
                    for (var i = 0; i < res.patients.length; i++) {
                        var p = res.patients[i];
                        html += '<div class="p-3 border-bottom" style="cursor:pointer;" onclick="searchResultClick(' + p.visit_id + ', \'' + escapeHtml(p.patient_name) + '\')">';
                        html += '    <div class="fw-bold">' + escapeHtml(p.patient_name) + '</div>';
                        html += '    <div class="small text-muted">MRN: ' + escapeHtml(p.mr_number) + ' | ' + escapeHtml(p.phone || 'N/A') + ' | ' + getStatusBadge(p.status) + '</div>';
                        html += '    <div class="small text-muted">Waiting: ' + (p.waiting_minutes || 0) + ' min</div>';
                        html += '</div>';
                    }
                    searchResults.innerHTML = html;
                    searchResults.style.display = 'block';
                }
            });
        }, 300);
    });
}

function searchResultClick(visitId, patientName) {
    searchResults.style.display = 'none';
    searchInput.value = patientName;
    
    // Scroll to the patient in queue - highlight them
    var items = document.querySelectorAll('.queue-item');
    for (var i = 0; i < items.length; i++) {
        var item = items[i];
        if (item.textContent.indexOf(patientName) !== -1) {
            item.style.backgroundColor = '#fef3c7';
            item.scrollIntoView({ behavior: 'smooth', block: 'center' });
            setTimeout(function() {
                item.style.backgroundColor = '';
            }, 3000);
            break;
        }
    }
}

// Close search results on outside click
document.addEventListener('click', function(e) {
    if (searchInput && searchResults && !searchInput.contains(e.target) && !searchResults.contains(e.target)) {
        searchResults.style.display = 'none';
    }
});

// =============================================================================
// FILTERS & TABS
// =============================================================================
document.querySelectorAll('.filter-badge').forEach(function(btn) {
    btn.addEventListener('click', function() {
        document.querySelectorAll('.filter-badge').forEach(function(b) { b.classList.remove('active'); });
        this.classList.add('active');
        currentFilter = this.dataset.filter;
        applyFilter();
    });
});

document.querySelectorAll('#queueTabs button').forEach(function(btn) {
    btn.addEventListener('click', function() {
        document.querySelectorAll('#queueTabs button').forEach(function(b) { b.classList.remove('active'); });
        this.classList.add('active');
        currentQueueTab = this.dataset.queue;
        
        // Show/hide panels
        var panels = {
            'all': ['nurseQueuePanel', 'doctorQueuePanel', 'emergencyQueuePanel', 'activePanel', 'doctorStatusPanel'],
            'waiting': ['nurseQueuePanel', 'doctorQueuePanel'],
            'triage': ['nurseQueuePanel'],
            'doctor': ['doctorQueuePanel'],
            'emergency': ['emergencyQueuePanel']
        };
        
        var showPanels = panels[currentQueueTab] || panels['all'];
        document.querySelectorAll('.queue-panel').forEach(function(p) {
            var shouldShow = false;
            for (var i = 0; i < showPanels.length; i++) {
                if (p.id === showPanels[i]) {
                    shouldShow = true;
                    break;
                }
            }
            p.style.display = shouldShow ? '' : 'none';
        });
    });
});

function applyFilter() {
    var items = document.querySelectorAll('.queue-item');
    for (var i = 0; i < items.length; i++) {
        var item = items[i];
        if (currentFilter === 'all') {
            item.style.display = '';
            continue;
        }
        
        var priorityMap = {
            'red': 'priority-red',
            'yellow': 'priority-yellow',
            'green': 'priority-green',
            'blue': 'priority-blue'
        };
        
        if (currentFilter === 'emergency') {
            item.style.display = item.className.indexOf('emergency') !== -1 ? '' : 'none';
        } else if (item.className.indexOf(priorityMap[currentFilter]) !== -1) {
            item.style.display = '';
        } else {
            item.style.display = 'none';
        }
    }
}

// =============================================================================
// REFRESH & AUTO-UPDATE
// =============================================================================
function refreshQueue() {
    fetchQueueData();
    showToast('Queue refreshed', 'success');
}

function startAutoRefresh() {
    if (refreshInterval) clearInterval(refreshInterval);
    refreshInterval = setInterval(function() {
        if (!document.hidden) {
            fetchQueueData();
        }
    }, 30000);
}

// =============================================================================
// INITIALIZATION
// =============================================================================
$(document).ready(function() {
    fetchQueueData();
    startAutoRefresh();
});

// Clean up interval on page unload
window.addEventListener('beforeunload', function() {
    if (refreshInterval) clearInterval(refreshInterval);
});
</script>
</body>
</html>