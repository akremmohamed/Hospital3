<?php
/**
 * Waiting Room Display - HMS ERP System
 * Real-time patient queue display for waiting rooms / TV screens
 * 
 * @package HMS-ERP
 * @version 1.0
 * @requires nursing_dashboard_access or super_admin
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

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../index.php');
    exit;
}

// Get user information from session
$user_id = (int)($_SESSION['user_id'] ?? 0);
$user_branch_id = (int)($_SESSION['branch_id'] ?? 0);
$is_global_admin = ($_SESSION['user_type'] ?? '') === 'super_admin';
$user_type = $_SESSION['user_type'] ?? '';
$user_name = $_SESSION['first_name'] ?? $_SESSION['username'] ?? 'User';

// Check database availability
global $pdo;
$db_available = ($pdo instanceof PDO);

if (!$db_available) {
    die('Database connection failed. Please check your configuration.');
}

// Permission check - Nurse or Super Admin
$can_access_waiting_room = $is_global_admin;

if (!$can_access_waiting_room && $db_available && $user_id > 0) {
    // Check if user has nurse permissions or waiting room access
    $permissions_to_check = [
        'nursing_dashboard_access',
        'ward_nursing_access',
        'view_waiting_room',
        'view_live_queue'
    ];
    
    foreach ($permissions_to_check as $perm) {
        if (user_has_permission($pdo, $perm, $user_id)) {
            $can_access_waiting_room = true;
            break;
        }
    }
}

// If user has no permission, redirect
if (!$can_access_waiting_room) {
    header('Location: dashboard.php?error=unauthorized');
    exit;
}

// CSRF Token setup for API calls
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

// ============================================
// GET WAITING ROOM QUEUE DATA
// ============================================
function get_waiting_room_queue(PDO $pdo, int $branch_id, bool $is_global_admin): array {
    try {
        // Get patients currently waiting (checked in but not completed)
        $sql = "SELECT 
                    pv.id AS visit_id,
                    pv.visit_number,
                    pv.visit_date,
                    pv.visit_time,
                    pv.queue_token_number,
                    pv.queue_status,
                    pv.is_priority,
                    pv.status AS visit_status,
                    pv.assigned_room_number,
                    pv.checked_in_at,
                    pv.consultation_started_at,
                    pv.consultation_completed_at,
                    
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
                    p.phone,
                    p.date_of_birth,
                    p.blood_group,
                    
                    a.id AS appointment_id,
                    a.appointment_number,
                    a.appointment_date,
                    a.appointment_time,
                    a.chief_complaint,
                    a.priority AS appointment_priority,
                    
                    d.id AS doctor_id,
                    CONCAT(
                        COALESCE(d.first_name, ''),
                        ' ',
                        COALESCE(d.middle_name, ''),
                        ' ',
                        COALESCE(d.last_name, '')
                    ) AS doctor_name,
                    d.user_type AS doctor_type,
                    
                    dep.id AS department_id,
                    dep.name AS department_name,
                    dep.color_code AS department_color,
                    
                    tr.id AS triage_id,
                    tr.priority AS triage_priority,
                    tr.esi_level,
                    tr.heart_rate,
                    tr.blood_pressure_systolic,
                    tr.blood_pressure_diastolic,
                    tr.temperature_celsius,
                    tr.respiratory_rate,
                    tr.oxygen_saturation,
                    tr.pain_score,
                    tr.consciousness,
                    tr.chief_complaint AS triage_chief_complaint,
                    tr.triage_notes,
                    tr.triage_time,
                    
                    CASE 
                        WHEN tr.priority = 'red' THEN 'Critical'
                        WHEN tr.priority = 'yellow' THEN 'Urgent'
                        WHEN tr.priority = 'green' THEN 'Routine'
                        WHEN tr.priority = 'blue' THEN 'Non-urgent'
                        ELSE 'Unknown'
                    END AS priority_label,
                    
                    CASE 
                        WHEN tr.priority = 'red' THEN '#dc2626'
                        WHEN tr.priority = 'yellow' THEN '#f59e0b'
                        WHEN tr.priority = 'green' THEN '#10b981'
                        WHEN tr.priority = 'blue' THEN '#3b82f6'
                        ELSE '#64748b'
                    END AS priority_color,
                    
                    TIMESTAMPDIFF(MINUTE, pv.checked_in_at, NOW()) AS waiting_minutes
                    
                FROM patient_visits pv
                INNER JOIN patients p ON pv.patient_id = p.id AND p.deleted_at IS NULL
                LEFT JOIN appointments a ON pv.id = a.visit_id AND a.deleted_at IS NULL
                LEFT JOIN users d ON a.doctor_id = d.id AND d.deleted_at IS NULL
                LEFT JOIN departments dep ON a.department_id = dep.id AND dep.deleted_at IS NULL
                LEFT JOIN triage_records tr ON pv.id = tr.visit_id AND tr.deleted_at IS NULL
                
                WHERE pv.deleted_at IS NULL
                    AND pv.status IN ('checked_in', 'in_triage', 'in_consultation', 'awaiting_lab', 'lab_ready', 'awaiting_pharmacy')
                    AND pv.branch_id = :branch_id
                
                ORDER BY 
                    CASE tr.priority 
                        WHEN 'red' THEN 1
                        WHEN 'yellow' THEN 2
                        WHEN 'green' THEN 3
                        WHEN 'blue' THEN 4
                        ELSE 5
                    END ASC,
                    pv.is_priority DESC,
                    pv.checked_in_at ASC,
                    pv.queue_token_number ASC
                LIMIT 50";
        
        $params = [':branch_id' => $branch_id];
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $queue = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Clean up patient names
        foreach ($queue as &$patient) {
            $patient['patient_name'] = preg_replace('/\s+/', ' ', trim($patient['patient_name'] ?? 'Unknown Patient'));
            if (!empty($patient['doctor_name'])) {
                $patient['doctor_name'] = preg_replace('/\s+/', ' ', trim($patient['doctor_name']));
            }
        }
        
        // Calculate estimated wait times based on average consultation duration
        $avg_consultation_minutes = 15; // Default
        try {
            $avg_sql = "SELECT AVG(TIMESTAMPDIFF(MINUTE, consultation_started_at, consultation_completed_at)) AS avg_time
                        FROM patient_visits 
                        WHERE consultation_started_at IS NOT NULL 
                        AND consultation_completed_at IS NOT NULL
                        AND branch_id = :branch_id
                        AND deleted_at IS NULL";
            $avg_stmt = $pdo->prepare($avg_sql);
            $avg_stmt->execute([':branch_id' => $branch_id]);
            $avg_result = $avg_stmt->fetch(PDO::FETCH_ASSOC);
            if ($avg_result && $avg_result['avg_time'] > 0) {
                $avg_consultation_minutes = ceil($avg_result['avg_time']);
            }
        } catch (Exception $e) {
            // Use default
        }
        
        // Add estimated wait time for each patient
        $position = 0;
        foreach ($queue as &$patient) {
            $position++;
            $patient['queue_position'] = $position;
            $patient['estimated_wait'] = $position * $avg_consultation_minutes;
            $patient['estimated_wait_display'] = format_wait_time($position * $avg_consultation_minutes);
            
            // Calculate age from DOB
            if (!empty($patient['date_of_birth'])) {
                $dob = new DateTime($patient['date_of_birth']);
                $now = new DateTime();
                $age = $now->diff($dob);
                $patient['age'] = $age->y;
                $patient['age_display'] = $age->y . ' yrs';
            } else {
                $patient['age'] = null;
                $patient['age_display'] = 'N/A';
            }
        }
        
        return $queue;
        
    } catch (PDOException $e) {
        error_log("Get waiting room queue error: " . $e->getMessage());
        return [];
    }
}

// ============================================
// FORMAT WAIT TIME
// ============================================
function format_wait_time(int $minutes): string {
    if ($minutes < 1) return 'Now';
    if ($minutes < 60) return $minutes . ' min';
    $hours = floor($minutes / 60);
    $mins = $minutes % 60;
    return $hours . 'h ' . $mins . 'm';
}

// ============================================
// GET QUEUE STATISTICS
// ============================================
function get_queue_statistics(PDO $pdo, int $branch_id): array {
    try {
        $stats = [
            'total_waiting' => 0,
            'critical' => 0,
            'urgent' => 0,
            'routine' => 0,
            'non_urgent' => 0,
            'in_consultation' => 0,
            'awaiting_lab' => 0,
            'avg_wait_time' => 0,
            'today_served' => 0
        ];
        
        // Total waiting
        $sql = "SELECT COUNT(*) FROM patient_visits 
                WHERE branch_id = :branch_id 
                AND deleted_at IS NULL 
                AND status IN ('checked_in', 'in_triage', 'in_consultation', 'awaiting_lab', 'lab_ready', 'awaiting_pharmacy')";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':branch_id' => $branch_id]);
        $stats['total_waiting'] = (int)$stmt->fetchColumn();
        
        // By triage priority (from triage_records)
        $sql = "SELECT tr.priority, COUNT(DISTINCT pv.id) 
                FROM patient_visits pv
                INNER JOIN triage_records tr ON pv.id = tr.visit_id AND tr.deleted_at IS NULL
                WHERE pv.branch_id = :branch_id 
                AND pv.deleted_at IS NULL 
                AND pv.status IN ('checked_in', 'in_triage', 'in_consultation', 'awaiting_lab', 'lab_ready', 'awaiting_pharmacy')
                AND tr.priority IS NOT NULL
                GROUP BY tr.priority";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':branch_id' => $branch_id]);
        $priorities = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        
        $stats['critical'] = $priorities['red'] ?? 0;
        $stats['urgent'] = $priorities['yellow'] ?? 0;
        $stats['routine'] = $priorities['green'] ?? 0;
        $stats['non_urgent'] = $priorities['blue'] ?? 0;
        
        // In consultation
        $sql = "SELECT COUNT(*) FROM patient_visits 
                WHERE branch_id = :branch_id 
                AND deleted_at IS NULL 
                AND status = 'in_consultation'";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':branch_id' => $branch_id]);
        $stats['in_consultation'] = (int)$stmt->fetchColumn();
        
        // Awaiting lab
        $sql = "SELECT COUNT(*) FROM patient_visits 
                WHERE branch_id = :branch_id 
                AND deleted_at IS NULL 
                AND status IN ('awaiting_lab', 'lab_ready')";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':branch_id' => $branch_id]);
        $stats['awaiting_lab'] = (int)$stmt->fetchColumn();
        
        // Average wait time for today
        $sql = "SELECT AVG(TIMESTAMPDIFF(MINUTE, checked_in_at, consultation_started_at)) AS avg_wait
                FROM patient_visits 
                WHERE branch_id = :branch_id 
                AND deleted_at IS NULL 
                AND consultation_started_at IS NOT NULL
                AND DATE(checked_in_at) = CURDATE()";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':branch_id' => $branch_id]);
        $avg = (int)$stmt->fetchColumn();
        $stats['avg_wait_time'] = $avg > 0 ? $avg : 0;
        
        // Today served
        $sql = "SELECT COUNT(*) FROM patient_visits 
                WHERE branch_id = :branch_id 
                AND deleted_at IS NULL 
                AND status = 'completed'
                AND DATE(created_at) = CURDATE()";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':branch_id' => $branch_id]);
        $stats['today_served'] = (int)$stmt->fetchColumn();
        
        return $stats;
        
    } catch (PDOException $e) {
        error_log("Get queue statistics error: " . $e->getMessage());
        return [
            'total_waiting' => 0,
            'critical' => 0,
            'urgent' => 0,
            'routine' => 0,
            'non_urgent' => 0,
            'in_consultation' => 0,
            'awaiting_lab' => 0,
            'avg_wait_time' => 0,
            'today_served' => 0
        ];
    }
}

// ============================================
// GET DOCTOR AVAILABILITY
// ============================================
function get_doctor_availability(PDO $pdo, int $branch_id): array {
    try {
        $sql = "SELECT 
                    u.id AS doctor_id,
                    CONCAT(
                        COALESCE(u.first_name, ''),
                        ' ',
                        COALESCE(u.middle_name, ''),
                        ' ',
                        COALESCE(u.last_name, '')
                    ) AS doctor_name,
                    d.name AS department_name,
                    COUNT(DISTINCT pv.id) AS current_patients,
                    MAX(CASE WHEN pv.status = 'in_consultation' THEN 1 ELSE 0 END) AS is_busy
                FROM users u
                LEFT JOIN departments d ON u.department_id = d.id
                LEFT JOIN appointments a ON u.id = a.doctor_id AND a.deleted_at IS NULL
                LEFT JOIN patient_visits pv ON a.visit_id = pv.id 
                    AND pv.deleted_at IS NULL 
                    AND pv.status IN ('checked_in', 'in_triage', 'in_consultation')
                WHERE u.branch_id = :branch_id
                    AND u.user_type = 'doctor'
                    AND u.is_active = 1
                    AND u.deleted_at IS NULL
                GROUP BY u.id, u.first_name, u.last_name, d.name
                ORDER BY current_patients ASC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':branch_id' => $branch_id]);
        $doctors = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Clean up doctor names
        foreach ($doctors as &$doctor) {
            $doctor['doctor_name'] = preg_replace('/\s+/', ' ', trim($doctor['doctor_name'] ?? 'Unknown Doctor'));
        }
        
        return $doctors;
        
    } catch (PDOException $e) {
        error_log("Get doctor availability error: " . $e->getMessage());
        return [];
    }
}

// ============================================
// UPDATE PATIENT QUEUE STATUS
// ============================================
function update_queue_status(PDO $pdo, int $visit_id, string $status, int $user_id): bool {
    try {
        $sql = "UPDATE patient_visits 
                SET status = :status, 
                    updated_at = NOW(),
                    queue_status = CASE 
                        WHEN :status = 'in_consultation' THEN 'in_room'
                        WHEN :status = 'completed' THEN 'completed'
                        ELSE 'waiting'
                    END,
                    consultation_started_at = CASE 
                        WHEN :status = 'in_consultation' AND consultation_started_at IS NULL THEN NOW()
                        ELSE consultation_started_at
                    END,
                    consultation_completed_at = CASE 
                        WHEN :status = 'completed' THEN NOW()
                        ELSE consultation_completed_at
                    END
                WHERE id = :visit_id AND deleted_at IS NULL";
        
        $stmt = $pdo->prepare($sql);
        return $stmt->execute([
            ':visit_id' => $visit_id,
            ':status' => $status
        ]);
        
    } catch (PDOException $e) {
        error_log("Update queue status error: " . $e->getMessage());
        return false;
    }
}

// ============================================
// CALL NEXT PATIENT
// ============================================
function call_next_patient(PDO $pdo, int $branch_id, int $user_id): ?array {
    try {
        // Get the next patient in queue (highest priority first)
        $sql = "SELECT 
                    pv.id AS visit_id,
                    pv.visit_number,
                    pv.queue_token_number,
                    pv.is_priority,
                    p.id AS patient_id,
                    CONCAT(
                        COALESCE(p.first_name, ''),
                        ' ',
                        COALESCE(p.middle_name, ''),
                        ' ',
                        COALESCE(p.last_name, '')
                    ) AS patient_name,
                    p.mr_number,
                    p.phone,
                    a.id AS appointment_id,
                    a.chief_complaint,
                    tr.priority AS triage_priority
                FROM patient_visits pv
                INNER JOIN patients p ON pv.patient_id = p.id AND p.deleted_at IS NULL
                LEFT JOIN appointments a ON pv.id = a.visit_id AND a.deleted_at IS NULL
                LEFT JOIN triage_records tr ON pv.id = tr.visit_id AND tr.deleted_at IS NULL
                WHERE pv.branch_id = :branch_id
                    AND pv.deleted_at IS NULL
                    AND pv.status = 'checked_in'
                    AND pv.queue_status = 'waiting'
                ORDER BY 
                    CASE tr.priority 
                        WHEN 'red' THEN 1
                        WHEN 'yellow' THEN 2
                        WHEN 'green' THEN 3
                        WHEN 'blue' THEN 4
                        ELSE 5
                    END ASC,
                    pv.is_priority DESC,
                    pv.checked_in_at ASC,
                    pv.queue_token_number ASC
                LIMIT 1";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':branch_id' => $branch_id]);
        $patient = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($patient) {
            // Clean up patient name
            $patient['patient_name'] = preg_replace('/\s+/', ' ', trim($patient['patient_name'] ?? 'Unknown Patient'));
            
            // Update status to called
            $sql = "UPDATE patient_visits 
                    SET queue_status = 'called',
                        called_at = NOW()
                    WHERE id = :visit_id";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':visit_id' => $patient['visit_id']]);
            
            // Log the action
            error_log("Nurse $user_id called patient {$patient['patient_name']} (Visit: {$patient['visit_id']})");
        }
        
        return $patient;
        
    } catch (PDOException $e) {
        error_log("Call next patient error: " . $e->getMessage());
        return null;
    }
}

// ============================================
// AJAX HANDLER
// ============================================
if (isset($_GET['ajax'])) {
    $action = $_GET['ajax'] ?? '';
    header('Content-Type: application/json; charset=utf-8');
    
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $action !== 'fetch_queue') {
        json_exit(['success' => false, 'message' => 'Invalid request method.']);
    }
    
    // CSRF validation for write operations
    if (!in_array($action, ['fetch_queue', 'fetch_stats', 'fetch_doctors'])) {
        $submitted_token = $_POST['csrf_token'] ?? '';
        if (!isset($_SESSION['csrf_token']) || $submitted_token !== $_SESSION['csrf_token']) {
            error_log("Waiting Room: CSRF validation failed for user {$user_id}");
            json_exit(['success' => false, 'message' => 'Security validation failed.']);
        }
    }
    
    // ================================================================
    // FETCH QUEUE
    // ================================================================
    if ($action === 'fetch_queue') {
        $branch = $is_global_admin && isset($_POST['branch_id']) ? (int)$_POST['branch_id'] : $user_branch_id;
        $queue = get_waiting_room_queue($pdo, $branch, $is_global_admin);
        json_exit(['success' => true, 'queue' => $queue]);
    }
    
    // ================================================================
    // FETCH STATS
    // ================================================================
    if ($action === 'fetch_stats') {
        $branch = $is_global_admin && isset($_POST['branch_id']) ? (int)$_POST['branch_id'] : $user_branch_id;
        $stats = get_queue_statistics($pdo, $branch);
        json_exit(['success' => true, 'stats' => $stats]);
    }
    
    // ================================================================
    // FETCH DOCTORS
    // ================================================================
    if ($action === 'fetch_doctors') {
        $branch = $is_global_admin && isset($_POST['branch_id']) ? (int)$_POST['branch_id'] : $user_branch_id;
        $doctors = get_doctor_availability($pdo, $branch);
        json_exit(['success' => true, 'doctors' => $doctors]);
    }
    
    // ================================================================
    // UPDATE QUEUE STATUS
    // ================================================================
    if ($action === 'update_status') {
        $visit_id = (int)($_POST['visit_id'] ?? 0);
        $status = $_POST['status'] ?? '';
        $branch = $is_global_admin && isset($_POST['branch_id']) ? (int)$_POST['branch_id'] : $user_branch_id;
        
        if ($visit_id <= 0 || empty($status)) {
            json_exit(['success' => false, 'message' => 'Invalid parameters']);
        }
        
        $valid_statuses = ['checked_in', 'in_triage', 'in_consultation', 'awaiting_lab', 'lab_ready', 'awaiting_pharmacy', 'completed'];
        if (!in_array($status, $valid_statuses)) {
            json_exit(['success' => false, 'message' => 'Invalid status']);
        }
        
        $result = update_queue_status($pdo, $visit_id, $status, $user_id);
        
        if ($result) {
            json_exit(['success' => true, 'message' => 'Status updated successfully']);
        } else {
            json_exit(['success' => false, 'message' => 'Failed to update status']);
        }
    }
    
    // ================================================================
    // CALL NEXT PATIENT
    // ================================================================
    if ($action === 'call_next') {
        $branch = $is_global_admin && isset($_POST['branch_id']) ? (int)$_POST['branch_id'] : $user_branch_id;
        $patient = call_next_patient($pdo, $branch, $user_id);
        
        if ($patient) {
            json_exit([
                'success' => true, 
                'patient' => $patient,
                'message' => "Called: {$patient['patient_name']}"
            ]);
        } else {
            json_exit(['success' => false, 'message' => 'No patients waiting']);
        }
    }
    
    json_exit(['success' => false, 'message' => 'Unsupported action']);
}

// ============================================
// INITIAL DATA FETCHING
// ============================================
$queue = get_waiting_room_queue($pdo, $user_branch_id, $is_global_admin);
$stats = get_queue_statistics($pdo, $user_branch_id);
$doctors = get_doctor_availability($pdo, $user_branch_id);

// Get branch info
$branch_info = [];
$branch_stmt = $pdo->prepare("SELECT name, location, phone FROM branches WHERE id = :id AND deleted_at IS NULL LIMIT 1");
$branch_stmt->execute([':id' => $user_branch_id]);
$branch_info = $branch_stmt->fetch(PDO::FETCH_ASSOC);

// Check if this is a TV display mode (no navigation)
$is_tv_mode = isset($_GET['tv']) && $_GET['tv'] === '1';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $is_tv_mode ? 'Waiting Room Display' : 'Waiting Room Manager'; ?> - HMS ERP</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.js"></script>
    <style>
        :root {
            --primary: #1a237e;
            --primary-light: #283593;
            --primary-dark: #0d1b5e;
            --success: #10b981;
            --danger: #dc2626;
            --warning: #f59e0b;
            --info: #3b82f6;
            --gray-light: #e2e8f0;
            --gray: #64748b;
            --dark: #0f172a;
            --critical: #dc2626;
            --urgent: #f59e0b;
            --routine: #10b981;
            --nonurgent: #3b82f6;
        }
        
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: <?php echo $is_tv_mode ? '#0f172a' : 'linear-gradient(135deg, #f1f5f9 0%, #e2e8f0 100%)'; ?>;
            min-height: 100vh;
            <?php if ($is_tv_mode): ?>
            overflow: hidden;
            <?php endif; ?>
        }
        
        <?php if ($is_tv_mode): ?>
        /* TV Mode - Full Screen Display */
        .tv-display {
            height: 100vh;
            width: 100vw;
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            color: white;
            padding: 30px 40px;
            display: flex;
            flex-direction: column;
        }
        
        .tv-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-bottom: 20px;
            border-bottom: 2px solid rgba(255,255,255,0.1);
            margin-bottom: 25px;
            flex-wrap: wrap;
            gap: 10px;
        }
        
        .tv-header h1 {
            font-size: 28px;
            font-weight: 700;
            background: linear-gradient(135deg, #60a5fa, #a78bfa);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        
        .tv-header .info {
            font-size: 14px;
            color: #94a3b8;
        }
        
        .tv-header .info i {
            margin-right: 8px;
        }
        
        .tv-stats {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 15px;
            margin-bottom: 25px;
        }
        
        .tv-stat {
            background: rgba(255,255,255,0.05);
            border-radius: 16px;
            padding: 15px 20px;
            text-align: center;
            border: 1px solid rgba(255,255,255,0.08);
        }
        
        .tv-stat .number {
            font-size: 32px;
            font-weight: 800;
        }
        
        .tv-stat .label {
            font-size: 12px;
            color: #94a3b8;
            margin-top: 4px;
        }
        
        .tv-stat.critical .number { color: var(--critical); }
        .tv-stat.urgent .number { color: var(--urgent); }
        .tv-stat.routine .number { color: var(--routine); }
        
        .tv-queue {
            flex: 1;
            overflow-y: auto;
            background: rgba(255,255,255,0.03);
            border-radius: 16px;
            padding: 20px;
        }
        
        .tv-queue::-webkit-scrollbar {
            width: 4px;
        }
        
        .tv-queue::-webkit-scrollbar-track {
            background: rgba(255,255,255,0.05);
            border-radius: 2px;
        }
        
        .tv-queue::-webkit-scrollbar-thumb {
            background: rgba(255,255,255,0.2);
            border-radius: 2px;
        }
        
        .tv-patient {
            display: grid;
            grid-template-columns: 60px 1fr 1fr 1fr 120px 100px;
            gap: 15px;
            padding: 14px 18px;
            border-bottom: 1px solid rgba(255,255,255,0.06);
            align-items: center;
            transition: background 0.2s;
            border-radius: 8px;
        }
        
        .tv-patient:hover {
            background: rgba(255,255,255,0.05);
        }
        
        .tv-patient .position {
            font-size: 24px;
            font-weight: 700;
            color: #94a3b8;
            text-align: center;
        }
        
        .tv-patient .name {
            font-size: 18px;
            font-weight: 600;
        }
        
        .tv-patient .details {
            font-size: 13px;
            color: #94a3b8;
        }
        
        .tv-patient .details i {
            margin-right: 6px;
            width: 16px;
        }
        
        .tv-patient .wait-time {
            font-size: 15px;
            font-weight: 600;
            color: #fcd34d;
        }
        
        .tv-patient .priority-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            text-align: center;
        }
        
        .priority-critical { background: #dc2626; color: white; }
        .priority-urgent { background: #f59e0b; color: #1e293b; }
        .priority-routine { background: #10b981; color: white; }
        .priority-nonurgent { background: #3b82f6; color: white; }
        
        .tv-empty {
            text-align: center;
            padding: 60px 20px;
            color: #94a3b8;
        }
        
        .tv-empty i {
            font-size: 48px;
            opacity: 0.3;
            margin-bottom: 16px;
        }
        
        .tv-empty h3 {
            font-size: 20px;
            color: #e2e8f0;
            margin-bottom: 8px;
        }
        
        @media (max-width: 1024px) {
            .tv-patient {
                grid-template-columns: 50px 1fr 1fr 80px;
                font-size: 14px;
            }
            .tv-stats {
                grid-template-columns: repeat(3, 1fr);
            }
        }
        
        @media (max-width: 768px) {
            .tv-display {
                padding: 15px;
            }
            .tv-patient {
                grid-template-columns: 40px 1fr;
                gap: 10px;
            }
            .tv-patient .wait-time,
            .tv-patient .priority-badge {
                grid-column: 1 / -1;
            }
            .tv-stats {
                grid-template-columns: 1fr 1fr;
            }
            .tv-header h1 {
                font-size: 20px;
            }
        }
        <?php else: ?>
        /* Normal Mode - Dashboard Style */
        .content-page {
            margin-left: 260px;
            margin-top: 70px;
            padding: 28px 32px;
            transition: margin-left 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            min-height: calc(100vh - 70px);
        }
        
        body.erp-sidebar-collapsed .content-page {
            margin-left: 80px;
        }
        
        @media (max-width: 992px) {
            .content-page {
                margin-left: 0 !important;
                padding: 20px;
            }
        }
        
        .page-header {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%);
            border-radius: 24px;
            padding: 24px 32px;
            margin-bottom: 28px;
            color: white;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
        }
        
        .page-title {
            font-size: 24px;
            font-weight: 700;
        }
        
        .page-subtitle {
            opacity: 0.8;
            font-size: 14px;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }
        
        .stat-card {
            background: white;
            border-radius: 16px;
            padding: 18px 20px;
            border: 1px solid rgba(0,0,0,0.05);
            transition: all 0.3s;
            box-shadow: 0 2px 8px rgba(0,0,0,0.04);
        }
        
        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 30px rgba(0,0,0,0.08);
        }
        
        .stat-card .number {
            font-size: 28px;
            font-weight: 800;
            color: var(--dark);
        }
        
        .stat-card .label {
            font-size: 12px;
            color: var(--gray);
            font-weight: 500;
        }
        
        .stat-card .icon {
            font-size: 20px;
            opacity: 0.5;
        }
        
        .stat-card.critical .number { color: var(--critical); }
        .stat-card.urgent .number { color: var(--urgent); }
        .stat-card.routine .number { color: var(--routine); }
        .stat-card.info .number { color: var(--info); }
        
        .main-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 24px;
            margin-bottom: 24px;
        }
        
        .card-module {
            background: white;
            border-radius: 20px;
            border: 1px solid var(--gray-light);
            overflow: hidden;
            box-shadow: 0 4px 12px rgba(0,0,0,0.03);
        }
        
        .card-header {
            padding: 16px 20px;
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            border-bottom: 2px solid var(--primary);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
        }
        
        .card-header h3 {
            margin: 0;
            font-size: 16px;
            font-weight: 700;
            color: var(--primary);
        }
        
        .card-header .actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        
        .card-body {
            padding: 16px 20px;
            max-height: 550px;
            overflow-y: auto;
        }
        
        .queue-table {
            width: 100%;
            font-size: 13px;
            border-collapse: collapse;
        }
        
        .queue-table th {
            padding: 10px 12px;
            background: #f8fafc;
            font-weight: 600;
            color: var(--gray);
            border-bottom: 1px solid var(--gray-light);
            position: sticky;
            top: 0;
            background: white;
            z-index: 2;
            text-align: left;
        }
        
        .queue-table td {
            padding: 10px 12px;
            border-bottom: 1px solid #f1f5f9;
            vertical-align: middle;
        }
        
        .queue-table tr:hover td {
            background: #f8fafc;
        }
        
        .btn-sm {
            padding: 4px 12px;
            border-radius: 8px;
            font-size: 11px;
            font-weight: 600;
            border: none;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .btn-primary-sm {
            background: var(--primary);
            color: white;
        }
        
        .btn-primary-sm:hover {
            background: var(--primary-light);
        }
        
        .btn-success-sm {
            background: var(--success);
            color: white;
        }
        
        .btn-success-sm:hover {
            background: #059669;
        }
        
        .btn-danger-sm {
            background: var(--danger);
            color: white;
        }
        
        .btn-danger-sm:hover {
            background: #b91c1c;
        }
        
        .btn-warning-sm {
            background: var(--warning);
            color: white;
        }
        
        .btn-warning-sm:hover {
            background: #d97706;
        }
        
        .btn-outline-sm {
            background: white;
            border: 1px solid var(--gray-light);
            color: var(--dark);
        }
        
        .btn-outline-sm:hover {
            border-color: var(--primary);
            color: var(--primary);
        }
        
        .status-badge {
            display: inline-block;
            padding: 2px 10px;
            border-radius: 30px;
            font-size: 10px;
            font-weight: 600;
        }
        
        .status-checked_in { background: #dbeafe; color: #1e40af; }
        .status-in_triage { background: #fef3c7; color: #92400e; }
        .status-in_consultation { background: #d1fae5; color: #065f46; }
        .status-awaiting_lab { background: #fce7f3; color: #9d174d; }
        .status-lab_ready { background: #ede9fe; color: #5b21b6; }
        .status-awaiting_pharmacy { background: #fffbeb; color: #92400e; }
        .status-completed { background: #d1fae5; color: #065f46; }
        
        .priority-dot {
            display: inline-block;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            margin-right: 6px;
        }
        
        .doctor-list {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        
        .doctor-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 14px;
            background: #f8fafc;
            border-radius: 10px;
            border-left: 4px solid var(--primary);
        }
        
        .doctor-item .name {
            font-weight: 600;
            font-size: 14px;
        }
        
        .doctor-item .dept {
            font-size: 12px;
            color: var(--gray);
        }
        
        .doctor-item .status {
            font-size: 12px;
            font-weight: 600;
        }
        
        .doctor-item .status.busy { color: var(--danger); }
        .doctor-item .status.available { color: var(--success); }
        
        .call-alert {
            background: linear-gradient(135deg, #fef3c7, #fbbf24);
            border-radius: 12px;
            padding: 16px 20px;
            margin-bottom: 16px;
            border-left: 4px solid var(--warning);
        }
        
        .call-alert .patient-name {
            font-size: 20px;
            font-weight: 700;
            color: var(--dark);
        }
        
        .call-alert .patient-details {
            font-size: 13px;
            color: #78350f;
        }
        
        .tv-mode-toggle {
            background: rgba(255,255,255,0.15);
            border: 1px solid rgba(255,255,255,0.2);
            color: white;
            padding: 8px 16px;
            border-radius: 10px;
            font-size: 13px;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .tv-mode-toggle:hover {
            background: rgba(255,255,255,0.25);
            color: white;
        }
        
        .waiting-time-badge {
            font-weight: 600;
        }
        
        .waiting-time-badge.long { color: var(--danger); }
        .waiting-time-badge.medium { color: var(--urgent); }
        .waiting-time-badge.short { color: var(--routine); }
        
        @media (max-width: 992px) {
            .main-grid {
                grid-template-columns: 1fr;
            }
        }
        <?php endif; ?>
        
        .toast-container {
            z-index: 9999;
        }
        
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 9998;
            display: none;
            align-items: center;
            justify-content: center;
        }
        
        .loading-overlay.active {
            display: flex;
        }
        
        .loading-spinner {
            width: 50px;
            height: 50px;
            border: 4px solid rgba(255,255,255,0.3);
            border-top-color: white;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        .fade-in {
            animation: fadeIn 0.3s ease;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .text-center { text-align: center; }
        .text-muted { color: var(--gray); }
        .py-4 { padding-top: 24px; padding-bottom: 24px; }
        .me-1 { margin-right: 4px; }
        .me-2 { margin-right: 8px; }
        .ms-2 { margin-left: 8px; }
        .mt-2 { margin-top: 8px; }
    </style>
</head>
<body>

<?php if ($is_tv_mode): ?>
<!-- TV MODE -->
<div class="tv-display">
    <div class="tv-header">
        <div>
            <h1><i class="fas fa-clinic-medical me-2"></i>Waiting Room</h1>
            <div style="font-size:13px; color:#94a3b8; margin-top:4px;">
                <?php echo htmlspecialchars($branch_info['name'] ?? 'Hospital', ENT_QUOTES, 'UTF-8'); ?>
                <?php if (!empty($branch_info['location'])): ?>
                • <?php echo htmlspecialchars($branch_info['location'], ENT_QUOTES, 'UTF-8'); ?>
                <?php endif; ?>
            </div>
        </div>
        <div class="info">
            <i class="fas fa-clock me-1"></i> <?php echo date('l, F j, Y g:i A'); ?>
            <span style="margin-left:20px;">
                <i class="fas fa-sync-alt me-1"></i> Updates every 30s
            </span>
        </div>
    </div>
    
    <div class="tv-stats" id="tvStats">
        <div class="tv-stat">
            <div class="number" id="tvTotalWaiting"><?php echo $stats['total_waiting']; ?></div>
            <div class="label">Total Waiting</div>
        </div>
        <div class="tv-stat critical">
            <div class="number" id="tvCritical"><?php echo $stats['critical']; ?></div>
            <div class="label">Critical</div>
        </div>
        <div class="tv-stat urgent">
            <div class="number" id="tvUrgent"><?php echo $stats['urgent']; ?></div>
            <div class="label">Urgent</div>
        </div>
        <div class="tv-stat routine">
            <div class="number" id="tvRoutine"><?php echo $stats['routine']; ?></div>
            <div class="label">Routine</div>
        </div>
        <div class="tv-stat">
            <div class="number" id="tvServed"><?php echo $stats['today_served']; ?></div>
            <div class="label">Served Today</div>
        </div>
    </div>
    
    <div class="tv-queue" id="tvQueue">
        <?php if (empty($queue)): ?>
            <div class="tv-empty">
                <i class="fas fa-check-circle"></i>
                <h3>No Patients Waiting</h3>
                <p>All patients have been attended to</p>
            </div>
        <?php else: ?>
            <?php foreach ($queue as $index => $patient): ?>
                <div class="tv-patient">
                    <div class="position">#<?php echo $index + 1; ?></div>
                    <div>
                        <div class="name"><?php echo htmlspecialchars($patient['patient_name'], ENT_QUOTES, 'UTF-8'); ?></div>
                        <div class="details">
                            <i class="fas fa-id-card"></i> <?php echo htmlspecialchars($patient['mr_number'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?>
                            <span style="margin-left:10px;"><i class="fas fa-calendar"></i> <?php echo $patient['age_display'] ?? 'N/A'; ?></span>
                        </div>
                    </div>
                    <div class="details">
                        <i class="fas fa-stethoscope"></i> <?php echo htmlspecialchars($patient['chief_complaint'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?>
                        <?php if (!empty($patient['department_name'])): ?>
                            <br><i class="fas fa-building"></i> <?php echo htmlspecialchars($patient['department_name'], ENT_QUOTES, 'UTF-8'); ?>
                        <?php endif; ?>
                    </div>
                    <div class="details">
                        <?php if (!empty($patient['doctor_name'])): ?>
                            <i class="fas fa-user-md"></i> Dr. <?php echo htmlspecialchars($patient['doctor_name'], ENT_QUOTES, 'UTF-8'); ?>
                        <?php else: ?>
                            <i class="fas fa-clock"></i> Awaiting assignment
                        <?php endif; ?>
                    </div>
                    <div class="wait-time">
                        <i class="fas fa-hourglass-half me-1"></i> <?php echo $patient['waiting_minutes'] ?? 0; ?> min
                    </div>
                    <div>
                        <span class="priority-badge priority-<?php echo strtolower($patient['priority_label'] ?? 'routine'); ?>">
                            <?php echo $patient['priority_label'] ?? 'Routine'; ?>
                        </span>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<script>
// TV Mode Auto-Refresh
function refreshTVQueue() {
    $.ajax({
        url: window.location.pathname + '?ajax=fetch_queue&tv=1',
        method: 'POST',
        data: {
            branch_id: <?php echo (int)$user_branch_id; ?>,
            csrf_token: <?php echo json_encode($csrf_token); ?>
        },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                updateTVDisplay(response.queue);
                updateTVStats();
            }
        },
        error: function() {
            console.log('Failed to refresh queue');
        }
    });
}

function updateTVDisplay(queue) {
    const container = document.getElementById('tvQueue');
    if (!queue || queue.length === 0) {
        container.innerHTML = `
            <div class="tv-empty">
                <i class="fas fa-check-circle"></i>
                <h3>No Patients Waiting</h3>
                <p>All patients have been attended to</p>
            </div>
        `;
        return;
    }
    
    container.innerHTML = '';
    queue.forEach((patient, index) => {
        const position = index + 1;
        const priorityClass = (patient.priority_label || 'Routine').toLowerCase();
        const waitTime = patient.waiting_minutes || 0;
        
        container.innerHTML += `
            <div class="tv-patient fade-in">
                <div class="position">#${position}</div>
                <div>
                    <div class="name">${escapeHtml(patient.patient_name || 'N/A')}</div>
                    <div class="details">
                        <i class="fas fa-id-card"></i> ${escapeHtml(patient.mr_number || 'N/A')}
                        <span style="margin-left:10px;"><i class="fas fa-calendar"></i> ${escapeHtml(patient.age_display || 'N/A')}</span>
                    </div>
                </div>
                <div class="details">
                    <i class="fas fa-stethoscope"></i> ${escapeHtml(patient.chief_complaint || 'N/A')}
                    ${patient.department_name ? `<br><i class="fas fa-building"></i> ${escapeHtml(patient.department_name)}` : ''}
                </div>
                <div class="details">
                    ${patient.doctor_name ? `<i class="fas fa-user-md"></i> Dr. ${escapeHtml(patient.doctor_name)}` : '<i class="fas fa-clock"></i> Awaiting assignment'}
                </div>
                <div class="wait-time">
                    <i class="fas fa-hourglass-half me-1"></i> ${waitTime} min
                </div>
                <div>
                    <span class="priority-badge priority-${priorityClass}">${escapeHtml(patient.priority_label || 'Routine')}</span>
                </div>
            </div>
        `;
    });
}

function updateTVStats() {
    $.ajax({
        url: window.location.pathname + '?ajax=fetch_stats',
        method: 'POST',
        data: {
            branch_id: <?php echo (int)$user_branch_id; ?>,
            csrf_token: <?php echo json_encode($csrf_token); ?>
        },
        dataType: 'json',
        success: function(response) {
            if (response.success && response.stats) {
                document.getElementById('tvTotalWaiting').textContent = response.stats.total_waiting || 0;
                document.getElementById('tvCritical').textContent = response.stats.critical || 0;
                document.getElementById('tvUrgent').textContent = response.stats.urgent || 0;
                document.getElementById('tvRoutine').textContent = response.stats.routine || 0;
                document.getElementById('tvServed').textContent = response.stats.today_served || 0;
            }
        }
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

// Refresh every 30 seconds for TV mode
setInterval(refreshTVQueue, 30000);
setInterval(updateTVStats, 60000);

// Initial load
$(document).ready(function() {
    // Update clock every minute
    setInterval(function() {
        const now = new Date();
        document.querySelector('.tv-header .info').innerHTML = 
            `<i class="fas fa-clock me-1"></i> ${now.toLocaleString('en-US', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric', hour: '2-digit', minute: '2-digit' })}` +
            `<span style="margin-left:20px;"><i class="fas fa-sync-alt me-1"></i> Updates every 30s</span>`;
    }, 60000);
});
</script>

<?php else: ?>
<!-- NORMAL MODE -->

<div id="wrapper">
    <?php include('assets/inc/nav.php'); ?>
    <?php include('assets/inc/sidebar.php'); ?>

    <div class="content-page">
        <div class="container-fluid">
            
            <!-- Page Header -->
            <div class="page-header">
                <div>
                    <div class="page-title">
                        <i class="fas fa-users me-2"></i> Waiting Room Manager
                    </div>
                    <div class="page-subtitle">
                        Monitor and manage patient queues in real-time
                    </div>
                </div>
                <div>
                    <a href="?tv=1" target="_blank" class="tv-mode-toggle">
                        <i class="fas fa-tv me-1"></i> TV Display Mode
                    </a>
                </div>
            </div>
            
            <!-- Statistics -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="number" id="statTotalWaiting"><?php echo $stats['total_waiting']; ?></div>
                    <div class="label">Total Waiting</div>
                </div>
                <div class="stat-card critical">
                    <div class="number" id="statCritical"><?php echo $stats['critical']; ?></div>
                    <div class="label">Critical <span style="font-size:10px; color:var(--gray);">(Red)</span></div>
                </div>
                <div class="stat-card urgent">
                    <div class="number" id="statUrgent"><?php echo $stats['urgent']; ?></div>
                    <div class="label">Urgent <span style="font-size:10px; color:var(--gray);">(Yellow)</span></div>
                </div>
                <div class="stat-card routine">
                    <div class="number" id="statRoutine"><?php echo $stats['routine']; ?></div>
                    <div class="label">Routine <span style="font-size:10px; color:var(--gray);">(Green)</span></div>
                </div>
                <div class="stat-card info">
                    <div class="number" id="statServed"><?php echo $stats['today_served']; ?></div>
                    <div class="label">Served Today</div>
                </div>
                <div class="stat-card">
                    <div class="number" id="statAvgWait"><?php echo $stats['avg_wait_time']; ?></div>
                    <div class="label">Avg. Wait Time (min)</div>
                </div>
            </div>
            
            <!-- Call Next Patient Alert -->
            <div class="call-alert" id="callAlert" style="display:none;">
                <div>
                    <i class="fas fa-bell" style="font-size:20px; color:#78350f;"></i>
                    <span class="patient-name" id="calledPatientName"></span>
                    <span class="patient-details" id="calledPatientDetails"></span>
                </div>
                <div style="margin-top:8px;">
                    <button class="btn-sm btn-success-sm" onclick="startConsultation()" style="padding:8px 16px;">
                        <i class="fas fa-stethoscope me-1"></i> Start Consultation
                    </button>
                    <button class="btn-sm btn-warning-sm" onclick="dismissCall()" style="padding:8px 16px;">
                        <i class="fas fa-times me-1"></i> Dismiss
                    </button>
                </div>
            </div>
            
            <!-- Main Grid -->
            <div class="main-grid">
                <!-- Queue Table -->
                <div class="card-module">
                    <div class="card-header">
                        <h3><i class="fas fa-list-ul me-2"></i> Patient Queue</h3>
                        <div class="actions">
                            <button class="btn-sm btn-outline-sm" onclick="refreshQueue()">
                                <i class="fas fa-sync-alt me-1"></i> Refresh
                            </button>
                            <button class="btn-sm btn-primary-sm" onclick="callNextPatient()">
                                <i class="fas fa-phone me-1"></i> Call Next
                            </button>
                        </div>
                    </div>
                    <div class="card-body" id="queueContainer">
                        <table class="queue-table">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Patient</th>
                                    <th>Priority</th>
                                    <th>Wait Time</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="queueBody">
                                <?php if (empty($queue)): ?>
                                <tr>
                                    <td colspan="6" class="text-center text-muted py-4">
                                        <i class="fas fa-check-circle me-2"></i> No patients in queue
                                    </td>
                                </tr>
                                <?php else: ?>
                                    <?php foreach ($queue as $index => $patient): ?>
                                    <tr class="fade-in">
                                        <td>
                                            <span style="font-weight:700; color:var(--gray);">
                                                #<?php echo $index + 1; ?>
                                                <?php if ($patient['is_priority']): ?>
                                                    <i class="fas fa-star" style="color:var(--warning);"></i>
                                                <?php endif; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div style="font-weight:600;"><?php echo htmlspecialchars($patient['patient_name'], ENT_QUOTES, 'UTF-8'); ?></div>
                                            <div style="font-size:11px; color:var(--gray);">
                                                <i class="fas fa-id-card me-1"></i> <?php echo htmlspecialchars($patient['mr_number'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?>
                                                <i class="fas fa-calendar ms-2 me-1"></i> <?php echo $patient['age_display'] ?? 'N/A'; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="priority-dot" style="background:<?php echo $patient['priority_color'] ?? '#64748b'; ?>;"></span>
                                            <span style="font-size:12px; font-weight:600;"><?php echo $patient['priority_label'] ?? 'Unknown'; ?></span>
                                            <?php if (!empty($patient['chief_complaint'])): ?>
                                                <br><span style="font-size:10px; color:var(--gray);">
                                                    <?php echo htmlspecialchars(substr($patient['chief_complaint'], 0, 30), ENT_QUOTES, 'UTF-8'); ?>
                                                    <?php echo strlen($patient['chief_complaint']) > 30 ? '...' : ''; ?>
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="waiting-time-badge <?php 
                                                $wait = $patient['waiting_minutes'] ?? 0;
                                                echo $wait > 45 ? 'long' : ($wait > 20 ? 'medium' : 'short');
                                            ?>">
                                                <?php echo $wait; ?> min
                                            </span>
                                        </td>
                                        <td>
                                            <span class="status-badge status-<?php echo str_replace('_', '', $patient['visit_status'] ?? 'checked_in'); ?>">
                                                <?php echo str_replace('_', ' ', ucfirst($patient['visit_status'] ?? 'Waiting')); ?>
                                            </span>
                                            <?php if (!empty($patient['doctor_name'])): ?>
                                                <br><span style="font-size:10px; color:var(--gray);">
                                                    <i class="fas fa-user-md me-1"></i> Dr. <?php echo htmlspecialchars($patient['doctor_name'], ENT_QUOTES, 'UTF-8'); ?>
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <button class="btn-sm btn-success-sm" onclick="updateStatus(<?php echo $patient['visit_id']; ?>, 'in_consultation')" title="Start Consultation">
                                                <i class="fas fa-stethoscope"></i>
                                            </button>
                                            <button class="btn-sm btn-warning-sm" onclick="updateStatus(<?php echo $patient['visit_id']; ?>, 'awaiting_lab')" title="Send to Lab">
                                                <i class="fas fa-flask"></i>
                                            </button>
                                            <button class="btn-sm btn-primary-sm" onclick="updateStatus(<?php echo $patient['visit_id']; ?>, 'completed')" title="Complete">
                                                <i class="fas fa-check"></i>
                                            </button>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <!-- Doctors Availability -->
                <div class="card-module">
                    <div class="card-header">
                        <h3><i class="fas fa-user-md me-2"></i> Doctors Available</h3>
                        <button class="btn-sm btn-outline-sm" onclick="refreshDoctors()">
                            <i class="fas fa-sync-alt me-1"></i>
                        </button>
                    </div>
                    <div class="card-body" id="doctorContainer">
                        <?php if (empty($doctors)): ?>
                            <div class="text-center text-muted py-4">
                                <i class="fas fa-user-md me-2"></i> No doctors available
                            </div>
                        <?php else: ?>
                            <div class="doctor-list">
                                <?php foreach ($doctors as $doctor): ?>
                                <div class="doctor-item">
                                    <div>
                                        <div class="name">Dr. <?php echo htmlspecialchars($doctor['doctor_name'], ENT_QUOTES, 'UTF-8'); ?></div>
                                        <div class="dept">
                                            <i class="fas fa-building me-1"></i> <?php echo htmlspecialchars($doctor['department_name'] ?? 'General', ENT_QUOTES, 'UTF-8'); ?>
                                        </div>
                                    </div>
                                    <div style="text-align:right;">
                                        <div class="status <?php echo $doctor['is_busy'] ? 'busy' : 'available'; ?>">
                                            <?php if ($doctor['is_busy']): ?>
                                                <i class="fas fa-circle" style="color:var(--danger);"></i> Busy
                                            <?php else: ?>
                                                <i class="fas fa-circle" style="color:var(--success);"></i> Available
                                            <?php endif; ?>
                                        </div>
                                        <div style="font-size:11px; color:var(--gray);">
                                            <?php echo $doctor['current_patients'] ?? 0; ?> patients
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- Quick Actions -->
            <div class="card-module">
                <div class="card-header">
                    <h3><i class="fas fa-bolt me-2"></i> Quick Actions</h3>
                </div>
                <div class="card-body" style="display:flex; gap:12px; flex-wrap:wrap;">
                    <button class="btn-sm btn-primary-sm" onclick="callNextPatient()" style="padding:10px 20px; font-size:14px;">
                        <i class="fas fa-phone me-2"></i> Call Next Patient
                    </button>
                    <button class="btn-sm btn-success-sm" onclick="refreshQueue()" style="padding:10px 20px; font-size:14px;">
                        <i class="fas fa-sync-alt me-2"></i> Refresh Queue
                    </button>
                    <a href="?tv=1" target="_blank" class="btn-sm btn-outline-sm" style="padding:10px 20px; font-size:14px; text-decoration:none; display:inline-flex; align-items:center;">
                        <i class="fas fa-tv me-2"></i> Open TV Display
                    </a>
                </div>
            </div>
            
        </div>
    </div>
    
    <?php include('assets/inc/footer.php'); ?>
</div>

<!-- Loading Overlay -->
<div id="loadingOverlay" class="loading-overlay">
    <div class="loading-spinner"></div>
</div>

<script>
// ================================================================
// WAITING ROOM MANAGER - JavaScript Controller
// ================================================================

const branchId = <?php echo (int)$user_branch_id; ?>;
const isGlobalAdmin = <?php echo $is_global_admin ? 'true' : 'false'; ?>;
const csrfToken = <?php echo json_encode($csrf_token); ?>;
const scriptUrl = window.location.pathname;

let currentCalledVisitId = null;

toastr.options = {
    positionClass: 'toast-top-right',
    progressBar: true,
    closeButton: true,
    timeOut: 4000
};

function showLoader(show) {
    document.getElementById('loadingOverlay').classList.toggle('active', show);
}

function showToast(message, type = 'success') {
    if (type === 'success') toastr.success(message);
    else if (type === 'error') toastr.error(message);
    else if (type === 'warning') toastr.warning(message);
    else toastr.info(message);
}

// ================================================================
// REFRESH QUEUE
// ================================================================
function refreshQueue() {
    showLoader(true);
    $.ajax({
        url: scriptUrl + '?ajax=fetch_queue',
        method: 'POST',
        data: {
            branch_id: branchId,
            csrf_token: csrfToken
        },
        dataType: 'json',
        success: function(response) {
            showLoader(false);
            if (response.success) {
                updateQueueTable(response.queue);
                refreshStats();
            }
        },
        error: function() {
            showLoader(false);
            showToast('Failed to refresh queue', 'error');
        }
    });
}

function updateQueueTable(queue) {
    const tbody = document.getElementById('queueBody');
    if (!queue || queue.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="6" class="text-center text-muted py-4">
                    <i class="fas fa-check-circle me-2"></i> No patients in queue
                </td>
            </tr>
        `;
        return;
    }
    
    tbody.innerHTML = '';
    queue.forEach((patient, index) => {
        const position = index + 1;
        const waitTime = patient.waiting_minutes || 0;
        const waitClass = waitTime > 45 ? 'long' : (waitTime > 20 ? 'medium' : 'short');
        const statusClass = (patient.visit_status || 'checked_in').replace(/_/g, '');
        
        tbody.innerHTML += `
            <tr class="fade-in">
                <td>
                    <span style="font-weight:700; color:var(--gray);">
                        #${position}
                        ${patient.is_priority ? '<i class="fas fa-star" style="color:var(--warning);"></i>' : ''}
                    </span>
                </td>
                <td>
                    <div style="font-weight:600;">${escapeHtml(patient.patient_name || 'N/A')}</div>
                    <div style="font-size:11px; color:var(--gray);">
                        <i class="fas fa-id-card me-1"></i> ${escapeHtml(patient.mr_number || 'N/A')}
                        <i class="fas fa-calendar ms-2 me-1"></i> ${escapeHtml(patient.age_display || 'N/A')}
                    </div>
                </td>
                <td>
                    <span class="priority-dot" style="background:${patient.priority_color || '#64748b'};"></span>
                    <span style="font-size:12px; font-weight:600;">${escapeHtml(patient.priority_label || 'Unknown')}</span>
                    ${patient.chief_complaint ? `<br><span style="font-size:10px; color:var(--gray);">${escapeHtml(patient.chief_complaint.substring(0, 30))}${patient.chief_complaint.length > 30 ? '...' : ''}</span>` : ''}
                </td>
                <td>
                    <span class="waiting-time-badge ${waitClass}">
                        ${waitTime} min
                    </span>
                </td>
                <td>
                    <span class="status-badge status-${statusClass}">
                        ${escapeHtml((patient.visit_status || 'Waiting').replace(/_/g, ' '))}
                    </span>
                    ${patient.doctor_name ? `<br><span style="font-size:10px; color:var(--gray);"><i class="fas fa-user-md me-1"></i> Dr. ${escapeHtml(patient.doctor_name)}</span>` : ''}
                </td>
                <td>
                    <button class="btn-sm btn-success-sm" onclick="updateStatus(${patient.visit_id}, 'in_consultation')" title="Start Consultation">
                        <i class="fas fa-stethoscope"></i>
                    </button>
                    <button class="btn-sm btn-warning-sm" onclick="updateStatus(${patient.visit_id}, 'awaiting_lab')" title="Send to Lab">
                        <i class="fas fa-flask"></i>
                    </button>
                    <button class="btn-sm btn-primary-sm" onclick="updateStatus(${patient.visit_id}, 'completed')" title="Complete">
                        <i class="fas fa-check"></i>
                    </button>
                </td>
            </tr>
        `;
    });
}

// ================================================================
// REFRESH STATS
// ================================================================
function refreshStats() {
    $.ajax({
        url: scriptUrl + '?ajax=fetch_stats',
        method: 'POST',
        data: {
            branch_id: branchId,
            csrf_token: csrfToken
        },
        dataType: 'json',
        success: function(response) {
            if (response.success && response.stats) {
                const s = response.stats;
                document.getElementById('statTotalWaiting').textContent = s.total_waiting || 0;
                document.getElementById('statCritical').textContent = s.critical || 0;
                document.getElementById('statUrgent').textContent = s.urgent || 0;
                document.getElementById('statRoutine').textContent = s.routine || 0;
                document.getElementById('statServed').textContent = s.today_served || 0;
                document.getElementById('statAvgWait').textContent = s.avg_wait_time || 0;
            }
        }
    });
}

// ================================================================
// REFRESH DOCTORS
// ================================================================
function refreshDoctors() {
    $.ajax({
        url: scriptUrl + '?ajax=fetch_doctors',
        method: 'POST',
        data: {
            branch_id: branchId,
            csrf_token: csrfToken
        },
        dataType: 'json',
        success: function(response) {
            if (response.success && response.doctors) {
                const container = document.getElementById('doctorContainer');
                if (response.doctors.length === 0) {
                    container.innerHTML = '<div class="text-center text-muted py-4"><i class="fas fa-user-md me-2"></i> No doctors available</div>';
                    return;
                }
                
                container.innerHTML = '<div class="doctor-list">';
                response.doctors.forEach(doc => {
                    container.innerHTML += `
                        <div class="doctor-item">
                            <div>
                                <div class="name">Dr. ${escapeHtml(doc.doctor_name)}</div>
                                <div class="dept"><i class="fas fa-building me-1"></i> ${escapeHtml(doc.department_name || 'General')}</div>
                            </div>
                            <div style="text-align:right;">
                                <div class="status ${doc.is_busy ? 'busy' : 'available'}">
                                    ${doc.is_busy ? '<i class="fas fa-circle" style="color:var(--danger);"></i> Busy' : '<i class="fas fa-circle" style="color:var(--success);"></i> Available'}
                                </div>
                                <div style="font-size:11px; color:var(--gray);">${doc.current_patients || 0} patients</div>
                            </div>
                        </div>
                    `;
                });
                container.innerHTML += '</div>';
            }
        }
    });
}

// ================================================================
// UPDATE PATIENT STATUS
// ================================================================
function updateStatus(visitId, status) {
    showLoader(true);
    $.ajax({
        url: scriptUrl + '?ajax=update_status',
        method: 'POST',
        data: {
            visit_id: visitId,
            status: status,
            branch_id: branchId,
            csrf_token: csrfToken
        },
        dataType: 'json',
        success: function(response) {
            showLoader(false);
            if (response.success) {
                showToast(response.message, 'success');
                refreshQueue();
            } else {
                showToast(response.message || 'Failed to update status', 'error');
            }
        },
        error: function() {
            showLoader(false);
            showToast('Network error. Please try again.', 'error');
        }
    });
}

// ================================================================
// CALL NEXT PATIENT
// ================================================================
function callNextPatient() {
    showLoader(true);
    $.ajax({
        url: scriptUrl + '?ajax=call_next',
        method: 'POST',
        data: {
            branch_id: branchId,
            csrf_token: csrfToken
        },
        dataType: 'json',
        success: function(response) {
            showLoader(false);
            if (response.success && response.patient) {
                const patient = response.patient;
                currentCalledVisitId = patient.visit_id;
                
                // Show call alert
                const alert = document.getElementById('callAlert');
                document.getElementById('calledPatientName').textContent = 
                    `${patient.patient_name} (Token #${patient.queue_token_number || 'N/A'})`;
                document.getElementById('calledPatientDetails').textContent = 
                    `MRN: ${patient.mr_number || 'N/A'} • ${patient.chief_complaint || 'No complaint'}`;
                alert.style.display = 'block';
                alert.scrollIntoView({ behavior: 'smooth' });
                
                showToast(response.message, 'success');
                refreshQueue();
            } else {
                showToast(response.message || 'No patients waiting', 'warning');
            }
        },
        error: function() {
            showLoader(false);
            showToast('Network error. Please try again.', 'error');
        }
    });
}

function startConsultation() {
    if (currentCalledVisitId) {
        updateStatus(currentCalledVisitId, 'in_consultation');
        dismissCall();
    }
}

function dismissCall() {
    document.getElementById('callAlert').style.display = 'none';
    currentCalledVisitId = null;
}

// ================================================================
// ESCAPE HTML
// ================================================================
function escapeHtml(str) {
    if (!str) return '';
    return String(str).replace(/[&<>]/g, function(m) {
        if (m === '&') return '&amp;';
        if (m === '<') return '&lt;';
        if (m === '>') return '&gt;';
        return m;
    });
}

// ================================================================
// AUTO-REFRESH
// ================================================================
$(document).ready(function() {
    // Refresh every 30 seconds
    setInterval(refreshQueue, 30000);
    setInterval(refreshDoctors, 60000);
    
    // Keyboard shortcuts
    $(document).keydown(function(e) {
        // 'C' key - Call Next
        if (e.key === 'c' && !e.ctrlKey && !e.altKey && !e.metaKey) {
            if (!document.getElementById('callAlert').style.display || 
                document.getElementById('callAlert').style.display === 'none') {
                callNextPatient();
            }
        }
        // 'Enter' key - Start Consultation when call alert is visible
        if (e.key === 'Enter' && document.getElementById('callAlert').style.display !== 'none') {
            startConsultation();
        }
        // 'Esc' key - Dismiss call alert
        if (e.key === 'Escape' && document.getElementById('callAlert').style.display !== 'none') {
            dismissCall();
        }
    });
});
</script>

<?php endif; ?>

</body>
</html>