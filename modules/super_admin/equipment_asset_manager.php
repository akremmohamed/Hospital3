<?php
/**
 * Super Admin - Medical Equipment Maintenance & Asset Management
 * HMS ERP System
 * 
 * @package HMS-ERP
 * @version 2.0 (Full asset management with CRUD)
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
// AJAX ROUTER
// ============================================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'equipment_action') {
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
        // ---------- GET EQUIPMENT LIST ----------
        if ($action === 'get_equipment_list') {
            $branch_filter = (int)($_POST['branch_id'] ?? 0);
            $sql = "SELECT e.id, e.equipment_name, e.model_number, e.serial_number, e.current_status,
                           e.installation_date, d.name AS department_name, b.name AS branch_name,
                           b.id AS branch_id
                    FROM medical_equipment_assets e
                    LEFT JOIN departments d ON e.department_id = d.id
                    JOIN branches b ON e.branch_id = b.id
                    WHERE e.deleted_at IS NULL";
            if ($branch_filter > 0) $sql .= " AND e.branch_id = :bid";
            $stmt = $db->prepare($sql);
            if ($branch_filter > 0) $stmt->execute([':bid' => $branch_filter]);
            else $stmt->execute();
            $equipment = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'equipment' => $equipment]);
            exit;
        }

        // ---------- SAVE EQUIPMENT (CREATE/UPDATE) ----------
        if ($action === 'save_equipment') {
            $equipment_id = (int)($_POST['equipment_id'] ?? 0);
            $branch_id = (int)($_POST['branch_id'] ?? 0);
            $equipment_name = trim($_POST['equipment_name'] ?? '');
            $model_number = trim($_POST['model_number'] ?? '');
            $serial_number = trim($_POST['serial_number'] ?? '');
            $department_id = (int)($_POST['department_id'] ?? 0);
            $installation_date = $_POST['installation_date'] ?? null;
            $current_status = $_POST['current_status'] ?? 'operational';

            if (!$branch_id || !$equipment_name || !$serial_number) {
                echo json_encode(['success' => false, 'message' => 'Branch, equipment name and serial number are required.']);
                exit;
            }
            // Check unique serial number
            $check = $db->prepare("SELECT id FROM medical_equipment_assets WHERE serial_number = :sn AND deleted_at IS NULL AND id != :id");
            $check->execute([':sn' => $serial_number, ':id' => $equipment_id]);
            if ($check->fetch()) {
                echo json_encode(['success' => false, 'message' => 'Serial number already exists.']);
                exit;
            }
            if ($equipment_id > 0) {
                $stmt = $db->prepare("UPDATE medical_equipment_assets SET branch_id = :bid, equipment_name = :name, model_number = :model, serial_number = :sn, department_id = :dept, installation_date = :idate, current_status = :status, updated_at = NOW() WHERE id = :id");
                $stmt->execute([':bid' => $branch_id, ':name' => $equipment_name, ':model' => $model_number ?: null, ':sn' => $serial_number, ':dept' => $department_id ?: null, ':idate' => $installation_date, ':status' => $current_status, ':id' => $equipment_id]);
                echo json_encode(['success' => true, 'message' => 'Equipment updated successfully.']);
            } else {
                $stmt = $db->prepare("INSERT INTO medical_equipment_assets (branch_id, equipment_name, model_number, serial_number, department_id, installation_date, current_status, created_at, updated_at) VALUES (:bid, :name, :model, :sn, :dept, :idate, :status, NOW(), NOW())");
                $stmt->execute([':bid' => $branch_id, ':name' => $equipment_name, ':model' => $model_number ?: null, ':sn' => $serial_number, ':dept' => $department_id ?: null, ':idate' => $installation_date, ':status' => $current_status]);
                echo json_encode(['success' => true, 'message' => 'Equipment registered successfully.']);
            }
            exit;
        }

        // ---------- DELETE EQUIPMENT (soft delete) ----------
        if ($action === 'delete_equipment') {
            $equipment_id = (int)($_POST['equipment_id'] ?? 0);
            $stmt = $db->prepare("UPDATE medical_equipment_assets SET deleted_at = NOW() WHERE id = :id");
            $stmt->execute([':id' => $equipment_id]);
            echo json_encode(['success' => true, 'message' => 'Equipment removed.']);
            exit;
        }

        // ---------- REPORT FAILURE (Breakdown) ----------
        if ($action === 'report_failure') {
            $equipment_id = (int)($_POST['equipment_id'] ?? 0);
            $failure_reason = trim($_POST['failure_reason'] ?? '');
            if (!$equipment_id || !$failure_reason) {
                echo json_encode(['success' => false, 'message' => 'Equipment ID and failure reason are required.']);
                exit;
            }
            $db->beginTransaction();
            try {
                $check = $db->prepare("SELECT current_status FROM medical_equipment_assets WHERE id = :id FOR UPDATE");
                $check->execute([':id' => $equipment_id]);
                $asset = $check->fetch(PDO::FETCH_ASSOC);
                if (!$asset) throw new Exception('Equipment not found.');
                if ($asset['current_status'] === 'broken_down') {
                    throw new Exception('Equipment is already marked as broken down.');
                }
                $insert = $db->prepare("INSERT INTO equipment_downtime_logs (equipment_id, failure_date_time, reported_by_user_id, failure_reason) VALUES (:eid, NOW(), :uid, :reason)");
                $insert->execute([':eid' => $equipment_id, ':uid' => $user_id, ':reason' => $failure_reason]);
                $update = $db->prepare("UPDATE medical_equipment_assets SET current_status = 'broken_down', updated_at = NOW() WHERE id = :id");
                $update->execute([':id' => $equipment_id]);
                $db->commit();
                echo json_encode(['success' => true, 'message' => 'Failure reported. Equipment is now marked as broken down.']);
            } catch (Exception $e) {
                $db->rollBack();
                echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            }
            exit;
        }

        // ---------- COMPLETE REPAIR (Restoration) ----------
        if ($action === 'complete_repair') {
            $downtime_id = (int)($_POST['downtime_id'] ?? 0);
            $restoration_date = $_POST['restoration_date'] ?? date('Y-m-d H:i:s');
            $repair_cost = (float)($_POST['repair_cost'] ?? 0);
            $technician_notes = trim($_POST['technician_notes'] ?? '');
            if (!$downtime_id) {
                echo json_encode(['success' => false, 'message' => 'Invalid downtime record.']);
                exit;
            }
            $db->beginTransaction();
            try {
                $stmt = $db->prepare("SELECT equipment_id FROM equipment_downtime_logs WHERE id = :id AND restoration_date_time IS NULL FOR UPDATE");
                $stmt->execute([':id' => $downtime_id]);
                $log = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$log) throw new Exception('Downtime record not found or already restored.');
                $updateLog = $db->prepare("UPDATE equipment_downtime_logs SET restoration_date_time = :restore, repair_cost = :cost, technician_notes = :notes, updated_at = NOW() WHERE id = :id");
                $updateLog->execute([':restore' => $restoration_date, ':cost' => $repair_cost, ':notes' => $technician_notes, ':id' => $downtime_id]);
                $updateAsset = $db->prepare("UPDATE medical_equipment_assets SET current_status = 'operational', updated_at = NOW() WHERE id = :eid");
                $updateAsset->execute([':eid' => $log['equipment_id']]);
                $db->commit();
                echo json_encode(['success' => true, 'message' => 'Repair completed. Equipment is now operational.']);
            } catch (Exception $e) {
                $db->rollBack();
                echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            }
            exit;
        }

        // ---------- GET DOWNTIME LOGS ----------
        if ($action === 'get_downtime_logs') {
            $branch_filter = (int)($_POST['branch_id'] ?? 0);
            $sql = "SELECT dl.id, dl.failure_date_time, dl.restoration_date_time, dl.failure_reason,
                           dl.repair_cost, dl.technician_notes,
                           e.id AS equipment_id, e.equipment_name, e.model_number, e.serial_number,
                           b.name AS branch_name,
                           CONCAT(u.first_name, ' ', u.last_name) AS reported_by
                    FROM equipment_downtime_logs dl
                    JOIN medical_equipment_assets e ON dl.equipment_id = e.id
                    JOIN branches b ON e.branch_id = b.id
                    JOIN users u ON dl.reported_by_user_id = u.id
                    WHERE dl.deleted_at IS NULL";
            if ($branch_filter > 0) $sql .= " AND e.branch_id = :bid";
            $sql .= " ORDER BY dl.failure_date_time DESC";
            $stmt = $db->prepare($sql);
            if ($branch_filter > 0) $stmt->execute([':bid' => $branch_filter]);
            else $stmt->execute();
            $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'logs' => $logs]);
            exit;
        }

        // ---------- GET MAINTENANCE SCHEDULES (with overdue auto-flag) ----------
        if ($action === 'get_maintenance_schedules') {
            $branch_filter = (int)($_POST['branch_id'] ?? 0);
            $overdueUpdate = $db->prepare("UPDATE preventive_maintenance_scheduler SET status = 'overdue' WHERE scheduled_date < CURDATE() AND status = 'scheduled'");
            $overdueUpdate->execute();
            $sql = "SELECT ms.id, ms.maintenance_type, ms.scheduled_date, ms.status, ms.performed_by, ms.completion_date,
                           e.id AS equipment_id, e.equipment_name, e.model_number, e.serial_number,
                           b.name AS branch_name
                    FROM preventive_maintenance_scheduler ms
                    JOIN medical_equipment_assets e ON ms.equipment_id = e.id
                    JOIN branches b ON e.branch_id = b.id
                    WHERE ms.deleted_at IS NULL";
            if ($branch_filter > 0) $sql .= " AND e.branch_id = :bid";
            $sql .= " ORDER BY ms.scheduled_date ASC";
            $stmt = $db->prepare($sql);
            if ($branch_filter > 0) $stmt->execute([':bid' => $branch_filter]);
            else $stmt->execute();
            $schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'schedules' => $schedules]);
            exit;
        }

        // ---------- SAVE MAINTENANCE SCHEDULE (Create/Update) ----------
        if ($action === 'save_maintenance_schedule') {
            $schedule_id = (int)($_POST['schedule_id'] ?? 0);
            $equipment_id = (int)($_POST['equipment_id'] ?? 0);
            $maintenance_type = $_POST['maintenance_type'] ?? '';
            $scheduled_date = $_POST['scheduled_date'] ?? '';
            $status = $_POST['status'] ?? 'scheduled';
            $performed_by = trim($_POST['performed_by'] ?? '');
            if (!$equipment_id || !$maintenance_type || !$scheduled_date) {
                echo json_encode(['success' => false, 'message' => 'Equipment, maintenance type and scheduled date are required.']);
                exit;
            }
            if ($schedule_id > 0) {
                $update = $db->prepare("UPDATE preventive_maintenance_scheduler SET equipment_id = :eid, maintenance_type = :type, scheduled_date = :sdate, status = :stat, performed_by = :perf, completion_date = IF(:stat = 'completed', CURDATE(), NULL), updated_at = NOW() WHERE id = :id");
                $update->execute([':eid' => $equipment_id, ':type' => $maintenance_type, ':sdate' => $scheduled_date, ':stat' => $status, ':perf' => $performed_by ?: null, ':id' => $schedule_id]);
                echo json_encode(['success' => true, 'message' => 'Schedule updated.']);
            } else {
                $insert = $db->prepare("INSERT INTO preventive_maintenance_scheduler (equipment_id, maintenance_type, scheduled_date, status, created_at, updated_at) VALUES (:eid, :type, :sdate, :stat, NOW(), NOW())");
                $insert->execute([':eid' => $equipment_id, ':type' => $maintenance_type, ':sdate' => $scheduled_date, ':stat' => $status]);
                echo json_encode(['success' => true, 'message' => 'Schedule created.']);
            }
            exit;
        }

        // ---------- UPDATE MAINTENANCE STATUS (mark completed) ----------
        if ($action === 'update_maintenance_status') {
            $schedule_id = (int)($_POST['schedule_id'] ?? 0);
            $new_status = $_POST['status'] ?? '';
            $performed_by = trim($_POST['performed_by'] ?? '');
            if (!in_array($new_status, ['completed', 'scheduled'])) {
                echo json_encode(['success' => false, 'message' => 'Invalid status.']);
                exit;
            }
            $update = $db->prepare("UPDATE preventive_maintenance_scheduler SET status = :stat, performed_by = :perf, completion_date = IF(:stat = 'completed', CURDATE(), NULL), updated_at = NOW() WHERE id = :id");
            $update->execute([':stat' => $new_status, ':perf' => $performed_by ?: null, ':id' => $schedule_id]);
            echo json_encode(['success' => true, 'message' => 'Maintenance status updated.']);
            exit;
        }

        // ---------- DELETE MAINTENANCE SCHEDULE ----------
        if ($action === 'delete_maintenance_schedule') {
            $schedule_id = (int)($_POST['schedule_id'] ?? 0);
            $stmt = $db->prepare("DELETE FROM preventive_maintenance_scheduler WHERE id = :id");
            $stmt->execute([':id' => $schedule_id]);
            echo json_encode(['success' => true, 'message' => 'Schedule deleted.']);
            exit;
        }

        // ---------- GET ASSET TELEMETRY (KPI HUD) ----------
        if ($action === 'get_asset_telemetry') {
            $branch_filter = (int)($_POST['branch_id'] ?? 0);
            $current_month = date('m');
            $current_year = date('Y');
            // Total assets
            $totalSql = "SELECT COUNT(*) FROM medical_equipment_assets WHERE deleted_at IS NULL";
            if ($branch_filter > 0) $totalSql .= " AND branch_id = :bid";
            $totalStmt = $db->prepare($totalSql);
            if ($branch_filter > 0) $totalStmt->execute([':bid' => $branch_filter]);
            else $totalStmt->execute();
            $total_assets = (int)$totalStmt->fetchColumn();
            // Broken down assets
            $brokenSql = "SELECT COUNT(*) FROM medical_equipment_assets WHERE current_status = 'broken_down' AND deleted_at IS NULL";
            if ($branch_filter > 0) $brokenSql .= " AND branch_id = :bid";
            $brokenStmt = $db->prepare($brokenSql);
            if ($branch_filter > 0) $brokenStmt->execute([':bid' => $branch_filter]);
            else $brokenStmt->execute();
            $broken_count = (int)$brokenStmt->fetchColumn();
            // Scheduled maintenance today
            $maintenanceSql = "SELECT COUNT(*) FROM preventive_maintenance_scheduler WHERE scheduled_date = CURDATE() AND status = 'scheduled' AND deleted_at IS NULL";
            if ($branch_filter > 0) $maintenanceSql .= " AND equipment_id IN (SELECT id FROM medical_equipment_assets WHERE branch_id = :bid)";
            $maintenanceStmt = $db->prepare($maintenanceSql);
            if ($branch_filter > 0) $maintenanceStmt->execute([':bid' => $branch_filter]);
            else $maintenanceStmt->execute();
            $today_maintenance = (int)$maintenanceStmt->fetchColumn();
            // Repair costs this month
            $costSql = "SELECT COALESCE(SUM(repair_cost), 0) FROM equipment_downtime_logs WHERE MONTH(restoration_date_time) = :month AND YEAR(restoration_date_time) = :year AND restoration_date_time IS NOT NULL";
            if ($branch_filter > 0) $costSql .= " AND equipment_id IN (SELECT id FROM medical_equipment_assets WHERE branch_id = :bid)";
            $costStmt = $db->prepare($costSql);
            $costParams = [':month' => $current_month, ':year' => $current_year];
            if ($branch_filter > 0) $costParams[':bid'] = $branch_filter;
            $costStmt->execute($costParams);
            $repair_cost = (float)$costStmt->fetchColumn();
            echo json_encode([
                'success' => true,
                'total_assets' => $total_assets,
                'broken_count' => $broken_count,
                'today_maintenance' => $today_maintenance,
                'repair_cost' => number_format($repair_cost, 2)
            ]);
            exit;
        }

        // ---------- GET DEPARTMENTS (for dropdown) ----------
        if ($action === 'get_departments') {
            $branch_filter = (int)($_POST['branch_id'] ?? 0);
            $sql = "SELECT id, name FROM departments WHERE is_active = 1 AND deleted_at IS NULL";
            if ($branch_filter > 0) $sql .= " AND branch_id = :bid";
            $stmt = $db->prepare($sql);
            if ($branch_filter > 0) $stmt->execute([':bid' => $branch_filter]);
            else $stmt->execute();
            $depts = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'departments' => $depts]);
            exit;
        }

        // ---------- GET SINGLE EQUIPMENT DETAILS (for edit) ----------
        if ($action === 'get_equipment_details') {
            $equipment_id = (int)($_POST['equipment_id'] ?? 0);
            $stmt = $db->prepare("SELECT * FROM medical_equipment_assets WHERE id = :id AND deleted_at IS NULL");
            $stmt->execute([':id' => $equipment_id]);
            $equipment = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($equipment) {
                echo json_encode(['success' => true, 'equipment' => $equipment]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Equipment not found.']);
            }
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
// BRANCH LIST FOR FILTER
// ============================================
$branches = [];
if ($pdo) {
    $branchStmt = $pdo->query("SELECT id, name FROM branches WHERE deleted_at IS NULL AND status = 'active' ORDER BY name");
    $branches = $branchStmt->fetchAll(PDO::FETCH_ASSOC);
}
$current_date_display = date('l, F j, Y');
$current_time_display = date('g:i A');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Medical Equipment Asset Manager | Super Admin | HMS ERP</title>
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
        body { font-family: 'Inter', sans-serif; background: linear-gradient(135deg, #f5f7fb 0%, #eef2f6 100%); overflow-x: hidden; }
        .content-page { margin-left: 260px; margin-top: 70px; padding: 24px 32px; transition: margin-left 0.3s; min-height: calc(100vh - 70px); }
        body.erp-sidebar-collapsed .content-page { margin-left: 80px; }
        @media (max-width: 768px) { .content-page { margin-left: 0 !important; } }
        .welcome-header { background: linear-gradient(135deg, var(--primary-dark) 0%, var(--primary) 100%); border-radius: 24px; padding: 24px 32px; color: white; margin-bottom: 28px; }
        .filter-bar { background: white; border-radius: 20px; padding: 16px 24px; margin-bottom: 24px; display: flex; gap: 16px; flex-wrap: wrap; align-items: flex-end; }
        .stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; margin-bottom: 28px; }
        .stat-card { background: white; border-radius: 20px; padding: 20px; border-left: 5px solid var(--primary); transition: all 0.2s; }
        .stat-value { font-size: 32px; font-weight: 800; color: var(--dark); }
        .stat-label { font-size: 13px; color: var(--gray); }
        .quick-btn { padding: 10px 20px; border-radius: 14px; font-size: 14px; font-weight: 600; border: none; cursor: pointer; transition: all 0.2s; }
        .quick-btn-primary { background: linear-gradient(135deg, var(--primary), var(--primary-dark)); color: white; }
        .quick-btn-outline { background: white; border: 1px solid var(--gray-light); color: var(--dark); }
        .quick-btn-danger { background: linear-gradient(135deg, var(--danger), #c0392b); color: white; }
        .loading-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(255,255,255,0.7); z-index: 2000; display: none; align-items: center; justify-content: center; }
        .loading-overlay.active { display: flex; }
        .spinner { width: 40px; height: 40px; border: 3px solid var(--gray-light); border-top-color: var(--primary); border-radius: 50%; animation: spin 0.8s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }
        .status-badge { display: inline-block; padding: 4px 12px; border-radius: 20px; font-size: 11px; font-weight: 600; }
        .status-operational { background: #d1fae5; color: #06d6a0; }
        .status-broken { background: #fee2e2; color: #ef476f; }
        .status-maintenance { background: #fff3cd; color: #f9c74f; }
        .status-decommissioned { background: #e2e8f0; color: #475569; }
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
                        <div class="welcome-title">Medical Equipment Maintenance & Asset Management</div>
                        <div class="welcome-subtitle">Super Admin Console: Asset telemetry, downtime logs, preventive maintenance scheduler.</div>
                    </div>
                    <div class="col-md-4 text-md-end mt-3 mt-md-0">
                        <div class="time-badge" style="background: rgba(255,255,255,0.15); padding: 8px 16px; border-radius: 12px;">
                            <i class="far fa-calendar-alt"></i> <?php echo htmlspecialchars($current_date_display); ?>
                            <i class="far fa-clock ms-2"></i> <?php echo htmlspecialchars($current_time_display); ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Branch Filter -->
            <div class="filter-bar">
                <div style="min-width: 200px;">
                    <label class="form-label fw-semibold">Select Branch</label>
                    <select id="globalBranchFilter" class="form-select">
                        <option value="0">All Branches</option>
                        <?php foreach ($branches as $branch): ?>
                        <option value="<?php echo $branch['id']; ?>"><?php echo htmlspecialchars($branch['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <button class="quick-btn quick-btn-primary" id="refreshAllBtn"><i class="fas fa-sync-alt me-2"></i>Refresh Data</button>
                </div>
            </div>

            <!-- Analytics HUD -->
            <div class="stats-grid" id="statsContainer">
                <div class="stat-card"><div class="stat-value" id="totalAssets">0</div><div class="stat-label">Total Biomedical Assets</div></div>
                <div class="stat-card"><div class="stat-value" id="brokenAssets">0</div><div class="stat-label">Active Breakdowns</div></div>
                <div class="stat-card"><div class="stat-value" id="todayMaintenance">0</div><div class="stat-label">Scheduled Maintenance Today</div></div>
                <div class="stat-card"><div class="stat-value" id="repairCost">0.00 ETB</div><div class="stat-label">Repair Costs (MTD)</div></div>
            </div>

            <!-- Tabs -->
            <ul class="nav nav-tabs mb-4" id="assetTabs" role="tablist">
                <li class="nav-item"><button class="nav-link active" id="assets-tab" data-bs-toggle="tab" data-bs-target="#assets">Asset Matrix & Telemetry</button></li>
                <li class="nav-item"><button class="nav-link" id="downtime-tab" data-bs-toggle="tab" data-bs-target="#downtime">Downtime Breakdown Records</button></li>
                <li class="nav-item"><button class="nav-link" id="maintenance-tab" data-bs-toggle="tab" data-bs-target="#maintenance">Preventive Calibration Calendar</button></li>
            </ul>
            <div class="tab-content">
                <!-- ASSET MATRIX TAB -->
                <div class="tab-pane fade show active" id="assets">
                    <div class="stat-card">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h5 class="mb-0"><i class="fas fa-microscope me-2"></i>Medical Equipment Assets</h5>
                            <button class="quick-btn quick-btn-primary" id="addAssetBtn"><i class="fas fa-plus me-2"></i>Register Asset</button>
                        </div>
                        <div class="table-responsive">
                            <table id="assetsTable" class="table table-hover" style="width:100%">
                                <thead>
                                    <tr><th>ID</th><th>Equipment Name</th><th>Model</th><th>Serial #</th><th>Department</th><th>Branch</th><th>Status</th><th>Installation Date</th><th>Actions</th></tr>
                                </thead>
                                <tbody><tr><td colspan="9" class="text-center">Loading assets...</td></tr></tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- DOWNTIME BREAKDOWN RECORDS TAB -->
                <div class="tab-pane fade" id="downtime">
                    <div class="stat-card">
                        <h5 class="mb-3"><i class="fas fa-tools me-2"></i>Equipment Downtime Logs</h5>
                        <div class="table-responsive">
                            <table id="downtimeTable" class="table table-hover" style="width:100%">
                                <thead>
                                    <tr><th>Equipment</th><th>Branch</th><th>Failure Date/Time</th><th>Restoration Date/Time</th><th>Failure Reason</th><th>Repair Cost (ETB)</th><th>Reported By</th><th>Actions</th></tr>
                                </thead>
                                <tbody><tr><td colspan="8" class="text-center">Loading...</td></tr></tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- PREVENTIVE MAINTENANCE CALENDAR TAB -->
                <div class="tab-pane fade" id="maintenance">
                    <div class="stat-card">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h5 class="mb-0"><i class="fas fa-calendar-check me-2"></i>Preventive Maintenance Schedules</h5>
                            <button class="quick-btn quick-btn-primary" id="addScheduleBtn"><i class="fas fa-plus me-2"></i>Add Schedule</button>
                        </div>
                        <div class="table-responsive">
                            <table id="scheduleTable" class="table table-hover" style="width:100%">
                                <thead>
                                    <tr><th>Equipment</th><th>Branch</th><th>Maintenance Type</th><th>Scheduled Date</th><th>Status</th><th>Performed By</th><th>Completion Date</th><th>Actions</th></tr>
                                </thead>
                                <tbody><tr><td colspan="8" class="text-center">Loading...</td></tr></tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php include('assets/inc/footer.php'); ?>
</div>

<div id="loadingOverlay" class="loading-overlay"><div class="spinner"></div></div>

<!-- MODAL: Asset Register/Edit -->
<div id="assetModal" class="modal fade" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content"><div class="modal-header"><h5 id="assetModalTitle">Register Equipment</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body"><form id="assetForm"><input type="hidden" name="equipment_id" id="assetId"><div class="mb-2"><label>Branch *</label><select name="branch_id" id="assetBranch" class="form-select" required><?php foreach($branches as $b){ echo "<option value='{$b['id']}'>{$b['name']}</option>"; } ?></select></div><div class="mb-2"><label>Equipment Name *</label><input type="text" name="equipment_name" id="assetName" class="form-control" required></div><div class="mb-2"><label>Model Number</label><input type="text" name="model_number" id="assetModel" class="form-control"></div><div class="mb-2"><label>Serial Number *</label><input type="text" name="serial_number" id="assetSerial" class="form-control" required></div><div class="mb-2"><label>Department</label><select name="department_id" id="assetDepartment" class="form-select"><option value="">Select Department</option></select></div><div class="mb-2"><label>Installation Date</label><input type="date" name="installation_date" id="assetInstallDate" class="form-control"></div><div class="mb-2"><label>Current Status</label><select name="current_status" id="assetStatus" class="form-select"><option value="operational">Operational</option><option value="under_maintenance">Under Maintenance</option><option value="broken_down">Broken Down</option><option value="decommissioned">Decommissioned</option></select></div><input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>"></form></div>
    <div class="modal-footer"><button class="quick-btn quick-btn-outline" data-bs-dismiss="modal">Cancel</button><button class="quick-btn quick-btn-primary" id="saveAssetBtn">Save Equipment</button></div></div></div>
</div>

<!-- MODAL: Report Failure -->
<div id="failureModal" class="modal fade" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content"><div class="modal-header"><h5>Report Equipment Failure</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body"><form id="failureForm"><input type="hidden" name="equipment_id" id="failEquipmentId"><div class="mb-2"><label>Failure Reason *</label><textarea name="failure_reason" id="failReason" class="form-control" rows="3" required></textarea></div><input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>"></form></div>
    <div class="modal-footer"><button class="quick-btn quick-btn-outline" data-bs-dismiss="modal">Cancel</button><button class="quick-btn quick-btn-primary" id="submitFailureBtn">Report Breakdown</button></div></div></div>
</div>

<!-- MODAL: Complete Repair -->
<div id="repairModal" class="modal fade" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content"><div class="modal-header"><h5>Complete Repair</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body"><form id="repairForm"><input type="hidden" name="downtime_id" id="repairDowntimeId"><div class="mb-2"><label>Restoration Date/Time *</label><input type="datetime-local" name="restoration_date" id="repairRestoreDate" class="form-control" required></div><div class="mb-2"><label>Repair Cost (ETB)</label><input type="number" step="0.01" name="repair_cost" id="repairCostInput" class="form-control" value="0"></div><div class="mb-2"><label>Technician Notes</label><textarea name="technician_notes" id="repairNotes" rows="2" class="form-control"></textarea></div><input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>"></form></div>
    <div class="modal-footer"><button class="quick-btn quick-btn-outline" data-bs-dismiss="modal">Cancel</button><button class="quick-btn quick-btn-primary" id="submitRepairBtn">Mark as Repaired</button></div></div></div>
</div>

<!-- MODAL: Add/Edit Maintenance Schedule -->
<div id="scheduleModal" class="modal fade" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content"><div class="modal-header"><h5 id="scheduleModalTitle">Maintenance Schedule</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body"><form id="scheduleForm"><input type="hidden" name="schedule_id" id="scheduleId"><div class="mb-2"><label>Equipment *</label><select name="equipment_id" id="scheduleEquipment" class="form-select" required><option value="">Select Equipment</option></select></div><div class="mb-2"><label>Maintenance Type *</label><select name="maintenance_type" id="scheduleType" class="form-select" required><option value="Routine Calibration">Routine Calibration</option><option value="Preventive Inspection">Preventive Inspection</option><option value="Software Update">Software Update</option><option value="Deep Cleaning">Deep Cleaning</option></select></div><div class="mb-2"><label>Scheduled Date *</label><input type="date" name="scheduled_date" id="scheduleDate" class="form-control" required></div><div class="mb-2"><label>Status</label><select name="status" id="scheduleStatus" class="form-select"><option value="scheduled">Scheduled</option><option value="completed">Completed</option></select></div><div class="mb-2"><label>Performed By (if completed)</label><input type="text" name="performed_by" id="schedulePerformedBy" class="form-control"></div><input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>"></form></div>
    <div class="modal-footer"><button class="quick-btn quick-btn-outline" data-bs-dismiss="modal">Cancel</button><button class="quick-btn quick-btn-primary" id="saveScheduleBtn">Save Schedule</button></div></div></div>
</div>

<script src="assets/js/vendor.min.js"></script>
<script src="assets/js/app.min.js"></script>
<script>
const csrfToken = "<?php echo $csrf_token; ?>";
let assetsTable, downtimeTable, scheduleTable;

$(function(){
    loadTelemetry();
    loadAssetsTable();
    loadDowntimeLogs();
    loadSchedulesTable();
    loadEquipmentDropdown('#scheduleEquipment');
    loadDepartmentsDropdown();
    $('#globalBranchFilter, #refreshAllBtn').on('change click', function(){ loadTelemetry(); loadAssetsTable(); loadDowntimeLogs(); loadSchedulesTable(); });
    $('#addAssetBtn').on('click', function(){ resetAssetForm(); $('#assetModal').modal('show'); });
    $('#addScheduleBtn').on('click', function(){ $('#scheduleId').val(''); $('#scheduleForm')[0].reset(); $('#scheduleModalTitle').text('Add Maintenance Schedule'); $('#scheduleModal').modal('show'); });
    $('#saveAssetBtn').on('click', saveAsset);
    $('#submitFailureBtn').on('click', submitFailure);
    $('#submitRepairBtn').on('click', submitRepair);
    $('#saveScheduleBtn').on('click', saveSchedule);
    $(document).on('click', '.edit-asset', function(){ editAsset($(this).data('id')); });
    $(document).on('click', '.delete-asset', function(){ deleteAsset($(this).data('id')); });
    $(document).on('click', '.report-failure', function(){ openFailureModal($(this).data('id')); });
    $(document).on('click', '.complete-repair', function(){ openRepairModal($(this).data('id')); });
    $(document).on('click', '.edit-schedule', function(){ editSchedule($(this).data('id')); });
    $(document).on('click', '.delete-schedule', function(){ deleteSchedule($(this).data('id')); });
    $(document).on('click', '.complete-schedule', function(){ markScheduleCompleted($(this).data('id')); });
});

function loadTelemetry() {
    let branch = $('#globalBranchFilter').val();
    $.ajax({
        url: window.location.pathname + '?ajax=equipment_action',
        method: 'POST',
        dataType: 'json',
        data: { action: 'get_asset_telemetry', branch_id: branch, csrf_token: csrfToken },
        success: function(res){
            if(res.success){
                $('#totalAssets').text(res.total_assets);
                $('#brokenAssets').text(res.broken_count);
                $('#todayMaintenance').text(res.today_maintenance);
                $('#repairCost').text(parseFloat(res.repair_cost).toLocaleString() + ' ETB');
            }
        }
    });
}

function loadAssetsTable() {
    let branch = $('#globalBranchFilter').val();
    showLoading(true);
    $.ajax({
        url: window.location.pathname + '?ajax=equipment_action',
        method: 'POST',
        dataType: 'json',
        data: { action: 'get_equipment_list', branch_id: branch, csrf_token: csrfToken },
        success: function(res){
            showLoading(false);
            if(res.success && res.equipment){
                if(assetsTable) assetsTable.destroy();
                let rows = '';
                res.equipment.forEach(e => {
                    let statusClass = '';
                    if(e.current_status === 'operational') statusClass = 'status-operational';
                    else if(e.current_status === 'broken_down') statusClass = 'status-broken';
                    else if(e.current_status === 'under_maintenance') statusClass = 'status-maintenance';
                    else statusClass = 'status-decommissioned';
                    rows += `<tr>
                        <td>${e.id}</td>
                        <td>${escapeHtml(e.equipment_name)}</td>
                        <td>${escapeHtml(e.model_number || '—')}</td>
                        <td>${escapeHtml(e.serial_number)}</td>
                        <td>${escapeHtml(e.department_name || '—')}</td>
                        <td>${escapeHtml(e.branch_name)}</td>
                        <td><span class="status-badge ${statusClass}">${e.current_status.replace('_', ' ')}</span></td>
                        <td>${e.installation_date || '—'}</td>
                        <td><button class="btn btn-sm btn-outline-primary edit-asset" data-id="${e.id}"><i class="fas fa-edit"></i></button>
                            <button class="btn btn-sm btn-outline-danger delete-asset" data-id="${e.id}"><i class="fas fa-trash"></i></button>
                            <button class="btn btn-sm btn-danger report-failure" data-id="${e.id}" ${e.current_status === 'broken_down' ? 'disabled' : ''}><i class="fas fa-exclamation-triangle"></i> Report Failure</button></td>
                    </tr>`;
                });
                $('#assetsTable tbody').html(rows);
                assetsTable = $('#assetsTable').DataTable({ order: [[0, 'desc']], pageLength: 10 });
            } else {
                $('#assetsTable tbody').html('<tr><td colspan="9">No equipment found.</td></tr>');
            }
        },
        error: function(){ showLoading(false); Swal.fire('Error', 'Failed to load assets', 'error'); }
    });
}

function loadDepartmentsDropdown() {
    let branch = $('#globalBranchFilter').val();
    $.ajax({
        url: window.location.pathname + '?ajax=equipment_action',
        method: 'POST',
        dataType: 'json',
        data: { action: 'get_departments', branch_id: branch, csrf_token: csrfToken },
        success: function(res){
            if(res.success && res.departments){
                let opts = '<option value="">Select Department</option>';
                res.departments.forEach(d => { opts += `<option value="${d.id}">${escapeHtml(d.name)}</option>`; });
                $('#assetDepartment').html(opts);
            }
        }
    });
}

function resetAssetForm(){
    $('#assetForm')[0].reset();
    $('#assetId').val('');
    $('#assetModalTitle').text('Register Equipment');
}

function editAsset(assetId){
    showLoading(true);
    $.ajax({
        url: window.location.pathname + '?ajax=equipment_action',
        method: 'POST',
        dataType: 'json',
        data: { action: 'get_equipment_details', equipment_id: assetId, csrf_token: csrfToken },
        success: function(res){
            showLoading(false);
            if(res.success && res.equipment){
                let eq = res.equipment;
                $('#assetId').val(eq.id);
                $('#assetBranch').val(eq.branch_id);
                $('#assetName').val(eq.equipment_name);
                $('#assetModel').val(eq.model_number);
                $('#assetSerial').val(eq.serial_number);
                $('#assetDepartment').val(eq.department_id);
                $('#assetInstallDate').val(eq.installation_date);
                $('#assetStatus').val(eq.current_status);
                $('#assetModalTitle').text('Edit Equipment');
                $('#assetModal').modal('show');
            } else {
                Swal.fire('Error', 'Equipment not found', 'error');
            }
        },
        error: function(){ showLoading(false); Swal.fire('Error', 'Failed to load equipment details', 'error'); }
    });
}

function saveAsset(){
    let formData = $('#assetForm').serialize() + '&action=save_equipment';
    showLoading(true);
    $.ajax({
        url: window.location.pathname + '?ajax=equipment_action',
        method: 'POST',
        data: formData,
        dataType: 'json',
        success: function(res){
            showLoading(false);
            if(res.success){
                Swal.fire('Success', res.message, 'success');
                $('#assetModal').modal('hide');
                loadAssetsTable();
                loadTelemetry();
            } else {
                Swal.fire('Error', res.message, 'error');
            }
        },
        error: function(){ showLoading(false); Swal.fire('Error', 'Save failed', 'error'); }
    });
}

function deleteAsset(assetId){
    Swal.fire({
        title: 'Delete Equipment',
        text: 'This will soft-delete the asset. Proceed?',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Yes, delete'
    }).then(res => {
        if(res.isConfirmed){
            showLoading(true);
            $.ajax({
                url: window.location.pathname + '?ajax=equipment_action',
                method: 'POST',
                dataType: 'json',
                data: { action: 'delete_equipment', equipment_id: assetId, csrf_token: csrfToken },
                success: function(r){
                    showLoading(false);
                    if(r.success){
                        Swal.fire('Deleted', r.message, 'success');
                        loadAssetsTable();
                        loadTelemetry();
                    } else { Swal.fire('Error', r.message, 'error'); }
                },
                error: function(){ showLoading(false); Swal.fire('Error', 'Delete failed', 'error'); }
            });
        }
    });
}

function loadDowntimeLogs() {
    let branch = $('#globalBranchFilter').val();
    $.ajax({
        url: window.location.pathname + '?ajax=equipment_action',
        method: 'POST',
        dataType: 'json',
        data: { action: 'get_downtime_logs', branch_id: branch, csrf_token: csrfToken },
        success: function(res){
            if(res.success && res.logs){
                if(downtimeTable) downtimeTable.destroy();
                let rows = '';
                res.logs.forEach(log => {
                    let restore = log.restoration_date_time ? log.restoration_date_time : '—';
                    let actionBtn = log.restoration_date_time ? '<span class="text-muted">Closed</span>' : `<button class="btn btn-sm btn-success complete-repair" data-id="${log.id}"><i class="fas fa-wrench"></i> Log Repair</button>`;
                    rows += `<tr>
                        <td>${escapeHtml(log.equipment_name)} (${escapeHtml(log.serial_number)})</td>
                        <td>${escapeHtml(log.branch_name)}</td>
                        <td>${log.failure_date_time}</td>
                        <td>${restore}</td>
                        <td>${escapeHtml(log.failure_reason)}</td>
                        <td>${parseFloat(log.repair_cost).toFixed(2)}</td>
                        <td>${escapeHtml(log.reported_by)}</td>
                        <td>${actionBtn}</td>
                    </tr>`;
                });
                $('#downtimeTable tbody').html(rows);
                downtimeTable = $('#downtimeTable').DataTable({ order: [[2, 'desc']], pageLength: 10 });
            } else {
                $('#downtimeTable tbody').html('<tr><td colspan="8">No downtime records.</td></tr>');
            }
        }
    });
}

function openFailureModal(equipmentId){
    $('#failEquipmentId').val(equipmentId);
    $('#failReason').val('');
    $('#failureModal').modal('show');
}
function submitFailure(){
    let data = $('#failureForm').serialize() + '&action=report_failure';
    showLoading(true);
    $.ajax({
        url: window.location.pathname + '?ajax=equipment_action',
        method: 'POST',
        data: data,
        dataType: 'json',
        success: function(res){
            showLoading(false);
            if(res.success){
                Swal.fire('Reported', res.message, 'success');
                $('#failureModal').modal('hide');
                loadTelemetry();
                loadAssetsTable();
                loadDowntimeLogs();
            } else {
                Swal.fire('Error', res.message, 'error');
            }
        },
        error: function(){ showLoading(false); Swal.fire('Error', 'Request failed', 'error'); }
    });
}

function openRepairModal(downtimeId){
    $('#repairDowntimeId').val(downtimeId);
    $('#repairRestoreDate').val(new Date().toISOString().slice(0,16));
    $('#repairCostInput').val('');
    $('#repairNotes').val('');
    $('#repairModal').modal('show');
}
function submitRepair(){
    let data = $('#repairForm').serialize() + '&action=complete_repair';
    showLoading(true);
    $.ajax({
        url: window.location.pathname + '?ajax=equipment_action',
        method: 'POST',
        data: data,
        dataType: 'json',
        success: function(res){
            showLoading(false);
            if(res.success){
                Swal.fire('Completed', res.message, 'success');
                $('#repairModal').modal('hide');
                loadTelemetry();
                loadAssetsTable();
                loadDowntimeLogs();
            } else {
                Swal.fire('Error', res.message, 'error');
            }
        },
        error: function(){ showLoading(false); Swal.fire('Error', 'Request failed', 'error'); }
    });
}

function loadSchedulesTable() {
    let branch = $('#globalBranchFilter').val();
    $.ajax({
        url: window.location.pathname + '?ajax=equipment_action',
        method: 'POST',
        dataType: 'json',
        data: { action: 'get_maintenance_schedules', branch_id: branch, csrf_token: csrfToken },
        success: function(res){
            if(res.success && res.schedules){
                if(scheduleTable) scheduleTable.destroy();
                let rows = '';
                res.schedules.forEach(s => {
                    let statusBadge = s.status === 'completed' ? '<span class="badge bg-success">Completed</span>' : (s.status === 'overdue' ? '<span class="badge bg-danger">Overdue</span>' : '<span class="badge bg-warning">Scheduled</span>');
                    rows += `<tr>
                        <td>${escapeHtml(s.equipment_name)} (${escapeHtml(s.serial_number)})</td>
                        <td>${escapeHtml(s.branch_name)}</td>
                        <td>${escapeHtml(s.maintenance_type)}</td>
                        <td>${s.scheduled_date}</td>
                        <td>${statusBadge}</td>
                        <td>${escapeHtml(s.performed_by || '—')}</td>
                        <td>${s.completion_date || '—'}</td>
                        <td><button class="btn btn-sm btn-outline-primary edit-schedule" data-id="${s.id}"><i class="fas fa-edit"></i></button>
                            <button class="btn btn-sm btn-outline-danger delete-schedule" data-id="${s.id}"><i class="fas fa-trash"></i></button>
                            ${s.status !== 'completed' ? `<button class="btn btn-sm btn-success complete-schedule" data-id="${s.id}"><i class="fas fa-check"></i> Complete</button>` : ''}
                          </td>
                    </tr>`;
                });
                $('#scheduleTable tbody').html(rows);
                scheduleTable = $('#scheduleTable').DataTable({ order: [[3, 'asc']], pageLength: 10 });
            } else {
                $('#scheduleTable tbody').html('<tr><td colspan="8">No schedules found.</td></tr>');
            }
        }
    });
}

function loadEquipmentDropdown(selector, selectedId = null) {
    let branch = $('#globalBranchFilter').val();
    $.ajax({
        url: window.location.pathname + '?ajax=equipment_action',
        method: 'POST',
        dataType: 'json',
        data: { action: 'get_equipment_list', branch_id: branch, csrf_token: csrfToken },
        success: function(res){
            if(res.success && res.equipment){
                let opts = '<option value="">Select Equipment</option>';
                res.equipment.forEach(e => {
                    opts += `<option value="${e.id}" ${selectedId == e.id ? 'selected' : ''}>${escapeHtml(e.equipment_name)} (${escapeHtml(e.serial_number)})</option>`;
                });
                $(selector).html(opts);
            }
        }
    });
}

function editSchedule(scheduleId){
    $.ajax({
        url: window.location.pathname + '?ajax=equipment_action',
        method: 'POST',
        dataType: 'json',
        data: { action: 'get_maintenance_schedules', branch_id: $('#globalBranchFilter').val(), csrf_token: csrfToken },
        success: function(res){
            if(res.success && res.schedules){
                let schedule = res.schedules.find(s => s.id == scheduleId);
                if(schedule){
                    $('#scheduleId').val(schedule.id);
                    $('#scheduleEquipment').val(schedule.equipment_id);
                    $('#scheduleType').val(schedule.maintenance_type);
                    $('#scheduleDate').val(schedule.scheduled_date);
                    $('#scheduleStatus').val(schedule.status);
                    $('#schedulePerformedBy').val(schedule.performed_by || '');
                    $('#scheduleModalTitle').text('Edit Maintenance Schedule');
                    $('#scheduleModal').modal('show');
                }
            }
        }
    });
}

function deleteSchedule(scheduleId){
    Swal.fire({
        title: 'Delete Schedule',
        text: 'This will permanently remove the maintenance schedule.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Yes, delete'
    }).then(res => {
        if(res.isConfirmed){
            showLoading(true);
            $.ajax({
                url: window.location.pathname + '?ajax=equipment_action',
                method: 'POST',
                dataType: 'json',
                data: { action: 'delete_maintenance_schedule', schedule_id: scheduleId, csrf_token: csrfToken },
                success: function(r){
                    showLoading(false);
                    if(r.success){
                        Swal.fire('Deleted', r.message, 'success');
                        loadSchedulesTable();
                    } else { Swal.fire('Error', r.message, 'error'); }
                },
                error: function(){ showLoading(false); Swal.fire('Error', 'Delete failed', 'error'); }
            });
        }
    });
}

function saveSchedule(){
    let data = $('#scheduleForm').serialize() + '&action=save_maintenance_schedule';
    showLoading(true);
    $.ajax({
        url: window.location.pathname + '?ajax=equipment_action',
        method: 'POST',
        data: data,
        dataType: 'json',
        success: function(res){
            showLoading(false);
            if(res.success){
                Swal.fire('Saved', res.message, 'success');
                $('#scheduleModal').modal('hide');
                loadSchedulesTable();
                loadTelemetry();
            } else {
                Swal.fire('Error', res.message, 'error');
            }
        },
        error: function(){ showLoading(false); Swal.fire('Error', 'Save failed', 'error'); }
    });
}

function markScheduleCompleted(scheduleId){
    Swal.fire({
        title: 'Complete Maintenance',
        text: 'Mark this schedule as completed?',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Yes, complete'
    }).then(res => {
        if(res.isConfirmed){
            showLoading(true);
            $.ajax({
                url: window.location.pathname + '?ajax=equipment_action',
                method: 'POST',
                dataType: 'json',
                data: { action: 'update_maintenance_status', schedule_id: scheduleId, status: 'completed', performed_by: '', csrf_token: csrfToken },
                success: function(r){
                    showLoading(false);
                    if(r.success){
                        Swal.fire('Completed', r.message, 'success');
                        loadSchedulesTable();
                        loadTelemetry();
                    } else {
                        Swal.fire('Error', r.message, 'error');
                    }
                },
                error: function(){ showLoading(false); Swal.fire('Error', 'Update failed', 'error'); }
            });
        }
    });
}

function escapeHtml(str) {
    if(!str) return '';
    return String(str).replace(/[&<>]/g, m => m === '&' ? '&amp;' : (m === '<' ? '&lt;' : '&gt;'));
}
function showLoading(show){ if(show) $('#loadingOverlay').addClass('active'); else $('#loadingOverlay').removeClass('active'); }
</script>
</body>
</html>