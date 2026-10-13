<?php
/**
 * Super Admin Central Command & Analytics Dashboard
 * Module: dashboard.php
 * 
 * Real-time operational analytics, financial core, clinical TAT,
 * supply chain risk, staff productivity, and security audit stream.
 * 
 * @package HMS-ERP
 * @subpackage SuperAdmin
 * @version 7.0
 */

if (session_status() === PHP_SESSION_NONE) session_start();

// ============================================
// 1. IRONCLAD AUTHENTICATION & SECURITY
// ============================================
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'super_admin') {
    http_response_code(403);
    die('<h1>Access Denied</h1><p>Super Administrator privileges required.</p>');
}

require_once __DIR__ . '/../../config/db_connection.php';
require_once __DIR__ . '/../../includes/auth_helper.php';

$db = getDB();
if (!$db) {
    die('Database connection error. Contact IT support.');
}

$branch_id = (int)($_SESSION['branch_id'] ?? 0);
$user_id   = (int)($_SESSION['user_id'] ?? 0);
$user_name = $_SESSION['first_name'] ?? $_SESSION['username'] ?? 'Super Admin';
$current_date_display = date('l, F j, Y');
$current_time_display = date('g:i A');

// ============================================
// 2. AJAX ROUTER (GET actions only)
// ============================================
if (isset($_GET['action'])) {
    error_reporting(0);
    ini_set('display_errors', 0);
    if (ob_get_level()) ob_clean();
    header('Content-Type: application/json; charset=utf-8');

    $action = $_GET['action'];
    $pdo = $db;

    try {
        switch ($action) {
            case 'get_traffic_flow':
                $traffic = getTrafficFlow($pdo);
                echo json_encode(['success' => true, 'data' => $traffic]);
                break;
            case 'get_branch_hud':
                $branchData = getBranchHUD($pdo);
                echo json_encode(['success' => true, 'data' => $branchData]);
                break;
            case 'get_finance_core':
                $finance = getFinanceCore($pdo);
                echo json_encode(['success' => true, 'data' => $finance]);
                break;
            case 'get_inventory_risk':
                $risk = getInventoryRisk($pdo);
                echo json_encode(['success' => true, 'data' => $risk]);
                break;
            case 'get_clinical_tat':
                $clinical = getClinicalTAT($pdo);
                echo json_encode(['success' => true, 'data' => $clinical]);
                break;
            case 'get_productivity':
                $productivity = getProductivity($pdo);
                echo json_encode(['success' => true, 'data' => $productivity]);
                break;
            case 'get_security_pulse':
                $security = getSecurityPulse($pdo);
                echo json_encode(['success' => true, 'data' => $security]);
                break;
            default:
                echo json_encode(['success' => false, 'message' => 'Unknown action']);
        }
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}

// ============================================
// 3. CORE ANALYTICS FUNCTIONS
// ============================================

/**
 * Real-Time Hospital Traffic Flow (Patient Process)
 */
function getTrafficFlow(PDO $pdo): array {
    $sql = "SELECT status, COUNT(id) as count 
            FROM patient_visits 
            WHERE DATE(created_at) = CURDATE() AND deleted_at IS NULL
            GROUP BY status";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $flow = [
        'waiting' => 0,
        'triaged' => 0,
        'consulting' => 0,
        'completed' => 0
    ];
    foreach ($rows as $row) {
        switch ($row['status']) {
            case 'pending': $flow['waiting'] += (int)$row['count']; break;
            case 'in_triage': $flow['triaged'] += (int)$row['count']; break;
            case 'in_consultation': $flow['consulting'] += (int)$row['count']; break;
            case 'completed': $flow['completed'] += (int)$row['count']; break;
            default: break;
        }
    }
    return $flow;
}

/**
 * Multi-Branch Operational HUD
 */
function getBranchHUD(PDO $pdo): array {
    // Fetch all active branches
    $branchStmt = $pdo->query("SELECT id, name FROM branches WHERE deleted_at IS NULL AND status = 'active'");
    $branches = $branchStmt->fetchAll(PDO::FETCH_ASSOC);
    
    $result = [];
    foreach ($branches as $branch) {
        $branchId = $branch['id'];
        
        // Today's active visits (excluding completed/cancelled/no_show)
        $visitsStmt = $pdo->prepare("SELECT COUNT(*) FROM patient_visits WHERE branch_id = :bid AND DATE(created_at) = CURDATE() AND status NOT IN ('completed','cancelled','no_show') AND deleted_at IS NULL");
        $visitsStmt->execute([':bid' => $branchId]);
        $activeVisits = (int)$visitsStmt->fetchColumn();
        
        // Today's confirmed appointments
        $apptStmt = $pdo->prepare("SELECT COUNT(*) FROM appointments WHERE branch_id = :bid AND appointment_date = CURDATE() AND status NOT IN ('cancelled') AND deleted_at IS NULL");
        $apptStmt->execute([':bid' => $branchId]);
        $confirmedAppts = (int)$apptStmt->fetchColumn();
        
        // Live inpatient admissions (status = 'admitted')
        $admitStmt = $pdo->prepare("SELECT COUNT(*) FROM admissions WHERE branch_id = :bid AND status = 'admitted' AND deleted_at IS NULL");
        $admitStmt->execute([':bid' => $branchId]);
        $liveInpatients = (int)$admitStmt->fetchColumn();
        
        $result[] = [
            'branch_name' => $branch['name'],
            'active_visits' => $activeVisits,
            'confirmed_appointments' => $confirmedAppts,
            'live_inpatients' => $liveInpatients
        ];
    }
    return $result;
}

/**
 * Executive Revenue & Financial Health Core
 */
function getFinanceCore(PDO $pdo): array {
    // Gross revenue collected and pending amount from vw_daily_revenue_by_branch
    $revenueStmt = $pdo->prepare("SELECT SUM(collected_amount) as collected, SUM(pending_amount) as pending FROM vw_daily_revenue_by_branch WHERE invoice_date = CURDATE()");
    $revenueStmt->execute();
    $revenue = $revenueStmt->fetch(PDO::FETCH_ASSOC);
    
    // Approved daily operational cash outflow
    $expenseStmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) as total FROM expenses WHERE DATE(expense_date) = CURDATE() AND status = 'approved' AND deleted_at IS NULL");
    $expenseStmt->execute();
    $expenses = (float)$expenseStmt->fetchColumn();
    
    return [
        'gross_revenue' => (float)($revenue['collected'] ?? 0),
        'pending_amount' => (float)($revenue['pending'] ?? 0),
        'approved_expenses' => $expenses
    ];
}

/**
 * Cross-Branch Supply Chain & Expiry Risk Watchdog
 */
function getInventoryRisk(PDO $pdo): array {
    // Low-stock critical triggers
    $lowStockStmt = $pdo->prepare("SELECT COUNT(*) FROM pharmacy_inventory WHERE quantity_in_hand <= reorder_level AND deleted_at IS NULL");
    $lowStockStmt->execute();
    $lowStockCount = (int)$lowStockStmt->fetchColumn();
    
    // Imminent batches expiry (next 90 days)
    $expiryStmt = $pdo->prepare("SELECT m.generic_name, i.batch_number, i.expiry_date 
                                 FROM pharmacy_inventory i 
                                 JOIN medicines_master m ON i.medicine_id = m.id 
                                 WHERE i.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 90 DAY) 
                                 AND i.deleted_at IS NULL
                                 ORDER BY i.expiry_date ASC LIMIT 10");
    $expiryStmt->execute();
    $expiringBatches = $expiryStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Procurement pipeline flow (pending orders count)
    $pendingOrdersStmt = $pdo->query("SELECT COUNT(*) as pending FROM vw_pending_supplier_orders");
    $pendingOrders = (int)$pendingOrdersStmt->fetchColumn();
    
    return [
        'low_stock_count' => $lowStockCount,
        'expiring_batches' => $expiringBatches,
        'pending_supplier_orders' => $pendingOrders
    ];
}

/**
 * Clinical Quality Audit & Turnaround Time
 */
function getClinicalTAT(PDO $pdo): array {
    // Average triage wait time (minutes) for today
    $tatStmt = $pdo->prepare("SELECT AVG(TIMESTAMPDIFF(MINUTE, pv.created_at, tr.created_at)) as avg_wait 
                              FROM patient_visits pv 
                              JOIN triage_records tr ON tr.visit_id = pv.id 
                              WHERE DATE(pv.created_at) = CURDATE() 
                              AND pv.deleted_at IS NULL AND tr.deleted_at IS NULL");
    $tatStmt->execute();
    $avgTriageWait = round((float)$tatStmt->fetchColumn(), 1);
    
    // High-risk lab warning count (critical lab results today)
    $criticalLabStmt = $pdo->prepare("SELECT COUNT(*) FROM lab_test_results WHERE is_critical = 1 AND DATE(created_at) = CURDATE()");
    $criticalLabStmt->execute();
    $criticalLabCount = (int)$criticalLabStmt->fetchColumn();
    
    return [
        'avg_triage_wait_minutes' => $avgTriageWait,
        'critical_lab_results_today' => $criticalLabCount
    ];
}

/**
 * Staff Productivity Leaderboard
 */
function getProductivity(PDO $pdo): array {
    // Top 5 doctors by number of consultations today
    $doctorStmt = $pdo->prepare("SELECT CONCAT(u.first_name, ' ', u.last_name) as name, COUNT(c.id) as total 
                                 FROM consultations c 
                                 JOIN users u ON c.doctor_id = u.id 
                                 WHERE DATE(c.created_at) = CURDATE() 
                                 AND c.deleted_at IS NULL 
                                 GROUP BY c.doctor_id 
                                 ORDER BY total DESC LIMIT 5");
    $doctorStmt->execute();
    $topDoctors = $doctorStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Top 5 nurses by triage count today
    $nurseStmt = $pdo->prepare("SELECT CONCAT(u.first_name, ' ', u.last_name) as name, COUNT(tr.id) as total 
                                FROM triage_records tr 
                                JOIN users u ON tr.taken_by_user_id = u.id 
                                WHERE DATE(tr.created_at) = CURDATE() 
                                AND tr.deleted_at IS NULL 
                                GROUP BY tr.taken_by_user_id 
                                ORDER BY total DESC LIMIT 5");
    $nurseStmt->execute();
    $topNurses = $nurseStmt->fetchAll(PDO::FETCH_ASSOC);
    
    return [
        'top_doctors' => $topDoctors,
        'top_nurses' => $topNurses
    ];
}

/**
 * DevSecOps Security & Audit Pulse
 */
function getSecurityPulse(PDO $pdo): array {
    // Recent forensic activity logs
    $auditStmt = $pdo->prepare("SELECT old_values, new_values, ip_address, created_at 
                                FROM audit_logs 
                                ORDER BY created_at DESC LIMIT 5");
    $auditStmt->execute();
    $recentAudits = $auditStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Latest database backup success
    $backupStmt = $pdo->prepare("SELECT MAX(created_at) as last_backup FROM backup_logs WHERE status = 'success'");
    $backupStmt->execute();
    $lastBackup = $backupStmt->fetchColumn();
    
    return [
        'recent_audit_logs' => $recentAudits,
        'last_successful_backup' => $lastBackup ? date('Y-m-d H:i:s', strtotime($lastBackup)) : 'No successful backup found'
    ];
}

// ============================================
// 4. MAIN PAGE RENDERING (HTML/UI)
// ============================================
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Super Admin Command Center | HMS ERP</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
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
        .welcome-title { font-size: 24px; font-weight: 700; margin-bottom: 4px; }
        .kpi-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin-bottom: 28px; }
        @media (max-width: 800px) { .kpi-grid { grid-template-columns: 1fr; } }
        .kpi-card { background: white; border-radius: 20px; padding: 20px; border-left: 5px solid var(--primary); box-shadow: 0 4px 12px rgba(0,0,0,0.05); }
        .kpi-value { font-size: 32px; font-weight: 800; color: var(--dark); }
        .kpi-label { font-size: 13px; color: var(--gray); margin-top: 8px; }
        .chart-row { display: flex; gap: 24px; margin-bottom: 28px; flex-wrap: wrap; }
        .chart-card { background: white; border-radius: 24px; padding: 20px; flex: 1; min-width: 280px; box-shadow: 0 4px 12px rgba(0,0,0,0.05); }
        .alert-card { background: white; border-radius: 24px; padding: 20px; margin-bottom: 28px; }
        .expiry-item { background: #fff3cd; border-left: 4px solid var(--warning); padding: 10px 16px; margin-bottom: 8px; border-radius: 12px; }
        .leaderboard-table { width: 100%; border-collapse: collapse; }
        .leaderboard-table th, .leaderboard-table td { padding: 12px; border-bottom: 1px solid var(--gray-light); }
        .audit-log { font-family: monospace; font-size: 12px; background: #f8f9fa; padding: 8px 12px; border-radius: 8px; margin-bottom: 8px; }
        .badge-risk { background: var(--danger); color: white; padding: 4px 12px; border-radius: 20px; font-size: 12px; }
        .refresh-note { text-align: right; font-size: 12px; color: var(--gray); margin-top: 8px; }
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
                        <div class="welcome-title">Central Command & Analytics Dashboard</div>
                        <div class="welcome-subtitle">Real-time enterprise pulse, multi-branch HUD, financial core, and security stream.</div>
                    </div>
                    <div class="col-md-4 text-md-end mt-3 mt-md-0">
                        <div class="time-badge" style="background: rgba(255,255,255,0.15); padding: 8px 16px; border-radius: 12px; display: inline-block;">
                            <i class="far fa-calendar-alt"></i> <?php echo htmlspecialchars($current_date_display); ?>
                            <i class="far fa-clock ms-2"></i> <?php echo htmlspecialchars($current_time_display); ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Financial KPI Cards -->
            <div class="kpi-grid" id="financeKpiContainer">
                <div class="kpi-card"><div class="kpi-value" id="grossRevenue">-- ETB</div><div class="kpi-label">Gross Revenue Collected (Today)</div></div>
                <div class="kpi-card"><div class="kpi-value" id="pendingAmount">-- ETB</div><div class="kpi-label">Accounts Receivable (Pending)</div></div>
                <div class="kpi-card"><div class="kpi-value" id="approvedExpenses">-- ETB</div><div class="kpi-label">Approved Cash Outflow (Today)</div></div>
            </div>

            <!-- Charts Row -->
            <div class="chart-row">
                <div class="chart-card">
                    <h5><i class="fas fa-chart-pie me-2"></i>Hospital Traffic Flow (Today)</h5>
                    <canvas id="trafficChart" width="400" height="300" style="max-height: 300px;"></canvas>
                </div>
                <div class="chart-card">
                    <h5><i class="fas fa-chart-bar me-2"></i>Multi-Branch Operational HUD</h5>
                    <canvas id="branchChart" width="400" height="300" style="max-height: 300px;"></canvas>
                </div>
            </div>

            <!-- Supply Chain & Expiry Risk Watchdog -->
            <div class="alert-card">
                <h5><i class="fas fa-exclamation-triangle me-2 text-warning"></i>Inventory Risk & Expiry Alarm</h5>
                <div id="inventoryRiskContent">Loading risk data...</div>
            </div>

            <!-- Clinical TAT & Productivity Grid -->
            <div class="row g-4 mb-4">
                <div class="col-md-6">
                    <div class="alert-card">
                        <h5><i class="fas fa-clock me-2"></i>Clinical Quality TAT</h5>
                        <div id="clinicalTatContent">Loading...</div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="alert-card">
                        <h5><i class="fas fa-trophy me-2"></i>Staff Productivity Leaderboard</h5>
                        <div id="productivityContent">Loading...</div>
                    </div>
                </div>
            </div>

            <!-- Security Audit Pulse -->
            <div class="alert-card">
                <h5><i class="fas fa-shield-alt me-2"></i>DevSecOps Audit Pulse</h5>
                <div id="securityPulseContent">Loading...</div>
            </div>
            <div class="refresh-note"><i class="fas fa-sync-alt me-1"></i>Auto-refreshes every 30 seconds</div>
        </div>
    </div>
    <?php include('assets/inc/footer.php'); ?>
</div>

<script src="assets/js/vendor.min.js"></script>
<script src="assets/js/app.min.js"></script>
<script>
    // Chart instances
    let trafficChart, branchChart;
    
    // Fetch all dashboard data and update UI
    async function refreshAllData() {
        await Promise.all([
            fetchTrafficFlow(),
            fetchBranchHUD(),
            fetchFinanceCore(),
            fetchInventoryRisk(),
            fetchClinicalTAT(),
            fetchProductivity(),
            fetchSecurityPulse()
        ]);
    }
    
    async function fetchTrafficFlow() {
        try {
            const res = await fetch(window.location.pathname + '?action=get_traffic_flow');
            const data = await res.json();
            if (data.success) {
                const flow = data.data;
                if (trafficChart) trafficChart.destroy();
                const ctx = document.getElementById('trafficChart').getContext('2d');
                trafficChart = new Chart(ctx, {
                    type: 'doughnut',
                    data: {
                        labels: ['Waiting in Reception', 'Triaged by Nurses', 'Consulting with Doctors', 'Completed Discharged'],
                        datasets: [{
                            data: [flow.waiting, flow.triaged, flow.consulting, flow.completed],
                            backgroundColor: ['#f9c74f', '#4cc9f0', '#4361ee', '#06d6a0'],
                            borderWidth: 0
                        }]
                    },
                    options: { responsive: true, maintainAspectRatio: true, plugins: { legend: { position: 'bottom' } } }
                });
            }
        } catch(e) { console.error('Traffic flow error', e); }
    }
    
    async function fetchBranchHUD() {
        try {
            const res = await fetch(window.location.pathname + '?action=get_branch_hud');
            const data = await res.json();
            if (data.success && data.data.length) {
                const branches = data.data;
                if (branchChart) branchChart.destroy();
                const ctx = document.getElementById('branchChart').getContext('2d');
                branchChart = new Chart(ctx, {
                    type: 'bar',
                    data: {
                        labels: branches.map(b => b.branch_name),
                        datasets: [
                            { label: 'Active Visits', data: branches.map(b => b.active_visits), backgroundColor: '#4361ee' },
                            { label: 'Confirmed Appointments', data: branches.map(b => b.confirmed_appointments), backgroundColor: '#4cc9f0' },
                            { label: 'Live Inpatients', data: branches.map(b => b.live_inpatients), backgroundColor: '#06d6a0' }
                        ]
                    },
                    options: { responsive: true, maintainAspectRatio: true, scales: { y: { beginAtZero: true } } }
                });
            }
        } catch(e) { console.error('Branch HUD error', e); }
    }
    
    async function fetchFinanceCore() {
        try {
            const res = await fetch(window.location.pathname + '?action=get_finance_core');
            const data = await res.json();
            if (data.success) {
                document.getElementById('grossRevenue').innerText = data.data.gross_revenue.toLocaleString() + ' ETB';
                document.getElementById('pendingAmount').innerText = data.data.pending_amount.toLocaleString() + ' ETB';
                document.getElementById('approvedExpenses').innerText = data.data.approved_expenses.toLocaleString() + ' ETB';
            }
        } catch(e) { console.error('Finance error', e); }
    }
    
    async function fetchInventoryRisk() {
        try {
            const res = await fetch(window.location.pathname + '?action=get_inventory_risk');
            const data = await res.json();
            if (data.success) {
                let html = `<div class="mb-3"><span class="badge-risk"><i class="fas fa-boxes me-1"></i> Low Stock Items: ${data.data.low_stock_count}</span>
                            <span class="badge bg-warning ms-2"><i class="fas fa-hourglass-half me-1"></i> Pending Supplier Orders: ${data.data.pending_supplier_orders}</span></div>`;
                html += `<h6>Expiring Batches (next 90 days):</h6>`;
                if (data.data.expiring_batches.length === 0) {
                    html += `<div class="text-muted">No expiring batches in the next 90 days.</div>`;
                } else {
                    data.data.expiring_batches.forEach(b => {
                        html += `<div class="expiry-item"><strong>${escapeHtml(b.generic_name)}</strong> (Batch: ${escapeHtml(b.batch_number)}) - Expires: ${b.expiry_date}</div>`;
                    });
                }
                document.getElementById('inventoryRiskContent').innerHTML = html;
            }
        } catch(e) { console.error('Inventory risk error', e); }
    }
    
    async function fetchClinicalTAT() {
        try {
            const res = await fetch(window.location.pathname + '?action=get_clinical_tat');
            const data = await res.json();
            if (data.success) {
                let html = `<p><i class="fas fa-stopwatch me-2"></i> Average Triage Wait Time: <strong>${data.data.avg_triage_wait_minutes} minutes</strong></p>
                           <p><i class="fas fa-flask me-2"></i> Critical Lab Results Today: <span class="badge bg-danger">${data.data.critical_lab_results_today}</span></p>`;
                document.getElementById('clinicalTatContent').innerHTML = html;
            }
        } catch(e) { console.error('Clinical TAT error', e); }
    }
    
    async function fetchProductivity() {
        try {
            const res = await fetch(window.location.pathname + '?action=get_productivity');
            const data = await res.json();
            if (data.success) {
                let doctorHtml = '<table class="leaderboard-table"><thead><tr><th>Doctor</th><th>Consultations Today</th></tr></thead><tbody>';
                data.data.top_doctors.forEach(doc => {
                    doctorHtml += `<tr><td>${escapeHtml(doc.name)}</td><td>${doc.total}</td></tr>`;
                });
                doctorHtml += '</tbody></table>';
                
                let nurseHtml = '<table class="leaderboard-table mt-3"><thead><tr><th>Nurse</th><th>Triage Count Today</th></tr></thead><tbody>';
                data.data.top_nurses.forEach(nurse => {
                    nurseHtml += `<tr><td>${escapeHtml(nurse.name)}</td><td>${nurse.total}</td></tr>`;
                });
                nurseHtml += '</tbody></table>';
                
                document.getElementById('productivityContent').innerHTML = `<h6>🏆 Top 5 Doctors</h6>${doctorHtml}<h6 class="mt-3">🏆 Top 5 Nurses</h6>${nurseHtml}`;
            }
        } catch(e) { console.error('Productivity error', e); }
    }
    
    async function fetchSecurityPulse() {
        try {
            const res = await fetch(window.location.pathname + '?action=get_security_pulse');
            const data = await res.json();
            if (data.success) {
                let auditHtml = `<p><i class="fas fa-database me-2"></i> Last Successful Backup: <strong>${escapeHtml(data.data.last_successful_backup)}</strong></p>
                                 <h6>Recent System Mutation Logs:</h6>`;
                if (data.data.recent_audit_logs.length === 0) {
                    auditHtml += '<div class="text-muted">No recent audit logs.</div>';
                } else {
                    data.data.recent_audit_logs.forEach(log => {
                        auditHtml += `<div class="audit-log">
                                        <i class="fas fa-code-branch me-2"></i> <strong>${log.created_at}</strong> | IP: ${escapeHtml(log.ip_address)}<br>
                                        <small class="text-muted">Changes: ${escapeHtml(log.old_values)} → ${escapeHtml(log.new_values)}</small>
                                      </div>`;
                    });
                }
                document.getElementById('securityPulseContent').innerHTML = auditHtml;
            }
        } catch(e) { console.error('Security pulse error', e); }
    }
    
    function escapeHtml(str) {
        if (!str) return '';
        return String(str).replace(/[&<>]/g, m => m === '&' ? '&amp;' : (m === '<' ? '&lt;' : '&gt;'));
    }
    
    // Auto-refresh every 30 seconds
    refreshAllData();
    setInterval(refreshAllData, 30000);
</script>
</body>
</html>