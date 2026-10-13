<?php
/**
 * ======================================================================
 * PHYSICIAN ACTIVITY LOG & AUDIT TRAIL - HMS ERP
 * Strict multi‑tenancy | PHP 8.2+ type‑safe | Server‑side pagination
 * ======================================================================
 */

session_start();

// ----------------------------------------------------------------------
// 1. BOOTSTRAP & SECURITY
// ----------------------------------------------------------------------
require_once __DIR__ . '/../../config/db_connection.php';
require_once __DIR__ . '/../../includes/auth_helper.php';

date_default_timezone_set('Africa/Addis_Ababa');
$pdo->exec("SET time_zone = '+03:00'");

$user_id = (int)($_SESSION['user_id'] ?? 0);
$branch_id = (int)($_SESSION['branch_id'] ?? 0);
$is_global_admin = ($_SESSION['user_type'] ?? '') === 'super_admin';
$doctor_name = $_SESSION['first_name'] ?? $_SESSION['username'] ?? 'Doctor';

if ($user_id <= 0) {
    header('Location: ../../index.php');
    exit;
}

// Permission check
$has_view_logs = user_has_permission($pdo, 'view_doctor_activity_logs', $user_id);
$has_audit_trail = user_has_permission($pdo, 'view_audit_trail', $user_id);
if (!$is_global_admin && !$has_view_logs && !$has_audit_trail) {
    header('Location: dashboard.php?error=unauthorized');
    exit;
}

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// ----------------------------------------------------------------------
// Helper functions
// ----------------------------------------------------------------------
function escapeHtml($str) {
    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}

// ----------------------------------------------------------------------
// 2. AJAX HANDLER (Fetch logs with filters & pagination)
// ----------------------------------------------------------------------
if (isset($_GET['ajax']) && $_GET['ajax'] === 'fetch_logs') {
    header('Content-Type: application/json');
    try {
        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = 20;
        $offset = ($page - 1) * $limit;

        // Filters
        $action_type = $_GET['action_type'] ?? 'all';
        $start_date = $_GET['start_date'] ?? '';
        $end_date = $_GET['end_date'] ?? '';
        $search = trim($_GET['search'] ?? '');

        // Base query with patient context (left join using record_id or JSON patient_id)
        $baseSql = "FROM audit_logs al
                    LEFT JOIN patients p ON (
                        (al.target_table = 'patients' AND al.record_id = p.id)
                        OR (p.id = JSON_UNQUOTE(JSON_EXTRACT(al.new_values, '$.patient_id')) AND al.new_values IS NOT NULL)
                    ) AND p.deleted_at IS NULL
                    WHERE al.user_id = :user_id
                      AND al.branch_id = :branch_id";

        $params = [':user_id' => $user_id, ':branch_id' => $branch_id];

        // Filter by action type (target_table or action_type)
        if ($action_type !== 'all') {
            switch ($action_type) {
                case 'patient':
                    $baseSql .= " AND al.target_table = 'patients'";
                    break;
                case 'appointment':
                    $baseSql .= " AND al.target_table = 'appointments'";
                    break;
                case 'consultation':
                    $baseSql .= " AND al.target_table = 'consultations'";
                    break;
                case 'lab':
                    $baseSql .= " AND al.target_table = 'lab_orders'";
                    break;
                case 'prescription':
                    $baseSql .= " AND al.target_table = 'prescriptions'";
                    break;
                case 'admission':
                    $baseSql .= " AND al.target_table = 'admissions'";
                    break;
                case 'login':
                    $baseSql .= " AND al.action_type IN ('LOGIN', 'LOGOUT')";
                    break;
                default:
                    // custom target_table or action_type
                    if (in_array($action_type, ['INSERT', 'UPDATE', 'DELETE', 'LOGIN', 'LOGOUT'])) {
                        $baseSql .= " AND al.action_type = :action_type";
                        $params[':action_type'] = $action_type;
                    } else {
                        $baseSql .= " AND al.target_table = :target_table";
                        $params[':target_table'] = $action_type;
                    }
            }
        }

        // Date range filter
        if (!empty($start_date)) {
            $baseSql .= " AND DATE(al.created_at) >= :start_date";
            $params[':start_date'] = $start_date;
        }
        if (!empty($end_date)) {
            $baseSql .= " AND DATE(al.created_at) <= :end_date";
            $params[':end_date'] = $end_date;
        }

        // Search filter (action description, patient MRN, patient name)
        if (!empty($search)) {
            $baseSql .= " AND (
                al.action_type LIKE :search
                OR al.target_table LIKE :search
                OR al.old_values LIKE :search
                OR al.new_values LIKE :search
                OR p.mr_number LIKE :search
                OR CONCAT(p.first_name, ' ', p.last_name) LIKE :search
            )";
            $params[':search'] = "%$search%";
        }

        // Count total records
        $countSql = "SELECT COUNT(DISTINCT al.id) " . $baseSql;
        $stmt = $pdo->prepare($countSql);
        $stmt->execute($params);
        $totalRecords = (int)$stmt->fetchColumn();
        $totalPages = ceil($totalRecords / $limit);

        // Fetch data for current page
        $dataSql = "SELECT 
                        al.id,
                        al.action_type,
                        al.target_table,
                        al.record_id,
                        al.old_values,
                        al.new_values,
                        al.ip_address,
                        al.created_at,
                        p.id AS patient_id,
                        p.mr_number,
                        CONCAT(p.first_name, ' ', COALESCE(p.middle_name, ''), ' ', p.last_name) AS patient_name
                    " . $baseSql . "
                    ORDER BY al.created_at DESC
                    LIMIT :limit OFFSET :offset";

        $stmt = $pdo->prepare($dataSql);
        foreach ($params as $key => $val) {
            $stmt->bindValue($key, $val, is_int($val) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($logs)) $logs = [];

        // Build human-readable action description and status
        $results = [];
        foreach ($logs as $log) {
            $actionDesc = $log['action_type'] . ' on ' . ucfirst(str_replace('_', ' ', $log['target_table']));
            if ($log['action_type'] === 'LOGIN') $actionDesc = 'System Login';
            if ($log['action_type'] === 'LOGOUT') $actionDesc = 'System Logout';

            $module = ucfirst(str_replace('_', ' ', $log['target_table']));
            if ($log['target_table'] === 'patients') $module = 'Patient Record';
            if ($log['target_table'] === 'appointments') $module = 'Appointment';
            if ($log['target_table'] === 'consultations') $module = 'Consultation';
            if ($log['target_table'] === 'lab_orders') $module = 'Lab Order';
            if ($log['target_table'] === 'prescriptions') $module = 'Prescription';
            if ($log['target_table'] === 'admissions') $module = 'Admission';
            if (in_array($log['action_type'], ['LOGIN', 'LOGOUT'])) $module = 'Authentication';

            $patientContext = '—';
            if (!empty($log['patient_name']) && !empty($log['mr_number'])) {
                $patientContext = escapeHtml($log['patient_name']) . ' (MRN: ' . escapeHtml($log['mr_number']) . ')';
            } elseif (!empty($log['patient_name'])) {
                $patientContext = escapeHtml($log['patient_name']);
            } elseif (!empty($log['mr_number'])) {
                $patientContext = 'MRN: ' . escapeHtml($log['mr_number']);
            }

            $status = '<span class="badge bg-success"><i class="fas fa-check-circle"></i> Success</span>';

            $results[] = [
                'created_at' => date('Y-m-d H:i:s', strtotime($log['created_at'])),
                'action' => $actionDesc,
                'module' => $module,
                'patient_context' => $patientContext,
                'ip_address' => $log['ip_address'] ?: '127.0.0.1',
                'status' => $status,
            ];
        }

        echo json_encode([
            'success' => true,
            'logs' => $results,
            'pagination' => [
                'current_page' => $page,
                'total_pages' => $totalPages,
                'total_records' => $totalRecords,
                'per_page' => $limit
            ]
        ]);
        exit;
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        exit;
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
        exit;
    }
}

// ----------------------------------------------------------------------
// 3. INITIAL PAGE RENDER (no data, all via AJAX)
// ----------------------------------------------------------------------
$page_title = "Activity Log & Audit Trail";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include('assets/inc/head.php'); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> | HMS ERP</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
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
            --gray: #6c757d;
            --gray-light: #e9ecef;
        }
        body { font-family: 'Inter', sans-serif; background: #f5f7fb; overflow-x: hidden; }
        .content-page { margin-left: 260px; margin-top: 70px; padding: 24px 32px; transition: margin-left 0.3s; min-height: calc(100vh - 70px); }
        body.erp-sidebar-collapsed .content-page { margin-left: 80px; }
        @media (max-width: 768px) { .content-page { margin-left: 0 !important; padding: 16px; } }
        .page-title { font-size: 24px; font-weight: 700; color: var(--dark); margin-bottom: 24px; }
        .filter-card { background: white; border-radius: 20px; border: 1px solid var(--gray-light); padding: 20px; margin-bottom: 24px; }
        .filter-row { display: flex; flex-wrap: wrap; gap: 15px; align-items: flex-end; }
        .filter-group { flex: 1; min-width: 150px; }
        .filter-group label { font-size: 12px; font-weight: 600; color: var(--gray); margin-bottom: 5px; display: block; }
        .filter-group select, .filter-group input { width: 100%; padding: 8px 12px; border: 1px solid var(--gray-light); border-radius: 12px; font-size: 13px; }
        .action-btn { padding: 8px 18px; border-radius: 12px; font-size: 13px; font-weight: 500; border: none; cursor: pointer; transition: all 0.2s; }
        .action-btn-primary { background: var(--primary); color: white; }
        .action-btn-primary:hover { background: var(--primary-dark); }
        .action-btn-outline { background: white; border: 1px solid var(--gray-light); color: var(--dark); }
        .action-btn-outline:hover { border-color: var(--primary); color: var(--primary); }
        .table-card { background: white; border-radius: 20px; border: 1px solid var(--gray-light); overflow: hidden; }
        .data-table { width: 100%; }
        .data-table th { padding: 14px 20px; font-size: 12px; font-weight: 600; color: var(--gray); background: #f8fafc; border-bottom: 1px solid var(--gray-light); }
        .data-table td { padding: 12px 20px; font-size: 13px; border-bottom: 1px solid #f3f4f6; vertical-align: middle; }
        .data-table tr:hover { background: #f8fafc; }
        .badge { padding: 5px 10px; border-radius: 20px; font-size: 11px; font-weight: 600; }
        .pagination-container { display: flex; justify-content: center; align-items: center; gap: 12px; margin-top: 24px; flex-wrap: wrap; }
        .pagination-btn { padding: 8px 16px; border-radius: 10px; border: 1px solid var(--gray-light); background: white; cursor: pointer; transition: all 0.2s; }
        .pagination-btn:hover { background: var(--primary); color: white; border-color: var(--primary); }
        .pagination-btn.active { background: var(--primary); color: white; border-color: var(--primary); }
        .loading-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(255,255,255,0.7); z-index: 2000; display: none; align-items: center; justify-content: center; }
        .loading-overlay.active { display: flex; }
        .spinner { width: 40px; height: 40px; border: 3px solid var(--gray-light); border-top-color: var(--primary); border-radius: 50%; animation: spin 0.8s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }
        .empty-state { text-align: center; padding: 40px; color: var(--gray); }
        .toast-notification { position: fixed; bottom: 20px; right: 20px; background: white; border-radius: 12px; padding: 12px 20px; box-shadow: 0 10px 25px rgba(0,0,0,0.1); z-index: 1100; animation: slideIn 0.3s ease; display: flex; align-items: center; gap: 12px; border-left: 4px solid var(--success); }
        .toast-error { border-left-color: var(--danger); }
        @keyframes slideIn { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
    </style>
</head>
<body>
<div id="wrapper">
    <?php include('assets/inc/nav.php'); ?>
    <?php include('assets/inc/sidebar.php'); ?>

    <div class="content-page">
        <div class="container-fluid">
            <div class="page-title"><i class="fas fa-history me-2"></i><?php echo $page_title; ?></div>

            <!-- Filter Bar -->
            <div class="filter-card">
                <div class="filter-row">
                    <div class="filter-group">
                        <label><i class="fas fa-tag"></i> Log Type</label>
                        <select id="filterActionType">
                            <option value="all">All Activities</option>
                            <option value="patient">Patient Records</option>
                            <option value="appointment">Appointments</option>
                            <option value="consultation">Consultations</option>
                            <option value="lab">Lab Orders</option>
                            <option value="prescription">Prescriptions</option>
                            <option value="admission">Admissions</option>
                            <option value="login">Login / Logout</option>
                            <option value="INSERT">INSERT Operations</option>
                            <option value="UPDATE">UPDATE Operations</option>
                            <option value="DELETE">DELETE Operations</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label><i class="fas fa-calendar-alt"></i> From Date</label>
                        <input type="date" id="filterStartDate" class="form-control">
                    </div>
                    <div class="filter-group">
                        <label><i class="fas fa-calendar-alt"></i> To Date</label>
                        <input type="date" id="filterEndDate" class="form-control">
                    </div>
                    <div class="filter-group">
                        <label><i class="fas fa-search"></i> Search (MRN / Action)</label>
                        <input type="text" id="filterSearch" placeholder="Patient MRN or keyword..." class="form-control">
                    </div>
                    <div class="filter-group" style="flex: 0 0 auto;">
                        <button id="applyFiltersBtn" class="action-btn action-btn-primary"><i class="fas fa-filter"></i> Apply</button>
                        <button id="resetFiltersBtn" class="action-btn action-btn-outline ms-2"><i class="fas fa-undo-alt"></i> Reset</button>
                    </div>
                </div>
            </div>

            <!-- Activity Table -->
            <div class="table-card">
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr><th>Timestamp</th><th>Action Executed</th><th>Target Module</th><th>Patient Context</th><th>IP Address</th><th>Status</th></tr>
                        </thead>
                        <tbody id="logsTableBody">
                            <tr><td colspan="6" class="empty-state">Loading activity logs...</td></tr>
                        </tbody>
                    </table>
                </div>
                <div class="pagination-container" id="paginationControls"></div>
            </div>
        </div>
    </div>
    <?php include('assets/inc/footer.php'); ?>
</div>

<div id="loadingOverlay" class="loading-overlay"><div class="spinner"></div></div>

<script src="assets/js/vendor.min.js"></script>
<script src="assets/js/bootstrap.bundle.min.js"></script>
<script>
const csrfToken = <?php echo json_encode($csrf_token); ?>;
let currentPage = 1;

function showToast(message, type = 'success') {
    const toast = document.createElement('div');
    toast.className = `toast-notification ${type === 'error' ? 'toast-error' : ''}`;
    toast.innerHTML = `<i class="fas fa-${type === 'error' ? 'exclamation-circle' : 'check-circle'}"></i> <span>${escapeHtml(message)}</span>`;
    document.body.appendChild(toast);
    setTimeout(() => toast.remove(), 3000);
}

function escapeHtml(unsafe) {
    if (!unsafe) return '';
    return String(unsafe).replace(/[&<>]/g, m => m === '&' ? '&amp;' : (m === '<' ? '&lt;' : '&gt;'));
}

function showLoading(show) {
    document.getElementById('loadingOverlay').classList.toggle('active', show);
}

function fetchLogs(page = 1) {
    showLoading(true);
    const actionType = document.getElementById('filterActionType').value;
    const startDate = document.getElementById('filterStartDate').value;
    const endDate = document.getElementById('filterEndDate').value;
    const search = document.getElementById('filterSearch').value;

    $.ajax({
        url: window.location.pathname + '?ajax=fetch_logs',
        method: 'GET',
        data: {
            page: page,
            action_type: actionType,
            start_date: startDate,
            end_date: endDate,
            search: search,
            csrf_token: csrfToken
        },
        dataType: 'json',
        success: function(res) {
            showLoading(false);
            if (res.success) {
                renderTable(res.logs);
                renderPagination(res.pagination);
                currentPage = res.pagination.current_page;
            } else {
                showToast(res.message || 'Failed to load logs', 'error');
                document.getElementById('logsTableBody').innerHTML = '<tr><td colspan="6" class="empty-state">Error loading data. Please try again.</td></tr>';
                document.getElementById('paginationControls').innerHTML = '';
            }
        },
        error: function(xhr) {
            showLoading(false);
            showToast('Network error: ' + xhr.statusText, 'error');
            document.getElementById('logsTableBody').innerHTML = '<tr><td colspan="6" class="empty-state">Network error. Please refresh.</td></tr>';
        }
    });
}

function renderTable(logs) {
    const tbody = document.getElementById('logsTableBody');
    if (!logs || logs.length === 0) {
        tbody.innerHTML = '<tr><td colspan="6" class="empty-state">No activity logs found for the selected filters.</td></tr>';
        return;
    }
    let html = '';
    logs.forEach(log => {
        html += `<tr>
                    <td>${escapeHtml(log.created_at)}</td>
                    <td><strong>${escapeHtml(log.action)}</strong></td>
                    <td>${escapeHtml(log.module)}</td>
                    <td>${log.patient_context}</td>
                    <td>${escapeHtml(log.ip_address)}</td>
                    <td>${log.status}</td>
                 </tr>`;
    });
    tbody.innerHTML = html;
}

function renderPagination(pagination) {
    const container = document.getElementById('paginationControls');
    if (!pagination || pagination.total_pages <= 1) {
        container.innerHTML = '';
        return;
    }
    let html = `<button class="pagination-btn" id="firstPageBtn" ${pagination.current_page === 1 ? 'disabled' : ''}><i class="fas fa-angle-double-left"></i></button>
                <button class="pagination-btn" id="prevPageBtn" ${pagination.current_page === 1 ? 'disabled' : ''}><i class="fas fa-angle-left"></i></button>
                <span class="mx-2">Page ${pagination.current_page} of ${pagination.total_pages}</span>
                <button class="pagination-btn" id="nextPageBtn" ${pagination.current_page === pagination.total_pages ? 'disabled' : ''}><i class="fas fa-angle-right"></i></button>
                <button class="pagination-btn" id="lastPageBtn" ${pagination.current_page === pagination.total_pages ? 'disabled' : ''}><i class="fas fa-angle-double-right"></i></button>`;
    container.innerHTML = html;

    document.getElementById('firstPageBtn')?.addEventListener('click', () => fetchLogs(1));
    document.getElementById('prevPageBtn')?.addEventListener('click', () => fetchLogs(pagination.current_page - 1));
    document.getElementById('nextPageBtn')?.addEventListener('click', () => fetchLogs(pagination.current_page + 1));
    document.getElementById('lastPageBtn')?.addEventListener('click', () => fetchLogs(pagination.total_pages));
}

// Event listeners
$(document).ready(function() {
    fetchLogs(1);

    $('#applyFiltersBtn').click(function() {
        fetchLogs(1);
    });
    $('#resetFiltersBtn').click(function() {
        document.getElementById('filterActionType').value = 'all';
        document.getElementById('filterStartDate').value = '';
        document.getElementById('filterEndDate').value = '';
        document.getElementById('filterSearch').value = '';
        fetchLogs(1);
    });
});

// Sidebar toggle compatibility
if (typeof MetisMenu !== 'undefined') {
    const sideMenu = document.getElementById('side-menu');
    if (sideMenu && !sideMenu.hasAttribute('data-metismenu-initialized')) {
        new MetisMenu(sideMenu, { toggle: true, triggerElement: 'a.has-arrow', parentTrigger: 'li', subMenu: 'ul' });
        sideMenu.setAttribute('data-metismenu-initialized', 'true');
    }
}
document.getElementById('mobileMenuToggle')?.addEventListener('click', function(e) {
    e.preventDefault();
    document.body.classList.toggle('sidebar-enable');
});
window.addEventListener('resize', function() {
    if (window.innerWidth > 768 && document.body.classList.contains('sidebar-enable')) document.body.classList.remove('sidebar-enable');
});
</script>
</body>
</html>