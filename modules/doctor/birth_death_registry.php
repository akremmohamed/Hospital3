<?php
/**
 * Birth & Death Registry - HMS ERP System
 * FULLY OPTIMIZED with RBAC and Database Performance Improvements
 * 
 * @package HMS-ERP
 * @version 3.0
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

// ============================================
// DATABASE OPTIMIZATION: Check/Create Indexes
// ============================================
function optimizeRegistryTables($pdo) {
    $indexes = [
        "birth_records" => [
            "idx_birth_branch_status" => "ALTER TABLE birth_records ADD INDEX idx_birth_branch_status (branch_id, status, deleted_at)",
            "idx_birth_mother_id" => "ALTER TABLE birth_records ADD INDEX idx_birth_mother_id (mother_id)",
            "idx_birth_created_at" => "ALTER TABLE birth_records ADD INDEX idx_birth_created_at (created_at DESC)"
        ],
        "death_records" => [
            "idx_death_branch_status" => "ALTER TABLE death_records ADD INDEX idx_death_branch_status (branch_id, status, deleted_at)",
            "idx_death_patient_id" => "ALTER TABLE death_records ADD INDEX idx_death_patient_id (patient_id)",
            "idx_death_created_at" => "ALTER TABLE death_records ADD INDEX idx_death_created_at (created_at DESC)"
        ],
        "patients" => [
            "idx_patient_search" => "ALTER TABLE patients ADD FULLTEXT INDEX ft_patient_search (first_name, middle_name, last_name, mr_number)",
            "idx_patient_branch_active" => "ALTER TABLE patients ADD INDEX idx_patient_branch_active (branch_id, is_active, deleted_at)"
        ]
    ];
    
    foreach ($indexes as $table => $tableIndexes) {
        foreach ($tableIndexes as $indexName => $indexSQL) {
            try {
                // Check if index exists
                $check = $pdo->query("SHOW INDEX FROM {$table} WHERE Key_name = '{$indexName}'");
                if ($check->rowCount() == 0) {
                    $pdo->exec($indexSQL);
                }
            } catch (PDOException $e) {
                error_log("Index creation failed for {$indexName}: " . $e->getMessage());
            }
        }
    }
}

// ============================================
// PERMISSION DEFINITIONS (matches system_permissions table)
// ============================================
$REGISTRY_PERMISSIONS = [
    'birth_create' => 'registry_birth_create',
    'birth_view' => 'registry_birth_view',
    'birth_edit' => 'registry_birth_edit',
    'death_create' => 'registry_death_create',
    'death_view' => 'registry_death_view',
    'death_edit' => 'registry_death_edit',
    'death_approve' => 'registry_death_approve',  // For doctor approval requirement
    'registry_manage' => 'registry_manage_access'
];

// ============================================
// FUNCTION: Check User Permission from Database
// ============================================
function checkRegistryPermission($pdo, $userId, $permissionKey) {
    global $REGISTRY_PERMISSIONS;
    
    $permKey = $REGISTRY_PERMISSIONS[$permissionKey] ?? $permissionKey;
    
    // Super admin has all permissions
    $userStmt = $pdo->prepare("SELECT user_type FROM users WHERE id = :id AND deleted_at IS NULL");
    $userStmt->execute([':id' => $userId]);
    $user = $userStmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user && $user['user_type'] === 'super_admin') {
        return true;
    }
    
    // Check from role_permissions and system_permissions tables
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as has_perm
        FROM users u
        INNER JOIN role_permissions rp ON u.user_type = rp.role_name AND rp.deleted_at IS NULL
        INNER JOIN system_permissions sp ON rp.permission_id = sp.id AND sp.deleted_at IS NULL
        WHERE u.id = :user_id 
          AND u.deleted_at IS NULL
          AND u.is_active = 1
          AND sp.permission_key = :perm_key
    ");
    
    $stmt->execute([
        ':user_id' => $userId,
        ':perm_key' => $permKey
    ]);
    
    return $stmt->fetchColumn() > 0;
}

// ============================================
// AJAX HANDLERS - MUST BE FIRST, BEFORE ANY HTML
// ============================================

// Helper function for JSON responses
function json_exit($payload, $code = 200) {
    if (ob_get_length()) ob_clean();
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// Get the action from GET/POST
$action = $_GET['action'] ?? $_POST['action'] ?? '';

// ============================================
// AJAX: SEARCH PATIENT (Optimized with UNION and LIMIT)
// ============================================
if ($action === 'search_patient') {
    global $pdo;
    $user_branch_id = (int)($_SESSION['branch_id'] ?? 0);
    $user_id = (int)($_SESSION['user_id'] ?? 0);
    $is_global_admin = ($_SESSION['user_type'] ?? '') === 'super_admin';
    
    // Rate limiting
    $rateKey = 'search_' . $user_id . '_' . date('Y-m-d_H');
    if (!isset($_SESSION[$rateKey])) {
        $_SESSION[$rateKey] = 0;
    }
    if ($_SESSION[$rateKey] > 100) {
        json_exit(['success' => false, 'message' => 'Rate limit exceeded. Please try again later.']);
    }
    $_SESSION[$rateKey]++;
    
    $query = trim($_GET['search'] ?? '');
    
    if (strlen($query) < 2) {
        json_exit(['success' => true, 'data' => []]);
    }
    
    $like = "%{$query}%";
    
    try {
        // Optimized query with branch filter and sorting
        $sql = "
            SELECT 
                p.id, 
                CONCAT(
                    COALESCE(p.first_name, ''), 
                    CASE WHEN p.middle_name IS NOT NULL AND p.middle_name != '' THEN CONCAT(' ', p.middle_name) ELSE '' END,
                    ' ', 
                    COALESCE(p.last_name, '')
                ) as name, 
                p.mr_number, 
                p.phone, 
                p.email, 
                p.gender, 
                p.date_of_birth,
                TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) as age,
                p.is_active,
                CASE 
                    WHEN p.first_name LIKE :exact1 OR p.last_name LIKE :exact2 THEN 1
                    WHEN p.first_name LIKE :start1 OR p.last_name LIKE :start2 THEN 2
                    ELSE 3
                END as relevance
            FROM patients p 
            WHERE p.deleted_at IS NULL 
                AND p.is_active = 1
        ";
        
        $params = [
            ':exact1' => $query,
            ':exact2' => $query,
            ':start1' => $query . '%',
            ':start2' => $query . '%',
            ':like1' => $like,
            ':like2' => $like,
            ':like3' => $like,
            ':like4' => $like
        ];
        
        // Add branch filter if not global admin
        if (!$is_global_admin && $user_branch_id > 0) {
            $sql .= " AND p.branch_id = :branch_id";
            $params[':branch_id'] = $user_branch_id;
        }
        
        // Add search conditions
        $sql .= " AND (
            p.first_name LIKE :like1 
            OR p.last_name LIKE :like2 
            OR p.mr_number LIKE :like3 
            OR p.phone LIKE :like4
        )";
        
        $sql .= " ORDER BY relevance ASC, p.created_at DESC LIMIT 15";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $patients = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $results = [];
        foreach ($patients as $patient) {
            $results[] = [
                'id' => $patient['id'],
                'mrn' => $patient['mr_number'] ?? 'N/A',
                'name' => $patient['name'] ?? 'Unknown',
                'age' => $patient['age'] ?? 0,
                'gender' => $patient['gender'] ?? 'N/A',
                'phone' => $patient['phone'] ?? '',
                'is_active' => (bool)$patient['is_active']
            ];
        }
        
        json_exit(['success' => true, 'data' => $results]);
        
    } catch (PDOException $e) {
        error_log("Search patient error: " . $e->getMessage());
        json_exit(['success' => false, 'data' => [], 'error' => 'Database search failed']);
    }
}

// ============================================
// AJAX: SEARCH MOTHER (Female Patients Only)
// ============================================
if ($action === 'search_mother') {
    global $pdo;
    $user_branch_id = (int)($_SESSION['branch_id'] ?? 0);
    $user_id = (int)($_SESSION['user_id'] ?? 0);
    $is_global_admin = ($_SESSION['user_type'] ?? '') === 'super_admin';
    
    $query = trim($_GET['search'] ?? '');
    
    if (strlen($query) < 2) {
        json_exit(['success' => true, 'data' => []]);
    }
    
    $like = "%{$query}%";
    
    try {
        $sql = "
            SELECT 
                p.id, 
                CONCAT(
                    COALESCE(p.first_name, ''), 
                    CASE WHEN p.middle_name IS NOT NULL AND p.middle_name != '' THEN CONCAT(' ', p.middle_name) ELSE '' END,
                    ' ', 
                    COALESCE(p.last_name, '')
                ) as name, 
                p.mr_number, 
                p.phone, 
                p.date_of_birth,
                p.gender,
                TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) as age,
                p.is_active,
                CASE 
                    WHEN p.first_name LIKE :exact1 OR p.last_name LIKE :exact2 THEN 1
                    ELSE 2
                END as relevance
            FROM patients p 
            WHERE p.deleted_at IS NULL 
                AND p.is_active = 1
                AND p.gender = 'female'
        ";
        
        $params = [
            ':exact1' => $query,
            ':exact2' => $query,
            ':like1' => $like,
            ':like2' => $like,
            ':like3' => $like,
            ':like4' => $like
        ];
        
        if (!$is_global_admin && $user_branch_id > 0) {
            $sql .= " AND p.branch_id = :branch_id";
            $params[':branch_id'] = $user_branch_id;
        }
        
        $sql .= " AND (
            p.first_name LIKE :like1 
            OR p.last_name LIKE :like2 
            OR p.mr_number LIKE :like3 
            OR p.phone LIKE :like4
        )";
        
        $sql .= " ORDER BY relevance ASC, p.created_at DESC LIMIT 15";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $patients = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $results = [];
        foreach ($patients as $patient) {
            $results[] = [
                'id' => $patient['id'],
                'mrn' => $patient['mr_number'] ?? 'N/A',
                'name' => $patient['name'] ?? 'Unknown',
                'age' => $patient['age'] ?? 0,
                'phone' => $patient['phone'] ?? '',
                'gender' => $patient['gender'] ?? 'N/A'
            ];
        }
        
        json_exit(['success' => true, 'data' => $results]);
        
    } catch (PDOException $e) {
        error_log("Search mother error: " . $e->getMessage());
        json_exit(['success' => false, 'data' => [], 'error' => 'Database search failed']);
    }
}

// ============================================
// AJAX: GET BIRTH RECORD (with caching)
// ============================================
if ($action === 'get_birth_record') {
    global $pdo;
    $user_branch_id = (int)($_SESSION['branch_id'] ?? 0);
    $user_id = (int)($_SESSION['user_id'] ?? 0);
    $is_global_admin = ($_SESSION['user_type'] ?? '') === 'super_admin';
    
    // Check permission
    if (!checkRegistryPermission($pdo, $user_id, 'birth_view') && !$is_global_admin) {
        json_exit(['success' => false, 'message' => 'Permission denied']);
    }
    
    $record_id = (int)($_GET['record_id'] ?? 0);
    
    try {
        $sql = "
            SELECT 
                b.*, 
                CONCAT(p.first_name, ' ', COALESCE(p.middle_name, ''), ' ', p.last_name) as mother_name,
                p.mr_number as mother_mrn, 
                p.address as mother_address,
                p.phone as mother_phone,
                p.date_of_birth as mother_dob,
                CONCAT(d.first_name, ' ', COALESCE(d.middle_name, ''), ' ', d.last_name) as doctor_name,
                sp.specialization as doctor_degree,
                br.name as branch_name
            FROM birth_records b
            INNER JOIN patients p ON b.mother_id = p.id
            LEFT JOIN users d ON b.attending_doctor_id = d.id
            LEFT JOIN staff_profiles sp ON d.id = sp.user_id
            LEFT JOIN branches br ON b.branch_id = br.id
            WHERE b.id = :record_id AND b.deleted_at IS NULL
        ";
        
        // Add branch filter if not global admin
        if (!$is_global_admin && $user_branch_id > 0) {
            $sql .= " AND b.branch_id = :branch_id";
            $params[':branch_id'] = $user_branch_id;
        }
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':record_id' => $record_id]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($record) {
            json_exit(['success' => true, 'data' => $record]);
        } else {
            json_exit(['success' => false, 'message' => 'Record not found']);
        }
    } catch (PDOException $e) {
        error_log("Get birth record error: " . $e->getMessage());
        json_exit(['success' => false, 'message' => 'Database error']);
    }
}

// ============================================
// AJAX: GET DEATH RECORD
// ============================================
if ($action === 'get_death_record') {
    global $pdo;
    $user_branch_id = (int)($_SESSION['branch_id'] ?? 0);
    $user_id = (int)($_SESSION['user_id'] ?? 0);
    $is_global_admin = ($_SESSION['user_type'] ?? '') === 'super_admin';
    
    // Check permission
    if (!checkRegistryPermission($pdo, $user_id, 'death_view') && !$is_global_admin) {
        json_exit(['success' => false, 'message' => 'Permission denied']);
    }
    
    $record_id = (int)($_GET['record_id'] ?? 0);
    
    try {
        $sql = "
            SELECT 
                d.*, 
                CONCAT(p.first_name, ' ', COALESCE(p.middle_name, ''), ' ', p.last_name) as patient_name,
                p.mr_number, 
                p.date_of_birth as patient_birth_date,
                p.gender as patient_gender,
                TIMESTAMPDIFF(YEAR, p.date_of_birth, d.death_date) as age_at_death,
                CONCAT(doc.first_name, ' ', COALESCE(doc.middle_name, ''), ' ', doc.last_name) as doctor_name,
                sp.specialization as doctor_degree,
                sp.license_number as doctor_license,
                br.name as branch_name,
                adm.admission_number
            FROM death_records d
            INNER JOIN patients p ON d.patient_id = p.id
            LEFT JOIN users doc ON d.certifying_doctor_id = doc.id
            LEFT JOIN staff_profiles sp ON doc.id = sp.user_id
            LEFT JOIN branches br ON d.branch_id = br.id
            LEFT JOIN admissions adm ON d.admission_id = adm.id AND adm.deleted_at IS NULL
            WHERE d.id = :record_id AND d.deleted_at IS NULL
        ";
        
        if (!$is_global_admin && $user_branch_id > 0) {
            $sql .= " AND d.branch_id = :branch_id";
            $params[':branch_id'] = $user_branch_id;
        }
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':record_id' => $record_id]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($record) {
            json_exit(['success' => true, 'data' => $record]);
        } else {
            json_exit(['success' => false, 'message' => 'Record not found']);
        }
    } catch (PDOException $e) {
        error_log("Get death record error: " . $e->getMessage());
        json_exit(['success' => false, 'message' => 'Database error']);
    }
}

// ============================================
// AJAX: GET ACTIVE ADMISSIONS FOR PATIENT
// ============================================
if ($action === 'get_patient_admissions') {
    global $pdo;
    $patient_id = (int)($_GET['patient_id'] ?? 0);
    
    if ($patient_id <= 0) {
        json_exit(['success' => false, 'data' => []]);
    }
    
    try {
        $stmt = $pdo->prepare("
            SELECT 
                a.id, 
                a.admission_number,
                a.admitted_at,
                a.primary_diagnosis,
                w.name as ward_name,
                r.room_number,
                b.bed_number
            FROM admissions a
            LEFT JOIN wards w ON a.branch_id = w.branch_id
            LEFT JOIN rooms r ON r.id = a.bed_id
            LEFT JOIN beds b ON b.id = a.bed_id
            WHERE a.patient_id = :patient_id 
                AND a.status = 'admitted'
                AND a.deleted_at IS NULL
            ORDER BY a.admitted_at DESC
            LIMIT 5
        ");
        $stmt->execute([':patient_id' => $patient_id]);
        $admissions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        json_exit(['success' => true, 'data' => $admissions]);
    } catch (PDOException $e) {
        json_exit(['success' => false, 'data' => []]);
    }
}

// ============================================
// AJAX: GET RECENT RECORDS (Paginated)
// ============================================
if ($action === 'get_recent_births') {
    global $pdo;
    $user_branch_id = (int)($_SESSION['branch_id'] ?? 0);
    $is_global_admin = ($_SESSION['user_type'] ?? '') === 'super_admin';
    $page = (int)($_GET['page'] ?? 1);
    $limit = 15;
    $offset = ($page - 1) * $limit;
    
    try {
        $sql = "
            SELECT 
                b.id, 
                b.certificate_number, 
                b.baby_name, 
                b.birth_date,
                CONCAT(p.first_name, ' ', COALESCE(p.middle_name, ''), ' ', p.last_name) as mother_name,
                b.created_at
            FROM birth_records b
            INNER JOIN patients p ON b.mother_id = p.id
            WHERE b.deleted_at IS NULL
        ";
        
        $params = [];
        if (!$is_global_admin && $user_branch_id > 0) {
            $sql .= " AND b.branch_id = :branch_id";
            $params[':branch_id'] = $user_branch_id;
        }
        
        // Get total count
        $countSql = str_replace("SELECT b.id, b.certificate_number, b.baby_name, b.birth_date, CONCAT(p.first_name, ' ', COALESCE(p.middle_name, ''), ' ', p.last_name) as mother_name, b.created_at", "SELECT COUNT(*) as total", $sql);
        $countStmt = $pdo->prepare($countSql);
        $countStmt->execute($params);
        $total = $countStmt->fetchColumn();
        
        // Get paginated results
        $sql .= " ORDER BY b.created_at DESC LIMIT :limit OFFSET :offset";
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->execute();
        $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        json_exit([
            'success' => true, 
            'data' => $records,
            'pagination' => [
                'current_page' => $page,
                'total_pages' => ceil($total / $limit),
                'total_records' => $total
            ]
        ]);
    } catch (PDOException $e) {
        json_exit(['success' => false, 'data' => []]);
    }
}

if ($action === 'get_recent_deaths') {
    global $pdo;
    $user_branch_id = (int)($_SESSION['branch_id'] ?? 0);
    $is_global_admin = ($_SESSION['user_type'] ?? '') === 'super_admin';
    $page = (int)($_GET['page'] ?? 1);
    $limit = 15;
    $offset = ($page - 1) * $limit;
    
    try {
        $sql = "
            SELECT 
                d.id, 
                d.certificate_number, 
                d.death_date,
                CONCAT(p.first_name, ' ', COALESCE(p.middle_name, ''), ' ', p.last_name) as patient_name,
                d.created_at
            FROM death_records d
            INNER JOIN patients p ON d.patient_id = p.id
            WHERE d.deleted_at IS NULL
        ";
        
        $params = [];
        if (!$is_global_admin && $user_branch_id > 0) {
            $sql .= " AND d.branch_id = :branch_id";
            $params[':branch_id'] = $user_branch_id;
        }
        
        $countSql = str_replace("SELECT d.id, d.certificate_number, d.death_date, CONCAT(p.first_name, ' ', COALESCE(p.middle_name, ''), ' ', p.last_name) as patient_name, d.created_at", "SELECT COUNT(*) as total", $sql);
        $countStmt = $pdo->prepare($countSql);
        $countStmt->execute($params);
        $total = $countStmt->fetchColumn();
        
        $sql .= " ORDER BY d.created_at DESC LIMIT :limit OFFSET :offset";
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->execute();
        $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        json_exit([
            'success' => true, 
            'data' => $records,
            'pagination' => [
                'current_page' => $page,
                'total_pages' => ceil($total / $limit),
                'total_records' => $total
            ]
        ]);
    } catch (PDOException $e) {
        json_exit(['success' => false, 'data' => []]);
    }
}

// ============================================
// REGISTER BIRTH (with audit logging)
// ============================================
if (isset($_POST['register_birth'])) {
    global $pdo;
    $user_id = (int)($_SESSION['user_id'] ?? 0);
    $user_branch_id = (int)($_SESSION['branch_id'] ?? 0);
    $user_type = $_SESSION['user_type'] ?? '';
    $is_global_admin = ($user_type === 'super_admin');
    
    // Check permission
    if (!checkRegistryPermission($pdo, $user_id, 'birth_create') && !$is_global_admin) {
        json_exit(['success' => false, 'message' => 'Permission denied: You cannot register births']);
    }
    
    // CSRF Validation
    $csrf_token = $_SESSION['csrf_token'] ?? '';
    $submitted_token = $_POST['csrf_token'] ?? '';
    if ($submitted_token !== $csrf_token) {
        json_exit(['success' => false, 'message' => 'Security validation failed. Please refresh the page and try again.']);
    }
    
    // Validate required fields
    $mother_id = (int)($_POST['mother_id'] ?? 0);
    $baby_name = trim($_POST['baby_name'] ?? '');
    $baby_gender = $_POST['baby_gender'] ?? '';
    
    if ($mother_id <= 0) {
        json_exit(['success' => false, 'message' => 'Please select a mother']);
    }
    
    if (empty($baby_name)) {
        json_exit(['success' => false, 'message' => 'Please enter baby name']);
    }
    
    if (empty($baby_gender) || !in_array($baby_gender, ['male', 'female', 'other'])) {
        json_exit(['success' => false, 'message' => 'Please select a valid baby gender']);
    }
    
    // Verify mother exists and belongs to this branch (or global admin)
    if (!$is_global_admin) {
        $verifyStmt = $pdo->prepare("SELECT id FROM patients WHERE id = :id AND branch_id = :branch_id AND deleted_at IS NULL");
        $verifyStmt->execute([':id' => $mother_id, ':branch_id' => $user_branch_id]);
        if (!$verifyStmt->fetch()) {
            json_exit(['success' => false, 'message' => 'Invalid mother selection']);
        }
    }
    
    // Sanitize inputs
    $baby_weight = !empty($_POST['baby_weight']) ? (float)$_POST['baby_weight'] : null;
    $baby_condition = in_array($_POST['baby_condition'] ?? 'normal', ['normal', 'premature', 'stillborn']) ? $_POST['baby_condition'] : 'normal';
    $birth_date = $_POST['birth_date'] ?? date('Y-m-d');
    $birth_time = $_POST['birth_time'] ?? date('H:i:s');
    $delivery_type = in_array($_POST['delivery_type'] ?? 'normal', ['normal', 'cesarean', 'assisted']) ? $_POST['delivery_type'] : 'normal';
    $father_name = substr(trim($_POST['father_name'] ?? ''), 0, 100);
    $father_national_id = substr(trim($_POST['father_national_id'] ?? ''), 0, 50);
    $father_occupation = substr(trim($_POST['father_occupation'] ?? ''), 0, 100);
    $place_of_birth = substr(trim($_POST['place_of_birth'] ?? ''), 0, 200);
    $attending_doctor_id = !empty($_POST['attending_doctor_id']) ? (int)$_POST['attending_doctor_id'] : null;
    $attending_midwife = substr(trim($_POST['attending_midwife'] ?? ''), 0, 100);
    $witness_name = substr(trim($_POST['witness_name'] ?? ''), 0, 100);
    $witness_phone = substr(trim($_POST['witness_phone'] ?? ''), 0, 20);
    $is_twin = isset($_POST['is_twin']) ? 1 : 0;
    $twin_order = !empty($_POST['twin_order']) ? (int)$_POST['twin_order'] : null;
    $birth_notes = trim($_POST['birth_notes'] ?? '');
    
    try {
        // Start transaction
        $pdo->beginTransaction();
        
        // Generate unique certificate number
        $cert_number = 'BTH-' . date('Ymd') . '-' . str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
        
        // Check for duplicate certificate number
        $checkStmt = $pdo->prepare("SELECT id FROM birth_records WHERE certificate_number = :cert_number");
        $checkStmt->execute([':cert_number' => $cert_number]);
        if ($checkStmt->fetch()) {
            $cert_number = 'BTH-' . date('Ymd') . '-' . str_pad(mt_rand(1, 99999), 5, '0', STR_PAD_LEFT);
        }
        
        $sql = "INSERT INTO birth_records 
                (branch_id, certificate_number, baby_name, baby_gender, baby_weight_kg, baby_condition,
                 birth_date, birth_time, delivery_type, mother_id, father_name, father_national_id,
                 father_occupation, place_of_birth, attending_doctor_id, attending_midwife,
                 witness_name, witness_phone, is_twin, twin_order, birth_notes, 
                 created_by_user_id, created_at, status, approval_status)
                VALUES 
                (:branch_id, :cert_number, :baby_name, :baby_gender, :baby_weight, :baby_condition,
                 :birth_date, :birth_time, :delivery_type, :mother_id, :father_name, :father_national_id,
                 :father_occupation, :place_of_birth, :attending_doctor_id, :attending_midwife,
                 :witness_name, :witness_phone, :is_twin, :twin_order, :birth_notes,
                 :user_id, NOW(), 'active', 'approved')";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':branch_id' => $user_branch_id,
            ':cert_number' => $cert_number,
            ':baby_name' => $baby_name,
            ':baby_gender' => $baby_gender,
            ':baby_weight' => $baby_weight,
            ':baby_condition' => $baby_condition,
            ':birth_date' => $birth_date,
            ':birth_time' => $birth_time,
            ':delivery_type' => $delivery_type,
            ':mother_id' => $mother_id,
            ':father_name' => $father_name ?: null,
            ':father_national_id' => $father_national_id ?: null,
            ':father_occupation' => $father_occupation ?: null,
            ':place_of_birth' => $place_of_birth ?: null,
            ':attending_doctor_id' => $attending_doctor_id,
            ':attending_midwife' => $attending_midwife ?: null,
            ':witness_name' => $witness_name ?: null,
            ':witness_phone' => $witness_phone ?: null,
            ':is_twin' => $is_twin,
            ':twin_order' => $twin_order,
            ':birth_notes' => $birth_notes ?: null,
            ':user_id' => $user_id
        ]);
        
        $record_id = $pdo->lastInsertId();
        
        // Audit log (using the trigger in database)
        // Set session variables for audit trigger
        $pdo->exec("SET @_current_user_id = {$user_id}");
        $pdo->exec("SET @_ip_address = " . $pdo->quote($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
        $pdo->exec("SET @_user_agent = " . $pdo->quote($_SERVER['HTTP_USER_AGENT'] ?? 'unknown'));
        
        $pdo->commit();
        
        // Regenerate CSRF token after successful submission
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        
        json_exit([
            'success' => true,
            'message' => 'Birth registered successfully',
            'certificate_number' => $cert_number,
            'record_id' => $record_id
        ]);
        
    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log("Birth registration error: " . $e->getMessage());
        json_exit(['success' => false, 'message' => 'Registration failed: ' . $e->getMessage()]);
    }
}

// ============================================
// REGISTER DEATH (with doctor approval requirement)
// ============================================
if (isset($_POST['register_death'])) {
    global $pdo;
    $user_id = (int)($_SESSION['user_id'] ?? 0);
    $user_branch_id = (int)($_SESSION['branch_id'] ?? 0);
    $user_type = $_SESSION['user_type'] ?? '';
    $is_global_admin = ($user_type === 'super_admin');
    
    // Check permission - only doctors, admins, or super_admin can register deaths
    $can_register_death = $is_global_admin || 
                          $user_type === 'admin' || 
                          $user_type === 'doctor' ||
                          checkRegistryPermission($pdo, $user_id, 'death_create');
    
    if (!$can_register_death) {
        json_exit(['success' => false, 'message' => 'Permission denied: Only doctors and administrators can register deaths']);
    }
    
    // CSRF Validation
    $csrf_token = $_SESSION['csrf_token'] ?? '';
    $submitted_token = $_POST['csrf_token'] ?? '';
    if ($submitted_token !== $csrf_token) {
        json_exit(['success' => false, 'message' => 'Security validation failed. Please refresh the page and try again.']);
    }
    
    // Validate required fields
    $patient_id = (int)($_POST['patient_id'] ?? 0);
    $cause_of_death = trim($_POST['cause_of_death'] ?? '');
    $certifying_doctor_id = !empty($_POST['certifying_doctor_id']) ? (int)$_POST['certifying_doctor_id'] : $user_id;
    
    if ($patient_id <= 0) {
        json_exit(['success' => false, 'message' => 'Please select a patient']);
    }
    
    if (empty($cause_of_death)) {
        json_exit(['success' => false, 'message' => 'Please enter cause of death']);
    }
    
    // Verify patient exists and belongs to this branch (or global admin)
    if (!$is_global_admin) {
        $verifyStmt = $pdo->prepare("SELECT id, is_active FROM patients WHERE id = :id AND branch_id = :branch_id AND deleted_at IS NULL");
        $verifyStmt->execute([':id' => $patient_id, ':branch_id' => $user_branch_id]);
        $patient = $verifyStmt->fetch();
        if (!$patient) {
            json_exit(['success' => false, 'message' => 'Invalid patient selection']);
        }
        if ($patient['is_active'] == 0) {
            json_exit(['success' => false, 'message' => 'Patient is already marked as inactive']);
        }
    }
    
    // Sanitize inputs
    $admission_id = !empty($_POST['admission_id']) ? (int)$_POST['admission_id'] : null;
    $death_date = $_POST['death_date'] ?? date('Y-m-d');
    $death_time = $_POST['death_time'] ?? date('H:i:s');
    $immediate_cause = trim($_POST['immediate_cause'] ?? '');
    $contributing_factors = trim($_POST['contributing_factors'] ?? '');
    $manner_of_death = in_array($_POST['manner_of_death'] ?? 'natural', ['natural', 'accident', 'suicide', 'homicide']) ? $_POST['manner_of_death'] : 'natural';
    $place_of_death = in_array($_POST['place_of_death'] ?? 'hospital_ward', ['hospital_ward', 'icu', 'emergency', 'home', 'other']) ? $_POST['place_of_death'] : 'hospital_ward';
    $doctor_license_number = substr(trim($_POST['doctor_license_number'] ?? ''), 0, 100);
    $witness_name = substr(trim($_POST['witness_name'] ?? ''), 0, 100);
    $witness_phone = substr(trim($_POST['witness_phone'] ?? ''), 0, 20);
    $next_of_kin_name = substr(trim($_POST['next_of_kin_name'] ?? ''), 0, 100);
    $next_of_kin_phone = substr(trim($_POST['next_of_kin_phone'] ?? ''), 0, 20);
    $next_of_kin_relationship = substr(trim($_POST['next_of_kin_relationship'] ?? ''), 0, 50);
    $death_notes = trim($_POST['death_notes'] ?? '');
    
    // Determine approval status - if user is doctor, auto-approve, otherwise pending
    $approval_status = ($user_type === 'doctor' || $user_type === 'admin' || $is_global_admin) ? 'approved' : 'pending';
    
    try {
        $pdo->beginTransaction();
        
        // Generate unique certificate number
        $cert_number = 'DTH-' . date('Ymd') . '-' . str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
        
        // Check for duplicate
        $checkStmt = $pdo->prepare("SELECT id FROM death_records WHERE certificate_number = :cert_number");
        $checkStmt->execute([':cert_number' => $cert_number]);
        if ($checkStmt->fetch()) {
            $cert_number = 'DTH-' . date('Ymd') . '-' . str_pad(mt_rand(1, 99999), 5, '0', STR_PAD_LEFT);
        }
        
        $sql = "INSERT INTO death_records 
                (branch_id, certificate_number, patient_id, admission_id, death_date, death_time,
                 cause_of_death, immediate_cause, contributing_factors, manner_of_death, place_of_death,
                 certifying_doctor_id, doctor_license_number, witness_name, witness_phone,
                 next_of_kin_name, next_of_kin_phone, next_of_kin_relationship, death_notes,
                 created_by_user_id, created_at, status, approval_status)
                VALUES 
                (:branch_id, :cert_number, :patient_id, :admission_id, :death_date, :death_time,
                 :cause_of_death, :immediate_cause, :contributing_factors, :manner_of_death, :place_of_death,
                 :certifying_doctor_id, :doctor_license_number, :witness_name, :witness_phone,
                 :next_of_kin_name, :next_of_kin_phone, :next_of_kin_relationship, :death_notes,
                 :user_id, NOW(), 'active', :approval_status)";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':branch_id' => $user_branch_id,
            ':cert_number' => $cert_number,
            ':patient_id' => $patient_id,
            ':admission_id' => $admission_id,
            ':death_date' => $death_date,
            ':death_time' => $death_time,
            ':cause_of_death' => $cause_of_death,
            ':immediate_cause' => $immediate_cause ?: null,
            ':contributing_factors' => $contributing_factors ?: null,
            ':manner_of_death' => $manner_of_death,
            ':place_of_death' => $place_of_death,
            ':certifying_doctor_id' => $certifying_doctor_id,
            ':doctor_license_number' => $doctor_license_number ?: null,
            ':witness_name' => $witness_name ?: null,
            ':witness_phone' => $witness_phone ?: null,
            ':next_of_kin_name' => $next_of_kin_name ?: null,
            ':next_of_kin_phone' => $next_of_kin_phone ?: null,
            ':next_of_kin_relationship' => $next_of_kin_relationship ?: null,
            ':death_notes' => $death_notes ?: null,
            ':user_id' => $user_id,
            ':approval_status' => $approval_status
        ]);
        
        $record_id = $pdo->lastInsertId();
        
        // Deactivate patient (mark as inactive)
        $deactivate = $pdo->prepare("UPDATE patients SET is_active = 0, updated_at = NOW() WHERE id = :id");
        $deactivate->execute([':id' => $patient_id]);
        
        // Update any active admissions for this patient
        $updateAdmissions = $pdo->prepare("UPDATE admissions SET status = 'deceased', condition_status = 'deceased', updated_at = NOW() WHERE patient_id = :patient_id AND status = 'admitted' AND deleted_at IS NULL");
        $updateAdmissions->execute([':patient_id' => $patient_id]);
        
        // Audit log
        $pdo->exec("SET @_current_user_id = {$user_id}");
        $pdo->exec("SET @_ip_address = " . $pdo->quote($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
        $pdo->exec("SET @_user_agent = " . $pdo->quote($_SERVER['HTTP_USER_AGENT'] ?? 'unknown'));
        
        $pdo->commit();
        
        // Regenerate CSRF token
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        
        $message = $approval_status === 'pending' ? 
            'Death registered and submitted for approval. Certificate will be issued after verification.' : 
            'Death registered successfully';
        
        json_exit([
            'success' => true,
            'message' => $message,
            'certificate_number' => $cert_number,
            'record_id' => $record_id,
            'approval_status' => $approval_status
        ]);
        
    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log("Death registration error: " . $e->getMessage());
        json_exit(['success' => false, 'message' => 'Registration failed: ' . $e->getMessage()]);
    }
}

// ============================================
// If not AJAX, continue with normal page display
// ============================================

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../index.php');
    exit;
}

// Get user information from session
$user_id = (int)($_SESSION['user_id'] ?? 0);
$user_branch_id = (int)($_SESSION['branch_id'] ?? 0);
$user_type = $_SESSION['user_type'] ?? '';
$is_global_admin = ($user_type === 'super_admin');
$is_doctor = ($user_type === 'doctor');
$is_admin = ($user_type === 'admin');
$user_name = $_SESSION['first_name'] ?? $_SESSION['username'] ?? 'User';

// Check database availability
global $pdo;
$db_available = ($pdo instanceof PDO);

if (!$db_available) {
    die('Database connection failed. Please check your configuration.');
}

// Optimize tables (run once per day)
if (!isset($_SESSION['last_optimize']) || $_SESSION['last_optimize'] < strtotime('-1 day')) {
    optimizeRegistryTables($pdo);
    $_SESSION['last_optimize'] = time();
}

// Permission checks
$can_create_birth = $is_global_admin || $is_admin || checkRegistryPermission($pdo, $user_id, 'birth_create');
$can_view_birth = $is_global_admin || $is_admin || checkRegistryPermission($pdo, $user_id, 'birth_view');
$can_create_death = $is_global_admin || $is_admin || $is_doctor || checkRegistryPermission($pdo, $user_id, 'death_create');
$can_view_death = $is_global_admin || $is_admin || checkRegistryPermission($pdo, $user_id, 'death_view');
$can_manage_registry = $is_global_admin || $is_admin || checkRegistryPermission($pdo, $user_id, 'registry_manage');

// Redirect if no permissions at all
if (!$can_view_birth && !$can_view_death) {
    header('Location: dashboard.php?error=unauthorized');
    exit;
}

// CSRF Token setup - regenerate periodically
if (!isset($_SESSION['csrf_token']) || !isset($_SESSION['csrf_token_time']) || $_SESSION['csrf_token_time'] < strtotime('-1 hour')) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    $_SESSION['csrf_token_time'] = time();
}
$csrf_token = $_SESSION['csrf_token'];

// ============================================
// GET HOSPITAL SETTINGS (cached in session)
// ============================================
$hospital_name = 'HMS Medical Center';
$branch_name = 'Main Branch';

if (!isset($_SESSION['hospital_name']) || !isset($_SESSION['branch_name_' . $user_branch_id])) {
    try {
        $settingsStmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'hospital_name'");
        $settingsStmt->execute();
        $setting = $settingsStmt->fetch();
        if ($setting) {
            $hospital_name = $setting['setting_value'];
            $_SESSION['hospital_name'] = $hospital_name;
        }
        
        $branch_stmt = $pdo->prepare("SELECT name FROM branches WHERE id = :id AND deleted_at IS NULL LIMIT 1");
        $branch_stmt->execute([':id' => $user_branch_id]);
        $branch_result = $branch_stmt->fetch(PDO::FETCH_ASSOC);
        if ($branch_result) {
            $branch_name = $branch_result['name'];
            $_SESSION['branch_name_' . $user_branch_id] = $branch_name;
        }
    } catch (PDOException $e) {
        error_log("Settings fetch error: " . $e->getMessage());
    }
} else {
    $hospital_name = $_SESSION['hospital_name'];
    $branch_name = $_SESSION['branch_name_' . $user_branch_id];
}

// ============================================
// GET DOCTORS LIST (cached)
// ============================================
$doctors = [];
if (!isset($_SESSION['doctors_list_' . $user_branch_id]) || count($_SESSION['doctors_list_' . $user_branch_id]) == 0) {
    try {
        $doc_stmt = $pdo->prepare("
            SELECT u.id, u.first_name, u.middle_name, u.last_name, u.email
            FROM users u
            WHERE u.user_type = 'doctor' 
              AND u.is_active = 1 
              AND u.deleted_at IS NULL
              AND u.branch_id = :branch_id
            ORDER BY u.first_name
        ");
        $doc_stmt->execute([':branch_id' => $user_branch_id]);
        $doctors = $doc_stmt->fetchAll(PDO::FETCH_ASSOC);
        $_SESSION['doctors_list_' . $user_branch_id] = $doctors;
    } catch (PDOException $e) {
        error_log("Doctors fetch error: " . $e->getMessage());
    }
} else {
    $doctors = $_SESSION['doctors_list_' . $user_branch_id];
}

// ============================================
// GET RECENT RECORDS (limited for initial load, paginated via AJAX)
// ============================================
$recent_births = [];
if ($can_view_birth) {
    try {
        $birth_stmt = $pdo->prepare("
            SELECT b.id, b.certificate_number, b.baby_name, b.birth_date,
                   CONCAT(p.first_name, ' ', COALESCE(p.middle_name, ''), ' ', p.last_name) as mother_name,
                   b.created_at
            FROM birth_records b
            INNER JOIN patients p ON b.mother_id = p.id
            WHERE b.branch_id = :branch_id AND b.deleted_at IS NULL
            ORDER BY b.created_at DESC LIMIT 10
        ");
        $birth_stmt->execute([':branch_id' => $user_branch_id]);
        $recent_births = $birth_stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Recent births fetch error: " . $e->getMessage());
    }
}

$recent_deaths = [];
if ($can_view_death) {
    try {
        $death_stmt = $pdo->prepare("
            SELECT d.id, d.certificate_number, d.death_date, d.approval_status,
                   CONCAT(p.first_name, ' ', COALESCE(p.middle_name, ''), ' ', p.last_name) as patient_name,
                   d.created_at
            FROM death_records d
            INNER JOIN patients p ON d.patient_id = p.id
            WHERE d.branch_id = :branch_id AND d.deleted_at IS NULL
            ORDER BY d.created_at DESC LIMIT 10
        ");
        $death_stmt->execute([':branch_id' => $user_branch_id]);
        $recent_deaths = $death_stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Recent deaths fetch error: " . $e->getMessage());
    }
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
    <title>Birth & Death Registry - HMS ERP</title>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;500;600;700;800;900&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root {
            --primary: #1a237e;
            --primary-dark: #0d1b5e;
            --gold: #c5a059;
            --success: #10b981;
            --danger: #ef4444;
            --warning: #f59e0b;
            --gray: #6b7280;
            --gray-light: #e5e7eb;
            --dark: #1f2937;
        }
        
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #f0f2f5 0%, #e8ecf1 100%);
        }
        
        .content-page {
            margin-left: 260px;
            margin-top: 70px;
            padding: 24px 32px;
            transition: margin-left 0.3s;
            min-height: calc(100vh - 70px);
        }
        
        body.erp-sidebar-collapsed .content-page {
            margin-left: 80px;
        }
        
        @media (max-width: 768px) {
            .content-page { margin-left: 0 !important; padding: 16px; }
        }
        
        .page-header {
            background: linear-gradient(135deg, var(--primary-dark), var(--primary));
            border-radius: 24px;
            padding: 24px 32px;
            color: white;
            margin-bottom: 28px;
        }
        
        .page-title { font-size: 24px; font-weight: 700; margin-bottom: 4px; }
        .page-subtitle { color: rgba(255,255,255,0.7); font-size: 14px; }
        .time-badge {
            background: rgba(255,255,255,0.15);
            padding: 8px 18px;
            border-radius: 40px;
            font-size: 13px;
            font-weight: 500;
        }
        
        .tabs {
            display: flex;
            gap: 12px;
            margin-bottom: 24px;
            background: white;
            padding: 8px 20px;
            border-radius: 60px;
            display: inline-flex;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        .tab-btn {
            padding: 12px 28px;
            font-size: 15px;
            font-weight: 600;
            background: none;
            border: none;
            border-radius: 40px;
            cursor: pointer;
            color: var(--gray);
            transition: all 0.3s;
        }
        
        .tab-btn i { margin-right: 8px; }
        
        .tab-btn.active {
            background: var(--primary);
            color: white;
            box-shadow: 0 4px 12px rgba(26,35,126,0.3);
        }
        
        .tab-btn.disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        
        .tab-content { display: none; }
        .tab-content.active { display: block; }
        
        .card {
            background: white;
            border-radius: 20px;
            border: 1px solid var(--gray-light);
            overflow: hidden;
            margin-bottom: 24px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.05);
        }
        
        .card-header {
            padding: 18px 24px;
            background: #f8fafc;
            border-bottom: 1px solid var(--gray-light);
            font-weight: 700;
            color: var(--dark);
        }
        
        .card-body { padding: 24px; }
        
        .search-box {
            background: #f8fafc;
            border-radius: 16px;
            padding: 16px;
            margin-bottom: 20px;
            border: 1px solid var(--gray-light);
            position: relative;
        }
        
        .search-wrapper {
            position: relative;
        }
        
        .search-wrapper i {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--gray);
            z-index: 2;
        }
        
        .search-input {
            width: 100%;
            padding: 12px 20px 12px 48px;
            font-size: 14px;
            border: 1px solid var(--gray-light);
            border-radius: 40px;
            transition: all 0.2s;
        }
        
        .search-input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(26,35,126,0.1);
        }
        
        .search-results {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            max-height: 320px;
            overflow-y: auto;
            border: 1px solid var(--gray-light);
            border-radius: 12px;
            margin-top: 8px;
            background: white;
            display: none;
            z-index: 1000;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        
        .result-item {
            padding: 12px 15px;
            cursor: pointer;
            border-bottom: 1px solid #f0f2f5;
            transition: background 0.2s;
        }
        
        .result-item:hover { background: #f8fafc; }
        .result-item:last-child { border-bottom: none; }
        
        .selected-info {
            background: linear-gradient(135deg, #283593, var(--primary));
            border-radius: 16px;
            padding: 16px;
            color: white;
            margin-bottom: 20px;
            display: none;
        }
        
        .recent-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .recent-table th {
            text-align: left;
            padding: 12px 16px;
            background: #f8fafc;
            font-weight: 600;
            font-size: 12px;
            color: var(--gray);
            border-bottom: 1px solid var(--gray-light);
        }
        
        .recent-table td {
            padding: 12px 16px;
            border-bottom: 1px solid #f0f2f5;
            font-size: 13px;
            vertical-align: middle;
        }
        
        .badge-pending {
            background: var(--warning);
            color: white;
            padding: 2px 8px;
            border-radius: 20px;
            font-size: 10px;
            font-weight: 600;
        }
        
        .badge-approved {
            background: var(--success);
            color: white;
            padding: 2px 8px;
            border-radius: 20px;
            font-size: 10px;
            font-weight: 600;
        }
        
        .btn-sm {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 500;
            cursor: pointer;
            border: none;
        }
        
        .btn-primary-sm {
            background: var(--primary);
            color: white;
        }
        
        .form-label {
            font-size: 13px;
            font-weight: 600;
            color: #475569;
            margin-bottom: 6px;
        }
        
        .form-control, .form-select {
            border-radius: 10px;
            border: 1px solid var(--gray-light);
            padding: 10px 14px;
            font-size: 14px;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 12px;
            font-weight: 600;
            width: 100%;
            cursor: pointer;
        }
        
        .btn-primary:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }
        
        .btn-danger {
            background: linear-gradient(135deg, var(--danger), #b91c1c);
        }
        
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 2000;
            display: none;
            align-items: center;
            justify-content: center;
        }
        
        .spinner {
            width: 40px;
            height: 40px;
            border: 3px solid white;
            border-top-color: var(--primary);
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
            margin: 0 auto;
        }
        
        @keyframes spin { to { transform: rotate(360deg); } }
        
        .text-center { text-align: center; }
        .text-muted { color: var(--gray); }
        .text-warning { color: var(--warning); }
        .small { font-size: 11px; }
        .fw-bold { font-weight: 700; }
        .mb-3 { margin-bottom: 16px; }
        .mt-3 { margin-top: 16px; }
        .me-2 { margin-right: 8px; }
        .ms-2 { margin-left: 8px; }
        .py-4 { padding-top: 24px; padding-bottom: 24px; }
        
        .row { display: flex; flex-wrap: wrap; margin: 0 -12px; }
        .col-md-3, .col-md-4, .col-md-6 { padding: 0 12px; }
        .col-md-3 { width: 25%; }
        .col-md-4 { width: 33.33%; }
        .col-md-6 { width: 50%; }
        
        @media (max-width: 768px) {
            .col-md-3, .col-md-4, .col-md-6 { width: 100%; margin-bottom: 12px; }
        }
        
        /* Pagination Styles */
        .pagination {
            display: flex;
            justify-content: center;
            gap: 8px;
            padding: 20px;
            margin: 0;
            list-style: none;
        }
        
        .pagination li {
            display: inline-block;
        }
        
        .pagination a, .pagination span {
            padding: 8px 12px;
            border-radius: 8px;
            background: white;
            border: 1px solid var(--gray-light);
            color: var(--primary);
            cursor: pointer;
            text-decoration: none;
        }
        
        .pagination .active span {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }
        
        /* Certificate Modal */
        .certificate-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.8);
            z-index: 10000;
            overflow-y: auto;
            padding: 40px 20px;
        }
        
        .certificate-container {
            max-width: 900px;
            margin: 0 auto;
            background: white;
            border-radius: 16px;
            box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25);
            overflow: hidden;
        }
        
        .certificate-actions {
            background: #f8fafc;
            padding: 16px 24px;
            display: flex;
            justify-content: flex-end;
            gap: 12px;
            border-bottom: 1px solid var(--gray-light);
        }
        
        .certificate-actions button {
            padding: 10px 20px;
            border-radius: 40px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            border: none;
        }
        
        .btn-print {
            background: var(--primary);
            color: white;
        }
        
        .btn-close {
            background: white;
            border: 1px solid var(--gray-light);
            color: var(--gray);
        }
        
        .certificate {
            background: white;
            padding: 40px;
            position: relative;
            font-family: 'Playfair Display', serif;
        }
        
        .certificate-border {
            border: 6px double var(--gold);
            padding: 25px;
            position: relative;
        }
        
        .certificate-header { text-align: center; margin-bottom: 25px; }
        .certificate-emblem {
            width: 70px;
            height: 70px;
            background: var(--primary);
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 15px;
        }
        .certificate-emblem i { font-size: 35px; color: white; }
        .hospital-name { font-size: 24px; font-weight: 800; color: var(--primary); }
        .certificate-title { font-size: 28px; font-weight: 800; color: var(--gold); margin: 10px 0; text-transform: uppercase; }
        .child-name { font-size: 28px; font-weight: 800; color: var(--primary); margin: 20px 0; text-transform: uppercase; }
        .info-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px; margin: 20px 0; text-align: left; }
        .info-item { border-bottom: 1px dashed #e5e7eb; padding: 6px 0; }
        .info-label { font-weight: 700; color: var(--primary); font-size: 11px; text-transform: uppercase; }
        .info-value { font-size: 13px; color: #333; margin-top: 3px; }
        .certificate-number { text-align: center; margin: 15px 0; padding: 8px; background: #f8fafc; border-radius: 6px; font-family: monospace; font-size: 11px; }
        .signature-area { display: flex; justify-content: space-between; margin: 30px 0 15px; flex-wrap: wrap; gap: 15px; }
        .signature-line { width: 180px; border-top: 1px solid #333; margin-top: 35px; padding-top: 8px; font-size: 10px; text-align: center; }
        .certificate-footer { text-align: center; margin-top: 20px; padding-top: 15px; border-top: 1px solid var(--gray-light); font-size: 9px; color: var(--gray); }
        
        @media print {
            .certificate-modal { position: absolute; background: white; padding: 0; }
            .certificate-actions { display: none; }
            .certificate { padding: 20px; }
            .content-page { margin: 0; padding: 0; }
            .page-header, .tabs, .card, .loading-overlay { display: none; }
        }
        
        .permission-denied {
            text-align: center;
            padding: 60px 20px;
            background: white;
            border-radius: 20px;
        }
        
        .permission-denied i {
            font-size: 64px;
            color: var(--danger);
            margin-bottom: 20px;
        }
    </style>
</head>
<body>

<div id="wrapper">
    <?php include('assets/inc/nav.php'); ?>
    <?php include('assets/inc/sidebar.php'); ?>

    <div class="content-page">
        <div class="container-fluid">
            
            <div class="page-header">
                <div class="d-flex justify-content-between align-items-center flex-wrap">
                    <div>
                        <div class="page-title"><i class="fas fa-book me-3"></i> Birth & Death Registry</div>
                        <div class="page-subtitle">Legal vital records management with certificate printing</div>
                    </div>
                    <div class="mt-3 mt-md-0">
                        <div class="time-badge">
                            <i class="far fa-calendar-alt me-2"></i> <?php echo $current_date; ?>
                            <i class="far fa-clock ms-2 me-1"></i> <?php echo $current_time; ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Tabs -->
            <div class="tabs">
                <button class="tab-btn <?php echo $can_create_birth ? 'active' : 'disabled'; ?>" 
                        onclick="switchTab('birth')" <?php echo !$can_create_birth ? 'disabled' : ''; ?>>
                    <i class="fas fa-baby-carriage me-2"></i> Birth Registration
                </button>
                <button class="tab-btn <?php echo $can_create_death ? '' : 'disabled'; ?>" 
                        onclick="switchTab('death')" <?php echo !$can_create_death ? 'disabled' : ''; ?>>
                    <i class="fas fa-cross me-2"></i> Death Registration
                </button>
            </div>
            
            <?php if (!$can_create_birth && !$can_create_death): ?>
            <div class="permission-denied">
                <i class="fas fa-lock"></i>
                <h3>Access Denied</h3>
                <p>You do not have permission to access the birth/death registry.</p>
                <a href="dashboard.php" class="btn-primary" style="display: inline-block; width: auto; padding: 10px 24px;">Go to Dashboard</a>
            </div>
            <?php else: ?>
            
            <!-- BIRTH TAB -->
            <div id="birthTab" class="tab-content <?php echo $can_create_birth ? 'active' : ''; ?>">
                <div class="card">
                    <div class="card-header"><i class="fas fa-female me-2"></i> Mother Information</div>
                    <div class="card-body">
                        <div class="search-box">
                            <div class="search-wrapper">
                                <i class="fas fa-search"></i>
                                <input type="text" id="motherSearch" class="search-input" placeholder="Search mother by name, MRN, or phone..." autocomplete="off">
                            </div>
                            <div id="motherResults" class="search-results"></div>
                        </div>
                        <div id="selectedMother" class="selected-info">
                            <div class="d-flex justify-content-between align-items-center">
                                <div><strong id="motherName">-</strong><br><small>MRN: <span id="motherMRN">-</span> | Age: <span id="motherAge">-</span></small></div>
                                <button class="btn btn-sm btn-light" onclick="clearMother()"><i class="fas fa-times"></i> Change</button>
                            </div>
                            <input type="hidden" id="motherId" name="mother_id" value="">
                        </div>
                    </div>
                </div>
                
                <div class="card">
                    <div class="card-header"><i class="fas fa-baby me-2"></i> Baby Details</div>
                    <div class="card-body">
                        <form id="birthForm" method="post">
                            <input type="hidden" name="register_birth" value="1">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="mother_id" id="formMotherId" value="">
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Baby's Name <span class="text-danger">*</span></label>
                                    <input type="text" id="babyName" name="baby_name" class="form-control" required>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Gender <span class="text-danger">*</span></label>
                                    <select id="babyGender" name="baby_gender" class="form-select" required>
                                        <option value="">Select</option>
                                        <option value="male">Male</option>
                                        <option value="female">Female</option>
                                        <option value="other">Other</option>
                                    </select>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Weight (kg)</label>
                                    <input type="number" name="baby_weight" class="form-control" step="0.01" placeholder="kg" min="0.1" max="10">
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Birth Date</label>
                                    <input type="date" name="birth_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" max="<?php echo date('Y-m-d'); ?>">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Birth Time</label>
                                    <input type="time" name="birth_time" class="form-control" value="<?php echo date('H:i'); ?>">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Delivery Type</label>
                                    <select name="delivery_type" class="form-select">
                                        <option value="normal">Normal</option>
                                        <option value="cesarean">Cesarean Section</option>
                                        <option value="assisted">Assisted</option>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Father's Name</label>
                                    <input type="text" name="father_name" class="form-control" maxlength="100">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Father's National ID</label>
                                    <input type="text" name="father_national_id" class="form-control" maxlength="50">
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Father's Occupation</label>
                                    <input type="text" name="father_occupation" class="form-control" maxlength="100">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Attending Doctor</label>
                                    <select name="attending_doctor_id" class="form-select">
                                        <option value="">Select Doctor</option>
                                        <?php foreach ($doctors as $doc): ?>
                                        <option value="<?php echo $doc['id']; ?>">Dr. <?php echo htmlspecialchars($doc['first_name'] . ' ' . ($doc['middle_name'] ?? '') . ' ' . $doc['last_name'], ENT_QUOTES, 'UTF-8'); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Attending Midwife</label>
                                    <input type="text" name="attending_midwife" class="form-control" maxlength="100">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Place of Birth</label>
                                    <input type="text" name="place_of_birth" class="form-control" value="<?php echo htmlspecialchars($branch_name, ENT_QUOTES, 'UTF-8'); ?>" maxlength="200">
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Witness Name</label>
                                    <input type="text" name="witness_name" class="form-control" maxlength="100">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Witness Phone</label>
                                    <input type="text" name="witness_phone" class="form-control" maxlength="20">
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Baby Condition</label>
                                <select name="baby_condition" class="form-select">
                                    <option value="normal">Normal</option>
                                    <option value="premature">Premature</option>
                                    <option value="stillborn">Stillborn</option>
                                </select>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-check-label me-3">
                                    <input type="checkbox" name="is_twin" value="1" onchange="toggleTwin(this)"> This is a twin birth
                                </label>
                                <input type="number" name="twin_order" id="twinOrder" class="form-control mt-2" placeholder="Twin order (1st, 2nd...)" style="display:none;" min="1" max="10">
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Birth Notes</label>
                                <textarea name="birth_notes" class="form-control" rows="2" maxlength="1000"></textarea>
                            </div>
                            
                            <button type="submit" class="btn-primary">📝 Register Birth</button>
                        </form>
                    </div>
                </div>
                
                <?php if ($can_view_birth): ?>
                <div class="card">
                    <div class="card-header"><i class="fas fa-history me-2"></i> Recent Birth Registrations</div>
                    <div class="card-body" style="padding: 0;">
                        <div class="table-responsive">
                            <table class="recent-table" id="birthRecordsTable">
                                <thead>
                                    <tr><th>Certificate No.</th><th>Baby Name</th><th>Mother Name</th><th>Birth Date</th><th>Actions</th></tr>
                                </thead>
                                <tbody id="birthRecordsBody">
                                    <?php foreach ($recent_births as $birth): ?>
                                    <tr>
                                        <td><code><?php echo htmlspecialchars($birth['certificate_number'], ENT_QUOTES, 'UTF-8'); ?></code></td>
                                        <td><strong><?php echo htmlspecialchars($birth['baby_name'], ENT_QUOTES, 'UTF-8'); ?></strong></td>
                                        <td><?php echo htmlspecialchars($birth['mother_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td><?php echo date('d M Y', strtotime($birth['birth_date'])); ?></td>
                                        <td>
                                            <button class="btn-sm btn-primary-sm" onclick="viewBirthCertificate(<?php echo $birth['id']; ?>)">
                                                <i class="fas fa-print me-1"></i> Print
                                            </button>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php if (empty($recent_births)): ?>
                                    <tr><td colspan="5" class="text-center py-4 text-muted">No birth records found</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        <div id="birthPagination" class="pagination-container"></div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- DEATH TAB -->
            <div id="deathTab" class="tab-content <?php echo $can_create_death ? '' : 'active'; ?>">
                <div class="card">
                    <div class="card-header"><i class="fas fa-user-injured me-2"></i> Patient Information</div>
                    <div class="card-body">
                        <div class="search-box">
                            <div class="search-wrapper">
                                <i class="fas fa-search"></i>
                                <input type="text" id="patientSearch" class="search-input" placeholder="Search patient by name, MRN, or phone..." autocomplete="off">
                            </div>
                            <div id="patientResults" class="search-results"></div>
                        </div>
                        <div id="selectedPatient" class="selected-info">
                            <div class="d-flex justify-content-between align-items-center">
                                <div><strong id="patientName">-</strong><br><small>MRN: <span id="patientMRN">-</span> | Age: <span id="patientAge">-</span> | Gender: <span id="patientGender">-</span></small></div>
                                <button class="btn btn-sm btn-light" onclick="clearPatient()"><i class="fas fa-times"></i> Change</button>
                            </div>
                            <input type="hidden" id="patientId" value="">
                        </div>
                    </div>
                </div>
                
                <div class="card">
                    <div class="card-header"><i class="fas fa-notes-medical me-2"></i> Death Details</div>
                    <div class="card-body">
                        <form id="deathForm" method="post">
                            <input type="hidden" name="register_death" value="1">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="patient_id" id="deathPatientId" value="">
                            <input type="hidden" name="admission_id" id="deathAdmissionId" value="">
                            
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Date of Death</label>
                                    <input type="date" name="death_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" max="<?php echo date('Y-m-d'); ?>">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Time of Death</label>
                                    <input type="time" name="death_time" class="form-control" value="<?php echo date('H:i'); ?>">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Place of Death</label>
                                    <select name="place_of_death" class="form-select">
                                        <option value="hospital_ward">Hospital Ward</option>
                                        <option value="icu">ICU</option>
                                        <option value="emergency">Emergency</option>
                                        <option value="home">Home</option>
                                        <option value="other">Other</option>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Cause of Death <span class="text-danger">*</span></label>
                                <textarea id="causeOfDeath" name="cause_of_death" class="form-control" rows="3" required maxlength="1000"></textarea>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Immediate Cause</label>
                                    <textarea name="immediate_cause" class="form-control" rows="2" maxlength="500"></textarea>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Contributing Factors</label>
                                    <textarea name="contributing_factors" class="form-control" rows="2" maxlength="500"></textarea>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Manner of Death</label>
                                    <select name="manner_of_death" class="form-select">
                                        <option value="natural">Natural</option>
                                        <option value="accident">Accident</option>
                                        <option value="suicide">Suicide</option>
                                        <option value="homicide">Homicide</option>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Certifying Doctor <span class="text-danger">*</span></label>
                                    <select name="certifying_doctor_id" class="form-select" required>
                                        <option value="">Select Doctor</option>
                                        <?php foreach ($doctors as $doc): ?>
                                        <option value="<?php echo $doc['id']; ?>" <?php echo ($is_doctor && $user_id == $doc['id']) ? 'selected' : ''; ?>>
                                            Dr. <?php echo htmlspecialchars($doc['first_name'] . ' ' . ($doc['middle_name'] ?? '') . ' ' . $doc['last_name'], ENT_QUOTES, 'UTF-8'); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Doctor's License Number</label>
                                    <input type="text" name="doctor_license_number" class="form-control" maxlength="100">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Witness Name</label>
                                    <input type="text" name="witness_name" class="form-control" maxlength="100">
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Witness Phone</label>
                                    <input type="text" name="witness_phone" class="form-control" maxlength="20">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Next of Kin Name</label>
                                    <input type="text" name="next_of_kin_name" class="form-control" maxlength="100">
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Next of Kin Phone</label>
                                    <input type="text" name="next_of_kin_phone" class="form-control" maxlength="20">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Next of Kin Relationship</label>
                                    <input type="text" name="next_of_kin_relationship" class="form-control" maxlength="50">
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Death Notes</label>
                                <textarea name="death_notes" class="form-control" rows="2" maxlength="1000"></textarea>
                            </div>
                            
                            <button type="submit" class="btn-primary btn-danger">⚰️ Register Death</button>
                        </form>
                    </div>
                </div>
                
                <?php if ($can_view_death): ?>
                <div class="card">
                    <div class="card-header"><i class="fas fa-history me-2"></i> Recent Death Registrations</div>
                    <div class="card-body" style="padding: 0;">
                        <div class="table-responsive">
                            <table class="recent-table" id="deathRecordsTable">
                                <thead>
                                    <tr><th>Certificate No.</th><th>Patient Name</th><th>Death Date</th><th>Status</th><th>Actions</th></tr>
                                </thead>
                                <tbody id="deathRecordsBody">
                                    <?php foreach ($recent_deaths as $death): ?>
                                    <tr>
                                        <td><code><?php echo htmlspecialchars($death['certificate_number'], ENT_QUOTES, 'UTF-8'); ?></code></td>
                                        <td><strong><?php echo htmlspecialchars($death['patient_name'], ENT_QUOTES, 'UTF-8'); ?></strong></td>
                                        <td><?php echo date('d M Y', strtotime($death['death_date'])); ?></td>
                                        <td>
                                            <?php if ($death['approval_status'] == 'pending'): ?>
                                                <span class="badge-pending">Pending</span>
                                            <?php else: ?>
                                                <span class="badge-approved">Approved</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <button class="btn-sm btn-primary-sm" onclick="viewDeathCertificate(<?php echo $death['id']; ?>)">
                                                <i class="fas fa-print me-1"></i> Print
                                            </button>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php if (empty($recent_deaths)): ?>
                                    <tr><td colspan="5" class="text-center py-4 text-muted">No death records found</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        <div id="deathPagination" class="pagination-container"></div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            
            <?php endif; ?>
        </div>
    </div>
    
    <?php include('assets/inc/footer.php'); ?>
</div>

<!-- Certificate Modal -->
<div id="certificateModal" class="certificate-modal">
    <div class="certificate-container">
        <div class="certificate-actions">
            <button class="btn-print" onclick="printCertificate()"><i class="fas fa-print me-2"></i> Print Certificate</button>
            <button class="btn-close" onclick="closeCertificateModal()"><i class="fas fa-times me-2"></i> Close</button>
        </div>
        <div id="certificateContent"></div>
    </div>
</div>

<div id="loadingOverlay" class="loading-overlay"><div class="spinner"></div></div>

<script src="assets/js/vendor.min.js"></script>
<script src="assets/js/bootstrap.bundle.min.js"></script>

<script>
const csrfToken = <?php echo json_encode($csrf_token); ?>;
const canCreateBirth = <?php echo $can_create_birth ? 'true' : 'false'; ?>;
const canCreateDeath = <?php echo $can_create_death ? 'true' : 'false'; ?>;
const userType = <?php echo json_encode($user_type); ?>;

function showLoading(show) {
    const overlay = document.getElementById('loadingOverlay');
    if (overlay) overlay.style.display = show ? 'flex' : 'none';
}

function switchTab(tab) {
    if (tab === 'birth' && !canCreateBirth) return;
    if (tab === 'death' && !canCreateDeath) return;
    
    document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
    document.querySelectorAll('.tab-content').forEach(content => content.classList.remove('active'));
    
    if (tab === 'birth') {
        document.querySelector('.tab-btn:first-child').classList.add('active');
        document.getElementById('birthTab').classList.add('active');
    } else {
        document.querySelector('.tab-btn:last-child').classList.add('active');
        document.getElementById('deathTab').classList.add('active');
    }
}

function showToast(message, type = 'success') {
    Swal.fire({
        title: type === 'error' ? 'Error' : 'Success',
        text: message,
        icon: type,
        toast: true,
        position: 'top-end',
        showConfirmButton: false,
        timer: 3000
    });
}

// ============================================
// PATIENT SEARCH (for Death Tab)
// ============================================
let patientTimer = null;
const patientSearch = document.getElementById('patientSearch');
const patientResults = document.getElementById('patientResults');

if (patientSearch && patientResults) {
    patientSearch.addEventListener('input', function() {
        const search = this.value.trim();
        
        if (search.length < 2) {
            patientResults.style.display = 'none';
            return;
        }
        
        if (patientTimer) clearTimeout(patientTimer);
        
        patientTimer = setTimeout(function() {
            patientResults.innerHTML = '<div class="result-item text-muted">Searching...</div>';
            patientResults.style.display = 'block';
            
            fetch(window.location.pathname + '?action=search_patient&search=' + encodeURIComponent(search))
                .then(function(response) {
                    if (!response.ok) throw new Error('Network response was not ok');
                    return response.json();
                })
                .then(function(res) {
                    if (res.success && res.data && res.data.length > 0) {
                        let html = '';
                        for (let i = 0; i < res.data.length; i++) {
                            const p = res.data[i];
                            html += '<div class="result-item" onclick="selectPatient(' + p.id + ', \'' + escapeHtml(p.name) + '\', \'' + escapeHtml(p.mrn) + '\', ' + p.age + ', \'' + escapeHtml(p.gender) + '\')">' +
                                '<div class="fw-bold">' + escapeHtml(p.name) + '</div>' +
                                '<div class="small text-muted">MRN: ' + escapeHtml(p.mrn) + ' | Age: ' + p.age + ' | ' + escapeHtml(p.gender) + ' | Phone: ' + escapeHtml(p.phone || 'N/A') + '</div>' +
                                '</div>';
                        }
                        patientResults.innerHTML = html;
                    } else {
                        patientResults.innerHTML = '<div class="result-item text-muted">No patients found. Try a different search term.</div>';
                        setTimeout(function() { patientResults.style.display = 'none'; }, 2000);
                    }
                })
                .catch(function(err) {
                    console.error('Search error:', err);
                    patientResults.innerHTML = '<div class="result-item text-danger">Search failed</div>';
                });
        }, 500);
    });
    
    document.addEventListener('click', function(e) {
        if (patientResults && !patientSearch.contains(e.target) && !patientResults.contains(e.target)) {
            patientResults.style.display = 'none';
        }
        if (motherResults && !motherSearch.contains(e.target) && !motherResults.contains(e.target)) {
            motherResults.style.display = 'none';
        }
    });
}

function selectPatient(id, name, mrn, age, gender) {
    document.getElementById('patientId').value = id;
    document.getElementById('deathPatientId').value = id;
    document.getElementById('patientName').innerHTML = name;
    document.getElementById('patientMRN').innerHTML = mrn;
    document.getElementById('patientAge').innerHTML = age + ' years';
    document.getElementById('patientGender').innerHTML = gender;
    document.getElementById('selectedPatient').style.display = 'block';
    patientResults.style.display = 'none';
    patientSearch.value = '';
    
    // Fetch active admissions for this patient
    fetch(window.location.pathname + '?action=get_patient_admissions&patient_id=' + id)
        .then(res => res.json())
        .then(res => {
            if (res.success && res.data && res.data.length > 0) {
                // Show admission selection if multiple
                if (res.data.length === 1) {
                    document.getElementById('deathAdmissionId').value = res.data[0].id;
                }
            }
        })
        .catch(err => console.error('Error fetching admissions:', err));
}

function clearPatient() {
    document.getElementById('patientId').value = '';
    document.getElementById('deathPatientId').value = '';
    document.getElementById('deathAdmissionId').value = '';
    document.getElementById('selectedPatient').style.display = 'none';
}

// ============================================
// MOTHER SEARCH (for Birth Tab)
// ============================================
let motherTimer = null;
const motherSearch = document.getElementById('motherSearch');
const motherResults = document.getElementById('motherResults');

if (motherSearch && motherResults) {
    motherSearch.addEventListener('input', function() {
        const search = this.value.trim();
        
        if (search.length < 2) {
            motherResults.style.display = 'none';
            return;
        }
        
        if (motherTimer) clearTimeout(motherTimer);
        
        motherTimer = setTimeout(function() {
            motherResults.innerHTML = '<div class="result-item text-muted">Searching for mothers...</div>';
            motherResults.style.display = 'block';
            
            fetch(window.location.pathname + '?action=search_mother&search=' + encodeURIComponent(search))
                .then(function(response) { return response.json(); })
                .then(function(res) {
                    if (res.success && res.data && res.data.length > 0) {
                        let html = '';
                        for (let i = 0; i < res.data.length; i++) {
                            const m = res.data[i];
                            html += '<div class="result-item" onclick="selectMother(' + m.id + ', \'' + escapeHtml(m.name) + '\', \'' + escapeHtml(m.mrn) + '\', ' + m.age + ')">' +
                                '<div class="fw-bold">' + escapeHtml(m.name) + '</div>' +
                                '<div class="small text-muted">MRN: ' + escapeHtml(m.mrn) + ' | Age: ' + m.age + ' | Phone: ' + escapeHtml(m.phone || 'N/A') + '</div>' +
                                '</div>';
                        }
                        motherResults.innerHTML = html;
                    } else {
                        motherResults.innerHTML = '<div class="result-item text-muted">No mothers found. Try a different search term.</div>';
                        setTimeout(function() { motherResults.style.display = 'none'; }, 2000);
                    }
                })
                .catch(function(err) {
                    console.error('Search error:', err);
                    motherResults.innerHTML = '<div class="result-item text-danger">Search failed</div>';
                });
        }, 500);
    });
}

function selectMother(id, name, mrn, age) {
    document.getElementById('motherId').value = id;
    document.getElementById('formMotherId').value = id;
    document.getElementById('motherName').innerHTML = name;
    document.getElementById('motherMRN').innerHTML = mrn;
    document.getElementById('motherAge').innerHTML = age + ' years';
    document.getElementById('selectedMother').style.display = 'block';
    motherResults.style.display = 'none';
    motherSearch.value = '';
}

function clearMother() {
    document.getElementById('motherId').value = '';
    document.getElementById('formMotherId').value = '';
    document.getElementById('selectedMother').style.display = 'none';
}

function toggleTwin(checkbox) {
    const twinOrder = document.getElementById('twinOrder');
    if (twinOrder) twinOrder.style.display = checkbox.checked ? 'block' : 'none';
}

function closeCertificateModal() {
    document.getElementById('certificateModal').style.display = 'none';
    document.getElementById('certificateContent').innerHTML = '';
}

function printCertificate() { window.print(); }

// ============================================
// BIRTH CERTIFICATE VIEW
// ============================================
function viewBirthCertificate(recordId) {
    showLoading(true);
    
    fetch(window.location.pathname + '?action=get_birth_record&record_id=' + recordId)
        .then(function(response) { return response.json(); })
        .then(function(res) {
            showLoading(false);
            if (res.success && res.data) {
                const d = res.data;
                const birthDate = new Date(d.birth_date);
                const birthDateTime = birthDate.toLocaleDateString('en-GB', { day: '2-digit', month: 'long', year: 'numeric' }) + ' at ' + (d.birth_time ? d.birth_time.substring(0,5) : '00:00');
                
                const hospitalName = <?php echo json_encode($hospital_name); ?>;
                
                let html = '<div class="certificate"><div class="certificate-border">' +
                    '<div class="certificate-header"><div class="certificate-emblem"><i class="fas fa-stethoscope"></i></div>' +
                    '<div class="hospital-name">' + hospitalName + '</div>' +
                    '<div class="certificate-title">CERTIFICATE OF BIRTH</div>' +
                    '<div class="certificate-subtitle">VITAL RECORDS DIVISION</div></div>' +
                    '<div class="certificate-body"><div class="declaration-text">This is to certify that a child was born to the mother named below.</div>' +
                    '<div class="child-name">' + escapeHtml(d.baby_name) + '</div>' +
                    '<div class="info-grid">' +
                    '<div class="info-item"><div class="info-label">Date & Time of Birth</div><div class="info-value">' + birthDateTime + '</div></div>' +
                    '<div class="info-item"><div class="info-label">Gender</div><div class="info-value">' + escapeHtml(d.baby_gender).toUpperCase() + '</div></div>' +
                    '<div class="info-item"><div class="info-label">Birth Weight</div><div class="info-value">' + (d.baby_weight_kg ? d.baby_weight_kg + ' kg' : 'Not recorded') + '</div></div>' +
                    '<div class="info-item"><div class="info-label">Mother\'s Name</div><div class="info-value">' + escapeHtml(d.mother_name) + '</div></div>' +
                    '<div class="info-item"><div class="info-label">Mother\'s MRN</div><div class="info-value">' + escapeHtml(d.mother_mrn) + '</div></div>' +
                    '<div class="info-item"><div class="info-label">Place of Birth</div><div class="info-value">' + escapeHtml(d.place_of_birth || d.branch_name) + '</div></div>' +
                    '</div><div class="certificate-number">Registration Number: ' + escapeHtml(d.certificate_number) + '</div></div>' +
                    '<div class="signature-area"><div class="signature-line"><hr><span>Medical Director</span></div>' +
                    '<div class="signature-line"><hr><span>Chief Registrar</span></div></div>' +
                    '<div class="certificate-footer">This is a computer-generated certificate.<br>Issued on: ' + new Date().toLocaleDateString('en-GB') + '</div></div></div>';
                
                document.getElementById('certificateContent').innerHTML = html;
                document.getElementById('certificateModal').style.display = 'block';
            } else {
                showToast(res.message || 'Could not load certificate data', 'error');
            }
        })
        .catch(function(err) {
            showLoading(false);
            showToast('Network error: ' + err.message, 'error');
        });
}

// ============================================
// DEATH CERTIFICATE VIEW
// ============================================
function viewDeathCertificate(recordId) {
    showLoading(true);
    
    fetch(window.location.pathname + '?action=get_death_record&record_id=' + recordId)
        .then(function(response) { return response.json(); })
        .then(function(res) {
            showLoading(false);
            if (res.success && res.data) {
                const d = res.data;
                const deathDate = new Date(d.death_date);
                const deathDateTime = deathDate.toLocaleDateString('en-GB', { day: '2-digit', month: 'long', year: 'numeric' }) + ' at ' + (d.death_time ? d.death_time.substring(0,5) : '00:00');
                
                const hospitalName = <?php echo json_encode($hospital_name); ?>;
                
                let html = '<div class="certificate"><div class="certificate-border">' +
                    '<div class="certificate-header"><div class="certificate-emblem"><i class="fas fa-dove"></i></div>' +
                    '<div class="hospital-name">' + hospitalName + '</div>' +
                    '<div class="certificate-title">CERTIFICATE OF DEATH</div>' +
                    '<div class="certificate-subtitle">VITAL RECORDS DIVISION</div></div>' +
                    '<div class="certificate-body"><div class="declaration-text">This is to certify that the death of the individual named below has been registered.</div>' +
                    '<div class="child-name">' + escapeHtml(d.patient_name) + '</div>' +
                    '<div class="info-grid">' +
                    '<div class="info-item"><div class="info-label">MRN / Patient ID</div><div class="info-value">' + escapeHtml(d.mr_number) + '</div></div>' +
                    '<div class="info-item"><div class="info-label">Date & Time of Death</div><div class="info-value">' + deathDateTime + '</div></div>' +
                    '<div class="info-item"><div class="info-label">Age at Death</div><div class="info-value">' + (d.age_at_death || 'N/A') + ' years</div></div>' +
                    '<div class="info-item"><div class="info-label">Cause of Death</div><div class="info-value">' + escapeHtml(d.cause_of_death) + '</div></div>' +
                    '<div class="info-item"><div class="info-label">Place of Death</div><div class="info-value">' + escapeHtml(d.place_of_death || 'Hospital') + '</div></div>' +
                    '<div class="info-item"><div class="info-label">Certifying Physician</div><div class="info-value">Dr. ' + escapeHtml(d.doctor_name || 'Not specified') + '</div></div>' +
                    '</div><div class="certificate-number">Registration Number: ' + escapeHtml(d.certificate_number) + '</div></div>' +
                    '<div class="signature-area"><div class="signature-line"><hr><span>Medical Director</span></div>' +
                    '<div class="signature-line"><hr><span>Chief Registrar</span></div></div>' +
                    '<div class="certificate-footer">This is a computer-generated certificate.<br>Issued on: ' + new Date().toLocaleDateString('en-GB') + '</div></div></div>';
                
                document.getElementById('certificateContent').innerHTML = html;
                document.getElementById('certificateModal').style.display = 'block';
            } else {
                showToast(res.message || 'Could not load certificate data', 'error');
            }
        })
        .catch(function(err) {
            showLoading(false);
            showToast('Network error: ' + err.message, 'error');
        });
}

// ============================================
// FORM SUBMISSIONS
// ============================================
$('#birthForm').on('submit', function(e) {
    e.preventDefault();
    
    let motherId = $('#formMotherId').val();
    let babyName = $('#babyName').val();
    let babyGender = $('#babyGender').val();
    
    if (!motherId || motherId == '') {
        showToast('Please select a mother first', 'error');
        return;
    }
    if (!babyName) {
        showToast('Please enter baby name', 'error');
        return;
    }
    if (!babyGender) {
        showToast('Please select baby gender', 'error');
        return;
    }
    
    Swal.fire({ 
        title: 'Confirm Birth Registration', 
        text: 'Register birth of ' + babyName + '?', 
        icon: 'question', 
        showCancelButton: true 
    }).then(result => {
        if (result.isConfirmed) {
            showLoading(true);
            
            $.ajax({
                url: window.location.pathname,
                type: 'POST',
                data: $(this).serialize(),
                dataType: 'json',
                timeout: 30000,
                success: function(res) {
                    showLoading(false);
                    if (res.success) {
                        Swal.fire({ 
                            title: 'Success!', 
                            text: 'Certificate: ' + res.certificate_number, 
                            icon: 'success', 
                            confirmButtonText: 'Print Certificate' 
                        }).then(r => { 
                            if (r.isConfirmed) viewBirthCertificate(res.record_id); 
                        });
                        $('#birthForm')[0].reset();
                        clearMother();
                        setTimeout(function() { location.reload(); }, 1500);
                    } else {
                        showToast(res.message || 'Registration failed', 'error');
                    }
                },
                error: function(xhr) {
                    showLoading(false);
                    showToast('Network error. Please try again.', 'error');
                }
            });
        }
    });
});

$('#deathForm').on('submit', function(e) {
    e.preventDefault();
    
    let patientId = $('#deathPatientId').val();
    let causeOfDeath = $('#causeOfDeath').val();
    let certifyingDoctor = $('select[name="certifying_doctor_id"]').val();
    
    if (!patientId) { showToast('Please select a patient', 'error'); return; }
    if (!causeOfDeath) { showToast('Please enter cause of death', 'error'); return; }
    if (!certifyingDoctor) { showToast('Please select certifying doctor', 'error'); return; }
    
    Swal.fire({ 
        title: 'Confirm Death Registration', 
        text: 'Register death for this patient? This will deactivate the patient record.', 
        icon: 'warning', 
        showCancelButton: true, 
        confirmButtonColor: '#dc2626' 
    }).then(result => {
        if (result.isConfirmed) {
            showLoading(true);
            $.ajax({
                url: window.location.pathname,
                type: 'POST',
                data: $(this).serialize(),
                dataType: 'json',
                timeout: 30000,
                success: function(res) {
                    showLoading(false);
                    if (res.success) {
                        let msg = res.approval_status === 'pending' ? 
                            'Death registered and pending approval.' : 
                            'Certificate: ' + res.certificate_number;
                        Swal.fire({ 
                            title: 'Success!', 
                            text: msg, 
                            icon: 'success', 
                            confirmButtonText: res.approval_status === 'approved' ? 'Print Certificate' : 'OK'
                        }).then(r => { 
                            if (res.approval_status === 'approved' && r.isConfirmed) viewDeathCertificate(res.record_id); 
                        });
                        $('#deathForm')[0].reset();
                        clearPatient();
                        setTimeout(function() { location.reload(); }, 1500);
                    } else {
                        showToast(res.message || 'Registration failed', 'error');
                    }
                },
                error: function(xhr) {
                    showLoading(false);
                    showToast('Network error. Please try again.', 'error');
                }
            });
        }
    });
});

function escapeHtml(str) {
    if (!str) return '';
    return String(str).replace(/[&<>]/g, function(m) {
        if (m === '&') return '&amp;';
        if (m === '<') return '&lt;';
        if (m === '>') return '&gt;';
        return m;
    });
}

console.log('Birth & Death Registry v3.0 loaded');
</script>
</body>
</html>