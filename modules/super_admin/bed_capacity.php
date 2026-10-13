<?php
/**
 * Bed & Ward Capacity Engine
 * Super Admin Module: Real‑time bed occupancy, admission mapping, maintenance lockout.
 * Strictly based on hms_erp.sql schema using wards, beds, admissions, branches tables.
 *
 * @package HMS-ERP
 * @version 1.0
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

    $action = $_GET['action'];

    // ---------- 2.1 Get Capacity Matrix (Ward occupancy + counters) ----------
    if ($action === 'get_capacity_matrix') {
        try {
            // Aggregate bed counts per ward
            $wardSql = "
                SELECT 
                    w.id AS ward_id,
                    w.name AS ward_name,
                    w.ward_type,
                    COUNT(b.id) AS total_beds,
                    SUM(CASE WHEN b.status = 'occupied' THEN 1 ELSE 0 END) AS occupied_beds,
                    SUM(CASE WHEN b.status = 'available' THEN 1 ELSE 0 END) AS available_beds,
                    SUM(CASE WHEN b.status = 'maintenance' THEN 1 ELSE 0 END) AS maintenance_beds
                FROM wards w
                LEFT JOIN beds b ON w.id = b.ward_id AND b.deleted_at IS NULL
                WHERE w.deleted_at IS NULL
                GROUP BY w.id
                ORDER BY w.name
            ";
            $stmt = $pdo->prepare($wardSql);
            $stmt->execute();
            $wardsData = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Global counters (across all branches)
            $globalSql = "
                SELECT 
                    COUNT(*) AS total_beds,
                    SUM(CASE WHEN status = 'occupied' THEN 1 ELSE 0 END) AS occupied,
                    SUM(CASE WHEN status = 'available' THEN 1 ELSE 0 END) AS available,
                    SUM(CASE WHEN status = 'maintenance' THEN 1 ELSE 0 END) AS maintenance
                FROM beds
                WHERE deleted_at IS NULL
            ";
            $globalStmt = $pdo->prepare($globalSql);
            $globalStmt->execute();
            $globalCounters = $globalStmt->fetch(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'wards' => $wardsData,
                'counters' => [
                    'total_beds' => (int)$globalCounters['total_beds'],
                    'occupied' => (int)$globalCounters['occupied'],
                    'available' => (int)$globalCounters['available'],
                    'maintenance' => (int)$globalCounters['maintenance']
                ]
            ]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        }
        exit;
    }

    // ---------- 2.2 Load Detailed Bed Grid (with ward, status, current patient if occupied) ----------
    if ($action === 'load_bed_grid') {
        try {
            $sql = "
                SELECT 
                    b.id AS bed_id,
                    b.bed_number,
                    b.status,
                    b.daily_rate,
                    w.name AS ward_name,
                    w.ward_type,
                    br.name AS branch_name,
                    a.id AS admission_id,
                    CONCAT(p.first_name, ' ', COALESCE(p.middle_name, ''), ' ', p.last_name) AS patient_name,
                    p.mr_number
                FROM beds b
                INNER JOIN wards w ON b.ward_id = w.id
                INNER JOIN branches br ON b.branch_id = br.id
                LEFT JOIN admissions a ON a.bed_id = b.id AND a.status = 'admitted' AND a.deleted_at IS NULL
                LEFT JOIN patients p ON a.patient_id = p.id
                WHERE b.deleted_at IS NULL
                ORDER BY br.name, w.name, b.bed_number
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute();
            $beds = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'beds' => $beds]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        }
        exit;
    }

    // ---------- 2.3 Toggle Bed Maintenance (Admin Quarantine) ----------
    if ($action === 'toggle_bed_maintenance') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
            exit;
        }
        $submittedToken = $_POST['csrf_token'] ?? '';
        if (!hash_equals($csrf_token, $submittedToken)) {
            echo json_encode(['success' => false, 'message' => 'CSRF validation failed.']);
            exit;
        }

        $bedId = (int)($_POST['bed_id'] ?? 0);
        $newStatus = $_POST['status'] ?? ''; // expected 'maintenance' or 'available'

        if ($bedId <= 0 || !in_array($newStatus, ['maintenance', 'available'])) {
            echo json_encode(['success' => false, 'message' => 'Invalid parameters.']);
            exit;
        }

        try {
            // Prevent setting a bed to 'available' if it is currently occupied (safety)
            if ($newStatus === 'available') {
                $check = $pdo->prepare("SELECT status FROM beds WHERE id = :id AND deleted_at IS NULL");
                $check->execute([':id' => $bedId]);
                $current = $check->fetchColumn();
                if ($current === 'occupied') {
                    echo json_encode(['success' => false, 'message' => 'Cannot set an occupied bed to available. Discharge patient first.']);
                    exit;
                }
            }

            $update = $pdo->prepare("UPDATE beds SET status = :status WHERE id = :id AND deleted_at IS NULL");
            $update->execute([':status' => $newStatus, ':id' => $bedId]);
            if ($update->rowCount() > 0) {
                echo json_encode(['success' => true, 'message' => 'Bed status updated successfully.']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Bed not found or status unchanged.']);
            }
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        }
        exit;
    }

    // ---------- 2.4 Assign Bed to an Admitted Patient (Admission Mapping) ----------
    if ($action === 'assign_bed') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
            exit;
        }
        $submittedToken = $_POST['csrf_token'] ?? '';
        if (!hash_equals($csrf_token, $submittedToken)) {
            echo json_encode(['success' => false, 'message' => 'CSRF validation failed.']);
            exit;
        }

        $admissionId = (int)($_POST['admission_id'] ?? 0);
        $bedId = (int)($_POST['bed_id'] ?? 0);

        if ($admissionId <= 0 || $bedId <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid admission or bed ID.']);
            exit;
        }

        try {
            // Begin transaction
            $pdo->beginTransaction();

            // Check if bed is available and not occupied/maintenance
            $bedCheck = $pdo->prepare("SELECT status FROM beds WHERE id = :bed_id AND deleted_at IS NULL FOR UPDATE");
            $bedCheck->execute([':bed_id' => $bedId]);
            $bedStatus = $bedCheck->fetchColumn();
            if ($bedStatus !== 'available') {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'message' => 'Selected bed is not available for assignment.']);
                exit;
            }

            // Update admission with bed_id
            $updateAdmission = $pdo->prepare("UPDATE admissions SET bed_id = :bed_id WHERE id = :id AND status = 'admitted' AND deleted_at IS NULL");
            $updateAdmission->execute([':bed_id' => $bedId, ':id' => $admissionId]);
            if ($updateAdmission->rowCount() === 0) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'message' => 'Admission record not found or already discharged.']);
                exit;
            }

            // Update bed status to occupied
            $updateBed = $pdo->prepare("UPDATE beds SET status = 'occupied' WHERE id = :bed_id");
            $updateBed->execute([':bed_id' => $bedId]);

            $pdo->commit();
            echo json_encode(['success' => true, 'message' => 'Patient successfully assigned to bed.']);
        } catch (PDOException $e) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        }
        exit;
    }

    // ---------- 2.5 Discharge Patient (Restore Bed Availability) ----------
    if ($action === 'discharge_patient') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
            exit;
        }
        $submittedToken = $_POST['csrf_token'] ?? '';
        if (!hash_equals($csrf_token, $submittedToken)) {
            echo json_encode(['success' => false, 'message' => 'CSRF validation failed.']);
            exit;
        }

        $admissionId = (int)($_POST['admission_id'] ?? 0);
        if ($admissionId <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid admission ID.']);
            exit;
        }

        try {
            $pdo->beginTransaction();

            // Get bed_id from admission before discharging
            $getBed = $pdo->prepare("SELECT bed_id FROM admissions WHERE id = :id AND status = 'admitted' AND deleted_at IS NULL FOR UPDATE");
            $getBed->execute([':id' => $admissionId]);
            $bedId = $getBed->fetchColumn();

            if (!$bedId) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'message' => 'Admission record not found or already discharged.']);
                exit;
            }

            // Update admission status to discharged and set discharged_at
            $discharge = $pdo->prepare("UPDATE admissions SET status = 'discharged', discharged_at = NOW() WHERE id = :id");
            $discharge->execute([':id' => $admissionId]);

            // Reset bed status to available
            $resetBed = $pdo->prepare("UPDATE beds SET status = 'available' WHERE id = :bed_id");
            $resetBed->execute([':bed_id' => $bedId]);

            $pdo->commit();
            echo json_encode(['success' => true, 'message' => 'Patient discharged and bed freed.']);
        } catch (PDOException $e) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        }
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Invalid action.']);
    exit;
}

// ============================================
// 3. INITIAL PAGE LOAD – HTML STRUCTURE
// ============================================
$current_date_display = date('l, F j, Y');
$current_time_display = date('g:i A');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bed & Ward Capacity Engine | Super Admin | HMS ERP</title>
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
        .chart-container {
            background: white;
            border-radius: 24px;
            padding: 20px;
            margin-bottom: 28px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
        }
        .data-table th {
            font-size: 13px;
            font-weight: 600;
            background: #f8fafc;
        }
        .bed-status-badge {
            padding: 4px 10px;
            border-radius: 30px;
            font-size: 12px;
            font-weight: 600;
        }
        .badge-available { background: #e8f5e9; color: #2e7d32; }
        .badge-occupied { background: #ffebee; color: #c62828; }
        .badge-maintenance { background: #fff3e0; color: #e65100; }
        .btn-maintenance { background: #ff9800; color: white; border: none; border-radius: 20px; padding: 4px 12px; font-size: 12px; }
        .btn-restore { background: #4caf50; color: white; border: none; border-radius: 20px; padding: 4px 12px; font-size: 12px; }
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
            transition: 0.2s;
        }
        .action-icons i:hover { color: var(--primary); }
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
                        <div class="welcome-title"><i class="fas fa-bed me-2"></i>Bed & Ward Capacity Engine</div>
                        <div class="welcome-subtitle">Real‑time occupancy matrix, admission mapping, and biomedical maintenance controls.</div>
                    </div>
                    <div class="col-md-4 text-md-end mt-3 mt-md-0">
                        <div class="time-badge" style="background: rgba(255,255,255,0.15); padding: 8px 16px; border-radius: 12px;">
                            <i class="far fa-calendar-alt"></i> <?php echo htmlspecialchars($current_date_display); ?>
                            <i class="far fa-clock ms-2"></i> <?php echo htmlspecialchars($current_time_display); ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Live Telemetry Counters -->
            <div class="row g-4 mb-4">
                <div class="col-md-3 col-sm-6">
                    <div class="stat-card text-center">
                        <i class="fas fa-hotel fa-2x text-primary mb-2"></i>
                        <h3 class="stat-number" id="totalBeds">0</h3>
                        <p class="text-muted mb-0">Total Beds</p>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="stat-card text-center">
                        <i class="fas fa-user-injured fa-2x text-danger mb-2"></i>
                        <h3 class="stat-number" id="occupiedBeds">0</h3>
                        <p class="text-muted mb-0">Occupied</p>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="stat-card text-center">
                        <i class="fas fa-bed fa-2x text-success mb-2"></i>
                        <h3 class="stat-number" id="availableBeds">0</h3>
                        <p class="text-muted mb-0">Available</p>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="stat-card text-center">
                        <i class="fas fa-tools fa-2x text-warning mb-2"></i>
                        <h3 class="stat-number" id="maintenanceBeds">0</h3>
                        <p class="text-muted mb-0">Out of Service</p>
                    </div>
                </div>
            </div>

            <!-- Ward Capacity Chart (Stacked Horizontal Bar) -->
            <div class="chart-container">
                <h5 class="mb-3"><i class="fas fa-chart-simple me-2 text-primary"></i>Bed Occupancy by Ward (Occupied / Available / Maintenance)</h5>
                <canvas id="wardCapacityChart" height="300"></canvas>
            </div>

            <!-- Detailed Bed Grid -->
            <div class="card shadow-sm border-0 rounded-4 mb-4">
                <div class="card-header bg-white border-0 py-3 d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="fas fa-table-list me-2 text-primary"></i>Bed Inventory & Status Matrix</h5>
                    <button class="btn btn-sm btn-outline-secondary" id="refreshGridBtn"><i class="fas fa-sync-alt me-1"></i>Refresh</button>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover data-table mb-0" style="width:100%">
                            <thead>
                                <tr>
                                    <th>Branch</th>
                                    <th>Ward</th>
                                    <th>Bed Number</th>
                                    <th>Daily Rate (ETB)</th>
                                    <th>Status</th>
                                    <th>Current Patient</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="bedGridTbody">
                                <tr><td colspan="7" class="text-center py-5">Loading bed data...</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Active Admissions Table (for manual assignment / discharge) -->
            <div class="card shadow-sm border-0 rounded-4">
                <div class="card-header bg-white border-0 py-3">
                    <h5 class="mb-0"><i class="fas fa-procedures me-2 text-primary"></i>Active Admissions (Inpatients)</h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover data-table mb-0" style="width:100%">
                            <thead>
                                <tr>
                                    <th>Patient Name</th>
                                    <th>MRN</th>
                                    <th>Admitted Since</th>
                                    <th>Assigned Bed</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="activeAdmissionsTbody">
                                <tr><td colspan="5" class="text-center py-5">Loading active admissions...</td></tr>
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

<!-- Assign Bed Modal -->
<div class="modal fade" id="assignBedModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Assign Bed to Patient</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="assign_admission_id">
                <div class="mb-3">
                    <label class="form-label">Select Available Bed</label>
                    <select id="available_bed_select" class="form-select">
                        <option value="">Loading beds...</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="confirmAssignBed">Assign Bed</button>
            </div>
        </div>
    </div>
</div>

<script>
const csrfToken = "<?php echo $csrf_token; ?>";
let wardChart = null;
let refreshInterval = null;

function showLoading(show) {
    if (show) $('#loadingOverlay').addClass('active');
    else $('#loadingOverlay').removeClass('active');
}

// Load capacity matrix (counters + chart data)
function loadCapacityMatrix() {
    $.ajax({
        url: window.location.pathname + '?action=get_capacity_matrix',
        method: 'GET',
        dataType: 'json',
        cache: false,
        success: function(res) {
            if (res.success) {
                // Update counters
                $('#totalBeds').text(res.counters.total_beds);
                $('#occupiedBeds').text(res.counters.occupied);
                $('#availableBeds').text(res.counters.available);
                $('#maintenanceBeds').text(res.counters.maintenance);

                // Prepare chart datasets (stacked horizontal bar)
                const wardNames = res.wards.map(w => w.ward_name);
                const occupiedData = res.wards.map(w => w.occupied_beds);
                const availableData = res.wards.map(w => w.available_beds);
                const maintenanceData = res.wards.map(w => w.maintenance_beds);

                if (wardChart) wardChart.destroy();
                const ctx = document.getElementById('wardCapacityChart').getContext('2d');
                wardChart = new Chart(ctx, {
                    type: 'bar',
                    data: {
                        labels: wardNames,
                        datasets: [
                            { label: 'Occupied', data: occupiedData, backgroundColor: '#ef476f', borderRadius: 4 },
                            { label: 'Available', data: availableData, backgroundColor: '#06d6a0', borderRadius: 4 },
                            { label: 'Maintenance', data: maintenanceData, backgroundColor: '#f9c74f', borderRadius: 4 }
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: true,
                        indexAxis: 'y',
                        scales: { x: { stacked: true, title: { display: true, text: 'Number of Beds' } }, y: { stacked: true } }
                    }
                });
            }
        },
        error: function() { console.error('Failed to load capacity matrix'); }
    });
}

// Load bed grid and active admissions
function loadBedGridAndAdmissions() {
    $.ajax({
        url: window.location.pathname + '?action=load_bed_grid',
        method: 'GET',
        dataType: 'json',
        cache: false,
        success: function(res) {
            if (res.success && res.beds) {
                let bedRows = '';
                res.beds.forEach(bed => {
                    let statusClass = '';
                    let statusLabel = '';
                    if (bed.status === 'available') { statusClass = 'badge-available'; statusLabel = 'Available'; }
                    else if (bed.status === 'occupied') { statusClass = 'badge-occupied'; statusLabel = 'Occupied'; }
                    else if (bed.status === 'maintenance') { statusClass = 'badge-maintenance'; statusLabel = 'Maintenance'; }
                    else { statusClass = 'badge-secondary'; statusLabel = bed.status; }

                    const patientInfo = bed.patient_name ? `${bed.patient_name} (${bed.mr_number})` : '—';
                    const maintenanceAction = (bed.status === 'maintenance') ?
                        `<button class="btn-restore" data-bed-id="${bed.bed_id}" data-new-status="available"><i class="fas fa-undo-alt me-1"></i>Restore</button>` :
                        `<button class="btn-maintenance" data-bed-id="${bed.bed_id}" data-new-status="maintenance"><i class="fas fa-hammer me-1"></i>Set Maintenance</button>`;

                    bedRows += `
                        <tr>
                            <td>${escapeHtml(bed.branch_name)}</td>
                            <td>${escapeHtml(bed.ward_name)} (${bed.ward_type})</td>
                            <td>${escapeHtml(bed.bed_number)}</td>
                            <td>${parseFloat(bed.daily_rate).toFixed(2)}</td>
                            <td><span class="bed-status-badge ${statusClass}">${statusLabel}</span></td>
                            <td>${escapeHtml(patientInfo)}</td>
                            <td class="action-icons">${maintenanceAction}</td>
                        </tr>
                    `;
                });
                $('#bedGridTbody').html(bedRows);

                // Rebind maintenance toggle buttons
                $('.btn-maintenance, .btn-restore').off('click').on('click', function() {
                    const bedId = $(this).data('bed-id');
                    const newStatus = $(this).data('new-status');
                    toggleMaintenance(bedId, newStatus);
                });
            }

            // Build active admissions list from the same dataset (extract where bed.occupied and admission_id exists)
            const activeAdmissions = res.beds.filter(b => b.status === 'occupied' && b.admission_id);
            let admissionRows = '';
            if (activeAdmissions.length === 0) {
                admissionRows = '<tr><td colspan="5" class="text-center py-4">No active admissions found.</td></tr>';
            } else {
                activeAdmissions.forEach(adm => {
                    admissionRows += `
                        <tr>
                            <td>${escapeHtml(adm.patient_name)}</td>
                            <td>${escapeHtml(adm.mr_number)}</td>
                            <td>—</td> <!-- admitted_at not directly in this query, can be extended -->
                            <td>${escapeHtml(adm.bed_number)} (${escapeHtml(adm.ward_name)})</td>
                            <td>
                                <button class="btn btn-sm btn-danger discharge-btn" data-admission-id="${adm.admission_id}"><i class="fas fa-sign-out-alt"></i> Discharge</button>
                                <button class="btn btn-sm btn-primary reassign-btn" data-admission-id="${adm.admission_id}" data-patient-name="${escapeHtml(adm.patient_name)}"><i class="fas fa-exchange-alt"></i> Reassign Bed</button>
                            </td>
                        </tr>
                    `;
                });
            }
            $('#activeAdmissionsTbody').html(admissionRows);

            // Bind discharge and reassign events
            $('.discharge-btn').off('click').on('click', function() {
                const admissionId = $(this).data('admission-id');
                dischargePatient(admissionId);
            });
            $('.reassign-btn').off('click').on('click', function() {
                const admissionId = $(this).data('admission-id');
                const patientName = $(this).data('patient-name');
                showAssignBedModal(admissionId, patientName);
            });
        },
        error: function() { $('#bedGridTbody').html('<tr><td colspan="7" class="text-center py-4 text-danger">Network error loading bed grid.</td></tr>'); }
    });
}

// Toggle bed maintenance status
function toggleMaintenance(bedId, newStatus) {
    Swal.fire({
        title: 'Confirm Status Change',
        text: `Are you sure you want to mark this bed as ${newStatus === 'maintenance' ? 'MAINTENANCE (out of service)' : 'AVAILABLE'}?`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#ff9800',
        confirmButtonText: 'Yes, update'
    }).then((result) => {
        if (result.isConfirmed) {
            showLoading(true);
            $.ajax({
                url: window.location.pathname + '?action=toggle_bed_maintenance',
                method: 'POST',
                dataType: 'json',
                data: { bed_id: bedId, status: newStatus, csrf_token: csrfToken },
                success: function(res) {
                    showLoading(false);
                    if (res.success) {
                        Swal.fire('Updated', res.message, 'success');
                        loadBedGridAndAdmissions();
                        loadCapacityMatrix();
                    } else {
                        Swal.fire('Error', res.message, 'error');
                    }
                },
                error: function() { showLoading(false); Swal.fire('Error', 'Network error.', 'error'); }
            });
        }
    });
}

// Discharge patient
function dischargePatient(admissionId) {
    Swal.fire({
        title: 'Discharge Patient',
        text: 'This will free the bed and mark the admission as discharged. Continue?',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        confirmButtonText: 'Yes, discharge'
    }).then((result) => {
        if (result.isConfirmed) {
            showLoading(true);
            $.ajax({
                url: window.location.pathname + '?action=discharge_patient',
                method: 'POST',
                dataType: 'json',
                data: { admission_id: admissionId, csrf_token: csrfToken },
                success: function(res) {
                    showLoading(false);
                    if (res.success) {
                        Swal.fire('Discharged', res.message, 'success');
                        loadBedGridAndAdmissions();
                        loadCapacityMatrix();
                    } else {
                        Swal.fire('Error', res.message, 'error');
                    }
                },
                error: function() { showLoading(false); Swal.fire('Error', 'Network error.', 'error'); }
            });
        }
    });
}

// Show assign bed modal and load available beds
function showAssignBedModal(admissionId, patientName) {
    $('#assign_admission_id').val(admissionId);
    $('#assignBedModal').modal('show');
    $('#available_bed_select').html('<option value="">Loading available beds...</option>');
    // Load available beds via separate AJAX (reuse bed grid data filtered)
    $.ajax({
        url: window.location.pathname + '?action=load_bed_grid',
        method: 'GET',
        dataType: 'json',
        success: function(res) {
            if (res.success && res.beds) {
                const availableBeds = res.beds.filter(b => b.status === 'available');
                let options = '<option value="">Select a bed</option>';
                availableBeds.forEach(b => {
                    options += `<option value="${b.bed_id}">${b.branch_name} - ${b.ward_name} - Bed ${b.bed_number} (${b.daily_rate} ETB/day)</option>`;
                });
                $('#available_bed_select').html(options);
            } else {
                $('#available_bed_select').html('<option value="">No available beds found</option>');
            }
        },
        error: function() { $('#available_bed_select').html('<option value="">Error loading beds</option>'); }
    });
}

// Confirm bed assignment
$('#confirmAssignBed').on('click', function() {
    const admissionId = $('#assign_admission_id').val();
    const bedId = $('#available_bed_select').val();
    if (!admissionId || !bedId) {
        Swal.fire('Error', 'Please select a bed.', 'error');
        return;
    }
    showLoading(true);
    $.ajax({
        url: window.location.pathname + '?action=assign_bed',
        method: 'POST',
        dataType: 'json',
        data: { admission_id: admissionId, bed_id: bedId, csrf_token: csrfToken },
        success: function(res) {
            showLoading(false);
            if (res.success) {
                Swal.fire('Assigned', res.message, 'success');
                $('#assignBedModal').modal('hide');
                loadBedGridAndAdmissions();
                loadCapacityMatrix();
            } else {
                Swal.fire('Error', res.message, 'error');
            }
        },
        error: function() { showLoading(false); Swal.fire('Error', 'Network error.', 'error'); }
    });
});

// Auto-refresh every 30 seconds
function startAutoRefresh() {
    if (refreshInterval) clearInterval(refreshInterval);
    refreshInterval = setInterval(() => {
        loadCapacityMatrix();
        loadBedGridAndAdmissions();
    }, 30000);
}

$(document).ready(function() {
    loadCapacityMatrix();
    loadBedGridAndAdmissions();
    startAutoRefresh();
    $('#refreshGridBtn').on('click', function() {
        loadCapacityMatrix();
        loadBedGridAndAdmissions();
    });
});

function escapeHtml(str) {
    if (!str) return '';
    return String(str).replace(/[&<>]/g, m => m === '&' ? '&amp;' : (m === '<' ? '&lt;' : '&gt;'));
}
</script>
</body>
</html>