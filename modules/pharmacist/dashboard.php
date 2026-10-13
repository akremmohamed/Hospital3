<?php
/**
 * Pharmacist Operational Command Dashboard
 * Provides real‑time analytics: pending/dispensed prescriptions, low stock alerts,
 * expiry chart, inter‑branch transfer requests, and live prescription stream.
 *
 * @package HMS-ERP
 * @version 1.0
 */

if (session_status() === PHP_SESSION_NONE) session_start();

// ============================================
// 1. IRONCLAD AUTHENTICATION – PHARMACIST ONLY
// ============================================
if (!isset($_SESSION['user_id']) || !isset($_SESSION['branch_id']) || ($_SESSION['user_type'] ?? '') !== 'pharmacist') {
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized Access. Pharmacist security authorization required.']);
        exit;
    }
    header('Location: ../login.php');
    exit;
}

require_once __DIR__ . '/../../config/db_connection.php';
date_default_timezone_set('Africa/Addis_Ababa');
$pdo = getDB();
if (!$pdo) die('Database connection failed.');

// CSRF token for any mutation requests (if needed later)
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// ============================================
// 2. ASYNCHRONOUS AJAX ROUTER PIPELINE
// ============================================
if (isset($_GET['action'])) {
    error_reporting(0);
    ini_set('display_errors', 0);
    if (ob_get_level()) ob_clean();
    header('Content-Type: application/json; charset=utf-8');

    // Re-authenticate
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['branch_id']) || ($_SESSION['user_type'] ?? '') !== 'pharmacist') {
        echo json_encode(['status' => 'error', 'message' => 'Session expired or unauthorized.']);
        exit;
    }

    $branchId = (int)$_SESSION['branch_id'];
    $action = $_GET['action'];

    // ---------- Refresh Dashboard Telemetry (counters + chart data) ----------
    if ($action === 'refresh_dashboard_telemetry') {
        try {
            // 1. Pending Prescriptions Today
            $pendingSql = "
                SELECT COUNT(*) AS total
                FROM prescriptions p
                INNER JOIN patient_visits pv ON p.visit_id = pv.id
                WHERE pv.branch_id = :branch_id
                  AND p.status IN ('pending_payment', 'active')
                  AND DATE(p.created_at) = CURDATE()
                  AND p.deleted_at IS NULL
            ";
            $pendingStmt = $pdo->prepare($pendingSql);
            $pendingStmt->execute([':branch_id' => $branchId]);
            $pendingToday = (int)$pendingStmt->fetchColumn();

            // 2. Dispensed Orders Today
            $dispensedSql = "
                SELECT COUNT(*) AS total
                FROM prescriptions p
                INNER JOIN patient_visits pv ON p.visit_id = pv.id
                WHERE pv.branch_id = :branch_id
                  AND p.status = 'dispensed'
                  AND DATE(p.dispensed_at) = CURDATE()
                  AND p.deleted_at IS NULL
            ";
            $dispensedStmt = $pdo->prepare($dispensedSql);
            $dispensedStmt->execute([':branch_id' => $branchId]);
            $dispensedToday = (int)$dispensedStmt->fetchColumn();

            // 3. Critical Low Stock Alerts
            $lowStockSql = "
                SELECT COUNT(DISTINCT pi.medicine_id) AS total
                FROM pharmacy_inventory pi
                WHERE pi.branch_id = :branch_id
                  AND pi.deleted_at IS NULL
                  AND pi.quantity_in_hand > 0
                  AND pi.quantity_in_hand <= pi.reorder_level
                  AND pi.expiry_date >= CURDATE()
                  AND pi.is_quarantined = 0
            ";
            $lowStockStmt = $pdo->prepare($lowStockSql);
            $lowStockStmt->execute([':branch_id' => $branchId]);
            $lowStockAlerts = (int)$lowStockStmt->fetchColumn();

            // 4. Expired / Quarantined Batches
            $expiredSql = "
                SELECT COUNT(*) AS total
                FROM pharmacy_inventory
                WHERE branch_id = :branch_id
                  AND deleted_at IS NULL
                  AND (expiry_date <= CURDATE() OR is_quarantined = 1)
            ";
            $expiredStmt = $pdo->prepare($expiredSql);
            $expiredStmt->execute([':branch_id' => $branchId]);
            $expiredBatches = (int)$expiredStmt->fetchColumn();

            // Chart Data: Stock Expiry Categories
            $safeSql = "
                SELECT COUNT(*) AS total
                FROM pharmacy_inventory
                WHERE branch_id = :branch_id
                  AND deleted_at IS NULL
                  AND expiry_date > DATE_ADD(CURDATE(), INTERVAL 3 MONTH)
                  AND is_quarantined = 0
            ";
            $safeStmt = $pdo->prepare($safeSql);
            $safeStmt->execute([':branch_id' => $branchId]);
            $safeStock = (int)$safeStmt->fetchColumn();

            $expiringSql = "
                SELECT COUNT(*) AS total
                FROM pharmacy_inventory
                WHERE branch_id = :branch_id
                  AND deleted_at IS NULL
                  AND expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 3 MONTH)
                  AND is_quarantined = 0
            ";
            $expiringStmt = $pdo->prepare($expiringSql);
            $expiringStmt->execute([':branch_id' => $branchId]);
            $expiringSoon = (int)$expiringStmt->fetchColumn();

            $expiredTotal = $expiredBatches; // already includes quarantined + expired

            echo json_encode([
                'status' => 'success',
                'counters' => [
                    'pending_today' => $pendingToday,
                    'dispensed_today' => $dispensedToday,
                    'low_stock_alerts' => $lowStockAlerts,
                    'expired_batches' => $expiredBatches
                ],
                'chart' => [
                    'safe_stock' => $safeStock,
                    'expiring_3months' => $expiringSoon,
                    'expired_quarantined' => $expiredTotal
                ]
            ]);
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
        }
        exit;
    }

    // ---------- Fetch Live Prescription Stream (Top 5 Recent Pending) ----------
    if ($action === 'fetch_live_stream') {
        try {
            $sql = "
                SELECT 
                    p.id AS prescription_id,
                    p.prescription_number,
                    p.created_at,
                    CONCAT(pt.first_name, ' ', COALESCE(pt.middle_name, ''), ' ', pt.last_name) AS patient_name,
                    CONCAT(u.first_name, ' ', COALESCE(u.middle_name, ''), ' ', u.last_name) AS doctor_name,
                    GROUP_CONCAT(DISTINCT pi.medicine_name SEPARATOR ', ') AS medicines
                FROM prescriptions p
                INNER JOIN patient_visits pv ON p.visit_id = pv.id
                INNER JOIN patients pt ON p.patient_id = pt.id
                LEFT JOIN users u ON p.doctor_id = u.id
                LEFT JOIN prescription_items pi ON p.id = pi.prescription_id AND pi.deleted_at IS NULL
                WHERE pv.branch_id = :branch_id
                  AND p.status IN ('pending_payment', 'active')
                  AND p.deleted_at IS NULL
                GROUP BY p.id
                ORDER BY p.created_at DESC
                LIMIT 5
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':branch_id' => $branchId]);
            $prescriptions = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['status' => 'success', 'prescriptions' => $prescriptions]);
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
        }
        exit;
    }

    echo json_encode(['status' => 'error', 'message' => 'Invalid action.']);
    exit;
}

// ============================================
// 3. INITIAL PAGE LOAD – USER & BRANCH INFO
// ============================================
$userId = (int)$_SESSION['user_id'];
$branchId = (int)$_SESSION['branch_id'];

// Fetch pharmacist name
$pharmacistName = 'Pharmacist';
$branchName = 'Unknown Branch';
try {
    $userStmt = $pdo->prepare("SELECT CONCAT(first_name, ' ', COALESCE(middle_name, ''), ' ', last_name) AS name FROM users WHERE id = :id");
    $userStmt->execute([':id' => $userId]);
    $pharmacistName = $userStmt->fetchColumn() ?: 'Pharmacist';

    $branchStmt = $pdo->prepare("SELECT name FROM branches WHERE id = :id AND deleted_at IS NULL");
    $branchStmt->execute([':id' => $branchId]);
    $branchName = $branchStmt->fetchColumn() ?: 'Unknown Branch';
} catch (PDOException $e) {
    error_log('Failed to fetch user/branch info: ' . $e->getMessage());
}

$currentDateDisplay = date('l, F j, Y');
$currentTimeDisplay = date('g:i A');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pharmacist Command Dashboard | HMS ERP</title>
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
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 0;
        }
        .dashboard-widget {
            background: white;
            border-radius: 24px;
            padding: 20px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
            margin-bottom: 28px;
        }
        .data-table th {
            font-size: 13px;
            font-weight: 600;
            background: #f8fafc;
        }
        .data-table td {
            vertical-align: middle;
            padding: 12px 16px;
        }
        .btn-sm-custom {
            padding: 4px 12px;
            font-size: 12px;
            border-radius: 30px;
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
        .transfer-card {
            background: #f8fafc;
            border-radius: 16px;
            padding: 12px;
            margin-bottom: 12px;
        }
    </style>
</head>
<body>
<div id="wrapper">
    <?php include('assets/inc/nav.php'); ?>
    <?php include('assets/inc/sidebar.php'); ?>
    <div class="content-page">
        <div class="container-fluid">
            <!-- Welcome Banner -->
            <div class="welcome-header">
                <div class="row align-items-center">
                    <div class="col-md-8">
                        <div class="welcome-title">
                            <i class="fas fa-chalkboard-user me-2"></i>Operational Command Dashboard
                        </div>
                        <div class="welcome-subtitle">
                            Welcome back, <?php echo htmlspecialchars($pharmacistName); ?> | 
                            <i class="fas fa-store me-1"></i><?php echo htmlspecialchars($branchName); ?>
                        </div>
                    </div>
                    <div class="col-md-4 text-md-end mt-3 mt-md-0">
                        <div class="time-badge" style="background: rgba(255,255,255,0.15); padding: 8px 16px; border-radius: 12px;">
                            <i class="far fa-calendar-alt"></i> <?php echo $currentDateDisplay; ?>
                            <i class="far fa-clock ms-2"></i> <?php echo $currentTimeDisplay; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Top Analytical HUD Cards -->
            <div class="row g-4 mb-4">
                <div class="col-md-3 col-sm-6">
                    <div class="stat-card text-center">
                        <i class="fas fa-prescription-bottle fa-2x text-warning mb-2"></i>
                        <h3 class="stat-number" id="pendingCount">0</h3>
                        <p class="text-muted mb-0">Pending Prescriptions Today</p>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="stat-card text-center">
                        <i class="fas fa-check-circle fa-2x text-success mb-2"></i>
                        <h3 class="stat-number" id="dispensedCount">0</h3>
                        <p class="text-muted mb-0">Dispensed Orders Today</p>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="stat-card text-center">
                        <i class="fas fa-exclamation-triangle fa-2x text-danger mb-2"></i>
                        <h3 class="stat-number" id="lowStockCount">0</h3>
                        <p class="text-muted mb-0">Critical Low Stock Alerts</p>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="stat-card text-center">
                        <i class="fas fa-skull-crossbones fa-2x text-secondary mb-2"></i>
                        <h3 class="stat-number" id="expiredCount">0</h3>
                        <p class="text-muted mb-0">Expired / Quarantined Batches</p>
                    </div>
                </div>
            </div>

            <!-- Two Column Layout: Live Prescription Stream + Stock Expiry Chart -->
            <div class="row">
                <div class="col-lg-7">
                    <div class="dashboard-widget">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h5 class="mb-0"><i class="fas fa-stream me-2 text-primary"></i>Live Prescription Influx (Recent Pending)</h5>
                            <a href="prescription_dispensary.php" class="btn btn-primary btn-sm-custom"><i class="fas fa-arrow-right me-1"></i>Go to Dispensary</a>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-hover data-table mb-0">
                                <thead>
                                    <tr><th>Prescription #</th><th>Patient</th><th>Doctor</th><th>Medicines</th><th>Action</th></tr>
                                </thead>
                                <tbody id="liveStreamTbody">
                                    <tr><td colspan="5" class="text-center py-4">Loading live stream...</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <div class="col-lg-5">
                    <div class="dashboard-widget">
                        <h5 class="mb-3"><i class="fas fa-chart-pie me-2 text-primary"></i>Stock Expiry Distribution</h5>
                        <canvas id="expiryChart" height="250" style="max-height:250px"></canvas>
                        <div class="row mt-3 text-center">
                            <div class="col-4"><span class="badge bg-success">Safe</span><br><span id="safeStockLabel">0</span></div>
                            <div class="col-4"><span class="badge bg-warning text-dark">Expiring ≤3m</span><br><span id="expiringLabel">0</span></div>
                            <div class="col-4"><span class="badge bg-danger">Expired/Quarantined</span><br><span id="expiredLabel">0</span></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Inter-Branch Actionable Requests Tracker -->
            <div class="dashboard-widget">
                <h5 class="mb-3"><i class="fas fa-exchange-alt me-2 text-primary"></i>Inter‑Branch Actionable Requests (Pending Transfers)</h5>
                <div id="transferRequestsContainer" class="row">
                    <div class="col-12 text-center py-4">Loading transfer requests...</div>
                </div>
                <div class="text-end mt-2">
                    <a href="pharmacy_transfers.php" class="btn btn-outline-primary btn-sm-custom"><i class="fas fa-arrow-right me-1"></i>Manage Transfers</a>
                </div>
            </div>
        </div>
    </div>
    <?php include('assets/inc/footer.php'); ?>
</div>

<!-- Loading Overlay -->
<div id="loadingOverlay" class="loading-overlay"><div class="spinner"></div></div>

<script>
const csrfToken = "<?php echo $csrf_token; ?>";
let expiryChart = null;
let refreshInterval = null;

function showLoading(show) {
    if (show) $('#loadingOverlay').addClass('active');
    else $('#loadingOverlay').removeClass('active');
}

// Refresh dashboard telemetry (counters + chart data)
function refreshTelemetry() {
    $.ajax({
        url: window.location.pathname + '?action=refresh_dashboard_telemetry',
        method: 'GET',
        dataType: 'json',
        cache: false,
        success: function(res) {
            if (res.status === 'success') {
                $('#pendingCount').text(res.counters.pending_today);
                $('#dispensedCount').text(res.counters.dispensed_today);
                $('#lowStockCount').text(res.counters.low_stock_alerts);
                $('#expiredCount').text(res.counters.expired_batches);

                // Update chart
                const safe = res.chart.safe_stock;
                const expiring = res.chart.expiring_3months;
                const expired = res.chart.expired_quarantined;
                $('#safeStockLabel').text(safe);
                $('#expiringLabel').text(expiring);
                $('#expiredLabel').text(expired);

                if (expiryChart) {
                    expiryChart.data.datasets[0].data = [safe, expiring, expired];
                    expiryChart.update();
                } else {
                    const ctx = document.getElementById('expiryChart').getContext('2d');
                    expiryChart = new Chart(ctx, {
                        type: 'doughnut',
                        data: {
                            labels: ['Safe Stock (>3 months)', 'Expiring within 3 months', 'Expired / Quarantined'],
                            datasets: [{
                                data: [safe, expiring, expired],
                                backgroundColor: ['#06d6a0', '#f9c74f', '#ef476f'],
                                borderWidth: 0
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: true,
                            plugins: { legend: { position: 'bottom' } }
                        }
                    });
                }
            } else {
                console.error('Telemetry error:', res.message);
            }
        },
        error: function() { console.error('Failed to refresh telemetry'); }
    });
}

// Fetch live prescription stream (top 5 pending)
function fetchLiveStream() {
    $.ajax({
        url: window.location.pathname + '?action=fetch_live_stream',
        method: 'GET',
        dataType: 'json',
        cache: false,
        success: function(res) {
            if (res.status === 'success') {
                let tbody = '';
                if (res.prescriptions.length === 0) {
                    tbody = '<tr><td colspan="5" class="text-center py-4 text-muted">No pending prescriptions found.</td></tr>';
                } else {
                    res.prescriptions.forEach(function(rx) {
                        const medicines = rx.medicines ? rx.medicines.substring(0, 60) : '—';
                        tbody += `
                            <tr>
                                <td>${escapeHtml(rx.prescription_number)}</td>
                                <td>${escapeHtml(rx.patient_name)}</td>
                                <td>${escapeHtml(rx.doctor_name || '—')}</td>
                                <td><small>${escapeHtml(medicines)}</small></td>
                                <td><a href="prescription_dispensary.php" class="btn btn-sm btn-info btn-sm-custom">Process Order</a></td>
                            </tr>
                        `;
                    });
                }
                $('#liveStreamTbody').html(tbody);
            } else {
                $('#liveStreamTbody').html('<tr><td colspan="5" class="text-center text-danger">Error loading stream.</td></tr>');
            }
        },
        error: function() { $('#liveStreamTbody').html('<tr><td colspan="5" class="text-center text-danger">Network error.</td></tr>'); }
    });
}

// Fetch inter-branch actionable requests (pending inbound transfers)
function fetchTransferRequests() {
    $.ajax({
        url: 'pharmacy_transfers.php?action=load_transfer_tables',
        method: 'GET',
        dataType: 'json',
        cache: false,
        success: function(res) {
            if (res.status === 'success' && res.inbound && res.inbound.length > 0) {
                let html = '';
                res.inbound.forEach(function(transfer) {
                    html += `
                        <div class="col-md-6 col-lg-4">
                            <div class="transfer-card">
                                <i class="fas fa-truck-moving me-2 text-primary"></i>
                                <strong>${escapeHtml(transfer.source_branch)}</strong> requested
                                <strong>${escapeHtml(transfer.medicine_code)}</strong><br>
                                Quantity: ${transfer.quantity_transferred}<br>
                                <small>Reference: ${escapeHtml(transfer.transfer_reference_number)}</small><br>
                                <a href="pharmacy_transfers.php" class="btn btn-sm btn-success mt-2">Review Request</a>
                            </div>
                        </div>
                    `;
                });
                $('#transferRequestsContainer').html(html);
            } else {
                $('#transferRequestsContainer').html('<div class="col-12 text-center py-4 text-muted">No pending inter‑branch transfer requests.</div>');
            }
        },
        error: function() {
            $('#transferRequestsContainer').html('<div class="col-12 text-center py-4 text-danger">Unable to load transfer requests.</div>');
        }
    });
}

// Auto-refresh every 30 seconds (as required)
function startAutoRefresh() {
    if (refreshInterval) clearInterval(refreshInterval);
    refreshInterval = setInterval(function() {
        refreshTelemetry();
        fetchLiveStream();
        fetchTransferRequests();
    }, 30000);
}

$(document).ready(function() {
    refreshTelemetry();
    fetchLiveStream();
    fetchTransferRequests();
    startAutoRefresh();
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