<?php
/**
 * Nursing Activity Log - Production Grade (Fixed Duplicate Column)
 *
 * @package HMS-ERP
 * @version 7.1
 */

session_start();
require_once '../../config/db_connection.php';

// ============================================
// ENVIRONMENT HARDENING
// ============================================
date_default_timezone_set('Africa/Addis_Ababa');
if ($pdo) $pdo->exec("SET time_zone = '+03:00'");

// ============================================
// AUTHENTICATION & ACCESS CONTROL
// ============================================
if (!isset($_SESSION['user_id']) || !isset($_SESSION['branch_id'])) {
    header('Location: ../index.php');
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$branch_id = (int)$_SESSION['branch_id'];
$user_role = $_SESSION['user_type'] ?? '';
$user_name = $_SESSION['first_name'] ?? 'Nurse';

// ============================================
// FETCH NURSE'S DEPARTMENT (display only)
// ============================================
$department_name = 'All Wards';
$dept_stmt = $pdo->prepare("SELECT d.name FROM users u LEFT JOIN departments d ON u.department_id = d.id WHERE u.id = ? LIMIT 1");
if ($dept_stmt) {
    $dept_stmt->bind_param('i', $user_id);
    $dept_stmt->execute();
    $dept_res = $dept_stmt->get_result();
    if ($dept_row = $dept_res->fetch_assoc()) {
        $department_name = $dept_row['name'] ?? 'All Wards';
    }
    $dept_stmt->close();
}

// ============================================
// FILTERS & PAGINATION
// ============================================
$start_date = $_GET['start_date'] ?? date('Y-m-d', strtotime('-7 days'));
$end_date   = $_GET['end_date'] ?? date('Y-m-d');
$activity_type = $_GET['activity_type'] ?? 'all';
$patient_search = trim($_GET['patient_search'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 25;
$offset = ($page - 1) * $limit;

// ============================================
// UNION QUERY USING ONLY EXISTING TABLES AND CORRECT COLUMNS
// ============================================
// 1. TRIAGE / VITALS
$triage_sql = "
    SELECT 
        t.id AS activity_id,
        'triage' AS activity_type,
        'Vitals Recording' AS activity_name,
        t.triage_time AS activity_time,
        t.patient_id,
        t.taken_by_user_id AS staff_id,
        t.chief_complaint,
        t.pain_score,
        t.triage_notes AS notes,
        CONCAT(COALESCE(t.blood_pressure_systolic, ''), '/', COALESCE(t.blood_pressure_diastolic, '')) AS bp,
        t.heart_rate,
        t.temperature_celsius AS temperature,
        t.oxygen_saturation
    FROM triage_records t
    WHERE t.branch_id = ? AND t.deleted_at IS NULL
      AND DATE(t.triage_time) BETWEEN ? AND ?
";

// 2. LAB ORDERS
$lab_sql = "
    SELECT 
        lo.id AS activity_id,
        'lab' AS activity_type,
        CONCAT('Lab Order: ', IFNULL(lt.test_name, lo.order_number)) AS activity_name,
        lo.created_at AS activity_time,
        lo.patient_id,
        lo.specimen_collected_by_user_id AS staff_id,
        lo.clinical_indication AS chief_complaint,
        NULL AS pain_score,
        lo.notes,
        NULL AS bp,
        NULL AS heart_rate,
        NULL AS temperature,
        NULL AS oxygen_saturation
    FROM lab_orders lo
    LEFT JOIN lab_tests_master lt ON lo.test_id = lt.id
    WHERE lo.branch_id = ? AND lo.deleted_at IS NULL
      AND lo.status IN ('pending', 'collected', 'completed')
      AND DATE(lo.created_at) BETWEEN ? AND ?
";

// 3. PROCEDURES
$procedure_sql = "
    SELECT 
        po.id AS activity_id,
        'procedure' AS activity_type,
        po.procedure_name AS activity_name,
        po.created_at AS activity_time,
        po.patient_id,
        po.performed_by_user_id AS staff_id,
        po.clinical_notes AS chief_complaint,
        NULL AS pain_score,
        po.procedure_findings AS notes,
        NULL AS bp,
        NULL AS heart_rate,
        NULL AS temperature,
        NULL AS oxygen_saturation
    FROM procedure_orders po
    WHERE po.branch_id = ? AND po.deleted_at IS NULL
      AND po.execution_status IN ('scheduled', 'in_progress', 'completed')
      AND DATE(po.created_at) BETWEEN ? AND ?
";

// 4. PRESCRIPTIONS
$prescription_sql = "
    SELECT 
        p.id AS activity_id,
        'prescription' AS activity_type,
        'Medication Order' AS activity_name,
        p.created_at AS activity_time,
        p.patient_id,
        NULL AS staff_id,
        p.clinical_notes AS chief_complaint,
        NULL AS pain_score,
        CONCAT('Status: ', p.status, ' - ', IFNULL(p.clinical_notes, '')) AS notes,
        NULL AS bp,
        NULL AS heart_rate,
        NULL AS temperature,
        NULL AS oxygen_saturation
    FROM prescriptions p
    WHERE p.branch_id = ? AND p.deleted_at IS NULL
      AND DATE(p.created_at) BETWEEN ? AND ?
";

// 5. CONSULTATIONS
$consult_sql = "
    SELECT 
        c.id AS activity_id,
        'consultation' AS activity_type,
        'Consultation' AS activity_name,
        c.created_at AS activity_time,
        c.patient_id,
        c.doctor_id AS staff_id,
        c.chief_complaint,
        NULL AS pain_score,
        c.assessment AS notes,
        NULL AS bp,
        NULL AS heart_rate,
        NULL AS temperature,
        NULL AS oxygen_saturation
    FROM consultations c
    WHERE c.branch_id = ? AND c.deleted_at IS NULL
      AND DATE(c.created_at) BETWEEN ? AND ?
";

// Combine UNIONs (5 blocks)
$union_sql = "($triage_sql) UNION ALL ($lab_sql) UNION ALL ($procedure_sql) UNION ALL ($prescription_sql) UNION ALL ($consult_sql)";

// Parameters: each block has 3 params (branch_id, start_date, end_date) => total 15 params
$params = [];
for ($i = 0; $i < 5; $i++) {
    $params[] = $branch_id;
    $params[] = $start_date;
    $params[] = $end_date;
}
$types = str_repeat('iss', 5); // 5 blocks * 3 = 15

// Apply activity type filter if specified
if ($activity_type !== 'all') {
    $union_sql = "SELECT * FROM ($union_sql) AS combined WHERE activity_type = ?";
    array_unshift($params, $activity_type);
    array_unshift($types, 's');
}

// Outer query: join patients, beds, staff using correct column names
// FIXED: Duplicate column 'patient_id' - we select a.* (which contains patient_id) and then do NOT alias p.id as patient_id again
$final_sql = "
    SELECT 
        a.*,
        p.first_name,
        p.middle_name,
        p.last_name,
        p.mr_number,
        p.gender,
        p.date_of_birth AS dob,
        TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) AS age,
        b.bed_number AS bed_code,
        CONCAT(COALESCE(s.first_name, ''), ' ', COALESCE(s.last_name, '')) AS staff_name
    FROM ($union_sql) AS a
    LEFT JOIN patients p ON a.patient_id = p.id
    LEFT JOIN bed_allocations ba ON a.patient_id = ba.patient_id AND ba.check_out_date IS NULL
    LEFT JOIN beds b ON ba.bed_number = b.bed_number AND b.deleted_at IS NULL
    LEFT JOIN users s ON a.staff_id = s.id
    WHERE p.deleted_at IS NULL
";

// Add patient search filter
if (!empty($patient_search)) {
    $search_like = "%$patient_search%";
    $final_sql .= " AND (p.first_name LIKE ? OR p.last_name LIKE ? OR p.mr_number LIKE ?)";
    $params[] = $search_like;
    $params[] = $search_like;
    $params[] = $search_like;
    $types .= 'sss';
}

$final_sql .= " ORDER BY a.activity_time DESC";

// ============================================
// COUNT TOTAL FOR PAGINATION
// ============================================
$count_sql = "SELECT COUNT(*) FROM ($final_sql) AS counted";
$count_stmt = $pdo->prepare($count_sql);
$total_rows = 0;
if ($count_stmt) {
    $count_stmt->bind_param($types, ...$params);
    $count_stmt->execute();
    $count_res = $count_stmt->get_result();
    $total_rows = $count_res->fetch_row()[0];
    $count_stmt->close();
}
$total_pages = ceil($total_rows / $limit);

// Add LIMIT & OFFSET
$final_sql .= " LIMIT ? OFFSET ?";
$params[] = $limit;
$params[] = $offset;
$types .= 'ii';

// ============================================
// EXECUTE MAIN QUERY
// ============================================
$activities = [];
$stmt = $pdo->prepare($final_sql);
if ($stmt) {
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $row['full_name'] = trim(($row['first_name'] ?? '') . ' ' . ($row['middle_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
        $row['formatted_details'] = formatActivityDetails($row);
        $activities[] = $row;
    }
    $stmt->close();
}

// ============================================
// CSV EXPORT (if requested)
// ============================================
if (isset($_GET['export']) && $_GET['export'] === 'csv' && !empty($activities)) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="nursing_activity_log_' . date('Y-m-d') . '.csv"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Date/Time', 'Activity Type', 'Activity Name', 'Patient Name', 'MRN', 'Age', 'Gender', 'Bed', 'Staff', 'Details']);
    foreach ($activities as $act) {
        $details = strip_tags($act['formatted_details']);
        fputcsv($output, [
            date('Y-m-d H:i:s', strtotime($act['activity_time'])),
            $act['activity_type'],
            $act['activity_name'],
            $act['full_name'],
            $act['mr_number'],
            $act['age'],
            ucfirst($act['gender'] ?? 'N/A'),
            $act['bed_code'] ?? '',
            $act['staff_name'] ?? '',
            $details
        ]);
    }
    fclose($output);
    exit;
}

// ============================================
// HELPERS
// ============================================
function formatActivityDetails($activity) {
    $html = '<div class="small text-muted">';
    switch ($activity['activity_type']) {
        case 'triage':
            if (!empty($activity['bp'])) $html .= '<span class="me-2"><i class="fas fa-tachometer-alt"></i> BP: ' . htmlspecialchars($activity['bp']) . '</span>';
            if (!empty($activity['heart_rate'])) $html .= '<span class="me-2"><i class="fas fa-heartbeat"></i> HR: ' . htmlspecialchars($activity['heart_rate']) . '</span>';
            if (!empty($activity['temperature'])) $html .= '<span class="me-2"><i class="fas fa-thermometer-half"></i> Temp: ' . htmlspecialchars($activity['temperature']) . '°C</span>';
            if (!empty($activity['oxygen_saturation'])) $html .= '<span><i class="fas fa-lungs"></i> SpO₂: ' . htmlspecialchars($activity['oxygen_saturation']) . '%</span>';
            if (!empty($activity['pain_score'])) $html .= '<div class="mt-1"><i class="fas fa-face-frown"></i> Pain: ' . htmlspecialchars($activity['pain_score']) . '/10</div>';
            if (!empty($activity['chief_complaint'])) $html .= '<div class="mt-1"><i class="fas fa-stethoscope"></i> CC: ' . htmlspecialchars(substr($activity['chief_complaint'], 0, 100)) . '</div>';
            break;
        case 'lab':
            $html .= '<span><i class="fas fa-flask"></i> ' . htmlspecialchars(substr($activity['chief_complaint'] ?? '', 0, 100)) . '</span>';
            break;
        case 'procedure':
            $html .= '<div>' . htmlspecialchars(substr($activity['chief_complaint'] ?? '', 0, 150)) . '</div>';
            break;
        case 'prescription':
            $html .= '<div><i class="fas fa-prescription"></i> ' . htmlspecialchars(substr($activity['notes'] ?? '', 0, 150)) . '</div>';
            break;
        case 'consultation':
            $html .= '<div><i class="fas fa-stethoscope"></i> ' . htmlspecialchars(substr($activity['notes'] ?? '', 0, 150)) . '</div>';
            break;
        default:
            $html .= '<div>' . htmlspecialchars(substr($activity['notes'] ?? '', 0, 150)) . '</div>';
    }
    $html .= '</div>';
    return $html;
}

function getActivityIcon($type) {
    $icons = [
        'triage' => 'fa-stethoscope',
        'lab' => 'fa-flask',
        'procedure' => 'fa-syringe',
        'prescription' => 'fa-capsules',
        'consultation' => 'fa-user-md'
    ];
    return $icons[$type] ?? 'fa-activity';
}

function getActivityColor($type) {
    $colors = [
        'triage' => 'primary',
        'lab' => 'info',
        'procedure' => 'danger',
        'prescription' => 'success',
        'consultation' => 'secondary'
    ];
    return $colors[$type] ?? 'secondary';
}

// Summary counts
$summary = [
    'total' => count($activities),
    'triage' => 0,
    'lab' => 0,
    'procedure' => 0,
    'prescription' => 0,
    'consultation' => 0,
];
foreach ($activities as $act) {
    $summary[$act['activity_type']] = ($summary[$act['activity_type']] ?? 0) + 1;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include('assets/inc/head.php'); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Nursing Activity Log | Clinical Timeline</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <style>
        :root { --primary: #4361ee; --primary-dark: #3a0ca3; --success: #06d6a0; --warning: #f9c74f; --danger: #ef476f; --info: #4cc9f0; --dark: #1a1a2e; --gray: #6c757d; --gray-light: #e9ecef; }
        body { font-family: 'Inter', sans-serif; background: linear-gradient(135deg, #f5f7fb 0%, #eef2f6 100%); }
        .content-page { margin-left: 260px; margin-top: 70px; padding: 24px 32px; transition: margin-left 0.3s; min-height: calc(100vh - 70px); }
        body.erp-sidebar-collapsed .content-page { margin-left: 80px; }
        @media (max-width: 768px) { .content-page { margin-left: 0 !important; padding: 16px; } }
        .welcome-header { background: linear-gradient(135deg, var(--primary-dark), var(--primary)); border-radius: 24px; padding: 24px 32px; color: white; margin-bottom: 28px; }
        .time-badge { background: rgba(255,255,255,0.15); padding: 8px 16px; border-radius: 12px; font-size: 13px; display: inline-flex; align-items: center; gap: 8px; }
        .stats-grid { display: grid; grid-template-columns: repeat(5, 1fr); gap: 16px; margin-bottom: 28px; }
        .stat-card { background: white; border-radius: 16px; padding: 16px; text-align: center; border: 1px solid var(--gray-light); transition: 0.2s; }
        .stat-card:hover { transform: translateY(-3px); box-shadow: 0 8px 20px rgba(0,0,0,0.08); }
        .stat-value { font-size: 24px; font-weight: 800; color: #0f172a !important; }
        .stat-label { font-size: 11px; color: #475569; font-weight: 500; text-transform: uppercase; letter-spacing: 0.5px; }
        .stat-icon { font-size: 28px; margin-bottom: 8px; }
        .filter-bar { background: white; border-radius: 20px; padding: 20px 24px; margin-bottom: 28px; border: 1px solid var(--gray-light); }
        .timeline { position: relative; padding-left: 30px; }
        .timeline::before { content: ''; position: absolute; left: 10px; top: 0; bottom: 0; width: 2px; background: linear-gradient(to bottom, var(--primary), var(--success), var(--warning)); }
        .timeline-item { position: relative; margin-bottom: 24px; background: white; border-radius: 16px; border: 1px solid var(--gray-light); overflow: hidden; transition: 0.2s; }
        .timeline-item:hover { transform: translateX(5px); box-shadow: 0 8px 25px rgba(0,0,0,0.08); }
        .timeline-badge { position: absolute; left: -30px; top: 20px; width: 40px; height: 40px; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; font-size: 18px; z-index: 1; }
        .timeline-badge.primary { background: linear-gradient(135deg, var(--primary), var(--primary-dark)); }
        .timeline-badge.success { background: linear-gradient(135deg, var(--success), #059669); }
        .timeline-badge.danger { background: linear-gradient(135deg, var(--danger), #c1121f); }
        .timeline-badge.info { background: linear-gradient(135deg, var(--info), #0891b2); }
        .timeline-badge.warning { background: linear-gradient(135deg, var(--warning), #d97706); }
        .timeline-badge.secondary { background: linear-gradient(135deg, #64748b, #475569); }
        .timeline-content { padding: 18px 24px; }
        .timeline-header { display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; margin-bottom: 12px; }
        .activity-title { font-weight: 700; font-size: 16px; color: #0f172a !important; }
        .activity-time { font-size: 12px; color: #475569 !important; }
        .patient-info { background: #f8fafc; border-radius: 12px; padding: 10px 14px; margin: 12px 0; display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; }
        .patient-name { font-weight: 600; font-size: 14px; color: #0f172a !important; }
        .patient-meta { font-size: 11px; color: #475569 !important; }
        .badge-activity { padding: 4px 12px; border-radius: 30px; font-size: 11px; font-weight: 600; }
        .empty-state { text-align: center; padding: 60px; background: white; border-radius: 24px; border: 1px solid var(--gray-light); }
        .btn-export { border-radius: 40px; padding: 8px 20px; background: white; border: 1px solid var(--gray-light); transition: 0.2s; }
        .btn-export:hover { background: var(--primary); color: white; border-color: var(--primary); }
        .pagination .page-link { border-radius: 40px; margin: 0 3px; color: #475569; }
        .pagination .active .page-link { background: var(--primary); border-color: var(--primary); }
        @media (max-width: 768px) { .stats-grid { grid-template-columns: repeat(2, 1fr); } .timeline { padding-left: 20px; } .timeline-badge { width: 32px; height: 32px; font-size: 14px; left: -22px; top: 16px; } .timeline-content { padding: 12px 16px; } }
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
                        <div class="welcome-title"><i class="fas fa-clipboard-list me-2"></i> Nursing Activity Log</div>
                        <div class="welcome-subtitle">Clinical documentation timeline for <?php echo htmlspecialchars($department_name); ?></div>
                    </div>
                    <div class="col-md-4 text-md-end mt-3 mt-md-0">
                        <div class="time-badge">
                            <i class="far fa-calendar-alt"></i> <?php echo date('l, F j, Y'); ?>
                            <i class="far fa-clock ms-2"></i> <?php echo date('g:i A'); ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Statistics Cards -->
            <div class="stats-grid">
                <div class="stat-card"><div class="stat-icon text-primary"><i class="fas fa-chart-line"></i></div><div class="stat-value"><?php echo $summary['total']; ?></div><div class="stat-label">Total Activities</div></div>
                <div class="stat-card"><div class="stat-icon text-primary"><i class="fas fa-stethoscope"></i></div><div class="stat-value"><?php echo $summary['triage']; ?></div><div class="stat-label">Vitals / Triage</div></div>
                <div class="stat-card"><div class="stat-icon text-info"><i class="fas fa-flask"></i></div><div class="stat-value"><?php echo $summary['lab']; ?></div><div class="stat-label">Lab Orders</div></div>
                <div class="stat-card"><div class="stat-icon text-danger"><i class="fas fa-syringe"></i></div><div class="stat-value"><?php echo $summary['procedure']; ?></div><div class="stat-label">Procedures</div></div>
                <div class="stat-card"><div class="stat-icon text-success"><i class="fas fa-capsules"></i></div><div class="stat-value"><?php echo $summary['prescription']; ?></div><div class="stat-label">Prescriptions</div></div>
            </div>

            <!-- Filter Bar -->
            <div class="filter-bar">
                <form method="GET" action="" class="row g-3 align-items-end">
                    <div class="col-md-3">
                        <label class="form-label small fw-semibold">Start Date</label>
                        <input type="date" name="start_date" class="form-control" value="<?php echo htmlspecialchars($start_date); ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small fw-semibold">End Date</label>
                        <input type="date" name="end_date" class="form-control" value="<?php echo htmlspecialchars($end_date); ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small fw-semibold">Activity Type</label>
                        <select name="activity_type" class="form-select">
                            <option value="all" <?php echo $activity_type == 'all' ? 'selected' : ''; ?>>All Activities</option>
                            <option value="triage" <?php echo $activity_type == 'triage' ? 'selected' : ''; ?>>Vitals / Triage</option>
                            <option value="lab" <?php echo $activity_type == 'lab' ? 'selected' : ''; ?>>Lab Orders</option>
                            <option value="procedure" <?php echo $activity_type == 'procedure' ? 'selected' : ''; ?>>Procedures</option>
                            <option value="prescription" <?php echo $activity_type == 'prescription' ? 'selected' : ''; ?>>Prescriptions</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small fw-semibold">Patient Search</label>
                        <input type="text" name="patient_search" class="form-control" placeholder="Name or MRN..." value="<?php echo htmlspecialchars($patient_search); ?>">
                    </div>
                    <div class="col-md-12 mt-3">
                        <button type="submit" class="btn btn-primary"><i class="fas fa-search me-2"></i>Apply Filters</button>
                        <a href="activity_log.php" class="btn btn-secondary ms-2"><i class="fas fa-times me-2"></i>Clear</a>
                        <button type="button" class="btn btn-export float-end" onclick="window.location.href='?export=csv&<?php echo http_build_query($_GET); ?>'"><i class="fas fa-download me-2"></i>Export CSV</button>
                    </div>
                </form>
            </div>

            <!-- Activity Timeline -->
            <?php if (empty($activities)): ?>
                <div class="empty-state"><i class="fas fa-clipboard-list fa-3x text-muted mb-3"></i><h5>No Activities Found</h5><p class="text-muted">No nursing activities recorded for the selected date range.</p></div>
            <?php else: ?>
                <div class="timeline">
                    <?php foreach ($activities as $act): ?>
                        <div class="timeline-item">
                            <div class="timeline-badge <?php echo getActivityColor($act['activity_type']); ?>">
                                <i class="fas <?php echo getActivityIcon($act['activity_type']); ?>"></i>
                            </div>
                            <div class="timeline-content">
                                <div class="timeline-header">
                                    <div>
                                        <span class="activity-title"><i class="fas <?php echo getActivityIcon($act['activity_type']); ?> me-2 text-<?php echo getActivityColor($act['activity_type']); ?>"></i><?php echo htmlspecialchars($act['activity_name']); ?></span>
                                        <span class="badge-activity bg-<?php echo getActivityColor($act['activity_type']); ?> bg-opacity-10 text-<?php echo getActivityColor($act['activity_type']); ?> ms-2"><?php echo strtoupper(str_replace('_', ' ', $act['activity_type'])); ?></span>
                                    </div>
                                    <div class="activity-time"><i class="far fa-clock me-1"></i><?php echo date('M d, Y g:i A', strtotime($act['activity_time'])); ?></div>
                                </div>
                                <div class="patient-info">
                                    <div>
                                        <div class="patient-name"><i class="fas fa-user-injured me-2 text-primary"></i><?php echo htmlspecialchars($act['full_name']); ?></div>
                                        <div class="patient-meta">MRN: <?php echo htmlspecialchars($act['mr_number']); ?> | <?php echo ucfirst($act['gender'] ?? 'N/A'); ?>, <?php echo $act['age']; ?>y <?php if ($act['bed_code']): ?>| Bed: <?php echo htmlspecialchars($act['bed_code']); endif; ?></div>
                                    </div>
                                    <div class="patient-meta"><i class="fas fa-user-md me-1"></i><?php echo htmlspecialchars($act['staff_name'] ?? 'System'); ?></div>
                                </div>
                                <?php echo $act['formatted_details']; ?>
                                <?php if (!empty($act['notes']) && $act['activity_type'] != 'triage'): ?>
                                    <div class="mt-2 p-2 bg-light rounded small"><i class="fas fa-comment-dots me-1 text-muted"></i><?php echo htmlspecialchars(substr($act['notes'], 0, 200)); ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                <nav class="mt-4"><ul class="pagination justify-content-center">
                    <li class="page-item <?php echo ($page <= 1) ? 'disabled' : ''; ?>"><a class="page-link" href="?page=<?php echo $page-1; ?>&<?php echo http_build_query(array_merge($_GET, ['page' => $page-1])); ?>"><i class="fas fa-chevron-left"></i></a></li>
                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                        <li class="page-item <?php echo ($i == $page) ? 'active' : ''; ?>"><a class="page-link" href="?page=<?php echo $i; ?>&<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>"><?php echo $i; ?></a></li>
                    <?php endfor; ?>
                    <li class="page-item <?php echo ($page >= $total_pages) ? 'disabled' : ''; ?>"><a class="page-link" href="?page=<?php echo $page+1; ?>&<?php echo http_build_query(array_merge($_GET, ['page' => $page+1])); ?>"><i class="fas fa-chevron-right"></i></a></li>
                </ul></nav>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php include('assets/inc/footer.php'); ?>
    </div>
</div>

<script src="assets/js/vendor.min.js"></script>
<script src="assets/js/bootstrap.bundle.min.js"></script>
<script>
const sidebarToggle = document.querySelector('.button-menu-mobile');
if (sidebarToggle) sidebarToggle.addEventListener('click', () => document.body.classList.toggle('sidebar-enable'));
console.log('✅ Activity Log v7.1 - Fixed duplicate column');
</script>
</body>
</html>