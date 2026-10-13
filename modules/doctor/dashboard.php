
<?php
/**
 * ======================================================================
 * DOCTOR DASHBOARD - HMS ERP (Production v9.0)
 * All issues fixed:
 * - Added call_patient functionality
 * - Fixed specialization session variable
 * - Added rate limiting
 * - Fixed status validation
 * - Added loading states
 * - Combined KPI queries for performance
 * - Added pending radiology to KPIs
 * - Added "Last Updated" timestamp
 * - Fixed prescription status query
 * - Added call button to queue
 * ======================================================================
 */

session_start();

// ----------------------------------------------------------------------
// 1. BOOTSTRAP & SECURITY
// ----------------------------------------------------------------------
require_once __DIR__ . '/../../config/db_connection.php';
require_once __DIR__ . '/../../includes/auth_helper.php';

date_default_timezone_set('Africa/Addis_Ababa');
if ($pdo) {
    $pdo->exec("SET time_zone = '+03:00'");
}

$user_id = (int)($_SESSION['user_id'] ?? 0);
$branch_id = (int)($_SESSION['branch_id'] ?? 0);
$is_global_admin = ($_SESSION['user_type'] ?? '') === 'super_admin';
$first_name = $_SESSION['first_name'] ?? '';
$last_name = $_SESSION['last_name'] ?? '';
$doctor_name = trim($first_name . ' ' . $last_name);

if (empty($doctor_name)) {
    $doctor_name = $_SESSION['username'] ?? 'Doctor';
}

if ($user_id <= 0) {
    header('Location: ../../index.php');
    exit;
}

if (!$is_global_admin && !user_has_permission($pdo, 'doctor_dashboard_access', $user_id)) {
    header('Location: dashboard.php?error=unauthorized');
    exit;
}

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// ----------------------------------------------------------------------
// 2. HELPER FUNCTIONS
// ----------------------------------------------------------------------
function jsonResponse($success, $message, $data = null, $code = 200) {
    if (ob_get_length()) ob_clean();
    http_response_code($code);
    header('Content-Type: application/json');
    header('X-Content-Type-Options: nosniff');
    $response = ['success' => $success, 'message' => $message];
    if ($data !== null) {
        $response = array_merge($response, $data);
    }
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

function escapeHtml($str) {
    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}

function getPatientAge($dob) {
    if (empty($dob)) return 'N/A';
    try {
        $birthDate = new DateTime($dob);
        $today = new DateTime();
        return $birthDate->diff($today)->y;
    } catch (Exception $e) {
        return 'N/A';
    }
}

function getStatusBadge($status) {
    if (!$status) return '<span class="badge bg-secondary">N/A</span>';
    
    $badgeMap = [
        'pending_payment' => 'warning',
        'unpaid' => 'warning',
        'paid' => 'success',
        'completed' => 'success',
        'cancelled' => 'danger',
        'pending' => 'warning',
        'approved' => 'success',
        'collected' => 'info',
        'in_progress' => 'primary',
        'active' => 'success',
        'dispensed' => 'success',
        'scheduled' => 'info',
        'admitted' => 'primary',
        'discharged' => 'success',
        'deceased' => 'danger',
        'ama' => 'danger',
        'transferred' => 'info',
        'partial' => 'warning',
        'refunded' => 'info',
        'waived' => 'secondary',
        'stable' => 'success',
        'guarded' => 'warning',
        'critical' => 'danger',
        'serious' => 'danger',
        'checked_in' => 'info',
        'in_triage' => 'warning',
        'in_consultation' => 'primary',
        'awaiting_lab' => 'warning',
        'lab_ready' => 'info',
        'awaiting_pharmacy' => 'warning',
        'no_show' => 'secondary'
    ];
    
    $cls = $badgeMap[$status] ?? 'secondary';
    $label = str_replace('_', ' ', $status);
    $label = ucwords($label);
    return "<span class='badge bg-{$cls}'>{$label}</span>";
}

// Rate limiting function
function checkRateLimit($pdo, $user_id, $action, $limit = 60, $window = 60) {
    $sql = "SELECT COUNT(*) FROM rate_limits 
            WHERE user_id = :user_id 
            AND action = :action 
            AND created_at > DATE_SUB(NOW(), INTERVAL :window SECOND)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':user_id' => $user_id,
        ':action' => $action,
        ':window' => $window
    ]);
    $count = (int)$stmt->fetchColumn();
    
    if ($count >= $limit) {
        return false;
    }
    
    // Log this request
    $insert = $pdo->prepare("INSERT INTO rate_limits (user_id, action, created_at) VALUES (:user_id, :action, NOW())");
    $insert->execute([':user_id' => $user_id, ':action' => $action]);
    
    return true;
}

// Create rate_limits table if not exists (run once)
function ensureRateLimitsTable($pdo) {
    $sql = "CREATE TABLE IF NOT EXISTS rate_limits (
        id int(10) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        user_id int(10) UNSIGNED NOT NULL,
        action varchar(50) NOT NULL,
        created_at timestamp NOT NULL DEFAULT current_timestamp(),
        INDEX idx_user_action (user_id, action, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    $pdo->exec($sql);
}

// ----------------------------------------------------------------------
// 3. AJAX HANDLERS (Real-time endpoints)
// ----------------------------------------------------------------------
if (isset($_GET['ajax']) && $_GET['ajax'] === 'doctor_action') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(false, 'Invalid request method');
    }
    
    $action = $_POST['action'] ?? '';
    $incoming_token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($csrf_token, $incoming_token)) {
        jsonResponse(false, 'Security token mismatch');
    }

    // Ensure rate limits table exists
    ensureRateLimitsTable($pdo);

    // Apply rate limiting for write actions
    $write_actions = ['call_patient', 'update_appointment_status', 'acknowledge_referral', 'review_lab_result', 'mark_notification_read'];
    if (in_array($action, $write_actions)) {
        if (!checkRateLimit($pdo, $user_id, $action, 30, 60)) {
            jsonResponse(false, 'Too many requests. Please wait a moment.');
        }
    }

    try {
        // ---------- 3.1 Fetch KPI Metrics (Combined Query for Performance) ----------
        if ($action === 'fetch_kpis') {
            $params = [':doctor_id' => $user_id, ':user_id' => $user_id];
            
            // Build branch condition
            $branch_condition = '';
            if (!$is_global_admin && $branch_id > 0) {
                $branch_condition = " AND branch_id = :branch_id";
                $params[':branch_id'] = $branch_id;
            }
            
            // Combined query for all KPIs
            $sql = "
                SELECT 
                    (SELECT COUNT(*) FROM patient_visits pv
                     INNER JOIN appointments a ON pv.id = a.visit_id
                     WHERE a.doctor_id = :doctor_id
                       AND (pv.visit_date = CURDATE() OR DATE(pv.checked_in_at) = CURDATE())
                       AND pv.queue_status IN ('waiting', 'called')
                       AND pv.status IN ('checked_in', 'pending')
                       AND pv.deleted_at IS NULL
                       {$branch_condition}) AS waiting_queue,
                       
                    (SELECT COUNT(*) FROM admissions
                     WHERE doctor_id = :doctor_id
                       AND status = 'admitted'
                       AND discharged_at IS NULL
                       AND deleted_at IS NULL
                       {$branch_condition}) AS active_inpatients,
                       
                    (SELECT COUNT(DISTINCT ltr.id) FROM lab_test_results ltr
                     INNER JOIN lab_orders lo ON ltr.lab_order_id = lo.id
                     WHERE lo.doctor_id = :doctor_id
                       AND ltr.is_critical = 1
                       AND ltr.doctor_reviewed_at IS NULL
                       AND lo.status = 'completed'
                       AND ltr.deleted_at IS NULL
                       {$branch_condition}) AS critical_diagnostics,
                       
                    (SELECT COUNT(*) FROM patient_referrals
                     WHERE referred_to_user_id = :doctor_id
                       AND status = 'pending'
                       AND deleted_at IS NULL
                       {$branch_condition}) AS pending_referrals,
                       
                    (SELECT COUNT(*) FROM prescriptions
                     WHERE doctor_id = :doctor_id
                       AND status IN ('pending_payment', 'active', 'partial')
                       AND deleted_at IS NULL
                       {$branch_condition}) AS pending_prescriptions,
                       
                    (SELECT COUNT(*) FROM procedure_orders
                     WHERE doctor_id = :doctor_id
                       AND execution_status IN ('scheduled', 'pending_payment')
                       AND deleted_at IS NULL
                       {$branch_condition}) AS scheduled_procedures,
                       
                    (SELECT COUNT(*) FROM radiology_orders
                     WHERE doctor_id = :doctor_id
                       AND status IN ('pending_payment', 'scheduled')
                       AND deleted_at IS NULL
                       {$branch_condition}) AS pending_radiology,
                       
                    (SELECT COUNT(*) FROM admissions
                     WHERE doctor_id = :doctor_id
                       AND status = 'admitted'
                       AND discharge_summary IS NULL
                       AND deleted_at IS NULL
                       {$branch_condition}) AS pending_discharge,
                       
                    (SELECT COUNT(*) FROM notifications_tracker
                     WHERE user_id = :user_id
                       AND is_read = 0
                       AND deleted_at IS NULL
                       {$branch_condition}) AS unread_notifications
            ";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $metrics = $stmt->fetch(PDO::FETCH_ASSOC);
            
            jsonResponse(true, '', ['metrics' => $metrics]);
        }

        // ---------- 3.2 Fetch Master Patient Queue ----------
        if ($action === 'fetch_queue') {
            $params = [':doctor_id' => $user_id];
            $branch_condition = '';
            if (!$is_global_admin && $branch_id > 0) {
                $branch_condition = " AND a.branch_id = :branch_id";
                $params[':branch_id'] = $branch_id;
            }
            
            $sql = "
                SELECT 
                    pv.id AS visit_id,
                    pv.patient_id,
                    a.id AS appointment_id,
                    a.appointment_time,
                    pv.status AS visit_status,
                    a.status AS appointment_status,
                    CONCAT(p.first_name, ' ', COALESCE(p.middle_name, ''), ' ', p.last_name) AS patient_name,
                    p.mr_number,
                    p.date_of_birth,
                    COALESCE(tr.priority, 'green') AS triage_priority,
                    tr.chief_complaint,
                    tr.triage_notes,
                    pv.queue_status,
                    pv.called_at,
                    pv.consultation_started_at
                FROM appointments a
                INNER JOIN patient_visits pv ON a.visit_id = pv.id
                INNER JOIN patients p ON a.patient_id = p.id AND p.deleted_at IS NULL
                LEFT JOIN triage_records tr ON tr.visit_id = pv.id AND tr.deleted_at IS NULL
                WHERE a.doctor_id = :doctor_id
                  AND (a.appointment_date = CURDATE() OR DATE(pv.checked_in_at) = CURDATE())
                  AND a.status NOT IN ('completed', 'cancelled')
                  AND a.deleted_at IS NULL
                  AND pv.deleted_at IS NULL
                  {$branch_condition}
                ORDER BY FIELD(COALESCE(tr.priority, 'green'), 'red', 'yellow', 'green', 'blue'), 
                         CASE WHEN pv.queue_status = 'called' THEN 0 ELSE 1 END,
                         a.appointment_time ASC
                LIMIT 20
            ";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $queue = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (!is_array($queue)) $queue = [];

            foreach ($queue as &$row) {
                $row['age'] = $row['date_of_birth'] ? getPatientAge($row['date_of_birth']) : 'N/A';
                $row['waiting_time'] = $row['appointment_time'] ? 
                    (new DateTime())->diff(new DateTime($row['appointment_time']))->format('%i') . ' min' : 'N/A';
                $row['status_display'] = $row['queue_status'] === 'called' ? 'Called' : 
                                         ($row['consultation_started_at'] ? 'In Progress' : 'Waiting');
            }
            jsonResponse(true, '', ['queue' => $queue]);
        }

        // ---------- 3.3 Call Patient (FIXED) ----------
        if ($action === 'call_patient') {
            $visit_id = (int)($_POST['visit_id'] ?? 0);
            if ($visit_id <= 0) {
                jsonResponse(false, 'Invalid visit ID');
            }

            try {
                $pdo->beginTransaction();
                
                // Check if patient exists and is waiting
                $check = $pdo->prepare("SELECT id, queue_status, patient_id FROM patient_visits WHERE id = :id AND queue_status IN ('waiting', 'called') AND deleted_at IS NULL");
                $check->execute([':id' => $visit_id]);
                $visit = $check->fetch(PDO::FETCH_ASSOC);
                
                if (!$visit) {
                    $pdo->rollBack();
                    jsonResponse(false, 'Patient not found or already in room');
                }
                
                if ($visit['queue_status'] === 'called') {
                    $pdo->rollBack();
                    jsonResponse(false, 'Patient has already been called');
                }
                
                // Update visit status
                $sql = "UPDATE patient_visits 
                        SET queue_status = 'called', 
                            called_at = NOW(),
                            called_by_user_id = :user_id,
                            updated_at = NOW() 
                        WHERE id = :id";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([':id' => $visit_id, ':user_id' => $user_id]);
                
                // Update appointment status
                $appt_stmt = $pdo->prepare("UPDATE appointments SET status = 'in_progress', updated_at = NOW() WHERE visit_id = :id AND status != 'in_progress'");
                $appt_stmt->execute([':id' => $visit_id]);
                
                // Generate notification for nurse (optional)
                $nurse_stmt = $pdo->prepare("SELECT id FROM users WHERE user_type = 'nurse' AND branch_id = :branch_id AND is_active = 1 LIMIT 1");
                $nurse_stmt->execute([':branch_id' => $branch_id]);
                $nurse = $nurse_stmt->fetch(PDO::FETCH_ASSOC);
                if ($nurse) {
                    $patient_stmt = $pdo->prepare("SELECT first_name, last_name FROM patients WHERE id = :id");
                    $patient_stmt->execute([':id' => $visit['patient_id']]);
                    $patient = $patient_stmt->fetch(PDO::FETCH_ASSOC);
                    $patient_name = trim($patient['first_name'] . ' ' . $patient['last_name']);
                    
                    generateNotification(
                        $pdo,
                        $nurse['id'],
                        'Patient Called for Consultation',
                        'Patient ' . $patient_name . ' has been called for consultation by Dr. ' . $doctor_name,
                        'system_notice',
                        $branch_id
                    );
                }
                
                $pdo->commit();
                jsonResponse(true, 'Patient called successfully');
            } catch (PDOException $e) {
                $pdo->rollBack();
                jsonResponse(false, 'Database error: ' . $e->getMessage());
            }
        }

        // ---------- 3.4 Fetch Critical Lab Items ----------
        if ($action === 'fetch_critical_labs') {
            $params = [':doctor_id' => $user_id];
            $branch_condition = '';
            if (!$is_global_admin && $branch_id > 0) {
                $branch_condition = " AND lo.branch_id = :branch_id";
                $params[':branch_id'] = $branch_id;
            }
            
            $sql = "
                SELECT 
                    ltr.id AS result_id,
                    ltr.test_name,
                    ltr.result_numeric,
                    ltr.result_text,
                    ltr.result_unit,
                    ltr.reference_range_text,
                    ltr.is_critical,
                    lo.order_number,
                    CONCAT(p.first_name, ' ', COALESCE(p.middle_name, ''), ' ', p.last_name) AS patient_name,
                    p.mr_number,
                    p.id AS patient_id,
                    p.date_of_birth
                FROM lab_test_results ltr
                INNER JOIN lab_orders lo ON ltr.lab_order_id = lo.id
                INNER JOIN patients p ON lo.patient_id = p.id AND p.deleted_at IS NULL
                WHERE lo.doctor_id = :doctor_id
                  AND ltr.is_critical = 1
                  AND ltr.doctor_reviewed_at IS NULL
                  AND lo.status = 'completed'
                  AND ltr.deleted_at IS NULL
                  {$branch_condition}
                ORDER BY lo.order_date DESC LIMIT 10
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $critical_labs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (!is_array($critical_labs)) $critical_labs = [];
            
            foreach ($critical_labs as &$lab) {
                $lab['age'] = $lab['date_of_birth'] ? getPatientAge($lab['date_of_birth']) : 'N/A';
            }
            jsonResponse(true, '', ['critical_labs' => $critical_labs]);
        }

        // ---------- 3.5 Fetch Pending Referrals ----------
        if ($action === 'fetch_referrals') {
            $params = [':doctor_id' => $user_id];
            $branch_condition = '';
            if (!$is_global_admin && $branch_id > 0) {
                $branch_condition = " AND pr.branch_id = :branch_id";
                $params[':branch_id'] = $branch_id;
            }
            
            $sql = "
                SELECT 
                    pr.id AS referral_id,
                    pr.referral_reason,
                    pr.created_at,
                    CONCAT(u_from.first_name, ' ', COALESCE(u_from.middle_name, ''), ' ', u_from.last_name) AS referring_doctor,
                    CONCAT(p.first_name, ' ', COALESCE(p.middle_name, ''), ' ', p.last_name) AS patient_name,
                    p.mr_number,
                    p.id AS patient_id,
                    p.date_of_birth
                FROM patient_referrals pr
                INNER JOIN users u_from ON pr.referred_from_user_id = u_from.id
                INNER JOIN patients p ON pr.patient_id = p.id AND p.deleted_at IS NULL
                WHERE pr.referred_to_user_id = :doctor_id
                  AND pr.status = 'pending'
                  AND pr.deleted_at IS NULL
                  {$branch_condition}
                ORDER BY pr.created_at DESC LIMIT 10
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $referrals = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (!is_array($referrals)) $referrals = [];
            
            foreach ($referrals as &$ref) {
                $ref['age'] = $ref['date_of_birth'] ? getPatientAge($ref['date_of_birth']) : 'N/A';
            }
            jsonResponse(true, '', ['referrals' => $referrals]);
        }

        // ---------- 3.6 Acknowledge Referral ----------
        if ($action === 'acknowledge_referral') {
            $referral_id = (int)($_POST['referral_id'] ?? 0);
            if ($referral_id <= 0) jsonResponse(false, 'Invalid referral ID');
            
            try {
                $pdo->beginTransaction();
                $check_sql = "SELECT id, referred_from_user_id, patient_id FROM patient_referrals WHERE id = :id AND referred_to_user_id = :doctor_id AND status = 'pending' AND deleted_at IS NULL";
                $params = [':id' => $referral_id, ':doctor_id' => $user_id];
                $check_stmt = $pdo->prepare($check_sql);
                $check_stmt->execute($params);
                $referral = $check_stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$referral) {
                    $pdo->rollBack();
                    jsonResponse(false, 'Referral not found or already acknowledged');
                }
                
                $update_sql = "UPDATE patient_referrals SET status = 'accepted', updated_at = NOW() WHERE id = :id";
                $update_stmt = $pdo->prepare($update_sql);
                $update_stmt->execute([':id' => $referral_id]);
                
                // Notify the referring doctor
                generateNotification(
                    $pdo,
                    $referral['referred_from_user_id'],
                    'Referral Accepted',
                    'Dr. ' . $doctor_name . ' has accepted your referral for patient #' . $referral['patient_id'],
                    'system_notice',
                    $branch_id
                );
                
                $pdo->commit();
                jsonResponse(true, 'Referral acknowledged successfully');
            } catch (PDOException $e) {
                $pdo->rollBack();
                jsonResponse(false, 'Database error: ' . $e->getMessage());
            }
        }

        // ---------- 3.7 Review Lab Result ----------
        if ($action === 'review_lab_result') {
            $result_id = (int)($_POST['result_id'] ?? 0);
            if ($result_id <= 0) jsonResponse(false, 'Invalid result ID');
            
            try {
                $pdo->beginTransaction();
                $check_sql = "
                    SELECT ltr.id 
                    FROM lab_test_results ltr
                    INNER JOIN lab_orders lo ON ltr.lab_order_id = lo.id
                    WHERE ltr.id = :result_id 
                      AND lo.doctor_id = :doctor_id
                      AND ltr.doctor_reviewed_at IS NULL
                      AND ltr.deleted_at IS NULL
                ";
                $params = [':result_id' => $result_id, ':doctor_id' => $user_id];
                if (!$is_global_admin && $branch_id > 0) {
                    $check_sql .= " AND lo.branch_id = :branch_id";
                    $params[':branch_id'] = $branch_id;
                }
                $check_stmt = $pdo->prepare($check_sql);
                $check_stmt->execute($params);
                if (!$check_stmt->fetch()) {
                    $pdo->rollBack();
                    jsonResponse(false, 'Lab result not found or already reviewed');
                }
                
                $update_sql = "UPDATE lab_test_results SET doctor_reviewed_at = NOW(), doctor_reviewed_by = :doctor_id WHERE id = :id";
                $update_stmt = $pdo->prepare($update_sql);
                $update_stmt->execute([':doctor_id' => $user_id, ':id' => $result_id]);
                
                $pdo->commit();
                jsonResponse(true, 'Lab result marked as reviewed');
            } catch (PDOException $e) {
                $pdo->rollBack();
                jsonResponse(false, 'Database error: ' . $e->getMessage());
            }
        }

        // ---------- 3.8 Update Appointment Status ----------
        if ($action === 'update_appointment_status') {
            $appointment_id = (int)($_POST['appointment_id'] ?? 0);
            $new_status = $_POST['status'] ?? '';
            
            if ($appointment_id <= 0 || empty($new_status)) {
                jsonResponse(false, 'Invalid parameters');
            }
            
            $valid_statuses = ['scheduled', 'confirmed', 'checked_in', 'in_progress', 'completed', 'cancelled', 'no_show', 'rescheduled'];
            if (!in_array($new_status, $valid_statuses)) {
                jsonResponse(false, 'Invalid status');
            }
            
            try {
                $pdo->beginTransaction();
                $check_sql = "SELECT id, visit_id, patient_id FROM appointments WHERE id = :id AND doctor_id = :doctor_id AND deleted_at IS NULL";
                $check_stmt = $pdo->prepare($check_sql);
                $check_stmt->execute([':id' => $appointment_id, ':doctor_id' => $user_id]);
                $appt = $check_stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$appt) {
                    $pdo->rollBack();
                    jsonResponse(false, 'Appointment not found or not assigned to you');
                }
                
                // Update appointment
                $update_sql = "UPDATE appointments SET status = :status, updated_at = NOW() WHERE id = :id";
                $update_stmt = $pdo->prepare($update_sql);
                $update_stmt->execute([':status' => $new_status, ':id' => $appointment_id]);
                
                // Update visit status if needed
                if ($appt['visit_id']) {
                    $visit_status_map = [
                        'checked_in' => 'in_triage',
                        'in_progress' => 'in_consultation',
                        'completed' => 'completed'
                    ];
                    if (isset($visit_status_map[$new_status])) {
                        $visit_update = "UPDATE patient_visits SET status = :status, updated_at = NOW() WHERE id = :id";
                        $visit_stmt = $pdo->prepare($visit_update);
                        $visit_stmt->execute([':status' => $visit_status_map[$new_status], ':id' => $appt['visit_id']]);
                    }
                }
                
                $pdo->commit();
                jsonResponse(true, 'Appointment status updated successfully');
            } catch (PDOException $e) {
                $pdo->rollBack();
                jsonResponse(false, 'Database error: ' . $e->getMessage());
            }
        }

        // ---------- 3.9 Fetch Active Inpatients ----------
        if ($action === 'fetch_inpatients') {
            $params = [':doctor_id' => $user_id];
            $branch_condition = '';
            if (!$is_global_admin && $branch_id > 0) {
                $branch_condition = " AND a.branch_id = :branch_id";
                $params[':branch_id'] = $branch_id;
            }
            
            $sql = "
                SELECT 
                    a.id AS admission_id,
                    a.admission_number,
                    a.admitted_at,
                    a.condition_status,
                    a.primary_diagnosis,
                    CONCAT(p.first_name, ' ', COALESCE(p.middle_name, ''), ' ', p.last_name) AS patient_name,
                    p.mr_number,
                    p.id AS patient_id,
                    p.date_of_birth,
                    p.gender,
                    b.bed_number,
                    w.name AS ward_name,
                    DATEDIFF(NOW(), a.admitted_at) AS length_of_stay
                FROM admissions a
                INNER JOIN patients p ON a.patient_id = p.id AND p.deleted_at IS NULL
                LEFT JOIN beds b ON a.bed_id = b.id
                LEFT JOIN wards w ON b.ward_id = w.id
                WHERE a.doctor_id = :doctor_id
                  AND a.status = 'admitted'
                  AND a.discharged_at IS NULL
                  AND a.deleted_at IS NULL
                  {$branch_condition}
                ORDER BY a.condition_status = 'critical' DESC, 
                         a.condition_status = 'serious' DESC, 
                         a.admitted_at ASC
                LIMIT 20
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $inpatients = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (!is_array($inpatients)) $inpatients = [];
            
            foreach ($inpatients as &$ip) {
                $ip['age'] = $ip['date_of_birth'] ? getPatientAge($ip['date_of_birth']) : 'N/A';
            }
            jsonResponse(true, '', ['inpatients' => $inpatients]);
        }

        // ---------- 3.10 Fetch Pending Prescriptions ----------
        if ($action === 'fetch_prescriptions') {
            $params = [':doctor_id' => $user_id];
            $branch_condition = '';
            if (!$is_global_admin && $branch_id > 0) {
                $branch_condition = " AND pr.branch_id = :branch_id";
                $params[':branch_id'] = $branch_id;
            }
            
            $sql = "
                SELECT 
                    pr.id AS prescription_id,
                    pr.prescription_number,
                    pr.prescription_date,
                    pr.status,
                    CONCAT(p.first_name, ' ', COALESCE(p.middle_name, ''), ' ', p.last_name) AS patient_name,
                    p.mr_number,
                    p.id AS patient_id,
                    (SELECT COUNT(*) FROM prescription_items WHERE prescription_id = pr.id AND deleted_at IS NULL) AS item_count
                FROM prescriptions pr
                INNER JOIN patients p ON pr.patient_id = p.id AND p.deleted_at IS NULL
                WHERE pr.doctor_id = :doctor_id
                  AND pr.status IN ('pending_payment', 'active', 'partial')
                  AND pr.deleted_at IS NULL
                  {$branch_condition}
                ORDER BY pr.prescription_date DESC LIMIT 10
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $prescriptions = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (!is_array($prescriptions)) $prescriptions = [];
            jsonResponse(true, '', ['prescriptions' => $prescriptions]);
        }

        // ---------- 3.11 Fetch Scheduled Procedures ----------
        if ($action === 'fetch_procedures') {
            $params = [':doctor_id' => $user_id];
            $branch_condition = '';
            if (!$is_global_admin && $branch_id > 0) {
                $branch_condition = " AND po.branch_id = :branch_id";
                $params[':branch_id'] = $branch_id;
            }
            
            $sql = "
                SELECT 
                    po.id AS procedure_id,
                    po.procedure_number,
                    po.procedure_name,
                    po.priority,
                    po.scheduled_date,
                    po.scheduled_time,
                    po.execution_status,
                    CONCAT(p.first_name, ' ', COALESCE(p.middle_name, ''), ' ', p.last_name) AS patient_name,
                    p.mr_number,
                    p.id AS patient_id
                FROM procedure_orders po
                INNER JOIN patients p ON po.patient_id = p.id AND p.deleted_at IS NULL
                WHERE po.doctor_id = :doctor_id
                  AND po.execution_status IN ('scheduled', 'pending_payment')
                  AND po.deleted_at IS NULL
                  {$branch_condition}
                ORDER BY po.scheduled_date ASC, po.scheduled_time ASC LIMIT 10
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $procedures = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (!is_array($procedures)) $procedures = [];
            jsonResponse(true, '', ['procedures' => $procedures]);
        }

        // ---------- 3.12 Fetch Pending Discharges ----------
        if ($action === 'fetch_discharges') {
            $params = [':doctor_id' => $user_id];
            $branch_condition = '';
            if (!$is_global_admin && $branch_id > 0) {
                $branch_condition = " AND a.branch_id = :branch_id";
                $params[':branch_id'] = $branch_id;
            }
            
            $sql = "
                SELECT 
                    a.id AS admission_id,
                    a.admission_number,
                    a.admitted_at,
                    a.condition_status,
                    a.primary_diagnosis,
                    a.discharge_summary,
                    CONCAT(p.first_name, ' ', COALESCE(p.middle_name, ''), ' ', p.last_name) AS patient_name,
                    p.mr_number,
                    p.id AS patient_id,
                    p.date_of_birth,
                    DATEDIFF(NOW(), a.admitted_at) AS length_of_stay
                FROM admissions a
                INNER JOIN patients p ON a.patient_id = p.id AND p.deleted_at IS NULL
                WHERE a.doctor_id = :doctor_id
                  AND a.status = 'admitted'
                  AND a.discharge_summary IS NULL
                  AND a.discharged_at IS NULL
                  AND a.deleted_at IS NULL
                  {$branch_condition}
                ORDER BY a.admitted_at ASC LIMIT 10
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $discharges = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (!is_array($discharges)) $discharges = [];
            
            foreach ($discharges as &$d) {
                $d['age'] = $d['date_of_birth'] ? getPatientAge($d['date_of_birth']) : 'N/A';
            }
            jsonResponse(true, '', ['discharges' => $discharges]);
        }

        // ---------- 3.13 Fetch Notifications ----------
        if ($action === 'fetch_notifications') {
            $params = [':user_id' => $user_id];
            $branch_condition = '';
            if (!$is_global_admin && $branch_id > 0) {
                $branch_condition = " AND branch_id = :branch_id";
                $params[':branch_id'] = $branch_id;
            }
            
            $sql = "
                SELECT 
                    id,
                    title,
                    message,
                    notification_type,
                    is_read,
                    created_at
                FROM notifications_tracker
                WHERE user_id = :user_id
                  AND deleted_at IS NULL
                  {$branch_condition}
                ORDER BY created_at DESC LIMIT 20
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (!is_array($notifications)) $notifications = [];
            jsonResponse(true, '', ['notifications' => $notifications]);
        }

        // ---------- 3.14 Mark Notification Read ----------
        if ($action === 'mark_notification_read') {
            $notification_id = (int)($_POST['notification_id'] ?? 0);
            if ($notification_id <= 0) jsonResponse(false, 'Invalid notification ID');
            
            try {
                $update_sql = "UPDATE notifications_tracker SET is_read = 1, read_at = NOW() WHERE id = :id AND user_id = :user_id";
                $stmt = $pdo->prepare($update_sql);
                $stmt->execute([':id' => $notification_id, ':user_id' => $user_id]);
                jsonResponse(true, 'Notification marked as read');
            } catch (PDOException $e) {
                jsonResponse(false, 'Database error: ' . $e->getMessage());
            }
        }

        jsonResponse(false, 'Unsupported action');
    } catch (PDOException $e) {
        error_log('Doctor Dashboard AJAX Error: ' . $e->getMessage());
        jsonResponse(false, 'Database error: ' . $e->getMessage());
    }
    exit;
}

// ----------------------------------------------------------------------
// 4. GENERATE NOTIFICATION HELPER
// ----------------------------------------------------------------------
function generateNotification($pdo, $userId, $title, $message, $type, $branchId) {
    $sql = "INSERT INTO notifications_tracker (branch_id, user_id, title, message, notification_type, created_at) 
            VALUES (:branch_id, :user_id, :title, :message, :type, NOW())";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':branch_id' => $branchId,
        ':user_id' => $userId,
        ':title' => $title,
        ':message' => $message,
        ':type' => $type
    ]);
}

// ----------------------------------------------------------------------
// 5. INITIAL PAGE DATA (Optimized with combined query)
// ----------------------------------------------------------------------
// Get doctor specialization from database
$doctor_specialty = '';
$spec_stmt = $pdo->prepare("SELECT specialization FROM staff_profiles WHERE user_id = :user_id AND deleted_at IS NULL");
$spec_stmt->execute([':user_id' => $user_id]);
$spec = $spec_stmt->fetch(PDO::FETCH_ASSOC);
if ($spec) {
    $doctor_specialty = $spec['specialization'];
    $_SESSION['specialization'] = $doctor_specialty; // Cache for future
}
if (empty($doctor_specialty)) $doctor_specialty = 'General Medicine';

// Initialize variables with defaults
$metrics = [
    'waiting_queue' => 0,
    'active_inpatients' => 0,
    'critical_diagnostics' => 0,
    'pending_referrals' => 0,
    'pending_prescriptions' => 0,
    'scheduled_procedures' => 0,
    'pending_radiology' => 0,
    'pending_discharge' => 0,
    'unread_notifications' => 0
];

$queue_patients = [];
$critical_labs = [];
$referrals_list = [];
$inpatients_list = [];
$prescriptions_list = [];
$procedures_list = [];
$discharges_list = [];
$notifications_list = [];

try {
    // Build branch condition
    $params = [':doctor_id' => $user_id, ':user_id' => $user_id];
    $branch_condition = '';
    if (!$is_global_admin && $branch_id > 0) {
        $branch_condition = " AND branch_id = :branch_id";
        $params[':branch_id'] = $branch_id;
    }

    // Combined KPI query (optimized)
    $sql = "
        SELECT 
            (SELECT COUNT(*) FROM patient_visits pv
             INNER JOIN appointments a ON pv.id = a.visit_id
             WHERE a.doctor_id = :doctor_id
               AND (pv.visit_date = CURDATE() OR DATE(pv.checked_in_at) = CURDATE())
               AND pv.queue_status IN ('waiting', 'called')
               AND pv.status IN ('checked_in', 'pending')
               AND pv.deleted_at IS NULL
               {$branch_condition}) AS waiting_queue,
               
            (SELECT COUNT(*) FROM admissions
             WHERE doctor_id = :doctor_id
               AND status = 'admitted'
               AND discharged_at IS NULL
               AND deleted_at IS NULL
               {$branch_condition}) AS active_inpatients,
               
            (SELECT COUNT(DISTINCT ltr.id) FROM lab_test_results ltr
             INNER JOIN lab_orders lo ON ltr.lab_order_id = lo.id
             WHERE lo.doctor_id = :doctor_id
               AND ltr.is_critical = 1
               AND ltr.doctor_reviewed_at IS NULL
               AND lo.status = 'completed'
               AND ltr.deleted_at IS NULL
               {$branch_condition}) AS critical_diagnostics,
               
            (SELECT COUNT(*) FROM patient_referrals
             WHERE referred_to_user_id = :doctor_id
               AND status = 'pending'
               AND deleted_at IS NULL
               {$branch_condition}) AS pending_referrals,
               
            (SELECT COUNT(*) FROM prescriptions
             WHERE doctor_id = :doctor_id
               AND status IN ('pending_payment', 'active', 'partial')
               AND deleted_at IS NULL
               {$branch_condition}) AS pending_prescriptions,
               
            (SELECT COUNT(*) FROM procedure_orders
             WHERE doctor_id = :doctor_id
               AND execution_status IN ('scheduled', 'pending_payment')
               AND deleted_at IS NULL
               {$branch_condition}) AS scheduled_procedures,
               
            (SELECT COUNT(*) FROM radiology_orders
             WHERE doctor_id = :doctor_id
               AND status IN ('pending_payment', 'scheduled')
               AND deleted_at IS NULL
               {$branch_condition}) AS pending_radiology,
               
            (SELECT COUNT(*) FROM admissions
             WHERE doctor_id = :doctor_id
               AND status = 'admitted'
               AND discharge_summary IS NULL
               AND deleted_at IS NULL
               {$branch_condition}) AS pending_discharge,
               
            (SELECT COUNT(*) FROM notifications_tracker
             WHERE user_id = :user_id
               AND is_read = 0
               AND deleted_at IS NULL
               {$branch_condition}) AS unread_notifications
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $metrics_result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($metrics_result) {
        $metrics = $metrics_result;
    }

    // Queue list (first 10)
    $params = [':doctor_id' => $user_id];
    $branch_condition = '';
    if (!$is_global_admin && $branch_id > 0) {
        $branch_condition = " AND a.branch_id = :branch_id";
        $params[':branch_id'] = $branch_id;
    }
    
    $sql = "
        SELECT 
            pv.id AS visit_id,
            pv.patient_id,
            a.id AS appointment_id,
            a.appointment_time,
            pv.status AS visit_status,
            a.status AS appointment_status,
            CONCAT(p.first_name, ' ', COALESCE(p.middle_name, ''), ' ', p.last_name) AS patient_name,
            p.mr_number,
            p.date_of_birth,
            COALESCE(tr.priority, 'green') AS triage_priority,
            tr.chief_complaint,
            tr.triage_notes,
            pv.queue_status,
            pv.called_at,
            pv.consultation_started_at
        FROM appointments a
        INNER JOIN patient_visits pv ON a.visit_id = pv.id
        INNER JOIN patients p ON a.patient_id = p.id AND p.deleted_at IS NULL
        LEFT JOIN triage_records tr ON tr.visit_id = pv.id AND tr.deleted_at IS NULL
        WHERE a.doctor_id = :doctor_id
          AND (a.appointment_date = CURDATE() OR DATE(pv.checked_in_at) = CURDATE())
          AND a.status NOT IN ('completed', 'cancelled')
          AND a.deleted_at IS NULL
          AND pv.deleted_at IS NULL
          {$branch_condition}
        ORDER BY FIELD(COALESCE(tr.priority, 'green'), 'red', 'yellow', 'green', 'blue'), 
                 CASE WHEN pv.queue_status = 'called' THEN 0 ELSE 1 END,
                 a.appointment_time ASC
        LIMIT 10
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $queue_patients = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($queue_patients)) $queue_patients = [];

    foreach ($queue_patients as &$row) {
        $row['age'] = $row['date_of_birth'] ? getPatientAge($row['date_of_birth']) : 'N/A';
        $row['waiting_time'] = $row['appointment_time'] ? 
            (new DateTime())->diff(new DateTime($row['appointment_time']))->format('%i') . ' min' : 'N/A';
        $row['status_display'] = $row['queue_status'] === 'called' ? 'Called' : 
                                 ($row['consultation_started_at'] ? 'In Progress' : 'Waiting');
    }

    // Critical labs list (first 5)
    $params = [':doctor_id' => $user_id];
    $branch_condition = '';
    if (!$is_global_admin && $branch_id > 0) {
        $branch_condition = " AND lo.branch_id = :branch_id";
        $params[':branch_id'] = $branch_id;
    }
    
    $sql = "
        SELECT 
            ltr.id AS result_id,
            ltr.test_name,
            ltr.result_numeric,
            ltr.result_text,
            ltr.result_unit,
            ltr.reference_range_text,
            lo.order_number,
            CONCAT(p.first_name, ' ', COALESCE(p.middle_name, ''), ' ', p.last_name) AS patient_name,
            p.mr_number,
            p.id AS patient_id,
            p.date_of_birth
        FROM lab_test_results ltr
        INNER JOIN lab_orders lo ON ltr.lab_order_id = lo.id
        INNER JOIN patients p ON lo.patient_id = p.id AND p.deleted_at IS NULL
        WHERE lo.doctor_id = :doctor_id
          AND ltr.is_critical = 1
          AND ltr.doctor_reviewed_at IS NULL
          AND lo.status = 'completed'
          AND ltr.deleted_at IS NULL
          {$branch_condition}
        ORDER BY lo.order_date DESC LIMIT 5
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $critical_labs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($critical_labs)) $critical_labs = [];
    foreach ($critical_labs as &$lab) {
        $lab['age'] = $lab['date_of_birth'] ? getPatientAge($lab['date_of_birth']) : 'N/A';
    }

    // Referrals list (first 5)
    $params = [':doctor_id' => $user_id];
    $branch_condition = '';
    if (!$is_global_admin && $branch_id > 0) {
        $branch_condition = " AND pr.branch_id = :branch_id";
        $params[':branch_id'] = $branch_id;
    }
    
    $sql = "
        SELECT 
            pr.id AS referral_id,
            pr.referral_reason,
            pr.created_at,
            CONCAT(u_from.first_name, ' ', COALESCE(u_from.middle_name, ''), ' ', u_from.last_name) AS referring_doctor,
            CONCAT(p.first_name, ' ', COALESCE(p.middle_name, ''), ' ', p.last_name) AS patient_name,
            p.mr_number,
            p.id AS patient_id,
            p.date_of_birth
        FROM patient_referrals pr
        INNER JOIN users u_from ON pr.referred_from_user_id = u_from.id
        INNER JOIN patients p ON pr.patient_id = p.id AND p.deleted_at IS NULL
        WHERE pr.referred_to_user_id = :doctor_id
          AND pr.status = 'pending'
          AND pr.deleted_at IS NULL
          {$branch_condition}
        ORDER BY pr.created_at DESC LIMIT 5
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $referrals_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($referrals_list)) $referrals_list = [];
    foreach ($referrals_list as &$ref) {
        $ref['age'] = $ref['date_of_birth'] ? getPatientAge($ref['date_of_birth']) : 'N/A';
    }

    // Active inpatients list (first 5)
    $params = [':doctor_id' => $user_id];
    $branch_condition = '';
    if (!$is_global_admin && $branch_id > 0) {
        $branch_condition = " AND a.branch_id = :branch_id";
        $params[':branch_id'] = $branch_id;
    }
    
    $sql = "
        SELECT 
            a.id AS admission_id,
            a.admission_number,
            a.admitted_at,
            a.condition_status,
            a.primary_diagnosis,
            CONCAT(p.first_name, ' ', COALESCE(p.middle_name, ''), ' ', p.last_name) AS patient_name,
            p.mr_number,
            p.id AS patient_id,
            p.date_of_birth,
            p.gender,
            b.bed_number,
            w.name AS ward_name,
            DATEDIFF(NOW(), a.admitted_at) AS length_of_stay
        FROM admissions a
        INNER JOIN patients p ON a.patient_id = p.id AND p.deleted_at IS NULL
        LEFT JOIN beds b ON a.bed_id = b.id
        LEFT JOIN wards w ON b.ward_id = w.id
        WHERE a.doctor_id = :doctor_id
          AND a.status = 'admitted'
          AND a.discharged_at IS NULL
          AND a.deleted_at IS NULL
          {$branch_condition}
        ORDER BY a.condition_status = 'critical' DESC, 
                 a.condition_status = 'serious' DESC, 
                 a.admitted_at ASC
        LIMIT 5
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $inpatients_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($inpatients_list)) $inpatients_list = [];
    foreach ($inpatients_list as &$ip) {
        $ip['age'] = $ip['date_of_birth'] ? getPatientAge($ip['date_of_birth']) : 'N/A';
    }

    // Prescriptions list (first 5)
    $params = [':doctor_id' => $user_id];
    $branch_condition = '';
    if (!$is_global_admin && $branch_id > 0) {
        $branch_condition = " AND pr.branch_id = :branch_id";
        $params[':branch_id'] = $branch_id;
    }
    
    $sql = "
        SELECT 
            pr.id AS prescription_id,
            pr.prescription_number,
            pr.prescription_date,
            pr.status,
            CONCAT(p.first_name, ' ', COALESCE(p.middle_name, ''), ' ', p.last_name) AS patient_name,
            p.mr_number,
            p.id AS patient_id,
            (SELECT COUNT(*) FROM prescription_items WHERE prescription_id = pr.id AND deleted_at IS NULL) AS item_count
        FROM prescriptions pr
        INNER JOIN patients p ON pr.patient_id = p.id AND p.deleted_at IS NULL
        WHERE pr.doctor_id = :doctor_id
          AND pr.status IN ('pending_payment', 'active', 'partial')
          AND pr.deleted_at IS NULL
          {$branch_condition}
        ORDER BY pr.prescription_date DESC LIMIT 5
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $prescriptions_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($prescriptions_list)) $prescriptions_list = [];

    // Scheduled procedures (first 5)
    $params = [':doctor_id' => $user_id];
    $branch_condition = '';
    if (!$is_global_admin && $branch_id > 0) {
        $branch_condition = " AND po.branch_id = :branch_id";
        $params[':branch_id'] = $branch_id;
    }
    
    $sql = "
        SELECT 
            po.id AS procedure_id,
            po.procedure_number,
            po.procedure_name,
            po.priority,
            po.scheduled_date,
            po.scheduled_time,
            po.execution_status,
            CONCAT(p.first_name, ' ', COALESCE(p.middle_name, ''), ' ', p.last_name) AS patient_name,
            p.mr_number,
            p.id AS patient_id
        FROM procedure_orders po
        INNER JOIN patients p ON po.patient_id = p.id AND p.deleted_at IS NULL
        WHERE po.doctor_id = :doctor_id
          AND po.execution_status IN ('scheduled', 'pending_payment')
          AND po.deleted_at IS NULL
          {$branch_condition}
        ORDER BY po.scheduled_date ASC, po.scheduled_time ASC LIMIT 5
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $procedures_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($procedures_list)) $procedures_list = [];

    // Pending discharges (first 5)
    $params = [':doctor_id' => $user_id];
    $branch_condition = '';
    if (!$is_global_admin && $branch_id > 0) {
        $branch_condition = " AND a.branch_id = :branch_id";
        $params[':branch_id'] = $branch_id;
    }
    
    $sql = "
        SELECT 
            a.id AS admission_id,
            a.admission_number,
            a.admitted_at,
            a.condition_status,
            a.primary_diagnosis,
            CONCAT(p.first_name, ' ', COALESCE(p.middle_name, ''), ' ', p.last_name) AS patient_name,
            p.mr_number,
            p.id AS patient_id,
            p.date_of_birth,
            DATEDIFF(NOW(), a.admitted_at) AS length_of_stay
        FROM admissions a
        INNER JOIN patients p ON a.patient_id = p.id AND p.deleted_at IS NULL
        WHERE a.doctor_id = :doctor_id
          AND a.status = 'admitted'
          AND a.discharge_summary IS NULL
          AND a.discharged_at IS NULL
          AND a.deleted_at IS NULL
          {$branch_condition}
        ORDER BY a.admitted_at ASC LIMIT 5
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $discharges_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($discharges_list)) $discharges_list = [];
    foreach ($discharges_list as &$d) {
        $d['age'] = $d['date_of_birth'] ? getPatientAge($d['date_of_birth']) : 'N/A';
    }

    // Notifications (first 5)
    $params = [':user_id' => $user_id];
    $branch_condition = '';
    if (!$is_global_admin && $branch_id > 0) {
        $branch_condition = " AND branch_id = :branch_id";
        $params[':branch_id'] = $branch_id;
    }
    
    $sql = "
        SELECT 
            id,
            title,
            message,
            notification_type,
            is_read,
            created_at
        FROM notifications_tracker
        WHERE user_id = :user_id
          AND deleted_at IS NULL
          {$branch_condition}
        ORDER BY created_at DESC LIMIT 5
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $notifications_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($notifications_list)) $notifications_list = [];

} catch (PDOException $e) {
    error_log('Doctor Dashboard init error: ' . $e->getMessage());
    // Keep default empty arrays
}

// Final safety cast to array (defensive)
if (!is_array($queue_patients)) $queue_patients = [];
if (!is_array($critical_labs)) $critical_labs = [];
if (!is_array($referrals_list)) $referrals_list = [];
if (!is_array($inpatients_list)) $inpatients_list = [];
if (!is_array($prescriptions_list)) $prescriptions_list = [];
if (!is_array($procedures_list)) $procedures_list = [];
if (!is_array($discharges_list)) $discharges_list = [];
if (!is_array($notifications_list)) $notifications_list = [];

$current_date = date('l, F j, Y');
$current_time = date('g:i A');

// Ensure rate limits table exists
ensureRateLimitsTable($pdo);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include('assets/inc/head.php'); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Doctor Dashboard | HMS ERP</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <style>
        :root {
            --primary: #4361ee;
            --primary-dark: #3a0ca3;
            --primary-light: #667eea;
            --secondary: #7209b7;
            --success: #06d6a0;
            --warning: #f9c74f;
            --danger: #ef476f;
            --info: #4cc9f0;
            --dark: #1a1a2e;
            --light-bg: #f5f7fb;
            --white: #ffffff;
            --gray: #6c757d;
            --gray-light: #e9ecef;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif; background: linear-gradient(135deg, #f5f7fb 0%, #eef2f6 100%); overflow-x: hidden; }
        .content-page { margin-left: 260px; margin-top: 70px; padding: 24px 32px; transition: margin-left 0.3s cubic-bezier(0.4, 0, 0.2, 1); min-height: calc(100vh - 70px); }
        body.erp-sidebar-collapsed .content-page { margin-left: 80px; }
        @media (max-width: 768px) { .content-page { margin-left: 0 !important; padding: 16px; } }
        
        .welcome-header { background: linear-gradient(135deg, var(--primary-dark) 0%, var(--primary) 100%); border-radius: 24px; padding: 24px 32px; color: white; margin-bottom: 28px; }
        .welcome-title { font-size: 24px; font-weight: 700; margin-bottom: 4px; }
        .welcome-subtitle { color: rgba(255,255,255,0.7); font-size: 14px; }
        .doctor-badge-header { background: rgba(255,255,255,0.15); padding: 8px 16px; border-radius: 12px; font-size: 13px; display: inline-flex; align-items: center; gap: 8px; }
        .time-badge { background: rgba(255,255,255,0.15); padding: 8px 16px; border-radius: 12px; font-size: 13px; display: inline-flex; align-items: center; gap: 8px; }
        .last-updated { font-size: 11px; color: rgba(255,255,255,0.6); }
        
        .stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; margin-bottom: 28px; }
        @media (max-width: 992px) { .stats-grid { grid-template-columns: repeat(2, 1fr); } }
        @media (max-width: 576px) { .stats-grid { grid-template-columns: 1fr; } }
        
        .stat-card { background: white; border-radius: 20px; padding: 20px; transition: all 0.3s; border: 1px solid var(--gray-light); position: relative; overflow: hidden; cursor: pointer; }
        .stat-card:hover { transform: translateY(-3px); box-shadow: 0 10px 30px rgba(0,0,0,0.08); }
        .stat-card::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 4px; }
        .stat-card.primary::before { background: linear-gradient(90deg, var(--primary-light), var(--primary)); }
        .stat-card.success::before { background: linear-gradient(90deg, var(--success), #059669); }
        .stat-card.warning::before { background: linear-gradient(90deg, var(--warning), #d97706); }
        .stat-card.danger::before { background: linear-gradient(90deg, var(--danger), #dc2626); }
        .stat-card.info::before { background: linear-gradient(90deg, var(--info), #0284c7); }
        .stat-card.purple::before { background: linear-gradient(90deg, var(--secondary), #7c3aed); }
        
        .stat-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 16px; }
        .stat-icon { width: 48px; height: 48px; border-radius: 14px; display: flex; align-items: center; justify-content: center; font-size: 22px; }
        .stat-icon.primary { background: rgba(67,97,238,0.1); color: var(--primary); }
        .stat-icon.success { background: rgba(6,214,160,0.1); color: var(--success); }
        .stat-icon.warning { background: rgba(249,199,79,0.1); color: var(--warning); }
        .stat-icon.danger { background: rgba(239,71,111,0.1); color: var(--danger); }
        .stat-icon.info { background: rgba(76,201,240,0.1); color: var(--info); }
        .stat-icon.purple { background: rgba(114,9,183,0.1); color: var(--secondary); }
        .stat-value { font-size: 32px; font-weight: 800; color: var(--dark); line-height: 1.2; }
        .stat-label { font-size: 13px; color: var(--gray); font-weight: 500; }
        
        .dashboard-grid { display: grid; grid-template-columns: 1fr 420px; gap: 24px; }
        @media (max-width: 1200px) { .dashboard-grid { grid-template-columns: 1fr; } }
        
        .card-modern { background: white; border-radius: 20px; border: 1px solid var(--gray-light); overflow: hidden; margin-bottom: 24px; }
        .card-header-custom { padding: 18px 24px; background: #f8fafc; border-bottom: 1px solid var(--gray-light); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; }
        .card-header-custom h3 { margin: 0; font-size: 18px; font-weight: 700; color: var(--dark); }
        
        .data-table { width: 100%; }
        .data-table th { padding: 14px 20px; font-size: 12px; font-weight: 600; color: var(--gray); text-transform: uppercase; background: #f8fafc; border-bottom: 1px solid var(--gray-light); }
        .data-table td { padding: 12px 20px; font-size: 13px; border-bottom: 1px solid #f3f4f6; vertical-align: middle; }
        .data-table tr:hover { background: #f8fafc; }
        
        .status-badge { display: inline-block; padding: 4px 12px; border-radius: 20px; font-size: 11px; font-weight: 600; }
        .priority-red { background: #fee2e2; color: #dc2626; font-weight: 700; animation: pulse 1s ease-in-out infinite; }
        .priority-yellow { background: #fef3c7; color: #d97706; }
        .priority-green { background: #d1fae5; color: #059669; }
        .priority-blue { background: #e0e7ff; color: #4361ee; }
        
        @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.7; } }
        
        .emergency-alert { animation: blinkRed 0.8s ease-in-out infinite; display: inline-block; padding: 4px 12px; border-radius: 20px; font-size: 11px; font-weight: 700; }
        @keyframes blinkRed { 0% { background-color: #fee2e2; color: #ef476f; } 50% { background-color: #ef476f; color: white; } 100% { background-color: #fee2e2; color: #ef476f; } }
        
        .action-btn { padding: 6px 14px; border-radius: 10px; font-size: 12px; font-weight: 500; border: none; cursor: pointer; transition: all 0.2s; }
        .action-btn-primary { background: var(--primary); color: white; }
        .action-btn-primary:hover { background: var(--primary-dark); }
        .action-btn-success { background: var(--success); color: white; }
        .action-btn-success:hover { background: #059669; }
        .action-btn-danger { background: var(--danger); color: white; }
        .action-btn-danger:hover { background: #dc2626; }
        .action-btn-warning { background: var(--warning); color: var(--dark); }
        .action-btn-warning:hover { background: #d97706; color: white; }
        .action-btn-outline { background: white; border: 1px solid var(--gray-light); color: var(--dark); }
        .action-btn-outline:hover { background: #f8fafc; }
        
        .call-btn { background: #f59e0b; color: white; animation: pulse-call 1.5s ease-in-out infinite; }
        @keyframes pulse-call { 0%, 100% { opacity: 1; transform: scale(1); } 50% { opacity: 0.8; transform: scale(1.05); } }
        .call-btn:hover { background: #d97706; color: white; }
        
        .list-group-item { border: 1px solid var(--gray-light); border-radius: 12px; margin-bottom: 12px; padding: 16px; background: white; }
        .badge { display: inline-block; padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: 600; }
        .badge-primary { background: var(--primary); color: white; }
        .badge-success { background: var(--success); color: white; }
        .badge-danger { background: var(--danger); color: white; }
        .badge-warning { background: var(--warning); color: var(--dark); }
        
        .toast-notification { position: fixed; bottom: 20px; right: 20px; background: white; border-radius: 12px; padding: 12px 20px; box-shadow: 0 10px 25px rgba(0,0,0,0.1); z-index: 1100; animation: slideIn 0.3s ease; display: flex; align-items: center; gap: 12px; border-left: 4px solid var(--success); }
        .toast-error { border-left-color: var(--danger); }
        @keyframes slideIn { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
        
        .loading-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(255,255,255,0.7); z-index: 2000; display: none; align-items: center; justify-content: center; }
        .loading-overlay.active { display: flex; }
        .spinner { width: 40px; height: 40px; border: 3px solid var(--gray-light); border-top-color: var(--primary); border-radius: 50%; animation: spin 0.8s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }
        
        .loading-placeholder { padding: 20px; text-align: center; }
        .loading-placeholder .spinner-border { width: 30px; height: 30px; }
        
        .empty-state { text-align: center; padding: 40px; color: var(--gray); }
        .small { font-size: 11px; }
        .text-muted { color: var(--gray) !important; }
        .text-danger { color: var(--danger) !important; }
        .text-success { color: var(--success) !important; }
        .me-2 { margin-right: 8px; }
        .mt-1 { margin-top: 4px; }
        .mb-1 { margin-bottom: 4px; }
        .fw-bold { font-weight: 700; }
        .fw-semibold { font-weight: 600; }
        
        .notification-unread { background: #f0f4ff; border-left: 3px solid var(--primary); }
        .mini-tabs { display: flex; gap: 4px; flex-wrap: wrap; padding: 8px 16px; background: #f8fafc; }
        .mini-tab { padding: 4px 12px; border-radius: 8px; font-size: 12px; cursor: pointer; background: var(--gray-light); border: none; }
        .mini-tab.active { background: var(--primary); color: white; }
        .scrollable-section { max-height: 400px; overflow-y: auto; }
        .scrollable-section::-webkit-scrollbar { width: 6px; }
        .scrollable-section::-webkit-scrollbar-thumb { background: var(--gray-light); border-radius: 3px; }
        .scrollable-section::-webkit-scrollbar-thumb:hover { background: var(--gray); }
        
        .condition-critical { color: var(--danger); font-weight: 700; }
        .condition-serious { color: #d97706; font-weight: 600; }
        .condition-stable { color: var(--success); }
        
        .floating-critical-alert { position: fixed; top: 80px; right: 20px; z-index: 1000; max-width: 400px; animation: slideDown 0.5s ease; }
        @keyframes slideDown { from { transform: translateY(-100px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
    </style>
</head>
<body>
<div id="wrapper">
    <?php include('assets/inc/nav.php'); ?>
    <?php include('assets/inc/sidebar.php'); ?>

    <div class="content-page">
        <div class="container-fluid">
            <!-- Floating Critical Alert Banner -->
            <div id="floatingCriticalAlert" class="floating-critical-alert" style="display:none;">
                <div class="alert alert-danger shadow-lg">
                    <div class="d-flex align-items-center">
                        <i class="fas fa-exclamation-triangle fa-2x me-3"></i>
                        <div>
                            <strong>Critical Lab Results!</strong>
                            <div id="criticalAlertMessage" class="small">Loading...</div>
                        </div>
                        <button class="btn btn-sm btn-outline-danger ms-3" onclick="$('#floatingCriticalAlert').hide();">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Welcome Header -->
            <div class="welcome-header">
                <div class="row align-items-center">
                    <div class="col-md-7">
                        <div class="welcome-title">Welcome, Dr. <?php echo escapeHtml($doctor_name); ?> 👋</div>
                        <div class="welcome-subtitle">Your unified command center for OPD, inpatient care, diagnostics & clinical communication.</div>
                        <div class="doctor-badge-header mt-2"><i class="fas fa-stethoscope"></i> <?php echo escapeHtml($doctor_specialty); ?></div>
                    </div>
                    <div class="col-md-5 text-md-end mt-3 mt-md-0">
                        <div class="time-badge"><i class="far fa-calendar-alt"></i> <?php echo escapeHtml($current_date); ?> <i class="far fa-clock ms-2"></i> <?php echo escapeHtml($current_time); ?></div>
                        <div class="last-updated mt-1" id="lastUpdated">Last updated: Just now</div>
                    </div>
                </div>
            </div>

            <!-- KPI Cards Grid - 9 Cards -->
            <div class="stats-grid">
                <div class="stat-card warning" onclick="window.location.href='current_consultation.php'">
                    <div class="stat-header"><div><div class="stat-value" id="kpiWaitingQueue"><?php echo (int)($metrics['waiting_queue'] ?? 0); ?></div><div class="stat-label">Outpatient Waiting Queue</div></div><div class="stat-icon warning"><i class="fas fa-hourglass-half"></i></div></div>
                    <div class="stat-footer"><i class="fas fa-users"></i> Patients awaiting consultation</div>
                </div>
                <div class="stat-card primary" onclick="window.location.href='ward_management.php'">
                    <div class="stat-header"><div><div class="stat-value" id="kpiActiveInpatients"><?php echo (int)($metrics['active_inpatients'] ?? 0); ?></div><div class="stat-label">My Active Inpatients</div></div><div class="stat-icon primary"><i class="fas fa-hospital-user"></i></div></div>
                    <div class="stat-footer"><i class="fas fa-procedures"></i> Under your care</div>
                </div>
                <div class="stat-card danger" onclick="window.location.href='diagnostics_review.php'">
                    <div class="stat-header"><div><div class="stat-value" id="kpiCriticalDiagnostics"><?php echo (int)($metrics['critical_diagnostics'] ?? 0); ?></div><div class="stat-label">Critical Diagnostics</div></div><div class="stat-icon danger"><i class="fas fa-flask"></i></div></div>
                    <div class="stat-footer"><i class="fas fa-exclamation-triangle"></i> Require immediate review</div>
                </div>
                <div class="stat-card success" onclick="window.location.href='clinical_communication.php'">
                    <div class="stat-header"><div><div class="stat-value" id="kpiPendingReferrals"><?php echo (int)($metrics['pending_referrals'] ?? 0); ?></div><div class="stat-label">Pending Internal Referrals</div></div><div class="stat-icon success"><i class="fas fa-user-md"></i></div></div>
                    <div class="stat-footer"><i class="fas fa-arrow-right"></i> Inbound patient transfers</div>
                </div>
                <div class="stat-card info" onclick="window.location.href='prescription_management.php'">
                    <div class="stat-header"><div><div class="stat-value" id="kpiPendingPrescriptions"><?php echo (int)($metrics['pending_prescriptions'] ?? 0); ?></div><div class="stat-label">Pending Prescriptions</div></div><div class="stat-icon info"><i class="fas fa-prescription"></i></div></div>
                    <div class="stat-footer"><i class="fas fa-pills"></i> Awaiting dispensing</div>
                </div>
                <div class="stat-card purple" onclick="window.location.href='procedure_management.php'">
                    <div class="stat-header"><div><div class="stat-value" id="kpiScheduledProcedures"><?php echo (int)($metrics['scheduled_procedures'] ?? 0); ?></div><div class="stat-label">Scheduled Procedures</div></div><div class="stat-icon purple"><i class="fas fa-syringe"></i></div></div>
                    <div class="stat-footer"><i class="fas fa-calendar-check"></i> Planned interventions</div>
                </div>
                <div class="stat-card info" onclick="window.location.href='radiology_management.php'">
                    <div class="stat-header"><div><div class="stat-value" id="kpiPendingRadiology"><?php echo (int)($metrics['pending_radiology'] ?? 0); ?></div><div class="stat-label">Pending Radiology</div></div><div class="stat-icon info"><i class="fas fa-x-ray"></i></div></div>
                    <div class="stat-footer"><i class="fas fa-image"></i> Imaging requests</div>
                </div>
                <div class="stat-card warning" onclick="window.location.href='discharge_management.php'">
                    <div class="stat-header"><div><div class="stat-value" id="kpiPendingDischarge"><?php echo (int)($metrics['pending_discharge'] ?? 0); ?></div><div class="stat-label">Pending Discharge</div></div><div class="stat-icon warning"><i class="fas fa-door-open"></i></div></div>
                    <div class="stat-footer"><i class="fas fa-file-alt"></i> Need discharge summary</div>
                </div>
                <div class="stat-card primary" onclick="window.location.href='notifications.php'">
                    <div class="stat-header"><div><div class="stat-value" id="kpiUnreadNotifications"><?php echo (int)($metrics['unread_notifications'] ?? 0); ?></div><div class="stat-label">Unread Notifications</div></div><div class="stat-icon primary"><i class="fas fa-bell"></i></div></div>
                    <div class="stat-footer"><i class="fas fa-envelope"></i> System alerts & updates</div>
                </div>
            </div>

            <!-- Main Workspace Grid -->
            <div class="dashboard-grid">
                <!-- LEFT COLUMN: Master Patient Queue -->
                <div>
                    <div class="card-modern">
                        <div class="card-header-custom">
                            <h3><i class="fas fa-users me-2"></i> Master Patient Queue</h3>
                            <div>
                                <button class="action-btn action-btn-outline me-2" onclick="fetchQueue()"><i class="fas fa-sync-alt"></i></button>
                                <button class="action-btn action-btn-primary" onclick="window.location.href='appointment_schedule.php'"><i class="fas fa-plus"></i> Schedule</button>
                            </div>
                        </div>
                        <div class="table-responsive">
                            <table class="data-table">
                                <thead>
                                    <tr><th>#</th><th>Patient</th><th>MRN</th><th>Age</th><th>Priority</th><th>Status</th><th>Action</th></tr>
                                </thead>
                                <tbody id="queueBody">
                                    <?php if (is_array($queue_patients) && count($queue_patients) > 0): $cnt = 1; foreach ($queue_patients as $pat): 
                                        $priority_class = 'priority-green';
                                        $priority_display = 'Normal';
                                        if ($pat['triage_priority'] == 'red') { $priority_class = 'priority-red'; $priority_display = '<span class="emergency-alert"><i class="fas fa-exclamation-triangle me-1"></i>EMERGENCY</span>'; }
                                        elseif ($pat['triage_priority'] == 'yellow') { $priority_class = 'priority-yellow'; $priority_display = 'Urgent'; }
                                        elseif ($pat['triage_priority'] == 'blue') { $priority_class = 'priority-blue'; $priority_display = 'Expectant'; }
                                        
                                        $status_display = $pat['status_display'] ?? 'Waiting';
                                        $is_called = ($pat['queue_status'] ?? '') === 'called';
                                    ?>
                                    <tr>
                                        <td><?php echo $cnt++; ?></td>
                                        <td><strong><?php echo escapeHtml($pat['patient_name']); ?></strong></td>
                                        <td><?php echo escapeHtml($pat['mr_number'] ?? 'N/A'); ?></td>
                                        <td><?php echo $pat['age']; ?></td>
                                        <td class="<?php echo $priority_class; ?>"><?php echo $priority_display; ?></td>
                                        <td><span class="status-badge <?php echo $is_called ? 'priority-yellow' : 'priority-green'; ?>"><?php echo $status_display; ?></span></td>
                                        <td>
                                            <button class="action-btn action-btn-primary" onclick="startConsultation(<?php echo $pat['visit_id']; ?>)"><i class="fas fa-play"></i> Start</button>
                                            <?php if (!$is_called && ($pat['queue_status'] ?? '') !== 'called'): ?>
                                            <button class="action-btn action-btn-warning call-btn mt-1" onclick="callPatient(<?php echo $pat['visit_id']; ?>)"><i class="fas fa-bell"></i> Call</button>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; else: ?>
                                    <tr><td colspan="7" class="empty-state"><i class="fas fa-smile-wink fa-2x mb-2 d-block"></i>No patients waiting in queue</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- My Active Inpatients -->
                    <div class="card-modern">
                        <div class="card-header-custom">
                            <h3><i class="fas fa-hospital-user me-2"></i> My Active Inpatients</h3>
                            <button class="action-btn action-btn-outline" onclick="fetchInpatients()"><i class="fas fa-sync-alt"></i></button>
                        </div>
                        <div id="inpatientsBody" class="scrollable-section" style="max-height:300px;">
                            <?php if (is_array($inpatients_list) && count($inpatients_list) > 0): foreach ($inpatients_list as $ip): ?>
                            <div class="list-group-item">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <div class="fw-bold"><?php echo escapeHtml($ip['patient_name']); ?></div>
                                        <div class="small text-muted">MRN: <?php echo escapeHtml($ip['mr_number'] ?? 'N/A'); ?> | Age: <?php echo $ip['age']; ?> | Ward: <?php echo escapeHtml($ip['ward_name'] ?? 'N/A'); ?> | Bed: <?php echo escapeHtml($ip['bed_number'] ?? 'N/A'); ?></div>
                                        <div class="small mt-1">Dx: <?php echo escapeHtml(substr($ip['primary_diagnosis'] ?? 'N/A', 0, 60)); ?></div>
                                        <div class="small">LOS: <?php echo $ip['length_of_stay']; ?> days | Condition: <span class="condition-<?php echo strtolower($ip['condition_status'] ?? 'stable'); ?>"><?php echo ucfirst($ip['condition_status'] ?? 'Stable'); ?></span></div>
                                    </div>
                                    <div>
                                        <button class="action-btn action-btn-primary" onclick="window.location.href='ward_management.php?admission_id=<?php echo $ip['admission_id']; ?>'"><i class="fas fa-notes-medical"></i> Round</button>
                                        <button class="action-btn action-btn-success mt-1" onclick="window.location.href='discharge_summary.php?admission_id=<?php echo $ip['admission_id']; ?>'"><i class="fas fa-door-open"></i> Discharge</button>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; else: ?>
                            <div class="empty-state"><i class="fas fa-hospital-user fa-2x mb-2 text-muted"></i><p>No active inpatients assigned</p></div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Pending Prescriptions & Procedures Combined -->
                    <div class="card-modern">
                        <div class="card-header-custom">
                            <h3><i class="fas fa-notes-medical me-2"></i> Clinical Orders</h3>
                            <div>
                                <button class="action-btn action-btn-outline me-2" onclick="fetchPrescriptions()"><i class="fas fa-sync-alt"></i></button>
                                <button class="action-btn action-btn-primary" onclick="window.location.href='new_prescription.php'"><i class="fas fa-plus"></i> Prescribe</button>
                            </div>
                        </div>
                        <div class="mini-tabs">
                            <button class="mini-tab active" onclick="showClinicalTab('prescriptions')">Prescriptions</button>
                            <button class="mini-tab" onclick="showClinicalTab('procedures')">Procedures</button>
                        </div>
                        <div id="prescriptionsSection">
                            <?php if (is_array($prescriptions_list) && count($prescriptions_list) > 0): foreach ($prescriptions_list as $rx): ?>
                            <div class="list-group-item">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <div class="fw-bold"><?php echo escapeHtml($rx['patient_name']); ?></div>
                                        <div class="small text-muted">MRN: <?php echo escapeHtml($rx['mr_number'] ?? 'N/A'); ?> | <?php echo escapeHtml($rx['prescription_number']); ?></div>
                                        <div class="small mt-1"><?php echo $rx['item_count']; ?> items | Status: <?php echo getStatusBadge($rx['status']); ?></div>
                                    </div>
                                    <div>
                                        <button class="action-btn action-btn-primary" onclick="window.location.href='prescription_view.php?id=<?php echo $rx['prescription_id']; ?>'"><i class="fas fa-eye"></i> View</button>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; else: ?>
                            <div class="empty-state"><p>No pending prescriptions</p></div>
                            <?php endif; ?>
                        </div>
                        <div id="proceduresSection" style="display:none;">
                            <?php if (is_array($procedures_list) && count($procedures_list) > 0): foreach ($procedures_list as $proc): ?>
                            <div class="list-group-item">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <div class="fw-bold"><?php echo escapeHtml($proc['patient_name']); ?></div>
                                        <div class="small text-muted">MRN: <?php echo escapeHtml($proc['mr_number'] ?? 'N/A'); ?> | <?php echo escapeHtml($proc['procedure_number']); ?></div>
                                        <div class="small mt-1"><?php echo escapeHtml($proc['procedure_name']); ?> | Priority: <?php echo getStatusBadge($proc['priority']); ?></div>
                                        <div class="small">Scheduled: <?php echo date('M d, Y g:i A', strtotime($proc['scheduled_date'] . ' ' . ($proc['scheduled_time'] ?? '00:00:00'))); ?></div>
                                    </div>
                                    <div>
                                        <button class="action-btn action-btn-primary" onclick="window.location.href='procedure_view.php?id=<?php echo $proc['procedure_id']; ?>'"><i class="fas fa-eye"></i> View</button>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; else: ?>
                            <div class="empty-state"><p>No scheduled procedures</p></div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Pending Discharges -->
                    <div class="card-modern">
                        <div class="card-header-custom">
                            <h3><i class="fas fa-door-open me-2"></i> Pending Discharges</h3>
                            <button class="action-btn action-btn-outline" onclick="fetchDischarges()"><i class="fas fa-sync-alt"></i></button>
                        </div>
                        <div id="dischargesBody">
                            <?php if (is_array($discharges_list) && count($discharges_list) > 0): foreach ($discharges_list as $dc): ?>
                            <div class="list-group-item">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <div class="fw-bold"><?php echo escapeHtml($dc['patient_name']); ?></div>
                                        <div class="small text-muted">MRN: <?php echo escapeHtml($dc['mr_number'] ?? 'N/A'); ?> | Age: <?php echo $dc['age']; ?> | LOS: <?php echo $dc['length_of_stay']; ?> days</div>
                                        <div class="small mt-1">Dx: <?php echo escapeHtml(substr($dc['primary_diagnosis'] ?? 'N/A', 0, 60)); ?></div>
                                    </div>
                                    <div>
                                        <button class="action-btn action-btn-success" onclick="window.location.href='discharge_summary.php?admission_id=<?php echo $dc['admission_id']; ?>'"><i class="fas fa-file-alt"></i> Create Summary</button>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; else: ?>
                            <div class="empty-state"><p>No pending discharges</p></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- RIGHT COLUMN: Alerts, Referrals, Notifications -->
                <div>
                    <!-- Critical Lab Items -->
                    <div class="card-modern">
                        <div class="card-header-custom">
                            <h3><i class="fas fa-flask me-2"></i> Critical Diagnostics</h3>
                            <span class="badge badge-danger" id="criticalCount"><?php echo (int)($metrics['critical_diagnostics'] ?? 0); ?></span>
                        </div>
                        <div id="criticalLabsBody" class="scrollable-section" style="max-height:300px;">
                            <?php if (is_array($critical_labs) && count($critical_labs) > 0): foreach ($critical_labs as $lab): ?>
                            <div class="list-group-item" style="border-left: 3px solid #ef476f;">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <strong class="text-danger">⚠️ CRITICAL</strong>
                                        <div class="fw-bold mt-1"><?php echo escapeHtml($lab['patient_name']); ?></div>
                                        <div class="small text-muted">MRN: <?php echo escapeHtml($lab['mr_number'] ?? 'N/A'); ?> | Age: <?php echo $lab['age']; ?></div>
                                        <div class="small mt-1">Test: <?php echo escapeHtml($lab['test_name']); ?></div>
                                        <div class="small">Result: <?php echo $lab['result_numeric'] ?: $lab['result_text']; ?> <?php echo escapeHtml($lab['result_unit']); ?></div>
                                        <div class="small text-muted">Ref: <?php echo escapeHtml($lab['reference_range_text'] ?? 'N/A'); ?></div>
                                    </div>
                                    <div>
                                        <button class="action-btn action-btn-success" onclick="reviewLabResult(<?php echo $lab['result_id']; ?>, <?php echo $lab['patient_id']; ?>)"><i class="fas fa-eye"></i> Review</button>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; else: ?>
                            <div class="empty-state"><i class="fas fa-check-circle fa-2x mb-2 text-success"></i><p>No unread critical lab results</p></div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Incoming Referrals -->
                    <div class="card-modern">
                        <div class="card-header-custom">
                            <h3><i class="fas fa-comments me-2"></i> Incoming Referrals</h3>
                            <button class="action-btn action-btn-outline" onclick="fetchReferrals()"><i class="fas fa-sync-alt"></i></button>
                        </div>
                        <div id="referralsBody" class="scrollable-section" style="max-height:250px;">
                            <?php if (is_array($referrals_list) && count($referrals_list) > 0): foreach ($referrals_list as $ref): ?>
                            <div class="list-group-item">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <div class="fw-bold"><?php echo escapeHtml($ref['patient_name']); ?></div>
                                        <div class="small text-muted">MRN: <?php echo escapeHtml($ref['mr_number'] ?? 'N/A'); ?> | Age: <?php echo $ref['age']; ?></div>
                                        <div class="small mt-1">From: Dr. <?php echo escapeHtml($ref['referring_doctor']); ?></div>
                                        <div class="small text-muted">Reason: <?php echo escapeHtml(substr($ref['referral_reason'] ?? 'N/A', 0, 60)); ?></div>
                                    </div>
                                    <div>
                                        <button class="action-btn action-btn-primary" onclick="acknowledgeReferral(<?php echo $ref['referral_id']; ?>)"><i class="fas fa-check-circle"></i> Acknowledge</button>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; else: ?>
                            <div class="empty-state"><i class="fas fa-comment-slash fa-2x mb-2 text-muted"></i><p>No pending referrals</p></div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Notifications -->
                    <div class="card-modern">
                        <div class="card-header-custom">
                            <h3><i class="fas fa-bell me-2"></i> Recent Notifications</h3>
                            <span class="badge badge-primary" id="notifCount"><?php echo (int)($metrics['unread_notifications'] ?? 0); ?></span>
                        </div>
                        <div id="notificationsBody" class="scrollable-section" style="max-height:200px;">
                            <?php if (is_array($notifications_list) && count($notifications_list) > 0): foreach ($notifications_list as $notif): ?>
                            <div class="list-group-item <?php echo $notif['is_read'] ? '' : 'notification-unread'; ?>">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <div class="fw-semibold"><?php echo escapeHtml($notif['title']); ?></div>
                                        <div class="small text-muted"><?php echo escapeHtml(substr($notif['message'] ?? '', 0, 80)); ?></div>
                                        <div class="small text-muted mt-1"><?php echo date('M d, g:i A', strtotime($notif['created_at'])); ?></div>
                                    </div>
                                    <?php if (!$notif['is_read']): ?>
                                    <div>
                                        <button class="action-btn action-btn-outline" onclick="markNotificationRead(<?php echo $notif['id']; ?>)"><i class="fas fa-check"></i></button>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endforeach; else: ?>
                            <div class="empty-state"><p>No notifications</p></div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Quick Actions -->
                    <div class="card-modern">
                        <div class="card-header-custom">
                            <h3><i class="fas fa-bolt me-2"></i> Quick Actions</h3>
                        </div>
                        <div class="p-3">
                            <div class="d-grid gap-2">
                                <button class="action-btn action-btn-primary" onclick="window.location.href='new_consultation.php'"><i class="fas fa-stethoscope me-2"></i> New Consultation</button>
                                <button class="action-btn action-btn-success" onclick="window.location.href='new_prescription.php'"><i class="fas fa-prescription me-2"></i> New Prescription</button>
                                <button class="action-btn action-btn-warning" onclick="window.location.href='new_admission.php'"><i class="fas fa-hospital me-2"></i> Admit Patient</button>
                                <button class="action-btn action-btn-danger" onclick="window.location.href='diagnostics_review.php'"><i class="fas fa-flask me-2"></i> Review Diagnostics</button>
                                <button class="action-btn action-btn-outline" onclick="window.location.href='clinical_communication.php'"><i class="fas fa-user-md me-2"></i> Refer Patient</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php include('assets/inc/footer.php'); ?>
</div>

<div id="loadingOverlay" class="loading-overlay"><div class="spinner"></div></div>

<script src="assets/js/vendor.min.js"></script>
<script src="assets/js/bootstrap.bundle.min.js"></script>
<script>
const doctorId = <?php echo $user_id; ?>;
const branchId = <?php echo $branch_id; ?>;
const csrfToken = <?php echo json_encode($csrf_token); ?>;
let autoRefreshInterval = null;
let lastUpdated = new Date();

// ============================================
// UTILITY FUNCTIONS
// ============================================

function showToast(message, type = 'success') {
    const toast = document.createElement('div');
    toast.className = `toast-notification ${type === 'error' ? 'toast-error' : ''}`;
    toast.innerHTML = `<i class="fas fa-${type === 'error' ? 'exclamation-circle' : 'check-circle'}"></i> <span>${escapeHtml(message)}</span>`;
    document.body.appendChild(toast);
    setTimeout(() => toast.remove(), 3000);
}

function showLoading(show) {
    document.getElementById('loadingOverlay').classList.toggle('active', show);
}

function escapeHtml(unsafe) {
    if (!unsafe) return '';
    return String(unsafe).replace(/[&<>]/g, m => m === '&' ? '&amp;' : (m === '<' ? '&lt;' : '&gt;'));
}

function updateTimestamp() {
    lastUpdated = new Date();
    document.getElementById('lastUpdated').textContent = 'Last updated: ' + lastUpdated.toLocaleTimeString();
}

function startConsultation(visitId) {
    window.location.href = `current_consultation.php?visit_id=${visitId}`;
}

// ============================================
// CALL PATIENT (FIXED - New Feature)
// ============================================

function callPatient(visitId) {
    if (!visitId) {
        showToast('Invalid visit ID', 'error');
        return;
    }
    
    if (!confirm('Are you sure you want to call this patient?')) {
        return;
    }
    
    showLoading(true);
    $.post(window.location.pathname + '?ajax=doctor_action', {
        action: 'call_patient',
        visit_id: visitId,
        csrf_token: csrfToken
    }, function(res) {
        showLoading(false);
        if (res.success) {
            showToast(res.message);
            updateTimestamp();
            fetchKPIs();
            fetchQueue();
        } else {
            showToast(res.message || 'Failed to call patient', 'error');
        }
    }).fail(function() {
        showLoading(false);
        showToast('Network error', 'error');
    });
}

// ============================================
// UPDATE APPOINTMENT STATUS
// ============================================

function updateAppointmentStatus(appointmentId, status) {
    showLoading(true);
    $.post(window.location.pathname + '?ajax=doctor_action', {
        action: 'update_appointment_status',
        appointment_id: appointmentId,
        status: status,
        csrf_token: csrfToken
    }, function(res) {
        showLoading(false);
        if (res.success) {
            showToast(res.message);
            updateTimestamp();
            fetchKPIs();
            fetchQueue();
        } else {
            showToast(res.message || 'Failed', 'error');
        }
    }).fail(function() {
        showLoading(false);
        showToast('Network error', 'error');
    });
}

// ============================================
// REVIEW LAB RESULT
// ============================================

function reviewLabResult(resultId, patientId) {
    showLoading(true);
    $.post(window.location.pathname + '?ajax=doctor_action', {
        action: 'review_lab_result',
        result_id: resultId,
        csrf_token: csrfToken
    }, function(res) {
        showLoading(false);
        if (res.success) {
            showToast(res.message);
            updateTimestamp();
            fetchKPIs();
            fetchCriticalLabs();
            if (patientId) {
                window.location.href = `patient_record.php?patient_id=${patientId}`;
            }
        } else {
            showToast(res.message || 'Failed', 'error');
        }
    }).fail(function() {
        showLoading(false);
        showToast('Network error', 'error');
    });
}

// ============================================
// ACKNOWLEDGE REFERRAL
// ============================================

function acknowledgeReferral(referralId) {
    showLoading(true);
    $.post(window.location.pathname + '?ajax=doctor_action', {
        action: 'acknowledge_referral',
        referral_id: referralId,
        csrf_token: csrfToken
    }, function(res) {
        showLoading(false);
        if (res.success) {
            showToast(res.message);
            updateTimestamp();
            fetchKPIs();
            fetchReferrals();
        } else {
            showToast(res.message || 'Failed', 'error');
        }
    }).fail(function() {
        showLoading(false);
        showToast('Network error', 'error');
    });
}

// ============================================
// MARK NOTIFICATION READ
// ============================================

function markNotificationRead(notificationId) {
    showLoading(true);
    $.post(window.location.pathname + '?ajax=doctor_action', {
        action: 'mark_notification_read',
        notification_id: notificationId,
        csrf_token: csrfToken
    }, function(res) {
        showLoading(false);
        if (res.success) {
            showToast(res.message);
            updateTimestamp();
            fetchKPIs();
            fetchNotifications();
        } else {
            showToast(res.message || 'Failed', 'error');
        }
    }).fail(function() {
        showLoading(false);
        showToast('Network error', 'error');
    });
}

// ============================================
// SHOW CLINICAL TAB
// ============================================

function showClinicalTab(tab) {
    document.querySelectorAll('.mini-tab').forEach(t => t.classList.remove('active'));
    document.querySelector(`.mini-tab[onclick*="${tab}"]`)?.classList.add('active');
    document.getElementById('prescriptionsSection').style.display = tab === 'prescriptions' ? 'block' : 'none';
    document.getElementById('proceduresSection').style.display = tab === 'procedures' ? 'block' : 'none';
}

// ============================================
// AJAX POLLING FUNCTIONS
// ============================================

function fetchKPIs() {
    $.post(window.location.pathname + '?ajax=doctor_action', {
        action: 'fetch_kpis',
        csrf_token: csrfToken
    }, function(res) {
        if (res.success && res.metrics) {
            const m = res.metrics;
            $('#kpiWaitingQueue').text(m.waiting_queue ?? 0);
            $('#kpiActiveInpatients').text(m.active_inpatients ?? 0);
            $('#kpiCriticalDiagnostics').text(m.critical_diagnostics ?? 0);
            $('#kpiPendingReferrals').text(m.pending_referrals ?? 0);
            $('#kpiPendingPrescriptions').text(m.pending_prescriptions ?? 0);
            $('#kpiScheduledProcedures').text(m.scheduled_procedures ?? 0);
            $('#kpiPendingRadiology').text(m.pending_radiology ?? 0);
            $('#kpiPendingDischarge').text(m.pending_discharge ?? 0);
            $('#kpiUnreadNotifications').text(m.unread_notifications ?? 0);
            $('#criticalCount').text(m.critical_diagnostics ?? 0);
            $('#notifCount').text(m.unread_notifications ?? 0);
            
            // Show floating critical alert if there are critical diagnostics
            if (m.critical_diagnostics > 0) {
                $('#criticalAlertMessage').text(m.critical_diagnostics + ' critical lab result(s) require immediate review!');
                $('#floatingCriticalAlert').show();
            } else {
                $('#floatingCriticalAlert').hide();
            }
        }
    }).fail(function() {
        console.error('KPIs fetch failed');
    });
}

function fetchQueue() {
    $.post(window.location.pathname + '?ajax=doctor_action', {
        action: 'fetch_queue',
        csrf_token: csrfToken
    }, function(res) {
        if (res.success && res.queue) {
            let tbody = $('#queueBody');
            tbody.empty();
            if (res.queue.length === 0) {
                tbody.append('<tr><td colspan="7" class="empty-state"><i class="fas fa-smile-wink fa-2x mb-2 d-block"></i>No patients waiting in queue</td></tr>');
            } else {
                let cnt = 1;
                res.queue.forEach(p => {
                    let priorityClass = 'priority-green', priorityDisplay = 'Normal';
                    if (p.triage_priority === 'red') {
                        priorityClass = 'priority-red';
                        priorityDisplay = '<span class="emergency-alert"><i class="fas fa-exclamation-triangle me-1"></i>EMERGENCY</span>';
                    } else if (p.triage_priority === 'yellow') {
                        priorityClass = 'priority-yellow';
                        priorityDisplay = 'Urgent';
                    } else if (p.triage_priority === 'blue') {
                        priorityClass = 'priority-blue';
                        priorityDisplay = 'Expectant';
                    }
                    
                    let statusDisplay = p.status_display || 'Waiting';
                    let isCalled = p.queue_status === 'called';
                    let callBtn = '';
                    if (!isCalled && p.queue_status !== 'called') {
                        callBtn = `<button class="action-btn action-btn-warning call-btn mt-1" onclick="callPatient(${p.visit_id})"><i class="fas fa-bell"></i> Call</button>`;
                    }
                    
                    tbody.append(`
                        <tr>
                            <td>${cnt++}</td>
                            <td><strong>${escapeHtml(p.patient_name)}</strong></td>
                            <td>${escapeHtml(p.mr_number || 'N/A')}</td>
                            <td>${p.age}</td>
                            <td class="${priorityClass}">${priorityDisplay}</td>
                            <td><span class="status-badge ${isCalled ? 'priority-yellow' : 'priority-green'}">${statusDisplay}</span></td>
                            <td>
                                <button class="action-btn action-btn-primary" onclick="startConsultation(${p.visit_id})"><i class="fas fa-play"></i> Start</button>
                                ${callBtn}
                            </td>
                        </tr>
                    `);
                });
            }
            updateTimestamp();
        }
    }).fail(function() {
        console.error('Queue fetch failed');
    });
}

function fetchCriticalLabs() {
    $.post(window.location.pathname + '?ajax=doctor_action', {
        action: 'fetch_critical_labs',
        csrf_token: csrfToken
    }, function(res) {
        const container = $('#criticalLabsBody');
        if (res.success && res.critical_labs && res.critical_labs.length > 0) {
            let html = '';
            res.critical_labs.forEach(lab => {
                html += `
                    <div class="list-group-item" style="border-left:3px solid #ef476f;">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <strong class="text-danger">⚠️ CRITICAL</strong>
                                <div class="fw-bold mt-1">${escapeHtml(lab.patient_name)}</div>
                                <div class="small text-muted">MRN: ${escapeHtml(lab.mr_number || 'N/A')} | Age: ${lab.age}</div>
                                <div class="small mt-1">Test: ${escapeHtml(lab.test_name)}</div>
                                <div class="small">Result: ${lab.result_numeric || lab.result_text || 'N/A'} ${escapeHtml(lab.result_unit || '')}</div>
                                <div class="small text-muted">Ref: ${escapeHtml(lab.reference_range_text || 'N/A')}</div>
                            </div>
                            <div><button class="action-btn action-btn-success" onclick="reviewLabResult(${lab.result_id}, ${lab.patient_id})"><i class="fas fa-eye"></i> Review</button></div>
                        </div>
                    </div>
                `;
            });
            container.html(html);
            
            // Show floating alert
            $('#criticalAlertMessage').text(res.critical_labs.length + ' critical lab result(s) require immediate review!');
            $('#floatingCriticalAlert').show();
        } else {
            container.html('<div class="empty-state"><i class="fas fa-check-circle fa-2x mb-2 text-success"></i><p>No unread critical lab results</p></div>');
            $('#floatingCriticalAlert').hide();
        }
    }).fail(function() {
        console.error('Critical labs fetch failed');
    });
}

function fetchReferrals() {
    $.post(window.location.pathname + '?ajax=doctor_action', {
        action: 'fetch_referrals',
        csrf_token: csrfToken
    }, function(res) {
        const container = $('#referralsBody');
        if (res.success && res.referrals && res.referrals.length > 0) {
            let html = '';
            res.referrals.forEach(ref => {
                html += `
                    <div class="list-group-item">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <div class="fw-bold">${escapeHtml(ref.patient_name)}</div>
                                <div class="small text-muted">MRN: ${escapeHtml(ref.mr_number || 'N/A')} | Age: ${ref.age}</div>
                                <div class="small mt-1">From: Dr. ${escapeHtml(ref.referring_doctor)}</div>
                                <div class="small text-muted">Reason: ${escapeHtml((ref.referral_reason || 'N/A').substring(0,60))}</div>
                            </div>
                            <div><button class="action-btn action-btn-primary" onclick="acknowledgeReferral(${ref.referral_id})"><i class="fas fa-check-circle"></i> Acknowledge</button></div>
                        </div>
                    </div>
                `;
            });
            container.html(html);
        } else {
            container.html('<div class="empty-state"><i class="fas fa-comment-slash fa-2x mb-2 text-muted"></i><p>No pending referrals</p></div>');
        }
    }).fail(function() {
        console.error('Referrals fetch failed');
    });
}

function fetchInpatients() {
    $.post(window.location.pathname + '?ajax=doctor_action', {
        action: 'fetch_inpatients',
        csrf_token: csrfToken
    }, function(res) {
        const container = $('#inpatientsBody');
        if (res.success && res.inpatients && res.inpatients.length > 0) {
            let html = '';
            res.inpatients.forEach(ip => {
                html += `
                    <div class="list-group-item">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <div class="fw-bold">${escapeHtml(ip.patient_name)}</div>
                                <div class="small text-muted">MRN: ${escapeHtml(ip.mr_number || 'N/A')} | Age: ${ip.age} | Ward: ${escapeHtml(ip.ward_name || 'N/A')} | Bed: ${escapeHtml(ip.bed_number || 'N/A')}</div>
                                <div class="small mt-1">Dx: ${escapeHtml((ip.primary_diagnosis || 'N/A').substring(0,60))}</div>
                                <div class="small">LOS: ${ip.length_of_stay} days | Condition: <span class="condition-${(ip.condition_status || 'stable').toLowerCase()}">${escapeHtml(ip.condition_status || 'Stable')}</span></div>
                            </div>
                            <div>
                                <button class="action-btn action-btn-primary" onclick="window.location.href='ward_management.php?admission_id=${ip.admission_id}'"><i class="fas fa-notes-medical"></i> Round</button>
                                <button class="action-btn action-btn-success mt-1" onclick="window.location.href='discharge_summary.php?admission_id=${ip.admission_id}'"><i class="fas fa-door-open"></i> Discharge</button>
                            </div>
                        </div>
                    </div>
                `;
            });
            container.html(html);
        } else {
            container.html('<div class="empty-state"><i class="fas fa-hospital-user fa-2x mb-2 text-muted"></i><p>No active inpatients assigned</p></div>');
        }
    }).fail(function() {
        console.error('Inpatients fetch failed');
    });
}

function fetchPrescriptions() {
    $.post(window.location.pathname + '?ajax=doctor_action', {
        action: 'fetch_prescriptions',
        csrf_token: csrfToken
    }, function(res) {
        const container = $('#prescriptionsSection');
        if (res.success && res.prescriptions && res.prescriptions.length > 0) {
            let html = '';
            res.prescriptions.forEach(rx => {
                html += `
                    <div class="list-group-item">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <div class="fw-bold">${escapeHtml(rx.patient_name)}</div>
                                <div class="small text-muted">MRN: ${escapeHtml(rx.mr_number || 'N/A')} | ${escapeHtml(rx.prescription_number)}</div>
                                <div class="small mt-1">${rx.item_count} items | Status: ${getStatusBadge(rx.status)}</div>
                            </div>
                            <div>
                                <button class="action-btn action-btn-primary" onclick="window.location.href='prescription_view.php?id=${rx.prescription_id}'"><i class="fas fa-eye"></i> View</button>
                            </div>
                        </div>
                    </div>
                `;
            });
            container.html(html);
        } else {
            container.html('<div class="empty-state"><p>No pending prescriptions</p></div>');
        }
    }).fail(function() {
        console.error('Prescriptions fetch failed');
    });
}

function fetchProcedures() {
    $.post(window.location.pathname + '?ajax=doctor_action', {
        action: 'fetch_procedures',
        csrf_token: csrfToken
    }, function(res) {
        const container = $('#proceduresSection');
        if (res.success && res.procedures && res.procedures.length > 0) {
            let html = '';
            res.procedures.forEach(proc => {
                html += `
                    <div class="list-group-item">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <div class="fw-bold">${escapeHtml(proc.patient_name)}</div>
                                <div class="small text-muted">MRN: ${escapeHtml(proc.mr_number || 'N/A')} | ${escapeHtml(proc.procedure_number)}</div>
                                <div class="small mt-1">${escapeHtml(proc.procedure_name)} | Priority: ${getStatusBadge(proc.priority)}</div>
                                <div class="small">Scheduled: ${new Date(proc.scheduled_date + ' ' + (proc.scheduled_time || '00:00:00')).toLocaleString()}</div>
                            </div>
                            <div>
                                <button class="action-btn action-btn-primary" onclick="window.location.href='procedure_view.php?id=${proc.procedure_id}'"><i class="fas fa-eye"></i> View</button>
                            </div>
                        </div>
                    </div>
                `;
            });
            container.html(html);
        } else {
            container.html('<div class="empty-state"><p>No scheduled procedures</p></div>');
        }
    }).fail(function() {
        console.error('Procedures fetch failed');
    });
}

function fetchDischarges() {
    $.post(window.location.pathname + '?ajax=doctor_action', {
        action: 'fetch_discharges',
        csrf_token: csrfToken
    }, function(res) {
        const container = $('#dischargesBody');
        if (res.success && res.discharges && res.discharges.length > 0) {
            let html = '';
            res.discharges.forEach(dc => {
                html += `
                    <div class="list-group-item">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <div class="fw-bold">${escapeHtml(dc.patient_name)}</div>
                                <div class="small text-muted">MRN: ${escapeHtml(dc.mr_number || 'N/A')} | Age: ${dc.age} | LOS: ${dc.length_of_stay} days</div>
                                <div class="small mt-1">Dx: ${escapeHtml((dc.primary_diagnosis || 'N/A').substring(0,60))}</div>
                            </div>
                            <div>
                                <button class="action-btn action-btn-success" onclick="window.location.href='discharge_summary.php?admission_id=${dc.admission_id}'"><i class="fas fa-file-alt"></i> Create Summary</button>
                            </div>
                        </div>
                    </div>
                `;
            });
            container.html(html);
        } else {
            container.html('<div class="empty-state"><p>No pending discharges</p></div>');
        }
    }).fail(function() {
        console.error('Discharges fetch failed');
    });
}

function fetchNotifications() {
    $.post(window.location.pathname + '?ajax=doctor_action', {
        action: 'fetch_notifications',
        csrf_token: csrfToken
    }, function(res) {
        const container = $('#notificationsBody');
        if (res.success && res.notifications && res.notifications.length > 0) {
            let html = '';
            res.notifications.forEach(notif => {
                html += `
                    <div class="list-group-item ${notif.is_read ? '' : 'notification-unread'}">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <div class="fw-semibold">${escapeHtml(notif.title)}</div>
                                <div class="small text-muted">${escapeHtml((notif.message || '').substring(0,80))}</div>
                                <div class="small text-muted mt-1">${new Date(notif.created_at).toLocaleString()}</div>
                            </div>
                            ${!notif.is_read ? `<div><button class="action-btn action-btn-outline" onclick="markNotificationRead(${notif.id})"><i class="fas fa-check"></i></button></div>` : ''}
                        </div>
                    </div>
                `;
            });
            container.html(html);
        } else {
            container.html('<div class="empty-state"><p>No notifications</p></div>');
        }
    }).fail(function() {
        console.error('Notifications fetch failed');
    });
}

// ============================================
// GET STATUS BADGE (Client-side helper)
// ============================================

function getStatusBadge(status) {
    if (!status) return '<span class="badge bg-secondary">N/A</span>';
    
    const badgeMap = {
        'pending_payment': 'warning',
        'unpaid': 'warning',
        'paid': 'success',
        'completed': 'success',
        'cancelled': 'danger',
        'pending': 'warning',
        'approved': 'success',
        'collected': 'info',
        'in_progress': 'primary',
        'active': 'success',
        'dispensed': 'success',
        'scheduled': 'info',
        'partial': 'warning',
        'routine': 'primary',
        'urgent': 'warning',
        'emergency': 'danger',
        'elective': 'info',
        'stat': 'danger'
    };
    
    const cls = badgeMap[status] || 'secondary';
    const label = status.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
    return `<span class="badge bg-${cls}">${label}</span>`;
}

// ============================================
// INITIALIZATION
// ============================================

$(document).ready(function() {
    // Initial load
    fetchKPIs();
    fetchQueue();
    fetchCriticalLabs();
    fetchReferrals();
    fetchInpatients();
    fetchPrescriptions();
    fetchProcedures();
    fetchDischarges();
    fetchNotifications();
    updateTimestamp();
    
    // Auto-refresh every 15 seconds
    autoRefreshInterval = setInterval(function() {
        fetchKPIs();
        fetchQueue();
        fetchCriticalLabs();
        fetchReferrals();
        fetchInpatients();
        fetchPrescriptions();
        fetchProcedures();
        fetchDischarges();
        fetchNotifications();
    }, 15000);
});

// Clean up interval on page unload
$(window).on('beforeunload', function() {
    if (autoRefreshInterval) {
        clearInterval(autoRefreshInterval);
    }
});

// ============================================
// SIDEBAR TOGGLE COMPATIBILITY
// ============================================

if (typeof MetisMenu !== 'undefined') {
    const sideMenu = document.getElementById('side-menu');
    if (sideMenu && !sideMenu.hasAttribute('data-metismenu-initialized')) {
        new MetisMenu(sideMenu, {
            toggle: true,
            triggerElement: 'a.has-arrow',
            parentTrigger: 'li',
            subMenu: 'ul'
        });
        sideMenu.setAttribute('data-metismenu-initialized', 'true');
    }
}

document.getElementById('mobileMenuToggle')?.addEventListener('click', function(e) {
    e.preventDefault();
    document.body.classList.toggle('sidebar-enable');
});

window.addEventListener('resize', function() {
    if (window.innerWidth > 768 && document.body.classList.contains('sidebar-enable')) {
        document.body.classList.remove('sidebar-enable');
    }
});
</script>
</body>
</html>