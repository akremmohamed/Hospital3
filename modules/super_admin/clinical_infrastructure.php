<?php
/**
 * Super Admin - Clinical Infrastructure & Departmental Control
 * HMS ERP System
 * 
 * @package HMS-ERP
 * @version 3.0 (Fully corrected for hms_erp.sql schema)
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

$branch_id = (int)($_SESSION['branch_id'] ?? 0);
$user_id   = (int)($_SESSION['user_id'] ?? 0);
$user_name = $_SESSION['first_name'] ?? $_SESSION['username'] ?? 'Super Admin';

$db_available = is_db_available();
if (!$db_available) die('Database connection failed.');

$pdo = getDB();

// ============================================
// AJAX ROUTER
// ============================================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'infra_action') {
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
        // ---------- GET INFRASTRUCTURE TREE ----------
        if ($action === 'get_infra_tree') {
            $branch_filter = (int)($_POST['branch_id'] ?? 0);
            
            // Departments (standalone)
            $deptSql = "SELECT id, name, code, is_active FROM departments WHERE deleted_at IS NULL";
            if ($branch_filter > 0) $deptSql .= " AND branch_id = :bid";
            $deptStmt = $db->prepare($deptSql);
            if ($branch_filter > 0) $deptStmt->execute([':bid' => $branch_filter]);
            else $deptStmt->execute();
            $departments = $deptStmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Wards (no department_id, only branch_id)
            $wardSql = "SELECT id, name, ward_type, is_active, floor_location, total_beds FROM wards WHERE deleted_at IS NULL";
            if ($branch_filter > 0) $wardSql .= " AND branch_id = :bid";
            $wardStmt = $db->prepare($wardSql);
            if ($branch_filter > 0) $wardStmt->execute([':bid' => $branch_filter]);
            else $wardStmt->execute();
            $wards = $wardStmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Rooms
            $roomSql = "SELECT r.id, r.room_number, r.room_type, r.is_active, r.ward_id, w.name as ward_name 
                        FROM rooms r 
                        LEFT JOIN wards w ON r.ward_id = w.id 
                        WHERE r.deleted_at IS NULL";
            if ($branch_filter > 0) $roomSql .= " AND r.branch_id = :bid";
            $roomStmt = $db->prepare($roomSql);
            if ($branch_filter > 0) $roomStmt->execute([':bid' => $branch_filter]);
            else $roomStmt->execute();
            $rooms = $roomStmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Beds
            $bedSql = "SELECT b.id, b.bed_number, b.status, b.is_active, b.room_id, r.room_number 
                       FROM beds b 
                       LEFT JOIN rooms r ON b.room_id = r.id 
                       WHERE b.deleted_at IS NULL";
            if ($branch_filter > 0) $bedSql .= " AND b.branch_id = :bid";
            $bedStmt = $db->prepare($bedSql);
            if ($branch_filter > 0) $bedStmt->execute([':bid' => $branch_filter]);
            else $bedStmt->execute();
            $beds = $bedStmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode(['success' => true, 'departments' => $departments, 'wards' => $wards, 'rooms' => $rooms, 'beds' => $beds]);
            exit;
        }

        // ---------- GET OCCUPANCY STATS ----------
        if ($action === 'get_occupancy_stats') {
            $branch_filter = (int)($_POST['branch_id'] ?? 0);
            $totalBeds = 0;
            $occupied = 0;
            $available = 0;
            $maintenance = 0;
            
            $baseSql = "SELECT status, COUNT(*) as cnt FROM beds WHERE deleted_at IS NULL AND is_active = 1";
            if ($branch_filter > 0) $baseSql .= " AND branch_id = :bid";
            $statStmt = $db->prepare($baseSql);
            if ($branch_filter > 0) $statStmt->execute([':bid' => $branch_filter]);
            else $statStmt->execute();
            $stats = $statStmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($stats as $row) {
                $totalBeds += $row['cnt'];
                if ($row['status'] == 'occupied') $occupied = $row['cnt'];
                elseif ($row['status'] == 'available') $available = $row['cnt'];
                elseif ($row['status'] == 'maintenance') $maintenance = $row['cnt'];
            }
            $occupancyRate = ($totalBeds > 0) ? round(($occupied / $totalBeds) * 100, 1) : 0;
            echo json_encode(['success' => true, 'total_beds' => $totalBeds, 'occupied' => $occupied, 'available' => $available, 'maintenance' => $maintenance, 'occupancy_rate' => $occupancyRate]);
            exit;
        }

        // ---------- SAVE DEPARTMENT ----------
        if ($action === 'save_department') {
            $dept_id = (int)($_POST['dept_id'] ?? 0);
            $branch_d = (int)($_POST['branch_id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            $code = trim($_POST['code'] ?? '');
            $is_active = isset($_POST['is_active']) ? 1 : 0;
            if (!$branch_d || !$name || !$code) {
                echo json_encode(['success' => false, 'message' => 'Branch, name and code are required.']);
                exit;
            }
            // Unique code per branch
            $check = $db->prepare("SELECT id FROM departments WHERE branch_id = :bid AND code = :code AND deleted_at IS NULL AND id != :did");
            $check->execute([':bid' => $branch_d, ':code' => $code, ':did' => $dept_id]);
            if ($check->fetch()) {
                echo json_encode(['success' => false, 'message' => 'Department code already exists for this branch.']);
                exit;
            }
            if ($dept_id > 0) {
                $stmt = $db->prepare("UPDATE departments SET branch_id = :bid, name = :name, code = :code, is_active = :act, updated_at = NOW() WHERE id = :id");
                $stmt->execute([':bid' => $branch_d, ':name' => $name, ':code' => $code, ':act' => $is_active, ':id' => $dept_id]);
            } else {
                $stmt = $db->prepare("INSERT INTO departments (branch_id, name, code, is_active, created_by_user_id, created_at, updated_at) VALUES (:bid, :name, :code, :act, :uid, NOW(), NOW())");
                $stmt->execute([':bid' => $branch_d, ':name' => $name, ':code' => $code, ':act' => $is_active, ':uid' => $user_id]);
            }
            echo json_encode(['success' => true, 'message' => 'Department saved successfully.']);
            exit;
        }

        // ---------- SAVE WARD (no department_id) ----------
        if ($action === 'save_ward') {
            $ward_id = (int)($_POST['ward_id'] ?? 0);
            $branch_w = (int)($_POST['branch_id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            $ward_type = $_POST['ward_type'] ?? 'general';
            $is_active = isset($_POST['is_active']) ? 1 : 0;
            if (!$branch_w || !$name) {
                echo json_encode(['success' => false, 'message' => 'Branch and ward name are required.']);
                exit;
            }
            if ($ward_id > 0) {
                $stmt = $db->prepare("UPDATE wards SET branch_id = :bid, name = :name, ward_type = :wtype, is_active = :act, updated_at = NOW() WHERE id = :id");
                $stmt->execute([':bid' => $branch_w, ':name' => $name, ':wtype' => $ward_type, ':act' => $is_active, ':id' => $ward_id]);
            } else {
                $stmt = $db->prepare("INSERT INTO wards (branch_id, name, ward_type, is_active, created_by_user_id, created_at, updated_at) VALUES (:bid, :name, :wtype, :act, :uid, NOW(), NOW())");
                $stmt->execute([':bid' => $branch_w, ':name' => $name, ':wtype' => $ward_type, ':act' => $is_active, ':uid' => $user_id]);
            }
            echo json_encode(['success' => true, 'message' => 'Ward saved successfully.']);
            exit;
        }

        // ---------- SAVE ROOM ----------
        if ($action === 'save_room') {
            $room_id = (int)($_POST['room_id'] ?? 0);
            $branch_r = (int)($_POST['branch_id'] ?? 0);
            $ward_id = (int)($_POST['ward_id'] ?? 0);
            $room_number = trim($_POST['room_number'] ?? '');
            $room_type = $_POST['room_type'] ?? 'general';
            $is_active = isset($_POST['is_active']) ? 1 : 0;
            if (!$branch_r || !$ward_id || !$room_number) {
                echo json_encode(['success' => false, 'message' => 'Branch, ward and room number are required.']);
                exit;
            }
            if ($room_id > 0) {
                $stmt = $db->prepare("UPDATE rooms SET branch_id = :bid, ward_id = :wid, room_number = :num, room_type = :type, is_active = :act, updated_at = NOW() WHERE id = :id");
                $stmt->execute([':bid' => $branch_r, ':wid' => $ward_id, ':num' => $room_number, ':type' => $room_type, ':act' => $is_active, ':id' => $room_id]);
            } else {
                $stmt = $db->prepare("INSERT INTO rooms (branch_id, ward_id, room_number, room_type, is_active, created_by_user_id, created_at, updated_at) VALUES (:bid, :wid, :num, :type, :act, :uid, NOW(), NOW())");
                $stmt->execute([':bid' => $branch_r, ':wid' => $ward_id, ':num' => $room_number, ':type' => $room_type, ':act' => $is_active, ':uid' => $user_id]);
            }
            echo json_encode(['success' => true, 'message' => 'Room saved successfully.']);
            exit;
        }

        // ---------- SAVE BED ----------
        if ($action === 'save_bed') {
            $bed_id = (int)($_POST['bed_id'] ?? 0);
            $branch_b = (int)($_POST['branch_id'] ?? 0);
            $room_id = (int)($_POST['room_id'] ?? 0);
            $bed_number = trim($_POST['bed_number'] ?? '');
            $status = $_POST['status'] ?? 'available';
            $is_active = isset($_POST['is_active']) ? 1 : 0;
            if (!$branch_b || !$room_id || !$bed_number) {
                echo json_encode(['success' => false, 'message' => 'Branch, room and bed number are required.']);
                exit;
            }
            if ($bed_id > 0) {
                $stmt = $db->prepare("UPDATE beds SET branch_id = :bid, room_id = :rid, bed_number = :num, status = :stat, is_active = :act, updated_at = NOW() WHERE id = :id");
                $stmt->execute([':bid' => $branch_b, ':rid' => $room_id, ':num' => $bed_number, ':stat' => $status, ':act' => $is_active, ':id' => $bed_id]);
            } else {
                $stmt = $db->prepare("INSERT INTO beds (branch_id, room_id, bed_number, status, is_active, created_by_user_id, created_at, updated_at) VALUES (:bid, :rid, :num, :stat, :act, :uid, NOW(), NOW())");
                $stmt->execute([':bid' => $branch_b, ':rid' => $room_id, ':num' => $bed_number, ':stat' => $status, ':act' => $is_active, ':uid' => $user_id]);
            }
            echo json_encode(['success' => true, 'message' => 'Bed saved successfully.']);
            exit;
        }

        // ---------- TOGGLE BED MAINTENANCE ----------
        if ($action === 'toggle_bed_maintenance') {
            $bed_id = (int)($_POST['bed_id'] ?? 0);
            $new_status = $_POST['new_status'] ?? '';
            if (!in_array($new_status, ['available', 'maintenance'])) {
                echo json_encode(['success' => false, 'message' => 'Invalid status transition.']);
                exit;
            }
            $stmt = $db->prepare("UPDATE beds SET status = :stat, updated_at = NOW() WHERE id = :id");
            $stmt->execute([':stat' => $new_status, ':id' => $bed_id]);
            echo json_encode(['success' => true, 'message' => 'Bed status updated.']);
            exit;
        }

        // ---------- DELETE RECORD (soft delete) ----------
        if ($action === 'delete_record') {
            $table = $_POST['table'] ?? '';
            $record_id = (int)($_POST['record_id'] ?? 0);
            $allowed = ['departments', 'wards', 'rooms', 'beds'];
            if (!in_array($table, $allowed) || !$record_id) {
                echo json_encode(['success' => false, 'message' => 'Invalid request.']);
                exit;
            }
            $stmt = $db->prepare("UPDATE $table SET deleted_at = NOW() WHERE id = :id");
            $stmt->execute([':id' => $record_id]);
            echo json_encode(['success' => true, 'message' => 'Record deleted successfully.']);
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
// BRANCH LIST FOR SUPER ADMIN FILTER
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
    <title>Clinical Infrastructure & Departmental Control | Super Admin | HMS ERP</title>
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
        .occupancy-ring { text-align: center; background: white; border-radius: 20px; padding: 20px; margin-bottom: 20px; }
        .progress-circle { width: 120px; height: 120px; margin: 0 auto; border-radius: 50%; background: #e9ecef; }
        .quick-btn { padding: 10px 20px; border-radius: 14px; font-size: 14px; font-weight: 600; border: none; cursor: pointer; transition: all 0.2s; }
        .quick-btn-primary { background: linear-gradient(135deg, var(--primary), var(--primary-dark)); color: white; }
        .quick-btn-outline { background: white; border: 1px solid var(--gray-light); color: var(--dark); }
        .bed-grid { display: flex; flex-wrap: wrap; gap: 12px; margin-top: 15px; }
        .bed-card { width: 100px; padding: 12px; text-align: center; border-radius: 16px; cursor: pointer; transition: 0.2s; box-shadow: 0 2px 6px rgba(0,0,0,0.05); }
        .bed-card.available { background: #d1fae5; border-left: 4px solid var(--success); }
        .bed-card.occupied { background: #fee2e2; border-left: 4px solid var(--danger); }
        .bed-card.maintenance { background: #fff3cd; border-left: 4px solid var(--warning); }
        .bed-card:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(0,0,0,0.1); }
        .loading-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(255,255,255,0.7); z-index: 2000; display: none; align-items: center; justify-content: center; }
        .loading-overlay.active { display: flex; }
        .spinner { width: 40px; height: 40px; border: 3px solid var(--gray-light); border-top-color: var(--primary); border-radius: 50%; animation: spin 0.8s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }
        .table-responsive { margin-top: 15px; }
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
                        <div class="welcome-title">Clinical Infrastructure & Departmental Control</div>
                        <div class="welcome-subtitle">Super Admin Console: Manage departments, wards, rooms, and bed assets.</div>
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
                    <button class="quick-btn quick-btn-primary" id="refreshInfraBtn"><i class="fas fa-sync-alt me-2"></i>Refresh Data</button>
                </div>
            </div>

            <!-- Analytics HUD -->
            <div class="stats-grid">
                <div class="stat-card"><div class="stat-value" id="totalBeds">0</div><div class="stat-label">Total Operational Beds</div></div>
                <div class="stat-card"><div class="stat-value" id="occupiedBeds">0</div><div class="stat-label">Occupied Beds</div></div>
                <div class="stat-card"><div class="stat-value" id="availableBeds">0</div><div class="stat-label">Available Beds</div></div>
                <div class="stat-card"><div class="stat-value" id="maintenanceBeds">0</div><div class="stat-label">Maintenance Lockouts</div></div>
            </div>
            <div class="occupancy-ring">
                <h6 class="mb-3">Bed Occupancy Rate</h6>
                <div class="progress-circle" id="occupancyCircle"></div>
                <div class="mt-2 fw-bold" id="occupancyPercent">0%</div>
            </div>

            <!-- Tabs -->
            <ul class="nav nav-tabs mb-4" id="infraTabs" role="tablist">
                <li class="nav-item"><button class="nav-link active" id="departments-tab" data-bs-toggle="tab" data-bs-target="#departments">Departments</button></li>
                <li class="nav-item"><button class="nav-link" id="wards-tab" data-bs-toggle="tab" data-bs-target="#wards">Wards</button></li>
                <li class="nav-item"><button class="nav-link" id="rooms-tab" data-bs-toggle="tab" data-bs-target="#rooms">Rooms & Bed Matrix</button></li>
            </ul>
            <div class="tab-content">
                <!-- DEPARTMENTS TAB -->
                <div class="tab-pane fade show active" id="departments">
                    <div class="stat-card">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h5><i class="fas fa-building me-2"></i>Clinical Departments</h5>
                            <button class="quick-btn quick-btn-primary" id="addDeptBtn"><i class="fas fa-plus"></i> Add Department</button>
                        </div>
                        <div class="table-responsive"><table id="departmentsTable" class="table table-hover"><thead><tr><th>ID</th><th>Name</th><th>Code</th><th>Status</th><th>Actions</th></tr></thead><tbody></tbody></table></div>
                    </div>
                </div>

                <!-- WARDS TAB (standalone, no department filter) -->
                <div class="tab-pane fade" id="wards">
                    <div class="stat-card">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h5><i class="fas fa-ward me-2"></i>Hospital Wards</h5>
                            <button class="quick-btn quick-btn-primary" id="addWardBtn"><i class="fas fa-plus"></i> Add Ward</button>
                        </div>
                        <div class="table-responsive"><table id="wardsTable" class="table table-hover"><thead><tr><th>ID</th><th>Name</th><th>Ward Type</th><th>Floor Location</th><th>Total Beds</th><th>Status</th><th>Actions</th></tr></thead><tbody></tbody></table></div>
                    </div>
                </div>

                <!-- ROOMS & BED MATRIX TAB -->
                <div class="tab-pane fade" id="rooms">
                    <div class="row">
                        <div class="col-md-5">
                            <div class="stat-card">
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <h5><i class="fas fa-door-open me-2"></i>Rooms</h5>
                                    <button class="quick-btn quick-btn-primary" id="addRoomBtn"><i class="fas fa-plus"></i> Add Room</button>
                                </div>
                                <select id="roomWardFilter" class="form-select mb-3"><option value="0">All Wards</option></select>
                                <div class="table-responsive"><table id="roomsTable" class="table table-hover"><thead><tr><th>ID</th><th>Room Number</th><th>Ward</th><th>Room Type</th><th>Status</th><th>Actions</th></tr></thead><tbody></tbody></table></div>
                            </div>
                        </div>
                        <div class="col-md-7">
                            <div class="stat-card">
                                <h5><i class="fas fa-bed me-2"></i>Bed Asset Matrix</h5>
                                <select id="bedRoomFilter" class="form-select mb-3"><option value="0">Select Room to View Beds</option></select>
                                <div id="bedGridContainer" class="bed-grid">Select a room to display beds.</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php include('assets/inc/footer.php'); ?>
</div>

<div id="loadingOverlay" class="loading-overlay"><div class="spinner"></div></div>

<!-- MODAL: Department -->
<div id="deptModal" class="modal fade" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><div class="modal-header"><h5 id="deptModalTitle">Department</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><form id="deptForm"><input type="hidden" name="dept_id" id="deptId"><div class="mb-2"><label>Branch *</label><select name="branch_id" id="deptBranch" class="form-select" required><?php foreach($branches as $b){ echo "<option value='{$b['id']}'>{$b['name']}</option>"; } ?></select></div><div class="mb-2"><label>Department Name *</label><input type="text" name="name" id="deptName" class="form-control" required></div><div class="mb-2"><label>Code *</label><input type="text" name="code" id="deptCode" class="form-control" required></div><div class="form-check mb-2"><input type="checkbox" name="is_active" id="deptActive" class="form-check-input" checked><label class="form-check-label">Active</label></div><input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>"></form></div><div class="modal-footer"><button class="quick-btn quick-btn-outline" data-bs-dismiss="modal">Cancel</button><button class="quick-btn quick-btn-primary" id="saveDeptBtn">Save Department</button></div></div></div></div>

<!-- MODAL: Ward (no department_id) -->
<div id="wardModal" class="modal fade" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><div class="modal-header"><h5 id="wardModalTitle">Ward</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><form id="wardForm"><input type="hidden" name="ward_id" id="wardId"><div class="mb-2"><label>Branch *</label><select name="branch_id" id="wardBranch" class="form-select" required><?php foreach($branches as $b){ echo "<option value='{$b['id']}'>{$b['name']}</option>"; } ?></select></div><div class="mb-2"><label>Ward Name *</label><input type="text" name="name" id="wardName" class="form-control" required></div><div class="mb-2"><label>Ward Type</label><select name="ward_type" id="wardType" class="form-select"><option value="general">General</option><option value="icu">ICU</option><option value="nicu">NICU</option><option value="pediatric">Pediatric</option><option value="maternity">Maternity</option><option value="isolation">Isolation</option><option value="emergency">Emergency</option><option value="surgical">Surgical</option><option value="cardiac">Cardiac</option><option value="oncology">Oncology</option></select></div><div class="mb-2"><label>Floor Location</label><input type="text" name="floor_location" id="wardFloor" class="form-control"></div><div class="mb-2"><label>Total Beds (capacity)</label><input type="number" name="total_beds" id="wardTotalBeds" class="form-control" value="0"></div><div class="form-check mb-2"><input type="checkbox" name="is_active" id="wardActive" class="form-check-input" checked><label class="form-check-label">Active</label></div><input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>"></form></div><div class="modal-footer"><button class="quick-btn quick-btn-outline" data-bs-dismiss="modal">Cancel</button><button class="quick-btn quick-btn-primary" id="saveWardBtn">Save Ward</button></div></div></div></div>

<!-- MODAL: Room -->
<div id="roomModal" class="modal fade" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><div class="modal-header"><h5 id="roomModalTitle">Room</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><form id="roomForm"><input type="hidden" name="room_id" id="roomId"><div class="mb-2"><label>Branch *</label><select name="branch_id" id="roomBranch" class="form-select" required><?php foreach($branches as $b){ echo "<option value='{$b['id']}'>{$b['name']}</option>"; } ?></select></div><div class="mb-2"><label>Ward *</label><select name="ward_id" id="roomWard" class="form-select" required></select></div><div class="mb-2"><label>Room Number *</label><input type="text" name="room_number" id="roomNumber" class="form-control" required></div><div class="mb-2"><label>Room Type</label><select name="room_type" id="roomType" class="form-select"><option value="general">General</option><option value="private">Private</option><option value="semi_private">Semi-Private</option><option value="icu">ICU</option><option value="isolation">Isolation</option></select></div><div class="form-check mb-2"><input type="checkbox" name="is_active" id="roomActive" class="form-check-input" checked><label class="form-check-label">Active</label></div><input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>"></form></div><div class="modal-footer"><button class="quick-btn quick-btn-outline" data-bs-dismiss="modal">Cancel</button><button class="quick-btn quick-btn-primary" id="saveRoomBtn">Save Room</button></div></div></div></div>

<!-- MODAL: Bed -->
<div id="bedModal" class="modal fade" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><div class="modal-header"><h5 id="bedModalTitle">Bed</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><form id="bedForm"><input type="hidden" name="bed_id" id="bedId"><div class="mb-2"><label>Branch *</label><select name="branch_id" id="bedBranch" class="form-select" required><?php foreach($branches as $b){ echo "<option value='{$b['id']}'>{$b['name']}</option>"; } ?></select></div><div class="mb-2"><label>Room *</label><select name="room_id" id="bedRoom" class="form-select" required></select></div><div class="mb-2"><label>Bed Number *</label><input type="text" name="bed_number" id="bedNumber" class="form-control" required></div><div class="mb-2"><label>Status</label><select name="status" id="bedStatus" class="form-select"><option value="available">Available</option><option value="occupied">Occupied</option><option value="maintenance">Maintenance</option><option value="reserved">Reserved</option></select></div><div class="form-check mb-2"><input type="checkbox" name="is_active" id="bedActive" class="form-check-input" checked><label class="form-check-label">Active</label></div><input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>"></form></div><div class="modal-footer"><button class="quick-btn quick-btn-outline" data-bs-dismiss="modal">Cancel</button><button class="quick-btn quick-btn-primary" id="saveBedBtn">Save Bed</button></div></div></div></div>

<script src="assets/js/vendor.min.js"></script>
<script src="assets/js/app.min.js"></script>
<script>
const csrfToken = "<?php echo $csrf_token; ?>";
let departmentsData = [], wardsData = [], roomsData = [], bedsData = [];

$(function(){
    loadOccupancyStats();
    loadInfrastructureTree();
    $('#globalBranchFilter, #refreshInfraBtn').on('change click', function(){ loadOccupancyStats(); loadInfrastructureTree(); });
    $('#addDeptBtn').on('click', function(){ resetDeptForm(); $('#deptModal').modal('show'); });
    $('#addWardBtn').on('click', function(){ $('#wardId').val(''); $('#wardForm')[0].reset(); $('#wardActive').prop('checked', true); $('#wardModal').modal('show'); });
    $('#addRoomBtn').on('click', function(){ populateWardSelect('#roomWard'); $('#roomId').val(''); $('#roomForm')[0].reset(); $('#roomActive').prop('checked', true); $('#roomModal').modal('show'); });
    $('#saveDeptBtn').on('click', saveDepartment);
    $('#saveWardBtn').on('click', saveWard);
    $('#saveRoomBtn').on('click', saveRoom);
    $('#saveBedBtn').on('click', saveBed);
    $('#bedRoomFilter').on('change', function(){ renderBedGrid($(this).val()); });
});

function loadOccupancyStats() {
    let branch = $('#globalBranchFilter').val();
    $.ajax({ url: window.location.pathname + '?ajax=infra_action', method: 'POST', dataType: 'json', data: { action: 'get_occupancy_stats', branch_id: branch, csrf_token: csrfToken }, success: function(res){
        if(res.success){
            $('#totalBeds').text(res.total_beds);
            $('#occupiedBeds').text(res.occupied);
            $('#availableBeds').text(res.available);
            $('#maintenanceBeds').text(res.maintenance);
            $('#occupancyPercent').text(res.occupancy_rate + '%');
            let angle = (res.occupancy_rate / 100) * 360;
            $('#occupancyCircle').css('background', `conic-gradient(#06d6a0 0deg ${angle}deg, #e9ecef ${angle}deg 360deg)`);
        }
    }});
}

function loadInfrastructureTree() {
    let branch = $('#globalBranchFilter').val();
    showLoading(true);
    $.ajax({ url: window.location.pathname + '?ajax=infra_action', method: 'POST', dataType: 'json', data: { action: 'get_infra_tree', branch_id: branch, csrf_token: csrfToken }, success: function(res){
        if(res.success){
            departmentsData = res.departments;
            wardsData = res.wards;
            roomsData = res.rooms;
            bedsData = res.beds;
            renderDepartmentsTable();
            renderWardsTable();
            renderRoomsTable();
            populateWardFilter();
            populateRoomFilter();
            populateWardSelect('#roomWard');
            populateRoomSelect('#bedRoom');
        } else { Swal.fire('Error', res.message || 'Failed to load infrastructure data', 'error'); }
        showLoading(false);
    }, error: function(xhr){ showLoading(false); Swal.fire('Error', 'AJAX error: ' + xhr.statusText, 'error'); }});
}

function renderDepartmentsTable(){
    let html = '';
    departmentsData.forEach(d => {
        let statusBadge = d.is_active ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>';
        html += `<tr>
            <td>${d.id}</td>
            <td>${escapeHtml(d.name)}</td><td>${escapeHtml(d.code)}</td><td>${statusBadge}</td>
            <td><button class="btn btn-sm btn-outline-primary edit-dept" data-id="${d.id}" data-name="${escapeHtml(d.name)}" data-code="${escapeHtml(d.code)}" data-active="${d.is_active}"><i class="fas fa-edit"></i></button>
            <button class="btn btn-sm btn-outline-danger delete-dept" data-id="${d.id}" data-table="departments"><i class="fas fa-trash"></i></button></td>
        </tr>`;
    });
    $('#departmentsTable tbody').html(html);
    $('.edit-dept').on('click', function(){ openDeptEdit($(this).data('id'), $(this).data('name'), $(this).data('code'), $(this).data('active')); });
    $('.delete-dept').on('click', function(){ deleteRecord('departments', $(this).data('id')); });
}
function openDeptEdit(id, name, code, active){
    $('#deptId').val(id); $('#deptName').val(name); $('#deptCode').val(code);
    $('#deptActive').prop('checked', active == 1);
    $('#deptModal').modal('show');
}
function resetDeptForm(){ $('#deptForm')[0].reset(); $('#deptId').val(''); $('#deptActive').prop('checked', true); }
function saveDepartment(){
    let data = $('#deptForm').serialize() + '&action=save_department';
    $.ajax({ url: window.location.pathname + '?ajax=infra_action', method: 'POST', data: data, dataType: 'json', success: function(res){
        if(res.success){ Swal.fire('Success', res.message, 'success'); $('#deptModal').modal('hide'); loadInfrastructureTree(); loadOccupancyStats(); }
        else Swal.fire('Error', res.message, 'error');
    }});
}

function renderWardsTable(){
    let html = '';
    wardsData.forEach(w => {
        let statusBadge = w.is_active ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>';
        html += `<tr>
            <td>${w.id}</td>
            <td>${escapeHtml(w.name)}</td><td>${escapeHtml(w.ward_type)}</td><td>${escapeHtml(w.floor_location || '')}</td>
            <td>${w.total_beds || 0}</td><td>${statusBadge}</td>
            <td><button class="btn btn-sm btn-outline-primary edit-ward" data-id="${w.id}" data-name="${escapeHtml(w.name)}" data-type="${w.ward_type}" data-floor="${escapeHtml(w.floor_location)}" data-beds="${w.total_beds}" data-active="${w.is_active}"><i class="fas fa-edit"></i></button>
            <button class="btn btn-sm btn-outline-danger delete-ward" data-id="${w.id}" data-table="wards"><i class="fas fa-trash"></i></button></td>
        </tr>`;
    });
    $('#wardsTable tbody').html(html);
    $('.edit-ward').on('click', function(){ openWardEdit($(this).data('id'), $(this).data('name'), $(this).data('type'), $(this).data('floor'), $(this).data('beds'), $(this).data('active')); });
    $('.delete-ward').on('click', function(){ deleteRecord('wards', $(this).data('id')); });
}
function openWardEdit(id, name, type, floor, beds, active){
    $('#wardId').val(id); $('#wardName').val(name); $('#wardType').val(type);
    $('#wardFloor').val(floor); $('#wardTotalBeds').val(beds);
    $('#wardActive').prop('checked', active == 1);
    $('#wardModal').modal('show');
}
function saveWard(){
    let data = $('#wardForm').serialize() + '&action=save_ward';
    $.ajax({ url: window.location.pathname + '?ajax=infra_action', method: 'POST', data: data, dataType: 'json', success: function(res){
        if(res.success){ Swal.fire('Success', res.message, 'success'); $('#wardModal').modal('hide'); loadInfrastructureTree(); loadOccupancyStats(); }
        else Swal.fire('Error', res.message, 'error');
    }});
}

function renderRoomsTable(){
    let filterWard = $('#roomWardFilter').val();
    let filtered = filterWard == 0 ? roomsData : roomsData.filter(r => r.ward_id == filterWard);
    let html = '';
    filtered.forEach(r => {
        let statusBadge = r.is_active ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>';
        html += `<tr>
            <td>${r.id}</td>
            <td>${escapeHtml(r.room_number)}</td><td>${escapeHtml(r.ward_name)}</td><td>${escapeHtml(r.room_type)}</td>
            <td>${statusBadge}</td>
            <td><button class="btn btn-sm btn-outline-primary edit-room" data-id="${r.id}" data-number="${escapeHtml(r.room_number)}" data-ward="${r.ward_id}" data-type="${r.room_type}" data-active="${r.is_active}"><i class="fas fa-edit"></i></button>
            <button class="btn btn-sm btn-outline-danger delete-room" data-id="${r.id}" data-table="rooms"><i class="fas fa-trash"></i></button></td>
        </tr>`;
    });
    $('#roomsTable tbody').html(html);
    $('.edit-room').on('click', function(){ openRoomEdit($(this).data('id'), $(this).data('number'), $(this).data('ward'), $(this).data('type'), $(this).data('active')); });
    $('.delete-room').on('click', function(){ deleteRecord('rooms', $(this).data('id')); });
}
function openRoomEdit(id, number, wardId, type, active){
    $('#roomId').val(id); $('#roomNumber').val(number); $('#roomType').val(type);
    $('#roomActive').prop('checked', active == 1);
    populateWardSelect('#roomWard', wardId);
    $('#roomModal').modal('show');
}
function saveRoom(){
    let data = $('#roomForm').serialize() + '&action=save_room';
    $.ajax({ url: window.location.pathname + '?ajax=infra_action', method: 'POST', data: data, dataType: 'json', success: function(res){
        if(res.success){ Swal.fire('Success', res.message, 'success'); $('#roomModal').modal('hide'); loadInfrastructureTree(); loadOccupancyStats(); }
        else Swal.fire('Error', res.message, 'error');
    }});
}

function renderBedGrid(roomId){
    if(!roomId || roomId == 0){ $('#bedGridContainer').html('<p class="text-muted">Select a room to view beds.</p>'); return; }
    let filtered = bedsData.filter(b => b.room_id == roomId);
    if(filtered.length === 0){ $('#bedGridContainer').html('<p class="text-muted">No beds configured for this room. <button class="quick-btn quick-btn-primary" id="quickAddBedBtn">Add Bed</button></p>'); $('#quickAddBedBtn').on('click', function(){ openBedModalForRoom(roomId); }); return; }
    let html = '';
    filtered.forEach(b => {
        html += `<div class="bed-card ${b.status}" data-bed-id="${b.id}" data-status="${b.status}">
                    <i class="fas fa-bed fa-2x"></i>
                    <div><strong>${escapeHtml(b.bed_number)}</strong></div>
                    <small>${b.status}</small>
                    <div><button class="btn btn-xs btn-outline-secondary mt-1 toggle-maintenance" data-id="${b.id}" data-status="${b.status}">${b.status == 'maintenance' ? 'Mark Available' : 'Maintenance'}</button></div>
                 </div>`;
    });
    $('#bedGridContainer').html(html);
    $('.toggle-maintenance').on('click', function(e){ e.stopPropagation(); toggleBedMaintenance($(this).data('id'), $(this).data('status')); });
    $('.bed-card').on('click', function(){ let bid = $(this).data('bed-id'); openBedEdit(bid); });
}
function openBedModalForRoom(roomId){
    $('#bedId').val('');
    $('#bedForm')[0].reset();
    $('#bedActive').prop('checked', true);
    $('#bedStatus').val('available');
    populateRoomSelect('#bedRoom', roomId);
    $('#bedModal').modal('show');
}
function openBedEdit(bedId){
    let bed = bedsData.find(b => b.id == bedId);
    if(bed){
        $('#bedId').val(bed.id);
        $('#bedNumber').val(bed.bed_number);
        $('#bedStatus').val(bed.status);
        $('#bedActive').prop('checked', bed.is_active == 1);
        populateRoomSelect('#bedRoom', bed.room_id);
        $('#bedModal').modal('show');
    }
}
function saveBed(){
    let data = $('#bedForm').serialize() + '&action=save_bed';
    $.ajax({ url: window.location.pathname + '?ajax=infra_action', method: 'POST', data: data, dataType: 'json', success: function(res){
        if(res.success){ Swal.fire('Success', res.message, 'success'); $('#bedModal').modal('hide'); loadInfrastructureTree(); loadOccupancyStats(); }
        else Swal.fire('Error', res.message, 'error');
    }});
}
function toggleBedMaintenance(bedId, currentStatus){
    let newStatus = (currentStatus == 'maintenance') ? 'available' : 'maintenance';
    Swal.fire({ title: 'Confirm', text: `Change bed status to ${newStatus}?`, icon: 'question', showCancelButton: true }).then(res=>{
        if(res.isConfirmed){
            $.ajax({ url: window.location.pathname + '?ajax=infra_action', method: 'POST', dataType: 'json', data: { action: 'toggle_bed_maintenance', bed_id: bedId, new_status: newStatus, csrf_token: csrfToken }, success: function(r){
                if(r.success){ loadInfrastructureTree(); loadOccupancyStats(); Swal.fire('Updated', r.message, 'success'); }
                else Swal.fire('Error', r.message, 'error');
            }});
        }
    });
}
function deleteRecord(table, id){
    Swal.fire({ title: 'Delete Record?', text: 'This action is irreversible.', icon: 'warning', showCancelButton: true }).then(res=>{
        if(res.isConfirmed){
            $.ajax({ url: window.location.pathname + '?ajax=infra_action', method: 'POST', dataType: 'json', data: { action: 'delete_record', table: table, record_id: id, csrf_token: csrfToken }, success: function(r){
                if(r.success){ Swal.fire('Deleted', r.message, 'success'); loadInfrastructureTree(); loadOccupancyStats(); }
                else Swal.fire('Error', r.message, 'error');
            }});
        }
    });
}
function populateWardSelect(selector, selectedId){
    let html = '<option value="">Select Ward</option>';
    wardsData.forEach(w => { html += `<option value="${w.id}" ${selectedId == w.id ? 'selected' : ''}>${escapeHtml(w.name)}</option>`; });
    $(selector).html(html);
}
function populateRoomSelect(selector, selectedId){
    let html = '<option value="">Select Room</option>';
    roomsData.forEach(r => { html += `<option value="${r.id}" ${selectedId == r.id ? 'selected' : ''}>${escapeHtml(r.room_number)}</option>`; });
    $(selector).html(html);
}
function populateWardFilter(){
    let html = '<option value="0">All Wards</option>';
    wardsData.forEach(w => { html += `<option value="${w.id}">${escapeHtml(w.name)}</option>`; });
    $('#roomWardFilter').html(html);
    $('#roomWardFilter').off('change').on('change', function(){ renderRoomsTable(); });
}
function populateRoomFilter(){
    let html = '<option value="0">Select Room</option>';
    roomsData.forEach(r => { html += `<option value="${r.id}">${escapeHtml(r.room_number)}</option>`; });
    $('#bedRoomFilter').html(html);
    $('#bedRoomFilter').off('change').on('change', function(){ renderBedGrid($(this).val()); });
}
function escapeHtml(str) { if(!str) return ''; return String(str).replace(/[&<>]/g, m => m === '&' ? '&amp;' : (m === '<' ? '&lt;' : '&gt;')); }
function showLoading(show){ if(show) $('#loadingOverlay').addClass('active'); else $('#loadingOverlay').removeClass('active'); }
</script>
</body>
</html>