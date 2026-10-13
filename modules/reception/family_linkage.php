<?php
/**
 * Family Linkage - HMS ERP System
 * Enhanced version with comprehensive patient relationship management
 * 
 * @package HMS-ERP
 * @version 3.0
 * @requires patient_family_manage_access permission
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

// Permission check for managing family linkages
$can_manage_family = $is_global_admin;

if (!$is_global_admin && $db_available && $user_id > 0) {
    $can_manage_family = user_has_permission($pdo, 'patient_family_manage_access', $user_id);
}

// If user has no permission, redirect
if (!$can_manage_family) {
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
// AJAX: Live patient search (Select2) with Branch Isolation
// ============================================
if (isset($_GET['ajax_search_patients'])) {
    if (!$db_available) {
        json_exit(['results' => []]);
    }
    
    $submitted_token = $_GET['csrf_token'] ?? '';
    if (!isset($_SESSION['csrf_token']) || $submitted_token !== $_SESSION['csrf_token']) {
        error_log("Family linkage search: CSRF validation warning for user {$user_id}");
    }
    
    $q = trim((string)($_GET['q'] ?? ''));
    $qLike = '%' . $q . '%';
    
    try {
        $sql = "
            SELECT 
                p.id, 
                p.mr_number, 
                CONCAT(COALESCE(p.first_name, ''), ' ', COALESCE(p.middle_name, ''), ' ', COALESCE(p.last_name, '')) AS full_name,
                p.phone,
                p.email,
                p.branch_id,
                p.date_of_birth,
                p.gender
            FROM patients p
            WHERE p.deleted_at IS NULL
              AND (p.first_name LIKE :search1 OR p.last_name LIKE :search2 OR p.mr_number LIKE :search3 OR p.phone LIKE :search4)
        ";
        
        $params = [
            ':search1' => $qLike,
            ':search2' => $qLike,
            ':search3' => $qLike,
            ':search4' => $qLike
        ];
        
        if (!$is_global_admin && $user_branch_id > 0) {
            $sql .= " AND p.branch_id = :branch_id";
            $params[':branch_id'] = $user_branch_id;
        }
        
        $sql .= " ORDER BY p.first_name ASC LIMIT 25";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $patients = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $items = [];
        foreach ($patients as $row) {
            $name = $row['full_name'] ?? 'Unknown Patient';
            $items[] = [
                'id' => (int)$row['id'],
                'text' => htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . ' • ' . 
                          htmlspecialchars($row['mr_number'] ?? '—', ENT_QUOTES, 'UTF-8') . 
                          ($row['phone'] ? ' • ' . htmlspecialchars($row['phone'], ENT_QUOTES, 'UTF-8') : ''),
            ];
        }
        json_exit(['results' => $items]);
        
    } catch (PDOException $e) {
        error_log("Patient search error: " . $e->getMessage());
        json_exit(['results' => []]);
    }
}

// ============================================
// AJAX: Fetch family linkages with Enhanced Data
// ============================================
if (isset($_GET['ajax_get_family'])) {
    if (!$db_available) {
        json_exit(['success' => false, 'message' => 'Database unavailable']);
    }
    
    $primary_id = (int)($_GET['patient_id'] ?? 0);
    if ($primary_id <= 0) {
        json_exit(['success' => false, 'message' => 'Invalid patient'], 400);
    }
    
    try {
        // Validate primary patient exists
        $chkStmt = $pdo->prepare("
            SELECT id, mr_number, first_name, middle_name, last_name, branch_id, phone, email, date_of_birth, gender, blood_group
            FROM patients
            WHERE id = :id AND deleted_at IS NULL
            LIMIT 1
        ");
        $chkStmt->execute([':id' => $primary_id]);
        $primary = $chkStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$primary) {
            json_exit(['success' => false, 'message' => 'Patient not found'], 404);
        }
        
        if (!$is_global_admin && $user_branch_id > 0 && $primary['branch_id'] != $user_branch_id) {
            json_exit(['success' => false, 'message' => 'Access denied'], 403);
        }
        
        // Enhanced query with all available data
        $relStmt = $pdo->prepare("
            WITH patient_invoice_summary AS (
                SELECT 
                    patient_id,
                    SUM(CASE WHEN payment_status IN ('unpaid', 'partial') THEN due_amount ELSE 0 END) AS due,
                    SUM(CASE WHEN payment_status = 'unpaid' THEN 1 ELSE 0 END) AS pending_count
                FROM invoices
                WHERE deleted_at IS NULL
                GROUP BY patient_id
            ),
            patient_insurance AS (
                SELECT 
                    pip.patient_id,
                    pip.policy_number,
                    pip.coverage_percentage,
                    pip.status as policy_status,
                    ip.name as insurance_provider,
                    ip.code as insurance_code
                FROM patient_insurance_profiles pip
                JOIN insurance_providers ip ON ip.id = pip.insurance_provider_id
                WHERE pip.status = 'active' AND pip.deleted_at IS NULL
            ),
            patient_admissions AS (
                SELECT 
                    patient_id,
                    COUNT(*) as admission_count,
                    MAX(admitted_at) as last_admission_date,
                    GROUP_CONCAT(DISTINCT status) as admission_statuses
                FROM admissions
                WHERE deleted_at IS NULL AND status = 'admitted'
                GROUP BY patient_id
            ),
            patient_recent_visits AS (
                SELECT 
                    patient_id,
                    MAX(visit_date) as last_visit_date,
                    COUNT(*) as total_visits
                FROM patient_visits
                WHERE deleted_at IS NULL
                GROUP BY patient_id
            )
            SELECT 
                pr.id AS link_id,
                pr.relationship_type,
                pr.is_emergency_contact,
                pr.created_at,
                pr.updated_at,
                CONCAT(creator.first_name, ' ', creator.last_name) AS created_by,
                p.id AS patient_id,
                p.mr_number,
                p.first_name,
                p.middle_name,
                p.last_name,
                p.phone,
                p.email,
                p.gender,
                p.date_of_birth,
                p.blood_group,
                p.branch_id,
                COALESCE(inv.due, 0) AS outstanding_due,
                COALESCE(inv.pending_count, 0) AS pending_count,
                pi.policy_number,
                pi.insurance_provider,
                pi.coverage_percentage,
                pi.policy_status,
                pv.last_visit_date,
                pv.total_visits,
                pa.admission_count,
                pa.last_admission_date,
                pa.admission_statuses
            FROM patient_relationships pr
            INNER JOIN patients p ON p.id = pr.related_patient_id
            LEFT JOIN users creator ON creator.id = pr.created_by_user_id
            LEFT JOIN patient_invoice_summary inv ON inv.patient_id = p.id
            LEFT JOIN patient_insurance pi ON pi.patient_id = p.id
            LEFT JOIN patient_recent_visits pv ON pv.patient_id = p.id
            LEFT JOIN patient_admissions pa ON pa.patient_id = p.id
            WHERE pr.primary_patient_id = :primary_id
              AND pr.deleted_at IS NULL
              AND p.deleted_at IS NULL
            ORDER BY pr.created_at DESC
        ");
        $relStmt->execute([':primary_id' => $primary_id]);
        $linked = [];
        
        while ($row = $relStmt->fetch(PDO::FETCH_ASSOC)) {
            $pid = (int)$row['patient_id'];
            $name = trim(($row['first_name'] ?? '') . ' ' . ($row['middle_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
            if (empty($name)) $name = 'Unknown Patient';
            
            $linked[] = [
                'patient_id' => $pid,
                'link_id' => $row['link_id'],
                'name' => htmlspecialchars($name, ENT_QUOTES, 'UTF-8'),
                'mrn' => htmlspecialchars($row['mr_number'] ?? '', ENT_QUOTES, 'UTF-8'),
                'phone' => htmlspecialchars($row['phone'] ?? '', ENT_QUOTES, 'UTF-8'),
                'email' => htmlspecialchars($row['email'] ?? '', ENT_QUOTES, 'UTF-8'),
                'gender' => htmlspecialchars($row['gender'] ?? '', ENT_QUOTES, 'UTF-8'),
                'date_of_birth' => $row['date_of_birth'] ?? null,
                'blood_group' => htmlspecialchars($row['blood_group'] ?? '', ENT_QUOTES, 'UTF-8'),
                'relationship_type' => $row['relationship_type'] ?? null,
                'is_emergency_contact' => (int)($row['is_emergency_contact'] ?? 0) === 1,
                'outstanding_due' => (float)($row['outstanding_due'] ?? 0),
                'pending_count' => (int)($row['pending_count'] ?? 0),
                'insurance_provider' => htmlspecialchars($row['insurance_provider'] ?? '', ENT_QUOTES, 'UTF-8'),
                'policy_number' => htmlspecialchars($row['policy_number'] ?? '', ENT_QUOTES, 'UTF-8'),
                'coverage_percentage' => (float)($row['coverage_percentage'] ?? 0),
                'last_visit_date' => $row['last_visit_date'] ?? null,
                'total_visits' => (int)($row['total_visits'] ?? 0),
                'is_admitted' => ($row['admission_count'] ?? 0) > 0,
                'admission_count' => (int)($row['admission_count'] ?? 0),
                'created_at' => $row['created_at'] ?? null,
                'created_by' => htmlspecialchars($row['created_by'] ?? '', ENT_QUOTES, 'UTF-8'),
            ];
        }
        
        // Load reverse relationships
        $revStmt = $pdo->prepare("
            WITH patient_invoice_summary AS (
                SELECT 
                    patient_id,
                    SUM(CASE WHEN payment_status IN ('unpaid', 'partial') THEN due_amount ELSE 0 END) AS due,
                    SUM(CASE WHEN payment_status = 'unpaid' THEN 1 ELSE 0 END) AS pending_count
                FROM invoices
                WHERE deleted_at IS NULL
                GROUP BY patient_id
            ),
            patient_insurance AS (
                SELECT 
                    pip.patient_id,
                    pip.policy_number,
                    pip.coverage_percentage,
                    pip.status as policy_status,
                    ip.name as insurance_provider,
                    ip.code as insurance_code
                FROM patient_insurance_profiles pip
                JOIN insurance_providers ip ON ip.id = pip.insurance_provider_id
                WHERE pip.status = 'active' AND pip.deleted_at IS NULL
            ),
            patient_recent_visits AS (
                SELECT 
                    patient_id,
                    MAX(visit_date) as last_visit_date,
                    COUNT(*) as total_visits
                FROM patient_visits
                WHERE deleted_at IS NULL
                GROUP BY patient_id
            )
            SELECT 
                pr.id AS link_id,
                pr.relationship_type,
                pr.is_emergency_contact,
                pr.created_at,
                CONCAT(creator.first_name, ' ', creator.last_name) AS created_by,
                p.id AS patient_id,
                p.mr_number,
                p.first_name,
                p.middle_name,
                p.last_name,
                p.phone,
                p.email,
                p.gender,
                p.date_of_birth,
                p.blood_group,
                p.branch_id,
                COALESCE(inv.due, 0) AS outstanding_due,
                COALESCE(inv.pending_count, 0) AS pending_count,
                pi.policy_number,
                pi.insurance_provider,
                pi.coverage_percentage,
                pi.policy_status,
                pv.last_visit_date,
                pv.total_visits
            FROM patient_relationships pr
            INNER JOIN patients p ON p.id = pr.primary_patient_id
            LEFT JOIN users creator ON creator.id = pr.created_by_user_id
            LEFT JOIN patient_invoice_summary inv ON inv.patient_id = p.id
            LEFT JOIN patient_insurance pi ON pi.patient_id = p.id
            LEFT JOIN patient_recent_visits pv ON pv.patient_id = p.id
            WHERE pr.related_patient_id = :primary_id
              AND pr.deleted_at IS NULL
              AND p.deleted_at IS NULL
            ORDER BY pr.created_at DESC
        ");
        $revStmt->execute([':primary_id' => $primary_id]);
        
        while ($row = $revStmt->fetch(PDO::FETCH_ASSOC)) {
            $pid = (int)$row['patient_id'];
            $name = trim(($row['first_name'] ?? '') . ' ' . ($row['middle_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
            if (empty($name)) $name = 'Unknown Patient';
            
            $linked[] = [
                'patient_id' => $pid,
                'link_id' => $row['link_id'],
                'name' => htmlspecialchars($name, ENT_QUOTES, 'UTF-8'),
                'mrn' => htmlspecialchars($row['mr_number'] ?? '', ENT_QUOTES, 'UTF-8'),
                'phone' => htmlspecialchars($row['phone'] ?? '', ENT_QUOTES, 'UTF-8'),
                'email' => htmlspecialchars($row['email'] ?? '', ENT_QUOTES, 'UTF-8'),
                'gender' => htmlspecialchars($row['gender'] ?? '', ENT_QUOTES, 'UTF-8'),
                'date_of_birth' => $row['date_of_birth'] ?? null,
                'blood_group' => htmlspecialchars($row['blood_group'] ?? '', ENT_QUOTES, 'UTF-8'),
                'relationship_type' => $row['relationship_type'] ?? null,
                'is_emergency_contact' => (int)($row['is_emergency_contact'] ?? 0) === 1,
                'outstanding_due' => (float)($row['outstanding_due'] ?? 0),
                'pending_count' => (int)($row['pending_count'] ?? 0),
                'insurance_provider' => htmlspecialchars($row['insurance_provider'] ?? '', ENT_QUOTES, 'UTF-8'),
                'policy_number' => htmlspecialchars($row['policy_number'] ?? '', ENT_QUOTES, 'UTF-8'),
                'coverage_percentage' => (float)($row['coverage_percentage'] ?? 0),
                'last_visit_date' => $row['last_visit_date'] ?? null,
                'total_visits' => (int)($row['total_visits'] ?? 0),
                'is_admitted' => false, // Reverse relationships don't track admissions in this query
                'admission_count' => 0,
                'created_at' => $row['created_at'] ?? null,
                'created_by' => htmlspecialchars($row['created_by'] ?? '', ENT_QUOTES, 'UTF-8'),
            ];
        }
        
        // Remove duplicates (if any)
        $uniqueLinked = [];
        $seenIds = [];
        foreach ($linked as $item) {
            if (!in_array($item['patient_id'], $seenIds)) {
                $seenIds[] = $item['patient_id'];
                $uniqueLinked[] = $item;
            }
        }
        $linked = $uniqueLinked;
        
        // Calculate family summary statistics
        $summary = [
            'total_members' => count($linked),
            'emergency_contacts' => count(array_filter($linked, function($m) { return $m['is_emergency_contact']; })),
            'total_outstanding' => array_sum(array_column($linked, 'outstanding_due')),
            'insured_members' => count(array_filter($linked, function($m) { return !empty($m['insurance_provider']); })),
            'admitted_members' => count(array_filter($linked, function($m) { return $m['is_admitted']; })),
            'total_visits' => array_sum(array_column($linked, 'total_visits')),
        ];
        
        $primaryName = trim(($primary['first_name'] ?? '') . ' ' . ($primary['middle_name'] ?? '') . ' ' . ($primary['last_name'] ?? ''));
        if (empty($primaryName)) $primaryName = 'Unknown Patient';
        
        json_exit([
            'success' => true,
            'primary' => [
                'id' => (int)$primary['id'],
                'mrn' => htmlspecialchars($primary['mr_number'] ?? '', ENT_QUOTES, 'UTF-8'),
                'name' => htmlspecialchars($primaryName, ENT_QUOTES, 'UTF-8'),
                'phone' => htmlspecialchars($primary['phone'] ?? '', ENT_QUOTES, 'UTF-8'),
                'email' => htmlspecialchars($primary['email'] ?? '', ENT_QUOTES, 'UTF-8'),
                'gender' => htmlspecialchars($primary['gender'] ?? '', ENT_QUOTES, 'UTF-8'),
                'date_of_birth' => $primary['date_of_birth'] ?? null,
                'blood_group' => htmlspecialchars($primary['blood_group'] ?? '', ENT_QUOTES, 'UTF-8'),
            ],
            'linked' => $linked,
            'summary' => $summary,
        ]);
        
    } catch (PDOException $e) {
        error_log("Fetch family error: " . $e->getMessage());
        json_exit(['success' => false, 'message' => 'Database error: ' . $e->getMessage()], 500);
    }
}

// ============================================
// AJAX: Add Family Medical Note
// ============================================
if (isset($_POST['ajax_add_family_note'])) {
    if (!$db_available) {
        json_exit(['success' => false, 'message' => 'Database unavailable']);
    }
    
    $submitted_token = $_POST['csrf_token'] ?? '';
    if (!isset($_SESSION['csrf_token']) || $submitted_token !== $_SESSION['csrf_token']) {
        json_exit(['success' => false, 'message' => 'Security validation failed']);
    }
    
    $primary_id = (int)($_POST['primary_patient_id'] ?? 0);
    $note = trim((string)($_POST['note'] ?? ''));
    
    if ($primary_id <= 0 || empty($note)) {
        json_exit(['success' => false, 'message' => 'Invalid input'], 400);
    }
    
    try {
        // Create a new table for family notes if not exists
        $createStmt = $pdo->prepare("
            CREATE TABLE IF NOT EXISTS family_medical_notes (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                primary_patient_id INT UNSIGNED NOT NULL,
                note TEXT NOT NULL,
                created_by_user_id INT UNSIGNED NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                deleted_at TIMESTAMP NULL,
                FOREIGN KEY (primary_patient_id) REFERENCES patients(id),
                FOREIGN KEY (created_by_user_id) REFERENCES users(id)
            )
        ");
        $createStmt->execute();
        
        $insertStmt = $pdo->prepare("
            INSERT INTO family_medical_notes (primary_patient_id, note, created_by_user_id)
            VALUES (:primary_id, :note, :user_id)
        ");
        $insertStmt->execute([
            ':primary_id' => $primary_id,
            ':note' => $note,
            ':user_id' => $user_id
        ]);
        
        json_exit(['success' => true, 'message' => 'Note added successfully']);
        
    } catch (PDOException $e) {
        error_log("Add family note error: " . $e->getMessage());
        json_exit(['success' => false, 'message' => 'Database error: ' . $e->getMessage()], 500);
    }
}

// ============================================
// AJAX: Get Family Medical Notes
// ============================================
if (isset($_GET['ajax_get_family_notes'])) {
    if (!$db_available) {
        json_exit(['success' => false, 'message' => 'Database unavailable']);
    }
    
    $primary_id = (int)($_GET['patient_id'] ?? 0);
    if ($primary_id <= 0) {
        json_exit(['success' => false, 'message' => 'Invalid patient'], 400);
    }
    
    try {
        // Check if table exists
        $tableCheck = $pdo->query("SHOW TABLES LIKE 'family_medical_notes'");
        if ($tableCheck->rowCount() == 0) {
            json_exit(['success' => true, 'notes' => []]);
        }
        
        $stmt = $pdo->prepare("
            SELECT 
                fmn.id,
                fmn.note,
                fmn.created_at,
                CONCAT(u.first_name, ' ', u.last_name) AS created_by
            FROM family_medical_notes fmn
            LEFT JOIN users u ON u.id = fmn.created_by_user_id
            WHERE fmn.primary_patient_id = :primary_id
              AND fmn.deleted_at IS NULL
            ORDER BY fmn.created_at DESC
        ");
        $stmt->execute([':primary_id' => $primary_id]);
        $notes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($notes as &$note) {
            $note['note'] = htmlspecialchars($note['note'], ENT_QUOTES, 'UTF-8');
            $note['created_by'] = htmlspecialchars($note['created_by'] ?? 'Unknown', ENT_QUOTES, 'UTF-8');
            $note['created_at_formatted'] = date('M d, Y h:i A', strtotime($note['created_at']));
        }
        
        json_exit(['success' => true, 'notes' => $notes]);
        
    } catch (PDOException $e) {
        error_log("Get family notes error: " . $e->getMessage());
        json_exit(['success' => false, 'message' => 'Database error: ' . $e->getMessage()], 500);
    }
}

// ============================================
// AJAX: Add connection with CSRF Validation
// ============================================
if (isset($_POST['ajax_add_link'])) {
    if (!$db_available) {
        json_exit(['success' => false, 'message' => 'Database unavailable']);
    }
    
    $submitted_token = $_POST['csrf_token'] ?? '';
    if (!isset($_SESSION['csrf_token']) || $submitted_token !== $_SESSION['csrf_token']) {
        error_log("Family linkage: CSRF validation failed for user {$user_id}");
        json_exit(['success' => false, 'message' => 'Security validation failed. Please refresh the page and try again.']);
    }
    
    $primary_patient_id = (int)($_POST['primary_patient_id'] ?? 0);
    $related_patient_id = (int)($_POST['related_patient_id'] ?? 0);
    $relationship_type = (string)($_POST['relationship_type'] ?? '');
    $is_emergency_contact = (int)($_POST['is_emergency_contact'] ?? 0) === 1 ? 1 : 0;
    
    $allowed = ['spouse', 'child', 'parent', 'sibling', 'guardian', 'employee'];
    
    if ($primary_patient_id <= 0 || $related_patient_id <= 0 || $primary_patient_id === $related_patient_id) {
        json_exit(['success' => false, 'message' => 'Invalid patients selected'], 400);
    }
    
    if (!in_array($relationship_type, $allowed, true)) {
        json_exit(['success' => false, 'message' => 'Invalid relationship type. Allowed: spouse, child, parent, sibling, guardian, employee'], 400);
    }
    
    try {
        $checkStmt = $pdo->prepare("SELECT id, branch_id FROM patients WHERE id IN (:id1, :id2) AND deleted_at IS NULL");
        $checkStmt->execute([':id1' => $primary_patient_id, ':id2' => $related_patient_id]);
        $patients = $checkStmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (count($patients) !== 2) {
            json_exit(['success' => false, 'message' => 'One or both patients not found'], 404);
        }
        
        if (!$is_global_admin && $user_branch_id > 0) {
            foreach ($patients as $patient) {
                if ($patient['branch_id'] != $user_branch_id) {
                    json_exit(['success' => false, 'message' => 'Access denied - One or both patients belong to a different branch'], 403);
                }
            }
        }
        
        $dupStmt = $pdo->prepare("
            SELECT id FROM patient_relationships 
            WHERE primary_patient_id = :primary_id AND related_patient_id = :related_id AND deleted_at IS NULL
            LIMIT 1
        ");
        $dupStmt->execute([':primary_id' => $primary_patient_id, ':related_id' => $related_patient_id]);
        
        if ($dupStmt->fetch()) {
            json_exit(['success' => false, 'message' => 'Relationship already exists'], 409);
        }
        
        $dupReverseStmt = $pdo->prepare("
            SELECT id, deleted_at FROM patient_relationships 
            WHERE primary_patient_id = :primary_id AND related_patient_id = :related_id
            LIMIT 1
        ");
        $dupReverseStmt->execute([':primary_id' => $related_patient_id, ':related_id' => $primary_patient_id]);
        $existing = $dupReverseStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($existing) {
            if ($existing['deleted_at'] !== null) {
                $updateStmt = $pdo->prepare("
                    UPDATE patient_relationships 
                    SET relationship_type = :rel_type, is_emergency_contact = :is_emergency, 
                        deleted_at = NULL, updated_at = NOW()
                    WHERE id = :id
                ");
                $updateStmt->execute([
                    ':rel_type' => $relationship_type,
                    ':is_emergency' => $is_emergency_contact,
                    ':id' => $existing['id']
                ]);
                json_exit(['success' => true, 'message' => 'Relationship reactivated successfully']);
            } else {
                json_exit(['success' => false, 'message' => 'Relationship already exists'], 409);
            }
        }
        
        $insertStmt = $pdo->prepare("
            INSERT INTO patient_relationships (
                primary_patient_id, related_patient_id, relationship_type, is_emergency_contact, 
                created_by_user_id, created_at
            ) VALUES (:primary_id, :related_id, :rel_type, :is_emergency, :user_id, NOW())
        ");
        $insertStmt->execute([
            ':primary_id' => $primary_patient_id,
            ':related_id' => $related_patient_id,
            ':rel_type' => $relationship_type,
            ':is_emergency' => $is_emergency_contact,
            ':user_id' => $user_id
        ]);
        
        if (function_exists('log_user_action')) {
            log_user_action($pdo, 'INSERT', 'patient_relationships', $pdo->lastInsertId(), null, [
                'primary_patient_id' => $primary_patient_id,
                'related_patient_id' => $related_patient_id,
                'relationship_type' => $relationship_type
            ]);
        }
        
        json_exit(['success' => true, 'message' => 'Family relationship added successfully']);
        
    } catch (PDOException $e) {
        error_log("Add relationship error: " . $e->getMessage());
        json_exit(['success' => false, 'message' => 'Database error: ' . $e->getMessage()], 500);
    }
}

// ============================================
// AJAX: Remove linkage (SOFT DELETE)
// ============================================
if (isset($_POST['ajax_remove_link'])) {
    if (!$db_available) {
        json_exit(['success' => false, 'message' => 'Database unavailable']);
    }
    
    $submitted_token = $_POST['csrf_token'] ?? '';
    if (!isset($_SESSION['csrf_token']) || $submitted_token !== $_SESSION['csrf_token']) {
        error_log("Family linkage removal: CSRF validation failed for user {$user_id}");
        json_exit(['success' => false, 'message' => 'Security validation failed. Please refresh the page and try again.']);
    }
    
    $link_id = (int)($_POST['link_id'] ?? 0);
    if ($link_id <= 0) {
        json_exit(['success' => false, 'message' => 'Invalid relationship ID'], 400);
    }
    
    try {
        $verifyStmt = $pdo->prepare("
            SELECT pr.id, p.branch_id
            FROM patient_relationships pr
            INNER JOIN patients p ON p.id = pr.primary_patient_id
            WHERE pr.id = :link_id AND pr.deleted_at IS NULL
            LIMIT 1
        ");
        $verifyStmt->execute([':link_id' => $link_id]);
        $relationship = $verifyStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$relationship) {
            json_exit(['success' => false, 'message' => 'Relationship not found'], 404);
        }
        
        if (!$is_global_admin && $user_branch_id > 0 && $relationship['branch_id'] != $user_branch_id) {
            json_exit(['success' => false, 'message' => 'Access denied - Cannot modify relationships from other branches'], 403);
        }
        
        $updateStmt = $pdo->prepare("
            UPDATE patient_relationships 
            SET deleted_at = NOW() 
            WHERE id = :id AND deleted_at IS NULL
        ");
        $updateStmt->execute([':id' => $link_id]);
        
        if ($updateStmt->rowCount() > 0) {
            if (function_exists('log_user_action')) {
                log_user_action($pdo, 'DELETE', 'patient_relationships', $link_id, null, [
                    'action' => 'soft_delete_relationship'
                ]);
            }
            json_exit(['success' => true, 'message' => 'Relationship removed successfully']);
        } else {
            json_exit(['success' => false, 'message' => 'Relationship not found or already removed'], 404);
        }
        
    } catch (PDOException $e) {
        error_log("Remove relationship error: " . $e->getMessage());
        json_exit(['success' => false, 'message' => 'Database error: ' . $e->getMessage()], 500);
    }
}

// ============================================
// AJAX: Toggle emergency contact status
// ============================================
if (isset($_POST['ajax_toggle_emergency'])) {
    if (!$db_available) {
        json_exit(['success' => false, 'message' => 'Database unavailable']);
    }
    
    $submitted_token = $_POST['csrf_token'] ?? '';
    if (!isset($_SESSION['csrf_token']) || $submitted_token !== $_SESSION['csrf_token']) {
        error_log("Family linkage toggle: CSRF validation failed for user {$user_id}");
        json_exit(['success' => false, 'message' => 'Security validation failed. Please refresh the page and try again.']);
    }
    
    $link_id = (int)($_POST['link_id'] ?? 0);
    $is_emergency_contact = (int)($_POST['is_emergency_contact'] ?? 0) === 1 ? 1 : 0;
    
    if ($link_id <= 0) {
        json_exit(['success' => false, 'message' => 'Invalid relationship ID'], 400);
    }
    
    try {
        $verifyStmt = $pdo->prepare("
            SELECT pr.id, p.branch_id
            FROM patient_relationships pr
            INNER JOIN patients p ON p.id = pr.primary_patient_id
            WHERE pr.id = :link_id AND pr.deleted_at IS NULL
            LIMIT 1
        ");
        $verifyStmt->execute([':link_id' => $link_id]);
        $relationship = $verifyStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$relationship) {
            json_exit(['success' => false, 'message' => 'Relationship not found'], 404);
        }
        
        if (!$is_global_admin && $user_branch_id > 0 && $relationship['branch_id'] != $user_branch_id) {
            json_exit(['success' => false, 'message' => 'Access denied'], 403);
        }
        
        $updateStmt = $pdo->prepare("
            UPDATE patient_relationships 
            SET is_emergency_contact = :is_emergency, updated_at = NOW()
            WHERE id = :id AND deleted_at IS NULL
        ");
        $updateStmt->execute([
            ':is_emergency' => $is_emergency_contact,
            ':id' => $link_id
        ]);
        
        if ($updateStmt->rowCount() > 0) {
            json_exit(['success' => true, 'message' => 'Emergency contact status updated']);
        } else {
            json_exit(['success' => false, 'message' => 'Relationship not found'], 404);
        }
        
    } catch (PDOException $e) {
        error_log("Toggle emergency error: " . $e->getMessage());
        json_exit(['success' => false, 'message' => 'Database error: ' . $e->getMessage()], 500);
    }
}

// ============================================
// Page UI
// ============================================
$prefill_id = (int)($_GET['id'] ?? 0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include('assets/inc/head.php'); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Family Linkage | Patient Relationships</title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">

    <link href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/select2-bootstrap4-theme/1.5.2/select2-bootstrap4.min.css" rel="stylesheet">

    <style>
        body { background: linear-gradient(135deg,#f5f7fb 0%,#eef2f6 100%); }
        .content-page { margin-left: 260px; padding: 28px 32px; transition: all 0.3s ease; }
        @media (max-width: 768px) { .content-page { margin-left: 0; padding: 20px; } }
        body.erp-sidebar-collapsed .content-page { margin-left: 80px; }

        .card { border: 0; border-radius: 20px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); margin-bottom: 24px; overflow: hidden; }
        .card-header { background: #f8fafc; border-bottom: 1px solid #e2e8f0; padding: 16px 24px; font-weight: 700; }
        .card-body { padding: 24px; }
        
        .tile { border: 1px solid #e5e7eb; border-radius: 16px; padding: 16px; background: #fff; height: 100%; transition: all 0.2s; }
        .tile:hover { box-shadow: 0 8px 25px rgba(0,0,0,0.1); transform: translateY(-2px); }
        .tile-title { font-weight: 700; font-size: 1rem; }
        .mrn { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; font-size: 11px; background: #f1f5f9; padding: 4px 10px; border-radius: 20px; display: inline-block; }
        .badge { font-weight: 600; padding: 5px 10px; border-radius: 20px; }
        
        .select2-container--bootstrap4 .select2-selection { border-radius: 12px; border-color: #e2e8f0; min-height: 42px; }
        .select2-container--bootstrap4 .select2-selection__rendered { line-height: 40px; }
        .select2-container--bootstrap4 .select2-selection--single .select2-selection__arrow { height: 40px; }
        
        .btn-primary { background: linear-gradient(135deg, #4361ee, #3a0ca3); border: none; border-radius: 12px; padding: 8px 20px; font-weight: 600; }
        .btn-outline-primary { border-radius: 12px; padding: 8px 20px; font-weight: 600; }
        .btn-success { background: linear-gradient(135deg, #06d6a0, #059669); border: none; border-radius: 12px; padding: 8px 20px; font-weight: 600; }
        
        .form-control { border-radius: 12px; border: 1px solid #e2e8f0; padding: 10px 14px; }
        .form-control:focus { border-color: #4361ee; box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.1); }
        
        .custom-switch .custom-control-label:before { border-radius: 20px; }
        .custom-switch .custom-control-input:checked ~ .custom-control-label:before { background-color: #4361ee; border-color: #4361ee; }
        
        .btn-outline-secondary { border: 1px solid #e2e8f0; background: white; padding: 8px 20px; border-radius: 12px; }
        .btn-outline-secondary:hover { background: #f8f9fa; border-color: #4361ee; }
        
        .summary-stats { display: flex; gap: 20px; flex-wrap: wrap; }
        .summary-stat { background: #f8fafc; padding: 10px 20px; border-radius: 12px; display: flex; align-items: center; gap: 10px; }
        .summary-stat .icon { font-size: 20px; color: #4361ee; }
        .summary-stat .value { font-weight: 700; font-size: 18px; }
        .summary-stat .label { color: #6b7280; font-size: 12px; }
        
        .family-note-item { padding: 12px; border-left: 3px solid #4361ee; background: #f8fafc; border-radius: 8px; margin-bottom: 10px; }
        .family-note-item .note-meta { font-size: 12px; color: #6b7280; }
        
        .relationship-badge { display: inline-flex; align-items: center; gap: 4px; padding: 2px 10px; border-radius: 12px; font-size: 11px; font-weight: 600; }
        .relationship-spouse { background: #fee2e2; color: #991b1b; }
        .relationship-child { background: #dbeafe; color: #1e40af; }
        .relationship-parent { background: #d1fae5; color: #065f46; }
        .relationship-sibling { background: #fef3c7; color: #92400e; }
        .relationship-guardian { background: #e0e7ff; color: #3730a3; }
        .relationship-employee { background: #fce4ec; color: #831843; }
    </style>
</head>
<body>
<div id="wrapper">
    <?php include('assets/inc/nav.php'); ?>
    <?php include('assets/inc/sidebar.php'); ?>

    <div class="content-page">
        <div class="container-fluid">

            <div class="d-flex justify-content-between align-items-center flex-wrap mb-4" style="gap: 10px;">
                <div>
                    <h4 class="mb-1"><i class="fas fa-people-arrows text-primary me-2"></i> Family Linkage</h4>
                    <p class="text-muted mb-0">Comprehensive patient relationship and family management</p>
                </div>
                <a class="btn btn-outline-secondary" href="patient_directory.php"><i class="fas fa-arrow-left me-1"></i> Back to Directory</a>
            </div>

            <div class="card">
                <div class="card-body">
                    <label class="fw-bold mb-2"><i class="fas fa-user me-2 text-primary"></i> Primary Patient</label>
                    <select id="primaryPatient" class="form-control" style="width:100%"></select>
                    <small class="text-muted d-block mt-2"><i class="fas fa-info-circle me-1"></i> Search by name, MRN, or phone number</small>
                </div>
            </div>

            <div id="familySection" style="display:none;">
                <div class="card">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center flex-wrap" style="gap: 10px;">
                            <div>
                                <div class="text-muted small text-uppercase mb-1"><i class="fas fa-user-check me-1"></i> Selected Patient</div>
                                <div class="fw-bold fs-5" id="primaryLabel">—</div>
                                <div class="text-muted small" id="primaryDetails"></div>
                            </div>
                            <div class="d-flex" style="gap: 8px; flex-wrap: wrap;">
                                <a class="btn btn-outline-primary" id="openProfileBtn" href="#"><i class="fas fa-user me-1"></i> View Profile</a>
                                <button class="btn btn-primary" data-toggle="collapse" data-target="#addLinkBox">
                                    <i class="fas fa-link me-1"></i> Add Connection
                                </button>
                            </div>
                        </div>
                        
                        <!-- Family Summary Stats -->
                        <div class="mt-3 pt-3 border-top" id="familySummary">
                            <div class="summary-stats" id="summaryStats">
                                <div class="summary-stat">
                                    <span class="icon"><i class="fas fa-users"></i></span>
                                    <div>
                                        <div class="value" id="statMembers">0</div>
                                        <div class="label">Total Members</div>
                                    </div>
                                </div>
                                <div class="summary-stat">
                                    <span class="icon"><i class="fas fa-phone-alt"></i></span>
                                    <div>
                                        <div class="value" id="statEmergency">0</div>
                                        <div class="label">Emergency Contacts</div>
                                    </div>
                                </div>
                                <div class="summary-stat">
                                    <span class="icon"><i class="fas fa-shield-alt"></i></span>
                                    <div>
                                        <div class="value" id="statInsured">0</div>
                                        <div class="label">Insured Members</div>
                                    </div>
                                </div>
                                <div class="summary-stat">
                                    <span class="icon"><i class="fas fa-hospital"></i></span>
                                    <div>
                                        <div class="value" id="statAdmitted">0</div>
                                        <div class="label">Admitted</div>
                                    </div>
                                </div>
                                <div class="summary-stat">
                                    <span class="icon"><i class="fas fa-money-bill-wave text-danger"></i></span>
                                    <div>
                                        <div class="value text-danger" id="statOutstanding">ETB 0.00</div>
                                        <div class="label">Total Outstanding</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="collapse mb-3" id="addLinkBox">
                    <div class="card">
                        <div class="card-body">
                            <h6 class="fw-bold mb-3"><i class="fas fa-link me-2 text-primary"></i> Link a Family Member</h6>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label">Related Patient</label>
                                    <select id="relatedPatient" class="form-control" style="width:100%"></select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Relationship Type</label>
                                    <select id="relationshipType" class="form-control">
                                        <option value="spouse">Spouse</option>
                                        <option value="child">Child</option>
                                        <option value="parent">Parent</option>
                                        <option value="sibling">Sibling</option>
                                        <option value="guardian">Guardian</option>
                                        <option value="employee">Employee</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label d-block">Emergency Contact</label>
                                    <div class="custom-control custom-switch mt-2">
                                        <input type="checkbox" class="custom-control-input" id="isEmergencyContact">
                                        <label class="custom-control-label" for="isEmergencyContact">Mark as emergency contact</label>
                                    </div>
                                </div>
                            </div>

                            <div class="mt-4 d-flex justify-content-end" style="gap: 8px;">
                                <button class="btn btn-outline-secondary" type="button" data-toggle="collapse" data-target="#addLinkBox">Cancel</button>
                                <button class="btn btn-success" id="saveLinkBtn"><i class="fas fa-save me-1"></i> Save Link</button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <i class="fas fa-users me-2 text-primary"></i> Linked Family Members
                    </div>
                    <div class="card-body">
                        <div class="row" id="familyGrid"></div>
                    </div>
                </div>

                <!-- Family Medical Notes Section -->
                <div class="card">
                    <div class="card-header">
                        <i class="fas fa-notes-medical me-2 text-primary"></i> Family Medical Notes
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <textarea class="form-control" id="familyNoteInput" rows="3" placeholder="Add shared family medical history, hereditary conditions, or important family health notes..."></textarea>
                        </div>
                        <div class="d-flex justify-content-end">
                            <button class="btn btn-primary btn-sm" id="saveFamilyNoteBtn">
                                <i class="fas fa-save me-1"></i> Save Note
                            </button>
                        </div>
                        <div id="familyNotesList" class="mt-3"></div>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <?php include('assets/inc/footer.php'); ?>
</div>

<script src="assets/js/vendor.min.js"></script>
<script src="assets/js/app.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
const csrfToken = <?php echo json_encode($csrf_token); ?>;
let currentPrimaryId = <?php echo (int)$prefill_id; ?>;

function escapeHtml(str) {
    if (!str) return '';
    return String(str).replace(/[&<>]/g, function(m) {
        if (m === '&') return '&amp;';
        if (m === '<') return '&lt;';
        if (m === '>') return '&gt;';
        return m;
    });
}

function formatDue(amount) {
    if (!amount || amount === 0) return '';
    return `<span class="badge bg-danger text-white ms-2" title="Outstanding balance">ETB ${parseFloat(amount).toFixed(2)}</span>`;
}

function getRelationshipBadgeClass(type) {
    const classes = {
        'spouse': 'relationship-spouse',
        'child': 'relationship-child',
        'parent': 'relationship-parent',
        'sibling': 'relationship-sibling',
        'guardian': 'relationship-guardian',
        'employee': 'relationship-employee'
    };
    return classes[type] || '';
}

function calculateAge(birthDate) {
    if (!birthDate) return null;
    const today = new Date();
    const birth = new Date(birthDate);
    let age = today.getFullYear() - birth.getFullYear();
    const m = today.getMonth() - birth.getMonth();
    if (m < 0 || (m === 0 && today.getDate() < birth.getDate())) {
        age--;
    }
    return age;
}

function formatDate(dateString) {
    if (!dateString) return '';
    const date = new Date(dateString);
    return date.toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric'
    });
}

function formatDateTime(dateString) {
    if (!dateString) return '';
    const date = new Date(dateString);
    return date.toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    });
}

function initPatientSelect($el, placeholder) {
    $el.select2({
        theme: 'bootstrap4',
        placeholder: placeholder,
        allowClear: true,
        ajax: {
            url: window.location.pathname,
            dataType: 'json',
            delay: 300,
            data: function(params) {
                return { 
                    ajax_search_patients: 1, 
                    q: params.term || '',
                    csrf_token: csrfToken
                };
            },
            processResults: function(data) {
                return data;
            }
        },
        minimumInputLength: 2
    });
}

function tileHtml(m) {
    const dueBadge = formatDue(m.outstanding_due);
    const age = m.date_of_birth ? calculateAge(m.date_of_birth) : null;
    const relBadgeClass = getRelationshipBadgeClass(m.relationship_type);
    const relBadge = m.relationship_type ? 
        `<span class="relationship-badge ${relBadgeClass}">${escapeHtml(m.relationship_type)}</span>` : 
        `<span class="relationship-badge" style="background:#e5e7eb;color:#374151;">unknown</span>`;
    const emBadge = m.is_emergency_contact ? 
        `<span class="badge bg-warning text-dark ms-1"><i class="fas fa-phone-alt me-1"></i>Emergency</span>` : '';
    const insuranceBadge = m.insurance_provider ? 
        `<span class="badge bg-success text-white ms-1"><i class="fas fa-shield-alt me-1"></i>${escapeHtml(m.insurance_provider)}</span>` : '';
    const admittedBadge = m.is_admitted ? 
        `<span class="badge bg-danger text-white ms-1"><i class="fas fa-hospital me-1"></i>Admitted</span>` : '';
    const genderIcon = m.gender === 'male' ? '♂' : m.gender === 'female' ? '♀' : '';
    
    // Get insurance coverage badge
    let coverageBadge = '';
    if (m.coverage_percentage > 0) {
        coverageBadge = `<span class="badge bg-info text-white ms-1">${m.coverage_percentage}% coverage</span>`;
    }
    
    return `
        <div class="col-sm-6 col-lg-4 mb-4" data-link-id="${m.link_id}" data-patient-id="${m.patient_id}">
            <div class="tile">
                <div class="d-flex justify-content-between align-items-start mb-2">
                    <div>
                        <div class="tile-title">${escapeHtml(m.name) || 'Unknown'}</div>
                        <div class="text-muted small">
                            ${age !== null ? age + ' years' : ''} 
                            ${genderIcon ? '• ' + genderIcon : ''}
                            ${m.blood_group && m.blood_group !== 'unknown' ? '• ' + escapeHtml(m.blood_group) : ''}
                        </div>
                    </div>
                    ${dueBadge}
                </div>
                <div class="mb-2">
                    <span class="mrn"><i class="fas fa-qrcode me-1"></i> ${escapeHtml(m.mrn || '—')}</span>
                    ${m.pending_count > 0 ? `<span class="badge bg-warning text-dark ms-1">${m.pending_count} pending</span>` : ''}
                </div>
                <div class="mb-2" style="display:flex; flex-wrap:wrap; gap:4px;">
                    ${relBadge} ${emBadge} ${insuranceBadge} ${admittedBadge} ${coverageBadge}
                </div>
                ${m.phone ? `<div class="small text-muted"><i class="fas fa-phone me-1"></i>${escapeHtml(m.phone)}</div>` : ''}
                ${m.email ? `<div class="small text-muted"><i class="fas fa-envelope me-1"></i>${escapeHtml(m.email)}</div>` : ''}
                ${m.last_visit_date ? `<div class="small text-muted mt-1"><i class="far fa-calendar-alt me-1"></i>Last visit: ${formatDate(m.last_visit_date)}</div>` : ''}
                ${m.total_visits ? `<div class="small text-muted"><i class="fas fa-history me-1"></i>${m.total_visits} total visits</div>` : ''}
                ${m.created_at ? `<div class="small text-muted mt-1"><i class="far fa-clock me-1"></i>Linked: ${formatDateTime(m.created_at)}</div>` : ''}
                ${m.created_by ? `<div class="small text-muted"><i class="fas fa-user me-1"></i>By: ${escapeHtml(m.created_by)}</div>` : ''}
                <div class="mt-2 d-flex justify-content-between align-items-center">
                    <a class="btn btn-outline-primary btn-sm" href="patient_history.php?id=${m.patient_id}">
                        <i class="fas fa-user me-1"></i> Profile
                    </a>
                    <div>
                        <button class="btn btn-outline-warning btn-sm toggle-emergency-btn me-1" 
                                data-link-id="${m.link_id}" 
                                data-current="${m.is_emergency_contact}"
                                title="Toggle emergency contact status">
                            <i class="fas fa-exchange-alt me-1"></i>
                        </button>
                        <button class="btn btn-outline-danger btn-sm remove-link-btn" 
                                data-link-id="${m.link_id}" 
                                data-name="${escapeHtml(m.name)}"
                                title="Remove relationship">
                            <i class="fas fa-trash-alt"></i>
                        </button>
                    </div>
                </div>
                ${m.insurance_provider && m.policy_number ? `<div class="mt-2 small text-muted"><i class="fas fa-file-contract me-1"></i>Policy: ${escapeHtml(m.policy_number)}</div>` : ''}
            </div>
        </div>
    `;
}

function loadFamily(primaryId) {
    $('#familySection').hide();
    $('#familyNotesList').html('');
    Swal.fire({ title: 'Loading...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

    $.getJSON(window.location.pathname, { 
        ajax_get_family: 1, 
        patient_id: primaryId,
        csrf_token: csrfToken
    })
    .done(function(res) {
        Swal.close();
        if (!res.success) {
            Swal.fire('Error', res.message || 'Failed to load family data', 'error');
            return;
        }

        $('#familySection').show();
        
        // Update primary patient info
        let primaryDetails = '';
        if (res.primary.phone) primaryDetails += `📱 ${escapeHtml(res.primary.phone)} `;
        if (res.primary.email) primaryDetails += `✉️ ${escapeHtml(res.primary.email)} `;
        if (res.primary.gender) primaryDetails += `• ${escapeHtml(res.primary.gender)}`;
        if (res.primary.date_of_birth) {
            const age = calculateAge(res.primary.date_of_birth);
            if (age !== null) primaryDetails += ` • ${age} years`;
        }
        
        $('#primaryLabel').text(`${res.primary.name} • ${res.primary.mrn}`);
        $('#primaryDetails').text(primaryDetails);
        $('#openProfileBtn').attr('href', `patient_history.php?id=${res.primary.id}`);

        // Update summary stats
        if (res.summary) {
            $('#statMembers').text(res.summary.total_members);
            $('#statEmergency').text(res.summary.emergency_contacts);
            $('#statInsured').text(res.summary.insured_members);
            $('#statAdmitted').text(res.summary.admitted_members);
            $('#statOutstanding').text(`ETB ${res.summary.total_outstanding.toFixed(2)}`);
        }

        const linked = res.linked || [];
        if (linked.length) {
            $('#familyGrid').html(linked.map(tileHtml).join(''));
            
            $('.remove-link-btn').off('click').on('click', function() {
                const linkId = $(this).data('link-id');
                const name = $(this).data('name');
                removeRelationship(linkId, name);
            });
            
            $('.toggle-emergency-btn').off('click').on('click', function() {
                const linkId = $(this).data('link-id');
                const currentStatus = $(this).data('current') === true;
                toggleEmergencyContact(linkId, currentStatus);
            });
        } else {
            $('#familyGrid').html(`<div class="col-12 text-center text-muted py-4"><i class="fas fa-users fa-3x mb-2 opacity-25 d-block"></i>No linked family members yet.</div>`);
        }
        
        // Load family notes
        loadFamilyNotes(primaryId);
    })
    .fail(function(xhr) {
        Swal.close();
        let errorMsg = 'Server error. Please try again.';
        try {
            const resp = JSON.parse(xhr.responseText);
            if (resp.message) errorMsg = resp.message;
        } catch(e) {}
        Swal.fire('Error', errorMsg, 'error');
        console.error(xhr.responseText);
    });
}

function loadFamilyNotes(primaryId) {
    $.getJSON(window.location.pathname, {
        ajax_get_family_notes: 1,
        patient_id: primaryId,
        csrf_token: csrfToken
    })
    .done(function(res) {
        if (!res.success) return;
        
        const notesList = $('#familyNotesList');
        notesList.empty();
        
        if (res.notes && res.notes.length > 0) {
            res.notes.forEach(function(note) {
                notesList.append(`
                    <div class="family-note-item">
                        <div>${escapeHtml(note.note)}</div>
                        <div class="note-meta">By ${escapeHtml(note.created_by)} • ${note.created_at_formatted}</div>
                    </div>
                `);
            });
        } else {
            notesList.html('<div class="text-muted text-center py-3"><i class="fas fa-info-circle me-1"></i>No family medical notes yet.</div>');
        }
    })
    .fail(function() {
        $('#familyNotesList').html('<div class="text-muted text-center py-3">Unable to load notes.</div>');
    });
}

function removeRelationship(linkId, name) {
    Swal.fire({
        title: 'Remove Family Link?',
        html: `Are you sure you want to remove the relationship with <strong>${name}</strong>?<br><small>This action can be undone by re-linking.</small>`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        confirmButtonText: '<i class="fas fa-trash-alt me-1"></i>Remove',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            Swal.fire({ title: 'Processing...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
            
            $.ajax({
                url: window.location.pathname,
                type: 'POST',
                dataType: 'json',
                data: {
                    ajax_remove_link: 1,
                    link_id: linkId,
                    csrf_token: csrfToken
                }
            })
            .done(function(res) {
                Swal.close();
                if (res.success) {
                    Swal.fire('Removed', res.message, 'success');
                    loadFamily(currentPrimaryId);
                } else {
                    Swal.fire('Error', res.message || 'Failed to remove relationship', 'error');
                }
            })
            .fail(function() {
                Swal.close();
                Swal.fire('Error', 'Server error. Please try again.', 'error');
            });
        }
    });
}

function toggleEmergencyContact(linkId, currentStatus) {
    const newStatus = !currentStatus;
    const actionText = newStatus ? 'set as Emergency Contact' : 'remove Emergency Contact status';
    
    Swal.fire({
        title: 'Update Emergency Contact',
        html: `Are you sure you want to ${actionText} for this family member?`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#4361ee',
        confirmButtonText: '<i class="fas fa-check me-1"></i>Confirm',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            Swal.fire({ title: 'Processing...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
            
            $.ajax({
                url: window.location.pathname,
                type: 'POST',
                dataType: 'json',
                data: {
                    ajax_toggle_emergency: 1,
                    link_id: linkId,
                    is_emergency_contact: newStatus ? 1 : 0,
                    csrf_token: csrfToken
                }
            })
            .done(function(res) {
                Swal.close();
                if (res.success) {
                    Swal.fire('Success', res.message, 'success');
                    loadFamily(currentPrimaryId);
                } else {
                    Swal.fire('Error', res.message || 'Failed to update status', 'error');
                }
            })
            .fail(function() {
                Swal.close();
                Swal.fire('Error', 'Server error. Please try again.', 'error');
            });
        }
    });
}

$(function() {
    initPatientSelect($('#primaryPatient'), 'Search for primary patient...');
    initPatientSelect($('#relatedPatient'), 'Search for related patient...');

    $('#primaryPatient').on('select2:select', function(e) {
        currentPrimaryId = e.params.data.id;
        loadFamily(currentPrimaryId);
    });

    // Save family note
    $('#saveFamilyNoteBtn').on('click', function() {
        const note = $('#familyNoteInput').val().trim();
        if (!note) {
            Swal.fire('Error', 'Please enter a note.', 'error');
            return;
        }
        if (!currentPrimaryId || currentPrimaryId <= 0) {
            Swal.fire('Error', 'Please select a patient first.', 'error');
            return;
        }
        
        Swal.fire({ title: 'Saving...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
        
        $.ajax({
            url: window.location.pathname,
            type: 'POST',
            dataType: 'json',
            data: {
                ajax_add_family_note: 1,
                primary_patient_id: currentPrimaryId,
                note: note,
                csrf_token: csrfToken
            }
        })
        .done(function(res) {
            Swal.close();
            if (res.success) {
                $('#familyNoteInput').val('');
                Swal.fire('Success', res.message, 'success');
                loadFamilyNotes(currentPrimaryId);
            } else {
                Swal.fire('Error', res.message || 'Failed to save note', 'error');
            }
        })
        .fail(function() {
            Swal.close();
            Swal.fire('Error', 'Server error. Please try again.', 'error');
        });
    });

    $('#saveLinkBtn').on('click', function() {
        const relatedId = parseInt($('#relatedPatient').val() || '0', 10);
        const relType = $('#relationshipType').val();
        const isEmergency = $('#isEmergencyContact').is(':checked') ? 1 : 0;

        if (!currentPrimaryId || currentPrimaryId <= 0) {
            Swal.fire('Error', 'Please select a primary patient first.', 'error');
            return;
        }
        if (!relatedId || relatedId <= 0) {
            Swal.fire('Error', 'Please select a related patient to link.', 'error');
            return;
        }
        if (currentPrimaryId === relatedId) {
            Swal.fire('Error', 'Cannot link a patient to themselves.', 'error');
            return;
        }
        if (!relType) {
            Swal.fire('Error', 'Please select a relationship type.', 'error');
            return;
        }

        Swal.fire({ title: 'Saving...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

        $.ajax({
            url: window.location.pathname,
            type: 'POST',
            dataType: 'json',
            data: {
                ajax_add_link: 1,
                primary_patient_id: currentPrimaryId,
                related_patient_id: relatedId,
                relationship_type: relType,
                is_emergency_contact: isEmergency,
                csrf_token: csrfToken
            }
        })
        .done(function(res) {
            Swal.close();
            if (res.success) {
                $('#addLinkBox').collapse('hide');
                $('#relatedPatient').val(null).trigger('change');
                $('#isEmergencyContact').prop('checked', false);
                loadFamily(currentPrimaryId);
                Swal.fire('Success', res.message, 'success');
            } else {
                Swal.fire('Error', res.message || 'Failed to add relationship', 'error');
            }
        })
        .fail(function(xhr) {
            Swal.close();
            let errorMsg = 'Server error. Please try again.';
            try {
                const resp = JSON.parse(xhr.responseText);
                if (resp.message) errorMsg = resp.message;
            } catch(e) {}
            Swal.fire('Error', errorMsg, 'error');
            console.error(xhr.responseText);
        });
    });

    // Prefill from URL parameter ?id=123
    if (currentPrimaryId && currentPrimaryId > 0) {
        $.ajax({
            url: window.location.pathname,
            dataType: 'json',
            data: { ajax_search_patients: 1, q: currentPrimaryId, csrf_token: csrfToken },
            success: function(data) {
                if (data.results && data.results.length) {
                    const patient = data.results.find(p => p.id === currentPrimaryId);
                    if (patient) {
                        const option = new Option(patient.text, patient.id, true, true);
                        $('#primaryPatient').append(option).trigger('change');
                        loadFamily(currentPrimaryId);
                    } else {
                        loadFamily(currentPrimaryId);
                    }
                } else {
                    loadFamily(currentPrimaryId);
                }
            },
            error: function() {
                loadFamily(currentPrimaryId);
            }
        });
    }
});
</script>
</body>
</html>