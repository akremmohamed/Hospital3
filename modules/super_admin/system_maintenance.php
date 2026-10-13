<?php
/**
 * Super Admin - Backup, System Settings & Maintenance
 * HMS ERP System
 * 
 * @package HMS-ERP
 * @version 3.2 (Fixed DataTables column count warning)
 */

if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../../config/db_connection.php';
require_once __DIR__ . '/../../includes/auth_helper.php';

// ============================================
// AUTHENTICATION & RBAC - SUPER ADMIN ONLY
// ============================================
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../index.php');
    exit;
}
if (($_SESSION['user_type'] ?? '') !== 'super_admin') {
    die('Access Denied. Super Admin privileges required.');
}

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

$user_id   = (int)($_SESSION['user_id'] ?? 0);
$user_name = $_SESSION['first_name'] ?? $_SESSION['username'] ?? 'Super Admin';

$db_available = is_db_available();
if (!$db_available) die('Database connection failed.');

$pdo = getDB();

// ============================================
// CREATE REQUIRED SYSTEM TABLES (SAFE MODE)
// ============================================
try {
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
    
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `system_settings` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `setting_key` varchar(100) NOT NULL,
            `setting_value` text DEFAULT NULL,
            `category` varchar(50) DEFAULT NULL,
            `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_setting_key` (`setting_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `backup_logs` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `file_name` varchar(255) NOT NULL,
            `file_path` varchar(255) NOT NULL,
            `file_size` varchar(50) DEFAULT NULL,
            `created_by_user_id` int(10) UNSIGNED NOT NULL,
            `status` enum('success','failed') NOT NULL DEFAULT 'success',
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `created_by_user_id` (`created_by_user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
    
    $fkCheck = $pdo->query("SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS 
                            WHERE CONSTRAINT_SCHEMA = DATABASE() 
                            AND TABLE_NAME = 'backup_logs' 
                            AND CONSTRAINT_NAME = 'fk_backup_logs_user'");
    if ($fkCheck->fetchColumn() == 0) {
        $pdo->exec("ALTER TABLE `backup_logs` ADD CONSTRAINT `fk_backup_logs_user` FOREIGN KEY (`created_by_user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE");
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `notification_rules` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `event_trigger` varchar(100) NOT NULL,
            `recipient_role` varchar(50) NOT NULL,
            `is_email_enabled` tinyint(1) NOT NULL DEFAULT 1,
            `is_in_app_enabled` tinyint(1) NOT NULL DEFAULT 1,
            `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_event_role` (`event_trigger`,`recipient_role`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    $checkRules = $pdo->query("SELECT COUNT(*) FROM notification_rules")->fetchColumn();
    if ($checkRules == 0) {
        $defaultTriggers = ['critical_lab_result', 'low_stock_alert', 'new_emergency_admission', 'narcotic_prescribed'];
        $roles = ['doctor', 'nurse', 'pharmacist'];
        $stmt = $pdo->prepare("INSERT INTO notification_rules (event_trigger, recipient_role, is_email_enabled, is_in_app_enabled) VALUES (?, ?, 1, 1)");
        foreach ($defaultTriggers as $trigger) {
            foreach ($roles as $role) {
                $stmt->execute([$trigger, $role]);
            }
        }
    }

    $defaultSettings = [
        ['backup_retention_days', '30', 'backup'],
        ['backup_path', '../../backups/', 'backup'],
        ['system_maintenance_mode', '0', 'general']
    ];
    foreach ($defaultSettings as $set) {
        $exists = $pdo->prepare("SELECT id FROM system_settings WHERE setting_key = ?");
        $exists->execute([$set[0]]);
        if (!$exists->fetch()) {
            $ins = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, category) VALUES (?, ?, ?)");
            $ins->execute([$set[0], $set[1], $set[2]]);
        }
    }
    
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
} catch (PDOException $e) {
    error_log("System maintenance table setup: " . $e->getMessage());
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
}

// ============================================
// AJAX ROUTER (All endpoints with error catching)
// ============================================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'maintenance_action') {
    error_reporting(0);
    ini_set('display_errors', 0);
    if (ob_get_level()) ob_clean();
    header('Content-Type: application/json; charset=utf-8');

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
        exit;
    }

    $submitted = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $submitted)) {
        echo json_encode(['success' => false, 'message' => 'Security validation failed. Refresh the page.']);
        exit;
    }

    $action = (string)($_POST['action'] ?? '');
    $db = getDB();
    if (!$db) {
        echo json_encode(['success' => false, 'message' => 'Database connection failed']);
        exit;
    }

    try {
        // ---------- GET BACKUP HISTORY ----------
        if ($action === 'get_backup_history') {
            $sql = "SELECT bl.id, bl.file_name, bl.file_size, bl.status, bl.created_at, u.first_name, u.last_name
                    FROM backup_logs bl
                    LEFT JOIN users u ON bl.created_by_user_id = u.id
                    ORDER BY bl.created_at DESC";
            $stmt = $db->query($sql);
            $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
            // Format each record to include 'created_by' string for DataTables
            $formatted = [];
            foreach ($history as $row) {
                $formatted[] = [
                    'id'         => $row['id'],
                    'file_name'  => $row['file_name'],
                    'file_size'  => $row['file_size'],
                    'status'     => $row['status'],
                    'created_by' => trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')),
                    'created_at' => $row['created_at'],
                    'actions'    => ''
                ];
            }
            echo json_encode(['success' => true, 'history' => $formatted]);
            exit;
        }

        // ---------- RUN MANUAL BACKUP ----------
        if ($action === 'run_backup') {
            $pathStmt = $db->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'backup_path'");
            $pathStmt->execute();
            $backupDir = rtrim($pathStmt->fetchColumn(), '/') . '/';
            
            if (!realpath($backupDir)) {
                $backupDir = $_SERVER['DOCUMENT_ROOT'] . '/../' . ltrim($backupDir, './');
                $backupDir = rtrim(str_replace('//', '/', $backupDir), '/') . '/';
            }
            
            if (!is_dir($backupDir)) {
                if (!mkdir($backupDir, 0755, true)) {
                    echo json_encode(['success' => false, 'message' => 'Backup directory not writable: ' . $backupDir]);
                    exit;
                }
            }
            if (!is_writable($backupDir)) {
                echo json_encode(['success' => false, 'message' => 'Backup directory is not writable: ' . $backupDir]);
                exit;
            }
            
            $filename = 'hms_backup_' . date('Ymd_His') . '.sql';
            $filepath = $backupDir . $filename;
            
            $dbHost = defined('DB_HOST') ? DB_HOST : 'localhost';
            $dbName = defined('DB_NAME') ? DB_NAME : 'hms_erp';
            $dbUser = defined('DB_USER') ? DB_USER : 'root';
            $dbPass = defined('DB_PASSWORD') ? DB_PASSWORD : '';
            
            $mysqldumpPaths = ['mysqldump', '/usr/bin/mysqldump', '/usr/local/bin/mysqldump', 'C:\\xampp\\mysql\\bin\\mysqldump.exe'];
            $mysqldump = 'mysqldump';
            foreach ($mysqldumpPaths as $path) {
                if (file_exists($path) || (PHP_OS_FAMILY !== 'Windows' && is_executable($path))) {
                    $mysqldump = $path;
                    break;
                }
            }
            
            $command = sprintf(
                '"%s" --host=%s --user=%s --password=%s %s > "%s" 2>&1',
                $mysqldump,
                escapeshellarg($dbHost),
                escapeshellarg($dbUser),
                escapeshellarg($dbPass),
                escapeshellarg($dbName),
                escapeshellarg($filepath)
            );
            
            exec($command, $output, $returnVar);
            
            if ($returnVar === 0 && file_exists($filepath) && filesize($filepath) > 0) {
                $size = filesize($filepath);
                $sizeFormatted = ($size > 1048576) ? round($size / 1048576, 2) . ' MB' : round($size / 1024, 2) . ' KB';
                $logStmt = $db->prepare("INSERT INTO backup_logs (file_name, file_path, file_size, created_by_user_id, status) VALUES (?, ?, ?, ?, 'success')");
                $logStmt->execute([$filename, $filepath, $sizeFormatted, $user_id]);
                echo json_encode(['success' => true, 'message' => 'Backup created successfully.', 'file' => $filename]);
            } else {
                $errorMsg = implode("\n", $output);
                $logStmt = $db->prepare("INSERT INTO backup_logs (file_name, file_path, file_size, created_by_user_id, status) VALUES (?, ?, ?, ?, 'failed')");
                $logStmt->execute([$filename, $filepath, '0 B', $user_id]);
                echo json_encode(['success' => false, 'message' => 'Backup failed: ' . $errorMsg]);
            }
            exit;
        }

        // ---------- DELETE BACKUP ----------
        if ($action === 'delete_backup') {
            $backup_id = (int)($_POST['backup_id'] ?? 0);
            $stmt = $db->prepare("SELECT file_path FROM backup_logs WHERE id = ?");
            $stmt->execute([$backup_id]);
            $backup = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($backup) {
                if (file_exists($backup['file_path'])) unlink($backup['file_path']);
                $del = $db->prepare("DELETE FROM backup_logs WHERE id = ?");
                $del->execute([$backup_id]);
                echo json_encode(['success' => true, 'message' => 'Backup deleted.']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Backup not found.']);
            }
            exit;
        }

        // ---------- RESTORE DATABASE ----------
        if ($action === 'restore_backup') {
            $backup_id = (int)($_POST['backup_id'] ?? 0);
            $confirm = trim($_POST['confirm_text'] ?? '');
            if ($confirm !== 'RESTORE') {
                echo json_encode(['success' => false, 'message' => 'Confirmation text does not match. Type RESTORE to proceed.']);
                exit;
            }
            $stmt = $db->prepare("SELECT file_path FROM backup_logs WHERE id = ? AND status = 'success'");
            $stmt->execute([$backup_id]);
            $backup = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$backup || !file_exists($backup['file_path'])) {
                echo json_encode(['success' => false, 'message' => 'Backup file not found.']);
                exit;
            }
            $dbHost = defined('DB_HOST') ? DB_HOST : 'localhost';
            $dbName = defined('DB_NAME') ? DB_NAME : 'hms_erp';
            $dbUser = defined('DB_USER') ? DB_USER : 'root';
            $dbPass = defined('DB_PASSWORD') ? DB_PASSWORD : '';
            $mysqlPaths = ['mysql', '/usr/bin/mysql', '/usr/local/bin/mysql', 'C:\\xampp\\mysql\\bin\\mysql.exe'];
            $mysql = 'mysql';
            foreach ($mysqlPaths as $path) {
                if (file_exists($path) || (PHP_OS_FAMILY !== 'Windows' && is_executable($path))) {
                    $mysql = $path;
                    break;
                }
            }
            $command = sprintf(
                '"%s" --host=%s --user=%s --password=%s %s < "%s" 2>&1',
                $mysql,
                escapeshellarg($dbHost),
                escapeshellarg($dbUser),
                escapeshellarg($dbPass),
                escapeshellarg($dbName),
                escapeshellarg($backup['file_path'])
            );
            exec($command, $output, $returnVar);
            if ($returnVar === 0) {
                echo json_encode(['success' => true, 'message' => 'Database restored successfully.']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Restore failed: ' . implode("\n", $output)]);
            }
            exit;
        }

        // ---------- GET NOTIFICATION RULES ----------
        if ($action === 'get_notification_rules') {
            $sql = "SELECT id, event_trigger, recipient_role, is_email_enabled, is_in_app_enabled FROM notification_rules ORDER BY event_trigger, recipient_role";
            $stmt = $db->query($sql);
            $rules = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'rules' => $rules]);
            exit;
        }

        // ---------- UPDATE NOTIFICATION RULE ----------
        if ($action === 'update_notification_rule') {
            $rule_id = (int)($_POST['rule_id'] ?? 0);
            $field = $_POST['field'] ?? '';
            $value = (int)($_POST['value'] ?? 0);
            if (!in_array($field, ['is_email_enabled', 'is_in_app_enabled'])) {
                echo json_encode(['success' => false, 'message' => 'Invalid field.']);
                exit;
            }
            $update = $db->prepare("UPDATE notification_rules SET $field = ? WHERE id = ?");
            $update->execute([$value, $rule_id]);
            echo json_encode(['success' => true, 'message' => 'Notification rule updated.']);
            exit;
        }

        // ---------- GET GENERAL SETTINGS ----------
        if ($action === 'get_general_settings') {
            $sql = "SELECT setting_key, setting_value FROM system_settings";
            $stmt = $db->query($sql);
            $settings = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $settings[$row['setting_key']] = $row['setting_value'];
            }
            echo json_encode(['success' => true, 'settings' => $settings]);
            exit;
        }

        // ---------- SAVE GENERAL SETTINGS ----------
        if ($action === 'save_general_settings') {
            $backup_retention = (int)($_POST['backup_retention_days'] ?? 30);
            $maintenance_mode = isset($_POST['system_maintenance_mode']) ? 1 : 0;
            $backup_path = trim($_POST['backup_path'] ?? '../../backups/');
            $upd1 = $db->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = 'backup_retention_days'");
            $upd1->execute([$backup_retention]);
            $upd2 = $db->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = 'system_maintenance_mode'");
            $upd2->execute([$maintenance_mode]);
            $upd3 = $db->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = 'backup_path'");
            $upd3->execute([$backup_path]);
            echo json_encode(['success' => true, 'message' => 'Settings saved.']);
            exit;
        }

        echo json_encode(['success' => false, 'message' => 'Unknown action']);
        exit;
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        exit;
    }
}

// ============================================
// HANDLE DOWNLOAD REQUEST (separate GET)
// ============================================
if (isset($_GET['download_backup']) && is_numeric($_GET['download_backup'])) {
    session_start();
    if (!isset($_SESSION['user_id']) || ($_SESSION['user_type'] ?? '') !== 'super_admin') {
        die('Unauthorized');
    }
    $backup_id = (int)$_GET['download_backup'];
    $db = getDB();
    $stmt = $db->prepare("SELECT file_path, file_name FROM backup_logs WHERE id = ? AND status = 'success'");
    $stmt->execute([$backup_id]);
    $backup = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($backup && file_exists($backup['file_path'])) {
        header('Content-Type: application/sql');
        header('Content-Disposition: attachment; filename="' . basename($backup['file_name']) . '"');
        readfile($backup['file_path']);
        exit;
    } else {
        die('File not found.');
    }
}

$current_date_display = date('l, F j, Y');
$current_time_display = date('g:i A');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Backup, System Settings & Maintenance | Super Admin | HMS ERP</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
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
            --gray: #6c757d;
            --gray-light: #e9ecef;
        }
        body { font-family: 'Inter', sans-serif; background: #f5f7fb; overflow-x: hidden; margin: 0; padding: 0; }
        .content-page {
            margin-left: 260px;
            margin-top: 70px;
            padding: 24px 32px;
            transition: margin-left 0.3s;
            min-height: calc(100vh - 70px);
            position: relative;
            z-index: 1;
            background: #f5f7fb;
        }
        body.erp-sidebar-collapsed .content-page { margin-left: 80px; }
        @media (max-width: 768px) {
            .content-page { margin-left: 0 !important; padding: 16px; }
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
            border-radius: 20px;
            padding: 20px;
            margin-bottom: 20px;
            border: 1px solid var(--gray-light);
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }
        .quick-btn {
            padding: 10px 20px;
            border-radius: 14px;
            font-size: 14px;
            font-weight: 600;
            border: none;
            cursor: pointer;
            transition: all 0.2s;
        }
        .quick-btn-primary { background: linear-gradient(135deg, var(--primary), var(--primary-dark)); color: white; }
        .quick-btn-danger { background: linear-gradient(135deg, var(--danger), #c0392b); color: white; }
        .quick-btn-outline { background: white; border: 1px solid var(--gray-light); color: var(--dark); }
        .toggle-switch { position: relative; display: inline-block; width: 50px; height: 24px; }
        .toggle-switch input { opacity: 0; width: 0; height: 0; }
        .slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: #ccc; transition: 0.4s; border-radius: 24px; }
        .slider:before { position: absolute; content: ""; height: 18px; width: 18px; left: 3px; bottom: 3px; background-color: white; transition: 0.4s; border-radius: 50%; }
        input:checked + .slider { background-color: var(--success); }
        input:checked + .slider:before { transform: translateX(26px); }
        .cron-box { background: #f8fafc; border-radius: 16px; padding: 15px; font-family: monospace; font-size: 13px; border-left: 4px solid var(--primary); }
        .loading-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(255,255,255,0.7); z-index: 2000; display: none; align-items: center; justify-content: center; }
        .loading-overlay.active { display: flex; }
        .spinner { width: 40px; height: 40px; border: 3px solid var(--gray-light); border-top-color: var(--primary); border-radius: 50%; animation: spin 0.8s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }
        .dataTables_wrapper { overflow-x: auto; }
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
                        <div class="welcome-title">Backup, System Settings & Maintenance</div>
                        <div class="welcome-subtitle">Super Admin Console: Database vault, notification rules, and cron scheduler.</div>
                    </div>
                    <div class="col-md-4 text-md-end mt-3 mt-md-0">
                        <div class="time-badge" style="background: rgba(255,255,255,0.15); padding: 8px 16px; border-radius: 12px;">
                            <i class="far fa-calendar-alt"></i> <?php echo htmlspecialchars($current_date_display); ?>
                            <i class="far fa-clock ms-2"></i> <?php echo htmlspecialchars($current_time_display); ?>
                        </div>
                    </div>
                </div>
            </div>

            <ul class="nav nav-tabs mb-4" id="maintenanceTabs" role="tablist">
                <li class="nav-item"><button class="nav-link active" id="backup-tab" data-bs-toggle="tab" data-bs-target="#backup">Database Backup Vault</button></li>
                <li class="nav-item"><button class="nav-link" id="notifications-tab" data-bs-toggle="tab" data-bs-target="#notifications">Notification Rules</button></li>
                <li class="nav-item"><button class="nav-link" id="cron-tab" data-bs-toggle="tab" data-bs-target="#cron">Cron Scheduling Terminal</button></li>
                <li class="nav-item"><button class="nav-link" id="settings-tab" data-bs-toggle="tab" data-bs-target="#settings">General Settings</button></li>
            </ul>

            <div class="tab-content">
                <!-- BACKUP VAULT TAB -->
                <div class="tab-pane fade show active" id="backup">
                    <div class="stat-card">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h5 class="mb-0"><i class="fas fa-database me-2"></i>Backup History</h5>
                            <div>
                                <button class="quick-btn quick-btn-outline me-2" id="refreshBackupBtn"><i class="fas fa-sync-alt me-2"></i>Refresh</button>
                                <button class="quick-btn quick-btn-primary" id="manualBackupBtn"><i class="fas fa-cloud-upload-alt me-2"></i>Create Manual Backup</button>
                            </div>
                        </div>
                        <div class="table-responsive">
                            <table id="backupTable" class="table table-hover" style="width:100%">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>File Name</th>
                                        <th>Size</th>
                                        <th>Status</th>
                                        <th>Created By</th>
                                        <th>Created At</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <!-- Empty tbody: DataTables will populate from AJAX -->
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- NOTIFICATION RULES TAB -->
                <div class="tab-pane fade" id="notifications">
                    <div class="stat-card">
                        <h5 class="mb-3"><i class="fas fa-bell me-2"></i>Clinical Notification Matrix</h5>
                        <div class="table-responsive">
                            <table id="rulesTable" class="table table-bordered">
                                <thead>
                                    <tr><th>Event Trigger</th><th>Recipient Role</th><th>Email Enabled</th><th>In-App Enabled</th></tr>
                                </thead>
                                <tbody>
                                    <tr><td colspan="4" class="text-center">Loading rules...</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- CRON SCHEDULING TERMINAL TAB -->
                <div class="tab-pane fade" id="cron">
                    <div class="stat-card">
                        <h5><i class="fas fa-clock me-2"></i>Automated Backup Scheduler</h5>
                        <p class="text-muted">To enable daily midnight automated backups, add the following cron job to your server (crontab -e).</p>
                        <div class="cron-box">
                            <code id="cronCommand">0 0 * * * php <?php echo realpath(__DIR__ . '/../../cron/db_backup_runner.php'); ?></code>
                            <button class="quick-btn quick-btn-outline mt-2" id="copyCronBtn"><i class="fas fa-copy"></i> Copy Command</button>
                        </div>
                        <hr>
                        <h6>Create automated backup script:</h6>
                        <div class="cron-box">
                            <pre style="background:#f1f3f5; padding:10px; border-radius:12px;">#!/usr/bin/env php
&lt;?php
// File: cron/db_backup_runner.php
require_once __DIR__ . '/../config/db_connection.php';
// ... backup logic (same as manual backup) ...
?&gt;</pre>
                            <small class="text-muted">Place the runner script in the cron directory to enable automated backups.</small>
                        </div>
                    </div>
                </div>

                <!-- GENERAL SETTINGS TAB -->
                <div class="tab-pane fade" id="settings">
                    <div class="stat-card">
                        <h5><i class="fas fa-sliders-h me-2"></i>System Configuration</h5>
                        <form id="generalSettingsForm">
                            <div class="mb-3">
                                <label>Backup Retention (Days)</label>
                                <input type="number" name="backup_retention_days" id="backupRetention" class="form-control" required>
                            </div>
                            <div class="mb-3">
                                <label>Backup Directory Path</label>
                                <input type="text" name="backup_path" id="backupPath" class="form-control" required>
                            </div>
                            <div class="form-check mb-3">
                                <input type="checkbox" name="system_maintenance_mode" id="maintenanceMode" class="form-check-input">
                                <label class="form-check-label">Enable Maintenance Mode (site inaccessible to non-admins)</label>
                            </div>
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                            <button type="button" class="quick-btn quick-btn-primary" id="saveSettingsBtn">Save Settings</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php include('assets/inc/footer.php'); ?>
</div>

<div id="loadingOverlay" class="loading-overlay"><div class="spinner"></div></div>

<script>
const csrfToken = "<?php echo $csrf_token; ?>";
let backupDataTable = null;

$(document).ready(function() {
    console.log("Document ready, initializing modules...");
    loadBackupHistory();
    loadNotificationRules();
    loadGeneralSettings();
    
    $('#manualBackupBtn').on('click', runManualBackup);
    $('#refreshBackupBtn').on('click', function() { loadBackupHistory(); });
    $('#saveSettingsBtn').on('click', saveGeneralSettings);
    $('#copyCronBtn').on('click', function(){
        let cmd = $('#cronCommand').text();
        navigator.clipboard.writeText(cmd).then(() => {
            Swal.fire('Copied', 'Cron command copied to clipboard.', 'success');
        }).catch(() => {
            Swal.fire('Error', 'Could not copy command.', 'error');
        });
    });
});

function loadBackupHistory() {
    showLoading(true);
    $.ajax({
        url: window.location.pathname + '?ajax=maintenance_action',
        method: 'POST',
        dataType: 'json',
        data: { action: 'get_backup_history', csrf_token: csrfToken },
        timeout: 30000,
        success: function(res){
            showLoading(false);
            if (res.success && Array.isArray(res.history)) {
                // Destroy existing DataTable if any
                if (backupDataTable) {
                    backupDataTable.destroy();
                    backupDataTable = null;
                }
                
                // Initialize DataTable with server-side processing and column mapping
                backupDataTable = $('#backupTable').DataTable({
                    data: res.history,
                    order: [[0, 'desc']],
                    pageLength: 10,
                    autoWidth: false,
                    columns: [
                        { data: 'id' },
                        { data: 'file_name' },
                        { data: 'file_size' },
                        { 
                            data: 'status',
                            render: function(data) {
                                return data === 'success' 
                                    ? '<span class="badge bg-success">success</span>' 
                                    : '<span class="badge bg-danger">failed</span>';
                            }
                        },
                        { data: 'created_by' },
                        { data: 'created_at' },
                        {
                            data: null,
                            orderable: false,
                            searchable: false,
                            render: function(row) {
                                if (row.status === 'success') {
                                    return `<a href="?download_backup=${row.id}" class="btn btn-sm btn-info" download><i class="fas fa-download"></i> Download</a>
                                            <button class="btn btn-sm btn-warning restore-backup" data-id="${row.id}"><i class="fas fa-undo-alt"></i> Restore</button>
                                            <button class="btn btn-sm btn-danger delete-backup" data-id="${row.id}"><i class="fas fa-trash"></i> Delete</button>`;
                                }
                                return '';
                            }
                        }
                    ]
                });
                
                // Rebind buttons after DataTable renders
                $('#backupTable').on('click', '.restore-backup', function() { restoreBackup($(this).data('id')); });
                $('#backupTable').on('click', '.delete-backup', function() { deleteBackup($(this).data('id')); });
            } else {
                $('#backupTable tbody').html('<tr><td colspan="7" class="text-center">Failed to load backups. ' + (res.message || 'Unknown error') + '</td></tr>');
                if (!res.success) Swal.fire('Error', res.message || 'Failed to load backup history', 'error');
            }
        },
        error: function(xhr, status, error){
            showLoading(false);
            console.error("AJAX error:", status, error);
            $('#backupTable tbody').html('<tr><td colspan="7" class="text-center">Network error loading backups. Please refresh.</td></tr>');
            Swal.fire('Error', 'Could not load backup history. Check console for details.', 'error');
        }
    });
}

function runManualBackup() {
    Swal.fire({
        title: 'Create Backup',
        text: 'This will generate a full SQL dump. Proceed?',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Yes, run backup'
    }).then(res => {
        if(res.isConfirmed){
            showLoading(true);
            $.ajax({
                url: window.location.pathname + '?ajax=maintenance_action',
                method: 'POST',
                dataType: 'json',
                data: { action: 'run_backup', csrf_token: csrfToken },
                timeout: 120000,
                success: function(r){
                    showLoading(false);
                    if(r.success){
                        Swal.fire('Success', r.message, 'success');
                        loadBackupHistory();
                    } else {
                        Swal.fire('Error', r.message, 'error');
                    }
                },
                error: function(xhr, status, error){
                    showLoading(false);
                    console.error("Backup AJAX error:", status, error);
                    Swal.fire('Error', 'Backup request failed. Check server logs.', 'error');
                }
            });
        }
    });
}

function deleteBackup(backupId){
    Swal.fire({
        title: 'Delete Backup',
        text: 'This will permanently remove the backup file from disk.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        confirmButtonText: 'Yes, delete'
    }).then(res => {
        if(res.isConfirmed){
            showLoading(true);
            $.ajax({
                url: window.location.pathname + '?ajax=maintenance_action',
                method: 'POST',
                dataType: 'json',
                data: { action: 'delete_backup', backup_id: backupId, csrf_token: csrfToken },
                success: function(r){
                    showLoading(false);
                    if(r.success){
                        Swal.fire('Deleted', r.message, 'success');
                        loadBackupHistory();
                    } else { Swal.fire('Error', r.message, 'error'); }
                },
                error: function(){ showLoading(false); Swal.fire('Error', 'Delete failed.', 'error'); }
            });
        }
    });
}

function restoreBackup(backupId){
    Swal.fire({
        title: 'Restore Database',
        html: 'Type <strong>RESTORE</strong> in the field below to confirm.<br>This will overwrite all current data.',
        input: 'text',
        inputPlaceholder: 'RESTORE',
        showCancelButton: true,
        confirmButtonText: 'Restore',
        preConfirm: (value) => { if(value !== 'RESTORE') Swal.showValidationMessage('Text does not match'); return value; }
    }).then(res => {
        if(res.isConfirmed && res.value === 'RESTORE'){
            showLoading(true);
            $.ajax({
                url: window.location.pathname + '?ajax=maintenance_action',
                method: 'POST',
                dataType: 'json',
                data: { action: 'restore_backup', backup_id: backupId, confirm_text: res.value, csrf_token: csrfToken },
                timeout: 300000,
                success: function(r){
                    showLoading(false);
                    if(r.success){
                        Swal.fire('Restored', r.message, 'success');
                        loadBackupHistory();
                    } else { Swal.fire('Error', r.message, 'error'); }
                },
                error: function(){ showLoading(false); Swal.fire('Error', 'Restore request failed.', 'error'); }
            });
        }
    });
}

function loadNotificationRules() {
    $.ajax({
        url: window.location.pathname + '?ajax=maintenance_action',
        method: 'POST',
        dataType: 'json',
        data: { action: 'get_notification_rules', csrf_token: csrfToken },
        success: function(res){
            if(res.success && res.rules){
                let html = '';
                res.rules.forEach(rule => {
                    html += `<tr>
                                 <td>${escapeHtml(rule.event_trigger)}</td>
                                 <td>${escapeHtml(rule.recipient_role)}</td>
                                 <td><label class="toggle-switch"><input type="checkbox" class="email-toggle" data-id="${rule.id}" ${rule.is_email_enabled ? 'checked' : ''}><span class="slider"></span></label></td>
                                 <td><label class="toggle-switch"><input type="checkbox" class="app-toggle" data-id="${rule.id}" ${rule.is_in_app_enabled ? 'checked' : ''}><span class="slider"></span></label></td>
                             </tr>`;
                });
                $('#rulesTable tbody').html(html);
                $('.email-toggle').off('change').on('change', function(){ updateNotificationRule($(this).data('id'), 'is_email_enabled', $(this).prop('checked') ? 1 : 0); });
                $('.app-toggle').off('change').on('change', function(){ updateNotificationRule($(this).data('id'), 'is_in_app_enabled', $(this).prop('checked') ? 1 : 0); });
            } else { $('#rulesTable tbody').html('<tr><td colspan="4">No rules found.</td></tr>'); }
        },
        error: function(){ $('#rulesTable tbody').html('<tr><td colspan="4">Error loading rules.</td></tr>'); }
    });
}

function updateNotificationRule(ruleId, field, value) {
    $.ajax({
        url: window.location.pathname + '?ajax=maintenance_action',
        method: 'POST',
        dataType: 'json',
        data: { action: 'update_notification_rule', rule_id: ruleId, field: field, value: value, csrf_token: csrfToken },
        success: function(r){ if(!r.success) Swal.fire('Error', r.message, 'error'); },
        error: function(){ Swal.fire('Error', 'Update failed.', 'error'); }
    });
}

function loadGeneralSettings() {
    $.ajax({
        url: window.location.pathname + '?ajax=maintenance_action',
        method: 'POST',
        dataType: 'json',
        data: { action: 'get_general_settings', csrf_token: csrfToken },
        success: function(res){
            if(res.success && res.settings){
                $('#backupRetention').val(res.settings.backup_retention_days || 30);
                $('#backupPath').val(res.settings.backup_path || '../../backups/');
                $('#maintenanceMode').prop('checked', res.settings.system_maintenance_mode == 1);
            }
        }
    });
}

function saveGeneralSettings() {
    let formData = {
        action: 'save_general_settings',
        backup_retention_days: $('#backupRetention').val(),
        backup_path: $('#backupPath').val(),
        system_maintenance_mode: $('#maintenanceMode').is(':checked') ? 1 : 0,
        csrf_token: csrfToken
    };
    showLoading(true);
    $.ajax({
        url: window.location.pathname + '?ajax=maintenance_action',
        method: 'POST',
        dataType: 'json',
        data: formData,
        success: function(r){
            showLoading(false);
            if(r.success){ Swal.fire('Saved', r.message, 'success'); }
            else Swal.fire('Error', r.message, 'error');
        },
        error: function(){ showLoading(false); Swal.fire('Error', 'Save failed.', 'error'); }
    });
}

function escapeHtml(str) {
    if(!str) return '';
    return String(str).replace(/[&<>]/g, m => m === '&' ? '&amp;' : (m === '<' ? '&lt;' : '&gt;'));
}

function showLoading(show){
    if(show) $('#loadingOverlay').addClass('active');
    else $('#loadingOverlay').removeClass('active');
}
</script>
</body>
</html>