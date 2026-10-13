<?php
/**
 * Real‑Time Prescription Dispensation Desk
 * Pharmacist Module: Live pending prescriptions queue, dispense with atomic transactions.
 * Strictly based on hms_erp.sql schema using prescriptions, prescription_items, patients, users, patient_visits tables.
 *
 * @package HMS-ERP
 * @version 1.0
 */

if (session_status() === PHP_SESSION_NONE) session_start();

// ============================================
// 1. IRONCLAD AUTHENTICATION – PHARMACIST ONLY
// ============================================
if (!isset($_SESSION['user_id']) || !isset($_SESSION['branch_id']) || ($_SESSION['user_type'] ?? '') !== 'pharmacist') {
    // For AJAX requests, return JSON error; otherwise redirect to login
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized Access. Pharmacist permissions required.']);
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

// ============================================
// 2. ASYNCHRONOUS AJAX ROUTER PIPELINE
// ============================================
if (isset($_GET['action'])) {
    // Silent error handling for clean JSON output
    error_reporting(0);
    ini_set('display_errors', 0);
    if (ob_get_level()) ob_clean();
    header('Content-Type: application/json; charset=utf-8');

    // Re‑authenticate for every AJAX call
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['branch_id']) || ($_SESSION['user_type'] ?? '') !== 'pharmacist') {
        echo json_encode(['status' => 'error', 'message' => 'Session expired or unauthorized.']);
        exit;
    }

    $action = $_GET['action'];
    $branchId = (int)$_SESSION['branch_id'];

    // ---------- 2.1 Load Pending Prescription Queue ----------
    if ($action === 'load_pending_queue') {
        try {
            // Fetch pending prescriptions (status = 'pending_payment' or 'active') created today for this branch
            $sql = "
                SELECT 
                    p.id AS prescription_id,
                    p.prescription_number,
                    p.created_at AS order_time,
                    p.status,
                    pt.id AS patient_id,
                    CONCAT(pt.first_name, ' ', COALESCE(pt.middle_name, ''), ' ', pt.last_name) AS patient_name,
                    pt.mr_number,
                    u.id AS doctor_id,
                    CONCAT(u.first_name, ' ', COALESCE(u.middle_name, ''), ' ', u.last_name) AS doctor_name,
                    GROUP_CONCAT(
                        CONCAT(pi.medicine_name, ' (', pi.dosage, ', ', pi.frequency, 
                               IF(pi.duration_days IS NOT NULL, CONCAT(', ', pi.duration_days, ' days'), ''), ')')
                        SEPARATOR ' | '
                    ) AS medicine_details
                FROM prescriptions p
                INNER JOIN patients pt ON p.patient_id = pt.id
                LEFT JOIN users u ON p.doctor_id = u.id AND u.user_type = 'doctor'
                LEFT JOIN prescription_items pi ON p.id = pi.prescription_id AND pi.deleted_at IS NULL
                WHERE p.branch_id = :branch_id
                  AND p.status IN ('pending_payment', 'active')
                  AND DATE(p.created_at) = CURDATE()
                  AND p.deleted_at IS NULL
                GROUP BY p.id
                ORDER BY p.created_at ASC
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':branch_id' => $branchId]);
            $prescriptions = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Counters: total pending today, total dispensed today
            $countSql = "
                SELECT 
                    COUNT(CASE WHEN p.status IN ('pending_payment', 'active') THEN 1 END) AS pending_today,
                    COUNT(CASE WHEN p.status = 'dispensed' THEN 1 END) AS dispensed_today
                FROM prescriptions p
                WHERE p.branch_id = :branch_id
                  AND DATE(p.created_at) = CURDATE()
                  AND p.deleted_at IS NULL
            ";
            $countStmt = $pdo->prepare($countSql);
            $countStmt->execute([':branch_id' => $branchId]);
            $counters = $countStmt->fetch(PDO::FETCH_ASSOC);

            echo json_encode([
                'status' => 'success',
                'prescriptions' => $prescriptions,
                'counters' => [
                    'pending_today' => (int)$counters['pending_today'],
                    'dispensed_today' => (int)$counters['dispensed_today']
                ]
            ]);
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
        }
        exit;
    }

    // ---------- 2.2 Submit Dispensation (Atomic Transaction) ----------
    if ($action === 'submit_dispensation') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['status' => 'error', 'message' => 'Invalid request method.']);
            exit;
        }

        $submittedToken = $_POST['csrf_token'] ?? '';
        if (!hash_equals($csrf_token, $submittedToken)) {
            echo json_encode(['status' => 'error', 'message' => 'CSRF validation failed.']);
            exit;
        }

        $prescriptionId = (int)($_POST['prescription_id'] ?? 0);
        if ($prescriptionId <= 0) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid prescription ID.']);
            exit;
        }

        $userId = (int)$_SESSION['user_id'];
        try {
            $pdo->beginTransaction();

            // Check if prescription exists, belongs to same branch, and is still pending
            $checkSql = "SELECT id FROM prescriptions WHERE id = :id AND branch_id = :branch_id AND status IN ('pending_payment', 'active') AND deleted_at IS NULL FOR UPDATE";
            $checkStmt = $pdo->prepare($checkSql);
            $checkStmt->execute([':id' => $prescriptionId, ':branch_id' => $branchId]);
            if ($checkStmt->rowCount() === 0) {
                $pdo->rollBack();
                echo json_encode(['status' => 'error', 'message' => 'Prescription not found or already dispensed/cancelled.']);
                exit;
            }

            // Update prescription status to 'dispensed' and set dispensed timestamp & pharmacist
            $updateSql = "UPDATE prescriptions SET status = 'dispensed', dispensed_at = NOW(), dispensed_by_user_id = :user_id WHERE id = :id";
            $updateStmt = $pdo->prepare($updateSql);
            $updateStmt->execute([':user_id' => $userId, ':id' => $prescriptionId]);

            $pdo->commit();
            echo json_encode(['status' => 'success', 'message' => 'Medication dispensed successfully.']);
        } catch (PDOException $e) {
            $pdo->rollBack();
            echo json_encode(['status' => 'error', 'message' => 'Database transaction failed: ' . $e->getMessage()]);
        }
        exit;
    }

    // Invalid action
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
    <title>Prescription Dispensary | Pharmacist | HMS ERP</title>
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
        .badge-pending {
            background: #fff3e0;
            color: #e65100;
            padding: 4px 12px;
            border-radius: 30px;
            font-size: 12px;
        }
        .btn-dispense {
            background: linear-gradient(135deg, var(--success), #059669);
            color: white;
            border: none;
            border-radius: 30px;
            padding: 6px 16px;
            font-size: 13px;
            font-weight: 600;
            transition: 0.2s;
        }
        .btn-dispense:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(6,214,160,0.3);
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
        .action-icons i {
            font-size: 1.2rem;
            cursor: pointer;
            margin: 0 4px;
            color: var(--gray);
        }
        .refresh-btn {
            background: white;
            border: 1px solid var(--gray-light);
            border-radius: 40px;
            padding: 6px 16px;
            font-size: 13px;
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
                        <div class="welcome-title"><i class="fas fa-prescription-bottle me-2"></i>Real‑Time Prescription Dispensation Desk</div>
                        <div class="welcome-subtitle">Live pending orders | Instant dispensing | Secure audit trail</div>
                    </div>
                    <div class="col-md-4 text-md-end mt-3 mt-md-0">
                        <div class="time-badge" style="background: rgba(255,255,255,0.15); padding: 8px 16px; border-radius: 12px;">
                            <i class="far fa-calendar-alt"></i> <?php echo $currentDateDisplay; ?>
                            <i class="far fa-clock ms-2"></i> <?php echo $currentTimeDisplay; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Telemetry Statistics Matrix -->
            <div class="row g-4 mb-4">
                <div class="col-md-4">
                    <div class="stat-card text-center">
                        <i class="fas fa-clock fa-2x text-warning mb-2"></i>
                        <h3 class="stat-number" id="pendingCount">0</h3>
                        <p class="text-muted mb-0">Pending Orders Today</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stat-card text-center">
                        <i class="fas fa-check-circle fa-2x text-success mb-2"></i>
                        <h3 class="stat-number" id="dispensedCount">0</h3>
                        <p class="text-muted mb-0">Dispensed Orders Today</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stat-card text-center">
                        <i class="fas fa-store fa-2x text-primary mb-2"></i>
                        <h3 class="stat-number" id="branchNameDisplay"><?php echo $branchName; ?></h3>
                        <p class="text-muted mb-0">Active Branch</p>
                    </div>
                </div>
            </div>

            <!-- Pending Prescriptions Grid -->
            <div class="card shadow-sm border-0 rounded-4 mb-4">
                <div class="card-header bg-white border-0 py-3 d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="fas fa-list-alt me-2 text-primary"></i>Pending Prescriptions (Today)</h5>
                    <button class="btn refresh-btn" id="manualRefreshBtn"><i class="fas fa-sync-alt me-1"></i>Refresh</button>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover data-table mb-0" style="width:100%">
                            <thead>
                                <tr>
                                    <th>Prescription ID</th>
                                    <th>Patient</th>
                                    <th>Doctor</th>
                                    <th>Medicine Details</th>
                                    <th>Order Time</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody id="pendingQueueTbody">
                                <tr><td colspan="6" class="text-center py-5">Loading pending prescriptions...</td></tr>
                            </tbody>
                        </table>
                    </div>
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
let refreshInterval = null;

function showLoading(show) {
    if (show) $('#loadingOverlay').addClass('active');
    else $('#loadingOverlay').removeClass('active');
}

// Load pending queue via AJAX
function loadPendingQueue() {
    showLoading(true);
    $.ajax({
        url: window.location.pathname + '?action=load_pending_queue',
        method: 'GET',
        dataType: 'json',
        cache: false,
        success: function(res) {
            showLoading(false);
            if (res.status === 'success') {
                // Update counters
                $('#pendingCount').text(res.counters.pending_today);
                $('#dispensedCount').text(res.counters.dispensed_today);

                // Build table rows
                let tbody = '';
                if (res.prescriptions.length === 0) {
                    tbody = '<tr><td colspan="6" class="text-center py-4 text-muted">No pending prescriptions for today.</td></tr>';
                } else {
                    res.prescriptions.forEach(function(prescription) {
                        const orderTime = new Date(prescription.order_time).toLocaleTimeString();
                        const doctorName = prescription.doctor_name || 'Not assigned';
                        const medicineDetails = prescription.medicine_details || '<em>No items</em>';
                        tbody += `
                            <tr data-prescription-id="${prescription.prescription_id}">
                                <td><strong>${escapeHtml(prescription.prescription_number)}</strong><br><small class="text-muted">ID: ${prescription.prescription_id}</small></td>
                                <td>${escapeHtml(prescription.patient_name)}<br><small class="text-muted">MRN: ${escapeHtml(prescription.mr_number)}</small></td>
                                <td>${escapeHtml(doctorName)}</td>
                                <td>${escapeHtml(medicineDetails)}</td>
                                <td>${orderTime}</td>
                                <td><button class="btn-dispense" data-prescription-id="${prescription.prescription_id}"><i class="fas fa-check-circle me-1"></i>Confirm & Dispense</button></td>
                            </tr>
                        `;
                    });
                }
                $('#pendingQueueTbody').html(tbody);

                // Bind dispense buttons
                $('.btn-dispense').off('click').on('click', function() {
                    const prescriptionId = $(this).data('prescription-id');
                    confirmDispense(prescriptionId);
                });
            } else {
                $('#pendingQueueTbody').html('<tr><td colspan="6" class="text-center py-4 text-danger">Error loading queue: ' + (res.message || 'Unknown') + '</td></tr>');
            }
        },
        error: function(xhr, status, error) {
            showLoading(false);
            console.error('AJAX error:', error);
            $('#pendingQueueTbody').html('<tr><td colspan="6" class="text-center py-4 text-danger">Network error. Please refresh the page.</td></tr>');
        }
    });
}

// Confirm and process dispensation
function confirmDispense(prescriptionId) {
    Swal.fire({
        title: 'Confirm Dispensation',
        text: 'Have you verified the prescription and prepared the medication? This action cannot be undone.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#06d6a0',
        confirmButtonText: 'Yes, dispense now',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            showLoading(true);
            $.ajax({
                url: window.location.pathname + '?action=submit_dispensation',
                method: 'POST',
                dataType: 'json',
                data: {
                    prescription_id: prescriptionId,
                    csrf_token: csrfToken
                },
                success: function(res) {
                    showLoading(false);
                    if (res.status === 'success') {
                        Swal.fire('Dispensed', res.message, 'success');
                        loadPendingQueue(); // refresh grid and counters
                    } else {
                        Swal.fire('Error', res.message, 'error');
                    }
                },
                error: function() {
                    showLoading(false);
                    Swal.fire('Error', 'Could not complete dispensation. Network issue.', 'error');
                }
            });
        }
    });
}

// Auto-refresh every 30 seconds
function startAutoRefresh() {
    if (refreshInterval) clearInterval(refreshInterval);
    refreshInterval = setInterval(function() {
        loadPendingQueue();
    }, 30000);
}

$(document).ready(function() {
    loadPendingQueue();
    startAutoRefresh();
    $('#manualRefreshBtn').on('click', function() {
        loadPendingQueue();
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