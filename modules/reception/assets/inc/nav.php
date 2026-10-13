<?php
/**
 * Top Navigation Bar - HMS ERP System
 * Super Admin Style with Dashboard v15.2 Compatibility
 * FIXED: Notifications and Profile dropdowns working
 * 
 * @package HMS-ERP
 * @version 3.2
 */

// Use global PDO connection
global $pdo;

// Initialize variables
$active_user_id = (int)($_SESSION['user_id'] ?? 0);
$session_branch_id = (int)($_SESSION['branch_id'] ?? 0);
$is_global_admin = ($_SESSION['user_type'] ?? '') === 'super_admin';
$available_branches = [];

// User profile data
$nav_user = [
    'id' => $active_user_id,
    'name' => 'System User',
    'role' => 'User',
    'photo_path' => '',
    'email' => '',
    'user_type' => ''
];

$branch_name = 'All Branches';
$notification_count = 0;
$notifications = [];
$reminder_count = 0;
$emergency_count = 0;
$queue_count = 0;

// Global Telemetry Metrics
$critical_alerts_count = 0;
$live_inpatients = 0;
$today_total_visits = 0;
$recent_audits_count = 0;

// Helper function to check user permissions
if (!function_exists('nav_has_permission')) {
    function nav_has_permission($pdo, $user_id, $permission_key) {
        if (!$pdo || $user_id <= 0) return false;
        
        if (isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'super_admin') {
            return true;
        }
        
        static $permission_cache = [];
        $cache_key = $user_id . '_' . $permission_key;
        
        if (isset($permission_cache[$cache_key])) {
            return $permission_cache[$cache_key];
        }
        
        try {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) 
                FROM users u
                INNER JOIN role_permissions rp ON u.user_type = rp.role_name
                INNER JOIN system_permissions sp ON rp.permission_id = sp.id
                WHERE u.id = :user_id
                    AND sp.permission_key = :permission_key
                    AND u.is_active = 1
                    AND u.deleted_at IS NULL
                    AND rp.deleted_at IS NULL
                    AND sp.deleted_at IS NULL
            ");
            $stmt->execute([
                ':user_id' => $user_id,
                ':permission_key' => $permission_key
            ]);
            $result = (bool)$stmt->fetchColumn();
            $permission_cache[$cache_key] = $result;
            return $result;
        } catch (PDOException $e) {
            error_log("Permission check failed for user {$user_id}: " . $e->getMessage());
            return false;
        }
    }
}

// Helper function to check if user has any permission from a list
if (!function_exists('nav_has_any_permission')) {
    function nav_has_any_permission($pdo, $user_id, $permission_keys) {
        if (isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'super_admin') {
            return true;
        }
        foreach ($permission_keys as $key) {
            if (nav_has_permission($pdo, $user_id, $key)) {
                return true;
            }
        }
        return false;
    }
}

// Fetch user data if PDO connection exists
if (isset($pdo) && $pdo instanceof PDO && $active_user_id > 0) {
    try {
        $stmt = $pdo->prepare("
            SELECT 
                u.id,
                u.first_name,
                u.middle_name,
                u.last_name,
                u.email,
                u.user_type,
                u.photo_path,
                u.branch_id,
                u.is_active,
                b.name as branch_name,
                b.is_hq as branch_is_hq
            FROM users u
            LEFT JOIN branches b ON u.branch_id = b.id AND b.deleted_at IS NULL
            WHERE u.id = :id 
                AND u.deleted_at IS NULL
            LIMIT 1
        ");
        $stmt->execute([':id' => $active_user_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($row) {
            $full_name = trim(
                ($row['first_name'] ?? '') . ' ' . 
                ($row['middle_name'] ?? '') . ' ' . 
                ($row['last_name'] ?? '')
            );
            $full_name = preg_replace('/\s+/', ' ', $full_name);
            $nav_user['name'] = $full_name !== '' ? $full_name : $nav_user['name'];
            $nav_user['role'] = ucfirst(str_replace('_', ' ', (string)($row['user_type'] ?? 'User')));
            $nav_user['photo_path'] = (string)($row['photo_path'] ?? '');
            $nav_user['email'] = (string)($row['email'] ?? '');
            $nav_user['user_type'] = (string)($row['user_type'] ?? '');
            
            if ($session_branch_id <= 0 && !empty($row['branch_id'])) {
                $session_branch_id = (int)$row['branch_id'];
                $_SESSION['branch_id'] = $session_branch_id;
            }
            
            if (!empty($row['branch_name'])) {
                $branch_name = $row['branch_name'];
            }
            
            $is_global_admin = ($row['user_type'] === 'super_admin');
            if (!$is_global_admin && !empty($row['branch_is_hq'])) {
                $is_global_admin = ($row['branch_is_hq'] == 1);
            }
        }
    } catch (PDOException $e) {
        error_log("Nav user query failed: " . $e->getMessage());
    }

    // Fetch available branches for HQ/Super Admin
    if ($is_global_admin) {
        try {
            $branchStmt = $pdo->prepare("
                SELECT id, name, code, city, branch_type, status, is_hq
                FROM branches 
                WHERE deleted_at IS NULL 
                    AND status = 'active'
                ORDER BY is_hq DESC, name ASC
            ");
            $branchStmt->execute();
            $available_branches = $branchStmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Branches fetch failed: " . $e->getMessage());
        }
    }

    // ============================================
    // GLOBAL TELEMETRY METRICS
    // ============================================
    try {
        // Critical lab results
        $sql = "SELECT COUNT(*) FROM lab_test_results WHERE is_critical = 1 AND DATE(created_at) = CURDATE() AND deleted_at IS NULL";
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        $critical_alerts_count = (int)$stmt->fetchColumn();

        // Active admissions
        $sql = "SELECT COUNT(*) FROM admissions WHERE status = 'admitted' AND deleted_at IS NULL";
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        $live_inpatients = (int)$stmt->fetchColumn();

        // Today's visits
        $sql = "SELECT COUNT(*) FROM patient_visits WHERE DATE(created_at) = CURDATE() AND deleted_at IS NULL";
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        $today_total_visits = (int)$stmt->fetchColumn();

        // Audit logs today
        $sql = "SELECT COUNT(*) FROM audit_logs WHERE DATE(created_at) = CURDATE()";
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        $recent_audits_count = (int)$stmt->fetchColumn();

    } catch (PDOException $e) {
        error_log('Navbar Metric Error: ' . $e->getMessage());
    }

    // Check table existence for feature availability
    $hasAppointments = false;
    $hasTriage = false;
    
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE 'appointments'");
        $hasAppointments = $stmt->rowCount() > 0;
        
        $stmt = $pdo->query("SHOW TABLES LIKE 'triage_records'");
        $hasTriage = $stmt->rowCount() > 0;
    } catch (PDOException $e) {
        error_log("Table existence check failed: " . $e->getMessage());
    }

    // Fetch dashboard counts based on user permissions
    if ($hasAppointments) {
        $can_view_appointments = nav_has_permission($pdo, $active_user_id, 'view_appointments') || $is_global_admin;
        
        if ($can_view_appointments) {
            try {
                if ($session_branch_id > 0 && !$is_global_admin) {
                    $stmt = $pdo->prepare("
                        SELECT COUNT(*) AS c
                        FROM appointments
                        WHERE branch_id = :branch_id
                            AND deleted_at IS NULL
                            AND DATE(created_at) = CURDATE()
                    ");
                    $stmt->execute([':branch_id' => $session_branch_id]);
                } else {
                    $stmt = $pdo->prepare("
                        SELECT COUNT(*) AS c 
                        FROM appointments 
                        WHERE deleted_at IS NULL 
                            AND DATE(created_at) = CURDATE()
                    ");
                    $stmt->execute();
                }
                $new_appts = (int)$stmt->fetchColumn();
                $notification_count = $new_appts;
                if ($new_appts > 0) {
                    $notifications[] = $new_appts . ' new appointment(s) booked today';
                }
            } catch (PDOException $e) {
                error_log("Appointment count failed: " . $e->getMessage());
            }
        }

        $can_send_reminders = nav_has_permission($pdo, $active_user_id, 'send_reminders') || $is_global_admin;
        
        if ($can_send_reminders) {
            try {
                if ($session_branch_id > 0 && !$is_global_admin) {
                    $stmt = $pdo->prepare("
                        SELECT COUNT(*) AS c
                        FROM appointments
                        WHERE branch_id = :branch_id
                            AND deleted_at IS NULL
                            AND appointment_date = DATE_ADD(CURDATE(), INTERVAL 1 DAY)
                            AND status IN ('scheduled', 'confirmed')
                    ");
                    $stmt->execute([':branch_id' => $session_branch_id]);
                } else {
                    $stmt = $pdo->prepare("
                        SELECT COUNT(*) AS c
                        FROM appointments
                        WHERE deleted_at IS NULL
                            AND appointment_date = DATE_ADD(CURDATE(), INTERVAL 1 DAY)
                            AND status IN ('scheduled', 'confirmed')
                    ");
                    $stmt->execute();
                }
                $reminder_count = (int)$stmt->fetchColumn();
            } catch (PDOException $e) {
                error_log("Reminder count failed: " . $e->getMessage());
            }
        }

        $can_view_queue = nav_has_permission($pdo, $active_user_id, 'view_live_queue') || $is_global_admin;
        
        if ($can_view_queue) {
            try {
                if ($session_branch_id > 0 && !$is_global_admin) {
                    $stmt = $pdo->prepare("
                        SELECT COUNT(*) AS c 
                        FROM appointments 
                        WHERE branch_id = :branch_id 
                            AND deleted_at IS NULL 
                            AND appointment_date = CURDATE() 
                            AND status IN ('checked_in', 'in_progress')
                    ");
                    $stmt->execute([':branch_id' => $session_branch_id]);
                } else {
                    $stmt = $pdo->prepare("
                        SELECT COUNT(*) AS c 
                        FROM appointments 
                        WHERE deleted_at IS NULL 
                            AND appointment_date = CURDATE() 
                            AND status IN ('checked_in', 'in_progress')
                    ");
                    $stmt->execute();
                }
                $queue_count = (int)$stmt->fetchColumn();
            } catch (PDOException $e) {
                error_log("Queue count failed: " . $e->getMessage());
            }
        }
    }

    // Fetch emergency count from triage
    if ($hasTriage) {
        $can_view_emergency = nav_has_permission($pdo, $active_user_id, 'view_emergency_alerts') || $is_global_admin;
        
        if ($can_view_emergency) {
            try {
                if ($session_branch_id > 0 && !$is_global_admin) {
                    $stmt = $pdo->prepare("
                        SELECT COUNT(*) AS c 
                        FROM triage_records tr
                        INNER JOIN patient_visits pv ON tr.visit_id = pv.id
                        WHERE tr.priority = 'red' 
                            AND DATE(tr.triage_time) = CURDATE() 
                            AND tr.deleted_at IS NULL
                            AND pv.branch_id = :branch_id
                    ");
                    $stmt->execute([':branch_id' => $session_branch_id]);
                } else {
                    $stmt = $pdo->prepare("
                        SELECT COUNT(*) AS c 
                        FROM triage_records 
                        WHERE priority = 'red' 
                            AND DATE(triage_time) = CURDATE() 
                            AND deleted_at IS NULL
                    ");
                    $stmt->execute();
                }
                $emergency_count = (int)$stmt->fetchColumn();
            } catch (PDOException $e) {
                error_log("Emergency count failed: " . $e->getMessage());
            }
        }
    }
    
    if (empty($notifications)) {
        $notifications[] = 'No new alerts';
    }
}

// Determine which quick links to show based on permissions
$show_reminders_link = nav_has_permission($pdo, $active_user_id, 'send_reminders') || $is_global_admin;
$show_queue_link = nav_has_permission($pdo, $active_user_id, 'view_live_queue') || $is_global_admin;
$show_emergency_link = nav_has_permission($pdo, $active_user_id, 'view_emergency_alerts') || $is_global_admin;
$show_notifications = nav_has_permission($pdo, $active_user_id, 'view_notifications') || $is_global_admin;

$branch_context = $is_global_admin ? "HQ Command Center (All Branches Hub)" : htmlspecialchars($branch_name, ENT_QUOTES, 'UTF-8');
?>

<style>
/* ============================================
   TOP NAVBAR STYLES - SUPER ADMIN STYLE
   ============================================ */
   
:root {
    --primary: #4361ee;
    --primary-dark: #3a0ca3;
    --success: #06d6a0;
    --warning: #f9c74f;
    --danger: #ef476f;
    --info: #4cc9f0;
    --gray: #6b7280;
    --light-gray: #f3f4f6;
    --border-gray: #e5e7eb;
    --text-dark: #1f2937;
    --text-muted: #6b7280;
    --header-height: 70px;
}

.erp-topbar {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    z-index: 1030;
    height: var(--header-height);
    background: #ffffff;
    border-bottom: 1px solid var(--border-gray);
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0 24px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
}

.erp-topbar-left,
.erp-topbar-right {
    display: flex;
    align-items: center;
    gap: 16px;
}

.erp-brand {
    display: flex;
    align-items: center;
    gap: 12px;
}

.erp-logo {
    width: 36px;
    height: 36px;
    border-radius: 10px;
    background: linear-gradient(135deg, var(--primary), var(--primary-dark));
    color: #fff;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
}

.erp-brand-text {
    line-height: 1.2;
}

.erp-brand-title {
    margin: 0;
    font-size: 15px;
    font-weight: 700;
    color: var(--text-dark);
}

.erp-branch {
    margin: 0;
    font-size: 10px;
    color: var(--text-muted);
}

.erp-branch-selector {
    margin-left: 8px;
}

.erp-branch-select {
    padding: 4px 28px 4px 10px;
    font-size: 10px;
    font-weight: 500;
    border-radius: 6px;
    border: 1px solid var(--border-gray);
    background: var(--light-gray);
    color: var(--text-dark);
    cursor: pointer;
    transition: all 0.2s;
}

.erp-branch-select:hover {
    background: #ffffff;
    border-color: var(--primary);
}

.button-menu-mobile {
    border: 1px solid var(--border-gray);
    background: #fff;
    color: var(--text-dark);
    border-radius: 10px;
    width: 40px;
    height: 40px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.2s ease;
    font-size: 18px;
}

.button-menu-mobile:hover {
    background: var(--light-gray);
    color: var(--primary);
    border-color: var(--primary);
}

.button-menu-mobile:active {
    transform: scale(0.96);
}

.erp-search-wrap {
    position: relative;
    width: 300px;
}

.erp-search-wrap .input-group {
    display: flex;
    align-items: center;
    background: var(--light-gray);
    border-radius: 12px;
    border: 1px solid var(--border-gray);
}

.erp-search-wrap .input-group:focus-within {
    border-color: var(--primary);
    background: white;
}

.erp-search-wrap .input-group-text {
    background: transparent;
    border: none;
    color: #94a3b8;
    padding: 8px 12px;
    font-size: 14px;
}

.erp-search-wrap .form-control {
    background: transparent;
    border: none;
    padding: 8px 12px 8px 0;
    font-size: 13px;
}

.erp-search-wrap .form-control:focus {
    outline: none;
    box-shadow: none;
}

.erp-search-results {
    position: absolute;
    top: 100%;
    left: 0;
    right: 0;
    background: white;
    border: 1px solid var(--border-gray);
    border-radius: 12px;
    box-shadow: 0 10px 25px rgba(0,0,0,0.1);
    max-height: 360px;
    overflow-y: auto;
    display: none;
    z-index: 1050;
    margin-top: 8px;
}

.erp-search-item {
    padding: 12px 16px;
    border-bottom: 1px solid var(--border-gray);
    cursor: pointer;
    transition: background 0.2s;
}

.erp-search-item:hover {
    background: var(--light-gray);
}

.erp-search-item:last-child {
    border-bottom: none;
}

.erp-quick-links {
    display: flex;
    gap: 8px;
    align-items: center;
}

.erp-quick-link {
    border: 1px solid var(--border-gray);
    background: #fff;
    color: var(--text-dark);
    border-radius: 30px;
    padding: 6px 14px;
    font-size: 12px;
    font-weight: 600;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.2s;
}

.erp-quick-link:hover {
    text-decoration: none;
    background: var(--light-gray);
    color: var(--primary);
    border-color: var(--primary);
}

.erp-quick-link .badge {
    font-size: 9px;
    padding: 2px 6px;
    border-radius: 20px;
}

.badge-warning {
    background: var(--warning);
    color: #92400e;
}

.badge-info {
    background: var(--info);
    color: #0c4e6e;
}

.badge-danger {
    background: var(--danger);
    color: white;
    animation: pulse-red 1.5s infinite;
}

.badge-critical {
    background: #dc2626;
    color: white;
    animation: pulse-red 1.5s infinite;
}

@keyframes pulse-red {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.7; }
}

.erp-notif {
    position: relative;
}

.erp-icon-btn {
    border: 1px solid var(--border-gray);
    background: #fff;
    color: var(--text-dark);
    border-radius: 10px;
    width: 40px;
    height: 40px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.2s;
    position: relative;
}

.erp-icon-btn:hover {
    background: var(--light-gray);
    color: var(--primary);
    border-color: var(--primary);
}

.erp-notif .badge {
    position: absolute;
    top: -5px;
    right: -5px;
    font-size: 9px;
    padding: 3px 5px;
    border-radius: 20px;
    background: var(--danger);
    color: white;
    min-width: 16px;
    text-align: center;
}

.erp-profile-btn {
    border: 1px solid var(--border-gray);
    background: #fff;
    border-radius: 40px;
    padding: 4px 12px 4px 6px;
    display: flex;
    align-items: center;
    gap: 10px;
    cursor: pointer;
    transition: all 0.2s;
}

.erp-profile-btn:hover {
    background: var(--light-gray);
    border-color: var(--primary);
}

.erp-avatar {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: linear-gradient(135deg, var(--primary), var(--primary-dark));
    color: white;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 14px;
    overflow: hidden;
}

.erp-avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.erp-user-meta {
    line-height: 1.2;
}

.erp-user-name {
    margin: 0;
    font-size: 13px;
    font-weight: 700;
    color: var(--text-dark);
}

.erp-user-role {
    margin: 0;
    font-size: 10px;
    color: var(--text-muted);
}

.erp-profile-btn .fa-chevron-down {
    font-size: 10px;
    color: var(--text-muted);
}

/* Dropdown Menu Styles - FIXED */
.erp-dropdown-menu {
    position: absolute;
    top: 100%;
    right: 0;
    z-index: 1050;
    min-width: 280px;
    padding: 0;
    margin: 8px 0 0;
    background: #fff;
    border: 1px solid var(--border-gray);
    border-radius: 12px;
    box-shadow: 0 10px 25px rgba(0,0,0,0.1);
    display: none;
}

.erp-dropdown-menu.show {
    display: block !important;
}

.erp-dropdown-menu .dropdown-header {
    padding: 12px 16px;
    font-weight: 700;
    font-size: 11px;
    color: var(--text-muted);
    border-bottom: 1px solid var(--border-gray);
    text-transform: uppercase;
    margin: 0;
}

.erp-dropdown-menu .dropdown-item-text {
    padding: 10px 16px;
    font-size: 13px;
    color: var(--text-dark);
    border-bottom: 1px solid var(--border-gray);
    line-height: 1.4;
    display: block;
}

.erp-dropdown-menu .dropdown-item-text:last-child {
    border-bottom: none;
}

.erp-dropdown-menu .dropdown-item {
    padding: 10px 16px;
    display: block;
    font-size: 13px;
    color: var(--text-dark);
    text-decoration: none;
    transition: background 0.2s;
}

.erp-dropdown-menu .dropdown-item:hover {
    background: var(--light-gray);
    text-decoration: none;
    color: var(--primary);
}

.erp-dropdown-menu .dropdown-divider {
    height: 1px;
    background: var(--border-gray);
    margin: 6px 0;
}

.erp-dropdown-menu .text-danger {
    color: var(--danger) !important;
}

.erp-dropdown-menu .text-danger:hover {
    background: #fef2f2 !important;
}

/* Profile Dropdown specific */
#profileMenu {
    min-width: 220px;
}

#profileMenu .dropdown-item i {
    width: 18px;
    margin-right: 8px;
    text-align: center;
}

@media (max-width: 992px) {
    .erp-brand-text { display: none; }
    .erp-quick-links { display: none; }
    .erp-search-wrap { width: 200px; }
}

@media (max-width: 768px) {
    .erp-topbar { padding: 0 16px; }
    .erp-search-wrap { display: none; }
    .erp-user-meta { display: none; }
    .erp-profile-btn { padding: 4px 8px; }
    .erp-dropdown-menu {
        min-width: 240px;
        right: -10px;
    }
}

/* Override Bootstrap dropdown toggle arrow */
.dropdown-toggle::after {
    display: none !important;
}
</style>

<div class="erp-topbar">
    <div class="erp-topbar-left">
        <button type="button" class="button-menu-mobile" id="erpSidebarToggle">
            <i class="fas fa-bars"></i>
        </button>
        <div class="erp-brand">
            <div class="erp-logo"><i class="fas <?php echo $is_global_admin ? 'fa-crown' : 'fa-hospital'; ?>"></i></div>
            <div class="erp-brand-text">
                <div class="erp-brand-title"><?php echo $is_global_admin ? 'Super Admin Console' : 'Hospital ERP'; ?></div>
                <div class="erp-branch">
                    <?php if ($is_global_admin && !empty($available_branches)): ?>
                        <select id="branchSwitcher" class="erp-branch-select" data-current-branch="<?php echo (int)$session_branch_id; ?>">
                            <option value="0">All Branches (HQ View)</option>
                            <?php foreach ($available_branches as $branch): ?>
                                <option value="<?php echo (int)$branch['id']; ?>" <?php echo ($session_branch_id === (int)$branch['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($branch['name'] . ' (' . $branch['code'] . ')', ENT_QUOTES, 'UTF-8'); ?>
                                    <?php if ($branch['is_hq']): ?>(HQ)<?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    <?php else: ?>
                        <?php echo htmlspecialchars($branch_context, ENT_QUOTES, 'UTF-8'); ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="erp-topbar-right">
        <!-- Global Patient Search -->
        <div class="erp-search-wrap">
            <div class="input-group">
                <span class="input-group-text"><i class="fas fa-search"></i></span>
                <input type="text" id="erpGlobalSearch" class="form-control" placeholder="Search patient by name, MRN, phone...">
            </div>
            <div class="erp-search-results" id="erpSearchResults"></div>
        </div>

        <!-- Quick Links (Permission-based) -->
        <?php if ($show_reminders_link || $show_queue_link || $show_emergency_link || $is_global_admin): ?>
        <div class="erp-quick-links">
            <?php if ($is_global_admin): ?>
            <a href="audit_logs_viewer.php" class="erp-quick-link">
                <i class="fas fa-shield-alt"></i> Audit Trail 
                <?php if ($recent_audits_count > 0): ?>
                    <span class="badge badge-info"><?php echo (int)$recent_audits_count; ?></span>
                <?php endif; ?>
            </a>
            <a href="bed_capacity.php" class="erp-quick-link">
                <i class="fas fa-bed"></i> Inpatients 
                <?php if ($live_inpatients > 0): ?>
                    <span class="badge badge-warning"><?php echo (int)$live_inpatients; ?></span>
                <?php endif; ?>
            </a>
            <a href="lab_critical_alerts.php" class="erp-quick-link">
                <i class="fas fa-flask"></i> Critical Labs 
                <?php if ($critical_alerts_count > 0): ?>
                    <span class="badge badge-danger"><?php echo (int)$critical_alerts_count; ?></span>
                <?php endif; ?>
            </a>
            <?php endif; ?>
            
            <?php if ($show_reminders_link): ?>
            <a href="notifications_manager.php" class="erp-quick-link">
                <i class="fas fa-bell"></i> Reminders
                <?php if ($reminder_count > 0): ?>
                    <span class="badge badge-warning"><?php echo (int)$reminder_count; ?></span>
                <?php endif; ?>
            </a>
            <?php endif; ?>
            
            <?php if ($show_queue_link): ?>
            <a href="live_queue.php" class="erp-quick-link">
                <i class="fas fa-users"></i> Queue
                <?php if ($queue_count > 0): ?>
                    <span class="badge badge-info"><?php echo (int)$queue_count; ?></span>
                <?php endif; ?>
            </a>
            <?php endif; ?>
            
            <?php if ($show_emergency_link): ?>
            <a href="emergency_alerts.php" class="erp-quick-link">
                <i class="fas fa-ambulance"></i> Emergency
                <?php if ($emergency_count > 0): ?>
                    <span class="badge badge-danger"><?php echo (int)$emergency_count; ?></span>
                <?php endif; ?>
            </a>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Notifications Dropdown - FIXED -->
        <?php if ($show_notifications || $is_global_admin): ?>
        <div class="erp-notif">
            <button class="erp-icon-btn" id="notificationBtn" type="button">
                <i class="fas fa-bell"></i>
                <?php if ($notification_count > 0): ?>
                    <span class="badge"><?php echo (int)$notification_count; ?></span>
                <?php endif; ?>
            </button>
            <div class="erp-dropdown-menu" id="notificationMenu">
                <div class="dropdown-header"><?php echo $is_global_admin ? 'Live Security Pulse' : 'Notifications'; ?></div>
                <?php foreach ($notifications as $item): ?>
                    <div class="dropdown-item-text"><?php echo htmlspecialchars($item, ENT_QUOTES, 'UTF-8'); ?></div>
                <?php endforeach; ?>
                <div class="dropdown-divider"></div>
                <a class="dropdown-item" href="<?php echo $is_global_admin ? 'audit_logs_viewer.php' : 'notifications_manager.php'; ?>">
                    View All →
                </a>
            </div>
        </div>
        <?php endif; ?>

        <!-- User Profile Dropdown - FIXED -->
        <div class="erp-notif">
            <button class="erp-profile-btn" id="profileBtn" type="button">
                <span class="erp-avatar">
                    <?php if (!empty($nav_user['photo_path']) && file_exists($nav_user['photo_path'])): ?>
                        <img src="<?php echo htmlspecialchars($nav_user['photo_path'], ENT_QUOTES, 'UTF-8'); ?>" alt="Profile">
                    <?php else: ?>
                        <?php
                        $initial = mb_strtoupper(mb_substr($nav_user['name'], 0, 1, 'UTF-8'), 'UTF-8');
                        echo htmlspecialchars($initial, ENT_QUOTES, 'UTF-8');
                        ?>
                    <?php endif; ?>
                </span>
                <span class="erp-user-meta">
                    <div class="erp-user-name"><?php echo htmlspecialchars($nav_user['name'], ENT_QUOTES, 'UTF-8'); ?></div>
                    <div class="erp-user-role"><?php echo htmlspecialchars($nav_user['role'], ENT_QUOTES, 'UTF-8'); ?></div>
                </span>
                <i class="fas fa-chevron-down"></i>
            </button>
            <div class="erp-dropdown-menu" id="profileMenu">
                <a class="dropdown-item" href="profile_settings.php"><i class="fas fa-user-cog"></i> Profile Settings</a>
                <?php if ($is_global_admin): ?>
                <a class="dropdown-item" href="user_permission_rules.php"><i class="fas fa-shield-halved"></i> RBAC Manager</a>
                <?php endif; ?>
                <?php if (nav_has_permission($pdo, $active_user_id, 'view_activity_log') || $is_global_admin): ?>
                <a class="dropdown-item" href="activity_log.php"><i class="fas fa-history"></i> Activity Log</a>
                <?php endif; ?>
                <div class="dropdown-divider"></div>
                <a class="dropdown-item text-danger" href="assets/inc/logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
(function() {
    'use strict';
    
    document.addEventListener('DOMContentLoaded', function() {
        // ============================================
        // NOTIFICATION DROPDOWN - FIXED
        // ============================================
        var notificationBtn = document.getElementById('notificationBtn');
        var notificationMenu = document.getElementById('notificationMenu');
        
        if (notificationBtn && notificationMenu) {
            // Toggle notification dropdown
            notificationBtn.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                
                // Close profile dropdown if open
                var profileMenu = document.getElementById('profileMenu');
                if (profileMenu) {
                    profileMenu.classList.remove('show');
                }
                
                notificationMenu.classList.toggle('show');
            });
        }

        // ============================================
        // PROFILE DROPDOWN - FIXED
        // ============================================
        var profileBtn = document.getElementById('profileBtn');
        var profileMenu = document.getElementById('profileMenu');
        
        if (profileBtn && profileMenu) {
            // Toggle profile dropdown
            profileBtn.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                
                // Close notification dropdown if open
                var notificationMenu = document.getElementById('notificationMenu');
                if (notificationMenu) {
                    notificationMenu.classList.remove('show');
                }
                
                profileMenu.classList.toggle('show');
            });
        }

        // ============================================
        // CLOSE DROPDOWNS ON OUTSIDE CLICK
        // ============================================
        document.addEventListener('click', function(e) {
            // Close notification dropdown
            var notificationBtn = document.getElementById('notificationBtn');
            var notificationMenu = document.getElementById('notificationMenu');
            if (notificationMenu && notificationBtn) {
                if (!notificationBtn.contains(e.target) && !notificationMenu.contains(e.target)) {
                    notificationMenu.classList.remove('show');
                }
            }
            
            // Close profile dropdown
            var profileBtn = document.getElementById('profileBtn');
            var profileMenu = document.getElementById('profileMenu');
            if (profileMenu && profileBtn) {
                if (!profileBtn.contains(e.target) && !profileMenu.contains(e.target)) {
                    profileMenu.classList.remove('show');
                }
            }
        });

        // ============================================
        // SIDEBAR TOGGLE - FIXED
        // ============================================
        var toggleBtn = document.getElementById('erpSidebarToggle');
        if (toggleBtn) {
            toggleBtn.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                
                if (window.innerWidth <= 768) {
                    document.body.classList.toggle('sidebar-enable');
                    if (document.body.classList.contains('sidebar-enable')) {
                        document.body.style.overflow = 'hidden';
                    } else {
                        document.body.style.overflow = '';
                    }
                } else {
                    document.body.classList.toggle('erp-sidebar-collapsed');
                    
                    if (document.body.classList.contains('erp-sidebar-collapsed')) {
                        localStorage.setItem('sidebarCollapsed', 'true');
                    } else {
                        localStorage.setItem('sidebarCollapsed', 'false');
                    }
                }
            });
            
            if (window.innerWidth > 768) {
                const savedState = localStorage.getItem('sidebarCollapsed');
                if (savedState === 'true') {
                    document.body.classList.add('erp-sidebar-collapsed');
                }
            }
        }

        // Close mobile sidebar when clicking outside
        document.addEventListener('click', function(e) {
            if (window.innerWidth <= 768) {
                var sidebar = document.querySelector('.left-side-menu');
                var toggle = document.getElementById('erpSidebarToggle');
                if (sidebar && toggle && !sidebar.contains(e.target) && !toggle.contains(e.target)) {
                    document.body.classList.remove('sidebar-enable');
                    document.body.style.overflow = '';
                }
            }
        });

        window.addEventListener('resize', function() {
            if (window.innerWidth > 768) {
                document.body.classList.remove('sidebar-enable');
                document.body.style.overflow = '';
            }
        });
        
        // ============================================
        // BRANCH SWITCHER
        // ============================================
        var branchSwitcher = document.getElementById('branchSwitcher');
        if (branchSwitcher) {
            branchSwitcher.addEventListener('change', function() {
                var branchId = this.value;
                var currentUrl = new URL(window.location.href);
                currentUrl.searchParams.set('branch_id', branchId);
                window.location.href = currentUrl.toString();
            });
        }

        // ============================================
        // PATIENT SEARCH
        // ============================================
        var searchInput = document.getElementById('erpGlobalSearch');
        var searchResults = document.getElementById('erpSearchResults');
        var searchTimer = null;

        function escapeHtml(str) {
            if (!str) return '';
            return str.replace(/[&<>]/g, function(m) {
                if (m === '&') return '&amp;';
                if (m === '<') return '&lt;';
                if (m === '>') return '&gt;';
                return m;
            });
        }

        if (searchInput && searchResults) {
            searchInput.addEventListener('input', function() {
                var query = searchInput.value.trim();
                clearTimeout(searchTimer);
                
                if (query.length < 2) {
                    searchResults.style.display = 'none';
                    return;
                }
                
                searchTimer = setTimeout(function() {
                    fetch('assets/inc/nav_patient_search.php?q=' + encodeURIComponent(query))
                        .then(function(res) { return res.json(); })
                        .then(function(data) {
                            var items = (data && data.success && Array.isArray(data.items)) ? data.items : [];
                            
                            if (items.length === 0) {
                                searchResults.innerHTML = '<div class="erp-search-item text-muted">No patient found.</div>';
                                searchResults.style.display = 'block';
                                return;
                            }
                            
                            var html = '';
                            for (var i = 0; i < items.length; i++) {
                                var p = items[i];
                                html += '<div class="erp-search-item" data-id="' + p.id + '">' +
                                    '<div><strong>' + escapeHtml(p.name) + '</strong></div>' +
                                    '<small class="text-muted">MRN: ' + escapeHtml(p.mrn) + ' | Phone: ' + escapeHtml(p.phone || 'N/A') + '</small>' +
                                    '</div>';
                            }
                            searchResults.innerHTML = html;
                            searchResults.style.display = 'block';
                        })
                        ['catch'](function() {
                            searchResults.innerHTML = '<div class="erp-search-item text-danger">Search failed.</div>';
                            searchResults.style.display = 'block';
                        });
                }, 300);
            });

            searchResults.addEventListener('click', function(e) {
                var row = e.target.closest('.erp-search-item[data-id]');
                if (row) {
                    var id = row.getAttribute('data-id');
                    window.location.href = 'patient_history.php?patient_id=' + encodeURIComponent(id);
                }
            });

            document.addEventListener('click', function(e) {
                if (searchResults && !searchResults.contains(e.target) && e.target !== searchInput) {
                    searchResults.style.display = 'none';
                }
            });
        }
    });
})();
</script>