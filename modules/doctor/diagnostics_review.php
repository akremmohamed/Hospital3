<?php
/**
 * ======================================================================
 * REAL-TIME DIAGNOSTICS REVIEW HUB - diagnostics_review.php
 * For Doctor Module – Displays lab & radiology results, critical alerts,
 * physician review signing.
 * Strictly aligned with hms_erp.sql schema (patients.mr_number, lab_test_results)
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

// permission: view_diagnostic_results OR start_consultation (fallback)
if (!$is_global_admin && !user_has_permission($pdo, 'view_diagnostic_results', $user_id)
    && !user_has_permission($pdo, 'start_consultation', $user_id)) {
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
// 2. DETECT RADIOLOGY TABLES (optional)
// ============================================
$radiology_tables_exist = false;
try {
    $stmt = $pdo->query("SHOW TABLES LIKE 'radiology_orders'");
    $radiology_tables_exist = $stmt->rowCount() > 0;
} catch (PDOException $e) {
    $radiology_tables_exist = false;
}

// ============================================
// 3. AJAX HANDLERS (transaction-safe, type‑safe)
// ============================================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'review_action') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(false, 'Invalid request method');
    }
    $action = $_POST['action'] ?? '';
    $csrf_actions = ['fetch_review_queue', 'load_order_details', 'save_review_notes'];
    if (in_array($action, $csrf_actions)) {
        if (!isset($_POST['csrf_token']) || !hash_equals($csrf_token, (string)$_POST['csrf_token'])) {
            jsonResponse(false, 'CSRF mismatch');
        }
    }

    try {
        // ---------- 3.1 FETCH REVIEW QUEUE (Lab + Radiology) ----------
        if ($action === 'fetch_review_queue') {
            $queue = [];  // ensure array for PHP 8.2+

            // ---- LAB ORDERS (completed or pending_review) ----
            $sql_lab = "
                SELECT 
                    lo.id AS order_id,
                    'lab' AS order_type,
                    lo.order_number,
                    lo.order_date,
                    lo.status AS order_status,
                    lo.priority,
                    lo.patient_id,
                    CONCAT(p.first_name, ' ', p.last_name) AS patient_name,
                    p.mr_number,
                    COALESCE((
                        SELECT COUNT(*) FROM lab_test_results ltr
                        WHERE ltr.lab_order_id = lo.id AND ltr.deleted_at IS NULL
                    ), 0) AS test_count,
                    COALESCE((
                        SELECT MAX(ltr.is_critical) FROM lab_test_results ltr
                        WHERE ltr.lab_order_id = lo.id AND ltr.deleted_at IS NULL
                    ), 0) AS has_critical
                FROM lab_orders lo
                JOIN patients p ON lo.patient_id = p.id AND p.deleted_at IS NULL
                WHERE lo.doctor_id = :doctor_id
                  AND lo.status IN ('completed', 'pending_review')
                  AND (lo.deleted_at IS NULL OR lo.deleted_at = '0000-00-00 00:00:00')
            ";
            $params = [':doctor_id' => $user_id];
            if (!$is_global_admin && $branch_id > 0) {
                $sql_lab .= " AND lo.branch_id = :branch_id";
                $params[':branch_id'] = $branch_id;
            }
            $sql_lab .= " ORDER BY lo.order_date DESC";
            $stmt = $pdo->prepare($sql_lab);
            $stmt->execute($params);
            $lab_queue = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (is_array($lab_queue)) {
                $queue = array_merge($queue, $lab_queue);
            } else {
                $lab_queue = []; // safety
            }

            // ---- RADIOLOGY ORDERS (if table exists) ----
            if ($radiology_tables_exist) {
                $sql_rad = "
                    SELECT 
                        ro.id AS order_id,
                        'radiology' AS order_type,
                        ro.order_number,
                        ro.order_date,
                        ro.status AS order_status,
                        ro.priority,
                        ro.patient_id,
                        CONCAT(p.first_name, ' ', p.last_name) AS patient_name,
                        p.mr_number,
                        1 AS test_count,
                        0 AS has_critical
                    FROM radiology_orders ro
                    JOIN patients p ON ro.patient_id = p.id AND p.deleted_at IS NULL
                    WHERE ro.doctor_id = :doctor_id2
                      AND ro.status IN ('completed', 'pending_review')
                      AND (ro.deleted_at IS NULL OR ro.deleted_at = '0000-00-00 00:00:00')
                ";
                $params2 = [':doctor_id2' => $user_id];
                if (!$is_global_admin && $branch_id > 0) {
                    $sql_rad .= " AND ro.branch_id = :branch_id2";
                    $params2[':branch_id2'] = $branch_id;
                }
                $sql_rad .= " ORDER BY ro.order_date DESC";
                $stmt2 = $pdo->prepare($sql_rad);
                $stmt2->execute($params2);
                $rad_queue = $stmt2->fetchAll(PDO::FETCH_ASSOC);
                if (is_array($rad_queue)) {
                    $queue = array_merge($queue, $rad_queue);
                }
            }

            // sort combined queue by order_date (most recent first)
            usort($queue, function($a, $b) {
                return strtotime($b['order_date']) - strtotime($a['order_date']);
            });

            jsonResponse(true, '', ['queue' => $queue]);
        }

        // ---------- 3.2 LOAD ORDER DETAILS (lab or radiology) ----------
        if ($action === 'load_order_details') {
            $order_id = (int)($_POST['order_id'] ?? 0);
            $order_type = $_POST['order_type'] ?? '';
            if ($order_id <= 0 || !in_array($order_type, ['lab', 'radiology'])) {
                jsonResponse(false, 'Invalid parameters');
            }

            $details = [];
            $details['order_type'] = $order_type;

            if ($order_type === 'lab') {
                // Fetch lab order header
                $sql = "SELECT lo.*, 
                               CONCAT(p.first_name, ' ', p.last_name) AS patient_name,
                               p.mr_number, p.date_of_birth, p.gender
                        FROM lab_orders lo
                        JOIN patients p ON lo.patient_id = p.id AND p.deleted_at IS NULL
                        WHERE lo.id = :id AND lo.doctor_id = :doctor_id
                          AND (lo.deleted_at IS NULL OR lo.deleted_at = '0000-00-00 00:00:00')";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([':id' => $order_id, ':doctor_id' => $user_id]);
                $header = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$header) {
                    jsonResponse(false, 'Lab order not found or access denied');
                }
                $details['header'] = $header;

                // Fetch test results from lab_test_results
                $sql_items = "
                    SELECT 
                        ltr.id AS item_id,
                        ltr.test_name,
                        ltr.result_numeric,
                        ltr.result_text,
                        ltr.result_unit AS unit,
                        ltr.is_critical,
                        ltr.reference_range_low,
                        ltr.reference_range_high,
                        ltr.reference_range_text,
                        ltr.technician_notes,
                        ltr.verified_at,
                        ltr.verified_by_user_id
                    FROM lab_test_results ltr
                    WHERE ltr.lab_order_id = :order_id
                      AND (ltr.deleted_at IS NULL OR ltr.deleted_at = '0000-00-00 00:00:00')
                    ORDER BY ltr.test_name ASC
                ";
                $stmt2 = $pdo->prepare($sql_items);
                $stmt2->execute([':order_id' => $order_id]);
                $items = $stmt2->fetchAll(PDO::FETCH_ASSOC);
                $details['items'] = is_array($items) ? $items : [];
                $details['physician_review_notes'] = '';
            }

            if ($order_type === 'radiology') {
                if (!$radiology_tables_exist) {
                    jsonResponse(false, 'Radiology module not available');
                }
                $sql = "
                    SELECT 
                        ro.*,
                        CONCAT(p.first_name, ' ', p.last_name) AS patient_name,
                        p.mr_number, p.date_of_birth, p.gender,
                        rs.service_name, rs.service_code, rs.category
                    FROM radiology_orders ro
                    JOIN patients p ON ro.patient_id = p.id AND p.deleted_at IS NULL
                    LEFT JOIN radiology_services rs ON ro.service_id = rs.id AND rs.deleted_at IS NULL
                    WHERE ro.id = :id AND ro.doctor_id = :doctor_id
                      AND (ro.deleted_at IS NULL OR ro.deleted_at = '0000-00-00 00:00:00')
                ";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([':id' => $order_id, ':doctor_id' => $user_id]);
                $header = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$header) {
                    jsonResponse(false, 'Radiology order not found or access denied');
                }
                $details['header'] = $header;
                $details['physician_review_notes'] = $header['physician_review_notes'] ?? '';
                $details['items'] = [];
            }

            jsonResponse(true, '', ['details' => $details]);
        }

        // ---------- 3.3 SAVE PHYSICIAN REVIEW NOTES (radiology only) ----------
        if ($action === 'save_review_notes') {
            $order_id = (int)($_POST['order_id'] ?? 0);
            $order_type = $_POST['order_type'] ?? '';
            $review_notes = trim($_POST['review_notes'] ?? '');
            if ($order_id <= 0 || $order_type !== 'radiology') {
                jsonResponse(false, 'Only radiology orders support review notes');
            }
            if (!$radiology_tables_exist) {
                jsonResponse(false, 'Radiology module not available');
            }

            try {
                $pdo->beginTransaction();

                // Verify ownership
                $check = $pdo->prepare("SELECT id FROM radiology_orders WHERE id = ? AND doctor_id = ? AND deleted_at IS NULL");
                $check->execute([$order_id, $user_id]);
                if (!$check->fetch()) {
                    $pdo->rollBack();
                    jsonResponse(false, 'Order not found or access denied');
                }

                $update = $pdo->prepare("UPDATE radiology_orders SET physician_review_notes = ?, status = 'reviewed', reviewed_at = NOW() WHERE id = ?");
                $update->execute([$review_notes, $order_id]);
                $pdo->commit();
                jsonResponse(true, 'Review notes saved successfully');
            } catch (PDOException $e) {
                $pdo->rollBack();
                jsonResponse(false, 'Database error: ' . $e->getMessage());
            }
        }

        jsonResponse(false, 'Unsupported action');
    } catch (PDOException $e) {
        // Return safe empty data to prevent frontend type errors
        jsonResponse(false, 'Database error: ' . $e->getMessage());
    } catch (Exception $e) {
        jsonResponse(false, 'Error: ' . $e->getMessage());
    }
    exit;
}

// ============================================
// 4. INITIAL PAGE DATA (just for template)
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
    <title>Diagnostics Review Hub - HMS ERP</title>
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

        .review-container {
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
        .queue-item {
            padding: 14px 16px;
            border-bottom: 1px solid #f0f0f0;
            cursor: pointer;
            transition: all 0.2s;
            border-left: 3px solid transparent;
        }
        .queue-item:hover { background: var(--light-gray); }
        .queue-item.active {
            background: #eef2ff;
            border-left-color: var(--primary);
        }
        .queue-item-critical {
            background: #fff0f0;
            border-left-color: var(--danger);
        }
        .queue-patient-name {
            font-weight: 600;
            font-size: 15px;
            margin-bottom: 4px;
            display: flex;
            justify-content: space-between;
        }
        .queue-mrn {
            font-size: 11px;
            color: var(--gray);
        }
        .badge-critical {
            background: var(--danger);
            color: white;
            font-size: 10px;
            padding: 2px 6px;
            border-radius: 12px;
            animation: pulse 1s infinite;
        }
        @keyframes pulse { 0% { opacity: 0.7; } 50% { opacity: 1; } 100% { opacity: 0.7; } }

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

        .lab-result-table {
            width: 100%;
            border-collapse: collapse;
        }
        .lab-result-table th, .lab-result-table td {
            padding: 12px 10px;
            text-align: left;
            border-bottom: 1px solid var(--border-gray);
            font-size: 13px;
        }
        .lab-result-table th {
            background: #f8fafc;
            font-weight: 600;
        }
        .critical-row {
            background-color: #fee2e2;
            border-left: 3px solid var(--danger);
        }
        .critical-badge {
            background: var(--danger);
            color: white;
            font-size: 11px;
            padding: 2px 8px;
            border-radius: 20px;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            animation: blink 0.8s infinite;
        }
        @keyframes blink { 0%,100%{ opacity:1; } 50%{ opacity:0.6; } }

        .radiology-report {
            background: var(--light-gray);
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 20px;
        }
        .report-text {
            white-space: pre-wrap;
            font-family: monospace;
            background: white;
            padding: 16px;
            border-radius: 12px;
            border: 1px solid var(--border-gray);
            max-height: 300px;
            overflow-y: auto;
        }
        .attachment-link {
            display: inline-block;
            margin-top: 12px;
            background: var(--primary);
            color: white;
            padding: 8px 16px;
            border-radius: 12px;
            text-decoration: none;
            font-size: 13px;
        }
        .review-box {
            margin-top: 24px;
            border-top: 1px solid var(--border-gray);
            padding-top: 20px;
        }
        .review-box textarea {
            width: 100%;
            border-radius: 12px;
            border: 1px solid var(--border-gray);
            padding: 12px;
            font-size: 13px;
        }
        .btn-save-review {
            background: var(--primary);
            color: white;
            border: none;
            padding: 8px 20px;
            border-radius: 12px;
            font-weight: 600;
            margin-top: 12px;
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
        .stat-alert {
            background: #fee2e2;
            border-left: 4px solid var(--danger);
            padding: 12px;
            margin-bottom: 16px;
            border-radius: 12px;
            animation: blinkBg 1s infinite;
        }
        @keyframes blinkBg { 0% { background-color: #fee2e2; } 50% { background-color: #ffcccc; } 100% { background-color: #fee2e2; } }
    </style>
</head>
<body>
<div id="wrapper">
    <?php include($base_path . '/modules/doctor/assets/inc/nav.php'); ?>
    <?php include($base_path . '/modules/doctor/assets/inc/sidebar.php'); ?>

    <div class="content-page">
        <div class="container-fluid">
            <div class="page-header">
                <div class="page-title"><i class="fas fa-microscope me-2"></i> Real‑time Diagnostics Review Hub</div>
                <div class="page-subtitle">Review lab results and radiology reports, acknowledge critical values, and sign off.</div>
            </div>

            <div class="review-container">
                <!-- Left Sidebar: Queue -->
                <div class="queue-sidebar">
                    <div class="queue-header">
                        <h3><i class="fas fa-list-ul me-2"></i> Diagnostic Orders</h3>
                    </div>
                    <div class="queue-search">
                        <input type="text" id="queueSearch" placeholder="Search patient, MRN, order #..." class="form-control">
                    </div>
                    <div class="queue-list" id="queueList">
                        <div class="text-center p-4 text-muted">Loading orders...</div>
                    </div>
                </div>

                <!-- Right Panel: Result Viewer -->
                <div class="result-panel" id="resultPanel">
                    <div class="panel-header">
                        <h3 id="panelTitle">Diagnostic Profile Viewer</h3>
                    </div>
                    <div class="panel-tabs" id="panelTabs" style="display: none;">
                        <button class="tab-btn active" data-tab="lab-tab">🔬 Laboratory Results</button>
                        <button class="tab-btn" data-tab="rad-tab">🩻 Radiology Report</button>
                    </div>
                    <div class="tab-content" id="tabContent">
                        <div class="tab-pane active" id="lab-tab">
                            <div id="labResultsContainer" class="empty-state">
                                <i class="fas fa-flask fa-2x mb-2"></i>
                                <p>Select a diagnostic order from the queue</p>
                            </div>
                        </div>
                        <div class="tab-pane" id="rad-tab">
                            <div id="radiologyContainer" class="empty-state">
                                <i class="fas fa-x-ray fa-2x mb-2"></i>
                                <p>Select a radiology order</p>
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
let currentOrder = null; // { order_id, order_type }

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

function fetchReviewQueue() {
    showLoading(true);
    $.post(window.location.pathname + '?ajax=review_action', {
        action: 'fetch_review_queue',
        csrf_token: csrfToken,
        branch_id: branchId
    }, function(res) {
        showLoading(false);
        if (res.success && res.queue) {
            renderQueue(res.queue);
        } else {
            $('#queueList').html('<div class="text-center p-4 text-muted">Error loading queue</div>');
        }
    }).fail(() => { showLoading(false); showToast('Failed to load queue','error'); });
}

function renderQueue(queue) {
    const container = $('#queueList');
    container.empty();
    if (!queue.length) {
        container.html('<div class="text-center p-4 text-muted">No pending diagnostic orders</div>');
        return;
    }
    let html = '';
    queue.forEach(item => {
        const criticalClass = (item.has_critical == 1) ? 'queue-item-critical' : '';
        const criticalBadge = (item.has_critical == 1) ? '<span class="badge-critical"><i class="fas fa-exclamation-triangle fa-fw"></i> CRITICAL</span>' : '';
        html += `
            <div class="queue-item ${criticalClass}" data-order-id="${item.order_id}" data-order-type="${item.order_type}">
                <div class="queue-patient-name">
                    ${escapeHtml(item.patient_name)}
                    ${criticalBadge}
                </div>
                <div class="queue-mrn">${escapeHtml(item.mr_number)} | Order: ${escapeHtml(item.order_number)} | ${item.order_date}</div>
                <div class="small text-muted mt-1">${item.order_type === 'lab' ? 'Laboratory' : 'Radiology'} · ${item.test_count} test(s)</div>
            </div>
        `;
    });
    container.html(html);
    $('.queue-item').on('click', function() {
        const orderId = $(this).data('order-id');
        const orderType = $(this).data('order-type');
        $('.queue-item').removeClass('active');
        $(this).addClass('active');
        loadOrderDetails(orderId, orderType);
    });
}

function loadOrderDetails(orderId, orderType) {
    showLoading(true);
    $.post(window.location.pathname + '?ajax=review_action', {
        action: 'load_order_details',
        order_id: orderId,
        order_type: orderType,
        csrf_token: csrfToken,
        branch_id: branchId
    }, function(res) {
        showLoading(false);
        if (res.success && res.details) {
            currentOrder = { order_id: orderId, order_type: orderType };
            displayOrderDetails(res.details);
        } else {
            showToast(res.message || 'Failed to load details', 'error');
        }
    }).fail(() => { showLoading(false); showToast('Network error','error'); });
}

function displayOrderDetails(details) {
    const orderType = details.order_type;
    const header = details.header;
    $('#panelTitle').html(`${escapeHtml(header.patient_name)} (${escapeHtml(header.mr_number)}) – ${orderType === 'lab' ? 'Lab Results' : 'Radiology Report'}`);

    if (orderType === 'lab') {
        // Remove existing stat-alert if any
        $('#lab-tab .stat-alert').remove();
        if (header.priority === 'stat') {
            $('#lab-tab').prepend(`<div class="stat-alert"><i class="fas fa-exclamation-triangle fa-beat-fw me-2"></i> <strong>STAT ORDER</strong> – This order was marked as urgent. Please review immediately.</div>`);
        }
        $('#panelTabs').show();
        $('.tab-btn[data-tab="lab-tab"]').show();
        $('.tab-btn[data-tab="rad-tab"]').hide();
        // force lab tab active
        $('.tab-btn[data-tab="lab-tab"]').trigger('click');
        renderLabResults(details);
    } else if (orderType === 'radiology') {
        $('#panelTabs').show();
        $('.tab-btn[data-tab="lab-tab"]').hide();
        $('.tab-btn[data-tab="rad-tab"]').show();
        $('.tab-btn[data-tab="rad-tab"]').trigger('click');
        renderRadiology(details);
    }
}

function renderLabResults(details) {
    const items = details.items || [];
    if (!items.length) {
        $('#labResultsContainer').html('<div class="empty-state"><i class="fas fa-vial fa-2x mb-2"></i><p>No test results available</p></div>');
        return;
    }
    let html = '<table class="lab-result-table"><thead><tr><th>Test Name</th><th>Result</th><th>Reference Range</th><th>Unit</th><th>Status</th></tr></thead><tbody>';
    items.forEach(item => {
        const criticalRow = (item.is_critical == 1) ? 'critical-row' : '';
        const criticalFlag = (item.is_critical == 1) ? '<span class="critical-badge"><i class="fas fa-skull-crosswalk fa-beat-fw"></i> CRITICAL VALUE</span>' : '';
        let resultDisplay = '—';
        if (item.result_numeric !== null && item.result_numeric !== '') resultDisplay = item.result_numeric;
        else if (item.result_text) resultDisplay = escapeHtml(item.result_text);
        let refRange = '—';
        if (item.reference_range_low !== null && item.reference_range_high !== null) refRange = `${item.reference_range_low} – ${item.reference_range_high}`;
        else if (item.reference_range_text) refRange = escapeHtml(item.reference_range_text);
        html += `<tr class="${criticalRow}">
            <td>${escapeHtml(item.test_name)} ${criticalFlag}</td>
            <td><strong>${resultDisplay}</strong></td>
            <td>${refRange}</td>
            <td>${escapeHtml(item.unit || '—')}</td>
            <td>Completed</td>
        </tr>`;
    });
    html += '</tbody></table>';
    if (details.header.clinical_indication) {
        html += `<div class="mt-3 p-2 bg-light rounded"><strong>Clinical Indication:</strong> ${escapeHtml(details.header.clinical_indication)}</div>`;
    }
    $('#labResultsContainer').html(html);
}

function renderRadiology(details) {
    const header = details.header;
    let html = `
        <div class="radiology-report">
            <div><strong>Service:</strong> ${escapeHtml(header.service_name || header.service_code || 'Radiology')}</div>
            <div class="mt-2"><strong>Clinical History:</strong> ${escapeHtml(header.clinical_history || '—')}</div>
            <div class="mt-2"><strong>Priority:</strong> ${escapeHtml(header.priority)}</div>
            <div class="mt-2"><strong>Order Status:</strong> ${escapeHtml(header.status)}</div>
        </div>
    `;
    if (header.interpretation_report || header.findings) {
        html += `<div class="mt-3"><strong>Interpretation Report / Findings</strong><div class="report-text mt-1">${escapeHtml(header.interpretation_report || header.findings || '—')}</div></div>`;
    } else {
        html += `<div class="empty-state mt-3">No report text available.</div>`;
    }
    if (header.image_attachment_path) {
        html += `<div class="mt-3"><a href="${escapeHtml(header.image_attachment_path)}" target="_blank" class="attachment-link"><i class="fas fa-image me-1"></i> View Attached Imaging (DICOM / Scan)</a></div>`;
    }
    // Physician review form
    html += `
        <div class="review-box">
            <label><strong>Physician Review Notes</strong></label>
            <textarea id="reviewNotes" rows="4" placeholder="Add your clinical interpretation, follow-up recommendations...">${escapeHtml(details.physician_review_notes || '')}</textarea>
            <button id="saveReviewBtn" class="btn-save-review" data-order-id="${header.id}" data-order-type="radiology"><i class="fas fa-signature"></i> Sign & Save Review</button>
        </div>
    `;
    $('#radiologyContainer').html(html);
    $('#saveReviewBtn').off('click').on('click', function() {
        const orderId = $(this).data('order-id');
        const reviewNotes = $('#reviewNotes').val();
        saveReviewNotes(orderId, reviewNotes);
    });
}

function saveReviewNotes(orderId, reviewNotes) {
    showLoading(true);
    $.post(window.location.pathname + '?ajax=review_action', {
        action: 'save_review_notes',
        order_id: orderId,
        order_type: 'radiology',
        review_notes: reviewNotes,
        csrf_token: csrfToken,
        branch_id: branchId
    }, function(res) {
        showLoading(false);
        if (res.success) {
            showToast(res.message);
            // refresh queue to update status
            fetchReviewQueue();
        } else {
            showToast(res.message || 'Failed to save review', 'error');
        }
    }).fail(() => { showLoading(false); showToast('Network error','error'); });
}

$(document).ready(function() {
    initTabs();
    fetchReviewQueue();
    $('#queueSearch').on('keyup', function() {
        const term = $(this).val().toLowerCase();
        $('.queue-item').each(function() {
            const text = $(this).text().toLowerCase();
            $(this).toggle(text.indexOf(term) > -1);
        });
    });
    setInterval(fetchReviewQueue, 30000);
});
</script>
</body>
</html>