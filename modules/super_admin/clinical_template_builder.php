<?php
/**
 * Super Admin - Clinical Template & Protocol Builder
 * HMS ERP System
 * 
 * @package HMS-ERP
 * @version 1.0
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
// CREATE REQUIRED SYSTEM TABLES IF NOT EXIST
// ============================================
try {
    // 1. Clinical thresholds table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `clinical_thresholds` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `metric_name` varchar(50) NOT NULL,
            `min_normal` decimal(5,2) DEFAULT NULL,
            `max_normal` decimal(5,2) DEFAULT NULL,
            `critical_low` decimal(5,2) DEFAULT NULL,
            `critical_high` decimal(5,2) DEFAULT NULL,
            `alert_color` varchar(20) DEFAULT 'danger',
            `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_metric_name` (`metric_name`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // Insert default thresholds if empty
    $checkThresholds = $pdo->query("SELECT COUNT(*) FROM clinical_thresholds")->fetchColumn();
    if ($checkThresholds == 0) {
        $defaults = [
            ['temperature', 36.1, 37.5, 35.0, 39.0, 'danger'],
            ['pulse_rate', 60, 100, 40, 130, 'danger'],
            ['respiratory_rate', 12, 20, 8, 30, 'danger'],
            ['oxygen_saturation', 95, 100, 90, null, 'danger'],
            ['systolic_bp', 90, 120, 80, 180, 'danger'],
            ['diastolic_bp', 60, 80, 50, 120, 'danger']
        ];
        $ins = $pdo->prepare("INSERT INTO clinical_thresholds (metric_name, min_normal, max_normal, critical_low, critical_high, alert_color) VALUES (?, ?, ?, ?, ?, ?)");
        foreach ($defaults as $d) {
            $ins->execute([$d[0], $d[1], $d[2], $d[3], $d[4], $d[5]]);
        }
    }

    // 2. Clinical SOAP templates table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `clinical_soap_templates` (
            `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `department_id` int(10) UNSIGNED NOT NULL,
            `template_name` varchar(150) NOT NULL,
            `specialty_category` varchar(100) NOT NULL,
            `form_payload` longtext NOT NULL COMMENT 'JSON field schema',
            `is_active` tinyint(1) NOT NULL DEFAULT 1,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `department_id` (`department_id`),
            CONSTRAINT `fk_soap_template_department` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
} catch (PDOException $e) {
    error_log("Clinical template tables creation: " . $e->getMessage());
}

// ============================================
// AJAX ROUTER
// ============================================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'template_action') {
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
        // ---------- GET CLINICAL THRESHOLDS ----------
        if ($action === 'get_thresholds') {
            $sql = "SELECT id, metric_name, min_normal, max_normal, critical_low, critical_high, alert_color FROM clinical_thresholds ORDER BY metric_name";
            $stmt = $db->query($sql);
            $thresholds = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'thresholds' => $thresholds]);
            exit;
        }

        // ---------- SAVE THRESHOLD BOUNDS (Update single metric) ----------
        if ($action === 'save_threshold_bounds') {
            $threshold_id = (int)($_POST['threshold_id'] ?? 0);
            $min_normal = $_POST['min_normal'] !== '' ? (float)$_POST['min_normal'] : null;
            $max_normal = $_POST['max_normal'] !== '' ? (float)$_POST['max_normal'] : null;
            $critical_low = $_POST['critical_low'] !== '' ? (float)$_POST['critical_low'] : null;
            $critical_high = $_POST['critical_high'] !== '' ? (float)$_POST['critical_high'] : null;
            $alert_color = $_POST['alert_color'] ?? 'danger';
            if (!$threshold_id) {
                echo json_encode(['success' => false, 'message' => 'Invalid threshold ID.']);
                exit;
            }
            $update = $db->prepare("UPDATE clinical_thresholds SET min_normal = ?, max_normal = ?, critical_low = ?, critical_high = ?, alert_color = ? WHERE id = ?");
            $update->execute([$min_normal, $max_normal, $critical_low, $critical_high, $alert_color, $threshold_id]);
            echo json_encode(['success' => true, 'message' => 'Threshold updated successfully.']);
            exit;
        }

        // ---------- GET SOAP TEMPLATES ----------
        if ($action === 'get_soap_templates') {
            $branch_filter = (int)($_POST['branch_id'] ?? 0);
            $sql = "SELECT t.id, t.template_name, t.specialty_category, t.form_payload, t.is_active,
                           d.id as department_id, d.name as department_name
                    FROM clinical_soap_templates t
                    JOIN departments d ON t.department_id = d.id
                    WHERE 1=1";
            if ($branch_filter > 0) $sql .= " AND d.branch_id = :bid";
            $stmt = $db->prepare($sql);
            if ($branch_filter > 0) $stmt->execute([':bid' => $branch_filter]);
            else $stmt->execute();
            $templates = $stmt->fetchAll(PDO::FETCH_ASSOC);
            // Decode form_payload for each
            foreach ($templates as &$tmpl) {
                $tmpl['form_payload'] = json_decode($tmpl['form_payload'], true);
            }
            echo json_encode(['success' => true, 'templates' => $templates]);
            exit;
        }

        // ---------- SAVE SOAP TEMPLATE (Create/Update) ----------
        if ($action === 'save_soap_template') {
            $template_id = (int)($_POST['template_id'] ?? 0);
            $department_id = (int)($_POST['department_id'] ?? 0);
            $template_name = trim($_POST['template_name'] ?? '');
            $specialty_category = trim($_POST['specialty_category'] ?? '');
            $fields_json = $_POST['form_payload'] ?? '[]';
            $is_active = isset($_POST['is_active']) ? 1 : 0;

            if (!$department_id || !$template_name || !$specialty_category) {
                echo json_encode(['success' => false, 'message' => 'Department, template name and specialty are required.']);
                exit;
            }
            // Validate JSON
            $fields = json_decode($fields_json, true);
            if ($fields === null) {
                echo json_encode(['success' => false, 'message' => 'Invalid JSON payload.']);
                exit;
            }
            $db->beginTransaction();
            try {
                if ($template_id > 0) {
                    $update = $db->prepare("UPDATE clinical_soap_templates SET department_id = :did, template_name = :name, specialty_category = :cat, form_payload = :payload, is_active = :act, updated_at = NOW() WHERE id = :id");
                    $update->execute([':did' => $department_id, ':name' => $template_name, ':cat' => $specialty_category, ':payload' => $fields_json, ':act' => $is_active, ':id' => $template_id]);
                } else {
                    $insert = $db->prepare("INSERT INTO clinical_soap_templates (department_id, template_name, specialty_category, form_payload, is_active, created_at, updated_at) VALUES (:did, :name, :cat, :payload, :act, NOW(), NOW())");
                    $insert->execute([':did' => $department_id, ':name' => $template_name, ':cat' => $specialty_category, ':payload' => $fields_json, ':act' => $is_active]);
                }
                $db->commit();
                echo json_encode(['success' => true, 'message' => 'Template saved successfully.']);
            } catch (Exception $e) {
                $db->rollBack();
                echo json_encode(['success' => false, 'message' => 'Save failed: ' . $e->getMessage()]);
            }
            exit;
        }

        // ---------- TOGGLE TEMPLATE ACTIVE STATUS ----------
        if ($action === 'toggle_template_status') {
            $template_id = (int)($_POST['template_id'] ?? 0);
            $current_status = (int)($_POST['current_status'] ?? 1);
            $new_status = $current_status ? 0 : 1;
            $update = $db->prepare("UPDATE clinical_soap_templates SET is_active = :act WHERE id = :id");
            $update->execute([':act' => $new_status, ':id' => $template_id]);
            echo json_encode(['success' => true, 'message' => 'Template status updated.']);
            exit;
        }

        // ---------- GET ANALYTICS HUD DATA ----------
        if ($action === 'get_protocol_analytics') {
            // Active custom templates
            $activeTemplates = $db->query("SELECT COUNT(*) FROM clinical_soap_templates WHERE is_active = 1")->fetchColumn();
            // Modified critical thresholds (count where critical_low or critical_high is set)
            $modifiedThresholds = $db->query("SELECT COUNT(*) FROM clinical_thresholds WHERE critical_low IS NOT NULL OR critical_high IS NOT NULL")->fetchColumn();
            // Monitored vital parameters today (triage records count)
            $vitalsToday = $db->query("SELECT COUNT(*) FROM triage_records WHERE DATE(created_at) = CURDATE()")->fetchColumn();
            // Most active specialty form
            $mostActive = $db->query("SELECT specialty_category, COUNT(*) as cnt FROM clinical_soap_templates GROUP BY specialty_category ORDER BY cnt DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            $mostActiveSpecialty = $mostActive ? $mostActive['specialty_category'] : 'N/A';
            echo json_encode([
                'success' => true,
                'active_templates' => (int)$activeTemplates,
                'modified_thresholds' => (int)$modifiedThresholds,
                'vitals_today' => (int)$vitalsToday,
                'most_active_specialty' => $mostActiveSpecialty
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
    <title>Clinical Template & Protocol Builder | Super Admin | HMS ERP</title>
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
        .threshold-input { width: 100px; display: inline-block; margin: 0 4px; }
        .loading-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(255,255,255,0.7); z-index: 2000; display: none; align-items: center; justify-content: center; }
        .loading-overlay.active { display: flex; }
        .spinner { width: 40px; height: 40px; border: 3px solid var(--gray-light); border-top-color: var(--primary); border-radius: 50%; animation: spin 0.8s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }
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
                        <div class="welcome-title">Clinical Template & Protocol Builder</div>
                        <div class="welcome-subtitle">Super Admin Console: Triage thresholds, SOAP note templates, clinical protocols.</div>
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
                <div class="stat-card"><div class="stat-value" id="activeTemplates">0</div><div class="stat-label">Active Custom Templates</div></div>
                <div class="stat-card"><div class="stat-value" id="modifiedThresholds">0</div><div class="stat-label">Modified Critical Thresholds</div></div>
                <div class="stat-card"><div class="stat-value" id="vitalsToday">0</div><div class="stat-label">Vitals Recorded Today</div></div>
                <div class="stat-card"><div class="stat-value" id="topSpecialty">--</div><div class="stat-label">Most Active Specialty</div></div>
            </div>

            <!-- Tabs -->
            <ul class="nav nav-tabs mb-4" id="protocolTabs" role="tablist">
                <li class="nav-item"><button class="nav-link active" id="thresholds-tab" data-bs-toggle="tab" data-bs-target="#thresholds">Triage Alert Thresholds</button></li>
                <li class="nav-item"><button class="nav-link" id="templates-tab" data-bs-toggle="tab" data-bs-target="#templates">SOAP Note Template Designer</button></li>
                <li class="nav-item"><button class="nav-link" id="protocols-tab" data-bs-toggle="tab" data-bs-target="#protocols">Active Protocols Registry</button></li>
            </ul>
            <div class="tab-content">
                <!-- THRESHOLDS MATRIX -->
                <div class="tab-pane fade show active" id="thresholds">
                    <div class="stat-card">
                        <h5 class="mb-3"><i class="fas fa-heartbeat me-2"></i>Vital Signs Threshold Configuration</h5>
                        <div class="table-responsive">
                            <table id="thresholdsTable" class="table table-bordered">
                                <thead>
                                    <tr><th>Metric</th><th>Min Normal</th><th>Max Normal</th><th>Critical Low</th><th>Critical High</th><th>Alert Color</th><th>Actions</th></tr>
                                </thead>
                                <tbody><tr><td colspan="7" class="text-center">Loading thresholds...</td></tr></tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- SOAP TEMPLATE DESIGNER -->
                <div class="tab-pane fade" id="templates">
                    <div class="stat-card">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h5 class="mb-0"><i class="fas fa-file-alt me-2"></i>Clinical SOAP Note Templates</h5>
                            <button class="quick-btn quick-btn-primary" id="addTemplateBtn"><i class="fas fa-plus me-2"></i>Create New Template</button>
                        </div>
                        <div class="table-responsive">
                            <table id="templatesTable" class="table table-hover" style="width:100%">
                                <thead><tr><th>ID</th><th>Template Name</th><th>Department</th><th>Specialty</th><th>Fields</th><th>Status</th><th>Actions</th></tr></thead>
                                <tbody><tr><td colspan="7" class="text-center">Loading...</td></tr></tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- PROTOCOLS REGISTRY (Info only) -->
                <div class="tab-pane fade" id="protocols">
                    <div class="stat-card">
                        <h5><i class="fas fa-list-check me-2"></i>Active Clinical Protocols</h5>
                        <p class="text-muted">Threshold rules are automatically enforced during triage. Templates are available in doctor/nurse modules.</p>
                        <div class="alert alert-info">All configured thresholds and templates are live and will be used by clinical staff.</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php include('assets/inc/footer.php'); ?>
</div>

<div id="loadingOverlay" class="loading-overlay"><div class="spinner"></div></div>

<!-- MODAL: Edit Threshold -->
<div id="thresholdModal" class="modal fade" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content"><div class="modal-header"><h5>Edit Clinical Threshold</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body"><form id="thresholdForm"><input type="hidden" name="threshold_id" id="thresholdId"><div class="mb-2"><label>Metric Name</label><input type="text" id="thresholdMetric" class="form-control" disabled></div><div class="mb-2"><label>Min Normal</label><input type="number" step="0.1" name="min_normal" id="thresholdMinNormal" class="form-control"></div><div class="mb-2"><label>Max Normal</label><input type="number" step="0.1" name="max_normal" id="thresholdMaxNormal" class="form-control"></div><div class="mb-2"><label>Critical Low</label><input type="number" step="0.1" name="critical_low" id="thresholdCriticalLow" class="form-control"></div><div class="mb-2"><label>Critical High</label><input type="number" step="0.1" name="critical_high" id="thresholdCriticalHigh" class="form-control"></div><div class="mb-2"><label>Alert Color</label><select name="alert_color" id="thresholdColor" class="form-select"><option value="danger">Red (Danger)</option><option value="warning">Orange (Warning)</option><option value="info">Blue (Info)</option></select></div><input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>"></form></div>
    <div class="modal-footer"><button class="quick-btn quick-btn-outline" data-bs-dismiss="modal">Cancel</button><button class="quick-btn quick-btn-primary" id="saveThresholdBtn">Save Changes</button></div></div></div>
</div>

<!-- MODAL: Template Builder -->
<div id="templateModal" class="modal fade" tabindex="-1">
    <div class="modal-dialog modal-lg"><div class="modal-content"><div class="modal-header"><h5 id="templateModalTitle">SOAP Template Builder</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
        <form id="templateForm">
            <input type="hidden" name="template_id" id="templateId">
            <div class="mb-2"><label>Template Name *</label><input type="text" name="template_name" id="templateName" class="form-control" required></div>
            <div class="mb-2"><label>Department *</label><select name="department_id" id="templateDept" class="form-select" required><option value="">Select Department</option></select></div>
            <div class="mb-2"><label>Specialty Category *</label><input type="text" name="specialty_category" id="templateSpecialty" class="form-control" placeholder="e.g., Cardiology, Pediatrics, Dental" required></div>
            <div class="mb-2"><label>Form Fields (JSON Schema)</label>
                <textarea name="form_payload" id="templatePayload" rows="8" class="form-control" placeholder='[{"label":"Chief Complaint","type":"textarea"},{"label":"Blood Pressure","type":"text"}]'></textarea>
                <small class="text-muted">Enter valid JSON array of field objects. Example: [{"label":"Chief Complaint","type":"textarea"},{"label":"Visual Acuity","type":"text"}]</small>
            </div>
            <div class="form-check mb-2"><input type="checkbox" name="is_active" id="templateActive" class="form-check-input" checked><label class="form-check-label">Active</label></div>
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
        </form>
    </div>
    <div class="modal-footer"><button class="quick-btn quick-btn-outline" data-bs-dismiss="modal">Cancel</button><button class="quick-btn quick-btn-primary" id="saveTemplateBtn">Save Template</button></div></div></div>
</div>

<script src="assets/js/vendor.min.js"></script>
<script src="assets/js/app.min.js"></script>
<script>
const csrfToken = "<?php echo $csrf_token; ?>";
let thresholdsTable, templatesDataTable;
let currentThresholdId = 0;

$(function(){
    loadAnalytics();
    loadThresholds();
    loadTemplates();
    loadDepartmentsDropdown();
    $('#globalBranchFilter, #refreshAllBtn').on('change click', function(){ loadAnalytics(); loadThresholds(); loadTemplates(); });
    $('#addTemplateBtn').on('click', function(){ resetTemplateForm(); $('#templateModal').modal('show'); });
    $('#saveThresholdBtn').on('click', saveThreshold);
    $('#saveTemplateBtn').on('click', saveTemplate);
});

function loadAnalytics() {
    let branch = $('#globalBranchFilter').val();
    $.ajax({
        url: window.location.pathname + '?ajax=template_action',
        method: 'POST',
        dataType: 'json',
        data: { action: 'get_protocol_analytics', branch_id: branch, csrf_token: csrfToken },
        success: function(res){
            if(res.success){
                $('#activeTemplates').text(res.active_templates);
                $('#modifiedThresholds').text(res.modified_thresholds);
                $('#vitalsToday').text(res.vitals_today);
                $('#topSpecialty').text(res.most_active_specialty);
            }
        }
    });
}

function loadThresholds() {
    showLoading(true);
    $.ajax({
        url: window.location.pathname + '?ajax=template_action',
        method: 'POST',
        dataType: 'json',
        data: { action: 'get_thresholds', csrf_token: csrfToken },
        success: function(res){
            showLoading(false);
            if(res.success && res.thresholds){
                let rows = '';
                res.thresholds.forEach(t => {
                    rows += `<tr>
                        <td><strong>${escapeHtml(t.metric_name)}</strong></td>
                        <td>${t.min_normal !== null ? t.min_normal : '—'}</td>
                        <td>${t.max_normal !== null ? t.max_normal : '—'}</td>
                        <td>${t.critical_low !== null ? t.critical_low : '—'}</td>
                        <td>${t.critical_high !== null ? t.critical_high : '—'}</td>
                        <td><span class="badge bg-${t.alert_color}">${t.alert_color}</span></td>
                        <td><button class="btn btn-sm btn-outline-primary edit-threshold" data-id="${t.id}" data-metric="${escapeHtml(t.metric_name)}" data-min="${t.min_normal}" data-max="${t.max_normal}" data-clow="${t.critical_low}" data-chigh="${t.critical_high}" data-color="${t.alert_color}"><i class="fas fa-edit"></i> Edit</button></td>
                    </tr>`;
                });
                $('#thresholdsTable tbody').html(rows);
                $('.edit-threshold').on('click', function(){ openThresholdEdit($(this).data('id'), $(this).data('metric'), $(this).data('min'), $(this).data('max'), $(this).data('clow'), $(this).data('chigh'), $(this).data('color')); });
            } else {
                $('#thresholdsTable tbody').html('<tr><td colspan="7">Failed to load thresholds. </td></tr>');
            }
        },
        error: function(){ showLoading(false); Swal.fire('Error', 'Network error', 'error'); }
    });
}

function openThresholdEdit(id, metric, minNorm, maxNorm, critLow, critHigh, color){
    $('#thresholdId').val(id);
    $('#thresholdMetric').val(metric);
    $('#thresholdMinNormal').val(minNorm !== null ? minNorm : '');
    $('#thresholdMaxNormal').val(maxNorm !== null ? maxNorm : '');
    $('#thresholdCriticalLow').val(critLow !== null ? critLow : '');
    $('#thresholdCriticalHigh').val(critHigh !== null ? critHigh : '');
    $('#thresholdColor').val(color);
    $('#thresholdModal').modal('show');
}

function saveThreshold(){
    let data = $('#thresholdForm').serialize() + '&action=save_threshold_bounds';
    showLoading(true);
    $.ajax({
        url: window.location.pathname + '?ajax=template_action',
        method: 'POST',
        data: data,
        dataType: 'json',
        success: function(res){
            showLoading(false);
            if(res.success){
                Swal.fire('Success', res.message, 'success');
                $('#thresholdModal').modal('hide');
                loadThresholds();
                loadAnalytics();
            } else {
                Swal.fire('Error', res.message, 'error');
            }
        },
        error: function(){ showLoading(false); Swal.fire('Error', 'Save failed', 'error'); }
    });
}

function loadTemplates() {
    let branch = $('#globalBranchFilter').val();
    showLoading(true);
    $.ajax({
        url: window.location.pathname + '?ajax=template_action',
        method: 'POST',
        dataType: 'json',
        data: { action: 'get_soap_templates', branch_id: branch, csrf_token: csrfToken },
        success: function(res){
            showLoading(false);
            if(res.success && res.templates){
                if(templatesDataTable) templatesDataTable.destroy();
                let rows = '';
                res.templates.forEach(t => {
                    let fieldsCount = Array.isArray(t.form_payload) ? t.form_payload.length : 0;
                    let statusBadge = t.is_active ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>';
                    rows += `<tr>
                        <td>${t.id}</td>
                        <td>${escapeHtml(t.template_name)}</td>
                        <td>${escapeHtml(t.department_name)}</td>
                        <td>${escapeHtml(t.specialty_category)}</td>
                        <td>${fieldsCount} fields</td>
                        <td>${statusBadge}</td>
                        <td><button class="btn btn-sm btn-outline-primary edit-template" data-id="${t.id}" data-name="${escapeHtml(t.template_name)}" data-dept="${t.department_id}" data-specialty="${escapeHtml(t.specialty_category)}" data-payload='${JSON.stringify(t.form_payload)}' data-active="${t.is_active}"><i class="fas fa-edit"></i> Edit</button>
                            <button class="btn btn-sm btn-outline-secondary toggle-template" data-id="${t.id}" data-status="${t.is_active}"><i class="fas fa-power-off"></i> Toggle</button>
                         </tr>`;
                });
                $('#templatesTable tbody').html(rows);
                templatesDataTable = $('#templatesTable').DataTable({ order: [[0, 'desc']], pageLength: 10 });
                $('.edit-template').on('click', function(){ openTemplateEdit($(this).data('id'), $(this).data('name'), $(this).data('dept'), $(this).data('specialty'), $(this).data('payload'), $(this).data('active')); });
                $('.toggle-template').on('click', function(){ toggleTemplateStatus($(this).data('id'), $(this).data('status')); });
            } else {
                $('#templatesTable tbody').html('<tr><td colspan="7">No templates found. </td></tr>');
            }
        },
        error: function(){ showLoading(false); Swal.fire('Error', 'Failed to load templates', 'error'); }
    });
}

function loadDepartmentsDropdown() {
    let branch = $('#globalBranchFilter').val();
    $.ajax({
        url: window.location.pathname + '?ajax=template_action',
        method: 'POST',
        dataType: 'json',
        data: { action: 'get_departments', branch_id: branch, csrf_token: csrfToken },
        success: function(res){
            if(res.success && res.departments){
                let opts = '<option value="">Select Department</option>';
                res.departments.forEach(d => { opts += `<option value="${d.id}">${escapeHtml(d.name)}</option>`; });
                $('#templateDept').html(opts);
            }
        }
    });
}

function resetTemplateForm(){
    $('#templateId').val('');
    $('#templateName').val('');
    $('#templateDept').val('');
    $('#templateSpecialty').val('');
    $('#templatePayload').val('[]');
    $('#templateActive').prop('checked', true);
    $('#templateModalTitle').text('Create SOAP Template');
}

function openTemplateEdit(id, name, deptId, specialty, payload, active){
    $('#templateId').val(id);
    $('#templateName').val(name);
    $('#templateDept').val(deptId);
    $('#templateSpecialty').val(specialty);
    $('#templatePayload').val(JSON.stringify(payload, null, 2));
    $('#templateActive').prop('checked', active == 1);
    $('#templateModalTitle').text('Edit SOAP Template');
    $('#templateModal').modal('show');
}

function saveTemplate(){
    let data = $('#templateForm').serialize() + '&action=save_soap_template';
    showLoading(true);
    $.ajax({
        url: window.location.pathname + '?ajax=template_action',
        method: 'POST',
        data: data,
        dataType: 'json',
        success: function(res){
            showLoading(false);
            if(res.success){
                Swal.fire('Success', res.message, 'success');
                $('#templateModal').modal('hide');
                loadTemplates();
                loadAnalytics();
            } else {
                Swal.fire('Error', res.message, 'error');
            }
        },
        error: function(){ showLoading(false); Swal.fire('Error', 'Save failed', 'error'); }
    });
}

function toggleTemplateStatus(id, current){
    Swal.fire({
        title: 'Toggle Template Status',
        text: 'Change active status of this template?',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Yes, toggle'
    }).then(res => {
        if(res.isConfirmed){
            showLoading(true);
            $.ajax({
                url: window.location.pathname + '?ajax=template_action',
                method: 'POST',
                dataType: 'json',
                data: { action: 'toggle_template_status', template_id: id, current_status: current, csrf_token: csrfToken },
                success: function(r){
                    showLoading(false);
                    if(r.success){
                        Swal.fire('Updated', r.message, 'success');
                        loadTemplates();
                        loadAnalytics();
                    } else { Swal.fire('Error', r.message, 'error'); }
                },
                error: function(){ showLoading(false); Swal.fire('Error', 'Toggle failed', 'error'); }
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