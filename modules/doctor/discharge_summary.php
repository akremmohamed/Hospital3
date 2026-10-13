<?php
/**
 * ======================================================================
 * DISCHARGE SUMMARY & PLANNING SUBSYSTEM - discharge_summary.php
 * For Doctor Module – Finalize inpatient discharge, free beds, print summary
 * Strictly aligned with hms_erp.sql, patients.sql, branch isolation.
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

// RBAC: manage_discharge_summary OR finalize_patient_discharge
if (!$is_global_admin && !user_has_permission($pdo, 'manage_discharge_summary', $user_id)
    && !user_has_permission($pdo, 'finalize_patient_discharge', $user_id)) {
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
// 2. AJAX HANDLERS (discharge_action)
// ============================================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'discharge_action') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(false, 'Invalid request method');
    }
    $action = $_POST['action'] ?? '';
    $csrf_actions = ['get_admission_details', 'submit_discharge'];
    if (in_array($action, $csrf_actions)) {
        if (!isset($_POST['csrf_token']) || !hash_equals($csrf_token, (string)$_POST['csrf_token'])) {
            jsonResponse(false, 'CSRF mismatch');
        }
    }

    try {
        // ---------- 2.1 GET ADMISSION DETAILS (for form pre‑fill) ----------
        if ($action === 'get_admission_details') {
            $admission_id = (int)($_POST['admission_id'] ?? 0);
            if ($admission_id <= 0) {
                jsonResponse(false, 'Invalid admission ID');
            }

            // Fetch admission + patient + bed + ward
            $sql = "
                SELECT 
                    a.id AS admission_id,
                    a.patient_id,
                    a.admission_number,
                    a.admitted_at,
                    a.primary_diagnosis,
                    a.admitting_diagnosis,
                    a.condition_status,
                    a.status AS admission_status,
                    a.discharge_status,
                    CONCAT(p.first_name, ' ', p.last_name) AS patient_name,
                    p.mr_number,
                    p.date_of_birth,
                    p.gender,
                    w.name AS ward_name,
                    wb.bed_number,
                    wb.id AS bed_id
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
            $admission = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$admission) {
                jsonResponse(false, 'Admission not found or access denied');
            }

            // Also fetch progress notes (last 3) for clinical summary draft
            $notes_sql = "
                SELECT progress_note, clinical_assessment, created_at
                FROM admission_progress_notes
                WHERE admission_id = :admission_id AND deleted_at IS NULL
                ORDER BY created_at DESC
                LIMIT 3
            ";
            $notes_stmt = $pdo->prepare($notes_sql);
            $notes_stmt->execute([':admission_id' => $admission_id]);
            $recent_notes = $notes_stmt->fetchAll(PDO::FETCH_ASSOC);
            $admission['recent_notes'] = $recent_notes;

            jsonResponse(true, '', ['admission' => $admission]);
        }

        // ---------- 2.2 SUBMIT DISCHARGE (atomic transaction) ----------
        if ($action === 'submit_discharge') {
            $admission_id = (int)($_POST['admission_id'] ?? 0);
            $discharge_condition = $_POST['discharge_condition'] ?? 'improved';
            $clinical_course = trim($_POST['clinical_course'] ?? '');
            $final_diagnosis = trim($_POST['final_diagnosis'] ?? '');
            $procedures = trim($_POST['procedures'] ?? '');
            $discharge_instructions = trim($_POST['discharge_instructions'] ?? '');
            $follow_up_date = !empty($_POST['follow_up_date']) ? $_POST['follow_up_date'] : null;
            $follow_up_with = trim($_POST['follow_up_with'] ?? '');
            $home_medications = trim($_POST['home_medications'] ?? '');
            $warning_signs = trim($_POST['warning_signs'] ?? '');

            if ($admission_id <= 0) {
                jsonResponse(false, 'Invalid admission ID');
            }

            // Validate condition against allowed ENUM
            $allowed_conditions = ['improved', 'stabilized', 'relieved', 'ama', 'expired', 'transferred'];
            if (!in_array($discharge_condition, $allowed_conditions)) {
                $discharge_condition = 'improved';
            }

            try {
                $pdo->beginTransaction();

                // 1) Verify admission belongs to doctor & branch, and fetch bed_id
                $check_sql = "
                    SELECT id, bed_id FROM admissions 
                    WHERE id = :id AND doctor_id = :doctor_id AND branch_id = :branch_id 
                      AND (discharge_status != 'discharged' OR discharge_status IS NULL)
                      AND deleted_at IS NULL
                ";
                $check_stmt = $pdo->prepare($check_sql);
                $check_stmt->execute([
                    ':id' => $admission_id,
                    ':doctor_id' => $user_id,
                    ':branch_id' => $branch_id
                ]);
                $admission = $check_stmt->fetch(PDO::FETCH_ASSOC);
                if (!$admission) {
                    $pdo->rollBack();
                    jsonResponse(false, 'Admission not found, already discharged, or access denied');
                }
                $bed_id = $admission['bed_id'];

                // Build discharge summary text
                $discharge_summary = "CLINICAL COURSE:\n" . $clinical_course . "\n\n";
                $discharge_summary .= "FINAL DIAGNOSIS:\n" . $final_diagnosis . "\n\n";
                $discharge_summary .= "PROCEDURES PERFORMED:\n" . $procedures . "\n\n";
                $discharge_summary .= "DISCHARGE INSTRUCTIONS:\n" . $discharge_instructions . "\n\n";
                $discharge_summary .= "HOME MEDICATIONS:\n" . $home_medications . "\n\n";
                $discharge_summary .= "WARNING SIGNS:\n" . $warning_signs . "\n\n";
                if ($follow_up_date) {
                    $discharge_summary .= "FOLLOW-UP: " . $follow_up_date . " with " . ($follow_up_with ?: 'primary care') . "\n";
                }

                // 2) Update admissions table
                $update_adm = $pdo->prepare("
                    UPDATE admissions 
                    SET discharge_status = 'discharged',
                        discharge_condition = :condition,
                        discharge_summary = :summary,
                        discharge_diagnosis = :final_dx,
                        discharge_instructions = :instructions,
                        discharged_at = NOW(),
                        updated_at = NOW()
                    WHERE id = :id
                ");
                $update_adm->execute([
                    ':condition' => $discharge_condition,
                    ':summary' => $discharge_summary,
                    ':final_dx' => $final_diagnosis,
                    ':instructions' => $discharge_instructions,
                    ':id' => $admission_id
                ]);

                // 3) Free the bed if one was assigned
                if ($bed_id) {
                    $free_bed = $pdo->prepare("
                        UPDATE ward_beds 
                        SET is_occupied = 0, 
                            occupied_by_patient_id = NULL, 
                            occupied_at = NULL 
                        WHERE id = :bed_id
                    ");
                    $free_bed->execute([':bed_id' => $bed_id]);
                }

                $pdo->commit();
                jsonResponse(true, 'Discharge completed successfully. Bed has been freed.');
            } catch (PDOException $e) {
                $pdo->rollBack();
                error_log("Discharge submission error: " . $e->getMessage());
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
// 3. INITIAL PAGE DATA (active & discharged admissions)
// ============================================
$admissions_list = [];
try {
    $sql = "
        SELECT 
            a.id AS admission_id,
            a.patient_id,
            CONCAT(p.first_name, ' ', p.last_name) AS patient_name,
            p.mr_number,
            a.admission_number,
            a.discharge_status,
            a.discharged_at,
            w.name AS ward_name,
            wb.bed_number
        FROM admissions a
        JOIN patients p ON a.patient_id = p.id AND p.deleted_at IS NULL
        LEFT JOIN ward_beds wb ON a.bed_id = wb.id AND wb.deleted_at IS NULL
        LEFT JOIN wards w ON wb.ward_id = w.id AND w.deleted_at IS NULL
        WHERE a.branch_id = :branch_id
          AND a.doctor_id = :doctor_id
          AND a.deleted_at IS NULL
        ORDER BY 
            FIELD(a.discharge_status, 'admitted', NULL) DESC,
            a.created_at DESC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':branch_id' => $branch_id, ':doctor_id' => $user_id]);
    $admissions_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($admissions_list)) $admissions_list = [];
} catch (PDOException $e) {
    error_log("Discharge summary initial load error: " . $e->getMessage());
}

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
    <title>Discharge Summary & Planning | HMS ERP</title>
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

        .discharge-container {
            display: flex;
            gap: 24px;
        }
        .queue-sidebar {
            width: 340px;
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
        .status-badge {
            font-size: 10px;
            padding: 2px 6px;
            border-radius: 12px;
            display: inline-block;
            margin-top: 4px;
        }
        .status-admitted { background: #dbeafe; color: #2563eb; }
        .status-discharged { background: #d1fae5; color: #059669; }

        .form-panel {
            flex: 1;
            background: white;
            border-radius: 20px;
            border: 1px solid var(--border-gray);
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }
        .form-header {
            padding: 18px 24px;
            background: #f8fafc;
            border-bottom: 1px solid var(--border-gray);
        }
        .form-header h3 { font-size: 18px; font-weight: 700; margin: 0; }
        .form-body {
            padding: 24px;
            max-height: calc(100vh - 280px);
            overflow-y: auto;
        }
        .section-card {
            background: var(--light-gray);
            border-radius: 16px;
            padding: 16px;
            margin-bottom: 20px;
        }
        .section-title {
            font-weight: 700;
            margin-bottom: 12px;
            font-size: 14px;
            color: var(--dark);
            border-left: 3px solid var(--primary);
            padding-left: 10px;
        }
        .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }
        .info-item {
            font-size: 13px;
        }
        .info-label {
            color: var(--gray);
            font-weight: 500;
        }
        .info-value {
            color: var(--dark);
            font-weight: 600;
        }
        .form-group { margin-bottom: 16px; }
        .form-group label {
            font-weight: 600;
            font-size: 13px;
            display: block;
            margin-bottom: 6px;
            color: var(--dark);
        }
        .form-group textarea, .form-group input, .form-group select {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid var(--border-gray);
            border-radius: 12px;
            font-size: 14px;
        }
        .form-group textarea:focus, .form-group input:focus, .form-group select:focus {
            outline: none;
            border-color: var(--primary);
        }
        .radio-group {
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
        }
        .radio-group label {
            font-weight: normal;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .action-buttons {
            display: flex;
            gap: 12px;
            justify-content: flex-end;
            margin-top: 20px;
        }
        .btn {
            padding: 10px 24px;
            border-radius: 12px;
            font-weight: 600;
            border: none;
            cursor: pointer;
        }
        .btn-primary { background: var(--primary); color: white; }
        .btn-primary:hover { background: var(--primary-dark); }
        .btn-outline { background: white; border: 1px solid var(--border-gray); color: var(--dark); }
        .btn-danger { background: var(--danger); color: white; }

        /* Print styles */
        @media print {
            body * {
                visibility: hidden;
            }
            .printable-area, .printable-area * {
                visibility: visible;
            }
            .printable-area {
                position: absolute;
                top: 0;
                left: 0;
                width: 100%;
                padding: 20px;
                background: white;
            }
            .no-print {
                display: none !important;
            }
            .form-panel, .queue-sidebar, .page-header, .action-buttons {
                display: none !important;
            }
            .content-page {
                margin-left: 0 !important;
                padding: 0 !important;
            }
        }

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
                <div class="page-title"><i class="fas fa-door-open me-2"></i> Discharge Summary & Planning</div>
                <div class="page-subtitle">Finalize inpatient discharge, free beds, and generate patient summary</div>
            </div>

            <div class="discharge-container">
                <!-- Left: Admissions List -->
                <div class="queue-sidebar">
                    <div class="queue-header">
                        <h3><i class="fas fa-procedures me-2"></i> My Inpatients</h3>
                    </div>
                    <div class="queue-list" id="admissionsList">
                        <?php if (count($admissions_list) > 0): ?>
                            <?php foreach ($admissions_list as $adm): ?>
                            <div class="patient-card" data-admission-id="<?php echo (int)$adm['admission_id']; ?>">
                                <div class="patient-name"><?php echo escapeHtml($adm['patient_name']); ?></div>
                                <div class="patient-mrn">MRN: <?php echo escapeHtml($adm['mr_number']); ?> | <?php echo escapeHtml($adm['admission_number']); ?></div>
                                <div class="small text-muted">Ward: <?php echo escapeHtml($adm['ward_name'] ?? '—'); ?> / Bed: <?php echo escapeHtml($adm['bed_number'] ?? '—'); ?></div>
                                <div>
                                    <?php if (empty($adm['discharge_status']) || $adm['discharge_status'] === 'admitted'): ?>
                                        <span class="status-badge status-admitted">🟢 Admitted</span>
                                    <?php else: ?>
                                        <span class="status-badge status-discharged">✅ Discharged on <?php echo date('d M Y', strtotime($adm['discharged_at'])); ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="empty-state">No admissions found under your care.</div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Right: Discharge Form -->
                <div class="form-panel" id="dischargePanel">
                    <div class="form-header">
                        <h3 id="panelTitle">Select a patient to start discharge summary</h3>
                    </div>
                    <div class="form-body" id="dischargeFormContainer">
                        <div class="empty-state">
                            <i class="fas fa-file-alt fa-3x mb-3 text-muted"></i>
                            <p>Choose an inpatient from the left panel to begin.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php include($base_path . '/modules/doctor/assets/inc/footer.php'); ?>
</div>

<div id="loadingOverlay" class="loading-overlay"><div class="spinner"></div></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
const doctorId = <?php echo $user_id; ?>;
const branchId = <?php echo $branch_id; ?>;
const csrfToken = <?php echo json_encode($csrf_token); ?>;
let currentAdmissionId = null;

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

// Load admission details when patient card clicked
function loadAdmissionDetails(admissionId) {
    currentAdmissionId = admissionId;
    showLoading(true);
    $.post(window.location.pathname + '?ajax=discharge_action', {
        action: 'get_admission_details',
        admission_id: admissionId,
        csrf_token: csrfToken,
        branch_id: branchId
    }, function(res) {
        showLoading(false);
        if (res.success && res.admission) {
            renderDischargeForm(res.admission);
        } else {
            showToast(res.message || 'Failed to load admission details', 'error');
        }
    }).fail(() => { showLoading(false); showToast('Network error', 'error'); });
}

function renderDischargeForm(admission) {
    const isDischarged = (admission.discharge_status === 'discharged');
    const panelTitle = isDischarged ? 'Discharge Summary (View Only)' : 'Complete Discharge Form';
    document.getElementById('panelTitle').innerText = `${escapeHtml(admission.patient_name)} – ${panelTitle}`;

    let recentNotesHtml = '';
    if (admission.recent_notes && admission.recent_notes.length) {
        recentNotesHtml = '<div class="section-card"><div class="section-title">Recent Progress Notes</div>';
        admission.recent_notes.forEach(note => {
            recentNotesHtml += `<div class="mb-2"><small class="text-muted">${new Date(note.created_at).toLocaleString()}</small><div>${escapeHtml(note.progress_note || note.clinical_assessment || '')}</div></div>`;
        });
        recentNotesHtml += '</div>';
    }

    const html = `
        <form id="dischargeForm">
            <input type="hidden" name="admission_id" value="${admission.admission_id}">
            <input type="hidden" name="csrf_token" value="${csrfToken}">
            <input type="hidden" name="branch_id" value="${branchId}">
            
            <!-- Admission Info -->
            <div class="section-card">
                <div class="section-title">Admission Information</div>
                <div class="info-grid">
                    <div class="info-item"><span class="info-label">Admission Number:</span> <span class="info-value">${escapeHtml(admission.admission_number)}</span></div>
                    <div class="info-item"><span class="info-label">Ward / Bed:</span> <span class="info-value">${escapeHtml(admission.ward_name || '—')} / ${escapeHtml(admission.bed_number || '—')}</span></div>
                    <div class="info-item"><span class="info-label">Admission Date:</span> <span class="info-value">${new Date(admission.admitted_at).toLocaleString()}</span></div>
                    <div class="info-item"><span class="info-label">Primary Diagnosis:</span> <span class="info-value">${escapeHtml(admission.primary_diagnosis || '—')}</span></div>
                </div>
            </div>
            
            ${recentNotesHtml}
            
            <!-- Clinical Summary -->
            <div class="section-card">
                <div class="section-title">Clinical Summary</div>
                <div class="form-group">
                    <label>Course of Illness in Hospital *</label>
                    <textarea name="clinical_course" rows="3" class="form-control" required ${isDischarged ? 'disabled' : ''}>${escapeHtml(admission.discharge_summary ? admission.discharge_summary.split('CLINICAL COURSE:')[1]?.split('FINAL DIAGNOSIS:')[0]?.trim() : '')}</textarea>
                </div>
                <div class="form-group">
                    <label>Final Diagnosis *</label>
                    <textarea name="final_diagnosis" rows="2" class="form-control" required ${isDischarged ? 'disabled' : ''}>${escapeHtml(admission.discharge_diagnosis || '')}</textarea>
                </div>
                <div class="form-group">
                    <label>Operations / Procedures Performed</label>
                    <textarea name="procedures" rows="2" class="form-control" ${isDischarged ? 'disabled' : ''}>${escapeHtml(admission.procedures || '')}</textarea>
                </div>
            </div>
            
            <!-- Discharge Condition -->
            <div class="section-card">
                <div class="section-title">Discharge Condition</div>
                <div class="radio-group">
                    <label><input type="radio" name="discharge_condition" value="improved" ${!isDischarged ? 'checked' : (admission.discharge_condition === 'improved' ? 'checked disabled' : 'disabled')}> Improved</label>
                    <label><input type="radio" name="discharge_condition" value="stabilized" ${admission.discharge_condition === 'stabilized' ? 'checked disabled' : ''} ${isDischarged ? 'disabled' : ''}> Stabilized</label>
                    <label><input type="radio" name="discharge_condition" value="relieved" ${admission.discharge_condition === 'relieved' ? 'checked disabled' : ''} ${isDischarged ? 'disabled' : ''}> Relieved</label>
                    <label><input type="radio" name="discharge_condition" value="ama" ${admission.discharge_condition === 'ama' ? 'checked disabled' : ''} ${isDischarged ? 'disabled' : ''}> Against Medical Advice (AMA)</label>
                    <label><input type="radio" name="discharge_condition" value="expired" ${admission.discharge_condition === 'expired' ? 'checked disabled' : ''} ${isDischarged ? 'disabled' : ''}> Expired</label>
                    <label><input type="radio" name="discharge_condition" value="transferred" ${admission.discharge_condition === 'transferred' ? 'checked disabled' : ''} ${isDischarged ? 'disabled' : ''}> Transferred</label>
                </div>
            </div>
            
            <!-- Discharge Instructions -->
            <div class="section-card">
                <div class="section-title">Discharge Plan & Instructions</div>
                <div class="form-group">
                    <label>Discharge Instructions *</label>
                    <textarea name="discharge_instructions" rows="3" class="form-control" required ${isDischarged ? 'disabled' : ''}>${escapeHtml(admission.discharge_instructions || '')}</textarea>
                </div>
                <div class="form-group">
                    <label>Home Medications</label>
                    <textarea name="home_medications" rows="2" class="form-control" ${isDischarged ? 'disabled' : ''}>${escapeHtml(admission.home_medications || '')}</textarea>
                </div>
                <div class="form-group">
                    <label>Warning Signs / When to Return</label>
                    <textarea name="warning_signs" rows="2" class="form-control" ${isDischarged ? 'disabled' : ''}>${escapeHtml(admission.warning_signs || '')}</textarea>
                </div>
                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label>Follow-up Date</label>
                            <input type="date" name="follow_up_date" class="form-control" value="${admission.follow_up_date || ''}" ${isDischarged ? 'disabled' : ''}>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label>Follow-up With (Dept/Doctor)</label>
                            <input type="text" name="follow_up_with" class="form-control" placeholder="e.g., Cardiology Clinic" value="${escapeHtml(admission.follow_up_with || '')}" ${isDischarged ? 'disabled' : ''}>
                        </div>
                    </div>
                </div>
            </div>
            
            ${!isDischarged ? `
            <div class="action-buttons">
                <button type="button" class="btn btn-outline" onclick="printDischargeSummary()"><i class="fas fa-print"></i> Print Draft</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Finalize Discharge</button>
            </div>
            ` : `
            <div class="action-buttons">
                <button type="button" class="btn btn-outline" onclick="printDischargeSummary()"><i class="fas fa-print"></i> Print Summary</button>
            </div>
            `}
        </form>
    `;
    document.getElementById('dischargeFormContainer').innerHTML = html;

    if (!isDischarged) {
        $('#dischargeForm').on('submit', function(e) {
            e.preventDefault();
            submitDischarge();
        });
    }
}

function submitDischarge() {
    const formData = {
        action: 'submit_discharge',
        admission_id: $('input[name="admission_id"]').val(),
        discharge_condition: $('input[name="discharge_condition"]:checked').val(),
        clinical_course: $('textarea[name="clinical_course"]').val(),
        final_diagnosis: $('textarea[name="final_diagnosis"]').val(),
        procedures: $('textarea[name="procedures"]').val(),
        discharge_instructions: $('textarea[name="discharge_instructions"]').val(),
        home_medications: $('textarea[name="home_medications"]').val(),
        warning_signs: $('textarea[name="warning_signs"]').val(),
        follow_up_date: $('input[name="follow_up_date"]').val(),
        follow_up_with: $('input[name="follow_up_with"]').val(),
        csrf_token: csrfToken,
        branch_id: branchId
    };
    if (!formData.clinical_course || !formData.final_diagnosis || !formData.discharge_instructions) {
        showToast('Please fill all required fields (Course, Final Diagnosis, Discharge Instructions)', 'error');
        return;
    }
    if (!formData.discharge_condition) {
        showToast('Please select a discharge condition', 'error');
        return;
    }
    showLoading(true);
    $.post(window.location.pathname + '?ajax=discharge_action', formData, function(res) {
        showLoading(false);
        if (res.success) {
            showToast(res.message);
            // Reload the admissions list and clear form
            setTimeout(() => location.reload(), 1500);
        } else {
            showToast(res.message || 'Discharge failed', 'error');
        }
    }).fail(() => { showLoading(false); showToast('Network error', 'error'); });
}

function printDischargeSummary() {
    // Build printable HTML
    const form = document.getElementById('dischargeForm');
    if (!form) return;
    const admissionId = $('input[name="admission_id"]').val();
    const patientName = $('#panelTitle').text().split('–')[0].trim();
    const clinicalCourse = $('textarea[name="clinical_course"]').val();
    const finalDiagnosis = $('textarea[name="final_diagnosis"]').val();
    const procedures = $('textarea[name="procedures"]').val();
    const dischargeCondition = $('input[name="discharge_condition"]:checked').val();
    const instructions = $('textarea[name="discharge_instructions"]').val();
    const homeMeds = $('textarea[name="home_medications"]').val();
    const warningSigns = $('textarea[name="warning_signs"]').val();
    const followUpDate = $('input[name="follow_up_date"]').val();
    const followUpWith = $('input[name="follow_up_with"]').val();

    const printWindow = window.open('', '_blank');
    printWindow.document.write(`
        <!DOCTYPE html>
        <html>
        <head><title>Discharge Summary - ${escapeHtml(patientName)}</title>
        <style>
            body { font-family: 'Inter', sans-serif; padding: 40px; max-width: 800px; margin: 0 auto; }
            .header { text-align: center; margin-bottom: 30px; }
            .hospital-name { font-size: 24px; font-weight: 700; color: #1a1a2e; }
            .address { font-size: 12px; color: #6b7280; }
            .title { font-size: 18px; font-weight: 700; margin: 20px 0; border-bottom: 2px solid #4361ee; padding-bottom: 5px; }
            .patient-info { background: #f3f4f6; padding: 15px; border-radius: 12px; margin-bottom: 20px; }
            .info-row { margin-bottom: 8px; }
            .label { font-weight: 600; color: #4b5563; }
            .section { margin-bottom: 20px; }
            .section h4 { font-size: 16px; font-weight: 700; margin-bottom: 8px; color: #4361ee; }
            .signature { margin-top: 40px; border-top: 1px solid #e5e7eb; padding-top: 20px; }
            @media print {
                body { padding: 0; }
                .no-print { display: none; }
            }
        </style>
        </head>
        <body>
        <div class="header">
            <div class="hospital-name">Central General Hospital</div>
            <div class="address">Bole Road, Addis Ababa, Ethiopia | Tel: +251-11-555-1000</div>
        </div>
        <div class="title">DISCHARGE SUMMARY</div>
        <div class="patient-info">
            <div class="info-row"><span class="label">Patient Name:</span> ${escapeHtml(patientName)}</div>
            <div class="info-row"><span class="label">MRN:</span> ${escapeHtml($('#dischargeForm input[name="admission_id"]').closest('.patient-card')?.find('.patient-mrn')?.text() || 'N/A')}</div>
            <div class="info-row"><span class="label">Admission ID:</span> ${admissionId}</div>
            <div class="info-row"><span class="label">Discharge Date:</span> ${new Date().toLocaleDateString()}</div>
        </div>
        <div class="section"><h4>Clinical Course</h4><p>${escapeHtml(clinicalCourse).replace(/\n/g, '<br>')}</p></div>
        <div class="section"><h4>Final Diagnosis</h4><p>${escapeHtml(finalDiagnosis).replace(/\n/g, '<br>')}</p></div>
        ${procedures ? `<div class="section"><h4>Procedures Performed</h4><p>${escapeHtml(procedures).replace(/\n/g, '<br>')}</p></div>` : ''}
        <div class="section"><h4>Discharge Condition</h4><p>${escapeHtml(dischargeCondition)}</p></div>
        <div class="section"><h4>Discharge Instructions</h4><p>${escapeHtml(instructions).replace(/\n/g, '<br>')}</p></div>
        ${homeMeds ? `<div class="section"><h4>Home Medications</h4><p>${escapeHtml(homeMeds).replace(/\n/g, '<br>')}</p></div>` : ''}
        ${warningSigns ? `<div class="section"><h4>Warning Signs / When to Return</h4><p>${escapeHtml(warningSigns).replace(/\n/g, '<br>')}</p></div>` : ''}
        <div class="section"><h4>Follow-up Plan</h4><p>${followUpDate ? `Date: ${followUpDate}<br>` : ''}${followUpWith ? `With: ${escapeHtml(followUpWith)}` : 'None specified'}</p></div>
        <div class="signature">
            <div>_________________________</div>
            <div>Physician Signature</div>
            <div>Dr. <?php echo $doctor_name; ?></div>
        </div>
        </body>
        </html>
    `);
    printWindow.document.close();
    printWindow.print();
}

// Event delegation for patient cards
document.getElementById('admissionsList').addEventListener('click', function(e) {
    const card = e.target.closest('.patient-card');
    if (card) {
        document.querySelectorAll('.patient-card').forEach(c => c.classList.remove('active'));
        card.classList.add('active');
        const admissionId = card.getAttribute('data-admission-id');
        loadAdmissionDetails(admissionId);
    }
});
</script>
</body>
</html>