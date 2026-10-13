<?php
/**
 * Narcotics & Controlled Substance Forensic Audit
 * Pharmacist Module: High‑risk prescription audit trail with JSON payload inspection.
 * Strictly based on hms_erp.sql schema using audit_logs, prescriptions, users, patients.
 *
 * @package HMS-ERP
 * @version 1.1 (fixed parameter error, added export & chart analytics)
 */

if (session_status() === PHP_SESSION_NONE) session_start();

// ============================================
// 1. IRONCLAD AUTHENTICATION – PHARMACIST ONLY
// ============================================
if (!isset($_SESSION['user_id']) || !isset($_SESSION['branch_id']) || ($_SESSION['user_type'] ?? '') !== 'pharmacist') {
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized Access. Strict Pharmacist forensic clearance required.']);
        exit;
    }
    header('Location: ../login.php');
    exit;
}

require_once __DIR__ . '/../../config/db_connection.php';
date_default_timezone_set('Africa/Addis_Ababa');
$pdo = getDB();
if (!$pdo) die('Database connection failed.');

// CSRF token for mutation requests
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Predefined list of narcotic / controlled substance keywords (case‑insensitive)
$narcoticKeywords = [
    'morphine', 'fentanyl', 'oxycodone', 'hydrocodone', 'codeine', 'meperidine',
    'hydromorphone', 'methadone', 'buprenorphine', 'naloxone', 'tramadol',
    'diazepam', 'alprazolam', 'lorazepam', 'clonazepam', 'midazolam',
    'amphetamine', 'methylphenidate', 'modafinil', 'carisoprodol', 'zolpidem',
    'pentobarbital', 'secobarbital', 'phenobarbital', 'ketamine', 'anabolic steroid'
];
$keywordRegex = implode('|', array_map('preg_quote', $narcoticKeywords));

// ============================================
// 2. ASYNCHRONOUS AJAX ROUTER PIPELINE
// ============================================
if (isset($_GET['action'])) {
    error_reporting(0);
    ini_set('display_errors', 0);
    if (ob_get_level()) ob_clean();
    header('Content-Type: application/json; charset=utf-8');

    // Re-authenticate for every AJAX call
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['branch_id']) || ($_SESSION['user_type'] ?? '') !== 'pharmacist') {
        echo json_encode(['status' => 'error', 'message' => 'Session expired or unauthorized.']);
        exit;
    }

    $branchId = (int)$_SESSION['branch_id'];
    $action = $_GET['action'];

    // ---------- 2.1 Load Forensic Ledger (High‑Risk Narcotic Logs) ----------
    if ($action === 'load_forensic_ledger') {
        $page = (int)($_GET['page'] ?? 1);
        $limit = 25;
        $offset = ($page - 1) * $limit;

        $patientName = trim($_GET['patient_name'] ?? '');
        $doctorName = trim($_GET['doctor_name'] ?? '');
        $medicineFilter = trim($_GET['medicine_name'] ?? '');
        $dateFrom = trim($_GET['date_from'] ?? '');
        $dateTo = trim($_GET['date_to'] ?? '');

        try {
            // Build SQL using REGEXP to avoid many placeholders
            $sql = "
                SELECT 
                    al.id AS audit_id,
                    al.created_at AS event_time,
                    al.action_type,
                    al.ip_address,
                    al.user_agent,
                    al.new_values,
                    al.old_values,
                    u.id AS pharmacist_id,
                    CONCAT(u.first_name, ' ', COALESCE(u.middle_name, ''), ' ', u.last_name) AS pharmacist_name,
                    p.id AS prescription_id,
                    p.status AS prescription_status,
                    pat.id AS patient_id,
                    CONCAT(pat.first_name, ' ', COALESCE(pat.middle_name, ''), ' ', pat.last_name) AS patient_name,
                    doc.id AS doctor_id,
                    CONCAT(doc.first_name, ' ', COALESCE(doc.middle_name, ''), ' ', doc.last_name) AS doctor_name,
                    JSON_UNQUOTE(JSON_EXTRACT(al.new_values, '$.medicine_name')) AS medicine_name
                FROM audit_logs al
                INNER JOIN users u ON al.user_id = u.id
                LEFT JOIN prescriptions p ON al.record_id = p.id AND al.target_table = 'prescriptions'
                LEFT JOIN patients pat ON p.patient_id = pat.id
                LEFT JOIN users doc ON p.doctor_id = doc.id
                WHERE al.branch_id = :branch_id
                  AND al.target_table = 'prescriptions'
                  AND al.new_values REGEXP :keyword_regex
            ";
            $params = [
                ':branch_id' => $branchId,
                ':keyword_regex' => '"(' . $keywordRegex . ')"'
            ];

            if (!empty($patientName)) {
                $sql .= " AND CONCAT(pat.first_name, ' ', COALESCE(pat.middle_name, ''), ' ', pat.last_name) LIKE :patient_name";
                $params[':patient_name'] = "%{$patientName}%";
            }
            if (!empty($doctorName)) {
                $sql .= " AND CONCAT(doc.first_name, ' ', COALESCE(doc.middle_name, ''), ' ', doc.last_name) LIKE :doctor_name";
                $params[':doctor_name'] = "%{$doctorName}%";
            }
            if (!empty($medicineFilter)) {
                $sql .= " AND JSON_UNQUOTE(JSON_EXTRACT(al.new_values, '$.medicine_name')) LIKE :medicine_name";
                $params[':medicine_name'] = "%{$medicineFilter}%";
            }
            if (!empty($dateFrom)) {
                $sql .= " AND DATE(al.created_at) >= :date_from";
                $params[':date_from'] = $dateFrom;
            }
            if (!empty($dateTo)) {
                $sql .= " AND DATE(al.created_at) <= :date_to";
                $params[':date_to'] = $dateTo;
            }

            // Count total records for pagination
            $countSql = preg_replace('/SELECT.*?FROM/', 'SELECT COUNT(*) as total FROM', $sql, 1);
            $countStmt = $pdo->prepare($countSql);
            $countStmt->execute($params);
            $totalRecords = (int)$countStmt->fetchColumn();

            // Add ordering and pagination
            $sql .= " ORDER BY al.created_at DESC LIMIT :limit OFFSET :offset";
            $stmt = $pdo->prepare($sql);
            foreach ($params as $key => $value) {
                $paramType = (strpos($key, 'branch') !== false) ? PDO::PARAM_INT : PDO::PARAM_STR;
                $stmt->bindValue($key, $value, $paramType);
            }
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Compute counters (without pagination filters)
            $counterSql = "
                SELECT COUNT(*) AS total_dispensations,
                       COUNT(DISTINCT JSON_UNQUOTE(JSON_EXTRACT(al.new_values, '$.medicine_name'))) AS unique_substances
                FROM audit_logs al
                WHERE al.branch_id = :branch_id
                  AND al.target_table = 'prescriptions'
                  AND al.new_values REGEXP :keyword_regex
            ";
            $counterStmt = $pdo->prepare($counterSql);
            $counterStmt->execute([':branch_id' => $branchId, ':keyword_regex' => '"(' . $keywordRegex . ')"']);
            $counters = $counterStmt->fetch(PDO::FETCH_ASSOC);

            // Branch name for HUD
            $branchStmt = $pdo->prepare("SELECT name FROM branches WHERE id = :id AND deleted_at IS NULL");
            $branchStmt->execute([':id' => $branchId]);
            $branchName = $branchStmt->fetchColumn() ?: 'Unknown';

            echo json_encode([
                'status' => 'success',
                'logs' => $logs,
                'pagination' => [
                    'current_page' => $page,
                    'per_page' => $limit,
                    'total' => $totalRecords,
                    'total_pages' => ceil($totalRecords / $limit)
                ],
                'counters' => [
                    'total_dispensations' => (int)$counters['total_dispensations'],
                    'unique_substances' => (int)$counters['unique_substances'],
                    'branch_name' => $branchName
                ]
            ]);
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
        }
        exit;
    }

    // ---------- 2.2 Fetch Audit Details (JSON Payload Decoder) ----------
    if ($action === 'fetch_audit_details') {
        $auditId = (int)($_GET['audit_id'] ?? 0);
        if ($auditId <= 0) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid audit ID.']);
            exit;
        }
        try {
            $stmt = $pdo->prepare("
                SELECT new_values, old_values, created_at, action_type, target_table, record_id, ip_address, user_agent
                FROM audit_logs
                WHERE id = :id AND branch_id = :branch_id
            ");
            $stmt->execute([':id' => $auditId, ':branch_id' => $branchId]);
            $audit = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$audit) {
                echo json_encode(['status' => 'error', 'message' => 'Audit record not found.']);
                exit;
            }

            $newData = json_decode($audit['new_values'], true);
            $oldData = json_decode($audit['old_values'], true);

            if (!is_array($newData)) $newData = ['raw' => $audit['new_values']];
            if (!is_array($oldData)) $oldData = ['raw' => $audit['old_values']];

            // Resolve patient name if patient_id exists in new_values
            $patientName = 'Unknown';
            $medicineName = 'Unknown';
            if (isset($newData['patient_id'])) {
                $patStmt = $pdo->prepare("SELECT CONCAT(first_name, ' ', COALESCE(middle_name, ''), ' ', last_name) AS name FROM patients WHERE id = :id");
                $patStmt->execute([':id' => $newData['patient_id']]);
                $patientName = $patStmt->fetchColumn() ?: 'Unknown Patient';
            }
            if (isset($newData['medicine_name'])) {
                $medicineName = $newData['medicine_name'];
            }

            echo json_encode([
                'status' => 'success',
                'audit' => [
                    'id' => $auditId,
                    'created_at' => $audit['created_at'],
                    'action_type' => $audit['action_type'],
                    'target_table' => $audit['target_table'],
                    'record_id' => $audit['record_id'],
                    'ip_address' => $audit['ip_address'],
                    'user_agent' => $audit['user_agent'],
                    'patient_name' => $patientName,
                    'medicine_name' => $medicineName,
                    'old_values' => $oldData,
                    'new_values' => $newData
                ]
            ]);
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
        }
        exit;
    }

    // ---------- 2.3 Export to CSV ----------
    if ($action === 'export_csv') {
        $patientName = trim($_GET['patient_name'] ?? '');
        $doctorName = trim($_GET['doctor_name'] ?? '');
        $medicineFilter = trim($_GET['medicine_name'] ?? '');
        $dateFrom = trim($_GET['date_from'] ?? '');
        $dateTo = trim($_GET['date_to'] ?? '');

        try {
            $sql = "
                SELECT 
                    al.id AS audit_id,
                    al.created_at AS event_time,
                    al.action_type,
                    al.ip_address,
                    al.user_agent,
                    CONCAT(u.first_name, ' ', COALESCE(u.middle_name, ''), ' ', u.last_name) AS pharmacist_name,
                    CONCAT(pat.first_name, ' ', COALESCE(pat.middle_name, ''), ' ', pat.last_name) AS patient_name,
                    CONCAT(doc.first_name, ' ', COALESCE(doc.middle_name, ''), ' ', doc.last_name) AS doctor_name,
                    JSON_UNQUOTE(JSON_EXTRACT(al.new_values, '$.medicine_name')) AS medicine_name,
                    JSON_UNQUOTE(JSON_EXTRACT(al.new_values, '$.dosage')) AS dosage,
                    JSON_UNQUOTE(JSON_EXTRACT(al.new_values, '$.frequency')) AS frequency,
                    JSON_UNQUOTE(JSON_EXTRACT(al.new_values, '$.quantity')) AS quantity
                FROM audit_logs al
                INNER JOIN users u ON al.user_id = u.id
                LEFT JOIN prescriptions p ON al.record_id = p.id AND al.target_table = 'prescriptions'
                LEFT JOIN patients pat ON p.patient_id = pat.id
                LEFT JOIN users doc ON p.doctor_id = doc.id
                WHERE al.branch_id = :branch_id
                  AND al.target_table = 'prescriptions'
                  AND al.new_values REGEXP :keyword_regex
            ";
            $params = [
                ':branch_id' => $branchId,
                ':keyword_regex' => '"(' . $keywordRegex . ')"'
            ];

            if (!empty($patientName)) {
                $sql .= " AND CONCAT(pat.first_name, ' ', COALESCE(pat.middle_name, ''), ' ', pat.last_name) LIKE :patient_name";
                $params[':patient_name'] = "%{$patientName}%";
            }
            if (!empty($doctorName)) {
                $sql .= " AND CONCAT(doc.first_name, ' ', COALESCE(doc.middle_name, ''), ' ', doc.last_name) LIKE :doctor_name";
                $params[':doctor_name'] = "%{$doctorName}%";
            }
            if (!empty($medicineFilter)) {
                $sql .= " AND JSON_UNQUOTE(JSON_EXTRACT(al.new_values, '$.medicine_name')) LIKE :medicine_name";
                $params[':medicine_name'] = "%{$medicineFilter}%";
            }
            if (!empty($dateFrom)) {
                $sql .= " AND DATE(al.created_at) >= :date_from";
                $params[':date_from'] = $dateFrom;
            }
            if (!empty($dateTo)) {
                $sql .= " AND DATE(al.created_at) <= :date_to";
                $params[':date_to'] = $dateTo;
            }
            $sql .= " ORDER BY al.created_at DESC";

            $stmt = $pdo->prepare($sql);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
            }
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Set CSV headers
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="narcotics_audit_' . date('Y-m-d') . '.csv"');
            $output = fopen('php://output', 'w');
            fputcsv($output, ['Audit ID', 'Event Time', 'Action', 'Pharmacist', 'Patient', 'Doctor', 'Medicine', 'Dosage', 'Frequency', 'Quantity', 'IP Address', 'User Agent']);
            foreach ($rows as $row) {
                fputcsv($output, [
                    $row['audit_id'],
                    $row['event_time'],
                    $row['action_type'],
                    $row['pharmacist_name'],
                    $row['patient_name'],
                    $row['doctor_name'],
                    $row['medicine_name'],
                    $row['dosage'],
                    $row['frequency'],
                    $row['quantity'],
                    $row['ip_address'],
                    $row['user_agent']
                ]);
            }
            fclose($output);
            exit;
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'Export failed: ' . $e->getMessage()]);
            exit;
        }
    }

    // ---------- 2.4 Monthly Statistics for Chart ----------
    if ($action === 'monthly_stats') {
        try {
            $sql = "
                SELECT 
                    DATE_FORMAT(al.created_at, '%Y-%m') AS month,
                    COUNT(*) AS total_dispensations
                FROM audit_logs al
                WHERE al.branch_id = :branch_id
                  AND al.target_table = 'prescriptions'
                  AND al.new_values REGEXP :keyword_regex
                  AND al.created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
                GROUP BY DATE_FORMAT(al.created_at, '%Y-%m')
                ORDER BY month ASC
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':branch_id' => $branchId, ':keyword_regex' => '"(' . $keywordRegex . ')"']);
            $stats = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['status' => 'success', 'stats' => $stats]);
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
        }
        exit;
    }

    echo json_encode(['status' => 'error', 'message' => 'Invalid action.']);
    exit;
}

// ============================================
// 3. INITIAL PAGE LOAD – BRANCH NAME FOR DISPLAY
// ============================================
$branchId = (int)$_SESSION['branch_id'];
$branchName = 'Unknown Branch';
try {
    $branchStmt = $pdo->prepare("SELECT name FROM branches WHERE id = :id AND deleted_at IS NULL");
    $branchStmt->execute([':id' => $branchId]);
    $branchRow = $branchStmt->fetch(PDO::FETCH_ASSOC);
    if ($branchRow) {
        $branchName = htmlspecialchars($branchRow['name']);
    }
} catch (PDOException $e) {
    error_log('Failed to fetch branch name: ' . $e->getMessage());
}
$currentDateDisplay = date('l, F j, Y');
$currentTimeDisplay = date('g:i A');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Narcotics Forensic Audit | Pharmacist | HMS ERP</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
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
        body {
            font-family: 'Inter', sans-serif;
            background: #f5f7fb;
            margin: 0;
            padding: 0;
            overflow-x: hidden;
        }
        .content-page {
            margin-left: 260px;
            margin-top: 70px;
            padding: 24px 32px;
            transition: margin-left 0.3s;
            min-height: calc(100vh - 70px);
            background: #f5f7fb;
        }
        body.erp-sidebar-collapsed .content-page {
            margin-left: 80px;
        }
        @media (max-width: 768px) {
            .content-page {
                margin-left: 0 !important;
                padding: 16px;
            }
        }
        .welcome-header {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--primary) 100%);
            border-radius: 24px;
            padding: 24px 32px;
            color: white;
            margin-bottom: 28px;
        }
        .stat-card {
            background: white;
            border-radius: 24px;
            padding: 20px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
            transition: transform 0.2s;
            height: 100%;
        }
        .stat-card:hover {
            transform: translateY(-3px);
        }
        .stat-number {
            font-size: 2.2rem;
            font-weight: 700;
            margin-bottom: 0;
        }
        .filter-bar {
            background: white;
            border-radius: 24px;
            padding: 20px;
            margin-bottom: 24px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
        }
        .data-table th {
            font-size: 13px;
            font-weight: 600;
            background: #f8fafc;
            border-bottom: 1px solid var(--gray-light);
        }
        .data-table td {
            vertical-align: middle;
            padding: 12px 16px;
        }
        .badge-action {
            padding: 4px 12px;
            border-radius: 30px;
            font-size: 12px;
            font-weight: 600;
        }
        .badge-insert { background: #e8f5e9; color: #2e7d32; }
        .badge-update { background: #fff3e0; color: #e65100; }
        .badge-delete { background: #ffebee; color: #c62828; }
        .btn-inspect {
            background: var(--info);
            color: white;
            border: none;
            border-radius: 30px;
            padding: 4px 12px;
            font-size: 12px;
        }
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(255,255,255,0.7);
            z-index: 2000;
            display: none;
            align-items: center;
            justify-content: center;
        }
        .loading-overlay.active { display: flex; }
        .spinner {
            width: 40px;
            height: 40px;
            border: 3px solid var(--gray-light);
            border-top-color: var(--primary);
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
        .refresh-btn {
            background: white;
            border: 1px solid var(--gray-light);
            border-radius: 40px;
            padding: 6px 16px;
            font-size: 13px;
        }
        .json-viewer {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 15px;
            font-family: monospace;
            font-size: 13px;
            white-space: pre-wrap;
            word-break: break-word;
        }
        .chart-container {
            background: white;
            border-radius: 24px;
            padding: 20px;
            margin-bottom: 24px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
        }
    </style>
</head>
<body>
<div id="wrapper">
    <?php include('assets/inc/nav.php'); ?>
    <?php include('assets/inc/sidebar.php'); ?>
    <div class="content-page">
        <div class="container-fluid">
            <div class="welcome-header">
                <div class="row align-items-center">
                    <div class="col-md-8">
                        <div class="welcome-title"><i class="fas fa-skull-crosswalk me-2"></i>Narcotics & Controlled Substance Forensic Audit</div>
                        <div class="welcome-subtitle">High‑risk prescription audit trail | JSON payload inspection | Pharmacist forensic clearance</div>
                    </div>
                    <div class="col-md-4 text-md-end mt-3 mt-md-0">
                        <div class="time-badge" style="background: rgba(255,255,255,0.15); padding: 8px 16px; border-radius: 12px;">
                            <i class="far fa-calendar-alt"></i> <?php echo $currentDateDisplay; ?>
                            <i class="far fa-clock ms-2"></i> <?php echo $currentTimeDisplay; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Live Telemetry Counters -->
            <div class="row g-4 mb-4">
                <div class="col-md-4">
                    <div class="stat-card text-center">
                        <i class="fas fa-capsules fa-2x text-danger mb-2"></i>
                        <h3 class="stat-number" id="totalDispensations">0</h3>
                        <p class="text-muted mb-0">Total Controlled Dispensations Logged</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stat-card text-center">
                        <i class="fas fa-tablets fa-2x text-warning mb-2"></i>
                        <h3 class="stat-number" id="uniqueSubstances">0</h3>
                        <p class="text-muted mb-0">Unique Narcotic Substances Traced</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stat-card text-center">
                        <i class="fas fa-shield-alt fa-2x text-primary mb-2"></i>
                        <h3 class="stat-number"><?php echo $branchName; ?></h3>
                        <p class="text-muted mb-0">Active Branch Security Node</p>
                    </div>
                </div>
            </div>

            <!-- Monthly Trend Chart -->
            <div class="chart-container">
                <h5 class="mb-3"><i class="fas fa-chart-line me-2 text-primary"></i>Monthly Narcotic Dispensation Trend (Last 12 Months)</h5>
                <canvas id="trendChart" height="120"></canvas>
            </div>

            <!-- Search Control Banner -->
            <div class="filter-bar">
                <h5 class="mb-3"><i class="fas fa-filter me-2 text-primary"></i>Forensic Search Filters</h5>
                <div class="row g-3 align-items-end">
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">Patient Name</label>
                        <input type="text" id="filterPatient" class="form-control" placeholder="Enter patient name">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">Prescribing Doctor</label>
                        <input type="text" id="filterDoctor" class="form-control" placeholder="Doctor name">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label fw-semibold">Medicine Name</label>
                        <input type="text" id="filterMedicine" class="form-control" placeholder="e.g., Morphine">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label fw-semibold">Date From</label>
                        <input type="date" id="filterDateFrom" class="form-control">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label fw-semibold">Date To</label>
                        <input type="date" id="filterDateTo" class="form-control">
                    </div>
                </div>
                <div class="row mt-3">
                    <div class="col-12">
                        <button class="btn btn-primary me-2" id="applyFiltersBtn"><i class="fas fa-search me-1"></i> Apply Filters</button>
                        <button class="btn btn-success me-2" id="exportCsvBtn"><i class="fas fa-file-csv me-1"></i> Export to CSV</button>
                        <button class="btn btn-outline-secondary" id="manualRefreshBtn"><i class="fas fa-sync-alt me-1"></i> Refresh Grid</button>
                    </div>
                </div>
            </div>

            <!-- Forensic Audit Grid -->
            <div class="card shadow-sm border-0 rounded-4">
                <div class="card-header bg-white border-0 py-3 d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="fas fa-clipboard-list me-2 text-primary"></i>High‑Risk Dispensation Logs (Narcotics / Controlled)</h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover data-table mb-0" style="width:100%">
                            <thead>
                                <tr>
                                    <th>Audit ID</th>
                                    <th>Timestamp</th>
                                    <th>Pharmacist</th>
                                    <th>Patient</th>
                                    <th>Prescribing Doctor</th>
                                    <th>Medicine Name</th>
                                    <th>Action</th>
                                    <th>IP Address</th>
                                    <th>Forensic View</th>
                                </tr>
                            </thead>
                            <tbody id="forensicTbody">
                                <tr><td colspan="9" class="text-center py-5">Loading forensic audit data...</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="card-footer bg-white border-0 py-3">
                    <nav aria-label="Page navigation">
                        <ul class="pagination justify-content-end mb-0" id="pagination"></ul>
                    </nav>
                </div>
            </div>
        </div>
    </div>
    <?php include('assets/inc/footer.php'); ?>
</div>

<!-- Forensic Snapshot Delta Decoder Modal -->
<div class="modal fade" id="auditModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-microscope me-2"></i>Forensic Audit Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="auditModalBody">
                <div class="text-center">Loading forensic data...</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Loading Overlay -->
<div id="loadingOverlay" class="loading-overlay"><div class="spinner"></div></div>

<script>
const csrfToken = "<?php echo $csrf_token; ?>";
let currentPage = 1;
let currentFilters = {
    patient_name: '',
    doctor_name: '',
    medicine_name: '',
    date_from: '',
    date_to: ''
};
let trendChart = null;

function showLoading(show) {
    if (show) $('#loadingOverlay').addClass('active');
    else $('#loadingOverlay').removeClass('active');
}

function loadMonthlyStats() {
    $.ajax({
        url: window.location.pathname + '?action=monthly_stats',
        method: 'GET',
        dataType: 'json',
        cache: false,
        success: function(res) {
            if (res.status === 'success' && res.stats) {
                const months = res.stats.map(s => s.month);
                const counts = res.stats.map(s => s.total_dispensations);
                if (trendChart) trendChart.destroy();
                const ctx = document.getElementById('trendChart').getContext('2d');
                trendChart = new Chart(ctx, {
                    type: 'line',
                    data: {
                        labels: months,
                        datasets: [{
                            label: 'Narcotic Dispensations',
                            data: counts,
                            borderColor: '#ef476f',
                            backgroundColor: 'rgba(239,71,111,0.1)',
                            tension: 0.3,
                            fill: true
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: true,
                        plugins: {
                            legend: { position: 'top' }
                        }
                    }
                });
            }
        },
        error: function() { console.error('Failed to load monthly stats'); }
    });
}

function loadForensicLedger(page = 1) {
    showLoading(true);
    $.ajax({
        url: window.location.pathname + '?action=load_forensic_ledger',
        method: 'GET',
        dataType: 'json',
        data: {
            page: page,
            patient_name: currentFilters.patient_name,
            doctor_name: currentFilters.doctor_name,
            medicine_name: currentFilters.medicine_name,
            date_from: currentFilters.date_from,
            date_to: currentFilters.date_to,
            csrf_token: csrfToken
        },
        cache: false,
        success: function(res) {
            showLoading(false);
            if (res.status === 'success') {
                $('#totalDispensations').text(res.counters.total_dispensations);
                $('#uniqueSubstances').text(res.counters.unique_substances);

                let tbody = '';
                if (res.logs.length === 0) {
                    tbody = '<tr><td colspan="9" class="text-center py-4 text-muted">No narcotic dispensation logs found.</td></tr>';
                } else {
                    res.logs.forEach(function(log) {
                        let actionBadge = '';
                        if (log.action_type === 'INSERT') actionBadge = 'badge-insert';
                        else if (log.action_type === 'UPDATE') actionBadge = 'badge-update';
                        else if (log.action_type === 'DELETE') actionBadge = 'badge-delete';
                        else actionBadge = 'badge-secondary';

                        const eventDate = new Date(log.event_time).toLocaleString();
                        const patientDisplay = log.patient_name ? escapeHtml(log.patient_name) : '—';
                        const doctorDisplay = log.doctor_name ? escapeHtml(log.doctor_name) : '—';
                        const medicineDisplay = log.medicine_name ? escapeHtml(log.medicine_name) : '—';
                        const pharmacistDisplay = log.pharmacist_name ? escapeHtml(log.pharmacist_name) : '—';

                        tbody += `
                            <tr>
                                <td>${log.audit_id}</td>
                                <td>${eventDate}</td>
                                <td>${pharmacistDisplay}</td>
                                <td>${patientDisplay}</td>
                                <td>${doctorDisplay}</td>
                                <td>${medicineDisplay}</td>
                                <td><span class="badge-action ${actionBadge}">${log.action_type}</span></td>
                                <td><code>${escapeHtml(log.ip_address)}</code></td>
                                <td><button class="btn-inspect" data-audit-id="${log.audit_id}"><i class="fas fa-search-plus me-1"></i>Inspect JSON</button></td>
                            </tr>
                        `;
                    });
                }
                $('#forensicTbody').html(tbody);

                let paginationHtml = '';
                const totalPages = res.pagination.total_pages;
                const current = res.pagination.current_page;
                if (totalPages > 1) {
                    paginationHtml += `<li class="page-item ${current === 1 ? 'disabled' : ''}"><a class="page-link" href="#" data-page="${current-1}">Previous</a></li>`;
                    for (let i = 1; i <= totalPages; i++) {
                        if (i === current) {
                            paginationHtml += `<li class="page-item active"><span class="page-link">${i}</span></li>`;
                        } else if (Math.abs(i - current) <= 2 || i === 1 || i === totalPages) {
                            paginationHtml += `<li class="page-item"><a class="page-link" href="#" data-page="${i}">${i}</a></li>`;
                        } else if (Math.abs(i - current) === 3) {
                            paginationHtml += `<li class="page-item disabled"><span class="page-link">...</span></li>`;
                        }
                    }
                    paginationHtml += `<li class="page-item ${current === totalPages ? 'disabled' : ''}"><a class="page-link" href="#" data-page="${current+1}">Next</a></li>`;
                }
                $('#pagination').html(paginationHtml);

                $('.page-link[data-page]').off('click').on('click', function(e) {
                    e.preventDefault();
                    const newPage = parseInt($(this).data('page'));
                    if (!isNaN(newPage) && newPage >= 1 && newPage <= totalPages) {
                        currentPage = newPage;
                        loadForensicLedger(currentPage);
                    }
                });

                $('.btn-inspect').off('click').on('click', function() {
                    const auditId = $(this).data('audit-id');
                    showAuditDetails(auditId);
                });
            } else {
                $('#forensicTbody').html(`<tr><td colspan="9" class="text-center text-danger">Error: ${escapeHtml(res.message)}</td></tr>`);
                $('#pagination').html('');
            }
        },
        error: function(xhr, status, error) {
            showLoading(false);
            console.error('AJAX error:', error);
            $('#forensicTbody').html('<tr><td colspan="9" class="text-center text-danger">Network error. Please refresh the page.</td></tr>');
            $('#pagination').html('');
        }
    });
}

function showAuditDetails(auditId) {
    $('#auditModalBody').html('<div class="text-center"><i class="fas fa-spinner fa-spin"></i> Loading forensic payload...</div>');
    $.ajax({
        url: window.location.pathname + '?action=fetch_audit_details',
        method: 'GET',
        dataType: 'json',
        data: { audit_id: auditId, csrf_token: csrfToken },
        success: function(res) {
            if (res.status === 'success') {
                const data = res.audit;
                let html = `
                    <div class="mb-3">
                        <strong>Audit ID:</strong> ${data.id}<br>
                        <strong>Event Time:</strong> ${new Date(data.created_at).toLocaleString()}<br>
                        <strong>Action:</strong> ${data.action_type}<br>
                        <strong>Target Table:</strong> ${data.target_table}<br>
                        <strong>Record ID:</strong> ${data.record_id}<br>
                        <strong>Patient:</strong> ${escapeHtml(data.patient_name)}<br>
                        <strong>Medicine Name:</strong> ${escapeHtml(data.medicine_name)}<br>
                        <strong>IP Address:</strong> ${escapeHtml(data.ip_address)}<br>
                        <strong>User Agent:</strong> ${escapeHtml(data.user_agent)}<br>
                    </div>
                    <hr>
                    <h6>📦 New Values (After Change)</h6>
                    <div class="json-viewer">${formatJson(data.new_values)}</div>
                    <hr>
                    <h6>🗑️ Old Values (Before Change)</h6>
                    <div class="json-viewer">${formatJson(data.old_values)}</div>
                `;
                $('#auditModalBody').html(html);
                $('#auditModal').modal('show');
            } else {
                Swal.fire('Error', res.message, 'error');
            }
        },
        error: function() { Swal.fire('Error', 'Could not fetch forensic details.', 'error'); }
    });
}

function formatJson(obj) {
    if (!obj) return '<em>No data available</em>';
    try {
        let str;
        if (typeof obj === 'string') {
            str = obj;
        } else {
            str = JSON.stringify(obj, null, 2);
        }
        return `<pre style="margin:0; white-space:pre-wrap;">${escapeHtml(str)}</pre>`;
    } catch(e) {
        return '<em>Invalid JSON structure</em>';
    }
}

function applyFilters() {
    currentFilters = {
        patient_name: $('#filterPatient').val().trim(),
        doctor_name: $('#filterDoctor').val().trim(),
        medicine_name: $('#filterMedicine').val().trim(),
        date_from: $('#filterDateFrom').val(),
        date_to: $('#filterDateTo').val()
    };
    currentPage = 1;
    loadForensicLedger(currentPage);
}

function exportCSV() {
    // Collect current filter values and redirect to export action
    const params = new URLSearchParams({
        action: 'export_csv',
        patient_name: $('#filterPatient').val().trim(),
        doctor_name: $('#filterDoctor').val().trim(),
        medicine_name: $('#filterMedicine').val().trim(),
        date_from: $('#filterDateFrom').val(),
        date_to: $('#filterDateTo').val(),
        csrf_token: csrfToken
    });
    window.location.href = window.location.pathname + '?' + params.toString();
}

$(document).ready(function() {
    loadMonthlyStats();
    loadForensicLedger(1);
    $('#applyFiltersBtn').on('click', applyFilters);
    $('#manualRefreshBtn').on('click', function() {
        loadMonthlyStats();
        loadForensicLedger(currentPage);
        Swal.fire('Refreshed', 'Forensic audit data updated.', 'success');
    });
    $('#exportCsvBtn').on('click', exportCSV);
    $('#filterPatient, #filterDoctor, #filterMedicine').on('keypress', function(e) {
        if (e.which === 13) applyFilters();
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
</script>
</body>
</html>