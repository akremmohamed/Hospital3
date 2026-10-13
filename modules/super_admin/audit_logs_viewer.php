<?php
/**
 * Forensic Audit Logs Viewer
 * Super Admin Module: Complete audit trail with side-by-side comparison, IP/agent tracking,
 * and multi‑branch isolation.
 *
 * Strictly based on hms_erp.sql schema. Uses `audit_logs`, `users`, `branches` tables.
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
    die(json_encode(['status' => 'unauthorized', 'message' => 'Access denied.']));
}

require_once __DIR__ . '/../../config/db_connection.php';
require_once __DIR__ . '/../../includes/auth_helper.php';

date_default_timezone_set('Africa/Addis_Ababa');
$pdo = getDB();
if (!$pdo) die('Database connection failed.');

// CSRF token for any POST mutation (not strictly needed for GETs, but kept for future)
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// ============================================
// 2. AJAX ROUTER
// ============================================
if (isset($_GET['action'])) {
    error_reporting(0);
    ini_set('display_errors', 0);
    if (ob_get_level()) ob_clean();
    header('Content-Type: application/json; charset=utf-8');

    $action = $_GET['action'];

    // ---------- DataTables Server‑Side Data ----------
    if ($action === 'get_audit_logs_data') {
        $draw   = (int) ($_GET['draw'] ?? 1);
        $start  = max(0, (int) ($_GET['start'] ?? 0));
        $length = min(500, (int) ($_GET['length'] ?? 25));
        $search = trim($_GET['search']['value'] ?? '');

        // Additional filters from UI
        $branchFilter   = (int) ($_GET['branch_id'] ?? 0);
        $tableFilter    = trim($_GET['target_table'] ?? '');
        $actionFilter   = trim($_GET['action_type'] ?? '');

        $where = [];
        $params = [];

        // Base where (no deleted_at column in audit_logs)
        if ($branchFilter > 0) {
            $where[] = "al.branch_id = :branch_id";
            $params[':branch_id'] = $branchFilter;
        }
        if (!empty($tableFilter)) {
            $where[] = "al.target_table = :target_table";
            $params[':target_table'] = $tableFilter;
        }
        if (!empty($actionFilter)) {
            $where[] = "al.action_type = :action_type";
            $params[':action_type'] = $actionFilter;
        }
        if ($search !== '') {
            $where[] = "(CONCAT(u.first_name, ' ', u.last_name) LIKE :search OR al.action_type LIKE :search OR al.target_table LIKE :search OR al.ip_address LIKE :search)";
            $params[':search'] = "%$search%";
        }

        $whereClause = empty($where) ? '' : 'WHERE ' . implode(' AND ', $where);

        // Total count (without filters)
        $totalStmt = $pdo->query("SELECT COUNT(*) FROM audit_logs al");
        $totalRecords = (int) $totalStmt->fetchColumn();

        // Filtered count
        $filteredSql = "SELECT COUNT(*) FROM audit_logs al LEFT JOIN users u ON al.user_id = u.id $whereClause";
        $filteredStmt = $pdo->prepare($filteredSql);
        $filteredStmt->execute($params);
        $filteredRecords = (int) $filteredStmt->fetchColumn();

        // Data query
        $sql = "
            SELECT al.id, al.action_type, al.target_table, al.record_id, al.ip_address, al.user_agent, al.created_at,
                   al.old_values, al.new_values,
                   CONCAT(u.first_name, ' ', u.last_name) AS user_name,
                   b.name AS branch_name
            FROM audit_logs al
            LEFT JOIN users u ON al.user_id = u.id
            LEFT JOIN branches b ON al.branch_id = b.id
            $whereClause
            ORDER BY al.created_at DESC
            LIMIT :limit OFFSET :offset
        ";
        $stmt = $pdo->prepare($sql);
        foreach ($params as $key => $val) {
            $stmt->bindValue($key, $val);
        }
        $stmt->bindValue(':limit', $length, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $start, PDO::PARAM_INT);
        $stmt->execute();
        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $data = [];
        foreach ($logs as $log) {
            // Build action badge
            $actionBadge = match ($log['action_type']) {
                'INSERT' => '<span class="badge bg-success">INSERT</span>',
                'UPDATE' => '<span class="badge bg-warning text-dark">UPDATE</span>',
                'DELETE' => '<span class="badge bg-danger">DELETE</span>',
                'LOGIN'  => '<span class="badge bg-info">LOGIN</span>',
                'LOGOUT' => '<span class="badge bg-secondary">LOGOUT</span>',
                default  => '<span class="badge bg-secondary">' . htmlspecialchars($log['action_type']) . '</span>',
            };

            // Decode user agent for display (client‑side will refine)
            $userAgentShort = substr($log['user_agent'] ?? '', 0, 60);
            $userAgentFull = htmlspecialchars($log['user_agent'] ?? 'N/A');

            $data[] = [
                'id'            => $log['id'],
                'timestamp'     => date('Y-m-d H:i:s', strtotime($log['created_at'])),
                'user'          => htmlspecialchars($log['user_name'] ?? 'System'),
                'branch'        => htmlspecialchars($log['branch_name'] ?? '—'),
                'action'        => $actionBadge,
                'target_table'  => htmlspecialchars($log['target_table']),
                'record_id'     => (int) $log['record_id'],
                'ip'            => htmlspecialchars($log['ip_address']),
                'user_agent'    => $userAgentFull,
                'user_agent_short' => $userAgentShort,
                'has_diff'      => ($log['action_type'] === 'UPDATE' && (!empty($log['old_values']) || !empty($log['new_values']))) ? 1 : 0,
                'old_values'    => $log['old_values'],
                'new_values'    => $log['new_values'],
            ];
        }

        echo json_encode([
            'draw'            => $draw,
            'recordsTotal'    => $totalRecords,
            'recordsFiltered' => $filteredRecords,
            'data'            => $data,
        ]);
        exit;
    }

    // ---------- Fetch Diff (Side‑by‑Side Comparison) ----------
    if ($action === 'fetch_diff') {
        $logId = (int) ($_GET['log_id'] ?? 0);
        if ($logId <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid log ID']);
            exit;
        }
        $stmt = $pdo->prepare("SELECT action_type, old_values, new_values, target_table, record_id FROM audit_logs WHERE id = :id");
        $stmt->execute([':id' => $logId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            echo json_encode(['success' => false, 'message' => 'Log entry not found']);
            exit;
        }

        $old = json_decode($row['old_values'], true);
        $new = json_decode($row['new_values'], true);

        // For INSERT actions, old is null; for DELETE new is null.
        $response = [
            'success' => true,
            'action' => $row['action_type'],
            'target' => $row['target_table'],
            'record_id' => $row['record_id'],
            'old' => is_array($old) ? $old : [],
            'new' => is_array($new) ? $new : []
        ];
        echo json_encode($response);
        exit;
    }

    // ---------- Get Distinct Target Tables for Filter ----------
    if ($action === 'get_target_tables') {
        $stmt = $pdo->query("SELECT DISTINCT target_table FROM audit_logs WHERE target_table IS NOT NULL ORDER BY target_table");
        $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
        echo json_encode(['success' => true, 'tables' => $tables]);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Invalid action']);
    exit;
}

// ============================================
// 3. PREPARE FILTER DROPDOWNS (Branches, target tables, action types)
// ============================================
$branches = [];
$targetTables = [];
$actionTypes = ['INSERT', 'UPDATE', 'DELETE', 'LOGIN', 'LOGOUT'];

try {
    $branchStmt = $pdo->query("SELECT id, name FROM branches WHERE deleted_at IS NULL AND status = 'active' ORDER BY name");
    $branches = $branchStmt->fetchAll(PDO::FETCH_ASSOC);

    $tableStmt = $pdo->query("SELECT DISTINCT target_table FROM audit_logs WHERE target_table IS NOT NULL ORDER BY target_table");
    $targetTables = $tableStmt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    // Fallback to empty arrays, but error already logged
}

$current_date_display = date('l, F j, Y');
$current_time_display = date('g:i A');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forensic Audit Logs Viewer | Super Admin | HMS ERP</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
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
        body { font-family: 'Inter', sans-serif; background: #f5f7fb; overflow-x: hidden; margin: 0; padding: 0; }
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
        body.erp-sidebar-collapsed .content-page { margin-left: 80px; }
        @media (max-width: 768px) {
            .content-page { margin-left: 0 !important; padding: 16px; }
        }
        .welcome-header {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--primary) 100%);
            border-radius: 24px;
            padding: 24px 32px;
            color: white;
            margin-bottom: 28px;
        }
        .filter-card {
            background: white;
            border-radius: 20px;
            padding: 20px;
            margin-bottom: 24px;
            border: 1px solid var(--gray-light);
        }
        .badge-diff {
            font-size: 11px;
            padding: 4px 8px;
            border-radius: 12px;
        }
        .bg-light-danger {
            background-color: #f8d7da;
            text-decoration: line-through;
            color: #721c24;
        }
        .bg-light-success {
            background-color: #d4edda;
            color: #155724;
        }
        .diff-table td {
            padding: 8px;
            border-bottom: 1px solid #e9ecef;
        }
        .diff-table th {
            background: #f1f5f9;
            padding: 10px;
        }
        .ua-badge {
            background: #e9ecef;
            border-radius: 30px;
            padding: 4px 12px;
            font-size: 11px;
            font-family: monospace;
            cursor: help;
        }
        .dataTables_wrapper {
            overflow-x: auto;
        }
        .btn-diff {
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
        pre { background: #f8f9fa; padding: 12px; border-radius: 12px; font-size: 12px; overflow-x: auto; }
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
                        <div class="welcome-title"><i class="fas fa-shield-alt me-2"></i>Forensic Audit Logs Viewer</div>
                        <div class="welcome-subtitle">Complete system mutation trail with side‑by‑side comparison, IP/agent tracking, and multi‑branch isolation.</div>
                    </div>
                    <div class="col-md-4 text-md-end mt-3 mt-md-0">
                        <div class="time-badge" style="background: rgba(255,255,255,0.15); padding: 8px 16px; border-radius: 12px;">
                            <i class="far fa-calendar-alt"></i> <?php echo htmlspecialchars($current_date_display); ?>
                            <i class="far fa-clock ms-2"></i> <?php echo htmlspecialchars($current_time_display); ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Filter Bar -->
            <div class="filter-card">
                <div class="row g-3 align-items-end">
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">Branch</label>
                        <select id="branchFilter" class="form-select">
                            <option value="0">All Branches</option>
                            <?php foreach ($branches as $branch): ?>
                                <option value="<?php echo $branch['id']; ?>"><?php echo htmlspecialchars($branch['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">Target Table</label>
                        <select id="tableFilter" class="form-select">
                            <option value="">All Tables</option>
                            <?php foreach ($targetTables as $table): ?>
                                <option value="<?php echo htmlspecialchars($table); ?>"><?php echo htmlspecialchars($table); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">Action Type</label>
                        <select id="actionFilter" class="form-select">
                            <option value="">All Actions</option>
                            <?php foreach ($actionTypes as $type): ?>
                                <option value="<?php echo $type; ?>"><?php echo $type; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <button id="refreshBtn" class="btn btn-primary w-100"><i class="fas fa-sync-alt me-2"></i>Refresh Logs</button>
                    </div>
                </div>
            </div>

            <!-- Audit Logs Table -->
            <div class="card-custom">
                <div class="card-header-custom d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="fas fa-history me-2 text-primary"></i>System Mutation Trail</h5>
                    <span class="badge bg-secondary" id="totalRecordsBadge">0 records</span>
                </div>
                <div class="p-3">
                    <table id="auditTable" class="table table-hover" style="width:100%">
                        <thead>
                            <tr>
                                <th>Timestamp</th>
                                <th>User</th>
                                <th>Branch</th>
                                <th>Action</th>
                                <th>Target Table</th>
                                <th>Record ID</th>
                                <th>IP Address</th>
                                <th>User Agent</th>
                                <th>Details</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <?php include('assets/inc/footer.php'); ?>
</div>

<!-- Modal: Side‑by‑Side Diff Viewer -->
<div class="modal fade" id="diffModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-light">
                <h5 class="modal-title"><i class="fas fa-code-branch me-2"></i>Data Mutation Delta Comparison</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="diffModalBody">
                <div class="text-center py-4">Loading comparison data...</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<div id="loadingOverlay" class="loading-overlay"><div class="spinner"></div></div>

<script>
const csrfToken = "<?php echo $csrf_token; ?>";
let auditDataTable = null;

$(document).ready(function() {
    initializeDataTable();
    attachEventListeners();
});

function initializeDataTable() {
    auditDataTable = $('#auditTable').DataTable({
        processing: true,
        serverSide: true,
        ajax: {
            url: window.location.pathname + '?action=get_audit_logs_data',
            type: 'GET',
            data: function(d) {
                d.branch_id = $('#branchFilter').val();
                d.target_table = $('#tableFilter').val();
                d.action_type = $('#actionFilter').val();
                d.csrf_token = csrfToken;
            },
            dataSrc: 'data'
        },
        columns: [
            { data: 'timestamp' },
            { data: 'user' },
            { data: 'branch' },
            { data: 'action' },
            { data: 'target_table' },
            { data: 'record_id' },
            { data: 'ip' },
            {
                data: null,
                render: function(data) {
                    const ua = data.user_agent_short || '';
                    const full = data.user_agent || '';
                    // client-side user agent parsing for readable badge
                    let readable = parseUserAgent(full);
                    return `<span class="ua-badge" title="${escapeHtml(full)}">${escapeHtml(readable)}</span>`;
                }
            },
            {
                data: null,
                render: function(data) {
                    if (data.has_diff === 1) {
                        return `<button class="btn btn-sm btn-outline-primary btn-diff" data-id="${data.id}"><i class="fas fa-code-compare"></i> View Diff</button>`;
                    } else if (data.action === 'INSERT') {
                        return `<button class="btn btn-sm btn-outline-secondary btn-diff" data-id="${data.id}" disabled><i class="fas fa-plus"></i> Insert</button>`;
                    } else {
                        return `<span class="text-muted">—</span>`;
                    }
                }
            }
        ],
        order: [[0, 'desc']],
        pageLength: 25,
        lengthMenu: [[10, 25, 50, 100], [10, 25, 50, 100]],
        drawCallback: function(settings) {
            const info = this.api().page.info();
            $('#totalRecordsBadge').text(info.recordsDisplay + ' records');
            $('.btn-diff').off('click').on('click', function() {
                const logId = $(this).data('id');
                if (logId) showDiffModal(logId);
            });
        }
    });
}

function attachEventListeners() {
    $('#refreshBtn').on('click', function() {
        auditDataTable.ajax.reload();
    });
    $('#branchFilter, #tableFilter, #actionFilter').on('change', function() {
        auditDataTable.ajax.reload();
    });
}

function showDiffModal(logId) {
    showLoading(true);
    $.ajax({
        url: window.location.pathname + '?action=fetch_diff&log_id=' + logId,
        method: 'GET',
        dataType: 'json',
        success: function(res) {
            showLoading(false);
            if (res.success) {
                renderDiffModal(res);
                $('#diffModal').modal('show');
            } else {
                Swal.fire('Error', res.message || 'Could not load diff data', 'error');
            }
        },
        error: function() {
            showLoading(false);
            Swal.fire('Error', 'Network error while fetching diff.', 'error');
        }
    });
}

function renderDiffModal(data) {
    const oldObj = data.old || {};
    const newObj = data.new || {};
    const action = data.action;
    const target = data.target;
    const recordId = data.record_id;

    let html = `<div class="mb-3"><strong>Target:</strong> ${escapeHtml(target)} (ID: ${recordId}) | <strong>Action:</strong> ${escapeHtml(action)}</div>`;

    if (action === 'INSERT') {
        html += `<div class="alert alert-info">No previous state. This is a new record insertion.</div>`;
        html += `<h6>Inserted Values:</h6><pre>${escapeHtml(JSON.stringify(newObj, null, 2))}</pre>`;
    } else if (action === 'DELETE') {
        html += `<div class="alert alert-warning">Record has been deleted. Only old state is available.</div>`;
        html += `<h6>Deleted Values:</h6><pre>${escapeHtml(JSON.stringify(oldObj, null, 2))}</pre>`;
    } else if (action === 'UPDATE') {
        if (Object.keys(oldObj).length === 0 && Object.keys(newObj).length === 0) {
            html += `<div class="alert alert-secondary">No field‑level changes recorded (raw JSON may be empty).</div>`;
        } else {
            // Build side-by-side table
            const allKeys = new Set([...Object.keys(oldObj), ...Object.keys(newObj)]);
            html += `<div class="table-responsive"><table class="table diff-table"><thead><tr><th style="width:50%">Previous Value (Old)</th><th style="width:50%">New Value (Changed)</th></tr></thead><tbody>`;
            for (let key of allKeys) {
                let oldVal = oldObj.hasOwnProperty(key) ? JSON.stringify(oldObj[key]) : '—';
                let newVal = newObj.hasOwnProperty(key) ? JSON.stringify(newObj[key]) : '—';
                let oldClass = (oldVal !== newVal) ? 'bg-light-danger' : '';
                let newClass = (oldVal !== newVal) ? 'bg-light-success' : '';
                html += `<tr>
                            <td class="${oldClass}"><strong>${escapeHtml(key)}</strong><br><code>${escapeHtml(oldVal)}</code></td>
                            <td class="${newClass}"><strong>${escapeHtml(key)}</strong><br><code>${escapeHtml(newVal)}</code></td>
                         </tr>`;
            }
            html += `</tbody></table></div>`;
        }
    } else {
        html += `<pre>Old:\n${escapeHtml(JSON.stringify(oldObj, null, 2))}\n\nNew:\n${escapeHtml(JSON.stringify(newObj, null, 2))}</pre>`;
    }

    $('#diffModalBody').html(html);
}

function parseUserAgent(ua) {
    if (!ua) return 'Unknown';
    // Simple parser: extract OS and browser
    let os = 'Unknown OS';
    let browser = 'Unknown Browser';
    if (ua.includes('Windows NT 10.0')) os = 'Windows 10';
    else if (ua.includes('Windows NT 6.1')) os = 'Windows 7';
    else if (ua.includes('Mac OS X')) os = 'macOS';
    else if (ua.includes('Linux')) os = 'Linux';
    else if (ua.includes('Android')) os = 'Android';
    else if (ua.includes('iPhone') || ua.includes('iPad')) os = 'iOS';

    if (ua.includes('Chrome') && !ua.includes('Edg')) browser = 'Chrome';
    else if (ua.includes('Firefox')) browser = 'Firefox';
    else if (ua.includes('Safari') && !ua.includes('Chrome')) browser = 'Safari';
    else if (ua.includes('Edg')) browser = 'Edge';
    else if (ua.includes('MSIE') || ua.includes('Trident')) browser = 'IE';

    return `${os} · ${browser}`;
}

function escapeHtml(str) {
    if (!str) return '';
    return String(str).replace(/[&<>]/g, m => m === '&' ? '&amp;' : (m === '<' ? '&lt;' : '&gt;'));
}

function showLoading(show) {
    if (show) $('#loadingOverlay').addClass('active');
    else $('#loadingOverlay').removeClass('active');
}
</script>
<style>
.card-custom {
    background: white;
    border-radius: 24px;
    border: 1px solid #e9ecef;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    overflow: hidden;
}
.card-header-custom {
    background: white;
    padding: 20px 24px;
    border-bottom: 1px solid #e9ecef;
}
</style>
</body>
</html>