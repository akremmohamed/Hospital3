<?php
/**
 * Calendar View - HMS ERP System
 * Enhanced with:
 * - Advanced filtering (doctor, department, status, priority)
 * - Appointment status management
 * - Quick actions (check-in, start consultation, complete, cancel)
 * - Patient quick view
 * - Daily summary stats
 * 
 * @package HMS-ERP
 * @version 3.0
 * @requires appointment_view_access permission
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
$user_type = $_SESSION['user_type'] ?? '';

global $pdo;
$db_available = ($pdo instanceof PDO);

if (!$db_available) {
    die('Database connection failed. Please check your configuration.');
}

$can_view_appointments = $is_global_admin;
if (!$is_global_admin && $db_available && $user_id > 0) {
    $can_view_appointments = user_has_permission($pdo, 'appointment_view_access', $user_id);
}

if (!$can_view_appointments) {
    header('Location: dashboard.php?error=unauthorized');
    exit;
}

// Check for edit permission
$can_edit_appointments = $is_global_admin || user_has_permission($pdo, 'appointment_edit_access', $user_id);
$can_create_appointments = $is_global_admin || user_has_permission($pdo, 'appointment_create_access', $user_id);

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

// ============================================
// AJAX: Fetch Calendar Events
// ============================================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'events') {
    try {
        $startRaw = trim((string)($_GET['start'] ?? ''));
        $endRaw = trim((string)($_GET['end'] ?? ''));
        $doctor_id = (int)($_GET['doctor_id'] ?? 0);
        $department_id = (int)($_GET['department_id'] ?? 0);
        $status_filter = trim((string)($_GET['status'] ?? ''));
        $priority_filter = trim((string)($_GET['priority'] ?? ''));
        $patient_search = trim((string)($_GET['patient_search'] ?? ''));

        $startDate = substr($startRaw, 0, 10);
        $endDate = substr($endRaw, 0, 10);
        
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
            json_exit(['success' => false, 'message' => 'Invalid date range.'], 422);
        }

        // Build WHERE clause with branch isolation
        $where = "a.branch_id = :branch_id AND a.deleted_at IS NULL 
                  AND p.deleted_at IS NULL 
                  AND a.appointment_date BETWEEN :start_date AND :end_date";
        
        $params = [
            ':branch_id' => $user_branch_id,
            ':start_date' => $startDate,
            ':end_date' => $endDate
        ];
        
        if ($doctor_id > 0) {
            $where .= " AND a.doctor_id = :doctor_id";
            $params[':doctor_id'] = $doctor_id;
        }
        
        if ($department_id > 0) {
            $where .= " AND a.department_id = :department_id";
            $params[':department_id'] = $department_id;
        }
        
        if (!empty($status_filter)) {
            $where .= " AND a.status = :status_filter";
            $params[':status_filter'] = $status_filter;
        }
        
        if (!empty($priority_filter)) {
            $where .= " AND a.priority = :priority_filter";
            $params[':priority_filter'] = $priority_filter;
        }
        
        if (!empty($patient_search)) {
            $where .= " AND (p.mr_number LIKE :patient_search OR CONCAT(p.first_name, ' ', COALESCE(p.middle_name, ''), ' ', p.last_name) LIKE :patient_search)";
            $params[':patient_search'] = '%' . $patient_search . '%';
        }

        $sql = "
            SELECT
                a.id,
                a.patient_id,
                a.doctor_id,
                a.department_id,
                a.appointment_date AS date,
                a.appointment_time AS time,
                a.status,
                a.notes,
                a.priority,
                a.visit_type,
                a.queue_token_number,
                a.is_emergency_walk_in,
                p.mr_number,
                p.phone AS patient_phone,
                CONCAT(p.first_name, ' ', COALESCE(p.middle_name, ''), ' ', p.last_name) AS patient_name,
                CASE 
                    WHEN a.doctor_id > 0 THEN CONCAT(d.first_name, ' ', COALESCE(d.middle_name, ''), ' ', d.last_name)
                    ELSE 'Unassigned'
                END AS doctor_name,
                sp.specialization AS doctor_specialty,
                dep.name AS department_name,
                COALESCE(dep.color_code, '#f59e0b') AS department_color,
                TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) AS patient_age,
                p.gender AS patient_gender,
                p.blood_group
            FROM appointments a
            INNER JOIN patients p ON p.id = a.patient_id AND p.deleted_at IS NULL
            LEFT JOIN users d ON d.id = a.doctor_id AND d.deleted_at IS NULL AND d.user_type = 'doctor'
            LEFT JOIN staff_profiles sp ON d.id = sp.user_id
            LEFT JOIN departments dep ON dep.id = a.department_id AND dep.deleted_at IS NULL
            WHERE {$where}
            ORDER BY a.appointment_date ASC, 
                     CASE WHEN a.is_emergency_walk_in = 1 THEN 0 ELSE 1 END,
                     a.appointment_time ASC
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get daily stats
        $stats = [];
        foreach ($rows as $row) {
            $dateKey = $row['date'];
            if (!isset($stats[$dateKey])) {
                $stats[$dateKey] = ['total' => 0, 'confirmed' => 0, 'emergency' => 0, 'urgent' => 0];
            }
            $stats[$dateKey]['total']++;
            if ($row['status'] === 'confirmed' || $row['status'] === 'checked_in') $stats[$dateKey]['confirmed']++;
            if ($row['priority'] === 'stat') $stats[$dateKey]['emergency']++;
            if ($row['priority'] === 'urgent') $stats[$dateKey]['urgent']++;
        }
        
        $events = [];
        foreach ($rows as $row) {
            $status = strtolower((string)$row['status']);
            $notes = (string)($row['notes'] ?? '');
            $priority = strtolower((string)($row['priority'] ?? 'normal'));
            $isUrgent = $priority === 'stat' || $priority === 'urgent' || $row['is_emergency_walk_in'] == 1;
            
            // Color coding based on status and priority
            $bgColor = '#f59e0b'; // pending - warning
            $borderColor = '#f59e0b';
            $textColor = '#ffffff';
            
            if ($isUrgent) {
                $bgColor = '#ef4444'; // urgent - red
                $borderColor = '#dc2626';
            } elseif ($status === 'confirmed') {
                $bgColor = '#10b981'; // confirmed - green
                $borderColor = '#059669';
            } elseif ($status === 'checked_in') {
                $bgColor = '#8b5cf6'; // checked in - purple
                $borderColor = '#7c3aed';
            } elseif ($status === 'in_progress') {
                $bgColor = '#3b82f6'; // in progress - blue
                $borderColor = '#2563eb';
            } elseif ($status === 'completed') {
                $bgColor = '#6b7280'; // completed - gray
                $borderColor = '#4b5563';
            } elseif ($status === 'cancelled') {
                $bgColor = '#dc2626'; // cancelled - dark red
                $borderColor = '#b91c1c';
            } elseif ($status === 'no_show') {
                $bgColor = '#9ca3af'; // no show - light gray
                $borderColor = '#6b7280';
            } elseif ($status === 'rescheduled') {
                $bgColor = '#f59e0b'; // rescheduled - yellow
                $borderColor = '#d97706';
            }
            
            $patientName = (string)($row['patient_name'] ?? 'Unknown Patient');
            $mrn = (string)($row['mr_number'] ?? '');
            $queueToken = (int)($row['queue_token_number'] ?? 0);
            
            $title = $patientName;
            if ($mrn) $title .= " (MRN: {$mrn})";
            if ($queueToken > 0) $title .= " [#{$queueToken}]";
            if ($isUrgent) $title = "🚨 " . $title;
            
            $timeFormatted = substr((string)$row['time'], 0, 5);
            if (empty($timeFormatted) || $timeFormatted === '00:00') {
                $timeFormatted = '09:00';
            }
            
            $events[] = [
                'id' => (string)$row['id'],
                'title' => htmlspecialchars($title, ENT_QUOTES, 'UTF-8'),
                'start' => $row['date'] . 'T' . $timeFormatted . ':00',
                'backgroundColor' => $bgColor,
                'borderColor' => $borderColor,
                'textColor' => $textColor,
                'className' => $isUrgent ? 'fc-event-urgent' : ($status === 'confirmed' ? 'fc-event-confirmed' : ''),
                'extendedProps' => [
                    'mrn' => htmlspecialchars($mrn, ENT_QUOTES, 'UTF-8'),
                    'patient_name' => htmlspecialchars($patientName, ENT_QUOTES, 'UTF-8'),
                    'patient_phone' => htmlspecialchars($row['patient_phone'] ?? '', ENT_QUOTES, 'UTF-8'),
                    'patient_age' => (int)($row['patient_age'] ?? 0),
                    'patient_gender' => htmlspecialchars($row['patient_gender'] ?? '', ENT_QUOTES, 'UTF-8'),
                    'patient_blood_group' => htmlspecialchars($row['blood_group'] ?? '', ENT_QUOTES, 'UTF-8'),
                    'doctor_name' => htmlspecialchars($row['doctor_name'] ?? 'Unassigned', ENT_QUOTES, 'UTF-8'),
                    'doctor_specialty' => htmlspecialchars($row['doctor_specialty'] ?? 'General', ENT_QUOTES, 'UTF-8'),
                    'department_name' => htmlspecialchars($row['department_name'] ?? 'N/A', ENT_QUOTES, 'UTF-8'),
                    'status' => htmlspecialchars($row['status'] ?? 'scheduled', ENT_QUOTES, 'UTF-8'),
                    'notes' => htmlspecialchars($notes, ENT_QUOTES, 'UTF-8'),
                    'priority' => htmlspecialchars($row['priority'] ?? 'normal', ENT_QUOTES, 'UTF-8'),
                    'visit_type' => htmlspecialchars($row['visit_type'] ?? 'regular', ENT_QUOTES, 'UTF-8'),
                    'queue_token' => (int)$queueToken,
                    'is_emergency_walk_in' => (bool)($row['is_emergency_walk_in'] ?? false),
                    'patient_id' => (int)$row['patient_id'],
                    'doctor_id' => (int)$row['doctor_id'],
                    'time_formatted' => $timeFormatted
                ]
            ];
        }
        
        json_exit([
            'success' => true, 
            'events' => $events,
            'stats' => $stats
        ]);
        
    } catch (PDOException $e) {
        error_log("Calendar events fetch error: " . $e->getMessage());
        json_exit(['success' => false, 'message' => 'Database error occurred'], 500);
    }
}

// ============================================
// AJAX: Get Event Details with Quick Actions
// ============================================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'event_details') {
    try {
        $appointment_id = (int)($_GET['id'] ?? 0);
        if ($appointment_id <= 0) {
            json_exit(['success' => false, 'message' => 'Invalid appointment.'], 422);
        }

        $sql = "
            SELECT
                a.id,
                a.appointment_number,
                a.appointment_date AS date,
                a.appointment_time AS time,
                a.status,
                a.notes,
                a.doctor_id,
                a.priority,
                a.visit_type,
                a.queue_token_number,
                a.estimated_wait_minutes,
                a.is_emergency_walk_in,
                a.created_at,
                a.created_by_user_id,
                p.id AS patient_id,
                p.mr_number,
                CONCAT(p.first_name, ' ', COALESCE(p.middle_name, ''), ' ', p.last_name) AS patient_name,
                p.phone AS patient_phone,
                p.email AS patient_email,
                p.gender,
                p.date_of_birth AS birth_date,
                p.blood_group,
                p.address AS patient_address,
                TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) AS patient_age,
                CASE 
                    WHEN a.doctor_id > 0 THEN CONCAT(d.first_name, ' ', COALESCE(d.middle_name, ''), ' ', d.last_name)
                    ELSE 'Unassigned'
                END AS doctor_name,
                sp.specialization AS doctor_specialty,
                sp.license_number AS doctor_license,
                dep.name AS department_name,
                dep.code AS department_code,
                u.username AS created_by_username,
                CONCAT(u.first_name, ' ', u.last_name) AS created_by_name,
                v.visit_number,
                v.status AS visit_status,
                v.queue_token_number AS visit_queue_token,
                v.checked_in_at,
                v.consultation_started_at,
                v.consultation_completed_at
            FROM appointments a
            INNER JOIN patients p ON p.id = a.patient_id AND p.deleted_at IS NULL
            LEFT JOIN users d ON d.id = a.doctor_id AND d.deleted_at IS NULL AND d.user_type = 'doctor'
            LEFT JOIN staff_profiles sp ON d.id = sp.user_id
            LEFT JOIN departments dep ON dep.id = a.department_id AND dep.deleted_at IS NULL
            LEFT JOIN users u ON u.id = a.created_by_user_id
            LEFT JOIN patient_visits v ON v.id = a.visit_id AND v.deleted_at IS NULL
            WHERE a.id = :appointment_id AND a.branch_id = :branch_id AND a.deleted_at IS NULL
            LIMIT 1
        ";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':appointment_id' => $appointment_id,
            ':branch_id' => $user_branch_id
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$row) {
            json_exit(['success' => false, 'message' => 'Appointment not found.'], 404);
        }

        $statusClass = match($row['status'] ?? '') {
            'confirmed' => 'badge-success',
            'checked_in' => 'badge-primary',
            'in_progress' => 'badge-info',
            'completed' => 'badge-secondary',
            'cancelled' => 'badge-danger',
            'no_show' => 'badge-dark',
            'rescheduled' => 'badge-warning',
            default => 'badge-warning'
        };
        
        $statusText = match($row['status'] ?? '') {
            'confirmed' => 'Confirmed',
            'checked_in' => 'Checked In',
            'in_progress' => 'In Progress',
            'completed' => 'Completed',
            'cancelled' => 'Cancelled',
            'no_show' => 'No Show',
            'rescheduled' => 'Rescheduled',
            default => 'Scheduled'
        };
        
        $timeFormatted = substr($row['time'] ?? '', 0, 5);
        if (empty($timeFormatted) || $timeFormatted === '00:00') {
            $timeFormatted = '09:00';
        }
        
        // Check if user can perform actions
        $can_edit = $is_global_admin || user_has_permission($pdo, 'appointment_edit_access', $user_id);
        $can_checkin = $is_global_admin || user_has_permission($pdo, 'checkin_patient', $user_id);
        $can_start_consultation = $is_global_admin || user_has_permission($pdo, 'start_consultation', $user_id) || $user_type === 'doctor';
        $can_complete = $is_global_admin || user_has_permission($pdo, 'complete_consultation', $user_id) || $user_type === 'doctor';
        
        $html = '
            <div class="appointment-details">
                <!-- Header with status -->
                <div class="d-flex justify-content-between align-items-start mb-3">
                    <div>
                        <h5 class="mb-1">' . htmlspecialchars($row['patient_name'] ?? 'Unknown Patient', ENT_QUOTES, 'UTF-8') . '</h5>
                        <div class="small text-muted">
                            MRN: ' . htmlspecialchars($row['mr_number'] ?? 'N/A', ENT_QUOTES, 'UTF-8') . '
                            ' . ($row['queue_token_number'] ? ' | Queue #' . (int)$row['queue_token_number'] : '') . '
                        </div>
                    </div>
                    <div>
                        <span class="badge ' . $statusClass . '">' . $statusText . '</span>
                        ' . ($row['priority'] === 'stat' ? '<span class="badge badge-danger ms-1"><i class="fas fa-exclamation-triangle"></i> STAT</span>' : '') . '
                        ' . ($row['is_emergency_walk_in'] ? '<span class="badge badge-warning ms-1"><i class="fas fa-ambulance"></i> Walk-in</span>' : '') . '
                    </div>
                </div>
                
                <!-- Patient Info -->
                <div class="row mb-3">
                    <div class="col-6">
                        <div class="detail-label"><i class="fas fa-user me-2 text-primary"></i>Patient</div>
                        <div class="detail-value fw-bold">' . htmlspecialchars($row['patient_name'] ?? 'N/A', ENT_QUOTES, 'UTF-8') . '</div>
                        <div class="small text-muted">MRN: ' . htmlspecialchars($row['mr_number'] ?? 'N/A', ENT_QUOTES, 'UTF-8') . '</div>
                        <div class="small text-muted">' . htmlspecialchars($row['patient_phone'] ?? 'N/A', ENT_QUOTES, 'UTF-8') . '</div>
                        ' . ($row['patient_email'] ? '<div class="small text-muted">' . htmlspecialchars($row['patient_email'], ENT_QUOTES, 'UTF-8') . '</div>' : '') . '
                    </div>
                    <div class="col-6">
                        <div class="detail-label"><i class="fas fa-stethoscope me-2 text-primary"></i>Doctor</div>
                        <div class="detail-value fw-bold">' . htmlspecialchars($row['doctor_name'] ?? 'Unassigned', ENT_QUOTES, 'UTF-8') . '</div>
                        <div class="small text-muted">' . htmlspecialchars($row['doctor_specialty'] ?? 'General Medicine', ENT_QUOTES, 'UTF-8') . '</div>
                        ' . ($row['doctor_license'] ? '<div class="small text-muted">License: ' . htmlspecialchars($row['doctor_license'], ENT_QUOTES, 'UTF-8') . '</div>' : '') . '
                    </div>
                </div>
                
                <!-- Appointment Details -->
                <div class="row mb-3">
                    <div class="col-4">
                        <div class="detail-label"><i class="fas fa-calendar me-2 text-primary"></i>Date & Time</div>
                        <div class="detail-value">' . htmlspecialchars(date('d M Y', strtotime($row['date'])), ENT_QUOTES, 'UTF-8') . ' at ' . htmlspecialchars($timeFormatted, ENT_QUOTES, 'UTF-8') . '</div>
                    </div>
                    <div class="col-4">
                        <div class="detail-label"><i class="fas fa-building me-2 text-primary"></i>Department</div>
                        <div class="detail-value">' . htmlspecialchars($row['department_name'] ?? 'N/A', ENT_QUOTES, 'UTF-8') . '</div>
                        <div class="small text-muted">' . htmlspecialchars($row['department_code'] ?? '', ENT_QUOTES, 'UTF-8') . '</div>
                    </div>
                    <div class="col-4">
                        <div class="detail-label"><i class="fas fa-tag me-2 text-primary"></i>Priority / Type</div>
                        <div class="detail-value">' . ucfirst(htmlspecialchars($row['priority'] ?? 'normal', ENT_QUOTES, 'UTF-8')) . ' / ' . ucfirst(htmlspecialchars($row['visit_type'] ?? 'regular', ENT_QUOTES, 'UTF-8')) . '</div>
                        ' . ($row['estimated_wait_minutes'] ? '<div class="small text-muted">Est. Wait: ' . (int)$row['estimated_wait_minutes'] . ' min</div>' : '') . '
                    </div>
                </div>
                
                <!-- Patient Demographics -->
                <div class="row mb-3">
                    <div class="col-4">
                        <div class="detail-label"><i class="fas fa-venus-mars me-2 text-primary"></i>Gender / Age</div>
                        <div class="detail-value">' . htmlspecialchars(ucfirst($row['gender'] ?? 'N/A'), ENT_QUOTES, 'UTF-8') . ' / ' . ($row['patient_age'] ?? 'N/A') . ' yrs</div>
                    </div>
                    <div class="col-4">
                        <div class="detail-label"><i class="fas fa-tint me-2 text-primary"></i>Blood Group</div>
                        <div class="detail-value">' . htmlspecialchars($row['blood_group'] ?? 'N/A', ENT_QUOTES, 'UTF-8') . '</div>
                    </div>
                    <div class="col-4">
                        <div class="detail-label"><i class="fas fa-map-marker-alt me-2 text-primary"></i>Address</div>
                        <div class="detail-value small">' . htmlspecialchars($row['patient_address'] ?? 'N/A', ENT_QUOTES, 'UTF-8') . '</div>
                    </div>
                </div>
                
                <!-- Visit Info -->
                ' . ($row['visit_number'] ? '
                <div class="row mb-3">
                    <div class="col-6">
                        <div class="detail-label"><i class="fas fa-clipboard-list me-2 text-primary"></i>Visit</div>
                        <div class="detail-value">' . htmlspecialchars($row['visit_number'], ENT_QUOTES, 'UTF-8') . '</div>
                        <div class="small text-muted">Status: ' . htmlspecialchars($row['visit_status'] ?? 'N/A', ENT_QUOTES, 'UTF-8') . '</div>
                    </div>
                    <div class="col-6">
                        <div class="detail-label"><i class="fas fa-hourglass-half me-2 text-primary"></i>Timeline</div>
                        ' . ($row['checked_in_at'] ? '<div class="small">Checked in: ' . date('g:i A', strtotime($row['checked_in_at'])) . '</div>' : '') . '
                        ' . ($row['consultation_started_at'] ? '<div class="small">Consultation: ' . date('g:i A', strtotime($row['consultation_started_at'])) . '</div>' : '') . '
                    </div>
                </div>
                ' : '') . '
                
                <!-- Notes -->
                ' . (!empty($row['notes']) ? '
                <div class="mt-3">
                    <div class="detail-label"><i class="fas fa-comment me-2 text-primary"></i>Notes</div>
                    <div class="detail-value p-2 bg-light rounded">' . nl2br(htmlspecialchars($row['notes'], ENT_QUOTES, 'UTF-8')) . '</div>
                </div>
                ' : '') . '
                
                <!-- Created Info -->
                <div class="mt-2 small text-muted">
                    Created by ' . htmlspecialchars($row['created_by_name'] ?? 'Unknown', ENT_QUOTES, 'UTF-8') . ' on ' . htmlspecialchars(date('d M Y g:i A', strtotime($row['created_at'] ?? 'now')), ENT_QUOTES, 'UTF-8') . '
                </div>
                
                <hr class="my-3">';
        
        // Action buttons
        $html .= '<div class="d-flex flex-wrap gap-2">';
        
        // View Patient button
        $html .= '
            <button class="btn btn-sm btn-outline-primary" onclick="window.open(\'patient_history.php?id=' . (int)$row['patient_id'] . '\', \'_blank\')">
                <i class="fas fa-user me-1"></i> View Patient
            </button>';
        
        // Quick actions based on status
        if ($can_edit) {
            $html .= '
                <button class="btn btn-sm btn-outline-success" onclick="window.location.href=\'update_appointment.php?id=' . $appointment_id . '\'">
                    <i class="fas fa-edit me-1"></i> Edit
                </button>';
        }
        
        // Status update actions
        $current_status = $row['status'] ?? '';
        if ($can_checkin && $current_status === 'scheduled') {
            $html .= '
                <button class="btn btn-sm btn-primary" onclick="updateAppointmentStatus(' . $appointment_id . ', \'checked_in\')">
                    <i class="fas fa-check-in me-1"></i> Check In
                </button>';
        }
        if ($can_start_consultation && $current_status === 'checked_in') {
            $html .= '
                <button class="btn btn-sm btn-info" onclick="updateAppointmentStatus(' . $appointment_id . ', \'in_progress\')">
                    <i class="fas fa-stethoscope me-1"></i> Start Consultation
                </button>';
        }
        if ($can_complete && $current_status === 'in_progress') {
            $html .= '
                <button class="btn btn-sm btn-success" onclick="updateAppointmentStatus(' . $appointment_id . ', \'completed\')">
                    <i class="fas fa-check me-1"></i> Complete
                </button>';
        }
        if ($can_edit && !in_array($current_status, ['completed', 'cancelled', 'no_show'])) {
            $html .= '
                <button class="btn btn-sm btn-danger" onclick="updateAppointmentStatus(' . $appointment_id . ', \'cancelled\')">
                    <i class="fas fa-times me-1"></i> Cancel
                </button>';
        }
        
        // QR Code / Check-in link
        if ($row['queue_token_number']) {
            $html .= '
                <button class="btn btn-sm btn-secondary" onclick="showQueueInfo(' . (int)$row['queue_token_number'] . ', \'' . htmlspecialchars($row['patient_name'], ENT_QUOTES, 'UTF-8') . '\')">
                    <i class="fas fa-qrcode me-1"></i> Queue Info
                </button>';
        }
        
        $html .= '</div>';
        
        // Quick stats footer
        $html .= '
            <div class="mt-3 pt-2 border-top">
                <div class="d-flex justify-content-between small text-muted">
                    <span><i class="fas fa-clock me-1"></i> ' . $timeFormatted . '</span>
                    <span><i class="fas fa-hashtag me-1"></i> ' . ($row['appointment_number'] ?? 'N/A') . '</span>
                </div>
            </div>
        ';
        
        $html .= '</div>';
        
        json_exit([
            'success' => true, 
            'html' => $html,
            'appointment' => $row,
            'permissions' => [
                'can_edit' => $can_edit,
                'can_checkin' => $can_checkin,
                'can_start_consultation' => $can_start_consultation,
                'can_complete' => $can_complete
            ]
        ]);
        
    } catch (PDOException $e) {
        error_log("Event details error: " . $e->getMessage());
        json_exit(['success' => false, 'message' => 'Database error occurred'], 500);
    }
}

// ============================================
// AJAX: Update Appointment Status
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax']) && $_POST['ajax'] === 'update_status') {
    $submitted_token = $_POST['csrf_token'] ?? '';
    if (!isset($_SESSION['csrf_token']) || $submitted_token !== $_SESSION['csrf_token']) {
        json_exit(['success' => false, 'message' => 'Security validation failed.'], 403);
    }
    
    try {
        $pdo->beginTransaction();
        
        $appointment_id = (int)($_POST['appointment_id'] ?? 0);
        $new_status = trim((string)($_POST['status'] ?? ''));
        $reason = trim((string)($_POST['reason'] ?? ''));
        
        $valid_statuses = ['scheduled', 'confirmed', 'checked_in', 'in_progress', 'completed', 'cancelled', 'no_show', 'rescheduled'];
        
        if ($appointment_id <= 0 || !in_array($new_status, $valid_statuses, true)) {
            json_exit(['success' => false, 'message' => 'Invalid parameters.'], 422);
        }
        
        // Get current appointment
        $stmt = $pdo->prepare("
            SELECT id, status, visit_id, patient_id, doctor_id 
            FROM appointments 
            WHERE id = :id AND branch_id = :branch_id AND deleted_at IS NULL
        ");
        $stmt->execute([':id' => $appointment_id, ':branch_id' => $user_branch_id]);
        $appointment = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$appointment) {
            json_exit(['success' => false, 'message' => 'Appointment not found.'], 404);
        }
        
        $old_status = $appointment['status'];
        
        // Update appointment
        $update_stmt = $pdo->prepare("
            UPDATE appointments 
            SET status = :status, 
                updated_at = NOW(),
                notes = CASE 
                    WHEN :reason != '' AND notes IS NOT NULL THEN CONCAT(notes, '\n[Status Update: ', :status, '] ', :reason)
                    WHEN :reason != '' THEN CONCAT('[Status Update: ', :status, '] ', :reason)
                    ELSE notes
                END
            WHERE id = :id AND branch_id = :branch_id AND deleted_at IS NULL
        ");
        $update_stmt->execute([
            ':status' => $new_status,
            ':reason' => $reason,
            ':id' => $appointment_id,
            ':branch_id' => $user_branch_id
        ]);
        
        // Update visit status if visit exists
        if ($appointment['visit_id']) {
            $visit_status = match($new_status) {
                'checked_in' => 'checked_in',
                'in_progress' => 'in_consultation',
                'completed' => 'completed',
                'cancelled' => 'cancelled',
                'no_show' => 'no_show',
                default => 'pending'
            };
            
            $visit_update = $pdo->prepare("
                UPDATE patient_visits 
                SET status = :status, updated_at = NOW()
                WHERE id = :id AND deleted_at IS NULL
            ");
            $visit_update->execute([':status' => $visit_status, ':id' => $appointment['visit_id']]);
        }
        
        // Log the action
        if (function_exists('log_user_action')) {
            log_user_action($pdo, 'UPDATE', 'appointments', $appointment_id, null, [
                'action' => 'status_change',
                'old_status' => $old_status,
                'new_status' => $new_status,
                'reason' => $reason
            ]);
        }
        
        $pdo->commit();
        
        json_exit([
            'success' => true,
            'message' => 'Appointment status updated successfully.',
            'old_status' => $old_status,
            'new_status' => $new_status
        ]);
        
    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log("Status update error: " . $e->getMessage());
        json_exit(['success' => false, 'message' => 'Database error occurred'], 500);
    } catch (Exception $e) {
        $pdo->rollBack();
        json_exit(['success' => false, 'message' => $e->getMessage()], 500);
    }
}

// ============================================
// AJAX: Move Event (Reschedule)
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax']) && $_POST['ajax'] === 'move_event') {
    $submitted_token = $_POST['csrf_token'] ?? '';
    if (!isset($_SESSION['csrf_token']) || $submitted_token !== $_SESSION['csrf_token']) {
        json_exit(['success' => false, 'message' => 'Security validation failed.'], 403);
    }
    
    try {
        $pdo->beginTransaction();
        
        $appointment_id = (int)($_POST['appointment_id'] ?? 0);
        $doctor_id = (int)($_POST['doctor_id'] ?? 0);
        $new_start = trim((string)($_POST['new_start'] ?? ''));
        
        if ($appointment_id <= 0 || $new_start === '') {
            json_exit(['success' => false, 'message' => 'Invalid payload.'], 422);
        }

        $dt = new DateTime($new_start);
        $newDate = $dt->format('Y-m-d');
        $newTime = $dt->format('H:i:s');
        
        if ($doctor_id <= 0) {
            $getDocStmt = $pdo->prepare("SELECT doctor_id FROM appointments WHERE id = :id AND deleted_at IS NULL LIMIT 1");
            $getDocStmt->execute([':id' => $appointment_id]);
            $docResult = $getDocStmt->fetch(PDO::FETCH_ASSOC);
            $doctor_id = $docResult['doctor_id'] ?? 0;
        }
        
        if ($doctor_id > 0) {
            $conflictStmt = $pdo->prepare("
                SELECT id FROM appointments
                WHERE branch_id = :branch_id 
                  AND doctor_id = :doctor_id 
                  AND appointment_date = :date 
                  AND appointment_time = :time
                  AND deleted_at IS NULL 
                  AND status NOT IN ('cancelled', 'no_show')
                  AND id != :id
                LIMIT 1
            ");
            $conflictStmt->execute([
                ':branch_id' => $user_branch_id,
                ':doctor_id' => $doctor_id,
                ':date' => $newDate,
                ':time' => $newTime,
                ':id' => $appointment_id
            ]);
            $conflict = $conflictStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($conflict) {
                $pdo->rollBack();
                json_exit(['success' => false, 'message' => 'Doctor already booked at this time slot.'], 409);
            }
        }

        $updateStmt = $pdo->prepare("
            UPDATE appointments
            SET appointment_date = :date, 
                appointment_time = :time, 
                status = 'rescheduled',
                updated_at = NOW()
            WHERE id = :id AND branch_id = :branch_id AND deleted_at IS NULL
            LIMIT 1
        ");
        $updateStmt->execute([
            ':date' => $newDate,
            ':time' => $newTime,
            ':id' => $appointment_id,
            ':branch_id' => $user_branch_id
        ]);
        
        if ($updateStmt->rowCount() === 0) {
            throw new Exception('Appointment not found or already moved.');
        }

        if (function_exists('log_user_action')) {
            log_user_action($pdo, 'UPDATE', 'appointments', $appointment_id, null, [
                'action' => 'reschedule',
                'new_date' => $newDate,
                'new_time' => $newTime
            ]);
        }

        $pdo->commit();
        json_exit(['success' => true, 'message' => 'Appointment rescheduled successfully.']);
        
    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log("Move event error: " . $e->getMessage());
        json_exit(['success' => false, 'message' => 'Database error occurred'], 500);
    } catch (Exception $e) {
        $pdo->rollBack();
        json_exit(['success' => false, 'message' => $e->getMessage()], 500);
    }
}

// ============================================
// AJAX: Get Daily Stats
// ============================================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'daily_stats') {
    try {
        $date = trim((string)($_GET['date'] ?? date('Y-m-d')));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            json_exit(['success' => false, 'message' => 'Invalid date'], 422);
        }
        
        $sql = "
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status IN ('scheduled', 'confirmed') THEN 1 ELSE 0 END) as scheduled,
                SUM(CASE WHEN status = 'checked_in' THEN 1 ELSE 0 END) as checked_in,
                SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled,
                SUM(CASE WHEN status = 'no_show' THEN 1 ELSE 0 END) as no_show,
                SUM(CASE WHEN priority = 'stat' THEN 1 ELSE 0 END) as emergency,
                SUM(CASE WHEN priority = 'urgent' THEN 1 ELSE 0 END) as urgent,
                SUM(CASE WHEN is_emergency_walk_in = 1 THEN 1 ELSE 0 END) as walk_in
            FROM appointments
            WHERE branch_id = :branch_id 
              AND appointment_date = :date 
              AND deleted_at IS NULL
        ";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':branch_id' => $user_branch_id,
            ':date' => $date
        ]);
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);
        
        json_exit([
            'success' => true,
            'stats' => $stats,
            'date' => $date
        ]);
        
    } catch (PDOException $e) {
        error_log("Daily stats error: " . $e->getMessage());
        json_exit(['success' => false, 'message' => 'Failed to load stats'], 500);
    }
}

// ============================================
// AJAX: Get Doctors for Filter
// ============================================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_doctors') {
    try {
        $department_id = (int)($_GET['department_id'] ?? 0);
        
        if ($department_id > 0) {
            $sql = "
                SELECT DISTINCT u.id, CONCAT(u.first_name, ' ', COALESCE(u.middle_name, ''), ' ', u.last_name) AS name, sp.specialization
                FROM users u
                LEFT JOIN staff_profiles sp ON u.id = sp.user_id
                WHERE u.user_type = 'doctor' 
                  AND u.is_active = 1 
                  AND u.deleted_at IS NULL
                  AND u.branch_id = :branch_id 
                  AND sp.department_id = :department_id
                ORDER BY u.first_name, u.last_name
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':branch_id' => $user_branch_id,
                ':department_id' => $department_id
            ]);
        } else {
            $sql = "
                SELECT id, CONCAT(first_name, ' ', COALESCE(middle_name, ''), ' ', last_name) AS name, 
                       (SELECT specialization FROM staff_profiles WHERE user_id = users.id LIMIT 1) AS specialization
                FROM users
                WHERE user_type = 'doctor' 
                  AND is_active = 1 
                  AND deleted_at IS NULL 
                  AND branch_id = :branch_id
                ORDER BY first_name, last_name
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':branch_id' => $user_branch_id]);
        }
        
        $doctors = $stmt->fetchAll(PDO::FETCH_ASSOC);
        json_exit(['success' => true, 'doctors' => $doctors]);
        
    } catch (PDOException $e) {
        error_log("Get doctors error: " . $e->getMessage());
        json_exit(['success' => false, 'message' => 'Failed to load doctors'], 500);
    }
}

// ============================================
// AJAX: Get Departments for Filter
// ============================================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_departments') {
    try {
        $sql = "
            SELECT id, name, code, color_code
            FROM departments
            WHERE branch_id = :branch_id AND is_active = 1 AND deleted_at IS NULL
            ORDER BY name
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':branch_id' => $user_branch_id]);
        $departments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        json_exit(['success' => true, 'departments' => $departments]);
        
    } catch (PDOException $e) {
        error_log("Get departments error: " . $e->getMessage());
        json_exit(['success' => false, 'message' => 'Failed to load departments'], 500);
    }
}

// ============================================
// Fetch data for filters
// ============================================
$departments = [];
$deptStmt = $pdo->prepare("
    SELECT id, name, code, color_code
    FROM departments
    WHERE branch_id = :branch_id AND is_active = 1 AND deleted_at IS NULL
    ORDER BY name
");
$deptStmt->execute([':branch_id' => $user_branch_id]);
$departments = $deptStmt->fetchAll(PDO::FETCH_ASSOC);

$doctors = [];
$docStmt = $pdo->prepare("
    SELECT id, first_name, middle_name, last_name,
           (SELECT specialization FROM staff_profiles WHERE user_id = users.id LIMIT 1) AS specialization
    FROM users
    WHERE branch_id = :branch_id AND user_type = 'doctor' AND is_active = 1 AND deleted_at IS NULL
    ORDER BY first_name, last_name
");
$docStmt->execute([':branch_id' => $user_branch_id]);
$doctors = $docStmt->fetchAll(PDO::FETCH_ASSOC);

$statuses = ['scheduled', 'confirmed', 'checked_in', 'in_progress', 'completed', 'cancelled', 'no_show', 'rescheduled'];
$priorities = ['normal', 'urgent', 'stat'];

$branch_name = '';
$branchStmt = $pdo->prepare("SELECT name FROM branches WHERE id = :id AND deleted_at IS NULL LIMIT 1");
$branchStmt->execute([':id' => $user_branch_id]);
$branchResult = $branchStmt->fetch(PDO::FETCH_ASSOC);
if ($branchResult) $branch_name = $branchResult['name'];

$current_time = date('g:i A');
$current_date = date('l, F j, Y');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include('assets/inc/head.php'); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Calendar View | HMS ERP</title>
    
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    
    <link href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/main.min.css" rel="stylesheet">
    <link href="assets/libs/fullcalendar/main.min.css" rel="stylesheet">
    <script src="assets/libs/fullcalendar/index.global.min.js"></script>
    <script src="assets/libs/fullcalendar/sweetalert2.all.min.js"></script>

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
            padding: 28px 36px;
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
            font-size: 24px;
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
        
        .stats-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            gap: 12px;
            margin-top: 16px;
            padding-top: 16px;
            border-top: 1px solid rgba(255,255,255,0.1);
        }
        
        .stat-item {
            text-align: center;
        }
        
        .stat-item .number {
            font-size: 24px;
            font-weight: 700;
        }
        
        .stat-item .label {
            font-size: 11px;
            opacity: 0.7;
        }
        
        .stat-item .number.emergency { color: #ef476f; }
        .stat-item .number.urgent { color: #f9c74f; }
        .stat-item .number.checked_in { color: #8b5cf6; }
        .stat-item .number.completed { color: #10b981; }
        
        .filter-card {
            background: rgba(255,255,255,0.95);
            backdrop-filter: blur(10px);
            border-radius: 24px;
            padding: 24px 28px;
            margin-bottom: 28px;
            border: 1px solid rgba(255,255,255,0.3);
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
        }
        
        .filter-label {
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--slate);
            margin-bottom: 8px;
            display: block;
        }
        
        .filter-select {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            font-size: 14px;
            transition: all 0.2s;
            background: white;
        }
        
        .filter-select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(67,97,238,0.1);
        }
        
        .filter-input {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            font-size: 14px;
            transition: all 0.2s;
            background: white;
        }
        
        .filter-input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(67,97,238,0.1);
        }
        
        .legend {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            padding-top: 12px;
            border-top: 1px solid #e2e8f0;
            margin-top: 16px;
        }
        
        .legend-item {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 12px;
            color: var(--slate);
        }
        
        .legend-dot {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            display: inline-block;
        }
        
        .calendar-card {
            background: rgba(255,255,255,0.95);
            backdrop-filter: blur(10px);
            border-radius: 24px;
            overflow: hidden;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            border: 1px solid rgba(255,255,255,0.3);
        }
        
        .fc {
            font-family: 'Plus Jakarta Sans', 'Inter', sans-serif;
        }
        
        .fc .fc-toolbar-title {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--dark);
        }
        
        .fc .fc-button-primary {
            background: white;
            border-color: #e2e8f0;
            color: var(--slate);
            font-weight: 500;
        }
        
        .fc .fc-button-primary:hover {
            background: var(--primary);
            border-color: var(--primary);
            color: white;
        }
        
        .fc .fc-button-primary:not(:disabled).fc-button-active {
            background: var(--primary);
            border-color: var(--primary);
            color: white;
        }
        
        .fc-event {
            border-radius: 10px;
            padding: 4px 8px;
            font-size: 12px;
            font-weight: 500;
            cursor: pointer;
            transition: transform 0.2s;
        }
        
        .fc-event:hover {
            transform: scale(1.02);
        }
        
        .fc-event-urgent {
            animation: pulse 1.5s infinite;
        }
        
        @keyframes pulse {
            0% { opacity: 1; }
            50% { opacity: 0.85; }
            100% { opacity: 1; }
        }
        
        .fc-day-today {
            background: rgba(67,97,238,0.05) !important;
        }
        
        .appointment-details {
            max-height: 70vh;
            overflow-y: auto;
        }
        
        .detail-label {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--slate);
            margin-bottom: 4px;
        }
        
        .detail-value {
            font-size: 14px;
            font-weight: 500;
            color: var(--dark);
        }
        
        .badge {
            padding: 5px 12px;
            border-radius: 30px;
            font-weight: 600;
            font-size: 11px;
        }
        
        .badge-success { background: #d1fae5; color: #065f46; }
        .badge-warning { background: #fef3c7; color: #92400e; }
        .badge-info { background: #e0e7ff; color: #3730a3; }
        .badge-danger { background: #fee2e2; color: #991b1b; }
        .badge-secondary { background: #f1f5f9; color: #475569; }
        .badge-primary { background: #dbeafe; color: #1e40af; }
        .badge-dark { background: #e2e8f0; color: #1e293b; }
        
        .btn-primary-custom {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            border: none;
            padding: 10px 24px;
            border-radius: 40px;
            font-weight: 600;
            color: white;
            transition: all 0.2s;
        }
        
        .btn-primary-custom:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(67,97,238,0.3);
        }
        
        .btn-outline-secondary {
            border: 1px solid #e2e8f0;
            background: white;
            padding: 10px 24px;
            border-radius: 40px;
            font-weight: 500;
            transition: all 0.2s;
        }
        
        .btn-outline-secondary:hover {
            background: #f8f9fa;
            border-color: var(--primary);
        }
        
        .row {
            display: flex;
            flex-wrap: wrap;
            margin: 0 -12px;
        }
        
        .col-md-3, .col-md-4 {
            padding: 0 12px;
            flex: 1;
        }
        
        .col-md-3 { flex: 0 0 25%; max-width: 25%; }
        .col-md-4 { flex: 0 0 33.333333%; max-width: 33.333333%; }
        
        @media (max-width: 768px) {
            .col-md-3, .col-md-4 { flex: 0 0 100%; max-width: 100%; margin-bottom: 15px; }
        }
        
        .gap-2 { gap: 8px; }
        .mt-3 { margin-top: 1rem; }
        .mb-3 { margin-bottom: 1rem; }
        .me-1 { margin-right: 0.25rem; }
        .me-2 { margin-right: 0.5rem; }
        .d-flex { display: flex; }
        .justify-content-end { justify-content: flex-end; }
        .justify-content-between { justify-content: space-between; }
        .align-items-center { align-items: center; }
        .flex-wrap { flex-wrap: wrap; }
        .text-muted { color: #6c757d; }
        .small { font-size: 0.875rem; }
        .text-center { text-align: center; }
        .fw-bold { font-weight: 700; }
        .w-100 { width: 100%; }
        
        .quick-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 12px;
        }
        
        .quick-actions .btn {
            font-size: 12px;
            padding: 6px 14px;
            border-radius: 20px;
        }
        
        .fc-event-title {
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        /* Loading spinner */
        .loading-overlay {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(255,255,255,0.7);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 999;
            border-radius: 24px;
        }
        
        .fc .fc-daygrid-day-number {
            font-size: 13px;
        }
        
        .fc .fc-timegrid-slot-label {
            font-size: 11px;
        }
        
        .fc .fc-daygrid-day-events {
            min-height: 20px;
        }
        
        /* Mini stats in calendar header */
        .fc-day-today .fc-daygrid-day-number {
            background: var(--primary);
            color: white;
            border-radius: 50%;
            padding: 2px 6px;
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
                            <i class="fas fa-calendar-alt me-3"></i>Schedule Command Center
                        </div>
                        <div class="greeting-sub">
                            Manage appointments • <?php echo htmlspecialchars($branch_name, ENT_QUOTES, 'UTF-8'); ?>
                        </div>
                    </div>
                    <div class="mt-3 mt-md-0">
                        <div class="time-badge">
                            <i class="far fa-calendar-alt me-2"></i> <?php echo $current_date; ?>
                            <i class="far fa-clock ms-2 me-1"></i> <?php echo $current_time; ?>
                        </div>
                    </div>
                </div>
                
                <!-- Daily Stats -->
                <div class="stats-row" id="dailyStats">
                    <div class="stat-item">
                        <div class="number" id="statTotal">0</div>
                        <div class="label">Total</div>
                    </div>
                    <div class="stat-item">
                        <div class="number checked_in" id="statCheckedIn">0</div>
                        <div class="label">Checked In</div>
                    </div>
                    <div class="stat-item">
                        <div class="number" style="color:#3b82f6;" id="statInProgress">0</div>
                        <div class="label">In Progress</div>
                    </div>
                    <div class="stat-item">
                        <div class="number emergency" id="statEmergency">0</div>
                        <div class="label">Emergency</div>
                    </div>
                    <div class="stat-item">
                        <div class="number urgent" id="statUrgent">0</div>
                        <div class="label">Urgent</div>
                    </div>
                    <div class="stat-item">
                        <div class="number completed" id="statCompleted">0</div>
                        <div class="label">Completed</div>
                    </div>
                </div>
            </div>
            
            <div class="filter-card">
                <div class="row align-items-end g-3">
                    <div class="col-md-3">
                        <label class="filter-label"><i class="fas fa-user-md me-2 text-primary"></i>Doctor</label>
                        <select id="filterDoctor" class="filter-select">
                            <option value="">All Doctors</option>
                            <?php foreach ($doctors as $d): ?>
                                <option value="<?php echo (int)$d['id']; ?>">
                                    Dr. <?php echo htmlspecialchars($d['first_name'] . ' ' . ($d['middle_name'] ? $d['middle_name'] . ' ' : '') . $d['last_name'], ENT_QUOTES, 'UTF-8'); ?>
                                    <?php if (!empty($d['specialization'])): ?>
                                        (<?php echo htmlspecialchars($d['specialization'], ENT_QUOTES, 'UTF-8'); ?>)
                                    <?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="filter-label"><i class="fas fa-building me-2 text-primary"></i>Department</label>
                        <select id="filterDepartment" class="filter-select">
                            <option value="">All Departments</option>
                            <?php foreach ($departments as $dep): ?>
                                <option value="<?php echo (int)$dep['id']; ?>">
                                    <?php echo htmlspecialchars($dep['name'], ENT_QUOTES, 'UTF-8'); ?>
                                    (<?php echo htmlspecialchars($dep['code'], ENT_QUOTES, 'UTF-8'); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="filter-label"><i class="fas fa-flag me-2 text-primary"></i>Status</label>
                        <select id="filterStatus" class="filter-select">
                            <option value="">All Statuses</option>
                            <?php foreach ($statuses as $s): ?>
                                <option value="<?php echo $s; ?>"><?php echo ucfirst($s); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="filter-label"><i class="fas fa-exclamation-triangle me-2 text-primary"></i>Priority</label>
                        <select id="filterPriority" class="filter-select">
                            <option value="">All Priorities</option>
                            <option value="normal">Normal</option>
                            <option value="urgent">Urgent</option>
                            <option value="stat">STAT</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="filter-label"><i class="fas fa-search me-2 text-primary"></i>Patient</label>
                        <input type="text" id="filterPatient" class="filter-input" placeholder="Search patient...">
                    </div>
                </div>
                <div class="row mt-3">
                    <div class="col-md-12">
                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                            <div>
                                <?php if ($can_create_appointments): ?>
                                    <a href="book_appointment.php" class="btn-primary-custom">
                                        <i class="fas fa-plus me-2"></i>New Appointment
                                    </a>
                                <?php endif; ?>
                            </div>
                            <div>
                                <button id="refreshCalendar" class="btn btn-outline-secondary" style="border-radius:40px;padding:8px 20px;">
                                    <i class="fas fa-sync-alt me-2"></i>Refresh
                                </button>
                                <button id="resetFilters" class="btn btn-outline-secondary" style="border-radius:40px;padding:8px 20px;">
                                    <i class="fas fa-undo me-2"></i>Reset
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="legend">
                    <div class="legend-item"><span class="legend-dot" style="background: #ef4444;"></span> Urgent / Emergency</div>
                    <div class="legend-item"><span class="legend-dot" style="background: #10b981;"></span> Confirmed</div>
                    <div class="legend-item"><span class="legend-dot" style="background: #8b5cf6;"></span> Checked In</div>
                    <div class="legend-item"><span class="legend-dot" style="background: #3b82f6;"></span> In Progress</div>
                    <div class="legend-item"><span class="legend-dot" style="background: #f59e0b;"></span> Scheduled</div>
                    <div class="legend-item"><span class="legend-dot" style="background: #6b7280;"></span> Completed</div>
                    <div class="legend-item"><span class="legend-dot" style="background: #9ca3af;"></span> No Show</div>
                    <div class="legend-item"><span class="legend-dot" style="background: #dc2626;"></span> Cancelled</div>
                </div>
            </div>
            
            <div class="calendar-card" style="position:relative;">
                <div class="p-3">
                    <div id="calendar"></div>
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
    const doctorFilter = document.getElementById('filterDoctor');
    const departmentFilter = document.getElementById('filterDepartment');
    const statusFilter = document.getElementById('filterStatus');
    const priorityFilter = document.getElementById('filterPriority');
    const patientFilter = document.getElementById('filterPatient');
    const refreshBtn = document.getElementById('refreshCalendar');
    const resetBtn = document.getElementById('resetFilters');
    const calendarEl = document.getElementById('calendar');
    const csrfToken = <?php echo json_encode($csrf_token); ?>;
    
    let calendar = null;
    let isLoading = false;
    let currentView = 'timeGridWeek';
    
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
    
    function updateDailyStats(events) {
        const total = events.length;
        const checkedIn = events.filter(e => e.extendedProps && e.extendedProps.status === 'checked_in').length;
        const inProgress = events.filter(e => e.extendedProps && e.extendedProps.status === 'in_progress').length;
        const completed = events.filter(e => e.extendedProps && e.extendedProps.status === 'completed').length;
        const emergency = events.filter(e => e.extendedProps && e.extendedProps.priority === 'stat').length;
        const urgent = events.filter(e => e.extendedProps && e.extendedProps.priority === 'urgent').length;
        
        document.getElementById('statTotal').textContent = total;
        document.getElementById('statCheckedIn').textContent = checkedIn;
        document.getElementById('statInProgress').textContent = inProgress;
        document.getElementById('statCompleted').textContent = completed;
        document.getElementById('statEmergency').textContent = emergency;
        document.getElementById('statUrgent').textContent = urgent;
    }
    
    // Update appointment status
    window.updateAppointmentStatus = function(appointmentId, newStatus) {
        let message = '';
        let confirmText = '';
        
        switch(newStatus) {
            case 'checked_in':
                message = 'Check in this patient?';
                confirmText = 'Check In';
                break;
            case 'in_progress':
                message = 'Start consultation for this patient?';
                confirmText = 'Start Consultation';
                break;
            case 'completed':
                message = 'Mark this appointment as completed?';
                confirmText = 'Complete';
                break;
            case 'cancelled':
                message = 'Cancel this appointment?';
                confirmText = 'Cancel';
                break;
            default:
                message = 'Update appointment status?';
                confirmText = 'Update';
        }
        
        Swal.fire({
            title: 'Confirm Action',
            text: message,
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: confirmText,
            cancelButtonText: 'Cancel',
            confirmButtonColor: newStatus === 'cancelled' ? '#dc2626' : '#4361ee',
            input: newStatus === 'cancelled' ? 'textarea' : null,
            inputPlaceholder: 'Reason for cancellation (optional)',
            inputAttributes: { rows: 2 }
        }).then(async (result) => {
            if (result.isConfirmed) {
                showLoadingToast('Updating status...');
                
                try {
                    const formData = new FormData();
                    formData.append('ajax', 'update_status');
                    formData.append('csrf_token', csrfToken);
                    formData.append('appointment_id', appointmentId);
                    formData.append('status', newStatus);
                    if (result.value) {
                        formData.append('reason', result.value);
                    }
                    
                    const response = await fetch('calendar_view.php', { method: 'POST', body: formData });
                    const data = await response.json();
                    
                    hideLoadingToast();
                    
                    if (data.success) {
                        showToast(data.message, 'success');
                        calendar.refetchEvents();
                        // Close any open modals
                        Swal.close();
                    } else {
                        showToast(data.message || 'Failed to update status', 'error');
                    }
                } catch (err) {
                    hideLoadingToast();
                    showToast('Network error. Please try again.', 'error');
                }
            }
        });
    };
    
    // Show queue info
    window.showQueueInfo = function(token, patientName) {
        Swal.fire({
            title: 'Queue Information',
            html: `
                <div class="text-center">
                    <div style="font-size:72px;font-weight:800;color:#4361ee;">#${token}</div>
                    <div class="mt-2">${patientName}</div>
                    <div class="small text-muted mt-1">Queue Token</div>
                    <hr>
                    <div class="small text-muted">Please wait for your turn. You will be called when the doctor is ready.</div>
                </div>
            `,
            confirmButtonColor: '#4361ee',
            confirmButtonText: 'OK'
        });
    };
    
    calendar = new FullCalendar.Calendar(calendarEl, {
        initialView: window.innerWidth < 768 ? 'timeGridDay' : 'timeGridWeek',
        height: 'auto',
        headerToolbar: {
            left: 'prev,next today',
            center: 'title',
            right: 'dayGridMonth,timeGridWeek,timeGridDay,listWeek'
        },
        selectable: true,
        editable: <?php echo $can_edit_appointments ? 'true' : 'false'; ?>,
        droppable: true,
        nowIndicator: true,
        slotMinTime: '07:00:00',
        slotMaxTime: '20:00:00',
        slotDuration: '00:15:00',
        eventTimeFormat: { hour: '2-digit', minute: '2-digit', meridiem: false },
        eventMinHeight: 45,
        eventDisplay: 'block',
        views: {
            dayGridMonth: {
                eventDisplay: 'block'
            },
            timeGridWeek: {
                eventDisplay: 'block'
            },
            timeGridDay: {
                eventDisplay: 'block'
            }
        },
        
        events: async function(fetchInfo, successCallback, failureCallback) {
            if (isLoading) return;
            isLoading = true;
            
            try {
                const params = new URLSearchParams({
                    ajax: 'events',
                    start: fetchInfo.startStr,
                    end: fetchInfo.endStr,
                    doctor_id: doctorFilter.value || '',
                    department_id: departmentFilter.value || '',
                    status: statusFilter.value || '',
                    priority: priorityFilter.value || '',
                    patient_search: patientFilter.value || ''
                });
                
                const response = await fetch(`calendar_view.php?${params.toString()}`);
                const data = await response.json();
                
                if (!data.success) throw new Error(data.message || 'Failed to load events');
                
                // Update stats
                if (data.events) {
                    updateDailyStats(data.events);
                }
                
                successCallback(data.events || []);
            } catch (err) {
                console.error('Calendar events error:', err);
                failureCallback(err);
                showToast('Failed to load calendar events', 'error');
            } finally {
                isLoading = false;
            }
        },
        
        eventClick: async function(info) {
            const eventId = info.event.id;
            
            try {
                showLoadingToast('Loading appointment details...');
                
                const response = await fetch(`calendar_view.php?ajax=event_details&id=${encodeURIComponent(eventId)}`);
                const data = await response.json();
                
                hideLoadingToast();
                
                if (!data.success) throw new Error(data.message || 'Failed to load details');
                
                Swal.fire({
                    title: '<i class="fas fa-calendar-check me-2 text-primary"></i> Appointment Details',
                    html: data.html,
                    width: 700,
                    showCloseButton: true,
                    confirmButtonText: 'Close',
                    confirmButtonColor: '#4361ee',
                    didOpen: function() {
                        // Add custom styles for the modal content
                        const modal = Swal.getHtmlContainer();
                        if (modal) {
                            modal.style.maxHeight = '80vh';
                            modal.style.overflow = 'auto';
                        }
                    }
                });
            } catch (err) {
                hideLoadingToast();
                showToast(err.message || 'Failed to load appointment details', 'error');
            }
        },
        
        dateClick: function(info) {
            <?php if ($can_create_appointments): ?>
            const dt = moment(info.dateStr);
            const date = dt.format('YYYY-MM-DD');
            const time = dt.format('HH:mm');
            window.location.href = `book_appointment.php?date=${encodeURIComponent(date)}&time=${encodeURIComponent(time)}`;
            <?php else: ?>
            showToast('You do not have permission to book appointments', 'info');
            <?php endif; ?>
        },
        
        eventDrop: async function(info) {
            <?php if (!$can_edit_appointments): ?>
            info.revert();
            showToast('You do not have permission to reschedule appointments', 'error');
            return;
            <?php endif; ?>
            
            try {
                showLoadingToast('Rescheduling appointment...');
                
                const formData = new FormData();
                formData.append('ajax', 'move_event');
                formData.append('csrf_token', csrfToken);
                formData.append('appointment_id', info.event.id);
                formData.append('doctor_id', info.event.extendedProps.doctor_id || '');
                formData.append('new_start', info.event.start.toISOString());
                
                const response = await fetch('calendar_view.php', { method: 'POST', body: formData });
                const data = await response.json();
                
                hideLoadingToast();
                
                if (!data.success) {
                    info.revert();
                    showToast(data.message || 'Move failed', 'error');
                } else {
                    showToast('Appointment rescheduled successfully', 'success');
                    calendar.refetchEvents();
                }
            } catch (err) {
                hideLoadingToast();
                info.revert();
                showToast('Network error. Please try again.', 'error');
            }
        },
        
        eventResize: async function(info) {
            <?php if (!$can_edit_appointments): ?>
            info.revert();
            showToast('You do not have permission to resize appointments', 'error');
            return;
            <?php endif; ?>
            
            try {
                showLoadingToast('Updating appointment duration...');
                
                const formData = new FormData();
                formData.append('ajax', 'resize_event');
                formData.append('csrf_token', csrfToken);
                formData.append('appointment_id', info.event.id);
                formData.append('new_end', info.event.end.toISOString());
                
                const response = await fetch('calendar_view.php', { method: 'POST', body: formData });
                const data = await response.json();
                
                hideLoadingToast();
                
                if (!data.success) {
                    info.revert();
                    showToast(data.message || 'Resize failed', 'error');
                } else {
                    showToast('Appointment duration updated', 'success');
                }
            } catch (err) {
                hideLoadingToast();
                info.revert();
                showToast('Network error. Please try again.', 'error');
            }
        },
        
        loading: function(isLoading) {
            const calendarWrapper = document.querySelector('.calendar-card');
            if (!calendarWrapper) return;
            
            let overlay = calendarWrapper.querySelector('.loading-overlay');
            if (isLoading) {
                if (!overlay) {
                    overlay = document.createElement('div');
                    overlay.className = 'loading-overlay';
                    overlay.innerHTML = '<div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div>';
                    calendarWrapper.style.position = 'relative';
                    calendarWrapper.appendChild(overlay);
                }
            } else {
                if (overlay) {
                    overlay.remove();
                }
            }
        }
    });
    
    calendar.render();
    
    // Filter handlers
    doctorFilter.addEventListener('change', () => calendar.refetchEvents());
    departmentFilter.addEventListener('change', () => calendar.refetchEvents());
    statusFilter.addEventListener('change', () => calendar.refetchEvents());
    priorityFilter.addEventListener('change', () => calendar.refetchEvents());
    
    // Patient filter with debounce
    let patientFilterTimeout = null;
    patientFilter.addEventListener('input', function() {
        clearTimeout(patientFilterTimeout);
        patientFilterTimeout = setTimeout(() => {
            calendar.refetchEvents();
        }, 500);
    });
    
    // Reset filters
    resetBtn.addEventListener('click', function() {
        doctorFilter.value = '';
        departmentFilter.value = '';
        statusFilter.value = '';
        priorityFilter.value = '';
        patientFilter.value = '';
        calendar.refetchEvents();
        showToast('Filters reset', 'info');
    });
    
    // Refresh button
    refreshBtn.addEventListener('click', function(e) {
        e.preventDefault();
        showToast('Refreshing calendar...', 'info');
        calendar.refetchEvents();
    });
    
    // Handle resize for responsive views
    let resizeTimer;
    window.addEventListener('resize', function() {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(function() {
            const newView = window.innerWidth < 768 ? 'timeGridDay' : 'timeGridWeek';
            if (calendar.view.type !== newView && !calendar.view.type.startsWith('dayGridMonth')) {
                calendar.changeView(newView);
            }
        }, 250);
    });
    
    // Initial stats load
    setTimeout(() => {
        calendar.refetchEvents();
    }, 500);
})();
</script>

</body>
</html>