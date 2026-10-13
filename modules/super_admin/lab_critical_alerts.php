<?php
/**
 * Lab Critical Alerts Center
 * Super Admin Module: Real-time monitoring of critical lab results with SLA tracking.
 *
 * Strictly based on hms_erp.sql schema. Uses `lab_orders`, `lab_test_results`, `patients`, `users` tables.
 *
 * @package HMS-ERP
 * @version 1.1 (fixed column mismatch)
 */

if (session_status() === PHP_SESSION_NONE) session_start();

// ============================================
// 1. IRONCLAD AUTHENTICATION – SUPER ADMIN ONLY
// ============================================
if (!isset($_SESSION['user_id']) || ($_SESSION['user_type'] ?? '') !== 'super_admin') {
    http_response_code(403);
    die('Access denied. Super administrator privileges required.');
}

require_once __DIR__ . '/../../config/db_connection.php';
require_once __DIR__ . '/../../includes/auth_helper.php';

date_default_timezone_set('Africa/Addis_Ababa');
$pdo = getDB();
if (!$pdo) die('Database connection failed.');

// CSRF token for mutation requests
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Ensure the required column `critical_notified_at` exists (add if missing)
try {
    $stmt = $pdo->query("SHOW COLUMNS FROM lab_orders LIKE 'critical_notified_at'");
    if ($stmt->rowCount() === 0) {
        $pdo->exec("ALTER TABLE lab_orders ADD COLUMN critical_notified_at TIMESTAMP NULL DEFAULT NULL");
    }
} catch (PDOException $e) {
    error_log('Could not alter lab_orders: ' . $e->getMessage());
}

// ============================================
// 2. AJAX ROUTER
// ============================================
if (isset($_GET['action'])) {
    error_reporting(0);
    ini_set('display_errors', 0);
    if (ob_get_level()) ob_clean();
    header('Content-Type: application/json; charset=utf-8');

    $action = $_GET['action'];

    // ---------- Load Critical Alerts Grid ----------
    if ($action === 'load_critical_grid') {
        try {
            // Inside the AJAX router, replace the existing SQL with:
$sql = "
SELECT 
    lo.id AS lab_order_id,
    ltr.id AS result_id,
    CASE 
        WHEN ltr.result_text IS NOT NULL THEN ltr.result_text
        ELSE CAST(ltr.result_numeric AS CHAR)
    END AS result_value,
    ltr.test_name,
    lo.created_at AS order_created_at,
    lo.critical_notified_at,
    ltr.is_critical,
    p.id AS patient_id,
    CONCAT(p.first_name, ' ', COALESCE(p.middle_name, ''), ' ', p.last_name) AS patient_name,
    p.mr_number,
    u.id AS doctor_id,
    CONCAT(u.first_name, ' ', COALESCE(u.middle_name, ''), ' ', u.last_name) AS doctor_name,
    u.email AS doctor_email,
    u.phone AS doctor_phone,
    pv.visit_number
FROM lab_test_results ltr
INNER JOIN lab_orders lo ON ltr.lab_order_id = lo.id
INNER JOIN patients p ON lo.patient_id = p.id
LEFT JOIN users u ON lo.doctor_id = u.id
LEFT JOIN patient_visits pv ON lo.visit_id = pv.id
WHERE ltr.is_critical = 1
  AND lo.status = 'completed'
  AND lo.deleted_at IS NULL
ORDER BY 
    CASE WHEN lo.critical_notified_at IS NULL THEN 0 ELSE 1 END,
    lo.created_at DESC
";
            $stmt = $pdo->prepare($sql);
            $stmt->execute();
            $alerts = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Compute counters
            $total_unacknowledged = 0;
            $acknowledged_today = 0;
            $today_date = date('Y-m-d');
            foreach ($alerts as $alert) {
                if ($alert['critical_notified_at'] === null) {
                    $total_unacknowledged++;
                } else {
                    $notified_date = date('Y-m-d', strtotime($alert['critical_notified_at']));
                    if ($notified_date === $today_date) {
                        $acknowledged_today++;
                    }
                }
            }

            echo json_encode([
                'success' => true,
                'alerts' => $alerts,
                'counters' => [
                    'total_unacknowledged' => $total_unacknowledged,
                    'acknowledged_today' => $acknowledged_today
                ]
            ]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        }
        exit;
    }

    // ---------- Acknowledge SLA (Mark Notified) ----------
    if ($action === 'acknowledge_sla') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
            exit;
        }
        $submittedToken = $_POST['csrf_token'] ?? '';
        if (!hash_equals($csrf_token, $submittedToken)) {
            echo json_encode(['success' => false, 'message' => 'CSRF validation failed.']);
            exit;
        }

        $orderId = (int) ($_POST['order_id'] ?? 0);
        if ($orderId <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid lab order ID.']);
            exit;
        }

        try {
            $update = $pdo->prepare("UPDATE lab_orders SET critical_notified_at = NOW() WHERE id = :id AND status = 'completed'");
            $update->execute([':id' => $orderId]);
            if ($update->rowCount() > 0) {
                echo json_encode(['success' => true, 'message' => 'SLA acknowledged. Notification timestamp recorded.']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Order not found or already acknowledged.']);
            }
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        }
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Invalid action']);
    exit;
}

// ============================================
// 3. INITIAL PAGE LOAD – No extra data needed, frontend will fetch via AJAX
// ============================================
$current_date_display = date('l, F j, Y');
$current_time_display = date('g:i A');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lab Critical Alerts Center | Super Admin | HMS ERP</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
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
            position: relative;
            z-index: 1;
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
        .alert-card {
            background: white;
            border-radius: 24px;
            border: 1px solid var(--gray-light);
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
            overflow: hidden;
            margin-bottom: 24px;
        }
        .alert-header {
            background: white;
            padding: 18px 24px;
            border-bottom: 1px solid var(--gray-light);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 16px;
        }
        .counter-badge {
            background: #f1f5f9;
            border-radius: 40px;
            padding: 6px 16px;
            font-weight: 600;
            font-size: 13px;
        }
        .critical-row {
            background-color: #fff5f5;
            border-left: 4px solid var(--danger);
            transition: background 0.2s;
        }
        .critical-row:hover {
            background-color: #ffe8e8;
        }
        .acknowledged-row {
            background-color: #ffffff;
            border-left: 4px solid var(--success);
        }
        .blink-red {
            animation: blinkWarning 1s ease-in-out infinite;
            font-weight: 700;
        }
        @keyframes blinkWarning {
            0% { color: var(--danger); opacity: 1; }
            50% { color: #ff8c8c; opacity: 0.7; }
            100% { color: var(--danger); opacity: 1; }
        }
        .btn-acknowledge {
            background: linear-gradient(135deg, var(--success), #059669);
            color: white;
            border: none;
            border-radius: 30px;
            padding: 6px 16px;
            font-size: 12px;
            font-weight: 600;
            transition: 0.2s;
        }
        .btn-acknowledge:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(6,214,160,0.3);
        }
        .badge-acknowledged {
            background: #e8f5e9;
            color: #2e7d32;
            padding: 4px 12px;
            border-radius: 30px;
            font-size: 12px;
        }
        .action-icons a {
            color: var(--gray);
            margin: 0 4px;
            transition: 0.2s;
        }
        .action-icons a:hover {
            color: var(--primary);
        }
        .data-table th {
            font-size: 13px;
            font-weight: 600;
            background: #f8fafc;
            border-bottom: 1px solid var(--gray-light);
        }
        .data-table td {
            vertical-align: middle;
            padding: 14px 16px;
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
        @media (max-width: 768px) {
            .alert-header { flex-direction: column; align-items: stretch; }
            .data-table td, .data-table th { padding: 10px 12px; }
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
                        <div class="welcome-title"><i class="fas fa-exclamation-triangle me-2"></i>Lab Critical Alerts Center</div>
                        <div class="welcome-subtitle">Real‑time monitoring of critical laboratory results with SLA tracking and escalation.</div>
                    </div>
                    <div class="col-md-4 text-md-end mt-3 mt-md-0">
                        <div class="time-badge" style="background: rgba(255,255,255,0.15); padding: 8px 16px; border-radius: 12px;">
                            <i class="far fa-calendar-alt"></i> <?php echo htmlspecialchars($current_date_display); ?>
                            <i class="far fa-clock ms-2"></i> <?php echo htmlspecialchars($current_time_display); ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Live Counters HUD -->
            <div class="row g-4 mb-4">
                <div class="col-md-6">
                    <div class="alert-card p-4 text-center">
                        <h2 class="mb-0 text-danger" id="unacknowledgedCounter">0</h2>
                        <p class="text-muted">Total Critical Alerts Unacknowledged</p>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="alert-card p-4 text-center">
                        <h2 class="mb-0 text-success" id="acknowledgedTodayCounter">0</h2>
                        <p class="text-muted">Acknowledged Today</p>
                    </div>
                </div>
            </div>

            <!-- Critical Alerts Grid -->
            <div class="alert-card">
                <div class="alert-header">
                    <h5 class="mb-0"><i class="fas fa-chart-line me-2 text-primary"></i>High‑Risk Pathology Alerts</h5>
                    <div>
                        <button class="btn btn-sm btn-outline-secondary" id="refreshAlertsBtn"><i class="fas fa-sync-alt me-1"></i>Refresh</button>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="data-table table table-hover" style="width:100%">
                        <thead>
                            <tr>
                                <th>Time / Order</th>
                                <th>Patient</th>
                                <th>Test / Result</th>
                                <th>Prescribing Doctor</th>
                                <th>SLA Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="alertsTbody">
                            <tr><td colspan="6" class="text-center py-5">Loading critical alerts...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <?php include('assets/inc/footer.php'); ?>
</div>

<div id="loadingOverlay" class="loading-overlay"><div class="spinner"></div></div>

<script>
const csrfToken = "<?php echo $csrf_token; ?>";

// Helper: show/hide loading overlay
function showLoading(show) {
    if (show) $('#loadingOverlay').addClass('active');
    else $('#loadingOverlay').removeClass('active');
}

// Load critical alerts from server
function loadCriticalAlerts() {
    showLoading(true);
    $.ajax({
        url: window.location.pathname + '?action=load_critical_grid',
        method: 'GET',
        dataType: 'json',
        cache: false,
        success: function(res) {
            showLoading(false);
            if (res.success && res.alerts) {
                // Update counters
                $('#unacknowledgedCounter').text(res.counters.total_unacknowledged);
                $('#acknowledgedTodayCounter').text(res.counters.acknowledged_today);

                // Build table rows
                let tbody = '';
                if (res.alerts.length === 0) {
                    tbody = '<tr><td colspan="6" class="text-center py-4 text-muted">No critical alerts found.</td></tr>';
                } else {
                    res.alerts.forEach(alert => {
                        const isAcknowledged = alert.critical_notified_at !== null;
                        const rowClass = isAcknowledged ? 'acknowledged-row' : 'critical-row';
                        const orderDate = new Date(alert.order_created_at).toLocaleString();
                        const resultDisplay = alert.result_value ? alert.result_value : '<em>Value not recorded</em>';
                        const doctorName = alert.doctor_name || 'Not assigned';
                        const doctorContact = alert.doctor_phone || alert.doctor_email || '—';
                        const patientName = alert.patient_name;
                        const patientMrn = alert.mr_number;

                        let slaStatusHtml = '';
                        if (isAcknowledged) {
                            const notifiedDate = new Date(alert.critical_notified_at).toLocaleString();
                            slaStatusHtml = `<span class="badge-acknowledged"><i class="fas fa-check-circle me-1"></i>Acknowledged ${notifiedDate}</span>`;
                        } else {
                            slaStatusHtml = `<button class="btn-acknowledge" data-order-id="${alert.lab_order_id}"><i class="fas fa-bell me-1"></i>Mark Notified / Acknowledge SLA</button>`;
                        }

                        const doctorPhone = alert.doctor_phone || '';
                        const doctorEmail = alert.doctor_email || '';

                        tbody += `
                            <tr class="${rowClass}" data-order-id="${alert.lab_order_id}">
                                <td>${orderDate}<br><small class="text-muted">Order #${alert.lab_order_id}</small></td>
                                <td><strong>${escapeHtml(patientName)}</strong><br><small class="text-muted">MRN: ${escapeHtml(patientMrn)}</small></td>
                                <td><strong>${escapeHtml(alert.test_name)}</strong><br><span class="text-danger blink-red">${escapeHtml(resultDisplay)}</span></td>
                                <td>${escapeHtml(doctorName)}<br><small class="text-muted">${escapeHtml(doctorContact)}</small></td>
                                <td>${slaStatusHtml}</td>
                                <td class="action-icons">
                                    <a href="tel:${escapeHtml(doctorPhone)}" class="text-decoration-none" title="Call Doctor"><i class="fas fa-phone-alt"></i></a>
                                    <a href="mailto:${escapeHtml(doctorEmail)}" class="text-decoration-none" title="Email Doctor"><i class="fas fa-envelope"></i></a>
                                    <a href="patient_profile.php?patient_id=${alert.patient_id}" class="text-decoration-none" title="View Patient"><i class="fas fa-chart-line"></i></a>
                                 </td>
                             </tr>
                        `;
                    });
                }
                $('#alertsTbody').html(tbody);

                // Rebind acknowledge buttons
                $('.btn-acknowledge').off('click').on('click', function(e) {
                    e.preventDefault();
                    const orderId = $(this).data('order-id');
                    acknowledgeSLA(orderId);
                });
            } else {
                $('#alertsTbody').html('<tr><td colspan="6" class="text-center py-4 text-danger">Failed to load alerts: ' + (res.message || 'Unknown error') + '</td></tr>');
            }
        },
        error: function(xhr, status, error) {
            showLoading(false);
            console.error('AJAX error:', status, error);
            $('#alertsTbody').html('<tr><td colspan="6" class="text-center py-4 text-danger">Network error. Please refresh the page.</td></tr>');
        }
    });
}

// Acknowledge SLA (mark as notified)
function acknowledgeSLA(orderId) {
    Swal.fire({
        title: 'Confirm Acknowledgment',
        text: 'Mark this critical result as officially notified? The timestamp will be recorded for SLA tracking.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#06d6a0',
        confirmButtonText: 'Yes, acknowledge',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            showLoading(true);
            $.ajax({
                url: window.location.pathname + '?action=acknowledge_sla',
                method: 'POST',
                dataType: 'json',
                data: {
                    order_id: orderId,
                    csrf_token: csrfToken
                },
                success: function(res) {
                    showLoading(false);
                    if (res.success) {
                        Swal.fire('Acknowledged', res.message, 'success');
                        loadCriticalAlerts(); // refresh grid
                    } else {
                        Swal.fire('Error', res.message, 'error');
                    }
                },
                error: function() {
                    showLoading(false);
                    Swal.fire('Error', 'Could not complete acknowledgment. Network issue.', 'error');
                }
            });
        }
    });
}

// Auto-refresh every 30 seconds
let refreshInterval = null;
function startAutoRefresh() {
    if (refreshInterval) clearInterval(refreshInterval);
    refreshInterval = setInterval(() => {
        loadCriticalAlerts();
    }, 30000);
}

$(document).ready(function() {
    loadCriticalAlerts();
    startAutoRefresh();
    $('#refreshAlertsBtn').on('click', function() {
        loadCriticalAlerts();
    });
});

function escapeHtml(str) {
    if (!str) return '';
    return String(str).replace(/[&<>]/g, m => m === '&' ? '&amp;' : (m === '<' ? '&lt;' : '&gt;'));
}
</script>
</body>
</html>