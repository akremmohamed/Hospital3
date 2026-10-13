<?php
/**
 * Notifications Manager - HMS ERP System
 * Enhanced with full database integration
 * 
 * @package HMS-ERP
 * @version 3.0
 * @requires notification_manage_access permission
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
$user_name = $_SESSION['first_name'] ?? $_SESSION['username'] ?? 'User';

// Check database availability
global $pdo;
$db_available = ($pdo instanceof PDO);

if (!$db_available) {
    die('Database connection failed. Please check your configuration.');
}

// Permission check for managing notifications
$can_manage_notifications = $is_global_admin;

if (!$is_global_admin && $db_available && $user_id > 0) {
    // Check multiple possible permission keys
    $permission_keys = [
        'notification_manage_access',
        'manage_notifications',
        'send_reminders',
        'view_notifications'
    ];
    
    $can_manage_notifications = false;
    foreach ($permission_keys as $key) {
        if (user_has_permission($pdo, $key, $user_id)) {
            $can_manage_notifications = true;
            break;
        }
    }
}

// If user has no permission, redirect
if (!$can_manage_notifications) {
    // Log the failed attempt
    error_log("User ID: $user_id attempted to access notifications without permission");
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

// ============================================
// SCHEMA INITIALIZATION
// ============================================
function ensure_notification_schema(PDO $pdo): bool {
    try {
        // Check if notification_logs table exists
        $stmt = $pdo->query("SHOW TABLES LIKE 'notification_logs'");
        $table_exists = $stmt && $stmt->rowCount() > 0;
        
        if (!$table_exists) {
            $sql = "CREATE TABLE IF NOT EXISTS `notification_logs` (
                `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
                `appointment_id` int(10) UNSIGNED DEFAULT NULL,
                `patient_id` int(10) UNSIGNED DEFAULT NULL,
                `branch_id` int(10) UNSIGNED DEFAULT NULL,
                `notification_type` ENUM('appointment_reminder', 'bulk_announcement', 'general_alert', 'critical_alert', 'system_notification') NOT NULL,
                `channel` ENUM('sms', 'email', 'in_app', 'whatsapp') NOT NULL,
                `recipient_address` varchar(255) NOT NULL,
                `recipient_type` ENUM('patient', 'staff', 'all') DEFAULT 'patient',
                `subject` varchar(255) DEFAULT NULL,
                `message_body` text DEFAULT NULL,
                `priority` ENUM('low', 'normal', 'high', 'critical') DEFAULT 'normal',
                `scheduled_at` timestamp NULL DEFAULT NULL,
                `status` ENUM('pending', 'sent', 'failed', 'queued', 'scheduled', 'cancelled') DEFAULT 'pending',
                `error_message` text DEFAULT NULL,
                `sent_at` timestamp NULL DEFAULT NULL,
                `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
                `created_by` int(10) UNSIGNED DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `idx_patient_id` (`patient_id`),
                KEY `idx_branch_id` (`branch_id`),
                KEY `idx_notification_type` (`notification_type`),
                KEY `idx_status` (`status`),
                KEY `idx_created_at` (`created_at`),
                KEY `idx_notification_logs_created_by` (`created_by`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
            
            $pdo->exec($sql);
        }

        // Check if notification_scheduled table exists
        $stmt = $pdo->query("SHOW TABLES LIKE 'notification_scheduled'");
        if (!$stmt || $stmt->rowCount() === 0) {
            $sql = "CREATE TABLE IF NOT EXISTS `notification_scheduled` (
                `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
                `branch_id` int(10) UNSIGNED NOT NULL,
                `notification_type` VARCHAR(50) NOT NULL,
                `channel` ENUM('sms', 'email', 'in_app', 'whatsapp') NOT NULL,
                `recipient_type` ENUM('patients', 'staff_role', 'all') NOT NULL,
                `recipient_value` VARCHAR(100) DEFAULT NULL,
                `subject` VARCHAR(255) DEFAULT NULL,
                `message_template` TEXT NOT NULL,
                `scheduled_at` timestamp NOT NULL,
                `status` ENUM('pending', 'processing', 'completed', 'failed', 'cancelled') DEFAULT 'pending',
                `recurrence_pattern` VARCHAR(50) DEFAULT NULL,
                `last_run_at` timestamp NULL DEFAULT NULL,
                `next_run_at` timestamp NULL DEFAULT NULL,
                `created_by` int(10) UNSIGNED NOT NULL,
                `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                `deleted_at` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `idx_branch_id` (`branch_id`),
                KEY `idx_scheduled_at` (`scheduled_at`),
                KEY `idx_status` (`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
            
            $pdo->exec($sql);
        }

        return true;
    } catch (PDOException $e) {
        error_log("Schema check failed: " . $e->getMessage());
        return false;
    }
}

// Initialize schema
ensure_notification_schema($pdo);

// ============================================
// NOTIFICATION LOGGING FUNCTION (Enhanced)
// ============================================
function log_notification(PDO $pdo, ?int $patient_id, ?int $appointment_id, int $branch_id, 
                          string $notification_type, string $channel, string $recipient_address, 
                          string $status, ?string $error_message, ?string $message_body, 
                          ?string $subject, int $created_by, string $recipient_type = 'patient',
                          string $priority = 'normal', ?string $scheduled_at = null): bool {
    try {
        $sent_at = ($status === 'sent') ? date('Y-m-d H:i:s') : null;
        
        $sql = "INSERT INTO notification_logs 
                (patient_id, appointment_id, branch_id, notification_type, channel, recipient_address, 
                 recipient_type, subject, message_body, status, error_message, sent_at, priority,
                 scheduled_at, created_by, created_at) 
                VALUES (:patient_id, :appointment_id, :branch_id, :notification_type, :channel, :recipient_address, 
                        :recipient_type, :subject, :message_body, :status, :error_message, :sent_at, :priority,
                        :scheduled_at, :created_by, NOW())";
        
        $stmt = $pdo->prepare($sql);
        return $stmt->execute([
            ':patient_id' => $patient_id,
            ':appointment_id' => $appointment_id,
            ':branch_id' => $branch_id,
            ':notification_type' => $notification_type,
            ':channel' => $channel,
            ':recipient_address' => $recipient_address,
            ':recipient_type' => $recipient_type,
            ':subject' => $subject,
            ':message_body' => $message_body,
            ':status' => $status,
            ':error_message' => $error_message,
            ':sent_at' => $sent_at,
            ':priority' => $priority,
            ':scheduled_at' => $scheduled_at,
            ':created_by' => $created_by
        ]);
    } catch (PDOException $e) {
        error_log("Log notification failed: " . $e->getMessage());
        return false;
    }
}

// ============================================
// SMS SENDER FUNCTION
// ============================================
function send_sms(string $phone, string $message): bool {
    // TODO: Integrate actual SMS gateway (Twilio, Africa's Talking, etc.)
    error_log("SMS to $phone: " . substr($message, 0, 100));
    return true;
}

// ============================================
// EMAIL SENDER FUNCTION
// ============================================
function send_email(string $email, string $subject, string $html_body): bool {
    // TODO: Integrate actual SMTP/mailer service (PHPMailer)
    error_log("Email to $email: $subject");
    return true;
}

// ============================================
// GET NOTIFICATION RULES
// ============================================
function get_notification_rules(PDO $pdo): array {
    try {
        $stmt = $pdo->query("SELECT * FROM notification_rules ORDER BY event_trigger");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Get notification rules error: " . $e->getMessage());
        return [];
    }
}

// ============================================
// GET STAFF ROLES
// ============================================
function get_staff_roles(PDO $pdo): array {
    try {
        $stmt = $pdo->query("SELECT DISTINCT user_type FROM users WHERE is_active = 1 AND user_type != 'patient'");
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) {
        error_log("Get staff roles error: " . $e->getMessage());
        return [];
    }
}

// ============================================
// GET STAFF BY ROLE
// ============================================
function get_staff_by_role(PDO $pdo, string $role, int $branch_id): array {
    try {
        $sql = "SELECT id, CONCAT(first_name, ' ', COALESCE(middle_name, ''), ' ', last_name) AS full_name, 
                       email, phone, user_type 
                FROM users 
                WHERE user_type = :role AND is_active = 1 AND deleted_at IS NULL";
        $params = [':role' => $role];
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Get staff by role error: " . $e->getMessage());
        return [];
    }
}

// ============================================
// GET APPOINTMENT REMINDER COUNT
// ============================================
function get_appointment_reminder_counts(PDO $pdo, int $branch_id, bool $is_global_admin): array {
    try {
        $result = ['today' => 0, 'tomorrow' => 0, 'pending' => 0];
        
        // Today's appointments
        $sql = "SELECT COUNT(*) FROM appointments a
                WHERE a.appointment_date = CURDATE()
                    AND a.deleted_at IS NULL
                    AND a.status NOT IN ('cancelled', 'completed', 'no_show')";
        $params = [];
        if (!$is_global_admin && $branch_id > 0) {
            $sql .= " AND a.branch_id = :branch_id";
            $params[':branch_id'] = $branch_id;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $result['today'] = (int)$stmt->fetchColumn();
        
        // Tomorrow's appointments
        $sql = "SELECT COUNT(*) FROM appointments a
                WHERE a.appointment_date = DATE_ADD(CURDATE(), INTERVAL 1 DAY)
                    AND a.deleted_at IS NULL
                    AND a.status NOT IN ('cancelled', 'completed', 'no_show')";
        $params = [];
        if (!$is_global_admin && $branch_id > 0) {
            $sql .= " AND a.branch_id = :branch_id";
            $params[':branch_id'] = $branch_id;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $result['tomorrow'] = (int)$stmt->fetchColumn();
        
        $result['pending'] = $result['today'] + $result['tomorrow'];
        
        return $result;
    } catch (PDOException $e) {
        error_log("Get appointment reminder counts error: " . $e->getMessage());
        return ['today' => 0, 'tomorrow' => 0, 'pending' => 0];
    }
}

// ============================================
// AJAX HANDLER FOR ALL NOTIFICATION ACTIONS
// ============================================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'notify_action') {
    header('Content-Type: application/json; charset=utf-8');
    
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_exit(['success' => false, 'message' => 'Invalid request method.']);
    }
    
    // CSRF validation for write operations
    $action = (string)($_POST['action'] ?? '');
    if (!in_array($action, ['fetch_logs', 'fetch_counts', 'fetch_rules', 'fetch_roles', 'fetch_scheduled'])) {
        $submitted_token = $_POST['csrf_token'] ?? '';
        if (!isset($_SESSION['csrf_token']) || $submitted_token !== $_SESSION['csrf_token']) {
            error_log("Notifications: CSRF validation failed for user {$user_id}");
            json_exit(['success' => false, 'message' => 'Security validation failed.']);
        }
    }
    
    // ================================================================
    // SEND APPOINTMENT REMINDERS
    // ================================================================
    if ($action === 'send_reminders') {
        try {
            $reminder_branch = $is_global_admin ? (int)($_POST['branch_id'] ?? $user_branch_id) : $user_branch_id;
            $reminder_type = $_POST['reminder_type'] ?? 'upcoming';
            
            if ($reminder_type === 'today') {
                $date_condition = "a.appointment_date = CURDATE()";
                $reminder_label = "today's";
            } else {
                $date_condition = "a.appointment_date = DATE_ADD(CURDATE(), INTERVAL 1 DAY)";
                $reminder_label = "tomorrow's";
            }
            
            $sql = "SELECT 
                        a.id AS appointment_id,
                        a.appointment_date,
                        a.appointment_time,
                        p.id AS patient_id,
                        p.mr_number,
                        CONCAT(p.first_name, ' ', COALESCE(p.middle_name, ''), ' ', p.last_name) AS patient_name,
                        p.phone AS patient_phone,
                        p.email AS patient_email,
                        p.blood_group,
                        CONCAT(d.first_name, ' ', COALESCE(d.middle_name, ''), ' ', d.last_name) AS doctor_name,
                        dep.name AS department_name,
                        b.name AS branch_name,
                        ip.provider_name AS insurance_provider,
                        ip.policy_number AS insurance_policy
                    FROM appointments a
                    INNER JOIN patients p ON a.patient_id = p.id AND p.deleted_at IS NULL AND p.is_active = 1
                    LEFT JOIN users d ON a.doctor_id = d.id AND d.user_type = 'doctor' AND d.deleted_at IS NULL
                    LEFT JOIN departments dep ON a.department_id = dep.id AND dep.deleted_at IS NULL
                    LEFT JOIN branches b ON a.branch_id = b.id AND b.deleted_at IS NULL
                    LEFT JOIN insurance_policies ip ON p.id = ip.patient_id AND ip.status = 'active'
                    WHERE $date_condition
                        AND a.deleted_at IS NULL
                        AND a.status NOT IN ('cancelled', 'completed', 'no_show')
                        AND (p.phone IS NOT NULL OR p.email IS NOT NULL)";
            
            $params = [];
            if (!$is_global_admin && $reminder_branch > 0) {
                $sql .= " AND a.branch_id = :branch_id";
                $params[':branch_id'] = $reminder_branch;
            }
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (empty($appointments)) {
                json_exit(['success' => true, 'message' => "No $reminder_label appointments found to notify.", 'sent' => 0]);
            }
            
            $sent_count = 0;
            $failed_count = 0;
            $sms_count = 0;
            $email_count = 0;
            
            foreach ($appointments as $apt) {
                $patient_name = !empty($apt['patient_name']) ? $apt['patient_name'] : 'Patient';
                $doctor_name = !empty($apt['doctor_name']) ? $apt['doctor_name'] : 'the doctor';
                $branch_name_val = !empty($apt['branch_name']) ? $apt['branch_name'] : 'Hospital';
                
                // SMS Reminder
                if (!empty($apt['patient_phone'])) {
                    $sms_body = "Dear $patient_name, this is a reminder of your appointment on " . 
                                date('F j, Y', strtotime($apt['appointment_date'])) . " at " . 
                                date('g:i A', strtotime($apt['appointment_time'])) . 
                                " with Dr. $doctor_name. Please arrive 15 minutes early.";
                    
                    if (!empty($apt['mr_number'])) {
                        $sms_body .= " MRN: " . $apt['mr_number'];
                    }
                    
                    $sms_sent = send_sms($apt['patient_phone'], $sms_body);
                    log_notification($pdo, $apt['patient_id'], $apt['appointment_id'], $reminder_branch, 
                                    'appointment_reminder', 'sms', $apt['patient_phone'], 
                                    $sms_sent ? 'sent' : 'failed', $sms_sent ? null : 'SMS gateway error', 
                                    $sms_body, 'Appointment Reminder', $user_id, 'patient', 'normal');
                    
                    if ($sms_sent) { $sent_count++; $sms_count++; } else { $failed_count++; }
                }
                
                // Email Reminder
                if (!empty($apt['patient_email'])) {
                    $email_subject = "Appointment Reminder - $branch_name_val";
                    $email_body = "<html><body>
                        <h3>Appointment Reminder</h3>
                        <p>Dear <strong>" . htmlspecialchars($patient_name) . "</strong>,</p>
                        <p>This is a friendly reminder of your upcoming appointment:</p>
                        <ul>
                            <li><strong>Date:</strong> " . date('F j, Y', strtotime($apt['appointment_date'])) . "</li>
                            <li><strong>Time:</strong> " . date('g:i A', strtotime($apt['appointment_time'])) . "</li>
                            <li><strong>Doctor:</strong> Dr. " . htmlspecialchars($doctor_name) . "</li>
                            <li><strong>Department:</strong> " . htmlspecialchars($apt['department_name'] ?? 'N/A') . "</li>
                            <li><strong>Location:</strong> " . htmlspecialchars($branch_name_val) . "</li>";
                    
                    if (!empty($apt['mr_number'])) {
                        $email_body .= "<li><strong>MRN:</strong> " . htmlspecialchars($apt['mr_number']) . "</li>";
                    }
                    
                    if (!empty($apt['insurance_provider'])) {
                        $email_body .= "<li><strong>Insurance:</strong> " . htmlspecialchars($apt['insurance_provider']) . "</li>";
                    }
                    
                    $email_body .= "</ul>
                        <p>Please arrive 15 minutes before your scheduled time.</p>
                        <p>Thank you for choosing " . htmlspecialchars($branch_name_val) . ".</p>
                        </body></html>";
                    
                    $email_sent = send_email($apt['patient_email'], $email_subject, $email_body);
                    log_notification($pdo, $apt['patient_id'], $apt['appointment_id'], $reminder_branch, 
                                    'appointment_reminder', 'email', $apt['patient_email'], 
                                    $email_sent ? 'sent' : 'failed', $email_sent ? null : 'SMTP error', 
                                    $email_body, $email_subject, $user_id, 'patient', 'normal');
                    
                    if ($email_sent) { $sent_count++; $email_count++; } else { $failed_count++; }
                }
            }
            
            json_exit([
                'success' => true,
                'message' => "Reminders sent: $sent_count successful ($sms_count SMS, $email_count Email), $failed_count failed",
                'sent' => $sent_count,
                'failed' => $failed_count,
                'sms' => $sms_count,
                'email' => $email_count
            ]);
            
        } catch (PDOException $e) {
            error_log("Send reminders PDO error: " . $e->getMessage());
            json_exit(['success' => false, 'message' => 'Database error occurred']);
        } catch (Exception $e) {
            error_log("Send reminders error: " . $e->getMessage());
            json_exit(['success' => false, 'message' => 'Failed to send reminders']);
        }
    }
    
    // ================================================================
    // SEND BULK ANNOUNCEMENT
    // ================================================================
    if ($action === 'send_bulk') {
        try {
            $target_type = $_POST['target_type'] ?? 'all';
            $target_value = $_POST['target_value'] ?? '';
            $channel = $_POST['channel'] ?? 'email';
            $subject = trim($_POST['subject'] ?? '');
            $message = trim($_POST['message'] ?? '');
            $priority = $_POST['priority'] ?? 'normal';
            $bulk_branch = $is_global_admin ? (int)($_POST['branch_id'] ?? $user_branch_id) : $user_branch_id;
            
            if (empty($message)) {
                json_exit(['success' => false, 'message' => 'Message content cannot be empty']);
            }
            
            if ($channel === 'email' && empty($subject)) {
                json_exit(['success' => false, 'message' => 'Email subject is required']);
            }
            
            // Build patient query with branch isolation
            $sql = "SELECT id, first_name, middle_name, last_name, email, phone, branch_id, mr_number 
                    FROM patients 
                    WHERE is_active = 1 AND deleted_at IS NULL";
            
            $params = [];
            
            if (!$is_global_admin && $bulk_branch > 0) {
                $sql .= " AND branch_id = :branch_id";
                $params[':branch_id'] = $bulk_branch;
            }
            
            if ($target_type === 'gender' && !empty($target_value)) {
                $sql .= " AND gender = :target_value";
                $params[':target_value'] = $target_value;
            } elseif ($target_type === 'blood_group' && !empty($target_value)) {
                $sql .= " AND blood_group = :target_value";
                $params[':target_value'] = $target_value;
            } elseif ($target_type === 'staff_role' && !empty($target_value)) {
                // Send to staff instead of patients
                $staff_sql = "SELECT id, first_name, middle_name, last_name, email, phone, branch_id 
                              FROM users 
                              WHERE user_type = :role AND is_active = 1 AND deleted_at IS NULL";
                $staff_params = [':role' => $target_value];
                
                if (!$is_global_admin && $bulk_branch > 0) {
                    $staff_sql .= " AND branch_id = :branch_id";
                    $staff_params[':branch_id'] = $bulk_branch;
                }
                
                $stmt = $pdo->prepare($staff_sql);
                $stmt->execute($staff_params);
                $staff_members = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                if (empty($staff_members)) {
                    json_exit(['success' => false, 'message' => 'No staff members found with the selected role']);
                }
                
                $sent_count = 0;
                $failed_count = 0;
                
                foreach ($staff_members as $staff) {
                    $staff_full_name = trim($staff['first_name'] . ' ' . 
                                          ($staff['middle_name'] ? $staff['middle_name'] . ' ' : '') . 
                                          $staff['last_name']);
                    
                    $processed_message = str_replace(
                        ['{STAFF_NAME}', '{BRANCH_NAME}'],
                        [$staff_full_name, $_SESSION['branch_name'] ?? 'Hospital'],
                        $message
                    );
                    
                    if ($channel === 'sms' && !empty($staff['phone'])) {
                        $sms_sent = send_sms($staff['phone'], $processed_message);
                        log_notification($pdo, null, null, $bulk_branch, 'bulk_announcement', 'sms', 
                                        $staff['phone'], $sms_sent ? 'sent' : 'failed', 
                                        $sms_sent ? null : 'SMS gateway error', $processed_message, $subject, 
                                        $user_id, 'staff', $priority);
                        if ($sms_sent) $sent_count++; else $failed_count++;
                    } elseif ($channel === 'email' && !empty($staff['email'])) {
                        $email_sent = send_email($staff['email'], $subject, nl2br(htmlspecialchars($processed_message)));
                        log_notification($pdo, null, null, $bulk_branch, 'bulk_announcement', 'email', 
                                        $staff['email'], $email_sent ? 'sent' : 'failed', 
                                        $email_sent ? null : 'SMTP error', $processed_message, $subject, 
                                        $user_id, 'staff', $priority);
                        if ($email_sent) $sent_count++; else $failed_count++;
                    }
                }
                
                json_exit([
                    'success' => true,
                    'message' => "Bulk {$channel} sent to " . count($staff_members) . " staff members: $sent_count delivered, $failed_count failed",
                    'sent' => $sent_count,
                    'failed' => $failed_count,
                    'total_targeted' => count($staff_members)
                ]);
                return;
            }
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $patients = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (empty($patients)) {
                json_exit(['success' => false, 'message' => 'No patients found matching the selected criteria']);
            }
            
            $sent_count = 0;
            $failed_count = 0;
            $branch_name_val = $_SESSION['branch_name'] ?? 'Hospital';
            
            foreach ($patients as $patient) {
                $patient_full_name = trim($patient['first_name'] . ' ' . 
                                        ($patient['middle_name'] ? $patient['middle_name'] . ' ' : '') . 
                                        $patient['last_name']);
                if (empty($patient_full_name)) $patient_full_name = 'Patient';
                
                $processed_message = str_replace(
                    ['{PATIENT_NAME}', '{BRANCH_NAME}', '{MR_NUMBER}'],
                    [$patient_full_name, $branch_name_val, $patient['mr_number'] ?? 'N/A'],
                    $message
                );
                
                if ($channel === 'sms' && !empty($patient['phone'])) {
                    $sms_sent = send_sms($patient['phone'], $processed_message);
                    log_notification($pdo, $patient['id'], null, $bulk_branch, 'bulk_announcement', 'sms', 
                                    $patient['phone'], $sms_sent ? 'sent' : 'failed', 
                                    $sms_sent ? null : 'SMS gateway error', $processed_message, $subject, 
                                    $user_id, 'patient', $priority);
                    if ($sms_sent) $sent_count++; else $failed_count++;
                } elseif ($channel === 'email' && !empty($patient['email'])) {
                    $email_sent = send_email($patient['email'], $subject, nl2br(htmlspecialchars($processed_message)));
                    log_notification($pdo, $patient['id'], null, $bulk_branch, 'bulk_announcement', 'email', 
                                    $patient['email'], $email_sent ? 'sent' : 'failed', 
                                    $email_sent ? null : 'SMTP error', $processed_message, $subject, 
                                    $user_id, 'patient', $priority);
                    if ($email_sent) $sent_count++; else $failed_count++;
                }
            }
            
            json_exit([
                'success' => true,
                'message' => "Bulk {$channel} sent to " . count($patients) . " patients: $sent_count delivered, $failed_count failed",
                'sent' => $sent_count,
                'failed' => $failed_count,
                'total_targeted' => count($patients)
            ]);
            
        } catch (PDOException $e) {
            error_log("Send bulk PDO error: " . $e->getMessage());
            json_exit(['success' => false, 'message' => 'Database error occurred']);
        } catch (Exception $e) {
            error_log("Send bulk error: " . $e->getMessage());
            json_exit(['success' => false, 'message' => 'Failed to send bulk announcement']);
        }
    }
    
    // ================================================================
    // SEND CRITICAL ALERT
    // ================================================================
    if ($action === 'send_critical_alert') {
        try {
            $alert_branch = $is_global_admin ? (int)($_POST['branch_id'] ?? $user_branch_id) : $user_branch_id;
            $alert_type = $_POST['alert_type'] ?? 'critical';
            $recipient_role = $_POST['recipient_role'] ?? 'all';
            $subject = trim($_POST['subject'] ?? '');
            $message = trim($_POST['message'] ?? '');
            
            if (empty($message)) {
                json_exit(['success' => false, 'message' => 'Alert message cannot be empty']);
            }
            
            // Get recipients based on role
            $recipients = [];
            if ($recipient_role === 'all') {
                $sql = "SELECT id, CONCAT(first_name, ' ', COALESCE(middle_name, ''), ' ', last_name) AS name,
                               email, phone 
                        FROM users 
                        WHERE is_active = 1 AND deleted_at IS NULL AND user_type != 'patient'";
            } else {
                $sql = "SELECT id, CONCAT(first_name, ' ', COALESCE(middle_name, ''), ' ', last_name) AS name,
                               email, phone 
                        FROM users 
                        WHERE user_type = :role AND is_active = 1 AND deleted_at IS NULL";
            }
            
            $params = [];
            if ($recipient_role !== 'all') {
                $params[':role'] = $recipient_role;
            }
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $recipients = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (empty($recipients)) {
                json_exit(['success' => false, 'message' => 'No recipients found']);
            }
            
            $sent_count = 0;
            $failed_count = 0;
            
            foreach ($recipients as $recipient) {
                if (!empty($recipient['email'])) {
                    $email_sent = send_email($recipient['email'], "[CRITICAL ALERT] $subject", 
                                            "<div style='background:#fee; border:2px solid #f00; padding:15px;'>
                                                <h2 style='color:#f00;'>⚠️ CRITICAL ALERT</h2>
                                                <p>" . nl2br(htmlspecialchars($message)) . "</p>
                                                <p><small>Sent from HMS ERP System</small></p>
                                             </div>");
                    
                    log_notification($pdo, null, null, $alert_branch, 'critical_alert', 'email', 
                                    $recipient['email'], $email_sent ? 'sent' : 'failed', 
                                    $email_sent ? null : 'SMTP error', $message, $subject, 
                                    $user_id, 'staff', 'critical');
                    
                    if ($email_sent) $sent_count++; else $failed_count++;
                }
            }
            
            json_exit([
                'success' => true,
                'message' => "Critical alert sent to " . count($recipients) . " staff members: $sent_count delivered, $failed_count failed"
            ]);
            
        } catch (Exception $e) {
            error_log("Send critical alert error: " . $e->getMessage());
            json_exit(['success' => false, 'message' => 'Failed to send critical alert']);
        }
    }
    
    // ================================================================
    // SCHEDULE NOTIFICATION
    // ================================================================
    if ($action === 'schedule_notification') {
        try {
            $schedule_branch = $is_global_admin ? (int)($_POST['branch_id'] ?? $user_branch_id) : $user_branch_id;
            $notification_type = $_POST['notification_type'] ?? 'bulk_announcement';
            $channel = $_POST['channel'] ?? 'email';
            $recipient_type = $_POST['recipient_type'] ?? 'patients';
            $recipient_value = $_POST['recipient_value'] ?? '';
            $subject = trim($_POST['subject'] ?? '');
            $message = trim($_POST['message'] ?? '');
            $scheduled_at = $_POST['scheduled_at'] ?? null;
            $recurrence = $_POST['recurrence'] ?? 'none';
            
            if (empty($message)) {
                json_exit(['success' => false, 'message' => 'Message content cannot be empty']);
            }
            
            if (empty($scheduled_at)) {
                json_exit(['success' => false, 'message' => 'Scheduled date/time is required']);
            }
            
            $sql = "INSERT INTO notification_scheduled 
                    (branch_id, notification_type, channel, recipient_type, recipient_value, 
                     subject, message_template, scheduled_at, recurrence_pattern, created_by) 
                    VALUES 
                    (:branch_id, :notification_type, :channel, :recipient_type, :recipient_value,
                     :subject, :message_template, :scheduled_at, :recurrence, :created_by)";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':branch_id' => $schedule_branch,
                ':notification_type' => $notification_type,
                ':channel' => $channel,
                ':recipient_type' => $recipient_type,
                ':recipient_value' => $recipient_value,
                ':subject' => $subject,
                ':message_template' => $message,
                ':scheduled_at' => $scheduled_at,
                ':recurrence' => $recurrence,
                ':created_by' => $user_id
            ]);
            
            json_exit([
                'success' => true,
                'message' => 'Notification scheduled successfully',
                'id' => $pdo->lastInsertId()
            ]);
            
        } catch (Exception $e) {
            error_log("Schedule notification error: " . $e->getMessage());
            json_exit(['success' => false, 'message' => 'Failed to schedule notification']);
        }
    }
    
    // ================================================================
    // FETCH NOTIFICATION LOGS
    // ================================================================
    if ($action === 'fetch_logs') {
        try {
            $log_branch = $is_global_admin ? (int)($_POST['branch_id'] ?? $user_branch_id) : $user_branch_id;
            $limit = min(200, (int)($_POST['limit'] ?? 50));
            $status_filter = $_POST['status_filter'] ?? '';
            
            $sql = "SELECT nl.*, 
                           CONCAT(p.first_name, ' ', COALESCE(p.middle_name, ''), ' ', p.last_name) AS patient_name,
                           CONCAT(u.first_name, ' ', COALESCE(u.middle_name, ''), ' ', u.last_name) AS created_by_name
                    FROM notification_logs nl
                    LEFT JOIN patients p ON nl.patient_id = p.id AND p.deleted_at IS NULL
                    LEFT JOIN users u ON nl.created_by = u.id AND u.deleted_at IS NULL
                    WHERE 1=1";
            
            $params = [];
            
            if (!$is_global_admin && $log_branch > 0) {
                $sql .= " AND nl.branch_id = :branch_id";
                $params[':branch_id'] = $log_branch;
            }
            
            if (!empty($status_filter)) {
                $sql .= " AND nl.status = :status_filter";
                $params[':status_filter'] = $status_filter;
            }
            
            $sql .= " ORDER BY nl.created_at DESC LIMIT :limit";
            $params[':limit'] = $limit;
            
            $stmt = $pdo->prepare($sql);
            foreach ($params as $key => $value) {
                if ($key === ':limit') {
                    $stmt->bindValue($key, $value, PDO::PARAM_INT);
                } else {
                    $stmt->bindValue($key, $value);
                }
            }
            $stmt->execute();
            $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Sanitize output
            foreach ($logs as &$log) {
                $log['patient_name'] = htmlspecialchars($log['patient_name'] ?? 'N/A', ENT_QUOTES, 'UTF-8');
                $log['created_by_name'] = htmlspecialchars($log['created_by_name'] ?? 'System', ENT_QUOTES, 'UTF-8');
                $log['message_body'] = htmlspecialchars(substr($log['message_body'] ?? '', 0, 150), ENT_QUOTES, 'UTF-8');
                $log['notification_type'] = str_replace('_', ' ', $log['notification_type'] ?? 'unknown');
                $log['recipient_type'] = htmlspecialchars($log['recipient_type'] ?? 'patient', ENT_QUOTES, 'UTF-8');
            }
            
            json_exit(['success' => true, 'logs' => $logs]);
            
        } catch (PDOException $e) {
            error_log("Fetch logs PDO error: " . $e->getMessage());
            json_exit(['success' => false, 'message' => 'Database error', 'logs' => []]);
        }
    }
    
    // ================================================================
    // FETCH COUNT STATS
    // ================================================================
    if ($action === 'fetch_counts') {
        try {
            $stats_branch = $is_global_admin ? (int)($_POST['branch_id'] ?? $user_branch_id) : $user_branch_id;
            
            $counts = get_appointment_reminder_counts($pdo, $stats_branch, $is_global_admin);
            
            // Count logs today
            $sql = "SELECT COUNT(*) FROM notification_logs WHERE DATE(created_at) = CURDATE()";
            $params = [];
            if (!$is_global_admin && $stats_branch > 0) {
                $sql .= " AND branch_id = :branch_id";
                $params[':branch_id'] = $stats_branch;
            }
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $total_today = (int)$stmt->fetchColumn();
            
            // Count failed today
            $sql = "SELECT COUNT(*) FROM notification_logs WHERE DATE(created_at) = CURDATE() AND status = 'failed'";
            $params = [];
            if (!$is_global_admin && $stats_branch > 0) {
                $sql .= " AND branch_id = :branch_id";
                $params[':branch_id'] = $stats_branch;
            }
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $failed_today = (int)$stmt->fetchColumn();
            
            // Count scheduled notifications
            $sql = "SELECT COUNT(*) FROM notification_scheduled WHERE status = 'pending' AND scheduled_at > NOW()";
            $params = [];
            if (!$is_global_admin && $stats_branch > 0) {
                $sql .= " AND branch_id = :branch_id";
                $params[':branch_id'] = $stats_branch;
            }
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $scheduled = (int)$stmt->fetchColumn();
            
            $counts['total_logs_today'] = $total_today;
            $counts['failed_today'] = $failed_today;
            $counts['scheduled'] = $scheduled;
            
            json_exit(['success' => true, 'counts' => $counts]);
            
        } catch (PDOException $e) {
            error_log("Fetch counts PDO error: " . $e->getMessage());
            json_exit(['success' => false, 'message' => 'Database error', 'counts' => [
                'today' => 0, 'tomorrow' => 0, 'pending' => 0, 
                'total_logs_today' => 0, 'failed_today' => 0, 'scheduled' => 0
            ]]);
        }
    }
    
    // ================================================================
    // FETCH NOTIFICATION RULES
    // ================================================================
    if ($action === 'fetch_rules') {
        $rules = get_notification_rules($pdo);
        json_exit(['success' => true, 'rules' => $rules]);
    }
    
    // ================================================================
    // FETCH STAFF ROLES
    // ================================================================
    if ($action === 'fetch_roles') {
        $roles = get_staff_roles($pdo);
        json_exit(['success' => true, 'roles' => $roles]);
    }
    
    // ================================================================
    // FETCH SCHEDULED NOTIFICATIONS
    // ================================================================
    if ($action === 'fetch_scheduled') {
        try {
            $sched_branch = $is_global_admin ? (int)($_POST['branch_id'] ?? $user_branch_id) : $user_branch_id;
            
            $sql = "SELECT ns.*, CONCAT(u.first_name, ' ', COALESCE(u.middle_name, ''), ' ', u.last_name) AS created_by_name
                    FROM notification_scheduled ns
                    LEFT JOIN users u ON ns.created_by = u.id AND u.deleted_at IS NULL
                    WHERE ns.deleted_at IS NULL";
            
            $params = [];
            if (!$is_global_admin && $sched_branch > 0) {
                $sql .= " AND ns.branch_id = :branch_id";
                $params[':branch_id'] = $sched_branch;
            }
            
            $sql .= " ORDER BY ns.scheduled_at ASC LIMIT 50";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $scheduled = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($scheduled as &$s) {
                $s['created_by_name'] = htmlspecialchars($s['created_by_name'] ?? 'System', ENT_QUOTES, 'UTF-8');
                $s['message_template'] = htmlspecialchars(substr($s['message_template'] ?? '', 0, 100), ENT_QUOTES, 'UTF-8');
            }
            
            json_exit(['success' => true, 'scheduled' => $scheduled]);
            
        } catch (PDOException $e) {
            error_log("Fetch scheduled PDO error: " . $e->getMessage());
            json_exit(['success' => false, 'message' => 'Database error', 'scheduled' => []]);
        }
    }
    
    json_exit(['success' => false, 'message' => 'Unsupported action']);
}

// ============================================
// INITIAL DATA FETCHING
// ============================================
$initial_logs = [];
$counts = ['today' => 0, 'tomorrow' => 0, 'pending' => 0, 'total_logs_today' => 0, 'failed_today' => 0, 'scheduled' => 0];

try {
    // Check if logs table exists
    $table_check = $pdo->query("SHOW TABLES LIKE 'notification_logs'");
    if ($table_check && $table_check->rowCount() > 0) {
        $log_sql = "SELECT nl.*, 
                           CONCAT(p.first_name, ' ', COALESCE(p.middle_name, ''), ' ', p.last_name) AS patient_name
                    FROM notification_logs nl
                    LEFT JOIN patients p ON nl.patient_id = p.id AND p.deleted_at IS NULL
                    WHERE 1=1";
        $params = [];
        
        if (!$is_global_admin && $user_branch_id > 0) {
            $log_sql .= " AND nl.branch_id = :branch_id";
            $params[':branch_id'] = $user_branch_id;
        }
        
        $log_sql .= " ORDER BY nl.created_at DESC LIMIT 50";
        
        $stmt = $pdo->prepare($log_sql);
        $stmt->execute($params);
        $initial_logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Fetch counts
    $counts = get_appointment_reminder_counts($pdo, $user_branch_id, $is_global_admin);
    
    // Fetch today's logs count
    $sql = "SELECT COUNT(*) FROM notification_logs WHERE DATE(created_at) = CURDATE()";
    $params = [];
    if (!$is_global_admin && $user_branch_id > 0) {
        $sql .= " AND branch_id = :branch_id";
        $params[':branch_id'] = $user_branch_id;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $counts['total_logs_today'] = (int)$stmt->fetchColumn();
    
    // Fetch failed today
    $sql = "SELECT COUNT(*) FROM notification_logs WHERE DATE(created_at) = CURDATE() AND status = 'failed'";
    $params = [];
    if (!$is_global_admin && $user_branch_id > 0) {
        $sql .= " AND branch_id = :branch_id";
        $params[':branch_id'] = $user_branch_id;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $counts['failed_today'] = (int)$stmt->fetchColumn();
    
    // Fetch scheduled count
    $table_check = $pdo->query("SHOW TABLES LIKE 'notification_scheduled'");
    if ($table_check && $table_check->rowCount() > 0) {
        $sql = "SELECT COUNT(*) FROM notification_scheduled WHERE status = 'pending' AND scheduled_at > NOW()";
        $params = [];
        if (!$is_global_admin && $user_branch_id > 0) {
            $sql .= " AND branch_id = :branch_id";
            $params[':branch_id'] = $user_branch_id;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $counts['scheduled'] = (int)$stmt->fetchColumn();
    }
    
} catch (PDOException $e) {
    error_log("Initial data fetch error: " . $e->getMessage());
}

// Get branch name
$branch_name_display = '';
$branch_stmt = $pdo->prepare("SELECT name FROM branches WHERE id = :id AND deleted_at IS NULL LIMIT 1");
$branch_stmt->execute([':id' => $user_branch_id]);
$branch_result = $branch_stmt->fetch(PDO::FETCH_ASSOC);
if ($branch_result) $branch_name_display = $branch_result['name'];

// Get staff roles
$staff_roles = get_staff_roles($pdo);
$notification_rules = get_notification_rules($pdo);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include('assets/inc/head.php'); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications Manager - HMS ERP</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
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
            --secondary: #311b92;
            --success: #10b981;
            --danger: #ef4444;
            --warning: #f59e0b;
            --info: #3b82f6;
            --gray-light: #e2e8f0;
            --gray: #64748b;
            --dark: #0f172a;
            --critical: #dc2626;
        }
        
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: linear-gradient(135deg, #f1f5f9 0%, #e2e8f0 100%);
        }
        
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
            .three-column-grid {
                grid-template-columns: 1fr !important;
                gap: 20px !important;
            }
            .two-column-grid {
                grid-template-columns: 1fr !important;
                gap: 20px !important;
            }
        }
        
        .page-header {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%);
            border-radius: 24px;
            padding: 24px 32px;
            margin-bottom: 28px;
            color: white;
        }
        
        .page-title {
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 4px;
        }
        
        .page-subtitle {
            opacity: 0.8;
            font-size: 14px;
        }
        
        .stats-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 16px;
            margin-bottom: 28px;
        }
        
        .stat-card {
            background: white;
            border-radius: 20px;
            padding: 18px 20px;
            border: 1px solid rgba(0,0,0,0.05);
            transition: all 0.3s;
        }
        
        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 30px rgba(0,0,0,0.08);
        }
        
        .stat-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 8px;
        }
        
        .stat-icon {
            width: 40px;
            height: 40px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
        }
        
        .stat-icon.primary { background: rgba(26,35,126,0.1); color: var(--primary); }
        .stat-icon.success { background: rgba(16,185,129,0.1); color: var(--success); }
        .stat-icon.warning { background: rgba(245,158,11,0.1); color: var(--warning); }
        .stat-icon.danger { background: rgba(239,68,68,0.1); color: var(--danger); }
        .stat-icon.info { background: rgba(59,130,246,0.1); color: var(--info); }
        
        .stat-value {
            font-size: 28px;
            font-weight: 800;
            color: var(--dark);
        }
        
        .stat-label {
            font-size: 12px;
            color: var(--gray);
            font-weight: 500;
        }
        
        .stat-detail {
            font-size: 11px;
            color: var(--gray);
            margin-top: 4px;
        }
        
        .three-column-grid {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 24px;
            margin-bottom: 28px;
        }
        
        .two-column-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
            margin-bottom: 28px;
        }
        
        .card-module {
            background: white;
            border-radius: 24px;
            border: 1px solid var(--gray-light);
            overflow: hidden;
            box-shadow: 0 4px 12px rgba(0,0,0,0.03);
        }
        
        .card-header {
            padding: 18px 22px;
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            border-bottom: 2px solid var(--primary);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .card-header h3 {
            margin: 0;
            font-size: 16px;
            font-weight: 700;
            color: var(--primary);
        }
        
        .card-header p {
            margin: 2px 0 0;
            font-size: 11px;
            color: var(--gray);
        }
        
        .card-body {
            padding: 20px 22px;
        }
        
        .reminder-buttons {
            display: flex;
            gap: 12px;
            margin-bottom: 16px;
            flex-wrap: wrap;
        }
        
        .btn-reminder {
            flex: 1;
            padding: 12px 16px;
            border-radius: 14px;
            font-weight: 600;
            font-size: 13px;
            border: none;
            transition: all 0.2s;
            cursor: pointer;
            min-width: 140px;
        }
        
        .btn-reminder-primary {
            background: linear-gradient(135deg, var(--primary), var(--primary-light));
            color: white;
        }
        
        .btn-reminder-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(26,35,126,0.3);
        }
        
        .btn-reminder-outline {
            background: white;
            border: 1px solid var(--gray-light);
            color: var(--dark);
        }
        
        .btn-reminder-outline:hover {
            border-color: var(--primary);
            color: var(--primary);
        }
        
        .btn-reminder-danger {
            background: linear-gradient(135deg, var(--danger), #b91c1c);
            color: white;
        }
        
        .btn-reminder-danger:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(239,68,68,0.3);
        }
        
        .form-group {
            margin-bottom: 16px;
        }
        
        .form-group label {
            font-size: 12px;
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 4px;
            display: block;
        }
        
        .form-control, .form-select {
            width: 100%;
            padding: 10px 14px;
            border: 1px solid var(--gray-light);
            border-radius: 12px;
            font-size: 13px;
            transition: all 0.2s;
            background: white;
        }
        
        .form-control:focus, .form-select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(26,35,126,0.1);
        }
        
        textarea.form-control {
            resize: vertical;
            min-height: 100px;
        }
        
        .placeholder-tokens {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            margin-bottom: 10px;
        }
        
        .token-pill {
            background: #f1f5f9;
            padding: 4px 12px;
            border-radius: 30px;
            font-size: 11px;
            font-family: monospace;
            cursor: pointer;
            transition: all 0.2s;
            color: var(--primary);
            border: 1px solid #e2e8f0;
        }
        
        .token-pill:hover {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }
        
        .logs-table-container {
            max-height: 500px;
            overflow-y: auto;
        }
        
        .logs-table {
            width: 100%;
            font-size: 12px;
        }
        
        .logs-table th {
            padding: 10px 12px;
            background: #f8fafc;
            font-weight: 600;
            color: var(--gray);
            border-bottom: 1px solid var(--gray-light);
            position: sticky;
            top: 0;
            background: white;
            z-index: 2;
        }
        
        .logs-table td {
            padding: 8px 12px;
            border-bottom: 1px solid #f1f5f9;
            vertical-align: middle;
        }
        
        .logs-table tr:hover td {
            background: #f8fafc;
        }
        
        .status-badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 30px;
            font-size: 10px;
            font-weight: 600;
        }
        
        .status-sent { background: #d1fae5; color: #065f46; }
        .status-failed { background: #fee2e2; color: #991b1b; }
        .status-pending { background: #fef3c7; color: #92400e; }
        .status-scheduled { background: #dbeafe; color: #1e40af; }
        .status-queued { background: #e0e7ff; color: #3730a3; }
        
        .badge-sms { background: #3b82f6; color: white; padding: 2px 8px; border-radius: 16px; font-size: 10px; }
        .badge-email { background: #8b5cf6; color: white; padding: 2px 8px; border-radius: 16px; font-size: 10px; }
        .badge-inapp { background: #06b6d4; color: white; padding: 2px 8px; border-radius: 16px; font-size: 10px; }
        .badge-whatsapp { background: #25d366; color: white; padding: 2px 8px; border-radius: 16px; font-size: 10px; }
        .badge-secondary { background: #64748b; color: white; padding: 2px 8px; border-radius: 16px; font-size: 10px; }
        .badge-critical { background: var(--critical); color: white; padding: 2px 8px; border-radius: 16px; font-size: 10px; }
        
        .btn-submit {
            background: linear-gradient(135deg, var(--success), #059669);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 12px;
            font-weight: 600;
            font-size: 13px;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(16,185,129,0.3);
        }
        
        .btn-danger {
            background: linear-gradient(135deg, var(--danger), #b91c1c);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 12px;
            font-weight: 600;
            font-size: 13px;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .btn-danger:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(239,68,68,0.3);
        }
        
        .btn-outline-secondary {
            background: white;
            border: 1px solid var(--gray-light);
            padding: 8px 14px;
            border-radius: 10px;
            font-size: 12px;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .btn-outline-secondary:hover {
            border-color: var(--primary);
            color: var(--primary);
        }
        
        .text-muted { color: var(--gray); }
        .small { font-size: 11px; }
        .mt-2 { margin-top: 12px; }
        .mt-3 { margin-top: 16px; }
        .mb-0 { margin-bottom: 0; }
        .me-1 { margin-right: 4px; }
        .me-2 { margin-right: 8px; }
        .ms-2 { margin-left: 8px; }
        .fw-bold { font-weight: 700; }
        
        hr {
            margin: 16px 0;
            border-color: var(--gray-light);
        }
        
        .loader-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.6);
            z-index: 2000;
            display: flex;
            align-items: center;
            justify-content: center;
            visibility: hidden;
        }
        
        .loader-overlay.active {
            visibility: visible;
        }
        
        .loader-content {
            background: white;
            border-radius: 20px;
            padding: 30px 40px;
            text-align: center;
        }
        
        .spinner {
            width: 40px;
            height: 40px;
            border: 3px solid #e2e8f0;
            border-top-color: var(--primary);
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
            margin: 0 auto 16px;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        .text-center { text-align: center; }
        .py-4 { padding-top: 24px; padding-bottom: 24px; }
        
        .alert-info {
            background: #e0e7ff;
            border: none;
            border-radius: 12px;
            padding: 10px 14px;
            font-size: 12px;
            color: var(--primary);
        }
        
        .alert-critical {
            background: #fee2e2;
            border: none;
            border-radius: 12px;
            padding: 10px 14px;
            font-size: 12px;
            color: var(--critical);
        }
        
        .rules-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
            max-height: 200px;
            overflow-y: auto;
        }
        
        .rule-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 6px 10px;
            background: #f8fafc;
            border-radius: 8px;
            font-size: 11px;
        }
        
        .rule-item .event {
            font-weight: 500;
            color: var(--dark);
        }
        
        .toggle-switch {
            position: relative;
            width: 36px;
            height: 20px;
            flex-shrink: 0;
        }
        
        .toggle-switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        
        .toggle-slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: #cbd5e1;
            transition: .3s;
            border-radius: 20px;
        }
        
        .toggle-slider:before {
            position: absolute;
            content: "";
            height: 14px;
            width: 14px;
            left: 3px;
            bottom: 3px;
            background: white;
            transition: .3s;
            border-radius: 50%;
        }
        
        .toggle-switch input:checked + .toggle-slider {
            background: var(--success);
        }
        
        .toggle-switch input:checked + .toggle-slider:before {
            transform: translateX(16px);
        }
        
        .scheduled-list {
            max-height: 150px;
            overflow-y: auto;
        }
        
        .scheduled-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 6px 10px;
            border-bottom: 1px solid #f1f5f9;
            font-size: 11px;
        }
        
        .scheduled-item .time {
            color: var(--gray);
            font-size: 10px;
        }
        
        .critical-alert-section {
            border-top: 2px solid var(--critical);
            padding-top: 16px;
            margin-top: 16px;
        }
        
        .flex-between {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .channel-selector {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        
        .channel-option {
            padding: 6px 14px;
            border-radius: 20px;
            border: 1px solid var(--gray-light);
            cursor: pointer;
            font-size: 12px;
            transition: all 0.2s;
            background: white;
        }
        
        .channel-option.active {
            border-color: var(--primary);
            background: var(--primary);
            color: white;
        }
        
        .channel-option:hover:not(.active) {
            border-color: var(--primary);
        }
        
        .priority-badge {
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 9px;
            font-weight: 700;
            text-transform: uppercase;
        }
        
        .priority-low { background: #e2e8f0; color: #475569; }
        .priority-normal { background: #dbeafe; color: #1e40af; }
        .priority-high { background: #fef3c7; color: #92400e; }
        .priority-critical { background: #fee2e2; color: #991b1b; }
        
        .filter-bar {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-bottom: 12px;
        }
        
        .filter-bar select, .filter-bar input {
            padding: 6px 12px;
            border: 1px solid var(--gray-light);
            border-radius: 8px;
            font-size: 12px;
            background: white;
        }
    </style>
</head>
<body>

<div id="wrapper">
    <?php include('assets/inc/nav.php'); ?>
    <?php include('assets/inc/sidebar.php'); ?>

    <div class="content-page">
        <div class="container-fluid">
            
            <!-- Page Header -->
            <div class="page-header">
                <div class="row align-items-center">
                    <div class="col-md-7">
                        <div class="page-title">
                            <i class="fas fa-bell me-2"></i> Notifications Manager
                        </div>
                        <div class="page-subtitle">
                            Send appointment reminders, bulk announcements, and critical alerts to patients and staff
                        </div>
                    </div>
                    <div class="col-md-5 text-md-end mt-3 mt-md-0">
                        <span class="badge bg-light text-dark p-2 me-2">
                            <i class="fas fa-building me-1"></i> <?php echo htmlspecialchars($branch_name_display, ENT_QUOTES, 'UTF-8'); ?>
                        </span>
                        <span class="badge bg-light text-dark p-2">
                            <i class="fas fa-user me-1"></i> <?php echo htmlspecialchars($user_name, ENT_QUOTES, 'UTF-8'); ?>
                        </span>
                    </div>
                </div>
            </div>
            
            <!-- Stats Cards -->
            <div class="stats-row">
                <div class="stat-card">
                    <div class="stat-header">
                        <div>
                            <div class="stat-value" id="statPendingReminders"><?php echo $counts['pending']; ?></div>
                            <div class="stat-label">Pending Reminders</div>
                        </div>
                        <div class="stat-icon primary">
                            <i class="fas fa-clock"></i>
                        </div>
                    </div>
                    <div class="stat-detail">
                        Today: <span id="statTodayReminders"><?php echo $counts['today']; ?></span> | 
                        Tomorrow: <span id="statTomorrowReminders"><?php echo $counts['tomorrow']; ?></span>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-header">
                        <div>
                            <div class="stat-value" id="statTotalToday"><?php echo $counts['total_logs_today']; ?></div>
                            <div class="stat-label">Notifications Today</div>
                        </div>
                        <div class="stat-icon success">
                            <i class="fas fa-check-circle"></i>
                        </div>
                    </div>
                    <div class="stat-detail">Failed: <span id="statFailedToday"><?php echo $counts['failed_today']; ?></span></div>
                </div>
                <div class="stat-card">
                    <div class="stat-header">
                        <div>
                            <div class="stat-value" id="statScheduled"><?php echo $counts['scheduled']; ?></div>
                            <div class="stat-label">Scheduled</div>
                        </div>
                        <div class="stat-icon info">
                            <i class="fas fa-calendar-plus"></i>
                        </div>
                    </div>
                    <div class="stat-detail">Pending future notifications</div>
                </div>
                <div class="stat-card">
                    <div class="stat-header">
                        <div>
                            <div class="stat-value" id="statTotalSent"><?php 
                                $total_sent = 0;
                                try {
                                    $sql = "SELECT COUNT(*) FROM notification_logs WHERE status = 'sent'";
                                    $stmt = $pdo->query($sql);
                                    $total_sent = (int)$stmt->fetchColumn();
                                } catch (Exception $e) {}
                                echo $total_sent;
                            ?></div>
                            <div class="stat-label">Total Sent</div>
                        </div>
                        <div class="stat-icon warning">
                            <i class="fas fa-envelope-open-text"></i>
                        </div>
                    </div>
                    <div class="stat-detail">All time notifications</div>
                </div>
            </div>
            
            <!-- Three Column Workspace -->
            <div class="three-column-grid">
                
                <!-- LEFT PANEL: Automated Reminders -->
                <div class="card-module">
                    <div class="card-header">
                        <div>
                            <h3><i class="fas fa-bell me-2"></i> Appointment Reminders</h3>
                            <p>Send reminders to patients with appointments today or tomorrow</p>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="reminder-buttons">
                            <button class="btn-reminder btn-reminder-primary" onclick="sendReminders('today')">
                                <i class="fas fa-calendar-day me-2"></i> Today's
                            </button>
                            <button class="btn-reminder btn-reminder-outline" onclick="sendReminders('upcoming')">
                                <i class="fas fa-calendar-alt me-2"></i> Tomorrow's
                            </button>
                        </div>
                        <div class="alert-info">
                            <i class="fas fa-info-circle me-2"></i> 
                            Reminders sent via SMS and Email to patients with appointments. 
                            <span id="reminderCountInfo"><?php echo $counts['pending']; ?> pending.</span>
                        </div>
                        
                        <!-- Insurance Info Display -->
                        <div class="mt-2" id="insuranceInfo" style="display:none;">
                            <hr>
                            <div class="small text-muted">
                                <i class="fas fa-shield-alt me-1"></i> 
                                <span id="insuranceCount">0</span> patients have active insurance policies.
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- MIDDLE PANEL: Bulk Announcements -->
                <div class="card-module">
                    <div class="card-header">
                        <div>
                            <h3><i class="fas fa-bullhorn me-2"></i> Bulk Announcements</h3>
                            <p>Target patients or staff by segment</p>
                        </div>
                    </div>
                    <div class="card-body">
                        <form id="bulkForm">
                            <div class="form-group">
                                <label>Target Audience</label>
                                <select name="target_type" id="targetType" class="form-select">
                                    <option value="all">All Patients</option>
                                    <option value="gender">By Gender</option>
                                    <option value="blood_group">By Blood Group</option>
                                    <option value="staff_role">Staff by Role</option>
                                </select>
                            </div>
                            <div class="form-group" id="targetValueGroup" style="display:none;">
                                <label>Select Value</label>
                                <select name="target_value" id="targetValue" class="form-select">
                                    <option value="">-- Select --</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Delivery Channel</label>
                                <div class="channel-selector">
                                    <span class="channel-option active" data-channel="email" onclick="selectChannel(this)">
                                        <i class="fas fa-envelope"></i> Email
                                    </span>
                                    <span class="channel-option" data-channel="sms" onclick="selectChannel(this)">
                                        <i class="fas fa-sms"></i> SMS
                                    </span>
                                    <span class="channel-option" data-channel="in_app" onclick="selectChannel(this)">
                                        <i class="fas fa-bell"></i> In-App
                                    </span>
                                </div>
                                <input type="hidden" name="channel" id="bulkChannel" value="email">
                            </div>
                            <div class="form-group" id="bulkSubjectGroup">
                                <label>Email Subject</label>
                                <input type="text" name="subject" id="bulkSubject" class="form-control" placeholder="e.g., Important Health Announcement">
                            </div>
                            <div class="form-group">
                                <label>Message Content</label>
                                <div class="placeholder-tokens">
                                    <span class="token-pill" data-token="{PATIENT_NAME}">{PATIENT_NAME}</span>
                                    <span class="token-pill" data-token="{BRANCH_NAME}">{BRANCH_NAME}</span>
                                    <span class="token-pill" data-token="{MR_NUMBER}">{MR_NUMBER}</span>
                                    <span class="token-pill" data-token="{STAFF_NAME}">{STAFF_NAME}</span>
                                </div>
                                <textarea name="message" id="bulkMessage" class="form-control" placeholder="Enter your announcement message..."></textarea>
                            </div>
                            <div class="form-group">
                                <label>Priority</label>
                                <select name="priority" id="bulkPriority" class="form-select">
                                    <option value="normal">Normal</option>
                                    <option value="high">High</option>
                                    <option value="critical">Critical</option>
                                </select>
                            </div>
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="branch_id" value="<?php echo (int)$user_branch_id; ?>">
                            <div class="mt-2">
                                <button type="button" class="btn-submit" onclick="sendBulkAnnouncement()">
                                    <i class="fas fa-paper-plane me-2"></i> Send Announcement
                                </button>
                                <button type="button" class="btn-outline-secondary ms-2" onclick="showScheduleModal()">
                                    <i class="fas fa-clock me-1"></i> Schedule
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
                
                <!-- RIGHT PANEL: Critical Alerts & Rules -->
                <div class="card-module">
                    <div class="card-header">
                        <div>
                            <h3><i class="fas fa-exclamation-triangle me-2"></i> Critical Alerts</h3>
                            <p>Send urgent alerts to staff members</p>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="form-group">
                            <label>Recipient Role</label>
                            <select id="alertRole" class="form-select">
                                <option value="all">All Staff</option>
                                <?php foreach ($staff_roles as $role): ?>
                                    <option value="<?php echo htmlspecialchars($role, ENT_QUOTES, 'UTF-8'); ?>">
                                        <?php echo ucwords(str_replace('_', ' ', $role)); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Alert Subject</label>
                            <input type="text" id="alertSubject" class="form-control" placeholder="e.g., Code Blue - Emergency">
                        </div>
                        <div class="form-group">
                            <label>Alert Message</label>
                            <textarea id="alertMessage" class="form-control" placeholder="Describe the urgent situation..."></textarea>
                        </div>
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                        <button type="button" class="btn-danger" onclick="sendCriticalAlert()">
                            <i class="fas fa-exclamation-circle me-2"></i> Send Critical Alert
                        </button>
                        
                        <!-- Notification Rules -->
                        <div class="critical-alert-section">
                            <div class="flex-between">
                                <label class="fw-bold small">Notification Rules</label>
                                <button class="btn-outline-secondary" onclick="refreshRules()" style="padding:4px 10px; font-size:10px;">
                                    <i class="fas fa-sync-alt"></i>
                                </button>
                            </div>
                            <div class="rules-grid" id="rulesContainer">
                                <?php foreach ($notification_rules as $rule): ?>
                                <div class="rule-item">
                                    <span class="event"><?php echo htmlspecialchars(str_replace('_', ' ', $rule['event_trigger']), ENT_QUOTES, 'UTF-8'); ?></span>
                                    <span style="font-size:10px; color:var(--gray);">
                                        <?php echo htmlspecialchars($rule['recipient_role'], ENT_QUOTES, 'UTF-8'); ?>
                                    </span>
                                    <span>
                                        <label class="toggle-switch">
                                            <input type="checkbox" <?php echo $rule['is_email_enabled'] ? 'checked' : ''; ?> 
                                                   onchange="updateRule(<?php echo $rule['id']; ?>, 'email', this.checked)">
                                            <span class="toggle-slider"></span>
                                        </label>
                                    </span>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Scheduled Notifications -->
            <div class="card-module mb-3">
                <div class="card-header">
                    <div>
                        <h3><i class="fas fa-calendar-check me-2"></i> Scheduled Notifications</h3>
                        <p>Upcoming scheduled announcements</p>
                    </div>
                    <button class="btn-outline-secondary" onclick="refreshScheduled()">
                        <i class="fas fa-sync-alt me-1"></i> Refresh
                    </button>
                </div>
                <div class="card-body">
                    <div class="scheduled-list" id="scheduledContainer">
                        <div class="text-center text-muted py-4 small">
                            <i class="fas fa-clock me-1"></i> No scheduled notifications
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Notification Logs -->
            <div class="card-module">
                <div class="card-header">
                    <div>
                        <h3><i class="fas fa-history me-2"></i> Notification History</h3>
                        <p>Recent delivery logs and status tracking</p>
                    </div>
                    <div>
                        <button class="btn-outline-secondary" onclick="refreshLogs()">
                            <i class="fas fa-sync-alt me-1"></i> Refresh
                        </button>
                    </div>
                </div>
                <div class="card-body">
                    <div class="filter-bar">
                        <select id="statusFilter" onchange="refreshLogs()">
                            <option value="">All Status</option>
                            <option value="sent">Sent</option>
                            <option value="failed">Failed</option>
                            <option value="pending">Pending</option>
                            <option value="scheduled">Scheduled</option>
                        </select>
                        <input type="text" id="logSearch" placeholder="Search..." onkeyup="filterLogs()">
                    </div>
                    <div class="logs-table-container">
                        <table class="logs-table" id="logsTable">
                            <thead>
                                <tr>
                                    <th>Date/Time</th>
                                    <th>Recipient</th>
                                    <th>Type</th>
                                    <th>Channel</th>
                                    <th>Status</th>
                                    <th>Priority</th>
                                    <th>Message</th>
                                </tr>
                            </thead>
                            <tbody id="logsTableBody">
                                <?php if (empty($initial_logs)): ?>
                                <tr>
                                    <td colspan="7" class="text-center text-muted py-4">No notification logs found</td>
                                </tr>
                                <?php else: ?>
                                    <?php foreach ($initial_logs as $log): ?>
                                    <tr>
                                        <td><?php echo date('m/d H:i', strtotime($log['created_at'])); ?></td>
                                        <td>
                                            <?php 
                                                $recipient = $log['patient_name'] ?? 'N/A';
                                                if (($log['recipient_type'] ?? '') === 'staff') {
                                                    $recipient = 'Staff';
                                                }
                                                echo htmlspecialchars($recipient, ENT_QUOTES, 'UTF-8'); 
                                            ?>
                                        </td>
                                        <td><span class="badge badge-secondary"><?php echo str_replace('_', ' ', $log['notification_type'] ?? 'unknown'); ?></span></td>
                                        <td>
                                            <span class="badge <?php echo $log['channel'] === 'sms' ? 'badge-sms' : ($log['channel'] === 'email' ? 'badge-email' : ($log['channel'] === 'in_app' ? 'badge-inapp' : 'badge-secondary')); ?>">
                                                <?php echo strtoupper($log['channel'] ?? 'unknown'); ?>
                                            </span>
                                        </td>
                                        <td><span class="status-badge status-<?php echo $log['status'] ?? 'pending'; ?>"><?php echo ucfirst($log['status'] ?? 'pending'); ?></span></td>
                                        <td>
                                            <span class="priority-badge priority-<?php echo $log['priority'] ?? 'normal'; ?>">
                                                <?php echo ucfirst($log['priority'] ?? 'normal'); ?>
                                            </span>
                                        </td>
                                        <td class="small text-muted"><?php echo htmlspecialchars(substr($log['message_body'] ?? '', 0, 50), ENT_QUOTES, 'UTF-8') . '...'; ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            
        </div>
    </div>
    
    <?php include('assets/inc/footer.php'); ?>
</div>

<!-- Schedule Modal -->
<div class="modal fade" id="scheduleModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-clock me-2"></i> Schedule Notification</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="scheduleForm">
                    <div class="form-group">
                        <label>Notification Type</label>
                        <select name="notification_type" id="scheduleType" class="form-select">
                            <option value="bulk_announcement">Bulk Announcement</option>
                            <option value="general_alert">General Alert</option>
                            <option value="appointment_reminder">Appointment Reminder</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Channel</label>
                        <select name="channel" id="scheduleChannel" class="form-select">
                            <option value="email">Email</option>
                            <option value="sms">SMS</option>
                            <option value="in_app">In-App</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Recipient Type</label>
                        <select name="recipient_type" id="scheduleRecipientType" class="form-select">
                            <option value="patients">Patients</option>
                            <option value="staff_role">Staff by Role</option>
                            <option value="all">All</option>
                        </select>
                    </div>
                    <div class="form-group" id="scheduleRoleGroup" style="display:none;">
                        <label>Staff Role</label>
                        <select name="recipient_value" id="scheduleRole" class="form-select">
                            <?php foreach ($staff_roles as $role): ?>
                                <option value="<?php echo htmlspecialchars($role, ENT_QUOTES, 'UTF-8'); ?>">
                                    <?php echo ucwords(str_replace('_', ' ', $role)); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Subject</label>
                        <input type="text" name="subject" id="scheduleSubject" class="form-control" placeholder="Subject">
                    </div>
                    <div class="form-group">
                        <label>Message</label>
                        <textarea name="message" id="scheduleMessage" class="form-control" rows="3" placeholder="Enter message..."></textarea>
                    </div>
                    <div class="form-group">
                        <label>Schedule Date/Time</label>
                        <input type="datetime-local" name="scheduled_at" id="scheduleDateTime" class="form-control">
                    </div>
                    <div class="form-group">
                        <label>Recurrence</label>
                        <select name="recurrence" id="scheduleRecurrence" class="form-select">
                            <option value="none">None (One-time)</option>
                            <option value="daily">Daily</option>
                            <option value="weekly">Weekly</option>
                            <option value="monthly">Monthly</option>
                        </select>
                    </div>
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="scheduleNotification()">
                    <i class="fas fa-calendar-plus me-1"></i> Schedule
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Loading Overlay -->
<div id="loaderOverlay" class="loader-overlay">
    <div class="loader-content">
        <div class="spinner"></div>
        <div class="fw-bold">Processing...</div>
        <div class="small text-muted mt-1">Please wait</div>
    </div>
</div>

<script src="assets/js/vendor.min.js"></script>
<script src="assets/js/bootstrap.bundle.min.js"></script>

<script>
// =================================================================
// NOTIFICATIONS MANAGER - Full JavaScript Controller
// =================================================================

const branchId = <?php echo (int)$user_branch_id; ?>;
const isGlobalAdmin = <?php echo $is_global_admin ? 'true' : 'false'; ?>;
const csrfToken = <?php echo json_encode($csrf_token); ?>;
const scriptUrl = window.location.pathname;

toastr.options = {
    positionClass: 'toast-top-right',
    progressBar: true,
    closeButton: true,
    timeOut: 4000
};

// Helper: Show/hide loading overlay
function showLoader(show) {
    const overlay = document.getElementById('loaderOverlay');
    if (show) overlay.classList.add('active');
    else overlay.classList.remove('active');
}

// Helper: Show toast notification
function showToast(message, type = 'success') {
    if (type === 'success') toastr.success(message);
    else if (type === 'error') toastr.error(message);
    else if (type === 'warning') toastr.warning(message);
    else toastr.info(message);
}

// =================================================================
// CHANNEL SELECTOR
// =================================================================
function selectChannel(el) {
    document.querySelectorAll('.channel-option').forEach(c => c.classList.remove('active'));
    el.classList.add('active');
    document.getElementById('bulkChannel').value = el.dataset.channel;
    
    // Show/hide subject for email
    const isEmail = el.dataset.channel === 'email';
    document.getElementById('bulkSubjectGroup').style.display = isEmail ? 'block' : 'none';
}

// =================================================================
// DYNAMIC Target Value Selector
// =================================================================
function updateTargetValueOptions() {
    const targetType = document.getElementById('targetType').value;
    const targetValueGroup = document.getElementById('targetValueGroup');
    const targetValueSelect = document.getElementById('targetValue');
    
    if (targetType === 'all') {
        targetValueGroup.style.display = 'none';
        return;
    }
    
    targetValueGroup.style.display = 'block';
    targetValueSelect.innerHTML = '<option value="">-- Select --</option>';
    
    if (targetType === 'gender') {
        targetValueSelect.innerHTML += `
            <option value="male">Male</option>
            <option value="female">Female</option>
            <option value="other">Other</option>
        `;
    } else if (targetType === 'blood_group') {
        const bloodGroups = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];
        bloodGroups.forEach(bg => {
            targetValueSelect.innerHTML += `<option value="${bg}">${bg}</option>`;
        });
    } else if (targetType === 'staff_role') {
        // Fetch staff roles from server
        $.ajax({
            url: scriptUrl + '?ajax=notify_action',
            method: 'POST',
            data: { action: 'fetch_roles', csrf_token: csrfToken },
            dataType: 'json',
            success: function(response) {
                if (response.success && response.roles) {
                    response.roles.forEach(role => {
                        targetValueSelect.innerHTML += `<option value="${role}">${role.charAt(0).toUpperCase() + role.slice(1)}</option>`;
                    });
                }
            }
        });
    }
}

// =================================================================
// TOKEN INSERTION
// =================================================================
function insertToken(token) {
    const textarea = document.getElementById('bulkMessage');
    if (!textarea) return;
    const start = textarea.selectionStart;
    const end = textarea.selectionEnd;
    const text = textarea.value;
    const newText = text.substring(0, start) + token + text.substring(end);
    textarea.value = newText;
    textarea.focus();
    textarea.selectionStart = textarea.selectionEnd = start + token.length;
}

// =================================================================
// ACTION: Send Reminders
// =================================================================
async function sendReminders(reminderType) {
    const typeLabel = reminderType === 'today' ? 'Today\'s' : 'Tomorrow\'s';
    
    const confirm = await Swal.fire({
        title: 'Confirm Reminders',
        html: `Send <strong>${typeLabel}</strong> appointment reminders to all eligible patients?`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#1a237e',
        confirmButtonText: 'Yes, Send Now',
        cancelButtonText: 'Cancel'
    });
    
    if (!confirm.isConfirmed) return;
    
    showLoader(true);
    
    $.ajax({
        url: scriptUrl + '?ajax=notify_action',
        method: 'POST',
        data: {
            action: 'send_reminders',
            reminder_type: reminderType,
            branch_id: branchId,
            csrf_token: csrfToken
        },
        dataType: 'json',
        success: function(response) {
            showLoader(false);
            if (response.success) {
                showToast(response.message, 'success');
                refreshStats();
                refreshLogs();
                refreshScheduled();
            } else {
                showToast(response.message || 'Failed to send reminders', 'error');
            }
        },
        error: function() {
            showLoader(false);
            showToast('Network error. Please try again.', 'error');
        }
    });
}

// =================================================================
// ACTION: Send Bulk Announcement
// =================================================================
async function sendBulkAnnouncement() {
    const targetType = document.getElementById('targetType').value;
    const targetValue = document.getElementById('targetValue').value;
    const channel = document.getElementById('bulkChannel').value;
    const subject = document.getElementById('bulkSubject').value.trim();
    const message = document.getElementById('bulkMessage').value.trim();
    const priority = document.getElementById('bulkPriority').value;
    
    if (!message) {
        showToast('Message content cannot be empty', 'error');
        return;
    }
    
    if (channel === 'email' && !subject) {
        showToast('Email subject is required', 'error');
        return;
    }
    
    const typeLabel = targetType === 'gender' ? 'Gender: ' + targetValue : 
                      (targetType === 'blood_group' ? 'Blood Group: ' + targetValue : 
                      (targetType === 'staff_role' ? 'Role: ' + targetValue : 'All Patients'));
    
    const confirm = await Swal.fire({
        title: 'Confirm Bulk Announcement',
        html: `Send <strong>${channel.toUpperCase()}</strong> announcement to <strong>${typeLabel}</strong>?<br><br>
               <div style="background:#f1f5f9; padding:12px; border-radius:12px; font-size:13px; text-align:left;">
               <strong>Message preview:</strong><br>${escapeHtml(message.substring(0, 100))}${message.length > 100 ? '...' : ''}
               </div>`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#10b981',
        confirmButtonText: 'Send Announcement',
        cancelButtonText: 'Cancel'
    });
    
    if (!confirm.isConfirmed) return;
    
    showLoader(true);
    
    $.ajax({
        url: scriptUrl + '?ajax=notify_action',
        method: 'POST',
        data: {
            action: 'send_bulk',
            target_type: targetType,
            target_value: targetValue,
            channel: channel,
            subject: subject,
            message: message,
            priority: priority,
            branch_id: branchId,
            csrf_token: csrfToken
        },
        dataType: 'json',
        success: function(response) {
            showLoader(false);
            if (response.success) {
                showToast(response.message, 'success');
                refreshStats();
                refreshLogs();
                document.getElementById('bulkMessage').value = '';
                if (channel === 'email') document.getElementById('bulkSubject').value = '';
            } else {
                showToast(response.message || 'Bulk send failed', 'error');
            }
        },
        error: function() {
            showLoader(false);
            showToast('Network error. Please try again.', 'error');
        }
    });
}

// =================================================================
// ACTION: Send Critical Alert
// =================================================================
async function sendCriticalAlert() {
    const role = document.getElementById('alertRole').value;
    const subject = document.getElementById('alertSubject').value.trim();
    const message = document.getElementById('alertMessage').value.trim();
    
    if (!message) {
        showToast('Alert message cannot be empty', 'error');
        return;
    }
    
    if (!subject) {
        showToast('Alert subject is required', 'error');
        return;
    }
    
    const roleLabel = role === 'all' ? 'All Staff' : role;
    
    const confirm = await Swal.fire({
        title: '⚠️ Send Critical Alert',
        html: `You are about to send a <strong style="color:#dc2626;">CRITICAL</strong> alert to <strong>${roleLabel}</strong>.<br><br>
               <div style="background:#fee2e2; padding:12px; border-radius:12px; font-size:13px; text-align:left; border:2px solid #dc2626;">
               <strong style="color:#dc2626;">Subject:</strong> ${escapeHtml(subject)}<br>
               <strong style="color:#dc2626;">Message:</strong><br>${escapeHtml(message)}
               </div>`,
        icon: 'error',
        showCancelButton: true,
        confirmButtonColor: '#dc2626',
        confirmButtonText: 'Send Critical Alert',
        cancelButtonText: 'Cancel'
    });
    
    if (!confirm.isConfirmed) return;
    
    showLoader(true);
    
    $.ajax({
        url: scriptUrl + '?ajax=notify_action',
        method: 'POST',
        data: {
            action: 'send_critical_alert',
            alert_type: 'critical',
            recipient_role: role,
            subject: subject,
            message: message,
            branch_id: branchId,
            csrf_token: csrfToken
        },
        dataType: 'json',
        success: function(response) {
            showLoader(false);
            if (response.success) {
                showToast(response.message, 'success');
                refreshLogs();
                document.getElementById('alertSubject').value = '';
                document.getElementById('alertMessage').value = '';
            } else {
                showToast(response.message || 'Failed to send alert', 'error');
            }
        },
        error: function() {
            showLoader(false);
            showToast('Network error. Please try again.', 'error');
        }
    });
}

// =================================================================
// ACTION: Schedule Notification
// =================================================================
function showScheduleModal() {
    $('#scheduleModal').modal('show');
    // Set default datetime to 1 hour from now
    const now = new Date();
    now.setHours(now.getHours() + 1);
    const iso = now.toISOString().slice(0, 16);
    document.getElementById('scheduleDateTime').value = iso;
}

function scheduleNotification() {
    const notificationType = document.getElementById('scheduleType').value;
    const channel = document.getElementById('scheduleChannel').value;
    const recipientType = document.getElementById('scheduleRecipientType').value;
    const recipientValue = document.getElementById('scheduleRole').value;
    const subject = document.getElementById('scheduleSubject').value.trim();
    const message = document.getElementById('scheduleMessage').value.trim();
    const scheduledAt = document.getElementById('scheduleDateTime').value;
    const recurrence = document.getElementById('scheduleRecurrence').value;
    
    if (!message) {
        showToast('Message content cannot be empty', 'error');
        return;
    }
    
    if (!scheduledAt) {
        showToast('Please select a date and time', 'error');
        return;
    }
    
    showLoader(true);
    
    $.ajax({
        url: scriptUrl + '?ajax=notify_action',
        method: 'POST',
        data: {
            action: 'schedule_notification',
            notification_type: notificationType,
            channel: channel,
            recipient_type: recipientType,
            recipient_value: recipientValue,
            subject: subject,
            message: message,
            scheduled_at: scheduledAt,
            recurrence: recurrence,
            branch_id: branchId,
            csrf_token: csrfToken
        },
        dataType: 'json',
        success: function(response) {
            showLoader(false);
            if (response.success) {
                showToast(response.message, 'success');
                $('#scheduleModal').modal('hide');
                refreshScheduled();
                refreshStats();
                document.getElementById('scheduleMessage').value = '';
                document.getElementById('scheduleSubject').value = '';
            } else {
                showToast(response.message || 'Failed to schedule', 'error');
            }
        },
        error: function() {
            showLoader(false);
            showToast('Network error. Please try again.', 'error');
        }
    });
}

// =================================================================
// REFRESH Notification Logs
// =================================================================
function refreshLogs() {
    const statusFilter = document.getElementById('statusFilter').value;
    const searchTerm = document.getElementById('logSearch').value.toLowerCase();
    
    $.ajax({
        url: scriptUrl + '?ajax=notify_action',
        method: 'POST',
        data: {
            action: 'fetch_logs',
            branch_id: branchId,
            limit: 100,
            status_filter: statusFilter,
            csrf_token: csrfToken
        },
        dataType: 'json',
        success: function(response) {
            if (response.success && response.logs) {
                const tbody = document.getElementById('logsTableBody');
                tbody.innerHTML = '';
                if (response.logs.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted py-4">No notification logs found</td></tr>';
                } else {
                    response.logs.forEach(log => {
                        // Filter by search term
                        const messageText = (log.message_body || '').toLowerCase();
                        const patientName = (log.patient_name || '').toLowerCase();
                        if (searchTerm && !messageText.includes(searchTerm) && !patientName.includes(searchTerm)) {
                            return;
                        }
                        
                        const date = new Date(log.created_at);
                        const formattedDate = `${date.getMonth()+1}/${date.getDate()} ${date.getHours().toString().padStart(2,'0')}:${date.getMinutes().toString().padStart(2,'0')}`;
                        const statusClass = log.status === 'sent' ? 'status-sent' : (log.status === 'failed' ? 'status-failed' : (log.status === 'scheduled' ? 'status-scheduled' : 'status-pending'));
                        const channelClass = log.channel === 'sms' ? 'badge-sms' : (log.channel === 'email' ? 'badge-email' : (log.channel === 'in_app' ? 'badge-inapp' : 'badge-secondary'));
                        const priorityClass = log.priority === 'critical' ? 'priority-critical' : (log.priority === 'high' ? 'priority-high' : (log.priority === 'normal' ? 'priority-normal' : 'priority-low'));
                        const typeDisplay = (log.notification_type || '').replace(/_/g, ' ');
                        const recipient = log.patient_name || (log.recipient_type === 'staff' ? 'Staff' : 'N/A');
                        
                        tbody.innerHTML += `
                            <tr>
                                <td>${escapeHtml(formattedDate)}</td>
                                <td>${escapeHtml(recipient)}</td>
                                <td><span class="badge badge-secondary">${escapeHtml(typeDisplay)}</span></td>
                                <td><span class="badge ${channelClass}">${log.channel.toUpperCase()}</span></td>
                                <td><span class="status-badge ${statusClass}">${log.status}</span></td>
                                <td><span class="priority-badge ${priorityClass}">${log.priority || 'normal'}</span></td>
                                <td class="small text-muted">${escapeHtml((log.message_body || '').substring(0, 50))}...</td>
                            </tr>
                        `;
                    });
                }
            }
        },
        error: function() {
            console.log('Failed to refresh logs');
        }
    });
}

function filterLogs() {
    refreshLogs();
}

// =================================================================
// REFRESH Stats
// =================================================================
function refreshStats() {
    $.ajax({
        url: scriptUrl + '?ajax=notify_action',
        method: 'POST',
        data: {
            action: 'fetch_counts',
            branch_id: branchId,
            csrf_token: csrfToken
        },
        dataType: 'json',
        success: function(response) {
            if (response.success && response.counts) {
                document.getElementById('statPendingReminders').textContent = response.counts.pending || 0;
                document.getElementById('statTodayReminders').textContent = response.counts.today || 0;
                document.getElementById('statTomorrowReminders').textContent = response.counts.tomorrow || 0;
                document.getElementById('statTotalToday').textContent = response.counts.total_logs_today || 0;
                document.getElementById('statFailedToday').textContent = response.counts.failed_today || 0;
                document.getElementById('statScheduled').textContent = response.counts.scheduled || 0;
                
                // Update reminder count info
                document.getElementById('reminderCountInfo').textContent = response.counts.pending + ' pending.';
            }
        },
        error: function() {
            console.log('Failed to refresh stats');
        }
    });
}

// =================================================================
// REFRESH Scheduled Notifications
// =================================================================
function refreshScheduled() {
    $.ajax({
        url: scriptUrl + '?ajax=notify_action',
        method: 'POST',
        data: {
            action: 'fetch_scheduled',
            branch_id: branchId,
            csrf_token: csrfToken
        },
        dataType: 'json',
        success: function(response) {
            const container = document.getElementById('scheduledContainer');
            if (response.success && response.scheduled && response.scheduled.length > 0) {
                container.innerHTML = '';
                response.scheduled.forEach(item => {
                    const date = new Date(item.scheduled_at);
                    const formattedDate = `${date.toLocaleDateString()} ${date.toLocaleTimeString([], {hour:'2-digit', minute:'2-digit'})}`;
                    container.innerHTML += `
                        <div class="scheduled-item">
                            <div>
                                <strong>${escapeHtml(item.notification_type.replace(/_/g, ' '))}</strong>
                                <span class="badge ${item.channel === 'sms' ? 'badge-sms' : (item.channel === 'email' ? 'badge-email' : 'badge-inapp')}">${item.channel.toUpperCase()}</span>
                                <span class="text-muted small">to ${escapeHtml(item.recipient_type)}</span>
                            </div>
                            <div>
                                <span class="time"><i class="far fa-clock me-1"></i>${formattedDate}</span>
                                <span class="badge status-pending ms-2">${item.status}</span>
                            </div>
                        </div>
                    `;
                });
            } else {
                container.innerHTML = '<div class="text-center text-muted py-4 small"><i class="fas fa-clock me-1"></i> No scheduled notifications</div>';
            }
        },
        error: function() {
            console.log('Failed to refresh scheduled');
        }
    });
}

// =================================================================
// REFRESH Notification Rules
// =================================================================
function refreshRules() {
    $.ajax({
        url: scriptUrl + '?ajax=notify_action',
        method: 'POST',
        data: {
            action: 'fetch_rules',
            csrf_token: csrfToken
        },
        dataType: 'json',
        success: function(response) {
            if (response.success && response.rules) {
                const container = document.getElementById('rulesContainer');
                container.innerHTML = '';
                response.rules.forEach(rule => {
                    container.innerHTML += `
                        <div class="rule-item">
                            <span class="event">${escapeHtml(rule.event_trigger.replace(/_/g, ' '))}</span>
                            <span style="font-size:10px; color:var(--gray);">${escapeHtml(rule.recipient_role)}</span>
                            <span>
                                <label class="toggle-switch">
                                    <input type="checkbox" ${rule.is_email_enabled ? 'checked' : ''} 
                                           onchange="updateRule(${rule.id}, 'email', this.checked)">
                                    <span class="toggle-slider"></span>
                                </label>
                            </span>
                        </div>
                    `;
                });
            }
        },
        error: function() {
            console.log('Failed to refresh rules');
        }
    });
}

// =================================================================
// UPDATE Notification Rule
// =================================================================
function updateRule(ruleId, type, enabled) {
    // This would typically send an update to the server
    // For now, we just show a toast
    showToast(`Rule ${ruleId} ${type} ${enabled ? 'enabled' : 'disabled'}`, 'info');
}

// =================================================================
// ESCAPE HTML
// =================================================================
function escapeHtml(str) {
    if (!str) return '';
    return String(str).replace(/[&<>]/g, function(m) {
        if (m === '&') return '&amp;';
        if (m === '<') return '&lt;';
        if (m === '>') return '&gt;';
        return m;
    });
}

// =================================================================
// SCHEDULE RECIPIENT TYPE CHANGE
// =================================================================
document.addEventListener('DOMContentLoaded', function() {
    // Setup target value change
    document.getElementById('targetType').addEventListener('change', updateTargetValueOptions);
    
    // Setup schedule recipient type change
    document.getElementById('scheduleRecipientType').addEventListener('change', function() {
        const showRole = this.value === 'staff_role';
        document.getElementById('scheduleRoleGroup').style.display = showRole ? 'block' : 'none';
    });
    
    // Token pill clicks
    document.querySelectorAll('.token-pill').forEach(pill => {
        pill.addEventListener('click', function() {
            insertToken(this.dataset.token);
        });
    });
    
    // Initial setup
    updateTargetValueOptions();
    
    // Auto-refresh every 30 seconds
    setInterval(() => {
        refreshStats();
        refreshLogs();
    }, 30000);
    
    // Refresh scheduled every minute
    setInterval(refreshScheduled, 60000);
});
</script>

</body>
</html>