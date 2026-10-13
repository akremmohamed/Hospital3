<?php
/**
 * ======================================================================
 * INPATIENT & WARD MANAGEMENT - ward_management.php
 * For Doctor Module – Active ward roster, vitals timeline, progress notes,
 * discharge planning.
 * Strictly aligned with hms_erp.sql and patients.sql.
 * ======================================================================
 */

session_start();

// ============================================
// 1. BOOTSTRAP & SECURITY
// ============================================
$base_path = dirname(dirname(dirname(__FILE__))); // /Hospital3
require_once $base_path . '/config/db_connection.php';
require_once $base_path . '/includes/auth_helper.php';

if (!isset($pdo) || $pdo === null) {
    die('Database connection failed.');
}

date_default_timezone_set('Africa/Addis_Ababa');
try {
    $pdo->exec("SET time_zone = '+03:00'");
} catch (PDOException $e) {
    // ignore
}

$user_id = (int)($_SESSION['user_id'] ?? 0);
$branch_id = (int)($_SESSION['branch_id'] ?? 0);
$is_global_admin = ($_SESSION['user_type'] ?? '') === 'super_admin';

if ($user_id <= 0) {
    header('Location: ../../index.php');
    exit;
}

// RBAC: view_inpatient_admissions OR manage_ward_rounds
if (!$is_global_admin && !user_has_permission($pdo, 'view_inpatient_admissions', $user_id)
    && !user_has_permission($pdo, 'manage_ward_rounds', $user_id)) {
    header('Location: dashboard.php?error=unauthorized');
    exit;
}

// CSRF token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Helper functions
function jsonResponse($success, $message, $data = null, $code = 200) {
    if (ob_get_length()) ob_clean();
    http_response_code($code);
    header('Content-Type: application/json');
    $response = ['success' => $success, 'message' => $message];
    if ($data !== null) $response = array_merge($response, $data);
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

function escapeHtml($str) {
    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}

// ============================================
// 2. AJAX HANDLERS (ward_action)
// ============================================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'ward_action') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(false, 'Invalid request method');
    }
    $action = $_POST['action'] ?? '';
    $csrf_actions = ['fetch_ward_queue', 'load_inpatient_profile', 'save_progress_note', 'process_discharge'];
    if (in_array($action, $csrf_actions)) {
        if (!isset($_POST['csrf_token']) || !hash_equals($csrf_token, (string)$_POST['csrf_token'])) {
            jsonResponse(false, 'CSRF mismatch');
        }
    }

    try {
        // ---------- 2.1 FETCH ACTIVE INPATIENT QUEUE ----------
        if ($action === 'fetch_ward_queue') {
            $sql = "
                SELECT 
                    a.id AS admission_id,
                    a.patient_id,
                    CONCAT(p.first_name, ' ', p.last_name) AS patient_name,
                    p.mr_number,
                    w.name AS ward_name,
                    wb.bed_number,
                    a.admitted_at,
                    a.primary_diagnosis,
                    a.condition_status,
                    a.status
                FROM admissions a
                JOIN patients p ON a.patient_id = p.id AND p.deleted_at IS NULL
                LEFT JOIN ward_beds wb ON a.bed_id = wb.id AND wb.deleted_at IS NULL
                LEFT JOIN wards w ON wb.ward_id = w.id AND w.deleted_at IS NULL
                WHERE a.branch_id = :branch_id
                  AND a.doctor_id = :doctor_id
                  AND a.status = 'admitted'
                  AND a.discharged_at IS NULL
                  AND a.deleted_at IS NULL
                ORDER BY w.name ASC, wb.bed_number ASC
            ";
            $params = [':branch_id' => $branch_id, ':doctor_id' => $user_id];
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $queue = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (!is_array($queue)) $queue = [];
            jsonResponse(true, '', ['queue' => $queue]);
        }

        // ---------- 2.2 LOAD INPATIENT PROFILE (vitals + notes + bed details) ----------
        if ($action === 'load_inpatient_profile') {
            $admission_id = (int)($_POST['admission_id'] ?? 0);
            if ($admission_id <= 0) {
                jsonResponse(false, 'Invalid admission ID');
            }
            $profile = [];

            // Admission + patient + bed details
            $sql = "
                SELECT 
                    a.id AS admission_id,
                    a.patient_id,
                    a.admission_number,
                    a.admitted_at,
                    a.primary_diagnosis,
                    a.condition_status,
                    a.status AS admission_status,
                    a.discharge_condition,
                    a.discharge_summary,
                    CONCAT(p.first_name, ' ', p.last_name) AS patient_name,
                    p.mr_number,
                    p.date_of_birth,
                    p.gender,
                    p.phone,
                    w.name AS ward_name,
                    wb.bed_number,
                    a.discharged_at
                FROM admissions a
                JOIN patients p ON a.patient_id = p.id AND p.deleted_at IS NULL
                LEFT JOIN ward_beds wb ON a.bed_id = wb.id AND wb.deleted_at IS NULL
                LEFT JOIN wards w ON wb.ward_id = w.id AND w.deleted_at IS NULL
                WHERE a.id = :admission_id
                  AND a.branch_id = :branch_id
                  AND a.doctor_id = :doctor_id
                  AND a.deleted_at IS NULL
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':admission_id' => $admission_id,
                ':branch_id' => $branch_id,
                ':doctor_id' => $user_id
            ]);
            $header = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$header) {
                jsonResponse(false, 'Admission not found or access denied');
            }
            $profile['header'] = $header;

            // Vitals from triage_records (sorted chronologically)
            $sql_vitals = "
                SELECT 
                    id,
                    heart_rate,
                    blood_pressure_systolic,
                    blood_pressure_diastolic,
                    temperature_celsius,
                    respiratory_rate,
                    oxygen_saturation,
                    weight_kg,
                    taken_by_user_id,
                    created_at
                FROM triage_records
                WHERE patient_id = :patient_id
                  AND visit_id IS NOT NULL
                  AND deleted_at IS NULL
                ORDER BY created_at DESC
                LIMIT 30
            ";
            $stmt_v = $pdo->prepare($sql_vitals);
            $stmt_v->execute([':patient_id' => $header['patient_id']]);
            $vitals = $stmt_v->fetchAll(PDO::FETCH_ASSOC);
            $profile['vitals'] = is_array($vitals) ? $vitals : [];

            // Progress notes (admission_progress_notes)
            $sql_notes = "
                SELECT 
                    id,
                    round_type,
                    progress_note,
                    clinical_assessment,
                    plan_modifications,
                    created_at,
                    doctor_id
                FROM admission_progress_notes
                WHERE admission_id = :admission_id
                  AND deleted_at IS NULL
                ORDER BY created_at DESC
                LIMIT 50
            ";
            $stmt_n = $pdo->prepare($sql_notes);
            $stmt_n->execute([':admission_id' => $admission_id]);
            $notes = $stmt_n->fetchAll(PDO::FETCH_ASSOC);
            $profile['progress_notes'] = is_array($notes) ? $notes : [];

            jsonResponse(true, '', ['profile' => $profile]);
        }

        // ---------- 2.3 SAVE PROGRESS NOTE (rounding) ----------
        if ($action === 'save_progress_note') {
            $admission_id = (int)($_POST['admission_id'] ?? 0);
            $round_type = $_POST['round_type'] ?? 'morning';
            $progress_note = trim($_POST['progress_note'] ?? '');
            $clinical_assessment = trim($_POST['clinical_assessment'] ?? '');
            $plan_modifications = trim($_POST['plan_modifications'] ?? '');

            if ($admission_id <= 0 || empty($progress_note)) {
                jsonResponse(false, 'Progress note text is required');
            }
            if (!in_array($round_type, ['morning', 'evening'])) {
                $round_type = 'morning';
            }

            try {
                $pdo->beginTransaction();
                // Verify admission belongs to doctor & branch
                $check = $pdo->prepare("
                    SELECT id FROM admissions 
                    WHERE id = ? AND doctor_id = ? AND branch_id = ? AND deleted_at IS NULL
                ");
                $check->execute([$admission_id, $user_id, $branch_id]);
                if (!$check->fetch()) {
                    $pdo->rollBack();
                    jsonResponse(false, 'Admission not found or access denied');
                }

                $insert = $pdo->prepare("
                    INSERT INTO admission_progress_notes
                    (admission_id, doctor_id, round_type, progress_note, clinical_assessment, plan_modifications, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, NOW())
                ");
                $insert->execute([
                    $admission_id, $user_id, $round_type, $progress_note,
                    $clinical_assessment, $plan_modifications
                ]);
                $pdo->commit();
                jsonResponse(true, 'Progress note saved successfully');
            } catch (PDOException $e) {
                $pdo->rollBack();
                jsonResponse(false, 'Database error: ' . $e->getMessage());
            }
        }

        // ---------- 2.4 PROCESS DISCHARGE (atomic: update admission + free bed) ----------
        if ($action === 'process_discharge') {
            $admission_id = (int)($_POST['admission_id'] ?? 0);
            $discharge_condition = $_POST['discharge_condition'] ?? 'improved';
            $discharge_summary = trim($_POST['discharge_summary'] ?? '');
            $final_diagnosis = trim($_POST['final_diagnosis'] ?? '');

            if ($admission_id <= 0 || empty($discharge_summary)) {
                jsonResponse(false, 'Discharge summary is required');
            }
            $valid_conditions = ['improved', 'stabilized', 'relieved', 'ama', 'expired', 'transferred'];
            if (!in_array($discharge_condition, $valid_conditions)) {
                $discharge_condition = 'improved';
            }

            try {
                $pdo->beginTransaction();

                // Fetch bed_id before discharge to free it
                $bed_stmt = $pdo->prepare("SELECT bed_id FROM admissions WHERE id = ? AND doctor_id = ? AND branch_id = ? AND deleted_at IS NULL");
                $bed_stmt->execute([$admission_id, $user_id, $branch_id]);
                $admission = $bed_stmt->fetch(PDO::FETCH_ASSOC);
                if (!$admission) {
                    $pdo->rollBack();
                    jsonResponse(false, 'Admission not found');
                }
                $bed_id = $admission['bed_id'];

                // Update admission record
                $update_adm = $pdo->prepare("
                    UPDATE admissions 
                    SET status = 'discharged',
                        discharge_condition = ?,
                        discharge_summary = ?,
                        discharge_diagnosis = ?,
                        discharged_at = NOW()
                    WHERE id = ?
                ");
                $update_adm->execute([$discharge_condition, $discharge_summary, $final_diagnosis, $admission_id]);

                // Free the bed (if assigned)
                if ($bed_id) {
                    $free_bed = $pdo->prepare("UPDATE ward_beds SET is_occupied = 0, occupied_by_patient_id = NULL, occupied_at = NULL WHERE id = ?");
                    $free_bed->execute([$bed_id]);
                }

                $pdo->commit();
                jsonResponse(true, 'Patient discharged successfully');
            } catch (PDOException $e) {
                $pdo->rollBack();
                jsonResponse(false, 'Database error: ' . $e->getMessage());
            }
        }

        jsonResponse(false, 'Unsupported action');
    } catch (PDOException $e) {
        jsonResponse(false, 'Database error: ' . $e->getMessage());
    } catch (Exception $e) {
        jsonResponse(false, 'Error: ' . $e->getMessage());
    }
    exit;
}

// ============================================
// 3. INITIAL PAGE DATA (template only)
// ============================================
$doctor_name = $_SESSION['first_name'] ?? $_SESSION['username'] ?? 'Doctor';
$current_date = date('l, F j, Y');
$current_time = date('g:i A');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include($base_path . '/modules/doctor/assets/inc/head.php'); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ward Management - Inpatient Rounds | HMS ERP</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="<?php echo $base_path; ?>/modules/doctor/assets/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <style>
        :root {
            --primary: #4361ee;
            --primary-dark: #3a0ca3;
            --success: #06d6a0;
            --warning: #f9c74f;
            --danger: #ef476f;
            --info: #4cc9f0;
            --dark: #1a1a2e;
            --gray: #6b7280;
            --light-gray: #f3f4f6;
            --border-gray: #e5e7eb;
        }
        * { margin:0; padding:0; box-sizing:border-box; }
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: linear-gradient(135deg, #f5f7fb 0%, #eef2f6 100%);
            overflow-x: hidden;
        }
        .content-page {
            margin-left: 260px;
            margin-top: 70px;
            padding: 24px 32px;
            transition: margin-left 0.3s;
            min-height: calc(100vh - 70px);
        }
        body.erp-sidebar-collapsed .content-page { margin-left: 80px; }
        @media (max-width: 768px) { .content-page { margin-left: 0!important; padding: 16px; } }

        .page-header {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--primary) 100%);
            border-radius: 20px;
            padding: 20px 28px;
            margin-bottom: 28px;
            color: white;
        }
        .page-title { font-size: 24px; font-weight: 700; margin-bottom: 4px; }
        .page-subtitle { font-size: 14px; color: rgba(255,255,255,0.8); }

        .ward-container {
            display: flex;
            gap: 24px;
        }
        .queue-sidebar {
            width: 380px;
            flex-shrink: 0;
            background: white;
            border-radius: 20px;
            border: 1px solid var(--border-gray);
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }
        .queue-header {
            padding: 16px 20px;
            background: #f8fafc;
            border-bottom: 1px solid var(--border-gray);
        }
        .queue-header h3 { font-size: 16px; font-weight: 700; margin: 0; }
        .queue-search {
            padding: 12px 16px;
            border-bottom: 1px solid var(--border-gray);
        }
        .queue-search input {
            width: 100%;
            padding: 8px 12px;
            border-radius: 12px;
            border: 1px solid var(--border-gray);
            font-size: 13px;
        }
        .queue-list {
            flex: 1;
            overflow-y: auto;
            max-height: calc(100vh - 280px);
        }
        .patient-card {
            padding: 14px 16px;
            border-bottom: 1px solid #f0f0f0;
            cursor: pointer;
            transition: all 0.2s;
            border-left: 3px solid transparent;
        }
        .patient-card:hover { background: var(--light-gray); }
        .patient-card.active {
            background: #eef2ff;
            border-left-color: var(--primary);
        }
        .patient-name {
            font-weight: 600;
            font-size: 15px;
            margin-bottom: 4px;
        }
        .patient-mrn {
            font-size: 11px;
            color: var(--gray);
        }
        .bed-badge {
            font-size: 10px;
            background: #e0e7ff;
            padding: 2px 6px;
            border-radius: 12px;
            margin-top: 4px;
            display: inline-block;
        }

        .result-panel {
            flex: 1;
            background: white;
            border-radius: 20px;
            border: 1px solid var(--border-gray);
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }
        .panel-header {
            padding: 18px 24px;
            background: #f8fafc;
            border-bottom: 1px solid var(--border-gray);
        }
        .panel-header h3 { font-size: 18px; font-weight: 700; margin: 0; }
        .panel-tabs {
            display: flex;
            border-bottom: 1px solid var(--border-gray);
            background: white;
            padding: 0 16px;
        }
        .tab-btn {
            padding: 12px 20px;
            font-weight: 600;
            color: var(--gray);
            background: none;
            border: none;
            cursor: pointer;
            transition: 0.2s;
            border-bottom: 2px solid transparent;
        }
        .tab-btn.active {
            color: var(--primary);
            border-bottom-color: var(--primary);
        }
        .tab-content {
            padding: 24px;
            overflow-y: auto;
            flex: 1;
        }
        .tab-pane { display: none; }
        .tab-pane.active { display: block; }

        .vital-card {
            background: var(--light-gray);
            border-radius: 16px;
            padding: 12px;
            margin-bottom: 12px;
        }
        .abnormal {
            background: #fee2e2;
            border-left: 4px solid var(--danger);
        }
        .progress-note-card {
            background: white;
            border: 1px solid var(--border-gray);
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 16px;
        }
        .form-group { margin-bottom: 20px; }
        .form-group label { font-weight: 600; font-size: 13px; display: block; margin-bottom: 8px; color: var(--dark); }
        .form-group textarea, .form-group input, .form-group select {
            width: 100%; padding: 10px 14px; border: 1px solid var(--border-gray); border-radius: 12px; font-size: 14px;
        }
        .btn {
            padding: 10px 24px; border-radius: 12px; font-weight: 600; border: none; cursor: pointer;
        }
        .btn-primary { background: var(--primary); color: white; }
        .btn-primary:hover { background: var(--primary-dark); }
        .btn-outline { background: white; border: 1px solid var(--border-gray); color: var(--dark); }
        .btn-danger { background: var(--danger); color: white; }
        .action-buttons { display: flex; gap: 12px; justify-content: flex-end; margin-top: 24px; }

        .toast-notification {
            position: fixed; bottom: 20px; right: 20px;
            background: white; border-radius: 12px; padding: 12px 20px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
            z-index: 1100; animation: slideIn 0.3s;
            border-left: 4px solid var(--success);
        }
        .toast-error { border-left-color: var(--danger); }
        @keyframes slideIn { from { transform: translateX(100%); opacity:0; } to { transform: translateX(0); opacity:1; } }
        .loading-overlay {
            position: fixed; top:0; left:0; width:100%; height:100%;
            background: rgba(255,255,255,0.7); z-index:2000;
            display: none; align-items: center; justify-content: center;
        }
        .loading-overlay.active { display: flex; }
        .spinner {
            width: 40px; height: 40px;
            border: 3px solid var(--light-gray);
            border-top-color: var(--primary);
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
        .empty-state { text-align: center; padding: 40px; color: var(--gray); }
    </style>
</head>
<body>
<div id="wrapper">
    <?php include($base_path . '/modules/doctor/assets/inc/nav.php'); ?>
    <?php include($base_path . '/modules/doctor/assets/inc/sidebar.php'); ?>

    <div class="content-page">
        <div class="container-fluid">
            <div class="page-header">
                <div class="page-title"><i class="fas fa-procedures me-2"></i> Inpatient Ward Management</div>
                <div class="page-subtitle">Active ward rounds, vitals monitoring, progress notes & discharge planning</div>
            </div>

            <div class="ward-container">
                <!-- Left Sidebar: Active Ward Roster -->
                <div class="queue-sidebar">
                    <div class="queue-header">
                        <h3><i class="fas fa-hospital-user me-2"></i> Active Inpatients</h3>
                    </div>
                    <div class="queue-search">
                        <input type="text" id="patientSearch" placeholder="Search by name, MRN, bed..." class="form-control">
                    </div>
                    <div class="queue-list" id="queueList">
                        <div class="text-center p-4 text-muted">Loading admissions...</div>
                    </div>
                </div>

                <!-- Right Panel: Clinical Timeline -->
                <div class="result-panel" id="resultPanel">
                    <div class="panel-header">
                        <h3 id="panelTitle">Select a patient from the list</h3>
                    </div>
                    <div class="panel-tabs" id="panelTabs" style="display: none;">
                        <button class="tab-btn active" data-tab="vitals-tab">🩺 Vital Signs</button>
                        <button class="tab-btn" data-tab="progress-tab">📝 Progress Notes</button>
                        <button class="tab-btn" data-tab="discharge-tab">🚪 Discharge Summary</button>
                    </div>
                    <div class="tab-content" id="tabContent">
                        <div class="tab-pane active" id="vitals-tab">
                            <div id="vitalsContainer" class="empty-state">
                                <i class="fas fa-heartbeat fa-2x mb-2"></i>
                                <p>Select a patient to view vital signs</p>
                            </div>
                        </div>
                        <div class="tab-pane" id="progress-tab">
                            <div id="progressContainer" class="empty-state">
                                <i class="fas fa-notes-medical fa-2x mb-2"></i>
                                <p>Select a patient to view progress notes</p>
                            </div>
                        </div>
                        <div class="tab-pane" id="discharge-tab">
                            <div id="dischargeContainer" class="empty-state">
                                <i class="fas fa-file-alt fa-2x mb-2"></i>
                                <p>Select a patient to initiate discharge planning</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php include($base_path . '/modules/doctor/assets/inc/footer.php'); ?>
</div>

<div id="loadingOverlay" class="loading-overlay"><div class="spinner"></div></div>

<script>
const doctorId = <?php echo $user_id; ?>;
const branchId = <?php echo $branch_id; ?>;
const csrfToken = <?php echo json_encode($csrf_token); ?>;
let currentAdmissionId = null;
let currentPatientId = null;

function showToast(msg, type='success') {
    const t = document.createElement('div');
    t.className = `toast-notification ${type==='error'?'toast-error':''}`;
    t.innerHTML = `<i class="fas fa-${type==='error'?'exclamation-circle':'check-circle'}"></i> <span>${escapeHtml(msg)}</span>`;
    document.body.appendChild(t);
    setTimeout(()=>t.remove(),3000);
}
function showLoading(show) {
    document.getElementById('loadingOverlay').classList.toggle('active', show);
}
function escapeHtml(s) {
    if (!s) return '';
    return String(s).replace(/[&<>]/g, m => m==='&'?'&amp;':(m==='<'?'&lt;':'&gt;'));
}

function initTabs() {
    const btns = document.querySelectorAll('.tab-btn');
    btns.forEach(btn => {
        btn.addEventListener('click', () => {
            const target = btn.getAttribute('data-tab');
            document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('active'));
            document.getElementById(target).classList.add('active');
        });
    });
}

function fetchWardQueue() {
    showLoading(true);
    $.post(window.location.pathname + '?ajax=ward_action', {
        action: 'fetch_ward_queue',
        csrf_token: csrfToken,
        branch_id: branchId
    }, function(res) {
        showLoading(false);
        if (res.success && res.queue) {
            renderQueue(res.queue);
        } else {
            $('#queueList').html('<div class="text-center p-4 text-muted">Error loading admissions</div>');
        }
    }).fail(() => { showLoading(false); showToast('Failed to load queue','error'); });
}

function renderQueue(queue) {
    const container = $('#queueList');
    container.empty();
    if (!queue.length) {
        container.html('<div class="text-center p-4 text-muted">No active admissions under your care</div>');
        return;
    }
    let html = '';
    queue.forEach(item => {
        const conditionClass = (item.condition_status === 'critical') ? 'text-danger fw-bold' : '';
        html += `
            <div class="patient-card" data-admission-id="${item.admission_id}" data-patient-id="${item.patient_id}">
                <div class="patient-name">${escapeHtml(item.patient_name)} <span class="bed-badge">🛏️ ${escapeHtml(item.bed_number || '—')}</span></div>
                <div class="patient-mrn">MRN: ${escapeHtml(item.mr_number)} | Ward: ${escapeHtml(item.ward_name || '—')}</div>
                <div class="small text-muted mt-1">Admitted: ${item.admitted_at ? new Date(item.admitted_at).toLocaleDateString() : '—'} | Dx: ${escapeHtml((item.primary_diagnosis || '').substring(0,40))}</div>
                <div class="small ${conditionClass}">Status: ${escapeHtml(item.condition_status)}</div>
            </div>
        `;
    });
    container.html(html);
    $('.patient-card').on('click', function() {
        const admissionId = $(this).data('admission-id');
        const patientId = $(this).data('patient-id');
        $('.patient-card').removeClass('active');
        $(this).addClass('active');
        loadInpatientProfile(admissionId, patientId);
    });
    $('#patientSearch').off('keyup').on('keyup', function() {
        const term = $(this).val().toLowerCase();
        $('.patient-card').each(function() {
            const text = $(this).text().toLowerCase();
            $(this).toggle(text.indexOf(term) > -1);
        });
    });
}

function loadInpatientProfile(admissionId, patientId) {
    currentAdmissionId = admissionId;
    currentPatientId = patientId;
    showLoading(true);
    $.post(window.location.pathname + '?ajax=ward_action', {
        action: 'load_inpatient_profile',
        admission_id: admissionId,
        csrf_token: csrfToken,
        branch_id: branchId
    }, function(res) {
        showLoading(false);
        if (res.success && res.profile) {
            displayProfile(res.profile);
            $('#panelTabs').show();
        } else {
            showToast(res.message || 'Failed to load profile', 'error');
        }
    }).fail(() => { showLoading(false); showToast('Network error','error'); });
}

function displayProfile(profile) {
    const header = profile.header;
    $('#panelTitle').html(`${escapeHtml(header.patient_name)} (${escapeHtml(header.mr_number)}) – ${escapeHtml(header.ward_name || 'Ward')} / Bed ${escapeHtml(header.bed_number || '—')}`);

    // Vitals Tab
    const vitals = profile.vitals || [];
    if (vitals.length === 0) {
        $('#vitalsContainer').html('<div class="empty-state"><i class="fas fa-chart-line fa-2x mb-2"></i><p>No vital signs recorded</p></div>');
    } else {
        let vitHtml = '<div class="mb-3"><strong>Recent Vital Signs (last 30)</strong></div>';
        vitals.forEach(v => {
            const isAbnormal = (v.heart_rate && (v.heart_rate < 60 || v.heart_rate > 100)) ||
                               (v.blood_pressure_systolic && v.blood_pressure_systolic > 140) ||
                               (v.temperature_celsius && (v.temperature_celsius > 37.5 || v.temperature_celsius < 36)) ||
                               (v.oxygen_saturation && v.oxygen_saturation < 94);
            const abnormalClass = isAbnormal ? 'abnormal' : '';
            const datetime = new Date(v.created_at).toLocaleString();
            vitHtml += `
                <div class="vital-card ${abnormalClass}">
                    <div class="d-flex justify-content-between mb-2">
                        <span class="fw-bold">${datetime}</span>
                        <span class="small text-muted">By: ${escapeHtml(v.taken_by_user_id || 'Nurse')}</span>
                    </div>
                    <div class="row">
                        <div class="col-4">HR: ${v.heart_rate || '—'} bpm</div>
                        <div class="col-4">BP: ${v.blood_pressure_systolic || '—'}/${v.blood_pressure_diastolic || '—'} mmHg</div>
                        <div class="col-4">Temp: ${v.temperature_celsius || '—'} °C</div>
                        <div class="col-4">RR: ${v.respiratory_rate || '—'} /min</div>
                        <div class="col-4">SpO₂: ${v.oxygen_saturation || '—'} %</div>
                        <div class="col-4">Weight: ${v.weight_kg || '—'} kg</div>
                    </div>
                    ${isAbnormal ? '<div class="mt-2 text-danger"><i class="fas fa-exclamation-triangle"></i> Abnormal values detected</div>' : ''}
                </div>
            `;
        });
        $('#vitalsContainer').html(vitHtml);
    }

    // Progress Notes Tab
    const notes = profile.progress_notes || [];
    let notesHtml = '';
    if (notes.length === 0) {
        notesHtml = '<div class="empty-state"><i class="fas fa-edit fa-2x mb-2"></i><p>No progress notes yet</p></div>';
    } else {
        notes.forEach(n => {
            notesHtml += `
                <div class="progress-note-card">
                    <div class="d-flex justify-content-between mb-2">
                        <span class="badge bg-secondary">${escapeHtml(n.round_type)} Round</span>
                        <span class="small text-muted">${new Date(n.created_at).toLocaleString()}</span>
                    </div>
                    <div><strong>Progress:</strong> ${escapeHtml(n.progress_note || '—')}</div>
                    ${n.clinical_assessment ? `<div class="mt-2"><strong>Assessment:</strong> ${escapeHtml(n.clinical_assessment)}</div>` : ''}
                    ${n.plan_modifications ? `<div class="mt-2"><strong>Plan:</strong> ${escapeHtml(n.plan_modifications)}</div>` : ''}
                </div>
            `;
        });
    }
    // Add new progress note form
    notesHtml += `
        <hr>
        <div class="mt-3">
            <h6>Add Daily Round Note</h6>
            <form id="progressNoteForm">
                <input type="hidden" name="admission_id" value="${currentAdmissionId}">
                <div class="form-group">
                    <label>Round Type</label>
                    <select name="round_type" class="form-control" required>
                        <option value="morning">Morning Round</option>
                        <option value="evening">Evening Round</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Progress Note *</label>
                    <textarea name="progress_note" rows="3" class="form-control" required placeholder="Subjective/objective findings, response to treatment..."></textarea>
                </div>
                <div class="form-group">
                    <label>Clinical Assessment</label>
                    <textarea name="clinical_assessment" rows="2" class="form-control" placeholder="Current diagnosis status, new findings..."></textarea>
                </div>
                <div class="form-group">
                    <label>Plan Modifications</label>
                    <textarea name="plan_modifications" rows="2" class="form-control" placeholder="Medication changes, follow-up tests, referrals..."></textarea>
                </div>
                <div class="action-buttons">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Progress Note</button>
                </div>
            </form>
        </div>
    `;
    $('#progressContainer').html(notesHtml);
    $('#progressNoteForm').off('submit').on('submit', function(e) {
        e.preventDefault();
        saveProgressNote();
    });

    // Discharge Tab
    let dischargeHtml = `
        <div class="card p-3">
            <h5>Discharge Patient</h5>
            <p class="small text-muted">Complete this form to discharge the patient and free the bed.</p>
            <form id="dischargeForm">
                <input type="hidden" name="admission_id" value="${currentAdmissionId}">
                <div class="form-group">
                    <label>Discharge Condition</label>
                    <select name="discharge_condition" class="form-control">
                        <option value="improved">Improved</option>
                        <option value="stabilized">Stabilized</option>
                        <option value="relieved">Relieved</option>
                        <option value="ama">Against Medical Advice (AMA)</option>
                        <option value="expired">Expired</option>
                        <option value="transferred">Transferred</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Final Diagnosis</label>
                    <textarea name="final_diagnosis" rows="2" class="form-control" placeholder="Final diagnosis at discharge"></textarea>
                </div>
                <div class="form-group">
                    <label>Discharge Summary *</label>
                    <textarea name="discharge_summary" rows="4" class="form-control" required placeholder="Summary of hospital course, medications, follow-up instructions..."></textarea>
                </div>
                <div class="action-buttons">
                    <button type="submit" class="btn btn-danger"><i class="fas fa-door-open"></i> Process Discharge</button>
                </div>
            </form>
        </div>
    `;
    $('#dischargeContainer').html(dischargeHtml);
    $('#dischargeForm').off('submit').on('submit', function(e) {
        e.preventDefault();
        processDischarge();
    });
}

function saveProgressNote() {
    const formData = {
        action: 'save_progress_note',
        admission_id: $('#progressNoteForm input[name="admission_id"]').val(),
        round_type: $('#progressNoteForm select[name="round_type"]').val(),
        progress_note: $('#progressNoteForm textarea[name="progress_note"]').val(),
        clinical_assessment: $('#progressNoteForm textarea[name="clinical_assessment"]').val(),
        plan_modifications: $('#progressNoteForm textarea[name="plan_modifications"]').val(),
        csrf_token: csrfToken,
        branch_id: branchId
    };
    if (!formData.progress_note) {
        showToast('Please enter a progress note', 'error');
        return;
    }
    showLoading(true);
    $.post(window.location.pathname + '?ajax=ward_action', formData, function(res) {
        showLoading(false);
        if (res.success) {
            showToast(res.message);
            // refresh profile to show new note
            loadInpatientProfile(currentAdmissionId, currentPatientId);
        } else {
            showToast(res.message || 'Failed to save note', 'error');
        }
    }).fail(() => { showLoading(false); showToast('Network error','error'); });
}

function processDischarge() {
    const formData = {
        action: 'process_discharge',
        admission_id: $('#dischargeForm input[name="admission_id"]').val(),
        discharge_condition: $('#dischargeForm select[name="discharge_condition"]').val(),
        final_diagnosis: $('#dischargeForm textarea[name="final_diagnosis"]').val(),
        discharge_summary: $('#dischargeForm textarea[name="discharge_summary"]').val(),
        csrf_token: csrfToken,
        branch_id: branchId
    };
    if (!formData.discharge_summary) {
        showToast('Discharge summary is required', 'error');
        return;
    }
    if (!confirm('Are you sure you want to discharge this patient? This will free the bed and cannot be undone.')) {
        return;
    }
    showLoading(true);
    $.post(window.location.pathname + '?ajax=ward_action', formData, function(res) {
        showLoading(false);
        if (res.success) {
            showToast(res.message);
            // refresh queue and clear panel
            fetchWardQueue();
            $('#panelTabs').hide();
            $('#panelTitle').html('Select a patient from the list');
            $('#vitalsContainer, #progressContainer, #dischargeContainer').html('<div class="empty-state"><i class="fas fa-info-circle fa-2x mb-2"></i><p>Patient discharged</p></div>');
        } else {
            showToast(res.message || 'Discharge failed', 'error');
        }
    }).fail(() => { showLoading(false); showToast('Network error','error'); });
}

$(document).ready(function() {
    initTabs();
    fetchWardQueue();
    setInterval(fetchWardQueue, 30000);
});
</script>
</body>
</html>