<?php
/**
 * Super Admin - Executive KPI Dashboard & Employee Performance
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

$branch_id = (int)($_SESSION['branch_id'] ?? 0);
$user_id   = (int)($_SESSION['user_id'] ?? 0);
$user_name = $_SESSION['first_name'] ?? $_SESSION['username'] ?? 'Super Admin';

$db_available = is_db_available();
if (!$db_available) die('Database connection failed.');

$pdo = getDB();

// ============================================
// AJAX ROUTER - ALL ENDPOINTS (POST only with CSRF)
// ============================================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'dashboard_kpi') {
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

    // ---------- LIVE PATIENT TRAFFIC FLOW (PIE CHART & KPI CARDS) ----------
    if ($action === 'get_live_traffic') {
        $branch_filter = (int)($_POST['branch_id'] ?? 0);
        $today = date('Y-m-d');
        
        // Total registrations today (patients created)
        $regSql = "SELECT COUNT(*) FROM patients WHERE DATE(created_at) = CURDATE() AND deleted_at IS NULL";
        if ($branch_filter > 0) $regSql .= " AND branch_id = :bid";
        $regStmt = $db->prepare($regSql);
        if ($branch_filter > 0) $regStmt->execute([':bid' => $branch_filter]);
        else $regStmt->execute();
        $total_registrations = (int)$regStmt->fetchColumn();
        
        // Waiting (pending)
        $waitSql = "SELECT COUNT(*) FROM patient_visits WHERE DATE(created_at) = CURDATE() AND status = 'pending' AND deleted_at IS NULL";
        if ($branch_filter > 0) $waitSql .= " AND branch_id = :bid";
        $waitStmt = $db->prepare($waitSql);
        if ($branch_filter > 0) $waitStmt->execute([':bid' => $branch_filter]);
        else $waitStmt->execute();
        $waiting = (int)$waitStmt->fetchColumn();
        
        // Triaged (in_triage)
        $triageSql = "SELECT COUNT(*) FROM patient_visits WHERE DATE(created_at) = CURDATE() AND status = 'in_triage' AND deleted_at IS NULL";
        if ($branch_filter > 0) $triageSql .= " AND branch_id = :bid";
        $triageStmt = $db->prepare($triageSql);
        if ($branch_filter > 0) $triageStmt->execute([':bid' => $branch_filter]);
        else $triageStmt->execute();
        $triaged = (int)$triageStmt->fetchColumn();
        
        // In consultation
        $consultSql = "SELECT COUNT(*) FROM patient_visits WHERE DATE(created_at) = CURDATE() AND status = 'in_consultation' AND deleted_at IS NULL";
        if ($branch_filter > 0) $consultSql .= " AND branch_id = :bid";
        $consultStmt = $db->prepare($consultSql);
        if ($branch_filter > 0) $consultStmt->execute([':bid' => $branch_filter]);
        else $consultStmt->execute();
        $in_consult = (int)$consultStmt->fetchColumn();
        
        // Completed today
        $compSql = "SELECT COUNT(*) FROM patient_visits WHERE DATE(created_at) = CURDATE() AND status = 'completed' AND deleted_at IS NULL";
        if ($branch_filter > 0) $compSql .= " AND branch_id = :bid";
        $compStmt = $db->prepare($compSql);
        if ($branch_filter > 0) $compStmt->execute([':bid' => $branch_filter]);
        else $compStmt->execute();
        $completed = (int)$compStmt->fetchColumn();
        
        echo json_encode([
            'success' => true,
            'total_registrations' => $total_registrations,
            'waiting' => $waiting,
            'triaged' => $triaged,
            'in_consult' => $in_consult,
            'completed' => $completed
        ]);
        exit;
    }

    // ---------- RECEPTION PRODUCTIVITY RANKING ----------
    if ($action === 'get_reception_rank') {
        $branch_filter = (int)($_POST['branch_id'] ?? 0);
        $sql = "SELECT u.id, CONCAT(u.first_name, ' ', u.last_name) AS name,
                       COUNT(p.id) AS patients_registered,
                       COALESCE(SUM(bi.amount), 0) AS fees_collected
                FROM users u
                LEFT JOIN patients p ON p.created_by_user_id = u.id AND DATE(p.created_at) = CURDATE() AND p.deleted_at IS NULL
                LEFT JOIN billing_items bi ON bi.patient_id = p.id AND bi.item_type = 'card_fee' AND bi.status = 'paid' AND DATE(bi.created_at) = CURDATE()
                WHERE u.user_type = 'receptionist' AND u.is_active = 1 AND u.deleted_at IS NULL";
        if ($branch_filter > 0) $sql .= " AND u.branch_id = :bid";
        $sql .= " GROUP BY u.id ORDER BY patients_registered DESC";
        $stmt = $db->prepare($sql);
        if ($branch_filter > 0) $stmt->execute([':bid' => $branch_filter]);
        else $stmt->execute();
        $receptionists = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'receptionists' => $receptionists]);
        exit;
    }

    // ---------- DOCTOR CONSULTATION THROUGHPUT & CLINICAL AUDIT ----------
    if ($action === 'get_doctor_radar') {
        $branch_filter = (int)($_POST['branch_id'] ?? 0);
        $sql = "SELECT u.id, CONCAT(u.first_name, ' ', u.last_name) AS name,
                       COUNT(DISTINCT pv.id) AS patients_seen,
                       ROUND(AVG(TIMESTAMPDIFF(MINUTE, pv.consultation_started_at, pv.consultation_completed_at)), 1) AS avg_duration_mins,
                       COUNT(lo.id) AS lab_orders_issued
                FROM users u
                LEFT JOIN patient_visits pv ON pv.doctor_id = u.id AND DATE(pv.created_at) = CURDATE() AND pv.status IN ('in_consultation', 'completed') AND pv.deleted_at IS NULL
                LEFT JOIN lab_orders lo ON lo.doctor_id = u.id AND DATE(lo.created_at) = CURDATE() AND lo.deleted_at IS NULL
                WHERE u.user_type = 'doctor' AND u.is_active = 1 AND u.deleted_at IS NULL";
        if ($branch_filter > 0) $sql .= " AND u.branch_id = :bid";
        $sql .= " GROUP BY u.id ORDER BY patients_seen DESC";
        $stmt = $db->prepare($sql);
        if ($branch_filter > 0) $stmt->execute([':bid' => $branch_filter]);
        else $stmt->execute();
        $doctors = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'doctors' => $doctors]);
        exit;
    }

    // ---------- TURNAROUND TIME (TAT) OPERATIONAL METRICS ----------
    if ($action === 'get_tat_metrics') {
        $branch_filter = (int)($_POST['branch_id'] ?? 0);
        // Average triage response time (from patient_visits creation to triage record)
        $triageTatSql = "SELECT AVG(TIMESTAMPDIFF(MINUTE, pv.created_at, tr.created_at)) AS avg_triage_min
                         FROM patient_visits pv
                         JOIN triage_records tr ON tr.visit_id = pv.id
                         WHERE DATE(pv.created_at) = CURDATE() AND pv.deleted_at IS NULL AND tr.deleted_at IS NULL";
        if ($branch_filter > 0) $triageTatSql .= " AND pv.branch_id = :bid";
        $triageStmt = $db->prepare($triageTatSql);
        if ($branch_filter > 0) $triageStmt->execute([':bid' => $branch_filter]);
        else $triageStmt->execute();
        $avg_triage = round((float)$triageStmt->fetchColumn(), 1);
        
        // Average wait time from triage to consultation start
        $waitTatSql = "SELECT AVG(TIMESTAMPDIFF(MINUTE, tr.created_at, pv.consultation_started_at)) AS avg_wait_min
                       FROM patient_visits pv
                       JOIN triage_records tr ON tr.visit_id = pv.id
                       WHERE DATE(pv.created_at) = CURDATE() AND pv.consultation_started_at IS NOT NULL
                         AND pv.deleted_at IS NULL AND tr.deleted_at IS NULL";
        if ($branch_filter > 0) $waitTatSql .= " AND pv.branch_id = :bid";
        $waitStmt = $db->prepare($waitTatSql);
        if ($branch_filter > 0) $waitStmt->execute([':bid' => $branch_filter]);
        else $waitStmt->execute();
        $avg_wait = round((float)$waitStmt->fetchColumn(), 1);
        
        echo json_encode(['success' => true, 'avg_triage_response' => $avg_triage, 'avg_consult_wait' => $avg_wait]);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action']);
    exit;
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
    <title>Executive KPI Dashboard | Super Admin | HMS ERP</title>
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
        body { font-family: 'Inter', sans-serif; background: linear-gradient(135deg, #f5f7fb 0%, #eef2f6 100%); overflow-x: hidden; }
        .content-page { margin-left: 260px; margin-top: 70px; padding: 24px 32px; transition: margin-left 0.3s; min-height: calc(100vh - 70px); }
        body.erp-sidebar-collapsed .content-page { margin-left: 80px; }
        @media (max-width: 768px) { .content-page { margin-left: 0 !important; } }
        .welcome-header { background: linear-gradient(135deg, var(--primary-dark) 0%, var(--primary) 100%); border-radius: 24px; padding: 24px 32px; color: white; margin-bottom: 28px; }
        .filter-bar { background: white; border-radius: 20px; padding: 16px 24px; margin-bottom: 24px; display: flex; gap: 16px; flex-wrap: wrap; align-items: flex-end; }
        .kpi-card { background: white; border-radius: 20px; padding: 20px; text-align: center; box-shadow: 0 4px 12px rgba(0,0,0,0.05); transition: all 0.2s; }
        .kpi-value { font-size: 32px; font-weight: 800; color: var(--dark); }
        .kpi-label { font-size: 13px; color: var(--gray); margin-top: 6px; }
        .chart-container { background: white; border-radius: 20px; padding: 20px; margin-bottom: 24px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); }
        .stat-card { background: white; border-radius: 20px; padding: 20px; margin-bottom: 20px; border: 1px solid var(--gray-light); }
        .quick-btn { padding: 10px 20px; border-radius: 14px; font-size: 14px; font-weight: 600; border: none; cursor: pointer; transition: all 0.2s; }
        .quick-btn-primary { background: linear-gradient(135deg, var(--primary), var(--primary-dark)); color: white; }
        .loading-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(255,255,255,0.7); z-index: 2000; display: none; align-items: center; justify-content: center; }
        .loading-overlay.active { display: flex; }
        .spinner { width: 40px; height: 40px; border: 3px solid var(--gray-light); border-top-color: var(--primary); border-radius: 50%; animation: spin 0.8s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }
        .tat-box { background: linear-gradient(135deg, var(--info), #0891b2); color: white; border-radius: 20px; padding: 20px; text-align: center; }
        .tat-value { font-size: 28px; font-weight: 800; }
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
                        <div class="welcome-title">Executive KPI Dashboard & Employee Performance</div>
                        <div class="welcome-subtitle">Real-time hospital analytics, staff productivity, and operational turnaround metrics.</div>
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
                    <button class="quick-btn quick-btn-primary" id="refreshDashboardBtn"><i class="fas fa-sync-alt me-2"></i>Refresh Data</button>
                </div>
            </div>

            <!-- KPI Cards (Patient Traffic) -->
            <div class="row mb-4">
                <div class="col-md-2 col-6 mb-3"><div class="kpi-card"><div class="kpi-value" id="kpiTotalReg">0</div><div class="kpi-label">Total Registrations</div></div></div>
                <div class="col-md-2 col-6 mb-3"><div class="kpi-card"><div class="kpi-value" id="kpiWaiting">0</div><div class="kpi-label">Waiting</div></div></div>
                <div class="col-md-2 col-6 mb-3"><div class="kpi-card"><div class="kpi-value" id="kpiTriaged">0</div><div class="kpi-label">Triaged</div></div></div>
                <div class="col-md-2 col-6 mb-3"><div class="kpi-card"><div class="kpi-value" id="kpiInConsult">0</div><div class="kpi-label">In Consultation</div></div></div>
                <div class="col-md-2 col-6 mb-3"><div class="kpi-card"><div class="kpi-value" id="kpiCompleted">0</div><div class="kpi-label">Completed Today</div></div></div>
                <div class="col-md-2 col-6 mb-3"><div class="kpi-card" style="background: linear-gradient(135deg, var(--primary), var(--primary-dark)); color: white;"><div class="kpi-value" id="kpiTotalActive">0</div><div class="kpi-label">Active Patients (All)</div></div></div>
            </div>

            <!-- Charts Row -->
            <div class="row">
                <div class="col-md-6">
                    <div class="chart-container">
                        <h5 class="mb-3"><i class="fas fa-chart-pie me-2"></i>Live Traffic Distribution (Today)</h5>
                        <canvas id="trafficPieChart" height="250"></canvas>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="chart-container">
                        <h5 class="mb-3"><i class="fas fa-chart-bar me-2"></i>Doctor Performance: Patients Seen vs Lab Orders</h5>
                        <canvas id="doctorBarChart" height="250"></canvas>
                    </div>
                </div>
            </div>

            <!-- TAT Metrics Row -->
            <div class="row mb-4">
                <div class="col-md-6"><div class="tat-box"><div class="tat-value" id="tatTriage">-- min</div><div>Average Triage Response Time (Today)</div></div></div>
                <div class="col-md-6"><div class="tat-box"><div class="tat-value" id="tatConsultWait">-- min</div><div>Average Wait Time (Triage → Consultation)</div></div></div>
            </div>

            <!-- Staff Productivity Tables -->
            <div class="row">
                <div class="col-md-6">
                    <div class="stat-card">
                        <h5><i class="fas fa-user-tie me-2"></i>Reception Productivity</h5>
                        <div class="table-responsive">
                            <table id="receptionTable" class="table table-hover">
                                <thead><tr><th>Receptionist</th><th>Patients Registered</th><th>Card Fees Collected (ETB)</th></tr></thead>
                                <tbody><tr><td colspan="3" class="text-center">Loading...</td></tr></tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="stat-card">
                        <h5><i class="fas fa-stethoscope me-2"></i>Doctor Consultation Radar</h5>
                        <div class="table-responsive">
                            <table id="doctorTable" class="table table-hover">
                                <thead><tr><th>Doctor</th><th>Patients Seen</th><th>Avg Duration (min)</th><th>Lab Orders</th></tr></thead>
                                <tbody><tr><td colspan="4" class="text-center">Loading...</td></tr></tbody>
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

<script src="assets/js/vendor.min.js"></script>
<script src="assets/js/app.min.js"></script>
<script>
const csrfToken = "<?php echo $csrf_token; ?>";
let trafficChart = null;
let doctorChart = null;
let autoRefreshInterval = null;

$(function(){
    loadAllDashboardData();
    $('#globalBranchFilter, #refreshDashboardBtn').on('change click', function(){ loadAllDashboardData(); });
    // Auto refresh every 30 seconds
    if(autoRefreshInterval) clearInterval(autoRefreshInterval);
    autoRefreshInterval = setInterval(loadAllDashboardData, 30000);
});

function loadAllDashboardData() {
    loadTrafficAndKPIs();
    loadReceptionRanking();
    loadDoctorRadar();
    loadTATMetrics();
}

function loadTrafficAndKPIs() {
    let branch = $('#globalBranchFilter').val();
    $.ajax({
        url: window.location.pathname + '?ajax=dashboard_kpi',
        method: 'POST',
        dataType: 'json',
        data: { action: 'get_live_traffic', branch_id: branch, csrf_token: csrfToken },
        success: function(res){
            if(res.success){
                $('#kpiTotalReg').text(res.total_registrations);
                $('#kpiWaiting').text(res.waiting);
                $('#kpiTriaged').text(res.triaged);
                $('#kpiInConsult').text(res.in_consult);
                $('#kpiCompleted').text(res.completed);
                // Update pie chart
                if(trafficChart) trafficChart.destroy();
                const ctx = document.getElementById('trafficPieChart').getContext('2d');
                trafficChart = new Chart(ctx, {
                    type: 'pie',
                    data: {
                        labels: ['Waiting', 'Triaged', 'In Consultation', 'Completed'],
                        datasets: [{
                            data: [res.waiting, res.triaged, res.in_consult, res.completed],
                            backgroundColor: ['#f9c74f', '#4cc9f0', '#4361ee', '#06d6a0'],
                            borderWidth: 0
                        }]
                    },
                    options: { responsive: true, maintainAspectRatio: true, plugins: { legend: { position: 'bottom' } } }
                });
            }
        }
    });
}

function loadReceptionRanking() {
    let branch = $('#globalBranchFilter').val();
    $.ajax({
        url: window.location.pathname + '?ajax=dashboard_kpi',
        method: 'POST',
        dataType: 'json',
        data: { action: 'get_reception_rank', branch_id: branch, csrf_token: csrfToken },
        success: function(res){
            if(res.success && res.receptionists){
                let html = '';
                res.receptionists.forEach(rec => {
                    html += `<tr><td>${escapeHtml(rec.name)}</td><td>${rec.patients_registered}</td><td>${parseFloat(rec.fees_collected).toFixed(2)}</td></tr>`;
                });
                if(html === '') html = '<tr><td colspan="3">No receptionist data for today.</td></tr>';
                $('#receptionTable tbody').html(html);
            } else {
                $('#receptionTable tbody').html('<tr><td colspan="3">No data available.</td></tr>');
            }
        }
    });
}

function loadDoctorRadar() {
    let branch = $('#globalBranchFilter').val();
    $.ajax({
        url: window.location.pathname + '?ajax=dashboard_kpi',
        method: 'POST',
        dataType: 'json',
        data: { action: 'get_doctor_radar', branch_id: branch, csrf_token: csrfToken },
        success: function(res){
            if(res.success && res.doctors){
                let html = '';
                let doctorNames = [];
                let patientsSeen = [];
                let labOrders = [];
                res.doctors.forEach(doc => {
                    html += `<tr><td>${escapeHtml(doc.name)}</td><td>${doc.patients_seen}</td><td>${doc.avg_duration_mins || '—'}</td><td>${doc.lab_orders_issued}</td></tr>`;
                    doctorNames.push(doc.name);
                    patientsSeen.push(doc.patients_seen);
                    labOrders.push(doc.lab_orders_issued);
                });
                if(html === '') html = '<tr><td colspan="4">No doctor activity today.</td></tr>';
                $('#doctorTable tbody').html(html);
                // Update bar chart
                if(doctorChart) doctorChart.destroy();
                const ctx = document.getElementById('doctorBarChart').getContext('2d');
                doctorChart = new Chart(ctx, {
                    type: 'bar',
                    data: {
                        labels: doctorNames,
                        datasets: [
                            { label: 'Patients Seen', data: patientsSeen, backgroundColor: '#4361ee', borderRadius: 8 },
                            { label: 'Lab Orders Issued', data: labOrders, backgroundColor: '#4cc9f0', borderRadius: 8 }
                        ]
                    },
                    options: { responsive: true, maintainAspectRatio: true, scales: { y: { beginAtZero: true, title: { display: true, text: 'Count' } } } }
                });
            }
        }
    });
}

function loadTATMetrics() {
    let branch = $('#globalBranchFilter').val();
    $.ajax({
        url: window.location.pathname + '?ajax=dashboard_kpi',
        method: 'POST',
        dataType: 'json',
        data: { action: 'get_tat_metrics', branch_id: branch, csrf_token: csrfToken },
        success: function(res){
            if(res.success){
                $('#tatTriage').text(res.avg_triage_response + ' min');
                $('#tatConsultWait').text(res.avg_consult_wait + ' min');
            } else {
                $('#tatTriage').text('-- min');
                $('#tatConsultWait').text('-- min');
            }
        }
    });
}

function escapeHtml(str) {
    if(!str) return '';
    return String(str).replace(/[&<>]/g, m => m === '&' ? '&amp;' : (m === '<' ? '&lt;' : '&gt;'));
}
</script>
</body>
</html>