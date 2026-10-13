<?php
/**
 * Integrated Pharmacy POS & Retail Billing Engine
 * Pharmacist Module: Pre-dispensation payment verification, insurance co-pay calculator.
 * Strictly based on hms_erp.sql schema using invoices, invoice_items, prescriptions, insurance_policies.
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
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized Access. Strict Pharmacist Permissions Required.']);
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

    // ---------- 2.1 Fetch Billing Queue (Pending Prescriptions with Invoice Status) ----------
    if ($action === 'fetch_billing_queue') {
        try {
            $sql = "
                SELECT 
                    p.id AS prescription_id,
                    p.prescription_number,
                    p.created_at,
                    p.status AS prescription_status,
                    pt.id AS patient_id,
                    CONCAT(pt.first_name, ' ', COALESCE(pt.middle_name, ''), ' ', pt.last_name) AS patient_name,
                    pt.mr_number,
                    i.id AS invoice_id,
                    i.invoice_number,
                    i.total_amount,
                    i.discount_amount,
                    i.total_amount - i.discount_amount AS net_amount,
                    i.payment_status,
                    i.billing_type,
                    GROUP_CONCAT(
                        CONCAT(pi.medicine_name, ' (x', pi.quantity, ')') SEPARATOR ', '
                    ) AS medicines
                FROM prescriptions p
                INNER JOIN patient_visits pv ON p.visit_id = pv.id
                INNER JOIN patients pt ON p.patient_id = pt.id
                LEFT JOIN invoices i ON pv.id = i.visit_id AND i.deleted_at IS NULL
                LEFT JOIN prescription_items pi ON p.id = pi.prescription_id AND pi.deleted_at IS NULL
                WHERE pv.branch_id = :branch_id
                  AND p.deleted_at IS NULL
                  AND p.status IN ('pending_payment', 'active')
                  AND (i.deleted_at IS NULL OR i.id IS NULL)
                GROUP BY p.id
                ORDER BY p.created_at ASC
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':branch_id' => $branchId]);
            $pendingItems = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Counters
            $today = date('Y-m-d');
            $clearedTodaySql = "
                SELECT COUNT(DISTINCT i.id) AS total
                FROM invoices i
                WHERE i.branch_id = :branch_id
                  AND DATE(i.created_at) = :today
                  AND i.payment_status = 'paid'
                  AND i.deleted_at IS NULL
            ";
            $clearedStmt = $pdo->prepare($clearedTodaySql);
            $clearedStmt->execute([':branch_id' => $branchId, ':today' => $today]);
            $clearedToday = (int)$clearedStmt->fetchColumn();

            $pendingUnpaidSql = "
                SELECT COUNT(DISTINCT p.id) AS total
                FROM prescriptions p
                INNER JOIN patient_visits pv ON p.visit_id = pv.id
                LEFT JOIN invoices i ON pv.id = i.visit_id AND i.deleted_at IS NULL
                WHERE pv.branch_id = :branch_id
                  AND p.deleted_at IS NULL
                  AND p.status IN ('pending_payment', 'active')
                  AND (i.id IS NULL OR i.payment_status != 'paid')
            ";
            $unpaidStmt = $pdo->prepare($pendingUnpaidSql);
            $unpaidStmt->execute([':branch_id' => $branchId]);
            $pendingUnpaid = (int)$unpaidStmt->fetchColumn();

            $insuranceClaimsTodaySql = "
                SELECT COUNT(DISTINCT i.id) AS total
                FROM invoices i
                WHERE i.branch_id = :branch_id
                  AND DATE(i.created_at) = :today
                  AND i.billing_type = 'insurance'
                  AND i.deleted_at IS NULL
            ";
            $claimsStmt = $pdo->prepare($insuranceClaimsTodaySql);
            $claimsStmt->execute([':branch_id' => $branchId, ':today' => $today]);
            $insuranceClaimsToday = (int)$claimsStmt->fetchColumn();

            echo json_encode([
                'status' => 'success',
                'queue' => $pendingItems,
                'counters' => [
                    'cleared_invoices_today' => $clearedToday,
                    'pending_unpaid_blocks' => $pendingUnpaid,
                    'insurance_claims_processed' => $insuranceClaimsToday
                ]
            ]);
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
        }
        exit;
    }

    // ---------- 2.2 Calculate Insurance Split for a Patient ----------
    if ($action === 'calculate_insurance_split') {
        $patientId = (int)($_GET['patient_id'] ?? 0);
        $netAmount = (float)($_GET['net_amount'] ?? 0);

        if ($patientId <= 0 || $netAmount <= 0) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid patient ID or net amount.']);
            exit;
        }

        try {
            // Fetch active insurance policy for the patient
            $policySql = "
                SELECT 
                    coverage_percentage,
                    insurance_provider_name,
                    policy_number
                FROM insurance_policies
                WHERE patient_id = :patient_id
                  AND status = 'active'
                  AND valid_until >= CURDATE()
                  AND deleted_at IS NULL
                ORDER BY id DESC
                LIMIT 1
            ";
            $policyStmt = $pdo->prepare($policySql);
            $policyStmt->execute([':patient_id' => $patientId]);
            $policy = $policyStmt->fetch(PDO::FETCH_ASSOC);

            if ($policy) {
                $coveragePercent = (float)$policy['coverage_percentage'];
                $coPayPercent = 100 - $coveragePercent;
                $insuranceCoverage = round($netAmount * ($coveragePercent / 100), 2);
                $patientCoPay = round($netAmount * ($coPayPercent / 100), 2);
                echo json_encode([
                    'status' => 'success',
                    'has_insurance' => true,
                    'provider_name' => $policy['insurance_provider_name'],
                    'policy_number' => $policy['policy_number'],
                    'coverage_percentage' => $coveragePercent,
                    'co_pay_percentage' => $coPayPercent,
                    'net_amount' => $netAmount,
                    'insurance_coverage' => $insuranceCoverage,
                    'patient_cash_due' => $patientCoPay
                ]);
            } else {
                echo json_encode([
                    'status' => 'success',
                    'has_insurance' => false,
                    'message' => 'No active insurance policy found. Patient is responsible for full amount.',
                    'patient_cash_due' => $netAmount,
                    'net_amount' => $netAmount
                ]);
            }
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
        }
        exit;
    }

    // ---------- 2.3 Dispense Prescription (only if invoice paid) ----------
    if ($action === 'dispense_prescription') {
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

        try {
            $pdo->beginTransaction();

            // Verify prescription belongs to branch and check invoice payment status
            $verifySql = "
                SELECT p.id, p.status, i.payment_status, i.id AS invoice_id
                FROM prescriptions p
                INNER JOIN patient_visits pv ON p.visit_id = pv.id
                LEFT JOIN invoices i ON pv.id = i.visit_id AND i.deleted_at IS NULL
                WHERE p.id = :prescription_id
                  AND pv.branch_id = :branch_id
                  AND p.deleted_at IS NULL
                FOR UPDATE
            ";
            $verifyStmt = $pdo->prepare($verifySql);
            $verifyStmt->execute([':prescription_id' => $prescriptionId, ':branch_id' => $branchId]);
            $data = $verifyStmt->fetch(PDO::FETCH_ASSOC);

            if (!$data) {
                $pdo->rollBack();
                echo json_encode(['status' => 'error', 'message' => 'Prescription not found.']);
                exit;
            }

            if ($data['status'] === 'dispensed') {
                $pdo->rollBack();
                echo json_encode(['status' => 'error', 'message' => 'Prescription already dispensed.']);
                exit;
            }

            // Strict verification: invoice must exist and be paid
            if (!$data['invoice_id'] || $data['payment_status'] !== 'paid') {
                $pdo->rollBack();
                echo json_encode(['status' => 'error', 'message' => 'Dispensation blocked: Invoice is not paid or does not exist.']);
                exit;
            }

            // Update prescription status
            $updateSql = "UPDATE prescriptions SET status = 'dispensed', dispensed_at = NOW(), dispensed_by_user_id = :user_id WHERE id = :id";
            $updateStmt = $pdo->prepare($updateSql);
            $updateStmt->execute([':user_id' => $_SESSION['user_id'], ':id' => $prescriptionId]);

            $pdo->commit();
            echo json_encode(['status' => 'success', 'message' => 'Prescription dispensed successfully.']);
        } catch (PDOException $e) {
            $pdo->rollBack();
            echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
        }
        exit;
    }

    echo json_encode(['status' => 'error', 'message' => 'Invalid action.']);
    exit;
}

// ============================================
// 3. INITIAL PAGE LOAD – BRANCH NAME
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
    <title>Pharmacy POS & Billing Engine | Pharmacist | HMS ERP</title>
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
            font-size: 2.2rem;
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
        .badge-paid {
            background: #e8f5e9;
            color: #2e7d32;
            padding: 4px 12px;
            border-radius: 30px;
            font-size: 12px;
            font-weight: 600;
        }
        .badge-unpaid {
            background: #ffebee;
            color: #c62828;
            padding: 4px 12px;
            border-radius: 30px;
            font-size: 12px;
            font-weight: 600;
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
        .btn-dispense:disabled {
            background: #cccccc;
            cursor: not-allowed;
        }
        .btn-dispense:hover:not(:disabled) {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(6,214,160,0.3);
        }
        .btn-calc {
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
        .insurance-breakdown {
            font-size: 14px;
            line-height: 1.6;
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
                        <div class="welcome-title"><i class="fas fa-cash-register me-2"></i>Integrated Pharmacy POS & Retail Billing Engine</div>
                        <div class="welcome-subtitle">Pre‑dispensation payment verification | Insurance co‑pay calculator | Real‑time clearance HUD</div>
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
                <div class="col-md-3 col-sm-6">
                    <div class="stat-card text-center">
                        <i class="fas fa-check-circle fa-2x text-success mb-2"></i>
                        <h3 class="stat-number" id="clearedCount">0</h3>
                        <p class="text-muted mb-0">Cleared Invoices Today</p>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="stat-card text-center">
                        <i class="fas fa-hourglass-half fa-2x text-warning mb-2"></i>
                        <h3 class="stat-number" id="pendingCount">0</h3>
                        <p class="text-muted mb-0">Pending Unpaid Blocks</p>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="stat-card text-center">
                        <i class="fas fa-file-invoice-dollar fa-2x text-info mb-2"></i>
                        <h3 class="stat-number" id="insuranceClaimsCount">0</h3>
                        <p class="text-muted mb-0">Insurance Claims Processed</p>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="stat-card text-center">
                        <i class="fas fa-store fa-2x text-primary mb-2"></i>
                        <h3 class="stat-number"><?php echo $branchName; ?></h3>
                        <p class="text-muted mb-0">Active Branch</p>
                    </div>
                </div>
            </div>

            <!-- Pending Prescriptions Grid -->
            <div class="card shadow-sm border-0 rounded-4 mb-4">
                <div class="card-header bg-white border-0 py-3 d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="fas fa-prescription-bottle me-2 text-primary"></i>Pre‑Dispensation Payment Gateway</h5>
                    <button class="btn refresh-btn" id="manualRefreshBtn"><i class="fas fa-sync-alt me-1"></i> Refresh Queue</button>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover data-table mb-0" style="width:100%">
                            <thead>
                                <tr>
                                    <th>Patient</th>
                                    <th>Prescription #</th>
                                    <th>Medicines</th>
                                    <th>Invoice #</th>
                                    <th>Net Amount (ETB)</th>
                                    <th>Payment Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="queueTbody">
                                <tr><td colspan="7" class="text-center py-5">Loading billing queue...</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php include('assets/inc/footer.php'); ?>
</div>

<!-- Insurance Split Modal -->
<div class="modal fade" id="insuranceModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Insurance Co‑Pay Breakdown</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="insuranceModalBody">
                <div class="text-center">Loading insurance details...</div>
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
let refreshInterval = null;

function showLoading(show) {
    if (show) $('#loadingOverlay').addClass('active');
    else $('#loadingOverlay').removeClass('active');
}

function loadBillingQueue() {
    showLoading(true);
    $.ajax({
        url: window.location.pathname + '?action=fetch_billing_queue',
        method: 'GET',
        dataType: 'json',
        cache: false,
        success: function(res) {
            showLoading(false);
            if (res.status === 'success') {
                // Update counters
                $('#clearedCount').text(res.counters.cleared_invoices_today);
                $('#pendingCount').text(res.counters.pending_unpaid_blocks);
                $('#insuranceClaimsCount').text(res.counters.insurance_claims_processed);

                let tbody = '';
                if (res.queue.length === 0) {
                    tbody = '<tr><td colspan="7" class="text-center py-4 text-muted">No pending prescriptions requiring billing clearance.</td></tr>';
                } else {
                    res.queue.forEach(function(item) {
                        const isPaid = (item.payment_status === 'paid');
                        const badgeClass = isPaid ? 'badge-paid' : 'badge-unpaid';
                        const badgeText = isPaid ? 'PAID - Clear to Dispense' : 'UNPAID - Lock Dispensation';
                        const netAmount = parseFloat(item.net_amount || 0).toFixed(2);
                        const medicinesDisplay = item.medicines ? item.medicines.substring(0, 100) : '—';
                        const invoiceDisplay = item.invoice_number ? item.invoice_number : '—';
                        const prescriptionNumber = item.prescription_number ? item.prescription_number : `RX-${item.prescription_id}`;

                        tbody += `
                            <tr data-prescription-id="${item.prescription_id}" data-patient-id="${item.patient_id}" data-net-amount="${netAmount}">
                                <td><strong>${escapeHtml(item.patient_name)}</strong><br><small class="text-muted">MRN: ${escapeHtml(item.mr_number)}</small></td>
                                <td>${escapeHtml(prescriptionNumber)}</td>
                                <td><small>${escapeHtml(medicinesDisplay)}</small></td>
                                <td>${escapeHtml(invoiceDisplay)}</td>
                                <td class="text-end">${netAmount}</td>
                                <td><span class="${badgeClass}">${badgeText}</span></td>
                                <td>
                                    <button class="btn btn-sm btn-calc insurance-split-btn me-1" data-patient-id="${item.patient_id}" data-net-amount="${netAmount}" data-patient-name="${escapeHtml(item.patient_name)}"><i class="fas fa-calculator"></i> Co‑Pay Split</button>
                                    ${isPaid ? `<button class="btn-dispense" data-prescription-id="${item.prescription_id}"><i class="fas fa-check-circle me-1"></i>Dispense</button>` : '<button class="btn-dispense" disabled style="background:#cccccc;"><i class="fas fa-lock me-1"></i>Locked</button>'}
                                </td>
                            </tr>
                        `;
                    });
                }
                $('#queueTbody').html(tbody);

                // Bind insurance split buttons
                $('.insurance-split-btn').off('click').on('click', function() {
                    const patientId = $(this).data('patient-id');
                    const netAmount = $(this).data('net-amount');
                    const patientName = $(this).data('patient-name');
                    showInsuranceSplit(patientId, netAmount, patientName);
                });

                // Bind dispense buttons
                $('.btn-dispense').off('click').on('click', function() {
                    const prescriptionId = $(this).data('prescription-id');
                    dispensePrescription(prescriptionId);
                });
            } else {
                $('#queueTbody').html('<tr><td colspan="7" class="text-center py-4 text-danger">Error: ' + (res.message || 'Unknown') + '</td></tr>');
            }
        },
        error: function(xhr, status, error) {
            showLoading(false);
            console.error('AJAX error:', error);
            $('#queueTbody').html('<tr><td colspan="7" class="text-center py-4 text-danger">Network error. Please refresh the page.</td></tr>');
        }
    });
}

function showInsuranceSplit(patientId, netAmount, patientName) {
    $('#insuranceModalBody').html('<div class="text-center"><i class="fas fa-spinner fa-spin"></i> Loading insurance details...</div>');
    $.ajax({
        url: window.location.pathname + '?action=calculate_insurance_split',
        method: 'GET',
        dataType: 'json',
        data: { patient_id: patientId, net_amount: netAmount },
        success: function(res) {
            if (res.status === 'success') {
                let html = `<div class="insurance-breakdown">`;
                html += `<p><strong>Patient:</strong> ${escapeHtml(patientName)}</p>`;
                html += `<p><strong>Invoice Net Amount:</strong> ${parseFloat(res.net_amount).toFixed(2)} ETB</p>`;
                if (res.has_insurance) {
                    html += `<hr>`;
                    html += `<p><strong>Insurance Provider:</strong> ${escapeHtml(res.provider_name)}</p>`;
                    html += `<p><strong>Policy Number:</strong> ${escapeHtml(res.policy_number)}</p>`;
                    html += `<p><strong>Coverage Percentage:</strong> ${res.coverage_percentage}%</p>`;
                    html += `<p><strong>Co‑Pay Percentage (Patient):</strong> ${res.co_pay_percentage}%</p>`;
                    html += `<hr>`;
                    html += `<p><strong>Insurance Coverage Share:</strong> <span class="text-success">${res.insurance_coverage} ETB</span></p>`;
                    html += `<p><strong>Patient Co‑Pay Cash Due:</strong> <span class="text-danger fw-bold">${res.patient_cash_due} ETB</span></p>`;
                } else {
                    html += `<div class="alert alert-warning mt-2">${escapeHtml(res.message)}</div>`;
                    html += `<p><strong>Total Patient Responsibility:</strong> ${res.patient_cash_due} ETB</p>`;
                }
                html += `</div>`;
                $('#insuranceModalBody').html(html);
                $('#insuranceModal').modal('show');
            } else {
                Swal.fire('Error', res.message, 'error');
            }
        },
        error: function() { Swal.fire('Error', 'Could not fetch insurance details.', 'error'); }
    });
}

function dispensePrescription(prescriptionId) {
    Swal.fire({
        title: 'Confirm Dispensation',
        text: 'Verify that the patient has paid and the invoice is cleared. This action will mark the prescription as DISPENSED.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#06d6a0',
        confirmButtonText: 'Yes, Dispense Now',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            showLoading(true);
            $.ajax({
                url: window.location.pathname + '?action=dispense_prescription',
                method: 'POST',
                dataType: 'json',
                data: { prescription_id: prescriptionId, csrf_token: csrfToken },
                success: function(res) {
                    showLoading(false);
                    if (res.status === 'success') {
                        Swal.fire('Dispensed', res.message, 'success');
                        loadBillingQueue(); // refresh grid
                    } else {
                        Swal.fire('Error', res.message, 'error');
                    }
                },
                error: function() { showLoading(false); Swal.fire('Error', 'Network error.', 'error'); }
            });
        }
    });
}

// Auto-refresh every 30 seconds
function startAutoRefresh() {
    if (refreshInterval) clearInterval(refreshInterval);
    refreshInterval = setInterval(function() {
        loadBillingQueue();
    }, 30000);
}

$(document).ready(function() {
    loadBillingQueue();
    startAutoRefresh();
    $('#manualRefreshBtn').on('click', function() {
        loadBillingQueue();
        Swal.fire('Refreshed', 'Billing queue updated.', 'success');
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