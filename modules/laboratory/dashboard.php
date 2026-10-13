<?php
/**
 * ============================================================
 * LABORATORY INFORMATION SYSTEM - dashboard.php
 * VERSION: v12.0 - SPECIMEN-PARTITIONED ENCOUNTER WORKFLOW (FULLY FIXED)
 * ============================================================
 * 
 * ARCHITECTURAL REFACTOR: Specimen-Partitioned Workflow
 * - Pending: Specimen-type grouped collection with dynamic forms
 * - Collected: Panel-sectioned result entry with individual saving
 * - Complete: Granular verification with panel-level release
 * - Enhanced: Multi-specimen support, partial completion, critical alerts
 * - FIXED: All database column name mismatches and status enum issues
 * ============================================================
 */

session_start();

// ============================================================
// 1. SYSTEM BOOTSTRAP
// ============================================================
require_once __DIR__ . '/../../config/db_connection.php';
require_once __DIR__ . '/../../includes/auth_helper.php';

date_default_timezone_set('Africa/Addis_Ababa');

$pdo = getDB();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$user_id = (int)($_SESSION['user_id'] ?? 0);
$branch_id = (int)($_SESSION['branch_id'] ?? 0);
$is_global_admin = ($_SESSION['user_type'] ?? '') === 'super_admin';
$user_type = $_SESSION['user_type'] ?? '';
$first_name = $_SESSION['first_name'] ?? '';
$last_name = $_SESSION['last_name'] ?? '';
$user_full_name = trim($first_name . ' ' . $last_name);

// Authentication check
if ($user_id <= 0) {
    header('Location: ../../index.php');
    exit;
}

// Permission check
if (!$is_global_admin && !user_has_permission($pdo, 'view_lab_dashboard', $user_id) && 
    !user_has_permission($pdo, 'enter_lab_results', $user_id) && 
    !user_has_permission($pdo, 'receive_lab_specimens', $user_id)) {
    header('Location: dashboard.php?error=unauthorized');
    exit;
}

// CSRF Token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// ============================================================
// 2. ENHANCED LOGGER CLASS
// ============================================================
class Logger {
    private $pdo;
    private $user_id;
    private $branch_id;
    private $ip_address;
    private $user_agent;
    private $request_id;
    /** @var PDOStatement|null Reused across batch audit writes to avoid re-preparing each INSERT */
    private $auditInsertStmt = null;

    public function __construct($pdo, $user_id = null, $branch_id = null) {
        $this->pdo = $pdo;
        $this->user_id = $user_id ?? ($_SESSION['user_id'] ?? 0);
        $this->branch_id = $branch_id ?? ($_SESSION['branch_id'] ?? 0);
        $this->ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $this->user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
        $this->request_id = $_SERVER['HTTP_X_REQUEST_ID'] ?? uniqid('req_', true);
    }

    private function getAuditInsertStmt() {
        if ($this->auditInsertStmt === null) {
            $this->auditInsertStmt = $this->pdo->prepare("
                INSERT INTO audit_logs (
                    branch_id, user_id, action_type, target_table, record_id,
                    old_values, new_values, ip_address, user_agent,
                    request_id, session_id, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
        }
        return $this->auditInsertStmt;
    }

    public function log($action_type, $target_table, $record_id, $new_values = null, $old_values = null) {
        try {
            $stmt = $this->getAuditInsertStmt();

            $new_json = $new_values ? json_encode($new_values, JSON_UNESCAPED_UNICODE) : null;
            $old_json = $old_values ? json_encode($old_values, JSON_UNESCAPED_UNICODE) : null;

            $stmt->execute([
                $this->branch_id,
                $this->user_id,
                $action_type,
                $target_table,
                $record_id,
                $old_json,
                $new_json,
                $this->ip_address,
                $this->user_agent,
                $this->request_id,
                session_id() ?? null
            ]);

            return $this->pdo->lastInsertId();
        } catch (PDOException $e) {
            error_log('Audit log error: ' . $e->getMessage());
            return false;
        }
    }

    public function logBulk($action_type, $target_table, $data) {
        return $this->log($action_type, $target_table, null, $data);
    }

    /**
     * Granular audit entry for a single lab_result_details row change.
     * Payload shape: old/new each contain parameter_id, value, status.
     */
    public function logResultDetailChange($detailId, $actionType, $oldValues, $newValues) {
        return $this->log(
            $actionType,
            'lab_result_details',
            $detailId,
            $newValues,
            $oldValues
        );
    }

    /**
     * Batch audit entries without re-preparing the INSERT on every row.
     * Each item: detail_id, action, old, new.
     */
    public function logResultDetailChanges(array $changes) {
        if (empty($changes)) {
            return;
        }

        $stmt = $this->getAuditInsertStmt();
        $sessionId = session_id() ?: null;

        foreach ($changes as $change) {
            try {
                $old_json = json_encode($change['old'] ?? [], JSON_UNESCAPED_UNICODE);
                $new_json = json_encode($change['new'] ?? [], JSON_UNESCAPED_UNICODE);

                $stmt->execute([
                    $this->branch_id,
                    $this->user_id,
                    $change['action'] ?? 'UPDATE',
                    'lab_result_details',
                    (int)$change['detail_id'],
                    $old_json,
                    $new_json,
                    $this->ip_address,
                    $this->user_agent,
                    $this->request_id,
                    $sessionId
                ]);
            } catch (PDOException $e) {
                error_log('Batch audit log error (detail #' . ($change['detail_id'] ?? '?') . '): ' . $e->getMessage());
            }
        }
    }

    /**
     * Specimen/order rejection audit with before/after snapshots.
     */
    public function logSpecimenRejection($orderId, $oldValues, $newValues) {
        return $this->log('REJECT', 'lab_orders', $orderId, $newValues, $oldValues);
    }
}

// ============================================================
// 3. LAB SERVICE - SPECIMEN-PARTITIONED ENCOUNTER WORKFLOW (FULLY FIXED)
// ============================================================

class LabService {
    private $pdo;
    private $logger;
    private $user_id;
    private $branch_id;
    private $reference_ranges = [];

    /**
     * STATUS CONSTANTS - Mapped to database ENUM values
     * Database: lab_orders.status ENUM('pending_payment','pending','approved','collected','in_progress','completed','cancelled','rejected')
     */
    private const STATUS_PENDING = ['pending', 'pending_payment', 'approved'];
    private const STATUS_COLLECTED = ['collected'];
    private const STATUS_RESULT_ENTRY = ['collected', 'in_progress'];
    private const STATUS_AWAITING_VERIFY = ['in_progress'];
    private const STATUS_TERMINAL = ['completed', 'cancelled', 'rejected'];
    private const STATUS_COMPLETED = ['completed', 'verified'];
    
    /**
     * Database: lab_result_details.status ENUM('draft','submitted','verified','amended','rejected')
     */
    private const RESULT_STATUS_DRAFT = 'draft';
    private const RESULT_STATUS_SUBMITTED = 'submitted';
    private const RESULT_STATUS_VERIFIED = 'verified';
    private const RESULT_STATUS_AMENDED = 'amended';
    private const RESULT_STATUS_REJECTED = 'rejected';
    
    // Specimen type configuration for collection forms
    private $specimen_config = [
        'blood' => [
            'label' => '🩸 Blood Collection',
            'icon' => 'fa-droplet',
            'color' => '#dc2626',
            'fields' => [
                ['name' => 'collection_site', 'label' => 'Collection Site', 'type' => 'select', 
                 'options' => ['Cephalic Vein', 'Basilic Vein', 'Median Cubital', 'Fingerstick', 'Arterial Puncture']],
                ['name' => 'tube_type', 'label' => 'Tube Type', 'type' => 'select',
                 'options' => ['EDTA (Purple)', 'SST/Gel (Gold)', 'Sodium Citrate (Blue)', 'Heparin (Green)', 'Plain (Red)', 'Fluoride (Grey)']],
                ['name' => 'volume_ml', 'label' => 'Volume (mL)', 'type' => 'number', 'step' => '0.5'],
                ['name' => 'collection_method', 'label' => 'Collection Method', 'type' => 'select',
                 'options' => ['Venipuncture', 'Capillary', 'Arterial Puncture', 'Central Line']]
            ]
        ],
        'urine' => [
            'label' => '🧪 Urine Collection',
            'icon' => 'fa-toilet',
            'color' => '#f59e0b',
            'fields' => [
                ['name' => 'collection_method', 'label' => 'Collection Method', 'type' => 'select',
                 'options' => ['Midstream Clean Catch', 'Catheterized', 'Suprapubic Aspiration', '24-Hour Collection']],
                ['name' => 'container_type', 'label' => 'Container Type', 'type' => 'select',
                 'options' => ['Sterile Cup', 'Vacutainer', 'Urinalysis Tube', '24-Hour Collection Jug']],
                ['name' => 'volume_ml', 'label' => 'Volume (mL)', 'type' => 'number', 'step' => '1'],
                ['name' => 'preservative', 'label' => 'Preservative Used', 'type' => 'text']
            ]
        ],
        'serum' => [
            'label' => '🧫 Serum Collection',
            'icon' => 'fa-flask',
            'color' => '#f97316',
            'fields' => [
                ['name' => 'collection_site', 'label' => 'Collection Site', 'type' => 'select',
                 'options' => ['Cephalic Vein', 'Basilic Vein', 'Median Cubital']],
                ['name' => 'tube_type', 'label' => 'Tube Type', 'type' => 'select',
                 'options' => ['SST/Gel (Gold)', 'Plain (Red)', 'Heparin (Green)']],
                ['name' => 'volume_ml', 'label' => 'Volume (mL)', 'type' => 'number', 'step' => '0.5'],
                ['name' => 'clot_time', 'label' => 'Clot Time (minutes)', 'type' => 'number']
            ]
        ],
        'plasma' => [
            'label' => '🧫 Plasma Collection',
            'icon' => 'fa-flask',
            'color' => '#8b5cf6',
            'fields' => [
                ['name' => 'collection_site', 'label' => 'Collection Site', 'type' => 'select',
                 'options' => ['Cephalic Vein', 'Basilic Vein', 'Median Cubital']],
                ['name' => 'tube_type', 'label' => 'Tube Type', 'type' => 'select',
                 'options' => ['EDTA (Purple)', 'Heparin (Green)', 'Sodium Citrate (Blue)']],
                ['name' => 'volume_ml', 'label' => 'Volume (mL)', 'type' => 'number', 'step' => '0.5']
            ]
        ],
        'stool' => [
            'label' => '💩 Stool Collection',
            'icon' => 'fa-poop',
            'color' => '#92400e',
            'fields' => [
                ['name' => 'collection_method', 'label' => 'Collection Method', 'type' => 'select',
                 'options' => ['Random', 'Timed', '24-Hour Collection']],
                ['name' => 'container_type', 'label' => 'Container Type', 'type' => 'select',
                 'options' => ['Sterile Cup', 'Formalin Jar', 'Para-Pak']],
                ['name' => 'preservative', 'label' => 'Preservative Used', 'type' => 'select',
                 'options' => ['None', 'Formalin', 'PVA', 'SAF']]
            ]
        ],
        'csf' => [
            'label' => '🧠 CSF Collection',
            'icon' => 'fa-brain',
            'color' => '#06b6d4',
            'fields' => [
                ['name' => 'collection_method', 'label' => 'Collection Method', 'type' => 'select',
                 'options' => ['Lumbar Puncture', 'Ventricular Tap', 'Cisternal Puncture']],
                ['name' => 'tube_type', 'label' => 'Tube Type', 'type' => 'select',
                 'options' => ['Tube 1 (Chemistry)', 'Tube 2 (Microbiology)', 'Tube 3 (Cell Count)']],
                ['name' => 'volume_ml', 'label' => 'Volume (mL)', 'type' => 'number', 'step' => '0.5']
            ]
        ],
        'swab' => [
            'label' => '🦠 Swab Collection',
            'icon' => 'fa-swab',
            'color' => '#6366f1',
            'fields' => [
                ['name' => 'collection_site', 'label' => 'Collection Site', 'type' => 'select',
                 'options' => ['Nasopharyngeal', 'Oropharyngeal', 'Vaginal', 'Rectal', 'Wound', 'Skin']],
                ['name' => 'swab_type', 'label' => 'Swab Type', 'type' => 'select',
                 'options' => ['Flocked', 'Cotton', 'Nylon', 'Dacron']],
                ['name' => 'transport_media', 'label' => 'Transport Media', 'type' => 'select',
                 'options' => ['Viral Transport', 'Amies', 'Stuart', 'None']]
            ]
        ],
        'sputum' => [
            'label' => '💨 Sputum Collection',
            'icon' => 'fa-lungs',
            'color' => '#14b8a6',
            'fields' => [
                ['name' => 'collection_method', 'label' => 'Collection Method', 'type' => 'select',
                 'options' => ['Spontaneous', 'Induced', 'Bronchoscopy']],
                ['name' => 'container_type', 'label' => 'Container Type', 'type' => 'select',
                 'options' => ['Sterile Cup', 'Sputum Trap']],
                ['name' => 'volume_ml', 'label' => 'Volume (mL)', 'type' => 'number', 'step' => '0.5']
            ]
        ]
    ];
    
    public function __construct($pdo, $user_id, $branch_id) {
        $this->pdo = $pdo;
        $this->user_id = $user_id;
        $this->branch_id = $branch_id;
        $this->logger = new Logger($pdo, $user_id, $branch_id);
        $this->loadReferenceRanges();
    }
    
    private function loadReferenceRanges() {
        try {
            $stmt = $this->pdo->prepare("
                SELECT parameter_code, min_normal, max_normal, critical_low, critical_high 
                FROM lab_test_parameters 
                WHERE is_active = 1 AND deleted_at IS NULL
            ");
            $stmt->execute();
            $ranges = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($ranges as $range) {
                $this->reference_ranges[$range['parameter_code']] = [
                    'low' => $range['min_normal'],
                    'high' => $range['max_normal'],
                    'critical_low' => $range['critical_low'],
                    'critical_high' => $range['critical_high']
                ];
            }
        } catch (PDOException $e) {
            error_log('Error loading reference ranges: ' . $e->getMessage());
        }
    }
    
    private function getReferenceRange($parameter_code) {
        return $this->reference_ranges[$parameter_code] ?? null;
    }
    
    private function flagValue($value, $parameter_code) {
        $result = [
            'is_abnormal' => 0,
            'is_critical' => 0,
            'flag_type' => 'normal'
        ];
        
        if ($value === null || $value === '') {
            return $result;
        }
        
        $range = $this->getReferenceRange($parameter_code);
        if (!$range) {
            return $result;
        }
        
        $numValue = (float)$value;
        
        if ($range['critical_low'] !== null && $numValue < $range['critical_low']) {
            $result['is_critical'] = 1;
            $result['flag_type'] = 'critical_low';
            return $result;
        }
        
        if ($range['critical_high'] !== null && $numValue > $range['critical_high']) {
            $result['is_critical'] = 1;
            $result['flag_type'] = 'critical_high';
            return $result;
        }
        
        if ($range['low'] !== null && $numValue < $range['low']) {
            $result['is_abnormal'] = 1;
            $result['flag_type'] = 'low';
            return $result;
        }
        
        if ($range['high'] !== null && $numValue > $range['high']) {
            $result['is_abnormal'] = 1;
            $result['flag_type'] = 'high';
            return $result;
        }
        
        return $result;
    }

    /**
     * Normalize a result row into a single comparable string.
     */
    private function formatResultValue($numerical, $textual) {
        if ($numerical !== null && $numerical !== '') {
            return (string)$numerical;
        }
        return trim((string)($textual ?? ''));
    }

    /**
     * submitted/verified + changed value => amended (CLIA-style amendment trail).
     */
    private function resolveResultStatusOnSave($existingStatus, $valueChanged) {
        if ($valueChanged && in_array($existingStatus, ['submitted', 'verified'], true)) {
            return self::RESULT_STATUS_AMENDED;
        }
        if ($existingStatus === self::RESULT_STATUS_DRAFT) {
            return self::RESULT_STATUS_SUBMITTED;
        }
        if ($existingStatus === self::RESULT_STATUS_AMENDED) {
            return self::RESULT_STATUS_AMENDED;
        }
        if ($existingStatus === self::RESULT_STATUS_VERIFIED && !$valueChanged) {
            return self::RESULT_STATUS_VERIFIED;
        }
        return self::RESULT_STATUS_SUBMITTED;
    }

    private function fetchExistingResultDetail($orderId, $parameterId) {
        $stmt = $this->pdo->prepare("
            SELECT id, numerical_value, textual_value, status,
                   is_abnormal, is_critical, flag_type, verified_at, verified_by_user_id
            FROM lab_result_details
            WHERE lab_order_id = ?
              AND parameter_id = ?
              AND deleted_at IS NULL
            LIMIT 1
        ");
        $stmt->execute([$orderId, $parameterId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function normalizeSpecimenType($specimen_type) {
        $specimen_type = strtolower(trim((string)$specimen_type));
        if ($specimen_type === '') {
            $specimen_type = 'blood';
        }

        if (isset($this->specimen_config[$specimen_type])) {
            return $specimen_type;
        }

        foreach (array_keys($this->specimen_config) as $key) {
            if (strpos($specimen_type, $key) !== false) {
                return $key;
            }
        }

        return 'blood';
    }

    private function generateSpecimenBarcode() {
        do {
            $barcode = 'SPC-' . date('Ymd') . '-' . str_pad((string)random_int(1, 99999), 5, '0', STR_PAD_LEFT)
                     . chr(random_int(65, 90)) . chr(random_int(65, 90));
            $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM specimen_tracking WHERE barcode = ? AND deleted_at IS NULL");
            $stmt->execute([$barcode]);
        } while ((int)$stmt->fetchColumn() > 0);

        return $barcode;
    }

    private function normalizeCollectionPayload($specimen_payload) {
        if (isset($specimen_payload['order_ids']) && is_array($specimen_payload['order_ids'])) {
            return [
                'order_ids' => array_values(array_unique(array_map('intval', $specimen_payload['order_ids']))),
                'fields' => is_array($specimen_payload['fields'] ?? null) ? $specimen_payload['fields'] : []
            ];
        }

        $order_ids = [];
        $fields = [];
        if (is_array($specimen_payload)) {
            foreach ($specimen_payload as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $order_id = (int)($item['order_id'] ?? 0);
                if ($order_id > 0) {
                    $order_ids[] = $order_id;
                }
                if (empty($fields) && !empty($item['fields']) && is_array($item['fields'])) {
                    $fields = $item['fields'];
                }
            }
        }

        return [
            'order_ids' => array_values(array_unique($order_ids)),
            'fields' => $fields
        ];
    }

    /**
     * FIXED: Uses database-valid status values only
     */
    private function getOrderParameterCompletion($order_id) {
        $stmt = $this->pdo->prepare("
            SELECT 
                COUNT(*) AS total_params,
                SUM(CASE 
                    WHEN lrd.numerical_value IS NOT NULL 
                      OR (lrd.textual_value IS NOT NULL AND TRIM(lrd.textual_value) <> '') 
                    THEN 1 ELSE 0 
                END) AS entered_params
            FROM lab_test_parameters ltp
            INNER JOIN lab_tests_catalog ltc ON ltp.test_catalog_id = ltc.id AND ltc.deleted_at IS NULL
            INNER JOIN lab_orders lo ON lo.test_catalog_id = ltc.id AND lo.deleted_at IS NULL
            LEFT JOIN lab_result_details lrd 
                ON lrd.lab_order_id = lo.id 
               AND lrd.parameter_id = ltp.id 
               AND lrd.deleted_at IS NULL
            WHERE lo.id = ?
              AND ltp.deleted_at IS NULL
              AND ltp.is_active = 1
        ");
        $stmt->execute([$order_id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: ['total_params' => 0, 'entered_params' => 0];
    }

    /**
     * FIXED: Uses database-valid status values only
     */
    private function getOrderVerificationCompletion($order_id) {
        $stmt = $this->pdo->prepare("
            SELECT 
                COUNT(*) AS total_params,
                SUM(CASE 
                    WHEN lrd.verified_at IS NOT NULL 
                    THEN 1 ELSE 0 
                END) AS verified_params
            FROM lab_test_parameters ltp
            INNER JOIN lab_tests_catalog ltc ON ltp.test_catalog_id = ltc.id AND ltc.deleted_at IS NULL
            INNER JOIN lab_orders lo ON lo.test_catalog_id = ltc.id AND lo.deleted_at IS NULL
            LEFT JOIN lab_result_details lrd 
                ON lrd.lab_order_id = lo.id 
               AND lrd.parameter_id = ltp.id 
               AND lrd.deleted_at IS NULL
            WHERE lo.id = ?
              AND ltp.deleted_at IS NULL
              AND ltp.is_active = 1
              AND (lrd.numerical_value IS NOT NULL OR lrd.textual_value IS NOT NULL)
        ");
        $stmt->execute([$order_id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: ['total_params' => 0, 'verified_params' => 0];
    }

    /**
     * FIXED: Uses 'in_progress' and 'collected' instead of 'submitted'
     */
    private function syncOrderStatusAfterResults($order_id) {
        $completion = $this->getOrderParameterCompletion($order_id);
        $total = (int)($completion['total_params'] ?? 0);
        $entered = (int)($completion['entered_params'] ?? 0);

        if ($total <= 0) {
            return 'collected';
        }

        if ($entered >= $total) {
            $stmt = $this->pdo->prepare("
                UPDATE lab_orders
                SET status = 'in_progress',
                    results_ready_at = NOW(),
                    updated_at = NOW()
                WHERE id = ?
                  AND deleted_at IS NULL
            ");
            $stmt->execute([$order_id]);
            return 'in_progress';
        }

        if ($entered > 0) {
            $stmt = $this->pdo->prepare("
                UPDATE lab_orders
                SET status = 'in_progress',
                    updated_at = NOW()
                WHERE id = ?
                  AND deleted_at IS NULL
                  AND status IN ('collected', 'in_progress')
            ");
            $stmt->execute([$order_id]);
            return 'in_progress';
        }

        return 'collected';
    }

    /**
     * FIXED: Uses database-valid status values only
     */
    private function getOrderStatusForVisit($visit_id) {
        $stmt = $this->pdo->prepare("
            SELECT 
                COUNT(*) AS total_orders,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed_orders,
                SUM(CASE WHEN status IN ('collected', 'in_progress') THEN 1 ELSE 0 END) AS active_orders
            FROM lab_orders
            WHERE visit_id = ?
              AND branch_id = ?
              AND deleted_at IS NULL
              AND status NOT IN ('cancelled', 'rejected')
        ");
        $stmt->execute([$visit_id, $this->branch_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $total_orders = (int)($result['total_orders'] ?? 0);
        $completed_orders = (int)($result['completed_orders'] ?? 0);
        
        if ($total_orders <= 0) {
            return 'completed';
        }
        
        if ($completed_orders >= $total_orders) {
            return 'completed';
        }
        
        return 'in_progress';
    }

    private function updateVisitAfterCollection($visit_id) {
        $pending_stmt = $this->pdo->prepare("
            SELECT COUNT(*) FROM lab_orders
            WHERE visit_id = ?
              AND branch_id = ?
              AND status IN ('pending', 'pending_payment', 'approved')
              AND deleted_at IS NULL
        ");
        $pending_stmt->execute([$visit_id, $this->branch_id]);
        $pending_orders = (int)$pending_stmt->fetchColumn();

        if ($pending_orders === 0) {
            $visit_stmt = $this->pdo->prepare("
                UPDATE patient_visits
                SET status = 'lab_ready',
                    updated_at = NOW()
                WHERE id = ?
                  AND deleted_at IS NULL
            ");
            $visit_stmt->execute([$visit_id]);
        } else {
            $visit_stmt = $this->pdo->prepare("
                UPDATE patient_visits
                SET status = 'awaiting_lab',
                    updated_at = NOW()
                WHERE id = ?
                  AND deleted_at IS NULL
                  AND status NOT IN ('completed', 'cancelled', 'no_show')
            ");
            $visit_stmt->execute([$visit_id]);
        }
    }

    /**
     * FIXED: Only updates visit to 'completed' when all orders are verified
     */
    private function updateVisitAfterVerification($visit_id) {
        $order_status = $this->getOrderStatusForVisit($visit_id);
        
        if ($order_status !== 'completed') {
            return false;
        }

        $visit_stmt = $this->pdo->prepare("
            UPDATE patient_visits
            SET status = 'completed',
                completed_at = NOW(),
                updated_at = NOW()
            WHERE id = ?
              AND deleted_at IS NULL
        ");
        $visit_stmt->execute([$visit_id]);
        return true;
    }

    private function buildPanelMeta(array $row, array $parameters) {
        $total = count($parameters);
        $entered = 0;
        $verified = 0;
        $critical = 0;
        $abnormal = 0;

        foreach ($parameters as $param) {
            $has_value = $param['numerical_value'] !== null
                || ($param['textual_value'] !== null && trim((string)$param['textual_value']) !== '');
            if ($has_value) {
                $entered++;
            }
            if (!empty($param['verified_at'])) {
                $verified++;
            }
            if (!empty($param['is_critical'])) {
                $critical++;
            } elseif (!empty($param['is_abnormal'])) {
                $abnormal++;
            }
        }

        $order_status = $row['order_status'] ?? '';
        $ready_for_verification = $total > 0
            && $entered === $total
            && $verified < $total
            && in_array($order_status, ['in_progress', 'collected'], true);

        return [
            'total_parameters' => $total,
            'entered_parameters' => $entered,
            'verified_parameters' => $verified,
            'critical_count' => $critical,
            'abnormal_count' => $abnormal,
            'is_complete' => $total > 0 && $entered === $total,
            'is_verified' => $total > 0 && $verified === $total,
            'ready_for_verification' => $ready_for_verification,
            'completion_percent' => $total > 0 ? round(($entered / $total) * 100) : 0
        ];
    }
    
    // ============================================================
    // SECTION 1: PENDING - SPECIMEN COLLECTION
    // ============================================================

    public function getEncounterOrdersGroupedBySpecimen($visit_id) {
        $sql = "
            SELECT 
                lo.id AS order_id,
                lo.order_number,
                lo.order_date,
                lo.priority,
                lo.status,
                lo.specimen_type,
                lo.clinical_indication,
                COALESCE(ltc.test_name, ltm.test_name, 'Unknown Test') AS test_name,
                COALESCE(ltc.test_code, ltm.test_code, 'N/A') AS test_code,
                COALESCE(ltc.sample_type, ltm.sample_type, lo.specimen_type, 'blood') AS required_specimen,
                COALESCE(ltc.sample_volume_ml, ltm.sample_volume_ml, NULL) AS sample_volume,
                COALESCE(ltc.container_type, 'vacutainer') AS container_type,
                CONCAT(d.first_name, ' ', d.last_name) AS doctor_name,
                (
                    SELECT st.barcode
                    FROM specimen_tracking st
                    WHERE st.order_id = lo.id
                      AND st.deleted_at IS NULL
                    ORDER BY st.collected_at DESC
                    LIMIT 1
                ) AS existing_barcode,
                (
                    SELECT st.status
                    FROM specimen_tracking st
                    WHERE st.order_id = lo.id
                      AND st.deleted_at IS NULL
                    ORDER BY st.collected_at DESC
                    LIMIT 1
                ) AS specimen_status
            FROM lab_orders lo
            LEFT JOIN lab_tests_catalog ltc ON lo.test_catalog_id = ltc.id AND ltc.deleted_at IS NULL
            LEFT JOIN lab_tests_master ltm ON lo.test_id = ltm.id AND ltm.deleted_at IS NULL
            INNER JOIN users d ON lo.doctor_id = d.id AND d.deleted_at IS NULL
            WHERE lo.visit_id = :visit_id
              AND lo.branch_id = :branch_id
              AND lo.status IN ('pending', 'pending_payment', 'approved')
              AND lo.deleted_at IS NULL
            ORDER BY lo.specimen_type, lo.priority = 'stat' DESC, lo.priority = 'urgent' DESC, lo.order_date ASC
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':visit_id' => $visit_id,
            ':branch_id' => $this->branch_id
        ]);
        $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $grouped = [];
        foreach ($orders as $order) {
            $specimen_type = $this->normalizeSpecimenType(
                $order['specimen_type'] ?: $order['required_specimen']
            );

            if (!isset($grouped[$specimen_type])) {
                $grouped[$specimen_type] = [
                    'specimen_type' => $specimen_type,
                    'label' => $this->specimen_config[$specimen_type]['label'] ?? ucfirst($specimen_type) . ' Collection',
                    'icon' => $this->specimen_config[$specimen_type]['icon'] ?? 'fa-vial',
                    'color' => $this->specimen_config[$specimen_type]['color'] ?? '#6b7280',
                    'orders' => [],
                    'config' => $this->specimen_config[$specimen_type] ?? $this->specimen_config['blood'],
                    'order_count' => 0,
                    'has_collected' => false
                ];
            }

            $grouped[$specimen_type]['orders'][] = $order;
            $grouped[$specimen_type]['order_count']++;
            if (!empty($order['existing_barcode']) && $order['specimen_status'] === 'received') {
                $grouped[$specimen_type]['has_collected'] = true;
            }
        }

        return $grouped;
    }
    
    public function getEncounterInfo($visit_id) {
        $sql = "
            SELECT 
                v.id AS visit_id,
                v.visit_number,
                v.visit_date,
                p.id AS patient_id,
                p.mr_number,
                CONCAT(p.first_name, ' ', COALESCE(p.middle_name,''), ' ', p.last_name) AS patient_name,
                p.date_of_birth,
                p.gender,
                p.phone,
                p.email
            FROM patient_visits v
            INNER JOIN patients p ON v.patient_id = p.id AND p.deleted_at IS NULL
            WHERE v.id = :visit_id AND v.deleted_at IS NULL
        ";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':visit_id' => $visit_id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * FIXED: Properly inserts into specimen_tracking with both order_id and lab_order_id
     * FIXED: Uses 'collected' status which exists in database
     */
    public function bulkCollectSpecimens($visit_id, $collection_data, $notes = '') {
        try {
            $this->pdo->beginTransaction();

            $collected_count = 0;
            $barcodes = [];
            $errors = [];
            $collected_order_ids = [];

            if (!is_array($collection_data) || empty($collection_data)) {
                throw new Exception('No collection data provided');
            }

            foreach ($collection_data as $raw_specimen_type => $specimen_payload) {
                $specimen_type = $this->normalizeSpecimenType($raw_specimen_type);
                $payload = $this->normalizeCollectionPayload($specimen_payload);
                $order_ids = $payload['order_ids'];
                $fields = $payload['fields'];

                if (empty($order_ids)) {
                    $errors[] = "No orders selected for {$specimen_type} specimen";
                    continue;
                }

                $placeholders = implode(',', array_fill(0, count($order_ids), '?'));
                $validate_sql = "
                    SELECT lo.id, lo.order_number, lo.patient_id, lo.visit_id, lo.specimen_type, lo.status
                    FROM lab_orders lo
                    WHERE lo.id IN ($placeholders)
                      AND lo.visit_id = ?
                      AND lo.branch_id = ?
                      AND lo.deleted_at IS NULL
                ";
                $validate_stmt = $this->pdo->prepare($validate_sql);
                $validate_params = array_merge($order_ids, [$visit_id, $this->branch_id]);
                $validate_stmt->execute($validate_params);
                $validated_orders = $validate_stmt->fetchAll(PDO::FETCH_ASSOC);

                if (count($validated_orders) !== count($order_ids)) {
                    $errors[] = "One or more {$specimen_type} orders are invalid for this encounter";
                    continue;
                }

                // Check if any order already has a specimen collected
                $has_collected = false;
                foreach ($validated_orders as $validated_order) {
                    if (!in_array($validated_order['status'], self::STATUS_PENDING, true)) {
                        $errors[] = "Order #{$validated_order['order_number']} is not pending collection";
                        continue 2;
                    }
                    
                    // Check if specimen already exists for this order
                    $check_specimen = $this->pdo->prepare("
                        SELECT id FROM specimen_tracking 
                        WHERE order_id = ? AND deleted_at IS NULL
                    ");
                    $check_specimen->execute([$validated_order['id']]);
                    if ($check_specimen->fetch()) {
                        $has_collected = true;
                    }
                }

                // Generate a single barcode for this specimen type collection
                $barcode = $this->generateSpecimenBarcode();
                $primary_order_id = (int)$validated_orders[0]['id'];
                $specimen_notes = trim($fields['notes'] ?? '') ?: $notes;

                $optional_fields = [
                    'collection_site' => $fields['collection_site'] ?? null,
                    'collection_method' => $fields['collection_method'] ?? null,
                    'tube_type' => $fields['tube_type'] ?? null,
                    'container_type' => $fields['container_type'] ?? null,
                    'specimen_volume' => isset($fields['volume_ml']) && $fields['volume_ml'] !== ''
                        ? (string)$fields['volume_ml']
                        : null,
                    'preservative' => $fields['preservative'] ?? null,
                    'swab_type' => $fields['swab_type'] ?? null,
                    'transport_media' => $fields['transport_media'] ?? null,
                    'clot_time' => isset($fields['clot_time']) && $fields['clot_time'] !== ''
                        ? (int)$fields['clot_time']
                        : null,
                    'storage_location' => $fields['storage_location'] ?? null
                ];

                // FIXED: Include both order_id and lab_order_id
                $columns = ['order_id', 'lab_order_id', 'barcode', 'specimen_type', 
                           'collected_by_user_id', 'collected_at', 'status', 'notes', 
                           'created_at', 'updated_at'];
                $placeholders_insert = ['?', '?', '?', '?', '?', 'NOW()', '?', '?', 'NOW()', 'NOW()'];
                $values = [
                    $primary_order_id,
                    $primary_order_id,  // lab_order_id same as order_id
                    $barcode,
                    $specimen_type,
                    $this->user_id,
                    'received',
                    $specimen_notes
                ];

                foreach ($optional_fields as $col => $val) {
                    if ($val !== null && $val !== '') {
                        $columns[] = $col;
                        $placeholders_insert[] = '?';
                        $values[] = $val;
                    }
                }

                // Insert specimen tracking record
                $sql = "INSERT INTO specimen_tracking (" . implode(', ', $columns) . ")
                        VALUES (" . implode(', ', $placeholders_insert) . ")";
                $specimen_stmt = $this->pdo->prepare($sql);
                $specimen_stmt->execute($values);
                $specimen_id = $this->pdo->lastInsertId();

                // FIXED: Use 'collected' status which exists in database ENUM
                $update_stmt = $this->pdo->prepare("
                    UPDATE lab_orders
                    SET status = 'collected',
                        specimen_type = ?,
                        specimen_collected_at = NOW(),
                        specimen_collected_by_user_id = ?,
                        barcode = ?,
                        notes = CONCAT(COALESCE(notes, ''), ?),
                        updated_at = NOW()
                    WHERE id = ?
                      AND deleted_at IS NULL
                ");

                $note_suffix = "\n[Collected: " . date('Y-m-d H:i:s') . "] Specimen: {$specimen_type}; Barcode: {$barcode}";
                if ($specimen_notes) {
                    $note_suffix .= " Notes: {$specimen_notes}";
                }

                $order_ids_collected = [];
                foreach ($validated_orders as $validated_order) {
                    $order_id = (int)$validated_order['id'];
                    $update_stmt->execute([
                        $specimen_type,
                        $this->user_id,
                        $barcode,
                        $note_suffix,
                        $order_id
                    ]);
                    $collected_count++;
                    $collected_order_ids[] = $order_id;
                    $order_ids_collected[] = $order_id;
                }

                $barcodes[] = [
                    'specimen_id' => $specimen_id,
                    'specimen_type' => $specimen_type,
                    'barcode' => $barcode,
                    'primary_order_id' => $primary_order_id,
                    'order_ids' => $order_ids_collected,
                    'order_numbers' => array_column($validated_orders, 'order_number')
                ];
            }

            if (!empty($errors)) {
                throw new Exception(implode('; ', $errors));
            }

            if ($collected_count > 0) {
                $this->updateVisitAfterCollection($visit_id);
            }

            $this->logger->logBulk('BULK_COLLECT', 'specimen_tracking', [
                'visit_id' => $visit_id,
                'order_ids' => $collected_order_ids,
                'collected_count' => $collected_count,
                'barcodes' => $barcodes,
                'notes' => $notes
            ]);

            $this->pdo->commit();

            return [
                'success' => true,
                'message' => "Successfully collected {$collected_count} order(s) across " . count($barcodes) . " specimen type(s)",
                'collected_count' => $collected_count,
                'barcodes' => $barcodes,
                'errors' => $errors
            ];
        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        } catch (PDOException $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw new Exception('Database error: ' . $e->getMessage());
        }
    }
    
    // ============================================================
    // SECTION 2: COLLECTED - SECTIONED RESULT ENTRY
    // ============================================================
    
    /**
     * FIXED: Uses database-valid status values only
     */
    public function getEncounterOrdersWithPanels($visit_id) {
        $sql = "
            SELECT 
                lo.id AS order_id,
                lo.order_number,
                lo.order_date,
                lo.priority,
                lo.status AS order_status,
                lo.specimen_type,
                lo.specimen_collected_at,
                lo.barcode,
                lo.clinical_indication,
                ltc.id AS test_catalog_id,
                ltc.test_name,
                ltc.test_code,
                CONCAT(d.first_name, ' ', d.last_name) AS doctor_name,
                
                ltp.id AS parameter_id,
                ltp.parameter_code,
                ltp.parameter_name,
                ltp.unit_of_measure,
                ltp.min_normal,
                ltp.max_normal,
                ltp.critical_low,
                ltp.critical_high,
                ltp.reference_range_text,
                ltp.display_order,
                
                lrd.id AS result_id,
                lrd.numerical_value,
                lrd.textual_value,
                lrd.is_abnormal,
                lrd.is_critical,
                lrd.flag_type,
                lrd.technician_notes,
                lrd.status AS result_status,
                lrd.verified_at,
                lrd.verified_by_user_id
                
            FROM lab_orders lo
            INNER JOIN patients p ON lo.patient_id = p.id AND p.deleted_at IS NULL
            LEFT JOIN lab_tests_catalog ltc ON lo.test_catalog_id = ltc.id AND ltc.deleted_at IS NULL
            LEFT JOIN lab_test_parameters ltp ON ltp.test_catalog_id = ltc.id AND ltp.is_active = 1 AND ltp.deleted_at IS NULL
            LEFT JOIN lab_result_details lrd ON lrd.lab_order_id = lo.id AND lrd.parameter_id = ltp.id AND lrd.deleted_at IS NULL
            INNER JOIN users d ON lo.doctor_id = d.id AND d.deleted_at IS NULL
            WHERE lo.visit_id = :visit_id
                AND lo.branch_id = :branch_id
                AND lo.status IN ('collected', 'in_progress')
                AND lo.deleted_at IS NULL
            ORDER BY lo.specimen_type, lo.priority = 'stat' DESC, lo.priority = 'urgent' DESC, 
                     lo.order_date ASC, ltc.test_name ASC, ltp.display_order ASC
        ";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':visit_id' => $visit_id,
            ':branch_id' => $this->branch_id
        ]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Group by test panel
        $grouped = [];
        foreach ($rows as $row) {
            $panel_id = $row['test_catalog_id'] ?? 0;
            if (!isset($grouped[$panel_id])) {
                $grouped[$panel_id] = [
                    'panel_id' => $panel_id,
                    'panel_name' => $row['test_name'] ?? 'Unknown Panel',
                    'panel_code' => $row['test_code'] ?? 'N/A',
                    'order_id' => $row['order_id'],
                    'order_number' => $row['order_number'],
                    'order_date' => $row['order_date'],
                    'priority' => $row['priority'],
                    'order_status' => $row['order_status'],
                    'specimen_type' => $row['specimen_type'],
                    'specimen_collected_at' => $row['specimen_collected_at'],
                    'barcode' => $row['barcode'],
                    'doctor_name' => $row['doctor_name'],
                    'clinical_indication' => $row['clinical_indication'],
                    'parameters' => []
                ];
            }
            if ($row['parameter_id']) {
                $grouped[$panel_id]['parameters'][] = [
                    'parameter_id' => $row['parameter_id'],
                    'parameter_code' => $row['parameter_code'],
                    'parameter_name' => $row['parameter_name'],
                    'unit_of_measure' => $row['unit_of_measure'],
                    'min_normal' => $row['min_normal'],
                    'max_normal' => $row['max_normal'],
                    'critical_low' => $row['critical_low'],
                    'critical_high' => $row['critical_high'],
                    'reference_range_text' => $row['reference_range_text'],
                    'display_order' => $row['display_order'],
                    'result_id' => $row['result_id'],
                    'numerical_value' => $row['numerical_value'],
                    'textual_value' => $row['textual_value'],
                    'is_abnormal' => $row['is_abnormal'],
                    'is_critical' => $row['is_critical'],
                    'flag_type' => $row['flag_type'],
                    'technician_notes' => $row['technician_notes'],
                    'result_status' => $row['result_status']
                ];
            }
        }
        
        return array_values($grouped);
    }
    
    /**
     * Reject or cancel a compromised specimen at the order level.
     * Schema has cancellation_reason (not rejection_reason); rejected_by is captured in audit_logs.
     */
    public function rejectSpecimen(int $orderId, string $reason, int $userId, bool $cancel = false) {
        $orderId = (int)$orderId;
        $userId = (int)$userId;
        $reason = trim((string)$reason);

        if ($orderId <= 0 || $reason === '') {
            throw new InvalidArgumentException('Order ID and rejection reason are required.');
        }

        try {
            $this->pdo->beginTransaction();

            $fetch = $this->pdo->prepare("
                SELECT id, status, collection_status, cancellation_reason, notes, visit_id, order_number
                FROM lab_orders
                WHERE id = ?
                  AND branch_id = ?
                  AND deleted_at IS NULL
                FOR UPDATE
            ");
            $fetch->execute([$orderId, $this->branch_id]);
            $order = $fetch->fetch(PDO::FETCH_ASSOC);

            if (!$order) {
                throw new RuntimeException("Lab order #{$orderId} not found.");
            }

            if (in_array($order['status'], ['completed', 'cancelled', 'rejected'], true)) {
                throw new RuntimeException("Order #{$order['order_number']} is already terminal ({$order['status']}).");
            }

            $newStatus = $cancel ? 'cancelled' : 'rejected';
            $reasonPrefix = $cancel ? 'Order cancelled' : 'Specimen rejected';
            $fullReason = "{$reasonPrefix}: {$reason}";

            $update = $this->pdo->prepare("
                UPDATE lab_orders
                SET status = ?,
                    collection_status = 'rejected',
                    cancellation_reason = ?,
                    notes = CONCAT(COALESCE(notes, ''), '\n[', UPPER(?), ': ', NOW(), '] By user #', ?, ' — ', ?),
                    updated_at = NOW()
                WHERE id = ?
                  AND deleted_at IS NULL
            ");
            $update->execute([
                $newStatus,
                $fullReason,
                $newStatus,
                $userId,
                $reason,
                $orderId
            ]);

            $items = $this->pdo->prepare("
                UPDATE lab_order_items
                SET status = 'cancelled',
                    updated_at = NOW()
                WHERE lab_order_id = ?
                  AND deleted_at IS NULL
            ");
            $items->execute([$orderId]);

            $results = $this->pdo->prepare("
                UPDATE lab_result_details
                SET status = 'rejected',
                    technician_notes = CONCAT(COALESCE(technician_notes, ''), '\n[SPECIMEN REJECTED: ', ?, ']'),
                    updated_at = NOW()
                WHERE lab_order_id = ?
                  AND deleted_at IS NULL
                  AND status IN ('draft', 'submitted')
            ");
            $results->execute([$reason, $orderId]);

            $this->logger->logSpecimenRejection(
                $orderId,
                [
                    'order_id' => $orderId,
                    'order_number' => $order['order_number'],
                    'status' => $order['status'],
                    'collection_status' => $order['collection_status'],
                    'cancellation_reason' => $order['cancellation_reason'],
                ],
                [
                    'order_id' => $orderId,
                    'order_number' => $order['order_number'],
                    'status' => $newStatus,
                    'collection_status' => 'rejected',
                    'cancellation_reason' => $fullReason,
                    'rejected_by' => $userId,
                    'reason' => $reason,
                ]
            );

            $this->pdo->commit();

            return [
                'success' => true,
                'message' => "Order #{$order['order_number']} marked as {$newStatus}.",
                'order_id' => $orderId,
                'new_status' => $newStatus,
                'visit_id' => (int)$order['visit_id'],
            ];

        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Save or amend lab results with per-parameter amendment detection and audit.
     * Compares existing vs incoming values; submitted/verified + changed => amended.
     */
    public function bulkSaveResults(array $results, int $userId, string $technicianNotes = '') {
        if (empty($results)) {
            throw new InvalidArgumentException('No results provided.');
        }

        try {
            $this->pdo->beginTransaction();

            $savedCount = 0;
            $amendedCount = 0;
            $criticalCount = 0;
            $abnormalCount = 0;
            $errors = [];
            $affectedOrderIds = [];
            $auditChanges = [];

            $paramStmt = $this->pdo->prepare("
                SELECT parameter_code, parameter_name, unit_of_measure,
                       min_normal, max_normal, critical_low, critical_high, reference_range_text
                FROM lab_test_parameters
                WHERE id = ? AND deleted_at IS NULL
            ");

            $upsertStmt = $this->pdo->prepare("
                INSERT INTO lab_result_details (
                    lab_order_id, parameter_id, numerical_value, textual_value,
                    result_unit, technician_notes, is_abnormal, is_critical,
                    flag_type, status, performed_by_user_id,
                    verified_by_user_id, verified_at,
                    amendment_reason, created_at, updated_at
                ) VALUES (
                    ?, ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, ?,
                    NULL, NULL,
                    ?, NOW(), NOW()
                )
                ON DUPLICATE KEY UPDATE
                    numerical_value = VALUES(numerical_value),
                    textual_value = VALUES(textual_value),
                    result_unit = VALUES(result_unit),
                    technician_notes = CASE
                        WHEN VALUES(technician_notes) <> '' THEN CONCAT(COALESCE(technician_notes, ''), VALUES(technician_notes))
                        ELSE technician_notes
                    END,
                    is_abnormal = VALUES(is_abnormal),
                    is_critical = VALUES(is_critical),
                    flag_type = VALUES(flag_type),
                    status = VALUES(status),
                    performed_by_user_id = VALUES(performed_by_user_id),
                    verified_by_user_id = CASE WHEN VALUES(status) = 'amended' THEN NULL ELSE verified_by_user_id END,
                    verified_at = CASE WHEN VALUES(status) = 'amended' THEN NULL ELSE verified_at END,
                    amendment_reason = CASE WHEN VALUES(status) = 'amended' THEN VALUES(amendment_reason) ELSE amendment_reason END,
                    updated_at = NOW()
            ");

            $idLookup = $this->pdo->prepare("
                SELECT id FROM lab_result_details
                WHERE lab_order_id = ? AND parameter_id = ? AND deleted_at IS NULL
            ");

            foreach ($results as $result) {
                $orderId = (int)($result['order_id'] ?? 0);
                $parameterId = (int)($result['parameter_id'] ?? 0);
                $numerical = isset($result['numerical_value']) && $result['numerical_value'] !== ''
                    ? (float)$result['numerical_value'] : null;
                $textual = trim((string)($result['textual_value'] ?? ''));

                if ($orderId <= 0 || $parameterId <= 0) {
                    $errors[] = 'Invalid order_id or parameter_id in payload.';
                    continue;
                }

                $paramStmt->execute([$parameterId]);
                $paramData = $paramStmt->fetch(PDO::FETCH_ASSOC);
                if (!$paramData) {
                    $errors[] = "Parameter #{$parameterId} not found.";
                    continue;
                }

                $flagResult = ['is_abnormal' => 0, 'is_critical' => 0, 'flag_type' => 'normal'];
                if ($numerical !== null) {
                    $flagResult = $this->flagValue($numerical, $paramData['parameter_code']);
                } elseif ($textual !== '') {
                    $flagResult = $this->flagValue($textual, $paramData['parameter_code']);
                }

                if ($flagResult['is_critical']) {
                    $criticalCount++;
                }
                if ($flagResult['is_abnormal']) {
                    $abnormalCount++;
                }

                $existing = $this->fetchExistingResultDetail($orderId, $parameterId);

                $oldValue = $existing
                    ? $this->formatResultValue(
                        $existing['numerical_value'] !== null ? (float)$existing['numerical_value'] : null,
                        $existing['textual_value']
                    )
                    : null;

                $newValue = $this->formatResultValue($numerical, $textual);
                $valueChanged = $existing !== null && $oldValue !== $newValue;

                $existingStatus = $existing['status'] ?? self::RESULT_STATUS_DRAFT;
                $newStatus = $existing
                    ? $this->resolveResultStatusOnSave($existingStatus, $valueChanged)
                    : self::RESULT_STATUS_SUBMITTED;

                if ($newStatus === self::RESULT_STATUS_AMENDED) {
                    $amendedCount++;
                }

                $amendmentReason = ($newStatus === self::RESULT_STATUS_AMENDED)
                    ? "Value changed from '{$oldValue}' to '{$newValue}'"
                    : null;

                $noteFragment = $technicianNotes !== '' ? "\n" . $technicianNotes : '';

                $upsertStmt->execute([
                    $orderId,
                    $parameterId,
                    $numerical,
                    $textual !== '' ? $textual : null,
                    $paramData['unit_of_measure'] ?? 'N/A',
                    $noteFragment,
                    $flagResult['is_abnormal'],
                    $flagResult['is_critical'],
                    $flagResult['flag_type'],
                    $newStatus,
                    $userId,
                    $amendmentReason,
                ]);

                $detailId = $existing['id'] ?? (int)$this->pdo->lastInsertId();
                if ($detailId <= 0) {
                    $idLookup->execute([$orderId, $parameterId]);
                    $detailId = (int)$idLookup->fetchColumn();
                }

                // Granular audit: only log rows that were inserted or materially changed
                $statusChanged = !$existing || $existingStatus !== $newStatus;
                if (!$existing || $valueChanged || $statusChanged) {
                    $auditChanges[] = [
                        'detail_id' => $detailId,
                        'action' => $newStatus === self::RESULT_STATUS_AMENDED ? 'AMEND' : 'UPDATE',
                        'old' => [
                            'parameter_id' => $parameterId,
                            'value' => $oldValue,
                            'status' => $existing ? $existingStatus : null,
                        ],
                        'new' => [
                            'parameter_id' => $parameterId,
                            'value' => $newValue,
                            'status' => $newStatus,
                        ],
                    ];
                }

                if ($flagResult['is_critical']) {
                    $this->logger->log('CRITICAL_RESULT', 'lab_result_details', $detailId, [
                        'order_id' => $orderId,
                        'parameter_id' => $parameterId,
                        'parameter' => $paramData['parameter_name'],
                        'value' => $newValue,
                        'flag_type' => $flagResult['flag_type'],
                    ]);
                }

                $savedCount++;
                if (!in_array($orderId, $affectedOrderIds, true)) {
                    $affectedOrderIds[] = $orderId;
                }
            }

            foreach ($affectedOrderIds as $orderId) {
                $this->syncOrderStatusAfterResults($orderId);
            }

            $this->pdo->commit();

            // Audit after commit so rolled-back mutations never leave orphan log rows
            $this->logger->logResultDetailChanges($auditChanges);

            $message = "Successfully saved {$savedCount} result(s)";
            if ($amendedCount > 0) {
                $message .= " ({$amendedCount} amended)";
            }
            if ($criticalCount > 0) {
                $message .= " ⚠️ {$criticalCount} critical value(s) detected!";
            }
            if ($abnormalCount > 0) {
                $message .= " 📊 {$abnormalCount} abnormal value(s) detected.";
            }

            return [
                'success' => true,
                'message' => $message,
                'saved_count' => $savedCount,
                'amended_count' => $amendedCount,
                'critical_count' => $criticalCount,
                'abnormal_count' => $abnormalCount,
                'errors' => $errors,
            ];

        } catch (PDOException $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw new Exception('Database error: ' . $e->getMessage());
        }
    }
    
    // ============================================================
    // SECTION 3: COMPLETE - UNIFIED VERIFICATION
    // ============================================================
    
    /**
     * FIXED: Uses database-valid status values only
     */
    public function getVerificationData($visit_id) {
        $sql = "
            SELECT 
                lo.id AS order_id,
                lo.order_number,
                lo.order_date,
                lo.priority,
                lo.status AS order_status,
                lo.specimen_type,
                lo.barcode,
                ltc.id AS test_catalog_id,
                ltc.test_name,
                ltc.test_code,
                CONCAT(d.first_name, ' ', d.last_name) AS doctor_name,
                
                ltp.id AS parameter_id,
                ltp.parameter_code,
                ltp.parameter_name,
                ltp.unit_of_measure,
                ltp.min_normal,
                ltp.max_normal,
                ltp.critical_low,
                ltp.critical_high,
                ltp.reference_range_text,
                ltp.display_order,
                
                lrd.id AS result_id,
                lrd.numerical_value,
                lrd.textual_value,
                lrd.is_abnormal,
                lrd.is_critical,
                lrd.flag_type,
                lrd.technician_notes,
                lrd.status AS result_status,
                lrd.performed_by_user_id,
                CONCAT(performer.first_name, ' ', performer.last_name) AS performed_by,
                lrd.verified_at,
                lrd.verified_by_user_id,
                CONCAT(verifier.first_name, ' ', verifier.last_name) AS verified_by
                
            FROM lab_orders lo
            INNER JOIN patients p ON lo.patient_id = p.id AND p.deleted_at IS NULL
            LEFT JOIN lab_tests_catalog ltc ON lo.test_catalog_id = ltc.id AND ltc.deleted_at IS NULL
            LEFT JOIN lab_test_parameters ltp ON ltp.test_catalog_id = ltc.id AND ltp.is_active = 1 AND ltp.deleted_at IS NULL
            LEFT JOIN lab_result_details lrd ON lrd.lab_order_id = lo.id AND lrd.parameter_id = ltp.id AND lrd.deleted_at IS NULL
            LEFT JOIN users performer ON lrd.performed_by_user_id = performer.id
            LEFT JOIN users verifier ON lrd.verified_by_user_id = verifier.id
            INNER JOIN users d ON lo.doctor_id = d.id AND d.deleted_at IS NULL
            WHERE lo.visit_id = :visit_id
                AND lo.branch_id = :branch_id
                AND lo.status IN ('collected', 'in_progress', 'completed')
                AND lo.deleted_at IS NULL
            ORDER BY lo.specimen_type, lo.priority = 'stat' DESC, lo.priority = 'urgent' DESC, 
                     lo.order_date ASC, ltc.test_name ASC, ltp.display_order ASC
        ";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':visit_id' => $visit_id,
            ':branch_id' => $this->branch_id
        ]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Group by test panel
        $grouped = [];
        foreach ($rows as $row) {
            $panel_id = $row['test_catalog_id'] ?? 0;
            if (!isset($grouped[$panel_id])) {
                $grouped[$panel_id] = [
                    'panel_id' => $panel_id,
                    'panel_name' => $row['test_name'] ?? 'Unknown Panel',
                    'panel_code' => $row['test_code'] ?? 'N/A',
                    'order_id' => $row['order_id'],
                    'order_number' => $row['order_number'],
                    'order_date' => $row['order_date'],
                    'priority' => $row['priority'],
                    'order_status' => $row['order_status'],
                    'specimen_type' => $row['specimen_type'],
                    'barcode' => $row['barcode'],
                    'doctor_name' => $row['doctor_name'],
                    'parameters' => []
                ];
            }
            if ($row['parameter_id']) {
                $grouped[$panel_id]['parameters'][] = [
                    'parameter_id' => $row['parameter_id'],
                    'parameter_code' => $row['parameter_code'],
                    'parameter_name' => $row['parameter_name'],
                    'unit_of_measure' => $row['unit_of_measure'],
                    'min_normal' => $row['min_normal'],
                    'max_normal' => $row['max_normal'],
                    'critical_low' => $row['critical_low'],
                    'critical_high' => $row['critical_high'],
                    'reference_range_text' => $row['reference_range_text'],
                    'display_order' => $row['display_order'],
                    'result_id' => $row['result_id'],
                    'numerical_value' => $row['numerical_value'],
                    'textual_value' => $row['textual_value'],
                    'is_abnormal' => $row['is_abnormal'],
                    'is_critical' => $row['is_critical'],
                    'flag_type' => $row['flag_type'],
                    'technician_notes' => $row['technician_notes'],
                    'result_status' => $row['result_status'],
                    'performed_by' => $row['performed_by'],
                    'verified_at' => $row['verified_at'],
                    'verified_by' => $row['verified_by']
                ];
            }
        }
        
        // Calculate summary statistics
        $summary = [
            'total_orders' => count($grouped),
            'total_parameters' => 0,
            'entered_parameters' => 0,
            'critical_count' => 0,
            'abnormal_count' => 0,
            'verified_count' => 0
        ];
        
        foreach ($grouped as $panel) {
            foreach ($panel['parameters'] as $param) {
                $summary['total_parameters']++;
                if ($param['numerical_value'] !== null || $param['textual_value']) {
                    $summary['entered_parameters']++;
                }
                if ($param['is_critical']) {
                    $summary['critical_count']++;
                }
                if ($param['is_abnormal']) {
                    $summary['abnormal_count']++;
                }
                if ($param['verified_at']) {
                    $summary['verified_count']++;
                }
            }
        }
        
        return [
            'panels' => array_values($grouped),
            'summary' => $summary
        ];
    }
    
    /**
     * Verify selected lab_result_details rows (partial panel release).
     * Accepts flat detail ID list or structured ['detail_id' => X, 'parameter_id' => Y] items.
     */
    public function bulkVerifyResults(array $detailIds, int $userId, string $verifyNotes = '') {
        if (empty($detailIds)) {
            throw new InvalidArgumentException('No result detail IDs provided.');
        }

        $normalizedIds = [];
        foreach ($detailIds as $item) {
            if (is_array($item)) {
                $normalizedIds[] = (int)($item['detail_id'] ?? 0);
            } else {
                $normalizedIds[] = (int)$item;
            }
        }
        $normalizedIds = array_values(array_filter(array_unique($normalizedIds)));

        if (empty($normalizedIds)) {
            throw new InvalidArgumentException('No valid detail IDs provided.');
        }

        try {
            $this->pdo->beginTransaction();

            $placeholders = implode(',', array_fill(0, count($normalizedIds), '?'));

            $fetchSql = "
                SELECT
                    lrd.id AS detail_id,
                    lrd.lab_order_id,
                    lrd.parameter_id,
                    lrd.numerical_value,
                    lrd.textual_value,
                    lrd.status,
                    lrd.is_critical,
                    lrd.verified_at,
                    lo.visit_id,
                    lo.doctor_id,
                    lo.order_number,
                    lo.branch_id,
                    ltp.parameter_name,
                    p.first_name,
                    p.last_name,
                    p.mr_number
                FROM lab_result_details lrd
                INNER JOIN lab_orders lo ON lrd.lab_order_id = lo.id AND lo.deleted_at IS NULL
                INNER JOIN lab_test_parameters ltp ON lrd.parameter_id = ltp.id AND ltp.deleted_at IS NULL
                INNER JOIN patients p ON lo.patient_id = p.id AND p.deleted_at IS NULL
                WHERE lrd.id IN ({$placeholders})
                  AND lrd.deleted_at IS NULL
                  AND lo.branch_id = ?
                  AND lrd.status IN ('submitted', 'amended')
                  AND (lrd.numerical_value IS NOT NULL OR (lrd.textual_value IS NOT NULL AND TRIM(lrd.textual_value) <> ''))
            ";
            $fetchParams = array_merge($normalizedIds, [$this->branch_id]);
            $fetchStmt = $this->pdo->prepare($fetchSql);
            $fetchStmt->execute($fetchParams);
            $rows = $fetchStmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($rows)) {
                throw new Exception('No verifiable results found for the selected parameters.');
            }

            // Batch UPDATE: only the selected parameter rows move to verified
            $verifiableIds = array_map(static fn($r) => (int)$r['detail_id'], $rows);
            $verifyPlaceholders = implode(',', array_fill(0, count($verifiableIds), '?'));
            $batchVerifySql = "
                UPDATE lab_result_details
                SET status = 'verified',
                    verified_by_user_id = ?,
                    verified_at = NOW(),
                    updated_at = NOW()
                WHERE id IN ({$verifyPlaceholders})
                  AND deleted_at IS NULL
                  AND status IN ('submitted', 'amended')
            ";
            $batchVerifyStmt = $this->pdo->prepare($batchVerifySql);
            $batchVerifyStmt->execute(array_merge([$userId], $verifiableIds));

            $auditChanges = [];
            $orderIds = [];
            $visitId = null;
            $criticalAlerts = [];
            $doctorIds = [];

            foreach ($rows as $row) {
                $detailId = (int)$row['detail_id'];

                $value = $this->formatResultValue(
                    $row['numerical_value'] !== null ? (float)$row['numerical_value'] : null,
                    $row['textual_value']
                );

                $auditChanges[] = [
                    'detail_id' => $detailId,
                    'action' => 'VERIFY',
                    'old' => [
                        'parameter_id' => (int)$row['parameter_id'],
                        'value' => $value,
                        'status' => $row['status'],
                    ],
                    'new' => [
                        'parameter_id' => (int)$row['parameter_id'],
                        'value' => $value,
                        'status' => 'verified',
                        'verified_by' => $userId,
                    ],
                ];

                $orderIds[(int)$row['lab_order_id']] = true;
                $visitId = (int)$row['visit_id'];
                $doctorIds[(int)$row['doctor_id']] = true;

                if ((int)$row['is_critical'] === 1) {
                    $criticalAlerts[] = $row;
                }
            }

            foreach (array_keys($orderIds) as $orderId) {
                $completion = $this->getOrderVerificationCompletion($orderId);
                $total = (int)($completion['total_params'] ?? 0);
                $verified = (int)($completion['verified_params'] ?? 0);

                if ($total > 0 && $verified >= $total) {
                    $orderUpdate = $this->pdo->prepare("
                        UPDATE lab_orders
                        SET status = 'completed',
                            completed_at = NOW(),
                            updated_at = NOW()
                        WHERE id = ?
                          AND deleted_at IS NULL
                    ");
                    $orderUpdate->execute([$orderId]);
                }
            }

            if ($visitId) {
                $this->updateVisitAfterVerification($visitId);
            }

            foreach ($criticalAlerts as $result) {
                $notify = $this->pdo->prepare("
                    INSERT INTO notifications_tracker
                    (branch_id, user_id, title, message, notification_type, created_at)
                    VALUES (?, ?, ?, ?, 'lab_result', NOW())
                ");
                $notify->execute([
                    $this->branch_id,
                    $result['doctor_id'],
                    '⚠️ CRITICAL Lab Result Alert',
                    sprintf(
                        "⚠️ CRITICAL RESULT: Patient %s %s (MRN: %s) - %s: %s\nVerification Notes: %s",
                        $result['first_name'],
                        $result['last_name'],
                        $result['mr_number'],
                        $result['parameter_name'],
                        $this->formatResultValue(
                            $result['numerical_value'] !== null ? (float)$result['numerical_value'] : null,
                            $result['textual_value']
                        ),
                        $verifyNotes ?: 'None'
                    ),
                ]);
            }

            if (!empty($rows)) {
                $patientName = $rows[0]['first_name'] . ' ' . $rows[0]['last_name'];
                $patientMrn = $rows[0]['mr_number'];
                $verifiedCount = count($auditChanges);

                foreach (array_keys($doctorIds) as $doctorId) {
                    $notify = $this->pdo->prepare("
                        INSERT INTO notifications_tracker
                        (branch_id, user_id, title, message, notification_type, created_at)
                        VALUES (?, ?, ?, ?, 'lab_result', NOW())
                    ");
                    $notify->execute([
                        $this->branch_id,
                        $doctorId,
                        'Lab Results Verified',
                        sprintf(
                            "✅ Lab Results Verified: Patient %s (MRN: %s)\n%d parameter(s) verified and released.\nVerification Notes: %s",
                            $patientName,
                            $patientMrn,
                            $verifiedCount,
                            $verifyNotes ?: 'None'
                        ),
                    ]);
                }
            }

            $this->pdo->commit();

            $this->logger->logResultDetailChanges($auditChanges);

            $criticalCount = count($criticalAlerts);
            $message = 'Successfully verified ' . count($auditChanges) . ' parameter(s)';
            if ($criticalCount > 0) {
                $message .= " ⚠️ {$criticalCount} critical alert(s) sent to doctor(s)";
            }

            return [
                'success' => true,
                'message' => $message,
                'verified_count' => count($auditChanges),
                'critical_count' => $criticalCount,
                'doctor_notified' => count($doctorIds),
                'visit_id' => $visitId,
            ];

        } catch (PDOException $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw new Exception('Database error: ' . $e->getMessage());
        }
    }

    /**
     * Upsert partial entry values as status = 'draft' in lab_result_details (no PHP session storage).
     */
    public function saveDraftResults(int $visitId, array $draftData, int $userId) {
        if ($visitId <= 0) {
            throw new InvalidArgumentException('Invalid visit ID.');
        }

        try {
            $this->pdo->beginTransaction();

            $saved = 0;
            $skipped = 0;

            $orderCheck = $this->pdo->prepare("
                SELECT id FROM lab_orders
                WHERE id = ? AND visit_id = ? AND branch_id = ? AND deleted_at IS NULL
            ");

            $existingStmt = $this->pdo->prepare("
                SELECT id, status FROM lab_result_details
                WHERE lab_order_id = ? AND parameter_id = ? AND deleted_at IS NULL
            ");

            $draftUpsert = $this->pdo->prepare("
                INSERT INTO lab_result_details (
                    lab_order_id, parameter_id, numerical_value, textual_value,
                    result_unit, is_abnormal, is_critical, flag_type,
                    status, performed_by_user_id, created_at, updated_at
                ) VALUES (?, ?, ?, ?, 'N/A', 0, 0, 'normal', 'draft', ?, NOW(), NOW())
                ON DUPLICATE KEY UPDATE
                    numerical_value = CASE
                        WHEN lab_result_details.status = 'draft' THEN VALUES(numerical_value)
                        ELSE lab_result_details.numerical_value
                    END,
                    textual_value = CASE
                        WHEN lab_result_details.status = 'draft' THEN VALUES(textual_value)
                        ELSE lab_result_details.textual_value
                    END,
                    performed_by_user_id = CASE
                        WHEN lab_result_details.status = 'draft' THEN VALUES(performed_by_user_id)
                        ELSE lab_result_details.performed_by_user_id
                    END,
                    updated_at = CASE
                        WHEN lab_result_details.status = 'draft' THEN NOW()
                        ELSE lab_result_details.updated_at
                    END
            ");

            foreach ($draftData as $key => $values) {
                if (!preg_match('/^(\d+)_(\d+)$/', (string)$key, $m)) {
                    $skipped++;
                    continue;
                }

                $orderId = (int)$m[1];
                $parameterId = (int)$m[2];
                $numerical = isset($values['numerical_value']) && $values['numerical_value'] !== ''
                    ? (float)$values['numerical_value'] : null;
                $textual = trim((string)($values['textual_value'] ?? ''));

                if ($numerical === null && $textual === '') {
                    continue;
                }

                $orderCheck->execute([$orderId, $visitId, $this->branch_id]);
                if (!$orderCheck->fetchColumn()) {
                    $skipped++;
                    continue;
                }

                $existingStmt->execute([$orderId, $parameterId]);
                $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);

                if ($existing && $existing['status'] !== 'draft') {
                    $skipped++;
                    continue;
                }

                $draftUpsert->execute([
                    $orderId,
                    $parameterId,
                    $numerical,
                    $textual !== '' ? $textual : null,
                    $userId,
                ]);
                $saved++;
            }

            $this->pdo->commit();

            return [
                'success' => true,
                'message' => "Draft saved ({$saved} parameter(s)).",
                'saved' => $saved,
                'skipped' => $skipped,
            ];

        } catch (PDOException $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw new Exception('Database error: ' . $e->getMessage());
        }
    }

    /**
     * Load draft values for a visit to repopulate the result entry UI.
     */
    public function getDraftResults(int $visitId) {
        $stmt = $this->pdo->prepare("
            SELECT
                lrd.lab_order_id,
                lrd.parameter_id,
                lrd.numerical_value,
                lrd.textual_value,
                lrd.updated_at
            FROM lab_result_details lrd
            INNER JOIN lab_orders lo ON lrd.lab_order_id = lo.id AND lo.deleted_at IS NULL
            WHERE lo.visit_id = ?
              AND lo.branch_id = ?
              AND lrd.status = 'draft'
              AND lrd.deleted_at IS NULL
              AND (lrd.numerical_value IS NOT NULL OR (lrd.textual_value IS NOT NULL AND TRIM(lrd.textual_value) <> ''))
        ");
        $stmt->execute([$visitId, $this->branch_id]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $draft = [];
        $latestUpdate = null;

        foreach ($rows as $row) {
            $key = $row['lab_order_id'] . '_' . $row['parameter_id'];
            $draft[$key] = [
                'numerical_value' => $row['numerical_value'],
                'textual_value' => $row['textual_value'],
            ];
            if ($latestUpdate === null || $row['updated_at'] > $latestUpdate) {
                $latestUpdate = $row['updated_at'];
            }
        }

        return [
            'has_draft' => !empty($draft),
            'data' => $draft,
            'updated_at' => $latestUpdate,
        ];
    }

    /**
     * Resolve verifiable detail IDs for a visit (fallback when none selected).
     */
    public function getVerifiableDetailIds($visitId) {
        $stmt = $this->pdo->prepare("
            SELECT lrd.id
            FROM lab_result_details lrd
            INNER JOIN lab_orders lo ON lrd.lab_order_id = lo.id AND lo.deleted_at IS NULL
            WHERE lo.visit_id = ?
              AND lo.branch_id = ?
              AND lrd.deleted_at IS NULL
              AND lrd.status IN ('submitted', 'amended')
              AND lrd.verified_at IS NULL
              AND (lrd.numerical_value IS NOT NULL OR (lrd.textual_value IS NOT NULL AND TRIM(lrd.textual_value) <> ''))
        ");
        $stmt->execute([(int)$visitId, $this->branch_id]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
    
    // ============================================================
    // DASHBOARD QUERIES
    // ============================================================
    
    /**
     * FIXED: Uses database-valid status values only
     */
    public function getGroupedOrdersByStatus($status = 'pending', $search = '', $limit = 100) {
        $params = [':branch_id' => $this->branch_id];
        $where_conditions = [
            "lo.branch_id = :branch_id", 
            "lo.deleted_at IS NULL",
            "lo.visit_id IS NOT NULL"
        ];
        
        if (!empty($search)) {
            $where_conditions[] = "(
                p.mr_number LIKE :search OR 
                CONCAT(p.first_name, ' ', COALESCE(p.middle_name,''), ' ', p.last_name) LIKE :search OR 
                p.phone LIKE :search
            )";
            $params[':search'] = '%' . $search . '%';
        }
        
        switch ($status) {
            case 'pending':
                $where_conditions[] = "lo.status IN ('pending', 'pending_payment', 'approved')";
                $order_by = "MAX(CASE WHEN lo.priority = 'stat' THEN 1 WHEN lo.priority = 'urgent' THEN 2 ELSE 3 END), MIN(lo.order_date)";
                break;
            case 'collected':
                $where_conditions[] = "lo.status IN ('collected', 'in_progress')";
                $order_by = "MIN(lo.specimen_collected_at)";
                break;
            case 'completed':
                $where_conditions[] = "lo.status IN ('completed')";
                $order_by = "MAX(lo.completed_at) DESC";
                break;
            case 'critical':
                $where_conditions[] = "lo.status NOT IN ('cancelled', 'rejected')";
                $where_conditions[] = "EXISTS (
                    SELECT 1 FROM lab_result_details lrd_sub 
                    WHERE lrd_sub.lab_order_id = lo.id 
                    AND lrd_sub.is_critical = 1 
                    AND lrd_sub.deleted_at IS NULL
                )";
                $order_by = "MAX(lo.order_date) DESC";
                break;
            default:
                $where_conditions[] = "lo.status NOT IN ('cancelled', 'rejected')";
                $order_by = "MIN(lo.order_date)";
        }
        
        $where_sql = implode(" AND ", $where_conditions);
        
        $sql = "
            SELECT 
                p.id AS patient_id,
                p.mr_number,
                CONCAT(p.first_name, ' ', COALESCE(p.middle_name,''), ' ', p.last_name) AS patient_name,
                p.date_of_birth,
                p.gender,
                p.phone,
                p.email,
                
                lo.visit_id,
                v.visit_number,
                v.visit_date,
                
                COUNT(DISTINCT lo.id) AS order_count,
                GROUP_CONCAT(DISTINCT lo.id ORDER BY lo.id) AS order_ids,
                GROUP_CONCAT(DISTINCT lo.order_number ORDER BY lo.id) AS order_numbers,
                GROUP_CONCAT(DISTINCT CONCAT(lo.id, '|', lo.status) SEPARATOR '||') AS order_statuses,
                
                MIN(lo.order_date) AS first_order_date,
                MAX(lo.order_date) AS last_order_date,
                MIN(lo.specimen_collected_at) AS first_collected_at,
                MAX(lo.specimen_collected_at) AS last_collected_at,
                MIN(lo.results_ready_at) AS first_results_ready,
                MAX(lo.completed_at) AS last_completed_at,
                
                COUNT(DISTINCT CASE WHEN lo.priority = 'stat' THEN lo.id END) AS stat_count,
                COUNT(DISTINCT CASE WHEN lo.priority = 'urgent' THEN lo.id END) AS urgent_count,
                
                COUNT(DISTINCT CASE WHEN EXISTS (
                    SELECT 1 FROM lab_result_details lrd_sub 
                    WHERE lrd_sub.lab_order_id = lo.id 
                    AND lrd_sub.is_critical = 1 
                    AND lrd_sub.deleted_at IS NULL
                ) THEN lo.id END) AS critical_order_count,
                
                GROUP_CONCAT(DISTINCT CONCAT(
                    COALESCE(ltc.test_name, ltm.test_name, 'Unknown'),
                    ' (', 
                    IFNULL((SELECT COUNT(*) FROM lab_test_parameters ltp WHERE ltp.test_catalog_id = ltc.id AND ltp.deleted_at IS NULL), 0),
                    ' params)',
                    ' [', COALESCE(lo.specimen_type, ltc.sample_type, 'N/A'), ']'
                ) ORDER BY lo.id SEPARATOR '||') AS test_names_with_params,
                
                CASE 
                    WHEN SUM(IFNULL((SELECT COUNT(*) FROM lab_result_details lrd_sub 
                        WHERE lrd_sub.lab_order_id = lo.id 
                        AND lrd_sub.deleted_at IS NULL), 0)) = 0 THEN 'no_results'
                    WHEN SUM(IFNULL((SELECT COUNT(*) FROM lab_result_details lrd_sub 
                        WHERE lrd_sub.lab_order_id = lo.id 
                        AND (lrd_sub.numerical_value IS NOT NULL OR lrd_sub.textual_value IS NOT NULL)
                        AND lrd_sub.deleted_at IS NULL), 0)) = SUM(IFNULL((SELECT COUNT(*) FROM lab_result_details lrd_sub 
                        WHERE lrd_sub.lab_order_id = lo.id 
                        AND lrd_sub.deleted_at IS NULL), 0)) THEN 'all_entered'
                    ELSE 'partial'
                END AS result_entry_status,
                
                CASE 
                    WHEN SUM(CASE WHEN lo.priority = 'stat' THEN 1 ELSE 0 END) > 0 THEN 'stat'
                    WHEN SUM(CASE WHEN lo.priority = 'urgent' THEN 1 ELSE 0 END) > 0 THEN 'urgent'
                    ELSE 'routine'
                END AS overall_priority,
                
                CASE 
                    WHEN SUM(CASE WHEN lo.status = 'completed' THEN 1 ELSE 0 END) = COUNT(DISTINCT lo.id) THEN 'completed'
                    WHEN SUM(CASE WHEN lo.status IN ('collected', 'in_progress') THEN 1 ELSE 0 END) > 0 THEN 'collected'
                    WHEN SUM(CASE WHEN lo.status IN ('pending', 'pending_payment', 'approved') THEN 1 ELSE 0 END) > 0 THEN 'pending'
                    ELSE 'mixed'
                END AS overall_status,
                
                GROUP_CONCAT(DISTINCT lo.specimen_type ORDER BY lo.specimen_type) AS specimen_types
                
            FROM lab_orders lo
            INNER JOIN patients p ON lo.patient_id = p.id AND p.deleted_at IS NULL
            INNER JOIN patient_visits v ON lo.visit_id = v.id AND v.deleted_at IS NULL
            LEFT JOIN lab_tests_catalog ltc ON lo.test_catalog_id = ltc.id AND ltc.deleted_at IS NULL
            LEFT JOIN lab_tests_master ltm ON lo.test_id = ltm.id AND ltm.deleted_at IS NULL
            WHERE {$where_sql}
            GROUP BY lo.visit_id, p.id, p.mr_number, p.first_name, p.last_name, p.middle_name, 
                     p.date_of_birth, p.gender, p.phone, p.email, v.visit_number, v.visit_date
            ORDER BY {$order_by}
            LIMIT :limit
        ";
        
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    public function getCounts() {
        $counts = [];
        foreach (['pending', 'collected', 'completed', 'critical'] as $status) {
            $data = $this->getGroupedOrdersByStatus($status);
            $counts[$status] = count($data);
        }
        return $counts;
    }
    
    private function getOrderNumber($order_id) {
        $stmt = $this->pdo->prepare("SELECT order_number FROM lab_orders WHERE id = ?");
        $stmt->execute([$order_id]);
        return $stmt->fetchColumn();
    }
}

// ============================================================
// 4. HELPER FUNCTIONS
// ============================================================

function jsonResponse($success, $message, $data = null, $code = 200) {
    if (ob_get_length()) ob_clean();
    http_response_code($code);
    header('Content-Type: application/json');
    header('X-Content-Type-Options: nosniff');
    header('X-Request-ID: ' . ($_SERVER['HTTP_X_REQUEST_ID'] ?? uniqid('resp_', true)));
    $response = ['success' => $success, 'message' => $message];
    if ($data !== null) {
        $response = array_merge($response, $data);
    }
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

function escapeHtml($str) {
    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}

function formatDateTime($date, $format = 'Y-m-d H:i') {
    if (empty($date)) return 'N/A';
    try {
        return date($format, strtotime($date));
    } catch (Exception $e) {
        return $date;
    }
}

function getStatusBadge($status) {
    $badges = [
        'pending_payment' => 'warning',
        'pending' => 'secondary',
        'approved' => 'info',
        'collected' => 'info',
        'in_progress' => 'primary',
        'completed' => 'success',
        'cancelled' => 'danger',
        'rejected' => 'danger'
    ];
    $class = $badges[$status] ?? 'secondary';
    $display = str_replace('_', ' ', $status);
    return "<span class='badge bg-{$class}'>" . $display . "</span>";
}

function getPriorityBadge($priority) {
    $badges = ['routine' => 'info', 'urgent' => 'warning', 'stat' => 'danger'];
    $class = $badges[$priority] ?? 'secondary';
    return "<span class='badge bg-{$class}'>" . strtoupper($priority) . "</span>";
}

// ============================================================
// 5. AJAX HANDLER
// ============================================================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'lab_action') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(false, 'Invalid request method');
    }

    $action = $_POST['action'] ?? '';
    
    $write_actions = [
        'collect_sample', 'save_result', 'verify_results', 
        'fetch_test_parameters', 'fetch_order_details', 'fetch_orders',
        'acknowledge_alert', 'print_barcode', 'search_barcode',
        'fetch_stats', 'reject_order', 'fetch_grouped_orders',
        'get_encounter_orders', 'bulk_collect_specimens',
        'bulk_save_results', 'bulk_verify_results',
        'get_verification_summary', 'get_encounter_info',
        'get_encounter_panels', 'get_encounter_orders_with_params',
        'auto_save_draft', 'get_draft_status'
    ];
    
    if (in_array($action, $write_actions)) {
        if (!isset($_POST['csrf_token']) || !hash_equals($csrf_token, (string)$_POST['csrf_token'])) {
            jsonResponse(false, 'Security token mismatch');
        }
    }

    try {
        $labService = new LabService($pdo, $user_id, $branch_id);
        
        if ($action === 'fetch_grouped_orders') {
            $filter = $_POST['filter'] ?? 'pending';
            $search = trim($_POST['search'] ?? '');
            $limit = (int)($_POST['limit'] ?? 100);
            
            try {
                $grouped_orders = $labService->getGroupedOrdersByStatus($filter, $search, $limit);
                $counts = $labService->getCounts();
                
                jsonResponse(true, '', [
                    'orders' => $grouped_orders,
                    'counts' => $counts
                ]);
                
            } catch (Exception $e) {
                jsonResponse(false, $e->getMessage());
            }
        }
        
        // SECTION 1: Get encounter orders grouped by specimen type
        if ($action === 'get_encounter_orders') {
            $visit_id = (int)($_POST['visit_id'] ?? 0);
            if ($visit_id <= 0) jsonResponse(false, 'Invalid visit ID');
            
            try {
                $grouped = $labService->getEncounterOrdersGroupedBySpecimen($visit_id);
                $encounter = $labService->getEncounterInfo($visit_id);
                
                jsonResponse(true, '', [
                    'encounter' => $encounter,
                    'grouped_orders' => $grouped
                ]);
                
            } catch (Exception $e) {
                jsonResponse(false, $e->getMessage());
            }
        }
        
        // SECTION 1: Bulk collect specimens with partitioned data
        if ($action === 'bulk_collect_specimens') {
            $visit_id = (int)($_POST['visit_id'] ?? 0);
            $collection_data = json_decode($_POST['collection_data'] ?? '{}', true);
            $notes = trim($_POST['notes'] ?? '');
            
            if ($visit_id <= 0) jsonResponse(false, 'Invalid visit ID');
            if (empty($collection_data)) jsonResponse(false, 'No collection data provided');
            
            try {
                $result = $labService->bulkCollectSpecimens($visit_id, $collection_data, $notes);
                jsonResponse($result['success'], $result['message'], $result);
                
            } catch (Exception $e) {
                jsonResponse(false, $e->getMessage());
            }
        }
        
        // SECTION 2: Get encounter orders with test panels for result entry
        if ($action === 'get_encounter_panels') {
            $visit_id = (int)($_POST['visit_id'] ?? 0);
            if ($visit_id <= 0) jsonResponse(false, 'Invalid visit ID');
            
            try {
                $panels = $labService->getEncounterOrdersWithPanels($visit_id);
                $encounter = $labService->getEncounterInfo($visit_id);
                
                jsonResponse(true, '', [
                    'encounter' => $encounter,
                    'panels' => $panels
                ]);
                
            } catch (Exception $e) {
                jsonResponse(false, $e->getMessage());
            }
        }
        
        // SECTION 2: Bulk save results with panel data
        if ($action === 'bulk_save_results') {
            $visit_id = (int)($_POST['visit_id'] ?? 0);
            $results_data = json_decode($_POST['results_data'] ?? '[]', true);
            $technician_notes = trim($_POST['technician_notes'] ?? '');
            
            if ($visit_id <= 0) jsonResponse(false, 'Invalid visit ID');
            if (empty($results_data)) jsonResponse(false, 'No results data provided');
            
            try {
                $result = $labService->bulkSaveResults($results_data, $user_id, $technician_notes);
                jsonResponse($result['success'], $result['message'], $result);
                
            } catch (Exception $e) {
                jsonResponse(false, $e->getMessage());
            }
        }
        
        // SECTION 3: Get verification data with panels and summary
        if ($action === 'get_verification_summary') {
            $visit_id = (int)($_POST['visit_id'] ?? 0);
            if ($visit_id <= 0) jsonResponse(false, 'Invalid visit ID');
            
            try {
                $verification_data = $labService->getVerificationData($visit_id);
                $encounter = $labService->getEncounterInfo($visit_id);
                
                jsonResponse(true, '', [
                    'encounter' => $encounter,
                    'verification_data' => $verification_data
                ]);
                
            } catch (Exception $e) {
                jsonResponse(false, $e->getMessage());
            }
        }
        
        // SECTION 3: Bulk verify selected results (parameter-level)
        if ($action === 'bulk_verify_results') {
            $visit_id = (int)($_POST['visit_id'] ?? 0);
            $verify_notes = trim($_POST['verify_notes'] ?? '');
            $detail_ids = json_decode($_POST['detail_ids'] ?? '[]', true);

            if ($visit_id <= 0) jsonResponse(false, 'Invalid visit ID');

            try {
                if (empty($detail_ids)) {
                    $detail_ids = $labService->getVerifiableDetailIds($visit_id);
                }
                if (empty($detail_ids)) {
                    jsonResponse(false, 'No verifiable results selected.');
                }

                $result = $labService->bulkVerifyResults($detail_ids, $user_id, $verify_notes);
                jsonResponse($result['success'], $result['message'], $result);

            } catch (Exception $e) {
                jsonResponse(false, $e->getMessage());
            }
        }

        if ($action === 'reject_order') {
            $order_id = (int)($_POST['order_id'] ?? 0);
            $reason = trim($_POST['reason'] ?? $_POST['rejection_reason'] ?? '');
            $cancel = isset($_POST['cancel']) && (int)$_POST['cancel'] === 1;

            if ($order_id <= 0) jsonResponse(false, 'Invalid order ID');
            if ($reason === '') jsonResponse(false, 'Rejection reason is required');

            try {
                $result = $labService->rejectSpecimen($order_id, $reason, $user_id, $cancel);
                jsonResponse(true, $result['message'], $result);
            } catch (Exception $e) {
                jsonResponse(false, $e->getMessage());
            }
        }
        
        if ($action === 'get_encounter_info') {
            $visit_id = (int)($_POST['visit_id'] ?? 0);
            if ($visit_id <= 0) jsonResponse(false, 'Invalid visit ID');
            
            try {
                $encounter = $labService->getEncounterInfo($visit_id);
                jsonResponse(true, '', ['encounter' => $encounter]);
                
            } catch (Exception $e) {
                jsonResponse(false, $e->getMessage());
            }
        }
        
        if ($action === 'fetch_stats') {
            try {
                $counts = $labService->getCounts();
                
                $today_stmt = $pdo->prepare("
                    SELECT COUNT(*) FROM lab_orders 
                    WHERE branch_id = ? 
                    AND DATE(order_date) = CURDATE() 
                    AND deleted_at IS NULL
                ");
                $today_stmt->execute([$branch_id]);
                $today_orders = $today_stmt->fetchColumn();
                
                jsonResponse(true, '', [
                    'stats' => [
                        'today_orders' => $today_orders,
                        'pending_orders' => $counts['pending'] ?? 0,
                        'collected_orders' => $counts['collected'] ?? 0,
                        'completed_orders' => $counts['completed'] ?? 0,
                        'critical_alerts' => $counts['critical'] ?? 0
                    ]
                ]);
                
            } catch (Exception $e) {
                jsonResponse(false, $e->getMessage());
            }
        }
        
        if ($action === 'search_barcode') {
            $barcode = trim($_POST['barcode'] ?? '');
            if (empty($barcode)) jsonResponse(false, 'Please enter a barcode');
            
            $sql = "
                SELECT 
                    lo.id AS order_id,
                    lo.order_number,
                    lo.status,
                    lo.order_date,
                    lo.visit_id,
                    CONCAT(p.first_name, ' ', COALESCE(p.middle_name,''), ' ', p.last_name) AS patient_name,
                    p.id AS patient_id,
                    p.mr_number,
                    st.barcode,
                    st.specimen_type,
                    st.status AS specimen_status,
                    st.collected_at
                FROM specimen_tracking st
                INNER JOIN lab_orders lo ON st.order_id = lo.id AND lo.deleted_at IS NULL
                INNER JOIN patients p ON lo.patient_id = p.id AND p.deleted_at IS NULL
                WHERE st.barcode = ? AND st.deleted_at IS NULL
                LIMIT 1
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$barcode]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$result) {
                jsonResponse(false, 'No specimen found with this barcode');
            }
            
            jsonResponse(true, '', ['order' => $result]);
        }
        
        if ($action === 'acknowledge_alert') {
            $result_id = (int)($_POST['result_id'] ?? 0);
            $notes = trim($_POST['notes'] ?? '');
            
            if ($result_id <= 0) jsonResponse(false, 'Invalid result ID');
            
            try {
                $update = $pdo->prepare("
                    UPDATE lab_result_details 
                    SET reviewed_by_doctor_id = ?,
                        reviewed_at = NOW(),
                        technician_notes = CONCAT(
                            COALESCE(technician_notes, ''),
                            '\n[ACKNOWLEDGED: ', NOW(), '] By: ', ?, ' - ', ?
                        ),
                        updated_at = NOW()
                    WHERE id = ?
                ");
                $update->execute([$user_id, $user_full_name, $notes, $result_id]);
                
                jsonResponse(true, 'Alert acknowledged successfully');
            } catch (PDOException $e) {
                jsonResponse(false, 'Database error: ' . $e->getMessage());
            }
        }
        
        if ($action === 'auto_save_draft') {
            $visit_id = (int)($_POST['visit_id'] ?? 0);
            $draft_data = json_decode($_POST['draft_data'] ?? '{}', true);

            if ($visit_id <= 0) jsonResponse(false, 'Invalid visit ID');

            try {
                $result = $labService->saveDraftResults($visit_id, is_array($draft_data) ? $draft_data : [], $user_id);
                jsonResponse(true, $result['message'], $result);
            } catch (Exception $e) {
                jsonResponse(false, $e->getMessage());
            }
        }

        if ($action === 'get_draft_status') {
            $visit_id = (int)($_POST['visit_id'] ?? 0);

            if ($visit_id <= 0) jsonResponse(false, 'Invalid visit ID');

            try {
                $draft = $labService->getDraftResults($visit_id);

                jsonResponse(true, '', [
                    'has_draft' => $draft['has_draft'],
                    'draft' => [
                        'data' => $draft['data'],
                        'updated_at' => $draft['updated_at'],
                    ],
                ]);

            } catch (Exception $e) {
                jsonResponse(false, $e->getMessage());
            }
        }
        
        jsonResponse(false, 'Unsupported action: ' . $action);
        
    } catch (PDOException $e) {
        error_log('Lab AJAX error: ' . $e->getMessage());
        jsonResponse(false, 'Database error: ' . $e->getMessage());
    } catch (Exception $e) {
        error_log('Lab general error: ' . $e->getMessage());
        jsonResponse(false, 'Error: ' . $e->getMessage());
    }
    exit;
}

// ============================================================
// 6. INITIAL PAGE DATA
// ============================================================
$labService = new LabService($pdo, $user_id, $branch_id);
$initial_counts = $labService->getCounts();
$initial_data = $labService->getGroupedOrdersByStatus('pending', '', 50);

$today_stmt = $pdo->prepare("
    SELECT COUNT(*) FROM lab_orders 
    WHERE branch_id = ? 
    AND DATE(order_date) = CURDATE() 
    AND deleted_at IS NULL
");
$today_stmt->execute([$branch_id]);
$today_orders = $today_stmt->fetchColumn();

$stats = [
    'today_orders' => $today_orders,
    'pending_orders' => $initial_counts['pending'] ?? 0,
    'collected_orders' => $initial_counts['collected'] ?? 0,
    'completed_orders' => $initial_counts['completed'] ?? 0,
    'critical_alerts' => $initial_counts['critical'] ?? 0
];

// Calculate average turnaround time
$avg_tat_stmt = $pdo->prepare("
    SELECT AVG(TIMESTAMPDIFF(HOUR, order_date, completed_at)) as avg_hours
    FROM lab_orders 
    WHERE branch_id = ? 
    AND status = 'completed'
    AND completed_at IS NOT NULL
    AND deleted_at IS NULL
    AND order_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
");
$avg_tat_stmt->execute([$branch_id]);
$avg_tat = $avg_tat_stmt->fetchColumn();
$avg_tat = $avg_tat ? round($avg_tat, 1) : 'N/A';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?php echo $csrf_token; ?>">
    <title>Laboratory Dashboard - Patient-Centric Encounter Model v10.0</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
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
            --gray: #6b7280;
            --light-gray: #f3f4f6;
            --border: #e5e7eb;
            --sidebar-width: 260px;
            --transition-speed: 0.3s;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: #f0f2f5; overflow-x: hidden; }
        
        #wrapper { display: flex; width: 100%; min-height: 100vh; align-items: stretch; position: relative; }
        
        #sidebar-wrapper {
            min-height: 100vh;
            width: var(--sidebar-width);
            margin-left: calc(-1 * var(--sidebar-width));
            transition: margin var(--transition-speed) ease-in-out;
            position: fixed;
            top: 0;
            left: 0;
            z-index: 1050;
            background: #1a1a2e;
            overflow-y: auto;
            flex-shrink: 0;
            height: 100vh;
        }
        #sidebar-wrapper.active { margin-left: 0; }
        
        #page-content-wrapper {
            width: 100%;
            min-height: 100vh;
            transition: margin var(--transition-speed) ease-in-out, width var(--transition-speed) ease-in-out;
            margin-left: 0;
            padding: 0;
            flex: 1;
        }
        #page-content-wrapper.with-sidebar {
            margin-left: var(--sidebar-width);
            width: calc(100% - var(--sidebar-width));
        }
        
        .navbar-custom {
            position: sticky;
            top: 0;
            z-index: 1040;
            background: white;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            padding: 10px 20px;
            width: 100%;
        }
        .navbar-custom .navbar-brand { font-weight: 700; color: var(--dark); }
        
        .content-page { padding: 24px 32px; min-height: calc(100vh - 70px); width: 100%; }
        
        #mobileMenuToggle {
            display: none;
            background: none;
            border: none;
            font-size: 24px;
            color: var(--dark);
            padding: 4px 8px;
            cursor: pointer;
            border-radius: 8px;
            transition: background 0.2s;
        }
        #mobileMenuToggle:hover { background: var(--light-gray); }
        
        .sidebar-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.4);
            z-index: 1049;
            transition: opacity var(--transition-speed) ease;
        }
        .sidebar-overlay.active { display: block; }
        
        @media (max-width: 992px) {
            #mobileMenuToggle { display: inline-block; }
            #page-content-wrapper.with-sidebar { margin-left: 0; width: 100%; }
            #sidebar-wrapper { width: 280px; margin-left: -280px; }
            #sidebar-wrapper.active { margin-left: 0; }
        }
        
        @media (max-width: 768px) {
            .content-page { padding: 16px; }
            .stats-grid { grid-template-columns: repeat(2, 1fr) !important; gap: 12px; }
            .lab-container { grid-template-columns: 1fr !important; }
            .details-panel { position: static !important; max-height: none !important; }
            .details-panel .body { max-height: none !important; }
            .panel-header { flex-direction: column; align-items: stretch; }
            .tabs { flex-wrap: nowrap; overflow-x: auto; }
            .tab { padding: 8px 12px; font-size: 12px; }
        }
        
        @media (max-width: 480px) {
            .stats-grid { grid-template-columns: 1fr 1fr !important; gap: 8px; }
            .stat-card { padding: 12px 14px; }
            .stat-card .number { font-size: 18px; }
            .stat-card .label { font-size: 11px; }
        }
        
        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 16px 20px;
            border: 1px solid var(--border);
            transition: all 0.2s;
        }
        .stat-card:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.08); }
        .stat-card .icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            margin-bottom: 8px;
        }
        .stat-card .number { font-size: 24px; font-weight: 700; color: var(--dark); }
        .stat-card .label { font-size: 13px; color: var(--gray); }
        .stat-card.clickable { cursor: pointer; }
        .stat-card.clickable:hover { border-color: var(--danger); }
        
        /* Layout */
        .lab-container {
            display: grid;
            grid-template-columns: 1fr 420px;
            gap: 24px;
        }
        
        .panel {
            background: white;
            border-radius: 16px;
            border: 1px solid var(--border);
            overflow: hidden;
        }
        .panel-header {
            padding: 14px 20px;
            background: #f8fafc;
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }
        .panel-header h5 { margin: 0; font-size: 15px; font-weight: 700; }
        
        .tabs {
            display: flex;
            border-bottom: 1px solid var(--border);
            background: #fafafa;
            overflow-x: auto;
        }
        .tab {
            padding: 10px 16px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 600;
            color: var(--gray);
            border: none;
            background: transparent;
            border-bottom: 2px solid transparent;
            transition: all 0.2s;
            white-space: nowrap;
        }
        .tab:hover { background: #f0f0f0; }
        .tab.active { color: var(--primary); border-bottom-color: var(--primary); background: white; }
        .tab .count {
            display: inline-block;
            background: var(--light-gray);
            border-radius: 10px;
            padding: 0 8px;
            font-size: 11px;
            margin-left: 4px;
        }
        .tab.active .count { background: #e0e7ff; color: var(--primary); }
        .tab.critical .count { background: #fef2f2; color: var(--danger); animation: pulseRed 1.5s infinite; }
        @keyframes pulseRed { 0%,100% { background: #fef2f2; } 50% { background: #fca5a5; } }
        
        .order-list { max-height: calc(100vh - 380px); overflow-y: auto; padding: 4px 0; }
        .order-list::-webkit-scrollbar { width: 5px; }
        .order-list::-webkit-scrollbar-thumb { background: var(--border); border-radius: 3px; }
        
        .order-card {
            padding: 12px 16px;
            border-bottom: 1px solid var(--border);
            cursor: pointer;
            transition: all 0.2s;
        }
        .order-card:hover { background: var(--light-gray); }
        .order-card.active { background: #f0f4ff; border-left: 3px solid var(--primary); }
        .order-card .header { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 4px; }
        .order-card .number { font-weight: 700; font-size: 14px; }
        .order-card .patient { font-size: 14px; font-weight: 500; }
        .order-card .meta { font-size: 12px; color: var(--gray); }
        .order-card .progress-bar { height: 3px; background: var(--light-gray); border-radius: 2px; overflow: hidden; margin-top: 6px; }
        .order-card .progress-bar .fill { height: 100%; background: var(--primary); border-radius: 2px; transition: width 0.3s; }
        .order-card .critical-badge {
            background: var(--danger); color: white; padding: 2px 8px; border-radius: 10px;
            font-size: 10px; font-weight: 600; animation: blinkRed 1s infinite;
        }
        @keyframes blinkRed { 0%,100% { opacity: 1; } 50% { opacity: 0.5; } }
        
        .details-panel { position: sticky; top: 90px; max-height: calc(100vh - 110px); }
        .details-panel .body { padding: 20px; overflow-y: auto; max-height: calc(100vh - 180px); }
        
        /* Buttons */
        .btn { 
            padding: 7px 16px; 
            border-radius: 8px; 
            font-weight: 600; 
            border: none; 
            cursor: pointer; 
            transition: all 0.2s; 
            font-family: inherit; 
            font-size: 13px; 
            display: inline-flex; 
            align-items: center; 
            gap: 6px; 
        }
        .btn-primary { background: var(--primary); color: white; }
        .btn-primary:hover { background: var(--primary-dark); }
        .btn-success { background: var(--success); color: white; }
        .btn-success:hover { background: #059669; }
        .btn-danger { background: var(--danger); color: white; }
        .btn-danger:hover { background: #dc2626; }
        .btn-warning { background: var(--warning); color: var(--dark); }
        .btn-warning:hover { background: #d97706; color: white; }
        .btn-outline { background: white; border: 1px solid var(--border); color: var(--dark); }
        .btn-outline:hover { border-color: var(--primary); color: var(--primary); }
        .btn-sm { padding: 3px 10px; font-size: 11px; }
        .btn-sm i { font-size: 11px; }
        
        /* Forms */
        .form-group { margin-bottom: 14px; }
        .form-group label { font-weight: 600; font-size: 13px; display: block; margin-bottom: 4px; color: var(--dark); }
        .form-group input, .form-group select, .form-group textarea {
            width: 100%; padding: 8px 12px; border: 1px solid var(--border); border-radius: 8px;
            font-size: 14px; font-family: inherit; transition: border-color 0.2s;
        }
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus {
            outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(67,97,238,0.1);
        }
        .form-group textarea { resize: vertical; min-height: 50px; }
        
        .action-bar { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 16px; padding-top: 16px; border-top: 1px solid var(--border); }
        
        .empty-state { text-align: center; padding: 40px 20px; color: var(--gray); }
        .empty-state i { font-size: 48px; margin-bottom: 16px; opacity: 0.3; }
        
        .loading-overlay {
            position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(255,255,255,0.7); z-index: 2000; display: none;
            align-items: center; justify-content: center;
        }
        .loading-overlay.active { display: flex; }
        .spinner { width: 40px; height: 40px; border: 3px solid var(--light-gray); border-top-color: var(--primary); border-radius: 50%; animation: spin 0.8s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }
        
        .toast {
            position: fixed; bottom: 20px; right: 20px; background: white; border-radius: 12px;
            padding: 12px 20px; box-shadow: 0 10px 25px rgba(0,0,0,0.1); z-index: 1100;
            animation: slideIn 0.3s ease; display: flex; align-items: center; gap: 12px;
            border-left: 4px solid var(--success); max-width: 400px;
        }
        .toast.error { border-left-color: var(--danger); }
        @keyframes slideIn { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
        
        .barcode-input { font-family: 'Courier New', monospace; font-size: 20px; letter-spacing: 2px; text-align: center; }
        
        /* Panel-specific styles */
        .panel-header .specimen-type-badge {
            display: inline-block;
            padding: 2px 12px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            background: #e0e7ff;
            color: var(--primary);
            margin-left: 8px;
        }
        .panel-card {
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 16px;
            margin-bottom: 12px;
            background: #fafafa;
        }
        .panel-card .panel-title {
            font-weight: 600;
            font-size: 14px;
            margin-bottom: 8px;
            padding-bottom: 8px;
            border-bottom: 2px solid var(--primary);
        }
        .panel-card .panel-title .badge-count {
            background: var(--light-gray);
            padding: 1px 10px;
            border-radius: 10px;
            font-size: 11px;
            font-weight: 600;
            margin-left: 8px;
        }
        .specimen-section {
            background: white;
            border-radius: 10px;
            padding: 16px;
            margin-bottom: 16px;
            border-left: 4px solid var(--primary);
        }
        .specimen-section .section-title {
            font-weight: 600;
            font-size: 15px;
            margin-bottom: 12px;
        }
        .specimen-section .section-title .specimen-icon {
            margin-right: 8px;
        }
        .result-panel {
            background: white;
            border-radius: 10px;
            padding: 16px;
            margin-bottom: 16px;
            border-left: 4px solid var(--success);
        }
        .result-panel .panel-title {
            font-weight: 600;
            font-size: 14px;
            margin-bottom: 12px;
            padding-bottom: 8px;
            border-bottom: 2px solid var(--success);
        }
        .result-panel .panel-title .panel-status {
            float: right;
            font-weight: 400;
            font-size: 12px;
        }
        .result-panel .panel-title .panel-status .badge {
            margin-left: 4px;
        }
        .result-table th {
            background: #f8fafc;
            font-weight: 600;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 8px 12px;
            border: 1px solid #e5e7eb;
        }
        .result-table td {
            border: 1px solid #e5e7eb;
            padding: 6px 12px;
            vertical-align: middle;
        }
        .result-table tr:hover { background-color: #f8fafc !important; }
        .result-table .form-control-sm { min-width: 80px; }
        .result-table .critical-row { background-color: #fef2f2 !important; border-left: 4px solid #ef4444 !important; }
        .result-table .abnormal-row { background-color: #fffbeb !important; border-left: 4px solid #f59e0b !important; }
        .result-table .normal-row { background-color: #f0fdf4 !important; border-left: 4px solid #22c55e !important; }
        .result-table .verified-row { background-color: #e8f5e9 !important; opacity: 0.85; }
        
        .specimen-badge {
            display: inline-block;
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            margin: 2px;
        }
        .specimen-badge.collected { background: #d1fae5; color: #065f46; }
        .specimen-badge.missing { background: #fef3c7; color: #92400e; }
        
        .verification-panel {
            background: #f8fafc;
            border-radius: 10px;
            padding: 16px;
            margin-bottom: 16px;
            border-left: 4px solid var(--info);
        }
        .verification-panel .panel-title {
            font-weight: 600;
            font-size: 14px;
            margin-bottom: 12px;
            padding-bottom: 8px;
            border-bottom: 2px solid var(--info);
        }
        .verification-panel .verified-value {
            font-weight: 600;
        }
        .verification-panel .verified-value.critical { color: #dc2626; }
        .verification-panel .verified-value.abnormal { color: #f59e0b; }
        .verification-panel .verified-value.normal { color: #10b981; }
        
        .critical-banner {
            background: #fef2f2;
            border-left: 4px solid #ef4444;
            padding: 12px 16px;
            margin: 12px 20px 0 20px;
            border-radius: 8px;
            animation: pulse-banner 1.5s ease-in-out infinite;
            display: none;
        }
        @keyframes pulse-banner {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.7; }
        }
        
        .order-checkbox {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }
        .bulk-result-input {
            font-size: 14px;
        }
        .bulk-result-input:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(67,97,238,0.1);
        }
        
        .modal-xl {
            max-width: 1140px;
        }
        .modal-body {
            max-height: calc(100vh - 180px);
            overflow-y: auto;
        }
        .modal-body::-webkit-scrollbar { width: 6px; }
        .modal-body::-webkit-scrollbar-thumb { background: var(--border); border-radius: 3px; }
        
        /* Auto-save indicator */
        .auto-save-indicator {
            font-size: 12px;
            color: var(--gray);
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 4px 12px;
            background: var(--light-gray);
            border-radius: 12px;
        }
        .auto-save-indicator.saved { color: var(--success); }
        .auto-save-indicator.saving { color: var(--warning); animation: pulse-save 1s infinite; }
        @keyframes pulse-save { 0%,100% { opacity: 1; } 50% { opacity: 0.5; } }
        
        /* TAT indicator */
        .tat-indicator {
            display: inline-block;
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
        }
        .tat-indicator.good { background: #d1fae5; color: #065f46; }
        .tat-indicator.warning { background: #fef3c7; color: #92400e; }
        .tat-indicator.bad { background: #fef2f2; color: #dc2626; }
    </style>
</head>
<body>
<div id="wrapper">
    <?php include('assets/inc/nav.php'); ?>
    <?php include('assets/inc/sidebar.php'); ?>
    
    <div id="sidebarOverlay" class="sidebar-overlay" onclick="toggleSidebar()"></div>
    
    <div id="page-content-wrapper">
        
        <div class="content-page">
            <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap">
                <div>
                    <h4 class="mb-1"><i class="fas fa-microscope text-primary me-2"></i> Laboratory Dashboard</h4>
                    <p class="text-muted small mb-0">Three-Section Workflow: Pending → Collected → Verified</p>
                </div>
                <div class="d-flex gap-2">
                    <span class="badge bg-light text-dark p-2">
                        <i class="fas fa-user-md me-1"></i>Welcome, <?php echo escapeHtml($user_full_name); ?>
                    </span>
                </div>
            </div>

            <!-- Statistics Cards -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="icon" style="background:#e0e7ff;color:var(--primary);"><i class="fas fa-flask"></i></div>
                    <div class="number" id="statToday"><?php echo $stats['today_orders'] ?? 0; ?></div>
                    <div class="label">Today's Orders</div>
                </div>
                <div class="stat-card">
                    <div class="icon" style="background:#fef3c7;color:#d97706;"><i class="fas fa-clock"></i></div>
                    <div class="number" id="statPending"><?php echo $stats['pending_orders'] ?? 0; ?></div>
                    <div class="label">Pending Collection</div>
                </div>
                <div class="stat-card">
                    <div class="icon" style="background:#dbeafe;color:#2563eb;"><i class="fas fa-vial"></i></div>
                    <div class="number" id="statCollected"><?php echo $stats['collected_orders'] ?? 0; ?></div>
                    <div class="label">Collected / Results Pending</div>
                </div>
                <div class="stat-card">
                    <div class="icon" style="background:#d1fae5;color:#059669;"><i class="fas fa-check-circle"></i></div>
                    <div class="number" id="statCompleted"><?php echo $stats['completed_orders'] ?? 0; ?></div>
                    <div class="label">Verified / Complete</div>
                </div>
                <div class="stat-card clickable" id="criticalStatCard">
                    <div class="icon" style="background:#fef2f2;color:var(--danger);"><i class="fas fa-exclamation-triangle"></i></div>
                    <div class="number text-danger" id="statCritical"><?php echo $stats['critical_alerts'] ?? 0; ?></div>
                    <div class="label">Critical Alerts</div>
                </div>
            </div>

            <!-- Main Layout -->
            <div class="lab-container">
                <!-- Left Panel: Patient List -->
                <div class="panel">
                    <div class="panel-header">
                        <h5><i class="fas fa-users me-2"></i> Patients</h5>
                        <div class="d-flex gap-2">
                            <input type="text" id="orderSearch" placeholder="Search patient..." class="form-control form-control-sm" style="width:200px;" oninput="fetchGroupedOrders()">
                            <button class="btn btn-primary btn-sm" onclick="fetchGroupedOrders()"><i class="fas fa-search"></i></button>
                        </div>
                    </div>
                    <div class="tabs" id="tabs">
                        <button class="tab active" data-filter="pending" onclick="switchFilter('pending')">
                            <i class="fas fa-clock me-1"></i> Pending <span class="count" id="cntPending">0</span>
                        </button>
                        <button class="tab" data-filter="collected" onclick="switchFilter('collected')">
                            <i class="fas fa-vial me-1"></i> Collected <span class="count" id="cntCollected">0</span>
                        </button>
                        <button class="tab" data-filter="completed" onclick="switchFilter('completed')">
                            <i class="fas fa-check-circle me-1"></i> Complete <span class="count" id="cntCompleted">0</span>
                        </button>
                        <button class="tab critical" data-filter="critical" onclick="switchFilter('critical')">
                            <i class="fas fa-exclamation-triangle text-danger"></i> Critical <span class="count" id="cntCritical">0</span>
                        </button>
                    </div>
                    <div class="order-list" id="orderList">
                        <div class="text-center p-4 text-muted"><div class="spinner-border spinner-border-sm text-primary"></div> Loading patients...</div>
                    </div>
                </div>

                <!-- Right Panel: Patient Details -->
                <div class="panel details-panel">
                    <div class="panel-header">
                        <h5><i class="fas fa-info-circle me-2"></i> Patient Details</h5>
                        <span class="text-muted small" id="detailStatus">Select a patient</span>
                    </div>
                    <div class="body" id="orderDetails">
                        <div class="empty-state"><i class="fas fa-hand-pointer"></i><h5>No Patient Selected</h5><p class="text-muted small">Click a patient from the list</p></div>
                    </div>
                </div>
            </div>
        </div>
        <?php include('assets/inc/footer.php'); ?>
    </div>
</div>

<div id="loadingOverlay" class="loading-overlay"><div class="spinner"></div></div>

<!-- ============================================================
SECTION 1: SPECIMEN COLLECTION MODAL (Grouped by Specimen Type)
============================================================ -->
<div class="modal fade" id="collectModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header" style="background:linear-gradient(135deg,#eff6ff,#eef2ff);">
                <div>
                    <h5 class="modal-title fw-bold"><i class="fas fa-syringe text-primary me-2"></i> Collect Specimens</h5>
                    <div class="text-muted small mt-1">
                        <span id="collectPatientName" class="fw-medium">Loading patient...</span>
                        <span class="mx-2">|</span>
                        <span id="collectMRN" class="fw-medium">Loading MRN...</span>
                        <span class="mx-2">|</span>
                        <span id="collectOrderCount" class="fw-medium">0 orders</span>
                    </div>
                    <div class="text-muted small mt-1">
                        <i class="fas fa-info-circle text-primary"></i> 
                        Orders are grouped by specimen type. Fill in collection details for each specimen type.
                    </div>
                </div>
                <input type="hidden" id="collectVisitId" value="">
                <button type="button" class="btn-close" onclick="closeModal('collectModal')"></button>
            </div>
            <div class="modal-body">
                <form id="collectForm">
                    <div id="collectGroupsList"></div>
                    <div class="form-group mt-3">
                        <label>General Collection Notes</label>
                        <textarea class="form-control" id="collectNotes" rows="2" placeholder="Any additional notes for all specimens..."></textarea>
                    </div>
                    <div class="action-bar">
                        <button type="button" class="btn btn-outline" onclick="closeModal('collectModal')">Cancel</button>
                        <button type="submit" class="btn btn-primary" id="collectSubmitBtn">
                            <i class="fas fa-vial"></i> Collect All Specimens
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- ============================================================
SECTION 2: RESULT ENTRY MODAL (Grouped by Test Panels)
============================================================ -->
<div class="modal fade" id="resultModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header" style="background:linear-gradient(135deg,#eff6ff,#eef2ff);">
                <div>
                    <h5 class="modal-title fw-bold"><i class="fas fa-edit text-primary me-2"></i> Enter Results</h5>
                    <div class="text-muted small mt-1">
                        <span id="resultPatientName" class="fw-medium">Loading patient...</span>
                        <span class="mx-2">|</span>
                        <span id="resultMRN" class="fw-medium">Loading MRN...</span>
                        <span class="mx-2">|</span>
                        <span id="resultPanelCount" class="fw-medium">0 panels</span>
                    </div>
                    <div class="text-muted small mt-1">
                        <i class="fas fa-info-circle text-primary"></i> 
                        Results are grouped by test panels. Scroll down to enter all results, then click "Save All Results".
                    </div>
                </div>
                <input type="hidden" id="resultVisitId" value="">
                <button type="button" class="btn-close" onclick="closeModal('resultModal')"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-warning" id="criticalAlertBannerResult" style="display:none;">
                    <i class="fas fa-exclamation-triangle text-danger"></i>
                    <span id="resultCriticalCountDisplay">0</span> critical value(s) detected. Please verify before submission.
                </div>
                <form id="resultForm">
                    <div id="resultPanelsList"></div>
                    <div class="form-group mt-3">
                        <label class="fw-semibold text-secondary small">
                            <i class="fas fa-sticky-note text-primary me-2"></i>Technician Notes (applies to all results)
                        </label>
                        <textarea class="form-control" id="resultNotes" rows="2" placeholder="Add any observations or notes..."></textarea>
                    </div>
                    <div class="action-bar">
                        <button type="button" class="btn btn-outline" onclick="closeModal('resultModal')">Cancel</button>
                        <button type="submit" class="btn btn-primary" id="resultSubmitBtn">
                            <i class="fas fa-check-double me-1"></i> Save All Results
                        </button>
                    </div>
                </form>
            </div>
            <div class="modal-footer bg-light border-top">
                <div class="me-auto">
                    <span class="text-muted small" id="resultProgress">0/0 results entered</span>
                    <span class="mx-2 text-muted">•</span>
                    <span class="text-danger small fw-medium" id="resultCriticalCount">0 critical</span>
                    <span class="mx-2 text-muted">•</span>
                    <span class="text-warning small fw-medium" id="resultAbnormalCount">0 abnormal</span>
                    <span class="mx-2 text-muted">•</span>
                    <span class="auto-save-indicator" id="autoSaveIndicator">
                        <i class="fas fa-circle text-muted"></i> Auto-save ready
                    </span>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ============================================================
SECTION 3: VERIFICATION MODAL (Unified Verification View)
============================================================ -->
<div class="modal fade" id="verifyModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header" style="background:linear-gradient(135deg,#eff6ff,#eef2ff);">
                <div>
                    <h5 class="modal-title fw-bold">
                        <i class="fas fa-check-double text-success me-2"></i> Verify & Release Results
                    </h5>
                    <div class="text-muted small mt-1" id="verifyPatientInfo">Loading patient...</div>
                    <div class="text-muted small mt-1">
                        <i class="fas fa-info-circle text-primary"></i> 
                        Review all results grouped by panel. Click "Verify All" to release the entire encounter.
                    </div>
                </div>
                <input type="hidden" id="verifyVisitId" value="">
                <button type="button" class="btn-close" onclick="closeModal('verifyModal')"></button>
            </div>
            <div class="modal-body">
                <div id="verifyPanelsList"></div>
                <div id="verifySummaryStats" class="mt-3 p-3 bg-light rounded">
                    <div class="row text-center">
                        <div class="col-3"><strong>Total Orders</strong><br><span id="verifyTotalOrders">0</span></div>
                        <div class="col-3"><strong>Parameters</strong><br><span id="verifyTotalParams">0</span></div>
                        <div class="col-3"><strong class="text-danger">Critical</strong><br><span id="verifyCriticalCount">0</span></div>
                        <div class="col-3"><strong class="text-warning">Abnormal</strong><br><span id="verifyAbnormalCount">0</span></div>
                    </div>
                </div>
                <div class="form-group mt-3">
                    <label class="fw-semibold text-secondary small">
                        <i class="fas fa-sticky-note text-primary me-2"></i>Verification Notes
                    </label>
                    <textarea class="form-control" id="verifyNotes" rows="2" placeholder="Add any verification notes..."></textarea>
                </div>
                <div class="action-bar">
                    <button type="button" class="btn btn-outline" onclick="closeModal('verifyModal')">Cancel</button>
                    <button type="button" class="btn btn-success" id="verifySubmitBtn">
                        <i class="fas fa-check-double"></i> Verify & Release All
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ============================================================
BARCODE SEARCH MODAL
============================================================ -->
<div class="modal fade" id="barcodeModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-qrcode me-2"></i> Scan Barcode</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label>Barcode</label>
                    <input type="text" class="form-control barcode-input" id="barcodeInput" placeholder="SPC-YYYYMMDD-XXXXX" autofocus>
                </div>
                <div id="barcodeResult" class="mt-3"></div>
                <div class="action-bar">
                    <button class="btn btn-outline" data-bs-dismiss="modal">Close</button>
                    <button class="btn btn-primary" id="barcodeSearchBtn"><i class="fas fa-search"></i> Find</button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ============================================================
EXPORT MODAL
============================================================ -->
<div class="modal fade" id="exportModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-download me-2"></i> Export Data</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label>Date Range</label>
                    <div class="row g-2">
                        <div class="col-6">
                            <input type="date" class="form-control" id="exportDateFrom" value="<?php echo date('Y-m-d', strtotime('-7 days')); ?>">
                        </div>
                        <div class="col-6">
                            <input type="date" class="form-control" id="exportDateTo" value="<?php echo date('Y-m-d'); ?>">
                        </div>
                    </div>
                </div>
                <div class="form-group">
                    <label>Export Type</label>
                    <select class="form-control" id="exportType">
                        <option value="summary">Summary Report</option>
                        <option value="detailed">Detailed Results</option>
                        <option value="stats">Statistics Only</option>
                    </select>
                </div>
                <div class="action-bar">
                    <button class="btn btn-outline" data-bs-dismiss="modal">Cancel</button>
                    <button class="btn btn-primary" id="exportBtn"><i class="fas fa-file-excel"></i> Export Excel</button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// ============================================================
// LABORATORY DASHBOARD - PATIENT-CENTRIC ENCOUNTER MODEL JS
// ============================================================

// ============================================================
// GLOBALS
// ============================================================
const AJAX_URL = window.location.pathname + '?ajax=lab_action';
const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '<?php echo $csrf_token; ?>';
const branchId = <?php echo $branch_id; ?>;
let currentFilter = 'pending';
let currentVisitId = null;
let currentPatientId = null;
let pollInterval = null;
let bsModals = {};
let isProcessing = false;
let autoSaveTimer = null;
let hasUnsavedChanges = false;

// ============================================================
// UTILITY FUNCTIONS
// ============================================================

function showToast(msg, type = 'success', duration = 4000) {
    const icons = {
        success: 'fa-check-circle',
        error: 'fa-exclamation-circle',
        warning: 'fa-exclamation-triangle',
        info: 'fa-info-circle'
    };
    const colors = {
        success: '#10b981',
        error: '#ef4444',
        warning: '#f59e0b',
        info: '#3b82f6'
    };
    
    const t = document.createElement('div');
    t.className = 'toast';
    t.style.cssText = `
        position: fixed; bottom: 24px; right: 24px; background: white; border-radius: 12px;
        padding: 14px 20px; box-shadow: 0 10px 40px rgba(0,0,0,0.15); z-index: 1100;
        animation: slideIn 0.3s ease; display: flex; align-items: center; gap: 12px;
        max-width: 450px; border-left: 4px solid ${colors[type] || colors.info};
        font-family: 'Inter', sans-serif; font-size: 14px;
    `;
    t.innerHTML = `
        <i class="fas ${icons[type] || icons.info}" style="color: ${colors[type] || colors.info}; font-size: 20px;"></i>
        <span style="flex: 1;">${escapeHtml(msg)}</span>
        <button onclick="this.parentElement.remove()" style="background: none; border: none; cursor: pointer; color: #9ca3af; font-size: 16px;">
            <i class="fas fa-times"></i>
        </button>
    `;
    document.body.appendChild(t);
    setTimeout(() => {
        if (t.parentNode) {
            t.style.opacity = '0';
            t.style.transform = 'translateX(100%)';
            t.style.transition = 'all 0.3s ease';
            setTimeout(() => t.remove(), 300);
        }
    }, duration);
}

function showLoading(show) {
    const overlay = document.getElementById('loadingOverlay');
    if (overlay) overlay.classList.toggle('active', show);
}

function escapeHtml(s) {
    if (!s) return '';
    const div = document.createElement('div');
    div.textContent = s;
    return div.innerHTML;
}

function formatDate(d) {
    if (!d) return 'N/A';
    try {
        return new Date(d).toLocaleString('en-US', {
            year: 'numeric', month: 'short', day: 'numeric',
            hour: '2-digit', minute: '2-digit'
        });
    } catch(e) { return d; }
}

function getStatusBadge(status) {
    const map = {
        'pending_payment': 'warning', 'pending': 'secondary', 'collected': 'info',
        'partial_collected': 'info', 'submitted': 'primary', 'verified': 'success',
        'completed': 'success', 'cancelled': 'danger', 'rejected': 'danger'
    };
    const cls = map[status] || 'secondary';
    const display = status === 'partial_collected' ? 'Partial Collected' : status.replace('_', ' ');
    return `<span class="badge bg-${cls}">${display}</span>`;
}

function getPriorityBadge(priority) {
    const map = {'routine': 'info', 'urgent': 'warning', 'stat': 'danger'};
    const cls = map[priority] || 'secondary';
    return `<span class="badge bg-${cls}">${(priority || '').toUpperCase()}</span>`;
}

function getGroupBadge(status) {
    const map = {'pending': 'warning', 'collected': 'info', 'completed': 'success', 'mixed': 'secondary'};
    const cls = map[status] || 'secondary';
    const display = status.charAt(0).toUpperCase() + status.slice(1);
    return `<span class="badge bg-${cls}">${display}</span>`;
}

function openModal(id) {
    if (!bsModals[id]) {
        const el = document.getElementById(id);
        if (el) bsModals[id] = new bootstrap.Modal(el);
    }
    if (bsModals[id]) bsModals[id].show();
}

function closeModal(id) {
    if (bsModals[id]) bsModals[id].hide();
}

// ============================================================
// FETCH GROUPED ORDERS
// ============================================================

function fetchGroupedOrders() {
    const search = document.getElementById('orderSearch')?.value || '';
    const limit = 100;
    
    showLoading(true);
    $.post(AJAX_URL, {
        action: 'fetch_grouped_orders',
        filter: currentFilter,
        search: search,
        limit: limit,
        csrf_token: csrf
    }, function(res) {
        showLoading(false);
        if (res.success) {
            renderGroupedOrders(res.orders || []);
            updateCounts(res.counts || {});
            updateStats(res.counts || {});
        } else {
            showToast(res.message || 'Error loading orders', 'error');
            document.getElementById('orderList').innerHTML = 
                '<div class="empty-state"><i class="fas fa-exclamation-circle text-danger"></i><p>' + 
                escapeHtml(res.message) + '</p></div>';
        }
    }).fail(function() {
        showLoading(false);
        showToast('Network error', 'error');
    });
}

function renderGroupedOrders(patients) {
    const container = document.getElementById('orderList');
    if (!patients || patients.length === 0) {
        const labels = {
            pending: 'patients pending collection',
            collected: 'patients with collected specimens',
            completed: 'patients with completed tests',
            critical: 'patients with critical alerts'
        };
        container.innerHTML = `
            <div class="empty-state">
                <i class="fas fa-check-circle text-success" style="font-size: 40px;"></i>
                <p style="margin-top: 12px; color: #6b7280;">No ${labels[currentFilter] || 'patients'}</p>
            </div>
        `;
        return;
    }
    
    let html = '';
    patients.forEach(patient => {
        const active = patient.visit_id === currentVisitId ? 'active' : '';
        html += `
            <div class="order-card ${active}" onclick="selectPatient(${patient.visit_id})" 
                 data-visit-id="${patient.visit_id}" data-patient-id="${patient.patient_id}">
                <div class="header">
                    <span class="number">${escapeHtml(patient.patient_name)}</span>
                    ${getGroupBadge(patient.overall_status)}
                    ${patient.overall_priority === 'stat' ? '<span class="badge bg-danger">STAT</span>' : ''}
                    ${patient.overall_priority === 'urgent' ? '<span class="badge bg-warning">URGENT</span>' : ''}
                </div>
                <div class="patient">
                    MRN: ${escapeHtml(patient.mr_number)} 
                    <span class="text-muted small">(${patient.order_count} order${patient.order_count > 1 ? 's' : ''})</span>
                    <span class="text-muted small">Visit: ${escapeHtml(patient.visit_number || 'N/A')}</span>
                </div>
                ${patient.test_names_with_params ? `
                    <div class="meta">Tests: ${patient.test_names_with_params.split('||').map(name => 
                        `<span class="badge bg-light text-dark me-1">${escapeHtml(name.trim())}</span>`
                    ).join('')}</div>
                ` : ''}
                ${patient.order_numbers ? `
                    <div class="meta small text-muted">Orders: ${escapeHtml(patient.order_numbers.split(',').join(', '))}</div>
                ` : ''}
                ${currentFilter === 'collected' || patient.overall_status === 'collected' || patient.overall_status === 'mixed' ? `
                    <div class="progress-bar">
                        <div class="fill" style="width:${patient.result_entry_status === 'all_entered' ? 100 : patient.result_entry_status === 'partial' ? 50 : 0}%; ${patient.result_entry_status === 'all_entered' ? 'background: #10b981;' : ''}"></div>
                    </div>
                    <div class="meta text-muted small">
                        ${patient.result_entry_status === 'all_entered' ? '✅ All results entered' : 
                          patient.result_entry_status === 'partial' ? '⏳ Partial results entered' : '⏳ No results entered'}
                    </div>
                ` : ''}
                ${patient.critical_order_count > 0 ? `
                    <div class="critical-badge"><i class="fas fa-exclamation-triangle"></i> ${patient.critical_order_count} Critical</div>
                ` : ''}
                ${patient.first_collected_at ? `<div class="meta"><i class="fas fa-vial text-success"></i> Collected: ${formatDate(patient.first_collected_at)}</div>` : ''}
                ${(patient.overall_status === 'completed' || patient.overall_status === 'mixed') && patient.last_completed_at ? 
                    `<div class="meta text-success"><i class="fas fa-check-circle"></i> Verified: ${formatDate(patient.last_completed_at)}</div>` : ''}
            </div>
        `;
    });
    container.innerHTML = html;
}

function updateCounts(c) {
    document.getElementById('cntPending').textContent = c.pending || 0;
    document.getElementById('cntCollected').textContent = c.collected || 0;
    document.getElementById('cntCompleted').textContent = c.completed || 0;
    document.getElementById('cntCritical').textContent = c.critical || 0;
}

function updateStats(c) {
    document.getElementById('statToday').textContent = (c.pending || 0) + (c.collected || 0) + (c.completed || 0);
    document.getElementById('statPending').textContent = c.pending || 0;
    document.getElementById('statCollected').textContent = c.collected || 0;
    document.getElementById('statCompleted').textContent = c.completed || 0;
    document.getElementById('statCritical').textContent = c.critical || 0;
}

function switchFilter(filter) {
    currentFilter = filter;
    document.querySelectorAll('.tab').forEach(t => {
        t.classList.toggle('active', t.dataset.filter === filter);
    });
    currentVisitId = null;
    currentPatientId = null;
    document.getElementById('orderDetails').innerHTML = `
        <div class="empty-state"><i class="fas fa-hand-pointer" style="font-size: 36px;"></i>
        <h5 style="margin-top: 12px;">No Patient Selected</h5>
        <p class="text-muted small">Click a patient from the list</p></div>
    `;
    document.getElementById('detailStatus').textContent = 'Select a patient';
    fetchGroupedOrders();
}

// ============================================================
// SELECT PATIENT / ENCOUNTER
// ============================================================

function selectPatient(visitId) {
    if (isProcessing || currentVisitId === visitId) return;
    currentVisitId = visitId;
    
    document.querySelectorAll('.order-card').forEach(c => {
        c.classList.toggle('active', parseInt(c.dataset.visitId) === visitId);
    });
    const card = document.querySelector(`.order-card[data-visit-id="${visitId}"]`);
    if (card) currentPatientId = parseInt(card.dataset.patientId);
    
    document.getElementById('orderDetails').innerHTML = 
        '<div class="text-center p-4"><div class="spinner-border spinner-border-sm text-primary"></div> Loading patient details...</div>';
    document.getElementById('detailStatus').textContent = 'Loading...';
    showLoading(true);
    
    let action = '';
    switch (currentFilter) {
        case 'pending': action = 'get_encounter_orders'; break;
        case 'collected': action = 'get_encounter_panels'; break;
        case 'completed': action = 'get_verification_summary'; break;
        case 'critical': action = 'get_encounter_panels'; break;
        default: action = 'get_encounter_orders';
    }
    
    $.post(AJAX_URL, {
        action: action,
        visit_id: visitId,
        csrf_token: csrf
    }, function(res) {
        showLoading(false);
        if (res.success) {
            renderPatientDetails(res);
            document.getElementById('detailStatus').textContent = 
                `Patient: ${res.encounter?.patient_name || 'N/A'} (${res.encounter?.mr_number || 'N/A'})`;
        } else {
            showToast(res.message || 'Error loading patient details', 'error');
            document.getElementById('orderDetails').innerHTML = 
                '<div class="empty-state"><i class="fas fa-exclamation-circle text-danger"></i><p>' + 
                escapeHtml(res.message || 'Error loading details') + '</p></div>';
            document.getElementById('detailStatus').textContent = 'Error';
        }
    }).fail(function() {
        showLoading(false);
        showToast('Network error loading details', 'error');
        document.getElementById('orderDetails').innerHTML = 
            '<div class="empty-state"><i class="fas fa-exclamation-circle text-danger"></i><p>Network error loading details</p></div>';
        document.getElementById('detailStatus').textContent = 'Error';
    });
}

// ============================================================
// RENDER PATIENT DETAILS
// ============================================================

function renderPatientDetails(res) {
    const container = document.getElementById('orderDetails');
    const encounter = res.encounter;
    let html = '';
    
    html += `
        <div class="bg-light p-3 rounded mb-3">
            <div class="row g-2">
                <div class="col-md-4"><strong>Patient:</strong> ${escapeHtml(encounter?.patient_name || 'N/A')}</div>
                <div class="col-md-4"><strong>MRN:</strong> ${escapeHtml(encounter?.mr_number || 'N/A')}</div>
                <div class="col-md-4"><strong>Visit:</strong> ${escapeHtml(encounter?.visit_number || 'N/A')}</div>
                <div class="col-md-4"><strong>Gender:</strong> ${escapeHtml(encounter?.gender || 'N/A')}</div>
                <div class="col-md-4"><strong>Phone:</strong> ${escapeHtml(encounter?.phone || 'N/A')}</div>
                <div class="col-md-4"><strong>Visit Date:</strong> ${formatDate(encounter?.visit_date)}</div>
            </div>
        </div>
    `;
    
    switch (currentFilter) {
        case 'pending':
            html += renderPendingDetails(res);
            break;
        case 'collected':
            html += renderCollectedDetails(res);
            break;
        case 'completed':
            html += renderCompletedDetails(res);
            break;
        case 'critical':
            html += renderCriticalDetails(res);
            break;
        default:
            html += renderPendingDetails(res);
    }
    
    container.innerHTML = html;
}

// ============================================================
// SECTION 1: PENDING - SPECIMEN COLLECTION VIEW
// ============================================================

function renderPendingDetails(res) {
    const grouped = res.grouped_orders || {};
    const encounter = res.encounter;
    let html = '';
    let totalOrders = 0;
    
    const specimenIcons = {
        'blood': 'fa-droplet', 'urine': 'fa-toilet', 'serum': 'fa-flask',
        'plasma': 'fa-flask', 'stool': 'fa-poop', 'csf': 'fa-brain',
        'swab': 'fa-swab', 'sputum': 'fa-lungs'
    };
    
    const specimenColors = {
        'blood': '#dc2626', 'urine': '#f59e0b', 'serum': '#f97316',
        'plasma': '#8b5cf6', 'stool': '#92400e', 'csf': '#06b6d4',
        'swab': '#6366f1', 'sputum': '#14b8a6'
    };
    
    for (const [specimenType, group] of Object.entries(grouped)) {
        const orders = group.orders || [];
        if (orders.length === 0) continue;
        totalOrders += orders.length;
        const config = group.config || {};
        const icon = specimenIcons[specimenType] || 'fa-vial';
        const color = specimenColors[specimenType] || '#6b7280';
        
        html += `
            <div class="specimen-section" style="border-left-color: ${color};">
                <div class="section-title">
                    <i class="fas ${icon} specimen-icon" style="color: ${color};"></i>
                    ${escapeHtml(specimenType.charAt(0).toUpperCase() + specimenType.slice(1))} Collection
                    <span class="badge bg-secondary ms-2">${orders.length} order${orders.length > 1 ? 's' : ''}</span>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm table-bordered">
                        <thead>
                            <tr>
                                <th><input type="checkbox" class="specimen-select-all" data-specimen="${specimenType}" checked></th>
                                <th>Order #</th>
                                <th>Test</th>
                                <th>Priority</th>
                                <th>Ordered By</th>
                            </tr>
                        </thead>
                        <tbody>
        `;
        
        orders.forEach(order => {
            html += `
                <tr>
                    <td><input type="checkbox" class="order-collect-checkbox" data-specimen="${specimenType}" value="${order.order_id}" checked></td>
                    <td>${escapeHtml(order.order_number)}</td>
                    <td>${escapeHtml(order.test_name)} <small class="text-muted">(${escapeHtml(order.test_code)})</small></td>
                    <td>${getPriorityBadge(order.priority)}</td>
                    <td>Dr. ${escapeHtml(order.doctor_name)}</td>
                </tr>
            `;
        });
        
        html += `
                        </tbody>
                    </table>
                </div>
                <div class="row g-2 mt-2">
                    ${(config.fields || []).map(field => {
                        let inputHtml = '';
                        if (field.type === 'select') {
                            inputHtml = `<select class="form-control form-control-sm" id="collect_${specimenType}_${field.name}">`;
                            if (field.options) {
                                field.options.forEach(opt => {
                                    inputHtml += `<option value="${escapeHtml(opt)}">${escapeHtml(opt)}</option>`;
                                });
                            }
                            inputHtml += `</select>`;
                        } else if (field.type === 'number') {
                            inputHtml = `<input type="number" step="${field.step || '0.1'}" class="form-control form-control-sm" id="collect_${specimenType}_${field.name}" placeholder="${escapeHtml(field.label)}">`;
                        } else {
                            inputHtml = `<input type="text" class="form-control form-control-sm" id="collect_${specimenType}_${field.name}" placeholder="${escapeHtml(field.label)}">`;
                        }
                        return `
                            <div class="col-md-3">
                                <label class="small text-muted">${escapeHtml(field.label)}</label>
                                ${inputHtml}
                            </div>
                        `;
                    }).join('')}
                </div>
            </div>
        `;
    }
    
    if (totalOrders === 0) {
        html += `<div class="text-muted text-center py-3">No pending orders for this patient</div>`;
    }
    
    html += `
        <div class="action-bar">
            ${totalOrders > 0 ? `
                <button class="btn btn-primary" onclick="openCollectModal()">
                    <i class="fas fa-syringe"></i> Collect Specimens (${totalOrders})
                </button>
            ` : `
                <button class="btn btn-success" disabled><i class="fas fa-check"></i> All Specimens Collected</button>
            `}
            <button class="btn btn-outline btn-sm" onclick="window.open('emr_view.php?patient_id=${encounter?.patient_id}','_blank')">
                <i class="fas fa-file-medical"></i> View Record
            </button>
        </div>
    `;
    
    window._pendingGrouped = grouped;
    window._selectedPatient = encounter;
    
    return html;
}

// ============================================================
// SECTION 2: COLLECTED - RESULT ENTRY VIEW
// ============================================================

function renderCollectedDetails(res) {
    const panels = res.panels || [];
    const encounter = res.encounter;
    let html = '';
    let totalParams = 0;
    let enteredParams = 0;
    let criticalCount = 0;
    let abnormalCount = 0;
    
    if (!panels || panels.length === 0) {
        html += `<div class="text-muted text-center py-3">No collected orders for this patient</div>`;
        html += `
            <div class="action-bar">
                <button class="btn btn-outline btn-sm" onclick="window.open('emr_view.php?patient_id=${encounter?.patient_id}','_blank')">
                    <i class="fas fa-file-medical"></i> View Record
                </button>
            </div>
        `;
        return html;
    }
    
    html += `
        <div class="mb-3">
            <h6><i class="fas fa-vial me-2"></i>Collected Orders (${panels.length} panels)</h6>
    `;
    
    panels.forEach(panel => {
        const params = panel.parameters || [];
        const allEntered = params.every(p => p.numerical_value !== null || p.textual_value);
        const someEntered = params.some(p => p.numerical_value !== null || p.textual_value);
        const panelCritical = params.filter(p => p.is_critical).length;
        const panelAbnormal = params.filter(p => p.is_abnormal && !p.is_critical).length;
        
        totalParams += params.length;
        enteredParams += params.filter(p => p.numerical_value !== null || p.textual_value).length;
        criticalCount += panelCritical;
        abnormalCount += panelAbnormal;
        
        html += `
            <div class="result-panel" style="${panelCritical > 0 ? 'border-left-color: #ef4444;' : ''}">
                <div class="panel-title">
                    <i class="fas fa-microscope me-2"></i>
                    ${escapeHtml(panel.panel_name)}
                    <span class="badge bg-light text-dark ms-2">${panel.order_number}</span>
                    <span class="panel-status">
                        ${getPriorityBadge(panel.priority)}
                        ${allEntered ? '<span class="badge bg-success"><i class="fas fa-check"></i> Complete</span>' : ''}
                        ${someEntered && !allEntered ? '<span class="badge bg-warning">Partial</span>' : ''}
                        ${!someEntered ? '<span class="badge bg-secondary">Pending</span>' : ''}
                        ${panelCritical > 0 ? '<span class="badge bg-danger"><i class="fas fa-exclamation-triangle"></i> ' + panelCritical + ' Critical</span>' : ''}
                    </span>
                </div>
                <div class="small text-muted mb-2">
                    Specimen: ${escapeHtml(panel.specimen_type || 'N/A')} | 
                    Collected: ${formatDate(panel.specimen_collected_at)} | 
                    Dr. ${escapeHtml(panel.doctor_name)}
                </div>
        `;
        
        if (params.length > 0) {
            html += `
                <div class="table-responsive">
                    <table class="table table-sm table-bordered result-table">
                        <thead>
                            <tr>
                                <th>Parameter</th>
                                <th>Reference Range</th>
                                <th>Unit</th>
                                <th>Result</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
            `;
            
            params.forEach(p => {
                const hasResult = p.numerical_value !== null || p.textual_value;
                let rowClass = '';
                let statusBadge = '';
                
                if (p.is_critical) {
                    rowClass = 'critical-row';
                    statusBadge = '<span class="badge bg-danger"><i class="fas fa-exclamation-triangle"></i> CRITICAL</span>';
                } else if (p.is_abnormal) {
                    rowClass = 'abnormal-row';
                    statusBadge = '<span class="badge bg-warning">Abnormal</span>';
                } else if (hasResult) {
                    rowClass = 'normal-row';
                    statusBadge = '<span class="badge bg-success">Entered</span>';
                } else {
                    statusBadge = '<span class="badge bg-secondary">Pending</span>';
                }
                
                let refDisplay = 'N/A';
                if (p.reference_range_text) {
                    refDisplay = p.reference_range_text;
                } else if (p.min_normal !== null && p.max_normal !== null) {
                    refDisplay = p.min_normal + ' - ' + p.max_normal;
                }
                
                let resultDisplay = 'N/A';
                if (hasResult) {
                    if (p.numerical_value !== null && p.numerical_value !== '') {
                        resultDisplay = p.numerical_value;
                    } else if (p.textual_value) {
                        resultDisplay = p.textual_value;
                    }
                }
                
                html += `
                    <tr class="${rowClass}">
                        <td><strong>${escapeHtml(p.parameter_name)}</strong></td>
                        <td class="text-muted small">${escapeHtml(refDisplay)}</td>
                        <td class="text-muted small">${escapeHtml(p.unit_of_measure || 'N/A')}</td>
                        <td><strong>${escapeHtml(resultDisplay)}</strong></td>
                        <td>${statusBadge}</td>
                    </tr>
                `;
            });
            
            html += `
                        </tbody>
                    </table>
                </div>
            `;
        }
        
        html += `</div>`;
    });
    
    html += `</div>`;
    
    const allEntered = totalParams > 0 && enteredParams === totalParams;
    const someEntered = enteredParams > 0;
    
    html += `
        <div class="action-bar">
            ${!allEntered ? `
                <button class="btn btn-primary" onclick="openResultModal()">
                    <i class="fas fa-edit"></i> ${enteredParams > 0 ? 'Continue Entering Results' : 'Enter Results'}
                </button>
            ` : `
                <button class="btn btn-success" onclick="openVerifyModal()">
                    <i class="fas fa-check-double"></i> Verify & Release Results
                </button>
            `}
            ${someEntered ? `
                <button class="btn btn-outline btn-sm" onclick="openResultModal()">
                    <i class="fas fa-edit"></i> Review/Edit Results
                </button>
            ` : ''}
            <button class="btn btn-outline btn-sm" onclick="window.open('emr_view.php?patient_id=${encounter?.patient_id}','_blank')">
                <i class="fas fa-file-medical"></i> View Record
            </button>
        </div>
    `;
    
    window._collectedPanels = panels;
    window._collectedPatient = encounter;
    window._collectedStats = { totalParams, enteredParams, criticalCount, abnormalCount };
    
    return html;
}

// ============================================================
// SECTION 3: COMPLETE - VERIFICATION VIEW
// ============================================================

function renderCompletedDetails(res) {
    const verificationData = res.verification_data || {};
    const encounter = res.encounter;
    const panels = verificationData.panels || [];
    const summary = verificationData.summary || {};
    
    let html = '';
    
    if (!panels || panels.length === 0) {
        html += `<div class="text-muted text-center py-3">No completed orders for this patient</div>`;
        html += `
            <div class="action-bar">
                <button class="btn btn-outline btn-sm" onclick="window.open('emr_view.php?patient_id=${encounter?.patient_id}','_blank')">
                    <i class="fas fa-file-medical"></i> View Record
                </button>
            </div>
        `;
        return html;
    }
    
    html += `
        <div class="mb-3">
            <h6><i class="fas fa-check-circle text-success me-2"></i>Verified Results (${panels.length} panels)</h6>
            <div class="row g-2 mb-3">
                <div class="col-3"><span class="badge bg-info">${summary.total_orders || 0} Orders</span></div>
                <div class="col-3"><span class="badge bg-primary">${summary.total_parameters || 0} Parameters</span></div>
                <div class="col-3"><span class="badge bg-danger">${summary.critical_count || 0} Critical</span></div>
                <div class="col-3"><span class="badge bg-warning">${summary.abnormal_count || 0} Abnormal</span></div>
            </div>
    `;
    
    panels.forEach(panel => {
        const params = panel.parameters || [];
        const panelCritical = params.filter(p => p.is_critical).length;
        const panelAbnormal = params.filter(p => p.is_abnormal && !p.is_critical).length;
        
        html += `
            <div class="verification-panel" style="${panelCritical > 0 ? 'border-left-color: #ef4444;' : panelAbnormal > 0 ? 'border-left-color: #f59e0b;' : ''}">
                <div class="panel-title">
                    <i class="fas fa-microscope me-2"></i>
                    ${escapeHtml(panel.panel_name)}
                    <span class="badge bg-light text-dark ms-2">${panel.order_number}</span>
                    <span class="panel-status">
                        ${getPriorityBadge(panel.priority)}
                        ${panel.order_status === 'verified' ? '<span class="badge bg-success"><i class="fas fa-check"></i> Verified</span>' : ''}
                        ${panelCritical > 0 ? '<span class="badge bg-danger"><i class="fas fa-exclamation-triangle"></i> ' + panelCritical + ' Critical</span>' : ''}
                    </span>
                </div>
                <div class="small text-muted mb-2">
                    Dr. ${escapeHtml(panel.doctor_name)} | 
                    Order: ${escapeHtml(panel.order_number)} | 
                    ${formatDate(panel.order_date)}
                </div>
        `;
        
        if (params.length > 0) {
            html += `
                <div class="table-responsive">
                    <table class="table table-sm table-bordered result-table">
                        <thead>
                            <tr>
                                <th>Parameter</th>
                                <th>Result</th>
                                <th>Unit</th>
                                <th>Reference Range</th>
                                <th>Status</th>
                                <th>Verified</th>
                            </tr>
                        </thead>
                        <tbody>
            `;
            
            params.forEach(p => {
                const hasResult = p.numerical_value !== null || p.textual_value;
                let rowClass = '';
                let statusBadge = '';
                
                if (p.is_critical) {
                    rowClass = 'critical-row';
                    statusBadge = '<span class="badge bg-danger"><i class="fas fa-exclamation-triangle"></i> CRITICAL</span>';
                } else if (p.is_abnormal) {
                    rowClass = 'abnormal-row';
                    statusBadge = '<span class="badge bg-warning">Abnormal</span>';
                } else if (hasResult) {
                    rowClass = 'normal-row';
                    statusBadge = '<span class="badge bg-success">Normal</span>';
                } else {
                    statusBadge = '<span class="badge bg-secondary">No Result</span>';
                }
                
                let refDisplay = 'N/A';
                if (p.reference_range_text) {
                    refDisplay = p.reference_range_text;
                } else if (p.min_normal !== null && p.max_normal !== null) {
                    refDisplay = p.min_normal + ' - ' + p.max_normal;
                }
                
                let resultDisplay = 'N/A';
                if (hasResult) {
                    if (p.numerical_value !== null && p.numerical_value !== '') {
                        resultDisplay = p.numerical_value;
                    } else if (p.textual_value) {
                        resultDisplay = p.textual_value;
                    }
                }
                
                const verifiedDisplay = p.verified_at ? formatDate(p.verified_at) : 'Not verified';
                
                html += `
                    <tr class="${rowClass}">
                        <td><strong>${escapeHtml(p.parameter_name)}</strong></td>
                        <td><strong class="${p.is_critical ? 'text-danger' : p.is_abnormal ? 'text-warning' : ''}">${escapeHtml(resultDisplay)}</strong></td>
                        <td class="text-muted small">${escapeHtml(p.unit_of_measure || 'N/A')}</td>
                        <td class="text-muted small">${escapeHtml(refDisplay)}</td>
                        <td>${statusBadge}</td>
                        <td class="text-muted small">${verifiedDisplay}</td>
                    </tr>
                `;
            });
            
            html += `
                        </tbody>
                    </table>
                </div>
            `;
        }
        
        html += `</div>`;
    });
    
    html += `</div>`;
    
    html += `
        <div class="action-bar">
            <button class="btn btn-outline btn-sm" onclick="printPatientSummary()">
                <i class="fas fa-print"></i> Print Summary
            </button>
            <button class="btn btn-outline btn-sm" onclick="window.open('emr_view.php?patient_id=${encounter?.patient_id}','_blank')">
                <i class="fas fa-file-medical"></i> View Record
            </button>
        </div>
    `;
    
    window._verifyData = verificationData;
    window._selectedPatient = encounter;
    
    return html;
}

// ============================================================
// CRITICAL DETAILS VIEW
// ============================================================

function renderCriticalDetails(res) {
    const panels = res.panels || [];
    const encounter = res.encounter;
    let html = '';
    let criticalParams = [];
    
    panels.forEach(panel => {
        (panel.parameters || []).forEach(p => {
            if (p.is_critical) {
                criticalParams.push({
                    ...p,
                    order_number: panel.order_number,
                    panel_name: panel.panel_name
                });
            }
        });
    });
    
    if (criticalParams.length === 0) {
        html += `<div class="text-muted text-center py-3">No critical results found</div>`;
        html += `
            <div class="action-bar">
                <button class="btn btn-outline btn-sm" onclick="window.open('emr_view.php?patient_id=${encounter?.patient_id}','_blank')">
                    <i class="fas fa-file-medical"></i> View Record
                </button>
            </div>
        `;
        return html;
    }
    
    html += `
        <div class="mb-3">
            <h6><i class="fas fa-exclamation-triangle text-danger me-2"></i>Critical Results (${criticalParams.length})</h6>
    `;
    
    criticalParams.forEach(cp => {
        html += `
            <div class="border border-danger rounded p-3 mb-3" style="background: #fef2f2;">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <strong>${escapeHtml(cp.order_number)}</strong>
                        <span class="badge bg-danger ms-2">${escapeHtml(cp.panel_name)}</span>
                    </div>
                    ${getPriorityBadge('stat')}
                </div>
                <div class="mt-2">
                    <strong>${escapeHtml(cp.parameter_name)}</strong>: 
                    <span class="text-danger fw-bold">
                        ${cp.numerical_value !== null ? cp.numerical_value : escapeHtml(cp.textual_value)}
                        ${cp.unit_of_measure ? ' ' + escapeHtml(cp.unit_of_measure) : ''}
                    </span>
                    <span class="text-muted small">
                        (Reference: ${escapeHtml(cp.reference_range_text || 'N/A')})
                    </span>
                </div>
                ${cp.technician_notes ? `
                    <div class="text-muted small mt-1">Notes: ${escapeHtml(cp.technician_notes)}</div>
                ` : ''}
                <div class="mt-2">
                    <button class="btn btn-outline-danger btn-sm" onclick="acknowledgeCriticalResult(${cp.result_id})">
                        <i class="fas fa-check"></i> Acknowledge
                    </button>
                </div>
            </div>
        `;
    });
    
    html += `</div>`;
    
    return html;
}

// ============================================================
// SECTION 1: OPEN COLLECT MODAL
// ============================================================

function openCollectModal() {
    if (isProcessing) return;
    const grouped = window._pendingGrouped || {};
    const patient = window._selectedPatient || {};
    
    if (Object.keys(grouped).length === 0) {
        showToast('No pending orders to collect', 'warning');
        return;
    }
    
    document.getElementById('collectPatientName').textContent = patient?.patient_name || 'Loading patient...';
    document.getElementById('collectMRN').textContent = patient?.mr_number || 'Loading MRN...';
    document.getElementById('collectVisitId').value = patient?.visit_id || '';
    
    let totalOrders = 0;
    let html = '';
    const specimenIcons = {
        'blood': 'fa-droplet', 'urine': 'fa-toilet', 'serum': 'fa-flask',
        'plasma': 'fa-flask', 'stool': 'fa-poop', 'csf': 'fa-brain',
        'swab': 'fa-swab', 'sputum': 'fa-lungs'
    };
    const specimenColors = {
        'blood': '#dc2626', 'urine': '#f59e0b', 'serum': '#f97316',
        'plasma': '#8b5cf6', 'stool': '#92400e', 'csf': '#06b6d4',
        'swab': '#6366f1', 'sputum': '#14b8a6'
    };
    
    for (const [specimenType, group] of Object.entries(grouped)) {
        const orders = group.orders || [];
        if (orders.length === 0) continue;
        totalOrders += orders.length;
        const config = group.config || {};
        const icon = specimenIcons[specimenType] || 'fa-vial';
        const color = specimenColors[specimenType] || '#6b7280';
        
        html += `
            <div class="specimen-section" style="border-left-color: ${color};">
                <div class="section-title">
                    <i class="fas ${icon} specimen-icon" style="color: ${color};"></i>
                    ${escapeHtml(specimenType.charAt(0).toUpperCase() + specimenType.slice(1))} Collection
                    <span class="badge bg-secondary ms-2">${orders.length} order${orders.length > 1 ? 's' : ''}</span>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm table-bordered">
                        <thead>
                            <tr>
                                <th><input type="checkbox" class="specimen-select-all" data-specimen="${specimenType}" checked></th>
                                <th>Order #</th>
                                <th>Test</th>
                                <th>Priority</th>
                                <th>Ordered By</th>
                            </tr>
                        </thead>
                        <tbody>
        `;
        
        orders.forEach(order => {
            html += `
                <tr>
                    <td><input type="checkbox" class="order-collect-checkbox" data-specimen="${specimenType}" value="${order.order_id}" checked></td>
                    <td>${escapeHtml(order.order_number)}</td>
                    <td>${escapeHtml(order.test_name)} <small class="text-muted">(${escapeHtml(order.test_code)})</small></td>
                    <td>${getPriorityBadge(order.priority)}</td>
                    <td>Dr. ${escapeHtml(order.doctor_name)}</td>
                </tr>
            `;
        });
        
        html += `
                        </tbody>
                    </table>
                </div>
                <div class="row g-2 mt-2">
                    ${(config.fields || []).map(field => {
                        let inputHtml = '';
                        if (field.type === 'select') {
                            inputHtml = `<select class="form-control form-control-sm" id="collect_${specimenType}_${field.name}">`;
                            if (field.options) {
                                field.options.forEach(opt => {
                                    inputHtml += `<option value="${escapeHtml(opt)}">${escapeHtml(opt)}</option>`;
                                });
                            }
                            inputHtml += `</select>`;
                        } else if (field.type === 'number') {
                            inputHtml = `<input type="number" step="${field.step || '0.1'}" class="form-control form-control-sm" id="collect_${specimenType}_${field.name}" placeholder="${escapeHtml(field.label)}">`;
                        } else {
                            inputHtml = `<input type="text" class="form-control form-control-sm" id="collect_${specimenType}_${field.name}" placeholder="${escapeHtml(field.label)}">`;
                        }
                        return `
                            <div class="col-md-3">
                                <label class="small text-muted">${escapeHtml(field.label)}</label>
                                ${inputHtml}
                            </div>
                        `;
                    }).join('')}
                </div>
            </div>
        `;
    }
    
    document.getElementById('collectGroupsList').innerHTML = html;
    document.getElementById('collectOrderCount').textContent = totalOrders + ' orders';
    document.getElementById('collectNotes').value = '';
    
    // Handle "select all" checkboxes
    document.querySelectorAll('.specimen-select-all').forEach(cb => {
        cb.addEventListener('change', function() {
            const specimen = this.dataset.specimen;
            document.querySelectorAll(`.order-collect-checkbox[data-specimen="${specimen}"]`).forEach(c => {
                c.checked = this.checked;
            });
        });
    });
    
    openModal('collectModal');
}

// Collect Form Submit
$('#collectForm').submit(function(e) {
    e.preventDefault();
    if (isProcessing) return;
    
    const visitId = document.getElementById('collectVisitId').value;
    const notes = document.getElementById('collectNotes').value;
    const collectionData = {};
    
    const specimens = new Set();
    document.querySelectorAll('.order-collect-checkbox:checked').forEach(cb => {
        specimens.add(cb.dataset.specimen);
    });
    
    specimens.forEach(specimen => {
        const checkboxes = document.querySelectorAll(`.order-collect-checkbox[data-specimen="${specimen}"]:checked`);
        if (checkboxes.length === 0) return;
        
        const orders = [];
        checkboxes.forEach(cb => {
            const orderId = parseInt(cb.value);
            const fields = {};
            const config = window._pendingGrouped?.[specimen]?.config || {};
            (config.fields || []).forEach(field => {
                const input = document.getElementById(`collect_${specimen}_${field.name}`);
                if (input) fields[field.name] = input.value;
            });
            orders.push({ order_id: orderId, fields: fields });
        });
        
        if (orders.length > 0) {
            collectionData[specimen] = orders;
        }
    });
    
    if (Object.keys(collectionData).length === 0) {
        showToast('Please select at least one specimen to collect', 'warning');
        return;
    }
    
    isProcessing = true;
    showLoading(true);
    $.post(AJAX_URL, {
        action: 'bulk_collect_specimens',
        visit_id: visitId,
        collection_data: JSON.stringify(collectionData),
        notes: notes,
        csrf_token: csrf
    }, function(res) {
        showLoading(false);
        isProcessing = false;
        if (res.success) {
            closeModal('collectModal');
            showToast(res.message);
            fetchGroupedOrders();
            if (currentVisitId) selectPatient(currentVisitId);
        } else {
            showToast(res.message || 'Error', 'error');
        }
    }).fail(function() {
        showLoading(false);
        isProcessing = false;
        showToast('Network error', 'error');
    });
});

// ============================================================
// SECTION 2: OPEN RESULT MODAL WITH AUTO-SAVE
// ============================================================

function openResultModal() {
    if (isProcessing) return;
    const panels = window._collectedPanels || [];
    const patient = window._collectedPatient || {};
    
    if (!panels || panels.length === 0) {
        showToast('No collected orders to enter results for', 'warning');
        return;
    }
    
    document.getElementById('resultPatientName').textContent = patient?.patient_name || 'Loading patient...';
    document.getElementById('resultMRN').textContent = patient?.mr_number || 'Loading MRN...';
    document.getElementById('resultVisitId').value = patient?.visit_id || '';
    document.getElementById('resultPanelCount').textContent = panels.length + ' panels';
    
    // Check for existing draft
    checkDraftStatus(patient?.visit_id, function(hasDraft, draftData) {
        let html = '';
        let totalParams = 0;
        let enteredParams = 0;
        let criticalCount = 0;
        let abnormalCount = 0;
        
        panels.forEach((panel, panelIdx) => {
            const params = panel.parameters || [];
            if (params.length === 0) return;
            
            const allEntered = params.every(p => p.numerical_value !== null || p.textual_value);
            const someEntered = params.some(p => p.numerical_value !== null || p.textual_value);
            const panelCritical = params.filter(p => p.is_critical).length;
            const panelAbnormal = params.filter(p => p.is_abnormal && !p.is_critical).length;
            
            totalParams += params.length;
            enteredParams += params.filter(p => p.numerical_value !== null || p.textual_value).length;
            criticalCount += panelCritical;
            abnormalCount += panelAbnormal;
            
            html += `
                <div class="result-panel" style="${panelCritical > 0 ? 'border-left-color: #ef4444;' : ''}">
                    <div class="panel-title">
                        <i class="fas fa-microscope me-2"></i>
                        ${escapeHtml(panel.panel_name)}
                        <span class="badge bg-light text-dark ms-2">${escapeHtml(panel.order_number)}</span>
                        <span class="panel-status">
                            ${getPriorityBadge(panel.priority)}
                            ${allEntered ? '<span class="badge bg-success"><i class="fas fa-check"></i> Complete</span>' : ''}
                            ${someEntered && !allEntered ? '<span class="badge bg-warning">Partial</span>' : ''}
                            ${!someEntered ? '<span class="badge bg-secondary">Pending</span>' : ''}
                            ${panelCritical > 0 ? '<span class="badge bg-danger"><i class="fas fa-exclamation-triangle"></i> ' + panelCritical + ' Critical</span>' : ''}
                        </span>
                    </div>
                    <div class="small text-muted mb-2">
                        Specimen: ${escapeHtml(panel.specimen_type || 'N/A')} | 
                        Collected: ${formatDate(panel.specimen_collected_at)} | 
                        Dr. ${escapeHtml(panel.doctor_name)}
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered result-table">
                            <thead>
                                <tr>
                                    <th style="width:20%;">Parameter</th>
                                    <th style="width:20%;">Reference Range</th>
                                    <th style="width:10%;">Unit</th>
                                    <th style="width:25%;">Numeric Result</th>
                                    <th style="width:25%;">Text Result</th>
                                </tr>
                            </thead>
                            <tbody>
            `;
            
            params.forEach((p, pIdx) => {
                const paramKey = `${panel.order_id}_${p.parameter_id}`;
                
                // Use draft values if available
                let numValue = p.numerical_value !== null ? p.numerical_value : '';
                let textValue = p.textual_value || '';
                
                if (hasDraft && draftData && draftData[paramKey]) {
                    if (draftData[paramKey].numerical_value !== undefined) {
                        numValue = draftData[paramKey].numerical_value;
                    }
                    if (draftData[paramKey].textual_value !== undefined) {
                        textValue = draftData[paramKey].textual_value;
                    }
                }
                
                let refDisplay = 'N/A';
                if (p.reference_range_text) {
                    refDisplay = p.reference_range_text;
                } else if (p.min_normal !== null && p.max_normal !== null) {
                    refDisplay = p.min_normal + ' - ' + p.max_normal;
                }
                
                const hasResult = numValue !== '' || textValue !== '';
                let rowClass = '';
                if (p.is_critical) rowClass = 'critical-row';
                else if (p.is_abnormal) rowClass = 'abnormal-row';
                else if (hasResult) rowClass = 'normal-row';
                
                html += `
                    <tr class="${rowClass}">
                        <td>
                            <strong>${escapeHtml(p.parameter_name)}</strong>
                            ${p.is_critical ? ' <span class="badge bg-danger">CRITICAL</span>' : ''}
                            ${p.is_abnormal && !p.is_critical ? ' <span class="badge bg-warning">Abnormal</span>' : ''}
                        </td>
                        <td class="text-muted small">${escapeHtml(refDisplay)}</td>
                        <td class="text-muted small">${escapeHtml(p.unit_of_measure || 'N/A')}</td>
                        <td>
                            <input type="number" step="0.001" class="form-control form-control-sm bulk-result-input" 
                                   id="result_num_${paramKey}" 
                                   value="${numValue}" 
                                   data-order-id="${panel.order_id}"
                                   data-parameter-id="${p.parameter_id}"
                                   data-parameter-code="${escapeHtml(p.parameter_code || '')}"
                                   data-result-id="${p.result_id || ''}"
                                   onchange="validateResultInput('${paramKey}'); autoSaveDraft()"
                                   oninput="validateResultInput('${paramKey}'); autoSaveDraft()"
                                   style="width:100%;">
                        </td>
                        <td>
                            <input type="text" class="form-control form-control-sm bulk-result-input" 
                                   id="result_text_${paramKey}" 
                                   value="${escapeHtml(textValue)}"
                                   data-order-id="${panel.order_id}"
                                   data-parameter-id="${p.parameter_id}"
                                   data-result-id="${p.result_id || ''}"
                                   onchange="validateResultInput('${paramKey}'); autoSaveDraft()"
                                   oninput="validateResultInput('${paramKey}'); autoSaveDraft()"
                                   style="width:100%;">
                        </td>
                    </tr>
                `;
            });
            
            html += `
                            </tbody>
                        </table>
                    </div>
                </div>
            `;
        });
        
        document.getElementById('resultPanelsList').innerHTML = html;
        document.getElementById('resultNotes').value = '';
        document.getElementById('resultCriticalCount').textContent = criticalCount;
        document.getElementById('resultCriticalCountDisplay').textContent = criticalCount;
        document.getElementById('resultAbnormalCount').textContent = abnormalCount;
        document.getElementById('resultProgress').textContent = enteredParams + '/' + totalParams + ' results entered';
        document.getElementById('criticalAlertBannerResult').style.display = criticalCount > 0 ? 'block' : 'none';
        
        window._resultData = { totalParams, enteredParams, criticalCount, abnormalCount, panels };
        window._currentVisitId = patient?.visit_id;
        
        openModal('resultModal');
    });
}

function checkDraftStatus(visitId, callback) {
    if (!visitId) {
        callback(false, null);
        return;
    }
    
    $.post(AJAX_URL, {
        action: 'get_draft_status',
        visit_id: visitId,
        csrf_token: csrf
    }, function(res) {
        if (res.success && res.has_draft) {
            callback(true, res.draft.data);
        } else {
            callback(false, null);
        }
    }).fail(function() {
        callback(false, null);
    });
}

function autoSaveDraft() {
    if (autoSaveTimer) {
        clearTimeout(autoSaveTimer);
    }
    
    const indicator = document.getElementById('autoSaveIndicator');
    indicator.className = 'auto-save-indicator saving';
    indicator.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
    
    autoSaveTimer = setTimeout(function() {
        const visitId = document.getElementById('resultVisitId').value;
        if (!visitId) return;
        
        const draftData = {};
        const inputs = document.querySelectorAll('.bulk-result-input');
        inputs.forEach(input => {
            const key = input.id.replace('result_num_', '').replace('result_text_', '');
            if (!draftData[key]) {
                draftData[key] = {};
            }
            if (input.id.startsWith('result_num_')) {
                draftData[key].numerical_value = input.value;
            } else if (input.id.startsWith('result_text_')) {
                draftData[key].textual_value = input.value;
            }
        });
        
        // Count entered results
        let entered = 0;
        document.querySelectorAll('.bulk-result-input').forEach(inp => {
            if (inp.value.trim() !== '') entered++;
        });
        const total = window._resultData?.totalParams || 1;
        document.getElementById('resultProgress').textContent = entered + '/' + total + ' results entered';
        
        $.post(AJAX_URL, {
            action: 'auto_save_draft',
            visit_id: visitId,
            draft_data: JSON.stringify(draftData),
            csrf_token: csrf
        }, function(res) {
            if (res.success) {
                indicator.className = 'auto-save-indicator saved';
                indicator.innerHTML = '<i class="fas fa-check-circle text-success"></i> Saved';
                setTimeout(() => {
                    indicator.className = 'auto-save-indicator';
                    indicator.innerHTML = '<i class="fas fa-circle text-muted"></i> Auto-save ready';
                }, 2000);
            } else {
                indicator.className = 'auto-save-indicator';
                indicator.innerHTML = '<i class="fas fa-exclamation-circle text-danger"></i> Save failed';
            }
        }).fail(function() {
            indicator.className = 'auto-save-indicator';
            indicator.innerHTML = '<i class="fas fa-exclamation-circle text-danger"></i> Save failed';
        });
    }, 1000);
}

function validateResultInput(paramKey) {
    const numInput = document.getElementById('result_num_' + paramKey);
    const textInput = document.getElementById('result_text_' + paramKey);
    const numValue = numInput ? numInput.value.trim() : '';
    const textValue = textInput ? textInput.value.trim() : '';
    
    if (numInput) {
        const row = numInput.closest('tr');
        if (row) {
            if (numValue !== '' || textValue !== '') {
                row.className = 'table-success';
            } else {
                row.className = '';
            }
        }
    }
    
    // Update progress
    let entered = 0;
    document.querySelectorAll('.bulk-result-input').forEach(input => {
        if (input.value.trim() !== '') entered++;
    });
    const total = window._resultData?.totalParams || 1;
    document.getElementById('resultProgress').textContent = entered + '/' + total + ' results entered';
}

// Result Form Submit
$('#resultForm').submit(function(e) {
    e.preventDefault();
    if (isProcessing) return;
    
    const visitId = document.getElementById('resultVisitId').value;
    const notes = document.getElementById('resultNotes').value;
    const resultsData = [];
    
    const numInputs = document.querySelectorAll('[id^="result_num_"]');
    numInputs.forEach(input => {
        const numValue = input.value.trim();
        const key = input.id.replace('result_num_', '');
        const textInput = document.getElementById('result_text_' + key);
        const textValue = textInput ? textInput.value.trim() : '';
        
        if (numValue !== '' || textValue !== '') {
            resultsData.push({
                order_id: parseInt(input.dataset.orderId || 0),
                parameter_id: parseInt(input.dataset.parameterId || 0),
                parameter_code: input.dataset.parameterCode || '',
                numerical_value: numValue !== '' ? parseFloat(numValue) : null,
                textual_value: textValue
            });
        }
    });
    
    if (resultsData.length === 0) {
        showToast('No results to save', 'warning');
        return;
    }
    
    isProcessing = true;
    showLoading(true);
    $.post(AJAX_URL, {
        action: 'bulk_save_results',
        visit_id: visitId,
        results_data: JSON.stringify(resultsData),
        technician_notes: notes,
        csrf_token: csrf
    }, function(res) {
        showLoading(false);
        isProcessing = false;
        if (res.success) {
            // Clear draft
            $.post(AJAX_URL, {
                action: 'auto_save_draft',
                visit_id: visitId,
                draft_data: JSON.stringify({}),
                csrf_token: csrf
            });
            
            closeModal('resultModal');
            showToast(res.message);
            fetchGroupedOrders();
            if (currentVisitId) selectPatient(currentVisitId);
        } else {
            showToast(res.message || 'Error', 'error');
        }
    }).fail(function() {
        showLoading(false);
        isProcessing = false;
        showToast('Network error', 'error');
    });
});

// ============================================================
// SECTION 3: OPEN VERIFY MODAL
// ============================================================

function openVerifyModal() {
    if (isProcessing) return;
    
    // Fetch fresh verification data
    const visitId = document.getElementById('resultVisitId')?.value || currentVisitId;
    if (!visitId) {
        showToast('Invalid visit ID', 'error');
        return;
    }
    
    showLoading(true);
    $.post(AJAX_URL, {
        action: 'get_verification_summary',
        visit_id: visitId,
        csrf_token: csrf
    }, function(res) {
        showLoading(false);
        if (res.success) {
            renderVerifyModal(res);
            openModal('verifyModal');
        } else {
            showToast(res.message || 'Error loading verification data', 'error');
        }
    }).fail(function() {
        showLoading(false);
        showToast('Network error', 'error');
    });
}

function renderVerifyModal(res) {
    const encounter = res.encounter;
    const verificationData = res.verification_data || {};
    const panels = verificationData.panels || [];
    const summary = verificationData.summary || {};
    
    if (!panels || panels.length === 0) {
        showToast('No orders ready for verification', 'warning');
        return;
    }
    
    document.getElementById('verifyVisitId').value = encounter?.visit_id || '';
    document.getElementById('verifyPatientInfo').innerHTML = `
        <strong>${escapeHtml(encounter?.patient_name || 'N/A')}</strong>
        <span class="text-muted">(MRN: ${escapeHtml(encounter?.mr_number || 'N/A')})</span>
        <span class="text-muted">Visit: ${escapeHtml(encounter?.visit_number || 'N/A')}</span>
    `;
    document.getElementById('verifyNotes').value = '';
    
    document.getElementById('verifyTotalOrders').textContent = summary.total_orders || 0;
    document.getElementById('verifyTotalParams').textContent = summary.total_parameters || 0;
    document.getElementById('verifyCriticalCount').textContent = summary.critical_count || 0;
    document.getElementById('verifyAbnormalCount').textContent = summary.abnormal_count || 0;
    
    let html = `
        <div class="alert alert-info">
            <i class="fas fa-info-circle"></i> 
            You are about to verify <strong>${panels.length}</strong> panel(s) for this patient.
            ${summary.critical_count > 0 ? ' <span class="text-danger">⚠️ Critical results detected!</span>' : ''}
        </div>
    `;
    
    panels.forEach(panel => {
        const params = panel.parameters || [];
        const panelCritical = params.filter(p => p.is_critical).length;
        const panelAbnormal = params.filter(p => p.is_abnormal && !p.is_critical).length;
        const allVerified = params.every(p => p.verified_at !== null);
        
        html += `
            <div class="verification-panel" style="${panelCritical > 0 ? 'border-left-color: #ef4444;' : panelAbnormal > 0 ? 'border-left-color: #f59e0b;' : ''}">
                <div class="panel-title">
                    <i class="fas fa-microscope me-2"></i>
                    ${escapeHtml(panel.panel_name)}
                    <span class="badge bg-light text-dark ms-2">${escapeHtml(panel.order_number)}</span>
                    <span class="panel-status">
                        ${getPriorityBadge(panel.priority)}
                        ${allVerified ? '<span class="badge bg-success"><i class="fas fa-check"></i> Verified</span>' : ''}
                        ${panelCritical > 0 ? '<span class="badge bg-danger"><i class="fas fa-exclamation-triangle"></i> ' + panelCritical + ' Critical</span>' : ''}
                    </span>
                </div>
                <div class="small text-muted mb-2">
                    Dr. ${escapeHtml(panel.doctor_name)} | ${formatDate(panel.order_date)}
                </div>
                <div class="table-responsive">
                    <table class="table table-sm table-bordered result-table">
                        <thead>
                            <tr>
                                <th>Parameter</th>
                                <th>Result</th>
                                <th>Unit</th>
                                <th>Reference Range</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
        `;
        
        params.forEach(p => {
            const hasResult = p.numerical_value !== null || p.textual_value;
            let rowClass = '';
            let statusDisplay = '';
            
            if (p.is_critical) {
                rowClass = 'critical-row';
                statusDisplay = '<span class="badge bg-danger"><i class="fas fa-exclamation-triangle"></i> CRITICAL</span>';
            } else if (p.is_abnormal) {
                rowClass = 'abnormal-row';
                statusDisplay = '<span class="badge bg-warning">Abnormal</span>';
            } else if (hasResult) {
                rowClass = 'normal-row';
                statusDisplay = '<span class="badge bg-success">Normal</span>';
            } else {
                statusDisplay = '<span class="badge bg-secondary">No Result</span>';
            }
            
            let refDisplay = 'N/A';
            if (p.reference_range_text) {
                refDisplay = p.reference_range_text;
            } else if (p.min_normal !== null && p.max_normal !== null) {
                refDisplay = p.min_normal + ' - ' + p.max_normal;
            }
            
            let resultDisplay = 'N/A';
            if (hasResult) {
                if (p.numerical_value !== null && p.numerical_value !== '') {
                    resultDisplay = p.numerical_value;
                } else if (p.textual_value) {
                    resultDisplay = p.textual_value;
                }
            }
            
            html += `
                <tr class="${rowClass}">
                    <td><strong>${escapeHtml(p.parameter_name)}</strong></td>
                    <td><strong class="${p.is_critical ? 'text-danger' : p.is_abnormal ? 'text-warning' : ''}">${escapeHtml(resultDisplay)}</strong></td>
                    <td class="text-muted small">${escapeHtml(p.unit_of_measure || 'N/A')}</td>
                    <td class="text-muted small">${escapeHtml(refDisplay)}</td>
                    <td>${statusDisplay}</td>
                </tr>
            `;
        });
        
        html += `
                        </tbody>
                    </table>
                </div>
            </div>
        `;
    });
    
    document.getElementById('verifyPanelsList').innerHTML = html;
}

// Verify Submit
$('#verifySubmitBtn').click(function() {
    if (isProcessing) return;
    
    const visitId = document.getElementById('verifyVisitId').value;
    const notes = document.getElementById('verifyNotes').value;
    
    if (!visitId) {
        showToast('Invalid visit ID', 'error');
        return;
    }
    
    Swal.fire({
        title: 'Verify & Release All Results?',
        html: 'This will verify all results for this encounter and notify the ordering doctors.<br><br>Are you sure all results are correct?',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#10b981',
        confirmButtonText: 'Yes, Verify All',
        cancelButtonText: 'Cancel'
    }).then(result => {
        if (result.isConfirmed) {
            executeVerify(visitId, notes);
        }
    });
});

function executeVerify(visitId, notes) {
    isProcessing = true;
    showLoading(true);
    $.post(AJAX_URL, {
        action: 'bulk_verify_results',
        visit_id: visitId,
        verify_notes: notes,
        csrf_token: csrf
    }, function(res) {
        showLoading(false);
        isProcessing = false;
        if (res.success) {
            closeModal('verifyModal');
            showToast(res.message);
            fetchGroupedOrders();
            if (currentVisitId) selectPatient(currentVisitId);
        } else {
            showToast(res.message || 'Error', 'error');
        }
    }).fail(function() {
        showLoading(false);
        isProcessing = false;
        showToast('Network error', 'error');
    });
}

// ============================================================
// ACKNOWLEDGE CRITICAL RESULT
// ============================================================

function acknowledgeCriticalResult(resultId) {
    if (isProcessing) return;
    
    Swal.fire({
        title: 'Acknowledge Critical Result',
        input: 'textarea',
        inputLabel: 'Notes',
        inputPlaceholder: 'Add any notes about this critical result...',
        showCancelButton: true,
        confirmButtonText: 'Acknowledge',
        confirmButtonColor: '#ef4444'
    }).then(result => {
        if (result.isConfirmed) {
            const notes = result.value || '';
            executeAcknowledgeCritical(resultId, notes);
        }
    });
}

function executeAcknowledgeCritical(resultId, notes) {
    isProcessing = true;
    showLoading(true);
    $.post(AJAX_URL, {
        action: 'acknowledge_alert',
        result_id: resultId,
        notes: notes,
        csrf_token: csrf
    }, function(res) {
        showLoading(false);
        isProcessing = false;
        if (res.success) {
            showToast('Critical result acknowledged');
            if (currentVisitId) selectPatient(currentVisitId);
        } else {
            showToast(res.message || 'Error', 'error');
        }
    }).fail(function() {
        showLoading(false);
        isProcessing = false;
        showToast('Network error', 'error');
    });
}

// ============================================================
// PRINT PATIENT SUMMARY
// ============================================================

function printPatientSummary() {
    const visitId = document.getElementById('verifyVisitId')?.value || currentVisitId;
    if (!visitId) {
        showToast('No patient selected', 'error');
        return;
    }
    
    showLoading(true);
    $.post(AJAX_URL, {
        action: 'get_verification_summary',
        visit_id: visitId,
        csrf_token: csrf
    }, function(res) {
        showLoading(false);
        if (res.success) {
            const encounter = res.encounter;
            const verificationData = res.verification_data || {};
            const panels = verificationData.panels || [];
            const summary = verificationData.summary || {};
            
            const w = window.open('', '_blank', 'width=800,height=600');
            
            w.document.write(`
                <!DOCTYPE html>
                <html>
                <head>
                    <title>Patient Lab Summary</title>
                    <style>
                        body { font-family: Arial; padding: 30px; }
                        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
                        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
                        th { background: #f0f0f0; }
                        .header { text-align: center; border-bottom: 2px solid #333; padding-bottom: 20px; margin-bottom: 20px; }
                        .patient-info { margin-bottom: 20px; }
                        .critical { color: #dc2626; font-weight: bold; }
                        .abnormal { color: #f59e0b; }
                        .normal { color: #10b981; }
                        .panel-section { margin-top: 20px; border: 1px solid #ddd; padding: 15px; border-radius: 8px; }
                        .panel-title { font-weight: bold; font-size: 16px; margin-bottom: 10px; background: #f8fafc; padding: 8px 12px; }
                        .summary-stats { display: flex; gap: 20px; margin: 15px 0; flex-wrap: wrap; }
                        .stat-box { padding: 10px 20px; background: #f8fafc; border-radius: 8px; }
                        .stat-box .number { font-size: 24px; font-weight: bold; }
                        .stat-box .label { font-size: 12px; color: #666; }
                    </style>
                </head>
                <body>
                    <div class="header">
                        <h2>Patient Laboratory Summary</h2>
                        <p>Generated: ${new Date().toLocaleString()}</p>
                    </div>
                    <div class="patient-info">
                        <h3>Patient Information</h3>
                        <p><strong>Name:</strong> ${escapeHtml(encounter?.patient_name || 'N/A')}</p>
                        <p><strong>MRN:</strong> ${escapeHtml(encounter?.mr_number || 'N/A')}</p>
                        <p><strong>Gender:</strong> ${escapeHtml(encounter?.gender || 'N/A')}</p>
                        <p><strong>Visit:</strong> ${escapeHtml(encounter?.visit_number || 'N/A')}</p>
                    </div>
                    <div class="summary-stats">
                        <div class="stat-box"><span class="number">${summary.total_orders || 0}</span><div class="label">Orders</div></div>
                        <div class="stat-box"><span class="number">${summary.total_parameters || 0}</span><div class="label">Parameters</div></div>
                        <div class="stat-box"><span class="number text-danger">${summary.critical_count || 0}</span><div class="label">Critical</div></div>
                        <div class="stat-box"><span class="number text-warning">${summary.abnormal_count || 0}</span><div class="label">Abnormal</div></div>
                    </div>
            `);
            
            if (panels && panels.length > 0) {
                panels.forEach(panel => {
                    const params = panel.parameters || [];
                    const panelCritical = params.filter(p => p.is_critical).length;
                    
                    w.document.write(`
                        <div class="panel-section">
                            <div class="panel-title">
                                ${escapeHtml(panel.panel_name)} 
                                <span style="font-weight:normal;font-size:12px;color:#666;">(${escapeHtml(panel.order_number)})</span>
                                ${panelCritical > 0 ? '<span style="color:#dc2626;font-weight:bold;"> ⚠️ CRITICAL</span>' : ''}
                            </div>
                            <table>
                                <thead>
                                    <tr>
                                        <th>Parameter</th>
                                        <th>Result</th>
                                        <th>Unit</th>
                                        <th>Reference Range</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                    `);
                    
                    params.forEach(p => {
                        const hasResult = p.numerical_value !== null || p.textual_value;
                        let statusText = 'No Result';
                        let statusClass = '';
                        
                        if (p.is_critical) {
                            statusText = '⚠️ CRITICAL';
                            statusClass = 'critical';
                        } else if (p.is_abnormal) {
                            statusText = 'Abnormal';
                            statusClass = 'abnormal';
                        } else if (hasResult) {
                            statusText = 'Normal';
                            statusClass = 'normal';
                        }
                        
                        let refDisplay = 'N/A';
                        if (p.reference_range_text) {
                            refDisplay = p.reference_range_text;
                        } else if (p.min_normal !== null && p.max_normal !== null) {
                            refDisplay = p.min_normal + ' - ' + p.max_normal;
                        }
                        
                        let resultDisplay = 'N/A';
                        if (hasResult) {
                            if (p.numerical_value !== null && p.numerical_value !== '') {
                                resultDisplay = p.numerical_value;
                            } else if (p.textual_value) {
                                resultDisplay = p.textual_value;
                            }
                        }
                        
                        w.document.write(`
                            <tr>
                                <td><strong>${escapeHtml(p.parameter_name)}</strong></td>
                                <td><span class="${statusClass}">${escapeHtml(resultDisplay)}</span></td>
                                <td>${escapeHtml(p.unit_of_measure || 'N/A')}</td>
                                <td>${escapeHtml(refDisplay)}</td>
                                <td>${statusText}</td>
                            </tr>
                        `);
                    });
                    
                    w.document.write(`
                                </tbody>
                            </table>
                        </div>
                    `);
                });
            } else {
                w.document.write('<p>No completed orders found.</p>');
            }
            
            w.document.write(`
                    <p style="margin-top: 30px; text-align: center; color: #666;">
                        This is a system-generated report. For official use only.
                    </p>
                </body>
                </html>
            `);
            w.document.close();
            w.print();
        } else {
            showToast(res.message || 'Error generating summary', 'error');
        }
    }).fail(function() {
        showLoading(false);
        showToast('Network error', 'error');
    });
}

// ============================================================
// BARCODE SEARCH
// ============================================================

function openBarcodeSearch() {
    document.getElementById('barcodeInput').value = '';
    document.getElementById('barcodeResult').innerHTML = '';
    openModal('barcodeModal');
    setTimeout(() => document.getElementById('barcodeInput').focus(), 300);
}

$('#barcodeSearchBtn').click(searchBarcode);
$('#barcodeInput').on('keypress', function(e) {
    if (e.key === 'Enter') { e.preventDefault(); searchBarcode(); }
});

function searchBarcode() {
    const barcode = document.getElementById('barcodeInput').value.trim();
    if (!barcode) { showToast('Enter a barcode', 'error'); return; }
    
    showLoading(true);
    $.post(AJAX_URL, {
        action: 'search_barcode',
        barcode: barcode,
        csrf_token: csrf
    }, function(res) {
        showLoading(false);
        if (res.success && res.order) {
            const o = res.order;
            document.getElementById('barcodeResult').innerHTML = `
                <div class="alert alert-success">
                    <strong>Found!</strong>
                    <div class="mt-2">
                        <p><strong>Order:</strong> ${escapeHtml(o.order_number)}</p>
                        <p><strong>Patient:</strong> ${escapeHtml(o.patient_name)} (${escapeHtml(o.mr_number)})</p>
                        <p><strong>Status:</strong> ${getStatusBadge(o.status)}</p>
                        <p><strong>Specimen:</strong> ${escapeHtml(o.specimen_type)}</p>
                        <p><strong>Collected:</strong> ${formatDate(o.collected_at)}</p>
                    </div>
                    <button class="btn btn-primary btn-sm mt-2" onclick="selectPatient(${o.visit_id}); closeModal('barcodeModal');">
                        View Patient
                    </button>
                    <button class="btn btn-outline btn-sm mt-2" onclick="window.open('emr_view.php?patient_id=${o.patient_id}','_blank')">
                        <i class="fas fa-file-medical"></i> View Record
                    </button>
                </div>
            `;
        } else {
            document.getElementById('barcodeResult').innerHTML = `
                <div class="alert alert-danger">
                    No specimen found with barcode: <strong>${escapeHtml(barcode)}</strong>
                </div>
            `;
        }
    }).fail(function() {
        showLoading(false);
        showToast('Network error', 'error');
    });
}

// ============================================================
// EXPORT FUNCTIONALITY
// ============================================================

function openExportModal() {
    openModal('exportModal');
}

$('#exportBtn').click(function() {
    const dateFrom = document.getElementById('exportDateFrom').value;
    const dateTo = document.getElementById('exportDateTo').value;
    const exportType = document.getElementById('exportType').value;
    
    if (!dateFrom || !dateTo) {
        showToast('Please select a date range', 'error');
        return;
    }
    
    showLoading(true);
    $.post('export_lab_data.php', {
        date_from: dateFrom,
        date_to: dateTo,
        export_type: exportType,
        csrf_token: csrf
    }, function(res) {
        showLoading(false);
        if (res.success) {
            window.location.href = res.file_url;
            showToast('Export generated successfully');
            closeModal('exportModal');
        } else {
            showToast(res.message || 'Export failed', 'error');
        }
    }).fail(function() {
        showLoading(false);
        showToast('Network error', 'error');
    });
});

// ============================================================
// CRITICAL ALERTS
// ============================================================

function fetchCriticalAlerts() {
    switchFilter('critical');
}

// ============================================================
// FETCH STATS
// ============================================================

function fetchStats() {
    $.post(AJAX_URL, {
        action: 'fetch_stats',
        csrf_token: csrf
    }, function(res) {
        if (res.success && res.stats) {
            document.getElementById('statToday').textContent = res.stats.today_orders || 0;
            document.getElementById('statPending').textContent = res.stats.pending_orders || 0;
            document.getElementById('statCollected').textContent = res.stats.collected_orders || 0;
            document.getElementById('statCompleted').textContent = res.stats.completed_orders || 0;
            document.getElementById('statCritical').textContent = res.stats.critical_alerts || 0;
        }
    }).fail(function() { /* silent fail */ });
}

// ============================================================
// SIDEBAR TOGGLE
// ============================================================

function toggleSidebar() {
    const sidebar = document.getElementById('sidebar-wrapper');
    const overlay = document.getElementById('sidebarOverlay');
    const content = document.getElementById('page-content-wrapper');
    const isMobile = window.innerWidth <= 992;
    
    if (isMobile) {
        sidebar.classList.toggle('active');
        overlay.classList.toggle('active');
        document.body.style.overflow = sidebar.classList.contains('active') ? 'hidden' : '';
    } else {
        sidebar.classList.toggle('active');
        content.classList.toggle('with-sidebar');
    }
}

function initSidebar() {
    const sidebar = document.getElementById('sidebar-wrapper');
    const content = document.getElementById('page-content-wrapper');
    const isMobile = window.innerWidth <= 992;
    if (!isMobile) {
        sidebar.classList.add('active');
        content.classList.add('with-sidebar');
    } else {
        sidebar.classList.remove('active');
        content.classList.remove('with-sidebar');
    }
}

window.addEventListener('resize', function() {
    const sidebar = document.getElementById('sidebar-wrapper');
    const overlay = document.getElementById('sidebarOverlay');
    const content = document.getElementById('page-content-wrapper');
    const isMobile = window.innerWidth <= 992;
    if (!isMobile) {
        sidebar.classList.add('active');
        content.classList.add('with-sidebar');
        overlay.classList.remove('active');
        document.body.style.overflow = '';
    } else {
        if (!sidebar.classList.contains('active')) {
            content.classList.remove('with-sidebar');
        }
        overlay.classList.remove('active');
        document.body.style.overflow = '';
    }
});

document.getElementById('sidebarOverlay')?.addEventListener('click', function() {
    document.getElementById('sidebar-wrapper').classList.remove('active');
    this.classList.remove('active');
    document.body.style.overflow = '';
});

// ============================================================
// INITIALIZATION
// ============================================================

$(document).ready(function() {
    const modalIds = ['collectModal', 'resultModal', 'verifyModal', 'barcodeModal', 'criticalModal', 'exportModal'];
    modalIds.forEach(id => {
        const el = document.getElementById(id);
        if (el) bsModals[id] = new bootstrap.Modal(el);
    });
    
    fetchGroupedOrders();
    fetchStats();
    
    pollInterval = setInterval(function() {
        if (!document.hidden) {
            fetchGroupedOrders();
            fetchStats();
        }
    }, 30000);
    
    document.getElementById('criticalStatCard')?.addEventListener('click', fetchCriticalAlerts);
    initSidebar();
});

$(window).on('beforeunload', function() {
    if (pollInterval) clearInterval(pollInterval);
});

document.addEventListener('visibilitychange', function() {
    if (document.hidden && pollInterval) {
        clearInterval(pollInterval);
        pollInterval = null;
    } else if (!document.hidden && !pollInterval) {
        pollInterval = setInterval(function() {
            if (!document.hidden) {
                fetchGroupedOrders();
                fetchStats();
            }
        }, 30000);
    }
});
</script>
</body>
</html>