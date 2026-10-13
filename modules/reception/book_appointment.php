<?php
/**
 * Book Appointment - HMS ERP System
 * Enhanced with full database capabilities including:
 * - Insurance profiles
 * - Visit management with queue tokens
 * - Emergency walk-in support
 * - Referral tracking
 * - Billing status integration
 * - Queue token generation
 * 
 * @package HMS-ERP
 * @version 3.0
 * @requires appointment_create_access permission
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

// Enable error reporting for debugging (remove in production)
error_reporting(E_ALL);
ini_set('display_errors', 0);

// ============================================
// AUTHENTICATION & AUTHORIZATION
// ============================================

if (!isset($_SESSION['user_id'])) {
    header('Location: ../../index.php');
    exit;
}

$user_id = (int)($_SESSION['user_id'] ?? 0);
$user_branch_id = (int)($_SESSION['branch_id'] ?? 0);
$is_global_admin = ($_SESSION['user_type'] ?? '') === 'super_admin';
$user_name = $_SESSION['first_name'] ?? $_SESSION['username'] ?? 'User';

global $pdo;
$db_available = ($pdo instanceof PDO);

if (!$db_available) {
    die('Database connection failed. Please check your configuration.');
}

$can_create_appointment = $is_global_admin;
if (!$is_global_admin && $db_available && $user_id > 0) {
    $can_create_appointment = user_has_permission($pdo, 'appointment_create_access', $user_id);
}

if (!$can_create_appointment) {
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
    if (ob_get_length()) {
        ob_clean();
    }
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Generate unique appointment number
 */
function generateAppointmentNumber(PDO $pdo): string {
    $max_attempts = 10;
    $attempt = 0;
    
    while ($attempt < $max_attempts) {
        $date_prefix = date('Ymd');
        $random = mt_rand(1000, 9999);
        $appt_number = "APT-{$date_prefix}-{$random}";
        
        $check_stmt = $pdo->prepare("SELECT id FROM appointments WHERE appointment_number = :apt_num AND deleted_at IS NULL LIMIT 1");
        $check_stmt->execute([':apt_num' => $appt_number]);
        
        if ($check_stmt->rowCount() === 0) {
            return $appt_number;
        }
        $attempt++;
    }
    return 'APT-' . date('Ymd') . '-' . substr(uniqid(), -6);
}

/**
 * Generate unique visit number
 */
function generateVisitNumber(PDO $pdo): string {
    $max_attempts = 10;
    $attempt = 0;
    
    while ($attempt < $max_attempts) {
        $date_prefix = date('Ymd');
        $random = mt_rand(1000, 9999);
        $visit_number = "VIS-{$date_prefix}-{$random}";
        
        $check_stmt = $pdo->prepare("SELECT id FROM patient_visits WHERE visit_number = :visit_num AND deleted_at IS NULL LIMIT 1");
        $check_stmt->execute([':visit_num' => $visit_number]);
        
        if ($check_stmt->rowCount() === 0) {
            return $visit_number;
        }
        $attempt++;
    }
    return 'VIS-' . date('Ymd') . '-' . substr(uniqid(), -6);
}

/**
 * Generate queue token number
 */
function generateQueueToken(PDO $pdo, int $branch_id, string $date): int {
    $stmt = $pdo->prepare("
        SELECT MAX(queue_token_number) as max_token 
        FROM appointments 
        WHERE branch_id = :branch_id 
          AND appointment_date = :date 
          AND deleted_at IS NULL
    ");
    $stmt->execute([':branch_id' => $branch_id, ':date' => $date]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return ($result['max_token'] ?? 0) + 1;
}

// Get prefilled date/time from calendar
$prefill_date = trim((string)($_GET['date'] ?? ''));
$prefill_time = trim((string)($_GET['time'] ?? ''));
$prefill_patient_id = (int)($_GET['patient_id'] ?? 0);
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $prefill_date)) $prefill_date = '';
if (!preg_match('/^\d{2}:\d{2}$/', $prefill_time)) $prefill_time = '';

// ============================================
// AJAX: Patient Search (with Branch Isolation)
// ============================================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'patient_search') {
    try {
        $term = trim((string)($_GET['q'] ?? ''));
        
        if (strlen($term) < 2) {
            json_exit(['success' => true, 'items' => []]);
        }
        
        $like = '%' . $term . '%';
        
        $sql = "
            SELECT 
                p.id, 
                p.mr_number, 
                CONCAT(p.first_name, ' ', COALESCE(p.middle_name, ''), ' ', p.last_name) AS patient_name,
                p.phone, 
                p.email, 
                p.gender, 
                p.date_of_birth,
                p.is_free_treatment_eligible,
                pip.id as insurance_profile_id,
                pip.policy_number,
                pip.coverage_percentage,
                ip.name as insurance_provider_name
            FROM patients p
            LEFT JOIN patient_insurance_profiles pip ON p.id = pip.patient_id AND pip.status = 'active' AND pip.is_primary = 1 AND pip.deleted_at IS NULL
            LEFT JOIN insurance_providers ip ON pip.insurance_provider_id = ip.id AND ip.is_active = 1 AND ip.deleted_at IS NULL
            WHERE p.branch_id = :branch_id
              AND p.is_active = 1 
              AND p.deleted_at IS NULL
              AND (
                  p.mr_number LIKE :like 
                  OR p.phone LIKE :like 
                  OR p.first_name LIKE :like 
                  OR p.last_name LIKE :like 
                  OR CONCAT(p.first_name, ' ', COALESCE(p.middle_name, ''), ' ', p.last_name) LIKE :like
                  OR CONCAT(p.first_name, ' ', p.last_name) LIKE :like
              )
            ORDER BY p.first_name, p.last_name
            LIMIT 30
        ";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':branch_id' => $user_branch_id,
            ':like' => $like
        ]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $items = [];
        foreach ($rows as $row) {
            $age = null;
            if (!empty($row['date_of_birth'])) {
                $birth = new DateTime($row['date_of_birth']);
                $now = new DateTime();
                $age = $birth->diff($now)->y;
            }
            
            $items[] = [
                'id' => (int)$row['id'],
                'mr_number' => htmlspecialchars($row['mr_number'] ?? '', ENT_QUOTES, 'UTF-8'),
                'name' => htmlspecialchars($row['patient_name'] ?? '', ENT_QUOTES, 'UTF-8'),
                'phone' => htmlspecialchars($row['phone'] ?? '', ENT_QUOTES, 'UTF-8'),
                'email' => htmlspecialchars($row['email'] ?? '', ENT_QUOTES, 'UTF-8'),
                'gender' => htmlspecialchars($row['gender'] ?? '', ENT_QUOTES, 'UTF-8'),
                'birth_date' => htmlspecialchars($row['date_of_birth'] ?? '', ENT_QUOTES, 'UTF-8'),
                'age' => $age,
                'has_insurance' => !empty($row['insurance_profile_id']),
                'insurance_provider' => htmlspecialchars($row['insurance_provider_name'] ?? '', ENT_QUOTES, 'UTF-8'),
                'free_treatment_eligible' => (bool)($row['is_free_treatment_eligible'] ?? false)
            ];
        }
        
        json_exit(['success' => true, 'items' => $items]);
        
    } catch (PDOException $e) {
        error_log("Patient search error: " . $e->getMessage());
        json_exit(['success' => false, 'message' => 'Search failed', 'items' => []], 500);
    }
}

// ============================================
// AJAX: Get Patient Insurance Details
// ============================================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'patient_insurance') {
    try {
        $patient_id = (int)($_GET['patient_id'] ?? 0);
        
        if ($patient_id <= 0) {
            json_exit(['success' => true, 'profiles' => []]);
        }
        
        $sql = "
            SELECT 
                pip.id,
                pip.policy_number,
                pip.member_id,
                pip.group_number,
                pip.coverage_percentage,
                pip.co_pay_percentage,
                pip.effective_from,
                pip.effective_to,
                pip.is_primary,
                pip.status,
                ip.id as provider_id,
                ip.name as provider_name,
                ip.code as provider_code
            FROM patient_insurance_profiles pip
            INNER JOIN insurance_providers ip ON pip.insurance_provider_id = ip.id AND ip.is_active = 1 AND ip.deleted_at IS NULL
            WHERE pip.patient_id = :patient_id
              AND pip.status = 'active'
              AND pip.deleted_at IS NULL
            ORDER BY pip.is_primary DESC, pip.effective_from DESC
        ";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':patient_id' => $patient_id]);
        $profiles = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        json_exit(['success' => true, 'profiles' => $profiles]);
        
    } catch (PDOException $e) {
        error_log("Patient insurance error: " . $e->getMessage());
        json_exit(['success' => false, 'message' => 'Failed to load insurance', 'profiles' => []], 500);
    }
}

// ============================================
// AJAX: Get Doctors by Department
// ============================================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'doctors_by_department') {
    try {
        $department_id = (int)($_GET['department_id'] ?? 0);
        
        if ($department_id <= 0) {
            json_exit(['success' => true, 'items' => []]);
        }
        
        $sql = "
            SELECT DISTINCT u.id, u.first_name, u.middle_name, u.last_name, sp.specialization, sp.license_number
            FROM users u
            LEFT JOIN staff_profiles sp ON u.id = sp.user_id
            LEFT JOIN departments d ON sp.department_id = d.id
            WHERE u.user_type = 'doctor'
              AND u.branch_id = :branch_id
              AND u.is_active = 1
              AND u.deleted_at IS NULL
              AND (sp.department_id = :department_id OR d.id = :department_id)
            ORDER BY u.first_name, u.last_name
        ";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':branch_id' => $user_branch_id,
            ':department_id' => $department_id
        ]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $items = [];
        foreach ($rows as $row) {
            $full_name = trim($row['first_name'] . ' ' . ($row['middle_name'] ? $row['middle_name'] . ' ' : '') . $row['last_name']);
            $items[] = [
                'id' => (int)$row['id'],
                'name' => 'Dr. ' . htmlspecialchars($full_name, ENT_QUOTES, 'UTF-8'),
                'first_name' => htmlspecialchars($row['first_name'] ?? '', ENT_QUOTES, 'UTF-8'),
                'middle_name' => htmlspecialchars($row['middle_name'] ?? '', ENT_QUOTES, 'UTF-8'),
                'last_name' => htmlspecialchars($row['last_name'] ?? '', ENT_QUOTES, 'UTF-8'),
                'specialist' => htmlspecialchars($row['specialization'] ?? 'General', ENT_QUOTES, 'UTF-8'),
                'license_number' => htmlspecialchars($row['license_number'] ?? '', ENT_QUOTES, 'UTF-8')
            ];
        }
        
        json_exit(['success' => true, 'items' => $items]);
        
    } catch (PDOException $e) {
        error_log("Doctors by department error: " . $e->getMessage());
        json_exit(['success' => false, 'message' => 'Failed to load doctors', 'items' => []], 500);
    }
}

// ============================================
// AJAX: Check Doctor Availability
// ============================================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'check_availability') {
    try {
        $doctor_id = (int)($_GET['doctor_id'] ?? 0);
        $date = trim((string)($_GET['date'] ?? ''));
        $time = trim((string)($_GET['time'] ?? ''));
        $exclude_appointment_id = (int)($_GET['exclude_id'] ?? 0);
        
        if ($doctor_id <= 0 || empty($date) || empty($time)) {
            json_exit(['success' => false, 'message' => 'Invalid parameters'], 422);
        }
        
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            json_exit(['success' => false, 'message' => 'Invalid date format'], 422);
        }
        
        if (!preg_match('/^\d{2}:\d{2}$/', $time)) {
            json_exit(['success' => false, 'message' => 'Invalid time format'], 422);
        }
        
        $time_with_seconds = $time . ':00';
        
        $sql = "
            SELECT id, status FROM appointments
            WHERE branch_id = :branch_id
              AND doctor_id = :doctor_id
              AND appointment_date = :date
              AND appointment_time = :time
              AND deleted_at IS NULL
              AND status NOT IN ('cancelled', 'no_show')
        ";
        
        if ($exclude_appointment_id > 0) {
            $sql .= " AND id != :exclude_id";
        }
        $sql .= " LIMIT 1";
        
        $stmt = $pdo->prepare($sql);
        $params = [
            ':branch_id' => $user_branch_id,
            ':doctor_id' => $doctor_id,
            ':date' => $date,
            ':time' => $time_with_seconds
        ];
        if ($exclude_appointment_id > 0) {
            $params[':exclude_id'] = $exclude_appointment_id;
        }
        $stmt->execute($params);
        $conflict = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Also check if doctor has staff schedule conflicts (optional)
        $schedule_conflict = false;
        $day_of_week = date('l', strtotime($date));
        $sql_schedule = "
            SELECT id FROM staff_schedules
            WHERE user_id = :doctor_id
              AND day_of_week = :day_of_week
              AND status = 'active'
              AND deleted_at IS NULL
              AND :time NOT BETWEEN start_time AND end_time
        ";
        $stmt_schedule = $pdo->prepare($sql_schedule);
        $stmt_schedule->execute([
            ':doctor_id' => $doctor_id,
            ':day_of_week' => $day_of_week,
            ':time' => $time_with_seconds
        ]);
        $schedule_conflict = $stmt_schedule->rowCount() > 0;
        
        json_exit([
            'success' => true,
            'available' => !$conflict && !$schedule_conflict,
            'message' => $conflict ? 'Doctor already booked at this time' : ($schedule_conflict ? 'Doctor not scheduled at this time' : 'Time slot available'),
            'conflict' => $conflict ? true : false,
            'schedule_conflict' => $schedule_conflict
        ]);
        
    } catch (PDOException $e) {
        error_log("Availability check error: " . $e->getMessage());
        json_exit(['success' => false, 'message' => 'Availability check failed'], 500);
    }
}

// ============================================
// AJAX: Get Appointment Types/Durations
// ============================================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'appointment_settings') {
    try {
        $sql = "
            SELECT setting_key, setting_value 
            FROM system_settings 
            WHERE setting_key IN (
                'default_appointment_duration',
                'emergency_priority_timeout',
                'max_appointments_per_day'
            )
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        
        json_exit([
            'success' => true,
            'settings' => [
                'default_duration' => (int)($settings['default_appointment_duration'] ?? 15),
                'emergency_timeout' => (int)($settings['emergency_priority_timeout'] ?? 10),
                'max_per_day' => (int)($settings['max_appointments_per_day'] ?? 50)
            ]
        ]);
        
    } catch (PDOException $e) {
        json_exit(['success' => false, 'message' => 'Failed to load settings'], 500);
    }
}

// ============================================
// AJAX: Book Appointment (with CSRF Validation)
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax']) && $_POST['ajax'] === 'book') {
    // Validate CSRF token
    $submitted_token = $_POST['csrf_token'] ?? '';
    if (!isset($_SESSION['csrf_token']) || $submitted_token !== $_SESSION['csrf_token']) {
        error_log("Book appointment: CSRF validation failed for user {$user_id}");
        json_exit(['success' => false, 'message' => 'Security validation failed. Please refresh the page and try again.'], 403);
    }
    
    try {
        $pdo->beginTransaction();
        
        $patient_id = (int)($_POST['patient_id'] ?? 0);
        $department_id = (int)($_POST['department_id'] ?? 0);
        $doctor_id = (int)($_POST['doctor_id'] ?? 0);
        $date = trim((string)($_POST['appointment_date'] ?? ''));
        $time = trim((string)($_POST['appointment_time'] ?? ''));
        $priority = trim((string)($_POST['priority'] ?? 'normal'));
        $visit_type = trim((string)($_POST['visit_type'] ?? 'regular'));
        $chief_complaint = trim((string)($_POST['chief_complaint'] ?? ''));
        $symptoms_duration = trim((string)($_POST['symptoms_duration'] ?? ''));
        $referral_source = trim((string)($_POST['referral_source'] ?? ''));
        $is_emergency_walk_in = isset($_POST['is_emergency_walk_in']) ? (int)$_POST['is_emergency_walk_in'] : 0;
        $billing_type = trim((string)($_POST['billing_type'] ?? 'cash'));
        $insurance_profile_id = (int)($_POST['insurance_profile_id'] ?? 0);
        $estimated_wait_minutes = (int)($_POST['estimated_wait_minutes'] ?? 0);
        
        // Validation
        $errors = [];
        $valid_priorities = ['normal', 'urgent', 'stat'];
        $valid_visit_types = ['regular', 'emergency', 'follow_up', 'telemedicine'];
        $valid_billing_types = ['cash', 'insurance', 'corporate', 'package', 'free_treatment'];
        
        if ($patient_id <= 0) $errors[] = 'Patient is required.';
        if ($department_id <= 0) $errors[] = 'Department is required.';
        if ($doctor_id <= 0) $errors[] = 'Doctor is required.';
        if ($date === '' || !DateTime::createFromFormat('Y-m-d', $date)) $errors[] = 'Valid appointment date is required.';
        if ($time === '' || !preg_match('/^\d{2}:\d{2}$/', $time)) $errors[] = 'Valid appointment time is required.';
        if (!in_array($priority, $valid_priorities, true)) $errors[] = 'Priority must be Normal, Urgent, or STAT.';
        if (!in_array($visit_type, $valid_visit_types, true)) $errors[] = 'Invalid visit type.';
        if (!in_array($billing_type, $valid_billing_types, true)) $errors[] = 'Invalid billing type.';
        if ($chief_complaint === '') $errors[] = 'Chief complaint is required.';
        
        // If insurance billing, require insurance profile
        if ($billing_type === 'insurance' && $insurance_profile_id <= 0) {
            $errors[] = 'Insurance profile is required for insurance billing.';
        }
        
        if ($errors) {
            json_exit(['success' => false, 'message' => implode("\n", $errors)], 422);
        }
        
        // Verify patient belongs to branch and is active
        $stmt = $pdo->prepare("
            SELECT id, is_free_treatment_eligible 
            FROM patients 
            WHERE id = :id AND branch_id = :branch_id AND is_active = 1 AND deleted_at IS NULL 
            LIMIT 1
        ");
        $stmt->execute([
            ':id' => $patient_id,
            ':branch_id' => $user_branch_id
        ]);
        $patient = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$patient) {
            json_exit(['success' => false, 'message' => 'Invalid or inactive patient'], 403);
        }
        
        // Check if free treatment eligible and billing type matches
        if ($billing_type === 'free_treatment' && !$patient['is_free_treatment_eligible']) {
            json_exit(['success' => false, 'message' => 'Patient is not eligible for free treatment'], 403);
        }
        
        // Generate appointment number and visit number
        $appointment_number = generateAppointmentNumber($pdo);
        $visit_number = generateVisitNumber($pdo);
        $queue_token = generateQueueToken($pdo, $user_branch_id, $date);
        
        // Check for conflicting appointments
        $time_with_seconds = $time . ':00';
        $stmt = $pdo->prepare("
            SELECT id FROM appointments
            WHERE branch_id = :branch_id
              AND doctor_id = :doctor_id
              AND appointment_date = :date
              AND appointment_time = :time
              AND deleted_at IS NULL
              AND status NOT IN ('cancelled', 'no_show')
            LIMIT 1
        ");
        $stmt->execute([
            ':branch_id' => $user_branch_id,
            ':doctor_id' => $doctor_id,
            ':date' => $date,
            ':time' => $time_with_seconds
        ]);
        $conflict = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($conflict) {
            $pdo->rollBack();
            json_exit(['success' => false, 'message' => 'Doctor already has an appointment at this time.'], 409);
        }
        
        // Calculate estimated wait time based on queue
        if ($estimated_wait_minutes <= 0) {
            $wait_stmt = $pdo->prepare("
                SELECT COUNT(*) as waiting_count 
                FROM appointments 
                WHERE branch_id = :branch_id 
                  AND appointment_date = :date 
                  AND status IN ('scheduled', 'confirmed')
                  AND deleted_at IS NULL
            ");
            $wait_stmt->execute([
                ':branch_id' => $user_branch_id,
                ':date' => $date
            ]);
            $waiting = $wait_stmt->fetch(PDO::FETCH_ASSOC);
            $estimated_wait_minutes = min(($waiting['waiting_count'] ?? 0) * 15, 120);
        }
        
        // Set status based on visit type
        $status = ($visit_type === 'emergency' || $is_emergency_walk_in) ? 'confirmed' : 'scheduled';
        
        // Build notes
        $notes = [];
        if ($priority !== 'normal') {
            $notes[] = '[Priority: ' . strtoupper($priority) . ']';
        }
        if ($referral_source) {
            $notes[] = '[Referral: ' . $referral_source . ']';
        }
        $notes[] = $chief_complaint;
        $notes_text = implode(' ', $notes);
        
        // Create patient visit first
        $visit_stmt = $pdo->prepare("
            INSERT INTO patient_visits
            (branch_id, patient_id, visit_number, visit_date, visit_time, visit_type, 
             card_fee_status, payment_mode, billing_status, status, queue_token_number,
             is_priority, queue_status, registration_notes, created_by_user_id, created_at, updated_at)
            VALUES 
            (:branch_id, :patient_id, :visit_number, :date, :time, :visit_type,
             :card_fee_status, :payment_mode, :billing_status, :visit_status, :queue_token,
             :is_priority, 'waiting', :registration_notes, :user_id, NOW(), NOW())
        ");
        
        $card_fee_status = $billing_type === 'free_treatment' ? 'exempt' : 'pending';
        $is_priority = ($priority === 'stat' || $visit_type === 'emergency') ? 1 : 0;
        
        $visit_stmt->execute([
            ':branch_id' => $user_branch_id,
            ':patient_id' => $patient_id,
            ':visit_number' => $visit_number,
            ':date' => $date,
            ':time' => $time_with_seconds,
            ':visit_type' => $visit_type,
            ':card_fee_status' => $card_fee_status,
            ':payment_mode' => $billing_type,
            ':billing_status' => 'unpaid',
            ':visit_status' => 'pending',
            ':queue_token' => $queue_token,
            ':is_priority' => $is_priority,
            ':registration_notes' => $notes_text,
            ':user_id' => $user_id
        ]);
        
        $visit_id = (int)$pdo->lastInsertId();
        
        // Insert appointment
        $stmt = $pdo->prepare("
            INSERT INTO appointments
            (appointment_number, branch_id, patient_id, doctor_id, department_id, 
             visit_id, appointment_date, appointment_time, visit_type, billing_status,
             priority, status, queue_token_number, estimated_wait_minutes,
             chief_complaint, symptoms_duration, referral_source, is_emergency_walk_in,
             notes, created_by_user_id, created_at, updated_at)
            VALUES 
            (:apt_num, :branch_id, :patient_id, :doctor_id, :dept_id,
             :visit_id, :date, :time, :visit_type, :billing_status,
             :priority, :status, :queue_token, :estimated_wait,
             :chief_complaint, :symptoms_duration, :referral_source, :is_emergency_walk_in,
             :notes, :user_id, NOW(), NOW())
        ");
        
        $stmt->execute([
            ':apt_num' => $appointment_number,
            ':branch_id' => $user_branch_id,
            ':patient_id' => $patient_id,
            ':doctor_id' => $doctor_id,
            ':dept_id' => $department_id,
            ':visit_id' => $visit_id,
            ':date' => $date,
            ':time' => $time_with_seconds,
            ':visit_type' => $visit_type,
            ':billing_status' => 'unpaid',
            ':priority' => $priority,
            ':status' => $status,
            ':queue_token' => $queue_token,
            ':estimated_wait' => $estimated_wait_minutes,
            ':chief_complaint' => $chief_complaint,
            ':symptoms_duration' => $symptoms_duration,
            ':referral_source' => $referral_source,
            ':is_emergency_walk_in' => $is_emergency_walk_in,
            ':notes' => $notes_text,
            ':user_id' => $user_id
        ]);
        
        $appointment_id = (int)$pdo->lastInsertId();
        
        // Log the action for audit trail
        if (function_exists('log_user_action')) {
            log_user_action($pdo, 'INSERT', 'appointments', $appointment_id, null, [
                'appointment_number' => $appointment_number,
                'patient_id' => $patient_id,
                'doctor_id' => $doctor_id,
                'appointment_date' => $date,
                'visit_id' => $visit_id,
                'queue_token' => $queue_token
            ]);
        }
        
        $pdo->commit();
        
        json_exit([
            'success' => true,
            'message' => 'Appointment booked successfully.',
            'appointment_id' => $appointment_id,
            'appointment_number' => $appointment_number,
            'visit_id' => $visit_id,
            'visit_number' => $visit_number,
            'queue_token' => $queue_token,
            'estimated_wait' => $estimated_wait_minutes
        ]);
        
    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log("Book appointment error: " . $e->getMessage());
        json_exit(['success' => false, 'message' => 'Booking failed: ' . $e->getMessage()], 500);
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Book appointment error: " . $e->getMessage());
        json_exit(['success' => false, 'message' => 'Booking failed: ' . $e->getMessage()], 500);
    }
}

// ============================================
// Fetch departments for dropdown (branch isolated)
// ============================================
$departments = [];
$dept_stmt = $pdo->prepare("
    SELECT id, code, name, color_code, is_point_of_entry
    FROM departments
    WHERE branch_id = :branch_id AND is_active = 1 AND deleted_at IS NULL
    ORDER BY display_order, name
");
$dept_stmt->execute([':branch_id' => $user_branch_id]);
$departments = $dept_stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch billing types from system settings
$billing_types = [
    'cash' => 'Cash',
    'insurance' => 'Insurance',
    'corporate' => 'Corporate',
    'package' => 'Package',
    'free_treatment' => 'Free Treatment'
];

// Fetch visit types from system settings
$visit_types = [
    'regular' => 'Regular',
    'emergency' => 'Emergency',
    'follow_up' => 'Follow-up',
    'telemedicine' => 'Telemedicine'
];

$branch_name = '';
$stmt = $pdo->prepare("SELECT name FROM branches WHERE id = :id AND deleted_at IS NULL LIMIT 1");
$stmt->execute([':id' => $user_branch_id]);
$branchResult = $stmt->fetch(PDO::FETCH_ASSOC);
if ($branchResult) $branch_name = $branchResult['name'];

$current_date = date('l, F j, Y');
$current_time_display = date('g:i A');

// Preload patient data if patient_id provided
$prefill_patient_data = null;
if ($prefill_patient_id > 0) {
    $stmt = $pdo->prepare("
        SELECT 
            id, mr_number, 
            CONCAT(first_name, ' ', COALESCE(middle_name, ''), ' ', last_name) as full_name,
            phone, email, gender, date_of_birth
        FROM patients 
        WHERE id = :id AND branch_id = :branch_id AND is_active = 1 AND deleted_at IS NULL
    ");
    $stmt->execute([':id' => $prefill_patient_id, ':branch_id' => $user_branch_id]);
    $prefill_patient_data = $stmt->fetch(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include('assets/inc/head.php'); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Book Appointment | HMS ERP</title>
    
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
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
            --slate: #455a64;
            --navy: #1a237e;
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
        
        .glass-header {
            background: linear-gradient(135deg, var(--navy) 0%, var(--primary-dark) 100%);
            backdrop-filter: blur(10px);
            border-radius: 28px;
            padding: 24px 32px;
            margin-bottom: 28px;
            color: white;
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
            font-size: 22px;
            font-weight: 700;
            letter-spacing: -0.5px;
        }
        
        .greeting-sub {
            font-size: 13px;
            opacity: 0.8;
            margin-top: 4px;
        }
        
        .time-badge {
            background: rgba(255,255,255,0.15);
            backdrop-filter: blur(5px);
            padding: 8px 18px;
            border-radius: 40px;
            font-size: 13px;
            font-weight: 500;
        }
        
        .form-card {
            background: rgba(255,255,255,0.95);
            backdrop-filter: blur(10px);
            border-radius: 24px;
            overflow: hidden;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            border: 1px solid rgba(255,255,255,0.3);
        }
        
        .form-card-header {
            padding: 20px 28px;
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .form-card-body {
            padding: 28px;
        }
        
        .form-group {
            margin-bottom: 24px;
        }
        
        .form-label {
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 8px;
            display: block;
            color: var(--dark);
        }
        
        .form-label i {
            width: 20px;
            color: var(--primary);
        }
        
        .form-control, .custom-select {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            font-size: 14px;
            transition: all 0.2s;
            background: white;
        }
        
        .form-control:focus, .custom-select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(67,97,238,0.1);
        }
        
        .search-wrap {
            position: relative;
        }
        
        .search-results {
            position: absolute;
            z-index: 100;
            width: 100%;
            background: white;
            border-radius: 16px;
            margin-top: 8px;
            max-height: 300px;
            overflow-y: auto;
            box-shadow: 0 10px 30px rgba(0,0,0,0.15);
            display: none;
            border: 1px solid #e2e8f0;
        }
        
        .search-item {
            padding: 12px 16px;
            cursor: pointer;
            border-bottom: 1px solid #e2e8f0;
            transition: background 0.2s;
        }
        
        .search-item:hover {
            background: #f8fafc;
        }
        
        .selected-patient {
            background: #f0fdf4;
            border-radius: 12px;
            padding: 12px 16px;
            margin-top: 12px;
            border-left: 3px solid var(--secondary);
        }
        
        .btn-primary-custom {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            border: none;
            padding: 12px 28px;
            border-radius: 40px;
            font-weight: 600;
            color: white;
            transition: all 0.2s;
            cursor: pointer;
        }
        
        .btn-primary-custom:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(67,97,238,0.3);
        }
        
        .btn-secondary-custom {
            background: white;
            border: 1px solid #e2e8f0;
            padding: 12px 28px;
            border-radius: 40px;
            font-weight: 600;
            color: var(--slate);
            transition: all 0.2s;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
        }
        
        .btn-secondary-custom:hover {
            border-color: var(--primary);
            color: var(--primary);
        }
        
        .required:after {
            content: " *";
            color: var(--danger);
        }
        
        .availability-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 12px;
            border-radius: 30px;
            font-size: 11px;
            font-weight: 600;
            margin-top: 8px;
        }
        
        .availability-available {
            background: #d1fae5;
            color: #065f46;
        }
        
        .availability-unavailable {
            background: #fee2e2;
            color: #991b1b;
        }
        
        hr {
            margin: 24px 0;
            border-color: #e2e8f0;
        }
        
        .row {
            display: flex;
            flex-wrap: wrap;
            margin: 0 -12px;
        }
        
        .col-md-4, .col-md-6 {
            padding: 0 12px;
            flex: 1;
        }
        
        .col-md-4 { flex: 0 0 33.333333%; max-width: 33.333333%; }
        .col-md-6 { flex: 0 0 50%; max-width: 50%; }
        .col-md-12 { flex: 0 0 100%; max-width: 100%; }
        
        @media (max-width: 768px) {
            .col-md-4, .col-md-6 { flex: 0 0 100%; max-width: 100%; margin-bottom: 15px; }
        }
        
        .gap-3 { gap: 1rem; }
        .mt-1 { margin-top: 0.25rem; }
        .mt-2 { margin-top: 0.5rem; }
        .mt-3 { margin-top: 1rem; }
        .mb-0 { margin-bottom: 0; }
        .me-1 { margin-right: 0.25rem; }
        .me-2 { margin-right: 0.5rem; }
        .ms-2 { margin-left: 0.5rem; }
        .d-flex { display: flex; }
        .justify-content-end { justify-content: flex-end; }
        .align-items-center { align-items: center; }
        .flex-wrap { flex-wrap: wrap; }
        .text-muted { color: #6c757d; }
        .text-success { color: #10b981; }
        .text-danger { color: #ef4444; }
        .text-primary { color: var(--primary); }
        .fw-bold { font-weight: 700; }
        .small { font-size: 0.875rem; }
        
        .badge-insurance {
            background: #dbeafe;
            color: #1e40af;
            padding: 2px 10px;
            border-radius: 20px;
            font-size: 11px;
        }
        
        .badge-free {
            background: #fef3c7;
            color: #92400e;
            padding: 2px 10px;
            border-radius: 20px;
            font-size: 11px;
        }
        
        .patient-extra-info {
            margin-top: 8px;
        }
        
        .patient-extra-info .badge {
            display: inline-block;
            margin-right: 4px;
        }
        
        .insurance-select-group {
            background: #f8fafc;
            border-radius: 12px;
            padding: 16px;
            border: 1px solid #e2e8f0;
        }
        
        .queue-token-display {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
            border-radius: 12px;
            padding: 12px 20px;
            text-align: center;
            margin-top: 8px;
        }
        
        .queue-token-display .token-number {
            font-size: 28px;
            font-weight: 800;
        }
        
        .queue-token-display .token-label {
            font-size: 11px;
            opacity: 0.8;
        }
    </style>
</head>
<body>

<div id="wrapper">
    <?php include('assets/inc/nav.php'); ?>
    <?php include('assets/inc/sidebar.php'); ?>

    <div class="content-page">
        <div class="container-fluid">
            
            <div class="glass-header">
                <div class="d-flex justify-content-between align-items-center flex-wrap">
                    <div>
                        <div class="greeting-text">
                            <i class="fas fa-calendar-plus me-3"></i>Schedule New Appointment
                        </div>
                        <div class="greeting-sub">
                            Book appointments with smart conflict detection • <?php echo htmlspecialchars($branch_name, ENT_QUOTES, 'UTF-8'); ?>
                        </div>
                    </div>
                    <div class="mt-3 mt-md-0">
                        <div class="time-badge">
                            <i class="far fa-calendar-alt me-2"></i> <?php echo $current_date; ?>
                            <i class="far fa-clock ms-2 me-1"></i> <?php echo $current_time_display; ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="form-card">
                <div class="form-card-header">
                    <h6 class="mb-0"><i class="fas fa-edit me-2 text-primary"></i> Appointment Information</h6>
                </div>
                <div class="form-card-body">
                    <form id="bookingForm" novalidate>
                        <input type="hidden" name="ajax" value="book">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="patient_id" id="patientId" value="<?php echo $prefill_patient_id > 0 ? $prefill_patient_id : ''; ?>">
                        <input type="hidden" name="insurance_profile_id" id="insuranceProfileId" value="">
                        
                        <!-- Patient Selection -->
                        <div class="form-group">
                            <label class="form-label required"><i class="fas fa-user"></i> Patient Search</label>
                            <div class="search-wrap">
                                <input type="text" id="patientSearch" class="form-control" 
                                       placeholder="Search by name, MRN, or phone number..." autocomplete="off"
                                       value="<?php echo $prefill_patient_data ? htmlspecialchars($prefill_patient_data['full_name'] ?? '', ENT_QUOTES, 'UTF-8') : ''; ?>">
                                <div id="patientResults" class="search-results" style="display:none;"></div>
                            </div>
                            <div id="selectedPatientInfo" class="selected-patient" style="<?php echo $prefill_patient_data ? 'display:block;' : 'display:none;'; ?>">
                                <i class="fas fa-check-circle text-success me-2"></i>
                                <span id="selectedPatientName">
                                    <?php echo $prefill_patient_data ? htmlspecialchars($prefill_patient_data['full_name'] ?? '', ENT_QUOTES, 'UTF-8') : ''; ?>
                                </span>
                                <small class="text-muted d-block mt-1" id="selectedPatientDetails">
                                    <?php if ($prefill_patient_data): ?>
                                        MRN: <?php echo htmlspecialchars($prefill_patient_data['mr_number'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?> | 
                                        Phone: <?php echo htmlspecialchars($prefill_patient_data['phone'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?>
                                        <?php if (!empty($prefill_patient_data['date_of_birth'])): ?>
                                            | Age: <?php 
                                                $birth = new DateTime($prefill_patient_data['date_of_birth']);
                                                $now = new DateTime();
                                                echo $birth->diff($now)->y; 
                                            ?> yrs
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </small>
                            </div>
                        </div>
                        
                        <!-- Appointment Details Row 1 -->
                        <div class="row">
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label class="form-label required"><i class="fas fa-calendar-day"></i> Appointment Date</label>
                                    <input type="date" name="appointment_date" id="appointmentDate" class="form-control" 
                                           min="<?php echo date('Y-m-d'); ?>" 
                                           value="<?php echo htmlspecialchars($prefill_date, ENT_QUOTES, 'UTF-8'); ?>" required>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label class="form-label required"><i class="fas fa-clock"></i> Appointment Time</label>
                                    <input type="time" name="appointment_time" id="appointmentTime" class="form-control" 
                                           value="<?php echo htmlspecialchars($prefill_time, ENT_QUOTES, 'UTF-8'); ?>" required>
                                    <div id="availabilityIndicator" class="availability-badge" style="display:none;"></div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label class="form-label required"><i class="fas fa-flag"></i> Priority</label>
                                    <select name="priority" id="prioritySelect" class="custom-select" required>
                                        <option value="normal">Normal</option>
                                        <option value="urgent">⚠️ Urgent</option>
                                        <option value="stat">🚨 STAT (Emergency)</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Appointment Details Row 2 -->
                        <div class="row">
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label class="form-label required"><i class="fas fa-building"></i> Department</label>
                                    <select id="departmentId" name="department_id" class="custom-select" required>
                                        <option value="">Select department</option>
                                        <?php foreach ($departments as $d): ?>
                                            <option value="<?php echo (int)$d['id']; ?>"
                                                    data-color="<?php echo htmlspecialchars($d['color_code'] ?? '#4361ee', ENT_QUOTES, 'UTF-8'); ?>">
                                                <?php echo htmlspecialchars($d['name'], ENT_QUOTES, 'UTF-8'); ?> 
                                                <?php if ($d['is_point_of_entry']): ?>⭐<?php endif; ?>
                                                (<?php echo htmlspecialchars($d['code'], ENT_QUOTES, 'UTF-8'); ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label class="form-label required"><i class="fas fa-user-md"></i> Doctor</label>
                                    <select id="doctorId" name="doctor_id" class="custom-select" required disabled>
                                        <option value="">Select department first</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label class="form-label required"><i class="fas fa-procedures"></i> Visit Type</label>
                                    <select name="visit_type" id="visitTypeSelect" class="custom-select" required>
                                        <?php foreach ($visit_types as $key => $label): ?>
                                            <option value="<?php echo $key; ?>" <?php echo $key === 'regular' ? 'selected' : ''; ?>>
                                                <?php echo $label; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Billing & Insurance -->
                        <div class="row">
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label class="form-label required"><i class="fas fa-money-bill-wave"></i> Billing Type</label>
                                    <select name="billing_type" id="billingTypeSelect" class="custom-select" required>
                                        <?php foreach ($billing_types as $key => $label): ?>
                                            <option value="<?php echo $key; ?>">
                                                <?php echo $label; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-8">
                                <div class="form-group" id="insuranceGroup" style="display:none;">
                                    <label class="form-label"><i class="fas fa-shield-alt"></i> Insurance Profile</label>
                                    <div id="insuranceProfilesContainer" class="insurance-select-group">
                                        <p class="text-muted small mb-0">Select a patient first to view insurance profiles.</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Emergency Walk-in -->
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="form-label"><i class="fas fa-ambulance"></i> Emergency Walk-in</label>
                                    <div class="d-flex align-items-center">
                                        <input type="checkbox" name="is_emergency_walk_in" id="emergencyWalkIn" value="1" class="me-2" style="width:18px;height:18px;">
                                        <label for="emergencyWalkIn" class="mb-0 small">Mark as emergency walk-in (bypasses normal queue)</label>
                                    </div>
                                    <small class="text-muted">Emergency walk-ins are prioritized in the queue</small>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="form-label"><i class="fas fa-tag"></i> Referral Source</label>
                                    <input type="text" name="referral_source" class="form-control" 
                                           placeholder="e.g., Dr. Smith, External Clinic, Self">
                                </div>
                            </div>
                        </div>
                        
                        <!-- Chief Complaint & Symptoms -->
                        <div class="row">
                            <div class="col-md-8">
                                <div class="form-group">
                                    <label class="form-label required"><i class="fas fa-comment-medical"></i> Chief Complaint</label>
                                    <textarea name="chief_complaint" id="chiefComplaint" class="form-control" rows="2" 
                                              placeholder="Describe the patient's symptoms or reason for visit (Amharic supported)..." required></textarea>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label class="form-label"><i class="fas fa-hourglass-half"></i> Symptoms Duration</label>
                                    <input type="text" name="symptoms_duration" class="form-control" 
                                           placeholder="e.g., 3 days, 2 weeks, since yesterday">
                                </div>
                            </div>
                        </div>
                        
                        <!-- Additional Notes -->
                        <div class="form-group">
                            <label class="form-label"><i class="fas fa-sticky-note"></i> Additional Notes</label>
                            <textarea name="notes" class="form-control" rows="2" 
                                      placeholder="Any additional information about the patient or appointment..."></textarea>
                        </div>
                        
                        <!-- Queue Token Display (will be populated after booking) -->
                        <div id="queueTokenDisplay" style="display:none;">
                            <hr>
                            <div class="queue-token-display">
                                <div class="token-label">Queue Token</div>
                                <div class="token-number" id="queueTokenNumber">--</div>
                                <div class="token-label">Estimated Wait: <span id="estimatedWait">--</span> minutes</div>
                            </div>
                        </div>
                        
                        <hr>
                        
                        <div class="d-flex justify-content-end gap-3">
                            <a href="calendar_view.php" class="btn-secondary-custom">
                                <i class="fas fa-arrow-left me-2"></i>Cancel
                            </a>
                            <button type="submit" class="btn-primary-custom" id="submitBtn">
                                <i class="fas fa-save me-2"></i>Book Appointment
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            
        </div>
    </div>
    
    <?php include('assets/inc/footer.php'); ?>
</div>

<script src="assets/js/vendor.min.js"></script>
<script src="assets/js/app.min.js"></script>

<script>
(function() {
    const patientSearch = document.getElementById('patientSearch');
    const patientResults = document.getElementById('patientResults');
    const patientIdInput = document.getElementById('patientId');
    const selectedPatientInfo = document.getElementById('selectedPatientInfo');
    const selectedPatientName = document.getElementById('selectedPatientName');
    const selectedPatientDetails = document.getElementById('selectedPatientDetails');
    const departmentSelect = document.getElementById('departmentId');
    const doctorSelect = document.getElementById('doctorId');
    const appointmentDate = document.getElementById('appointmentDate');
    const appointmentTime = document.getElementById('appointmentTime');
    const availabilityIndicator = document.getElementById('availabilityIndicator');
    const chiefComplaint = document.getElementById('chiefComplaint');
    const bookingForm = document.getElementById('bookingForm');
    const billingTypeSelect = document.getElementById('billingTypeSelect');
    const insuranceGroup = document.getElementById('insuranceGroup');
    const insuranceProfilesContainer = document.getElementById('insuranceProfilesContainer');
    const prioritySelect = document.getElementById('prioritySelect');
    const visitTypeSelect = document.getElementById('visitTypeSelect');
    const emergencyWalkIn = document.getElementById('emergencyWalkIn');
    const queueTokenDisplay = document.getElementById('queueTokenDisplay');
    const queueTokenNumber = document.getElementById('queueTokenNumber');
    const estimatedWait = document.getElementById('estimatedWait');
    
    let searchTimeout = null;
    let selectedPatient = null;
    let insuranceProfiles = [];
    let currentAppointmentId = null;
    
    const API_BASE = 'book_appointment.php';
    const csrfToken = document.querySelector('input[name="csrf_token"]')?.value || '';
    
    // Auto-populate if patient pre-selected
    <?php if ($prefill_patient_data): ?>
    selectedPatient = {
        id: <?php echo (int)$prefill_patient_data['id']; ?>,
        name: '<?php echo htmlspecialchars($prefill_patient_data['full_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>',
        mr_number: '<?php echo htmlspecialchars($prefill_patient_data['mr_number'] ?? '', ENT_QUOTES, 'UTF-8'); ?>',
        phone: '<?php echo htmlspecialchars($prefill_patient_data['phone'] ?? '', ENT_QUOTES, 'UTF-8'); ?>'
    };
    patientIdInput.value = <?php echo (int)$prefill_patient_data['id']; ?>;
    // Load insurance profiles for pre-selected patient
    loadPatientInsurance(<?php echo (int)$prefill_patient_data['id']; ?>);
    <?php endif; ?>
    
    function escapeHtml(str) {
        return String(str || '').replace(/[&<>]/g, function(m) {
            if (m === '&') return '&amp;';
            if (m === '<') return '&lt;';
            if (m === '>') return '&gt;';
            return m;
        });
    }
    
    function showToast(message, type = 'success') {
        Swal.fire({
            title: type === 'success' ? 'Success' : 'Error',
            text: message,
            icon: type,
            toast: true,
            position: 'top-end',
            showConfirmButton: false,
            timer: 3000,
            timerProgressBar: true
        });
    }
    
    function showLoadingToast(message) {
        Swal.fire({
            title: message,
            allowOutsideClick: false,
            didOpen: () => Swal.showLoading()
        });
    }
    
    function hideLoadingToast() {
        Swal.close();
    }
    
    // Load patient insurance profiles
    async function loadPatientInsurance(patientId) {
        if (!patientId || patientId <= 0) {
            insuranceProfilesContainer.innerHTML = '<p class="text-muted small mb-0">Select a patient first to view insurance profiles.</p>';
            return;
        }
        
        try {
            const response = await fetch(`${API_BASE}?ajax=patient_insurance&patient_id=${encodeURIComponent(patientId)}`);
            const data = await response.json();
            
            if (!data.success) throw new Error(data.message);
            
            insuranceProfiles = data.profiles || [];
            
            if (insuranceProfiles.length === 0) {
                insuranceProfilesContainer.innerHTML = '<p class="text-muted small mb-0">No active insurance profiles found for this patient.</p>';
                return;
            }
            
            let html = '<div class="d-flex flex-wrap gap-2">';
            insuranceProfiles.forEach(function(profile, index) {
                const isPrimary = profile.is_primary == 1;
                html += `
                    <div class="d-flex align-items-center p-2 bg-white rounded border ${isPrimary ? 'border-primary' : ''}" 
                         style="cursor:pointer;" onclick="document.getElementById('insuranceProfileId').value = ${profile.id}; document.querySelectorAll('.ins-profile-select').forEach(el => el.classList.remove('active')); this.classList.add('active');">
                        <div>
                            <div class="fw-bold small">${escapeHtml(profile.provider_name)}</div>
                            <div class="small text-muted">Policy: ${escapeHtml(profile.policy_number)}</div>
                            <div class="small">Coverage: ${profile.coverage_percentage}% ${isPrimary ? '⭐ Primary' : ''}</div>
                        </div>
                    </div>
                `;
            });
            html += '</div>';
            html += '<div class="mt-2 small text-muted">Click on a profile to select it for this appointment.</div>';
            
            insuranceProfilesContainer.innerHTML = html;
            
            // Auto-select primary profile
            const primary = insuranceProfiles.find(p => p.is_primary == 1);
            if (primary) {
                document.getElementById('insuranceProfileId').value = primary.id;
                const items = document.querySelectorAll('.ins-profile-select');
                if (items.length > 0) items[0].classList.add('active');
            }
            
        } catch (err) {
            console.error('Load insurance error:', err);
            insuranceProfilesContainer.innerHTML = '<p class="text-danger small">Failed to load insurance profiles.</p>';
        }
    }
    
    // Patient Search
    patientSearch.addEventListener('input', function() {
        const query = this.value.trim();
        clearTimeout(searchTimeout);
        
        if (query.length < 2) {
            patientResults.style.display = 'none';
            return;
        }
        
        searchTimeout = setTimeout(async () => {
            try {
                const response = await fetch(`${API_BASE}?ajax=patient_search&q=${encodeURIComponent(query)}`);
                const data = await response.json();
                
                if (!data.success) throw new Error(data.message);
                
                const items = data.items || [];
                
                if (items.length === 0) {
                    patientResults.innerHTML = '<div class="search-item text-muted">No patients found. <a href="patient_directory.php">Register new patient?</a></div>';
                    patientResults.style.display = 'block';
                    return;
                }
                
                patientResults.innerHTML = items.map(p => `
                    <div class="search-item" data-id="${p.id}" data-name="${escapeHtml(p.name)}" data-mrn="${escapeHtml(p.mr_number)}" data-phone="${escapeHtml(p.phone)}" data-age="${p.age || ''}" data-has-insurance="${p.has_insurance ? 'true' : 'false'}" data-free="${p.free_treatment_eligible ? 'true' : 'false'}">
                        <div class="d-flex justify-content-between align-items-center">
                            <div class="fw-bold">${escapeHtml(p.name)}</div>
                            <div>
                                ${p.has_insurance ? '<span class="badge-insurance"><i class="fas fa-shield-alt me-1"></i>Insured</span>' : ''}
                                ${p.free_treatment_eligible ? '<span class="badge-free"><i class="fas fa-gift me-1"></i>Free</span>' : ''}
                            </div>
                        </div>
                        <div class="small text-muted">
                            MRN: ${escapeHtml(p.mr_number)} | Phone: ${escapeHtml(p.phone || 'N/A')}
                            ${p.age ? `| Age: ${p.age} yrs` : ''}
                            ${p.insurance_provider ? `| ${escapeHtml(p.insurance_provider)}` : ''}
                        </div>
                    </div>
                `).join('');
                patientResults.style.display = 'block';
                
            } catch (err) {
                console.error('Patient search error:', err);
                patientResults.innerHTML = '<div class="search-item text-danger">Search failed. Please try again.</div>';
                patientResults.style.display = 'block';
            }
        }, 300);
    });
    
    // Select Patient
    patientResults.addEventListener('click', function(e) {
        const item = e.target.closest('.search-item[data-id]');
        if (!item) return;
        
        const id = item.getAttribute('data-id');
        const name = item.getAttribute('data-name');
        const mrn = item.getAttribute('data-mrn');
        const phone = item.getAttribute('data-phone');
        const age = item.getAttribute('data-age');
        const hasInsurance = item.getAttribute('data-has-insurance') === 'true';
        const isFree = item.getAttribute('data-free') === 'true';
        
        patientIdInput.value = id;
        patientSearch.value = name;
        selectedPatientName.textContent = name;
        selectedPatientDetails.textContent = `MRN: ${mrn} | Phone: ${phone || 'N/A'}${age ? ` | Age: ${age} yrs` : ''}`;
        
        // Show badges
        let badges = '';
        if (hasInsurance) badges += '<span class="badge-insurance me-1"><i class="fas fa-shield-alt me-1"></i>Insured</span>';
        if (isFree) badges += '<span class="badge-free"><i class="fas fa-gift me-1"></i>Free Treatment Eligible</span>';
        if (badges) {
            selectedPatientDetails.innerHTML += `<div class="patient-extra-info mt-1">${badges}</div>`;
        }
        
        selectedPatientInfo.style.display = 'block';
        patientResults.style.display = 'none';
        selectedPatient = { id, name, mrn, phone };
        
        // Load insurance profiles
        loadPatientInsurance(id);
    });
    
    document.addEventListener('click', function(e) {
        if (patientResults && !patientResults.contains(e.target) && e.target !== patientSearch) {
            patientResults.style.display = 'none';
        }
    });
    
    // Load Doctors by Department
    departmentSelect.addEventListener('change', async function() {
        const deptId = this.value;
        
        if (!deptId) {
            doctorSelect.innerHTML = '<option value="">Select department first</option>';
            doctorSelect.disabled = true;
            return;
        }
        
        doctorSelect.innerHTML = '<option value="">Loading doctors...</option>';
        doctorSelect.disabled = true;
        
        try {
            const response = await fetch(`${API_BASE}?ajax=doctors_by_department&department_id=${deptId}`);
            const data = await response.json();
            
            if (!data.success) throw new Error(data.message);
            
            const items = data.items || [];
            
            if (items.length === 0) {
                doctorSelect.innerHTML = '<option value="">No doctors available in this department</option>';
                doctorSelect.disabled = true;
            } else {
                doctorSelect.innerHTML = '<option value="">Select doctor</option>' + 
                    items.map(d => `<option value="${d.id}" data-specialist="${escapeHtml(d.specialist)}">${escapeHtml(d.name)} (${escapeHtml(d.specialist)})</option>`).join('');
                doctorSelect.disabled = false;
            }
            
        } catch (err) {
            console.error('Load doctors error:', err);
            doctorSelect.innerHTML = '<option value="">Failed to load doctors</option>';
            doctorSelect.disabled = true;
            showToast('Failed to load doctors', 'error');
        }
    });
    
    // Check Availability
    async function checkAvailability() {
        const doctorId = doctorSelect.value;
        const date = appointmentDate.value;
        const time = appointmentTime.value;
        
        if (!doctorId || !date || !time || doctorSelect.disabled) {
            availabilityIndicator.style.display = 'none';
            return;
        }
        
        try {
            const response = await fetch(`${API_BASE}?ajax=check_availability&doctor_id=${doctorId}&date=${date}&time=${time}`);
            const data = await response.json();
            
            if (data.success && data.available) {
                availabilityIndicator.innerHTML = '<i class="fas fa-check-circle me-1"></i> Time slot available';
                availabilityIndicator.className = 'availability-badge availability-available';
                availabilityIndicator.style.display = 'inline-flex';
            } else {
                const msg = data.message || 'Doctor already booked';
                availabilityIndicator.innerHTML = `<i class="fas fa-exclamation-triangle me-1"></i> ${msg}`;
                availabilityIndicator.className = 'availability-badge availability-unavailable';
                availabilityIndicator.style.display = 'inline-flex';
            }
        } catch (err) {
            console.error('Availability check error:', err);
            availabilityIndicator.style.display = 'none';
        }
    }
    
    doctorSelect.addEventListener('change', checkAvailability);
    appointmentDate.addEventListener('change', function() {
        // Update min date for time input if needed
        checkAvailability();
    });
    appointmentTime.addEventListener('change', checkAvailability);
    
    // Handle billing type change
    billingTypeSelect.addEventListener('change', function() {
        const billingType = this.value;
        if (billingType === 'insurance') {
            insuranceGroup.style.display = 'block';
            if (selectedPatient && selectedPatient.id) {
                loadPatientInsurance(selectedPatient.id);
            } else {
                insuranceProfilesContainer.innerHTML = '<p class="text-muted small mb-0">Select a patient first to view insurance profiles.</p>';
            }
        } else {
            insuranceGroup.style.display = 'none';
            document.getElementById('insuranceProfileId').value = '';
        }
    });
    
    // Handle priority change - auto-set visit type
    prioritySelect.addEventListener('change', function() {
        const priority = this.value;
        if (priority === 'stat') {
            visitTypeSelect.value = 'emergency';
            emergencyWalkIn.checked = true;
        }
    });
    
    // Handle emergency walk-in
    emergencyWalkIn.addEventListener('change', function() {
        if (this.checked) {
            visitTypeSelect.value = 'emergency';
            prioritySelect.value = 'urgent';
        }
    });
    
    // Form Submission
    bookingForm.addEventListener('submit', async function(e) {
        e.preventDefault();
        
        if (!patientIdInput.value) {
            showToast('Please select a patient', 'error');
            return;
        }
        
        if (!departmentSelect.value) {
            showToast('Please select a department', 'error');
            return;
        }
        
        if (!doctorSelect.value || doctorSelect.disabled) {
            showToast('Please select a doctor', 'error');
            return;
        }
        
        if (!appointmentDate.value) {
            showToast('Please select an appointment date', 'error');
            return;
        }
        
        if (!appointmentTime.value) {
            showToast('Please select an appointment time', 'error');
            return;
        }
        
        if (!chiefComplaint.value.trim()) {
            showToast('Please enter the chief complaint', 'error');
            return;
        }
        
        // Check insurance requirement
        if (billingTypeSelect.value === 'insurance' && !document.getElementById('insuranceProfileId').value) {
            showToast('Please select an insurance profile for insurance billing', 'error');
            return;
        }
        
        const formData = new FormData(bookingForm);
        
        showLoadingToast('Booking appointment...');
        
        try {
            const response = await fetch(API_BASE, { method: 'POST', body: formData });
            const data = await response.json();
            
            hideLoadingToast();
            
            if (data.success) {
                // Show queue token
                if (data.queue_token) {
                    queueTokenNumber.textContent = data.queue_token;
                    estimatedWait.textContent = data.estimated_wait || '--';
                    queueTokenDisplay.style.display = 'block';
                }
                
                const message = data.message || 'Appointment booked successfully!';
                if (data.queue_token) {
                    showToast(`${message} | Queue #${data.queue_token}`, 'success');
                } else {
                    showToast(message, 'success');
                }
                
                // Reset form but keep patient selection
                const patientId = patientIdInput.value;
                const patientName = patientSearch.value;
                const dateValue = appointmentDate.value;
                const timeValue = appointmentTime.value;
                
                bookingForm.reset();
                patientIdInput.value = patientId;
                patientSearch.value = patientName;
                appointmentDate.value = dateValue;
                appointmentTime.value = timeValue;
                
                // Reset doctor select
                doctorSelect.innerHTML = '<option value="">Select department first</option>';
                doctorSelect.disabled = true;
                availabilityIndicator.style.display = 'none';
                
                // Keep patient info
                if (selectedPatient) {
                    selectedPatientInfo.style.display = 'block';
                }
                
                // Show success with redirect option
                Swal.fire({
                    icon: 'success',
                    title: 'Appointment Booked!',
                    html: `
                        <p>${message}</p>
                        ${data.queue_token ? `<div class="mt-2"><span class="fw-bold">Queue #${data.queue_token}</span> | Est. Wait: ${data.estimated_wait || '--'} min</div>` : ''}
                        ${data.appointment_number ? `<div class="small text-muted">Appointment: ${data.appointment_number}</div>` : ''}
                    `,
                    confirmButtonColor: '#4361ee',
                    confirmButtonText: 'View Calendar',
                    showCancelButton: true,
                    cancelButtonText: 'Book Another'
                }).then((result) => {
                    if (result.isConfirmed) {
                        window.location.href = 'calendar_view.php';
                    } else {
                        // Reset for new booking
                        patientSearch.value = '';
                        selectedPatientInfo.style.display = 'none';
                        selectedPatient = null;
                        patientIdInput.value = '';
                        insuranceProfilesContainer.innerHTML = '<p class="text-muted small mb-0">Select a patient first to view insurance profiles.</p>';
                        queueTokenDisplay.style.display = 'none';
                        chiefComplaint.value = '';
                    }
                });
            } else {
                showToast(data.message || 'Booking failed', 'error');
            }
        } catch (err) {
            hideLoadingToast();
            console.error('Booking error:', err);
            showToast('Network error. Please try again.', 'error');
        }
    });
    
    const today = new Date().toISOString().split('T')[0];
    appointmentDate.min = today;
})();
</script>

</body>
</html>