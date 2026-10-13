<?php
// ============================================
// PRINT INVOICE / RECEIPT GENERATOR
// Dual-Mode: ERP Shell (Screen) + Clean Print
// ============================================

ob_start();
session_start();
include('assets/inc/config.php');

// ============================================
// ACCESS CONTROL
// ============================================
$user_id        = $_SESSION['ad_id']      ?? $_SESSION['user_id']  ?? 0;
$user_role      = $_SESSION['type']       ?? $_SESSION['ad_role']  ?? '';
$user_branch_id = (int)($_SESSION['branch_id'] ?? 0);
$user_name      = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''));

// ============================================
// GET INVOICE ID FROM URL
// ============================================
$invoice_id = isset($_GET['invoice_id']) ? (int)$_GET['invoice_id'] : 0;
$autoprint = isset($_GET['autoprint']) && $_GET['autoprint'] == 'true';

if ($invoice_id <= 0) {
    header("Location: dashboard.php?error=invalid_invoice");
    exit();
}

// ============================================
// FETCH INVOICE DATA WITH COMPLEX JOINS
// ============================================
$invoice_data = null;
$invoice_items = [];
$patient_data = null;
$cashier_data = null;

// Main invoice query with all joins
$stmt = $mysqli->prepare("
    SELECT 
        i.id, i.invoice_number, i.invoice_type, i.subtotal, i.discount, i.tax, i.total,
        i.paid_amount, i.due_amount, i.payment_status, i.billing_type, i.payment_method,
        i.billing_date, i.created_at, i.notes,
        u.id as patient_id, u.first_name, u.middle_name, u.last_name, u.mr_number, 
        u.phone, u.email, u.address, u.national_id,
        u.insurance_provider, u.insurance_number, u.insurance_plan,
        CONCAT(u.first_name, ' ', u.last_name) as patient_full_name,
        c.id as cashier_id, c.first_name as cashier_first, c.last_name as cashier_last,
        CONCAT(c.first_name, ' ', c.last_name) as cashier_name,
        b.name as branch_name, b.address as branch_address, b.phone as branch_phone
    FROM invoices i
    JOIN users u ON i.patient_id = u.id
    LEFT JOIN users c ON i.created_by = c.id
    LEFT JOIN branches b ON i.branch_id = b.id
    WHERE i.id = ? AND i.deleted_at IS NULL
");
$stmt->bind_param('i', $invoice_id);
$stmt->execute();
$invoice_data = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$invoice_data) {
    // Invoice not found - show error in shell
    $error_message = "Invoice not found or has been deleted.";
    $show_error = true;
} else {
    $show_error = false;
    
    // Fetch invoice items with service names
    $items_stmt = $mysqli->prepare("
        SELECT 
            ii.id, ii.quantity, ii.unit_price, ii.discount as item_discount, 
            ii.tax_amount, ii.total_price,
            COALESCE(si.name, sm.name, sm2.name, 'Service') as service_name,
            COALESCE(si.code, sm.code, sm2.code, '') as service_code,
            COALESCE(sc.name, 'General') as category_name
        FROM invoice_items ii
        LEFT JOIN service_items si ON ii.service_item_id = si.id
        LEFT JOIN service_master sm ON ii.service_item_id = sm.id
        LEFT JOIN service_master sm2 ON ii.service_id = sm2.id
        LEFT JOIN service_categories sc ON si.category_id = sc.id
        WHERE ii.invoice_id = ?
        ORDER BY ii.id ASC
    ");
    $items_stmt->bind_param('i', $invoice_id);
    $items_stmt->execute();
    $invoice_items = $items_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $items_stmt->close();
}

// ============================================
// GET SYSTEM SETTINGS FOR BRANDING
// ============================================
$hospital_name = 'HMS Hospital';
$hospital_address = 'Bole Road, Addis Ababa, Ethiopia';
$hospital_phone = '+251-11-123-1111';
$hospital_email = 'info@hms.com';
$hospital_website = 'www.hmshospital.com';
$tax_id = '123456789';
$currency_symbol = 'ETB';
$logo_path = 'assets/images/logo.png';

$settings_query = "SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('hospital_name', 'hospital_address', 'hospital_phone', 'hospital_email', 'hospital_website', 'tax_id', 'currency_symbol')";
$settings_result = $mysqli->query($settings_query);
if ($settings_result) {
    while ($setting = $settings_result->fetch_assoc()) {
        switch ($setting['setting_key']) {
            case 'hospital_name': $hospital_name = $setting['setting_value']; break;
            case 'hospital_address': $hospital_address = $setting['setting_value']; break;
            case 'hospital_phone': $hospital_phone = $setting['setting_value']; break;
            case 'hospital_email': $hospital_email = $setting['setting_value']; break;
            case 'hospital_website': $hospital_website = $setting['setting_value']; break;
            case 'tax_id': $tax_id = $setting['setting_value']; break;
            case 'currency_symbol': $currency_symbol = $setting['setting_value']; break;
        }
    }
}

// Format dates
$invoice_date = date('F d, Y', strtotime($invoice_data['billing_date'] ?? $invoice_data['created_at'] ?? 'now'));
$invoice_time = date('h:i A', strtotime($invoice_data['billing_date'] ?? $invoice_data['created_at'] ?? 'now'));

// Get status badge class
$status_class = '';
$status_icon = '';
switch ($invoice_data['payment_status'] ?? 'pending') {
    case 'paid':
        $status_class = 'status-paid';
        $status_icon = 'fa-check-circle';
        break;
    case 'partial':
        $status_class = 'status-partial';
        $status_icon = 'fa-clock';
        break;
    default:
        $status_class = 'status-pending';
        $status_icon = 'fa-exclamation-circle';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($hospital_name); ?> - Invoice <?php echo htmlspecialchars($invoice_data['invoice_number'] ?? 'Not Found'); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Inter', sans-serif;
            background: #f5f7fb;
        }
        
        /* Main Content Area - Adjusted for Sidebar */
        .content-page {
            margin-left: 260px;
            padding: 80px 30px 30px 30px;
            transition: all 0.3s;
        }
        
        @media (max-width: 768px) {
            .content-page {
                margin-left: 0;
                padding: 70px 15px 15px 15px;
            }
        }
        
        /* Invoice Container */
        .invoice-wrapper {
            max-width: 1000px;
            margin: 0 auto;
        }
        
        .invoice-card {
            background: white;
            border-radius: 20px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.08);
            overflow: hidden;
        }
        
        .invoice-header-actions {
            padding: 20px 30px;
            background: #f8fafc;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .print-btn {
            background: #2563eb;
            color: white;
            border: none;
            padding: 10px 24px;
            border-radius: 10px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .print-btn:hover {
            background: #1d4ed8;
            transform: translateY(-2px);
        }
        
        .close-btn {
            background: #6b7280;
            color: white;
            border: none;
            padding: 10px 24px;
            border-radius: 10px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .close-btn:hover {
            background: #4b5563;
        }
        
        /* Invoice Content */
        .invoice-content {
            padding: 40px;
        }
        
        /* Header Section */
        .invoice-header {
            text-align: center;
            border-bottom: 2px solid #e5e7eb;
            padding-bottom: 25px;
            margin-bottom: 25px;
        }
        
        .hospital-logo {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, #2563eb, #1d4ed8);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 15px;
        }
        
        .hospital-logo i {
            font-size: 40px;
            color: white;
        }
        
        .hospital-name {
            font-size: 24px;
            font-weight: 800;
            color: #1f2937;
            margin-bottom: 8px;
        }
        
        .hospital-details {
            font-size: 12px;
            color: #6b7280;
            line-height: 1.5;
        }
        
        /* Invoice Title */
        .invoice-title {
            text-align: center;
            margin-bottom: 25px;
        }
        
        .invoice-title h2 {
            font-size: 28px;
            font-weight: 700;
            color: #2563eb;
            letter-spacing: 2px;
        }
        
        /* Watermark for Paid Status */
        .watermark-paid {
            position: relative;
        }
        
        .watermark-paid::after {
            content: "PAID";
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) rotate(-30deg);
            font-size: 80px;
            font-weight: 900;
            color: rgba(16, 185, 129, 0.1);
            pointer-events: none;
            z-index: 1;
            white-space: nowrap;
        }
        
        /* Info Grid */
        .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 25px;
            margin-bottom: 30px;
            padding: 20px;
            background: #f9fafb;
            border-radius: 16px;
        }
        
        .info-section {
            font-size: 13px;
        }
        
        .info-label {
            font-weight: 700;
            color: #4b5563;
            margin-bottom: 8px;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .info-value {
            color: #1f2937;
            font-weight: 500;
            line-height: 1.6;
        }
        
        /* Status Badge */
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 5px 12px;
            border-radius: 30px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .status-paid {
            background: #d1fae5;
            color: #059669;
        }
        
        .status-partial {
            background: #fef3c7;
            color: #d97706;
        }
        
        .status-pending {
            background: #fee2e2;
            color: #dc2626;
        }
        
        /* Items Table */
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 25px;
        }
        
        .items-table th {
            background: #f3f4f6;
            padding: 14px;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #4b5563;
            text-align: left;
            border-bottom: 2px solid #e5e7eb;
        }
        
        .items-table td {
            padding: 14px;
            font-size: 13px;
            border-bottom: 1px solid #f3f4f6;
            color: #374151;
        }
        
        .items-table tr:last-child td {
            border-bottom: none;
        }
        
        .text-right {
            text-align: right;
        }
        
        /* Totals Section */
        .totals-section {
            background: #f9fafb;
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 25px;
            max-width: 350px;
            margin-left: auto;
        }
        
        .total-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            font-size: 14px;
        }
        
        .total-row.subtotal {
            color: #4b5563;
        }
        
        .total-row.discount {
            color: #ef4444;
        }
        
        .total-row.tax {
            color: #6b7280;
        }
        
        .total-row.grand-total {
            font-size: 18px;
            font-weight: 800;
            color: #1f2937;
            border-top: 2px solid #e5e7eb;
            margin-top: 8px;
            padding-top: 12px;
        }
        
        /* Payment Info */
        .payment-info {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            padding: 15px 20px;
            background: #f3f4f6;
            border-radius: 16px;
            margin-bottom: 25px;
            font-size: 13px;
        }
        
        /* Footer */
        .invoice-footer {
            text-align: center;
            padding-top: 25px;
            border-top: 1px solid #e5e7eb;
            margin-top: 10px;
        }
        
        .thank-you {
            font-size: 16px;
            font-weight: 600;
            color: #2563eb;
            margin-bottom: 15px;
        }
        
        .footer-text {
            font-size: 11px;
            color: #9ca3af;
            line-height: 1.6;
        }
        
        /* Error Container */
        .error-container {
            text-align: center;
            padding: 60px 40px;
            background: white;
            border-radius: 20px;
            max-width: 500px;
            margin: 50px auto;
        }
        
        .error-icon {
            font-size: 64px;
            color: #ef4444;
            margin-bottom: 20px;
        }
        
        .error-title {
            font-size: 24px;
            font-weight: 700;
            color: #1f2937;
            margin-bottom: 10px;
        }
        
        /* ============================================
           PRINT MEDIA STYLES - HIDE SIDEBAR, NAV, BUTTONS
           ============================================ */
        @media print {
            /* Hide Sidebar, Navbar, Footer, and Action Buttons */
            .left-side-menu, 
            .navbar-custom,
            #sidebar,
            .mobile-toggle,
            .sidebar-overlay,
            .footer,
            .invoice-header-actions,
            .print-btn,
            .close-btn,
            .action-buttons {
                display: none !important;
            }
            
            /* Reset main content area for printing */
            .content-page {
                margin-left: 0 !important;
                padding: 0 !important;
                width: 100% !important;
            }
            
            .invoice-wrapper {
                max-width: 100% !important;
                margin: 0 !important;
            }
            
            .invoice-card {
                box-shadow: none !important;
                border-radius: 0 !important;
            }
            
            .invoice-content {
                padding: 20px !important;
            }
            
            body {
                background: white !important;
                padding: 0 !important;
                margin: 0 !important;
            }
            
            .status-badge {
                border: 1px solid #e5e7eb;
                background: transparent !important;
            }
            
            @page {
                size: A4;
                margin: 15mm;
            }
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .invoice-content {
                padding: 20px;
            }
            
            .info-grid {
                grid-template-columns: 1fr;
                gap: 15px;
            }
            
            .totals-section {
                max-width: 100%;
            }
            
            .payment-info {
                flex-direction: column;
                text-align: center;
            }
        }
    </style>
</head>
<body>

<?php if (!$show_error): ?>
<!-- Include Sidebar and Navbar (Visible on screen, hidden in print) -->
<div id="wrapper">
    <?php include('assets/inc/nav.php'); ?>
    <br><br>
    <?php include('assets/inc/sidebar.php'); ?>

    <div class="content-page">
        <div class="invoice-wrapper">
            <div class="invoice-card <?php echo ($invoice_data['payment_status'] == 'paid') ? 'watermark-paid' : ''; ?>">
                <!-- Header with Action Buttons -->
                <div class="invoice-header-actions">
                    <div>
                        <i class="fas fa-file-invoice-dollar" style="color: #2563eb; font-size: 24px;"></i>
                        <span style="font-weight: 600; margin-left: 10px;">Invoice Details</span>
                    </div>
                    <div class="d-flex gap-2">
                        <button class="print-btn" onclick="printInvoice()">
                            <i class="fas fa-print"></i> Print / Save PDF
                        </button>
                        <button class="close-btn" onclick="window.history.back()">
                            <i class="fas fa-arrow-left"></i> Back
                        </button>
                    </div>
                </div>
                
                <!-- Invoice Content -->
                <div class="invoice-content" id="invoiceContent">
                    <!-- Header -->
                    <div class="invoice-header">
                        <div class="hospital-logo">
                            <i class="fas fa-hospital-user"></i>
                        </div>
                        <div class="hospital-name"><?php echo htmlspecialchars($hospital_name); ?></div>
                        <div class="hospital-details">
                            <?php echo htmlspecialchars($hospital_address); ?><br>
                            Tel: <?php echo htmlspecialchars($hospital_phone); ?> | Email: <?php echo htmlspecialchars($hospital_email); ?><br>
                            Website: <?php echo htmlspecialchars($hospital_website); ?> | Tax ID: <?php echo htmlspecialchars($tax_id); ?>
                        </div>
                    </div>
                    
                    <!-- Invoice Title -->
                    <div class="invoice-title">
                        <h2>TAX INVOICE / RECEIPT</h2>
                        <div style="font-size: 14px; color: #6b7280;">Invoice #: <?php echo htmlspecialchars($invoice_data['invoice_number']); ?></div>
                    </div>
                    
                    <!-- Information Grid -->
                    <div class="info-grid">
                        <div class="info-section">
                            <div class="info-label"><i class="fas fa-user"></i> BILLED TO:</div>
                            <div class="info-value">
                                <strong><?php echo htmlspecialchars($invoice_data['patient_full_name']); ?></strong><br>
                                <?php if ($invoice_data['mr_number']): ?>MRN: <?php echo htmlspecialchars($invoice_data['mr_number']); ?><br><?php endif; ?>
                                <?php if ($invoice_data['phone']): ?><i class="fas fa-phone"></i> <?php echo htmlspecialchars($invoice_data['phone']); ?><br><?php endif; ?>
                                <?php if ($invoice_data['email']): ?><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($invoice_data['email']); ?><br><?php endif; ?>
                                <?php if ($invoice_data['address']): ?><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($invoice_data['address']); ?><?php endif; ?>
                            </div>
                        </div>
                        <div class="info-section">
                            <div class="info-label"><i class="fas fa-info-circle"></i> INVOICE DETAILS:</div>
                            <div class="info-value">
                                <strong>Date:</strong> <?php echo $invoice_date; ?><br>
                                <strong>Time:</strong> <?php echo $invoice_time; ?><br>
                                <strong>Branch:</strong> <?php echo htmlspecialchars($invoice_data['branch_name'] ?? 'Main Branch'); ?><br>
                                <strong>Billing Type:</strong> <?php echo ucfirst($invoice_data['billing_type'] ?? 'Cash'); ?><br>
                                <strong>Status:</strong> 
                                <span class="status-badge <?php echo $status_class; ?>">
                                    <i class="fas <?php echo $status_icon; ?>"></i>
                                    <?php echo ucfirst($invoice_data['payment_status'] ?? 'Pending'); ?>
                                </span>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Insurance Info if applicable -->
                    <?php if (($invoice_data['billing_type'] ?? '') == 'insurance' && !empty($invoice_data['insurance_provider'])): ?>
                    <div class="info-grid" style="margin-top: -15px;">
                        <div class="info-section">
                            <div class="info-label"><i class="fas fa-shield-alt"></i> INSURANCE DETAILS:</div>
                            <div class="info-value">
                                Provider: <?php echo htmlspecialchars($invoice_data['insurance_provider']); ?><br>
                                Policy #: <?php echo htmlspecialchars($invoice_data['insurance_number']); ?>
                                <?php if ($invoice_data['insurance_plan']): ?> | Plan: <?php echo htmlspecialchars($invoice_data['insurance_plan']); ?><?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Items Table -->
                    <table class="items-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Description</th>
                                <th class="text-right">Qty</th>
                                <th class="text-right">Unit Price (<?php echo htmlspecialchars($currency_symbol); ?>)</th>
                                <th class="text-right">Total (<?php echo htmlspecialchars($currency_symbol); ?>)</th>
                            </tr
                        </thead>
                        <tbody>
                            <?php if (empty($invoice_items)): ?>
                            <tr>
                                <td colspan="5" class="text-center" style="padding: 40px;">No items found for this invoice</td>
                            </tr
                            <?php else: ?>
                                <?php $counter = 1; ?>
                                <?php foreach ($invoice_items as $item): ?>
                                <tr>
                                    <td><?php echo $counter++; ?></td>
                                    <td>
                                        <?php echo htmlspecialchars($item['service_name'] ?? 'Service Item'); ?>
                                        <?php if (!empty($item['service_code'])): ?>
                                            <br><small class="text-muted">Code: <?php echo htmlspecialchars($item['service_code']); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-right"><?php echo (int)($item['quantity'] ?? 1); ?></td>
                                    <td class="text-right"><?php echo number_format((float)($item['unit_price'] ?? 0), 2); ?></td>
                                    <td class="text-right"><?php echo number_format((float)($item['total_price'] ?? 0), 2); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                    
                    <!-- Totals Section -->
                    <div class="totals-section">
                        <div class="total-row subtotal">
                            <span>Subtotal:</span>
                            <span><?php echo htmlspecialchars($currency_symbol); ?> <?php echo number_format($invoice_data['subtotal'] ?? 0, 2); ?></span>
                        </div>
                        <?php if (($invoice_data['discount'] ?? 0) > 0): ?>
                        <div class="total-row discount">
                            <span>Discount:</span>
                            <span>- <?php echo htmlspecialchars($currency_symbol); ?> <?php echo number_format($invoice_data['discount'], 2); ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if (($invoice_data['tax'] ?? 0) > 0): ?>
                        <div class="total-row tax">
                            <span>Tax (VAT):</span>
                            <span><?php echo htmlspecialchars($currency_symbol); ?> <?php echo number_format($invoice_data['tax'], 2); ?></span>
                        </div>
                        <?php endif; ?>
                        <div class="total-row grand-total">
                            <span>TOTAL AMOUNT:</span>
                            <span><?php echo htmlspecialchars($currency_symbol); ?> <?php echo number_format($invoice_data['total'] ?? 0, 2); ?></span>
                        </div>
                    </div>
                    
                    <!-- Payment Information -->
                    <div class="payment-info">
                        <div>
                            <strong><i class="fas fa-credit-card"></i> Payment Method:</strong> 
                            <?php echo ucfirst($invoice_data['payment_method'] ?? 'Cash'); ?>
                        </div>
                        <div>
                            <strong><i class="fas fa-money-bill-wave"></i> Amount Paid:</strong> 
                            <?php echo htmlspecialchars($currency_symbol); ?> <?php echo number_format($invoice_data['paid_amount'] ?? 0, 2); ?>
                        </div>
                        <?php if (($invoice_data['due_amount'] ?? 0) > 0): ?>
                        <div>
                            <strong><i class="fas fa-hourglass-half"></i> Due Amount:</strong> 
                            <?php echo htmlspecialchars($currency_symbol); ?> <?php echo number_format($invoice_data['due_amount'], 2); ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Footer -->
                    <div class="invoice-footer">
                        <div class="thank-you">
                            <i class="fas fa-heart" style="color: #ef4444;"></i> 
                            Thank You for Choosing <?php echo htmlspecialchars($hospital_name); ?> 
                            <i class="fas fa-heart" style="color: #ef4444;"></i>
                        </div>
                        <div class="footer-text">
                            <strong>Prepared by:</strong> <?php echo htmlspecialchars($invoice_data['cashier_name'] ?? $user_name); ?><br>
                            <strong>Issued on:</strong> <?php echo $invoice_date . ' at ' . $invoice_time; ?><br>
                            This is a computer-generated invoice and requires no signature.<br>
                            For any inquiries, please contact our billing department at <?php echo htmlspecialchars($hospital_phone); ?>.
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    function printInvoice() {
        window.print();
    }
    
    <?php if ($autoprint): ?>
    // Auto-print if flag is set
    window.addEventListener('load', function() {
        setTimeout(function() {
            window.print();
        }, 1000);
    });
    <?php endif; ?>
    
    console.log('✅ Invoice Print System Ready');
</script>

<?php else: ?>
<!-- Error State within ERP Shell -->
<div id="wrapper">
    <?php include('assets/inc/nav.php'); ?>
    <br><br>
    <?php include('assets/inc/sidebar.php'); ?>
    
    <div class="content-page">
        <div class="error-container">
            <div class="error-icon">
                <i class="fas fa-file-invoice"></i>
            </div>
            <div class="error-title">Invoice Not Found</div>
            <div class="error-message">
                <?php echo htmlspecialchars($error_message); ?><br>
                Please check the invoice number and try again.
            </div>
            <button class="print-btn" onclick="window.history.back()" style="background: #2563eb;">
                <i class="fas fa-arrow-left"></i> Go Back
            </button>
        </div>
    </div>
</div>
<?php endif; ?>

</body>
</html>