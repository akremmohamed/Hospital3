<?php
/**
 * insurance_claims.php
 * 
 * Insurance & Corporate Credit Management
 * 
 * Manages credit-based healthcare consumption, automates split between
 * insurance/corporate coverage and patient co-pay, and batches claims.
 * Works with actual schema: invoices, invoice_items, patients, patient_visits.
 * Enhanced with: Multi-branch support, claim history, provider management,
 * pre-authorization tracking, and advanced filtering.
 * 
 * @package HMS_ERP
 */

session_start();

// ----------------------------------------------------------------------
// 1. BOOTSTRAP & SECURITY
// ----------------------------------------------------------------------
require_once __DIR__ . '/../../config/db_connection.php';
require_once __DIR__ . '/../../includes/auth_helper.php';

date_default_timezone_set('Africa/Addis_Ababa');
if ($pdo) {
    $pdo->exec("SET time_zone = '+03:00'");
}

$user_id = (int)($_SESSION['user_id'] ?? 0);
$branch_id = (int)($_SESSION['branch_id'] ?? 0);
$user_type = $_SESSION['user_type'] ?? '';
$is_global_admin = ($user_type === 'super_admin' || $user_type === 'admin');

if ($user_id <= 0) {
    header('Location: ../../index.php');
    exit;
}

$has_permission = $is_global_admin || user_has_permission($pdo, 'manage_insurance_claims', $user_id);
if (!$has_permission) {
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

function formatMoney($amount) {
    return number_format((float)$amount, 2);
}

function applyBranchFilter(&$sql, &$params, $branch_id, $is_global_admin, $table_alias) {
    if (!$is_global_admin && $branch_id > 0) {
        $sql .= " AND {$table_alias}.branch_id = :branch_id";
        $params[':branch_id'] = $branch_id;
    }
}

// ----------------------------------------------------------------------
// 2. AJAX HANDLERS
// ----------------------------------------------------------------------

// Refresh endpoint for left panel (returns HTML list of sponsors)
if (isset($_GET['ajax_refresh_sponsors']) && $_GET['ajax_refresh_sponsors'] == 1 && isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    ob_clean();
    
    $filter = $_GET['filter'] ?? 'all';
    $search = trim($_GET['search'] ?? '');
    
    $sql = "SELECT 
                ip.id AS provider_id,
                ip.name AS provider_name,
                ip.code AS company_code,
                ip.contract_start_date,
                ip.contract_end_date,
                ip.default_coverage_percentage,
                ip.default_co_pay_percentage,
                ip.credit_limit,
                ip.current_balance,
                COUNT(DISTINCT i.id) AS outstanding_count,
                COALESCE(SUM(ii.total_price), 0) AS total_outstanding_value,
                COUNT(DISTINCT pip.patient_id) AS active_members
            FROM insurance_providers ip
            LEFT JOIN patient_insurance_profiles pip ON pip.insurance_provider_id = ip.id 
                AND pip.status = 'active' 
                AND pip.deleted_at IS NULL
            LEFT JOIN patients p ON p.id = pip.patient_id AND p.deleted_at IS NULL
            LEFT JOIN invoices i ON i.patient_id = p.id 
                AND i.payment_status IN ('unpaid', 'partial') 
                AND i.billing_type = 'insurance' 
                AND i.deleted_at IS NULL
            LEFT JOIN invoice_items ii ON ii.invoice_id = i.id
            WHERE ip.status = 'active' AND ip.deleted_at IS NULL";
    
    $params = [];
    
    if (!$is_global_admin && $branch_id > 0) {
        $sql .= " AND ip.branch_id = :branch_id";
        $params[':branch_id'] = $branch_id;
    }
    
    if ($filter === 'credit_limit_exceeded') {
        $sql .= " AND ip.current_balance >= ip.credit_limit";
    } elseif ($filter === 'contract_expiring') {
        $sql .= " AND ip.contract_end_date <= DATE_ADD(CURDATE(), INTERVAL 90 DAY)";
    }
    
    if (!empty($search)) {
        $sql .= " AND (ip.name LIKE :search OR ip.code LIKE :search)";
        $params[':search'] = '%' . $search . '%';
    }
    
    $sql .= " GROUP BY ip.id
              HAVING outstanding_count > 0
              ORDER BY total_outstanding_value DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $sponsors = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($sponsors) === 0) {
        echo '<div class="empty-state"><i class="fas fa-check-circle fa-2x mb-2 text-success"></i><p>No active corporate partners with pending claims.</p></div>';
    } else {
        foreach ($sponsors as $sp) {
            $credit_used_pct = $sp['credit_limit'] > 0 ? round(($sp['current_balance'] / $sp['credit_limit']) * 100) : 0;
            $credit_class = $credit_used_pct >= 90 ? 'text-danger' : ($credit_used_pct >= 70 ? 'text-warning' : '');
            ?>
            <div class="sponsor-item" data-provider-id="<?php echo $sp['provider_id']; ?>" onclick="selectSponsor(<?php echo $sp['provider_id']; ?>, this)">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="fw-bold"><?php echo escapeHtml($sp['provider_name']); ?></div>
                        <div class="small text-muted">Code: <?php echo escapeHtml($sp['company_code']); ?></div>
                        <div class="small text-muted"><i class="fas fa-users"></i> <?php echo $sp['active_members']; ?> active members</div>
                        <div class="small mt-1">
                            <span class="badge <?php echo $credit_class; ?>">
                                Credit: <?php echo formatMoney($sp['current_balance'] ?? 0); ?> / <?php echo formatMoney($sp['credit_limit'] ?? 0); ?> ETB
                            </span>
                        </div>
                    </div>
                    <div class="text-end">
                        <span class="badge-outstanding"><?php echo $sp['outstanding_count']; ?> invoices</span>
                        <div class="small mt-1"><?php echo number_format($sp['total_outstanding_value'], 2); ?> ETB</div>
                        <?php if ($sp['contract_end_date']): ?>
                            <div class="small text-muted">Contract: <?php echo date('d/m/Y', strtotime($sp['contract_end_date'])); ?></div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php
        }
    }
    exit;
}

// Main AJAX actions
if (isset($_GET['ajax']) && $_GET['ajax'] === 'claims_action') {
    
    // Fetch all pending invoice items for a given insurance provider
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'fetch_provider_items') {
        $provider_id = (int)($_GET['provider_id'] ?? 0);
        if ($provider_id <= 0) {
            jsonResponse(false, 'Invalid provider ID');
        }
        
        $prov_sql = "SELECT id, name, code, default_coverage_percentage, default_co_pay_percentage,
                            credit_limit, current_balance, requires_pre_authorization,
                            pre_authorization_lead_days, contract_end_date
                     FROM insurance_providers 
                     WHERE id = :id AND status = 'active' AND deleted_at IS NULL";
        $prov_stmt = $pdo->prepare($prov_sql);
        $prov_stmt->execute([':id' => $provider_id]);
        $provider = $prov_stmt->fetch(PDO::FETCH_ASSOC);
        if (!$provider) {
            jsonResponse(false, 'Provider not found or inactive');
        }
        
        // Get all patients with active policy for this provider
        $policy_sql = "SELECT pip.patient_id, pip.coverage_percentage, pip.co_pay_percentage, 
                              pip.policy_number, pip.member_id, pip.group_number,
                              pip.effective_from, pip.effective_to,
                              pip.deductible_remaining, pip.out_of_pocket_year_to_date,
                              pip.max_coverage_per_year, pip.max_coverage_per_admission,
                              p.first_name, p.last_name, p.middle_name, p.mr_number
                       FROM patient_insurance_profiles pip
                       JOIN patients p ON pip.patient_id = p.id
                       WHERE pip.insurance_provider_id = :provider_id 
                         AND pip.status = 'active' 
                         AND pip.deleted_at IS NULL";
        $policy_stmt = $pdo->prepare($policy_sql);
        $policy_stmt->execute([':provider_id' => $provider_id]);
        $policies = $policy_stmt->fetchAll(PDO::FETCH_ASSOC);
        if (empty($policies)) {
            jsonResponse(false, 'No active insurance policies found for this provider.');
        }
        
        $policy_map = [];
        foreach ($policies as $p) {
            $policy_map[$p['patient_id']] = $p;
        }
        
        $patient_ids = array_keys($policy_map);
        $placeholders = implode(',', array_fill(0, count($patient_ids), '?'));
        
        // Get invoice_items from unpaid/partial insurance invoices for these patients
        $items_sql = "SELECT 
                        ii.id AS item_id,
                        ii.invoice_id,
                        ii.service_name AS item_name,
                        ii.service_category,
                        ii.quantity,
                        ii.unit_price,
                        ii.total_price AS total_amount,
                        ii.discount_amount,
                        ii.tax_amount,
                        i.id AS invoice_id,
                        i.invoice_number,
                        i.invoice_date,
                        i.patient_id,
                        i.visit_id,
                        pv.visit_number,
                        CONCAT(pat.first_name, ' ', IFNULL(pat.middle_name, ''), ' ', pat.last_name) AS patient_name,
                        pat.mr_number,
                        pat.date_of_birth,
                        pat.gender,
                        pat.phone,
                        b.name AS branch_name
                      FROM invoice_items ii
                      JOIN invoices i ON ii.invoice_id = i.id
                      JOIN patients pat ON i.patient_id = pat.id AND pat.deleted_at IS NULL
                      LEFT JOIN patient_visits pv ON i.visit_id = pv.id AND pv.deleted_at IS NULL
                      JOIN branches b ON i.branch_id = b.id
                      WHERE i.patient_id IN ($placeholders)
                        AND i.payment_status IN ('unpaid', 'partial')
                        AND i.billing_type = 'insurance'
                        AND i.deleted_at IS NULL
                      ORDER BY i.invoice_date ASC";
        $params = array_merge($patient_ids);
        if (!$is_global_admin && $branch_id > 0) {
            $items_sql .= " AND i.branch_id = ?";
            $params[] = $branch_id;
        }
        $items_stmt = $pdo->prepare($items_sql);
        $items_stmt->execute($params);
        $items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($items)) {
            jsonResponse(false, 'No pending billable items for this provider.');
        }
        
        $result_items = [];
        $totals = ['gross' => 0, 'insurance' => 0, 'copay' => 0, 'discount' => 0];
        
        foreach ($items as $item) {
            $patient_id = $item['patient_id'];
            $coverage_pct = (float)($policy_map[$patient_id]['coverage_percentage'] ?? $provider['default_coverage_percentage']);
            $copay_pct = (float)($policy_map[$patient_id]['co_pay_percentage'] ?? $provider['default_co_pay_percentage']);
            $gross = (float)$item['total_amount'];
            $discount = (float)($item['discount_amount'] ?? 0);
            $net = $gross - $discount;
            $ins_share = round($net * ($coverage_pct / 100), 2);
            $copay_share = round($net * ($copay_pct / 100), 2);
            
            $totals['gross'] += $gross;
            $totals['insurance'] += $ins_share;
            $totals['copay'] += $copay_share;
            $totals['discount'] += $discount;
            
            $result_items[] = [
                'item_id' => $item['item_id'],
                'invoice_id' => $item['invoice_id'],
                'invoice_number' => $item['invoice_number'],
                'invoice_date' => $item['invoice_date'],
                'visit_number' => $item['visit_number'] ?? 'N/A',
                'patient_name' => $item['patient_name'],
                'patient_id' => $patient_id,
                'mr_number' => $item['mr_number'],
                'gender' => $item['gender'],
                'phone' => $item['phone'],
                'branch_name' => $item['branch_name'],
                'item_name' => $item['item_name'],
                'service_category' => $item['service_category'],
                'quantity' => $item['quantity'],
                'unit_price' => $item['unit_price'],
                'gross_amount' => $gross,
                'discount_amount' => $discount,
                'net_amount' => $net,
                'insurance_share' => $ins_share,
                'copay_share' => $copay_share,
                'coverage_percent' => $coverage_pct,
                'copay_percent' => $copay_pct,
                'policy_number' => $policy_map[$patient_id]['policy_number'],
                'member_id' => $policy_map[$patient_id]['member_id'] ?? 'N/A',
                'deductible_remaining' => $policy_map[$patient_id]['deductible_remaining'] ?? 0,
                'out_of_pocket_ytd' => $policy_map[$patient_id]['out_of_pocket_year_to_date'] ?? 0,
                'max_coverage' => $policy_map[$patient_id]['max_coverage_per_year'] ?? 0
            ];
        }
        
        jsonResponse(true, '', [
            'provider' => $provider,
            'items' => $result_items,
            'totals' => $totals,
            'policy_info' => [
                'coverage_percent' => $provider['default_coverage_percentage'],
                'copay_percent' => $provider['default_co_pay_percentage'],
                'credit_limit' => $provider['credit_limit'],
                'current_balance' => $provider['current_balance'],
                'requires_pre_auth' => $provider['requires_pre_authorization']
            ]
        ]);
    }
    
    // Generate batch claim for selected items
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'generate_batch_claim') {
        $incoming_token = $_POST['csrf_token'] ?? '';
        if (!hash_equals($csrf_token, $incoming_token)) {
            jsonResponse(false, 'Security token mismatch');
        }
        
        $provider_id = (int)($_POST['provider_id'] ?? 0);
        $billing_item_ids = $_POST['billing_item_ids'] ?? [];
        $pre_auth_code = trim($_POST['pre_auth_code'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        
        if ($provider_id <= 0 || empty($billing_item_ids) || !is_array($billing_item_ids)) {
            jsonResponse(false, 'Invalid provider or no items selected.');
        }
        
        $prov_sql = "SELECT id, name, requires_pre_authorization, pre_authorization_lead_days 
                     FROM insurance_providers 
                     WHERE id = :id AND status = 'active' AND deleted_at IS NULL";
        $prov_stmt = $pdo->prepare($prov_sql);
        $prov_stmt->execute([':id' => $provider_id]);
        $provider = $prov_stmt->fetch(PDO::FETCH_ASSOC);
        if (!$provider) {
            jsonResponse(false, 'Insurance provider not found or inactive.');
        }
        
        // Check if pre-authorization is required
        if ($provider['requires_pre_authorization'] && empty($pre_auth_code)) {
            jsonResponse(false, 'Pre-authorization code is required for this provider.');
        }
        
        $placeholders = implode(',', array_fill(0, count($billing_item_ids), '?'));
        $item_sql = "SELECT ii.id, ii.total_price, ii.discount_amount, 
                            i.id AS invoice_id, i.payment_status, i.patient_id
                     FROM invoice_items ii
                     JOIN invoices i ON ii.invoice_id = i.id
                     WHERE ii.id IN ($placeholders)";
        $params = $billing_item_ids;
        if (!$is_global_admin && $branch_id > 0) {
            $item_sql .= " AND i.branch_id = ?";
            $params[] = $branch_id;
        }
        $item_sql .= " AND i.payment_status IN ('unpaid', 'partial') AND i.billing_type = 'insurance' AND i.deleted_at IS NULL";
        $item_stmt = $pdo->prepare($item_sql);
        $item_stmt->execute($params);
        $items = $item_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (count($items) !== count($billing_item_ids)) {
            jsonResponse(false, 'One or more selected items are invalid or already claimed.');
        }
        
        // Calculate totals
        $total_gross = array_sum(array_column($items, 'total_price'));
        $total_discount = array_sum(array_column($items, 'discount_amount'));
        $total_claimed = $total_gross - $total_discount;
        
        // Get policy info for co-pay calculation
        $patient_id = $items[0]['patient_id'] ?? 0;
        $policy_sql = "SELECT coverage_percentage, co_pay_percentage 
                       FROM patient_insurance_profiles 
                       WHERE patient_id = :patient_id 
                         AND insurance_provider_id = :provider_id 
                         AND status = 'active' 
                         AND deleted_at IS NULL 
                       LIMIT 1";
        $policy_stmt = $pdo->prepare($policy_sql);
        $policy_stmt->execute([':patient_id' => $patient_id, ':provider_id' => $provider_id]);
        $policy = $policy_stmt->fetch(PDO::FETCH_ASSOC);
        
        $coverage_pct = $policy['coverage_percentage'] ?? 80;
        $copay_pct = $policy['co_pay_percentage'] ?? 20;
        $insurance_covered = round($total_claimed * ($coverage_pct / 100), 2);
        $patient_copay = round($total_claimed * ($copay_pct / 100), 2);
        
        // Generate unique batch number
        $batch_number = 'CLM-BCH-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
        $unique = false;
        for ($i = 0; $i < 5; $i++) {
            $check = $pdo->prepare("SELECT id FROM claims_log WHERE claim_batch_number = ?");
            $check->execute([$batch_number]);
            if (!$check->fetch()) {
                $unique = true;
                break;
            }
            $batch_number = 'CLM-BCH-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
        }
        if (!$unique) {
            jsonResponse(false, 'Unable to generate unique batch number.');
        }
        
        try {
            $pdo->beginTransaction();
            
            // Insert into claims_log
            $log_sql = "INSERT INTO claims_log 
                        (branch_id, provider_id, claim_batch_number, total_claimed_amount, 
                         generated_by_user_id, status, created_at)
                        VALUES 
                        (:branch_id, :provider_id, :batch_number, :total_claimed, 
                         :user_id, 'compiled', NOW())";
            $log_stmt = $pdo->prepare($log_sql);
            $log_stmt->execute([
                ':branch_id' => $branch_id,
                ':provider_id' => $provider_id,
                ':batch_number' => $batch_number,
                ':total_claimed' => $total_claimed,
                ':user_id' => $user_id
            ]);
            $claim_id = $pdo->lastInsertId();
            
            // Insert into claims_log_items
            $item_log_sql = "INSERT INTO claims_log_items (claim_id, invoice_item_id, claimed_amount) 
                             VALUES (?, ?, ?)";
            $item_log_stmt = $pdo->prepare($item_log_sql);
            foreach ($items as $item) {
                $claimed = (float)$item['total_price'] - (float)($item['discount_amount'] ?? 0);
                $item_log_stmt->execute([$claim_id, $item['id'], $claimed]);
            }
            
            // Update invoice claims table
            $claim_insert_sql = "INSERT INTO invoice_claims 
                                (invoice_id, insurance_provider_id, patient_insurance_profile_id, 
                                 claim_number, gross_amount, discount_amount, net_amount,
                                 insurance_coverage_percentage, insurance_covered_amount,
                                 patient_co_pay_percentage, patient_co_pay_amount,
                                 claimed_amount, claim_status, 
                                 pre_auth_code, created_by_user_id, created_at, updated_at)
                                VALUES 
                                (:invoice_id, :provider_id, :profile_id, 
                                 :claim_number, :gross_amount, :discount_amount, :net_amount,
                                 :coverage_pct, :insurance_covered,
                                 :copay_pct, :patient_copay,
                                 :claimed_amount, 'submitted',
                                 :pre_auth_code, :user_id, NOW(), NOW())";
            
            // Get patient insurance profile ID
            $profile_sql = "SELECT id FROM patient_insurance_profiles 
                           WHERE patient_id = :patient_id AND insurance_provider_id = :provider_id 
                           AND status = 'active' AND deleted_at IS NULL LIMIT 1";
            $profile_stmt = $pdo->prepare($profile_sql);
            $profile_stmt->execute([':patient_id' => $patient_id, ':provider_id' => $provider_id]);
            $profile = $profile_stmt->fetch(PDO::FETCH_ASSOC);
            
            // Update for first invoice (simplified - in production, would handle per invoice)
            if ($profile) {
                $claim_stmt = $pdo->prepare($claim_insert_sql);
                $claim_stmt->execute([
                    ':invoice_id' => $items[0]['invoice_id'],
                    ':provider_id' => $provider_id,
                    ':profile_id' => $profile['id'],
                    ':claim_number' => 'CLM-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT),
                    ':gross_amount' => $total_gross,
                    ':discount_amount' => $total_discount,
                    ':net_amount' => $total_claimed,
                    ':coverage_pct' => $coverage_pct,
                    ':insurance_covered' => $insurance_covered,
                    ':copay_pct' => $copay_pct,
                    ':patient_copay' => $patient_copay,
                    ':claimed_amount' => $insurance_covered,
                    ':pre_auth_code' => $pre_auth_code,
                    ':user_id' => $user_id
                ]);
            }
            
            // Mark the invoices as 'insurance_pending'
            $invoice_ids = array_unique(array_column($items, 'invoice_id'));
            $update_inv_sql = "UPDATE invoices 
                               SET payment_status = 'insurance_pending', 
                                   insurance_claim_reference = :batch_number,
                                   updated_at = NOW() 
                               WHERE id = ?";
            $update_inv_stmt = $pdo->prepare($update_inv_sql);
            foreach ($invoice_ids as $inv_id) {
                $update_inv_stmt->execute([':batch_number' => $batch_number, $inv_id]);
            }
            
            // Log the batch creation
            $log_sql = "INSERT INTO status_transition_logs 
                        (visit_id, entity_type, entity_id, old_status, new_status, changed_by_user_id, changed_at, created_at)
                        VALUES (0, 'batch_claim', :claim_id, 'compiled', 'submitted', :user_id, NOW(), NOW())";
            $log_stmt = $pdo->prepare($log_sql);
            $log_stmt->execute([
                ':claim_id' => $claim_id,
                ':user_id' => $user_id
            ]);
            
            $pdo->commit();
            jsonResponse(true, "Batch claim {$batch_number} created successfully.", [
                'batch_number' => $batch_number,
                'total_claimed' => $total_claimed,
                'total_gross' => $total_gross,
                'insurance_covered' => $insurance_covered,
                'patient_copay' => $patient_copay,
                'claim_id' => $claim_id
            ]);
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Batch claim error: ' . $e->getMessage());
            jsonResponse(false, 'Database error: ' . $e->getMessage());
        }
    }
    
    // Fetch claim history
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'fetch_claim_history') {
        try {
            $sql = "SELECT cl.id, cl.claim_batch_number, cl.total_claimed_amount, 
                           cl.status, cl.created_at,
                           ip.name AS provider_name,
                           CONCAT(u.first_name, ' ', u.last_name) AS generated_by,
                           COUNT(cli.id) AS item_count
                    FROM claims_log cl
                    JOIN insurance_providers ip ON cl.provider_id = ip.id
                    JOIN users u ON cl.generated_by_user_id = u.id
                    LEFT JOIN claims_log_items cli ON cl.id = cli.claim_id
                    WHERE cl.deleted_at IS NULL";
            $params = [];
            if (!$is_global_admin && $branch_id > 0) {
                $sql .= " AND cl.branch_id = :branch_id";
                $params[':branch_id'] = $branch_id;
            }
            $sql .= " GROUP BY cl.id ORDER BY cl.created_at DESC LIMIT 50";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
            jsonResponse(true, '', ['history' => $history]);
        } catch (PDOException $e) {
            error_log('fetch_claim_history error: ' . $e->getMessage());
            jsonResponse(false, 'Database error: ' . $e->getMessage());
        }
    }
    
    // Update claim status
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_claim_status') {
        $incoming_token = $_POST['csrf_token'] ?? '';
        if (!hash_equals($csrf_token, $incoming_token)) {
            jsonResponse(false, 'Security token mismatch');
        }
        
        $claim_id = (int)($_POST['claim_id'] ?? 0);
        $new_status = $_POST['status'] ?? '';
        $notes = trim($_POST['notes'] ?? '');
        
        if ($claim_id <= 0 || !in_array($new_status, ['compiled', 'submitted', 'settled', 'disputed'])) {
            jsonResponse(false, 'Invalid parameters');
        }
        
        try {
            $pdo->beginTransaction();
            
            $update_sql = "UPDATE claims_log SET status = :status, updated_at = NOW() WHERE id = :id";
            $update_stmt = $pdo->prepare($update_sql);
            $update_stmt->execute([':status' => $new_status, ':id' => $claim_id]);
            
            // If settled, update related invoice claims
            if ($new_status === 'settled') {
                $update_claims = "UPDATE invoice_claims 
                                  SET claim_status = 'settled', 
                                      settlement_date = CURDATE(), 
                                      updated_at = NOW() 
                                  WHERE claim_number IN (
                                      SELECT claim_batch_number FROM claims_log WHERE id = :claim_id
                                  )";
                $claim_update = $pdo->prepare($update_claims);
                $claim_update->execute([':claim_id' => $claim_id]);
            }
            
            $pdo->commit();
            jsonResponse(true, 'Claim status updated successfully.');
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('update_claim_status error: ' . $e->getMessage());
            jsonResponse(false, 'Database error: ' . $e->getMessage());
        }
    }
    
    // Fetch insurance providers list for dropdown
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'fetch_providers') {
        try {
            $sql = "SELECT id, name, code, default_coverage_percentage, credit_limit, current_balance 
                    FROM insurance_providers 
                    WHERE status = 'active' AND deleted_at IS NULL
                    ORDER BY name";
            $params = [];
            if (!$is_global_admin && $branch_id > 0) {
                $sql .= " AND branch_id = :branch_id";
                $params[':branch_id'] = $branch_id;
            }
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $providers = $stmt->fetchAll(PDO::FETCH_ASSOC);
            jsonResponse(true, '', ['providers' => $providers]);
        } catch (PDOException $e) {
            error_log('fetch_providers error: ' . $e->getMessage());
            jsonResponse(false, 'Database error: ' . $e->getMessage());
        }
    }
    
    exit;
}

// ----------------------------------------------------------------------
// 3. INITIAL PAGE DATA: Corporate Sponsors List with outstanding claims
// ----------------------------------------------------------------------
$current_date = date('l, F j, Y');
$current_time = date('g:i A');
$sponsors = [];
$claim_stats = ['total_claims' => 0, 'total_amount' => 0, 'pending_count' => 0, 'settled_count' => 0];

try {
    // Get claim statistics
    $stats_sql = "SELECT 
                    COUNT(*) AS total_claims,
                    COALESCE(SUM(total_claimed_amount), 0) AS total_amount,
                    SUM(CASE WHEN status = 'compiled' OR status = 'submitted' THEN 1 ELSE 0 END) AS pending_count,
                    SUM(CASE WHEN status = 'settled' THEN 1 ELSE 0 END) AS settled_count
                  FROM claims_log
                  WHERE deleted_at IS NULL";
    $params = [];
    if (!$is_global_admin && $branch_id > 0) {
        $stats_sql .= " AND branch_id = :branch_id";
        $params[':branch_id'] = $branch_id;
    }
    $stats_stmt = $pdo->prepare($stats_sql);
    $stats_stmt->execute($params);
    $claim_stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);
    if (!$claim_stats) {
        $claim_stats = ['total_claims' => 0, 'total_amount' => 0, 'pending_count' => 0, 'settled_count' => 0];
    }
    
    // Get sponsors list
    $sql = "SELECT 
                ip.id AS provider_id,
                ip.name AS provider_name,
                ip.code AS company_code,
                ip.contract_start_date,
                ip.contract_end_date,
                ip.default_coverage_percentage,
                ip.credit_limit,
                ip.current_balance,
                COUNT(DISTINCT i.id) AS outstanding_count,
                COALESCE(SUM(ii.total_price), 0) AS total_outstanding_value,
                COUNT(DISTINCT pip.patient_id) AS active_members
            FROM insurance_providers ip
            LEFT JOIN patient_insurance_profiles pip ON pip.insurance_provider_id = ip.id 
                AND pip.status = 'active' 
                AND pip.deleted_at IS NULL
            LEFT JOIN patients p ON p.id = pip.patient_id AND p.deleted_at IS NULL
            LEFT JOIN invoices i ON i.patient_id = p.id 
                AND i.payment_status IN ('unpaid', 'partial') 
                AND i.billing_type = 'insurance' 
                AND i.deleted_at IS NULL
            LEFT JOIN invoice_items ii ON ii.invoice_id = i.id
            WHERE ip.status = 'active' AND ip.deleted_at IS NULL";
    
    if (!$is_global_admin && $branch_id > 0) {
        $sql .= " AND ip.branch_id = :branch_id";
        $params = [':branch_id' => $branch_id];
    } else {
        $params = [];
    }
    
    $sql .= " GROUP BY ip.id
              HAVING outstanding_count > 0
              ORDER BY total_outstanding_value DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $sponsors = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('Insurance claims init error: ' . $e->getMessage());
    $sponsors = [];
}

// Fetch insurance providers for dropdown
$insurance_providers = [];
try {
    $stmt = $pdo->prepare("SELECT id, name, code FROM insurance_providers WHERE status = 'active' AND deleted_at IS NULL ORDER BY name");
    $stmt->execute();
    $insurance_providers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('Fetch providers error: ' . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Insurance & Corporate Claims | HMS ERP</title>
    <!-- CDNs to avoid 404 -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
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
            --light-bg: #f5f7fb;
            --white: #ffffff;
            --gray: #6c757d;
            --gray-light: #e9ecef;
            --orange: #f9844a;
            --purple: #7209b7;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif; background: linear-gradient(135deg, #f5f7fb 0%, #eef2f6 100%); overflow-x: hidden; }
        .content-page { margin-left: 280px; margin-top: 70px; padding: 24px 32px; transition: margin-left 0.3s cubic-bezier(0.4, 0, 0.2, 1); min-height: calc(100vh - 70px); }
        body.erp-sidebar-collapsed .content-page { margin-left: 85px; }
        @media (max-width: 768px) { .content-page { margin-left: 0 !important; padding: 16px; } }
        
        .welcome-header { background: linear-gradient(135deg, var(--primary-dark) 0%, var(--primary) 100%); border-radius: 24px; padding: 20px 28px; color: white; margin-bottom: 24px; }
        .welcome-title { font-size: 22px; font-weight: 700; margin-bottom: 4px; }
        .welcome-subtitle { color: rgba(255,255,255,0.7); font-size: 13px; }
        .time-badge { background: rgba(255,255,255,0.2); display: inline-block; padding: 8px 16px; border-radius: 30px; font-size: 13px; backdrop-filter: blur(4px); }
        
        .stats-row { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 24px; }
        @media (max-width: 992px) { .stats-row { grid-template-columns: repeat(2, 1fr); } }
        @media (max-width: 576px) { .stats-row { grid-template-columns: 1fr; } }
        
        .stat-mini { background: white; border-radius: 16px; padding: 16px 20px; border: 1px solid var(--gray-light); }
        .stat-mini .number { font-size: 28px; font-weight: 800; color: var(--dark); }
        .stat-mini .label { font-size: 12px; color: var(--gray); font-weight: 500; }
        .stat-mini .sub { font-size: 11px; color: var(--gray); margin-top: 4px; }
        
        .dashboard-grid { display: grid; grid-template-columns: 380px 1fr; gap: 24px; }
        @media (max-width: 1200px) { .dashboard-grid { grid-template-columns: 1fr; } }
        
        .card-modern { background: white; border-radius: 20px; border: 1px solid var(--gray-light); overflow: hidden; margin-bottom: 24px; min-height: 400px; }
        .card-header-custom { padding: 16px 20px; background: #f8fafc; border-bottom: 1px solid var(--gray-light); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; }
        .card-header-custom h3 { margin: 0; font-size: 16px; font-weight: 700; color: var(--dark); }
        
        .sponsor-list { max-height: 600px; overflow-y: auto; }
        .sponsor-list::-webkit-scrollbar { width: 6px; }
        .sponsor-list::-webkit-scrollbar-thumb { background: var(--gray-light); border-radius: 3px; }
        
        .sponsor-item { padding: 14px 16px; border-bottom: 1px solid #f0f2f5; cursor: pointer; transition: all 0.2s; }
        .sponsor-item:hover { background: #f8fafc; }
        .sponsor-item.active { background: #e0e7ff; border-left: 3px solid var(--primary); }
        .badge-outstanding { background: var(--warning); color: #92400e; border-radius: 20px; padding: 2px 8px; font-size: 11px; font-weight: 600; }
        
        .action-btn { padding: 8px 18px; border-radius: 12px; font-size: 13px; font-weight: 600; border: none; cursor: pointer; transition: all 0.2s; }
        .action-btn-primary { background: var(--primary); color: white; }
        .action-btn-primary:hover { background: var(--primary-dark); }
        .action-btn-success { background: var(--success); color: white; }
        .action-btn-success:hover { background: #059669; }
        .action-btn-danger { background: var(--danger); color: white; }
        .action-btn-danger:hover { background: #dc2626; }
        .action-btn-outline { background: white; border: 1px solid var(--gray-light); color: var(--dark); }
        .action-btn-outline:hover { border-color: var(--primary); color: var(--primary); }
        .action-btn-sm { padding: 4px 12px; font-size: 12px; }
        
        .toast-notification { position: fixed; bottom: 20px; right: 20px; background: white; border-radius: 12px; padding: 12px 20px; box-shadow: 0 10px 25px rgba(0,0,0,0.1); z-index: 1100; animation: slideIn 0.3s ease; display: flex; align-items: center; gap: 12px; border-left: 4px solid var(--success); }
        .toast-error { border-left-color: var(--danger); }
        @keyframes slideIn { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
        
        .loading-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(255,255,255,0.7); z-index: 2000; display: none; align-items: center; justify-content: center; }
        .loading-overlay.active { display: flex; }
        .spinner { width: 40px; height: 40px; border: 3px solid var(--gray-light); border-top-color: var(--primary); border-radius: 50%; animation: spin 0.8s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }
        
        .empty-state { text-align: center; padding: 40px; color: var(--gray); }
        .detail-section { padding: 20px; }
        .claims-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .claims-table th, .claims-table td { padding: 10px 10px; border-bottom: 1px solid #f0f2f5; vertical-align: middle; }
        .claims-table th { background: #f8fafc; font-weight: 600; font-size: 11px; color: var(--gray); text-transform: uppercase; letter-spacing: 0.3px; }
        .claims-table tr:hover { background: #f8fafc; }
        .claims-table .text-end { text-align: right; }
        
        .summary-bar { background: #f8fafc; padding: 12px 20px; border-top: 1px solid var(--gray-light); }
        .policy-badge { background: #e9ecef; padding: 6px 12px; border-radius: 12px; display: inline-block; margin-right: 10px; font-size: 12px; }
        
        .search-box { display: flex; gap: 8px; align-items: center; }
        .search-box input { width: 140px; }
        .filter-dropdown { min-width: 140px; }
        
        .badge { display: inline-block; padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: 600; }
        .badge-compiled { background: #f3f4f6; color: #6b7280; }
        .badge-submitted { background: #fef3c7; color: #d97706; }
        .badge-settled { background: #d1fae5; color: #059669; }
        .badge-disputed { background: #fee2e2; color: #dc2626; }
        
        .form-control { border-radius: 12px; border-color: var(--gray-light); padding: 10px 14px; }
        .form-control:focus { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(67,97,238,0.1); }
        .form-control-sm { padding: 6px 12px; font-size: 13px; }
        
        .modal-content { border-radius: 20px; border: none; }
        .modal-header { border-bottom: 1px solid var(--gray-light); padding: 20px 24px; }
        .modal-body { padding: 24px; }
        .modal-footer { border-top: 1px solid var(--gray-light); padding: 16px 24px; }
        
        .text-success { color: #059669 !important; }
        .text-danger { color: #dc2626 !important; }
        .text-warning { color: #d97706 !important; }
        .text-muted { color: #6c757d !important; }
        
        @media (max-width: 576px) {
            .content-page { padding: 12px; }
            .welcome-header { padding: 16px; }
            .claims-table { font-size: 11px; }
            .claims-table th, .claims-table td { padding: 6px 4px; }
        }
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
                    <div class="col-md-7">
                        <div class="welcome-title">Insurance & Corporate Credit Management 🏢</div>
                        <div class="welcome-subtitle">Batch claim compilation, coverage split, and revenue cycle management.</div>
                        <div class="small mt-2"><i class="fas fa-sync-alt"></i> Real‑time updates – list refreshes automatically every 15 seconds.</div>
                    </div>
                    <div class="col-md-5 text-md-end mt-3 mt-md-0">
                        <div class="time-badge"><i class="far fa-calendar-alt"></i> <?php echo escapeHtml($current_date); ?> <i class="far fa-clock ms-2"></i> <?php echo escapeHtml($current_time); ?></div>
                    </div>
                </div>
            </div>

            <!-- Quick Stats -->
            <div class="stats-row">
                <div class="stat-mini">
                    <div class="number"><?php echo number_format($claim_stats['total_claims'] ?? 0); ?></div>
                    <div class="label">Total Claims</div>
                </div>
                <div class="stat-mini">
                    <div class="number text-primary"><?php echo number_format($claim_stats['pending_count'] ?? 0); ?></div>
                    <div class="label">Pending</div>
                </div>
                <div class="stat-mini">
                    <div class="number text-success"><?php echo number_format($claim_stats['settled_count'] ?? 0); ?></div>
                    <div class="label">Settled</div>
                </div>
                <div class="stat-mini">
                    <div class="number text-danger"><?php echo number_format($claim_stats['total_amount'] ?? 0, 2); ?></div>
                    <div class="label">Total Claim Value (ETB)</div>
                </div>
            </div>

            <div class="dashboard-grid" id="mainGrid">
                <!-- LEFT PANEL: Corporate Sponsors List (auto‑refreshes) -->
                <div class="card-modern">
                    <div class="card-header-custom">
                        <h3><i class="fas fa-building me-2"></i> Insurance / Corporate Partners</h3>
                        <div class="d-flex gap-2 align-items-center flex-wrap">
                            <div class="search-box">
                                <input type="text" id="sponsorSearch" class="form-control form-control-sm" placeholder="Search..." style="width:120px;">
                                <button class="btn btn-sm btn-outline" onclick="searchSponsors()"><i class="fas fa-search"></i></button>
                            </div>
                            <select id="filterDropdown" class="form-select form-select-sm filter-dropdown" onchange="refreshSponsorList()">
                                <option value="all">All Partners</option>
                                <option value="credit_limit_exceeded">Credit Limit Exceeded</option>
                                <option value="contract_expiring">Contract Expiring</option>
                            </select>
                        </div>
                    </div>
                    <div class="sponsor-list" id="sponsorListContainer">
                        <?php if (count($sponsors) > 0): ?>
                            <?php foreach ($sponsors as $sp): ?>
                                <div class="sponsor-item" data-provider-id="<?php echo $sp['provider_id']; ?>" onclick="selectSponsor(<?php echo $sp['provider_id']; ?>, this)">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <div class="fw-bold"><?php echo escapeHtml($sp['provider_name']); ?></div>
                                            <div class="small text-muted">Code: <?php echo escapeHtml($sp['company_code']); ?></div>
                                            <div class="small text-muted"><i class="fas fa-users"></i> <?php echo $sp['active_members'] ?? 0; ?> active members</div>
                                        </div>
                                        <div class="text-end">
                                            <span class="badge-outstanding"><?php echo $sp['outstanding_count']; ?> invoices</span>
                                            <div class="small mt-1"><?php echo number_format($sp['total_outstanding_value'], 2); ?> ETB</div>
                                            <?php if ($sp['contract_end_date']): ?>
                                                <div class="small text-muted">Contract: <?php echo date('d/m/Y', strtotime($sp['contract_end_date'])); ?></div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="empty-state"><i class="fas fa-check-circle fa-2x mb-2 text-success"></i><p>No active corporate partners with pending claims.</p></div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- RIGHT PANEL: Claim Processing Desk -->
                <div class="card-modern">
                    <div class="card-header-custom">
                        <h3><i class="fas fa-file-invoice me-2"></i> Claim Processing & Split Desk</h3>
                        <div>
                            <button class="action-btn action-btn-outline action-btn-sm" onclick="refreshSponsorList()"><i class="fas fa-sync-alt"></i></button>
                            <button class="action-btn action-btn-primary action-btn-sm" onclick="viewClaimHistory()"><i class="fas fa-history"></i> History</button>
                        </div>
                    </div>
                    <div id="claimWorkspace">
                        <div class="empty-state">
                            <i class="fas fa-hand-pointer fa-2x mb-2 text-muted"></i>
                            <p>Select a corporate insurance provider from the ledger roster to load active clinical liabilities and compile batch statement invoices.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php include('assets/inc/footer.php'); ?>
</div>

<div id="loadingOverlay" class="loading-overlay"><div class="spinner"></div></div>
<input type="hidden" id="csrfToken" value="<?php echo escapeHtml($csrf_token); ?>">
<input type="hidden" id="isAdmin" value="<?php echo $is_global_admin ? '1' : '0'; ?>">

<!-- Claim History Modal -->
<div class="modal fade" id="historyModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-history me-2"></i> Claim History</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="table-responsive">
                    <table class="claims-table">
                        <thead>
                            <tr>
                                <th>Batch Number</th>
                                <th>Provider</th>
                                <th>Generated By</th>
                                <th>Items</th>
                                <th class="text-end">Total Claimed</th>
                                <th>Status</th>
                                <th>Date</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="historyBody">
                            <tr><td colspan="8" class="text-center text-muted">Loading...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary" onclick="loadClaimHistory()"><i class="fas fa-sync-alt"></i> Refresh</button>
            </div>
        </div>
    </div>
</div>

<!-- Pre-Authorization Modal -->
<div class="modal fade" id="preAuthModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-file-contract me-2"></i> Pre-Authorization Required</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i> This provider requires pre-authorization for claims.
                </div>
                <form id="preAuthForm">
                    <input type="hidden" name="csrf_token" id="preAuthCsrf" value="<?php echo escapeHtml($csrf_token); ?>">
                    <input type="hidden" name="provider_id" id="preAuthProviderId">
                    <input type="hidden" name="selected_items" id="preAuthSelectedItems">
                    <div class="mb-3">
                        <label>Pre-Authorization Code <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="preAuthCode" placeholder="Enter pre-authorization code" required>
                    </div>
                    <div class="mb-3">
                        <label>Notes</label>
                        <textarea class="form-control" id="preAuthNotes" rows="2" placeholder="Additional notes"></textarea>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="submitBatchClaim()"><i class="fas fa-check"></i> Submit Claim</button>
            </div>
        </div>
    </div>
</div>

<script>
let currentProviderId = null;
let currentActiveRow = null;
let currentItems = [];
let currentProviderData = null;
let refreshTimer = null;

function showToast(message, type = 'success') {
    const toast = document.createElement('div');
    toast.className = `toast-notification ${type === 'error' ? 'toast-error' : ''}`;
    toast.innerHTML = `<i class="fas fa-${type === 'error' ? 'exclamation-circle' : 'check-circle'}"></i> <span>${escapeHtml(message)}</span>`;
    document.body.appendChild(toast);
    setTimeout(() => toast.remove(), 4000);
}

function showLoading(show) {
    document.getElementById('loadingOverlay').classList.toggle('active', show);
}

function escapeHtml(str) {
    if (!str) return '';
    return String(str).replace(/[&<>]/g, m => m === '&' ? '&amp;' : (m === '<' ? '&lt;' : '&gt;'));
}

function formatMoney(amount) {
    return parseFloat(amount || 0).toFixed(2);
}

function getStatusBadge(status) {
    const classes = {
        'compiled': 'badge-compiled',
        'submitted': 'badge-submitted',
        'settled': 'badge-settled',
        'disputed': 'badge-disputed'
    };
    const labels = {
        'compiled': 'Compiled',
        'submitted': 'Submitted',
        'settled': 'Settled',
        'disputed': 'Disputed'
    };
    return `<span class="badge ${classes[status] || ''}">${labels[status] || status}</span>`;
}

// Real‑time refresh of left panel (polling)
function refreshSponsorList() {
    const filter = $('#filterDropdown').val() || 'all';
    const search = $('#sponsorSearch').val() || '';
    
    showLoading(true);
    $.ajax({
        url: window.location.pathname,
        method: 'GET',
        data: { 
            ajax_refresh_sponsors: 1,
            filter: filter,
            search: search
        },
        success: function(html) {
            showLoading(false);
            const scrollPos = $('.sponsor-list').scrollTop();
            $('#sponsorListContainer').html(html);
            $('.sponsor-list').scrollTop(scrollPos);
            
            if (currentProviderId) {
                const targetRow = $(`.sponsor-item[data-provider-id="${currentProviderId}"]`);
                if (targetRow.length) {
                    selectSponsor(currentProviderId, targetRow[0]);
                } else {
                    currentProviderId = null;
                    $('#claimWorkspace').html(`<div class="empty-state"><i class="fas fa-hand-pointer fa-2x mb-2 text-muted"></i><p>Select a corporate insurance provider from the ledger roster to load active clinical liabilities and compile batch statement invoices.</p></div>`);
                }
            }
            requestAnimationFrame(() => {
                const grid = document.getElementById('mainGrid');
                if (grid) grid.style.gridTemplateColumns = '380px 1fr';
            });
        },
        error: function() { 
            showLoading(false); 
            showToast('Failed to refresh sponsor list', 'error'); 
        }
    });
}

function searchSponsors() {
    refreshSponsorList();
}

function selectSponsor(providerId, element) {
    $('.sponsor-item').removeClass('active');
    $(element).addClass('active');
    currentProviderId = providerId;
    currentActiveRow = element;
    
    showLoading(true);
    $.ajax({
        url: window.location.pathname + '?ajax=claims_action&action=fetch_provider_items&provider_id=' + providerId,
        method: 'GET',
        success: function(res) {
            showLoading(false);
            if (res.success && res.items) {
                currentItems = res.items;
                currentProviderData = res.provider;
                renderClaimWorkspace(res.provider, res.items, res.totals, res.policy_info);
            } else {
                showToast(res.message || 'Failed to load items', 'error');
                $('#claimWorkspace').html(`<div class="empty-state"><i class="fas fa-exclamation-triangle fa-2x mb-2 text-warning"></i><p>${escapeHtml(res.message || 'No pending billable items for this provider.')}</p></div>`);
                if (currentActiveRow) $(currentActiveRow).removeClass('active');
                currentProviderId = null;
            }
        },
        error: function() { 
            showLoading(false); 
            showToast('Network error', 'error'); 
        }
    });
}

function renderClaimWorkspace(provider, items, totals, policyInfo) {
    let itemsHtml = '';
    if (items && items.length > 0) {
        items.forEach((item, idx) => {
            const patientAge = item.date_of_birth ? calculateAge(item.date_of_birth) : 'N/A';
            itemsHtml += `
                <tr>
                    <td><input type="checkbox" class="item-checkbox" data-item-id="${item.item_id}" checked></td>
                    <td>
                        ${escapeHtml(item.patient_name)}<br>
                        <small class="text-muted">MRN: ${escapeHtml(item.mr_number)} | Age: ${patientAge}</small>
                    </td>
                    <td>
                        ${escapeHtml(item.item_name)}<br>
                        <small class="text-muted">${escapeHtml(item.service_category || 'N/A')}</small>
                    </td>
                    <td class="text-end">${formatMoney(item.gross_amount)}</td>
                    <td class="text-end text-primary">${formatMoney(item.insurance_share)}</td>
                    <td class="text-end">${formatMoney(item.copay_share)}</td>
                </tr>
            `;
        });
    } else {
        itemsHtml = '<tr><td colspan="6" class="text-center text-muted">No items found</td></tr>';
    }
    
    const credit_used_pct = provider.credit_limit > 0 ? Math.round((provider.current_balance / provider.credit_limit) * 100) : 0;
    const credit_class = credit_used_pct >= 90 ? 'text-danger' : (credit_used_pct >= 70 ? 'text-warning' : 'text-success');
    
    const html = `
        <div class="detail-section">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <div>
                    <h5 class="mb-0">${escapeHtml(provider.name)} <span class="badge bg-primary">${escapeHtml(provider.code)}</span></h5>
                    <div class="small text-muted">
                        Coverage: ${provider.default_coverage_percentage}% | Co-pay: ${provider.default_co_pay_percentage}%
                        ${provider.requires_pre_authorization ? ' | <span class="text-warning"><i class="fas fa-exclamation-triangle"></i> Pre-auth required</span>' : ''}
                    </div>
                    <div class="small ${credit_class}">
                        <i class="fas fa-credit-card"></i> Credit: ${formatMoney(provider.current_balance)} / ${formatMoney(provider.credit_limit)} ETB (${credit_used_pct}%)
                    </div>
                </div>
                <button class="action-btn action-btn-outline action-btn-sm" onclick="selectSponsor(${provider.id}, $('.sponsor-item.active')[0])">
                    <i class="fas fa-sync-alt"></i> Reload
                </button>
            </div>
            
            <div class="alert alert-info py-2 mb-3" style="font-size:12px;">
                <div class="row">
                    <div class="col-md-4"><strong>Total Gross:</strong> ${formatMoney(totals.gross)} ETB</div>
                    <div class="col-md-4"><strong>Total Discount:</strong> ${formatMoney(totals.discount)} ETB</div>
                    <div class="col-md-4"><strong>Total Net:</strong> ${formatMoney(totals.gross - totals.discount)} ETB</div>
                </div>
            </div>
            
            <div class="table-responsive">
                <table class="claims-table">
                    <thead>
                        <tr>
                            <th style="width:30px;"><input type="checkbox" id="selectAllItems" checked></th>
                            <th>Patient</th>
                            <th>Service / Item</th>
                            <th class="text-end">Gross (ETB)</th>
                            <th class="text-end">Sponsor Share (ETB)</th>
                            <th class="text-end">Patient Co-Pay (ETB)</th>
                        </tr>
                    </thead>
                    <tbody id="claimsTableBody">
                        ${itemsHtml}
                    </tbody>
                </table>
            </div>
            
            <div class="summary-bar">
                <div class="row">
                    <div class="col-md-3"><strong>Selected Gross:</strong> <span id="summaryGross">0.00</span> ETB</div>
                    <div class="col-md-3"><strong>Selected Discount:</strong> <span id="summaryDiscount">0.00</span> ETB</div>
                    <div class="col-md-3"><strong>Sponsor Claim:</strong> <span id="summaryInsurance">0.00</span> ETB</div>
                    <div class="col-md-3"><strong>Patient Co-Pay:</strong> <span id="summaryCopay">0.00</span> ETB</div>
                </div>
                <div class="mt-3 text-end">
                    <button class="action-btn action-btn-primary" id="generateBatchBtn">
                        <i class="fas fa-file-invoice"></i> Compile Selected Items into Batch Claim
                    </button>
                </div>
            </div>
        </div>
    `;
    
    $('#claimWorkspace').html(html);
    
    // Select all checkbox
    $('#selectAllItems').on('change', function() {
        $('.item-checkbox').prop('checked', $(this).is(':checked'));
        updateSummary();
    });
    
    $('.item-checkbox').on('change', function() {
        updateSummary();
    });
    
    updateSummary();
    
    // Generate batch claim
    $('#generateBatchBtn').on('click', function() {
        const selectedIds = [];
        $('.item-checkbox:checked').each(function() {
            selectedIds.push($(this).data('item-id'));
        });
        if (selectedIds.length === 0) {
            showToast('Please select at least one item to batch.', 'error');
            return;
        }
        
        // Check if pre-authorization is required
        if (provider.requires_pre_authorization) {
            $('#preAuthProviderId').val(provider.id);
            $('#preAuthSelectedItems').val(JSON.stringify(selectedIds));
            $('#preAuthCode').val('');
            $('#preAuthNotes').val('');
            $('#preAuthModal').modal('show');
        } else {
            // Direct submit
            submitBatchClaimDirect(selectedIds);
        }
    });
    
    function updateSummary() {
        let gross = 0, insurance = 0, copay = 0, discount = 0;
        $('.item-checkbox:checked').each(function() {
            // Find the corresponding item data
            const itemId = $(this).data('item-id');
            const item = currentItems.find(i => i.item_id === itemId);
            if (item) {
                gross += item.gross_amount || 0;
                insurance += item.insurance_share || 0;
                copay += item.copay_share || 0;
                discount += item.discount_amount || 0;
            }
        });
        $('#summaryGross').text(formatMoney(gross));
        $('#summaryInsurance').text(formatMoney(insurance));
        $('#summaryCopay').text(formatMoney(copay));
        $('#summaryDiscount').text(formatMoney(discount));
    }
}

function submitBatchClaimDirect(selectedIds) {
    const csrf = $('#csrfToken').val();
    showLoading(true);
    $.ajax({
        url: window.location.pathname + '?ajax=claims_action',
        method: 'POST',
        data: {
            action: 'generate_batch_claim',
            provider_id: currentProviderId,
            billing_item_ids: selectedIds,
            pre_auth_code: '',
            notes: '',
            csrf_token: csrf
        },
        dataType: 'json',
        success: function(res) {
            showLoading(false);
            if (res.success) {
                showToast(res.message);
                refreshSponsorList();
                Swal.fire({
                    title: 'Batch Claim Created!',
                    html: `
                        <p><strong>Batch Number:</strong> ${escapeHtml(res.batch_number)}</p>
                        <p><strong>Total Claimed:</strong> ${formatMoney(res.total_claimed)} ETB</p>
                        <p><strong>Insurance Covered:</strong> ${formatMoney(res.insurance_covered)} ETB</p>
                        <p><strong>Patient Co-pay:</strong> ${formatMoney(res.patient_copay)} ETB</p>
                    `,
                    icon: 'success',
                    confirmButtonText: 'OK',
                    confirmButtonColor: '#4361ee'
                });
                $('#claimWorkspace').html(`<div class="empty-state"><i class="fas fa-check-circle fa-2x mb-2 text-success"></i><p>Batch claim created successfully.<br>Select another provider to continue.</p></div>`);
                currentProviderId = null;
            } else {
                showToast(res.message || 'Batch creation failed', 'error');
            }
        },
        error: function() { 
            showLoading(false); 
            showToast('Server error', 'error'); 
        }
    });
}

function submitBatchClaim() {
    const selectedItemsJson = $('#preAuthSelectedItems').val();
    const selectedIds = JSON.parse(selectedItemsJson);
    const preAuthCode = $('#preAuthCode').val().trim();
    const notes = $('#preAuthNotes').val().trim();
    
    if (!preAuthCode) {
        showToast('Please enter the pre-authorization code.', 'error');
        return;
    }
    
    $('#preAuthModal').modal('hide');
    
    const csrf = $('#csrfToken').val();
    showLoading(true);
    $.ajax({
        url: window.location.pathname + '?ajax=claims_action',
        method: 'POST',
        data: {
            action: 'generate_batch_claim',
            provider_id: currentProviderId,
            billing_item_ids: selectedIds,
            pre_auth_code: preAuthCode,
            notes: notes,
            csrf_token: csrf
        },
        dataType: 'json',
        success: function(res) {
            showLoading(false);
            if (res.success) {
                showToast(res.message);
                refreshSponsorList();
                Swal.fire({
                    title: 'Batch Claim Created with Pre-Authorization!',
                    html: `
                        <p><strong>Batch Number:</strong> ${escapeHtml(res.batch_number)}</p>
                        <p><strong>Pre-Auth Code:</strong> ${escapeHtml(preAuthCode)}</p>
                        <p><strong>Total Claimed:</strong> ${formatMoney(res.total_claimed)} ETB</p>
                    `,
                    icon: 'success',
                    confirmButtonText: 'OK',
                    confirmButtonColor: '#4361ee'
                });
                $('#claimWorkspace').html(`<div class="empty-state"><i class="fas fa-check-circle fa-2x mb-2 text-success"></i><p>Batch claim created successfully.<br>Select another provider to continue.</p></div>`);
                currentProviderId = null;
            } else {
                showToast(res.message || 'Batch creation failed', 'error');
            }
        },
        error: function() { 
            showLoading(false); 
            showToast('Server error', 'error'); 
        }
    });
}

function viewClaimHistory() {
    loadClaimHistory();
    $('#historyModal').modal('show');
}

function loadClaimHistory() {
    const tbody = $('#historyBody');
    tbody.html('<tr><td colspan="8" class="text-center text-muted"><i class="fas fa-spinner fa-spin"></i> Loading...</td></tr>');
    
    $.ajax({
        url: window.location.pathname + '?ajax=claims_action&action=fetch_claim_history&csrf_token=' + encodeURIComponent($('#csrfToken').val()),
        method: 'GET',
        dataType: 'json',
        success: function(res) {
            if (res.success && res.history) {
                if (res.history.length === 0) {
                    tbody.html('<tr><td colspan="8" class="text-center text-muted">No claim history found.</td></tr>');
                    return;
                }
                let html = '';
                res.history.forEach(claim => {
                    html += `
                        <tr>
                            <td><strong>${escapeHtml(claim.claim_batch_number)}</strong></td>
                            <td>${escapeHtml(claim.provider_name)}</td>
                            <td>${escapeHtml(claim.generated_by)}</td>
                            <td>${claim.item_count || 0}</td>
                            <td class="text-end">${formatMoney(claim.total_claimed_amount)}</td>
                            <td>${getStatusBadge(claim.status)}</td>
                            <td>${new Date(claim.created_at).toLocaleString()}</td>
                            <td>
                                <button class="action-btn action-btn-sm action-btn-outline" onclick="viewClaimDetails(${claim.id})">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </td>
                        </tr>
                    `;
                });
                tbody.html(html);
            } else {
                tbody.html('<tr><td colspan="8" class="text-center text-danger">Failed to load history</td></tr>');
            }
        },
        error: function() {
            tbody.html('<tr><td colspan="8" class="text-center text-danger">Network error</td></tr>');
        }
    });
}

function viewClaimDetails(claimId) {
    Swal.fire({
        title: 'Claim Details',
        text: 'Viewing details for claim #' + claimId,
        icon: 'info',
        confirmButtonText: 'OK'
    });
}

function calculateAge(dateOfBirth) {
    if (!dateOfBirth) return 'N/A';
    const birthDate = new Date(dateOfBirth);
    const age = new Date().getFullYear() - birthDate.getFullYear();
    return age;
}

// Enter key on search
$('#sponsorSearch').on('keypress', function(e) {
    if (e.which === 13) {
        searchSponsors();
    }
});

// Poll every 15 seconds
if (refreshTimer) clearInterval(refreshTimer);
refreshTimer = setInterval(refreshSponsorList, 15000);

$(document).ready(function() {
    const firstItem = $('.sponsor-item').first();
    if (firstItem.length) firstItem.click();
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

// Auto-refresh stats periodically
setInterval(function() {
    // Refresh stats via AJAX
    $.ajax({
        url: window.location.pathname + '?ajax=claims_action&action=fetch_claim_history&csrf_token=' + encodeURIComponent($('#csrfToken').val()),
        method: 'GET',
        dataType: 'json',
        success: function(res) {
            if (res.success && res.history) {
                const total = res.history.length;
                const pending = res.history.filter(c => c.status === 'compiled' || c.status === 'submitted').length;
                const settled = res.history.filter(c => c.status === 'settled').length;
                const totalAmount = res.history.reduce((sum, c) => sum + parseFloat(c.total_claimed_amount), 0);
                
                $('.stat-mini').eq(0).find('.number').text(total);
                $('.stat-mini').eq(1).find('.number').text(pending);
                $('.stat-mini').eq(2).find('.number').text(settled);
                $('.stat-mini').eq(3).find('.number').text(formatMoney(totalAmount));
            }
        }
    });
}, 60000);
</script>
</body>
</html>