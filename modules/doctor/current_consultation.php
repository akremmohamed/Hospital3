<?php
/**
 * ======================================================================
 * DOCTOR CONSULTATION HUB - current_consultation.php
 * VERSION: COMPLETE v8.0 - UPGRADED WITH ALL FEATURES
 * 
 * NEW FEATURES IN THIS VERSION:
 * - Lab Results: Cumulative Trends & Amended Flags
 * - Lab Orders: Panel/Profile Ordering
 * - Radiology: Bulk Ordering & DICOM Links
 * - Patient History: Server-Side Pagination & Filtering
 * ======================================================================
 */

session_start();

// ============================================
// 1. BOOTSTRAP & SECURITY
// ============================================
require_once __DIR__ . '/../../config/db_connection.php';
require_once __DIR__ . '/../../includes/auth_helper.php';

date_default_timezone_set('Africa/Addis_Ababa');

$pdo = getDB();
if ($pdo) {
    $pdo->exec("SET time_zone = '+03:00'");
}

$user_id = (int)($_SESSION['user_id'] ?? 0);
$branch_id = (int)($_SESSION['branch_id'] ?? 0);
$is_global_admin = ($_SESSION['user_type'] ?? '') === 'super_admin';
$user_type = $_SESSION['user_type'] ?? '';
$first_name = $_SESSION['first_name'] ?? '';
$last_name = $_SESSION['last_name'] ?? '';

if ($user_id <= 0) {
    header('Location: ../../index.php');
    exit;
}

// Permission check
if (!$is_global_admin && !user_has_permission($pdo, 'start_consultation', $user_id)) {
    header('Location: dashboard.php?error=unauthorized');
    exit;
}

// CSRF token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// ============================================
// 2. HELPER FUNCTIONS
// ============================================
function jsonResponse($success, $message, $data = null, $code = 200) {
    if (ob_get_length()) ob_clean();
    http_response_code($code);
    header('Content-Type: application/json');
    header('X-Content-Type-Options: nosniff');
    $response = ['success' => $success, 'message' => $message];
    if ($data !== null) $response = array_merge($response, $data);
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

function formatDate($date) {
    if (empty($date)) return 'N/A';
    try {
        return date('Y-m-d H:i', strtotime($date));
    } catch (Exception $e) {
        return $date;
    }
}

function calculateQuantity($frequency, $durationDays) {
    $freqLower = strtolower(trim($frequency));
    $dailyDose = 1;
    if (strpos($freqLower, 'bid') !== false) $dailyDose = 2;
    elseif (strpos($freqLower, 'tid') !== false) $dailyDose = 3;
    elseif (strpos($freqLower, 'qid') !== false) $dailyDose = 4;
    elseif (strpos($freqLower, 'qd') !== false || strpos($freqLower, 'od') !== false) $dailyDose = 1;
    elseif (preg_match('/(\d+)\s*(?:x|times?)/', $freqLower, $matches)) $dailyDose = (int)$matches[1];
    return $dailyDose * max(1, (int)$durationDays);
}

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

function generateLabOrderNumber() {
    return 'LAB-' . date('Ymd') . '-' . str_pad((string)random_int(1, 9999), 4, '0', STR_PAD_LEFT);
}

function normalizeSpecimenType($sampleType) {
    $allowed = ['blood', 'serum', 'plasma', 'urine', 'stool', 'sputum', 'csf', 'swab', 'tissue', 'saliva', 'bone_marrow', 'other'];
    $normalized = strtolower(trim((string)$sampleType));
    if ($normalized === '' || !in_array($normalized, $allowed, true)) {
        return 'other';
    }
    return $normalized;
}

function getStatusBadge($status, $type = 'default') {
    $badges = [
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
        'stable' => 'success',
        'guarded' => 'warning',
        'critical' => 'danger',
        'serious' => 'danger',
        'received' => 'info',
        'processing' => 'primary',
        'rejected' => 'danger',
        'discarded' => 'secondary',
        'draft' => 'secondary',
        'finalized' => 'success',
        'amended' => 'warning',
        'billed' => 'info',
        'expired' => 'danger',
        'partial' => 'warning',
        'refunded' => 'info',
        'waived' => 'secondary',
        'overdue' => 'danger',
        'inactive' => 'secondary'
    ];
    
    $class = $badges[$status] ?? 'secondary';
    return "<span class='badge bg-{$class}'>{$status}</span>";
}

// ============================================
// 3. AJAX HANDLER - Full Workflow
// ============================================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'consultation_action') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(false, 'Invalid request method');
    }

    $action = $_POST['action'] ?? '';
    $csrf_protected_actions = ['fetch_queue', 'fetch_lab_queue', 'call_patient', 'start_consultation', 'save_soap',
                                'search_drugs', 'search_labs', 'save_prescription', 'save_lab_order', 
                                'get_order_history', 'complete_consultation', 'save_radiology_order',
                                'save_procedure_order', 'fetch_lab_status', 'fetch_patient_insurance', 
                                'request_consultation', 'refer_patient', 'fetch_lab_results',
                                'review_lab_result', 'get_patient_medications', 'get_consultation_history',
                                'get_patient_vitals_history', 'get_patient_allergies', 'fetch_family_history',
                                'create_discharge_summary', 'admit_patient', 'fetch_specimen_status',
                                'create_specimen', 'fetch_critical_alerts', 'mark_alert_read',
                                'fetch_patient_data', 'fetch_admission_details', 'update_condition_status',
                                'fetch_available_beds', 'get_patient_invoices', 'fetch_radiology_status',
                                'fetch_procedure_status', 'save_family_history_note', 'mark_result_viewed',
                                'bulk_mark_viewed', 'fetch_lab_trends', 'fetch_lab_panels'];
    
    if (in_array($action, $csrf_protected_actions)) {
        if (!hash_equals($csrf_token, (string)($_POST['csrf_token'] ?? ''))) {
            jsonResponse(false, 'Security token mismatch');
        }
    }

    try {
        // ---------- FETCH PATIENT QUEUE ----------
        if ($action === 'fetch_queue') {
            // [Existing code - kept intact for brevity]
            $tab = $_POST['tab'] ?? 'pending';
            
            $status_conditions = array();
            $order_by = "";
            $params = array(':doctor_id' => $user_id);
            $having_clause = "";
            
            switch ($tab) {
                case 'pending':
                    $status_conditions = array(
                        "pv.status IN ('checked_in', 'in_triage', 'in_consultation', 'awaiting_pharmacy')"
                    );
                    $having_clause = "HAVING pending_lab_count = 0";
                    $order_by = "CASE WHEN a.priority = 'stat' THEN 1 WHEN a.priority = 'urgent' THEN 2 ELSE 3 END, pv.checked_in_at ASC";
                    break;
                    
                case 'lab_pending':
                    $status_conditions = array(
                        "pv.status IN ('checked_in', 'in_triage', 'in_consultation', 'awaiting_lab', 'awaiting_pharmacy')"
                    );
                    $having_clause = "HAVING pending_lab_count > 0";
                    $order_by = "CASE WHEN a.priority = 'stat' THEN 1 WHEN a.priority = 'urgent' THEN 2 ELSE 3 END, pv.checked_in_at ASC";
                    break;
                    
                case 'lab_ready':
                    $status_conditions = array(
                        "pv.status = 'lab_ready'"
                    );
                    $having_clause = "";
                    $order_by = "CASE WHEN a.priority = 'stat' THEN 1 WHEN a.priority = 'urgent' THEN 2 ELSE 3 END, pv.checked_in_at ASC";
                    break;
                    
                case 'completed':
                    $status_conditions = array("pv.status = 'completed'");
                    $having_clause = "";
                    $order_by = "pv.consultation_completed_at DESC";
                    break;
                    
                default:
                    $status_conditions = array("pv.status IN ('checked_in', 'in_triage', 'in_consultation', 'awaiting_lab', 'awaiting_pharmacy')");
                    $having_clause = "";
                    $order_by = "CASE WHEN a.priority = 'stat' THEN 1 WHEN a.priority = 'urgent' THEN 2 ELSE 3 END, pv.checked_in_at ASC";
            }
            
            $sql = "
                SELECT 
                    pv.id AS visit_id,
                    pv.patient_id,
                    pv.queue_status,
                    pv.checked_in_at,
                    pv.consultation_started_at,
                    pv.consultation_completed_at,
                    pv.status AS visit_status,
                    pv.entered_room_at,
                    
                    CONCAT(pat.first_name, ' ', COALESCE(pat.middle_name,''), ' ', pat.last_name) AS patient_name,
                    pat.mr_number,
                    pat.date_of_birth,
                    pat.gender,
                    pat.phone,
                    pat.blood_group,
                    
                    tr.id AS triage_id,
                    tr.heart_rate,
                    tr.blood_pressure_systolic,
                    tr.blood_pressure_diastolic,
                    tr.temperature_celsius,
                    tr.respiratory_rate,
                    tr.oxygen_saturation,
                    tr.weight_kg,
                    tr.priority AS triage_priority,
                    tr.chief_complaint,
                    tr.allergies,
                    tr.pain_score,
                    tr.consciousness,
                    
                    a.id AS appointment_id,
                    a.priority AS appointment_priority,
                    a.appointment_number,
                    a.appointment_time,
                    
                    (SELECT COUNT(*) FROM lab_orders WHERE visit_id = pv.id AND status IN ('pending_payment', 'pending', 'approved', 'collected', 'in_progress') AND deleted_at IS NULL) AS pending_lab_count,
                    
                    (SELECT COUNT(*) FROM lab_orders WHERE visit_id = pv.id AND status = 'completed' AND deleted_at IS NULL) AS completed_lab_count,
                    
                    (SELECT COUNT(*) FROM lab_test_results ltr 
                    INNER JOIN lab_orders lo ON ltr.lab_order_id = lo.id 
                    WHERE lo.visit_id = pv.id AND ltr.doctor_reviewed_at IS NULL AND lo.status = 'completed') AS unread_results,
                    
                    (SELECT COUNT(*) FROM lab_test_results ltr 
                    INNER JOIN lab_orders lo ON ltr.lab_order_id = lo.id 
                    WHERE lo.visit_id = pv.id AND ltr.is_critical = 1 AND ltr.doctor_reviewed_at IS NULL AND lo.status = 'completed') AS critical_unread,
                    
                    (SELECT COUNT(*) FROM prescriptions WHERE visit_id = pv.id AND status IN ('pending_payment', 'active') AND deleted_at IS NULL) AS pending_rx_count,
                    
                    (SELECT COUNT(*) FROM admissions WHERE visit_id = pv.id AND status = 'admitted' AND deleted_at IS NULL) AS has_active_admission,
                    
                    TIMESTAMPDIFF(MINUTE, pv.checked_in_at, NOW()) AS waiting_minutes
                    
                FROM patient_visits pv
                INNER JOIN appointments a ON pv.id = a.visit_id
                INNER JOIN patients pat ON pv.patient_id = pat.id AND pat.deleted_at IS NULL
                LEFT JOIN triage_records tr ON tr.visit_id = pv.id AND tr.deleted_at IS NULL
                WHERE a.doctor_id = :doctor_id
                AND pv.deleted_at IS NULL
                AND (" . implode(' OR ', $status_conditions) . ")
            ";
            
            if (!$is_global_admin && $branch_id > 0) {
                $sql .= " AND pv.branch_id = :branch_id";
                $params[':branch_id'] = $branch_id;
            }
            
            $sql .= " GROUP BY pv.id " . $having_clause . " ORDER BY " . $order_by;
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $queue = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $counts = array('pending' => 0, 'lab_pending' => 0, 'lab_ready' => 0, 'completed' => 0);
            
            foreach ($queue as &$row) {
                $row['age'] = 'N/A';
                if (!empty($row['date_of_birth'])) {
                    try {
                        $birthDate = new DateTime($row['date_of_birth']);
                        $today = new DateTime();
                        $row['age'] = $birthDate->diff($today)->y;
                    } catch (Exception $e) {
                        $row['age'] = 'N/A';
                    }
                }
                
                $row['bp'] = ($row['blood_pressure_systolic'] && $row['blood_pressure_diastolic']) 
                    ? $row['blood_pressure_systolic'] . '/' . $row['blood_pressure_diastolic'] 
                    : '--/--';
                
                if ($row['visit_status'] === 'completed') {
                    $row['status_display'] = 'Completed';
                    $row['status_class'] = 'status-completed';
                    $row['status_icon'] = 'fa-check-circle';
                } elseif ($row['visit_status'] === 'lab_ready' || $row['unread_results'] > 0 || $row['critical_unread'] > 0) {
                    $row['status_display'] = 'Lab Ready';
                    $row['status_class'] = 'status-labready';
                    $row['status_icon'] = 'fa-flask';
                } elseif ($row['pending_lab_count'] > 0) {
                    $row['status_display'] = 'Lab Pending';
                    $row['status_class'] = 'status-labpending';
                    $row['status_icon'] = 'fa-hourglass-half';
                } elseif ($row['consultation_started_at'] && $row['visit_status'] !== 'completed') {
                    $row['status_display'] = 'In Progress';
                    $row['status_class'] = 'status-inroom';
                    $row['status_icon'] = 'fa-play-circle';
                } else {
                    $row['status_display'] = 'Waiting';
                    $row['status_class'] = 'status-waiting';
                    $row['status_icon'] = 'fa-clock';
                }
                
                $row['waiting_display'] = $row['waiting_minutes'] > 30 
                    ? '<span class="text-danger">' . $row['waiting_minutes'] . ' min</span>' 
                    : $row['waiting_minutes'] . ' min';
                
                $badges = array();
                
                if ($row['critical_unread'] > 0) {
                    $badges[] = '<span class="badge bg-danger"><i class="fas fa-exclamation-triangle"></i> ' . $row['critical_unread'] . ' Critical</span>';
                }
                
                if ($row['unread_results'] > 0 && $row['critical_unread'] == 0) {
                    $badges[] = '<span class="badge bg-info"><i class="fas fa-bell"></i> ' . $row['unread_results'] . ' New</span>';
                }
                
                if ($row['pending_lab_count'] > 0) {
                    $badges[] = '<span class="badge bg-warning text-dark"><i class="fas fa-clock"></i> ' . $row['pending_lab_count'] . ' Lab Pending</span>';
                }
                
                if ($row['completed_lab_count'] > 0) {
                    $badges[] = '<span class="badge bg-success"><i class="fas fa-check-circle"></i> ' . $row['completed_lab_count'] . ' Lab Done</span>';
                }
                
                if ($row['pending_rx_count'] > 0) {
                    $badges[] = '<span class="badge bg-primary"><i class="fas fa-prescription"></i> ' . $row['pending_rx_count'] . ' Rx</span>';
                }
                
                if ($row['has_active_admission'] > 0) {
                    $badges[] = '<span class="badge bg-info"><i class="fas fa-hospital"></i> Admitted</span>';
                }
                
                $row['badges_html'] = implode(' ', $badges);
                
                if ($row['visit_status'] === 'completed') {
                    $counts['completed']++;
                } elseif ($row['visit_status'] === 'lab_ready' || $row['unread_results'] > 0 || $row['critical_unread'] > 0) {
                    $counts['lab_ready']++;
                } elseif ($row['pending_lab_count'] > 0) {
                    $counts['lab_pending']++;
                } else {
                    $counts['pending']++;
                }
            }
            
            jsonResponse(true, '', array(
                'queue' => $queue, 
                'counts' => $counts,
                'current_tab' => $tab
            ));
        }

        // ---------- FETCH PATIENT DATA ----------
        if ($action === 'fetch_patient_data') {
            // [Existing code - kept intact]
            $patient_id = (int)($_POST['patient_id'] ?? 0);
            if ($patient_id <= 0) jsonResponse(false, 'Invalid patient ID');
            
            $sql = "
                SELECT 
                    p.id,
                    p.first_name,
                    p.middle_name,
                    p.last_name,
                    p.mr_number,
                    p.date_of_birth,
                    p.gender,
                    p.phone,
                    p.email,
                    p.address,
                    p.national_id,
                    p.blood_group,
                    p.emergency_contact_name,
                    p.emergency_contact_phone,
                    p.emergency_contact_relationship,
                    p.is_active,
                    p.is_consent_given,
                    p.consent_given_at,
                    (SELECT COUNT(*) FROM patient_relationships WHERE primary_patient_id = p.id AND deleted_at IS NULL) AS family_count,
                    (SELECT COUNT(*) FROM patient_insurance_profiles WHERE patient_id = p.id AND status = 'active' AND deleted_at IS NULL) AS insurance_count,
                    (SELECT COUNT(*) FROM admissions WHERE patient_id = p.id AND status = 'admitted' AND deleted_at IS NULL) AS active_admissions
                FROM patients p
                WHERE p.id = :id AND p.deleted_at IS NULL
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':id' => $patient_id]);
            $patient = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($patient) {
                $patient['age'] = getPatientAge($patient['date_of_birth']);
                $patient['full_name'] = trim($patient['first_name'] . ' ' . ($patient['middle_name'] ?? '') . ' ' . $patient['last_name']);
            }
            
            jsonResponse(true, '', ['patient' => $patient]);
        }

        // ---------- FETCH ADMISSION DETAILS ----------
        if ($action === 'fetch_admission_details') {
            // [Existing code - kept intact]
            $visit_id = (int)($_POST['visit_id'] ?? 0);
            if ($visit_id <= 0) jsonResponse(false, 'Invalid visit ID');
            
            $sql = "
                SELECT 
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
                    a.admission_notes,
                    a.discharge_summary,
                    a.discharge_instructions,
                    a.follow_up_date,
                    a.home_medications,
                    a.warning_signs,
                    a.procedures,
                    b.bed_number,
                    b.bed_type,
                    b.daily_rate,
                    w.name AS ward_name
                FROM admissions a
                LEFT JOIN beds b ON a.bed_id = b.id
                LEFT JOIN wards w ON b.ward_id = w.id
                WHERE a.visit_id = :visit_id 
                  AND a.deleted_at IS NULL
                  AND a.status IN ('admitted', 'transferred')
                ORDER BY a.created_at DESC LIMIT 1
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':visit_id' => $visit_id]);
            $admission = $stmt->fetch(PDO::FETCH_ASSOC);
            
            jsonResponse(true, '', ['admission' => $admission]);
        }

        // ---------- FETCH AVAILABLE BEDS ----------
        if ($action === 'fetch_available_beds') {
            // [Existing code - kept intact]
            $sql = "
                SELECT 
                    b.id,
                    b.bed_number,
                    b.bed_type,
                    b.daily_rate,
                    b.notes,
                    w.name AS ward_name,
                    w.ward_type,
                    w.floor_location
                FROM beds b
                INNER JOIN wards w ON b.ward_id = w.id
                WHERE b.branch_id = :branch_id 
                AND b.status = 'available' 
                AND b.is_active = 1 
                AND b.deleted_at IS NULL
                AND w.is_active = 1
                ORDER BY w.name, b.bed_number
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(array(':branch_id' => $branch_id));
            $beds = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            jsonResponse(true, '', array('beds' => $beds));
        }

        // ---------- UPDATE CONDITION STATUS ----------
        if ($action === 'update_condition_status') {
            // [Existing code - kept intact]
            $admission_id = (int)($_POST['admission_id'] ?? 0);
            $condition_status = $_POST['condition_status'] ?? 'stable';
            $notes = trim($_POST['notes'] ?? '');
            
            if ($admission_id <= 0) jsonResponse(false, 'Invalid admission ID');
            
            $sql = "UPDATE admissions SET condition_status = :status, admission_notes = CONCAT(COALESCE(admission_notes, ''), '\n[CONDITION UPDATE: ', NOW(), '] Status changed to ', UPPER(:status), ' - ', :notes) WHERE id = :id";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':status' => $condition_status,
                ':notes' => $notes,
                ':id' => $admission_id
            ]);
            
            jsonResponse(true, 'Condition status updated successfully');
        }

        // ---------- START CONSULTATION ----------
        if ($action === 'start_consultation') {
            // [Existing code - kept intact]
            $visit_id = (int)($_POST['visit_id'] ?? 0);
            if ($visit_id <= 0) jsonResponse(false, 'Invalid visit ID');

            try {
                $pdo->beginTransaction();
                
                $sql = "UPDATE patient_visits 
                        SET queue_status = 'in_room', 
                            entered_room_at = NOW(), 
                            consultation_started_at = NOW(),
                            status = 'in_consultation',
                            updated_at = NOW() 
                        WHERE id = :id AND consultation_started_at IS NULL";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([':id' => $visit_id]);
                
                $appt_stmt = $pdo->prepare("UPDATE appointments SET status = 'in_progress', updated_at = NOW() WHERE visit_id = :id");
                $appt_stmt->execute([':id' => $visit_id]);
                
                $pdo->commit();
                jsonResponse(true, 'Consultation started');
            } catch (PDOException $e) {
                $pdo->rollBack();
                jsonResponse(false, 'Database error: ' . $e->getMessage());
            }
        }

        // ---------- SAVE SOAP NOTES ----------
        if ($action === 'save_soap') {
            // [Existing code - kept intact]
            $visit_id = (int)($_POST['visit_id'] ?? 0);
            $patient_id = (int)($_POST['patient_id'] ?? 0);
            $subjective = trim($_POST['subjective'] ?? '');
            $objective = trim($_POST['objective'] ?? '');
            $assessment = trim($_POST['assessment'] ?? '');
            $plan = trim($_POST['plan'] ?? '');
            $primary_icd10 = trim($_POST['primary_icd10'] ?? '');
            $diagnosis = trim($_POST['diagnosis'] ?? '');
            
            if ($visit_id <= 0 || $patient_id <= 0) {
                jsonResponse(false, 'Missing visit or patient ID');
            }

            try {
                $pdo->beginTransaction();
                
                $check = $pdo->prepare("SELECT id FROM consultations WHERE visit_id = :visit_id AND doctor_id = :doctor_id AND deleted_at IS NULL");
                $check->execute([':visit_id' => $visit_id, ':doctor_id' => $user_id]);
                $existing = $check->fetch(PDO::FETCH_ASSOC);
                
                if ($existing) {
                    $update_sql = "
                        UPDATE consultations 
                        SET chief_complaint = :chief_complaint,
                            history_of_present_illness = :hpi,
                            physical_examination = :physical_exam,
                            assessment = :assessment,
                            treatment_plan = :plan,
                            icd10_codes = :icd10,
                            provisional_diagnosis = :diagnosis,
                            updated_at = NOW()
                        WHERE id = :id
                    ";
                    $stmt = $pdo->prepare($update_sql);
                    $stmt->execute([
                        ':chief_complaint' => $subjective,
                        ':hpi' => $subjective,
                        ':physical_exam' => $objective,
                        ':assessment' => $assessment,
                        ':plan' => $plan,
                        ':icd10' => $primary_icd10,
                        ':diagnosis' => $diagnosis,
                        ':id' => $existing['id']
                    ]);
                    $consultation_id = $existing['id'];
                } else {
                    $consultation_number = 'CONS-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
                    $insert_sql = "
                        INSERT INTO consultations 
                        (branch_id, patient_id, doctor_id, visit_id, consultation_number, consultation_type, 
                         consultation_date, consultation_time, chief_complaint, history_of_present_illness,
                         physical_examination, assessment, treatment_plan, icd10_codes, provisional_diagnosis,
                         status, created_by_user_id, created_at, updated_at)
                        VALUES 
                        (:branch_id, :patient_id, :doctor_id, :visit_id, :consultation_number, 'initial',
                         CURDATE(), CURTIME(), :chief_complaint, :hpi,
                         :physical_exam, :assessment, :plan, :icd10, :diagnosis,
                         'draft', :user_id, NOW(), NOW())
                    ";
                    $stmt = $pdo->prepare($insert_sql);
                    $stmt->execute([
                        ':branch_id' => $branch_id,
                        ':patient_id' => $patient_id,
                        ':doctor_id' => $user_id,
                        ':visit_id' => $visit_id,
                        ':consultation_number' => $consultation_number,
                        ':chief_complaint' => $subjective,
                        ':hpi' => $subjective,
                        ':physical_exam' => $objective,
                        ':assessment' => $assessment,
                        ':plan' => $plan,
                        ':icd10' => $primary_icd10,
                        ':diagnosis' => $diagnosis,
                        ':user_id' => $user_id
                    ]);
                    $consultation_id = $pdo->lastInsertId();
                }

                $pdo->commit();
                jsonResponse(true, 'Consultation notes saved successfully', ['consultation_id' => $consultation_id]);
            } catch (PDOException $e) {
                $pdo->rollBack();
                jsonResponse(false, 'Database error: ' . $e->getMessage());
            }
        }

        // ---------- COMPLETE CONSULTATION ----------
        if ($action === 'complete_consultation') {
            // [Existing code - kept intact]
            $visit_id = (int)($_POST['visit_id'] ?? 0);
            $final_diagnosis = trim($_POST['final_diagnosis'] ?? '');
            $follow_up_required = (int)($_POST['follow_up_required'] ?? 0);
            $follow_up_date = $_POST['follow_up_date'] ?? null;
            $follow_up_instructions = trim($_POST['follow_up_instructions'] ?? '');
            $discharge_notes = trim($_POST['discharge_notes'] ?? '');
            $create_discharge_summary = (int)($_POST['create_discharge_summary'] ?? 0);
            $discharge_condition = $_POST['discharge_condition'] ?? 'improved';
            
            if ($visit_id <= 0) jsonResponse(false, 'Invalid visit ID');

            try {
                $pdo->beginTransaction();
                
                $check_stmt = $pdo->prepare("
                    SELECT pv.id, pv.patient_id, c.id AS consultation_id
                    FROM patient_visits pv
                    INNER JOIN appointments a ON pv.id = a.visit_id
                    LEFT JOIN consultations c ON c.visit_id = pv.id AND c.deleted_at IS NULL
                    WHERE pv.id = :id AND a.doctor_id = :doctor_id AND pv.deleted_at IS NULL
                    ORDER BY c.created_at DESC LIMIT 1
                ");
                $check_stmt->execute([':id' => $visit_id, ':doctor_id' => $user_id]);
                $visit = $check_stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$visit) {
                    $pdo->rollBack();
                    jsonResponse(false, 'Visit not found or access denied');
                }
                
                if ($visit['consultation_id']) {
                    $update_cons = $pdo->prepare("
                        UPDATE consultations 
                        SET final_diagnosis = :diagnosis,
                            follow_up_required = :follow_up,
                            follow_up_date = :follow_up_date,
                            follow_up_instructions = :instructions,
                            status = 'finalized',
                            finalized_at = NOW(),
                            finalized_by_user_id = :user_id,
                            updated_at = NOW()
                        WHERE id = :id
                    ");
                    $update_cons->execute([
                        ':diagnosis' => $final_diagnosis,
                        ':follow_up' => $follow_up_required,
                        ':follow_up_date' => $follow_up_date,
                        ':instructions' => $follow_up_instructions,
                        ':user_id' => $user_id,
                        ':id' => $visit['consultation_id']
                    ]);
                }
                
                $adm_stmt = $pdo->prepare("SELECT id FROM admissions WHERE visit_id = :visit_id AND status = 'admitted' AND deleted_at IS NULL ORDER BY created_at DESC LIMIT 1");
                $adm_stmt->execute([':visit_id' => $visit_id]);
                $admission = $adm_stmt->fetch(PDO::FETCH_ASSOC);
                $admission_id = $admission['id'] ?? null;
                
                if ($create_discharge_summary && $admission_id) {
                    $ds_sql = "
                        INSERT INTO discharge_summaries 
                        (admission_id, patient_id, visit_id, discharging_doctor_id, discharge_date, 
                         discharge_condition, final_diagnosis, special_instructions, created_by_user_id)
                        VALUES 
                        (:admission_id, :patient_id, :visit_id, :doctor_id, NOW(), 
                         :condition, :diagnosis, :instructions, :user_id)
                    ";
                    $ds_stmt = $pdo->prepare($ds_sql);
                    $ds_stmt->execute([
                        ':admission_id' => $admission_id,
                        ':patient_id' => $visit['patient_id'],
                        ':visit_id' => $visit_id,
                        ':doctor_id' => $user_id,
                        ':condition' => $discharge_condition,
                        ':diagnosis' => $final_diagnosis,
                        ':instructions' => $discharge_notes,
                        ':user_id' => $user_id
                    ]);
                    
                    $update_adm = $pdo->prepare("
                        UPDATE admissions 
                        SET discharged_at = NOW(), 
                            status = 'discharged',
                            discharge_diagnosis = :diagnosis,
                            discharge_condition = :condition,
                            discharge_summary = :summary,
                            discharge_instructions = :instructions,
                            follow_up_date = :follow_up_date,
                            updated_at = NOW()
                        WHERE id = :id
                    ");
                    $update_adm->execute([
                        ':diagnosis' => $final_diagnosis,
                        ':condition' => $discharge_condition,
                        ':summary' => $discharge_notes,
                        ':instructions' => $discharge_notes,
                        ':follow_up_date' => $follow_up_date,
                        ':id' => $admission_id
                    ]);
                }
                
                $update_sql = "UPDATE patient_visits 
                               SET status = 'completed', 
                                   consultation_completed_at = NOW(), 
                                   completed_at = NOW(),
                                   updated_at = NOW() 
                               WHERE id = :id";
                $stmt = $pdo->prepare($update_sql);
                $stmt->execute([':id' => $visit_id]);
                
                $appt_stmt = $pdo->prepare("UPDATE appointments SET status = 'completed', updated_at = NOW() WHERE visit_id = :id");
                $appt_stmt->execute([':id' => $visit_id]);
                
                $pdo->commit();
                jsonResponse(true, 'Consultation completed successfully!');
                
            } catch (PDOException $e) {
                $pdo->rollBack();
                jsonResponse(false, 'Database error: ' . $e->getMessage());
            }
        }

        // ---------- ADMIT PATIENT ----------
        if ($action === 'admit_patient') {
            // [Existing code - kept intact]
            $visit_id = (int)($_POST['visit_id'] ?? 0);
            $patient_id = (int)($_POST['patient_id'] ?? 0);
            $admission_type = $_POST['admission_type'] ?? 'elective';
            $primary_diagnosis = trim($_POST['primary_diagnosis'] ?? '');
            $admitting_diagnosis = trim($_POST['admitting_diagnosis'] ?? '');
            $bed_id = (int)($_POST['bed_id'] ?? 0);
            $expected_discharge_at = $_POST['expected_discharge_at'] ?? null;
            $admission_notes = trim($_POST['admission_notes'] ?? '');
            $condition_status = $_POST['condition_status'] ?? 'stable';
            
            if ($visit_id <= 0 || $patient_id <= 0) {
                jsonResponse(false, 'Missing visit or patient ID');
            }
            if (empty($primary_diagnosis)) {
                jsonResponse(false, 'Primary diagnosis is required for admission');
            }

            try {
                $pdo->beginTransaction();
                
                $admission_number = 'ADM-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
                
                $insert_sql = "
                    INSERT INTO admissions 
                    (branch_id, patient_id, doctor_id, visit_id, bed_id, admission_number, admission_type,
                     admitted_at, expected_discharge_at, primary_diagnosis, admitting_diagnosis,
                     condition_status, status, admission_notes, created_by_user_id)
                    VALUES 
                    (:branch_id, :patient_id, :doctor_id, :visit_id, :bed_id, :admission_number, :admission_type,
                     NOW(), :expected_discharge_at, :primary_diagnosis, :admitting_diagnosis,
                     :condition_status, 'admitted', :admission_notes, :user_id)
                ";
                $stmt = $pdo->prepare($insert_sql);
                $stmt->execute([
                    ':branch_id' => $branch_id,
                    ':patient_id' => $patient_id,
                    ':doctor_id' => $user_id,
                    ':visit_id' => $visit_id,
                    ':bed_id' => $bed_id ?: null,
                    ':admission_number' => $admission_number,
                    ':admission_type' => $admission_type,
                    ':expected_discharge_at' => $expected_discharge_at,
                    ':primary_diagnosis' => $primary_diagnosis,
                    ':admitting_diagnosis' => $admitting_diagnosis,
                    ':condition_status' => $condition_status,
                    ':admission_notes' => $admission_notes,
                    ':user_id' => $user_id
                ]);
                $admission_id = $pdo->lastInsertId();
                
                if ($bed_id > 0) {
                    $bed_stmt = $pdo->prepare("SELECT bed_number, ward_id FROM beds WHERE id = :id AND deleted_at IS NULL");
                    $bed_stmt->execute([':id' => $bed_id]);
                    $bed = $bed_stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($bed) {
                        $update_bed = $pdo->prepare("UPDATE beds SET status = 'occupied' WHERE id = :id");
                        $update_bed->execute([':id' => $bed_id]);
                        
                        $alloc_sql = "
                            INSERT INTO bed_allocations 
                            (patient_id, ward_id, visit_id, bed_number, check_in_date, status, assigned_by_user_id)
                            VALUES 
                            (:patient_id, :ward_id, :visit_id, :bed_number, NOW(), 'occupied', :user_id)
                        ";
                        $alloc_stmt = $pdo->prepare($alloc_sql);
                        $alloc_stmt->execute([
                            ':patient_id' => $patient_id,
                            ':ward_id' => $bed['ward_id'],
                            ':visit_id' => $visit_id,
                            ':bed_number' => $bed['bed_number'],
                            ':user_id' => $user_id
                        ]);
                    }
                }
                
                $pdo->commit();
                jsonResponse(true, 'Patient admitted successfully', ['admission_id' => $admission_id]);
                
            } catch (PDOException $e) {
                $pdo->rollBack();
                jsonResponse(false, 'Database error: ' . $e->getMessage());
            }
        }

        // ---------- SEARCH DRUGS ----------
        if ($action === 'search_drugs') {
            // [Existing code - kept intact]
            $term = trim($_POST['term'] ?? '');
            if (strlen($term) < 2) jsonResponse(true, '', array('drugs' => array()));
            
            $sql = "SELECT id, 
                        CONCAT(generic_name, 
                                CASE WHEN brand_name IS NOT NULL AND brand_name != '' 
                                    THEN CONCAT(' (', brand_name, ')') 
                                    ELSE '' END) AS text,
                        unit_of_measure,
                        unit_price,
                        dosage_form,
                        strength,
                        is_controlled_substance,
                        requires_prescription
                    FROM medicines_master 
                    WHERE (generic_name LIKE :term OR brand_name LIKE :term) 
                    AND is_active = 1
                    AND deleted_at IS NULL 
                    LIMIT 20";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(array(':term' => "%$term%"));
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            jsonResponse(true, '', array('drugs' => $results));
        }

        // ---------- SEARCH LAB TESTS (with Panel Support) ----------
        if ($action === 'search_labs') {
            $term = trim($_POST['term'] ?? '');
            $include_panels = isset($_POST['include_panels']) ? (int)$_POST['include_panels'] : 0;
            
            if (strlen($term) < 2) jsonResponse(true, '', ['tests' => [], 'panels' => []]);
            
            $results = ['tests' => [], 'panels' => []];
            
            // Search individual tests
            $sql = "SELECT id, test_name AS text, price, test_code, sample_type, turnaround_time_hours, test_category,
                           'test' AS type
                    FROM lab_tests_master 
                    WHERE test_name LIKE :term 
                      AND is_active = 1
                      AND deleted_at IS NULL 
                    LIMIT 20";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':term' => "%$term%"]);
            $results['tests'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Search panels if requested
            if ($include_panels) {
                $panel_sql = "SELECT id, panel_name AS text, description,
                                     'panel' AS type
                              FROM lab_panels
                              WHERE panel_name LIKE :term 
                                AND is_active = 1
                                AND deleted_at IS NULL
                              LIMIT 10";
                $panel_stmt = $pdo->prepare($panel_sql);
                $panel_stmt->execute([':term' => "%$term%"]);
                $results['panels'] = $panel_stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // For each panel, get the test count
                foreach ($results['panels'] as &$panel) {
                    $count_sql = "SELECT COUNT(*) as test_count FROM lab_panel_tests WHERE panel_id = :panel_id AND deleted_at IS NULL";
                    $count_stmt = $pdo->prepare($count_sql);
                    $count_stmt->execute([':panel_id' => $panel['id']]);
                    $panel['test_count'] = (int)$count_stmt->fetchColumn();
                }
            }
            
            jsonResponse(true, '', $results);
        }

        // ---------- FETCH LAB PANELS ----------
        if ($action === 'fetch_lab_panels') {
            $sql = "SELECT p.id, p.panel_name, p.description,
                           (SELECT COUNT(*) FROM lab_panel_tests WHERE panel_id = p.id AND deleted_at IS NULL) AS test_count
                    FROM lab_panels p
                    WHERE p.is_active = 1 AND p.deleted_at IS NULL
                    ORDER BY p.panel_name";
            $stmt = $pdo->prepare($sql);
            $stmt->execute();
            $panels = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get tests for each panel
            foreach ($panels as &$panel) {
                $test_sql = "SELECT t.id, t.test_name, t.price, t.sample_type
                             FROM lab_panel_tests pt
                             INNER JOIN lab_tests_master t ON pt.test_id = t.id
                             WHERE pt.panel_id = :panel_id AND t.is_active = 1 AND t.deleted_at IS NULL
                             ORDER BY t.test_name";
                $test_stmt = $pdo->prepare($test_sql);
                $test_stmt->execute([':panel_id' => $panel['id']]);
                $panel['tests'] = $test_stmt->fetchAll(PDO::FETCH_ASSOC);
            }
            
            jsonResponse(true, '', ['panels' => $panels]);
        }

        // ---------- SAVE PRESCRIPTION ----------
        if ($action === 'save_prescription') {
            // [Existing code - kept intact]
            $visit_id = (int)($_POST['visit_id'] ?? 0);
            $patient_id = (int)($_POST['patient_id'] ?? 0);
            $clinical_notes = trim($_POST['clinical_notes'] ?? '');
            $items = json_decode($_POST['items'] ?? '[]', true);
            
            if ($visit_id <= 0 || $patient_id <= 0) {
                jsonResponse(false, 'Missing visit or patient ID');
            }
            if (empty($items)) {
                jsonResponse(false, 'At least one medication is required');
            }

            try {
                $pdo->beginTransaction();
                
                // Drug-Allergy Contraindication Validation
                $allergy_sql = "SELECT allergies FROM triage_records WHERE patient_id = :patient_id AND deleted_at IS NULL ORDER BY triage_time DESC LIMIT 1";
                $allergy_stmt = $pdo->prepare($allergy_sql);
                $allergy_stmt->execute([':patient_id' => $patient_id]);
                $allergy_row = $allergy_stmt->fetch(PDO::FETCH_ASSOC);
                $patient_allergies = $allergy_row['allergies'] ?? '';
                
                if (!empty($patient_allergies)) {
                    $allergy_terms = explode(',', strtolower($patient_allergies));
                    $allergy_terms = array_map('trim', $allergy_terms);
                    
                    foreach ($items as $item) {
                        $medicine_id = (int)($item['medicine_id'] ?? 0);
                        if ($medicine_id <= 0) continue;
                        
                        $med_stmt = $pdo->prepare("SELECT generic_name, brand_name, is_controlled_substance FROM medicines_master WHERE id = :id AND deleted_at IS NULL");
                        $med_stmt->execute([':id' => $medicine_id]);
                        $med = $med_stmt->fetch(PDO::FETCH_ASSOC);
                        if (!$med) continue;
                        
                        $drug_names = strtolower($med['generic_name'] . ' ' . $med['brand_name']);
                        foreach ($allergy_terms as $allergy) {
                            if (strpos($drug_names, $allergy) !== false) {
                                $pdo->rollBack();
                                jsonResponse(false, 'CRITICAL WARNING: Patient has a documented allergy to ' . $allergy . ' (medication: ' . $med['generic_name'] . '). Prescription aborted.', [
                                    'contraindication_error' => true,
                                    'allergy_details' => 'Allergy to: ' . $allergy,
                                    'drug_name' => $med['generic_name']
                                ], 409);
                            }
                        }
                    }
                }

                // Inventory Validation Gatekeeper
                $inventory_errors = [];
                foreach ($items as $item) {
                    $medicine_id = (int)($item['medicine_id'] ?? 0);
                    $requested_qty = (int)($item['duration'] ?? 7);
                    if ($medicine_id <= 0) continue;
                    
                    $frequency = trim($item['frequency'] ?? '');
                    $duration_days = (int)($item['duration'] ?? 7);
                    $total_needed = calculateQuantity($frequency, $duration_days);
                    if ($total_needed <= 0) $total_needed = $duration_days;
                    
                    $inv_sql = "SELECT SUM(quantity_in_hand) as total_stock FROM pharmacy_inventory WHERE medicine_id = :medicine_id AND branch_id = :branch_id AND expiry_date > CURDATE() AND deleted_at IS NULL";
                    $inv_stmt = $pdo->prepare($inv_sql);
                    $inv_stmt->execute([':medicine_id' => $medicine_id, ':branch_id' => $branch_id]);
                    $inv_row = $inv_stmt->fetch(PDO::FETCH_ASSOC);
                    $available_stock = (int)($inv_row['total_stock'] ?? 0);
                    
                    if ($available_stock < $total_needed) {
                        $med_name_stmt = $pdo->prepare("SELECT generic_name FROM medicines_master WHERE id = :id");
                        $med_name_stmt->execute([':id' => $medicine_id]);
                        $med_name = $med_name_stmt->fetchColumn() ?? 'Unknown';
                        $inventory_errors[] = [
                            'medicine_id' => $medicine_id,
                            'medicine_name' => $med_name,
                            'requested' => $total_needed,
                            'available' => $available_stock
                        ];
                    }
                }
                
                if (!empty($inventory_errors)) {
                    $pdo->rollBack();
                    jsonResponse(false, 'Inventory shortage detected. Please adjust quantities.', [
                        'inventory_errors' => $inventory_errors,
                        'status' => 'inventory_insufficient'
                    ], 409);
                }

                // Proceed with prescription creation
                $prescription_number = 'RX-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
                
                $insert_rx_sql = "
                    INSERT INTO prescriptions 
                    (branch_id, patient_id, doctor_id, visit_id, prescription_number, prescription_date, 
                     clinical_notes, payment_status, status, created_by_user_id, created_at, updated_at)
                    VALUES 
                    (:branch_id, :patient_id, :doctor_id, :visit_id, :prescription_number, CURDATE(), 
                     :clinical_notes, 'unpaid', 'pending_payment', :user_id, NOW(), NOW())
                ";
                $stmt = $pdo->prepare($insert_rx_sql);
                $stmt->execute([
                    ':branch_id' => $branch_id,
                    ':patient_id' => $patient_id,
                    ':doctor_id' => $user_id,
                    ':visit_id' => $visit_id,
                    ':prescription_number' => $prescription_number,
                    ':clinical_notes' => $clinical_notes,
                    ':user_id' => $user_id
                ]);
                $prescription_id = $pdo->lastInsertId();
                
                $insert_item_sql = "
                    INSERT INTO prescription_items 
                    (prescription_id, medicine_id, medicine_name, dosage, frequency, duration_days, 
                     quantity, unit_of_measure, instructions, is_controlled_substance, meal_relation, created_at)
                    VALUES 
                    (:prescription_id, :medicine_id, :medicine_name, :dosage, :frequency, :duration_days, 
                     :quantity, :unit_of_measure, :instructions, :is_controlled, :meal_relation, NOW())
                ";
                $item_stmt = $pdo->prepare($insert_item_sql);
                
                $has_controlled = false;
                $medication_list = [];
                foreach ($items as $item) {
                    $medicine_id = (int)($item['medicine_id'] ?? 0);
                    if ($medicine_id <= 0) continue;
                    
                    $medStmt = $pdo->prepare("SELECT generic_name, brand_name, unit_of_measure, is_controlled_substance FROM medicines_master WHERE id = :id AND deleted_at IS NULL");
                    $medStmt->execute([':id' => $medicine_id]);
                    $med = $medStmt->fetch(PDO::FETCH_ASSOC);
                    if (!$med) continue;
                    
                    $medicine_name = $med['generic_name'] . ($med['brand_name'] ? ' (' . $med['brand_name'] . ')' : '');
                    $unit_of_measure = $med['unit_of_measure'] ?? 'tablet';
                    $dosage = trim($item['dosage'] ?? '');
                    $frequency = trim($item['frequency'] ?? '');
                    $duration_days = (int)($item['duration'] ?? 7);
                    $instructions = trim($item['instructions'] ?? '');
                    $meal_relation = $item['meal_relation'] ?? 'any';
                    $is_controlled = (int)($med['is_controlled_substance'] ?? 0);
                    
                    if ($is_controlled) $has_controlled = true;
                    
                    $quantity = calculateQuantity($frequency, $duration_days);
                    if ($quantity <= 0) $quantity = $duration_days;
                    
                    $item_stmt->execute([
                        ':prescription_id' => $prescription_id,
                        ':medicine_id' => $medicine_id,
                        ':medicine_name' => $medicine_name,
                        ':dosage' => $dosage,
                        ':frequency' => $frequency,
                        ':duration_days' => $duration_days,
                        ':quantity' => $quantity,
                        ':unit_of_measure' => $unit_of_measure,
                        ':instructions' => $instructions,
                        ':is_controlled' => $is_controlled,
                        ':meal_relation' => $meal_relation
                    ]);
                    
                    $medication_list[] = $medicine_name;
                }
                
                $pharmStmt = $pdo->prepare("SELECT id FROM users WHERE user_type = 'pharmacist' AND branch_id = :branch_id AND is_active = 1");
                $pharmStmt->execute([':branch_id' => $branch_id]);
                while ($pharmacist = $pharmStmt->fetch(PDO::FETCH_ASSOC)) {
                    generateNotification(
                        $pdo,
                        $pharmacist['id'],
                        $has_controlled ? 'Controlled Substance Prescription' : 'New Prescription',
                        'Prescription #' . $prescription_number . ' for ' . implode(', ', array_slice($medication_list, 0, 3)) . 
                        ($has_controlled ? ' - CONTAINS CONTROLLED SUBSTANCES' : ''),
                        $has_controlled ? 'narcotic_prescribed' : 'prescription_alert',
                        $branch_id
                    );
                }
                
                $check_visit = $pdo->prepare("SELECT status FROM patient_visits WHERE id = :id");
                $check_visit->execute([':id' => $visit_id]);
                $vis_status = $check_visit->fetchColumn();
                if ($vis_status != 'awaiting_pharmacy' && $vis_status != 'completed') {
                    $update_visit = $pdo->prepare("UPDATE patient_visits SET status = 'awaiting_pharmacy', updated_at = NOW() WHERE id = :id");
                    $update_visit->execute([':id' => $visit_id]);
                }
                
                $pdo->commit();
                jsonResponse(true, 'Prescription saved successfully. Sent to pharmacy for processing.', ['prescription_id' => $prescription_id]);
            } catch (PDOException $e) {
                $pdo->rollBack();
                jsonResponse(false, 'Database error: ' . $e->getMessage());
            }
        }

        // ---------- SAVE LAB ORDER (with Panel Support) ----------
        if ($action === 'save_lab_order') {
            $visit_id = (int)($_POST['visit_id'] ?? 0);
            $patient_id = (int)($_POST['patient_id'] ?? 0);
            $clinical_indication = trim($_POST['clinical_indication'] ?? '');
            $priority = strtolower(trim($_POST['priority'] ?? 'routine'));
            $tests = json_decode($_POST['tests'] ?? '[]', true);
            $panels = json_decode($_POST['panels'] ?? '[]', true);
            
            if ($visit_id <= 0 || $patient_id <= 0) {
                jsonResponse(false, 'Missing visit or patient ID');
            }
            
            // Collect all test IDs from both individual tests and panels
            $test_ids = [];
            
            // Process individual tests
            if (!empty($tests) && is_array($tests)) {
                foreach ($tests as $test) {
                    $test_id = (int)($test['test_id'] ?? 0);
                    if ($test_id > 0) {
                        $test_ids[] = $test_id;
                    }
                }
            }
            
            // Process panels - resolve to individual test IDs
            if (!empty($panels) && is_array($panels)) {
                foreach ($panels as $panel) {
                    $panel_id = (int)($panel['panel_id'] ?? 0);
                    if ($panel_id > 0) {
                        // Get all tests for this panel
                        $panel_test_sql = "SELECT test_id FROM lab_panel_tests WHERE panel_id = :panel_id AND deleted_at IS NULL";
                        $panel_test_stmt = $pdo->prepare($panel_test_sql);
                        $panel_test_stmt->execute([':panel_id' => $panel_id]);
                        $panel_tests = $panel_test_stmt->fetchAll(PDO::FETCH_COLUMN);
                        $test_ids = array_merge($test_ids, $panel_tests);
                    }
                }
            }
            
            // Remove duplicates
            $test_ids = array_values(array_unique($test_ids));
            
            if (empty($test_ids)) {
                jsonResponse(false, 'No valid tests or panels selected');
            }

            try {
                $pdo->beginTransaction();

                $placeholders = implode(',', array_fill(0, count($test_ids), '?'));
                $fetch_tests_sql = "
                    SELECT id, test_name, test_category, sample_type,
                           reference_range_low, reference_range_high, unit
                    FROM lab_tests_master
                    WHERE id IN ({$placeholders})
                      AND is_active = 1
                      AND deleted_at IS NULL
                ";
                $fetch_stmt = $pdo->prepare($fetch_tests_sql);
                $fetch_stmt->execute($test_ids);
                $test_rows = $fetch_stmt->fetchAll(PDO::FETCH_ASSOC);

                if (count($test_rows) !== count($test_ids)) {
                    $found_ids = array_map('intval', array_column($test_rows, 'id'));
                    $missing_ids = array_diff($test_ids, $found_ids);
                    throw new RuntimeException('Invalid or inactive test(s): ' . implode(', ', $missing_ids));
                }

                $grouped_by_specimen = [];
                foreach ($test_rows as $test_row) {
                    $specimen_type = normalizeSpecimenType($test_row['sample_type'] ?? 'other');
                    if (!isset($grouped_by_specimen[$specimen_type])) {
                        $grouped_by_specimen[$specimen_type] = [];
                    }
                    $grouped_by_specimen[$specimen_type][] = $test_row;
                }

                $batch_number = 'BATCH-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
                $insert_order_sql = "
                    INSERT INTO lab_orders
                    (branch_id, patient_id, doctor_id, visit_id, batch_number, order_number, order_date, priority,
                     clinical_indication, payment_status, status, specimen_type, created_by_user_id, created_at, updated_at)
                    VALUES
                    (:branch_id, :patient_id, :doctor_id, :visit_id, :batch_number, :order_number, CURDATE(), :priority,
                     :clinical_indication, 'unpaid', 'pending_payment', :specimen_type, :user_id, NOW(), NOW())
                ";
                $order_stmt = $pdo->prepare($insert_order_sql);

                $insert_result_sql = "
                    INSERT INTO lab_test_results
                    (lab_order_id, test_name, test_category, technician_notes, created_at)
                    VALUES
                    (:lab_order_id, :test_name, :test_category, :clinical_indication, NOW())
                ";
                $result_stmt = $pdo->prepare($insert_result_sql);

                $insert_item_sql = "
                    INSERT INTO lab_order_items
                    (lab_order_id, test_name, sample_type, status, reference_range, created_at)
                    VALUES
                    (:lab_order_id, :test_name, :sample_type, 'ordered', :reference_range, NOW())
                ";
                $item_stmt = $pdo->prepare($insert_item_sql);

                $created_orders = [];
                $all_test_names = [];

                foreach ($grouped_by_specimen as $specimen_type => $specimen_tests) {
                    $order_number = generateLabOrderNumber();

                    $order_stmt->execute([
                        ':branch_id' => $branch_id,
                        ':patient_id' => $patient_id,
                        ':doctor_id' => $user_id,
                        ':visit_id' => $visit_id,
                        ':batch_number' => $batch_number,
                        ':order_number' => $order_number,
                        ':priority' => $priority,
                        ':clinical_indication' => $clinical_indication,
                        ':specimen_type' => $specimen_type,
                        ':user_id' => $user_id
                    ]);
                    $lab_order_id = (int)$pdo->lastInsertId();

                    foreach ($specimen_tests as $test_row) {
                        $test_name = $test_row['test_name'] ?? 'Unknown Test';
                        $test_category = $test_row['test_category'] ?? 'other';
                        $all_test_names[] = $test_name;

                        $result_stmt->execute([
                            ':lab_order_id' => $lab_order_id,
                            ':test_name' => $test_name,
                            ':test_category' => $test_category,
                            ':clinical_indication' => $clinical_indication
                        ]);

                        $reference_range = null;
                        if ($test_row['reference_range_low'] !== null && $test_row['reference_range_high'] !== null) {
                            $reference_range = $test_row['reference_range_low'] . ' - ' . $test_row['reference_range_high'];
                            if (!empty($test_row['unit'])) {
                                $reference_range .= ' ' . $test_row['unit'];
                            }
                        }

                        $item_stmt->execute([
                            ':lab_order_id' => $lab_order_id,
                            ':test_name' => $test_name,
                            ':sample_type' => $specimen_type,
                            ':reference_range' => $reference_range
                        ]);
                    }

                    $created_orders[] = [
                        'lab_order_id' => $lab_order_id,
                        'order_number' => $order_number,
                        'specimen_type' => $specimen_type,
                        'test_count' => count($specimen_tests)
                    ];
                }

                $check_visit = $pdo->prepare("SELECT status FROM patient_visits WHERE id = :id");
                $check_visit->execute([':id' => $visit_id]);
                $vis_status = $check_visit->fetchColumn();
                if ($vis_status != 'awaiting_lab' && $vis_status != 'completed') {
                    $update_visit = $pdo->prepare("UPDATE patient_visits SET status = 'awaiting_lab', updated_at = NOW() WHERE id = :id");
                    $update_visit->execute([':id' => $visit_id]);
                }

                $order_summary = implode(', ', array_map(function ($order) {
                    return $order['order_number'] . ' (' . ucfirst(str_replace('_', ' ', $order['specimen_type'])) . ')';
                }, $created_orders));

                $labStaff = $pdo->prepare("SELECT id FROM users WHERE user_type = 'laboratorist' AND branch_id = :branch_id AND is_active = 1");
                $labStaff->execute([':branch_id' => $branch_id]);
                while ($labUser = $labStaff->fetch(PDO::FETCH_ASSOC)) {
                    generateNotification(
                        $pdo,
                        $labUser['id'],
                        'New Lab Order' . (count($created_orders) > 1 ? 's' : ''),
                        count($created_orders) . ' lab order(s) created (' . $order_summary . '). Tests: ' . implode(', ', array_slice(array_unique($all_test_names), 0, 5)),
                        'lab_result',
                        $branch_id
                    );
                }

                $pdo->commit();

                $order_count = count($created_orders);
                $message = $order_count > 1
                    ? "{$order_count} lab orders created (split by specimen type) and sent to the laboratory."
                    : 'Lab order saved successfully. Sent to laboratory for processing.';

                jsonResponse(true, $message, [
                    'lab_order_id' => $created_orders[0]['lab_order_id'],
                    'lab_order_ids' => array_column($created_orders, 'lab_order_id'),
                    'orders' => $created_orders,
                    'batch_number' => $batch_number
                ]);
            } catch (RuntimeException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                jsonResponse(false, $e->getMessage());
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                jsonResponse(false, 'Database error: ' . $e->getMessage());
            }
        }

        // ---------- SAVE RADIOLOGY ORDER (with Bulk Support) ----------
        if ($action === 'save_radiology_order') {
            $visit_id = (int)($_POST['visit_id'] ?? 0);
            $patient_id = (int)($_POST['patient_id'] ?? 0);
            $service_ids = json_decode($_POST['service_ids'] ?? '[]', true);
            $modality = trim($_POST['modality'] ?? '');
            $body_part = trim($_POST['body_part'] ?? '');
            $clinical_history = trim($_POST['clinical_history'] ?? '');
            $priority = strtolower(trim($_POST['priority'] ?? 'routine'));
            
            if ($visit_id <= 0 || $patient_id <= 0) {
                jsonResponse(false, 'Missing visit or patient ID');
            }
            if (empty($service_ids) || !is_array($service_ids)) {
                jsonResponse(false, 'Please select at least one radiology service');
            }

            try {
                $pdo->beginTransaction();
                
                $created_orders = [];
                
                foreach ($service_ids as $service_id) {
                    $service_id = (int)$service_id;
                    if ($service_id <= 0) continue;
                    
                    $service_stmt = $pdo->prepare("SELECT service_name, service_code, standard_price FROM radiology_services WHERE id = :id AND is_active = 1 AND deleted_at IS NULL");
                    $service_stmt->execute([':id' => $service_id]);
                    $service = $service_stmt->fetch(PDO::FETCH_ASSOC);
                    if (!$service) continue;
                    
                    $order_number = 'RAD-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
                    
                    $insert_sql = "
                        INSERT INTO radiology_orders 
                        (branch_id, patient_id, doctor_id, visit_id, order_number, service_name, service_code, 
                         modality, body_part, priority, clinical_history, payment_status, status, 
                         created_by_user_id, created_at, updated_at)
                        VALUES 
                        (:branch_id, :patient_id, :doctor_id, :visit_id, :order_number, :service_name, :service_code,
                         :modality, :body_part, :priority, :clinical_history, 'unpaid', 'pending_payment',
                         :user_id, NOW(), NOW())
                    ";
                    $stmt = $pdo->prepare($insert_sql);
                    $stmt->execute([
                        ':branch_id' => $branch_id,
                        ':patient_id' => $patient_id,
                        ':doctor_id' => $user_id,
                        ':visit_id' => $visit_id,
                        ':order_number' => $order_number,
                        ':service_name' => $service['service_name'],
                        ':service_code' => $service['service_code'],
                        ':modality' => $modality,
                        ':body_part' => $body_part,
                        ':priority' => $priority,
                        ':clinical_history' => $clinical_history,
                        ':user_id' => $user_id
                    ]);
                    
                    $created_orders[] = [
                        'order_number' => $order_number,
                        'service_name' => $service['service_name']
                    ];
                }
                
                $pdo->commit();
                jsonResponse(true, count($created_orders) . ' radiology order(s) saved successfully', ['orders' => $created_orders]);
                
            } catch (PDOException $e) {
                $pdo->rollBack();
                jsonResponse(false, 'Database error: ' . $e->getMessage());
            }
        }

        // ---------- SAVE PROCEDURE ORDER ----------
        if ($action === 'save_procedure_order') {
            // [Existing code - kept intact]
            $visit_id = (int)($_POST['visit_id'] ?? 0);
            $patient_id = (int)($_POST['patient_id'] ?? 0);
            $procedure_name = trim($_POST['procedure_name'] ?? '');
            $priority = trim($_POST['priority'] ?? 'routine');
            $scheduled_date = $_POST['scheduled_date'] ?? null;
            $clinical_notes = trim($_POST['clinical_notes'] ?? '');
            
            if ($visit_id <= 0 || $patient_id <= 0) {
                jsonResponse(false, 'Missing visit or patient ID');
            }
            if (empty($procedure_name)) {
                jsonResponse(false, 'Procedure name is required');
            }

            try {
                $pdo->beginTransaction();
                
                $procedure_number = 'PROC-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
                
                $tariff_stmt = $pdo->prepare("SELECT id FROM service_tariffs WHERE service_name LIKE :name AND service_category = 'procedure' AND is_active = 1 LIMIT 1");
                $tariff_stmt->execute([':name' => '%' . $procedure_name . '%']);
                $tariff = $tariff_stmt->fetch(PDO::FETCH_ASSOC);
                $tariff_id = $tariff['id'] ?? null;
                
                $insert_sql = "
                    INSERT INTO procedure_orders 
                    (branch_id, patient_id, doctor_id, visit_id, procedure_number, procedure_name, 
                     priority, scheduled_date, clinical_notes, service_tariff_id,
                     payment_status, execution_status,
                     created_by_user_id, created_at, updated_at)
                    VALUES 
                    (:branch_id, :patient_id, :doctor_id, :visit_id, :procedure_number, :procedure_name,
                     :priority, :scheduled_date, :clinical_notes, :tariff_id,
                     'unpaid', 'pending_payment',
                     :user_id, NOW(), NOW())
                ";
                $stmt = $pdo->prepare($insert_sql);
                $stmt->execute([
                    ':branch_id' => $branch_id,
                    ':patient_id' => $patient_id,
                    ':doctor_id' => $user_id,
                    ':visit_id' => $visit_id,
                    ':procedure_number' => $procedure_number,
                    ':procedure_name' => $procedure_name,
                    ':priority' => $priority,
                    ':scheduled_date' => $scheduled_date,
                    ':clinical_notes' => $clinical_notes,
                    ':tariff_id' => $tariff_id,
                    ':user_id' => $user_id
                ]);
                
                $pdo->commit();
                jsonResponse(true, 'Procedure order saved successfully');
            } catch (PDOException $e) {
                $pdo->rollBack();
                jsonResponse(false, 'Database error: ' . $e->getMessage());
            }
        }

        // ---------- FETCH RADIOLOGY STATUS (with DICOM Link Support) ----------
        if ($action === 'fetch_radiology_status') {
            $visit_id = (int)($_POST['visit_id'] ?? 0);
            if ($visit_id <= 0) jsonResponse(false, 'Invalid visit ID');
            
            // Base PACS URL for DICOM viewer (configure as needed)
            $pacs_base_url = $_ENV['PACS_BASE_URL'] ?? 'https://pacs-viewer.example.com/study';
            
            $sql = "
                SELECT 
                    ro.id,
                    ro.order_number,
                    ro.service_name,
                    ro.modality,
                    ro.body_part,
                    ro.priority,
                    ro.status,
                    ro.payment_status,
                    ro.scheduled_date,
                    ro.scheduled_time,
                    ro.performed_at,
                    ro.findings,
                    ro.impression,
                    ro.interpretation_report,
                    ro.image_attachment_path,
                    CONCAT(u.first_name, ' ', u.last_name) AS performed_by,
                    CASE 
                        WHEN ro.status = 'pending_payment' THEN 'Pending Payment'
                        WHEN ro.status = 'scheduled' THEN 'Scheduled'
                        WHEN ro.status = 'in_progress' THEN 'In Progress'
                        WHEN ro.status = 'completed' THEN 'Completed'
                        WHEN ro.status = 'reviewed' THEN 'Reviewed'
                        ELSE ro.status
                    END AS display_status
                FROM radiology_orders ro
                LEFT JOIN users u ON ro.performed_by_user_id = u.id
                WHERE ro.visit_id = :visit_id
                AND ro.deleted_at IS NULL
                ORDER BY ro.created_at DESC
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(array(':visit_id' => $visit_id));
            $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Add DICOM/Image link for each order if image path exists
            foreach ($orders as &$order) {
                $order['has_image'] = !empty($order['image_attachment_path']);
                $order['image_link'] = null;
                $order['image_type'] = null;
                
                if ($order['has_image']) {
                    $path = $order['image_attachment_path'];
                    // Determine if it's a DICOM UID or file path
                    if (strpos($path, 'dicom://') === 0) {
                        // DICOM UID format
                        $order['image_type'] = 'dicom';
                        $study_uid = substr($path, 9);
                        $order['image_link'] = $pacs_base_url . '?studyUID=' . urlencode($study_uid);
                    } elseif (strpos($path, 'http') === 0) {
                        // Full URL
                        $order['image_type'] = 'url';
                        $order['image_link'] = $path;
                    } else {
                        // Local file path - assume web-accessible
                        $order['image_type'] = 'local';
                        $order['image_link'] = '/uploads/radiology/' . ltrim($path, '/');
                    }
                }
            }
            
            jsonResponse(true, '', array('radiology_orders' => $orders));
        }

        // ---------- FETCH PROCEDURE STATUS ----------
        if ($action === 'fetch_procedure_status') {
            // [Existing code - kept intact]
            $visit_id = (int)($_POST['visit_id'] ?? 0);
            if ($visit_id <= 0) jsonResponse(false, 'Invalid visit ID');
            
            $sql = "
                SELECT 
                    po.id,
                    po.procedure_number,
                    po.procedure_name,
                    po.priority,
                    po.scheduled_date,
                    po.execution_status,
                    po.payment_status,
                    po.performed_at,
                    po.completed_at,
                    po.procedure_findings,
                    po.complications,
                    po.post_procedure_instructions,
                    CONCAT(u.first_name, ' ', u.last_name) AS performed_by,
                    CASE 
                        WHEN po.execution_status = 'pending_payment' THEN 'Pending Payment'
                        WHEN po.execution_status = 'scheduled' THEN 'Scheduled'
                        WHEN po.execution_status = 'in_progress' THEN 'In Progress'
                        WHEN po.execution_status = 'completed' THEN 'Completed'
                        ELSE po.execution_status
                    END AS display_status
                FROM procedure_orders po
                LEFT JOIN users u ON po.performed_by_user_id = u.id
                WHERE po.visit_id = :visit_id
                  AND po.deleted_at IS NULL
                ORDER BY po.created_at DESC
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':visit_id' => $visit_id]);
            $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            jsonResponse(true, '', ['procedure_orders' => $orders]);
        }

        // ---------- FETCH LAB STATUS ----------
        if ($action === 'fetch_lab_status') {
            // [Existing code - kept intact]
            $visit_id = (int)($_POST['visit_id'] ?? 0);
            if ($visit_id <= 0) jsonResponse(false, 'Invalid visit ID');
            
            $branch_condition = "";
            $params = array(':visit_id' => $visit_id);
            
            if (!$is_global_admin && $branch_id > 0) {
                $branch_condition = " AND lo.branch_id = :branch_id";
                $params[':branch_id'] = $branch_id;
            }
            
            $sql = "
                SELECT 
                    lo.id,
                    lo.order_number,
                    lo.order_date,
                    lo.priority,
                    lo.status,
                    lo.payment_status,
                    lo.clinical_indication,
                    lo.specimen_collected_at,
                    lo.specimen_type,
                    lo.results_ready_at,
                    lo.completed_at,
                    lo.critical_notified_at,
                    (SELECT GROUP_CONCAT(DISTINCT ltr.test_name SEPARATOR ', ') 
                    FROM lab_test_results ltr 
                    WHERE ltr.lab_order_id = lo.id) AS tests,
                    (SELECT COUNT(*) 
                    FROM lab_test_results ltr 
                    WHERE ltr.lab_order_id = lo.id AND ltr.doctor_reviewed_at IS NULL AND lo.status = 'completed') AS unread_results,
                    (SELECT COUNT(*) 
                    FROM lab_test_results ltr 
                    WHERE ltr.lab_order_id = lo.id AND ltr.is_critical = 1 AND ltr.doctor_reviewed_at IS NULL) AS critical_unread,
                    (SELECT COUNT(*) 
                    FROM specimen_tracking st 
                    WHERE st.order_id = lo.id AND st.deleted_at IS NULL) AS specimen_count
                FROM lab_orders lo
                WHERE lo.visit_id = :visit_id
                AND lo.deleted_at IS NULL
                $branch_condition
                ORDER BY lo.created_at DESC
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $labs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $has_ready_results = false;
            foreach ($labs as $lab) {
                if ($lab['unread_results'] > 0) {
                    $has_ready_results = true;
                    break;
                }
            }
            
            jsonResponse(true, '', array('labs' => $labs, 'has_ready_results' => $has_ready_results));
        }

        // ---------- FETCH LAB RESULTS (with Amended Flag Support) ----------
        if ($action === 'fetch_lab_results') {
            $visit_id = isset($_POST['visit_id']) ? (int)$_POST['visit_id'] : 0;
            $patient_id = isset($_POST['patient_id']) ? (int)$_POST['patient_id'] : 0;
            
            if ($visit_id <= 0 && $patient_id <= 0) {
                jsonResponse(false, 'Please provide a valid visit or patient ID');
                exit;
            }
            
            try {
                $where_conditions = array();
                $params = array();

                if ($visit_id > 0) {
                    $param_counter++;
                    $where_conditions[] = "lo.visit_id = :visit_id_{$param_counter}";  // ✅
                    $params[":visit_id_{$param_counter}"] = $visit_id;
                }

                if ($patient_id > 0 && $visit_id <= 0) {
                    $param_counter++;
                    $where_conditions[] = "lo.patient_id = :patient_id_{$param_counter}";  // ✅
                    $params[":patient_id_{$param_counter}"] = $patient_id;
                }

                if (!$is_global_admin && $branch_id > 0) {
                    $param_counter++;
                    $where_conditions[] = "lo.branch_id = :branch_id_{$param_counter}";  // ✅
                    $params[":branch_id_{$param_counter}"] = $branch_id;
                }
                
                $where_clause = "";
                if (!empty($where_conditions)) {
                    $where_clause = "WHERE " . implode(" AND ", $where_conditions);
                }
                
                    $sql = "
                            SELECT 
                                lo.id AS lab_order_id,
                                lo.order_number,
                                lo.order_date,
                                lo.priority,
                                lo.status AS order_status,
                                lo.payment_status,
                                lo.clinical_indication,
                                lo.specimen_type,
                                lo.specimen_collected_at,
                                lo.results_ready_at,
                                lo.completed_at,
                                lo.critical_notified_at,
                                
                                ltr.id AS result_id,
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
                                
                                ltr.verified_by_user_id,
                                ltr.verified_at,
                                ltr.doctor_reviewed_at,
                                ltr.doctor_reviewed_by,
                                ltr.created_at AS result_created_at,
                                ltr.updated_at AS result_updated_at,
                                
                                loi.is_amended,
                                loi.amended_at,
                                loi.amendment_reason,
                                
                                CONCAT(
                                    COALESCE(u.first_name, ''),
                                    ' ',
                                    COALESCE(u.middle_name, ''),
                                    ' ',
                                    COALESCE(u.last_name, '')
                                ) AS technician_name,
                                
                                CONCAT(
                                    COALESCE(rv.first_name, ''),
                                    ' ',
                                    COALESCE(rv.middle_name, ''),
                                    ' ',
                                    COALESCE(rv.last_name, '')
                                ) AS verified_by_name,
                                
                                CONCAT(
                                    COALESCE(dr.first_name, ''),
                                    ' ',
                                    COALESCE(dr.middle_name, ''),
                                    ' ',
                                    COALESCE(dr.last_name, '')
                                ) AS reviewed_by_name,
                                
                                CONCAT(
                                    COALESCE(p.first_name, ''),
                                    ' ',
                                    COALESCE(p.middle_name, ''),
                                    ' ',
                                    COALESCE(p.last_name, '')
                                ) AS patient_name,
                                
                                p.mr_number,
                                p.date_of_birth,
                                p.gender,
                                p.phone,
                                
                                CONCAT(
                                    COALESCE(d.first_name, ''),
                                    ' ',
                                    COALESCE(d.middle_name, ''),
                                    ' ',
                                    COALESCE(d.last_name, '')
                                ) AS ordering_doctor_name
                                
                            FROM lab_test_results ltr
                            INNER JOIN lab_orders lo ON ltr.lab_order_id = lo.id
                            INNER JOIN patients p ON lo.patient_id = p.id AND p.deleted_at IS NULL
                            LEFT JOIN lab_order_items loi ON loi.lab_order_id = lo.id AND loi.test_name = ltr.test_name AND loi.deleted_at IS NULL
                            LEFT JOIN users u ON ltr.performed_by_user_id = u.id AND u.deleted_at IS NULL
                            LEFT JOIN users rv ON ltr.verified_by_user_id = rv.id AND rv.deleted_at IS NULL
                            LEFT JOIN users dr ON ltr.doctor_reviewed_by = dr.id AND dr.deleted_at IS NULL
                            LEFT JOIN users d ON lo.doctor_id = d.id AND d.deleted_at IS NULL
                            {$where_clause}
                            AND lo.deleted_at IS NULL
                            ORDER BY 
                                CASE WHEN ltr.is_critical = 1 THEN 0 ELSE 1 END,
                                ltr.created_at DESC,
                                ltr.test_name ASC
                        ";
                
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                $lab_order_ids = array();
                foreach ($results as $row) {
                    if (!in_array($row['lab_order_id'], $lab_order_ids)) {
                        $lab_order_ids[] = $row['lab_order_id'];
                    }
                }
                
                $components_data = array();
                $specimen_data = array();
                
                if (!empty($lab_order_ids)) {
                    $in_placeholders = implode(',', array_fill(0, count($lab_order_ids), '?'));
                    
                    $comp_sql = "
                        SELECT 
                            ltc.lab_order_id,
                            ltc.test_component_name,
                            ltc.result_numeric,
                            ltc.result_text,
                            ltc.result_unit,
                            ltc.reference_range_low,
                            ltc.reference_range_high,
                            ltc.reference_range_text,
                            ltc.is_abnormal,
                            ltc.is_critical,
                            ltc.critical_flag_notes,
                            ltc.technician_notes
                        FROM lab_test_components ltc
                        WHERE ltc.lab_order_id IN ({$in_placeholders})
                        AND ltc.deleted_at IS NULL
                        ORDER BY ltc.display_order, ltc.test_component_name
                    ";
                    $comp_stmt = $pdo->prepare($comp_sql);
                    $comp_stmt->execute($lab_order_ids);
                    $components = $comp_stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    foreach ($components as $comp) {
                        $order_id = $comp['lab_order_id'];
                        if (!isset($components_data[$order_id])) {
                            $components_data[$order_id] = array();
                        }
                        $components_data[$order_id][] = $comp;
                    }
                    
                    $spec_sql = "
                        SELECT 
                            st.order_id AS lab_order_id,
                            st.barcode,
                            st.specimen_type,
                            st.specimen_volume,
                            st.container_type,
                            st.status,
                            st.collected_at,
                            st.received_at,
                            st.processed_at,
                            st.completed_at,
                            st.is_hemolyzed,
                            st.is_icteric,
                            st.is_lipemic,
                            st.quality_issues,
                            st.storage_location,
                            st.notes,
                            CONCAT(
                                COALESCE(st_col.first_name, ''),
                                ' ',
                                COALESCE(st_col.middle_name, ''),
                                ' ',
                                COALESCE(st_col.last_name, '')
                            ) AS collected_by
                        FROM specimen_tracking st
                        LEFT JOIN users st_col ON st.collected_by_user_id = st_col.id AND st_col.deleted_at IS NULL
                        WHERE st.order_id IN ({$in_placeholders})
                        AND st.deleted_at IS NULL
                        ORDER BY st.created_at DESC
                    ";
                    $spec_stmt = $pdo->prepare($spec_sql);
                    $spec_stmt->execute($lab_order_ids);
                    $specimens = $spec_stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    foreach ($specimens as $spec) {
                        $order_id = $spec['lab_order_id'];
                        if (!isset($specimen_data[$order_id])) {
                            $specimen_data[$order_id] = array();
                        }
                        $specimen_data[$order_id][] = $spec;
                    }
                }
                
                $processed_results = array();
                $total_count = 0;
                $unread_count = 0;
                $critical_count = 0;
                $abnormal_count = 0;
                $reviewed_count = 0;
                $amended_count = 0;
                
                foreach ($results as $row) {
                    $row['test_components'] = isset($components_data[$row['lab_order_id']]) 
                        ? $components_data[$row['lab_order_id']] 
                        : array();
                    $row['specimen_data'] = isset($specimen_data[$row['lab_order_id']]) 
                        ? $specimen_data[$row['lab_order_id']] 
                        : array();
                    
                    $row['age'] = 'N/A';
                    if (!empty($row['date_of_birth'])) {
                        try {
                            $birthDate = new DateTime($row['date_of_birth']);
                            $today = new DateTime();
                            $row['age'] = $birthDate->diff($today)->y;
                        } catch (Exception $e) {
                            $row['age'] = 'N/A';
                        }
                    }
                    
                    if (!empty($row['reference_range_text'])) {
                        $row['reference_display'] = $row['reference_range_text'];
                    } elseif ($row['reference_range_low'] !== null && $row['reference_range_high'] !== null) {
                        $row['reference_display'] = $row['reference_range_low'] . ' - ' . $row['reference_range_high'] . ' ' . ($row['result_unit'] ?? '');
                    } else {
                        $row['reference_display'] = 'N/A';
                    }
                    
                    if ($row['result_text'] !== null && $row['result_text'] !== '') {
                        $row['result_display'] = $row['result_text'];
                    } else {
                        $row['result_display'] = $row['result_numeric'] ?? 'N/A';
                        if ($row['result_unit']) {
                            $row['result_display'] .= ' ' . $row['result_unit'];
                        }
                    }
                    
                    $row['is_reviewed'] = $row['doctor_reviewed_at'] !== null;
                    
                    // Check if amended
                    $row['is_amended'] = (int)($row['is_amended'] ?? 0);
                    if ($row['is_amended'] == 1) {
                        $amended_count++;
                    }
                    
                    $total_count++;
                    if ($row['is_reviewed']) {
                        $reviewed_count++;
                    } else {
                        $unread_count++;
                        if ($row['is_critical'] == 1) {
                            $critical_count++;
                        } elseif ($row['is_abnormal'] == 1) {
                            $abnormal_count++;
                        }
                    }
                    
                    $processed_results[] = $row;
                }
                
                jsonResponse(true, '', array(
                    'results' => $processed_results,
                    'stats' => array(
                        'total' => $total_count,
                        'unread' => $unread_count,
                        'critical' => $critical_count,
                        'abnormal' => $abnormal_count,
                        'reviewed' => $reviewed_count,
                        'amended' => $amended_count,
                        'has_unread' => $unread_count > 0,
                        'has_critical' => $critical_count > 0
                    )
                ));
                
            } catch (PDOException $e) {
                error_log('Fetch Lab Results Error: ' . $e->getMessage());
                jsonResponse(false, 'Database error: ' . $e->getMessage());
            } catch (Exception $e) {
                error_log('Fetch Lab Results General Error: ' . $e->getMessage());
                jsonResponse(false, 'Error: ' . $e->getMessage());
            }
        }

        // ---------- FETCH LAB TRENDS (Cumulative Historical Data) ----------
        if ($action === 'fetch_lab_trends') {
            $patient_id = (int)($_POST['patient_id'] ?? 0);
            $test_id = (int)($_POST['test_id'] ?? 0);
            $test_name = trim($_POST['test_name'] ?? '');
            
            if ($patient_id <= 0) {
                jsonResponse(false, 'Invalid patient ID');
            }
            
            try {
                // Build the query based on test_id or test_name
                $where_conditions = ["lo.patient_id = :patient_id", "lo.status = 'completed'"];
                $params = [':patient_id' => $patient_id];
                
                if ($test_id > 0) {
                    $where_conditions[] = "ltr.test_id = :test_id";
                    $params[':test_id'] = $test_id;
                } elseif (!empty($test_name)) {
                    $where_conditions[] = "ltr.test_name LIKE :test_name";
                    $params[':test_name'] = '%' . $test_name . '%';
                } else {
                    jsonResponse(false, 'Please provide a test ID or test name');
                }
                
                $sql = "
                    SELECT 
                        lo.order_number,
                        lo.order_date,
                        lo.visit_id,
                        ltr.id AS result_id,
                        ltr.test_name,
                        ltr.result_numeric,
                        ltr.result_text,
                        ltr.result_unit,
                        ltr.reference_range_low,
                        ltr.reference_range_high,
                        ltr.reference_range_text,
                        ltr.is_abnormal,
                        ltr.is_critical,
                        ltr.verified_at,
                        ltr.created_at,
                        pv.visit_date,
                        pv.visit_type,
                        CONCAT(d.first_name, ' ', COALESCE(d.middle_name, ''), ' ', d.last_name) AS ordering_doctor
                    FROM lab_test_results ltr
                    INNER JOIN lab_orders lo ON ltr.lab_order_id = lo.id
                    LEFT JOIN patient_visits pv ON lo.visit_id = pv.id
                    LEFT JOIN users d ON lo.doctor_id = d.id
                    WHERE " . implode(' AND ', $where_conditions) . "
                    AND lo.deleted_at IS NULL
                    AND ltr.deleted_at IS NULL
                    ORDER BY lo.order_date ASC, ltr.created_at ASC
                ";
                
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $trends = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Calculate trend statistics
                $stats = [];
                if (!empty($trends)) {
                    $values = array_filter(array_column($trends, 'result_numeric'), function($v) { return $v !== null && $v !== ''; });
                    if (!empty($values)) {
                        $stats = [
                            'count' => count($values),
                            'min' => min($values),
                            'max' => max($values),
                            'avg' => array_sum($values) / count($values),
                            'latest' => end($values),
                            'first' => reset($values)
                        ];
                    }
                }
                
                jsonResponse(true, '', [
                    'trends' => $trends,
                    'stats' => $stats,
                    'test_name' => !empty($trends) ? $trends[0]['test_name'] : $test_name
                ]);
                
            } catch (PDOException $e) {
                error_log('Fetch Lab Trends Error: ' . $e->getMessage());
                jsonResponse(false, 'Database error: ' . $e->getMessage());
            }
        }

        // ---------- MARK RESULT VIEWED (AUTO-VIEW) ----------
        if ($action === 'mark_result_viewed') {
            // [Existing code - kept intact]
            $result_id = (int)($_POST['result_id'] ?? 0);
            if ($result_id <= 0) jsonResponse(false, 'Invalid result ID');
            
            try {
                $sql = "UPDATE lab_test_results 
                        SET critical_flag_notes = CONCAT(
                            COALESCE(critical_flag_notes, ''),
                            '\n[VIEWED: ', NOW(), '] By Dr. ',
                            (SELECT first_name FROM users WHERE id = :user_id),
                            ' (auto-read)'
                        )
                        WHERE id = :result_id
                        AND doctor_reviewed_at IS NULL";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    ':result_id' => $result_id,
                    ':user_id' => $user_id
                ]);
                
                jsonResponse(true, 'Result marked as viewed');
            } catch (PDOException $e) {
                jsonResponse(false, 'Database error: ' . $e->getMessage());
            }
        }

        // ---------- BULK MARK VIEWED ----------
        if ($action === 'bulk_mark_viewed') {
            // [Existing code - kept intact]
            $result_ids = json_decode($_POST['result_ids'] ?? '[]', true);
            if (empty($result_ids)) jsonResponse(false, 'No results to mark');
            
            try {
                $pdo->beginTransaction();
                
                $placeholders = rtrim(str_repeat('?,', count($result_ids)), ',');
                
                $sql = "UPDATE lab_test_results 
                        SET critical_flag_notes = CONCAT(
                            COALESCE(critical_flag_notes, ''),
                            '\n[VIEWED: ', NOW(), '] By Dr. ',
                            (SELECT first_name FROM users WHERE id = ?),
                            ' (auto-read)'
                        )
                        WHERE id IN ($placeholders)
                        AND doctor_reviewed_at IS NULL";
                
                $stmt = $pdo->prepare($sql);
                $params = array_merge([$user_id], $result_ids);
                $stmt->execute($params);
                
                $pdo->commit();
                jsonResponse(true, 'Results marked as viewed');
            } catch (PDOException $e) {
                $pdo->rollBack();
                jsonResponse(false, 'Database error: ' . $e->getMessage());
            }
        }

        // ---------- REVIEW LAB RESULT ----------
        if ($action === 'review_lab_result') {
            // [Existing code - kept intact]
            $result_id = (int)($_POST['result_id'] ?? 0);
            $notes = trim($_POST['notes'] ?? '');
            
            if ($result_id <= 0) {
                jsonResponse(false, 'Invalid result ID');
            }
            
            try {
                $pdo->beginTransaction();
                
                $get_notes = $pdo->prepare("SELECT critical_flag_notes FROM lab_test_results WHERE id = :id");
                $get_notes->execute([':id' => $result_id]);
                $current = $get_notes->fetch(PDO::FETCH_ASSOC);
                
                $new_notes = $current['critical_flag_notes'] ?? '';
                $new_notes .= "\n[REVIEWED: " . date('Y-m-d H:i:s') . "] By: Dr. " . $user_id;
                if (!empty($notes)) {
                    $new_notes .= " Notes: " . $notes;
                }
                
                $sql = "UPDATE lab_test_results 
                        SET doctor_reviewed_at = NOW(),
                            doctor_reviewed_by = :reviewer_id,
                            critical_flag_notes = :notes
                        WHERE id = :result_id";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    ':reviewer_id' => $user_id,
                    ':notes' => $new_notes,
                    ':result_id' => $result_id
                ]);
                
                $check_sql = "
                    SELECT COUNT(*) as total, 
                        SUM(CASE WHEN doctor_reviewed_at IS NOT NULL THEN 1 ELSE 0 END) as reviewed
                    FROM lab_test_results
                    WHERE lab_order_id = (SELECT lab_order_id FROM lab_test_results WHERE id = :result_id)
                ";
                $check_stmt = $pdo->prepare($check_sql);
                $check_stmt->execute([':result_id' => $result_id]);
                $status = $check_stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($status['total'] > 0 && $status['total'] == $status['reviewed']) {
                    $update_order = $pdo->prepare("
                        UPDATE lab_orders 
                        SET status = 'completed', 
                            completed_at = NOW(), 
                            updated_at = NOW() 
                        WHERE id = (SELECT lab_order_id FROM lab_test_results WHERE id = :result_id)
                    ");
                    $update_order->execute([':result_id' => $result_id]);
                }
                
                $pdo->commit();
                jsonResponse(true, 'Result reviewed successfully');
                
            } catch (PDOException $e) {
                $pdo->rollBack();
                error_log('Review Lab Result Error: ' . $e->getMessage());
                jsonResponse(false, 'Database error: ' . $e->getMessage());
            }
        }

        // ---------- FETCH CRITICAL ALERTS ----------
        if ($action === 'fetch_critical_alerts') {
            // [Existing code - kept intact]
            $visit_id = (int)($_POST['visit_id'] ?? 0);
            if ($visit_id <= 0) jsonResponse(false, 'Invalid visit ID');
            
            $branch_condition = "";
            $params = array(':visit_id' => $visit_id);
            
            if (!$is_global_admin && $branch_id > 0) {
                $branch_condition = " AND lo.branch_id = :branch_id";
                $params[':branch_id'] = $branch_id;
            }
            
            $sql = "
                SELECT 
                    ltr.id,
                    ltr.lab_order_id,
                    ltr.test_name,
                    ltr.result_numeric,
                    ltr.result_text,
                    ltr.result_unit,
                    ltr.reference_range_low,
                    ltr.reference_range_high,
                    ltr.reference_range_text,
                    ltr.is_critical,
                    ltr.critical_flag_notes,
                    ltr.doctor_reviewed_at,
                    lo.order_number,
                    lo.order_date,
                    CONCAT(u.first_name, ' ', COALESCE(u.middle_name, ''), ' ', u.last_name) AS technician_name
                FROM lab_test_results ltr
                INNER JOIN lab_orders lo ON ltr.lab_order_id = lo.id
                LEFT JOIN users u ON ltr.performed_by_user_id = u.id AND u.deleted_at IS NULL
                WHERE lo.visit_id = :visit_id
                AND ltr.is_critical = 1
                AND ltr.doctor_reviewed_at IS NULL
                AND lo.deleted_at IS NULL
                $branch_condition
                ORDER BY ltr.created_at DESC
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $alerts = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            jsonResponse(true, '', array('alerts' => $alerts));
        }

        // ---------- MARK ALERT READ ----------
        if ($action === 'mark_alert_read') {
            // [Existing code - kept intact]
            $alert_id = (int)($_POST['alert_id'] ?? 0);
            if ($alert_id <= 0) jsonResponse(false, 'Invalid alert ID');
            
            $sql = "UPDATE lab_test_results SET doctor_reviewed_at = NOW(), doctor_reviewed_by = :doctor_id WHERE id = :id";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':doctor_id' => $user_id, ':id' => $alert_id]);
            
            jsonResponse(true, 'Alert marked as read');
        }

        // ---------- FETCH SPECIMEN STATUS ----------
        if ($action === 'fetch_specimen_status') {
            // [Existing code - kept intact]
            $visit_id = (int)($_POST['visit_id'] ?? 0);
            if ($visit_id <= 0) jsonResponse(false, 'Invalid visit ID');
            
            $sql = "
                SELECT 
                    st.id,
                    st.barcode,
                    st.specimen_type,
                    st.specimen_volume,
                    st.container_type,
                    st.status,
                    st.is_hemolyzed,
                    st.is_icteric,
                    st.is_lipemic,
                    st.quality_issues,
                    st.storage_location,
                    st.collected_at,
                    st.received_at,
                    st.processed_at,
                    st.completed_at,
                    CONCAT(u.first_name, ' ', u.last_name) AS collected_by,
                    lo.order_number
                FROM specimen_tracking st
                INNER JOIN lab_orders lo ON st.order_id = lo.id
                LEFT JOIN users u ON st.collected_by_user_id = u.id
                WHERE lo.visit_id = :visit_id
                AND st.deleted_at IS NULL
                ORDER BY st.created_at DESC
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(array(':visit_id' => $visit_id));
            $specimens = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            jsonResponse(true, '', array('specimens' => $specimens));
        }

        // ---------- CREATE SPECIMEN ----------
        if ($action === 'create_specimen') {
            // [Existing code - kept intact]
            $order_id = (int)($_POST['order_id'] ?? 0);
            $specimen_type = trim($_POST['specimen_type'] ?? '');
            $specimen_volume = trim($_POST['specimen_volume'] ?? '');
            $container_type = trim($_POST['container_type'] ?? '');
            $storage_location = trim($_POST['storage_location'] ?? '');
            $notes = trim($_POST['notes'] ?? '');
            
            if ($order_id <= 0 || empty($specimen_type)) {
                jsonResponse(false, 'Order ID and specimen type are required');
            }
            
            try {
                $barcode = 'SPC-' . date('Ymd') . '-' . str_pad(rand(1, 99999), 5, '0', STR_PAD_LEFT);
                
                $sql = "
                    INSERT INTO specimen_tracking 
                    (order_id, barcode, specimen_type, specimen_volume, container_type, 
                     storage_location, status, collected_by_user_id, collected_at, notes)
                    VALUES 
                    (:order_id, :barcode, :specimen_type, :specimen_volume, :container_type,
                     :storage_location, 'received', :user_id, NOW(), :notes)
                ";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    ':order_id' => $order_id,
                    ':barcode' => $barcode,
                    ':specimen_type' => $specimen_type,
                    ':specimen_volume' => $specimen_volume,
                    ':container_type' => $container_type,
                    ':storage_location' => $storage_location,
                    ':user_id' => $user_id,
                    ':notes' => $notes
                ]);
                
                $update_lo = $pdo->prepare("UPDATE lab_orders SET specimen_type = :specimen_type, specimen_collected_at = NOW(), specimen_collected_by_user_id = :user_id WHERE id = :id");
                $update_lo->execute([
                    ':specimen_type' => $specimen_type,
                    ':user_id' => $user_id,
                    ':id' => $order_id
                ]);
                
                jsonResponse(true, 'Specimen created successfully', ['barcode' => $barcode]);
                
            } catch (PDOException $e) {
                jsonResponse(false, 'Database error: ' . $e->getMessage());
            }
        }

        // ---------- FETCH PATIENT INSURANCE ----------
        if ($action === 'fetch_patient_insurance') {
            // [Existing code - kept intact]
            $patient_id = (int)($_POST['patient_id'] ?? 0);
            if ($patient_id <= 0) jsonResponse(false, 'Missing patient ID');
            
            $sql = "
                SELECT 
                    pip.id,
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
                    pip.is_primary,
                    pip.status,
                    CONCAT(u.first_name, ' ', u.last_name) AS verified_by
                FROM patient_insurance_profiles pip
                INNER JOIN insurance_providers ip ON pip.insurance_provider_id = ip.id
                LEFT JOIN users u ON pip.verified_by_user_id = u.id
                WHERE pip.patient_id = :patient_id 
                  AND pip.deleted_at IS NULL
                  AND pip.status = 'active'
                ORDER BY pip.is_primary DESC, pip.effective_from DESC
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':patient_id' => $patient_id]);
            $insurance = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            jsonResponse(true, '', ['insurance' => $insurance]);
        }

        // ---------- GET PATIENT INVOICES ----------
        if ($action === 'get_patient_invoices') {
            // [Existing code - kept intact]
            $patient_id = (int)($_POST['patient_id'] ?? 0);
            if ($patient_id <= 0) jsonResponse(false, 'Missing patient ID');
            
            $sql = "
                SELECT 
                    id,
                    invoice_number,
                    invoice_date,
                    total_amount,
                    paid_amount,
                    due_amount,
                    payment_status,
                    billing_type,
                    insurance_approved_amount,
                    co_pay_amount,
                    notes,
                    created_at
                FROM invoices
                WHERE patient_id = :patient_id
                  AND deleted_at IS NULL
                ORDER BY created_at DESC
                LIMIT 20
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':patient_id' => $patient_id]);
            $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            jsonResponse(true, '', ['invoices' => $invoices]);
        }

        // ---------- GET ORDER HISTORY (with Pagination & Filtering) ----------
        if ($action === 'get_order_history') {
            $patient_id = (int)($_POST['patient_id'] ?? 0);
            $page = max(1, (int)($_POST['page'] ?? 1));
            $limit = 15;
            $offset = ($page - 1) * $limit;
            $history_type = $_POST['history_type'] ?? 'all';
            $start_date = $_POST['start_date'] ?? null;
            $end_date = $_POST['end_date'] ?? null;
            
            if ($patient_id <= 0) jsonResponse(false, 'Missing patient ID');
            
            // Build date filter clause
            $date_filter = '';
            $date_params = [];
            if ($start_date && $end_date) {
                $date_filter = " AND created_at BETWEEN :start_date AND :end_date";
                $date_params[':start_date'] = $start_date . ' 00:00:00';
                $date_params[':end_date'] = $end_date . ' 23:59:59';
            } elseif ($start_date) {
                $date_filter = " AND created_at >= :start_date";
                $date_params[':start_date'] = $start_date . ' 00:00:00';
            } elseif ($end_date) {
                $date_filter = " AND created_at <= :end_date";
                $date_params[':end_date'] = $end_date . ' 23:59:59';
            }
            
            $history = [
                'prescriptions' => [],
                'lab_orders' => [],
                'radiology_orders' => [],
                'procedure_orders' => [],
                'consultations' => [],
                'admissions' => [],
                'visits' => [],
                'discharge_summaries' => []
            ];
            
            $total_counts = [];
            
            try {
                $pdo->beginTransaction();
                
                // Function to execute paginated query with filters
                $executeQuery = function($sql, $params, $limit, $offset) use ($pdo) {
                    $sql .= " LIMIT :limit OFFSET :offset";
                    $stmt = $pdo->prepare($sql);
                    $params[':limit'] = $limit;
                    $params[':offset'] = $offset;
                    foreach ($params as $key => $value) {
                        $stmt->bindValue($key, $value);
                    }
                    $stmt->execute();
                    return $stmt->fetchAll(PDO::FETCH_ASSOC);
                };
                
                // Count query helper
                $getCount = function($table, $where, $params) use ($pdo) {
                    $sql = "SELECT COUNT(*) FROM {$table} WHERE {$where} AND deleted_at IS NULL";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($params);
                    return (int)$stmt->fetchColumn();
                };
                
                // Prescriptions
                if ($history_type === 'all' || $history_type === 'prescriptions') {
                    $sql = "SELECT id, prescription_number, prescription_date, clinical_notes, status, payment_status,
                                   (SELECT COUNT(*) FROM prescription_items WHERE prescription_id = p.id AND deleted_at IS NULL) AS item_count,
                                   created_at
                            FROM prescriptions p
                            WHERE patient_id = :patient_id AND deleted_at IS NULL {$date_filter}
                            ORDER BY created_at DESC";
                    $params = array_merge([':patient_id' => $patient_id], $date_params);
                    $history['prescriptions'] = $executeQuery($sql, $params, $limit, $offset);
                    $history['prescriptions_total'] = $getCount('prescriptions', 'patient_id = :patient_id', [':patient_id' => $patient_id]);
                }
                
                // Lab Orders
                if ($history_type === 'all' || $history_type === 'labs') {
                    $sql = "SELECT lo.id, lo.order_number, lo.order_date, lo.priority, lo.status, lo.payment_status, lo.clinical_indication,
                                   (SELECT GROUP_CONCAT(DISTINCT ltr.test_name SEPARATOR ', ') FROM lab_test_results ltr WHERE ltr.lab_order_id = lo.id) AS tests,
                                   (SELECT COUNT(*) FROM lab_test_results ltr WHERE ltr.lab_order_id = lo.id AND ltr.doctor_reviewed_at IS NULL AND lo.status = 'completed') AS unread_count,
                                   (SELECT COUNT(*) FROM lab_test_results ltr WHERE ltr.lab_order_id = lo.id AND ltr.is_critical = 1 AND ltr.doctor_reviewed_at IS NULL) AS critical_unread,
                                   lo.created_at
                            FROM lab_orders lo
                            WHERE lo.patient_id = :patient_id AND lo.deleted_at IS NULL {$date_filter}
                            ORDER BY lo.created_at DESC";
                    $params = array_merge([':patient_id' => $patient_id], $date_params);
                    $history['lab_orders'] = $executeQuery($sql, $params, $limit, $offset);
                    $history['lab_orders_total'] = $getCount('lab_orders', 'patient_id = :patient_id', [':patient_id' => $patient_id]);
                }
                
                // Radiology Orders
                if ($history_type === 'all' || $history_type === 'radiology') {
                    $sql = "SELECT id, order_number, service_name, modality, priority, status, payment_status, created_at
                            FROM radiology_orders
                            WHERE patient_id = :patient_id AND deleted_at IS NULL {$date_filter}
                            ORDER BY created_at DESC";
                    $params = array_merge([':patient_id' => $patient_id], $date_params);
                    $history['radiology_orders'] = $executeQuery($sql, $params, $limit, $offset);
                    $history['radiology_orders_total'] = $getCount('radiology_orders', 'patient_id = :patient_id', [':patient_id' => $patient_id]);
                }
                
                // Procedure Orders
                if ($history_type === 'all' || $history_type === 'procedures') {
                    $sql = "SELECT id, procedure_number, procedure_name, priority, scheduled_date, execution_status, payment_status,
                                   created_at
                            FROM procedure_orders
                            WHERE patient_id = :patient_id AND deleted_at IS NULL {$date_filter}
                            ORDER BY created_at DESC";
                    $params = array_merge([':patient_id' => $patient_id], $date_params);
                    $history['procedure_orders'] = $executeQuery($sql, $params, $limit, $offset);
                    $history['procedure_orders_total'] = $getCount('procedure_orders', 'patient_id = :patient_id', [':patient_id' => $patient_id]);
                }
                
                // Consultations
                if ($history_type === 'all' || $history_type === 'consultations') {
                    $sql = "SELECT id, consultation_number, consultation_date, chief_complaint, final_diagnosis, status,
                                   provisional_diagnosis, assessment, treatment_plan, created_at,
                                   CONCAT(u.first_name, ' ', u.last_name) as doctor_name
                            FROM consultations c
                            LEFT JOIN users u ON c.doctor_id = u.id
                            WHERE c.patient_id = :patient_id AND c.deleted_at IS NULL {$date_filter}
                            ORDER BY c.created_at DESC";
                    $params = array_merge([':patient_id' => $patient_id], $date_params);
                    $history['consultations'] = $executeQuery($sql, $params, $limit, $offset);
                    $history['consultations_total'] = $getCount('consultations', 'patient_id = :patient_id', [':patient_id' => $patient_id]);
                }
                
                // Admissions
                if ($history_type === 'all' || $history_type === 'admissions') {
                    $sql = "SELECT id, admission_number, admitted_at, discharged_at, status, primary_diagnosis,
                                   condition_status, admission_type, expected_discharge_at, created_at
                            FROM admissions
                            WHERE patient_id = :patient_id AND deleted_at IS NULL {$date_filter}
                            ORDER BY created_at DESC";
                    $params = array_merge([':patient_id' => $patient_id], $date_params);
                    $history['admissions'] = $executeQuery($sql, $params, $limit, $offset);
                    $history['admissions_total'] = $getCount('admissions', 'patient_id = :patient_id', [':patient_id' => $patient_id]);
                }
                
                // Visits
                if ($history_type === 'all' || $history_type === 'visits') {
                    $sql = "SELECT id, visit_number, visit_date, visit_type, status, created_at
                            FROM patient_visits
                            WHERE patient_id = :patient_id AND deleted_at IS NULL {$date_filter}
                            ORDER BY created_at DESC";
                    $params = array_merge([':patient_id' => $patient_id], $date_params);
                    $history['visits'] = $executeQuery($sql, $params, $limit, $offset);
                    $history['visits_total'] = $getCount('patient_visits', 'patient_id = :patient_id', [':patient_id' => $patient_id]);
                }
                
                // Discharge Summaries
                if ($history_type === 'all' || $history_type === 'discharges') {
                    $sql = "SELECT id, discharge_date, discharge_condition, final_diagnosis, follow_up_date, follow_up_instructions,
                                   created_at
                            FROM discharge_summaries
                            WHERE patient_id = :patient_id AND deleted_at IS NULL {$date_filter}
                            ORDER BY created_at DESC";
                    $params = array_merge([':patient_id' => $patient_id], $date_params);
                    $history['discharge_summaries'] = $executeQuery($sql, $params, $limit, $offset);
                    $history['discharge_summaries_total'] = $getCount('discharge_summaries', 'patient_id = :patient_id', [':patient_id' => $patient_id]);
                }
                
                $pdo->commit();
                
                // Add pagination metadata
                $history['pagination'] = [
                    'page' => $page,
                    'limit' => $limit,
                    'offset' => $offset,
                    'history_type' => $history_type
                ];
                
                jsonResponse(true, '', ['history' => $history]);
                
            } catch (PDOException $e) {
                $pdo->rollBack();
                jsonResponse(false, 'Database error: ' . $e->getMessage());
            }
        }

        // ---------- GET PATIENT MEDICATIONS ----------
        if ($action === 'get_patient_medications') {
            // [Existing code - kept intact]
            $patient_id = (int)($_POST['patient_id'] ?? 0);
            if ($patient_id <= 0) jsonResponse(false, 'Missing patient ID');
            
            $sql = "
                SELECT 
                    pi.medicine_name,
                    pi.dosage,
                    pi.frequency,
                    pi.duration_days,
                    pi.instructions,
                    pi.meal_relation,
                    p.prescription_date,
                    p.status as prescription_status,
                    mm.generic_name,
                    mm.brand_name,
                    mm.dosage_form,
                    mm.strength
                FROM prescription_items pi
                INNER JOIN prescriptions p ON pi.prescription_id = p.id
                LEFT JOIN medicines_master mm ON pi.medicine_id = mm.id
                WHERE p.patient_id = :patient_id
                  AND p.status IN ('active', 'dispensed')
                  AND p.deleted_at IS NULL
                  AND pi.deleted_at IS NULL
                ORDER BY p.created_at DESC
                LIMIT 30
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':patient_id' => $patient_id]);
            $medications = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            jsonResponse(true, '', ['medications' => $medications]);
        }

        // ---------- GET CONSULTATION HISTORY ----------
        if ($action === 'get_consultation_history') {
            // [Existing code - kept intact]
            $patient_id = (int)($_POST['patient_id'] ?? 0);
            if ($patient_id <= 0) jsonResponse(false, 'Missing patient ID');
            
            $sql = "
                SELECT 
                    consultation_number,
                    consultation_date,
                    final_diagnosis,
                    provisional_diagnosis,
                    icd10_codes,
                    assessment,
                    treatment_plan,
                    status,
                    CONCAT(u.first_name, ' ', u.last_name) as doctor_name
                FROM consultations c
                LEFT JOIN users u ON c.doctor_id = u.id
                WHERE c.patient_id = :patient_id
                  AND c.deleted_at IS NULL
                ORDER BY c.created_at DESC
                LIMIT 20
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':patient_id' => $patient_id]);
            $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            jsonResponse(true, '', ['consultation_history' => $history]);
        }

        // ---------- GET PATIENT VITALS HISTORY ----------
        if ($action === 'get_patient_vitals_history') {
            // [Existing code - kept intact]
            $patient_id = (int)($_POST['patient_id'] ?? 0);
            if ($patient_id <= 0) jsonResponse(false, 'Missing patient ID');
            
            $sql = "
                SELECT 
                    tr.heart_rate,
                    tr.blood_pressure_systolic,
                    tr.blood_pressure_diastolic,
                    tr.temperature_celsius,
                    tr.respiratory_rate,
                    tr.oxygen_saturation,
                    tr.weight_kg,
                    tr.bmi,
                    tr.pain_score,
                    tr.consciousness,
                    tr.triage_time,
                    tr.chief_complaint,
                    CONCAT(u.first_name, ' ', u.last_name) as taken_by
                FROM triage_records tr
                LEFT JOIN users u ON tr.taken_by_user_id = u.id
                WHERE tr.patient_id = :patient_id
                  AND tr.deleted_at IS NULL
                ORDER BY tr.triage_time DESC
                LIMIT 10
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':patient_id' => $patient_id]);
            $vitals = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            jsonResponse(true, '', ['vitals' => $vitals]);
        }

        // ---------- GET PATIENT ALLERGIES ----------
        if ($action === 'get_patient_allergies') {
            // [Existing code - kept intact]
            $patient_id = (int)($_POST['patient_id'] ?? 0);
            if ($patient_id <= 0) jsonResponse(false, 'Missing patient ID');
            
            $sql = "
                SELECT 
                    allergies,
                    current_medications,
                    created_at
                FROM triage_records
                WHERE patient_id = :patient_id
                  AND deleted_at IS NULL
                  AND (allergies IS NOT NULL OR current_medications IS NOT NULL)
                ORDER BY created_at DESC
                LIMIT 5
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':patient_id' => $patient_id]);
            $allergies = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            jsonResponse(true, '', ['allergies' => $allergies]);
        }

        // ---------- FETCH FAMILY HISTORY ----------
        if ($action === 'fetch_family_history') {
            // [Existing code - kept intact]
            $patient_id = (int)($_POST['patient_id'] ?? 0);
            if ($patient_id <= 0) jsonResponse(false, 'Missing patient ID');
            
            $sql = "
                SELECT 
                    pr.id,
                    pr.relationship_type,
                    pr.is_emergency_contact,
                    pr.notes,
                    pr.created_at,
                    CONCAT(p.first_name, ' ', COALESCE(p.middle_name,''), ' ', p.last_name) AS related_patient_name,
                    p.mr_number,
                    p.phone,
                    p.date_of_birth,
                    p.gender,
                    fmn.note AS family_medical_note
                FROM patient_relationships pr
                INNER JOIN patients p ON pr.related_patient_id = p.id
                LEFT JOIN family_medical_notes fmn ON fmn.primary_patient_id = pr.primary_patient_id AND fmn.deleted_at IS NULL
                WHERE pr.primary_patient_id = :patient_id
                  AND pr.deleted_at IS NULL
                  AND p.deleted_at IS NULL
                ORDER BY pr.created_at DESC
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':patient_id' => $patient_id]);
            $family = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $notes_sql = "
                SELECT note, created_at, CONCAT(u.first_name, ' ', u.last_name) AS created_by
                FROM family_medical_notes
                WHERE primary_patient_id = :patient_id
                  AND deleted_at IS NULL
                ORDER BY created_at DESC
                LIMIT 10
            ";
            $notes_stmt = $pdo->prepare($notes_sql);
            $notes_stmt->execute([':patient_id' => $patient_id]);
            $notes = $notes_stmt->fetchAll(PDO::FETCH_ASSOC);
            
            jsonResponse(true, '', ['family' => $family, 'notes' => $notes]);
        }

        // ---------- SAVE FAMILY HISTORY NOTE ----------
        if ($action === 'save_family_history_note') {
            // [Existing code - kept intact]
            $patient_id = (int)($_POST['patient_id'] ?? 0);
            $relationship_type = trim($_POST['relationship_type'] ?? '');
            $related_patient_name = trim($_POST['related_patient_name'] ?? '');
            $family_medical_note = trim($_POST['family_medical_note'] ?? '');
            $is_emergency_contact = (int)($_POST['is_emergency_contact'] ?? 0);
            
            if ($patient_id <= 0) {
                jsonResponse(false, 'Missing patient ID');
            }
            if (empty($relationship_type) || empty($related_patient_name)) {
                jsonResponse(false, 'Relationship type and family member name are required');
            }
            
            try {
                $pdo->beginTransaction();
                
                $check_sql = "SELECT id FROM patient_relationships WHERE primary_patient_id = :patient_id AND relationship_type = :rel_type AND deleted_at IS NULL ORDER BY created_at DESC LIMIT 1";
                $check_stmt = $pdo->prepare($check_sql);
                $check_stmt->execute([
                    ':patient_id' => $patient_id,
                    ':rel_type' => $relationship_type
                ]);
                $existing = $check_stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($existing) {
                    $update_sql = "UPDATE patient_relationships SET notes = :notes, is_emergency_contact = :is_emergency, updated_at = NOW() WHERE id = :id";
                    $update_stmt = $pdo->prepare($update_sql);
                    $update_stmt->execute([
                        ':notes' => $family_medical_note,
                        ':is_emergency' => $is_emergency_contact,
                        ':id' => $existing['id']
                    ]);
                    $relationship_id = $existing['id'];
                } else {
                    $insert_related_sql = "INSERT INTO patients (branch_id, mr_number, first_name, last_name, date_of_birth, gender, created_by_user_id) VALUES (:branch_id, CONCAT('FAM-', UUID()), :first_name, :last_name, CURDATE(), 'other', :user_id)";
                    $insert_related_stmt = $pdo->prepare($insert_related_sql);
                    $insert_related_stmt->execute([
                        ':branch_id' => $branch_id,
                        ':first_name' => $related_patient_name,
                        ':last_name' => 'Family',
                        ':user_id' => $user_id
                    ]);
                    $related_patient_id = $pdo->lastInsertId();
                    
                    $insert_sql = "INSERT INTO patient_relationships (primary_patient_id, related_patient_id, relationship_type, is_emergency_contact, notes, created_by_user_id) VALUES (:primary_id, :related_id, :rel_type, :is_emergency, :notes, :user_id)";
                    $insert_stmt = $pdo->prepare($insert_sql);
                    $insert_stmt->execute([
                        ':primary_id' => $patient_id,
                        ':related_id' => $related_patient_id,
                        ':rel_type' => $relationship_type,
                        ':is_emergency' => $is_emergency_contact,
                        ':notes' => $family_medical_note,
                        ':user_id' => $user_id
                    ]);
                    $relationship_id = $pdo->lastInsertId();
                }
                
                $note_check_sql = "SELECT id FROM family_medical_notes WHERE primary_patient_id = :patient_id AND deleted_at IS NULL ORDER BY created_at DESC LIMIT 1";
                $note_check_stmt = $pdo->prepare($note_check_sql);
                $note_check_stmt->execute([':patient_id' => $patient_id]);
                $note_existing = $note_check_stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($note_existing) {
                    $note_update_sql = "UPDATE family_medical_notes SET note = CONCAT(COALESCE(note, ''), '\n[UPDATE: ', NOW(), '] ', :note), updated_at = NOW() WHERE id = :id";
                    $note_update_stmt = $pdo->prepare($note_update_sql);
                    $note_update_stmt->execute([
                        ':note' => $family_medical_note,
                        ':id' => $note_existing['id']
                    ]);
                } else {
                    $note_insert_sql = "INSERT INTO family_medical_notes (primary_patient_id, note, created_by_user_id) VALUES (:patient_id, :note, :user_id)";
                    $note_insert_stmt = $pdo->prepare($note_insert_sql);
                    $note_insert_stmt->execute([
                        ':patient_id' => $patient_id,
                        ':note' => $family_medical_note,
                        ':user_id' => $user_id
                    ]);
                }
                
                $pdo->commit();
                jsonResponse(true, 'Family history updated successfully', ['relationship_id' => $relationship_id]);
                
            } catch (PDOException $e) {
                $pdo->rollBack();
                jsonResponse(false, 'Database error: ' . $e->getMessage());
            }
        }

        // ---------- REQUEST DOCTOR CONSULTATION ----------
        if ($action === 'request_consultation') {
            // [Existing code - kept intact]
            $patient_id = (int)($_POST['patient_id'] ?? 0);
            $assigned_doctor_id = (int)($_POST['assigned_doctor_id'] ?? 0);
            $urgency_level = $_POST['urgency_level'] ?? 'routine';
            $clinical_question_summary = trim($_POST['clinical_question_summary'] ?? '');
            
            if ($patient_id <= 0 || $assigned_doctor_id <= 0) {
                jsonResponse(false, 'Missing patient or doctor ID');
            }
            if (empty($clinical_question_summary)) {
                jsonResponse(false, 'Clinical question summary is required');
            }

            try {
                $insert_sql = "
                    INSERT INTO doctor_consultations 
                    (branch_id, requested_by_user_id, assigned_doctor_id, patient_id, urgency_level, 
                     clinical_question_summary, status, created_at, updated_at)
                    VALUES 
                    (:branch_id, :requested_by, :assigned_doctor, :patient_id, :urgency, 
                     :summary, 'open', NOW(), NOW())
                ";
                $stmt = $pdo->prepare($insert_sql);
                $stmt->execute([
                    ':branch_id' => $branch_id,
                    ':requested_by' => $user_id,
                    ':assigned_doctor' => $assigned_doctor_id,
                    ':patient_id' => $patient_id,
                    ':urgency' => $urgency_level,
                    ':summary' => $clinical_question_summary
                ]);
                
                generateNotification(
                    $pdo,
                    $assigned_doctor_id,
                    'New Consultation Request',
                    'Dr. ' . $first_name . ' has requested a consultation. Urgency: ' . $urgency_level,
                    'system_notice',
                    $branch_id
                );
                
                jsonResponse(true, 'Consultation request sent successfully');
            } catch (PDOException $e) {
                jsonResponse(false, 'Database error: ' . $e->getMessage());
            }
        }

        // ---------- REFER PATIENT ----------
        if ($action === 'refer_patient') {
            // [Existing code - kept intact]
            $patient_id = (int)($_POST['patient_id'] ?? 0);
            $referral_reason = trim($_POST['referral_reason'] ?? '');
            $referred_to_user_id = (int)($_POST['referred_to_user_id'] ?? 0);
            $visit_id = (int)($_POST['visit_id'] ?? 0);
            
            if ($patient_id <= 0 || $referred_to_user_id <= 0) {
                jsonResponse(false, 'Missing patient or receiving doctor ID');
            }
            if (empty($referral_reason)) {
                jsonResponse(false, 'Referral reason is required');
            }

            try {
                $insert_sql = "
                    INSERT INTO patient_referrals 
                    (branch_id, patient_id, visit_id, referred_from_user_id, referred_to_user_id, 
                     referral_reason, status, created_at, updated_at)
                    VALUES 
                    (:branch_id, :patient_id, :visit_id, :from_user, :to_user, 
                     :reason, 'pending', NOW(), NOW())
                ";
                $stmt = $pdo->prepare($insert_sql);
                $stmt->execute([
                    ':branch_id' => $branch_id,
                    ':patient_id' => $patient_id,
                    ':visit_id' => $visit_id,
                    ':from_user' => $user_id,
                    ':to_user' => $referred_to_user_id,
                    ':reason' => $referral_reason
                ]);
                
                generateNotification(
                    $pdo,
                    $referred_to_user_id,
                    'New Patient Referral',
                    'Dr. ' . $first_name . ' has referred a patient to you.',
                    'system_notice',
                    $branch_id
                );
                
                jsonResponse(true, 'Patient referred successfully');
            } catch (PDOException $e) {
                jsonResponse(false, 'Database error: ' . $e->getMessage());
            }
        }

        // ---------- CREATE DISCHARGE SUMMARY ----------
        if ($action === 'create_discharge_summary') {
            // [Existing code - kept intact]
            $admission_id = (int)($_POST['admission_id'] ?? 0);
            $patient_id = (int)($_POST['patient_id'] ?? 0);
            $visit_id = (int)($_POST['visit_id'] ?? 0);
            $discharge_condition = $_POST['discharge_condition'] ?? 'improved';
            $hospital_course = trim($_POST['hospital_course'] ?? '');
            $final_diagnosis = trim($_POST['final_diagnosis'] ?? '');
            $discharge_medications = trim($_POST['discharge_medications'] ?? '');
            $follow_up_required = (int)($_POST['follow_up_required'] ?? 0);
            $follow_up_date = $_POST['follow_up_date'] ?? null;
            $follow_up_with = trim($_POST['follow_up_with'] ?? '');
            $follow_up_instructions = trim($_POST['follow_up_instructions'] ?? '');
            $special_instructions = trim($_POST['special_instructions'] ?? '');
            $warning_signs = trim($_POST['warning_signs'] ?? '');
            
            if ($admission_id <= 0 || $patient_id <= 0) {
                jsonResponse(false, 'Missing admission or patient ID');
            }

            try {
                $pdo->beginTransaction();
                
                $sql = "
                    INSERT INTO discharge_summaries 
                    (admission_id, patient_id, visit_id, discharging_doctor_id, discharge_date,
                     discharge_condition, hospital_course, final_diagnosis, discharge_medications,
                     follow_up_required, follow_up_date, follow_up_with, follow_up_instructions,
                     special_instructions, created_by_user_id)
                    VALUES 
                    (:admission_id, :patient_id, :visit_id, :doctor_id, NOW(),
                     :condition, :course, :diagnosis, :medications,
                     :follow_up, :follow_up_date, :follow_up_with, :follow_up_instructions,
                     :special_instructions, :user_id)
                ";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    ':admission_id' => $admission_id,
                    ':patient_id' => $patient_id,
                    ':visit_id' => $visit_id,
                    ':doctor_id' => $user_id,
                    ':condition' => $discharge_condition,
                    ':course' => $hospital_course,
                    ':diagnosis' => $final_diagnosis,
                    ':medications' => $discharge_medications,
                    ':follow_up' => $follow_up_required,
                    ':follow_up_date' => $follow_up_date,
                    ':follow_up_with' => $follow_up_with,
                    ':follow_up_instructions' => $follow_up_instructions,
                    ':special_instructions' => $special_instructions,
                    ':user_id' => $user_id
                ]);
                
                $update_adm = $pdo->prepare("
                    UPDATE admissions 
                    SET discharged_at = NOW(), 
                        status = 'discharged',
                        discharge_diagnosis = :diagnosis,
                        discharge_condition = :condition,
                        discharge_summary = :summary,
                        discharge_instructions = :instructions,
                        follow_up_date = :follow_up_date,
                        home_medications = :medications,
                        warning_signs = :warning_signs,
                        updated_at = NOW()
                    WHERE id = :id
                ");
                $update_adm->execute([
                    ':diagnosis' => $final_diagnosis,
                    ':condition' => $discharge_condition,
                    ':summary' => $hospital_course,
                    ':instructions' => $special_instructions,
                    ':follow_up_date' => $follow_up_date,
                    ':medications' => $discharge_medications,
                    ':warning_signs' => $warning_signs,
                    ':id' => $admission_id
                ]);
                
                $pdo->commit();
                jsonResponse(true, 'Discharge summary created successfully');
                
            } catch (PDOException $e) {
                $pdo->rollBack();
                jsonResponse(false, 'Database error: ' . $e->getMessage());
            }
        }

        jsonResponse(false, 'Unsupported action: ' . $action);
    } catch (PDOException $e) {
        error_log('Consultation AJAX error: ' . $e->getMessage());
        jsonResponse(false, 'Database error: ' . $e->getMessage());
    } catch (Exception $e) {
        error_log('Consultation general error: ' . $e->getMessage());
        jsonResponse(false, 'Error: ' . $e->getMessage());
    }
    exit;
}

// ============================================
// 4. INITIAL PAGE DATA
// ============================================
$active_visit_id = (int)($_GET['visit_id'] ?? 0);
$active_patient_id = (int)($_GET['patient_id'] ?? 0);
$current_visit = null;
$patient_data = null;
$admission_data = null;
$patient_insurance = [];
$pending_lab_count = 0;
$pending_rx_count = 0;
$lab_results_ready = false;
$critical_alerts_count = 0;
$family_history = [];
$critical_alerts = [];
$lab_tests = [];
$lab_panels = [];
$radiology_services = [];
$doctors = [];
$beds = [];
$branch_name = 'All Branches';
$doctor_name = '';
$doctor_specialization = 'General Medicine';

if ($pdo) {
    $docStmt = $pdo->prepare("SELECT first_name, last_name FROM users WHERE id = :id");
    $docStmt->execute([':id' => $user_id]);
    $doc = $docStmt->fetch(PDO::FETCH_ASSOC);
    $doctor_name = trim(($doc['first_name'] ?? '') . ' ' . ($doc['last_name'] ?? ''));
    
    $specStmt = $pdo->prepare("SELECT specialization FROM staff_profiles WHERE user_id = :user_id AND deleted_at IS NULL");
    $specStmt->execute([':user_id' => $user_id]);
    $spec = $specStmt->fetch(PDO::FETCH_ASSOC);
    $doctor_specialization = $spec['specialization'] ?? 'General Medicine';
    
    if ($branch_id > 0) {
        $stmt = $pdo->prepare("SELECT name FROM branches WHERE id = :id AND deleted_at IS NULL");
        $stmt->execute([':id' => $branch_id]);
        $branch = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($branch) $branch_name = $branch['name'];
    }
    
    // Fetch lab tests
    $testStmt = $pdo->query("SELECT id, test_name, price, sample_type, turnaround_time_hours, test_category FROM lab_tests_master WHERE is_active = 1 AND deleted_at IS NULL ORDER BY test_name");
    $lab_tests = $testStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Fetch lab panels
    $panelStmt = $pdo->query("SELECT p.id, p.panel_name, p.description,
                              (SELECT COUNT(*) FROM lab_panel_tests WHERE panel_id = p.id AND deleted_at IS NULL) AS test_count
                              FROM lab_panels p
                              WHERE p.is_active = 1 AND p.deleted_at IS NULL
                              ORDER BY p.panel_name");
    $lab_panels = $panelStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // For each panel, get the associated tests
    foreach ($lab_panels as &$panel) {
        $test_sql = "SELECT t.id, t.test_name, t.price, t.sample_type
                     FROM lab_panel_tests pt
                     INNER JOIN lab_tests_master t ON pt.test_id = t.id
                     WHERE pt.panel_id = :panel_id AND t.is_active = 1 AND t.deleted_at IS NULL
                     ORDER BY t.test_name";
        $test_stmt = $pdo->prepare($test_sql);
        $test_stmt->execute([':panel_id' => $panel['id']]);
        $panel['tests'] = $test_stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    $radStmt = $pdo->query("SELECT id, service_name, service_code, category, standard_price FROM radiology_services WHERE is_active = 1 AND deleted_at IS NULL ORDER BY service_name");
    $radiology_services = $radStmt->fetchAll(PDO::FETCH_ASSOC);
    
    $docStmt = $pdo->prepare("
        SELECT u.id, u.first_name, u.last_name, sp.specialization 
        FROM users u
        LEFT JOIN staff_profiles sp ON u.id = sp.user_id
        WHERE u.user_type = 'doctor' 
          AND u.is_active = 1 
          AND u.id != :user_id
          AND u.deleted_at IS NULL
        ORDER BY u.first_name
    ");
    $docStmt->execute([':user_id' => $user_id]);
    $doctors = $docStmt->fetchAll(PDO::FETCH_ASSOC);
    
    if ($branch_id > 0) {
        $bedStmt = $pdo->prepare("
            SELECT b.id, b.bed_number, b.bed_type, b.daily_rate, b.notes, w.name AS ward_name, w.ward_type, w.floor_location
            FROM beds b
            INNER JOIN wards w ON b.ward_id = w.id
            WHERE b.branch_id = :branch_id AND b.status = 'available' AND b.is_active = 1 AND b.deleted_at IS NULL
            ORDER BY w.name, b.bed_number
        ");
        $bedStmt->execute([':branch_id' => $branch_id]);
        $beds = $bedStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    if ($active_visit_id > 0) {
        $sql = "
            SELECT 
                pv.id AS visit_id,
                pv.patient_id,
                pv.queue_status,
                pv.checked_in_at,
                pv.entered_room_at,
                pv.consultation_started_at,
                pv.consultation_completed_at,
                pv.status AS visit_status,
                CONCAT(pat.first_name, ' ', COALESCE(pat.middle_name,''), ' ', pat.last_name) AS patient_name,
                pat.mr_number,
                pat.date_of_birth,
                pat.gender,
                pat.phone,
                pat.email,
                pat.address,
                pat.national_id,
                pat.blood_group,
                pat.emergency_contact_name,
                pat.emergency_contact_phone,
                pat.emergency_contact_relationship,
                tr.id AS triage_id,
                tr.heart_rate,
                tr.blood_pressure_systolic,
                tr.blood_pressure_diastolic,
                tr.temperature_celsius,
                tr.respiratory_rate,
                tr.oxygen_saturation,
                tr.weight_kg,
                tr.height_cm,
                tr.bmi,
                tr.priority AS triage_priority,
                tr.chief_complaint,
                tr.history_of_present_illness,
                tr.allergies,
                tr.current_medications,
                tr.pain_score,
                tr.consciousness,
                a.priority AS appointment_priority,
                a.appointment_number,
                a.id AS appointment_id,
                (SELECT COUNT(*) FROM lab_orders WHERE visit_id = pv.id AND status IN ('pending_payment', 'pending', 'approved', 'collected') AND deleted_at IS NULL) AS pending_lab_orders,
                (SELECT COUNT(*) FROM lab_orders WHERE visit_id = pv.id AND status = 'completed' AND deleted_at IS NULL) AS completed_lab_orders,
                (SELECT COUNT(*) FROM lab_test_results ltr 
                 INNER JOIN lab_orders lo ON ltr.lab_order_id = lo.id 
                 WHERE lo.visit_id = pv.id AND ltr.doctor_reviewed_at IS NULL AND lo.status = 'completed') AS unread_lab_results,
                (SELECT COUNT(*) FROM lab_test_results ltr 
                 INNER JOIN lab_orders lo ON ltr.lab_order_id = lo.id 
                 WHERE lo.visit_id = pv.id AND ltr.is_critical = 1 AND ltr.doctor_reviewed_at IS NULL AND lo.status = 'completed') AS critical_unread,
                (SELECT COUNT(*) FROM prescriptions WHERE visit_id = pv.id AND status = 'pending_payment' AND deleted_at IS NULL) AS pending_prescriptions,
                (SELECT COUNT(*) FROM radiology_orders WHERE visit_id = pv.id AND status = 'pending_payment' AND deleted_at IS NULL) AS pending_radiology,
                (SELECT COUNT(*) FROM procedure_orders WHERE visit_id = pv.id AND execution_status = 'pending_payment' AND deleted_at IS NULL) AS pending_procedures
            FROM patient_visits pv
            INNER JOIN appointments a ON pv.id = a.visit_id
            INNER JOIN patients pat ON pv.patient_id = pat.id AND pat.deleted_at IS NULL
            LEFT JOIN triage_records tr ON tr.visit_id = pv.id AND tr.deleted_at IS NULL
            WHERE pv.id = :visit_id 
              AND a.doctor_id = :doctor_id 
              AND pv.deleted_at IS NULL
        ";
        $params = [':visit_id' => $active_visit_id, ':doctor_id' => $user_id];
        if (!$is_global_admin && $branch_id > 0) {
            $sql .= " AND pv.branch_id = :branch_id";
            $params[':branch_id'] = $branch_id;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $current_visit = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($current_visit) {
            $active_patient_id = $current_visit['patient_id'];
            $pending_lab_count = $current_visit['pending_lab_orders'] ?? 0;
            $pending_rx_count = $current_visit['pending_prescriptions'] ?? 0;
            $lab_results_ready = ($current_visit['unread_lab_results'] ?? 0) > 0;
            $critical_alerts_count = $current_visit['critical_unread'] ?? 0;
            
            $current_visit['bp'] = ($current_visit['blood_pressure_systolic'] && $current_visit['blood_pressure_diastolic']) 
                ? $current_visit['blood_pressure_systolic'] . '/' . $current_visit['blood_pressure_diastolic'] 
                : '--/--';
            $current_visit['age'] = getPatientAge($current_visit['date_of_birth'] ?? null);
            $current_visit['is_active'] = !is_null($current_visit['consultation_started_at']) && is_null($current_visit['consultation_completed_at']);
            $current_visit['is_completed'] = !is_null($current_visit['consultation_completed_at']);
            
            $adm_sql = "
                SELECT 
                    a.id,
                    a.admission_number,
                    a.admission_type,
                    a.admitted_at,
                    a.expected_discharge_at,
                    a.primary_diagnosis,
                    a.admitting_diagnosis,
                    a.condition_status,
                    a.status,
                    a.admission_notes,
                    b.bed_number,
                    b.bed_type,
                    b.daily_rate,
                    w.name AS ward_name
                FROM admissions a
                LEFT JOIN beds b ON a.bed_id = b.id
                LEFT JOIN wards w ON b.ward_id = w.id
                WHERE a.visit_id = :visit_id 
                  AND a.status = 'admitted'
                  AND a.deleted_at IS NULL
                ORDER BY a.created_at DESC LIMIT 1
            ";
            $adm_stmt = $pdo->prepare($adm_sql);
            $adm_stmt->execute([':visit_id' => $active_visit_id]);
            $admission_data = $adm_stmt->fetch(PDO::FETCH_ASSOC);
            
            $ins_sql = "
                SELECT 
                    ip.name AS provider_name,
                    pip.policy_number,
                    pip.coverage_percentage,
                    pip.co_pay_percentage,
                    pip.effective_from,
                    pip.effective_to,
                    pip.is_primary
                FROM patient_insurance_profiles pip
                INNER JOIN insurance_providers ip ON pip.insurance_provider_id = ip.id
                WHERE pip.patient_id = :patient_id 
                  AND pip.status = 'active'
                  AND pip.deleted_at IS NULL
                ORDER BY pip.is_primary DESC, pip.effective_from DESC
                LIMIT 1
            ";
            $ins_stmt = $pdo->prepare($ins_sql);
            $ins_stmt->execute([':patient_id' => $active_patient_id]);
            $patient_insurance = $ins_stmt->fetch(PDO::FETCH_ASSOC);
            
            $fam_sql = "
                SELECT 
                    pr.relationship_type,
                    CONCAT(p.first_name, ' ', COALESCE(p.middle_name,''), ' ', p.last_name) AS related_patient_name,
                    p.mr_number,
                    p.phone,
                    pr.is_emergency_contact
                FROM patient_relationships pr
                INNER JOIN patients p ON pr.related_patient_id = p.id
                WHERE pr.primary_patient_id = :patient_id
                  AND pr.deleted_at IS NULL
                  AND p.deleted_at IS NULL
                LIMIT 5
            ";
            $fam_stmt = $pdo->prepare($fam_sql);
            $fam_stmt->execute([':patient_id' => $active_patient_id]);
            $family_history = $fam_stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if ($critical_alerts_count > 0) {
                $crit_sql = "
                    SELECT 
                        ltr.id,
                        ltr.test_name,
                        ltr.result_numeric,
                        ltr.result_text,
                        ltr.result_unit,
                        ltr.reference_range_high,
                        ltr.critical_flag_notes,
                        lo.order_number
                    FROM lab_test_results ltr
                    INNER JOIN lab_orders lo ON ltr.lab_order_id = lo.id
                    WHERE lo.visit_id = :visit_id
                      AND ltr.is_critical = 1
                      AND ltr.doctor_reviewed_at IS NULL
                      AND lo.deleted_at IS NULL
                    ORDER BY ltr.created_at DESC
                    LIMIT 5
                ";
                $crit_stmt = $pdo->prepare($crit_sql);
                $crit_stmt->execute([':visit_id' => $active_visit_id]);
                $critical_alerts = $crit_stmt->fetchAll(PDO::FETCH_ASSOC);
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Consultation Hub - Dr. <?php echo escapeHtml($doctor_name); ?> | HMS ERP</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
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
            --gray: #6b7280;
            --light-gray: #f3f4f6;
            --border-gray: #e5e7eb;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: linear-gradient(135deg, #f5f7fb 0%, #eef2f6 100%);
            overflow-x: hidden;
        }
        .content-page {
            margin-left: 260px;
            margin-top: 70px;
            padding: 24px 32px;
            transition: margin-left 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            min-height: calc(100vh - 70px);
        }
        body.erp-sidebar-collapsed .content-page { margin-left: 80px; }
        @media (max-width: 768px) { .content-page { margin-left: 0 !important; padding: 16px; } }
        
        .consultation-container {
            display: grid;
            grid-template-columns: 380px 1fr;
            gap: 24px;
        }
        @media (max-width: 992px) {
            .consultation-container { grid-template-columns: 1fr; }
        }
        
        .queue-sidebar {
            background: white;
            border-radius: 20px;
            border: 1px solid var(--border-gray);
            overflow: hidden;
            height: fit-content;
            position: sticky;
            top: 90px;
        }
        .queue-header {
            padding: 18px 20px;
            background: #f8fafc;
            border-bottom: 1px solid var(--border-gray);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
        }
        .queue-header h3 { margin: 0; font-size: 16px; font-weight: 700; }
        .queue-header .header-actions { display: flex; gap: 8px; flex-wrap: wrap; }
        
        .queue-tabs {
            display: flex;
            border-bottom: 1px solid var(--border-gray);
            background: #fafafa;
        }
        .queue-tab {
            flex: 1;
            padding: 10px 8px;
            text-align: center;
            cursor: pointer;
            font-size: 12px;
            font-weight: 600;
            color: var(--gray);
            border: none;
            background: transparent;
            transition: all 0.2s;
            position: relative;
        }
        .queue-tab:hover { background: #f0f0f0; }
        .queue-tab.active {
            color: var(--primary);
            background: white;
            border-bottom: 2px solid var(--primary);
        }
        .queue-tab .tab-count {
            display: inline-block;
            background: var(--light-gray);
            border-radius: 10px;
            padding: 0 6px;
            font-size: 10px;
            margin-left: 4px;
        }
        .queue-tab.active .tab-count { background: #e0e7ff; color: var(--primary); }
        .queue-tab .tab-badge {
            position: absolute;
            top: -4px;
            right: -4px;
            background: var(--danger);
            color: white;
            border-radius: 50%;
            width: 18px;
            height: 18px;
            font-size: 9px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .queue-list {
            max-height: calc(100vh - 280px);
            overflow-y: auto;
            padding: 4px 0;
        }
        .queue-list::-webkit-scrollbar { width: 5px; }
        .queue-list::-webkit-scrollbar-thumb { background: var(--border-gray); border-radius: 3px; }
        
        .patient-card {
            padding: 14px 16px;
            border-bottom: 1px solid var(--border-gray);
            cursor: pointer;
            transition: all 0.2s;
        }
        .patient-card:hover { background: var(--light-gray); }
        .patient-card.active {
            background: linear-gradient(90deg, rgba(67,97,238,0.08), transparent);
            border-left: 3px solid var(--primary);
        }
        .patient-card .patient-name { font-weight: 700; font-size: 15px; margin-bottom: 2px; }
        .patient-card .patient-mrn { font-size: 11px; color: var(--gray); }
        .patient-card .waiting-time { font-size: 10px; color: var(--gray); }
        .patient-card .patient-meta { font-size: 11px; color: var(--gray); }
        
        .status-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: 600;
        }
        .status-waiting { background: #fef3c7; color: #d97706; }
        .status-called { background: #dbeafe; color: #2563eb; }
        .status-inroom { background: #d1fae5; color: #059669; }
        .status-labready { background: #ede9fe; color: #7c3aed; }
        .status-completed { background: #d1fae5; color: #059669; }
        
        .patient-info-card {
            background: white;
            border-radius: 20px;
            border: 1px solid var(--border-gray);
            margin-bottom: 24px;
            padding: 20px;
        }
        .vitals-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(90px, 1fr));
            gap: 10px;
            margin-top: 16px;
        }
        .vital-item {
            background: var(--light-gray);
            border-radius: 16px;
            padding: 12px;
            text-align: center;
        }
        .vital-item .vital-label { font-size: 10px; color: var(--gray); text-transform: uppercase; }
        .vital-item .vital-value { font-size: 18px; font-weight: 700; }
        .vital-item .vital-unit { font-size: 10px; color: var(--gray); }
        .vital-critical { border: 2px solid var(--danger); animation: pulseBorder 1s ease-in-out infinite; }
        
        @keyframes pulseBorder {
            0%, 100% { border-color: var(--danger); }
            50% { border-color: #fca5a5; }
        }
        
        .soap-section {
            background: white;
            border-radius: 20px;
            border: 1px solid var(--border-gray);
            overflow: hidden;
            margin-bottom: 24px;
        }
        .soap-header {
            padding: 16px 24px;
            background: #f8fafc;
            border-bottom: 1px solid var(--border-gray);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
        }
        .soap-header h4 { margin: 0; font-size: 16px; font-weight: 700; }
        .soap-body { padding: 24px; }
        .form-group { margin-bottom: 20px; }
        .form-group label { font-weight: 600; font-size: 13px; display: block; margin-bottom: 6px; color: var(--dark); }
        .form-group textarea, .form-group input, .form-group select { 
            width: 100%; padding: 10px 14px; border: 1px solid var(--border-gray); border-radius: 12px; font-size: 14px; font-family: inherit;
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        .form-group textarea:focus, .form-group input:focus, .form-group select:focus { 
            outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(67,97,238,0.1); 
        }
        .form-group textarea { resize: vertical; min-height: 60px; }
        
        .cpoe-tabs {
            background: white;
            border-radius: 20px;
            border: 1px solid var(--border-gray);
            overflow: hidden;
            margin-top: 24px;
        }
        .nav-tabs {
            border-bottom: 1px solid var(--border-gray);
            padding: 0;
            background: #f8fafc;
            display: flex;
            flex-wrap: wrap;
        }
        .nav-tabs .nav-item { margin-bottom: -1px; list-style: none; }
        .nav-tabs .nav-link {
            border: none;
            padding: 12px 18px;
            font-weight: 600;
            color: var(--gray);
            transition: all 0.2s;
            cursor: pointer;
            display: block;
            text-decoration: none;
            background: transparent;
            font-size: 13px;
            position: relative;
        }
        .nav-tabs .nav-link:hover { color: var(--primary); }
        .nav-tabs .nav-link.active {
            color: var(--primary);
            border-bottom: 2px solid var(--primary);
            background: transparent;
        }
        .nav-tabs .nav-link .badge {
            font-size: 9px;
            padding: 2px 6px;
            margin-left: 4px;
        }
        .tab-content { padding: 24px; }
        .tab-pane { display: none; }
        .tab-pane.active { display: block; }
        
        .prescription-row {
            background: var(--light-gray);
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 16px;
            position: relative;
        }
        .remove-row {
            position: absolute;
            top: 8px;
            right: 8px;
            background: none;
            border: none;
            color: var(--danger);
            cursor: pointer;
            font-size: 16px;
        }
        .btn { padding: 10px 24px; border-radius: 12px; font-weight: 600; border: none; cursor: pointer; transition: all 0.2s; font-family: inherit; }
        .btn-primary { background: var(--primary); color: white; }
        .btn-primary:hover { background: var(--primary-dark); }
        .btn-success { background: var(--success); color: white; }
        .btn-success:hover { background: #059669; }
        .btn-danger { background: var(--danger); color: white; }
        .btn-danger:hover { background: #dc2626; }
        .btn-warning { background: var(--warning); color: var(--dark); }
        .btn-warning:hover { background: #d97706; color: white; }
        .btn-outline { background: white; border: 1px solid var(--border-gray); color: var(--dark); }
        .btn-outline:hover { border-color: var(--primary); color: var(--primary); }
        .btn-sm { padding: 6px 14px; font-size: 12px; }
        
        .lab-tests-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 10px;
            max-height: 300px;
            overflow-y: auto;
            padding: 12px;
            background: var(--light-gray);
            border-radius: 12px;
            margin-top: 8px;
        }
        .lab-test-checkbox {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px 12px;
            background: white;
            border-radius: 10px;
            cursor: pointer;
            transition: all 0.2s;
        }
        .lab-test-checkbox:hover { background: #e0e7ff; transform: translateX(2px); }
        .lab-test-checkbox input { width: 18px; height: 18px; cursor: pointer; margin: 0; }
        .lab-test-checkbox label { cursor: pointer; margin: 0; flex: 1; font-size: 13px; font-weight: 500; }
        .lab-test-checkbox .test-price { font-size: 11px; color: var(--gray); font-weight: normal; }
        
        .action-buttons { display: flex; gap: 12px; justify-content: flex-end; margin-top: 24px; flex-wrap: wrap; }
        
        .order-card {
            background: var(--light-gray);
            border-radius: 12px;
            padding: 14px 16px;
            margin-bottom: 10px;
        }
        .order-card .critical-badge {
            background: var(--danger);
            color: white;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 10px;
            font-weight: 600;
            animation: pulse 1s ease-in-out infinite;
        }
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.6; }
        }
        
        .scrollable-section {
            max-height: 300px;
            overflow-y: auto;
        }
        .scrollable-section::-webkit-scrollbar { width: 6px; }
        .scrollable-section::-webkit-scrollbar-thumb { background: var(--border-gray); border-radius: 3px; }
        
        .result-item {
            padding: 10px 12px;
            border-radius: 10px;
            margin-bottom: 8px;
            border-left: 4px solid var(--gray);
        }
        .result-item.normal { border-left-color: var(--success); background: #f0fdf4; }
        .result-item.abnormal { border-left-color: var(--warning); background: #fffbeb; }
        .result-item.critical { border-left-color: var(--danger); background: #fef2f2; animation: pulseBorder 1.5s ease-in-out infinite; }
        .result-item .result-label { font-weight: 600; font-size: 14px; }
        .result-item .result-value { font-size: 16px; font-weight: 700; }
        .result-item .result-range { font-size: 12px; color: var(--gray); }
        
        .loading-overlay { 
            position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(255,255,255,0.7); 
            z-index: 2000; display: none; align-items: center; justify-content: center; 
        }
        .loading-overlay.active { display: flex; }
        .spinner { width: 40px; height: 40px; border: 3px solid var(--light-gray); border-top-color: var(--primary); border-radius: 50%; animation: spin 0.8s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }
        
        .toast-notification { 
            position: fixed; bottom: 20px; right: 20px; background: white; border-radius: 12px; 
            padding: 12px 20px; box-shadow: 0 10px 25px rgba(0,0,0,0.1); z-index: 1100; 
            animation: slideIn 0.3s ease; display: flex; align-items: center; gap: 12px; 
            border-left: 4px solid var(--success); 
        }
        .toast-error { border-left-color: var(--danger); }
        @keyframes slideIn { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
        
        .emergency-alert {
            animation: blinkRed 0.8s ease-in-out infinite;
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
        }
        @keyframes blinkRed {
            0% { background-color: #fee2e2; color: #ef476f; }
            50% { background-color: #ef476f; color: white; }
            100% { background-color: #fee2e2; color: #ef476f; }
        }
        
        .consultation-active-badge {
            background: var(--success);
            color: white;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .lab-results-ready-badge {
            background: #ede9fe;
            color: #7c3aed;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            animation: pulse 1.5s ease-in-out infinite;
        }
        
        .insurance-badge {
            background: #e0e7ff;
            color: #4361ee;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
        }
        
        .admitted-badge {
            background: #dbeafe;
            color: #2563eb;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: var(--gray);
        }
        .empty-state i { font-size: 48px; margin-bottom: 16px; opacity: 0.3; }
        .empty-state h5 { font-weight: 600; }
        
        .quick-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 16px;
        }

        /* Lab Results Styling */
        .lab-order-card {
            background: white;
            border-radius: 12px;
            border: 1px solid #e5e7eb;
            overflow: hidden;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            transition: box-shadow 0.2s;
            margin-bottom: 16px;
        }
        .lab-order-card:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
        }
        .lab-order-header {
            background: #f8fafc;
            border-bottom: 1px solid #e5e7eb;
            cursor: pointer;
        }
        .lab-order-header:hover {
            background: #f0f4ff;
        }
        .result-card {
            background: white;
            transition: background-color 0.2s;
        }
        .result-card:hover {
            background: #fafafa;
        }
        .result-card.critical {
            background: #fef2f2;
            border-color: #ef476f !important;
            animation: criticalPulse 2s ease-in-out infinite;
        }
        .result-card.abnormal {
            background: #fffbeb;
            border-color: #f9c74f !important;
        }
        @keyframes criticalPulse {
            0%, 100% { background: #fef2f2; }
            50% { background: #fee2e2; }
        }
        .critical-notes {
            border-left: 3px solid #ef476f;
        }
        .critical-blink {
            animation: blinkRed 1s ease-in-out infinite;
        }
        .lab-stats-summary {
            display: flex;
            gap: 16px;
            flex-wrap: wrap;
            padding: 12px 16px;
            background: #f8fafc;
            border-radius: 8px;
            margin-bottom: 16px;
        }
        .lab-stats-summary .stat-item {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 13px;
        }
        .lab-stats-summary .stat-item .count {
            font-weight: 700;
            font-size: 16px;
        }
        .lab-stats-summary .stat-item.critical .count { color: #ef476f; }
        .lab-stats-summary .stat-item.unread .count { color: #4cc9f0; }
        .lab-stats-summary .stat-item.abnormal .count { color: #f9c74f; }
        .lab-stats-summary .stat-item.reviewed .count { color: #06d6a0; }
        .lab-stats-summary .stat-item.amended .count { color: #f59e0b; }

        /* Panel styles */
        .panel-card {
            background: white;
            border-radius: 12px;
            border: 1px solid var(--border-gray);
            padding: 12px 16px;
            margin-bottom: 10px;
            cursor: pointer;
            transition: all 0.2s;
        }
        .panel-card:hover {
            border-color: var(--primary);
            box-shadow: 0 2px 8px rgba(67,97,238,0.1);
        }
        .panel-card.selected {
            border-color: var(--primary);
            background: #f0f4ff;
        }
        .panel-card .panel-tests {
            font-size: 12px;
            color: var(--gray);
            margin-top: 4px;
        }

        /* History pagination */
        .pagination-controls {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 12px;
        }
        .pagination-controls .page-info {
            font-size: 13px;
            color: var(--gray);
        }
        .pagination-controls .btn-group {
            display: flex;
            gap: 4px;
        }

        @media (max-width: 768px) {
            .result-card .row > div {
                margin-bottom: 8px;
            }
            .lab-order-header {
                flex-direction: column;
                align-items: flex-start !important;
            }
            .lab-order-header .btn {
                margin-top: 8px;
            }
            .lab-stats-summary {
                gap: 8px;
                padding: 8px 12px;
            }
            .lab-stats-summary .stat-item {
                font-size: 11px;
            }
            .queue-tabs {
                overflow-x: auto;
            }
            .queue-tab {
                min-width: 70px;
                font-size: 10px;
                padding: 8px 6px;
            }
        }
    </style>
</head>
<body>
<div id="wrapper">
    <?php include('assets/inc/nav.php'); ?>
    <?php include('assets/inc/sidebar.php'); ?>

    <div class="content-page">
        <div class="consultation-container">
            <!-- LEFT: Patient Queue Sidebar -->
            <div class="queue-sidebar">
                <div class="queue-header">
                    <h3><i class="fas fa-users me-2"></i> My Queue</h3>
                    <div class="header-actions">
                        <button class="btn btn-outline btn-sm" onclick="refreshQueue()" title="Refresh Queue">
                            <i class="fas fa-sync-alt"></i>
                        </button>
                        <a href="dashboard.php" class="btn btn-outline btn-sm">
                            <i class="fas fa-arrow-left"></i> Dashboard
                        </a>
                    </div>
                </div>
                
                <div class="queue-tabs" id="queueTabs">
                    <button class="queue-tab active" data-tab="pending" onclick="switchQueueTab('pending')">
                        <i class="fas fa-clock"></i> Pending
                        <span class="tab-count" id="pendingCount">0</span>
                    </button>
                    <button class="queue-tab" data-tab="lab_pending" onclick="switchQueueTab('lab_pending')">
                        <i class="fas fa-hourglass-half"></i> Lab Pending
                        <span class="tab-count" id="labPendingCount">0</span>
                    </button>
                    <button class="queue-tab" data-tab="lab_ready" onclick="switchQueueTab('lab_ready')">
                        <i class="fas fa-flask"></i> Lab Ready
                        <span class="tab-count" id="labReadyCount">0</span>
                        <span class="tab-badge" id="labReadyBadge" style="display:none;">!</span>
                    </button>
                    <button class="queue-tab" data-tab="completed" onclick="switchQueueTab('completed')">
                        <i class="fas fa-check-circle"></i> Completed
                        <span class="tab-count" id="completedCount">0</span>
                    </button>
                </div>
                
                <div class="queue-list" id="queueList">
                    <div class="text-center p-4 text-muted">
                        <div class="spinner-border spinner-border-sm text-primary" role="status"></div>
                        <span class="ms-2">Loading queue...</span>
                    </div>
                </div>
            </div>

            <!-- RIGHT: Consultation Workspace -->
            <div>
                <?php if ($current_visit): ?>
                    <!-- Critical Alerts Banner -->
                    <?php if (!empty($critical_alerts)): ?>
                    <div class="critical-alert-banner">
                        <div class="d-flex align-items-start">
                            <div class="alert-icon"><i class="fas fa-exclamation-triangle"></i></div>
                            <div>
                                <strong class="text-danger">CRITICAL LAB RESULTS</strong>
                                <div class="small mt-1">
                                    <?php foreach ($critical_alerts as $alert): ?>
                                    <div class="d-flex justify-content-between align-items-center mt-1">
                                        <span><?php echo escapeHtml($alert['test_name']); ?>: 
                                            <strong><?php echo escapeHtml($alert['result_numeric'] ?? $alert['result_text'] ?? 'N/A'); ?></strong>
                                            <?php echo escapeHtml($alert['result_unit'] ?? ''); ?>
                                            (Reference: <?php echo escapeHtml($alert['reference_range_high'] ?? 'N/A'); ?>)
                                        </span>
                                        <button class="btn btn-danger btn-sm ms-2" onclick="markCriticalAlertRead(<?php echo $alert['id']; ?>)">
                                            <i class="fas fa-check"></i> Acknowledge
                                        </button>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Patient Info Card -->
                    <div class="patient-info-card">
                        <div class="d-flex justify-content-between align-items-start flex-wrap">
                            <div>
                                <h2 class="mb-1"><?php echo escapeHtml($current_visit['patient_name']); ?>
                                    <?php if ($patient_insurance): ?>
                                    <span class="insurance-badge ms-2"><i class="fas fa-shield-alt"></i> <?php echo escapeHtml($patient_insurance['provider_name']); ?></span>
                                    <?php endif; ?>
                                    <?php if ($admission_data): ?>
                                    <span class="admitted-badge ms-2"><i class="fas fa-hospital"></i> Admitted</span>
                                    <?php endif; ?>
                                </h2>
                                <div class="text-muted">MRN: <?php echo escapeHtml($current_visit['mr_number']); ?> | Age: <?php echo $current_visit['age']; ?> | Gender: <?php echo ucfirst($current_visit['gender']); ?></div>
                                <div class="text-muted small"><?php echo escapeHtml($current_visit['phone'] ?? ''); ?> <?php echo $current_visit['email'] ? '| ' . escapeHtml($current_visit['email']) : ''; ?></div>
                                <div class="mt-2">
                                    <?php if ($current_visit['is_active'] && !$current_visit['is_completed']): ?>
                                        <span class="consultation-active-badge"><i class="fas fa-play-circle"></i> In Progress</span>
                                    <?php elseif ($current_visit['is_completed']): ?>
                                        <span class="status-badge status-completed"><i class="fas fa-check-circle"></i> Completed</span>
                                    <?php else: ?>
                                        <span class="status-badge status-waiting"><i class="fas fa-hourglass-half"></i> Waiting to Start</span>
                                    <?php endif; ?>
                                    
                                    <?php if ($current_visit['appointment_priority'] === 'stat'): ?>
                                        <span class="ms-2 emergency-alert"><i class="fas fa-exclamation-triangle"></i> STAT</span>
                                    <?php elseif ($current_visit['appointment_priority'] === 'urgent'): ?>
                                        <span class="ms-2 badge" style="background:#ffedd5; color:#ea580c;">Urgent</span>
                                    <?php endif; ?>
                                    
                                    <?php if ($lab_results_ready): ?>
                                        <span class="ms-2 lab-results-ready-badge"><i class="fas fa-flask"></i> Lab Results Ready</span>
                                    <?php endif; ?>
                                    
                                    <?php if ($pending_lab_count > 0): ?>
                                        <span class="ms-2 badge" style="background:#fef3c7; color:#d97706;"><i class="fas fa-hourglass-half"></i> <?php echo $pending_lab_count; ?> Lab Pending</span>
                                    <?php endif; ?>
                                    
                                    <?php if ($pending_rx_count > 0): ?>
                                        <span class="ms-2 badge" style="background:#fef3c7; color:#d97706;"><i class="fas fa-prescription"></i> <?php echo $pending_rx_count; ?> Rx Pending</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="mt-2 mt-md-0 d-flex flex-wrap gap-2">
                                <?php if (!$current_visit['is_active'] && !$current_visit['is_completed']): ?>
                                    <button class="btn btn-primary" id="startConsultBtn"><i class="fas fa-play"></i> Start</button>
                                <?php elseif ($current_visit['is_active'] && !$current_visit['is_completed']): ?>
                                    <button class="btn btn-success" id="completeConsultBtn"><i class="fas fa-check-circle"></i> Complete</button>
                                <?php endif; ?>
                                <?php if (!$admission_data && !$current_visit['is_completed']): ?>
                                    <button class="btn btn-outline btn-sm" onclick="openAdmitModal()"><i class="fas fa-hospital"></i> Admit</button>
                                <?php endif; ?>
                                <?php if ($admission_data && !$current_visit['is_completed']): ?>
                                    <button class="btn btn-outline btn-sm" onclick="openDischargeSummary()"><i class="fas fa-file-medical-alt"></i> Discharge</button>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <!-- Vitals Grid -->
                        <div class="vitals-grid">
                            <?php
                            $vitals = [
                                ['label' => 'BP', 'value' => $current_visit['bp'], 'unit' => 'mmHg'],
                                ['label' => 'HR', 'value' => $current_visit['heart_rate'] ?: '--', 'unit' => 'bpm'],
                                ['label' => 'Temp', 'value' => $current_visit['temperature_celsius'] ?: '--', 'unit' => '°C'],
                                ['label' => 'RR', 'value' => $current_visit['respiratory_rate'] ?: '--', 'unit' => '/min'],
                                ['label' => 'SpO₂', 'value' => $current_visit['oxygen_saturation'] ?: '--', 'unit' => '%'],
                                ['label' => 'Weight', 'value' => $current_visit['weight_kg'] ?: '--', 'unit' => 'kg'],
                                ['label' => 'Pain', 'value' => $current_visit['pain_score'] ?: '--', 'unit' => '/10'],
                                ['label' => 'BMI', 'value' => $current_visit['bmi'] ?: '--', 'unit' => '']
                            ];
                            foreach ($vitals as $v): ?>
                            <div class="vital-item <?php echo ($v['label'] === 'SpO₂' && $current_visit['oxygen_saturation'] < 95) ? 'vital-critical' : ''; ?>">
                                <div class="vital-label"><?php echo $v['label']; ?></div>
                                <div class="vital-value"><?php echo $v['value']; ?></div>
                                <div class="vital-unit"><?php echo $v['unit']; ?></div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <!-- Chief Complaint & Allergies -->
                        <div class="row mt-3 g-2">
                            <div class="col-md-6">
                                <div class="p-2 bg-light rounded"><strong><i class="fas fa-comment-dots"></i> Chief Complaint:</strong> <?php echo escapeHtml($current_visit['chief_complaint'] ?: 'Not recorded'); ?></div>
                            </div>
                            <div class="col-md-6">
                                <div class="p-2 bg-light rounded"><strong><i class="fas fa-allergies"></i> Allergies:</strong> <?php echo escapeHtml($current_visit['allergies'] ?: 'None reported'); ?></div>
                            </div>
                        </div>
                        <?php if ($current_visit['current_medications']): ?>
                        <div class="mt-2">
                            <div class="p-2 bg-light rounded"><strong><i class="fas fa-pills"></i> Current Medications:</strong> <?php echo escapeHtml($current_visit['current_medications']); ?></div>
                        </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($family_history)): ?>
                        <div class="mt-2">
                            <div class="p-2 bg-light rounded">
                                <strong><i class="fas fa-users"></i> Family:</strong>
                                <?php foreach ($family_history as $fam): ?>
                                <span class="badge bg-secondary me-1"><?php echo escapeHtml($fam['relationship_type']); ?>: <?php echo escapeHtml($fam['related_patient_name']); ?></span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <?php if ($admission_data): ?>
                        <div class="mt-2">
                            <div class="p-2 bg-light rounded">
                                <strong><i class="fas fa-hospital"></i> Admission:</strong> 
                                #<?php echo escapeHtml($admission_data['admission_number']); ?> 
                                | Admitted: <?php echo formatDate($admission_data['admitted_at']); ?>
                                | Diagnosis: <?php echo escapeHtml($admission_data['primary_diagnosis']); ?>
                                | Status: <span class="badge bg-<?php echo $admission_data['condition_status'] === 'critical' ? 'danger' : ($admission_data['condition_status'] === 'serious' ? 'warning' : 'info'); ?>">
                                    <?php echo ucfirst($admission_data['condition_status']); ?>
                                </span>
                                <?php if ($admission_data['bed_number']): ?>
                                | Bed: <?php echo escapeHtml($admission_data['bed_number']); ?> (<?php echo escapeHtml($admission_data['ward_name'] ?? 'N/A'); ?>)
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- SOAP Editor -->
                    <div class="soap-section">
                        <div class="soap-header">
                            <h4><i class="fas fa-notes-medical"></i> Consultation Notes (SOAP)</h4>
                            <span class="text-muted small" id="soapLastSaved"></span>
                        </div>
                        <div class="soap-body">
                            <form id="soapForm">
                                <input type="hidden" name="visit_id" value="<?php echo $current_visit['visit_id']; ?>">
                                <input type="hidden" name="patient_id" value="<?php echo $current_visit['patient_id']; ?>">
                                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                
                                <div class="form-group">
                                    <label>Subjective (S) - Chief Complaint & HPI</label>
                                    <textarea class="form-control" id="subjective" name="subjective" rows="3" placeholder="Patient's chief complaint, history of present illness, review of systems..."><?php echo escapeHtml($current_visit['chief_complaint'] ?? ''); ?></textarea>
                                </div>
                                <div class="form-group">
                                    <label>Objective (O) - Physical Examination</label>
                                    <textarea class="form-control" id="objective" name="objective" rows="3" placeholder="Physical examination findings, vital signs summary..."></textarea>
                                </div>
                                <div class="row g-2">
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label>Primary Diagnosis (ICD-10)</label>
                                            <input type="text" class="form-control" id="primary_icd10" name="primary_icd10" placeholder="e.g., I10, J45.901">
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label>Provisional Diagnosis</label>
                                            <input type="text" class="form-control" id="diagnosis" name="diagnosis" placeholder="Provisional diagnosis">
                                        </div>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label>Assessment (A)</label>
                                    <textarea class="form-control" id="assessment" name="assessment" rows="4" placeholder="Differential diagnosis, clinical reasoning..."></textarea>
                                </div>
                                <div class="form-group">
                                    <label>Plan (P)</label>
                                    <textarea class="form-control" id="plan" name="plan" rows="4" placeholder="Treatment plan, medications, follow-up, referrals..."></textarea>
                                </div>
                                <div class="action-buttons">
                                    <button type="button" class="btn btn-outline" onclick="resetSoapForm()">Reset</button>
                                    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Notes</button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- CPOE Tabs -->
                    <div class="cpoe-tabs">
                        <ul class="nav nav-tabs" id="cpoeTabs" role="tablist">
                            <li class="nav-item" role="presentation">
                                <button class="nav-link active" id="prescription-tab" data-tab="prescription" type="button" role="tab">
                                    <i class="fas fa-prescription-bottle"></i> E-Prescription
                                </button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="lab-tab" data-tab="lab" type="button" role="tab">
                                    <i class="fas fa-microscope"></i> Lab Orders
                                </button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="radiology-tab" data-tab="radiology" type="button" role="tab">
                                    <i class="fas fa-x-ray"></i> Radiology
                                </button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="procedure-tab" data-tab="procedure" type="button" role="tab">
                                    <i class="fas fa-syringe"></i> Procedures
                                </button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="results-tab" data-tab="results" type="button" role="tab">
                                    <i class="fas fa-flask"></i> Lab Results
                                    <?php if ($lab_results_ready): ?><span class="badge bg-danger">!</span><?php endif; ?>
                                    <?php if ($critical_alerts_count > 0): ?><span class="badge bg-danger">CRITICAL</span><?php endif; ?>
                                </button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="history-tab" data-tab="history" type="button" role="tab">
                                    <i class="fas fa-history"></i> History
                                </button>
                            </li>
                        </ul>
                        <div class="tab-content" id="cpoeTabContent">
                            <!-- Prescription Tab -->
                            <div class="tab-pane active" id="prescription" role="tabpanel">
                                <form id="prescriptionForm">
                                    <input type="hidden" name="visit_id" value="<?php echo $current_visit['visit_id']; ?>">
                                    <input type="hidden" name="patient_id" value="<?php echo $current_visit['patient_id']; ?>">
                                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                    
                                    <div class="form-group">
                                        <label>Clinical Notes</label>
                                        <textarea class="form-control" id="rx_clinical_notes" name="clinical_notes" rows="2" placeholder="Prescribing notes, indications..."></textarea>
                                    </div>
                                    
                                    <div id="prescriptionItemsContainer">
                                        <div class="prescription-row" data-row="0">
                                            <button type="button" class="remove-row" onclick="removePrescriptionRow(this)"><i class="fas fa-times"></i></button>
                                            <div class="row g-2">
                                                <div class="col-md-5">
                                                    <div class="form-group">
                                                        <label>Medication <span class="text-danger">*</span></label>
                                                        <select class="form-control drug-select" name="drug[]" data-row="0" style="width: 100%;"></select>
                                                    </div>
                                                </div>
                                                <div class="col-md-2">
                                                    <div class="form-group">
                                                        <label>Dosage <span class="text-danger">*</span></label>
                                                        <input type="text" class="form-control" name="dosage[]" placeholder="e.g., 500mg">
                                                    </div>
                                                </div>
                                                <div class="col-md-2">
                                                    <div class="form-group">
                                                        <label>Frequency <span class="text-danger">*</span></label>
                                                        <input type="text" class="form-control" name="frequency[]" placeholder="e.g., BID, TID">
                                                    </div>
                                                </div>
                                                <div class="col-md-1">
                                                    <div class="form-group">
                                                        <label>Days</label>
                                                        <input type="number" class="form-control" name="duration[]" placeholder="Days" value="7" min="1">
                                                    </div>
                                                </div>
                                                <div class="col-md-2">
                                                    <div class="form-group">
                                                        <label>Meal Relation</label>
                                                        <select class="form-control" name="meal_relation[]">
                                                            <option value="any">Any</option>
                                                            <option value="before_meal">Before Meal</option>
                                                            <option value="after_meal">After Meal</option>
                                                            <option value="with_meal">With Meal</option>
                                                            <option value="empty_stomach">Empty Stomach</option>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div class="col-md-12">
                                                    <div class="form-group">
                                                        <label>Instructions</label>
                                                        <input type="text" class="form-control" name="instructions[]" placeholder="Special instructions">
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <button type="button" class="btn btn-outline btn-sm add-row-btn" onclick="addPrescriptionRow()"><i class="fas fa-plus"></i> Add Medication</button>
                                    
                                    <div class="action-buttons mt-3">
                                        <button type="submit" class="btn btn-primary"><i class="fas fa-prescription"></i> Send to Pharmacy</button>
                                    </div>
                                    <div class="small text-muted mt-2"><i class="fas fa-info-circle"></i> Prescription will be sent to pharmacy after payment verification</div>
                                </form>
                            </div>
                            
                            <!-- LAB ORDERS TAB -->
                            <div class="tab-pane" id="lab" role="tabpanel">
                                <div class="card bg-light border-0 mb-4">
                                    <div class="card-body">
                                        <h6 class="card-title mb-3"><i class="fas fa-plus-circle text-primary"></i> New Lab Order</h6>
                                        
                                        <!-- Panel Selection -->
                                        <div class="mb-3">
                                            <label class="fw-semibold small">Test Panels (Select a panel to add all tests)</label>
                                            <div id="panelContainer" class="d-flex flex-wrap gap-2 mt-2">
                                                <?php foreach ($lab_panels as $panel): ?>
                                                <div class="panel-card" data-panel-id="<?php echo $panel['id']; ?>" onclick="togglePanel(<?php echo $panel['id']; ?>)">
                                                    <div class="d-flex justify-content-between align-items-center">
                                                        <span class="fw-semibold"><?php echo escapeHtml($panel['panel_name']); ?></span>
                                                        <span class="badge bg-secondary"><?php echo $panel['test_count']; ?> tests</span>
                                                    </div>
                                                    <div class="panel-tests">
                                                        <?php 
                                                        $test_names = array_column($panel['tests'] ?? [], 'test_name');
                                                        echo escapeHtml(implode(', ', array_slice($test_names, 0, 3)));
                                                        if (count($test_names) > 3) echo ' + ' . (count($test_names) - 3) . ' more';
                                                        ?>
                                                    </div>
                                                </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                        
                                        <form id="labOrderForm">
                                            <input type="hidden" name="visit_id" value="<?php echo $current_visit['visit_id'] ?? 0; ?>">
                                            <input type="hidden" name="patient_id" value="<?php echo $current_visit['patient_id'] ?? 0; ?>">
                                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                            
                                            <div class="row g-3">
                                                <div class="col-md-3">
                                                    <div class="form-group">
                                                        <label class="fw-semibold small">Priority <span class="text-danger">*</span></label>
                                                        <select class="form-control form-select" name="priority" id="labPriority">
                                                            <option value="routine">🟢 Routine</option>
                                                            <option value="urgent">🟡 Urgent</option>
                                                            <option value="stat">🔴 STAT</option>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div class="col-md-9">
                                                    <div class="form-group">
                                                        <label class="fw-semibold small">Clinical Indication</label>
                                                        <input type="text" class="form-control" id="labClinicalIndication" 
                                                            name="clinical_indication" 
                                                            placeholder="e.g., Rule out infection, Monitor diabetes, Pre-op screening">
                                                    </div>
                                                </div>
                                            </div>
                                            
                                            <div class="form-group mt-3">
                                                <div class="d-flex justify-content-between align-items-center mb-2">
                                                    <label class="fw-semibold small mb-0">Select Tests <span class="text-danger">*</span></label>
                                                    <div>
                                                        <span class="badge bg-info me-2" id="selectedTestCount">0 selected</span>
                                                        <span class="badge bg-secondary" id="totalTestPrice">0.00 ETB</span>
                                                    </div>
                                                </div>
                                                
                                                <div class="row g-2 mb-2">
                                                    <div class="col-md-6">
                                                        <input type="text" class="form-control form-control-sm" id="labTestSearch" 
                                                            placeholder="🔍 Search tests by name, category...">
                                                    </div>
                                                    <div class="col-md-3">
                                                        <select class="form-control form-control-sm" id="labTestCategoryFilter">
                                                            <option value="">All Categories</option>
                                                            <option value="hematology">Hematology</option>
                                                            <option value="biochemistry">Biochemistry</option>
                                                            <option value="microbiology">Microbiology</option>
                                                            <option value="pathology">Pathology</option>
                                                            <option value="immunology">Immunology</option>
                                                            <option value="serology">Serology</option>
                                                            <option value="urinalysis">Urinalysis</option>
                                                            <option value="hormone">Hormone</option>
                                                            <option value="toxicology">Toxicology</option>
                                                        </select>
                                                    </div>
                                                    <div class="col-md-3 text-end">
                                                        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="selectAllLabs()">
                                                            <i class="fas fa-check-double"></i> Select All
                                                        </button>
                                                        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="deselectAllLabs()">
                                                            <i class="fas fa-times"></i> Clear
                                                        </button>
                                                    </div>
                                                </div>
                                                
                                                <div id="labTestsCheckboxContainer" class="lab-tests-grid border rounded p-2" style="max-height:250px; overflow-y:auto;">
                                                    <?php foreach ($lab_tests as $test): ?>
                                                    <div class="lab-test-checkbox" data-category="<?php echo escapeHtml($test['test_category'] ?? 'other'); ?>" data-name="<?php echo strtolower(escapeHtml($test['test_name'])); ?>">
                                                        <input type="checkbox" name="selected_tests[]" value="<?php echo $test['id']; ?>" 
                                                            id="test_<?php echo $test['id']; ?>" 
                                                            data-price="<?php echo (float)($test['price'] ?? 0); ?>"
                                                            data-name="<?php echo escapeHtml($test['test_name']); ?>"
                                                            onchange="updateLabSelection()">
                                                        <label for="test_<?php echo $test['id']; ?>" class="d-flex justify-content-between w-100">
                                                            <span>
                                                                <strong><?php echo escapeHtml($test['test_name']); ?></strong>
                                                                <?php if ($test['sample_type']): ?>
                                                                <span class="badge bg-light text-dark ms-1" style="font-size:9px;"><?php echo escapeHtml($test['sample_type']); ?></span>
                                                                <?php endif; ?>
                                                                <?php if ($test['turnaround_time_hours']): ?>
                                                                <span class="badge bg-light text-dark ms-1" style="font-size:9px;">⏱ <?php echo $test['turnaround_time_hours']; ?>h</span>
                                                                <?php endif; ?>
                                                            </span>
                                                            <span class="test-price">
                                                                <?php if ($test['price'] > 0): ?>
                                                                <?php echo number_format($test['price'], 2); ?> ETB
                                                                <?php else: ?>
                                                                <span class="text-muted">Price N/A</span>
                                                                <?php endif; ?>
                                                            </span>
                                                        </label>
                                                    </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                            
                                            <div class="d-flex justify-content-end gap-2 mt-3">
                                                <button type="reset" class="btn btn-outline-secondary">
                                                    <i class="fas fa-undo"></i> Reset
                                                </button>
                                                <button type="submit" class="btn btn-primary" id="submitLabOrder">
                                                    <i class="fas fa-paper-plane"></i> Send to Laboratory
                                                </button>
                                            </div>
                                            <div class="small text-muted mt-2">
                                                <i class="fas fa-info-circle"></i> Lab orders will be processed after payment verification
                                            </div>
                                        </form>
                                    </div>
                                </div>
                                
                                <div class="mt-4">
                                    <div class="d-flex justify-content-between align-items-center mb-3">
                                        <h6 class="mb-0"><i class="fas fa-flask me-2"></i> Lab Order Status</h6>
                                        <button class="btn btn-outline-secondary btn-sm" onclick="fetchLabStatus()">
                                            <i class="fas fa-sync-alt"></i> Refresh
                                        </button>
                                    </div>
                                    <div id="labStatusList" class="scrollable-section" style="max-height:350px;">
                                        <div class="text-center py-3 text-muted">
                                            <div class="spinner-border spinner-border-sm text-primary" role="status"></div>
                                            <span class="ms-2">Loading lab orders...</span>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="mt-4">
                                    <div class="d-flex justify-content-between align-items-center mb-3">
                                        <h6 class="mb-0"><i class="fas fa-vial me-2"></i> Specimen Tracking</h6>
                                    </div>
                                    <div id="specimenList" class="scrollable-section" style="max-height:200px;">
                                        <div class="text-center py-2 text-muted small">Loading specimens...</div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Radiology Tab -->
                            <div class="tab-pane" id="radiology" role="tabpanel">
                                <div class="card bg-light border-0 mb-4">
                                    <div class="card-body">
                                        <h6 class="card-title mb-3"><i class="fas fa-plus-circle text-primary"></i> New Radiology Order</h6>
                                        <form id="radiologyForm">
                                            <input type="hidden" name="visit_id" value="<?php echo $current_visit['visit_id']; ?>">
                                            <input type="hidden" name="patient_id" value="<?php echo $current_visit['patient_id']; ?>">
                                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                            
                                            <div class="form-group">
                                                <label>Select Services <span class="text-danger">*</span> (Select multiple)</label>
                                                <select class="form-control" name="service_ids[]" multiple required style="height:120px;">
                                                    <?php foreach ($radiology_services as $svc): ?>
                                                    <option value="<?php echo $svc['id']; ?>">
                                                        <?php echo escapeHtml($svc['service_name']); ?> 
                                                        (<?php echo escapeHtml($svc['category']); ?>) 
                                                        - <?php echo number_format($svc['standard_price'], 2); ?> ETB
                                                    </option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <small class="text-muted">Hold Ctrl/Cmd to select multiple services</small>
                                            </div>
                                            
                                            <div class="row g-2">
                                                <div class="col-md-6">
                                                    <div class="form-group">
                                                        <label>Modality</label>
                                                        <select class="form-control" name="modality">
                                                            <option value="">Select Modality</option>
                                                            <option value="xray">X-Ray</option>
                                                            <option value="ct">CT Scan</option>
                                                            <option value="mri">MRI</option>
                                                            <option value="ultrasound">Ultrasound</option>
                                                            <option value="mammography">Mammography</option>
                                                            <option value="fluoroscopy">Fluoroscopy</option>
                                                            <option value="angiography">Angiography</option>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div class="col-md-6">
                                                    <div class="form-group">
                                                        <label>Body Part</label>
                                                        <input type="text" class="form-control" name="body_part" placeholder="e.g., Chest, Abdomen, Brain">
                                                    </div>
                                                </div>
                                            </div>
                                            
                                            <div class="form-group">
                                                <label>Priority</label>
                                                <select class="form-control" name="priority">
                                                    <option value="routine">Routine</option>
                                                    <option value="urgent">Urgent</option>
                                                    <option value="stat">STAT</option>
                                                </select>
                                            </div>
                                            
                                            <div class="form-group">
                                                <label>Clinical History</label>
                                                <textarea class="form-control" name="clinical_history" rows="2" placeholder="Clinical history and indication..."></textarea>
                                            </div>
                                            
                                            <div class="action-buttons mt-3">
                                                <button type="submit" class="btn btn-primary"><i class="fas fa-x-ray"></i> Send to Radiology</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                                
                                <div class="mt-4">
                                    <h6><i class="fas fa-x-ray me-2"></i> Radiology Order Status</h6>
                                    <div id="radiologyStatusList" class="scrollable-section">
                                        <div class="text-muted small text-center">Loading radiology orders...</div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Procedures Tab -->
                            <div class="tab-pane" id="procedure" role="tabpanel">
                                <form id="procedureForm">
                                    <input type="hidden" name="visit_id" value="<?php echo $current_visit['visit_id']; ?>">
                                    <input type="hidden" name="patient_id" value="<?php echo $current_visit['patient_id']; ?>">
                                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                    
                                    <div class="form-group">
                                        <label>Procedure Name <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" name="procedure_name" placeholder="e.g., Appendectomy, Endoscopy" required>
                                    </div>
                                    
                                    <div class="row g-2">
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label>Priority</label>
                                                <select class="form-control" name="priority">
                                                    <option value="routine">Routine</option>
                                                    <option value="urgent">Urgent</option>
                                                    <option value="emergency">Emergency</option>
                                                    <option value="elective">Elective</option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label>Scheduled Date</label>
                                                <input type="date" class="form-control" name="scheduled_date">
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label>Clinical Notes</label>
                                        <textarea class="form-control" name="clinical_notes" rows="2" placeholder="Procedure details and notes..."></textarea>
                                    </div>
                                    
                                    <div class="action-buttons mt-3">
                                        <button type="submit" class="btn btn-primary"><i class="fas fa-syringe"></i> Schedule Procedure</button>
                                    </div>
                                </form>
                                
                                <div class="mt-4">
                                    <h6><i class="fas fa-syringe me-2"></i> Procedure Order Status</h6>
                                    <div id="procedureStatusList" class="scrollable-section">
                                        <div class="text-muted small text-center">Loading procedure orders...</div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- LAB RESULTS TAB -->
                            <div class="tab-pane" id="results" role="tabpanel">
                                <!-- [Existing Lab Results UI with Amended flag support] -->
                                <div class="lab-stats-summary mb-3">
                                    <div class="stat-item">
                                        <i class="fas fa-flask text-secondary"></i>
                                        <span>Total: <span class="count" id="labTotalCount">0</span></span>
                                    </div>
                                    <div class="stat-item unread">
                                        <i class="fas fa-eye text-info"></i>
                                        <span>New: <span class="count" id="labUnreadCount">0</span></span>
                                        <span id="labUnreadIndicator" class="badge bg-info ms-1" style="display:none;"></span>
                                    </div>
                                    <div class="stat-item critical">
                                        <i class="fas fa-exclamation-triangle text-danger"></i>
                                        <span>Critical: <span class="count" id="labCriticalCount">0</span></span>
                                        <span id="labCriticalIndicator" class="badge bg-danger ms-1" style="display:none;"></span>
                                    </div>
                                    <div class="stat-item abnormal">
                                        <i class="fas fa-exclamation-circle text-warning"></i>
                                        <span>Abnormal: <span class="count" id="labAbnormalCount">0</span></span>
                                    </div>
                                    <div class="stat-item reviewed">
                                        <i class="fas fa-check-double text-success"></i>
                                        <span>Reviewed: <span class="count" id="labReviewedCount">0</span></span>
                                    </div>
                                    <div class="stat-item amended">
                                        <i class="fas fa-edit text-warning"></i>
                                        <span>Amended: <span class="count" id="labAmendedCount">0</span></span>
                                    </div>
                                    <div class="ms-auto">
                                        <button class="btn btn-outline-primary btn-sm" onclick="fetchLabResults()">
                                            <i class="fas fa-sync-alt"></i> Refresh
                                        </button>
                                        <button class="btn btn-outline-danger btn-sm" onclick="fetchCriticalAlerts()">
                                            <i class="fas fa-exclamation-triangle"></i> Critical Alerts
                                        </button>
                                        <button class="btn btn-outline-success btn-sm" onclick="exportLabResults()">
                                            <i class="fas fa-file-pdf"></i> Export
                                        </button>
                                        <button class="btn btn-outline-info btn-sm" onclick="openTrendModal()">
                                            <i class="fas fa-chart-line"></i> Trends
                                        </button>
                                    </div>
                                </div>

                                <div class="row g-2 mb-3">
                                    <div class="col-md-2">
                                        <select class="form-control form-control-sm" id="labResultFilter" onchange="filterLabResults()">
                                            <option value="all">All Results</option>
                                            <option value="unread">Unread</option>
                                            <option value="critical">Critical</option>
                                            <option value="abnormal">Abnormal</option>
                                            <option value="normal">Normal</option>
                                            <option value="reviewed">Reviewed</option>
                                            <option value="amended">Amended</option>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <select class="form-control form-control-sm" id="labResultOrderFilter" onchange="filterLabResults()">
                                            <option value="">All Orders</option>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <input type="text" class="form-control form-control-sm" id="labResultSearch" 
                                            placeholder="🔍 Search tests..." oninput="filterLabResults()">
                                    </div>
                                    <div class="col-md-4 text-end">
                                        <span class="badge bg-light text-dark" id="resultCountDisplay">0 results</span>
                                        <button class="btn btn-outline-secondary btn-sm ms-2" onclick="openTrendModal()">
                                            <i class="fas fa-chart-line"></i> Trend Analysis
                                        </button>
                                    </div>
                                </div>

                                <div id="labResultsContainer">
                                    <div id="labResultsList" class="scrollable-section" style="max-height:500px;">
                                        <div class="text-center p-4 text-muted">
                                            <div class="spinner-border text-primary" role="status"></div>
                                            <p class="mt-2">Loading lab results...</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- History Tab -->
                            <div class="tab-pane" id="history" role="tabpanel">
                                <!-- History Filters -->
                                <div class="row g-2 mb-3">
                                    <div class="col-md-3">
                                        <select class="form-control form-control-sm" id="historyTypeFilter" onchange="fetchOrderHistory()">
                                            <option value="all">All History</option>
                                            <option value="prescriptions">Prescriptions</option>
                                            <option value="labs">Lab Orders</option>
                                            <option value="radiology">Radiology Orders</option>
                                            <option value="procedures">Procedure Orders</option>
                                            <option value="consultations">Consultations</option>
                                            <option value="admissions">Admissions</option>
                                            <option value="visits">Visits</option>
                                            <option value="discharges">Discharge Summaries</option>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <input type="date" class="form-control form-control-sm" id="historyStartDate" placeholder="Start Date" onchange="fetchOrderHistory()">
                                    </div>
                                    <div class="col-md-2">
                                        <input type="date" class="form-control form-control-sm" id="historyEndDate" placeholder="End Date" onchange="fetchOrderHistory()">
                                    </div>
                                    <div class="col-md-2">
                                        <button class="btn btn-outline-primary btn-sm w-100" onclick="fetchOrderHistory()">
                                            <i class="fas fa-search"></i> Apply Filters
                                        </button>
                                    </div>
                                    <div class="col-md-3 text-end">
                                        <button class="btn btn-outline-secondary btn-sm" onclick="resetHistoryFilters()">
                                            <i class="fas fa-undo"></i> Reset
                                        </button>
                                        <button class="btn btn-outline-info btn-sm" onclick="fetchOrderHistory()">
                                            <i class="fas fa-sync-alt"></i> Refresh
                                        </button>
                                    </div>
                                </div>
                                
                                <div class="row mb-3 g-2">
                                    <div class="col-md-6">
                                        <div class="card bg-light p-2">
                                            <h6><i class="fas fa-prescription-bottle"></i> Active Medications</h6>
                                            <div id="patientMedicationsList" class="small">
                                                <div class="text-muted">Loading...</div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="card bg-light p-2">
                                            <h6><i class="fas fa-stethoscope"></i> Past Diagnoses</h6>
                                            <div id="consultationHistoryList" class="small">
                                                <div class="text-muted">Loading...</div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <div class="card bg-light p-2">
                                        <h6><i class="fas fa-users"></i> Family History</h6>
                                        <div id="familyHistoryList" class="small">
                                            <div class="text-muted">Loading...</div>
                                        </div>
                                        <button class="btn btn-outline-primary btn-sm mt-2" onclick="openFamilyHistoryForm()">
                                            <i class="fas fa-edit"></i> Add/Update Family History
                                        </button>
                                    </div>
                                </div>
                                
                                <h6><i class="fas fa-prescription-bottle me-2"></i> Prescriptions</h6>
                                <div id="prescriptionsHistory" class="order-history scrollable-section">
                                    <div class="text-muted text-center small">No prescriptions found</div>
                                </div>
                                <hr>
                                
                                <h6><i class="fas fa-microscope me-2"></i> Lab Orders</h6>
                                <div id="labOrdersHistory" class="order-history scrollable-section">
                                    <div class="text-muted text-center small">No lab orders found</div>
                                </div>
                                <hr>
                                
                                <h6><i class="fas fa-x-ray me-2"></i> Radiology Orders</h6>
                                <div id="radiologyOrdersHistory" class="order-history scrollable-section">
                                    <div class="text-muted text-center small">No radiology orders found</div>
                                </div>
                                <hr>
                                
                                <h6><i class="fas fa-syringe me-2"></i> Procedure Orders</h6>
                                <div id="procedureOrdersHistory" class="order-history scrollable-section">
                                    <div class="text-muted text-center small">No procedure orders found</div>
                                </div>
                                <hr>
                                
                                <h6><i class="fas fa-notes-medical me-2"></i> Consultations</h6>
                                <div id="consultationsHistory" class="order-history scrollable-section">
                                    <div class="text-muted text-center small">No consultations found</div>
                                </div>
                                <hr>
                                
                                <h6><i class="fas fa-hospital me-2"></i> Admissions</h6>
                                <div id="admissionsHistory" class="order-history scrollable-section">
                                    <div class="text-muted text-center small">No admissions found</div>
                                </div>
                                <hr>
                                
                                <h6><i class="fas fa-file-medical-alt me-2"></i> Discharge Summaries</h6>
                                <div id="dischargeSummariesHistory" class="order-history scrollable-section">
                                    <div class="text-muted text-center small">No discharge summaries found</div>
                                </div>
                                <hr>
                                
                                <h6><i class="fas fa-calendar-check me-2"></i> Visits</h6>
                                <div id="visitsHistory" class="order-history scrollable-section">
                                    <div class="text-muted text-center small">No visits found</div>
                                </div>
                                <hr>
                                
                                <!-- Pagination Controls for History -->
                                <div class="pagination-controls" id="historyPagination">
                                    <span class="page-info" id="historyPageInfo">Page 1</span>
                                    <div class="btn-group">
                                        <button class="btn btn-outline-secondary btn-sm" onclick="changeHistoryPage(-1)" id="historyPrevBtn">
                                            <i class="fas fa-chevron-left"></i> Previous
                                        </button>
                                        <button class="btn btn-outline-secondary btn-sm" onclick="changeHistoryPage(1)" id="historyNextBtn">
                                            Next <i class="fas fa-chevron-right"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="quick-actions">
                        <button class="btn btn-outline btn-sm" onclick="openConsultationRequest()"><i class="fas fa-user-md"></i> Request Consultation</button>
                        <button class="btn btn-outline btn-sm" onclick="openReferral()"><i class="fas fa-share-alt"></i> Refer Patient</button>
                        <button class="btn btn-outline btn-sm" onclick="window.location.href='emr_view.php?patient_id=<?php echo $current_visit['patient_id']; ?>'"><i class="fas fa-file-medical"></i> Full Record</button>
                        <?php if ($admission_data): ?>
                        <button class="btn btn-outline btn-sm" onclick="updateConditionStatus()"><i class="fas fa-heartbeat"></i> Update Status</button>
                        <?php endif; ?>
                    </div>
                    
                <?php else: ?>
                    <div class="patient-info-card text-center py-5">
                        <div class="empty-state">
                            <i class="fas fa-user-md"></i>
                            <h5>No patient selected</h5>
                            <p class="text-muted">Choose a patient from the queue to start consultation</p>
                            <a href="dashboard.php" class="btn btn-primary mt-3"><i class="fas fa-arrow-left"></i> Go to Dashboard</a>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php include('assets/inc/footer.php'); ?>
</div>

<div id="loadingOverlay" class="loading-overlay"><div class="spinner"></div></div>

<script>
// ============================================
// CONSULTATION HUB - UPGRADED FRONTEND
// VERSION: 3.0 - Full Feature Upgrade
// ============================================

// ============================================
// 1. GLOBAL VARIABLES & CONFIGURATION
// ============================================

const doctorId = <?php echo $user_id; ?>;
const branchId = <?php echo $branch_id; ?>;
const isGlobalAdmin = <?php echo $is_global_admin ? 'true' : 'false'; ?>;
const csrfToken = <?php echo json_encode($csrf_token); ?>;
let currentVisitId = <?php echo $active_visit_id ?: 0; ?>;
let currentPatientId = <?php echo $active_patient_id ?: 0; ?>;
let currentTab = 'pending';
let labPollingInterval = null;
let queueRefreshInterval = null;
let allLabResults = [];
let selectedLabTests = [];
let isQueueLoading = false;
let selectedPanels = [];
const AJAX_URL = 'current_consultation.php?ajax=consultation_action';
let historyPage = 1;
let historyType = 'all';

// ============================================
// 2. UTILITY FUNCTIONS
// ============================================

function showToast(message, type) {
    type = type || 'success';
    const toast = document.createElement('div');
    toast.className = 'toast-notification' + (type === 'error' ? ' toast-error' : '');
    toast.innerHTML = '<i class="fas fa-' + (type === 'error' ? 'exclamation-circle' : 'check-circle') + '"></i> <span>' + escapeHtml(message) + '</span>';
    document.body.appendChild(toast);
    setTimeout(function() {
        if (toast.parentNode) toast.remove();
    }, 4000);
}

function showLoading(show) {
    const overlay = document.getElementById('loadingOverlay');
    if (overlay) {
        if (show) {
            overlay.classList.add('active');
        } else {
            overlay.classList.remove('active');
        }
    }
}

function escapeHtml(unsafe) {
    if (!unsafe) return '';
    return String(unsafe)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function formatDate(date) {
    if (!date) return 'N/A';
    try {
        return new Date(date).toLocaleString('en-US', {
            year: 'numeric',
            month: 'short',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        });
    } catch(e) {
        return date;
    }
}

function getStatusBadge(status) {
    var badges = {
        'pending_payment': 'warning',
        'unpaid': 'warning',
        'paid': 'success',
        'completed': 'success',
        'cancelled': 'danger',
        'pending': 'secondary',
        'approved': 'info',
        'collected': 'primary',
        'in_progress': 'info',
        'active': 'success',
        'dispensed': 'success',
        'scheduled': 'info',
        'admitted': 'primary',
        'discharged': 'success',
        'deceased': 'danger',
        'ama': 'danger',
        'transferred': 'info',
        'stable': 'success',
        'guarded': 'warning',
        'critical': 'danger',
        'serious': 'danger',
        'received': 'info',
        'processing': 'primary',
        'rejected': 'danger',
        'discarded': 'secondary',
        'draft': 'secondary',
        'finalized': 'success',
        'amended': 'warning',
        'billed': 'info',
        'expired': 'danger',
        'partial': 'warning',
        'refunded': 'info',
        'waived': 'secondary',
        'overdue': 'danger',
        'inactive': 'secondary'
    };
    var cls = badges[status] || 'secondary';
    return '<span class="badge bg-' + cls + '">' + status + '</span>';
}

function formatTime(timeStr) {
    if (!timeStr) return 'N/A';
    try {
        var parts = timeStr.split(':');
        var hour = parseInt(parts[0]);
        var minute = parts[1] || '00';
        var ampm = hour >= 12 ? 'PM' : 'AM';
        var hour12 = hour % 12 || 12;
        return hour12 + ':' + minute + ' ' + ampm;
    } catch(e) {
        return timeStr;
    }
}

// ============================================
// 3. QUEUE MANAGEMENT
// ============================================

function switchQueueTab(tab) {
    if (isQueueLoading) return;
    currentTab = tab;
    
    document.querySelectorAll('.queue-tab').forEach(function(t) {
        if (t.dataset.tab === tab) {
            t.classList.add('active');
        } else {
            t.classList.remove('active');
        }
    });
    
    refreshQueue(tab);
}

function refreshQueue(tab) {
    tab = tab || currentTab;
    
    if (isQueueLoading) return;
    isQueueLoading = true;
    
    var queueList = document.getElementById('queueList');
    if (queueList) {
        queueList.innerHTML = '<div class="text-center p-4 text-muted"><div class="spinner-border spinner-border-sm text-primary" role="status"></div><span class="ms-2">Loading queue...</span></div>';
    }
    
    $.ajax({
        url: AJAX_URL,
        type: 'POST',
        dataType: 'json',
        data: {
            action: 'fetch_queue',
            tab: tab,
            csrf_token: csrfToken
        },
        timeout: 10000,
        success: function(res) {
            isQueueLoading = false;
            
            if (res.success && res.queue !== undefined) {
                renderQueue(res);
            } else {
                showQueueError(res.message || 'Error loading queue');
            }
        },
        error: function(xhr, status, error) {
            isQueueLoading = false;
            console.error('Queue fetch error:', status, error);
            showQueueError('Network error. Please try again.');
        }
    });
}

function showQueueError(message) {
    var queueList = document.getElementById('queueList');
    if (queueList) {
        queueList.innerHTML = '<div class="empty-state"><i class="fas fa-exclamation-circle text-danger fa-3x mb-3"></i><p class="text-danger">' + escapeHtml(message) + '</p><button class="btn btn-sm btn-outline-primary mt-2" onclick="refreshQueue()"><i class="fas fa-sync-alt"></i> Retry</button></div>';
    }
    showToast(message, 'error');
}

function renderQueue(res) {
    var queueList = document.getElementById('queueList');
    if (!queueList) return;
    
    var html = '';
    
    if (!res.queue || res.queue.length === 0) {
        var tabLabels = {
            'pending': 'patients waiting',
            'lab_pending': 'patients with pending labs',
            'lab_ready': 'patients with lab results ready',
            'completed': 'completed consultations'
        };
        html = '<div class="empty-state"><i class="fas fa-check-circle text-success fa-3x mb-3"></i><p class="mb-0">No ' + (tabLabels[res.current_tab || currentTab] || 'patients') + '</p></div>';
    } else {
        if (res.counts) {
            updateCounts(res.counts);
        }
        
        res.queue.forEach(function(p, index) {
            var activeClass = (p.visit_id == currentVisitId) ? 'active' : '';
            var isEven = index % 2 === 0;
            
            var priorityBadge = '';
            if (p.appointment_priority === 'stat') {
                priorityBadge = '<span class="badge bg-danger ms-1">STAT</span>';
            } else if (p.appointment_priority === 'urgent') {
                priorityBadge = '<span class="badge bg-warning text-dark ms-1">Urgent</span>';
            }
            
            var triageBadge = '';
            if (p.triage_priority === 'red') {
                triageBadge = '<span class="badge bg-danger ms-1"><i class="fas fa-exclamation-triangle"></i> RED</span>';
            } else if (p.triage_priority === 'yellow') {
                triageBadge = '<span class="badge bg-warning text-dark ms-1">Yellow</span>';
            } else if (p.triage_priority === 'green') {
                triageBadge = '<span class="badge bg-success ms-1">Green</span>';
            }
            
            html += '<div class="patient-card ' + activeClass + '" data-visit-id="' + p.visit_id + '" data-patient-id="' + p.patient_id + '" style="' + (isEven ? '' : 'background: #fafafa;') + '">';
            html += '<div class="d-flex justify-content-between align-items-start">';
            html += '<div class="flex-grow-1" style="min-width:0;">';
            html += '<div class="d-flex flex-wrap align-items-center gap-1">';
            html += '<span class="patient-name">' + escapeHtml(p.patient_name) + '</span>';
            html += priorityBadge;
            html += triageBadge;
            html += '<span class="status-badge ' + p.status_class + '"><i class="fas ' + (p.status_icon || 'fa-clock') + '"></i> ' + p.status_display + '</span>';
            html += '</div>';
            html += '<div class="patient-mrn">MRN: ' + escapeHtml(p.mr_number || 'N/A') + ' | Age: ' + (p.age || 'N/A') + ' | ' + (p.gender || 'N/A') + '</div>';
            html += '<div class="patient-meta">BP: ' + (p.bp || '--/--') + ' | HR: ' + (p.heart_rate || '--') + ' | SpO₂: ' + (p.oxygen_saturation || '--') + '%' + (p.temperature_celsius ? ' | Temp: ' + p.temperature_celsius + '°C' : '') + '</div>';
            if (p.chief_complaint) {
                html += '<div class="small text-muted mt-1"><i class="fas fa-comment-dots"></i> ' + escapeHtml(p.chief_complaint.substring(0, 60)) + (p.chief_complaint.length > 60 ? '...' : '') + '</div>';
            }
            if (p.badges_html) {
                html += '<div class="badge-group mt-1">' + p.badges_html + '</div>';
            }
            html += '</div>';
            html += '<div class="text-end ms-2" style="flex-shrink:0;">';
            html += '<div class="waiting-time"><i class="fas fa-clock"></i> ' + (p.waiting_display || '0 min') + '</div>';
            if (p.consultation_started_at) {
                html += '<div class="small text-success mt-1"><i class="fas fa-play-circle"></i> In Progress</div>';
            }
            if (p.appointment_time) {
                html += '<div class="small text-muted mt-1"><i class="far fa-calendar-alt"></i> ' + formatTime(p.appointment_time) + '</div>';
            }
            html += '</div>';
            html += '</div>';
            html += '</div>';
        });
    }
    
    queueList.innerHTML = html;
    
    document.querySelectorAll('.patient-card').forEach(function(card) {
        card.addEventListener('click', function() {
            var visitId = this.dataset.visitId;
            var patientId = this.dataset.patientId;
            if (visitId && patientId) {
                window.location.href = 'current_consultation.php?visit_id=' + visitId + '&patient_id=' + patientId;
            }
        });
    });
}

function updateCounts(counts) {
    var elements = {
        'pendingCount': counts.pending || 0,
        'labPendingCount': counts.lab_pending || 0,
        'labReadyCount': counts.lab_ready || 0,
        'completedCount': counts.completed || 0
    };
    
    Object.keys(elements).forEach(function(id) {
        var el = document.getElementById(id);
        if (el) el.textContent = elements[id];
    });
    
    var badge = document.getElementById('labReadyBadge');
    if (badge) {
        if (counts.lab_ready > 0) {
            badge.style.display = 'flex';
            badge.textContent = counts.lab_ready;
        } else {
            badge.style.display = 'none';
        }
    }
}

function initQueuePolling() {
    refreshQueue('pending');
    
    if (queueRefreshInterval) {
        clearInterval(queueRefreshInterval);
    }
    queueRefreshInterval = setInterval(function() {
        if (!document.hidden) {
            refreshQueue(currentTab);
        }
    }, 30000);
}

function cleanupQueuePolling() {
    if (queueRefreshInterval) {
        clearInterval(queueRefreshInterval);
        queueRefreshInterval = null;
    }
    isQueueLoading = false;
}

// ============================================
// 4. TAB MANAGEMENT
// ============================================

function initTabs() {
    var tabButtons = document.querySelectorAll('#cpoeTabs .nav-link');
    var tabPanes = document.querySelectorAll('.tab-pane');
    
    tabButtons.forEach(function(button) {
        button.addEventListener('click', function(e) {
            e.preventDefault();
            var targetTabId = this.getAttribute('data-tab');
            
            tabButtons.forEach(function(btn) { btn.classList.remove('active'); });
            tabPanes.forEach(function(pane) { pane.classList.remove('active'); });
            
            this.classList.add('active');
            var targetPane = document.getElementById(targetTabId);
            if (targetPane) targetPane.classList.add('active');
            
            if (targetTabId === 'history' && currentPatientId) {
                historyPage = 1;
                fetchOrderHistory();
                fetchPatientMedications();
                fetchConsultationHistory();
                fetchFamilyHistory();
            }
            if (targetTabId === 'lab' && currentVisitId) {
                fetchLabStatus();
                fetchSpecimens();
            }
            if (targetTabId === 'radiology' && currentVisitId) {
                fetchRadiologyStatus();
            }
            if (targetTabId === 'procedure' && currentVisitId) {
                fetchProcedureStatus();
            }
            if (targetTabId === 'results' && currentVisitId) {
                fetchLabResults();
                fetchCriticalAlerts();
            }
        });
    });
}

// ============================================
// 5. PANEL MANAGEMENT
// ============================================

function togglePanel(panelId) {
    var panelCards = document.querySelectorAll('.panel-card');
    var targetPanel = document.querySelector('.panel-card[data-panel-id="' + panelId + '"]');
    
    if (!targetPanel) return;
    
    // Toggle selection
    targetPanel.classList.toggle('selected');
    
    // Update panel selection state
    if (targetPanel.classList.contains('selected')) {
        if (!selectedPanels.includes(panelId)) {
            selectedPanels.push(panelId);
        }
    } else {
        selectedPanels = selectedPanels.filter(function(id) { return id !== panelId; });
    }
    
    // Update test checkboxes based on panel selection
    updatePanelTests();
}

function updatePanelTests() {
    // Clear all test selections
    // We'll re-apply based on selected panels
    var allCheckboxes = document.querySelectorAll('#labTestsCheckboxContainer input[type="checkbox"]');
    
    // If no panels selected, leave test selection as is
    if (selectedPanels.length === 0) return;
    
    // Get all test IDs from selected panels
    var panelTestIds = [];
    selectedPanels.forEach(function(panelId) {
        var panel = document.querySelector('.panel-card[data-panel-id="' + panelId + '"]');
        if (panel) {
            // Get test IDs from panel data
            var tests = panel.dataset.tests ? JSON.parse(panel.dataset.tests) : [];
            panelTestIds = panelTestIds.concat(tests);
        }
    });
    
    // Check all tests that belong to selected panels
    allCheckboxes.forEach(function(cb) {
        if (panelTestIds.includes(parseInt(cb.value))) {
            cb.checked = true;
        }
    });
    
    updateLabSelection();
}

// ============================================
// 6. CONSULTATION WORKFLOW
// ============================================

$(document).ready(function() {
    initQueuePolling();
    initTabs();
    
    if (currentVisitId) {
        initDrugSelect($('.drug-select[data-row="0"]'));
    }
    
    updateLabSelection();
    
    if (currentVisitId) {
        fetchLabStatus();
        fetchSpecimens();
        fetchRadiologyStatus();
        fetchProcedureStatus();
        fetchLabResults();
        fetchOrderHistory();
        fetchPatientMedications();
        fetchConsultationHistory();
        fetchFamilyHistory();
        
        labPollingInterval = setInterval(function() {
            fetchLabStatus();
            fetchSpecimens();
            fetchRadiologyStatus();
            fetchProcedureStatus();
        }, 30000);
    }
    
    // Start Consultation
    $('#startConsultBtn').on('click', function() {
        if (!currentVisitId) return;
        showLoading(true);
        $.post(AJAX_URL, {
            action: 'start_consultation',
            visit_id: currentVisitId,
            csrf_token: csrfToken
        }, function(res) {
            showLoading(false);
            if (res.success) {
                showToast(res.message);
                setTimeout(function() { location.reload(); }, 1000);
            } else {
                showToast(res.message || 'Failed to start consultation', 'error');
            }
        }).fail(function() { 
            showLoading(false); 
            showToast('Network error', 'error'); 
        });
    });
    
    // Complete Consultation
    $('#completeConsultBtn').on('click', function() {
        if (!currentVisitId) {
            showToast('No consultation selected', 'error');
            return;
        }
        
        Swal.fire({
            title: 'Complete Consultation?',
            html: '<div class="text-start"><p>Are you sure you want to complete this consultation?</p><div class="form-group"><label>Final Diagnosis <span class="text-danger">*</span></label><input type="text" id="finalDiagnosis" class="form-control" placeholder="Enter final diagnosis..."></div><div class="form-group"><label>Discharge Condition</label><select id="dischargeCondition" class="form-control"><option value="improved">Improved</option><option value="stabilized">Stabilized</option><option value="relieved">Relieved</option><option value="ama">Against Medical Advice</option><option value="expired">Expired</option><option value="transferred">Transferred</option></select></div><div class="form-group"><label><input type="checkbox" id="createDischargeSummary" value="1"> Create Discharge Summary</label></div><div class="form-group"><label>Follow-up Required</label><select id="followUpRequired" class="form-control"><option value="0">No</option><option value="1">Yes</option></select></div><div class="form-group" id="followUpDateGroup" style="display:none;"><label>Follow-up Date</label><input type="date" id="followUpDate" class="form-control"></div><div class="form-group"><label>Follow-up Instructions</label><textarea id="followUpInstructions" class="form-control" rows="2" placeholder="Follow-up instructions..."></textarea></div><div class="form-group"><label>Discharge Notes</label><textarea id="dischargeNotes" class="form-control" rows="2" placeholder="Discharge summary notes..."></textarea></div></div>',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Complete Consultation',
            confirmButtonColor: '#06d6a0',
            cancelButtonText: 'Cancel',
            width: 550,
            preConfirm: function() {
                var diagnosis = document.getElementById('finalDiagnosis').value.trim();
                if (!diagnosis) {
                    Swal.showValidationMessage('Please enter a final diagnosis');
                    return false;
                }
                return {
                    diagnosis: diagnosis,
                    dischargeCondition: document.getElementById('dischargeCondition').value,
                    createDischargeSummary: document.getElementById('createDischargeSummary').checked ? 1 : 0,
                    followUpRequired: document.getElementById('followUpRequired').value,
                    followUpDate: document.getElementById('followUpDate').value,
                    followUpInstructions: document.getElementById('followUpInstructions').value,
                    dischargeNotes: document.getElementById('dischargeNotes').value
                };
            }
        }).then(function(result) {
            if (result.isConfirmed && result.value) {
                var data = result.value;
                showLoading(true);
                $.ajax({
                    url: AJAX_URL,
                    type: 'POST',
                    dataType: 'json',
                    data: {
                        action: 'complete_consultation',
                        visit_id: currentVisitId,
                        final_diagnosis: data.diagnosis,
                        discharge_condition: data.dischargeCondition,
                        create_discharge_summary: data.createDischargeSummary,
                        follow_up_required: data.followUpRequired,
                        follow_up_date: data.followUpDate,
                        follow_up_instructions: data.followUpInstructions,
                        discharge_notes: data.dischargeNotes,
                        csrf_token: csrfToken
                    },
                    success: function(res) {
                        showLoading(false);
                        if (res.success) {
                            showToast(res.message);
                            Swal.fire({
                                icon: 'success',
                                title: 'Consultation Completed!',
                                text: 'The consultation has been successfully completed.',
                                confirmButtonText: 'OK',
                                confirmButtonColor: '#4361ee',
                                timer: 3000,
                                timerProgressBar: true
                            }).then(function() {
                                window.location.href = 'dashboard.php';
                            });
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: 'Error',
                                text: res.message || 'Failed to complete consultation.',
                                confirmButtonText: 'OK',
                                confirmButtonColor: '#ef476f'
                            });
                            showToast(res.message || 'Failed to complete consultation', 'error');
                        }
                    },
                    error: function(xhr) {
                        showLoading(false);
                        var errorMsg = 'Network error. Please try again.';
                        if (xhr.responseJSON && xhr.responseJSON.message) {
                            errorMsg = xhr.responseJSON.message;
                        }
                        Swal.fire({
                            icon: 'error',
                            title: 'Network Error',
                            text: errorMsg,
                            confirmButtonText: 'OK',
                            confirmButtonColor: '#ef476f'
                        });
                        showToast(errorMsg, 'error');
                    }
                });
            }
        });
    });
    
    // SOAP Form Submit
    $('#soapForm').on('submit', function(e) {
        e.preventDefault();
        var visitId = $('input[name="visit_id"]').val();
        var patientId = $('input[name="patient_id"]').val();
        var subjective = $('#subjective').val();
        var objective = $('#objective').val();
        var assessment = $('#assessment').val();
        var plan = $('#plan').val();
        var primaryIcd10 = $('#primary_icd10').val();
        var diagnosis = $('#diagnosis').val();
        
        if (!subjective && !objective && !assessment && !plan) {
            showToast('Please fill at least one SOAP section', 'error');
            return;
        }
        
        showLoading(true);
        $.post(AJAX_URL, {
            action: 'save_soap',
            visit_id: visitId,
            patient_id: patientId,
            subjective: subjective,
            objective: objective,
            assessment: assessment,
            plan: plan,
            primary_icd10: primaryIcd10,
            diagnosis: diagnosis,
            csrf_token: csrfToken
        }, function(res) {
            showLoading(false);
            if (res.success) {
                showToast(res.message);
                $('#soapLastSaved').text('Last saved: ' + new Date().toLocaleTimeString());
            } else {
                showToast(res.message || 'Failed to save consultation', 'error');
            }
        }).fail(function() { 
            showLoading(false); 
            showToast('Network error', 'error'); 
        });
    });
    
    // Prescription Form Submit
    $('#prescriptionForm').on('submit', function(e) {
        e.preventDefault();
        var visitId = $('input[name="visit_id"]').val();
        var patientId = $('input[name="patient_id"]').val();
        var clinicalNotes = $('#rx_clinical_notes').val();
        
        var items = [];
        $('.prescription-row').each(function() {
            var row = $(this);
            var drugSelect = row.find('.drug-select');
            var medicineId = drugSelect.val();
            var dosage = row.find('input[name="dosage[]"]').val();
            var frequency = row.find('input[name="frequency[]"]').val();
            var duration = row.find('input[name="duration[]"]').val();
            var mealRelation = row.find('select[name="meal_relation[]"]').val();
            var instructions = row.find('input[name="instructions[]"]').val();
            
            if (medicineId && dosage && frequency) {
                items.push({
                    medicine_id: medicineId,
                    dosage: dosage,
                    frequency: frequency,
                    duration: duration || 7,
                    meal_relation: mealRelation || 'any',
                    instructions: instructions
                });
            }
        });
        
        if (items.length === 0) {
            showToast('Please add at least one medication', 'error');
            return;
        }
        
        showLoading(true);
        $.post(AJAX_URL, {
            action: 'save_prescription',
            visit_id: visitId,
            patient_id: patientId,
            clinical_notes: clinicalNotes,
            items: JSON.stringify(items),
            csrf_token: csrfToken
        }, function(res) {
            showLoading(false);
            if (res.success) {
                showToast(res.message);
                $('#prescriptionForm')[0].reset();
                $('#prescriptionItemsContainer').html('<div class="prescription-row" data-row="0"><button type="button" class="remove-row" onclick="removePrescriptionRow(this)"><i class="fas fa-times"></i></button><div class="row g-2"><div class="col-md-5"><div class="form-group"><label>Medication <span class="text-danger">*</span></label><select class="form-control drug-select" name="drug[]" data-row="0" style="width: 100%;"></select></div></div><div class="col-md-2"><div class="form-group"><label>Dosage <span class="text-danger">*</span></label><input type="text" class="form-control" name="dosage[]" placeholder="e.g., 500mg"></div></div><div class="col-md-2"><div class="form-group"><label>Frequency <span class="text-danger">*</span></label><input type="text" class="form-control" name="frequency[]" placeholder="e.g., BID, TID"></div></div><div class="col-md-1"><div class="form-group"><label>Days</label><input type="number" class="form-control" name="duration[]" placeholder="Days" value="7" min="1"></div></div><div class="col-md-2"><div class="form-group"><label>Meal Relation</label><select class="form-control" name="meal_relation[]"><option value="any">Any</option><option value="before_meal">Before Meal</option><option value="after_meal">After Meal</option><option value="with_meal">With Meal</option><option value="empty_stomach">Empty Stomach</option></select></div></div><div class="col-md-12"><div class="form-group"><label>Instructions</label><input type="text" class="form-control" name="instructions[]" placeholder="Special instructions"></div></div></div></div>');
                initDrugSelect($('.drug-select[data-row="0"]'));
                if ($('#history').hasClass('active')) {
                    fetchOrderHistory();
                    fetchPatientMedications();
                }
                refreshQueue();
            } else {
                if (res.contraindication_error) {
                    showToast('❌ ' + res.message, 'error');
                    Swal.fire({
                        icon: 'error',
                        title: 'Contraindication Alert!',
                        html: '<div class="text-danger"><strong>' + res.message + '</strong></div><div class="mt-2">Allergy: ' + res.allergy_details + '</div><div class="mt-1">Drug: ' + res.drug_name + '</div>',
                        confirmButtonText: 'Acknowledge',
                        confirmButtonColor: '#ef476f'
                    });
                } else if (res.inventory_errors) {
                    var errorHtml = '<div class="text-warning"><strong>Inventory Shortage:</strong></div><ul>';
                    res.inventory_errors.forEach(function(err) {
                        errorHtml += '<li>' + err.medicine_name + ': Requested ' + err.requested + ', Available ' + err.available + '</li>';
                    });
                    errorHtml += '</ul>';
                    Swal.fire({
                        icon: 'warning',
                        title: 'Insufficient Stock',
                        html: errorHtml,
                        confirmButtonText: 'Adjust Prescription',
                        confirmButtonColor: '#f9c74f'
                    });
                    showToast(res.message, 'error');
                } else {
                    showToast(res.message || 'Failed to save prescription', 'error');
                }
            }
        }).fail(function() { 
            showLoading(false); 
            showToast('Network error', 'error'); 
        });
    });
});

// ============================================
// 7. PRESCRIPTION DRUG SELECT
// ============================================

function initDrugSelect(element) {
    element.select2({
        placeholder: 'Search for medication...',
        allowClear: true,
        minimumInputLength: 2,
        ajax: {
            url: AJAX_URL,
            dataType: 'json',
            delay: 300,
            data: function(params) {
                return {
                    action: 'search_drugs',
                    term: params.term,
                    csrf_token: csrfToken
                };
            },
            processResults: function(data) {
                if (data.success && data.drugs) {
                    return {
                        results: data.drugs.map(function(item) {
                            var label = item.text + ' (' + item.unit_of_measure + ')';
                            if (item.is_controlled_substance) label += ' 🔒';
                            return { id: item.id, text: label };
                        })
                    };
                }
                return { results: [] };
            },
            cache: true
        }
    });
}

function addPrescriptionRow() {
    var container = $('#prescriptionItemsContainer');
    var rowCount = container.children('.prescription-row').length;
    var newRow = '<div class="prescription-row" data-row="' + rowCount + '"><button type="button" class="remove-row" onclick="removePrescriptionRow(this)"><i class="fas fa-times"></i></button><div class="row g-2"><div class="col-md-5"><div class="form-group"><label>Medication <span class="text-danger">*</span></label><select class="form-control drug-select" name="drug[]" data-row="' + rowCount + '" style="width: 100%;"></select></div></div><div class="col-md-2"><div class="form-group"><label>Dosage <span class="text-danger">*</span></label><input type="text" class="form-control" name="dosage[]" placeholder="e.g., 500mg"></div></div><div class="col-md-2"><div class="form-group"><label>Frequency <span class="text-danger">*</span></label><input type="text" class="form-control" name="frequency[]" placeholder="e.g., BID, TID"></div></div><div class="col-md-1"><div class="form-group"><label>Days</label><input type="number" class="form-control" name="duration[]" placeholder="Days" value="7" min="1"></div></div><div class="col-md-2"><div class="form-group"><label>Meal Relation</label><select class="form-control" name="meal_relation[]"><option value="any">Any</option><option value="before_meal">Before Meal</option><option value="after_meal">After Meal</option><option value="with_meal">With Meal</option><option value="empty_stomach">Empty Stomach</option></select></div></div><div class="col-md-12"><div class="form-group"><label>Instructions</label><input type="text" class="form-control" name="instructions[]" placeholder="Special instructions"></div></div></div></div>';
    container.append(newRow);
    initDrugSelect($('.drug-select[data-row="' + rowCount + '"]'));
}

function removePrescriptionRow(btn) {
    $(btn).closest('.prescription-row').remove();
}

// ============================================
// 8. LAB ORDERS
// ============================================

function updateLabSelection() {
    var checkboxes = document.querySelectorAll('input[name="selected_tests[]"]:checked');
    var count = checkboxes.length;
    var total = 0;
    selectedLabTests = [];
    
    checkboxes.forEach(function(cb) {
        var price = parseFloat(cb.dataset.price) || 0;
        total += price;
        selectedLabTests.push({
            id: cb.value,
            name: cb.dataset.name,
            price: price
        });
    });
    
    var countEl = document.getElementById('selectedTestCount');
    var totalEl = document.getElementById('totalTestPrice');
    if (countEl) countEl.textContent = count + ' selected';
    if (totalEl) totalEl.textContent = total.toFixed(2) + ' ETB';
    
    var submitBtn = document.getElementById('submitLabOrder');
    if (submitBtn) {
        if (count > 0) {
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<i class="fas fa-paper-plane"></i> Send ' + count + ' Test' + (count > 1 ? 's' : '') + ' to Laboratory';
        } else {
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-paper-plane"></i> Select Tests First';
        }
    }
}

function selectAllLabs() {
    document.querySelectorAll('#labTestsCheckboxContainer input[type="checkbox"]').forEach(function(cb) {
        cb.checked = true;
    });
    updateLabSelection();
}

function deselectAllLabs() {
    document.querySelectorAll('#labTestsCheckboxContainer input[type="checkbox"]').forEach(function(cb) {
        cb.checked = false;
    });
    updateLabSelection();
}

function filterLabTests() {
    var searchTerm = document.getElementById('labTestSearch') ? document.getElementById('labTestSearch').value.toLowerCase() : '';
    var category = document.getElementById('labTestCategoryFilter') ? document.getElementById('labTestCategoryFilter').value : '';
    
    document.querySelectorAll('#labTestsCheckboxContainer .lab-test-checkbox').forEach(function(item) {
        var name = item.dataset.name || '';
        var itemCategory = item.dataset.category || '';
        var matchesSearch = name.includes(searchTerm);
        var matchesCategory = !category || itemCategory === category;
        item.style.display = (matchesSearch && matchesCategory) ? '' : 'none';
    });
}

function fetchLabStatus() {
    if (!currentVisitId) return;
    
    $.post(AJAX_URL, {
        action: 'fetch_lab_status',
        visit_id: currentVisitId,
        csrf_token: csrfToken
    }, function(res) {
        if (res.success && res.labs) {
            var html = '';
            if (res.labs.length === 0) {
                html = '<div class="text-center py-4 text-muted"><i class="fas fa-flask fa-2x mb-2 opacity-25"></i><p class="mb-0">No lab orders found</p><small>Create a new lab order above</small></div>';
            } else {
                res.labs.forEach(function(lab) {
                    var statusMap = {
                        'pending_payment': { class: 'warning', icon: 'fa-clock', text: 'Pending Payment' },
                        'pending': { class: 'secondary', icon: 'fa-hourglass-half', text: 'Pending' },
                        'approved': { class: 'info', icon: 'fa-check-circle', text: 'Approved' },
                        'collected': { class: 'primary', icon: 'fa-syringe', text: 'Collected' },
                        'in_progress': { class: 'info', icon: 'fa-microscope', text: 'In Progress' },
                        'completed': { class: 'success', icon: 'fa-check-circle', text: 'Completed' },
                        'cancelled': { class: 'danger', icon: 'fa-times-circle', text: 'Cancelled' },
                        'rejected': { class: 'danger', icon: 'fa-exclamation-circle', text: 'Rejected' },
                        'verified': { class: 'success', icon: 'fa-check-double', text: 'Verified' }
                    };
                    var statusInfo = statusMap[lab.status] || statusMap['pending'];
                    var priorityClass = lab.priority === 'stat' ? 'danger' : (lab.priority === 'urgent' ? 'warning' : 'info');
                    
                    var criticalBadge = '';
                    if (lab.critical_unread > 0) {
                        criticalBadge = '<span class="badge bg-danger pulse ms-2"><i class="fas fa-exclamation-triangle"></i> ' + lab.critical_unread + ' Critical</span>';
                    }
                    
                    var resultBadge = '';
                    if (lab.unread_results > 0) {
                        resultBadge = '<span class="badge bg-info ms-2"><i class="fas fa-bell"></i> ' + lab.unread_results + ' New</span>';
                    }
                    
                    html += '<div class="order-card border-start border-4 border-' + statusInfo.class + '">';
                    html += '<div class="d-flex justify-content-between align-items-center">';
                    html += '<div><strong>' + escapeHtml(lab.order_number) + '</strong>' + criticalBadge + resultBadge + '<span class="badge bg-' + priorityClass + ' ms-1">' + (lab.priority || 'routine').toUpperCase() + '</span></div>';
                    html += '<span class="badge bg-' + statusInfo.class + '"><i class="fas ' + statusInfo.icon + '"></i> ' + statusInfo.text + '</span>';
                    html += '</div>';
                    html += '<div class="small mt-1"><span class="text-muted">Tests:</span> ' + escapeHtml(lab.tests || 'N/A') + '</div>';
                    html += '<div class="small"><span class="text-muted">Ordered:</span> ' + formatDate(lab.order_date) + (lab.specimen_collected_at ? ' | <span class="text-success"><i class="fas fa-vial"></i> Collected: ' + formatDate(lab.specimen_collected_at) + '</span>' : '') + (lab.results_ready_at ? ' | <span class="text-info"><i class="fas fa-flask"></i> Results: ' + formatDate(lab.results_ready_at) + '</span>' : '') + '</div>';
                    if (lab.clinical_indication) html += '<div class="small text-muted mt-1"><i class="fas fa-notes-medical"></i> ' + escapeHtml(lab.clinical_indication) + '</div>';
                    if (lab.specimen_type) html += '<div class="small text-muted">Specimen: ' + escapeHtml(lab.specimen_type) + '</div>';
                    if (lab.specimen_count > 0) html += '<div class="small text-muted"><i class="fas fa-vial"></i> ' + lab.specimen_count + ' specimen(s) collected</div>';
                    
                    if (lab.unread_results > 0) {
                        html += '<div class="mt-2"><button class="btn btn-primary btn-sm" onclick="fetchLabResults(); $(\'#results-tab\').tab(\'show\');"><i class="fas fa-eye"></i> View ' + lab.unread_results + ' New Results</button></div>';
                    }
                    
                    if (lab.critical_unread > 0) {
                        html += '<div class="mt-2"><button class="btn btn-danger btn-sm pulse" onclick="fetchCriticalAlerts(); $(\'#results-tab\').tab(\'show\');"><i class="fas fa-exclamation-triangle"></i> View ' + lab.critical_unread + ' Critical Alerts</button></div>';
                    }
                    
                    html += '</div>';
                });
            }
            $('#labStatusList').html(html);
            
            if (res.has_ready_results) {
                showToast('Lab results are ready for review!', 'success');
            }
        } else {
            $('#labStatusList').html('<div class="text-center py-3 text-muted"><i class="fas fa-exclamation-circle"></i> Error loading lab orders</div>');
        }
    }).fail(function() {
        $('#labStatusList').html('<div class="text-center py-3 text-muted"><i class="fas fa-wifi-slash"></i> Network error</div>');
    });
}

function fetchSpecimens() {
    if (!currentVisitId) return;
    
    $.post(AJAX_URL, {
        action: 'fetch_specimen_status',
        visit_id: currentVisitId,
        csrf_token: csrfToken
    }, function(res) {
        if (res.success && res.specimens) {
            var html = '';
            if (res.specimens.length === 0) {
                html = '<div class="text-center py-2 text-muted small"><i class="fas fa-vial me-1"></i> No specimens collected</div>';
            } else {
                res.specimens.forEach(function(spec) {
                    var statusMap = {
                        'received': { class: 'info', text: 'Received' },
                        'processing': { class: 'warning', text: 'Processing' },
                        'completed': { class: 'success', text: 'Completed' },
                        'rejected': { class: 'danger', text: 'Rejected' },
                        'discarded': { class: 'secondary', text: 'Discarded' }
                    };
                    var info = statusMap[spec.status] || statusMap['received'];
                    
                    var qualityIssues = '';
                    if (spec.is_hemolyzed) qualityIssues += ' Hemolyzed';
                    if (spec.is_icteric) qualityIssues += ' Icteric';
                    if (spec.is_lipemic) qualityIssues += ' Lipemic';
                    if (spec.quality_issues) qualityIssues += ' ' + spec.quality_issues;
                    
                    html += '<div class="d-flex justify-content-between align-items-center border-bottom py-2 small">';
                    html += '<div><span class="fw-semibold">' + escapeHtml(spec.barcode) + '</span><span class="badge bg-' + info.class + ' ms-1">' + info.text + '</span><span class="text-muted ms-2">' + escapeHtml(spec.specimen_type) + '</span>' + (qualityIssues ? '<span class="badge bg-warning text-dark ms-1">⚠️ ' + qualityIssues.trim() + '</span>' : '') + '</div>';
                    html += '<div class="text-muted">' + formatDate(spec.collected_at) + '</div>';
                    html += '</div>';
                });
            }
            $('#specimenList').html(html);
        }
    }).fail(function() {
        $('#specimenList').html('<div class="text-center py-2 text-muted small">Error loading specimens</div>');
    });
}

// Lab Order Form Submit
$('#labOrderForm').on('submit', function(e) {
    e.preventDefault();
    
    var visitId = $('input[name="visit_id"]').val();
    var patientId = $('input[name="patient_id"]').val();
    var priority = $('#labPriority').val();
    var clinicalIndication = $('#labClinicalIndication').val();
    
    var selectedTests = [];
    $('input[name="selected_tests[]"]:checked').each(function() {
        selectedTests.push({ 
            test_id: $(this).val(),
            name: $(this).data('name') || 'Unknown'
        });
    });
    
    // Get selected panels
    var selectedPanelsData = [];
    selectedPanels.forEach(function(panelId) {
        selectedPanelsData.push({ panel_id: panelId });
    });
    
    if (selectedTests.length === 0 && selectedPanelsData.length === 0) {
        showToast('Please select at least one test or panel', 'error');
        return;
    }
    
    var testList = selectedTests.map(function(t) { return '• ' + t.name; }).join('\n');
    var panelList = selectedPanelsData.map(function(p) { return '• Panel #' + p.panel_id; }).join('\n');
    var totalItems = selectedTests.length + selectedPanelsData.length;
    
    Swal.fire({
        title: 'Confirm Lab Order',
        html: '<div class="text-start"><p><strong>Priority:</strong> ' + priority.toUpperCase() + '</p><p><strong>Items:</strong> ' + totalItems + ' (' + selectedTests.length + ' tests, ' + selectedPanelsData.length + ' panels)</p>' + (testList ? '<div class="bg-light p-2 rounded small mb-2">' + testList + '</div>' : '') + (panelList ? '<div class="bg-light p-2 rounded small">' + panelList + '</div>' : '') + (clinicalIndication ? '<p class="mt-2"><strong>Indication:</strong> ' + escapeHtml(clinicalIndication) + '</p>' : '') + '</div>',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Send to Laboratory',
        confirmButtonColor: '#4361ee',
        cancelButtonText: 'Cancel'
    }).then(function(result) {
        if (result.isConfirmed) {
            showLoading(true);
            $.post(AJAX_URL, {
                action: 'save_lab_order',
                visit_id: visitId,
                patient_id: patientId,
                priority: priority,
                clinical_indication: clinicalIndication,
                tests: JSON.stringify(selectedTests),
                panels: JSON.stringify(selectedPanelsData),
                csrf_token: csrfToken
            }, function(res) {
                showLoading(false);
                if (res.success) {
                    showToast(res.message);
                    $('#labOrderForm')[0].reset();
                    $('input[name="selected_tests[]"]').prop('checked', false);
                    // Clear panel selections
                    document.querySelectorAll('.panel-card').forEach(function(p) {
                        p.classList.remove('selected');
                    });
                    selectedPanels = [];
                    updateLabSelection();
                    fetchLabStatus();
                    if ($('#history').hasClass('active')) fetchOrderHistory();
                    refreshQueue();
                    
                    Swal.fire({
                        icon: 'success',
                        title: 'Lab Order Created',
                        text: 'Order has been sent to the laboratory',
                        confirmButtonText: 'OK',
                        timer: 3000,
                        timerProgressBar: true
                    });
                } else {
                    showToast(res.message || 'Failed to save lab order', 'error');
                }
            }).fail(function() { 
                showLoading(false); 
                showToast('Network error', 'error'); 
            });
        }
    });
});

// Lab test search and filter event listeners
document.addEventListener('DOMContentLoaded', function() {
    var searchInput = document.getElementById('labTestSearch');
    var categoryFilter = document.getElementById('labTestCategoryFilter');
    
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            filterLabTests();
        });
    }
    
    if (categoryFilter) {
        categoryFilter.addEventListener('change', function() {
            filterLabTests();
        });
    }
    
    updateLabSelection();
});

// ============================================
// 9. RADIOLOGY ORDERS
// ============================================

$('#radiologyForm').on('submit', function(e) {
    e.preventDefault();
    var visitId = $('input[name="visit_id"]').val();
    var patientId = $('input[name="patient_id"]').val();
    var serviceIds = $('select[name="service_ids[]"]').val();
    var modality = $('select[name="modality"]').val();
    var bodyPart = $('input[name="body_part"]').val();
    var clinicalHistory = $('textarea[name="clinical_history"]').val();
    var priority = $('select[name="priority"]').val().toLowerCase();
    
    if (!serviceIds || serviceIds.length === 0) {
        showToast('Please select at least one radiology service', 'error');
        return;
    }
    
    // Get service names for confirmation
    var serviceNames = [];
    $('select[name="service_ids[]"] option:selected').each(function() {
        serviceNames.push($(this).text().trim());
    });
    
    Swal.fire({
        title: 'Confirm Radiology Order',
        html: '<div class="text-start"><p><strong>Services:</strong> ' + serviceIds.length + '</p><div class="bg-light p-2 rounded small">' + serviceNames.map(function(s) { return '• ' + s; }).join('\n') + '</div><p class="mt-2"><strong>Priority:</strong> ' + priority.toUpperCase() + '</p>' + (modality ? '<p><strong>Modality:</strong> ' + modality + '</p>' : '') + (bodyPart ? '<p><strong>Body Part:</strong> ' + bodyPart + '</p>' : '') + '</div>',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Send to Radiology',
        confirmButtonColor: '#4361ee',
        cancelButtonText: 'Cancel'
    }).then(function(result) {
        if (result.isConfirmed) {
            showLoading(true);
            $.post(AJAX_URL, {
                action: 'save_radiology_order',
                visit_id: visitId,
                patient_id: patientId,
                service_ids: JSON.stringify(serviceIds.map(function(id) { return parseInt(id); })),
                modality: modality,
                body_part: bodyPart,
                clinical_history: clinicalHistory,
                priority: priority,
                csrf_token: csrfToken
            }, function(res) {
                showLoading(false);
                if (res.success) {
                    showToast(res.message);
                    $('#radiologyForm')[0].reset();
                    if ($('#history').hasClass('active')) fetchOrderHistory();
                    refreshQueue();
                } else {
                    showToast(res.message || 'Failed to save radiology order', 'error');
                }
            }).fail(function() { 
                showLoading(false); 
                showToast('Network error', 'error'); 
            });
        }
    });
});

function fetchRadiologyStatus() {
    if (!currentVisitId) return;
    
    $.post(AJAX_URL, {
        action: 'fetch_radiology_status',
        visit_id: currentVisitId,
        csrf_token: csrfToken
    }, function(res) {
        if (res.success && res.radiology_orders) {
            var html = '';
            if (res.radiology_orders.length === 0) {
                html = '<div class="text-muted small text-center">No radiology orders found</div>';
            } else {
                res.radiology_orders.forEach(function(order) {
                    var statusHtml = getStatusBadge(order.status);
                    var paymentHtml = order.payment_status === 'paid' ? '<span class="badge bg-success">Paid</span>' : '<span class="badge bg-warning">Unpaid</span>';
                    
                    // DICOM/Image link
                    var imageHtml = '';
                    if (order.has_image && order.image_link) {
                        var linkClass = 'btn btn-info btn-sm';
                        var icon = 'fa-image';
                        var label = 'View Image';
                        if (order.image_type === 'dicom') {
                            icon = 'fa-cubes';
                            label = 'DICOM Viewer';
                        } else if (order.image_type === 'url') {
                            icon = 'fa-external-link-alt';
                            label = 'Open Image';
                        }
                        imageHtml = '<a href="' + order.image_link + '" target="_blank" class="' + linkClass + '"><i class="fas ' + icon + '"></i> ' + label + '</a>';
                    }
                    
                    html += '<div class="order-card">';
                    html += '<div class="d-flex justify-content-between align-items-center"><h6>' + escapeHtml(order.order_number) + '</h6>' + statusHtml + '</div>';
                    html += '<div class="small">Service: ' + escapeHtml(order.service_name) + '</div>';
                    html += '<div class="small">Modality: ' + escapeHtml(order.modality || 'N/A') + ' | Body: ' + escapeHtml(order.body_part || 'N/A') + '</div>';
                    html += '<div class="small mt-1">Payment: ' + paymentHtml + ' | Priority: ' + order.priority + '</div>';
                    if (order.scheduled_date) html += '<div class="small">Scheduled: ' + order.scheduled_date + ' ' + (order.scheduled_time || '') + '</div>';
                    if (order.performed_at) html += '<div class="small text-success">Performed: ' + order.performed_at + ' by ' + escapeHtml(order.performed_by || '') + '</div>';
                    if (order.findings) html += '<div class="small text-muted mt-1">Findings: ' + escapeHtml(order.findings) + '</div>';
                    if (order.impression) html += '<div class="small text-muted">Impression: ' + escapeHtml(order.impression) + '</div>';
                    if (order.interpretation_report) html += '<div class="small text-info"><i class="fas fa-file-alt"></i> Report available</div>';
                    if (imageHtml) html += '<div class="mt-2">' + imageHtml + '</div>';
                    html += '</div>';
                });
            }
            $('#radiologyStatusList').html(html);
        } else {
            $('#radiologyStatusList').html('<div class="text-muted small text-center">Error loading radiology orders</div>');
        }
    }).fail(function() {
        $('#radiologyStatusList').html('<div class="text-muted small text-center">Network error loading radiology orders</div>');
    });
}

// ============================================
// 10. PROCEDURE ORDERS
// ============================================

$('#procedureForm').on('submit', function(e) {
    e.preventDefault();
    var visitId = $('input[name="visit_id"]').val();
    var patientId = $('input[name="patient_id"]').val();
    var procedureName = $('input[name="procedure_name"]').val();
    var priority = $('select[name="priority"]').val();
    var scheduledDate = $('input[name="scheduled_date"]').val();
    var clinicalNotes = $('textarea[name="clinical_notes"]').val();
    
    if (!procedureName) {
        showToast('Procedure name is required', 'error');
        return;
    }
    
    showLoading(true);
    $.post(AJAX_URL, {
        action: 'save_procedure_order',
        visit_id: visitId,
        patient_id: patientId,
        procedure_name: procedureName,
        priority: priority,
        scheduled_date: scheduledDate,
        clinical_notes: clinicalNotes,
        csrf_token: csrfToken
    }, function(res) {
        showLoading(false);
        if (res.success) {
            showToast(res.message);
            $('#procedureForm')[0].reset();
            if ($('#history').hasClass('active')) fetchOrderHistory();
            refreshQueue();
        } else {
            showToast(res.message || 'Failed to save procedure order', 'error');
        }
    }).fail(function() { 
        showLoading(false); 
        showToast('Network error', 'error'); 
    });
});

function fetchProcedureStatus() {
    // [Existing code - kept intact]
    if (!currentVisitId) return;
    
    $.post(AJAX_URL, {
        action: 'fetch_procedure_status',
        visit_id: currentVisitId,
        csrf_token: csrfToken
    }, function(res) {
        if (res.success && res.procedure_orders) {
            var html = '';
            if (res.procedure_orders.length === 0) {
                html = '<div class="text-muted small text-center">No procedure orders found</div>';
            } else {
                res.procedure_orders.forEach(function(order) {
                    var statusHtml = getStatusBadge(order.execution_status);
                    var paymentHtml = order.payment_status === 'paid' ? '<span class="badge bg-success">Paid</span>' : '<span class="badge bg-warning">Unpaid</span>';
                    html += '<div class="order-card">';
                    html += '<div class="d-flex justify-content-between align-items-center"><h6>' + escapeHtml(order.procedure_number) + '</h6>' + statusHtml + '</div>';
                    html += '<div class="small">Procedure: ' + escapeHtml(order.procedure_name) + '</div>';
                    html += '<div class="small">Priority: ' + order.priority + ' | Payment: ' + paymentHtml + '</div>';
                    if (order.scheduled_date) html += '<div class="small">Scheduled: ' + order.scheduled_date + '</div>';
                    if (order.performed_at) html += '<div class="small text-success">Performed: ' + order.performed_at + ' by ' + escapeHtml(order.performed_by || '') + '</div>';
                    if (order.completed_at) html += '<div class="small text-success">Completed: ' + order.completed_at + '</div>';
                    if (order.procedure_findings) html += '<div class="small text-muted mt-1">Findings: ' + escapeHtml(order.procedure_findings) + '</div>';
                    if (order.complications) html += '<div class="small text-danger">Complications: ' + escapeHtml(order.complications) + '</div>';
                    if (order.post_procedure_instructions) html += '<div class="small text-muted">Post-op: ' + escapeHtml(order.post_procedure_instructions) + '</div>';
                    html += '</div>';
                });
            }
            $('#procedureStatusList').html(html);
        } else {
            $('#procedureStatusList').html('<div class="text-muted small text-center">Error loading procedure orders</div>');
        }
    }).fail(function() {
        $('#procedureStatusList').html('<div class="text-muted small text-center">Network error loading procedure orders</div>');
    });
}

// ============================================
// 11. LAB RESULTS
// ============================================

function fetchLabResults() {
    if (!currentVisitId && !currentPatientId) {
        showToast('No patient or visit selected', 'error');
        return;
    }
    
    showLoading(true);
    
    var data = {
        action: 'fetch_lab_results',
        csrf_token: csrfToken
    };
    
    if (currentVisitId) data.visit_id = currentVisitId;
    if (currentPatientId) data.patient_id = currentPatientId;
    
    $.post(AJAX_URL, data, function(res) {
        showLoading(false);
        
        if (res.success && res.results) {
            allLabResults = res.results;
            var stats = res.stats || {};
            
            $('#labTotalCount').text(stats.total || 0);
            $('#labUnreadCount').text(stats.unread || 0);
            $('#labCriticalCount').text(stats.critical || 0);
            $('#labAbnormalCount').text(stats.abnormal || 0);
            $('#labReviewedCount').text(stats.reviewed || 0);
            $('#labAmendedCount').text(stats.amended || 0);
            
            if (stats.has_unread) {
                $('#labUnreadIndicator').show().text(stats.unread + ' new');
            } else {
                $('#labUnreadIndicator').hide();
            }
            
            if (stats.has_critical) {
                $('#labCriticalIndicator').show().text(stats.critical + ' critical!');
                $('#labCriticalIndicator').addClass('critical-blink');
            } else {
                $('#labCriticalIndicator').hide();
            }
            
            populateOrderFilter(res.results);
            renderLabResults(res.results);
            
            var unreadIds = res.results.filter(function(r) { return !r.is_reviewed; }).map(function(r) { return r.result_id; });
            
            if (unreadIds.length > 0) {
                $.post(AJAX_URL, {
                    action: 'bulk_mark_viewed',
                    result_ids: JSON.stringify(unreadIds),
                    csrf_token: csrfToken
                }, function(viewRes) {
                    if (viewRes.success) {
                        updateUnreadCounter();
                    }
                });
            }
            
        } else {
            $('#labResultsList').html('<div class="text-center p-4 text-muted"><i class="fas fa-exclamation-circle fa-2x mb-2"></i><p>' + escapeHtml(res.message || 'Error loading lab results') + '</p><button class="btn btn-outline-primary btn-sm mt-2" onclick="fetchLabResults()">Retry</button></div>');
        }
    }).fail(function(xhr) {
        showLoading(false);
        console.error('Lab results fetch error:', xhr);
        $('#labResultsList').html('<div class="text-center p-4 text-muted"><i class="fas fa-wifi-slash fa-2x mb-2"></i><p>Network error loading lab results</p><button class="btn btn-outline-primary btn-sm mt-2" onclick="fetchLabResults()">Retry</button></div>');
    });
}

function populateOrderFilter(results) {
    var orders = {};
    results.forEach(function(r) {
        if (!orders[r.lab_order_id]) {
            orders[r.lab_order_id] = r.order_number;
        }
    });
    
    var select = document.getElementById('labResultOrderFilter');
    if (!select) return;
    var currentVal = select.value;
    select.innerHTML = '<option value="">All Orders</option>';
    Object.values(orders).forEach(function(order) {
        select.innerHTML += '<option value="' + order + '">' + order + '</option>';
    });
    if (currentVal) select.value = currentVal;
}

function renderLabResults(results) {
    var filterType = document.getElementById('labResultFilter') ? document.getElementById('labResultFilter').value : 'all';
    var filterOrder = document.getElementById('labResultOrderFilter') ? document.getElementById('labResultOrderFilter').value : '';
    var searchTerm = document.getElementById('labResultSearch') ? document.getElementById('labResultSearch').value.toLowerCase() : '';
    
    var filtered = results.filter(function(r) {
        if (filterType === 'unread' && r.is_reviewed) return false;
        if (filterType === 'critical' && r.is_critical != 1) return false;
        if (filterType === 'abnormal' && (r.is_abnormal != 1 || r.is_critical == 1)) return false;
        if (filterType === 'normal' && (r.is_abnormal == 1 || r.is_critical == 1)) return false;
        if (filterType === 'reviewed' && !r.is_reviewed) return false;
        if (filterType === 'amended' && r.is_amended != 1) return false;
        
        if (filterOrder && r.order_number !== filterOrder) return false;
        
        if (searchTerm && !r.test_name.toLowerCase().includes(searchTerm) && 
            !r.test_category.toLowerCase().includes(searchTerm)) return false;
        
        return true;
    });
    
    var countDisplay = document.getElementById('resultCountDisplay');
    if (countDisplay) countDisplay.textContent = filtered.length + ' results';
    
    if (filtered.length === 0) {
        $('#labResultsList').html('<div class="text-center p-4 text-muted"><i class="fas fa-filter fa-2x mb-2 opacity-25"></i><p>No results match your filters</p><button class="btn btn-outline-secondary btn-sm" onclick="resetLabFilters()">Reset Filters</button></div>');
        return;
    }
    
    var grouped = {};
    filtered.forEach(function(r) {
        var key = r.lab_order_id;
        if (!grouped[key]) {
            grouped[key] = {
                order_id: r.lab_order_id,
                order_number: r.order_number,
                order_date: r.order_date,
                priority: r.priority,
                order_status: r.order_status,
                payment_status: r.payment_status,
                clinical_indication: r.clinical_indication,
                specimen_type: r.specimen_type,
                specimen_collected_at: r.specimen_collected_at,
                results_ready_at: r.results_ready_at,
                completed_at: r.completed_at,
                patient_name: r.patient_name,
                mr_number: r.mr_number,
                age: r.age,
                gender: r.gender,
                ordering_doctor: r.ordering_doctor_name,
                specimen_data: r.specimen_data || [],
                results: []
            };
        }
        grouped[key].results.push(r);
    });
    
    var html = '';
    Object.values(grouped).forEach(function(order) {
        var orderCritical = order.results.filter(function(r) { return r.is_critical == 1 && !r.is_reviewed; }).length;
        var orderUnread = order.results.filter(function(r) { return !r.is_reviewed; }).length;
        var orderTotal = order.results.length;
        var orderReviewed = orderTotal - orderUnread;
        var orderAmended = order.results.filter(function(r) { return r.is_amended == 1; }).length;
        
        html += '<div class="lab-order-card mb-3">';
        html += '<div class="lab-order-header d-flex justify-content-between align-items-center p-3" onclick="toggleOrderDetails(\'order_' + order.order_id + '\')">';
        html += '<div><div class="d-flex align-items-center flex-wrap gap-2"><h5 class="mb-0"><i class="fas fa-flask me-2"></i>' + escapeHtml(order.order_number) + '</h5>' + (order.order_status === 'completed' ? '<span class="badge bg-success">Completed</span>' : '') + (order.order_status === 'verified' ? '<span class="badge bg-primary">Verified</span>' : '') + (order.priority === 'stat' ? '<span class="badge bg-danger">STAT</span>' : '') + (order.priority === 'urgent' ? '<span class="badge bg-warning">Urgent</span>' : '') + (orderCritical > 0 ? '<span class="badge bg-danger pulse"><i class="fas fa-exclamation-triangle"></i> ' + orderCritical + ' Critical</span>' : '') + (orderUnread > 0 ? '<span class="badge bg-info">' + orderUnread + ' New</span>' : '') + (orderAmended > 0 ? '<span class="badge bg-warning text-dark"><i class="fas fa-edit"></i> ' + orderAmended + ' Amended</span>' : '') + '</div>';
        html += '<div class="small text-muted mt-1"><span><i class="far fa-calendar-alt"></i> ' + formatDate(order.order_date) + '</span>' + (order.specimen_collected_at ? ' | <span class="text-success"><i class="fas fa-vial"></i> Collected: ' + formatDate(order.specimen_collected_at) + '</span>' : '') + (order.results_ready_at ? ' | <span class="text-info"><i class="fas fa-check-circle"></i> Results: ' + formatDate(order.results_ready_at) + '</span>' : '') + '<span class="ms-2">| ' + orderReviewed + '/' + orderTotal + ' reviewed</span></div></div>';
        html += '<div><span class="badge bg-light text-dark me-2">' + order.results.length + ' tests</span><button class="btn btn-outline-primary btn-sm" onclick="event.stopPropagation(); toggleOrderDetails(\'order_' + order.order_id + '\')"><i class="fas fa-chevron-down"></i></button></div>';
        html += '</div>';
        
        html += '<div class="patient-summary p-2 bg-light border-bottom small d-flex flex-wrap gap-3">';
        html += '<span><strong>' + escapeHtml(order.patient_name) + '</strong></span>';
        html += '<span class="text-muted">MRN: ' + escapeHtml(order.mr_number) + '</span>';
        html += '<span class="text-muted">Age: ' + (order.age || 'N/A') + '</span>';
        html += '<span class="text-muted">Gender: ' + (order.gender || 'N/A') + '</span>';
        html += '<span class="text-muted">Ordered by: ' + escapeHtml(order.ordering_doctor || 'N/A') + '</span>';
        if (order.clinical_indication) html += '<span class="text-muted">Indication: ' + escapeHtml(order.clinical_indication) + '</span>';
        html += '</div>';
        
        html += '<div class="lab-results-content p-3" id="order_' + order.order_id + '">';
        order.results.forEach(function(result) {
            html += buildResultCard(result);
        });
        html += '</div>';
        
        if (order.specimen_data && order.specimen_data.length > 0) {
            html += '<div class="specimen-info p-2 bg-light border-top small d-flex flex-wrap gap-3"><i class="fas fa-vial text-muted"></i>';
            order.specimen_data.forEach(function(s) {
                html += '<span><span class="fw-semibold">' + escapeHtml(s.barcode) + '</span>' + escapeHtml(s.specimen_type) + (s.specimen_volume ? '(' + escapeHtml(s.specimen_volume) + ')' : '') + '<span class="badge ' + (s.status === 'received' ? 'bg-info' : s.status === 'processing' ? 'bg-warning' : s.status === 'completed' ? 'bg-success' : 'bg-secondary') + '">' + escapeHtml(s.status || 'N/A') + '</span>' + (s.is_hemolyzed || s.is_icteric || s.is_lipemic || s.quality_issues ? '<span class="badge bg-warning text-dark"><i class="fas fa-exclamation-triangle"></i> QC Issue</span>' : '') + '</span>';
            });
            html += '</div>';
        }
        
        html += '</div>';
    });
    
    $('#labResultsList').html(html);
}

function buildResultCard(result) {
    var statusClass = 'normal';
    var statusIcon = 'fa-check-circle';
    var statusColor = 'success';
    var statusText = 'Normal';
    
    if (result.is_critical == 1) {
        statusClass = 'critical';
        statusIcon = 'fa-exclamation-triangle';
        statusColor = 'danger';
        statusText = '⚠️ CRITICAL';
    } else if (result.is_abnormal == 1) {
        statusClass = 'abnormal';
        statusIcon = 'fa-exclamation-circle';
        statusColor = 'warning';
        statusText = 'Abnormal';
    }
    
    var isReviewed = result.is_reviewed;
    var reviewIcon = isReviewed ? 'fa-check-double' : 'fa-eye';
    var reviewColor = isReviewed ? 'success' : 'info';
    var reviewText = isReviewed ? 'Reviewed' : 'Pending Review';
    
    // Amended flag
    var amendedHtml = '';
    if (result.is_amended == 1) {
        amendedHtml = '<span class="badge bg-warning text-dark ms-1" title="Amended at ' + (result.amended_at ? formatDate(result.amended_at) : 'N/A') + '"><i class="fas fa-edit"></i> Amended</span>';
        if (result.amendment_reason) {
            amendedHtml += '<span class="badge bg-light text-dark ms-1" title="' + escapeHtml(result.amendment_reason) + '">?</span>';
        }
    }
    
    var valueDisplay = 'N/A';
    if (result.result_numeric !== null && result.result_numeric !== '') {
        valueDisplay = result.result_numeric;
        if (result.result_unit) valueDisplay += ' ' + result.result_unit;
    } else if (result.result_text) {
        valueDisplay = result.result_text;
    }
    
    var refDisplay = 'N/A';
    if (result.reference_range_text) {
        refDisplay = result.reference_range_text;
    } else if (result.reference_range_low !== null && result.reference_range_high !== null) {
        refDisplay = result.reference_range_low + ' - ' + result.reference_range_high;
        if (result.result_unit) refDisplay += ' ' + result.result_unit;
    }
    
    var componentsHtml = '';
    if (result.test_components && result.test_components.length > 0) {
        componentsHtml = '<div class="test-components mt-2"><small class="text-muted">Components:</small><div class="component-grid row g-1 mt-1">';
        result.test_components.forEach(function(comp) {
            componentsHtml += '<div class="col-md-6 col-lg-4"><span class="badge bg-light text-dark w-100 text-start p-2 border ' + (comp.is_critical ? 'border-danger' : comp.is_abnormal ? 'border-warning' : 'border-light') + '"><span class="fw-semibold">' + escapeHtml(comp.test_component_name) + '</span>' + (comp.is_critical ? ' 🔴' : comp.is_abnormal ? ' 🟡' : '') + '<span class="float-end">' + escapeHtml(comp.result_numeric || comp.result_text || 'N/A') + (comp.result_unit ? ' ' + escapeHtml(comp.result_unit) : '') + '</span></span></div>';
        });
        componentsHtml += '</div></div>';
    }
    
    var specimenInfo = '';
    if (result.specimen_data && result.specimen_data.length > 0) {
        var spec = result.specimen_data[0];
        specimenInfo = '<div class="small text-muted mt-1"><i class="fas fa-vial me-1"></i> Specimen: ' + escapeHtml(spec.specimen_type) + (spec.barcode ? '(' + escapeHtml(spec.barcode) + ')' : '') + (spec.status ? '| ' + escapeHtml(spec.status) : '') + '</div>';
    }
    
    var html = '<div class="result-card ' + statusClass + ' p-3 mb-2 rounded border-start border-4 border-' + statusColor + '">';
    html += '<div class="row align-items-center">';
    html += '<div class="col-md-3"><div class="d-flex align-items-center"><i class="fas ' + statusIcon + ' text-' + statusColor + ' me-2"></i><div><strong class="result-name">' + escapeHtml(result.test_name) + '</strong><div class="small text-muted">' + escapeHtml(result.test_category || 'N/A') + '</div>' + amendedHtml + '</div></div></div>';
    html += '<div class="col-md-3"><div class="result-value text-center"><strong class="' + (result.is_critical ? 'text-danger' : result.is_abnormal ? 'text-warning' : 'text-success') + '">' + escapeHtml(valueDisplay) + '</strong><div class="small text-muted">Ref: ' + escapeHtml(refDisplay) + '</div></div></div>';
    html += '<div class="col-md-3"><div class="result-status text-center"><span class="badge bg-' + statusColor + '">' + statusText + '</span><div class="mt-1"><span class="badge bg-' + reviewColor + '"><i class="fas ' + reviewIcon + '"></i> ' + reviewText + '</span>' + (result.verified_by_name ? '<span class="badge bg-secondary ms-1" title="Verified by ' + escapeHtml(result.verified_by_name) + '"><i class="fas fa-user-check"></i></span>' : '') + '</div>' + (result.verified_at ? '<div class="small text-muted mt-1">Verified: ' + formatDate(result.verified_at) + '</div>' : '') + '</div></div>';
    html += '<div class="col-md-3 text-end">';
    if (!isReviewed) {
        html += '<button class="btn btn-primary btn-sm" onclick="reviewLabResult(' + result.result_id + ')"><i class="fas fa-check"></i> Review</button>';
    } else {
        html += '<button class="btn btn-outline-secondary btn-sm" onclick="viewResultDetails(' + result.result_id + ')"><i class="fas fa-file-alt"></i> Details</button>';
    }
    html += '<button class="btn btn-outline-info btn-sm" onclick="printResult(' + result.result_id + ')" title="Print result"><i class="fas fa-print"></i></button>';
    if (result.is_amended == 1) {
        html += '<button class="btn btn-outline-warning btn-sm" onclick="viewAmendHistory(' + result.result_id + ')" title="View amendment history"><i class="fas fa-history"></i></button>';
    }
    html += '</div></div>';
    
    html += componentsHtml;
    
    if (result.technician_notes) {
        html += '<div class="small text-muted mt-1"><i class="fas fa-user-nurse"></i> Tech Notes: ' + escapeHtml(result.technician_notes) + '</div>';
    }
    
    if (result.critical_flag_notes && result.is_critical) {
        html += '<div class="critical-notes mt-1 p-2 bg-danger bg-opacity-10 rounded"><small class="text-danger"><i class="fas fa-exclamation-circle"></i> ' + escapeHtml(result.critical_flag_notes) + '</small></div>';
    }
    
    if (result.is_amended == 1 && result.amended_at) {
        html += '<div class="small text-warning mt-1"><i class="fas fa-edit"></i> Amended: ' + formatDate(result.amended_at) + (result.amendment_reason ? ' - Reason: ' + escapeHtml(result.amendment_reason) : '') + '</div>';
    }
    
    html += specimenInfo;
    
    if (result.doctor_reviewed_by && isReviewed) {
        html += '<div class="small text-muted mt-1"><i class="fas fa-user-md"></i> Reviewed by: Dr. ' + escapeHtml(result.reviewed_by_name) + (result.doctor_reviewed_at ? ' at ' + formatDate(result.doctor_reviewed_at) : '') + '</div>';
    }
    
    html += '<div class="small text-muted mt-1"><i class="far fa-clock"></i> Result recorded: ' + formatDate(result.result_created_at || result.created_at) + '</div>';
    html += '</div>';
    
    return html;
}

function filterLabResults() {
    renderLabResults(allLabResults);
}

function resetLabFilters() {
    var filter = document.getElementById('labResultFilter');
    var order = document.getElementById('labResultOrderFilter');
    var search = document.getElementById('labResultSearch');
    if (filter) filter.value = 'all';
    if (order) order.value = '';
    if (search) search.value = '';
    renderLabResults(allLabResults);
}

function toggleOrderDetails(orderId) {
    var content = document.getElementById(orderId);
    if (content) {
        var isHidden = content.style.display === 'none';
        content.style.display = isHidden ? 'block' : 'none';
        var card = content.closest('.lab-order-card');
        if (card) {
            var btn = card.querySelector('.lab-order-header .btn');
            if (btn) {
                btn.innerHTML = isHidden ? '<i class="fas fa-chevron-up"></i>' : '<i class="fas fa-chevron-down"></i>';
            }
        }
    }
}

function updateUnreadCounter() {
    $.post(AJAX_URL, {
        action: 'fetch_lab_status',
        visit_id: currentVisitId,
        csrf_token: csrfToken
    }, function(res) {
        if (res.success && res.labs) {
            var unreadTotal = 0;
            var criticalTotal = 0;
            res.labs.forEach(function(lab) {
                unreadTotal += parseInt(lab.unread_results) || 0;
                criticalTotal += parseInt(lab.critical_unread) || 0;
            });
            
            $('#labUnreadCount').text(unreadTotal);
            $('#labCriticalCount').text(criticalTotal);
            
            if (unreadTotal > 0) {
                $('#labUnreadIndicator').show().text(unreadTotal + ' new');
            } else {
                $('#labUnreadIndicator').hide();
            }
            
            if (criticalTotal > 0) {
                $('#labCriticalIndicator').show().text(criticalTotal + ' critical!');
            } else {
                $('#labCriticalIndicator').hide();
            }
        }
    });
}

function viewResultDetails(resultId) {
    var result = allLabResults.find(function(r) { return r.result_id === resultId; });
    if (!result) {
        showToast('Result not found', 'error');
        return;
    }
    
    var valueDisplay = 'N/A';
    if (result.result_numeric !== null && result.result_numeric !== '') {
        valueDisplay = result.result_numeric;
        if (result.result_unit) valueDisplay += ' ' + result.result_unit;
    } else if (result.result_text) {
        valueDisplay = result.result_text;
    }
    
    var refDisplay = 'N/A';
    if (result.reference_range_text) {
        refDisplay = result.reference_range_text;
    } else if (result.reference_range_low !== null && result.reference_range_high !== null) {
        refDisplay = result.reference_range_low + ' - ' + result.reference_range_high;
        if (result.result_unit) refDisplay += ' ' + result.result_unit;
    }
    
    Swal.fire({
        title: 'Lab Result Details',
        html: '<div class="text-start"><div class="mb-3"><h5>' + escapeHtml(result.test_name) + '</h5><p class="text-muted small">Order #' + escapeHtml(result.order_number) + ' | ' + formatDate(result.order_date) + '</p></div><div class="row mb-2"><div class="col-6"><label class="text-muted small">Result</label><div class="fw-bold ' + (result.is_critical ? 'text-danger' : result.is_abnormal ? 'text-warning' : '') + '">' + escapeHtml(valueDisplay) + '</div></div><div class="col-6"><label class="text-muted small">Reference Range</label><div>' + escapeHtml(refDisplay) + '</div></div></div><div class="row mb-2"><div class="col-4"><label class="text-muted small">Status</label><div>' + (result.is_critical ? '<span class="badge bg-danger">Critical</span>' : '') + (result.is_abnormal && !result.is_critical ? '<span class="badge bg-warning">Abnormal</span>' : '') + (!result.is_abnormal && !result.is_critical ? '<span class="badge bg-success">Normal</span>' : '') + (result.is_amended ? ' <span class="badge bg-warning text-dark">Amended</span>' : '') + '</div></div><div class="col-4"><label class="text-muted small">Review Status</label><div>' + (result.is_reviewed ? '<span class="text-success">Reviewed by Dr. ' + escapeHtml(result.reviewed_by_name) + '</span>' : '<span class="text-warning">Pending Review</span>') + '</div></div><div class="col-4"><label class="text-muted small">Verification</label><div>' + (result.verified_by_name ? '<span class="text-info">Verified by ' + escapeHtml(result.verified_by_name) + '</span>' : '<span class="text-muted">Not verified</span>') + '</div></div></div><div class="row mb-2"><div class="col-6"><label class="text-muted small">Patient</label><div>' + escapeHtml(result.patient_name) + ' (' + escapeHtml(result.mr_number) + ')</div></div><div class="col-6"><label class="text-muted small">Ordering Doctor</label><div>' + escapeHtml(result.ordering_doctor_name || 'N/A') + '</div></div></div>' + (result.test_components && result.test_components.length > 0 ? '<div class="mb-2"><label class="text-muted small">Test Components</label><div class="component-list bg-light p-2 rounded">' + result.test_components.map(function(c) { return '<div class="d-flex justify-content-between border-bottom py-1"><span>' + escapeHtml(c.test_component_name) + '</span><span class="' + (c.is_critical ? 'text-danger' : c.is_abnormal ? 'text-warning' : '') + '">' + escapeHtml(c.result_numeric || c.result_text || 'N/A') + (c.result_unit ? ' ' + escapeHtml(c.result_unit) : '') + '</span></div>'; }).join('') + '</div></div>' : '') + (result.technician_notes ? '<div class="mb-2"><label class="text-muted small">Technician Notes</label><div class="p-2 bg-light rounded">' + escapeHtml(result.technician_notes) + '</div></div>' : '') + (result.critical_flag_notes ? '<div class="mb-2"><label class="text-muted small text-danger">Critical Notes</label><div class="p-2 bg-danger bg-opacity-10 rounded">' + escapeHtml(result.critical_flag_notes) + '</div></div>' : '') + (result.specimen_data && result.specimen_data.length > 0 ? '<div><label class="text-muted small">Specimen Information</label>' + result.specimen_data.map(function(s) { return '<div class="p-2 bg-light rounded small mb-1"><span class="fw-bold">' + escapeHtml(s.barcode) + '</span>' + escapeHtml(s.specimen_type) + (s.specimen_volume ? '(' + escapeHtml(s.specimen_volume) + ')' : '') + '<span class="text-muted">| ' + escapeHtml(s.status || 'N/A') + '</span>' + (s.collected_by ? '| Collected by: ' + escapeHtml(s.collected_by) : '') + (s.collected_at ? '| ' + formatDate(s.collected_at) : '') + '</div>'; }).join('') + '</div>' : '') + '<div class="small text-muted mt-2"><div>Recorded: ' + formatDate(result.result_created_at || result.created_at) + '</div>' + (result.result_updated_at ? '<div>Last Updated: ' + formatDate(result.result_updated_at) + '</div>' : '') + (result.is_amended && result.amended_at ? '<div class="text-warning">Amended: ' + formatDate(result.amended_at) + (result.amendment_reason ? ' - Reason: ' + escapeHtml(result.amendment_reason) : '') + '</div>' : '') + '</div></div>',
        width: 700,
        confirmButtonText: 'Close',
        confirmButtonColor: '#4361ee',
        showCancelButton: !result.is_reviewed,
        cancelButtonText: 'Review Now',
        cancelButtonColor: '#06d6a0',
        preConfirm: function() {
            if (!result.is_reviewed) {
                reviewLabResult(resultId);
            }
        }
    });
}

function viewAmendHistory(resultId) {
    var result = allLabResults.find(function(r) { return r.result_id === resultId; });
    if (!result || result.is_amended != 1) {
        showToast('No amendment history found', 'info');
        return;
    }
    
    Swal.fire({
        title: 'Result Amendment History',
        html: '<div class="text-start"><p><strong>Test:</strong> ' + escapeHtml(result.test_name) + '</p><p><strong>Order:</strong> ' + escapeHtml(result.order_number) + '</p><div class="alert alert-warning"><i class="fas fa-edit"></i> This result was amended on ' + formatDate(result.amended_at) + (result.amendment_reason ? '<br><strong>Reason:</strong> ' + escapeHtml(result.amendment_reason) : '') + '</div><p class="text-muted small">Original result: ' + escapeHtml(result.result_numeric || result.result_text || 'N/A') + ' ' + escapeHtml(result.result_unit || '') + '</p><p class="text-muted small">Current result: <strong>' + escapeHtml(result.result_numeric || result.result_text || 'N/A') + ' ' + escapeHtml(result.result_unit || '') + '</strong></p></div>',
        width: 500,
        confirmButtonText: 'Close',
        confirmButtonColor: '#4361ee'
    });
}

function printResult(resultId) {
    // [Existing print function - kept intact]
    var result = allLabResults.find(function(r) { return r.result_id === resultId; });
    if (!result) {
        showToast('Result not found', 'error');
        return;
    }
    
    var valueDisplay = 'N/A';
    if (result.result_numeric !== null && result.result_numeric !== '') {
        valueDisplay = result.result_numeric;
        if (result.result_unit) valueDisplay += ' ' + result.result_unit;
    } else if (result.result_text) {
        valueDisplay = result.result_text;
    }
    
    var printWindow = window.open('', '_blank', 'width=800,height=600');
    printWindow.document.write('<!DOCTYPE html><html><head><title>Lab Result - ' + escapeHtml(result.test_name) + '</title><style>body { font-family: Arial, sans-serif; padding: 40px; } .header { border-bottom: 2px solid #333; padding-bottom: 10px; margin-bottom: 20px; } .result { font-size: 24px; font-weight: bold; margin: 20px 0; } .row { display: flex; margin: 8px 0; } .label { font-weight: bold; width: 150px; } .critical { color: #dc3545; } .abnormal { color: #fd7e14; } .normal { color: #198754; } .footer { margin-top: 40px; border-top: 1px solid #ccc; padding-top: 10px; font-size: 12px; color: #666; } .badge { display: inline-block; padding: 3px 10px; border-radius: 12px; font-size: 12px; font-weight: bold; } .badge-danger { background: #dc3545; color: white; } .badge-warning { background: #fd7e14; color: white; } .badge-success { background: #198754; color: white; }</style></head><body>');
    printWindow.document.write('<div class="header"><h2>Laboratory Result</h2><p>Order: ' + escapeHtml(result.order_number) + ' | Date: ' + formatDate(result.order_date) + '</p></div>');
    printWindow.document.write('<div class="row"><span class="label">Patient:</span> ' + escapeHtml(result.patient_name) + ' (' + escapeHtml(result.mr_number) + ')</div>');
    printWindow.document.write('<div class="row"><span class="label">Test:</span> ' + escapeHtml(result.test_name) + '</div>');
    printWindow.document.write('<div class="row"><span class="label">Category:</span> ' + escapeHtml(result.test_category || 'N/A') + '</div>');
    printWindow.document.write('<div class="result ' + (result.is_critical ? 'critical' : result.is_abnormal ? 'abnormal' : 'normal') + '">' + escapeHtml(valueDisplay) + '</div>');
    printWindow.document.write('<div class="row"><span class="label">Reference Range:</span> ' + escapeHtml(result.reference_range_text || result.reference_display || 'N/A') + '</div>');
    printWindow.document.write('<div class="row"><span class="label">Status:</span> ' + (result.is_critical ? '<span class="badge badge-danger">Critical</span>' : '') + (result.is_abnormal && !result.is_critical ? '<span class="badge badge-warning">Abnormal</span>' : '') + (!result.is_abnormal && !result.is_critical ? '<span class="badge badge-success">Normal</span>' : '') + (result.is_amended ? ' <span class="badge" style="background: #fd7e14; color: white;">Amended</span>' : '') + '</div>');
    printWindow.document.write('<div class="row"><span class="label">Reviewed:</span> ' + (result.is_reviewed ? 'Yes' : 'No') + '</div>');
    if (result.technician_notes) printWindow.document.write('<div class="row"><span class="label">Tech Notes:</span> ' + escapeHtml(result.technician_notes) + '</div>');
    if (result.critical_flag_notes) printWindow.document.write('<div class="row"><span class="label">Critical Notes:</span> ' + escapeHtml(result.critical_flag_notes) + '</div>');
    if (result.is_amended) {
        printWindow.document.write('<div class="row"><span class="label">Amended:</span> ' + formatDate(result.amended_at) + (result.amendment_reason ? ' - Reason: ' + escapeHtml(result.amendment_reason) : '') + '</div>');
    }
    if (result.verified_by_name) printWindow.document.write('<div class="row"><span class="label">Verified By:</span> ' + escapeHtml(result.verified_by_name) + '</div>');
    if (result.doctor_reviewed_by && result.is_reviewed) printWindow.document.write('<div class="row"><span class="label">Reviewed By:</span> Dr. ' + escapeHtml(result.reviewed_by_name) + '</div>');
    printWindow.document.write('<div class="footer"><p>Report generated: ' + new Date().toLocaleString() + '</p><p>' + escapeHtml(result.order_number) + ' - ' + escapeHtml(result.test_name) + '</p></div>');
    printWindow.document.write('<script>window.onload = function() { window.print(); }<\/script></body></html>');
    printWindow.document.close();
}

function exportLabResults() {
    // [Existing export function - kept intact]
    if (!allLabResults || allLabResults.length === 0) {
        showToast('No results to export', 'error');
        return;
    }
    
    var csv = 'Order,Test,Category,Result,Unit,Reference Range,Status,Amended,Reviewed,Verified,Date\n';
    allLabResults.forEach(function(r) {
        var value = r.result_numeric || r.result_text || 'N/A';
        var ref = r.reference_range_text || r.reference_display || 'N/A';
        var status = r.is_critical ? 'Critical' : r.is_abnormal ? 'Abnormal' : 'Normal';
        var amended = r.is_amended ? 'Yes' : 'No';
        csv += '"' + r.order_number + '","' + r.test_name + '","' + (r.test_category || '') + '","' + value + '","' + (r.result_unit || '') + '","' + ref + '","' + status + '","' + amended + '","' + (r.is_reviewed ? 'Yes' : 'No') + '","' + (r.verified_by_name || 'No') + '","' + (r.result_created_at || r.created_at || '') + '"\n';
    });
    
    var blob = new Blob([csv], { type: 'text/csv' });
    var url = URL.createObjectURL(blob);
    var a = document.createElement('a');
    a.href = url;
    a.download = 'lab_results_' + new Date().toISOString().slice(0,10) + '.csv';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
    showToast('Export complete!');
}

function reviewLabResult(resultId) {
    // [Existing review function - kept intact]
    Swal.fire({
        title: 'Review Lab Result',
        html: '<div class="form-group text-start"><label>Clinical Notes / Interpretation</label><textarea id="reviewNotes" class="form-control" rows="4" placeholder="Add clinical notes or interpretation..."></textarea></div>',
        showCancelButton: true,
        confirmButtonText: 'Confirm Review',
        confirmButtonColor: '#06d6a0',
        cancelButtonText: 'Cancel',
        preConfirm: function() {
            var notes = document.getElementById('reviewNotes').value;
            return { notes: notes };
        }
    }).then(function(result) {
        if (result.isConfirmed) {
            var notes = result.value.notes || '';
            showLoading(true);
            
            $.ajax({
                url: AJAX_URL,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'review_lab_result',
                    result_id: resultId,
                    notes: notes,
                    csrf_token: csrfToken
                },
                success: function(res) {
                    showLoading(false);
                    if (res.success) {
                        showToast('Result reviewed successfully');
                        fetchLabResults();
                        refreshQueue();
                        if (currentVisitId) {
                            fetchLabStatus();
                        }
                    } else {
                        showToast(res.message || 'Failed to review result', 'error');
                    }
                },
                error: function(xhr) {
                    showLoading(false);
                    var errorMsg = 'Network error. Please try again.';
                    if (xhr.responseJSON && xhr.responseJSON.message) {
                        errorMsg = xhr.responseJSON.message;
                    }
                    showToast(errorMsg, 'error');
                    console.error('Review error:', xhr);
                }
            });
        }
    });
}

function fetchCriticalAlerts() {
    // [Existing function - kept intact]
    if (!currentVisitId) return;
    
    $.post(AJAX_URL, {
        action: 'fetch_critical_alerts',
        visit_id: currentVisitId,
        csrf_token: csrfToken
    }, function(res) {
        if (res.success && res.alerts) {
            if (res.alerts.length > 0) {
                var alertHtml = '<div class="alert alert-danger critical-alert-banner"><div class="d-flex align-items-start"><i class="fas fa-exclamation-triangle fa-2x me-3 text-danger"></i><div class="flex-grow-1"><strong class="text-danger">Critical Alerts</strong><div class="mt-2">';
                res.alerts.forEach(function(alert) {
                    alertHtml += '<div class="d-flex justify-content-between align-items-center border-bottom border-danger border-opacity-25 py-2"><div><strong>' + escapeHtml(alert.test_name) + '</strong><span class="badge bg-danger ms-2">' + escapeHtml(alert.result_numeric || alert.result_text || 'N/A') + ' ' + escapeHtml(alert.result_unit || '') + '</span><span class="text-muted small ms-2">Ref: ' + escapeHtml(alert.reference_range_low || '') + ' - ' + escapeHtml(alert.reference_range_high || '') + '</span><div class="small text-muted">' + escapeHtml(alert.order_number) + ' | ' + formatDate(alert.order_date) + '</div></div><button class="btn btn-danger btn-sm" onclick="markCriticalAlertRead(' + alert.id + ')"><i class="fas fa-check"></i> Acknowledge</button></div>';
                });
                alertHtml += '</div></div><button class="btn btn-close" onclick="$(this).closest(\'.critical-alert-banner\').hide();"></button></div></div>';
                
                $('.critical-alert-banner').remove();
                $('#labResultsContainer').prepend(alertHtml);
                showToast(res.alerts.length + ' critical alert(s) found!', 'error');
            } else {
                showToast('No critical alerts found', 'success');
            }
        }
    });
}

function markCriticalAlertRead(alertId) {
    // [Existing function - kept intact]
    showLoading(true);
    $.post(AJAX_URL, {
        action: 'mark_alert_read',
        alert_id: alertId,
        csrf_token: csrfToken
    }, function(res) {
        showLoading(false);
        if (res.success) {
            showToast('Alert acknowledged');
            fetchCriticalAlerts();
            fetchLabResults();
            refreshQueue();
        } else {
            showToast(res.message || 'Failed to acknowledge alert', 'error');
        }
    }).fail(function() { 
        showLoading(false); 
        showToast('Network error', 'error'); 
    });
}

// ============================================
// 12. TREND ANALYSIS
// ============================================

function openTrendModal() {
    if (!currentPatientId) {
        showToast('No patient selected', 'error');
        return;
    }
    
    // Get unique test names from results
    var testNames = [];
    var testIds = [];
    allLabResults.forEach(function(r) {
        if (!testNames.includes(r.test_name) && r.test_name) {
            testNames.push(r.test_name);
            testIds.push(r.test_id || 0);
        }
    });
    
    if (testNames.length === 0) {
        showToast('No test results available for trend analysis', 'info');
        return;
    }
    
    var optionsHtml = '<option value="">Select a test...</option>';
    testNames.forEach(function(name, index) {
        optionsHtml += '<option value="' + (testIds[index] || 0) + '" data-name="' + escapeHtml(name) + '">' + escapeHtml(name) + '</option>';
    });
    
    Swal.fire({
        title: 'Trend Analysis',
        html: '<div class="text-start"><div class="form-group"><label>Select Test <span class="text-danger">*</span></label><select id="trendTestSelect" class="form-control">' + optionsHtml + '</select></div><div id="trendResults" class="mt-3"><div class="text-muted text-center">Select a test to view historical trends</div></div></div>',
        width: 800,
        showCancelButton: true,
        confirmButtonText: 'View Trend',
        cancelButtonText: 'Close',
        preConfirm: function() {
            var select = document.getElementById('trendTestSelect');
            return {
                test_id: select.value,
                test_name: select.options[select.selectedIndex] ? select.options[select.selectedIndex].text : ''
            };
        },
        didOpen: function() {
            var select = document.getElementById('trendTestSelect');
            if (select) {
                select.addEventListener('change', function() {
                    var testId = this.value;
                    var testName = this.options[this.selectedIndex] ? this.options[this.selectedIndex].text : '';
                    if (testId) {
                        fetchTrendData(testId, testName);
                    }
                });
            }
        }
    }).then(function(result) {
        if (result.isConfirmed && result.value && result.value.test_id) {
            // Already handled by the change event
        }
    });
}

function fetchTrendData(testId, testName) {
    if (!currentPatientId || !testId) return;
    
    showLoading(true);
    $.post(AJAX_URL, {
        action: 'fetch_lab_trends',
        patient_id: currentPatientId,
        test_id: testId,
        test_name: testName || '',
        csrf_token: csrfToken
    }, function(res) {
        showLoading(false);
        var container = document.getElementById('trendResults');
        if (!container) return;
        
        if (res.success && res.trends) {
            var trends = res.trends;
            var stats = res.stats || {};
            
            if (trends.length === 0) {
                container.innerHTML = '<div class="text-muted text-center">No historical data found for this test</div>';
                return;
            }
            
            // Build trend table
            var html = '<div class="table-responsive"><table class="table table-sm table-bordered"><thead><tr><th>Date</th><th>Value</th><th>Reference Range</th><th>Status</th><th>Order</th></tr></thead><tbody>';
            
            trends.forEach(function(t) {
                var value = t.result_numeric || t.result_text || 'N/A';
                var unit = t.result_unit || '';
                var ref = t.reference_range_text || (t.reference_range_low && t.reference_range_high ? t.reference_range_low + ' - ' + t.reference_range_high + ' ' + unit : 'N/A');
                var statusClass = t.is_critical ? 'danger' : t.is_abnormal ? 'warning' : 'success';
                var statusText = t.is_critical ? '⚠️ Critical' : t.is_abnormal ? 'Abnormal' : 'Normal';
                
                html += '<tr class="' + (t.is_critical ? 'table-danger' : t.is_abnormal ? 'table-warning' : '') + '">';
                html += '<td>' + formatDate(t.order_date || t.created_at) + '</td>';
                html += '<td><strong>' + escapeHtml(value) + ' ' + escapeHtml(unit) + '</strong></td>';
                html += '<td>' + escapeHtml(ref) + '</td>';
                html += '<td><span class="badge bg-' + statusClass + '">' + statusText + '</span></td>';
                html += '<td>' + escapeHtml(t.order_number) + '</td>';
                html += '</tr>';
            });
            
            html += '</tbody></table></div>';
            
            // Add statistics summary
            if (stats.count > 0) {
                html = '<div class="alert alert-info"><strong>Statistics:</strong> Count: ' + stats.count + ' | Min: ' + stats.min + ' | Max: ' + stats.max + ' | Avg: ' + stats.avg.toFixed(2) + ' | Latest: ' + stats.latest + ' | First: ' + stats.first + '</div>' + html;
            }
            
            container.innerHTML = html;
        } else {
            container.innerHTML = '<div class="text-danger">Error loading trend data: ' + escapeHtml(res.message || 'Unknown error') + '</div>';
        }
    }).fail(function() {
        showLoading(false);
        var container = document.getElementById('trendResults');
        if (container) {
            container.innerHTML = '<div class="text-danger">Network error loading trend data</div>';
        }
    });
}

// ============================================
// 13. HISTORY WITH PAGINATION & FILTERS
// ============================================

function fetchOrderHistory() {
    if (!currentPatientId) return;
    
    var type = document.getElementById('historyTypeFilter') ? document.getElementById('historyTypeFilter').value : 'all';
    var startDate = document.getElementById('historyStartDate') ? document.getElementById('historyStartDate').value : '';
    var endDate = document.getElementById('historyEndDate') ? document.getElementById('historyEndDate').value : '';
    
    historyType = type;
    
    showLoading(true);
    $.post(AJAX_URL, {
        action: 'get_order_history',
        patient_id: currentPatientId,
        page: historyPage,
        history_type: type,
        start_date: startDate || null,
        end_date: endDate || null,
        csrf_token: csrfToken
    }, function(res) {
        showLoading(false);
        if (res.success && res.history) {
            renderHistorySections(res.history);
            updatePaginationControls(res.history);
        } else {
            showToast(res.message || 'Failed to load history', 'error');
        }
    }).fail(function() { 
        showLoading(false); 
        showToast('Network error loading history', 'error'); 
    });
}

function renderHistorySections(history) {
    // Render each section with the new paginated data
    renderHistorySection('prescriptionsHistory', history.prescriptions, 'prescription_number', 'prescription_date');
    renderHistorySection('labOrdersHistory', history.lab_orders, 'order_number', 'order_date');
    renderHistorySection('radiologyOrdersHistory', history.radiology_orders, 'order_number', 'created_at');
    renderHistorySection('procedureOrdersHistory', history.procedure_orders, 'procedure_number', 'scheduled_date');
    renderHistorySection('consultationsHistory', history.consultations, 'consultation_number', 'consultation_date');
    renderHistorySection('admissionsHistory', history.admissions, 'admission_number', 'admitted_at');
    renderHistorySection('dischargeSummariesHistory', history.discharge_summaries, 'discharge_date', 'discharge_condition');
    renderHistorySection('visitsHistory', history.visits, 'visit_number', 'visit_date');
    
    // Update family history if available
    if (history.family_history !== undefined) {
        renderFamilyHistory(history.family_history);
    }
}

function renderHistorySection(containerId, data, idField, dateField) {
    var html = '';
    if (!data || data.length === 0) {
        html = '<div class="text-muted small text-center">No records found</div>';
    } else {
        data.forEach(function(item) {
            var id = item[idField] || 'N/A';
            var date = item[dateField] || '';
            var status = item.status || item.payment_status || '';
            var statusBadge = status ? getStatusBadge(status) : '';
            
            var details = '';
            if (item.primary_diagnosis) details += '<div class="small">Diagnosis: ' + escapeHtml(item.primary_diagnosis) + '</div>';
            if (item.final_diagnosis) details += '<div class="small">Final Diagnosis: ' + escapeHtml(item.final_diagnosis) + '</div>';
            if (item.procedure_name) details += '<div class="small">Procedure: ' + escapeHtml(item.procedure_name) + '</div>';
            if (item.service_name) details += '<div class="small">Service: ' + escapeHtml(item.service_name) + '</div>';
            if (item.tests) details += '<div class="small">Tests: ' + escapeHtml(item.tests) + '</div>';
            if (item.item_count > 0) details += '<div class="small">Items: ' + item.item_count + '</div>';
            
            html += '<div class="order-card"><div class="d-flex justify-content-between align-items-center"><h6>' + escapeHtml(id) + '</h6>' + statusBadge + '</div><div class="small text-muted">' + date + '</div>' + details + '</div>';
        });
    }
    $('#' + containerId).html(html);
}

function updatePaginationControls(history) {
    var pagination = history.pagination || {};
    var page = pagination.page || 1;
    var limit = pagination.limit || 15;
    
    // Get total counts for the current history type
    var type = historyType;
    var totalKey = type + '_total';
    var total = history[totalKey] || 0;
    var totalPages = Math.ceil(total / limit) || 1;
    
    var pageInfo = document.getElementById('historyPageInfo');
    var prevBtn = document.getElementById('historyPrevBtn');
    var nextBtn = document.getElementById('historyNextBtn');
    
    if (pageInfo) pageInfo.textContent = 'Page ' + page + ' of ' + totalPages + ' (' + total + ' records)';
    if (prevBtn) prevBtn.disabled = (page <= 1);
    if (nextBtn) nextBtn.disabled = (page >= totalPages);
}

function changeHistoryPage(direction) {
    historyPage += direction;
    if (historyPage < 1) historyPage = 1;
    fetchOrderHistory();
}

function resetHistoryFilters() {
    var typeFilter = document.getElementById('historyTypeFilter');
    var startDate = document.getElementById('historyStartDate');
    var endDate = document.getElementById('historyEndDate');
    
    if (typeFilter) typeFilter.value = 'all';
    if (startDate) startDate.value = '';
    if (endDate) endDate.value = '';
    historyPage = 1;
    fetchOrderHistory();
}

function fetchPatientMedications() {
    // [Existing function - kept intact]
    if (!currentPatientId) return;
    
    $.post(AJAX_URL, {
        action: 'get_patient_medications',
        patient_id: currentPatientId,
        csrf_token: csrfToken
    }, function(res) {
        if (res.success && res.medications) {
            var html = '';
            if (res.medications.length === 0) {
                html = '<div class="text-muted small">No active medications</div>';
            } else {
                res.medications.forEach(function(med) {
                    html += '<div class="history-item">' + escapeHtml(med.medicine_name) + ' ' + escapeHtml(med.dosage) + ' ' + escapeHtml(med.frequency) + '</div>';
                });
            }
            $('#patientMedicationsList').html(html);
        }
    }).fail(function() { 
        $('#patientMedicationsList').html('<div class="text-muted small">Error loading medications</div>'); 
    });
}

function fetchConsultationHistory() {
    // [Existing function - kept intact]
    if (!currentPatientId) return;
    
    $.post(AJAX_URL, {
        action: 'get_consultation_history',
        patient_id: currentPatientId,
        csrf_token: csrfToken
    }, function(res) {
        if (res.success && res.consultation_history) {
            var html = '';
            if (res.consultation_history.length === 0) {
                html = '<div class="text-muted small">No past diagnoses</div>';
            } else {
                res.consultation_history.forEach(function(cons) {
                    html += '<div class="history-item">' + escapeHtml(cons.consultation_date) + ': ' + escapeHtml(cons.final_diagnosis || cons.provisional_diagnosis || 'N/A') + '</div>';
                });
            }
            $('#consultationHistoryList').html(html);
        }
    }).fail(function() { 
        $('#consultationHistoryList').html('<div class="text-muted small">Error loading diagnoses</div>'); 
    });
}

function fetchFamilyHistory() {
    // [Existing function - kept intact]
    if (!currentPatientId) return;
    
    $.post(AJAX_URL, {
        action: 'fetch_family_history',
        patient_id: currentPatientId,
        csrf_token: csrfToken
    }, function(res) {
        if (res.success) {
            renderFamilyHistory(res);
        }
    }).fail(function() { 
        $('#familyHistoryList').html('<div class="text-muted small">Error loading family history</div>'); 
    });
}

function renderFamilyHistory(data) {
    var html = '';
    if (data.family && data.family.length > 0) {
        data.family.forEach(function(fam) {
            html += '<div class="history-item">' + escapeHtml(fam.relationship_type) + ': ' + escapeHtml(fam.related_patient_name) + ' (' + escapeHtml(fam.mr_number) + ')' + (fam.is_emergency_contact ? ' 🔔' : '') + '</div>';
        });
    } else {
        html = '<div class="text-muted small">No family history recorded</div>';
    }
    if (data.notes && data.notes.length > 0) {
        html += '<div class="mt-2"><strong>Family Notes:</strong>';
        data.notes.forEach(function(note) {
            html += '<div class="small text-muted">' + escapeHtml(note.note) + ' - ' + escapeHtml(note.created_by) + '</div>';
        });
        html += '</div>';
    }
    $('#familyHistoryList').html(html);
}

function openFamilyHistoryForm() {
    // [Existing function - kept intact]
    Swal.fire({
        title: 'Add/Update Family History',
        html: '<div class="form-group text-start"><label>Relationship Type <span class="text-danger">*</span></label><select id="famRelationship" class="form-control"><option value="spouse">Spouse</option><option value="child">Child</option><option value="parent">Parent</option><option value="sibling">Sibling</option><option value="guardian">Guardian</option><option value="employee">Employee</option></select></div><div class="form-group text-start"><label>Family Member Name <span class="text-danger">*</span></label><input type="text" id="famMemberName" class="form-control" placeholder="Full name of family member"></div><div class="form-group text-start"><label>Medical Note / Condition</label><textarea id="famMedicalNote" class="form-control" rows="3" placeholder="e.g., Hypertension, Diabetes, Heart Disease, Cancer..."></textarea></div><div class="form-group text-start"><label><input type="checkbox" id="famEmergencyContact" value="1"> Is Emergency Contact</label></div>',
        showCancelButton: true,
        confirmButtonText: 'Save Family History',
        confirmButtonColor: '#4361ee',
        cancelButtonText: 'Cancel',
        width: 500,
        preConfirm: function() {
            var rel = document.getElementById('famRelationship').value;
            var name = document.getElementById('famMemberName').value.trim();
            var note = document.getElementById('famMedicalNote').value.trim();
            if (!name) {
                Swal.showValidationMessage('Please enter the family member name');
                return false;
            }
            return {
                relationship_type: rel,
                related_patient_name: name,
                family_medical_note: note || 'Family member documented',
                is_emergency_contact: document.getElementById('famEmergencyContact').checked ? 1 : 0
            };
        }
    }).then(function(result) {
        if (result.isConfirmed && result.value) {
            var data = result.value;
            showLoading(true);
            $.post(AJAX_URL, {
                action: 'save_family_history_note',
                patient_id: currentPatientId,
                relationship_type: data.relationship_type,
                related_patient_name: data.related_patient_name,
                family_medical_note: data.family_medical_note,
                is_emergency_contact: data.is_emergency_contact,
                csrf_token: csrfToken
            }, function(res) {
                showLoading(false);
                if (res.success) {
                    showToast(res.message);
                    fetchFamilyHistory();
                } else {
                    showToast(res.message || 'Failed to save family history', 'error');
                }
            }).fail(function() { 
                showLoading(false); 
                showToast('Network error', 'error'); 
            });
        }
    });
}

function fetchPatientInsurance() {
    // [Existing function - kept intact]
    if (!currentPatientId) {
        showToast('No patient selected', 'error');
        return;
    }
    
    showLoading(true);
    $.post(AJAX_URL, {
        action: 'fetch_patient_insurance',
        patient_id: currentPatientId,
        csrf_token: csrfToken
    }, function(res) {
        showLoading(false);
        if (res.success) {
            var html = '<div class="text-start">';
            if (res.insurance && res.insurance.length > 0) {
                res.insurance.forEach(function(ins) {
                    html += '<div class="order-card"><h6>' + escapeHtml(ins.provider_name) + (ins.is_primary ? ' ⭐' : '') + '</h6><div class="small">Policy: ' + escapeHtml(ins.policy_number) + '</div><div class="small">Coverage: ' + ins.coverage_percentage + '% | Co-pay: ' + ins.co_pay_percentage + '%</div><div class="small">Valid: ' + ins.effective_from + ' - ' + ins.effective_to + '</div>' + (ins.deductible_amount > 0 ? '<div class="small">Deductible: ' + ins.deductible_amount + ' ETB</div>' : '') + (ins.max_coverage_per_year > 0 ? '<div class="small">Max Coverage/Year: ' + ins.max_coverage_per_year + ' ETB</div>' : '') + '</div>';
                });
            } else {
                html += '<div class="text-muted">No active insurance found</div>';
            }
            html += '</div>';
            
            Swal.fire({
                title: 'Patient Insurance',
                html: html,
                width: 600,
                confirmButtonText: 'Close',
                confirmButtonColor: '#4361ee'
            });
        } else {
            showToast(res.message || 'Failed to fetch insurance', 'error');
        }
    }).fail(function() { 
        showLoading(false); 
        showToast('Network error', 'error'); 
    });
}

// ============================================
// 14. ADMISSION & DISCHARGE
// ============================================

function openAdmitModal() {
    // [Existing function - kept intact]
    if (!currentPatientId || !currentVisitId) {
        showToast('No patient or visit selected', 'error');
        return;
    }
    
    showLoading(true);
    $.post(AJAX_URL, {
        action: 'fetch_available_beds',
        csrf_token: csrfToken
    }, function(res) {
        showLoading(false);
        var bedOptions = '<option value="">No Bed (Observation)</option>';
        if (res.success && res.beds) {
            res.beds.forEach(function(bed) {
                bedOptions += '<option value="' + bed.id + '">' + escapeHtml(bed.bed_number) + ' - ' + escapeHtml(bed.ward_name) + ' (' + escapeHtml(bed.bed_type) + ') - ' + bed.daily_rate + ' ETB/day</option>';
            });
        }
        
        Swal.fire({
            title: 'Admit Patient',
            html: '<div class="form-group text-start"><label>Admission Type <span class="text-danger">*</span></label><select id="admissionType" class="form-control"><option value="elective">Elective</option><option value="emergency">Emergency</option><option value="transfer">Transfer</option><option value="observation">Observation</option></select></div><div class="form-group text-start"><label>Primary Diagnosis <span class="text-danger">*</span></label><input type="text" id="primaryDiagnosis" class="form-control" placeholder="Enter primary diagnosis..."></div><div class="form-group text-start"><label>Admitting Diagnosis</label><input type="text" id="admittingDiagnosis" class="form-control" placeholder="Enter admitting diagnosis..."></div><div class="form-group text-start"><label>Condition Status</label><select id="conditionStatus" class="form-control"><option value="stable">Stable</option><option value="guarded">Guarded</option><option value="serious">Serious</option><option value="critical">Critical</option></select></div><div class="form-group text-start"><label>Bed</label><select id="bedSelect" class="form-control">' + bedOptions + '</select></div><div class="form-group text-start"><label>Expected Discharge Date</label><input type="datetime-local" id="expectedDischargeAt" class="form-control"></div><div class="form-group text-start"><label>Admission Notes</label><textarea id="admissionNotes" class="form-control" rows="2" placeholder="Additional notes..."></textarea></div>',
            showCancelButton: true,
            confirmButtonText: 'Admit Patient',
            confirmButtonColor: '#4361ee',
            cancelButtonText: 'Cancel',
            width: 550,
            preConfirm: function() {
                var primaryDiagnosis = document.getElementById('primaryDiagnosis').value.trim();
                if (!primaryDiagnosis) {
                    Swal.showValidationMessage('Please enter a primary diagnosis');
                    return false;
                }
                return {
                    primaryDiagnosis: primaryDiagnosis,
                    admissionType: document.getElementById('admissionType').value,
                    admittingDiagnosis: document.getElementById('admittingDiagnosis').value,
                    conditionStatus: document.getElementById('conditionStatus').value,
                    bedId: document.getElementById('bedSelect').value,
                    expectedDischargeAt: document.getElementById('expectedDischargeAt').value,
                    admissionNotes: document.getElementById('admissionNotes').value
                };
            }
        }).then(function(result) {
            if (result.isConfirmed && result.value) {
                var data = result.value;
                showLoading(true);
                $.post(AJAX_URL, {
                    action: 'admit_patient',
                    visit_id: currentVisitId,
                    patient_id: currentPatientId,
                    admission_type: data.admissionType,
                    primary_diagnosis: data.primaryDiagnosis,
                    admitting_diagnosis: data.admittingDiagnosis,
                    condition_status: data.conditionStatus,
                    bed_id: data.bedId || 0,
                    expected_discharge_at: data.expectedDischargeAt || null,
                    admission_notes: data.admissionNotes,
                    csrf_token: csrfToken
                }, function(res) {
                    showLoading(false);
                    if (res.success) {
                        showToast(res.message);
                        setTimeout(function() { location.reload(); }, 1000);
                    } else {
                        showToast(res.message || 'Failed to admit patient', 'error');
                    }
                }).fail(function() { 
                    showLoading(false); 
                    showToast('Network error', 'error'); 
                });
            }
        });
    }).fail(function() { 
        showLoading(false); 
        showToast('Failed to load beds', 'error'); 
    });
}

function openDischargeSummary() {
    <?php if ($admission_data): ?>
    Swal.fire({
        title: 'Discharge Summary',
        html: '<div class="form-group text-start"><label>Discharge Condition <span class="text-danger">*</span></label><select id="dsCondition" class="form-control"><option value="improved">Improved</option><option value="stabilized">Stabilized</option><option value="relieved">Relieved</option><option value="ama">Against Medical Advice</option><option value="expired">Expired</option><option value="transferred">Transferred</option></select></div><div class="form-group text-start"><label>Hospital Course</label><textarea id="dsCourse" class="form-control" rows="3" placeholder="Describe the hospital course..."></textarea></div><div class="form-group text-start"><label>Final Diagnosis <span class="text-danger">*</span></label><input type="text" id="dsDiagnosis" class="form-control" placeholder="Final diagnosis..."></div><div class="form-group text-start"><label>Discharge Medications</label><textarea id="dsMedications" class="form-control" rows="2" placeholder="List discharge medications..."></textarea></div><div class="form-group text-start"><label>Warning Signs</label><textarea id="warningSigns" class="form-control" rows="2" placeholder="Warning signs to watch for..."></textarea></div><div class="form-group text-start"><label>Follow-up Required</label><select id="dsFollowUp" class="form-control"><option value="0">No</option><option value="1">Yes</option></select></div><div class="form-group text-start" id="dsFollowUpDateGroup" style="display:none;"><label>Follow-up Date</label><input type="date" id="dsFollowUpDate" class="form-control"></div><div class="form-group text-start"><label>Follow-up With</label><input type="text" id="dsFollowUpWith" class="form-control" placeholder="Department or doctor name"></div><div class="form-group text-start"><label>Special Instructions</label><textarea id="dsInstructions" class="form-control" rows="2" placeholder="Special discharge instructions..."></textarea></div>',
        showCancelButton: true,
        confirmButtonText: 'Create Discharge Summary',
        confirmButtonColor: '#06d6a0',
        cancelButtonText: 'Cancel',
        width: 550,
        preConfirm: function() {
            var diagnosis = document.getElementById('dsDiagnosis').value.trim();
            if (!diagnosis) {
                Swal.showValidationMessage('Please enter a final diagnosis');
                return false;
            }
            return {
                condition: document.getElementById('dsCondition').value,
                course: document.getElementById('dsCourse').value,
                diagnosis: diagnosis,
                medications: document.getElementById('dsMedications').value,
                warningSigns: document.getElementById('warningSigns').value,
                followUp: document.getElementById('dsFollowUp').value,
                followUpDate: document.getElementById('dsFollowUpDate').value,
                followUpWith: document.getElementById('dsFollowUpWith').value,
                instructions: document.getElementById('dsInstructions').value
            };
        }
    }).then(function(result) {
        if (result.isConfirmed && result.value) {
            var data = result.value;
            showLoading(true);
            $.post(AJAX_URL, {
                action: 'create_discharge_summary',
                admission_id: <?php echo $admission_data['id'] ?? 0; ?>,
                patient_id: currentPatientId,
                visit_id: currentVisitId,
                discharge_condition: data.condition,
                hospital_course: data.course,
                final_diagnosis: data.diagnosis,
                discharge_medications: data.medications,
                warning_signs: data.warningSigns,
                follow_up_required: data.followUp,
                follow_up_date: data.followUpDate || null,
                follow_up_with: data.followUpWith,
                follow_up_instructions: '',
                special_instructions: data.instructions,
                csrf_token: csrfToken
            }, function(res) {
                showLoading(false);
                if (res.success) {
                    showToast(res.message);
                    setTimeout(function() { location.reload(); }, 1000);
                } else {
                    showToast(res.message || 'Failed to create discharge summary', 'error');
                }
            }).fail(function() { 
                showLoading(false); 
                showToast('Network error', 'error'); 
            });
        }
    });
    <?php else: ?>
    showToast('Patient is not currently admitted. Please admit the patient first.', 'error');
    <?php endif; ?>
}

function updateConditionStatus() {
    <?php if ($admission_data): ?>
    Swal.fire({
        title: 'Update Condition Status',
        html: '<div class="form-group text-start"><label>Condition Status <span class="text-danger">*</span></label><select id="conditionStatusSelect" class="form-control"><option value="stable" ' + ('<?php echo $admission_data['condition_status']; ?>' === 'stable' ? 'selected' : '') + '>Stable</option><option value="guarded" ' + ('<?php echo $admission_data['condition_status']; ?>' === 'guarded' ? 'selected' : '') + '>Guarded</option><option value="serious" ' + ('<?php echo $admission_data['condition_status']; ?>' === 'serious' ? 'selected' : '') + '>Serious</option><option value="critical" ' + ('<?php echo $admission_data['condition_status']; ?>' === 'critical' ? 'selected' : '') + '>Critical</option><option value="deceased" ' + ('<?php echo $admission_data['condition_status']; ?>' === 'deceased' ? 'selected' : '') + '>Deceased</option></select></div><div class="form-group text-start"><label>Notes</label><textarea id="conditionNotes" class="form-control" rows="2" placeholder="Additional notes about condition change..."></textarea></div>',
        showCancelButton: true,
        confirmButtonText: 'Update Status',
        confirmButtonColor: '#4361ee',
        cancelButtonText: 'Cancel',
        preConfirm: function() {
            return {
                status: document.getElementById('conditionStatusSelect').value,
                notes: document.getElementById('conditionNotes').value
            };
        }
    }).then(function(result) {
        if (result.isConfirmed && result.value) {
            showLoading(true);
            $.post(AJAX_URL, {
                action: 'update_condition_status',
                admission_id: <?php echo $admission_data['id'] ?? 0; ?>,
                condition_status: result.value.status,
                notes: result.value.notes,
                csrf_token: csrfToken
            }, function(res) {
                showLoading(false);
                if (res.success) {
                    showToast(res.message);
                    setTimeout(function() { location.reload(); }, 1000);
                } else {
                    showToast(res.message || 'Failed to update status', 'error');
                }
            }).fail(function() { 
                showLoading(false); 
                showToast('Network error', 'error'); 
            });
        }
    });
    <?php else: ?>
    showToast('No admission found for this patient', 'error');
    <?php endif; ?>
}

// ============================================
// 15. CONSULTATION REQUESTS & REFERRALS
// ============================================

function openConsultationRequest() {
    // [Existing function - kept intact]
    if (!currentPatientId) {
        showToast('No patient selected', 'error');
        return;
    }
    
    Swal.fire({
        title: 'Request Consultation',
        html: '<div class="form-group text-start"><label>Consulting Doctor <span class="text-danger">*</span></label><select id="consultDoctor" class="form-control"><option value="">Select Doctor</option><?php foreach ($doctors as $doc): ?><option value="<?php echo $doc['id']; ?>"><?php echo escapeHtml($doc['first_name'] . ' ' . $doc['last_name']); ?><?php echo $doc['specialization'] ? ' (' . escapeHtml($doc['specialization']) . ')' : ''; ?></option><?php endforeach; ?></select></div><div class="form-group text-start"><label>Urgency Level</label><select id="consultUrgency" class="form-control"><option value="routine">Routine</option><option value="urgent">Urgent</option><option value="stat">STAT</option></select></div><div class="form-group text-start"><label>Clinical Question <span class="text-danger">*</span></label><textarea id="consultQuestion" class="form-control" rows="3" placeholder="Describe the clinical question or case summary..."></textarea></div>',
        showCancelButton: true,
        confirmButtonText: 'Send Request',
        confirmButtonColor: '#4361ee',
        cancelButtonText: 'Cancel',
        preConfirm: function() {
            var doctorId = document.getElementById('consultDoctor').value;
            var urgency = document.getElementById('consultUrgency').value;
            var question = document.getElementById('consultQuestion').value.trim();
            if (!doctorId) { Swal.showValidationMessage('Please select a consulting doctor'); return false; }
            if (!question) { Swal.showValidationMessage('Please describe the clinical question'); return false; }
            return { doctorId: doctorId, urgency: urgency, question: question };
        }
    }).then(function(result) {
        if (result.isConfirmed && result.value) {
            showLoading(true);
            $.post(AJAX_URL, {
                action: 'request_consultation',
                patient_id: currentPatientId,
                assigned_doctor_id: result.value.doctorId,
                urgency_level: result.value.urgency,
                clinical_question_summary: result.value.question,
                csrf_token: csrfToken
            }, function(res) {
                showLoading(false);
                if (res.success) {
                    showToast(res.message);
                } else {
                    showToast(res.message || 'Failed to send request', 'error');
                }
            }).fail(function() { 
                showLoading(false); 
                showToast('Network error', 'error'); 
            });
        }
    });
}

function openReferral() {
    // [Existing function - kept intact]
    if (!currentPatientId) {
        showToast('No patient selected', 'error');
        return;
    }
    
    Swal.fire({
        title: 'Refer Patient',
        html: '<div class="form-group text-start"><label>Receiving Doctor <span class="text-danger">*</span></label><select id="refDoctor" class="form-control"><option value="">Select Doctor</option><?php foreach ($doctors as $doc): ?><option value="<?php echo $doc['id']; ?>"><?php echo escapeHtml($doc['first_name'] . ' ' . $doc['last_name']); ?><?php echo $doc['specialization'] ? ' (' . escapeHtml($doc['specialization']) . ')' : ''; ?></option><?php endforeach; ?></select></div><div class="form-group text-start"><label>Referral Reason <span class="text-danger">*</span></label><textarea id="refReason" class="form-control" rows="3" placeholder="Why is this patient being referred?"></textarea></div>',
        showCancelButton: true,
        confirmButtonText: 'Send Referral',
        confirmButtonColor: '#06d6a0',
        cancelButtonText: 'Cancel',
        preConfirm: function() {
            var doctorId = document.getElementById('refDoctor').value;
            var reason = document.getElementById('refReason').value.trim();
            if (!doctorId) { Swal.showValidationMessage('Please select a receiving doctor'); return false; }
            if (!reason) { Swal.showValidationMessage('Please provide a referral reason'); return false; }
            return { doctorId: doctorId, reason: reason };
        }
    }).then(function(result) {
        if (result.isConfirmed && result.value) {
            showLoading(true);
            $.post(AJAX_URL, {
                action: 'refer_patient',
                patient_id: currentPatientId,
                visit_id: currentVisitId,
                referred_to_user_id: result.value.doctorId,
                referral_reason: result.value.reason,
                csrf_token: csrfToken
            }, function(res) {
                showLoading(false);
                if (res.success) {
                    showToast(res.message);
                } else {
                    showToast(res.message || 'Failed to send referral', 'error');
                }
            }).fail(function() { 
                showLoading(false); 
                showToast('Network error', 'error'); 
            });
        }
    });
}

function resetSoapForm() {
    $('#subjective, #objective, #assessment, #plan, #primary_icd10, #diagnosis').val('');
    $('#soapLastSaved').text('');
}

// ============================================
// 16. CLEANUP
// ============================================

$(window).on('beforeunload', function() {
    cleanupQueuePolling();
    if (labPollingInterval) clearInterval(labPollingInterval);
});

document.addEventListener('visibilitychange', function() {
    if (document.hidden) {
        if (queueRefreshInterval) {
            clearInterval(queueRefreshInterval);
            queueRefreshInterval = null;
        }
    } else {
        if (!queueRefreshInterval) {
            refreshQueue(currentTab);
            queueRefreshInterval = setInterval(function() {
                refreshQueue(currentTab);
            }, 30000);
        }
    }
});

// Follow-up date toggle
$(document).on('change', '#followUpRequired', function() {
    $('#followUpDateGroup').toggle($(this).val() === '1');
});

$(document).on('change', '#dsFollowUp', function() {
    $('#dsFollowUpDateGroup').toggle($(this).val() === '1');
});

// ============================================
// 17. SIDEBAR COMPATIBILITY
// ============================================

if (typeof MetisMenu !== 'undefined') {
    var sideMenu = document.getElementById('side-menu');
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

document.getElementById('mobileMenuToggle') && document.getElementById('mobileMenuToggle').addEventListener('click', function(e) {
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