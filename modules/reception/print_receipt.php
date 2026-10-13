<?php
/**
 * ======================================================================
 * PRINT RECEIPT - HMS ERP System (PDO Version)
 * PRODUCTION GRADE | Thermal Printer Optimized | Multi-Format Support
 * Compatible with service_billing.php and hms_erp v3.0 schema
 * Three-part name support | Multi-tenant branch isolation
 * ======================================================================
 */

ob_start();
session_start();

// ============================================
// SECURE SESSION CONFIGURATION
// ============================================
$user_id = (int)($_SESSION['ad_id'] ?? $_SESSION['user_id'] ?? 0);
$user_branch_id = (int)($_SESSION['branch_id'] ?? 0);
$is_global_admin = ($_SESSION['user_type'] ?? '') === 'super_admin';

if ($user_id <= 0 || $user_branch_id <= 0) {
    header('Location: ../../index.php');
    exit;
}

// ============================================
// PDO DATABASE CONNECTION & TIMEZONE SETUP
// ============================================
require_once '../../config/db_connection.php';

date_default_timezone_set('Africa/Addis_Ababa');

$db_available = isset($pdo) && $pdo instanceof PDO;

if ($db_available) {
    try {
        $pdo->exec("SET time_zone = '+03:00'");
        $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
    } catch (PDOException $e) {
        error_log('Timezone set failed: ' . $e->getMessage());
    }
}

// Get invoice ID from URL
$invoice_id = (int)($_GET['invoice_id'] ?? 0);
$receipt_format = $_GET['format'] ?? 'thermal'; // thermal, a4, mini

if ($invoice_id <= 0 || !$db_available) {
    die('Invalid invoice ID or database connection failed');
}

// ============================================
// HELPER FUNCTIONS
// ============================================
function escapeHtml($str) {
    if ($str === null) return '';
    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}

function db_safe_error_log($message) {
    error_log(date('Y-m-d H:i:s') . ' - ' . $message . "\n", 3, __DIR__ . '/error_log.txt');
}

// ============================================
// MAIN INVOICE QUERY (PDO Version with three-part name)
// ============================================
try {
    $stmt = $pdo->prepare("
        SELECT 
            i.*,
            CONCAT(p.first_name, ' ', COALESCE(p.middle_name, ''), ' ', p.last_name) AS patient_name,
            p.mr_number,
            p.phone AS patient_phone,
            p.email AS patient_email,
            p.gender,
            p.date_of_birth AS birth_date,
            b.name AS branch_name,
            b.location AS branch_address,
            b.phone AS branch_phone,
            b.email AS branch_email
        FROM invoices i
        INNER JOIN patients p ON i.patient_id = p.id AND p.deleted_at IS NULL
        INNER JOIN branches b ON i.branch_id = b.id AND b.deleted_at IS NULL
        WHERE i.id = :id AND i.branch_id = :branch_id AND i.deleted_at IS NULL
    ");
    
    $stmt->execute([
        ':id' => $invoice_id,
        ':branch_id' => $user_branch_id
    ]);
    $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$invoice) {
        die('Invoice not found or access denied');
    }
} catch (PDOException $e) {
    db_safe_error_log('Invoice fetch error: ' . $e->getMessage());
    die('Database error: ' . $e->getMessage());
}

// ============================================
// INVOICE ITEMS QUERY (PDO Version)
// ============================================
try {
    $items_stmt = $pdo->prepare("
        SELECT 
            ii.*,
            st.service_name,
            st.service_code
        FROM invoice_items ii
        LEFT JOIN service_tariffs st ON ii.service_tariff_id = st.id AND st.deleted_at IS NULL
        WHERE ii.invoice_id = :invoice_id
        ORDER BY ii.id ASC
    ");
    
    $items_stmt->execute([':invoice_id' => $invoice_id]);
    $invoice_items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    db_safe_error_log('Invoice items fetch error: ' . $e->getMessage());
    $invoice_items = [];
}

// ============================================
// PAYMENTS QUERY (PDO Version)
// ============================================
try {
    $payments_stmt = $pdo->prepare("
        SELECT 
            p.*
        FROM payments p
        WHERE p.invoice_id = :invoice_id
        ORDER BY p.payment_date DESC
    ");
    
    $payments_stmt->execute([':invoice_id' => $invoice_id]);
    $payments = $payments_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    db_safe_error_log('Payments fetch error: ' . $e->getMessage());
    $payments = [];
}

// Calculate age if birth date exists
$age = '';
if (!empty($invoice['birth_date'])) {
    $birth = new DateTime($invoice['birth_date']);
    $today = new DateTime();
    $age = $birth->diff($today)->y . ' years';
}

// Format payment method display
$payment_methods = [
    'cash' => 'Cash',
    'credit_card' => 'Credit Card',
    'debit_card' => 'Debit Card',
    'bank_transfer' => 'Bank Transfer',
    'insurance_claim' => 'Insurance',
    'mobile_money' => 'Mobile Money',
    'telebirr' => 'Telebirr',
    'cbe_birr' => 'CBE Birr',
    'check' => 'Check'
];

// Format invoice type display (billing_type from schema)
$invoice_types = [
    'consultation' => 'Consultation',
    'admission' => 'Admission',
    'pharmacy' => 'Pharmacy',
    'lab' => 'Laboratory',
    'radiology' => 'Radiology',
    'inventory' => 'Inventory',
    'surgery' => 'Surgery',
    'cash' => 'Cash Service',
    'insurance' => 'Insurance Claim',
    'corporate' => 'Corporate Billing',
    'package' => 'Package Deal',
    'free_treatment' => 'Free Treatment'
];

// Format payment status display
$payment_statuses = [
    'paid' => 'PAID',
    'partial' => 'PARTIAL',
    'unpaid' => 'UNPAID',
    'overdue' => 'OVERDUE',
    'cancelled' => 'CANCELLED',
    'refunded' => 'REFUNDED'
];

// Number to words function for amount in words
function numberToWords($number) {
    $words = array(
        0 => 'Zero', 1 => 'One', 2 => 'Two', 3 => 'Three', 4 => 'Four', 
        5 => 'Five', 6 => 'Six', 7 => 'Seven', 8 => 'Eight', 9 => 'Nine',
        10 => 'Ten', 11 => 'Eleven', 12 => 'Twelve', 13 => 'Thirteen', 
        14 => 'Fourteen', 15 => 'Fifteen', 16 => 'Sixteen', 17 => 'Seventeen', 
        18 => 'Eighteen', 19 => 'Nineteen', 20 => 'Twenty', 30 => 'Thirty', 
        40 => 'Forty', 50 => 'Fifty', 60 => 'Sixty', 70 => 'Seventy', 
        80 => 'Eighty', 90 => 'Ninety'
    );
    
    if ($number < 21) {
        return $words[$number];
    } elseif ($number < 100) {
        $tens = floor($number / 10) * 10;
        $units = $number % 10;
        return $words[$tens] . ($units ? ' ' . $words[$units] : '');
    } elseif ($number < 1000) {
        $hundreds = floor($number / 100);
        $remainder = $number % 100;
        return $words[$hundreds] . ' Hundred' . ($remainder ? ' ' . numberToWords($remainder) : '');
    } else {
        return number_format($number, 2);
    }
}

$total_in_words = numberToWords(floor($invoice['total_amount'])) . ' Birr Only';
$total_in_words_full = numberToWords(floor($invoice['total_amount'])) . ' Birr and ' . 
                       number_format($invoice['total_amount'] - floor($invoice['total_amount']), 2) * 100 . ' Cents';

// Determine which format to output
if ($receipt_format === 'thermal') {
    header('Content-Type: text/html; charset=UTF-8');
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Receipt - <?php echo escapeHtml($invoice['invoice_number']); ?></title>
        <style>
            * { margin: 0; padding: 0; box-sizing: border-box; }
            body { font-family: 'Courier New', 'Monaco', monospace; background: #e2e8f0; display: flex; justify-content: center; align-items: center; min-height: 100vh; padding: 20px; }
            .receipt { max-width: 300px; margin: 0 auto; background: white; padding: 16px 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); font-size: 11px; line-height: 1.4; }
            .receipt-header { text-align: center; border-bottom: 1px dashed #000; padding-bottom: 10px; margin-bottom: 10px; }
            .hospital-name { font-size: 16px; font-weight: bold; letter-spacing: 1px; }
            .hospital-address { font-size: 9px; color: #666; margin-top: 3px; }
            .receipt-title { font-size: 14px; font-weight: bold; text-align: center; margin: 10px 0; letter-spacing: 2px; }
            .info-row { display: flex; justify-content: space-between; margin-bottom: 5px; font-size: 10px; }
            .info-label { font-weight: bold; }
            .divider { border-top: 1px dashed #000; margin: 8px 0; }
            .divider-double { border-top: 2px solid #000; margin: 8px 0; }
            .items-table { width: 100%; margin: 10px 0; font-size: 10px; }
            .items-table th { text-align: left; border-bottom: 1px dotted #000; padding-bottom: 4px; }
            .items-table td { padding: 3px 0; }
            .items-table .text-right { text-align: right; }
            .total-row { display: flex; justify-content: space-between; font-weight: bold; margin-top: 8px; padding-top: 5px; border-top: 1px dashed #000; }
            .amount-words { font-size: 9px; color: #555; margin-top: 8px; padding: 5px; background: #f8f9fa; border-left: 3px solid #1a237e; }
            .payment-details { margin: 10px 0; padding: 8px; background: #f8f9fa; font-size: 10px; }
            .status-paid { color: #10b981; font-weight: bold; }
            .status-partial { color: #f59e0b; font-weight: bold; }
            .status-unpaid { color: #ef4444; font-weight: bold; }
            .footer { text-align: center; margin-top: 12px; padding-top: 8px; border-top: 1px dashed #000; font-size: 9px; color: #666; }
            .signature-line { margin-top: 20px; display: flex; justify-content: space-between; font-size: 9px; }
            .barcode { text-align: center; margin: 10px 0; font-family: 'Courier New', monospace; letter-spacing: 2px; }
            @media print { body { background: white; padding: 0; margin: 0; } .receipt { box-shadow: none; padding: 0; max-width: 100%; } .no-print { display: none; } }
            .print-btn, .close-btn { display: block; width: 100%; max-width: 300px; margin: 10px auto; padding: 12px 20px; border: none; border-radius: 40px; cursor: pointer; font-size: 14px; font-weight: 600; font-family: inherit; transition: all 0.2s; }
            .print-btn { background: linear-gradient(135deg, #1a237e, #0d1b5e); color: white; }
            .print-btn:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(26,35,126,0.3); }
            .close-btn { background: #6b7280; color: white; }
            .close-btn:hover { background: #4b5563; }
            .button-group { max-width: 300px; margin: 0 auto; display: flex; flex-direction: column; gap: 10px; }
        </style>
    </head>
    <body>
        <div class="receipt">
            <div class="receipt-header">
                <div class="hospital-name"><?php echo escapeHtml($invoice['branch_name'] ?? 'Hospital'); ?></div>
                <div class="hospital-address"><?php echo escapeHtml($invoice['branch_address'] ?? ''); ?></div>
                <div class="hospital-address">Tel: <?php echo escapeHtml($invoice['branch_phone'] ?? ''); ?></div>
                <div class="hospital-address">Email: <?php echo escapeHtml($invoice['branch_email'] ?? ''); ?></div>
            </div>
            <div class="receipt-title">TAX INVOICE / RECEIPT</div>
            <div class="info-row"><span class="info-label">Invoice #:</span><span><?php echo escapeHtml($invoice['invoice_number']); ?></span></div>
            <div class="info-row"><span class="info-label">Date:</span><span><?php echo date('d/m/Y H:i', strtotime($invoice['created_at'])); ?></span></div>
            <div class="info-row"><span class="info-label">Status:</span><span class="status-<?php echo $invoice['payment_status']; ?>"><?php echo $payment_statuses[$invoice['payment_status']] ?? ucfirst($invoice['payment_status']); ?></span></div>
            <div class="divider"></div>
            <div class="info-row"><span class="info-label">Patient:</span><span><?php echo escapeHtml($invoice['patient_name']); ?></span></div>
            <div class="info-row"><span class="info-label">MRN:</span><span><?php echo escapeHtml($invoice['mr_number'] ?? 'N/A'); ?></span></div>
            <?php if ($age): ?>
            <div class="info-row"><span class="info-label">Age/Gender:</span><span><?php echo $age . ' / ' . ucfirst($invoice['gender'] ?? 'N/A'); ?></span></div>
            <?php endif; ?>
            <?php if ($invoice['patient_phone']): ?>
            <div class="info-row"><span class="info-label">Phone:</span><span><?php echo escapeHtml($invoice['patient_phone']); ?></span></div>
            <?php endif; ?>
            <div class="divider-double"></div>
            <table class="items-table">
                <thead><tr><th>Description</th><th class="text-right">Qty</th><th class="text-right">Price</th><th class="text-right">Total</th></tr></thead>
                <tbody>
                    <?php foreach ($invoice_items as $item): ?>
                    <tr>
                        <td><?php echo escapeHtml($item['service_name'] ?? 'Service Item'); ?></td>
                        <td class="text-right"><?php echo $item['quantity']; ?></td>
                        <td class="text-right"><?php echo number_format($item['unit_price'], 2); ?></td>
                        <td class="text-right"><?php echo number_format($item['total_price'], 2); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <div class="divider"></div>
            <div class="info-row"><span>Subtotal:</span><span>ETB <?php echo number_format($invoice['subtotal'], 2); ?></span></div>
            <div class="info-row"><span>Tax (VAT):</span><span>ETB <?php echo number_format($invoice['tax_amount'], 2); ?></span></div>
            <?php if ($invoice['discount_amount'] > 0): ?>
            <div class="info-row"><span>Discount:</span><span>- ETB <?php echo number_format($invoice['discount_amount'], 2); ?></span></div>
            <?php endif; ?>
            <div class="total-row"><span>TOTAL:</span><span>ETB <?php echo number_format($invoice['total_amount'], 2); ?></span></div>
            <div class="amount-words"><?php echo $total_in_words; ?></div>
            <div class="payment-details">
                <div class="info-row"><span>Amount Paid:</span><span>ETB <?php echo number_format($invoice['paid_amount'], 2); ?></span></div>
                <div class="info-row"><span>Due Amount:</span><span>ETB <?php echo number_format($invoice['due_amount'], 2); ?></span></div>
                <?php foreach ($payments as $payment): ?>
                <div class="info-row"><span>Payment Method:</span><span><?php echo $payment_methods[$payment['payment_method']] ?? ucfirst($payment['payment_method']); ?></span></div>
                <div class="info-row"><span>Payment Date:</span><span><?php echo date('d/m/Y H:i', strtotime($payment['payment_date'])); ?></span></div>
                <?php if ($payment['transaction_reference']): ?>
                <div class="info-row"><span>Transaction ID:</span><span><?php echo escapeHtml($payment['transaction_reference']); ?></span></div>
                <?php endif; ?>
                <?php endforeach; ?>
            </div>
            <?php if (!empty($invoice['notes'])): ?>
            <div class="divider"></div>
            <div class="info-row"><span class="info-label">Notes:</span><span><?php echo nl2br(escapeHtml($invoice['notes'])); ?></span></div>
            <?php endif; ?>
            <div class="divider"></div>
            <div class="barcode"><?php echo $invoice['invoice_number']; ?></div>
            <div class="footer">Thank you for choosing <?php echo escapeHtml($invoice['branch_name'] ?? 'us'); ?><br>This is a computer-generated document. No signature required.<br>For inquiries, please contact <?php echo escapeHtml($invoice['branch_phone'] ?? ''); ?></div>
            <div class="signature-line"><div>Generated by: System</div><div>Patient Signature: _______________</div></div>
        </div>
        <div class="button-group no-print">
            <button class="print-btn" onclick="window.print();"><i class="fas fa-print"></i> 🖨️ Print Receipt</button>
            <button class="close-btn" onclick="window.close();"><i class="fas fa-times"></i> Close Window</button>
        </div>
        <script>window.onload = function() { setTimeout(function() { window.print(); }, 500); };</script>
    </body>
    </html>
    <?php
} elseif ($receipt_format === 'mini') {
    header('Content-Type: text/html; charset=UTF-8');
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Mini Receipt - <?php echo escapeHtml($invoice['invoice_number']); ?></title>
        <style>
            * { margin: 0; padding: 0; box-sizing: border-box; }
            body { font-family: 'Courier New', monospace; background: #e2e8f0; display: flex; justify-content: center; align-items: center; min-height: 100vh; padding: 10px; }
            .receipt { max-width: 220px; margin: 0 auto; background: white; padding: 10px 8px; font-size: 9px; line-height: 1.3; }
            .text-center { text-align: center; }
            .text-right { text-align: right; }
            .fw-bold { font-weight: bold; }
            .divider { border-top: 1px dotted #000; margin: 5px 0; }
            .divider-double { border-top: 2px solid #000; margin: 5px 0; }
            .items-table { width: 100%; margin: 5px 0; }
            .items-table td { padding: 2px 0; }
            .total-row { display: flex; justify-content: space-between; font-weight: bold; margin-top: 5px; }
            .footer { text-align: center; margin-top: 8px; font-size: 8px; }
            .print-btn, .close-btn { display: block; width: 220px; margin: 10px auto; padding: 10px; border: none; border-radius: 30px; cursor: pointer; font-size: 12px; font-weight: 600; }
            .print-btn { background: linear-gradient(135deg, #1a237e, #0d1b5e); color: white; }
            .close-btn { background: #6b7280; color: white; }
            .button-group { display: flex; flex-direction: column; gap: 8px; align-items: center; }
            @media print { body { background: white; padding: 0; } .receipt { padding: 0; max-width: 100%; } .print-btn, .close-btn { display: none; } }
        </style>
    </head>
    <body>
        <div class="receipt">
            <div class="text-center fw-bold"><?php echo escapeHtml(substr($invoice['branch_name'] ?? 'Hospital', 0, 20)); ?></div>
            <div class="text-center">INVOICE / RECEIPT</div>
            <div class="divider"></div>
            <div>Inv: <?php echo escapeHtml($invoice['invoice_number']); ?></div>
            <div>Date: <?php echo date('d/m/Y H:i', strtotime($invoice['created_at'])); ?></div>
            <div>Patient: <?php echo escapeHtml(substr($invoice['patient_name'], 0, 20)); ?></div>
            <div>MRN: <?php echo escapeHtml($invoice['mr_number'] ?? 'N/A'); ?></div>
            <div class="divider"></div>
            <?php foreach ($invoice_items as $item): ?>
            <div><?php echo escapeHtml(substr($item['service_name'] ?? 'Item', 0, 15)); ?> x<?php echo $item['quantity']; ?> = ETB <?php echo number_format($item['total_price'], 2); ?></div>
            <?php endforeach; ?>
            <div class="divider"></div>
            <div>Subtotal: ETB <?php echo number_format($invoice['subtotal'], 2); ?></div>
            <div>Tax: ETB <?php echo number_format($invoice['tax_amount'], 2); ?></div>
            <?php if ($invoice['discount_amount'] > 0): ?>
            <div>Discount: -ETB <?php echo number_format($invoice['discount_amount'], 2); ?></div>
            <?php endif; ?>
            <div class="total-row"><span>TOTAL:</span><span>ETB <?php echo number_format($invoice['total_amount'], 2); ?></span></div>
            <div>Paid: ETB <?php echo number_format($invoice['paid_amount'], 2); ?></div>
            <div>Due: ETB <?php echo number_format($invoice['due_amount'], 2); ?></div>
            <div class="divider"></div>
            <div class="text-center">Thank you!<br><?php echo escapeHtml($invoice['branch_phone'] ?? ''); ?></div>
        </div>
        <div class="button-group">
            <button class="print-btn" onclick="window.print();">🖨️ Print Receipt</button>
            <button class="close-btn" onclick="window.close();">Close Window</button>
        </div>
        <script>window.onload = function() { setTimeout(function() { window.print(); }, 500); };</script>
    </body>
    </html>
    <?php
} else {
    // A4 Format
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Official Receipt - <?php echo escapeHtml($invoice['invoice_number']); ?></title>
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
        <style>
            * { margin: 0; padding: 0; box-sizing: border-box; }
            body { font-family: 'Inter', 'Arial', sans-serif; background: #e2e8f0; display: flex; justify-content: center; align-items: center; min-height: 100vh; padding: 40px; }
            .receipt-a4 { max-width: 800px; width: 100%; margin: 0 auto; background: white; border-radius: 16px; box-shadow: 0 20px 40px rgba(0,0,0,0.1); overflow: hidden; }
            .receipt-header { background: linear-gradient(135deg, #1a237e, #283593); color: white; padding: 30px 40px; text-align: center; }
            .hospital-name { font-size: 28px; font-weight: 800; letter-spacing: 1px; }
            .hospital-address { font-size: 13px; opacity: 0.8; margin-top: 8px; }
            .receipt-body { padding: 30px 40px; }
            .receipt-title { font-size: 24px; font-weight: 700; text-align: center; margin-bottom: 30px; color: #1a237e; border-bottom: 2px solid #e2e8f0; padding-bottom: 15px; }
            .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 30px; background: #f8fafc; padding: 20px; border-radius: 12px; }
            .info-group { margin-bottom: 10px; }
            .info-label { font-size: 11px; text-transform: uppercase; color: #64748b; letter-spacing: 0.5px; }
            .info-value { font-size: 14px; font-weight: 600; color: #1e293b; margin-top: 4px; }
            .status-badge { display: inline-block; padding: 4px 12px; border-radius: 30px; font-size: 11px; font-weight: 700; }
            .status-paid { background: #d1fae5; color: #065f46; }
            .status-partial { background: #fef3c7; color: #92400e; }
            .status-unpaid { background: #fee2e2; color: #991b1b; }
            .items-table { width: 100%; border-collapse: collapse; margin: 20px 0; }
            .items-table th { background: #f1f5f9; padding: 12px; text-align: left; font-size: 13px; font-weight: 700; color: #475569; border-bottom: 2px solid #e2e8f0; }
            .items-table td { padding: 12px; border-bottom: 1px solid #e2e8f0; font-size: 13px; }
            .items-table .text-right { text-align: right; }
            .summary-box { background: #f8fafc; border-radius: 12px; padding: 20px; margin-top: 20px; }
            .summary-row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #e2e8f0; }
            .summary-row.grand-total { font-size: 18px; font-weight: 800; color: #1a237e; border-bottom: none; padding-top: 12px; }
            .amount-words { margin-top: 20px; padding: 12px; background: #f1f5f9; border-left: 4px solid #1a237e; font-size: 12px; color: #475569; }
            .footer { text-align: center; margin-top: 30px; padding-top: 20px; border-top: 1px solid #e2e8f0; font-size: 11px; color: #94a3b8; }
            .signature-row { display: flex; justify-content: space-between; margin-top: 30px; padding-top: 20px; }
            .print-btn, .close-btn { display: block; width: 250px; margin: 10px auto; padding: 14px 24px; border: none; border-radius: 40px; cursor: pointer; font-weight: 600; font-size: 14px; transition: all 0.2s; }
            .print-btn { background: linear-gradient(135deg, #1a237e, #0d1b5e); color: white; }
            .print-btn:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(26,35,126,0.3); }
            .close-btn { background: #6b7280; color: white; }
            .close-btn:hover { background: #4b5563; }
            .button-group { text-align: center; margin-top: 20px; }
            @media print { body { background: white; padding: 0; margin: 0; } .receipt-a4 { box-shadow: none; border-radius: 0; margin: 0; max-width: 100%; } .print-btn, .close-btn, .button-group { display: none; } }
            .text-right { text-align: right; }
            .fw-bold { font-weight: 700; }
            .text-success { color: #10b981; }
            .text-danger { color: #ef4444; }
        </style>
    </head>
    <body>
        <div class="receipt-a4">
            <div class="receipt-header">
                <div class="hospital-name"><?php echo escapeHtml($invoice['branch_name'] ?? 'Hospital'); ?></div>
                <div class="hospital-address"><?php echo nl2br(escapeHtml($invoice['branch_address'] ?? '')); ?></div>
                <div class="hospital-address">📞 <?php echo escapeHtml($invoice['branch_phone'] ?? ''); ?> | ✉️ <?php echo escapeHtml($invoice['branch_email'] ?? ''); ?></div>
            </div>
            <div class="receipt-body">
                <div class="receipt-title">OFFICIAL RECEIPT & TAX INVOICE</div>
                <div class="info-grid">
                    <div>
                        <div class="info-group"><div class="info-label">INVOICE NUMBER</div><div class="info-value"><?php echo escapeHtml($invoice['invoice_number']); ?></div></div>
                        <div class="info-group"><div class="info-label">INVOICE DATE</div><div class="info-value"><?php echo date('F d, Y H:i A', strtotime($invoice['created_at'])); ?></div></div>
                        <div class="info-group"><div class="info-label">INVOICE TYPE</div><div class="info-value"><?php echo $invoice_types[$invoice['billing_type']] ?? ucfirst($invoice['billing_type']); ?></div></div>
                    </div>
                    <div>
                        <div class="info-group"><div class="info-label">PAYMENT STATUS</div><div class="info-value"><span class="status-badge status-<?php echo $invoice['payment_status']; ?>"><?php echo $payment_statuses[$invoice['payment_status']] ?? ucfirst($invoice['payment_status']); ?></span></div></div>
                        <div class="info-group"><div class="info-label">BILLING TYPE</div><div class="info-value"><?php echo ucfirst($invoice['billing_type'] ?? 'Cash'); ?></div></div>
                        <div class="info-group"><div class="info-label">GENERATED BY</div><div class="info-value">System</div></div>
                    </div>
                </div>
                <div class="info-grid" style="background: white; padding: 0; margin-bottom: 20px;">
                    <div>
                        <div class="info-label">PATIENT INFORMATION</div>
                        <div class="info-value" style="font-size: 16px; margin-top: 8px;"><?php echo escapeHtml($invoice['patient_name']); ?></div>
                        <div class="info-value" style="font-size: 13px; color: #64748b;">MRN: <?php echo escapeHtml($invoice['mr_number'] ?? 'N/A'); ?></div>
                        <?php if ($age): ?>
                        <div class="info-value" style="font-size: 13px;"><?php echo $age . ' • ' . ucfirst($invoice['gender'] ?? 'N/A'); ?></div>
                        <?php endif; ?>
                    </div>
                    <div>
                        <?php if ($invoice['patient_phone']): ?>
                        <div class="info-label">CONTACT</div>
                        <div class="info-value">📞 <?php echo escapeHtml($invoice['patient_phone']); ?></div>
                        <?php endif; ?>
                        <?php if ($invoice['patient_email']): ?>
                        <div class="info-value">✉️ <?php echo escapeHtml($invoice['patient_email']); ?></div>
                        <?php endif; ?>
                    </div>
                </div>
                <table class="items-table">
                    <thead><tr><th>#</th><th>Description / Service</th><th class="text-right">Qty</th><th class="text-right">Unit Price (ETB)</th><th class="text-right">Total (ETB)</th></tr></thead>
                    <tbody>
                        <?php $counter = 1; foreach ($invoice_items as $item): ?>
                        <tr>
                            <td><?php echo $counter++; ?></td>
                            <td><?php echo escapeHtml($item['service_name'] ?? 'Service Item'); ?>
                                <?php if ($item['service_code']): ?><br><small style="color:#94a3b8;"><?php echo escapeHtml($item['service_code']); ?></small><?php endif; ?>
                            </td>
                            <td class="text-right"><?php echo $item['quantity']; ?></td>
                            <td class="text-right"><?php echo number_format($item['unit_price'], 2); ?></td>
                            <td class="text-right fw-bold"><?php echo number_format($item['total_price'], 2); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <div class="summary-box">
                    <div class="summary-row"><span>Subtotal</span><span>ETB <?php echo number_format($invoice['subtotal'], 2); ?></span></div>
                    <div class="summary-row"><span>Value Added Tax (VAT)</span><span>ETB <?php echo number_format($invoice['tax_amount'], 2); ?></span></div>
                    <?php if ($invoice['discount_amount'] > 0): ?>
                    <div class="summary-row"><span>Discount</span><span>- ETB <?php echo number_format($invoice['discount_amount'], 2); ?></span></div>
                    <?php endif; ?>
                    <div class="summary-row grand-total"><span>GRAND TOTAL</span><span>ETB <?php echo number_format($invoice['total_amount'], 2); ?></span></div>
                </div>
                <div class="amount-words"><strong>Amount in Words:</strong><br><?php echo $total_in_words_full; ?></div>
                <div class="summary-box" style="margin-top: 20px;">
                    <div class="summary-row"><span>Amount Paid</span><span class="text-success fw-bold">ETB <?php echo number_format($invoice['paid_amount'], 2); ?></span></div>
                    <div class="summary-row"><span>Outstanding Balance</span><span class="text-danger fw-bold">ETB <?php echo number_format($invoice['due_amount'], 2); ?></span></div>
                    <?php foreach ($payments as $payment): ?>
                    <div class="summary-row" style="border-top: 1px solid #e2e8f0; margin-top: 8px; padding-top: 8px;">
                        <span>Payment Method: <?php echo $payment_methods[$payment['payment_method']] ?? ucfirst($payment['payment_method']); ?></span>
                        <span><?php echo date('d/m/Y H:i', strtotime($payment['payment_date'])); ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php if (!empty($invoice['notes'])): ?>
                <div style="margin-top: 20px; padding: 12px; background: #fef3c7; border-radius: 12px;">
                    <div class="info-label">NOTES</div>
                    <div class="info-value" style="font-size: 12px;"><?php echo nl2br(escapeHtml($invoice['notes'])); ?></div>
                </div>
                <?php endif; ?>
                <div class="signature-row">
                    <div><div class="info-label">RECEIVED BY</div><div class="info-value" style="margin-top: 30px;">_________________</div><div class="info-value" style="font-size: 11px;">(Patient/Representative Signature)</div></div>
                    <div><div class="info-label">AUTHORIZED SIGNATURE</div><div class="info-value" style="margin-top: 30px;">_________________</div><div class="info-value" style="font-size: 11px;">(Hospital Stamp & Signature)</div></div>
                </div>
                <div class="footer">This is a computer-generated document. No additional signature required.<br>Thank you for choosing <?php echo escapeHtml($invoice['branch_name'] ?? 'us'); ?>. For inquiries, please contact our billing department.<br>Printed on: <?php echo date('F d, Y H:i A'); ?></div>
            </div>
        </div>
        <div class="button-group">
            <button class="print-btn" onclick="window.print();"><i class="fas fa-print"></i> 🖨️ Print Receipt</button>
            <button class="close-btn" onclick="window.close();"><i class="fas fa-times"></i> Close Window</button>
        </div>
        <script>window.onload = function() { setTimeout(function() { window.print(); }, 500); };</script>
    </body>
    </html>
    <?php
}
?>