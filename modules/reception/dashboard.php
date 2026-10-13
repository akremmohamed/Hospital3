<?php
/**
 * Reception Dashboard - HMS ERP System
 * Version: 15.2 - Production Ready
 * SECURITY: Complete input validation, CSRF protection, XSS prevention
 * FEATURES: Default OPD department, Dynamic card fee based on visit frequency
 * FIXED: DataTable loading, getDB() fallback, session security
 * FIXED: Pending Card Fee Invoices DataTable
 * 
 * @package HMS-ERP
 * @subpackage Reception
 * @version 15.2
 */

// =============================================================================
// SESSION SECURITY - MUST BE FIRST
// =============================================================================
if (session_status() === PHP_SESSION_NONE) {
    // Secure session configuration
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_only_cookies', 1);
    ini_set('session.cookie_samesite', 'Strict');
    // Only enable secure flag if using HTTPS
    if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
        ini_set('session.cookie_secure', 1);
    }
    session_start();
}

// =============================================================================
// REQUIRE DEPENDENCIES
// =============================================================================
require_once __DIR__ . '/../../config/db_connection.php';
require_once __DIR__ . '/../../includes/auth_helper.php';

// =============================================================================
// FALLBACK FUNCTIONS - In case they're not defined in required files
// =============================================================================
if (!function_exists('getDB')) {
    function getDB() {
        global $pdo;
        if (isset($pdo) && $pdo instanceof PDO) {
            return $pdo;
        }
        // Try to create connection from db_connection.php
        try {
            require_once __DIR__ . '/../../config/db_connection.php';
            global $pdo;
            return $pdo;
        } catch (Exception $e) {
            return null;
        }
    }
}

if (!function_exists('is_db_available')) {
    function is_db_available() {
        try {
            $db = getDB();
            return $db instanceof PDO;
        } catch (Exception $e) {
            return false;
        }
    }
}

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
// INPUT VALIDATION FUNCTIONS
// =============================================================================
function validateEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL);
}

function validatePhone($phone) {
    // Ethiopian phone number format: 09XXXXXXXX or +2519XXXXXXXX
    return preg_match('/^(09|2519|\+2519)\d{8}$/', $phone);
}

function validateNationalId($id) {
    // Ethiopian national ID: 7-8 digits
    return preg_match('/^\d{7,8}$/', $id);
}

function validateAge($age) {
    $age = (int)$age;
    return $age >= 0 && $age <= 150;
}

function validateDate($date) {
    $d = DateTime::createFromFormat('Y-m-d', $date);
    return $d && $d->format('Y-m-d') === $date;
}

function isCardFeeMandatory($patient_id, $db) {
    // Check if patient visited in last 2 months
    $stmt = $db->prepare("
        SELECT MAX(visit_date) as last_visit 
        FROM patient_visits 
        WHERE patient_id = :pid 
        AND deleted_at IS NULL
        AND status != 'cancelled'
    ");
    $stmt->execute([':pid' => $patient_id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$result || !$result['last_visit']) {
        return true; // First visit - fee mandatory
    }
    
    $last_visit = new DateTime($result['last_visit']);
    $now = new DateTime();
    $diff = $now->diff($last_visit);
    $months = ($diff->y * 12) + $diff->m;
    
    return $months >= 2; // Fee mandatory if > 2 months
}

function sanitizeOutput($str) {
    return htmlspecialchars($str, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

// =============================================================================
// UNIFIED AJAX HANDLER
// =============================================================================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'dashboard_action') {
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
        echo json_encode(['success' => false, 'message' => 'Security validation failed. Please refresh the page.']);
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
    // GET DEPARTMENTS
    // -------------------------------------------------------------------------
    if ($action === 'get_departments') {
        $sql = "SELECT id, name FROM departments WHERE is_active = 1 AND deleted_at IS NULL";
        $params = [];
        
        if (!$is_global_admin && $request_branch > 0) {
            $sql .= " AND branch_id = :branch_id";
            $params[':branch_id'] = $request_branch;
        }
        
        $sql .= " ORDER BY name";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        
        echo json_encode(['success' => true, 'departments' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    // -------------------------------------------------------------------------
    // GET DOCTORS BY DEPARTMENT
    // -------------------------------------------------------------------------
    if ($action === 'get_doctors_by_department') {
        $dept_id = (int)($_POST['department_id'] ?? 0);
        
        $sql = "SELECT u.id,
                       CONCAT(COALESCE(u.first_name,''), ' ', COALESCE(u.middle_name,''), ' ', COALESCE(u.last_name,'')) AS name,
                       COALESCE(sp.specialization, 'General Medicine') AS specialist
                FROM users u
                LEFT JOIN staff_profiles sp ON u.id = sp.user_id
                WHERE u.user_type = 'doctor' AND u.is_active = 1 AND u.deleted_at IS NULL";
        
        $params = [];
        
        if (!$is_global_admin && $request_branch > 0) {
            $sql .= " AND u.branch_id = :branch_id";
            $params[':branch_id'] = $request_branch;
        }
        
        if ($dept_id > 0) {
            $sql .= " AND u.department_id = :department_id";
            $params[':department_id'] = $dept_id;
        }
        
        $sql .= " ORDER BY name";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        
        echo json_encode(['success' => true, 'doctors' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    // -------------------------------------------------------------------------
    // CHECK CARD FEE MANDATORY
    // -------------------------------------------------------------------------
    if ($action === 'check_card_fee_mandatory') {
        $patient_id = (int)($_POST['patient_id'] ?? 0);
        
        if ($patient_id === 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid patient ID']);
            exit;
        }
        
        $is_mandatory = isCardFeeMandatory($patient_id, $db);
        echo json_encode([
            'success' => true,
            'is_mandatory' => $is_mandatory,
            'message' => $is_mandatory ? 'Card fee required (last visit > 2 months ago)' : 'Card fee optional (visited within 2 months)'
        ]);
        exit;
    }

    // -------------------------------------------------------------------------
    // COMPREHENSIVE DASHBOARD DATA
    // -------------------------------------------------------------------------
    if ($action === 'dashboard_data') {
        try {
            $data = [];
            
            // 1. KPIs
            $reg_stmt = $db->prepare("SELECT COUNT(*) FROM patients WHERE DATE(created_at) = CURDATE() AND deleted_at IS NULL AND branch_id = :b");
            $reg_stmt->execute([':b' => $request_branch]);
            $data['registrations_today'] = (int)$reg_stmt->fetchColumn();

            // Regular patients awaiting card fee (non-emergency)
            $pending_stmt = $db->prepare("
                SELECT COUNT(DISTINCT i.id) 
                FROM invoices i
                INNER JOIN patient_visits pv ON pv.id = i.visit_id
                WHERE i.payment_status = 'unpaid' 
                  AND i.deleted_at IS NULL 
                  AND i.branch_id = :b
                  AND pv.visit_type != 'emergency'
            ");
            $pending_stmt->execute([':b' => $request_branch]);
            $data['awaiting_card_fee'] = (int)$pending_stmt->fetchColumn();

            // Emergency patients with deferred card fee
            $emergency_pending_stmt = $db->prepare("
                SELECT COUNT(DISTINCT i.id) 
                FROM invoices i
                INNER JOIN patient_visits pv ON pv.id = i.visit_id
                WHERE i.payment_status = 'unpaid' 
                  AND i.deleted_at IS NULL 
                  AND i.branch_id = :b
                  AND pv.visit_type = 'emergency'
            ");
            $emergency_pending_stmt->execute([':b' => $request_branch]);
            $data['emergency_pending_card_fee'] = (int)$emergency_pending_stmt->fetchColumn();

            $em_stmt = $db->prepare("SELECT COUNT(*) FROM patient_visits 
                                    WHERE visit_type = 'emergency' 
                                    AND DATE(created_at) = CURDATE() 
                                    AND branch_id = :b 
                                    AND deleted_at IS NULL");
            $em_stmt->execute([':b' => $request_branch]);
            $data['emergency_today'] = (int)$em_stmt->fetchColumn();

            $active_stmt = $db->prepare("SELECT COUNT(*) FROM patient_visits 
                                        WHERE status = 'in_consultation' 
                                        AND branch_id = :b 
                                        AND deleted_at IS NULL");
            $active_stmt->execute([':b' => $request_branch]);
            $data['in_consultation'] = (int)$active_stmt->fetchColumn();

            // 2. Active Admissions
            $admit_stmt = $db->prepare("SELECT COUNT(*) FROM admissions 
                                        WHERE status = 'admitted' 
                                        AND branch_id = :b 
                                        AND deleted_at IS NULL");
            $admit_stmt->execute([':b' => $request_branch]);
            $data['active_admissions'] = (int)$admit_stmt->fetchColumn();

            // 3. Today's Appointments
            $appt_stmt = $db->prepare("
                SELECT a.id, a.appointment_number, a.appointment_time, a.status, a.visit_type, a.priority,
                       CONCAT(p.first_name, ' ', COALESCE(p.middle_name, ''), ' ', p.last_name) AS patient_name,
                       p.mr_number, p.phone,
                       d.name AS department_name,
                       CONCAT('Dr. ', u.first_name, ' ', u.last_name) AS doctor_name,
                       i.payment_status AS card_fee_status,
                       TIMESTAMPDIFF(MINUTE, pv.created_at, NOW()) AS waiting_minutes
                FROM appointments a
                INNER JOIN patients p ON a.patient_id = p.id
                LEFT JOIN departments d ON a.department_id = d.id
                LEFT JOIN users u ON a.doctor_id = u.id
                LEFT JOIN patient_visits pv ON pv.id = a.visit_id
                LEFT JOIN invoices i ON i.visit_id = pv.id AND i.deleted_at IS NULL
                WHERE a.appointment_date = CURDATE() 
                  AND a.branch_id = :b 
                  AND a.deleted_at IS NULL
                ORDER BY a.appointment_time ASC
                LIMIT 50
            ");
            $appt_stmt->execute([':b' => $request_branch]);
            $data['today_appointments'] = $appt_stmt->fetchAll(PDO::FETCH_ASSOC);

            // 4. Walk-in vs Scheduled
            $walkin_stmt = $db->prepare("
                SELECT 
                    SUM(CASE WHEN is_emergency_walk_in = 1 THEN 1 ELSE 0 END) AS walk_in,
                    SUM(CASE WHEN is_emergency_walk_in = 0 AND visit_type != 'emergency' THEN 1 ELSE 0 END) AS scheduled
                FROM appointments 
                WHERE appointment_date = CURDATE() 
                  AND branch_id = :b 
                  AND deleted_at IS NULL
            ");
            $walkin_stmt->execute([':b' => $request_branch]);
            $data['walkin_stats'] = $walkin_stmt->fetch(PDO::FETCH_ASSOC);

            // 5. Department-wise Patient Distribution
            $dept_stmt = $db->prepare("
                SELECT d.name AS department_name, COUNT(a.id) AS patient_count
                FROM appointments a
                INNER JOIN departments d ON a.department_id = d.id
                WHERE a.appointment_date = CURDATE() 
                  AND a.branch_id = :b 
                  AND a.deleted_at IS NULL
                GROUP BY d.id, d.name
                ORDER BY patient_count DESC
            ");
            $dept_stmt->execute([':b' => $request_branch]);
            $data['department_distribution'] = $dept_stmt->fetchAll(PDO::FETCH_ASSOC);

            // 6. Insurance Claim Status
            $ins_stmt = $db->prepare("
                SELECT 
                    SUM(CASE WHEN insurance_claim_status = 'pending_authorization' THEN 1 ELSE 0 END) AS pending_auth,
                    SUM(CASE WHEN insurance_claim_status = 'authorized' THEN 1 ELSE 0 END) AS authorized,
                    SUM(CASE WHEN insurance_claim_status = 'denied' THEN 1 ELSE 0 END) AS denied,
                    SUM(CASE WHEN insurance_claim_status = 'not_applicable' THEN 1 ELSE 0 END) AS not_applicable
                FROM patient_visits 
                WHERE DATE(created_at) = CURDATE() 
                  AND branch_id = :b 
                  AND deleted_at IS NULL
            ");
            $ins_stmt->execute([':b' => $request_branch]);
            $data['insurance_status'] = $ins_stmt->fetch(PDO::FETCH_ASSOC);

            // 7. Last 7 Days Registrations
            $trend_stmt = $db->prepare("
                SELECT DATE(created_at) AS date, COUNT(*) AS count
                FROM patients 
                WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
                  AND branch_id = :b 
                  AND deleted_at IS NULL
                GROUP BY DATE(created_at)
                ORDER BY date ASC
            ");
            $trend_stmt->execute([':b' => $request_branch]);
            $data['registration_trend'] = $trend_stmt->fetchAll(PDO::FETCH_ASSOC);

            // 8. Recent Activity (Last 5 Patient Registrations)
            $recent_stmt = $db->prepare("
                SELECT p.id, p.first_name, p.last_name, p.mr_number, p.created_at,
                       CONCAT(u.first_name, ' ', u.last_name) AS registered_by
                FROM patients p
                LEFT JOIN users u ON p.created_by_user_id = u.id
                WHERE p.branch_id = :b AND p.deleted_at IS NULL
                ORDER BY p.created_at DESC
                LIMIT 5
            ");
            $recent_stmt->execute([':b' => $request_branch]);
            $data['recent_activity'] = $recent_stmt->fetchAll(PDO::FETCH_ASSOC);

            // 9. Nurse Triage Queue
            $nurse_stmt = $db->prepare("
                SELECT pv.id AS visit_id, 
                       pv.visit_number,
                       p.id AS patient_id,
                       CONCAT(COALESCE(p.first_name,''), ' ', COALESCE(p.middle_name,''), ' ', COALESCE(p.last_name,'')) AS patient_name,
                       p.mr_number,
                       p.phone,
                       COALESCE(d.name, 'General') AS department_name,
                       pv.created_at,
                       pv.status,
                       a.doctor_id,
                       pv.visit_type,
                       pv.is_priority
                FROM patient_visits pv
                INNER JOIN patients p ON p.id = pv.patient_id
                INNER JOIN invoices i ON pv.id = i.visit_id
                LEFT JOIN appointments a ON a.visit_id = pv.id AND a.deleted_at IS NULL
                LEFT JOIN departments d ON d.id = a.department_id
                LEFT JOIN triage_records tr ON pv.id = tr.visit_id AND tr.deleted_at IS NULL
                WHERE pv.status = 'checked_in' 
                  AND i.payment_status = 'paid' 
                  AND pv.branch_id = :b
                  AND pv.deleted_at IS NULL
                  AND i.deleted_at IS NULL
                  AND pv.visit_type != 'emergency'
                  AND (tr.id IS NULL OR tr.blood_pressure_systolic IS NULL OR tr.blood_pressure_systolic = 0)
                ORDER BY 
                  CASE WHEN pv.is_priority = 1 THEN 1 ELSE 2 END,
                  pv.created_at ASC
                LIMIT 50
            ");
            $nurse_stmt->execute([':b' => $request_branch]);
            $data['nurse_queue'] = $nurse_stmt->fetchAll(PDO::FETCH_ASSOC);

            // 10. Doctor Consultation Queue
            $doctor_stmt = $db->prepare("
                SELECT pv.id AS visit_id,
                       pv.visit_number,
                       p.id AS patient_id,
                       CONCAT(COALESCE(p.first_name,''), ' ', COALESCE(p.middle_name,''), ' ', COALESCE(p.last_name,'')) AS patient_name,
                       p.mr_number,
                       p.phone,
                       COALESCE(d.name, 'General') AS department_name,
                       CASE 
                           WHEN pv.is_priority = 1 THEN 'stat'
                           WHEN a.priority = 'urgent' THEN 'urgent'
                           WHEN a.priority = 'stat' THEN 'stat'
                           ELSE 'normal'
                       END AS priority,
                       pv.created_at,
                       pv.status,
                       CONCAT('Dr. ', COALESCE(doc.first_name,''), ' ', COALESCE(doc.last_name,'')) AS doctor_name,
                       pv.visit_type,
                       pv.is_priority
                FROM patient_visits pv
                INNER JOIN patients p ON p.id = pv.patient_id
                INNER JOIN invoices i ON pv.id = i.visit_id
                LEFT JOIN appointments a ON a.visit_id = pv.id AND a.deleted_at IS NULL
                LEFT JOIN departments d ON d.id = a.department_id
                LEFT JOIN users doc ON doc.id = a.doctor_id
                LEFT JOIN triage_records tr ON pv.id = tr.visit_id AND tr.deleted_at IS NULL
                WHERE pv.status = 'checked_in' 
                  AND i.payment_status = 'paid' 
                  AND pv.branch_id = :b
                  AND pv.deleted_at IS NULL
                  AND i.deleted_at IS NULL
                  AND pv.visit_type != 'emergency'
                  AND (tr.id IS NOT NULL AND tr.blood_pressure_systolic IS NOT NULL AND tr.blood_pressure_systolic > 0)
                ORDER BY 
                  pv.is_priority DESC,
                  CASE WHEN a.priority = 'stat' THEN 1 WHEN a.priority = 'urgent' THEN 2 ELSE 3 END,
                  pv.created_at ASC
                LIMIT 50
            ");
            $doctor_stmt->execute([':b' => $request_branch]);
            $data['doctor_queue'] = $doctor_stmt->fetchAll(PDO::FETCH_ASSOC);

            // 11. Emergency Queue
            $emergency_queue_stmt = $db->prepare("
                SELECT pv.id AS visit_id,
                       pv.visit_number,
                       p.id AS patient_id,
                       CONCAT(COALESCE(p.first_name,''), ' ', COALESCE(p.middle_name,''), ' ', COALESCE(p.last_name,'')) AS patient_name,
                       p.mr_number,
                       p.phone,
                       COALESCE(d.name, 'Emergency') AS department_name,
                       pv.created_at,
                       pv.status,
                       a.priority AS appointment_priority,
                       TIMESTAMPDIFF(MINUTE, pv.created_at, NOW()) AS waiting_minutes,
                       COALESCE(i.id, 0) AS invoice_id,
                       i.payment_status AS card_fee_status,
                       i.total_amount AS card_fee_amount,
                       pv.is_priority,
                       COALESCE(a.doctor_id, 0) AS doctor_id,
                       CONCAT('Dr. ', COALESCE(doc.first_name,''), ' ', COALESCE(doc.last_name,'')) AS doctor_name
                FROM patient_visits pv
                INNER JOIN patients p ON p.id = pv.patient_id
                LEFT JOIN invoices i ON pv.id = i.visit_id AND i.deleted_at IS NULL
                LEFT JOIN appointments a ON a.visit_id = pv.id AND a.deleted_at IS NULL
                LEFT JOIN departments d ON d.id = a.department_id
                LEFT JOIN users doc ON doc.id = a.doctor_id
                WHERE pv.visit_type = 'emergency'
                  AND pv.branch_id = :b
                  AND pv.deleted_at IS NULL
                  AND pv.status IN ('checked_in', 'in_triage', 'in_consultation')
                ORDER BY 
                  CASE WHEN i.payment_status = 'unpaid' THEN 1 ELSE 2 END,
                  pv.is_priority DESC,
                  pv.created_at ASC
                LIMIT 50
            ");
            $emergency_queue_stmt->execute([':b' => $request_branch]);
            $data['emergency_queue'] = $emergency_queue_stmt->fetchAll(PDO::FETCH_ASSOC);

            // 12. Doctor Availability
            $doc_avail_stmt = $db->prepare("
                SELECT u.id,
                       CONCAT(COALESCE(u.first_name,''), ' ', COALESCE(u.middle_name,''), ' ', COALESCE(u.last_name,'')) AS doctor_name,
                       COALESCE(sp.specialization, 'General Medicine') AS specialist,
                       (SELECT COUNT(DISTINCT a.visit_id) 
                        FROM appointments a
                        INNER JOIN patient_visits pv ON pv.id = a.visit_id
                        WHERE a.doctor_id = u.id 
                          AND a.appointment_date = CURDATE()
                          AND a.status IN ('scheduled', 'checked_in', 'in_progress')
                          AND a.deleted_at IS NULL) AS todays_patients
                FROM users u
                LEFT JOIN staff_profiles sp ON u.id = sp.user_id
                WHERE u.user_type = 'doctor' AND u.is_active = 1 AND u.deleted_at IS NULL
                  AND u.branch_id = :b
                ORDER BY doctor_name
            ");
            $doc_avail_stmt->execute([':b' => $request_branch]);
            $data['doctor_availability'] = $doc_avail_stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['success' => true, 'data' => $data]);
            
        } catch (PDOException $e) {
            error_log("Dashboard data error: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Database error occurred']);
        }
        exit;
    }

    // -------------------------------------------------------------------------
    // SEARCH PATIENTS
    // -------------------------------------------------------------------------
    if ($action === 'search_patients') {
        $query = trim($_POST['query'] ?? '');
        
        if (strlen($query) < 2) {
            echo json_encode(['success' => true, 'patients' => []]);
            exit;
        }
        
        $like = "%{$query}%";
        
        $sql = "SELECT id,
                       CONCAT(COALESCE(first_name,''), ' ', COALESCE(middle_name,''), ' ', COALESCE(last_name,'')) AS name,
                       mr_number, 
                       phone, 
                       email, 
                       gender, 
                       date_of_birth AS birth_date, 
                       address, 
                       is_active
                FROM patients
                WHERE deleted_at IS NULL 
                  AND is_active = 1 
                  AND branch_id = :b
                  AND (first_name LIKE :q1 
                       OR last_name LIKE :q2 
                       OR mr_number LIKE :q3 
                       OR phone LIKE :q4
                       OR CONCAT(first_name, ' ', last_name) LIKE :q5)
                LIMIT 30";
        
        $stmt = $db->prepare($sql);
        $stmt->execute([
            ':b' => $request_branch,
            ':q1' => $like, 
            ':q2' => $like, 
            ':q3' => $like, 
            ':q4' => $like,
            ':q5' => $like
        ]);
        
        $patients = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($patients as &$p) {
            $p['id'] = (int)$p['id'];
            if (!empty($p['birth_date'])) {
                $birthDate = new DateTime($p['birth_date']);
                $today = new DateTime();
                $age = $birthDate->diff($today);
                $p['age'] = $age->y;
            } else {
                $p['age'] = null;
            }
            // Check if card fee is mandatory
            $p['card_fee_mandatory'] = isCardFeeMandatory($p['id'], $db);
        }
        
        echo json_encode(['success' => true, 'patients' => $patients]);
        exit;
    }

    // -------------------------------------------------------------------------
    // REGISTER NEW PATIENT - With OPD default and dynamic card fee
    // -------------------------------------------------------------------------
    if ($action === 'register_patient') {
        // Validate all inputs
        $first_name   = trim($_POST['first_name'] ?? '');
        $last_name    = trim($_POST['last_name'] ?? '');
        $middle_name  = trim($_POST['middle_name'] ?? '');
        $email        = trim($_POST['email'] ?? '');
        $phone        = trim($_POST['phone'] ?? '');
        $birth_date   = !empty($_POST['birth_date']) ? $_POST['birth_date'] : null;
        $age          = !empty($_POST['age']) ? (int)$_POST['age'] : null;
        $gender       = !empty($_POST['gender']) ? $_POST['gender'] : null;
        $blood_group  = !empty($_POST['blood_group']) ? $_POST['blood_group'] : 'unknown';
        $address      = trim($_POST['address'] ?? '');
        $national_id  = trim($_POST['national_id'] ?? '');
        $visit_type   = strtolower($_POST['visit_type'] ?? 'regular');
        
        // Default to OPD department (ID 1)
        $dept_id      = (int)($_POST['department_id'] ?? 1);
        $doctor_id    = (int)($_POST['doctor_id'] ?? 0);
        $routing      = $_POST['routing'] ?? 'triage';
        
        // Card fee logic: Check if mandatory
        $card_fee_collected = isset($_POST['card_fee_collected']) && $_POST['card_fee_collected'] == '1';
        $card_fee_mandatory = true; // Default to mandatory for new patients
        
        $emergency_priority = $_POST['emergency_priority'] ?? 'yellow';

        // Validate email
        if (!empty($email) && !validateEmail($email)) {
            echo json_encode(['success' => false, 'message' => 'Invalid email format']);
            exit;
        }

        // Validate phone
        if (!empty($phone) && !validatePhone($phone)) {
            echo json_encode(['success' => false, 'message' => 'Invalid phone number format. Use 09XXXXXXXX or +2519XXXXXXXX']);
            exit;
        }

        // Validate national ID
        if (!empty($national_id) && !validateNationalId($national_id)) {
            echo json_encode(['success' => false, 'message' => 'Invalid National ID format. Must be 7-8 digits']);
            exit;
        }

        // Validate age
        if ($age !== null && !validateAge($age)) {
            echo json_encode(['success' => false, 'message' => 'Invalid age. Must be between 0 and 150']);
            exit;
        }

        // Validate birth date
        if ($birth_date && !validateDate($birth_date)) {
            echo json_encode(['success' => false, 'message' => 'Invalid birth date format']);
            exit;
        }

        // Validate department exists
        $dept_check = $db->prepare("SELECT id FROM departments WHERE id = :dept_id AND is_active = 1 AND deleted_at IS NULL");
        $dept_check->execute([':dept_id' => $dept_id]);
        if (!$dept_check->fetch()) {
            $dept_id = 1; // Fallback to OPD
        }

        if ($age && !$birth_date) {
            $today = new DateTime();
            $calculated = clone $today;
            $calculated->modify("-$age years");
            // Ensure the calculated date is not in the future
            if ($calculated > $today) {
                echo json_encode(['success' => false, 'message' => 'Invalid age calculation']);
                exit;
            }
            $birth_date = $calculated->format('Y-m-d');
        }

        if ($birth_date && !$age) {
            $birthDateObj = new DateTime($birth_date);
            $today = new DateTime();
            $age = $birthDateObj->diff($today)->y;
        }

        if (empty($first_name) || empty($last_name)) {
            echo json_encode(['success' => false, 'message' => 'First name and last name are required']);
            exit;
        }
        
        // Ensure OPD department if none selected
        if ($dept_id === 0) {
            $dept_id = 1; // Default to OPD
        }

        // Card fee validation based on visit frequency (for existing patients)
        // For new patients, fee is mandatory
        if ($visit_type !== 'emergency' && !$card_fee_collected) {
            echo json_encode(['success' => false, 'message' => 'Card fee is required for new patients']);
            exit;
        }

        $db->beginTransaction();
        
        try {
            $mr_number = 'MRN-' . date('Ymd') . '-' . str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
            
            $stmt = $db->prepare("INSERT INTO patients
                (branch_id, mr_number, first_name, last_name, middle_name, email, phone, address,
                date_of_birth, gender, blood_group, is_active, created_by_user_id, 
                is_free_treatment_eligible, is_consent_given, national_id, created_at, updated_at)
                VALUES (:b, :mr, :fn, :ln, :mn, :em, :ph, :ad, :bd, :gen, :bg, 1, :uid, 
                        0, 1, :nid, NOW(), NOW())");
            
            $stmt->execute([
                ':b' => $request_branch, 
                ':mr' => $mr_number,
                ':fn' => $first_name, 
                ':ln' => $last_name,
                ':mn' => $middle_name ?: null, 
                ':em' => $email ?: null,
                ':ph' => $phone ?: null, 
                ':ad' => $address ?: null,
                ':bd' => $birth_date, 
                ':gen' => $gender,
                ':bg' => $blood_group,
                ':nid' => $national_id ?: null,
                ':uid' => $user_id
            ]);
            
            $new_patient_id = (int)$db->lastInsertId();

            $visit_number = 'VIS-' . date('Ymd') . '-' . str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
            $now_time = date('H:i:s');
            
            $visit_status = ($card_fee_collected || $visit_type === 'emergency') ? 'checked_in' : 'pending';
            $card_fee_status = ($card_fee_collected || $visit_type === 'emergency') ? 'paid' : 'pending';
            
            $vstmt = $db->prepare("INSERT INTO patient_visits
                (branch_id, patient_id, visit_number, visit_date, visit_time, visit_type, card_fee_status, status, billing_status, is_priority, created_by_user_id, created_at, updated_at)
                VALUES (:b, :pid, :vn, CURDATE(), :vt, :vty, :cfs, :vst, 'unpaid', :prio, :uid, NOW(), NOW())");
            
            $vstmt->execute([
                ':b' => $request_branch, 
                ':pid' => $new_patient_id,
                ':vn' => $visit_number,
                ':vt' => $now_time,
                ':vty' => $visit_type,
                ':cfs' => $card_fee_status,
                ':vst' => $visit_status,
                ':prio' => ($visit_type === 'emergency') ? 1 : 0,
                ':uid' => $user_id
            ]);
            
            $new_visit_id = (int)$db->lastInsertId();

            $timestamp = time();
            $routing_token = ($routing === 'triage') ? "TRIAGE" : "DOCTOR";
            $invoice_number = "INV-REG-{$routing_token}-DEPT{$dept_id}-{$timestamp}";
            
            $billing_type = ($visit_type === 'emergency' && !$card_fee_collected) ? 'deferred' : 'cash';
            
            $invStmt = $db->prepare("INSERT INTO invoices
                (branch_id, patient_id, visit_id, invoice_number, invoice_date, subtotal, discount_amount, tax_amount, total_amount, paid_amount, payment_status, billing_type, co_pay_percentage, co_pay_amount, created_by_user_id, created_at, updated_at)
                VALUES (:b, :pid, :vid, :invno, NOW(), 50.00, 0.00, 0.00, 50.00, 0.00, :pstatus, :btype, 0, 0.00, :uid, NOW(), NOW())");
            
            $invStmt->execute([
                ':b' => $request_branch, 
                ':pid' => $new_patient_id,
                ':vid' => $new_visit_id,
                ':invno' => $invoice_number,
                ':pstatus' => ($card_fee_collected) ? 'paid' : 'unpaid',
                ':btype' => $billing_type,
                ':uid' => $user_id
            ]);
            
            $new_invoice_id = (int)$db->lastInsertId();
            
            $appointment_number = 'APT-' . date('Ymd') . '-' . str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
            $appt_status = ($card_fee_collected || $visit_type === 'emergency') ? 'checked_in' : 'scheduled';
            $billing_status = ($card_fee_collected) ? 'paid' : 'unpaid';
            $appt_priority = ($visit_type === 'emergency') ? 'stat' : 'normal';
            
            $astmt = $db->prepare("INSERT INTO appointments
                (appointment_number, branch_id, patient_id, doctor_id, department_id, visit_id, appointment_date, appointment_time, status, is_emergency_walk_in, billing_status, priority, visit_type, created_by_user_id, created_at, updated_at)
                VALUES (:an, :b, :pid, :did, :dept, :vid, CURDATE(), :at, :st, :ew, :bs, :pr, :vty, :uid, NOW(), NOW())");
            
            $astmt->execute([
                ':an' => $appointment_number, 
                ':b' => $request_branch,
                ':pid' => $new_patient_id, 
                ':did' => ($doctor_id > 0 ? $doctor_id : null),
                ':dept' => $dept_id,
                ':vid' => $new_visit_id,
                ':at' => $now_time,
                ':st' => $appt_status, 
                ':ew' => ($visit_type === 'emergency') ? 1 : 0,
                ':bs' => $billing_status,
                ':pr' => $appt_priority,
                ':vty' => $visit_type,
                ':uid' => $user_id
            ]);

            // Create billing item
            $bstmt = $db->prepare("INSERT INTO billing_items
                (branch_id, patient_id, visit_id, item_type, description, amount, status, created_at, updated_at)
                VALUES (:b, :pid, :vid, 'card_fee', 'Registration Card Fee', 50.00, :status, NOW(), NOW())");
            
            $bstmt->execute([
                ':b' => $request_branch, 
                ':pid' => $new_patient_id,
                ':vid' => $new_visit_id,
                ':status' => $card_fee_collected ? 'paid' : 'pending'
            ]);

            if ($card_fee_collected) {
                $receipt_number = 'RCP-' . date('Ymd') . '-' . str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
                
                $payStmt = $db->prepare("INSERT INTO payments
                    (branch_id, invoice_id, patient_id, visit_id, receipt_number, payment_method, amount_paid, received_by_user_id, is_verified, created_at, updated_at)
                    VALUES (:b, :inv_id, :pid, :vid, :rcpt, 'cash', 50.00, :uid, 1, NOW(), NOW())");
                
                $payStmt->execute([
                    ':b' => $request_branch, 
                    ':inv_id' => $new_invoice_id,
                    ':pid' => $new_patient_id, 
                    ':vid' => $new_visit_id,
                    ':rcpt' => $receipt_number,
                    ':uid' => $user_id
                ]);
                
                $db->prepare("UPDATE invoices SET paid_amount = 50.00, payment_status = 'paid' WHERE id = :iid")
                ->execute([':iid' => $new_invoice_id]);
            }

            // Insert triage record if routed to triage
            if ($routing === 'triage' && ($card_fee_collected || $visit_type === 'emergency')) {
                $triage_priority = ($visit_type === 'emergency') ? $emergency_priority : 'yellow';
                
                $apptCheck = $db->prepare("SELECT id FROM appointments WHERE visit_id = :vid AND deleted_at IS NULL ORDER BY id LIMIT 1");
                $apptCheck->execute([':vid' => $new_visit_id]);
                $apptResult = $apptCheck->fetch(PDO::FETCH_ASSOC);
                
                if ($apptResult && !empty($apptResult['id']) && (int)$apptResult['id'] > 0) {
                    $tstmt = $db->prepare("INSERT INTO triage_records
                        (branch_id, patient_id, visit_id, taken_by_user_id, priority, chief_complaint, triage_time, appointment_id, created_at, updated_at)
                        VALUES (:b, :pid, :vid, :uid, :prio, 'Awaiting triage assessment', NOW(), :appt_id, NOW(), NOW())");
                    
                    $tstmt->execute([
                        ':b' => $request_branch, 
                        ':pid' => $new_patient_id,
                        ':vid' => $new_visit_id, 
                        ':uid' => $user_id,
                        ':prio' => $triage_priority,
                        ':appt_id' => (int)$apptResult['id']
                    ]);
                } else {
                    $tstmt = $db->prepare("INSERT INTO triage_records
                        (branch_id, patient_id, visit_id, taken_by_user_id, priority, chief_complaint, triage_time, created_at, updated_at)
                        VALUES (:b, :pid, :vid, :uid, :prio, 'Awaiting triage assessment', NOW(), NOW(), NOW())");
                    
                    $tstmt->execute([
                        ':b' => $request_branch, 
                        ':pid' => $new_patient_id,
                        ':vid' => $new_visit_id, 
                        ':uid' => $user_id,
                        ':prio' => $triage_priority
                    ]);
                }
            }
            
            if ($visit_type === 'emergency' && !$card_fee_collected) {
                $success_msg = 'Emergency patient registered with deferred card fee. Patient is in emergency queue.';
            } else {
                $success_msg = 'Patient registered and card fee paid. Routed to ' . ($routing === 'triage' ? 'nurse triage' : 'doctor consultation') . '.';
            }

            $db->commit();
            
            echo json_encode([
                'success' => true,
                'message' => $success_msg,
                'patient_id' => $new_patient_id,
                'visit_id' => $new_visit_id,
                'mr_number' => $mr_number,
                'age' => $age,
                'birth_date' => $birth_date,
                'requires_payment' => !($card_fee_collected || $visit_type === 'emergency'),
                'is_emergency' => $visit_type === 'emergency'
            ]);
            
        } catch (Exception $e) {
            $db->rollBack();
            error_log("Registration error: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Registration failed: ' . $e->getMessage()]);
        }
        
        exit;
    }
    
    // -------------------------------------------------------------------------
    // DIRECT CHECK-IN - With dynamic card fee
    // -------------------------------------------------------------------------
    if ($action === 'register_direct_visit') {
        $patient_id   = (int)($_POST['patient_id'] ?? 0);
        $dept_id      = (int)($_POST['department_id'] ?? 1); // Default OPD
        $doctor_id    = (int)($_POST['doctor_id'] ?? 0);
        $routing      = $_POST['routing'] ?? 'triage';
        
        // Check if card fee is mandatory based on visit frequency
        $card_fee_mandatory = isCardFeeMandatory($patient_id, $db);
        $card_fee_collected = isset($_POST['card_fee_collected']) && $_POST['card_fee_collected'] == '1';
        
        // If mandatory but not collected, reject
        if ($card_fee_mandatory && !$card_fee_collected) {
            echo json_encode(['success' => false, 'message' => 'Card fee is mandatory for this patient (last visit > 2 months ago)']);
            exit;
        }
        
        // Validate department exists
        $dept_check = $db->prepare("SELECT id FROM departments WHERE id = :dept_id AND is_active = 1 AND deleted_at IS NULL");
        $dept_check->execute([':dept_id' => $dept_id]);
        if (!$dept_check->fetch()) {
            $dept_id = 1; // Fallback to OPD
        }
        
        $is_emergency = isset($_POST['is_emergency']) && $_POST['is_emergency'] == '1';
        $emergency_priority = $_POST['emergency_priority'] ?? 'yellow';

        if ($patient_id === 0 || $dept_id === 0) {
            echo json_encode(['success' => false, 'message' => 'Patient and department are required']);
            exit;
        }

        $patCheck = $db->prepare("SELECT id, first_name, last_name, date_of_birth FROM patients WHERE id = :pid AND deleted_at IS NULL");
        $patCheck->execute([':pid' => $patient_id]);
        $patient = $patCheck->fetch(PDO::FETCH_ASSOC);
        
        if (!$patient) {
            echo json_encode(['success' => false, 'message' => 'Patient not found']);
            exit;
        }

        $db->beginTransaction();
        
        try {
            $visit_number = 'VIS-' . date('Ymd') . '-' . str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
            $now_time = date('H:i:s');
            
            $visit_status = ($card_fee_collected || $is_emergency) ? 'checked_in' : 'pending';
            $card_fee_status = ($card_fee_collected || $is_emergency) ? 'paid' : 'pending';
            $visit_type = $is_emergency ? 'emergency' : 'regular';
            
            $vstmt = $db->prepare("INSERT INTO patient_visits
                (branch_id, patient_id, visit_number, visit_date, visit_time, visit_type, card_fee_status, status, billing_status, is_priority, created_by_user_id, created_at, updated_at)
                VALUES (:b, :pid, :vn, CURDATE(), :vt, :vty, :cfs, :vst, 'unpaid', :prio, :uid, NOW(), NOW())");
            
            $vstmt->execute([
                ':b' => $request_branch, 
                ':pid' => $patient_id,
                ':vn' => $visit_number,
                ':vt' => $now_time,
                ':vty' => $visit_type,
                ':cfs' => $card_fee_status,
                ':vst' => $visit_status,
                ':prio' => $is_emergency ? 1 : 0,
                ':uid' => $user_id
            ]);
            
            $new_visit_id = (int)$db->lastInsertId();

            $timestamp = time();
            $routing_token = ($routing === 'triage') ? "TRIAGE" : "DOCTOR";
            $invoice_number = "INV-REG-{$routing_token}-DEPT{$dept_id}-{$timestamp}";
            
            $billing_type = ($is_emergency && !$card_fee_collected) ? 'deferred' : 'cash';
            
            $invStmt = $db->prepare("INSERT INTO invoices
                (branch_id, patient_id, visit_id, invoice_number, invoice_date, subtotal, discount_amount, tax_amount, total_amount, paid_amount, payment_status, billing_type, co_pay_percentage, co_pay_amount, created_by_user_id, created_at, updated_at)
                VALUES (:b, :pid, :vid, :invno, NOW(), 50.00, 0.00, 0.00, 50.00, 0.00, :pstatus, :btype, 0, 0.00, :uid, NOW(), NOW())");
            
            $invStmt->execute([
                ':b' => $request_branch, 
                ':pid' => $patient_id,
                ':vid' => $new_visit_id,
                ':invno' => $invoice_number,
                ':pstatus' => ($card_fee_collected) ? 'paid' : 'unpaid',
                ':btype' => $billing_type,
                ':uid' => $user_id
            ]);
            
            $new_invoice_id = (int)$db->lastInsertId();
            
            $appointment_number = 'APT-' . date('Ymd') . '-' . str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
            $appt_status = ($card_fee_collected || $is_emergency) ? 'checked_in' : 'scheduled';
            $billing_status = ($card_fee_collected) ? 'paid' : 'unpaid';
            $appt_priority = $is_emergency ? 'stat' : 'normal';
            
            $astmt = $db->prepare("INSERT INTO appointments
                (appointment_number, branch_id, patient_id, doctor_id, department_id, visit_id, appointment_date, appointment_time, status, is_emergency_walk_in, billing_status, priority, visit_type, created_by_user_id, created_at, updated_at)
                VALUES (:an, :b, :pid, :did, :dept, :vid, CURDATE(), :at, :st, :ew, :bs, :pr, :vty, :uid, NOW(), NOW())");
            
            $astmt->execute([
                ':an' => $appointment_number, 
                ':b' => $request_branch,
                ':pid' => $patient_id,
                ':did' => ($doctor_id > 0 ? $doctor_id : null),
                ':dept' => $dept_id,
                ':vid' => $new_visit_id,
                ':at' => $now_time,
                ':st' => $appt_status, 
                ':ew' => $is_emergency ? 1 : 0,
                ':bs' => $billing_status,
                ':pr' => $appt_priority,
                ':vty' => $visit_type,
                ':uid' => $user_id
            ]);

            $bstmt = $db->prepare("INSERT INTO billing_items
                (branch_id, patient_id, visit_id, item_type, description, amount, status, created_at, updated_at)
                VALUES (:b, :pid, :vid, 'card_fee', 'Registration Card Fee', 50.00, :status, NOW(), NOW())");
            
            $bstmt->execute([
                ':b' => $request_branch, 
                ':pid' => $patient_id,
                ':vid' => $new_visit_id,
                ':status' => $card_fee_collected ? 'paid' : 'pending'
            ]);

            if ($card_fee_collected) {
                $receipt_number = 'RCP-' . date('Ymd') . '-' . str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
                
                $payStmt = $db->prepare("INSERT INTO payments
                    (branch_id, invoice_id, patient_id, visit_id, receipt_number, payment_method, amount_paid, received_by_user_id, is_verified, created_at, updated_at)
                    VALUES (:b, :inv_id, :pid, :vid, :rcpt, 'cash', 50.00, :uid, 1, NOW(), NOW())");
                
                $payStmt->execute([
                    ':b' => $request_branch, 
                    ':inv_id' => $new_invoice_id,
                    ':pid' => $patient_id, 
                    ':vid' => $new_visit_id,
                    ':rcpt' => $receipt_number,
                    ':uid' => $user_id
                ]);
                
                $db->prepare("UPDATE invoices SET paid_amount = 50.00, payment_status = 'paid' WHERE id = :iid")
                ->execute([':iid' => $new_invoice_id]);
            }

            // Insert triage record if routed to triage
            if ($routing === 'triage' && ($card_fee_collected || $is_emergency)) {
                $triage_priority = $is_emergency ? $emergency_priority : 'yellow';
                
                $apptCheck = $db->prepare("SELECT id FROM appointments WHERE visit_id = :vid AND deleted_at IS NULL ORDER BY id LIMIT 1");
                $apptCheck->execute([':vid' => $new_visit_id]);
                $apptResult = $apptCheck->fetch(PDO::FETCH_ASSOC);
                
                if ($apptResult && !empty($apptResult['id']) && (int)$apptResult['id'] > 0) {
                    $tstmt = $db->prepare("INSERT INTO triage_records
                        (branch_id, patient_id, visit_id, taken_by_user_id, priority, chief_complaint, triage_time, appointment_id, created_at, updated_at)
                        VALUES (:b, :pid, :vid, :uid, :prio, 'Awaiting triage assessment', NOW(), :appt_id, NOW(), NOW())");
                    
                    $tstmt->execute([
                        ':b' => $request_branch, 
                        ':pid' => $patient_id,
                        ':vid' => $new_visit_id, 
                        ':uid' => $user_id,
                        ':prio' => $triage_priority,
                        ':appt_id' => (int)$apptResult['id']
                    ]);
                } else {
                    $tstmt = $db->prepare("INSERT INTO triage_records
                        (branch_id, patient_id, visit_id, taken_by_user_id, priority, chief_complaint, triage_time, created_at, updated_at)
                        VALUES (:b, :pid, :vid, :uid, :prio, 'Awaiting triage assessment', NOW(), NOW(), NOW())");
                    
                    $tstmt->execute([
                        ':b' => $request_branch, 
                        ':pid' => $patient_id,
                        ':vid' => $new_visit_id, 
                        ':uid' => $user_id,
                        ':prio' => $triage_priority
                    ]);
                }
            }
            
            if ($is_emergency && !$card_fee_collected) {
                $success_msg = 'Emergency patient checked in with deferred card fee.';
            } else {
                $success_msg = 'Patient checked in. Card fee paid. Routed to ' . ($routing === 'triage' ? 'nurse triage' : 'doctor consultation') . '.';
            }

            $db->commit();
            
            echo json_encode([
                'success' => true, 
                'message' => $success_msg,
                'visit_id' => $new_visit_id,
                'requires_payment' => !($card_fee_collected || $is_emergency),
                'is_emergency' => $is_emergency
            ]);
            
        } catch (Exception $e) {
            $db->rollBack();
            error_log("Direct check-in error: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Check-in failed: ' . $e->getMessage()]);
        }
        
        exit;
    }

    // -------------------------------------------------------------------------
    // SETTLE CARD FEE
    // -------------------------------------------------------------------------
    if ($action === 'settle_card_fee') {
        $invoice_id = (int)($_POST['invoice_id'] ?? 0);
        
        if ($invoice_id === 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid invoice ID']);
            exit;
        }

        $db->beginTransaction();
        
        try {
            $invStmt = $db->prepare("SELECT i.id, i.patient_id, i.invoice_number, i.total_amount, i.payment_status,
                                            i.visit_id,
                                            p.first_name, p.last_name, p.mr_number,
                                            pv.visit_type,
                                            pv.status AS visit_status
                                     FROM invoices i
                                     INNER JOIN patients p ON p.id = i.patient_id
                                     INNER JOIN patient_visits pv ON pv.id = i.visit_id
                                     WHERE i.id = :inv_id AND i.deleted_at IS NULL");
            
            $invStmt->execute([':inv_id' => $invoice_id]);
            $invoice = $invStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$invoice) {
                throw new Exception('Invoice not found.');
            }
            
            if ($invoice['payment_status'] === 'paid') {
                throw new Exception('Invoice already paid.');
            }

            $visit_id = $invoice['visit_id'];
            
            if (!$visit_id) {
                throw new Exception('No visit associated with this invoice.');
            }

            $routing = 'triage';
            if (preg_match('/INV-REG-(TRIAGE|DOCTOR)-DEPT(\d+)-(\d+)/', $invoice['invoice_number'], $matches)) {
                $routing = strtolower($matches[1]);
            }

            $receipt_number = 'RCP-' . date('Ymd') . '-' . str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
            
            $payStmt = $db->prepare("INSERT INTO payments
                (branch_id, invoice_id, patient_id, visit_id, receipt_number, payment_method, amount_paid, received_by_user_id, is_verified, created_at, updated_at)
                VALUES (:b, :inv_id, :pid, :vid, :rcpt, 'cash', :amt, :uid, 1, NOW(), NOW())");
            
            $payStmt->execute([
                ':b' => $request_branch,
                ':inv_id' => $invoice_id,
                ':pid' => $invoice['patient_id'],
                ':vid' => $visit_id,
                ':rcpt' => $receipt_number,
                ':amt' => $invoice['total_amount'],
                ':uid' => $user_id
            ]);

            $db->prepare("UPDATE invoices SET paid_amount = total_amount, payment_status = 'paid', updated_at = NOW() WHERE id = :inv_id")
               ->execute([':inv_id' => $invoice_id]);

            $db->prepare("UPDATE patient_visits SET card_fee_status = 'paid', updated_at = NOW() WHERE id = :vid")
               ->execute([':vid' => $visit_id]);
               
            $db->prepare("UPDATE appointments SET billing_status = 'paid', updated_at = NOW() WHERE visit_id = :vid")
               ->execute([':vid' => $visit_id]);
            
            $db->prepare("UPDATE billing_items SET status = 'paid', updated_at = NOW() WHERE visit_id = :vid AND item_type = 'card_fee'")
               ->execute([':vid' => $visit_id]);

            $db->commit();
            
            $routing_display = ($routing === 'triage') ? 'nurse triage queue' : 'doctor consultation queue';
            $emergency_note = ($invoice['visit_type'] === 'emergency') ? ' (Emergency patient now moved to ' . $routing_display . ')' : '';
            
            echo json_encode([
                'success' => true,
                'message' => 'Card fee settled. Patient released to ' . $routing_display . '.' . $emergency_note,
                'visit_id' => $visit_id,
                'is_emergency' => $invoice['visit_type'] === 'emergency'
            ]);
            
        } catch (Exception $e) {
            $db->rollBack();
            error_log("Settlement error: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Settlement failed: ' . $e->getMessage()]);
        }
        
        exit;
    }

    // -------------------------------------------------------------------------
    // START CONSULTATION
    // -------------------------------------------------------------------------
    if ($action === 'start_consultation') {
        $visit_id = (int)($_POST['visit_id'] ?? 0);
        
        if ($visit_id === 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid visit ID']);
            exit;
        }

        $stmt = $db->prepare("UPDATE patient_visits SET status = 'in_consultation', entered_room_at = NOW(), updated_at = NOW() WHERE id = :vid AND status = 'checked_in'");
        $stmt->execute([':vid' => $visit_id]);
        
        $apptStmt = $db->prepare("UPDATE appointments SET status = 'in_progress', updated_at = NOW() WHERE visit_id = :vid");
        $apptStmt->execute([':vid' => $visit_id]);
        
        if ($stmt->rowCount() > 0) {
            echo json_encode(['success' => true, 'message' => 'Consultation started']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Unable to start consultation']);
        }
        
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action']);
    exit;
}

// =============================================================================
// PENDING CARD FEES DATATABLE ENDPOINT - FIXED
// =============================================================================
if (isset($_GET['action']) && $_GET['action'] === 'get_pending_card_fees') {
    error_reporting(0);
    ini_set('display_errors', 0);
    
    if (ob_get_level()) ob_clean();
    header('Content-Type: application/json; charset=utf-8');

    // Ensure session is started for branch_id
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    try {
        $db = getDB();
        
        if (!$db) {
            echo json_encode(['draw' => (int)($_GET['draw'] ?? 1), 'recordsTotal' => 0, 'recordsFiltered' => 0, 'data' => []]);
            exit;
        }
        
        $draw = isset($_GET['draw']) ? (int)$_GET['draw'] : 1;
        $start = isset($_GET['start']) ? max(0, (int)$_GET['start']) : 0;
        $length = isset($_GET['length']) ? min(200, max(10, (int)$_GET['length'])) : 15;
        $search = isset($_GET['search']['value']) ? trim($_GET['search']['value']) : '';
        $like = '%' . $search . '%';
        $order_column = isset($_GET['order'][0]['column']) ? (int)$_GET['order'][0]['column'] : 3;
        $order_dir = isset($_GET['order'][0]['dir']) && strtoupper($_GET['order'][0]['dir']) === 'ASC' ? 'ASC' : 'DESC';

        // Map DataTable column index to database column
        $column_map = [
            0 => 'patient_name',
            1 => 'mr_number',
            2 => 'branch_name',
            3 => 'invoice_date',
            4 => 'visit_type',
            5 => 'total_amount'
        ];
        $order_by = $column_map[$order_column] ?? 'invoice_date';

        // Get branch_id from session
        $branch_filter = (int)($_SESSION['branch_id'] ?? 0);
        
        // Build WHERE clause with proper aliases
        $where = "i.payment_status = 'unpaid' AND i.deleted_at IS NULL";
        $params = array();
        
        if ($branch_filter > 0) {
            $where .= " AND i.branch_id = ?";
            $params[] = $branch_filter;
        }
        
        if ($search !== '') {
            $where .= " AND (p.mr_number LIKE ? OR CONCAT(p.first_name, ' ', COALESCE(p.middle_name,''), ' ', p.last_name) LIKE ? OR p.first_name LIKE ? OR p.last_name LIKE ?)";
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        // Get total count
        $totalSql = "SELECT COUNT(DISTINCT i.id) FROM invoices i
                     INNER JOIN patients p ON p.id = i.patient_id
                     WHERE " . $where;
        $totalStmt = $db->prepare($totalSql);
        $totalStmt->execute($params);
        $total = (int)$totalStmt->fetchColumn();

        // Get filtered count (same as total since we're using the same WHERE)
        $filteredTotal = $total;

        // Build the main query
        $sql = "SELECT 
                    i.id AS invoice_id, 
                    i.total_amount, 
                    i.invoice_date, 
                    i.visit_id,
                    p.id AS patient_id, 
                    p.first_name, 
                    p.middle_name, 
                    p.last_name, 
                    p.mr_number,
                    COALESCE(b.name, 'Main Branch') AS branch_name,
                    pv.visit_type,
                    CASE 
                        WHEN pv.visit_type = 'emergency' THEN 'Emergency (Deferred)'
                        ELSE 'Regular'
                    END AS visit_type_display,
                    TIMESTAMPDIFF(MINUTE, pv.created_at, NOW()) AS waiting_minutes,
                    CONCAT(COALESCE(p.first_name,''), ' ', COALESCE(p.middle_name,''), ' ', COALESCE(p.last_name,'')) AS patient_name
                FROM invoices i
                INNER JOIN patients p ON p.id = i.patient_id
                LEFT JOIN branches b ON i.branch_id = b.id
                LEFT JOIN patient_visits pv ON i.visit_id = pv.id
                WHERE " . $where . "
                ORDER BY 
                    CASE WHEN pv.visit_type = 'emergency' THEN 1 ELSE 2 END,
                    pv.created_at ASC,
                    " . $order_by . " " . $order_dir . "
                LIMIT " . (int)$length . " OFFSET " . (int)$start;
        
        $dataStmt = $db->prepare($sql);
        $dataStmt->execute($params);
        $rows = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

        $data = array();
        
        foreach ($rows as $row) {
            $fullName = trim(($row['first_name'] ?? '') . ' ' . ($row['middle_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
            if (empty($fullName)) $fullName = 'Unknown';
            
            $invoiceDate = date('d M Y, H:i', strtotime($row['invoice_date']));
            $amount = number_format((float)$row['total_amount'], 2) . ' ETB';
            
            $isEmergency = ($row['visit_type'] ?? '') === 'emergency';
            $visitTypeBadge = $isEmergency 
                ? '<span class="badge badge-danger"><i class="fas fa-ambulance me-1"></i> Emergency (Deferred)</span>'
                : '<span class="badge badge-secondary">Regular</span>';
            
            $waitingDisplay = $isEmergency && ($row['waiting_minutes'] ?? 0) > 0
                ? ' <span class="small text-danger">(' . $row['waiting_minutes'] . ' min waiting)</span>'
                : '';

            $data[] = array(
                'patient_name'       => sanitizeOutput($fullName) . $waitingDisplay,
                'mr_number'          => sanitizeOutput($row['mr_number'] ?? 'N/A'),
                'branch'             => sanitizeOutput($row['branch_name'] ?? 'OPD'),
                'invoice_date'       => $invoiceDate,
                'visit_type'         => $visitTypeBadge,
                'amount'             => $amount,
                'action'             => '<button class="mark-paid-btn btn btn-sm ' . ($isEmergency ? 'btn-warning' : 'btn-success') . '" data-invoice-id="' . (int)$row['invoice_id'] . '" data-patient-name="' . sanitizeOutput($fullName) . '" data-is-emergency="' . ($isEmergency ? '1' : '0') . '"><i class="fas fa-check-circle me-1"></i> ' . ($isEmergency ? 'Settle Emergency Fee' : 'Settle Card Fee') . '</button>'
            );
        }

        echo json_encode(array(
            'draw'            => $draw,
            'recordsTotal'    => $total,
            'recordsFiltered' => $filteredTotal,
            'data'            => $data
        ));
        
    } catch (PDOException $e) {
        error_log("Pending fees error: " . $e->getMessage());
        echo json_encode(array(
            'draw' => (int)($_GET['draw'] ?? 1), 
            'recordsTotal' => 0, 
            'recordsFiltered' => 0, 
            'data' => array(),
            'error' => 'Database error occurred'
        ));
    }
    
    exit;
}

// =============================================================================
// PAGE LOAD - Get initial data
// =============================================================================
$pdo = getDB();
$initial_data = array();

if ($pdo) {
    try {
        $stmt = $pdo->prepare("
            SELECT 
                (SELECT COUNT(*) FROM patients WHERE DATE(created_at) = CURDATE() AND deleted_at IS NULL AND branch_id = ?) AS registrations_today,
                (SELECT COUNT(DISTINCT i.id) 
                 FROM invoices i
                 INNER JOIN patient_visits pv ON pv.id = i.visit_id
                 WHERE i.payment_status = 'unpaid' 
                   AND i.deleted_at IS NULL 
                   AND i.branch_id = ?
                   AND pv.visit_type != 'emergency') AS awaiting_card_fee,
                (SELECT COUNT(DISTINCT i.id) 
                 FROM invoices i
                 INNER JOIN patient_visits pv ON pv.id = i.visit_id
                 WHERE i.payment_status = 'unpaid' 
                   AND i.deleted_at IS NULL 
                   AND i.branch_id = ?
                   AND pv.visit_type = 'emergency') AS emergency_pending_card_fee,
                (SELECT COUNT(*) FROM patient_visits WHERE visit_type = 'emergency' AND DATE(created_at) = CURDATE() AND branch_id = ? AND deleted_at IS NULL) AS emergency_today,
                (SELECT COUNT(*) FROM patient_visits WHERE status = 'in_consultation' AND branch_id = ? AND deleted_at IS NULL) AS in_consultation,
                (SELECT COUNT(*) FROM admissions WHERE status = 'admitted' AND branch_id = ? AND deleted_at IS NULL) AS active_admissions
        ");
        $stmt->execute([$branch_id, $branch_id, $branch_id, $branch_id, $branch_id, $branch_id]);
        $initial_data = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $doc_stmt = $pdo->prepare("
            SELECT u.id,
                   CONCAT(COALESCE(u.first_name,''), ' ', COALESCE(u.middle_name,''), ' ', COALESCE(u.last_name,'')) AS doctor_name,
                   COALESCE(sp.specialization, 'General Medicine') AS specialist,
                   (SELECT COUNT(DISTINCT a.visit_id) 
                    FROM appointments a
                    INNER JOIN patient_visits pv ON pv.id = a.visit_id
                    WHERE a.doctor_id = u.id 
                      AND a.appointment_date = CURDATE()
                      AND a.status IN ('scheduled', 'checked_in', 'in_progress')
                      AND a.deleted_at IS NULL) AS todays_patients
            FROM users u
            LEFT JOIN staff_profiles sp ON u.id = sp.user_id
            WHERE u.user_type = 'doctor' AND u.is_active = 1 AND u.deleted_at IS NULL
              AND u.branch_id = ?
            ORDER BY doctor_name
        ");
        $doc_stmt->execute([$branch_id]);
        $doctor_availability = $doc_stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (PDOException $e) {
        error_log("Initial data error: " . $e->getMessage());
    }
}

$current_date_display = date('l, F j, Y');
$current_time_display = date('g:i A');

// Debug - uncomment to test the endpoint
// if (isset($_GET['test'])) {
//     header('Content-Type: application/json');
//     echo json_encode(['test' => 'working', 'branch' => $branch_id]);
//     exit;
// }
?>

<!-- ============================================================================= -->
<!-- HTML SECTION - Full layout (same as before, keep the existing HTML) -->
<!-- ============================================================================= -->

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reception Dashboard - HMS ERP | Patient Flow Management</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
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
        
        .welcome-header {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--primary) 100%);
            border-radius: 24px;
            padding: 28px 32px;
            color: white;
            margin-bottom: 28px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }
        
        .welcome-title { font-size: 26px; font-weight: 700; margin-bottom: 6px; }
        .welcome-subtitle { color: rgba(255,255,255,0.8); font-size: 14px; }
        
        .time-badge {
            background: rgba(255,255,255,0.15);
            padding: 10px 20px;
            border-radius: 14px;
            font-size: 13px;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            backdrop-filter: blur(10px);
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-bottom: 28px;
        }
        
        @media (max-width: 1200px) { .stats-grid { grid-template-columns: repeat(2, 1fr); } }
        @media (max-width: 600px) { .stats-grid { grid-template-columns: 1fr; } }
        
        .stat-card {
            background: white;
            border-radius: 20px;
            padding: 22px;
            transition: all 0.3s;
            border: 1px solid var(--gray-light);
            position: relative;
            overflow: hidden;
        }
        
        .stat-card:hover { transform: translateY(-3px); box-shadow: 0 15px 35px rgba(0,0,0,0.08); }
        
        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
        }
        
        .stat-card.primary::before { background: linear-gradient(90deg, var(--primary), var(--primary-dark)); }
        .stat-card.warning::before { background: linear-gradient(90deg, var(--warning), #d97706); }
        .stat-card.danger::before { background: linear-gradient(90deg, var(--danger), #c0392b); }
        .stat-card.info::before { background: linear-gradient(90deg, var(--info), #0891b2); }
        .stat-card.success::before { background: linear-gradient(90deg, var(--success), #059669); }
        
        .stat-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 16px;
        }
        
        .stat-icon {
            width: 52px;
            height: 52px;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
        }
        
        .stat-icon.primary { background: rgba(67,97,238,0.1); color: var(--primary); }
        .stat-icon.warning { background: rgba(249,199,79,0.1); color: var(--warning); }
        .stat-icon.danger { background: rgba(239,71,111,0.1); color: var(--danger); }
        .stat-icon.info { background: rgba(76,201,240,0.1); color: var(--info); }
        .stat-icon.success { background: rgba(6,214,160,0.1); color: var(--success); }
        
        .stat-value { font-size: 34px; font-weight: 800; color: var(--dark); line-height: 1.1; }
        .stat-label { font-size: 13px; color: var(--gray); font-weight: 500; margin-top: 4px; }
        
        .stat-footer {
            margin-top: 14px;
            padding-top: 12px;
            border-top: 1px solid var(--gray-light);
            font-size: 12px;
            color: var(--gray);
        }
        
        .quick-actions {
            background: white;
            border-radius: 20px;
            padding: 20px 28px;
            margin-bottom: 28px;
            border: 1px solid var(--gray-light);
            display: flex;
            gap: 16px;
            flex-wrap: wrap;
        }
        
        .quick-btn {
            padding: 12px 28px;
            border-radius: 14px;
            font-size: 14px;
            font-weight: 600;
            border: none;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            transition: all 0.2s;
        }
        
        .quick-btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
        }
        
        .quick-btn-primary:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(67,97,238,0.3); }
        
        .quick-btn-success {
            background: linear-gradient(135deg, var(--success), #059669);
            color: white;
        }
        
        .quick-btn-success:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(6,214,160,0.3); }
        
        .quick-btn-danger {
            background: linear-gradient(135deg, var(--danger), #c0392b);
            color: white;
        }
        
        .quick-btn-danger:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(239,71,111,0.3); }
        
        .quick-btn-outline {
            background: white;
            border: 1px solid var(--gray-light);
            color: var(--dark);
        }
        
        .quick-btn-outline:hover { border-color: var(--primary); color: var(--primary); background: #f8fafc; }
        
        .search-container {
            background: white;
            border-radius: 20px;
            padding: 20px 28px;
            margin-bottom: 28px;
            border: 1px solid var(--gray-light);
        }
        
        .search-input-group input {
            width: 100%;
            padding: 14px 20px;
            border: 1px solid var(--gray-light);
            border-radius: 14px;
            font-size: 14px;
            transition: all 0.2s;
        }
        
        .search-input-group input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(67,97,238,0.1);
        }
        
        .dashboard-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
            margin-bottom: 28px;
        }
        
        @media (max-width: 1200px) { .dashboard-grid { grid-template-columns: 1fr; } }
        
        .appointments-section, .doctor-section, .activity-section {
            background: white;
            border-radius: 20px;
            border: 1px solid var(--gray-light);
            overflow: hidden;
        }
        
        .section-header {
            padding: 20px 24px;
            background: #f8fafc;
            border-bottom: 1px solid var(--gray-light);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
        }
        
        .section-header h3 { margin: 0; font-size: 18px; font-weight: 700; color: var(--dark); }
        
        .data-table { width: 100%; border-collapse: collapse; }
        
        .data-table th {
            padding: 14px 20px;
            font-size: 12px;
            font-weight: 600;
            color: var(--gray);
            text-transform: uppercase;
            background: #f8fafc;
            border-bottom: 1px solid var(--gray-light);
        }
        
        .data-table td {
            padding: 12px 20px;
            font-size: 13px;
            border-bottom: 1px solid #f3f4f6;
            vertical-align: middle;
        }
        
        .data-table tr:hover { background: #f8fafc; }
        
        .badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
        }
        
        .badge-primary { background: #e0e7ff; color: var(--primary); }
        .badge-success { background: #d1fae5; color: var(--success); }
        .badge-warning { background: #fef3c7; color: #d97706; }
        .badge-danger { background: #fee2e2; color: var(--danger); }
        .badge-secondary { background: #e2e8f0; color: #475569; }
        .badge-info { background: #e0f0fa; color: #0c4a6e; }
        
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
        
        .doctor-grid { display: grid; gap: 12px; padding: 20px; }
        
        .doctor-card {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 14px 18px;
            background: #f8fafc;
            border-radius: 16px;
            transition: all 0.2s;
        }
        
        .doctor-card:hover { background: #f1f5f9; }
        
        .doctor-name { font-weight: 700; font-size: 14px; color: var(--dark); }
        .doctor-specialty { font-size: 11px; color: var(--gray); margin-top: 3px; }
        
        .doctor-status {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            margin-right: 8px;
            display: inline-block;
            background: var(--success);
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0% { opacity: 1; }
            50% { opacity: 0.5; }
            100% { opacity: 1; }
        }
        
        .doctor-badge {
            font-size: 12px;
            padding: 5px 12px;
            border-radius: 20px;
            background: white;
            font-weight: 600;
        }
        
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
            backdrop-filter: blur(4px);
        }
        
        .modal.active { display: flex; }
        
        .modal-content {
            background: white;
            border-radius: 24px;
            max-width: 640px;
            width: 90%;
            max-height: 85vh;
            overflow-y: auto;
            box-shadow: 0 25px 50px rgba(0,0,0,0.25);
        }
        
        .modal-header {
            padding: 20px 24px;
            border-bottom: 1px solid var(--gray-light);
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #f8fafc;
            border-radius: 24px 24px 0 0;
        }
        
        .modal-header h4 { margin: 0; font-weight: 700; color: var(--dark); }
        
        .close-modal {
            background: none;
            border: none;
            font-size: 28px;
            cursor: pointer;
            color: var(--gray);
            transition: color 0.2s;
        }
        
        .close-modal:hover { color: var(--danger); }
        
        .modal-body { padding: 24px; }
        
        .modal-footer {
            padding: 16px 24px;
            border-top: 1px solid var(--gray-light);
            display: flex;
            justify-content: flex-end;
            gap: 12px;
            background: #f8fafc;
            border-radius: 0 0 24px 24px;
        }
        
        .form-group { margin-bottom: 20px; }
        
        .form-group label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 8px;
            color: var(--dark);
        }
        
        .form-group input, 
        .form-group select, 
        .form-group textarea {
            width: 100%;
            padding: 10px 14px;
            border: 1px solid var(--gray-light);
            border-radius: 12px;
            font-size: 14px;
            transition: all 0.2s;
        }
        
        .form-group input:focus, 
        .form-group select:focus, 
        .form-group textarea:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(67,97,238,0.1);
        }
        
        .radio-group {
            display: flex;
            gap: 24px;
            margin-top: 8px;
            flex-wrap: wrap;
        }
        
        .radio-group label {
            font-weight: normal;
            margin-left: 6px;
            cursor: pointer;
        }
        
        .radio-group input { width: auto; cursor: pointer; }
        
        .form-check {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .form-check-input { width: 18px; height: 18px; cursor: pointer; }
        .form-check-label { font-weight: normal; cursor: pointer; }
        
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
        
        @keyframes slideIn {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        
        .toast-error { border-left-color: var(--danger); }
        
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
        
        .search-results-list {
            max-height: 400px;
            overflow-y: auto;
            border: 1px solid var(--gray-light);
            border-radius: 12px;
            margin-top: 16px;
            display: none;
            background: white;
        }
        
        .search-result-item {
            padding: 16px;
            cursor: pointer;
            border-bottom: 1px solid var(--gray-light);
            transition: background 0.2s;
        }
        
        .search-result-item:hover { background: #f8fafc; }
        
        .search-result-actions {
            display: flex;
            gap: 10px;
            margin-top: 10px;
        }
        
        .action-btn {
            padding: 6px 16px;
            border-radius: 10px;
            font-size: 12px;
            font-weight: 500;
            border: none;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .action-btn-checkin {
            background: var(--success);
            color: white;
        }
        
        .action-btn-checkin:hover { background: #059669; }
        
        .action-btn-emergency {
            background: var(--danger);
            color: white;
        }
        
        .action-btn-emergency:hover { background: #c0392b; }
        
        .nav-tabs {
            border-bottom: 1px solid var(--gray-light);
            margin-bottom: 16px;
        }
        
        .nav-tabs .nav-link {
            border: none;
            color: var(--gray);
            font-weight: 600;
            padding: 10px 20px;
            transition: all 0.2s;
        }
        
        .nav-tabs .nav-link:hover {
            color: var(--primary);
            background: transparent;
        }
        
        .nav-tabs .nav-link.active {
            color: var(--primary);
            border-bottom: 2px solid var(--primary);
            background: transparent;
        }
        
        .pending-fees-card { margin-top: 0; }
        
        .card {
            background: white;
            border-radius: 20px;
            border: 1px solid var(--gray-light);
            overflow: hidden;
        }
        
        .card-header {
            padding: 20px 24px;
            background: #f8fafc;
            border-bottom: 1px solid var(--gray-light);
        }
        
        .card-body { padding: 20px 24px; }
        
        .text-muted { color: var(--gray); }
        .small { font-size: 11px; }
        .me-1 { margin-right: 4px; }
        .me-2 { margin-right: 8px; }
        .mt-2 { margin-top: 8px; }
        .fw-bold { font-weight: 700; }
        .btn-sm { padding: 5px 12px; font-size: 12px; }
        .btn-success { background: var(--success); border: none; }
        .btn-success:hover { background: #059669; }
        .btn-warning { background: var(--warning); border: none; color: #000; }
        .btn-warning:hover { background: #eab308; }
        
        table.dataTable { margin-top: 0 !important; }
        
        .dataTables_wrapper .dataTables_paginate .paginate_button.current {
            background: var(--primary);
            color: white !important;
            border: none;
        }
        
        .status-badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 10px;
            font-weight: 600;
        }
        .status-badge.scheduled { background: #e0e7ff; color: #3730a3; }
        .status-badge.checked_in { background: #d1fae5; color: #065f46; }
        .status-badge.in_progress { background: #fef3c7; color: #92400e; }
        .status-badge.completed { background: #d1fae5; color: #065f46; }
        .status-badge.cancelled { background: #fee2e2; color: #991b1b; }
        
        .priority-stat { background: #ef476f; color: white; padding: 2px 8px; border-radius: 12px; font-size: 10px; font-weight: 700; }
        .priority-urgent { background: #f9c74f; color: #000; }
        .priority-normal { background: #06d6a0; color: white; }
        
        .chart-container {
            height: 180px;
            margin: 10px 0;
        }
        
        .department-badge {
            display: inline-block;
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: 600;
            background: #e0e7ff;
            color: var(--primary);
        }
        
        .activity-item {
            padding: 10px 0;
            border-bottom: 1px solid #f3f4f6;
        }
        
        .activity-item:last-child { border-bottom: none; }
        
        .activity-time {
            font-size: 11px;
            color: var(--gray);
        }
        
        .quick-link {
            color: var(--primary);
            text-decoration: none;
            font-weight: 500;
            padding: 8px 16px;
            border-radius: 10px;
            background: rgba(67,97,238,0.08);
            transition: all 0.2s;
        }
        
        .quick-link:hover {
            background: rgba(67,97,238,0.15);
            color: var(--primary-dark);
        }

        .age-calc-group {
            display: flex;
            gap: 16px;
            align-items: flex-end;
        }

        .age-calc-group .form-group {
            flex: 1;
        }

        .age-calc-group .form-group:last-child {
            flex: 0 0 100px;
        }

        .emergency-badge-header {
            background: var(--danger);
            color: white;
            padding: 4px 16px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .card-fee-info {
            background: #f0f4ff;
            border-radius: 12px;
            padding: 12px 16px;
            border-left: 4px solid var(--primary);
            margin-top: 10px;
        }
        
        .card-fee-mandatory {
            border-left-color: var(--danger);
            background: #fff0f0;
        }
        
        .card-fee-optional {
            border-left-color: var(--success);
            background: #f0fff4;
        }

        /* DataTable loading state */
        .dataTables_processing {
            background: rgba(255,255,255,0.9) !important;
            border: none !important;
            box-shadow: none !important;
            padding: 20px !important;
        }
        
        /* Fix for DataTable on smaller screens */
        @media (max-width: 768px) {
            .dataTables_wrapper .dataTables_length,
            .dataTables_wrapper .dataTables_filter,
            .dataTables_wrapper .dataTables_info,
            .dataTables_wrapper .dataTables_paginate {
                float: none !important;
                text-align: center !important;
                margin: 10px 0 !important;
            }
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
                <div class="row align-items-center">
                    <div class="col-md-8">
                        <div class="welcome-title">Welcome, <?php echo sanitizeOutput($user_name); ?> 👋</div>
                        <div class="welcome-subtitle">Manage patient flow, appointments, and clinical routing from your command center.</div>
                    </div>
                    <div class="col-md-4 text-md-end mt-3 mt-md-0">
                        <div class="time-badge">
                            <i class="far fa-calendar-alt"></i> <?php echo sanitizeOutput($current_date_display); ?>
                            <i class="far fa-clock ms-2"></i> <?php echo sanitizeOutput($current_time_display); ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Stats Cards -->
            <div class="stats-grid">
                <div class="stat-card primary">
                    <div class="stat-header">
                        <div>
                            <div class="stat-value" id="kpiRegistrationsToday">0</div>
                            <div class="stat-label">Total Registered Today</div>
                        </div>
                        <div class="stat-icon primary"><i class="fas fa-users"></i></div>
                    </div>
                    <div class="stat-footer">New patient profiles created today</div>
                </div>
                <div class="stat-card warning">
                    <div class="stat-header">
                        <div>
                            <div class="stat-value" id="kpiAwaitingCardFee">0</div>
                            <div class="stat-label">Awaiting Card Fee</div>
                        </div>
                        <div class="stat-icon warning"><i class="fas fa-hourglass-half"></i></div>
                    </div>
                    <div class="stat-footer">Patients locked until cashier clearance</div>
                </div>
                <div class="stat-card danger">
                    <div class="stat-header">
                        <div>
                            <div class="stat-value" id="kpiEmergencyPending">0</div>
                            <div class="stat-label">Emergency Pending Fee</div>
                        </div>
                        <div class="stat-icon danger"><i class="fas fa-ambulance"></i></div>
                    </div>
                    <div class="stat-footer">Emergency patients with deferred card fee</div>
                </div>
                <div class="stat-card info">
                    <div class="stat-header">
                        <div>
                            <div class="stat-value" id="kpiInConsult">0</div>
                            <div class="stat-label">Active Consultations</div>
                        </div>
                        <div class="stat-icon info"><i class="fas fa-user-md"></i></div>
                    </div>
                    <div class="stat-footer">Patients currently with doctors</div>
                </div>
                <div class="stat-card success">
                    <div class="stat-header">
                        <div>
                            <div class="stat-value" id="kpiActiveAdmissions">0</div>
                            <div class="stat-label">Active Admissions</div>
                        </div>
                        <div class="stat-icon success"><i class="fas fa-procedures"></i></div>
                    </div>
                    <div class="stat-footer">Patients admitted in wards</div>
                </div>
                <div class="stat-card primary">
                    <div class="stat-header">
                        <div>
                            <div class="stat-value" id="kpiTodayAppointments">0</div>
                            <div class="stat-label">Today's Appointments</div>
                        </div>
                        <div class="stat-icon primary"><i class="fas fa-calendar-check"></i></div>
                    </div>
                    <div class="stat-footer">Total scheduled for today</div>
                </div>
                <div class="stat-card danger" style="grid-column: span 1;">
                    <div class="stat-header">
                        <div>
                            <div class="stat-value" id="kpiEmergencyToday">0</div>
                            <div class="stat-label">Emergency Admissions</div>
                        </div>
                        <div class="stat-icon danger"><i class="fas fa-heartbeat"></i></div>
                    </div>
                    <div class="stat-footer">Emergency cases today</div>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="quick-actions">
                <button class="quick-btn quick-btn-primary" onclick="openModal('registerPatientModal')">
                    <i class="fas fa-user-plus"></i> Register New Patient
                </button>
                <button class="quick-btn quick-btn-success" onclick="openModal('directCheckInModal')">
                    <i class="fas fa-sign-in-alt"></i> Direct Check-In
                </button>
                <button class="quick-btn quick-btn-danger" onclick="openModal('emergencyCheckInModal')">
                    <i class="fas fa-ambulance"></i> Emergency Check-In
                </button>
                <a href="birth_death_registry.php" class="quick-btn quick-btn-outline">
                    <i class="fas fa-baby"></i> Birth/Death Registry
                </a>
                <a href="patient_directory.php" class="quick-btn quick-btn-outline">
                    <i class="fas fa-folder-open"></i> Patient Directory
                </a>
                <button class="quick-btn quick-btn-outline" onclick="fetchAllData()">
                    <i class="fas fa-sync-alt"></i> Refresh Dashboard
                </button>
            </div>

            <!-- Global Patient Search -->
            <div class="search-container">
                <div class="search-input-group">
                    <input type="text" id="globalSearch" placeholder="Search by Patient Name, MRN, or Phone Number..." autocomplete="off">
                </div>
                <div id="liveSearchResults" class="search-results-list"></div>
            </div>

            <!-- Main Dashboard Grid -->
            <div class="dashboard-grid">
                <!-- Left Column: Queues -->
                <div>
                    <div class="appointments-section">
                        <div class="section-header">
                            <h3><i class="fas fa-tasks me-2"></i> Clinical Workflow Queues</h3>
                            <button class="quick-btn quick-btn-outline" onclick="fetchQueues()">Refresh Queues</button>
                        </div>
                        <div style="padding: 16px 20px;">
                            <ul class="nav nav-tabs" id="workflowTabs" role="tablist">
                                <li class="nav-item">
                                    <button class="nav-link active" id="nurse-tab" data-bs-toggle="tab" data-bs-target="#nurse">
                                        <i class="fas fa-stethoscope me-1"></i> Nurse Triage
                                    </button>
                                </li>
                                <li class="nav-item">
                                    <button class="nav-link" id="doctor-tab" data-bs-toggle="tab" data-bs-target="#doctor">
                                        <i class="fas fa-user-md me-1"></i> Doctor Queue
                                    </button>
                                </li>
                                <li class="nav-item">
                                    <button class="nav-link" id="emergency-tab" data-bs-toggle="tab" data-bs-target="#emergency-queue-tab">
                                        <i class="fas fa-ambulance me-1"></i> Emergency Queue
                                        <span class="badge badge-danger ms-1" id="emergencyQueueCount">0</span>
                                    </button>
                                </li>
                                <li class="nav-item">
                                    <button class="nav-link" id="appointments-tab" data-bs-toggle="tab" data-bs-target="#appointments">
                                        <i class="fas fa-calendar me-1"></i> Today's Appointments
                                    </button>
                                </li>
                            </ul>
                            <div class="tab-content" style="margin-top: 16px;">
                                <div class="tab-pane fade show active" id="nurse">
                                    <div class="table-responsive">
                                        <table class="data-table">
                                            <thead>
                                                <tr><th>Time</th><th>Patient</th><th>MRN</th><th>Phone</th><th>Department</th><th>Action</th></tr>
                                            </thead>
                                            <tbody id="nurseQueueBody">
                                                <tr><td colspan="6" class="text-muted text-center">Loading nurse queue...</td></tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                                <div class="tab-pane fade" id="doctor">
                                    <div class="table-responsive">
                                        <table class="data-table">
                                            <thead>
                                                <tr><th>#</th><th>Patient</th><th>Phone</th><th>Priority</th><th>Department</th><th>Action</th></tr>
                                            </thead>
                                            <tbody id="doctorQueueBody">
                                                <tr><td colspan="6" class="text-muted text-center">Loading doctor queue...</td></tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                                <div class="tab-pane fade" id="emergency-queue-tab">
                                    <div class="table-responsive">
                                        <table class="data-table">
                                            <thead>
                                                <tr><th>Time</th><th>Patient</th><th>MRN</th><th>Phone</th><th>Department</th><th>Fee Status</th><th>Action</th></tr>
                                            </thead>
                                            <tbody id="emergencyQueueBody">
                                                <tr><td colspan="7" class="text-muted text-center">Loading emergency queue...</td></tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                                <div class="tab-pane fade" id="appointments">
                                    <div class="table-responsive">
                                        <table class="data-table">
                                            <thead>
                                                <tr><th>Time</th><th>Patient</th><th>MRN</th><th>Dept</th><th>Doctor</th><th>Status</th></tr>
                                            </thead>
                                            <tbody id="appointmentsBody">
                                                <tr><td colspan="6" class="text-muted text-center">Loading appointments...</td></tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Right Column: Doctors & Activity -->
                <div>
                    <div class="doctor-section">
                        <div class="section-header">
                            <h3><i class="fas fa-stethoscope me-2"></i> Doctor Availability</h3>
                            <span class="badge badge-primary">Today's Schedule</span>
                        </div>
                        <div class="doctor-grid" id="doctorGrid">
                            <?php if (isset($doctor_availability) && count($doctor_availability) > 0): ?>
                                <?php foreach ($doctor_availability as $doctor): ?>
                                <div class="doctor-card">
                                    <div class="doctor-info">
                                        <div class="doctor-name">
                                            <span class="doctor-status"></span>
                                            Dr. <?php echo sanitizeOutput($doctor['doctor_name']); ?>
                                        </div>
                                        <div class="doctor-specialty"><?php echo sanitizeOutput($doctor['specialist'] ?? 'General Medicine'); ?></div>
                                    </div>
                                    <div class="doctor-badge">
                                        <i class="fas fa-user me-1"></i> <?php echo (int)($doctor['todays_patients'] ?? 0); ?> patients
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="text-center py-4 text-muted">No doctor schedules found for today.</div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Recent Activity -->
                    <div class="activity-section" style="margin-top: 24px;">
                        <div class="section-header">
                            <h3><i class="fas fa-clock me-2"></i> Recent Activity</h3>
                            <span class="badge badge-secondary">Latest Registrations</span>
                        </div>
                        <div id="recentActivity" style="padding: 16px 20px;">
                            <div class="text-muted text-center">Loading...</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Pending Card Fees Resolution Center - FIXED SECTION -->
            <div class="card pending-fees-card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-exclamation-triangle text-warning me-2"></i> Pending Card Fee Invoices</h5>
                    <small class="text-muted">Unpaid registration fees requiring cashier settlement <span class="badge badge-danger ms-2"><i class="fas fa-ambulance"></i> Emergency deferred fees shown in red</span></small>
                </div>
                <div class="card-body">
                    <table id="pendingFeesTable" class="table table-hover display" style="width:100%">
                        <thead>
                            <tr>
                                <th>Patient</th>
                                <th>MRN</th>
                                <th>Branch</th>
                                <th>Invoice Date</th>
                                <th>Visit Type</th>
                                <th>Amount</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <!-- DataTable will populate this -->
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Insurance Status & Department Distribution -->
            <div class="dashboard-grid" style="margin-top: 24px;">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-shield-alt me-2"></i> Insurance Claim Status</h5>
                    </div>
                    <div class="card-body" id="insuranceStatus">
                        <div class="text-muted text-center">Loading...</div>
                    </div>
                </div>
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-building me-2"></i> Department Distribution</h5>
                    </div>
                    <div class="card-body" id="departmentDistribution">
                        <div class="text-muted text-center">Loading...</div>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <?php include('assets/inc/footer.php'); ?>
</div>

<div id="loadingOverlay" class="loading-overlay">
    <div class="spinner"></div>
</div>

<!-- MODAL: REGISTER NEW PATIENT -->
<div id="registerPatientModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h4><i class="fas fa-user-plus me-2"></i> Register New Patient</h4>
            <button class="close-modal" onclick="closeModal('registerPatientModal')">&times;</button>
        </div>
        <div class="modal-body">
            <form id="registerPatientForm">
                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label>First Name *</label>
                            <input type="text" name="first_name" class="form-control" required maxlength="100">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label>Last Name *</label>
                            <input type="text" name="last_name" class="form-control" required maxlength="100">
                        </div>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Middle Name</label>
                    <input type="text" name="middle_name" class="form-control" maxlength="50">
                </div>
                
                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label>Email</label>
                            <input type="email" name="email" class="form-control" maxlength="100">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label>Phone (09XXXXXXXX or +2519XXXXXXXX)</label>
                            <input type="tel" name="phone" class="form-control" maxlength="15" placeholder="0912345678">
                        </div>
                    </div>
                </div>
                
                <div class="age-calc-group">
                    <div class="form-group">
                        <label>Age (Years)</label>
                        <input type="number" name="age" id="regAge" class="form-control" min="0" max="150" placeholder="Enter age or birth date">
                    </div>
                    <div class="form-group">
                        <label>Date of Birth</label>
                        <input type="date" name="birth_date" id="regBirthDate" class="form-control">
                    </div>
                </div>
                <div class="small text-muted mt-1">
                    <i class="fas fa-info-circle"></i> Enter either Age or Date of Birth. The other field will be calculated automatically.
                </div>
                
                <div class="row mt-3">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label>Gender</label>
                            <select name="gender" class="form-control">
                                <option value="male">Male</option>
                                <option value="female">Female</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label>Blood Group</label>
                            <select name="blood_group" class="form-control">
                                <option value="unknown">Unknown</option>
                                <option value="A+">A+</option>
                                <option value="A-">A-</option>
                                <option value="B+">B+</option>
                                <option value="B-">B-</option>
                                <option value="AB+">AB+</option>
                                <option value="AB-">AB-</option>
                                <option value="O+">O+</option>
                                <option value="O-">O-</option>
                            </select>
                        </div>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>National ID (7-8 digits)</label>
                    <input type="text" name="national_id" class="form-control" placeholder="1234567" maxlength="8">
                </div>
                
                <div class="form-group">
                    <label>Address</label>
                    <textarea name="address" class="form-control" rows="2" maxlength="500"></textarea>
                </div>

                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label>Visit Type *</label>
                            <select name="visit_type" id="regVisitType" class="form-control" required>
                                <option value="regular">Regular OPD</option>
                                <option value="emergency">Emergency</option>
                                <option value="follow_up">Follow-up</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label>Department (Default: OPD)</label>
                            <select name="department_id" id="regDepartment" class="form-control" required>
                                <option value="">Loading...</option>
                            </select>
                            <small class="text-muted">OPD department will be selected by default</small>
                        </div>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Doctor (Optional - Auto-assign if empty)</label>
                    <select name="doctor_id" id="regDoctor" class="form-control">
                        <option value="0">Auto-assign Doctor</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>Clinical Routing Preference</label>
                    <div class="radio-group">
                        <label>
                            <input type="radio" name="routing" value="triage" checked> 
                            <i class="fas fa-stethoscope"></i> Send to Triage
                        </label>
                        <label>
                            <input type="radio" name="routing" value="doctor"> 
                            <i class="fas fa-user-md"></i> Send to Doctor
                        </label>
                    </div>
                </div>

                <div class="form-group" id="cardFeeCheckboxGroup">
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input" id="card_fee_collected" name="card_fee_collected" value="1" checked>
                        <label class="form-check-label" for="card_fee_collected">
                            <i class="fas fa-money-bill-wave"></i> Confirm Card Fee Payment (50 ETB)
                        </label>
                    </div>
                    <div id="cardFeeInfo" class="card-fee-info card-fee-mandatory mt-2">
                        <i class="fas fa-info-circle me-2"></i>
                        <span id="cardFeeMessage">Card fee is mandatory for new patients</span>
                    </div>
                </div>
                
                <div id="visitTypeInfo" class="alert alert-info" style="background:#e0f0fa; border-radius:12px; padding:12px; font-size:12px; display:none;">
                    <i class="fas fa-info-circle me-2"></i> 
                    <span id="visitTypeMessage"></span>
                </div>
                
                <input type="hidden" name="csrf_token" value="<?php echo sanitizeOutput($csrf_token); ?>">
                <input type="hidden" name="branch_id" value="<?php echo (int)$branch_id; ?>">
            </form>
        </div>
        <div class="modal-footer">
            <button class="quick-btn quick-btn-outline" onclick="closeModal('registerPatientModal')">Cancel</button>
            <button class="quick-btn quick-btn-primary" onclick="submitRegisterPatient()">Register Patient</button>
        </div>
    </div>
</div>

<!-- MODAL: DIRECT CHECK-IN -->
<div id="directCheckInModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h4><i class="fas fa-bolt me-2"></i> Direct Check-In</h4>
            <button class="close-modal" onclick="closeModal('directCheckInModal')">&times;</button>
        </div>
        <div class="modal-body">
            <form id="directCheckInForm">
                <div class="form-group">
                    <label>Search Patient</label>
                    <input type="text" id="directSearch" placeholder="Type name or MR number..." class="form-control">
                    <input type="hidden" name="patient_id" id="directPatientId">
                </div>
                <div id="directSearchResults" class="search-results-list"></div>
                
                <div class="form-group">
                    <label>Department (Default: OPD)</label>
                    <select name="department_id" id="directDepartmentId" class="form-control" required>
                        <option value="">Select Department</option>
                    </select>
                    <small class="text-muted">OPD department will be selected by default</small>
                </div>
                
                <div class="form-group">
                    <label>Doctor (Optional)</label>
                    <select name="doctor_id" id="directDoctorId" class="form-control">
                        <option value="0">Auto-assign Doctor</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Clinical Routing</label>
                    <div class="radio-group">
                        <label>
                            <input type="radio" name="routing" value="triage" checked> 
                            <i class="fas fa-stethoscope"></i> Send to Triage
                        </label>
                        <label>
                            <input type="radio" name="routing" value="doctor"> 
                            <i class="fas fa-user-md"></i> Send to Doctor
                        </label>
                    </div>
                </div>
                
                <div class="form-group">
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input" id="direct_card_fee_collected" name="card_fee_collected" value="1">
                        <label class="form-check-label" for="direct_card_fee_collected">
                            <i class="fas fa-money-bill-wave"></i> Card fee collected (50 ETB)
                        </label>
                    </div>
                    <div id="directCardFeeInfo" class="card-fee-info card-fee-mandatory mt-2" style="display:none;">
                        <i class="fas fa-info-circle me-2"></i>
                        <span id="directCardFeeMessage">Checking fee status...</span>
                    </div>
                </div>
                
                <div id="reactivationWarning" style="display:none;" class="alert alert-warning mt-2">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    <span id="reactivationMessage"></span>
                </div>
                
                <input type="hidden" name="csrf_token" value="<?php echo sanitizeOutput($csrf_token); ?>">
                <input type="hidden" name="branch_id" value="<?php echo (int)$branch_id; ?>">
            </form>
        </div>
        <div class="modal-footer">
            <button class="quick-btn quick-btn-outline" onclick="closeModal('directCheckInModal')">Cancel</button>
            <button class="quick-btn quick-btn-primary" onclick="submitDirectCheckIn()">Check In Now</button>
        </div>
    </div>
</div>

<!-- MODAL: EMERGENCY CHECK-IN -->
<div id="emergencyCheckInModal" class="modal">
    <div class="modal-content">
        <div class="modal-header" style="background: #fee2e2; border-bottom-color: var(--danger);">
            <h4><i class="fas fa-ambulance me-2" style="color:var(--danger);"></i> Emergency Check-In</h4>
            <button class="close-modal" onclick="closeModal('emergencyCheckInModal')">&times;</button>
        </div>
        <div class="modal-body">
            <div class="alert alert-danger mb-3">
                <i class="fas fa-exclamation-triangle me-2"></i>
                <strong>Emergency Mode:</strong> Card fee will be deferred. Patient will be routed directly to emergency care.
            </div>
            <form id="emergencyCheckInForm">
                <div class="form-group">
                    <label>Search Patient</label>
                    <input type="text" id="emergencySearch" placeholder="Type name or MR number..." class="form-control">
                    <input type="hidden" name="patient_id" id="emergencyPatientId">
                </div>
                <div id="emergencySearchResults" class="search-results-list"></div>
                
                <div class="form-group">
                    <label>Department (Default: Emergency)</label>
                    <select name="department_id" id="emergencyDepartmentId" class="form-control" required>
                        <option value="">Select Department</option>
                    </select>
                    <small class="text-muted">Will default to Emergency department</small>
                </div>
                
                <div class="form-group">
                    <label>Doctor (Optional)</label>
                    <select name="doctor_id" id="emergencyDoctorId" class="form-control">
                        <option value="0">Auto-assign Doctor</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Emergency Priority</label>
                    <select name="emergency_priority" class="form-control">
                        <option value="red">🔴 Critical (Red)</option>
                        <option value="yellow" selected>🟡 Urgent (Yellow)</option>
                        <option value="green">🟢 Stable (Green)</option>
                        <option value="blue">🔵 Non-urgent (Blue)</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input" id="emergency_consent" required>
                        <label class="form-check-label" for="emergency_consent">
                            <i class="fas fa-check-circle"></i> I confirm this is an emergency case requiring immediate attention
                        </label>
                    </div>
                </div>
                
                <input type="hidden" name="csrf_token" value="<?php echo sanitizeOutput($csrf_token); ?>">
                <input type="hidden" name="branch_id" value="<?php echo (int)$branch_id; ?>">
                <input type="hidden" name="is_emergency" value="1">
            </form>
        </div>
        <div class="modal-footer">
            <button class="quick-btn quick-btn-outline" onclick="closeModal('emergencyCheckInModal')">Cancel</button>
            <button class="quick-btn quick-btn-danger" onclick="submitEmergencyCheckIn()">
                <i class="fas fa-ambulance"></i> Emergency Check-In
            </button>
        </div>
    </div>
</div>

<script>
// =============================================================================
// GLOBALS & UTILITIES
// =============================================================================
const branchId = <?php echo (int)$branch_id; ?>;
const isGlobalAdmin = <?php echo $is_global_admin ? 'true' : 'false'; ?>;
const csrfToken = <?php echo json_encode($csrf_token); ?>;
const ajaxUrl = window.location.pathname + '?ajax=dashboard_action';

let pendingFeesTable = null;
let searchDebounceTimer = null;
let autoRefreshInterval = null;

function showToast(message, type = 'success') {
    const toast = document.createElement('div');
    toast.className = `toast-notification ${type === 'error' ? 'toast-error' : ''}`;
    toast.innerHTML = `<i class="fas fa-${type === 'error' ? 'exclamation-circle' : 'check-circle'}"></i> <span>${escapeHtml(message)}</span>`;
    document.body.appendChild(toast);
    setTimeout(() => toast.remove(), 4000);
}

function showLoading(show) {
    const overlay = document.getElementById('loadingOverlay');
    if (overlay) overlay.classList.toggle('active', show);
}

function escapeHtml(str) {
    if (!str) return '';
    return String(str).replace(/[&<>]/g, m => m === '&' ? '&amp;' : (m === '<' ? '&lt;' : '&gt;'));
}

function openModal(modalId) {
    document.getElementById(modalId).classList.add('active');
}

function closeModal(modalId) {
    document.getElementById(modalId).classList.remove('active');
}

document.querySelectorAll('.modal').forEach(modal => {
    modal.addEventListener('click', (e) => {
        if (e.target === modal) modal.classList.remove('active');
    });
});

// =============================================================================
// AGE/BIRTH DATE CALCULATION
// =============================================================================
document.addEventListener('DOMContentLoaded', function() {
    const ageInput = document.getElementById('regAge');
    const birthDateInput = document.getElementById('regBirthDate');
    
    if (ageInput && birthDateInput) {
        ageInput.addEventListener('input', function() {
            const age = parseInt(this.value);
            if (age && age > 0 && age <= 150) {
                const today = new Date();
                const birthDate = new Date(today.getFullYear() - age, today.getMonth(), today.getDate());
                const year = birthDate.getFullYear();
                const month = String(birthDate.getMonth() + 1).padStart(2, '0');
                const day = String(birthDate.getDate()).padStart(2, '0');
                birthDateInput.value = `${year}-${month}-${day}`;
            }
        });
        
        birthDateInput.addEventListener('change', function() {
            if (this.value) {
                const birthDate = new Date(this.value);
                const today = new Date();
                let age = today.getFullYear() - birthDate.getFullYear();
                const m = today.getMonth() - birthDate.getMonth();
                if (m < 0 || (m === 0 && today.getDate() < birthDate.getDate())) {
                    age--;
                }
                if (age >= 0 && age <= 150) {
                    ageInput.value = age;
                }
            }
        });
    }
});

// =============================================================================
// FETCH FUNCTIONS
// =============================================================================
function fetchDashboardData() {
    $.ajax({
        url: ajaxUrl,
        method: 'POST',
        dataType: 'json',
        data: { 
            action: 'dashboard_data', 
            branch_id: branchId, 
            csrf_token: csrfToken 
        },
        success: res => {
            if (res.success && res.data) {
                const d = res.data;
                
                document.getElementById('kpiRegistrationsToday').textContent = (d.registrations_today ?? 0).toLocaleString();
                document.getElementById('kpiAwaitingCardFee').textContent = (d.awaiting_card_fee ?? 0).toLocaleString();
                document.getElementById('kpiEmergencyPending').textContent = (d.emergency_pending_card_fee ?? 0).toLocaleString();
                document.getElementById('kpiEmergencyToday').textContent = (d.emergency_today ?? 0).toLocaleString();
                document.getElementById('kpiInConsult').textContent = (d.in_consultation ?? 0).toLocaleString();
                document.getElementById('kpiActiveAdmissions').textContent = (d.active_admissions ?? 0).toLocaleString();
                document.getElementById('kpiTodayAppointments').textContent = (d.today_appointments ? d.today_appointments.length : 0).toLocaleString();
                
                const emergencyCount = document.getElementById('emergencyQueueCount');
                if (emergencyCount && d.emergency_queue) {
                    emergencyCount.textContent = d.emergency_queue.length;
                }
                
                renderNurseQueue(d.nurse_queue || []);
                renderDoctorQueue(d.doctor_queue || []);
                renderEmergencyQueue(d.emergency_queue || []);
                renderAppointments(d.today_appointments || []);
                renderDoctors(d.doctor_availability || []);
                renderRecentActivity(d.recent_activity || []);
                renderInsuranceStatus(d.insurance_status || {});
                renderDepartmentDistribution(d.department_distribution || []);
            }
        },
        error: (xhr, status, error) => {
            console.error('Dashboard data error:', error);
        }
    });
}

function renderNurseQueue(queue) {
    const tbody = document.getElementById('nurseQueueBody');
    if (!tbody) return;
    
    if (!queue || queue.length === 0) {
        tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted">No pending patients for triage</td></tr>';
        return;
    }
    
    tbody.innerHTML = queue.map(row => `
        <tr>
            <td>${row.created_at ? new Date(row.created_at).toLocaleTimeString([], {hour:'2-digit', minute:'2-digit'}) : 'N/A'}</td>
            <td><strong>${escapeHtml(row.patient_name)}</strong></td>
            <td><span class="small text-muted">${escapeHtml(row.mr_number)}</span></td>
            <td>${escapeHtml(row.phone || 'N/A')}</td>
            <td>${escapeHtml(row.department_name || 'N/A')}</td>
            <td>
                <button class="quick-btn quick-btn-primary" style="padding:6px 12px;font-size:12px" onclick="startConsultation(${row.visit_id})">
                    <i class="fas fa-stethoscope"></i> Start Triage
                </button>
            </td>
        </tr>
    `).join('');
}

function renderDoctorQueue(queue) {
    const tbody = document.getElementById('doctorQueueBody');
    if (!tbody) return;
    
    if (!queue || queue.length === 0) {
        tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted">No patients ready for doctor consultation</td></tr>';
        return;
    }
    
    tbody.innerHTML = queue.map((row, idx) => {
        let priorityHtml = '';
        const priority = (row.priority || '').toLowerCase();
        
        if (priority === 'stat') {
            priorityHtml = '<span class="emergency-alert"><i class="fas fa-exclamation-triangle me-1"></i>EMERGENCY</span>';
        } else if (priority === 'urgent') {
            priorityHtml = '<span class="badge badge-warning">Urgent</span>';
        } else {
            priorityHtml = '<span class="badge badge-success">Normal</span>';
        }
        
        return `
            <tr>
                <td>${idx + 1}</td>
                <td>
                    <strong>${escapeHtml(row.patient_name)}</strong><br>
                    <span class="small text-muted">${escapeHtml(row.mr_number)}</span>
                </td>
                <td>${escapeHtml(row.phone || 'N/A')}</td>
                <td>${priorityHtml}</td>
                <td>${escapeHtml(row.department_name || 'N/A')}</td>
                <td>
                    <button class="quick-btn quick-btn-primary" style="padding:6px 12px;font-size:12px" onclick="startConsultation(${row.visit_id})">
                        <i class="fas fa-play"></i> Start Consultation
                    </button>
                </td>
            </tr>
        `;
    }).join('');
}

function renderEmergencyQueue(queue) {
    const tbody = document.getElementById('emergencyQueueBody');
    if (!tbody) return;
    
    if (!queue || queue.length === 0) {
        tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted">No emergency patients in queue</td></tr>';
        return;
    }
    
    tbody.innerHTML = queue.map(row => {
        const feeStatus = row.card_fee_status === 'paid' 
            ? '<span class="badge badge-success">Paid</span>' 
            : '<span class="badge badge-danger"><i class="fas fa-hourglass-half"></i> Deferred</span>';
        
        const feeAction = row.card_fee_status === 'unpaid' && row.invoice_id > 0
            ? `<button class="btn btn-warning btn-sm" onclick="settleEmergencyFee(${row.invoice_id}, '${escapeHtml(row.patient_name)}')">
                <i class="fas fa-money-bill-wave"></i> Settle Fee
               </button>`
            : '<span class="text-muted small">N/A</span>';
        
        const waitingTime = row.waiting_minutes || 0;
        const waitingClass = waitingTime > 15 ? 'text-danger fw-bold' : 'text-muted';
        
        return `
            <tr>
                <td>${row.created_at ? new Date(row.created_at).toLocaleTimeString([], {hour:'2-digit', minute:'2-digit'}) : 'N/A'}</td>
                <td>
                    <strong>${escapeHtml(row.patient_name)}</strong>
                    ${row.is_priority ? '<span class="emergency-alert ms-1">STAT</span>' : ''}
                </td>
                <td><span class="small text-muted">${escapeHtml(row.mr_number)}</span></td>
                <td>${escapeHtml(row.phone || 'N/A')}</td>
                <td>${escapeHtml(row.department_name || 'Emergency')}</td>
                <td>${feeStatus} <span class="small ${waitingClass}">(${waitingTime} min)</span></td>
                <td>
                    <div class="d-flex gap-1 flex-wrap">
                        ${feeAction}
                        ${row.status !== 'in_consultation' ? `<button class="btn btn-danger btn-sm" onclick="startConsultation(${row.visit_id})"><i class="fas fa-ambulance"></i> Rush</button>` : ''}
                    </div>
                </td>
            </tr>
        `;
    }).join('');
}

function renderAppointments(appointments) {
    const tbody = document.getElementById('appointmentsBody');
    if (!tbody) return;
    
    if (!appointments || appointments.length === 0) {
        tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted">No appointments scheduled for today</td></tr>';
        return;
    }
    
    const statusMap = {
        'scheduled': 'badge-secondary',
        'checked_in': 'badge-primary',
        'in_progress': 'badge-warning',
        'completed': 'badge-success',
        'cancelled': 'badge-danger'
    };
    
    tbody.innerHTML = appointments.map(row => `
        <tr>
            <td>${row.appointment_time ? row.appointment_time.substring(0,5) : 'N/A'}</td>
            <td><strong>${escapeHtml(row.patient_name)}</strong></td>
            <td><span class="small text-muted">${escapeHtml(row.mr_number)}</span></td>
            <td>${escapeHtml(row.department_name || 'N/A')}</td>
            <td>${escapeHtml(row.doctor_name || 'Unassigned')}</td>
            <td><span class="status-badge ${statusMap[row.status] || 'badge-secondary'}">${escapeHtml(row.status)}</span></td>
        </tr>
    `).join('');
}

function renderDoctors(doctors) {
    const grid = document.getElementById('doctorGrid');
    if (!grid) return;
    
    if (!doctors || doctors.length === 0) {
        grid.innerHTML = '<div class="text-center py-4 text-muted">No doctor schedules found for today.</div>';
        return;
    }
    
    grid.innerHTML = doctors.map(doc => `
        <div class="doctor-card">
            <div class="doctor-info">
                <div class="doctor-name">
                    <span class="doctor-status"></span>
                    Dr. ${escapeHtml(doc.doctor_name)}
                </div>
                <div class="doctor-specialty">${escapeHtml(doc.specialist || 'General Medicine')}</div>
            </div>
            <div class="doctor-badge">
                <i class="fas fa-user me-1"></i> ${doc.todays_patients || 0} patients
            </div>
        </div>
    `).join('');
}

function renderRecentActivity(activities) {
    const container = document.getElementById('recentActivity');
    if (!container) return;
    
    if (!activities || activities.length === 0) {
        container.innerHTML = '<div class="text-muted text-center">No recent activity</div>';
        return;
    }
    
    container.innerHTML = activities.map(a => `
        <div class="activity-item">
            <div class="d-flex justify-content-between">
                <div>
                    <strong>${escapeHtml(a.first_name)} ${escapeHtml(a.last_name)}</strong>
                    <span class="small text-muted">(MRN: ${escapeHtml(a.mr_number)})</span>
                </div>
                <span class="activity-time">${a.created_at ? new Date(a.created_at).toLocaleTimeString([], {hour:'2-digit', minute:'2-digit'}) : 'N/A'}</span>
            </div>
            <div class="small text-muted">Registered by ${escapeHtml(a.registered_by || 'System')}</div>
        </div>
    `).join('');
}

function renderInsuranceStatus(status) {
    const container = document.getElementById('insuranceStatus');
    if (!container) return;
    
    const data = status || {};
    const total = (data.pending_auth || 0) + (data.authorized || 0) + (data.denied || 0) + (data.not_applicable || 0);
    
    if (total === 0) {
        container.innerHTML = '<div class="text-muted text-center">No insurance claims data for today</div>';
        return;
    }
    
    container.innerHTML = `
        <div class="row">
            <div class="col-6">
                <div class="d-flex justify-content-between py-1"><span>Pending Authorization</span><span class="badge badge-warning">${data.pending_auth || 0}</span></div>
                <div class="d-flex justify-content-between py-1"><span>Authorized</span><span class="badge badge-success">${data.authorized || 0}</span></div>
            </div>
            <div class="col-6">
                <div class="d-flex justify-content-between py-1"><span>Denied</span><span class="badge badge-danger">${data.denied || 0}</span></div>
                <div class="d-flex justify-content-between py-1"><span>Not Applicable</span><span class="badge badge-secondary">${data.not_applicable || 0}</span></div>
            </div>
        </div>
        <div class="mt-2 small text-muted">Total claims processed today: ${total}</div>
    `;
}

function renderDepartmentDistribution(distribution) {
    const container = document.getElementById('departmentDistribution');
    if (!container) return;
    
    if (!distribution || distribution.length === 0) {
        container.innerHTML = '<div class="text-muted text-center">No department data for today</div>';
        return;
    }
    
    const total = distribution.reduce((sum, d) => sum + (d.patient_count || 0), 0);
    
    container.innerHTML = distribution.map(d => {
        const pct = total > 0 ? Math.round((d.patient_count / total) * 100) : 0;
        return `
            <div class="d-flex justify-content-between align-items-center py-1">
                <span>${escapeHtml(d.department_name)}</span>
                <span class="d-flex align-items-center gap-2">
                    <span class="department-badge">${d.patient_count || 0} patients</span>
                    <span class="small text-muted">${pct}%</span>
                </span>
            </div>
            <div class="progress" style="height:4px; margin-bottom:6px;">
                <div class="progress-bar bg-primary" style="width:${pct}%"></div>
            </div>
        `;
    }).join('');
}

function fetchQueues() {
    fetchDashboardData();
}

// =============================================================================
// CARD FEE CHECK FUNCTION
// =============================================================================
function checkCardFeeMandatory(patientId, callback) {
    $.ajax({
        url: ajaxUrl,
        method: 'POST',
        dataType: 'json',
        data: {
            action: 'check_card_fee_mandatory',
            patient_id: patientId,
            branch_id: branchId,
            csrf_token: csrfToken
        },
        success: res => {
            if (res.success) {
                callback(res.is_mandatory, res.message);
            } else {
                callback(true, 'Unable to check fee status. Card fee required.');
            }
        },
        error: () => {
            callback(true, 'Network error. Card fee required by default.');
        }
    });
}

// =============================================================================
// EMERGENCY FUNCTIONS
// =============================================================================
function settleEmergencyFee(invoiceId, patientName) {
    Swal.fire({
        title: 'Settle Emergency Fee',
        text: `Collect card fee (50 ETB) for emergency patient ${patientName}?`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Yes, collect payment',
        confirmButtonColor: '#ef476f',
        cancelButtonText: 'Cancel'
    }).then(result => {
        if (result.isConfirmed) {
            showLoading(true);
            
            $.ajax({
                url: ajaxUrl,
                method: 'POST',
                dataType: 'json',
                data: {
                    action: 'settle_card_fee',
                    invoice_id: invoiceId,
                    branch_id: branchId,
                    csrf_token: csrfToken
                },
                success: res => {
                    showLoading(false);
                    if (res.success) {
                        Swal.fire('Success', res.message, 'success');
                        fetchDashboardData();
                        if (pendingFeesTable) {
                            pendingFeesTable.ajax.reload(null, false);
                        }
                    } else {
                        Swal.fire('Error', res.message, 'error');
                    }
                },
                error: () => {
                    showLoading(false);
                    Swal.fire('Error', 'Network error', 'error');
                }
            });
        }
    });
}

// =============================================================================
// START CONSULTATION
// =============================================================================
function startConsultation(visitId) {
    Swal.fire({
        title: 'Start Consultation?',
        text: 'This patient will be moved to active consultation status.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Yes, start consultation',
        confirmButtonColor: '#4361ee',
        cancelButtonText: 'Cancel'
    }).then(result => {
        if (result.isConfirmed) {
            showLoading(true);
            
            $.ajax({
                url: ajaxUrl,
                method: 'POST',
                dataType: 'json',
                data: {
                    action: 'start_consultation',
                    visit_id: visitId,
                    branch_id: branchId,
                    csrf_token: csrfToken
                },
                success: res => {
                    showLoading(false);
                    if (res.success) {
                        showToast(res.message);
                        fetchDashboardData();
                    } else {
                        showToast(res.message || 'Failed to start consultation', 'error');
                    }
                },
                error: () => {
                    showLoading(false);
                    showToast('Network error', 'error');
                }
            });
        }
    });
}

function fetchAllData() {
    fetchDashboardData();
    if (pendingFeesTable) {
        pendingFeesTable.ajax.reload(null, false);
    }
    showToast('Dashboard refreshed', 'success');
}

// =============================================================================
// SEARCH FUNCTIONS
// =============================================================================
const globalSearch = document.getElementById('globalSearch');
const liveResults = document.getElementById('liveSearchResults');

if (globalSearch) {
    globalSearch.addEventListener('input', function() {
        clearTimeout(searchDebounceTimer);
        const query = this.value.trim();
        
        if (query.length < 2) {
            liveResults.style.display = 'none';
            return;
        }
        
        searchDebounceTimer = setTimeout(() => {
            $.ajax({
                url: ajaxUrl,
                method: 'POST',
                dataType: 'json',
                data: {
                    action: 'search_patients',
                    query: query,
                    branch_id: branchId,
                    csrf_token: csrfToken
                },
                success: res => {
                    if (!res.success || !res.patients || res.patients.length === 0) {
                        liveResults.innerHTML = '<div class="p-3 text-center text-muted">No patients found.</div>';
                        liveResults.style.display = 'block';
                        return;
                    }
                    
                    liveResults.innerHTML = res.patients.map(p => {
                        const feeStatus = p.card_fee_mandatory ? 
                            '<span class="badge badge-danger ms-2">Fee Mandatory</span>' : 
                            '<span class="badge badge-success ms-2">Fee Optional</span>';
                        return `
                            <div class="search-result-item">
                                <div class="fw-bold">${escapeHtml(p.name)} ${feeStatus}</div>
                                <div class="small text-muted">MRN: ${escapeHtml(p.mr_number || 'N/A')} | Phone: ${escapeHtml(p.phone || 'N/A')} | Age: ${p.age || 'N/A'}</div>
                                <div class="search-result-actions">
                                    <button class="action-btn action-btn-checkin" onclick="openCheckInModal(${p.id}, '${escapeHtml(p.name)}', ${p.card_fee_mandatory})">
                                        <i class="fas fa-sign-in-alt"></i> Check-In
                                    </button>
                                    <button class="action-btn action-btn-emergency" onclick="openEmergencyCheckInModal(${p.id}, '${escapeHtml(p.name)}')">
                                        <i class="fas fa-ambulance"></i> Emergency
                                    </button>
                                </div>
                            </div>
                        `;
                    }).join('');
                    liveResults.style.display = 'block';
                }
            });
        }, 300);
    });
}

document.addEventListener('click', (e) => {
    if (globalSearch && liveResults && !globalSearch.contains(e.target) && !liveResults.contains(e.target)) {
        liveResults.style.display = 'none';
    }
});

function openCheckInModal(patientId, patientName, isFeeMandatory) {
    document.getElementById('directPatientId').value = patientId;
    document.getElementById('directSearch').value = patientName;
    
    // Update card fee info
    const feeInfo = document.getElementById('directCardFeeInfo');
    const feeMessage = document.getElementById('directCardFeeMessage');
    
    if (isFeeMandatory) {
        feeInfo.className = 'card-fee-info card-fee-mandatory mt-2';
        feeInfo.style.display = 'block';
        feeMessage.textContent = 'Card fee is mandatory (last visit > 2 months ago)';
        document.getElementById('direct_card_fee_collected').checked = false;
        document.getElementById('direct_card_fee_collected').required = true;
    } else {
        feeInfo.className = 'card-fee-info card-fee-optional mt-2';
        feeInfo.style.display = 'block';
        feeMessage.textContent = 'Card fee is optional (visited within 2 months)';
        document.getElementById('direct_card_fee_collected').checked = false;
        document.getElementById('direct_card_fee_collected').required = false;
    }
    
    loadDepartments('directDepartmentId');
    openModal('directCheckInModal');
}

function openEmergencyCheckInModal(patientId, patientName) {
    document.getElementById('emergencyPatientId').value = patientId;
    document.getElementById('emergencySearch').value = patientName;
    loadDepartments('emergencyDepartmentId');
    openModal('emergencyCheckInModal');
}

// =============================================================================
// DIRECT CHECK-IN SEARCH
// =============================================================================
const directSearch = document.getElementById('directSearch');
const directResults = document.getElementById('directSearchResults');

if (directSearch) {
    directSearch.addEventListener('input', function() {
        clearTimeout(searchDebounceTimer);
        const query = this.value.trim();
        
        if (query.length < 2) {
            directResults.style.display = 'none';
            return;
        }
        
        searchDebounceTimer = setTimeout(() => {
            $.ajax({
                url: ajaxUrl,
                method: 'POST',
                dataType: 'json',
                data: {
                    action: 'search_patients',
                    query: query,
                    branch_id: branchId,
                    csrf_token: csrfToken
                },
                success: res => {
                    if (!res.success || !res.patients || res.patients.length === 0) {
                        directResults.innerHTML = '<div class="p-3 text-muted">No patients found</div>';
                        directResults.style.display = 'block';
                        return;
                    }
                    
                    directResults.innerHTML = res.patients.map(p => `
                        <div class="search-result-item" onclick="selectDirectPatient(${p.id}, '${escapeHtml(p.name)}', ${p.card_fee_mandatory})">
                            <div class="fw-bold">${escapeHtml(p.name)}</div>
                            <div class="small text-muted">MRN: ${escapeHtml(p.mr_number)} | ${escapeHtml(p.phone || '')}</div>
                        </div>
                    `).join('');
                    directResults.style.display = 'block';
                }
            });
        }, 300);
    });
}

function selectDirectPatient(id, name, isFeeMandatory) {
    document.getElementById('directPatientId').value = id;
    document.getElementById('directSearch').value = name;
    document.getElementById('directSearchResults').style.display = 'none';
    
    // Update card fee info
    const feeInfo = document.getElementById('directCardFeeInfo');
    const feeMessage = document.getElementById('directCardFeeMessage');
    
    if (isFeeMandatory) {
        feeInfo.className = 'card-fee-info card-fee-mandatory mt-2';
        feeInfo.style.display = 'block';
        feeMessage.textContent = 'Card fee is mandatory (last visit > 2 months ago)';
        document.getElementById('direct_card_fee_collected').checked = false;
        document.getElementById('direct_card_fee_collected').required = true;
    } else {
        feeInfo.className = 'card-fee-info card-fee-optional mt-2';
        feeInfo.style.display = 'block';
        feeMessage.textContent = 'Card fee is optional (visited within 2 months)';
        document.getElementById('direct_card_fee_collected').checked = false;
        document.getElementById('direct_card_fee_collected').required = false;
    }
}

// =============================================================================
// EMERGENCY CHECK-IN SEARCH
// =============================================================================
const emergencySearch = document.getElementById('emergencySearch');
const emergencyResults = document.getElementById('emergencySearchResults');

if (emergencySearch) {
    emergencySearch.addEventListener('input', function() {
        clearTimeout(searchDebounceTimer);
        const query = this.value.trim();
        
        if (query.length < 2) {
            emergencyResults.style.display = 'none';
            return;
        }
        
        searchDebounceTimer = setTimeout(() => {
            $.ajax({
                url: ajaxUrl,
                method: 'POST',
                dataType: 'json',
                data: {
                    action: 'search_patients',
                    query: query,
                    branch_id: branchId,
                    csrf_token: csrfToken
                },
                success: res => {
                    if (!res.success || !res.patients || res.patients.length === 0) {
                        emergencyResults.innerHTML = '<div class="p-3 text-muted">No patients found</div>';
                        emergencyResults.style.display = 'block';
                        return;
                    }
                    
                    emergencyResults.innerHTML = res.patients.map(p => `
                        <div class="search-result-item" onclick="selectEmergencyPatient(${p.id}, '${escapeHtml(p.name)}')">
                            <div class="fw-bold">${escapeHtml(p.name)}</div>
                            <div class="small text-muted">MRN: ${escapeHtml(p.mr_number)} | ${escapeHtml(p.phone || '')}</div>
                        </div>
                    `).join('');
                    emergencyResults.style.display = 'block';
                }
            });
        }, 300);
    });
}

function selectEmergencyPatient(id, name) {
    document.getElementById('emergencyPatientId').value = id;
    document.getElementById('emergencySearch').value = name;
    document.getElementById('emergencySearchResults').style.display = 'none';
}

// =============================================================================
// DEPARTMENT/DOCTOR LOADERS
// =============================================================================
async function loadDepartments(selectId) {
    return new Promise((resolve) => {
        $.ajax({
            url: ajaxUrl,
            method: 'POST',
            dataType: 'json',
            data: { 
                action: 'get_departments', 
                branch_id: branchId, 
                csrf_token: csrfToken 
            },
            success: res => {
                const sel = document.getElementById(selectId);
                if (!sel) return;
                
                // Keep OPD as default if available
                sel.innerHTML = '<option value="">Select Department</option>';
                
                if (res.success && res.departments) {
                    res.departments.forEach(d => {
                        const opt = document.createElement('option');
                        opt.value = d.id;
                        opt.textContent = d.name;
                        // Set OPD (ID 1) as default selected
                        if (d.id == 1) {
                            opt.selected = true;
                        }
                        sel.appendChild(opt);
                    });
                }
                resolve(res);
            },
            error: () => resolve(false)
        });
    });
}

function loadDoctors(selectId, deptId) {
    if (!deptId) return;
    
    $.ajax({
        url: ajaxUrl,
        method: 'POST',
        dataType: 'json',
        data: { 
            action: 'get_doctors_by_department', 
            department_id: deptId, 
            branch_id: branchId, 
            csrf_token: csrfToken 
        },
        success: res => {
            const sel = document.getElementById(selectId);
            if (!sel) return;
            
            sel.innerHTML = '<option value="0">Auto-assign Doctor</option>';
            
            if (res.success && res.doctors) {
                res.doctors.forEach(doc => {
                    sel.innerHTML += `<option value="${doc.id}">Dr. ${escapeHtml(doc.name)} (${escapeHtml(doc.specialist)})</option>`;
                });
            }
        },
        error: () => {
            const sel = document.getElementById(selectId);
            if (sel) sel.innerHTML = '<option value="0">Auto-assign Doctor</option>';
        }
    });
}

// =============================================================================
// SUBMIT FUNCTIONS
// =============================================================================
function submitDirectCheckIn() {
    const patientId = document.getElementById('directPatientId').value;
    const departmentId = document.getElementById('directDepartmentId').value;
    const doctorId = document.getElementById('directDoctorId')?.value || '0';
    const routing = document.querySelector('#directCheckInForm input[name="routing"]:checked')?.value || 'triage';
    const cardFeeCollected = document.getElementById('direct_card_fee_collected')?.checked ? '1' : '0';
    
    if (!patientId || !departmentId) {
        showToast('Patient and department are required', 'error');
        return;
    }
    
    // Check if card fee is mandatory
    const isFeeMandatory = document.getElementById('direct_card_fee_collected').required;
    if (isFeeMandatory && cardFeeCollected === '0') {
        showToast('Card fee is mandatory for this patient. Please collect payment.', 'error');
        return;
    }
    
    showLoading(true);
    
    $.ajax({
        url: ajaxUrl,
        method: 'POST',
        dataType: 'json',
        data: {
            action: 'register_direct_visit',
            patient_id: patientId,
            department_id: departmentId,
            doctor_id: doctorId,
            routing: routing,
            card_fee_collected: cardFeeCollected,
            is_emergency: 0,
            branch_id: branchId,
            csrf_token: csrfToken
        },
        success: res => {
            showLoading(false);
            if (res.success) {
                showToast(res.message);
                closeModal('directCheckInModal');
                fetchDashboardData();
                if (pendingFeesTable) {
                    pendingFeesTable.ajax.reload(null, false);
                }
                document.getElementById('directCheckInForm').reset();
                document.getElementById('directPatientId').value = '';
                document.getElementById('directSearch').value = '';
                document.getElementById('directCardFeeInfo').style.display = 'none';
            } else {
                showToast(res.message || 'Check-in failed', 'error');
            }
        },
        error: () => {
            showLoading(false);
            showToast('Network error', 'error');
        }
    });
}

function submitEmergencyCheckIn() {
    const patientId = document.getElementById('emergencyPatientId').value;
    const departmentId = document.getElementById('emergencyDepartmentId').value;
    const doctorId = document.getElementById('emergencyDoctorId')?.value || '0';
    const consent = document.getElementById('emergency_consent')?.checked;
    const emergencyPriority = document.querySelector('#emergencyCheckInForm select[name="emergency_priority"]')?.value || 'yellow';
    
    if (!patientId || !departmentId) {
        showToast('Patient and department are required', 'error');
        return;
    }
    
    if (!consent) {
        showToast('Please confirm this is an emergency case', 'error');
        return;
    }
    
    showLoading(true);
    
    $.ajax({
        url: ajaxUrl,
        method: 'POST',
        dataType: 'json',
        data: {
            action: 'register_direct_visit',
            patient_id: patientId,
            department_id: departmentId,
            doctor_id: doctorId,
            routing: 'triage',
            card_fee_collected: 0,
            is_emergency: 1,
            emergency_priority: emergencyPriority,
            branch_id: branchId,
            csrf_token: csrfToken
        },
        success: res => {
            showLoading(false);
            if (res.success) {
                Swal.fire({
                    icon: 'success',
                    title: 'Emergency Patient Checked In',
                    html: `
                        <div class="text-danger fw-bold mb-2"><i class="fas fa-ambulance"></i> EMERGENCY MODE</div>
                        <div>${escapeHtml(res.message)}</div>
                        <div class="mt-2 small text-muted">Card fee has been deferred. Please collect payment when patient is stable.</div>
                    `,
                    confirmButtonColor: '#ef476f'
                });
                closeModal('emergencyCheckInModal');
                fetchDashboardData();
                if (pendingFeesTable) {
                    pendingFeesTable.ajax.reload(null, false);
                }
                document.getElementById('emergencyCheckInForm').reset();
                document.getElementById('emergencyPatientId').value = '';
                document.getElementById('emergencySearch').value = '';
            } else {
                showToast(res.message || 'Emergency check-in failed', 'error');
            }
        },
        error: () => {
            showLoading(false);
            showToast('Network error', 'error');
        }
    });
}

function submitRegisterPatient() {
    const form = document.getElementById('registerPatientForm');
    const formData = new FormData(form);
    formData.append('action', 'register_patient');
    formData.append('branch_id', branchId);
    formData.append('csrf_token', csrfToken);
    
    const firstName = formData.get('first_name')?.trim();
    const lastName = formData.get('last_name')?.trim();
    const departmentId = formData.get('department_id');
    const email = formData.get('email')?.trim();
    const phone = formData.get('phone')?.trim();
    const nationalId = formData.get('national_id')?.trim();
    const age = formData.get('age')?.trim();
    
    // Validate required fields
    if (!firstName || !lastName) {
        showToast('First name and last name are required', 'error');
        return;
    }
    
    if (!departmentId) {
        showToast('Please select a department', 'error');
        return;
    }
    
    // Validate email if provided
    if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
        showToast('Please enter a valid email address', 'error');
        return;
    }
    
    // Validate phone if provided
    if (phone && !/^(09|2519|\+2519)\d{8}$/.test(phone)) {
        showToast('Please enter a valid phone number (09XXXXXXXX or +2519XXXXXXXX)', 'error');
        return;
    }
    
    // Validate national ID if provided
    if (nationalId && !/^\d{7,8}$/.test(nationalId)) {
        showToast('National ID must be 7-8 digits', 'error');
        return;
    }
    
    // Validate age if provided
    if (age && (isNaN(age) || age < 0 || age > 150)) {
        showToast('Age must be between 0 and 150', 'error');
        return;
    }
    
    showLoading(true);
    
    $.ajax({
        url: ajaxUrl,
        method: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        dataType: 'json',
        success: res => {
            showLoading(false);
            if (res.success) {
                const isEmergency = res.is_emergency || false;
                Swal.fire({
                    icon: 'success',
                    title: isEmergency ? 'Emergency Patient Registered' : 'Patient Registered Successfully',
                    html: `
                        <div><strong>MRN:</strong> ${escapeHtml(res.mr_number)}</div>
                        ${res.age ? `<div><strong>Age:</strong> ${res.age} years</div>` : ''}
                        ${res.birth_date ? `<div><strong>Birth Date:</strong> ${escapeHtml(res.birth_date)}</div>` : ''}
                        <div class="mt-2">${escapeHtml(res.message)}</div>
                        ${isEmergency && res.requires_payment ? `<div class="mt-2 text-danger"><i class="fas fa-info-circle"></i> Card fee deferred - collect when stable</div>` : ''}
                    `,
                    confirmButtonColor: isEmergency ? '#ef476f' : '#4361ee'
                });
                closeModal('registerPatientModal');
                form.reset();
                document.getElementById('regAge').value = '';
                document.getElementById('regBirthDate').value = '';
                updateVisitTypeUI();
                fetchDashboardData();
                if (pendingFeesTable) {
                    pendingFeesTable.ajax.reload(null, false);
                }
            } else {
                showToast(res.message || 'Registration failed', 'error');
            }
        },
        error: () => {
            showLoading(false);
            showToast('Network error', 'error');
        }
    });
}

// =============================================================================
// VISIT TYPE UI UPDATE
// =============================================================================
function updateVisitTypeUI() {
    const vt = document.getElementById('regVisitType');
    if (!vt) return;
    
    const value = vt.value;
    const cb = document.getElementById('card_fee_collected');
    const infoDiv = document.getElementById('visitTypeInfo');
    const msgSpan = document.getElementById('visitTypeMessage');
    
    if (value === 'emergency') {
        if (cb) {
            cb.checked = false;
            cb.disabled = true;
        }
        if (msgSpan) msgSpan.innerHTML = 'Emergency Mode Active: Card fee deferred for immediate treatment.';
        if (infoDiv) {
            infoDiv.style.display = 'block';
            infoDiv.className = 'alert alert-danger';
            infoDiv.style.background = '#fee2e2';
            infoDiv.style.borderColor = '#ef476f';
        }
        // Update card fee info
        const feeInfo = document.getElementById('cardFeeInfo');
        if (feeInfo) {
            feeInfo.className = 'card-fee-info card-fee-mandatory mt-2';
            document.getElementById('cardFeeMessage').textContent = 'Emergency: Card fee will be deferred';
        }
    } else {
        if (cb) {
            cb.disabled = false;
            cb.checked = true;
        }
        if (infoDiv) {
            infoDiv.style.display = 'none';
            infoDiv.className = 'alert alert-info';
            infoDiv.style.background = '#e0f0fa';
            infoDiv.style.borderColor = '#4cc9f0';
        }
        // Update card fee info for new patients
        const feeInfo = document.getElementById('cardFeeInfo');
        if (feeInfo) {
            feeInfo.className = 'card-fee-info card-fee-mandatory mt-2';
            document.getElementById('cardFeeMessage').textContent = 'Card fee is mandatory for new patients';
        }
    }
}

const regVisitType = document.getElementById('regVisitType');
if (regVisitType) {
    regVisitType.addEventListener('change', updateVisitTypeUI);
}

const regDepartment = document.getElementById('regDepartment');
if (regDepartment) {
    regDepartment.addEventListener('change', function() {
        loadDoctors('regDoctor', this.value);
    });
}

const directDepartment = document.getElementById('directDepartmentId');
if (directDepartment) {
    directDepartment.addEventListener('change', function() {
        loadDoctors('directDoctorId', this.value);
    });
}

const emergencyDepartment = document.getElementById('emergencyDepartmentId');
if (emergencyDepartment) {
    emergencyDepartment.addEventListener('change', function() {
        loadDoctors('emergencyDoctorId', this.value);
    });
}

// =============================================================================
// PENDING FEES TABLE - FIXED
// =============================================================================
function initPendingFeesTable() {
    pendingFeesTable = $('#pendingFeesTable').DataTable({
        processing: true,
        serverSide: true,
        ajax: {
            url: window.location.pathname + '?action=get_pending_card_fees',
            type: 'GET',
            dataType: 'json',
            data: function(d) {
                d.csrf_token = csrfToken;
                return d;
            },
            error: function(xhr, status, error) {
                console.error('DataTable Error:', status, error);
                console.log('Response:', xhr.responseText);
                $('#pendingFeesTable tbody').html('<tr><td colspan="7" class="text-center text-danger">Error loading data. Please refresh the page.</td></tr>');
            }
        },
        columns: [
            { data: 'patient_name' },
            { data: 'mr_number' },
            { data: 'branch' },
            { data: 'invoice_date' },
            { data: 'visit_type' },
            { data: 'amount' },
            { data: 'action', orderable: false, searchable: false }
        ],
        order: [[3, 'desc']],
        language: {
            emptyTable: 'No pending card fees found.',
            zeroRecords: 'No matching records found.',
            processing: '<div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div>'
        },
        pageLength: 15,
        lengthMenu: [[10, 15, 25, 50], [10, 15, 25, 50]],
        drawCallback: function() {
            // Re-initialize any tooltips or buttons after draw
            $('[data-bs-toggle="tooltip"]').tooltip();
        }
    });
    
    $('#pendingFeesTable').on('click', '.mark-paid-btn', function() {
        const invoiceId = $(this).data('invoice-id');
        const patientName = $(this).data('patient-name');
        const isEmergency = $(this).data('is-emergency') || 0;
        
        const title = isEmergency ? 'Settle Emergency Fee' : 'Confirm Settlement';
        const icon = isEmergency ? 'warning' : 'question';
        const text = isEmergency 
            ? `Collect card fee (50 ETB) for emergency patient ${patientName}? This will settle the deferred payment.`
            : `Mark card fee as paid for ${patientName}? This will release the patient to clinical queues.`;
        
        Swal.fire({
            title: title,
            text: text,
            icon: icon,
            showCancelButton: true,
            confirmButtonText: 'Yes, mark as paid',
            confirmButtonColor: isEmergency ? '#ef476f' : '#28a745',
            cancelButtonText: 'Cancel'
        }).then(result => {
            if (result.isConfirmed) {
                showLoading(true);
                
                $.ajax({
                    url: ajaxUrl,
                    method: 'POST',
                    dataType: 'json',
                    data: {
                        action: 'settle_card_fee',
                        invoice_id: invoiceId,
                        branch_id: branchId,
                        csrf_token: csrfToken
                    },
                    success: res => {
                        showLoading(false);
                        if (res.success) {
                            Swal.fire('Success', res.message, 'success');
                            pendingFeesTable.ajax.reload(null, false);
                            fetchDashboardData();
                        } else {
                            Swal.fire('Error', res.message, 'error');
                        }
                    },
                    error: () => {
                        showLoading(false);
                        Swal.fire('Error', 'Network error', 'error');
                    }
                });
            }
        });
    });
}

// =============================================================================
// AUTO REFRESH
// =============================================================================
function startAutoRefresh() {
    if (autoRefreshInterval) clearInterval(autoRefreshInterval);
    
    autoRefreshInterval = setInterval(() => {
        if (!document.hidden) {
            fetchDashboardData();
            if (pendingFeesTable && $('#pendingFeesTable').is(':visible')) {
                pendingFeesTable.ajax.reload(null, false);
            }
        }
    }, 30000);
}

// =============================================================================
// INIT
// =============================================================================
$(function() {
    loadDepartments('regDepartment');
    loadDepartments('directDepartmentId');
    loadDepartments('emergencyDepartmentId');
    fetchDashboardData();
    initPendingFeesTable();
    startAutoRefresh();
    updateVisitTypeUI();
});
</script>
</body>
</html>