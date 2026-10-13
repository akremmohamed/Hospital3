<?php
/**
 * laboratory_dashboard.php
 * 
 * HMS ERP - Laboratory Dashboard
 * Fixed version - properly handles missing tables and columns
 * 
 * @package HMS_ERP
 * @version 2.1
 */

// ============================================
// 1. SECURITY & SESSION VALIDATION
// ============================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../config/db_connection.php';
require_once __DIR__ . '/../../includes/auth_helper.php';

date_default_timezone_set('Africa/Addis_Ababa');

// Validate user session
$user_id = (int)($_SESSION['user_id'] ?? 0);
$branch_id = (int)($_SESSION['branch_id'] ?? 0);
$user_type = $_SESSION['user_type'] ?? '';
$is_global_admin = ($user_type === 'super_admin');

if ($user_id <= 0) {
    header('Location: login.php');
    exit;
}

// Get branch name
$branch_name = 'All Branches';
if ($branch_id > 0) {
    try {
        $stmt = $pdo->prepare("SELECT name FROM branches WHERE id = :id AND deleted_at IS NULL");
        $stmt->execute([':id' => $branch_id]);
        $branch = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($branch) $branch_name = $branch['name'];
    } catch (Exception $e) {
        error_log('Branch name query error: ' . $e->getMessage());
    }
}

// ============================================
// 2. LABORATORY DASHBOARD DATA FETCHING
// ============================================

// Initialize all data arrays with default values
$workflow_data = [
    'pending_payment' => 0,
    'ordered' => 0,
    'collected' => 0,
    'in_progress' => 0,
    'completed' => 0,
    'verified' => 0,
    'cancelled' => 0,
    'rejected' => 0
];

$test_volume = [
    'total_tests' => 0,
    'today_tests' => 0,
    'this_week' => 0,
    'this_month' => 0,
    'stat_tests' => 0,
    'routine_tests' => 0,
    'urgent_tests' => 0
];

$revenue_data = [
    'total_revenue' => 0,
    'today_revenue' => 0,
    'this_month_revenue' => 0,
    'pending_amount' => 0
];

$qc_metrics = [
    'abnormal_results' => 0,
    'critical_results' => 0,
    'unverified_results' => 0,
    'pending_review' => 0,
    'tat_exceeded' => 0,
    'avg_tat_hours' => 0
];

$inventory_alerts = [
    'low_stock' => 0,
    'expiring_soon' => 0,
    'out_of_stock' => 0
];

$staff_productivity = [
    'total_orders_processed' => 0,
    'tests_per_tech' => 0
];

$equipment_status = [
    'operational' => 0,
    'maintenance' => 0,
    'broken' => 0
];

$recent_activities = [];
$critical_alerts = [];
$notifications = [];
$error_message = '';
$data_loaded = false;

// Check if PDO connection exists
if (!isset($pdo) || !($pdo instanceof PDO)) {
    $error_message = 'Database connection failed. Please check your configuration.';
} else {
    try {
        // Set timezone
        $pdo->exec("SET time_zone = '+03:00'");
        
        // Build branch filter
        $branch_filter = "";
        $params = [];
        if (!$is_global_admin && $branch_id > 0) {
            $branch_filter = " AND branch_id = :branch_id";
            $params[':branch_id'] = $branch_id;
        }

        // ============================================
        // 2.1. WORKFLOW PHASE COUNTS
        // ============================================
        try {
            $sql = "SELECT status, COUNT(*) as count 
                    FROM lab_orders 
                    WHERE deleted_at IS NULL" . $branch_filter . "
                    GROUP BY status";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                if (isset($workflow_data[$row['status']])) {
                    $workflow_data[$row['status']] = (int)$row['count'];
                }
            }
        } catch (PDOException $e) {
            error_log('Workflow query error: ' . $e->getMessage());
        }

        // ============================================
        // 2.2. TEST VOLUME STATISTICS
        // ============================================
        try {
            // Total tests
            $sql = "SELECT COUNT(*) as total 
                    FROM lab_orders
                    WHERE deleted_at IS NULL" . $branch_filter;
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $test_volume['total_tests'] = (int)$stmt->fetchColumn();

            // Today's tests
            $sql = "SELECT COUNT(*) as today 
                    FROM lab_orders
                    WHERE deleted_at IS NULL" . $branch_filter . "
                    AND DATE(created_at) = CURDATE()";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $test_volume['today_tests'] = (int)$stmt->fetchColumn();

            // This week's tests
            $sql = "SELECT COUNT(*) as week 
                    FROM lab_orders
                    WHERE deleted_at IS NULL" . $branch_filter . "
                    AND YEARWEEK(created_at) = YEARWEEK(CURDATE())";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $test_volume['this_week'] = (int)$stmt->fetchColumn();

            // This month's tests
            $sql = "SELECT COUNT(*) as month 
                    FROM lab_orders
                    WHERE deleted_at IS NULL" . $branch_filter . "
                    AND MONTH(created_at) = MONTH(CURDATE()) 
                    AND YEAR(created_at) = YEAR(CURDATE())";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $test_volume['this_month'] = (int)$stmt->fetchColumn();

            // Priority breakdown
            $sql = "SELECT priority, COUNT(*) as count 
                    FROM lab_orders 
                    WHERE deleted_at IS NULL 
                    AND status NOT IN ('cancelled', 'rejected')" . $branch_filter . "
                    GROUP BY priority";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                switch ($row['priority']) {
                    case 'stat':
                        $test_volume['stat_tests'] = (int)$row['count'];
                        break;
                    case 'urgent':
                        $test_volume['urgent_tests'] = (int)$row['count'];
                        break;
                    case 'routine':
                        $test_volume['routine_tests'] = (int)$row['count'];
                        break;
                }
            }
        } catch (PDOException $e) {
            error_log('Test volume query error: ' . $e->getMessage());
        }

        // ============================================
        // 2.3. REVENUE & BILLING
        // ============================================
        try {
            // Check if test_id column exists in lab_orders
            $columns = $pdo->query("SHOW COLUMNS FROM lab_orders LIKE 'test_id'")->fetch();
            if ($columns) {
                // test_id exists, join with lab_tests_master
                $sql = "SELECT SUM(lmt.price) as total 
                        FROM lab_orders lo
                        INNER JOIN lab_tests_master lmt ON lo.test_id = lmt.id
                        WHERE lo.deleted_at IS NULL 
                        AND lo.status NOT IN ('cancelled', 'rejected')" . $branch_filter;
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $revenue_data['total_revenue'] = (float)($stmt->fetchColumn() ?: 0);

                // Today's revenue
                $sql = "SELECT SUM(lmt.price) as today 
                        FROM lab_orders lo
                        INNER JOIN lab_tests_master lmt ON lo.test_id = lmt.id
                        WHERE lo.deleted_at IS NULL 
                        AND lo.status NOT IN ('cancelled', 'rejected')" . $branch_filter . "
                        AND DATE(lo.created_at) = CURDATE()";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $revenue_data['today_revenue'] = (float)($stmt->fetchColumn() ?: 0);

                // This month's revenue
                $sql = "SELECT SUM(lmt.price) as month 
                        FROM lab_orders lo
                        INNER JOIN lab_tests_master lmt ON lo.test_id = lmt.id
                        WHERE lo.deleted_at IS NULL 
                        AND lo.status NOT IN ('cancelled', 'rejected')" . $branch_filter . "
                        AND MONTH(lo.created_at) = MONTH(CURDATE()) 
                        AND YEAR(lo.created_at) = YEAR(CURDATE())";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $revenue_data['this_month_revenue'] = (float)($stmt->fetchColumn() ?: 0);
            } else {
                // test_id doesn't exist, use lab_order_items or lab_test_results
                // Try lab_order_items
                $columns = $pdo->query("SHOW COLUMNS FROM lab_order_items LIKE 'lab_order_id'")->fetch();
                if ($columns) {
                    $sql = "SELECT COUNT(DISTINCT lo.id) as total 
                            FROM lab_orders lo
                            WHERE lo.deleted_at IS NULL 
                            AND lo.status NOT IN ('cancelled', 'rejected')" . $branch_filter;
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($params);
                    $revenue_data['total_revenue'] = (float)($stmt->fetchColumn() ?: 0) * 100; // Estimate
                }
            }

            // Pending payments - use lab_orders table directly
            $sql = "SELECT COUNT(*) as pending 
                    FROM lab_orders 
                    WHERE payment_status IN ('unpaid', 'partial')
                    AND status NOT IN ('cancelled', 'rejected')
                    AND deleted_at IS NULL" . $branch_filter;
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $revenue_data['pending_amount'] = (float)($stmt->fetchColumn() ?: 0);
            
        } catch (PDOException $e) {
            error_log('Revenue query error: ' . $e->getMessage());
        }

        // ============================================
        // 2.4. QUALITY CONTROL METRICS
        // ============================================
        // Check if lab_test_components table exists and has the required columns
        $tables = $pdo->query("SHOW TABLES LIKE 'lab_test_components'")->fetchAll();
        $has_lab_test_components = !empty($tables);
        
        if ($has_lab_test_components) {
            try {
                // Check columns in lab_test_components
                $columns = $pdo->query("SHOW COLUMNS FROM lab_test_components")->fetchAll(PDO::FETCH_COLUMN);
                $has_is_abnormal = in_array('is_abnormal', $columns);
                $has_is_critical = in_array('is_critical', $columns);
                $has_verified_by = in_array('verified_by_user_id', $columns);
                $has_result_numeric = in_array('result_numeric', $columns);
                $has_result_text = in_array('result_text', $columns);
                
                if ($has_is_abnormal) {
                    $sql = "SELECT COUNT(DISTINCT lo.id) as abnormal 
                            FROM lab_orders lo
                            INNER JOIN lab_test_components ltc ON lo.id = ltc.lab_order_id
                            WHERE ltc.is_abnormal = 1
                            AND lo.deleted_at IS NULL
                            AND ltc.deleted_at IS NULL" . $branch_filter;
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($params);
                    $qc_metrics['abnormal_results'] = (int)$stmt->fetchColumn();
                }

                if ($has_is_critical) {
                    $sql = "SELECT COUNT(DISTINCT lo.id) as critical 
                            FROM lab_orders lo
                            INNER JOIN lab_test_components ltc ON lo.id = ltc.lab_order_id
                            WHERE ltc.is_critical = 1
                            AND lo.deleted_at IS NULL
                            AND ltc.deleted_at IS NULL" . $branch_filter;
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($params);
                    $qc_metrics['critical_results'] = (int)$stmt->fetchColumn();
                }

                if ($has_verified_by) {
                    $sql = "SELECT COUNT(DISTINCT lo.id) as unverified 
                            FROM lab_orders lo
                            INNER JOIN lab_test_components ltc ON lo.id = ltc.lab_order_id
                            WHERE lo.status = 'completed'
                            AND ltc.verified_by_user_id IS NULL
                            AND lo.deleted_at IS NULL
                            AND ltc.deleted_at IS NULL" . $branch_filter;
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($params);
                    $qc_metrics['unverified_results'] = (int)$stmt->fetchColumn();
                }
            } catch (PDOException $e) {
                error_log('QC metrics query error: ' . $e->getMessage());
            }
        }

        // TAT exceeded (orders older than 3 days not completed)
        try {
            $sql = "SELECT COUNT(*) as tat_exceeded 
                    FROM lab_orders
                    WHERE status NOT IN ('completed', 'cancelled', 'rejected')
                    AND created_at <= DATE_SUB(NOW(), INTERVAL 3 DAY)
                    AND deleted_at IS NULL" . $branch_filter;
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $qc_metrics['tat_exceeded'] = (int)$stmt->fetchColumn();
        } catch (PDOException $e) {
            error_log('TAT query error: ' . $e->getMessage());
        }

        // Average TAT (completed orders)
        try {
            $sql = "SELECT AVG(TIMESTAMPDIFF(HOUR, created_at, updated_at)) as avg_tat
                    FROM lab_orders
                    WHERE status = 'completed'
                    AND deleted_at IS NULL" . $branch_filter;
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $avg = (float)($stmt->fetchColumn() ?: 0);
            $qc_metrics['avg_tat_hours'] = round($avg, 1);
        } catch (PDOException $e) {
            error_log('Avg TAT query error: ' . $e->getMessage());
        }

        // ============================================
        // 2.5. INVENTORY ALERTS
        // ============================================
        try {
            $inv_params = [];
            $inv_filter = "";
            if (!$is_global_admin && $branch_id > 0) {
                $inv_filter = " AND pi.branch_id = :branch_id";
                $inv_params[':branch_id'] = $branch_id;
            }

            // Check if tables exist
            $tables = $pdo->query("SHOW TABLES LIKE 'pharmacy_inventory'")->fetchAll();
            if (!empty($tables)) {
                // Check columns in pharmacy_inventory
                $columns = $pdo->query("SHOW COLUMNS FROM pharmacy_inventory")->fetchAll(PDO::FETCH_COLUMN);
                $has_quantity_in_hand = in_array('quantity_in_hand', $columns);
                $has_reorder_level = in_array('reorder_level', $columns);
                $has_expiry_date = in_array('expiry_date', $columns);
                $has_medicine_id = in_array('medicine_id', $columns);
                
                if ($has_quantity_in_hand && $has_reorder_level && $has_medicine_id) {
                    // Low stock - use COALESCE for reorder_level if it might be NULL
                    $sql = "SELECT COUNT(*) as low_stock 
                            FROM pharmacy_inventory pi
                            INNER JOIN medicines_master mm ON pi.medicine_id = mm.id
                            WHERE pi.quantity_in_hand <= COALESCE(pi.reorder_level, 5)
                            AND pi.deleted_at IS NULL
                            AND mm.deleted_at IS NULL
                            AND mm.is_active = 1" . $inv_filter;
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($inv_params);
                    $inventory_alerts['low_stock'] = (int)$stmt->fetchColumn();

                    // Out of stock
                    $sql = "SELECT COUNT(*) as out_of_stock 
                            FROM pharmacy_inventory pi
                            INNER JOIN medicines_master mm ON pi.medicine_id = mm.id
                            WHERE pi.quantity_in_hand <= 0
                            AND pi.deleted_at IS NULL
                            AND mm.deleted_at IS NULL
                            AND mm.is_active = 1" . $inv_filter;
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($inv_params);
                    $inventory_alerts['out_of_stock'] = (int)$stmt->fetchColumn();
                }

                if ($has_expiry_date && $has_quantity_in_hand && $has_medicine_id) {
                    // Expiring soon
                    $sql = "SELECT COUNT(*) as expiring 
                            FROM pharmacy_inventory pi
                            INNER JOIN medicines_master mm ON pi.medicine_id = mm.id
                            WHERE pi.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
                            AND pi.expiry_date > CURDATE()
                            AND pi.deleted_at IS NULL
                            AND mm.deleted_at IS NULL
                            AND mm.is_active = 1
                            AND pi.quantity_in_hand > 0" . $inv_filter;
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($inv_params);
                    $inventory_alerts['expiring_soon'] = (int)$stmt->fetchColumn();
                }
            }
        } catch (PDOException $e) {
            error_log('Inventory query error: ' . $e->getMessage());
        }

        // ============================================
        // 2.6. STAFF PRODUCTIVITY
        // ============================================
        try {
            $sql = "SELECT COUNT(*) as processed 
                    FROM lab_orders
                    WHERE status IN ('completed', 'verified')
                    AND deleted_at IS NULL" . $branch_filter;
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $staff_productivity['total_orders_processed'] = (int)$stmt->fetchColumn();
        } catch (PDOException $e) {
            error_log('Staff productivity query error: ' . $e->getMessage());
        }

        // ============================================
        // 2.7. EQUIPMENT STATUS
        // ============================================
        try {
            // Check if medical_equipment_assets table exists
            $tables = $pdo->query("SHOW TABLES LIKE 'medical_equipment_assets'")->fetchAll();
            if (!empty($tables)) {
                $eq_params = [];
                $eq_filter = "";
                if (!$is_global_admin && $branch_id > 0) {
                    $eq_filter = " AND branch_id = :branch_id";
                    $eq_params[':branch_id'] = $branch_id;
                }
                $sql = "SELECT current_status, COUNT(*) as count 
                        FROM medical_equipment_assets
                        WHERE deleted_at IS NULL" . $eq_filter . "
                        GROUP BY current_status";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($eq_params);
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    switch ($row['current_status']) {
                        case 'operational':
                            $equipment_status['operational'] = (int)$row['count'];
                            break;
                        case 'under_maintenance':
                            $equipment_status['maintenance'] = (int)$row['count'];
                            break;
                        case 'broken_down':
                        case 'decommissioned':
                            $equipment_status['broken'] += (int)$row['count'];
                            break;
                    }
                }
            }
        } catch (PDOException $e) {
            error_log('Equipment query error: ' . $e->getMessage());
        }

        // ============================================
        // 2.8. RECENT ACTIVITIES
        // ============================================
        try {
            $sql = "SELECT 
                        lo.id, lo.order_number, lo.status, lo.priority, lo.created_at,
                        CONCAT(p.first_name, ' ', COALESCE(p.middle_name, ''), ' ', p.last_name) as patient_name,
                        CONCAT(u.first_name, ' ', u.last_name) as doctor_name
                    FROM lab_orders lo
                    LEFT JOIN patients p ON lo.patient_id = p.id
                    LEFT JOIN users u ON lo.doctor_id = u.id
                    WHERE lo.deleted_at IS NULL" . $branch_filter . "
                    ORDER BY lo.created_at DESC
                    LIMIT 10";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $recent_activities = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log('Recent activities query error: ' . $e->getMessage());
        }

        // Critical alerts - only if lab_test_components exists
        if ($has_lab_test_components && $qc_metrics['critical_results'] > 0) {
            try {
                $sql = "SELECT 
                            lo.id, lo.order_number, 
                            ltc.test_component_name as test_name, 
                            ltc.result_numeric, ltc.result_text,
                            ltc.is_critical, ltc.created_at,
                            CONCAT(p.first_name, ' ', COALESCE(p.middle_name, ''), ' ', p.last_name) as patient_name
                        FROM lab_test_components ltc
                        INNER JOIN lab_orders lo ON ltc.lab_order_id = lo.id
                        INNER JOIN patients p ON lo.patient_id = p.id
                        WHERE ltc.is_critical = 1
                        AND lo.deleted_at IS NULL
                        AND ltc.deleted_at IS NULL" . $branch_filter . "
                        ORDER BY ltc.created_at DESC
                        LIMIT 5";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $critical_alerts = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (PDOException $e) {
                error_log('Critical alerts query error: ' . $e->getMessage());
            }
        }

        // Notifications
        try {
            $sql = "SELECT title, message, created_at 
                    FROM notifications_tracker 
                    WHERE user_id = :user_id 
                    AND is_read = 0 
                    AND deleted_at IS NULL 
                    ORDER BY created_at DESC 
                    LIMIT 5";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':user_id' => $user_id]);
            $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log('Notifications query error: ' . $e->getMessage());
        }

        $data_loaded = true;

    } catch (Throwable $e) {
        error_log('Lab Dashboard Fatal Error: ' . $e->getMessage() . ' in ' . $e->getFile() . ' line ' . $e->getLine());
        $error_message = 'Unable to load dashboard data. Please try again later.';
        $data_loaded = false;
    }
}

// ============================================
// 3. CALCULATE SUMMARY STATISTICS
// ============================================
$total_active_orders = array_sum($workflow_data) - ($workflow_data['cancelled'] ?? 0) - ($workflow_data['rejected'] ?? 0);
$completion_rate = ($test_volume['total_tests'] ?? 0) > 0 ? 
    round((($staff_productivity['total_orders_processed'] ?? 0) / ($test_volume['total_tests'] ?? 1)) * 100, 1) : 0;
$pending_tests = ($workflow_data['ordered'] ?? 0) + ($workflow_data['pending_payment'] ?? 0) + 
                  ($workflow_data['collected'] ?? 0) + ($workflow_data['in_progress'] ?? 0);
$total_alerts = ($qc_metrics['critical_results'] ?? 0) + ($qc_metrics['tat_exceeded'] ?? 0) + 
                ($inventory_alerts['low_stock'] ?? 0) + ($inventory_alerts['expiring_soon'] ?? 0);

// Get daily counts for chart
$daily_counts = [];
try {
    $chart_params = [];
    $chart_filter = "";
    if (!$is_global_admin && $branch_id > 0) {
        $chart_filter = " AND branch_id = :branch_id";
        $chart_params[':branch_id'] = $branch_id;
    }
    for ($i = 6; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-$i days"));
        $sql = "SELECT COUNT(*) as count 
                FROM lab_orders 
                WHERE DATE(created_at) = :date 
                AND deleted_at IS NULL" . $chart_filter;
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge([':date' => $date], $chart_params));
        $daily_counts[$date] = (int)$stmt->fetchColumn();
    }
} catch (Exception $e) {
    error_log('Daily counts query error: ' . $e->getMessage());
    // Fill with zeros if query fails
    for ($i = 6; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-$i days"));
        $daily_counts[$date] = 0;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laboratory Dashboard | HMS ERP</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;
            background: #f0f2f5;
            color: #1f2937;
            overflow-x: hidden;
        }
        
        #wrapper {
            display: flex;
            min-height: 100vh;
        }
        
        #page-wrapper {
            flex: 1;
            margin-left: 260px;
            padding: 20px 24px 30px;
            transition: margin-left 0.3s ease;
            min-height: 100vh;
            padding-top: 90px;
        }
        
        body.erp-sidebar-collapsed #page-wrapper {
            margin-left: 80px;
        }
        
        @media (max-width: 768px) {
            #page-wrapper {
                margin-left: 0 !important;
                padding: 12px 16px 20px;
                padding-top: 80px;
            }
            body.sidebar-enable #page-wrapper {
                margin-left: 280px !important;
            }
        }
        
        .dashboard-container {
            max-width: 1440px;
            margin: 0 auto;
        }
        
        .dashboard-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
            flex-wrap: wrap;
            gap: 12px;
        }
        .dashboard-header h1 {
            font-size: 24px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .dashboard-header h1 i {
            color: #4361ee;
        }
        .dashboard-header .branch-badge {
            font-size: 13px;
            color: #6b7280;
            font-weight: 400;
        }
        .header-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        .btn {
            padding: 8px 16px;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            background: white;
            cursor: pointer;
            font-size: 13px;
            font-weight: 500;
            transition: all 0.2s;
            text-decoration: none;
            color: #1f2937;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .btn:hover {
            background: #f3f4f6;
            border-color: #4361ee;
        }
        .btn-primary {
            background: #4361ee;
            color: white;
            border-color: #4361ee;
        }
        .btn-primary:hover {
            background: #3a0ca3;
            border-color: #3a0ca3;
            color: white;
        }
        
        .error-message {
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #dc2626;
            padding: 16px 20px;
            border-radius: 12px;
            margin-bottom: 20px;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }
        .stat-card {
            background: white;
            padding: 18px 20px;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08);
            border-left: 4px solid #4361ee;
            transition: transform 0.2s;
        }
        .stat-card:hover { transform: translateY(-2px); }
        .stat-card .stat-icon {
            font-size: 18px;
            color: #6b7280;
            margin-bottom: 4px;
        }
        .stat-card .stat-value {
            font-size: 28px;
            font-weight: 700;
            margin: 2px 0;
        }
        .stat-card .stat-label {
            font-size: 13px;
            color: #6b7280;
        }
        .stat-card .stat-change {
            font-size: 12px;
            font-weight: 500;
            margin-top: 2px;
        }
        .stat-change.positive { color: #06d6a0; }
        .stat-change.negative { color: #ef476f; }
        
        .stat-card.primary { border-left-color: #4361ee; }
        .stat-card.success { border-left-color: #06d6a0; }
        .stat-card.warning { border-left-color: #f9c74f; }
        .stat-card.danger { border-left-color: #ef476f; }
        .stat-card.info { border-left-color: #4cc9f0; }
        .stat-card.purple { border-left-color: #8b5cf6; }
        
        .stat-value.danger { color: #ef476f; }
        .stat-value.success { color: #06d6a0; }
        .stat-value.warning { color: #d97706; }
        .stat-value.primary { color: #4361ee; }
        
        .dashboard-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 20px;
            margin-bottom: 24px;
        }
        .dashboard-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08);
            overflow: hidden;
        }
        .dashboard-card .card-header {
            padding: 14px 20px;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .dashboard-card .card-header h3 {
            font-size: 15px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .dashboard-card .card-header .badge {
            font-size: 11px;
            padding: 3px 10px;
            border-radius: 20px;
            font-weight: 600;
        }
        .badge-danger { background: #fee2e2; color: #dc2626; }
        .badge-warning { background: #fef3c7; color: #d97706; }
        .badge-success { background: #d1fae5; color: #059669; }
        .badge-info { background: #dbeafe; color: #2563eb; }
        .badge-secondary { background: #f3f4f6; color: #6b7280; }
        
        .dashboard-card .card-body {
            padding: 16px 20px;
        }
        
        .workflow-pipeline {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
            align-items: center;
        }
        .workflow-step {
            flex: 1;
            min-width: 75px;
            text-align: center;
            padding: 10px 6px;
            background: #f3f4f6;
            border-radius: 8px;
        }
        .workflow-step .step-number {
            font-size: 20px;
            font-weight: 700;
        }
        .workflow-step .step-label {
            font-size: 10px;
            color: #6b7280;
            margin-top: 2px;
        }
        .workflow-step.active { background: #dbeafe; }
        .workflow-step.completed { background: #d1fae5; }
        .workflow-step.pending { background: #fef3c7; }
        .workflow-step.blocked { background: #fee2e2; }
        .workflow-step .step-number.active { color: #4361ee; }
        .workflow-step .step-number.completed { color: #06d6a0; }
        .workflow-step .step-number.pending { color: #d97706; }
        .workflow-step .step-number.blocked { color: #ef476f; }
        
        .workflow-arrow {
            font-size: 16px;
            color: #6b7280;
            flex-shrink: 0;
        }
        
        .activity-item {
            padding: 10px 0;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 13px;
        }
        .activity-item:last-child { border-bottom: none; }
        .activity-status {
            font-size: 10px;
            padding: 2px 10px;
            border-radius: 12px;
            font-weight: 600;
            display: inline-block;
        }
        .status-ordered { background: #dbeafe; color: #2563eb; }
        .status-collected { background: #d1fae5; color: #059669; }
        .status-in_progress { background: #fef3c7; color: #d97706; }
        .status-completed { background: #d1fae5; color: #059669; }
        .status-cancelled { background: #fee2e2; color: #dc2626; }
        .status-pending_payment { background: #fef3c7; color: #d97706; }
        .status-rejected { background: #fee2e2; color: #dc2626; }
        
        .activity-time {
            font-size: 11px;
            color: #6b7280;
        }
        
        .alert-item {
            padding: 10px 0;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            align-items: flex-start;
            gap: 10px;
            font-size: 13px;
        }
        .alert-item:last-child { border-bottom: none; }
        .alert-icon { font-size: 16px; margin-top: 2px; }
        .alert-content { flex: 1; }
        .alert-title { font-weight: 600; }
        .text-danger { color: #ef476f; }
        .text-warning { color: #d97706; }
        .text-muted { color: #6b7280; }
        
        .chart-container {
            position: relative;
            height: 240px;
        }
        
        .no-data {
            text-align: center;
            padding: 30px 0;
            color: #6b7280;
        }
        .no-data i {
            font-size: 28px;
            display: block;
            margin-bottom: 8px;
            color: #d1d5db;
        }
        
        .dashboard-footer {
            text-align: center;
            padding: 20px 0;
            color: #6b7280;
            font-size: 12px;
            border-top: 1px solid #e5e7eb;
            margin-top: 20px;
        }
        
        @media (max-width: 992px) {
            .dashboard-grid {
                grid-template-columns: 1fr 1fr;
            }
        }
        @media (max-width: 768px) {
            #page-wrapper {
                padding: 12px 12px 20px;
                padding-top: 80px;
            }
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 10px;
            }
            .stat-card {
                padding: 14px 16px;
            }
            .stat-card .stat-value {
                font-size: 22px;
            }
            .dashboard-grid {
                grid-template-columns: 1fr;
                gap: 14px;
            }
            .workflow-pipeline {
                flex-wrap: nowrap;
                overflow-x: auto;
                padding-bottom: 8px;
            }
            .workflow-step {
                min-width: 60px;
                padding: 8px 4px;
            }
            .workflow-step .step-number {
                font-size: 16px;
            }
            .workflow-arrow {
                font-size: 12px;
            }
            .dashboard-header h1 {
                font-size: 20px;
            }
            .header-actions .btn span {
                display: none;
            }
        }
        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr 1fr;
                gap: 8px;
            }
            .stat-card {
                padding: 10px 12px;
            }
            .stat-card .stat-value {
                font-size: 18px;
            }
            .stat-card .stat-label {
                font-size: 11px;
            }
        }
        
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: #f3f4f6; border-radius: 4px; }
        ::-webkit-scrollbar-thumb { background: #e5e7eb; border-radius: 4px; }
        ::-webkit-scrollbar-thumb:hover { background: #6b7280; }
    </style>
</head>
<body>
<div id="wrapper">
    <?php include('assets/inc/nav.php'); ?>
    <?php include('assets/inc/sidebar.php'); ?>
    
    <div id="page-wrapper">
        <div class="dashboard-container">
            <!-- Dashboard Header -->
            <div class="dashboard-header">
                <h1>
                    <i class="fas fa-flask"></i> Laboratory Dashboard
                    <span class="branch-badge">
                        <i class="fas fa-map-marker-alt" style="font-size:12px;"></i>
                        <?php echo htmlspecialchars($branch_name, ENT_QUOTES, 'UTF-8'); ?>
                    </span>
                </h1>
                <div class="header-actions">
                    <a href="lab_orders_queue.php?action=new" class="btn btn-primary">
                        <i class="fas fa-plus"></i> <span>New Order</span>
                    </a>
                    <a href="lab_reports.php" class="btn">
                        <i class="fas fa-chart-bar"></i> <span>Reports</span>
                    </a>
                    <button class="btn" onclick="location.reload();" title="Refresh">
                        <i class="fas fa-sync-alt"></i>
                    </button>
                </div>
            </div>

            <?php if (!empty($error_message)): ?>
                <div class="error-message">
                    <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error_message); ?>
                </div>
            <?php endif; ?>

            <!-- ============================================ -->
            <!-- 1. SUMMARY STATISTICS -->
            <!-- ============================================ -->
            <div class="stats-grid">
                <div class="stat-card primary">
                    <div class="stat-icon"><i class="fas fa-flask"></i></div>
                    <div class="stat-value"><?php echo number_format($test_volume['total_tests'] ?? 0); ?></div>
                    <div class="stat-label">Total Tests</div>
                    <div class="stat-change <?php echo ($test_volume['today_tests'] ?? 0) > 0 ? 'positive' : ''; ?>">
                        <i class="fas fa-calendar-day"></i> <?php echo $test_volume['today_tests'] ?? 0; ?> today
                    </div>
                </div>
                
                <div class="stat-card warning">
                    <div class="stat-icon"><i class="fas fa-clock"></i></div>
                    <div class="stat-value <?php echo $pending_tests > 0 ? 'warning' : ''; ?>"><?php echo number_format($pending_tests); ?></div>
                    <div class="stat-label">Pending Tests</div>
                    <div class="stat-change">
                        <i class="fas fa-hourglass-half"></i> <?php echo $workflow_data['in_progress'] ?? 0; ?> in progress
                    </div>
                </div>
                
                <div class="stat-card success">
                    <div class="stat-icon"><i class="fas fa-check-circle"></i></div>
                    <div class="stat-value success"><?php echo number_format($staff_productivity['total_orders_processed'] ?? 0); ?></div>
                    <div class="stat-label">Completed Tests</div>
                    <div class="stat-change positive">
                        <i class="fas fa-percentage"></i> <?php echo $completion_rate; ?>% completion rate
                    </div>
                </div>
                
                <div class="stat-card danger">
                    <div class="stat-icon"><i class="fas fa-exclamation-triangle"></i></div>
                    <div class="stat-value danger"><?php echo number_format($total_alerts); ?></div>
                    <div class="stat-label">Active Alerts</div>
                    <div class="stat-change negative">
                        <i class="fas fa-bell"></i> <?php echo $qc_metrics['critical_results'] ?? 0; ?> critical
                    </div>
                </div>
                
                <div class="stat-card info">
                    <div class="stat-icon"><i class="fas fa-money-bill-wave"></i></div>
                    <div class="stat-value primary"><?php echo number_format($revenue_data['this_month_revenue'] ?? 0, 2); ?></div>
                    <div class="stat-label">Revenue (This Month)</div>
                    <div class="stat-change">
                        <i class="fas fa-wallet"></i> <?php echo number_format($revenue_data['today_revenue'] ?? 0, 2); ?> today
                    </div>
                </div>
                
                <div class="stat-card purple">
                    <div class="stat-icon"><i class="fas fa-hourglass-end"></i></div>
                    <div class="stat-value"><?php echo $qc_metrics['avg_tat_hours'] ?? 0; ?>h</div>
                    <div class="stat-label">Avg. Turnaround Time</div>
                    <div class="stat-change <?php echo ($qc_metrics['tat_exceeded'] ?? 0) > 0 ? 'negative' : 'positive'; ?>">
                        <i class="fas fa-clock"></i> <?php echo $qc_metrics['tat_exceeded'] ?? 0; ?> overdue
                    </div>
                </div>
            </div>

            <!-- ============================================ -->
            <!-- 2. WORKFLOW PIPELINE -->
            <!-- ============================================ -->
            <div class="dashboard-card" style="margin-bottom: 20px;">
                <div class="card-header">
                    <h3><i class="fas fa-sitemap"></i> Workflow Pipeline</h3>
                    <span class="badge badge-info"><?php echo $total_active_orders; ?> active orders</span>
                </div>
                <div class="card-body">
                    <div class="workflow-pipeline">
                        <?php
                        $steps = [
                            'pending_payment' => ['label' => 'Pending Payment', 'icon' => 'fa-credit-card', 'color' => 'pending'],
                            'ordered' => ['label' => 'Ordered', 'icon' => 'fa-clipboard-list', 'color' => 'active'],
                            'collected' => ['label' => 'Collected', 'icon' => 'fa-vial', 'color' => 'active'],
                            'in_progress' => ['label' => 'In Progress', 'icon' => 'fa-spinner fa-spin', 'color' => 'active'],
                            'completed' => ['label' => 'Completed', 'icon' => 'fa-check-circle', 'color' => 'completed'],
                            'verified' => ['label' => 'Verified', 'icon' => 'fa-check-double', 'color' => 'completed']
                        ];
                        $step_count = count($steps);
                        $idx = 0;
                        foreach ($steps as $key => $step):
                            $count = $workflow_data[$key] ?? 0;
                            $color_class = $step['color'];
                        ?>
                            <div class="workflow-step <?php echo $color_class; ?>">
                                <div class="step-number <?php echo $color_class; ?>"><?php echo number_format($count); ?></div>
                                <div class="step-label"><i class="fas <?php echo $step['icon']; ?>"></i> <?php echo $step['label']; ?></div>
                            </div>
                            <?php if ($idx < $step_count - 1): ?>
                                <span class="workflow-arrow"><i class="fas fa-chevron-right"></i></span>
                            <?php endif; ?>
                        <?php 
                            $idx++;
                        endforeach; 
                        ?>
                    </div>
                </div>
            </div>

            <!-- ============================================ -->
            <!-- 3. MAIN DASHBOARD GRID -->
            <!-- ============================================ -->
            <div class="dashboard-grid">
                <!-- 3.1. Test Volume Chart -->
                <div class="dashboard-card">
                    <div class="card-header">
                        <h3><i class="fas fa-chart-bar"></i> Test Volume (Last 7 Days)</h3>
                        <span class="badge badge-secondary"><?php echo $test_volume['this_week'] ?? 0; ?> this week</span>
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="testVolumeChart"></canvas>
                        </div>
                    </div>
                </div>

                <!-- 3.2. Priority Breakdown -->
                <div class="dashboard-card">
                    <div class="card-header">
                        <h3><i class="fas fa-tachometer-alt"></i> Priority Distribution</h3>
                        <span class="badge badge-danger"><?php echo $test_volume['stat_tests'] ?? 0; ?> STAT</span>
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="priorityChart"></canvas>
                        </div>
                    </div>
                </div>

                <!-- 3.3. Recent Activities -->
                <div class="dashboard-card">
                    <div class="card-header">
                        <h3><i class="fas fa-history"></i> Recent Orders</h3>
                        <a href="lab_orders_queue.php" class="btn" style="padding:4px 12px;font-size:12px;">View All</a>
                    </div>
                    <div class="card-body" style="max-height:320px;overflow-y:auto;">
                        <?php if (empty($recent_activities)): ?>
                            <div class="no-data">
                                <i class="fas fa-inbox"></i>
                                No recent orders
                            </div>
                        <?php else: ?>
                            <?php foreach ($recent_activities as $activity): ?>
                                <?php if (!is_array($activity)) continue; ?>
                                <div class="activity-item">
                                    <div>
                                        <strong><?php echo htmlspecialchars($activity['order_number'] ?? 'N/A'); ?></strong>
                                        <div style="font-size:12px;color:#6b7280;">
                                            <?php echo htmlspecialchars($activity['patient_name'] ?? 'Unknown Patient'); ?>
                                            <span style="margin:0 4px;">•</span>
                                            Dr. <?php echo htmlspecialchars($activity['doctor_name'] ?? 'N/A'); ?>
                                        </div>
                                    </div>
                                    <div style="text-align:right;">
                                        <span class="activity-status status-<?php echo str_replace('_', '', $activity['status'] ?? 'ordered'); ?>">
                                            <?php echo ucfirst(str_replace('_', ' ', $activity['status'] ?? 'Ordered')); ?>
                                        </span>
                                        <?php if (($activity['priority'] ?? '') === 'stat'): ?>
                                            <span class="badge badge-danger" style="font-size:9px;margin-left:4px;">STAT</span>
                                        <?php endif; ?>
                                        <div class="activity-time">
                                            <?php echo date('M d, H:i', strtotime($activity['created_at'] ?? 'now')); ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- 3.4. Critical Alerts -->
                <div class="dashboard-card">
                    <div class="card-header">
                        <h3><i class="fas fa-exclamation-triangle"></i> Critical Alerts</h3>
                        <span class="badge badge-danger"><?php echo $total_alerts; ?> active</span>
                    </div>
                    <div class="card-body" style="max-height:320px;overflow-y:auto;">
                        <?php if (empty($critical_alerts) && $total_alerts === 0): ?>
                            <div class="no-data">
                                <i class="fas fa-check-circle" style="color:#06d6a0;"></i>
                                No critical alerts
                            </div>
                        <?php else: ?>
                            <?php foreach ($critical_alerts as $alert): ?>
                                <?php if (!is_array($alert)) continue; ?>
                                <div class="alert-item">
                                    <span class="alert-icon text-danger">
                                        <i class="fas fa-bell"></i>
                                    </span>
                                    <div class="alert-content">
                                        <div class="alert-title">
                                            <?php echo htmlspecialchars($alert['test_name'] ?? 'Unknown Test'); ?>
                                            <span class="badge badge-danger" style="font-size:9px;">CRITICAL</span>
                                        </div>
                                        <div style="font-size:13px;">
                                            <?php echo htmlspecialchars($alert['patient_name'] ?? 'Unknown Patient'); ?>
                                            <span style="color:#6b7280;font-size:12px;">
                                                • <?php echo date('M d, H:i', strtotime($alert['created_at'] ?? 'now')); ?>
                                            </span>
                                        </div>
                                        <?php if (!empty($alert['result_text'])): ?>
                                            <div style="font-size:12px;color:#6b7280;">
                                                Result: <?php echo htmlspecialchars($alert['result_text']); ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            <?php if (empty($critical_alerts) && $total_alerts > 0): ?>
                                <div class="no-data" style="padding:10px 0;">
                                    <i class="fas fa-boxes" style="color:#d97706;"></i>
                                    <?php echo $inventory_alerts['low_stock'] ?? 0; ?> low stock items, 
                                    <?php echo $inventory_alerts['expiring_soon'] ?? 0; ?> expiring soon
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- 3.5. Inventory Alerts -->
                <div class="dashboard-card">
                    <div class="card-header">
                        <h3><i class="fas fa-boxes"></i> Inventory Alerts</h3>
                        <span class="badge badge-warning"><?php echo array_sum($inventory_alerts); ?> alerts</span>
                    </div>
                    <div class="card-body">
                        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;">
                            <div style="text-align:center;padding:14px 8px;background:#fee2e2;border-radius:8px;">
                                <div style="font-size:24px;font-weight:700;color:#dc2626;"><?php echo $inventory_alerts['out_of_stock'] ?? 0; ?></div>
                                <div style="font-size:11px;color:#dc2626;">Out of Stock</div>
                            </div>
                            <div style="text-align:center;padding:14px 8px;background:#fef3c7;border-radius:8px;">
                                <div style="font-size:24px;font-weight:700;color:#d97706;"><?php echo $inventory_alerts['low_stock'] ?? 0; ?></div>
                                <div style="font-size:11px;color:#d97706;">Low Stock</div>
                            </div>
                            <div style="text-align:center;padding:14px 8px;background:#dbeafe;border-radius:8px;">
                                <div style="font-size:24px;font-weight:700;color:#2563eb;"><?php echo $inventory_alerts['expiring_soon'] ?? 0; ?></div>
                                <div style="font-size:11px;color:#2563eb;">Expiring Soon</div>
                            </div>
                        </div>
                        <div style="margin-top:12px;text-align:center;">
                            <a href="lab_inventory.php" class="btn" style="font-size:12px;">
                                <i class="fas fa-arrow-right"></i> Manage Inventory
                            </a>
                        </div>
                    </div>
                </div>

                <!-- 3.6. Equipment Status -->
                <div class="dashboard-card">
                    <div class="card-header">
                        <h3><i class="fas fa-microscope"></i> Equipment Status</h3>
                        <span class="badge badge-info"><?php echo array_sum($equipment_status); ?> assets</span>
                    </div>
                    <div class="card-body">
                        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;">
                            <div style="text-align:center;padding:14px 8px;background:#d1fae5;border-radius:8px;">
                                <div style="font-size:24px;font-weight:700;color:#059669;"><?php echo $equipment_status['operational'] ?? 0; ?></div>
                                <div style="font-size:11px;color:#059669;">Operational</div>
                            </div>
                            <div style="text-align:center;padding:14px 8px;background:#fef3c7;border-radius:8px;">
                                <div style="font-size:24px;font-weight:700;color:#d97706;"><?php echo $equipment_status['maintenance'] ?? 0; ?></div>
                                <div style="font-size:11px;color:#d97706;">Maintenance</div>
                            </div>
                            <div style="text-align:center;padding:14px 8px;background:#fee2e2;border-radius:8px;">
                                <div style="font-size:24px;font-weight:700;color:#dc2626;"><?php echo $equipment_status['broken'] ?? 0; ?></div>
                                <div style="font-size:11px;color:#dc2626;">Out of Service</div>
                            </div>
                        </div>
                        <?php if (($equipment_status['maintenance'] ?? 0) > 0 || ($equipment_status['broken'] ?? 0) > 0): ?>
                            <div style="margin-top:10px;padding:8px 12px;background:#fef3c7;border-radius:6px;font-size:12px;color:#92400e;">
                                <i class="fas fa-tools"></i> 
                                <?php echo ($equipment_status['maintenance'] ?? 0) + ($equipment_status['broken'] ?? 0); ?> equipment 
                                requires attention
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- 3.7. Quality Control Summary -->
                <div class="dashboard-card">
                    <div class="card-header">
                        <h3><i class="fas fa-clipboard-check"></i> Quality Control</h3>
                        <span class="badge badge-secondary">QC Metrics</span>
                    </div>
                    <div class="card-body">
                        <div style="display:grid;grid-template-columns:repeat(2,1fr);gap:10px;">
                            <div style="padding:12px;background:#f3f4f6;border-radius:6px;text-align:center;">
                                <div style="font-size:20px;font-weight:700;color:#ef476f;"><?php echo $qc_metrics['abnormal_results'] ?? 0; ?></div>
                                <div style="font-size:11px;color:#6b7280;">Abnormal Results</div>
                            </div>
                            <div style="padding:12px;background:#f3f4f6;border-radius:6px;text-align:center;">
                                <div style="font-size:20px;font-weight:700;color:#ef476f;"><?php echo $qc_metrics['critical_results'] ?? 0; ?></div>
                                <div style="font-size:11px;color:#6b7280;">Critical Results</div>
                            </div>
                            <div style="padding:12px;background:#f3f4f6;border-radius:6px;text-align:center;">
                                <div style="font-size:20px;font-weight:700;color:#d97706;"><?php echo $qc_metrics['unverified_results'] ?? 0; ?></div>
                                <div style="font-size:11px;color:#6b7280;">Unverified</div>
                            </div>
                            <div style="padding:12px;background:#f3f4f6;border-radius:6px;text-align:center;">
                                <div style="font-size:20px;font-weight:700;color:#6b7280;"><?php echo $qc_metrics['pending_review'] ?? 0; ?></div>
                                <div style="font-size:11px;color:#6b7280;">Pending Review</div>
                            </div>
                        </div>
                        <div style="margin-top:12px;text-align:center;">
                            <a href="quality_control.php" class="btn" style="font-size:12px;">
                                <i class="fas fa-arrow-right"></i> QC Dashboard
                            </a>
                        </div>
                    </div>
                </div>

                <!-- 3.8. Notifications -->
                <div class="dashboard-card">
                    <div class="card-header">
                        <h3><i class="fas fa-bell"></i> Notifications</h3>
                        <span class="badge badge-secondary"><?php echo count($notifications); ?> unread</span>
                    </div>
                    <div class="card-body" style="max-height:250px;overflow-y:auto;">
                        <?php if (empty($notifications)): ?>
                            <div class="no-data" style="padding:15px 0;">
                                <i class="fas fa-check-circle" style="color:#06d6a0;"></i>
                                No new notifications
                            </div>
                        <?php else: ?>
                            <?php foreach ($notifications as $notif): ?>
                                <?php if (!is_array($notif)) continue; ?>
                                <div class="alert-item" style="border-bottom:1px solid #e5e7eb;padding:8px 0;">
                                    <span class="alert-icon"><i class="fas fa-info-circle" style="color:#4361ee;"></i></span>
                                    <div class="alert-content">
                                        <div style="font-weight:500;font-size:13px;"><?php echo htmlspecialchars($notif['title'] ?? 'Notification'); ?></div>
                                        <div style="font-size:12px;color:#6b7280;">
                                            <?php echo htmlspecialchars($notif['message'] ?? ''); ?>
                                            <span style="float:right;"><?php echo date('M d, H:i', strtotime($notif['created_at'] ?? 'now')); ?></span>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- ============================================ -->
            <!-- 4. FOOTER -->
            <!-- ============================================ -->
            <div class="dashboard-footer">
                <i class="fas fa-flask"></i> Laboratory Dashboard &bull; HMS ERP &bull; 
                Last updated: <?php echo date('Y-m-d H:i:s'); ?>
                <?php if ($branch_id > 0): ?>
                    &bull; Branch: <?php echo htmlspecialchars($branch_name, ENT_QUOTES, 'UTF-8'); ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- ============================================ -->
<!-- 5. CHARTS INITIALIZATION -->
<!-- ============================================ -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // 5.1. Test Volume Chart (Last 7 Days)
    const ctx1 = document.getElementById('testVolumeChart');
    if (ctx1) {
        <?php
        $dates = array_keys($daily_counts);
        $counts = array_values($daily_counts);
        ?>
        new Chart(ctx1, {
            type: 'bar',
            data: {
                labels: [
                    <?php
                    foreach ($dates as $d) {
                        echo "'" . date('M d', strtotime($d)) . "',";
                    }
                    ?>
                ],
                datasets: [{
                    label: 'Tests',
                    data: [
                        <?php
                        foreach ($counts as $count) {
                            echo $count . ',';
                        }
                        ?>
                    ],
                    backgroundColor: 'rgba(67, 97, 238, 0.7)',
                    borderColor: '#4361ee',
                    borderWidth: 2,
                    borderRadius: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: { stepSize: 1 }
                    }
                }
            }
        });
    }

    // 5.2. Priority Distribution Chart
    const ctx2 = document.getElementById('priorityChart');
    if (ctx2) {
        new Chart(ctx2, {
            type: 'doughnut',
            data: {
                labels: ['STAT', 'Urgent', 'Routine'],
                datasets: [{
                    data: [
                        <?php echo $test_volume['stat_tests'] ?? 0; ?>,
                        <?php echo $test_volume['urgent_tests'] ?? 0; ?>,
                        <?php echo $test_volume['routine_tests'] ?? 0; ?>
                    ],
                    backgroundColor: ['#ef476f', '#f9c74f', '#4361ee'],
                    borderWidth: 2,
                    borderColor: '#fff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            padding: 12,
                            usePointStyle: true,
                            pointStyle: 'circle'
                        }
                    }
                },
                cutout: '65%'
            }
        });
    }
});

// Auto-refresh every 60 seconds
setTimeout(function() {
    location.reload();
}, 60000);
</script>

</body>
</html>