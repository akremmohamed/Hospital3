<?php
/**
 * ======================================================================
 * ACTIVITY LOG / AUDIT TRAIL - HMS ERP
 * PRODUCTION GRADE | Live AJAX Filtering | Modern Card Design
 * Strictly bound to hms_erp v5.sql (audit_logs table)
 * ======================================================================
 */

ob_start();
session_start();

// ============================================
// ETHIOPIAN RUNTIME COMPLIANCE
// ============================================
date_default_timezone_set('Africa/Addis_Ababa');

// ============================================
// SECURE SESSION CONFIGURATION
// ============================================
$user_id = (int)($_SESSION['ad_id'] ?? $_SESSION['user_id'] ?? 0);
$user_role = $_SESSION['type'] ?? $_SESSION['ad_role'] ?? '';
$user_branch_id = (int)($_SESSION['branch_id'] ?? 0);
$user_name = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''));
$is_global_admin = ($_SESSION['user_type'] ?? '') === 'super_admin';

if ($user_id <= 0) {
    header('Location: ../../index.php');
    exit;
}

// ============================================
// DATABASE CONNECTION & TIMEZONE SETUP
// ============================================
require_once 'assets/inc/config.php';

if (isset($mysqli) && $mysqli instanceof mysqli && !$mysqli->connect_error) {
    @mysqli_set_charset($mysqli, 'utf8mb4');
    $mysqli->query("SET time_zone = '+03:00'");
}

$db_available = isset($mysqli) && $mysqli && !$mysqli->connect_error;

// ============================================
// CHECK IF AUDIT LOGS TABLE EXISTS
// ============================================
$audit_table_exists = false;
if ($db_available) {
    $check_table = $mysqli->query("SHOW TABLES LIKE 'audit_logs'");
    $audit_table_exists = $check_table && $check_table->num_rows > 0;
}

// ============================================
// AJAX ENDPOINT - FETCH FILTERED LOGS
// ============================================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'fetch_logs') {
    ob_clean();
    header('Content-Type: application/json');
    
    if (!$db_available || !$audit_table_exists) {
        echo json_encode(['success' => false, 'message' => 'Table not available']);
        exit;
    }
    
    $page = (int)($_GET['page'] ?? 1);
    $limit = 15;
    $offset = ($page - 1) * $limit;
    
    $event_filter = $_GET['event'] ?? '';
    $date_from = $_GET['date_from'] ?? '';
    $date_to = $_GET['date_to'] ?? '';
    $search = $_GET['search'] ?? '';
    
    $where_clauses = [];
    $params = [];
    $types = "";
    
    if (!$is_global_admin && $user_branch_id > 0) {
        $where_clauses[] = "branch_id = ?";
        $params[] = $user_branch_id;
        $types .= "i";
    }
    
    if (!empty($event_filter)) {
        $where_clauses[] = "event = ?";
        $params[] = $event_filter;
        $types .= "s";
    }
    
    if (!empty($date_from)) {
        $where_clauses[] = "DATE(created_at) >= ?";
        $params[] = $date_from;
        $types .= "s";
    }
    
    if (!empty($date_to)) {
        $where_clauses[] = "DATE(created_at) <= ?";
        $params[] = $date_to;
        $types .= "s";
    }
    
    if (!empty($search)) {
        $where_clauses[] = "(event LIKE ? OR auditable_type LIKE ? OR new_values LIKE ?)";
        $search_param = "%$search%";
        $params[] = $search_param;
        $params[] = $search_param;
        $params[] = $search_param;
        $types .= "sss";
    }
    
    $where_sql = !empty($where_clauses) ? "WHERE " . implode(" AND ", $where_clauses) : "";
    
    // Count total
    $count_sql = "SELECT COUNT(*) as total FROM audit_logs $where_sql";
    $count_stmt = $mysqli->prepare($count_sql);
    if ($count_stmt) {
        if (!empty($params)) {
            $count_stmt->bind_param($types, ...$params);
        }
        $count_stmt->execute();
        $total_records = $count_stmt->get_result()->fetch_assoc()['total'] ?? 0;
        $count_stmt->close();
    } else {
        $total_records = 0;
    }
    
    // Fetch data
    $data_sql = "SELECT id, user_id, branch_id, event, auditable_type, auditable_id, 
                        old_values, new_values, changed_fields, ip_address, user_agent, 
                        url, method, request_data, created_at
                 FROM audit_logs $where_sql
                 ORDER BY created_at DESC LIMIT ? OFFSET ?";
    
    $data_stmt = $mysqli->prepare($data_sql);
    $logs = [];
    
    if ($data_stmt) {
        $data_params = array_merge($params, [$limit, $offset]);
        $data_types = $types . "ii";
        $data_stmt->bind_param($data_types, ...$data_params);
        $data_stmt->execute();
        $result = $data_stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            // Parse new_values JSON
            $changes = [];
            if (!empty($row['new_values'])) {
                $new = json_decode($row['new_values'], true);
                if (is_array($new)) {
                    foreach ($new as $key => $value) {
                        if (!is_array($value) && strlen($value) < 100) {
                            $changes[$key] = $value;
                        }
                    }
                }
            }
            $row['changes'] = $changes;
            $row['event_display'] = ucfirst(str_replace('_', ' ', $row['event']));
            $logs[] = $row;
        }
        $data_stmt->close();
    }
    
    // Get event types for filter
    $event_types = [];
    $event_stmt = $mysqli->prepare("SELECT DISTINCT event FROM audit_logs ORDER BY event ASC");
    if ($event_stmt) {
        $event_stmt->execute();
        $event_result = $event_stmt->get_result();
        while ($row = $event_result->fetch_assoc()) {
            $event_types[] = $row['event'];
        }
        $event_stmt->close();
    }
    
    echo json_encode([
        'success' => true,
        'logs' => $logs,
        'total' => $total_records,
        'page' => $page,
        'total_pages' => ceil($total_records / $limit),
        'event_types' => $event_types
    ]);
    exit;
}

// Get branch name for display
$branch_name = 'Main Branch';
if ($db_available && $user_branch_id > 0) {
    $branch_stmt = $mysqli->prepare("SELECT name FROM branches WHERE id = ? AND deleted_at IS NULL LIMIT 1");
    if ($branch_stmt) {
        $branch_stmt->bind_param('i', $user_branch_id);
        $branch_stmt->execute();
        $row = $branch_stmt->get_result()->fetch_assoc();
        if ($row && !empty($row['name'])) {
            $branch_name = $row['name'];
        }
        $branch_stmt->close();
    }
}

// Helper function to get event icon
function getEventIcon($event) {
    $event_lower = strtolower($event);
    if (strpos($event_lower, 'delete') !== false) return 'fa-trash-alt';
    if (strpos($event_lower, 'update') !== false) return 'fa-edit';
    if (strpos($event_lower, 'create') !== false) return 'fa-plus-circle';
    if (strpos($event_lower, 'register') !== false) return 'fa-baby-carriage';
    if (strpos($event_lower, 'login') !== false) return 'fa-sign-in-alt';
    if (strpos($event_lower, 'logout') !== false) return 'fa-sign-out-alt';
    if (strpos($event_lower, 'death') !== false) return 'fa-cross';
    if (strpos($event_lower, 'invoice') !== false) return 'fa-file-invoice';
    return 'fa-history';
}

function getEventColor($event) {
    $event_lower = strtolower($event);
    if (strpos($event_lower, 'delete') !== false) return 'danger';
    if (strpos($event_lower, 'update') !== false) return 'warning';
    if (strpos($event_lower, 'create') !== false) return 'success';
    if (strpos($event_lower, 'register') !== false) return 'success';
    if (strpos($event_lower, 'login') !== false) return 'info';
    return 'secondary';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include('assets/inc/head.php'); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Activity Log - HMS ERP</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #1a237e;
            --primary-dark: #0d1b5e;
            --primary-light: #283593;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --info: #3b82f6;
            --gray: #6b7280;
            --gray-light: #e5e7eb;
            --dark: #1f2937;
            --white: #ffffff;
        }
        
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #f5f7fb 0%, #eef2f6 100%);
            overflow-x: hidden;
        }
        
        .content-page {
            margin-left: 260px;
            margin-top: 70px;
            padding: 24px 32px;
            transition: margin-left 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            min-height: calc(100vh - 70px);
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
        
        .welcome-title {
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 4px;
        }
        
        .welcome-subtitle {
            color: rgba(255,255,255,0.7);
            font-size: 14px;
        }
        
        /* Filter Section - Modern Card */
        .filter-card {
            background: white;
            border-radius: 20px;
            border: 1px solid var(--gray-light);
            margin-bottom: 28px;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }
        
        .filter-header {
            padding: 18px 24px;
            background: #f8fafc;
            border-bottom: 1px solid var(--gray-light);
            font-weight: 700;
            color: var(--dark);
        }
        
        .filter-body {
            padding: 24px;
        }
        
        .filter-row {
            display: flex;
            flex-wrap: wrap;
            gap: 16px;
            align-items: flex-end;
        }
        
        .filter-group {
            flex: 1;
            min-width: 180px;
        }
        
        .filter-group label {
            font-size: 12px;
            font-weight: 600;
            color: var(--gray);
            margin-bottom: 6px;
            display: block;
        }
        
        .filter-input, .filter-select {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid var(--gray-light);
            border-radius: 12px;
            font-size: 14px;
            transition: all 0.2s;
            background: white;
        }
        
        .filter-input:focus, .filter-select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(26,35,126,0.1);
        }
        
        /* Activity Feed - Timeline Style */
        .activity-feed {
            display: flex;
            flex-direction: column;
            gap: 16px;
        }
        
        .activity-item {
            background: white;
            border-radius: 16px;
            border: 1px solid var(--gray-light);
            padding: 20px;
            transition: all 0.3s;
            position: relative;
            overflow: hidden;
        }
        
        .activity-item:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.08);
        }
        
        .activity-item::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 4px;
        }
        
        .activity-item.success::before { background: var(--success); }
        .activity-item.warning::before { background: var(--warning); }
        .activity-item.danger::before { background: var(--danger); }
        .activity-item.info::before { background: var(--info); }
        .activity-item.secondary::before { background: var(--gray); }
        
        .activity-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 16px;
        }
        
        .activity-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 6px 14px;
            border-radius: 30px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .badge-success { background: #d1fae5; color: #059669; }
        .badge-warning { background: #fef3c7; color: #d97706; }
        .badge-danger { background: #fee2e2; color: #dc2626; }
        .badge-info { background: #e0e7ff; color: #4338ca; }
        .badge-secondary { background: #f3f4f6; color: #6b7280; }
        
        .activity-time {
            font-size: 12px;
            color: var(--gray);
            display: flex;
            align-items: center;
            gap: 6px;
        }
        
        .activity-details {
            display: flex;
            flex-wrap: wrap;
            gap: 24px;
        }
        
        .detail-block {
            flex: 1;
            min-width: 200px;
        }
        
        .detail-label {
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            color: var(--gray);
            margin-bottom: 6px;
        }
        
        .detail-value {
            font-size: 14px;
            color: var(--dark);
            word-break: break-word;
        }
        
        .detail-value code {
            background: #f1f5f9;
            padding: 2px 8px;
            border-radius: 6px;
            font-family: monospace;
            font-size: 12px;
        }
        
        .changes-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-top: 8px;
        }
        
        .change-item {
            background: #f8fafc;
            padding: 6px 12px;
            border-radius: 8px;
            font-size: 12px;
        }
        
        .change-key {
            font-weight: 600;
            color: var(--primary);
        }
        
        .change-value {
            color: var(--dark);
            margin-left: 6px;
        }
        
        .ip-badge {
            background: #f1f5f9;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-family: monospace;
        }
        
        /* Pagination */
        .pagination-container {
            margin-top: 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 16px;
            padding: 20px 0;
        }
        
        .pagination {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        
        .page-btn {
            padding: 8px 14px;
            border: 1px solid var(--gray-light);
            border-radius: 10px;
            background: white;
            cursor: pointer;
            transition: all 0.2s;
            font-size: 13px;
        }
        
        .page-btn:hover {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }
        
        .page-btn.active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }
        
        .page-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        
        .stats-info {
            font-size: 13px;
            color: var(--gray);
        }
        
        /* Loading State */
        .loading-state {
            text-align: center;
            padding: 60px;
            color: var(--gray);
        }
        
        .loading-spinner {
            width: 40px;
            height: 40px;
            border: 3px solid var(--gray-light);
            border-top-color: var(--primary);
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
            margin: 0 auto 16px;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        .empty-state {
            text-align: center;
            padding: 60px;
            color: var(--gray);
        }
        
        .empty-state i {
            font-size: 48px;
            margin-bottom: 16px;
            opacity: 0.5;
        }
        
        /* Search Input with Icon */
        .search-wrapper {
            position: relative;
        }
        
        .search-wrapper i {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--gray);
            font-size: 14px;
        }
        
        .search-input {
            padding-left: 40px !important;
        }
        
        @media (max-width: 768px) {
            .filter-row {
                flex-direction: column;
            }
            .filter-group {
                width: 100%;
            }
            .activity-details {
                flex-direction: column;
                gap: 12px;
            }
        }
    </style>
</head>
<body>

<div id="wrapper">
    <?php include('assets/inc/nav.php'); ?>
    <?php include('assets/inc/sidebar.php'); ?>

    <div class="content-page">
        <div class="container-fluid">
            
            <!-- Welcome Header -->
            <div class="welcome-header">
                <div class="row align-items-center">
                    <div class="col-md-8">
                        <div class="welcome-title">
                            Activity Log / Audit Trail 📋
                        </div>
                        <div class="welcome-subtitle">
                            Track all system actions and user activities in real-time
                        </div>
                    </div>
                    <div class="col-md-4 text-md-end mt-3 mt-md-0">
                        <span class="badge bg-light text-dark p-2">
                            <i class="fas fa-building me-1"></i> <?php echo htmlspecialchars($branch_name); ?>
                        </span>
                    </div>
                </div>
            </div>
            
            <!-- Modern Filter Card -->
            <div class="filter-card">
                <div class="filter-header">
                    <i class="fas fa-sliders-h me-2"></i> Filter Activity Logs
                </div>
                <div class="filter-body">
                    <div class="filter-row">
                        <div class="filter-group">
                            <label><i class="fas fa-search me-1"></i> Search</label>
                            <div class="search-wrapper">
                                <i class="fas fa-search"></i>
                                <input type="text" id="searchInput" class="filter-input search-input" placeholder="Search by event, record type, or data...">
                            </div>
                        </div>
                        <div class="filter-group">
                            <label><i class="fas fa-tag me-1"></i> Event Type</label>
                            <select id="eventFilter" class="filter-select">
                                <option value="">All Events</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label><i class="fas fa-calendar me-1"></i> From Date</label>
                            <input type="date" id="dateFrom" class="filter-input">
                        </div>
                        <div class="filter-group">
                            <label><i class="fas fa-calendar me-1"></i> To Date</label>
                            <input type="date" id="dateTo" class="filter-input">
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Activity Feed Container -->
            <div id="activityContainer">
                <div class="loading-state">
                    <div class="loading-spinner"></div>
                    <p>Loading activity logs...</p>
                </div>
            </div>
            
        </div>
    </div>
    
    <?php include('assets/inc/footer.php'); ?>
</div>

<script src="assets/js/vendor.min.js"></script>
<script src="assets/js/app.min.js"></script>

<script>
// ============================================
// ACTIVITY LOG - LIVE AJAX FILTERING
// ============================================

let currentPage = 1;
let isLoading = false;
let debounceTimer;

// Helper function to escape HTML
function escapeHtml(str) {
    if (!str) return '';
    return String(str).replace(/[&<>]/g, function(m) {
        if (m === '&') return '&amp;';
        if (m === '<') return '&lt;';
        if (m === '>') return '&gt;';
        return m;
    });
}

// Format date nicely
function formatDate(dateStr) {
    if (!dateStr) return '';
    const date = new Date(dateStr);
    const now = new Date();
    const diff = now - date;
    const days = Math.floor(diff / (1000 * 60 * 60 * 24));
    
    if (days === 0) {
        return 'Today at ' + date.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });
    } else if (days === 1) {
        return 'Yesterday at ' + date.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });
    } else {
        return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) + ' at ' + 
               date.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });
    }
}

// Get badge class based on event
function getBadgeClass(event) {
    const eventLower = (event || '').toLowerCase();
    if (eventLower.includes('delete')) return 'badge-danger';
    if (eventLower.includes('update')) return 'badge-warning';
    if (eventLower.includes('create') || eventLower.includes('register')) return 'badge-success';
    if (eventLower.includes('login') || eventLower.includes('logout')) return 'badge-info';
    return 'badge-secondary';
}

// Get activity color class
function getActivityColor(event) {
    const eventLower = (event || '').toLowerCase();
    if (eventLower.includes('delete')) return 'danger';
    if (eventLower.includes('update')) return 'warning';
    if (eventLower.includes('create') || eventLower.includes('register')) return 'success';
    if (eventLower.includes('login') || eventLower.includes('logout')) return 'info';
    return 'secondary';
}

// Get event icon
function getEventIcon(event) {
    const eventLower = (event || '').toLowerCase();
    if (eventLower.includes('delete')) return 'fa-trash-alt';
    if (eventLower.includes('update')) return 'fa-edit';
    if (eventLower.includes('create')) return 'fa-plus-circle';
    if (eventLower.includes('register')) return 'fa-baby-carriage';
    if (eventLower.includes('login')) return 'fa-sign-in-alt';
    if (eventLower.includes('logout')) return 'fa-sign-out-alt';
    if (eventLower.includes('death')) return 'fa-cross';
    if (eventLower.includes('invoice')) return 'fa-file-invoice';
    return 'fa-history';
}

// Render activity items
function renderActivities(logs) {
    if (!logs || logs.length === 0) {
        return `
            <div class="empty-state">
                <i class="fas fa-inbox"></i>
                <p>No activity records found</p>
                <p class="small text-muted mt-2">Try adjusting your filters to see more results</p>
            </div>
        `;
    }
    
    return logs.map(log => {
        const colorClass = getActivityColor(log.event);
        const badgeClass = getBadgeClass(log.event);
        const eventIcon = getEventIcon(log.event);
        const eventDisplay = log.event_display || log.event;
        
        // Build changes HTML
        let changesHtml = '';
        if (log.changes && Object.keys(log.changes).length > 0) {
            const changes = Object.entries(log.changes).slice(0, 5);
            changesHtml = `
                <div class="detail-label mt-2"><i class="fas fa-exchange-alt me-1"></i> Changes</div>
                <div class="changes-grid">
                    ${changes.map(([key, value]) => `
                        <div class="change-item">
                            <span class="change-key">${escapeHtml(key)}:</span>
                            <span class="change-value">${escapeHtml(String(value).substring(0, 50))}${String(value).length > 50 ? '...' : ''}</span>
                        </div>
                    `).join('')}
                    ${Object.keys(log.changes).length > 5 ? `<div class="change-item text-muted">+ ${Object.keys(log.changes).length - 5} more fields</div>` : ''}
                </div>
            `;
        }
        
        return `
            <div class="activity-item ${colorClass}">
                <div class="activity-header">
                    <div>
                        <span class="activity-badge ${badgeClass}">
                            <i class="fas ${eventIcon}"></i>
                            ${escapeHtml(eventDisplay)}
                        </span>
                    </div>
                    <div class="activity-time">
                        <i class="far fa-clock"></i>
                        ${formatDate(log.created_at)}
                        <span class="ip-badge ms-2">
                            <i class="fas fa-network-wired"></i> ${escapeHtml(log.ip_address || 'N/A')}
                        </span>
                    </div>
                </div>
                <div class="activity-details">
                    <div class="detail-block">
                        <div class="detail-label"><i class="fas fa-database me-1"></i> Record Type</div>
                        <div class="detail-value">
                            <code>${escapeHtml(log.auditable_type || 'N/A')}</code>
                            <span class="text-muted">(ID: ${escapeHtml(log.auditable_id || 'N/A')})</span>
                        </div>
                    </div>
                    <div class="detail-block">
                        <div class="detail-label"><i class="fas fa-code-branch me-1"></i> Method</div>
                        <div class="detail-value">
                            <code>${escapeHtml(log.method || 'GET')}</code>
                        </div>
                    </div>
                </div>
                ${changesHtml}
            </div>
        `;
    }).join('');
}

// Render pagination
function renderPagination(currentPage, totalPages, totalRecords) {
    if (totalPages <= 1) return '';
    
    let paginationHtml = '<div class="pagination">';
    
    // Previous button
    paginationHtml += `<button class="page-btn" onclick="loadPage(${currentPage - 1})" ${currentPage <= 1 ? 'disabled' : ''}>
        <i class="fas fa-chevron-left"></i> Prev
    </button>`;
    
    // Page numbers
    let startPage = Math.max(1, currentPage - 2);
    let endPage = Math.min(totalPages, currentPage + 2);
    
    if (startPage > 1) {
        paginationHtml += `<button class="page-btn" onclick="loadPage(1)">1</button>`;
        if (startPage > 2) paginationHtml += `<span class="page-btn disabled">...</span>`;
    }
    
    for (let i = startPage; i <= endPage; i++) {
        paginationHtml += `<button class="page-btn ${i === currentPage ? 'active' : ''}" onclick="loadPage(${i})">${i}</button>`;
    }
    
    if (endPage < totalPages) {
        if (endPage < totalPages - 1) paginationHtml += `<span class="page-btn disabled">...</span>`;
        paginationHtml += `<button class="page-btn" onclick="loadPage(${totalPages})">${totalPages}</button>`;
    }
    
    // Next button
    paginationHtml += `<button class="page-btn" onclick="loadPage(${currentPage + 1})" ${currentPage >= totalPages ? 'disabled' : ''}>
        Next <i class="fas fa-chevron-right"></i>
    </button>`;
    
    paginationHtml += '</div>';
    
    const startRecord = (currentPage - 1) * 15 + 1;
    const endRecord = Math.min(currentPage * 15, totalRecords);
    
    return `
        <div class="pagination-container">
            <div class="stats-info">
                <i class="fas fa-chart-line me-1"></i> Showing ${startRecord} to ${endRecord} of ${totalRecords} records
            </div>
            ${paginationHtml}
        </div>
    `;
}

// Load data via AJAX
async function loadData(page = 1) {
    if (isLoading) return;
    isLoading = true;
    
    const search = document.getElementById('searchInput')?.value || '';
    const eventFilter = document.getElementById('eventFilter')?.value || '';
    const dateFrom = document.getElementById('dateFrom')?.value || '';
    const dateTo = document.getElementById('dateTo')?.value || '';
    
    const container = document.getElementById('activityContainer');
    container.innerHTML = `
        <div class="loading-state">
            <div class="loading-spinner"></div>
            <p>Loading activity logs...</p>
        </div>
    `;
    
    try {
        const url = new URL(window.location.pathname, window.location.origin);
        url.searchParams.set('ajax', 'fetch_logs');
        url.searchParams.set('page', page);
        if (search) url.searchParams.set('search', search);
        if (eventFilter) url.searchParams.set('event', eventFilter);
        if (dateFrom) url.searchParams.set('date_from', dateFrom);
        if (dateTo) url.searchParams.set('date_to', dateTo);
        
        const response = await fetch(url, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        const data = await response.json();
        
        if (data.success) {
            // Update event filter dropdown if needed
            if (data.event_types && document.getElementById('eventFilter').children.length <= 1) {
                const select = document.getElementById('eventFilter');
                data.event_types.forEach(event => {
                    const option = document.createElement('option');
                    option.value = event;
                    option.textContent = event.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
                    select.appendChild(option);
                });
            }
            
            const activitiesHtml = renderActivities(data.logs);
            const paginationHtml = renderPagination(data.page, data.total_pages, data.total);
            
            container.innerHTML = `
                <div class="filter-card" style="margin-bottom: 0;">
                    <div class="activity-feed">
                        ${activitiesHtml}
                    </div>
                    ${paginationHtml}
                </div>
            `;
            
            currentPage = data.page;
        } else {
            container.innerHTML = `
                <div class="empty-state">
                    <i class="fas fa-exclamation-triangle"></i>
                    <p>Failed to load activity logs</p>
                </div>
            `;
        }
    } catch (error) {
        console.error('Error loading data:', error);
        container.innerHTML = `
            <div class="empty-state">
                <i class="fas fa-exclamation-triangle"></i>
                <p>Network error. Please try again.</p>
            </div>
        `;
    }
    
    isLoading = false;
}

// Load specific page
function loadPage(page) {
    if (page < 1) return;
    loadData(page);
}

// Debounced filter change
function onFilterChange() {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(() => {
        loadData(1);
    }, 500);
}

// Initialize event listeners
document.addEventListener('DOMContentLoaded', function() {
    // Set up filter listeners with debounce
    const searchInput = document.getElementById('searchInput');
    const eventFilter = document.getElementById('eventFilter');
    const dateFrom = document.getElementById('dateFrom');
    const dateTo = document.getElementById('dateTo');
    
    if (searchInput) searchInput.addEventListener('input', onFilterChange);
    if (eventFilter) eventFilter.addEventListener('change', onFilterChange);
    if (dateFrom) dateFrom.addEventListener('change', onFilterChange);
    if (dateTo) dateTo.addEventListener('change', onFilterChange);
    
    // Initial load
    loadData(1);
});

// SAFEGUARDED Sidebar initialization
if (typeof MetisMenu !== 'undefined') {
    var sideMenu = document.getElementById('side-menu');
    if (sideMenu && !sideMenu.hasAttribute('data-metismenu-initialized')) {
        try {
            new MetisMenu(sideMenu, { toggle: true });
            sideMenu.setAttribute('data-metismenu-initialized', 'true');
        } catch(e) {
            console.warn('MetisMenu init failed:', e.message);
        }
    }
}

console.log('✅ Activity Log Loaded - Live AJAX Filtering Active');
</script>
</body>
</html>